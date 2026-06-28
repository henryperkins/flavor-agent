<?php
declare(strict_types=1);

use FlavorAgent\Apply\BlockTreeMutator;
use PHPUnit\Framework\TestCase;

final class BlockTreeMutatorTest extends TestCase {

	private function nested(): array {
		// <!-- wp:group --> wrapping a heading and a paragraph.
		return parse_blocks(
			'<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
		);
	}

	public function test_resolve_returns_block_at_path(): void {
		$block = BlockTreeMutator::resolve( $this->nested(), [ 0, 1 ] );
		$this->assertSame( 'core/paragraph', $block['blockName'] );
	}

	public function test_remove_nested_block_round_trips_without_orphan_markers(): void {
		$next = BlockTreeMutator::remove( $this->nested(), [ 0, 0 ] ); // remove heading
		$html = serialize_blocks( $next );
		$this->assertStringNotContainsString( 'wp:heading', $html );
		$this->assertStringContainsString( 'wp:paragraph', $html );
		// Parent group still has exactly one inner block, markers intact.
		$this->assertCount( 1, BlockTreeMutator::resolve( $next, [ 0 ] )['innerBlocks'] );
		$this->assertSame( 1, self::count_nulls( BlockTreeMutator::resolve( $next, [ 0 ] )['innerContent'] ) );
	}

	public function test_replace_nested_block_with_multiple_blocks_round_trips(): void {
		$replacement = parse_blocks( '<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->' );
		$next        = BlockTreeMutator::replace( $this->nested(), [ 0, 0 ], $replacement );
		$group       = BlockTreeMutator::resolve( $next, [ 0 ] );
		$this->assertSame( 'core/separator', $group['innerBlocks'][0]['blockName'] );
		$this->assertSame( 2, self::count_nulls( $group['innerContent'] ) ); // separator + paragraph
		$this->assertStringContainsString( 'wp:separator', serialize_blocks( $next ) );
	}

	public function test_insert_at_top_level_index(): void {
		$blocks = parse_blocks( '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->' );
		$new    = parse_blocks( '<!-- wp:heading --><h2>New</h2><!-- /wp:heading -->' );
		$next   = BlockTreeMutator::insert( $blocks, [], 0, $new );
		$this->assertSame( 'core/heading', $next[0]['blockName'] );
		$this->assertSame( 'core/paragraph', $next[1]['blockName'] );
	}

	/**
	 * R6: nested insert exercises splice_inner_content (the branch insert_pattern
	 * relies on), which the brief's top-level insert test bypasses entirely.
	 * Insert a heading at index 0 of a core/group wrapping a single paragraph.
	 */
	public function test_insert_nested_into_group_keeps_markers_and_round_trips(): void {
		$blocks = parse_blocks(
			'<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
		);
		$new    = parse_blocks( '<!-- wp:heading --><h2>New</h2><!-- /wp:heading -->' );
		$next   = BlockTreeMutator::insert( $blocks, [ 0 ], 0, $new );

		$group = BlockTreeMutator::resolve( $next, [ 0 ] );
		// Heading lands before the original paragraph.
		$this->assertSame( 'core/heading', $group['innerBlocks'][0]['blockName'] );
		$this->assertSame( 'core/paragraph', $group['innerBlocks'][1]['blockName'] );
		// One null marker per inner block, markers intact.
		$this->assertSame( 2, self::count_nulls( $group['innerContent'] ) );
		// Round-trips with both blocks present.
		$html = serialize_blocks( $next );
		$this->assertStringContainsString( 'wp:heading', $html );
		$this->assertStringContainsString( 'wp:paragraph', $html );
	}

	/**
	 * End-insert into a nested wrapper (Task 5's apply_insert after_block_path on a
	 * group's last child: parent [0], index === childCount). The new marker(s) must
	 * land INSIDE the wrapper, after the last child marker but before the closing
	 * </div> literal — not appended past it (which would serialize outside the group).
	 */
	public function test_insert_at_end_of_nested_wrapper_stays_inside_wrapper(): void {
		$blocks    = $this->nested(); // group wrapping heading + paragraph (2 children).
		$separator = parse_blocks( '<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->' );
		$next      = BlockTreeMutator::insert( $blocks, [ 0 ], 2, $separator );

		$group = BlockTreeMutator::resolve( $next, [ 0 ] );
		$this->assertSame( 'core/heading', $group['innerBlocks'][0]['blockName'] );
		$this->assertSame( 'core/paragraph', $group['innerBlocks'][1]['blockName'] );
		$this->assertSame( 'core/separator', $group['innerBlocks'][2]['blockName'] );
		// One marker per child, none orphaned past the trailing literal.
		$this->assertSame( 3, self::count_nulls( $group['innerContent'] ) );

		// The separator serializes BEFORE the group's closing </div> (inside it).
		$html         = serialize_blocks( $next );
		$separator_at = strpos( $html, 'wp:separator' );
		$closing_at   = strpos( $html, '</div>' );
		$this->assertNotFalse( $separator_at );
		$this->assertNotFalse( $closing_at );
		$this->assertLessThan( $closing_at, $separator_at );
	}

	private static function count_nulls( array $inner_content ): int {
		return count( array_filter( $inner_content, static fn ( $chunk ) => null === $chunk ) );
	}
}
