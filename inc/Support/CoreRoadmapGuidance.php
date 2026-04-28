<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class CoreRoadmapGuidance {

	private const ROADMAP_SOURCE_URL     = 'https://github.com/orgs/WordPress/projects/240/views/7?layout=table&hierarchy=true';
	private const ROADMAP_SOURCE_KEY     = 'github.com/orgs/WordPress/projects/240';
	private const ROADMAP_VIEW_URL       = 'https://github.com/orgs/WordPress/projects/240/views/7?layout=table&hierarchy=true';
	private const CACHE_KEY              = 'flavor_agent_core_roadmap_guidance_v1';
	private const SCHEDULE_LOCK_KEY      = 'flavor_agent_core_roadmap_guidance_schedule_lock';
	private const SCHEDULE_LOCK_TTL      = 60;
	private const CACHE_TTL              = 21600;
	private const EMPTY_CACHE_TTL        = 1800;
	private const REQUEST_TIMEOUT        = 12;
	private const MAX_ROADMAP_GUIDANCE   = 3;
	private const MAX_ITEM_GUIDANCE      = 2;
	private const PAGINATED_ITEMS_SCRIPT = 'memex-paginated-items-data';
	private const COLUMNS_SCRIPT         = 'memex-columns-data';

	public const WARM_CRON_HOOK = 'flavor_agent_warm_core_roadmap_guidance';

	private const FINISHED_STATUSES = [
		'done'     => true,
		'closed'   => true,
		'complete' => true,
	];

	private const STATUS_ORDER = [
		'In progress'                    => 0,
		'Needs review'                   => 1,
		'In discussion / Needs decision' => 2,
		'To do'                          => 3,
		'Backlog'                        => 4,
		'Triage'                         => 5,
		''                               => 9,
	];

	public static function collect( array $context = [] ): array {
		if ( ! self::is_enabled( $context ) ) {
			return [];
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return self::sanitize_guidance_chunks( $cached );
		}

		self::schedule_warm( $context );

		return [];
	}

	public static function warm( array $context = [] ): array {
		if ( ! self::is_enabled( $context ) ) {
			return [];
		}

		// If a parallel warm already populated the cache, reuse it instead of
		// re-fetching from GitHub. Duplicate scheduled events can otherwise
		// hammer the upstream when locks are missed.
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && [] !== $cached ) {
			delete_transient( self::SCHEDULE_LOCK_KEY );
			return self::sanitize_guidance_chunks( $cached );
		}

		$html = self::fetch_roadmap_html( $context );

		if ( '' === $html ) {
			// Transient upstream error (network, non-2xx, empty body). Retry sooner.
			set_transient( self::CACHE_KEY, [], self::empty_cache_ttl( $context ) );
			delete_transient( self::SCHEDULE_LOCK_KEY );
			return [];
		}

		$guidance = self::build_guidance_from_html( $html, $context );

		// Cache for the full TTL whenever we received a usable response — even
		// if it produced no items. An empty result with a 200 response means
		// either no open work or a page-format change; either way, retrying
		// every EMPTY_CACHE_TTL would hammer GitHub for no benefit.
		set_transient( self::CACHE_KEY, $guidance, self::cache_ttl( $context ) );
		delete_transient( self::SCHEDULE_LOCK_KEY );

		return $guidance;
	}

	public static function schedule_warm( array $context = [] ): void {
		if ( ! self::is_enabled( $context ) ) {
			return;
		}

		if ( false !== get_transient( self::CACHE_KEY ) ) {
			return;
		}

		if ( wp_next_scheduled( self::WARM_CRON_HOOK ) ) {
			return;
		}

		// Brief lock to suppress thundering herd when multiple cold-cache
		// requests arrive concurrently. Cleared by warm() on completion or
		// when the lock expires.
		if ( false !== get_transient( self::SCHEDULE_LOCK_KEY ) ) {
			return;
		}
		set_transient( self::SCHEDULE_LOCK_KEY, 1, self::SCHEDULE_LOCK_TTL );

		wp_schedule_single_event( time() + 5, self::WARM_CRON_HOOK );
	}

	private static function is_enabled( array $context ): bool {
		if ( isset( $context['skipCoreRoadmapGuidance'] ) && (bool) $context['skipCoreRoadmapGuidance'] ) {
			return false;
		}

		// Off by default: roadmap guidance scrapes a private GitHub Memex view
		// (HTML regex), so site owners must explicitly opt in to the unannounced
		// outbound HTTP. The filter takes precedence; tests opt in via setUp.
		$default_enabled = false;

		/**
		 * Control whether Core roadmap guidance is collected and injected.
		 *
		 * @param bool  $enabled
		 * @param array $context
		 */
		return (bool) apply_filters( 'flavor_agent_enable_core_roadmap_guidance', $default_enabled, $context );
	}

	private static function build_guidance_from_html( string $html, array $context ): array {
		$payload = self::extract_json_payload( $html, self::PAGINATED_ITEMS_SCRIPT );
		$columns = self::extract_json_payload( $html, self::COLUMNS_SCRIPT );

		if ( empty( $payload ) || empty( $columns ) ) {
			return [];
		}

		$status_column_ids   = self::extract_field_column_ids( $columns, 'Status' );
		$priority_column_ids = self::extract_field_column_ids( $columns, 'Priority' );
		$status_map          = self::extract_field_value_map( $columns, 'Status' );
		$priority_map        = self::extract_field_value_map( $columns, 'Priority' );
		$items               = self::extract_roadmap_items(
			$payload,
			$status_map,
			$priority_map,
			$status_column_ids,
			$priority_column_ids
		);

		if ( [] === $items ) {
			return [];
		}

		usort( $items, [ self::class, 'compare_items' ] );

		return self::build_guidance_chunks( $items, $payload );
	}

	private static function fetch_roadmap_html( array $context ): string {
		$remote = wp_remote_get(
			self::ROADMAP_SOURCE_URL,
			[
				'timeout' => self::request_timeout( $context ),
			]
		);

		if ( is_wp_error( $remote ) ) {
			return '';
		}

		$response_code = wp_remote_retrieve_response_code( $remote );
		if ( $response_code < 200 || $response_code >= 300 ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $remote );
		if ( ! is_string( $body ) ) {
			return '';
		}

		return trim( $body );
	}

	private static function cache_ttl( array $context ): int {
		/**
		 * Control how long Core roadmap guidance is cached, in seconds.
		 *
		 * @param int   $ttl
		 * @param array $context
		 */
		return max( 0, (int) apply_filters( 'flavor_agent_core_roadmap_guidance_cache_ttl', self::CACHE_TTL, $context ) );
	}

	private static function empty_cache_ttl( array $context ): int {
		/**
		 * Control how long empty or failed Core roadmap guidance warms are cached, in seconds.
		 *
		 * @param int   $ttl
		 * @param array $context
		 */
		return max( 0, (int) apply_filters( 'flavor_agent_core_roadmap_guidance_empty_cache_ttl', self::EMPTY_CACHE_TTL, $context ) );
	}

	private static function request_timeout( array $context ): int {
		/**
		 * Control the Core roadmap guidance HTTP request timeout, in seconds.
		 *
		 * @param int   $timeout
		 * @param array $context
		 */
		return max( 1, (int) apply_filters( 'flavor_agent_core_roadmap_guidance_request_timeout', self::REQUEST_TIMEOUT, $context ) );
	}

	private static function extract_json_payload( string $html, string $script_id ): array {
		$pattern = '/<script\b[^>]*\bid="' . preg_quote( $script_id, '/' ) . '"[^>]*>(.*?)<\/script>/s';

		if ( ! preg_match( $pattern, $html, $matches ) ) {
			return [];
		}

		$decoded = json_decode( trim( (string) ( $matches[1] ?? '' ) ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return [];
		}

		return $decoded;
	}

	private static function extract_field_value_map( array $columns, string $column_name ): array {
		$map = [];

		foreach ( $columns as $column ) {
			if ( ! is_array( $column ) || ! array_key_exists( 'id', $column ) ) {
				continue;
			}

			$id   = sanitize_text_field( (string) $column['id'] );
			$name = sanitize_text_field( (string) ( $column['name'] ?? '' ) );

			if ( $id !== $column_name && $name !== $column_name ) {
				continue;
			}

			$options = $column['settings']['options'] ?? [];
			if ( ! is_array( $options ) ) {
				continue;
			}

			foreach ( $options as $option ) {
				if ( ! is_array( $option ) ) {
					continue;
				}

				$option_id   = sanitize_text_field( (string) ( $option['id'] ?? '' ) );
				$option_name = sanitize_text_field( (string) ( $option['name'] ?? '' ) );

				if ( '' !== $option_id && '' !== $option_name ) {
					$map[ $option_id ] = $option_name;
				}
			}
		}

		return $map;
	}

	/**
	 * Resolve the memex column IDs that correspond to a given column name. The
	 * priority column's ID is numeric (not literally "Priority"), so node
	 * lookups must match against the resolved IDs rather than the column name
	 * directly. Returning multiple IDs would only happen if upstream renamed
	 * the column without removing the old one — collecting them all keeps the
	 * lookup tolerant.
	 *
	 * @param array<int, array<string, mixed>> $columns
	 * @return array<int, string>
	 */
	private static function extract_field_column_ids( array $columns, string $column_name ): array {
		$ids = [];

		foreach ( $columns as $column ) {
			if ( ! is_array( $column ) || ! array_key_exists( 'id', $column ) ) {
				continue;
			}

			$id   = sanitize_text_field( (string) $column['id'] );
			$name = sanitize_text_field( (string) ( $column['name'] ?? '' ) );

			if ( ( $id === $column_name || $name === $column_name ) && '' !== $id ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private static function extract_roadmap_items(
		array $payload,
		array $status_map,
		array $priority_map,
		array $status_column_ids,
		array $priority_column_ids
	): array {
		$grouped_items = $payload['groupedItems'] ?? [];
		if ( ! is_array( $grouped_items ) ) {
			return [];
		}

		$group_map = self::extract_group_map( $payload );
		$items     = [];

		foreach ( $grouped_items as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$group_id       = sanitize_text_field( (string) ( $group['groupId'] ?? '' ) );
			$group_data     = '' !== $group_id && isset( $group_map[ $group_id ] ) ? $group_map[ $group_id ] : [];
			$group_value    = sanitize_text_field( (string) ( $group['groupValue'] ?? ( $group_data['value'] ?? '' ) ) );
			$group_metadata = is_array( $group['groupMetadata'] ?? null ) ? $group['groupMetadata'] : [];
			if ( [] === $group_metadata && isset( $group_data['metadata'] ) && is_array( $group_data['metadata'] ) ) {
				$group_metadata = $group_data['metadata'];
			}
			$group_title   = sanitize_text_field( (string) ( $group_metadata['title'] ?? ( $group_value !== '' ? $group_value : $group_id ) ) );
			$is_group_open = 'open' === sanitize_key( (string) ( $group_metadata['state'] ?? '' ) );
			$nodes         = is_array( $group['nodes'] ?? null ) ? $group['nodes'] : [];

			if ( [] === $nodes ) {
				continue;
			}

			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}

				$item_data = self::extract_node_data(
					$node,
					$status_map,
					$priority_map,
					$status_column_ids,
					$priority_column_ids
				);
				if ( '' === $item_data['title'] || self::is_completed_status( $item_data['status'] ) ) {
					continue;
				}

				if ( '' === $item_data['url'] ) {
					$item_data['url'] = self::ROADMAP_VIEW_URL;
				}

				$items[] = [
					'title'        => $item_data['title'],
					'url'          => $item_data['url'],
					'status'       => $item_data['status'],
					'priority'     => $item_data['priority'],
					'group'        => $group_title !== '' ? $group_title : $group_value,
					'group_open'   => $is_group_open,
					'updated_at'   => $item_data['updated_at'],
					'virtualScore' => $item_data['virtual_score'],
				];
			}
		}

		return $items;
	}

	private static function extract_group_map( array $payload ): array {
		$groups = $payload['groups']['nodes'] ?? [];
		if ( ! is_array( $groups ) ) {
			return [];
		}

		$map = [];
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$group_id = sanitize_text_field( (string) ( $group['groupId'] ?? '' ) );
			if ( '' === $group_id ) {
				continue;
			}

			$map[ $group_id ] = [
				'value'    => sanitize_text_field( (string) ( $group['groupValue'] ?? '' ) ),
				'metadata' => is_array( $group['groupMetadata'] ?? null ) ? $group['groupMetadata'] : [],
			];
		}

		return $map;
	}

	private static function extract_node_data(
		array $node,
		array $status_map,
		array $priority_map,
		array $status_column_ids,
		array $priority_column_ids
	): array {
		$item_title    = '';
		$item_url      = '';
		$status        = '';
		$priority      = '';
		$updated_at    = 0;
		$virtual_score = 0.0;

		$updated = (string) ( $node['updatedAt'] ?? '' );
		if ( '' !== $updated ) {
			$updated_at = max( 0, (int) strtotime( $updated ) );
		}

		$virtual       = (string) ( $node['virtualPriority'] ?? '' );
		$virtual_score = max( 0.0, min( 1.0, 1 - (float) $virtual ) );

		$node_state    = sanitize_key( (string) ( $node['state'] ?? '' ) );
		$columns       = is_array( $node['memexProjectColumnValues'] ?? null ) ? $node['memexProjectColumnValues'] : [];
		$has_issue_url = false;

		foreach ( $columns as $column ) {
			if ( ! is_array( $column ) ) {
				continue;
			}

			$column_id    = sanitize_text_field( (string) ( $column['memexProjectColumnId'] ?? '' ) );
			$column_value = $column['value'] ?? null;

			if ( 'Title' === $column_id && is_array( $column_value ) ) {
				$item_title = sanitize_text_field( (string) ( $column_value['title']['raw'] ?? '' ) );
				if ( '' === $node_state ) {
					$node_state = sanitize_key( (string) ( $column_value['state'] ?? '' ) );
				}
				if ( ! empty( $column_value['url'] ) ) {
					$item_url      = sanitize_url( (string) $column_value['url'] );
					$has_issue_url = true;
				}
			}

			$is_status_column   = 'Status' === $column_id || in_array( $column_id, $status_column_ids, true );
			$is_priority_column = in_array( $column_id, $priority_column_ids, true );

			if ( $is_status_column && is_array( $column_value ) ) {
				$status_id = sanitize_text_field( (string) ( $column_value['id'] ?? '' ) );
				$status    = $status_map[ $status_id ] ?? '';
			}

			// Match priority strictly by column ID so a colliding option ID in
			// the status (or any other) column can't be misread as a priority.
			if ( $is_priority_column && is_array( $column_value ) ) {
				$priority_id = sanitize_text_field( (string) ( $column_value['id'] ?? '' ) );
				if ( isset( $priority_map[ $priority_id ] ) ) {
					$priority = $priority_map[ $priority_id ];
				}
			}
		}

		if ( '' !== $node_state && '' === $status ) {
			$status = 'closed' === $node_state ? 'Done' : '';
		}

		if ( '' === $item_url && ! empty( $node['content']['url'] ) && ! $has_issue_url ) {
			$item_url = sanitize_url( (string) ( $node['content']['url'] ) );
		}

		return [
			'title'         => $item_title,
			'url'           => $item_url,
			'status'        => $status,
			'priority'      => $priority,
			'updated_at'    => $updated_at,
			'virtual_score' => $virtual_score,
		];
	}

	private static function is_completed_status( string $status ): bool {
		$normalized = strtolower( (string) sanitize_text_field( $status ) );

		return '' !== $normalized && array_key_exists( $normalized, self::FINISHED_STATUSES );
	}

	private static function compare_items( array $left, array $right ): int {
		if ( (bool) ( $left['group_open'] ?? false ) !== (bool) ( $right['group_open'] ?? false ) ) {
			return (bool) ( $left['group_open'] ?? false ) ? -1 : 1;
		}

		$status_rank = self::status_rank( (string) ( $left['status'] ?? '' ) )
			<=> self::status_rank( (string) ( $right['status'] ?? '' ) );
		if ( 0 !== $status_rank ) {
			return $status_rank;
		}

		if ( (float) ( $left['virtualScore'] ?? 0.0 ) !== (float) ( $right['virtualScore'] ?? 0.0 ) ) {
			return ( (float) ( $right['virtualScore'] ?? 0.0 ) <=> (float) ( $left['virtualScore'] ?? 0.0 ) );
		}

		return ( (int) ( $right['updated_at'] ?? 0 ) ) <=> ( (int) ( $left['updated_at'] ?? 0 ) );
	}

	private static function status_rank( string $status ): int {
		return self::STATUS_ORDER[ $status ] ?? 10;
	}

	private static function build_guidance_chunks( array $items, array $payload ): array {
		$open_milestones = [];
		$groups          = $payload['groups']['nodes'] ?? [];

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$group_metadata = is_array( $group['groupMetadata'] ?? null ) ? $group['groupMetadata'] : [];

			if ( 'open' !== sanitize_key( (string) ( $group_metadata['state'] ?? '' ) ) ) {
				continue;
			}

			$name = sanitize_text_field(
				(string) (
					$group_metadata['title']
					?? ( $group['groupValue'] ?? '' )
				)
			);
			if ( '' !== $name ) {
				$open_milestones[] = $name;
			}
		}

		$chunks = [];

		$open_milestones = StringArray::sanitize( $open_milestones );
		if ( [] !== $open_milestones ) {
			$chunks[] = [
				'id'         => 'flavor-agent-roadmap-summary',
				'title'      => 'WordPress AI roadmap status',
				'sourceKey'  => self::ROADMAP_SOURCE_KEY,
				'sourceType' => 'core-roadmap',
				'url'        => self::ROADMAP_VIEW_URL,
				'excerpt'    => 'Open roadmap milestones: ' . implode( ', ', array_slice( $open_milestones, 0, 3 ) ),
				'score'      => 0.95,
			];
		}

		foreach ( array_slice( $items, 0, self::MAX_ITEM_GUIDANCE ) as $item ) {
			$status    = sanitize_text_field( (string) ( $item['status'] ?? '' ) );
			$milestone = sanitize_text_field( (string) ( $item['group'] ?? '' ) );
			$priority  = sanitize_text_field( (string) ( $item['priority'] ?? '' ) );
			$excerpt   = 'In-progress roadmap item in ' . ( '' !== $milestone ? $milestone : 'current milestone' ) . '.';
			if ( '' !== $status ) {
				$excerpt .= ' Status: ' . $status . '.';
			}
			if ( '' !== $priority ) {
				$excerpt .= ' Priority: ' . $priority . '.';
			}

			$chunks[] = [
				'id'         => 'core-roadmap-' . sanitize_title( (string) ( $item['title'] ?? '' ) ),
				'title'      => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'sourceKey'  => self::ROADMAP_SOURCE_KEY,
				'sourceType' => 'core-roadmap',
				'url'        => sanitize_url( (string) ( $item['url'] ?? '' ) ),
				'excerpt'    => $excerpt,
				'score'      => (float) ( 0.8 + ( (float) ( $item['virtualScore'] ?? 0.0 ) / 10 ) ),
			];
		}

		return array_values( self::sanitize_guidance_chunks( array_slice( $chunks, 0, self::MAX_ROADMAP_GUIDANCE ) ) );
	}

	private static function sanitize_guidance_chunks( array $guidance ): array {
		$filtered = [];
		foreach ( $guidance as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}

			$title   = trim( (string) ( $chunk['title'] ?? '' ) );
			$excerpt = GuidanceExcerpt::sanitize( (string) ( $chunk['excerpt'] ?? '' ) );

			if ( $title === '' || $excerpt === '' ) {
				continue;
			}

			$filtered[] = [
				'id'         => sanitize_text_field( (string) ( $chunk['id'] ?? '' ) ),
				'title'      => $title,
				'sourceKey'  => sanitize_text_field( (string) ( $chunk['sourceKey'] ?? '' ) ),
				'sourceType' => sanitize_key( (string) ( $chunk['sourceType'] ?? '' ) ),
				'url'        => sanitize_url( (string) ( $chunk['url'] ?? '' ) ),
				'excerpt'    => $excerpt,
				'score'      => max( 0.0, min( 1.0, (float) ( $chunk['score'] ?? 0.0 ) ) ),
			];
		}

		return $filtered;
	}
}
