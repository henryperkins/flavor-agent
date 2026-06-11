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
		$this->assertSame( [ 'available', 'sourceTypes', 'count' ], array_keys( $summary ) );
		$this->assertTrue( $summary['available'] );
		$this->assertSame( 2, $summary['count'] );
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
