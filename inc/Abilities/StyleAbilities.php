<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\StylePrompt;

final class StyleAbilities {

	private const SURFACE_GLOBAL_STYLES = 'global-styles';
	private const SURFACE_STYLE_BOOK    = 'style-book';
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
		$input         = self::normalize_map( $input );
		$scope         = self::normalize_map( $input['scope'] ?? [] );
		$style_context = self::normalize_map( $input['styleContext'] ?? [] );
		$prompt        = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : '';

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

		$style_context_payload = self::build_shared_style_context( $style_context, $block_manifest );

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
				'blockName'      => $block_name,
				'blockTitle'     => $block_title,
			],
			'styleContext' => $style_context_payload,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_shared_style_context( array $style_context, ?array $block_manifest = null ): array {
		$supported_style_paths = is_array( $block_manifest )
			? self::supported_block_style_paths_from_manifest( $block_manifest )
			: self::supported_style_paths();

		return [
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
			'supportedStylePaths'   => $supported_style_paths,
		];
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
	private static function normalize_style_book_target( mixed $target ): array {
		$target       = self::normalize_map( $target );
		$block_name   = sanitize_text_field( (string) ( $target['blockName'] ?? '' ) );
		$block_title  = sanitize_text_field(
			(string) ( $target['blockTitle'] ?? $target['label'] ?? '' )
		);
		$description  = sanitize_text_field( (string) ( $target['description'] ?? '' ) );
		$current      = self::normalize_map( $target['currentStyles'] ?? [] );
		$merged       = self::normalize_map( $target['mergedStyles'] ?? [] );

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
