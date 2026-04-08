<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\BlockAbilities;
use FlavorAgent\Abilities\PatternAbilities;
use FlavorAgent\Abilities\StyleAbilities;
use FlavorAgent\Abilities\TemplateAbilities;
use FlavorAgent\Abilities\WordPressDocsAbilities;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DocsGroundingEntityCacheTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
	}

	public function test_block_docs_guidance_uses_query_cache_before_entity_cache(): void {
		$query_guidance  = [
			[
				'id'        => 'query-chunk',
				'title'     => 'Footer navigation guidance',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
				'excerpt'   => 'Specific footer navigation guidance for submenu spacing.',
				'score'     => 0.94,
			],
		];
		$entity_guidance = [
			[
				'id'        => 'entity-chunk',
				'title'     => 'Navigation block reference',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
				'excerpt'   => 'Generic navigation block guidance.',
				'score'     => 0.82,
			],
		];
		$context         = [
			'block' => [
				'name'               => 'core/navigation',
				'structuralIdentity' => [
					'role'     => 'footer-navigation',
					'location' => 'footer',
				],
			],
		];
		$prompt          = 'Simplify footer links.';
		$query           = $this->invoke_private_string_method(
			BlockAbilities::class,
			'build_wordpress_docs_query',
			[ $context, $prompt ]
		);

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ]                = $query_guidance;
		WordPressTestState::$transients[ $this->build_entity_cache_key( 'core/navigation' ) ] = $entity_guidance;

		$this->assertSame(
			$query_guidance,
			$this->invoke_private_array_method(
				BlockAbilities::class,
				'collect_wordpress_docs_guidance',
				[ $context, $prompt ]
			)
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_template_docs_guidance_uses_query_cache_before_entity_cache(): void {
		$query_guidance  = [
			[
				'id'        => 'query-chunk',
				'title'     => '404 template recovery guidance',
				'sourceKey' => 'developer.wordpress.org/themes/templates/template-hierarchy',
				'url'       => 'https://developer.wordpress.org/themes/templates/template-hierarchy/',
				'excerpt'   => 'Specific 404 template guidance for recovery content.',
				'score'     => 0.93,
			],
		];
		$entity_guidance = [
			[
				'id'        => 'entity-chunk',
				'title'     => 'Template hierarchy',
				'sourceKey' => 'developer.wordpress.org/themes/templates/template-hierarchy',
				'url'       => 'https://developer.wordpress.org/themes/templates/template-hierarchy/',
				'excerpt'   => 'Generic 404 template guidance.',
				'score'     => 0.88,
			],
		];
		$context         = [
			'templateType' => '404',
			'allowedAreas' => [ 'header', 'footer' ],
		];
		$prompt          = 'Keep the recovery options minimal.';
		$query           = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_wordpress_docs_query',
			[ $context, $prompt ]
		);

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ]             = $query_guidance;
		WordPressTestState::$transients[ $this->build_entity_cache_key( 'template:404' ) ] = $entity_guidance;

		$this->assertSame(
			$query_guidance,
			$this->invoke_private_array_method(
				TemplateAbilities::class,
				'collect_wordpress_docs_guidance',
				[ $context, $prompt ]
			)
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_pattern_docs_guidance_uses_query_cache_before_entity_cache(): void {
		$query_guidance  = [
			[
				'id'        => 'query-chunk',
				'title'     => 'Cover pattern guidance',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/cover',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/cover/',
				'excerpt'   => 'Patterns near cover blocks should respect focal point and overlay readability.',
				'score'     => 0.95,
			],
		];
		$entity_guidance = [
			[
				'id'        => 'entity-chunk',
				'title'     => 'Cover block reference',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/cover',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/cover/',
				'excerpt'   => 'Generic cover block guidance.',
				'score'     => 0.84,
			],
		];
		$context         = [
			'postType'     => 'page',
			'templateType' => 'home',
			'blockContext' => [
				'blockName' => 'core/cover',
			],
		];
		$prompt          = 'Make the hero feel more editorial.';
		$query           = $this->invoke_private_string_method(
			PatternAbilities::class,
			'build_wordpress_docs_query',
			[ $context, $prompt ]
		);

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ]           = $query_guidance;
		WordPressTestState::$transients[ $this->build_entity_cache_key( 'core/cover' ) ] = $entity_guidance;

		$this->assertSame(
			$query_guidance,
			$this->invoke_private_array_method(
				PatternAbilities::class,
				'collect_wordpress_docs_guidance',
				[ $context, $prompt ]
			)
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_pattern_docs_query_and_family_context_include_insertion_scope_and_visible_patterns(): void {
		$context = [
			'postType'            => 'page',
			'templateType'        => 'home',
			'blockContext'        => [
				'blockName' => 'core/group',
			],
			'insertionContext'    => [
				'rootBlock'        => 'core/group',
				'ancestors'        => [ 'core/template-part', 'core/group' ],
				'nearbySiblings'   => [ 'core/site-logo', 'core/navigation' ],
				'templatePartArea' => 'header',
				'templatePartSlug' => 'site-header',
				'containerLayout'  => 'flex',
			],
			'visiblePatternNames' => [ 'theme/header-utility', 'theme/header-social' ],
		];
		$prompt  = 'Keep the header practical.';
		$query   = $this->invoke_private_string_method(
			PatternAbilities::class,
			'build_wordpress_docs_query',
			[ $context, $prompt ]
		);
		$family  = $this->invoke_private_array_method(
			PatternAbilities::class,
			'build_wordpress_docs_family_context',
			[ $context, 'core/group' ]
		);

		$this->assertStringContainsString( 'template part area header', $query );
		$this->assertStringContainsString( 'container layout flex', $query );
		$this->assertStringContainsString( 'visible pattern scope theme/header-utility, theme/header-social', $query );
		$this->assertSame( 'core/group', $family['insertionRoot'] ?? null );
		$this->assertSame( 'header', $family['templatePartArea'] ?? null );
		$this->assertSame( 'flex', $family['containerLayout'] ?? null );
		$this->assertSame(
			[ 'theme/header-utility', 'theme/header-social' ],
			$family['visiblePatterns'] ?? null
		);
	}

	public function test_template_docs_query_includes_live_structure_summary_and_override_counts(): void {
		$context = [
			'templateType'             => 'home',
			'allowedAreas'             => [ 'header', 'footer' ],
			'emptyAreas'               => [ 'sidebar' ],
			'topLevelBlockTree'        => [
				[
					'name' => 'core/template-part',
				],
				[
					'name' => 'core/group',
				],
			],
			'structureStats'           => [
				'blockCount'         => 7,
				'topLevelBlockCount' => 2,
				'maxDepth'           => 3,
			],
			'currentPatternOverrides'  => [
				'blockCount' => 2,
			],
			'currentViewportVisibility' => [
				'blockCount' => 1,
			],
		];
		$query   = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_wordpress_docs_query',
			[ $context, 'Keep the structure focused.' ]
		);

		$this->assertStringContainsString( 'top-level blocks core/template-part, core/group', $query );
		$this->assertStringContainsString( 'live structure 7 blocks, 2 top level, depth 3', $query );
		$this->assertStringContainsString( 'override-ready blocks 2', $query );
		$this->assertStringContainsString( 'viewport-constrained blocks 1', $query );
	}

	public function test_template_part_docs_query_includes_targets_anchors_and_constraints(): void {
		$context = [
			'area'                 => 'header',
			'slug'                 => 'site-header',
			'topLevelBlocks'       => [ 'core/group' ],
			'operationTargets'     => [
				[
					'name'              => 'core/navigation',
					'label'             => 'Navigation block',
					'allowedOperations' => [ 'replace_block_with_pattern' ],
				],
			],
			'insertionAnchors'     => [
				[
					'placement' => 'after_block_path',
					'label'     => 'After navigation',
					'blockName' => 'core/navigation',
				],
			],
			'structuralConstraints' => [
				'hasContentOnly'   => true,
				'contentOnlyPaths' => [ [ 0, 1 ] ],
				'hasLockedBlocks'  => true,
				'lockedPaths'      => [ [ 0 ] ],
			],
		];
		$query   = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_part_wordpress_docs_query',
			[ $context, 'Keep the header compact.' ]
		);

		$this->assertStringContainsString( 'executable targets Navigation block [replace_block_with_pattern]', $query );
		$this->assertStringContainsString( 'validated anchors After navigation', $query );
		$this->assertStringContainsString( 'structural constraints content-only paths 1, locked paths 1', $query );
	}

	public function test_style_book_docs_guidance_uses_query_cache_before_entity_cache(): void {
		$query_guidance  = [
			[
				'id'        => 'query-chunk',
				'title'     => 'Paragraph block styling guidance',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph/',
				'excerpt'   => 'Paragraph styles should stay inside theme.json-backed typography and color controls.',
				'score'     => 0.93,
			],
		];
		$entity_guidance = [
			[
				'id'        => 'entity-chunk',
				'title'     => 'Paragraph block reference',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph/',
				'excerpt'   => 'Generic paragraph block guidance.',
				'score'     => 0.82,
			],
		];
		$context         = [
			'scope'        => [
				'surface'    => 'style-book',
				'blockName'  => 'core/paragraph',
				'blockTitle' => 'Paragraph',
			],
			'styleContext' => [
				'styleBookTarget'     => [
					'blockName'   => 'core/paragraph',
					'blockTitle'  => 'Paragraph',
					'description' => 'Primary intro copy block.',
				],
				'supportedStylePaths' => [
					[
						'path'        => [ 'color', 'text' ],
						'valueSource' => 'color',
					],
				],
			],
		];
		$prompt          = 'Tune the intro typography.';
		$query           = $this->invoke_private_string_method(
			StyleAbilities::class,
			'build_wordpress_docs_query',
			[ $context, $prompt ]
		);

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ]               = $query_guidance;
		WordPressTestState::$transients[ $this->build_entity_cache_key( 'core/paragraph' ) ] = $entity_guidance;

		$this->assertSame(
			$query_guidance,
			$this->invoke_private_array_method(
				StyleAbilities::class,
				'collect_wordpress_docs_guidance',
				[ $context, $prompt ]
			)
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_global_styles_docs_guidance_falls_back_to_global_styles_guidance_on_query_miss(): void {
		$entity_guidance = [
			[
				'id'        => 'entity-chunk',
				'title'     => 'Global Styles guidance',
				'sourceKey' => 'developer.wordpress.org/themes/global-settings-and-styles/settings',
				'url'       => 'https://developer.wordpress.org/themes/global-settings-and-styles/settings/',
				'excerpt'   => 'Use theme.json preset families and site-wide controls before custom CSS.',
				'score'     => 0.88,
			],
		];

		WordPressTestState::$transients[ $this->build_entity_cache_key( 'guidance:global-styles' ) ] = $entity_guidance;

		$context = [
			'scope'        => [
				'surface'        => 'global-styles',
				'scopeKey'       => 'global_styles:17',
				'globalStylesId' => '17',
			],
			'styleContext' => [
				'supportedStylePaths' => [
					[
						'path'        => [ 'color', 'text' ],
						'valueSource' => 'color',
					],
				],
			],
		];

		$this->assertSame(
			$entity_guidance,
			$this->invoke_private_array_method(
				StyleAbilities::class,
				'collect_wordpress_docs_guidance',
				[ $context, 'Keep the palette restrained.' ]
			)
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_style_book_docs_guidance_falls_back_to_style_book_guidance_when_block_entity_cache_is_cold(): void {
		$generic_guidance = [
			[
				'id'        => 'generic-chunk',
				'title'     => 'Style Book guidance',
				'sourceKey' => 'developer.wordpress.org/themes/global-settings-and-styles/styles',
				'url'       => 'https://developer.wordpress.org/themes/global-settings-and-styles/styles/',
				'excerpt'   => 'Style Book recommendations should stay inside supported block style paths and theme.json controls.',
				'score'     => 0.87,
			],
		];

		WordPressTestState::$transients[ $this->build_entity_cache_key( 'guidance:style-book' ) ] = $generic_guidance;

		$context = [
			'scope'        => [
				'surface'        => 'style-book',
				'scopeKey'       => 'style_book:17:core/paragraph',
				'globalStylesId' => '17',
				'blockName'      => 'core/paragraph',
				'blockTitle'     => 'Paragraph',
			],
			'styleContext' => [
				'styleBookTarget'     => [
					'blockName'   => 'core/paragraph',
					'blockTitle'  => 'Paragraph',
					'description' => 'Primary intro copy block.',
				],
				'supportedStylePaths' => [
					[
						'path'        => [ 'typography', 'fontSize' ],
						'valueSource' => 'font-size',
					],
				],
			],
		];

		$this->assertSame(
			$generic_guidance,
			$this->invoke_private_array_method(
				StyleAbilities::class,
				'collect_wordpress_docs_guidance',
				[ $context, 'Tune the intro typography.' ]
			)
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_template_part_docs_guidance_uses_query_cache_before_entity_cache(): void {
		$query_guidance  = [
			[
				'id'        => 'query-chunk',
				'title'     => 'Template part guidance',
				'sourceKey' => 'developer.wordpress.org/themes/templates/template-parts',
				'url'       => 'https://developer.wordpress.org/themes/templates/template-parts/',
				'excerpt'   => 'Header template parts should keep top-level blocks concise and purposeful.',
				'score'     => 0.92,
			],
		];
		$entity_guidance = [
			[
				'id'        => 'entity-chunk',
				'title'     => 'Template part reference',
				'sourceKey' => 'developer.wordpress.org/themes/templates/template-parts',
				'url'       => 'https://developer.wordpress.org/themes/templates/template-parts/',
				'excerpt'   => 'Generic template part guidance.',
				'score'     => 0.83,
			],
		];
		$context         = [
			'area'           => 'header',
			'slug'           => 'site-header',
			'topLevelBlocks' => [ 'core/group', 'core/navigation' ],
			'blockCounts'    => [
				'core/group'      => 1,
				'core/navigation' => 1,
			],
		];
		$prompt          = 'Keep the header compact.';
		$query           = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_part_wordpress_docs_query',
			[ $context, $prompt ]
		);

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ]                   = $query_guidance;
		WordPressTestState::$transients[ $this->build_entity_cache_key( 'core/template-part' ) ] = $entity_guidance;

		$this->assertSame(
			$query_guidance,
			$this->invoke_private_array_method(
				TemplateAbilities::class,
				'collect_template_part_wordpress_docs_guidance',
				[ $context, $prompt ]
			)
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_block_docs_guidance_falls_back_to_entity_cache_on_query_miss(): void {
		$entity_guidance = [
			[
				'id'        => 'entity-chunk',
				'title'     => 'Navigation block reference',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
				'excerpt'   => 'Generic navigation block guidance.',
				'score'     => 0.82,
			],
		];

		WordPressTestState::$transients[ $this->build_entity_cache_key( 'core/navigation' ) ] = $entity_guidance;

		$context = [
			'block' => [
				'name' => 'core/navigation',
			],
		];

		$this->assertSame(
			$entity_guidance,
			$this->invoke_private_array_method(
				BlockAbilities::class,
				'collect_wordpress_docs_guidance',
				[ $context, 'Simplify footer links.' ]
			)
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_block_docs_guidance_uses_family_cache_before_entity_cache(): void {
		$family_guidance = [
			[
				'id'        => 'family-chunk',
				'title'     => 'Footer navigation guidance',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
				'excerpt'   => 'Footer menus should keep submenu spacing compact and labels concise.',
				'score'     => 0.9,
			],
		];
		$entity_guidance = [
			[
				'id'        => 'entity-chunk',
				'title'     => 'Navigation block reference',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
				'excerpt'   => 'Generic navigation block guidance.',
				'score'     => 0.82,
			],
		];
		$context         = [
			'block' => [
				'name'               => 'core/navigation',
				'inspectorPanels'    => [
					'color'   => true,
					'spacing' => true,
				],
				'structuralIdentity' => [
					'role'     => 'footer-navigation',
					'location' => 'footer',
				],
			],
		];
		$prompt          = 'Simplify footer links.';
		$family_context  = $this->invoke_private_array_method(
			BlockAbilities::class,
			'build_wordpress_docs_family_context',
			[ $context ]
		);

		WordPressTestState::$transients[ $this->build_family_cache_key( $family_context, 4 ) ] = $family_guidance;
		WordPressTestState::$transients[ $this->build_entity_cache_key( 'core/navigation' ) ]  = $entity_guidance;

		$this->assertSame(
			$family_guidance,
			$this->invoke_private_array_method(
				BlockAbilities::class,
				'collect_wordpress_docs_guidance',
				[ $context, $prompt ]
			)
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_explicit_docs_search_seeds_entity_cache_from_entity_key(): void {
		$this->prime_search_response(
			'developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation',
			'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
			'Navigation block docs describe menu structure and responsive controls.'
		);

		$query  = 'Navigation block design guidance for footer menus.';
		$result = WordPressDocsAbilities::search_wordpress_docs(
			[
				'query'     => $query,
				'entityKey' => 'Core/Navigation',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			$result['guidance'],
			AISearchClient::maybe_search( $query )
		);
		$this->assertSame(
			$result['guidance'],
			AISearchClient::maybe_search_entity( 'core/navigation' )
		);
	}

	public function test_free_form_docs_search_without_entity_key_does_not_seed_entity_cache_without_legacy_inference(): void {
		$this->prime_search_response(
			'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
			'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/',
			'Block supports expose design tools instead of ad hoc attributes.'
		);

		$query  = 'Need guidance on design tools for editor blocks.';
		$result = WordPressDocsAbilities::search_wordpress_docs(
			[
				'query' => $query,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			$result['guidance'],
			AISearchClient::maybe_search( $query )
		);
		$this->assertSame( [], AISearchClient::maybe_search_entity( 'core/navigation' ) );
	}

	public function test_legacy_docs_search_query_inference_still_seeds_entity_cache(): void {
		$this->prime_search_response(
			'developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation',
			'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
			'Navigation block docs describe menu structure and responsive controls.'
		);

		$result = WordPressDocsAbilities::search_wordpress_docs(
			[
				'query' => 'WordPress Gutenberg block editor best practices. block type core/navigation. theme.json guidance.',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			$result['guidance'],
			AISearchClient::maybe_search_entity( 'core/navigation' )
		);
	}

	/**
	 * @param array<int, mixed> $arguments
	 */
	private function invoke_private_array_method( string $class_name, string $method_name, array $arguments ): array {
		$method = new ReflectionMethod( $class_name, $method_name );
		$method->setAccessible( true );

		$result = $method->invokeArgs( null, $arguments );

		$this->assertIsArray( $result );

		return $result;
	}

	/**
	 * @param array<int, mixed> $arguments
	 */
	private function invoke_private_string_method( string $class_name, string $method_name, array $arguments ): string {
		$method = new ReflectionMethod( $class_name, $method_name );
		$method->setAccessible( true );

		$result = $method->invokeArgs( null, $arguments );

		$this->assertIsString( $result );

		return $result;
	}

	/**
	 * @param array<string, mixed> $family_context
	 */
	private function build_family_cache_key( array $family_context, int $max_results ): string {
		$method = new ReflectionMethod( AISearchClient::class, 'build_family_cache_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $family_context, $max_results );

		$this->assertIsString( $result );

		return $result;
	}

	private function prime_search_response( string $source_key, string $url, string $excerpt ): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'    => 'chunk-1',
								'score' => 0.91,
								'item'  => [
									'key'      => $source_key,
									'metadata' => [],
								],
								'text'  => "---\nsource_url: \"{$url}\"\n---\n{$excerpt}",
							],
						],
					],
				]
			),
		];
	}

	private function build_cache_key( string $query, int $max_results ): string {
		$method = new ReflectionMethod( AISearchClient::class, 'build_cache_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $query, $max_results );

		$this->assertIsString( $result );

		return $result;
	}

	private function build_entity_cache_key( string $entity_key ): string {
		$method = new ReflectionMethod( AISearchClient::class, 'build_entity_cache_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $entity_key );

		$this->assertIsString( $result );

		return $result;
	}
}
