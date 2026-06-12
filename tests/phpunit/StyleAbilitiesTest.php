<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\StyleAbilities;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class StyleAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->configure_text_generation_connector();
		$this->prime_default_docs_grounding();
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
	}

	public function test_recommend_style_requires_global_styles_scope(): void {
		$result = StyleAbilities::recommend_style(
			[
				'scope'        => [
					'surface'        => 'template',
					'scopeKey'       => 'wp_template:theme//home',
					'globalStylesId' => '17',
				],
				'styleContext' => [],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_style_scope', $result->get_error_code() );
	}

	public function test_recommend_style_requires_style_book_block_name(): void {
		$result = StyleAbilities::recommend_style(
			[
				'scope'        => [
					'surface'        => 'style-book',
					'scopeKey'       => 'style_book:17',
					'globalStylesId' => '17',
				],
				'styleContext' => [],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_style_scope', $result->get_error_code() );
	}

	public function test_recommend_style_returns_validated_style_suggestions(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [
								[
									'label'       => 'Use accent canvas',
									'description' => 'Apply the accent preset to the site background.',
									'category'    => 'color',
									'tone'        => 'executable',
									'operations'  => [
										[
											'type'       => 'set_styles',
											'path'       => [ 'color', 'background' ],
											'value'      => 'var:preset|color|accent',
											'valueType'  => 'preset',
											'presetType' => 'color',
											'presetSlug' => 'accent',
											'cssVar'     => 'var(--wp--preset--color--accent)',
										],
									],
								],
							],
							'explanation' => 'Prefer theme palette colors.',
						]
					),
				]
			),
		];

		$result = StyleAbilities::recommend_style(
			[
				'scope'        => [
					'surface'        => 'global-styles',
					'scopeKey'       => 'global_styles:17',
					'globalStylesId' => '17',
				],
				'styleContext' => [
					'currentConfig'         => [ 'styles' => [] ],
					'mergedConfig'          => [
						'styles' => [
							'color' => [
								'background' => '#000000',
								'text'       => '#000000',
							],
						],
					],
					'availableVariations'   => [
						[
							'title'       => 'Midnight',
							'description' => 'Dark editorial palette',
							'settings'    => [],
							'styles'      => [
								'color' => [
									'background' => 'var:preset|color|accent',
								],
							],
						],
					],
					'themeTokenDiagnostics' => [
						'source'      => 'stable',
						'settingsKey' => 'features',
						'reason'      => 'stable-parity',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Use accent canvas', $result['suggestions'][0]['label'] ?? null );
		$this->assertSame( 'set_styles', $result['suggestions'][0]['operations'][0]['type'] ?? null );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $result['reviewContextSignature'] ?? '' )
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $result['resolvedContextSignature'] ?? '' )
		);
	}

	public function test_recommend_style_proceeds_when_docs_grounding_is_empty(): void {
		WordPressTestState::$transients = [];
		$this->route_docs_grounding_search( [] );
		WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Use accent canvas',
						'description' => 'Would have called the model without the grounding gate.',
						'category'    => 'color',
						'tone'        => 'executable',
						'operations'  => [
							[
								'type'       => 'set_styles',
								'path'       => [ 'color', 'background' ],
								'value'      => 'var:preset|color|accent',
								'valueType'  => 'preset',
								'presetType' => 'color',
								'presetSlug' => 'accent',
								'cssVar'     => 'var(--wp--preset--color--accent)',
							],
						],
					],
				],
				'explanation' => 'Grounding gate regression fixture.',
			]
		);

		$result = StyleAbilities::recommend_style(
			[
				'scope'        => [
					'surface'        => 'global-styles',
					'scopeKey'       => 'global_styles:17',
					'globalStylesId' => '17',
				],
				'styleContext' => [
					'currentConfig'         => [ 'styles' => [] ],
					'mergedConfig'          => [ 'styles' => [] ],
					'availableVariations'   => [],
					'themeTokenDiagnostics' => [
						'source'      => 'stable',
						'settingsKey' => 'features',
						'reason'      => 'stable-parity',
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

	public function test_recommend_style_canonicalizes_scope_key_server_side(): void {
		$canonical_input = [
			'scope'                => [
				'surface'        => 'global-styles',
				'scopeKey'       => 'global_styles:17',
				'globalStylesId' => '17',
			],
			'styleContext'         => [
				'currentConfig'         => [ 'styles' => [] ],
				'mergedConfig'          => [ 'styles' => [] ],
				'availableVariations'   => [],
				'themeTokenDiagnostics' => [
					'source'      => 'stable',
					'settingsKey' => 'features',
					'reason'      => 'stable-parity',
				],
			],
			'prompt'               => 'Make the palette warmer.',
			'resolveSignatureOnly' => true,
		];
		$forged_input    = array_merge(
			$canonical_input,
			[
				'scope' => [
					'surface'        => 'global-styles',
					// Forged scopeKey that doesn't match the canonical form
					// derived from globalStylesId. The server should ignore it.
					'scopeKey'       => 'attacker-bucket-9001',
					'globalStylesId' => '17',
				],
			]
		);

		$canonical = StyleAbilities::recommend_style( $canonical_input );
		$forged    = StyleAbilities::recommend_style( $forged_input );

		// Resolved signature must be identical regardless of the client's
		// scopeKey, because the server canonicalizes it before hashing.
		$this->assertSame(
			$canonical['resolvedContextSignature'] ?? null,
			$forged['resolvedContextSignature'] ?? null,
			'Resolved signature must be deterministic across client-supplied scopeKey variants.'
		);
		$this->assertSame(
			$canonical['reviewContextSignature'] ?? null,
			$forged['reviewContextSignature'] ?? null,
			'Review signature must be deterministic across client-supplied scopeKey variants.'
		);
	}

	public function test_recommend_style_canonicalizes_style_book_scope_key(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'core/paragraph',
			[
				'title' => 'Paragraph',
			]
		);

		$style_context = [
			'currentConfig'         => [ 'styles' => [] ],
			'mergedConfig'          => [ 'styles' => [] ],
			'styleBookTarget'       => [
				'blockName'  => 'core/paragraph',
				'blockTitle' => 'Paragraph',
			],
			'themeTokenDiagnostics' => [
				'source'      => 'stable',
				'settingsKey' => 'features',
				'reason'      => 'stable-parity',
			],
		];

		$canonical = StyleAbilities::recommend_style(
			[
				'scope'                => [
					'surface'        => 'style-book',
					'scopeKey'       => 'style_book:17:core/paragraph',
					'globalStylesId' => '17',
					'blockName'      => 'core/paragraph',
				],
				'styleContext'         => $style_context,
				'prompt'               => 'Tighten paragraph rhythm.',
				'resolveSignatureOnly' => true,
			]
		);
		$forged    = StyleAbilities::recommend_style(
			[
				'scope'                => [
					'surface'        => 'style-book',
					'scopeKey'       => 'forged-style-book-bucket',
					'globalStylesId' => '17',
					'blockName'      => 'core/paragraph',
				],
				'styleContext'         => $style_context,
				'prompt'               => 'Tighten paragraph rhythm.',
				'resolveSignatureOnly' => true,
			]
		);

		$this->assertSame(
			$canonical['resolvedContextSignature'] ?? null,
			$forged['resolvedContextSignature'] ?? null,
			'Style Book resolved signature must be deterministic across client-supplied scopeKey variants.'
		);
	}

	public function test_recommend_style_resolve_signature_only_returns_minimal_payload_and_changes_when_prompt_changes(): void {
		WordPressTestState::$transients = [];
		$input                          = [
			'scope'        => [
				'surface'        => 'global-styles',
				'scopeKey'       => 'global_styles:17',
				'globalStylesId' => '17',
			],
			'styleContext' => [
				'currentConfig'         => [ 'styles' => [] ],
				'mergedConfig'          => [ 'styles' => [] ],
				'availableVariations'   => [],
				'themeTokenDiagnostics' => [
					'source'      => 'stable',
					'settingsKey' => 'features',
					'reason'      => 'stable-parity',
				],
			],
		];

		$baseline = StyleAbilities::recommend_style(
			array_merge(
				$input,
				[
					'prompt'               => 'Keep the palette restrained.',
					'resolveSignatureOnly' => true,
				]
			)
		);
		$changed  = StyleAbilities::recommend_style(
			array_merge(
				$input,
				[
					'prompt'               => 'Make the palette warmer and more editorial.',
					'resolveSignatureOnly' => true,
				]
			)
		);

		$this->assertIsArray( $baseline );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $baseline['reviewContextSignature'] ?? '' )
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $baseline['resolvedContextSignature'] ?? '' )
		);
		$this->assertFalse( $baseline['docsGrounding']['available'] ?? true );
		$this->assertIsArray( $changed );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $changed['reviewContextSignature'] ?? '' )
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $changed['resolvedContextSignature'] ?? '' )
		);
		$this->assertSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_recommend_style_resolve_signature_only_includes_docs_guidance_changes(): void {
		WordPressTestState::$options['flavor_agent_cloudflare_ai_search_account_id']  = 'account-123';
		WordPressTestState::$options['flavor_agent_cloudflare_ai_search_instance_id'] = 'wp-dev-docs';
		WordPressTestState::$options['flavor_agent_cloudflare_ai_search_api_token']   = 'token-xyz';

		$input = [
			'scope'                => [
				'surface'        => 'global-styles',
				'scopeKey'       => 'global_styles:17',
				'globalStylesId' => '17',
			],
			'styleContext'         => [
				'currentConfig'         => [ 'styles' => [] ],
				'mergedConfig'          => [ 'styles' => [] ],
				'availableVariations'   => [],
				'themeTokenDiagnostics' => [
					'source'      => 'stable',
					'settingsKey' => 'features',
					'reason'      => 'stable-parity',
				],
			],
			'prompt'               => 'Keep the palette restrained.',
			'resolveSignatureOnly' => true,
		];

		$built_context = $this->invoke_private_array_method(
			StyleAbilities::class,
			'build_global_styles_context',
			[
				$input['scope'],
				$input['styleContext'],
			]
		);
		$query         = $this->invoke_private_string_method(
			StyleAbilities::class,
			'build_wordpress_docs_query',
			[
				$built_context,
				$input['prompt'],
			]
		);

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [
			[
				'id'        => 'chunk-style-a',
				'title'     => 'Global styles reference',
				'sourceKey' => 'developer.wordpress.org/themes/global-settings-and-styles/settings',
				'url'       => 'https://developer.wordpress.org/themes/global-settings-and-styles/settings/',
				'excerpt'   => 'Use theme.json preset families for color and typography changes.',
				'score'     => 0.91,
			],
		];

		$baseline = StyleAbilities::recommend_style( $input );

		$this->assertIsArray( $baseline );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $baseline['reviewContextSignature'] ?? '' )
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $baseline['resolvedContextSignature'] ?? '' )
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [
			[
				'id'        => 'chunk-style-b',
				'title'     => 'Theme.json styles',
				'sourceKey' => 'developer.wordpress.org/themes/global-settings-and-styles/styles/',
				'url'       => 'https://developer.wordpress.org/themes/global-settings-and-styles/styles/',
				'excerpt'   => 'Keep site-wide style adjustments inside supported Global Styles controls.',
				'score'     => 0.93,
			],
		];
		WordPressTestState::$last_remote_post                                  = [];

		$with_docs = StyleAbilities::recommend_style( $input );

		$this->assertIsArray( $with_docs );
		$this->assertNotSame(
			$baseline['resolvedContextSignature'] ?? null,
			$with_docs['resolvedContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['reviewContextSignature'] ?? null,
			$with_docs['reviewContextSignature'] ?? null
		);
		$this->assertNotSame(
			$baseline['docsGroundingFingerprint'] ?? null,
			$with_docs['docsGroundingFingerprint'] ?? null
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_recommend_style_signatures_are_stable_between_recommendation_and_signature_modes(): void {
		WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Use accent canvas',
						'description' => 'Apply the accent preset to the site background.',
						'category'    => 'color',
						'tone'        => 'executable',
						'operations'  => [
							[
								'type'       => 'set_styles',
								'path'       => [ 'color', 'background' ],
								'value'      => 'var:preset|color|accent',
								'valueType'  => 'preset',
								'presetType' => 'color',
								'presetSlug' => 'accent',
								'cssVar'     => 'var(--wp--preset--color--accent)',
							],
						],
					],
				],
				'explanation' => 'Grounded style response.',
			]
		);

		$input = [
			'scope'        => [
				'surface'        => 'global-styles',
				'scopeKey'       => 'global_styles:17',
				'globalStylesId' => '17',
			],
			'styleContext' => [
				'currentConfig'         => [ 'styles' => [] ],
				'mergedConfig'          => [ 'styles' => [] ],
				'availableVariations'   => [],
				'themeTokenDiagnostics' => [
					'source'      => 'stable',
					'settingsKey' => 'features',
					'reason'      => 'stable-parity',
				],
			],
		];

		$recommendation = StyleAbilities::recommend_style( $input );
		$signature      = StyleAbilities::recommend_style(
			array_merge(
				$input,
				[
					'resolveSignatureOnly' => true,
				]
			)
		);

		$this->assertIsArray( $recommendation );
		$this->assertIsArray( $signature );
		$this->assertTrue( $recommendation['docsGrounding']['available'] ?? false );
		$this->assertTrue( $signature['docsGrounding']['available'] ?? false );
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

	public function test_recommend_style_downgrades_invalid_freeform_operations_to_advisory(): void {
		WordPressTestState::$global_settings      = [
			'color'  => [
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#ff5500',
					],
				],
			],
			'border' => [
				'style' => true,
			],
		];
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [
								[
									'label'       => 'Unsafe border treatment',
									'description' => 'Uses an invalid freeform border style.',
									'category'    => 'border',
									'tone'        => 'executable',
									'operations'  => [
										[
											'type'      => 'set_styles',
											'path'      => [ 'border', 'style' ],
											'value'     => [ 'style' => 'glow' ],
											'valueType' => 'freeform',
										],
									],
								],
							],
							'explanation' => 'Unsafe freeform values should be stripped.',
						]
					),
				]
			),
		];

		$result = StyleAbilities::recommend_style(
			[
				'scope'        => [
					'surface'        => 'global-styles',
					'scopeKey'       => 'global_styles:17',
					'globalStylesId' => '17',
				],
				'styleContext' => [
					'currentConfig'         => [ 'styles' => [] ],
					'mergedConfig'          => [ 'styles' => [] ],
					'availableVariations'   => [],
					'templateStructure'     => [
						[
							'name'        => 'core/template-part',
							'innerBlocks' => [
								[
									'name' => 'core/site-title',
								],
							],
						],
					],
					'templateVisibility'    => [
						'hasVisibilityRules' => true,
						'blockCount'         => 1,
						'blocks'             => [
							[
								'path'             => [ 0 ],
								'name'             => 'core/template-part',
								'label'            => 'Header template part',
								'hiddenViewports'  => [ 'mobile' ],
								'visibleViewports' => [ 'desktop' ],
							],
						],
					],
					'themeTokenDiagnostics' => [
						'source'      => 'stable',
						'settingsKey' => 'features',
						'reason'      => 'stable-parity',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'advisory', $result['suggestions'][0]['tone'] ?? null );
		$this->assertSame( [], $result['suggestions'][0]['operations'] ?? null );
	}

	public function test_supported_style_paths_keep_palette_backed_color_controls_when_custom_colors_are_disabled(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				'custom'  => false,
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#ff5500',
					],
				],
			],
		];

		$paths = StyleAbilities::supported_style_paths();

		$this->assertContains(
			[
				'path'        => [ 'color', 'background' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertContains(
			[
				'path'        => [ 'color', 'text' ],
				'valueSource' => 'color',
			],
			$paths
		);
	}

	public function test_supported_style_paths_omit_disabled_color_controls(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				'background' => false,
				'text'       => false,
				'link'       => false,
				'button'     => false,
				'heading'    => false,
				'palette'    => [
					[
						'slug'  => 'accent',
						'color' => '#ff5500',
					],
				],
			],
		];

		$paths = StyleAbilities::supported_style_paths();

		$this->assertNotContains(
			[
				'path'        => [ 'color', 'background' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertNotContains(
			[
				'path'        => [ 'color', 'text' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertNotContains(
			[
				'path'        => [ 'elements', 'link', 'color', 'text' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertNotContains(
			[
				'path'        => [ 'elements', 'button', 'color', 'background' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertNotContains(
			[
				'path'        => [ 'elements', 'button', 'color', 'text' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertNotContains(
			[
				'path'        => [ 'elements', 'heading', 'color', 'text' ],
				'valueSource' => 'color',
			],
			$paths
		);
	}

	public function test_recommend_style_strips_non_semantic_config_fields_from_prompt_context(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [],
							'explanation' => 'Use semantic Global Styles context only.',
						]
					),
				]
			),
		];

		StyleAbilities::recommend_style(
			[
				'scope'        => [
					'surface'        => 'global-styles',
					'scopeKey'       => 'global_styles:17',
					'globalStylesId' => '17',
				],
				'styleContext' => [
					'currentConfig'         => [
						'settings' => [
							'color' => [
								'palette' => [
									'default' => true,
								],
							],
						],
						'styles'   => [
							'color' => [
								'background' => 'var:preset|color|accent',
							],
						],
						'_links'   => [
							'self' => [
								[ 'href' => '/wp/v2/global-styles/17' ],
							],
						],
					],
					'mergedConfig'          => [
						'settings' => [],
						'styles'   => [],
						'_links'   => [
							'self' => [
								[ 'href' => '/wp/v2/global-styles/17?context=edit' ],
							],
						],
					],
					'availableVariations'   => [],
					'themeTokenDiagnostics' => [
						'source'      => 'stable',
						'settingsKey' => 'features',
						'reason'      => 'stable-parity',
					],
				],
			]
		);

		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);

		$this->assertIsArray( $request_body );
		$this->assertStringContainsString(
			'"settings":{"color":{"palette":{"default":true}}}',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'_links',
			(string) ( $request_body['input'] ?? '' )
		);
	}

	public function test_recommend_style_accepts_style_book_scope_with_target_context(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'core/paragraph',
			[
				'title'    => 'Paragraph',
				'supports' => [
					'color'      => [
						'text' => true,
					],
					'customCSS'  => true,
					'background' => [
						'backgroundImage' => true,
					],
				],
			]
		);

		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [],
							'explanation' => 'Style Book target acknowledged.',
						]
					),
				]
			),
		];

		$result = StyleAbilities::recommend_style(
			[
				'scope'        => [
					'surface'        => 'style-book',
					'scopeKey'       => 'style_book:17:core/paragraph',
					'globalStylesId' => '17',
					'blockName'      => 'core/paragraph',
					'blockTitle'     => 'Paragraph',
				],
				'styleContext' => [
					'currentConfig'         => [ 'styles' => [] ],
					'mergedConfig'          => [ 'styles' => [] ],
					'availableVariations'   => [],
					'templateStructure'     => [
						[
							'name'        => 'core/template-part',
							'innerBlocks' => [
								[
									'name' => 'core/site-title',
								],
							],
						],
					],
					'templateVisibility'    => [
						'hasVisibilityRules' => true,
						'blockCount'         => 1,
						'blocks'             => [
							[
								'path'             => [ 0 ],
								'name'             => 'core/template-part',
								'label'            => 'Header template part',
								'hiddenViewports'  => [ 'mobile' ],
								'visibleViewports' => [ 'desktop' ],
							],
						],
					],
					'designSemantics'       => [
						'surface'          => 'style-book',
						'targetBlockName'  => 'core/paragraph',
						'occurrenceCount'  => 1,
						'confidence'       => 'high',
						'dominantRole'     => 'footer-paragraph',
						'dominantLocation' => 'footer',
						'occurrences'      => [
							[
								'path'             => [ 0, 1 ],
								'block'            => 'core/paragraph',
								'label'            => 'Footer paragraph',
								'role'             => 'footer-paragraph',
								'location'         => 'footer',
								'templateArea'     => 'footer',
								'templatePartSlug' => 'footer',
								'nearbyBlocks'     => [
									'before' => [
										[
											'block' => 'core/heading',
											'label' => 'Heading',
										],
									],
								],
							],
						],
					],
					'themeTokenDiagnostics' => [
						'source'      => 'stable',
						'settingsKey' => 'features',
						'reason'      => 'stable-parity',
					],
					'styleBookTarget'       => [
						'description'   => 'Primary intro copy block.',
						'currentStyles' => [
							'color' => [
								'text' => 'var:preset|color|accent',
							],
						],
					],
				],
			]
		);

		$this->assertIsArray( $result );

		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);

		$this->assertIsArray( $request_body );
		$this->assertStringContainsString(
			'Surface: style-book',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'Block name: core/paragraph',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'- color.text (color)',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'- color.background (color)',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'- customCSS',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'- background.backgroundImage',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'Primary intro copy block.',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'## Current template structure',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'## Viewport visibility constraints',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'## Design semantic context',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'Dominant role hint: `footer-paragraph`',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'hidden on `mobile`',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'## Available theme style variations',
			(string) ( $request_body['input'] ?? '' )
		);
	}

	public function test_recommend_style_includes_docs_guidance_template_identity_and_structure_in_prompt(): void {
		WordPressTestState::$options['flavor_agent_cloudflare_ai_search_account_id']  = 'account-123';
		WordPressTestState::$options['flavor_agent_cloudflare_ai_search_instance_id'] = 'wp-dev-docs';
		WordPressTestState::$options['flavor_agent_cloudflare_ai_search_api_token']   = 'token-xyz';
		WordPressTestState::$remote_post_response                                     = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [],
							'explanation' => 'Use documented Global Styles controls.',
						]
					),
				]
			),
		];

		$recommendation_input = [
			'scope'        => [
				'surface'        => 'global-styles',
				'scopeKey'       => 'global_styles:17',
				'globalStylesId' => '17',
				'templateSlug'   => 'theme-slug//home',
				'templateType'   => 'home',
			],
			'styleContext' => [
				'currentConfig'         => [ 'styles' => [] ],
				'mergedConfig'          => [ 'styles' => [] ],
				'availableVariations'   => [],
				'templateStructure'     => [
					[
						'name'        => 'core/template-part',
						'innerBlocks' => [
							[
								'name' => 'core/site-title',
							],
						],
					],
				],
				'designSemantics'       => [
					'surface'            => 'global-styles',
					'templateType'       => 'home',
					'overallDensityHint' => 'balanced',
					'locationSummary'    => [
						[
							'value' => 'header',
							'count' => 1,
						],
					],
					'sections'           => [
						[
							'path'             => [ 0 ],
							'block'            => 'core/template-part',
							'label'            => 'Header slot',
							'role'             => 'header-slot',
							'location'         => 'header',
							'templateArea'     => 'header',
							'templatePartSlug' => 'header',
							'childRoles'       => [ 'header-site-title' ],
							'emphasisHint'     => 'primary',
							'densityHint'      => 'airy',
						],
					],
				],
				'themeTokenDiagnostics' => [
					'source'      => 'stable',
					'settingsKey' => 'features',
					'reason'      => 'stable-parity',
				],
			],
			'prompt'       => 'Keep the palette restrained.',
		];
		$built_context        = $this->invoke_private_array_method(
			StyleAbilities::class,
			'build_global_styles_context',
			[
				$recommendation_input['scope'],
				$recommendation_input['styleContext'],
			]
		);
		$query                = $this->invoke_private_string_method(
			StyleAbilities::class,
			'build_wordpress_docs_query',
			[ $built_context, 'Keep the palette restrained.' ]
		);

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [
			[
				'id'          => 'chunk-1',
				'title'       => 'Global styles reference',
				'sourceKey'   => 'developer.wordpress.org/themes/global-settings-and-styles/settings',
				'sourceType'  => 'developer-docs',
				'url'         => 'https://developer.wordpress.org/themes/global-settings-and-styles/settings/',
				'excerpt'     => 'Use theme.json preset families for color and typography changes.',
				'score'       => 0.91,
				'retrievedAt' => '2026-05-08T14:00:00Z',
				'publishedAt' => '',
				'contentHash' => 'global-styles-reference-current',
				'freshness'   => 'current',
			],
		];

		StyleAbilities::recommend_style( $recommendation_input );

		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);

		$this->assertIsArray( $request_body );
		$this->assertStringContainsString( '## WordPress Developer Guidance', (string) ( $request_body['input'] ?? '' ) );
		$this->assertStringContainsString( 'Template slug: theme-slug//home', (string) ( $request_body['input'] ?? '' ) );
		$this->assertStringContainsString( '## Current template structure', (string) ( $request_body['input'] ?? '' ) );
		$this->assertStringContainsString( '## Design semantic context', (string) ( $request_body['input'] ?? '' ) );
		$this->assertStringContainsString( 'Overall density hint: balanced', (string) ( $request_body['input'] ?? '' ) );
	}

	public function test_style_docs_query_includes_live_structure_visibility_and_semantics(): void {
		$context = [
			'scope'        => [
				'surface'        => 'global-styles',
				'scopeKey'       => 'global_styles:17',
				'globalStylesId' => '17',
				'templateSlug'   => 'theme//home',
				'templateType'   => 'home',
			],
			'styleContext' => [
				'supportedStylePaths' => [
					[
						'path'        => [ 'color', 'background' ],
						'valueSource' => 'color',
					],
				],
				'templateStructure'   => [
					[
						'name'        => 'core/template-part',
						'innerBlocks' => [
							[
								'name' => 'core/site-title',
							],
						],
					],
					[
						'name' => 'core/group',
					],
				],
				'templateVisibility'  => [
					'blockCount' => 1,
				],
				'designSemantics'     => [
					'overallDensityHint' => 'balanced',
					'locationSummary'    => [
						[
							'value' => 'header',
							'count' => 1,
						],
					],
				],
			],
		];
		$query   = $this->invoke_private_string_method(
			StyleAbilities::class,
			'build_wordpress_docs_query',
			[ $context, 'Keep the palette restrained.' ]
		);

		$this->assertStringContainsString( 'template structure core/template-part, core/group', $query );
		$this->assertStringContainsString( 'live template block count 3', $query );
		$this->assertStringContainsString( 'viewport-constrained blocks 1', $query );
		$this->assertStringContainsString( 'overall density balanced', $query );
		$this->assertStringContainsString( 'template locations header x1', $query );
	}

	public function test_supported_block_style_paths_follow_block_support_manifest(): void {
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
				'fontSizes'    => [
					[
						'slug' => 'body',
						'size' => '1rem',
					],
				],
				'fontFamilies' => [
					[
						'slug'       => 'display',
						'fontFamily' => 'Georgia, serif',
					],
				],
				'lineHeight'   => true,
			],
			'spacing'    => [
				'spacingSizes' => [
					[
						'slug' => 's',
						'size' => '0.5rem',
					],
				],
				'blockGap'     => true,
			],
			'border'     => [
				'color'  => true,
				'radius' => true,
				'style'  => true,
				'width'  => true,
			],
			'shadow'     => [
				'presets' => [
					[
						'slug'   => 'soft',
						'shadow' => '0 10px 30px rgba(0,0,0,0.1)',
					],
				],
			],
		];

		$paths = $this->invoke_private_array_method(
			StyleAbilities::class,
			'supported_block_style_paths_from_manifest',
			[
				[
					'title'    => 'Paragraph',
					'supports' => [
						'color'      => [
							'background' => true,
							'text'       => true,
						],
						'typography' => [
							'fontSize'   => true,
							'fontFamily' => true,
							'lineHeight' => true,
						],
						'spacing'    => [
							'blockGap' => true,
						],
						'border'     => [
							'color'  => true,
							'radius' => true,
							'style'  => true,
							'width'  => true,
						],
						'shadow'     => true,
						'customCSS'  => true,
						'background' => [
							'backgroundImage' => true,
						],
					],
				],
			]
		);

		$this->assertContains(
			[
				'path'        => [ 'color', 'background' ],
				'valueSource' => 'color',
			],
			$paths
		);
		$this->assertContains(
			[
				'path'        => [ 'typography', 'fontFamily' ],
				'valueSource' => 'font-family',
			],
			$paths
		);
		$this->assertContains(
			[
				'path'        => [ 'shadow' ],
				'valueSource' => 'shadow',
			],
			$paths
		);
		$this->assertNotContains(
			[
				'path'        => [ 'customCSS' ],
				'valueSource' => 'freeform',
			],
			$paths
		);
		$this->assertNotContains(
			[
				'path'        => [ 'background', 'backgroundImage' ],
				'valueSource' => 'freeform',
			],
			$paths
		);
	}

	private function build_cache_key( string $query, int $max_results ): string {
		$method = new ReflectionMethod( AISearchClient::class, 'build_cache_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $query, $max_results );

		$this->assertIsString( $result );

		return $result;
	}

	private function configure_text_generation_connector(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => 'openai',
		];
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
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [
			'openai' => true,
		];
	}

	private function prime_default_docs_grounding(): void {
		$this->prime_current_docs_source_coverage();
		$this->route_docs_grounding_search(
			[
				$this->docs_grounding_chunk(
					'Global Styles developer guidance',
					'https://developer.wordpress.org/themes/global-settings-and-styles/',
					'Use supported theme.json and Global Styles controls for style recommendations.'
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
}
