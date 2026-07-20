<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\TemplatePartCompositionProfile;
use PHPUnit\Framework\TestCase;

final class TemplatePartCompositionProfileTest extends TestCase {

	public function test_header_missing_navigation_reports_gap(): void {
		$profile = TemplatePartCompositionProfile::analyze(
			'header',
			[
				'core/site-logo' => 1,
				'core/group'     => 1,
			],
			2
		);

		$this->assertTrue( $profile['hasRoleVocabulary'] );
		$this->assertFalse( $profile['isEmpty'] );
		$this->assertContains( 'branding', $profile['presentRoles'] );
		$this->assertContains( 'primary-navigation', $profile['missingRoles'] );
		$this->assertContains( 'header-missing-primary-navigation', $profile['negativeSignals'] );
		$this->assertSame( 0.5, $profile['completeness'] );

		$branding = array_values(
			array_filter(
				$profile['expectedRoles'],
				static fn( array $role ): bool => 'branding' === $role['role']
			)
		);
		$this->assertNotEmpty( $branding );
		$this->assertTrue( $branding[0]['present'] );
	}

	public function test_complete_header_has_no_gaps(): void {
		$profile = TemplatePartCompositionProfile::analyze(
			'header',
			[
				'core/site-logo'  => 1,
				'core/navigation' => 1,
				'core/search'     => 1,
			],
			3
		);

		$this->assertSame( [], $profile['missingRoles'] );
		$this->assertSame( 1.0, $profile['completeness'] );
		$this->assertContains( 'search', $profile['optionalPresentRoles'] );
		$this->assertSame( [], $profile['negativeSignals'] );
	}

	public function test_empty_part_reports_empty_signal(): void {
		$profile = TemplatePartCompositionProfile::analyze( 'header', [], 0 );

		$this->assertTrue( $profile['isEmpty'] );
		$this->assertTrue( $profile['isNearlyEmpty'] );
		$this->assertContains( 'empty-template-part', $profile['negativeSignals'] );
		$this->assertContains( 'header-missing-branding', $profile['negativeSignals'] );
		$this->assertSame( 0.0, $profile['completeness'] );
	}

	public function test_navigation_overlay_requires_navigation_and_close(): void {
		$profile = TemplatePartCompositionProfile::analyze(
			'navigation-overlay',
			[ 'core/navigation' => 1 ],
			1
		);

		$this->assertContains( 'close-control', $profile['missingRoles'] );
		$this->assertNotContains( 'primary-navigation', $profile['missingRoles'] );
		$this->assertContains( 'navigation-overlay-missing-close-control', $profile['negativeSignals'] );
	}

	public function test_sidebar_has_vocabulary_but_no_hard_gaps(): void {
		$profile = TemplatePartCompositionProfile::analyze(
			'sidebar',
			[ 'core/navigation' => 1 ],
			1
		);

		$this->assertTrue( $profile['hasRoleVocabulary'] );
		$this->assertSame( [], $profile['missingRoles'] );
		$this->assertContains( 'navigation', $profile['optionalPresentRoles'] );
	}

	public function test_unknown_area_has_no_role_vocabulary(): void {
		$profile = TemplatePartCompositionProfile::analyze(
			'uncategorized',
			[ 'core/paragraph' => 2 ],
			2
		);

		$this->assertFalse( $profile['hasRoleVocabulary'] );
		$this->assertSame( [], $profile['missingRoles'] );
		$this->assertSame( [], $profile['negativeSignals'] );
	}

	public function test_blocks_for_role_maps_missing_role_to_blocks(): void {
		$this->assertSame(
			[ 'core/navigation' ],
			TemplatePartCompositionProfile::blocks_for_role( 'header', 'primary-navigation' )
		);
		$this->assertSame(
			[],
			TemplatePartCompositionProfile::blocks_for_role( 'header', 'nonexistent' )
		);
	}

	public function test_collect_token_affinity_groups_and_dedupes_preset_slugs(): void {
		$blocks = [
			[
				'blockName'   => 'core/group',
				'attrs'       => [
					'backgroundColor' => 'primary',
					'style'           => [
						'spacing' => [
							'padding' => [ 'top' => 'var:preset|spacing|50' ],
						],
					],
				],
				'innerBlocks' => [
					[
						'blockName'   => 'core/site-title',
						'attrs'       => [
							'textColor' => 'primary',
							'fontSize'  => 'large',
							'style'     => [
								'elements' => [
									'link' => [
										'color' => [ 'text' => 'var:preset|color|accent' ],
									],
								],
							],
						],
						'innerBlocks' => [],
					],
				],
			],
		];

		$affinity = TemplatePartCompositionProfile::collect_token_affinity( $blocks );

		$this->assertSame( [ 'accent', 'primary' ], $affinity['color'] );
		$this->assertSame( [ '50' ], $affinity['spacing'] );
		$this->assertSame( [ 'large' ], $affinity['fontSize'] );
	}

	public function test_classify_root_contrast_uses_background_luminance(): void {
		$theme_tokens = [
			'colorPresets' => [
				[
					'slug'  => 'dark',
					'color' => '#111111',
				],
				[
					'slug'  => 'light',
					'color' => '#ffffff',
				],
			],
		];

		$dark = TemplatePartCompositionProfile::classify_root_contrast(
			[
				[
					'blockName'   => 'core/group',
					'attrs'       => [ 'backgroundColor' => 'dark' ],
					'innerBlocks' => [],
				],
			],
			$theme_tokens
		);
		$this->assertSame( 'dark-parent', $dark );

		$light = TemplatePartCompositionProfile::classify_root_contrast(
			[
				[
					'blockName'   => 'core/group',
					'attrs'       => [ 'style' => [ 'color' => [ 'background' => '#ffffff' ] ] ],
					'innerBlocks' => [],
				],
			],
			$theme_tokens
		);
		$this->assertSame( 'light-parent', $light );

		$overlay = TemplatePartCompositionProfile::classify_root_contrast(
			[
				[
					'blockName'   => 'core/cover',
					'attrs'       => [ 'gradient' => 'midnight' ],
					'innerBlocks' => [],
				],
			],
			$theme_tokens
		);
		$this->assertSame( 'image-overlay', $overlay );

		$unknown = TemplatePartCompositionProfile::classify_root_contrast(
			[
				[
					'blockName'   => 'core/group',
					'attrs'       => [],
					'innerBlocks' => [],
				],
			],
			$theme_tokens
		);
		$this->assertSame( '', $unknown );
	}

	public function test_classify_root_contrast_detects_media_backed_cover(): void {
		$media_cover = TemplatePartCompositionProfile::classify_root_contrast(
			[
				[
					'blockName'   => 'core/cover',
					'attrs'       => [
						'url'          => 'https://example.com/hero.jpg',
						'id'           => 42,
						'dimRatio'     => 50,
						'overlayColor' => 'dark',
					],
					'innerBlocks' => [],
				],
			],
			[]
		);
		$this->assertSame( 'image-overlay', $media_cover );

		$featured_cover = TemplatePartCompositionProfile::classify_root_contrast(
			[
				[
					'blockName'   => 'core/cover',
					'attrs'       => [ 'useFeaturedImage' => true ],
					'innerBlocks' => [],
				],
			],
			[]
		);
		$this->assertSame( 'image-overlay', $featured_cover );

		// A color-only Cover carries no media, so it is not an overlay context.
		$color_only_cover = TemplatePartCompositionProfile::classify_root_contrast(
			[
				[
					'blockName'   => 'core/cover',
					'attrs'       => [
						'overlayColor' => 'light',
						'dimRatio'     => 100,
					],
					'innerBlocks' => [],
				],
			],
			[]
		);
		$this->assertSame( '', $color_only_cover );
	}

	public function test_collect_token_affinity_captures_every_preset_in_one_value(): void {
		$blocks = [
			[
				'blockName'   => 'core/group',
				'attrs'       => [
					'style' => [
						'color' => [
							'gradient' => 'linear-gradient(var:preset|color|primary 0%, var:preset|color|secondary 100%)',
						],
					],
				],
				'innerBlocks' => [],
			],
		];

		$affinity = TemplatePartCompositionProfile::collect_token_affinity( $blocks );

		$this->assertSame( [ 'primary', 'secondary' ], $affinity['color'] );
	}

	public function test_classify_root_contrast_treats_transparency_as_unknown(): void {
		$transparent = TemplatePartCompositionProfile::classify_root_contrast(
			[
				[
					'blockName'   => 'core/group',
					'attrs'       => [ 'style' => [ 'color' => [ 'background' => '#00000000' ] ] ],
					'innerBlocks' => [],
				],
			],
			[]
		);
		$this->assertSame( '', $transparent );

		$opaque_eight_digit = TemplatePartCompositionProfile::classify_root_contrast(
			[
				[
					'blockName'   => 'core/group',
					'attrs'       => [ 'style' => [ 'color' => [ 'background' => '#111111ff' ] ] ],
					'innerBlocks' => [],
				],
			],
			[]
		);
		$this->assertSame( 'dark-parent', $opaque_eight_digit );

		$opaque_shorthand = TemplatePartCompositionProfile::classify_root_contrast(
			[
				[
					'blockName'   => 'core/group',
					'attrs'       => [ 'style' => [ 'color' => [ 'background' => '#000f' ] ] ],
					'innerBlocks' => [],
				],
			],
			[]
		);
		$this->assertSame( 'dark-parent', $opaque_shorthand );
	}
}
