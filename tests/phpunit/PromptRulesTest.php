<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\Prompt;
use PHPUnit\Framework\TestCase;

final class PromptRulesTest extends TestCase {

	public function test_enforce_block_context_rules_returns_empty_payload_for_disabled_blocks(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[ 'label' => 'Toggle setting' ],
				],
				'styles'      => [
					[ 'label' => 'Accent background' ],
				],
				'block'       => [
					[ 'label' => 'Edit content' ],
				],
				'explanation' => 'Should not survive disabled mode.',
			],
			[
				'editingMode' => 'disabled',
			]
		);

		$this->assertSame(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [],
				'explanation' => '',
			],
			$result
		);
	}

	public function test_enforce_block_context_rules_filters_non_content_updates_for_block_level_content_only(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[
						'label'            => 'Toggle setting',
						'attributeUpdates' => [ 'dropCap' => true ],
					],
				],
				'styles'      => [
					[
						'label'            => 'Accent background',
						'attributeUpdates' => [ 'backgroundColor' => 'accent' ],
					],
				],
				'block'       => [
					[
						'label'            => 'Update copy',
						'attributeUpdates' => [
							'content'         => 'Shorter copy',
							'backgroundColor' => 'accent',
						],
					],
				],
				'explanation' => 'Content-only output.',
			],
			[
				'editingMode'       => 'contentOnly',
				'contentAttributes' => [
					'content' => [
						'role' => 'content',
					],
				],
			]
		);

		$this->assertSame( [], $result['settings'] );
		$this->assertSame( [], $result['styles'] );
		$this->assertSame(
			[
				[
					'label'            => 'Update copy',
					'attributeUpdates' => [
						'content' => 'Shorter copy',
					],
				],
			],
			$result['block']
		);
		$this->assertSame( 'Content-only output.', $result['explanation'] );
	}

	public function test_enforce_block_context_rules_keeps_container_level_content_only_behavior(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [
					[
						'label'            => 'Update copy',
						'attributeUpdates' => [
							'content'  => 'Updated',
							'metadata' => [ 'foo' => 'bar' ],
						],
					],
				],
				'explanation' => 'Container lock.',
			],
			[
				'isInsideContentOnly' => true,
				'contentAttributes'   => [
					'content' => [
						'role' => 'content',
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'label'            => 'Update copy',
					'attributeUpdates' => [
						'content' => 'Updated',
					],
				],
			],
			$result['block']
		);
		$this->assertSame( 'Container lock.', $result['explanation'] );
	}
}
