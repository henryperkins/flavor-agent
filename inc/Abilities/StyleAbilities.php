<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\ResponseSchema;
use FlavorAgent\LLM\StylePrompt;
use FlavorAgent\Support\CollectsDocsGuidance;
use FlavorAgent\Support\RecommendationResolvedSignature;
use FlavorAgent\Support\RecommendationReviewSignature;

final class StyleAbilities {

	private const SURFACE_GLOBAL_STYLES     = 'global-styles';
	private const SURFACE_STYLE_BOOK        = 'style-book';
	private const BLOCK_STYLE_SUPPORT_PATHS = [
		[
			'path'         => [ 'color', 'background' ],
			'valueSource'  => 'color',
			'supportPaths' => [ [ 'color', 'background' ] ],
		],
		[
			'path'         => [ 'color', 'text' ],
			'valueSource'  => 'color',
			'supportPaths' => [ [ 'color', 'text' ] ],
		],
		[
			'path'         => [ 'typography', 'fontSize' ],
			'valueSource'  => 'font-size',
			'supportPaths' => [ [ 'typography', 'fontSize' ] ],
		],
		[
			'path'         => [ 'typography', 'fontFamily' ],
			'valueSource'  => 'font-family',
			'supportPaths' => [ [ 'typography', 'fontFamily' ], [ 'typography', '__experimentalFontFamily' ] ],
		],
		[
			'path'         => [ 'typography', 'lineHeight' ],
			'valueSource'  => 'freeform',
			'supportPaths' => [ [ 'typography', 'lineHeight' ] ],
		],
		[
			'path'         => [ 'spacing', 'blockGap' ],
			'valueSource'  => 'spacing',
			'supportPaths' => [ [ 'spacing', 'blockGap' ] ],
		],
		[
			'path'         => [ 'border', 'color' ],
			'valueSource'  => 'color',
			'supportPaths' => [ [ 'border', 'color' ] ],
		],
		[
			'path'         => [ 'border', 'radius' ],
			'valueSource'  => 'freeform',
			'supportPaths' => [ [ 'border', 'radius' ] ],
		],
		[
			'path'         => [ 'border', 'style' ],
			'valueSource'  => 'freeform',
			'supportPaths' => [ [ 'border', 'style' ] ],
		],
		[
			'path'         => [ 'border', 'width' ],
			'valueSource'  => 'freeform',
			'supportPaths' => [ [ 'border', 'width' ] ],
		],
		[
			'path'         => [ 'shadow' ],
			'valueSource'  => 'shadow',
			'supportPaths' => [ [ 'shadow' ] ],
		],
	];

	public static function recommend_style( mixed $input ): array|\WP_Error {
		$input                  = self::normalize_map( $input );
		$resolve_signature_only = filter_var(
			$input['resolveSignatureOnly'] ?? false,
			FILTER_VALIDATE_BOOLEAN
		);
		$scope                  = self::normalize_map( $input['scope'] ?? [] );
		$style_context          = self::normalize_map( $input['styleContext'] ?? [] );
		$prompt                 = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : '';

		$scope_surface = self::normalize_style_surface( (string) ( $scope['surface'] ?? '' ) );

		if ( '' === $scope_surface ) {
			return new \WP_Error(
				'invalid_style_scope',
				'Style recommendations require the global-styles or style-book surface scope.',
				[ 'status' => 400 ]
			);
		}

		$context = self::build_context_for_surface( $scope_surface, $scope, $style_context );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$resolved_context_signature = RecommendationResolvedSignature::from_payload(
			$scope_surface,
			[
				'context' => $context,
				'prompt'  => $prompt,
			]
		);

		$review_context_signature = self::build_review_context_signature(
			$scope_surface,
			$context
		);

		if ( $resolve_signature_only ) {
			return [
				'reviewContextSignature'   => $review_context_signature,
				'resolvedContextSignature' => $resolved_context_signature,
			];
		}

		$docs_guidance = self::collect_wordpress_docs_guidance( $context, $prompt );
		$system_prompt = StylePrompt::build_system();
		$user_prompt   = StylePrompt::build_user(
			$context,
			$prompt,
			$docs_guidance
		);

		$result = ResponsesClient::rank(
			$system_prompt,
			$user_prompt,
			null,
			ResponseSchema::get( 'style' ),
			'flavor_agent_style'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = StylePrompt::parse_response( $result, $context );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$payload['reviewContextSignature']   = $review_context_signature;
		$payload['resolvedContextSignature'] = $resolved_context_signature;

		return $payload;
	}

	/**
	 * @return array{scope: array<string, mixed>, styleContext: array<string, mixed>}|\WP_Error
	 */
	private static function build_context_for_surface( string $surface, array $scope, array $style_context ): array|\WP_Error {
		return match ( $surface ) {
			self::SURFACE_GLOBAL_STYLES => self::build_global_styles_context( $scope, $style_context ),
			self::SURFACE_STYLE_BOOK => self::build_style_book_context( $scope, $style_context ),
			default => new \WP_Error(
				'invalid_style_scope',
				'Style recommendations require the global-styles or style-book surface scope.',
				[ 'status' => 400 ]
			),
		};
	}

	/**
	 * @return array{scope: array<string, mixed>, styleContext: array<string, mixed>}|\WP_Error
	 */
	private static function build_global_styles_context( array $scope, array $style_context ): array|\WP_Error {
		$scope_key        = sanitize_text_field( (string) ( $scope['scopeKey'] ?? '' ) );
		$global_styles_id = sanitize_text_field( (string) ( $scope['globalStylesId'] ?? '' ) );

		if ( '' === $scope_key || '' === $global_styles_id ) {
			return new \WP_Error(
				'missing_style_scope',
				'Style recommendations require a resolved Global Styles scope and entity id.',
				[ 'status' => 400 ]
			);
		}

		return [
			'scope'        => [
				'surface'        => self::SURFACE_GLOBAL_STYLES,
				'scopeKey'       => $scope_key,
				'globalStylesId' => $global_styles_id,
				'postType'       => sanitize_text_field( (string) ( $scope['postType'] ?? 'global_styles' ) ),
				'entityId'       => sanitize_text_field( (string) ( $scope['entityId'] ?? $global_styles_id ) ),
				'entityKind'     => sanitize_text_field( (string) ( $scope['entityKind'] ?? 'root' ) ),
				'entityName'     => sanitize_text_field( (string) ( $scope['entityName'] ?? 'globalStyles' ) ),
				'stylesheet'     => sanitize_text_field( (string) ( $scope['stylesheet'] ?? '' ) ),
				'templateSlug'   => sanitize_text_field( (string) ( $scope['templateSlug'] ?? '' ) ),
				'templateType'   => sanitize_key( (string) ( $scope['templateType'] ?? '' ) ),
			],
			'styleContext' => self::build_shared_style_context( $style_context ),
		];
	}

	/**
	 * @return array{scope: array<string, mixed>, styleContext: array<string, mixed>}|\WP_Error
	 */
	private static function build_style_book_context( array $scope, array $style_context ): array|\WP_Error {
		$scope_key         = sanitize_text_field( (string) ( $scope['scopeKey'] ?? '' ) );
		$global_styles_id  = sanitize_text_field( (string) ( $scope['globalStylesId'] ?? '' ) );
		$style_book_target = self::normalize_style_book_target( $style_context['styleBookTarget'] ?? [] );
		$block_manifest    = null;
		$block_name        = sanitize_text_field(
			(string) ( $scope['blockName'] ?? ( $style_book_target['blockName'] ?? '' ) )
		);

		if ( '' === $scope_key || '' === $global_styles_id ) {
			return new \WP_Error(
				'missing_style_scope',
				'Style recommendations require a resolved Style Book scope and Global Styles entity id.',
				[ 'status' => 400 ]
			);
		}

		if ( '' === $block_name ) {
			return new \WP_Error(
				'missing_style_scope',
				'Style Book recommendations require a target block name.',
				[ 'status' => 400 ]
			);
		}

		$block_manifest = ServerCollector::introspect_block_type( $block_name );

		if ( ! is_array( $block_manifest ) ) {
			return new \WP_Error(
				'invalid_style_scope',
				'Style Book recommendations require a registered target block.',
				[ 'status' => 400 ]
			);
		}

		$block_title = sanitize_text_field(
			(string) ( $scope['blockTitle'] ?? ( $style_book_target['blockTitle'] ?? ( $block_manifest['title'] ?? '' ) ) )
		);

		$style_context_payload = self::build_shared_style_context( $style_context, $block_manifest, false );

		if ( [] !== $style_book_target ) {
			$style_context_payload['styleBookTarget'] = $style_book_target;
		}

		return [
			'scope'        => [
				'surface'        => self::SURFACE_STYLE_BOOK,
				'scopeKey'       => $scope_key,
				'globalStylesId' => $global_styles_id,
				'postType'       => sanitize_text_field( (string) ( $scope['postType'] ?? 'global_styles' ) ),
				'entityId'       => sanitize_text_field( (string) ( $scope['entityId'] ?? $block_name ) ),
				'entityKind'     => sanitize_text_field( (string) ( $scope['entityKind'] ?? 'block' ) ),
				'entityName'     => sanitize_text_field( (string) ( $scope['entityName'] ?? 'styleBook' ) ),
				'stylesheet'     => sanitize_text_field( (string) ( $scope['stylesheet'] ?? '' ) ),
				'templateSlug'   => sanitize_text_field( (string) ( $scope['templateSlug'] ?? '' ) ),
				'templateType'   => sanitize_key( (string) ( $scope['templateType'] ?? '' ) ),
				'blockName'      => $block_name,
				'blockTitle'     => $block_title,
			],
			'styleContext' => $style_context_payload,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_shared_style_context( array $style_context, ?array $block_manifest = null, bool $include_variations = true ): array {
		$current_config        = self::normalize_style_config( $style_context['currentConfig'] ?? [] );
		$merged_config         = self::normalize_style_config( $style_context['mergedConfig'] ?? [] );
		$supported_style_paths = is_array( $block_manifest )
			? self::supported_block_style_paths_from_manifest( $block_manifest )
			: self::supported_style_paths();
		$template_structure    = self::normalize_template_structure( $style_context['templateStructure'] ?? [] );
		$template_visibility   = self::normalize_template_visibility( $style_context['templateVisibility'] ?? [] );
		$design_semantics      = self::normalize_design_semantics( $style_context['designSemantics'] ?? [] );
		$context               = [
			'currentConfig'         => $current_config,
			'mergedConfig'          => $merged_config,
			'themeTokenDiagnostics' => self::normalize_map( $style_context['themeTokenDiagnostics'] ?? [] ),
			'themeTokens'           => ServerCollector::for_tokens(),
			'supportedStylePaths'   => $supported_style_paths,
		];

		if ( $include_variations ) {
			$available_variations            = array_values(
				array_filter(
					array_map( [ self::class, 'normalize_variation' ], self::normalize_list( $style_context['availableVariations'] ?? [] ) ),
					static fn( array $variation ): bool => [] !== $variation
				)
			);
			$active_variation                = self::resolve_active_variation( $current_config['styles'], $available_variations );
			$context['availableVariations']  = $available_variations;
			$context['activeVariationIndex'] = $active_variation['index'] ?? null;
			$context['activeVariationTitle'] = $active_variation['title'] ?? '';
		}

		if ( [] !== $template_structure ) {
			$context['templateStructure'] = $template_structure;
		}

		if ( [] !== $template_visibility ) {
			$context['templateVisibility'] = $template_visibility;
		}

		if ( [] !== $design_semantics ) {
			$context['designSemantics'] = $design_semantics;
		}

		$block_manifest_context = self::normalize_block_manifest_context( $block_manifest );

		if ( [] !== $block_manifest_context ) {
			$context['blockManifest'] = $block_manifest_context;
		}

		return $context;
	}

	private static function build_review_context_signature( string $surface, array $context ): string {
		$style_context = self::normalize_map( $context['styleContext'] ?? [] );
		// Review freshness hashes only server-owned context so docs cache churn does not mark stored results stale.
		$payload = [
			'themeTokens' => self::normalize_review_theme_tokens(
				self::normalize_map( $style_context['themeTokens'] ?? [] )
			),
		];

		if ( self::SURFACE_STYLE_BOOK === $surface ) {
			$payload['blockManifest'] = self::normalize_review_block_manifest(
				self::normalize_map( $style_context['blockManifest'] ?? [] )
			);
		}

		return RecommendationReviewSignature::from_payload( $surface, $payload );
	}

	/**
	 * @param array<string, mixed> $theme_tokens
	 * @return array<string, mixed>
	 */
	private static function normalize_review_theme_tokens( array $theme_tokens ): array {
		$normalized = [];

		foreach ( [ 'colors', 'gradients', 'fontSizes', 'fontFamilies', 'spacing', 'shadows', 'duotone' ] as $key ) {
			$values = array_values(
				array_filter(
					array_map(
						static fn( mixed $value ): string => is_string( $value )
							? sanitize_text_field( $value )
							: '',
						self::normalize_list( $theme_tokens[ $key ] ?? [] )
					),
					static fn( string $value ): bool => '' !== $value
				)
			);

			if ( [] !== $values ) {
				$normalized[ $key ] = $values;
			}
		}

		foreach ( [ 'diagnostics', 'layout', 'enabledFeatures', 'elementStyles', 'blockPseudoStyles' ] as $key ) {
			$value = self::sanitize_structured_context_value( $theme_tokens[ $key ] ?? [] );

			if ( is_array( $value ) && [] !== $value ) {
				$normalized[ $key ] = $value;
			}
		}

		$duotone_presets = self::sanitize_structured_context_value( $theme_tokens['duotonePresets'] ?? [] );
		if ( is_array( $duotone_presets ) && [] !== $duotone_presets ) {
			$normalized['duotonePresets'] = $duotone_presets;
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $block_manifest
	 * @return array<string, mixed>
	 */
	private static function normalize_review_block_manifest( array $block_manifest ): array {
		$normalized = [];

		foreach ( [ 'supports', 'inspectorPanels' ] as $key ) {
			$value = self::sanitize_structured_context_value( $block_manifest[ $key ] ?? [] );

			if ( is_array( $value ) && [] !== $value ) {
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
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

	private static function build_wordpress_docs_query( array $context, string $prompt ): string {
		$scope                 = self::normalize_map( $context['scope'] ?? [] );
		$style_context         = self::normalize_map( $context['styleContext'] ?? [] );
		$surface               = self::normalize_style_surface( (string) ( $scope['surface'] ?? self::SURFACE_GLOBAL_STYLES ) );
		$style_book_target     = self::normalize_map( $style_context['styleBookTarget'] ?? [] );
		$template_structure    = is_array( $style_context['templateStructure'] ?? null ) ? $style_context['templateStructure'] : [];
		$template_visibility   = self::normalize_map( $style_context['templateVisibility'] ?? [] );
		$design_semantics      = self::normalize_map( $style_context['designSemantics'] ?? [] );
		$supported_path_labels = [];
		$top_level_blocks      = array_values(
			array_filter(
				array_map(
					static fn( mixed $node ): string => is_array( $node ) && is_string( $node['name'] ?? null )
						? sanitize_text_field( $node['name'] )
						: '',
					array_slice( $template_structure, 0, 6 )
				)
			)
		);
		$structure_block_count = self::count_template_structure_nodes( $template_structure );

		foreach ( self::normalize_list( $style_context['supportedStylePaths'] ?? [] ) as $path_entry ) {
			$path = is_array( $path_entry['path'] ?? null ) ? implode( '.', $path_entry['path'] ) : '';

			if ( $path !== '' ) {
				$supported_path_labels[] = $path;
			}
		}

		$supported_path_labels = array_values( array_unique( $supported_path_labels ) );
		$parts                 = [
			'WordPress Global Styles and theme.json best practices',
			'preset families and supported style path guidance',
		];

		if ( self::SURFACE_STYLE_BOOK === $surface ) {
			$parts[] = 'WordPress Style Book block styling guidance';

			$block_name  = sanitize_text_field(
				(string) ( $scope['blockName'] ?? ( $style_book_target['blockName'] ?? '' ) )
			);
			$block_title = sanitize_text_field(
				(string) ( $scope['blockTitle'] ?? ( $style_book_target['blockTitle'] ?? '' ) )
			);
			$description = sanitize_text_field( (string) ( $style_book_target['description'] ?? '' ) );

			if ( $block_name !== '' ) {
				$parts[] = "block type {$block_name}";
			}

			if ( $block_title !== '' ) {
				$parts[] = "block title {$block_title}";
			}

			if ( $description !== '' ) {
				$parts[] = "block description {$description}";
			}
		}

		if ( [] !== $supported_path_labels ) {
			$parts[] = 'supported style paths ' . implode( ', ', $supported_path_labels );
		}

		if ( ! empty( $top_level_blocks ) ) {
			$parts[] = 'template structure ' . implode( ', ', $top_level_blocks );
		}

		if ( $structure_block_count > 0 ) {
			$parts[] = 'live template block count ' . $structure_block_count;
		}

		if ( ! empty( $template_visibility['blockCount'] ) ) {
			$parts[] = 'viewport-constrained blocks ' . (int) $template_visibility['blockCount'];
		}

		if ( ! empty( $design_semantics['overallDensityHint'] ) && is_string( $design_semantics['overallDensityHint'] ) ) {
			$parts[] = 'overall density ' . sanitize_text_field( $design_semantics['overallDensityHint'] );
		}

		if ( ! empty( $design_semantics['dominantLocation'] ) && is_string( $design_semantics['dominantLocation'] ) ) {
			$parts[] = 'dominant location ' . sanitize_text_field( $design_semantics['dominantLocation'] );
		}

		if ( ! empty( $design_semantics['dominantRole'] ) && is_string( $design_semantics['dominantRole'] ) ) {
			$parts[] = 'dominant role ' . sanitize_text_field( $design_semantics['dominantRole'] );
		}

		$location_summary = self::summarize_design_location_summary( $design_semantics['locationSummary'] ?? [] );
		if ( '' !== $location_summary ) {
			$parts[] = 'template locations ' . $location_summary;
		}

		if ( $prompt !== '' ) {
			$parts[] = $prompt;
		}

		$parts[] = 'theme.json presets, Global Styles capabilities, Style Book controls, and editor standards';

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

	private static function build_wordpress_docs_entity_key( array $context ): string {
		$scope             = self::normalize_map( $context['scope'] ?? [] );
		$style_context     = self::normalize_map( $context['styleContext'] ?? [] );
		$style_book_target = self::normalize_map( $style_context['styleBookTarget'] ?? [] );
		$surface           = self::normalize_style_surface( (string) ( $scope['surface'] ?? self::SURFACE_GLOBAL_STYLES ) );
		$style_book_name   = sanitize_text_field(
			(string) ( $scope['blockName'] ?? ( $style_book_target['blockName'] ?? '' ) )
		);

		if ( self::SURFACE_STYLE_BOOK === $surface && $style_book_name !== '' ) {
			return AISearchClient::resolve_entity_key( $style_book_name );
		}

		if ( self::SURFACE_STYLE_BOOK === $surface ) {
			return AISearchClient::resolve_entity_key( 'guidance:style-book' );
		}

		return AISearchClient::resolve_entity_key( 'guidance:global-styles' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_wordpress_docs_family_context( array $context ): array {
		$scope               = self::normalize_map( $context['scope'] ?? [] );
		$style_context       = self::normalize_map( $context['styleContext'] ?? [] );
		$style_book_target   = self::normalize_map( $style_context['styleBookTarget'] ?? [] );
		$surface             = self::normalize_style_surface( (string) ( $scope['surface'] ?? self::SURFACE_GLOBAL_STYLES ) );
		$template_structure  = is_array( $style_context['templateStructure'] ?? null ) ? $style_context['templateStructure'] : [];
		$template_visibility = self::normalize_map( $style_context['templateVisibility'] ?? [] );
		$design_semantics    = self::normalize_map( $style_context['designSemantics'] ?? [] );
		$family_context      = [
			'surface'   => $surface !== '' ? $surface : self::SURFACE_GLOBAL_STYLES,
			'entityKey' => self::build_wordpress_docs_entity_key( $context ),
		];
		$supported_families  = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( mixed $path_entry ): string => is_array( $path_entry ) && is_string( $path_entry['valueSource'] ?? null )
							? sanitize_key( $path_entry['valueSource'] )
							: '',
						self::normalize_list( $style_context['supportedStylePaths'] ?? [] )
					)
				)
			)
		);

		if ( [] !== $supported_families ) {
			sort( $supported_families );
			$family_context['supportedPathFamilies'] = $supported_families;
		}

		if ( self::SURFACE_STYLE_BOOK === $surface ) {
			$block_name = sanitize_text_field(
				(string) ( $scope['blockName'] ?? ( $style_book_target['blockName'] ?? '' ) )
			);

			if ( $block_name !== '' ) {
				$family_context['blockName'] = $block_name;
			}
		}

		$template_type = sanitize_key( (string) ( $scope['templateType'] ?? '' ) );
		if ( '' !== $template_type ) {
			$family_context['templateType'] = $template_type;
		}

		$top_level_blocks = array_values(
			array_filter(
				array_map(
					static fn( mixed $node ): string => is_array( $node ) && is_string( $node['name'] ?? null )
						? sanitize_text_field( $node['name'] )
						: '',
					array_slice( $template_structure, 0, 6 )
				)
			)
		);
		if ( ! empty( $top_level_blocks ) ) {
			$family_context['topLevelBlocks'] = $top_level_blocks;
		}

		if ( ! empty( $template_visibility['blockCount'] ) ) {
			$family_context['visibilityConstraintCount'] = (int) $template_visibility['blockCount'];
		}

		if ( ! empty( $design_semantics['overallDensityHint'] ) && is_string( $design_semantics['overallDensityHint'] ) ) {
			$family_context['overallDensityHint'] = sanitize_text_field( $design_semantics['overallDensityHint'] );
		}

		if ( ! empty( $design_semantics['dominantLocation'] ) && is_string( $design_semantics['dominantLocation'] ) ) {
			$family_context['dominantLocation'] = sanitize_text_field( $design_semantics['dominantLocation'] );
		}

		if ( ! empty( $design_semantics['dominantRole'] ) && is_string( $design_semantics['dominantRole'] ) ) {
			$family_context['dominantRole'] = sanitize_text_field( $design_semantics['dominantRole'] );
		}

		if ( isset( $design_semantics['occurrenceCount'] ) && is_numeric( $design_semantics['occurrenceCount'] ) ) {
			$family_context['occurrenceCount'] = max( 0, (int) $design_semantics['occurrenceCount'] );
		}

		return $family_context;
	}

	private static function count_template_structure_nodes( array $nodes ): int {
		$count = 0;

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $node['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			++$count;
			$count += self::count_template_structure_nodes(
				is_array( $node['innerBlocks'] ?? null ) ? $node['innerBlocks'] : []
			);
		}

		return $count;
	}

	private static function summarize_design_location_summary( mixed $location_summary ): string {
		$summary = [];

		foreach ( array_slice( self::normalize_list( $location_summary ), 0, 3 ) as $entry ) {
			$entry = self::normalize_map( $entry );
			$value = sanitize_text_field( (string) ( $entry['value'] ?? '' ) );
			$count = isset( $entry['count'] ) && is_numeric( $entry['count'] )
				? max( 0, (int) $entry['count'] )
				: null;

			if ( '' === $value ) {
				continue;
			}

			$summary[] = null !== $count ? $value . ' x' . $count : $value;
		}

		return implode( ', ', $summary );
	}

	private static function normalize_style_surface( string $surface ): string {
		$surface = sanitize_key( $surface );

		return in_array(
			$surface,
			[
				self::SURFACE_GLOBAL_STYLES,
				self::SURFACE_STYLE_BOOK,
			],
			true
		)
			? $surface
			: '';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function supported_style_paths(): array {
		$theme_tokens = ServerCollector::for_tokens();
		$features     = is_array( $theme_tokens['enabledFeatures'] ?? null )
			? $theme_tokens['enabledFeatures']
			: [];
		$paths        = [];

		if ( ! empty( $theme_tokens['colors'] ) ) {
			if ( ! empty( $features['backgroundColor'] ) ) {
				$paths[] = self::supported_path( [ 'color', 'background' ], 'color' );
			}

			if ( ! empty( $features['textColor'] ) ) {
				$paths[] = self::supported_path( [ 'color', 'text' ], 'color' );
			}

			if ( ! empty( $features['linkColor'] ) ) {
				$paths[] = self::supported_path( [ 'elements', 'link', 'color', 'text' ], 'color' );
			}

			if ( ! empty( $features['buttonColor'] ) ) {
				$paths[] = self::supported_path( [ 'elements', 'button', 'color', 'background' ], 'color' );
				$paths[] = self::supported_path( [ 'elements', 'button', 'color', 'text' ], 'color' );
			}

			if ( ! empty( $features['headingColor'] ) ) {
				$paths[] = self::supported_path( [ 'elements', 'heading', 'color', 'text' ], 'color' );
			}
		}

		if ( ! empty( $theme_tokens['fontSizes'] ) ) {
			$paths[] = self::supported_path( [ 'typography', 'fontSize' ], 'font-size' );
		}

		if ( ! empty( $theme_tokens['fontFamilies'] ) ) {
			$paths[] = self::supported_path( [ 'typography', 'fontFamily' ], 'font-family' );
			$paths[] = self::supported_path( [ 'elements', 'heading', 'typography', 'fontFamily' ], 'font-family' );
		}

		if ( ! empty( $features['lineHeight'] ) ) {
			$paths[] = self::supported_path( [ 'typography', 'lineHeight' ], 'freeform' );
		}

		if ( ! empty( $features['blockGap'] ) && ! empty( $theme_tokens['spacing'] ) ) {
			$paths[] = self::supported_path( [ 'spacing', 'blockGap' ], 'spacing' );
		}

		if ( ! empty( $features['borderColor'] ) ) {
			$paths[] = self::supported_path( [ 'border', 'color' ], 'color' );
		}

		if ( ! empty( $features['borderRadius'] ) ) {
			$paths[] = self::supported_path( [ 'border', 'radius' ], 'freeform' );
		}

		if ( ! empty( $features['borderStyle'] ) ) {
			$paths[] = self::supported_path( [ 'border', 'style' ], 'freeform' );
		}

		if ( ! empty( $features['borderWidth'] ) ) {
			$paths[] = self::supported_path( [ 'border', 'width' ], 'freeform' );
		}

		if ( ! empty( $theme_tokens['shadows'] ) ) {
			$paths[] = self::supported_path( [ 'shadow' ], 'shadow' );
		}

		return array_values( $paths );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function supported_block_style_paths( string $block_name ): array {
		$block_manifest = ServerCollector::introspect_block_type( sanitize_text_field( $block_name ) );

		if ( ! is_array( $block_manifest ) ) {
			return [];
		}

		return self::supported_block_style_paths_from_manifest( $block_manifest );
	}

	/**
	 * @param array<string, mixed> $block_manifest
	 * @return array<int, array<string, mixed>>
	 */
	private static function supported_block_style_paths_from_manifest( array $block_manifest ): array {
		$theme_tokens = ServerCollector::for_tokens();
		$supports     = self::normalize_map( $block_manifest['supports'] ?? [] );
		$paths        = [];

		foreach ( self::BLOCK_STYLE_SUPPORT_PATHS as $spec ) {
			$path          = is_array( $spec['path'] ?? null ) ? $spec['path'] : [];
			$value_source  = isset( $spec['valueSource'] ) ? (string) $spec['valueSource'] : '';
			$support_paths = is_array( $spec['supportPaths'] ?? null ) ? $spec['supportPaths'] : [];

			if ( [] === $path || '' === $value_source ) {
				continue;
			}

			if ( ! self::block_theme_supports_path( $theme_tokens, $path, $value_source ) ) {
				continue;
			}

			if ( ! self::block_supports_style_path( $supports, $support_paths ) ) {
				continue;
			}

			$paths[] = self::supported_path( $path, $value_source );
		}

		return array_values( $paths );
	}

	/**
	 * @param array<string, mixed> $theme_tokens
	 * @param string[]             $path
	 */
	private static function block_theme_supports_path( array $theme_tokens, array $path, string $value_source ): bool {
		$features = is_array( $theme_tokens['enabledFeatures'] ?? null )
			? $theme_tokens['enabledFeatures']
			: [];
		$path_key = implode( '.', $path );

		return match ( $path_key ) {
			'color.background' => ! empty( $theme_tokens['colors'] ) && ! empty( $features['backgroundColor'] ),
			'color.text' => ! empty( $theme_tokens['colors'] ) && ! empty( $features['textColor'] ),
			'typography.fontSize' => ! empty( $theme_tokens['fontSizes'] ),
			'typography.fontFamily' => ! empty( $theme_tokens['fontFamilies'] ),
			'typography.lineHeight' => ! empty( $features['lineHeight'] ),
			'spacing.blockGap' => ! empty( $theme_tokens['spacing'] ) && ! empty( $features['blockGap'] ),
			'border.color' => ! empty( $theme_tokens['colors'] ) && ! empty( $features['borderColor'] ),
			'border.radius' => ! empty( $features['borderRadius'] ),
			'border.style' => ! empty( $features['borderStyle'] ),
			'border.width' => ! empty( $features['borderWidth'] ),
			'shadow' => ! empty( $theme_tokens['shadows'] ),
			default => 'freeform' === $value_source,
		};
	}

	/**
	 * @param array<string, mixed>               $supports
	 * @param array<int, array<int, string>>     $support_paths
	 */
	private static function block_supports_style_path( array $supports, array $support_paths ): bool {
		foreach ( $support_paths as $support_path ) {
			if ( ! is_array( $support_path ) || [] === $support_path ) {
				continue;
			}

			if ( self::is_truthy( self::read_support_path( $supports, $support_path ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $supports
	 * @param string[]             $path
	 */
	private static function read_support_path( array $supports, array $path ): mixed {
		$current = $supports;

		foreach ( $path as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}

			$current = $current[ $segment ];
		}

		return $current;
	}

	private static function is_truthy( mixed $value ): bool {
		if ( true === $value ) {
			return true;
		}

		if ( false === $value || null === $value ) {
			return false;
		}

		if ( is_array( $value ) ) {
			return [] !== $value;
		}

		return (bool) $value;
	}

	/**
	 * @param string[] $path
	 * @return array<string, mixed>
	 */
	private static function supported_path( array $path, string $value_source ): array {
		return [
			'path'        => $path,
			'valueSource' => $value_source,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_variation( mixed $variation ): array {
		$variation = self::normalize_map( $variation );
		$title     = sanitize_text_field( (string) ( $variation['title'] ?? '' ) );

		if ( '' === $title && [] === $variation ) {
			return [];
		}

		return [
			'title'       => $title,
			'description' => sanitize_text_field( (string) ( $variation['description'] ?? '' ) ),
			'settings'    => self::normalize_map( $variation['settings'] ?? [] ),
			'styles'      => self::normalize_map( $variation['styles'] ?? [] ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_block_manifest_context( ?array $block_manifest ): array {
		if ( ! is_array( $block_manifest ) ) {
			return [];
		}

		$supports         = self::normalize_map( $block_manifest['supports'] ?? [] );
		$inspector_panels = self::normalize_map( $block_manifest['inspectorPanels'] ?? [] );

		if ( [] === $supports && [] === $inspector_panels ) {
			return [];
		}

		return [
			'supports'        => $supports,
			'inspectorPanels' => $inspector_panels,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_style_book_target( mixed $target ): array {
		$target      = self::normalize_map( $target );
		$block_name  = sanitize_text_field( (string) ( $target['blockName'] ?? '' ) );
		$block_title = sanitize_text_field(
			(string) ( $target['blockTitle'] ?? $target['label'] ?? '' )
		);
		$description = sanitize_text_field( (string) ( $target['description'] ?? '' ) );
		$current     = self::normalize_map( $target['currentStyles'] ?? [] );
		$merged      = self::normalize_map( $target['mergedStyles'] ?? [] );

		if (
			'' === $block_name
			&& '' === $block_title
			&& '' === $description
			&& [] === $current
			&& [] === $merged
		) {
			return [];
		}

		return [
			'blockName'     => $block_name,
			'blockTitle'    => $block_title,
			'description'   => $description,
			'currentStyles' => $current,
			'mergedStyles'  => $merged,
		];
	}

	/**
	 * @return array{settings: array<string, mixed>, styles: array<string, mixed>}
	 */
	private static function normalize_style_config( mixed $value ): array {
		$value = self::normalize_map( $value );

		return [
			'settings' => self::normalize_map( $value['settings'] ?? [] ),
			'styles'   => self::normalize_map( $value['styles'] ?? [] ),
		];
	}

	/**
	 * @param array<string, mixed>               $current_styles
	 * @param array<int, array<string, mixed>>   $available_variations
	 * @return array{index?: int, title?: string}
	 */
	private static function resolve_active_variation( array $current_styles, array $available_variations ): array {
		$normalized_current = self::normalize_comparable_value( $current_styles );

		foreach ( $available_variations as $index => $variation ) {
			$variation_styles = self::normalize_map( $variation['styles'] ?? [] );

			if ( self::normalize_comparable_value( $variation_styles ) !== $normalized_current ) {
				continue;
			}

			return [
				'index' => $index,
				'title' => sanitize_text_field( (string) ( $variation['title'] ?? '' ) ),
			];
		}

		return [];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_template_structure( mixed $structure, int $depth = 0 ): array {
		$nodes      = self::normalize_list( $structure );
		$normalized = [];

		foreach ( $nodes as $node ) {
			$node = self::normalize_map( $node );
			$name = sanitize_text_field( (string) ( $node['name'] ?? '' ) );

			if ( $name === '' ) {
				continue;
			}

			$normalized_node = [ 'name' => $name ];

			if ( $depth < 1 ) {
				$children = self::normalize_template_structure( $node['innerBlocks'] ?? [], $depth + 1 );

				if ( [] !== $children ) {
					$normalized_node['innerBlocks'] = $children;
				}
			}

			$normalized[] = $normalized_node;
		}

		return $normalized;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_template_visibility( mixed $visibility ): array {
		$visibility = self::normalize_map( $visibility );
		$blocks     = [];

		foreach ( self::normalize_list( $visibility['blocks'] ?? [] ) as $block ) {
			$block = self::normalize_map( $block );
			$path  = [];

			foreach ( self::normalize_list( $block['path'] ?? [] ) as $segment ) {
				if ( is_int( $segment ) || is_numeric( $segment ) ) {
					$path[] = max( 0, (int) $segment );
				}
			}

			$name = sanitize_text_field( (string) ( $block['name'] ?? '' ) );

			if ( [] === $path || '' === $name ) {
				continue;
			}

			$blocks[] = [
				'path'             => $path,
				'name'             => $name,
				'label'            => sanitize_text_field( (string) ( $block['label'] ?? '' ) ),
				'hiddenViewports'  => array_values(
					array_unique(
						array_filter(
							array_map(
								static fn( mixed $viewport ): string => is_string( $viewport )
									? sanitize_key( $viewport )
									: '',
								self::normalize_list( $block['hiddenViewports'] ?? [] )
							),
							static fn( string $viewport ): bool => '' !== $viewport
						)
					)
				),
				'visibleViewports' => array_values(
					array_unique(
						array_filter(
							array_map(
								static fn( mixed $viewport ): string => is_string( $viewport )
									? sanitize_key( $viewport )
									: '',
								self::normalize_list( $block['visibleViewports'] ?? [] )
							),
							static fn( string $viewport ): bool => '' !== $viewport
						)
					)
				),
			];
		}

		return [
			'hasVisibilityRules' => ! empty( $visibility['hasVisibilityRules'] ) || [] !== $blocks,
			'blockCount'         => [] !== $blocks ? count( $blocks ) : max( 0, (int) ( $visibility['blockCount'] ?? 0 ) ),
			'blocks'             => $blocks,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_design_semantics( mixed $semantics ): array {
		$semantics = self::normalize_map( $semantics );

		if ( [] === $semantics ) {
			return [];
		}

		$normalized = self::sanitize_structured_context_value( $semantics );

		return is_array( $normalized ) ? $normalized : [];
	}

	/**
	 * @return array<string, mixed>|array<int, mixed>|bool|float|int|string|null
	 */
	private static function normalize_comparable_value( mixed $value ): array|bool|float|int|string|null {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			if ( self::is_list_array( $value ) ) {
				return array_map( [ self::class, 'normalize_comparable_value' ], $value );
			}

			$normalized = [];

			foreach ( $value as $key => $entry ) {
				$normalized[ (string) $key ] = self::normalize_comparable_value( $entry );
			}

			ksort( $normalized );

			return $normalized;
		}

		return $value;
	}

	/**
	 * @return array<string, mixed>|array<int, mixed>|bool|float|int|string|null
	 */
	private static function sanitize_structured_context_value( mixed $value ): array|bool|float|int|string|null {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			if ( self::is_list_array( $value ) ) {
				return array_values(
					array_map( [ self::class, 'sanitize_structured_context_value' ], $value )
				);
			}

			$sanitized = [];

			foreach ( $value as $key => $entry ) {
				$sanitized[ (string) $key ] =
					self::sanitize_structured_context_value( $entry );
			}

			return $sanitized;
		}

		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		return is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value
			? $value
			: null;
	}

	/**
	 * @param array<mixed> $value
	 */
	private static function is_list_array( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_map( mixed $value ): array {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		return is_array( $value ) ? $value : [];
	}

	/**
	 * @return array<int, mixed>
	 */
	private static function normalize_list( mixed $value ): array {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		return is_array( $value ) ? array_values( $value ) : [];
	}
}
