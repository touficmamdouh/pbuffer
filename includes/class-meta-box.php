<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Meta_Box {

    private Settings $settings;
    private Deliveries $deliveries;

    public function __construct( Settings $settings, Deliveries $deliveries ) {
        $this->settings   = $settings;
        $this->deliveries = $deliveries;
    }

    public function register(): void {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post', [ $this, 'save' ] );
    }

    public function add_meta_box(): void {
        add_meta_box(
            'lightweb2-meta-box',
            __( 'LightWeb2', 'lightweb2-publish-with-buffer' ),
            [ $this, 'render' ],
            'post',
            'side',
            'high'
        );
    }

    public function render( \WP_Post $post ): void {
        $settings = $this->settings->get();
        $enabled  = get_post_meta( $post->ID, '_lightweb2_enabled', true );
        $mode     = get_post_meta( $post->ID, '_lightweb2_mode', true );
        $mode     = in_array( $mode, [ 'shareNow', 'addToQueue' ], true )
            ? $mode
            : (string) $settings['default_mode'];
        $rows     = $this->deliveries->get_for_post( (int) $post->ID );

        wp_nonce_field( 'lightweb2_meta', 'lightweb2_meta_nonce' );
        ?>
        <?php if ( '' === (string) $settings['token'] ) : ?>
            <p>
                <?php esc_html_e( 'Buffer is not connected.', 'lightweb2-publish-with-buffer' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lightweb2' ) ); ?>">
                    <?php esc_html_e( 'Open settings', 'lightweb2-publish-with-buffer' ); ?>
                </a>
            </p>
        <?php endif; ?>

        <p>
            <label>
                <input type="checkbox" name="lightweb2_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?> />
                <?php esc_html_e( 'Send to Buffer on publish', 'lightweb2-publish-with-buffer' ); ?>
            </label>
        </p>

        <p>
            <label for="lightweb2-mode-field"><?php esc_html_e( 'Publishing mode', 'lightweb2-publish-with-buffer' ); ?></label>
            <select name="lightweb2_mode" id="lightweb2-mode-field" class="widefat">
                <option value="addToQueue" <?php selected( $mode, 'addToQueue' ); ?>><?php esc_html_e( 'Add to Queue', 'lightweb2-publish-with-buffer' ); ?></option>
                <option value="shareNow" <?php selected( $mode, 'shareNow' ); ?>><?php esc_html_e( 'Share Now', 'lightweb2-publish-with-buffer' ); ?></option>
            </select>
        </p>

        <?php if ( ! empty( $rows ) ) : ?>
            <p><strong><?php esc_html_e( 'Delivery status', 'lightweb2-publish-with-buffer' ); ?></strong></p>
            <ul class="lightweb2-delivery-list">
                <?php foreach ( $rows as $row ) : ?>
                    <li class="lightweb2-delivery is-<?php echo esc_attr( $row['status'] ); ?>">
                        <span><?php echo esc_html( $row['channel_name'] ?: $row['channel_id'] ); ?></span>
                        <strong><?php echo esc_html( ucfirst( $row['status'] ) ); ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php
    }

    public function save( int $post_id ): void {
        if ( ! isset( $_POST['lightweb2_meta_nonce'] )
            || ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( (string) $_POST['lightweb2_meta_nonce'] ) ),
                'lightweb2_meta'
            )
        ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( 'post' !== get_post_type( $post_id ) ) {
            return;
        }

        $enabled = isset( $_POST['lightweb2_enabled'] ) ? 'yes' : 'no';
        $mode    = isset( $_POST['lightweb2_mode'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['lightweb2_mode'] ) )
            : 'addToQueue';

        if ( ! in_array( $mode, [ 'shareNow', 'addToQueue' ], true ) ) {
            $mode = 'addToQueue';
        }

        update_post_meta( $post_id, '_lightweb2_enabled', $enabled );
        update_post_meta( $post_id, '_lightweb2_mode', $mode );
    }
}
