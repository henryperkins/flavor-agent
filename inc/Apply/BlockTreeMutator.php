<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Path-addressed mutation over parse_blocks() output that keeps each parent
 * block's innerBlocks list and innerContent null-markers consistent, so
 * serialize_blocks() reproduces valid markup. Paths are 0-indexed; [] is the
 * top-level list (which has no innerContent wrapper).
 */
final class BlockTreeMutator {

	/** @return array<string,mixed>|null */
	public static function resolve( array $blocks, array $path ): ?array {
		$node = null;
		$list = $blocks;

		foreach ( $path as $index ) {
			if ( ! array_key_exists( $index, $list ) || ! is_array( $list[ $index ] ) ) {
				return null;
			}
			$node = $list[ $index ];
			$list = is_array( $node['innerBlocks'] ?? null ) ? $node['innerBlocks'] : [];
		}

		return $node;
	}

	public static function remove( array $blocks, array $path ): array {
		return self::splice_at_path( $blocks, $path, 1, [] );
	}

	/** @param array<int,array<string,mixed>> $replacement_blocks */
	public static function replace( array $blocks, array $path, array $replacement_blocks ): array {
		return self::splice_at_path( $blocks, $path, 1, array_values( $replacement_blocks ) );
	}

	/** @param array<int,array<string,mixed>> $new_blocks */
	public static function insert( array $blocks, array $parent_path, int $index, array $new_blocks ): array {
		$child_path   = $parent_path;
		$child_path[] = $index;

		return self::splice_at_path( $blocks, $child_path, 0, array_values( $new_blocks ) );
	}

	/**
	 * Splice $remove_count blocks at $path with $insert_blocks, adjusting the
	 * parent's innerContent null-markers when the path is nested.
	 *
	 * @param array<int,array<string,mixed>> $insert_blocks
	 */
	private static function splice_at_path( array $blocks, array $path, int $remove_count, array $insert_blocks ): array {
		if ( [] === $path ) {
			return $blocks;
		}

		$index = (int) array_pop( $path );

		if ( [] === $path ) {
			array_splice( $blocks, $index, $remove_count, $insert_blocks );

			return $blocks;
		}

		// Walk to the parent block, mutate, and write it back up the path.
		return self::with_node(
			$blocks,
			$path,
			static function ( array $parent_block ) use ( $index, $remove_count, $insert_blocks ): array {
				$inner = is_array( $parent_block['innerBlocks'] ?? null ) ? $parent_block['innerBlocks'] : [];
				array_splice( $inner, $index, $remove_count, $insert_blocks );
				$parent_block['innerBlocks']  = $inner;
				$parent_block['innerContent'] = self::splice_inner_content(
					is_array( $parent_block['innerContent'] ?? null ) ? $parent_block['innerContent'] : [],
					$index,
					$remove_count,
					count( $insert_blocks )
				);

				return $parent_block;
			}
		);
	}

	/**
	 * Replace the null-marker run for child $block_index: drop $remove_count
	 * null markers and write $insert_count fresh nulls in their place.
	 *
	 * @param array<int,string|null> $inner_content
	 * @return array<int,string|null>
	 */
	private static function splice_inner_content( array $inner_content, int $block_index, int $remove_count, int $insert_count ): array {
		$result          = [];
		$null_seen       = 0;
		$inserted        = false;
		$after_last_null = null; // Offset in $result just past the most recent preserved marker.

		foreach ( $inner_content as $chunk ) {
			if ( null !== $chunk ) {
				$result[] = $chunk;
				continue;
			}

			// Emit the fresh markers once, at the splice point, before deciding
			// whether the marker we landed on is itself removed or preserved.
			if ( ! $inserted && $null_seen === $block_index ) {
				for ( $i = 0; $i < $insert_count; $i++ ) {
					$result[] = null;
				}
				$inserted = true;
			}

			$within_removed = $null_seen >= $block_index && $null_seen < $block_index + $remove_count;
			++$null_seen;

			if ( $within_removed ) {
				continue; // drop a marker inside the removed run
			}

			$result[]        = $chunk; // preserve this marker
			$after_last_null = count( $result );
		}

		// Pure insert past the end of the null run (block_index >= null count).
		// Splice the fresh markers in right after the final child marker so they
		// stay INSIDE a nested wrapper, before any trailing literal (e.g. the
		// closing </div>). With no markers to anchor to (e.g. an empty wrapper),
		// fall back to appending at the end.
		if ( ! $inserted ) {
			$fresh = array_fill( 0, max( 0, $insert_count ), null );

			if ( null !== $after_last_null ) {
				array_splice( $result, $after_last_null, 0, $fresh );
			} else {
				foreach ( $fresh as $marker ) {
					$result[] = $marker;
				}
			}
		}

		return $result;
	}

	/** @param callable(array<string,mixed>):array<string,mixed> $mutate */
	private static function with_node( array $blocks, array $path, callable $mutate ): array {
		$index = (int) array_shift( $path );

		if ( ! array_key_exists( $index, $blocks ) || ! is_array( $blocks[ $index ] ) ) {
			return $blocks;
		}

		if ( [] === $path ) {
			$blocks[ $index ] = $mutate( $blocks[ $index ] );

			return $blocks;
		}

		$inner                           = is_array( $blocks[ $index ]['innerBlocks'] ?? null ) ? $blocks[ $index ]['innerBlocks'] : [];
		$blocks[ $index ]['innerBlocks'] = self::with_node( $inner, $path, $mutate );

		return $blocks;
	}

	private function __construct() {}
}
