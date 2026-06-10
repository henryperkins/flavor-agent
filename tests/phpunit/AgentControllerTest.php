<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Embeddings\EmbeddingClient;
use FlavorAgent\Embeddings\QdrantClient;
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
			Provider::OPTION_NAME => 'openai',
		];
		WordPressTestState::$current_user_id            = 7;
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [
			'openai' => true,
		];
	}






































	public function test_handle_sync_patterns_queues_sync_without_remote_work(): void {
		$this->configure_pattern_recommendation_backends();

		$request  = new \WP_REST_Request( 'POST', '/flavor-agent/v1/sync-patterns' );
		$response = Agent_Controller::handle_sync_patterns( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['queued'] ?? null );
		$this->assertTrue( $response->get_data()['scheduled'] ?? null );
		$this->assertNotEmpty( $response->get_data()['scheduledAt'] ?? '' );
		$this->assertSame( 'indexing', $response->get_data()['status'] ?? null );
		$this->assertSame( 'indexing', $response->get_data()['runtimeState']['status'] ?? null );
		$this->assertSame(
			'POST /flavor-agent/v1/sync-patterns',
			$response->get_data()['requestMeta']['route'] ?? null
		);
		$this->assertArrayNotHasKey(
			'ability',
			$response->get_data()['requestMeta'] ?? []
		);
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertSame(
			gmdate( 'c', WordPressTestState::$scheduled_events[ PatternIndex::CRON_HOOK ]['timestamp'] ),
			$response->get_data()['scheduledAt'] ?? null
		);
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
	}

	public function test_handle_get_sync_patterns_runs_due_sync_before_returning_runtime_state(): void {
		$this->configure_pattern_recommendation_backends();

		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'         => 'indexing',
					'last_synced_at' => null,
				]
			)
		);
		WordPressTestState::$scheduled_events[ PatternIndex::CRON_HOOK ] = [
			'hook'      => PatternIndex::CRON_HOOK,
			'timestamp' => time() - 1,
			'args'      => [],
		];

		$this->queue_signature_probe();
		$this->queue_ensure_collection( false );
		$this->queue_scroll( [] );
		$this->queue_embeddings(
			[
				[ 0.11, 0.22 ],
				[ 0.33, 0.44 ],
				[ 0.55, 0.66 ],
			]
		);
		$this->queue_qdrant_success( '/points', 'PUT' );

		$request  = new \WP_REST_Request( 'GET', '/flavor-agent/v1/sync-patterns' );
		$response = Agent_Controller::handle_get_sync_patterns( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ready', $response->get_data()['runtimeState']['status'] ?? null );
		$this->assertArrayNotHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertNotEmpty( WordPressTestState::$remote_get_calls );
		$this->assertNotEmpty( WordPressTestState::$remote_post_calls );
	}

	public function test_handle_get_sync_patterns_returns_runtime_state_without_enqueuing(): void {
		$this->configure_pattern_recommendation_backends();
		$this->save_ready_pattern_index_state();

		$request  = new \WP_REST_Request( 'GET', '/flavor-agent/v1/sync-patterns' );
		$response = Agent_Controller::handle_get_sync_patterns( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ready', $response->get_data()['runtimeState']['status'] ?? null );
		$this->assertSame(
			'GET /flavor-agent/v1/sync-patterns',
			$response->get_data()['requestMeta']['route'] ?? null
		);
		$this->assertArrayNotHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
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

	public function test_handle_get_activity_includes_scoped_outcome_diagnostics_only_when_requested(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		ActivityRepository::create( $this->build_activity_entry( 'activity-1' ) );
		ActivityRepository::create(
			[
				'id'         => 'outcome-1',
				'type'       => 'recommendation_outcome',
				'surface'    => 'pattern',
				'target'     => [
					'recommendationSetId' => 'set-1',
					'patternKey'          => 'theme/hero',
				],
				'suggestion' => 'Recommendations shown',
				'after'      => [
					'outcome' => [
						'event'               => 'shown',
						'recommendationSetId' => 'set-1',
						'visibility'          => 'diagnostic',
					],
				],
				'document'   => [
					'scopeKey' => 'wp_template:theme//home',
					'postType' => 'wp_template',
					'entityId' => 'theme//home',
				],
				'timestamp'  => '2026-03-24T10:00:01Z',
			]
		);

		$default_request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$default_request->set_param( 'scopeKey', 'wp_template:theme//home' );

		$default_response = Agent_Controller::handle_get_activity( $default_request );
		$default_entries  = $default_response instanceof \WP_REST_Response
			? ( $default_response->get_data()['entries'] ?? [] )
			: [];

		$this->assertContains( 'activity-1', array_column( $default_entries, 'id' ) );
		$this->assertNotContains( 'outcome-1', array_column( $default_entries, 'id' ) );

		$diagnostic_request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$diagnostic_request->set_param( 'scopeKey', 'wp_template:theme//home' );
		$diagnostic_request->set_param( 'includeDiagnostics', true );

		$diagnostic_response = Agent_Controller::handle_get_activity( $diagnostic_request );
		$diagnostic_entries  = $diagnostic_response instanceof \WP_REST_Response
			? ( $diagnostic_response->get_data()['entries'] ?? [] )
			: [];

		$this->assertContains( 'activity-1', array_column( $diagnostic_entries, 'id' ) );
		$this->assertContains( 'outcome-1', array_column( $diagnostic_entries, 'id' ) );
	}

	public function test_handle_get_activity_grouped_by_surface_keeps_executable_history_when_diagnostics_are_newer(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		ActivityRepository::create( $this->build_activity_entry( 'activity-template' ) );

		$base_timestamp = strtotime( '2026-03-24T10:01:00Z' );

		for ( $index = 1; $index <= 181; ++$index ) {
			$pattern_entry               = $this->build_activity_entry( 'activity-pattern-' . $index );
			$pattern_entry['type']       = 'request_diagnostic';
			$pattern_entry['surface']    = 'pattern';
			$pattern_entry['target']     = [
				'requestRef' => 'pattern:' . $index,
			];
			$pattern_entry['suggestion'] = 'Pattern diagnostic ' . $index;
			$pattern_entry['timestamp']  = gmdate(
				'Y-m-d\TH:i:s\Z',
				$base_timestamp + $index
			);
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
		$this->assertContains( 'activity-pattern-181', array_column( $entries, 'id' ) );
		$this->assertNotContains( 'activity-pattern-1', array_column( $entries, 'id' ) );
	}

	public function test_handle_get_activity_grouped_by_surface_honors_include_diagnostics(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		ActivityRepository::create( $this->build_activity_entry( 'activity-template' ) );
		ActivityRepository::create(
			[
				'id'         => 'outcome-pattern',
				'type'       => 'recommendation_outcome',
				'surface'    => 'pattern',
				'target'     => [
					'recommendationSetId' => 'set-pattern',
					'patternKey'          => 'theme/hero',
				],
				'suggestion' => 'Recommendations shown',
				'after'      => [
					'outcome' => [
						'event'               => 'shown',
						'recommendationSetId' => 'set-pattern',
						'visibility'          => 'diagnostic',
					],
				],
				'document'   => [
					'scopeKey' => 'wp_template:theme//home',
					'postType' => 'wp_template',
					'entityId' => 'theme//home',
				],
				'timestamp'  => '2026-03-24T10:00:01Z',
			]
		);

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'wp_template:theme//home' );
		$request->set_param( 'groupBySurface', true );
		$request->set_param( 'surfaceLimit', 20 );
		$request->set_param( 'includeDiagnostics', true );

		$response = Agent_Controller::handle_get_activity( $request );
		$entries  = $response instanceof \WP_REST_Response
			? ( $response->get_data()['entries'] ?? [] )
			: [];

		$this->assertContains( 'activity-template', array_column( $entries, 'id' ) );
		$this->assertContains( 'outcome-pattern', array_column( $entries, 'id' ) );
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
				'total'    => 2,
				'applied'  => 2,
				'undone'   => 0,
				'review'   => 0,
				'blocked'  => 0,
				'failed'   => 0,
				'pending'  => 0,
				'rejected' => 0,
				'expired'  => 0,
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

	private function configure_pattern_recommendation_backends(): void {
		WordPressTestState::$options             = [
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			'flavor_agent_qdrant_url'                      => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                      => 'qdrant-key',
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

	private function queue_signature_probe(): void {
		$this->queue_embeddings(
			[
				[ 0.01, 0.02 ],
			]
		);
	}

	private function queue_ensure_collection( bool $collection_exists = true ): void {
		WordPressTestState::$remote_get_responses[] = $collection_exists
			? $this->qdrant_collection_response( 2 )
			: $this->qdrant_response(
				404,
				[
					'status' => [
						'error' => 'Collection not found',
					],
				]
			);

		if ( ! $collection_exists ) {
			WordPressTestState::$remote_post_responses[] = $this->qdrant_response(
				200,
				[
					'status' => 'ok',
					'result' => [],
				]
			);
		}

		for ( $i = 0; $i < 5; $i++ ) {
			$this->queue_qdrant_success( '/index', 'PUT' );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $points
	 */
	private function queue_scroll( array $points ): void {
		WordPressTestState::$remote_post_responses[] = $this->qdrant_response(
			200,
			[
				'status' => 'ok',
				'result' => [
					'points'           => $points,
					'next_page_offset' => null,
				],
			]
		);
	}

	/**
	 * @param array<int, array<int, float>> $vectors
	 */
	private function queue_embeddings( array $vectors ): void {
		WordPressTestState::$remote_post_responses[] = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'data' => array_map(
						static fn( array $vector ): array => [
							'embedding' => $vector,
						],
						$vectors
					),
				]
			),
		];
	}

	private function queue_qdrant_success( string $url_suffix, string $method ): void {
		unset( $url_suffix, $method );

		WordPressTestState::$remote_post_responses[] = $this->qdrant_response(
			200,
			[
				'status' => 'ok',
				'result' => [],
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function qdrant_response( int $status, array $body ): array {
		return [
			'response' => [ 'code' => $status ],
			'body'     => wp_json_encode( $body ),
		];
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
