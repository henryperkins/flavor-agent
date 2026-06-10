<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Abilities\StyleAbilities;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\StyleContrastValidator;
use FlavorAgent\LLM\StylePrompt;

/**
 * Server-side executor for Global Styles / Style Book apply and undo.
 *
 * Ports the client pipeline in src/utils/style-operations.js
 * (applyGlobalStyleSuggestionOperations / undoGlobalStyleSuggestionOperations):
 * validate against the live execution contract, apply to the user config,
 * enforce WCAG AA contrast, write the wp_global_styles CPT through core APIs,
 * and snapshot in the editor-compatible before/after shapes.
 */
final class StyleApplyExecutor {

	public const SURFACE_GLOBAL_STYLES = 'global-styles';
	public const SURFACE_STYLE_BOOK    = 'style-book';

	/**
	 * @return array{postId: int, config: array{settings: array<string, mixed>, styles: array<string, mixed>}, raw: array<string, mixed>}|\WP_Error
	 */
	public static function resolve_user_global_styles( string $global_styles_id ): array|\WP_Error {
		$post_id = (int) $global_styles_id;
		$post    = $post_id > 0 && function_exists( 'get_post' ) ? get_post( $post_id ) : null;

		if ( ! is_object( $post ) || 'wp_global_styles' !== (string) ( $post->post_type ?? '' ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'The requested Global Styles entity is not available on this site.',
				[ 'status' => 404 ]
			);
		}

		$decoded = json_decode( (string) ( $post->post_content ?? '' ), true );
		$raw     = is_array( $decoded ) ? $decoded : [];

		return [
			'postId' => $post_id,
			'config' => [
				'settings' => is_array( $raw['settings'] ?? null ) ? $raw['settings'] : [],
				'styles'   => is_array( $raw['styles'] ?? null ) ? $raw['styles'] : [],
			],
			'raw'    => $raw,
		];
	}

	/**
	 * Comparable view of a user config: settings + styles only, keys sorted
	 * deep, matching the client's getComparableGlobalStylesConfig().
	 *
	 * @param array<string, mixed> $config
	 * @return array{settings: mixed, styles: mixed}
	 */
	public static function comparable_config( array $config ): array {
		return [
			'settings' => self::sort_keys_deep( is_array( $config['settings'] ?? null ) ? $config['settings'] : [] ),
			'styles'   => self::sort_keys_deep( is_array( $config['styles'] ?? null ) ? $config['styles'] : [] ),
		];
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function comparable_config_hash( array $config ): string {
		return hash( 'sha256', (string) wp_json_encode( self::comparable_config( $config ) ) );
	}

	/**
	 * Live validation context with the same shape StylePrompt::validate_operations()
	 * consumes at generation time.
	 *
	 * @return array{scope: array<string, mixed>, styleContext: array<string, mixed>}|\WP_Error
	 */
	public static function build_validation_context( string $surface, string $block_name = '' ): array|\WP_Error {
		$theme_tokens = ServerCollector::for_tokens();

		if ( self::SURFACE_STYLE_BOOK === $surface ) {
			$block_manifest = ServerCollector::introspect_block_type( $block_name );

			if ( ! is_array( $block_manifest ) ) {
				return new \WP_Error(
					'flavor_agent_apply_target_unavailable',
					'The Style Book target block is no longer registered on this site.',
					[ 'status' => 409 ]
				);
			}

			return [
				'scope'        => [
					'surface'   => self::SURFACE_STYLE_BOOK,
					'blockName' => $block_name,
				],
				'styleContext' => [
					'supportedStylePaths' => StyleAbilities::supported_style_paths_for_block( $block_manifest ),
					'availableVariations' => [],
					'themeTokens'         => $theme_tokens,
					'styleBookTarget'     => [
						'blockName'  => $block_name,
						'blockTitle' => sanitize_text_field( (string) ( $block_manifest['title'] ?? '' ) ),
					],
				],
			];
		}

		return [
			'scope'        => [ 'surface' => self::SURFACE_GLOBAL_STYLES ],
			'styleContext' => [
				'supportedStylePaths' => StyleAbilities::supported_style_paths(),
				'availableVariations' => self::theme_style_variations(),
				'themeTokens'         => $theme_tokens,
			],
		];
	}

	/**
	 * Validate and execute operations against the live entity.
	 *
	 * @param array<int, array<string, mixed>> $operations
	 * @return array{target: array<string, mixed>, before: array<string, mixed>, after: array<string, mixed>}|\WP_Error
	 */
	public static function apply( string $surface, string $global_styles_id, array $operations, string $block_name = '' ): array|\WP_Error {
		$surface  = self::SURFACE_STYLE_BOOK === $surface ? self::SURFACE_STYLE_BOOK : self::SURFACE_GLOBAL_STYLES;
		$resolved = self::resolve_user_global_styles( $global_styles_id );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$context = self::build_validation_context( $surface, $block_name );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$validated = StylePrompt::validate_operations_for_apply( $operations, $context );

		if ( [] === $validated['operations'] || count( $validated['operations'] ) !== count( $operations ) ) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'One or more proposed style operations failed validation against the current execution contract.',
				[
					'status'            => 409,
					'validationReasons' => $validated['reasons'],
				]
			);
		}

		$before_config = $resolved['config'];
		$after_config  = $before_config;
		$applied       = [];
		$variations    = is_array( $context['styleContext']['availableVariations'] ?? null )
			? $context['styleContext']['availableVariations']
			: [];

		foreach ( $validated['operations'] as $operation ) {
			$type = (string) ( $operation['type'] ?? '' );

			if ( 'set_theme_variation' === $type ) {
				$variation = self::resolve_variation_payload( $operation, $variations );

				if ( null === $variation ) {
					return new \WP_Error(
						'flavor_agent_apply_operations_invalid',
						'The suggested theme variation is no longer available.',
						[ 'status' => 409 ]
					);
				}

				$after_config = [
					'settings' => is_array( $variation['settings'] ?? null ) ? $variation['settings'] : [],
					'styles'   => is_array( $variation['styles'] ?? null ) ? $variation['styles'] : [],
				];
				$applied[]    = $operation;
				continue;
			}

			$path        = is_array( $operation['path'] ?? null ) ? array_values( $operation['path'] ) : [];
			$config_path = 'set_block_styles' === $type
				? array_merge( [ 'blocks', (string) ( $operation['blockName'] ?? '' ) ], $path )
				: $path;
			$applied[]   = array_merge(
				$operation,
				[ 'beforeValue' => self::read_path( $before_config['styles'], $config_path ) ]
			);

			$after_config['styles'] = self::write_path( $after_config['styles'], $config_path, $operation['value'] ?? null );
		}

		$contrast = StyleContrastValidator::evaluate(
			$applied,
			[
				'styleContext' => [
					'themeTokens'  => is_array( $context['styleContext']['themeTokens'] ?? null )
						? $context['styleContext']['themeTokens']
						: [],
					'mergedConfig' => self::merged_config_with_user_overrides( $after_config ),
				],
			]
		);

		if ( empty( $contrast['passed'] ) ) {
			return new \WP_Error(
				'flavor_agent_apply_contrast_failed',
				(string) ( $contrast['reason'] ?? 'The proposed style changes fail the WCAG AA contrast requirement.' ),
				[ 'status' => 409 ]
			);
		}

		$write = self::write_user_global_styles( $resolved['postId'], $resolved['raw'], $after_config );

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		if ( self::SURFACE_STYLE_BOOK === $surface ) {
			$style_book_target = is_array( $context['styleContext']['styleBookTarget'] ?? null )
				? $context['styleContext']['styleBookTarget']
				: [];

			return [
				'target' => [
					'globalStylesId' => $global_styles_id,
					'blockName'      => $block_name,
					'blockTitle'     => (string) ( $style_book_target['blockTitle'] ?? '' ),
				],
				'before' => [ 'userConfig' => self::trim_config_to_block_branch( $before_config, $block_name ) ],
				'after'  => [
					'userConfig' => self::trim_config_to_block_branch( $after_config, $block_name ),
					'operations' => $applied,
				],
			];
		}

		return [
			'target' => [ 'globalStylesId' => $global_styles_id ],
			'before' => [ 'userConfig' => $before_config ],
			'after'  => [
				'userConfig' => $after_config,
				'operations' => $applied,
			],
		];
	}

	/**
	 * The live merged config already contains the current user layer, and the
	 * set-operation vocabulary never deletes keys, so overlaying the post-apply
	 * user config over the current merged data yields the same complements the
	 * client computes from theme-base + after-config. Variation switches (which
	 * CAN drop keys) never reach contrast grouping because variation + readable
	 * color combinations are rejected upstream.
	 *
	 * @param array{settings: array<string, mixed>, styles: array<string, mixed>} $after_config
	 * @return array{settings: array<string, mixed>, styles: array<string, mixed>}
	 */
	private static function merged_config_with_user_overrides( array $after_config ): array {
		$merged_settings = function_exists( 'wp_get_global_settings' ) ? (array) wp_get_global_settings() : [];
		$merged_styles   = function_exists( 'wp_get_global_styles' ) ? (array) wp_get_global_styles() : [];

		return [
			'settings' => self::merge_deep( $merged_settings, $after_config['settings'] ),
			'styles'   => self::merge_deep( $merged_styles, $after_config['styles'] ),
		];
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function write_user_global_styles( int $post_id, array $raw, array $after_config ): true|\WP_Error {
		$content = array_merge(
			$raw,
			[
				'isGlobalStylesUserThemeJSON' => true,
				'settings'                    => $after_config['settings'],
				'styles'                      => $after_config['styles'],
			]
		);

		if ( ! isset( $content['version'] ) ) {
			$content['version'] = class_exists( '\WP_Theme_JSON' ) && defined( '\WP_Theme_JSON::LATEST_SCHEMA' )
				? \WP_Theme_JSON::LATEST_SCHEMA
				: 3;
		}

		$updated = function_exists( 'wp_update_post' )
			? wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => (string) wp_json_encode( $content ),
				]
			)
			: 0;

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( 0 === (int) $updated ) {
			return new \WP_Error(
				'flavor_agent_apply_write_failed',
				'Flavor Agent could not write the Global Styles entity.',
				[ 'status' => 500 ]
			);
		}

		if ( class_exists( '\WP_Theme_JSON_Resolver' ) && method_exists( '\WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}

		return true;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function theme_style_variations(): array {
		$variations = class_exists( '\WP_Theme_JSON_Resolver' ) && method_exists( '\WP_Theme_JSON_Resolver', 'get_style_variations' )
			? (array) \WP_Theme_JSON_Resolver::get_style_variations()
			: [];

		return (array) apply_filters( 'flavor_agent_external_apply_theme_variations', $variations );
	}

	/**
	 * @param array<string, mixed>             $operation
	 * @param array<int, array<string, mixed>> $variations
	 * @return array<string, mixed>|null
	 */
	private static function resolve_variation_payload( array $operation, array $variations ): ?array {
		$index   = isset( $operation['variationIndex'] ) && is_numeric( $operation['variationIndex'] )
			? (int) $operation['variationIndex']
			: -1;
		$title   = trim( (string) ( $operation['variationTitle'] ?? '' ) );
		$indexed = $variations[ $index ] ?? null;

		if ( is_array( $indexed ) && ( '' === $title || trim( (string) ( $indexed['title'] ?? '' ) ) === $title ) ) {
			return $indexed;
		}

		if ( '' === $title ) {
			return null;
		}

		foreach ( $variations as $variation ) {
			if ( is_array( $variation ) && trim( (string) ( $variation['title'] ?? '' ) ) === $title ) {
				return $variation;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	private static function trim_config_to_block_branch( array $config, string $block_name ): array {
		$branch = self::read_path(
			is_array( $config['styles'] ?? null ) ? $config['styles'] : [],
			[ 'blocks', $block_name ]
		);

		if ( null === $branch ) {
			return [];
		}

		return [
			'styles' => [
				'blocks' => [
					$block_name => $branch,
				],
			],
		];
	}

	/**
	 * @param array<int, int|string> $path
	 */
	private static function read_path( mixed $value, array $path ): mixed {
		foreach ( $path as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * @param array<int, int|string> $path
	 */
	private static function write_path( mixed $value, array $path, mixed $next ): mixed {
		if ( [] === $path ) {
			return $next;
		}

		$value          = is_array( $value ) ? $value : [];
		$head           = array_shift( $path );
		$value[ $head ] = self::write_path( $value[ $head ] ?? null, $path, $next );

		return $value;
	}

	/**
	 * @param array<int, int|string> $path
	 */
	private static function remove_path( mixed $value, array $path ): mixed {
		if ( ! is_array( $value ) || [] === $path ) {
			return $value;
		}

		$head = $path[0];

		if ( ! array_key_exists( $head, $value ) ) {
			return $value;
		}

		if ( 1 === count( $path ) ) {
			unset( $value[ $head ] );

			return $value;
		}

		$branch = self::remove_path( $value[ $head ], array_slice( $path, 1 ) );

		if ( null === $branch || ( is_array( $branch ) && [] === $branch ) ) {
			unset( $value[ $head ] );
		} else {
			$value[ $head ] = $branch;
		}

		return $value;
	}

	private static function sort_keys_deep( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( [] === $value ) {
			return [];
		}

		if ( array_is_list( $value ) ) {
			return array_map( [ self::class, 'sort_keys_deep' ], $value );
		}

		ksort( $value );

		$sorted = [];

		foreach ( $value as $key => $entry ) {
			$sorted[ $key ] = self::sort_keys_deep( $entry );
		}

		return $sorted;
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $override
	 * @return array<string, mixed>
	 */
	private static function merge_deep( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			$base[ $key ] = is_array( $value ) && is_array( $base[ $key ] ?? null ) && ! array_is_list( $value )
				? self::merge_deep( $base[ $key ], $value )
				: $value;
		}

		return $base;
	}
}
