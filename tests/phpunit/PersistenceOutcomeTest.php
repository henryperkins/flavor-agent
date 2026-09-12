<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\PersistenceOutcome;
use FlavorAgent\Activity\RecommendationOutcome;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Activity\Serializer;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PersistenceOutcomeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		Repository::install();
	}

	public function test_forward_lane_survives_storage_and_historical_lane_stays_unknown(): void {
		$stored = Repository::create( $this->apply_entry() );
		$this->assertSame( 'editor-state', $stored['applyLane'] ?? null );
		$this->assertNull( Serializer::normalize_entry( [] )['applyLane'] );
		$this->assertSame( 'editor-state', Repository::find( 'apply-1' )['applyLane'] );
	}

	public function test_client_cannot_author_a_verdict_even_with_an_entry_marker(): void {
		$entry                   = $this->apply_entry();
		$entry['type']           = 'recommendation_outcome';
		$entry['serverAuthored'] = true;
		$entry['after']          = [
			'outcome' => [
				'event'          => 'save_confirmed',
				'serverAuthored' => true,
			],
		];
		$result                  = RecommendationOutcome::normalize_entry( $entry );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_persistence_server_authorship_required', $result->get_error_code() );
	}

	public function test_verdict_is_idempotent_inherits_owner_scope_and_keeps_capture_order(): void {
		$this->assertTrue( class_exists( PersistenceOutcome::class ) );
		$apply                               = Repository::create( $this->apply_entry() );
		WordPressTestState::$current_user_id = 12;
		$snapshot                            = [
			'snapshotId'         => 'snapshot-1',
			'saveOccurrenceId'   => 'occurrence-1',
			'saveSequence'       => 3,
			'origin'             => 'unobserved',
			'entity'             => [
				'type'     => 'post',
				'postType' => 'post',
				'postId'   => 42,
				'ref'      => '42',
			],
			'contentFingerprint' => hash( 'sha256', 'saved' ),
			'saverUserId'        => 12,
		];
		$comparison                          = [
			'event'      => 'save_confirmed',
			'reason'     => 'present',
			'operations' => [
				[
					'state'  => 'present',
					'reason' => 'attribute_match',
				],
			],
		];
		$first                               = PersistenceOutcome::write_verdict( $apply, $snapshot, $comparison );
		$second                              = PersistenceOutcome::write_verdict( $apply, $snapshot, $comparison );
		$this->assertIsArray( $first );
		$this->assertSame( $first['id'], $second['id'] );
		$this->assertSame( 7, $first['userId'] );
		$this->assertSame( $apply['document'], $first['document'] );
		$this->assertSame( 'apply-1', $first['linkedApplyActivityId'] );
		$this->assertSame( 'occurrence-1', $first['saveOccurrenceId'] );
		$this->assertSame( 3, $first['after']['outcome']['saveSequence'] );
		$this->assertSame( 12, $first['after']['outcome']['saverUserId'] );
		$this->assertCount(
			2,
			Repository::query(
				[
					'scopeKey'           => 'post:42',
					'includeDiagnostics' => true,
				]
			)
		);
		$this->assertFalse( PersistenceOutcome::is_authoring() );
	}

	public function test_replayed_apply_cannot_relabel_an_existing_historical_row(): void {
		$entry = $this->apply_entry();
		unset( $entry['applyLane'] );
		Repository::create( $entry );
		$entry['applyLane'] = 'editor-state';
		$stored             = Repository::create( $entry );
		$this->assertNull( $stored['applyLane'] );
	}

	private function apply_entry(): array {
		return [
			'id'        => 'apply-1',
			'type'      => 'apply_suggestion',
			'surface'   => 'block',
			'applyLane' => 'editor-state',
			'target'    => [
				'blockName' => 'core/heading',
				'blockPath' => [ 0 ],
			],
			'before'    => [ 'attributes' => [ 'level' => 2 ] ],
			'after'     => [ 'attributes' => [ 'level' => 3 ] ],
			'document'  => [
				'scopeKey' => 'post:42',
				'postType' => 'post',
				'entityId' => '42',
			],
		];
	}
}
