<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Plugin {

    private static ?Plugin $instance = null;

    private Settings $settings;
    private Admin $admin;

    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void {
        $this->settings = new Settings();
        $this->admin    = new Admin( $this->settings );

        $this->settings->register();
        $this->admin->register();
    }
}
