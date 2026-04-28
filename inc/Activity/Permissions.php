<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

final class Permissions {

	private const THEME_SURFACES = [
		'template',
		'template-part',
		'global-styles',
		'style-book',
		'navigation',
	];

	private const NON_POST_ENTITY_TYPES = [
		'template',
		'template-part',
		'global-styles',
		'style-book',
	];

	private const THEME_POST_TYPES = [
		'wp_template',
		'wp_template_part',
		'global_styles',
		'style_book',
	];

	private const NON_POST_THEME_POST_TYPES = [
		'wp_template',
		'wp_template_part',
		'global_styles',
	];

	public static function can_access_activity_request( \WP_REST_Request $request ): bool {
		if ( self::is_global_request( $request ) ) {
			return current_user_can( 'manage_options' );
		}

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
		$scope_type = self::parse_scope_key( $scope_key )['postType'];

		if ( '' !== $scope_type ) {
			return self::scope_type_requires_theme_capability( $scope_type )
				? 'edit_theme_options'
				: 'edit_posts';
		}

		if ( self::is_theme_surface_or_entity_type( $surface, $entity_type ) ) {
			return 'edit_theme_options';
		}

		return 'edit_posts';
	}

	private static function is_theme_surface_or_entity_type(
		string $surface,
		string $entity_type
	): bool {
		return in_array( $surface, self::THEME_SURFACES, true )
			|| in_array( $entity_type, self::THEME_SURFACES, true );
	}

	/**
	 * @return array{scopeKey: string, surface: string, entityType: string, postType: string, entityId: string, contextValid: bool}
	 */
	private static function resolve_request_context( \WP_REST_Request $request ): array {
		$scope_key   = trim( (string) $request->get_param( 'scopeKey' ) );
		$surface     = trim( (string) $request->get_param( 'surface' ) );
		$entity_type = trim( (string) $request->get_param( 'entityType' ) );
		$post_type   = trim( (string) $request->get_param( 'postType' ) );
		$entity_id   = trim( (string) $request->get_param( 'entityId' ) );
		$context     = self::resolve_canonical_context(
			$scope_key,
			$surface,
			$entity_type,
			$post_type,
			$entity_id
		);

		return [
			'scopeKey'     => $scope_key,
			'surface'      => $surface,
			'entityType'   => $entity_type,
			'postType'     => $context['postType'],
			'entityId'     => $context['entityId'],
			'contextValid' => $context['contextValid'],
		];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{scopeKey: string, surface: string, entityType: string, postType: string, entityId: string, contextValid: bool}
	 */
	private static function resolve_entry_context( array $entry ): array {
		$normalized_entry = Serializer::normalize_entry( $entry );
		$document         = is_array( $normalized_entry['document'] ?? null ) ? $normalized_entry['document'] : [];
		$entity           = Serializer::derive_entity( $normalized_entry );
		$scope_key        = trim( (string) ( $document['scopeKey'] ?? '' ) );
		$surface          = trim( (string) ( $normalized_entry['surface'] ?? '' ) );
		$entity_type      = trim( (string) ( $entity['type'] ?? '' ) );
		$post_type        = trim( (string) ( $document['postType'] ?? '' ) );
		$entity_id        = trim( (string) ( $document['entityId'] ?? '' ) );
		$context          = self::resolve_canonical_context(
			$scope_key,
			$surface,
			$entity_type,
			$post_type,
			$entity_id
		);

		return [
			'scopeKey'     => $scope_key,
			'surface'      => $surface,
			'entityType'   => $entity_type,
			'postType'     => $context['postType'],
			'entityId'     => $context['entityId'],
			'contextValid' => $context['contextValid'],
		];
	}

	/**
	 * @param array{scopeKey?: string, surface?: string, entityType?: string, postType?: string, entityId?: string, contextValid?: bool} $context
	 */
	private static function can_access_context( array $context ): bool {
		if ( isset( $context['contextValid'] ) && false === $context['contextValid'] ) {
			return false;
		}

		$scope_key   = trim( (string) ( $context['scopeKey'] ?? '' ) );
		$surface     = trim( (string) ( $context['surface'] ?? '' ) );
		$entity_type = trim( (string) ( $context['entityType'] ?? '' ) );
		$post_type   = trim( (string) ( $context['postType'] ?? '' ) );
		$entity_id   = trim( (string) ( $context['entityId'] ?? '' ) );

		// Defense in depth: theme-territory surfaces and entity types always
		// require edit_theme_options, AND post-scoped activity also requires
		// edit_post:N. Both gates apply — the user must satisfy each layer
		// the surface and scope demand. Without this guard, a post-scoped
		// scope_key would let a request with surface=navigation (or template,
		// template-part, global-styles, style-book) authorize against
		// edit_post:N alone and bypass the theme capability the surface
		// requires.
		if (
			self::is_theme_surface_or_entity_type( $surface, $entity_type )
			&& ! current_user_can( 'edit_theme_options' )
		) {
			return false;
		}

		$capability = self::capability_for_context(
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
	 * @return array{postType: string, entityId: string, contextValid: bool}
	 */
	private static function resolve_canonical_context(
		string $scope_key,
		string $surface,
		string $entity_type,
		string $post_type,
		string $entity_id
	): array {
		$parsed_scope   = self::parse_scope_key( $scope_key );
		$scope_type     = $parsed_scope['postType'];
		$scope_entity   = $parsed_scope['entityId'];
		$scope_is_theme = self::scope_type_requires_theme_capability( $scope_type )
			|| 'edit_theme_options' === self::capability_for_context(
				'' === $scope_type ? '' : $scope_key,
				$surface,
				$entity_type
			);
		$context_valid  = true;

		if ( '' !== $scope_type && '' !== $scope_entity ) {
			if ( ! $scope_is_theme ) {
				if ( '' !== $post_type && $post_type !== $scope_type ) {
					$context_valid = false;
				}

				if ( '' !== $entity_id && $entity_id !== $scope_entity ) {
					$context_valid = false;
				}

				$post_type = $scope_type;
				$entity_id = $scope_entity;
			} else {
				$post_type = '' !== $post_type ? $post_type : $scope_type;
				$entity_id = '' !== $entity_id ? $entity_id : $scope_entity;
			}
		} elseif ( '' === $post_type || '' === $entity_id ) {
			$post_type = '' !== $post_type ? $post_type : $scope_type;
			$entity_id = '' !== $entity_id ? $entity_id : $scope_entity;
		}

		return [
			'postType'     => $post_type,
			'entityId'     => $entity_id,
			'contextValid' => $context_valid,
		];
	}

	private static function scope_type_requires_theme_capability( string $scope_type ): bool {
		return in_array( $scope_type, self::THEME_POST_TYPES, true );
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
		if ( in_array( $entity_type, self::NON_POST_ENTITY_TYPES, true ) ) {
			return false;
		}

		$resolved_post_type = '' !== $post_type
			? $post_type
			: self::parse_scope_key( $scope_key )['postType'];

		return ! in_array( $resolved_post_type, self::NON_POST_THEME_POST_TYPES, true );
	}

	private static function is_global_request( \WP_REST_Request $request ): bool {
		$activity_id = trim( (string) $request->get_param( 'id' ) );

		if ( '' !== $activity_id ) {
			return false;
		}

		$entry = $request->get_param( 'entry' );

		if ( is_array( $entry ) || is_object( $entry ) ) {
			return false;
		}

		if ( true === $request->get_param( 'global' ) ) {
			return true;
		}

		return '' === trim( (string) $request->get_param( 'scopeKey' ) );
	}
}
