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

	public function test_parse_response_normalizes_list_view_panel_aliases(): void {
		$result = Prompt::parse_response(
			wp_json_encode(
				[
					'settings'    => [
						[
							'label'            => 'Open list items by default',
							'panel'            => 'list-view',
							'attributeUpdates' => [ 'ordered' => true ],
						],
					],
					'styles'      => [],
					'block'       => [],
					'explanation' => 'List view routing should be normalized.',
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'list', $result['settings'][0]['panel'] );
	}

	public function test_enforce_block_context_rules_strips_unsupported_bindings_for_unlocked_blocks(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[
						'label'            => 'Connect CTA fields',
						'attributeUpdates' => [
							'metadata' => [
								'name'     => 'Hero CTA',
								'bindings' => [
									'url'  => [
										'source' => 'core/post-meta',
										'args'   => [ 'key' => 'cta_url' ],
									],
									'text' => [
										'source' => 'core/post-meta',
										'args'   => [ 'key' => 'cta_label' ],
									],
								],
							],
						],
					],
				],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Only supported binding targets should survive.',
			],
			[
				'bindableAttributes' => [ 'url' ],
			]
		);

		$this->assertSame(
			[
				[
					'label'            => 'Connect CTA fields',
					'attributeUpdates' => [
						'metadata' => [
							'name'     => 'Hero CTA',
							'bindings' => [
								'url' => [
									'source' => 'core/post-meta',
									'args'   => [ 'key' => 'cta_url' ],
								],
							],
						],
					],
				],
			],
			$result['settings']
		);
	}

	public function test_enforce_block_context_rules_drops_binding_only_suggestions_when_no_bindable_attributes_are_allowed(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [
					[
						'label'            => 'Connect CTA fields',
						'attributeUpdates' => [
							'metadata' => [
								'name'     => 'Hero CTA',
								'bindings' => [
									'url' => [
										'source' => 'core/post-meta',
										'args'   => [ 'key' => 'cta_url' ],
									],
								],
							],
						],
					],
				],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Unsupported bindings should not degrade into renames.',
			],
			[
				'bindableAttributes' => [],
			]
		);

		$this->assertSame( [], $result['settings'] );
		$this->assertSame( 'Unsupported bindings should not degrade into renames.', $result['explanation'] );
	}
}
