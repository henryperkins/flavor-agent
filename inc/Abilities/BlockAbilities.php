<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\ChatClient;
use FlavorAgent\LLM\Prompt;
use FlavorAgent\Support\CollectsDocsGuidance;
use FlavorAgent\Support\RecommendationResolvedSignature;
use FlavorAgent\Support\StringArray;

final class BlockAbilities {

	private const STRUCTURAL_SUMMARY_MAX_ITEMS = 6;

	private const STRUCTURAL_SUMMARY_MAX_CHILDREN = 6;

	private const STRUCTURAL_SUMMARY_MAX_DEPTH = 2;

	public static function recommend_block( mixed $input ): array|\WP_Error {
		$input = self::normalize_map( $input );
		$resolve_signature_only = filter_var(
			$input['resolveSignatureOnly'] ?? false,
			FILTER_VALIDATE_BOOLEAN
		);

		$prepared = self::prepare_recommend_block_input( $input );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$context = $prepared['context'];
		$prompt  = $prepared['prompt'];

		$resolved_context_signature = RecommendationResolvedSignature::from_payload(
			'block',
			[
				'context' => $context,
				'prompt'  => $prompt,
			]
		);

		if ( $resolve_signature_only ) {
			return [
				'resolvedContextSignature' => $resolved_context_signature,
			];
		}

		if ( self::normalize_editing_mode( $context['block']['editingMode'] ?? 'default' ) === 'disabled' ) {
			$payload                             = self::get_empty_recommendation_payload();
			$payload['resolvedContextSignature'] = $resolved_context_signature;

			return $payload;
		}

		$docs_guidance = self::collect_wordpress_docs_guidance( $context, $prompt );
		$system_prompt = Prompt::build_system();
		$user_prompt   = Prompt::build_user(
			$context,
			$prompt,
			$docs_guidance
		);

		$result = ChatClient::chat( $system_prompt, $user_prompt );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = Prompt::parse_response( $result );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$payload = Prompt::enforce_block_context_rules( $payload, $context['block'] ?? [] );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$payload['resolvedContextSignature'] = $resolved_context_signature;

		return $payload;
	}

	public static function introspect_block( mixed $input ): array|\WP_Error {
		$input      = self::normalize_map( $input );
		$block_name = $input['blockName'] ?? '';

		if ( empty( $block_name ) ) {
			return new \WP_Error( 'missing_block_name', 'blockName is required.', [ 'status' => 400 ] );
		}

		$manifest = ServerCollector::introspect_block_type( $block_name );

		if ( $manifest === null ) {
			return new \WP_Error( 'block_not_found', "Block type '{$block_name}' is not registered.", [ 'status' => 404 ] );
		}

		return $manifest;
	}

	/**
	 * Normalize recommend-block inputs from either the REST editorContext payload
	 * or the Abilities selectedBlock payload into one canonical prompt context.
	 *
	 * @return array{context: array, prompt: string}|\WP_Error
	 */
	private static function prepare_recommend_block_input( array $input ): array|\WP_Error {
		$prompt = is_string( $input['prompt'] ?? null ) ? sanitize_textarea_field( $input['prompt'] ) : '';

		if ( array_key_exists( 'editorContext', $input ) ) {
			$context = self::build_context_from_editor_context( $input['editorContext'] );
		} else {
			$context = self::build_context_from_selected_block( $input['selectedBlock'] ?? [] );
		}

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		return [
			'context' => $context,
			'prompt'  => $prompt,
		];
	}

	private static function build_context_from_editor_context( mixed $raw_context ): array|\WP_Error {
		$context = self::normalize_map( $raw_context );
		$block   = self::normalize_map( $context['block'] ?? [] );
		$name    = is_string( $block['name'] ?? null ) ? sanitize_text_field( $block['name'] ) : '';

		if ( '' === $name ) {
			return new \WP_Error( 'missing_block_name', 'editorContext.block.name is required.', [ 'status' => 400 ] );
		}

		$selected = self::normalize_selected_block(
			[
				'blockName'           => $name,
				'attributes'          => $block['currentAttributes'] ?? [],
				'isInsideContentOnly' => ! empty( $block['isInsideContentOnly'] ),
				'editingMode'         => $block['editingMode'] ?? 'default',
				'blockVisibility'     => $block['blockVisibility'] ?? null,
			]
		);

		$normalized = self::build_context_from_selected_block( $selected );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		// Preserve client-only editor details that the server cannot infer.
		$normalized['block']['editingMode'] = self::normalize_editing_mode( $block['editingMode'] ?? 'default' );

		$title = is_string( $block['title'] ?? null ) ? sanitize_text_field( $block['title'] ) : '';
		if ( '' !== $title ) {
			$normalized['block']['title'] = $title;
		}

		$inspector_panels = self::normalize_map( $block['inspectorPanels'] ?? [] );
		if ( ! empty( $inspector_panels ) ) {
			$normalized['block']['inspectorPanels'] = $inspector_panels;
		}

		if ( array_key_exists( 'bindableAttributes', $block ) ) {
			$bindable_attributes                       = StringArray::sanitize( $block['bindableAttributes'] ?? [] );
			$normalized['block']['bindableAttributes'] = $bindable_attributes;

			if ( [] === $bindable_attributes ) {
				unset( $normalized['block']['inspectorPanels']['bindings'] );
			} else {
				$normalized['block']['inspectorPanels']['bindings'] =
					$normalized['block']['inspectorPanels']['bindings'] ?? $bindable_attributes;
			}
		}

			$styles = self::normalize_list( $block['styles'] ?? [] );
		if ( ! empty( $styles ) ) {
			$normalized['block']['styles'] = $styles;
		}

			$active_style = is_string( $block['activeStyle'] ?? null ) ? sanitize_text_field( $block['activeStyle'] ) : '';
		if ( '' !== $active_style ) {
			$normalized['block']['activeStyle'] = $active_style;
		}

			$child_count = $block['childCount'] ?? null;
		if ( is_int( $child_count ) || ( is_numeric( $child_count ) && (int) $child_count >= 0 ) ) {
			$normalized['block']['childCount'] = (int) $child_count;
		}

			$structural_identity = self::normalize_map( $block['structuralIdentity'] ?? [] );
		if ( ! empty( $structural_identity ) ) {
			$normalized['block']['structuralIdentity'] = $structural_identity;
		}

		$variations = self::normalize_list( $block['variations'] ?? [] );
		if ( ! empty( $variations ) ) {
			$normalized['block']['variations'] = $variations;
		}

		$normalized['block']['supportsContentRole'] =
			! empty( $block['supportsContentRole'] )
			|| ! empty( $normalized['block']['supportsContentRole'] );

		$content_attributes = self::normalize_map( $block['contentAttributes'] ?? [] );
		if ( ! empty( $content_attributes ) ) {
			$normalized['block']['contentAttributes'] = $content_attributes;
		}

		$config_attributes = self::normalize_map( $block['configAttributes'] ?? [] );
		if ( ! empty( $config_attributes ) ) {
			$normalized['block']['configAttributes'] = $config_attributes;
		}

		$normalized['siblingsBefore'] = StringArray::sanitize( $context['siblingsBefore'] ?? [] );
		$normalized['siblingsAfter']  = StringArray::sanitize( $context['siblingsAfter'] ?? [] );

		$parent_context = self::normalize_parent_context( $context['parentContext'] ?? [] );
		if ( ! empty( $parent_context ) ) {
			$normalized['parentContext'] = $parent_context;
		}

		$sibling_summaries_before = self::normalize_sibling_summaries( $context['siblingSummariesBefore'] ?? [] );
		if ( ! empty( $sibling_summaries_before ) ) {
			$normalized['siblingSummariesBefore'] = $sibling_summaries_before;
		}

		$sibling_summaries_after = self::normalize_sibling_summaries( $context['siblingSummariesAfter'] ?? [] );
		if ( ! empty( $sibling_summaries_after ) ) {
			$normalized['siblingSummariesAfter'] = $sibling_summaries_after;
		}

		$structural_ancestors = self::normalize_structural_summary_items( $context['structuralAncestors'] ?? [] );
		if ( ! empty( $structural_ancestors ) ) {
			$normalized['structuralAncestors'] = $structural_ancestors;
		}

		$structural_branch = self::normalize_structural_summary_items( $context['structuralBranch'] ?? [], true );
		if ( ! empty( $structural_branch ) ) {
			$normalized['structuralBranch'] = $structural_branch;
		}

		$theme_tokens = self::normalize_theme_tokens( $context['themeTokens'] ?? [] );
		if ( ! empty( $theme_tokens ) ) {
			$normalized['themeTokens'] = $theme_tokens;
		}

		return $normalized;
	}

	private static function build_context_from_selected_block( mixed $raw_selected ): array|\WP_Error {
		$selected               = self::normalize_selected_block( self::normalize_map( $raw_selected ) );
		$block_name             = is_string( $selected['blockName'] ?? null ) ? sanitize_text_field( $selected['blockName'] ) : '';
		$attributes             = self::normalize_map( $selected['attributes'] ?? [] );
		$inner_blocks           = self::normalize_list( $selected['innerBlocks'] ?? [] );
		$is_inside_content_only = ! empty( $selected['isInsideContentOnly'] );
		$parent_context         = self::normalize_parent_context( $selected['parentContext'] ?? [] );
		$sibling_summaries_before = self::normalize_sibling_summaries( $selected['siblingSummariesBefore'] ?? [] );
		$sibling_summaries_after  = self::normalize_sibling_summaries( $selected['siblingSummariesAfter'] ?? [] );

		if ( '' === $block_name ) {
			return new \WP_Error( 'missing_block_name', 'selectedBlock.blockName is required.', [ 'status' => 400 ] );
		}

		$context = ServerCollector::for_block(
			$block_name,
			$attributes,
			$inner_blocks,
			$is_inside_content_only,
			$parent_context,
			$sibling_summaries_before,
			$sibling_summaries_after
		);

		$context['block']['editingMode']         = self::normalize_editing_mode( $selected['editingMode'] ?? $context['block']['editingMode'] ?? 'default' );
		$context['block']['supportsContentRole'] = ! empty( $selected['supportsContentRole'] ) || ! empty( $context['block']['supportsContentRole'] );
		$context['siblingsBefore']               = StringArray::sanitize( $context['siblingsBefore'] ?? [] );
		$context['siblingsAfter']                = StringArray::sanitize( $context['siblingsAfter'] ?? [] );
		$context['structuralAncestors']          = self::normalize_structural_summary_items( $selected['structuralAncestors'] ?? [] );
		$context['structuralBranch']             = self::normalize_structural_summary_items( $selected['structuralBranch'] ?? [], true );
		$context['themeTokens']                  = self::normalize_theme_tokens( $context['themeTokens'] ?? [] );

		$structural_identity = self::normalize_map( $selected['structuralIdentity'] ?? [] );
		if ( ! empty( $structural_identity ) ) {
			$context['block']['structuralIdentity'] = $structural_identity;
		}

		return $context;
	}

	private static function normalize_selected_block( array $selected ): array {
		$selected   = self::normalize_map( $selected );
		$attributes = self::normalize_map( $selected['attributes'] ?? [] );

		$selected['blockName']           = is_string( $selected['blockName'] ?? null ) ? sanitize_text_field( $selected['blockName'] ) : '';
		$selected['editingMode']         = self::normalize_editing_mode( $selected['editingMode'] ?? 'default' );
		$selected['isInsideContentOnly'] = ! empty( $selected['isInsideContentOnly'] );
		$selected['supportsContentRole'] = ! empty( $selected['supportsContentRole'] );
		$selected['innerBlocks']         = self::normalize_list( $selected['innerBlocks'] ?? [] );

		$metadata = self::normalize_map( $attributes['metadata'] ?? [] );

		if (
			array_key_exists( 'blockVisibility', $selected ) &&
			! array_key_exists( 'blockVisibility', $metadata )
		) {
			$metadata['blockVisibility'] = self::normalize_value( $selected['blockVisibility'] );
		}

		if ( ! empty( $metadata ) || array_key_exists( 'metadata', $attributes ) ) {
			$attributes['metadata'] = $metadata;
		}

		$selected['attributes'] = $attributes;

		return $selected;
	}

	private static function normalize_visual_hints( mixed $raw_hints, bool $allow_parent_extensions = false ): array {
		$map   = self::normalize_map( $raw_hints );
		$paths = [
			'backgroundColor',
			'textColor',
			'gradient',
			'align',
			'textAlign',
			'style.color.background',
			'style.color.text',
			'layout.type',
			'layout.justifyContent',
		];

		if ( $allow_parent_extensions ) {
			$paths = array_merge(
				$paths,
				[ 'dimRatio', 'minHeight', 'minHeightUnit', 'tagName' ]
			);
		}

		$normalized = [];

		foreach ( $paths as $path ) {
			$segments = explode( '.', $path );
			$value    = self::get_value_from_path( $map, $segments );

			if ( null === $value || '' === $value ) {
				continue;
			}

			$sanitized = self::sanitize_scalar( $value );

			if ( null === $sanitized || '' === $sanitized ) {
				continue;
			}

			self::set_value_at_path( $normalized, $segments, $sanitized );
		}

		return $normalized;
	}

	private static function normalize_sibling_summaries( mixed $raw_summaries ): array {
		$summaries   = array_slice( self::normalize_list( $raw_summaries ), 0, 3 );
		$normalized  = [];

		foreach ( $summaries as $summary ) {
			if ( ! is_array( $summary ) ) {
				continue;
			}
			$block = is_string( $summary['block'] ?? null ) ? sanitize_text_field( $summary['block'] ) : '';

			if ( '' === $block ) {
				continue;
			}

			$entry = [
				'block' => $block,
			];

			$role = is_string( $summary['role'] ?? null ) ? sanitize_text_field( $summary['role'] ) : '';
			if ( '' !== $role ) {
				$entry['role'] = $role;
			}

			$visual_hints = self::normalize_visual_hints( $summary['visualHints'] ?? [] );
			if ( ! empty( $visual_hints ) ) {
				$entry['visualHints'] = $visual_hints;
			}

			$normalized[] = $entry;
		}

		return $normalized;
	}

	private static function normalize_parent_context( mixed $raw_parent ): array {
		$parent = self::normalize_map( $raw_parent );

		if ( empty( $parent ) ) {
			return [];
		}

		$block = is_string( $parent['block'] ?? null ) ? sanitize_text_field( $parent['block'] ) : '';
		if ( '' === $block ) {
			return [];
		}

		$normalized = [
			'block' => $block,
		];

		$title = is_string( $parent['title'] ?? null ) ? sanitize_text_field( $parent['title'] ) : '';
		if ( '' !== $title ) {
			$normalized['title'] = $title;
		}

		$role = is_string( $parent['role'] ?? null ) ? sanitize_text_field( $parent['role'] ) : '';
		if ( '' !== $role ) {
			$normalized['role'] = $role;
		}

		$job = is_string( $parent['job'] ?? null ) ? sanitize_text_field( $parent['job'] ) : '';
		if ( '' !== $job ) {
			$normalized['job'] = $job;
		}

		if ( isset( $parent['childCount'] ) && ( is_int( $parent['childCount'] ) || is_numeric( $parent['childCount'] ) ) ) {
			$normalized['childCount'] = (int) $parent['childCount'];
		}

		$visual_hints = self::normalize_visual_hints( $parent['visualHints'] ?? [], true );
		if ( ! empty( $visual_hints ) ) {
			$normalized['visualHints'] = $visual_hints;
		}

		return $normalized;
	}

	private static function normalize_structural_summary_items( mixed $raw_items, bool $include_children = false, int $depth = 0 ): array {
		$items       = array_slice( self::normalize_list( $raw_items ), 0, self::STRUCTURAL_SUMMARY_MAX_ITEMS );
		$normalized  = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$block = is_string( $item['block'] ?? null ) ? sanitize_text_field( $item['block'] ) : '';

			if ( '' === $block ) {
				continue;
			}

			$summary = [ 'block' => $block ];

			$title = is_string( $item['title'] ?? null ) ? sanitize_text_field( $item['title'] ) : '';
			if ( '' !== $title ) {
				$summary['title'] = $title;
			}

			$role = is_string( $item['role'] ?? null ) ? sanitize_text_field( $item['role'] ) : '';
			if ( '' !== $role ) {
				$summary['role'] = $role;
			}

			$job = is_string( $item['job'] ?? null ) ? sanitize_text_field( $item['job'] ) : '';
			if ( '' !== $job ) {
				$summary['job'] = $job;
			}

			$location = is_string( $item['location'] ?? null ) ? sanitize_text_field( $item['location'] ) : '';
			if ( '' !== $location ) {
				$summary['location'] = $location;
			}

			$template_area = is_string( $item['templateArea'] ?? null ) ? sanitize_text_field( $item['templateArea'] ) : '';
			if ( '' !== $template_area ) {
				$summary['templateArea'] = $template_area;
			}

			$template_part = is_string( $item['templatePartSlug'] ?? null ) ? sanitize_text_field( $item['templatePartSlug'] ) : '';
			if ( '' !== $template_part ) {
				$summary['templatePartSlug'] = $template_part;
			}

			if ( isset( $item['childCount'] ) && ( is_int( $item['childCount'] ) || is_numeric( $item['childCount'] ) ) ) {
				$summary['childCount'] = (int) $item['childCount'];
			}

			if ( isset( $item['moreChildren'] ) && ( is_int( $item['moreChildren'] ) || is_numeric( $item['moreChildren'] ) ) ) {
				$summary['moreChildren'] = (int) $item['moreChildren'];
			}

			if ( $include_children ) {
				$raw_children        = self::normalize_list( $item['children'] ?? [] );
				$hidden_child_count  = max( 0, (int) ( $summary['moreChildren'] ?? 0 ) );
				$displayed_children  = [];

				if ( $depth < self::STRUCTURAL_SUMMARY_MAX_DEPTH ) {
					$visible_children = array_slice( $raw_children, 0, self::STRUCTURAL_SUMMARY_MAX_CHILDREN );
					$hidden_child_count += max( 0, count( $raw_children ) - count( $visible_children ) );
					$displayed_children = self::normalize_structural_summary_items( $visible_children, true, $depth + 1 );
				} else {
					$hidden_child_count += count( $raw_children );
				}

				if ( ! empty( $displayed_children ) ) {
					$summary['children'] = $displayed_children;
				}

				if ( isset( $summary['childCount'] ) ) {
					$hidden_child_count = max(
						$hidden_child_count,
						max( 0, (int) $summary['childCount'] - count( $displayed_children ) )
					);
				}

				if ( $hidden_child_count > 0 ) {
					$summary['moreChildren'] = $hidden_child_count;
				} else {
					unset( $summary['moreChildren'] );
				}
			}

			if ( isset( $item['isSelected'] ) ) {
				$summary['isSelected'] = (bool) $item['isSelected'];
			}

			$normalized[] = $summary;
		}

		return $normalized;
	}

	private static function normalize_theme_tokens( mixed $raw_tokens ): array {
		$tokens = self::normalize_map( $raw_tokens );

		if ( empty( $tokens ) ) {
			return [];
		}

		return [
			'colors'            => StringArray::sanitize( $tokens['colors'] ?? [] ),
			'colorPresets'      => self::normalize_list( $tokens['colorPresets'] ?? [] ),
			'gradients'         => StringArray::sanitize( $tokens['gradients'] ?? [] ),
			'gradientPresets'   => self::normalize_list( $tokens['gradientPresets'] ?? [] ),
			'fontSizes'         => StringArray::sanitize( $tokens['fontSizes'] ?? [] ),
			'fontSizePresets'   => self::normalize_list( $tokens['fontSizePresets'] ?? [] ),
			'fontFamilies'      => StringArray::sanitize( $tokens['fontFamilies'] ?? [] ),
			'fontFamilyPresets' => self::normalize_list( $tokens['fontFamilyPresets'] ?? [] ),
			'spacing'           => StringArray::sanitize( $tokens['spacing'] ?? [] ),
			'spacingPresets'    => self::normalize_list( $tokens['spacingPresets'] ?? [] ),
			'shadows'           => StringArray::sanitize( $tokens['shadows'] ?? [] ),
			'shadowPresets'     => self::normalize_list( $tokens['shadowPresets'] ?? [] ),
			'duotone'           => StringArray::sanitize( $tokens['duotone'] ?? [] ),
			'duotonePresets'    => self::normalize_list( $tokens['duotonePresets'] ?? [] ),
			'diagnostics'       => self::normalize_map( $tokens['diagnostics'] ?? [] ),
			'layout'            => self::normalize_map( $tokens['layout'] ?? [] ),
			'enabledFeatures'   => self::normalize_map( $tokens['enabledFeatures'] ?? [] ),
			'elementStyles'     => self::normalize_map( $tokens['elementStyles'] ?? [] ),
			'blockPseudoStyles' => self::normalize_map( $tokens['blockPseudoStyles'] ?? [] ),
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_wordpress_docs_guidance( array $context, string $prompt ): array {
		return CollectsDocsGuidance::collect(
			static fn( array $request_context, string $request_prompt ): string => self::build_wordpress_docs_query( $request_context, $request_prompt ),
			static fn( array $request_context ): string => self::build_wordpress_docs_entity_key( $request_context ),
			static fn( array $request_context ): array => self::build_wordpress_docs_family_context( $request_context ),
			$context,
			$prompt
		);
	}

	private static function build_wordpress_docs_entity_key( array $context ): string {
		$block      = self::normalize_map( $context['block'] ?? [] );
		$block_name = is_string( $block['name'] ?? null ) ? sanitize_text_field( (string) $block['name'] ) : '';

		return AISearchClient::resolve_entity_key( $block_name );
	}

	private static function build_wordpress_docs_query( array $context, string $prompt ): string {
		$block         = self::normalize_map( $context['block'] ?? [] );
		$block_name    = is_string( $block['name'] ?? null ) ? sanitize_text_field( $block['name'] ) : '';
		$block_title   = is_string( $block['title'] ?? null ) ? sanitize_text_field( $block['title'] ) : '';
		$panel_keys    = array_keys( self::normalize_map( $block['inspectorPanels'] ?? [] ) );
		$panel_summary = array_values(
			array_filter(
				array_map(
					static fn( $panel ): string => sanitize_key( (string) $panel ),
					$panel_keys
				)
			)
		);
			$parts     = [ 'WordPress Gutenberg block editor best practices and design tool guidance' ];

		if ( $block_name !== '' ) {
			$parts[] = "block type {$block_name}";
		}

		if ( $block_title !== '' ) {
			$parts[] = "block title {$block_title}";
		}

		if ( ! empty( $panel_summary ) ) {
			$parts[] = 'inspector panels ' . implode( ', ', $panel_summary );
		}

		if ( ! empty( $block['isInsideContentOnly'] ) ) {
			$parts[] = 'contentOnly editing constraints';
		}

		if ( self::normalize_editing_mode( $block['editingMode'] ?? 'default' ) === 'contentOnly' ) {
			$parts[] = 'contentOnly editing constraints';
		}

			$identity        = self::normalize_map( $block['structuralIdentity'] ?? [] );
			$structural_role = is_string( $identity['role'] ?? null ) ? sanitize_key( (string) $identity['role'] ) : '';
			$location        = is_string( $identity['location'] ?? null ) ? sanitize_key( (string) $identity['location'] ) : '';
			$template_area   = is_string( $identity['templateArea'] ?? null ) ? sanitize_key( (string) $identity['templateArea'] ) : '';

		if ( $structural_role !== '' ) {
			$parts[] = 'structural role ' . $structural_role;
		}

		if ( $location !== '' ) {
			$parts[] = 'page location ' . $location;
		}

		if ( $template_area !== '' ) {
			$parts[] = 'template area ' . $template_area;
		}

		if ( ! empty( $block['editingMode'] ) && $block['editingMode'] !== 'default' ) {
			$parts[] = 'editing mode ' . sanitize_key( (string) $block['editingMode'] );
		}

		if ( $prompt !== '' ) {
			$parts[] = $prompt;
		}

		$parts[] = 'theme.json presets, block supports, inspector controls, and editor standards';

		return implode(
			'. ',
			array_values(
				array_filter(
					$parts,
					static fn( string $part ): bool => $part !== ''
				)
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_wordpress_docs_family_context( array $context ): array {
		$entity_key = self::build_wordpress_docs_entity_key( $context );

		if ( $entity_key === '' ) {
			return [];
		}

		$block         = self::normalize_map( $context['block'] ?? [] );
		$panel_keys    = array_keys( self::normalize_map( $block['inspectorPanels'] ?? [] ) );
		$panel_summary = array_values(
			array_filter(
				array_map(
					static fn( $panel ): string => sanitize_key( (string) $panel ),
					$panel_keys
				)
			)
		);
		sort( $panel_summary );

		$identity        = self::normalize_map( $block['structuralIdentity'] ?? [] );
		$structural_role = is_string( $identity['role'] ?? null ) ? sanitize_key( (string) $identity['role'] ) : '';
		$location        = is_string( $identity['location'] ?? null ) ? sanitize_key( (string) $identity['location'] ) : '';
		$template_area   = is_string( $identity['templateArea'] ?? null ) ? sanitize_key( (string) $identity['templateArea'] ) : '';
		$editing_mode    = self::normalize_editing_mode( $block['editingMode'] ?? 'default' );
		$family_context  = [
			'surface'   => 'block',
			'entityKey' => $entity_key,
		];

		if ( ! empty( $panel_summary ) ) {
			$family_context['inspectorPanels'] = $panel_summary;
		}

		if ( $structural_role !== '' ) {
			$family_context['structuralRole'] = $structural_role;
		}

		if ( $location !== '' ) {
			$family_context['location'] = $location;
		}

		if ( $template_area !== '' ) {
			$family_context['templateArea'] = $template_area;
		}

		if ( ! empty( $block['isInsideContentOnly'] ) || $editing_mode === 'contentOnly' ) {
			$family_context['contentOnly'] = true;
		}

		if ( $editing_mode !== 'default' ) {
			$family_context['editingMode'] = $editing_mode;
		}

		return $family_context;
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

	private static function get_value_from_path( array $source, array $path ): mixed {
		$current = $source;

		foreach ( $path as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}
			$current = $current[ $segment ];
		}

		return $current;
	}

	private static function set_value_at_path( array &$target, array $path, mixed $value ): void {
		$cursor = &$target;

		foreach ( $path as $index => $segment ) {
			if ( $index === count( $path ) - 1 ) {
				$cursor[ $segment ] = $value;
				return;
			}

			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = [];
			}

			$cursor = &$cursor[ $segment ];
		}
	}

	private static function sanitize_scalar( mixed $value ): string|int|float|bool|null {
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return $value + 0;
		}

		return null;
	}

	private static function normalize_editing_mode( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return 'default';
		}

		$value = strtolower( preg_replace( '/[^a-z]/i', '', $value ) ?? '' );

		return match ( $value ) {
			'contentonly' => 'contentOnly',
			'disabled' => 'disabled',
			default => 'default',
		};
	}

	private static function get_empty_recommendation_payload(): array {
		return [
			'settings'    => [],
			'styles'      => [],
			'block'       => [],
			'explanation' => '',
		];
	}
}
