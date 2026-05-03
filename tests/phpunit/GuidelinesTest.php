<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Guidelines;
use FlavorAgent\LLM\Prompt;
use FlavorAgent\LLM\WritingPrompt;
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

	public function test_get_all_prefers_core_guidelines_storage_when_available(): void {
		WordPressTestState::$registered_post_types['wp_guideline']      = [
			'show_in_rest' => true,
		];
		WordPressTestState::$registered_taxonomies['wp_guideline_type'] = [
			'object_type' => 'wp_guideline',
		];
		WordPressTestState::$options                                    = [
			Guidelines::OPTION_SITE   => 'Legacy site context.',
			Guidelines::OPTION_BLOCKS => [
				'core/paragraph' => 'Legacy paragraph rule.',
			],
		];
		WordPressTestState::$posts                                      = [
			101 => (object) [
				'ID'            => 101,
				'post_type'     => 'wp_guideline',
				'post_status'   => 'publish',
				'post_date_gmt' => '2026-04-28 10:00:00',
			],
		];
		WordPressTestState::$post_meta                                  = [
			101 => [
				'_guideline_site'                 => 'Core site context.',
				'_guideline_copy'                 => 'Core copy rule.',
				'_guideline_images'               => 'Core image rule.',
				'_guideline_additional'           => 'Core additional rule.',
				'_guideline_block_core_paragraph' => 'Core paragraph rule.',
			],
		];

		$this->assertSame(
			[
				'site'       => 'Core site context.',
				'copy'       => 'Core copy rule.',
				'images'     => 'Core image rule.',
				'additional' => 'Core additional rule.',
				'blocks'     => [
					'core/paragraph' => 'Core paragraph rule.',
				],
			],
			Guidelines::get_all()
		);
		$this->assertSame( 'wp_guideline', WordPressTestState::$get_posts_calls[0]['post_type'] ?? '' );
	}

	public function test_storage_status_reports_active_core_repository(): void {
		WordPressTestState::$registered_post_types['wp_guideline'] = [
			'show_in_rest' => true,
		];

		$this->assertSame(
			[
				'source'              => 'core',
				'core_available'      => true,
				'legacy_has_data'     => false,
				'migration_status'    => 'not_started',
				'migration_completed' => false,
			],
			Guidelines::storage_status()
		);
	}

	public function test_storage_status_reports_legacy_fallback_when_core_unavailable(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_COPY => 'Use active voice.',
		];

		$this->assertSame(
			[
				'source'              => 'legacy_options',
				'core_available'      => false,
				'legacy_has_data'     => true,
				'migration_status'    => 'not_started',
				'migration_completed' => false,
			],
			Guidelines::storage_status()
		);
	}

	public function test_block_prompt_includes_site_and_matching_block_guidelines(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_SITE       => 'Marketing site for enterprise buyers.',
			Guidelines::OPTION_COPY       => 'Use direct, plain language.',
			Guidelines::OPTION_IMAGES     => 'Prefer documentary photography.',
			Guidelines::OPTION_ADDITIONAL => 'Avoid discount language.',
			Guidelines::OPTION_BLOCKS     => [
				'core/paragraph' => 'Keep paragraphs under three sentences.',
				'core/image'     => 'Always include descriptive alt text.',
			],
		];

		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name'            => 'core/paragraph',
					'title'           => 'Paragraph',
					'inspectorPanels' => [
						'typography' => true,
					],
				],
				'themeTokens' => [],
			]
		);

		$this->assertStringContainsString( '## Site Guidelines', $prompt );
		$this->assertStringContainsString( 'Site: Marketing site for enterprise buyers.', $prompt );
		$this->assertStringContainsString( 'Copy: Use direct, plain language.', $prompt );
		$this->assertStringContainsString( 'Images: Prefer documentary photography.', $prompt );
		$this->assertStringContainsString( 'Additional: Avoid discount language.', $prompt );
		$this->assertStringContainsString( 'Block core/paragraph: Keep paragraphs under three sentences.', $prompt );
		$this->assertStringNotContainsString( 'Always include descriptive alt text.', $prompt );
	}

	public function test_writing_prompt_includes_site_guidelines(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_SITE => 'Audience is technical operators.',
			Guidelines::OPTION_COPY => 'Use active voice.',
		];

		$prompt = WritingPrompt::build_user(
			[
				'mode'        => 'draft',
				'postContext' => [
					'postType' => 'post',
				],
			],
			'Draft a launch note.'
		);

		$this->assertStringContainsString( '## Site Guidelines', $prompt );
		$this->assertStringContainsString( 'Site: Audience is technical operators.', $prompt );
		$this->assertStringContainsString( 'Copy: Use active voice.', $prompt );
	}

	public function test_prompt_context_prefers_upstream_wordpress_ai_guidelines_when_available(): void {
		WordPressTestState::$options                   = [
			Guidelines::OPTION_SITE => 'Legacy site context.',
		];
		WordPressTestState::$wpai_formatted_guidelines = '<guidelines><site>Upstream site policy.</site></guidelines>';

		$prompt_context = Guidelines::format_prompt_context( 'core/paragraph' );

		$this->assertSame( '<guidelines><site>Upstream site policy.</site></guidelines>', $prompt_context );
		$this->assertSame(
			[
				[
					'categories' => [ 'site', 'copy', 'images', 'additional' ],
					'blockName'  => 'core/paragraph',
				],
			],
			WordPressTestState::$wpai_guideline_calls
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
