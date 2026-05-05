<?php

declare(strict_types=1);

namespace FlavorAgent;

use FlavorAgent\Guidelines\PromptGuidelinesFormatter;
use FlavorAgent\Guidelines\RepositoryResolver;
use WP_Block_Type_Registry;

final class Guidelines {

	public const OPTION_SITE       = 'flavor_agent_guideline_site';
	public const OPTION_COPY       = 'flavor_agent_guideline_copy';
	public const OPTION_IMAGES     = 'flavor_agent_guideline_images';
	public const OPTION_ADDITIONAL = 'flavor_agent_guideline_additional';
	public const OPTION_BLOCKS     = 'flavor_agent_guideline_blocks';

	private const OPTION_MIGRATION_STATUS = 'flavor_agent_guidelines_migration_status';
	private const GUIDELINE_CATEGORIES    = [ 'site', 'copy', 'images', 'additional' ];

	/**
	 * @return array{site: string, copy: string, images: string, additional: string, blocks: array<string, string>}
	 */
	public static function get_all(): array {
		return RepositoryResolver::resolve()->get_all();
	}

	public static function has_any(): bool {
		return RepositoryResolver::has_any( self::get_all() );
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_block_guidelines(): array {
		$guidelines = self::get_all();

		return is_array( $guidelines['blocks'] ?? null ) ? $guidelines['blocks'] : [];
	}

	/**
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function get_content_block_options(): array {
		if ( ! class_exists( WP_Block_Type_Registry::class ) ) {
			return [];
		}

		$registry = WP_Block_Type_Registry::get_instance();
		$blocks   = [];

		foreach ( $registry->get_all_registered() as $name => $registered_block ) {
			$block_name = is_string( $name ) ? $name : '';

			if ( '' === $block_name || ! is_object( $registered_block ) || ! isset( $registered_block->attributes ) ) {
				continue;
			}

			$attributes       = is_array( $registered_block->attributes ) ? $registered_block->attributes : [];
			$has_content_role = false;

			foreach ( $attributes as $attribute ) {
				if ( ! is_array( $attribute ) ) {
					continue;
				}

				if ( 'content' === ( $attribute['role'] ?? '' ) ) {
					$has_content_role = true;
					break;
				}
			}

			if ( ! $has_content_role ) {
				continue;
			}

			$blocks[] = [
				'value' => $block_name,
				'label' => is_string( $registered_block->title ?? '' ) && '' !== (string) $registered_block->title
					? (string) $registered_block->title
					: $block_name,
			];
		}

		usort(
			$blocks,
			static function ( array $first, array $second ): int {
				return strnatcasecmp( (string) $first['label'], (string) $second['label'] );
			}
		);

		return $blocks;
	}

	public static function sanitize_guideline_text( mixed $value ): string {
		return is_string( $value ) ? sanitize_textarea_field( $value ) : '';
	}

	/**
	 * @param mixed $raw_guidelines
	 * @return array<string, string>
	 */
	public static function sanitize_block_guidelines( mixed $raw_guidelines ): array {
		if ( null === $raw_guidelines ) {
			return [];
		}

		if ( is_string( $raw_guidelines ) ) {
			$decoded = json_decode( $raw_guidelines, true );

			if ( ! is_array( $decoded ) ) {
				return [];
			}

			$raw_guidelines = $decoded;
		}

		if ( ! is_array( $raw_guidelines ) ) {
			return [];
		}

		$blocks = [];

		foreach ( $raw_guidelines as $block_name => $entry ) {
			if ( ! is_string( $block_name ) || ! self::is_valid_block_name( $block_name ) ) {
				continue;
			}

			$guideline = '';

			if ( is_string( $entry ) ) {
				$guideline = $entry;
			} elseif ( is_array( $entry ) && array_key_exists( 'guidelines', $entry ) ) {
				$guideline = $entry['guidelines'];
			} else {
				continue;
			}

			$guideline = self::sanitize_guideline_text( $guideline );

			if ( '' !== $guideline ) {
				$blocks[ $block_name ] = $guideline;
			}
		}

		ksort( $blocks, SORT_NATURAL );

		return $blocks;
	}

	/**
	 * @param array<int, string>|null $categories
	 */
	public static function format_prompt_context( string $block_name = '', ?array $categories = null ): string {
		$categories = self::normalize_guideline_categories( $categories );

		if ( function_exists( 'WordPress\\AI\\format_guidelines_for_prompt' ) ) {
			try {
				$upstream = (string) \WordPress\AI\format_guidelines_for_prompt(
					$categories,
					'' !== $block_name ? $block_name : null
				);

				$upstream = trim( $upstream );

				if ( '' !== $upstream ) {
					return $upstream;
				}
			} catch ( \Throwable $throwable ) {
				return '';
			}
		}

		return PromptGuidelinesFormatter::format( self::get_all(), $block_name, $categories );
	}

	/**
	 * @return array{guideline_categories: array<string, mixed>}
	 */
	public static function export_payload(): array {
		$guidelines = self::get_all();
		$blocks     = is_array( $guidelines['blocks'] ?? null ) ? $guidelines['blocks'] : [];
		$payload    = [];

		foreach ( [ 'site', 'copy', 'images', 'additional' ] as $category ) {
			$payload[ $category ] = [
				'guidelines' => (string) ( $guidelines[ $category ] ?? '' ),
			];
		}

		foreach ( $blocks as $block_name => $guideline ) {
			if ( is_string( $block_name ) && is_string( $guideline ) ) {
				$payload['blocks'][ $block_name ] = [
					'guidelines' => $guideline,
				];
			}
		}

		if ( ! is_array( $payload['blocks'] ?? null ) ) {
			$payload['blocks'] = [];
		}

		return [ 'guideline_categories' => $payload ];
	}

	public static function storage_status(): array {
		$all             = self::get_all();
		$source          = RepositoryResolver::resolve()->source();
		$core_available  = RepositoryResolver::core_available();
		$legacy_has_data = '' !== (string) get_option( self::OPTION_SITE, '' )
			|| '' !== (string) get_option( self::OPTION_COPY, '' )
			|| '' !== (string) get_option( self::OPTION_IMAGES, '' )
			|| '' !== (string) get_option( self::OPTION_ADDITIONAL, '' )
			|| ( is_array( get_option( self::OPTION_BLOCKS, [] ) ) && [] !== get_option( self::OPTION_BLOCKS, [] ) );

		$migration_status = is_string( get_option( self::OPTION_MIGRATION_STATUS, 'not_started' ) )
			? (string) get_option( self::OPTION_MIGRATION_STATUS, 'not_started' )
			: 'not_started';

		if ( '' === $migration_status ) {
			$migration_status = 'not_started';
		}

		return [
			'source'              => $source,
			'core_available'      => $core_available,
			'legacy_has_data'     => $legacy_has_data,
			'migration_status'    => $migration_status,
			'migration_completed' => 'completed' === $migration_status,
		];
	}

	private static function is_valid_block_name( string $block_name ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9_\-]*(?:\/[a-z][a-z0-9_\-]*)?$/', $block_name );
	}

	/**
	 * @param array<int, string>|null $categories
	 * @return array<int, string>
	 */
	private static function normalize_guideline_categories( ?array $categories ): array {
		if ( null === $categories || [] === $categories ) {
			return self::GUIDELINE_CATEGORIES;
		}

		$allowed    = array_flip( self::GUIDELINE_CATEGORIES );
		$normalized = [];

		foreach ( $categories as $category ) {
			$category = sanitize_key( (string) $category );

			if ( isset( $allowed[ $category ] ) && ! in_array( $category, $normalized, true ) ) {
				$normalized[] = $category;
			}
		}

		return [] !== $normalized ? $normalized : self::GUIDELINE_CATEGORIES;
	}
}
