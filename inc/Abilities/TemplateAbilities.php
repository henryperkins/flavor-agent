<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\TemplatePrompt;
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
	 * @param array $input { templateRef: string, templateType?: string, prompt?: string, visiblePatternNames?: string[] }
	 * @return array|\WP_Error Suggestions payload or error.
	 */
	public static function recommend_template( mixed $input ): array|\WP_Error {
		$input = self::normalize_input( $input );

		$template_ref  = isset( $input['templateRef'] )
			? trim( (string) $input['templateRef'] )
			: '';
		$template_type = isset( $input['templateType'] ) && is_string( $input['templateType'] ) && $input['templateType'] !== ''
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

		$context = ServerCollector::for_template(
			$template_ref,
			$template_type,
			$visible_pattern_names
		);
		if ( is_wp_error( $context ) ) {
			return $context;
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
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_wordpress_docs_guidance( array $context, string $prompt ): array {
		$query = self::build_wordpress_docs_query( $context, $prompt );

		if ( $query === '' ) {
			return [];
		}

		return AISearchClient::maybe_search( $query );
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
}
