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
				],
			]
		);

		$this->assertSame(
			sanitize_textarea_field( "Add more contrast\n<script>alert('x')</script>" ),
			$prepared['prompt']
		);
		$this->assertSame( 'core/paragraph', $prepared['context']['block']['name'] );
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
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $result['resolvedContextSignature'] ?? '' )
		);
	}

	public function test_recommend_block_resolve_signature_only_returns_minimal_payload_and_ignores_docs_guidance_cache_changes(): void {
		WordPressTestState::$options = [
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
		$this->assertSame(
			[ 'resolvedContextSignature' => $baseline['resolvedContextSignature'] ?? null ],
			$baseline
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $baseline['resolvedContextSignature'] ?? '' )
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
				'id'        => 'chunk-1',
				'title'     => 'Paragraph block guidance',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph/',
				'excerpt'   => 'Paragraph guidance should keep typography and spacing inside supported editor controls.',
				'score'     => 0.91,
			],
		];
		WordPressTestState::$last_remote_post                                  = [];

		$with_docs = BlockAbilities::recommend_block(
			array_merge(
				$input,
				[
					'resolveSignatureOnly' => true,
				]
			)
		);

		$this->assertIsArray( $with_docs );
		$this->assertSame(
			[ 'resolvedContextSignature' => $with_docs['resolvedContextSignature'] ?? null ],
			$with_docs
		);
		$this->assertSame(
			$baseline['resolvedContextSignature'] ?? null,
			$with_docs['resolvedContextSignature'] ?? null
		);
		$this->assertSame( [], WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
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
}
