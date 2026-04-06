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
	 * @return array|\WP_Error Navigation context or error.
	 */
	public function for_navigation( int $menu_id = 0, string $markup = '' ): array|\WP_Error {
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

		$menu_items        = $this->navigation_parser->extract_menu_items( $inner );
		$target_inventory  = $this->navigation_parser->build_target_inventory( $menu_items );
		$attributes        = $this->navigation_parser->collect_navigation_attributes( $nav_attrs );
		$location          = $menu_id > 0 ? $this->infer_navigation_location( $menu_id ) : 'unknown';
		$overlay_parts     = $this->template_repository->for_template_parts( 'navigation-overlay', false );
		$structure_summary = $this->navigation_parser->collect_navigation_structure_summary( $menu_items );
		$uses_overlay      = count( $overlay_parts ) > 0 || ( $attributes['overlayMenu'] ?? 'never' ) !== 'never';

		return [
			'menuId'               => $menu_id > 0 ? $menu_id : null,
			'location'             => $location,
			'locationDetails'      => [
				'area'   => $location,
				'source' => 'unknown' !== $location
					? 'template-part-scan'
					: ( $menu_id > 0 ? 'navigation-post' : 'live-markup' ),
			],
			'attributes'           => $attributes,
			'menuItems'            => $menu_items,
			'targetInventory'      => $target_inventory,
			'menuItemCount'        => $this->navigation_parser->count_menu_items_recursive( $menu_items ),
			'maxDepth'             => $this->navigation_parser->measure_menu_depth( $menu_items ),
			'structureSummary'     => $structure_summary,
			'overlayContext'       => [
				'usesOverlay'              => $uses_overlay,
				'overlayMode'              => (string) ( $attributes['overlayMenu'] ?? 'never' ),
				'hasDedicatedOverlayParts' => count( $overlay_parts ) > 0,
				'overlayTemplatePartCount' => count( $overlay_parts ),
				'overlayTemplatePartSlugs' => array_values(
					array_filter(
						array_map(
							static fn( array $part ): string => sanitize_key( (string) ( $part['slug'] ?? '' ) ),
							$overlay_parts
						)
					)
				),
			],
			'overlayTemplateParts' => $overlay_parts,
			'themeTokens'          => $this->theme_token_collector->for_tokens(),
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
}
