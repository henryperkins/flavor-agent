<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\Prompt;
use FlavorAgent\LLM\TemplatePrompt;
use PHPUnit\Framework\TestCase;

final class PromptGuidanceTest extends TestCase {

	public function test_block_prompt_includes_wordpress_guidance_section_when_available(): void {
			$prompt = Prompt::build_user(
				[
					'block'       => [
						'name' => 'core/group',
					],
					'themeTokens' => [],
				],
				'Tighten layout spacing.',
				[
					[
						'sourceKey' => 'developer.wordpress.org/block-supports',
						'excerpt'   => 'Use block supports to expose design tools instead of ad hoc attributes.',
					],
				]
			);

		$this->assertStringContainsString( '## WordPress Developer Guidance', $prompt );
		$this->assertStringContainsString( 'Use block supports to expose design tools instead of ad hoc attributes.', $prompt );
	}

	public function test_template_prompt_includes_wordpress_guidance_section_when_available(): void {
		$prompt = TemplatePrompt::build_user(
			[
				'templateType'   => 'single',
				'title'          => 'Single',
				'assignedParts'  => [],
				'emptyAreas'     => [ 'footer' ],
				'availableParts' => [],
				'patterns'       => [],
				'themeTokens'    => [],
			],
			'Improve footer composition.',
			[
				[
					'title'   => 'Template parts REST reference',
					'excerpt' => 'Template parts expose an area field that indicates where they are intended for use.',
				],
			]
		);

		$this->assertStringContainsString( '## WordPress Developer Guidance', $prompt );
		$this->assertStringContainsString( 'Template parts REST reference: Template parts expose an area field', $prompt );
	}

	public function test_block_prompt_labels_core_roadmap_guidance(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name' => 'core/group',
				],
				'themeTokens' => [],
			],
			'Align with current core work.',
			[
				[
					'title'      => 'WordPress AI roadmap status',
					'sourceType' => 'core-roadmap',
					'excerpt'    => 'Open roadmap milestones: 0.9.0, 1.0.0.',
				],
			]
		);

		$this->assertStringContainsString( 'Core roadmap - WordPress AI roadmap status: Open roadmap milestones', $prompt );
	}

	public function test_block_prompt_includes_structural_identity_sections_when_available(): void {
		$prompt = Prompt::build_user(
			[
				'block'               => [
					'name'               => 'core/navigation',
					'structuralIdentity' => [
						'role'             => 'footer-navigation',
						'job'              => 'Navigation block in the "footer" template part.',
						'location'         => 'footer',
						'templateArea'     => 'footer',
						'templatePartSlug' => 'footer',
					],
				],
				'structuralAncestors' => [
					[
						'block' => 'core/template-part',
						'role'  => 'footer-slot',
					],
				],
				'structuralBranch'    => [
					[
						'block' => 'core/template-part',
						'role'  => 'footer-slot',
					],
				],
				'themeTokens'         => [],
			]
		);

		$this->assertStringContainsString( '## Structural identity', $prompt );
		$this->assertStringContainsString( 'Resolved role: footer-navigation', $prompt );
		$this->assertStringContainsString( '## Structural ancestors', $prompt );
		$this->assertStringContainsString( '## Structural branch', $prompt );
	}

	public function test_block_prompt_describes_block_level_content_only_restrictions(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name'        => 'core/paragraph',
					'editingMode' => 'contentOnly',
					'title'       => 'Paragraph',
				],
				'themeTokens' => [],
			]
		);

		$this->assertStringContainsString(
			'## Content-only restrictions',
			$prompt
		);
		$this->assertStringContainsString(
			'This block is in contentOnly editing mode.',
			$prompt
		);
		$this->assertStringContainsString(
			'Only content attributes (role=content) can be edited.',
			$prompt
		);
	}

	public function test_block_prompt_warns_when_content_only_block_uses_inner_blocks_as_content(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name'                => 'core/navigation',
					'editingMode'         => 'contentOnly',
					'title'               => 'Navigation',
					'supportsContentRole' => true,
					'contentAttributes'   => [],
				],
				'themeTokens' => [],
			]
		);

		$this->assertStringContainsString(
			'supports.contentRole',
			$prompt
		);
		$this->assertStringContainsString(
			'Do not suggest direct wrapper attribute changes.',
			$prompt
		);
	}

	public function test_block_system_prompt_allows_filter_panel_in_appearance_guidance(): void {
		$prompt = Prompt::build_system();

		$this->assertStringContainsString(
			'general|layout|position|advanced|bindings|list|color|filter|typography|dimensions|border|shadow|background',
			$prompt
		);
		$this->assertStringContainsString(
			'Appearance tab (color, filter, typography, dimensions, border, shadow, background, style variations)',
			$prompt
		);
		$this->assertStringContainsString(
			'Settings tab (layout, alignment, position, advanced, bindings, list, general config)',
			$prompt
		);
		$this->assertStringContainsString(
			'Do not say the panels were not provided.',
			$prompt
		);
		$this->assertStringContainsString(
			'structural_recommendation|pattern_replacement',
			$prompt
		);
		$this->assertStringContainsString(
			'parent containers, structural composition, or replacing the block with a more suitable pattern',
			$prompt
		);
		$this->assertStringContainsString(
			'Only include attributeUpdates when the selected block\'s own local attributes can be changed safely.',
			$prompt
		);
		$this->assertStringContainsString(
			'Optional for executable block items when helpful; omit it for advisory structural/pattern ideas',
			$prompt
		);
		$this->assertStringContainsString(
			'emit two separate suggestions instead of combining them into one item',
			$prompt
		);
	}

	public function test_block_prompt_reports_explicitly_empty_available_panels(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name'            => 'plugin/plain-block',
					'inspectorPanels' => [],
				],
				'themeTokens' => [],
			]
		);

		$this->assertStringContainsString( 'Available panels: []', $prompt );
	}

	public function test_block_prompt_includes_duotone_token_summary_when_available(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name'               => 'core/image',
					'variations'         => [
						[
							'name'        => 'rounded',
							'title'       => 'Rounded',
							'description' => 'Rounded image treatment.',
						],
					],
					'configAttributes'   => [
						'sizeSlug' => [
							'type'    => 'string',
							'default' => 'large',
							'role'    => null,
						],
					],
					'currentAttributes'  => [
						'metadata' => [
							'bindings' => [
								'url' => [
									'source' => 'core/post-meta',
								],
							],
						],
					],
					'bindableAttributes' => [ 'content', 'url', 'linkTarget' ],
				],
				'themeTokens' => [
					'colors'          => [ 'accent: #ff5500' ],
					'colorPresets'    => [
						[
							'slug'   => 'accent',
							'color'  => '#ff5500',
							'cssVar' => 'var(--wp--preset--color--accent)',
						],
					],
					'gradients'       => [ 'sunset: linear-gradient(135deg,#f60,#fc0)' ],
					'gradientPresets' => [
						[
							'slug'     => 'sunset',
							'gradient' => 'linear-gradient(135deg,#f60,#fc0)',
							'cssVar'   => 'var(--wp--preset--gradient--sunset)',
						],
					],
					'duotone'         => [ 'midnight: #111111 / #f5f5f5' ],
					'duotonePresets'  => [
						[
							'slug'   => 'midnight',
							'colors' => [ '#111111', '#f5f5f5' ],
						],
					],
					'diagnostics'     => [
						'source' => 'server',
						'reason' => 'server-global-settings',
					],
					'enabledFeatures' => [
						'backgroundImage' => true,
					],
					'elementStyles'   => [
						'heading' => [
							'base' => [
								'text' => 'var(--wp--preset--color--contrast)',
							],
						],
					],
				],
			]
		);

		$this->assertStringContainsString(
			'Color preset details:',
			$prompt
		);
		$this->assertStringContainsString(
			'Gradient preset details:',
			$prompt
		);
		$this->assertStringContainsString(
			'Duotone presets: midnight: #111111 / #f5f5f5',
			$prompt
		);
		$this->assertStringContainsString(
			'Duotone preset details:',
			$prompt
		);
			$this->assertStringContainsString(
				'Block bindings:',
				$prompt
			);
			$this->assertStringContainsString(
				'Bindable attributes: ["content","url","linkTarget"]',
				$prompt
			);
		$this->assertStringContainsString(
			'Block variations:',
			$prompt
		);
		$this->assertStringContainsString(
			'Config attribute schema:',
			$prompt
		);
		$this->assertStringContainsString(
			'Theme feature flags:',
			$prompt
		);
		$this->assertStringContainsString(
			'Theme token diagnostics:',
			$prompt
		);
		$this->assertStringContainsString(
			'Global element styles:',
			$prompt
		);
	}

	public function test_block_system_prompt_mentions_aspect_ratio_and_height_exclusivity(): void {
		$prompt = Prompt::build_system();

		$this->assertStringContainsString(
			'Choose aspectRatio or height/minHeight, not both.',
			$prompt
		);
		$this->assertStringContainsString(
			'For duotone, use style.color.duotone.',
			$prompt
		);
	}

	public function test_block_prompt_limits_large_theme_token_collections_to_sixty_items(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name' => 'core/group',
				],
				'themeTokens' => [
					'colors'    => $this->buildTokenSequence( 'color', 65 ),
					'fontSizes' => $this->buildTokenSequence( 'font-size', 65 ),
					'spacing'   => $this->buildTokenSequence( 'spacing', 65 ),
					'shadows'   => $this->buildTokenSequence( 'shadow', 65 ),
				],
			]
		);

		$this->assertStringContainsString( 'color-59', $prompt );
		$this->assertStringNotContainsString( 'color-60', $prompt );
		$this->assertStringContainsString( 'font-size-59', $prompt );
		$this->assertStringNotContainsString( 'font-size-60', $prompt );
		$this->assertStringContainsString( 'spacing-59', $prompt );
		$this->assertStringNotContainsString( 'spacing-60', $prompt );
		$this->assertStringContainsString( 'shadow-59', $prompt );
		$this->assertStringNotContainsString( 'shadow-60', $prompt );
	}

	private function buildTokenSequence( string $prefix, int $count ): array {
		$tokens = [];

		for ( $index = 0; $index < $count; $index++ ) {
			$tokens[] = sprintf( '%s-%02d', $prefix, $index );
		}

		return $tokens;
	}
}
