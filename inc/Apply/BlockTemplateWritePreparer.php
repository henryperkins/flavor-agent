<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Apply core's Block Hooks save preparation before template persistence.
 */
final class BlockTemplateWritePreparer {

	/**
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function prepare( array $changes, string $post_type, string $surface ): array|\WP_Error {
		$expected_id = (int) ( $changes['ID'] ?? 0 );
		$prepared    = (object) $changes;

		if ( function_exists( 'inject_ignored_hooked_blocks_metadata_attributes' ) ) {
			try {
				$prepared = inject_ignored_hooked_blocks_metadata_attributes( $prepared );
			} catch ( \Throwable ) {
				return self::write_failed( $surface );
			}
		}

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( ! is_object( $prepared ) ) {
			return self::write_failed( $surface );
		}

		$result = (array) $prepared;

		if (
			! isset( $result['post_content'] )
			|| ! is_string( $result['post_content'] )
			|| ( isset( $result['post_type'] ) && $post_type !== (string) $result['post_type'] )
			|| ( $expected_id > 0 && $expected_id !== (int) ( $result['ID'] ?? 0 ) )
			|| ( isset( $result['meta_input'] ) && ! is_array( $result['meta_input'] ) )
		) {
			return self::write_failed( $surface );
		}

		return $result;
	}

	private static function write_failed( string $surface ): \WP_Error {
		return new \WP_Error(
			'flavor_agent_apply_write_preparation_failed',
			sprintf( 'Flavor Agent could not prepare the %s content for Block Hooks-safe persistence.', $surface ),
			[ 'status' => 500 ]
		);
	}

	private function __construct() {}
}
