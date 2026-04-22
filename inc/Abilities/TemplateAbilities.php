<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\ResponseSchema;
use FlavorAgent\LLM\TemplatePrompt;
use FlavorAgent\LLM\TemplatePartPrompt;
use FlavorAgent\LLM\ThemeTokenFormatter;
use FlavorAgent\Support\CollectsDocsGuidance;
use FlavorAgent\Support\NormalizesInput;
use FlavorAgent\Support\RecommendationResolvedSignature;
use FlavorAgent\Support\RecommendationReviewSignature;
use FlavorAgent\Support\StringArray;

final class TemplateAbilities {
	use NormalizesInput;

	private const REVIEW_TEMPLATE_PATTERN_LIMIT      = 30;
	private const REVIEW_TEMPLATE_PART_PATTERN_LIMIT = 30;

	public static function list_template_parts( mixed $input ): array {
		$input = self::normalize_input( $input );
		$area  = $input['area'] ?? null;

		return [
			'templateParts' => ServerCollector::for_template_parts( $area ),
		];
	}

	/**
	 * Recommend template-part composition and patterns for a template.
	 *
	 * The shipped template panel keeps this request template-global.
	 *
	 * @param array $input { templateRef: string, templateType?: string, prompt?: string, visiblePatternNames?: string[] }
	 * @return array|\WP_Error Suggestions payload or error.
	 */
	public static function recommend_template( mixed $input ): array|\WP_Error {
		$input                  = self::normalize_input( $input );
		$resolve_signature_only = filter_var(
			$input['resolveSignatureOnly'] ?? false,
			FILTER_VALIDATE_BOOLEAN
		);

		$template_ref          = isset( $input['templateRef'] )
			? trim( (string) $input['templateRef'] )
			: '';
		$template_type         = isset( $input['templateType'] ) && is_string( $input['templateType'] ) && $input['templateType'] !== ''
			? $input['templateType']
			: null;
		$prompt                = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : '';
		$visible_pattern_names = array_key_exists( 'visiblePatternNames', $input )
			? StringArray::sanitize( $input['visiblePatternNames'] )
			: null;

		if ( $template_ref === '' ) {
			return new \WP_Error(
				'missing_template_ref',
				'A templateRef is required.',
				[ 'status' => 400 ]
			);
		}

		$context = ServerCollector::for_template( $template_ref, $template_type, $visible_pattern_names );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$review_context = ServerCollector::for_template(
			$template_ref,
			is_string( $context['templateType'] ?? null ) && '' !== $context['templateType']
				? sanitize_key( (string) $context['templateType'] )
				: $template_type,
			null
		);
		if ( is_wp_error( $review_context ) ) {
			return $review_context;
		}

		if ( array_key_exists( 'visiblePatternNames', $input ) ) {
			$context['visiblePatternNames'] = is_array( $visible_pattern_names ) ? $visible_pattern_names : [];
		}

		$editor_slots     = self::normalize_template_editor_slots( $input['editorSlots'] ?? null );
		$editor_structure = self::normalize_template_editor_structure( $input['editorStructure'] ?? null );
		$context          = self::apply_template_live_slot_context( $context, $editor_slots );
		$context          = self::apply_template_live_structure_context( $context, $editor_structure );

		$resolved_context_signature = RecommendationResolvedSignature::from_payload(
			'template',
			[
				'context' => $context,
				'prompt'  => $prompt,
			]
		);

		$review_context_signature = self::build_template_review_context_signature( $review_context );

		if ( $resolve_signature_only ) {
			return [
				'reviewContextSignature'   => $review_context_signature,
				'resolvedContextSignature' => $resolved_context_signature,
			];
		}

		$docs_guidance = self::collect_wordpress_docs_guidance( $context, $prompt );
		$system        = TemplatePrompt::build_system();
		$user          = TemplatePrompt::build_user(
			$context,
			$prompt,
			$docs_guidance
		);

		$result = ResponsesClient::rank(
			$system,
			$user,
			null,
			ResponseSchema::get( 'template' ),
			'flavor_agent_template'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = TemplatePrompt::parse_response( $result, $context );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$payload['reviewContextSignature']   = $review_context_signature;
		$payload['resolvedContextSignature'] = $resolved_context_signature;

		return $payload;
	}

	/**
	 * Recommend composition improvements for a single template part.
	 *
	 * @param array $input { templatePartRef: string, prompt?: string, visiblePatternNames?: string[] }
	 * @return array|\WP_Error Suggestions payload or error.
	 */
	public static function recommend_template_part( mixed $input ): array|\WP_Error {
		$input                  = self::normalize_input( $input );
		$resolve_signature_only = filter_var(
			$input['resolveSignatureOnly'] ?? false,
			FILTER_VALIDATE_BOOLEAN
		);

		$template_part_ref     = isset( $input['templatePartRef'] )
			? trim( (string) $input['templatePartRef'] )
			: '';
		$prompt                = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : '';
		$visible_pattern_names = array_key_exists( 'visiblePatternNames', $input )
			? StringArray::sanitize( $input['visiblePatternNames'] )
			: null;

		if ( $template_part_ref === '' ) {
			return new \WP_Error(
				'missing_template_part_ref',
				'A templatePartRef is required.',
				[ 'status' => 400 ]
			);
		}

		$context = ServerCollector::for_template_part( $template_part_ref, $visible_pattern_names );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$review_context = ServerCollector::for_template_part( $template_part_ref, null );
		if ( is_wp_error( $review_context ) ) {
			return $review_context;
		}

		if ( array_key_exists( 'visiblePatternNames', $input ) ) {
			$context['visiblePatternNames'] = is_array( $visible_pattern_names ) ? $visible_pattern_names : [];
		}

		$editor_structure = self::normalize_template_part_editor_structure( $input['editorStructure'] ?? null );
		$context          = self::apply_template_part_live_structure_context( $context, $editor_structure );

		$resolved_context_signature = RecommendationResolvedSignature::from_payload(
			'template-part',
			[
				'context' => $context,
				'prompt'  => $prompt,
			]
		);

		$review_context_signature = self::build_template_part_review_context_signature( $review_context );

		if ( $resolve_signature_only ) {
			return [
				'reviewContextSignature'   => $review_context_signature,
				'resolvedContextSignature' => $resolved_context_signature,
			];
		}

		$docs_guidance = self::collect_template_part_wordpress_docs_guidance( $context, $prompt );
		$system        = TemplatePartPrompt::build_system();
		$user          = TemplatePartPrompt::build_user(
			$context,
			$prompt,
			$docs_guidance
		);

		$result = ResponsesClient::rank(
			$system,
			$user,
			null,
			ResponseSchema::get( 'template_part' ),
			'flavor_agent_template_part'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = TemplatePartPrompt::parse_response( $result, $context );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$payload['reviewContextSignature']   = $review_context_signature;
		$payload['resolvedContextSignature'] = $resolved_context_signature;

		return $payload;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_template_editor_slots( mixed $input ): array {
		if ( is_object( $input ) ) {
			$input = get_object_vars( $input );
		}

		if ( ! is_array( $input ) ) {
			return [];
		}

		$result = [];

		if ( array_key_exists( 'assignedParts', $input ) ) {
			$assigned_parts = [];

			foreach ( is_array( $input['assignedParts'] ) ? $input['assignedParts'] : [] as $part ) {
				if ( is_object( $part ) ) {
					$part = get_object_vars( $part );
				}

				if ( ! is_array( $part ) ) {
					continue;
				}

				$slug = sanitize_key( (string) ( $part['slug'] ?? '' ) );
				$area = sanitize_key( (string) ( $part['area'] ?? '' ) );

				if ( $slug === '' ) {
					continue;
				}

				$assigned_parts[] = [
					'slug' => $slug,
					'area' => $area,
				];
			}

			$result['assignedParts'] = $assigned_parts;
		}

		if ( array_key_exists( 'emptyAreas', $input ) ) {
			$result['emptyAreas'] = array_values(
				array_unique(
					array_map(
						'sanitize_key',
						StringArray::sanitize( $input['emptyAreas'] )
					)
				)
			);
		}

		if ( array_key_exists( 'allowedAreas', $input ) ) {
			$result['allowedAreas'] = array_values(
				array_unique(
					array_map(
						'sanitize_key',
						StringArray::sanitize( $input['allowedAreas'] )
					)
				)
			);
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_template_editor_structure( mixed $input ): array {
		if ( is_object( $input ) ) {
			$input = get_object_vars( $input );
		}

		if ( ! is_array( $input ) ) {
			return [];
		}

		$result = [];

		if ( array_key_exists( 'topLevelBlockTree', $input ) ) {
			$top_level_block_tree = [];

			foreach ( is_array( $input['topLevelBlockTree'] ) ? $input['topLevelBlockTree'] : [] as $node ) {
				if ( is_object( $node ) ) {
					$node = get_object_vars( $node );
				}

				if ( ! is_array( $node ) ) {
					continue;
				}

				$path = self::sanitize_block_path( $node['path'] ?? null );
				$name = sanitize_text_field( (string) ( $node['name'] ?? '' ) );

				if ( null === $path || '' === $name ) {
					continue;
				}

				$entry = [
					'path'  => $path,
					'name'  => $name,
					'label' => sanitize_text_field( (string) ( $node['label'] ?? '' ) ),
				];

				$attributes = self::normalize_template_block_attributes( $node['attributes'] ?? null );
				if ( [] !== $attributes ) {
					$entry['attributes'] = $attributes;
				}

				if ( isset( $node['childCount'] ) && is_numeric( $node['childCount'] ) ) {
					$entry['childCount'] = max( 0, (int) $node['childCount'] );
				}

				$slot = self::normalize_template_slot_summary( $node['slot'] ?? null );
				if ( [] !== $slot ) {
					$entry['slot'] = $slot;
				}

				$top_level_block_tree[] = $entry;
			}

			$result['topLevelBlockTree'] = $top_level_block_tree;
		}

		if ( array_key_exists( 'structureStats', $input ) ) {
			$result['structureStats'] = self::normalize_template_structure_stats(
				$input['structureStats']
			);
		}

		if ( array_key_exists( 'currentPatternOverrides', $input ) ) {
			$result['currentPatternOverrides'] = self::normalize_pattern_override_summary(
				$input['currentPatternOverrides']
			);
		}

		if ( array_key_exists( 'currentViewportVisibility', $input ) ) {
			$result['currentViewportVisibility'] = self::normalize_viewport_visibility_summary(
				$input['currentViewportVisibility']
			);
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_template_part_editor_structure( mixed $input ): array {
		$input = self::normalize_input( $input );

		if ( ! is_array( $input ) ) {
			return [];
		}

		$result = [];

		if ( array_key_exists( 'blockTree', $input ) ) {
			$result['blockTree'] = self::normalize_template_part_block_tree(
				$input['blockTree']
			);
		}

		if ( array_key_exists( 'allBlockPaths', $input ) ) {
			$result['allBlockPaths'] = self::normalize_template_part_path_index(
				$input['allBlockPaths']
			);
		}

		$has_live_path_coverage = array_key_exists( 'allBlockPaths', $result ) || array_key_exists( 'blockTree', $result );
		$path_lookup            = self::build_template_part_path_lookup_from_live_structure( $result );

		if ( array_key_exists( 'topLevelBlocks', $input ) ) {
			$result['topLevelBlocks'] = array_values(
				array_filter(
					array_map(
						'sanitize_text_field',
						StringArray::sanitize( $input['topLevelBlocks'] )
					),
					static fn( string $name ): bool => '' !== $name
				)
			);
		}

		if ( array_key_exists( 'blockCounts', $input ) ) {
			$result['blockCounts'] = self::normalize_block_counts(
				$input['blockCounts']
			);
		}

		if ( array_key_exists( 'structureStats', $input ) ) {
			$result['structureStats'] = self::normalize_template_part_structure_stats(
				$input['structureStats']
			);
		}

		if ( array_key_exists( 'currentPatternOverrides', $input ) ) {
			$overrides                         = self::normalize_pattern_override_summary(
				$input['currentPatternOverrides']
			);
			$result['currentPatternOverrides'] = $has_live_path_coverage
				? self::filter_pattern_override_summary_by_path_lookup( $overrides, $path_lookup )
				: $overrides;
		}

		if ( array_key_exists( 'operationTargets', $input ) ) {
			$result['operationTargets'] = self::normalize_template_part_operation_targets(
				$input['operationTargets'],
				$path_lookup
			);
		}

		if ( array_key_exists( 'insertionAnchors', $input ) ) {
			$result['insertionAnchors'] = self::normalize_template_part_insertion_anchors(
				$input['insertionAnchors'],
				$path_lookup
			);
		}

		if ( array_key_exists( 'structuralConstraints', $input ) ) {
			$result['structuralConstraints'] = self::normalize_template_part_structural_constraints(
				$input['structuralConstraints'],
				$path_lookup
			);
		}

		if ( $has_live_path_coverage ) {
			$result['topLevelBlocks'] = self::derive_template_part_top_level_blocks_from_path_lookup(
				$path_lookup
			);
			$result['blockCounts']    = self::derive_template_part_block_counts_from_path_lookup(
				$path_lookup
			);
			$result['structureStats'] = self::derive_template_part_structure_stats_from_path_lookup(
				$path_lookup
			);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $editor_slots
	 * @return array<string, mixed>
	 */
	private static function apply_template_live_slot_context( array $context, array $editor_slots ): array {
		if ( array_key_exists( 'assignedParts', $editor_slots ) ) {
			$context['assignedParts'] = $editor_slots['assignedParts'];
		}

		if ( array_key_exists( 'emptyAreas', $editor_slots ) ) {
			$context['emptyAreas'] = $editor_slots['emptyAreas'];
		}

		if (
			array_key_exists( 'assignedParts', $editor_slots )
			|| array_key_exists( 'emptyAreas', $editor_slots )
			|| array_key_exists( 'allowedAreas', $editor_slots )
		) {
			$context['allowedAreas'] = self::build_effective_template_allowed_areas(
				$context,
				$editor_slots
			);
		}

		return $context;
	}

	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $editor_structure
	 * @return array<string, mixed>
	 */
	private static function apply_template_live_structure_context( array $context, array $editor_structure ): array {
		if ( array_key_exists( 'topLevelBlockTree', $editor_structure ) ) {
			$context['topLevelBlockTree']        = $editor_structure['topLevelBlockTree'];
			$context['topLevelInsertionAnchors'] = self::build_top_level_insertion_anchors(
				$editor_structure['topLevelBlockTree']
			);
		}

		if ( array_key_exists( 'structureStats', $editor_structure ) ) {
			$context['structureStats'] = $editor_structure['structureStats'];
		}

		if ( array_key_exists( 'currentPatternOverrides', $editor_structure ) ) {
			$context['currentPatternOverrides'] = $editor_structure['currentPatternOverrides'];
		}

		if ( array_key_exists( 'currentViewportVisibility', $editor_structure ) ) {
			$context['currentViewportVisibility'] = $editor_structure['currentViewportVisibility'];
		}

		return $context;
	}

	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $editor_structure
	 * @return array<string, mixed>
	 */
	private static function apply_template_part_live_structure_context( array $context, array $editor_structure ): array {
		foreach (
			[
				'blockTree',
				'allBlockPaths',
				'topLevelBlocks',
				'blockCounts',
				'structureStats',
				'currentPatternOverrides',
				'operationTargets',
				'insertionAnchors',
				'structuralConstraints',
			] as $key
		) {
			if ( array_key_exists( $key, $editor_structure ) ) {
				$context[ $key ] = $editor_structure[ $key ];
			}
		}

		return $context;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_pattern_override_summary( mixed $input ): array {
		$input = self::normalize_input( $input );

		$blocks = [];

		foreach ( is_array( $input['blocks'] ?? null ) ? $input['blocks'] : [] as $block ) {
			$block = self::normalize_input( $block );
			$path  = self::sanitize_block_path( $block['path'] ?? null );
			$name  = sanitize_text_field( (string) ( $block['name'] ?? '' ) );

			if ( null === $path || '' === $name ) {
				continue;
			}

			$entry = [
				'path'               => $path,
				'name'               => $name,
				'label'              => sanitize_text_field( (string) ( $block['label'] ?? '' ) ),
				'overrideAttributes' => array_values(
					array_unique(
						array_map(
							'sanitize_key',
							StringArray::sanitize( $block['overrideAttributes'] ?? [] )
						)
					)
				),
				'usesDefaultBinding' => ! empty( $block['usesDefaultBinding'] ),
			];

			$bindable_attributes = array_values(
				array_unique(
					array_map(
						'sanitize_key',
						StringArray::sanitize( $block['bindableAttributes'] ?? [] )
					)
				)
			);

			if ( [] !== $bindable_attributes ) {
				$entry['bindableAttributes'] = $bindable_attributes;
			}

			$unsupported_attributes = array_values(
				array_unique(
					array_map(
						'sanitize_key',
						StringArray::sanitize( $block['unsupportedAttributes'] ?? [] )
					)
				)
			);

			if ( [] !== $unsupported_attributes ) {
				$entry['unsupportedAttributes'] = $unsupported_attributes;
			}

			$blocks[] = $entry;
		}

		return [
			'hasOverrides' => ! empty( $input['hasOverrides'] ) || [] !== $blocks,
			'blockCount'   => [] !== $blocks ? count( $blocks ) : max( 0, (int) ( $input['blockCount'] ?? 0 ) ),
			'blockNames'   => array_values(
				array_unique(
					array_map(
						'sanitize_text_field',
						StringArray::sanitize( $input['blockNames'] ?? array_column( $blocks, 'name' ) )
					)
				)
			),
			'blocks'       => $blocks,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_viewport_visibility_summary( mixed $input ): array {
		$input = self::normalize_input( $input );

		$blocks = [];

		foreach ( is_array( $input['blocks'] ?? null ) ? $input['blocks'] : [] as $block ) {
			$block = self::normalize_input( $block );
			$path  = self::sanitize_block_path( $block['path'] ?? null );
			$name  = sanitize_text_field( (string) ( $block['name'] ?? '' ) );

			if ( null === $path || '' === $name ) {
				continue;
			}

			$blocks[] = [
				'path'             => $path,
				'name'             => $name,
				'label'            => sanitize_text_field( (string) ( $block['label'] ?? '' ) ),
				'hiddenViewports'  => array_values(
					array_unique(
						array_map(
							'sanitize_key',
							StringArray::sanitize( $block['hiddenViewports'] ?? [] )
						)
					)
				),
				'visibleViewports' => array_values(
					array_unique(
						array_map(
							'sanitize_key',
							StringArray::sanitize( $block['visibleViewports'] ?? [] )
						)
					)
				),
			];
		}

		return [
			'hasVisibilityRules' => ! empty( $input['hasVisibilityRules'] ) || [] !== $blocks,
			'blockCount'         => [] !== $blocks ? count( $blocks ) : max( 0, (int) ( $input['blockCount'] ?? 0 ) ),
			'blocks'             => $blocks,
		];
	}

	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $editor_slots
	 * @return string[]
	 */
	private static function build_effective_template_allowed_areas( array $context, array $editor_slots ): array {
		$allowed_areas = StringArray::sanitize( $context['allowedAreas'] ?? [] );

		foreach ( is_array( $editor_slots['assignedParts'] ?? null ) ? $editor_slots['assignedParts'] : [] as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$area = sanitize_key( (string) ( $part['area'] ?? '' ) );

			if ( '' !== $area ) {
				$allowed_areas[] = $area;
			}
		}

		$allowed_areas = array_merge(
			$allowed_areas,
			StringArray::sanitize( $editor_slots['emptyAreas'] ?? [] ),
			StringArray::sanitize( $editor_slots['allowedAreas'] ?? [] )
		);

		$allowed_areas = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $allowed_areas ),
					static fn( string $area ): bool => '' !== $area
				)
			)
		);
		sort( $allowed_areas );

		return $allowed_areas;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_template_structure_stats( mixed $input ): array {
		$input = self::normalize_input( $input );

		if ( ! is_array( $input ) ) {
			return [];
		}

		return [
			'blockCount'         => max( 0, (int) ( $input['blockCount'] ?? 0 ) ),
			'maxDepth'           => max( 0, (int) ( $input['maxDepth'] ?? 0 ) ),
			'topLevelBlockCount' => max( 0, (int) ( $input['topLevelBlockCount'] ?? 0 ) ),
			'hasNavigation'      => ! empty( $input['hasNavigation'] ),
			'hasQuery'           => ! empty( $input['hasQuery'] ),
			'hasTemplateParts'   => ! empty( $input['hasTemplateParts'] ),
			'firstTopLevelBlock' => sanitize_text_field( (string) ( $input['firstTopLevelBlock'] ?? '' ) ),
			'lastTopLevelBlock'  => sanitize_text_field( (string) ( $input['lastTopLevelBlock'] ?? '' ) ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_template_part_structure_stats( mixed $input ): array {
		$input = self::normalize_input( $input );

		if ( ! is_array( $input ) ) {
			return [];
		}

		return [
			'blockCount'            => max( 0, (int) ( $input['blockCount'] ?? 0 ) ),
			'maxDepth'              => max( 0, (int) ( $input['maxDepth'] ?? 0 ) ),
			'hasNavigation'         => ! empty( $input['hasNavigation'] ),
			'containsLogo'          => ! empty( $input['containsLogo'] ),
			'containsSiteTitle'     => ! empty( $input['containsSiteTitle'] ),
			'containsSearch'        => ! empty( $input['containsSearch'] ),
			'containsSocialLinks'   => ! empty( $input['containsSocialLinks'] ),
			'containsQuery'         => ! empty( $input['containsQuery'] ),
			'containsColumns'       => ! empty( $input['containsColumns'] ),
			'containsButtons'       => ! empty( $input['containsButtons'] ),
			'containsSpacer'        => ! empty( $input['containsSpacer'] ),
			'containsSeparator'     => ! empty( $input['containsSeparator'] ),
			'firstTopLevelBlock'    => sanitize_text_field( (string) ( $input['firstTopLevelBlock'] ?? '' ) ),
			'lastTopLevelBlock'     => sanitize_text_field( (string) ( $input['lastTopLevelBlock'] ?? '' ) ),
			'hasSingleWrapperGroup' => ! empty( $input['hasSingleWrapperGroup'] ),
			'isNearlyEmpty'         => ! empty( $input['isNearlyEmpty'] ),
		];
	}

	/**
	 * @return array<string, int>
	 */
	private static function normalize_block_counts( mixed $input ): array {
		if ( is_object( $input ) ) {
			$input = get_object_vars( $input );
		}

		if ( ! is_array( $input ) ) {
			return [];
		}

		$result = [];

		foreach ( $input as $name => $count ) {
			$block_name = sanitize_text_field( (string) $name );

			if ( '' === $block_name || ! is_numeric( $count ) ) {
				continue;
			}

			$result[ $block_name ] = max( 0, (int) $count );
		}

		return $result;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_template_part_block_tree( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$tree = [];

		foreach ( $input as $node ) {
			$normalized = self::normalize_template_part_block_tree_node( $node );

			if ( null !== $normalized ) {
				$tree[] = $normalized;
			}
		}

		return $tree;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function normalize_template_part_block_tree_node( mixed $node ): ?array {
		$entry = self::normalize_template_part_path_node( $node );

		if ( null === $entry ) {
			return null;
		}

		if ( is_object( $node ) ) {
			$node = get_object_vars( $node );
		}

		$children = [];
		foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : [] as $child ) {
			$normalized_child = self::normalize_template_part_block_tree_node( $child );

			if ( null !== $normalized_child ) {
				$children[] = $normalized_child;
			}
		}

		$entry['children'] = $children;

		return $entry;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_template_part_path_index( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$paths = [];

		foreach ( $input as $node ) {
			$normalized = self::normalize_template_part_path_node( $node );

			if ( null !== $normalized ) {
				$paths[] = $normalized;
			}
		}

		return $paths;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function normalize_template_part_path_node( mixed $node ): ?array {
		if ( is_object( $node ) ) {
			$node = get_object_vars( $node );
		}

		if ( ! is_array( $node ) ) {
			return null;
		}

		$path = self::sanitize_block_path( $node['path'] ?? null );
		$name = sanitize_text_field( (string) ( $node['name'] ?? '' ) );

		if ( null === $path || '' === $name ) {
			return null;
		}

		$entry = [
			'path'       => $path,
			'name'       => $name,
			'label'      => sanitize_text_field( (string) ( $node['label'] ?? '' ) ),
			'attributes' => self::normalize_template_block_attributes( $node['attributes'] ?? null ),
			'childCount' => isset( $node['childCount'] ) && is_numeric( $node['childCount'] )
				? max( 0, (int) $node['childCount'] )
				: 0,
		];

		$slot = self::normalize_template_slot_summary( $node['slot'] ?? null );
		if ( [] !== $slot ) {
			$entry['slot'] = $slot;
		}

		return $entry;
	}

	/**
	 * @param array<string, mixed> $structure
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_template_part_path_lookup_from_live_structure( array $structure ): array {
		if ( array_key_exists( 'allBlockPaths', $structure ) ) {
			$lookup = [];

			foreach ( is_array( $structure['allBlockPaths'] ) ? $structure['allBlockPaths'] : [] as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}

				$path = self::sanitize_block_path( $node['path'] ?? null );

				if ( null === $path ) {
					continue;
				}

				$lookup[ self::block_path_key( $path ) ] = $node;
			}

			return $lookup;
		}

		if ( array_key_exists( 'blockTree', $structure ) ) {
			return self::build_template_part_path_lookup_from_block_tree(
				is_array( $structure['blockTree'] ) ? $structure['blockTree'] : []
			);
		}

		return [];
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_template_part_path_lookup_from_block_tree( array $tree ): array {
		$lookup = [];

		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path = self::sanitize_block_path( $node['path'] ?? null );

			if ( null !== $path ) {
				$lookup[ self::block_path_key( $path ) ] = $node;
			}

			$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
			$lookup   = array_merge(
				$lookup,
				self::build_template_part_path_lookup_from_block_tree( $children )
			);
		}

		return $lookup;
	}

	/**
	 * @param array<string, mixed>               $summary
	 * @param array<string, array<string, mixed>> $path_lookup
	 * @return array<string, mixed>
	 */
	private static function filter_pattern_override_summary_by_path_lookup( array $summary, array $path_lookup ): array {
		$blocks = [];

		foreach ( is_array( $summary['blocks'] ?? null ) ? $summary['blocks'] : [] as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$path = self::sanitize_block_path( $block['path'] ?? null );

			if ( null === $path || ! isset( $path_lookup[ self::block_path_key( $path ) ] ) ) {
				continue;
			}

			$blocks[] = $block;
		}

		return [
			'hasOverrides' => [] !== $blocks,
			'blockCount'   => count( $blocks ),
			'blockNames'   => array_values(
				array_unique(
					array_map(
						static fn( array $block ): string => sanitize_text_field( (string) ( $block['name'] ?? '' ) ),
						$blocks
					)
				)
			),
			'blocks'       => $blocks,
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $path_lookup
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_template_part_operation_targets( mixed $input, array $path_lookup ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$targets = [];

		foreach ( $input as $target ) {
			$target = self::normalize_input( $target );
			$path   = self::sanitize_block_path( $target['path'] ?? null );

			if ( null === $path ) {
				continue;
			}

			$path_key     = self::block_path_key( $path );
			$path_details = $path_lookup[ $path_key ] ?? null;

			if ( ! is_array( $path_details ) ) {
				continue;
			}

			$targets[] = [
				'path'              => $path,
				'name'              => sanitize_text_field( (string) ( $path_details['name'] ?? '' ) ),
				'label'             => sanitize_text_field(
					(string) ( $target['label'] ?? ( $path_details['label'] ?? '' ) )
				),
				'allowedOperations' => array_values(
					array_unique(
						array_filter(
							array_map(
								'sanitize_key',
								StringArray::sanitize( $target['allowedOperations'] ?? [] )
							),
							static fn( string $operation ): bool => '' !== $operation
						)
					)
				),
				'allowedInsertions' => array_values(
					array_unique(
						array_filter(
							array_map(
								'sanitize_key',
								StringArray::sanitize( $target['allowedInsertions'] ?? [] )
							),
							static fn( string $placement ): bool => '' !== $placement
						)
					)
				),
			];
		}

		return $targets;
	}

	/**
	 * @param array<string, array<string, mixed>> $path_lookup
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_template_part_insertion_anchors( mixed $input, array $path_lookup ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$anchors = [];

		foreach ( $input as $anchor ) {
			$anchor    = self::normalize_input( $anchor );
			$placement = sanitize_key( (string) ( $anchor['placement'] ?? '' ) );
			$path      = self::sanitize_block_path( $anchor['targetPath'] ?? null );

			if ( '' === $placement ) {
				continue;
			}

			$entry = [
				'placement' => $placement,
				'label'     => sanitize_text_field( (string) ( $anchor['label'] ?? '' ) ),
			];

			if ( null !== $path ) {
				$path_key     = self::block_path_key( $path );
				$path_details = $path_lookup[ $path_key ] ?? null;

				if ( ! is_array( $path_details ) ) {
					continue;
				}

				$entry['targetPath'] = $path;
				$entry['blockName']  = sanitize_text_field(
					(string) ( $path_details['name'] ?? '' )
				);
			}

			$anchors[] = $entry;
		}

		return $anchors;
	}

	/**
	 * @param array<string, array<string, mixed>> $path_lookup
	 * @return array<string, mixed>
	 */
	private static function normalize_template_part_structural_constraints( mixed $input, array $path_lookup ): array {
		$input = self::normalize_input( $input );

		$content_only_paths = self::normalize_path_list_against_lookup(
			$input['contentOnlyPaths'] ?? [],
			$path_lookup
		);
		$locked_paths       = self::normalize_path_list_against_lookup(
			$input['lockedPaths'] ?? [],
			$path_lookup
		);

		return [
			'contentOnlyPaths' => $content_only_paths,
			'lockedPaths'      => $locked_paths,
			'hasContentOnly'   => count( $content_only_paths ) > 0,
			'hasLockedBlocks'  => count( $locked_paths ) > 0,
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $path_lookup
	 * @return array<int, array<int, int>>
	 */
	private static function normalize_path_list_against_lookup( mixed $input, array $path_lookup ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$paths = [];

		foreach ( $input as $path ) {
			$normalized = self::sanitize_block_path( $path );

			if ( null === $normalized || ! isset( $path_lookup[ self::block_path_key( $normalized ) ] ) ) {
				continue;
			}

			$paths[ self::block_path_key( $normalized ) ] = $normalized;
		}

		return array_values( $paths );
	}

	/**
	 * @param array<string, array<string, mixed>> $path_lookup
	 * @return string[]
	 */
	private static function derive_template_part_top_level_blocks_from_path_lookup( array $path_lookup ): array {
		$top_level_blocks = [];

		foreach ( $path_lookup as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path = self::sanitize_block_path( $node['path'] ?? null );
			$name = sanitize_text_field( (string) ( $node['name'] ?? '' ) );

			if ( null === $path || 1 !== count( $path ) || '' === $name ) {
				continue;
			}

			$top_level_blocks[] = $name;
		}

		return $top_level_blocks;
	}

	/**
	 * @param array<string, array<string, mixed>> $path_lookup
	 * @return array<string, int>
	 */
	private static function derive_template_part_block_counts_from_path_lookup( array $path_lookup ): array {
		$block_counts = [];

		foreach ( $path_lookup as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $node['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$block_counts[ $name ] = ( $block_counts[ $name ] ?? 0 ) + 1;
		}

		return $block_counts;
	}

	/**
	 * @param array<string, array<string, mixed>> $path_lookup
	 * @return array<string, mixed>
	 */
	private static function derive_template_part_structure_stats_from_path_lookup( array $path_lookup ): array {
		$top_level_blocks = self::derive_template_part_top_level_blocks_from_path_lookup(
			$path_lookup
		);
		$block_counts     = self::derive_template_part_block_counts_from_path_lookup(
			$path_lookup
		);
		$block_count      = count( $path_lookup );
		$max_depth        = 0;

		foreach ( $path_lookup as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path = self::sanitize_block_path( $node['path'] ?? null );

			if ( null !== $path ) {
				$max_depth = max( $max_depth, count( $path ) );
			}
		}

		return [
			'blockCount'            => $block_count,
			'maxDepth'              => $max_depth,
			'hasNavigation'         => ! empty( $block_counts['core/navigation'] ),
			'containsLogo'          => ! empty( $block_counts['core/site-logo'] ),
			'containsSiteTitle'     => ! empty( $block_counts['core/site-title'] ),
			'containsSearch'        => ! empty( $block_counts['core/search'] ),
			'containsSocialLinks'   => ! empty( $block_counts['core/social-links'] ),
			'containsQuery'         => ! empty( $block_counts['core/query'] ),
			'containsColumns'       => ! empty( $block_counts['core/columns'] ),
			'containsButtons'       => ! empty( $block_counts['core/buttons'] ),
			'containsSpacer'        => ! empty( $block_counts['core/spacer'] ),
			'containsSeparator'     => ! empty( $block_counts['core/separator'] ),
			'firstTopLevelBlock'    => $top_level_blocks[0] ?? '',
			'lastTopLevelBlock'     => count( $top_level_blocks ) > 0 ? $top_level_blocks[ count( $top_level_blocks ) - 1 ] : '',
			'hasSingleWrapperGroup' => 1 === count( $top_level_blocks ) && 'core/group' === $top_level_blocks[0],
			'isNearlyEmpty'         => $block_count <= 1,
		];
	}

	/**
	 * @return array<string, scalar>
	 */
	private static function normalize_template_block_attributes( mixed $input ): array {
		if ( is_object( $input ) ) {
			$input = get_object_vars( $input );
		}

		if ( ! is_array( $input ) ) {
			return [];
		}

		$result         = [];
		$allowed_fields = [
			'tagName'              => 'text',
			'align'                => 'text',
			'overlayMenu'          => 'text',
			'maxNestingLevel'      => 'int',
			'showSubmenuIcon'      => 'bool',
			'placeholder'          => 'text',
			'slug'                 => 'key',
			'area'                 => 'key',
			'ref'                  => 'int',
			'templateLock'         => 'text',
			'layoutType'           => 'text',
			'layoutJustifyContent' => 'text',
			'layoutOrientation'    => 'text',
		];

		foreach ( $allowed_fields as $field => $type ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$value = $input[ $field ];

			switch ( $type ) {
				case 'bool':
					$result[ $field ] = (bool) $value;
					break;
				case 'int':
					if ( is_numeric( $value ) ) {
						$result[ $field ] = (int) $value;
					}
					break;
				case 'key':
					$sanitized = sanitize_key( (string) $value );
					if ( '' !== $sanitized ) {
						$result[ $field ] = $sanitized;
					}
					break;
				default:
					$sanitized = sanitize_text_field( (string) $value );
					if ( '' !== $sanitized ) {
						$result[ $field ] = $sanitized;
					}
					break;
			}
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_template_slot_summary( mixed $input ): array {
		if ( is_object( $input ) ) {
			$input = get_object_vars( $input );
		}

		if ( ! is_array( $input ) ) {
			return [];
		}

		return [
			'slug'    => sanitize_key( (string) ( $input['slug'] ?? '' ) ),
			'area'    => sanitize_key( (string) ( $input['area'] ?? '' ) ),
			'isEmpty' => ! empty( $input['isEmpty'] ),
		];
	}

	/**
	 * @param mixed $path
	 * @return int[]|null
	 */
	private static function sanitize_block_path( mixed $path ): ?array {
		if ( ! is_array( $path ) || count( $path ) === 0 ) {
			return null;
		}

		$normalized = [];

		foreach ( $path as $segment ) {
			if ( ! is_int( $segment ) && ! is_numeric( $segment ) ) {
				return null;
			}

			$segment = (int) $segment;

			if ( $segment < 0 ) {
				return null;
			}

			$normalized[] = $segment;
		}

		return $normalized;
	}

	/**
	 * @param int[] $path
	 */
	private static function block_path_key( array $path ): string {
		return implode( '.', $path );
	}

	/**
	 * @param array<int, array<string, mixed>> $top_level_block_tree
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_top_level_insertion_anchors( array $top_level_block_tree ): array {
		$anchors = [
			[
				'placement' => 'start',
				'label'     => 'Start of template',
			],
		];

		foreach ( $top_level_block_tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path       = self::sanitize_block_path( $node['path'] ?? null );
			$block_name = sanitize_text_field( (string) ( $node['name'] ?? '' ) );
			$label      = sanitize_text_field( (string) ( $node['label'] ?? $block_name ) );

			if ( null === $path || 1 !== count( $path ) || '' === $block_name || '' === $label ) {
				continue;
			}

			$path_key                        = self::block_path_key( $path );
			$anchors[ "before:{$path_key}" ] = [
				'placement'  => 'before_block_path',
				'targetPath' => $path,
				'blockName'  => $block_name,
				'label'      => 'Before ' . $label,
			];
			$anchors[ "after:{$path_key}" ]  = [
				'placement'  => 'after_block_path',
				'targetPath' => $path,
				'blockName'  => $block_name,
				'label'      => 'After ' . $label,
			];
		}

		$anchors[] = [
			'placement' => 'end',
			'label'     => 'End of template',
		];

		return array_values( $anchors );
	}

	private static function build_template_review_context_signature( array $context ): string {
		// Review freshness hashes only server-owned context so docs cache churn does not mark stored results stale.
		$payload      = [
			'template'      => self::normalize_template_review_identity( $context ),
			'allowedAreas'  => self::normalize_template_review_allowed_areas(
				is_array( $context['allowedAreas'] ?? null ) ? $context['allowedAreas'] : []
			),
			'availableParts' => self::normalize_template_review_available_parts( $context ),
			'patterns' => self::normalize_template_review_patterns(
				is_array( $context['patterns'] ?? null ) ? $context['patterns'] : [],
				self::REVIEW_TEMPLATE_PATTERN_LIMIT
			),
		];
		$theme_tokens = self::format_template_review_theme_tokens(
			is_array( $context['themeTokens'] ?? null ) ? $context['themeTokens'] : []
		);

		if ( '' !== $theme_tokens ) {
			$payload['themeTokens'] = $theme_tokens;
		}

		return RecommendationReviewSignature::from_payload(
			'template',
			$payload
		);
	}

	private static function build_template_part_review_context_signature( array $context ): string {
		// Review freshness hashes only server-owned context so docs cache churn does not mark stored results stale.
		$payload      = [
			'templatePart' => self::normalize_template_part_review_identity( $context ),
			'patterns' => self::normalize_template_review_patterns(
				is_array( $context['patterns'] ?? null ) ? $context['patterns'] : [],
				self::REVIEW_TEMPLATE_PART_PATTERN_LIMIT
			),
		];
		$theme_tokens = self::format_template_review_theme_tokens(
			is_array( $context['themeTokens'] ?? null ) ? $context['themeTokens'] : []
		);

		if ( '' !== $theme_tokens ) {
			$payload['themeTokens'] = $theme_tokens;
		}

		return RecommendationReviewSignature::from_payload(
			'template-part',
			$payload
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $patterns
	 * @return array<int, array<string, string>>
	 */
	private static function normalize_template_review_patterns( array $patterns, int $limit ): array {
		$normalized = [];

		foreach ( array_slice( $patterns, 0, $limit ) as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $pattern['name'] ?? '' ) );

			if ( $name === '' ) {
				continue;
			}

			$normalized[] = [
				'name'        => $name,
				'title'       => sanitize_text_field( (string) ( $pattern['title'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $pattern['description'] ?? '' ) ),
				'matchType'   => sanitize_key( (string) ( $pattern['matchType'] ?? '' ) ),
			];
		}

		return $normalized;
	}

	/**
	 * @return array<string, string>
	 */
	private static function normalize_template_review_identity( array $context ): array {
		return [
			'templateType' => sanitize_key( (string) ( $context['templateType'] ?? '' ) ),
			'title'        => sanitize_text_field( (string) ( $context['title'] ?? '' ) ),
		];
	}

	/**
	 * @param string[] $allowed_areas
	 * @return string[]
	 */
	private static function normalize_template_review_allowed_areas( array $allowed_areas ): array {
		$normalized = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_key',
						StringArray::sanitize( $allowed_areas )
					),
					static fn( string $area ): bool => '' !== $area
				)
			)
		);
		sort( $normalized );

		return $normalized;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private static function normalize_template_review_available_parts( array $context ): array {
		$assigned_slugs = [];

		foreach ( is_array( $context['assignedParts'] ?? null ) ? $context['assignedParts'] : [] as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$slug = sanitize_key( (string) ( $part['slug'] ?? '' ) );

			if ( '' !== $slug ) {
				$assigned_slugs[ $slug ] = true;
			}
		}

		$normalized = [];

		foreach ( is_array( $context['availableParts'] ?? null ) ? $context['availableParts'] : [] as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$slug = sanitize_key( (string) ( $part['slug'] ?? '' ) );

			if ( '' === $slug || isset( $assigned_slugs[ $slug ] ) ) {
				continue;
			}

			$normalized[] = [
				'slug'  => $slug,
				'title' => sanitize_text_field( (string) ( $part['title'] ?? '' ) ),
				'area'  => sanitize_key( (string) ( $part['area'] ?? '' ) ),
			];
		}

		usort(
			$normalized,
			static function ( array $left, array $right ): int {
				$left_key  = sanitize_key( (string) ( $left['area'] ?? '' ) ) . '|' . sanitize_key( (string) ( $left['slug'] ?? '' ) );
				$right_key = sanitize_key( (string) ( $right['area'] ?? '' ) ) . '|' . sanitize_key( (string) ( $right['slug'] ?? '' ) );

				return $left_key <=> $right_key;
			}
		);

		return $normalized;
	}

	/**
	 * @return array<string, string>
	 */
	private static function normalize_template_part_review_identity( array $context ): array {
		return [
			'slug'  => sanitize_key( (string) ( $context['slug'] ?? '' ) ),
			'title' => sanitize_text_field( (string) ( $context['title'] ?? '' ) ),
			'area'  => sanitize_key( (string) ( $context['area'] ?? '' ) ),
		];
	}

	/**
	 * @param array<string, mixed> $theme_tokens
	 * @return string
	 */
	private static function format_template_review_theme_tokens( array $theme_tokens ): string {
		return ThemeTokenFormatter::format( $theme_tokens );
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

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_template_part_wordpress_docs_guidance( array $context, string $prompt ): array {
		return CollectsDocsGuidance::collect(
			static fn( array $request_context, string $request_prompt ): string => self::build_template_part_wordpress_docs_query( $request_context, $request_prompt ),
			static fn( array $request_context, string $query ): string => AISearchClient::resolve_entity_key( 'core/template-part', $query ),
			static fn( array $request_context ): array => self::build_template_part_wordpress_docs_family_context( $request_context ),
			$context,
			$prompt
		);
	}

	private static function build_wordpress_docs_entity_key( array $context ): string {
		$template_type = isset( $context['templateType'] ) && is_string( $context['templateType'] )
			? sanitize_key( $context['templateType'] )
			: '';

		return AISearchClient::resolve_entity_key(
			$template_type !== '' ? 'template:' . $template_type : ''
		);
	}

	private static function build_wordpress_docs_query( array $context, string $prompt ): string {
		$template_type               = isset( $context['templateType'] ) && is_string( $context['templateType'] )
			? sanitize_key( $context['templateType'] )
			: '';
		$allowed_areas               = StringArray::sanitize( $context['allowedAreas'] ?? [] );
		$empty_areas                 = StringArray::sanitize( $context['emptyAreas'] ?? [] );
		$visible_pattern_names       = array_key_exists( 'visiblePatternNames', $context )
			? StringArray::sanitize( $context['visiblePatternNames'] ?? [] )
			: null;
		$top_level_block_tree        = is_array( $context['topLevelBlockTree'] ?? null ) ? $context['topLevelBlockTree'] : [];
		$structure_stats             = is_array( $context['structureStats'] ?? null ) ? $context['structureStats'] : [];
		$current_pattern_overrides   = is_array( $context['currentPatternOverrides'] ?? null ) ? $context['currentPatternOverrides'] : [];
		$current_viewport_visibility = is_array( $context['currentViewportVisibility'] ?? null ) ? $context['currentViewportVisibility'] : [];
		$top_level_block_names       = array_values(
			array_filter(
				array_map(
					static fn( mixed $node ): string => is_array( $node ) && is_string( $node['name'] ?? null )
						? sanitize_text_field( $node['name'] )
						: '',
					array_slice( $top_level_block_tree, 0, 6 )
				)
			)
		);
		$assigned                    = [];

		foreach ( is_array( $context['assignedParts'] ?? null ) ? $context['assignedParts'] : [] as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$slug = sanitize_key( (string) ( $part['slug'] ?? '' ) );
			$area = sanitize_key( (string) ( $part['area'] ?? '' ) );

			if ( $slug === '' ) {
				continue;
			}

			$assigned[] = $area !== '' ? "{$slug} in {$area}" : $slug;
		}

		$parts = [ 'WordPress block theme, site editor, and template part best practices' ];

		if ( $template_type !== '' ) {
			$parts[] = "template type {$template_type}";
		}

		if ( ! empty( $allowed_areas ) ) {
			$parts[] = 'template part areas ' . implode( ', ', $allowed_areas );
		}

		if ( ! empty( $assigned ) ) {
			$parts[] = 'current assignments ' . implode( ', ', array_slice( $assigned, 0, 6 ) );
		}

		if ( ! empty( $empty_areas ) ) {
			$parts[] = 'empty areas ' . implode( ', ', $empty_areas );
		}

		if ( ! empty( $top_level_block_names ) ) {
			$parts[] = 'top-level blocks ' . implode( ', ', $top_level_block_names );
		}

		if ( is_array( $visible_pattern_names ) ) {
			$visible_pattern_summary = [ 'visible pattern count ' . count( $visible_pattern_names ) ];
			$visible_pattern_sample  = array_slice( $visible_pattern_names, 0, 3 );

			if ( ! empty( $visible_pattern_sample ) ) {
				$visible_pattern_summary[] = 'visible patterns ' . implode( ', ', $visible_pattern_sample );
			}

			$parts[] = implode( ', ', $visible_pattern_summary );
		}

		if ( isset( $structure_stats['blockCount'] ) || isset( $structure_stats['maxDepth'] ) ) {
			$summary = [];

			if ( isset( $structure_stats['blockCount'] ) ) {
				$summary[] = (int) $structure_stats['blockCount'] . ' blocks';
			}

			if ( isset( $structure_stats['topLevelBlockCount'] ) ) {
				$summary[] = (int) $structure_stats['topLevelBlockCount'] . ' top level';
			}

			if ( isset( $structure_stats['maxDepth'] ) ) {
				$summary[] = 'depth ' . (int) $structure_stats['maxDepth'];
			}

			if ( ! empty( $summary ) ) {
				$parts[] = 'live structure ' . implode( ', ', $summary );
			}
		}

		if ( ! empty( $current_pattern_overrides['blockCount'] ) ) {
			$parts[] = 'override-ready blocks ' . (int) $current_pattern_overrides['blockCount'];
		}

		if ( ! empty( $current_viewport_visibility['blockCount'] ) ) {
			$parts[] = 'viewport-constrained blocks ' . (int) $current_viewport_visibility['blockCount'];
		}

		if ( $prompt !== '' ) {
			$parts[] = $prompt;
		}

		$parts[] = 'template files, template parts, block themes, and theme.json guidance';

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

	private static function build_template_part_wordpress_docs_query( array $context, string $prompt ): string {
		$area                      = isset( $context['area'] ) && is_string( $context['area'] )
			? sanitize_key( $context['area'] )
			: '';
		$slug                      = isset( $context['slug'] ) && is_string( $context['slug'] )
			? sanitize_key( $context['slug'] )
			: '';
		$top_level                 = StringArray::sanitize( $context['topLevelBlocks'] ?? [] );
		$block_counts              = is_array( $context['blockCounts'] ?? null ) ? $context['blockCounts'] : [];
		$current_pattern_overrides = is_array( $context['currentPatternOverrides'] ?? null ) ? $context['currentPatternOverrides'] : [];
		$operation_targets         = is_array( $context['operationTargets'] ?? null ) ? $context['operationTargets'] : [];
		$insertion_anchors         = is_array( $context['insertionAnchors'] ?? null ) ? $context['insertionAnchors'] : [];
		$structural_constraints    = is_array( $context['structuralConstraints'] ?? null ) ? $context['structuralConstraints'] : [];
		$parts                     = [ 'WordPress block theme template part best practices' ];

		if ( $area !== '' ) {
			$parts[] = "template part area {$area}";
		}

		if ( $slug !== '' ) {
			$parts[] = "template part slug {$slug}";
		}

		if ( ! empty( $top_level ) ) {
			$parts[] = 'top level blocks ' . implode( ', ', array_slice( $top_level, 0, 6 ) );
		}

		if ( ! empty( $block_counts ) ) {
			$parts[] = 'current blocks ' . implode(
				', ',
				array_map(
					static fn( string $name, int $count ): string => "{$name} x{$count}",
					array_slice( array_keys( $block_counts ), 0, 6 ),
					array_slice( array_values( $block_counts ), 0, 6 )
				)
			);
		}

		$override_block_names = array_slice(
			StringArray::sanitize( $current_pattern_overrides['blockNames'] ?? [] ),
			0,
			3
		);

		if ( [] === $override_block_names && ! empty( $current_pattern_overrides['blocks'] ) ) {
			$override_block_names = array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( mixed $block ): string => is_array( $block ) && is_string( $block['name'] ?? null )
								? sanitize_text_field( $block['name'] )
								: '',
							array_slice(
								is_array( $current_pattern_overrides['blocks'] ) ? $current_pattern_overrides['blocks'] : [],
								0,
								3
							)
						)
					)
				)
			);
		}

		if ( array_key_exists( 'currentPatternOverrides', $context ) ) {
			$override_summary = [ 'pattern override blocks ' . (int) ( $current_pattern_overrides['blockCount'] ?? 0 ) ];

			if ( ! empty( $override_block_names ) ) {
				$override_summary[] = 'override block names ' . implode( ', ', $override_block_names );
			}

			$parts[] = implode( ', ', $override_summary );
		}

		if ( ! empty( $operation_targets ) ) {
			$target_summaries = [];

			foreach ( array_slice( $operation_targets, 0, 4 ) as $target ) {
				if ( ! is_array( $target ) ) {
					continue;
				}

				$label              = sanitize_text_field(
					(string) ( $target['label'] ?? $target['name'] ?? '' )
				);
				$allowed_operations = array_slice(
					StringArray::sanitize( $target['allowedOperations'] ?? [] ),
					0,
					3
				);

				if ( '' === $label ) {
					continue;
				}

				if ( ! empty( $allowed_operations ) ) {
					$label .= ' [' . implode( '/', $allowed_operations ) . ']';
				}

				$target_summaries[] = $label;
			}

			if ( ! empty( $target_summaries ) ) {
				$parts[] = 'executable targets ' . implode( ', ', $target_summaries );
			}
		}

		if ( ! empty( $insertion_anchors ) ) {
			$anchor_summaries = [];

			foreach ( array_slice( $insertion_anchors, 0, 4 ) as $anchor ) {
				if ( ! is_array( $anchor ) ) {
					continue;
				}

				$label      = sanitize_text_field( (string) ( $anchor['label'] ?? '' ) );
				$placement  = sanitize_key( (string) ( $anchor['placement'] ?? '' ) );
				$block_name = sanitize_text_field( (string) ( $anchor['blockName'] ?? '' ) );
				$summary    = '' !== $label ? $label : $placement;

				if ( '' !== $block_name && '' !== $placement && '' === $label ) {
					$summary .= ' near ' . $block_name;
				}

				if ( '' !== $summary ) {
					$anchor_summaries[] = $summary;
				}
			}

			if ( ! empty( $anchor_summaries ) ) {
				$parts[] = 'validated anchors ' . implode( ', ', $anchor_summaries );
			}
		}

		if ( ! empty( $structural_constraints['hasContentOnly'] ) || ! empty( $structural_constraints['hasLockedBlocks'] ) ) {
			$constraint_summaries = [];

			if ( ! empty( $structural_constraints['hasContentOnly'] ) ) {
				$constraint_summaries[] = 'content-only paths ' . count( $structural_constraints['contentOnlyPaths'] ?? [] );
			}

			if ( ! empty( $structural_constraints['hasLockedBlocks'] ) ) {
				$constraint_summaries[] = 'locked paths ' . count( $structural_constraints['lockedPaths'] ?? [] );
			}

			if ( ! empty( $constraint_summaries ) ) {
				$parts[] = 'structural constraints ' . implode( ', ', $constraint_summaries );
			}
		}

		if ( $prompt !== '' ) {
			$parts[] = $prompt;
		}

		$parts[] = 'site editor, template parts, block patterns, and theme.json guidance';

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
		$entity_key    = self::build_wordpress_docs_entity_key( $context );
		$template_type = isset( $context['templateType'] ) && is_string( $context['templateType'] )
			? sanitize_key( $context['templateType'] )
			: '';

		if ( $entity_key === '' || $template_type === '' ) {
			return [];
		}

		$allowed_areas         = StringArray::sanitize( $context['allowedAreas'] ?? [] );
		$empty_areas           = StringArray::sanitize( $context['emptyAreas'] ?? [] );
		$visible_pattern_names = array_key_exists( 'visiblePatternNames', $context )
			? StringArray::sanitize( $context['visiblePatternNames'] ?? [] )
			: null;
		$assigned_areas        = [];

		foreach ( is_array( $context['assignedParts'] ?? null ) ? $context['assignedParts'] : [] as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$area = sanitize_key( (string) ( $part['area'] ?? '' ) );

			if ( $area !== '' ) {
				$assigned_areas[] = $area;
			}
		}

		$assigned_areas = array_values( array_unique( $assigned_areas ) );
		sort( $allowed_areas );
		sort( $empty_areas );
		sort( $assigned_areas );

		$family_context = [
			'surface'      => 'template',
			'entityKey'    => $entity_key,
			'templateType' => $template_type,
		];

		if ( ! empty( $allowed_areas ) ) {
			$family_context['allowedAreas'] = $allowed_areas;
		}

		if ( ! empty( $empty_areas ) ) {
			$family_context['emptyAreas'] = $empty_areas;
		}

		if ( ! empty( $assigned_areas ) ) {
			$family_context['assignedAreas'] = $assigned_areas;
		}

		if ( is_array( $visible_pattern_names ) ) {
			$family_context['hasVisiblePatternScope'] = true;
			$family_context['visiblePatternCount']    = count( $visible_pattern_names );
		}

		$top_level_block_names       = array_values(
			array_filter(
				array_map(
					static fn( mixed $node ): string => is_array( $node ) && is_string( $node['name'] ?? null )
						? sanitize_text_field( $node['name'] )
						: '',
					array_slice(
						is_array( $context['topLevelBlockTree'] ?? null ) ? $context['topLevelBlockTree'] : [],
						0,
						6
					)
				)
			)
		);
		$structure_stats             = is_array( $context['structureStats'] ?? null ) ? $context['structureStats'] : [];
		$current_pattern_overrides   = is_array( $context['currentPatternOverrides'] ?? null ) ? $context['currentPatternOverrides'] : [];
		$current_viewport_visibility = is_array( $context['currentViewportVisibility'] ?? null ) ? $context['currentViewportVisibility'] : [];

		if ( ! empty( $top_level_block_names ) ) {
			$family_context['topLevelBlocks'] = $top_level_block_names;
		}

		if ( isset( $structure_stats['blockCount'] ) ) {
			$family_context['blockCount'] = (int) $structure_stats['blockCount'];
		}

		if ( isset( $structure_stats['maxDepth'] ) ) {
			$family_context['maxDepth'] = (int) $structure_stats['maxDepth'];
		}

		if ( ! empty( $current_pattern_overrides['blockCount'] ) ) {
			$family_context['patternOverrideCount'] = (int) $current_pattern_overrides['blockCount'];
		}

		if ( ! empty( $current_viewport_visibility['blockCount'] ) ) {
			$family_context['visibilityConstraintCount'] = (int) $current_viewport_visibility['blockCount'];
		}

		return $family_context;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_template_part_wordpress_docs_family_context( array $context ): array {
		$area = isset( $context['area'] ) && is_string( $context['area'] )
			? sanitize_key( $context['area'] )
			: '';
		$slug = isset( $context['slug'] ) && is_string( $context['slug'] )
			? sanitize_key( $context['slug'] )
			: '';

		$family_context = [
			'surface'   => 'template-part',
			'entityKey' => 'core/template-part',
		];

		if ( $area !== '' ) {
			$family_context['area'] = $area;
		}

		if ( $slug !== '' ) {
			$family_context['slug'] = $slug;
		}

		$operation_targets         = is_array( $context['operationTargets'] ?? null ) ? $context['operationTargets'] : [];
		$insertion_anchors         = is_array( $context['insertionAnchors'] ?? null ) ? $context['insertionAnchors'] : [];
		$structural_constraints    = is_array( $context['structuralConstraints'] ?? null ) ? $context['structuralConstraints'] : [];
		$current_pattern_overrides = is_array( $context['currentPatternOverrides'] ?? null ) ? $context['currentPatternOverrides'] : [];
		$target_names              = array_values(
			array_filter(
				array_map(
					static fn( mixed $target ): string => is_array( $target ) && is_string( $target['name'] ?? null )
						? sanitize_text_field( $target['name'] )
						: '',
					array_slice( $operation_targets, 0, 4 )
				)
			)
		);
		$anchor_placements         = array_values(
			array_filter(
				array_map(
					static fn( mixed $anchor ): string => is_array( $anchor ) && is_string( $anchor['placement'] ?? null )
						? sanitize_key( $anchor['placement'] )
						: '',
					array_slice( $insertion_anchors, 0, 4 )
				)
			)
		);

		if ( ! empty( $target_names ) ) {
			$family_context['targetBlocks'] = $target_names;
		}

		if ( ! empty( $anchor_placements ) ) {
			$family_context['anchorPlacements'] = $anchor_placements;
		}

		if ( array_key_exists( 'currentPatternOverrides', $context ) ) {
			$family_context['hasPatternOverrides']  = ! empty( $current_pattern_overrides['hasOverrides'] )
				|| ! empty( $current_pattern_overrides['blockCount'] );
			$family_context['patternOverrideCount'] = (int) ( $current_pattern_overrides['blockCount'] ?? 0 );
		}

		if ( ! empty( $structural_constraints['hasContentOnly'] ) ) {
			$family_context['hasContentOnly'] = true;
		}

		if ( ! empty( $structural_constraints['hasLockedBlocks'] ) ) {
			$family_context['hasLockedBlocks'] = true;
		}

		return $family_context;
	}
}
