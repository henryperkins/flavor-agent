<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\Prompt;
use PHPUnit\Framework\TestCase;

final class PromptFormattingTest extends TestCase {

	public function test_build_user_formats_ancestors_as_readable_chain(): void {
		$context = [
			'block'               => [
				'name'               => 'core/paragraph',
				'title'              => 'Paragraph',
				'structuralIdentity' => [
					'position'     => [ 'depth' => 3 ],
					'templateArea' => 'header',
				],
			],
			'structuralAncestors' => [
				[
					'block'        => 'core/template-part',
					'templateArea' => 'header',
					'job'          => 'Header slot',
				],
				[
					'block' => 'core/group',
					'role'  => 'container',
				],
			],
			'themeTokens'         => [],
		];

		$prompt = Prompt::build_user( $context );

		$this->assertStringContainsString(
			'template-part(header) "Header slot" > core/group (container)',
			$prompt
		);
		$this->assertStringContainsString(
			'Selected block is 3 levels deep in the header template area.',
			$prompt
		);
		$this->assertStringNotContainsString( '"structuralAncestors":[', $prompt );
	}

	public function test_build_user_formats_branch_as_indented_tree(): void {
		$context = [
			'block'            => [
				'name' => 'core/heading',
			],
			'structuralBranch' => [
				[
					'block'    => 'core/template-part',
					'children' => [
						[
							'block'       => 'core/group',
							'childCount'  => 8,
							'children'    => [
								[
									'block'    => 'core/cover',
									'role'     => 'header-cover',
									'children' => [
										[ 'block' => 'core/heading', 'isSelected' => true ],
										[ 'block' => 'core/paragraph' ],
										[ 'block' => 'core/buttons' ],
									],
								],
								[
									'block' => 'core/navigation',
									'role'  => 'primary-navigation',
								],
							],
							'moreChildren' => 2,
						],
					],
				],
			],
			'themeTokens'      => [],
		];

		$prompt = Prompt::build_user( $context );

		$this->assertStringContainsString( "template-part\n", $prompt );
		$this->assertStringContainsString( 'core/group', $prompt );
		$this->assertStringContainsString( 'core/cover (header-cover)', $prompt );
		$this->assertStringContainsString( 'heading <- selected', $prompt );
		$this->assertStringContainsString( '... +2 more children not shown', $prompt );
	}

	public function test_build_user_includes_parent_container_section(): void {
		$context = [
			'block'         => [ 'name' => 'core/button' ],
			'parentContext' => [
				'block'       => 'core/cover',
				'role'        => 'header-cover',
				'job'         => 'Cover block in header',
				'childCount'  => 4,
				'visualHints' => [
					'backgroundColor' => 'contrast',
					'dimRatio'        => 80,
					'layout'          => [ 'type' => 'constrained' ],
				],
			],
			'themeTokens'   => [],
		];

		$prompt = Prompt::build_user( $context );

		$this->assertStringContainsString( '## Parent container', $prompt );
		$this->assertStringContainsString( 'cover (header-cover) "Cover block in header"', $prompt );
		$this->assertStringContainsString( 'bg:contrast', $prompt );
		$this->assertStringContainsString( 'overlay:80%', $prompt );
		$this->assertStringContainsString( 'layout:constrained', $prompt );
		$this->assertStringContainsString( '(4 children)', $prompt );
	}

	public function test_build_user_formats_sibling_summaries_with_hints(): void {
		$context = [
			'block'                   => [ 'name' => 'core/button' ],
			'siblingSummariesBefore'  => [
				[
					'block'       => 'core/paragraph',
					'role'        => 'lede',
					'visualHints' => [ 'textAlign' => 'center' ],
				],
			],
			'siblingSummariesAfter'   => [
				[
					'block'       => 'core/image',
					'visualHints' => [ 'align' => 'wide' ],
				],
			],
			'themeTokens'             => [],
		];

		$prompt = Prompt::build_user( $context );

		$this->assertStringContainsString( "Before:\n  - core/paragraph (lede; textAlign:center)", $prompt );
		$this->assertStringContainsString( "After:\n  - core/image (align:wide)", $prompt );
	}

	public function test_build_user_falls_back_to_bare_siblings_without_summaries(): void {
		$context = [
			'block'          => [ 'name' => 'core/button' ],
			'siblingsBefore' => [ 'core/heading', 'core/image' ],
			'siblingsAfter'  => [ 'core/paragraph' ],
			'themeTokens'    => [],
		];

		$prompt = Prompt::build_user( $context );

		$this->assertStringContainsString( 'Before: core/heading, core/image', $prompt );
		$this->assertStringContainsString( 'After: core/paragraph', $prompt );
	}

	public function test_build_user_omits_parent_section_when_parent_context_absent(): void {
		$context = [
			'block'        => [ 'name' => 'core/button' ],
			'themeTokens'  => [],
		];

		$prompt = Prompt::build_user( $context );

		$this->assertStringNotContainsString( '## Parent container', $prompt );
	}

	public function test_build_user_preserves_existing_sections_alongside_new_context(): void {
		$context = [
			'block'                  => [
				'name'               => 'core/paragraph',
				'title'              => 'Paragraph',
				'currentAttributes'  => [ 'content' => 'Hello' ],
				'inspectorPanels'    => [ 'color' => true ],
				'structuralIdentity' => [
					'role'     => 'body-text',
					'position' => [ 'depth' => 2 ],
				],
			],
			'siblingsBefore'         => [ 'core/heading' ],
			'siblingsAfter'          => [ 'core/image' ],
			'siblingSummariesBefore' => [
				[
					'block'       => 'core/heading',
					'role'        => 'section-heading',
					'visualHints' => [ 'textAlign' => 'center' ],
				],
			],
			'siblingSummariesAfter'  => [
				[
					'block'       => 'core/image',
					'visualHints' => [ 'align' => 'wide' ],
				],
			],
			'parentContext'           => [
				'block'       => 'core/group',
				'role'        => 'content-area',
				'childCount'  => 3,
				'visualHints' => [ 'backgroundColor' => 'base' ],
			],
			'structuralAncestors'    => [
				[ 'block' => 'core/group', 'role' => 'content-area' ],
			],
			'structuralBranch'       => [
				[
					'block'    => 'core/group',
					'children' => [
						[ 'block' => 'core/paragraph', 'isSelected' => true ],
					],
				],
			],
			'themeTokens'            => [
				'colors' => [ 'base' => '#fff', 'contrast' => '#000' ],
			],
		];

		$prompt = Prompt::build_user( $context );

		$this->assertStringContainsString( '## Block', $prompt );
		$this->assertStringContainsString( '## Surrounding blocks', $prompt );
		$this->assertStringContainsString( '## Parent container', $prompt );
		$this->assertStringContainsString( '## Structural ancestors', $prompt );
		$this->assertStringContainsString( '## Structural branch', $prompt );
		$this->assertStringContainsString( '## Theme Tokens', $prompt );

		$this->assertStringContainsString( 'Before:', $prompt );
		$this->assertStringContainsString( 'After:', $prompt );
		$this->assertStringContainsString( 'core/heading (section-heading', $prompt );
		$this->assertStringContainsString( 'core/image (align:wide)', $prompt );
		$this->assertStringContainsString( 'core/group (content-area)', $prompt );
		$this->assertStringContainsString( 'bg:base', $prompt );
		$this->assertStringContainsString( 'paragraph <- selected', $prompt );
	}
}
