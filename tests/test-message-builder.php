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

    public function test_image_url_is_null_without_a_thumbnail(): void
    {
        $post_id = self::factory()->post->create();

        $this->assertNull( $this->builder->image_url( $post_id ) );
    }
}
