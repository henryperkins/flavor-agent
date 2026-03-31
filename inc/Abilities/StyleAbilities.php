<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\StylePrompt;

final class StyleAbilities {

	public static function recommend_style( mixed $input ): array|\WP_Error {
		$input = self::normalize_map( $input );
		$scope = self::normalize_map( $input['scope'] ?? [] );
		$style_context = self::normalize_map( $input['styleContext'] ?? [] );
		$prompt = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : '';

		$scope_surface = sanitize_key( (string) ( $scope['surface'] ?? '' ) );
		$scope_key = sanitize_text_field( (string) ( $scope['scopeKey'] ?? '' ) );
		$global_styles_id = sanitize_text_field( (string) ( $scope['globalStylesId'] ?? '' ) );

		if ( $scope_surface !== 'global-styles' ) {
			return new \WP_Error(
				'invalid_style_scope',
				'Style recommendations require the global-styles surface scope.',
				[ 'status' => 400 ]
			);
		}

		if ( '' === $scope_key || '' === $global_styles_id ) {
			return new \WP_Error(
				'missing_style_scope',
				'Style recommendations require a resolved Global Styles scope and entity id.',
				[ 'status' => 400 ]
			);
		}

		$context = [
			'scope'        => [
				'surface'        => 'global-styles',
				'scopeKey'       => $scope_key,
				'globalStylesId' => $global_styles_id,
				'postType'       => sanitize_text_field( (string) ( $scope['postType'] ?? 'global_styles' ) ),
				'entityId'       => sanitize_text_field( (string) ( $scope['entityId'] ?? $global_styles_id ) ),
				'entityKind'     => sanitize_text_field( (string) ( $scope['entityKind'] ?? 'root' ) ),
				'entityName'     => sanitize_text_field( (string) ( $scope['entityName'] ?? 'globalStyles' ) ),
				'stylesheet'     => sanitize_text_field( (string) ( $scope['stylesheet'] ?? '' ) ),
			],
			'styleContext' => [
				'currentConfig'         => self::normalize_style_config( $style_context['currentConfig'] ?? [] ),
				'mergedConfig'          => self::normalize_style_config( $style_context['mergedConfig'] ?? [] ),
				'availableVariations'   => array_values(
					array_filter(
						array_map( [ self::class, 'normalize_variation' ], self::normalize_list( $style_context['availableVariations'] ?? [] ) ),
						static fn( array $variation ): bool => [] !== $variation
					)
				),
				'themeTokenDiagnostics' => self::normalize_map( $style_context['themeTokenDiagnostics'] ?? [] ),
				'themeTokens'           => ServerCollector::for_tokens(),
				'supportedStylePaths'   => self::supported_style_paths(),
			],
		];

		$result = ResponsesClient::rank(
			StylePrompt::build_system(),
			StylePrompt::build_user( $context, $prompt )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return StylePrompt::parse_response( $result, $context );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function supported_style_paths(): array {
		$theme_tokens = ServerCollector::for_tokens();
		$features = is_array( $theme_tokens['enabledFeatures'] ?? null )
			? $theme_tokens['enabledFeatures']
			: [];
		$paths = [];

		if ( ! empty( $theme_tokens['colors'] ) ) {
			$paths[] = self::supported_path( [ 'color', 'background' ], 'color' );
			$paths[] = self::supported_path( [ 'color', 'text' ], 'color' );

			if ( ! empty( $features['linkColor'] ) ) {
				$paths[] = self::supported_path( [ 'elements', 'link', 'color', 'text' ], 'color' );
			}

			$paths[] = self::supported_path( [ 'elements', 'button', 'color', 'background' ], 'color' );
			$paths[] = self::supported_path( [ 'elements', 'button', 'color', 'text' ], 'color' );
			$paths[] = self::supported_path( [ 'elements', 'heading', 'color', 'text' ], 'color' );
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
		$title = sanitize_text_field( (string) ( $variation['title'] ?? '' ) );

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
