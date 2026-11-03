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
