<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class TemplateApplyExecutorTest extends TestCase {

	private const TEMPLATE_REF = 'twentytwentyfive//home';

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$active_theme = [ 'stylesheet' => 'twentytwentyfive' ];
	}

	/**
	 * Seed the live template into the get_block_template(s) store so the bound
	 * TemplateRepository::resolve_template resolves it. When $wp_id > 0 also seed
	 * a wp_template post as the wp_update_post target.
	 */
	private function seed_template( string $content, int $wp_id = 0, string $slug = 'home' ): void {
		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => $wp_id,
				'slug'    => $slug,
				'title'   => 'Home',
				'content' => $content,
			],
		];

		if ( $wp_id > 0 ) {
			WordPressTestState::$posts[ $wp_id ] = new \WP_Post(
				[
					'ID'           => $wp_id,
					'post_type'    => 'wp_template',
					'post_content' => $content,
				]
			);
		}
	}

	public function test_resolve_template_for_apply_returns_the_live_template(): void {
		$this->seed_template( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' );

		$template = ServerCollector::resolve_template_for_apply( self::TEMPLATE_REF );

		$this->assertIsObject( $template );
		$this->assertSame( self::TEMPLATE_REF, $template->id );
	}

	public function test_resolve_template_for_apply_returns_null_when_missing(): void {
		$this->assertNull( ServerCollector::resolve_template_for_apply( 'twentytwentyfive//does-not-exist' ) );
	}
}
