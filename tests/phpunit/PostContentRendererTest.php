<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\PostContentRenderer;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PostContentRendererTest extends TestCase {

	private PostContentRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->renderer = new PostContentRenderer();
		$this->seed_post( 100, 'Working draft', 'post' );
	}

	public function test_extract_uses_fallback_without_positive_post_id(): void {
		$called = false;
		register_block_type(
			'flavor-agent-test/sentinel-missing-postid',
			[
				'render_callback' => static function () use ( &$called ): string {
					$called = true;

					return 'should not appear';
				},
			]
		);

		$out = $this->renderer->extract( "<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->\r\n" );
		$this->renderer->extract( '<!-- wp:flavor-agent-test/sentinel-missing-postid /-->', [ 'postId' => 0 ] );

		$this->assertStringContainsString( 'Hello', $out );
		$this->assertStringNotContainsString( '<p>', $out );
		$this->assertFalse( $called );
	}

	public function test_extract_uses_fallback_when_post_does_not_exist(): void {
		$called = false;
		register_block_type(
			'flavor-agent-test/sentinel-no-post',
			[
				'render_callback' => static function () use ( &$called ): string {
					$called = true;

					return 'should not appear';
				},
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/sentinel-no-post /-->',
			[ 'postId' => 9999 ]
		);

		$this->assertFalse( $called );
		$this->assertStringNotContainsString( 'should not appear', $out );
	}

	public function test_extract_renders_static_and_nested_blocks_in_document_order(): void {
		$content = '<!-- wp:paragraph --><p>First paragraph.</p><!-- /wp:paragraph -->'
			. '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:heading --><h2>Second heading.</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Third paragraph.</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
			. '<!-- wp:list --><ul><li>Fourth item.</li></ul><!-- /wp:list -->';

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertStringContainsString( 'First paragraph.', $out );
		$this->assertStringContainsString( 'Second heading.', $out );
		$this->assertStringContainsString( 'Third paragraph.', $out );
		$this->assertStringContainsString( 'Fourth item.', $out );
		$this->assertStringNotContainsString( '<!-- wp:', $out );
		$this->assertSame( 1, substr_count( $out, 'Second heading.' ) );
		$this->assertLessThan( strpos( $out, 'Second heading.' ), strpos( $out, 'First paragraph.' ) );
		$this->assertLessThan( strpos( $out, 'Third paragraph.' ), strpos( $out, 'Second heading.' ) );
		$this->assertLessThan( strpos( $out, 'Fourth item.' ), strpos( $out, 'Third paragraph.' ) );
	}

	public function test_extract_sets_up_post_globals_during_render_and_restores_after(): void {
		$captured = [ 'post_id' => null ];
		register_block_type(
			'flavor-agent-test/global-aware',
			[
				'render_callback' => static function () use ( &$captured ): string {
					$post                = $GLOBALS['post'] ?? null;
					$captured['post_id'] = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : null;

					return '<global>seen</global>';
				},
			]
		);

		$pre_post       = $GLOBALS['post'] ?? null;
		$pre_state_post = WordPressTestState::$current_post;

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/global-aware /-->',
			[ 'postId' => 100 ]
		);

		$this->assertSame( 100, $captured['post_id'] );
		$this->assertStringContainsString( 'seen', $out );
		$this->assertSame( $pre_post, $GLOBALS['post'] ?? null );
		$this->assertSame( $pre_state_post, WordPressTestState::$current_post );
	}

	public function test_extract_replaces_failed_block_with_marker_and_continues(): void {
		register_block_type(
			'flavor-agent-test/explody',
			[
				'render_callback' => static function (): string {
					throw new \RuntimeException( 'boom' );
				},
			]
		);

		$content = '<!-- wp:paragraph --><p>Survivor before.</p><!-- /wp:paragraph -->'
			. '<!-- wp:flavor-agent-test/explody /-->'
			. '<!-- wp:paragraph --><p>Survivor after.</p><!-- /wp:paragraph -->';

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertStringContainsString( 'Survivor before.', $out );
		$this->assertStringContainsString( 'Survivor after.', $out );
		$this->assertStringContainsString( '[block render failed: flavor-agent-test/explody]', $out );
	}

	public function test_extract_keeps_block_boundaries_after_strip(): void {
		register_block_type(
			'flavor-agent-test/glued',
			[
				'render_callback' => static fn (): string => '<p>One</p><p>Two</p><h2>Three</h2><li>Four</li><p>Five<br>Six</p><hr><p>Seven</p>',
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/glued /-->',
			[ 'postId' => 100 ]
		);

		$this->assertMatchesRegularExpression( '/One\s+Two/', $out );
		$this->assertMatchesRegularExpression( '/Two\s+Three/', $out );
		$this->assertMatchesRegularExpression( '/Three\s+Four/', $out );
		$this->assertMatchesRegularExpression( '/Five\s+Six/', $out );
		$this->assertMatchesRegularExpression( '/Six\s+Seven/', $out );
	}

	public function test_extract_harvests_attribute_text_and_allowed_links(): void {
		$this->require_dom_extension();

		$content = '<!-- wp:image {"id":42} -->'
			. '<figure><img src="https://example.test/image.jpg" alt="A meaningful description" />'
			. '<figcaption>Caption text.</figcaption></figure><!-- /wp:image -->'
			. '<!-- wp:button --><div><a href="https://example.test/destination" title="Destination title">Click me</a></div><!-- /wp:button -->';

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertStringContainsString( 'Caption text.', $out );
		$this->assertStringContainsString( 'Click me', $out );
		$this->assertStringContainsString( '[Attribute references]', $out );
		$this->assertStringContainsString( 'A meaningful description', $out );
		$this->assertStringContainsString( 'Destination title', $out );
		$this->assertStringContainsString( 'https://example.test/destination', $out );
		$ref_section = strstr( $out, '[Attribute references]' );
		$this->assertStringNotContainsString( 'https://example.test/image.jpg', false !== $ref_section ? $ref_section : '' );
	}

	public function test_extract_outputs_attribute_references_without_visible_text(): void {
		$this->require_dom_extension();

		register_block_type(
			'flavor-agent-test/attribute-only',
			[
				'render_callback' => static fn (): string => '<img alt="Standalone image description" /><a href="https://example.test/source"></a>',
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/attribute-only /-->',
			[ 'postId' => 100 ]
		);

		$this->assertSame(
			"[Attribute references]\n- Standalone image description\n- https://example.test/source",
			trim( $out )
		);
	}

	public function test_extract_limits_and_normalizes_attribute_values(): void {
		$this->require_dom_extension();

		$long = str_repeat( 'A', 600 );
		$imgs = '';
		for ( $i = 0; $i < 200; $i++ ) {
			$imgs .= sprintf( '<img alt="Alt %d" />', $i );
		}

		register_block_type(
			'flavor-agent-test/attrs',
			[
				'render_callback' => static fn (): string => '<img alt="' . $long . '" />'
					. '<img alt="Real description' . "\n\n## Injected heading\nMore text" . '" />'
					. $imgs,
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/attrs /-->',
			[ 'postId' => 100 ]
		);

		$ref_section = strstr( $out, '[Attribute references]' );
		$this->assertNotFalse( $ref_section );
		$this->assertStringContainsString( str_repeat( 'A', 500 ) . '…', $ref_section );
		$this->assertStringNotContainsString( str_repeat( 'A', 501 ), $ref_section );
		$this->assertStringContainsString( '- Real description ## Injected heading More text', $ref_section );
		$this->assertStringNotContainsString( "\n## ", $ref_section );
		$this->assertLessThanOrEqual( 100, substr_count( $ref_section, "\n- " ) );
	}

	public function test_extract_truncates_attribute_values_without_breaking_utf8(): void {
		$this->require_dom_extension();

		$long = str_repeat( 'A', 499 ) . "\u{1F642}" . 'tail';

		register_block_type(
			'flavor-agent-test/utf8-attr',
			[
				'render_callback' => static fn (): string => '<img alt="' . $long . '" />',
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/utf8-attr /-->',
			[ 'postId' => 100 ]
		);

		$this->assertSame( 1, preg_match( '//u', $out ) );
		$this->assertStringContainsString( str_repeat( 'A', 499 ) . "\u{1F642}" . '…', $out );
		$this->assertStringNotContainsString( 'tail', $out );
	}

	public function test_extract_drops_disallowed_href_schemes_and_dedupes_visible_links(): void {
		$this->require_dom_extension();

		register_block_type(
			'flavor-agent-test/links',
			[
				'render_callback' => static fn (): string => '<p>Visit https://kept.example.test/page for details.</p>'
					. '<a href="javascript:alert(1)">x</a>'
					. '<a href="data:text/html,bad">y</a>'
					. '<a href="https://kept.example.test/page">duplicate</a>'
					. '<a href="mailto:author@example.test">mail</a>'
					. '<a href="tel:+15551234567">call</a>'
					. '<a href="/relative/path">rel</a>',
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/links /-->',
			[ 'postId' => 100 ]
		);

		$this->assertStringNotContainsString( 'javascript:', $out );
		$this->assertStringNotContainsString( 'data:', $out );
		$this->assertSame( 1, substr_count( $out, 'https://kept.example.test/page' ) );
		$this->assertStringContainsString( 'mailto:author@example.test', $out );
		$this->assertStringContainsString( 'tel:+15551234567', $out );
		$this->assertStringContainsString( '/relative/path', $out );
	}

	public function test_extract_restores_libxml_internal_error_mode(): void {
		$this->require_dom_extension();

		$before = libxml_use_internal_errors( false );
		libxml_use_internal_errors( false );

		$this->renderer->extract(
			'<!-- wp:paragraph --><p>Anything.</p><!-- /wp:paragraph -->',
			[ 'postId' => 100 ]
		);

		$this->assertFalse( libxml_use_internal_errors( false ) );
		libxml_use_internal_errors( $before );
	}

	public function test_extract_intercepts_self_reference_blocks(): void {
		$content = '<!-- wp:post-title /-->'
			. '<!-- wp:post-excerpt /-->'
			. '<!-- wp:post-content /-->'
			. '<!-- wp:paragraph --><p>Real body.</p><!-- /wp:paragraph -->';

		$out = $this->renderer->extract(
			$content,
			[
				'postId'        => 100,
				'stagedTitle'   => 'Working title from editor',
				'stagedExcerpt' => 'Staged short summary.',
			]
		);

		$this->assertStringContainsString( 'Working title from editor', $out );
		$this->assertStringContainsString( 'Staged short summary.', $out );
		$this->assertStringContainsString( 'Real body.', $out );
		$this->assertStringNotContainsString( 'Working draft', $out );
	}

	public function test_extract_uses_empty_staged_title_when_user_cleared_field(): void {
		$out = $this->renderer->extract(
			'<!-- wp:post-title /--><!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
			[
				'postId'      => 100,
				'stagedTitle' => '',
			]
		);

		$this->assertStringNotContainsString( 'Working draft', $out );
		$this->assertSame( 'Body.', trim( $out ) );
	}

	public function test_extract_renders_freeform_and_strips_carriage_returns(): void {
		$content = "Intro text. <!-- wp:paragraph -->\r\n<p>Inside block.</p>\r\n<!-- /wp:paragraph --> Trailing text.";

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertStringContainsString( 'Intro text.', $out );
		$this->assertStringContainsString( 'Inside block.', $out );
		$this->assertStringContainsString( 'Trailing text.', $out );
		$this->assertStringNotContainsString( "\r", $out );
	}

	public function test_server_collector_for_post_content_routes_to_renderer(): void {
		$out = ServerCollector::for_post_content(
			'<!-- wp:paragraph --><p>Routed.</p><!-- /wp:paragraph -->',
			[ 'postId' => 100 ]
		);

		$this->assertStringContainsString( 'Routed.', $out );
	}

	private function seed_post( int $id, string $title, string $post_type ): void {
		WordPressTestState::$posts[ $id ] = new \WP_Post(
			[
				'ID'         => $id,
				'post_title' => $title,
				'post_type'  => $post_type,
			]
		);
	}

	private function require_dom_extension(): void {
		if ( ! class_exists( \DOMDocument::class ) || ! class_exists( \DOMXPath::class ) ) {
			$this->markTestSkipped( 'ext-dom is required for attribute-walk tests.' );
		}
	}
}
