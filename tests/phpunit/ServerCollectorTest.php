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

	public function test_for_template_part_returns_summarized_block_tree_and_area_ranked_patterns(): void {
		WordPressTestState::$block_templates['wp_template_part'][0]->content =
			'<!-- wp:group {"tagName":"header","align":"wide"} -->'
			. '<!-- wp:site-logo /-->'
			. '<!-- wp:navigation {"overlayMenu":"mobile","maxNestingLevel":2} /-->'
			. '<!-- /wp:group -->';

		$this->register_pattern(
			'theme/header-utility',
			[
				'title'      => 'Header Utility Row',
				'categories' => [ 'header' ],
				'blockTypes' => [ 'core/template-part/header' ],
				'content'    => '<!-- wp:paragraph --><p>Header utility</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/footer-columns',
			[
				'title'      => 'Footer Columns',
				'categories' => [ 'footer' ],
				'blockTypes' => [ 'core/template-part/footer' ],
				'content'    => '<!-- wp:paragraph --><p>Footer columns</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/generic-stack',
			[
				'title'   => 'Generic Stack',
				'content' => '<!-- wp:paragraph --><p>Generic stack</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/home-hero',
			[
				'title'         => 'Home Hero',
				'templateTypes' => [ 'home' ],
				'content'       => '<!-- wp:paragraph --><p>Home hero</p><!-- /wp:paragraph -->',
			]
		);

		$result        = ServerCollector::for_template_part( 'theme//header' );
		$pattern_names = array_column( $result['patterns'], 'name' );

		$this->assertIsArray( $result );
		$this->assertSame( 'theme//header', $result['templatePartRef'] );
		$this->assertSame( 'header', $result['slug'] );
		$this->assertSame( 'header', $result['area'] );
		$this->assertSame( [ 'core/group' ], $result['topLevelBlocks'] );
		$this->assertSame(
			[
				[
					'path'       => [ 0 ],
					'name'       => 'core/group',
					'attributes' => [
						'tagName' => 'header',
						'align'   => 'wide',
					],
					'childCount' => 2,
					'children'   => [
						[
							'path'       => [ 0, 0 ],
							'name'       => 'core/site-logo',
							'attributes' => [],
							'childCount' => 0,
							'children'   => [],
						],
						[
							'path'       => [ 0, 1 ],
							'name'       => 'core/navigation',
							'attributes' => [
								'overlayMenu'     => 'mobile',
								'maxNestingLevel' => 2,
							],
							'childCount' => 0,
							'children'   => [],
						],
					],
				],
			],
			$result['blockTree']
		);
		$this->assertSame( 3, $result['structureStats']['blockCount'] );
		$this->assertSame( 2, $result['structureStats']['maxDepth'] );
		$this->assertTrue( $result['structureStats']['hasNavigation'] );
		$this->assertTrue( $result['structureStats']['containsLogo'] );
		$this->assertTrue( $result['structureStats']['hasSingleWrapperGroup'] );
		$this->assertSame( 'theme/header-utility', $result['patterns'][0]['name'] );
		$this->assertSame( 'area', $result['patterns'][0]['matchType'] );
		$this->assertArrayNotHasKey( 'content', $result['patterns'][0] );
		$this->assertContains( 'theme/generic-stack', $pattern_names );
		$this->assertNotContains( 'theme/home-hero', $pattern_names );
	}

	public function test_for_tokens_includes_duotone_presets_in_compact_summary(): void {
		WordPressTestState::$global_settings = [
			'color'      => [
				'gradients' => [
					'theme' => [
						[
							'slug'     => 'sunset',
							'gradient' => 'linear-gradient(135deg,#f60,#fc0)',
						],
					],
				],
				'duotone'   => [
					'theme' => [
						[
							'slug'   => 'midnight',
							'colors' => [ '#111111', '#f5f5f5' ],
						],
					],
				],
			],
			'typography' => [],
			'spacing'    => [
				'blockGap' => true,
			],
			'shadow'     => [],
			'layout'     => [
				'allowEditing' => false,
			],
			'background' => [
				'backgroundImage' => true,
				'backgroundSize'  => false,
			],
			'border'     => [
				'style' => true,
			],
		];
		WordPressTestState::$global_styles   = [
			'elements' => [
				'heading' => [
					'color' => [
						'text' => 'var(--wp--preset--color--contrast)',
					],
				],
			],
		];

		$tokens = ServerCollector::for_tokens();

		$this->assertArrayHasKey( 'duotone', $tokens );
		$this->assertSame( [ 'midnight: #111111 / #f5f5f5' ], $tokens['duotone'] );
		$this->assertSame(
			[
				[
					'slug'   => 'midnight',
					'colors' => [ '#111111', '#f5f5f5' ],
				],
			],
			$tokens['duotonePresets']
		);
		$this->assertSame(
			[ 'sunset: linear-gradient(135deg,#f60,#fc0)' ],
			$tokens['gradients']
		);
		$this->assertFalse( $tokens['layout']['allowEditing'] );
		$this->assertTrue( $tokens['enabledFeatures']['blockGap'] );
		$this->assertTrue( $tokens['enabledFeatures']['backgroundImage'] );
		$this->assertTrue( $tokens['enabledFeatures']['borderStyle'] );
		$this->assertSame(
			[
				'heading' => [
					'base'         => [
						'text' => 'var(--wp--preset--color--contrast)',
					],
					'hover'        => [],
					'focus'        => [],
					'focusVisible' => [],
				],
			],
			$tokens['elementStyles']
		);
	}
}
