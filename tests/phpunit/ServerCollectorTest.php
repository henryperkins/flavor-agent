<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ServerCollectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();

		WordPressTestState::$block_templates = [
			'wp_template'      => [
				(object) [
					'id'      => 'theme//home',
					'slug'    => 'home',
					'title'   => 'Home',
					'content' => '<!-- wp:group --><div>Home</div><!-- /wp:group -->',
				],
			],
			'wp_template_part' => [
				(object) [
					'slug'    => 'header',
					'title'   => 'Header',
					'area'    => 'header',
					'content' => '<!-- wp:group -->Header<!-- /wp:group -->',
				],
				(object) [
					'slug'    => 'footer',
					'title'   => 'Footer',
					'area'    => 'footer',
					'content' => '<!-- wp:group -->Footer<!-- /wp:group -->',
				],
			],
		];
	}

	private function register_pattern( string $name, array $properties ): void {
		if ( ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
			$this->markTestSkipped( 'WP_Block_Patterns_Registry is not available.' );
		}
		\WP_Block_Patterns_Registry::get_instance()->register( $name, $properties );
	}

	public function test_for_template_parts_can_skip_content_when_only_metadata_is_needed(): void {
		$result = ServerCollector::for_template_parts( null, false );

		$this->assertSame(
			[
				[
					'slug'  => 'header',
					'title' => 'Header',
					'area'  => 'header',
				],
				[
					'slug'  => 'footer',
					'title' => 'Footer',
					'area'  => 'footer',
				],
			],
			$result
		);
	}

	public function test_for_template_part_areas_returns_slug_to_area_lookup(): void {
		$this->assertSame(
			[
				'header' => 'header',
				'footer' => 'footer',
			],
			ServerCollector::for_template_part_areas()
		);
	}

	public function test_introspect_block_type_supports_content_role_and_experimental_role(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/content-block',
			[
				'title'      => 'Content Block',
				'supports'   => [
					'contentRole' => true,
				],
				'attributes' => [
					'legacyContent' => [
						'type'               => 'string',
						'__experimentalRole' => 'content',
					],
					'className'     => [
						'type' => 'string',
					],
				],
			]
		);

		$manifest = ServerCollector::introspect_block_type( 'plugin/content-block' );

		$this->assertTrue( $manifest['supportsContentRole'] );
		$this->assertSame( 'content', $manifest['contentAttributes']['legacyContent']['role'] );
		$this->assertArrayNotHasKey( 'legacyContent', $manifest['configAttributes'] );
	}

	public function test_for_template_limits_candidate_patterns_after_typed_then_generic_ordering(): void {
		for ( $index = 1; $index <= 20; $index++ ) {
			$this->register_pattern(
				"plugin/typed-{$index}",
				[
					'title'         => "Typed {$index}",
					'templateTypes' => [ 'home' ],
					'content'       => "<!-- wp:paragraph --><p>Typed {$index}</p><!-- /wp:paragraph -->",
				]
			);
		}

		for ( $index = 1; $index <= 15; $index++ ) {
			$this->register_pattern(
				"plugin/generic-{$index}",
				[
					'title'         => "Generic {$index}",
					'templateTypes' => [],
					'content'       => "<!-- wp:paragraph --><p>Generic {$index}</p><!-- /wp:paragraph -->",
				]
			);
		}

		$result   = ServerCollector::for_template( 'theme//home', 'home' );
		$patterns = $result['patterns'];

		$this->assertCount( 30, $patterns );
		$this->assertSame(
			array_map(
				static fn ( int $index ): string => "plugin/typed-{$index}",
				range( 1, 20 )
			),
			array_column( array_slice( $patterns, 0, 20 ), 'name' )
		);
		$this->assertSame(
			array_map(
				static fn ( int $index ): string => "plugin/generic-{$index}",
				range( 1, 10 )
			),
			array_column( array_slice( $patterns, 20 ), 'name' )
		);
		$this->assertSame(
			array_fill( 0, 20, 'typed' ),
			array_column( array_slice( $patterns, 0, 20 ), 'matchType' )
		);
		$this->assertSame(
			array_fill( 0, 10, 'generic' ),
			array_column( array_slice( $patterns, 20 ), 'matchType' )
		);

		foreach ( $patterns as $pattern ) {
			$this->assertArrayNotHasKey( 'content', $pattern );
		}
	}
}
