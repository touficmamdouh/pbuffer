<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Buffer_Client;

final class Test_Buffer_Client extends WP_UnitTestCase {

    private Buffer_Client $client;

    public function set_up(): void {
        parent::set_up();
        $this->client = new Buffer_Client();
    }

    private function mock_response( array $body, int $status = 200, array $headers = [] ): void {
        add_filter(
            'pre_http_request',
            static function () use ( $body, $status, $headers ): array {
                return [
                    'headers'  => $headers,
                    'body'     => wp_json_encode( $body ),
                    'response' => [
                        'code'    => $status,
                        'message' => 200 === $status ? 'OK' : 'Error',
                    ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );
    }

    public function test_test_connection_sends_bearer_token_and_returns_data(): void {
        $captured = [];

        add_filter(
            'pre_http_request',
            static function ( $pre, $args ) use ( &$captured ): array {
                $captured = $args;

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(
                        [
                            'data' => [
                                'account' => [
                                    'id'            => 'acct-1',
                                    'name'          => 'Acme',
                                    'organizations' => [
                                        [ 'id' => 'org-1', 'name' => 'Acme Org' ],
                                    ],
                                ],
                            ],
                        ]
                    ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            },
            10,
            2
        );

        $result = $this->client->test_connection( 'test-token' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'Acme', $result['data']['account']['name'] );
        $this->assertSame( 'Bearer test-token', $captured['headers']['Authorization'] );
    }

    public function test_unauthorized_is_not_retryable(): void {
        $this->mock_response( [ 'errors' => [ [ 'message' => 'Not authorized' ] ] ], 401 );

        $result = $this->client->test_connection( 'bad-token' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'unauthorized', $result['code'] );
        $this->assertFalse( $result['retryable'] );
        $this->assertStringNotContainsString( 'bad-token', $result['message'] );
    }

    public function test_rate_limit_is_retryable_and_reads_retry_after(): void {
        $this->mock_response(
            [ 'errors' => [ [ 'message' => 'Too many requests' ] ] ],
            429,
            [ 'retry-after' => '120' ]
        );

        $result = $this->client->test_connection( 'test-token' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'rate_limited', $result['code'] );
        $this->assertTrue( $result['retryable'] );
        $this->assertSame( 120, $result['retry_after'] );
    }

    public function test_graphql_rate_limit_code_is_retryable(): void {
        $this->mock_response(
            [
                'errors' => [
                    [
                        'message'    => 'Rate limit exceeded',
                        'extensions' => [ 'code' => 'RATE_LIMIT_EXCEEDED' ],
                    ],
                ],
            ]
        );

        $result = $this->client->test_connection( 'test-token' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'rate_limit_exceeded', $result['code'] );
        $this->assertTrue( $result['retryable'] );
    }

    public function test_http_400_returns_buffer_graphql_error_message(): void {
        $this->mock_response(
            [
                'errors' => [
                    [
                        'message'    => 'Variable $input got invalid value for channelId',
                        'extensions' => [ 'code' => 'BAD_USER_INPUT' ],
                    ],
                ],
            ],
            400
        );

        $result = $this->client->test_connection( 'test-token' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'bad_user_input', $result['code'] );
        $this->assertSame( 'Variable $input got invalid value for channelId', $result['message'] );
        $this->assertFalse( $result['retryable'] );
    }

    public function test_create_post_returns_buffer_post_id(): void {
        $this->mock_response(
            [
                'data' => [
                    'createPost' => [
                        'post' => [ 'id' => 'buffer-post-1', 'text' => 'Hello' ],
                    ],
                ],
            ]
        );

        $result = $this->client->create_post(
            'test-token',
            [
                'text'           => 'Hello',
                'channelId'      => 'channel-1',
                'schedulingType' => 'automatic',
                'mode'           => 'addToQueue',
            ]
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'buffer-post-1', $result['data']['createPost']['post']['id'] );
    }

    public function test_create_post_typed_error_is_not_retryable(): void {
        $this->mock_response(
            [
                'data' => [
                    'createPost' => [
                        'message' => 'Text is required',
                    ],
                ],
            ]
        );

        $result = $this->client->create_post( 'test-token', [ 'text' => '' ] );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'mutation_error', $result['code'] );
        $this->assertSame( 'Text is required', $result['message'] );
        $this->assertFalse( $result['retryable'] );
    }
}
