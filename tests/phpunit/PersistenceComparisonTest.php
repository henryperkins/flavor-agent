<?php
declare(strict_types=1);

use FlavorAgent\Activity\PersistenceComparison;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PersistenceComparisonTest extends TestCase {

	protected function setUp(): void {
		WordPressTestState::reset();
	}

	public function test_heading_default_uses_the_pinned_schema_and_ignores_unaffected_fields(): void {
		$apply = $this->heading_apply( [ 'level' => 3 ], [ 'level' => 2 ] );
		$saved = $this->snapshot( '<!-- wp:heading {"textAlign":"right"} --><h2>Title</h2><!-- /wp:heading -->' );
		register_block_type(
			'core/heading',
			[
				'attributes' => [
					'level' => [
						'type'    => 'number',
						'default' => 6,
					],
				],
			]
		);
		$result = PersistenceComparison::compare( $apply, $saved );
		$this->assertSame( 'save_confirmed', $result['event'] );
		$this->assertSame( 'present', $result['operations'][0]['state'] );
	}

	public function test_heading_value_overwritten_in_saved_content_is_discarded(): void {
		$result = PersistenceComparison::compare(
			$this->heading_apply( [ 'level' => 2 ], [ 'level' => 3 ] ),
			$this->snapshot( '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->' )
		);
		$this->assertSame( 'save_discarded', $result['event'] );
	}

	public function test_html_sourced_rich_text_is_extracted_with_pinned_selector(): void {
		$result = PersistenceComparison::compare(
			$this->heading_apply( [ 'content' => 'Old' ], [ 'content' => 'Fresh <strong>heading</strong>' ] ),
			$this->snapshot( '<!-- wp:heading --><h2>Fresh <strong>heading</strong></h2><!-- /wp:heading -->' )
		);
		$this->assertSame( 'save_confirmed', $result['event'] );
	}

	public function test_partial_attribute_persistence_is_discarded_with_its_own_reason(): void {
		$result = PersistenceComparison::compare(
			$this->heading_apply(
				[
					'level'     => 2,
					'textAlign' => 'left',
				],
				[
					'level'     => 3,
					'textAlign' => 'center',
				]
			),
			$this->snapshot( '<!-- wp:heading {"level":3,"textAlign":"left"} --><h3>Title</h3><!-- /wp:heading -->' )
		);
		$this->assertSame( 'save_discarded', $result['event'] );
		$this->assertSame( 'partial_persistence', $result['reason'] );
		$this->assertSame( [ 'present', 'absent' ], array_column( $result['operations'], 'state' ) );
	}

	public function test_nested_attribute_deletion_ignores_unaffected_nested_values(): void {
		$result = PersistenceComparison::compare(
			$this->heading_apply(
				[
					'style' => [
						'color'   => [ 'text' => 'red' ],
						'spacing' => [ 'padding' => '1rem' ],
					],
				],
				[ 'style' => [ 'spacing' => [ 'padding' => '1rem' ] ] ]
			),
			$this->snapshot( '<!-- wp:heading {"style":{"spacing":{"padding":"2rem"}}} --><h2>Title</h2><!-- /wp:heading -->' )
		);
		$this->assertSame( 'save_confirmed', $result['event'] );
	}

	public function test_missing_expected_attribute_path_proves_absence(): void {
		$result = PersistenceComparison::compare(
			$this->heading_apply( [], [ 'style' => [ 'color' => [ 'text' => 'red' ] ] ] ),
			$this->snapshot( '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->' )
		);
		$this->assertSame( 'save_discarded', $result['event'] );
	}

	public function test_different_block_name_at_the_recorded_path_is_inconclusive(): void {
		$result = PersistenceComparison::compare(
			$this->heading_apply( [ 'level' => 2 ], [ 'level' => 3 ] ),
			$this->snapshot( '<!-- wp:paragraph --><p>Replacement</p><!-- /wp:paragraph -->' )
		);
		$this->assertSame( 'save_unverifiable', $result['event'] );
		$this->assertSame( 'identity_mismatch', $result['reason'] );
	}

	public function test_duplicate_block_names_without_a_persistent_witness_are_ambiguous(): void {
		$result = PersistenceComparison::compare(
			$this->heading_apply( [ 'level' => 2 ], [ 'level' => 3 ] ),
			$this->snapshot( '<!-- wp:heading {"level":3} --><h3>A</h3><!-- /wp:heading --><!-- wp:heading --><h2>B</h2><!-- /wp:heading -->' )
		);
		$this->assertSame( 'save_unverifiable', $result['event'] );
		$this->assertSame( 'identity_ambiguous', $result['reason'] );
	}

	public function test_persistent_attribute_witness_distinguishes_same_named_blocks(): void {
		$apply                                  = $this->heading_apply( [ 'level' => 2 ], [ 'level' => 3 ] );
		$apply['target']['persistenceIdentity'] = [
			'name'       => 'core/heading',
			'attributes' => [
				'content' => 'Title',
				'level'   => 2,
			],
		];
		$result                                 = PersistenceComparison::compare(
			$apply,
			$this->snapshot( '<!-- wp:heading {"level":3} --><h3>Title</h3><!-- /wp:heading --><!-- wp:heading --><h2>Other</h2><!-- /wp:heading -->' )
		);
		$this->assertSame( 'save_confirmed', $result['event'] );
	}

	public function test_missing_pinned_schema_is_schema_drift_even_if_registry_has_a_type(): void {
		$saved            = $this->snapshot( '<!-- wp:heading {"level":3} --><h3>Title</h3><!-- /wp:heading -->' );
		$saved['schemas'] = [];
		register_block_type( 'core/heading', [ 'attributes' => [ 'level' => [ 'type' => 'number' ] ] ] );
		$result = PersistenceComparison::compare( $this->heading_apply( [ 'level' => 2 ], [ 'level' => 3 ] ), $saved );
		$this->assertSame( 'save_unverifiable', $result['event'] );
		$this->assertSame( 'schema_drift', $result['reason'] );
	}

	public function test_unsupported_selector_is_inconclusive_instead_of_a_missing_value(): void {
		$saved = $this->snapshot( '<!-- wp:heading --><h2>New</h2><!-- /wp:heading -->' );
		$saved['schemas']['core/heading']['content']['selector'] = 'h2:has(strong)';
		$result = PersistenceComparison::compare( $this->heading_apply( [ 'content' => 'Old' ], [ 'content' => 'New' ] ), $saved );
		$this->assertSame( 'save_unverifiable', $result['event'] );
		$this->assertSame( 'unsupported_extraction', $result['reason'] );
	}

	public function test_unknown_attribute_source_does_not_fall_back_to_comment_json(): void {
		$saved = $this->snapshot( '<!-- wp:heading {"level":3} --><h3>Title</h3><!-- /wp:heading -->' );
		$saved['schemas']['core/heading']['level']['source'] = 'meta';
		$result = PersistenceComparison::compare( $this->heading_apply( [ 'level' => 2 ], [ 'level' => 3 ] ), $saved );
		$this->assertSame( 'save_unverifiable', $result['event'] );
	}

	public function test_attribute_source_reads_html_attribute_and_does_not_coerce_numbers(): void {
		$apply                          = $this->heading_apply( [ 'width' => '20' ], [ 'width' => '50' ] );
		$apply['target']['blockName']   = 'core/image';
		$saved                          = $this->snapshot( '<!-- wp:image --><figure><img src="/image.jpg" width="50"></figure><!-- /wp:image -->' );
		$saved['schemas']['core/image'] = [
			'width' => [
				'type'      => 'string',
				'source'    => 'attribute',
				'selector'  => 'figure > img',
				'attribute' => 'width',
			],
		];
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $saved )['event'] );
		$saved['schemas']['core/image']['width']['type'] = 'number';
		$apply['after']['attributes']['width']           = 50;
		$this->assertSame( 'save_discarded', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	public function test_query_source_retains_complete_nested_schema(): void {
		$apply                            = $this->heading_apply(
			[],
			[
				'images' => [
					[
						'url'     => '/one.jpg',
						'caption' => 'One',
					],
					[
						'url'     => '/two.jpg',
						'caption' => 'Two',
					],
				],
			]
		);
		$apply['target']['blockName']     = 'test/gallery';
		$saved                            = $this->snapshot( '<!-- wp:test/gallery --><figure><img src="/one.jpg" alt="One"><img src="/two.jpg" alt="Two"></figure><!-- /wp:test/gallery -->' );
		$saved['schemas']['test/gallery'] = [
			'images' => [
				'type'     => 'array',
				'source'   => 'query',
				'selector' => 'img',
				'query'    => [
					'url'     => [
						'type'      => 'string',
						'source'    => 'attribute',
						'attribute' => 'src',
					],
					'caption' => [
						'type'      => 'string',
						'source'    => 'attribute',
						'attribute' => 'alt',
					],
				],
			],
		];
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	public function test_global_styles_compares_only_affected_user_override_paths(): void {
		$apply = $this->styles_apply(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'text' ],
					'value' => 'red',
				],
			],
			[ 'styles' => [ 'color' => [ 'text' => 'red' ] ] ]
		);
		$saved = $this->styles_snapshot( '{"styles":{"color":{"text":"red","background":"blue"}},"settings":{"color":{"custom":false}}}' );
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	public function test_style_book_path_is_scoped_to_the_recorded_block(): void {
		$apply                        = $this->styles_apply(
			[
				[
					'type'      => 'set_block_styles',
					'blockName' => 'core/heading',
					'path'      => [ 'color', 'text' ],
					'value'     => 'red',
				],
			],
			[ 'styles' => [ 'blocks' => [ 'core/heading' => [ 'color' => [ 'text' => 'red' ] ] ] ] ]
		);
		$apply['type']                = 'apply_style_book_suggestion';
		$apply['surface']             = 'style-book';
		$apply['target']['blockName'] = 'core/heading';
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $this->styles_snapshot( '{"styles":{"blocks":{"core/heading":{"color":{"text":"red"}},"core/paragraph":{"color":{"text":"blue"}}}}}' ) )['event'] );
		$this->assertSame( 'save_discarded', PersistenceComparison::compare( $apply, $this->styles_snapshot( '{"styles":{"blocks":{"core/paragraph":{"color":{"text":"red"}}}}}' ) )['event'] );
	}

	public function test_theme_variation_records_deletion_and_partial_persistence(): void {
		$apply                         = $this->styles_apply(
			[
				[
					'type'           => 'set_theme_variation',
					'variationTitle' => 'Quiet',
				],
			],
			[
				'styles'   => [ 'color' => [ 'text' => 'red' ] ],
				'settings' => [],
			]
		);
		$apply['before']['userConfig'] = [
			'styles'   => [
				'color' => [
					'text'       => 'black',
					'background' => 'white',
				],
			],
			'settings' => [],
		];
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $this->styles_snapshot( '{"styles":{"color":{"text":"red"}}}' ) )['event'] );
		$result = PersistenceComparison::compare( $apply, $this->styles_snapshot( '{"styles":{"color":{"text":"red","background":"white"}}}' ) );
		$this->assertSame( 'save_discarded', $result['event'] );
		$this->assertSame( 'partial_persistence', $result['reason'] );
	}

	public function test_replacement_cannot_prove_removal_from_an_edited_original_snapshot(): void {
		$operation                          = $this->insert_operation( 'New', 0 );
		$operation['type']                  = 'replace_block_with_pattern';
		$operation['removedBlocksSnapshot'] = [ $this->heading_block( 'Old' ) ];
		$result                             = PersistenceComparison::compare( $this->template_apply( [ $operation ], true ), $this->template_snapshot( '<!-- wp:heading --><h2>New</h2><!-- /wp:heading --><!-- wp:heading {"level":3} --><h3>Old</h3><!-- /wp:heading -->', true ) );
		$this->assertSame( 'save_unverifiable', $result['event'] );
		$this->assertSame( 'identity_ambiguous', $result['reason'] );
		$this->assertSame( [ 'present', 'inconclusive' ], array_column( $result['operations'][0]['fields'], 'state' ) );
	}

	public function test_removal_anchor_does_not_prove_that_an_edited_original_was_not_moved(): void {
		$operation = [
			'type'                  => 'remove_block',
			'rootLocator'           => [
				'type' => 'root',
				'path' => [],
			],
			'index'                 => 0,
			'removedBlocksSnapshot' => [ $this->heading_block( 'Old' ) ],
			'postApplyAnchor'       => [
				'type'           => 'next-block',
				'blocksSnapshot' => [ $this->heading_block( 'Next' ) ],
			],
		];
		$apply     = $this->template_apply( [ $operation ], true );
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $this->template_snapshot( '<!-- wp:heading --><h2>Next</h2><!-- /wp:heading -->', true ) )['event'] );
		$result = PersistenceComparison::compare( $apply, $this->template_snapshot( '<!-- wp:heading --><h2>Next</h2><!-- /wp:heading --><!-- wp:heading {"level":3} --><h3>Old</h3><!-- /wp:heading -->', true ) );
		$this->assertSame( 'save_unverifiable', $result['event'] );
		$this->assertSame( 'identity_ambiguous', $result['reason'] );
	}

	public function test_removal_checks_for_originals_moved_outside_the_recorded_root(): void {
		$operation  = [
			'type'                  => 'remove_block',
			'rootLocator'           => [
				'type'      => 'block',
				'path'      => [ 0 ],
				'blockName' => 'core/group',
			],
			'index'                 => 0,
			'removedBlocksSnapshot' => [ $this->heading_block( 'Old' ) ],
			'postApplyAnchor'       => [ 'type' => 'end' ],
		];
		$apply      = $this->template_apply( [ $operation ], true );
		$empty_root = '<!-- wp:group --><div></div><!-- /wp:group -->';
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $this->template_snapshot( $empty_root, true ) )['event'] );
		$this->assertSame( 'save_discarded', PersistenceComparison::compare( $apply, $this->template_snapshot( $empty_root . '<!-- wp:heading --><h2>Old</h2><!-- /wp:heading -->', true ) )['event'] );
		$this->assertSame( 'save_unverifiable', PersistenceComparison::compare( $apply, $this->template_snapshot( $empty_root . '<!-- wp:heading {"level":3} --><h3>Old</h3><!-- /wp:heading -->', true ) )['event'] );
	}

	public function test_replacement_can_use_complete_before_and_after_roots_to_account_for_survivors(): void {
		$operation                          = $this->insert_operation( 'New', 0 );
		$operation['type']                  = 'replace_block_with_pattern';
		$operation['removedBlocksSnapshot'] = [ $this->heading_block( 'Old' ) ];
		foreach ( [ 'Other', 'Old' ] as $survivor ) {
			$apply = $this->structural_apply( $operation, [ $this->heading_block( 'Old' ), $this->heading_block( $survivor ) ], [ $this->heading_block( 'New' ), $this->heading_block( $survivor ) ] );
			$saved = $this->snapshot( '<!-- wp:heading --><h2>New</h2><!-- /wp:heading --><!-- wp:heading --><h2>' . $survivor . '</h2><!-- /wp:heading -->' );
			$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $saved )['event'] );
		}
	}

	public function test_partial_replacement_accounts_for_identifiable_inserted_survivors(): void {
		$operation                             = $this->insert_operation( 'First', 0 );
		$operation['type']                     = 'replace_block_with_pattern';
		$operation['insertedBlocksSnapshot'][] = $this->heading_block( 'Second' );
		$operation['removedBlocksSnapshot']    = [ $this->heading_block( 'Old' ) ];
		$result                                = PersistenceComparison::compare( $this->template_apply( [ $operation ], true ), $this->template_snapshot( '<!-- wp:heading --><h2>First</h2><!-- /wp:heading -->', true ) );
		$this->assertSame( 'save_discarded', $result['event'] );
		$this->assertSame( 'partial_persistence', $result['reason'] );
		$this->assertSame( [ 'absent', 'present' ], array_column( $result['operations'][0]['fields'], 'state' ) );
	}

	public function test_identical_replacement_snapshots_do_not_prove_a_remove_effect(): void {
		$operation                          = $this->insert_operation( 'Old', 0 );
		$operation['type']                  = 'replace_block_with_pattern';
		$operation['removedBlocksSnapshot'] = [ $this->heading_block( 'Old' ) ];
		$result                             = PersistenceComparison::compare( $this->template_apply( [ $operation ], true ), $this->template_snapshot( '<!-- wp:heading --><h2>Old</h2><!-- /wp:heading -->', true ) );
		$this->assertSame( 'save_unverifiable', $result['event'] );
		$this->assertSame( 'identity_ambiguous', $result['reason'] );
	}

	public function test_matching_after_root_does_not_account_for_an_original_moved_elsewhere(): void {
		$operation                          = $this->insert_operation( 'New', 0 );
		$operation['type']                  = 'replace_block_with_pattern';
		$operation['rootLocator']           = [
			'type'         => 'block',
			'rootClientId' => 'ephemeral-parent',
			'blockName'    => 'core/group',
		];
		$operation['removedBlocksSnapshot'] = [ $this->heading_block( 'Old' ) ];
		$apply                              = $this->structural_apply( $operation, [ $this->heading_block( 'Old' ) ], [ $this->heading_block( 'New' ) ] );
		$apply['target']['blockPath']       = [ 0, 0 ];
		$matched_root                       = '<!-- wp:group --><div><!-- wp:heading --><h2>New</h2><!-- /wp:heading --></div><!-- /wp:group -->';
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $this->snapshot( $matched_root ) )['event'] );
		$this->assertSame( 'save_discarded', PersistenceComparison::compare( $apply, $this->snapshot( $matched_root . '<!-- wp:heading --><h2>Old</h2><!-- /wp:heading -->' ) )['event'] );
		$this->assertSame( 'save_unverifiable', PersistenceComparison::compare( $apply, $this->snapshot( $matched_root . '<!-- wp:heading {"level":3} --><h3>Old</h3><!-- /wp:heading -->' ) )['event'] );
	}

	public function test_any_inconclusive_operation_makes_a_partial_apply_unverifiable(): void {
		$apply  = $this->styles_apply(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'text' ],
					'value' => 'red',
				],
				[ 'type' => 'unknown' ],
			],
			[ 'styles' => [ 'color' => [ 'text' => 'red' ] ] ]
		);
		$result = PersistenceComparison::compare( $apply, $this->styles_snapshot( '{"styles":{"color":{"text":"red"}}}' ) );
		$this->assertSame( 'save_unverifiable', $result['event'] );
		$this->assertSame( [ 'present', 'inconclusive' ], array_column( $result['operations'], 'state' ) );
	}

	public function test_template_assignment_uses_area_and_before_state_for_identity(): void {
		$apply = $this->template_apply(
			[
				[
					'type'               => 'replace_template_part',
					'area'               => 'header',
					'previousAttributes' => [
						'slug'  => 'old-header',
						'area'  => 'header',
						'theme' => 'theme',
					],
					'nextAttributes'     => [
						'slug' => 'new-header',
						'area' => 'header',
					],
					'undoLocator'        => [
						'area'         => 'header',
						'expectedSlug' => 'new-header',
					],
				],
			]
		);
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $this->template_snapshot( '<!-- wp:template-part {"slug":"new-header","area":"header","theme":"theme"} /-->' ) )['event'] );
		$this->assertSame( 'save_discarded', PersistenceComparison::compare( $apply, $this->template_snapshot( '<!-- wp:template-part {"slug":"old-header","area":"header","theme":"theme"} /-->' ) )['event'] );
		$result = PersistenceComparison::compare( $apply, $this->template_snapshot( '<!-- wp:template-part {"slug":"new-header","area":"header","theme":"theme"} /--><!-- wp:template-part {"slug":"other","area":"header","theme":"theme"} /-->' ) );
		$this->assertSame( 'save_unverifiable', $result['event'] );
	}

	public function test_template_insertion_and_other_operation_aggregate_independently(): void {
		$apply  = $this->template_apply( [ $this->insert_operation( 'First', 0 ), $this->insert_operation( 'Second', 1 ) ] );
		$result = PersistenceComparison::compare( $apply, $this->template_snapshot( '<!-- wp:heading --><h2>First</h2><!-- /wp:heading -->' ) );
		$this->assertSame( 'save_discarded', $result['event'] );
		$this->assertSame( 'partial_persistence', $result['reason'] );
		$this->assertSame( [ 'present', 'absent' ], array_column( $result['operations'], 'state' ) );
	}

	public function test_template_part_replacement_uses_inserted_and_removed_snapshots(): void {
		$operation                          = $this->insert_operation( 'New', 0 );
		$operation['type']                  = 'replace_block_with_pattern';
		$operation['targetPath']            = [ 0 ];
		$operation['expectedBlockName']     = 'core/heading';
		$operation['removedBlocksSnapshot'] = [ $this->heading_block( 'Old' ) ];
		$apply                              = $this->template_apply( [ $operation ], true );
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $this->template_snapshot( '<!-- wp:heading --><h2>New</h2><!-- /wp:heading -->', true ) )['event'] );
		$this->assertSame( 'save_discarded', PersistenceComparison::compare( $apply, $this->template_snapshot( '<!-- wp:heading --><h2>Old</h2><!-- /wp:heading -->', true ) )['event'] );
	}

	public function test_template_part_removal_can_confirm_empty_saved_content(): void {
		$operation = [
			'type'                  => 'remove_block',
			'targetPath'            => [ 0 ],
			'expectedBlockName'     => 'core/heading',
			'rootLocator'           => [
				'type' => 'root',
				'path' => [],
			],
			'index'                 => 0,
			'removedBlocksSnapshot' => [ $this->heading_block( 'Old' ) ],
			'postApplyAnchor'       => [ 'type' => 'end' ],
		];
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $this->template_apply( [ $operation ], true ), $this->template_snapshot( '', true ) )['event'] );
	}

	public function test_template_ref_and_reset_fallback_identity_are_required(): void {
		$apply                     = $this->template_apply( [ $this->insert_operation( 'First', 0 ) ] );
		$saved                     = $this->template_snapshot( '<!-- wp:heading --><h2>First</h2><!-- /wp:heading -->' );
		$saved['reason']           = 'reset_to_theme';
		$saved['entity']['source'] = 'theme';
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $saved )['event'] );
		$saved['entity']['ref'] = 'other-theme//home';
		$this->assertSame( 'save_unverifiable', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	public function test_block_structural_operation_uses_signature_to_resolve_nested_root(): void {
		$operation                    = $this->insert_operation( 'Inserted', 1 );
		$operation['rootLocator']     = [
			'type'         => 'block',
			'rootClientId' => 'ephemeral-parent',
			'blockName'    => 'core/group',
		];
		$operation['position']        = 'insert_after';
		$operation['expectedTarget']  = [
			'name'       => 'core/heading',
			'attributes' => [ 'content' => 'Anchor' ],
		];
		$apply                        = $this->heading_apply( [], [] );
		$apply['type']                = 'apply_block_structural_suggestion';
		$apply['target']['blockPath'] = [ 0, 0 ];
		$apply['before']              = [
			'operations'          => [ [ 'type' => 'insert_pattern' ] ],
			'structuralSignature' => json_encode(
				[
					'roots' => [
						[
							'rootLocator' => $operation['rootLocator'],
							'blocks'      => [ $this->heading_block( 'Anchor' ) ],
						],
					],
				]
			),
		];
		$apply['after']               = [
			'operations'          => [ $operation ],
			'structuralSignature' => json_encode(
				[
					'roots' => [
						[
							'rootLocator' => $operation['rootLocator'],
							'blocks'      => [ $this->heading_block( 'Anchor' ), $this->heading_block( 'Inserted' ) ],
						],
					],
				]
			),
		];
		$saved                        = $this->snapshot( '<!-- wp:group --><div><!-- wp:heading --><h2>Anchor</h2><!-- /wp:heading --><!-- wp:heading --><h2>Inserted</h2><!-- /wp:heading --></div><!-- /wp:group -->' );
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	public function test_insertion_duplicate_snapshots_do_not_establish_identity_by_index(): void {
		$apply = $this->template_apply( [ $this->insert_operation( 'Duplicate', 0 ) ] );
		$saved = $this->template_snapshot( '<!-- wp:heading --><h2>Duplicate</h2><!-- /wp:heading --><!-- wp:heading --><h2>Duplicate</h2><!-- /wp:heading -->' );
		$this->assertSame( 'save_unverifiable', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	public function test_replacement_requires_the_removed_block_to_be_absent(): void {
		$operation                          = $this->insert_operation( 'New', 0 );
		$operation['type']                  = 'replace_block_with_pattern';
		$operation['removedBlocksSnapshot'] = [ $this->heading_block( 'Old' ) ];
		$result                             = PersistenceComparison::compare( $this->template_apply( [ $operation ], true ), $this->template_snapshot( '<!-- wp:heading --><h2>New</h2><!-- /wp:heading --><!-- wp:heading --><h2>Old</h2><!-- /wp:heading -->', true ) );
		$this->assertSame( 'save_discarded', $result['event'] );
		$this->assertSame( 'partial_persistence', $result['reason'] );
	}

	public function test_only_one_inserted_pattern_block_surviving_is_partial_persistence(): void {
		$operation                             = $this->insert_operation( 'First', 0 );
		$operation['insertedBlocksSnapshot'][] = $this->heading_block( 'Second' );
		$result                                = PersistenceComparison::compare( $this->template_apply( [ $operation ] ), $this->template_snapshot( '<!-- wp:heading --><h2>First</h2><!-- /wp:heading -->' ) );
		$this->assertSame( 'save_discarded', $result['event'] );
		$this->assertSame( 'partial_persistence', $result['reason'] );
	}

	public function test_ambiguous_nested_parents_can_be_distinguished_by_the_structural_signature(): void {
		$operation                    = $this->insert_operation( 'Inserted', 1 );
		$operation['rootLocator']     = [
			'type'         => 'block',
			'rootClientId' => 'parent',
			'blockName'    => 'core/group',
		];
		$apply                        = $this->heading_apply( [], [] );
		$apply['type']                = 'apply_block_structural_suggestion';
		$apply['target']['blockPath'] = [ 0, 0 ];
		$apply['before']              = [
			'operations'          => [ [ 'type' => 'insert_pattern' ] ],
			'structuralSignature' => json_encode(
				[
					'roots' => [
						[
							'rootLocator' => $operation['rootLocator'],
							'blocks'      => [ $this->heading_block( 'Anchor' ) ],
						],
					],
				]
			),
		];
		$apply['after']               = [
			'operations'          => [ $operation ],
			'structuralSignature' => json_encode(
				[
					'roots' => [
						[
							'rootLocator' => $operation['rootLocator'],
							'blocks'      => [ $this->heading_block( 'Anchor' ), $this->heading_block( 'Inserted' ) ],
						],
					],
				]
			),
		];
		$result                       = PersistenceComparison::compare( $apply, $this->snapshot( '<!-- wp:group --><div><!-- wp:heading --><h2>Anchor</h2><!-- /wp:heading --><!-- wp:heading --><h2>Inserted</h2><!-- /wp:heading --></div><!-- /wp:group --><!-- wp:group --><div><!-- wp:heading --><h2>Other</h2><!-- /wp:heading --></div><!-- /wp:group -->' ) );
		$this->assertSame( 'save_confirmed', $result['event'] );
	}

	public function test_missing_html_boolean_attribute_is_false_even_when_schema_default_is_true(): void {
		$apply                          = $this->heading_apply( [ 'controls' => true ], [ 'controls' => false ] );
		$apply['target']['blockName']   = 'core/video';
		$saved                          = $this->snapshot( '<!-- wp:video --><figure></figure><!-- /wp:video -->' );
		$saved['schemas']['core/video'] = [
			'controls' => [
				'type'      => 'boolean',
				'source'    => 'attribute',
				'selector'  => 'video',
				'attribute' => 'controls',
				'default'   => true,
			],
		];
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	public function test_insertion_with_an_unsupported_schema_is_unverifiable_even_when_no_block_was_saved(): void {
		$saved = $this->template_snapshot( '' );
		$saved['schemas']['core/heading']['content']['source'] = 'children';
		$result = PersistenceComparison::compare( $this->template_apply( [ $this->insert_operation( 'New', 0 ) ] ), $saved );
		$this->assertSame( 'save_unverifiable', $result['event'] );
		$this->assertSame( 'unsupported_extraction', $result['reason'] );
	}

	public function test_json_null_is_not_mistaken_for_a_missing_style_path(): void {
		$apply                         = $this->styles_apply(
			[
				[
					'type' => 'set_styles',
					'path' => [ 'color', 'text' ],
				],
			],
			[]
		);
		$apply['before']['userConfig'] = [ 'styles' => [ 'color' => [ 'text' => 'red' ] ] ];
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $this->styles_snapshot( '{"styles":{}}' ) )['event'] );
		$this->assertSame( 'save_discarded', PersistenceComparison::compare( $apply, $this->styles_snapshot( '{"styles":{"color":{"text":null}}}' ) )['event'] );
	}

	public function test_invalid_json_and_wrong_entity_id_are_unverifiable(): void {
		$apply = $this->styles_apply(
			[
				[
					'type'  => 'set_styles',
					'path'  => [ 'color', 'text' ],
					'value' => 'red',
				],
			],
			[ 'styles' => [ 'color' => [ 'text' => 'red' ] ] ]
		);
		$this->assertSame( 'save_unverifiable', PersistenceComparison::compare( $apply, $this->styles_snapshot( '{invalid}' ) )['event'] );
		$saved                     = $this->styles_snapshot( '{"styles":{"color":{"text":"red"}}}' );
		$saved['entity']['postId'] = 78;
		$this->assertSame( 'save_unverifiable', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	public function test_whitespace_between_blocks_does_not_change_editor_paths(): void {
		$apply                        = $this->heading_apply( [ 'level' => 2 ], [ 'level' => 3 ] );
		$apply['target']['blockPath'] = [ 1 ];
		$saved                        = $this->snapshot( "\n<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->\n\n<!-- wp:heading {\"level\":3} --><h3>Title</h3><!-- /wp:heading -->\n" );
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	public function test_editor_only_attributes_are_not_required_as_identity_witnesses(): void {
		$apply                                  = $this->heading_apply( [ 'level' => 2 ], [ 'level' => 3 ] );
		$apply['target']['persistenceIdentity'] = [
			'name'       => 'core/heading',
			'attributes' => [
				'content'       => 'Title',
				'level'         => 2,
				'editorPreview' => true,
			],
		];
		$saved                                  = $this->snapshot( '<!-- wp:heading {"level":3} --><h3>Title</h3><!-- /wp:heading --><!-- wp:heading --><h2>Other</h2><!-- /wp:heading -->' );
		$saved['schemas']['core/heading']['editorPreview'] = [
			'type' => 'boolean',
			'role' => 'local',
		];
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	public function test_unsupported_unaffected_witness_does_not_hide_a_supported_unique_anchor(): void {
		$apply                                  = $this->heading_apply( [ 'level' => 2 ], [ 'level' => 3 ] );
		$apply['target']['persistenceIdentity'] = [
			'name'       => 'core/heading',
			'attributes' => [
				'content'     => 'Title',
				'level'       => 2,
				'legacyNodes' => [],
			],
		];
		$saved                                  = $this->snapshot( '<!-- wp:heading {"level":3} --><h3>Title</h3><!-- /wp:heading --><!-- wp:heading --><h2>Other</h2><!-- /wp:heading -->' );
		$saved['schemas']['core/heading']['legacyNodes'] = [
			'type'     => 'array',
			'source'   => 'children',
			'selector' => 'h2',
		];
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	public function test_style_path_missing_from_both_recorded_states_is_not_proof_of_a_deletion(): void {
		$apply = $this->styles_apply(
			[
				[
					'type' => 'set_styles',
					'path' => [ 'color', 'text' ],
				],
			],
			[]
		);
		$this->assertSame( 'save_unverifiable', PersistenceComparison::compare( $apply, $this->styles_snapshot( '{}' ) )['event'] );
	}

	public function test_pinned_schema_extracts_text_and_tag_without_comparing_unaffected_markup(): void {
		$apply                                    = $this->heading_apply(
			[
				'text' => 'Old',
				'tag'  => 'h2',
			],
			[
				'text' => 'New title',
				'tag'  => 'h3',
			]
		);
		$saved                                    = $this->snapshot( '<!-- wp:heading --><h3 class="title">New <strong>title</strong></h3><!-- /wp:heading -->' );
		$saved['schemas']['core/heading']['text'] = [
			'type'     => 'string',
			'source'   => 'text',
			'selector' => '.title',
		];
		$saved['schemas']['core/heading']['tag']  = [
			'type'     => 'string',
			'source'   => 'tag',
			'selector' => '.title',
		];
		$this->assertSame( 'save_confirmed', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	public function test_malformed_operation_lists_fail_closed_without_running_the_comparison(): void {
		$apply                        = $this->template_apply( [] );
		$apply['after']['operations'] = [ 'unexpected-key' => $this->insert_operation( 'Title', 0 ) ];
		$this->assertSame( 'save_unverifiable', PersistenceComparison::compare( $apply, $this->template_snapshot( '' ) )['event'] );
		$apply                        = $this->styles_apply( [], [] );
		$apply['after']['operations'] = 'not an operation list';
		$this->assertSame( 'save_unverifiable', PersistenceComparison::compare( $apply, $this->styles_snapshot( '{}' ) )['event'] );
	}

	public function test_observer_rejected_or_missing_fallback_identity_cannot_confirm_even_matching_bytes(): void {
		$apply                               = $this->template_apply( [ $this->insert_operation( 'Title', 0 ) ] );
		$saved                               = $this->template_snapshot( '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->' );
		$saved['entity']['identityVerified'] = false;
		$this->assertSame( 'save_unverifiable', PersistenceComparison::compare( $apply, $saved )['event'] );
		unset( $saved['entity']['identityVerified'] );
		$saved['entity']['source'] = 'missing';
		$this->assertSame( 'save_unverifiable', PersistenceComparison::compare( $apply, $saved )['event'] );
	}

	private function heading_apply( array $before, array $after ): array {
		return [
			'type'     => 'apply_suggestion',
			'surface'  => 'block',
			'target'   => [
				'blockName' => 'core/heading',
				'blockPath' => [ 0 ],
				'clientId'  => 'ephemeral-id',
			],
			'before'   => [ 'attributes' => $before ],
			'after'    => [ 'attributes' => $after ],
			'document' => [
				'entityId' => 42,
				'postType' => 'post',
				'scopeKey' => 'post:42',
			],
		];
	}

	private function snapshot( string $content ): array {
		return [
			'saveOccurrenceId' => 'save-1',
			'saveSequence'     => 1,
			'origin'           => 'observed',
			'entity'           => [
				'postId'   => 42,
				'postType' => 'post',
				'type'     => 'post',
				'ref'      => '42',
				'source'   => 'custom',
			],
			'content'          => $content,
			'schemas'          => [
				'core/heading'       => [
					'level'     => [
						'type'    => 'number',
						'default' => 2,
					],
					'content'   => [
						'type'     => 'rich-text',
						'source'   => 'rich-text',
						'selector' => 'h1,h2,h3,h4,h5,h6',
						'role'     => 'content',
					],
					'textAlign' => [ 'type' => 'string' ],
					'style'     => [ 'type' => 'object' ],
				],
				'core/template-part' => [
					'slug'  => [ 'type' => 'string' ],
					'area'  => [ 'type' => 'string' ],
					'theme' => [ 'type' => 'string' ],
				],
				'core/group'         => [],
			],
			'reason'           => 'saved',
			'capturedAt'       => '2026-09-12 12:00:00',
		];
	}

	private function styles_apply( array $operations, array $after ): array {
		return [
			'type'    => 'apply_global_styles_suggestion',
			'surface' => 'global-styles',
			'target'  => [ 'globalStylesId' => 77 ],
			'before'  => [ 'userConfig' => [] ],
			'after'   => [
				'userConfig' => $after,
				'operations' => $operations,
			],
		];
	}

	private function styles_snapshot( string $content ): array {
		$snapshot           = $this->snapshot( $content );
		$snapshot['entity'] = [
			'postId'   => 77,
			'postType' => 'wp_global_styles',
			'type'     => 'global-styles',
			'ref'      => '77',
			'source'   => 'custom',
		];
		return $snapshot;
	}

	private function template_apply( array $operations, bool $part = false ): array {
		return [
			'type'    => $part ? 'apply_template_part_suggestion' : 'apply_template_suggestion',
			'surface' => $part ? 'template-part' : 'template',
			'target'  => [ $part ? 'templatePartRef' : 'templateRef' => 'theme//home' ],
			'before'  => [
				'operations' => array_map(
					static fn ( array $operation ): array => array_diff_key(
						$operation,
						[
							'insertedBlocksSnapshot' => true,
							'removedBlocksSnapshot'  => true,
							'postApplyAnchor'        => true,
						]
					),
					$operations
				),
			],
			'after'   => [ 'operations' => $operations ],
		];
	}

	private function template_snapshot( string $content, bool $part = false ): array {
		$snapshot           = $this->snapshot( $content );
		$snapshot['entity'] = [
			'postId'   => 51,
			'postType' => $part ? 'wp_template_part' : 'wp_template',
			'type'     => $part ? 'template-part' : 'template',
			'ref'      => 'theme//home',
			'theme'    => 'theme',
			'source'   => 'custom',
		];
		return $snapshot;
	}

	private function heading_block( string $content ): array {
		return [
			'name'        => 'core/heading',
			'attributes'  => [
				'content' => $content,
				'level'   => 2,
			],
			'innerBlocks' => [],
		];
	}

	private function insert_operation( string $content, int $index ): array {
		return [
			'type'                   => 'insert_pattern',
			'patternName'            => 'theme/example',
			'placement'              => 'end',
			'rootLocator'            => [
				'type' => 'root',
				'path' => [],
			],
			'index'                  => $index,
			'insertedBlocksSnapshot' => [ $this->heading_block( $content ) ],
		];
	}

	private function structural_apply( array $operation, array $before, array $after ): array {
		$apply         = $this->heading_apply( [], [] );
		$apply['type'] = 'apply_block_structural_suggestion';
		foreach ( [
			'before' => $before,
			'after'  => $after,
		] as $phase => $blocks ) {
			$apply[ $phase ] = [
				'operations'          => [ 'after' === $phase ? $operation : [ 'type' => $operation['type'] ] ],
				'structuralSignature' => json_encode(
					[
						'roots' => [
							[
								'rootLocator' => $operation['rootLocator'],
								'blocks'      => $blocks,
							],
						],
					]
				),
			];
		}
		return $apply;
	}
}
