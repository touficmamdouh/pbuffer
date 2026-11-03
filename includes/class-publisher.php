<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Publisher {

    private Settings $settings;
    private Deliveries $deliveries;
    private Buffer_Client $client;
    private Message_Builder $builder;

    public function __construct(
        Settings $settings,
        Deliveries $deliveries,
        Buffer_Client $client,
        Message_Builder $builder
    ) {
        $this->settings   = $settings;
        $this->deliveries = $deliveries;
        $this->client     = $client;
        $this->builder    = $builder;
    }

    public function register(): void {
        add_action( 'wp_after_insert_post', [ $this, 'maybe_publish' ], 10, 4 );
        add_action( 'lightweb2_deliver_channel', [ $this, 'deliver' ], 10, 3 );
        add_action( 'lightweb2_cleanup_logs', [ $this, 'cleanup_logs' ] );
        add_action( 'lightweb2_recover_stale', [ $this, 'recover_stale' ] );
    }

    public function maybe_publish(
        int $post_id,
        \WP_Post $post,
        bool $update,
        ?\WP_Post $post_before
    ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( 'publish' !== $post->post_status ) {
            return;
        }

        if ( $post_before instanceof \WP_Post && 'publish' === $post_before->post_status ) {
            return;
        }

        if ( 'yes' !== get_post_meta( $post_id, '_lightweb2_enabled', true ) ) {
            return;
        }

        $settings = $this->settings->get();

        if ( '' === (string) $settings['token'] || empty( $settings['channels'] ) ) {
            return;
        }

        $filter = (int) $settings['category_filter'];

        if ( $filter > 0 ) {
            $categories = array_map( 'intval', wp_get_post_categories( $post_id ) );

            if ( ! in_array( $filter, $categories, true ) ) {
                return;
            }
        }

        $mode = get_post_meta( $post_id, '_lightweb2_mode', true );
        $mode = in_array( $mode, [ 'shareNow', 'addToQueue' ], true )
            ? $mode
            : (string) $settings['default_mode'];
        $message = $this->builder->build( $post_id, (string) $settings['template'] );

        foreach ( $settings['channels'] as $channel ) {
            $row = $this->deliveries->create_or_get( $post_id, $channel, $mode );

            if ( empty( $row ) || in_array( $row['status'], [ 'sent', 'canceled' ], true ) ) {
                continue;
            }

            if ( '' === $message ) {
                $this->deliveries->mark_failed(
                    (int) $row['id'],
                    'empty_message',
                    __( 'The message template produced an empty post.', 'lightweb2-publish-with-buffer' )
                );
                continue;
            }

            if ( (int) $row['attempts'] >= 4 ) {
                continue;
            }

            $this->schedule_delivery( $post_id, (string) $channel['id'], (int) $row['attempts'] + 1 );
        }
    }

    public function deliver( int $post_id, string $channel_id, int $attempt = 1 ): void {
        $row = $this->deliveries->get_by_post_channel( $post_id, $channel_id );

        if ( null === $row || in_array( $row['status'], [ 'sent', 'canceled' ], true ) ) {
            return;
        }

        if ( ! $this->deliveries->claim( (int) $row['id'], $attempt ) ) {
            return;
        }

        $post = get_post( $post_id );

        if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
            $this->deliveries->mark_canceled( (int) $row['id'] );
            return;
        }

        $settings = $this->settings->get();

        if ( '' === (string) $settings['token'] ) {
            $this->deliveries->mark_failed(
                (int) $row['id'],
                'missing_token',
                __( 'Reconnect Buffer before publishing.', 'lightweb2-publish-with-buffer' )
            );
            return;
        }

        $message = $this->builder->build( $post_id, (string) $settings['template'] );

        if ( '' === $message ) {
            $this->deliveries->mark_failed(
                (int) $row['id'],
                'empty_message',
                __( 'The message template produced an empty post.', 'lightweb2-publish-with-buffer' )
            );
            return;
        }

        $input = [
            'text'           => $message,
            'channelId'      => $channel_id,
            'schedulingType' => 'automatic',
            'mode'           => in_array( $row['mode'], [ 'shareNow', 'addToQueue' ], true )
                ? $row['mode']
                : (string) $settings['default_mode'],
        ];

        $image = $this->builder->image_url( $post_id );

        if ( null !== $image ) {
            $input['assets'] = [
                [
                    'image' => [ 'url' => $image ],
                ],
            ];
        }

        $result = $this->client->create_post( (string) $settings['token'], $input );

        if ( $result['success'] ) {
            $buffer_id = (string) ( $result['data']['createPost']['post']['id'] ?? '' );

            if ( '' === $buffer_id ) {
                $this->deliveries->mark_failed(
                    (int) $row['id'],
                    'invalid_response',
                    __( 'Buffer did not return a post ID.', 'lightweb2-publish-with-buffer' )
                );
                return;
            }

            $this->deliveries->mark_sent( (int) $row['id'], $buffer_id );
            return;
        }

        $this->deliveries->mark_failed(
            (int) $row['id'],
            (string) $result['code'],
            (string) $result['message']
        );

        if ( ! $result['retryable'] || $attempt >= 4 ) {
            return;
        }

        $delays = [
            1 => 5 * MINUTE_IN_SECONDS,
            2 => 15 * MINUTE_IN_SECONDS,
            3 => HOUR_IN_SECONDS,
        ];
        $delay = (int) $result['retry_after'] > 0
            ? (int) $result['retry_after']
            : ( $delays[ $attempt ] ?? HOUR_IN_SECONDS );

        $this->schedule_delivery( $post_id, $channel_id, $attempt + 1, $delay );
    }

    public function retry( int $delivery_id ): bool {
        $row = $this->deliveries->get( $delivery_id );

        if ( null === $row || 'failed' !== $row['status'] ) {
            return false;
        }

        if ( ! $this->deliveries->reset_for_retry( $delivery_id ) ) {
            return false;
        }

        $this->clear_delivery_events(
            (int) $row['post_id'],
            (string) $row['channel_id']
        );

        if ( ! $this->schedule_delivery( (int) $row['post_id'], (string) $row['channel_id'], 1 ) ) {
            $this->deliveries->mark_failed(
                $delivery_id,
                'schedule_failed',
                __( 'Could not schedule the retry.', 'lightweb2-publish-with-buffer' )
            );
            return false;
        }

        return true;
    }

    public function recover_stale(): void {
        foreach ( $this->deliveries->get_stale_sending( 15 ) as $row ) {
            if ( ! $this->deliveries->mark_stale( (int) $row['id'], 15 ) ) {
                continue;
            }

            if ( (int) $row['attempts'] < 4 ) {
                $this->schedule_delivery(
                    (int) $row['post_id'],
                    (string) $row['channel_id'],
                    (int) $row['attempts'] + 1
                );
            }
        }
    }

    public function cleanup_logs(): void {
        $days = (int) $this->settings->get()['retention_days'];
        $this->deliveries->delete_older_than_days( $days );
    }

    private function clear_delivery_events( int $post_id, string $channel_id ): void {
        for ( $attempt = 1; $attempt <= 4; $attempt++ ) {
            wp_clear_scheduled_hook(
                'lightweb2_deliver_channel',
                [ $post_id, $channel_id, $attempt ]
            );
        }
    }

    private function schedule_delivery(
        int $post_id,
        string $channel_id,
        int $attempt,
        int $delay = 1
    ): bool {
        $args = [ $post_id, $channel_id, $attempt ];

        if ( false !== wp_next_scheduled( 'lightweb2_deliver_channel', $args ) ) {
            return true;
        }

        return (bool) wp_schedule_single_event(
            time() + max( 1, $delay ),
            'lightweb2_deliver_channel',
            $args
        );
    }
}
