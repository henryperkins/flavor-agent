<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AISearchClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_search_queries_cloudflare_ai_search_and_normalizes_chunks(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
			'flavor_agent_cloudflare_ai_search_max_results' => 6,
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'search_query' => 'rewritten query',
						'chunks'       => [
							[
								'id'    => 'chunk-1',
								'score' => 0.91,
								'item'  => [
									'key'      => 'developer.wordpress.org/rest-api/reference/wp_template_parts',
									'metadata' => [],
								],
								'text'  => "---\nsource_url: \"https://developer.wordpress.org/rest-api/reference/wp_template_parts/\"\n---\nWhere the template part is intended for use (header, footer, etc).",
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::search( 'template part area guidance' );

		$this->assertIsArray( $result );
		$this->assertSame( 'rewritten query', $result['query'] );
		$this->assertCount( 1, $result['guidance'] );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/instances/wp-dev-docs/search',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertSame(
			'Bearer token-xyz',
			WordPressTestState::$last_remote_post['args']['headers']['Authorization']
		);

		$request_body = json_decode(
			WordPressTestState::$last_remote_post['args']['body'],
			true
		);

		$this->assertSame( 'template part area guidance', $request_body['messages'][0]['content'] );
		$this->assertSame( 6, $request_body['ai_search_options']['retrieval']['max_num_results'] );
		$this->assertSame(
			[
				'id'        => 'chunk-1',
				'title'     => '',
				'sourceKey' => 'developer.wordpress.org/rest-api/reference/wp_template_parts',
				'url'       => 'https://developer.wordpress.org/rest-api/reference/wp_template_parts/',
				'excerpt'   => 'Where the template part is intended for use (header, footer, etc).',
				'score'     => 0.91,
			],
			$result['guidance'][0]
		);
		$this->assertSame(
			$result['guidance'],
			WordPressTestState::$transients[ $this->build_cache_key( 'template part area guidance', 6 ) ]
		);
	}

	public function test_search_uses_built_in_public_search_endpoint_without_authorization_when_legacy_credentials_are_missing(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'search_query' => 'rewritten query',
						'chunks'       => [
							[
								'id'    => 'chunk-1',
								'score' => 0.91,
								'item'  => [
									'key'      => 'developer.wordpress.org/rest-api/reference/wp_template_parts',
									'metadata' => [],
								],
								'text'  => "---\nsource_url: \"https://developer.wordpress.org/rest-api/reference/wp_template_parts/\"\n---\nWhere the template part is intended for use (header, footer, etc).",
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::search( 'template part area guidance' );

		$this->assertIsArray( $result );
		$this->assertSame(
			'https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/search',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertArrayNotHasKey(
			'Authorization',
			WordPressTestState::$last_remote_post['args']['headers']
		);
	}

	public function test_search_normalizes_crlf_frontmatter_before_extracting_url_and_excerpt(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
			'flavor_agent_cloudflare_ai_search_max_results' => 4,
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'search_query' => 'template part area guidance',
						'chunks'       => [
							[
								'id'    => 'chunk-1',
								'score' => 0.8,
								'item'  => [
									'key'      => 'developer.wordpress.org/rest-api/reference/wp_template_parts',
									'metadata' => [],
								],
								'text'  => "---\r\nsource_url: \"https://developer.wordpress.org/rest-api/reference/wp_template_parts/\"\r\n---\r\nWhere the template part is intended for use.",
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::search( 'template part area guidance' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['guidance'] );
		$this->assertSame(
			'https://developer.wordpress.org/rest-api/reference/wp_template_parts/',
			$result['guidance'][0]['url']
		);
		$this->assertSame(
			'Where the template part is intended for use.',
			$result['guidance'][0]['excerpt']
		);
		$this->assertStringNotContainsString( 'source_url', $result['guidance'][0]['excerpt'] );
	}

	public function test_search_rejects_chunks_without_explicit_trusted_urls_even_with_trusted_source_keys(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
			'flavor_agent_cloudflare_ai_search_max_results' => 4,
		];
			WordPressTestState::$remote_post_response = [
				'response' => [
					'code' => 200,
				],
				'body'     => wp_json_encode(
					[
						'result' => [
							'search_query' => 'block editor',
							'chunks'       => [
								[
									'id'    => 'chunk-1',
									'score' => 0.76,
									'item'  => [
										'key'      => 'ai-search/wp-dev-docs/developer.wordpress.org/rest-api/reference/blocks/20783ff926859519ef7fb001db48a93ffe461fec8c5d4d02505544331fff64d2/part-0001.md',
										'metadata' => [],
									],
									'text'  => 'Malicious non-doc content that only borrows a trusted-looking source key.',
								],
							],
						],
					]
				),
			];

			$result = AISearchClient::search( 'block editor' );

			$this->assertIsArray( $result );
			$this->assertSame( [], $result['guidance'] );
	}

	public function test_search_rejects_forged_or_traversing_source_keys_when_urls_are_missing(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
			'flavor_agent_cloudflare_ai_search_max_results' => 4,
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'search_query' => 'block editor',
						'chunks'       => [
							[
								'id'    => 'forged-prefix',
								'score' => 0.51,
								'item'  => [
									'key'      => 'internal/developer.wordpress.org/rest-api/reference/blocks/part-0001.md',
									'metadata' => [],
								],
								'text'  => 'Forged source keys must never be trusted.',
							],
							[
								'id'    => 'traversal',
								'score' => 0.5,
								'item'  => [
									'key'      => 'developer.wordpress.org/../rest-api/reference/blocks/part-0001.md',
									'metadata' => [],
								],
								'text'  => 'Traversal segments must never normalize to docs URLs.',
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::search( 'block editor' );

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['guidance'] );
	}

	public function test_validate_configuration_uses_probe_search_only(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'   => 'probe-chunk',
								'item' => [
									'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
									'metadata' => [],
								],
								'text' => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\n---\nUse block supports to expose design tools.",
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::validate_configuration();

		$this->assertSame(
			[
				'id'      => 'wp-dev-docs',
				'source'  => '',
				'enabled' => true,
				'paused'  => false,
			],
			$result
		);
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/instances/wp-dev-docs/search',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );

		$request_body = json_decode(
			WordPressTestState::$last_remote_post['args']['body'],
			true
		);

		$this->assertSame( 'block editor', $request_body['messages'][0]['content'] );
		$this->assertSame( 3, $request_body['ai_search_options']['retrieval']['max_num_results'] );
	}

	public function test_validate_configuration_rejects_probe_results_without_explicit_trusted_urls(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'   => 'forged-probe',
								'item' => [
									'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
									'metadata' => [],
								],
								'text' => 'Malicious non-doc content that only borrows a trusted-looking source key.',
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::validate_configuration(
			'account-123',
			'wp-dev-docs',
			'token-xyz'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_ai_search_validation_untrusted_source', $result->get_error_code() );
	}

	public function test_validate_configuration_surfaces_cloudflare_errors(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 403,
			],
			'body'     => wp_json_encode(
				[
					'errors' => [
						[
							'message' => 'Authentication error',
						],
					],
				]
			),
		];

		$result = AISearchClient::validate_configuration(
			'account-123',
			'wp-dev-docs',
			'token-xyz'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_ai_search_validation_error', $result->get_error_code() );
		$this->assertSame( 'Authentication error', $result->get_error_message() );
	}

	public function test_validate_configuration_surfaces_parse_errors_from_probe_search(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => '{invalid-json',
		];

		$result = AISearchClient::validate_configuration(
			'account-123',
			'wp-dev-docs',
			'token-xyz'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_ai_search_validation_parse_error', $result->get_error_code() );
		$this->assertSame(
			'Failed to parse Cloudflare AI Search response.',
			$result->get_error_message()
		);
	}

	public function test_validate_configuration_rejects_instances_without_trusted_wordpress_docs_probe_results(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'   => 'private-chunk',
								'item' => [
									'key'      => 'internal/wiki/theme-roadmap',
									'metadata' => [
										'url' => 'https://intranet.example.com/wiki/theme-roadmap',
									],
								],
								'text' => 'Private roadmap content that should never reach editor prompts.',
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::validate_configuration(
			'account-123',
			'wp-dev-docs',
			'token-xyz'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_ai_search_validation_untrusted_source', $result->get_error_code() );
		$this->assertSame(
			'Cloudflare AI Search validation could not confirm trusted developer.wordpress.org content from this instance. Use the official WordPress developer docs index before saving these credentials.',
			$result->get_error_message()
		);
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_validate_configuration_rejects_probe_results_with_forged_source_keys(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'   => 'forged-probe',
								'item' => [
									'key'      => 'internal/developer.wordpress.org/../rest-api/reference/blocks/part-0001.md',
									'metadata' => [],
								],
								'text' => 'Forged source keys must not satisfy trusted-doc validation.',
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::validate_configuration(
			'account-123',
			'wp-dev-docs',
			'token-xyz'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_ai_search_validation_untrusted_source', $result->get_error_code() );
	}

	public function test_search_uses_public_search_endpoint_by_default_when_no_legacy_credentials_are_saved(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'search_query' => 'template parts',
						'chunks'       => [],
					],
				]
			),
		];

		$result = AISearchClient::search( 'template parts' );

		$this->assertIsArray( $result );
		$this->assertSame(
			'https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/search',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertSame( [], AISearchClient::maybe_search( 'template parts' ) );
	}

	public function test_maybe_search_returns_empty_array_on_cache_miss_without_querying_cloudflare(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$result = AISearchClient::maybe_search( 'missing cache query', 3 );

		$this->assertSame( [], $result );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_maybe_search_returns_cached_guidance_without_querying_cloudflare(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$transients[ $this->build_cache_key( 'template part area guidance', 4 ) ] = [
			[
				'id'        => 'chunk-1',
				'title'     => 'Template parts REST reference',
				'sourceKey' => 'developer.wordpress.org/rest-api/reference/wp_template_parts',
				'url'       => 'https://developer.wordpress.org/rest-api/reference/wp_template_parts/',
				'excerpt'   => 'Template part areas define where a template part can be used.',
				'score'     => 0.87,
			],
		];

		$result = AISearchClient::maybe_search( 'template part area guidance', 4 );

		$this->assertSame(
			WordPressTestState::$transients[ $this->build_cache_key( 'template part area guidance', 4 ) ],
			$result
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_maybe_search_with_entity_fallback_prefers_query_cache(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$query_guidance  = [
			[
				'id'        => 'query-chunk',
				'title'     => 'Navigation layout guidance',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
				'excerpt'   => 'Specific footer navigation guidance.',
				'score'     => 0.95,
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

		WordPressTestState::$transients[ $this->build_cache_key( 'navigation footer guidance', 4 ) ] = $query_guidance;
		WordPressTestState::$transients[ $this->build_entity_cache_key( 'core/navigation' ) ]        = $entity_guidance;

		$result = AISearchClient::maybe_search_with_entity_fallback(
			'navigation footer guidance',
			'core/navigation',
			4
		);

		$this->assertSame( $query_guidance, $result );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_cache_keys_are_scoped_to_the_configured_cloudflare_instance(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$family_context = [
			'surface'   => 'navigation',
			'entityKey' => 'core/navigation',
			'location'  => 'footer',
		];
		$query_key_a    = $this->build_cache_key( 'navigation footer guidance', 4 );
		$family_key_a   = $this->build_family_cache_key( $family_context, 4 );
		$entity_key_a   = $this->build_entity_cache_key( 'core/navigation' );

		WordPressTestState::$options['flavor_agent_cloudflare_ai_search_api_token'] = 'rotated-token';

		$this->assertSame( $query_key_a, $this->build_cache_key( 'navigation footer guidance', 4 ) );
		$this->assertSame( $family_key_a, $this->build_family_cache_key( $family_context, 4 ) );
		$this->assertSame( $entity_key_a, $this->build_entity_cache_key( 'core/navigation' ) );

		WordPressTestState::$options['flavor_agent_cloudflare_ai_search_instance_id'] = 'wp-dev-docs-preview';

		$this->assertNotSame( $query_key_a, $this->build_cache_key( 'navigation footer guidance', 4 ) );
		$this->assertNotSame( $family_key_a, $this->build_family_cache_key( $family_context, 4 ) );
		$this->assertNotSame( $entity_key_a, $this->build_entity_cache_key( 'core/navigation' ) );
	}

	public function test_maybe_search_entity_returns_cached_guidance_without_querying_cloudflare(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$transients[ $this->build_entity_cache_key( 'core/navigation' ) ] = [
			[
				'id'        => 'chunk-1',
				'title'     => 'Navigation block reference',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
				'excerpt'   => 'The Navigation block manages menu structure and responsive behavior.',
				'score'     => 0.92,
			],
		];

		$result = AISearchClient::maybe_search_entity( 'core/navigation' );

		$this->assertSame(
			WordPressTestState::$transients[ $this->build_entity_cache_key( 'core/navigation' ) ],
			$result
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_maybe_search_with_entity_fallback_uses_entity_cache_on_query_miss(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$entity_guidance = [
			[
				'id'        => 'entity-chunk',
				'title'     => 'Template hierarchy',
				'sourceKey' => 'developer.wordpress.org/themes/templates/template-hierarchy',
				'url'       => 'https://developer.wordpress.org/themes/templates/template-hierarchy/',
				'excerpt'   => '404 templates should prioritize recovery paths.',
				'score'     => 0.88,
			],
		];

		WordPressTestState::$transients[ $this->build_entity_cache_key( 'template:404' ) ] = $entity_guidance;

		$result = AISearchClient::maybe_search_with_entity_fallback(
			'missing query cache',
			'template:404',
			4
		);

		$this->assertSame( $entity_guidance, $result );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_maybe_search_with_cache_fallbacks_prefers_family_cache_before_entity_cache(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$family_context  = [
			'surface'         => 'block',
			'entityKey'       => 'core/navigation',
			'location'        => 'footer',
			'structuralRole'  => 'footer-navigation',
			'inspectorPanels' => [ 'color', 'spacing' ],
		];
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

		WordPressTestState::$transients[ $this->build_family_cache_key( $family_context, 4 ) ] = $family_guidance;
		WordPressTestState::$transients[ $this->build_entity_cache_key( 'core/navigation' ) ]  = $entity_guidance;

		$result = AISearchClient::maybe_search_with_cache_fallbacks(
			'missing query cache',
			'core/navigation',
			$family_context,
			4
		);

		$this->assertSame( $family_guidance, $result );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
		$this->assertArrayNotHasKey( AISearchClient::CONTEXT_WARM_CRON_HOOK, WordPressTestState::$scheduled_events );
	}

	public function test_maybe_search_with_entity_fallback_ignores_invalid_entity_keys(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$query_guidance = [
			[
				'id'        => 'query-chunk',
				'title'     => 'Template parts REST reference',
				'sourceKey' => 'developer.wordpress.org/rest-api/reference/wp_template_parts',
				'url'       => 'https://developer.wordpress.org/rest-api/reference/wp_template_parts/',
				'excerpt'   => 'Template part areas define where a template part can be used.',
				'score'     => 0.87,
			],
		];

		WordPressTestState::$transients[ $this->build_cache_key( 'template part area guidance', 4 ) ] = $query_guidance;

		$this->assertSame(
			$query_guidance,
			AISearchClient::maybe_search_with_entity_fallback(
				'template part area guidance',
				'not-a-valid-entity-key',
				4
			)
		);
		$this->assertSame(
			[],
			AISearchClient::maybe_search_with_entity_fallback(
				'missing query cache',
				'not-a-valid-entity-key',
				4
			)
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_maybe_search_with_cache_fallbacks_queues_async_warm_after_query_and_family_miss(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$family_context  = [
			'surface'      => 'template',
			'entityKey'    => 'template:404',
			'templateType' => '404',
			'allowedAreas' => [ 'footer', 'header' ],
		];
		$entity_guidance = [
			[
				'id'        => 'entity-chunk',
				'title'     => 'Template hierarchy',
				'sourceKey' => 'developer.wordpress.org/themes/templates/template-hierarchy',
				'url'       => 'https://developer.wordpress.org/themes/templates/template-hierarchy/',
				'excerpt'   => '404 templates should prioritize recovery paths.',
				'score'     => 0.88,
			],
		];

		WordPressTestState::$transients[ $this->build_entity_cache_key( 'template:404' ) ] = $entity_guidance;

		$result = AISearchClient::maybe_search_with_cache_fallbacks(
			'missing query cache',
			'template:404',
			$family_context,
			4
		);

		$this->assertSame( $entity_guidance, $result );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
		$this->assertArrayHasKey( AISearchClient::CONTEXT_WARM_CRON_HOOK, WordPressTestState::$scheduled_events );

		$queue = WordPressTestState::$options['flavor_agent_docs_warm_queue'] ?? [];

		$this->assertCount( 1, $queue );
		$this->assertSame( 'missing query cache', array_values( $queue )[0]['query'] ?? '' );
	}

	public function test_maybe_search_with_cache_fallbacks_uses_generic_guidance_when_entity_cache_is_cold(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$remote_post_response = new \WP_Error(
			'http_request_failed',
			'Cloudflare timed out.'
		);

		$family_context   = [
			'surface'   => 'template-part',
			'entityKey' => 'core/template-part',
			'area'      => 'header',
			'slug'      => 'marketing-header',
		];
		$generic_guidance = [
			[
				'id'        => 'generic-chunk',
				'title'     => 'Template parts overview',
				'sourceKey' => 'developer.wordpress.org/themes/templates/template-parts',
				'url'       => 'https://developer.wordpress.org/themes/templates/template-parts/',
				'excerpt'   => 'Template parts should stay focused and reusable.',
				'score'     => 0.83,
			],
		];

		WordPressTestState::$transients[ $this->build_entity_cache_key( 'guidance:template-part' ) ] = $generic_guidance;

		$result = AISearchClient::maybe_search_with_cache_fallbacks(
			'missing query cache',
			'core/template-part',
			$family_context,
			4
		);

		$this->assertSame( $generic_guidance, $result );
		$this->assertSame( 5, WordPressTestState::$last_remote_post['args']['timeout'] );
		$this->assertArrayHasKey(
			AISearchClient::CONTEXT_WARM_CRON_HOOK,
			WordPressTestState::$scheduled_events
		);
	}

	public function test_maybe_search_with_cache_fallbacks_prefers_foreground_warm_over_generic_guidance_when_live_search_succeeds(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'    => 'fresh-chunk',
								'score' => 0.92,
								'item'  => [
									'key'      => 'developer.wordpress.org/themes/templates/template-parts',
									'metadata' => [],
								],
								'text'  => "---\nsource_url: \"https://developer.wordpress.org/themes/templates/template-parts/\"\n---\nHeader template parts should stay focused and reusable.",
							],
						],
					],
				]
			),
		];

		$family_context   = [
			'surface'   => 'template-part',
			'entityKey' => 'core/template-part',
			'area'      => 'header',
			'slug'      => 'marketing-header',
		];
		$generic_guidance = [
			[
				'id'        => 'generic-chunk',
				'title'     => 'Template parts overview',
				'sourceKey' => 'developer.wordpress.org/themes/templates/template-parts',
				'url'       => 'https://developer.wordpress.org/themes/templates/template-parts/',
				'excerpt'   => 'Template parts should stay focused and reusable.',
				'score'     => 0.83,
			],
		];

		WordPressTestState::$transients[ $this->build_entity_cache_key( 'guidance:template-part' ) ] = $generic_guidance;

		$result = AISearchClient::maybe_search_with_cache_fallbacks(
			'header template part guidance',
			'core/template-part',
			$family_context,
			4
		);

		$this->assertSame( 'fresh-chunk', $result[0]['id'] );
		$this->assertSame( 5, WordPressTestState::$last_remote_post['args']['timeout'] );
		$this->assertArrayNotHasKey( AISearchClient::CONTEXT_WARM_CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertSame(
			'fresh-chunk',
			WordPressTestState::$transients[ $this->build_cache_key( 'header template part guidance', 4 ) ][0]['id']
		);
		$this->assertSame(
			'fresh-chunk',
			WordPressTestState::$transients[ $this->build_family_cache_key( $family_context, 4 ) ][0]['id']
		);
		$this->assertSame(
			'fresh-chunk',
			WordPressTestState::$transients[ $this->build_entity_cache_key( 'core/template-part' ) ][0]['id']
		);

		$runtime_state = AISearchClient::get_runtime_state();

		$this->assertSame( 'healthy', $runtime_state['status'] );
		$this->assertSame( 'foreground', $runtime_state['lastTrustedSuccessMode'] );
		$this->assertSame( 'foreground', $runtime_state['lastServedMode'] );
		$this->assertSame( 'fresh', $runtime_state['lastFallbackType'] );
	}

	public function test_maybe_search_with_cache_fallbacks_prefers_foreground_warm_for_style_book_when_live_search_succeeds(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'    => 'fresh-style-book-chunk',
								'score' => 0.94,
								'item'  => [
									'key'      => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph',
									'metadata' => [],
								],
								'text'  => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph/\"\n---\nParagraph blocks should use supported typography and color controls in Style Book.",
							],
						],
					],
				]
			),
		];

		$family_context   = [
			'surface'   => 'style-book',
			'entityKey' => 'core/paragraph',
			'blockName' => 'core/paragraph',
		];
		$generic_guidance = [
			[
				'id'        => 'generic-style-book-chunk',
				'title'     => 'Style Book guidance',
				'sourceKey' => 'developer.wordpress.org/themes/global-settings-and-styles/style-book',
				'url'       => 'https://developer.wordpress.org/themes/global-settings-and-styles/style-book/',
				'excerpt'   => 'Generic Style Book guidance.',
				'score'     => 0.81,
			],
		];

		WordPressTestState::$transients[ $this->build_entity_cache_key( 'guidance:style-book' ) ] = $generic_guidance;

		$result = AISearchClient::maybe_search_with_cache_fallbacks(
			'paragraph style book guidance',
			'core/paragraph',
			$family_context,
			4
		);

		$this->assertSame( 'fresh-style-book-chunk', $result[0]['id'] );
		$this->assertSame( 5, WordPressTestState::$last_remote_post['args']['timeout'] );
		$this->assertArrayNotHasKey( AISearchClient::CONTEXT_WARM_CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertSame(
			'fresh-style-book-chunk',
			WordPressTestState::$transients[ $this->build_cache_key( 'paragraph style book guidance', 4 ) ][0]['id']
		);
		$this->assertSame(
			'fresh-style-book-chunk',
			WordPressTestState::$transients[ $this->build_family_cache_key( $family_context, 4 ) ][0]['id']
		);
		$this->assertSame(
			'fresh-style-book-chunk',
			WordPressTestState::$transients[ $this->build_entity_cache_key( 'core/paragraph' ) ][0]['id']
		);
	}

	public function test_maybe_search_with_cache_fallbacks_returns_generic_style_book_guidance_and_queues_async_warm_when_live_search_fails(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$remote_post_response = new \WP_Error(
			'http_request_failed',
			'Cloudflare timed out.'
		);

		$family_context   = [
			'surface'   => 'style-book',
			'entityKey' => 'core/paragraph',
			'blockName' => 'core/paragraph',
		];
		$generic_guidance = [
			[
				'id'        => 'generic-style-book-chunk',
				'title'     => 'Style Book guidance',
				'sourceKey' => 'developer.wordpress.org/themes/global-settings-and-styles/style-book',
				'url'       => 'https://developer.wordpress.org/themes/global-settings-and-styles/style-book/',
				'excerpt'   => 'Generic Style Book guidance.',
				'score'     => 0.81,
			],
		];

		WordPressTestState::$transients[ $this->build_entity_cache_key( 'guidance:style-book' ) ] = $generic_guidance;

		$result = AISearchClient::maybe_search_with_cache_fallbacks(
			'paragraph style book guidance',
			'core/paragraph',
			$family_context,
			4
		);

		$this->assertSame( $generic_guidance, $result );
		$this->assertSame( 5, WordPressTestState::$last_remote_post['args']['timeout'] );
		$this->assertArrayHasKey( AISearchClient::CONTEXT_WARM_CRON_HOOK, WordPressTestState::$scheduled_events );

		$queue = WordPressTestState::$options['flavor_agent_docs_warm_queue'] ?? [];

		$this->assertCount( 1, $queue );
		$this->assertSame( 'paragraph style book guidance', array_values( $queue )[0]['query'] ?? '' );
	}

	public function test_process_context_warm_queue_retries_failed_entries_with_backoff(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$remote_post_response = new \WP_Error(
			'http_request_failed',
			'Cloudflare timed out.'
		);

		$family_context = [
			'surface'        => 'block',
			'entityKey'      => 'core/navigation',
			'location'       => 'footer',
			'structuralRole' => 'footer-navigation',
		];

		AISearchClient::schedule_context_warm(
			'navigation footer guidance',
			'core/navigation',
			$family_context,
			4
		);
		AISearchClient::process_context_warm_queue();

		$queue = WordPressTestState::$options['flavor_agent_docs_warm_queue'] ?? [];

		$this->assertCount( 1, $queue );

		$entry = array_values( $queue )[0];

		$this->assertSame( 1, $entry['attempts'] ?? null );
		$this->assertSame( 'http_request_failed', $entry['lastErrorCode'] ?? null );
		$this->assertSame( 'Cloudflare timed out.', $entry['lastErrorMessage'] ?? null );
		$this->assertIsInt( $entry['nextAttemptAt'] ?? null );
		$this->assertGreaterThanOrEqual( time() + 50, $entry['nextAttemptAt'] ?? 0 );
		$this->assertArrayHasKey( AISearchClient::CONTEXT_WARM_CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertSame(
			$entry['nextAttemptAt'],
			WordPressTestState::$scheduled_events[ AISearchClient::CONTEXT_WARM_CRON_HOOK ]['timestamp']
		);

		$runtime_state = AISearchClient::get_runtime_state();

		$this->assertSame( 'retrying', $runtime_state['status'] );
		$this->assertSame( 'Cloudflare timed out.', $runtime_state['lastErrorMessage'] );
		$this->assertSame( 'async', $runtime_state['lastErrorMode'] );
	}

	public function test_process_context_warm_queue_seeds_exact_family_and_entity_caches(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$remote_post_responses = [
			new \WP_Error( 'http_request_failed', 'Foreground warm failed.' ),
			[
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
										'key'      => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation',
										'metadata' => [],
									],
									'text'  => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/\"\n---\nUse clear labels and keep footer navigation compact.",
								],
							],
						],
					]
				),
			],
		];

		$family_context = [
			'surface'         => 'block',
			'entityKey'       => 'core/navigation',
			'location'        => 'footer',
			'structuralRole'  => 'footer-navigation',
			'inspectorPanels' => [ 'color', 'spacing' ],
		];

		$this->assertSame(
			[],
			AISearchClient::maybe_search_with_cache_fallbacks(
				'navigation footer guidance',
				'core/navigation',
				$family_context,
				4
			)
		);
		$this->assertSame( 5, WordPressTestState::$last_remote_post['args']['timeout'] );

		AISearchClient::process_context_warm_queue();

		$this->assertArrayHasKey(
			$this->build_cache_key( 'navigation footer guidance', 4 ),
			WordPressTestState::$transients
		);
		$this->assertArrayHasKey(
			$this->build_family_cache_key( $family_context, 4 ),
			WordPressTestState::$transients
		);
		$this->assertArrayHasKey(
			$this->build_entity_cache_key( 'core/navigation' ),
			WordPressTestState::$transients
		);
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/instances/wp-dev-docs/search',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertSame( [], WordPressTestState::$options['flavor_agent_docs_warm_queue'] ?? [] );
	}

	public function test_search_filters_chunks_to_official_wordpress_docs_sources(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'   => 'allowed-chunk',
								'item' => [
									'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
									'metadata' => [],
								],
								'text' => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\n---\nUse block supports to expose design tools.",
							],
							[
								'id'   => 'private-chunk',
								'item' => [
									'key'      => 'internal/wiki/theme-roadmap',
									'metadata' => [
										'url' => 'https://intranet.example.com/wiki/theme-roadmap',
									],
								],
								'text' => 'Private roadmap content that should never reach editor prompts.',
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::search( 'block supports guidance' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['guidance'] );
		$this->assertSame( 'allowed-chunk', $result['guidance'][0]['id'] );
		$this->assertSame(
			'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/',
			$result['guidance'][0]['url']
		);
	}

	public function test_search_rejects_untrusted_and_conflicting_guidance_urls(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'   => 'valid-chunk',
								'item' => [
									'key'      => 'developer.wordpress.org/rest-api/reference/wp_template_parts',
									'metadata' => [],
								],
								'text' => "---\nsource_url: \"https://developer.wordpress.org/rest-api/reference/wp_template_parts/\"\n---\nTemplate part areas define where a template part can be used.",
							],
							[
								'id'   => 'javascript-host-bypass',
								'item' => [
									'key'      => 'developer.wordpress.org/rest-api/reference/wp_template_parts',
									'metadata' => [
										'url' => 'javascript://developer.wordpress.org/%0Aalert(1)',
									],
								],
								'text' => 'Hostile URL should be dropped.',
							],
							[
								'id'   => 'http-docs-url',
								'item' => [
									'key'      => 'developer.wordpress.org/rest-api/reference/wp_template_parts',
									'metadata' => [],
								],
								'text' => "---\nsource_url: \"http://developer.wordpress.org/rest-api/reference/wp_template_parts/\"\n---\nHTTP URLs should be dropped.",
							],
							[
								'id'   => 'metadata-frontmatter-conflict',
								'item' => [
									'key'      => 'developer.wordpress.org/rest-api/reference/wp_template_parts',
									'metadata' => [
										'url' => 'https://wordpress.org/news/',
									],
								],
								'text' => "---\nsource_url: \"https://developer.wordpress.org/rest-api/reference/wp_template_parts/\"\n---\nConflicting URL sources should be dropped.",
							],
							[
								'id'   => 'source-key-url-mismatch',
								'item' => [
									'key'      => 'developer.wordpress.org/rest-api/reference/wp_template_parts',
									'metadata' => [
										'url' => 'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/',
									],
								],
								'text' => 'Mismatched official docs URLs should be dropped.',
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::search( 'template part guidance' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['guidance'] );
		$this->assertSame( 'valid-chunk', $result['guidance'][0]['id'] );
		$this->assertSame(
			'https://developer.wordpress.org/rest-api/reference/wp_template_parts/',
			$result['guidance'][0]['url']
		);
	}

	public function test_explicit_search_errors_still_surface_while_maybe_search_stays_quiet(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$remote_post_response = new \WP_Error(
			'http_request_failed',
			'Cloudflare timed out.'
		);

		$this->assertSame( [], AISearchClient::maybe_search( 'template part guidance', 2 ) );
		$this->assertSame( [], WordPressTestState::$last_remote_post );

		$result = AISearchClient::search( 'template part guidance', 2 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/instances/wp-dev-docs/search',
			WordPressTestState::$last_remote_post['url']
		);
	}

	public function test_sanitize_excerpt_truncates_utf8_text_without_breaking_characters(): void {
		$method = new \ReflectionMethod( AISearchClient::class, 'sanitize_excerpt' );
		$method->setAccessible( true );
		$text   = str_repeat( 'é', 400 );
		$result = $method->invoke( null, $text );

		$this->assertSame( 360, function_exists( 'mb_strlen' ) ? mb_strlen( $result, 'UTF-8' ) : strlen( $result ) );
		$this->assertStringEndsWith( '...', $result );
		$this->assertStringNotContainsString( "\xef\xbf\xbd", $result );
	}

	public function test_normalize_family_context_drops_recursive_objects_without_fatal_error(): void {
		$method = new \ReflectionMethod( AISearchClient::class, 'normalize_family_context' );
		$method->setAccessible( true );

		$recursive       = new \stdClass();
		$recursive->self = $recursive;

		$result = $method->invoke(
			null,
			[
				'recursive' => $recursive,
				'surface'   => 'block',
			]
		);

		$this->assertSame(
			[
				'surface' => 'block',
			],
			$result
		);
	}

	public function test_resolve_entity_key_prefers_explicit_entity_key_before_legacy_query_inference(): void {
		$this->assertSame(
			'core/navigation',
			AISearchClient::resolve_entity_key(
				'Core/Navigation',
				'WordPress Gutenberg block editor best practices. block type core/query. theme.json guidance.'
			)
		);
		$this->assertSame(
			'template:404',
			AISearchClient::resolve_entity_key(
				'template:404',
				'WordPress block theme guidance. template type single. template parts and theme.json.'
			)
		);
		$this->assertSame(
			'core/navigation',
			AISearchClient::resolve_entity_key(
				'not-a-valid-entity-key',
				'WordPress Gutenberg block editor best practices. block type core/navigation. theme.json guidance.'
			)
		);
		$this->assertSame(
			'',
			AISearchClient::resolve_entity_key( 'not-a-valid-entity-key', 'No matching entity hint.' )
		);
	}

	public function test_infer_entity_key_from_query_detects_block_and_template_entities(): void {
		$this->assertSame(
			'core/navigation',
			AISearchClient::infer_entity_key_from_query(
				'WordPress Gutenberg block editor best practices. block type core/navigation. theme.json guidance.'
			)
		);
		$this->assertSame(
			'template:404',
			AISearchClient::infer_entity_key_from_query(
				'WordPress block theme guidance. template type 404. template parts and theme.json.'
			)
		);
	}

	public function test_normalize_public_search_url_rewrites_mcp_endpoints_to_search(): void {
		$method = new \ReflectionMethod( AISearchClient::class, 'normalize_public_search_url' );
		$method->setAccessible( true );

		$result = $method->invoke(
			null,
			'https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/mcp'
		);

		$this->assertSame(
			'https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/search',
			$result
		);
	}

	private function build_cache_key( string $query, int $max_results ): string {
		$method = new \ReflectionMethod( AISearchClient::class, 'build_cache_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $query, $max_results );

		$this->assertIsString( $result );

		return $result;
	}

	private function build_entity_cache_key( string $entity_key ): string {
		$method = new \ReflectionMethod( AISearchClient::class, 'build_entity_cache_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $entity_key );

		$this->assertIsString( $result );

		return $result;
	}

	/**
	 * @param array<string, mixed> $family_context
	 */
	private function build_family_cache_key( array $family_context, int $max_results ): string {
		$method = new \ReflectionMethod( AISearchClient::class, 'build_family_cache_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $family_context, $max_results );

		$this->assertIsString( $result );

		return $result;
	}
}
