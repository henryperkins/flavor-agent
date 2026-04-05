<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ServerCollectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();

		WordPressTestState::$block_templates = [
			'wp_template'      => [
				(object) [
					'id'      => 'theme//home',
					'slug'    => 'home',
					'title'   => 'Home',
					'content' => '<!-- wp:group --><div>Home</div><!-- /wp:group -->',
				],
			],
			'wp_template_part' => [
				(object) [
					'slug'    => 'header',
					'title'   => 'Header',
					'area'    => 'header',
					'content' => '<!-- wp:group -->Header<!-- /wp:group -->',
				],
				(object) [
					'slug'    => 'footer',
					'title'   => 'Footer',
					'area'    => 'footer',
					'content' => '<!-- wp:group -->Footer<!-- /wp:group -->',
				],
			],
		];
	}

	private function register_pattern( string $name, array $properties ): void {
		if ( ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
			$this->markTestSkipped( 'WP_Block_Patterns_Registry is not available.' );
		}
		\WP_Block_Patterns_Registry::get_instance()->register( $name, $properties );
	}

	public function test_for_template_parts_can_skip_content_when_only_metadata_is_needed(): void {
		$result = ServerCollector::for_template_parts( null, false );

		$this->assertSame(
			[
				[
					'slug'  => 'header',
					'title' => 'Header',
					'area'  => 'header',
				],
				[
					'slug'  => 'footer',
					'title' => 'Footer',
					'area'  => 'footer',
				],
			],
			$result
		);
	}

	public function test_for_template_part_areas_returns_slug_to_area_lookup(): void {
		$this->assertSame(
			[
				'header' => 'header',
				'footer' => 'footer',
			],
			ServerCollector::for_template_part_areas()
		);
	}

	public function test_for_tokens_exposes_diagnostics(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#ff5500',
					],
				],
			],
		];

		$tokens = ServerCollector::for_tokens();

		$this->assertSame(
			[
				'source'      => 'server',
				'settingsKey' => 'wp_get_global_settings',
				'reason'      => 'server-global-settings',
			],
			$tokens['diagnostics']
		);
	}

	public function test_introspect_block_type_supports_content_role_and_attribute_role(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/content-block',
			[
				'title'      => 'Content Block',
				'supports'   => [
					'contentRole' => true,
				],
				'attributes' => [
					'stableContent' => [
						'type' => 'string',
						'role' => 'content',
					],
					'className'     => [
						'type' => 'string',
					],
				],
			]
		);

		$manifest = ServerCollector::introspect_block_type( 'plugin/content-block' );

		$this->assertTrue( $manifest['supportsContentRole'] );
		$this->assertSame( 'content', $manifest['contentAttributes']['stableContent']['role'] );
		$this->assertArrayNotHasKey( 'stableContent', $manifest['configAttributes'] );
	}

	public function test_introspect_block_type_maps_custom_css_support_to_advanced_panel(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/custom-css-block',
			[
				'title'    => 'Custom CSS Block',
				'supports' => [
					'customCSS' => true,
				],
			]
		);

		$manifest = ServerCollector::introspect_block_type( 'plugin/custom-css-block' );

		$this->assertSame( [ 'customCSS' ], $manifest['inspectorPanels']['advanced'] );
	}

	public function test_introspect_block_type_maps_list_view_support_to_list_panel(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/list-view-block',
			[
				'title'    => 'List View Block',
				'supports' => [
					'listView' => true,
				],
			]
		);

		$manifest = ServerCollector::introspect_block_type( 'plugin/list-view-block' );

		$this->assertSame( [ 'listView' ], $manifest['inspectorPanels']['list'] );
	}

	public function test_introspect_block_type_exposes_bindings_panel_for_bindable_blocks(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'core/paragraph',
			[
				'title'      => 'Paragraph',
				'attributes' => [
					'content' => [
						'type' => 'string',
						'role' => 'content',
					],
				],
			]
		);

		$manifest = ServerCollector::introspect_block_type( 'core/paragraph' );

		$this->assertSame( [ 'content' ], $manifest['bindableAttributes'] );
		$this->assertSame( [ 'content' ], $manifest['inspectorPanels']['bindings'] );
	}

	public function test_introspect_block_type_maps_new_typography_supports_to_typography_panel(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/typography-block',
			[
				'title'    => 'Typography Block',
				'supports' => [
					'typography' => [
						'fitText'    => true,
						'textIndent' => true,
					],
				],
			]
		);

		$manifest = ServerCollector::introspect_block_type( 'plugin/typography-block' );

		$this->assertSame(
			[ 'typography.fitText', 'typography.textIndent' ],
			$manifest['inspectorPanels']['typography']
		);
	}

	public function test_introspect_block_type_adds_general_panel_for_meaningful_config_attributes(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/configurable-block',
			[
				'title'      => 'Configurable Block',
				'attributes' => [
					'height'    => [
						'type' => 'string',
					],
					'metadata'  => [
						'type' => 'object',
					],
					'className' => [
						'type' => 'string',
					],
					'style'     => [
						'type' => 'object',
					],
				],
			]
		);

		$manifest = ServerCollector::introspect_block_type( 'plugin/configurable-block' );

		$this->assertSame( [ 'height' ], $manifest['inspectorPanels']['general'] );
	}

	public function test_for_template_limits_candidate_patterns_after_typed_then_generic_ordering(): void {
		for ( $index = 1; $index <= 20; $index++ ) {
			$this->register_pattern(
				"plugin/typed-{$index}",
				[
					'title'         => "Typed {$index}",
					'templateTypes' => [ 'home' ],
					'content'       => "<!-- wp:paragraph --><p>Typed {$index}</p><!-- /wp:paragraph -->",
				]
			);
		}

		for ( $index = 1; $index <= 15; $index++ ) {
			$this->register_pattern(
				"plugin/generic-{$index}",
				[
					'title'         => "Generic {$index}",
					'templateTypes' => [],
					'content'       => "<!-- wp:paragraph --><p>Generic {$index}</p><!-- /wp:paragraph -->",
				]
			);
		}

		$result   = ServerCollector::for_template( 'theme//home', 'home' );
		$patterns = $result['patterns'];

		$this->assertCount( 30, $patterns );
		$this->assertSame(
			array_map(
				static fn ( int $index ): string => "plugin/typed-{$index}",
				range( 1, 20 )
			),
			array_column( array_slice( $patterns, 0, 20 ), 'name' )
		);
		$this->assertSame(
			array_map(
				static fn ( int $index ): string => "plugin/generic-{$index}",
				range( 1, 10 )
			),
			array_column( array_slice( $patterns, 20 ), 'name' )
		);
		$this->assertSame(
			array_fill( 0, 20, 'typed' ),
			array_column( array_slice( $patterns, 0, 20 ), 'matchType' )
		);
		$this->assertSame(
			array_fill( 0, 10, 'generic' ),
			array_column( array_slice( $patterns, 20 ), 'matchType' )
		);

		foreach ( $patterns as $pattern ) {
			$this->assertArrayNotHasKey( 'content', $pattern );
		}
	}

	public function test_for_patterns_collects_pattern_override_metadata_from_bindings(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/card',
			[
				'title' => 'Card',
			]
		);

		$this->register_pattern(
			'theme/override-card',
			[
				'title'   => 'Override Card',
				'content' => '<!-- wp:plugin/card {"metadata":{"bindings":{"__default":{"source":"core/pattern-overrides"},"title":{"source":"core/pattern-overrides"},"eyebrow":{"source":"core/pattern-overrides"}}}} /-->',
			]
		);

		$patterns          = ServerCollector::for_patterns();
		$override_pattern  = $patterns[0];
		$override_metadata = $override_pattern['patternOverrides'] ?? [];

		$this->assertTrue( $override_metadata['hasOverrides'] ?? false );
		$this->assertSame( 1, $override_metadata['blockCount'] ?? null );
		$this->assertSame( [ 'plugin/card' ], $override_metadata['blockNames'] ?? null );
		$this->assertSame(
			[ 'plugin/card' => [] ],
			$override_metadata['bindableAttributes'] ?? null
		);
		$this->assertSame(
			[ 'plugin/card' => [ 'eyebrow', 'title' ] ],
			$override_metadata['unsupportedAttributes'] ?? null
		);
		$this->assertTrue( $override_metadata['usesDefaultBinding'] ?? false );
	}

	public function test_for_patterns_marks_supported_override_attributes_for_bindable_blocks(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'core/paragraph',
			[
				'title'      => 'Paragraph',
				'attributes' => [
					'content' => [
						'type' => 'string',
						'role' => 'content',
					],
				],
			]
		);

		$this->register_pattern(
			'theme/override-paragraph',
			[
				'title'   => 'Override Paragraph',
				'content' => '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/pattern-overrides"}}}} --><p>Hello</p><!-- /wp:paragraph -->',
			]
		);

		$patterns          = ServerCollector::for_patterns();
		$override_metadata = $patterns[0]['patternOverrides'] ?? [];

		$this->assertSame(
			[ 'core/paragraph' => [ 'content' ] ],
			$override_metadata['bindableAttributes'] ?? null
		);
		$this->assertSame(
			[ 'core/paragraph' => [ 'content' ] ],
			$override_metadata['overrideAttributes'] ?? null
		);
		$this->assertSame( [], $override_metadata['unsupportedAttributes'] ?? null );
	}

	public function test_for_template_returns_top_level_structure_and_insertion_anchors(): void {
		WordPressTestState::$block_templates['wp_template'][0]->content =
			'<!-- wp:group {"tagName":"main"} --><div>Hero</div><!-- /wp:group -->'
			. '<!-- wp:template-part {"slug":"header","area":"header"} /-->';

		$result = ServerCollector::for_template( 'theme//home', 'home' );

		$this->assertSame(
			[
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'label'      => 'Group',
					'attributes' => [
						'tagName' => 'main',
					],
					'childCount' => 0,
				],
				[
					'path'       => [ 1 ],
					'name'       => 'core/template-part',
					'label'      => 'header template part (header)',
					'attributes' => [
						'slug' => 'header',
						'area' => 'header',
					],
					'childCount' => 0,
					'slot'       => [
						'slug'    => 'header',
						'area'    => 'header',
						'isEmpty' => false,
					],
				],
			],
			$result['topLevelBlockTree']
		);
		$this->assertSame(
			[
				[
					'placement' => 'start',
					'label'     => 'Start of template',
				],
				[
					'placement'  => 'before_block_path',
					'targetPath' => [ 0 ],
					'blockName'  => 'core/group',
					'label'      => 'Before Group',
				],
				[
					'placement'  => 'after_block_path',
					'targetPath' => [ 0 ],
					'blockName'  => 'core/group',
					'label'      => 'After Group',
				],
				[
					'placement'  => 'before_block_path',
					'targetPath' => [ 1 ],
					'blockName'  => 'core/template-part',
					'label'      => 'Before header template part (header)',
				],
				[
					'placement'  => 'after_block_path',
					'targetPath' => [ 1 ],
					'blockName'  => 'core/template-part',
					'label'      => 'After header template part (header)',
				],
				[
					'placement' => 'end',
					'label'     => 'End of template',
				],
			],
			$result['topLevelInsertionAnchors']
		);
		$this->assertSame( 2, $result['structureStats']['topLevelBlockCount'] );
		$this->assertSame( 'core/group', $result['structureStats']['firstTopLevelBlock'] );
		$this->assertSame( 'core/template-part', $result['structureStats']['lastTopLevelBlock'] );
		$this->assertTrue( $result['structureStats']['hasTemplateParts'] );
	}

	public function test_for_template_collects_current_pattern_overrides_and_viewport_visibility(): void {
		WordPressTestState::$block_templates['wp_template'][0]->content =
			'<!-- wp:group {"metadata":{"blockVisibility":{"viewport":{"mobile":false,"desktop":true}}}} -->'
			. '<div>'
			. '<!-- wp:heading {"metadata":{"bindings":{"content":{"source":"core/pattern-overrides"}}}} --><h2>Hello</h2><!-- /wp:heading -->'
			. '</div>'
			. '<!-- /wp:group -->';

		$result = ServerCollector::for_template( 'theme//home', 'home' );

		$this->assertSame(
			[
				'hasOverrides' => true,
				'blockCount'   => 1,
				'blockNames'   => [ 'core/heading' ],
				'blocks'       => [
					[
						'path'               => [ 0, 0 ],
						'name'               => 'core/heading',
						'label'              => 'Heading',
						'overrideAttributes' => [ 'content' ],
						'usesDefaultBinding' => false,
						'bindableAttributes' => [ 'content' ],
					],
				],
			],
			$result['currentPatternOverrides']
		);
		$this->assertSame(
			[
				'hasVisibilityRules' => true,
				'blockCount'         => 1,
				'blocks'             => [
					[
						'path'             => [ 0 ],
						'name'             => 'core/group',
						'label'            => 'Group',
						'hiddenViewports'  => [ 'mobile' ],
						'visibleViewports' => [ 'desktop' ],
					],
				],
			],
			$result['currentViewportVisibility']
		);
	}

	public function test_for_template_part_returns_summarized_block_tree_and_area_ranked_patterns(): void {
		WordPressTestState::$block_templates['wp_template_part'][0]->content =
			'<!-- wp:group {"tagName":"header","align":"wide"} -->'
			. '<!-- wp:site-logo /-->'
			. '<!-- wp:navigation {"overlayMenu":"mobile","maxNestingLevel":2} /-->'
			. '<!-- /wp:group -->';

		$this->register_pattern(
			'theme/header-utility',
			[
				'title'      => 'Header Utility Row',
				'categories' => [ 'header' ],
				'blockTypes' => [ 'core/template-part/header' ],
				'content'    => '<!-- wp:paragraph --><p>Header utility</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/footer-columns',
			[
				'title'      => 'Footer Columns',
				'categories' => [ 'footer' ],
				'blockTypes' => [ 'core/template-part/footer' ],
				'content'    => '<!-- wp:paragraph --><p>Footer columns</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/generic-stack',
			[
				'title'   => 'Generic Stack',
				'content' => '<!-- wp:paragraph --><p>Generic stack</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/home-hero',
			[
				'title'         => 'Home Hero',
				'templateTypes' => [ 'home' ],
				'content'       => '<!-- wp:paragraph --><p>Home hero</p><!-- /wp:paragraph -->',
			]
		);

		$result        = ServerCollector::for_template_part( 'theme//header' );
		$pattern_names = array_column( $result['patterns'], 'name' );

		$this->assertIsArray( $result );
		$this->assertSame( 'theme//header', $result['templatePartRef'] );
		$this->assertSame( 'header', $result['slug'] );
		$this->assertSame( 'header', $result['area'] );
		$this->assertSame( [ 'core/group' ], $result['topLevelBlocks'] );
		$this->assertSame(
			[
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'attributes' => [
						'tagName' => 'header',
						'align'   => 'wide',
					],
					'childCount' => 2,
					'children'   => [
						[
							'path'       => [ 0, 0 ],
							'name'       => 'core/site-logo',
							'attributes' => [],
							'childCount' => 0,
							'children'   => [],
						],
						[
							'path'       => [ 0, 1 ],
							'name'       => 'core/navigation',
							'attributes' => [
								'overlayMenu'     => 'mobile',
								'maxNestingLevel' => 2,
							],
							'childCount' => 0,
							'children'   => [],
						],
					],
				],
			],
			$result['blockTree']
		);
		$this->assertSame( 3, $result['structureStats']['blockCount'] );
		$this->assertSame( 2, $result['structureStats']['maxDepth'] );
		$this->assertTrue( $result['structureStats']['hasNavigation'] );
		$this->assertTrue( $result['structureStats']['containsLogo'] );
		$this->assertTrue( $result['structureStats']['hasSingleWrapperGroup'] );
		$this->assertSame(
			[
				[
					'path'              => [ 0 ],
					'name'              => 'core/group',
					'label'             => 'Group',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
					'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
				],
				[
					'path'              => [ 0, 0 ],
					'name'              => 'core/site-logo',
					'label'             => 'Site Logo',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
					'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
				],
				[
					'path'              => [ 0, 1 ],
					'name'              => 'core/navigation',
					'label'             => 'Navigation',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
					'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
				],
			],
			$result['operationTargets']
		);
		$this->assertSame(
			[
				[
					'placement' => 'start',
					'label'     => 'Start of template part',
				],
				[
					'placement' => 'end',
					'label'     => 'End of template part',
				],
				[
					'placement'  => 'before_block_path',
					'targetPath' => [ 0 ],
					'blockName'  => 'core/group',
					'label'      => 'Before Group',
				],
				[
					'placement'  => 'after_block_path',
					'targetPath' => [ 0 ],
					'blockName'  => 'core/group',
					'label'      => 'After Group',
				],
				[
					'placement'  => 'before_block_path',
					'targetPath' => [ 0, 0 ],
					'blockName'  => 'core/site-logo',
					'label'      => 'Before Site Logo',
				],
				[
					'placement'  => 'after_block_path',
					'targetPath' => [ 0, 0 ],
					'blockName'  => 'core/site-logo',
					'label'      => 'After Site Logo',
				],
				[
					'placement'  => 'before_block_path',
					'targetPath' => [ 0, 1 ],
					'blockName'  => 'core/navigation',
					'label'      => 'Before Navigation',
				],
				[
					'placement'  => 'after_block_path',
					'targetPath' => [ 0, 1 ],
					'blockName'  => 'core/navigation',
					'label'      => 'After Navigation',
				],
			],
			$result['insertionAnchors']
		);
		$this->assertSame(
			[
				'contentOnlyPaths' => [],
				'lockedPaths'      => [],
				'hasContentOnly'   => false,
				'hasLockedBlocks'  => false,
			],
			$result['structuralConstraints']
		);
		$this->assertSame( 'theme/header-utility', $result['patterns'][0]['name'] );
		$this->assertSame( 'area', $result['patterns'][0]['matchType'] );
		$this->assertArrayNotHasKey( 'content', $result['patterns'][0] );
		$this->assertContains( 'theme/generic-stack', $pattern_names );
		$this->assertNotContains( 'theme/home-hero', $pattern_names );
	}

	public function test_for_template_part_collects_current_pattern_override_metadata(): void {
		WordPressTestState::$block_templates['wp_template_part'][0]->content =
			'<!-- wp:group -->'
			. '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/pattern-overrides"}}}} --><p>Hello</p><!-- /wp:paragraph -->'
			. '<!-- /wp:group -->';

		$result = ServerCollector::for_template_part( 'theme//header' );

		$this->assertSame(
			[
				'hasOverrides' => true,
				'blockCount'   => 1,
				'blockNames'   => [ 'core/paragraph' ],
				'blocks'       => [
					[
						'path'               => [ 0, 0 ],
						'name'               => 'core/paragraph',
						'label'              => 'Paragraph',
						'overrideAttributes' => [ 'content' ],
						'usesDefaultBinding' => false,
						'bindableAttributes' => [ 'content' ],
					],
				],
			],
			$result['currentPatternOverrides']
		);
	}

	public function test_for_template_part_marks_locked_blocks_as_non_destructive_targets(): void {
		WordPressTestState::$block_templates['wp_template_part'][0]->content =
			'<!-- wp:group -->'
			. '<!-- wp:paragraph --><p>Keep</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph {"templateLock":"all"} --><p>Locked</p><!-- /wp:paragraph -->'
			. '<!-- /wp:group -->';

		$result = ServerCollector::for_template_part( 'theme//header' );

		$this->assertSame(
			[
				[
					'path'              => [ 0 ],
					'name'              => 'core/group',
					'label'             => 'Group',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
					'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
				],
				[
					'path'              => [ 0, 0 ],
					'name'              => 'core/paragraph',
					'label'             => 'Paragraph',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
					'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
				],
				[
					'path'              => [ 0, 1 ],
					'name'              => 'core/paragraph',
					'label'             => 'Paragraph',
					'allowedOperations' => [],
					'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
				],
			],
			$result['operationTargets']
		);
		$this->assertSame(
			[
				'contentOnlyPaths' => [],
				'lockedPaths'      => [ [ 0, 1 ] ],
				'hasContentOnly'   => false,
				'hasLockedBlocks'  => true,
			],
			$result['structuralConstraints']
		);
	}

	public function test_for_navigation_uses_live_block_attributes_with_saved_menu_structure(): void {
		WordPressTestState::$posts[42]                                       = (object) [
			'ID'           => 42,
			'post_type'    => 'wp_navigation',
			'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->'
				. '<!-- wp:navigation-link {"label":"Contact","url":"/contact"} /-->',
		];
		WordPressTestState::$block_templates['wp_template_part'][0]->content =
			'<!-- wp:navigation {"ref":42} /-->';

		$result = ServerCollector::for_navigation(
			42,
			'<!-- wp:navigation {"ref":42,"overlayMenu":"always","openSubmenusOnClick":true} /-->'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'header', $result['location'] ?? null );
		$this->assertSame(
			'always',
			$result['attributes']['overlayMenu'] ?? null
		);
		$this->assertTrue(
			(bool) ( $result['attributes']['openSubmenusOnClick'] ?? false )
		);
		$this->assertSame( 2, $result['menuItemCount'] ?? null );
		$this->assertSame(
			[
				'area'   => 'header',
				'source' => 'template-part-scan',
			],
			$result['locationDetails'] ?? null
		);
		$this->assertSame(
			[
				'topLevelCount'  => 2,
				'submenuCount'   => 0,
				'hasPageList'    => false,
				'nonLinkTypes'   => [],
				'topLevelLabels' => [ 'Home', 'Contact' ],
			],
			$result['structureSummary'] ?? null
		);
		$this->assertSame(
			[
				'usesOverlay'              => true,
				'overlayMode'              => 'always',
				'hasDedicatedOverlayParts' => false,
				'overlayTemplatePartCount' => 0,
				'overlayTemplatePartSlugs' => [],
			],
			$result['overlayContext'] ?? null
		);
		$this->assertSame(
			[ 'Home', 'Contact' ],
			array_column( $result['menuItems'] ?? [], 'label' )
		);
	}

	public function test_for_navigation_preserves_an_explicitly_empty_live_navigation_structure(): void {
		WordPressTestState::$posts[42]                                       = (object) [
			'ID'           => 42,
			'post_type'    => 'wp_navigation',
			'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->'
				. '<!-- wp:navigation-link {"label":"Contact","url":"/contact"} /-->',
		];
		WordPressTestState::$block_templates['wp_template_part'][0]->content =
			'<!-- wp:navigation {"ref":42} /-->';

		$result = ServerCollector::for_navigation(
			42,
			'<!-- wp:navigation {"ref":42,"overlayMenu":"always"} --><!-- /wp:navigation -->'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'always', $result['attributes']['overlayMenu'] ?? null );
		$this->assertSame( 0, $result['menuItemCount'] ?? null );
		$this->assertSame( [], $result['menuItems'] ?? [] );
	}

	public function test_for_tokens_includes_duotone_presets_in_compact_summary(): void {
		WordPressTestState::$global_settings = [
			'color'      => [
				'gradients' => [
					'theme' => [
						[
							'slug'     => 'sunset',
							'gradient' => 'linear-gradient(135deg,#f60,#fc0)',
						],
					],
				],
				'duotone'   => [
					'theme' => [
						[
							'slug'   => 'midnight',
							'colors' => [ '#111111', '#f5f5f5' ],
						],
					],
				],
			],
			'typography' => [],
			'spacing'    => [
				'blockGap' => true,
			],
			'shadow'     => [],
			'layout'     => [
				'allowEditing' => false,
			],
			'background' => [
				'backgroundImage' => true,
				'backgroundSize'  => false,
			],
			'border'     => [
				'style' => true,
			],
		];
		WordPressTestState::$global_styles   = [
			'elements' => [
				'heading' => [
					'color' => [
						'text' => 'var(--wp--preset--color--contrast)',
					],
				],
			],
		];

		$tokens = ServerCollector::for_tokens();

		$this->assertArrayHasKey( 'duotone', $tokens );
		$this->assertSame( [ 'midnight: #111111 / #f5f5f5' ], $tokens['duotone'] );
		$this->assertSame(
			[
				[
					'slug'   => 'midnight',
					'colors' => [ '#111111', '#f5f5f5' ],
				],
			],
			$tokens['duotonePresets']
		);
		$this->assertSame(
			[ 'sunset: linear-gradient(135deg,#f60,#fc0)' ],
			$tokens['gradients']
		);
		$this->assertSame(
			[
				[
					'name'     => '',
					'slug'     => 'sunset',
					'gradient' => 'linear-gradient(135deg,#f60,#fc0)',
					'cssVar'   => 'var(--wp--preset--gradient--sunset)',
				],
			],
			$tokens['gradientPresets']
		);
		$this->assertFalse( $tokens['layout']['allowEditing'] );
		$this->assertTrue( $tokens['enabledFeatures']['blockGap'] );
		$this->assertTrue( $tokens['enabledFeatures']['backgroundImage'] );
		$this->assertTrue( $tokens['enabledFeatures']['borderStyle'] );
		$this->assertSame(
			[
				'heading' => [
					'base'         => [
						'text' => 'var(--wp--preset--color--contrast)',
					],
					'hover'        => [],
					'focus'        => [],
					'focusVisible' => [],
				],
			],
			$tokens['elementStyles']
		);
	}

	public function test_for_block_uses_inner_blocks_for_child_count(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/container',
			[
				'title' => 'Container',
			]
		);

		$result = ServerCollector::for_block(
			'plugin/container',
			[],
			[
				[
					'blockName' => 'core/paragraph',
				],
				[
					'blockName' => 'core/image',
				],
			]
		);

		$this->assertSame( 2, $result['block']['childCount'] ?? null );
	}
}
