<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\REST\Agent_Controller;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AgentControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->register_paragraph_block();
		$this->register_pattern(
			'theme/hero',
			[
				'title'         => 'Hero',
				'templateTypes' => [ 'home' ],
				'content'       => '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/footer-callout',
			[
				'title'         => 'Footer Callout',
				'templateTypes' => [ 'home' ],
				'content'       => '<!-- wp:paragraph --><p>Footer Callout</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/header-utility',
			[
				'title'      => 'Header Utility',
				'categories' => [ 'header' ],
				'blockTypes' => [ 'core/template-part/header' ],
				'content'    => '<!-- wp:paragraph --><p>Header Utility</p><!-- /wp:paragraph -->',
			]
		);
		WordPressTestState::$block_templates = [
			'wp_template'      => [
				(object) [
					'id'      => 'theme//home',
					'slug'    => 'home',
					'title'   => 'Home',
					'content' => '<!-- wp:group --><div>Home</div><!-- /wp:group -->',
				],
			],
			'wp_template_part' => [
				(object) [
					'id'      => 'theme//header',
					'slug'    => 'header',
					'title'   => 'Header',
					'area'    => 'header',
					'content' => '<!-- wp:group {"tagName":"header"} --><!-- wp:site-logo /--><!-- wp:navigation /--><!-- /wp:group -->',
				],
			],
		];
		$this->stub_successful_llm_response();

		WordPressTestState::$global_settings     = [
			'color' => [
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#f00',
					],
				],
			],
		];
		WordPressTestState::$options             = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'chat-deployment',
		];
		WordPressTestState::$current_user_id     = 7;
		WordPressTestState::$ai_client_supported = true;
	}

	public function test_handle_recommend_block_wraps_payload_with_client_id(): void {
		WordPressTestState::$options = [];
		WordPressTestState::$ai_client_generate_text_result = [
			'text'       => '{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}',
			'tokenUsage' => [
				'total'  => 52,
				'input'  => 21,
				'output' => 31,
			],
			'latencyMs'  => 187,
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-block' );
		$request->set_param( 'clientId', 'client-123' );
		$request->set_param( 'prompt', 'Make it sharper' );
		$request->set_param(
			'editorContext',
			[
				'block' => [
					'name'              => 'core/paragraph',
					'currentAttributes' => [
						'content'   => 'Hello world',
						'className' => 'is-style-outline',
					],
				],
			]
		);

		$response = Agent_Controller::handle_recommend_block( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'payload'  => [
					'settings'    => [],
					'styles'      => [],
					'block'       => [],
					'explanation' => 'Use the accent color.',
					'requestMeta' => [
						'selectedProvider'      => 'azure_openai',
						'selectedProviderLabel' => 'Azure OpenAI',
						'connectorId'           => 'wordpress_ai_client',
						'connectorLabel'        => 'WordPress AI Client',
						'connectorPluginSlug'   => '',
						'provider'              => 'wordpress_ai_client',
						'providerLabel'         => 'WordPress AI Client',
						'backendLabel'          => 'WordPress AI Client',
						'model'                 => 'provider-managed',
						'owner'                 => 'connectors',
						'ownerLabel'            => 'Settings > Connectors',
						'pathLabel'             => 'WordPress AI Client via Settings > Connectors',
						'credentialSource'      => 'provider_managed',
						'credentialSourceLabel' => 'Provider-managed',
						'tokenUsage'            => [
							'total'  => 52,
							'input'  => 21,
							'output' => 31,
						],
						'latencyMs'             => 187,
						'usedFallback'          => true,
						'ability'               => 'flavor-agent/recommend-block',
						'route'                 => 'POST /flavor-agent/v1/recommend-block',
					],
				],
				'clientId' => 'client-123',
			],
			$response->get_data()
		);
		$this->assertStringContainsString(
			'core/paragraph',
			WordPressTestState::$last_ai_client_prompt['text'] ?? ''
		);
		$this->assertStringContainsString(
			'WordPress Gutenberg block styling and configuration assistant.',
			WordPressTestState::$last_ai_client_prompt['system'] ?? ''
		);
	}

	public function test_handle_recommend_template_limits_patterns_to_live_template_editor_visibility(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [
								[
									'label'              => 'Add hero',
									'description'        => 'Use the hero pattern.',
									'operations'         => [
										[
											'type'        => 'insert_pattern',
											'patternName' => 'theme/hero',
											'placement'   => 'start',
										],
									],
									'templateParts'      => [],
									'patternSuggestions' => [ 'theme/hero' ],
								],
							],
							'explanation' => 'Use the currently visible template pattern set.',
						]
					),
				]
			),
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-template' );
		$request->set_param( 'templateRef', 'theme//home' );
		$request->set_param( 'templateType', 'home' );
		$request->set_param( 'prompt', 'Make the home template feel more editorial.' );
		$request->set_param( 'visiblePatternNames', [ 'theme/hero' ] );

		$response = Agent_Controller::handle_recommend_template( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[ 'theme/hero' ],
			$response->get_data()['suggestions'][0]['patternSuggestions'] ?? []
		);
		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);
		$this->assertIsArray( $request_body );
		$this->assertStringContainsString(
			'theme/hero',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'## Current Top-Level Template Structure',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'## Executable Pattern Insertion Anchors',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'theme/footer-callout',
			(string) ( $request_body['input'] ?? '' )
		);
	}

	public function test_handle_recommend_content_forwards_post_context(): void {
		ActivityRepository::install();
		WordPressTestState::$options                        = [];
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$capabilities['edit_posts']     = true;
		WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
			[
				'mode'    => 'critique',
				'title'   => 'Retail floors to agent workflows',
				'summary' => 'Lead with the progression and cut the generic opener.',
				'content' => "Retail floors.\nWordPress themes.\nCloud platforms.\nAgentic AI.",
				'notes'   => [ 'The progression is the story.' ],
				'issues'  => [
					[
						'original' => 'Technology is rapidly changing.',
						'problem'  => 'This reads like boilerplate.',
						'revision' => 'The tools changed. The instinct did not.',
					],
				],
			]
		);

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-content' );
		$request->set_param( 'mode', 'critique' );
		$request->set_param( 'prompt', 'Tighten the voice and call out the flat lines.' );
		$request->set_param( 'voiceProfile', 'Keep the humor dry.' );
		$request->set_param(
			'postContext',
			[
				'postType' => 'post',
				'title'    => 'Working draft',
				'content'  => 'Technology is rapidly changing.',
				'tags'     => [ 'ai', 'wordpress' ],
			]
		);
		$request->set_param(
			'document',
			[
				'scopeKey' => 'post:42',
				'postType' => 'post',
				'entityId' => '42',
			]
		);

		$response = Agent_Controller::handle_recommend_content( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'critique', $response->get_data()['mode'] ?? null );
		$this->assertSame(
			'The tools changed. The instinct did not.',
			$response->get_data()['issues'][0]['revision'] ?? null
		);
		$this->assertStringContainsString(
			'Henry Perkins\'s voice',
			WordPressTestState::$last_ai_client_prompt['system'] ?? ''
		);
		$this->assertStringContainsString(
			'Technology is rapidly changing.',
			WordPressTestState::$last_ai_client_prompt['text'] ?? ''
		);
		$this->assertStringContainsString(
			'Tighten the voice and call out the flat lines.',
			WordPressTestState::$last_ai_client_prompt['text'] ?? ''
		);
		$this->assertResponseRequestMeta(
			$response->get_data(),
			'flavor-agent/recommend-content',
			'POST /flavor-agent/v1/recommend-content'
		);
		$entries = ActivityRepository::query( [ 'scopeKey' => 'post:42' ] );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'request_diagnostic', $entries[0]['type'] ?? null );
		$this->assertSame( 'content', $entries[0]['surface'] ?? null );
		$this->assertSame( 'review', $entries[0]['executionResult'] ?? null );
	}

	public function test_handle_recommend_content_persists_failed_request_diagnostic_when_scoped(): void {
		ActivityRepository::install();
		WordPressTestState::$options                        = [];
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$capabilities['edit_posts']     = true;
		WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
			[
				'mode'    => 'critique',
				'title'   => 'Needs more specifics',
				'summary' => 'Ask for the source draft first.',
				'content' => '',
				'notes'   => [],
				'issues'  => [],
			]
		);

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-content' );
		$request->set_param( 'mode', 'critique' );
		$request->set_param( 'prompt', 'Critique this draft.' );
		$request->set_param(
			'document',
			[
				'scopeKey' => 'post:42',
				'postType' => 'post',
				'entityId' => '42',
			]
		);

		$response = Agent_Controller::handle_recommend_content( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'missing_existing_content', $response->get_error_code() );

		$entries = ActivityRepository::query( [ 'scopeKey' => 'post:42' ] );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'request_diagnostic', $entries[0]['type'] ?? null );
		$this->assertSame( 'content', $entries[0]['surface'] ?? null );
		$this->assertSame( 'failed', $entries[0]['undo']['status'] ?? null );
		$this->assertSame(
			'Edit and critique modes require existing postContext.content.',
			$entries[0]['undo']['error'] ?? null
		);
	}

	public function test_handle_recommend_patterns_appends_matching_request_meta(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['edit_posts'] = true;
		$this->configure_pattern_recommendation_backends();
		$this->save_ready_pattern_index_state();

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

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-patterns' );
		$request->set_param( 'postType', 'page' );
		$request->set_param(
			'document',
			[
				'scopeKey' => 'post:42',
				'postType' => 'post',
				'entityId' => '42',
			]
		);

		$response = Agent_Controller::handle_recommend_patterns( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[ 'theme/hero' ],
			array_column( $response->get_data()['recommendations'] ?? [], 'name' )
		);
		$this->assertResponseRequestMeta(
			$response->get_data(),
			'flavor-agent/recommend-patterns',
			'POST /flavor-agent/v1/recommend-patterns'
		);
		$entries = ActivityRepository::query( [ 'scopeKey' => 'post:42' ] );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'request_diagnostic', $entries[0]['type'] ?? null );
		$this->assertSame( 'pattern', $entries[0]['surface'] ?? null );
		$this->assertSame( 'review', $entries[0]['executionResult'] ?? null );
	}

	public function test_handle_recommend_patterns_passes_insertion_context_through_to_ranking(): void {
		$this->configure_pattern_recommendation_backends();
		$this->save_ready_pattern_index_state();

		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/header-utility', 0.71 ),
				]
			),
			$this->qdrant_points_response(
				[
					$this->pattern_point( 'theme/header-utility', 0.79 ),
				]
			),
			$this->ranking_response(
				wp_json_encode(
					[
						'recommendations' => [
							[
								'name'   => 'theme/header-utility',
								'score'  => 0.84,
								'reason' => 'Fits the header structure.',
							],
						],
					]
				)
			),
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-patterns' );
		$request->set_param( 'postType', 'page' );
		$request->set_param(
			'insertionContext',
			[
				'rootBlock'        => 'core/group',
				'ancestors'        => [ 'core/template-part', 'core/group' ],
				'nearbySiblings'   => [ 'core/site-logo', 'core/navigation' ],
				'templatePartArea' => 'header',
				'templatePartSlug' => 'site-header',
				'containerLayout'  => 'flex',
			]
		);

		$response = Agent_Controller::handle_recommend_patterns( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$ranking_request = json_decode(
			(string) ( WordPressTestState::$remote_post_calls[3]['args']['body'] ?? '' ),
			true
		);

		$this->assertIsArray( $ranking_request );
		$this->assertStringContainsString(
			'Template-part area: header',
			(string) ( $ranking_request['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'Template-part slug: site-header',
			(string) ( $ranking_request['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'Container layout: flex',
			(string) ( $ranking_request['input'] ?? '' )
		);
	}

	public function test_handle_recommend_patterns_preserves_warming_error_for_retryable_bootstrap_failures(): void {
		$this->configure_pattern_recommendation_backends();

		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'               => 'error',
					'last_synced_at'       => null,
					'last_error'           => 'Rate limited.',
					'last_error_code'      => 'rate_limited',
					'last_error_status'    => 429,
					'last_error_retryable' => true,
					'last_error_retry_after' => 9,
				]
			)
		);

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-patterns' );
		$request->set_param( 'postType', 'page' );

		$response = Agent_Controller::handle_recommend_patterns( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'index_warming', $response->get_error_code() );
		$this->assertSame( 503, $response->get_error_data()['status'] ?? null );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
	}


	public function test_handle_recommend_template_visible_filter_applies_before_candidate_cap(): void {
		// Register 31 typed patterns so the 31st falls outside the
		// TEMPLATE_PATTERN_CANDIDATE_CAP of 30 when unfiltered.
		for ( $i = 1; $i <= 31; $i++ ) {
			$this->register_pattern(
				"theme/typed-pattern-{$i}",
				[
					'title'         => "Typed Pattern {$i}",
					'templateTypes' => [ 'home' ],
					'content'       => "<!-- wp:paragraph --><p>Pattern {$i}</p><!-- /wp:paragraph -->",
				]
			);
		}

		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [],
							'explanation' => 'ok',
						]
					),
				]
			),
		];

		// Request only the 31st pattern as visible — it would be excluded
		// by the old flow where the cap was applied before the filter.
		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-template' );
		$request->set_param( 'templateRef', 'theme//home' );
		$request->set_param( 'templateType', 'home' );
		$request->set_param( 'visiblePatternNames', [ 'theme/typed-pattern-31' ] );

		Agent_Controller::handle_recommend_template( $request );

		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);
		$this->assertIsArray( $request_body );
		$this->assertStringContainsString(
			'theme/typed-pattern-31',
			(string) ( $request_body['input'] ?? '' ),
			'Visible pattern beyond the unfiltered cap must reach the prompt.'
		);
	}

	public function test_handle_recommend_style_forwards_global_styles_context(): void {
		WordPressTestState::$capabilities['edit_theme_options'] = true;
		WordPressTestState::$remote_post_response               = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [
								[
									'label'       => 'Adopt Midnight variation',
									'description' => 'Use the darker preset-backed variation.',
									'category'    => 'variation',
									'tone'        => 'executable',
									'operations'  => [
										[
											'type' => 'set_theme_variation',
											'variationIndex' => 1,
											'variationTitle' => 'Midnight',
										],
									],
								],
							],
							'explanation' => 'The Midnight variation gives the site a stronger visual hierarchy.',
						]
					),
				]
			),
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-style' );
		$request->set_param(
			'scope',
			[
				'surface'        => 'global-styles',
				'scopeKey'       => 'global_styles:17',
				'globalStylesId' => '17',
				'postType'       => 'global_styles',
				'entityId'       => '17',
				'entityKind'     => 'root',
				'entityName'     => 'globalStyles',
			]
		);
		$request->set_param(
			'styleContext',
			[
				'currentConfig'         => [
					'styles' => [],
				],
				'mergedConfig'          => [
					'styles' => [],
				],
				'availableVariations'   => [
					[
						'title'    => 'Default',
						'settings' => [],
						'styles'   => [],
					],
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
			]
		);
		$request->set_param( 'prompt', 'Make the site feel more editorial.' );

		$response = Agent_Controller::handle_recommend_style( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			'Midnight',
			$response->get_data()['suggestions'][0]['operations'][0]['variationTitle'] ?? null
		);

		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);

		$this->assertIsArray( $request_body );
		$this->assertStringContainsString(
			'global_styles:17',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'## Available theme style variations',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertResponseRequestMeta(
			$response->get_data(),
			'flavor-agent/recommend-style',
			'POST /flavor-agent/v1/recommend-style'
		);
	}

	public function test_handle_recommend_template_preserves_explicit_empty_visible_pattern_filter(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [],
							'explanation' => 'ok',
						]
					),
				]
			),
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-template' );
		$request->set_param( 'templateRef', 'theme//home' );
		$request->set_param( 'templateType', 'home' );
		$request->set_param( 'visiblePatternNames', [] );

		Agent_Controller::handle_recommend_template( $request );

		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);
		$this->assertIsArray( $request_body );
		$this->assertStringNotContainsString(
			'theme/hero',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'theme/footer-callout',
			(string) ( $request_body['input'] ?? '' )
		);
	}

	public function test_handle_recommend_style_forwards_style_book_context(): void {
		WordPressTestState::$capabilities['edit_theme_options'] = true;
		WordPressTestState::$remote_post_response               = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [],
							'explanation' => 'Style Book context forwarded.',
						]
					),
				]
			),
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-style' );
		$request->set_param(
			'scope',
			[
				'surface'        => 'style-book',
				'scopeKey'       => 'style_book:17:core/paragraph',
				'globalStylesId' => '17',
				'blockName'      => 'core/paragraph',
				'blockTitle'     => 'Paragraph',
			]
		);
		$request->set_param(
			'styleContext',
			[
				'currentConfig'         => [
					'styles' => [],
				],
				'mergedConfig'          => [
					'styles' => [],
				],
				'availableVariations'   => [
					[
						'title'    => 'Default',
						'settings' => [],
						'styles'   => [],
					],
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
				'styleBookTarget'       => [
					'description' => 'Primary intro copy block.',
				],
			]
		);

		$response = Agent_Controller::handle_recommend_style( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

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
			'Primary intro copy block.',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'## Available theme style variations',
			(string) ( $request_body['input'] ?? '' )
		);
	}

	public function test_handle_recommend_template_prefers_live_editor_slots_over_saved_template_slots(): void {
		WordPressTestState::$block_templates['wp_template'][0]->content =
			'<!-- wp:template-part {"area":"header"} /-->';
		WordPressTestState::$remote_post_response                       = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [
								[
									'label'              => 'Add footer callout',
									'description'        => 'Use the footer callout pattern.',
									'operations'         => [
										[
											'type'        => 'insert_pattern',
											'patternName' => 'theme/footer-callout',
											'placement'   => 'start',
										],
									],
									'templateParts'      => [],
									'patternSuggestions' => [ 'theme/footer-callout' ],
								],
							],
							'explanation' => 'Use the broader template pattern set.',
						]
					),
				]
			),
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-template' );
		$request->set_param( 'templateRef', 'theme//home' );
		$request->set_param(
			'editorSlots',
			[
				'assignedParts' => [
					[
						'slug' => 'site-header',
						'area' => 'header',
					],
				],
				'emptyAreas'    => [],
				'allowedAreas'  => [ 'header' ],
			]
		);

		$response = Agent_Controller::handle_recommend_template( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);
		$this->assertIsArray( $request_body );
		$this->assertStringContainsString(
			'- `site-header` -> area: `header`',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'## Explicitly Empty Areas' . "\n" . 'None detected.',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertResponseRequestMeta(
			$response->get_data(),
			'flavor-agent/recommend-template',
			'POST /flavor-agent/v1/recommend-template'
		);
	}

	public function test_handle_recommend_template_prefers_live_editor_structure_over_saved_template_structure(): void {
		WordPressTestState::$block_templates['wp_template'][0]->content =
			'<!-- wp:paragraph --><p>Saved intro</p><!-- /wp:paragraph -->'
			. '<!-- wp:template-part {"slug":"header","area":"header"} /-->';
		WordPressTestState::$remote_post_response                       = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [
								[
									'label'              => 'Add hero',
									'description'        => 'Use the hero pattern at the start of the live template.',
									'operations'         => [
										[
											'type'        => 'insert_pattern',
											'patternName' => 'theme/hero',
											'placement'   => 'start',
										],
									],
									'templateParts'      => [],
									'patternSuggestions' => [ 'theme/hero' ],
								],
							],
							'explanation' => 'Use the live editor structure.',
						]
					),
				]
			),
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-template' );
		$request->set_param( 'templateRef', 'theme//home' );
		$request->set_param(
			'editorStructure',
			[
				'topLevelBlockTree' => [
					[
						'path'       => [ 0 ],
						'name'       => 'core/cover',
						'label'      => 'Hero wrapper',
						'attributes' => [
							'align' => 'full',
						],
						'childCount' => 1,
					],
					[
						'path'       => [ 1 ],
						'name'       => 'core/template-part',
						'label'      => 'site-header template part (header)',
						'attributes' => [
							'slug' => 'site-header',
							'area' => 'header',
						],
						'childCount' => 0,
						'slot'       => [
							'slug'    => 'site-header',
							'area'    => 'header',
							'isEmpty' => false,
						],
					],
				],
			]
		);

		$response = Agent_Controller::handle_recommend_template( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);
		$this->assertIsArray( $request_body );
		$this->assertStringContainsString(
			'- [0] core/cover {align=full} - Hero wrapper',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'- Before Hero wrapper (`before_block_path`) -> [0]',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'- [1] core/template-part {slug=site-header, area=header} - site-header template part (header)',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'- [0] core/paragraph',
			(string) ( $request_body['input'] ?? '' )
		);
	}

	public function test_handle_recommend_template_part_keeps_requests_part_scoped(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [
								[
									'label'              => 'Lighten the header frame',
									'description'        => 'Focus on the navigation row and browse a compact utility pattern.',
									'blockHints'         => [
										[
											'path'   => [ 0, 1 ],
											'label'  => 'Navigation block',
											'reason' => 'This is where the header feels heaviest.',
										],
									],
									'patternSuggestions' => [ 'theme/header-utility' ],
									'operations'         => [
										[
											'type'        => 'insert_pattern',
											'patternName' => 'theme/header-utility',
											'placement'   => 'start',
										],
									],
								],
							],
							'explanation' => 'The current wrapper is sound, so focus on the menu cluster.',
						]
					),
				]
			),
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-template-part' );
		$request->set_param( 'templatePartRef', 'theme//header' );
		$request->set_param( 'prompt', 'Make the header feel lighter.' );
		$request->set_param( 'visiblePatternNames', [ 'theme/header-utility' ] );

		$response = Agent_Controller::handle_recommend_template_part( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[ 'theme/header-utility' ],
			$response->get_data()['suggestions'][0]['patternSuggestions'] ?? []
		);
		$this->assertSame(
			[ 0, 1 ],
			$response->get_data()['suggestions'][0]['blockHints'][0]['path'] ?? []
		);
		$this->assertSame(
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'theme/header-utility',
					'placement'   => 'start',
				],
			],
			$response->get_data()['suggestions'][0]['operations'] ?? []
		);

		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);

		$this->assertIsArray( $request_body );
		$this->assertStringContainsString(
			'Area: header',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'[0, 1] core/navigation',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'## Executable Operation Targets',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'## Executable Insertion Anchors',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'theme/header-utility',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'theme/hero',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertResponseRequestMeta(
			$response->get_data(),
			'flavor-agent/recommend-template-part',
			'POST /flavor-agent/v1/recommend-template-part'
		);
	}

	public function test_handle_recommend_template_part_preserves_explicit_empty_visible_pattern_filter(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [],
							'explanation' => 'ok',
						]
					),
				]
			),
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-template-part' );
		$request->set_param( 'templatePartRef', 'theme//header' );
		$request->set_param( 'visiblePatternNames', [] );

		Agent_Controller::handle_recommend_template_part( $request );

		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);
		$this->assertIsArray( $request_body );
		$this->assertStringNotContainsString(
			'theme/header-utility',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'theme/hero',
			(string) ( $request_body['input'] ?? '' )
		);
	}

	public function test_handle_recommend_template_part_forwards_editor_structure_overrides(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [],
							'explanation' => 'ok',
						]
					),
				]
			),
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-template-part' );
		$request->set_param( 'templatePartRef', 'theme//header' );
		$request->set_param(
			'editorStructure',
			[
				'currentPatternOverrides' => [
					'hasOverrides' => true,
					'blockCount'   => 1,
					'blocks'       => [
						[
							'path'               => [ 0, 1 ],
							'name'               => 'core/navigation',
							'label'              => 'Navigation',
							'overrideAttributes' => [ 'overlayMenu' ],
						],
					],
				],
			]
		);

		Agent_Controller::handle_recommend_template_part( $request );

		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);

		$this->assertIsArray( $request_body );
		$this->assertStringContainsString(
			'## Current Pattern Override Blocks',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'Path 1 > 2 - `Navigation`',
			(string) ( $request_body['input'] ?? '' )
		);
	}

	public function test_handle_recommend_navigation_forwards_selected_navigation_context(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['edit_theme_options'] = true;
		WordPressTestState::$posts[42]                                       = (object) [
			'ID'           => 42,
			'post_type'    => 'wp_navigation',
			'post_content' => '<!-- wp:navigation-link {"label":"Saved Home","url":"/"} /-->',
		];
		WordPressTestState::$block_templates['wp_template_part'][0]->content =
			'<!-- wp:navigation {"ref":42} /-->';
		WordPressTestState::$remote_post_response                            = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => wp_json_encode(
						[
							'suggestions' => [
								[
									'label'       => 'Group utility links',
									'description' => 'Keep account and contact actions together.',
									'category'    => 'structure',
									'changes'     => [
										[
											'type'       => 'group',
											'targetPath' => [ 1 ],
											'target'     => 'Contact link',
											'detail'     => 'Move it into a single submenu to simplify the top level.',
										],
									],
								],
							],
							'explanation' => 'The top-level navigation is crowded.',
						]
					),
				]
			),
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/recommend-navigation' );
		$request->set_param( 'blockClientId', 'nav-1' );
		$request->set_param( 'menuId', 42 );
		$request->set_param(
			'navigationMarkup',
			'<!-- wp:navigation {"ref":42,"overlayMenu":"mobile"} --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- wp:navigation-link {"label":"Contact","url":"/contact"} /--><!-- /wp:navigation -->'
		);
		$request->set_param( 'prompt', 'Simplify the header navigation.' );
		$request->set_param(
			'document',
			[
				'scopeKey' => 'wp_template:theme//home',
				'postType' => 'wp_template',
				'entityId' => 'theme//home',
			]
		);

		$response = Agent_Controller::handle_recommend_navigation( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			'Group utility links',
			$response->get_data()['suggestions'][0]['label'] ?? ''
		);
		$this->assertSame(
			'The top-level navigation is crowded.',
			$response->get_data()['explanation'] ?? ''
		);

		$request_body = json_decode(
			(string) ( WordPressTestState::$last_remote_post['args']['body'] ?? '' ),
			true
		);
		$this->assertIsArray( $request_body );
		$this->assertStringContainsString(
			'Location: header',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'"Home"',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'"Contact"',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'## Structure Summary',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'## Current Menu Target Inventory',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'## Overlay Context',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringContainsString(
			'Simplify the header navigation.',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertResponseRequestMeta(
			$response->get_data(),
			'flavor-agent/recommend-navigation',
			'POST /flavor-agent/v1/recommend-navigation'
		);
		$entries = ActivityRepository::query( [ 'scopeKey' => 'wp_template:theme//home' ] );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'request_diagnostic', $entries[0]['type'] ?? null );
		$this->assertSame( 'navigation', $entries[0]['surface'] ?? null );
		$this->assertSame( 'nav-1', $entries[0]['target']['clientId'] ?? null );
	}

	public function test_handle_sync_patterns_appends_sync_route_metadata(): void {
		$this->configure_pattern_recommendation_backends();
		$this->save_ready_pattern_index_state();
		WordPressTestState::$remote_post_responses = [
			$this->embedding_response( [ 0.12, 0.34 ] ),
		];

		$request  = new \WP_REST_Request( 'POST', '/flavor-agent/v1/sync-patterns' );
		$response = Agent_Controller::handle_sync_patterns( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			'POST /flavor-agent/v1/sync-patterns',
			$response->get_data()['requestMeta']['route'] ?? null
		);
		$this->assertArrayNotHasKey(
			'ability',
			$response->get_data()['requestMeta'] ?? []
		);
	}

	public function test_handle_create_activity_persists_structured_entries(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity' );
		$request->set_param( 'entry', $this->build_activity_entry( 'activity-1' ) );

		$response = Agent_Controller::handle_create_activity( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'activity-1', $response->get_data()['entry']['id'] ?? null );
		$this->assertSame(
			'theme//home',
			$response->get_data()['entry']['target']['templateRef'] ?? null
		);
		$this->assertSame(
			'server',
			$response->get_data()['entry']['persistence']['status'] ?? null
		);
	}

	public function test_handle_get_activity_filters_by_scope_key(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		ActivityRepository::create( $this->build_activity_entry( 'activity-1' ) );
		ActivityRepository::create(
			array_merge(
				$this->build_activity_entry( 'activity-2' ),
				[
					'document' => [
						'scopeKey' => 'wp_template:theme//single',
						'postType' => 'wp_template',
						'entityId' => 'theme//single',
					],
					'target'   => [
						'templateRef' => 'theme//single',
					],
				]
			)
		);

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'wp_template:theme//home' );

		$response = Agent_Controller::handle_get_activity( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertCount( 1, $response->get_data()['entries'] ?? [] );
		$this->assertSame(
			'activity-1',
			$response->get_data()['entries'][0]['id'] ?? null
		);
	}

	public function test_handle_get_activity_supports_global_admin_queries(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['manage_options'] = true;

		ActivityRepository::create( $this->build_activity_entry( 'activity-1' ) );
		ActivityRepository::create(
			array_merge(
				$this->build_activity_entry( 'activity-2' ),
				[
					'document' => [
						'scopeKey' => 'post:42',
						'postType' => 'post',
						'entityId' => '42',
					],
					'surface'  => 'block',
					'target'   => [
						'clientId'  => 'block-1',
						'blockName' => 'core/paragraph',
						'blockPath' => [ 0 ],
					],
				]
			)
		);

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'global', true );
		$request->set_param( 'page', 1 );
		$request->set_param( 'perPage', 10 );

		$response = Agent_Controller::handle_get_activity( $request );
		$data     = $response->get_data();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data['entries'] ?? [] );
		$this->assertSame(
			'activity-2',
			$data['entries'][0]['id'] ?? null
		);
		$this->assertSame(
			'activity-1',
			$data['entries'][1]['id'] ?? null
		);
		$this->assertSame( 1, $data['paginationInfo']['page'] ?? null );
		$this->assertSame( 10, $data['paginationInfo']['perPage'] ?? null );
		$this->assertSame( 2, $data['paginationInfo']['totalItems'] ?? null );
		$this->assertSame( 1, $data['paginationInfo']['totalPages'] ?? null );
		$this->assertSame(
			[
				'total'   => 2,
				'applied' => 2,
				'undone'  => 0,
				'review'  => 0,
			],
			$data['summary'] ?? null
		);
		$this->assertSame(
			[
				[
					'value' => 'block',
					'label' => 'Block',
				],
				[
					'value' => 'template',
					'label' => 'Template',
				],
			],
			$data['filterOptions']['surface'] ?? null
		);
	}

	public function test_handle_get_activity_supports_global_admin_query_pagination(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['manage_options'] = true;

		$base_timestamp = strtotime( '2026-03-24T10:00:00Z' );

		for ( $i = 1; $i <= 25; $i++ ) {
			ActivityRepository::create(
				$this->build_block_activity_entry(
					"activity-{$i}",
					gmdate( 'Y-m-d\TH:i:s\Z', $base_timestamp + ( ( $i - 1 ) * 60 ) )
				)
			);
		}

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'global', true );
		$request->set_param( 'page', 3 );
		$request->set_param( 'perPage', 10 );

		$response = Agent_Controller::handle_get_activity( $request );
		$data     = $response->get_data();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 3, $data['paginationInfo']['page'] ?? null );
		$this->assertSame( 10, $data['paginationInfo']['perPage'] ?? null );
		$this->assertSame( 25, $data['paginationInfo']['totalItems'] ?? null );
		$this->assertSame( 3, $data['paginationInfo']['totalPages'] ?? null );
		$this->assertCount( 5, $data['entries'] ?? [] );
		$this->assertSame( 'activity-5', $data['entries'][0]['id'] ?? null );
		$this->assertSame( 'activity-1', $data['entries'][4]['id'] ?? null );
	}

	public function test_handle_update_activity_undo_persists_status_changes(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		ActivityRepository::create( $this->build_activity_entry( 'activity-1' ) );

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity/activity-1/undo' );
		$request->set_param( 'id', 'activity-1' );
		$request->set_param( 'status', 'undone' );

		$response = Agent_Controller::handle_update_activity_undo( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			'undone',
			$response->get_data()['entry']['undo']['status'] ?? null
		);
	}

	private function register_paragraph_block(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'core/paragraph',
			[
				'title'      => 'Paragraph',
				'attributes' => [
					'content'   => [
						'type' => 'string',
						'role' => 'content',
					],
					'className' => [
						'type' => 'string',
					],
				],
				'styles'     => [
					[
						'name'      => 'outline',
						'label'     => 'Outline',
						'isDefault' => false,
					],
				],
			]
		);
	}

	private function register_pattern( string $name, array $properties ): void {
		\WP_Block_Patterns_Registry::get_instance()->register(
			$name,
			$properties
		);
	}

	/**
	 * @param array<string, mixed> $response_data
	 */
	private function assertResponseRequestMeta( array $response_data, string $ability, string $route ): void {
		$this->assertSame( $ability, $response_data['requestMeta']['ability'] ?? null );
		$this->assertSame( $route, $response_data['requestMeta']['route'] ?? null );
	}

	private function configure_pattern_recommendation_backends(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                        => Provider::NATIVE,
			'flavor_agent_openai_native_api_key'        => 'native-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_openai_native_chat_model'     => 'gpt-5.4',
			'flavor_agent_qdrant_url'                   => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                   => 'qdrant-key',
		];
	}

	private function save_ready_pattern_index_state(): void {
		$patterns             = ServerCollector::for_patterns();
		$embedding_config     = Provider::embedding_configuration();
		$embedding_signature  = EmbeddingClient::build_signature_for_dimension( 2, $embedding_config );
		$pattern_fingerprints = [];
		$normalize_list       = static function ( array $values ): string {
			$values = array_values(
				array_filter(
					array_map( 'strval', $values ),
					static fn( string $value ): bool => $value !== ''
				)
			);
			sort( $values );

			return implode( ',', $values );
		};

		foreach ( $patterns as $pattern ) {
			$pattern_fingerprints[ PatternIndex::pattern_uuid( (string) $pattern['name'] ) ] =
				md5(
					implode(
						'|',
						[
							(string) ( $pattern['name'] ?? '' ),
							(string) ( $pattern['title'] ?? '' ),
							(string) ( $pattern['description'] ?? '' ),
							$normalize_list( (array) ( $pattern['categories'] ?? [] ) ),
							$normalize_list( (array) ( $pattern['blockTypes'] ?? [] ) ),
							$normalize_list( (array) ( $pattern['templateTypes'] ?? [] ) ),
							'0|0|0|',
							md5( (string) ( $pattern['content'] ?? '' ) ),
							(string) PatternIndex::EMBEDDING_RECIPE_VERSION,
						]
					)
				);
		}

		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'              => 'ready',
					'fingerprint'         => PatternIndex::compute_fingerprint( $patterns ),
					'qdrant_url'          => (string) get_option( 'flavor_agent_qdrant_url', '' ),
					'qdrant_collection'   => QdrantClient::get_collection_name( $embedding_signature ),
					'openai_provider'     => $embedding_config['provider'],
					'openai_endpoint'     => $embedding_config['endpoint'],
					'embedding_model'     => $embedding_config['model'],
					'embedding_dimension' => 2,
					'embedding_signature' => $embedding_signature['signature_hash'],
					'last_synced_at'      => '2026-03-24T00:00:00+00:00',
					'last_attempt_at'     => '2026-03-24T00:00:00+00:00',
					'indexed_count'        => count( $patterns ),
					'last_error'           => null,
					'last_error_code'      => '',
					'last_error_status'    => 0,
					'last_error_retryable' => false,
					'last_error_retry_after' => null,
					'pattern_fingerprints' => $pattern_fingerprints,
				]
			)
		);

		if ( [] === WordPressTestState::$remote_get_responses ) {
			WordPressTestState::$remote_get_response = $this->qdrant_collection_response( 2 );
		}
	}

	private function stub_successful_llm_response(): void {
		WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Use the accent color.',
			]
		);
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
				],
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
	private function pattern_point( string $name, float $score ): array {
		return [
			'score'   => $score,
			'payload' => [
				'name'          => $name,
				'title'         => ucwords( str_replace( [ 'theme/', '-' ], [ '', ' ' ], $name ) ),
				'description'   => "Description for {$name}",
				'categories'    => [ 'marketing' ],
				'blockTypes'    => [ 'core/template-part/header' ],
				'templateTypes' => [ 'home' ],
				'content'       => "<!-- wp:paragraph --><p>{$name}</p><!-- /wp:paragraph -->",
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_activity_entry( string $id ): array {
		return [
			'id'         => $id,
			'type'       => 'apply_template_suggestion',
			'surface'    => 'template',
			'target'     => [
				'templateRef' => 'theme//home',
			],
			'suggestion' => 'Clarify hierarchy',
			'before'     => [
				'operations' => [],
			],
			'after'      => [
				'operations' => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'theme/hero',
					],
				],
			],
			'request'    => [
				'prompt'    => 'Make the home template feel more editorial.',
				'reference' => 'template:theme//home:3',
			],
			'document'   => [
				'scopeKey' => 'wp_template:theme//home',
				'postType' => 'wp_template',
				'entityId' => 'theme//home',
			],
			'timestamp'  => '2026-03-24T10:00:00Z',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_block_activity_entry( string $id, string $timestamp, string $entity_id = '42' ): array {
		return [
			'id'         => $id,
			'type'       => 'apply_block_suggestion',
			'surface'    => 'block',
			'target'     => [
				'clientId'  => 'block-1',
				'blockName' => 'core/paragraph',
				'blockPath' => [ 0 ],
			],
			'suggestion' => 'Rewrite the introduction.',
			'before'     => [
				'attributes' => [
					'content' => 'Before',
				],
			],
			'after'      => [
				'attributes' => [
					'content' => 'After',
				],
			],
			'request'    => [
				'prompt'    => 'Make the introduction clearer.',
				'reference' => "post:{$entity_id}:0",
			],
			'document'   => [
				'scopeKey' => "post:{$entity_id}",
				'postType' => 'post',
				'entityId' => $entity_id,
			],
			'timestamp'  => $timestamp,
		];
	}
}
