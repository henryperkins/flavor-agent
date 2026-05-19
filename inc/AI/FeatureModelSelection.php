<?php

declare(strict_types=1);

namespace FlavorAgent\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FeatureModelSelection {

	public const OPTION_NAME = 'wpai_feature_flavor-agent_field_developer';

	/**
	 * @return array{provider: string, model: string}
	 */
	public static function get(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return self::empty();
		}

		$value = get_option( self::OPTION_NAME, [] );

		if ( ! is_array( $value ) ) {
			return self::empty();
		}

		$provider = sanitize_key( (string) ( $value['provider'] ?? '' ) );
		$model    = self::sanitize_model( $value['model'] ?? '' );

		return [
			'provider' => $provider,
			'model'    => $model,
		];
	}

	/**
	 * @return array{provider: string, model: string}
	 */
	private static function empty(): array {
		return [
			'provider' => '',
			'model'    => '',
		];
	}

	private static function sanitize_model( mixed $model ): string {
		if ( ! is_scalar( $model ) ) {
			return '';
		}

		$model = trim( (string) $model );

		if ( '' === $model ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9._:\\/-]/', '', $model ) ?? '';
	}
}
