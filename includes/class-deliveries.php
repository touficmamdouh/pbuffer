<?php
/**
 * Delivery repository.
 *
 * @package LightWeb2\PublishWithBuffer
 */

declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

/**
 * Reads and writes delivery records.
 */
final class Deliveries {

	/**
	 * Deliveries table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'lightweb2_deliveries';
	}

	/**
	 * Create a delivery or return the existing one.
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $channel Channel data.
	 * @param string $mode    Publishing mode.
	 * @return array
	 */
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
			array(
				'post_id'      => $post_id,
				'channel_id'   => (string) $channel['id'],
				'channel_name' => (string) ( $channel['name'] ?? '' ),
				'service'      => (string) ( $channel['service'] ?? '' ),
				'mode'         => $mode,
				'status'       => 'pending',
				'attempts'     => 0,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return $this->get_by_post_channel( $post_id, (string) $channel['id'] ) ?? array();
		}

		$row = $this->get( (int) $wpdb->insert_id );

		return $row ?? array();
	}

	/**
	 * Get a delivery by ID.
	 *
	 * @param int $id Delivery ID.
	 * @return array|null
	 */
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

	/**
	 * Get a delivery by post and channel.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $channel_id Channel ID.
	 * @return array|null
	 */
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

	/**
	 * Get all deliveries for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
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

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Increment the attempt counter for a delivery.
	 *
	 * @param int $id Delivery ID.
	 * @return int
	 */
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

	/**
	 * Mark a delivery as sent.
	 *
	 * @param int    $id             Delivery ID.
	 * @param string $buffer_post_id Buffer post ID.
	 * @return void
	 */
	public function mark_sent( int $id, string $buffer_post_id ): void {
		$this->update(
			$id,
			array(
				'status'         => 'sent',
				'buffer_post_id' => $buffer_post_id,
				'error_code'     => null,
				'error_message'  => null,
			)
		);
	}

	/**
	 * Mark a delivery as failed.
	 *
	 * @param int    $id      Delivery ID.
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return void
	 */
	public function mark_failed( int $id, string $code, string $message ): void {
		$this->update(
			$id,
			array(
				'status'        => 'failed',
				'error_code'    => $code,
				'error_message' => wp_html_excerpt( $message, 1000, '' ),
			)
		);
	}

	/**
	 * Mark a delivery as canceled.
	 *
	 * @param int $id Delivery ID.
	 * @return void
	 */
	public function mark_canceled( int $id ): void {
		$this->update( $id, array( 'status' => 'canceled' ) );
	}

	/**
	 * Claim a delivery for an attempt.
	 *
	 * @param int $id      Delivery ID.
	 * @param int $attempt Attempt number.
	 * @return bool
	 */
	public function claim( int $id, int $attempt ): bool {
		global $wpdb;

		if ( $attempt < 1 ) {
			return false;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
		$updated = $wpdb->query(
			$wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
				"UPDATE {$this->table}
                 SET status = 'sending', attempts = attempts + 1, updated_at = %s
                 WHERE id = %d
                   AND status IN ('pending', 'failed')
                   AND attempts = %d
                   AND attempts < 4",
				current_time( 'mysql', true ),
				$id,
				$attempt - 1
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Reset a failed delivery for manual retry.
	 *
	 * @param int $id Delivery ID.
	 * @return bool
	 */
	public function reset_for_retry( int $id ): bool {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
		$updated = $wpdb->query(
			$wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
				"UPDATE {$this->table}
                 SET status = 'pending', attempts = 0,
                     error_code = NULL, error_message = NULL, updated_at = %s
                 WHERE id = %d AND status = 'failed'",
				current_time( 'mysql', true ),
				$id
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Get deliveries stuck in the sending state.
	 *
	 * @param int $minutes Age in minutes.
	 * @return array
	 */
	public function get_stale_sending( int $minutes = 15 ): array {
		global $wpdb;

		$cutoff = gmdate(
			'Y-m-d H:i:s',
			time() - ( max( 1, $minutes ) * MINUTE_IN_SECONDS )
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
				"SELECT * FROM {$this->table}
                 WHERE status = 'sending' AND updated_at < %s",
				$cutoff
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark a stale sending delivery as failed.
	 *
	 * @param int $id      Delivery ID.
	 * @param int $minutes Age in minutes.
	 * @return bool
	 */
	public function mark_stale( int $id, int $minutes = 15 ): bool {
		global $wpdb;

		$cutoff = gmdate(
			'Y-m-d H:i:s',
			time() - ( max( 1, $minutes ) * MINUTE_IN_SECONDS )
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
		$updated = $wpdb->query(
			$wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
				"UPDATE {$this->table}
                 SET status = 'failed', error_code = 'stale_claim',
                     error_message = %s, updated_at = %s
                 WHERE id = %d
                   AND status = 'sending'
                   AND updated_at < %s",
				__( 'The delivery was interrupted and will be retried.', 'lightweb2-publish-with-buffer' ),
				current_time( 'mysql', true ),
				$id,
				$cutoff
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Get recent deliveries matching filters.
	 *
	 * @param array $args Filter arguments.
	 * @return array
	 */
	public function get_recent( array $args = array() ): array {
		global $wpdb;

		[ $where, $params ] = $this->build_where( $args );
		$per_page           = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page               = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset             = ( $page - 1 ) * $per_page;
		$params[]           = $per_page;
		$params[]           = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; the WHERE clause is assembled from internal column names and placeholders.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The dynamic list contains the two LIMIT/OFFSET values appended above.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name and WHERE fragments are built internally; the parameter list is dynamic.
				"SELECT * FROM {$this->table} {$where}
				 ORDER BY created_at DESC, id DESC
				 LIMIT %d OFFSET %d",
				...$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count recent deliveries matching filters.
	 *
	 * @param array $args Filter arguments.
	 * @return int
	 */
	public function count_recent( array $args = array() ): int {
		global $wpdb;

		[ $where, $params ] = $this->build_where( $args );
		if ( empty( $params ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; the WHERE clause contains only internal SQL fragments.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} {$where}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; the WHERE clause is assembled from internal column names and placeholders.
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The dynamic list is empty only in the branch above; this branch always has filters.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name and WHERE fragments are built internally; the parameter list is dynamic.
				"SELECT COUNT(*) FROM {$this->table} {$where}",
				...$params
			)
		);
	}

	/**
	 * Delete all deliveries.
	 *
	 * @return int
	 */
	public function clear(): int {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
		return (int) $wpdb->query( "DELETE FROM {$this->table}" );
	}

	/**
	 * Delete deliveries older than a number of days.
	 *
	 * @param int $days Retention days.
	 * @return int
	 */
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

	/**
	 * Update a delivery record.
	 *
	 * @param int   $id   Delivery ID.
	 * @param array $data Columns to update.
	 * @return void
	 */
	private function update( int $id, array $data ): void {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
		$wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	/**
	 * Build a WHERE clause from filter arguments.
	 *
	 * @param array $args Filter arguments.
	 * @return array
	 */
	private function build_where( array $args ): array {
		$where  = array( '1=1' );
		$params = array();

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

		return array( 'WHERE ' . implode( ' AND ', $where ), $params );
	}
}
