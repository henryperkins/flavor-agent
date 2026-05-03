<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\BlockTypeIntrospector;
use PHPUnit\Framework\TestCase;

final class BlockTypeIntrospectorTest extends TestCase {

	public function test_extract_active_style_matches_exact_style_tokens(): void {
		$introspector = new BlockTypeIntrospector();
		$styles       = [
			[ 'name' => 'outline' ],
			[ 'name' => 'rounded' ],
		];

		$this->assertSame(
			'outline',
			$introspector->extract_active_style( 'wp-block is-style-outline has-text-color', $styles )
		);
		$this->assertNull(
			$introspector->extract_active_style( 'wp-block is-style-outline-extra', $styles )
		);
		$this->assertSame(
			'rounded',
			$introspector->extract_active_style( 'is-style-rounded is-style-outline-extra', $styles )
		);
	}
}
