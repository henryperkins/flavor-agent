<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\ContentAbilities;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ContentAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_recommend_content_requires_existing_draft_for_edit_mode(): void {
		$result = ContentAbilities::recommend_content(
			[
				'mode'   => 'edit',
				'prompt' => 'Tighten the draft.',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_existing_content', $result->get_error_code() );
	}

	public function test_recommend_content_uses_editorial_prompt_contract(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
			[
				'mode'    => 'critique',
				'title'   => 'Coffee, code, and the same instinct',
				'summary' => 'Lead with the throughline, then tighten the abstractions.',
				'content' => "Retail floors.\nWordPress themes.\nCloud platforms.",
				'notes'   => [ 'Lead with the concrete progression.' ],
				'issues'  => [
					[
						'original' => 'Technology is rapidly evolving.',
						'problem'  => 'Too abstract.',
						'revision' => 'WordPress changed. Cloud changed. The customer still needed the thing to work.',
					],
				],
			]
		);

		$result = ContentAbilities::recommend_content(
			[
				'mode'         => 'critique',
				'prompt'       => 'Make it sound more like Henry.',
				'voiceProfile' => 'Keep the humor dry.',
				'postContext'  => [
					'title'   => 'Draft title',
					'content' => 'Technology is rapidly evolving.',
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'critique', $result['mode'] );
		$this->assertSame( 'Coffee, code, and the same instinct', $result['title'] );
		$this->assertCount( 1, $result['issues'] );
		$this->assertSame(
			'WordPress changed. Cloud changed. The customer still needed the thing to work.',
			$result['issues'][0]['revision']
		);
		$this->assertStringContainsString(
			'Henry Perkins\'s voice',
			WordPressTestState::$last_ai_client_prompt['system'] ?? ''
		);
		$this->assertStringContainsString(
			'blog posts, essays, and site copy',
			WordPressTestState::$last_ai_client_prompt['system'] ?? ''
		);
		$this->assertStringContainsString(
			'Technology is rapidly evolving.',
			WordPressTestState::$last_ai_client_prompt['text'] ?? ''
		);
	}
}
