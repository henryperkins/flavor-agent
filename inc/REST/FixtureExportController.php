<?php

declare(strict_types=1);

namespace FlavorAgent\REST;

use FlavorAgent\Activity\FixtureExportProvenance;
use FlavorAgent\Activity\RecommendationFixtureExport;
use FlavorAgent\Activity\RecommendationOutcome;
use FlavorAgent\Activity\Repository;

/** Local candidate export is an explicit administrator action. */
final class FixtureExportController {

	public static function register_routes(): void {
		register_rest_route(
			'flavor-agent/v1',
			'/activity/fixture-export',
			[
				'methods'             => 'POST',
				'permission_callback' => [ self::class, 'permission' ],
				'callback'            => [ self::class, 'export' ],
				'args'                => [
					'selection' => [
						'required'             => true,
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'dateFrom' => [
								'type'     => 'string',
								'pattern'  => '^[0-9]{4}-[0-9]{2}-[0-9]{2}(T[0-9]{2}:[0-9]{2}:[0-9]{2}Z)?$',
								'required' => true,
							],
							'dateTo'   => [
								'type'     => 'string',
								'pattern'  => '^[0-9]{4}-[0-9]{2}-[0-9]{2}(T[0-9]{2}:[0-9]{2}:[0-9]{2}Z)?$',
								'required' => true,
							],
							'surfaces' => [
								'type'        => 'array',
								'minItems'    => 1,
								'maxItems'    => 9,
								'uniqueItems' => true,
								'items'       => [
									'type' => 'string',
									'enum' => RecommendationOutcome::SURFACES,
								],
								'required'    => true,
							],
							'rowLimit' => [
								'type'     => 'integer',
								'minimum'  => 1,
								'maximum'  => 1000,
								'required' => true,
							],
						],
					],
				],
			]
		);
	}

	public static function permission(): bool|\WP_Error {
		return current_user_can( 'manage_options' ) ? true : new \WP_Error( 'flavor_agent_fixture_forbidden', 'Administrator permission is required for fixture export.', [ 'status' => 403 ] );
	}

	public static function export( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$permission = self::permission();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		// Reject provenance/config supplied through either body or query parameters.
		if ( [] !== array_diff( array_keys( $request->get_params() ), [ 'selection', '_locale' ] ) ) {
			return new \WP_Error( 'flavor_agent_fixture_invalid_request', 'Only a bounded selection is accepted.', [ 'status' => 400 ] );
		}
		$selection = RecommendationFixtureExport::validate_selection( $request->get_param( 'selection' ) );
		if ( is_wp_error( $selection ) ) {
			return $selection;
		}
		$provenance = FixtureExportProvenance::resolve();
		if ( is_wp_error( $provenance ) ) {
			return $provenance;
		}
		$sample = Repository::fixture_export_sample( $selection );
		if ( is_wp_error( $sample ) ) {
			return $sample;
		}
		$candidate = RecommendationFixtureExport::build( $sample['entries'], [ ...$selection, ...array_diff_key( $sample, [ 'entries' => true ] ) ], $provenance );
		if ( is_wp_error( $candidate ) ) {
			return $candidate;
		}
		$response = new \WP_REST_Response( [ 'candidate' => $candidate ], 200 );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $response;
	}
}
