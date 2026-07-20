<?php
/**
 * Pure, server-side analysis of a template part's composition against the
 * expected role vocabulary for its area.
 *
 * One shared foundation feeds three surfaces of the template-part
 * recommendation: content-aware pattern ranking (boost patterns that fill a
 * missing role), design-semantics gap signals (negativeSignals + tokenAffinity
 * + contrastContext), and the prompt's "Area role & composition" section.
 *
 * The area set and role → block signals are grounded in the WordPress 7.0 core
 * template-part areas returned by get_allowed_block_template_part_areas():
 * header, footer, sidebar, navigation-overlay, uncategorized. Only near-required
 * roles are marked `expected` (and therefore flagged when missing); highly
 * variable areas (sidebar, uncategorized) contribute present-role information
 * without prescriptive gaps.
 */

declare(strict_types=1);

namespace FlavorAgent\Support;

final class TemplatePartCompositionProfile {

	private const NEARLY_EMPTY_MAX_BLOCKS = 1;

	private const SLUG_CAP = 8;

	/**
	 * Per-area role definitions. Each role lists the core block names that
	 * satisfy it and whether its absence is a surfaced gap (`expected`).
	 *
	 * @var array<string, array<string, array{blocks: string[], expected: bool, label: string}>>
	 */
	private const AREA_ROLES = [
		'header'             => [
			'branding'           => [
				'blocks'   => [ 'core/site-logo', 'core/site-title' ],
				'expected' => true,
				'label'    => 'branding (logo/site title)',
			],
			'primary-navigation' => [
				'blocks'   => [ 'core/navigation' ],
				'expected' => true,
				'label'    => 'primary navigation',
			],
			'search'             => [
				'blocks'   => [ 'core/search' ],
				'expected' => false,
				'label'    => 'search',
			],
			'utility'            => [
				'blocks'   => [ 'core/social-links', 'core/buttons' ],
				'expected' => false,
				'label'    => 'utility row (social/buttons)',
			],
		],
		'footer'             => [
			'site-identity' => [
				'blocks'   => [ 'core/site-logo', 'core/site-title', 'core/site-tagline' ],
				'expected' => true,
				'label'    => 'site identity (logo/title/tagline)',
			],
			'navigation'    => [
				'blocks'   => [ 'core/navigation' ],
				'expected' => false,
				'label'    => 'navigation',
			],
			'social'        => [
				'blocks'   => [ 'core/social-links' ],
				'expected' => false,
				'label'    => 'social links',
			],
		],
		'sidebar'            => [
			'navigation' => [
				'blocks'   => [ 'core/navigation' ],
				'expected' => false,
				'label'    => 'navigation',
			],
			'search'     => [
				'blocks'   => [ 'core/search' ],
				'expected' => false,
				'label'    => 'search',
			],
		],
		'navigation-overlay' => [
			'primary-navigation' => [
				'blocks'   => [ 'core/navigation' ],
				'expected' => true,
				'label'    => 'navigation',
			],
			'close-control'      => [
				'blocks'   => [ 'core/navigation-overlay-close' ],
				'expected' => true,
				'label'    => 'close control',
			],
		],
	];

	/**
	 * Analyze the part's role coverage from a block-name => count map.
	 *
	 * @param array<string, int> $block_counts Block-name => count for the part.
	 * @param int                $block_count  Total block count in the part.
	 * @return array{
	 *   area: string,
	 *   isEmpty: bool,
	 *   isNearlyEmpty: bool,
	 *   hasRoleVocabulary: bool,
	 *   expectedRoles: array<int, array{role: string, label: string, present: bool}>,
	 *   presentRoles: string[],
	 *   missingRoles: string[],
	 *   optionalPresentRoles: string[],
	 *   completeness: float,
	 *   negativeSignals: string[]
	 * }
	 */
	public static function analyze( string $area, array $block_counts, int $block_count ): array {
		$area         = sanitize_key( $area );
		$roles        = self::AREA_ROLES[ $area ] ?? [];
		$is_empty     = $block_count <= 0;
		$nearly_empty = $block_count <= self::NEARLY_EMPTY_MAX_BLOCKS;

		$expected_roles         = [];
		$present_roles          = [];
		$missing_roles          = [];
		$optional_present_roles = [];
		$expected_total         = 0;
		$expected_present       = 0;

		foreach ( $roles as $role => $definition ) {
			$present = false;

			foreach ( $definition['blocks'] as $block_name ) {
				if ( (int) ( $block_counts[ $block_name ] ?? 0 ) > 0 ) {
					$present = true;
					break;
				}
			}

			if ( $definition['expected'] ) {
				$expected_roles[] = [
					'role'    => $role,
					'label'   => $definition['label'],
					'present' => $present,
				];
				++$expected_total;

				if ( $present ) {
					$present_roles[] = $role;
					++$expected_present;
				} else {
					$missing_roles[] = $role;
				}
			} elseif ( $present ) {
				$optional_present_roles[] = $role;
			}
		}

		$completeness = $expected_total > 0
			? round( $expected_present / $expected_total, 4 )
			: ( $is_empty ? 0.0 : 1.0 );

		$negative_signals = [];

		if ( $is_empty ) {
			$negative_signals[] = 'empty-template-part';
		} elseif ( $nearly_empty ) {
			$negative_signals[] = 'nearly-empty-template-part';
		}

		foreach ( $missing_roles as $role ) {
			$negative_signals[] = ( '' !== $area ? $area . '-' : '' ) . 'missing-' . $role;
		}

		return [
			'area'                 => $area,
			'isEmpty'              => $is_empty,
			'isNearlyEmpty'        => $nearly_empty,
			'hasRoleVocabulary'    => [] !== $roles,
			'expectedRoles'        => $expected_roles,
			'presentRoles'         => $present_roles,
			'missingRoles'         => $missing_roles,
			'optionalPresentRoles' => $optional_present_roles,
			'completeness'         => (float) $completeness,
			'negativeSignals'      => array_values( array_unique( $negative_signals ) ),
		];
	}

	/**
	 * Map a missing role to the core block names that would satisfy it, so
	 * pattern ranking can boost candidates that supply what the part lacks.
	 *
	 * @param string $area
	 * @param string $role
	 * @return string[]
	 */
	public static function blocks_for_role( string $area, string $role ): array {
		$roles = self::AREA_ROLES[ sanitize_key( $area ) ] ?? [];

		return isset( $roles[ $role ]['blocks'] ) && is_array( $roles[ $role ]['blocks'] )
			? $roles[ $role ]['blocks']
			: [];
	}

	/**
	 * Extract the theme preset slugs actually used across the part's blocks,
	 * grouped for DesignSemantics::tokenAffinity. Preset-backed attributes
	 * (backgroundColor/textColor/fontSize) yield their slug; inline
	 * `var:preset|<family>|<slug>` custom values are parsed for their slug.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks (parse_blocks()).
	 * @return array{color: string[], spacing: string[], fontSize: string[]}
	 */
	public static function collect_token_affinity( array $blocks ): array {
		$affinity = [
			'color'    => [],
			'spacing'  => [],
			'fontSize' => [],
		];

		self::walk_token_affinity( $blocks, $affinity );

		return [
			'color'    => self::dedupe_slugs( $affinity['color'] ),
			'spacing'  => self::dedupe_slugs( $affinity['spacing'] ),
			'fontSize' => self::dedupe_slugs( $affinity['fontSize'] ),
		];
	}

	/**
	 * Classify the tone of the part's dominant (outermost) background into a
	 * DesignSemantics contrastContext value, when confidently derivable from an
	 * explicit background. Returns '' when unknown so callers can fall back to
	 * the client-provided value instead of guessing.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, mixed>             $theme_tokens Theme token payload from ThemeTokenCollector::for_tokens().
	 */
	public static function classify_root_contrast( array $blocks, array $theme_tokens ): string {
		$root = self::first_named_block( $blocks );

		if ( null === $root ) {
			return '';
		}

		$attrs = is_array( $root['attrs'] ?? null ) ? $root['attrs'] : [];
		$style = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : [];

		if (
			isset( $attrs['gradient'] )
			|| isset( $style['color']['gradient'] )
			|| isset( $style['background']['backgroundImage'] )
		) {
			return 'image-overlay';
		}

		$hex = self::resolve_background_hex( $attrs, $theme_tokens );

		if ( null === $hex ) {
			return '';
		}

		$luminance = self::relative_luminance( $hex );

		if ( $luminance < 0 ) {
			return '';
		}

		return $luminance < 0.4 ? 'dark-parent' : 'light-parent';
	}

	/**
	 * @param array<int, array<string, mixed>>                                 $blocks
	 * @param array{color: string[], spacing: string[], fontSize: string[]} $affinity
	 */
	private static function walk_token_affinity( array $blocks, array &$affinity ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

			foreach ( [ 'backgroundColor', 'textColor', 'gradient' ] as $slug_attr ) {
				if ( isset( $attrs[ $slug_attr ] ) && is_string( $attrs[ $slug_attr ] ) && '' !== $attrs[ $slug_attr ] ) {
					$affinity['color'][] = sanitize_key( $attrs[ $slug_attr ] );
				}
			}

			if ( isset( $attrs['fontSize'] ) && is_string( $attrs['fontSize'] ) && '' !== $attrs['fontSize'] ) {
				$affinity['fontSize'][] = sanitize_key( $attrs['fontSize'] );
			}

			if ( isset( $attrs['style'] ) && is_array( $attrs['style'] ) ) {
				self::collect_preset_refs( $attrs['style'], $affinity );
			}

			$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( [] !== $inner ) {
				self::walk_token_affinity( $inner, $affinity );
			}
		}
	}

	/**
	 * Deep-scan a style subtree for `var:preset|<family>|<slug>` references and
	 * route each slug into the matching affinity family.
	 *
	 * @param array<string, mixed>                                          $style
	 * @param array{color: string[], spacing: string[], fontSize: string[]} $affinity
	 */
	private static function collect_preset_refs( array $style, array &$affinity ): void {
		foreach ( $style as $value ) {
			if ( is_array( $value ) ) {
				self::collect_preset_refs( $value, $affinity );
				continue;
			}

			if ( ! is_string( $value ) ) {
				continue;
			}

			if ( ! preg_match( '/var:preset\|([a-z-]+)\|([A-Za-z0-9-]+)/', $value, $matches ) ) {
				continue;
			}

			$family = $matches[1];
			$slug   = sanitize_key( $matches[2] );

			if ( '' === $slug ) {
				continue;
			}

			switch ( $family ) {
				case 'color':
				case 'gradient':
					$affinity['color'][] = $slug;
					break;
				case 'spacing':
					$affinity['spacing'][] = $slug;
					break;
				case 'font-size':
					$affinity['fontSize'][] = $slug;
					break;
			}
		}
	}

	/**
	 * @param string[] $slugs
	 * @return string[]
	 */
	private static function dedupe_slugs( array $slugs ): array {
		$slugs = array_values(
			array_unique(
				array_filter(
					$slugs,
					static fn( string $slug ): bool => '' !== $slug
				)
			)
		);
		sort( $slugs, SORT_STRING );

		return array_slice( $slugs, 0, self::SLUG_CAP );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<string, mixed>|null
	 */
	private static function first_named_block( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && '' !== (string) ( $block['blockName'] ?? '' ) ) {
				return $block;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $attrs
	 * @param array<string, mixed> $theme_tokens
	 */
	private static function resolve_background_hex( array $attrs, array $theme_tokens ): ?string {
		$style = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : [];

		$raw = $style['color']['background'] ?? null;

		if ( is_string( $raw ) && '' !== $raw ) {
			if ( preg_match( '/var:preset\|color\|([A-Za-z0-9-]+)/', $raw, $matches ) ) {
				return self::preset_color_hex( sanitize_key( $matches[1] ), $theme_tokens );
			}

			if ( str_starts_with( $raw, '#' ) ) {
				return $raw;
			}

			return null;
		}

		$named = $attrs['backgroundColor'] ?? null;

		if ( is_string( $named ) && '' !== $named ) {
			return self::preset_color_hex( sanitize_key( $named ), $theme_tokens );
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $theme_tokens
	 */
	private static function preset_color_hex( string $slug, array $theme_tokens ): ?string {
		$presets = is_array( $theme_tokens['colorPresets'] ?? null ) ? $theme_tokens['colorPresets'] : [];

		foreach ( $presets as $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}

			if ( sanitize_key( (string) ( $preset['slug'] ?? '' ) ) === $slug ) {
				$color = (string) ( $preset['color'] ?? '' );

				return '' !== $color ? $color : null;
			}
		}

		return null;
	}

	private static function relative_luminance( string $hex ): float {
		$hex = ltrim( trim( $hex ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 8 === strlen( $hex ) ) {
			$hex = substr( $hex, 0, 6 );
		}

		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return -1.0;
		}

		$channels = [];

		foreach ( [ 0, 2, 4 ] as $offset ) {
			$value      = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $value <= 0.03928
				? $value / 12.92
				: pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}
}
