<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Deliveries;
use LightWeb2\PublishWithBuffer\Installer;
use LightWeb2\PublishWithBuffer\Meta_Box;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Meta_Box extends WP_UnitTestCase {

    private Meta_Box $meta_box;

    public function set_up(): void {
        parent::set_up();
        Installer::create_table();
        update_option( Settings::OPTION, Settings::defaults() );
        $this->meta_box = new Meta_Box( new Settings(), new Deliveries() );
    }

    public function tear_down(): void {
        unset(
            $_POST['lightweb2_meta_nonce'],
            $_POST['lightweb2_enabled'],
            $_POST['lightweb2_mode']
        );
        parent::tear_down();
    }

    public function test_admin_can_enable_sending_and_select_mode(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );
        $post_id = self::factory()->post->create( [ 'post_author' => $user_id ] );

        $_POST['lightweb2_meta_nonce'] = wp_create_nonce( 'lightweb2_meta' );
        $_POST['lightweb2_enabled']    = 'yes';
        $_POST['lightweb2_mode']       = 'shareNow';

        $this->meta_box->save( $post_id );

        $this->assertSame( 'yes', get_post_meta( $post_id, '_lightweb2_enabled', true ) );
        $this->assertSame( 'shareNow', get_post_meta( $post_id, '_lightweb2_mode', true ) );
    }

    public function test_invalid_mode_falls_back_to_add_to_queue(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );
        $post_id = self::factory()->post->create( [ 'post_author' => $user_id ] );

        $_POST['lightweb2_meta_nonce'] = wp_create_nonce( 'lightweb2_meta' );
        $_POST['lightweb2_enabled']    = 'yes';
        $_POST['lightweb2_mode']       = 'invalid';

        $this->meta_box->save( $post_id );

        $this->assertSame( 'addToQueue', get_post_meta( $post_id, '_lightweb2_mode', true ) );
    }

    public function test_subscriber_cannot_change_meta(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $user_id );
        $post_id = self::factory()->post->create();

        $_POST['lightweb2_meta_nonce'] = wp_create_nonce( 'lightweb2_meta' );
        $_POST['lightweb2_enabled']    = 'yes';

        $this->meta_box->save( $post_id );

        $this->assertSame( '', get_post_meta( $post_id, '_lightweb2_enabled', true ) );
    }

    public function test_page_cannot_change_post_meta(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );
        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_author' => $user_id,
            ]
        );

        $_POST['lightweb2_meta_nonce'] = wp_create_nonce( 'lightweb2_meta' );
        $_POST['lightweb2_enabled']    = 'yes';

        $this->meta_box->save( $page_id );

        $this->assertSame( '', get_post_meta( $page_id, '_lightweb2_enabled', true ) );
    }

    public function test_missing_nonce_does_not_change_meta(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );
        $post_id = self::factory()->post->create( [ 'post_author' => $user_id ] );

        $_POST['lightweb2_enabled'] = 'yes';

        $this->meta_box->save( $post_id );

        $this->assertSame( '', get_post_meta( $post_id, '_lightweb2_enabled', true ) );
    }
}
