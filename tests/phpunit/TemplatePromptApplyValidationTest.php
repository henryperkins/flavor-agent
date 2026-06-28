<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\TemplatePrompt;
use PHPUnit\Framework\TestCase;

final class TemplatePromptApplyValidationTest extends TestCase {

	/**
	 * Minimal live for_template() context: one registered candidate pattern and a
	 * top-level block tree with a single group so start/end anchors and a
	 * before/after path are all resolvable.
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		return [
			'templateType'             => 'home',
			'patterns'                 => [
				[ 'name' => 'twentytwentyfive/hero', 'title' => 'Hero' ],
			],
			'topLevelBlockTree'        => [
				[ 'path' => [ 0 ], 'blockName' => 'core/group', 'innerBlocks' => [] ],
			],
			'topLevelInsertionAnchors' => [
				'start' => [ 'placement' => 'start' ],
				'end'   => [ 'placement' => 'end' ],
			],
		];
	}

	public function test_valid_start_insert_pattern_passes_with_no_expected_target(): void {
		$result = TemplatePrompt::validate_operations_for_apply(
			[
				[ 'type' => 'insert_pattern', 'patternName' => 'twentytwentyfive/hero', 'placement' => 'start' ],
			],
			$this->context()
		);

		$this->assertCount( 1, $result['operations'] );
		$this->assertSame( 'insert_pattern', $result['operations'][0]['type'] );
		$this->assertArrayNotHasKey( 'targetPath', $result['operations'][0] );
		$this->assertSame( [], $result['reasons'] );
	}

	public function test_unknown_pattern_is_rejected_with_a_reason(): void {
		$result = TemplatePrompt::validate_operations_for_apply(
			[
				[ 'type' => 'insert_pattern', 'patternName' => 'nope/missing', 'placement' => 'start' ],
			],
			$this->context()
		);

		$this->assertSame( [], $result['operations'] );
		$this->assertNotEmpty( $result['reasons'] );
		$this->assertSame( 'unknown_pattern', $result['reasons'][0] );
	}
}
