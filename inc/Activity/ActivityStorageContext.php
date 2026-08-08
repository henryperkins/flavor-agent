<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

/** Immutable database/table owner for one external-apply or undo workflow. */
final class ActivityStorageContext {

	public function __construct(
		private readonly object $database,
		private readonly string $table_name,
		private readonly string $prefix,
		private readonly int $blog_id,
		private readonly string $options_table,
		private readonly string $posts_table,
		private readonly string $postmeta_table,
		private readonly string $site_url
	) {
	}

	public function database(): object {
		return $this->database;
	}

	public function table_name(): string {
		return $this->table_name;
	}

	public function prefix(): string {
		return $this->prefix;
	}

	public function blog_id(): int {
		return $this->blog_id;
	}

	public function options_table(): string {
		return $this->options_table;
	}

	public function posts_table(): string {
		return $this->posts_table;
	}

	public function postmeta_table(): string {
		return $this->postmeta_table;
	}

	public function site_url(): string {
		return $this->site_url;
	}

	public function read_option( string $name, mixed $fallback = false ): mixed {
		if ( ! is_callable( [ $this->database, 'prepare' ] ) || ! is_callable( [ $this->database, 'get_var' ] ) ) {
			return $fallback;
		}

		$sql = $this->database->prepare(
			'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
			$this->options_table,
			$name
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The immutable owner database and options table are prepared above so a multisite switch cannot redirect the read.
		$value = $this->database->get_var( $sql );

		if ( null === $value ) {
			return $fallback;
		}

		return function_exists( 'maybe_unserialize' )
			? maybe_unserialize( $value )
			: self::maybe_unserialize_fallback( $value );
	}

	public function write_option( string $name, mixed $value, bool $autoload = false ): bool {
		if ( ! is_callable( [ $this->database, 'update' ] ) || ! is_callable( [ $this->database, 'insert' ] ) ) {
			return false;
		}

		$serialized = function_exists( 'maybe_serialize' )
			? maybe_serialize( $value )
			: self::maybe_serialize_fallback( $value );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The immutable owner database and options table prevent an ambient multisite switch from redirecting the update.
		$updated = $this->database->update(
			$this->options_table,
			[ 'option_value' => $serialized ],
			[ 'option_name' => $name ],
			[ '%s' ],
			[ '%s' ]
		);

		if ( false === $updated ) {
			return false;
		}

		if ( 0 === $updated ) {
			$existing = $this->read_raw_option( $name );

			if ( null === $existing ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The immutable owner database and options table prevent an ambient multisite switch from redirecting the insert.
				$inserted = $this->database->insert(
					$this->options_table,
					[
						'option_name'  => $name,
						'option_value' => $serialized,
						'autoload'     => $autoload ? 'yes' : 'no',
					],
					[ '%s', '%s', '%s' ]
				);

				if ( false === $inserted ) {
					return $serialized === $this->read_raw_option( $name );
				}
			} elseif ( $serialized !== $existing ) {
				return false;
			}
		}

		if ( $this->matches_current() && function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}

		return true;
	}

	public function matches_current(): bool {
		global $wpdb;

		return $wpdb === $this->database
			&& $this->prefix === (string) ( $this->database->prefix ?? '' )
			&& $this->options_table === (string) ( $this->database->options ?? '' )
			&& $this->posts_table === (string) ( $this->database->posts ?? '' )
			&& $this->postmeta_table === (string) ( $this->database->postmeta ?? '' )
			&& $this->blog_id === ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );
	}

	private function read_raw_option( string $name ): ?string {
		if ( ! is_callable( [ $this->database, 'prepare' ] ) || ! is_callable( [ $this->database, 'get_var' ] ) ) {
			return null;
		}

		$sql = $this->database->prepare(
			'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
			$this->options_table,
			$name
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The immutable owner database and options table are prepared above.
		$value = $this->database->get_var( $sql );

		return null === $value ? null : (string) $value;
	}

	private static function maybe_serialize_fallback( mixed $value ): mixed {
		return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
	}

	private static function maybe_unserialize_fallback( mixed $value ): mixed {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$unserialized = @unserialize( $value );

		return false !== $unserialized || 'b:0;' === $value ? $unserialized : $value;
	}
}
