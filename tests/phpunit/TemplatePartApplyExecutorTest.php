<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Apply\TemplatePartApplyExecutor;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class TemplatePartApplyExecutorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
	}

	/**
	 * Seed the live part into the get_block_template(s) stub store so the bound
	 * TemplateRepository::resolve_template_part resolves it. No filter seam.
	 */
	private function seed_part( string $id, string $content, int $wp_id = 0 ): void {
		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'id'      => $id,
				'wp_id'   => $wp_id,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Header',
				'content' => $content,
			],
		];
	}

	public function test_resolve_baseline_hashes_reserialized_content(): void {
		$content = '<!-- wp:navigation /-->';
		$this->seed_part( 'twentytwentyfive//header', $content );

		$hash = TemplatePartApplyExecutor::resolve_baseline(
			[
				'surface' => 'template-part',
				'target'  => [ 'templatePartId' => 'twentytwentyfive//header' ],
			]
		);

		$this->assertSame(
			hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) ),
			$hash
		);
	}

	public function test_resolve_baseline_errors_when_part_missing(): void {
		$result = TemplatePartApplyExecutor::resolve_baseline(
			[
				'surface' => 'template-part',
				'target'  => [ 'templatePartId' => 'no//such' ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}

	public function test_resolve_baseline_fails_closed_on_missing_identifier(): void {
		$result = TemplatePartApplyExecutor::resolve_baseline(
			[ 'surface' => 'template-part' ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}
}
