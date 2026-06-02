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

	public function test_current_guidance_returns_grounded_status_and_stable_fingerprint(): void {
		$guidance = [
			[
				'url'         => 'https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/',
				'sourceType'  => 'make-core',
				'contentHash' => 'abc123',
				'freshness'   => 'current',
			],
		];

		$result = DocsGuidanceResult::from_guidance( $guidance, 'fresh', 'foreground' );

		$this->assertSame( 'grounded', $result['status'] );
		$this->assertSame( [ 'make-core' ], $result['sourceTypes'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['fingerprint'] );
	}

	public function test_empty_guidance_returns_unavailable_status(): void {
		$result = DocsGuidanceResult::from_guidance( [], 'none', 'cache' );

		$this->assertSame( 'unavailable', $result['status'] );
		$this->assertSame( [], $result['guidance'] );
	}

	public function test_fingerprint_is_stable_across_recommendation_and_signature_modes(): void {
		$guidance = [
			[
				'id'          => 'block-supports',
				'title'       => 'Block supports',
				'sourceKey'   => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
				'sourceType'  => 'developer-docs',
				'url'         => 'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/',
				'excerpt'     => 'Use block supports to expose design tools.',
				'score'       => 0.9,
				'retrievedAt' => '2026-05-08T14:00:00Z',
				'publishedAt' => '',
				'contentHash' => 'block-supports-current',
				'freshness'   => 'current',
			],
		];

		$recommendation = DocsGuidanceResult::from_guidance( $guidance, 'recommendation', 'foreground-allowed' );
		$signature      = DocsGuidanceResult::from_guidance( $guidance, 'signature', 'cache-only' );

		$this->assertSame( $recommendation['fingerprint'], $signature['fingerprint'] );
		$this->assertSame( 'recommendation', $recommendation['mode'] );
		$this->assertSame( 'signature', $signature['mode'] );
	}

	public function test_roadmap_only_guidance_is_unavailable_not_actionable(): void {
		$result = DocsGuidanceResult::from_guidance(
			[
				[
					'id'         => 'roadmap-summary',
					'title'      => 'WordPress AI roadmap status',
					'sourceKey'  => 'github.com/wordpress/project-240',
					'sourceType' => 'roadmap',
					'url'        => 'https://github.com/orgs/WordPress/projects/240/views/7',
					'excerpt'    => 'Open roadmap milestones: 0.9.0.',
					'score'      => 0.9,
				],
			],
			'recommendation',
			'cache-only'
		);

		$this->assertSame( 'unavailable', $result['status'] );
		$this->assertFalse( DocsGuidanceResult::is_actionable( $result ) );
		$this->assertSame( [ 'roadmap' ], $result['sourceTypes'] );
	}

	public function test_actionable_statuses_allow_trusted_guidance_states(): void {
		foreach ( [ 'grounded', 'degraded', 'stale' ] as $status ) {
			$this->assertTrue(
				DocsGuidanceResult::is_actionable(
					[
						'status' => $status,
					]
				)
			);
		}

		$this->assertFalse(
			DocsGuidanceResult::is_actionable(
				[
					'status' => 'unavailable',
				]
			)
		);
	}

	public function test_unavailable_error_includes_public_grounding_summary(): void {
		$result = DocsGuidanceResult::from_guidance( [], 'signature', 'cache-only' );
		$error  = DocsGuidanceResult::unavailable_error( $result );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'flavor_agent_docs_grounding_unavailable', $error->get_error_code() );
		$this->assertSame( 503, $error->get_error_data()['status'] ?? null );
		$this->assertSame( 'unavailable', $error->get_error_data()['docsGrounding']['status'] ?? null );
	}

	public function test_required_source_coverage_blocks_stable_docs_only_guidance(): void {
		$result = DocsGuidanceResult::from_guidance(
			[
				[
					'sourceType'  => 'developer-docs',
					'url'         => 'https://developer.wordpress.org/block-editor/',
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'freshness'   => 'current',
				],
			],
			'recommendation',
			'foreground-allowed',
			[
				'requireCurrentSourceCoverage' => true,
				'sourceCoverage'               => [
					'status'                 => 'missing-current-release-cycle',
					'hasDeveloperDocs'       => true,
					'hasCurrentReleaseCycle' => false,
					'sourceTypes'            => [ 'developer-docs' ],
					'freshness'              => [ 'current' ],
					'checkedAt'              => '2026-05-11 00:00:00',
					'errorCode'              => 'missing_current_release_cycle',
					'errorMessage'           => 'Developer Docs grounding is missing current WordPress release-cycle sources.',
				],
			]
		);

		$this->assertSame( 'unavailable', $result['status'] );
		$this->assertSame( 'missing-current-release-cycle', $result['coverage']['status'] ?? null );
		$this->assertFalse( DocsGuidanceResult::is_actionable( $result ) );
	}

	public function test_non_required_source_coverage_warns_without_blocking_stable_guidance(): void {
		$result = DocsGuidanceResult::from_guidance(
			[
				[
					'sourceType'  => 'developer-docs',
					'url'         => 'https://developer.wordpress.org/block-editor/',
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'freshness'   => 'current',
				],
			],
			'recommendation',
			'foreground-allowed',
			[
				'requireCurrentSourceCoverage' => false,
				'sourceCoverage'               => [
					'status'                 => 'missing-current-release-cycle',
					'hasDeveloperDocs'       => true,
					'hasCurrentReleaseCycle' => false,
					'sourceTypes'            => [ 'developer-docs' ],
					'freshness'              => [ 'current' ],
					'checkedAt'              => '2026-05-11 00:00:00',
					'errorCode'              => 'missing_current_release_cycle',
					'errorMessage'           => 'Developer Docs grounding is missing current WordPress release-cycle sources.',
				],
			]
		);

		$this->assertSame( 'grounded', $result['status'] );
		$this->assertSame( 'missing-current-release-cycle', $result['coverage']['status'] ?? null );
		$this->assertTrue( DocsGuidanceResult::is_actionable( $result ) );
	}

	public function test_within_grace_coverage_satisfies_required_gate(): void {
		$result = DocsGuidanceResult::from_guidance(
			[
				[
					'sourceType'  => 'developer-docs',
					'url'         => 'https://developer.wordpress.org/block-editor/',
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'freshness'   => 'current',
				],
			],
			'recommendation',
			'foreground-allowed',
			[
				'requireCurrentSourceCoverage' => true,
				'sourceCoverage'               => [
					'status'                  => 'missing-current-release-cycle',
					'hasDeveloperDocs'        => true,
					'hasCurrentReleaseCycle'  => false,
					'sourceTypes'             => [ 'developer-docs' ],
					'freshness'               => [ 'current' ],
					'checkedAt'               => '2026-05-26 23:00:00',
					'errorCode'               => 'missing_current_release_cycle',
					'errorMessage'            => 'Developer Docs grounding is missing current WordPress release-cycle sources.',
					'withinGrace'             => true,
					'graceLastKnownCurrentAt' => '2026-05-22 12:00:00',
					'graceExpiresAt'          => '2026-05-29 12:00:00',
				],
			]
		);

		$this->assertSame( 'grounded', $result['status'] );
		$this->assertTrue( DocsGuidanceResult::is_actionable( $result ) );
		$this->assertTrue( $result['coverage']['withinGrace'] );
		$this->assertSame( '2026-05-22 12:00:00', $result['coverage']['graceLastKnownCurrentAt'] );
		$this->assertSame( '2026-05-29 12:00:00', $result['coverage']['graceExpiresAt'] );
	}

	public function test_within_grace_only_satisfies_missing_current_release_cycle_gate(): void {
		$result = DocsGuidanceResult::from_guidance(
			[
				[
					'sourceType'  => 'developer-docs',
					'url'         => 'https://developer.wordpress.org/block-editor/',
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'freshness'   => 'current',
				],
			],
			'recommendation',
			'foreground-allowed',
			[
				'requireCurrentSourceCoverage' => true,
				'sourceCoverage'               => [
					'status'      => 'unavailable',
					'errorCode'   => 'transport_failed',
					'withinGrace' => true,
				],
			]
		);

		$this->assertSame( 'unavailable', $result['status'] );
		$this->assertFalse( DocsGuidanceResult::is_actionable( $result ) );
	}

	public function test_within_grace_coverage_surfaces_in_public_summary(): void {
		$result = DocsGuidanceResult::from_guidance(
			[
				[
					'sourceType'  => 'developer-docs',
					'url'         => 'https://developer.wordpress.org/block-editor/',
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'freshness'   => 'current',
				],
			],
			'recommendation',
			'foreground-allowed',
			[
				'requireCurrentSourceCoverage' => true,
				'sourceCoverage'               => [
					'status'                  => 'missing-current-release-cycle',
					'hasDeveloperDocs'        => true,
					'hasCurrentReleaseCycle'  => false,
					'sourceTypes'             => [ 'developer-docs' ],
					'freshness'               => [ 'current' ],
					'checkedAt'               => '2026-05-26 23:00:00',
					'errorCode'               => 'missing_current_release_cycle',
					'errorMessage'            => '',
					'withinGrace'             => true,
					'graceLastKnownCurrentAt' => '2026-05-22 12:00:00',
					'graceExpiresAt'          => '2026-05-29 12:00:00',
				],
			]
		);

		$summary = DocsGuidanceResult::public_summary( $result );

		$this->assertTrue( $summary['coverage']['withinGrace'] );
		$this->assertSame( '2026-05-22 12:00:00', $summary['coverage']['graceLastKnownCurrentAt'] );
		$this->assertSame( '2026-05-29 12:00:00', $summary['coverage']['graceExpiresAt'] );
	}

	public function test_unavailable_error_fires_detection_action(): void {
		$result = DocsGuidanceResult::from_guidance( [], 'recommendation', 'foreground-allowed' );

		DocsGuidanceResult::unavailable_error( $result );

		$this->assertSame(
			1,
			WordPressTestState::$do_action_counts['flavor_agent_docs_grounding_unavailable'] ?? 0
		);
	}

	public function test_current_source_coverage_allows_stable_guidance_to_proceed(): void {
		$result = DocsGuidanceResult::from_guidance(
			[
				[
					'sourceType'  => 'developer-docs',
					'url'         => 'https://developer.wordpress.org/block-editor/',
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'freshness'   => 'current',
				],
			],
			'recommendation',
			'foreground-allowed',
			[
				'requireCurrentSourceCoverage' => true,
				'sourceCoverage'               => [
					'status'                 => 'current',
					'hasDeveloperDocs'       => true,
					'hasCurrentReleaseCycle' => true,
					'sourceTypes'            => [ 'developer-docs', 'make-core' ],
					'freshness'              => [ 'current' ],
					'checkedAt'              => '2026-05-11 00:00:00',
					'errorCode'              => '',
					'errorMessage'           => '',
				],
			]
		);

		$this->assertSame( 'grounded', $result['status'] );
		$this->assertTrue( DocsGuidanceResult::is_actionable( $result ) );
	}
}
