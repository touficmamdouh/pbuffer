<?php
/**
 * Plugin settings storage and sanitization.
 *
 * @package LightWeb2\PublishWithBuffer
 */

declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

/**
 * Reads, writes, and validates plugin settings.
 */
final class Settings {

	/**
	 * Option name for plugin settings.
	 */
	public const OPTION = 'lightweb2_settings';

	/**
	 * Register the settings hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	/**
	 * Register the settings option.
	 *
	 * @return void
	 */
	public function register_setting(): void {
		register_setting(
			'lightweb2_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Return default settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'token'           => '',
			'channels'        => array(),
			'default_mode'    => 'addToQueue',
			'template'        => "{title}\n\n{excerpt}\n\n{url}\n\n{tags}",
			'category_filter' => 0,
			'retention_days'  => 30,
		);
	}

	/**
	 * Return settings merged with defaults.
	 *
	 * @return array
	 */
	public function get(): array {
		$value = get_option( self::OPTION, array() );
		$value = is_array( $value ) ? $value : array();

		return wp_parse_args( $value, self::defaults() );
	}

	/**
	 * Build the transient key for a token's channel catalog.
	 *
	 * @param string $token Buffer API token.
	 * @return string
	 */
	public static function catalog_key( string $token ): string {
		return 'lightweb2_channels_' . substr( md5( $token ), 0, 12 );
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw settings input.
	 * @return array
	 */
	public function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = $this->get();

		$token = isset( $input['token'] )
			? sanitize_text_field( (string) $input['token'] )
			: '';

		if ( '' === $token ) {
			$token = (string) $current['token'];
		}

		$token_changed = $token !== (string) $current['token'];

		if ( $token_changed ) {
			delete_transient( self::catalog_key( (string) $current['token'] ) );
			delete_transient( self::catalog_key( $token ) );
			$channels = array();
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					'lightweb2',
					'lightweb2_token_changed',
					__( 'The Buffer token changed. Load and select your channels again.', 'lightweb2-publish-with-buffer' ),
					'warning'
				);
			}
		} elseif ( ! empty( $input['channels_submitted'] ) ) {
			$channels = $this->sanitize_channels( $input['channels'] ?? array(), (string) $current['token'] );
		} else {
			$channels = is_array( $current['channels'] ) ? $current['channels'] : array();
		}

		$mode = isset( $input['default_mode'] )
			? sanitize_text_field( (string) $input['default_mode'] )
			: (string) $current['default_mode'];

		if ( ! in_array( $mode, array( 'shareNow', 'addToQueue' ), true ) ) {
			$mode = 'addToQueue';
		}

		$template = isset( $input['template'] )
			? sanitize_textarea_field( (string) $input['template'] )
			: (string) $current['template'];

		if ( '' === trim( $template ) ) {
			$template = self::defaults()['template'];
		}

		$retention = isset( $input['retention_days'] )
			? absint( $input['retention_days'] )
			: (int) $current['retention_days'];

		$retention = max( 1, min( 365, $retention ) );

		return array(
			'token'           => $token,
			'channels'        => $channels,
			'default_mode'    => $mode,
			'template'        => $template,
			'category_filter' => isset( $input['category_filter'] )
				? absint( $input['category_filter'] )
				: (int) $current['category_filter'],
			'retention_days'  => $retention,
		);
	}

	/**
	 * Sanitize selected channels.
	 *
	 * @param mixed  $input Raw channel input.
	 * @param string $token Buffer API token.
	 * @return array
	 */
	public function sanitize_channels( $input, string $token = '' ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$catalog = array();
		$cache   = '' !== $token ? get_transient( self::catalog_key( $token ) ) : false;

		$current_channels = $this->get()['channels'];

		if ( is_array( $current_channels ) ) {
			foreach ( $current_channels as $existing ) {
				if ( isset( $existing['id'] ) ) {
					$catalog[ (string) $existing['id'] ] = $existing;
				}
			}
		}

		if ( is_array( $cache ) ) {
			foreach ( $cache as $organization ) {
				foreach ( $organization['channels'] ?? array() as $channel ) {
					if ( isset( $channel['id'] ) ) {
						$catalog[ (string) $channel['id'] ] = $channel;
					}
				}
			}
		}

		$channels = array();
		$seen     = array();

		foreach ( $input as $channel ) {
			if ( is_string( $channel ) ) {
				$id      = substr( sanitize_text_field( $channel ), 0, 64 );
				$channel = $catalog[ $id ] ?? array(
					'id'      => $id,
					'name'    => '',
					'service' => '',
				);
			}

			if ( ! is_array( $channel ) ) {
				continue;
			}

			$id = isset( $channel['id'] )
				? substr( sanitize_text_field( (string) $channel['id'] ), 0, 64 )
				: '';

			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$channels[]  = array(
				'id'      => $id,
				'name'    => isset( $channel['name'] )
					? substr( sanitize_text_field( (string) $channel['name'] ), 0, 191 )
					: '',
				'service' => isset( $channel['service'] )
					? substr( sanitize_key( (string) $channel['service'] ), 0, 32 )
					: '',
			);
		}

		return $channels;
	}
}
