<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\PersistenceOccurrenceRepository;
use FlavorAgent\Activity\PersistenceOutcome;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PersistenceRetentionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		Repository::install();
		PersistenceOccurrenceRepository::install();
	}

	public function test_verdict_survives_prune_while_apply_survives_and_is_removed_with_it(): void {
		$apply    = $this->create_apply();
		$snapshot = $this->capture();
		$verdict  = PersistenceOutcome::write_verdict(
			$apply,
			$snapshot,
			[
				'event'      => 'save_confirmed',
				'reason'     => 'present',
				'operations' => [],
			]
		);
		global $wpdb;
		$wpdb->update( Repository::table_name(), [ 'created_at' => '2000-01-01 00:00:00' ], [ 'activity_id' => $verdict['id'] ] );
		$this->assertSame( 0, Repository::delete_before( '2020-01-01' ) );
		$this->assertIsArray( Repository::find( $verdict['id'] ) );
		$wpdb->update( Repository::table_name(), [ 'created_at' => '2000-01-01 00:00:00' ], [ 'activity_id' => $apply['id'] ] );
		$this->assertSame( 2, Repository::delete_before( '2020-01-01' ) );
		$this->assertNull( Repository::find( $verdict['id'] ) );
	}

	public function test_expiry_removes_payload_and_orphan_cleanup_removes_metadata(): void {
		$this->assertTrue( method_exists( PersistenceOccurrenceRepository::class, 'cleanup' ) );
		$apply    = $this->create_apply();
		$snapshot = $this->capture();
		global $wpdb;
		$wpdb->update( PersistenceOccurrenceRepository::table_name(), [ 'expires_at' => '2000-01-01 00:00:00' ], [ 'snapshot_id' => $snapshot['snapshotId'] ] );
		PersistenceOccurrenceRepository::cleanup();
		$expired = PersistenceOccurrenceRepository::find( $snapshot['snapshotId'] );
		$this->assertSame( 'expired', $expired['status'] );
		$this->assertArrayNotHasKey( 'content', $expired );
		$this->assertTrue( PersistenceOccurrenceRepository::eligibility( $apply )['eligible'] );
		$wpdb->delete( Repository::table_name(), [ 'activity_id' => $apply['id'] ] );
		PersistenceOccurrenceRepository::cleanup();
		$this->assertNull( PersistenceOccurrenceRepository::find( $snapshot['snapshotId'] ) );
	}

	public function test_save_evidence_does_not_displace_learning_report_sample(): void {
		$apply  = $this->create_apply();
		$before = Repository::query_admin(
			[
				'includeReports' => true,
				'reportRowLimit' => 1,
			]
		);
		PersistenceOutcome::write_verdict(
			$apply,
			$this->capture(),
			[
				'event'      => 'save_confirmed',
				'reason'     => 'present',
				'operations' => [],
			]
		);
		$after = Repository::query_admin(
			[
				'includeReports' => true,
				'reportRowLimit' => 1,
			]
		);
		$this->assertSame( $before['learningReport']['sampleSize'], $after['learningReport']['sampleSize'] );
		$this->assertSame( $before['learningReport']['summary']['applyConversionRate'], $after['learningReport']['summary']['applyConversionRate'] );
		$this->assertFalse( $after['learningReport']['truncated'] );
	}

	private function create_apply(): array {
		return Repository::create(
			[
				'id'        => 'retained',
				'type'      => 'apply_suggestion',
				'surface'   => 'block',
				'applyLane' => 'editor-state',
				'document'  => [
					'scopeKey' => 'post:42',
					'postType' => 'post',
					'entityId' => '42',
				],
			]
		);
	}

	private function capture(): array {
		return PersistenceOccurrenceRepository::capture(
			[
				'saveOccurrenceId' => 'retention-save',
				'origin'           => 'unobserved',
				'reason'           => 'saved',
				'entity'           => [
					'type'     => 'post',
					'postType' => 'post',
					'ref'      => '42',
					'postId'   => 42,
				],
				'content'          => 'private content',
				'schemas'          => [],
			]
		);
	}
}
