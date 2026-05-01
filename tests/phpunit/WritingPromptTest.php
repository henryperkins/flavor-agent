<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\WritingPrompt;
use PHPUnit\Framework\TestCase;

final class WritingPromptTest extends TestCase {

	public function test_build_system_uses_updated_henry_voice_prompt(): void {
		$prompt = WritingPrompt::build_system();

		$this->assertStringContainsString( 'blog posts, essays, and site copy', $prompt );
		$this->assertStringContainsString( 'Preserve truth and specificity.', $prompt );
		$this->assertStringContainsString( 'The tools change. The instinct doesn\'t.', $prompt );
		$this->assertStringContainsString( 'ask concise follow-up questions instead of inventing details', $prompt );
	}

	public function test_build_user_includes_voice_notes_and_existing_draft(): void {
		$prompt = WritingPrompt::build_user(
			[
				'mode'         => 'critique',
				'voiceProfile' => 'Keep the phrasing dry.',
				'postContext'  => [
					'postType' => 'post',
					'title'    => 'Draft title',
					'content'  => 'This is the existing draft.',
					'tags'     => [ 'ai', 'WordPress' ],
				],
			],
			'Flag the flat lines.'
		);

		$this->assertStringContainsString( 'Mode: critique', $prompt );
		$this->assertStringContainsString( '## Extra voice notes', $prompt );
		$this->assertStringContainsString( 'Keep the phrasing dry.', $prompt );
		$this->assertStringContainsString( '## Existing draft', $prompt );
		$this->assertStringContainsString( 'This is the existing draft.', $prompt );
		$this->assertStringContainsString( 'Tags: ai, WordPress', $prompt );
	}

	public function test_build_user_defaults_to_requested_piece_instruction(): void {
		$prompt = WritingPrompt::build_user(
			[
				'mode'        => 'draft',
				'postContext' => [
					'title' => 'Draft title',
				],
			]
		);

		$this->assertStringContainsString( '## User instruction', $prompt );
		$this->assertStringContainsString( 'Draft the requested piece in Henry\'s voice.', $prompt );
	}

	public function test_parse_response_accepts_fenced_json_and_sanitizes_issues(): void {
		$result = WritingPrompt::parse_response(
			<<<'JSON'
```json
{
  "mode": "critique",
  "title": "Draft title",
  "summary": "Tighten the opener.",
  "content": "Retail floors.\nWordPress themes.",
  "notes": ["Lead with the specifics."],
  "issues": [
    {
      "original": "Technology is evolving.",
      "problem": "Too generic.",
      "revision": "Retail floors. WordPress themes. Cloud platforms."
    }
  ]
}
```
JSON
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'critique', $result['mode'] );
		$this->assertSame( 'Draft title', $result['title'] );
		$this->assertSame( 'Lead with the specifics.', $result['notes'][0] );
		$this->assertSame( 'Too generic.', $result['issues'][0]['problem'] );
	}

	public function test_build_user_renders_voice_samples_section_when_provided(): void {
		$prompt = WritingPrompt::build_user(
			[
				'mode'         => 'edit',
				'postContext'  => [
					'postType' => 'post',
					'title'    => 'Current',
					'content'  => 'Existing body.',
				],
				'voiceSamples' => [
					[
						'title'     => 'Earlier post',
						'published' => '2026-04-12',
						'opening'   => 'Retail floors. WordPress themes.',
					],
					[
						'title'     => 'Older post',
						'published' => '2026-03-05',
						'opening'   => 'Cloud platforms. Agentic AI.',
					],
				],
			],
			'Tighten the opener.'
		);

		$this->assertStringContainsString( '## Site voice samples', $prompt );
		$this->assertStringContainsString( 'Use them only as voice and style evidence.', $prompt );
		$this->assertStringContainsString( '### Sample: Earlier post', $prompt );
		$this->assertStringContainsString( 'Published: 2026-04-12', $prompt );
		$this->assertStringContainsString( 'Opening:', $prompt );
		$this->assertStringContainsString( 'Retail floors. WordPress themes.', $prompt );
		$this->assertStringContainsString( '### Sample: Older post', $prompt );
	}

	public function test_build_user_omits_voice_samples_section_when_array_empty(): void {
		$prompt = WritingPrompt::build_user(
			[
				'mode'         => 'edit',
				'postContext'  => [
					'postType' => 'post',
					'title'    => 'Current',
					'content'  => 'Existing body.',
				],
				'voiceSamples' => [],
			],
			'Tighten.'
		);

		$this->assertStringNotContainsString( '## Site voice samples', $prompt );
		$this->assertStringNotContainsString( '### Sample:', $prompt );
	}

	public function test_build_user_omits_voice_samples_section_when_key_missing(): void {
		$prompt = WritingPrompt::build_user(
			[
				'mode'        => 'draft',
				'postContext' => [
					'postType' => 'post',
					'title'    => 'New piece',
				],
			],
			'Sketch it.'
		);

		$this->assertStringNotContainsString( '## Site voice samples', $prompt );
	}

	public function test_build_user_uses_content_scoped_budget_filter(): void {
		$captured = [];
		$filter   = static function ( int $value, string $surface ) use ( &$captured ): int {
			$captured[] = $surface;

			return $value;
		};

		add_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10, 2 );

		try {
			WritingPrompt::build_user(
				[
					'mode'        => 'draft',
					'postContext' => [ 'postType' => 'post' ],
				],
				'Anything.'
			);
		} finally {
			remove_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10 );
		}

		$this->assertContains( 'content', $captured );
	}

	public function test_build_user_drops_voice_samples_first_under_budget_pressure(): void {
		$existing_draft = str_repeat( 'A', 6000 );
		$voice_opening  = str_repeat( 'B', 6000 );

		$filter = static fn (): int => 2000;
		add_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10 );

		try {
			$prompt = WritingPrompt::build_user(
				[
					'mode'         => 'edit',
					'postContext'  => [
						'postType' => 'post',
						'content'  => $existing_draft,
					],
					'voiceSamples' => [
						[
							'title'     => 'Sample',
							'published' => '2026-04-12',
							'opening'   => $voice_opening,
						],
					],
				],
				'Tighten.'
			);
		} finally {
			remove_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10 );
		}

		$this->assertStringContainsString( str_repeat( 'A', 100 ), $prompt );
		$this->assertStringNotContainsString( str_repeat( 'B', 100 ), $prompt );
		$this->assertStringNotContainsString( '## Site voice samples', $prompt );
	}
}
