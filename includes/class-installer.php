<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Installer {

    public const DB_VERSION = '1.0.0';

    public static function activate(): void {
        self::create_table();

        if ( false === get_option( Settings::OPTION ) ) {
            add_option( Settings::OPTION, Settings::defaults(), '', 'no' );
        }

        update_option( 'lightweb2_db_version', self::DB_VERSION, false );

        if ( ! wp_next_scheduled( 'lightweb2_cleanup_logs' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lightweb2_cleanup_logs' );
        }

        if ( ! wp_next_scheduled( 'lightweb2_recover_stale' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'lightweb2_recover_stale' );
        }
    }

    public static function create_table(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = $wpdb->prefix . 'lightweb2_deliveries';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            channel_id varchar(64) NOT NULL,
            channel_name varchar(191) NOT NULL DEFAULT '',
            service varchar(32) NOT NULL DEFAULT '',
            mode varchar(32) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            buffer_post_id varchar(64) DEFAULT NULL,
            error_code varchar(64) DEFAULT NULL,
            error_message text NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_channel (post_id, channel_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta( $sql );
    }

    public static function deactivate(): void {
        wp_unschedule_hook( 'lightweb2_deliver_channel' );
        wp_unschedule_hook( 'lightweb2_cleanup_logs' );
        wp_unschedule_hook( 'lightweb2_recover_stale' );
    }

    public static function uninstall(): void {
        global $wpdb;

        self::deactivate();

        delete_option( Settings::OPTION );
        delete_option( 'lightweb2_db_version' );
        delete_post_meta_by_key( '_lightweb2_enabled' );
        delete_post_meta_by_key( '_lightweb2_mode' );

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_lightweb2_channels_%'
                OR option_name LIKE '_transient_timeout_lightweb2_channels_%'"
        );

        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lightweb2_deliveries" );
    }
}
