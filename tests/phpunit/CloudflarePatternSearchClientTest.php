<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Cloudflare\PatternSearchClient;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class CloudflarePatternSearchClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_configuration_rejects_missing_required_values(): void {
		$this->assertFalse( PatternSearchClient::is_configured() );
		$this->assertFalse( PatternSearchClient::is_configured( 'account-123', 'patterns', 'pattern-index', '' ) );

		$result = PatternSearchClient::validate_configuration( 'account-123', '', 'pattern-index', 'token-xyz' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_cloudflare_pattern_ai_search_credentials', $result->get_error_code() );
		$this->assertStringContainsString( 'namespace', $result->get_error_message() );
	}

	public function test_validate_configuration_probes_namespaced_search_url(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [],
					],
				]
			),
		];

		$result = PatternSearchClient::validate_configuration(
			'account-123',
			'patterns',
			'pattern-index',
			'token-xyz'
		);

		$this->assertTrue( $result );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/namespaces/patterns/instances/pattern-index/search',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertSame(
			'Bearer token-xyz',
			WordPressTestState::$last_remote_post['args']['headers']['Authorization'] ?? null
		);

		$request_body = json_decode(
			WordPressTestState::$last_remote_post['args']['body'],
			true
		);

		$this->assertSame(
			[
				'$in' => [ '__flavor_agent_validation_probe__' ],
			],
			$request_body['ai_search_options']['retrieval']['filters']['pattern_name'] ?? null
		);
	}

	public function test_search_sends_retrieval_filters_and_normalizes_visible_candidates(): void {
		$this->seed_options();

		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'    => 'chunk-1',
								'score' => 0.87,
								'text'  => 'Hero pattern copy.',
								'item'  => [
									'key'      => 'theme-hero.md',
									'metadata' => [
										'pattern_name'   => 'theme/hero',
										'candidate_type' => 'pattern',
										'source'         => 'theme',
										'synced_id'      => 'theme-hero',
										'public_safe'    => true,
										'extra'          => 'ignored',
									],
								],
							],
							[
								'id'    => 'chunk-2',
								'score' => 0.66,
								'text'  => 'Invisible pattern copy.',
								'item'  => [
									'key'      => 'hidden.md',
									'metadata' => [
										'pattern_name'   => 'theme/hidden',
										'candidate_type' => 'pattern',
										'source'         => 'theme',
										'synced_id'      => 'theme-hidden',
										'public_safe'    => true,
									],
								],
							],
						],
					],
				]
			),
		];

		$result = PatternSearchClient::search_patterns(
			'Build a hero section',
			[ 'theme/hero', 'theme/footer' ],
			7
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame(
			[
				'name'     => 'theme/hero',
				'score'    => 0.87,
				'text'     => 'Hero pattern copy.',
				'metadata' => [
					'pattern_name'   => 'theme/hero',
					'candidate_type' => 'pattern',
					'source'         => 'theme',
					'synced_id'      => 'theme-hero',
					'public_safe'    => true,
				],
				'source'   => 'theme',
			],
			$result[0]
		);
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/namespaces/patterns/instances/pattern-index/search',
			WordPressTestState::$last_remote_post['url']
		);

		$request_body = json_decode(
			WordPressTestState::$last_remote_post['args']['body'],
			true
		);

		$this->assertSame( 'user', $request_body['messages'][0]['role'] ?? null );
		$this->assertSame( 'Build a hero section', $request_body['messages'][0]['content'] ?? null );
		$this->assertFalse( $request_body['ai_search_options']['query_rewrite']['enabled'] ?? true );
		$this->assertSame( 'hybrid', $request_body['ai_search_options']['retrieval']['retrieval_type'] ?? null );
		$this->assertSame( 'rrf', $request_body['ai_search_options']['retrieval']['fusion_method'] ?? null );
		$this->assertSame( 7, $request_body['ai_search_options']['retrieval']['max_num_results'] ?? null );
		$this->assertSame( 0.2, $request_body['ai_search_options']['retrieval']['match_threshold'] ?? null );
		$this->assertSame( 0, $request_body['ai_search_options']['retrieval']['context_expansion'] ?? null );
		$this->assertTrue( $request_body['ai_search_options']['retrieval']['return_on_failure'] ?? false );
		$this->assertSame(
			[
				'$in' => [ 'theme/hero', 'theme/footer' ],
			],
			$request_body['ai_search_options']['retrieval']['filters']['pattern_name'] ?? null
		);
	}

	public function test_upload_pattern_sends_multipart_file_metadata_and_wait_flag(): void {
		$this->seed_options();

		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ 'success' => true ] ),
		];

		$result = PatternSearchClient::upload_pattern(
			[
				'name'           => 'theme/hero',
				'title'          => 'Hero <strong>Pattern</strong>',
				'description'    => 'Lead section <script>alert("x")</script> for landing pages.',
				'categories'     => [ 'featured', ' landing ' ],
				'blockTypes'     => [ 'core/group', 'core/buttons' ],
				'templateTypes'  => [ 'front-page', 'home' ],
				'inferredTraits' => [ 'wide layout', 'CTA' ],
				'content'        => '<!-- wp:group --><div onclick="bad()">Hero CTA</div><!-- /wp:group --><script>bad()</script>',
				'source'         => 'theme',
				'public_safe'    => true,
			],
			'theme-hero',
			true
		);

		$this->assertTrue( $result );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/namespaces/patterns/instances/pattern-index/items',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertSame( 'POST', WordPressTestState::$last_remote_post['args']['method'] ?? null );
		$this->assertStringStartsWith(
			'multipart/form-data; boundary=',
			WordPressTestState::$last_remote_post['args']['headers']['Content-Type'] ?? ''
		);

		$body = WordPressTestState::$last_remote_post['args']['body'];

		$this->assertStringContainsString( 'name="file"; filename="theme-hero.md"', $body );
		$this->assertStringContainsString( '# Hero Pattern', $body );
		$this->assertStringContainsString( 'Categories: featured, landing', $body );
		$this->assertStringContainsString( 'Block types: core/group, core/buttons', $body );
		$this->assertStringContainsString( 'Template types: front-page, home', $body );
		$this->assertStringContainsString( 'Inferred traits: wide layout, CTA', $body );
		$this->assertStringContainsString( '<!-- wp:group -->', $body );
		$this->assertStringNotContainsString( '<script>', $body );
		$this->assertStringNotContainsString( 'onclick=', $body );
		$this->assertStringContainsString( 'name="metadata"', $body );
		$this->assertStringContainsString( '"pattern_name":"theme/hero"', $body );
		$this->assertStringContainsString( '"candidate_type":"pattern"', $body );
		$this->assertStringContainsString( '"source":"theme"', $body );
		$this->assertStringContainsString( '"synced_id":"theme-hero"', $body );
		$this->assertStringContainsString( '"public_safe":true', $body );
		$this->assertStringNotContainsString( '"categories"', $body );
		$this->assertStringContainsString( "name=\"wait_for_completion\"\r\n\r\ntrue", $body );
	}

	public function test_repeated_uploads_use_the_same_item_id_without_client_side_duplicates(): void {
		$this->seed_options();

		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'success' => true ] ),
			],
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'success' => true ] ),
			],
		];

		$pattern = [
			'name'        => 'theme/hero',
			'title'       => 'Hero',
			'description' => 'Lead section.',
			'content'     => '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->',
		];

		$this->assertTrue( PatternSearchClient::upload_pattern( $pattern, 'stable-pattern-id', true ) );
		$this->assertTrue( PatternSearchClient::upload_pattern( $pattern, 'stable-pattern-id', true ) );

		$this->assertCount( 2, WordPressTestState::$remote_post_calls );

		foreach ( WordPressTestState::$remote_post_calls as $call ) {
			$body = (string) ( $call['args']['body'] ?? '' );

			$this->assertSame(
				'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/namespaces/patterns/instances/pattern-index/items',
				$call['url'] ?? null
			);
			$this->assertStringContainsString( 'filename="stable-pattern-id.md"', $body );
			$this->assertStringContainsString( '"synced_id":"stable-pattern-id"', $body );
			$this->assertStringContainsString( "name=\"wait_for_completion\"\r\n\r\ntrue", $body );
		}
	}

	public function test_list_pattern_item_ids_pages_through_instance_items(): void {
		$this->seed_options();

		WordPressTestState::$remote_get_responses = [
			$this->item_list_response(
				[
					[ 'id' => 'theme-hero' ],
					[ 'id' => 'theme-footer' ],
				],
				2,
				1,
				3,
				2
			),
			$this->item_list_response(
				[
					[ 'id' => 'theme-pricing' ],
				],
				1,
				2,
				3,
				2
			),
		];

		$result = PatternSearchClient::list_pattern_item_ids();

		$this->assertSame( [ 'theme-hero', 'theme-footer', 'theme-pricing' ], $result );
		$this->assertStringContainsString( 'page=1', WordPressTestState::$remote_get_calls[0]['url'] ?? '' );
		$this->assertStringContainsString( 'page=2', WordPressTestState::$remote_get_calls[1]['url'] ?? '' );
		$this->assertStringContainsString( 'per_page=100', WordPressTestState::$remote_get_calls[0]['url'] ?? '' );
		$this->assertStringContainsString( 'source=builtin', WordPressTestState::$remote_get_calls[0]['url'] ?? '' );
	}

	public function test_delete_pattern_calls_item_endpoint_and_treats_404_as_success(): void {
		$this->seed_options();

		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 404 ],
			'body'     => wp_json_encode(
				[
					'errors' => [
						[ 'message' => 'item not found' ],
					],
				]
			),
		];

		$result = PatternSearchClient::delete_pattern( 'theme-hero' );

		$this->assertTrue( $result );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/namespaces/patterns/instances/pattern-index/items/theme-hero',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertSame( 'DELETE', WordPressTestState::$last_remote_post['args']['method'] ?? null );
	}

	public function test_validate_configuration_reports_filterable_metadata_schema_error(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 400 ],
			'body'     => wp_json_encode(
				[
					'errors' => [
						[ 'message' => 'Invalid filter: unknown custom metadata field pattern_name.' ],
					],
				]
			),
		];

		$result = PatternSearchClient::validate_configuration(
			'account-123',
			'patterns',
			'pattern-index',
			'token-xyz'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_pattern_ai_search_schema_error', $result->get_error_code() );
		$this->assertStringContainsString( 'Cloudflare AI Search dashboard', $result->get_error_message() );
		$this->assertStringContainsString( 'pattern_name', $result->get_error_message() );
		$this->assertStringContainsString( 'candidate_type', $result->get_error_message() );
		$this->assertStringContainsString( 'source', $result->get_error_message() );
		$this->assertStringContainsString( 'synced_id', $result->get_error_message() );
		$this->assertStringContainsString( 'public_safe', $result->get_error_message() );
	}

	private function seed_options(): void {
		WordPressTestState::$options = [
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_ACCOUNT_ID  => 'account-123',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE   => 'patterns',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => 'pattern-index',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN   => 'token-xyz',
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	private function item_list_response( array $items, int $count, int $page, int $total_count, int $per_page ): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'result'      => $items,
					'result_info' => [
						'count'       => $count,
						'page'        => $page,
						'total_count' => $total_count,
						'per_page'    => $per_page,
					],
				]
			),
		];
	}
}
