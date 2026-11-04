<?php
/**
 * Delivery status labels.
 *
 * @package LightWeb2\PublishWithBuffer
 */

declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

/**
 * Maps internal delivery statuses to translated labels.
 */
final class Statuses {

	/**
	 * Return the translated label for a delivery status.
	 *
	 * @param string $status Internal status.
	 * @return string
	 */
	public static function label( string $status ): string {
		switch ( $status ) {
			case 'pending':
				return __( 'Pending', 'lightweb2-publish-with-buffer' );
			case 'sending':
				return __( 'Sending', 'lightweb2-publish-with-buffer' );
			case 'sent':
				return __( 'Sent', 'lightweb2-publish-with-buffer' );
			case 'failed':
				return __( 'Failed', 'lightweb2-publish-with-buffer' );
			case 'canceled':
				return __( 'Canceled', 'lightweb2-publish-with-buffer' );
			default:
				return ucfirst( $status );
		}
	}
}
