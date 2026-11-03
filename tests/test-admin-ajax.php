<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Admin;
use LightWeb2\PublishWithBuffer\Buffer_Client;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Admin_Ajax extends WP_Ajax_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
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
        parent::tear_down();
    }

    private function admin(): Admin {
        return new Admin( new Settings(), new Buffer_Client() );
    }

    private function prepare_nonce(): void {
        $nonce                   = wp_create_nonce( 'lightweb2_admin' );
        $_REQUEST['nonce']       = $nonce;
        $_POST['nonce']          = $nonce;
    }

    public function test_test_connection_requires_manage_options(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $this->prepare_nonce();
        $_POST['token'] = 'test-token';

        $this->expectException( 'WPDieException' );
        $this->_handleAjax( 'lightweb2_test_connection' );
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

    public function test_disconnect_clears_token_channels_and_catalog(): void {
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
    }
}
