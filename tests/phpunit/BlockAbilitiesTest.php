<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\BlockAbilities;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class BlockAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->register_paragraph_block();
		$this->prime_default_docs_grounding();
	}

	public function test_prepare_recommend_block_input_normalizes_editor_context_payload(): void {
		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'prompt'        => "Add more contrast\n<script>alert('x')</script>",
				'editorContext' => (object) [
					'block'               => (object) [
						'name'                => 'core/paragraph',
						'currentAttributes'   => (object) [
							'content'   => '<strong>Hello</strong>',
							'className' => 'is-style-outline',
						],
						'blockPath'           => [ 0, '1', -1, 'bad' ],
						'isInsideContentOnly' => true,
						'editingMode'         => 'disabled',
						'blockVisibility'     => (object) [
							'viewport' => (object) [
								'mobile'  => false,
								'desktop' => true,
							],
						],
						'title'               => 'Paragraph <em>Block</em>',
						'inspectorPanels'     => (object) [
							'color' => [ 'color.background', 'color.text' ],
						],
						'styles'              => [
							(object) [
								'name'      => 'outline',
								'label'     => 'Outline',
								'isDefault' => false,
							],
						],
						'activeStyle'         => 'outline',
						'childCount'          => 2,
						'variations'          => [
							(object) [
								'name'  => 'intro',
								'title' => 'Intro',
							],
						],
						'contentAttributes'   => (object) [
							'content' => (object) [
								'type' => 'string',
								'role' => 'content',
							],
						],
						'configAttributes'    => (object) [
							'dropCap' => (object) [
								'type' => 'boolean',
							],
						],
						'structuralIdentity'  => (object) [
							'role'             => 'footer-paragraph',
							'job'              => 'Paragraph block in the "footer" template part.',
							'location'         => 'footer',
							'templateArea'     => 'footer',
							'templatePartSlug' => 'footer',
							'position'         => (object) [
								'depth'               => 3,
								'siblingIndex'        => 1,
								'siblingCount'        => 2,
								'sameTypeIndex'       => 1,
								'sameTypeCount'       => 1,
								'typeOrderInLocation' => 1,
							],
							'evidence'         => [ 'ancestor-template-part' ],
						],
					],
					'siblingsBefore'      => [ 'core/heading', '', 'core/image', 'core/heading' ],
					'siblingsAfter'       => (object) [ 'core/buttons', '' ],
					'structuralAncestors' => [
						(object) [
							'block'        => 'core/template-part',
							'role'         => 'footer-slot',
							'job'          => 'Footer template-part slot.',
							'location'     => 'footer',
							'templateArea' => 'footer',
						],
					],
					'structuralBranch'    => [
						(object) [
							'block'    => 'core/template-part',
							'role'     => 'footer-slot',
							'children' => [
								(object) [
									'block'      => 'core/paragraph',
									'role'       => 'footer-paragraph',
									'isSelected' => true,
								],
							],
						],
					],
					'themeTokens'         => (object) [
						'colors'            => [ 'accent: #f00', '', 'accent: #f00' ],
						'duotone'           => [ 'midnight: #111111 / #f5f5f5' ],
						'duotonePresets'    => [
							(object) [
								'slug'   => 'midnight',
								'colors' => [ '#111111', '#f5f5f5' ],
							],
						],
						'fontSizes'         => [ 'small: 12px' ],
						'layout'            => (object) [
							'content'      => '640px',
							'wide'         => '1200px',
							'allowEditing' => false,
						],
						'enabledFeatures'   => (object) [
							'lineHeight'      => true,
							'backgroundImage' => true,
						],
						'elementStyles'     => (object) [
							'button' => (object) [
								'base' => (object) [
									'text' => 'var(--wp--preset--color--contrast)',
								],
							],
						],
						'blockPseudoStyles' => (object) [
							'core/button' => (object) [
								':hover' => (object) [
									'color' => (object) [
										'text' => 'var(--wp--preset--color--base)',
									],
								],
							],
						],
					],
					'designSemantics'     => (object) [
						'surface'         => 'block',
						'sectionRole'     => 'footer',
						'contrastContext' => 'dark-parent',
						'layoutRhythm'    => 'constrained',
						'tokenAffinity'   => (object) [
							'color' => [ 'contrast', 'base', 'contrast' ],
						],
						'block'           => (object) [
							'name'        => 'core/paragraph',
							'unsupported' => [ 'drop' ],
						],
					],
				],
			]
		);

		$this->assertSame(
			sanitize_textarea_field( "Add more contrast\n<script>alert('x')</script>" ),
			$prepared['prompt']
		);
		$this->assertSame( 'core/paragraph', $prepared['context']['block']['name'] );
		$this->assertSame( [ 0, 1 ], $prepared['context']['block']['blockPath'] ?? null );
		$this->assertTrue( $prepared['context']['block']['isInsideContentOnly'] );
		$this->assertSame( 'disabled', $prepared['context']['block']['editingMode'] );
		$this->assertSame( 'Paragraph Block', $prepared['context']['block']['title'] );
		$this->assertSame( [ 'core/heading', 'core/image' ], $prepared['context']['siblingsBefore'] );
		$this->assertSame( [ 'core/buttons' ], $prepared['context']['siblingsAfter'] );
		$this->assertSame(
			[
				'viewport' => [
					'mobile'  => false,
					'desktop' => true,
				],
			],
			$prepared['context']['block']['currentAttributes']['metadata']['blockVisibility']
		);
			$this->assertSame( 'outline', $prepared['context']['block']['activeStyle'] );
			$this->assertSame( 2, $prepared['context']['block']['childCount'] );
			$this->assertSame(
				[ 'color' => [ 'color.background', 'color.text' ] ],
				$prepared['context']['block']['inspectorPanels']
			);
			$this->assertSame( [ 'accent: #f00' ], $prepared['context']['themeTokens']['colors'] );
			$this->assertSame( [ 'midnight: #111111 / #f5f5f5' ], $prepared['context']['themeTokens']['duotone'] );
			$this->assertSame( 'midnight', $prepared['context']['themeTokens']['duotonePresets'][0]['slug'] );
			$this->assertTrue( $prepared['context']['themeTokens']['enabledFeatures']['backgroundImage'] );
			$this->assertSame( 'var(--wp--preset--color--contrast)', $prepared['context']['themeTokens']['elementStyles']['button']['base']['text'] );
			$this->assertSame( 'footer-paragraph', $prepared['context']['block']['structuralIdentity']['role'] );
			$this->assertSame( 'footer-slot', $prepared['context']['structuralAncestors'][0]['role'] );
			$this->assertSame( 'footer-slot', $prepared['context']['structuralBranch'][0]['role'] );
			$this->assertSame( 'block', $prepared['context']['designSemantics']['surface'] ?? null );
			$this->assertSame( 'footer', $prepared['context']['designSemantics']['sectionRole'] ?? null );
			$this->assertSame( 'dark-parent', $prepared['context']['designSemantics']['contrastContext'] ?? null );
			$this->assertSame( [ 'base', 'contrast' ], $prepared['context']['designSemantics']['tokenAffinity']['color'] ?? null );
			$this->assertArrayNotHasKey( 'unsupported', $prepared['context']['designSemantics']['block'] ?? [] );
	}

	public function test_prepare_recommend_block_input_normalizes_selected_block_payload(): void {
		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'selectedBlock' => [
					'blockName'           => 'core/paragraph',
					'attributes'          => [
						'content'   => 'Hello world',
						'className' => 'is-style-outline',
					],
					'editingMode'         => 'contentOnly',
					'isInsideContentOnly' => false,
					'blockVisibility'     => [
						'viewport' => [
							'mobile' => false,
						],
					],
				],
			]
		);

		$this->assertSame( '', $prepared['prompt'] );
		$this->assertSame( 'core/paragraph', $prepared['context']['block']['name'] );
		$this->assertSame( 'contentOnly', $prepared['context']['block']['editingMode'] );
		$this->assertSame(
			[
				'viewport' => [
					'mobile' => false,
				],
			],
			$prepared['context']['block']['currentAttributes']['metadata']['blockVisibility']
		);
		$this->assertSame( 'outline', $prepared['context']['block']['activeStyle'] );
		$this->assertSame( [ 'accent: #f00' ], $prepared['context']['themeTokens']['colors'] );
		$this->assertSame( [ 'small: 12px' ], $prepared['context']['themeTokens']['fontSizes'] );
		$this->assertSame( [ 'midnight: #111111 / #f5f5f5' ], $prepared['context']['themeTokens']['duotone'] );
		$this->assertSame( 'midnight', $prepared['context']['themeTokens']['duotonePresets'][0]['slug'] );
		$this->assertFalse( $prepared['context']['themeTokens']['layout']['allowEditing'] );
		$this->assertTrue( $prepared['context']['themeTokens']['enabledFeatures']['backgroundImage'] );
		$this->assertSame( 'var(--wp--preset--color--contrast)', $prepared['context']['themeTokens']['elementStyles']['button']['base']['text'] );
		$this->assertSame( [ 'content' ], $prepared['context']['block']['bindableAttributes'] );
		$this->assertSame( [ 'content' ], $prepared['context']['block']['inspectorPanels']['bindings'] );
	}

	public function test_prepare_recommend_block_input_preserves_explicit_empty_bindable_attributes_from_editor_context(): void {
		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block' => [
						'name'               => 'core/paragraph',
						'currentAttributes'  => [
							'content' => 'Hello world',
						],
						'bindableAttributes' => [],
					],
				],
			]
		);

		$this->assertSame( [], $prepared['context']['block']['bindableAttributes'] );
		$this->assertArrayNotHasKey( 'bindings', $prepared['context']['block']['inspectorPanels'] );
	}

	public function test_prepare_recommend_block_input_preserves_parent_and_sibling_summaries_from_editor_context(): void {
		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block'                  => [
						'name'              => 'core/paragraph',
						'currentAttributes' => [],
					],
					'parentContext'          => [
						'block'       => 'core/cover',
						'title'       => 'Header Cover',
						'role'        => 'header-cover',
						'job'         => 'Cover in header',
						'childCount'  => 5,
						'visualHints' => [
							'backgroundColor' => 'contrast',
							'layout'          => [
								'type'           => 'constrained',
								'justifyContent' => 'center',
							],
							'unknown'         => 'drop-me',
						],
					],
					'siblingSummariesBefore' => [
						[
							'block'       => 'core/heading',
							'role'        => 'lede-heading',
							'visualHints' => [
								'align'       => 'wide',
								'style'       => [ 'color' => [ 'text' => 'var(--wp--preset--color--contrast)' ] ],
								'unsupported' => 'ignore',
							],
						],
						[
							'block' => '',
							'role'  => 'skip-me',
						],
						[ 'block' => 'core/image' ],
						[ 'block' => 'core/separator' ],
					],
					'siblingSummariesAfter'  => [
						[
							'block'       => 'core/buttons',
							'visualHints' => [ 'textAlign' => 'center' ],
						],
					],
				],
			]
		);

		$this->assertSame(
			[
				'block'       => 'core/cover',
				'title'       => 'Header Cover',
				'role'        => 'header-cover',
				'job'         => 'Cover in header',
				'childCount'  => 5,
				'visualHints' => [
					'backgroundColor' => 'contrast',
					'layout'          => [
						'type'           => 'constrained',
						'justifyContent' => 'center',
					],
				],
			],
			$prepared['context']['parentContext']
		);
		$this->assertSame(
			[
				[
					'block'       => 'core/heading',
					'role'        => 'lede-heading',
					'visualHints' => [
						'align' => 'wide',
						'style' => [ 'color' => [ 'text' => 'var(--wp--preset--color--contrast)' ] ],
					],
				],
				[
					'block' => 'core/image',
				],
			],
			$prepared['context']['siblingSummariesBefore']
		);
		$this->assertSame(
			[
				[
					'block'       => 'core/buttons',
					'visualHints' => [ 'textAlign' => 'center' ],
				],
			],
			$prepared['context']['siblingSummariesAfter']
		);
	}

	public function test_prepare_recommend_block_input_normalizes_parent_and_sibling_context_from_selected_block(): void {
		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'selectedBlock' => [
					'blockName'              => 'core/paragraph',
					'attributes'             => [],
					'parentContext'          => [
						'block'       => 'core/group',
						'visualHints' => [
							'backgroundColor' => 'base',
							'tagName'         => 'header',
							'minHeight'       => 80,
							'minHeightUnit'   => 'vh',
							'extra'           => 'drop',
						],
					],
					'siblingSummariesBefore' => [
						[
							'block'       => 'core/heading',
							'visualHints' => [
								'textAlign' => 'center',
								'unknown'   => 'x',
							],
						],
						[
							'block' => 'core/image',
							'role'  => '<script>alert(1)</script>',
						],
					],
					'siblingSummariesAfter'  => [
						[
							'block'       => 'core/buttons',
							'visualHints' => [ 'align' => 'wide' ],
						],
						[ 'block' => 'core/separator' ],
						[ 'block' => 'core/list' ],
					],
				],
			]
		);

		$this->assertSame(
			[
				'block'       => 'core/group',
				'visualHints' => [
					'backgroundColor' => 'base',
					'minHeight'       => 80,
					'minHeightUnit'   => 'vh',
					'tagName'         => 'header',
				],
			],
			$prepared['context']['parentContext']
		);
		$this->assertSame(
			[
				[
					'block'       => 'core/heading',
					'visualHints' => [ 'textAlign' => 'center' ],
				],
				[
					'block' => 'core/image',
					'role'  => 'alert(1)',
				],
			],
			$prepared['context']['siblingSummariesBefore']
		);
		$this->assertSame(
			[
				[
					'block'       => 'core/buttons',
					'visualHints' => [ 'align' => 'wide' ],
				],
				[ 'block' => 'core/separator' ],
				[ 'block' => 'core/list' ],
			],
			$prepared['context']['siblingSummariesAfter']
		);
	}

	public function test_prepare_recommend_block_input_bounds_structural_branch_children_from_editor_context(): void {
		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block'            => [
						'name'              => 'core/paragraph',
						'currentAttributes' => [],
					],
					'structuralBranch' => [
						[
							'block'      => 'core/group',
							'childCount' => 9,
							'children'   => [
								[
									'block'      => 'core/paragraph',
									'isSelected' => true,
								],
								[ 'block' => 'core/image' ],
								[ 'block' => 'core/buttons' ],
								[ 'block' => 'core/spacer' ],
								[ 'block' => 'core/separator' ],
								[ 'block' => 'core/list' ],
								[ 'block' => 'core/quote' ],
							],
						],
					],
				],
			]
		);

		$this->assertCount( 6, $prepared['context']['structuralBranch'][0]['children'] );
		$this->assertSame( 9, $prepared['context']['structuralBranch'][0]['childCount'] );
		$this->assertSame( 3, $prepared['context']['structuralBranch'][0]['moreChildren'] );
	}

	public function test_prepare_recommend_block_input_bounds_structural_summary_root_items_from_editor_context(): void {
		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block'               => [
						'name'              => 'core/paragraph',
						'currentAttributes' => [],
					],
					'structuralAncestors' => array_map(
						static fn( int $index ): array => [
							'block' => 'core/group-' . $index,
						],
						range( 1, 8 )
					),
					'structuralBranch'    => array_map(
						static fn( int $index ): array => [
							'block' => 'core/branch-' . $index,
						],
						range( 1, 8 )
					),
				],
			]
		);

		$this->assertCount( 6, $prepared['context']['structuralAncestors'] );
		$this->assertSame( 'core/group-3', $prepared['context']['structuralAncestors'][0]['block'] );
		$this->assertSame( 'core/group-8', $prepared['context']['structuralAncestors'][5]['block'] );
		$this->assertCount( 6, $prepared['context']['structuralBranch'] );
		$this->assertSame( 'core/branch-6', $prepared['context']['structuralBranch'][5]['block'] );
	}

	public function test_prepare_recommend_block_input_preserves_explicit_empty_inspector_panels(): void {
		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block' => [
						'name'              => 'core/paragraph',
						'currentAttributes' => [
							'content' => 'Hello world',
						],
						'inspectorPanels'   => [],
					],
				],
			]
		);

		$this->assertArrayHasKey( 'inspectorPanels', $prepared['context']['block'] );
		$this->assertSame( [], $prepared['context']['block']['inspectorPanels'] );
	}

	public function test_recommend_block_short_circuits_disabled_blocks_before_api_key_validation(): void {
		$result = BlockAbilities::recommend_block(
			[
				'selectedBlock' => [
					'blockName'   => 'core/paragraph',
					'attributes'  => [
						'content' => 'Hello world',
					],
					'editingMode' => 'disabled',
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['settings'] ?? null );
		$this->assertSame( [], $result['styles'] ?? null );
		$this->assertSame( [], $result['block'] ?? null );
		$this->assertSame( '', $result['explanation'] ?? null );
		$this->assertSame(
			[
				'settings' => 0,
				'styles'   => 0,
				'block'    => 0,
			],
			$result['preFilteringCounts'] ?? null
		);
		$this->assertIsArray( $result['executionContract'] ?? null );
		$this->assertSame( 'disabled', $result['executionContract']['editingMode'] ?? null );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $result['resolvedContextSignature'] ?? '' )
		);
	}

	public function test_recommend_block_emits_pre_filtering_counts_when_server_filters_remove_block_items(): void {
		WordPressTestState::$options                            = [
			'flavor_agent_openai_provider' => 'openai',
		];
		WordPressTestState::$connectors                         = [
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
			WordPressTestState::$ai_client_provider_support     = [
				'openai' => true,
			];
			WordPressTestState::$ai_client_supported            = true;
			WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
				[
					'settings'    => [],
					'styles'      => [],
					'block'       => [
						[
							'label'            => 'Add minimum height',
							'type'             => 'attribute_change',
							'panel'            => 'dimensions',
							'attributeUpdates' => '{"minHeight":"200px"}',
						],
						[
							'label'            => 'Set aspect ratio',
							'type'             => 'attribute_change',
							'panel'            => 'dimensions',
							'attributeUpdates' => '{"aspectRatio":"16/9"}',
						],
					],
					'explanation' => 'Tighten the paragraph layout.',
				]
			);

		$result = BlockAbilities::recommend_block(
			[
				'editorContext' => [
					'block' => [
						'name'              => 'core/paragraph',
						'currentAttributes' => [
							'content' => 'Hello world',
						],
						'inspectorPanels'   => [
							'color' => [ 'color.background' ],
						],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['block'] ?? null );
		$this->assertSame(
			[
				'settings' => 0,
				'styles'   => 0,
				'block'    => 2,
			],
			$result['preFilteringCounts'] ?? null
		);
	}

	public function test_recommend_block_proceeds_when_docs_grounding_is_empty(): void {
		$this->configure_text_generation_connector();
		WordPressTestState::$transients = [];
		$this->route_docs_grounding_search( [] );
		WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Proceeds without the grounding gate.',
			]
		);

		$result = BlockAbilities::recommend_block(
			[
				'selectedBlock' => [
					'blockName'  => 'core/paragraph',
					'attributes' => [
						'content' => 'Hello world',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertFalse( is_wp_error( $result ), 'grounding must never block a recommendation' );
		$this->assertFalse( $result['docsGrounding']['available'] ?? true );
		$this->assertSame( 0, $result['docsGrounding']['count'] ?? -1 );
		$this->assertNotSame( [], WordPressTestState::$last_ai_client_prompt, 'model must be called' );
	}

	public function test_recommend_block_resolve_signature_only_includes_docs_grounding_fingerprint(): void {
		WordPressTestState::$transients = [];
		WordPressTestState::$options    = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$input    = [
			'prompt'        => 'Keep the paragraph tighter and clearer.',
			'selectedBlock' => [
				'blockName'  => 'core/paragraph',
				'attributes' => [
					'content' => 'Hello world',
				],
			],
		];
		$baseline = BlockAbilities::recommend_block(
			array_merge(
				$input,
				[
					'resolveSignatureOnly' => true,
				]
			)
		);

		$this->assertIsArray( $baseline );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $baseline['resolvedContextSignature'] ?? '' )
		);
		$this->assertSame( 'unavailable', $baseline['docsGrounding']['status'] ?? null );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $baseline['docsGroundingFingerprint'] ?? '' )
		);
		$this->assertSame( [], WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( [], WordPressTestState::$last_remote_post );

		$prepared = $this->invoke_prepare_recommend_block_input( $input );
		$query    = $this->invoke_private_string_method(
			BlockAbilities::class,
			'build_wordpress_docs_query',
			[
				$prepared['context'],
				$prepared['prompt'],
			]
		);

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [
			[
				'id'          => 'chunk-1',
				'title'       => 'Paragraph block guidance',
				'sourceKey'   => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph',
				'sourceType'  => 'developer-docs',
				'url'         => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph/',
				'excerpt'     => 'Paragraph guidance should keep typography and spacing inside supported editor controls.',
				'score'       => 0.91,
				'retrievedAt' => '2026-05-08T14:00:00Z',
				'publishedAt' => '',
				'contentHash' => 'paragraph-guidance',
				'freshness'   => 'current',
			],
		];
		$this->prime_current_docs_source_coverage();
		WordPressTestState::$last_remote_post = [];

		$with_docs = BlockAbilities::recommend_block(
			array_merge(
				$input,
				[
					'resolveSignatureOnly' => true,
				]
			)
		);

		$this->assertIsArray( $with_docs );
		$this->assertSame( 'grounded', $with_docs['docsGrounding']['status'] ?? null );
		$this->assertNotSame(
			$baseline['resolvedContextSignature'] ?? null,
			$with_docs['resolvedContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['docsGroundingFingerprint'] ?? null,
			$with_docs['docsGroundingFingerprint'] ?? null
		);
		$this->assertSame( [], WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_recommend_block_resolved_signature_includes_design_semantics(): void {
		$input = [
			'prompt'               => 'Keep the paragraph tighter and clearer.',
			'editorContext'        => [
				'block'           => [
					'name' => 'core/paragraph',
				],
				'designSemantics' => [
					'surface'         => 'block',
					'sectionRole'     => 'hero',
					'contrastContext' => 'dark-parent',
					'layoutRhythm'    => 'constrained',
				],
			],
			'resolveSignatureOnly' => true,
		];

		$first     = BlockAbilities::recommend_block( $input );
		$reordered = BlockAbilities::recommend_block(
			[
				...$input,
				'editorContext' => [
					'block'           => [
						'name' => 'core/paragraph',
					],
					'designSemantics' => [
						'layoutRhythm'    => 'constrained',
						'contrastContext' => 'dark-parent',
						'sectionRole'     => 'hero',
						'surface'         => 'block',
					],
				],
			]
		);
		$changed   = BlockAbilities::recommend_block(
			[
				...$input,
				'editorContext' => [
					'block'           => [
						'name' => 'core/paragraph',
					],
					'designSemantics' => [
						'surface'         => 'block',
						'sectionRole'     => 'footer',
						'contrastContext' => 'dark-parent',
						'layoutRhythm'    => 'constrained',
					],
				],
			]
		);

		$this->assertIsArray( $first );
		$this->assertIsArray( $reordered );
		$this->assertIsArray( $changed );
		$this->assertSame(
			$first['resolvedContextSignature'] ?? null,
			$reordered['resolvedContextSignature'] ?? null
		);
		$this->assertNotSame(
			$first['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
	}

	public function test_recommend_block_resolved_signature_is_stable_between_recommendation_and_signature_modes(): void {
		$this->configure_text_generation_connector();
		WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Keep the paragraph concise.',
			]
		);

		$input = [
			'prompt'        => 'Keep the paragraph tighter and clearer.',
			'selectedBlock' => [
				'blockName'  => 'core/paragraph',
				'attributes' => [
					'content' => 'Hello world',
				],
			],
		];

		$recommendation = BlockAbilities::recommend_block( $input );
		$signature      = BlockAbilities::recommend_block(
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
			$recommendation['resolvedContextSignature'] ?? null,
			$signature['resolvedContextSignature'] ?? null
		);
		$this->assertSame(
			$recommendation['docsGroundingFingerprint'] ?? null,
			$signature['docsGroundingFingerprint'] ?? null
		);
	}

	public function test_recommend_block_warns_on_missing_release_cycle_coverage_by_default(): void {
		$this->markTestSkipped( 'Coverage diagnostics are removed by the docs-grounding relaxation (deleted in Task 4/5).' );
		$this->configure_text_generation_connector();
		WordPressTestState::$transients['flavor_agent_docs_source_coverage_v2'] = [
			'status'                 => 'missing-current-release-cycle',
			'hasDeveloperDocs'       => true,
			'hasCurrentReleaseCycle' => false,
			'sourceTypes'            => [ 'developer-docs' ],
			'freshness'              => [ 'current' ],
			'checkedAt'              => '2026-05-11 00:00:00',
			'errorCode'              => 'missing_current_release_cycle',
			'errorMessage'           => 'Developer Docs grounding is missing current WordPress release-cycle sources.',
		];
		WordPressTestState::$ai_client_generate_text_result                     = wp_json_encode(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Model still runs when coverage is only diagnostic.',
			]
		);

		$result = BlockAbilities::recommend_block(
			[
				'selectedBlock' => [
					'blockName'  => 'core/paragraph',
					'attributes' => [
						'content' => 'Hello world',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			'missing-current-release-cycle',
			$result['docsGrounding']['coverage']['status'] ?? null
		);
		$this->assertNotSame( [], WordPressTestState::$last_ai_client_prompt );
	}

	public function test_recommend_block_warns_on_missing_release_cycle_coverage_even_when_release_gate_is_enabled(): void {
		$this->markTestSkipped( 'Coverage diagnostics are removed by the docs-grounding relaxation (deleted in Task 4/5).' );
		$this->configure_text_generation_connector();
		WordPressTestState::$transients['flavor_agent_docs_source_coverage_v2'] = [
			'status'                 => 'missing-current-release-cycle',
			'hasDeveloperDocs'       => true,
			'hasCurrentReleaseCycle' => false,
			'sourceTypes'            => [ 'developer-docs' ],
			'freshness'              => [ 'current' ],
			'checkedAt'              => '2026-05-11 00:00:00',
			'errorCode'              => 'missing_current_release_cycle',
			'errorMessage'           => 'Developer Docs grounding is missing current WordPress release-cycle sources.',
		];
		WordPressTestState::$ai_client_generate_text_result                     = wp_json_encode(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Model still runs: release-cycle currency is now advisory, not a block.',
			]
		);

		add_filter( 'flavor_agent_docs_grounding_require_current_coverage', '__return_true' );

		try {
			$result = BlockAbilities::recommend_block(
				[
					'selectedBlock' => [
						'blockName'  => 'core/paragraph',
						'attributes' => [
							'content' => 'Hello world',
						],
					],
				]
			);
		} finally {
			remove_filter( 'flavor_agent_docs_grounding_require_current_coverage', '__return_true' );
		}

		$this->assertIsArray( $result );
		$this->assertSame(
			'missing-current-release-cycle',
			$result['docsGrounding']['coverage']['status'] ?? null
		);
		// The model runs even with the release gate enabled.
		$this->assertNotSame( [], WordPressTestState::$last_ai_client_prompt );
	}

	public function test_list_allowed_blocks_returns_registered_block_manifests(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/card',
			[
				'title'         => 'Card',
				'allowedBlocks' => [ 'core/paragraph' ],
			]
		);

		$result = BlockAbilities::list_allowed_blocks( [] );

		$this->assertContains( 'plugin/card', array_column( $result['blocks'], 'name' ) );
		$this->assertSame(
			[ 'core/paragraph' ],
			array_values(
				array_filter(
					$result['blocks'],
					static fn ( array $block ): bool => 'plugin/card' === ( $block['name'] ?? '' )
				)
			)[0]['allowedBlocks']
		);
	}

	public function test_list_allowed_blocks_supports_filters_pagination_and_variation_caps(): void {
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

		$paginated = BlockAbilities::list_allowed_blocks(
			[
				'search'   => 'car',
				'category' => 'design',
				'limit'    => 1,
				'offset'   => 1,
			]
		);

		$this->assertSame( 2, $paginated['total'] );
		$this->assertCount( 1, $paginated['blocks'] );
		$this->assertSame( 'plugin/carousel', $paginated['blocks'][0]['name'] );
		$this->assertSame( [], $paginated['blocks'][0]['variations'] );

		$with_variations = BlockAbilities::list_allowed_blocks(
			[
				'search'            => 'car',
				'category'          => 'design',
				'includeVariations' => true,
				'maxVariations'     => 1,
			]
		);

		$this->assertSame( 2, $with_variations['total'] );
		$this->assertSame( 1, count( $with_variations['blocks'][0]['variations'] ) );
		$this->assertSame( 1, count( $with_variations['blocks'][1]['variations'] ) );
	}

	/**
	 * @return array{context: array, prompt: string}
	 */
	private function invoke_prepare_recommend_block_input( array $input ): array {
		$method = new ReflectionMethod( BlockAbilities::class, 'prepare_recommend_block_input' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $input );

		$this->assertIsArray( $result );

		return $result;
	}

	private function build_cache_key( string $query, int $max_results ): string {
		$method = new ReflectionMethod( \FlavorAgent\Cloudflare\AISearchClient::class, 'build_cache_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $query, $max_results );

		$this->assertIsString( $result );

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

	private function register_paragraph_block(): void {
		WordPressTestState::$global_settings = [
			'color'      => [
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#f00',
					],
				],
				'duotone' => [
					[
						'slug'   => 'midnight',
						'colors' => [ '#111111', '#f5f5f5' ],
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
				'blockGap' => true,
			],
			'layout'     => [
				'allowEditing' => false,
			],
			'background' => [
				'backgroundImage' => true,
			],
		];
		WordPressTestState::$global_styles   = [
			'elements' => [
				'button' => [
					'color' => [
						'text' => 'var(--wp--preset--color--contrast)',
					],
				],
			],
		];

		\WP_Block_Type_Registry::get_instance()->register(
			'core/paragraph',
			[
				'title'      => 'Paragraph',
				'supports'   => [
					'color'      => [
						'background' => true,
						'text'       => true,
					],
					'typography' => [
						'fontSize' => true,
					],
				],
				'attributes' => [
					'content'   => [
						'type' => 'string',
						'role' => 'content',
					],
					'dropCap'   => [
						'type' => 'boolean',
					],
					'metadata'  => [
						'type' => 'object',
					],
					'className' => [
						'type' => 'string',
					],
				],
				'styles'     => [
					[
						'name'      => 'default',
						'label'     => 'Default',
						'isDefault' => true,
					],
					[
						'name'      => 'outline',
						'label'     => 'Outline',
						'isDefault' => false,
					],
				],
				'variations' => [
					[
						'name'        => 'intro',
						'title'       => 'Intro',
						'description' => 'Intro paragraph',
						'scope'       => [ 'inserter' ],
					],
				],
				'apiVersion' => 3,
			]
		);
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

	private function prime_default_docs_grounding(): void {
		$this->prime_current_docs_source_coverage();
		$this->route_docs_grounding_search(
			[
				$this->docs_grounding_chunk(
					'Paragraph block reference',
					'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph/',
					'Use supported paragraph block controls for typography and spacing recommendations.'
				),
			]
		);
	}

	/**
	 * Serve the docs-grounding public search endpoint by URL so the best-effort
	 * search resolves deterministically without consuming queued responses.
	 *
	 * @param array<int, array<string, mixed>> $chunks
	 */
	private function route_docs_grounding_search( array $chunks ): void {
		WordPressTestState::$remote_post_url_responses['.search.ai.cloudflare.com'] = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => $chunks,
					],
				]
			),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function docs_grounding_chunk( string $title, string $url, string $excerpt ): array {
		$frontmatter = "---\nsource_url: \"{$url}\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\ncontent_hash: " . md5( $title . $url );

		return [
			'id'   => md5( $title . $url ),
			'item' => [
				'key'      => $url,
				'metadata' => [ 'title' => $title ],
			],
			'text' => $frontmatter . "\n---\n{$excerpt}",
		];
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
