<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\PersistenceOccurrenceRepository;
use FlavorAgent\Activity\PersistenceWorker;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PersistenceWorkerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		Repository::install();
		PersistenceOccurrenceRepository::install();
	}

	public function test_bounded_batches_continue_without_dropping_candidates(): void {
		$this->assertTrue( class_exists( PersistenceWorker::class ) );
		for ( $index = 0; $index < 30; ++$index ) {
			Repository::create( $this->apply_entry( 'apply-' . $index ) );
		}
		$snapshot = PersistenceOccurrenceRepository::capture( $this->snapshot() );
		$first    = PersistenceWorker::run();
		$this->assertGreaterThan( 0, $first['processed'] );
		$this->assertLessThanOrEqual( 25, $first['processed'] );
		$this->assertSame( 'pending', PersistenceOccurrenceRepository::find( $snapshot['snapshotId'] )['status'] );
		for ( $run = 0; $run < 40 && [] !== PersistenceOccurrenceRepository::pending(); ++$run ) {
			PersistenceWorker::run();
		}
		$entries  = Repository::query(
			[
				'scopeKey'           => 'post:42',
				'includeDiagnostics' => true,
				'limit'              => 100,
			]
		);
		$verdicts = array_filter( $entries, static fn ( array $entry ): bool => 'save_confirmed' === ( $entry['after']['outcome']['event'] ?? '' ) );
		$this->assertCount( 30, $verdicts );
		$complete = PersistenceOccurrenceRepository::find( $snapshot['snapshotId'] );
		$this->assertSame( 'complete', $complete['status'] );
		$this->assertArrayNotHasKey( 'content', $complete );
		$this->assertTrue( PersistenceOccurrenceRepository::eligibility( Repository::find( 'apply-0' ) )['eligible'] );
		$this->assertSame( 0, PersistenceWorker::run()['processed'] );
	}

	public function test_expired_capture_keeps_missing_coverage_without_fabricating_a_verdict(): void {
		$this->assertTrue( class_exists( PersistenceWorker::class ) );
		$apply    = Repository::create( $this->apply_entry( 'apply-one' ) );
		$snapshot = PersistenceOccurrenceRepository::capture( $this->snapshot() );
		global $wpdb;
		$wpdb->update( PersistenceOccurrenceRepository::table_name(), [ 'expires_at' => '2000-01-01 00:00:00' ], [ 'snapshot_id' => $snapshot['snapshotId'] ] );
		$this->assertSame( 0, PersistenceWorker::run()['processed'] );
		$this->assertSame( 'expired', PersistenceOccurrenceRepository::find( $snapshot['snapshotId'] )['status'] );
		$this->assertCount(
			1,
			Repository::query(
				[
					'scopeKey'           => 'post:42',
					'includeDiagnostics' => true,
				]
			)
		);
		$this->assertTrue( PersistenceOccurrenceRepository::eligibility( $apply )['eligible'] );
		$this->assertFalse( PersistenceOccurrenceRepository::eligibility( $apply )['pending'] );
	}

	public function test_inconclusive_earlier_batch_retains_payload_until_expiry(): void {
		$this->assertTrue( class_exists( PersistenceWorker::class ) );
		$uncertain                        = $this->apply_entry( 'uncertain' );
		$uncertain['target']['blockName'] = 'unknown/block';
		Repository::create( $uncertain );
		Repository::create( $this->apply_entry( 'confirmed' ) );
		$snapshot = PersistenceOccurrenceRepository::capture( $this->snapshot() );
		PersistenceOccurrenceRepository::advance( $snapshot, 1, false, false );
		PersistenceWorker::run();
		$complete = PersistenceOccurrenceRepository::find( $snapshot['snapshotId'] );
		$this->assertSame( 'complete', $complete['status'] );
		$this->assertArrayHasKey( 'content', $complete );
	}

	private function apply_entry( string $id ): array {
		return [
			'id'        => $id,
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

	private function snapshot(): array {
		return [
			'saveOccurrenceId' => 'worker-save',
			'origin'           => 'unobserved',
			'reason'           => 'saved',
			'entity'           => [
				'type'     => 'post',
				'postType' => 'post',
				'ref'      => '42',
				'postId'   => 42,
			],
			'content'          => '<!-- wp:heading {"level":3} --><h3>Heading</h3><!-- /wp:heading -->',
			'schemas'          => [
				'core/heading' => [
					'level' => [
						'type'    => 'number',
						'default' => 2,
					],
				],
			],
		];
	}
}
