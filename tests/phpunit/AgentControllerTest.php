<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

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
		WordPressTestState::$ai_client_supported = true;
	}

	public function test_handle_recommend_block_wraps_payload_with_client_id(): void {
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
		$this->assertStringNotContainsString(
			'theme/footer-callout',
			(string) ( $request_body['input'] ?? '' )
		);
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

	public function test_handle_recommend_template_prefers_live_editor_slots_over_saved_template_slots(): void {
		WordPressTestState::$block_templates['wp_template'][0]->content =
			'<!-- wp:template-part {"area":"header"} /-->';
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
									'label'              => 'Add footer callout',
									'description'        => 'Use the footer callout pattern.',
									'operations'         => [
										[
											'type'        => 'insert_pattern',
											'patternName' => 'theme/footer-callout',
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
			'theme/header-utility',
			(string) ( $request_body['input'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'theme/hero',
			(string) ( $request_body['input'] ?? '' )
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
}
