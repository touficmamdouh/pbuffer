<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Admin {

    private Settings $settings;
    private Buffer_Client $client;

    public function __construct( Settings $settings, Buffer_Client $client ) {
        $this->settings = $settings;
        $this->client   = $client;
    }

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_lightweb2_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_lightweb2_fetch_channels', [ $this, 'ajax_fetch_channels' ] );
        add_action( 'wp_ajax_lightweb2_disconnect', [ $this, 'ajax_disconnect' ] );
    }

    public function register_menu(): void {
        add_menu_page(
            __( 'LightWeb2', 'lightweb2-publish-with-buffer' ),
            __( 'LightWeb2', 'lightweb2-publish-with-buffer' ),
            'manage_options',
            'lightweb2',
            [ $this, 'render_settings_page' ],
            LIGHTWEB2_URL . 'assets/icon.svg',
            80
        );

        add_submenu_page(
            'lightweb2',
            __( 'Publish with Buffer', 'lightweb2-publish-with-buffer' ),
            __( 'Publish with Buffer', 'lightweb2-publish-with-buffer' ),
            'manage_options',
            'lightweb2',
            [ $this, 'render_settings_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        $is_plugin_screen = in_array(
            $hook,
            [ 'toplevel_page_lightweb2', 'lightweb2_page_lightweb2-activity' ],
            true
        );
        $is_post_screen   = in_array( $hook, [ 'post.php', 'post-new.php' ], true );

        if ( ! $is_plugin_screen && ! $is_post_screen ) {
            return;
        }

        wp_enqueue_style(
            'lightweb2-admin',
            LIGHTWEB2_URL . 'assets/admin.css',
            [],
            LIGHTWEB2_VERSION
        );

        if ( ! $is_plugin_screen ) {
            return;
        }

        wp_enqueue_script(
            'lightweb2-admin',
            LIGHTWEB2_URL . 'assets/admin.js',
            [],
            LIGHTWEB2_VERSION,
            true
        );

        $settings = $this->settings->get();

        wp_localize_script(
            'lightweb2-admin',
            'lightweb2Admin',
            [
                'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
                'nonce'            => wp_create_nonce( 'lightweb2_admin' ),
                'selectedChannels' => array_values(
                    array_map(
                        static fn( array $channel ): string => (string) $channel['id'],
                        $settings['channels']
                    )
                ),
                'strings'          => [
                    'testing'            => __( 'Testing connection…', 'lightweb2-publish-with-buffer' ),
                    'loading'            => __( 'Loading channels…', 'lightweb2-publish-with-buffer' ),
                    'connected'          => __( 'Connected to Buffer as %s.', 'lightweb2-publish-with-buffer' ),
                    'noChannels'         => __( 'No channels found in this Buffer account.', 'lightweb2-publish-with-buffer' ),
                    'error'              => __( 'Could not reach Buffer. Check the token and try again.', 'lightweb2-publish-with-buffer' ),
                    'confirmDisconnect'  => __( 'Disconnect Buffer and clear the selected channels?', 'lightweb2-publish-with-buffer' ),
                    'confirmClearLogs'   => __( 'Delete all delivery history? This cannot be undone.', 'lightweb2-publish-with-buffer' ),
                ],
            ]
        );
    }

    private function verify_ajax(): void {
        check_ajax_referer( 'lightweb2_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                [ 'message' => __( 'You are not allowed to manage Buffer settings.', 'lightweb2-publish-with-buffer' ) ],
                403
            );
        }
    }

    private function request_token(): string {
        $token = isset( $_POST['token'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) )
            : '';

        if ( '' === $token ) {
            $token = (string) $this->settings->get()['token'];
        }

        return $token;
    }

    public function ajax_test_connection(): void {
        $this->verify_ajax();
        $token = $this->request_token();

        if ( '' === $token ) {
            wp_send_json_error(
                [ 'message' => __( 'Add a Buffer token first.', 'lightweb2-publish-with-buffer' ) ],
                400
            );
        }

        $result = $this->client->test_connection( $token );

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['message'] ], 400 );
        }

        $account = $result['data']['account'] ?? [];

        wp_send_json_success(
            [
                'account'       => sanitize_text_field( (string) ( $account['name'] ?? '' ) ),
                'organizations' => count( $account['organizations'] ?? [] ),
            ]
        );
    }

    public function ajax_fetch_channels(): void {
        $this->verify_ajax();
        $token = $this->request_token();

        if ( '' === $token ) {
            wp_send_json_error(
                [ 'message' => __( 'Add a Buffer token first.', 'lightweb2-publish-with-buffer' ) ],
                400
            );
        }

        $account = $this->client->test_connection( $token );

        if ( ! $account['success'] ) {
            wp_send_json_error( [ 'message' => $account['message'] ], 400 );
        }

        $catalog = [];

        foreach ( $account['data']['account']['organizations'] ?? [] as $organization ) {
            $organization_id = sanitize_text_field( (string) ( $organization['id'] ?? '' ) );

            if ( '' === $organization_id ) {
                continue;
            }

            $channels = $this->client->get_channels( $token, $organization_id );

            if ( ! $channels['success'] ) {
                wp_send_json_error( [ 'message' => $channels['message'] ], 400 );
            }

            $normalized = [];

            foreach ( $channels['data']['channels'] ?? [] as $channel ) {
                $normalized[] = [
                    'id'      => sanitize_text_field( (string) ( $channel['id'] ?? '' ) ),
                    'name'    => sanitize_text_field( (string) ( $channel['name'] ?? '' ) ),
                    'service' => sanitize_key( (string) ( $channel['service'] ?? '' ) ),
                ];
            }

            $catalog[] = [
                'id'       => $organization_id,
                'name'     => sanitize_text_field( (string) ( $organization['name'] ?? '' ) ),
                'channels' => $normalized,
            ];
        }

        set_transient( Settings::catalog_key( $token ), $catalog, 12 * HOUR_IN_SECONDS );

        wp_send_json_success( [ 'organizations' => $catalog ] );
    }

    public function ajax_disconnect(): void {
        $this->verify_ajax();

        $settings = $this->settings->get();
        delete_transient( Settings::catalog_key( (string) $settings['token'] ) );

        // Clear first so sanitize() does not preserve the existing token.
        delete_option( Settings::OPTION );

        update_option(
            Settings::OPTION,
            [
                'token'    => '',
                'channels' => [],
            ] + $settings,
            false
        );

        wp_send_json_success();
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->settings->get();
        $has_token = '' !== (string) $settings['token'];
        ?>
        <div class="wrap lightweb2-wrap">
            <header class="lightweb2-header">
                <div>
                    <p class="lightweb2-eyebrow"><?php esc_html_e( 'LightWeb2', 'lightweb2-publish-with-buffer' ); ?></p>
                    <h1><?php esc_html_e( 'Publish with Buffer', 'lightweb2-publish-with-buffer' ); ?></h1>
                    <p class="lightweb2-lede"><?php esc_html_e( 'Connect Buffer, choose your channels, and share WordPress posts without the busywork.', 'lightweb2-publish-with-buffer' ); ?></p>
                </div>
                <span class="lightweb2-version">v<?php echo esc_html( LIGHTWEB2_VERSION ); ?></span>
            </header>

            <?php settings_errors(); ?>

            <form action="options.php" method="post" class="lightweb2-form">
                <?php settings_fields( 'lightweb2_settings_group' ); ?>

                <section class="lightweb2-card">
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
                        <?php echo $has_token
                            ? esc_html__( 'Token is set. Enter a new one to replace it.', 'lightweb2-publish-with-buffer' )
                            : esc_html__( 'Not connected yet.', 'lightweb2-publish-with-buffer' ); ?>
                    </p>
                    <p class="lightweb2-actions">
                        <button type="button" class="button" id="lightweb2-test"><?php esc_html_e( 'Test connection', 'lightweb2-publish-with-buffer' ); ?></button>
                        <button type="button" class="button" id="lightweb2-load-channels"><?php esc_html_e( 'Load channels', 'lightweb2-publish-with-buffer' ); ?></button>
                        <button type="button" class="button-link-delete" id="lightweb2-disconnect"><?php esc_html_e( 'Disconnect', 'lightweb2-publish-with-buffer' ); ?></button>
                    </p>
                    <p class="lightweb2-ajax-status" id="lightweb2-ajax-status" role="status" aria-live="polite"></p>
                </section>

                <section class="lightweb2-card">
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
                                    <span><?php echo esc_html( $channel['name'] ?: $channel['id'] ); ?></span>
                                    <small><?php echo esc_html( $channel['service'] ); ?></small>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="lightweb2-card">
                    <h2><?php esc_html_e( 'Publishing defaults', 'lightweb2-publish-with-buffer' ); ?></h2>
                    <label for="lightweb2-mode" class="lightweb2-label"><?php esc_html_e( 'Default mode', 'lightweb2-publish-with-buffer' ); ?></label>
                    <select id="lightweb2-mode" name="lightweb2_settings[default_mode]">
                        <option value="addToQueue" <?php selected( $settings['default_mode'], 'addToQueue' ); ?>><?php esc_html_e( 'Add to Queue', 'lightweb2-publish-with-buffer' ); ?></option>
                        <option value="shareNow" <?php selected( $settings['default_mode'], 'shareNow' ); ?>><?php esc_html_e( 'Share Now', 'lightweb2-publish-with-buffer' ); ?></option>
                    </select>

                    <label for="lightweb2-template" class="lightweb2-label"><?php esc_html_e( 'Message template', 'lightweb2-publish-with-buffer' ); ?></label>
                    <textarea id="lightweb2-template" name="lightweb2_settings[template]" rows="5" class="large-text"><?php echo esc_textarea( $settings['template'] ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Variables:', 'lightweb2-publish-with-buffer' ); ?>
                        <code>{title}</code> <code>{url}</code> <code>{excerpt}</code>
                        <code>{author}</code> <code>{category}</code> <code>{tags}</code>
                    </p>
                    <label class="lightweb2-label"><?php esc_html_e( 'Preview', 'lightweb2-publish-with-buffer' ); ?></label>
                    <pre id="lightweb2-preview" class="lightweb2-preview"></pre>
                </section>

                <section class="lightweb2-card">
                    <h2><?php esc_html_e( 'Filtering', 'lightweb2-publish-with-buffer' ); ?></h2>
                    <label for="lightweb2-category" class="lightweb2-label"><?php esc_html_e( 'Category filter', 'lightweb2-publish-with-buffer' ); ?></label>
                    <?php
                    wp_dropdown_categories(
                        [
                            'show_option_all' => __( 'All categories', 'lightweb2-publish-with-buffer' ),
                            'name'            => 'lightweb2_settings[category_filter]',
                            'id'              => 'lightweb2-category',
                            'selected'        => (int) $settings['category_filter'],
                            'value_field'     => 'term_id',
                            'hide_empty'      => false,
                        ]
                    );
                    ?>
                </section>

                <section class="lightweb2-card">
                    <h2><?php esc_html_e( 'Tools', 'lightweb2-publish-with-buffer' ); ?></h2>
                    <label for="lightweb2-retention" class="lightweb2-label"><?php esc_html_e( 'Keep delivery history for', 'lightweb2-publish-with-buffer' ); ?></label>
                    <input type="number" id="lightweb2-retention" name="lightweb2_settings[retention_days]" min="1" max="365" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>" class="small-text" />
                    <span><?php esc_html_e( 'days', 'lightweb2-publish-with-buffer' ); ?></span>
                </section>

                <?php submit_button( __( 'Save Settings', 'lightweb2-publish-with-buffer' ) ); ?>
            </form>
        </div>
        <?php
    }
}
