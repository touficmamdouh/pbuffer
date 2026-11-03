<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Admin;
use LightWeb2\PublishWithBuffer\Buffer_Client;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Admin extends WP_UnitTestCase {

    public function tear_down(): void {
        wp_dequeue_script( 'lightweb2-admin' );
        wp_dequeue_style( 'lightweb2-admin' );
        parent::tear_down();
    }

    public function test_settings_page_renders_without_printing_the_token(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        update_option(
            Settings::OPTION,
            [
                'token'    => 'secret-token-value',
                'channels' => [],
            ] + Settings::defaults()
        );

        $admin = new Admin( new Settings(), new Buffer_Client() );

        ob_start();
        $admin->render_settings_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'LightWeb2', $html );
        $this->assertStringContainsString( 'lightweb2_settings[token]', $html );
        $this->assertStringContainsString( 'Token is set', $html );
        $this->assertStringNotContainsString( 'secret-token-value', $html );
    }

    public function test_settings_page_renders_general_settings_notices(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        add_settings_error( 'general', 'lightweb2_test_notice', 'General notice visible' );

        $admin = new Admin( new Settings(), new Buffer_Client() );

        ob_start();
        $admin->render_settings_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'General notice visible', $html );
    }

    public function test_enqueue_assets_localizes_only_public_settings(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        update_option(
            Settings::OPTION,
            [
                'token'    => 'secret-token-value',
                'channels' => [
                    [ 'id' => 'c1', 'name' => 'One', 'service' => 'linkedin' ],
                ],
            ] + Settings::defaults()
        );

        $admin = new Admin( new Settings(), new Buffer_Client() );
        $admin->enqueue_assets( 'toplevel_page_lightweb2' );
        $data = wp_scripts()->get_data( 'lightweb2-admin', 'data' );

        $this->assertIsString( $data );
        $this->assertStringNotContainsString( 'secret-token-value', $data );
        $this->assertStringContainsString( '"selectedChannels":["c1"]', $data );
    }

    public function test_post_editor_enqueues_admin_style_only(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $admin = new Admin( new Settings(), new Buffer_Client() );
        $admin->enqueue_assets( 'post.php' );

        $this->assertTrue( wp_style_is( 'lightweb2-admin', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'lightweb2-admin', 'enqueued' ) );
    }
}
