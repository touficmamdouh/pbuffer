<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Message_Builder;

final class Test_Message_Builder extends WP_UnitTestCase {

    private Message_Builder $builder;

    public function set_up(): void {
        parent::set_up();
        $this->builder = new Message_Builder();
    }

    public function test_all_template_variables_are_replaced(): void {
        $user_id = self::factory()->user->create(
            [
                'display_name' => 'Ada Lovelace',
                'role'         => 'author',
            ]
        );
        $category_id = self::factory()->category->create( [ 'name' => 'Engineering' ] );
        $post_id     = self::factory()->post->create(
            [
                'post_author'   => $user_id,
                'post_title'    => 'A &amp; B',
                'post_content'  => 'This is the content.',
                'post_excerpt'  => 'A short excerpt.',
                'post_category' => [ $category_id ],
            ]
        );
        wp_set_post_tags( $post_id, [ 'WordPress News', 'Buffer' ] );

        $message = $this->builder->build(
            $post_id,
            "{title}\n{excerpt}\n{author}\n{category}\n{tags}\n{url}"
        );

        $this->assertStringContainsString( 'A & B', $message );
        $this->assertStringContainsString( 'A short excerpt.', $message );
        $this->assertStringContainsString( 'Ada Lovelace', $message );
        $this->assertStringContainsString( 'Engineering', $message );
        $this->assertStringContainsString( '#WordPressNews', $message );
        $this->assertStringContainsString( '#Buffer', $message );
        $this->assertStringContainsString( get_permalink( $post_id ), $message );
    }

    public function test_excerpt_falls_back_to_trimmed_content(): void {
        $post_id = self::factory()->post->create(
            [
                'post_title'   => 'Fallback',
                'post_content' => '<p>' . str_repeat( 'word ', 40 ) . '</p>',
                'post_excerpt' => '',
            ]
        );

        $message = $this->builder->build( $post_id, '{excerpt}' );

        $this->assertStringNotContainsString( '<p>', $message );
        $this->assertStringContainsString( 'word', $message );
        $this->assertLessThan( 200, strlen( $message ) );
    }

    public function test_unicode_tags_become_hashtags(): void
    {
        $post_id = self::factory()->post->create( [ 'post_title' => 'Arabic' ] );
        wp_set_post_tags( $post_id, [ 'تقنية', 'ويب ٢' ] );

        $message = $this->builder->build( $post_id, '{tags}' );

        $this->assertStringContainsString( '#تقنية', $message );
        $this->assertStringContainsString( '#ويب٢', $message );
    }

    public function test_plain_text_fields_are_decoded_and_stripped(): void
    {
        $user_id = self::factory()->user->create(
            [
                'display_name' => 'R&amp;D',
                'role'         => 'author',
            ]
        );
        $category_id = self::factory()->category->create( [ 'name' => 'R&amp;D' ] );
        $post_id     = self::factory()->post->create(
            [
                'post_author'   => $user_id,
                'post_title'    => 'Launch &lt;em&gt;now&lt;/em&gt;',
                'post_excerpt'  => '<p>Hello &amp; welcome</p>',
                'post_category' => [ $category_id ],
            ]
        );
        wp_set_post_tags( $post_id, [ 'R&D' ] );

        $message = $this->builder->build(
            $post_id,
            "{title}\n{excerpt}\n{author}\n{category}\n{tags}"
        );

        $this->assertStringNotContainsString( '<em>', $message );
        $this->assertStringNotContainsString( '<p>', $message );
        $this->assertStringContainsString( 'Launch now', $message );
        $this->assertStringContainsString( 'Hello & welcome', $message );
        $this->assertStringContainsString( 'R&D', $message );
        $this->assertStringContainsString( '#RD', $message );
        $this->assertStringNotContainsString( '#RampD', $message );
    }

    public function test_positive_thumbnail_returns_image_url(): void
    {
        $post_id = self::factory()->post->create();
        $attachment_id = self::factory()->attachment->create(
            [
                'post_mime_type' => 'image/jpeg',
                'post_parent'    => $post_id,
            ]
        );
        // Bypass set_post_thumbnail()'s real-file checks for a metadata-only fixture.
        update_post_meta( $post_id, '_thumbnail_id', $attachment_id );

        add_filter(
            'post_thumbnail_url',
            static function (): string {
                return 'https://example.com/image.jpg';
            }
        );

        $this->assertSame(
            'https://example.com/image.jpg',
            $this->builder->image_url( $post_id )
        );
    }

    public function test_image_url_is_null_without_a_thumbnail(): void
    {
        $post_id = self::factory()->post->create();

        $this->assertNull( $this->builder->image_url( $post_id ) );
    }
}
