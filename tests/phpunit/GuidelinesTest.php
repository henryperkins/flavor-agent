<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Guidelines;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class GuidelinesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_sanitize_block_guidelines_accepts_json_and_drops_invalid_entries(): void {
		$input = wp_json_encode(
			[
				'core/paragraph' => [
					'guidelines' => ' Use short paragraphs. ',
				],
				'core/list'      => ' Prefer bulleted lists. ',
				'core/image'     => [
					'guidelines' => '',
				],
				'bad block name' => [
					'guidelines' => 'Ignore this entry.',
				],
				'core/quote'     => [
					'guidelines' => '   ',
				],
			]
		);

		$this->assertSame(
			[
				'core/list'      => 'Prefer bulleted lists.',
				'core/paragraph' => 'Use short paragraphs.',
			],
			Guidelines::sanitize_block_guidelines( $input )
		);
	}

	public function test_export_payload_returns_gutenberg_compatible_shape(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_SITE       => 'Marketing site for a design studio.',
			Guidelines::OPTION_COPY       => 'Use active voice.',
			Guidelines::OPTION_IMAGES     => 'Prefer candid team photography.',
			Guidelines::OPTION_ADDITIONAL => 'Avoid lorem ipsum.',
			Guidelines::OPTION_BLOCKS     => [
				'core/paragraph' => 'Keep paragraphs under three sentences.',
				'core/quote'     => 'Pull quotes should stay under 30 words.',
			],
		];

		$this->assertSame(
			[
				'guideline_categories' => [
					'site'       => [
						'guidelines' => 'Marketing site for a design studio.',
					],
					'copy'       => [
						'guidelines' => 'Use active voice.',
					],
					'images'     => [
						'guidelines' => 'Prefer candid team photography.',
					],
					'additional' => [
						'guidelines' => 'Avoid lorem ipsum.',
					],
					'blocks'     => [
						'core/paragraph' => [
							'guidelines' => 'Keep paragraphs under three sentences.',
						],
						'core/quote'     => [
							'guidelines' => 'Pull quotes should stay under 30 words.',
						],
					],
				],
			],
			Guidelines::export_payload()
		);
	}

	public function test_get_content_block_options_returns_only_blocks_with_content_role_attributes(): void {
		$registry = \WP_Block_Type_Registry::get_instance();

		$registry->register(
			'core/paragraph',
			[
				'title'      => 'Paragraph',
				'attributes' => [
					'content' => [
						'role' => 'content',
					],
				],
			]
		);
		$registry->register(
			'flavor/card-copy',
			[
				'title'      => 'Card Copy',
				'attributes' => [
					'body' => [
						'role' => 'content',
					],
				],
			]
		);
		$registry->register(
			'core/image',
			[
				'title'      => 'Image',
				'attributes' => [
					'url' => [
						'type' => 'string',
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'value' => 'flavor/card-copy',
					'label' => 'Card Copy',
				],
				[
					'value' => 'core/paragraph',
					'label' => 'Paragraph',
				],
			],
			Guidelines::get_content_block_options()
		);
	}
}
