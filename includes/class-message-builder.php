<?php
/**
 * Builds Buffer messages from post data.
 *
 * @package LightWeb2\PublishWithBuffer
 */

declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

/**
 * Replaces template variables with post content.
 */
final class Message_Builder {

	/**
	 * Build the message for a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $template Message template.
	 * @return string
	 */
	public function build( int $post_id, string $template ): string {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return '';
		}

		$title = wp_strip_all_tags(
			html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' )
		);

		if ( has_excerpt( $post_id ) ) {
			$excerpt = get_the_excerpt( $post_id );
		} else {
			$content = html_entity_decode(
				strip_shortcodes( (string) $post->post_content ),
				ENT_QUOTES,
				'UTF-8'
			);
			$content = preg_replace(
				'/<\/(p|div|h[1-6]|li|blockquote|tr)>/i',
				'$0 ',
				$content
			);
			$excerpt = wp_trim_words( wp_strip_all_tags( $content ), 20 );
		}

		$excerpt    = wp_strip_all_tags(
			html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' )
		);
		$author     = wp_strip_all_tags(
			html_entity_decode(
				(string) get_the_author_meta( 'display_name', (int) $post->post_author ),
				ENT_QUOTES,
				'UTF-8'
			)
		);
		$categories = get_the_category( $post_id );
		$category   = ! empty( $categories )
			? wp_strip_all_tags(
				html_entity_decode( $categories[0]->name, ENT_QUOTES, 'UTF-8' )
			)
			: '';

		$hashtags = array();

		$tags = get_the_tags( $post_id );

		foreach ( $tags ? $tags : array() as $tag ) {
			$clean = preg_replace(
				'/[^\p{L}\p{N}_]/u',
				'',
				html_entity_decode( (string) $tag->name, ENT_QUOTES, 'UTF-8' )
			);

			if ( is_string( $clean ) && '' !== $clean ) {
				$hashtags[] = '#' . $clean;
			}
		}

		$replacements = array(
			'{title}'    => $title,
			'{url}'      => (string) get_permalink( $post_id ),
			'{excerpt}'  => $excerpt,
			'{author}'   => (string) $author,
			'{category}' => (string) $category,
			'{tags}'     => implode( ' ', $hashtags ),
		);

		$message = strtr( $template, $replacements );
		$message = preg_replace( "/\n{3,}/", "\n\n", $message );

		return trim( is_string( $message ) ? $message : '' );
	}

	/**
	 * Build a message that fits a channel's service limits.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $template Message template.
	 * @param string $service  Buffer service slug.
	 * @return string
	 */
	public function build_for_service( int $post_id, string $template, string $service ): string {
		$message = $this->build( $post_id, $template );

		if ( 'twitter' !== strtolower( $service ) ) {
			return $message;
		}

		$url = (string) get_permalink( $post_id );

		if ( '' !== $url && false !== strpos( $message, $url ) ) {
			$prefix = trim( str_replace( $url, '', $message ) );

			if ( $this->utf16_length( $prefix ) + $this->utf16_length( "…\n" . $url ) <= 279 ) {
				return $message;
			}

			$available = max( 0, 279 - $this->utf16_length( "…\n" . $url ) );

			return $this->truncate_utf16( $prefix, $available ) . "…\n" . $url;
		}

		return $this->truncate_utf16( $message, 279 ) . '…';
	}

	/**
	 * Count UTF-16 code units used by Buffer's text validation.
	 *
	 * @param string $value Text to measure.
	 * @return int
	 */
	private function utf16_length( string $value ): int {
		return (int) ( strlen( mb_convert_encoding( $value, 'UTF-16LE', 'UTF-8' ) ) / 2 );
	}

	/**
	 * Truncate text without splitting a UTF-8 character.
	 *
	 * @param string $value Text to truncate.
	 * @param int    $limit Maximum UTF-16 code units.
	 * @return string
	 */
	private function truncate_utf16( string $value, int $limit ): string {
		$result = '';
		$used   = 0;

		foreach ( preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY ) as $character ) {
			$units = $this->utf16_length( $character );

			if ( $used + $units > $limit ) {
				break;
			}

			$result .= $character;
			$used   += $units;
		}

		return rtrim( $result );
	}

	/**
	 * Return the featured image URL for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	public function image_url( int $post_id ): ?string {
		if ( ! has_post_thumbnail( $post_id ) ) {
			return null;
		}

		$url = get_the_post_thumbnail_url( $post_id, 'full' );

		return is_string( $url ) && '' !== $url ? $url : null;
	}
}
