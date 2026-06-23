<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\DocsGuidanceResult;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class DocsGuidanceResultTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_from_guidance_reports_available_with_labels_and_fingerprint(): void {
		$guidance = [
			[
				'url'         => 'https://developer.wordpress.org/block-editor/',
				'sourceType'  => 'developer-docs',
				'excerpt'     => 'x',
				'contentHash' => 'a',
			],
			[
				'url'         => 'https://make.wordpress.org/core/2026/05/07/x/',
				'sourceType'  => 'make-core',
				'excerpt'     => 'y',
				'contentHash' => 'b',
			],
		];
		$result   = DocsGuidanceResult::from_guidance( $guidance, 'recommendation', 'best-effort' );

		$this->assertTrue( $result['available'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertEqualsCanonicalizing( [ 'developer-docs', 'make-core' ], $result['sourceTypes'] );
		$this->assertNotSame( '', $result['fingerprint'] );
		$this->assertSame( 'recommendation', $result['mode'] );
		$this->assertSame( 'best-effort', $result['transport'] );

		$summary = DocsGuidanceResult::public_summary( $result );
		$this->assertSame(
			[ 'available', 'sourceTypes', 'count', 'contentFingerprint', 'runtimeFingerprint', 'reason', 'source', 'errorCode' ],
			array_keys( $summary )
		);
		$this->assertTrue( $summary['available'] );
		$this->assertSame( 2, $summary['count'] );
		$this->assertSame( $result['contentFingerprint'], $summary['contentFingerprint'] );
		$this->assertSame( $result['runtimeFingerprint'], $summary['runtimeFingerprint'] );
		$this->assertSame( 'grounded', $summary['reason'] );
	}

	public function test_from_guidance_empty_is_unavailable_with_stable_fingerprint(): void {
		$a = DocsGuidanceResult::from_guidance( [], 'recommendation', 'best-effort' );
		$b = DocsGuidanceResult::from_guidance( [], 'recommendation', 'best-effort' );

		$this->assertFalse( $a['available'] );
		$this->assertSame( 0, $a['count'] );
		$this->assertSame( $a['fingerprint'], $b['fingerprint'] );

		$summary = DocsGuidanceResult::public_summary( $a );
		$this->assertFalse( $summary['available'] );
		$this->assertSame( 0, $summary['count'] );
		$this->assertSame( [], $summary['sourceTypes'] );
		$this->assertSame( 'unavailable', $summary['reason'] );
	}

	public function test_fingerprint_changes_when_guidance_content_changes(): void {
		$base    = [
			[
				'url'         => 'https://developer.wordpress.org/block-editor/',
				'sourceType'  => 'developer-docs',
				'excerpt'     => 'x',
				'contentHash' => 'a',
			],
		];
		$changed = [
			[
				'url'         => 'https://developer.wordpress.org/block-editor/',
				'sourceType'  => 'developer-docs',
				'excerpt'     => 'x',
				'contentHash' => 'b',
			],
		];

		$this->assertNotSame(
			DocsGuidanceResult::from_guidance( $base, 'recommendation', 'best-effort' )['fingerprint'],
			DocsGuidanceResult::from_guidance( $changed, 'recommendation', 'best-effort' )['fingerprint']
		);
	}

	public function test_content_fingerprint_ignores_runtime_fields_and_runtime_fingerprint_tracks_them(): void {
		$base            = [
			[
				'url'         => 'https://developer.wordpress.org/block-editor/',
				'sourceType'  => 'developer-docs',
				'excerpt'     => 'Block editor guidance.',
				'contentHash' => 'docs-content-a',
				'publishedAt' => '2026-05-01T00:00:00Z',
				'freshness'   => 'current',
				'retrievedAt' => '2026-05-08T14:00:00Z',
				'score'       => 0.91,
			],
		];
		$runtime_changed = [
			array_merge(
				$base[0],
				[
					'retrievedAt' => '2026-05-09T14:00:00Z',
					'score'       => 0.42,
				]
			),
		];

		$first  = DocsGuidanceResult::from_guidance( $base, 'recommendation', 'best-effort' );
		$second = DocsGuidanceResult::from_guidance( $runtime_changed, 'recommendation', 'best-effort' );

		$this->assertSame( $first['contentFingerprint'], $second['contentFingerprint'] );
		$this->assertSame( $first['fingerprint'], $first['contentFingerprint'] );
		$this->assertSame( $first['contentFingerprint'], DocsGuidanceResult::content_fingerprint( $first ) );
		$this->assertNotSame( $first['runtimeFingerprint'], $second['runtimeFingerprint'] );
		$this->assertSame( $second['runtimeFingerprint'], DocsGuidanceResult::runtime_fingerprint( $second ) );
	}

	public function test_content_fingerprint_changes_when_docs_currentness_fields_change(): void {
		$base    = [
			[
				'url'         => 'https://developer.wordpress.org/block-editor/',
				'sourceType'  => 'developer-docs',
				'excerpt'     => 'Block editor guidance.',
				'contentHash' => 'docs-content-a',
				'publishedAt' => '2026-05-01T00:00:00Z',
				'freshness'   => 'current',
			],
		];
		$changed = [
			array_merge(
				$base[0],
				[
					'freshness' => 'stale',
				]
			),
		];

		$this->assertNotSame(
			DocsGuidanceResult::from_guidance( $base, 'recommendation', 'best-effort' )['contentFingerprint'],
			DocsGuidanceResult::from_guidance( $changed, 'recommendation', 'best-effort' )['contentFingerprint']
		);
	}

	public function test_fingerprint_is_stable_across_chunk_order(): void {
		$first  = [
			'url'         => 'https://developer.wordpress.org/block-editor/',
			'sourceType'  => 'developer-docs',
			'excerpt'     => 'x',
			'contentHash' => 'a',
		];
		$second = [
			'url'         => 'https://make.wordpress.org/core/2026/05/07/x/',
			'sourceType'  => 'make-core',
			'excerpt'     => 'y',
			'contentHash' => 'b',
		];

		$this->assertSame(
			DocsGuidanceResult::from_guidance( [ $first, $second ], 'recommendation', 'best-effort' )['fingerprint'],
			DocsGuidanceResult::from_guidance( [ $second, $first ], 'recommendation', 'best-effort' )['fingerprint']
		);
	}

	public function test_prompt_guidance_does_not_affect_summary_fields(): void {
		$docs_chunk    = [
			'url'         => 'https://developer.wordpress.org/block-editor/',
			'sourceType'  => 'developer-docs',
			'excerpt'     => 'x',
			'contentHash' => 'a',
		];
		$roadmap_chunk = [
			'url'        => 'https://github.com/orgs/WordPress/projects/240',
			'sourceType' => 'roadmap',
			'excerpt'    => 'roadmap milestones',
		];

		$result = DocsGuidanceResult::from_guidance(
			[ $docs_chunk ],
			'recommendation',
			'best-effort',
			[ $roadmap_chunk, $docs_chunk ]
		);

		$this->assertTrue( $result['available'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( [ 'developer-docs' ], $result['sourceTypes'] );
		$this->assertCount( 2, DocsGuidanceResult::guidance( $result ) );
		$this->assertSame(
			DocsGuidanceResult::from_guidance( [ $docs_chunk ], 'recommendation', 'best-effort' )['fingerprint'],
			$result['fingerprint'],
			'fingerprint must cover docs chunks only'
		);

		$roadmap_only = DocsGuidanceResult::from_guidance(
			[],
			'recommendation',
			'best-effort',
			[ $roadmap_chunk ]
		);

		$this->assertFalse( $roadmap_only['available'] );
		$this->assertSame( 0, $roadmap_only['count'] );
		$this->assertSame( [], $roadmap_only['sourceTypes'] );
		$this->assertCount( 1, DocsGuidanceResult::guidance( $roadmap_only ) );
		$this->assertFalse( DocsGuidanceResult::public_summary( $roadmap_only )['available'] );
	}

	public function test_guidance_accessor_returns_normalized_chunks(): void {
		$guidance = [
			[
				'url'        => 'https://developer.wordpress.org/block-editor/',
				'sourceType' => 'developer-docs',
				'excerpt'    => 'x',
			],
			'not-a-chunk',
		];

		$result = DocsGuidanceResult::from_guidance( $guidance, 'recommendation', 'best-effort' );

		$this->assertCount( 1, DocsGuidanceResult::guidance( $result ) );
		$this->assertSame( [], DocsGuidanceResult::guidance( [] ) );
	}
}
