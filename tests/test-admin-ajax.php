<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Admin;
use LightWeb2\PublishWithBuffer\Buffer_Client;
use LightWeb2\PublishWithBuffer\Deliveries;
use LightWeb2\PublishWithBuffer\Installer;
use LightWeb2\PublishWithBuffer\Message_Builder;
use LightWeb2\PublishWithBuffer\Publisher;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Admin_Ajax extends WP_Ajax_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        Installer::create_table();
        ( new Deliveries() )->clear();
        wp_clear_scheduled_hook( 'lightweb2_deliver_channel' );
        remove_action( 'admin_init', '_maybe_update_core' );
        remove_action( 'admin_init', '_maybe_update_plugins' );
        remove_action( 'admin_init', '_maybe_update_themes' );
        update_option(
            Settings::OPTION,
            [
                'token'    => 'saved-token',
                'channels' => [],
            ] + Settings::defaults()
        );
    }

    public function tear_down(): void {
        remove_all_filters( 'pre_http_request' );
        wp_clear_scheduled_hook( 'lightweb2_deliver_channel' );
        parent::tear_down();
    }

    private function admin(): Admin {
        $settings   = new Settings();
        $deliveries = new Deliveries();

        return new Admin(
            $settings,
            new Buffer_Client(),
            $deliveries,
            new Publisher( $settings, $deliveries, new Buffer_Client(), new Message_Builder() )
        );
    }

    private function prepare_nonce(): void {
        $nonce                   = wp_create_nonce( 'lightweb2_admin' );
        $_REQUEST['nonce']       = $nonce;
        $_POST['nonce']          = $nonce;
    }

    private function failed_delivery(): array {
        $deliveries = new Deliveries();
        $row        = $deliveries->create_or_get(
            60,
            [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
            'addToQueue'
        );
        $deliveries->mark_failed( (int) $row['id'], 'server_error', 'Try later' );

        return $row;
    }

    public function test_test_connection_requires_manage_options(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $this->prepare_nonce();
        $_POST['token'] = 'test-token';

        $requested = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$requested ): array {
                $requested = true;

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( [ 'data' => [ 'account' => [ 'name' => 'Should not happen' ] ] ] ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );

        try {
            $this->_handleAjax( 'lightweb2_test_connection' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected after wp_send_json_error().
        }

        $response = json_decode( $this->_last_response, true );

        $this->assertFalse( $requested );
        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'not allowed', $response['data']['message'] );
    }

    public function test_test_connection_rejects_missing_nonce(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        unset( $_POST['nonce'], $_REQUEST['nonce'] );
        $_POST['token'] = 'test-token';

        $requested = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$requested ): array {
                $requested = true;

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( [ 'data' => [ 'account' => [ 'name' => 'Should not happen' ] ] ] ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );

        $die_message = '';

        try {
            $this->_handleAjax( 'lightweb2_test_connection' );
        } catch ( WPAjaxDieStopException $e ) {
            $die_message = $e->getMessage();
        }

        $this->assertFalse( $requested );
        $this->assertSame( '-1', $die_message );
    }

    public function test_test_connection_returns_account_name(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->prepare_nonce();
        $_POST['token'] = 'test-token';

        add_filter(
            'pre_http_request',
            static function (): array {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(
                        [
                            'data' => [
                                'account' => [
                                    'id'            => 'acct-1',
                                    'name'          => 'Acme',
                                    'organizations' => [
                                        [ 'id' => 'org-1', 'name' => 'Acme Org' ],
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

        try {
            $this->_handleAjax( 'lightweb2_test_connection' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected after wp_send_json_success().
        }
        $response = json_decode( $this->_last_response, true );

        $this->assertTrue( $response['success'] );
        $this->assertSame( 'Acme', $response['data']['account'] );
        $this->assertSame( 1, $response['data']['organizations'] );
    }

    public function test_fetch_channels_returns_grouped_channels(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->prepare_nonce();
        $_POST['token'] = 'test-token';

        $responses = [
            [
                'data' => [
                    'account' => [
                        'id'            => 'acct-1',
                        'name'          => 'Acme',
                        'organizations' => [
                            [ 'id' => 'org-1', 'name' => 'Acme Org' ],
                        ],
                    ],
                ],
            ],
            [
                'data' => [
                    'channels' => [
                        [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
                    ],
                ],
            ],
        ];

        add_filter(
            'pre_http_request',
            static function () use ( &$responses ): array {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( array_shift( $responses ) ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );

        try {
            $this->_handleAjax( 'lightweb2_fetch_channels' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected after wp_send_json_success().
        }
        $response = json_decode( $this->_last_response, true );

        $this->assertTrue( $response['success'] );
        $this->assertSame( 'org-1', $response['data']['organizations'][0]['id'] );
        $this->assertSame( 'Company LinkedIn', $response['data']['organizations'][0]['channels'][0]['name'] );
        $this->assertSame( $response['data']['organizations'], get_transient( Settings::catalog_key( 'test-token' ) ) );
    }

    public function test_disconnect_clears_state_and_keeps_option_non_autoloaded(): void {
        global $wpdb;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->prepare_nonce();
        update_option(
            Settings::OPTION,
            [
                'token'        => 'saved-token',
                'channels'     => [],
                'default_mode' => 'shareNow',
                'template'     => 'Keep this template',
            ] + Settings::defaults()
        );
        set_transient( Settings::catalog_key( 'saved-token' ), [ [ 'id' => 'org-1' ] ], HOUR_IN_SECONDS );

        try {
            $this->_handleAjax( 'lightweb2_disconnect' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected after wp_send_json_success().
        }

        $settings = ( new Settings() )->get();
        $response = json_decode( $this->_last_response, true );

        $this->assertTrue( $response['success'] );
        $this->assertSame( '', $settings['token'] );
        $this->assertSame( [], $settings['channels'] );
        $this->assertSame( 'shareNow', $settings['default_mode'] );
        $this->assertSame( 'Keep this template', $settings['template'] );
        $this->assertFalse( get_transient( Settings::catalog_key( 'saved-token' ) ) );
        $this->assertContains(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
                    Settings::OPTION
                )
            ),
            [ 'no', 'off' ]
        );
    }

    public function test_retry_delivery_requires_manage_options(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $this->prepare_nonce();
        $row = $this->failed_delivery();
        $_POST['delivery_id'] = (string) $row['id'];

        $requested = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$requested ): array {
                $requested = true;

                return [];
            }
        );

        try {
            $this->_handleAjax( 'lightweb2_retry_delivery' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected after wp_send_json_error().
        }

        $response = json_decode( $this->_last_response, true );
        $updated  = ( new Deliveries() )->get( (int) $row['id'] );

        $this->assertFalse( $requested );
        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'not allowed', $response['data']['message'] );
        $this->assertSame( 'failed', $updated['status'] );
        $this->assertFalse( wp_next_scheduled( 'lightweb2_deliver_channel', [ 60, 'c1', 1 ] ) );
    }

    public function test_retry_delivery_rejects_missing_nonce(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        unset( $_POST['nonce'], $_REQUEST['nonce'] );
        $row = $this->failed_delivery();
        $_POST['delivery_id'] = (string) $row['id'];

        $die_message = '';

        try {
            $this->_handleAjax( 'lightweb2_retry_delivery' );
        } catch ( WPAjaxDieStopException $e ) {
            $die_message = $e->getMessage();
        }

        $updated = ( new Deliveries() )->get( (int) $row['id'] );

        $this->assertSame( '-1', $die_message );
        $this->assertSame( 'failed', $updated['status'] );
    }

    public function test_retry_delivery_rejects_delivery_that_is_not_failed(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->prepare_nonce();
        $row = ( new Deliveries() )->create_or_get(
            61,
            [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
            'addToQueue'
        );
        $_POST['delivery_id'] = (string) $row['id'];

        try {
            $this->_handleAjax( 'lightweb2_retry_delivery' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected after wp_send_json_error().
        }

        $response = json_decode( $this->_last_response, true );
        $updated  = ( new Deliveries() )->get( (int) $row['id'] );

        $this->assertFalse( $response['success'] );
        $this->assertSame( 'This delivery cannot be retried.', $response['data']['message'] );
        $this->assertSame( 'pending', $updated['status'] );
        $this->assertSame( 0, (int) $updated['attempts'] );
        $this->assertFalse( wp_next_scheduled( 'lightweb2_deliver_channel', [ 61, 'c1', 1 ] ) );
    }

    public function test_retry_delivery_succeeds_for_failed_row(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->prepare_nonce();
        $row = $this->failed_delivery();
        $_POST['delivery_id'] = (string) $row['id'];

        try {
            $this->_handleAjax( 'lightweb2_retry_delivery' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected after wp_send_json_success().
        }

        $response = json_decode( $this->_last_response, true );
        $updated  = ( new Deliveries() )->get( (int) $row['id'] );

        $this->assertTrue( $response['success'] );
        $this->assertSame( 'pending', $updated['status'] );
        $this->assertSame( 0, (int) $updated['attempts'] );
        $this->assertNotFalse( wp_next_scheduled( 'lightweb2_deliver_channel', [ 60, 'c1', 1 ] ) );
    }

    public function test_clear_logs_requires_manage_options(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $this->prepare_nonce();
        $this->failed_delivery();

        try {
            $this->_handleAjax( 'lightweb2_clear_logs' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected after wp_send_json_error().
        }

        $response = json_decode( $this->_last_response, true );
        $count    = ( new Deliveries() )->count_recent();

        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'not allowed', $response['data']['message'] );
        $this->assertSame( 1, $count );
    }

    public function test_clear_logs_deletes_rows_for_administrator(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->prepare_nonce();
        $this->failed_delivery();

        try {
            $this->_handleAjax( 'lightweb2_clear_logs' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected after wp_send_json_success().
        }

        $response = json_decode( $this->_last_response, true );
        $count    = ( new Deliveries() )->count_recent();

        $this->assertTrue( $response['success'] );
        $this->assertSame( 0, $count );
    }

    public function test_bulk_manual_publish_requires_selected_posts(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->prepare_nonce();
        $_POST['post_ids'] = [];

        try {
            $this->_handleAjax( 'lightweb2_manual_publish_bulk' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected after wp_send_json_error().
        }

        $response = json_decode( $this->_last_response, true );

        $this->assertFalse( $response['success'] );
        $this->assertSame( 'Select at least one published post.', $response['data']['message'] );
    }
}
