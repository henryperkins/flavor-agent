<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\PersistenceOccurrenceRepository;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PersistenceOccurrenceRepositoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		Repository::install();
	}

	public function test_capture_freezes_candidates_content_schema_and_sequence(): void {
		$this->assertTrue( class_exists( PersistenceOccurrenceRepository::class ) );
		PersistenceOccurrenceRepository::install();
		Repository::create( $this->apply_entry( 'first' ) );
		$one = PersistenceOccurrenceRepository::capture( $this->snapshot( 'save-one', 'first content' ) );
		Repository::create( $this->apply_entry( 'late' ) );
		$two = PersistenceOccurrenceRepository::capture( $this->snapshot( 'save-two', 'second content' ) );
		$this->assertIsArray( $one );
		$this->assertIsArray( $two );
		$this->assertGreaterThan( $one['saveSequence'], $two['saveSequence'] );
		$this->assertSame( 'first content', PersistenceOccurrenceRepository::find( $one['snapshotId'] )['content'] );
		$this->assertSame(
			[
				'level' => [
					'type'    => 'number',
					'default' => 2,
				],
			],
			$one['schemas']['core/heading']
		);
		$this->assertSame( [ 'first' ], array_column( PersistenceOccurrenceRepository::candidate_rows( $one, 0, 25 ), 'activity_id' ) );
		$this->assertSame( [ 'first', 'late' ], array_column( PersistenceOccurrenceRepository::candidate_rows( $two, 0, 25 ), 'activity_id' ) );
	}

	public function test_historical_external_and_other_entity_applies_are_not_candidates(): void {
		$this->assertTrue( class_exists( PersistenceOccurrenceRepository::class ) );
		PersistenceOccurrenceRepository::install();
		$historical = $this->apply_entry( 'historical' );
		unset( $historical['applyLane'] );
		Repository::create( $historical );
		Repository::create( array_replace( $this->apply_entry( 'external' ), [ 'applyLane' => 'server-executed' ] ) );
		$other                         = $this->apply_entry( 'other' );
		$other['document']['entityId'] = '99';
		$other['document']['scopeKey'] = 'post:99';
		Repository::create( $other );
		$this->assertNull( PersistenceOccurrenceRepository::capture( $this->snapshot( 'save-empty', 'content' ) ) );
	}

	public function test_capture_replay_does_not_replace_the_saved_version(): void {
		$this->assertTrue( class_exists( PersistenceOccurrenceRepository::class ) );
		PersistenceOccurrenceRepository::install();
		Repository::create( $this->apply_entry( 'first' ) );
		$first  = PersistenceOccurrenceRepository::capture( $this->snapshot( 'save-one', 'original' ) );
		$replay = PersistenceOccurrenceRepository::capture( $this->snapshot( 'save-one', 'different' ) );
		$this->assertSame( $first['snapshotId'], $replay['snapshotId'] );
		$this->assertSame( 'original', $replay['content'] );
	}

	private function snapshot( string $occurrence, string $content ): array {
		return [
			'saveOccurrenceId' => $occurrence,
			'origin'           => 'unobserved',
			'entity'           => [
				'type'     => 'post',
				'postType' => 'post',
				'ref'      => '42',
				'postId'   => 42,
			],
			'content'          => $content,
			'schemas'          => [
				'core/heading' => [
					'level' => [
						'type'    => 'number',
						'default' => 2,
					],
				],
			],
			'reason'           => 'saved',
		];
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
			'after'     => [ 'attributes' => [ 'level' => 3 ] ],
			'document'  => [
				'scopeKey' => 'post:42',
				'postType' => 'post',
				'entityId' => '42',
			],
		];
	}
}
