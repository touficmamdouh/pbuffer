<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Deliveries {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'lightweb2_deliveries';
    }

    public function create_or_get( int $post_id, array $channel, string $mode ): array {
        global $wpdb;

        $existing = $this->get_by_post_channel( $post_id, (string) $channel['id'] );

        if ( null !== $existing ) {
            return $existing;
        }

        $now = current_time( 'mysql', true );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned table.
        $inserted = $wpdb->insert(
            $this->table,
            [
                'post_id'      => $post_id,
                'channel_id'   => (string) $channel['id'],
                'channel_name' => (string) ( $channel['name'] ?? '' ),
                'service'      => (string) ( $channel['service'] ?? '' ),
                'mode'         => $mode,
                'status'       => 'pending',
                'attempts'     => 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return $this->get_by_post_channel( $post_id, (string) $channel['id'] ) ?? [];
        }

        $row = $this->get( (int) $wpdb->insert_id );

        return $row ?? [];
    }

    public function get( int $id ): ?array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    public function get_by_post_channel( int $post_id, string $channel_id ): ?array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
                "SELECT * FROM {$this->table} WHERE post_id = %d AND channel_id = %s",
                $post_id,
                $channel_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    public function get_for_post( int $post_id ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
                "SELECT * FROM {$this->table} WHERE post_id = %d ORDER BY id ASC",
                $post_id
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    public function increment_attempts( int $id ): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
                "UPDATE {$this->table}
                 SET attempts = attempts + 1, updated_at = %s
                 WHERE id = %d",
                current_time( 'mysql', true ),
                $id
            )
        );

        $row = $this->get( $id );

        return $row ? (int) $row['attempts'] : 0;
    }

    public function mark_sent( int $id, string $buffer_post_id ): void {
        $this->update(
            $id,
            [
                'status'         => 'sent',
                'buffer_post_id' => $buffer_post_id,
                'error_code'     => null,
                'error_message'  => null,
            ]
        );
    }

    public function mark_failed( int $id, string $code, string $message ): void {
        $this->update(
            $id,
            [
                'status'        => 'failed',
                'error_code'    => $code,
                'error_message' => wp_html_excerpt( $message, 1000, '' ),
            ]
        );
    }

    public function mark_canceled( int $id ): void {
        $this->update( $id, [ 'status' => 'canceled' ] );
    }

    public function mark_pending( int $id ): void {
        $this->update(
            $id,
            [
                'status'        => 'pending',
                'error_code'    => null,
                'error_message' => null,
            ]
        );
    }

    public function get_recent( array $args = [] ): array {
        global $wpdb;

        [ $where, $params ] = $this->build_where( $args );
        $per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;
        $params[] = $per_page;
        $params[] = $offset;

        $sql = "SELECT * FROM {$this->table} {$where}
                ORDER BY created_at DESC, id DESC
                LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table.
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    public function count_recent( array $args = [] ): int {
        global $wpdb;

        [ $where, $params ] = $this->build_where( $args );
        $sql = "SELECT COUNT(*) FROM {$this->table} {$where}";

        if ( empty( $params ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table.
            return (int) $wpdb->get_var( $sql );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table.
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    }

    public function clear(): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
        return (int) $wpdb->query( "DELETE FROM {$this->table}" );
    }

    public function delete_older_than_days( int $days ): int {
        global $wpdb;

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
        return (int) $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
                "DELETE FROM {$this->table} WHERE created_at < %s",
                $cutoff
            )
        );
    }

    private function update( int $id, array $data ): void {
        global $wpdb;

        $data['updated_at'] = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
        $wpdb->update( $this->table, $data, [ 'id' => $id ] );
    }

    private function build_where( array $args ): array {
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = (string) $args['status'];
        }

        if ( ! empty( $args['channel_id'] ) ) {
            $where[]  = 'channel_id = %s';
            $params[] = (string) $args['channel_id'];
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'created_at >= %s';
            $params[] = (string) $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'created_at <= %s';
            $params[] = (string) $args['date_to'];
        }

        return [ 'WHERE ' . implode( ' AND ', $where ), $params ];
    }
}
