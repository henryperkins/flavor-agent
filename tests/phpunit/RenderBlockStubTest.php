<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class RenderBlockStubTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_freeform_block_returns_inner_html(): void {
		$block = [
			'blockName'    => null,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => 'Hello',
			'innerContent' => [ 'Hello' ],
		];

		$this->assertSame( 'Hello', render_block( $block ) );
	}

	public function test_static_parent_renders_inner_blocks_at_null_positions(): void {
		$inner_paragraph = [
			'blockName'    => 'core/paragraph',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '<p>Inside</p>',
			'innerContent' => [ '<p>Inside</p>' ],
		];

		$group = [
			'blockName'    => 'core/group',
			'attrs'        => [],
			'innerBlocks'  => [ $inner_paragraph ],
			'innerHTML'    => '<div></div>',
			'innerContent' => [ '<div>', null, '</div>' ],
		];

		$this->assertSame( '<div><p>Inside</p></div>', render_block( $group ) );
	}

	public function test_dynamic_block_render_callback_receives_attrs_and_inner(): void {
		register_block_type(
			'flavor-agent-test/echo-attrs',
			[
				'render_callback' => static fn ( array $attrs, string $inner ): string => sprintf(
					'<echo data-label="%s">%s</echo>',
					(string) ( $attrs['label'] ?? '' ),
					$inner
				),
			]
		);

		$block = [
			'blockName'    => 'flavor-agent-test/echo-attrs',
			'attrs'        => [ 'label' => 'hi' ],
			'innerBlocks'  => [],
			'innerHTML'    => 'inner',
			'innerContent' => [ 'inner' ],
		];

		$this->assertSame( '<echo data-label="hi">inner</echo>', render_block( $block ) );
	}

	public function test_dynamic_block_inside_static_group_executes_callback(): void {
		register_block_type(
			'flavor-agent-test/marker',
			[
				'render_callback' => static fn (): string => '<marker>HIT</marker>',
			]
		);

		$inner_dynamic = [
			'blockName'    => 'flavor-agent-test/marker',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$group = [
			'blockName'    => 'core/group',
			'attrs'        => [],
			'innerBlocks'  => [ $inner_dynamic ],
			'innerHTML'    => '<div></div>',
			'innerContent' => [ '<div>', null, '</div>' ],
		];

		$this->assertSame( '<div><marker>HIT</marker></div>', render_block( $group ) );
	}
}
