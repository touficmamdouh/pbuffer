<?php
/**
 * Schedules and performs Buffer deliveries.
 *
 * @package LightWeb2\PublishWithBuffer
 */

declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

/**
 * Coordinates publishing, retries, and cleanup.
 */
final class Publisher {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Delivery repository.
	 *
	 * @var Deliveries
	 */
	private Deliveries $deliveries;

	/**
	 * Buffer API client.
	 *
	 * @var Buffer_Client
	 */
	private Buffer_Client $client;

	/**
	 * Message builder.
	 *
	 * @var Message_Builder
	 */
	private Message_Builder $builder;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings   Settings service.
	 * @param Deliveries      $deliveries Delivery repository.
	 * @param Buffer_Client   $client     Buffer API client.
	 * @param Message_Builder $builder    Message builder.
	 */
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

	/**
	 * Register publishing hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'maybe_publish' ), 10, 4 );
		add_action( 'lightweb2_deliver_channel', array( $this, 'deliver' ), 10, 3 );
		add_action( 'lightweb2_cleanup_logs', array( $this, 'cleanup_logs' ) );
		add_action( 'lightweb2_recover_stale', array( $this, 'recover_stale' ) );
	}

	/**
	 * Schedule Buffer deliveries when a post is published.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post object.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before Post before the update.
	 * @return void
	 */
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

		if (
			$post_before instanceof \WP_Post
			&& 'publish' === $post_before->post_status
			&& ! empty( $this->deliveries->get_for_post( $post_id ) )
		) {
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

		$this->queue_post( $post_id );
	}

	/**
	 * Queue a published post for its selected Buffer channels.
	 *
	 * This is also used by the manual publishing screen and intentionally does
	 * not require the per-post automatic publishing toggle.
	 *
	 * @param int $post_id Post ID.
	 * @return array{success: bool, message: string, scheduled: int}
	 */
	public function queue_post( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return array(
				'success'   => false,
				'message'   => __( 'Only published posts can be sent to Buffer.', 'lightweb2-publish-with-buffer' ),
				'scheduled' => 0,
			);
		}

		$settings = $this->settings->get();

		if ( '' === (string) $settings['token'] || empty( $settings['channels'] ) ) {
			return array(
				'success'   => false,
				'message'   => __( 'Connect Buffer and select at least one channel first.', 'lightweb2-publish-with-buffer' ),
				'scheduled' => 0,
			);
		}

		$mode      = get_post_meta( $post_id, '_lightweb2_mode', true );
		$mode      = in_array( $mode, array( 'shareNow', 'addToQueue' ), true )
			? $mode
			: (string) $settings['default_mode'];
		$scheduled = 0;

		foreach ( $settings['channels'] as $channel ) {
			$row = $this->deliveries->create_or_get( $post_id, $channel, $mode );

			if ( empty( $row ) || in_array( $row['status'], array( 'sent', 'canceled' ), true ) ) {
				continue;
			}

			if ( (int) $row['attempts'] >= 4 ) {
				continue;
			}

			if ( $this->schedule_delivery( $post_id, (string) $channel['id'], (int) $row['attempts'] + 1 ) ) {
				++$scheduled;

				// Dispatch now so Queue mode does not depend solely on a working loopback cron.
				if ( apply_filters( 'lightweb2_dispatch_immediately', true, $post_id, (string) $channel['id'] ) ) {
					spawn_cron( time() );
					$this->deliver( $post_id, (string) $channel['id'], (int) $row['attempts'] + 1 );
				}
				wp_clear_scheduled_hook(
					'lightweb2_deliver_channel',
					array( $post_id, (string) $channel['id'], (int) $row['attempts'] + 1 )
				);
			}
		}

		if ( 0 === $scheduled ) {
			return array(
				'success'   => true,
				'message'   => __( 'This post is already sent or queued for the selected channels.', 'lightweb2-publish-with-buffer' ),
				'scheduled' => 0,
			);
		}

		return array(
			'success'   => true,
			/* translators: %d: number of channels queued. */
			'message'   => sprintf( _n( 'Post queued for %d channel.', 'Post queued for %d channels.', $scheduled, 'lightweb2-publish-with-buffer' ), $scheduled ),
			'scheduled' => $scheduled,
		);
	}

	/**
	 * Deliver a post to one channel.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $channel_id Buffer channel ID.
	 * @param int    $attempt    Attempt number.
	 * @return void
	 */
	public function deliver( int $post_id, string $channel_id, int $attempt = 1 ): void {
		$row = $this->deliveries->get_by_post_channel( $post_id, $channel_id );

		if ( null === $row || in_array( $row['status'], array( 'sent', 'canceled' ), true ) ) {
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

		$service = strtolower( (string) $row['service'] );
		$message = $this->builder->build_for_service( $post_id, (string) $settings['template'], $service );

		if ( '' === $message ) {
			$this->deliveries->mark_failed(
				(int) $row['id'],
				'empty_message',
				__( 'The message template produced an empty post.', 'lightweb2-publish-with-buffer' )
			);
			return;
		}

		$input = array(
			'text'           => $message,
			'channelId'      => $channel_id,
			'schedulingType' => 'automatic',
			'mode'           => in_array( $row['mode'], array( 'shareNow', 'addToQueue' ), true )
				? $row['mode']
				: (string) $settings['default_mode'],
		);

		if ( 'facebook' === $service ) {
			$input['metadata'] = array(
				'facebook' => array(
					'type' => 'post',
				),
			);
		}

		$image = $this->builder->image_url( $post_id );

		if ( null !== $image ) {
			$input['assets'] = array(
				array(
					'image' => array( 'url' => $image ),
				),
			);
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

		$delays = array(
			1 => 5 * MINUTE_IN_SECONDS,
			2 => 15 * MINUTE_IN_SECONDS,
			3 => HOUR_IN_SECONDS,
		);
		$delay  = (int) $result['retry_after'] > 0
			? (int) $result['retry_after']
			: ( $delays[ $attempt ] ?? HOUR_IN_SECONDS );

		$this->schedule_delivery( $post_id, $channel_id, $attempt + 1, $delay );
	}

	/**
	 * Retry a failed delivery.
	 *
	 * @param int $delivery_id Delivery ID.
	 * @return bool
	 */
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

	/**
	 * Recover deliveries stuck in the sending state.
	 *
	 * @return void
	 */
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

	/**
	 * Delete delivery history older than the retention period.
	 *
	 * @return void
	 */
	public function cleanup_logs(): void {
		$days = (int) $this->settings->get()['retention_days'];
		$this->deliveries->delete_older_than_days( $days );
	}

	/**
	 * Clear scheduled delivery events for a post and channel.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $channel_id Buffer channel ID.
	 * @return void
	 */
	private function clear_delivery_events( int $post_id, string $channel_id ): void {
		for ( $attempt = 1; $attempt <= 4; $attempt++ ) {
			wp_clear_scheduled_hook(
				'lightweb2_deliver_channel',
				array( $post_id, $channel_id, $attempt )
			);
		}
	}

	/**
	 * Schedule a single delivery event.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $channel_id Buffer channel ID.
	 * @param int    $attempt    Attempt number.
	 * @param int    $delay      Delay in seconds.
	 * @return bool
	 */
	private function schedule_delivery(
		int $post_id,
		string $channel_id,
		int $attempt,
		int $delay = 1
	): bool {
		$args = array( $post_id, $channel_id, $attempt );

		if ( false !== wp_next_scheduled( 'lightweb2_deliver_channel', $args ) ) {
			return true;
		}

		$scheduled = (bool) wp_schedule_single_event(
			time() + max( 0, $delay ),
			'lightweb2_deliver_channel',
			$args
		);

		return $scheduled;
	}
}
