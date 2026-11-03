<?php
/**
 * Plugin service container.
 *
 * @package LightWeb2\PublishWithBuffer
 */

declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

/**
 * Wires the plugin services and registers their hooks.
 */
final class Plugin {

	/**
	 * Shared plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Buffer API client.
	 *
	 * @var Buffer_Client
	 */
	private Buffer_Client $client;

	/**
	 * Delivery repository.
	 *
	 * @var Deliveries
	 */
	private Deliveries $deliveries;

	/**
	 * Message builder.
	 *
	 * @var Message_Builder
	 */
	private Message_Builder $builder;

	/**
	 * Publisher service.
	 *
	 * @var Publisher
	 */
	private Publisher $publisher;

	/**
	 * Admin screens.
	 *
	 * @var Admin
	 */
	private Admin $admin;

	/**
	 * Post meta box.
	 *
	 * @var Meta_Box
	 */
	private Meta_Box $meta_box;

	/**
	 * Return the shared plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Build the services and register their hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->settings   = new Settings();
		$this->client     = new Buffer_Client();
		$this->deliveries = new Deliveries();
		$this->builder    = new Message_Builder();
		$this->publisher  = new Publisher(
			$this->settings,
			$this->deliveries,
			$this->client,
			$this->builder
		);
		$this->admin      = new Admin( $this->settings, $this->client, $this->deliveries, $this->publisher );
		$this->meta_box   = new Meta_Box( $this->settings, $this->deliveries );

		$this->settings->register();
		$this->admin->register();
		$this->meta_box->register();
		$this->publisher->register();
	}
}
