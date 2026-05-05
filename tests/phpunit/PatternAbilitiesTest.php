<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\PatternAbilities;
use FlavorAgent\Admin\Settings\Config;
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
		$this->disable_public_docs_grounding();
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

	public function test_get_pattern_returns_registered_pattern_by_name(): void {
		$this->register_pattern(
			'theme/hero',
			[
				'title'   => 'Hero',
				'content' => '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->',
			]
		);

		$result = PatternAbilities::get_pattern(
			[
				'patternId' => 'theme/hero',
			]
		);

		$this->assertSame( 'theme/hero', $result['id'] );
		$this->assertSame( 'Hero', $result['title'] );
	}

	public function test_list_patterns_supports_search_pagination_and_lightweight_payloads(): void {
		$this->register_pattern(
			'theme/marketing-alpha',
			[
				'title'   => 'Marketing Alpha',
				'content' => '<!-- wp:paragraph --><p>Alpha</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/marketing-beta',
			[
				'title'   => 'Marketing Beta',
				'content' => '<!-- wp:paragraph --><p>Beta</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/editorial-gamma',
			[
				'title'   => 'Editorial Gamma',
				'content' => '<!-- wp:paragraph --><p>Gamma</p><!-- /wp:paragraph -->',
			]
		);

		$result = PatternAbilities::list_patterns(
			[
				'search'         => 'marketing',
				'includeContent' => false,
				'limit'          => 1,
				'offset'         => 1,
			]
		);

		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 1, $result['patterns'] );
		$this->assertSame( 'theme/marketing-beta', $result['patterns'][0]['name'] );
		$this->assertArrayNotHasKey( 'content', $result['patterns'][0] );
	}

	public function test_list_synced_patterns_defaults_to_synced_wp_block_posts(): void {
		WordPressTestState::$posts     = [
			11 => (object) [
				'ID'                => 11,
				'post_type'         => 'wp_block',
				'post_title'        => 'Shared Header',
				'post_name'         => 'shared-header',
				'post_content'      => '<!-- wp:group -->Header<!-- /wp:group -->',
				'post_status'       => 'publish',
				'post_author'       => 2,
				'post_date_gmt'     => '2026-04-20 00:00:00',
				'post_modified_gmt' => '2026-04-21 00:00:00',
			],
			12 => (object) [
				'ID'                => 12,
				'post_type'         => 'wp_block',
				'post_title'        => 'Unsynced Promo',
				'post_name'         => 'unsynced-promo',
				'post_content'      => '<!-- wp:paragraph --><p>Promo</p><!-- /wp:paragraph -->',
				'post_status'       => 'draft',
				'post_author'       => 4,
				'post_date_gmt'     => '2026-04-18 00:00:00',
				'post_modified_gmt' => '2026-04-19 00:00:00',
			],
		];
		WordPressTestState::$post_meta = [
			12 => [
				'wp_pattern_sync_status' => 'unsynced',
			],
		];

		$result = PatternAbilities::list_synced_patterns( [] );

		$this->assertSame( [ 11 ], array_column( $result['patterns'], 'id' ) );
	}

	public function test_list_synced_patterns_preserves_published_browse_fallback_when_read_post_is_denied(): void {
		WordPressTestState::$capabilities = [
			'read_post:13' => false,
		];
		WordPressTestState::$posts        = [
			13 => $this->synced_pattern_post( 13, 'Published Shared Header', 'Published shared copy', 'publish' ),
		];

		$result = PatternAbilities::list_synced_patterns(
			[
				'includeContent' => true,
			]
		);

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( [ 13 ], array_column( $result['patterns'], 'id' ) );
		$this->assertSame( 'Published Shared Header', $result['patterns'][0]['title'] ?? '' );
		$this->assertStringContainsString( 'Published shared copy', (string) ( $result['patterns'][0]['content'] ?? '' ) );
	}

	public function test_list_synced_patterns_can_filter_partial_and_omit_content_by_default(): void {
		WordPressTestState::$capabilities = [
			'read_post' => static fn( int $post_id ): bool => in_array( $post_id, [ 21, 22 ], true ),
		];
		WordPressTestState::$posts        = [
			21 => (object) [
				'ID'                => 21,
				'post_type'         => 'wp_block',
				'post_title'        => 'Alpha Partial',
				'post_name'         => 'alpha-partial',
				'post_content'      => '<!-- wp:group -->Alpha<!-- /wp:group -->',
				'post_status'       => 'publish',
				'post_author'       => 2,
				'post_date_gmt'     => '2026-04-18 00:00:00',
				'post_modified_gmt' => '2026-04-19 00:00:00',
			],
			22 => (object) [
				'ID'                => 22,
				'post_type'         => 'wp_block',
				'post_title'        => 'Beta Partial',
				'post_name'         => 'beta-partial',
				'post_content'      => '<!-- wp:group -->Beta<!-- /wp:group -->',
				'post_status'       => 'draft',
				'post_author'       => 3,
				'post_date_gmt'     => '2026-04-18 00:00:00',
				'post_modified_gmt' => '2026-04-19 00:00:00',
			],
			23 => (object) [
				'ID'                => 23,
				'post_type'         => 'wp_block',
				'post_title'        => 'Gamma Synced',
				'post_name'         => 'gamma-synced',
				'post_content'      => '<!-- wp:group -->Gamma<!-- /wp:group -->',
				'post_status'       => 'publish',
				'post_author'       => 4,
				'post_date_gmt'     => '2026-04-18 00:00:00',
				'post_modified_gmt' => '2026-04-19 00:00:00',
			],
		];
		WordPressTestState::$post_meta    = [
			21 => [
				'wp_pattern_sync_status' => 'partial',
			],
			22 => [
				'wp_pattern_sync_status' => 'partial',
			],
		];

		$result = PatternAbilities::list_synced_patterns(
			[
				'syncStatus' => 'partial',
				'search'     => 'partial',
				'limit'      => 1,
				'offset'     => 1,
			]
		);

		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 1, $result['patterns'] );
		$this->assertSame( 22, $result['patterns'][0]['id'] );
		$this->assertSame( 'partial', $result['patterns'][0]['syncStatus'] );
		$this->assertSame( 'partial', $result['patterns'][0]['wpPatternSyncStatus'] );
		$this->assertArrayNotHasKey( 'content', $result['patterns'][0] );
	}

	public function test_list_synced_patterns_filters_unreadable_private_patterns(): void {
		WordPressTestState::$capabilities = [
			'read_post' => static fn( int $post_id ): bool => 31 === $post_id,
		];
		WordPressTestState::$posts        = [
			31 => (object) [
				'ID'                => 31,
				'post_type'         => 'wp_block',
				'post_title'        => 'Readable Shared Hero',
				'post_name'         => 'readable-shared-hero',
				'post_content'      => '<!-- wp:group -->Hero<!-- /wp:group -->',
				'post_status'       => 'publish',
				'post_author'       => 2,
				'post_date_gmt'     => '2026-04-18 00:00:00',
				'post_modified_gmt' => '2026-04-19 00:00:00',
			],
			32 => (object) [
				'ID'                => 32,
				'post_type'         => 'wp_block',
				'post_title'        => 'Private Launch Banner',
				'post_name'         => 'private-launch-banner',
				'post_content'      => '<!-- wp:group -->Private launch copy<!-- /wp:group -->',
				'post_status'       => 'private',
				'post_author'       => 3,
				'post_date_gmt'     => '2026-04-18 00:00:00',
				'post_modified_gmt' => '2026-04-19 00:00:00',
			],
		];

		$result = PatternAbilities::list_synced_patterns(
			[
				'includeContent' => true,
				'syncStatus'     => 'all',
			]
		);

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( [ 31 ], array_column( $result['patterns'], 'id' ) );
		$this->assertSame( '<!-- wp:group -->Hero<!-- /wp:group -->', $result['patterns'][0]['content'] );
	}

	public function test_get_synced_pattern_returns_wp_block_pattern_entity(): void {
		WordPressTestState::$capabilities = [
			'read_post:12' => true,
		];
		WordPressTestState::$posts        = [
			12 => (object) [
				'ID'                => 12,
				'post_type'         => 'wp_block',
				'post_title'        => 'Unsynced Promo',
				'post_name'         => 'unsynced-promo',
				'post_content'      => '<!-- wp:paragraph --><p>Promo</p><!-- /wp:paragraph -->',
				'post_status'       => 'draft',
				'post_author'       => 4,
				'post_date_gmt'     => '2026-04-18 00:00:00',
				'post_modified_gmt' => '2026-04-19 00:00:00',
			],
		];
		WordPressTestState::$post_meta    = [
			12 => [
				'wp_pattern_sync_status' => 'unsynced',
			],
		];

		$result = PatternAbilities::get_synced_pattern(
			[
				'patternId' => 12,
			]
		);

		$this->assertSame( 12, $result['id'] );
		$this->assertSame( 'unsynced', $result['syncStatus'] );
		$this->assertSame( 'unsynced', $result['wpPatternSyncStatus'] );
	}

	public function test_get_synced_pattern_preserves_published_browse_fallback_when_read_post_is_denied(): void {
		WordPressTestState::$capabilities = [
			'read_post:14' => false,
		];
		WordPressTestState::$posts        = [
			14 => $this->synced_pattern_post( 14, 'Published Browse Pattern', 'Published browse copy', 'publish' ),
		];

		$result = PatternAbilities::get_synced_pattern(
			[
				'patternId' => 14,
			]
		);

		$this->assertSame( 14, $result['id'] );
		$this->assertSame( 'Published Browse Pattern', $result['title'] );
		$this->assertStringContainsString( 'Published browse copy', (string) ( $result['content'] ?? '' ) );
	}

	public function test_get_synced_pattern_returns_not_found_for_unreadable_pattern(): void {
		WordPressTestState::$capabilities = [
			'read_post:42' => false,
		];
		WordPressTestState::$posts        = [
			42 => (object) [
				'ID'                => 42,
				'post_type'         => 'wp_block',
				'post_title'        => 'Private Pattern',
				'post_name'         => 'private-pattern',
				'post_content'      => '<!-- wp:paragraph --><p>Private</p><!-- /wp:paragraph -->',
				'post_status'       => 'private',
				'post_author'       => 4,
				'post_date_gmt'     => '2026-04-18 00:00:00',
				'post_modified_gmt' => '2026-04-19 00:00:00',
			],
		];

		$result = PatternAbilities::get_synced_pattern(
			[
				'patternId' => 42,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pattern_not_found', $result->get_error_code() );
	}

	public function test_recommend_patterns_returns_missing_credentials_when_backends_are_not_configured(): void {
		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_credentials', $result->get_error_code() );
	}

	public function test_recommend_patterns_short_circuits_explicitly_empty_visible_patterns_before_backend_validation(): void {
		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[]
		);

		$this->assertSame( [ 'recommendations' => [] ], $result );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
	}

	public function test_recommend_patterns_returns_empty_when_visible_pattern_scope_is_absent(): void {
		$result = PatternAbilities::recommend_patterns(
			[
				'postType' => 'page',
			]
		);

		$this->assertSame( [ 'recommendations' => [] ], $result );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
	}

	public function test_recommend_patterns_qdrant_backend_uses_embeddings_and_qdrant(): void {
		$this->configure_backends();
		WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] = Config::PATTERN_BACKEND_QDRANT;
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/hero', 0.81 ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/hero',
								'score'  => 0.9,
								'reason' => 'Matches the current context.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
		);

		$this->assertSame( [ 'theme/hero' ], array_column( $result['recommendations'], 'name' ) );
		$this->assertStringContainsString( '/v1/embeddings', WordPressTestState::$remote_post_calls[0]['url'] ?? '' );
		$this->assertStringContainsString( '/points/query', WordPressTestState::$remote_post_calls[1]['url'] ?? '' );
		$this->assertCount( 1, WordPressTestState::$remote_get_calls );
	}

	public function test_recommend_patterns_cloudflare_ai_search_backend_does_not_call_embeddings_or_qdrant(): void {
		$this->configure_cloudflare_ai_search_backends();
		$this->save_cloudflare_ai_search_index_state();
		$this->register_pattern(
			'theme/hero',
			[
				'title'         => 'Current Hero',
				'categories'    => [ 'featured' ],
				'blockTypes'    => [ 'core/group' ],
				'templateTypes' => [ 'home' ],
				'content'       => '<!-- wp:group --><div>Current hero copy</div><!-- /wp:group -->',
			]
		);

		WordPressTestState::$remote_post_responses = [
			$this->cloudflare_ai_search_chunks_response(
				[
					$this->cloudflare_ai_search_chunk( 'theme/hero', 0.87, 'Indexed hero copy.' ),
					$this->cloudflare_ai_search_chunk( 'theme/hidden', 0.99, 'Hidden copy.' ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/hero',
								'score'  => 0.84,
								'reason' => 'Matches the current hero request.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'     => 'page',
				'templateType' => 'home',
				'prompt'       => 'Hero for a product launch.',
			],
			[ 'theme/hero' ]
		);

		$this->assertSame( [ 'theme/hero' ], array_column( $result['recommendations'], 'name' ) );
		$this->assertSame( 'Current Hero', $result['recommendations'][0]['title'] ?? '' );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertCount( 2, WordPressTestState::$remote_post_calls );
		$this->assertStringContainsString( '/ai-search/namespaces/patterns/instances/pattern-index/search', WordPressTestState::$remote_post_calls[0]['url'] ?? '' );
		$this->assertStringNotContainsString( '/embeddings', wp_json_encode( WordPressTestState::$remote_post_calls ) );
		$this->assertStringNotContainsString( 'qdrant', wp_json_encode( WordPressTestState::$remote_post_calls ) );

		$search_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[0] );
		$this->assertSame(
			[
				'$in' => [ 'theme/hero' ],
			],
			$search_request['ai_search_options']['retrieval']['filters']['pattern_name'] ?? null
		);

		$ranking_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[1] );
		$this->assertStringContainsString( 'Current hero copy', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringNotContainsString( 'Hidden copy', (string) ( $ranking_request['input'] ?? '' ) );
	}

	public function test_recommend_patterns_cloudflare_ai_search_rehydrates_synced_candidates_before_ranking(): void {
		$this->configure_cloudflare_ai_search_backends();
		$this->save_cloudflare_ai_search_index_state();
		WordPressTestState::$capabilities = [
			'read_post:94' => true,
		];
		WordPressTestState::$posts        = [
			94 => $this->synced_pattern_post( 94, 'Current Shared Banner', 'Current shared copy', 'publish' ),
		];

		WordPressTestState::$remote_post_responses = [
			$this->cloudflare_ai_search_chunks_response(
				[
					$this->cloudflare_ai_search_chunk(
						'core/block/94',
						0.92,
						'Stale shared copy from Cloudflare.',
						[
							'candidate_type' => 'user',
							'source'         => 'synced',
							'synced_id'      => '94',
						]
					),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'core/block/94',
								'score'  => 0.91,
								'reason' => 'Best current shared match.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'core/block/94' ]
		);

		$this->assertSame( [ 'core/block/94' ], array_column( $result['recommendations'], 'name' ) );
		$this->assertSame( 'Current Shared Banner', $result['recommendations'][0]['title'] ?? '' );
		$this->assertStringContainsString( 'Current shared copy', $result['recommendations'][0]['content'] ?? '' );

		$ranking_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[1] );
		$this->assertStringContainsString( 'Current Shared Banner', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'Current shared copy', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringNotContainsString( 'Stale shared copy from Cloudflare', (string) ( $ranking_request['input'] ?? '' ) );
	}

	public function test_recommend_patterns_cloudflare_ai_search_omits_unreadable_synced_candidates(): void {
		$this->configure_cloudflare_ai_search_backends();
		$this->save_cloudflare_ai_search_index_state();
		WordPressTestState::$capabilities = [
			'read_post:91' => false,
		];
		WordPressTestState::$posts        = [
			91 => $this->synced_pattern_post( 91, 'Private Launch Banner', 'Private launch copy', 'private' ),
		];

		WordPressTestState::$remote_post_responses = [
			$this->cloudflare_ai_search_chunks_response(
				[
					$this->cloudflare_ai_search_chunk(
						'core/block/91',
						0.96,
						'Private launch copy from Cloudflare.',
						[
							'candidate_type' => 'user',
							'source'         => 'synced',
							'synced_id'      => '91',
						]
					),
				]
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'core/block/91' ]
		);

		$this->assertSame( [], $result['recommendations'] );
		$this->assertSame(
			1,
			$result['diagnostics']['filteredCandidates']['unreadableSyncedPatterns'] ?? null
		);
		$this->assertStringNotContainsString( 'Private launch copy', wp_json_encode( $result ) );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
		$this->assertStringNotContainsString(
			'Private launch copy from Cloudflare',
			(string) ( WordPressTestState::$remote_post_calls[0]['args']['body'] ?? '' )
		);
	}

	public function test_recommend_patterns_cloudflare_ai_search_drops_synced_pattern_after_status_change_before_resync(): void {
		$this->configure_cloudflare_ai_search_backends();
		$this->save_cloudflare_ai_search_index_state();
		WordPressTestState::$capabilities = [
			'read_post:95' => true,
		];
		WordPressTestState::$posts        = [
			95 => $this->synced_pattern_post( 95, 'Drafted Shared Banner', 'Previously public copy', 'draft' ),
		];

		WordPressTestState::$remote_post_responses = [
			$this->cloudflare_ai_search_chunks_response(
				[
					$this->cloudflare_ai_search_chunk(
						'core/block/95',
						0.96,
						'Previously public copy from Cloudflare.',
						[
							'candidate_type' => 'user',
							'source'         => 'synced',
							'synced_id'      => '95',
						]
					),
				]
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'core/block/95' ]
		);

		$this->assertSame( [], $result['recommendations'] );
		$this->assertSame(
			1,
			$result['diagnostics']['filteredCandidates']['unreadableSyncedPatterns'] ?? null
		);
		$this->assertStringNotContainsString( 'Previously public copy', wp_json_encode( $result ) );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_recommend_patterns_cloudflare_ai_search_uses_ai_search_source_signal_and_threshold(): void {
		$this->configure_cloudflare_ai_search_backends();
		$this->save_cloudflare_ai_search_index_state();
		WordPressTestState::$options[ Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH ] = 0.5;
		$this->register_pattern(
			'theme/hero',
			[
				'title'      => 'Current Hero',
				'categories' => [ 'featured' ],
				'content'    => '<!-- wp:group --><div>Current hero copy</div><!-- /wp:group -->',
			]
		);

		WordPressTestState::$remote_post_responses = [
			$this->cloudflare_ai_search_chunks_response(
				[
					$this->cloudflare_ai_search_chunk( 'theme/hero', 0.87, 'Indexed hero copy.' ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/hero',
								'score'  => 0.4,
								'reason' => 'Below the AI Search threshold.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns( [ 'postType' => 'page' ], [ 'theme/hero' ] );

		$this->assertSame( [], $result['recommendations'] );

		WordPressTestState::$remote_post_calls     = [];
		WordPressTestState::$remote_post_responses = [
			$this->cloudflare_ai_search_chunks_response(
				[
					$this->cloudflare_ai_search_chunk( 'theme/hero', 0.87, 'Indexed hero copy.' ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/hero',
								'score'  => 0.4,
								'reason' => 'Accepted at lower threshold.',
							],
						],
					]
				)
			),
		];
		WordPressTestState::$options[ Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH ] = 0;

		$result = $this->recommend_patterns( [ 'postType' => 'page' ], [ 'theme/hero' ] );

		$this->assertSame( [ 'theme/hero' ], array_column( $result['recommendations'], 'name' ) );
		$this->assertSame(
			[ 'cloudflare_ai_search', 'llm_ranker' ],
			$result['recommendations'][0]['ranking']['sourceSignals'] ?? null
		);
		$this->assertSame(
			Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			$result['recommendations'][0]['ranking']['freshnessMeta']['patternBackend'] ?? null
		);
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

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
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

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
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

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
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

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'index_unavailable', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_recommend_patterns_returns_index_warming_for_retryable_error_state_without_usable_index(): void {
		$this->configure_backends();

		$this->save_index_state(
			[
				'status'                 => 'error',
				'last_synced_at'         => null,
				'last_error'             => 'Rate limited.',
				'last_error_code'        => 'rate_limited',
				'last_error_status'      => 429,
				'last_error_retryable'   => true,
				'last_error_retry_after' => 7,
			]
		);

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
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

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
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

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
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

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
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

		$result = $this->recommend_patterns(
			[
				'postType'            => 'page',
				'templateType'        => 'home',
				'blockContext'        => [
					'blockName' => 'core/template-part/header',
				],
				'prompt'              => 'Make the intro more editorial.',
				'visiblePatternNames' => [ 'theme/hero', 'theme/footer-callout' ],
			],
			[ 'theme/hero', 'theme/footer-callout' ]
		);

		$this->assertSame(
			[ 'theme/footer-callout', 'theme/hero' ],
			array_column( $result['recommendations'], 'name' )
		);
		$this->assertSame(
			[ 'Excellent fit', 'Matches the header context.' ],
			array_column( $result['recommendations'], 'reason' )
		);
		$this->assertSame(
			[ 'qdrant_semantic', 'llm_ranker', 'qdrant_structural' ],
			$result['recommendations'][0]['ranking']['sourceSignals'] ?? null
		);
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );

		$embedding_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[0] );
		$this->assertStringContainsString(
			'[POST TYPE]' . "\n" . 'page',
			(string) ( $embedding_request['input'][0] ?? '' )
		);
		$this->assertStringContainsString(
			'[BLOCK CONTEXT]' . "\n" . 'core/template-part/header',
			(string) ( $embedding_request['input'][0] ?? '' )
		);
		$this->assertStringContainsString(
			'[TEMPLATE TYPE]' . "\n" . 'home',
			(string) ( $embedding_request['input'][0] ?? '' )
		);
		$this->assertStringContainsString(
			'[USER INSTRUCTION]' . "\n" . 'Make the intro more editorial.',
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
		$this->assertStringContainsString( '## Visible Pattern Scope', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringNotContainsString( 'theme/header-utility', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringNotContainsString( 'theme/invisible-pattern', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertSame( 'medium', $ranking_request['reasoning']['effort'] ?? null );
	}

	public function test_recommend_patterns_rejects_live_search_when_query_vector_signature_changes(): void {
		$this->configure_backends();
		WordPressTestState::$capabilities = [ 'manage_options' => true ];
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34, 0.56 ] ),
		];

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
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

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
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
		WordPressTestState::$remote_get_response   = [
			'response' => [ 'code' => 404 ],
			'body'     => wp_json_encode(
				[
					'status' => [
						'error' => 'Collection not found',
					],
				]
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
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
		WordPressTestState::$remote_get_response   = $this->qdrant_collection_response( 3 );

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
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

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
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

		$result = $this->recommend_patterns(
			[
				'postType'     => 'page',
				'templateType' => 'home',
				'blockContext' => [
					'blockName' => 'core/cover',
				],
				'prompt'       => 'Make the hero feel more editorial.',
			],
			[ 'theme/hero' ]
		);

		$this->assertSame( [ 'theme/hero' ], array_column( $result['recommendations'], 'name' ) );

		$ranking_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[3] );
		$this->assertStringContainsString( '## WordPress Developer Guidance', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'Cover block reference', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'overlay styling, and inner content layout controls', (string) ( $ranking_request['input'] ?? '' ) );
	}

	public function test_recommend_patterns_docs_grounding_does_not_perform_foreground_ai_search_on_cache_miss(): void {
		$this->configure_backends();
		$this->configure_docs_grounding();
		$this->save_index_state();

		$generic_guidance = [
			[
				'id'        => 'generic-block-editor-doc',
				'title'     => 'Block Editor Handbook',
				'sourceKey' => 'developer.wordpress.org/block-editor',
				'url'       => 'https://developer.wordpress.org/block-editor/',
				'excerpt'   => 'Use patterns that fit the inserter context.',
				'score'     => 0.82,
			],
		];

		AISearchClient::cache_entity_guidance( 'guidance:block-editor', $generic_guidance );

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/hero', 0.71 ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/hero',
								'score'  => 0.82,
								'reason' => 'Matches the current context.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'            => 'page',
				'visiblePatternNames' => [ 'theme/hero' ],
			],
			[ 'theme/hero' ]
		);

		$this->assertSame( [ 'theme/hero' ], array_column( $result['recommendations'], 'name' ) );
		$this->assertCount( 3, WordPressTestState::$remote_post_calls );
		$this->assertStringNotContainsString( 'api.cloudflare.com', wp_json_encode( WordPressTestState::$remote_post_calls ) );
		$this->assertArrayHasKey( AISearchClient::CONTEXT_WARM_CRON_HOOK, WordPressTestState::$scheduled_events );

		$ranking_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[2] );
		$this->assertStringContainsString( 'Block Editor Handbook', (string) ( $ranking_request['input'] ?? '' ) );
	}

	public function test_recommend_patterns_adds_trait_should_clauses_from_insertion_context(): void {
		$this->configure_backends();
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'theme/header-utility',
						0.71,
						[
							'traits' => [ 'simple', 'site-chrome' ],
						]
					),
				]
			),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'theme/header-utility',
						0.79,
						[
							'traits' => [ 'simple', 'site-chrome' ],
						]
					),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/header-utility',
								'score'  => 0.83,
								'reason' => 'Fits the constrained header area.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'         => 'page',
				'insertionContext' => [
					'rootBlock'        => 'core/group',
					'ancestors'        => [ 'core/template-part', 'core/group' ],
					'nearbySiblings'   => [ 'core/site-logo', 'core/navigation' ],
					'templatePartArea' => 'header',
					'templatePartSlug' => 'site-header',
					'containerLayout'  => 'flex',
				],
			],
			[ 'theme/header-utility' ]
		);

		$this->assertSame( [ 'theme/header-utility' ], array_column( $result['recommendations'], 'name' ) );

		$embedding_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[0] );
		$this->assertStringContainsString(
			'Template-part area: header',
			(string) ( $embedding_request['input'][0] ?? '' )
		);
		$this->assertStringContainsString(
			'Template-part slug: site-header',
			(string) ( $embedding_request['input'][0] ?? '' )
		);
		$this->assertStringContainsString(
			'Container layout: flex',
			(string) ( $embedding_request['input'][0] ?? '' )
		);

		$structural_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[2] );
		$this->assertSame(
			[
				[
					'key'   => 'blockTypes',
					'match' => [ 'value' => 'core/group' ],
				],
				[
					'key'   => 'traits',
					'match' => [ 'value' => 'simple' ],
				],
				[
					'key'   => 'traits',
					'match' => [ 'value' => 'site-chrome' ],
				],
			],
			$structural_request['filter']['should'] ?? null
		);

		$ranking_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[3] );
		$this->assertStringContainsString( 'Template-part area: header', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'Template-part slug: site-header', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'Container layout: flex', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( '"traits"', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'structureSummary', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'contentBlockCount', (string) ( $ranking_request['input'] ?? '' ) );
	}

	public function test_recommend_patterns_uses_root_level_structural_fallbacks_when_only_insertion_context_is_present(): void {
		$this->configure_backends();
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'theme/root-section',
						0.73,
						[
							'templateTypes' => [ 'page' ],
							'traits'        => [ 'moderate-complexity' ],
						]
					),
				]
			),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'theme/root-section',
						0.81,
						[
							'templateTypes' => [ 'page' ],
							'traits'        => [ 'moderate-complexity' ],
						]
					),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/root-section',
								'score'  => 0.86,
								'reason' => 'Fits a root-level page insertion.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'         => 'page',
				'insertionContext' => [],
			],
			[ 'theme/root-section' ]
		);

		$this->assertSame( [ 'theme/root-section' ], array_column( $result['recommendations'], 'name' ) );

		$embedding_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[0] );
		$this->assertStringContainsString( 'Area type: root-level', (string) ( $embedding_request['input'][0] ?? '' ) );

		$structural_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[2] );
		$this->assertSame(
			[
				[
					'key'   => 'templateTypes',
					'match' => [ 'value' => 'page' ],
				],
				[
					'key'   => 'traits',
					'match' => [ 'value' => 'moderate-complexity' ],
				],
			],
			$structural_request['filter']['should'] ?? null
		);

		$ranking_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[3] );
		$this->assertStringContainsString( 'Area type: root-level', (string) ( $ranking_request['input'] ?? '' ) );
	}

	public function test_recommend_patterns_uses_single_and_singular_root_level_hints_for_post_contexts(): void {
		$this->configure_backends();
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'theme/post-feature',
						0.73,
						[
							'templateTypes' => [ 'singular' ],
							'traits'        => [ 'moderate-complexity' ],
						]
					),
				]
			),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'theme/post-feature',
						0.81,
						[
							'templateTypes' => [ 'singular' ],
							'traits'        => [ 'moderate-complexity' ],
						]
					),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/post-feature',
								'score'  => 0.88,
								'reason' => 'Fits a root-level post insertion.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'         => 'post',
				'insertionContext' => [],
			],
			[ 'theme/post-feature' ]
		);

		$this->assertSame( [ 'theme/post-feature' ], array_column( $result['recommendations'], 'name' ) );

		$structural_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[2] );
		$this->assertSame(
			[
				[
					'key'   => 'templateTypes',
					'match' => [ 'value' => 'single' ],
				],
				[
					'key'   => 'templateTypes',
					'match' => [ 'value' => 'singular' ],
				],
				[
					'key'   => 'traits',
					'match' => [ 'value' => 'moderate-complexity' ],
				],
			],
			$structural_request['filter']['should'] ?? null
		);
	}

	public function test_recommend_patterns_skips_singular_root_level_hints_for_internal_post_types(): void {
		$this->configure_backends();
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'theme/template-part-shell',
						0.73,
						[
							'traits' => [ 'moderate-complexity' ],
						]
					),
				]
			),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'theme/template-part-shell',
						0.81,
						[
							'traits' => [ 'moderate-complexity' ],
						]
					),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/template-part-shell',
								'score'  => 0.84,
								'reason' => 'Fits the root-level template-part shell.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'         => 'wp_template_part',
				'insertionContext' => [],
			],
			[ 'theme/template-part-shell' ]
		);

		$this->assertSame( [ 'theme/template-part-shell' ], array_column( $result['recommendations'], 'name' ) );

		$structural_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[2] );
		$this->assertSame(
			[
				[
					'key'   => 'traits',
					'match' => [ 'value' => 'moderate-complexity' ],
				],
			],
			$structural_request['filter']['should'] ?? null
		);
	}

	public function test_recommend_patterns_exposes_core_override_overlap_and_deduped_sibling_counts(): void {
		$this->configure_backends();
		$this->save_index_state();
		$this->register_block_type( 'core/image' );
		$this->register_block_type( 'core/heading' );
		$this->register_block_type( 'core/button' );
		$this->register_block_type( 'core/paragraph' );

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'theme/override-ready',
						0.81,
						[
							'patternOverrides' => [
								'hasOverrides'          => true,
								'blockCount'            => 4,
								'blockNames'            => [ 'core/image', 'core/heading', 'core/button', 'core/paragraph' ],
								'bindableAttributes'    => [
									'core/image'     => [ 'url', 'alt' ],
									'core/heading'   => [ 'content' ],
									'core/button'    => [ 'text', 'url' ],
									'core/paragraph' => [ 'content' ],
								],
								'overrideAttributes'    => [
									'core/image'     => [ 'url', 'alt' ],
									'core/heading'   => [ 'content' ],
									'core/button'    => [ 'text', 'url' ],
									'core/paragraph' => [ 'content' ],
								],
								'usesDefaultBinding'    => true,
								'unsupportedAttributes' => [],
							],
						]
					),
					$this->pattern_point( 'theme/plain-pattern', 0.82 ),
				]
			),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'theme/override-ready',
						0.83,
						[
							'patternOverrides' => [
								'hasOverrides'          => true,
								'blockCount'            => 4,
								'blockNames'            => [ 'core/image', 'core/heading', 'core/button', 'core/paragraph' ],
								'bindableAttributes'    => [
									'core/image'     => [ 'url', 'alt' ],
									'core/heading'   => [ 'content' ],
									'core/button'    => [ 'text', 'url' ],
									'core/paragraph' => [ 'content' ],
								],
								'overrideAttributes'    => [
									'core/image'     => [ 'url', 'alt' ],
									'core/heading'   => [ 'content' ],
									'core/button'    => [ 'text', 'url' ],
									'core/paragraph' => [ 'content' ],
								],
								'usesDefaultBinding'    => true,
								'unsupportedAttributes' => [],
							],
						]
					),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/override-ready',
								'score'  => 0.9,
								'reason' => 'Fits the media-heavy layout.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'         => 'page',
				'blockContext'     => [
					'blockName' => 'core/image',
				],
				'insertionContext' => [
					'nearbySiblings' => [
						'core/heading',
						'core/heading',
						'core/button',
						'core/paragraph',
						'core/image',
						'core/button',
					],
				],
			],
			[ 'theme/override-ready', 'theme/plain-pattern' ]
		);

		$this->assertSame( [ 'theme/override-ready' ], array_column( $result['recommendations'], 'name' ) );
		$this->assertStringContainsString(
			'Supports Pattern Overrides for core/image (url, alt).',
			(string) ( $result['recommendations'][0]['reason'] ?? '' )
		);
		$this->assertTrue( (bool) ( $result['recommendations'][0]['overrideCapabilities']['matchesNearbyBlock'] ?? false ) );
		$this->assertSame(
			[ 'url', 'alt' ],
			$result['recommendations'][0]['overrideCapabilities']['nearbyBlockOverlapAttrs'] ?? null
		);
		$this->assertSame(
			4,
			$result['recommendations'][0]['overrideCapabilities']['siblingOverrideCount'] ?? null
		);

		$ranking_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[3] );
		$this->assertStringContainsString( 'matchesNearbyBlock', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'nearbyBlockOverlapAttrs', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( '"siblingOverrideCount":4', (string) ( $ranking_request['input'] ?? '' ) );
	}

	public function test_recommend_patterns_sharpens_custom_block_override_reasons_without_widening_scope(): void {
		$this->configure_backends();
		$this->save_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'theme/custom-block-generic',
						0.78,
						[
							'patternOverrides' => [
								'hasOverrides'          => true,
								'blockCount'            => 1,
								'blockNames'            => [ 'plugin/card' ],
								'bindableAttributes'    => [ 'plugin/card' => [ 'title' ] ],
								'overrideAttributes'    => [ 'plugin/card' => [ 'title' ] ],
								'usesDefaultBinding'    => false,
								'unsupportedAttributes' => [],
							],
						]
					),
					$this->pattern_point( 'theme/plain-pattern', 0.79 ),
				]
			),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'theme/custom-block-generic',
						0.81,
						[
							'patternOverrides' => [
								'hasOverrides'          => true,
								'blockCount'            => 1,
								'blockNames'            => [ 'plugin/card' ],
								'bindableAttributes'    => [ 'plugin/card' => [ 'title' ] ],
								'overrideAttributes'    => [ 'plugin/card' => [ 'title' ] ],
								'usesDefaultBinding'    => true,
								'unsupportedAttributes' => [],
							],
						]
					),
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

		$result = $this->recommend_patterns(
			[
				'postType'     => 'page',
				'blockContext' => [
					'blockName' => 'plugin/card',
				],
				'prompt'       => 'Keep it flexible for repeated card instances.',
			],
			[ 'theme/custom-block-generic', 'theme/plain-pattern' ]
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

	public function test_recommend_patterns_skips_unreadable_synced_qdrant_candidates_before_ranking(): void {
		$this->configure_backends();
		$this->save_index_state();
		$payload_json                     = wp_json_encode( [ $this->synced_pattern_point( 91, 0.96, 'Private Launch Banner', 'Private launch copy' ) ] );
		WordPressTestState::$capabilities = [
			'read_post:91' => false,
		];
		WordPressTestState::$posts        = [
			91 => $this->synced_pattern_post( 91, 'Private Launch Banner', 'Private launch copy', 'private' ),
		];

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->synced_pattern_point( 91, 0.96, 'Private Launch Banner', 'Private launch copy' ),
				]
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'            => 'page',
				'visiblePatternNames' => [ 'core/block/91' ],
			],
			[ 'core/block/91' ]
		);

		$this->assertSame( [], $result['recommendations'] );
		$this->assertSame(
			1,
			$result['diagnostics']['filteredCandidates']['unreadableSyncedPatterns'] ?? null
		);
		$this->assertStringNotContainsString( 'Private launch copy', wp_json_encode( $result ) );
		$this->assertCount( 2, WordPressTestState::$remote_post_calls );
		$this->assertStringNotContainsString(
			'Private Launch Banner',
			(string) ( WordPressTestState::$remote_post_calls[1]['args']['body'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'Private launch copy',
			(string) ( WordPressTestState::$remote_post_calls[1]['args']['body'] ?? '' )
		);
		$this->assertStringContainsString( 'Private Launch Banner', (string) $payload_json );
	}

	public function test_recommend_patterns_skips_published_synced_candidate_when_read_post_is_denied(): void {
		$this->configure_backends();
		$this->save_index_state();
		WordPressTestState::$capabilities = [
			'read_post:92' => false,
		];
		WordPressTestState::$posts        = [
			92 => $this->synced_pattern_post( 92, 'Published Shared Banner', 'Published shared copy', 'publish' ),
		];

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->synced_pattern_point( 92, 0.96, 'Published Shared Banner', 'Published shared copy' ),
				]
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'            => 'page',
				'visiblePatternNames' => [ 'core/block/92' ],
			],
			[ 'core/block/92' ]
		);

		$this->assertSame( [], $result['recommendations'] );
		$this->assertSame(
			1,
			$result['diagnostics']['filteredCandidates']['unreadableSyncedPatterns'] ?? null
		);
		$this->assertCount( 2, WordPressTestState::$remote_post_calls );
	}

	public function test_recommend_patterns_skips_draft_synced_candidate_even_when_read_post_is_allowed(): void {
		$this->configure_backends();
		$this->save_index_state();
		WordPressTestState::$capabilities = [
			'read_post:96' => true,
		];
		WordPressTestState::$posts        = [
			96 => $this->synced_pattern_post( 96, 'Draft Shared Banner', 'Draft shared copy', 'draft' ),
		];

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->synced_pattern_point( 96, 0.96, 'Draft Shared Banner', 'Draft shared copy' ),
				]
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'            => 'page',
				'visiblePatternNames' => [ 'core/block/96' ],
			],
			[ 'core/block/96' ]
		);

		$this->assertSame( [], $result['recommendations'] );
		$this->assertSame(
			1,
			$result['diagnostics']['filteredCandidates']['unreadableSyncedPatterns'] ?? null
		);
		$this->assertStringNotContainsString( 'Draft shared copy', wp_json_encode( $result ) );
	}

	public function test_recommend_patterns_treats_legacy_core_block_name_as_synced_candidate(): void {
		$this->configure_backends();
		$this->save_index_state();
		$payload_json                     = wp_json_encode(
			[
				$this->pattern_point(
					'core/block/93',
					0.96,
					[
						'id'      => 'core/block/93',
						'title'   => 'Legacy Private Banner',
						'content' => '<!-- wp:paragraph --><p>Legacy private copy</p><!-- /wp:paragraph -->',
					]
				),
			]
		);
		WordPressTestState::$capabilities = [
			'read_post:93' => false,
		];
		WordPressTestState::$posts        = [
			93 => $this->synced_pattern_post( 93, 'Legacy Private Banner', 'Legacy private copy', 'private' ),
		];

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point(
						'core/block/93',
						0.96,
						[
							'id'      => 'core/block/93',
							'title'   => 'Legacy Private Banner',
							'content' => '<!-- wp:paragraph --><p>Legacy private copy</p><!-- /wp:paragraph -->',
						]
					),
				]
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'            => 'page',
				'visiblePatternNames' => [ 'core/block/93' ],
			],
			[ 'core/block/93' ]
		);

		$this->assertSame( [], $result['recommendations'] );
		$this->assertSame(
			1,
			$result['diagnostics']['filteredCandidates']['unreadableSyncedPatterns'] ?? null
		);
		$this->assertStringNotContainsString( 'Legacy private copy', wp_json_encode( $result ) );
		$this->assertStringNotContainsString(
			'Legacy Private Banner',
			(string) ( WordPressTestState::$remote_post_calls[1]['args']['body'] ?? '' )
		);
		$this->assertStringContainsString( 'Legacy Private Banner', (string) $payload_json );
	}

	public function test_recommend_patterns_rehydrates_readable_synced_candidates_before_ranking(): void {
		$this->configure_backends();
		$this->save_index_state();
		WordPressTestState::$capabilities = [
			'read_post:94' => true,
		];
		WordPressTestState::$posts        = [
			94 => $this->synced_pattern_post( 94, 'Current Shared Banner', 'Current shared copy', 'publish' ),
		];

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->synced_pattern_point( 94, 0.96, 'Stale Shared Banner', 'Stale shared copy' ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'core/block/94',
								'score'  => 0.91,
								'reason' => 'Best current shared match.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'            => 'page',
				'visiblePatternNames' => [ 'core/block/94' ],
			],
			[ 'core/block/94' ]
		);

		$this->assertSame( [ 'core/block/94' ], array_column( $result['recommendations'], 'name' ) );
		$this->assertSame( 'Current Shared Banner', $result['recommendations'][0]['title'] ?? '' );
		$this->assertStringContainsString( 'Current shared copy', $result['recommendations'][0]['content'] ?? '' );

		$ranking_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[2] );
		$this->assertStringContainsString( 'Current Shared Banner', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringContainsString( 'Current shared copy', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringNotContainsString( 'Stale Shared Banner', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringNotContainsString( 'Stale shared copy', (string) ( $ranking_request['input'] ?? '' ) );
	}

	public function test_recommend_patterns_reports_unreadable_synced_candidates_when_readable_results_remain(): void {
		$this->configure_backends();
		$this->save_index_state();
		WordPressTestState::$capabilities = [
			'read_post:91' => false,
			'read_post:94' => true,
		];
		WordPressTestState::$posts        = [
			91 => $this->synced_pattern_post( 91, 'Private Launch Banner', 'Private launch copy', 'private' ),
			94 => $this->synced_pattern_post( 94, 'Current Shared Banner', 'Current shared copy', 'publish' ),
		];

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->synced_pattern_point( 91, 0.96, 'Private Launch Banner', 'Private launch copy' ),
					$this->synced_pattern_point( 94, 0.94, 'Stale Shared Banner', 'Stale shared copy' ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'core/block/94',
								'score'  => 0.91,
								'reason' => 'Readable shared pattern fits.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'            => 'page',
				'visiblePatternNames' => [ 'core/block/91', 'core/block/94' ],
			],
			[ 'core/block/91', 'core/block/94' ]
		);

		$this->assertSame( [ 'core/block/94' ], array_column( $result['recommendations'], 'name' ) );
		$this->assertSame(
			1,
			$result['diagnostics']['filteredCandidates']['unreadableSyncedPatterns'] ?? null
		);
		$this->assertStringNotContainsString( 'Private launch copy', wp_json_encode( $result ) );
	}

	public function test_recommend_patterns_does_not_report_unreadable_synced_candidates_outside_visible_scope(): void {
		$this->configure_backends();
		$this->save_index_state();
		WordPressTestState::$capabilities = [
			'read_post:91' => false,
			'read_post:94' => true,
		];
		WordPressTestState::$posts        = [
			91 => $this->synced_pattern_post( 91, 'Private Launch Banner', 'Private launch copy', 'private' ),
			94 => $this->synced_pattern_post( 94, 'Current Shared Banner', 'Current shared copy', 'publish' ),
		];

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->synced_pattern_point( 91, 0.96, 'Private Launch Banner', 'Private launch copy' ),
					$this->synced_pattern_point( 94, 0.94, 'Stale Shared Banner', 'Stale shared copy' ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'core/block/94',
								'score'  => 0.91,
								'reason' => 'Readable shared pattern fits.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'            => 'page',
				'visiblePatternNames' => [ 'core/block/94' ],
			],
			[ 'core/block/94' ]
		);

		$this->assertSame( [ 'core/block/94' ], array_column( $result['recommendations'], 'name' ) );
		$this->assertSame(
			0,
			$result['diagnostics']['filteredCandidates']['unreadableSyncedPatterns'] ?? null
		);
		$this->assertStringNotContainsString( 'Private launch copy', wp_json_encode( $result ) );

		$ranking_request = $this->decode_request_body( WordPressTestState::$remote_post_calls[2] );
		$this->assertStringNotContainsString( 'Private Launch Banner', (string) ( $ranking_request['input'] ?? '' ) );
		$this->assertStringNotContainsString( 'Private launch copy', (string) ( $ranking_request['input'] ?? '' ) );
	}

	public function test_recommend_patterns_reports_duplicate_unreadable_synced_candidate_once(): void {
		$this->configure_backends();
		$this->save_index_state();
		WordPressTestState::$capabilities = [
			'read_post:91' => false,
		];
		WordPressTestState::$posts        = [
			91 => $this->synced_pattern_post( 91, 'Private Launch Banner', 'Private launch copy', 'private' ),
		];

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->synced_pattern_point( 91, 0.96, 'Private Launch Banner', 'Private launch copy' ),
				]
			),
			$this->qdrant_points_response(
				[
					$this->synced_pattern_point( 91, 0.94, 'Private Launch Banner', 'Private launch copy' ),
				]
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType'         => 'page',
				'insertionContext' => [
					'rootBlock'        => 'core/group',
					'ancestors'        => [ 'core/template-part', 'core/group' ],
					'templatePartArea' => 'header',
				],
			],
			[ 'core/block/91' ]
		);

		$this->assertSame( [], $result['recommendations'] );
		$this->assertSame(
			1,
			$result['diagnostics']['filteredCandidates']['unreadableSyncedPatterns'] ?? null
		);
		$this->assertStringNotContainsString( 'Private launch copy', wp_json_encode( $result ) );
		$this->assertCount( 3, WordPressTestState::$remote_post_calls );
	}

	public function test_recommend_patterns_uses_generic_chat_with_direct_openai_native_embeddings(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                        => Provider::NATIVE,
			'flavor_agent_openai_native_api_key'         => 'native-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_qdrant_url'                    => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                    => 'qdrant-key',
		];

		WordPressTestState::$ai_client_supported = true;

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

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/hero' ]
		);

		$this->assertSame( [ 'theme/hero' ], array_column( $result['recommendations'], 'name' ) );
		$this->assertSame( 0.82, $result['recommendations'][0]['ranking']['score'] ?? null );
		$this->assertSame( 'Matches the current context.', $result['recommendations'][0]['ranking']['reason'] ?? null );
		$this->assertSame( 'validated', $result['recommendations'][0]['ranking']['safetyMode'] ?? null );
		$this->assertSame(
			[ 'qdrant_semantic', 'llm_ranker' ],
			$result['recommendations'][0]['ranking']['sourceSignals'] ?? null
		);
		$this->assertArrayNotHasKey( 'provider', WordPressTestState::$last_ai_client_prompt );
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

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			array_map(
				static fn( int $index ): string => "theme/pattern-{$index}",
				range( 1, 9 )
			)
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

	public function test_recommend_patterns_respects_configured_score_threshold(): void {
		$this->configure_backends();
		$this->save_index_state();
		WordPressTestState::$options['flavor_agent_pattern_recommendation_threshold']                        = 0.75;
		WordPressTestState::$options[ Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH ] = 0;

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/pattern-a', 0.91 ),
					$this->pattern_point( 'theme/pattern-b', 0.89 ),
					$this->pattern_point( 'theme/pattern-c', 0.88 ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/pattern-a',
								'score'  => 0.91,
								'reason' => 'Strong fit.',
							],
							[
								'name'   => 'theme/pattern-b',
								'score'  => 0.79,
								'reason' => 'Still a fit.',
							],
							[
								'name'   => 'theme/pattern-c',
								'score'  => 0.74,
								'reason' => 'Below threshold.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/pattern-a', 'theme/pattern-b', 'theme/pattern-c' ]
		);

		$this->assertSame(
			[ 'theme/pattern-a', 'theme/pattern-b' ],
			array_column( $result['recommendations'], 'name' )
		);
	}

	public function test_recommend_patterns_respects_configured_max_results(): void {
		$this->configure_backends();
		$this->save_index_state();
		WordPressTestState::$options['flavor_agent_pattern_max_recommendations'] = 2;

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/pattern-a', 0.91 ),
					$this->pattern_point( 'theme/pattern-b', 0.89 ),
					$this->pattern_point( 'theme/pattern-c', 0.88 ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/pattern-a',
								'score'  => 0.91,
								'reason' => 'Strong fit.',
							],
							[
								'name'   => 'theme/pattern-b',
								'score'  => 0.89,
								'reason' => 'Strong fit.',
							],
							[
								'name'   => 'theme/pattern-c',
								'score'  => 0.88,
								'reason' => 'Strong fit.',
							],
						],
					]
				)
			),
		];

		$result = $this->recommend_patterns(
			[
				'postType' => 'page',
			],
			[ 'theme/pattern-a', 'theme/pattern-b', 'theme/pattern-c' ]
		);

		$this->assertSame(
			[ 'theme/pattern-a', 'theme/pattern-b' ],
			array_column( $result['recommendations'], 'name' )
		);
	}

	private function configure_backends(): void {
		WordPressTestState::$connectors                 = array_merge(
			WordPressTestState::$connectors,
			[
				'openai' => [
					'name'           => 'OpenAI',
					'description'    => 'OpenAI connector',
					'type'           => 'ai_provider',
					'authentication' => [
						'method'       => 'api_key',
						'setting_name' => 'connectors_ai_openai_api_key',
					],
				],
			]
		);
		WordPressTestState::$options                    = array_merge(
			WordPressTestState::$options,
			[
				Provider::OPTION_NAME                => Provider::NATIVE,
				'flavor_agent_openai_native_api_key' => 'native-key',
				'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
				'flavor_agent_qdrant_url'            => 'https://example.cloud.qdrant.io:6333',
				'flavor_agent_qdrant_key'            => 'qdrant-key',
			]
		);
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = array_merge(
			WordPressTestState::$ai_client_provider_support,
			[
				'openai' => true,
			]
		);
	}

	private function configure_cloudflare_ai_search_backends(): void {
		$this->configure_backends();

		WordPressTestState::$options = array_merge(
			WordPressTestState::$options,
			[
				Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_ACCOUNT_ID => 'account-123',
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE => 'patterns',
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => 'pattern-index',
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN => 'token-xyz',
			]
		);
	}

	private function disable_public_docs_grounding(): void {
		\add_filter(
			'flavor_agent_cloudflare_ai_search_public_search_url',
			static fn(): string => ''
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

	private function recommend_patterns( array $input, array $visible_pattern_names ): array|\WP_Error {
		$input['visiblePatternNames'] = $visible_pattern_names;
		return PatternAbilities::recommend_patterns( $input );
	}

	private function save_index_state( array $overrides = [] ): void {
		$embedding_config    = Provider::embedding_configuration();
		$embedding_signature = EmbeddingClient::build_signature_for_dimension( 2, $embedding_config );

		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'                 => 'ready',
					'fingerprint'            => 'fingerprint-123',
					'qdrant_url'             => (string) get_option( 'flavor_agent_qdrant_url', '' ),
					'qdrant_collection'      => QdrantClient::get_collection_name( $embedding_signature ),
					'openai_provider'        => $embedding_config['provider'],
					'openai_endpoint'        => $embedding_config['endpoint'],
					'embedding_model'        => $embedding_config['model'],
					'embedding_dimension'    => 2,
					'embedding_signature'    => $embedding_signature['signature_hash'],
					'last_synced_at'         => '2026-03-24T00:00:00+00:00',
					'last_attempt_at'        => '2000-01-01T00:00:00+00:00',
					'indexed_count'          => 3,
					'last_error'             => null,
					'last_error_code'        => '',
					'last_error_status'      => 0,
					'last_error_retryable'   => false,
					'last_error_retry_after' => null,
					'stale_reason'           => '',
					'stale_reasons'          => [],
					'pattern_fingerprints'   => [],
				],
				$overrides
			)
		);

		if ( [] === WordPressTestState::$remote_get_responses ) {
			WordPressTestState::$remote_get_response = $this->qdrant_collection_response( 2 );
		}
	}

	private function save_cloudflare_ai_search_index_state( array $overrides = [] ): void {
		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'                         => 'ready',
					'pattern_backend'                => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
					'fingerprint'                    => 'cloudflare-fingerprint-123',
					'qdrant_url'                     => '',
					'qdrant_collection'              => '',
					'cloudflare_ai_search_namespace' => (string) get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE, '' ),
					'cloudflare_ai_search_instance'  => (string) get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID, '' ),
					'cloudflare_ai_search_signature' => $this->expected_cloudflare_ai_search_signature(),
					'openai_provider'                => '',
					'openai_endpoint'                => '',
					'embedding_model'                => '',
					'embedding_dimension'            => 0,
					'embedding_signature'            => '',
					'last_synced_at'                 => '2026-03-24T00:00:00+00:00',
					'last_attempt_at'                => '2000-01-01T00:00:00+00:00',
					'indexed_count'                  => 3,
					'last_error'                     => null,
					'last_error_code'                => '',
					'last_error_status'              => 0,
					'last_error_retryable'           => false,
					'last_error_retry_after'         => null,
					'stale_reason'                   => '',
					'stale_reasons'                  => [],
					'pattern_fingerprints'           => [],
				],
				$overrides
			)
		);
	}

	private function expected_cloudflare_ai_search_signature(): string {
		return hash(
			'sha256',
			implode(
				'|',
				array_map(
					'trim',
					[
						(string) get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_ACCOUNT_ID, '' ),
						(string) get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE, '' ),
						(string) get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID, '' ),
						(string) get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN, '' ),
					]
				)
			)
		);
	}

	private function register_pattern( string $name, array $properties ): void {
		\WP_Block_Patterns_Registry::get_instance()->register( $name, $properties );
	}

	private function register_block_type( string $name, array $settings = [] ): void {
		\WP_Block_Type_Registry::get_instance()->register( $name, $settings );
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
	 * @param array<int, array<string, mixed>> $chunks
	 * @return array<string, mixed>
	 */
	private function cloudflare_ai_search_chunks_response( array $chunks ): array {
		return [
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
	 * @param array<string, mixed> $metadata_overrides
	 * @return array<string, mixed>
	 */
	private function cloudflare_ai_search_chunk( string $name, float $score, string $text, array $metadata_overrides = [] ): array {
		$metadata = array_merge(
			[
				'pattern_name'   => $name,
				'candidate_type' => 'pattern',
				'source'         => 'registered',
				'synced_id'      => str_replace( '/', '-', $name ),
				'public_safe'    => true,
			],
			$metadata_overrides
		);

		return [
			'id'    => str_replace( '/', '-', $name ) . '-chunk',
			'score' => $score,
			'text'  => $text,
			'item'  => [
				'key'      => str_replace( '/', '-', $name ) . '.md',
				'metadata' => $metadata,
			],
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
			'name'             => $name,
			'title'            => ucwords( str_replace( [ 'theme/', '-' ], [ '', ' ' ], $name ) ),
			'description'      => "Description for {$name}",
			'categories'       => [ 'marketing' ],
			'blockTypes'       => [ 'core/template-part/header' ],
			'templateTypes'    => [ 'home' ],
			'patternOverrides' => [
				'hasOverrides'          => $name === 'theme/footer-callout',
				'blockCount'            => $name === 'theme/footer-callout' ? 1 : 0,
				'blockNames'            => $name === 'theme/footer-callout' ? [ 'core/heading' ] : [],
				'bindableAttributes'    => $name === 'theme/footer-callout' ? [ 'core/heading' => [ 'content' ] ] : [],
				'overrideAttributes'    => $name === 'theme/footer-callout' ? [ 'core/heading' => [ 'content' ] ] : [],
				'usesDefaultBinding'    => false,
				'unsupportedAttributes' => [],
			],
			'content'          => "<!-- wp:paragraph --><p>{$name}</p><!-- /wp:paragraph -->",
		];

		return [
			'score'   => $score,
			'payload' => array_replace_recursive( $payload, $overrides ),
		];
	}

	private function synced_pattern_post( int $id, string $title, string $copy, string $status = 'publish' ): object {
		return (object) [
			'ID'                => $id,
			'post_type'         => 'wp_block',
			'post_title'        => $title,
			'post_name'         => 'synced-pattern-' . $id,
			'post_content'      => '<!-- wp:paragraph --><p>' . $copy . '</p><!-- /wp:paragraph -->',
			'post_status'       => $status,
			'post_author'       => 7,
			'post_date_gmt'     => '2026-04-20 00:00:00',
			'post_modified_gmt' => '2026-04-21 00:00:00',
		];
	}

	/**
	 * @return array{score: float, payload: array<string, mixed>}
	 */
	private function synced_pattern_point( int $id, float $score, string $title, string $copy ): array {
		return $this->pattern_point(
			'core/block/' . $id,
			$score,
			[
				'id'                  => 'core/block/' . $id,
				'title'               => $title,
				'type'                => 'user',
				'source'              => 'synced',
				'syncedPatternId'     => $id,
				'syncStatus'          => 'synced',
				'wpPatternSyncStatus' => '',
				'content'             => '<!-- wp:paragraph --><p>' . $copy . '</p><!-- /wp:paragraph -->',
			]
		);
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
