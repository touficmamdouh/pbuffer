<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Admin;
use LightWeb2\PublishWithBuffer\Buffer_Client;
use LightWeb2\PublishWithBuffer\Deliveries;
use LightWeb2\PublishWithBuffer\Installer;
use LightWeb2\PublishWithBuffer\Message_Builder;
use LightWeb2\PublishWithBuffer\Publisher;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Activity extends WP_UnitTestCase {

    private Deliveries $deliveries;
    private Publisher $publisher;
    private Admin $admin;

    public function set_up(): void {
        parent::set_up();
        Installer::create_table();

        $settings         = new Settings();
        $this->deliveries = new Deliveries();
        $this->deliveries->clear();
        $this->publisher  = new Publisher(
            $settings,
            $this->deliveries,
            new Buffer_Client(),
            new Message_Builder()
        );
        $this->admin      = new Admin(
            $settings,
            new Buffer_Client(),
            $this->deliveries,
            $this->publisher
        );

        wp_clear_scheduled_hook( 'lightweb2_deliver_channel' );
    }

    public function test_activity_page_renders_failed_delivery_and_retry_button(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $row = $this->deliveries->create_or_get(
            50,
            [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
            'addToQueue'
        );
        $this->deliveries->mark_failed( (int) $row['id'], 'server_error', 'Try later' );

        ob_start();
        $this->admin->render_activity_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Activity', $html );
        $this->assertStringContainsString( 'Company LinkedIn', $html );
        $this->assertStringContainsString( 'lightweb2-retry', $html );
    }

    public function test_retry_resets_failed_delivery_and_schedules_worker(): void {
        $row = $this->deliveries->create_or_get(
            51,
            [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
            'addToQueue'
        );
        $this->deliveries->mark_failed( (int) $row['id'], 'server_error', 'Try later' );

        $this->assertTrue( $this->publisher->retry( (int) $row['id'] ) );

        $updated = $this->deliveries->get( (int) $row['id'] );
        $this->assertSame( 'pending', $updated['status'] );
        $this->assertNotFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ 51, 'c1', 1 ] )
        );
    }

    public function test_local_date_filter_selects_delivery_using_utc_storage(): void {
        global $wpdb;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        update_option( 'timezone_string', 'America/New_York' );

        $match = $this->deliveries->create_or_get(
            52,
            [ 'id' => 'c1', 'name' => 'Local Match', 'service' => 'linkedin' ],
            'addToQueue'
        );
        $other = $this->deliveries->create_or_get(
            53,
            [ 'id' => 'c2', 'name' => 'Outside Range', 'service' => 'linkedin' ],
            'addToQueue'
        );

        $wpdb->update(
            $wpdb->prefix . 'lightweb2_deliveries',
            [ 'created_at' => '2026-01-15 02:00:00' ],
            [ 'id' => (int) $match['id'] ]
        );
        $wpdb->update(
            $wpdb->prefix . 'lightweb2_deliveries',
            [ 'created_at' => '2026-01-13 02:00:00' ],
            [ 'id' => (int) $other['id'] ]
        );

        $_GET['date_from'] = '2026-01-14';
        $_GET['date_to']   = '2026-01-14';

        ob_start();
        $this->admin->render_activity_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Local Match', $html );
        $this->assertStringNotContainsString( 'Outside Range', $html );
    }
}
