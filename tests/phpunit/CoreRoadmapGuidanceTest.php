<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\CoreRoadmapGuidance;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class CoreRoadmapGuidanceTest extends TestCase {

	private $roadmap_filter;

	private array $filters = [];

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->roadmap_filter = static fn(): bool => true;
		\add_filter( 'flavor_agent_enable_core_roadmap_guidance', $this->roadmap_filter );
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			\remove_filter( $filter['hook'], $filter['callback'], $filter['priority'] );
		}

		\remove_filter( 'flavor_agent_enable_core_roadmap_guidance', $this->roadmap_filter );

		parent::tearDown();
	}

	public function test_warm_parses_open_milestones_and_items_from_memex_payload(): void {
		WordPressTestState::$remote_get_response = [
			'response' => [ 'code' => 200 ],
			'body'     => $this->build_roadmap_page_fixture(),
		];

		$result = CoreRoadmapGuidance::warm();

		$this->assertSame( 2, count( $result ) );
		$this->assertSame( 'WordPress AI roadmap status', $result[0]['title'] );
		$this->assertSame( 'core-roadmap', $result[0]['sourceType'] );
		$this->assertStringContainsString( 'Open roadmap milestones: Core Improvements', $result[0]['excerpt'] );
		$this->assertSame( 'Enable richer editor controls', $result[1]['title'] );
		$this->assertSame( 'core-roadmap', $result[1]['sourceType'] );
		$this->assertStringContainsString( 'In progress', $result[1]['excerpt'] );
		$this->assertStringContainsString( 'Priority: High', $result[1]['excerpt'] );
		$this->assertSame( 'https://github.com/orgs/WordPress/projects/240/views/7?layout=table&hierarchy=true', WordPressTestState::$last_remote_get['url'] );
		$this->assertSame( 12, WordPressTestState::$last_remote_get['args']['timeout'] ?? null );

		$repeat = CoreRoadmapGuidance::collect();
		$this->assertSame( $result, $repeat );
		$this->assertCount( 1, WordPressTestState::$remote_get_calls );
	}

	public function test_collect_is_cache_only_and_schedules_warm_on_miss(): void {
		$result = CoreRoadmapGuidance::collect();

		$this->assertSame( [], $result );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertArrayHasKey(
			CoreRoadmapGuidance::WARM_CRON_HOOK,
			WordPressTestState::$scheduled_events
		);
	}

	public function test_warm_applies_timeout_and_cache_ttl_filters(): void {
		$context = [ 'surface' => 'style' ];

		$timeout_filter = static function ( int $timeout, array $filter_context ) use ( $context ): int {
			return $context === $filter_context ? 3 : $timeout;
		};
		$ttl_filter     = static function ( int $ttl, array $filter_context ) use ( $context ): int {
			return $context === $filter_context ? 120 : $ttl;
		};

		$this->add_test_filter( 'flavor_agent_core_roadmap_guidance_request_timeout', $timeout_filter, 10, 2 );
		$this->add_test_filter( 'flavor_agent_core_roadmap_guidance_cache_ttl', $ttl_filter, 10, 2 );

		WordPressTestState::$remote_get_response = [
			'response' => [ 'code' => 200 ],
			'body'     => $this->build_roadmap_page_fixture(),
		];

		CoreRoadmapGuidance::warm( $context );

		$this->assertSame( 3, WordPressTestState::$last_remote_get['args']['timeout'] ?? null );
		$this->assertSame( 120, WordPressTestState::$transient_expirations['flavor_agent_core_roadmap_guidance_v1'] ?? null );
	}

	public function test_collect_can_be_disabled_by_context_flag(): void {
		$result = CoreRoadmapGuidance::collect( [ 'skipCoreRoadmapGuidance' => true ] );

		$this->assertSame( [], $result );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
	}

	public function test_schedule_warm_skips_when_empty_result_is_cached(): void {
		WordPressTestState::$transients['flavor_agent_core_roadmap_guidance_v1'] = [];

		CoreRoadmapGuidance::schedule_warm();

		$this->assertSame( [], WordPressTestState::$scheduled_events );
	}

	public function test_warm_caches_empty_failures_with_short_ttl(): void {
		$context    = [ 'surface' => 'block' ];
		$ttl_filter = static function ( int $ttl, array $filter_context ) use ( $context ): int {
			return $context === $filter_context ? 90 : $ttl;
		};

		$this->add_test_filter( 'flavor_agent_core_roadmap_guidance_empty_cache_ttl', $ttl_filter, 10, 2 );
		WordPressTestState::$remote_get_response = [
			'response' => [ 'code' => 500 ],
			'body'     => '',
		];

		$result = CoreRoadmapGuidance::warm( $context );

		$this->assertSame( [], $result );
		$this->assertSame( [], WordPressTestState::$transients['flavor_agent_core_roadmap_guidance_v1'] ?? null );
		$this->assertSame( 90, WordPressTestState::$transient_expirations['flavor_agent_core_roadmap_guidance_v1'] ?? null );
	}

	public function test_warm_falls_back_to_node_state_when_status_column_is_missing(): void {
		WordPressTestState::$remote_get_response = [
			'response' => [ 'code' => 200 ],
			'body'     => $this->build_roadmap_page_fixture( false ),
		];

		$result = CoreRoadmapGuidance::warm();

		$this->assertSame( 2, count( $result ) );
		$this->assertSame( 'Enable richer editor controls', $result[1]['title'] );
		$this->assertStringNotContainsString( 'Shipped legacy feature', wp_json_encode( $result ) );
	}

	private function add_test_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		\add_filter( $hook, $callback, $priority, $accepted_args );

		$this->filters[] = [
			'hook'     => $hook,
			'callback' => $callback,
			'priority' => $priority,
		];
	}

	private function build_roadmap_page_fixture( bool $include_status_column = true ): string {
		$columns = [
			[
				'id'       => 'Title',
				'name'     => 'Title',
				'settings' => null,
			],
			[
				'id'       => 'Status',
				'name'     => 'Status',
				'settings' => [
					'options' => [
						[
							'id'   => 'status-in-progress',
							'name' => 'In progress',
						],
						[
							'id'   => 'status-done',
							'name' => 'Done',
						],
					],
				],
			],
			[
				'id'       => 203262247,
				'name'     => 'Priority',
				'settings' => [
					'options' => [
						[
							'id'   => 'priority-high',
							'name' => 'High',
						],
						[
							'id'   => 'priority-low',
							'name' => 'Low',
						],
					],
				],
			],
		];

		$payload = [
			'groups'       => [
				'nodes' => [
					[
						'groupValue'    => 'core-ui',
						'groupId'       => 'group-core-ui',
						'groupMetadata' => [
							'title' => 'Core Improvements',
							'state' => 'open',
						],
					],
				],
			],
			'groupedItems' => [
				[
					'groupId' => 'group-core-ui',
					'nodes'   => [
						[
							'updatedAt'                => '2026-04-27T10:12:00Z',
							'virtualPriority'          => '0.15',
							'state'                    => 'open',
							'content'                  => [
								'url' => 'https://github.com/wordpress/wordpress/pull/999',
							],
							'memexProjectColumnValues' => [
								[
									'memexProjectColumnId' => 'Title',
									'value'                => [
										'title' => [
											'raw' => 'Enable richer editor controls',
										],
										'url'   => 'https://github.com/wordpress/wordpress/pull/1001',
									],
								],
								[
									'memexProjectColumnId' => 203262247,
									'value'                => [
										'id' => 'priority-high',
									],
								],
							],
						],
						[
							'updatedAt'                => '2026-04-26T08:30:00Z',
							'virtualPriority'          => '0.05',
							'state'                    => 'closed',
							'content'                  => [
								'url' => 'https://github.com/wordpress/wordpress/pull/1002',
							],
							'memexProjectColumnValues' => [
								[
									'memexProjectColumnId' => 'Title',
									'value'                => [
										'title' => [
											'raw' => 'Shipped legacy feature',
										],
										'url'   => 'https://github.com/wordpress/wordpress/pull/1003',
									],
								],
							],
						],
					],
				],
			],
		];

		if ( $include_status_column ) {
			$payload['groupedItems'][0]['nodes'][0]['memexProjectColumnValues'][] = [
				'memexProjectColumnId' => 'Status',
				'value'                => [
					'id' => 'status-in-progress',
				],
			];
			$payload['groupedItems'][0]['nodes'][1]['memexProjectColumnValues'][] = [
				'memexProjectColumnId' => 'Status',
				'value'                => [
					'id' => 'status-done',
				],
			];
		}

		return sprintf(
			'<html><body>%s%s</body></html>',
			'<script type="application/json" id="memex-columns-data">' . wp_json_encode( $columns ) . '</script>',
			'<script type="application/json" id="memex-paginated-items-data">' . wp_json_encode( $payload ) . '</script>'
		);
	}
}
