<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Deliveries;
use LightWeb2\PublishWithBuffer\Installer;

final class Test_Deliveries extends WP_UnitTestCase {

    private Deliveries $deliveries;

    public function set_up(): void {
        parent::set_up();
        Installer::create_table();
        $this->deliveries = new Deliveries();
        $this->deliveries->clear();
    }

    private function channel( string $id = 'channel-1' ): array {
        return [
            'id'      => $id,
            'name'    => 'Company LinkedIn',
            'service' => 'linkedin',
        ];
    }

    public function test_create_or_get_prevents_duplicates(): void {
        $first  = $this->deliveries->create_or_get( 10, $this->channel(), 'addToQueue' );
        $second = $this->deliveries->create_or_get( 10, $this->channel(), 'shareNow' );

        $this->assertSame( $first['id'], $second['id'] );
        $this->assertSame( 'pending', $first['status'] );
        $this->assertSame( 'Company LinkedIn', $first['channel_name'] );
    }

    public function test_status_transitions_and_attempts(): void {
        $row = $this->deliveries->create_or_get( 11, $this->channel(), 'addToQueue' );

        $this->assertSame( 1, $this->deliveries->increment_attempts( (int) $row['id'] ) );
        $this->deliveries->mark_failed( (int) $row['id'], 'server_error', 'Try later' );

        $failed = $this->deliveries->get( (int) $row['id'] );
        $this->assertSame( 'failed', $failed['status'] );
        $this->assertSame( 'server_error', $failed['error_code'] );

        $this->deliveries->mark_sent( (int) $row['id'], 'buffer-1' );

        $sent = $this->deliveries->get( (int) $row['id'] );
        $this->assertSame( 'sent', $sent['status'] );
        $this->assertSame( 'buffer-1', $sent['buffer_post_id'] );
    }

    public function test_claim_allows_only_one_worker(): void {
        $row = $this->deliveries->create_or_get( 14, $this->channel(), 'addToQueue' );

        $this->assertTrue( $this->deliveries->claim( (int) $row['id'], 1 ) );
        $this->assertFalse( $this->deliveries->claim( (int) $row['id'], 1 ) );

        $claimed = $this->deliveries->get( (int) $row['id'] );
        $this->assertSame( 'sending', $claimed['status'] );
        $this->assertSame( 1, (int) $claimed['attempts'] );
    }

    public function test_claim_rejects_stale_attempt(): void {
        $row = $this->deliveries->create_or_get( 15, $this->channel(), 'addToQueue' );

        $this->assertFalse( $this->deliveries->claim( (int) $row['id'], 2 ) );

        $unchanged = $this->deliveries->get( (int) $row['id'] );
        $this->assertSame( 'pending', $unchanged['status'] );
        $this->assertSame( 0, (int) $unchanged['attempts'] );
    }

    public function test_reset_for_retry_clears_attempts_and_errors(): void {
        $row = $this->deliveries->create_or_get( 16, $this->channel(), 'addToQueue' );
        $this->deliveries->increment_attempts( (int) $row['id'] );
        $this->deliveries->increment_attempts( (int) $row['id'] );
        $this->deliveries->mark_failed( (int) $row['id'], 'server_error', 'Try later' );

        $this->assertTrue( $this->deliveries->reset_for_retry( (int) $row['id'] ) );

        $reset = $this->deliveries->get( (int) $row['id'] );
        $this->assertSame( 'pending', $reset['status'] );
        $this->assertSame( 0, (int) $reset['attempts'] );
        $this->assertNull( $reset['error_code'] );
        $this->assertNull( $reset['error_message'] );
    }

    public function test_reset_for_retry_rejects_sending_row(): void {
        $row = $this->deliveries->create_or_get( 18, $this->channel(), 'addToQueue' );
        $this->assertTrue( $this->deliveries->claim( (int) $row['id'], 1 ) );
        $before = $this->deliveries->get( (int) $row['id'] );

        $this->assertFalse( $this->deliveries->reset_for_retry( (int) $row['id'] ) );
        $this->assertSame( $before, $this->deliveries->get( (int) $row['id'] ) );
    }

    public function test_mark_stale_does_not_overwrite_sent_row(): void {
        $row = $this->deliveries->create_or_get( 19, $this->channel(), 'addToQueue' );
        $this->assertTrue( $this->deliveries->claim( (int) $row['id'], 1 ) );
        $this->deliveries->mark_sent( (int) $row['id'], 'buffer-1' );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'lightweb2_deliveries',
            [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ) ],
            [ 'id' => (int) $row['id'] ]
        );
        $before = $this->deliveries->get( (int) $row['id'] );

        $this->assertFalse( $this->deliveries->mark_stale( (int) $row['id'] ) );
        $this->assertSame( $before, $this->deliveries->get( (int) $row['id'] ) );
    }

    public function test_get_stale_sending_returns_only_old_sending_rows(): void {
        $old    = $this->deliveries->create_or_get( 17, $this->channel( 'old' ), 'addToQueue' );
        $recent = $this->deliveries->create_or_get( 17, $this->channel( 'recent' ), 'addToQueue' );
        $failed = $this->deliveries->create_or_get( 17, $this->channel( 'failed' ), 'addToQueue' );

        $this->assertTrue( $this->deliveries->claim( (int) $old['id'], 1 ) );
        $this->assertTrue( $this->deliveries->claim( (int) $recent['id'], 1 ) );
        $this->deliveries->mark_failed( (int) $failed['id'], 'server_error', 'Try later' );

        global $wpdb;
        $table = $wpdb->prefix . 'lightweb2_deliveries';
        $wpdb->update(
            $table,
            [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ) ],
            [ 'id' => (int) $old['id'] ]
        );
        $wpdb->update(
            $table,
            [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ) ],
            [ 'id' => (int) $failed['id'] ]
        );

        $stale = $this->deliveries->get_stale_sending( 15 );

        $this->assertCount( 1, $stale );
        $this->assertSame( (string) $old['id'], (string) $stale[0]['id'] );
    }

    public function test_recent_rows_can_be_filtered_by_status(): void {
        $sent   = $this->deliveries->create_or_get( 12, $this->channel( 'c1' ), 'addToQueue' );
        $failed = $this->deliveries->create_or_get( 12, $this->channel( 'c2' ), 'addToQueue' );

        $this->deliveries->mark_sent( (int) $sent['id'], 'buffer-1' );
        $this->deliveries->mark_failed( (int) $failed['id'], 'server_error', 'Try later' );

        $rows = $this->deliveries->get_recent( [ 'status' => 'failed' ] );

        $this->assertCount( 1, $rows );
        $this->assertSame( 'failed', $rows[0]['status'] );
        $this->assertSame( 1, $this->deliveries->count_recent( [ 'status' => 'failed' ] ) );
    }

    public function test_clear_and_retention_cleanup(): void {
        $this->deliveries->create_or_get( 13, $this->channel(), 'addToQueue' );
        $this->assertSame( 1, $this->deliveries->count_recent() );

        $this->assertSame( 0, $this->deliveries->delete_older_than_days( 30 ) );
        $this->assertSame( 1, $this->deliveries->clear() );
    }
}
