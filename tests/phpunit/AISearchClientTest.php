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

	public function test_search_requires_configuration(): void {
		$result = AISearchClient::search( 'template parts' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_cloudflare_ai_search_credentials', $result->get_error_code() );
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

	private function build_cache_key( string $query, int $max_results ): string {
		$method = new \ReflectionMethod( AISearchClient::class, 'build_cache_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $query, $max_results );

		$this->assertIsString( $result );

		return $result;
	}
}
