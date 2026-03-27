<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\TemplatePrompt;
use FlavorAgent\LLM\TemplatePartPrompt;
use FlavorAgent\Support\StringArray;

final class TemplateAbilities {

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
		$input = self::normalize_input( $input );

		$template_ref          = isset( $input['templateRef'] )
			? trim( (string) $input['templateRef'] )
			: '';
		$template_type         = isset( $input['templateType'] ) && is_string( $input['templateType'] ) && $input['templateType'] !== ''
			? $input['templateType']
			: null;
		$prompt                = isset( $input['prompt'] ) ? (string) $input['prompt'] : '';
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

		$editor_slots = self::normalize_editor_slots( $input['editorSlots'] ?? null );

		if ( array_key_exists( 'assignedParts', $editor_slots ) ) {
			$context['assignedParts'] = $editor_slots['assignedParts'];
		}

		if ( array_key_exists( 'emptyAreas', $editor_slots ) ) {
			$context['emptyAreas'] = $editor_slots['emptyAreas'];
		}

		if ( array_key_exists( 'allowedAreas', $editor_slots ) ) {
			$context['allowedAreas'] = $editor_slots['allowedAreas'];
		}

		$system = TemplatePrompt::build_system();
		$user   = TemplatePrompt::build_user(
			$context,
			$prompt,
			self::collect_wordpress_docs_guidance( $context, $prompt )
		);
		$result = ResponsesClient::rank( $system, $user );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return TemplatePrompt::parse_response( $result, $context );
	}

	/**
	 * Recommend composition improvements for a single template part.
	 *
	 * @param array $input { templatePartRef: string, prompt?: string, visiblePatternNames?: string[] }
	 * @return array|\WP_Error Suggestions payload or error.
	 */
	public static function recommend_template_part( mixed $input ): array|\WP_Error {
		$input = self::normalize_input( $input );

		$template_part_ref     = isset( $input['templatePartRef'] )
			? trim( (string) $input['templatePartRef'] )
			: '';
		$prompt                = isset( $input['prompt'] ) ? (string) $input['prompt'] : '';
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

		$system = TemplatePartPrompt::build_system();
		$user   = TemplatePartPrompt::build_user(
			$context,
			$prompt,
			self::collect_template_part_wordpress_docs_guidance( $context, $prompt )
		);
		$result = ResponsesClient::rank( $system, $user );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return TemplatePartPrompt::parse_response( $result, $context );
	}

	/**
	 * Normalize Abilities API object inputs to the array shape used internally.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>
	 */
	private static function normalize_input( mixed $input ): array {
		if ( is_object( $input ) ) {
			$input = get_object_vars( $input );
		}

		return is_array( $input ) ? $input : [];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_editor_slots( mixed $input ): array {
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
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_wordpress_docs_guidance( array $context, string $prompt ): array {
		$query          = self::build_wordpress_docs_query( $context, $prompt );
		$entity_key     = self::build_wordpress_docs_entity_key( $context );
		$family_context = self::build_wordpress_docs_family_context( $context );

		return AISearchClient::maybe_search_with_cache_fallbacks( $query, $entity_key, $family_context );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_template_part_wordpress_docs_guidance( array $context, string $prompt ): array {
		$query          = self::build_template_part_wordpress_docs_query( $context, $prompt );
		$entity_key     = AISearchClient::resolve_entity_key( 'core/template-part', $query );
		$family_context = self::build_template_part_wordpress_docs_family_context( $context );

		return AISearchClient::maybe_search_with_cache_fallbacks( $query, $entity_key, $family_context );
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
		$template_type = isset( $context['templateType'] ) && is_string( $context['templateType'] )
			? sanitize_key( $context['templateType'] )
			: '';
		$allowed_areas = StringArray::sanitize( $context['allowedAreas'] ?? [] );
		$empty_areas   = StringArray::sanitize( $context['emptyAreas'] ?? [] );
		$assigned      = [];

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
		$area         = isset( $context['area'] ) && is_string( $context['area'] )
			? sanitize_key( $context['area'] )
			: '';
		$slug         = isset( $context['slug'] ) && is_string( $context['slug'] )
			? sanitize_key( $context['slug'] )
			: '';
		$top_level    = StringArray::sanitize( $context['topLevelBlocks'] ?? [] );
		$block_counts = is_array( $context['blockCounts'] ?? null ) ? $context['blockCounts'] : [];
		$parts        = [ 'WordPress block theme template part best practices' ];

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

		$allowed_areas  = StringArray::sanitize( $context['allowedAreas'] ?? [] );
		$empty_areas    = StringArray::sanitize( $context['emptyAreas'] ?? [] );
		$assigned_areas = [];

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

		return $family_context;
	}
}
