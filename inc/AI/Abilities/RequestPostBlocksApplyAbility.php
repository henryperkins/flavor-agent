<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\Registration;
use WordPress\AI\Abstracts\Abstract_Ability;

final class RequestPostBlocksApplyAbility extends Abstract_Ability {

	public const ABILITY_NAME = 'flavor-agent/request-post-blocks-apply';

	public function input_schema(): array {
		return Registration::post_blocks_apply_input_schema( self::ABILITY_NAME );
	}

	public function output_schema(): array {
		return Registration::external_apply_output_schema( self::ABILITY_NAME );
	}

	public function execute_callback( mixed $input ): mixed {
		return ApplyAbilities::request_post_blocks_apply( $input );
	}

	public function permission_callback( mixed $input = null ): bool {
		if ( ! \current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$input = \is_object( $input ) ? \get_object_vars( $input ) : ( \is_array( $input ) ? $input : [] );
		$scope = $input['scope'] ?? [];
		$scope = \is_object( $scope ) ? \get_object_vars( $scope ) : ( \is_array( $scope ) ? $scope : [] );

		$post_id = (int) ( $scope['postId'] ?? 0 );

		if ( $post_id > 0 ) {
			return \current_user_can( 'edit_post', $post_id );
		}

		return true;
	}

	public function meta(): array {
		return Registration::external_apply_meta( self::ABILITY_NAME );
	}

	public function category(): string {
		return 'flavor-agent';
	}
}
