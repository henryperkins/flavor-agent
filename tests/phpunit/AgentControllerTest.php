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
		$this->disable_public_docs_grounding();
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

		WordPressTestState::$global_settings            = [
			'color' => [
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#f00',
					],
				],
			],
		];
		WordPressTestState::$connectors                 = [
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
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME                => 'openai',
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'chat-deployment',
		];
		WordPressTestState::$current_user_id            = 7;
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [
			'openai' => true,
		];
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

	public function test_handle_get_activity_grouped_by_surface_keeps_executable_history_when_diagnostics_are_newer(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		ActivityRepository::create( $this->build_activity_entry( 'activity-template' ) );

		for ( $index = 1; $index <= 25; ++$index ) {
			$pattern_entry               = $this->build_activity_entry( 'activity-pattern-' . $index );
			$pattern_entry['type']       = 'request_diagnostic';
			$pattern_entry['surface']    = 'pattern';
			$pattern_entry['target']     = [
				'requestRef' => 'pattern:' . $index,
			];
			$pattern_entry['suggestion'] = 'Pattern diagnostic ' . $index;
			$pattern_entry['timestamp']  = sprintf( '2026-03-24T10:%02d:00Z', $index );
			$pattern_entry['undo']       = [
				'canUndo' => false,
				'status'  => 'review',
			];

			ActivityRepository::create( $pattern_entry );
		}

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'wp_template:theme//home' );
		$request->set_param( 'groupBySurface', true );
		$request->set_param( 'surfaceLimit', 20 );

		$response = Agent_Controller::handle_get_activity( $request );
		$entries  = $response instanceof \WP_REST_Response
			? ( $response->get_data()['entries'] ?? [] )
			: [];

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertContains( 'activity-template', array_column( $entries, 'id' ) );
		$this->assertCount(
			20,
			array_filter(
				$entries,
				static fn( array $entry ): bool => 'pattern' === ( $entry['surface'] ?? '' )
			)
		);
		$this->assertNotContains( 'activity-pattern-1', array_column( $entries, 'id' ) );
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
				'blocked' => 0,
				'failed'  => 0,
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

	public function test_handle_get_activity_rejects_malformed_global_admin_date_filters(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['manage_options'] = true;

		$cases = [
			[
				'dayOperator' => 'between',
			],
			[
				'dayOperator' => 'between',
				'day'         => '2026-03-01',
			],
			[
				'dayOperator' => 'between',
				'day'         => '2026-03-31',
				'dayEnd'      => '2026-03-01',
			],
			[
				'dayOperator' => 'banana',
				'day'         => '2026-03-01',
			],
			[
				'dayOperator' => 'on',
				'day'         => 'not-a-date',
			],
			[
				'dayOperator'      => 'over',
				'dayRelativeValue' => 0,
				'dayRelativeUnit'  => 'days',
			],
			[
				'dayOperator'      => 'inThePast',
				'dayRelativeValue' => 7,
				'dayRelativeUnit'  => 'fortnights',
			],
		];

		foreach ( $cases as $params ) {
			$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
			$request->set_param( 'global', true );

			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}

			$response = Agent_Controller::handle_get_activity( $request );

			$this->assertInstanceOf( \WP_Error::class, $response );
			$this->assertSame( 'flavor_agent_activity_invalid_date_filter', $response->get_error_code() );
			$this->assertSame( 400, $response->get_error_data()['status'] ?? null );
		}
	}

	public function test_handle_get_activity_accepts_valid_global_admin_date_filters(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['manage_options'] = true;

		ActivityRepository::create( $this->build_activity_entry( 'activity-1' ) );

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'global', true );
		$request->set_param( 'dayOperator', 'between' );
		$request->set_param( 'day', '2026-03-01' );
		$request->set_param( 'dayEnd', '2026-03-31' );

		$response = Agent_Controller::handle_get_activity( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'entries', $response->get_data() );
		$this->assertArrayHasKey( 'paginationInfo', $response->get_data() );
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

	private function disable_public_docs_grounding(): void {
		\add_filter(
			'flavor_agent_cloudflare_ai_search_public_search_url',
			static fn(): string => ''
		);
	}





	private function configure_pattern_recommendation_backends(): void {
		WordPressTestState::$options             = [
			Provider::OPTION_NAME                        => Provider::NATIVE,
			'flavor_agent_openai_native_api_key'         => 'native-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_qdrant_url'                    => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                    => 'qdrant-key',
		];
		WordPressTestState::$ai_client_supported = true;
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
					'status'                 => 'ready',
					'fingerprint'            => PatternIndex::compute_fingerprint( $patterns ),
					'qdrant_url'             => (string) get_option( 'flavor_agent_qdrant_url', '' ),
					'qdrant_collection'      => QdrantClient::get_collection_name( $embedding_signature ),
					'openai_provider'        => $embedding_config['provider'],
					'openai_endpoint'        => $embedding_config['endpoint'],
					'embedding_model'        => $embedding_config['model'],
					'embedding_dimension'    => 2,
					'embedding_signature'    => $embedding_signature['signature_hash'],
					'last_synced_at'         => '2026-03-24T00:00:00+00:00',
					'last_attempt_at'        => '2026-03-24T00:00:00+00:00',
					'indexed_count'          => count( $patterns ),
					'last_error'             => null,
					'last_error_code'        => '',
					'last_error_status'      => 0,
					'last_error_retryable'   => false,
					'last_error_retry_after' => null,
					'pattern_fingerprints'   => $pattern_fingerprints,
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
