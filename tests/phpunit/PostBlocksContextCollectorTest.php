<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\PostBlocksContextCollector;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PostBlocksContextCollectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
	}

	private function seed_post(
		int $id,
		string $content,
		string $post_type = 'post',
		string $post_status = 'publish',
		string $post_password = ''
	): void {
		WordPressTestState::$posts[ $id ] = new \WP_Post(
			[
				'ID'            => $id,
				'post_type'     => $post_type,
				'post_status'   => $post_status,
				'post_title'    => 'Sample document',
				'post_content'  => $content,
				'post_password' => $post_password,
			]
		);
	}

	private function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	// ---------------------------------------------------------------------
	// Target resolution allowlist (fail closed).
	// ---------------------------------------------------------------------

	public function test_unknown_post_fails_closed(): void {
		$result = ServerCollector::for_post_blocks( 987654 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}

	public function test_zero_post_id_fails_closed(): void {
		$result = ServerCollector::for_post_blocks( 0 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}

	public function test_unsupported_post_type_fails_closed(): void {
		$this->seed_post( 71, $this->paragraph( 'Hello' ), 'wp_template_part' );

		$result = ServerCollector::for_post_blocks( 71 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}

	public function test_trashed_post_fails_closed(): void {
		$this->seed_post( 72, $this->paragraph( 'Hello' ), 'post', 'trash' );

		$result = ServerCollector::for_post_blocks( 72 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}

	public function test_password_protected_post_fails_closed(): void {
		$this->seed_post( 73, $this->paragraph( 'Hello' ), 'post', 'publish', 'secret' );

		$result = ServerCollector::for_post_blocks( 73 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}

	public function test_page_and_draft_are_allowed(): void {
		$this->seed_post( 74, $this->paragraph( 'Hello' ), 'page', 'draft' );

		$result = ServerCollector::for_post_blocks( 74 );

		$this->assertIsArray( $result );
		$this->assertSame( 74, $result['postId'] );
		$this->assertSame( 'page', $result['postType'] );
		$this->assertSame( 'draft', $result['postStatus'] );
	}

	// ---------------------------------------------------------------------
	// Document target contract shape.
	// ---------------------------------------------------------------------

	public function test_nested_tree_produces_correct_paths(): void {
		$content = '<!-- wp:group --><div class="wp-block-group">'
			. $this->paragraph( 'Inner' )
			. '<!-- wp:heading --><h2>Head</h2><!-- /wp:heading -->'
			. '</div><!-- /wp:group -->'
			. $this->paragraph( 'Outer' );
		$this->seed_post( 80, $content );

		$result = ServerCollector::for_post_blocks( 80 );

		$this->assertIsArray( $result );

		$paths = array_map(
			static fn( array $target ): array => $target['path'],
			$result['operationTargets']
		);

		$this->assertContains( [ 0 ], $paths );
		$this->assertContains( [ 0, 0 ], $paths );
		$this->assertContains( [ 0, 1 ], $paths );
		$this->assertContains( [ 1 ], $paths );

		$names = array_column( $result['operationTargets'], 'name' );
		$this->assertContains( 'core/group', $names );
		$this->assertContains( 'core/heading', $names );

		$placements = array_column( $result['insertionAnchors'], 'placement' );
		$this->assertContains( 'start', $placements );
		$this->assertContains( 'end', $placements );
		$this->assertContains( 'before_block_path', $placements );
		$this->assertContains( 'after_block_path', $placements );
	}

	public function test_baseline_hash_matches_reserialized_content(): void {
		$content = $this->paragraph( 'Hash me' );
		$this->seed_post( 81, $content );

		$result = ServerCollector::for_post_blocks( 81 );

		$this->assertIsArray( $result );
		$this->assertSame(
			hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) ),
			$result['baselineContentHash']
		);
		$this->assertSame( PostBlocksContextCollector::content_hash( $content ), $result['baselineContentHash'] );
	}

	public function test_resolve_post_for_apply_returns_live_post(): void {
		$this->seed_post( 82, $this->paragraph( 'Live' ) );

		$post = ServerCollector::resolve_post_for_apply( 82 );

		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( 82, $post->ID );
	}

	// ---------------------------------------------------------------------
	// Lock-aware exclusion (collection end of the fail-closed pair).
	// ---------------------------------------------------------------------

	public function test_locked_container_children_are_excluded_from_targets_and_anchors(): void {
		$content = '<!-- wp:group {"templateLock":"all"} --><div class="wp-block-group">'
			. $this->paragraph( 'Frozen' )
			. '</div><!-- /wp:group -->'
			. $this->paragraph( 'Free' );
		$this->seed_post( 90, $content );

		$result = ServerCollector::for_post_blocks( 90 );

		$this->assertIsArray( $result );

		$paths = array_map(
			static fn( array $target ): string => implode( '.', $target['path'] ),
			$result['operationTargets']
		);

		$this->assertContains( '0', $paths, 'The locked container itself remains visible.' );
		$this->assertNotContains( '0.0', $paths, 'Children of a templateLock:all container are excluded.' );
		$this->assertContains( '1', $paths );

		foreach ( $result['insertionAnchors'] as $anchor ) {
			if ( isset( $anchor['targetPath'] ) ) {
				$this->assertNotSame( [ 0, 0 ], $anchor['targetPath'], 'No anchors inside a locked container.' );
			}
		}
	}

	public function test_block_with_own_lock_gets_no_remove_or_replace_operations(): void {
		$content = '<!-- wp:paragraph {"lock":{"remove":true,"move":false}} --><p>Pinned</p><!-- /wp:paragraph -->'
			. $this->paragraph( 'Free' );
		$this->seed_post( 91, $content );

		$result = ServerCollector::for_post_blocks( 91 );

		$this->assertIsArray( $result );

		$locked = null;
		$free   = null;

		foreach ( $result['operationTargets'] as $target ) {
			if ( [ 0 ] === $target['path'] ) {
				$locked = $target;
			}
			if ( [ 1 ] === $target['path'] ) {
				$free = $target;
			}
		}

		$this->assertIsArray( $locked );
		$this->assertSame( [], $locked['allowedOperations'], 'A locked block is not removable or replaceable.' );
		$this->assertTrue( $locked['locked']['remove'] );
		$this->assertContains( 'before_block_path', $locked['allowedInsertions'], 'Siblings may still be inserted around it.' );

		$this->assertIsArray( $free );
		$this->assertContains( 'remove_block', $free['allowedOperations'] );
		$this->assertContains( 'replace_block_with_pattern', $free['allowedOperations'] );
	}

	public function test_content_only_container_children_are_excluded(): void {
		$content = '<!-- wp:group {"templateLock":"contentOnly"} --><div class="wp-block-group">'
			. $this->paragraph( 'Content-only child' )
			. '</div><!-- /wp:group -->';
		$this->seed_post( 92, $content );

		$result = ServerCollector::for_post_blocks( 92 );

		$this->assertIsArray( $result );

		$paths = array_map(
			static fn( array $target ): string => implode( '.', $target['path'] ),
			$result['operationTargets']
		);

		$this->assertContains( '0', $paths );
		$this->assertNotContains( '0.0', $paths, 'Children of a contentOnly container are excluded.' );
	}
}
