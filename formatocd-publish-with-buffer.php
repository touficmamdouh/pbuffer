<?php
/**
 * Plugin Name: FormatoCD Publish with Buffer
 * Plugin URI:  https://github.com/formatocd/formatocd-publish-with-buffer
 * Description: Generates Buffer posts automatically from WordPress posts.
 * Version:     1.2.1
 * Author:      Carlos Durán
 * License:     GPL-2.0+
 * Text Domain: formatocd-publish-with-buffer
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'add_meta_boxes', 'formatocd_buffer_add_meta_box' );
function formatocd_buffer_add_meta_box() {
    add_meta_box(
        'formatocd_buffer_meta_box',      
        __( 'FormatoCD Publish with Buffer', 'formatocd-publish-with-buffer' ),          
        'formatocd_buffer_meta_box_html', 
        'post',                        
        'side',                        
        'high'                         
    );
}

function formatocd_buffer_meta_box_html( $post ) {
    wp_nonce_field( 'formatocd_buffer_save_meta', 'formatocd_buffer_meta_nonce' );

    $already_sent = get_post_meta( $post->ID, '_buffer_sent_flag', true );

    if ( 'yes' === $already_sent ) {
        echo '<p><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> <strong>' . esc_html__( 'This post has already been published to Buffer.', 'formatocd-publish-with-buffer' ) . '</strong></p>';
        return;
    }

    $global_default_mode = get_option( 'buffer_default_mode', '' ); 
    $has_meta            = metadata_exists( 'post', $post->ID, '_buffer_is_active' );

    if ( ! $has_meta ) {
        if ( '' !== $global_default_mode ) {
            $is_active = 'yes';
            $mode      = $global_default_mode;
        } else {
            $is_active = 'no';       
            $mode      = 'shareNow'; 
        }
    } else {
        $is_active = get_post_meta( $post->ID, '_buffer_is_active', true );
        $mode      = get_post_meta( $post->ID, '_buffer_mode', true );
    }

    ?>
    <p>
        <label>
            <input type="checkbox" name="buffer_is_active" value="yes" <?php checked( $is_active, 'yes' ); ?> />
            <strong><?php esc_html_e( 'Send to Buffer on publish', 'formatocd-publish-with-buffer' ); ?></strong>
        </label>
    </p>
    
    <p>
        <label for="buffer_mode"><?php esc_html_e( 'Publishing mode:', 'formatocd-publish-with-buffer' ); ?></label>
        <select name="buffer_mode" id="buffer_mode" style="width: 100%;">
            <option value="shareNow" <?php selected( $mode, 'shareNow' ); ?>><?php esc_html_e( 'Share Now', 'formatocd-publish-with-buffer' ); ?></option>
            <option value="addToQueue" <?php selected( $mode, 'addToQueue' ); ?>><?php esc_html_e( 'Add to Queue', 'formatocd-publish-with-buffer' ); ?></option>
        </select>
    </p>
    <?php
}

add_action( 'save_post', 'formatocd_buffer_save_meta_box_data' );
function formatocd_buffer_save_meta_box_data( $post_id ) {
    if ( ! isset( $_POST['formatocd_buffer_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['formatocd_buffer_meta_nonce'] ) ), 'formatocd_buffer_save_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return; 
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $already_sent = get_post_meta( $post_id, '_buffer_sent_flag', true );
    if ( 'yes' === $already_sent ) {
        return;
    }

    $is_active = isset( $_POST['buffer_is_active'] ) ? 'yes' : 'no';
    update_post_meta( $post_id, '_buffer_is_active', $is_active );

    if ( isset( $_POST['buffer_mode'] ) ) {
        update_post_meta( $post_id, '_buffer_mode', sanitize_text_field( wp_unslash( $_POST['buffer_mode'] ) ) );
    }

    if ( 'publish' === get_post_status( $post_id ) ) {
        formatocd_buffer_check_and_send( $post_id, $is_active );
    }
}

add_action( 'publish_future_post', 'formatocd_buffer_trigger_scheduled_post' );
function formatocd_buffer_trigger_scheduled_post( $post_id ) {
    $is_active = get_post_meta( $post_id, '_buffer_is_active', true );
    formatocd_buffer_check_and_send( $post_id, $is_active );
}

function formatocd_buffer_check_and_send( $post_id, $is_active ) {
    $already_sent = get_post_meta( $post_id, '_buffer_sent_flag', true );
    
    if ( 'yes' === $is_active && 'yes' !== $already_sent ) {
        formatocd_buffer_send_to_buffer( $post_id );
    }
}

function formatocd_buffer_send_to_buffer( $post_id ) {
    $post = get_post( $post_id );
    
    $api_token   = get_option( 'buffer_api_token' );
    $channels    = get_option( 'buffer_channels', [] ); 
    $template    = get_option( 'buffer_template', '{title} - {url}' );
    $allowed_cat = get_option( 'buffer_allowed_category', '' ); 

    if ( empty( $api_token ) || empty( $channels ) ) return;

    if ( ! empty( $allowed_cat ) ) {
        $post_categories = wp_get_post_categories( $post_id );
        if ( ! in_array( $allowed_cat, $post_categories ) ) return; 
    }

    $excerpt = has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : wp_trim_words( $post->post_content, 20 );
    $author_name = get_the_author_meta( 'display_name', $post->post_author );
    $categories = get_the_category( $post_id );
    $category_name = ! empty( $categories ) ? $categories[0]->name : '';
    
    $tags = get_the_tags( $post_id );
    $tags_string = '';
    if ( $tags ) {
        $tag_names = wp_list_pluck( $tags, 'name' );
        $hashtag_array = array_map(function($tag) {
            return '#' . str_replace(' ', '', $tag);
        }, $tag_names);
        $tags_string = implode(' ', $hashtag_array);
    }

    $title_decoded   = html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' );
    $excerpt_decoded = html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' );

    $message = str_replace(
        ['{title}', '{url}', '{excerpt}', '{author}', '{category}', '{tags}'],
        [$title_decoded, get_permalink( $post_id ), $excerpt_decoded, $author_name, $category_name, $tags_string],
        $template
    );

    $image_url = null;
    if ( has_post_thumbnail( $post_id ) ) {
        $image_url = get_the_post_thumbnail_url( $post_id, 'full' );
    }

    $mode = get_post_meta( $post_id, '_buffer_mode', true );

    $query = '
    mutation CreatePost($input: CreatePostInput!) {
      createPost(input: $input) {
        ... on PostActionSuccess {
          post { id text }
        }
        ... on MutationError {
          message
        }
      }
    }';

    $all_success = true;

    foreach ( $channels as $channel_id ) {
        $input_vars = [
            'text'           => $message,
            'channelId'      => $channel_id,
            'schedulingType' => 'automatic',
            'mode'           => $mode
        ];

        if ( $image_url ) {
            $input_vars['assets'] = [
                [
                    'image' => [
                        'url' => $image_url
                    ]
                ]
            ];
        }

        $payload = [
            'query'     => $query,
            'variables' => [ 'input' => $input_vars ]
        ];

        $response = wp_remote_post( 'https://api.buffer.com', [
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_token,
            ],
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
            'timeout'     => 15,
            'blocking'    => true 
        ] );

        if ( is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( 'Buffer API Error (WP Error): ' . $response->get_error_message() );
            $all_success = false;
        } else {
            $status_code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            if ( $status_code >= 400 ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( "Buffer API Error ($status_code): " . $body );
                $all_success = false;
            } else {
                $body_data = json_decode( $body, true );
                if ( isset($body_data['data']['createPost']['message']) && !isset($body_data['data']['createPost']['post']) ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( "Buffer API Mutation Error: " . $body_data['data']['createPost']['message'] );
                    $all_success = false;
                }
            }
        }
    }

    if ( $all_success ) {
        update_post_meta( $post_id, '_buffer_sent_flag', 'yes' );
    }
}

add_action( 'admin_menu', 'formatocd_buffer_add_admin_menu' );
function formatocd_buffer_add_admin_menu() {
    add_menu_page(
        __( 'FormatoCD Publish with Buffer', 'formatocd-publish-with-buffer' ),            
        __( 'FormatoCD Publish with Buffer', 'formatocd-publish-with-buffer' ),            
        'manage_options',                 
        'formatocd-publish-with-buffer',            
        'formatocd_buffer_settings_page_html', 
        'dashicons-share',                
        80                                
    );
}

add_action( 'admin_init', 'formatocd_buffer_settings_init' );
function formatocd_buffer_settings_init() {
    register_setting( 'formatocd_buffer_settings_group', 'buffer_api_token', array(
        'type'              => 'string',
        'sanitize_callback' => 'formatocd_buffer_sanitize_api_token',
    ) );
    register_setting( 'formatocd_buffer_settings_group', 'buffer_channels', array(
        'type'              => 'string',
        'sanitize_callback' => 'formatocd_buffer_sanitize_channels',
    ) );
    register_setting( 'formatocd_buffer_settings_group', 'buffer_template', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_textarea_field',
    ) );
    register_setting( 'formatocd_buffer_settings_group', 'buffer_allowed_category', array(
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
    ) );
    register_setting( 'formatocd_buffer_settings_group', 'buffer_default_mode', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ) );

    add_settings_section(
        'formatocd_buffer_main_section',
        __( 'API and Publishing Settings', 'formatocd-publish-with-buffer' ),
        '__return_empty_string', 
        'formatocd-publish-with-buffer'
    );

    add_settings_field( 'buffer_api_token', __( 'Buffer API Token', 'formatocd-publish-with-buffer' ), 'formatocd_buffer_api_token_cb', 'formatocd-publish-with-buffer', 'formatocd_buffer_main_section' );
    add_settings_field( 'buffer_channels', __( 'Channel IDs', 'formatocd-publish-with-buffer' ), 'formatocd_buffer_channels_cb', 'formatocd-publish-with-buffer', 'formatocd_buffer_main_section' );
    add_settings_field( 'buffer_template', __( 'Message Template', 'formatocd-publish-with-buffer' ), 'formatocd_buffer_template_cb', 'formatocd-publish-with-buffer', 'formatocd_buffer_main_section' );
    add_settings_field( 'buffer_default_mode', __( 'Default Publishing Mode', 'formatocd-publish-with-buffer' ), 'formatocd_buffer_mode_cb', 'formatocd-publish-with-buffer', 'formatocd_buffer_main_section' );
    add_settings_field( 'buffer_allowed_category', __( 'Filter by Category', 'formatocd-publish-with-buffer' ), 'formatocd_buffer_category_cb', 'formatocd-publish-with-buffer', 'formatocd_buffer_main_section' );
}

function formatocd_buffer_sanitize_api_token( $input ) {
    $input = sanitize_text_field( $input );
    if ( empty( $input ) ) {
        return get_option( 'buffer_api_token' );
    }
    return $input;
}

function formatocd_buffer_sanitize_channels( $input ) {
    if ( ! is_array( $input ) ) {
        $input = explode( ',', $input );
    }
    $channels = array_map( 'trim', $input );
    $channels = array_map( 'sanitize_text_field', $channels );
    
    return array_filter( $channels ); 
}

function formatocd_buffer_api_token_cb() {
    $val = get_option( 'buffer_api_token' );
    $placeholder = ! empty( $val ) ? '********' : '';
    echo '<input type="password" name="buffer_api_token" value="" placeholder="' . esc_attr( $placeholder ) . '" class="regular-text" />';
    if ( ! empty( $val ) ) {
        echo '<p class="description">' . esc_html__( 'Token is set. Enter a new one to update it, or leave blank to keep the current one.', 'formatocd-publish-with-buffer' ) . '</p>';
    }
}

function formatocd_buffer_channels_cb() {
    $val = get_option( 'buffer_channels', [] );
    $val_string = is_array( $val ) ? implode( ', ', $val ) : '';
    echo '<input type="text" name="buffer_channels" value="' . esc_attr( $val_string ) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__( 'Example:', 'formatocd-publish-with-buffer' ) . ' <code>id_1, id_2</code></p>';
}

function formatocd_buffer_template_cb() {
    $val = get_option( 'buffer_template', '{title} - {url}' );
    echo '<textarea name="buffer_template" rows="3" class="large-text">' . esc_textarea( $val ) . '</textarea>';
    echo '<p class="description"><strong>' . esc_html__( 'Available variables:', 'formatocd-publish-with-buffer' ) . '</strong><br>';
    echo '<code>{title}</code> : ' . esc_html__( 'Post title.', 'formatocd-publish-with-buffer' ) . '<br>';
    echo '<code>{url}</code> : ' . esc_html__( 'Direct link to the post.', 'formatocd-publish-with-buffer' ) . '<br>';
    echo '<code>{excerpt}</code> : ' . esc_html__( 'Post excerpt.', 'formatocd-publish-with-buffer' ) . '<br>';
    echo '<code>{author}</code> : ' . esc_html__( 'Author public display name.', 'formatocd-publish-with-buffer' ) . '<br>';
    echo '<code>{category}</code> : ' . esc_html__( 'Primary category of the post.', 'formatocd-publish-with-buffer' ) . '<br>';
    echo '<code>{tags}</code> : ' . esc_html__( 'Post tags (automatically converted to #hashtags).', 'formatocd-publish-with-buffer' ) . '</p>';
}

function formatocd_buffer_mode_cb() {
    $val = get_option( 'buffer_default_mode', '' ); 
    ?>
    <select name="buffer_default_mode">
        <option value="" <?php selected( $val, '' ); ?>><?php esc_html_e( 'None (Disabled on posts)', 'formatocd-publish-with-buffer' ); ?></option>
        <option value="shareNow" <?php selected( $val, 'shareNow' ); ?>><?php esc_html_e( 'Share Now', 'formatocd-publish-with-buffer' ); ?></option>
        <option value="addToQueue" <?php selected( $val, 'addToQueue' ); ?>><?php esc_html_e( 'Add to Queue', 'formatocd-publish-with-buffer' ); ?></option>
    </select>
    <p class="description"><?php esc_html_e( 'If you choose "None", the checkbox in the editor will be unchecked by default.', 'formatocd-publish-with-buffer' ); ?></p>
    <?php
}

function formatocd_buffer_category_cb() {
    $val = get_option( 'buffer_allowed_category', '' );
    $args = [
        'show_option_all'    => __( 'All categories', 'formatocd-publish-with-buffer' ),
        'name'               => 'buffer_allowed_category',
        'selected'           => $val,
        'value_field'        => 'term_id',
    ];
    wp_dropdown_categories( $args );
}

function formatocd_buffer_settings_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'FormatoCD Publish with Buffer Settings', 'formatocd-publish-with-buffer' ); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'formatocd_buffer_settings_group' );
            do_settings_sections( 'formatocd-publish-with-buffer' );
            submit_button( __( 'Save Settings', 'formatocd-publish-with-buffer' ) );
            ?>
        </form>
    </div>
    <?php
}