<?php

declare(strict_types=1);

namespace FlavorAgent\AI;

use FlavorAgent\Abilities\Registration;

final class FeatureBootstrap {

	/**
	 * @param array<string, string> $classes
	 * @return array<string, string>
	 */
	public static function register_feature_class( array $classes ): array {
		if ( ! self::ai_feature_contracts_available() ) {
			return $classes;
		}

		$classes['flavor-agent'] = FlavorAgentFeature::class;

		return $classes;
	}

	public static function abilities_api_available(): bool {
		return \function_exists( 'wp_register_ability' );
	}

	public static function ai_feature_contracts_available(): bool {
		return \class_exists( '\WordPress\AI\Abstracts\Abstract_Feature' )
			&& \class_exists( '\WordPress\AI\Abstracts\Abstract_Ability' );
	}

	public static function canonical_contracts_available(): bool {
		return self::abilities_api_available() && self::ai_feature_contracts_available();
	}

	public static function editor_runtime_available(): bool {
		return self::canonical_contracts_available()
			&& \function_exists( 'wp_enqueue_script_module' );
	}

	public static function recommendation_feature_enabled(): bool {
		if ( ! self::ai_feature_contracts_available() ) {
			return false;
		}

		$features_enabled = (bool) \apply_filters(
			'wpai_features_enabled',
			self::enabled_option( 'wpai_features_enabled', false )
		);

		if ( ! $features_enabled ) {
			return false;
		}

		return (bool) \apply_filters(
			// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- AI plugin feature filters include the hyphenated feature ID.
			'wpai_feature_flavor-agent_enabled',
			self::enabled_option( 'wpai_feature_flavor-agent_enabled', false )
		);
	}

	private static function enabled_option( string $option_name, bool $default_value = false ): bool {
		$value = \get_option( $option_name, $default_value );

		if ( \is_bool( $value ) ) {
			return $value;
		}

		if ( \is_numeric( $value ) ) {
			return (bool) (int) $value;
		}

		if ( \is_string( $value ) ) {
			return ! \in_array(
				\strtolower( \trim( $value ) ),
				[ '', '0', 'false', 'off', 'no' ],
				true
			);
		}

		return (bool) $value;
	}

	public static function register_global_ability_category(): void {
		if ( ! \function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		Registration::register_category();
	}

	public static function register_global_helper_abilities(): void {
		if ( ! self::abilities_api_available() ) {
			return;
		}

		Registration::register_abilities();

		if ( self::canonical_contracts_available() && self::recommendation_feature_enabled() ) {
			Registration::register_recommendation_abilities();
		}
	}

	public static function render_missing_contract_notice(): void {
		if ( self::canonical_contracts_available() ) {
			return;
		}

		if ( ! \function_exists( 'current_user_can' ) || ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo \esc_html__(
			'Flavor Agent recommendations require the WordPress AI plugin Feature framework and the Abilities API. Recommendation UI is unavailable until those canonical AI contracts are active.',
			'flavor-agent'
		);
		echo '</p></div>';
	}
}
