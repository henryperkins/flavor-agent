<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\NavigationPrompt;
use FlavorAgent\LLM\ThemeTokenFormatter;
use FlavorAgent\Support\CollectsDocsGuidance;
use FlavorAgent\Support\NormalizesInput;
use FlavorAgent\Support\RecommendationReviewSignature;
use FlavorAgent\Support\StringArray;

final class NavigationAbilities {
	use NormalizesInput;

	/**
	 * Recommend navigation structure, overlay behavior, and organization.
	 *
	 * Accepts either a wp_navigation post ID (menuId) or serialized navigation
	 * block markup (navigationMarkup). At least one must be provided.
	 *
	 * @param mixed $input { menuId?: int, navigationMarkup?: string, prompt?: string, editorContext?: array<string, mixed> }
	 * @return array|\WP_Error Suggestions payload or error.
	 */
	public static function recommend_navigation( mixed $input ): array|\WP_Error {
		$input                  = self::normalize_input( $input );
		$resolve_signature_only = filter_var(
			$input['resolveSignatureOnly'] ?? false,
			FILTER_VALIDATE_BOOLEAN
		);

		$menu_id        = isset( $input['menuId'] ) && is_numeric( $input['menuId'] )
			? (int) $input['menuId']
			: 0;
		$markup         = isset( $input['navigationMarkup'] ) && is_string( $input['navigationMarkup'] )
			? $input['navigationMarkup']
			: '';
		$prompt         = isset( $input['prompt'] ) && is_string( $input['prompt'] )
			? sanitize_textarea_field( $input['prompt'] )
			: '';
		$editor_context = self::normalize_editor_context( $input['editorContext'] ?? [] );

		if ( $menu_id <= 0 && $markup === '' ) {
			return new \WP_Error(
				'missing_navigation_input',
				'Either menuId or navigationMarkup is required.',
				[ 'status' => 400 ]
			);
		}

		$context = ServerCollector::for_navigation( $menu_id, $markup, $editor_context );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$review_context_signature = self::build_review_context_signature( $context );

		if ( $resolve_signature_only ) {
			return [
				'reviewContextSignature' => $review_context_signature,
			];
		}

		$docs_guidance = self::collect_wordpress_docs_guidance( $context, $prompt );
		$system        = NavigationPrompt::build_system();
		$user          = NavigationPrompt::build_user(
			$context,
			$prompt,
			$docs_guidance
		);

		$result = ResponsesClient::rank( $system, $user );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = NavigationPrompt::parse_response( $result, $context );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$payload['reviewContextSignature'] = $review_context_signature;

		return $payload;
	}

	private static function build_review_context_signature( array $context ): string {
		// Review freshness hashes only server-owned context so docs cache churn does not mark stored results stale.
		$payload      = [
			'location'         => sanitize_key( (string) ( $context['location'] ?? '' ) ),
			'locationDetails'  => self::normalize_map( $context['locationDetails'] ?? [] ),
			'attributes'       => self::normalize_review_attributes(
				is_array( $context['attributes'] ?? null ) ? $context['attributes'] : []
			),
			'menuItemCount'    => isset( $context['menuItemCount'] ) ? max( 0, (int) $context['menuItemCount'] ) : 0,
			'maxDepth'         => isset( $context['maxDepth'] ) ? max( 0, (int) $context['maxDepth'] ) : 0,
			'menuItems'        => self::normalize_review_menu_items(
				is_array( $context['menuItems'] ?? null ) ? $context['menuItems'] : []
			),
			'targetInventory'  => self::normalize_review_target_inventory(
				is_array( $context['targetInventory'] ?? null ) ? $context['targetInventory'] : []
			),
			'structureSummary' => self::normalize_review_structure_summary(
				is_array( $context['structureSummary'] ?? null ) ? $context['structureSummary'] : []
			),
			'overlayContext'   => self::normalize_review_overlay_context(
				is_array( $context['overlayContext'] ?? null ) ? $context['overlayContext'] : []
			),
			'overlayParts'     => self::normalize_overlay_template_parts(
				is_array( $context['overlayTemplateParts'] ?? null ) ? $context['overlayTemplateParts'] : []
			),
		];
		$theme_tokens = ThemeTokenFormatter::format(
			is_array( $context['themeTokens'] ?? null ) ? $context['themeTokens'] : []
		);

		if ( '' !== $theme_tokens ) {
			$payload['themeTokens'] = $theme_tokens;
		}

		return RecommendationReviewSignature::from_payload(
			'navigation',
			$payload
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 * @return array<string, bool|int|string>
	 */
	private static function normalize_review_attributes( array $attributes ): array {
		return [
			'overlayMenu'         => sanitize_key( (string) ( $attributes['overlayMenu'] ?? '' ) ),
			'hasIcon'             => ! empty( $attributes['hasIcon'] ),
			'icon'                => sanitize_key( (string) ( $attributes['icon'] ?? '' ) ),
			'openSubmenusOnClick' => ! empty( $attributes['openSubmenusOnClick'] ),
			'showSubmenuIcon'     => array_key_exists( 'showSubmenuIcon', $attributes )
				? (bool) $attributes['showSubmenuIcon']
				: true,
			'maxNestingLevel'     => isset( $attributes['maxNestingLevel'] )
				? max( 0, (int) $attributes['maxNestingLevel'] )
				: 0,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $menu_items
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_review_menu_items( array $menu_items ): array {
		$normalized = [];

		foreach ( $menu_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$path = self::sanitize_target_path( $item['path'] ?? null );
			$type = sanitize_key( (string) ( $item['type'] ?? '' ) );

			if ( null === $path || '' === $type ) {
				continue;
			}

			$normalized_item = [
				'type'  => $type,
				'path'  => $path,
				'depth' => isset( $item['depth'] )
					? max( 0, (int) $item['depth'] )
					: count( $path ) - 1,
			];
			$label           = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			$url             = sanitize_text_field( (string) ( $item['url'] ?? '' ) );
			$children        = is_array( $item['children'] ?? null ) ? $item['children'] : [];

			if ( '' !== $label ) {
				$normalized_item['label'] = $label;
			}

			if ( '' !== $url ) {
				$normalized_item['url'] = $url;
			}

			if ( [] !== $children ) {
				$normalized_children = self::normalize_review_menu_items( $children );

				if ( [] !== $normalized_children ) {
					$normalized_item['children'] = $normalized_children;
				}
			}

			$normalized[] = $normalized_item;
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $overlay_context
	 * @return array<string, bool|int|string|array<int, string>>
	 */
	private static function normalize_review_overlay_context( array $overlay_context ): array {
		return [
			'usesOverlay'                  => ! empty( $overlay_context['usesOverlay'] ),
			'overlayMode'                  => sanitize_key( (string) ( $overlay_context['overlayMode'] ?? '' ) ),
			'hasDedicatedOverlayParts'     => ! empty( $overlay_context['hasDedicatedOverlayParts'] ),
			'overlayTemplatePartCount'     => isset( $overlay_context['overlayTemplatePartCount'] )
				? max( 0, (int) $overlay_context['overlayTemplatePartCount'] )
				: 0,
			'overlayTemplatePartSlugs'     => self::normalize_overlay_slugs(
				is_array( $overlay_context['overlayTemplatePartSlugs'] ?? null )
					? $overlay_context['overlayTemplatePartSlugs']
					: []
			),
			'siteHasDedicatedOverlayParts' => ! empty( $overlay_context['siteHasDedicatedOverlayParts'] ),
			'siteOverlayTemplatePartCount' => isset( $overlay_context['siteOverlayTemplatePartCount'] )
				? max( 0, (int) $overlay_context['siteOverlayTemplatePartCount'] )
				: 0,
			'siteOverlayTemplatePartSlugs' => self::normalize_overlay_slugs(
				is_array( $overlay_context['siteOverlayTemplatePartSlugs'] ?? null )
					? $overlay_context['siteOverlayTemplatePartSlugs']
					: []
			),
			'overlayReferenceScope'        => sanitize_key( (string) ( $overlay_context['overlayReferenceScope'] ?? '' ) ),
			'overlayReferenceSource'       => sanitize_key( (string) ( $overlay_context['overlayReferenceSource'] ?? '' ) ),
		];
	}

	/**
	 * @param array<int, mixed> $overlay_slugs
	 * @return array<int, string>
	 */
	private static function normalize_overlay_slugs( array $overlay_slugs ): array {
		$normalized = array_values(
			array_filter(
				array_map(
					static fn( mixed $slug ): string => sanitize_key( (string) $slug ),
					$overlay_slugs
				)
			)
		);

		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized );

		return $normalized;
	}

	/**
	 * @param array<int, array<string, mixed>> $target_inventory
	 * @return array<int, array<string, int|string|array<int, int>>>
	 */
	private static function normalize_review_target_inventory( array $target_inventory ): array {
		$normalized = [];

		foreach ( $target_inventory as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}

			$path = self::sanitize_target_path( $target['path'] ?? null );

			if ( null === $path ) {
				continue;
			}

			$normalized[] = [
				'path'  => $path,
				'label' => sanitize_text_field( (string) ( $target['label'] ?? '' ) ),
				'type'  => sanitize_key( (string) ( $target['type'] ?? '' ) ),
				'depth' => isset( $target['depth'] )
					? max( 0, (int) $target['depth'] )
					: count( $path ) - 1,
			];
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $structure_summary
	 * @return array<string, mixed>
	 */
	private static function normalize_review_structure_summary( array $structure_summary ): array {
		$normalized = [];

		foreach ( $structure_summary as $key => $value ) {
			$normalized_key = sanitize_key( (string) $key );

			if ( '' === $normalized_key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$normalized[ $normalized_key ] = array_values(
					array_filter(
						array_map(
							static fn( mixed $entry ): string => sanitize_text_field( (string) $entry ),
							$value
						),
						static fn( string $entry ): bool => '' !== $entry
					)
				);
				continue;
			}

			if ( is_bool( $value ) ) {
				$normalized[ $normalized_key ] = $value;
				continue;
			}

			if ( is_int( $value ) || is_float( $value ) ) {
				$normalized[ $normalized_key ] = $value;
				continue;
			}

			if ( null !== $value ) {
				$normalized[ $normalized_key ] = sanitize_text_field( (string) $value );
			}
		}

		return $normalized;
	}

	/**
	 * @return array<int, int>|null
	 */
	private static function sanitize_target_path( mixed $path ): ?array {
		if ( ! is_array( $path ) || [] === $path ) {
			return null;
		}

		$sanitized = [];

		foreach ( $path as $segment ) {
			if ( ! is_numeric( $segment ) ) {
				return null;
			}

			$sanitized[] = max( 0, (int) $segment );
		}

		return [] === $sanitized ? null : $sanitized;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_wordpress_docs_guidance( array $context, string $prompt ): array {
		return CollectsDocsGuidance::collect(
			static fn( array $request_context, string $request_prompt ): string => self::build_wordpress_docs_query( $request_context, $request_prompt ),
			static fn(): string => 'core/navigation',
			static fn( array $request_context ): array => self::build_wordpress_docs_family_context( $request_context ),
			$context,
			$prompt
		);
	}

	/**
	 * @return string Search query for WordPress docs grounding.
	 */
	private static function build_wordpress_docs_query( array $context, string $prompt ): string {
		$parts = [ 'WordPress navigation block' ];

		$location = (string) ( $context['location'] ?? '' );
		if ( $location !== '' && $location !== 'unknown' ) {
			$parts[] = $location . ' navigation';
		}

		$has_overlay  = ! empty( $context['overlayTemplateParts'] );
		$overlay_attr = $context['attributes']['overlayMenu'] ?? '';
		if ( $has_overlay || ( is_string( $overlay_attr ) && $overlay_attr !== 'never' ) ) {
			$parts[] = 'overlay responsive menu';
		}

		$max_depth = isset( $context['maxDepth'] ) ? (int) $context['maxDepth'] : 0;
		if ( $max_depth > 1 ) {
			$parts[] = 'nested navigation depth';
		}

		if ( ! empty( $context['structureSummary']['hasPageList'] ) ) {
			$parts[] = 'page list navigation';
		}

		if ( $prompt !== '' ) {
			$parts[] = $prompt;
		}

		$parts[] = 'menu structure and organization best practices';

		return implode( '. ', $parts );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_wordpress_docs_family_context( array $context ): array {
		$location       = sanitize_key( (string) ( $context['location'] ?? '' ) );
		$has_overlay    = ! empty( $context['overlayTemplateParts'] );
		$overlay_attr   = $context['attributes']['overlayMenu'] ?? '';
		$uses_overlay   = $has_overlay || ( is_string( $overlay_attr ) && $overlay_attr !== 'never' );
		$family_context = [
			'surface'   => 'navigation',
			'entityKey' => 'core/navigation',
		];

		if ( $location !== '' && $location !== 'unknown' ) {
			$family_context['location'] = $location;
		}

		if ( $uses_overlay ) {
			$family_context['overlay'] = true;
		}

		return $family_context;
	}

	/**
	 * @param array<int, array<string, mixed>> $overlay_parts
	 * @return array<int, array<string, string>>
	 */
	private static function normalize_overlay_template_parts( array $overlay_parts ): array {
		$normalized = [];

		foreach ( $overlay_parts as $overlay_part ) {
			if ( ! is_array( $overlay_part ) ) {
				continue;
			}

			$slug = sanitize_key( (string) ( $overlay_part['slug'] ?? '' ) );

			if ( '' === $slug ) {
				continue;
			}

			$normalized[] = [
				'slug'  => $slug,
				'title' => sanitize_text_field( (string) ( $overlay_part['title'] ?? '' ) ),
			];
		}

		usort(
			$normalized,
			static function ( array $left, array $right ): int {
				$slug_compare = strcmp(
					(string) ( $left['slug'] ?? '' ),
					(string) ( $right['slug'] ?? '' )
				);

				if ( 0 !== $slug_compare ) {
					return $slug_compare;
				}

				return strcmp(
					(string) ( $left['title'] ?? '' ),
					(string) ( $right['title'] ?? '' )
				);
			}
		);

		return $normalized;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_editor_context( mixed $raw_context ): array {
		$context = self::normalize_map( $raw_context );
		$block   = self::normalize_map( $context['block'] ?? [] );
		$name    = sanitize_text_field( (string) ( $block['name'] ?? '' ) );

		if ( '' === $name || 'core/navigation' !== $name ) {
			return [];
		}

		$normalized = [
			'block' => [
				'name' => $name,
			],
		];

		$title = sanitize_text_field( (string) ( $block['title'] ?? '' ) );
		if ( '' !== $title ) {
			$normalized['block']['title'] = $title;
		}

		$structural_identity = self::normalize_map( $block['structuralIdentity'] ?? [] );
		if ( [] !== $structural_identity ) {
			$normalized['block']['structuralIdentity'] = $structural_identity;
		}

		$siblings_before = StringArray::sanitize( $context['siblingsBefore'] ?? [] );
		if ( [] !== $siblings_before ) {
			$normalized['siblingsBefore'] = $siblings_before;
		}

		$siblings_after = StringArray::sanitize( $context['siblingsAfter'] ?? [] );
		if ( [] !== $siblings_after ) {
			$normalized['siblingsAfter'] = $siblings_after;
		}

		$structural_ancestors = self::normalize_list( $context['structuralAncestors'] ?? [] );
		if ( [] !== $structural_ancestors ) {
			$normalized['structuralAncestors'] = $structural_ancestors;
		}

		$structural_branch = self::normalize_list( $context['structuralBranch'] ?? [] );
		if ( [] !== $structural_branch ) {
			$normalized['structuralBranch'] = $structural_branch;
		}

		return $normalized;
	}

	private static function normalize_map( mixed $value ): array {
		$normalized = self::normalize_value( $value );

		return is_array( $normalized ) ? $normalized : [];
	}

	private static function normalize_list( mixed $value ): array {
		$normalized = self::normalize_map( $value );

		return array_values( $normalized );
	}

	private static function normalize_value( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$normalized = [];

			foreach ( $value as $key => $entry ) {
				$normalized[ $key ] = self::normalize_value( $entry );
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
