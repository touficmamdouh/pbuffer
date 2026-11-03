<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Plugin {

    private static ?Plugin $instance = null;

    private Settings $settings;
    private Buffer_Client $client;
    private Deliveries $deliveries;
    private Admin $admin;
    private Meta_Box $meta_box;

    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void {
        $this->settings   = new Settings();
        $this->client     = new Buffer_Client();
        $this->deliveries = new Deliveries();
        $this->admin      = new Admin( $this->settings, $this->client );
        $this->meta_box   = new Meta_Box( $this->settings, $this->deliveries );

        $this->settings->register();
        $this->admin->register();
        $this->meta_box->register();
    }
}
