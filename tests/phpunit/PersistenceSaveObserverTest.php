<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\PersistenceOccurrenceRepository;
use FlavorAgent\Activity\PersistenceSaveObserver;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PersistenceSaveObserverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		Repository::install();
		PersistenceOccurrenceRepository::install();
		Repository::create(
			[
				'id'        => 'apply-1',
				'type'      => 'apply_suggestion',
				'surface'   => 'block',
				'applyLane' => 'editor-state',
				'target'    => [
					'blockName' => 'core/heading',
					'blockPath' => [ 0 ],
				],
				'after'     => [ 'attributes' => [ 'level' => 3 ] ],
				'document'  => [
					'scopeKey' => 'post:42',
					'postType' => 'post',
					'entityId' => '42',
				],
			]
		);
	}

	public function test_two_headerless_saves_get_distinct_frozen_occurrences(): void {
		$this->assertTrue( class_exists( PersistenceSaveObserver::class ) );
		$post = (object) [
			'ID'           => 42,
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_content' => 'first',
		];
		PersistenceSaveObserver::after_save( 42, $post, true, clone $post );
		$post->post_content = 'second';
		PersistenceSaveObserver::after_save( 42, $post, true, clone $post );
		$rows = WordPressTestState::$db_tables[ PersistenceOccurrenceRepository::table_name() ];
		$this->assertCount( 2, $rows );
		$this->assertNotSame( $rows[0]['occurrence_id'], $rows[1]['occurrence_id'] );
		$this->assertSame( 'first', PersistenceOccurrenceRepository::find( $rows[0]['snapshot_id'] )['content'] );
	}

	public function test_rest_double_fire_is_one_capture_and_failed_response_does_not_erase_it(): void {
		$this->assertTrue( class_exists( PersistenceSaveObserver::class ) );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts/42' );
		$request->set_header( 'X-Flavor-Agent-Save-Occurrence', 'client-save-1' );
		$post = (object) [
			'ID'           => 42,
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_content' => 'saved',
		];
		PersistenceSaveObserver::before_request( null, [], $request );
		PersistenceSaveObserver::after_save( 42, $post, true, clone $post );
		PersistenceSaveObserver::after_save( 42, $post, true, clone $post );
		$error = new \WP_Error( 'lost-response', 'Lost response' );
		$this->assertSame( $error, PersistenceSaveObserver::after_request( $error, [], $request ) );
		$rows = WordPressTestState::$db_tables[ PersistenceOccurrenceRepository::table_name() ];
		$this->assertCount( 1, $rows );
		$this->assertSame( 'client-save-1', $rows[0]['occurrence_id'] );
	}

	public function test_trash_untrash_revision_and_autosave_requests_emit_no_capture(): void {
		$this->assertTrue( class_exists( PersistenceSaveObserver::class ) );
		$post = (object) [
			'ID'           => 42,
			'post_type'    => 'post',
			'post_status'  => 'trash',
			'post_content' => 'content',
		];
		PersistenceSaveObserver::after_save( 42, $post, true, null );
		$before            = clone $post;
		$post->post_status = 'draft';
		PersistenceSaveObserver::after_save( 42, $post, true, $before );
		$post->post_type = 'revision';
		PersistenceSaveObserver::after_save( 42, $post, false, null );
		$post->post_type = 'post';
		$request         = new \WP_REST_Request( 'POST', '/wp/v2/posts/42/autosaves' );
		PersistenceSaveObserver::before_request( null, [], $request );
		PersistenceSaveObserver::after_save( 42, $post, true, null );
		PersistenceSaveObserver::after_request( null, [], $request );
		$this->assertCount( 0, WordPressTestState::$db_tables[ PersistenceOccurrenceRepository::table_name() ] );
	}

	public function test_reset_freezes_theme_identity_before_deletion_and_captures_fallback_after_it(): void {
		$post                          = (object) [
			'ID'           => 90,
			'post_type'    => 'wp_template',
			'post_name'    => 'home',
			'post_status'  => 'publish',
			'post_content' => 'override',
		];
		WordPressTestState::$posts[90] = $post;
		WordPressTestState::$object_terms[90]['wp_theme'] = [ 'test-theme' ];
		Repository::create(
			[
				'id'        => 'template-apply',
				'type'      => 'apply_suggestion',
				'surface'   => 'block',
				'applyLane' => 'editor-state',
				'document'  => [
					'scopeKey' => 'wp_template:test-theme//home',
					'postType' => 'wp_template',
					'entityId' => 'test-theme//home',
				],
			]
		);
		$request = new \WP_REST_Request( 'POST', '/wp/v2/templates/test-theme//home' );
		$request->set_header( 'X-Flavor-Agent-Save-Occurrence', 'reset-one' );
		PersistenceSaveObserver::before_request( null, [], $request );
		PersistenceSaveObserver::before_delete( 90, $post );
		unset( WordPressTestState::$posts[90], WordPressTestState::$object_terms[90] );
		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'      => 'test-theme//home',
				'type'    => 'wp_template',
				'source'  => 'theme',
				'content' => 'theme fallback',
			],
		];
		PersistenceSaveObserver::after_delete( 90, $post );
		PersistenceSaveObserver::after_request( null, [], $request );
		$snapshot = PersistenceOccurrenceRepository::find_occurrence(
			'reset-one',
			[
				'postType' => 'wp_template',
				'ref'      => 'test-theme//home',
			]
		);
		$this->assertSame( 'theme fallback', $snapshot['content'] );
		$this->assertSame( 'reset_to_theme', $snapshot['reason'] );
		$this->assertSame( 'theme', $snapshot['entity']['source'] );
		$this->assertTrue( $snapshot['entity']['identityVerified'] );
		$this->assertSame( 'test-theme', $snapshot['entity']['theme'] );
	}

	public function test_reused_header_cannot_overwrite_an_earlier_saved_version(): void {
		$post = (object) [
			'ID'           => 42,
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_content' => 'one',
		];
		foreach ( [ 'one', 'two' ] as $content ) {
			$post->post_content = $content;
			$request            = new \WP_REST_Request( 'POST', '/wp/v2/posts/42' );
			$request->set_header( 'X-Flavor-Agent-Save-Occurrence', 'reused-header' );
			PersistenceSaveObserver::before_request( null, [], $request );
			PersistenceSaveObserver::after_save( 42, $post, true, clone $post );
			PersistenceSaveObserver::after_request( null, [], $request );
		}
		$rows = WordPressTestState::$db_tables[ PersistenceOccurrenceRepository::table_name() ];
		$this->assertCount( 2, $rows );
		$this->assertNotSame( $rows[0]['occurrence_id'], $rows[1]['occurrence_id'] );
		$this->assertSame( 'one', PersistenceOccurrenceRepository::find( $rows[0]['snapshot_id'] )['content'] );
		$this->assertSame( 'two', PersistenceOccurrenceRepository::find( $rows[1]['snapshot_id'] )['content'] );
	}

	public function test_custom_post_type_with_default_rest_base_keeps_client_correlation(): void {
		WordPressTestState::$post_type_objects['book'] = (object) [
			'name'           => 'book',
			'rest_base'      => false,
			'rest_namespace' => false,
		];
		Repository::create(
			[
				'id'        => 'book-apply',
				'type'      => 'apply_suggestion',
				'surface'   => 'block',
				'applyLane' => 'editor-state',
				'document'  => [
					'scopeKey' => 'book:42',
					'postType' => 'book',
					'entityId' => '42',
				],
			]
		);
		$request = new \WP_REST_Request( 'POST', '/wp/v2/book/42' );
		$request->set_header( 'X-Flavor-Agent-Save-Occurrence', 'book-save' );
		PersistenceSaveObserver::before_request( null, [], $request );
		PersistenceSaveObserver::after_save(
			42,
			(object) [
				'ID'           => 42,
				'post_type'    => 'book',
				'post_status'  => 'publish',
				'post_content' => 'book content',
			],
			true,
			null
		);
		PersistenceSaveObserver::after_request( null, [], $request );
		$this->assertIsArray(
			PersistenceOccurrenceRepository::find_occurrence(
				'book-save',
				[
					'postType' => 'book',
					'ref'      => '42',
				]
			)
		);
	}

	public function test_non_force_template_delete_does_not_turn_trash_into_a_save(): void {
		$post = (object) [
			'ID'          => 90,
			'post_type'   => 'wp_template',
			'post_name'   => 'home',
			'post_status' => 'publish',
		];
		WordPressTestState::$object_terms[90]['wp_theme'] = [ 'test-theme' ];
		Repository::create(
			[
				'id'        => 'template-apply',
				'type'      => 'apply_template_suggestion',
				'surface'   => 'template',
				'applyLane' => 'editor-state',
				'document'  => [
					'scopeKey' => 'wp_template:test-theme//home',
					'postType' => 'wp_template',
					'entityId' => 'test-theme//home',
				],
			]
		);
		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/templates/test-theme//home' );
		$request->set_param( 'force', false );
		PersistenceSaveObserver::before_request( null, [], $request );
		PersistenceSaveObserver::before_delete( 90, $post );
		PersistenceSaveObserver::after_delete( 90, $post );
		PersistenceSaveObserver::after_request( null, [], $request );
		$this->assertCount( 0, WordPressTestState::$db_tables[ PersistenceOccurrenceRepository::table_name() ] );
	}
}
