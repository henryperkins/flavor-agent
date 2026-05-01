<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\PostContentRenderer;
use FlavorAgent\Context\PostVoiceSampleCollector;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PostVoiceSampleCollectorTest extends TestCase {

	private PostVoiceSampleCollector $collector;

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->collector = new PostVoiceSampleCollector( new PostContentRenderer() );
	}

	public function test_returns_empty_for_unsupported_post_type(): void {
		$result = $this->collector->for_post( 0, 'wp_template' );

		$this->assertSame( [], $result );
		$this->assertSame( [], WordPressTestState::$get_posts_calls );
	}

	public function test_returns_empty_for_unknown_post_type(): void {
		$result = $this->collector->for_post( 0, 'completely-made-up' );

		$this->assertSame( [], $result );
		$this->assertSame( [], WordPressTestState::$get_posts_calls );
	}

	public function test_supported_post_types_include_post_and_page(): void {
		$this->assertContains( 'post', PostVoiceSampleCollector::SUPPORTED_POST_TYPES );
		$this->assertContains( 'page', PostVoiceSampleCollector::SUPPORTED_POST_TYPES );
	}

	public function test_returns_empty_when_current_user_is_zero_and_post_id_is_zero(): void {
		WordPressTestState::$current_user_id = 0;

		$result = $this->collector->for_post( 0, 'post' );

		$this->assertSame( [], $result );
		$this->assertSame( [], WordPressTestState::$get_posts_calls );
	}

	public function test_returns_empty_when_post_author_is_zero(): void {
		WordPressTestState::$posts[42] = new \WP_Post(
			[
				'ID'          => 42,
				'post_type'   => 'post',
				'post_author' => 0,
			]
		);

		$result = $this->collector->for_post( 42, 'post' );

		$this->assertSame( [], $result );
		$this->assertSame( [], WordPressTestState::$get_posts_calls );
	}

	public function test_resolves_author_from_post_for_positive_post_id(): void {
		WordPressTestState::$posts[100] = new \WP_Post(
			[
				'ID'          => 100,
				'post_type'   => 'post',
				'post_author' => 7,
			]
		);

		$this->collector->for_post( 100, 'post' );

		$this->assertCount( 1, WordPressTestState::$get_posts_calls );
		$this->assertSame( 7, WordPressTestState::$get_posts_calls[0]['author'] ?? null );
		$this->assertSame( [ 100 ], WordPressTestState::$get_posts_calls[0]['post__not_in'] ?? null );
	}

	public function test_resolves_author_from_current_user_for_unsaved_post(): void {
		WordPressTestState::$current_user_id = 11;

		$this->collector->for_post( 0, 'page' );

		$this->assertCount( 1, WordPressTestState::$get_posts_calls );
		$this->assertSame( 11, WordPressTestState::$get_posts_calls[0]['author'] ?? null );
		$this->assertSame( [], WordPressTestState::$get_posts_calls[0]['post__not_in'] ?? null );
	}

	public function test_skips_password_protected_candidates_in_field_check(): void {
		WordPressTestState::$current_user_id = 5;
		WordPressTestState::$posts[201]      = new \WP_Post(
			[
				'ID'            => 201,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'Open',
				'post_content'  => '<!-- wp:paragraph --><p>Open body.</p><!-- /wp:paragraph -->',
				'post_password' => '',
				'post_date_gmt' => '2026-04-15 09:00:00',
			]
		);
		WordPressTestState::$posts[202]      = new \WP_Post(
			[
				'ID'            => 202,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'Locked',
				'post_content'  => '<!-- wp:paragraph --><p>Locked body.</p><!-- /wp:paragraph -->',
				'post_password' => 'shibboleth',
				'post_date_gmt' => '2026-04-16 09:00:00',
			]
		);

		WordPressTestState::$capabilities['read_post:201'] = true;
		WordPressTestState::$capabilities['read_post:202'] = true;

		$samples = $this->collector->for_post( 0, 'post' );

		$titles = array_column( $samples, 'title' );
		$this->assertContains( 'Open', $titles );
		$this->assertNotContains( 'Locked', $titles );
	}

	public function test_skips_candidates_failing_read_post_capability(): void {
		WordPressTestState::$current_user_id = 5;
		WordPressTestState::$posts[210]      = new \WP_Post(
			[
				'ID'            => 210,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'Allowed',
				'post_content'  => '<!-- wp:paragraph --><p>Allowed body.</p><!-- /wp:paragraph -->',
				'post_date_gmt' => '2026-04-15 09:00:00',
			]
		);
		WordPressTestState::$posts[211]      = new \WP_Post(
			[
				'ID'            => 211,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'Forbidden',
				'post_content'  => '<!-- wp:paragraph --><p>Forbidden body.</p><!-- /wp:paragraph -->',
				'post_date_gmt' => '2026-04-16 09:00:00',
			]
		);

		WordPressTestState::$capabilities['read_post:210'] = true;
		WordPressTestState::$capabilities['read_post:211'] = false;

		$samples = $this->collector->for_post( 0, 'post' );

		$titles = array_column( $samples, 'title' );
		$this->assertContains( 'Allowed', $titles );
		$this->assertNotContains( 'Forbidden', $titles );
	}

	public function test_does_not_backfill_when_candidates_filtered_out(): void {
		WordPressTestState::$current_user_id = 5;
		foreach ( [ 220, 221, 222, 223 ] as $offset => $id ) {
			WordPressTestState::$posts[ $id ]                      = new \WP_Post(
				[
					'ID'            => $id,
					'post_type'     => 'post',
					'post_status'   => 'publish',
					'post_author'   => 5,
					'post_title'    => "Post {$id}",
					'post_content'  => "<!-- wp:paragraph --><p>Body {$id}.</p><!-- /wp:paragraph -->",
					'post_date_gmt' => sprintf( '2026-04-%02d 09:00:00', 10 + $offset ),
				]
			);
			WordPressTestState::$capabilities[ "read_post:{$id}" ] = true;
		}
		WordPressTestState::$capabilities['read_post:222'] = false;

		$samples = $this->collector->for_post( 0, 'post' );

		$this->assertCount( 2, $samples );
	}

	public function test_strips_attribute_references_tail_from_rendered_output(): void {
		WordPressTestState::$current_user_id               = 5;
		WordPressTestState::$posts[300]                    = new \WP_Post(
			[
				'ID'            => 300,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'With image',
				'post_content'  => '<!-- wp:paragraph --><p>Visible prose.</p><!-- /wp:paragraph -->'
					. '<!-- wp:image -->'
					. '<figure><img src="https://example.test/img.jpg" alt="Reference text" /></figure>'
					. '<!-- /wp:image -->',
				'post_date_gmt' => '2026-04-15 09:00:00',
			]
		);
		WordPressTestState::$capabilities['read_post:300'] = true;

		$samples = $this->collector->for_post( 0, 'post' );

		$this->assertCount( 1, $samples );
		$this->assertStringContainsString( 'Visible prose', $samples[0]['opening'] );
		$this->assertStringNotContainsString( '[Attribute references]', $samples[0]['opening'] );
		$this->assertStringNotContainsString( 'Reference text', $samples[0]['opening'] );
	}

	public function test_render_failure_marker_drops_candidate_and_keeps_siblings(): void {
		WordPressTestState::$current_user_id = 5;

		register_block_type(
			'flavor-agent-test/voice-sample-explody',
			[
				'render_callback' => static function (): string {
					throw new \RuntimeException( 'voice sample render boom' );
				},
			]
		);

		WordPressTestState::$posts[310] = new \WP_Post(
			[
				'ID'            => 310,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'Survives',
				'post_content'  => '<!-- wp:paragraph --><p>Survives.</p><!-- /wp:paragraph -->',
				'post_date_gmt' => '2026-04-15 09:00:00',
			]
		);
		WordPressTestState::$posts[311] = new \WP_Post(
			[
				'ID'            => 311,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'Throws',
				'post_content'  => '<!-- wp:flavor-agent-test/voice-sample-explody /-->',
				'post_date_gmt' => '2026-04-16 09:00:00',
			]
		);

		WordPressTestState::$capabilities['read_post:310'] = true;
		WordPressTestState::$capabilities['read_post:311'] = true;

		$samples = $this->collector->for_post( 0, 'post' );

		$titles = array_column( $samples, 'title' );
		$this->assertContains( 'Survives', $titles );
		$this->assertNotContains( 'Throws', $titles );
	}

	public function test_short_opening_passes_through_untruncated(): void {
		WordPressTestState::$current_user_id               = 5;
		WordPressTestState::$posts[400]                    = new \WP_Post(
			[
				'ID'            => 400,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'Short',
				'post_content'  => '<!-- wp:paragraph --><p>Short body.</p><!-- /wp:paragraph -->',
				'post_date_gmt' => '2026-04-15 09:00:00',
			]
		);
		WordPressTestState::$capabilities['read_post:400'] = true;

		$samples = $this->collector->for_post( 0, 'post' );

		$this->assertSame( 'Short body.', $samples[0]['opening'] );
	}

	public function test_truncates_at_paragraph_boundary_when_possible(): void {
		WordPressTestState::$current_user_id = 5;

		$first  = str_repeat( 'A', 800 );
		$second = str_repeat( 'B', 600 );
		$third  = str_repeat( 'C', 1200 );

		WordPressTestState::$posts[401]                    = new \WP_Post(
			[
				'ID'            => 401,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'ParagraphSnap',
				'post_content'  => sprintf(
					'<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->'
					. '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->'
					. '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->',
					$first,
					$second,
					$third
				),
				'post_date_gmt' => '2026-04-15 09:00:00',
			]
		);
		WordPressTestState::$capabilities['read_post:401'] = true;

		$samples = $this->collector->for_post( 0, 'post' );

		$opening = $samples[0]['opening'];
		$this->assertStringNotContainsString( str_repeat( 'C', 10 ), $opening );
		$this->assertLessThanOrEqual( 1500, self::utf8_length( $opening ) );
		$this->assertStringEndsWith( $second, $opening );
		$this->assertStringNotContainsString( '…', $opening );
	}

	public function test_first_paragraph_over_cap_truncates_with_ellipsis_utf8_safe(): void {
		WordPressTestState::$current_user_id = 5;

		$body = str_repeat( 'A', 1499 ) . "\u{1F642}" . str_repeat( 'B', 200 );

		WordPressTestState::$posts[402]                    = new \WP_Post(
			[
				'ID'            => 402,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'Ellipsis',
				'post_content'  => sprintf(
					'<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->',
					$body
				),
				'post_date_gmt' => '2026-04-15 09:00:00',
			]
		);
		WordPressTestState::$capabilities['read_post:402'] = true;

		$samples = $this->collector->for_post( 0, 'post' );

		$opening = $samples[0]['opening'];
		$this->assertSame( 1, preg_match( '//u', $opening ) );
		$this->assertStringEndsWith( '…', $opening );
		$this->assertStringContainsString( str_repeat( 'A', 1499 ) . "\u{1F642}", $opening );
		$this->assertStringNotContainsString( 'B', $opening );
	}

	public function test_drops_sample_whose_opening_truncates_to_empty(): void {
		WordPressTestState::$current_user_id = 5;

		WordPressTestState::$posts[403]                    = new \WP_Post(
			[
				'ID'            => 403,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'EmptyAfterStrip',
				'post_content'  => '<!-- wp:html --><div></div><!-- /wp:html -->',
				'post_date_gmt' => '2026-04-15 09:00:00',
			]
		);
		WordPressTestState::$capabilities['read_post:403'] = true;

		$samples = $this->collector->for_post( 0, 'post' );

		$titles = array_column( $samples, 'title' );
		$this->assertNotContains( 'EmptyAfterStrip', $titles );
	}

	public function test_drops_attribute_only_rendered_output(): void {
		WordPressTestState::$current_user_id = 5;

		register_block_type(
			'flavor-agent-test/voice-image-only',
			[
				'render_callback' => static fn (): string => '<img src="https://example.test/img.jpg" alt="A description" />',
			]
		);

		WordPressTestState::$posts[404]                    = new \WP_Post(
			[
				'ID'            => 404,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'ImageOnly',
				'post_content'  => '<!-- wp:flavor-agent-test/voice-image-only /-->',
				'post_date_gmt' => '2026-04-15 09:00:00',
			]
		);
		WordPressTestState::$capabilities['read_post:404'] = true;

		$samples = $this->collector->for_post( 0, 'post' );

		$titles = array_column( $samples, 'title' );
		$this->assertNotContains( 'ImageOnly', $titles );
	}

	public function test_published_uses_post_date_gmt_when_available(): void {
		WordPressTestState::$current_user_id               = 5;
		WordPressTestState::$posts[500]                    = new \WP_Post(
			[
				'ID'            => 500,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'Dated',
				'post_content'  => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'post_date'     => '2026-04-12 12:00:00',
				'post_date_gmt' => '2026-04-12 16:00:00',
			]
		);
		WordPressTestState::$capabilities['read_post:500'] = true;

		$samples = $this->collector->for_post( 0, 'post' );

		$this->assertSame( '2026-04-12', $samples[0]['published'] );
	}

	public function test_published_falls_back_to_post_date_when_gmt_empty(): void {
		WordPressTestState::$current_user_id               = 5;
		WordPressTestState::$posts[501]                    = new \WP_Post(
			[
				'ID'            => 501,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'NoGmt',
				'post_content'  => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'post_date'     => '2026-04-12 12:00:00',
				'post_date_gmt' => '',
			]
		);
		WordPressTestState::$capabilities['read_post:501'] = true;

		$samples = $this->collector->for_post( 0, 'post' );

		$this->assertSame( '2026-04-12', $samples[0]['published'] );
	}

	public function test_facade_routes_to_collector(): void {
		WordPressTestState::$current_user_id               = 5;
		WordPressTestState::$posts[510]                    = new \WP_Post(
			[
				'ID'            => 510,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 5,
				'post_title'    => 'Routed',
				'post_content'  => '<!-- wp:paragraph --><p>Routed body.</p><!-- /wp:paragraph -->',
				'post_date_gmt' => '2026-04-15 09:00:00',
			]
		);
		WordPressTestState::$capabilities['read_post:510'] = true;

		$samples = ServerCollector::for_post_voice_samples( 0, 'post' );

		$this->assertCount( 1, $samples );
		$this->assertSame( 'Routed', $samples[0]['title'] );
	}

	private static function utf8_length( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		if ( preg_match_all( '/./us', $text, $matches ) ) {
			return count( $matches[0] );
		}

		return strlen( $text );
	}
}
