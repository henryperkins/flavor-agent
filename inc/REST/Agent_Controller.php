<?php

declare(strict_types=1);

namespace FlavorAgent\REST;

use FlavorAgent\Activity\Permissions as ActivityPermissions;
use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Activity\Serializer;
use FlavorAgent\Patterns\PatternIndex;

final class Agent_Controller {

	private const NAMESPACE = 'flavor-agent/v1';

	private const REQUEST_META_ROUTES = [
		'sync-patterns' => [
			'route' => 'POST /flavor-agent/v1/sync-patterns',
		],
	];

	private const ACTIVITY_DAY_OPERATORS = [
		'on',
		'before',
		'after',
		'between',
		'inThePast',
		'over',
	];

	private const ACTIVITY_DAY_RELATIVE_UNITS = [
		'hours',
		'days',
		'weeks',
		'months',
		'years',
	];

	public static function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/sync-patterns',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_sync_patterns' ],
				'permission_callback' => static fn(): bool => \current_user_can( 'manage_options' ),
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/activity',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'handle_get_activity' ],
					'permission_callback' => [ ActivityPermissions::class, 'can_access_activity_request' ],
					'args'                => [
						'scopeKey'                   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'global'                     => [
							'required'          => false,
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => static fn( mixed $value ): bool => \in_array( $value, [ true, 1, '1', 'true', 'yes' ], true ),
						],
						'surface'                    => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityType'                 => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityRef'                  => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'userId'                     => [
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'postType'                   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'provider'                   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'providerPath'               => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'configurationOwner'         => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'credentialSource'           => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'selectedProvider'           => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityId'                   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'blockPath'                  => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'operationType'              => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'status'                     => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'page'                       => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						],
						'perPage'                    => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => ActivityRepository::DEFAULT_PER_PAGE,
							'sanitize_callback' => 'absint',
						],
						'search'                     => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'sortField'                  => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'sortDirection'              => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'surfaceOperator'            => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'statusOperator'             => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'postTypeOperator'           => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'providerOperator'           => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'providerPathOperator'       => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'configurationOwnerOperator' => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'credentialSourceOperator'   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'selectedProviderOperator'   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityIdOperator'           => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'blockPathOperator'          => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'userIdOperator'             => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'operationTypeOperator'      => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'day'                        => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'dayEnd'                     => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'dayOperator'                => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'dayRelativeValue'           => [
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'dayRelativeUnit'            => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'limit'                      => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => ActivityRepository::DEFAULT_PER_PAGE,
							'sanitize_callback' => 'absint',
						],
						'groupBySurface'             => [
							'required'          => false,
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
						'surfaceLimit'               => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => ActivityRepository::DEFAULT_SURFACE_LIMIT,
							'sanitize_callback' => 'absint',
						],
					],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'handle_create_activity' ],
					'permission_callback' => [ ActivityPermissions::class, 'can_access_activity_request' ],
					'args'                => [
						'entry' => [
							'required'          => true,
							'type'              => 'object',
							'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
							'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
						],
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/activity/(?P<id>[A-Za-z0-9._:-]+)/undo',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_update_activity_undo' ],
				'permission_callback' => [ ActivityPermissions::class, 'can_access_activity_request' ],
				'args'                => [
					'id'     => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'status' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => 'undone',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'error'  => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
			]
		);
	}

	public static function validate_structured_value( mixed $value ): bool {
		return \is_array( $value ) || \is_object( $value );
	}

	public static function sanitize_structured_value( mixed $value ): array {
		$sanitized = Serializer::normalize_structured_value( $value );

		return \is_array( $sanitized ) ? $sanitized : [];
	}

	public static function handle_sync_patterns( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = PatternIndex::sync();

		if ( \is_wp_error( $result ) ) {
			return self::append_request_meta_to_error_for_route( $result, 'sync-patterns' );
		}

		$result['runtimeState'] = PatternIndex::get_runtime_state();

		return new \WP_REST_Response(
			self::append_request_meta_for_route( $result, 'sync-patterns' ),
			200
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function append_request_meta_for_route( array $payload, string $route_key ): array {
		$route_definition = self::REQUEST_META_ROUTES[ $route_key ] ?? null;

		if ( ! \is_array( $route_definition ) ) {
			return $payload;
		}

		$request_meta           = [];
		$request_meta['route']  = $route_definition['route'];
		$payload['requestMeta'] = $request_meta;

		return $payload;
	}

	private static function append_request_meta_to_error_for_route( \WP_Error $error, string $route_key ): \WP_Error {
		$route_definition = self::REQUEST_META_ROUTES[ $route_key ] ?? null;

		if ( ! \is_array( $route_definition ) ) {
			return $error;
		}

		$code         = $error->get_error_code();
		$data         = $error->get_error_data( $code );
		$data         = \is_array( $data )
			? $data
			: ( null !== $data ? [ 'originalData' => $data ] : [] );
		$request_meta = \is_array( $data['requestMeta'] ?? null )
			? $data['requestMeta']
			: [];

		$request_meta['route'] = $route_definition['route'];
		$data['requestMeta']   = $request_meta;

		return new \WP_Error(
			$code,
			$error->get_error_message( $code ),
			$data
		);
	}

	public static function handle_get_activity( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ActivityPermissions::can_access_activity_request( $request ) ) {
			return ActivityPermissions::forbidden_error();
		}

		$is_global_request = true === $request->get_param( 'global' )
			|| '' === \trim( (string) $request->get_param( 'scopeKey' ) );

		if ( $is_global_request ) {
			$date_filter_error = self::validate_admin_activity_date_filter_request( $request );

			if ( \is_wp_error( $date_filter_error ) ) {
				return $date_filter_error;
			}

			$result = ActivityRepository::query_admin(
				[
					'scopeKey'                   => $request->get_param( 'scopeKey' ),
					'surface'                    => $request->get_param( 'surface' ),
					'surfaceOperator'            => $request->get_param( 'surfaceOperator' ),
					'status'                     => $request->get_param( 'status' ),
					'statusOperator'             => $request->get_param( 'statusOperator' ),
					'postType'                   => $request->get_param( 'postType' ),
					'postTypeOperator'           => $request->get_param( 'postTypeOperator' ),
					'provider'                   => $request->get_param( 'provider' ),
					'providerOperator'           => $request->get_param( 'providerOperator' ),
					'providerPath'               => $request->get_param( 'providerPath' ),
					'providerPathOperator'       => $request->get_param( 'providerPathOperator' ),
					'configurationOwner'         => $request->get_param( 'configurationOwner' ),
					'configurationOwnerOperator' => $request->get_param( 'configurationOwnerOperator' ),
					'credentialSource'           => $request->get_param( 'credentialSource' ),
					'credentialSourceOperator'   => $request->get_param( 'credentialSourceOperator' ),
					'selectedProvider'           => $request->get_param( 'selectedProvider' ),
					'selectedProviderOperator'   => $request->get_param( 'selectedProviderOperator' ),
					'entityId'                   => $request->get_param( 'entityId' ),
					'entityIdOperator'           => $request->get_param( 'entityIdOperator' ),
					'blockPath'                  => $request->get_param( 'blockPath' ),
					'blockPathOperator'          => $request->get_param( 'blockPathOperator' ),
					'userId'                     => $request->get_param( 'userId' ),
					'userIdOperator'             => $request->get_param( 'userIdOperator' ),
					'operationType'              => $request->get_param( 'operationType' ),
					'operationTypeOperator'      => $request->get_param( 'operationTypeOperator' ),
					'entityType'                 => $request->get_param( 'entityType' ),
					'entityRef'                  => $request->get_param( 'entityRef' ),
					'page'                       => $request->get_param( 'page' ),
					'perPage'                    => $request->get_param( 'perPage' ),
					'search'                     => $request->get_param( 'search' ),
					'sortField'                  => $request->get_param( 'sortField' ),
					'sortDirection'              => $request->get_param( 'sortDirection' ),
					'day'                        => $request->get_param( 'day' ),
					'dayEnd'                     => $request->get_param( 'dayEnd' ),
					'dayOperator'                => $request->get_param( 'dayOperator' ),
					'dayRelativeValue'           => $request->get_param( 'dayRelativeValue' ),
					'dayRelativeUnit'            => $request->get_param( 'dayRelativeUnit' ),
				]
			);

			return new \WP_REST_Response( $result, 200 );
		}

		$activity_filters = [
			'scopeKey'   => $request->get_param( 'scopeKey' ),
			'surface'    => $request->get_param( 'surface' ),
			'entityType' => $request->get_param( 'entityType' ),
			'entityRef'  => $request->get_param( 'entityRef' ),
			'userId'     => $request->get_param( 'userId' ),
			'limit'      => $request->get_param( 'limit' ),
		];

		$entries = true === $request->get_param( 'groupBySurface' )
			? ActivityRepository::query_grouped_by_surface(
				array_merge(
					$activity_filters,
					[
						'surfaceLimit' => $request->get_param( 'surfaceLimit' ),
					]
				)
			)
			: ActivityRepository::query( $activity_filters );

		return new \WP_REST_Response(
			[
				'entries' => $entries,
			],
			200
		);
	}

	private static function validate_admin_activity_date_filter_request( \WP_REST_Request $request ): ?\WP_Error {
		$operator = \trim( (string) ( $request->get_param( 'dayOperator' ) ?? 'on' ) );
		$operator = '' !== $operator ? $operator : 'on';
		$day      = \trim( (string) ( $request->get_param( 'day' ) ?? '' ) );
		$day_end  = \trim( (string) ( $request->get_param( 'dayEnd' ) ?? '' ) );

		if ( $request->has_param( 'dayOperator' ) && ! \in_array( $operator, self::ACTIVITY_DAY_OPERATORS, true ) ) {
			return self::invalid_admin_activity_date_filter_error( 'Unsupported activity date filter operator.' );
		}

		if ( '' !== $day && ! self::is_valid_activity_day( $day ) ) {
			return self::invalid_admin_activity_date_filter_error( 'Activity date filters must use YYYY-MM-DD dates.' );
		}

		if ( '' !== $day_end && ! self::is_valid_activity_day( $day_end ) ) {
			return self::invalid_admin_activity_date_filter_error( 'Activity date range filters must use YYYY-MM-DD dates.' );
		}

		if ( 'between' === $operator ) {
			if ( '' === $day && '' === $day_end && $request->has_param( 'dayOperator' ) ) {
				return self::invalid_admin_activity_date_filter_error( 'Activity date range filters require both start and end dates.' );
			}

			if ( ( '' === $day ) !== ( '' === $day_end ) ) {
				return self::invalid_admin_activity_date_filter_error( 'Activity date range filters require both start and end dates.' );
			}

			if ( '' !== $day && '' !== $day_end && $day > $day_end ) {
				return self::invalid_admin_activity_date_filter_error( 'Activity date range start must be on or before the end date.' );
			}

			return null;
		}

		if ( '' !== $day_end ) {
			return self::invalid_admin_activity_date_filter_error( 'Activity date range end is only supported with between filters.' );
		}

		if ( \in_array( $operator, [ 'inThePast', 'over' ], true ) ) {
			$relative_value = $request->get_param( 'dayRelativeValue' );
			$relative_unit  = \trim( (string) ( $request->get_param( 'dayRelativeUnit' ) ?? 'days' ) );
			$relative_unit  = '' !== $relative_unit ? $relative_unit : 'days';

			if ( ! $request->has_param( 'dayRelativeValue' ) || (int) $relative_value <= 0 ) {
				return self::invalid_admin_activity_date_filter_error( 'Relative activity date filters require a positive value.' );
			}

			if ( ! \in_array( $relative_unit, self::ACTIVITY_DAY_RELATIVE_UNITS, true ) ) {
				return self::invalid_admin_activity_date_filter_error( 'Unsupported relative activity date filter unit.' );
			}

			return null;
		}

		if ( $request->has_param( 'dayRelativeValue' ) && (int) $request->get_param( 'dayRelativeValue' ) > 0 ) {
			return self::invalid_admin_activity_date_filter_error( 'Relative activity date filters require a relative date operator.' );
		}

		if ( $request->has_param( 'dayOperator' ) && '' === $day ) {
			return self::invalid_admin_activity_date_filter_error( 'Activity date filters require a date value.' );
		}

		return null;
	}

	private static function invalid_admin_activity_date_filter_error( string $message ): \WP_Error {
		return new \WP_Error(
			'flavor_agent_activity_invalid_date_filter',
			$message,
			[ 'status' => 400 ]
		);
	}

	private static function is_valid_activity_day( string $day ): bool {
		$date   = \DateTimeImmutable::createFromFormat(
			'!Y-m-d',
			$day,
			new \DateTimeZone( 'UTC' )
		);
		$errors = \DateTimeImmutable::getLastErrors();

		return false !== $date
			&& ( false === $errors || ( 0 === (int) $errors['warning_count'] && 0 === (int) $errors['error_count'] ) )
			&& $date->format( 'Y-m-d' ) === $day;
	}

	public static function handle_create_activity( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ActivityPermissions::can_access_activity_request( $request ) ) {
			return ActivityPermissions::forbidden_error();
		}

		$entry = $request->get_param( 'entry' );

		if ( ! \is_array( $entry ) && ! \is_object( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_entry',
				'Flavor Agent activity entries must be structured objects.',
				[ 'status' => 400 ]
			);
		}

		$result = ActivityRepository::create(
			self::sanitize_structured_value( $entry )
		);

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'entry' => $result,
			],
			200
		);
	}

	public static function handle_update_activity_undo( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ActivityPermissions::can_access_activity_request( $request ) ) {
			return ActivityPermissions::forbidden_error();
		}

		$status = (string) $request->get_param( 'status' );
		$result = ActivityRepository::update_undo_status(
			(string) $request->get_param( 'id' ),
			'' !== $status ? $status : 'undone',
			$request->has_param( 'error' )
				? (string) $request->get_param( 'error' )
				: null
		);

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'entry' => $result,
			],
			200
		);
	}
}
