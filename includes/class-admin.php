<?php
/**
 * Admin screens and AJAX handlers.
 *
 * @package LightWeb2\PublishWithBuffer
 */

declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

/**
 * Builds the settings and activity screens.
 */
final class Admin {

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
	 * Publisher service.
	 *
	 * @var Publisher
	 */
	private Publisher $publisher;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings   Settings service.
	 * @param Buffer_Client $client     Buffer API client.
	 * @param Deliveries    $deliveries Delivery repository.
	 * @param Publisher     $publisher  Publisher service.
	 */
	public function __construct(
		Settings $settings,
		Buffer_Client $client,
		Deliveries $deliveries,
		Publisher $publisher
	) {
		$this->settings   = $settings;
		$this->client     = $client;
		$this->deliveries = $deliveries;
		$this->publisher  = $publisher;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_lightweb2_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_lightweb2_fetch_channels', array( $this, 'ajax_fetch_channels' ) );
		add_action( 'wp_ajax_lightweb2_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_lightweb2_manual_publish', array( $this, 'ajax_manual_publish' ) );
		add_action( 'wp_ajax_lightweb2_manual_publish_bulk', array( $this, 'ajax_manual_publish_bulk' ) );
		add_action( 'wp_ajax_lightweb2_retry_delivery', array( $this, 'ajax_retry_delivery' ) );
		add_action( 'wp_ajax_lightweb2_clear_logs', array( $this, 'ajax_clear_logs' ) );
	}

	/**
	 * Register the admin menu and submenus.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'LightWeb2', 'lightweb2-publish-with-buffer' ),
			__( 'LightWeb2', 'lightweb2-publish-with-buffer' ),
			'manage_options',
			'lightweb2',
			array( $this, 'render_settings_page' ),
			LIGHTWEB2_URL . 'assets/icon.svg',
			80
		);

		add_submenu_page(
			'lightweb2',
			__( 'Publish with Buffer', 'lightweb2-publish-with-buffer' ),
			__( 'Publish with Buffer', 'lightweb2-publish-with-buffer' ),
			'manage_options',
			'lightweb2',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'lightweb2',
			__( 'Activity', 'lightweb2-publish-with-buffer' ),
			__( 'Activity', 'lightweb2-publish-with-buffer' ),
			'manage_options',
			'lightweb2-activity',
			array( $this, 'render_activity_page' )
		);
	}

	/**
	 * Enqueue admin assets on plugin screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		$is_plugin_screen = in_array(
			$hook,
			array( 'toplevel_page_lightweb2', 'lightweb2_page_lightweb2-activity' ),
			true
		);
		$is_post_screen   = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

		if ( ! $is_plugin_screen && ! $is_post_screen ) {
			return;
		}

		wp_enqueue_style(
			'lightweb2-admin',
			LIGHTWEB2_URL . 'assets/admin.css',
			array(),
			LIGHTWEB2_VERSION
		);

		if ( ! $is_plugin_screen ) {
			return;
		}

		wp_enqueue_script(
			'lightweb2-admin',
			LIGHTWEB2_URL . 'assets/admin.js',
			array(),
			LIGHTWEB2_VERSION,
			true
		);

		$settings     = $this->settings->get();
		$recent_posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		wp_localize_script(
			'lightweb2-admin',
			'lightweb2Admin',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'lightweb2_admin' ),
				'selectedChannels' => array_values(
					array_map(
						static fn( array $channel ): string => (string) $channel['id'],
						$settings['channels']
					)
				),
				'previewValues'    => $this->get_preview_values( $recent_posts[0] ?? null ),
				'strings'          => array(
					'testing'           => __( 'Testing connection…', 'lightweb2-publish-with-buffer' ),
					'loading'           => __( 'Loading channels…', 'lightweb2-publish-with-buffer' ),
					/* translators: %s: Buffer account name. */
					'connected'         => __( 'Connected to Buffer as %s.', 'lightweb2-publish-with-buffer' ),
					'noChannels'        => __( 'No channels found in this Buffer account.', 'lightweb2-publish-with-buffer' ),
					'error'             => __( 'Could not reach Buffer. Check the token and try again.', 'lightweb2-publish-with-buffer' ),
					'confirmDisconnect' => __( 'Disconnect Buffer and clear the selected channels?', 'lightweb2-publish-with-buffer' ),
					'confirmClearLogs'  => __( 'Delete all delivery history? This cannot be undone. Clearing history also removes duplicate protection, so future publishes may be sent to Buffer again.', 'lightweb2-publish-with-buffer' ),
					'manualPublishing'  => __( 'Queueing post…', 'lightweb2-publish-with-buffer' ),
					'manualQueued'      => __( 'Queued', 'lightweb2-publish-with-buffer' ),
					'bulkPublishing'    => __( 'Queueing selected posts…', 'lightweb2-publish-with-buffer' ),
					'bulkQueued'        => __( 'Selected posts queued.', 'lightweb2-publish-with-buffer' ),
					'selectPosts'       => __( 'Select at least one post first.', 'lightweb2-publish-with-buffer' ),
					'insertVariable'    => __( 'Insert variable', 'lightweb2-publish-with-buffer' ),
				),
			)
		);
	}

	/**
	 * Verify the AJAX nonce and capability.
	 *
	 * @return void
	 */
	private function verify_ajax(): void {
		check_ajax_referer( 'lightweb2_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to manage Buffer settings.', 'lightweb2-publish-with-buffer' ) ),
				403
			);
		}
	}

	/**
	 * Read the submitted token, falling back to the stored token.
	 *
	 * @return string
	 */
	private function request_token(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by verify_ajax().
		$token = isset( $_POST['token'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $token ) {
			$token = (string) $this->settings->get()['token'];
		}

		return $token;
	}

	/**
	 * Test the Buffer connection over AJAX.
	 *
	 * @return void
	 */
	public function ajax_test_connection(): void {
		$this->verify_ajax();
		$token = $this->request_token();

		if ( '' === $token ) {
			wp_send_json_error(
				array( 'message' => __( 'Add a Buffer token first.', 'lightweb2-publish-with-buffer' ) ),
				400
			);
		}

		$result = $this->client->test_connection( $token );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ), 400 );
		}

		$account = $result['data']['account'] ?? array();

		wp_send_json_success(
			array(
				'account'       => sanitize_text_field( (string) ( $account['name'] ?? '' ) ),
				'organizations' => count( $account['organizations'] ?? array() ),
			)
		);
	}

	/**
	 * Load and cache channels over AJAX.
	 *
	 * @return void
	 */
	public function ajax_fetch_channels(): void {
		$this->verify_ajax();
		$token = $this->request_token();

		if ( '' === $token ) {
			wp_send_json_error(
				array( 'message' => __( 'Add a Buffer token first.', 'lightweb2-publish-with-buffer' ) ),
				400
			);
		}

		$account = $this->client->test_connection( $token );

		if ( ! $account['success'] ) {
			wp_send_json_error( array( 'message' => $account['message'] ), 400 );
		}

		$catalog = array();

		foreach ( $account['data']['account']['organizations'] ?? array() as $organization ) {
			$organization_id = sanitize_text_field( (string) ( $organization['id'] ?? '' ) );

			if ( '' === $organization_id ) {
				continue;
			}

			$channels = $this->client->get_channels( $token, $organization_id );

			if ( ! $channels['success'] ) {
				wp_send_json_error( array( 'message' => $channels['message'] ), 400 );
			}

			$normalized = array();

			foreach ( $channels['data']['channels'] ?? array() as $channel ) {
				$normalized[] = array(
					'id'      => sanitize_text_field( (string) ( $channel['id'] ?? '' ) ),
					'name'    => sanitize_text_field( (string) ( $channel['name'] ?? '' ) ),
					'service' => sanitize_key( (string) ( $channel['service'] ?? '' ) ),
				);
			}

			$catalog[] = array(
				'id'       => $organization_id,
				'name'     => sanitize_text_field( (string) ( $organization['name'] ?? '' ) ),
				'channels' => $normalized,
			);
		}

		set_transient( Settings::catalog_key( $token ), $catalog, 12 * HOUR_IN_SECONDS );

		wp_send_json_success( array( 'organizations' => $catalog ) );
	}

	/**
	 * Clear the stored token and channels over AJAX.
	 *
	 * @return void
	 */
	public function ajax_disconnect(): void {
		$this->verify_ajax();

		$settings = $this->settings->get();
		delete_transient( Settings::catalog_key( (string) $settings['token'] ) );

		// Clear first so sanitize() does not preserve the existing token.
		delete_option( Settings::OPTION );

		update_option(
			Settings::OPTION,
			array(
				'token'    => '',
				'channels' => array(),
			) + $settings,
			false
		);

		wp_send_json_success();
	}

	/**
	 * Queue a published post from the manual publishing screen.
	 *
	 * @return void
	 */
	public function ajax_manual_publish(): void {
		$this->verify_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by verify_ajax().
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->publisher->queue_post( $post_id );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ), 400 );
		}

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	/**
	 * Queue several published posts from the manual publishing screen.
	 *
	 * @return void
	 */
	public function ajax_manual_publish_bulk(): void {
		$this->verify_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by verify_ajax().
		$post_ids = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['post_ids'] ) ) ) ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$post_ids = array_slice( $post_ids, 0, 20 );

		if ( empty( $post_ids ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Select at least one published post.', 'lightweb2-publish-with-buffer' ) ),
				400
			);
		}

		$queued      = 0;
		$failed      = 0;
		$first_error = '';

		foreach ( $post_ids as $post_id ) {
			$result = $this->publisher->queue_post( $post_id );

			if ( $result['success'] ) {
				++$queued;
			} else {
				++$failed;
				if ( '' === $first_error ) {
					$first_error = (string) $result['message'];
				}
			}
		}

		if ( 0 === $queued ) {
			$message = '' !== $first_error
				? $first_error
				: __( 'No posts were queued.', 'lightweb2-publish-with-buffer' );
			wp_send_json_error( array( 'message' => $message ), 400 );
		}

		$message = sprintf(
			/* translators: 1: queued post count, 2: failed post count. */
			__( '%1$d post(s) queued. %2$d skipped.', 'lightweb2-publish-with-buffer' ),
			$queued,
			$failed
		);

		wp_send_json_success(
			array(
				'queued'  => $queued,
				'failed'  => $failed,
				'message' => $message,
			)
		);
	}

	/**
	 * Build real template values from a post for the admin preview.
	 *
	 * @param \WP_Post|null $post Preview post.
	 * @return array<string, string>
	 */
	private function get_preview_values( ?\WP_Post $post ): array {
		$sample = array(
			'{title}'    => __( 'A clearer way to publish', 'lightweb2-publish-with-buffer' ),
			'{url}'      => 'https://example.com/article',
			'{excerpt}'  => __( 'A short summary of the article.', 'lightweb2-publish-with-buffer' ),
			'{author}'   => 'LightWeb2',
			'{category}' => __( 'Product', 'lightweb2-publish-with-buffer' ),
			'{tags}'     => '#WordPress #Buffer',
		);

		if ( ! $post instanceof \WP_Post ) {
			return $sample;
		}

		$categories = get_the_category( $post->ID );
		$tags       = get_the_tags( $post->ID );
		$tag_text   = array();

		if ( is_array( $tags ) ) {
			foreach ( $tags as $tag ) {
				$tag_text[] = '#' . preg_replace( '/[^\p{L}\p{N}_-]+/u', '', (string) $tag->name );
			}
		}

		return array(
			'{title}'    => (string) get_the_title( $post ),
			'{url}'      => (string) get_permalink( $post ),
			'{excerpt}'  => (string) get_the_excerpt( $post ),
			'{author}'   => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'{category}' => isset( $categories[0] ) ? (string) $categories[0]->name : '',
			'{tags}'     => implode( ' ', array_filter( $tag_text ) ),
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings               = $this->settings->get();
		$has_token              = '' !== (string) $settings['token'];
		$recent_posts           = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$preview_values         = $this->get_preview_values( $recent_posts[0] ?? null );
		$selected_channel_count = count( $settings['channels'] );
		?>
		<div class="wrap lightweb2-wrap">
			<header class="lightweb2-header">
				<div class="lightweb2-header-copy">
					<p class="lightweb2-eyebrow"><span class="dashicons dashicons-share" aria-hidden="true"></span><?php esc_html_e( 'Publishing workspace', 'lightweb2-publish-with-buffer' ); ?></p>
					<h1><?php esc_html_e( 'Publish with Buffer', 'lightweb2-publish-with-buffer' ); ?></h1>
					<p class="lightweb2-lede"><?php esc_html_e( 'Connect Buffer, choose your channels, and share WordPress posts without the busywork.', 'lightweb2-publish-with-buffer' ); ?></p>
				</div>
				<div class="lightweb2-header-meta">
					<a class="lightweb2-header-brand" href="https://lightweb2.com/" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url( LIGHTWEB2_URL . 'assets/lightweb2-logo.png' ); ?>" alt="LightWeb2" /></a>
					<div class="lightweb2-header-stats"><span class="lightweb2-connection-chip <?php echo $has_token ? 'is-connected' : ''; ?>"><span class="lightweb2-status-mark" aria-hidden="true"></span><?php echo $has_token ? esc_html__( 'Connected to Buffer', 'lightweb2-publish-with-buffer' ) : esc_html__( 'Not connected', 'lightweb2-publish-with-buffer' ); ?></span><span class="lightweb2-stat"><strong><?php echo esc_html( (string) $selected_channel_count ); ?></strong> <?php esc_html_e( 'channels selected', 'lightweb2-publish-with-buffer' ); ?></span></div>
					<span class="lightweb2-version">v<?php echo esc_html( LIGHTWEB2_VERSION ); ?></span>
				</div>
			</header>

			<?php settings_errors(); ?>

			<nav class="lightweb2-local-nav" aria-label="<?php esc_attr_e( 'LightWeb2 sections', 'lightweb2-publish-with-buffer' ); ?>">
				<a class="is-active" href="#lightweb2-articles"><span class="dashicons dashicons-media-document" aria-hidden="true"></span><?php esc_html_e( 'Articles', 'lightweb2-publish-with-buffer' ); ?></a>
				<a href="#lightweb2-connection"><span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span><?php esc_html_e( 'Connection', 'lightweb2-publish-with-buffer' ); ?></a>
				<a href="#lightweb2-channels-section"><span class="dashicons dashicons-share-alt2" aria-hidden="true"></span><?php esc_html_e( 'Channels', 'lightweb2-publish-with-buffer' ); ?></a>
				<a href="#lightweb2-message"><span class="dashicons dashicons-edit" aria-hidden="true"></span><?php esc_html_e( 'Message', 'lightweb2-publish-with-buffer' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'lightweb2-activity' ), admin_url( 'admin.php' ) ) ); ?>"><span class="dashicons dashicons-clock" aria-hidden="true"></span><?php esc_html_e( 'Activity', 'lightweb2-publish-with-buffer' ); ?></a>
			</nav>

			<section id="lightweb2-articles" class="lightweb2-card lightweb2-card--manual-publish">
				<div class="lightweb2-section-heading">
					<div>
						<p class="lightweb2-eyebrow"><?php esc_html_e( 'Manual publishing', 'lightweb2-publish-with-buffer' ); ?></p>
						<h2><?php esc_html_e( 'Latest published articles', 'lightweb2-publish-with-buffer' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Select articles and send them to your selected Buffer channels.', 'lightweb2-publish-with-buffer' ); ?></p>
					</div>
					<div class="lightweb2-heading-actions"><span class="lightweb2-count"><?php echo esc_html( (string) count( $recent_posts ) ); ?>/20</span><button type="button" class="button lightweb2-refresh" onclick="window.location.reload()"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'Refresh', 'lightweb2-publish-with-buffer' ); ?></button></div>
				</div>
				<?php if ( ! empty( $recent_posts ) ) : ?>
					<div class="lightweb2-bulk-toolbar"><label class="lightweb2-check-label"><input type="checkbox" id="lightweb2-select-all" /><span><?php esc_html_e( 'Select all', 'lightweb2-publish-with-buffer' ); ?></span></label><button type="button" class="button button-primary lightweb2-bulk-publish" id="lightweb2-bulk-publish" disabled><span class="dashicons dashicons-share" aria-hidden="true"></span><?php esc_html_e( 'Publish selected', 'lightweb2-publish-with-buffer' ); ?></button></div>
				<?php endif; ?>
				<?php if ( empty( $recent_posts ) ) : ?>
					<p class="description"><?php esc_html_e( 'No published articles found yet.', 'lightweb2-publish-with-buffer' ); ?></p>
				<?php else : ?>
					<div class="lightweb2-post-list">
						<?php foreach ( $recent_posts as $recent_post ) : ?>
							<?php
							$recent_title = get_the_title( $recent_post );
							$categories   = get_the_category( $recent_post->ID );
							$category     = isset( $categories[0] ) ? $categories[0]->name : __( 'Uncategorized', 'lightweb2-publish-with-buffer' );
							?>
							<article class="lightweb2-post-row" data-post-row="<?php echo esc_attr( (string) $recent_post->ID ); ?>">
								<label class="lightweb2-post-check"><input type="checkbox" class="lightweb2-post-select" value="<?php echo esc_attr( (string) $recent_post->ID ); ?>" aria-label="<?php echo esc_attr( $recent_title ); ?>" /><span class="screen-reader-text"><?php echo esc_html( $recent_title ); ?></span></label>
								<div class="lightweb2-post-copy">
									<strong title="<?php echo esc_attr( $recent_title ); ?>"><?php echo esc_html( $recent_title ? $recent_title : __( '(Untitled)', 'lightweb2-publish-with-buffer' ) ); ?></strong>
									<small><?php echo esc_html( get_the_author_meta( 'display_name', (int) $recent_post->post_author ) ); ?></small>
								</div>
								<span class="lightweb2-category-badge"><?php echo esc_html( $category ); ?></span>
								<time datetime="<?php echo esc_attr( get_the_date( 'c', $recent_post ) ); ?>"><?php echo esc_html( get_the_date( '', $recent_post ) ); ?></time>
								<button type="button" class="button lightweb2-manual-publish" data-post-id="<?php echo esc_attr( (string) $recent_post->ID ); ?>"><span class="dashicons dashicons-share" aria-hidden="true"></span><?php esc_html_e( 'Publish now', 'lightweb2-publish-with-buffer' ); ?></button>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<form action="options.php" method="post" class="lightweb2-form">
				<?php settings_fields( 'lightweb2_settings_group' ); ?>

				<section id="lightweb2-connection" class="lightweb2-card lightweb2-card--connection">
					<h2><?php esc_html_e( 'Connection', 'lightweb2-publish-with-buffer' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Create a personal API key in Buffer, then paste it here.', 'lightweb2-publish-with-buffer' ); ?></p>
					<label for="lightweb2-token" class="lightweb2-label"><?php esc_html_e( 'Buffer API Token', 'lightweb2-publish-with-buffer' ); ?></label>
					<input
						type="password"
						id="lightweb2-token"
						name="lightweb2_settings[token]"
						value=""
						placeholder="<?php echo esc_attr( $has_token ? '********' : '' ); ?>"
						class="regular-text"
						autocomplete="new-password"
					/>
					<p class="lightweb2-status <?php echo $has_token ? 'is-connected' : ''; ?>" id="lightweb2-connection-status">
						<?php
						echo $has_token
							? esc_html__( 'Token is set. Enter a new one to replace it.', 'lightweb2-publish-with-buffer' )
							: esc_html__( 'Not connected yet.', 'lightweb2-publish-with-buffer' );
						?>
					</p>
					<p class="lightweb2-actions">
						<button type="button" class="button" id="lightweb2-test"><?php esc_html_e( 'Test connection', 'lightweb2-publish-with-buffer' ); ?></button>
						<button type="button" class="button" id="lightweb2-load-channels"><?php esc_html_e( 'Load channels', 'lightweb2-publish-with-buffer' ); ?></button>
						<button type="button" class="button-link-delete" id="lightweb2-disconnect"><?php esc_html_e( 'Disconnect', 'lightweb2-publish-with-buffer' ); ?></button>
					</p>
					<p class="lightweb2-ajax-status" id="lightweb2-ajax-status" role="status" aria-live="polite"></p>
				</section>

				<section id="lightweb2-channels-section" class="lightweb2-card lightweb2-card--channels">
					<h2><?php esc_html_e( 'Channels', 'lightweb2-publish-with-buffer' ); ?></h2>
					<input type="hidden" name="lightweb2_settings[channels_submitted]" value="1" />
					<div id="lightweb2-channels" class="lightweb2-channels">
						<?php if ( empty( $settings['channels'] ) ) : ?>
							<p class="description"><?php esc_html_e( 'No channels selected. Save your token, then load channels.', 'lightweb2-publish-with-buffer' ); ?></p>
						<?php else : ?>
							<?php foreach ( $settings['channels'] as $channel ) : ?>
								<label class="lightweb2-channel">
									<input
										type="checkbox"
										name="lightweb2_settings[channels][]"
										value="<?php echo esc_attr( $channel['id'] ); ?>"
										checked
									/>
									<span><?php echo esc_html( $channel['name'] ? $channel['name'] : $channel['id'] ); ?></span>
									<small><?php echo esc_html( $channel['service'] ); ?></small>
								</label>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</section>

				<section id="lightweb2-message" class="lightweb2-card lightweb2-card--publishing">
					<h2><?php esc_html_e( 'Publishing defaults', 'lightweb2-publish-with-buffer' ); ?></h2>
					<label for="lightweb2-mode" class="lightweb2-label"><?php esc_html_e( 'Default mode', 'lightweb2-publish-with-buffer' ); ?></label>
					<select id="lightweb2-mode" name="lightweb2_settings[default_mode]">
						<option value="addToQueue" <?php selected( $settings['default_mode'], 'addToQueue' ); ?>><?php esc_html_e( 'Add to Queue', 'lightweb2-publish-with-buffer' ); ?></option>
						<option value="shareNow" <?php selected( $settings['default_mode'], 'shareNow' ); ?>><?php esc_html_e( 'Share Now', 'lightweb2-publish-with-buffer' ); ?></option>
					</select>

					<label for="lightweb2-template" class="lightweb2-label"><?php esc_html_e( 'Message template', 'lightweb2-publish-with-buffer' ); ?></label>
					<textarea id="lightweb2-template" name="lightweb2_settings[template]" rows="5" class="large-text"><?php echo esc_textarea( $settings['template'] ); ?></textarea>
					<div class="lightweb2-variable-list" aria-label="<?php esc_attr_e( 'Template variables', 'lightweb2-publish-with-buffer' ); ?>">
						<?php foreach ( array( '{title}', '{excerpt}', '{url}', '{author}', '{category}', '{tags}' ) as $variable ) : ?>
							<button type="button" class="button lightweb2-variable-button" data-variable="<?php echo esc_attr( $variable ); ?>" title="<?php esc_attr_e( 'Insert variable', 'lightweb2-publish-with-buffer' ); ?>"><?php echo esc_html( $variable ); ?></button>
						<?php endforeach; ?>
					</div>
					<label class="lightweb2-label"><?php esc_html_e( 'Preview', 'lightweb2-publish-with-buffer' ); ?></label>
					<pre id="lightweb2-preview" class="lightweb2-preview"><?php echo esc_html( strtr( (string) $settings['template'], $preview_values ) ); ?></pre>
				</section>

				<div class="lightweb2-utility-bar">
				<section class="lightweb2-card lightweb2-card--filtering">
					<h2><?php esc_html_e( 'Filtering', 'lightweb2-publish-with-buffer' ); ?></h2>
					<label for="lightweb2-category" class="lightweb2-label"><?php esc_html_e( 'Category filter', 'lightweb2-publish-with-buffer' ); ?></label>
					<?php
					wp_dropdown_categories(
						array(
							'show_option_all' => __( 'All categories', 'lightweb2-publish-with-buffer' ),
							'name'            => 'lightweb2_settings[category_filter]',
							'id'              => 'lightweb2-category',
							'selected'        => (int) $settings['category_filter'],
							'value_field'     => 'term_id',
							'hide_empty'      => false,
						)
					);
					?>
				</section>

				<section class="lightweb2-card lightweb2-card--tools">
					<h2><?php esc_html_e( 'Tools', 'lightweb2-publish-with-buffer' ); ?></h2>
					<label for="lightweb2-retention" class="lightweb2-label"><?php esc_html_e( 'Keep delivery history for', 'lightweb2-publish-with-buffer' ); ?></label>
					<input type="number" id="lightweb2-retention" name="lightweb2_settings[retention_days]" min="1" max="365" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>" class="small-text" />
					<span><?php esc_html_e( 'days', 'lightweb2-publish-with-buffer' ); ?></span>
				</section>

				<?php submit_button( __( 'Save Settings', 'lightweb2-publish-with-buffer' ) ); ?>
				</div>
			</form>

			<?php $this->render_brand_footer(); ?>
		</div>
		<?php
	}

	/**
	 * Render the activity screen.
	 *
	 * @return void
	 */
	public function render_activity_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$status     = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$channel_id = isset( $_GET['channel_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['channel_id'] ) ) : '';
		$date_from  = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date_from'] ) ) : '';
		$date_to    = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date_to'] ) ) : '';
		$page       = isset( $_GET['paged'] )
			? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) )
			: 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$per_page = 20;

		$args = array(
			'status'     => $status,
			'channel_id' => $channel_id,
			'date_from'  => $date_from
				? get_gmt_from_date( $date_from . ' 00:00:00' )
				: '',
			'date_to'    => $date_to
				? get_gmt_from_date( $date_to . ' 23:59:59' )
				: '',
			'per_page'   => $per_page,
			'page'       => $page,
		);

		$rows  = $this->deliveries->get_recent( $args );
		$total = $this->deliveries->count_recent( $args );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		?>
		<div class="wrap lightweb2-wrap">
			<header class="lightweb2-header">
				<div>
					<a class="lightweb2-brand" href="https://lightweb2.com/" target="_blank" rel="noopener noreferrer">
						<img src="<?php echo esc_url( LIGHTWEB2_URL . 'assets/lightweb2-logo.png' ); ?>" alt="LightWeb2" />
					</a>
					<h1><?php esc_html_e( 'Activity', 'lightweb2-publish-with-buffer' ); ?></h1>
					<p class="lightweb2-lede"><?php esc_html_e( 'Every Buffer delivery attempt, with the reason it failed and a safe retry action.', 'lightweb2-publish-with-buffer' ); ?></p>
				</div>
				<button type="button" class="button" id="lightweb2-clear-logs"><?php esc_html_e( 'Clear history', 'lightweb2-publish-with-buffer' ); ?></button>
			</header>

			<form method="get" class="lightweb2-card lightweb2-filters">
				<input type="hidden" name="page" value="lightweb2-activity" />
				<label>
					<span><?php esc_html_e( 'Status', 'lightweb2-publish-with-buffer' ); ?></span>
					<select name="status">
						<option value=""><?php esc_html_e( 'All statuses', 'lightweb2-publish-with-buffer' ); ?></option>
						<?php foreach ( array( 'pending', 'sending', 'sent', 'failed', 'canceled' ) as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $status, $option ); ?>><?php echo esc_html( Statuses::label( $option ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Channel ID', 'lightweb2-publish-with-buffer' ); ?></span>
					<input type="text" name="channel_id" value="<?php echo esc_attr( $channel_id ); ?>" />
				</label>
				<label>
					<span><?php esc_html_e( 'From', 'lightweb2-publish-with-buffer' ); ?></span>
					<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
				</label>
				<label>
					<span><?php esc_html_e( 'To', 'lightweb2-publish-with-buffer' ); ?></span>
					<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
				</label>
				<?php submit_button( __( 'Filter', 'lightweb2-publish-with-buffer' ), '', '', false ); ?>
			</form>

			<div class="lightweb2-card lightweb2-card--activity-table">
				<?php if ( empty( $rows ) ) : ?>
					<p><?php esc_html_e( 'No deliveries yet. Publish a post with Buffer enabled to see activity here.', 'lightweb2-publish-with-buffer' ); ?></p>
				<?php else : ?>
					<div class="lightweb2-table-scroll">
					<table class="widefat striped lightweb2-activity-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'lightweb2-publish-with-buffer' ); ?></th>
								<th><?php esc_html_e( 'Post', 'lightweb2-publish-with-buffer' ); ?></th>
								<th><?php esc_html_e( 'Channel', 'lightweb2-publish-with-buffer' ); ?></th>
								<th><?php esc_html_e( 'Mode', 'lightweb2-publish-with-buffer' ); ?></th>
								<th><?php esc_html_e( 'Status', 'lightweb2-publish-with-buffer' ); ?></th>
								<th><?php esc_html_e( 'Attempts', 'lightweb2-publish-with-buffer' ); ?></th>
								<th><?php esc_html_e( 'Details', 'lightweb2-publish-with-buffer' ); ?></th>
								<th><?php esc_html_e( 'Action', 'lightweb2-publish-with-buffer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row['created_at'] . ' UTC' ) ) ); ?></td>
									<td>
										<?php
										$edit_link = get_edit_post_link( (int) $row['post_id'] );
										$title     = get_the_title( (int) $row['post_id'] );
										?>
										<?php if ( $edit_link ) : ?>
											<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $title ? $title : '#' . $row['post_id'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $title ? $title : '#' . $row['post_id'] ); ?>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $row['channel_name'] ? $row['channel_name'] : $row['channel_id'] ); ?><br /><small><?php echo esc_html( $row['service'] ); ?></small></td>
									<td><?php echo esc_html( $row['mode'] ); ?></td>
									<td><span class="lightweb2-badge is-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( Statuses::label( (string) $row['status'] ) ); ?></span></td>
									<td><?php echo esc_html( (string) $row['attempts'] ); ?></td>
									<td>
										<?php if ( $row['buffer_post_id'] ) : ?>
											<code><?php echo esc_html( $row['buffer_post_id'] ); ?></code>
										<?php endif; ?>
										<?php if ( $row['error_message'] ) : ?>
											<span class="lightweb2-error"><?php echo esc_html( $row['error_message'] ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( 'failed' === $row['status'] ) : ?>
											<button type="button" class="button button-small lightweb2-retry" data-delivery-id="<?php echo esc_attr( (string) $row['id'] ); ?>"><?php esc_html_e( 'Retry', 'lightweb2-publish-with-buffer' ); ?></button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div>

					<?php if ( $pages > 1 ) : ?>
						<div class="tablenav"><div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => add_query_arg( 'paged', '%#%' ),
									'format'  => '',
									'current' => $page,
									'total'   => $pages,
									'type'    => 'plain',
								)
							)
						);
						?>
						</div></div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<p class="lightweb2-ajax-status" id="lightweb2-ajax-status" role="status" aria-live="polite"></p>
			<?php $this->render_brand_footer(); ?>
		</div>
		<?php
	}

	/**
	 * Render the LightWeb2 ownership and support footer.
	 *
	 * @return void
	 */
	private function render_brand_footer(): void {
		?>
		<footer class="lightweb2-footer">
			<p>
				<span>LightWeb2. <?php esc_html_e( 'All rights reserved.', 'lightweb2-publish-with-buffer' ); ?></span>
				<span class="lightweb2-footer-separator" aria-hidden="true">·</span>
				<span><?php esc_html_e( 'For support:', 'lightweb2-publish-with-buffer' ); ?> <a href="https://lightweb2.com/contact/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Contact LightWeb2', 'lightweb2-publish-with-buffer' ); ?></a></span>
			</p>
			<a class="lightweb2-footer-site" href="https://lightweb2.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'lightweb2.com', 'lightweb2-publish-with-buffer' ); ?></a>
		</footer>
		<?php
	}

	/**
	 * Retry a failed delivery over AJAX.
	 *
	 * @return void
	 */
	public function ajax_retry_delivery(): void {
		$this->verify_ajax();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by verify_ajax().
		$delivery_id = isset( $_POST['delivery_id'] )
			? absint( wp_unslash( (string) $_POST['delivery_id'] ) )
			: 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $delivery_id < 1 || ! $this->publisher->retry( $delivery_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'This delivery cannot be retried.', 'lightweb2-publish-with-buffer' ) ),
				400
			);
		}

		wp_send_json_success();
	}

	/**
	 * Clear delivery history over AJAX.
	 *
	 * @return void
	 */
	public function ajax_clear_logs(): void {
		$this->verify_ajax();
		$this->deliveries->clear();
		wp_send_json_success();
	}
}
