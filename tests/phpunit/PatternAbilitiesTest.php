<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\PatternAbilities;
use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PatternAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_list_patterns_normalizes_object_input_and_applies_filters(): void {
		$this->register_pattern(
			'theme/hero',
			[
				'title'         => 'Hero',
				'categories'    => [ 'featured' ],
				'blockTypes'    => [ 'core/group' ],
				'templateTypes' => [ 'home' ],
				'content'       => '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/header-utility',
			[
				'title'         => 'Header Utility',
				'categories'    => [ 'marketing' ],
				'blockTypes'    => [ 'core/template-part/header' ],
				'templateTypes' => [ 'home' ],
				'content'       => '<!-- wp:paragraph --><p>Header Utility</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/footer-callout',
			[
				'title'         => 'Footer Callout',
				'categories'    => [ 'marketing' ],
				'blockTypes'    => [ 'core/template-part/footer' ],
				'templateTypes' => [ 'single' ],
				'content'       => '<!-- wp:paragraph --><p>Footer Callout</p><!-- /wp:paragraph -->',
			]
		);

		$result = PatternAbilities::list_patterns(
			(object) [
				'categories'    => [ 'marketing' ],
				'blockTypes'    => [ 'core/template-part/header' ],
				'templateTypes' => [ 'home' ],
			]
		);

		$this->assertSame( [ 'theme/header-utility' ], array_column( $result['patterns'], 'name' ) );
	}

	public function test_recommend_patterns_returns_missing_credentials_when_backends_are_not_configured(): void {
		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_credentials', $result->get_error_code() );
	}

	public function test_recommend_patterns_short_circuits_explicitly_empty_visible_patterns_before_backend_validation(): void {
		$result = PatternAbilities::recommend_patterns(
			[
				'postType'            => 'page',
				'visiblePatternNames' => [],
			]
		);

		$this->assertSame( [ 'recommendations' => [] ], $result );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
	}

	public function test_recommend_patterns_returns_index_warming_and_schedules_sync_for_uninitialized_index(): void {
		$this->configure_backends();
		WordPressTestState::$capabilities = [ 'manage_options' => true ];

		$this->save_index_state(
			[
				'status'         => 'uninitialized',
				'last_synced_at' => null,
			]
		);

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_warming', $result->get_error_code() );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_recommend_patterns_schedules_sync_for_uninitialized_index_for_non_admin_callers(): void {
		$this->configure_backends();

		$this->save_index_state(
			[
				'status'         => 'uninitialized',
				'last_synced_at' => null,
			]
		);

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_warming', $result->get_error_code() );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
	}

	public function test_recommend_patterns_returns_index_warming_while_indexing_without_usable_index(): void {
		$this->configure_backends();
		WordPressTestState::$capabilities = [ 'manage_options' => true ];

		$this->save_index_state(
			[
				'status'         => 'indexing',
				'last_synced_at' => null,
			]
		);

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_warming', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$scheduled_events );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_recommend_patterns_returns_index_unavailable_for_error_state_without_usable_index(): void {
		$this->configure_backends();

		$this->save_index_state(
			[
				'status'         => 'error',
				'last_synced_at' => null,
				'last_error'     => 'Sync failed.',
			]
		);

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_unavailable', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_recommend_patterns_returns_index_warming_for_retryable_error_state_without_usable_index(): void {
		$this->configure_backends();

		$this->save_index_state(
			[
				'status'               => 'error',
				'last_synced_at'       => null,
				'last_error'           => 'Rate limited.',
				'last_error_code'      => 'rate_limited',
				'last_error_status'    => 429,
				'last_error_retryable' => true,
				'last_error_retry_after' => 7,
			]
		);

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_warming', $result->get_error_code() );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
	}

	public function test_recommend_patterns_does_not_use_a_stale_index_when_collection_compatibility_changed(): void {
		$this->configure_backends();
		WordPressTestState::$capabilities = [ 'manage_options' => true ];

		$this->save_index_state(
			[
				'status'         => 'stale',
				'last_synced_at' => '2026-03-24T00:00:00+00:00',
				'stale_reason'   => 'collection_name_changed',
				'stale_reasons'  => [ 'collection_name_changed' ],
			]
		);

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_warming', $result->get_error_code() );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_recommend_patterns_schedules_sync_for_compatibility_drift_for_non_admin_callers(): void {
		$this->configure_backends();

		$this->save_index_state(
			[
				'status'         => 'stale',
				'last_synced_at' => '2026-03-24T00:00:00+00:00',
				'stale_reason'   => 'collection_name_changed',
				'stale_reasons'  => [ 'collection_name_changed' ],
			]
		);

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_warming', $result->get_error_code() );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_recommend_patterns_does_not_use_a_stale_index_when_only_the_embedding_endpoint_changed(): void {
		$this->configure_backends();
		WordPressTestState::$capabilities = [ 'manage_options' => true ];

		$this->save_index_state(
			[
				'status'         => 'stale',
				'last_synced_at' => '2026-03-24T00:00:00+00:00',
				'stale_reason'   => 'openai_endpoint_changed',
				'stale_reasons'  => [ 'openai_endpoint_changed' ],
			]
		);

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_warming', $result->get_error_code() );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_recommend_patterns_uses_stale_usable_index_builds_structural_query_and_filters_candidates(): void {
		$this->configure_backends();
		WordPressTestState::$capabilities = [ 'manage_options' => true ];

		$this->save_index_state(
			[
				'status'         => 'stale',
				'last_synced_at' => '2026-03-24T00:00:00+00:00',
			]
		);

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/hero', 0.41 ),
					$this->pattern_point( 'theme/header-utility', 0.83 ),
					$this->pattern_point( 'theme/invisible-pattern', 0.95 ),
				]
			),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/hero', 0.77 ),
					$this->pattern_point( 'theme/footer-callout', 0.65 ),
					$this->pattern_point( 'theme/invisible-pattern', 0.99 ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/footer-callout',
								'score'  => 0.92,
								'reason' => '<strong>Excellent fit</strong>',
							],
							[
								'name'   => 'theme/hero',
								'score'  => 0.61,
								'reason' => 'Matches the header context.',
							],
							[
								'name'   => 'theme/header-utility',
								'score'  => 0.29,
								'reason' => 'Too weak.',
							],
							[
								'name'   => 'theme/not-a-candidate',
								'score'  => 0.99,
								'reason' => 'Should be ignored.',
							],
						],
					]
				)
			),
		];

		$result = PatternAbilities::recommend_patterns(
			[
				'postType'            => 'page',
				'templateType'        => 'home',
				'blockContext'        => [
					'blockName' => 'core/template-part/header',
				],
				'prompt'              => 'Make the intro more editorial.',
				'visiblePatternNames' => [ 'theme/hero', 'theme/footer-callout' ],
			]
		);

		$this->assertSame(
			[ 'theme/footer-callout', 'theme/hero' ],
			array_column( $result['recommendations'], 'name' )
		);
		$this->assertSame(
			[ 'Excellent fit', 'Matches the header context.' ],
			array_column( $result['recommendations'], 'reason' )
		);
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );

		$embedding_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[0] );
		$this->assertStringContainsString(
			'Recommend patterns for a page post near a core/template-part/header block in a home template.',
			(string) ( $embedding_request['input'][0] ?? '' )
		);
		$this->assertStringContainsString(
			'Make the intro more editorial.',
			(string) ( $embedding_request['input'][0] ?? '' )
		);

		$semantic_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[1] );
		$this->assertSame( 24, $semantic_request['limit'] ?? null );
		$this->assertArrayNotHasKey( 'filter', $semantic_request );

		$structural_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[2] );
		$this->assertSame( 18, $structural_request['limit'] ?? null );
		$this->assertSame(
			[
				[
					'key'   => 'templateTypes',
					'match' => [ 'value' => 'home' ],
				],
				[
					'key'   => 'blockTypes',
					'match' => [ 'value' => 'core/template-part/header' ],
				],
			],
			$structural_request['filter']['should'] ?? null
		);

		$ranking_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[3] );
		$this->assertStringContainsString( 'theme/hero', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'theme/footer-callout', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'patternOverrides', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'hasOverrides', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringNotContainsString( 'theme/header-utility', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringNotContainsString( 'theme/invisible-pattern', (string) ( $ranking_request['input'] ?? '' ) );
	}

	public function test_recommend_patterns_rejects_live_search_when_query_vector_signature_changes(): void {
		$this->configure_backends();
		WordPressTestState::$capabilities = [ 'manage_options' => true ];
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34, 0.56 ] ),
		];

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_warming', $result->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );

		$state = PatternIndex::get_state();
		$this->assertSame( 'stale', $state['status'] );
		$this->assertContains( 'embedding_signature_changed', $state['stale_reasons'] );
	}

	public function test_recommend_patterns_rejects_live_search_when_query_vector_signature_changes_for_non_admins_and_schedules_sync(): void {
		$this->configure_backends();
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34, 0.56 ] ),
		];

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_warming', $result->get_error_code() );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );

		$state = PatternIndex::get_state();
		$this->assertSame( 'stale', $state['status'] );
		$this->assertContains( 'embedding_signature_changed', $state['stale_reasons'] );
	}

	public function test_recommend_patterns_marks_index_stale_and_rebuilds_when_collection_is_missing_before_search(): void {
		$this->configure_backends();
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
		];
		WordPressTestState::$remote_get_response = [
			'response' => [ 'code' => 404 ],
			'body'     => wp_json_encode(
				[
					'status' => [
						'error' => 'Collection not found',
					],
				]
			),
		];

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_warming', $result->get_error_code() );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
		$this->assertCount( 1, WordPressTestState::$remote_get_calls );

		$state = PatternIndex::get_state();
		$this->assertSame( 'stale', $state['status'] );
		$this->assertContains( 'collection_missing', $state['stale_reasons'] );
	}

	public function test_recommend_patterns_marks_index_stale_and_rebuilds_when_collection_size_mismatches_before_search(): void {
		$this->configure_backends();
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
		];
		WordPressTestState::$remote_get_response = $this->qdrant_collection_response( 3 );

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_warming', $result->get_error_code() );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
		$this->assertCount( 1, WordPressTestState::$remote_get_calls );

		$state = PatternIndex::get_state();
		$this->assertSame( 'stale', $state['status'] );
		$this->assertContains( 'collection_size_mismatch', $state['stale_reasons'] );
	}

	public function test_recommend_patterns_returns_parse_error_for_malformed_ranking_json(): void {
		$this->configure_backends();
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/hero', 0.71 ),
				]
			),
			$this->ranking_response( 'not-json' ),
		];

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'parse_error', $result->get_error_code() );
	}

	public function test_recommend_patterns_includes_cached_wordpress_docs_guidance_in_ranking_input(): void {
		$this->configure_backends();
		$this->configure_docs_grounding();
		$this->save_index_state();

		AISearchClient::cache_entity_guidance(
			'core/cover',
			[
				[
					'id'        => 'cover-doc',
					'title'     => 'Cover block reference',
					'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/cover',
					'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/cover/',
					'excerpt'   => 'Cover blocks support focal point, overlay styling, and inner content layout controls.',
					'score'     => 0.91,
				],
			]
		);

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/hero', 0.71 ),
				]
			),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/hero', 0.79 ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/hero',
								'score'  => 0.82,
								'reason' => 'Matches the cover hero context.',
							],
						],
					]
				)
			),
		];

		$result = PatternAbilities::recommend_patterns(
			[
				'postType'     => 'page',
				'templateType' => 'home',
				'blockContext' => [
					'blockName' => 'core/cover',
				],
				'prompt'       => 'Make the hero feel more editorial.',
			]
		);

		$this->assertSame( [ 'theme/hero' ], array_column( $result['recommendations'], 'name' ) );

		$ranking_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[3] );
		$this->assertStringContainsString( '## WordPress Developer Guidance', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'Cover block reference', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'overlay styling, and inner content layout controls', (string) ( $ranking_request['input'] ?? '' ) );
	}

	public function test_recommend_patterns_sharpens_custom_block_override_reasons_without_widening_scope(): void {
		$this->configure_backends();
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/custom-block-generic', 0.78, [
						'patternOverrides' => [
							'hasOverrides'          => true,
							'blockCount'            => 1,
							'blockNames'            => [ 'plugin/card' ],
							'bindableAttributes'    => [ 'plugin/card' => [ 'title' ] ],
							'overrideAttributes'    => [ 'plugin/card' => [ 'title' ] ],
							'usesDefaultBinding'    => false,
							'unsupportedAttributes' => [],
						],
					] ),
					$this->pattern_point( 'theme/plain-pattern', 0.79 ),
				]
			),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/custom-block-generic', 0.81, [
						'patternOverrides' => [
							'hasOverrides'          => true,
							'blockCount'            => 1,
							'blockNames'            => [ 'plugin/card' ],
							'bindableAttributes'    => [ 'plugin/card' => [ 'title' ] ],
							'overrideAttributes'    => [ 'plugin/card' => [ 'title' ] ],
							'usesDefaultBinding'    => true,
							'unsupportedAttributes' => [],
						],
					] ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/custom-block-generic',
								'score'  => 0.87,
								'reason' => 'Fits the surrounding card layout.',
							],
						],
					]
				)
			),
		];

		$result = PatternAbilities::recommend_patterns(
			[
				'postType'     => 'page',
				'blockContext' => [
					'blockName' => 'plugin/card',
				],
				'prompt'       => 'Keep it flexible for repeated card instances.',
			]
		);

		$this->assertSame( [ 'theme/custom-block-generic' ], array_column( $result['recommendations'], 'name' ) );
		$this->assertStringContainsString(
			'Supports Pattern Overrides for plugin/card.',
			(string) ( $result['recommendations'][0]['reason'] ?? '' )
		);
		$this->assertTrue( (bool) ( $result['recommendations'][0]['patternOverrides']['hasOverrides'] ?? false ) );

		$ranking_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[3] );
		$this->assertStringContainsString( 'Custom block context: plugin/card', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'Supports Pattern Overrides for plugin/card.', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'matchesNearbyCustomBlock', (string) ( $ranking_request['input'] ?? '' ) );
	}

	public function test_recommend_patterns_uses_connector_chat_with_fallback_direct_embeddings(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                        => 'anthropic',
			'flavor_agent_openai_native_api_key'         => 'native-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_qdrant_url'                    => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                    => 'qdrant-key',
		];

		WordPressTestState::$connectors = [
			'anthropic' => [
				'name'           => 'Anthropic',
				'description'    => 'Anthropic connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
			],
		];

		WordPressTestState::$ai_client_supported = true;

		WordPressTestState::$ai_client_provider_support = [
			'anthropic' => true,
		];

		WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
			[
				'recommendations' => [
					[
						'name'   => 'theme/hero',
						'score'  => 0.82,
						'reason' => 'Matches the current context.',
					],
				],
			]
		);

		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/hero', 0.71 ),
				]
			),
		];

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertSame( [ 'theme/hero' ], array_column( $result['recommendations'], 'name' ) );
		$this->assertSame( 'anthropic', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
		$this->assertCount( 2, WordPressTestState::$remote_post_calls );
	}

	public function test_recommend_patterns_caps_and_sorts_recommendations(): void {
		$this->configure_backends();
		$this->save_index_state();

		$points          = [];
		$ranked_patterns = [];

		for ( $i = 1; $i <= 9; $i++ ) {
			$name              = "theme/pattern-{$i}";
			$points[]          = $this->pattern_point( $name, 0.99 - ( $i * 0.01 ) );
			$ranked_patterns[] = [
				'name'   => $name,
				'score'  => 0.40 + ( $i * 0.05 ),
				'reason' => "Reason {$i}",
			];
		}

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response( $points ),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => $ranked_patterns,
					]
				)
			),
		];

		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertCount( 8, $result['recommendations'] );
		$this->assertSame(
			[
				'theme/pattern-8',
				'theme/pattern-7',
				'theme/pattern-6',
				'theme/pattern-5',
				'theme/pattern-4',
				'theme/pattern-3',
				'theme/pattern-2',
				'theme/pattern-1',
			],
			array_column( $result['recommendations'], 'name' )
		);
	}

	private function configure_backends(): void {
		WordPressTestState::$options = array_merge(
			WordPressTestState::$options,
			[
				Provider::OPTION_NAME                   => Provider::NATIVE,
				'flavor_agent_openai_native_api_key'    => 'native-key',
				'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
				'flavor_agent_openai_native_chat_model' => 'gpt-5.4',
				'flavor_agent_qdrant_url'               => 'https://example.cloud.qdrant.io:6333',
				'flavor_agent_qdrant_key'               => 'qdrant-key',
			]
		);
	}

	private function configure_docs_grounding(): void {
		WordPressTestState::$options = array_merge(
			WordPressTestState::$options,
			[
				'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
				'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
				'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
			]
		);
	}

	private function save_index_state( array $overrides = [] ): void {
		$embedding_config    = Provider::embedding_configuration();
		$embedding_signature = EmbeddingClient::build_signature_for_dimension( 2, $embedding_config );

		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'               => 'ready',
					'fingerprint'          => 'fingerprint-123',
					'qdrant_url'           => (string) get_option( 'flavor_agent_qdrant_url', '' ),
					'qdrant_collection'    => QdrantClient::get_collection_name( $embedding_signature ),
					'openai_provider'      => $embedding_config['provider'],
					'openai_endpoint'      => $embedding_config['endpoint'],
					'embedding_model'      => $embedding_config['model'],
					'embedding_dimension'  => 2,
					'embedding_signature'  => $embedding_signature['signature_hash'],
					'last_synced_at'       => '2026-03-24T00:00:00+00:00',
					'last_attempt_at'      => '2000-01-01T00:00:00+00:00',
					'indexed_count'        => 3,
					'last_error'           => null,
					'last_error_code'      => '',
					'last_error_status'    => 0,
					'last_error_retryable' => false,
					'last_error_retry_after' => null,
					'stale_reason'         => '',
					'stale_reasons'        => [],
					'pattern_fingerprints' => [],
				],
				$overrides
			)
		);

		if ( [] === WordPressTestState::$remote_get_responses ) {
			WordPressTestState::$remote_get_response = $this->qdrant_collection_response( 2 );
		}
	}

	private function register_pattern( string $name, array $properties ): void {
		\WP_Block_Patterns_Registry::get_instance()->register( $name, $properties );
	}

	/**
	 * @param float[] $vector
	 * @return array<string, mixed>
	 */
	private function embedding_response( array $vector ): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'data' => [
						[
							'embedding' => $vector,
						],
					],
				]
			),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $points
	 * @return array<string, mixed>
	 */
	private function qdrant_points_response( array $points ): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'status' => 'ok',
					'result' => [
						'points' => $points,
					],
				]
			),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function qdrant_collection_response( int $dimension ): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'status' => 'ok',
					'result' => [
						'config' => [
							'params' => [
								'vectors' => [
									'size'     => $dimension,
									'distance' => 'Cosine',
								],
							],
						],
					],
				]
			),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function ranking_response( string $output_text ): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => $output_text,
				]
			),
		];
	}

	/**
	 * @return array{score: float, payload: array<string, mixed>}
	 */
	private function pattern_point( string $name, float $score, array $overrides = [] ): array {
		$payload = [
			'name'          => $name,
			'title'         => ucwords( str_replace( [ 'theme/', '-' ], [ '', ' ' ], $name ) ),
			'description'   => "Description for {$name}",
			'categories'    => [ 'marketing' ],
			'blockTypes'    => [ 'core/template-part/header' ],
			'templateTypes' => [ 'home' ],
			'patternOverrides' => [
				'hasOverrides'          => $name === 'theme/footer-callout',
				'blockCount'            => $name === 'theme/footer-callout' ? 1 : 0,
				'blockNames'            => $name === 'theme/footer-callout' ? [ 'core/heading' ] : [],
				'bindableAttributes'    => $name === 'theme/footer-callout' ? [ 'core/heading' => [ 'content' ] ] : [],
				'overrideAttributes'    => $name === 'theme/footer-callout' ? [ 'core/heading' => [ 'content' ] ] : [],
				'usesDefaultBinding'    => false,
				'unsupportedAttributes' => [],
			],
			'content'       => "<!-- wp:paragraph --><p>{$name}</p><!-- /wp:paragraph -->",
		];

		return [
			'score'   => $score,
			'payload' => array_replace_recursive( $payload, $overrides ),
		];
	}

	/**
	 * @param array<string, mixed> $call
	 * @return array<string, mixed>
	 */
	private function decode_request_body( array $call ): array {
		$decoded = json_decode( (string) ( $call['args']['body'] ?? '' ), true );

		$this->assertIsArray( $decoded );

		return $decoded;
	}
}
