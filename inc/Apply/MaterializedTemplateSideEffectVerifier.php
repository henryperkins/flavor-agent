<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/** Verify the Core-managed taxonomy and metadata effects of materialization. */
final class MaterializedTemplateSideEffectVerifier {

	public static function verify(
		int $post_id,
		string $theme,
		?string $area,
		string $origin,
		string $surface,
		MaterializationWriteContext $context
	): true|\WP_Error {
		if (
			$post_id <= 0
			|| '' === $theme
			|| ! $context->matches_current()
		) {
			return self::recovery_required( $surface, $post_id );
		}

		$themes = $context->read_term_names( $post_id, 'wp_theme' );

		if (
			[ $theme ] !== $themes
			|| ! $context->matches_current()
		) {
			return self::recovery_required( $surface, $post_id );
		}

		if ( null !== $area ) {
			$areas = $context->read_term_names( $post_id, 'wp_template_part_area' );

			if (
				[ $area ] !== $areas
				|| ! $context->matches_current()
			) {
				return self::recovery_required( $surface, $post_id );
			}
		}

		$origin_rows = $context->read_post_meta_rows( $post_id, 'origin' );

		if (
			! is_array( $origin_rows )
			|| 1 !== count( $origin_rows )
			|| $origin !== (string) ( $origin_rows[0]->meta_value ?? '' )
			|| ! $context->matches_current()
		) {
			return self::recovery_required( $surface, $post_id );
		}

		return true;
	}

	private static function recovery_required( string $surface, int $post_id ): \WP_Error {
		return ExistingPostContentCompensator::recovery_required(
			$surface,
			$post_id,
			'Flavor Agent materialized the block entity but could not confirm all required taxonomy and metadata side effects.'
		);
	}

	private function __construct() {}
}
