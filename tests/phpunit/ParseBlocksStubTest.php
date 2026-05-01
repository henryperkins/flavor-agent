<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use PHPUnit\Framework\TestCase;

final class ParseBlocksStubTest extends TestCase {

	public function test_empty_string_returns_empty_array(): void {
		$this->assertSame( [], parse_blocks( '' ) );
	}

	public function test_plain_text_returns_single_freeform_block(): void {
		$blocks = parse_blocks( 'Hello world' );

		$this->assertCount( 1, $blocks );
		$this->assertNull( $blocks[0]['blockName'] );
		$this->assertSame( 'Hello world', $blocks[0]['innerHTML'] );
		$this->assertSame( [ 'Hello world' ], $blocks[0]['innerContent'] );
		$this->assertSame( [], $blocks[0]['innerBlocks'] );
		$this->assertSame( [], $blocks[0]['attrs'] );
	}

	public function test_freeform_regions_are_preserved_around_blocks(): void {
		$blocks = parse_blocks(
			'Intro<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->Middle'
			. '<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->Trailing'
		);

		$this->assertCount( 5, $blocks );
		$this->assertNull( $blocks[0]['blockName'] );
		$this->assertSame( 'Intro', $blocks[0]['innerHTML'] );
		$this->assertSame( 'core/paragraph', $blocks[1]['blockName'] );
		$this->assertNull( $blocks[2]['blockName'] );
		$this->assertSame( 'Middle', $blocks[2]['innerHTML'] );
		$this->assertSame( 'core/paragraph', $blocks[3]['blockName'] );
		$this->assertNull( $blocks[4]['blockName'] );
		$this->assertSame( 'Trailing', $blocks[4]['innerHTML'] );
	}

	public function test_nested_blocks_split_inner_content_with_nulls(): void {
		$content = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';

		$blocks = parse_blocks( $content );

		$this->assertCount( 1, $blocks );
		$group = $blocks[0];
		$this->assertSame( 'core/group', $group['blockName'] );
		$this->assertCount( 2, $group['innerBlocks'] );
		$this->assertSame( 'core/heading', $group['innerBlocks'][0]['blockName'] );
		$this->assertSame( 'core/paragraph', $group['innerBlocks'][1]['blockName'] );
		$this->assertSame( '<h2>Title</h2>', $group['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( '<p>Body</p>', $group['innerBlocks'][1]['innerHTML'] );

		$null_count = count( array_filter( $group['innerContent'], static fn ( $chunk ) => null === $chunk ) );
		$this->assertSame( 2, $null_count );
		$this->assertStringNotContainsString( '<!-- wp:', $group['innerHTML'] );
		$this->assertStringNotContainsString( '<!-- /wp:', $group['innerHTML'] );
	}

	public function test_self_closing_same_name_child_does_not_increase_parent_depth(): void {
		$blocks = parse_blocks(
			'<!-- wp:group --><div><!-- wp:group /--></div><!-- /wp:group -->'
		);

		$this->assertCount( 1, $blocks );
		$group = $blocks[0];
		$this->assertSame( 'core/group', $group['blockName'] );
		$this->assertSame( '<div></div>', $group['innerHTML'] );
		$this->assertCount( 1, $group['innerBlocks'] );
		$this->assertSame( 'core/group', $group['innerBlocks'][0]['blockName'] );
		$this->assertSame( [ '<div>', null, '</div>' ], $group['innerContent'] );
	}

	public function test_self_closing_block_has_empty_inner(): void {
		$blocks = parse_blocks( '<!-- wp:post-content /-->' );

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'core/post-content', $blocks[0]['blockName'] );
		$this->assertSame( '', $blocks[0]['innerHTML'] );
		$this->assertSame( [], $blocks[0]['innerContent'] );
		$this->assertSame( [], $blocks[0]['innerBlocks'] );
	}

	public function test_attrs_are_decoded(): void {
		$blocks = parse_blocks( '<!-- wp:image {"id":42,"sizeSlug":"large"} --><figure>img</figure><!-- /wp:image -->' );

		$this->assertCount( 1, $blocks );
		$this->assertSame(
			[
				'id'       => 42,
				'sizeSlug' => 'large',
			],
			$blocks[0]['attrs']
		);
	}
}
