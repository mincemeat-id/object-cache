<?php
/**
 * Query cache smoke tests for WordPress.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

if (class_exists('WP_UnitTestCase')) {
    class Tests_WordPressQueryCacheSmoke extends WP_UnitTestCase
    {
        public function test_wp_query_cache()
        {
            // Create a test post
            $post_id = $this->factory->post->create(array(
                'post_title'   => 'Test Post Title',
                'post_content' => 'Test Post Content',
            ));

            // Clear query cache
            wp_cache_flush();

            // Run query 1
            $query1 = new WP_Query(array(
                'p' => $post_id,
            ));
            $this->assertNotEmpty($query1->posts);
            $this->assertSame('Test Post Title', $query1->posts[0]->post_title);

            // Run query 2, should pull from cache
            $query2 = new WP_Query(array(
                'p' => $post_id,
            ));
            $this->assertNotEmpty($query2->posts);
            $this->assertSame('Test Post Title', $query2->posts[0]->post_title);
        }

        public function test_wp_term_query_cache()
        {
            $term_id = $this->factory->term->create(array(
                'name'     => 'Test Term',
                'taxonomy' => 'category',
            ));

            wp_cache_flush();

            $query1 = new WP_Term_Query(array(
                'taxonomy' => 'category',
                'include'  => array($term_id),
            ));
            $this->assertNotEmpty($query1->terms);
            $this->assertSame('Test Term', $query1->terms[0]->name);

            $query2 = new WP_Term_Query(array(
                'taxonomy' => 'category',
                'include'  => array($term_id),
            ));
            $this->assertNotEmpty($query2->terms);
            $this->assertSame('Test Term', $query2->terms[0]->name);
        }

        public function test_wp_comment_query_cache()
        {
            $post_id = $this->factory->post->create();
            $comment_id = $this->factory->comment->create(array(
                'comment_post_ID' => $post_id,
                'comment_content' => 'Test comment content',
            ));

            wp_cache_flush();

            $query1 = new WP_Comment_Query(array(
                'comment__in' => array($comment_id),
            ));
            $this->assertNotEmpty($query1->comments);
            $this->assertSame('Test comment content', $query1->comments[0]->comment_content);

            $query2 = new WP_Comment_Query(array(
                'comment__in' => array($comment_id),
            ));
            $this->assertNotEmpty($query2->comments);
            $this->assertSame('Test comment content', $query2->comments[0]->comment_content);
        }

        public function test_wp_user_query_cache()
        {
            $user_id = $this->factory->user->create(array(
                'user_login' => 'testuser',
                'user_pass'  => 'password',
                'user_email' => 'testuser@example.com',
            ));

            wp_cache_flush();

            $query1 = new WP_User_Query(array(
                'include' => array($user_id),
            ));
            $this->assertNotEmpty($query1->results);
            $this->assertSame('testuser', $query1->results[0]->user_login);

            $query2 = new WP_User_Query(array(
                'include' => array($user_id),
            ));
            $this->assertNotEmpty($query2->results);
            $this->assertSame('testuser', $query2->results[0]->user_login);
        }
    }
}
