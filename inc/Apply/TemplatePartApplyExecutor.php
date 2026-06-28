<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Context\ServerCollector;

/**
 * Server-side executor for governed external template-part structural applies.
 *
 * Mirrors StyleApplyExecutor: read the live part, re-validate operations and
 * expectedTarget fingerprints, mutate the parsed block tree atomically through
 * BlockTreeMutator, persist via core post APIs, and snapshot before/after
 * post_content. No attestation. See
 * docs/superpowers/specs/2026-06-24-template-part-external-apply-executor-design.md.
 *
 * This task adds only the gate-2 drift baseline (resolve + hash). `execute()`
 * and `undo()` arrive in later tasks, at which point this class declares
 * `implements ExternalApplyExecutor`.
 */
final class TemplatePartApplyExecutor {

	/**
	 * Re-resolve the live template part and return the gate-2 drift baseline:
	 * the sha256 of its parsed -> reserialized live post_content.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function resolve_baseline( array $entry ): string|\WP_Error {
		$part = self::resolve_part( self::part_ref( $entry ) );

		return is_wp_error( $part )
			? $part
			: self::content_hash( (string) ( $part->content ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function part_ref( array $entry ): string {
		$target = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];

		return trim( (string) ( $target['templatePartId'] ?? '' ) );
	}

	/**
	 * @return object|\WP_Error A WP_Block_Template-shaped object, or a fail-closed error.
	 */
	private static function resolve_part( string $ref ): object {
		if ( '' === $ref ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'Missing template-part identifier.',
				[ 'status' => 409 ]
			);
		}

		$part = ServerCollector::resolve_template_part_for_apply( $ref );

		// Error only when the part is genuinely missing (non-object). An
		// available-but-empty part is a legitimate apply target (e.g. a future
		// insert-into-empty); its empty content hashes to a valid, stable
		// baseline, so it must not be rejected here.
		if ( ! is_object( $part ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'The requested template part is not available on this site.',
				[ 'status' => 404 ]
			);
		}

		return $part;
	}

	private static function content_hash( string $content ): string {
		return hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) );
	}

	private function __construct() {}
}
