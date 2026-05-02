<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\ContentAbilities;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ContentAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->configure_text_generation_connector();
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

	public function test_recommend_content_allows_post_id_zero_without_per_post_auth(): void {
		$this->stub_successful_content_response(
			[
				'mode'    => 'draft',
				'title'   => 'OK',
				'summary' => '',
				'content' => 'X',
			]
		);

		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'draft',
				'prompt'      => 'Sketch something.',
				'postContext' => [
					'postId'  => 0,
					'title'   => 'New post',
					'content' => '',
				],
			]
		);

		$this->assertIsArray( $result );
	}

	public function test_recommend_content_requires_edit_post_for_positive_post_id(): void {
		WordPressTestState::$capabilities['edit_post:42'] = false;

		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Refine.',
				'postContext' => [
					'postId'  => 42,
					'title'   => 'Other post',
					'content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_context', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	public function test_recommend_content_renders_dynamic_block_into_existing_draft_section(): void {
		register_block_type(
			'flavor-agent-test/dynamic-text',
			[
				'render_callback' => static fn (): string => '<p>Rendered dynamic content sentinel.</p>',
			]
		);

		WordPressTestState::$capabilities['edit_post:77'] = true;
		WordPressTestState::$posts[77]                    = new \WP_Post(
			[
				'ID'         => 77,
				'post_title' => 'Working title',
				'post_type'  => 'post',
			]
		);

		$this->stub_successful_content_response(
			[
				'mode'    => 'edit',
				'title'   => 'OK',
				'summary' => '',
				'content' => 'X',
			]
		);

		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Tighten.',
				'postContext' => [
					'postId'  => 77,
					'title'   => 'Working title',
					'content' => '<!-- wp:flavor-agent-test/dynamic-text /-->',
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString(
			'Rendered dynamic content sentinel.',
			WordPressTestState::$last_ai_client_prompt['text'] ?? ''
		);
	}

	public function test_recommend_content_propagates_post_id_to_renderer_globals(): void {
		$captured_id = null;
		register_block_type(
			'flavor-agent-test/global-capture',
			[
				'render_callback' => static function () use ( &$captured_id ): string {
					$post        = $GLOBALS['post'] ?? null;
					$captured_id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : null;

					return 'captured';
				},
			]
		);

		WordPressTestState::$capabilities['edit_post:88'] = true;
		WordPressTestState::$posts[88]                    = new \WP_Post(
			[
				'ID'         => 88,
				'post_title' => 'X',
				'post_type'  => 'post',
			]
		);

		$this->stub_successful_content_response(
			[
				'mode'    => 'edit',
				'title'   => 'OK',
				'summary' => '',
				'content' => 'X',
			]
		);

		ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Anything.',
				'postContext' => [
					'postId'  => 88,
					'title'   => 'X',
					'content' => '<!-- wp:flavor-agent-test/global-capture /-->',
				],
			]
		);

		$this->assertSame( 88, $captured_id );
	}

	public function test_recommend_content_validates_after_rendering_empty_content(): void {
		WordPressTestState::$capabilities['edit_post:42'] = true;
		WordPressTestState::$posts[42]                    = new \WP_Post(
			[
				'ID'         => 42,
				'post_title' => 'X',
				'post_type'  => 'post',
			]
		);

		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Tighten.',
				'postContext' => [
					'postId'  => 42,
					'title'   => 'X',
					'content' => '<div></div>',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_existing_content', $result->get_error_code() );
	}

	public function test_recommend_content_does_not_collect_voice_samples_when_edit_content_renders_empty(): void {
		WordPressTestState::$current_user_id              = 5;
		WordPressTestState::$capabilities['edit_post:42'] = true;
		WordPressTestState::$posts[42]                    = new \WP_Post(
			[
				'ID'          => 42,
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_author' => 5,
				'post_title'  => 'Current',
			]
		);
		WordPressTestState::$posts[43]                    = new \WP_Post(
			[
				'ID'            => 43,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'Sample',
				'post_content'  => '<!-- wp:paragraph --><p>Sample body.</p><!-- /wp:paragraph -->',
				'post_date_gmt' => '2026-04-12 09:00:00',
			]
		);
		WordPressTestState::$capabilities['read_post:43'] = true;

		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Tighten.',
				'postContext' => [
					'postId'   => 42,
					'postType' => 'post',
					'title'    => 'Current',
					'content'  => '<div></div>',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_existing_content', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$get_posts_calls );
	}

	public function test_recommend_content_preserves_no_post_id_fallback_empty_instruction_validation(): void {
		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'draft',
				'postContext' => [
					'content' => '<!-- random comment -->',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_content_instruction', $result->get_error_code() );
	}

	public function test_recommend_content_includes_voice_samples_section_when_present(): void {
		WordPressTestState::$current_user_id = 5;

		WordPressTestState::$capabilities['edit_post:600'] = true;
		WordPressTestState::$posts[600]                    = new \WP_Post(
			[
				'ID'           => 600,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_author'  => 5,
				'post_title'   => 'Current',
				'post_content' => '<!-- wp:paragraph --><p>Current body.</p><!-- /wp:paragraph -->',
			]
		);

		WordPressTestState::$posts[601]                    = new \WP_Post(
			[
				'ID'            => 601,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'Published Sample',
				'post_content'  => '<!-- wp:paragraph --><p>Sample paragraph.</p><!-- /wp:paragraph -->',
				'post_date_gmt' => '2026-04-12 09:00:00',
			]
		);
		WordPressTestState::$capabilities['read_post:601'] = true;

		$this->stub_successful_content_response(
			[
				'mode'    => 'edit',
				'title'   => 'OK',
				'summary' => '',
				'content' => 'X',
			]
		);

		ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Tighten.',
				'postContext' => [
					'postId'   => 600,
					'postType' => 'post',
					'title'    => 'Current',
					'content'  => '<!-- wp:paragraph --><p>Current body.</p><!-- /wp:paragraph -->',
				],
			]
		);

		$prompt = WordPressTestState::$last_ai_client_prompt['text'] ?? '';

		$this->assertStringContainsString( '## Site voice samples', $prompt );
		$this->assertStringContainsString( '### Sample: Published Sample', $prompt );
		$this->assertStringContainsString( 'Published: 2026-04-12', $prompt );
		$this->assertStringContainsString( 'Opening:', $prompt );
		$this->assertStringContainsString( 'Sample paragraph.', $prompt );
	}

	public function test_recommend_content_omits_voice_samples_section_when_no_samples(): void {
		WordPressTestState::$current_user_id = 7;

		WordPressTestState::$capabilities['edit_post:610'] = true;
		WordPressTestState::$posts[610]                    = new \WP_Post(
			[
				'ID'           => 610,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_author'  => 7,
				'post_title'   => 'Lonely',
				'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
			]
		);

		$this->stub_successful_content_response(
			[
				'mode'    => 'edit',
				'title'   => 'OK',
				'summary' => '',
				'content' => 'X',
			]
		);

		ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Tighten.',
				'postContext' => [
					'postId'   => 610,
					'postType' => 'post',
					'title'    => 'Lonely',
					'content'  => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				],
			]
		);

		$prompt = WordPressTestState::$last_ai_client_prompt['text'] ?? '';

		$this->assertStringNotContainsString( '## Site voice samples', $prompt );
		$this->assertStringNotContainsString( '### Sample:', $prompt );
	}

	public function test_recommend_content_omits_samples_for_unsupported_post_type(): void {
		WordPressTestState::$current_user_id = 9;

		WordPressTestState::$posts[700]                    = new \WP_Post(
			[
				'ID'            => 700,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 9,
				'post_title'    => 'Should Not Appear',
				'post_content'  => '<!-- wp:paragraph --><p>Hidden body.</p><!-- /wp:paragraph -->',
				'post_date_gmt' => '2026-04-12 09:00:00',
			]
		);
		WordPressTestState::$capabilities['read_post:700'] = true;

		$this->stub_successful_content_response(
			[
				'mode'    => 'draft',
				'title'   => 'OK',
				'summary' => '',
				'content' => 'X',
			]
		);

		ContentAbilities::recommend_content(
			[
				'mode'        => 'draft',
				'prompt'      => 'Sketch.',
				'postContext' => [
					'postId'   => 0,
					'postType' => 'wp_template',
					'title'    => 'Brand new template',
				],
			]
		);

		$prompt = WordPressTestState::$last_ai_client_prompt['text'] ?? '';

		$this->assertStringNotContainsString( '## Site voice samples', $prompt );
		$this->assertStringNotContainsString( 'Should Not Appear', $prompt );
	}

	public function test_recommend_content_uses_canonical_post_type_for_saved_post(): void {
		WordPressTestState::$current_user_id = 9;

		WordPressTestState::$capabilities['edit_post:710'] = true;
		WordPressTestState::$posts[710]                    = new \WP_Post(
			[
				'ID'           => 710,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_author'  => 9,
				'post_title'   => 'Saved Page',
				'post_content' => '<!-- wp:paragraph --><p>Page draft.</p><!-- /wp:paragraph -->',
			]
		);

		WordPressTestState::$posts[711]                    = new \WP_Post(
			[
				'ID'            => 711,
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_author'   => 9,
				'post_title'    => 'Sister page',
				'post_content'  => '<!-- wp:paragraph --><p>Sister body.</p><!-- /wp:paragraph -->',
				'post_date_gmt' => '2026-04-10 09:00:00',
			]
		);
		WordPressTestState::$capabilities['read_post:711'] = true;

		$this->stub_successful_content_response(
			[
				'mode'    => 'edit',
				'title'   => 'OK',
				'summary' => '',
				'content' => 'X',
			]
		);

		ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Tighten.',
				'postContext' => [
					'postId'   => 710,
					'postType' => 'post',
					'title'    => 'Saved Page',
					'content'  => '<!-- wp:paragraph --><p>Page draft.</p><!-- /wp:paragraph -->',
				],
			]
		);

		$prompt = WordPressTestState::$last_ai_client_prompt['text'] ?? '';

		$this->assertStringContainsString( '### Sample: Sister page', $prompt );
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

	private function configure_text_generation_connector(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => 'openai',
		];
		WordPressTestState::$connectors                 = [
			'openai' => [
				'name'           => 'OpenAI',
				'description'    => 'OpenAI connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_provider_support = [
			'openai' => true,
		];
	}
}
