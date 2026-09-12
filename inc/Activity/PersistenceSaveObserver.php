<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

/** Observe successful WordPress writes without initiating or vetoing them. */
final class PersistenceSaveObserver {

	private static array $requests  = [];
	private static array $deletions = [];

	public static function register(): void {
		add_filter( 'rest_request_before_callbacks', [ self::class, 'before_request' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'after_request' ], 10, 3 );
		add_action( 'wp_after_insert_post', [ self::class, 'after_save' ], 20, 4 );
		add_action( 'before_delete_post', [ self::class, 'before_delete' ], 20, 2 );
		add_action( 'after_delete_post', [ self::class, 'after_delete' ], 20, 2 );
		add_action( PersistenceOccurrenceRepository::CRON_HOOK, [ PersistenceWorker::class, 'run' ] );
	}

	public static function before_request( mixed $response, array $handler, \WP_REST_Request $request ): mixed {
		unset( $handler );
		self::$requests[ spl_object_id( $request ) ] = [
			'request' => $request,
			'seen'    => [],
		];
		return $response;
	}

	public static function after_request( mixed $response, array $handler, \WP_REST_Request $request ): mixed {
		unset( $handler, self::$requests[ spl_object_id( $request ) ] );
		return $response;
	}

	public static function after_save( int $post_id, object $post, bool $update, ?object $post_before ): void {
		unset( $update );
		if ( self::excluded( $post_id, $post, $post_before ) ) {
			return;
		}
		try {
			$entity = self::entity_from_post( $post );
			if ( null !== $entity ) {
				self::capture( $entity, (string) ( $post->post_content ?? '' ), 'saved' );
			}
		} catch ( \Throwable $error ) {
			self::record_capture_error( $error );
		}
	}

	/** Theme identity must be frozen before term relationships are removed. */
	public static function before_delete( int $post_id, object $post ): void {
		if ( ! in_array( $post->post_type ?? '', [ 'wp_template', 'wp_template_part' ], true ) || 'trash' === ( $post->post_status ?? '' ) ) {
			return;
		}
		$current = end( self::$requests );
		if ( is_array( $current ) && 'DELETE' === $current['request']->get_method() && ! $current['request']->get_param( 'force' ) ) {
			// EMPTY_TRASH_DAYS=0 can send ordinary trashing through a hard delete.
			return;
		}
		try {
			$entity = self::entity_from_post( $post );
			if ( null === $entity ) {
				return;
			}
			$frozen = [
				'entity'            => $entity,
				'schemas'           => self::schemas(),
				'candidateBeforeId' => PHP_INT_MAX,
				'candidateSince'    => gmdate( 'Y-m-d H:i:s', time() - PersistenceOccurrenceRepository::age_seconds() ),
			];
			$rows   = PersistenceOccurrenceRepository::candidate_rows( $frozen, 0, 1, 'DESC' );
			if ( [] !== $rows ) {
				$frozen['candidateBeforeId'] = (int) $rows[0]['id'];
				self::$deletions[ $post_id ] = $frozen;
			}
		} catch ( \Throwable $error ) {
			self::record_capture_error( $error );
		}
	}

	/** after_delete_post runs after cache cleanup; deleted_post does not. */
	public static function after_delete( int $post_id, object $post ): void {
		unset( $post );
		$frozen = self::$deletions[ $post_id ] ?? null;
		unset( self::$deletions[ $post_id ] );
		if ( ! is_array( $frozen ) ) {
			return;
		}
		try {
			$entity                     = $frozen['entity'];
			$fallback                   = get_block_template( $entity['ref'], $entity['postType'] );
			$entity['source']           = is_object( $fallback ) ? (string) ( $fallback->source ?? '' ) : 'missing';
			$entity['identityVerified'] = null === $fallback || ( is_object( $fallback ) && (string) ( $fallback->id ?? '' ) === $entity['ref'] && (string) ( $fallback->type ?? $entity['postType'] ) === $entity['postType'] && in_array( $entity['source'], [ 'theme', 'plugin', 'custom' ], true ) );
			self::capture( $entity, is_object( $fallback ) ? (string) ( $fallback->content ?? '' ) : '', 'reset_to_theme', $frozen );
		} catch ( \Throwable $error ) {
			self::record_capture_error( $error );
		}
	}

	public static function entity_from_post( object $post ): ?array {
		$id        = (int) ( $post->ID ?? 0 );
		$post_type = (string) ( $post->post_type ?? '' );
		if ( $id <= 0 || '' === $post_type ) {
			return null;
		}
		$entity = [
			'postId'           => $id,
			'postType'         => $post_type,
			'ref'              => (string) $id,
			'type'             => 'wp_global_styles' === $post_type ? 'global-styles' : 'post',
			'source'           => 'custom',
			'identityVerified' => true,
		];
		if ( in_array( $post_type, [ 'wp_template', 'wp_template_part' ], true ) ) {
			$themes = wp_get_object_terms( $id, 'wp_theme', [ 'fields' => 'names' ] );
			if ( is_wp_error( $themes ) || ! is_array( $themes ) || 1 !== count( $themes ) || '' === (string) ( $post->post_name ?? '' ) ) {
				return null;
			}
			$entity['theme'] = (string) $themes[0];
			$entity['slug']  = (string) $post->post_name;
			$entity['ref']   = $entity['theme'] . '//' . $entity['slug'];
			$entity['type']  = 'wp_template' === $post_type ? 'template' : 'template-part';
		}
		return $entity;
	}

	private static function excluded( int $post_id, object $post, ?object $before ): bool {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'revision' === ( $post->post_type ?? '' ) || 'trash' === ( $post->post_status ?? '' ) || 'trash' === ( $before->post_status ?? '' ) ) {
			return true;
		}
		if ( ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) || ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) ) {
			return true;
		}
		$current = end( self::$requests );
		return is_array( $current ) && 1 === preg_match( '#/(?:autosaves|revisions)(?:/|$)#', $current['request']->get_route() );
	}

	private static function capture( array $entity, string $content, string $reason, array $frozen = [] ): void {
		$occurrence = 'server-' . bin2hex( random_bytes( 16 ) );
		$origin     = 'unobserved';
		$key        = array_key_last( self::$requests );
		if ( null !== $key ) {
			$request = self::$requests[ $key ]['request'];
			if ( self::request_matches_entity( $request, $entity ) ) {
				$fingerprint = PersistenceOccurrenceRepository::entity_key( $entity ) . ':' . hash( 'sha256', $reason . ':' . $content );
				if ( isset( self::$requests[ $key ]['seen'][ $fingerprint ] ) ) {
					return;
				}
				self::$requests[ $key ]['seen'][ $fingerprint ] = true;
				$header = (string) $request->get_header( 'x-flavor-agent-save-occurrence' );
				if ( 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $header ) && null === PersistenceOccurrenceRepository::find_occurrence( $header, $entity ) ) {
					$occurrence = $header;
					$origin     = 'observed';
				}
			}
		}
		$result = PersistenceOccurrenceRepository::capture(
			[
				...$frozen,
				'entity'           => $entity,
				'content'          => $content,
				'schemas'          => $frozen['schemas'] ?? self::schemas(),
				'reason'           => $reason,
				'saveOccurrenceId' => $occurrence,
				'origin'           => $origin,
				'saverUserId'      => (int) get_current_user_id(),
			]
		);
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only a fixed code, never saved content or personal data.
			error_log( 'flavor_agent_save_capture_failed: ' . $result->get_error_code() );
		}
	}

	private static function request_matches_entity( \WP_REST_Request $request, array $entity ): bool {
		if ( ! in_array( $request->get_method(), [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
			return false;
		}
		$bases         = [
			'post'             => 'posts',
			'page'             => 'pages',
			'wp_template'      => 'templates',
			'wp_template_part' => 'template-parts',
			'wp_global_styles' => 'global-styles',
		];
		$type          = function_exists( 'get_post_type_object' ) ? get_post_type_object( $entity['postType'] ) : null;
		$fallback_base = is_object( $type ) ? $entity['postType'] : ( $bases[ $entity['postType'] ] ?? $entity['postType'] );
		$base          = ! empty( $type->rest_base ) ? (string) $type->rest_base : (string) $fallback_base;
		$namespace     = ! empty( $type->rest_namespace ) ? (string) $type->rest_namespace : 'wp/v2';
		$route         = rawurldecode( $request->get_route() );
		$prefix        = '/' . trim( $namespace, '/' ) . '/' . trim( $base, '/' );
		return $route === $prefix || $route === $prefix . '/' . $entity['ref'];
	}

	private static function schemas(): array {
		$schemas = [];
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( is_array( $type->attributes ?? null ) ) {
				$schemas[ $name ] = $type->attributes;
			}
		}
		return $schemas;
	}

	private static function record_capture_error( \Throwable $error ): void {
		unset( $error );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Observation must not turn a completed WordPress save into an error; omit exception data.
		error_log( 'flavor_agent_save_capture_failed' );
	}
}
