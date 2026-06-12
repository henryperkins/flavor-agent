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

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->prime_current_docs_source_coverage();
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

	public function test_list_templates_can_omit_or_include_content_for_theme_editors(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
		];

		$metadata_only = TemplateAbilities::list_templates( [] );
		$with_content  = TemplateAbilities::list_templates(
			[
				'includeContent' => true,
			]
		);

		$this->assertCount( 1, $metadata_only['templates'] );
		$this->assertArrayNotHasKey( 'content', $metadata_only['templates'][0] );
		// The id is the templateRef recommend-template accepts, closing the
		// external-agent template discovery loop (list-template-parts parallel).
		$this->assertSame( 'theme//home', $metadata_only['templates'][0]['id'] );
		$this->assertSame( 'home', $metadata_only['templates'][0]['slug'] );
		$this->assertSame( 'Home', $metadata_only['templates'][0]['title'] );
		$this->assertSame(
			'<!-- wp:group {"tagName":"main"} --><div>Main</div><!-- /wp:group -->',
			$with_content['templates'][0]['content']
		);
	}

	public function test_list_templates_coerces_include_content_to_metadata_for_editors_without_theme_access(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		$result = TemplateAbilities::list_templates(
			[
				'includeContent' => true,
			]
		);

		$this->assertCount( 1, $result['templates'] );
		$this->assertSame( 'theme//home', $result['templates'][0]['id'] );
		$this->assertArrayNotHasKey( 'content', $result['templates'][0] );
	}

	public function test_list_templates_casts_non_string_fields_to_strings(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
		];
		// get_block_templates() output is filterable, so fields are not
		// guaranteed to be strings.
		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'          => 42,
				'slug'        => 7,
				'title'       => 99,
				'description' => null,
				'content'     => 123,
			],
		];

		$result = TemplateAbilities::list_templates( [ 'includeContent' => true ] );

		$this->assertSame( '42', $result['templates'][0]['id'] );
		$this->assertSame( '7', $result['templates'][0]['slug'] );
		$this->assertSame( '99', $result['templates'][0]['title'] );
		$this->assertSame( '', $result['templates'][0]['description'] );
		$this->assertSame( '123', $result['templates'][0]['content'] );
	}

	public function test_list_template_parts_casts_non_string_fields_to_strings(): void {
		WordPressTestState::$capabilities                        = [
			'edit_theme_options' => true,
		];
		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'id'      => 'theme//header',
				'slug'    => 8,
				'title'   => 100,
				'area'    => 'header',
				'content' => 456,
			],
		];

		$result = TemplateAbilities::list_template_parts( [ 'includeContent' => true ] );

		$this->assertSame( '8', $result['templateParts'][0]['slug'] );
		$this->assertSame( '100', $result['templateParts'][0]['title'] );
		$this->assertSame( 'header', $result['templateParts'][0]['area'] );
		$this->assertSame( '456', $result['templateParts'][0]['content'] );
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
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $result['reviewContextSignature'] ?? '' )
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $result['resolvedContextSignature'] ?? '' )
		);
		$this->assertSame( 'unavailable', $result['docsGrounding']['status'] ?? null );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $result['docsGroundingFingerprint'] ?? '' )
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_recommend_template_review_and_resolved_signatures_include_docs_guidance_changes(): void {
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
		$this->assertNotSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['docsGroundingFingerprint'] ?? null,
			$changed['docsGroundingFingerprint'] ?? null
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_recommend_template_review_and_resolved_signatures_include_design_semantics(): void {
		$baseline  = TemplateAbilities::recommend_template(
			[
				'templateRef'          => 'theme//home',
				'templateType'         => 'home',
				'prompt'               => 'Make the home template feel more editorial.',
				'visiblePatternNames'  => [ 'theme/hero' ],
				'designSemantics'      => [
					'surface'      => 'template',
					'sectionRole'  => 'archive-list',
					'layoutRhythm' => 'grid',
				],
				'resolveSignatureOnly' => true,
			]
		);
		$reordered = TemplateAbilities::recommend_template(
			[
				'templateRef'          => 'theme//home',
				'templateType'         => 'home',
				'prompt'               => 'Make the home template feel more editorial.',
				'visiblePatternNames'  => [ 'theme/hero' ],
				'designSemantics'      => [
					'layoutRhythm' => 'grid',
					'sectionRole'  => 'archive-list',
					'surface'      => 'template',
				],
				'resolveSignatureOnly' => true,
			]
		);
		$changed   = TemplateAbilities::recommend_template(
			[
				'templateRef'          => 'theme//home',
				'templateType'         => 'home',
				'prompt'               => 'Make the home template feel more editorial.',
				'visiblePatternNames'  => [ 'theme/hero' ],
				'designSemantics'      => [
					'surface'      => 'template',
					'sectionRole'  => 'archive-list',
					'layoutRhythm' => 'stacked',
				],
				'resolveSignatureOnly' => true,
			]
		);

		$this->assertIsArray( $baseline );
		$this->assertIsArray( $reordered );
		$this->assertIsArray( $changed );
		$this->assertSame(
			$baseline['resolvedContextSignature'] ?? null,
			$reordered['resolvedContextSignature'] ?? null
		);
		$this->assertSame(
			$baseline['reviewContextSignature'] ?? null,
			$reordered['reviewContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
	}

	public function test_recommend_template_signatures_are_stable_between_recommendation_and_signature_modes(): void {
		$this->configure_text_generation_connector();
		AISearchClient::cache_entity_guidance(
			'template:home',
			[
				[
					'id'          => 'template-home-current-doc',
					'title'       => 'Home template guidance',
					'sourceKey'   => 'developer.wordpress.org/themes/templates/introduction-to-templates/',
					'sourceType'  => 'developer-docs',
					'url'         => 'https://developer.wordpress.org/themes/templates/introduction-to-templates/',
					'excerpt'     => 'Use template structure to reinforce the hierarchy of the page.',
					'score'       => 0.91,
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'publishedAt' => '',
					'contentHash' => 'template-home-current-doc',
					'freshness'   => 'current',
				],
			]
		);
		WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Use the hero pattern',
						'description'        => 'Keep the home template focused on one clear lead section.',
						'operations'         => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
								'placement'   => 'end',
							],
						],
						'templateParts'      => [],
						'patternSuggestions' => [ 'theme/hero' ],
						'confidence'         => 0.8,
					],
				],
				'explanation' => 'Grounded template response.',
			]
		);

		$input = [
			'templateRef'         => 'theme//home',
			'templateType'        => 'home',
			'prompt'              => 'Make the home template feel more editorial.',
			'visiblePatternNames' => [ 'theme/hero' ],
		];

		$recommendation = TemplateAbilities::recommend_template( $input );
		$signature      = TemplateAbilities::recommend_template(
			array_merge(
				$input,
				[
					'resolveSignatureOnly' => true,
				]
			)
		);

		$this->assertIsArray( $recommendation );
		$this->assertIsArray( $signature );
		$this->assertSame( 'grounded', $recommendation['docsGrounding']['status'] ?? null );
		$this->assertSame( 'grounded', $signature['docsGrounding']['status'] ?? null );
		$this->assertSame(
			$recommendation['reviewContextSignature'] ?? null,
			$signature['reviewContextSignature'] ?? null
		);
		$this->assertSame(
			$recommendation['resolvedContextSignature'] ?? null,
			$signature['resolvedContextSignature'] ?? null
		);
		$this->assertSame(
			$recommendation['docsGroundingFingerprint'] ?? null,
			$signature['docsGroundingFingerprint'] ?? null
		);
	}

	public function test_recommend_template_part_review_and_resolved_signatures_include_docs_guidance_changes(): void {
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
		$this->assertNotSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['docsGroundingFingerprint'] ?? null,
			$changed['docsGroundingFingerprint'] ?? null
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_recommend_template_part_review_and_resolved_signatures_include_design_semantics(): void {
		$baseline  = TemplateAbilities::recommend_template_part(
			[
				'templatePartRef'      => 'theme//header',
				'prompt'               => 'Tighten the header utility area.',
				'visiblePatternNames'  => [ 'theme/header-utility' ],
				'designSemantics'      => [
					'surface'         => 'template-part',
					'sectionRole'     => 'footer',
					'contrastContext' => 'dark-parent',
					'templatePart'    => [
						'ref'  => 'theme//header',
						'slug' => 'header',
					],
				],
				'resolveSignatureOnly' => true,
			]
		);
		$reordered = TemplateAbilities::recommend_template_part(
			[
				'templatePartRef'      => 'theme//header',
				'prompt'               => 'Tighten the header utility area.',
				'visiblePatternNames'  => [ 'theme/header-utility' ],
				'designSemantics'      => [
					'templatePart'    => [
						'slug' => 'header',
						'ref'  => 'theme//header',
					],
					'contrastContext' => 'dark-parent',
					'sectionRole'     => 'footer',
					'surface'         => 'template-part',
				],
				'resolveSignatureOnly' => true,
			]
		);
		$changed   = TemplateAbilities::recommend_template_part(
			[
				'templatePartRef'      => 'theme//header',
				'prompt'               => 'Tighten the header utility area.',
				'visiblePatternNames'  => [ 'theme/header-utility' ],
				'designSemantics'      => [
					'surface'         => 'template-part',
					'sectionRole'     => 'footer',
					'contrastContext' => 'light-parent',
					'templatePart'    => [
						'ref'  => 'theme//header',
						'slug' => 'header',
					],
				],
				'resolveSignatureOnly' => true,
			]
		);

		$this->assertIsArray( $baseline );
		$this->assertIsArray( $reordered );
		$this->assertIsArray( $changed );
		$this->assertSame(
			$baseline['resolvedContextSignature'] ?? null,
			$reordered['resolvedContextSignature'] ?? null
		);
		$this->assertSame(
			$baseline['reviewContextSignature'] ?? null,
			$reordered['reviewContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
	}

	public function test_recommend_template_fails_closed_when_docs_grounding_is_unavailable(): void {
		$this->configure_text_generation_connector();
		WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'              => 'Use the hero pattern',
						'description'        => 'Would have called the model without the grounding gate.',
						'operations'         => [
							[
								'type'        => 'insert_pattern',
								'patternName' => 'theme/hero',
								'placement'   => 'end',
							],
						],
						'templateParts'      => [],
						'patternSuggestions' => [ 'theme/hero' ],
						'confidence'         => 0.8,
					],
				],
				'explanation' => 'Grounding gate regression fixture.',
			]
		);

		$result = TemplateAbilities::recommend_template(
			[
				'templateRef'         => 'theme//home',
				'templateType'        => 'home',
				'prompt'              => 'Make the home template feel more editorial.',
				'visiblePatternNames' => [ 'theme/hero' ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_docs_grounding_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] ?? null );
		$this->assertSame( 'unavailable', $result->get_error_data()['docsGrounding']['status'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_ai_client_prompt );
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

	private function configure_text_generation_connector(): void {
		WordPressTestState::$options                    = array_merge(
			WordPressTestState::$options,
			[
				'flavor_agent_openai_provider' => 'openai',
			]
		);
		WordPressTestState::$connectors                 = [
			'openai' => [
				'name'           => 'OpenAI',
				'description'    => 'OpenAI connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_provider_support = [
			'openai' => true,
		];
		WordPressTestState::$ai_client_supported        = true;
	}

	private function prime_current_docs_source_coverage(): void {
		WordPressTestState::$transients['flavor_agent_docs_source_coverage_v2'] = [
			'status'                 => 'current',
			'hasDeveloperDocs'       => true,
			'hasCurrentReleaseCycle' => true,
			'sourceTypes'            => [ 'developer-docs', 'make-core' ],
			'freshness'              => [ 'current' ],
			'checkedAt'              => '2026-05-11 00:00:00',
			'errorCode'              => '',
			'errorMessage'           => '',
		];
	}
}
