<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Admin {

    private Settings $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
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
        if ( ! in_array( $hook, [ 'toplevel_page_lightweb2', 'lightweb2_page_lightweb2-activity' ], true ) ) {
            return;
        }

        wp_enqueue_style(
            'lightweb2-admin',
            LIGHTWEB2_URL . 'assets/admin.css',
            [],
            LIGHTWEB2_VERSION
        );

        wp_enqueue_script(
            'lightweb2-admin',
            LIGHTWEB2_URL . 'assets/admin.js',
            [],
            LIGHTWEB2_VERSION,
            true
        );
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
