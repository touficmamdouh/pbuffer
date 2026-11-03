<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Message_Builder {

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
            $content = preg_replace( '/<[^>]+>/', ' ', $content );
            $excerpt = wp_trim_words( wp_strip_all_tags( $content ), 20 );
        }

        $excerpt = wp_strip_all_tags(
            html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' )
        );
        $author  = wp_strip_all_tags(
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

        $hashtags = [];

        foreach ( get_the_tags( $post_id ) ?: [] as $tag ) {
            $clean = preg_replace(
                '/[^\p{L}\p{N}_]/u',
                '',
                html_entity_decode( (string) $tag->name, ENT_QUOTES, 'UTF-8' )
            );

            if ( is_string( $clean ) && '' !== $clean ) {
                $hashtags[] = '#' . $clean;
            }
        }

        $replacements = [
            '{title}'    => $title,
            '{url}'      => (string) get_permalink( $post_id ),
            '{excerpt}'  => $excerpt,
            '{author}'   => (string) $author,
            '{category}' => (string) $category,
            '{tags}'     => implode( ' ', $hashtags ),
        ];

        $message = strtr( $template, $replacements );
        $message = preg_replace( "/\n{3,}/", "\n\n", $message );

        return trim( is_string( $message ) ? $message : '' );
    }

    public function image_url( int $post_id ): ?string {
        if ( ! has_post_thumbnail( $post_id ) ) {
            return null;
        }

        $url = get_the_post_thumbnail_url( $post_id, 'full' );

        return is_string( $url ) && '' !== $url ? $url : null;
    }
}
