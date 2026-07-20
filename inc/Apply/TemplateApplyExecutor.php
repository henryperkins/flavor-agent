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
		$ref      = self::template_ref( $entry );
		$type     = self::template_type( $entry );
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

		// Final concurrency gate: re-resolve and fail closed if the live content
		// moved; return the fresh entity so persist writes against the current wp_id.
		$fresh = self::assert_template_unchanged( $ref, $before_hash );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$persisted = self::persist( $fresh, $after_content, $before_hash );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		$persisted_content = self::resolve_persisted_content( $persisted );

		if ( is_wp_error( $persisted_content ) ) {
			return $persisted_content;
		}

		return [
			// Identity comes from the re-gated entity, not the start-of-execute
			// read: this is the value that lands in the activity row and the
			// Ring III attestation subject, so it must describe what was written.
			'target' => [
				'templateRef'  => (string) ( $fresh->id ?? $ref ),
				'templateType' => $type,
				'slug'         => (string) ( $fresh->slug ?? '' ),
				'title'        => (string) ( $fresh->title ?? '' ),
			],
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
		$content = self::resolve_live_content( self::template_ref( $entry ) );

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
		$ref      = self::template_ref( $entry );
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

		$fresh = self::assert_template_unchanged( $ref, $live_hash );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$persisted = self::persist( $fresh, (string) $before['content'], $live_hash );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		$persisted_content = self::resolve_persisted_content( $persisted );

		if ( is_wp_error( $persisted_content ) ) {
			return $persisted_content;
		}

		return [
			'result' => 'undone',
			'after'  => [ 'content' => $persisted_content ],
		];
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function template_ref( array $entry ): string {
		$target = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];

		return trim( (string) ( $target['templateRef'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function template_type( array $entry ): string {
		$target = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];

		return sanitize_key( (string) ( $target['templateType'] ?? '' ) );
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
	 * Persist the mutated content: update a DB-backed template in place, or
	 * materialize a theme-file template into a wp_template post on first apply.
	 * Receives the entity re-resolved by the concurrency gate, so a same-content
	 * materialization by another actor between read and write updates in place
	 * rather than inserting a duplicate. Fails closed; invalidates caches.
	 *
	 * @return int|\WP_Error The persisted post id.
	 */
	private static function persist( object $template, string $content, string $expected_hash ): int|\WP_Error {
		$wp_id = (int) ( $template->wp_id ?? 0 );

		if ( $wp_id > 0 ) {
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

		// Normalize through core's own post_name normalizer so the post-insert
		// read-back compares like with like. sanitize_key() keeps `--` and edge
		// dashes that sanitize_title() -- which wp_insert_post applies to
		// post_name -- collapses and trims; that divergence reads as a phantom
		// slug collision. sanitize_title() is idempotent on its own output, so
		// this is exactly what core will store, and it is also what
		// reconcile_existing_row() must probe with.
		$slug       = sanitize_title( sanitize_key( (string) ( $template->slug ?? '' ) ) );
		$stylesheet = function_exists( 'get_stylesheet' ) ? sanitize_key( (string) get_stylesheet() ) : '';

		if ( '' === $slug || '' === $stylesheet ) {
			return new \WP_Error(
				'flavor_agent_apply_write_failed',
				'Cannot materialize a template without a slug and active theme.',
				[ 'status' => 500 ]
			);
		}

		// Duplicate-row guard: if a wp_template post already exists for this
		// slug + theme (a concurrent materialization), reconcile against it
		// instead of inserting a second row or blind-overwriting its content.
		$reconciled = self::reconcile_existing_row( $slug, $content, $expected_hash );

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
					'wp_theme' => [ $stylesheet ],
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
			$reconciled = self::reconcile_existing_row( $slug, $content, $expected_hash );

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

		return (int) $post_id;
	}

	/**
	 * Reconcile a first materialization against a wp_template row that another
	 * actor created for the same slug + theme between our read and our write.
	 *
	 * Returns null when no materialized row owns the slug, so the caller may
	 * insert. Otherwise accepts an already-desired state idempotently, fails
	 * closed when the row diverged from the baseline we validated against, and
	 * updates in place when it still matches that baseline.
	 *
	 * @return int|\WP_Error|null
	 */
	private static function reconcile_existing_row( string $slug, string $content, string $expected_hash ): int|\WP_Error|null {
		$existing = get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template' );

		foreach ( $existing as $candidate ) {
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

			return self::persist( (object) [ 'wp_id' => $candidate_wp_id ], $content, $expected_hash );
		}

		return null;
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
