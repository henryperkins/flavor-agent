<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\TemplateAbilities;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TemplateAbilitiesTest extends TestCase {

	/** @var callable */
	private $disable_public_docs_filter;

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->disable_public_docs_filter    = static fn(): string => '';
		\add_filter(
			'flavor_agent_cloudflare_ai_search_public_search_url',
			$this->disable_public_docs_filter
		);
		WordPressTestState::$block_templates = [
			'wp_template'      => [
				(object) [
					'id'      => 'theme//home',
					'slug'    => 'home',
					'title'   => 'Home',
					'content' => '<!-- wp:group {"tagName":"main"} --><div>Main</div><!-- /wp:group -->',
				],
			],
			'wp_template_part' => [
				(object) [
					'id'      => 'theme//header',
					'slug'    => 'header',
					'title'   => 'Header',
					'area'    => 'header',
					'content' => '<!-- wp:group {"tagName":"header"} --><div>Header</div><!-- /wp:group -->',
				],
			],
		];
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
						'slug' => 'body',
						'size' => '1rem',
					],
				],
			],
			'spacing'    => [
				'spacingSizes' => [
					[
						'slug' => '50',
						'size' => '1.5rem',
					],
				],
			],
		];

		$this->register_pattern(
			'theme/hero',
			[
				'title'         => 'Hero',
				'templateTypes' => [ 'home' ],
				'content'       => '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/header-utility',
			[
				'title'      => 'Header Utility',
				'categories' => [ 'header' ],
				'blockTypes' => [ 'core/template-part/header' ],
				'content'    => '<!-- wp:paragraph --><p>Header Utility</p><!-- /wp:paragraph -->',
			]
		);
	}

	protected function tearDown(): void {
		\remove_filter(
			'flavor_agent_cloudflare_ai_search_public_search_url',
			$this->disable_public_docs_filter
		);

		parent::tearDown();
	}

	public function test_list_template_parts_can_omit_or_include_content_for_theme_editors(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
		];

		$metadata_only = TemplateAbilities::list_template_parts( [] );
		$with_content  = TemplateAbilities::list_template_parts(
			[
				'includeContent' => true,
			]
		);

		$this->assertCount( 1, $metadata_only['templateParts'] );
		$this->assertArrayNotHasKey( 'content', $metadata_only['templateParts'][0] );
		$this->assertSame( 'header', $metadata_only['templateParts'][0]['slug'] );
		$this->assertSame(
			'<!-- wp:group {"tagName":"header"} --><div>Header</div><!-- /wp:group -->',
			$with_content['templateParts'][0]['content']
		);
	}

	public function test_list_template_parts_coerces_include_content_to_metadata_for_editors_without_theme_access(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		$result = TemplateAbilities::list_template_parts(
			[
				'includeContent' => true,
			]
		);

		$this->assertCount( 1, $result['templateParts'] );
		$this->assertSame( 'header', $result['templateParts'][0]['slug'] );
		$this->assertArrayNotHasKey( 'content', $result['templateParts'][0] );
	}

	public function test_recommend_template_resolve_signature_only_returns_review_and_resolved_signatures(): void {
		$result = TemplateAbilities::recommend_template(
			[
				'templateRef'          => 'theme//home',
				'templateType'         => 'home',
				'prompt'               => 'Make the home template feel more editorial.',
				'visiblePatternNames'  => [ 'theme/hero' ],
				'resolveSignatureOnly' => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				'reviewContextSignature'   => $result['reviewContextSignature'] ?? null,
				'resolvedContextSignature' => $result['resolvedContextSignature'] ?? null,
			],
			$result
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $result['reviewContextSignature'] ?? '' )
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $result['resolvedContextSignature'] ?? '' )
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_recommend_template_review_and_resolved_signatures_ignore_docs_guidance_changes(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$input   = [
			'templateRef'          => 'theme//home',
			'templateType'         => 'home',
			'prompt'               => 'Make the home template feel more editorial.',
			'visiblePatternNames'  => [ 'theme/hero' ],
			'resolveSignatureOnly' => true,
		];
		$context = ServerCollector::for_template( 'theme//home', 'home', [ 'theme/hero' ] );
		$this->assertIsArray( $context );
		$context['visiblePatternNames'] = [ 'theme/hero' ];
		$query                          = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_wordpress_docs_query',
			[
				$context,
				$input['prompt'],
			]
		);

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [
			[
				'id'        => 'chunk-template-a',
				'title'     => 'Template reference',
				'sourceKey' => 'developer.wordpress.org/themes/templates/introduction-to-templates/',
				'url'       => 'https://developer.wordpress.org/themes/templates/introduction-to-templates/',
				'excerpt'   => 'Use template structure to reinforce the hierarchy of the page.',
				'score'     => 0.91,
			],
		];

		$baseline = TemplateAbilities::recommend_template( $input );

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [
			[
				'id'        => 'chunk-template-b',
				'title'     => 'Template part guidance',
				'sourceKey' => 'developer.wordpress.org/themes/templates/template-parts/',
				'url'       => 'https://developer.wordpress.org/themes/templates/template-parts/',
				'excerpt'   => 'Favor reusable template parts for stable structural sections.',
				'score'     => 0.93,
			],
		];
		WordPressTestState::$last_remote_post                                  = [];

		$changed = TemplateAbilities::recommend_template( $input );

		$this->assertIsArray( $baseline );
		$this->assertIsArray( $changed );
		$this->assertSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
		$this->assertSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_recommend_template_part_review_and_resolved_signatures_ignore_docs_guidance_changes(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$input   = [
			'templatePartRef'      => 'theme//header',
			'prompt'               => 'Give the header a cleaner utility rhythm.',
			'visiblePatternNames'  => [ 'theme/header-utility' ],
			'resolveSignatureOnly' => true,
		];
		$context = ServerCollector::for_template_part( 'theme//header', [ 'theme/header-utility' ] );
		$this->assertIsArray( $context );
		$context['visiblePatternNames'] = [ 'theme/header-utility' ];
		$query                          = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_part_wordpress_docs_query',
			[
				$context,
				$input['prompt'],
			]
		);

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [
			[
				'id'        => 'chunk-template-part-a',
				'title'     => 'Header template parts',
				'sourceKey' => 'developer.wordpress.org/themes/templates/template-parts/',
				'url'       => 'https://developer.wordpress.org/themes/templates/template-parts/',
				'excerpt'   => 'Keep header parts focused on branding, navigation, and lightweight utility actions.',
				'score'     => 0.89,
			],
		];

		$baseline = TemplateAbilities::recommend_template_part( $input );

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [
			[
				'id'        => 'chunk-template-part-b',
				'title'     => 'Block theme guidance',
				'sourceKey' => 'developer.wordpress.org/themes/global-settings-and-styles/',
				'url'       => 'https://developer.wordpress.org/themes/global-settings-and-styles/',
				'excerpt'   => 'Use theme tokens and restrained patterns so the header remains coherent with the overall site styles.',
				'score'     => 0.92,
			],
		];
		WordPressTestState::$last_remote_post                                  = [];

		$changed = TemplateAbilities::recommend_template_part( $input );

		$this->assertIsArray( $baseline );
		$this->assertIsArray( $changed );
		$this->assertSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
		$this->assertSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_recommend_template_review_signature_ignores_visible_pattern_scope_changes(): void {
		$baseline = TemplateAbilities::recommend_template(
			array_merge(
				$this->build_template_recommendation_input(),
				[
					'visiblePatternNames'  => [ 'theme/hero' ],
					'resolveSignatureOnly' => true,
				]
			)
		);
		$changed  = TemplateAbilities::recommend_template(
			array_merge(
				$this->build_template_recommendation_input(),
				[
					'visiblePatternNames'  => [ 'theme/header-utility' ],
					'resolveSignatureOnly' => true,
				]
			)
		);

		$this->assertIsArray( $baseline );
		$this->assertIsArray( $changed );
		$this->assertSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
	}

	public function test_recommend_template_review_signature_ignores_live_slot_and_structure_overlays(): void {
		$baseline = TemplateAbilities::recommend_template(
			array_merge(
				$this->build_template_recommendation_input(),
				[
					'editorSlots'          => $this->build_template_editor_slots_payload(),
					'editorStructure'      => $this->build_template_editor_structure_payload(),
					'resolveSignatureOnly' => true,
				]
			)
		);
		$changed  = TemplateAbilities::recommend_template(
			array_merge(
				$this->build_template_recommendation_input(),
				[
					'editorSlots'          => $this->build_template_editor_slots_payload(
						[
							'assignedParts' => [
								[
									'slug' => 'footer-main',
									'area' => 'footer',
								],
							],
							'emptyAreas'    => [ 'header' ],
							'allowedAreas'  => [ 'header', 'footer' ],
						]
					),
					'editorStructure'      => $this->build_template_editor_structure_payload(
						[
							'topLevelBlockTree'         => [
								[
									'path'       => [ 0 ],
									'name'       => 'core/query',
									'label'      => 'Query Loop',
									'attributes' => [
										'tagName' => 'main',
									],
									'childCount' => 3,
								],
							],
							'structureStats'            => [
								'blockCount'         => 6,
								'maxDepth'           => 3,
								'topLevelBlockCount' => 1,
								'hasNavigation'      => false,
								'hasQuery'           => true,
								'hasTemplateParts'   => false,
								'firstTopLevelBlock' => 'core/query',
								'lastTopLevelBlock'  => 'core/query',
							],
							'currentPatternOverrides'   => [
								'hasOverrides' => true,
								'blockCount'   => 1,
								'blockNames'   => [ 'core/query' ],
								'blocks'       => [
									[
										'path'  => [ 0 ],
										'name'  => 'core/query',
										'label' => 'Query Loop',
										'overrideAttributes' => [ 'query' ],
										'usesDefaultBinding' => false,
									],
								],
							],
							'currentViewportVisibility' => [
								'hasVisibilityRules' => true,
								'blockCount'         => 1,
								'blocks'             => [
									[
										'path'             => [ 0 ],
										'name'             => 'core/query',
										'label'            => 'Query Loop',
										'hiddenViewports'  => [ 'mobile' ],
										'visibleViewports' => [ 'desktop' ],
									],
								],
							],
						]
					),
					'resolveSignatureOnly' => true,
				]
			)
		);

		$this->assertIsArray( $baseline );
		$this->assertIsArray( $changed );
		$this->assertSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
	}

	public function test_recommend_template_part_review_signature_ignores_visible_pattern_scope_changes(): void {
		$baseline = TemplateAbilities::recommend_template_part(
			array_merge(
				$this->build_template_part_recommendation_input(),
				[
					'visiblePatternNames'  => [ 'theme/header-utility' ],
					'resolveSignatureOnly' => true,
				]
			)
		);
		$changed  = TemplateAbilities::recommend_template_part(
			array_merge(
				$this->build_template_part_recommendation_input(),
				[
					'visiblePatternNames'  => [ 'theme/hero' ],
					'resolveSignatureOnly' => true,
				]
			)
		);

		$this->assertIsArray( $baseline );
		$this->assertIsArray( $changed );
		$this->assertSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
	}

	public function test_recommend_template_part_review_signature_ignores_live_structure_overlays(): void {
		$baseline = TemplateAbilities::recommend_template_part(
			array_merge(
				$this->build_template_part_recommendation_input(),
				[
					'editorStructure'      => $this->build_template_part_editor_structure_payload(),
					'resolveSignatureOnly' => true,
				]
			)
		);
		$changed  = TemplateAbilities::recommend_template_part(
			array_merge(
				$this->build_template_part_recommendation_input(),
				[
					'editorStructure'      => $this->build_template_part_editor_structure_payload(
						[
							'blockTree'               => [
								[
									'path'       => [ 0 ],
									'name'       => 'core/navigation',
									'label'      => 'Navigation',
									'attributes' => [
										'overlayMenu' => 'mobile',
									],
									'childCount' => 0,
									'children'   => [],
								],
							],
							'allBlockPaths'           => [
								[
									'path'       => [ 0 ],
									'name'       => 'core/navigation',
									'label'      => 'Navigation',
									'attributes' => [
										'overlayMenu' => 'mobile',
									],
									'childCount' => 0,
								],
							],
							'topLevelBlocks'          => [ 'core/navigation' ],
							'blockCounts'             => [
								'core/navigation' => 1,
							],
							'structureStats'          => [
								'blockCount'            => 1,
								'maxDepth'              => 1,
								'hasNavigation'         => true,
								'containsLogo'          => false,
								'containsSiteTitle'     => false,
								'containsSearch'        => false,
								'containsSocialLinks'   => false,
								'containsQuery'         => false,
								'containsColumns'       => false,
								'containsButtons'       => false,
								'containsSpacer'        => false,
								'containsSeparator'     => false,
								'firstTopLevelBlock'    => 'core/navigation',
								'lastTopLevelBlock'     => 'core/navigation',
								'hasSingleWrapperGroup' => false,
								'isNearlyEmpty'         => true,
							],
							'currentPatternOverrides' => [
								'hasOverrides' => false,
								'blockCount'   => 0,
								'blockNames'   => [],
								'blocks'       => [],
							],
							'operationTargets'        => [
								[
									'path'              => [ 0 ],
									'name'              => 'core/navigation',
									'label'             => 'Navigation',
									'allowedOperations' => [ 'remove_block' ],
									'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
								],
							],
							'insertionAnchors'        => [
								[
									'placement' => 'start',
									'label'     => 'Start of template part',
								],
								[
									'placement'  => 'before_block_path',
									'targetPath' => [ 0 ],
									'blockName'  => 'core/navigation',
									'label'      => 'Before Navigation',
								],
								[
									'placement' => 'end',
									'label'     => 'End of template part',
								],
							],
							'structuralConstraints'   => [
								'contentOnlyPaths' => [],
								'lockedPaths'      => [],
								'hasContentOnly'   => false,
								'hasLockedBlocks'  => false,
							],
						]
					),
					'resolveSignatureOnly' => true,
				]
			)
		);

		$this->assertIsArray( $baseline );
		$this->assertIsArray( $changed );
		$this->assertSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
	}

	/**
	 * @dataProvider template_review_theme_token_signature_provider
	 */
	public function test_template_review_signature_changes_when_formatter_relevant_theme_tokens_change( array $baseline_theme_tokens, array $changed_theme_tokens ): void {
		$baseline_signature = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				[
					'patterns'    => [],
					'themeTokens' => $baseline_theme_tokens,
				],
			]
		);
		$changed_signature  = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				[
					'patterns'    => [],
					'themeTokens' => $changed_theme_tokens,
				],
			]
		);

		$this->assertNotSame( $baseline_signature, $changed_signature );
	}

	/**
	 * @dataProvider template_review_theme_token_signature_provider
	 */
	public function test_template_part_review_signature_changes_when_formatter_relevant_theme_tokens_change( array $baseline_theme_tokens, array $changed_theme_tokens ): void {
		$baseline_signature = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_part_review_context_signature',
			[
				[
					'patterns'    => [],
					'themeTokens' => $baseline_theme_tokens,
				],
			]
		);
		$changed_signature  = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_part_review_context_signature',
			[
				[
					'patterns'    => [],
					'themeTokens' => $changed_theme_tokens,
				],
			]
		);

		$this->assertNotSame( $baseline_signature, $changed_signature );
	}

	public function test_template_review_signature_ignores_theme_token_changes_removed_by_formatter_budget(): void {
		$baseline_signature = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				[
					'patterns'    => [],
					'themeTokens' => [
						'layout'          => [
							'content' => str_repeat( 'x', 2100 ),
						],
						'enabledFeatures' => [
							'backgroundColor' => true,
						],
					],
				],
			]
		);
		$changed_signature  = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				[
					'patterns'    => [],
					'themeTokens' => [
						'layout'          => [
							'content' => str_repeat( 'y', 2100 ),
						],
						'enabledFeatures' => [
							'backgroundColor' => true,
						],
					],
				],
			]
		);

		$this->assertSame( $baseline_signature, $changed_signature );
	}

	public function test_template_review_signature_changes_when_server_template_inventory_changes(): void {
		$baseline_signature = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				$this->build_template_review_signature_context(),
			]
		);
		$changed_signature  = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				$this->build_template_review_signature_context(
					[
						'allowedAreas'   => [ 'footer', 'header' ],
						'availableParts' => [
							[
								'slug'  => 'site-header',
								'title' => 'Site Header',
								'area'  => 'header',
							],
							[
								'slug'  => 'footer-secondary',
								'title' => 'Footer Secondary',
								'area'  => 'footer',
							],
						],
					]
				),
			]
		);

		$this->assertNotSame( $baseline_signature, $changed_signature );
	}

	public function test_template_review_signature_changes_when_template_identity_changes(): void {
		$baseline_signature = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				$this->build_template_review_signature_context(),
			]
		);
		$changed_signature  = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				$this->build_template_review_signature_context(
					[
						'templateType' => 'front-page',
						'title'        => 'Front Page',
					]
				),
			]
		);

		$this->assertNotSame( $baseline_signature, $changed_signature );
	}

	public function test_template_part_review_signature_changes_when_template_part_identity_changes(): void {
		$baseline_signature = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_part_review_context_signature',
			[
				$this->build_template_part_review_signature_context(),
			]
		);
		$changed_signature  = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_part_review_context_signature',
			[
				$this->build_template_part_review_signature_context(
					[
						'title' => 'Utility Header',
						'area'  => 'navigation-overlay',
					]
				),
			]
		);

		$this->assertNotSame( $baseline_signature, $changed_signature );
	}

	/**
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function template_review_theme_token_signature_provider(): array {
		return [
			'gradients'          => [
				[],
				[
					'gradients' => [ 'hero: linear-gradient(180deg, #fff, #ddd)' ],
				],
			],
			'shadows'            => [
				[],
				[
					'shadows' => [ 'natural: 6px 6px 9px rgba(0,0,0,0.2)' ],
				],
			],
			'preset refs'        => [
				[],
				[
					'colorPresets' => [
						[
							'slug'   => 'accent',
							'cssVar' => 'var(--wp--preset--color--accent)',
						],
					],
				],
			],
			'layout'             => [
				[],
				[
					'layout' => [
						'content'                       => '650px',
						'wide'                          => '1200px',
						'allowEditing'                  => true,
						'allowCustomContentAndWideSize' => true,
					],
				],
			],
			'enabled features'   => [
				[],
				[
					'enabledFeatures' => [
						'backgroundColor' => true,
						'textColor'       => true,
					],
				],
			],
			'element style keys' => [
				[],
				[
					'elementStyles' => [
						'link'   => [ 'color' => [ 'text' => '#ff5500' ] ],
						'button' => [ 'color' => [ 'text' => '#ffffff' ] ],
					],
				],
			],
		];
	}

	private function register_pattern( string $name, array $properties ): void {
		\WP_Block_Patterns_Registry::get_instance()->register(
			$name,
			$properties
		);
	}

	private function build_cache_key( string $query, int $max_results ): string {
		$method = new ReflectionMethod( AISearchClient::class, 'build_cache_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $query, $max_results );

		$this->assertIsString( $result );

		return $result;
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function build_template_review_signature_context( array $overrides = [] ): array {
		return array_merge(
			[
				'templateType'   => 'home',
				'title'          => 'Home',
				'assignedParts'  => [
					[
						'slug' => 'site-header',
						'area' => 'header',
					],
				],
				'allowedAreas'   => [ 'header' ],
				'availableParts' => [
					[
						'slug'  => 'site-header',
						'title' => 'Site Header',
						'area'  => 'header',
					],
					[
						'slug'  => 'footer-main',
						'title' => 'Footer Main',
						'area'  => 'footer',
					],
				],
				'patterns'       => [],
				'themeTokens'    => [],
			],
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function build_template_part_review_signature_context( array $overrides = [] ): array {
		return array_merge(
			[
				'slug'        => 'header',
				'title'       => 'Header',
				'area'        => 'header',
				'patterns'    => [],
				'themeTokens' => [],
			],
			$overrides
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_template_recommendation_input(): array {
		return [
			'templateRef'  => 'theme//home',
			'templateType' => 'home',
			'prompt'       => 'Make the home template feel more editorial.',
		];
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function build_template_editor_slots_payload( array $overrides = [] ): array {
		return array_merge(
			[
				'assignedParts' => [
					[
						'slug' => 'site-header',
						'area' => 'header',
					],
				],
				'emptyAreas'    => [ 'footer' ],
				'allowedAreas'  => [ 'header', 'footer' ],
			],
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function build_template_editor_structure_payload( array $overrides = [] ): array {
		return array_merge(
			[
				'topLevelBlockTree'         => [
					[
						'path'       => [ 0 ],
						'name'       => 'core/group',
						'label'      => 'Group',
						'attributes' => [
							'tagName' => 'main',
						],
						'childCount' => 2,
					],
				],
				'structureStats'            => [
					'blockCount'         => 4,
					'maxDepth'           => 2,
					'topLevelBlockCount' => 1,
					'hasNavigation'      => false,
					'hasQuery'           => false,
					'hasTemplateParts'   => true,
					'firstTopLevelBlock' => 'core/group',
					'lastTopLevelBlock'  => 'core/group',
				],
				'currentPatternOverrides'   => [
					'hasOverrides' => false,
					'blockCount'   => 0,
					'blockNames'   => [],
					'blocks'       => [],
				],
				'currentViewportVisibility' => [
					'hasVisibilityRules' => false,
					'blockCount'         => 0,
					'blocks'             => [],
				],
			],
			$overrides
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_template_part_recommendation_input(): array {
		return [
			'templatePartRef' => 'theme//header',
			'prompt'          => 'Give the header a cleaner utility rhythm.',
		];
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function build_template_part_editor_structure_payload( array $overrides = [] ): array {
		return array_merge(
			[
				'blockTree'               => [
					[
						'path'       => [ 0 ],
						'name'       => 'core/group',
						'label'      => 'Group',
						'attributes' => [
							'tagName' => 'header',
						],
						'childCount' => 1,
						'children'   => [
							[
								'path'       => [ 0, 0 ],
								'name'       => 'core/site-title',
								'label'      => 'Site Title',
								'attributes' => [],
								'childCount' => 0,
								'children'   => [],
							],
						],
					],
				],
				'allBlockPaths'           => [
					[
						'path'       => [ 0 ],
						'name'       => 'core/group',
						'label'      => 'Group',
						'attributes' => [
							'tagName' => 'header',
						],
						'childCount' => 1,
					],
					[
						'path'       => [ 0, 0 ],
						'name'       => 'core/site-title',
						'label'      => 'Site Title',
						'attributes' => [],
						'childCount' => 0,
					],
				],
				'topLevelBlocks'          => [ 'core/group' ],
				'blockCounts'             => [
					'core/group'      => 1,
					'core/site-title' => 1,
				],
				'structureStats'          => [
					'blockCount'            => 2,
					'maxDepth'              => 2,
					'hasNavigation'         => false,
					'containsLogo'          => false,
					'containsSiteTitle'     => true,
					'containsSearch'        => false,
					'containsSocialLinks'   => false,
					'containsQuery'         => false,
					'containsColumns'       => false,
					'containsButtons'       => false,
					'containsSpacer'        => false,
					'containsSeparator'     => false,
					'firstTopLevelBlock'    => 'core/group',
					'lastTopLevelBlock'     => 'core/group',
					'hasSingleWrapperGroup' => true,
					'isNearlyEmpty'         => false,
				],
				'currentPatternOverrides' => [
					'hasOverrides' => false,
					'blockCount'   => 0,
					'blockNames'   => [],
					'blocks'       => [],
				],
				'operationTargets'        => [
					[
						'path'              => [ 0 ],
						'name'              => 'core/group',
						'label'             => 'Group',
						'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
						'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
					],
				],
				'insertionAnchors'        => [
					[
						'placement' => 'start',
						'label'     => 'Start of template part',
					],
					[
						'placement'  => 'before_block_path',
						'targetPath' => [ 0 ],
						'blockName'  => 'core/group',
						'label'      => 'Before Group',
					],
					[
						'placement' => 'end',
						'label'     => 'End of template part',
					],
				],
				'structuralConstraints'   => [
					'contentOnlyPaths' => [],
					'lockedPaths'      => [],
					'hasContentOnly'   => false,
					'hasLockedBlocks'  => false,
				],
			],
			$overrides
		);
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
}
