<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Admin;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Admin extends WP_UnitTestCase {

    public function test_settings_page_renders_without_printing_the_token(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        update_option(
            Settings::OPTION,
            [
                'token'    => 'secret-token-value',
                'channels' => [],
            ] + Settings::defaults()
        );

        $admin = new Admin( new Settings() );

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

        $admin = new Admin( new Settings() );

        ob_start();
        $admin->render_settings_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'General notice visible', $html );
    }
}
