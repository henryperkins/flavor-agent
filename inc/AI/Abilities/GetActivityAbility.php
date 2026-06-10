<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\Registration;
use FlavorAgent\Activity\Permissions;
use FlavorAgent\Activity\Repository;
use WordPress\AI\Abstracts\Abstract_Ability;

final class GetActivityAbility extends Abstract_Ability {

	public const ABILITY_NAME = 'flavor-agent/get-activity';

	public function input_schema(): array {
		return Registration::external_apply_input_schema( self::ABILITY_NAME );
	}

	public function output_schema(): array {
		return Registration::external_apply_output_schema( self::ABILITY_NAME );
	}

	public function execute_callback( mixed $input ): mixed {
		return ApplyAbilities::get_activity( $input );
	}

	public function permission_callback( mixed $input = null ): bool {
		$input       = \is_array( $input ) ? $input : ( \is_object( $input ) ? \get_object_vars( $input ) : [] );
		$activity_id = \sanitize_text_field( (string) ( $input['activityId'] ?? '' ) );
		$entry       = '' !== $activity_id ? Repository::find( $activity_id ) : null;

		if ( \is_array( $entry ) ) {
			return Permissions::can_access_entry( $entry );
		}

		// Missing rows pass the gate so execution can 404 without leaking
		// whether the id exists to capability-less callers.
		return \current_user_can( 'edit_posts' ) || \current_user_can( 'edit_theme_options' );
	}

	public function meta(): array {
		return Registration::external_apply_meta( self::ABILITY_NAME );
	}

	public function category(): string {
		return 'flavor-agent';
	}
}
