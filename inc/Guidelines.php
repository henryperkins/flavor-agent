<?php

declare(strict_types=1);

namespace FlavorAgent;

use FlavorAgent\Guidelines\LegacyGuidelinesRepository;
use FlavorAgent\Guidelines\PromptGuidelinesFormatter;
use FlavorAgent\Guidelines\RepositoryResolver;
use FlavorAgent\Support\WordPressAIPolicy;

final class Guidelines {

	public const OPTION_SITE                  = 'flavor_agent_guideline_site';
	public const OPTION_COPY                  = 'flavor_agent_guideline_copy';
	public const OPTION_IMAGES                = 'flavor_agent_guideline_images';
	public const OPTION_ADDITIONAL            = 'flavor_agent_guideline_additional';
	public const OPTION_BLOCKS                = 'flavor_agent_guideline_blocks';
	public const OPTION_MIGRATION_STATUS      = 'flavor_agent_guidelines_migration_status';
	public const MAX_LENGTH                   = 5000;
	public const MIGRATION_STATUS_NOT_STARTED = 'not_started';

	/**
	 * @return array{site: string, copy: string, images: string, additional: string, blocks: array<string, string>}
	 */
	public static function get_all(): array {
		return RepositoryResolver::resolve()->get_all();
	}

	public static function get_guideline( string $category ): string {
		$guidelines = self::get_all();

		return match ( $category ) {
			'site', 'copy', 'images', 'additional' => $guidelines[ $category ],
			default => '',
		};
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_block_guidelines(): array {
		return self::get_all()['blocks'];
	}

	public static function get_block_guideline( string $block_name ): string {
		$guidelines = self::get_block_guidelines();

		return $guidelines[ $block_name ] ?? '';
	}

	public static function has_any(): bool {
		return RepositoryResolver::has_any( self::get_all() );
	}

	/**
	 * @return array{source: string, core_available: bool, legacy_has_data: bool, migration_status: string, migration_completed: bool}
	 */
	public static function storage_status(): array {
		$migration = self::get_migration_status();

		return [
			'source'              => RepositoryResolver::resolve()->source(),
			'core_available'      => RepositoryResolver::core_available(),
			'legacy_has_data'     => RepositoryResolver::has_any( ( new LegacyGuidelinesRepository() )->get_all() ),
			'migration_status'    => $migration['status'],
			'migration_completed' => 'completed' === $migration['status'],
		];
	}

	public static function uses_core_storage(): bool {
		return 'legacy_options' !== self::storage_status()['source'];
	}

	public static function format_prompt_context( string $block_name = '' ): string {
		$upstream = WordPressAIPolicy::upstream_guidelines_for_prompt(
			[ 'site', 'copy', 'images', 'additional' ],
			$block_name
		);

		if ( '' !== $upstream ) {
			return $upstream;
		}

		return PromptGuidelinesFormatter::format( self::get_all(), $block_name );
	}

	/**
	 * @return array{status: string, message: string}
	 */
	public static function get_migration_status(): array {
		$raw = get_option( self::OPTION_MIGRATION_STATUS, [] );

		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		$status = sanitize_key( (string) ( $raw['status'] ?? self::MIGRATION_STATUS_NOT_STARTED ) );

		if ( '' === $status ) {
			$status = self::MIGRATION_STATUS_NOT_STARTED;
		}

		return [
			'status'  => $status,
			'message' => sanitize_text_field( (string) ( $raw['message'] ?? '' ) ),
		];
	}

	public static function record_migration_status( string $status, string $message = '' ): void {
		update_option(
			self::OPTION_MIGRATION_STATUS,
			[
				'status'  => sanitize_key( $status ),
				'message' => sanitize_text_field( $message ),
			],
			false
		);
	}

	/**
	 * @return array{
	 *   guideline_categories: array{
	 *     site: array{guidelines: string},
	 *     copy: array{guidelines: string},
	 *     images: array{guidelines: string},
	 *     additional: array{guidelines: string},
	 *     blocks: array<string, array{guidelines: string}>
	 *   }
	 * }
	 */
	public static function export_payload(): array {
		$guidelines = self::get_all();

		return [
			'guideline_categories' => [
				'site'       => [
					'guidelines' => $guidelines['site'],
				],
				'copy'       => [
					'guidelines' => $guidelines['copy'],
				],
				'images'     => [
					'guidelines' => $guidelines['images'],
				],
				'additional' => [
					'guidelines' => $guidelines['additional'],
				],
				'blocks'     => array_reduce(
					array_keys( $guidelines['blocks'] ),
					static function ( array $carry, string $block_name ) use ( $guidelines ): array {
						$carry[ $block_name ] = [
							'guidelines' => $guidelines['blocks'][ $block_name ],
						];

						return $carry;
					},
					[]
				),
			],
		];
	}

	public static function sanitize_guideline_text( mixed $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$sanitized = sanitize_textarea_field( (string) $value );

		$length = function_exists( 'mb_strlen' )
			? mb_strlen( $sanitized, 'UTF-8' )
			: strlen( $sanitized );

		if ( $length > self::MAX_LENGTH ) {
			$sanitized = function_exists( 'mb_substr' )
				? (string) mb_substr( $sanitized, 0, self::MAX_LENGTH, 'UTF-8' )
				: substr( $sanitized, 0, self::MAX_LENGTH );
		}

		return $sanitized;
	}

	/**
	 * @return array<string, string>
	 */
	public static function sanitize_block_guidelines( mixed $value ): array {
		return self::normalize_block_guidelines( self::decode_block_guidelines_value( $value ) );
	}

	/**
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function get_content_block_options(): array {
		$options  = [];
		$registry = \WP_Block_Type_Registry::get_instance();

		$registered_blocks = method_exists( $registry, 'get_all_registered' )
			? $registry->get_all_registered()
			: [];

		foreach ( $registered_blocks as $block_type ) {
			if ( ! self::block_has_content_role( $block_type ) ) {
				continue;
			}

			$label = is_string( $block_type->title ?? null ) && '' !== $block_type->title
				? $block_type->title
				: (string) $block_type->name;

			$options[] = [
				'value' => (string) $block_type->name,
				'label' => $label,
			];
		}

		usort(
			$options,
			static function ( array $left, array $right ): int {
				$label_comparison = strcasecmp( $left['label'], $right['label'] );

				if ( 0 !== $label_comparison ) {
					return $label_comparison;
				}

				return strcmp( $left['value'], $right['value'] );
			}
		);

		return $options;
	}

	private static function block_has_content_role( mixed $block_type ): bool {
		if ( ! is_object( $block_type ) || ! is_array( $block_type->attributes ?? null ) ) {
			return false;
		}

		foreach ( $block_type->attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}

			if ( isset( $attribute['role'] ) && 'content' === $attribute['role'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string, string>
	 */
	private static function normalize_block_guidelines( mixed $value ): array {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$blocks = [];

		foreach ( $value as $block_name => $block_data ) {
			$block_name = is_string( $block_name ) ? $block_name : '';

			if ( '' === $block_name || ! self::is_valid_block_name( $block_name ) ) {
				continue;
			}

			$guidelines = '';

			if ( is_array( $block_data ) ) {
				$guidelines = self::sanitize_guideline_text( $block_data['guidelines'] ?? '' );
			} else {
				$guidelines = self::sanitize_guideline_text( $block_data );
			}

			if ( '' === $guidelines ) {
				continue;
			}

			$blocks[ $block_name ] = $guidelines;
		}

		if ( [] !== $blocks ) {
			ksort( $blocks, SORT_NATURAL | SORT_FLAG_CASE );
		}

		return $blocks;
	}

	private static function decode_block_guidelines_value( mixed $value ): mixed {
		if ( is_array( $value ) || is_object( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return [];
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed ) {
			return [];
		}

		$decoded = json_decode( $trimmed, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return [];
		}

		return $decoded;
	}

	private static function is_valid_block_name( string $block_name ): bool {
		return 1 === preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $block_name );
	}
}
