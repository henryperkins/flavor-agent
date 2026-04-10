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
}
