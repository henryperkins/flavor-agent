<?php
declare(strict_types=1);

namespace FlavorAgent\Activity;

/** Compares recorded editor changes with one immutable saved version. */
final class PersistenceComparison {

	private array $schemas;
	private array $blocks = [];

	private function __construct( array $schemas ) {
		$this->schemas = $schemas;
	}

	/**
	 * No content, schema, theme or entity lookup is performed here. In particular,
	 * editor client IDs never act as persistent block identities.
	 *
	 * @param array<string,mixed> $apply Stored editor apply.
	 * @param array<string,mixed> $snapshot Frozen save occurrence snapshot.
	 * @return array{event:string,reason:string,operations:array}
	 */
	public static function compare( array $apply, array $snapshot ): array {
		$comparison = new self( is_array( $snapshot['schemas'] ?? null ) ? $snapshot['schemas'] : [] );
		if ( ! self::entity_matches( $apply, $snapshot ) ) {
			return self::aggregate( [ self::result( 'inconclusive', 'identity_mismatch' ) ] );
		}
		if ( ! is_string( $snapshot['content'] ?? null ) ) {
			return self::aggregate( [ self::result( 'inconclusive', 'snapshot_unavailable' ) ] );
		}
		$type = $apply['type'] ?? '';
		if ( 'apply_suggestion' !== $type ) {
			$recorded = $apply['after']['operations'] ?? null;
			if ( ! is_array( $recorded ) || ! array_is_list( $recorded ) || [] !== array_filter( $recorded, static fn ( $operation ): bool => ! is_array( $operation ) ) ) {
				return self::aggregate( [ self::result( 'inconclusive', 'missing_operation_evidence' ) ] );
			}
		}
		if ( in_array( $type, [ 'apply_global_styles_suggestion', 'apply_style_book_suggestion' ], true ) ) {
			return self::aggregate( $comparison->styles( $apply, $snapshot['content'] ) );
		}
		$comparison->blocks = self::editor_blocks( parse_blocks( $snapshot['content'] ) );
		if ( 'apply_suggestion' === $type ) {
			return self::aggregate( $comparison->attributes( $apply ) );
		}
		if ( in_array( $type, [ 'apply_block_structural_suggestion', 'apply_template_suggestion', 'apply_template_part_suggestion' ], true ) ) {
			$operations = [];
			foreach ( $apply['after']['operations'] ?? [] as $index => $operation ) {
				$result       = is_array( $operation )
					? $comparison->document_operation( $apply, $operation, $index )
					: self::result( 'inconclusive', 'unsupported_operation' );
				$operations[] = array_merge(
					$result,
					[
						'index' => $index,
						'type'  => $operation['type'] ?? '',
					]
				);
			}
			return self::aggregate( $operations );
		}
		return self::aggregate( [ self::result( 'inconclusive', 'unsupported_surface' ) ] );
	}

	private static function entity_matches( array $apply, array $snapshot ): bool {
		$entity = $snapshot['entity'] ?? [];
		if ( false === ( $entity['identityVerified'] ?? true ) || 'missing' === ( $entity['source'] ?? '' ) ) {
			return false;
		}
		$target   = $apply['target'] ?? [];
		$document = $apply['document'] ?? [];
		$type     = $apply['type'] ?? '';
		if ( in_array( $type, [ 'apply_template_suggestion', 'apply_template_part_suggestion' ], true ) ) {
			$is_part = 'apply_template_part_suggestion' === $type;
			$ref     = $target[ $is_part ? 'templatePartRef' : 'templateRef' ] ?? '';
			$theme   = is_string( $ref ) && str_contains( $ref, '//' ) ? explode( '//', $ref, 2 )[0] : '';
			return '' !== $theme && $ref === ( $entity['ref'] ?? '' )
				&& $theme === ( $entity['theme'] ?? '' )
				&& ( $is_part ? 'template-part' : 'template' ) === ( $entity['type'] ?? '' )
				&& ( $is_part ? 'wp_template_part' : 'wp_template' ) === ( $entity['postType'] ?? '' )
				&& ( 'reset_to_theme' !== ( $snapshot['reason'] ?? '' ) || in_array( $entity['source'] ?? '', [ 'theme', 'plugin' ], true ) );
		}
		if ( in_array( $type, [ 'apply_global_styles_suggestion', 'apply_style_book_suggestion' ], true ) ) {
			return ! empty( $target['globalStylesId'] ) && 'global-styles' === ( $entity['type'] ?? '' )
				&& 'wp_global_styles' === ( $entity['postType'] ?? '' )
				&& (string) $target['globalStylesId'] === (string) ( $entity['postId'] ?? '' );
		}
		$id = (string) ( $document['entityId'] ?? '' );
		return '' !== $id && ! empty( $document['postType'] )
			&& $document['postType'] === ( $entity['postType'] ?? '' )
			&& in_array( $id, [ (string) ( $entity['postId'] ?? '' ), (string) ( $entity['ref'] ?? '' ) ], true );
	}

	private function attributes( array $apply ): array {
		$target = $apply['target'] ?? [];
		$before = $apply['before']['attributes'] ?? null;
		$after  = $apply['after']['attributes'] ?? null;
		if ( ! is_array( $before ) || ! is_array( $after ) ) {
			return [ self::result( 'inconclusive', 'missing_operation_evidence' ) ];
		}
		$paths = self::changed_paths( $before, $after );
		$block = self::resolve( $this->blocks, $target['blockPath'] ?? null );
		$name  = $target['blockName'] ?? '';
		if ( ! $block || '' === $name || $name !== ( $block['blockName'] ?? '' ) ) {
			return [ self::result( 'inconclusive', 'identity_mismatch' ) ];
		}
		$identity = $target['persistenceIdentity'] ?? [];
		if ( ! empty( $identity['name'] ) && $identity['name'] !== $name ) {
			return [ self::result( 'inconclusive', 'identity_mismatch' ) ];
		}
		// Historical entries contain only affected attributes. Forward entries may
		// additionally carry a full pre-apply identity witness. Changed keys cannot
		// witness identity, since they may be independently overwritten at this save.
		$witness = is_array( $identity['attributes'] ?? null ) ? $identity['attributes'] : [];
		foreach ( $paths as $path ) {
			unset( $witness[ $path[0] ] );
		}
		foreach ( array_keys( $witness ) as $key ) {
			$read = PersistenceAttributeNormalizer::read( $block, $this->schemas, [ $key ] );
			if ( ! $read['ok'] && 'unsupported_extraction' === $read['reason'] ) {
				// An optional identity witness need not depend on editor-local or
				// unsupported attributes. Affected fields still fail closed below.
				unset( $witness[ $key ] );
			}
		}
		$matches = [];
		foreach ( self::flatten( $this->blocks ) as $candidate ) {
			if ( $name !== ( $candidate['blockName'] ?? '' ) ) {
				continue;
			}
			$read = PersistenceAttributeNormalizer::read( $candidate, $this->schemas, array_keys( $witness ) );
			if ( ! $read['ok'] ) {
				return [ self::result( 'inconclusive', $read['reason'] ) ];
			}
			if ( $this->attribute_values_match( $read['attributes'], $witness, $name ) ) {
				$matches[] = $candidate;
			}
		}
		if ( 1 !== count( $matches ) ) {
			return [ self::result( 'inconclusive', [] === $matches ? 'identity_mismatch' : 'identity_ambiguous' ) ];
		}
		if ( $matches[0] !== $block ) {
			return [ self::result( 'inconclusive', 'identity_mismatch' ) ];
		}
		$results = [];
		foreach ( $paths as $path ) {
			$read = PersistenceAttributeNormalizer::read( $block, $this->schemas, [ $path[0] ] );
			if ( ! $read['ok'] ) {
				$results[] = array_merge( self::result( 'inconclusive', $read['reason'] ), [ 'path' => $path ] );
				continue;
			}
			$expected = $after;
			$schema   = $this->schemas[ $name ][ $path[0] ];
			if ( array_key_exists( $path[0], $expected ) ) {
				$expected[ $path[0] ] = PersistenceAttributeNormalizer::comparable_value( $expected[ $path[0] ], $schema );
			} elseif ( 1 === count( $path ) && array_key_exists( 'default', $schema ) ) {
				$expected[ $path[0] ] = $schema['default'];
			}
			$results[] = self::compare_path( $read['attributes'], $expected, $path );
		}
		return $results;
	}

	private function styles( array $apply, string $content ): array {
		$saved  = json_decode( $content, true );
		$before = $apply['before']['userConfig'] ?? null;
		$after  = $apply['after']['userConfig'] ?? null;
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $saved ) || ! is_array( $before ) || ! is_array( $after ) ) {
			return [ self::result( 'inconclusive', 'snapshot_unavailable' ) ];
		}
		$results = [];
		foreach ( $apply['after']['operations'] ?? [] as $index => $operation ) {
			$paths = [];
			$type  = $operation['type'] ?? '';
			$path  = $operation['path'] ?? null;
			if ( in_array( $type, [ 'set_styles', 'set_block_styles' ], true ) && self::valid_json_path( $path ) ) {
				if ( 'set_block_styles' === $type ) {
					$name = $operation['blockName'] ?? '';
					if ( '' === $name || 'apply_style_book_suggestion' !== $apply['type'] || $name !== ( $apply['target']['blockName'] ?? '' ) ) {
						$results[] = self::result( 'inconclusive', 'identity_mismatch' );
						continue;
					}
					$paths[] = array_merge( [ 'styles', 'blocks', $name ], $path );
				} elseif ( 'apply_global_styles_suggestion' === $apply['type'] ) {
					$paths[] = array_merge( [ 'styles' ], $path );
				}
			} elseif ( 'set_theme_variation' === $type && 'apply_global_styles_suggestion' === $apply['type'] ) {
				foreach ( [ 'styles', 'settings' ] as $branch ) {
					$paths = array_merge( $paths, self::changed_paths( $before[ $branch ] ?? [], $after[ $branch ] ?? [], [ $branch ] ) );
				}
			}
			if ( [] === $paths ) {
				$results[] = array_merge(
					self::result( 'inconclusive', 'unsupported_operation' ),
					[
						'index' => $index,
						'type'  => $type,
					]
				);
				continue;
			}
			$fields = [];
			foreach ( $paths as $affected ) {
				if ( ! self::read_path( $before, $affected )['found'] && ! self::read_path( $after, $affected )['found'] ) {
					$fields[] = array_merge( self::result( 'inconclusive', 'missing_operation_evidence' ), [ 'path' => $affected ] );
					continue;
				}
				$fields[] = self::compare_path( $saved, $after, $affected );
			}
			$result    = self::operation_result( $fields );
			$results[] = array_merge(
				$result,
				[
					'index'  => $index,
					'type'   => $type,
					'fields' => $fields,
				]
			);
		}
		return $results;
	}

	private function document_operation( array $apply, array $operation, int $index ): array {
		$type   = $operation['type'] ?? '';
		$before = $apply['before']['operations'][ $index ] ?? null;
		if ( ! is_array( $before ) || ( $before['type'] ?? '' ) !== $type ) {
			return self::result( 'inconclusive', 'missing_operation_evidence' );
		}
		if ( in_array( $type, [ 'assign_template_part', 'replace_template_part' ], true ) ) {
			return $this->template_assignment( $operation, $before );
		}
		if ( ! in_array( $type, [ 'insert_pattern', 'replace_block_with_pattern', 'remove_block' ], true ) ) {
			return self::result( 'inconclusive', 'unsupported_operation' );
		}
		$root = $this->operation_root( $apply, $operation );
		if ( ! $root['ok'] ) {
			return self::result( 'inconclusive', $root['reason'] );
		}
		$blocks           = $root['blocks'];
		$inserted         = $operation['insertedBlocksSnapshot'] ?? [];
		$removed          = $operation['removedBlocksSnapshot'] ?? [];
		$insertion_result = null;
		if ( 'remove_block' !== $type ) {
			if ( ! is_array( $inserted ) || [] === $inserted ) {
				return self::result( 'inconclusive', 'missing_operation_evidence' );
			}
			$presence = $this->find_sequence( $blocks, $inserted );
			if ( ! $presence['ok'] ) {
				return self::result( 'inconclusive', $presence['reason'] );
			}
			if ( count( $presence['matches'] ) > 1 ) {
				return self::result( 'inconclusive', 'identity_ambiguous' );
			}
			if ( 1 === count( $presence['matches'] ) ) {
				$insertion_result = self::result( 'present', 'recorded_change_present' );
			} else {
				$insertion_result = $this->partial_insertion( $blocks, $inserted );
			}
			// Exact absence is evaluated only inside an established entity/root and
			// using the complete inserted snapshot. An unsupported source above is
			// inconclusive, including when no candidate block shares its name.
			if ( 'insert_pattern' === $type ) {
				return $insertion_result;
			}
		}
		if ( ! is_array( $removed ) || [] === $removed ) {
			return self::result( 'inconclusive', 'missing_operation_evidence' );
		}
		$support = $this->find_sequence( [], $removed );
		if ( ! $support['ok'] ) {
			return self::result( 'inconclusive', $support['reason'] );
		}
		$removal_result = $this->removal_result( $apply, $operation, $root, $presence['matches'] ?? [] );
		if ( 'replace_block_with_pattern' === $type ) {
			$fields = [ array_merge( $insertion_result, [ 'effect' => 'insert' ] ), array_merge( $removal_result, [ 'effect' => 'remove' ] ) ];
			return array_merge( self::operation_result( $fields ), [ 'fields' => $fields ] );
		}
		return $removal_result;
	}

	/** An edited snapshot is not evidence that its original identity disappeared. */
	private function removal_result( array $apply, array $operation, array $root, array $inserted_matches ): array {
		if ( 1 === count( $inserted_matches ) && self::same_snapshots( $operation['removedBlocksSnapshot'], $operation['insertedBlocksSnapshot'] ?? [] ) ) {
			return self::result( 'inconclusive', 'identity_ambiguous' );
		}
		$covered = [];
		if ( $this->matches_recorded_removal_root( $apply, $operation, $root['blocks'] ) ) {
			// The complete before/after transition accounts for every survivor in
			// this root. Blocks elsewhere may still be moved originals.
			$covered[] = $root['path'];
		} elseif ( 1 === count( $inserted_matches ) ) {
			for ( $offset = 0; $offset < count( $operation['insertedBlocksSnapshot'] ); ++$offset ) {
				$covered[] = array_merge( $root['path'], [ $inserted_matches[0] + $offset ] );
			}
		} elseif ( 'replace_block_with_pattern' === $operation['type'] ) {
			// Individual inserted blocks can survive a partially persisted pattern.
			foreach ( $operation['insertedBlocksSnapshot'] as $inserted ) {
				$survivor = $this->find_sequence( $root['blocks'], [ $inserted ] );
				if ( ! $survivor['ok'] ) {
					return self::result( 'inconclusive', $survivor['reason'] );
				}
				if ( 1 === count( $survivor['matches'] ) ) {
					$covered[] = array_merge( $root['path'], [ $survivor['matches'][0] ] );
				}
			}
		} elseif ( 'remove_block' === $operation['type'] ) {
			$anchor = $operation['postApplyAnchor'] ?? [];
			$at     = $operation['index'] ?? null;
			if ( 'next-block' === ( $anchor['type'] ?? '' ) && is_int( $at ) && $at >= 0 ) {
				$next = $this->find_sequence( $root['blocks'], $anchor['blocksSnapshot'] ?? [] );
				if ( ! $next['ok'] ) {
					return self::result( 'inconclusive', $next['reason'] );
				}
				if ( 1 === count( $next['matches'] ) && $at === $next['matches'][0] ) {
					for ( $offset = 0; $offset < count( $anchor['blocksSnapshot'] ); ++$offset ) {
						$covered[] = array_merge( $root['path'], [ $at + $offset ] );
					}
				}
			}
		}
		$unaccounted = self::unaccounted_blocks( $this->blocks, $covered );
		$results     = [];
		foreach ( $operation['removedBlocksSnapshot'] as $removed ) {
			$candidates = array_values( array_filter( $unaccounted, static fn ( array $block ): bool => $removed['name'] === ( $block['blockName'] ?? '' ) ) );
			if ( [] === $candidates ) {
				$results[] = self::result( 'present', 'recorded_change_present' );
				continue;
			}
			$original = $this->find_sequence( $candidates, [ $removed ] );
			if ( ! $original['ok'] ) {
				$results[] = self::result( 'inconclusive', $original['reason'] );
			} elseif ( 1 === count( $original['matches'] ) ) {
				$results[] = self::result( 'absent', 'recorded_change_absent' );
			} else {
				$results[] = self::result( 'inconclusive', 'identity_ambiguous' );
			}
		}
		return self::operation_result( $results );
	}

	private function matches_recorded_removal_root( array $apply, array $operation, array $blocks ): bool {
		$at = $operation['index'] ?? null;
		if ( 'apply_block_structural_suggestion' !== $apply['type'] || ! is_int( $at ) || $at < 0 ) {
			return false;
		}
		$roots = [];
		foreach ( [ 'before', 'after' ] as $phase ) {
			$signature = json_decode( (string) ( $apply[ $phase ]['structuralSignature'] ?? '' ), true );
			foreach ( $signature['roots'] ?? [] as $recorded ) {
				if ( self::same( $recorded['rootLocator'] ?? [], $operation['rootLocator'] ) && is_array( $recorded['blocks'] ?? null ) ) {
					if ( isset( $roots[ $phase ] ) ) {
						return false;
					}
					$roots[ $phase ] = $recorded['blocks'];
				}
			}
		}
		$removed = $operation['removedBlocksSnapshot'];
		if ( ! isset( $roots['before'], $roots['after'] ) || ! self::same_snapshots( array_slice( $roots['before'], $at, count( $removed ) ), $removed ) ) {
			return false;
		}
		$expected_after = $roots['before'];
		array_splice( $expected_after, $at, count( $removed ), $operation['insertedBlocksSnapshot'] ?? [] );
		if ( ! self::same_snapshots( $expected_after, $roots['after'] ) ) {
			return false;
		}
		$match = $this->sequence_matches( $blocks, $roots['after'] );
		return $match['ok'] && $match['matches'];
	}

	/** Compare recorded block trees without using transient client IDs. */
	private static function same_snapshots( array $left, array $right ): bool {
		if ( count( $left ) !== count( $right ) || ! array_is_list( $left ) || ! array_is_list( $right ) ) {
			return false;
		}
		foreach ( $left as $index => $block ) {
			$other = $right[ $index ];
			if ( ! is_array( $block ) || ! is_array( $other ) || ! is_string( $block['name'] ?? null )
				|| $block['name'] !== ( $other['name'] ?? '' ) || ! is_array( $block['attributes'] ?? null )
				|| ! self::same( $block['attributes'], $other['attributes'] ?? null )
				|| ! is_array( $block['innerBlocks'] ?? [] ) || ! is_array( $other['innerBlocks'] ?? [] )
				|| ! self::same_snapshots( $block['innerBlocks'] ?? [], $other['innerBlocks'] ?? [] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function unaccounted_blocks( array $blocks, array $covered, array $prefix = [] ): array {
		if ( in_array( $prefix, $covered, true ) ) {
			return [];
		}
		$result = [];
		foreach ( $blocks as $index => $block ) {
			$path = array_merge( $prefix, [ $index ] );
			if ( in_array( $path, $covered, true ) ) {
				continue;
			}
			$result[] = $block;
			$result   = array_merge( $result, self::unaccounted_blocks( $block['innerBlocks'] ?? [], $covered, $path ) );
		}
		return $result;
	}

	/** Preserve partial survival inside a multi-block pattern operation. */
	private function partial_insertion( array $blocks, array $expected ): array {
		$fields = [];
		foreach ( $expected as $index => $block ) {
			$presence = $this->find_sequence( $blocks, [ $block ] );
			if ( ! $presence['ok'] ) {
				return self::result( 'inconclusive', $presence['reason'] );
			}
			if ( count( $presence['matches'] ) > 1 ) {
				return self::result( 'inconclusive', 'identity_ambiguous' );
			}
			$fields[] = array_merge( self::result( [] === $presence['matches'] ? 'absent' : 'present', [] === $presence['matches'] ? 'recorded_change_absent' : 'recorded_change_present' ), [ 'blockIndex' => $index ] );
		}
		// Every block may survive while their recorded sequence does not.
		if ( ! in_array( 'absent', array_column( $fields, 'state' ), true ) ) {
			$fields[] = self::result( 'absent', 'block_sequence_changed' );
		}
		return array_merge( self::operation_result( $fields ), [ 'fields' => $fields ] );
	}

	private function template_assignment( array $operation, array $before ): array {
		$previous = $operation['previousAttributes'] ?? $before['previousAttributes'] ?? null;
		$next     = $operation['nextAttributes'] ?? null;
		$area     = $operation['undoLocator']['area'] ?? $operation['area'] ?? '';
		if ( ! is_array( $previous ) || ! is_array( $next ) || '' === $area ) {
			return self::result( 'inconclusive', 'missing_operation_evidence' );
		}
		$expected = array_merge( $previous, $next );
		$matches  = [];
		foreach ( self::flatten( $this->blocks ) as $block ) {
			if ( 'core/template-part' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			$read = PersistenceAttributeNormalizer::read( $block, $this->schemas, array_unique( array_merge( [ 'area' ], array_keys( $previous ), array_keys( $next ) ) ) );
			if ( ! $read['ok'] ) {
				return self::result( 'inconclusive', $read['reason'] );
			}
			if ( ( $read['attributes']['area'] ?? '' ) === $area ) {
				$matches[] = $read['attributes'];
			}
		}
		if ( 1 !== count( $matches ) ) {
			return self::result( 'inconclusive', [] === $matches ? 'identity_mismatch' : 'identity_ambiguous' );
		}
		foreach ( array_diff_key( $previous, $next ) as $key => $value ) {
			if ( ! array_key_exists( $key, $matches[0] ) || ! self::same( $matches[0][ $key ], $value ) ) {
				return self::result( 'inconclusive', 'identity_mismatch' );
			}
		}
		$fields = [];
		foreach ( self::changed_paths( $previous, $expected ) as $path ) {
			$fields[] = self::compare_path( $matches[0], $expected, $path );
		}
		return array_merge( self::operation_result( $fields ), [ 'fields' => $fields ] );
	}

	private function operation_root( array $apply, array $operation ): array {
		$locator = $operation['rootLocator'] ?? null;
		if ( ! is_array( $locator ) ) {
			return [
				'ok'     => false,
				'reason' => 'identity_ambiguous',
			];
		}
		if ( 'root' === ( $locator['type'] ?? '' ) ) {
			return [
				'ok'     => true,
				'blocks' => $this->blocks,
				'path'   => [],
			];
		}
		$name = $locator['blockName'] ?? '';
		$path = $locator['path'] ?? null;
		if ( ! is_array( $path ) && 'apply_block_structural_suggestion' === $apply['type'] ) {
			$target_path = $apply['target']['blockPath'] ?? [];
			$path        = is_array( $target_path ) ? array_slice( $target_path, 0, -1 ) : null;
		}
		$parent = self::resolve( $this->blocks, $path );
		if ( ! $parent || '' === $name || $name !== ( $parent['blockName'] ?? '' ) ) {
			return [
				'ok'     => false,
				'reason' => 'identity_mismatch',
			];
		}
		$candidates = array_values( array_filter( self::flatten( $this->blocks ), static fn ( array $block ): bool => $name === ( $block['blockName'] ?? '' ) ) );
		if ( 1 === count( $candidates ) ) {
			return [
				'ok'     => true,
				'blocks' => $parent['innerBlocks'] ?? [],
				'path'   => $path,
			];
		}
		// Structural signatures retain full before/after roots, unlike template
		// applies. Use their block content, never the ephemeral rootClientId.
		if ( 'apply_block_structural_suggestion' === $apply['type'] ) {
			foreach ( [ 'after', 'before' ] as $phase ) {
				$signature = json_decode( (string) ( $apply[ $phase ]['structuralSignature'] ?? '' ), true );
				foreach ( $signature['roots'] ?? [] as $root ) {
					if ( ! self::same( $root['rootLocator'] ?? [], $locator ) ) {
						continue;
					}
					$matching = [];
					foreach ( $candidates as $candidate ) {
						$match = $this->sequence_matches( $candidate['innerBlocks'] ?? [], $root['blocks'] ?? [] );
						if ( ! $match['ok'] ) {
							return [
								'ok'     => false,
								'reason' => $match['reason'],
							];
						}
						if ( $match['matches'] ) {
							$matching[] = $candidate;
						}
					}
					if ( 1 === count( $matching ) && $matching[0] === $parent ) {
						return [
							'ok'     => true,
							'blocks' => $parent['innerBlocks'] ?? [],
							'path'   => $path,
						];
					}
				}
			}
		}
		return [
			'ok'     => false,
			'reason' => 'identity_ambiguous',
		];
	}

	private function find_sequence( array $blocks, array $expected ): array {
		if ( [] === $expected ) {
			return [
				'ok'     => false,
				'reason' => 'missing_operation_evidence',
			];
		}
		// Check schema/extraction support even when saved content has no candidate.
		foreach ( $expected as $block ) {
			if ( ! is_array( $block ) || ! is_string( $block['name'] ?? null ) || ! is_array( $block['attributes'] ?? null ) ) {
				return [
					'ok'     => false,
					'reason' => 'missing_operation_evidence',
				];
			}
			$read = PersistenceAttributeNormalizer::read(
				[
					'blockName' => $block['name'],
					'attrs'     => [],
					'innerHTML' => '',
				],
				$this->schemas,
				array_keys( $block['attributes'] )
			);
			if ( ! $read['ok'] ) {
				return [
					'ok'     => false,
					'reason' => $read['reason'],
				];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$children = $this->find_sequence( [], $block['innerBlocks'] );
				if ( ! $children['ok'] ) {
					return $children;
				}
			}
		}
		$matches = [];
		for ( $index = 0; $index <= count( $blocks ) - count( $expected ); ++$index ) {
			$match = $this->sequence_matches( array_slice( $blocks, $index, count( $expected ) ), $expected );
			if ( ! $match['ok'] ) {
				return $match;
			}
			if ( $match['matches'] ) {
				$matches[] = $index;
			}
		}
		return [
			'ok'      => true,
			'matches' => $matches,
		];
	}

	private function sequence_matches( array $blocks, array $expected ): array {
		if ( count( $blocks ) !== count( $expected ) ) {
			return [
				'ok'      => true,
				'matches' => false,
			];
		}
		foreach ( $expected as $index => $block ) {
			$name = $block['name'] ?? '';
			if ( '' === $name || $name !== ( $blocks[ $index ]['blockName'] ?? '' ) ) {
				return [
					'ok'      => true,
					'matches' => false,
				];
			}
			$read = PersistenceAttributeNormalizer::read( $blocks[ $index ], $this->schemas, array_keys( $block['attributes'] ?? [] ) );
			if ( ! $read['ok'] ) {
				return [
					'ok'     => false,
					'reason' => $read['reason'],
				];
			}
			if ( ! $this->attribute_values_match( $read['attributes'], $block['attributes'] ?? [], $name ) ) {
				return [
					'ok'      => true,
					'matches' => false,
				];
			}
			$children = $this->sequence_matches( $blocks[ $index ]['innerBlocks'] ?? [], $block['innerBlocks'] ?? [] );
			if ( ! $children['ok'] || ! $children['matches'] ) {
				return $children;
			}
		}
		return [
			'ok'      => true,
			'matches' => true,
		];
	}

	private function attribute_values_match( array $actual, array $expected, string $name ): bool {
		foreach ( $expected as $key => $value ) {
			$value = PersistenceAttributeNormalizer::comparable_value( $value, $this->schemas[ $name ][ $key ] ?? [] );
			if ( ! array_key_exists( $key, $actual ) || ! self::same( $actual[ $key ], $value ) ) {
				return false;
			}
		}
		return true;
	}

	private static function editor_blocks( array $blocks ): array {
		$result = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ( empty( $block['blockName'] ) && '' === trim( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}
			$block['innerBlocks'] = self::editor_blocks( $block['innerBlocks'] ?? [] );
			$result[]             = $block;
		}
		return $result;
	}

	private static function flatten( array $blocks ): array {
		$result = [];
		foreach ( $blocks as $block ) {
			$result[] = $block;
			$result   = array_merge( $result, self::flatten( $block['innerBlocks'] ?? [] ) );
		}
		return $result;
	}

	private static function resolve( array $blocks, $path ): ?array {
		if ( ! is_array( $path ) || [] === $path ) {
			return null;
		}
		$block = null;
		foreach ( $path as $index ) {
			if ( ! is_int( $index ) || $index < 0 || ! isset( $blocks[ $index ] ) ) {
				return null;
			}
			$block  = $blocks[ $index ];
			$blocks = $block['innerBlocks'] ?? [];
		}
		return $block;
	}

	private static function changed_paths( array $before, array $after, array $prefix = [] ): array {
		$paths = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			$path       = array_merge( $prefix, [ $key ] );
			$has_before = array_key_exists( $key, $before );
			$has_after  = array_key_exists( $key, $after );
			if ( $has_before && $has_after && self::same( $before[ $key ], $after[ $key ] ) ) {
				continue;
			}
			$old = $has_before ? $before[ $key ] : [];
			$new = $has_after ? $after[ $key ] : [];
			if ( is_array( $old ) && is_array( $new ) && ( [] === $old || ! array_is_list( $old ) ) && ( [] === $new || ! array_is_list( $new ) ) && ( [] !== $old || [] !== $new ) ) {
				$paths = array_merge( $paths, self::changed_paths( $old, $new, $path ) );
			} else {
				$paths[] = $path;
			}
		}
		return $paths;
	}

	private static function valid_json_path( $path ): bool {
		return is_array( $path ) && [] !== $path && array_is_list( $path )
			&& [] === array_filter( $path, static fn ( $key ): bool => ! is_string( $key ) || '' === $key || in_array( $key, [ '__proto__', 'constructor', 'prototype' ], true ) );
	}

	private static function read_path( array $value, array $path ): array {
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return [
					'found' => false,
					'value' => null,
				];
			}
			$value = $value[ $key ];
		}
		return [
			'found' => true,
			'value' => $value,
		];
	}

	private static function compare_path( array $actual, array $expected, array $path ): array {
		$saved   = self::read_path( $actual, $path );
		$desired = self::read_path( $expected, $path );
		$present = $saved['found'] === $desired['found'] && ( ! $desired['found'] || self::same( $saved['value'], $desired['value'] ) );
		return array_merge(
			self::result( $present ? 'present' : 'absent', $present ? 'recorded_change_present' : 'recorded_change_absent' ),
			[
				'path'           => $path,
				'expectedExists' => $desired['found'],
				'savedExists'    => $saved['found'],
			]
		);
	}

	private static function same( $left, $right ): bool {
		if ( is_array( $left ) && is_array( $right ) ) {
			if ( count( $left ) !== count( $right ) ) {
				return false;
			}
			foreach ( $left as $key => $value ) {
				if ( ! array_key_exists( $key, $right ) || ! self::same( $value, $right[ $key ] ) ) {
					return false;
				}
			}
			return true;
		}
		if ( ( is_int( $left ) || is_float( $left ) ) && ( is_int( $right ) || is_float( $right ) ) ) {
			return (float) $left === (float) $right;
		}
		return $left === $right;
	}

	private static function result( string $state, string $reason ): array {
		return [
			'state'  => $state,
			'reason' => $reason,
		];
	}

	private static function operation_result( array $fields ): array {
		$aggregate = self::aggregate( $fields );
		$state     = match ( $aggregate['event'] ) {
			'save_confirmed' => 'present',
			'save_discarded' => 'absent',
			default => 'inconclusive',
		};
		return self::result( $state, $aggregate['reason'] );
	}

	private static function aggregate( array $operations ): array {
		if ( [] === $operations ) {
			$operations = [ self::result( 'inconclusive', 'missing_operation_evidence' ) ];
		}
		$states  = array_column( $operations, 'state' );
		$reasons = array_column( $operations, 'reason' );
		$event   = 'save_confirmed';
		$reason  = 'recorded_change_present';
		if ( in_array( 'inconclusive', $states, true ) ) {
			$event  = 'save_unverifiable';
			$reason = $operations[ array_search( 'inconclusive', $states, true ) ]['reason'];
		} elseif ( in_array( 'absent', $states, true ) ) {
			$event  = 'save_discarded';
			$reason = in_array( 'present', $states, true ) || in_array( 'partial_persistence', $reasons, true ) ? 'partial_persistence' : 'recorded_change_absent';
		}
		return [
			'event'      => $event,
			'reason'     => $reason,
			'operations' => $operations,
		];
	}
}
