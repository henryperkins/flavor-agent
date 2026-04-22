<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class NavigationContextCollector {

	public function __construct(
		private NavigationParser $navigation_parser,
		private TemplateRepository $template_repository,
		private ThemeTokenCollector $theme_token_collector
	) {
	}

	/**
	 * Assemble context for a navigation recommendation.
	 *
	 * Accepts either a wp_navigation post ID or raw navigation block markup.
	 * Extracts menu item structure, overlay configuration, and overlay
	 * template parts (WP 7.0+ navigation-overlay area).
	 *
	 * @param int    $menu_id wp_navigation post ID. 0 to skip.
	 * @param string $markup Serialized navigation block markup. Empty to skip.
	 * @param array  $editor_context Slim editor-context snapshot from the client.
	 * @return array|\WP_Error Navigation context or error.
	 */
	public function for_navigation( int $menu_id = 0, string $markup = '', array $editor_context = [] ): array|\WP_Error {
		$saved_source  = $this->navigation_parser->parse_navigation_source( '' );
		$markup_source = $this->navigation_parser->parse_navigation_source( '' );

		if ( $menu_id > 0 ) {
			$post = get_post( $menu_id );

			if ( ! $post || 'wp_navigation' !== $post->post_type ) {
				return new \WP_Error(
					'navigation_not_found',
					"No wp_navigation post found with ID {$menu_id}.",
					[ 'status' => 404 ]
				);
			}

			$saved_source = $this->navigation_parser->parse_navigation_source(
				(string) ( $post->post_content ?? '' )
			);
		}

		if ( '' !== $markup ) {
			$markup_source = $this->navigation_parser->parse_navigation_source( $markup );
		}

		$nav_attrs = $markup_source['hasNavigationBlock']
			? $markup_source['attrs']
			: $saved_source['attrs'];
		$inner     = $markup_source['hasStructure']
			? $markup_source['inner']
			: $saved_source['inner'];

		$editor_context       = $this->normalize_map( $editor_context );
		$menu_items           = $this->navigation_parser->extract_menu_items( $inner );
		$target_inventory     = $this->navigation_parser->build_target_inventory( $menu_items );
		$attributes           = $this->navigation_parser->collect_navigation_attributes( $nav_attrs );
		$location_details     = $this->resolve_location_details( $menu_id, $editor_context );
		$location             = sanitize_key( (string) ( $location_details['area'] ?? 'unknown' ) );
		$site_overlay_parts   = $this->template_repository->for_template_parts( 'navigation-overlay', true );
		$scoped_overlay_match = $this->resolve_overlay_template_parts(
			$site_overlay_parts,
			$menu_id,
			$location_details
		);
		$overlay_parts        = $scoped_overlay_match['parts'];
		$structure_summary    = $this->navigation_parser->collect_navigation_structure_summary( $menu_items );
		$uses_overlay         = count( $overlay_parts ) > 0 || count( $site_overlay_parts ) > 0 || ( $attributes['overlayMenu'] ?? 'never' ) !== 'never';

		return [
			'menuId'               => $menu_id > 0 ? $menu_id : null,
			'location'             => $location,
			'locationDetails'      => $location_details,
			'attributes'           => $attributes,
			'menuItems'            => $menu_items,
			'targetInventory'      => $target_inventory,
			'menuItemCount'        => $this->navigation_parser->count_menu_items_recursive( $menu_items ),
			'maxDepth'             => $this->navigation_parser->measure_menu_depth( $menu_items ),
			'structureSummary'     => $structure_summary,
			'overlayContext'       => [
				'usesOverlay'                  => $uses_overlay,
				'overlayMode'                  => (string) ( $attributes['overlayMenu'] ?? 'never' ),
				'hasDedicatedOverlayParts'     => count( $overlay_parts ) > 0,
				'overlayTemplatePartCount'     => count( $overlay_parts ),
				'overlayTemplatePartSlugs'     => $this->collect_overlay_slugs( $overlay_parts ),
				'siteHasDedicatedOverlayParts' => count( $site_overlay_parts ) > 0,
				'siteOverlayTemplatePartCount' => count( $site_overlay_parts ),
				'siteOverlayTemplatePartSlugs' => $this->collect_overlay_slugs( $site_overlay_parts ),
				'overlayReferenceScope'        => count( $overlay_parts ) > 0
					? 'scoped'
					: ( count( $site_overlay_parts ) > 0 ? 'site-only' : 'none' ),
				'overlayReferenceSource'       => $scoped_overlay_match['source'],
			],
			'overlayTemplateParts' => $overlay_parts,
			'editorContext'        => $editor_context,
			'themeTokens'          => $this->theme_token_collector->for_tokens(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function resolve_location_details( int $menu_id, array $editor_context = [] ): array {
		$location_details = $this->resolve_location_details_from_editor_context( $editor_context );

		if ( [] !== $location_details ) {
			return $location_details;
		}

		$inferred_area = $menu_id > 0 ? $this->infer_navigation_location( $menu_id ) : 'unknown';

		return [
			'area'   => $inferred_area,
			'source' => 'unknown' !== $inferred_area
				? 'template-part-scan'
				: ( $menu_id > 0 ? 'navigation-post' : 'live-markup' ),
		];
	}

	private function infer_navigation_location( int $menu_id ): string {
		$parts = $this->template_repository->for_template_parts( null, true );

		foreach ( $parts as $part ) {
			$content = $part['content'] ?? '';
			$area    = $part['area'] ?? '';

			if ( '' === $content || '' === $area ) {
				continue;
			}

			$blocks = parse_blocks( $content );

			if ( $this->navigation_parser->blocks_reference_navigation( $blocks, $menu_id ) ) {
				return $area;
			}
		}

		return 'unknown';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function resolve_location_details_from_editor_context( array $editor_context ): array {
		$block      = $this->normalize_map( $editor_context['block'] ?? [] );
		$candidates = [];

		$block_identity = $this->normalize_map( $block['structuralIdentity'] ?? [] );
		if ( [] !== $block_identity ) {
			$candidates[] = $block_identity;
		}

		foreach ( array_reverse( $this->normalize_list( $editor_context['structuralAncestors'] ?? [] ) ) as $ancestor ) {
			if ( is_array( $ancestor ) ) {
				$candidates[] = $ancestor;
			}
		}

		$location           = '';
		$template_area      = '';
		$template_part_slug = '';
		$role               = '';

		foreach ( $candidates as $candidate ) {
			$location_candidate = sanitize_key( (string) ( $candidate['location'] ?? '' ) );
			$area_candidate     = sanitize_key( (string) ( $candidate['templateArea'] ?? '' ) );
			$part_candidate     = sanitize_key( (string) ( $candidate['templatePartSlug'] ?? '' ) );
			$role_candidate     = sanitize_key( (string) ( $candidate['role'] ?? '' ) );

			if ( '' === $location && '' !== $location_candidate && 'unknown' !== $location_candidate ) {
				$location = $location_candidate;
			}

			if ( '' === $template_area && '' !== $area_candidate ) {
				$template_area = $area_candidate;
			}

			if ( '' === $template_part_slug && '' !== $part_candidate ) {
				$template_part_slug = $part_candidate;
			}

			if ( '' === $role && '' !== $role_candidate ) {
				$role = $role_candidate;
			}
		}

		if ( '' === $location && '' !== $template_area ) {
			$location = $template_area;
		}

		if ( '' === $location ) {
			return [];
		}

		$details = [
			'area'   => $location,
			'source' => 'editor-context',
		];

		if ( '' !== $template_area ) {
			$details['templateArea'] = $template_area;
		}

		if ( '' !== $template_part_slug ) {
			$details['templatePartSlug'] = $template_part_slug;
		}

		if ( '' !== $role ) {
			$details['role'] = $role;
		}

		return $details;
	}

	/**
	 * @param array<int, array<string, mixed>> $overlay_parts
	 * @param array<string, mixed>             $location_details
	 * @return array{parts: array<int, array<string, mixed>>, source: string}
	 */
	private function resolve_overlay_template_parts( array $overlay_parts, int $menu_id, array $location_details ): array {
		$normalized_parts = [];
		foreach ( $overlay_parts as $index => $part ) {
			$reference = $this->normalize_overlay_reference( $part );

			if ( [] !== $reference ) {
				$normalized_parts[ $index ] = $reference;
			}
		}

		if ( [] === $normalized_parts ) {
			return [
				'parts'  => [],
				'source' => 'none',
			];
		}

		$direct_matches = [];
		foreach ( $overlay_parts as $index => $part ) {
			if ( $this->overlay_part_references_menu( $part, $menu_id ) ) {
				$direct_matches[] = $normalized_parts[ $index ];
			}
		}

		if ( [] !== $direct_matches ) {
			return [
				'parts'  => $direct_matches,
				'source' => 'direct-menu-ref',
			];
		}

		$context_matches = $this->filter_overlay_parts_by_context( $normalized_parts, $location_details );
		if ( [] !== $context_matches ) {
			return [
				'parts'  => $context_matches,
				'source' => 'location-heuristic',
			];
		}

		if ( 1 === count( $normalized_parts ) ) {
			return [
				'parts'  => array_values( $normalized_parts ),
				'source' => 'single-overlay',
			];
		}

		return [
			'parts'  => [],
			'source' => 'site-only',
		];
	}

	private function overlay_part_references_menu( array $part, int $menu_id ): bool {
		$content = (string) ( $part['content'] ?? '' );

		if ( $menu_id <= 0 || '' === $content ) {
			return false;
		}

		return $this->navigation_parser->blocks_reference_navigation(
			parse_blocks( $content ),
			$menu_id
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $overlay_parts
	 * @param array<string, mixed>             $location_details
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_overlay_parts_by_context( array $overlay_parts, array $location_details ): array {
		$location           = sanitize_key( (string) ( $location_details['area'] ?? '' ) );
		$template_part_slug = sanitize_key( (string) ( $location_details['templatePartSlug'] ?? '' ) );
		$scored             = [];

		foreach ( $overlay_parts as $part ) {
			$slug       = sanitize_key( (string) ( $part['slug'] ?? '' ) );
			$title_hint = sanitize_key( (string) ( $part['title'] ?? '' ) );
			$score      = 0;

			if ( '' !== $template_part_slug && ( str_contains( $slug, $template_part_slug ) || str_contains( $title_hint, $template_part_slug ) ) ) {
				$score += 3;
			}

			if ( '' !== $location && ( str_contains( $slug, $location ) || str_contains( $title_hint, $location ) ) ) {
				$score += 2;
			}

			if ( $score > 0 ) {
				$scored[] = [
					'part'  => $part,
					'score' => $score,
				];
			}
		}

		if ( [] === $scored ) {
			return [];
		}

		usort(
			$scored,
			static function ( array $left, array $right ): int {
				$score_compare = (int) ( $right['score'] ?? 0 ) <=> (int) ( $left['score'] ?? 0 );

				if ( 0 !== $score_compare ) {
					return $score_compare;
				}

				return strcmp(
					(string) ( $left['part']['slug'] ?? '' ),
					(string) ( $right['part']['slug'] ?? '' )
				);
			}
		);

		$top_score = (int) ( $scored[0]['score'] ?? 0 );

		return array_values(
			array_map(
				static fn( array $entry ): array => $entry['part'],
				array_filter(
					$scored,
					static fn( array $entry ): bool => (int) ( $entry['score'] ?? 0 ) === $top_score
				)
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function normalize_overlay_reference( array $part ): array {
		$slug  = sanitize_key( (string) ( $part['slug'] ?? '' ) );
		$title = sanitize_text_field( (string) ( $part['title'] ?? '' ) );
		$area  = sanitize_key( (string) ( $part['area'] ?? 'navigation-overlay' ) );

		if ( '' === $slug ) {
			return [];
		}

		return [
			'slug'  => $slug,
			'title' => $title,
			'area'  => $area,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $parts
	 * @return array<int, string>
	 */
	private function collect_overlay_slugs( array $parts ): array {
		return array_values(
			array_filter(
				array_map(
					static fn( array $part ): string => sanitize_key( (string) ( $part['slug'] ?? '' ) ),
					$parts
				)
			)
		);
	}

	private function normalize_map( mixed $value ): array {
		$normalized = $this->normalize_value( $value );

		return is_array( $normalized ) ? $normalized : [];
	}

	private function normalize_list( mixed $value ): array {
		$normalized = $this->normalize_map( $value );

		return array_values( $normalized );
	}

	private function normalize_value( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$normalized = [];

			foreach ( $value as $key => $entry ) {
				$normalized[ $key ] = $this->normalize_value( $entry );
			}

			return $normalized;
		}

		if (
			is_string( $value )
			|| is_int( $value )
			|| is_float( $value )
			|| is_bool( $value )
			|| null === $value
		) {
			return $value;
		}

		return null;
	}
}
