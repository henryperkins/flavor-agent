<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Support\DocsGuidanceResult;
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
				'https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/search',
				WordPressTestState::$last_remote_post['url']
			);
			$this->assertArrayNotHasKey(
				'Authorization',
				WordPressTestState::$last_remote_post['args']['headers']
			);

		$request_body = json_decode(
			WordPressTestState::$last_remote_post['args']['body'],
			true
		);

		$this->assertSame( 'template part area guidance', $request_body['messages'][0]['content'] );
		$this->assertSame( 6, $request_body['ai_search_options']['retrieval']['max_num_results'] );
		$this->assertSame(
			[
				'id'          => 'chunk-1',
				'title'       => '',
				'sourceKey'   => 'developer.wordpress.org/rest-api/reference/wp_template_parts',
				'sourceType'  => 'developer-docs',
				'url'         => 'https://developer.wordpress.org/rest-api/reference/wp_template_parts/',
				'excerpt'     => 'Where the template part is intended for use (header, footer, etc).',
				'score'       => 0.91,
				'retrievedAt' => '',
				'publishedAt' => '',
				'contentHash' => '',
				'freshness'   => 'unknown',
			],
			$result['guidance'][0]
		);
		$this->assertSame(
			$result['guidance'],
			WordPressTestState::$transients[ $this->build_cache_key( 'template part area guidance', 6 ) ]
		);
	}

	public function test_search_uses_built_in_public_search_endpoint_without_authorization(): void {
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

	public function test_search_ignores_saved_legacy_credentials_for_developer_docs(): void {
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
		$this->assertSame(
			'c5d54c4a-27df-4034-80da-ca6054684fcd',
			AISearchClient::configured_instance_id()
		);
	}

	public function test_search_accepts_current_cloudflare_chunk_shape_with_source_key_only(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'default/green-sun',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'search_query' => 'block editor',
					'chunks'       => [
						[
							'id'    => 'chunk-1',
							'score' => 0.88,
							'type'  => 'text',
							'item'  => [
								'key'       => 'ai-search/wp-dev-docs/developer.wordpress.org/block-editor/reference-guides/packages/packages-edit-widgets/17cdbd65c37e71c679c075cbf9b1443c019c399384140cae7323fcfa3ae26769/part-0001.md',
								'timestamp' => 1775925540000,
							],
							'text'  => 'The Widgets screen is another block editor in WordPress admin.',
						],
						[
							'id'    => 'chunk-2',
							'score' => 0.77,
							'type'  => 'text',
							'item'  => [
								'key'       => 'ai-search/c5d54c4a-27df-4034-80da-ca6054684fcd/developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/27cdbd65c37e71c679c075cbf9b1443c019c399384140cae7323fcfa3ae26769/part-0001.md',
								'timestamp' => 1775925540000,
							],
							'text'  => 'Instance-keyed chunks can derive the docs URL for the built-in public endpoint.',
						],
					],
				]
			),
		];

			$result = AISearchClient::search( 'block editor' );

			$this->assertIsArray( $result );
			$this->assertSame(
				'https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/search',
				WordPressTestState::$last_remote_post['url']
			);
		$this->assertCount( 2, $result['guidance'] );
		$this->assertSame(
			'https://developer.wordpress.org/block-editor/reference-guides/packages/packages-edit-widgets/',
			$result['guidance'][0]['url']
		);
		$this->assertSame(
			'The Widgets screen is another block editor in WordPress admin.',
			$result['guidance'][0]['excerpt']
		);
		$this->assertSame(
			'https://developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/',
			$result['guidance'][1]['url']
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

	public function test_search_preserves_frontmatter_provenance_and_freshness_metadata(): void {
		$chunk_text = "---\nsource_url: \"https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/\"\noriginal_url: \"https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\npublished_at: \"2026-03-24T21:40:44Z\"\ncontent_hash: abc123\n---\nWordPress 7.0 provides client-side abilities through @wordpress/abilities.";

		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'search_query' => 'WordPress 7.0 abilities',
						'chunks'       => [
							[
								'id'    => 'make-core-chunk',
								'score' => 0.94,
								'item'  => [
									'key'      => 'ai-search/wp-dev-docs/make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/136f765ace420bbea73a493de6698debade0c95a80f90ce5fc7dc1dbe2ebd6ff/part-0001.md',
									'metadata' => [
										'title' => 'Client-side abilities API in WordPress 7.0',
									],
								],
								'text'  => $chunk_text,
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::search( 'WordPress 7.0 abilities' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['guidance'] );
		$this->assertSame( 'make-core', $result['guidance'][0]['sourceType'] );
		$this->assertSame( '2026-05-08T14:00:00Z', $result['guidance'][0]['retrievedAt'] );
		$this->assertSame( '2026-03-24T21:40:44Z', $result['guidance'][0]['publishedAt'] );
		$this->assertSame( 'abc123', $result['guidance'][0]['contentHash'] );
		$this->assertSame( 'stale', $result['guidance'][0]['freshness'] );
	}

	public function test_search_accepts_trusted_ai_search_source_key_when_url_metadata_is_missing(): void {
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
								'text'  => 'REST API block reference guidance.',
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::search( 'block editor' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['guidance'] );
		$this->assertSame(
			'https://developer.wordpress.org/rest-api/reference/blocks/',
			$result['guidance'][0]['url']
		);
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

	public function test_validate_configuration_uses_probe_and_current_source_coverage_searches(): void {
		WordPressTestState::$options               = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		WordPressTestState::$remote_post_responses = [
			[
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
									'text' => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\n---\nUse block supports to expose design tools.",
								],
							],
						],
					]
				),
			],
			[
				'response' => [
					'code' => 200,
				],
				'body'     => wp_json_encode(
					[
						'result' => [
							'chunks' => [
								[
									'id'   => 'stable-docs',
									'item' => [
										'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
										'metadata' => [],
									],
									'text' => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\n---\nUse block supports to expose design tools.",
								],
								[
									'id'   => 'make-core',
									'item' => [
										'key'      => 'ai-search/wp-dev-docs/make.wordpress.org/core/2026/05/08/current-editor-guidance/136f765ace420bbea73a493de6698debade0c95a80f90ce5fc7dc1dbe2ebd6ff/part-0001.md',
										'metadata' => [],
									],
									'text' => "---\nsource_url: \"https://make.wordpress.org/core/2026/05/08/current-editor-guidance/\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\npublished_at: \"2026-05-08T13:00:00Z\"\n---\nWordPress 7.0 provides current editor guidance.",
								],
							],
						],
					]
				),
			],
		];

		$result = AISearchClient::validate_configuration();

		$this->assertSame(
			[
				'id'      => 'c5d54c4a-27df-4034-80da-ca6054684fcd',
				'source'  => 'public',
				'enabled' => true,
				'paused'  => false,
			],
			$result
		);
		$this->assertSame(
			'https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/search',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
		$this->assertCount( 2, WordPressTestState::$remote_post_calls );

		$request_body = json_decode(
			WordPressTestState::$remote_post_calls[0]['args']['body'],
			true
		);

		$this->assertSame( 'block editor', $request_body['messages'][0]['content'] );
		$this->assertSame( 3, $request_body['ai_search_options']['retrieval']['max_num_results'] );

		$coverage_body = json_decode(
			WordPressTestState::$remote_post_calls[1]['args']['body'],
			true
		);

		$this->assertSame(
			'WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes',
			$coverage_body['messages'][0]['content']
		);
		$this->assertSame( 8, $coverage_body['ai_search_options']['retrieval']['max_num_results'] );
	}

	public function test_validate_configuration_degrades_when_release_cycle_sources_are_missing(): void {
		WordPressTestState::$remote_post_responses = [
			[
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
									'text' => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\n---\nUse block supports to expose design tools.",
								],
							],
						],
					]
				),
			],
			[
				'response' => [
					'code' => 200,
				],
				'body'     => wp_json_encode(
					[
						'result' => [
							'chunks' => [
								[
									'id'   => 'stable-docs',
									'item' => [
										'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
										'metadata' => [],
									],
									'text' => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\n---\nUse block supports to expose design tools.",
								],
							],
						],
					]
				),
			],
		];

		$result = AISearchClient::validate_configuration();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_ai_search_missing_current_sources', $result->get_error_code() );
		$this->assertSame( [ 'developer-docs' ], $result->get_error_data()['sourceTypes'] ?? null );
	}

	public function test_validate_configuration_requires_current_release_cycle_freshness(): void {
		WordPressTestState::$remote_post_responses = [
			$this->trusted_docs_response(
				[
					$this->trusted_docs_chunk(
						'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
						'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/',
						'Use block supports to expose design tools.',
						'2026-05-08T14:00:00Z'
					),
				]
			),
			$this->trusted_docs_response(
				[
					$this->trusted_docs_chunk(
						'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
						'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/',
						'Use block supports to expose design tools.',
						'2026-05-08T14:00:00Z'
					),
					$this->trusted_docs_chunk(
						'ai-search/wp-dev-docs/make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/136f765ace420bbea73a493de6698debade0c95a80f90ce5fc7dc1dbe2ebd6ff/part-0001.md',
						'https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/',
						'WordPress 7.0 provides client-side abilities.',
						'2026-03-16T22:13:36Z',
						'2026-03-15T22:35:08Z'
					),
				]
			),
		];

		$result = AISearchClient::validate_configuration();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_ai_search_missing_current_sources', $result->get_error_code() );
		$this->assertSame( 'missing-current-release-cycle', $result->get_error_data()['coverage']['status'] ?? null );
	}

	public function test_source_coverage_probe_caches_current_summary_for_recommendations(): void {
		WordPressTestState::$remote_post_response = $this->trusted_docs_response(
			[
				$this->trusted_docs_chunk(
					'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
					'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/',
					'Use block supports to expose design tools.',
					'2026-05-08T14:00:00Z'
				),
				$this->trusted_docs_chunk(
					'ai-search/wp-dev-docs/make.wordpress.org/core/2026/05/08/current-editor-guidance/136f765ace420bbea73a493de6698debade0c95a80f90ce5fc7dc1dbe2ebd6ff/part-0001.md',
					'https://make.wordpress.org/core/2026/05/08/current-editor-guidance/',
					'Current editor guidance.',
					'2026-05-08T14:00:00Z',
					'2026-05-08T13:00:00Z'
				),
			]
		);

		$coverage = AISearchClient::get_current_source_coverage( true );

		$this->assertSame( 'current', $coverage['status'] );
		$this->assertTrue( $coverage['hasCurrentReleaseCycle'] );
		$this->assertSame( 1, count( WordPressTestState::$remote_post_calls ) );

		$cached = AISearchClient::get_current_source_coverage( false );

		$this->assertSame( 'current', $cached['status'] );
		$this->assertSame( 1, count( WordPressTestState::$remote_post_calls ) );
	}

	public function test_source_coverage_probe_uses_full_ttl_for_current_results(): void {
		WordPressTestState::$remote_post_response = $this->trusted_docs_response(
			[
				$this->trusted_docs_chunk(
					'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
					'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/',
					'Use block supports to expose design tools.',
					'2026-05-08T14:00:00Z'
				),
				$this->trusted_docs_chunk(
					'ai-search/wp-dev-docs/make.wordpress.org/core/2026/05/08/current-editor-guidance/part-0001.md',
					'https://make.wordpress.org/core/2026/05/08/current-editor-guidance/',
					'Current editor guidance.',
					'2026-05-08T14:00:00Z',
					'2026-05-08T13:00:00Z'
				),
			]
		);

		$coverage = AISearchClient::get_current_source_coverage( true );

		$this->assertSame( 'current', $coverage['status'] );
		$this->assertSame( 21600, WordPressTestState::$transient_expirations['flavor_agent_docs_source_coverage_v2'] ?? null );
	}

	public function test_source_coverage_probe_uses_short_ttl_for_missing_release_cycle_results(): void {
		WordPressTestState::$remote_post_response = $this->trusted_docs_response(
			[
				$this->trusted_docs_chunk(
					'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
					'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/',
					'Use block supports to expose design tools.',
					'2026-05-08T14:00:00Z'
				),
			]
		);

		$coverage = AISearchClient::get_current_source_coverage( true );

		$this->assertSame( 'missing-current-release-cycle', $coverage['status'] );
		$this->assertSame( 900, WordPressTestState::$transient_expirations['flavor_agent_docs_source_coverage_v2'] ?? null );
	}

	public function test_source_coverage_probe_uses_error_ttl_for_unavailable_results(): void {
		WordPressTestState::$remote_post_response = new \WP_Error( 'http_request_failed', 'Timeout.' );

		$coverage = AISearchClient::get_current_source_coverage( true );

		$this->assertSame( 'unavailable', $coverage['status'] );
		$this->assertSame( 300, WordPressTestState::$transient_expirations['flavor_agent_docs_source_coverage_v2'] ?? null );
	}

	public function test_validate_configuration_uses_short_ttl_for_missing_release_cycle_results(): void {
		WordPressTestState::$remote_post_responses = [
			$this->trusted_docs_response(
				[
					$this->trusted_docs_chunk(
						'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
						'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/',
						'Use block supports to expose design tools.',
						'2026-05-08T14:00:00Z'
					),
				]
			),
			$this->trusted_docs_response(
				[
					$this->trusted_docs_chunk(
						'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
						'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/',
						'Use block supports to expose design tools.',
						'2026-05-08T14:00:00Z'
					),
				]
			),
		];

		$result = AISearchClient::validate_configuration();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_ai_search_missing_current_sources', $result->get_error_code() );
		$this->assertSame( 900, WordPressTestState::$transient_expirations['flavor_agent_docs_source_coverage_v2'] ?? null );
	}

	public function test_cache_fallbacks_can_run_without_runtime_side_effects(): void {
		AISearchClient::maybe_search_with_cache_fallbacks(
			'current block editor guidance',
			'core/paragraph',
			[ 'surface' => 'block' ],
			null,
			false,
			false
		);

		$this->assertArrayNotHasKey( AISearchClient::CONTEXT_WARM_CRON_HOOK, WordPressTestState::$scheduled_events );

		$runtime_state = AISearchClient::get_runtime_state();

		$this->assertSame( '', $runtime_state['lastServedAt'] );
		$this->assertSame( '', $runtime_state['lastFallbackType'] );
	}

	public function test_validate_configuration_rejects_probe_results_without_trusted_source_keys_or_urls(): void {
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
									'key'      => 'internal/developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
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
			'Cloudflare AI Search validation could not confirm trusted developer.wordpress.org content from the built-in public Developer Docs endpoint.',
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

	public function test_search_uses_public_search_endpoint_by_default(): void {
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

	public function test_cache_keys_ignore_removed_legacy_developer_docs_credentials(): void {
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

		$this->assertSame( $query_key_a, $this->build_cache_key( 'navigation footer guidance', 4 ) );
		$this->assertSame( $family_key_a, $this->build_family_cache_key( $family_context, 4 ) );
		$this->assertSame( $entity_key_a, $this->build_entity_cache_key( 'core/navigation' ) );
	}

	public function test_cache_namespace_changes_with_docs_grounding_schema_version(): void {
		$reflection = new \ReflectionClass( AISearchClient::class );
		$cache_key  = $this->build_cache_key( 'block editor guidance', 4 );

		$this->assertSame( 3, $reflection->getConstant( 'CACHE_SCHEMA_VERSION' ) );
		$this->assertMatchesRegularExpression( '/^flavor_agent_ai_search_[a-f0-9]{32}$/', $cache_key );
	}

	public function test_source_coverage_cache_key_ignores_stale_schema_summary(): void {
		WordPressTestState::$transients['flavor_agent_docs_source_coverage'] = [
			'status'                 => 'current',
			'hasDeveloperDocs'       => true,
			'hasCurrentReleaseCycle' => true,
			'sourceTypes'            => [ 'developer-docs', 'make-core' ],
			'freshness'              => [ 'current' ],
			'checkedAt'              => '2026-05-11 00:00:00',
			'errorCode'              => '',
			'errorMessage'           => '',
		];

		$coverage = AISearchClient::get_current_source_coverage( false );

		$this->assertSame( 'unknown', $coverage['status'] );
		$this->assertSame( 'coverage_not_checked', $coverage['errorCode'] );

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

		$coverage = AISearchClient::get_current_source_coverage( false );

		$this->assertSame( 'current', $coverage['status'] );
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

	public function test_maybe_search_with_cache_fallbacks_can_skip_foreground_warm_and_queue_async_warm(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$family_context   = [
			'surface'   => 'template-part',
			'entityKey' => 'core/template-part',
			'area'      => 'header',
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
			4,
			false
		);

		$this->assertSame( $generic_guidance, $result );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
		$this->assertArrayHasKey( AISearchClient::CONTEXT_WARM_CRON_HOOK, WordPressTestState::$scheduled_events );

		$queue = WordPressTestState::$options['flavor_agent_docs_warm_queue'] ?? [];

		$this->assertCount( 1, $queue );
		$this->assertSame( 'missing query cache', array_values( $queue )[0]['query'] ?? '' );
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
								'text'  => "---\nsource_url: \"https://developer.wordpress.org/themes/templates/template-parts/\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\ncontent_hash: fresh-template-part\n---\nHeader template parts should stay focused and reusable.",
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

	public function test_maybe_search_with_cache_fallbacks_uses_full_foreground_timeout_when_current_coverage_is_required(): void {
		\add_filter( 'flavor_agent_docs_grounding_require_current_coverage', '__return_true' );

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
								'id'    => 'fresh-fail-closed-chunk',
								'score' => 0.93,
								'item'  => [
									'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
									'metadata' => [],
								],
								'text'  => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\ncontent_hash: fresh-fail-closed\n---\nUse block supports to expose design tools.",
							],
						],
					],
				]
			),
		];
		$family_context                           = [
			'surface'   => 'block',
			'entityKey' => 'core/paragraph',
		];

		$result = AISearchClient::maybe_search_with_cache_fallbacks(
			'paragraph block design guidance',
			'core/paragraph',
			$family_context,
			4
		);

		$this->assertSame( 'fresh-fail-closed-chunk', $result[0]['id'] ?? null );
		$this->assertSame( 20, WordPressTestState::$last_remote_post['args']['timeout'] );
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

	public function test_live_search_failure_uses_recent_last_known_current_guidance_as_degraded_grace(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'    => 'current-docs',
								'score' => 0.91,
								'item'  => [
									'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
									'metadata' => [],
								],
								'text'  => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\nretrieved_at: \"2026-05-08T14:00:00Z\"\ncontent_hash: current-docs\n---\nUse block supports to expose design tools.",
							],
						],
					],
				]
			),
		];

		$primed = AISearchClient::search( 'block supports guidance' );

		$this->assertIsArray( $primed );

		WordPressTestState::$remote_post_response = new \WP_Error(
			'http_request_failed',
			'Cloudflare timed out.'
		);

		$result = AISearchClient::search( 'block supports guidance after failure' );

		$this->assertIsArray( $result );
		$this->assertSame( 'current-docs', $result['guidance'][0]['id'] ?? null );

		$docs_grounding = DocsGuidanceResult::from_guidance( $result['guidance'], 'direct', 'live' );

		$this->assertSame( 'degraded', $docs_grounding['status'] );

		$runtime_state = AISearchClient::get_runtime_state();

		$this->assertSame( 'Cloudflare timed out.', $runtime_state['lastErrorMessage'] );
		$this->assertSame( 'grace', $runtime_state['lastServedMode'] );
		$this->assertSame( 'last-known-current', $runtime_state['lastFallbackType'] );
	}

	public function test_live_search_failure_does_not_use_expired_last_known_current_guidance(): void {
		WordPressTestState::$options['flavor_agent_docs_runtime_state'] = [
			'lastKnownCurrentAt'       => gmdate( 'Y-m-d H:i:s', time() - 21601 ),
			'lastKnownCurrentGuidance' => [
				[
					'id'          => 'expired-docs',
					'title'       => '',
					'sourceKey'   => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
					'sourceType'  => 'developer-docs',
					'url'         => 'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/',
					'excerpt'     => 'Expired guidance.',
					'score'       => 0.91,
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'publishedAt' => '',
					'contentHash' => 'expired-docs',
					'freshness'   => 'current',
				],
			],
		];
		WordPressTestState::$remote_post_response                       = new \WP_Error(
			'http_request_failed',
			'Cloudflare timed out.'
		);

		$result = AISearchClient::search( 'block supports guidance after failure' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	public function test_live_search_failure_without_last_known_current_guidance_fails(): void {
		WordPressTestState::$remote_post_response = new \WP_Error(
			'http_request_failed',
			'Cloudflare timed out.'
		);

		$result = AISearchClient::search( 'block supports guidance after failure' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	public function test_process_context_warm_queue_seeds_exact_family_and_entity_caches(): void {
		WordPressTestState::$options               = [
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
				'https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/search',
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
				'https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/search',
				WordPressTestState::$last_remote_post['url']
			);
	}

	public function test_empty_live_search_persists_docs_grounding_activity_diagnostic(): void {
		ActivityRepository::install();
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [],
					],
				]
			),
		];

		$result = AISearchClient::search( 'current block editor guidance' );

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['guidance'] );

		$entries = ActivityRepository::query(
			[
				'surface' => 'docs_grounding',
				'limit'   => 1,
			]
		);
		$entry   = $entries[0] ?? null;

		$this->assertIsArray( $entry );
		$this->assertSame( 'request_diagnostic', $entry['type'] ?? null );
		$this->assertSame( 'docs_grounding', $entry['surface'] ?? null );
		$this->assertSame( 'failed', $entry['executionResult'] ?? null );
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

	private function trusted_docs_response( array $chunks ): array {
		return [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => $chunks,
					],
				]
			),
		];
	}

	private function trusted_docs_chunk(
		string $source_key,
		string $url,
		string $excerpt,
		string $retrieved_at,
		string $published_at = ''
	): array {
		$frontmatter = "---\nsource_url: \"{$url}\"\nretrieved_at: \"{$retrieved_at}\"\ncontent_hash: docs-coverage-test";

		if ( '' !== $published_at ) {
			$frontmatter .= "\npublished_at: \"{$published_at}\"";
		}

		return [
			'id'   => md5( $source_key . $url ),
			'item' => [
				'key'      => $source_key,
				'metadata' => [],
			],
			'text' => $frontmatter . "\n---\n{$excerpt}",
		];
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
