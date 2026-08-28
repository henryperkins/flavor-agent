<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Attestation\BlockContentCanonicalizer;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\TemplatePartPrompt;

/**
 * Server-side executor for governed external template-part structural applies.
 *
 * Mirrors StyleApplyExecutor: read the live part, re-validate operations and
 * expectedTarget fingerprints, mutate the parsed block tree atomically through
 * BlockTreeMutator, persist via core post APIs, and snapshot before/after
 * post_content. Live contract: `docs/reference/governance-layer.md` and
 * `docs/reference/abilities-and-routes.md`.
 *
 * `undo()` re-resolves the live part and restores the before snapshot under the
 * same equality semantics as StyleApplyExecutor::undo, completing the
 * resolve_baseline + execute + undo trio the ExternalApplyExecutor contract
 * requires.
 */
final class TemplatePartApplyExecutor implements ExternalApplyExecutor {

	/**
	 * @param array<string, mixed> $entry
	 * @return array{target: array{templatePartId: string, templatePartRef: string}, document: array{entityId: string, postType: string, scopeKey: string}}|\WP_Error
	 */
	public static function resolve_target_identity( array $entry ): array|\WP_Error {
		$target    = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
		$raw_ref   = $target['templatePartId'] ?? null;
		$has_alias = array_key_exists( 'templatePartRef', $target );
		$raw_alias = $has_alias ? $target['templatePartRef'] : null;
		$ref       = is_string( $raw_ref ) ? trim( $raw_ref ) : '';
		$alias     = is_string( $raw_alias ) ? trim( $raw_alias ) : '';

		if (
			'template-part' !== (string) ( $entry['surface'] ?? '' )
			|| ! is_string( $raw_ref )
			|| ( $has_alias && ! is_string( $raw_alias ) )
			|| '' === $ref
			|| ( $has_alias && ( '' === $alias || $ref !== $alias ) )
		) {
			return self::target_mismatch();
		}

		return [
			'target'   => [
				'templatePartId'  => $ref,
				'templatePartRef' => $ref,
			],
			'document' => [
				'entityId' => $ref,
				'postType' => 'wp_template_part',
				'scopeKey' => 'wp_template_part:' . $ref,
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
	 * Re-resolve the live part, re-validate every stored operation against a
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

		$ref  = $identity['target']['templatePartId'];
		$part = self::resolve_part( $ref );

		if ( is_wp_error( $part ) ) {
			return $part;
		}

		$before_content = (string) ( $part->content ?? '' );
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

		// Re-validate against a freshly collected live context. No filter seam:
		// a governed write path must not be interceptable.
		$context = ServerCollector::for_template_part( $ref );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$validated = TemplatePartPrompt::validate_operations_for_apply( $operations, $context );

		if (
			[] === $validated['operations']
			|| count( $validated['operations'] ) !== count( $operations )
		) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'One or more template-part operations failed re-validation against the live execution contract.',
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
					'slug' => (string) ( $fresh->slug ?? '' ),
					'area' => (string) ( $fresh->area ?? '' ),
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
	 * Re-resolve the live template part and return the gate-2 drift baseline:
	 * the sha256 of its parsed -> reserialized live post_content.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function resolve_baseline( array $entry ): string|\WP_Error {
		$identity = self::resolve_target_identity( $entry );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$content = self::resolve_live_content( $identity['target']['templatePartId'] );

		return is_wp_error( $content )
			? $content
			: self::content_hash( $content );
	}

	/**
	 * Resolve the live template-part content for public subject-state verification.
	 */
	public static function resolve_live_content( string $ref ): string|\WP_Error {
		$part = self::resolve_part( $ref );

		return is_wp_error( $part )
			? $part
			: (string) ( $part->content ?? '' );
	}

	/**
	 * Resolve the exact theme-qualified subject for public attestation checks.
	 */
	public static function resolve_attested_content( string $ref ): string|\WP_Error {
		$part = ServerCollector::resolve_template_part_for_attestation( $ref );

		if ( ! is_object( $part ) ) {
			return new \WP_Error(
				'flavor_agent_attestation_subject_unavailable',
				'The attested template part is not available under its exact theme-qualified id.',
				[ 'status' => 409 ]
			);
		}

		return (string) ( $part->content ?? '' );
	}

	/**
	 * Server-side undo with the exact equality semantics StyleApplyExecutor uses:
	 * re-resolve the live part, then live == before → already undone (no write);
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

		$ref  = $identity['target']['templatePartId'];
		$part = self::resolve_part( $ref );

		if ( is_wp_error( $part ) ) {
			return $part;
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

		$live_hash   = self::content_hash( (string) ( $part->content ?? '' ) );
		$before_hash = self::content_hash( (string) $before['content'] );
		$after_hash  = self::content_hash( (string) $after['content'] );

		if ( hash_equals( $live_hash, $before_hash ) ) {
			return [
				'result' => 'already_undone',
				'after'  => [ 'content' => (string) ( $part->content ?? '' ) ],
			];
		}

		if ( ! hash_equals( $live_hash, $after_hash ) ) {
			return new \WP_Error(
				'flavor_agent_undo_drift',
				'The template part changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
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
		return new \WP_Error( 'flavor_agent_apply_target_mismatch', 'The stored template-part target does not match its canonical document identity.', [ 'status' => 409 ] );
	}

	private static function target_forbidden(): \WP_Error {
		return new \WP_Error( 'flavor_agent_apply_target_forbidden', 'You are not allowed to apply changes to this template part.', [ 'status' => 403 ] );
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

		if ( $ref !== (string) ( $part->id ?? '' ) ) {
			return self::target_mismatch();
		}

		return $part;
	}

	private static function content_hash( string $content ): string {
		return BlockContentCanonicalizer::digest( $content );
	}

	/**
	 * Final concurrency gate, mirroring StyleApplyExecutor::assert_global_styles_entity_unchanged:
	 * re-resolve the live part immediately before a write and fail closed if its
	 * parsed -> reserialized content hash moved since the value captured at the start
	 * of the operation. Closes the read -> write window so a concurrent save is never
	 * silently overwritten.
	 *
	 * @return object|\WP_Error
	 */
	private static function assert_part_unchanged( string $ref, string $expected_hash ): object {
		$current = self::resolve_part( $ref );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$current_content = (string) ( $current->content ?? '' );

		if ( ! hash_equals( self::content_hash( $current_content ), $expected_hash ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_changed',
				'The template part changed before Flavor Agent could persist this operation. Regenerate the request and try again.',
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
		$lock = MaterializationLock::acquire( 'template-part', $canonical_ref );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$context = $lock->context();
			$part    = self::assert_part_unchanged( $canonical_ref, $expected_hash );

			if ( is_wp_error( $part ) ) {
				return $part;
			}

			if ( ! $context->matches_current() ) {
				return self::write_context_changed();
			}

			$wp_id = (int) ( $part->wp_id ?? 0 );

			if ( $wp_id > 0 ) {
				return self::persist_existing_template_part_after_gate(
					$wp_id,
					$content,
					$canonical_ref,
					$expected_hash,
					$context
				);
			}

			$persisted = self::materialize_template_part( $part, $content, $expected_hash, $canonical_ref, $context );

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			if ( is_array( $persisted ) ) {
				return $persisted;
			}

			$persisted_state = self::resolve_persisted_state( $canonical_ref, $persisted, $context );

			if ( is_wp_error( $persisted_state ) ) {
				return $persisted_state;
			}

			return $persisted_state;
		} finally {
			$lock->release();
		}
	}

	/**
	 * Capture the exact database bytes, then repeat the semantic template-part
	 * gate. WP_Block_Template content may include Block Hooks and therefore is
	 * not a safe byte-for-byte predicate for the underlying posts-table row.
	 *
	 * @return array{entity: object, content: string}|\WP_Error
	 */
	private static function persist_existing_template_part_after_gate(
		int $wp_id,
		string $content,
		string $canonical_ref,
		string $expected_hash,
		MaterializationWriteContext $context
	): array|\WP_Error {
		if ( ! $context->matches_current() ) {
			return self::write_context_changed();
		}

		self::invalidate_part_cache( $wp_id );

		$before    = $context->read_post_row( $wp_id );
		$ref_parts = explode( '//', $canonical_ref, 2 );

		if (
			! is_object( $before )
			|| 'wp_template_part' !== (string) ( $before->post_type ?? '' )
			|| (string) ( $ref_parts[1] ?? '' ) !== (string) ( $before->post_name ?? '' )
		) {
			return new \WP_Error(
				'flavor_agent_apply_target_changed',
				'The template part database row changed before Flavor Agent could capture its guarded write predicate.',
				[ 'status' => 409 ]
			);
		}

		$expected_content = (string) ( $before->post_content ?? '' );

		self::invalidate_part_cache( $wp_id );

		$current = self::assert_part_unchanged( $canonical_ref, $expected_hash );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		if ( ! $context->matches_current() ) {
			return self::write_context_changed();
		}

		if ( ! self::is_exact_persisted_entity( $current, $canonical_ref, $wp_id ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_changed',
				'The template part identity changed before Flavor Agent could persist this operation.',
				[ 'status' => 409 ]
			);
		}

		return self::persist_existing_template_part( $wp_id, $content, $canonical_ref, $expected_content, $context );
	}

	/**
	 * Update one DB-backed template part while its exact target lock is already held.
	 *
	 * @return array{entity: object, content: string}|\WP_Error
	 */
	private static function persist_existing_template_part(
		int $wp_id,
		string $content,
		string $canonical_ref,
		string $expected_content,
		MaterializationWriteContext $context
	): array|\WP_Error {
		if ( ! $context->matches_current() ) {
			return self::write_context_changed();
		}

		$before = $context->read_post_row( $wp_id );

		if ( ! is_object( $before ) || 'wp_template_part' !== (string) ( $before->post_type ?? '' ) ) {
			return new \WP_Error(
				'flavor_agent_apply_post_write_read_failed',
				'Flavor Agent could not capture the template part content immediately before writing it.',
				[ 'status' => 500 ]
			);
		}

		$before_content = (string) ( $before->post_content ?? '' );

		if ( ! hash_equals( $expected_content, $before_content ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_changed',
				'The template part changed after Flavor Agent completed its final live-content gate.',
				[ 'status' => 409 ]
			);
		}

		$prepared = BlockTemplateWritePreparer::prepare(
			[
				'ID'           => $wp_id,
				'post_type'    => 'wp_template_part',
				'post_content' => $content,
			],
			'wp_template_part',
			'template part'
		);

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( ! $context->matches_current() ) {
			return self::write_context_changed();
		}

		$ref_parts = explode( '//', $canonical_ref, 2 );
		$write     = ExistingPostContentWriter::update(
			$wp_id,
			'wp_template_part',
			(string) ( $ref_parts[1] ?? '' ),
			$expected_content,
			(string) $prepared['post_content'],
			'template-part',
			is_array( $prepared['meta_input'] ?? null ) ? $prepared['meta_input'] : [],
			$context
		);

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		self::invalidate_part_cache( $wp_id );

		if ( ! $context->matches_current() ) {
			return ExistingPostContentCompensator::recovery_required(
				'template-part',
				$wp_id,
				'Flavor Agent wrote the template part but the site database context changed before attestation.'
			);
		}

		$written = $context->read_post_row( $wp_id );

		if ( ! is_object( $written ) || 'wp_template_part' !== (string) ( $written->post_type ?? '' ) ) {
			return ExistingPostContentCompensator::recovery_required(
				'template-part',
				$wp_id,
				'Flavor Agent wrote the template part but could not capture the exact content needed for safe compensation.'
			);
		}

		$written_content = (string) $write['content'];

		if ( ! hash_equals( $written_content, (string) ( $written->post_content ?? '' ) ) ) {
			return ExistingPostContentCompensator::recovery_required(
				'template-part',
				$wp_id,
				'Flavor Agent did not reconcile content that changed after its conditional write.',
				409
			);
		}

		$entity = ServerCollector::resolve_template_part_for_attestation( $canonical_ref );

		if ( ! $context->matches_current() ) {
			return ExistingPostContentCompensator::recovery_required(
				'template-part',
				$wp_id,
				'Flavor Agent wrote the template part but the site database context changed during attestation.'
			);
		}

		if ( ! self::is_exact_persisted_entity( $entity, $canonical_ref, $wp_id ) ) {
			$restored = ExistingPostContentCompensator::restore(
				$wp_id,
				'wp_template_part',
				$written_content,
				$before_content,
				'template-part',
				$context
			);

			if ( is_wp_error( $restored ) ) {
				return $restored;
			}

			return ExistingPostContentCompensator::recovery_required(
				'template-part',
				$wp_id,
				'Flavor Agent restored the prior template part content, but the canonical post-write identity still requires reconciliation.'
			);
		}

		return [
			'entity'  => $entity,
			'content' => (string) ( $entity->content ?? '' ),
		];
	}

	/**
	 * Materialize one file-backed template part while its exact target lock is held.
	 *
	 * @return int|array{entity: object, content: string}|\WP_Error The persisted post id or a reconciled existing state.
	 */
	private static function materialize_template_part( object $part, string $content, string $expected_hash, string $canonical_ref, MaterializationWriteContext $context ): int|array|\WP_Error {
		if ( ! $context->matches_current() ) {
			return self::write_context_changed();
		}

		// Materialize a theme-file part into a wp_template_part post (Site Editor parity).
		// Normalize through core's own post_name normalizer so the post-insert
		// read-back compares like with like. sanitize_key() keeps `--` and edge
		// dashes that sanitize_title() -- which wp_insert_post applies to
		// post_name -- collapses and trims; that divergence reads as a phantom
		// slug collision. sanitize_title() is idempotent on its own output, so
		// this is exactly what core will store, and it is also what
		// reconcile_existing_row() must probe with.
		$slug       = sanitize_title( sanitize_key( (string) ( $part->slug ?? '' ) ) );
		$stylesheet = function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '';
		$ref_parts  = 1 === substr_count( $canonical_ref, '//' ) ? explode( '//', $canonical_ref, 2 ) : [];
		$ref_theme  = 2 === count( $ref_parts ) ? $ref_parts[0] : '';
		$ref_slug   = 2 === count( $ref_parts ) ? $ref_parts[1] : '';
		$area       = _filter_block_template_part_area( (string) ( $part->area ?? '' ) );
		$origin     = (string) ( $part->source ?? '' );

		if ( '' === $slug || '' === $stylesheet ) {
			return new \WP_Error(
				'flavor_agent_apply_write_failed',
				'Cannot materialize a template part without a slug and active theme.',
				[ 'status' => 500 ]
			);
		}

		if ( '' === $ref_theme || '' === $ref_slug || $ref_theme !== $stylesheet || $ref_slug !== $slug ) {
			return self::target_mismatch();
		}

		// If another approval materialized the same active-theme part after our
		// final read, reconcile against that row instead of creating a suffixed
		// orphan or blind-overwriting what the other actor wrote.
		$reconciled = self::reconcile_existing_row( $canonical_ref, $slug, $content, $expected_hash, $ref_theme, $area, $origin, $context );

		if ( null !== $reconciled ) {
			return $reconciled;
		}

		if ( ! $context->matches_current() ) {
			return self::write_context_changed();
		}

		$prepared = BlockTemplateWritePreparer::prepare(
			[
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => (string) ( $part->title ?? $slug ),
				'post_excerpt' => (string) ( $part->description ?? '' ),
				'post_content' => $content,
				'tax_input'    => [
					'wp_theme'              => $ref_theme,
					'wp_template_part_area' => $area,
				],
				'meta_input'   => [
					'origin' => $origin,
				],
			],
			'wp_template_part',
			'template part'
		);

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( ! $context->matches_current() ) {
			return self::write_context_changed();
		}

		$post_id = BlockTemplatePostInserter::insert( $prepared, 'template-part', $context );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( 0 === (int) $post_id ) {
			return new \WP_Error(
				'flavor_agent_apply_write_failed',
				'Flavor Agent could not materialize the template part entity.',
				[ 'status' => 500 ]
			);
		}

		if ( ! $context->matches_current() ) {
			return ExistingPostContentCompensator::recovery_required(
				'template-part',
				(int) $post_id,
				'Flavor Agent could not prove the target site context after materializing the template part.'
			);
		}

		$inserted = $context->read_post_row( (int) $post_id );

		if ( ! is_object( $inserted ) ) {
			return ExistingPostContentCompensator::recovery_required(
				'template-part',
				(int) $post_id,
				'Flavor Agent materialized the template part but could not confirm its exact stored row.'
			);
		}

		$inserted_slug = (string) ( $inserted->post_name ?? '' );

		if (
			'wp_template_part' !== (string) ( $inserted->post_type ?? '' )
			|| $slug !== $inserted_slug
			|| ! hash_equals( (string) $prepared['post_content'], (string) ( $inserted->post_content ?? '' ) )
			|| (string) ( $prepared['post_excerpt'] ?? '' ) !== (string) ( $inserted->post_excerpt ?? '' )
		) {
			return ExistingPostContentCompensator::recovery_required(
				'template-part',
				(int) $post_id,
				'Flavor Agent did not claim or remove the materialized template part because its final stored row changed during insertion.',
				409
			);
		}

		$side_effects_verified = MaterializedTemplateSideEffectVerifier::verify(
			(int) $post_id,
			$ref_theme,
			(string) ( $prepared['tax_input']['wp_template_part_area'] ?? '' ),
			(string) ( $prepared['meta_input']['origin'] ?? '' ),
			'template-part',
			$context
		);

		if ( is_wp_error( $side_effects_verified ) ) {
			return $side_effects_verified;
		}

		self::invalidate_part_cache( (int) $post_id );
		$materialized = ServerCollector::resolve_template_part_for_attestation( $canonical_ref );

		if ( ! $context->matches_current() ) {
			return ExistingPostContentCompensator::recovery_required(
				'template-part',
				(int) $post_id,
				'Flavor Agent materialized the template part but the site database context changed during identity attestation.'
			);
		}

		if ( ! is_object( $materialized ) ) {
			return ExistingPostContentCompensator::recovery_required(
				'template-part',
				(int) $post_id,
				'Flavor Agent materialized the template part but could not confirm its canonical theme-qualified identity.'
			);
		}

		$materialized_id  = (int) ( $materialized->wp_id ?? 0 );
		$materialized_ref = (string) ( $materialized->id ?? '' );

		if ( $canonical_ref !== $materialized_ref || $materialized_id !== (int) $post_id ) {
			return ExistingPostContentCompensator::recovery_required(
				'template-part',
				(int) $post_id,
				'Flavor Agent left the materialized template part unchanged because its canonical identity could not be proven.',
				409
			);
		}

		return (int) $post_id;
	}

	/**
	 * Reconcile a first materialization against a wp_template_part row that
	 * another actor created for the same slug + theme between our read and our
	 * write.
	 *
	 * Searches all slug candidates for the canonical ref. Returns null when no
	 * row owns the slug, so the caller may insert; an unrelated-only result fails
	 * closed. A canonical row is accepted idempotently, rejected when it diverged
	 * from the validated baseline, or updated in place when the baseline matches.
	 *
	 * @return array{entity: object, content: string}|\WP_Error|null
	 */
	private static function reconcile_existing_row(
		string $canonical_ref,
		string $slug,
		string $content,
		string $expected_hash,
		string $theme,
		string $area,
		string $origin,
		MaterializationWriteContext $context
	): array|\WP_Error|null {
		if ( ! $context->matches_current() ) {
			return self::write_context_changed();
		}

		$existing                  = get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template_part' );
		$found_unrelated_candidate = false;

		if ( ! $context->matches_current() ) {
			return self::write_context_changed();
		}

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
			$desired_hash   = self::content_hash( $content );

			// Diverged from the baseline this apply was validated against, so the
			// operations were never checked against what is actually stored.
			if ( ! hash_equals( $desired_hash, $candidate_hash ) && ! hash_equals( $expected_hash, $candidate_hash ) ) {
				return new \WP_Error(
					'flavor_agent_apply_target_changed',
					'The template part changed while Flavor Agent was materializing it. Regenerate the request and try again.',
					[ 'status' => 409 ]
				);
			}

			$side_effects_verified = MaterializedTemplateSideEffectVerifier::verify(
				$candidate_wp_id,
				$theme,
				$area,
				$origin,
				'template-part',
				$context
			);

			if ( is_wp_error( $side_effects_verified ) ) {
				return $side_effects_verified;
			}

			// Already the state we intended to write: accept without rewriting.
			if ( hash_equals( $desired_hash, $candidate_hash ) ) {
				return self::resolve_persisted_state( $canonical_ref, $candidate_wp_id, $context );
			}

			$persisted = self::persist_existing_template_part_after_gate( $candidate_wp_id, $content, $canonical_ref, $expected_hash, $context );

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			$side_effects_verified = MaterializedTemplateSideEffectVerifier::verify(
				$candidate_wp_id,
				$theme,
				$area,
				$origin,
				'template-part',
				$context
			);

			return is_wp_error( $side_effects_verified ) ? $side_effects_verified : $persisted;
		}

		return $found_unrelated_candidate ? self::target_mismatch() : null;
	}

	private static function resolve_persisted_content( int $post_id, MaterializationWriteContext $context ): string|\WP_Error {
		$post = $post_id > 0 ? $context->read_post_row( $post_id ) : null;

		if ( ! is_object( $post ) || 'wp_template_part' !== (string) ( $post->post_type ?? '' ) ) {
			return new \WP_Error(
				'flavor_agent_apply_post_write_read_failed',
				'Flavor Agent wrote the template part but could not confirm its persisted content.',
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
	private static function resolve_persisted_state( string $canonical_ref, int $post_id, MaterializationWriteContext $context ): array|\WP_Error {
		if ( ! $context->matches_current() ) {
			return self::write_context_changed();
		}

		$entity = ServerCollector::resolve_template_part_for_attestation( $canonical_ref );

		if ( ! $context->matches_current() ) {
			return ExistingPostContentCompensator::recovery_required(
				'template-part',
				$post_id,
				'Flavor Agent wrote the template part but the site database context changed during persisted-state resolution.'
			);
		}

		if ( ! self::is_exact_persisted_entity( $entity, $canonical_ref, $post_id ) ) {
			return new \WP_Error(
				'flavor_agent_apply_post_write_read_failed',
				'Flavor Agent wrote the template part but could not confirm its canonical theme-qualified identity.',
				[ 'status' => 500 ]
			);
		}

		// Prove the raw stored row still exists under the expected post type. The
		// entity view below is the semantic content source, including Block Hooks.
		$stored_row_proof = self::resolve_persisted_content( $post_id, $context );

		if ( is_wp_error( $stored_row_proof ) ) {
			return $stored_row_proof;
		}

		return [
			'entity'  => $entity,
			'content' => (string) ( $entity->content ?? '' ),
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
	private static function invalidate_part_cache( int $post_id ): void {
		if ( $post_id > 0 && function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
	}

	private static function write_context_changed(): \WP_Error {
		return new \WP_Error(
			'flavor_agent_apply_write_context_changed',
			'Flavor Agent stopped because the target site database context changed during template-part persistence.',
			[ 'status' => 409 ]
		);
	}

	private function __construct() {}
}
