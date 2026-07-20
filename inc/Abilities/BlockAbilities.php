<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Context\BlockRecommendationExecutionContract;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\ChatClient;
use FlavorAgent\LLM\Prompt;
use FlavorAgent\LLM\ResponseSchema;
use FlavorAgent\Support\CollectsDocsGuidance;
use FlavorAgent\Support\DesignSemantics;
use FlavorAgent\Support\DocsGuidanceResult;
use FlavorAgent\Support\NonNegativeInteger;
use FlavorAgent\Support\NormalizesInput;
use FlavorAgent\Support\RecommendationResolvedSignature;
use FlavorAgent\Support\StringArray;

final class BlockAbilities {
	use NormalizesInput;

	private const STRUCTURAL_SUMMARY_MAX_ITEMS = 6;

	private const STRUCTURAL_SUMMARY_MAX_CHILDREN = 6;

	private const STRUCTURAL_SUMMARY_MAX_DEPTH = 2;

	// Mirrors BLOCK_INTERIOR_MAX_* in src/utils/block-recommendation-context.js.
	// Larger than the branch caps because containers routinely hold 6-8 children.
	private const BLOCK_INTERIOR_MAX_ITEMS = 8;

	private const BLOCK_INTERIOR_MAX_CHILDREN = 8;

	private const BLOCK_INTERIOR_MAX_DEPTH = 3;

	private const DOCS_SCOPE_MAX_ITEMS = 3;

	private const BLOCK_OPERATION_CONTEXT_MAX_PATTERNS = 20;

	private const BLOCK_OPERATION_CONTEXT_ALLOWED_ACTIONS = [
		'insert_before',
		'insert_after',
		'replace',
	];

	private const BLOCK_OPERATION_CONTEXT_ALLOWED_SOURCES = [
		'core',
		'theme',
		'plugin',
		'user',
	];

	public static function recommend_block( mixed $input ): array|\WP_Error {
		$input                  = self::normalize_map( $input );
		$resolve_signature_only = filter_var(
			$input['resolveSignatureOnly'] ?? false,
			FILTER_VALIDATE_BOOLEAN
		);

		$prepared = self::prepare_recommend_block_input( $input );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$context                    = $prepared['context'];
		$prompt                     = $prepared['prompt'];
		$execution_contract         = BlockRecommendationExecutionContract::from_context( $context );
		$docs_result                = self::collect_wordpress_docs_guidance_result(
			$context,
			$prompt,
			[
				'signatureOnly' => $resolve_signature_only,
			]
		);
		$docs_grounding_fingerprint = DocsGuidanceResult::content_fingerprint( $docs_result );

		$resolved_context_signature = RecommendationResolvedSignature::from_payload(
			'block',
			[
				'context'                  => $context,
				'prompt'                   => $prompt,
				'docsGroundingFingerprint' => $docs_grounding_fingerprint,
			]
		);

		if ( $resolve_signature_only ) {
			return [
				'resolvedContextSignature' => $resolved_context_signature,
				'docsGrounding'            => DocsGuidanceResult::public_summary( $docs_result ),
				'docsGroundingFingerprint' => $docs_grounding_fingerprint,
			];
		}

		if ( self::normalize_editing_mode( $context['block']['editingMode'] ?? 'default' ) === 'disabled' ) {
			$payload                             = self::get_empty_recommendation_payload();
			$payload['preFilteringCounts']       = $payload['preFilteringCounts'] ?? [
				'settings' => 0,
				'styles'   => 0,
				'block'    => 0,
			];
			$payload['executionContract']        = $execution_contract;
			$payload['resolvedContextSignature'] = $resolved_context_signature;
			$payload['docsGrounding']            = DocsGuidanceResult::public_summary( $docs_result );
			$payload['docsGroundingFingerprint'] = $docs_grounding_fingerprint;

			return $payload;
		}

		$docs_guidance = DocsGuidanceResult::guidance( $docs_result );
		$system_prompt = Prompt::build_system();
		$built_prompt  = Prompt::build_user_with_diagnostics(
			$context,
			$prompt,
			$docs_guidance,
			$execution_contract
		);
		$user_prompt   = $built_prompt['prompt'];

		$result = ChatClient::chat(
			$system_prompt,
			$user_prompt,
			ResponseSchema::get( 'block' ),
			'flavor_agent_block'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$ranking_context = [
			'surface'           => 'block',
			'context'           => $context,
			'prompt'            => $prompt,
			'docsGrounding'     => DocsGuidanceResult::public_summary( $docs_result ),
			'executionContract' => $execution_contract,
		];

		$payload = Prompt::parse_response( $result, $ranking_context );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$pre_filtering_counts = [
			'settings' => count( $payload['settings'] ?? [] ),
			'styles'   => count( $payload['styles'] ?? [] ),
			'block'    => count( $payload['block'] ?? [] ),
		];

		$payload = Prompt::enforce_block_context_rules(
			$payload,
			$context['block'] ?? [],
			$execution_contract,
			$context['blockOperationContext'] ?? []
		);
		$payload = Prompt::rerank_payload( $payload, $ranking_context );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$payload['preFilteringCounts']       = $pre_filtering_counts;
		$payload['executionContract']        = $execution_contract;
		$payload['resolvedContextSignature'] = $resolved_context_signature;
		$payload['docsGrounding']            = DocsGuidanceResult::public_summary( $docs_result );
		$payload['docsGroundingFingerprint'] = $docs_grounding_fingerprint;
		// Only set on the path that actually built a prompt. The disabled-editing
		// short-circuit above returns before prompt assembly, so omitting the key
		// there reads as "no information" rather than "nothing was dropped".
		$payload['promptDiagnostics'] = [
			'droppedSections'     => $built_prompt['droppedSections'],
			'trimmedSections'     => $built_prompt['trimmedSections'],
			'themeTokenItemLimit' => $built_prompt['themeTokenItemLimit'],
		];

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

	public static function list_allowed_blocks( mixed $input ): array {
		$input              = self::normalize_map( $input );
		$search             = isset( $input['search'] ) && is_string( $input['search'] )
			? sanitize_text_field( $input['search'] )
			: '';
		$category           = isset( $input['category'] ) && is_string( $input['category'] )
			? sanitize_key( $input['category'] )
			: null;
		$limit              = NonNegativeInteger::normalize( $input['limit'] ?? null );
		$offset             = NonNegativeInteger::normalize( $input['offset'] ?? null ) ?? 0;
		$include_variations = filter_var(
			$input['includeVariations'] ?? false,
			FILTER_VALIDATE_BOOLEAN
		);
		$max_variations     = NonNegativeInteger::normalize( $input['maxVariations'] ?? null );

		return [
			'blocks' => ServerCollector::for_registered_blocks(
				$search,
				$category,
				$limit,
				$offset,
				$include_variations,
				$max_variations ?? 10
			),
			'total'  => ServerCollector::count_registered_blocks( $search, $category ),
		];
	}

	/**
	 * Normalize recommend-block inputs from either the first-party editorContext payload
	 * or the external-client selectedBlock payload into one canonical prompt context.
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

	/**
	 * @return array<int, int>
	 */
	private static function normalize_block_path( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$path = [];

		foreach ( $value as $segment ) {
			if ( ! is_numeric( $segment ) ) {
				continue;
			}

			$segment = (int) $segment;

			if ( $segment >= 0 ) {
				$path[] = $segment;
			}
		}

		return $path;
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

		$block_path = self::normalize_block_path( $block['blockPath'] ?? [] );
		if ( [] !== $block_path ) {
			$normalized['block']['blockPath'] = $block_path;
		}

		$title = is_string( $block['title'] ?? null ) ? sanitize_text_field( $block['title'] ) : '';
		if ( '' !== $title ) {
			$normalized['block']['title'] = $title;
		}

		// Trust boundary: on the first-party editorContext path the client
		// supplies the introspected inspectorPanels (and content/config
		// attributes below), which BlockRecommendationExecutionContract turns
		// into the capability whitelist that gates which suggestions survive.
		// This is safe because recommendations are advisory — the local apply
		// still runs through the block editor's real supports/lock enforcement,
		// and no governed write consumes a recommendation-supplied contract
		// (the external apply lanes re-derive context server-side, with no
		// filter seam). The external selectedBlock path re-introspects panels
		// server-side rather than trusting the client. See
		// docs/reference/governance-layer.md (recommendation trust boundary).
		if ( array_key_exists( 'inspectorPanels', $block ) ) {
			$inspector_panels                               = self::normalize_map( $block['inspectorPanels'] ?? [] );
			$normalized['block']['inspectorPanels']         = $inspector_panels;
			$normalized['block']['inspectorPanelsExplicit'] = true;
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

		$structural_ancestors = self::normalize_structural_ancestor_items( $context['structuralAncestors'] ?? [] );
		if ( ! empty( $structural_ancestors ) ) {
			$normalized['structuralAncestors'] = $structural_ancestors;
		}

		$structural_branch = self::normalize_structural_branch_items( $context['structuralBranch'] ?? [] );
		if ( ! empty( $structural_branch ) ) {
			$normalized['structuralBranch'] = $structural_branch;
		}

		$block_interior = self::normalize_block_interior_items( $context['blockInterior'] ?? [] );
		if ( ! empty( $block_interior ) ) {
			$normalized['blockInterior'] = $block_interior;
		}

		$theme_tokens = self::normalize_theme_tokens( $context['themeTokens'] ?? [] );
		if ( ! empty( $theme_tokens ) ) {
			$normalized['themeTokens'] = $theme_tokens;
		}

		$block_operation_context = self::normalize_block_operation_context( $context['blockOperationContext'] ?? [] );
		if ( ! empty( $block_operation_context ) ) {
			$normalized['blockOperationContext'] = $block_operation_context;
		}

		$design_semantics = DesignSemantics::normalize(
			$context['designSemantics'] ?? [],
			'block'
		);
		if ( ! empty( $design_semantics ) ) {
			$normalized['designSemantics'] = $design_semantics;
		}

		return $normalized;
	}

	private static function build_context_from_selected_block( mixed $raw_selected ): array|\WP_Error {
		$selected                 = self::normalize_selected_block( self::normalize_map( $raw_selected ) );
		$block_name               = is_string( $selected['blockName'] ?? null ) ? sanitize_text_field( $selected['blockName'] ) : '';
		$attributes               = self::normalize_map( $selected['attributes'] ?? [] );
		$inner_blocks             = self::normalize_list( $selected['innerBlocks'] ?? [] );
		$is_inside_content_only   = ! empty( $selected['isInsideContentOnly'] );
		$parent_context           = self::normalize_parent_context( $selected['parentContext'] ?? [] );
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
		$context['structuralAncestors']          = self::normalize_structural_ancestor_items( $selected['structuralAncestors'] ?? [] );
		$context['structuralBranch']             = self::normalize_structural_branch_items( $selected['structuralBranch'] ?? [] );
		// Derived server-side from innerBlocks on this path, never client-supplied.
		$context['blockInterior'] = self::normalize_block_interior_items( $context['blockInterior'] ?? [] );
		$context['themeTokens']   = self::normalize_theme_tokens( $context['themeTokens'] ?? [] );

		$structural_identity = self::normalize_map( $selected['structuralIdentity'] ?? [] );
		if ( ! empty( $structural_identity ) ) {
			$context['block']['structuralIdentity'] = $structural_identity;
		}

		return $context;
	}

	private static function normalize_selected_block( array $selected ): array {
		$selected   = self::normalize_map( $selected );
		$attributes = self::normalize_map( $selected['attributes'] ?? [] );
		$block_name = is_string( $selected['blockName'] ?? null )
			? sanitize_text_field( $selected['blockName'] )
			: ( is_string( $selected['name'] ?? null ) ? sanitize_text_field( $selected['name'] ) : '' );

		$selected['blockName']           = $block_name;
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
		$summaries  = array_slice( self::normalize_list( $raw_summaries ), 0, 3 );
		$normalized = [];

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

		$layout_constraints = self::normalize_layout_constraints( $parent['layoutConstraints'] ?? [] );
		if ( ! empty( $layout_constraints ) ) {
			$normalized['layoutConstraints'] = $layout_constraints;
		}

		return $normalized;
	}

	/**
	 * Whitelist a parent container's resolved layout constraints (contentSize /
	 * wideSize / orientation / column geometry) so the prompt can ground the
	 * "respect the parent's width constraints" rule. Mirrors the client-side
	 * getParentLayoutConstraints() key set; unknown keys are dropped.
	 *
	 * @return array<string, string|int|float|bool>
	 */
	private static function normalize_layout_constraints( mixed $raw_constraints ): array {
		$map = self::normalize_map( $raw_constraints );

		if ( empty( $map ) ) {
			return [];
		}

		$allowed = [
			'type',
			'contentSize',
			'wideSize',
			'orientation',
			'flexWrap',
			'justifyContent',
			'verticalAlignment',
			'columnCount',
			'minimumColumnWidth',
			'columnWidth',
		];

		$normalized = [];

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $map ) ) {
				continue;
			}

			$value = self::sanitize_scalar( $map[ $key ] );

			if ( null === $value || '' === $value ) {
				continue;
			}

			$normalized[ $key ] = $value;
		}

		return $normalized;
	}

	private static function normalize_structural_ancestor_items( mixed $raw_items ): array {
		$items = self::normalize_list( $raw_items );

		if ( count( $items ) > self::STRUCTURAL_SUMMARY_MAX_ITEMS ) {
			$items = array_slice( $items, -self::STRUCTURAL_SUMMARY_MAX_ITEMS );
		}

		return self::normalize_structural_summary_items( $items );
	}

	/**
	 * Normalize the selected block's own subtree.
	 *
	 * Placement fields are dropped: a descendant's template area is the selected
	 * block's own and is already stated in the structural-identity section, so
	 * repeating it here only creates a surface for the two to contradict.
	 */
	private static function normalize_block_interior_items( mixed $raw_items ): array {
		return self::normalize_structural_summary_items(
			array_slice(
				self::normalize_list( $raw_items ),
				0,
				self::BLOCK_INTERIOR_MAX_ITEMS
			),
			true,
			0,
			[
				'maxChildren' => self::BLOCK_INTERIOR_MAX_CHILDREN,
				'maxDepth'    => self::BLOCK_INTERIOR_MAX_DEPTH,
				'visualHints' => true,
				'placement'   => false,
			]
		);
	}

	private static function normalize_structural_branch_items( mixed $raw_items ): array {
		return self::normalize_structural_summary_items(
			array_slice(
				self::normalize_list( $raw_items ),
				0,
				self::STRUCTURAL_SUMMARY_MAX_ITEMS
			),
			true
		);
	}

	private static function normalize_block_operation_context( mixed $raw_context ): array {
		if ( ! self::block_structural_actions_enabled() ) {
			return [];
		}

		$context = self::normalize_map( $raw_context );

		if ( empty( $context ) ) {
			return [];
		}

		$target_client_id  = is_string( $context['targetClientId'] ?? null ) ? sanitize_text_field( $context['targetClientId'] ) : '';
		$target_block_name = is_string( $context['targetBlockName'] ?? null ) ? sanitize_text_field( $context['targetBlockName'] ) : '';
		$target_signature  = is_string( $context['targetSignature'] ?? null ) ? sanitize_text_field( $context['targetSignature'] ) : '';

		if ( '' === $target_client_id || '' === $target_block_name || '' === $target_signature ) {
			return [];
		}

		$allowed_patterns = [];
		$raw_patterns     = array_slice(
			self::normalize_list( $context['allowedPatterns'] ?? [] ),
			0,
			self::BLOCK_OPERATION_CONTEXT_MAX_PATTERNS
		);

		foreach ( $raw_patterns as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}

			$name = is_string( $pattern['name'] ?? null ) ? sanitize_text_field( $pattern['name'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$allowed_actions = array_values(
				array_intersect(
					StringArray::sanitize( $pattern['allowedActions'] ?? [] ),
					self::BLOCK_OPERATION_CONTEXT_ALLOWED_ACTIONS
				)
			);

			if ( empty( $allowed_actions ) ) {
				continue;
			}

			$source = is_string( $pattern['source'] ?? null ) ? sanitize_key( $pattern['source'] ) : '';

			if ( ! in_array( $source, self::BLOCK_OPERATION_CONTEXT_ALLOWED_SOURCES, true ) ) {
				$source = 'theme';
			}

			$allowed_patterns[] = [
				'name'           => $name,
				'title'          => is_string( $pattern['title'] ?? null ) ? sanitize_text_field( $pattern['title'] ) : '',
				'source'         => $source,
				'categories'     => StringArray::sanitize( $pattern['categories'] ?? [] ),
				'blockTypes'     => StringArray::sanitize( $pattern['blockTypes'] ?? [] ),
				'allowedActions' => $allowed_actions,
			];
		}

		return [
			'targetClientId'  => $target_client_id,
			'targetBlockName' => $target_block_name,
			'targetSignature' => $target_signature,
			'isTargetLocked'  => ! empty( $context['isTargetLocked'] ),
			'isContentOnly'   => ! empty( $context['isContentOnly'] ) || ! empty( $context['isInsideContentOnly'] ),
			'editingMode'     => self::normalize_editing_mode( $context['editingMode'] ?? 'default' ),
			'allowedPatterns' => $allowed_patterns,
		];
	}

	private static function block_structural_actions_enabled(): bool {
		return function_exists( '\\flavor_agent_block_structural_actions_enabled' )
			&& \flavor_agent_block_structural_actions_enabled();
	}

	/**
	 * @param array{maxChildren?: int, maxDepth?: int, visualHints?: bool, placement?: bool} $options
	 */
	private static function normalize_structural_summary_items( array $items, bool $include_children = false, int $depth = 0, array $options = [] ): array {
		$max_children = (int) ( $options['maxChildren'] ?? self::STRUCTURAL_SUMMARY_MAX_CHILDREN );
		// Expressed as "node levels", matching the JS summarizeTree guard exactly
		// so the two caps can be compared literally. The default reproduces the
		// historical `$depth < STRUCTURAL_SUMMARY_MAX_DEPTH` behaviour.
		$max_depth      = (int) ( $options['maxDepth'] ?? ( self::STRUCTURAL_SUMMARY_MAX_DEPTH + 1 ) );
		$with_hints     = ! empty( $options['visualHints'] );
		$with_placement = ! array_key_exists( 'placement', $options ) || ! empty( $options['placement'] );
		$normalized     = [];

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

			if ( $with_placement ) {
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
			}

			if ( $with_hints ) {
				$hints = self::normalize_visual_hints( $item['visualHints'] ?? [] );

				if ( ! empty( $hints ) ) {
					$summary['visualHints'] = $hints;
				}
			}

			if ( isset( $item['childCount'] ) && ( is_int( $item['childCount'] ) || is_numeric( $item['childCount'] ) ) ) {
				$summary['childCount'] = (int) $item['childCount'];
			}

			if ( isset( $item['moreChildren'] ) && ( is_int( $item['moreChildren'] ) || is_numeric( $item['moreChildren'] ) ) ) {
				$summary['moreChildren'] = (int) $item['moreChildren'];
			}

			if ( $include_children ) {
				$raw_children       = self::normalize_list( $item['children'] ?? [] );
				$hidden_child_count = max( 0, (int) ( $summary['moreChildren'] ?? 0 ) );
				$displayed_children = [];

				if ( $depth + 1 < $max_depth ) {
					$visible_children    = array_slice( $raw_children, 0, $max_children );
					$hidden_child_count += max( 0, count( $raw_children ) - count( $visible_children ) );
					$displayed_children  = self::normalize_structural_summary_items( $visible_children, true, $depth + 1, $options );
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

			if ( $with_placement && isset( $item['isSelected'] ) ) {
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
	 * @return array<string, mixed>
	 */
	private static function collect_wordpress_docs_guidance_result( array $context, string $prompt, array $options = [] ): array {
		return CollectsDocsGuidance::collect_result(
			static fn( array $request_context, string $request_prompt ): string => self::build_wordpress_docs_query( $request_context, $request_prompt ),
			$context,
			$prompt,
			[ 'mode' => empty( $options['signatureOnly'] ) ? 'recommendation' : 'signature' ]
		);
	}

	private static function normalize_docs_scope_token( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return sanitize_key( str_replace( '/', '-', $value ) );
	}

	private static function collect_unique_docs_scope_tokens( array $values, int $limit = self::DOCS_SCOPE_MAX_ITEMS ): array {
		$tokens = [];

		foreach ( $values as $value ) {
			$token = self::normalize_docs_scope_token( $value );

			if ( '' === $token || in_array( $token, $tokens, true ) ) {
				continue;
			}

			$tokens[] = $token;

			if ( count( $tokens ) >= $limit ) {
				break;
			}
		}

		return $tokens;
	}

	private static function classify_docs_background_tone( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$normalized = strtolower( sanitize_text_field( $value ) );

		if ( '' === $normalized ) {
			return '';
		}

		foreach ( [ 'contrast', 'dark', 'black', 'foreground' ] as $needle ) {
			if ( str_contains( $normalized, $needle ) ) {
				return 'contrast';
			}
		}

		return 'custom';
	}

	private static function get_parent_docs_scope_summary( array $context ): array {
		$parent_context = self::normalize_parent_context( $context['parentContext'] ?? [] );

		if ( empty( $parent_context ) ) {
			return [];
		}

		$visual_hints    = self::normalize_map( $parent_context['visualHints'] ?? [] );
		$summary         = [];
		$parent_role     = self::normalize_docs_scope_token( $parent_context['role'] ?? '' );
		$parent_tag      = self::normalize_docs_scope_token( $visual_hints['tagName'] ?? '' );
		$parent_layout   = self::normalize_docs_scope_token(
			self::get_value_from_path( $visual_hints, [ 'layout', 'type' ] )
		);
		$background_tone = self::classify_docs_background_tone(
			$visual_hints['backgroundColor']
			?? self::get_value_from_path( $visual_hints, [ 'style', 'color', 'background' ] )
			?? $visual_hints['gradient']
			?? null
		);
		$dim_ratio       = $visual_hints['dimRatio'] ?? null;

		if ( '' !== $parent_role ) {
			$summary['parentRole'] = $parent_role;
		}

		if ( '' !== $parent_tag ) {
			$summary['parentTag'] = $parent_tag;
		}

		if ( '' !== $parent_layout ) {
			$summary['parentLayout'] = $parent_layout;
		}

		if ( '' !== $background_tone ) {
			$summary['parentBackgroundTone'] = $background_tone;
		}

		if ( ( is_int( $dim_ratio ) || is_float( $dim_ratio ) || is_numeric( $dim_ratio ) ) && (float) $dim_ratio > 0 ) {
			$summary['parentHasOverlay'] = true;
		}

		return $summary;
	}

	private static function get_sibling_docs_scope_summary( array $context ): array {
		$summaries = array_merge(
			self::normalize_sibling_summaries( $context['siblingSummariesBefore'] ?? [] ),
			self::normalize_sibling_summaries( $context['siblingSummariesAfter'] ?? [] )
		);

		if ( empty( $summaries ) ) {
			return [];
		}

		$roles      = [];
		$alignments = [];

		foreach ( $summaries as $summary ) {
			$role = self::normalize_docs_scope_token( $summary['role'] ?? '' );

			if ( '' === $role ) {
				$role = self::normalize_docs_scope_token( $summary['block'] ?? '' );
			}

			if ( '' !== $role ) {
				$roles[] = $role;
			}

			$visual_hints = self::normalize_map( $summary['visualHints'] ?? [] );
			$alignment    = self::normalize_docs_scope_token(
				$visual_hints['align'] ?? $visual_hints['textAlign'] ?? ''
			);

			if ( '' !== $alignment ) {
				$alignments[] = $alignment;
			}
		}

		$summary    = [];
		$roles      = self::collect_unique_docs_scope_tokens( $roles );
		$alignments = self::collect_unique_docs_scope_tokens( $alignments );

		if ( ! empty( $roles ) ) {
			$summary['siblingRoles'] = $roles;
		}

		if ( ! empty( $alignments ) ) {
			$summary['siblingAlignments'] = $alignments;
		}

		return $summary;
	}

	private static function get_ancestor_scope_token( array $ancestor ): string {
		$role = self::normalize_docs_scope_token( $ancestor['role'] ?? '' );

		if ( '' !== $role ) {
			return $role;
		}

		$template_area = self::normalize_docs_scope_token( $ancestor['templateArea'] ?? '' );

		if ( '' !== $template_area ) {
			return $template_area . '-area';
		}

		$template_part = self::normalize_docs_scope_token( $ancestor['templatePartSlug'] ?? '' );

		if ( '' !== $template_part ) {
			return $template_part;
		}

		return self::normalize_docs_scope_token( $ancestor['block'] ?? '' );
	}

	private static function get_ancestor_docs_scope_summary( array $context ): array {
		$ancestors = self::normalize_structural_ancestor_items( $context['structuralAncestors'] ?? [] );

		if ( empty( $ancestors ) ) {
			return [];
		}

		$scope = [];

		foreach ( array_slice( $ancestors, -self::DOCS_SCOPE_MAX_ITEMS ) as $ancestor ) {
			$token = self::get_ancestor_scope_token( $ancestor );

			if ( '' !== $token ) {
				$scope[] = $token;
			}
		}

		$scope = self::collect_unique_docs_scope_tokens( $scope );

		return ! empty( $scope )
			? [ 'ancestorScopes' => $scope ]
			: [];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_block_docs_scope_summary( array $context ): array {
		$block           = self::normalize_map( $context['block'] ?? [] );
		$identity        = self::normalize_map( $block['structuralIdentity'] ?? [] );
		$structural_role = self::normalize_docs_scope_token( $identity['role'] ?? '' );
		$location        = self::normalize_docs_scope_token( $identity['location'] ?? '' );
		$template_area   = self::normalize_docs_scope_token( $identity['templateArea'] ?? '' );
		$summary         = [];

		if ( '' !== $structural_role ) {
			$summary['structuralRole'] = $structural_role;
		}

		if ( '' !== $location ) {
			$summary['location'] = $location;
		}

		if ( '' !== $template_area ) {
			$summary['templateArea'] = $template_area;
		}

		return array_merge(
			$summary,
			self::get_parent_docs_scope_summary( $context ),
			self::get_sibling_docs_scope_summary( $context ),
			self::get_ancestor_docs_scope_summary( $context )
		);
	}

	private static function build_wordpress_docs_query( array $context, string $prompt ): string {
		$block         = self::normalize_map( $context['block'] ?? [] );
		$block_name    = is_string( $block['name'] ?? null ) ? sanitize_text_field( $block['name'] ) : '';
		$block_title   = is_string( $block['title'] ?? null ) ? sanitize_text_field( $block['title'] ) : '';
		$scope_summary = self::build_block_docs_scope_summary( $context );
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

		if ( ! empty( $scope_summary['structuralRole'] ) ) {
			$parts[] = 'structural role ' . $scope_summary['structuralRole'];
		}

		if ( ! empty( $scope_summary['location'] ) ) {
			$parts[] = 'page location ' . $scope_summary['location'];
		}

		if ( ! empty( $scope_summary['templateArea'] ) ) {
			$parts[] = 'template area ' . $scope_summary['templateArea'];
		}

		if ( ! empty( $scope_summary['parentRole'] ) ) {
			$parts[] = 'parent container role ' . $scope_summary['parentRole'];
		}

		if ( ! empty( $scope_summary['parentTag'] ) ) {
			$parts[] = 'parent tag ' . $scope_summary['parentTag'];
		}

		if ( ! empty( $scope_summary['parentLayout'] ) ) {
			$parts[] = 'parent layout ' . $scope_summary['parentLayout'];
		}

		if ( ! empty( $scope_summary['parentBackgroundTone'] ) ) {
			$parts[] = 'parent background ' . $scope_summary['parentBackgroundTone'];
		}

		if ( ! empty( $scope_summary['parentHasOverlay'] ) ) {
			$parts[] = 'parent overlay';
		}

		if ( ! empty( $scope_summary['siblingRoles'] ) ) {
			$parts[] = 'nearby sibling roles ' . implode( ', ', $scope_summary['siblingRoles'] );
		}

		if ( ! empty( $scope_summary['siblingAlignments'] ) ) {
			$parts[] = 'nearby sibling alignments ' . implode( ', ', $scope_summary['siblingAlignments'] );
		}

		if ( ! empty( $scope_summary['ancestorScopes'] ) ) {
			$parts[] = 'nearest ancestors ' . implode( ', ', $scope_summary['ancestorScopes'] );
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
