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

	public function test_recommend_content_requires_a_prompt_title_or_existing_content(): void {
		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'draft',
				'postContext' => [
					'title'   => '',
					'content' => '',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_content_instruction', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
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

	public function test_recommend_content_requires_existing_draft_for_critique_mode(): void {
		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'critique',
				'prompt'      => 'Find the weak lines.',
				'postContext' => [
					'title' => 'Draft title',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_existing_content', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_recommend_content_falls_back_to_draft_mode_for_invalid_modes(): void {
		$this->stub_successful_content_response(
			[
				'mode'    => 'draft',
				'title'   => 'Cleaner working title',
				'summary' => 'Draft mode was used.',
				'content' => 'Drafted copy.',
			]
		);

		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'rewrite-everything',
				'prompt'      => 'Start from the working title.',
				'postContext' => [
					'title' => 'Working title',
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['mode'] );
		$this->assertStringContainsString(
			'Mode: draft',
			WordPressTestState::$last_ai_client_prompt['text'] ?? ''
		);
	}

	public function test_recommend_content_sanitizes_context_and_voice_profile_before_prompting(): void {
		$this->stub_successful_content_response(
			[
				'mode'    => 'edit',
				'title'   => 'Retail floors to support tickets',
				'summary' => 'The context was sanitized.',
				'content' => 'Retail floors. Support tickets.',
			]
		);

		$result = ContentAbilities::recommend_content(
			[
				'mode'         => 'edit',
				'prompt'       => "<b>Tighten</b> the opener.\r\nKeep the sequence.",
				'voiceProfile' => '<em>Keep it dry.</em> Mention support tickets.',
				'postContext'  => [
					'postType'        => 'Post<script>',
					'title'           => "<b>Retail\r\nFloors</b>",
					'excerpt'         => '<i>Short excerpt</i>',
					'content'         => "Line one.\r\n\r\n<script>nope</script>Line two.",
					'slug'            => ' working <b>draft</b> ',
					'status'          => 'Draft<script>',
					'audience'        => '<strong>Store managers</strong>',
					'siteTitle'       => '<b>Lakefront</b>',
					'siteDescription' => '<span>Practical WordPress work</span>',
					'categories'      => [ ' Strategy ', '<b>Retail</b>', 'Retail' ],
					'tags'            => [ '<i>Ops</i>', 'AI Workflows' ],
				],
			]
		);

		$this->assertIsArray( $result );

		$prompt = WordPressTestState::$last_ai_client_prompt['text'] ?? '';

		$this->assertStringContainsString( 'Post type: postscript', $prompt );
		$this->assertStringContainsString( 'Status: draftscript', $prompt );
		$this->assertStringContainsString( 'Slug: working draft', $prompt );
		$this->assertStringContainsString( "Title: Retail\nFloors", $prompt );
		$this->assertStringContainsString( 'Excerpt: Short excerpt', $prompt );
		$this->assertStringContainsString( 'Store managers', $prompt );
		$this->assertStringContainsString( 'Title: Lakefront', $prompt );
		$this->assertStringContainsString( 'Description: Practical WordPress work', $prompt );
		$this->assertStringContainsString( 'Categories: Strategy, Retail', $prompt );
		$this->assertStringContainsString( 'Tags: Ops, AI Workflows', $prompt );
		$this->assertStringContainsString( "Line one.\n\nnopeLine two.", $prompt );
		$this->assertStringContainsString( "Tighten the opener.\nKeep the sequence.", $prompt );
		$this->assertStringContainsString( 'Keep it dry. Mention support tickets.', $prompt );
		$this->assertStringNotContainsString( '<script>', $prompt );
		$this->assertStringNotContainsString( '<b>', $prompt );
		$this->assertStringNotContainsString( '<em>', $prompt );
	}

	public function test_recommend_content_uses_editorial_prompt_contract(): void {
		$this->stub_successful_content_response(
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

	/**
	 * @param array<string, mixed> $payload
	 */
	private function stub_successful_content_response( array $payload ): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
			array_merge(
				[
					'mode'    => 'draft',
					'title'   => '',
					'summary' => '',
					'content' => '',
					'notes'   => [],
					'issues'  => [],
				],
				$payload
			)
		);
	}
}
