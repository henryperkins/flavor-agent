<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\Registration;
use FlavorAgent\Activity\Permissions;
use FlavorAgent\Activity\Repository;
use WordPress\AI\Abstracts\Abstract_Ability;

final class UndoActivityAbility extends Abstract_Ability {

	public const ABILITY_NAME = 'flavor-agent/undo-activity';

	public function input_schema(): array {
		return Registration::external_apply_input_schema( self::ABILITY_NAME );
	}

	public function output_schema(): array {
		return Registration::external_apply_output_schema( self::ABILITY_NAME );
	}

	public function execute_callback( mixed $input ): mixed {
		return ApplyAbilities::undo_activity( $input );
	}

	public function permission_callback( mixed $input = null ): bool {
		$input       = \is_array( $input ) ? $input : ( \is_object( $input ) ? \get_object_vars( $input ) : [] );
		$activity_id = \sanitize_text_field( (string) ( $input['activityId'] ?? '' ) );
		$entry       = '' !== $activity_id ? Repository::find( $activity_id ) : null;

		if ( \is_array( $entry ) ) {
			// Style rows resolve to edit_theme_options through the contextual check.
			return Permissions::can_access_entry( $entry );
		}

		return false;
	}

	public function meta(): array {
		return Registration::external_apply_meta( self::ABILITY_NAME );
	}

	public function category(): string {
		return 'flavor-agent';
	}
}
