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

	public function test_for_block_preserves_parent_and_sibling_context(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/simple',
			[
				'title' => 'Simple',
			]
		);

		$result = ServerCollector::for_block(
			'plugin/simple',
			[],
			[],
			false,
			[ 'block' => 'core/group' ],
			[ [ 'block' => 'core/heading' ] ],
			[ [ 'block' => 'core/image' ] ]
		);

		$this->assertSame( 'plugin/simple', $result['block']['name'] );
		$this->assertSame( [ [ 'block' => 'core/heading' ] ], $result['siblingSummariesBefore'] );
		$this->assertSame( [ [ 'block' => 'core/image' ] ], $result['siblingSummariesAfter'] );
		$this->assertSame( [ 'block' => 'core/group' ], $result['parentContext'] );
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

	public function test_for_active_theme_returns_theme_identity(): void {
		WordPressTestState::$active_theme = [
			'name'       => 'Pattern Theme',
			'version'    => '1.2.3',
			'stylesheet' => 'pattern-theme',
			'template'   => 'parent-theme',
		];

		$this->assertSame(
			[
				'name'       => 'Pattern Theme',
				'version'    => '1.2.3',
				'stylesheet' => 'pattern-theme',
				'template'   => 'parent-theme',
			],
			ServerCollector::for_active_theme()
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

	public function test_for_theme_presets_returns_preset_families(): void {
		WordPressTestState::$global_settings = [
			'color'      => [
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#ff5500',
					],
				],
			],
			'typography' => [
				'fontSizes' => [
					[
						'slug' => 'small',
						'size' => '12px',
					],
				],
			],
			'spacing'    => [
				'spacingSizes' => [
					[
						'slug' => '40',
						'size' => '1rem',
					],
				],
			],
		];

		$presets = ServerCollector::for_theme_presets();

		$this->assertSame( 'accent', $presets['colorPresets'][0]['slug'] );
		$this->assertSame( 'small', $presets['fontSizePresets'][0]['slug'] );
		$this->assertSame( '40', $presets['spacingPresets'][0]['slug'] );
	}

	public function test_for_theme_styles_returns_raw_and_extracted_style_data(): void {
		WordPressTestState::$global_styles = [
			'elements' => [
				'button' => [
					'color'  => [
						'text' => 'var(--wp--preset--color--contrast)',
					],
					':hover' => [
						'color' => [
							'text' => 'var(--wp--preset--color--accent)',
						],
					],
					':focus' => [
						'color' => [
							'text' => 'var(--wp--preset--color--base)',
						],
					],
				],
			],
			'blocks'   => [
				'core/button' => [
					':hover' => [
						'color' => [
							'text' => 'var(--wp--preset--color--base)',
						],
					],
				],
			],
		];

		$styles = ServerCollector::for_theme_styles();

		$this->assertSame( 'var(--wp--preset--color--contrast)', $styles['elementStyles']['button']['base']['text'] );
		$this->assertSame( 'var(--wp--preset--color--base)', $styles['blockPseudoStyles']['core/button'][':hover']['color']['text'] );
		$this->assertArrayHasKey( 'elements', $styles['styles'] );
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
						'fontFamily'               => true,
						'__experimentalFontFamily' => true,
						'fitText'                  => true,
						'fontStyle'                => true,
						'fontWeight'               => true,
						'letterSpacing'            => true,
						'textIndent'               => true,
						'textDecoration'           => true,
						'textTransform'            => true,
					],
				],
			]
		);

		$manifest = ServerCollector::introspect_block_type( 'plugin/typography-block' );

		$this->assertSame(
			[
				'typography.fontFamily',
				'typography.__experimentalFontFamily',
				'typography.fitText',
				'typography.fontStyle',
				'typography.fontWeight',
				'typography.letterSpacing',
				'typography.textIndent',
				'typography.textDecoration',
				'typography.textTransform',
			],
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

	public function test_for_template_resolves_url_encoded_site_editor_ref(): void {
		$result = ServerCollector::for_template( 'theme%2F%2Fhome' );

		$this->assertIsArray( $result );
		$this->assertSame( 'theme//home', $result['templateRef'] );
		$this->assertSame( 'home', $result['templateType'] );
	}

	public function test_for_template_resolves_numeric_site_editor_template_post_id(): void {
		WordPressTestState::$block_templates['wp_template'][0]->wp_id = 42;

		$result = ServerCollector::for_template( '42' );

		$this->assertIsArray( $result );
		$this->assertSame( 'theme//home', $result['templateRef'] );
		$this->assertSame( 'home', $result['templateType'] );
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

	public function test_for_pattern_returns_single_registered_pattern_by_name(): void {
		$this->register_pattern(
			'theme/hero',
			[
				'title'   => 'Hero',
				'content' => '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->',
			]
		);

		$pattern = ServerCollector::for_pattern( 'theme/hero' );

		$this->assertIsArray( $pattern );
		$this->assertSame( 'theme/hero', $pattern['id'] );
		$this->assertSame( 'Hero', $pattern['title'] );
	}

	public function test_for_patterns_supports_search_pagination_and_lightweight_cached_results(): void {
		$this->register_pattern(
			'theme/marketing-alpha',
			[
				'title'   => 'Marketing Alpha',
				'content' => '<!-- wp:paragraph --><p>Alpha</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/marketing-beta',
			[
				'title'   => 'Marketing Beta',
				'content' => '<!-- wp:paragraph --><p>Beta</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/editorial-gamma',
			[
				'title'   => 'Editorial Gamma',
				'content' => '<!-- wp:paragraph --><p>Gamma</p><!-- /wp:paragraph -->',
			]
		);

		$first_page  = ServerCollector::for_patterns( null, null, null, false, 1, 0, 'marketing' );
		$second_page = ServerCollector::for_patterns( null, null, null, false, 1, 1, 'marketing' );

		$this->assertSame( 2, ServerCollector::count_patterns( null, null, null, 'marketing' ) );
		$this->assertSame( 'theme/marketing-alpha', $first_page[0]['name'] );
		$this->assertSame( 'theme/marketing-beta', $second_page[0]['name'] );
		$this->assertArrayNotHasKey( 'content', $first_page[0] );
		$this->assertArrayNotHasKey( 'content', $second_page[0] );
	}

	public function test_for_synced_patterns_filters_wp_block_posts_by_sync_status(): void {
		WordPressTestState::$capabilities = [
			'read_post:102' => true,
		];
		WordPressTestState::$posts        = [
			101 => (object) [
				'ID'                => 101,
				'post_type'         => 'wp_block',
				'post_title'        => 'Shared Header',
				'post_name'         => 'shared-header',
				'post_content'      => '<!-- wp:group -->Header<!-- /wp:group -->',
				'post_status'       => 'publish',
				'post_author'       => 3,
				'post_date_gmt'     => '2026-04-20 00:00:00',
				'post_modified_gmt' => '2026-04-21 00:00:00',
			],
			102 => (object) [
				'ID'                => 102,
				'post_type'         => 'wp_block',
				'post_title'        => 'Local Promo',
				'post_name'         => 'local-promo',
				'post_content'      => '<!-- wp:paragraph --><p>Promo</p><!-- /wp:paragraph -->',
				'post_status'       => 'draft',
				'post_author'       => 5,
				'post_date_gmt'     => '2026-04-18 00:00:00',
				'post_modified_gmt' => '2026-04-19 00:00:00',
			],
		];
		WordPressTestState::$post_meta    = [
			102 => [
				'wp_pattern_sync_status' => 'unsynced',
			],
		];

		$synced_patterns   = ServerCollector::for_synced_patterns();
		$unsynced_patterns = ServerCollector::for_synced_patterns( 'unsynced' );
		$synced_pattern    = ServerCollector::for_synced_pattern( 101 );

		$this->assertSame( [ 101 ], array_column( $synced_patterns, 'id' ) );
		$this->assertSame( [ 102 ], array_column( $unsynced_patterns, 'id' ) );
		$this->assertSame( 'synced', $synced_pattern['syncStatus'] );
		$this->assertSame( '', $synced_pattern['wpPatternSyncStatus'] );
	}

	public function test_for_synced_patterns_supports_partial_search_pagination_and_lightweight_results(): void {
		WordPressTestState::$capabilities = [
			'read_post' => static fn( int $post_id ): bool => in_array( $post_id, [ 201, 202 ], true ),
		];
		WordPressTestState::$posts        = [
			201 => (object) [
				'ID'                => 201,
				'post_type'         => 'wp_block',
				'post_title'        => 'Alpha Partial',
				'post_name'         => 'alpha-partial',
				'post_content'      => '<!-- wp:group -->Alpha<!-- /wp:group -->',
				'post_status'       => 'publish',
				'post_author'       => 3,
				'post_date_gmt'     => '2026-04-20 00:00:00',
				'post_modified_gmt' => '2026-04-21 00:00:00',
			],
			202 => (object) [
				'ID'                => 202,
				'post_type'         => 'wp_block',
				'post_title'        => 'Beta Partial',
				'post_name'         => 'beta-partial',
				'post_content'      => '<!-- wp:group -->Beta<!-- /wp:group -->',
				'post_status'       => 'draft',
				'post_author'       => 5,
				'post_date_gmt'     => '2026-04-18 00:00:00',
				'post_modified_gmt' => '2026-04-19 00:00:00',
			],
			203 => (object) [
				'ID'                => 203,
				'post_type'         => 'wp_block',
				'post_title'        => 'Gamma Synced',
				'post_name'         => 'gamma-synced',
				'post_content'      => '<!-- wp:group -->Gamma<!-- /wp:group -->',
				'post_status'       => 'publish',
				'post_author'       => 6,
				'post_date_gmt'     => '2026-04-18 00:00:00',
				'post_modified_gmt' => '2026-04-19 00:00:00',
			],
		];
		WordPressTestState::$post_meta    = [
			201 => [
				'wp_pattern_sync_status' => 'partial',
			],
			202 => [
				'wp_pattern_sync_status' => 'partial',
			],
		];

		$patterns = ServerCollector::for_synced_patterns( 'partial', false, 1, 1, 'partial' );

		$this->assertSame( 2, ServerCollector::count_synced_patterns( 'partial', 'partial' ) );
		$this->assertCount( 1, $patterns );
		$this->assertSame( 202, $patterns[0]['id'] );
		$this->assertSame( 'partial', $patterns[0]['syncStatus'] );
		$this->assertArrayNotHasKey( 'content', $patterns[0] );
	}

	public function test_for_synced_patterns_keeps_synced_lists_paginated_without_capping_total(): void {
		WordPressTestState::$capabilities = [
			'read_post' => static fn( int $post_id ): bool => in_array( $post_id, [ 301, 302 ], true ),
		];
		WordPressTestState::$posts        = [
			301 => (object) [
				'ID'                => 301,
				'post_type'         => 'wp_block',
				'post_title'        => 'Alpha Synced',
				'post_name'         => 'alpha-synced',
				'post_content'      => '<!-- wp:group -->Alpha<!-- /wp:group -->',
				'post_status'       => 'publish',
				'post_author'       => 3,
				'post_date_gmt'     => '2026-04-20 00:00:00',
				'post_modified_gmt' => '2026-04-21 00:00:00',
			],
			302 => (object) [
				'ID'                => 302,
				'post_type'         => 'wp_block',
				'post_title'        => 'Beta Synced',
				'post_name'         => 'beta-synced',
				'post_content'      => '<!-- wp:group -->Beta<!-- /wp:group -->',
				'post_status'       => 'draft',
				'post_author'       => 5,
				'post_date_gmt'     => '2026-04-18 00:00:00',
				'post_modified_gmt' => '2026-04-19 00:00:00',
			],
			303 => (object) [
				'ID'                => 303,
				'post_type'         => 'wp_block',
				'post_title'        => 'Gamma Unsynced',
				'post_name'         => 'gamma-unsynced',
				'post_content'      => '<!-- wp:paragraph --><p>Gamma</p><!-- /wp:paragraph -->',
				'post_status'       => 'publish',
				'post_author'       => 6,
				'post_date_gmt'     => '2026-04-17 00:00:00',
				'post_modified_gmt' => '2026-04-18 00:00:00',
			],
		];
		WordPressTestState::$post_meta    = [
			303 => [
				'wp_pattern_sync_status' => 'unsynced',
			],
		];

		$patterns = ServerCollector::for_synced_patterns( 'synced', false, 1, 1, 'synced' );

		$this->assertSame( 2, ServerCollector::count_synced_patterns( 'synced', 'synced' ) );
		$this->assertCount( 1, $patterns );
		$this->assertSame( 302, $patterns[0]['id'] );
		$this->assertSame( 'synced', $patterns[0]['syncStatus'] );
		$this->assertSame( '', $patterns[0]['wpPatternSyncStatus'] );
	}

	public function test_for_synced_patterns_preserves_slug_search_without_query_level_search(): void {
		WordPressTestState::$posts = [
			401 => (object) [
				'ID'                => 401,
				'post_type'         => 'wp_block',
				'post_title'        => 'Reusable Section',
				'post_name'         => 'hero-synced',
				'post_content'      => '<!-- wp:group -->Intro<!-- /wp:group -->',
				'post_status'       => 'publish',
				'post_author'       => 3,
				'post_date_gmt'     => '2026-04-20 00:00:00',
				'post_modified_gmt' => '2026-04-21 00:00:00',
			],
		];

		$patterns = ServerCollector::for_synced_patterns( 'synced', false, null, 0, 'hero' );

		$this->assertSame( [ 401 ], array_column( $patterns, 'id' ) );
		$this->assertSame( 1, ServerCollector::count_synced_patterns( 'synced', 'hero' ) );
		$this->assertNotEmpty( WordPressTestState::$get_posts_calls );
		foreach ( WordPressTestState::$get_posts_calls as $call ) {
			$this->assertArrayNotHasKey( 's', $call );
		}
	}

	public function test_for_registered_blocks_returns_sorted_block_manifests(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/beta',
			[
				'title' => 'Beta',
			]
		);
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/alpha',
			[
				'title'         => 'Alpha',
				'allowedBlocks' => [ 'core/paragraph' ],
			]
		);

		$blocks = ServerCollector::for_registered_blocks();

		$this->assertSame( [ 'plugin/alpha', 'plugin/beta' ], array_column( $blocks, 'name' ) );
		$this->assertSame( [ 'core/paragraph' ], $blocks[0]['allowedBlocks'] );
	}

	public function test_for_registered_blocks_supports_filters_and_variation_controls(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/card',
			[
				'title'      => 'Card',
				'category'   => 'design',
				'variations' => [
					[
						'name'  => 'feature',
						'title' => 'Feature',
					],
					[
						'name'  => 'compact',
						'title' => 'Compact',
					],
				],
			]
		);
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/carousel',
			[
				'title'      => 'Carousel',
				'category'   => 'design',
				'variations' => [
					[
						'name'  => 'hero',
						'title' => 'Hero',
					],
					[
						'name'  => 'gallery',
						'title' => 'Gallery',
					],
				],
			]
		);
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/notice',
			[
				'title'    => 'Notice',
				'category' => 'widgets',
			]
		);

		$metadata_only   = ServerCollector::for_registered_blocks( 'car', 'design', 1, 1, false );
		$with_variations = ServerCollector::for_registered_blocks( 'car', 'design', null, 0, true, 1 );

		$this->assertSame( 2, ServerCollector::count_registered_blocks( 'car', 'design' ) );
		$this->assertCount( 1, $metadata_only );
		$this->assertSame( 'plugin/carousel', $metadata_only[0]['name'] );
		$this->assertSame( [], $metadata_only[0]['variations'] );
		$this->assertSame( 1, count( $with_variations[0]['variations'] ) );
		$this->assertSame( 1, count( $with_variations[1]['variations'] ) );
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
				'usesOverlay'                  => true,
				'overlayMode'                  => 'always',
				'hasDedicatedOverlayParts'     => false,
				'overlayTemplatePartCount'     => 0,
				'overlayTemplatePartSlugs'     => [],
				'siteHasDedicatedOverlayParts' => false,
				'siteOverlayTemplatePartCount' => 0,
				'siteOverlayTemplatePartSlugs' => [],
				'overlayReferenceScope'        => 'none',
				'overlayReferenceSource'       => 'none',
			],
			$result['overlayContext'] ?? null
		);
		$this->assertSame(
			[ 'Home', 'Contact' ],
			array_column( $result['menuItems'] ?? [], 'label' )
		);
		$this->assertSame(
			[
				[
					'path'  => [ 0 ],
					'label' => 'Home',
					'type'  => 'navigation-link',
					'depth' => 0,
				],
				[
					'path'  => [ 1 ],
					'label' => 'Contact',
					'type'  => 'navigation-link',
					'depth' => 0,
				],
			],
			$result['targetInventory'] ?? null
		);
	}

	public function test_for_navigation_prefers_editor_context_location_and_scopes_overlay_parts(): void {
		WordPressTestState::$posts[42]                           = (object) [
			'ID'           => 42,
			'post_type'    => 'wp_navigation',
			'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
		];
		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'slug'    => 'site-footer',
				'title'   => 'Site Footer',
				'area'    => 'footer',
				'content' => '<!-- wp:navigation {"ref":42} /-->',
			],
			(object) [
				'slug'    => 'header-overlay',
				'title'   => 'Header Overlay',
				'area'    => 'navigation-overlay',
				'content' => '<!-- wp:navigation {"ref":42} /-->',
			],
			(object) [
				'slug'    => 'footer-overlay',
				'title'   => 'Footer Overlay',
				'area'    => 'navigation-overlay',
				'content' => '<!-- wp:navigation {"ref":77} /-->',
			],
		];

		$result = ServerCollector::for_navigation(
			42,
			'<!-- wp:navigation {"ref":42,"overlayMenu":"mobile"} /-->',
			[
				'block'               => [
					'name'               => 'core/navigation',
					'structuralIdentity' => [
						'role'             => 'header-navigation',
						'location'         => 'header',
						'templateArea'     => 'header',
						'templatePartSlug' => 'site-header',
					],
				],
				'siblingsBefore'      => [ 'core/site-logo' ],
				'structuralAncestors' => [
					[
						'block'            => 'core/template-part',
						'role'             => 'header-slot',
						'location'         => 'header',
						'templateArea'     => 'header',
						'templatePartSlug' => 'site-header',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'header', $result['location'] ?? null );
		$this->assertSame(
			[
				'area'             => 'header',
				'source'           => 'editor-context',
				'templateArea'     => 'header',
				'templatePartSlug' => 'site-header',
				'role'             => 'header-navigation',
			],
			$result['locationDetails'] ?? null
		);
		$this->assertSame(
			[
				[
					'slug'  => 'header-overlay',
					'title' => 'Header Overlay',
					'area'  => 'navigation-overlay',
				],
			],
			$result['overlayTemplateParts'] ?? null
		);
		$this->assertSame(
			[
				'usesOverlay'                  => true,
				'overlayMode'                  => 'mobile',
				'hasDedicatedOverlayParts'     => true,
				'overlayTemplatePartCount'     => 1,
				'overlayTemplatePartSlugs'     => [ 'header-overlay' ],
				'siteHasDedicatedOverlayParts' => true,
				'siteOverlayTemplatePartCount' => 2,
				'siteOverlayTemplatePartSlugs' => [ 'header-overlay', 'footer-overlay' ],
				'overlayReferenceScope'        => 'scoped',
				'overlayReferenceSource'       => 'direct-menu-ref',
			],
			$result['overlayContext'] ?? null
		);
		$this->assertSame(
			'core/navigation',
			$result['editorContext']['block']['name'] ?? null
		);
	}

	public function test_for_navigation_location_inference_matches_menu_refs_exactly(): void {
		WordPressTestState::$posts[12]                           = (object) [
			'ID'           => 12,
			'post_type'    => 'wp_navigation',
			'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
		];
		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'slug'    => 'header',
				'title'   => 'Header',
				'area'    => 'header',
				'content' => '<!-- wp:group --><!-- wp:navigation {"ref":123} /--><!-- /wp:group -->',
			],
			(object) [
				'slug'    => 'footer',
				'title'   => 'Footer',
				'area'    => 'footer',
				'content' => '<!-- wp:group --><!-- wp:navigation {"ref":12} /--><!-- /wp:group -->',
			],
		];

		$result = ServerCollector::for_navigation( 12 );

		$this->assertIsArray( $result );
		$this->assertSame( 'footer', $result['location'] ?? null );
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
