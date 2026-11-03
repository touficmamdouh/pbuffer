<?php
/**
 * Buffer GraphQL API client.
 *
 * @package LightWeb2\PublishWithBuffer
 */

declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

/**
 * Sends requests to the Buffer GraphQL API.
 */
final class Buffer_Client {

	/**
	 * Buffer GraphQL endpoint.
	 */
	public const ENDPOINT = 'https://api.buffer.com';

	/**
	 * Verify the token and load the account.
	 *
	 * @param string $token Buffer API token.
	 * @return array
	 */
	public function test_connection( string $token ): array {
		return $this->request(
			$token,
			'query LightWeb2Account {
                account {
                    id
                    name
                    organizations {
                        id
                        name
                    }
                }
            }'
		);
	}

	/**
	 * Load channels for an organization.
	 *
	 * @param string $token           Buffer API token.
	 * @param string $organization_id Buffer organization ID.
	 * @return array
	 */
	public function get_channels( string $token, string $organization_id ): array {
		return $this->request(
			$token,
			'query LightWeb2Channels($organizationId: OrganizationId!) {
                channels(input: { organizationId: $organizationId }) {
                    id
                    name
                    service
                }
            }',
			array( 'organizationId' => $organization_id )
		);
	}

	/**
	 * Create a Buffer post.
	 *
	 * @param string $token Buffer API token.
	 * @param array  $input CreatePost input.
	 * @return array
	 */
	public function create_post( string $token, array $input ): array {
		$result = $this->request(
			$token,
			'mutation LightWeb2CreatePost($input: CreatePostInput!) {
                createPost(input: $input) {
                    ... on PostActionSuccess {
                        post {
                            id
                            text
                        }
                    }
                    ... on MutationError {
                        message
                    }
                }
            }',
			array( 'input' => $input )
		);

		if ( ! $result['success'] ) {
			return $result;
		}

		$mutation = $result['data']['createPost'] ?? array();

		if ( isset( $mutation['message'] ) && ! isset( $mutation['post'] ) ) {
			return array(
				'success'     => false,
				'code'        => 'mutation_error',
				'message'     => sanitize_text_field( (string) $mutation['message'] ),
				'retryable'   => false,
				'retry_after' => 0,
			);
		}

		if ( empty( $mutation['post']['id'] ) ) {
			return array(
				'success'     => false,
				'code'        => 'invalid_response',
				'message'     => __( 'Buffer returned an unexpected response.', 'lightweb2-publish-with-buffer' ),
				'retryable'   => true,
				'retry_after' => 0,
			);
		}

		return $result;
	}

	/**
	 * Execute a GraphQL request.
	 *
	 * @param string $token     Buffer API token.
	 * @param string $query     GraphQL query or mutation.
	 * @param array  $variables Query variables.
	 * @return array
	 */
	private function request( string $token, string $query, array $variables = array() ): array {
		$response = wp_safe_remote_post(
			self::ENDPOINT,
			array(
				'headers'     => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'        => wp_json_encode(
					array(
						'query'     => $query,
						'variables' => $variables,
					)
				),
				'data_format' => 'body',
				'timeout'     => 15,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->failure(
				'wp_error',
				$response->get_error_message(),
				true
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( 401 === $status ) {
			return $this->failure(
				'unauthorized',
				__( 'The token is invalid or expired. Reconnect Buffer.', 'lightweb2-publish-with-buffer' ),
				false
			);
		}

		if ( 403 === $status ) {
			return $this->failure(
				'forbidden',
				__( 'Buffer rejected this account. Reconnect Buffer.', 'lightweb2-publish-with-buffer' ),
				false
			);
		}

		if ( 429 === $status ) {
			$retry_after = absint( wp_remote_retrieve_header( $response, 'retry-after' ) );

			return $this->failure(
				'rate_limited',
				__( 'Buffer rate limit reached. The delivery will be retried.', 'lightweb2-publish-with-buffer' ),
				true,
				$retry_after
			);
		}

		if ( $status >= 500 ) {
			return $this->failure(
				'server_error',
				__( 'Buffer is temporarily unavailable.', 'lightweb2-publish-with-buffer' ),
				true
			);
		}

		if ( $status >= 400 ) {
			return $this->failure(
				'http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Buffer returned HTTP %d.', 'lightweb2-publish-with-buffer' ),
					$status
				),
				false
			);
		}

		if ( ! is_array( $decoded ) ) {
			return $this->failure(
				'invalid_json',
				__( 'Buffer returned an invalid response.', 'lightweb2-publish-with-buffer' ),
				true
			);
		}

		if ( ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
			$error   = $decoded['errors'][0];
			$code    = isset( $error['extensions']['code'] )
				? sanitize_key( (string) $error['extensions']['code'] )
				: 'graphql_error';
			$message = isset( $error['message'] )
				? sanitize_text_field( (string) $error['message'] )
				: __( 'Buffer returned an error.', 'lightweb2-publish-with-buffer' );
			$retry   = in_array( $code, array( 'rate_limit_exceeded', 'unexpected' ), true );

			return $this->failure( $code, $message, $retry );
		}

		if ( empty( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return $this->failure(
				'invalid_response',
				__( 'Buffer returned an unexpected response.', 'lightweb2-publish-with-buffer' ),
				true
			);
		}

		return array(
			'success' => true,
			'data'    => $decoded['data'],
		);
	}

	/**
	 * Build a normalized failure response.
	 *
	 * @param string $code        Error code.
	 * @param string $message     Error message.
	 * @param bool   $retryable   Whether the request may be retried.
	 * @param int    $retry_after Retry delay in seconds.
	 * @return array
	 */
	private function failure(
		string $code,
		string $message,
		bool $retryable,
		int $retry_after = 0
	): array {
		return array(
			'success'     => false,
			'code'        => $code,
			'message'     => $message,
			'retryable'   => $retryable,
			'retry_after' => $retry_after,
		);
	}
}
