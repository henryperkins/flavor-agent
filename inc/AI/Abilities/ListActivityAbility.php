<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\Registration;
use FlavorAgent\Activity\Permissions;
use WordPress\AI\Abstracts\Abstract_Ability;

final class ListActivityAbility extends Abstract_Ability {

	public const ABILITY_NAME = 'flavor-agent/list-activity';

	public function input_schema(): array {
		return Registration::external_apply_input_schema( self::ABILITY_NAME );
	}

	public function output_schema(): array {
		return Registration::external_apply_output_schema( self::ABILITY_NAME );
	}

	public function execute_callback( mixed $input ): mixed {
		return ApplyAbilities::list_activity( $input );
	}

	public function permission_callback( mixed $input = null ): bool {
		$input     = \is_array( $input ) ? $input : ( \is_object( $input ) ? \get_object_vars( $input ) : [] );
		$scope_key = \sanitize_text_field( (string) ( $input['scopeKey'] ?? '' ) );

		if ( '' === $scope_key ) {
			return false;
		}

		return Permissions::can_access_context_values(
			$scope_key,
			\sanitize_key( (string) ( $input['surface'] ?? '' ) )
		);
	}

	public function meta(): array {
		return Registration::external_apply_meta( self::ABILITY_NAME );
	}

	public function category(): string {
		return 'flavor-agent';
	}
}
