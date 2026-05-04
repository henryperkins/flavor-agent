<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\NavigationParser;
use PHPUnit\Framework\TestCase;

final class NavigationParserTest extends TestCase {

	private NavigationParser $parser;

	protected function setUp(): void {
		parent::setUp();
		$this->parser = new NavigationParser();
	}

	public function test_find_navigation_block_returns_top_level_match(): void {
		$blocks = [
			[
				'blockName'   => 'core/group',
				'innerBlocks' => [],
			],
			[
				'blockName' => 'core/navigation',
				'attrs'     => [ 'ref' => 9 ],
			],
		];

		$match = $this->parser->find_navigation_block( $blocks );

		$this->assertIsArray( $match );
		$this->assertSame( 'core/navigation', $match['blockName'] );
		$this->assertSame( 9, $match['attrs']['ref'] );
	}

	public function test_find_navigation_block_recurses_into_inner_blocks(): void {
		$blocks = [
			[
				'blockName'   => 'core/group',
				'innerBlocks' => [
					[
						'blockName'   => 'core/columns',
						'innerBlocks' => [
							[
								'blockName' => 'core/navigation',
								'attrs'     => [ 'ref' => 1 ],
							],
						],
					],
				],
			],
		];

		$match = $this->parser->find_navigation_block( $blocks );

		$this->assertIsArray( $match );
		$this->assertSame( 1, $match['attrs']['ref'] );
	}

	public function test_find_navigation_block_returns_null_when_absent(): void {
		$this->assertNull(
			$this->parser->find_navigation_block(
				[
					[ 'blockName' => 'core/paragraph' ],
					'not-an-array',
					[
						'blockName'   => 'core/group',
						'innerBlocks' => [],
					],
				]
			)
		);
	}

	public function test_parse_navigation_source_returns_empty_shape_for_empty_content(): void {
		$result = $this->parser->parse_navigation_source( '' );

		$this->assertSame(
			[
				'attrs'              => [],
				'inner'              => [],
				'hasNavigationBlock' => false,
				'hasStructure'       => false,
			],
			$result
		);
	}

	public function test_parse_navigation_source_extracts_navigation_block_with_inner(): void {
		$content  = '<!-- wp:navigation {"ref":42} -->';
		$content .= '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->';
		$content .= '<!-- /wp:navigation -->';

		$result = $this->parser->parse_navigation_source( $content );

		$this->assertTrue( $result['hasNavigationBlock'] );
		$this->assertTrue( $result['hasStructure'] );
		$this->assertSame( 42, $result['attrs']['ref'] );
		$this->assertCount( 1, $result['inner'] );
		$this->assertSame( 'core/navigation-link', $result['inner'][0]['blockName'] );
	}

	public function test_parse_navigation_source_marks_empty_navigation_wrapper_as_having_structure(): void {
		// An explicit closing comment alone is enough to count as structure
		// (even if the inner block list is empty).
		$content = '<!-- wp:navigation -->' . '<!-- /wp:navigation -->';

		$result = $this->parser->parse_navigation_source( $content );

		$this->assertTrue( $result['hasNavigationBlock'] );
		$this->assertTrue( $result['hasStructure'] );
		$this->assertSame( [], $result['inner'] );
	}

	public function test_parse_navigation_source_falls_back_to_top_level_blocks_when_no_navigation_wrapper(): void {
		$content = '<!-- wp:navigation-link {"label":"Top","url":"/top"} /-->';

		$result = $this->parser->parse_navigation_source( $content );

		$this->assertFalse( $result['hasNavigationBlock'] );
		$this->assertTrue( $result['hasStructure'] );
		$this->assertCount( 1, $result['inner'] );
	}

	public function test_extract_menu_items_classifies_supported_block_types(): void {
		$blocks = [
			[
				'blockName' => 'core/navigation-link',
				'attrs'     => [
					'label' => 'About',
					'url'   => '/about',
				],
			],
			[
				'blockName'   => 'core/navigation-submenu',
				'attrs'       => [
					'label' => 'Services',
					'url'   => '/services',
				],
				'innerBlocks' => [
					[
						'blockName' => 'core/navigation-link',
						'attrs'     => [
							'label' => 'Consulting',
							'url'   => '/consulting',
						],
					],
				],
			],
			[
				'blockName' => 'core/page-list',
				'attrs'     => [],
			],
			[
				'blockName' => 'core/home-link',
				'attrs'     => [],
			],
			[
				'blockName' => 'core/spacer',
				'attrs'     => [],
			],
		];

		$items = $this->parser->extract_menu_items( $blocks );

		$this->assertSame( 'navigation-link', $items[0]['type'] );
		$this->assertSame( 'About', $items[0]['label'] );
		$this->assertSame( [ 0 ], $items[0]['path'] );
		$this->assertSame( 0, $items[0]['depth'] );

		$this->assertSame( 'navigation-submenu', $items[1]['type'] );
		$this->assertArrayHasKey( 'children', $items[1] );
		$this->assertSame( 1, $items[1]['children'][0]['depth'] );
		$this->assertSame( [ 1, 0 ], $items[1]['children'][0]['path'] );

		$this->assertSame( 'page-list', $items[2]['type'] );
		$this->assertSame( 'Page List (auto-generated)', $items[2]['label'] );
		$this->assertSame( 'home-link', $items[3]['type'] );
		$this->assertSame( 'Home', $items[3]['label'] );
		$this->assertSame( 'spacer', $items[4]['type'] );
		$this->assertSame( '', $items[4]['label'] );
	}

	public function test_extract_menu_items_uses_explicit_home_link_label_when_provided(): void {
		$items = $this->parser->extract_menu_items(
			[
				[
					'blockName' => 'core/home-link',
					'attrs'     => [ 'label' => 'Start' ],
				],
			]
		);

		$this->assertSame( 'Start', $items[0]['label'] );
	}

	public function test_extract_menu_items_skips_blocks_without_a_name(): void {
		$items = $this->parser->extract_menu_items(
			[
				[
					'blockName' => null,
					'attrs'     => [],
				],
				[
					'blockName' => '',
					'attrs'     => [],
				],
				[
					'blockName' => 'core/navigation-link',
					'attrs'     => [ 'label' => 'A' ],
				],
			]
		);

		$this->assertCount( 1, $items );
		$this->assertSame( 'A', $items[0]['label'] );
	}

	public function test_build_target_inventory_flattens_items_and_skips_invalid_paths(): void {
		$items = [
			[
				'type'  => 'navigation-link',
				'label' => 'A',
				'path'  => [ 0 ],
				'depth' => 0,
			],
			[
				'type'     => 'navigation-submenu',
				'label'    => 'B',
				'path'     => [ 1 ],
				'depth'    => 0,
				'children' => [
					[
						'type'  => 'navigation-link',
						'label' => 'B1',
						'path'  => [ 1, 0 ],
						'depth' => 1,
					],
				],
			],
			[
				'type'  => 'broken',
				'label' => 'X',
				'path'  => 'not-an-array',
			],
			[
				'type'  => 'broken',
				'label' => 'X',
				'path'  => [],
			],
			[
				'type'  => 'broken',
				'label' => 'X',
				'path'  => [ 'not-numeric' ],
			],
		];

		$inventory = $this->parser->build_target_inventory( $items );

		$this->assertCount( 3, $inventory );
		$this->assertSame( [ 0 ], $inventory[0]['path'] );
		$this->assertSame( 'A', $inventory[0]['label'] );
		$this->assertSame( 'navigation-link', $inventory[0]['type'] );
		$this->assertSame( 0, $inventory[0]['depth'] );
		$this->assertSame( [ 1, 0 ], $inventory[2]['path'] );
		$this->assertSame( 1, $inventory[2]['depth'] );
	}

	public function test_build_target_inventory_clamps_negative_depth_and_path_segments(): void {
		$inventory = $this->parser->build_target_inventory(
			[
				[
					'type'  => 'navigation-link',
					'label' => 'A',
					'path'  => [ -3 ],
					'depth' => -7,
				],
			]
		);

		$this->assertSame( [ 0 ], $inventory[0]['path'] );
		$this->assertSame( 0, $inventory[0]['depth'] );
	}

	public function test_collect_navigation_attributes_applies_documented_defaults(): void {
		$this->assertSame(
			[
				'overlayMenu'         => 'mobile',
				'hasIcon'             => false,
				'icon'                => 'handle',
				'openSubmenusOnClick' => false,
				'showSubmenuIcon'     => true,
				'maxNestingLevel'     => 0,
			],
			$this->parser->collect_navigation_attributes( [] )
		);
	}

	public function test_collect_navigation_attributes_copies_through_explicit_values(): void {
		$result = $this->parser->collect_navigation_attributes(
			[
				'overlayMenu'         => 'always',
				'hasIcon'             => true,
				'icon'                => 'menu',
				'openSubmenusOnClick' => true,
				'showSubmenuIcon'     => false,
				'maxNestingLevel'     => '3',
			]
		);

		$this->assertSame( 'always', $result['overlayMenu'] );
		$this->assertTrue( $result['hasIcon'] );
		$this->assertSame( 'menu', $result['icon'] );
		$this->assertTrue( $result['openSubmenusOnClick'] );
		$this->assertFalse( $result['showSubmenuIcon'] );
		$this->assertSame( 3, $result['maxNestingLevel'] );
	}

	public function test_count_menu_items_recursive_includes_all_nested_descendants(): void {
		$items = [
			[ 'type' => 'navigation-link' ],
			[
				'type'     => 'navigation-submenu',
				'children' => [
					[ 'type' => 'navigation-link' ],
					[
						'type'     => 'navigation-submenu',
						'children' => [
							[ 'type' => 'navigation-link' ],
						],
					],
				],
			],
		];

		$this->assertSame( 5, $this->parser->count_menu_items_recursive( $items ) );
	}

	public function test_measure_menu_depth_returns_zero_for_empty_menu(): void {
		$this->assertSame( 0, $this->parser->measure_menu_depth( [] ) );
	}

	public function test_measure_menu_depth_walks_to_deepest_branch(): void {
		$items = [
			[ 'type' => 'navigation-link' ],
			[
				'type'     => 'navigation-submenu',
				'children' => [
					[
						'type'     => 'navigation-submenu',
						'children' => [ [ 'type' => 'navigation-link' ] ],
					],
				],
			],
		];

		$this->assertSame( 3, $this->parser->measure_menu_depth( $items ) );
	}

	public function test_collect_navigation_structure_summary_aggregates_top_level_metadata(): void {
		$items = [];
		for ( $i = 0; $i < 8; $i++ ) {
			$items[] = [
				'type'  => 'navigation-link',
				'label' => 'Item ' . $i,
			];
		}
		$items[3] = [
			'type'     => 'navigation-submenu',
			'label'    => 'Services',
			'children' => [
				[
					'type'  => 'navigation-link',
					'label' => 'Consulting',
				],
				[
					'type'  => 'page-list',
					'label' => 'Pages',
				],
			],
		];
		$items[5] = [
			'type'  => 'social-link',
			'label' => 'Twitter',
		];

		$summary = $this->parser->collect_navigation_structure_summary( $items );

		$this->assertSame( 8, $summary['topLevelCount'] );
		$this->assertSame( 1, $summary['submenuCount'] );
		$this->assertTrue( $summary['hasPageList'] );
		$this->assertSame( [ 'social-link' ], $summary['nonLinkTypes'] );
		// Only the first six top-level labels are captured.
		$this->assertCount( 6, $summary['topLevelLabels'] );
		$this->assertSame( 'Item 0', $summary['topLevelLabels'][0] );
	}

	public function test_collect_navigation_structure_summary_dedupes_non_link_types(): void {
		$summary = $this->parser->collect_navigation_structure_summary(
			[
				[
					'type'  => 'social-link',
					'label' => 'A',
				],
				[
					'type'  => 'social-link',
					'label' => 'B',
				],
				[
					'type'  => 'spacer',
					'label' => '',
				],
			]
		);

		$this->assertSame( [ 'social-link', 'spacer' ], $summary['nonLinkTypes'] );
	}

	public function test_blocks_reference_navigation_matches_top_level_ref(): void {
		$blocks = [
			[
				'blockName' => 'core/navigation',
				'attrs'     => [ 'ref' => 7 ],
			],
		];

		$this->assertTrue( $this->parser->blocks_reference_navigation( $blocks, 7 ) );
		$this->assertFalse( $this->parser->blocks_reference_navigation( $blocks, 8 ) );
	}

	public function test_blocks_reference_navigation_recurses_into_inner_blocks(): void {
		$blocks = [
			[
				'blockName'   => 'core/group',
				'innerBlocks' => [
					[
						'blockName' => 'core/navigation',
						'attrs'     => [ 'ref' => 4 ],
					],
				],
			],
		];

		$this->assertTrue( $this->parser->blocks_reference_navigation( $blocks, 4 ) );
	}

	public function test_blocks_reference_navigation_ignores_navigation_blocks_without_ref(): void {
		$blocks = [
			[
				'blockName' => 'core/navigation',
				'attrs'     => [],
			],
		];

		$this->assertFalse( $this->parser->blocks_reference_navigation( $blocks, 1 ) );
	}
}
