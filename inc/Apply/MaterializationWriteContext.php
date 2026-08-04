<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Immutable site/database binding for one template persistence attempt.
 *
 * WordPress multisite changes the table properties on the shared wpdb object
 * during switch_to_blog(). Keeping both the object identity and the exact table
 * names lets callers fail closed without reading, writing, or compensating a
 * same-ID row on another site.
 */
final class MaterializationWriteContext {

	private function __construct(
		private readonly object $database,
		private readonly string $posts_table,
		private readonly string $postmeta_table,
		private readonly string $terms_table,
		private readonly string $term_taxonomy_table,
		private readonly string $term_relationships_table,
		private readonly string $options_table,
		private readonly int $blog_id
	) {
	}

	public static function capture(): self|\WP_Error {
		global $wpdb;

		if (
			! is_object( $wpdb )
			|| ! isset(
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->terms,
				$wpdb->term_taxonomy,
				$wpdb->term_relationships,
				$wpdb->options
			)
			|| ! is_callable( [ $wpdb, 'prepare' ] )
		) {
			return new \WP_Error(
				'flavor_agent_apply_write_context_unavailable',
				'Flavor Agent could not bind the target site database context.',
				[ 'status' => 500 ]
			);
		}

		$posts_table              = (string) $wpdb->posts;
		$postmeta_table           = (string) $wpdb->postmeta;
		$terms_table              = (string) $wpdb->terms;
		$term_taxonomy_table      = (string) $wpdb->term_taxonomy;
		$term_relationships_table = (string) $wpdb->term_relationships;
		$options_table            = (string) $wpdb->options;

		if (
			'' === $posts_table
			|| '' === $postmeta_table
			|| '' === $terms_table
			|| '' === $term_taxonomy_table
			|| '' === $term_relationships_table
			|| '' === $options_table
		) {
			return new \WP_Error(
				'flavor_agent_apply_write_context_unavailable',
				'Flavor Agent could not bind the target site tables.',
				[ 'status' => 500 ]
			);
		}

		return new self(
			$wpdb,
			$posts_table,
			$postmeta_table,
			$terms_table,
			$term_taxonomy_table,
			$term_relationships_table,
			$options_table,
			function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0
		);
	}

	public function matches_current(): bool {
		global $wpdb;

		return $wpdb === $this->database
			&& $this->posts_table === (string) ( $this->database->posts ?? '' )
			&& $this->postmeta_table === (string) ( $this->database->postmeta ?? '' )
			&& $this->terms_table === (string) ( $this->database->terms ?? '' )
			&& $this->term_taxonomy_table === (string) ( $this->database->term_taxonomy ?? '' )
			&& $this->term_relationships_table === (string) ( $this->database->term_relationships ?? '' )
			&& $this->options_table === (string) ( $this->database->options ?? '' )
			&& $this->blog_id === ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );
	}

	public function database(): object {
		return $this->database;
	}

	public function posts_table(): string {
		return $this->posts_table;
	}

	public function options_table(): string {
		return $this->options_table;
	}

	public function postmeta_table(): string {
		return $this->postmeta_table;
	}

	public function terms_table(): string {
		return $this->terms_table;
	}

	public function term_taxonomy_table(): string {
		return $this->term_taxonomy_table;
	}

	public function term_relationships_table(): string {
		return $this->term_relationships_table;
	}

	public function blog_id(): int {
		return $this->blog_id;
	}

	/**
	 * Read one raw posts-table row through the captured database/table binding.
	 */
	public function read_post_row( int $post_id ): ?object {
		if ( $post_id <= 0 || ! is_callable( [ $this->database, 'get_row' ] ) ) {
			return null;
		}

		try {
			$sql = $this->database->prepare(
				'SELECT ID, post_author, post_date, post_date_gmt, post_content, post_content_filtered, post_title, post_excerpt, post_status, post_type, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_parent, menu_order, post_mime_type, guid FROM %i WHERE ID = %d LIMIT 1',
				$this->posts_table,
				$post_id
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- A context-bound raw row is the byte predicate/readback for the guarded write; $sql is prepared immediately above.
			$row = $this->database->get_row( $sql );
		} catch ( \Throwable ) {
			return null;
		}

		return is_object( $row ) ? $row : null;
	}

	/** @return list<object>|null */
	public function read_post_meta_rows( int $post_id, string $meta_key ): ?array {
		if (
			$post_id <= 0
			|| '' === $meta_key
			|| ! is_callable( [ $this->database, 'get_results' ] )
		) {
			return null;
		}

		try {
			$sql = $this->database->prepare(
				'SELECT meta_id, meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC',
				$this->postmeta_table,
				$post_id,
				$meta_key
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The exact raw rows are required for a context-bound metadata compare-and-swap; $sql is prepared immediately above.
			$rows = $this->database->get_results( $sql );
		} catch ( \Throwable ) {
			return null;
		}

		if ( ! is_array( $rows ) ) {
			return null;
		}

		return array_values( array_filter( $rows, 'is_object' ) );
	}

	/** @return list<string>|null */
	public function read_term_names( int $post_id, string $taxonomy ): ?array {
		if (
			$post_id <= 0
			|| '' === $taxonomy
			|| ! is_callable( [ $this->database, 'get_results' ] )
		) {
			return null;
		}

		try {
			$sql = $this->database->prepare(
				'SELECT t.name AS term_name FROM %i AS tr INNER JOIN %i AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN %i AS t ON t.term_id = tt.term_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY t.term_id ASC',
				$this->term_relationships_table,
				$this->term_taxonomy_table,
				$this->terms_table,
				$post_id,
				$taxonomy
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Exact context-bound taxonomy rows are required to verify Core's ignored side-effect return values; $sql is prepared immediately above.
			$rows = $this->database->get_results( $sql );
		} catch ( \Throwable ) {
			return null;
		}

		if ( ! is_array( $rows ) ) {
			return null;
		}

		$names = [];

		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->term_name ) ) {
				return null;
			}

			$names[] = (string) $row->term_name;
		}

		return $names;
	}
}
