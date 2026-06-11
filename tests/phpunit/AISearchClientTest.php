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
				'https://ba566764-a507-4cd0-8cc8-cffbbde72ac3.search.ai.cloudflare.com/search',
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
			'https://ba566764-a507-4cd0-8cc8-cffbbde72ac3.search.ai.cloudflare.com/search',
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
			'https://ba566764-a507-4cd0-8cc8-cffbbde72ac3.search.ai.cloudflare.com/search',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertArrayNotHasKey(
			'Authorization',
			WordPressTestState::$last_remote_post['args']['headers']
		);
		$this->assertSame(
			'ba566764-a507-4cd0-8cc8-cffbbde72ac3',
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
								'key'       => 'ai-search/ba566764-a507-4cd0-8cc8-cffbbde72ac3/developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/27cdbd65c37e71c679c075cbf9b1443c019c399384140cae7323fcfa3ae26769/part-0001.md',
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
				'https://ba566764-a507-4cd0-8cc8-cffbbde72ac3.search.ai.cloudflare.com/search',
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

	public function test_search_accepts_bounded_managed_source_key_when_path_exceeds_filename_limit(): void {
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
					'search_query' => 'private apis',
					'chunks'       => [
						[
							'id'    => 'chunk-1',
							'score' => 0.9,
							'type'  => 'text',
							'item'  => [
								// Cloudflare caps item filenames at 128 bytes, so deep docs URLs
								// ship a bounded slug + short hash that cannot reconstruct the path.
								'key'       => 'ai-search/wp-dev/developer.wordpress.org/block-editor-reference-guides-packages-packages-private-a/8d704f871324191e/part-0001.md',
								'timestamp' => 1775925540000,
							],
							'text'  => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/packages/packages-private-apis/\"\n---\nPrivate APIs let packages share unstable code.",
						],
					],
				]
			),
		];

		$result = AISearchClient::search( 'private apis' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['guidance'] );
		$this->assertSame(
			'https://developer.wordpress.org/block-editor/reference-guides/packages/packages-private-apis/',
			$result['guidance'][0]['url']
		);
		$this->assertSame(
			'ai-search/wp-dev/developer.wordpress.org/block-editor-reference-guides-packages-packages-private-a/8d704f871324191e/part-0001.md',
			$result['guidance'][0]['sourceKey']
		);
		$this->assertStringContainsString( 'Private APIs', $result['guidance'][0]['excerpt'] );
	}

	public function test_search_rejects_non_managed_source_key_even_with_trusted_metadata_url(): void {
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
					'search_query' => 'private apis',
					'chunks'       => [
						[
							'id'    => 'chunk-1',
							'score' => 0.9,
							'type'  => 'text',
							'item'  => [
								// Forged: trusted-looking metadata URL but a key outside the managed namespace.
								'key'       => 'internal/developer.wordpress.org/block-editor/reference-guides/packages/packages-private-apis/part-0001.md',
								'timestamp' => 1775925540000,
							],
							'text'  => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/packages/packages-private-apis/\"\n---\nForged chunk body.",
						],
					],
				]
			),
		];

		$result = AISearchClient::search( 'private apis' );

		$this->assertIsArray( $result );
		$this->assertCount( 0, $result['guidance'] );
	}

	public function test_search_rejects_bounded_source_key_with_forged_instance_segment(): void {
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
					'search_query' => 'private apis',
					'chunks'       => [
						[
							'id'    => 'chunk-1',
							'score' => 0.9,
							'type'  => 'text',
							'item'  => [
								// Forged instance segment: the host segment matches the trusted
								// metadata URL, but "attacker" is not a managed docs instance.
								'key'       => 'ai-search/attacker/developer.wordpress.org/block-editor-reference-guides-packages-packages-private-a/8d704f871324191e/part-0001.md',
								'timestamp' => 1775925540000,
							],
							'text'  => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/packages/packages-private-apis/\"\n---\nForged chunk body.",
						],
					],
				]
			),
		];

		$result = AISearchClient::search( 'private apis' );

		$this->assertIsArray( $result );
		$this->assertCount( 0, $result['guidance'] );
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

	public function test_validate_configuration_uses_single_probe_search(): void {
		$retrieved_at = gmdate( 'Y-m-d\TH:i:s\Z', time() - 86400 );

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
								'text' => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\nretrieved_at: \"{$retrieved_at}\"\n---\nUse block supports to expose design tools.",
							],
						],
					],
				]
			),
		];

		$result = AISearchClient::validate_configuration();

		$this->assertSame(
			[
				'id'      => 'ba566764-a507-4cd0-8cc8-cffbbde72ac3',
				'source'  => 'public',
				'enabled' => true,
				'paused'  => false,
			],
			$result
		);
		$this->assertSame(
			'https://ba566764-a507-4cd0-8cc8-cffbbde72ac3.search.ai.cloudflare.com/search',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );

		$request_body = json_decode(
			WordPressTestState::$remote_post_calls[0]['args']['body'],
			true
		);

		$this->assertSame( 'block editor', $request_body['messages'][0]['content'] );
		$this->assertSame( 3, $request_body['ai_search_options']['retrieval']['max_num_results'] );
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
			'https://ba566764-a507-4cd0-8cc8-cffbbde72ac3.search.ai.cloudflare.com/search',
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

	public function test_maybe_search_best_effort_returns_chunks_on_success(): void {
		WordPressTestState::$transients           = [];
		WordPressTestState::$remote_post_response = $this->trusted_docs_response(
			[
				$this->trusted_docs_chunk(
					'developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph',
					'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph/',
					'Paragraph typography stays inside supported editor controls.',
					'2026-05-08T14:00:00Z'
				),
				$this->trusted_docs_chunk(
					'developer.wordpress.org/block-editor/reference-guides/core-blocks/heading',
					'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/heading',
					'Heading rhythm guidance for editorial layouts.',
					'2026-05-08T14:00:00Z'
				),
			]
		);

		$guidance = AISearchClient::maybe_search_best_effort( 'block editor typography' );

		$this->assertCount( 2, $guidance );
		$this->assertSame( 'ok', AISearchClient::get_runtime_state()['status'] );
		$this->assertSame( 2, AISearchClient::get_runtime_state()['lastResultCount'] );
	}

	public function test_maybe_search_best_effort_returns_empty_and_marks_unreachable_on_transport_error(): void {
		WordPressTestState::$transients           = [];
		WordPressTestState::$remote_post_response = new \WP_Error( 'http_request_failed', 'down' );

		$guidance = AISearchClient::maybe_search_best_effort( 'block editor typography' );

		$this->assertSame( [], $guidance );
		$this->assertSame( 'unreachable', AISearchClient::get_runtime_state()['status'] );
	}

	public function test_cache_keys_ignore_removed_legacy_developer_docs_credentials(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$query_key_a = $this->build_cache_key( 'navigation footer guidance', 4 );

		WordPressTestState::$options['flavor_agent_cloudflare_ai_search_api_token'] = 'rotated-token';

		$this->assertSame( $query_key_a, $this->build_cache_key( 'navigation footer guidance', 4 ) );

		WordPressTestState::$options['flavor_agent_cloudflare_ai_search_instance_id'] = 'wp-dev-docs-preview';

		$this->assertSame( $query_key_a, $this->build_cache_key( 'navigation footer guidance', 4 ) );
	}

	public function test_cache_namespace_changes_with_docs_grounding_schema_version(): void {
		$reflection = new \ReflectionClass( AISearchClient::class );
		$cache_key  = $this->build_cache_key( 'block editor guidance', 4 );

		$this->assertSame( 3, $reflection->getConstant( 'CACHE_SCHEMA_VERSION' ) );
		$this->assertMatchesRegularExpression( '/^flavor_agent_ai_search_[a-f0-9]{32}$/', $cache_key );
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
				'https://ba566764-a507-4cd0-8cc8-cffbbde72ac3.search.ai.cloudflare.com/search',
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

	public function test_normalize_public_search_url_rewrites_mcp_endpoints_to_search(): void {
		$method = new \ReflectionMethod( AISearchClient::class, 'normalize_public_search_url' );
		$method->setAccessible( true );

		$result = $method->invoke(
			null,
			'https://ba566764-a507-4cd0-8cc8-cffbbde72ac3.search.ai.cloudflare.com/mcp'
		);

		$this->assertSame(
			'https://ba566764-a507-4cd0-8cc8-cffbbde72ac3.search.ai.cloudflare.com/search',
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
}
