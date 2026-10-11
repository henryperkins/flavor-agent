<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

require_once __DIR__ . '/Support/theme-json-stub.php';

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\BlockAbilities;
use FlavorAgent\Abilities\InfraAbilities;
use FlavorAgent\Abilities\RecommendationAbilityExecution;
use FlavorAgent\Abilities\StyleAbilities;
use FlavorAgent\Abilities\WordPressDocsAbilities;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Apply\PendingApplyDecision;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Cloudflare\PatternSearchClient;
use FlavorAgent\Cloudflare\PatternSearchInstanceManager;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Support\CollectsDocsGuidance;
use FlavorAgent\Support\ThemeJsonCapabilities;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DocsGroundingDisabledReleaseTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		\WP_Theme_JSON_Gutenberg::reset();
		ThemeJsonCapabilities::flush();
	}

	public function test_default_release_ignores_primed_docs_cache_in_recommendation_and_signature_modes(): void {
		$query = 'paragraph typography';
		$this->prime_docs_cache( $query );
		WordPressTestState::$remote_post_response = new \WP_Error( 'http_request_failed', 'External docs must not be contacted.' );

		foreach ( [ 'recommendation', 'signature' ] as $mode ) {
			$result = CollectsDocsGuidance::collect_result(
				static fn( array $context, string $prompt ): string => $query,
				[ 'block' => [ 'name' => 'core/paragraph' ] ],
				'make it clearer',
				[ 'mode' => $mode ]
			);
			$this->assertFalse( $result['available'], $mode );
			$this->assertSame( 0, $result['count'], $mode );
			$this->assertSame( [], $result['guidance'], $mode );
			$this->assertCount( 0, WordPressTestState::$remote_post_calls, $mode );
		}
	}

	public function test_direct_docs_ability_is_unavailable_without_an_http_request(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ 'result' => [ 'chunks' => [] ] ] ),
		];

		$result = WordPressDocsAbilities::search_wordpress_docs( [ 'query' => 'theme.json color presets' ] );

		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_status_does_not_advertise_the_disabled_docs_ability(): void {
		WordPressTestState::$capabilities = [
			'manage_options' => true,
			'edit_posts'     => true,
		];

		$result = InfraAbilities::check_status( [] );

		$this->assertFalse( $result['backends']['cloudflare_ai_search']['configured'] );
		$this->assertNotContains( 'flavor-agent/search-wordpress-docs', $result['availableAbilities'] );
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
	}

	public function test_native_recommendation_keeps_site_guidelines_and_opted_in_cached_roadmap_without_docs_http(): void {
		$this->configure_text_generation_connector();
		\WP_Block_Type_Registry::get_instance()->register(
			'core/paragraph',
			[
				'title'      => 'Paragraph',
				'attributes' => [
					'content' => [
						'type' => 'string',
						'role' => 'content',
					],
				],
				'supports'   => [ 'color' => [ 'text' => true ] ],
			]
		);
		WordPressTestState::$wpai_formatted_guidelines                           = 'Site: Use a calm voice. Blocks: Keep paragraphs concise.';
		WordPressTestState::$ai_client_generate_text_result                      = wp_json_encode(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Keep the paragraph concise.',
			]
		);
		WordPressTestState::$transients['flavor_agent_core_roadmap_guidance_v1'] = [
			[
				'title'      => 'WordPress AI roadmap',
				'sourceKey'  => 'github.com/orgs/WordPress/projects/240',
				'sourceType' => 'roadmap',
				'url'        => 'https://github.com/orgs/WordPress/projects/240',
				'excerpt'    => 'Open roadmap milestones: Core Improvements.',
			],
		];
		$roadmap_enabled  = static fn(): bool => true;
		$generated_prompt = [];
		add_filter( 'flavor_agent_enable_core_roadmap_guidance', $roadmap_enabled );
		try {
			$result = RecommendationAbilityExecution::execute(
				'block',
				'flavor-agent/recommend-block',
				[
					'prompt'        => 'make it clearer',
					'selectedBlock' => [
						'blockName'  => 'core/paragraph',
						'attributes' => [ 'content' => 'Hello world' ],
					],
				],
				static function ( array $input ) use ( &$generated_prompt ): array|\WP_Error {
					$result           = BlockAbilities::recommend_block( $input );
					$generated_prompt = WordPressTestState::$last_ai_client_prompt;
					return $result;
				},
				[
					'categories' => [ 'site', 'copy', 'images', 'additional' ],
					'blockName'  => 'core/paragraph',
				]
			);
		} finally {
			remove_filter( 'flavor_agent_enable_core_roadmap_guidance', $roadmap_enabled );
		}

		$this->assertIsArray( $result );
		$this->assertSame( 'Keep the paragraph concise.', $result['explanation'] );
		$this->assertFalse( $result['docsGrounding']['available'] );
		$this->assertSame( 0, $result['docsGrounding']['count'] );
		$this->assertSame( [], $result['docsGrounding']['sourceTypes'] );
		$this->assertStringContainsString( 'Site: Use a calm voice.', $generated_prompt['system'] );
		$this->assertStringContainsString( 'Blocks: Keep paragraphs concise.', $generated_prompt['system'] );
		$this->assertStringContainsString( 'Open roadmap milestones: Core Improvements.', $generated_prompt['text'] );
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
	}

	public function test_disabled_docs_does_not_disable_the_separate_pattern_search_backend(): void {
		$account_id                               = 'account-123';
		$api_token                                = 'token-xyz';
		$embedding_model                          = '@cf/qwen/qwen3-embedding-0.6b';
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_workers_ai_account_id' => $account_id,
			'flavor_agent_cloudflare_workers_ai_api_token' => $api_token,
			'flavor_agent_cloudflare_workers_ai_embedding_model' => $embedding_model,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => PatternSearchInstanceManager::credential_signature( $account_id, $api_token, $embedding_model ),
		];
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'    => 'pattern-1',
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
									],
								],
							],
						],
					],
				]
			),
		];

		$result = PatternSearchClient::search_patterns( 'Build a hero section', [ 'theme/hero' ], 1 );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'theme/hero', $result[0]['name'] );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/instances/' . PatternSearchInstanceManager::managed_instance_id() . '/search',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertFalse( AISearchClient::is_configured() );
	}

	public function test_grounded_review_is_stale_after_disable_and_fresh_ungrounded_apply_succeeds(): void {
		WordPressTestState::$current_user_id = 7;
		WordPressTestState::$capabilities    = [
			'edit_theme_options' => true,
			'edit_posts'         => true,
			'edit_post'          => true,
		];
		Repository::install();
		WordPressTestState::$posts[17]       = new \WP_Post(
			[
				'ID'           => 17,
				'post_type'    => 'wp_global_styles',
				'post_content' => wp_json_encode(
					[
						'version'                     => 3,
						'isGlobalStylesUserThemeJSON' => true,
						'settings'                    => [],
						'styles'                      => [],
					]
				),
			]
		);
		WordPressTestState::$global_settings = [
			'color' => [
				'palette'    => [
					'theme' => [
						[
							'slug'  => 'accent',
							'name'  => 'Accent',
							'color' => '#111111',
						],
					],
				],
				'background' => true,
				'text'       => true,
			],
		];
		WordPressTestState::$global_styles   = [ 'color' => [ 'background' => '#fefefe' ] ];
		$input                               = [
			'scope'                => [
				'surface'        => 'global-styles',
				'globalStylesId' => '17',
			],
			'styleContext'         => [
				'currentConfig' => [
					'settings' => [],
					'styles'   => [],
				],
				'mergedConfig'  => [
					'settings' => [],
					'styles'   => [],
				],
			],
			'prompt'               => 'darker',
			'resolveSignatureOnly' => true,
		];
		$context_builder                     = new ReflectionMethod( StyleAbilities::class, 'build_global_styles_context' );
		$context_builder->setAccessible( true );
		$context       = $context_builder->invoke( null, $input['scope'], $input['styleContext'] );
		$query_builder = new ReflectionMethod( StyleAbilities::class, 'build_wordpress_docs_query' );
		$query_builder->setAccessible( true );
		$this->prime_docs_cache( $query_builder->invoke( null, $context, $input['prompt'] ) );
		$docs_enabled = static fn(): bool => true;
		add_filter( 'flavor_agent_docs_grounding_enabled', $docs_enabled );
		try {
			$grounded_review = StyleAbilities::recommend_style( $input );
		} finally {
			remove_filter( 'flavor_agent_docs_grounding_enabled', $docs_enabled );
		}
		$this->assertIsArray( $grounded_review );
		$this->assertTrue( $grounded_review['docsGrounding']['available'] );
		$apply_input = $input;
		unset( $apply_input['resolveSignatureOnly'] );
		$apply_input['operations'] = [
			[
				'type'       => 'set_styles',
				'path'       => [ 'color', 'text' ],
				'value'      => 'var:preset|color|accent',
				'valueType'  => 'preset',
				'presetType' => 'color',
				'presetSlug' => 'accent',
			],
		];
		$apply_input['signatures'] = [
			'resolvedContextSignature' => $grounded_review['resolvedContextSignature'],
			'reviewContextSignature'   => $grounded_review['reviewContextSignature'],
		];

		$stale = ApplyAbilities::request_style_apply( $apply_input );
		$this->assertInstanceOf( \WP_Error::class, $stale );
		$this->assertSame( 'flavor_agent_apply_stale', $stale->get_error_code() );
		$fresh_review = StyleAbilities::recommend_style( $input );
		$this->assertIsArray( $fresh_review );
		$this->assertFalse( $fresh_review['docsGrounding']['available'] );
		$this->assertNotSame( $grounded_review['docsGroundingFingerprint'], $fresh_review['docsGroundingFingerprint'] );
		$apply_input['signatures'] = [
			'resolvedContextSignature' => $fresh_review['resolvedContextSignature'],
			'reviewContextSignature'   => $fresh_review['reviewContextSignature'],
		];
		$pending                   = ApplyAbilities::request_style_apply( $apply_input );
		$this->assertIsArray( $pending );
		$this->assertSame( 'pending', $pending['status'] );
		$applied = PendingApplyDecision::decide( (string) $pending['activityId'], 'approve', 'Reviewed without external docs.' );
		$this->assertIsArray( $applied );
		$this->assertSame( 'applied', $applied['executionResult'] );
		$written = json_decode( WordPressTestState::$posts[17]->post_content, true );
		$this->assertSame( 'var:preset|color|accent', $written['styles']['color']['text'] );
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
	}

	private function prime_docs_cache( string $query ): void {
		$enabled = static fn(): bool => true;
		add_filter( 'flavor_agent_docs_grounding_enabled', $enabled );
		try {
			$cache_key = new ReflectionMethod( AISearchClient::class, 'build_cache_key' );
			$cache_key->setAccessible( true );
			WordPressTestState::$transients[ $cache_key->invoke( null, $query, 4 ) ] = [
				[
					'id'          => 'external-docs',
					'title'       => 'Paragraph guidance',
					'sourceKey'   => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph',
					'sourceType'  => 'developer-docs',
					'url'         => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph/',
					'excerpt'     => 'Cached external paragraph advice must stay excluded.',
					'contentHash' => 'external-docs-hash',
				],
			];
		} finally {
			remove_filter( 'flavor_agent_docs_grounding_enabled', $enabled );
		}
	}

	private function configure_text_generation_connector(): void {
		WordPressTestState::$options[ Provider::OPTION_NAME ] = 'openai';
		WordPressTestState::$connectors                       = [
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
		WordPressTestState::$ai_client_supported              = true;
		WordPressTestState::$ai_client_provider_support       = [ 'openai' => true ];
	}
}
