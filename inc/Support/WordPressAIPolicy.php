<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class WordPressAIPolicy {

	private const TEXTGEN_OPTION_KEYS = [
		'candidate_count',
		'max_tokens',
		'temperature',
		'top_p',
		'top_k',
		'stop_sequences',
		'presence_penalty',
		'frequency_penalty',
		'logprobs',
		'top_logprobs',
	];

	/**
	 * @param array<string, mixed> $data
	 */
	public static function system_instruction( string $instruction, string $ability_name = '', array $data = [] ): string {
		$ability_name = '' !== $ability_name ? $ability_name : 'flavor-agent';

		return (string) apply_filters( 'wpai_system_instruction', $instruction, $ability_name, $data );
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public static function sanitize_text_generation_options( array $options ): array {
		$sanitized = [];

		foreach ( self::TEXTGEN_OPTION_KEYS as $key ) {
			if ( ! array_key_exists( $key, $options ) ) {
				continue;
			}

			$value = self::sanitize_text_generation_option( $key, $options[ $key ] );

			if ( null !== $value ) {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	public static function ability_name_for_schema_name( ?string $schema_name ): string {
		$schema_name = is_string( $schema_name ) ? sanitize_key( $schema_name ) : '';

		return match ( $schema_name ) {
			'flavor_agent_block' => 'flavor-agent/recommend-block',
			'flavor_agent_content' => 'flavor-agent/recommend-content',
			'flavor_agent_pattern' => 'flavor-agent/recommend-patterns',
			'flavor_agent_template' => 'flavor-agent/recommend-template',
			'flavor_agent_template_part' => 'flavor-agent/recommend-template-part',
			'flavor_agent_navigation' => 'flavor-agent/recommend-navigation',
			'flavor_agent_style' => 'flavor-agent/recommend-style',
			default => '',
		};
	}

	/**
	 * @param array<int, string> $categories
	 */
	public static function upstream_guidelines_for_prompt( array $categories, string $block_name = '' ): string {
		if ( ! function_exists( 'WordPress\\AI\\format_guidelines_for_prompt' ) ) {
			return '';
		}

		try {
			return trim(
				(string) \WordPress\AI\format_guidelines_for_prompt(
					array_values( array_map( 'strval', $categories ) ),
					'' !== $block_name ? $block_name : null
				)
			);
		} catch ( \Throwable $throwable ) {
			return '';
		}
	}

	public static function pre_normalize_content( string $content ): string {
		return (string) apply_filters( 'wpai_pre_normalize_content', $content );
	}

	public static function normalize_content( string $content ): string {
		return trim( (string) apply_filters( 'wpai_normalize_content', $content ) );
	}

	public static function sanitize_textarea_content( string $content ): string {
		return self::normalize_content(
			sanitize_textarea_field(
				str_replace( "\r", '', self::pre_normalize_content( $content ) )
			)
		);
	}

	private static function sanitize_text_generation_option( string $key, mixed $value ): mixed {
		return match ( $key ) {
			'candidate_count', 'max_tokens', 'top_k', 'top_logprobs' => self::sanitize_non_negative_int( $value ),
			'temperature', 'top_p', 'presence_penalty', 'frequency_penalty' => is_numeric( $value ) ? (float) $value : null,
			'logprobs' => filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ),
			'stop_sequences' => self::sanitize_stop_sequences( $value ),
			default => null,
		};
	}

	private static function sanitize_non_negative_int( mixed $value ): ?int {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		return max( 0, (int) $value );
	}

	/**
	 * @return array<int, string>|null
	 */
	private static function sanitize_stop_sequences( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$sequences = [];

		foreach ( $value as $sequence ) {
			if ( is_array( $sequence ) || is_object( $sequence ) ) {
				continue;
			}

			$sequence = sanitize_text_field( (string) $sequence );

			if ( '' !== $sequence ) {
				$sequences[] = $sequence;
			}
		}

		return [] !== $sequences ? array_values( array_unique( $sequences ) ) : null;
	}
}
