<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\BlockContentCanonicalizer;
use PHPUnit\Framework\TestCase;

final class BlockContentCanonicalizerTest extends TestCase {

	/**
	 * @dataProvider content_fixtures
	 */
	public function test_digest_matches_existing_executor_expression( string $content ): void {
		$this->assertSame(
			hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) ),
			BlockContentCanonicalizer::digest( $content )
		);
	}

	/**
	 * @dataProvider content_fixtures
	 */
	public function test_bytes_are_idempotent( string $content ): void {
		$canonical = BlockContentCanonicalizer::bytes( $content );

		$this->assertSame( $canonical, BlockContentCanonicalizer::bytes( $canonical ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function content_fixtures(): array {
		return [
			'plain blocks'            => [
				'<!-- wp:paragraph --><p>Hello world.</p><!-- /wp:paragraph -->',
			],
			'nested inner blocks'     => [
				'<!-- wp:group {"layout":{"type":"constrained"}} -->'
				. '<div class="wp-block-group">'
				. '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->'
				. '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
				. '</div>'
				. '<!-- /wp:group -->',
			],
			'template part slug'      => [
				'<!-- wp:template-part {"slug":"header","theme":"twentytwentyfive","tagName":"header"} /-->',
			],
			'pattern resolved output' => [
				'<!-- wp:group {"layout":{"type":"constrained"}} -->'
				. '<div class="wp-block-group">'
				. '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading -->'
				. '<!-- wp:buttons --><div class="wp-block-buttons">'
				. '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Read more</a></div><!-- /wp:button -->'
				. '</div><!-- /wp:buttons -->'
				. '</div>'
				. '<!-- /wp:group -->',
			],
			'unicode attributes'      => [
				'<!-- wp:paragraph {"metadata":{"name":"Crème brûlée — 東京","categories":["café","niño"]}} -->'
				. '<p>Résumé 🌶️</p>'
				. '<!-- /wp:paragraph -->',
			],
			'empty content'           => [ '' ],
		];
	}
}
