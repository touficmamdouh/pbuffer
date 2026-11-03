<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Installer;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Installer extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        Installer::activate();
    }

    public function test_deliveries_table_is_created(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'lightweb2_deliveries';

        $this->assertSame(
            'id',
            $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'id'" )
        );
    }

    public function test_default_settings_are_created_without_autoload(): void {
        global $wpdb;

        $settings = get_option( Settings::OPTION );

        $this->assertSame( 'addToQueue', $settings['default_mode'] );
        $this->assertSame( 30, $settings['retention_days'] );
        $this->assertSame(
            'no',
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
                    Settings::OPTION
                )
            )
        );
    }

    public function test_deactivation_preserves_settings_and_deliveries(): void {
        global $wpdb;

        update_option( Settings::OPTION, [ 'token' => 'keep-me' ] + Settings::defaults() );
        $wpdb->insert(
            $wpdb->prefix . 'lightweb2_deliveries',
            [
                'post_id'    => 123,
                'channel_id' => 'channel-1',
                'status'     => 'pending',
                'created_at' => gmdate( 'Y-m-d H:i:s' ),
                'updated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );

        Installer::deactivate();

        $this->assertSame( 'keep-me', get_option( Settings::OPTION )['token'] );
        $this->assertSame(
            '1',
            $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}lightweb2_deliveries WHERE post_id = 123"
            )
        );
    }

    public function test_uninstall_removes_settings_and_deliveries_table(): void {
        global $wpdb;

        Installer::activate();
        Installer::uninstall();

        $this->assertFalse( get_option( Settings::OPTION ) );
        $this->assertNull(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SHOW TABLES LIKE %s',
                    $wpdb->esc_like( $wpdb->prefix . 'lightweb2_deliveries' )
                )
            )
        );

        Installer::activate();
    }
}
