<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Buffer_Client;
use LightWeb2\PublishWithBuffer\Deliveries;
use LightWeb2\PublishWithBuffer\Installer;
use LightWeb2\PublishWithBuffer\Message_Builder;
use LightWeb2\PublishWithBuffer\Publisher;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Publisher extends WP_UnitTestCase {

    private Deliveries $deliveries;
    private Settings $settings;
    private Publisher $publisher;

    public function set_up(): void {
        parent::set_up();
        Installer::create_table();

        $this->deliveries = new Deliveries();
        $this->deliveries->clear();
        $this->settings = new Settings();

        update_option(
            Settings::OPTION,
            [
                'token'    => 'test-token',
                'channels' => [
                    [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
                ],
            ] + Settings::defaults()
        );

        $this->publisher = new Publisher(
            $this->settings,
            $this->deliveries,
            new Buffer_Client(),
            new Message_Builder()
        );

        wp_clear_scheduled_hook( 'lightweb2_deliver_channel' );
    }

    public function tear_down(): void {
        remove_all_filters( 'pre_http_request' );
        wp_clear_scheduled_hook( 'lightweb2_deliver_channel' );
        parent::tear_down();
    }

    private function published_post( bool $enabled = true ): int {
        $post_id = self::factory()->post->create(
            [
                'post_status' => 'publish',
                'post_title'  => 'A Buffer post',
                'post_content' => 'Hello from WordPress.',
            ]
        );

        update_post_meta( $post_id, '_lightweb2_enabled', $enabled ? 'yes' : 'no' );
        update_post_meta( $post_id, '_lightweb2_mode', 'addToQueue' );

        return $post_id;
    }

    private function draft_before(): WP_Post {
        return self::factory()->post->create_and_get( [ 'post_status' => 'draft' ] );
    }

    private function mock_success(): void {
        add_filter(
            'pre_http_request',
            static function (): array {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(
                        [
                            'data' => [
                                'createPost' => [
                                    'post' => [
                                        'id'   => 'buffer-1',
                                        'text' => 'Hello',
                                    ],
                                ],
                            ],
                        ]
                    ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );
    }

    private function mock_server_error(): void {
        add_filter(
            'pre_http_request',
            static function (): array {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( [ 'errors' => [ [ 'message' => 'Outage' ] ] ] ),
                    'response' => [ 'code' => 500, 'message' => 'Server error' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );
    }

    private function mock_unauthorized(): void {
        add_filter(
            'pre_http_request',
            static function (): array {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(
                        [
                            'errors' => [
                                [
                                    'message'    => 'Unauthorized',
                                    'extensions' => [ 'code' => 'UNAUTHORIZED' ],
                                ],
                            ],
                        ]
                    ),
                    'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );
    }

    public function test_publish_creates_delivery_and_schedules_worker(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );

        $rows = $this->deliveries->get_for_post( $post_id );

        $this->assertCount( 1, $rows );
        $this->assertSame( 'pending', $rows[0]['status'] );
        $this->assertNotFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ $post_id, 'c1', 1 ] )
        );
    }

    public function test_disabled_post_is_skipped(): void {
        $post_id = $this->published_post( false );
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );

        $this->assertCount( 0, $this->deliveries->get_for_post( $post_id ) );
    }

    public function test_category_filter_skips_other_posts(): void {
        $allowed = self::factory()->category->create( [ 'name' => 'Allowed' ] );
        update_option(
            Settings::OPTION,
            [
                'token'           => 'test-token',
                'channels'        => [
                    [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
                ],
                'category_filter' => $allowed,
            ] + Settings::defaults()
        );

        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );

        $this->assertCount( 0, $this->deliveries->get_for_post( $post_id ) );
    }

    public function test_success_marks_delivery_sent(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );
        $this->mock_success();

        $this->publisher->deliver( $post_id, 'c1', 1 );
        $row = $this->deliveries->get_by_post_channel( $post_id, 'c1' );

        $this->assertSame( 'sent', $row['status'] );
        $this->assertSame( 'buffer-1', $row['buffer_post_id'] );
    }

    public function test_temporary_failure_schedules_retry(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );
        $this->mock_server_error();

        $this->publisher->deliver( $post_id, 'c1', 1 );
        $row = $this->deliveries->get_by_post_channel( $post_id, 'c1' );

        $this->assertSame( 'failed', $row['status'] );
        $this->assertSame( 'server_error', $row['error_code'] );
        $this->assertNotFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ $post_id, 'c1', 2 ] )
        );
    }

    public function test_sent_delivery_is_not_retried(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );

        $requests = 0;

        add_filter(
            'pre_http_request',
            static function () use ( &$requests ): array {
                ++$requests;

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(
                        [
                            'data' => [
                                'createPost' => [
                                    'post' => [ 'id' => 'buffer-1', 'text' => 'Hello' ],
                                ],
                            ],
                        ]
                    ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );

        $this->publisher->deliver( $post_id, 'c1', 1 );
        $this->publisher->deliver( $post_id, 'c1', 2 );

        $this->assertSame( 1, $requests );
    }

    public function test_unpublished_post_is_canceled(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );

        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
        $this->publisher->deliver( $post_id, 'c1', 1 );

        $row = $this->deliveries->get_by_post_channel( $post_id, 'c1' );
        $this->assertSame( 'canceled', $row['status'] );
    }

    public function test_unauthorized_failure_does_not_schedule_retry(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );
        $this->mock_unauthorized();

        $this->publisher->deliver( $post_id, 'c1', 1 );
        $row = $this->deliveries->get_by_post_channel( $post_id, 'c1' );

        $this->assertSame( 'failed', $row['status'] );
        $this->assertSame( 'unauthorized', $row['error_code'] );
        $this->assertFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ $post_id, 'c1', 2 ] )
        );
    }

    public function test_retryable_failure_stops_at_four_attempts(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );
        $this->mock_server_error();

        for ( $attempt = 1; $attempt <= 4; $attempt++ ) {
            $this->publisher->deliver( $post_id, 'c1', $attempt );
        }

        $row = $this->deliveries->get_by_post_channel( $post_id, 'c1' );
        $this->assertSame( 'failed', $row['status'] );
        $this->assertSame( 4, (int) $row['attempts'] );
        $this->assertFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ $post_id, 'c1', 5 ] )
        );
    }

    public function test_stale_event_does_not_claim_or_call_buffer(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );
        $before  = $this->deliveries->get_by_post_channel( $post_id, 'c1' );
        $requests = 0;

        add_filter(
            'pre_http_request',
            static function () use ( &$requests ): array {
                ++$requests;

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(
                        [
                            'data' => [
                                'createPost' => [
                                    'post' => [ 'id' => 'buffer-1', 'text' => 'Hello' ],
                                ],
                            ],
                        ]
                    ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );

        $this->publisher->deliver( $post_id, 'c1', 2 );
        $after = $this->deliveries->get_by_post_channel( $post_id, 'c1' );

        $this->assertSame( 0, $requests );
        $this->assertSame( 'pending', $after['status'] );
        $this->assertSame( 0, (int) $after['attempts'] );
        $this->assertSame( $before['updated_at'], $after['updated_at'] );
    }

    public function test_manual_retry_resets_attempts_and_schedules_first_attempt(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );
        $this->mock_server_error();

        $this->publisher->deliver( $post_id, 'c1', 1 );
        $this->publisher->deliver( $post_id, 'c1', 2 );

        $row = $this->deliveries->get_by_post_channel( $post_id, 'c1' );
        $this->assertSame( 2, (int) $row['attempts'] );

        $this->assertTrue( $this->publisher->retry( (int) $row['id'] ) );
        $retried = $this->deliveries->get_by_post_channel( $post_id, 'c1' );

        $this->assertSame( 'pending', $retried['status'] );
        $this->assertSame( 0, (int) $retried['attempts'] );
        $this->assertNull( $retried['error_code'] );
        $this->assertFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ $post_id, 'c1', 2 ] )
        );
        $this->assertFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ $post_id, 'c1', 3 ] )
        );
        $this->assertNotFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ $post_id, 'c1', 1 ] )
        );
    }

    public function test_canceled_delivery_is_not_rescheduled_by_publish(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );
        $row = $this->deliveries->get_by_post_channel( $post_id, 'c1' );
        $this->deliveries->mark_canceled( (int) $row['id'] );
        wp_clear_scheduled_hook( 'lightweb2_deliver_channel', [ $post_id, 'c1', 1 ] );
        $this->assertFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ $post_id, 'c1', 1 ] )
        );

        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );
        $canceled = $this->deliveries->get_by_post_channel( $post_id, 'c1' );

        $this->assertSame( 'canceled', $canceled['status'] );
        $this->assertFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ $post_id, 'c1', 1 ] )
        );
    }

    public function test_recover_stale_marks_failed_and_schedules_next_attempt(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );
        $row     = $this->deliveries->get_by_post_channel( $post_id, 'c1' );
        $this->assertTrue( $this->deliveries->claim( (int) $row['id'], 1 ) );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'lightweb2_deliveries',
            [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ) ],
            [ 'id' => (int) $row['id'] ]
        );

        $this->publisher->recover_stale();
        $recovered = $this->deliveries->get_by_post_channel( $post_id, 'c1' );

        $this->assertSame( 'failed', $recovered['status'] );
        $this->assertSame( 'stale_claim', $recovered['error_code'] );
        $this->assertNotFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ $post_id, 'c1', 2 ] )
        );
    }
}
