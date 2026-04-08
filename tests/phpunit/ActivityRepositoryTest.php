<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ActivityRepositoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
	}

	public function test_install_creates_the_activity_table_and_schema_option(): void {
		Repository::install();

		$this->assertArrayHasKey(
			Repository::table_name(),
			WordPressTestState::$db_tables
		);
		$this->assertSame(
			Repository::SCHEMA_VERSION,
			WordPressTestState::$options[ Repository::SCHEMA_OPTION ] ?? null
		);
	}

	public function test_maybe_install_creates_the_activity_table_for_existing_sites_without_schema_option(): void {
		Repository::maybe_install();

		$this->assertArrayHasKey(
			Repository::table_name(),
			WordPressTestState::$db_tables
		);
		$this->assertSame(
			Repository::SCHEMA_VERSION,
			WordPressTestState::$options[ Repository::SCHEMA_OPTION ] ?? null
		);
	}

	public function test_maybe_install_repairs_a_missing_activity_table_even_when_schema_option_exists(): void {
		WordPressTestState::$options[ Repository::SCHEMA_OPTION ] =
			Repository::SCHEMA_VERSION;

		Repository::maybe_install();

		$this->assertArrayHasKey(
			Repository::table_name(),
			WordPressTestState::$db_tables
		);
	}

	public function test_create_and_query_return_structured_entries_for_scope(): void {
		Repository::install();

		$stored = Repository::create(
			[
				'id'         => 'activity-1',
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
					'prompt'    => 'Make the page feel more editorial.',
					'reference' => 'template:theme//home:3',
				],
				'document'   => [
					'scopeKey' => 'wp_template:theme//home',
					'postType' => 'wp_template',
					'entityId' => 'theme//home',
				],
				'timestamp'  => '2026-03-24T10:00:00Z',
			]
		);

		$this->assertIsArray( $stored );
		$this->assertSame( 7, $stored['userId'] ?? null );

		$entries = Repository::query(
			[
				'scopeKey' => 'wp_template:theme//home',
			]
		);

		$this->assertCount( 1, $entries );
		$this->assertSame( 'activity-1', $entries[0]['id'] ?? null );
		$this->assertSame(
			'theme//home',
			$entries[0]['target']['templateRef'] ?? null
		);
		$this->assertSame(
			'Make the page feel more editorial.',
			$entries[0]['request']['prompt'] ?? null
		);
		$this->assertSame(
			'available',
			$entries[0]['undo']['status'] ?? null
		);
	}

	public function test_query_returns_the_latest_window_for_a_scope(): void {
		Repository::install();

		for ( $index = 1; $index <= 25; ++$index ) {
			Repository::create(
				$this->build_template_entry(
					'activity-' . $index,
					sprintf( '2026-03-24T10:%02d:00Z', $index )
				)
			);
		}

		$entries = Repository::query(
			[
				'scopeKey' => 'wp_template:theme//home',
			]
		);

		$this->assertCount( 20, $entries );
		$this->assertSame( 'activity-6', $entries[0]['id'] ?? null );
		$this->assertSame( 'activity-25', $entries[19]['id'] ?? null );
	}

	public function test_query_can_return_recent_entries_without_a_scope_key(): void {
		Repository::install();

		Repository::create(
			$this->build_template_entry( 'activity-template', '2026-03-24T10:00:00Z' )
		);
		Repository::create(
			[
				'id'         => 'activity-block',
				'type'       => 'apply_suggestion',
				'surface'    => 'block',
				'target'     => [
					'clientId'  => 'block-1',
					'blockName' => 'core/paragraph',
					'blockPath' => [ 0 ],
				],
				'suggestion' => 'Tighten the intro copy',
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
					'prompt'    => 'Tighten the intro copy.',
					'reference' => 'block:block-1:1',
				],
				'document'   => [
					'scopeKey' => 'post:42',
					'postType' => 'post',
					'entityId' => '42',
				],
				'timestamp'  => '2026-03-24T10:00:01Z',
			]
		);

		$entries = Repository::query(
			[
				'limit' => 10,
			]
		);

		$this->assertCount( 2, $entries );
		$this->assertSame( 'activity-template', $entries[0]['id'] ?? null );
		$this->assertSame( 'activity-block', $entries[1]['id'] ?? null );
	}

	public function test_query_admin_paginates_across_page_two_and_page_three(): void {
		Repository::install();

		for ( $index = 1; $index <= 250; ++$index ) {
			Repository::create(
				$this->build_block_entry(
					'activity-' . $index,
					sprintf(
						'2026-03-24T10:%02d:%02dZ',
						intdiv( $index - 1, 60 ),
						( $index - 1 ) % 60
					)
				)
			);
		}

		$page_two   = Repository::query_admin(
			[
				'page'    => 2,
				'perPage' => 100,
			]
		);
		$page_three = Repository::query_admin(
			[
				'page'    => 3,
				'perPage' => 100,
			]
		);

		$this->assertSame( 250, $page_two['paginationInfo']['totalItems'] ?? null );
		$this->assertSame( 3, $page_two['paginationInfo']['totalPages'] ?? null );
		$this->assertSame( 2, $page_two['paginationInfo']['page'] ?? null );
		$this->assertCount( 100, $page_two['entries'] ?? [] );
		$this->assertSame( 'activity-150', $page_two['entries'][0]['id'] ?? null );
		$this->assertSame( 'activity-51', $page_two['entries'][99]['id'] ?? null );
		$this->assertSame( 3, $page_three['paginationInfo']['page'] ?? null );
		$this->assertCount( 50, $page_three['entries'] ?? [] );
		$this->assertSame( 'activity-50', $page_three['entries'][0]['id'] ?? null );
		$this->assertSame( 'activity-1', $page_three['entries'][49]['id'] ?? null );
	}

	public function test_query_admin_summary_counts_the_full_filtered_result_set(): void {
		Repository::install();

		Repository::create(
			$this->build_block_entry( 'activity-1', '2026-03-24T10:00:00Z' )
		);
		Repository::create(
			$this->build_block_entry( 'activity-2', '2026-03-24T10:00:01Z' )
		);
		Repository::create(
			$this->build_block_entry( 'activity-3', '2026-03-24T10:00:02Z' )
		);
		Repository::create(
			$this->build_block_entry(
				'activity-undone',
				'2026-03-24T10:00:03Z',
				'99'
			)
		);
		Repository::create(
			$this->build_block_entry(
				'activity-failed',
				'2026-03-24T10:00:04Z',
				'100'
			)
		);
		Repository::create(
			[
				'id'              => 'activity-review',
				'type'            => 'request_diagnostic',
				'surface'         => 'pattern',
				'target'          => [
					'postType'   => 'post',
					'requestRef' => 'pattern:post:42',
				],
				'suggestion'      => 'Pattern recommendation request',
				'before'          => [],
				'after'           => [],
				'request'         => [
					'prompt'    => 'Find cleaner patterns.',
					'reference' => 'pattern:post:42',
				],
				'document'        => [
					'scopeKey' => 'post:42',
					'postType' => 'post',
					'entityId' => '42',
				],
				'executionResult' => 'review',
				'undo'            => [
					'status' => 'review',
				],
				'timestamp'       => '2026-03-24T10:00:05Z',
			]
		);

		Repository::update_undo_status( 'activity-undone', 'undone' );
		Repository::update_undo_status(
			'activity-failed',
			'failed',
			'Undo failed.'
		);

		$result = Repository::query_admin(
			[
				'surface' => 'block',
				'page'    => 2,
				'perPage' => 2,
			]
		);

		$this->assertCount( 2, $result['entries'] ?? [] );
		$this->assertSame(
			[
				'total'   => 5,
				'applied' => 1,
				'undone'  => 1,
				'review'  => 0,
				'blocked' => 2,
				'failed'  => 1,
			],
			$result['summary'] ?? []
		);
	}

	public function test_query_admin_marks_blocked_rows_when_the_blocking_row_is_on_page_one(): void {
		Repository::install();

		Repository::create(
			$this->build_block_entry( 'activity-1', '2026-03-24T10:00:00Z' )
		);
		Repository::create(
			$this->build_block_entry( 'activity-2', '2026-03-24T10:00:01Z' )
		);
		Repository::create(
			$this->build_block_entry( 'activity-3', '2026-03-24T10:00:02Z' )
		);

		$result = Repository::query_admin(
			[
				'page'    => 3,
				'perPage' => 1,
			]
		);

		$this->assertSame( 3, $result['paginationInfo']['totalPages'] ?? null );
		$this->assertSame( 'activity-1', $result['entries'][0]['id'] ?? null );
		$this->assertSame( 'blocked', $result['entries'][0]['status'] ?? null );
	}

	public function test_query_admin_marks_failed_request_diagnostics_as_failed(): void {
		Repository::install();

		Repository::create(
			[
				'id'              => 'content-request-failed',
				'type'            => 'request_diagnostic',
				'surface'         => 'content',
				'target'          => [
					'requestRef' => 'content:post:42',
				],
				'suggestion'      => 'Content request failed: Missing draft context.',
				'before'          => [],
				'after'           => [],
				'request'         => [
					'prompt'    => 'Critique this draft.',
					'reference' => 'content:post:42',
				],
				'document'        => [
					'scopeKey' => 'post:42',
					'postType' => 'post',
					'entityId' => '42',
				],
				'executionResult' => 'review',
				'undo'            => [
					'status' => 'failed',
					'error'  => 'Missing draft context.',
				],
				'timestamp'       => '2026-03-24T10:00:05Z',
			]
		);

		$result = Repository::query_admin( [] );

		$this->assertSame( 'failed', $result['entries'][0]['status'] ?? null );
	}

	public function test_query_admin_returns_filter_options_for_the_full_filtered_result_set(): void {
		Repository::install();

		WordPressTestState::$current_user_id = 7;
		Repository::create(
			$this->build_block_entry_with_request_meta(
				'activity-block',
				'2026-03-24T10:00:00Z',
				[
					'backendLabel'          => 'WordPress AI Client',
					'providerLabel'         => 'WordPress AI Client',
					'pathLabel'             => 'WordPress AI Client via Settings > Connectors',
					'ownerLabel'            => 'Settings > Connectors',
					'credentialSourceLabel' => 'Provider-managed',
					'selectedProviderLabel' => 'Azure OpenAI',
				]
			)
		);

		WordPressTestState::$current_user_id = 11;
		Repository::create(
			array_merge(
				$this->build_template_entry( 'activity-template', '2026-03-24T10:00:01Z' ),
				[
					'request' => [
						'prompt'    => 'Make the page feel more editorial.',
						'reference' => 'template:theme//home:3',
						'ai'        => [
							'backendLabel'          => 'Azure OpenAI responses',
							'providerLabel'         => 'Azure OpenAI',
							'pathLabel'             => 'Azure OpenAI via Settings > Flavor Agent',
							'ownerLabel'            => 'Settings > Flavor Agent',
							'credentialSourceLabel' => 'Settings > Flavor Agent',
							'selectedProviderLabel' => 'Azure OpenAI',
						],
					],
				]
			)
		);

		$result = Repository::query_admin(
			[
				'page'    => 1,
				'perPage' => 1,
			]
		);

		$this->assertCount( 1, $result['entries'] ?? [] );
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
			$result['filterOptions']['surface'] ?? []
		);
		$this->assertSame(
			[
				[
					'value' => 'insert',
					'label' => 'Insert pattern',
				],
				[
					'value' => 'modify-attributes',
					'label' => 'Modify attributes',
				],
			],
			$result['filterOptions']['operationType'] ?? []
		);
		$this->assertSame(
			[
				[
					'value' => 'post',
					'label' => 'post',
				],
				[
					'value' => 'wp_template',
					'label' => 'wp_template',
				],
			],
			$result['filterOptions']['postType'] ?? []
		);
		$this->assertSame(
			[
				[
					'value' => '7',
					'label' => 'User #7',
				],
				[
					'value' => '11',
					'label' => 'User #11',
				],
			],
			$result['filterOptions']['userId'] ?? []
		);
		$this->assertSame(
			[
				[
					'value' => 'Azure OpenAI responses',
					'label' => 'Azure OpenAI responses',
				],
				[
					'value' => 'WordPress AI Client',
					'label' => 'WordPress AI Client',
				],
			],
			$result['filterOptions']['provider'] ?? []
		);
		$this->assertSame(
			[
				[
					'value' => 'Azure OpenAI via Settings > Flavor Agent',
					'label' => 'Azure OpenAI via Settings > Flavor Agent',
				],
				[
					'value' => 'WordPress AI Client via Settings > Connectors',
					'label' => 'WordPress AI Client via Settings > Connectors',
				],
			],
			$result['filterOptions']['providerPath'] ?? []
		);
	}

	public function test_query_admin_search_matches_ui_block_paths_and_assign_template_part_labels(): void {
		Repository::install();

		Repository::create(
			array_merge(
				$this->build_block_entry( 'activity-block', '2026-03-24T10:00:00Z' ),
				[
					'target' => [
						'clientId'  => 'block-activity-block',
						'blockName' => 'core/paragraph',
						'blockPath' => [ 0, 1 ],
					],
				]
			)
		);
		Repository::create(
			$this->build_template_part_assignment_entry(
				'activity-assign',
				'2026-03-24T10:00:01Z'
			)
		);

		$block_path_result = Repository::query_admin(
			[
				'search' => 'Paragraph · 1 → 2',
			]
		);
		$assignment_result = Repository::query_admin(
			[
				'search' => 'Assign template part',
			]
		);

		$this->assertSame(
			[ 'activity-block' ],
			array_column( $block_path_result['entries'] ?? [], 'id' )
		);
		$this->assertSame(
			[ 'activity-assign' ],
			array_column( $assignment_result['entries'] ?? [], 'id' )
		);
	}

	public function test_query_admin_search_matches_projected_style_book_block_labels(): void {
		Repository::install();

		Repository::create(
			[
				'id'         => 'activity-style-book',
				'type'       => 'apply_style_suggestion',
				'surface'    => 'style-book',
				'target'     => [
					'globalStylesId' => '17',
					'blockName'      => 'core/paragraph',
					'blockTitle'     => 'Paragraph',
				],
				'suggestion' => 'Refine paragraph styles.',
				'before'     => [
					'attributes' => [],
				],
				'after'      => [
					'attributes' => [],
				],
				'request'    => [],
				'document'   => [
					'scopeKey' => 'style_book:17:core/paragraph',
					'postType' => 'global_styles',
					'entityId' => '17',
				],
				'timestamp'  => '2026-03-24T10:00:00Z',
			]
		);

		$result = Repository::query_admin(
			[
				'search' => 'Paragraph',
			]
		);

		$this->assertSame(
			[ 'activity-style-book' ],
			array_column( $result['entries'] ?? [], 'id' )
		);
	}

	public function test_query_admin_supports_relative_day_filters(): void {
		Repository::install();

		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		Repository::create(
			$this->build_block_entry(
				'activity-recent',
				$now->sub( new \DateInterval( 'P2D' ) )->format( 'Y-m-d\TH:i:s\Z' )
			)
		);
		Repository::create(
			$this->build_block_entry(
				'activity-older',
				$now->sub( new \DateInterval( 'P10D' ) )->format( 'Y-m-d\TH:i:s\Z' )
			)
		);

		$recent_result  = Repository::query_admin(
			[
				'dayOperator'      => 'inThePast',
				'dayRelativeValue' => 7,
				'dayRelativeUnit'  => 'days',
			]
		);
		$older_result   = Repository::query_admin(
			[
				'dayOperator'      => 'over',
				'dayRelativeValue' => 7,
				'dayRelativeUnit'  => 'days',
			]
		);
		$between_result = Repository::query_admin(
			[
				'dayOperator' => 'between',
				'day'         => $now->sub( new \DateInterval( 'P2D' ) )->format( 'Y-m-d' ),
				'dayEnd'      => $now->sub( new \DateInterval( 'P2D' ) )->format( 'Y-m-d' ),
			]
		);

		$this->assertSame(
			[ 'activity-recent' ],
			array_column( $recent_result['entries'] ?? [], 'id' )
		);
		$this->assertSame(
			[ 'activity-older' ],
			array_column( $older_result['entries'] ?? [], 'id' )
		);
		$this->assertSame(
			[ 'activity-recent' ],
			array_column( $between_result['entries'] ?? [], 'id' )
		);
	}

	public function test_query_admin_supports_operator_filters_and_user_sorting(): void {
		Repository::install();

		WordPressTestState::$current_user_id = 20;
		Repository::create(
			$this->build_template_entry( 'activity-template', '2026-03-24T10:00:00Z' )
		);

		WordPressTestState::$current_user_id = 7;
		Repository::create(
			$this->build_block_entry( 'activity-block', '2026-03-24T10:00:01Z' )
		);

		WordPressTestState::$current_user_id = 11;
		Repository::create(
			$this->build_template_part_assignment_entry(
				'activity-assign',
				'2026-03-24T10:00:02Z'
			)
		);

		$sorted_result   = Repository::query_admin(
			[
				'userId'         => 20,
				'userIdOperator' => 'isNot',
				'sortField'      => 'userId',
				'sortDirection'  => 'asc',
			]
		);
		$filtered_result = Repository::query_admin(
			[
				'operationType'         => 'replace',
				'operationTypeOperator' => 'is',
			]
		);

		$this->assertSame(
			[ 'activity-block', 'activity-assign' ],
			array_column( $sorted_result['entries'] ?? [], 'id' )
		);
		$this->assertSame(
			[ 'activity-assign' ],
			array_column( $filtered_result['entries'] ?? [], 'id' )
		);
	}

	public function test_query_admin_supports_provider_filters_and_sorting(): void {
		Repository::install();

		Repository::create(
			$this->build_block_entry_with_request_meta(
				'activity-azure',
				'2026-03-24T10:00:00Z',
				[
					'backendLabel'          => 'Azure OpenAI responses',
					'providerLabel'         => 'Azure OpenAI',
					'pathLabel'             => 'Azure OpenAI via Settings > Flavor Agent',
					'ownerLabel'            => 'Settings > Flavor Agent',
					'credentialSourceLabel' => 'Settings > Flavor Agent',
					'selectedProviderLabel' => 'Azure OpenAI',
				]
			)
		);
		Repository::create(
			$this->build_block_entry_with_request_meta(
				'activity-connector',
				'2026-03-24T10:00:01Z',
				[
					'backendLabel'          => 'WordPress AI Client',
					'providerLabel'         => 'WordPress AI Client',
					'pathLabel'             => 'WordPress AI Client via Settings > Connectors',
					'ownerLabel'            => 'Settings > Connectors',
					'credentialSourceLabel' => 'Provider-managed',
					'selectedProviderLabel' => 'Azure OpenAI',
				]
			)
		);

		$filtered_result = Repository::query_admin(
			[
				'provider'         => 'WordPress AI Client',
				'providerOperator' => 'is',
			]
		);
		$sorted_result   = Repository::query_admin(
			[
				'sortField'     => 'configurationOwner',
				'sortDirection' => 'asc',
			]
		);

		$this->assertSame(
			[ 'activity-connector' ],
			array_column( $filtered_result['entries'] ?? [], 'id' )
		);
		$this->assertSame(
			[
				[
					'value' => 'Azure OpenAI responses',
					'label' => 'Azure OpenAI responses',
				],
				[
					'value' => 'WordPress AI Client',
					'label' => 'WordPress AI Client',
				],
			],
			$filtered_result['filterOptions']['provider'] ?? []
		);
		$this->assertSame(
			[ 'activity-connector', 'activity-azure' ],
			array_column( $sorted_result['entries'] ?? [], 'id' )
		);
	}

	public function test_query_admin_preserves_blocked_status_when_filtered_results_hide_newer_activity(): void {
		Repository::install();

		Repository::create(
			$this->build_block_entry_with_request_meta(
				'activity-older',
				'2026-03-24T10:00:00Z',
				[
					'backendLabel'  => 'WordPress AI Client',
					'providerLabel' => 'WordPress AI Client',
				]
			)
		);
		Repository::create(
			$this->build_block_entry_with_request_meta(
				'activity-newer',
				'2026-03-24T10:00:01Z',
				[
					'backendLabel'  => 'Azure OpenAI responses',
					'providerLabel' => 'Azure OpenAI',
				]
			)
		);

		$result = Repository::query_admin(
			[
				'provider' => 'WordPress AI Client',
			]
		);

		$this->assertSame(
			[ 'activity-older' ],
			array_column( $result['entries'] ?? [], 'id' )
		);
		$this->assertSame( 'blocked', $result['entries'][0]['status'] ?? null );
	}

	public function test_maybe_install_schedules_and_runs_admin_projection_backfill_for_legacy_rows(): void {
		Repository::install();

		Repository::create(
			$this->build_block_entry_with_request_meta(
				'activity-legacy',
				'2026-03-24T10:00:00Z',
				[
					'backendLabel'          => 'Azure OpenAI responses',
					'providerLabel'         => 'Azure OpenAI',
					'pathLabel'             => 'Azure OpenAI via Settings > Flavor Agent',
					'ownerLabel'            => 'Settings > Flavor Agent',
					'credentialSourceLabel' => 'Settings > Flavor Agent',
					'selectedProviderLabel' => 'Azure OpenAI',
				]
			)
		);

		$table_name = Repository::table_name();

		WordPressTestState::$db_tables[ $table_name ][0]['schema_version']            = 1;
		WordPressTestState::$db_tables[ $table_name ][0]['admin_operation_type']      = '';
		WordPressTestState::$db_tables[ $table_name ][0]['admin_operation_label']     = '';
		WordPressTestState::$db_tables[ $table_name ][0]['admin_provider']            = '';
		WordPressTestState::$db_tables[ $table_name ][0]['admin_provider_path']       = '';
		WordPressTestState::$db_tables[ $table_name ][0]['admin_configuration_owner'] = '';
		WordPressTestState::$db_tables[ $table_name ][0]['admin_credential_source']   = '';
		WordPressTestState::$db_tables[ $table_name ][0]['admin_selected_provider']   = '';
		WordPressTestState::$db_tables[ $table_name ][0]['admin_request_ability']     = '';
		WordPressTestState::$db_tables[ $table_name ][0]['admin_request_route']       = '';
		WordPressTestState::$db_tables[ $table_name ][0]['admin_request_reference']   = '';
		WordPressTestState::$db_tables[ $table_name ][0]['admin_request_prompt']      = null;
		WordPressTestState::$db_tables[ $table_name ][0]['admin_search_text']         = '';
		WordPressTestState::$options[ Repository::SCHEMA_OPTION ]                     = Repository::SCHEMA_VERSION - 1;

		Repository::maybe_install();

		$this->assertSame(
			Repository::ADMIN_PROJECTION_BACKFILL_CRON_HOOK,
			WordPressTestState::$scheduled_events[ Repository::ADMIN_PROJECTION_BACKFILL_CRON_HOOK ]['hook'] ?? null
		);
		$this->assertSame(
			'',
			WordPressTestState::$db_tables[ $table_name ][0]['admin_operation_type'] ?? null
		);

		$pending_result = Repository::query_admin(
			[
				'provider' => 'Azure OpenAI responses',
			]
		);

		$this->assertSame(
			[ 'activity-legacy' ],
			array_column( $pending_result['entries'] ?? [], 'id' )
		);

		Repository::run_admin_projection_backfill();

		$this->assertSame(
			'modify-attributes',
			WordPressTestState::$db_tables[ $table_name ][0]['admin_operation_type'] ?? null
		);
		$this->assertSame(
			'Azure OpenAI responses',
			WordPressTestState::$db_tables[ $table_name ][0]['admin_provider'] ?? null
		);
		$this->assertSame(
			'Azure OpenAI via Settings > Flavor Agent',
			WordPressTestState::$db_tables[ $table_name ][0]['admin_provider_path'] ?? null
		);
		$this->assertFalse(
			wp_next_scheduled( Repository::ADMIN_PROJECTION_BACKFILL_CRON_HOOK )
		);

		$result = Repository::query_admin(
			[
				'provider' => 'Azure OpenAI responses',
			]
		);

		$this->assertSame(
			[ 'activity-legacy' ],
			array_column( $result['entries'] ?? [], 'id' )
		);
	}

	public function test_query_admin_provider_filter_batches_history_queries(): void {
		$original_wpdb = $GLOBALS['wpdb'] ?? null;
		$counting_wpdb = new class() extends \wpdb {
			public int $get_results_calls = 0;

			public function get_results( string $query, string $output = OBJECT ): array {
				++$this->get_results_calls;

				return parent::get_results( $query, $output );
			}
		};

		if ( $original_wpdb instanceof \wpdb ) {
			$counting_wpdb->prefix = $original_wpdb->prefix;
		}

		$GLOBALS['wpdb'] = $counting_wpdb;

		try {
			Repository::install();

			for ( $index = 1; $index <= 3; ++$index ) {
				Repository::create(
					$this->build_block_entry_with_request_meta(
						'activity-' . $index,
						sprintf( '2026-03-24T10:00:%02dZ', $index ),
						[
							'backendLabel'  => 'Azure OpenAI responses',
							'providerLabel' => 'Azure OpenAI',
						],
						(string) ( 40 + $index )
					)
				);
			}

			$counting_wpdb->get_results_calls = 0;

			$result = Repository::query_admin(
				[
					'provider' => 'Azure OpenAI responses',
				]
			);

			$this->assertCount( 3, $result['entries'] ?? [] );
			$this->assertSame( 3, $counting_wpdb->get_results_calls );
		} finally {
			$GLOBALS['wpdb'] = $original_wpdb;
		}
	}

	public function test_create_generates_a_uuid_v4_activity_id_when_none_is_provided(): void {
		Repository::install();

		$stored = Repository::create(
			[
				'type'       => 'apply_suggestion',
				'surface'    => 'block',
				'target'     => [
					'clientId'  => 'block-1',
					'blockName' => 'core/paragraph',
				],
				'suggestion' => 'Tighten copy',
				'before'     => [],
				'after'      => [],
				'request'    => [],
				'document'   => [
					'scopeKey' => 'post:42',
					'postType' => 'post',
					'entityId' => '42',
				],
				'timestamp'  => '2026-03-27T10:00:00Z',
			]
		);

		$this->assertIsArray( $stored );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$stored['id'] ?? ''
		);
	}

	public function test_update_undo_status_requires_ordered_tail_eligibility(): void {
		Repository::install();

		Repository::create( $this->build_template_entry( 'activity-older', '2026-03-24T10:00:00Z' ) );
		Repository::create( $this->build_template_entry( 'activity-newer', '2026-03-24T10:00:01Z' ) );

		$blocked = Repository::update_undo_status( 'activity-older', 'undone' );

		$this->assertInstanceOf( \WP_Error::class, $blocked );
		$this->assertSame(
			'flavor_agent_activity_undo_blocked',
			$blocked->get_error_code()
		);

		$newer = Repository::update_undo_status( 'activity-newer', 'undone' );

		$this->assertIsArray( $newer );
		$this->assertSame( 'undone', $newer['undo']['status'] ?? null );

		$older = Repository::update_undo_status( 'activity-older', 'undone' );

		$this->assertIsArray( $older );
		$this->assertSame( 'undone', $older['undo']['status'] ?? null );
	}

	public function test_update_undo_status_rejects_terminal_state_rewrites(): void {
		Repository::install();

		Repository::create( $this->build_template_entry( 'activity-1', '2026-03-24T10:00:00Z' ) );

		$undone = Repository::update_undo_status( 'activity-1', 'undone' );

		$this->assertIsArray( $undone );
		$this->assertSame( 'undone', $undone['undo']['status'] ?? null );

		$rewrite = Repository::update_undo_status( 'activity-1', 'failed', 'Undo failed.' );

		$this->assertInstanceOf( \WP_Error::class, $rewrite );
		$this->assertSame( 'flavor_agent_activity_invalid_undo_transition', $rewrite->get_error_code() );
	}

	public function test_ordered_undo_check_works_with_many_entries_for_same_entity(): void {
		Repository::install();

		for ( $index = 1; $index <= 50; ++$index ) {
			Repository::create(
				$this->build_template_entry(
					'activity-bulk-' . $index,
					sprintf( '2026-03-24T10:%02d:%02dZ', intdiv( $index, 60 ), $index % 60 )
				)
			);
		}

		$blocked = Repository::update_undo_status( 'activity-bulk-1', 'undone' );

		$this->assertInstanceOf( \WP_Error::class, $blocked );
		$this->assertSame(
			'flavor_agent_activity_undo_blocked',
			$blocked->get_error_code()
		);

		$tail = Repository::update_undo_status( 'activity-bulk-50', 'undone' );

		$this->assertIsArray( $tail );
		$this->assertSame( 'undone', $tail['undo']['status'] ?? null );
	}

	public function test_create_merges_newer_terminal_undo_state_when_the_row_already_exists(): void {
		Repository::install();

		Repository::create(
			$this->build_template_entry( 'activity-1', '2026-03-24T10:00:00Z' )
		);

		$merged = Repository::create(
			array_merge(
				$this->build_template_entry( 'activity-1', '2026-03-24T10:00:00Z' ),
				[
					'undo' => [
						'status'    => 'undone',
						'updatedAt' => '2026-03-24T10:05:00Z',
						'undoneAt'  => '2026-03-24T10:05:00Z',
					],
				]
			)
		);

		$this->assertIsArray( $merged );
		$this->assertSame( 'undone', $merged['undo']['status'] ?? null );
		$this->assertSame(
			'2026-03-24T10:05:00+00:00',
			$merged['undo']['updatedAt'] ?? null
		);
	}

	public function test_query_admin_filters_hours_by_timestamp_across_day_boundaries(): void {
		$timezone_names = [
			'Pacific/Kiritimati',
			'Asia/Tokyo',
			'Europe/London',
			'America/New_York',
			'Pacific/Honolulu',
		];
		$selected       = null;

		foreach ( $timezone_names as $timezone_name ) {
			$local_now = new \DateTimeImmutable( 'now', new \DateTimeZone( $timezone_name ) );

			if ( (int) $local_now->format( 'G' ) < 6 ) {
				$selected = [
					'name' => $timezone_name,
					'now'  => $local_now,
				];
				break;
			}
		}

		if ( null === $selected ) {
			$this->markTestSkipped( 'No test timezone produced a local time within the first six hours of the day.' );
		}

		WordPressTestState::$options['timezone_string'] = $selected['name'];
		$get_timestamp_data                             = new \ReflectionMethod( Repository::class, 'get_timestamp_data' );
		$get_timestamp_data->setAccessible( true );
		$matches_day_filter = new \ReflectionMethod( Repository::class, 'matches_day_filter' );
		$matches_day_filter->setAccessible( true );

		$recent_meta = $get_timestamp_data->invoke(
			null,
			$selected['now']->sub( new \DateInterval( 'PT5H' ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'c' ),
			new \DateTimeZone( $selected['name'] )
		);
		$older_meta  = $get_timestamp_data->invoke(
			null,
			$selected['now']->sub( new \DateInterval( 'PT7H' ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'c' ),
			new \DateTimeZone( $selected['name'] )
		);
		$filters     = [
			'dayOperator'      => 'inThePast',
			'dayRelativeValue' => 6,
			'dayRelativeUnit'  => 'hours',
		];

		$this->assertTrue(
			(bool) $matches_day_filter->invoke( null, $recent_meta, $filters, new \DateTimeZone( $selected['name'] ) )
		);
		$this->assertFalse(
			(bool) $matches_day_filter->invoke( null, $older_meta, $filters, new \DateTimeZone( $selected['name'] ) )
		);
	}

	public function test_delete_before_removes_entries_older_than_the_cutoff(): void {
		Repository::install();

		Repository::create(
			$this->build_template_entry( 'activity-old', '2026-01-01T10:00:00Z' )
		);
		Repository::create(
			$this->build_template_entry( 'activity-recent', '2026-03-27T10:00:00Z' )
		);

		$deleted = Repository::delete_before( '2026-03-01T00:00:00Z' );

		$this->assertSame( 1, $deleted );

		$entries = Repository::query( [ 'limit' => 100 ] );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'activity-recent', $entries[0]['id'] ?? null );
	}

	public function test_delete_before_returns_zero_when_nothing_matches(): void {
		Repository::install();

		Repository::create(
			$this->build_template_entry( 'activity-recent', '2026-03-27T10:00:00Z' )
		);

		$deleted = Repository::delete_before( '2026-01-01T00:00:00Z' );

		$this->assertSame( 0, $deleted );
	}

	public function test_delete_before_returns_zero_when_timestamp_is_invalid(): void {
		Repository::install();

		Repository::create(
			$this->build_template_entry( 'activity-recent', '2026-03-27T10:00:00Z' )
		);

		$deleted = Repository::delete_before( 'not-a-timestamp' );

		$this->assertSame( 0, $deleted );

		$entries = Repository::query( [ 'limit' => 100 ] );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'activity-recent', $entries[0]['id'] ?? null );
	}

	public function test_ensure_prune_schedule_schedules_a_daily_event_once(): void {
		$this->assertFalse( wp_next_scheduled( Repository::PRUNE_CRON_HOOK ) );

		Repository::ensure_prune_schedule();

		$this->assertSame(
			Repository::PRUNE_CRON_HOOK,
			WordPressTestState::$scheduled_events[ Repository::PRUNE_CRON_HOOK ]['hook'] ?? null
		);
		$this->assertSame(
			'daily',
			WordPressTestState::$scheduled_events[ Repository::PRUNE_CRON_HOOK ]['recurrence'] ?? null
		);

		$first_timestamp = WordPressTestState::$scheduled_events[ Repository::PRUNE_CRON_HOOK ]['timestamp'] ?? null;

		Repository::ensure_prune_schedule();

		$this->assertCount( 1, WordPressTestState::$scheduled_events );
		$this->assertSame(
			$first_timestamp,
			WordPressTestState::$scheduled_events[ Repository::PRUNE_CRON_HOOK ]['timestamp'] ?? null
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_template_entry( string $id, string $timestamp ): array {
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
				'prompt'    => 'Make the page feel more editorial.',
				'reference' => 'template:theme//home:3',
			],
			'document'   => [
				'scopeKey' => 'wp_template:theme//home',
				'postType' => 'wp_template',
				'entityId' => 'theme//home',
			],
			'timestamp'  => $timestamp,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_block_entry(
		string $id,
		string $timestamp,
		string $entity_id = '42'
	): array {
		return [
			'id'         => $id,
			'type'       => 'apply_suggestion',
			'surface'    => 'block',
			'target'     => [
				'clientId'  => 'block-' . $id,
				'blockName' => 'core/paragraph',
				'blockPath' => [ 0 ],
			],
			'suggestion' => 'Tighten the intro copy',
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
				'prompt'    => 'Tighten the intro copy.',
				'reference' => 'block:' . $entity_id . ':1',
			],
			'document'   => [
				'scopeKey' => 'post:' . $entity_id,
				'postType' => 'post',
				'entityId' => $entity_id,
			],
			'timestamp'  => $timestamp,
		];
	}

	/**
	 * @param array<string, mixed> $request_meta
	 * @return array<string, mixed>
	 */
	private function build_block_entry_with_request_meta(
		string $id,
		string $timestamp,
		array $request_meta,
		string $entity_id = '42'
	): array {
		$entry                  = $this->build_block_entry( $id, $timestamp, $entity_id );
		$entry['request']['ai'] = $request_meta;

		return $entry;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_template_part_assignment_entry(
		string $id,
		string $timestamp
	): array {
		return [
			'id'         => $id,
			'type'       => 'apply_template_part_suggestion',
			'surface'    => 'template-part',
			'target'     => [
				'templatePartRef' => 'theme//header',
			],
			'suggestion' => '',
			'before'     => [
				'operations' => [],
			],
			'after'      => [
				'operations' => [
					[
						'type' => 'assign_template_part',
						'slug' => 'header',
					],
				],
			],
			'request'    => [
				'prompt'    => 'Attach the shared header.',
				'reference' => 'template-part:theme//header:0',
			],
			'document'   => [
				'scopeKey' => 'wp_template_part:theme//header',
				'postType' => 'wp_template_part',
				'entityId' => 'theme//header',
			],
			'timestamp'  => $timestamp,
		];
	}
}
