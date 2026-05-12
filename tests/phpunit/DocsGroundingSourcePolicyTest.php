<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\DocsGroundingSourcePolicy;
use PHPUnit\Framework\TestCase;

final class DocsGroundingSourcePolicyTest extends TestCase {

	public function test_classifies_trusted_official_sources(): void {
		$this->assertSame(
			'developer-docs',
			DocsGroundingSourcePolicy::classify_url( 'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/' )
		);
		$this->assertSame(
			'developer-docs',
			DocsGroundingSourcePolicy::classify_url( 'https://developer.wordpress.org/block-editor' )
		);
		$this->assertSame(
			'developer-blog',
			DocsGroundingSourcePolicy::classify_url( 'https://developer.wordpress.org/news/2026/04/whats-new-for-developers-april-2026/' )
		);
		$this->assertSame(
			'make-core',
			DocsGroundingSourcePolicy::classify_url( 'https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/' )
		);
	}

	public function test_rejects_untrusted_hosts_and_paths(): void {
		$this->assertSame( '', DocsGroundingSourcePolicy::classify_url( 'https://example.com/core/2026/03/24/fake/' ) );
		$this->assertSame( '', DocsGroundingSourcePolicy::classify_url( 'https://make.wordpress.org/polyglots/files/2023/02/template.pdf' ) );
		$this->assertSame( '', DocsGroundingSourcePolicy::classify_url( 'http://developer.wordpress.org/block-editor/' ) );
		$this->assertSame( '', DocsGroundingSourcePolicy::classify_url( 'https://developer.wordpress.org/../wp-admin/' ) );
	}

	public function test_rejects_credentialed_urls_and_non_default_ports(): void {
		$this->assertSame(
			'',
			DocsGroundingSourcePolicy::classify_url( 'https://user@developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/' )
		);
		$this->assertSame(
			'',
			DocsGroundingSourcePolicy::classify_url( 'https://user:pass@developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/' )
		);
		$this->assertSame(
			'',
			DocsGroundingSourcePolicy::classify_url( 'https://developer.wordpress.org:444/block-editor/reference-guides/block-api/block-supports/' )
		);
		$this->assertSame(
			'developer-docs',
			DocsGroundingSourcePolicy::classify_url( 'https://developer.wordpress.org:443/block-editor/reference-guides/block-api/block-supports/' )
		);
	}

	public function test_rejects_encoded_path_delimiters_and_traversal(): void {
		$this->assertSame(
			'',
			DocsGroundingSourcePolicy::classify_url( 'https://developer.wordpress.org/block-editor/foo%2F..%2Fwp-admin/' )
		);
		$this->assertSame(
			'',
			DocsGroundingSourcePolicy::classify_url( 'https://developer.wordpress.org/block-editor/%2e%2e/wp-admin/' )
		);
		$this->assertSame(
			'',
			DocsGroundingSourcePolicy::classify_url( 'https://developer.wordpress.org/block-editor/foo%5Cbar/' )
		);
		$this->assertSame(
			'developer-docs',
			DocsGroundingSourcePolicy::classify_url( 'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/' )
		);
	}

	public function test_freshness_windows_are_source_specific(): void {
		$now = strtotime( '2026-05-11T00:00:00Z' );

		$this->assertSame(
			'current',
			DocsGroundingSourcePolicy::freshness_status(
				'developer-docs',
				'2026-03-16T22:13:36Z',
				'2019-05-02T23:50:55Z',
				$now
			)
		);
		$this->assertSame(
			'stale',
			DocsGroundingSourcePolicy::freshness_status(
				'make-core',
				'2026-03-16T22:13:36Z',
				'2026-03-15T22:35:08Z',
				$now
			)
		);
		$this->assertSame(
			'current',
			DocsGroundingSourcePolicy::freshness_status(
				'make-core',
				'2026-05-08T14:00:00Z',
				'2026-05-08T13:00:00Z',
				$now
			)
		);
	}

	public function test_release_cycle_freshness_uses_published_timestamp_not_retrieved_timestamp(): void {
		$now = strtotime( '2026-05-11T00:00:00Z' );

		$this->assertSame(
			'stale',
			DocsGroundingSourcePolicy::freshness_status(
				DocsGroundingSourcePolicy::SOURCE_MAKE_CORE,
				'2026-05-10T00:00:00Z',
				'2025-01-01T00:00:00Z',
				$now
			)
		);

		$this->assertSame(
			'stale',
			DocsGroundingSourcePolicy::freshness_status(
				DocsGroundingSourcePolicy::SOURCE_DEVELOPER_BLOG,
				'2026-05-10T00:00:00Z',
				'2025-01-01T00:00:00Z',
				$now
			)
		);

		$this->assertSame(
			'unknown',
			DocsGroundingSourcePolicy::freshness_status(
				DocsGroundingSourcePolicy::SOURCE_MAKE_CORE,
				'2026-05-10T00:00:00Z',
				'',
				$now
			)
		);

		$this->assertSame(
			'current',
			DocsGroundingSourcePolicy::freshness_status(
				DocsGroundingSourcePolicy::SOURCE_DEVELOPER_DOCS,
				'2026-05-10T00:00:00Z',
				'',
				$now
			)
		);
	}

	public function test_source_coverage_requires_current_release_cycle_guidance(): void {
		$now = strtotime( '2026-05-11T00:00:00Z' );

		$stable_docs_only = DocsGroundingSourcePolicy::source_coverage_summary(
			[
				[
					'sourceType'  => 'developer-docs',
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'publishedAt' => '',
					'freshness'   => 'current',
				],
			],
			$now
		);

		$this->assertSame( 'missing-current-release-cycle', $stable_docs_only['status'] );
		$this->assertTrue( $stable_docs_only['hasDeveloperDocs'] );
		$this->assertFalse( $stable_docs_only['hasCurrentReleaseCycle'] );

		$stale_make_core = DocsGroundingSourcePolicy::source_coverage_summary(
			[
				[
					'sourceType'  => 'developer-docs',
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'publishedAt' => '',
					'freshness'   => 'current',
				],
				[
					'sourceType'  => 'make-core',
					'retrievedAt' => '2026-03-16T22:13:36Z',
					'publishedAt' => '2026-03-15T22:35:08Z',
					'freshness'   => 'stale',
				],
			],
			$now
		);

		$this->assertSame( 'missing-current-release-cycle', $stale_make_core['status'] );
		$this->assertContains( 'make-core', $stale_make_core['sourceTypes'] );

		$current_make_core = DocsGroundingSourcePolicy::source_coverage_summary(
			[
				[
					'sourceType'  => 'developer-docs',
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'publishedAt' => '',
					'freshness'   => 'current',
				],
				[
					'sourceType'  => 'make-core',
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'publishedAt' => '2026-05-08T13:00:00Z',
					'freshness'   => 'current',
				],
			],
			$now
		);

		$this->assertSame( 'current', $current_make_core['status'] );
		$this->assertTrue( $current_make_core['hasCurrentReleaseCycle'] );
	}

	public function test_source_coverage_does_not_trust_recent_crawl_time_for_old_release_cycle_posts(): void {
		$coverage = DocsGroundingSourcePolicy::source_coverage_summary(
			[
				[
					'sourceType'  => 'developer-docs',
					'retrievedAt' => '2026-05-10T00:00:00Z',
					'publishedAt' => '',
					'freshness'   => 'current',
				],
				[
					'sourceType'  => 'make-core',
					'retrievedAt' => '2026-05-10T00:00:00Z',
					'publishedAt' => '2025-01-01T00:00:00Z',
					'freshness'   => 'current',
				],
			],
			strtotime( '2026-05-11T00:00:00Z' )
		);

		$this->assertSame( 'missing-current-release-cycle', $coverage['status'] );
		$this->assertTrue( $coverage['hasDeveloperDocs'] );
		$this->assertFalse( $coverage['hasCurrentReleaseCycle'] );
		$this->assertContains( 'stale', $coverage['freshness'] );
	}
}
