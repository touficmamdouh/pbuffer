<?php
/**
 * Checks GitHub Releases for plugin updates.
 *
 * @package LightWeb2\PublishWithBuffer
 */

declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

/**
 * Integrates GitHub Releases with the WordPress plugin updater.
 */
final class Updater {

	/**
	 * GitHub Releases API endpoint.
	 */
	private const RELEASES_URL = 'https://api.github.com/repos/touficmamdouh/pbuffer/releases/latest';

	/**
	 * Cached release transient.
	 */
	private const CACHE_KEY = 'lightweb2_github_release';

	/**
	 * Register WordPress update hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
	}

	/**
	 * Add the latest GitHub release to the plugin update transient.
	 *
	 * @param mixed $transient Plugin update transient.
	 * @return mixed
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked[ LIGHTWEB2_PLUGIN_BASENAME ] ) ) {
			return $transient;
		}

		$release = $this->latest_release();

		if ( null === $release || ! version_compare( $release['version'], LIGHTWEB2_VERSION, '>' ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ LIGHTWEB2_PLUGIN_BASENAME ] = (object) array(
			'slug'         => 'lightweb2-publish-with-buffer',
			'plugin'       => LIGHTWEB2_PLUGIN_BASENAME,
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '6.5',
			'tested'       => '7.1',
			'requires_php' => '7.4',
		);

		return $transient;
	}

	/**
	 * Provide plugin information for the update details modal.
	 *
	 * @param mixed  $result Existing API result.
	 * @param string $action API action.
	 * @param object $args API arguments.
	 * @return mixed
	 */
	public function plugin_information( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'lightweb2-publish-with-buffer' !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();

		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'LightWeb2 Publish with Buffer',
			'slug'          => 'lightweb2-publish-with-buffer',
			'version'       => $release['version'],
			'author'        => '<a href="https://lightweb2.com/">LightWeb2</a>',
			'homepage'      => 'https://lightweb2.com/',
			'requires'      => '6.5',
			'tested'        => '7.1',
			'requires_php'  => '7.4',
			'download_link' => $release['package'],
			'last_updated'  => $release['published_at'],
			'icons'         => array(
				'svg' => 'https://raw.githubusercontent.com/touficmamdouh/pbuffer/master/assets/icon.svg',
			),
			'sections'      => array(
				'description' => 'Publish WordPress posts to Buffer channels with per-channel tracking, duplicate prevention, and safe retries.',
				'changelog'   => $release['changelog'],
			),
		);
	}

	/**
	 * Retrieve and validate the latest GitHub release.
	 *
	 * @return array{version: string, package: string, url: string, published_at: string, changelog: string}|null
	 */
	private function latest_release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && ! empty( $cached['version'] ) && ! empty( $cached['package'] ) ) {
			return $cached;
		}

		$response = wp_safe_remote_get(
			self::RELEASES_URL,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'LightWeb2-Publish-with-Buffer/' . LIGHTWEB2_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$tag  = is_array( $data ) && isset( $data['tag_name'] )
			? sanitize_text_field( (string) $data['tag_name'] )
			: '';

		if ( ! is_array( $data ) || '' === $tag || ! preg_match( '/^v?\d+\.\d+\.\d+$/', $tag ) ) {
			return null;
		}

		$version = ltrim( $tag, 'v' );
		$package = '';

		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( is_array( $asset ) && 'lightweb2-publish-with-buffer.zip' === ( $asset['name'] ?? '' ) ) {
					$package = esc_url_raw( (string) ( $asset['browser_download_url'] ?? '' ) );
					break;
				}
			}
		}

		if ( '' === $package ) {
			$package = 'https://github.com/touficmamdouh/pbuffer/releases/download/' . rawurlencode( $tag ) . '/lightweb2-publish-with-buffer.zip';
		}

		$release = array(
			'version'      => $version,
			'package'      => $package,
			'url'          => esc_url_raw( (string) ( $data['html_url'] ?? 'https://github.com/touficmamdouh/pbuffer/releases' ) ),
			'published_at' => sanitize_text_field( (string) ( $data['published_at'] ?? '' ) ),
			'changelog'    => wpautop( esc_html( (string) ( $data['body'] ?? '' ) ) ),
		);

		set_site_transient( self::CACHE_KEY, $release, 12 * HOUR_IN_SECONDS );

		return $release;
	}
}
