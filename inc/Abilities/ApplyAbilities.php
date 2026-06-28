<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Activity\RecommendationOutcome;
use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Apply\ExternalApplyExecutorRegistry;
use FlavorAgent\Apply\StyleApplyExecutor;
use FlavorAgent\LLM\StylePrompt;
use FlavorAgent\Support\NormalizesInput;

/**
 * Handlers for the external-apply abilities: request-style-apply,
 * get-activity, list-activity, undo-activity.
 */
final class ApplyAbilities {
	use NormalizesInput;

	private const PENDING_CAP_FILTER  = 'flavor_agent_external_apply_pending_cap';
	private const PENDING_TTL_FILTER  = 'flavor_agent_external_apply_pending_ttl';
	private const DEFAULT_PENDING_CAP = 10;

	public static function request_style_apply( mixed $input ): array|\WP_Error {
		$input             = self::normalize_map( $input );
		$scope             = self::normalize_map( $input['scope'] ?? [] );
		$style_context     = self::normalize_map( $input['styleContext'] ?? [] );
		$prompt            = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : '';
		$operations        = self::normalize_list( $input['operations'] ?? [] );
		$signatures        = self::normalize_map( $input['signatures'] ?? [] );
		$provided_resolved = sanitize_text_field( (string) ( $signatures['resolvedContextSignature'] ?? '' ) );
		$provided_review   = sanitize_text_field( (string) ( $signatures['reviewContextSignature'] ?? '' ) );
		$surface           = sanitize_key( (string) ( $scope['surface'] ?? '' ) );
		$global_styles_id  = sanitize_text_field( (string) ( $scope['globalStylesId'] ?? '' ) );
		$block_name        = sanitize_text_field( (string) ( $scope['blockName'] ?? '' ) );

		if ( ! in_array( $surface, [ 'global-styles', 'style-book' ], true ) || '' === $global_styles_id ) {
			return new \WP_Error(
				'invalid_style_scope',
				'External style applies require a global-styles or style-book scope with a Global Styles entity id.',
				[ 'status' => 400 ]
			);
		}

		if ( 'style-book' === $surface && '' === $block_name ) {
			return new \WP_Error(
				'invalid_style_scope',
				'External Style Book applies require a target block name.',
				[ 'status' => 400 ]
			);
		}

		if ( [] === $operations ) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'External style applies require at least one executable operation.',
				[ 'status' => 400 ]
			);
		}

		if ( '' === $provided_resolved || '' === $provided_review ) {
			return new \WP_Error(
				'flavor_agent_apply_stale',
				'External style applies require the resolved and review context signatures from the recommendation response.',
				[ 'status' => 409 ]
			);
		}

		// First freshness gate: recompute both signatures from the provided
		// request input through the same signature-only path the editor uses.
		$signature_probe = StyleAbilities::recommend_style(
			[
				'scope'                => $scope,
				'styleContext'         => $style_context,
				'prompt'               => $prompt,
				'resolveSignatureOnly' => true,
			]
		);

		if ( is_wp_error( $signature_probe ) ) {
			return $signature_probe;
		}

		$recomputed_resolved = (string) ( $signature_probe['resolvedContextSignature'] ?? '' );
		$recomputed_review   = (string) ( $signature_probe['reviewContextSignature'] ?? '' );

		if (
			! hash_equals( $recomputed_resolved, $provided_resolved )
			|| ! hash_equals( $recomputed_review, $provided_review )
		) {
			self::persist_stale_blocked_outcome( $surface, $global_styles_id, $block_name, $provided_resolved, 'external_apply_signature_stale' );

			return self::stale_error();
		}

		// Second freshness gate: the claimed current config must equal the live entity.
		$resolved_entity = StyleApplyExecutor::resolve_user_global_styles( $global_styles_id );

		if ( is_wp_error( $resolved_entity ) ) {
			return $resolved_entity;
		}

		$claimed_config = self::normalize_map( $style_context['currentConfig'] ?? [] );

		if ( StyleApplyExecutor::comparable_config( $claimed_config ) !== StyleApplyExecutor::comparable_config( $resolved_entity['config'] ) ) {
			self::persist_stale_blocked_outcome( $surface, $global_styles_id, $block_name, $provided_resolved, 'external_apply_config_drift' );

			return self::stale_error();
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$cap     = max( 1, (int) apply_filters( self::PENDING_CAP_FILTER, self::DEFAULT_PENDING_CAP ) );

		if ( ActivityRepository::count_active_pending_external_applies( $user_id ) >= $cap ) {
			return new \WP_Error(
				'flavor_agent_apply_queue_full',
				sprintf(
					'You already have %d pending external applies awaiting review. Wait for a decision or expiry before requesting more.',
					$cap
				),
				[ 'status' => 429 ]
			);
		}

		$validation_context = StyleApplyExecutor::build_validation_context( $surface, $block_name );

		if ( is_wp_error( $validation_context ) ) {
			return $validation_context;
		}

		$validated = StylePrompt::validate_operations_for_apply( $operations, $validation_context );

		if ( [] === $validated['operations'] || count( $validated['operations'] ) !== count( $operations ) ) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'One or more proposed style operations failed validation against the current execution contract.',
				[
					'status'            => 400,
					'validationReasons' => $validated['reasons'],
				]
			);
		}

		$suggestion        = self::normalize_map( $input['suggestion'] ?? [] );
		$timestamp         = gmdate( 'c' );
		$day_in_seconds    = defined( 'DAY_IN_SECONDS' ) ? \DAY_IN_SECONDS : 86400;
		$ttl               = max( 60, (int) apply_filters( self::PENDING_TTL_FILTER, $day_in_seconds ) );
		$expires_at        = gmdate( 'c', time() + $ttl );
		$scope_key         = StyleAbilities::canonical_scope_key_for( $surface, $global_styles_id, $block_name );
		$request_reference = sanitize_text_field( (string) ( $input['requestReference'] ?? '' ) );

		$created = ActivityRepository::create(
			[
				'type'            => 'style-book' === $surface ? 'apply_style_book_suggestion' : 'apply_global_styles_suggestion',
				'surface'         => $surface,
				'target'          => 'style-book' === $surface
					? [
						'globalStylesId' => $global_styles_id,
						'blockName'      => $block_name,
						'blockTitle'     => sanitize_text_field( (string) ( $scope['blockTitle'] ?? '' ) ),
					]
					: [ 'globalStylesId' => $global_styles_id ],
				'suggestion'      => sanitize_text_field( (string) ( $suggestion['label'] ?? 'External style apply request' ) ),
				'before'          => [],
				'after'           => [],
				'executionResult' => 'pending',
				'undo'            => [ 'status' => 'not_applicable' ],
				'timestamp'       => $timestamp,
				'request'         => [
					'prompt'      => $prompt,
					'reference'   => '' !== $request_reference ? $request_reference : 'external-apply:' . $scope_key,
					'requestMeta' => [
						'ability'            => 'flavor-agent/request-style-apply',
						'executionTransport' => 'wp-abilities',
						'route'              => 'wp-abilities:flavor-agent/request-style-apply',
					],
					'apply'       => [
						'status'           => 'pending',
						'requestedBy'      => $user_id,
						'requestedAt'      => $timestamp,
						'expiresAt'        => $expires_at,
						'operations'       => $validated['operations'],
						'signatures'       => [
							'resolvedContextSignature' => $provided_resolved,
							'reviewContextSignature'   => $provided_review,
							'baselineConfigHash'       => StyleApplyExecutor::comparable_config_hash( $resolved_entity['config'] ),
						],
						'requestReference' => $request_reference,
					],
				],
				'document'        => [
					'scopeKey'   => $scope_key,
					'postType'   => 'global_styles',
					'entityId'   => $global_styles_id,
					'entityKind' => 'style-book' === $surface ? 'block' : 'root',
					'entityName' => 'style-book' === $surface ? 'styleBook' : 'globalStyles',
				],
			]
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return [
			'activityId'       => (string) ( $created['id'] ?? '' ),
			'status'           => 'pending',
			'expiresAt'        => $expires_at,
			'requestReference' => $request_reference,
		];
	}

	public static function get_activity( mixed $input ): array|\WP_Error {
		$input       = self::normalize_map( $input );
		$activity_id = sanitize_text_field( (string) ( $input['activityId'] ?? '' ) );

		if ( '' === $activity_id ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_entry',
				'getActivity requires an activityId.',
				[ 'status' => 400 ]
			);
		}

		$entry = ActivityRepository::find( $activity_id );

		if ( ! is_array( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_found',
				'Flavor Agent could not find that activity entry.',
				[ 'status' => 404 ]
			);
		}

		$entry    = ActivityRepository::maybe_expire_pending_apply( $entry );
		$response = [ 'entry' => $entry ];

		if ( is_array( $entry['attestation'] ?? null ) ) {
			$response['attestation'] = $entry['attestation'];
		}

		return $response;
	}

	public static function list_activity( mixed $input ): array|\WP_Error {
		$input     = self::normalize_map( $input );
		$scope_key = sanitize_text_field( (string) ( $input['scopeKey'] ?? '' ) );

		if ( '' === $scope_key ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_entry',
				'listActivity requires a scopeKey; admin-global reads stay on the REST activity route.',
				[ 'status' => 400 ]
			);
		}

		$status  = sanitize_key( (string) ( $input['status'] ?? '' ) );
		$entries = ActivityRepository::query(
			[
				'scopeKey' => $scope_key,
				'surface'  => sanitize_key( (string) ( $input['surface'] ?? '' ) ),
				'limit'    => $input['limit'] ?? ActivityRepository::DEFAULT_PER_PAGE,
			]
		);
		$entries = array_map( [ ActivityRepository::class, 'maybe_expire_pending_apply' ], $entries );

		if ( '' !== $status ) {
			$entries = array_values(
				array_filter(
					$entries,
					static fn ( array $entry ): bool => self::entry_matches_status( $entry, $status )
				)
			);
		}

		return [ 'entries' => $entries ];
	}

	public static function undo_activity( mixed $input ): array|\WP_Error {
		$input       = self::normalize_map( $input );
		$activity_id = sanitize_text_field( (string) ( $input['activityId'] ?? '' ) );

		if ( '' === $activity_id ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_entry',
				'undoActivity requires an activityId.',
				[ 'status' => 400 ]
			);
		}

		$entry = ActivityRepository::find( $activity_id );

		if ( ! is_array( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_found',
				'Flavor Agent could not find that activity entry.',
				[ 'status' => 404 ]
			);
		}

		$entry    = ActivityRepository::maybe_expire_pending_apply( $entry );
		$surface  = (string) ( $entry['surface'] ?? '' );
		$executor = ExternalApplyExecutorRegistry::for_surface( $surface );

		if ( null === $executor ) {
			return new \WP_Error(
				'flavor_agent_undo_surface_unsupported',
				'External undo is not supported for this activity surface.',
				[ 'status' => 400 ]
			);
		}

		if ( self::is_non_executed_apply_entry( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_undoable',
				'Pending, rejected, expired, and approval-failed external applies never executed and cannot be undone.',
				[ 'status' => 409 ]
			);
		}

		$undo_status = (string) ( $entry['undo']['status'] ?? '' );

		if ( 'undone' === $undo_status ) {
			// Idempotent success report without rewriting the terminal row.
			return [
				'entry'  => $entry,
				'result' => 'already_undone',
				'error'  => null,
			];
		}

		if ( 'available' !== $undo_status ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_undo_transition',
				'Flavor Agent only allows undo status changes from the available state.',
				[ 'status' => 409 ]
			);
		}

		if ( ! ActivityRepository::can_perform_ordered_undo( $activity_id ) ) {
			return new \WP_Error(
				'flavor_agent_activity_undo_blocked',
				'Undo blocked by newer AI actions.',
				[ 'status' => 409 ]
			);
		}

		$result = $executor::undo( $entry );

		if ( is_wp_error( $result ) ) {
			if ( 'flavor_agent_undo_drift' === $result->get_error_code() ) {
				$failed = ActivityRepository::update_undo_status( $activity_id, 'failed', $result->get_error_message() );

				return [
					'entry'  => is_array( $failed ) ? $failed : $entry,
					'result' => 'failed',
					'error'  => $result->get_error_message(),
				];
			}

			return $result;
		}

		$updated = ActivityRepository::update_undo_status( $activity_id, 'undone' );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$prior = \FlavorAgent\Attestation\Repository::find_by_related_activity( $activity_id );

		if ( null !== $prior ) {
			try {
				\FlavorAgent\Attestation\AttestationService::record_revert(
					(string) $prior['attestation_id'],
					[
						'surface'            => (string) ( $entry['surface'] ?? '' ),
						'globalStylesId'     => (string) ( $entry['target']['globalStylesId'] ?? '' ),
						'blockName'          => (string) ( $entry['target']['blockName'] ?? '' ),
						'operations'         => [],
						'before'             => $entry['after'] ?? [],
						'after'              => $entry['before'] ?? [],
						'freshnessSignature' => '',
						'actorRole'          => self::actor_role_for_undo(),
						'requestedAt'        => '',
						'decidedAt'          => gmdate( 'c' ),
						'relatedActivityId'  => $activity_id,
					]
				);
			} catch ( \Throwable $e ) {
				\FlavorAgent\Attestation\AttestationService::record_failure(
					$e,
					[
						'operation'            => 'revert',
						'activityId'           => $activity_id,
						'revertsAttestationId' => (string) $prior['attestation_id'],
					]
				);
			}
		}

		return [
			'entry'  => $updated,
			'result' => 'already_undone' === (string) ( $result['result'] ?? '' ) ? 'already_undone' : 'undone',
			'error'  => null,
		];
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function is_non_executed_apply_entry( array $entry ): bool {
		$execution_result = (string) ( $entry['executionResult'] ?? '' );

		if ( in_array( $execution_result, [ 'pending', 'rejected', 'expired' ], true ) ) {
			return true;
		}

		return 'failed' === $execution_result
			&& '' === (string) ( $entry['apply']['executedAt'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function entry_matches_status( array $entry, string $status ): bool {
		$execution_result = (string) ( $entry['executionResult'] ?? '' );
		$undo_status      = (string) ( $entry['undo']['status'] ?? '' );

		return match ( $status ) {
			'pending', 'rejected', 'expired' => $status === $execution_result,
			'failed' => 'failed' === $execution_result || 'failed' === $undo_status,
			'undone' => 'undone' === $undo_status,
			'applied' => 'applied' === $execution_result && ! in_array( $undo_status, [ 'undone', 'failed' ], true ),
			default => true,
		};
	}

	private static function stale_error(): \WP_Error {
		return new \WP_Error(
			'flavor_agent_apply_stale',
			'The style recommendation context is stale. Re-run flavor-agent/recommend-style and request the apply again with fresh signatures.',
			[ 'status' => 409 ]
		);
	}

	private static function actor_role_for_undo(): string {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return '';
		}

		$user  = get_userdata( $user_id );
		$roles = is_object( $user ) && is_array( $user->roles ?? null ) ? $user->roles : [];

		return isset( $roles[0] ) ? sanitize_key( (string) $roles[0] ) : '';
	}

	private static function persist_stale_blocked_outcome( string $surface, string $global_styles_id, string $block_name, string $source_signature, string $reason ): void {
		$created = ActivityRepository::create(
			[
				'type'     => RecommendationOutcome::TYPE,
				'surface'  => $surface,
				'target'   => 'style-book' === $surface ? [ 'blockName' => $block_name ] : [],
				'after'    => [
					'outcome' => [
						'event'                  => 'stale_blocked',
						'reason'                 => $reason,
						'sourceRequestSignature' => $source_signature,
					],
				],
				'document' => [
					'scopeKey' => StyleAbilities::canonical_scope_key_for( $surface, $global_styles_id, $block_name ),
					'postType' => 'global_styles',
					'entityId' => $global_styles_id,
				],
			]
		);

		unset( $created ); // Diagnostic persistence is best-effort, mirroring the editor.
	}
}
