<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\CollectsDocsGuidance;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class CollectsDocsGuidanceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_collect_result_recommendation_mode_uses_best_effort_and_wraps_result(): void {
		WordPressTestState::$transients           = [];
		WordPressTestState::$remote_post_response = $this->trusted_docs_response(
			[
				$this->trusted_docs_chunk(
					'developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph',
					'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph/',
					'Paragraph typography stays inside supported editor controls.',
					'2026-05-08T14:00:00Z'
				),
				$this->trusted_docs_chunk(
					'developer.wordpress.org/block-editor/reference-guides/core-blocks/heading',
					'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/heading/',
					'Heading rhythm guidance for editorial layouts.',
					'2026-05-08T14:00:00Z'
				),
			]
		);

		$result = CollectsDocsGuidance::collect_result(
			static fn( array $c, string $p ): string => 'paragraph typography',
			[ 'block' => [ 'name' => 'core/paragraph' ] ],
			'make it punchier',
			[ 'mode' => 'recommendation' ]
		);

		$this->assertTrue( $result['available'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertNotSame( '', $result['fingerprint'] );
	}

	public function test_collect_result_signature_mode_is_cache_only(): void {
		WordPressTestState::$transients           = [];
		WordPressTestState::$remote_post_response = $this->trusted_docs_response(
			[
				$this->trusted_docs_chunk(
					'developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph',
					'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph/',
					'Paragraph typography guidance.',
					'2026-05-08T14:00:00Z'
				),
			]
		);

		$result = CollectsDocsGuidance::collect_result(
			static fn( array $c, string $p ): string => 'paragraph typography',
			[ 'block' => [ 'name' => 'core/paragraph' ] ],
			'make it punchier',
			[ 'mode' => 'signature' ]
		);

		$this->assertFalse( $result['available'] );
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( [], WordPressTestState::$last_remote_post, 'signature mode must not hit the search backend' );
	}

	public function test_collect_result_roadmap_only_guidance_stays_unavailable(): void {
		$roadmap_filter = static fn(): bool => true;
		\add_filter( 'flavor_agent_enable_core_roadmap_guidance', $roadmap_filter );

		WordPressTestState::$transients           = [
			'flavor_agent_core_roadmap_guidance_v1' => [
				[
					'title'      => 'WordPress AI roadmap',
					'sourceKey'  => 'github.com/orgs/WordPress/projects/240',
					'sourceType' => 'roadmap',
					'url'        => 'https://github.com/orgs/WordPress/projects/240',
					'excerpt'    => 'Open roadmap milestones: Core Improvements.',
				],
			],
		];
		WordPressTestState::$remote_post_response = new \WP_Error( 'http_request_failed', 'down' );

		$result = CollectsDocsGuidance::collect_result(
			static fn( array $c, string $p ): string => 'paragraph typography',
			[ 'block' => [ 'name' => 'core/paragraph' ] ],
			'make it punchier',
			[ 'mode' => 'recommendation' ]
		);

		\remove_filter( 'flavor_agent_enable_core_roadmap_guidance', $roadmap_filter );

		$this->assertFalse( $result['available'], 'roadmap-only guidance must not mask an ungrounded run' );
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( [], $result['sourceTypes'] );
		$this->assertCount( 1, $result['guidance'], 'roadmap chunks still ride along for prompt assembly' );
		$this->assertSame( 'roadmap', $result['guidance'][0]['sourceType'] );
	}

	public function test_collect_result_summary_counts_docs_chunks_only(): void {
		$roadmap_filter = static fn(): bool => true;
		\add_filter( 'flavor_agent_enable_core_roadmap_guidance', $roadmap_filter );

		WordPressTestState::$transients           = [
			'flavor_agent_core_roadmap_guidance_v1' => [
				[
					'title'      => 'WordPress AI roadmap',
					'sourceKey'  => 'github.com/orgs/WordPress/projects/240',
					'sourceType' => 'roadmap',
					'url'        => 'https://github.com/orgs/WordPress/projects/240',
					'excerpt'    => 'Open roadmap milestones: Core Improvements.',
				],
			],
		];
		WordPressTestState::$remote_post_response = $this->trusted_docs_response(
			[
				$this->trusted_docs_chunk(
					'developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph',
					'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/paragraph/',
					'Paragraph typography guidance.',
					'2026-05-08T14:00:00Z'
				),
			]
		);

		$result = CollectsDocsGuidance::collect_result(
			static fn( array $c, string $p ): string => 'paragraph typography',
			[ 'block' => [ 'name' => 'core/paragraph' ] ],
			'make it punchier',
			[ 'mode' => 'recommendation' ]
		);

		\remove_filter( 'flavor_agent_enable_core_roadmap_guidance', $roadmap_filter );

		$this->assertTrue( $result['available'] );
		$this->assertSame( 1, $result['count'], 'count covers docs chunks only' );
		$this->assertSame( [ 'developer-docs' ], $result['sourceTypes'] );
		$this->assertCount( 2, $result['guidance'], 'merged guidance keeps roadmap + docs chunks' );
	}

	/**
	 * @param array<int, array<string, mixed>> $chunks
	 * @return array<string, mixed>
	 */
	private function trusted_docs_response( array $chunks ): array {
		return [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => $chunks,
					],
				]
			),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function trusted_docs_chunk(
		string $source_key,
		string $url,
		string $excerpt,
		string $retrieved_at,
		string $published_at = ''
	): array {
		$frontmatter = "---\nsource_url: \"{$url}\"\nretrieved_at: \"{$retrieved_at}\"\ncontent_hash: docs-collect-test";

		if ( '' !== $published_at ) {
			$frontmatter .= "\npublished_at: \"{$published_at}\"";
		}

		return [
			'id'   => md5( $source_key . $url ),
			'item' => [
				'key'      => $source_key,
				'metadata' => [],
			],
			'text' => $frontmatter . "\n---\n{$excerpt}",
		];
	}
}
