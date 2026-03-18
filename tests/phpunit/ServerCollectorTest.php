<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ServerCollectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		WordPressTestState::$block_templates = [
			'wp_template_part' => [
				(object) [
					'slug'    => 'header',
					'title'   => 'Header',
					'area'    => 'header',
					'content' => '<!-- wp:group -->Header<!-- /wp:group -->',
				],
				(object) [
					'slug'    => 'footer',
					'title'   => 'Footer',
					'area'    => 'footer',
					'content' => '<!-- wp:group -->Footer<!-- /wp:group -->',
				],
			],
		];
	}

	public function test_for_template_parts_can_skip_content_when_only_metadata_is_needed(): void {
		$result = ServerCollector::for_template_parts( null, false );

		$this->assertSame(
			[
				[
					'slug'  => 'header',
					'title' => 'Header',
					'area'  => 'header',
				],
				[
					'slug'  => 'footer',
					'title' => 'Footer',
					'area'  => 'footer',
				],
			],
			$result
		);
	}

	public function test_for_template_part_areas_returns_slug_to_area_lookup(): void {
		$this->assertSame(
			[
				'header' => 'header',
				'footer' => 'footer',
			],
			ServerCollector::for_template_part_areas()
		);
	}
}
