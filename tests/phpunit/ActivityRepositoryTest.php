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
}
