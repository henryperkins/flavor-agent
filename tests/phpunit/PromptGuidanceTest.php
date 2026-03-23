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

	public function test_block_system_prompt_allows_filter_panel_in_appearance_guidance(): void {
		$prompt = Prompt::build_system();

		$this->assertStringContainsString(
			'general|layout|position|advanced|color|filter|typography|dimensions|border|shadow|background',
			$prompt
		);
		$this->assertStringContainsString(
			'Appearance tab (color, filter, typography, dimensions, border, shadow, background, style variations)',
			$prompt
		);
	}

	public function test_block_prompt_includes_duotone_token_summary_when_available(): void {
		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name' => 'core/image',
				],
				'themeTokens' => [
					'duotone' => [ 'midnight: #111111 / #f5f5f5' ],
				],
			]
		);

		$this->assertStringContainsString(
			'Duotone presets: midnight: #111111 / #f5f5f5',
			$prompt
		);
	}

	public function test_block_system_prompt_mentions_aspect_ratio_and_height_exclusivity(): void {
		$prompt = Prompt::build_system();

		$this->assertStringContainsString(
			'Choose aspectRatio or height, not both.',
			$prompt
		);
	}
}
