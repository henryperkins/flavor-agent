<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\Client;
use FlavorAgent\LLM\Prompt;
use FlavorAgent\Support\StringArray;

final class BlockAbilities {

	public static function recommend_block( mixed $input ): array|\WP_Error {
		$input = self::normalize_map( $input );

		$prepared = self::prepare_recommend_block_input( $input );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$context = $prepared['context'];
		$prompt  = $prepared['prompt'];

		$api_key = get_option( 'flavor_agent_api_key', '' );
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'missing_api_key', 'Configure your API key in Settings > Flavor Agent.', [ 'status' => 400 ] );
		}

		$system_prompt = Prompt::build_system();
		$user_prompt   = Prompt::build_user(
			$context,
			$prompt,
			self::collect_wordpress_docs_guidance( $context, $prompt )
		);

		$result = Client::chat( $system_prompt, $user_prompt, $api_key );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = Prompt::parse_response( $result );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		return Prompt::enforce_block_context_rules( $payload, $context['block'] ?? [] );
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

			$structural_ancestors = self::normalize_list( $context['structuralAncestors'] ?? [] );
		if ( ! empty( $structural_ancestors ) ) {
			$normalized['structuralAncestors'] = $structural_ancestors;
		}

			$structural_branch = self::normalize_list( $context['structuralBranch'] ?? [] );
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

		if ( '' === $block_name ) {
			return new \WP_Error( 'missing_block_name', 'selectedBlock.blockName is required.', [ 'status' => 400 ] );
		}

			$context                         = ServerCollector::for_block( $block_name, $attributes, $inner_blocks, $is_inside_content_only );
			$context['block']['editingMode'] = self::normalize_editing_mode( $selected['editingMode'] ?? $context['block']['editingMode'] ?? 'default' );
			$context['siblingsBefore']       = StringArray::sanitize( $context['siblingsBefore'] ?? [] );
			$context['siblingsAfter']        = StringArray::sanitize( $context['siblingsAfter'] ?? [] );
			$context['structuralAncestors']  = self::normalize_list( $selected['structuralAncestors'] ?? [] );
			$context['structuralBranch']     = self::normalize_list( $selected['structuralBranch'] ?? [] );
			$context['themeTokens']          = self::normalize_theme_tokens( $context['themeTokens'] ?? [] );

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

	private static function normalize_theme_tokens( mixed $raw_tokens ): array {
		$tokens = self::normalize_map( $raw_tokens );

		if ( empty( $tokens ) ) {
			return [];
		}

		return [
			'colors'            => StringArray::sanitize( $tokens['colors'] ?? [] ),
			'gradients'         => StringArray::sanitize( $tokens['gradients'] ?? [] ),
			'fontSizes'         => StringArray::sanitize( $tokens['fontSizes'] ?? [] ),
			'fontFamilies'      => StringArray::sanitize( $tokens['fontFamilies'] ?? [] ),
			'spacing'           => StringArray::sanitize( $tokens['spacing'] ?? [] ),
			'shadows'           => StringArray::sanitize( $tokens['shadows'] ?? [] ),
			'layout'            => self::normalize_map( $tokens['layout'] ?? [] ),
			'enabledFeatures'   => self::normalize_map( $tokens['enabledFeatures'] ?? [] ),
			'blockPseudoStyles' => self::normalize_map( $tokens['blockPseudoStyles'] ?? [] ),
		];
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

	private static function normalize_editing_mode( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return 'default';
		}

		$value = sanitize_key( $value );

		return '' === $value ? 'default' : $value;
	}
}
