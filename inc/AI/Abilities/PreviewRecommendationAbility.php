<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\Registration;
use WordPress\AI\Abstracts\Abstract_Ability;

abstract class PreviewRecommendationAbility extends Abstract_Ability {

	protected const ABILITY_NAME = '';

	protected const PARENT_CLASS = '';

	protected const PARENT_ABILITY = '';

	/**
	 * @var array<int, string>
	 */
	protected const SIGNATURE_KEYS = [];

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function prepare_parent_input( array $input ): array {
		$input['resolveSignatureOnly'] = true;
		unset( $input['clientRequest'] );

		return $input;
	}

	public function input_schema(): array {
		$schema = Registration::recommendation_input_schema( static::PARENT_ABILITY );

		if ( isset( $schema['properties'] ) && \is_array( $schema['properties'] ) ) {
			unset(
				$schema['properties']['resolveSignatureOnly'],
				$schema['properties']['clientRequest']
			);
		}

		return $schema;
	}

	public function output_schema(): array {
		$properties = [];

		foreach ( static::SIGNATURE_KEYS as $key ) {
			$properties[ $key ] = [ 'type' => 'string' ];
		}

		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		];
	}

	public function meta(): array {
		return Registration::preview_recommendation_meta();
	}

	public function category(): string {
		return 'flavor-agent';
	}

	public function execute_callback( mixed $input ): mixed {
		$parent = $this->build_parent_instance();

		if ( null === $parent ) {
			return new \WP_Error(
				'flavor_agent_preview_parent_unavailable',
				'Flavor Agent preview parent ability is unavailable.',
				[ 'status' => 500 ]
			);
		}

		$prepared = self::prepare_parent_input( \is_array( $input ) ? $input : [] );
		$result   = $parent->execute_callback( $prepared );

		if ( \is_wp_error( $result ) || ! \is_array( $result ) ) {
			return $result;
		}

		return self::filter_signature_output( $result, static::SIGNATURE_KEYS );
	}

	public function permission_callback( mixed $input = null ): bool {
		$parent = $this->build_parent_instance();

		if ( null === $parent ) {
			return false;
		}

		return (bool) $parent->permission_callback( $input );
	}

	protected function build_parent_instance(): ?Abstract_Ability {
		$parent_class = static::PARENT_CLASS;

		if ( '' === $parent_class || ! \class_exists( $parent_class ) ) {
			return null;
		}

		$definitions = Registration::recommendation_ability_classes();
		$definition  = $definitions[ static::PARENT_ABILITY ] ?? null;
		$properties  = \is_array( $definition )
			? [
				'label'       => (string) ( $definition['label'] ?? '' ),
				'description' => (string) ( $definition['description'] ?? '' ),
			]
			: [];

		return new $parent_class( static::PARENT_ABILITY, $properties );
	}

	/**
	 * @param array<string, mixed> $output
	 * @param array<int, string>   $signature_keys
	 * @return array<string, mixed>
	 */
	private static function filter_signature_output( array $output, array $signature_keys ): array {
		$filtered = [];

		foreach ( $signature_keys as $key ) {
			if ( \array_key_exists( $key, $output ) ) {
				$filtered[ $key ] = $output[ $key ];
			}
		}

		return $filtered;
	}
}
