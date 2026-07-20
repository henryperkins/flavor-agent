<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\BlockTypeIntrospector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class BlockTypeIntrospectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_extract_active_style_matches_exact_style_tokens(): void {
		$introspector = new BlockTypeIntrospector();
		$styles       = [
			[ 'name' => 'outline' ],
			[ 'name' => 'rounded' ],
		];

		$this->assertSame(
			'outline',
			$introspector->extract_active_style( 'wp-block is-style-outline has-text-color', $styles )
		);
		$this->assertNull(
			$introspector->extract_active_style( 'wp-block is-style-outline-extra', $styles )
		);
		$this->assertSame(
			'rounded',
			$introspector->extract_active_style( 'is-style-rounded is-style-outline-extra', $styles )
		);
	}

	private function register_block_with_styles( string $block_name, array $styles ): void {
		\WP_Block_Type_Registry::get_instance()->register(
			$block_name,
			[
				'title'  => 'Fixture',
				'styles' => $styles,
			]
		);
	}

	public function test_manifest_includes_registry_registered_styles(): void {
		$this->register_block_with_styles( 'fixture/card', [] );
		\WP_Block_Styles_Registry::get_instance()->register(
			'fixture/card',
			[
				'name'       => 'outline',
				'label'      => 'Outline',
				'is_default' => true,
			]
		);

		$manifest = ( new BlockTypeIntrospector() )->introspect_block_type( 'fixture/card' );

		$this->assertSame(
			[
				[
					'name'      => 'outline',
					'label'     => 'Outline',
					'isDefault' => true,
				],
			],
			$manifest['styles'] ?? null
		);
	}

	public function test_manifest_backfills_registry_style_label_from_name(): void {
		$this->register_block_with_styles( 'fixture/card', [] );
		\WP_Block_Styles_Registry::get_instance()->register(
			'fixture/card',
			[ 'name' => 'outline' ]
		);

		$manifest = ( new BlockTypeIntrospector() )->introspect_block_type( 'fixture/card' );

		$this->assertSame( 'outline', $manifest['styles'][0]['label'] ?? null );
		$this->assertFalse( $manifest['styles'][0]['isDefault'] ?? null );
	}

	public function test_manifest_prefers_block_json_style_on_name_collision(): void {
		$this->register_block_with_styles(
			'fixture/card',
			[
				[
					'name'      => 'outline',
					'label'     => 'JSON Outline',
					'isDefault' => false,
				],
			]
		);
		\WP_Block_Styles_Registry::get_instance()->register(
			'fixture/card',
			[
				'name'       => 'outline',
				'label'      => 'Registry Outline',
				'is_default' => true,
			]
		);

		$manifest = ( new BlockTypeIntrospector() )->introspect_block_type( 'fixture/card' );

		$this->assertCount( 1, $manifest['styles'] ?? [] );
		$this->assertSame( 'JSON Outline', $manifest['styles'][0]['label'] ?? null );
		$this->assertFalse( $manifest['styles'][0]['isDefault'] ?? null );
	}

	public function test_manifest_dedupes_styles_on_the_sanitized_name(): void {
		$this->register_block_with_styles(
			'fixture/card',
			[
				[
					'name'  => 'outline',
					'label' => 'JSON Outline',
				],
			]
		);
		\WP_Block_Styles_Registry::get_instance()->register(
			'fixture/card',
			[
				'name'  => 'Outline',
				'label' => 'Registry Outline',
			]
		);

		$manifest = ( new BlockTypeIntrospector() )->introspect_block_type( 'fixture/card' );

		// Downstream consumers all sanitize before comparing, so the manifest
		// must collapse case variants the same way registeredStyles does.
		$this->assertCount( 1, $manifest['styles'] ?? [] );
		$this->assertSame( 'JSON Outline', $manifest['styles'][0]['label'] ?? null );
	}

	public function test_manifest_keeps_block_json_styles_when_registry_is_empty(): void {
		$this->register_block_with_styles(
			'fixture/card',
			[
				[
					'name'      => 'plain',
					'label'     => 'Plain',
					'isDefault' => true,
				],
			]
		);

		$manifest = ( new BlockTypeIntrospector() )->introspect_block_type( 'fixture/card' );

		$this->assertSame(
			[
				[
					'name'      => 'plain',
					'label'     => 'Plain',
					'isDefault' => true,
				],
			],
			$manifest['styles'] ?? null
		);
	}

	public function test_manifest_drops_styles_with_no_usable_name(): void {
		$this->register_block_with_styles(
			'fixture/card',
			[
				[ 'label' => 'Nameless' ],
				'not-an-array',
				[
					'name'  => 'plain',
					'label' => 'Plain',
				],
			]
		);

		$manifest = ( new BlockTypeIntrospector() )->introspect_block_type( 'fixture/card' );

		$this->assertSame(
			[
				[
					'name'      => 'plain',
					'label'     => 'Plain',
					'isDefault' => false,
				],
			],
			$manifest['styles'] ?? null
		);
	}

	public function test_list_registered_blocks_carries_registry_styles(): void {
		$this->register_block_with_styles( 'fixture/card', [] );
		\WP_Block_Styles_Registry::get_instance()->register(
			'fixture/card',
			[
				'name'  => 'outline',
				'label' => 'Outline',
			]
		);

		$manifests = ( new BlockTypeIntrospector() )->list_registered_blocks();
		$card      = null;

		foreach ( $manifests as $manifest ) {
			if ( 'fixture/card' === ( $manifest['name'] ?? '' ) ) {
				$card = $manifest;
				break;
			}
		}

		$this->assertIsArray( $card, 'fixture/card missing from list_registered_blocks()' );
		$this->assertSame( 'outline', $card['styles'][0]['name'] ?? null );
	}
}
