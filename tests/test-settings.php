<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Settings;

final class Test_Settings extends WP_UnitTestCase {

    private Settings $settings;

    public function set_up(): void {
        parent::set_up();
        $this->settings = new Settings();
        update_option(
            Settings::OPTION,
            [
                'token'           => 'old-token',
                'channels'        => [
                    [ 'id' => 'c1', 'name' => 'One', 'service' => 'linkedin' ],
                ],
                'default_mode'    => 'shareNow',
                'template'        => '{title}',
                'category_filter' => 0,
                'retention_days'  => 30,
            ]
        );
    }

    public function test_empty_token_preserves_existing_token_and_channels(): void {
        $result = $this->settings->sanitize(
            [
                'token'  => '',
                'channels' => [],
            ]
        );

        $this->assertSame( 'old-token', $result['token'] );
        $this->assertSame( 'c1', $result['channels'][0]['id'] );
    }

    public function test_changed_token_clears_channels(): void {
        $result = $this->settings->sanitize(
            [
                'token'             => 'new-token',
                'channels'          => [
                    [ 'id' => 'c2', 'name' => 'Two', 'service' => 'x' ],
                ],
                'channels_submitted' => 1,
            ]
        );

        $this->assertSame( 'new-token', $result['token'] );
        $this->assertSame( [], $result['channels'] );
    }

    public function test_submitted_empty_channels_clear_existing_channels(): void {
        $result = $this->settings->sanitize(
            [
                'token'              => '',
                'channels'           => [],
                'channels_submitted' => 1,
            ]
        );

        $this->assertSame( 'old-token', $result['token'] );
        $this->assertSame( [], $result['channels'] );
    }

    public function test_invalid_mode_falls_back_to_add_to_queue(): void {
        $result = $this->settings->sanitize(
            [
                'token'        => '',
                'default_mode' => 'invalid-mode',
            ]
        );

        $this->assertSame( 'addToQueue', $result['default_mode'] );
    }

    public function test_retention_days_are_clamped(): void {
        $low  = $this->settings->sanitize( [ 'retention_days' => 0 ] );
        $high = $this->settings->sanitize( [ 'retention_days' => 9999 ] );

        $this->assertSame( 1, $low['retention_days'] );
        $this->assertSame( 365, $high['retention_days'] );
    }

    public function test_channels_are_sanitized_and_deduplicated(): void {
        $result = $this->settings->sanitize(
            [
                'token'              => '',
                'channels_submitted' => 1,
                'channels'           => [
                    [ 'id' => ' c1 ', 'name' => '<b>One</b>', 'service' => 'LinkedIn' ],
                    [ 'id' => 'c1', 'name' => 'Duplicate', 'service' => 'x' ],
                    [ 'id' => 'c2', 'name' => 'Two', 'service' => 'instagram' ],
                ],
            ]
        );

        $this->assertCount( 2, $result['channels'] );
        $this->assertSame( 'c1', $result['channels'][0]['id'] );
        $this->assertSame( 'One', $result['channels'][0]['name'] );
        $this->assertSame( 'linkedin', $result['channels'][0]['service'] );
    }
}
