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

		if ( ! self::ai_features_enabled() ) {
			return false;
		}

		return (bool) \apply_filters(
			// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- AI plugin feature-gate filter: deliberately unprefixed and hyphenated because it carries the feature ID.
			'wpai_feature_flavor-agent_enabled',
			self::enabled_option( 'wpai_feature_flavor-agent_enabled', false )
		);
	}

	/**
	 * Mirrors the WordPress AI plugin's master feature gate.
	 *
	 * AI 1.4.0 retired the global "Enable AI" toggle: its upgrade deletes the
	 * wpai_features_enabled option and its loader defaults the gate to true.
	 * Older AI versions still store the toggle, so it is honored until retired.
	 */
	public static function ai_features_enabled(): bool {
		$default_value = self::ai_global_toggle_retired()
			? true
			: self::enabled_option( 'wpai_features_enabled', false );

		return (bool) \apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- AI plugin master feature-gate filter, deliberately not plugin-prefixed.
			'wpai_features_enabled',
			$default_value
		);
	}

	private static function ai_global_toggle_retired(): bool {
		$ai_version = \defined( 'WPAI_VERSION' ) ? (string) \constant( 'WPAI_VERSION' ) : '';

		if ( '' !== $ai_version && \version_compare( $ai_version, '1.4.0', '>=' ) ) {
			return true;
		}

		// Recorded by AI 1.4.0's upgrade routine once it has deleted the toggle.
		return '1' === (string) \get_option( 'wpai_global_toggle_removed', '' );
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
			Registration::register_external_apply_abilities();
		}
	}

	/**
	 * Whether Jetpack AI is serving requests in place of the WordPress AI
	 * Client runtime (e.g. on WordPress.com managed hosting).
	 */
	public static function jetpack_ai_runtime_active(): bool {
		return ! self::ai_feature_contracts_available()
			&& \class_exists( '\\FlavorAgent\\LLM\\JetpackAIProvider' )
			&& \FlavorAgent\LLM\JetpackAIProvider::is_available();
	}

	public static function render_missing_contract_notice(): void {
		if ( self::canonical_contracts_available() ) {
			return;
		}

		if ( ! \function_exists( 'current_user_can' ) || ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		// In Jetpack-AI mode the canonical AI Client contracts are intentionally
		// absent and text generation runs through Jetpack AI. Suppress the
		// "missing contract" warning so it does not nag on every admin page.
		if ( self::jetpack_ai_runtime_active() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo \esc_html__(
			'Flavor Agent\'s governed AI surfaces require the WordPress AI plugin Feature framework and the Abilities API. Recommendation UI and governed external applies stay unavailable until those canonical AI contracts are active.',
			'flavor-agent'
		);
		echo '</p></div>';
	}
}
