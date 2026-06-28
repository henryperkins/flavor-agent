<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\Registration;
use WordPress\AI\Abstracts\Abstract_Ability;

final class RequestTemplatePartApplyAbility extends Abstract_Ability {

	public const ABILITY_NAME = 'flavor-agent/request-template-part-apply';

	public function input_schema(): array {
		return Registration::template_part_apply_input_schema( self::ABILITY_NAME );
	}

	public function output_schema(): array {
		return Registration::external_apply_output_schema( self::ABILITY_NAME );
	}

	public function execute_callback( mixed $input ): mixed {
		return ApplyAbilities::request_template_part_apply( $input );
	}

	public function permission_callback( mixed $input = null ): bool {
		unset( $input );

		return \current_user_can( 'edit_theme_options' );
	}

	public function meta(): array {
		return Registration::external_apply_meta( self::ABILITY_NAME );
	}

	public function category(): string {
		return 'flavor-agent';
	}
}
