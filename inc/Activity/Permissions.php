<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

final class Permissions {

	public static function can_access_activity_request( \WP_REST_Request $request ): bool {
		$activity_id = trim( (string) $request->get_param( 'id' ) );

		if ( '' !== $activity_id ) {
			$entry = Repository::find( $activity_id );

			if ( is_array( $entry ) ) {
				return self::can_access_entry( $entry );
			}

			return self::can_access_context(
				self::resolve_request_context( $request )
			);
		}

		$entry = $request->get_param( 'entry' );

		if ( is_array( $entry ) || is_object( $entry ) ) {
			return self::can_access_context(
				self::resolve_entry_context(
					is_array( $entry ) ? $entry : get_object_vars( $entry )
				)
			);
		}

		return self::can_access_context(
			self::resolve_request_context( $request )
		);
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	public static function can_access_entry( array $entry ): bool {
		return self::can_access_context( self::resolve_entry_context( $entry ) );
	}

	public static function forbidden_error(): \WP_Error {
		return new \WP_Error(
			'flavor_agent_activity_forbidden',
			'Sorry, you are not allowed to access Flavor Agent activity.',
			[ 'status' => 403 ]
		);
	}

	private static function capability_for_context(
		string $scope_key = '',
		string $surface = '',
		string $entity_type = ''
	): string {
		if (
			in_array( $surface, [ 'template', 'template-part' ], true )
			|| in_array( $entity_type, [ 'template', 'template-part' ], true )
			|| str_starts_with( $scope_key, 'wp_template:' )
			|| str_starts_with( $scope_key, 'wp_template_part:' )
		) {
			return 'edit_theme_options';
		}

		return 'edit_posts';
	}

	/**
	 * @return array{scopeKey: string, surface: string, entityType: string, postType: string, entityId: string}
	 */
	private static function resolve_request_context( \WP_REST_Request $request ): array {
		$scope_key   = trim( (string) $request->get_param( 'scopeKey' ) );
		$surface     = trim( (string) $request->get_param( 'surface' ) );
		$entity_type = trim( (string) $request->get_param( 'entityType' ) );
		$post_type   = '';
		$entity_id   = '';

		if ( '' === $post_type || '' === $entity_id ) {
			$parsed_scope = self::parse_scope_key( $scope_key );

			$post_type = '' !== $post_type ? $post_type : $parsed_scope['postType'];
			$entity_id = '' !== $entity_id ? $entity_id : $parsed_scope['entityId'];
		}

		return [
			'scopeKey'   => $scope_key,
			'surface'    => $surface,
			'entityType' => $entity_type,
			'postType'   => $post_type,
			'entityId'   => $entity_id,
		];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{scopeKey: string, surface: string, entityType: string, postType: string, entityId: string}
	 */
	private static function resolve_entry_context( array $entry ): array {
		$normalized_entry = Serializer::normalize_entry( $entry );
		$document         = is_array( $normalized_entry['document'] ?? null ) ? $normalized_entry['document'] : [];
		$entity           = Serializer::derive_entity( $normalized_entry );
		$scope_key        = trim( (string) ( $document['scopeKey'] ?? '' ) );
		$post_type        = trim( (string) ( $document['postType'] ?? '' ) );
		$entity_id        = trim( (string) ( $document['entityId'] ?? '' ) );

		if ( '' === $post_type || '' === $entity_id ) {
			$parsed_scope = self::parse_scope_key( $scope_key );

			$post_type = '' !== $post_type ? $post_type : $parsed_scope['postType'];
			$entity_id = '' !== $entity_id ? $entity_id : $parsed_scope['entityId'];
		}

		return [
			'scopeKey'   => $scope_key,
			'surface'    => trim( (string) ( $normalized_entry['surface'] ?? '' ) ),
			'entityType' => trim( (string) ( $entity['type'] ?? '' ) ),
			'postType'   => $post_type,
			'entityId'   => $entity_id,
		];
	}

	/**
	 * @param array{scopeKey?: string, surface?: string, entityType?: string, postType?: string, entityId?: string} $context
	 */
	private static function can_access_context( array $context ): bool {
		$scope_key   = trim( (string) ( $context['scopeKey'] ?? '' ) );
		$surface     = trim( (string) ( $context['surface'] ?? '' ) );
		$entity_type = trim( (string) ( $context['entityType'] ?? '' ) );
		$post_type   = trim( (string) ( $context['postType'] ?? '' ) );
		$entity_id   = trim( (string) ( $context['entityId'] ?? '' ) );
		$capability  = self::capability_for_context(
			$scope_key,
			$surface,
			$entity_type
		);

		if ( 'edit_theme_options' === $capability ) {
			return current_user_can( $capability );
		}

		if (
			'' !== $entity_id
			&& ctype_digit( $entity_id )
			&& self::is_post_entity_context( $scope_key, $post_type, $entity_type )
		) {
			return current_user_can( 'edit_post', (int) $entity_id );
		}

		return current_user_can( $capability );
	}

	/**
	 * @return array{postType: string, entityId: string}
	 */
	private static function parse_scope_key( string $scope_key ): array {
		if ( '' === $scope_key ) {
			return [
				'postType' => '',
				'entityId' => '',
			];
		}

		$parts = explode( ':', $scope_key, 2 );

		return [
			'postType' => trim( (string) ( $parts[0] ?? '' ) ),
			'entityId' => trim( (string) ( $parts[1] ?? '' ) ),
		];
	}

	private static function is_post_entity_context(
		string $scope_key,
		string $post_type,
		string $entity_type
	): bool {
		if ( in_array( $entity_type, [ 'template', 'template-part' ], true ) ) {
			return false;
		}

		$resolved_post_type = '' !== $post_type
			? $post_type
			: self::parse_scope_key( $scope_key )['postType'];

		return ! in_array(
			$resolved_post_type,
			[ 'wp_template', 'wp_template_part' ],
			true
		);
	}
}
