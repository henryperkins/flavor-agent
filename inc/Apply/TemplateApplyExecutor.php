<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Attestation\BlockContentCanonicalizer;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\TemplatePrompt;

/**
 * Server-side executor for governed external page-level template structural applies.
 *
 * Mirrors StyleApplyExecutor: read the live template, re-validate operations and
 * expectedTarget fingerprints, mutate the parsed block tree atomically through
 * BlockTreeMutator, persist via core post APIs, and snapshot before/after
 * post_content. See
 * docs/superpowers/specs/2026-06-28-wp-template-external-apply-executor-design.md.
 *
 * `undo()` re-resolves the live template and restores the before snapshot under the
 * same equality semantics as StyleApplyExecutor::undo, completing the
 * resolve_baseline + execute + undo trio the ExternalApplyExecutor contract
 * requires.
 */
final class TemplateApplyExecutor implements ExternalApplyExecutor {

	/**
	 * @param array<string, mixed> $entry
	 * @return array{target: array<string, string>, document: array{entityId: string, postType: string, scopeKey: string}}|\WP_Error
	 */
	public static function resolve_target_identity( array $entry ): array|\WP_Error {
		$target  = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
		$surface = (string) ( $entry['surface'] ?? '' );
		$raw_ref = $target['templateRef'] ?? null;
		$ref     = is_string( $raw_ref ) ? trim( $raw_ref ) : '';
		$type    = sanitize_key( (string) ( $target['templateType'] ?? '' ) );

		if ( 'template' !== $surface || ! is_string( $raw_ref ) || '' === $ref ) {
			return self::target_mismatch();
		}

		return [
			'target'   => array_merge(
				[ 'templateRef' => $ref ],
				'' !== $type ? [ 'templateType' => $type ] : []
			),
			'document' => [
				'entityId' => $ref,
				'postType' => 'wp_template',
				'scopeKey' => 'wp_template:' . $ref,
			],
		];
	}

	/** @param array<string, mixed> $entry */
	public static function authorize_target( array $entry ): true|\WP_Error {
		$identity = self::resolve_target_identity( $entry );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		if ( ! self::has_matching_document( $entry, $identity['document'] ) ) {
			return self::target_mismatch();
		}

		return current_user_can( 'edit_theme_options' ) ? true : self::target_forbidden();
	}

	/**
	 * Re-resolve the live template, re-validate every stored operation against a
	 * freshly collected live context, re-verify each path-addressed op's
	 * expectedTarget fingerprint, mutate the parsed block tree atomically, and
	 * persist. Any drift (re-validation, expectedTarget, or pattern resolution)
	 * aborts with zero writes.
	 *
	 * @param array<string, mixed> $entry
	 * @return array{target: array<string, string>, before: array<string, string>, after: array<string, mixed>}|\WP_Error
	 */
	public static function execute( array $entry ): array|\WP_Error {
		$identity = self::resolve_target_identity( $entry );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$ref      = $identity['target']['templateRef'];
		$type     = (string) ( $identity['target']['templateType'] ?? '' );
		$template = self::resolve_template( $ref );

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$before_content = (string) ( $template->content ?? '' );
		$before_hash    = self::content_hash( $before_content );
		$apply          = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];
		$operations     = is_array( $apply['operations'] ?? null ) ? $apply['operations'] : [];

		if ( [] === $operations ) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'No operations to apply.',
				[ 'status' => 409 ]
			);
		}

		// v1 guard: this lane executes insert_pattern only.
		foreach ( $operations as $operation ) {
			if ( 'insert_pattern' !== ( is_array( $operation ) ? ( $operation['type'] ?? '' ) : '' ) ) {
				return new \WP_Error(
					'flavor_agent_apply_operations_invalid',
					'External template applies support insert_pattern only in v1.',
					[ 'status' => 409 ]
				);
			}
		}

		// Re-validate against a freshly collected live context. No filter seam:
		// a governed write path must not be interceptable.
		$context = ServerCollector::for_template( $ref, '' !== $type ? $type : null );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$validated = TemplatePrompt::validate_operations_for_apply( $operations, $context );

		if (
			[] === $validated['operations']
			|| count( $validated['operations'] ) !== count( $operations )
		) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'One or more template operations failed re-validation against the live execution contract.',
				[
					'status'            => 409,
					'validationReasons' => $validated['reasons'],
				]
			);
		}

		$executable_operations = StructuralOperationsApplier::restore_requested_expected_targets(
			$validated['operations'],
			$operations
		);

		$blocks = self::apply_operations( parse_blocks( $before_content ), $executable_operations );

		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}

		$after_content = serialize_blocks( $blocks );

		$persisted = self::persist( $ref, $after_content, $before_hash );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		$fresh             = $persisted['entity'];
		$persisted_content = $persisted['content'];

		return [
			// Identity comes from the re-gated entity, not the start-of-execute
			// read: this is the value that lands in the activity row and the
			// Ring III attestation subject, so it must describe what was written.
			'target' => array_merge(
				$identity['target'],
				[
					'slug'  => (string) ( $fresh->slug ?? '' ),
					'title' => (string) ( $fresh->title ?? '' ),
				]
			),
			'before' => [ 'content' => $before_content ],
			'after'  => [
				'content'    => $persisted_content,
				'operations' => $executable_operations,
			],
		];
	}

	/**
	 * Re-resolve the live template and return the gate-2 drift baseline:
	 * the sha256 of its parsed -> reserialized live post_content.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function resolve_baseline( array $entry ): string|\WP_Error {
		$identity = self::resolve_target_identity( $entry );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$content = self::resolve_live_content( $identity['target']['templateRef'] );

		return is_wp_error( $content )
			? $content
			: self::content_hash( $content );
	}

	/**
	 * Resolve the live template content for public subject-state verification.
	 */
	public static function resolve_live_content( string $ref ): string|\WP_Error {
		$template = self::resolve_template( $ref );

		return is_wp_error( $template )
			? $template
			: (string) ( $template->content ?? '' );
	}

	/**
	 * Resolve the exact theme-qualified subject for public attestation checks.
	 */
	public static function resolve_attested_content( string $ref ): string|\WP_Error {
		$template = ServerCollector::resolve_template_for_attestation( $ref );

		if ( ! is_object( $template ) ) {
			return new \WP_Error(
				'flavor_agent_attestation_subject_unavailable',
				'The attested template is not available under its exact theme-qualified id.',
				[ 'status' => 409 ]
			);
		}

		return (string) ( $template->content ?? '' );
	}

	/**
	 * Server-side undo with the exact equality semantics StyleApplyExecutor uses:
	 * re-resolve the live template, then live == before -> already undone (no write);
	 * live != after → drift failure (fail closed, no write); else restore the
	 * before snapshot. Hashes compare parsed -> reserialized content so that
	 * insignificant serialization differences never read as drift.
	 *
	 * @param array<string, mixed> $entry Hydrated activity entry.
	 * @return array{result: string, after: array{content: string}}|\WP_Error
	 */
	public static function undo( array $entry ): array|\WP_Error {
		$identity = self::resolve_target_identity( $entry );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$ref      = $identity['target']['templateRef'];
		$template = self::resolve_template( $ref );

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$before = is_array( $entry['before'] ?? null ) ? $entry['before'] : [];
		$after  = is_array( $entry['after'] ?? null ) ? $entry['after'] : [];

		if ( ! array_key_exists( 'content', $before ) || ! array_key_exists( 'content', $after ) ) {
			return new \WP_Error(
				'flavor_agent_undo_snapshot_unsupported',
				'This activity row does not record the before/after content snapshots needed for a server-side undo.',
				[ 'status' => 409 ]
			);
		}

		$live_hash   = self::content_hash( (string) ( $template->content ?? '' ) );
		$before_hash = self::content_hash( (string) $before['content'] );
		$after_hash  = self::content_hash( (string) $after['content'] );

		if ( hash_equals( $live_hash, $before_hash ) ) {
			return [
				'result' => 'already_undone',
				'after'  => [ 'content' => (string) ( $template->content ?? '' ) ],
			];
		}

		if ( ! hash_equals( $live_hash, $after_hash ) ) {
			return new \WP_Error(
				'flavor_agent_undo_drift',
				'The template changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
				[ 'status' => 409 ]
			);
		}

		$persisted = self::persist( $ref, (string) $before['content'], $live_hash );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		return [
			'result' => 'undone',
			'after'  => [ 'content' => $persisted['content'] ],
		];
	}

	/** @param array<string, mixed> $entry @param array{entityId: string, postType: string, scopeKey: string} $canonical */
	private static function has_matching_document( array $entry, array $canonical ): bool {
		$document = is_array( $entry['document'] ?? null ) ? $entry['document'] : [];

		foreach ( $canonical as $field => $value ) {
			if ( ! array_key_exists( $field, $document ) || $value !== $document[ $field ] ) {
				return false;
			}
		}

		return true;
	}

	private static function target_mismatch(): \WP_Error {
		return new \WP_Error( 'flavor_agent_apply_target_mismatch', 'The stored template target does not match its canonical document identity.', [ 'status' => 409 ] );
	}

	private static function target_forbidden(): \WP_Error {
		return new \WP_Error( 'flavor_agent_apply_target_forbidden', 'You are not allowed to apply changes to this template.', [ 'status' => 403 ] );
	}

	/**
	 * @return object|\WP_Error A WP_Block_Template-shaped object, or a fail-closed error.
	 */
	private static function resolve_template( string $ref ): object {
		if ( '' === $ref ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'Missing template identifier.',
				[ 'status' => 409 ]
			);
		}

		$template = ServerCollector::resolve_template_for_apply( $ref );

		if ( ! is_object( $template ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'The requested template is not available on this site.',
				[ 'status' => 404 ]
			);
		}

		if ( $ref !== (string) ( $template->id ?? '' ) ) {
			return self::target_mismatch();
		}

		return $template;
	}

	private static function content_hash( string $content ): string {
		return BlockContentCanonicalizer::digest( $content );
	}

	/**
	 * Final concurrency gate: re-resolve the live template immediately before a
	 * write, fail closed if its parsed -> reserialized content hash moved since
	 * $expected_hash, and otherwise return the fresh entity so the caller persists
	 * against the current wp_id.
	 *
	 * @return object|\WP_Error
	 */
	private static function assert_template_unchanged( string $ref, string $expected_hash ): object {
		$current = self::resolve_template( $ref );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		if ( ! hash_equals( self::content_hash( (string) ( $current->content ?? '' ) ), $expected_hash ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_changed',
				'The template changed before Flavor Agent could persist this operation. Regenerate the request and try again.',
				[ 'status' => 409 ]
			);
		}

		return $current;
	}

	/**
	 * Delegates to the shared StructuralOperationsApplier (three fail-closed
	 * phases, lexicographic-descending single pass). Kept as a private seam so
	 * the ordering + fail-closed guards remain provable per-executor by test.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function apply_operations( array $blocks, array $operations ): array|\WP_Error {
		return StructuralOperationsApplier::apply_operations( $blocks, $operations );
	}

	/**
	 * Under one exact-target lock, re-resolve and re-gate the live entity, persist
	 * the mutated content, and capture the stored content before releasing. A
	 * same-content materialization by another actor before lock acquisition is
	 * therefore re-resolved, while divergent content fails closed without writes.
	 *
	 * @return array{entity: object, content: string}|\WP_Error
	 */
	private static function persist( string $canonical_ref, string $content, string $expected_hash ): array|\WP_Error {
		$lock = MaterializationLock::acquire( 'template', $canonical_ref );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$template = self::assert_template_unchanged( $canonical_ref, $expected_hash );

			if ( is_wp_error( $template ) ) {
				return $template;
			}

			$wp_id     = (int) ( $template->wp_id ?? 0 );
			$persisted = $wp_id > 0
				? self::persist_existing_template( $wp_id, $content )
				: self::materialize_template( $template, $content, $expected_hash, $canonical_ref );

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			$persisted_state = self::resolve_persisted_state( $canonical_ref, $persisted );

			if ( is_wp_error( $persisted_state ) ) {
				return $persisted_state;
			}

			return $persisted_state;
		} finally {
			$lock->release();
		}
	}

	/**
	 * Update one DB-backed template while its exact target lock is already held.
	 *
	 * @return int|\WP_Error The persisted post id.
	 */
	private static function persist_existing_template( int $wp_id, string $content ): int|\WP_Error {
		$updated = wp_update_post(
			[
				'ID'           => $wp_id,
				'post_content' => $content,
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( 0 === (int) $updated ) {
			return new \WP_Error(
				'flavor_agent_apply_write_failed',
				'Flavor Agent could not write the template entity.',
				[ 'status' => 500 ]
			);
		}

		self::invalidate_template_cache( (int) $updated );

		return (int) $updated;
	}

	/**
	 * Materialize one file-backed template while its exact target lock is held.
	 *
	 * @return int|\WP_Error The persisted post id.
	 */
	private static function materialize_template( object $template, string $content, string $expected_hash, string $canonical_ref ): int|\WP_Error {
		// Normalize through core's own post_name normalizer so the post-insert
		// read-back compares like with like. sanitize_key() keeps `--` and edge
		// dashes that sanitize_title() -- which wp_insert_post applies to
		// post_name -- collapses and trims; that divergence reads as a phantom
		// slug collision. sanitize_title() is idempotent on its own output, so
		// this is exactly what core will store, and it is also what
		// reconcile_existing_row() must probe with.
		$slug       = sanitize_title( sanitize_key( (string) ( $template->slug ?? '' ) ) );
		$stylesheet = function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '';
		$ref_parts  = 1 === substr_count( $canonical_ref, '//' ) ? explode( '//', $canonical_ref, 2 ) : [];
		$ref_theme  = 2 === count( $ref_parts ) ? $ref_parts[0] : '';
		$ref_slug   = 2 === count( $ref_parts ) ? $ref_parts[1] : '';

		if ( '' === $slug || '' === $stylesheet ) {
			return new \WP_Error(
				'flavor_agent_apply_write_failed',
				'Cannot materialize a template without a slug and active theme.',
				[ 'status' => 500 ]
			);
		}

		if ( '' === $ref_theme || '' === $ref_slug || $ref_theme !== $stylesheet || $ref_slug !== $slug ) {
			return self::target_mismatch();
		}

		// Duplicate-row guard: if a wp_template post already exists for this
		// slug + theme (a concurrent materialization), reconcile against it
		// instead of inserting a second row or blind-overwriting its content.
		$reconciled = self::reconcile_existing_row( $canonical_ref, $slug, $content, $expected_hash );

		if ( null !== $reconciled ) {
			return $reconciled;
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => (string) ( $template->title ?? $slug ),
				'post_content' => $content,
				'tax_input'    => [
					'wp_theme' => [ $ref_theme ],
				],
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( 0 === (int) $post_id ) {
			return new \WP_Error(
				'flavor_agent_apply_write_failed',
				'Flavor Agent could not materialize the template entity.',
				[ 'status' => 500 ]
			);
		}

		// Core scopes wp_template slug uniqueness to the active theme, so a suffixed
		// slug means another row already owns slug + theme. Drop the orphan we just
		// created, then decide what actually owns it.
		$inserted = get_post( (int) $post_id );

		// A failed read-back is not a slug collision. Falling into the collision
		// arm would delete a row that was almost certainly written correctly and
		// report a cause we know to be false, so fail closed and leave the row --
		// matching execute()'s existing post-persist read-back contract.
		if ( ! is_object( $inserted ) ) {
			return new \WP_Error(
				'flavor_agent_apply_post_write_read_failed',
				'Flavor Agent materialized the template but could not confirm its stored slug.',
				[ 'status' => 500 ]
			);
		}

		$inserted_slug = (string) ( $inserted->post_name ?? '' );

		if ( $slug !== $inserted_slug ) {
			$deleted = wp_delete_post( (int) $post_id, true );

			// wp_delete_post returns the deleted post, or false/null on failure --
			// but a pre_delete_post filter can short-circuit deletion while still
			// returning a WP_Post. Confirm the row is actually gone rather than
			// trusting the return value: otherwise we strand the duplicate here
			// and then also update the winning row below.
			if ( ! $deleted || is_object( get_post( (int) $post_id ) ) ) {
				return new \WP_Error(
					'flavor_agent_apply_write_failed',
					'Flavor Agent detected a template slug collision but could not remove the duplicate row.',
					[ 'status' => 500 ]
				);
			}

			// A row that raced us to publish is visible now; reconcile against it.
			$reconciled = self::reconcile_existing_row( $canonical_ref, $slug, $content, $expected_hash );

			if ( null !== $reconciled ) {
				return $reconciled;
			}

			// Nothing published owns the slug, so this is not concurrency. Core's
			// uniquifier also counts non-published rows that the publish-only probe
			// cannot see. Report the real cause instead of phantom concurrency.
			return new \WP_Error(
				'flavor_agent_apply_slug_conflict',
				'Another template already uses this slug for the active theme. Resolve the conflicting template before applying.',
				[ 'status' => 409 ]
			);
		}

		self::invalidate_template_cache( (int) $post_id );
		$materialized = ServerCollector::resolve_template_for_attestation( $canonical_ref );

		if ( ! is_object( $materialized ) ) {
			return new \WP_Error(
				'flavor_agent_apply_post_write_read_failed',
				'Flavor Agent materialized the template but could not confirm its canonical theme-qualified identity.',
				[ 'status' => 500 ]
			);
		}

		$materialized_id  = (int) ( $materialized->wp_id ?? 0 );
		$materialized_ref = (string) ( $materialized->id ?? '' );

		if ( $canonical_ref !== $materialized_ref || $materialized_id !== (int) $post_id ) {
			$deleted = wp_delete_post( (int) $post_id, true );

			if ( ! $deleted || is_object( get_post( (int) $post_id ) ) ) {
				return new \WP_Error(
					'flavor_agent_apply_write_failed',
					'Flavor Agent could not remove a template row whose canonical identity was not confirmed.',
					[ 'status' => 500 ]
				);
			}

			if ( $canonical_ref === $materialized_ref && $materialized_id > 0 ) {
				$reconciled = self::reconcile_existing_row( $canonical_ref, $slug, $content, $expected_hash );

				if ( null !== $reconciled ) {
					return $reconciled;
				}
			}

			return new \WP_Error(
				'flavor_agent_apply_post_write_read_failed',
				'Flavor Agent materialized the template but could not confirm its canonical theme-qualified identity.',
				[ 'status' => 500 ]
			);
		}

		return (int) $post_id;
	}

	/**
	 * Reconcile a first materialization against a wp_template row that another
	 * actor created for the same slug + theme between our read and our write.
	 *
	 * Searches all slug candidates for the canonical ref. Returns null when no
	 * row owns the slug, so the caller may insert; an unrelated-only result fails
	 * closed. A canonical row is accepted idempotently, rejected when it diverged
	 * from the validated baseline, or updated in place when the baseline matches.
	 *
	 * @return int|\WP_Error|null
	 */
	private static function reconcile_existing_row( string $canonical_ref, string $slug, string $content, string $expected_hash ): int|\WP_Error|null {
		$existing                  = get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template' );
		$found_unrelated_candidate = false;

		foreach ( $existing as $candidate ) {
			if ( ! is_object( $candidate ) || $canonical_ref !== (string) ( $candidate->id ?? '' ) ) {
				$found_unrelated_candidate = true;
				continue;
			}

			$candidate_wp_id = (int) ( $candidate->wp_id ?? 0 );

			if ( $candidate_wp_id <= 0 ) {
				continue;
			}

			$candidate_hash = self::content_hash( (string) ( $candidate->content ?? '' ) );

			// Already the state we intended to write: accept without rewriting.
			if ( hash_equals( self::content_hash( $content ), $candidate_hash ) ) {
				return $candidate_wp_id;
			}

			// Diverged from the baseline this apply was validated against, so the
			// operations were never checked against what is actually stored.
			if ( ! hash_equals( $expected_hash, $candidate_hash ) ) {
				return new \WP_Error(
					'flavor_agent_apply_target_changed',
					'The template changed while Flavor Agent was materializing it. Regenerate the request and try again.',
					[ 'status' => 409 ]
				);
			}

			return self::persist_existing_template( $candidate_wp_id, $content );
		}

		return $found_unrelated_candidate ? self::target_mismatch() : null;
	}

	private static function resolve_persisted_content( int $post_id ): string|\WP_Error {
		$post = $post_id > 0 && function_exists( 'get_post' ) ? get_post( $post_id ) : null;

		if ( ! is_object( $post ) || 'wp_template' !== (string) ( $post->post_type ?? '' ) ) {
			return new \WP_Error(
				'flavor_agent_apply_post_write_read_failed',
				'Flavor Agent wrote the template but could not confirm its persisted content.',
				[ 'status' => 500 ]
			);
		}

		return (string) ( $post->post_content ?? '' );
	}

	/**
	 * Re-resolve the exact canonical entity and capture its stored content while
	 * the target mutex is still held.
	 *
	 * @return array{entity: object, content: string}|\WP_Error
	 */
	private static function resolve_persisted_state( string $canonical_ref, int $post_id ): array|\WP_Error {
		$entity = ServerCollector::resolve_template_for_attestation( $canonical_ref );

		if ( ! self::is_exact_persisted_entity( $entity, $canonical_ref, $post_id ) ) {
			return new \WP_Error(
				'flavor_agent_apply_post_write_read_failed',
				'Flavor Agent wrote the template but could not confirm its canonical theme-qualified identity.',
				[ 'status' => 500 ]
			);
		}

		$content = self::resolve_persisted_content( $post_id );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return [
			'entity'  => $entity,
			'content' => $content,
		];
	}

	private static function is_exact_persisted_entity( mixed $entity, string $canonical_ref, int $post_id ): bool {
		return is_object( $entity )
			&& $canonical_ref === (string) ( $entity->id ?? '' )
			&& $post_id === (int) ( $entity->wp_id ?? 0 );
	}

	/**
	 * Invalidate the post cache after a write. In core, clean_post_cache() busts
	 * the 'posts' last_changed value that the wp_get_block_templates query cache
	 * keys on, so the block-template resolution path re-reads fresh content.
	 */
	private static function invalidate_template_cache( int $post_id ): void {
		if ( $post_id > 0 && function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
	}

	private function __construct() {}
}
