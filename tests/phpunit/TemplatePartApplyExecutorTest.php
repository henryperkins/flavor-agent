<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Apply\TemplatePartApplyExecutor;
use FlavorAgent\Apply\MaterializationLock;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class TemplatePartApplyExecutorTest extends TestCase {

	private const PART_ID = 'twentytwentyfive//header';

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$active_theme = [ 'stylesheet' => 'twentytwentyfive' ];
	}

	/**
	 * Seed the live part into the get_block_template(s) stub store so the bound
	 * TemplateRepository::resolve_template_part resolves it. When $wp_id > 0 also
	 * seed a wp_template_part post as the wp_update_post write target. No filter
	 * seam: the executor re-collects + persists through the real stubbed WP APIs.
	 */
	private function seed_part( string $content, int $wp_id = 0, string $area = '', string $slug = 'header', string $title = 'Header' ): void {
		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'id'      => self::PART_ID,
				'wp_id'   => $wp_id,
				'slug'    => $slug,
				'area'    => $area,
				'title'   => $title,
				'content' => $content,
			],
		];

		if ( $wp_id > 0 ) {
			WordPressTestState::$posts[ $wp_id ] = new \WP_Post(
				[
					'ID'           => $wp_id,
					'post_type'    => 'wp_template_part',
					'post_content' => $content,
				]
			);
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<string, mixed>
	 */
	private function entry( array $operations ): array {
		return [
			'surface'  => 'template-part',
			'target'   => [
				'templatePartId'  => self::PART_ID,
				'templatePartRef' => self::PART_ID,
			],
			'document' => [
				'entityId' => self::PART_ID,
				'postType' => 'wp_template_part',
				'scopeKey' => 'wp_template_part:' . self::PART_ID,
			],
			'apply'    => [ 'operations' => $operations ],
		];
	}

	/**
	 * The hydrated activity entry an executed external template-part apply leaves
	 * behind: the surface, the part target, and the before/after content snapshots
	 * that undo() drift-checks against the live part.
	 *
	 * @return array<string, mixed>
	 */
	private static function executed_entry( string $before, string $after ): array {
		return [
			'surface'  => 'template-part',
			'target'   => [
				'templatePartId'  => self::PART_ID,
				'templatePartRef' => self::PART_ID,
			],
			'document' => [
				'entityId' => self::PART_ID,
				'postType' => 'wp_template_part',
				'scopeKey' => 'wp_template_part:' . self::PART_ID,
			],
			'before'   => [ 'content' => $before ],
			'after'    => [ 'content' => $after ],
		];
	}

	public function test_resolve_target_identity_returns_equal_template_part_aliases(): void {
		$identity = TemplatePartApplyExecutor::resolve_target_identity( $this->entry( [] ) );

		$this->assertSame(
			[
				'target'   => [
					'templatePartId'  => self::PART_ID,
					'templatePartRef' => self::PART_ID,
				],
				'document' => [
					'entityId' => self::PART_ID,
					'postType' => 'wp_template_part',
					'scopeKey' => 'wp_template_part:' . self::PART_ID,
				],
			],
			$identity
		);
	}

	public function test_resolve_target_identity_accepts_an_id_only_legacy_row_and_populates_both_aliases(): void {
		$entry = $this->entry( [] );
		unset( $entry['target']['templatePartRef'] );

		$identity = TemplatePartApplyExecutor::resolve_target_identity( $entry );

		$this->assertIsArray( $identity );
		$this->assertSame(
			[
				'templatePartId'  => self::PART_ID,
				'templatePartRef' => self::PART_ID,
			],
			$identity['target']
		);
	}

	public function test_authorize_target_rejects_missing_or_conflicting_template_part_ids_before_reads_or_capability(): void {
		$fixtures = [
			'ref-only' => [ 'templatePartRef' => self::PART_ID ],
			'missing'  => [],
			'conflict' => [
				'templatePartId'  => self::PART_ID,
				'templatePartRef' => 'twentytwentyfive//footer',
			],
		];

		foreach ( $fixtures as $label => $target ) {
			$entry                                 = $this->entry( [] );
			$entry['target']                       = $target;
			$reads                                 = 0;
			WordPressTestState::$capability_checks = [];
			WordPressTestState::$block_templates_read_hook = static function () use ( &$reads ): void {
				++$reads;
			};

			$result = TemplatePartApplyExecutor::authorize_target( $entry );

			$this->assertInstanceOf( \WP_Error::class, $result, $label );
			$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code(), $label );
			$this->assertSame( 409, $result->get_error_data()['status'] ?? null, $label );
			$this->assertSame( 0, $reads, $label );
			$this->assertSame( [], WordPressTestState::$capability_checks, $label );
		}
	}

	public function test_authorize_target_rejects_non_string_template_part_aliases_before_reads_or_capability(): void {
		$fixtures = [
			[
				'templatePartId'  => [],
				'templatePartRef' => [],
			],
			[
				'templatePartId'  => 17,
				'templatePartRef' => 17,
			],
			[
				'templatePartId'  => new \stdClass(),
				'templatePartRef' => self::PART_ID,
			],
			[
				'templatePartId'  => [ 'first' ],
				'templatePartRef' => [ 'second' ],
			],
		];

		foreach ( $fixtures as $target ) {
			$entry           = $this->entry( [] );
			$entry['target'] = $target;
			$canonical_ref   = is_int( $target['templatePartId'] ) ? (string) $target['templatePartId'] : 'Array';

			if ( ! is_object( $target['templatePartId'] ) ) {
				$entry['document'] = [
					'entityId' => $canonical_ref,
					'postType' => 'wp_template_part',
					'scopeKey' => 'wp_template_part:' . $canonical_ref,
				];
			}

			$reads = 0;

			WordPressTestState::$capability_checks         = [];
			WordPressTestState::$block_templates_read_hook = static function () use ( &$reads ): void {
				++$reads;
			};

			$result = TemplatePartApplyExecutor::authorize_target( $entry );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] ?? null );
			$this->assertSame( 0, $reads );
			$this->assertSame( [], WordPressTestState::$capability_checks );
		}
	}

	public function test_authorize_target_rejects_each_divergent_template_part_document_field_before_capability(): void {
		$mutations = [
			'entityId' => 'twentytwentyfive//footer',
			'postType' => 'wp_template',
			'scopeKey' => 'wp_template_part:twentytwentyfive//footer',
		];

		foreach ( $mutations as $field => $value ) {
			$entry                                 = $this->entry( [] );
			$entry['document'][ $field ]           = $value;
			WordPressTestState::$capability_checks = [];

			$result = TemplatePartApplyExecutor::authorize_target( $entry );

			$this->assertInstanceOf( \WP_Error::class, $result, $field );
			$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code(), $field );
			$this->assertSame( [], WordPressTestState::$capability_checks, $field );
		}

		foreach ( array_keys( $mutations ) as $field ) {
			$entry = $this->entry( [] );
			unset( $entry['document'][ $field ] );
			WordPressTestState::$capability_checks = [];

			$result = TemplatePartApplyExecutor::authorize_target( $entry );

			$this->assertInstanceOf( \WP_Error::class, $result, 'missing ' . $field );
			$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code(), 'missing ' . $field );
			$this->assertSame( [], WordPressTestState::$capability_checks, 'missing ' . $field );
		}
	}

	public function test_authorize_target_requires_edit_theme_options_for_a_canonical_template_part(): void {
		$result = TemplatePartApplyExecutor::authorize_target( $this->entry( [] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );

		WordPressTestState::$capabilities['edit_theme_options'] = true;
		$this->assertTrue( TemplatePartApplyExecutor::authorize_target( $this->entry( [] ) ) );
	}

	public function test_resolve_baseline_rejects_repository_slug_fallback_to_a_different_template_part_id(): void {
		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'id'      => 'othertheme//header',
				'wp_id'   => 0,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Other Header',
				'content' => '<!-- wp:paragraph --><p>Wrong subject</p><!-- /wp:paragraph -->',
			],
		];

		$result = TemplatePartApplyExecutor::resolve_baseline( $this->entry( [] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
	}

	private function register_pattern( string $name, string $content ): void {
		\WP_Block_Patterns_Registry::get_instance()->register(
			$name,
			[
				'title'   => $name,
				'content' => $content,
			]
		);
	}

	private function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	/** @return array<string, mixed> */
	private static function materialization_locks(): array {
		return array_filter(
			WordPressTestState::$options,
			static fn( string $key ): bool => str_starts_with( $key, 'flavor_agent_materialization_lock_' ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Reflection seam onto the private R1 three-phase apply pipeline so the
	 * ordering + fail-closed guards can be proven without the collector/validator
	 * rebuilding the operations.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function apply_ops( array $blocks, array $operations ): array|\WP_Error {
		$method = new \ReflectionMethod( TemplatePartApplyExecutor::class, 'apply_operations' );
		$method->setAccessible( true );

		return $method->invoke( null, $blocks, $operations );
	}

	private static function assert_before( string $needle, string $first, string $second, string $haystack, string $message ): void {
		$a = strpos( $haystack, $first );
		$b = strpos( $haystack, $second );
		self::assertNotFalse( $a, $message . ' (missing ' . $first . ')' );
		self::assertNotFalse( $b, $message . ' (missing ' . $second . ')' );
		self::assertLessThan( $b, $a, $message );
	}

	// ---------------------------------------------------------------------
	// resolve_baseline (Task 4) — unchanged contract.
	// ---------------------------------------------------------------------

	public function test_resolve_baseline_hashes_reserialized_content(): void {
		$content = '<!-- wp:navigation /-->';
		$this->seed_part( $content );

		$hash = TemplatePartApplyExecutor::resolve_baseline( $this->entry( [] ) );

		$this->assertSame(
			hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) ),
			$hash
		);
	}

	public function test_resolve_baseline_hashes_an_available_but_empty_part(): void {
		$this->seed_part( '' );

		$hash = TemplatePartApplyExecutor::resolve_baseline( $this->entry( [] ) );

		$this->assertSame(
			hash( 'sha256', serialize_blocks( parse_blocks( '' ) ) ),
			$hash
		);
	}

	public function test_resolve_baseline_errors_when_part_missing(): void {
		$result = TemplatePartApplyExecutor::resolve_baseline(
			[
				'surface' => 'template-part',
				'target'  => [ 'templatePartId' => 'no//such' ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}

	public function test_resolve_baseline_fails_closed_on_missing_identifier(): void {
		$result = TemplatePartApplyExecutor::resolve_baseline( [ 'surface' => 'template-part' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
	}

	// ---------------------------------------------------------------------
	// execute() — happy paths (writes through the real stubbed WP APIs).
	// ---------------------------------------------------------------------

	public function test_execute_removes_nested_block_and_snapshots_before_after(): void {
		$content = '<!-- wp:group -->'
			. '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
			. $this->paragraph( 'Body' )
			. '<!-- /wp:group -->';
		$this->seed_part( $content, 4321, 'header' );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0, 0 ],
						'expectedBlockName' => 'core/heading',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::PART_ID, $result['target']['templatePartId'] );
		$this->assertSame( self::PART_ID, $result['target']['templatePartRef'] );
		$this->assertSame( $content, $result['before']['content'] );
		$this->assertStringNotContainsString( 'wp:heading', $result['after']['content'] );
		$this->assertStringContainsString( 'Body', $result['after']['content'] );
		$this->assertSame(
			$result['after']['content'],
			(string) WordPressTestState::$posts[4321]->post_content,
			'The persisted post_content must equal the mutated after snapshot.'
		);
		$this->assertStringNotContainsString( 'wp:heading', (string) WordPressTestState::$posts[4321]->post_content );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
	}

	public function test_execute_returns_the_post_persist_content_after_a_save_filter_changes_it(): void {
		$content = $this->paragraph( 'Keep' ) . $this->paragraph( 'Remove' );
		$this->seed_part( $content, 4399 );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( isset( $data['post_content'] ) ) {
					$data['post_content'] = str_replace( '>Keep<', '>Keep saved<', (string) $data['post_content'] );
				}

				return $data;
			},
			10,
			4
		);

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Keep saved', (string) WordPressTestState::$posts[4399]->post_content );
		$this->assertSame( WordPressTestState::$posts[4399]->post_content, $result['after']['content'] );
	}

	public function test_execute_fails_when_a_save_hook_moves_the_post_off_the_canonical_ref(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 9514, 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		WordPressTestState::$posts[9514]->post_name                      = 'header';
		WordPressTestState::$object_terms[9514]['wp_theme']              = [ 'twentytwentyfive' ];
		WordPressTestState::$object_terms[9514]['wp_template_part_area'] = [ 'header' ];
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 9514 === (int) ( $data['ID'] ?? 0 ) ) {
					$data['post_name'] = 'header-moved';
					WordPressTestState::$block_templates['wp_template_part'][0]->id   = 'twentytwentyfive//header-moved';
					WordPressTestState::$block_templates['wp_template_part'][0]->slug = 'header-moved';
				}

				return $data;
			},
			10,
			4
		);

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_post_write_read_failed', $result->get_error_code() );
		$this->assertSame( 'header-moved', WordPressTestState::$posts[9514]->post_name );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_fails_when_exact_post_write_resolution_selects_another_post(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 9516, 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 9516 === (int) ( $data['ID'] ?? 0 ) ) {
					WordPressTestState::$posts[9599]        = new \WP_Post(
						[
							'ID'           => 9599,
							'post_type'    => 'wp_template_part',
							'post_name'    => 'header',
							'post_content' => '<!-- wp:paragraph --><p>Other row</p><!-- /wp:paragraph -->',
						]
					);
					WordPressTestState::$object_terms[9599] = [
						'wp_theme'              => [ 'twentytwentyfive' ],
						'wp_template_part_area' => [ 'header' ],
					];
				}

				return $data;
			},
			10,
			4
		);

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_post_write_read_failed', $result->get_error_code() );
		$this->assertSame(
			9599,
			\FlavorAgent\Context\ServerCollector::resolve_template_part_for_attestation( self::PART_ID )?->wp_id
		);
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_regates_live_content_after_acquiring_the_target_lock(): void {
		$before     = $this->paragraph( 'Anchor' );
		$concurrent = $before . $this->paragraph( 'Concurrent edit' );
		$this->seed_part( $before, 9510, 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		WordPressTestState::$before_materialization_lock_insert = function () use ( $concurrent ): void {
			$this->seed_part( $concurrent, 9510, 'header' );
		};

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $concurrent, WordPressTestState::$posts[9510]->post_content );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_captures_persisted_content_before_releasing_the_target_lock(): void {
		$before = $this->paragraph( 'Anchor' );
		$later  = $this->paragraph( 'Later writer' );
		$this->seed_part( $before, 9511, 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		WordPressTestState::$after_materialization_lock_delete = static function () use ( $later ): void {
			WordPressTestState::$posts[9511]->post_content                       = $later;
			WordPressTestState::$block_templates['wp_template_part'][0]->content = $later;
		};

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'TailPat', $result['after']['content'] );
		$this->assertStringNotContainsString( 'Later writer', $result['after']['content'] );
		$this->assertSame( $later, WordPressTestState::$posts[9511]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_replaces_block_with_pattern(): void {
		$content = $this->paragraph( 'KeepMe' ) . $this->paragraph( 'ReplaceMe' );
		$this->seed_part( $content, 4322 );
		$this->register_pattern( 'fa-test/card', '<!-- wp:group -->' . $this->paragraph( 'CardBody' ) . '<!-- /wp:group -->' );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'replace_block_with_pattern',
						'patternName'       => 'fa-test/card',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$persisted = (string) WordPressTestState::$posts[4322]->post_content;
		$this->assertStringContainsString( 'KeepMe', $persisted );
		$this->assertStringContainsString( 'CardBody', $persisted );
		$this->assertStringNotContainsString( 'ReplaceMe', $persisted );
		$this->assertSame( $result['after']['content'], $persisted );
	}

	public function test_execute_inserts_pattern_before_anchor(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 4323 );
		$this->register_pattern( 'fa-test/intro', $this->paragraph( 'IntroPat' ) );

		TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/intro',
						'placement'   => 'before_block_path',
						'targetPath'  => [ 0 ],
					],
				]
			)
		);

		$persisted = (string) WordPressTestState::$posts[4323]->post_content;
		self::assert_before( 'order', 'IntroPat', 'Anchor', $persisted, 'before_block_path must land the pattern ahead of the anchor.' );
	}

	public function test_execute_inserts_pattern_after_anchor(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 4324 );
		$this->register_pattern( 'fa-test/outro', $this->paragraph( 'OutroPat' ) );

		TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/outro',
						'placement'   => 'after_block_path',
						'targetPath'  => [ 0 ],
					],
				]
			)
		);

		$persisted = (string) WordPressTestState::$posts[4324]->post_content;
		self::assert_before( 'order', 'Anchor', 'OutroPat', $persisted, 'after_block_path must land the pattern behind the anchor.' );
	}

	public function test_execute_inserts_pattern_at_start(): void {
		$this->seed_part( $this->paragraph( 'Existing' ), 4325 );
		$this->register_pattern( 'fa-test/lead', $this->paragraph( 'LeadPat' ) );

		TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/lead',
						'placement'   => 'start',
					],
				]
			)
		);

		$persisted = (string) WordPressTestState::$posts[4325]->post_content;
		self::assert_before( 'order', 'LeadPat', 'Existing', $persisted, 'start must prepend the pattern.' );
	}

	public function test_execute_inserts_pattern_at_end(): void {
		$this->seed_part( $this->paragraph( 'Existing' ), 4326 );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$persisted = (string) WordPressTestState::$posts[4326]->post_content;
		self::assert_before( 'order', 'Existing', 'TailPat', $persisted, 'end must append the pattern.' );
	}

	public function test_execute_applies_mixed_remove_and_insert_in_one_pass(): void {
		$content = $this->paragraph( 'AAA' ) . $this->paragraph( 'BBB' ) . $this->paragraph( 'CCC' );
		$this->seed_part( $content, 4327 );
		$this->register_pattern( 'fa-test/beta', $this->paragraph( 'BetaPat' ) );

		TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0 ],
						'expectedBlockName' => 'core/paragraph',
					],
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/beta',
						'placement'   => 'after_block_path',
						'targetPath'  => [ 2 ],
					],
				]
			)
		);

		$persisted = (string) WordPressTestState::$posts[4327]->post_content;
		$this->assertStringNotContainsString( 'AAA', $persisted, 'remove [0] must drop the first paragraph.' );
		self::assert_before( 'order', 'BBB', 'CCC', $persisted, 'survivors keep their order.' );
		self::assert_before( 'order', 'CCC', 'BetaPat', $persisted, 'insert after [2] must land behind the frozen anchor.' );
	}

	public function test_execute_materializes_theme_file_part_and_invalidates_cache(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$inserted_posts, 'A theme-file part must be materialized via wp_insert_post.' );
		$inserted = WordPressTestState::$inserted_posts[0];
		$this->assertSame( 'wp_template_part', $inserted['post_type'] );
		$this->assertStringContainsString( 'TailPat', (string) $inserted['post_content'] );
		$this->assertSame( $result['after']['content'], (string) $inserted['post_content'] );
		$this->assertNotEmpty( WordPressTestState::$cleaned_post_caches, 'clean_post_cache must run after the write (R7).' );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'A theme-file part is inserted, never updated.' );
	}

	public function test_materialization_rejects_an_active_theme_switch_before_the_write(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		WordPressTestState::$block_templates_read_hook = static function (): void {
			WordPressTestState::$active_theme = [ 'stylesheet' => 'othertheme' ];
		};

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_materialization_fails_retryably_when_the_canonical_target_is_locked(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		$lock = MaterializationLock::acquire( 'template-part', self::PART_ID );
		$this->assertInstanceOf( MaterializationLock::class, $lock );

		try {
			$result = TemplatePartApplyExecutor::execute(
				$this->entry(
					[
						[
							'type'        => 'insert_pattern',
							'patternName' => 'fa-test/tail',
							'placement'   => 'end',
						],
					]
				)
			);
		} finally {
			$lock->release();
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_materialization_locked', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_materialization_releases_the_lock_when_the_insert_throws(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				unset( $data );

				throw new \RuntimeException( 'template-part insert interrupted' );
			}
		);

		try {
			TemplatePartApplyExecutor::execute(
				$this->entry(
					[
						[
							'type'        => 'insert_pattern',
							'patternName' => 'fa-test/tail',
							'placement'   => 'end',
						],
					]
				)
			);
			$this->fail( 'Expected the insert hook to interrupt template-part materialization.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'template-part insert interrupted', $error->getMessage() );
		}

		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_materialization_lock_blocks_a_nested_update_before_attestation(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		$entry  = $this->entry(
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/tail',
					'placement'   => 'end',
				],
			]
		);
		$nested = null;
		add_action(
			'wp_after_insert_post',
			static function ( int $post_id, \WP_Post $post ) use ( $entry, &$nested ): void {
				unset( $post_id );

				if ( 'wp_template_part' === $post->post_type && null === $nested ) {
					$nested = TemplatePartApplyExecutor::execute( $entry );
				}
			},
			10,
			2
		);

		$result = TemplatePartApplyExecutor::execute( $entry );

		$this->assertIsArray( $result );
		$this->assertInstanceOf( \WP_Error::class, $nested );
		$this->assertSame( 'flavor_agent_apply_materialization_locked', $nested->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_materialization_preserves_the_exact_mixed_case_stylesheet_identity(): void {
		$ref = 'MyTheme//header';
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		WordPressTestState::$block_templates['wp_template_part'][0]->id = $ref;
		WordPressTestState::$active_theme                               = [ 'stylesheet' => 'MyTheme' ];
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		$entry                              = $this->entry(
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/tail',
					'placement'   => 'end',
				],
			]
		);
		$entry['target']['templatePartId']  = $ref;
		$entry['target']['templatePartRef'] = $ref;
		$entry['document']['entityId']      = $ref;
		$entry['document']['scopeKey']      = 'wp_template_part:' . $ref;

		$result = TemplatePartApplyExecutor::execute( $entry );

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertSame( [ 'MyTheme' ], WordPressTestState::$object_terms[ $post_id ]['wp_theme'] );
	}

	public function test_materialization_removes_the_row_when_taxonomy_assignment_is_skipped(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		WordPressTestState::$skip_insert_taxonomy_assignment = true;

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_post_write_read_failed', $result->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertSame( [ $post_id ], WordPressTestState::$deleted_posts );
		$this->assertArrayNotHasKey( $post_id, WordPressTestState::$posts );
		$this->assertArrayNotHasKey( $post_id, WordPressTestState::$object_terms );
	}

	public function test_materialization_identity_failure_fails_closed_when_the_row_cannot_be_removed(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		WordPressTestState::$skip_insert_taxonomy_assignment = true;
		WordPressTestState::$delete_post_short_circuits      = true;

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_write_failed', $result->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertArrayHasKey( $post_id, WordPressTestState::$posts );
	}

	/**
	 * Covers the wp_id 0 -> 9201 concurrency race end to end.
	 *
	 * NOTE ON WHAT THIS DOES AND DOES NOT PIN. This test does NOT discriminate
	 * $fresh from the stale start-of-execute object. With the slug unchanged,
	 * reverting persist( $fresh, ... ) to persist( $part, ... ) reaches
	 * reconcile_existing_row(), which finds the same wp_id=9201 row by slug and
	 * updates it in place — so inserted_posts stays [] and updated_posts[0]['ID']
	 * is still 9201, and every assertion below passes either way. The threading
	 * and the reconcile guard converge here by construction.
	 *
	 * The assertions that DO fail when the entity threading is reverted are
	 * test_materialization_writes_the_regated_identity() and
	 * test_undo_materialization_writes_the_regated_identity(), which diverge the
	 * identity fields persist() actually writes.
	 */
	public function test_materialization_race_writes_through_the_regated_entity(): void {
		$content = $this->paragraph( 'Anchor' );
		$this->seed_part( $content, 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		WordPressTestState::$block_templates_read_hook = function () use ( $content ): void {
			$this->seed_part( $content, 9201, 'header', 'header' );
		};

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], WordPressTestState::$inserted_posts, 'The write must follow the re-gated entity, not insert a duplicate from the stale read.' );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( 9201, WordPressTestState::$updated_posts[0]['ID'] );
		$this->assertStringContainsString( 'TailPat', (string) WordPressTestState::$posts[9201]->post_content );
	}

	/**
	 * Discriminating guard for persist( $fresh, ... ) in execute().
	 *
	 * Both reads see an unmaterialized theme-file part (wp_id=0), so persist()
	 * takes the materialization branch and reconcile_existing_row() finds nothing
	 * to reconcile against — it cannot mask the stale object. The identity fields
	 * persist() writes (post_title, wp_template_part_area) therefore come from
	 * whichever object was passed, and the re-gated read diverges on both.
	 *
	 * Models a theme update that relabels a part while its markup is byte
	 * identical. Reverting :105 to persist( $part, ... ) writes 'Header'/'header'
	 * and fails this test.
	 */
	public function test_materialization_writes_the_regated_identity(): void {
		$content = $this->paragraph( 'Anchor' );
		$this->seed_part( $content, 0, 'header', 'header', 'Header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		WordPressTestState::$block_templates_read_hook = function () use ( $content ): void {
			$this->seed_part( $content, 0, 'footer', 'header', 'Site Header' );
		};

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$inserted = WordPressTestState::$inserted_posts[0];
		$this->assertSame(
			'Site Header',
			(string) $inserted['post_title'],
			'The materialized row must carry the re-gated title, not the stale start-of-execute one.'
		);
		$this->assertSame(
			[ 'footer' ],
			$inserted['tax_input']['wp_template_part_area'] ?? null,
			'The materialized row must carry the re-gated area.'
		);
	}

	/**
	 * Discriminating guard for persist( $fresh, ... ) in undo() (line ~234),
	 * which had no coverage of its own. Same construction as the execute()
	 * counterpart: unmaterialized on both reads, identity diverged by the hook.
	 */
	public function test_undo_materialization_writes_the_regated_identity(): void {
		$before = $this->paragraph( 'Original' );
		$after  = $this->paragraph( 'Changed' );
		$this->seed_part( $after, 0, 'header', 'header', 'Header' );

		WordPressTestState::$block_templates_read_hook = function () use ( $after ): void {
			$this->seed_part( $after, 0, 'footer', 'header', 'Site Header' );
		};

		$result = TemplatePartApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$inserted = WordPressTestState::$inserted_posts[0];
		$this->assertSame(
			'Site Header',
			(string) $inserted['post_title'],
			'The undo write must follow the re-gated entity, not the pre-drift-check read.'
		);
		$this->assertSame(
			[ 'footer' ],
			$inserted['tax_input']['wp_template_part_area'] ?? null
		);
		$this->assertStringContainsString( 'Original', (string) $inserted['post_content'] );
	}

	public function test_persist_duplicate_row_guard_updates_concurrent_materialization_in_place(): void {
		$content = $this->paragraph( 'Anchor' );

		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'id'      => self::PART_ID,
				'wp_id'   => 0,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Header',
				'content' => $content,
			],
		];
		WordPressTestState::$before_block_templates_query        = static function ( array $query, string $template_type ) use ( $content ): void {
			if ( 'wp_template_part' !== $template_type || empty( $query['slug__in'] ) ) {
				return;
			}

			WordPressTestState::$before_block_templates_query          = null;
			WordPressTestState::$block_templates['wp_template_part'][] = (object) [
				'id'      => self::PART_ID,
				'wp_id'   => 9202,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Header',
				'content' => $content,
			];
			WordPressTestState::$posts[9202]                           = new \WP_Post(
				[
					'ID'           => 9202,
					'post_type'    => 'wp_template_part',
					'post_name'    => 'header',
					'post_content' => $content,
				]
			);
			WordPressTestState::$object_terms[9202]                    = [
				'wp_theme'              => [ 'twentytwentyfive' ],
				'wp_template_part_area' => [ 'header' ],
			];
		};
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], WordPressTestState::$inserted_posts, 'The duplicate-row guard must update the concurrent row.' );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( 9202, WordPressTestState::$updated_posts[0]['ID'] );
		$this->assertStringContainsString( 'TailPat', (string) WordPressTestState::$posts[9202]->post_content );
	}

	public function test_persist_duplicate_row_guard_finds_the_canonical_row_after_an_unrelated_candidate(): void {
		$content = $this->paragraph( 'Anchor' );

		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'id'      => 'plugin//header',
				'wp_id'   => 9220,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Plugin Header',
				'content' => $this->paragraph( 'Unrelated' ),
			],
			(object) [
				'id'      => self::PART_ID,
				'wp_id'   => 0,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Header',
				'content' => $content,
			],
		];
		WordPressTestState::$before_block_templates_query        = static function ( array $query, string $template_type ) use ( $content ): void {
			if ( 'wp_template_part' !== $template_type || empty( $query['slug__in'] ) ) {
				return;
			}

			WordPressTestState::$before_block_templates_query          = null;
			WordPressTestState::$block_templates['wp_template_part'][] = (object) [
				'id'      => self::PART_ID,
				'wp_id'   => 9221,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Header',
				'content' => $content,
			];
			WordPressTestState::$posts[9221]                           = new \WP_Post(
				[
					'ID'           => 9221,
					'post_type'    => 'wp_template_part',
					'post_name'    => 'header',
					'post_content' => $content,
				]
			);
			WordPressTestState::$object_terms[9221]                    = [
				'wp_theme'              => [ 'twentytwentyfive' ],
				'wp_template_part_area' => [ 'header' ],
			];
		};
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( 9221, WordPressTestState::$updated_posts[0]['ID'] );
		$this->assertStringContainsString( 'TailPat', (string) WordPressTestState::$posts[9221]->post_content );
	}

	public function test_post_write_identity_race_removes_its_row_and_reconciles_the_winner(): void {
		$content = $this->paragraph( 'Anchor' );
		$this->seed_part( $content, 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		add_action(
			'wp_after_insert_post',
			static function ( int $post_id, \WP_Post $post ) use ( $content ): void {
				if ( 'wp_template_part' !== $post->post_type ) {
					return;
				}

				$winner_id                                      = $post_id + 1;
				WordPressTestState::$posts[ $winner_id ]        = new \WP_Post(
					[
						'ID'           => $winner_id,
						'post_type'    => 'wp_template_part',
						'post_status'  => 'publish',
						'post_name'    => 'header',
						'post_title'   => 'Header',
						'post_content' => $content,
					]
				);
				WordPressTestState::$object_terms[ $winner_id ] = [
					'wp_theme'              => [ 'twentytwentyfive' ],
					'wp_template_part_area' => [ 'header' ],
				];
				WordPressTestState::$block_templates['wp_template_part'][] = (object) [
					'id'      => self::PART_ID,
					'wp_id'   => $winner_id,
					'slug'    => 'header',
					'area'    => 'header',
					'title'   => 'Header',
					'content' => $content,
				];
			},
			10,
			2
		);

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$inserted_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$winner_id   = $inserted_id + 1;
		$this->assertSame( [ $inserted_id ], WordPressTestState::$deleted_posts );
		$this->assertArrayNotHasKey( $inserted_id, WordPressTestState::$posts );
		$this->assertSame( $winner_id, WordPressTestState::$updated_posts[0]['ID'] );
		$this->assertStringContainsString( 'TailPat', (string) WordPressTestState::$posts[ $winner_id ]->post_content );
	}

	public function test_persist_duplicate_row_guard_rejects_a_same_slug_different_part_id_before_content_or_writes(): void {
		$content       = $this->paragraph( 'Anchor' );
		$content_reads = 0;
		$candidate     = new class( $content, $content_reads ) {
			public string $id   = 'twentytwentyfive//header-concurrent';
			public int $wp_id   = 9209;
			public string $slug = 'header';
			private string $stored_content;
			private $content_reads;

			public function __construct( string $stored_content, int &$content_reads ) {
				$this->stored_content = $stored_content;
				$this->content_reads  =& $content_reads;
			}

			public function __isset( string $name ): bool {
				return 'content' === $name;
			}

			public function __get( string $name ): mixed {
				if ( 'content' === $name ) {
					++$this->content_reads;
					return $this->stored_content;
				}

				return null;
			}
		};
		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'id'      => self::PART_ID,
				'wp_id'   => 0,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Header',
				'content' => $content,
			],
			$candidate,
		];
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
		$this->assertSame( 0, $content_reads );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_persist_duplicate_row_guard_accepts_an_already_desired_state_without_rewriting(): void {
		$content         = $this->paragraph( 'Anchor' );
		$pattern_content = $this->paragraph( 'TailPat' );
		$desired         = serialize_blocks(
			array_merge(
				parse_blocks( $content ),
				parse_blocks( $pattern_content )
			)
		);

		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'id'      => self::PART_ID,
				'wp_id'   => 0,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Header',
				'content' => $content,
			],
		];
		WordPressTestState::$before_block_templates_query        = static function ( array $query, string $template_type ) use ( $desired ): void {
			if ( 'wp_template_part' !== $template_type || empty( $query['slug__in'] ) ) {
				return;
			}

			WordPressTestState::$before_block_templates_query          = null;
			WordPressTestState::$block_templates['wp_template_part'][] = (object) [
				'id'      => self::PART_ID,
				'wp_id'   => 9203,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Header',
				'content' => $desired,
			];
			WordPressTestState::$posts[9203]                           = new \WP_Post(
				[
					'ID'           => 9203,
					'post_type'    => 'wp_template_part',
					'post_name'    => 'header',
					'post_content' => $desired,
				]
			);
			WordPressTestState::$object_terms[9203]                    = [
				'wp_theme'              => [ 'twentytwentyfive' ],
				'wp_template_part_area' => [ 'header' ],
			];
		};
		$this->register_pattern( 'fa-test/tail', $pattern_content );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $desired, $result['after']['content'] );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_persist_duplicate_row_guard_rejects_divergent_concurrent_content(): void {
		$content    = $this->paragraph( 'Anchor' );
		$concurrent = $this->paragraph( 'Concurrent edit' );

		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'id'      => self::PART_ID,
				'wp_id'   => 0,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Header',
				'content' => $content,
			],
			(object) [
				'id'      => self::PART_ID,
				'wp_id'   => 9204,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Header',
				'content' => $concurrent,
			],
		];
		WordPressTestState::$posts[9204]                         = new \WP_Post(
			[
				'ID'           => 9204,
				'post_type'    => 'wp_template_part',
				'post_name'    => 'header',
				'post_content' => $concurrent,
			]
		);
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $concurrent, (string) WordPressTestState::$posts[9204]->post_content );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	/**
	 * A suffixed slug means something already owns slug+theme. When no published
	 * row owns it, the collision is NOT a concurrent materialization (core's
	 * uniquifier also counts private rows, which the publish-only probe cannot
	 * see), so the orphan is removed and the failure is reported as a slug
	 * conflict rather than as phantom concurrency. Reporting concurrency here
	 * would send the operator chasing a writer that does not exist, and the
	 * condition does not self-heal on retry.
	 */
	public function test_materialization_slug_conflict_removes_the_orphan_and_reports_accurately(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 'wp_template_part' === ( $data['post_type'] ?? '' ) ) {
					$data['post_name'] = 'header-2';
				}

				return $data;
			},
			10,
			4
		);

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_slug_conflict', $result->get_error_code() );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$this->assertCount( 1, WordPressTestState::$deleted_posts );
		$this->assertArrayNotHasKey( WordPressTestState::$deleted_posts[0], WordPressTestState::$posts );
	}

	/**
	 * When the slug suffix WAS caused by a genuine concurrent materialization,
	 * the row becomes visible only after our insert. The post-insert re-probe
	 * must drop our orphan and reconcile against the winner rather than failing
	 * the operator out on a race the guard can safely resolve.
	 */
	public function test_materialization_slug_race_reconciles_against_the_winning_row(): void {
		$content = $this->paragraph( 'Anchor' );
		$this->seed_part( $content, 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		// Simulate the winner committing inside our insert: force the suffix and
		// publish the concurrent row in the same step, so only the post-insert
		// re-probe can see it.
		add_filter(
			'wp_insert_post_data',
			function ( array $data ) use ( $content ): array {
				if ( 'wp_template_part' !== ( $data['post_type'] ?? '' ) ) {
					return $data;
				}

				$data['post_name'] = 'header-2';

				WordPressTestState::$block_templates['wp_template_part'][] = (object) [
					'id'      => self::PART_ID,
					'wp_id'   => 9205,
					'slug'    => 'header',
					'area'    => 'header',
					'title'   => 'Header',
					'content' => $content,
				];
				WordPressTestState::$posts[9205]                           = new \WP_Post(
					[
						'ID'           => 9205,
						'post_type'    => 'wp_template_part',
						'post_name'    => 'header',
						'post_content' => $content,
					]
				);
				WordPressTestState::$object_terms[9205]                    = [
					'wp_theme'              => [ 'twentytwentyfive' ],
					'wp_template_part_area' => [ 'header' ],
				];

				return $data;
			},
			10,
			4
		);

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$deleted_posts, 'The suffixed orphan must be removed before reconciling.' );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( 9205, WordPressTestState::$updated_posts[0]['ID'], 'The winning row must be updated in place.' );
		$this->assertStringContainsString( 'TailPat', (string) WordPressTestState::$posts[9205]->post_content );
		// The winner carries the same canonical part id; only this identity is safe
		// to reconcile after the insert race. The result retains that executor-owned
		// subject for the Ring III attestation.
		$this->assertSame( self::PART_ID, $result['target']['templatePartId'] );
		$this->assertSame( self::PART_ID, $result['target']['templatePartRef'] );
	}

	// ---------------------------------------------------------------------
	// execute() — fail closed, zero writes (atomicity).
	// ---------------------------------------------------------------------

	public function test_execute_fails_closed_on_block_name_mismatch_without_writing(): void {
		// The live block at [0] is a paragraph; the stored op lies that it is a heading.
		$this->seed_part( $this->paragraph( 'Body' ), 99 );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0 ],
						'expectedBlockName' => 'core/heading',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'A re-validation failure must not write.' );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_execute_enforces_the_request_time_expected_target(): void {
		$content = '<!-- wp:group -->'
			. $this->paragraph( 'Child' )
			. '<!-- /wp:group -->';
		$this->seed_part( $content, 100, 'header' );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0 ],
						'expectedBlockName' => 'core/group',
						'expectedTarget'    => [
							'name'       => 'core/group',
							'childCount' => 0,
						],
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_undo_returns_the_post_persist_content_after_a_save_filter_changes_it(): void {
		$before = $this->paragraph( 'Original' );
		$after  = $this->paragraph( 'Changed' );
		$this->seed_part( $after, 55 );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( isset( $data['post_content'] ) ) {
					$data['post_content'] = str_replace( '>Original<', '>Original saved<', (string) $data['post_content'] );
				}

				return $data;
			},
			10,
			4
		);

		$result = TemplatePartApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame( WordPressTestState::$posts[55]->post_content, $result['after']['content'] );
		$this->assertStringContainsString( '>Original saved<', $result['after']['content'] );
	}

	public function test_undo_fails_when_a_save_hook_moves_the_post_off_the_canonical_ref(): void {
		$before = $this->paragraph( 'Original' );
		$after  = $this->paragraph( 'Changed' );
		$this->seed_part( $after, 9515, 'header' );
		WordPressTestState::$posts[9515]->post_name                      = 'header';
		WordPressTestState::$object_terms[9515]['wp_theme']              = [ 'twentytwentyfive' ];
		WordPressTestState::$object_terms[9515]['wp_template_part_area'] = [ 'header' ];
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 9515 === (int) ( $data['ID'] ?? 0 ) ) {
					$data['post_name'] = 'header-moved';
					WordPressTestState::$block_templates['wp_template_part'][0]->id   = 'twentytwentyfive//header-moved';
					WordPressTestState::$block_templates['wp_template_part'][0]->slug = 'header-moved';
				}

				return $data;
			},
			10,
			4
		);

		$result = TemplatePartApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_post_write_read_failed', $result->get_error_code() );
		$this->assertSame( 'header-moved', WordPressTestState::$posts[9515]->post_name );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_undo_regates_live_content_after_acquiring_the_target_lock(): void {
		$before     = $this->paragraph( 'Original' );
		$after      = $this->paragraph( 'Changed' );
		$concurrent = $after . $this->paragraph( 'Concurrent edit' );
		$this->seed_part( $after, 9512, 'header' );
		WordPressTestState::$before_materialization_lock_insert = function () use ( $concurrent ): void {
			$this->seed_part( $concurrent, 9512, 'header' );
		};

		$result = TemplatePartApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( $concurrent, WordPressTestState::$posts[9512]->post_content );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_undo_captures_persisted_content_before_releasing_the_target_lock(): void {
		$before = $this->paragraph( 'Original' );
		$after  = $this->paragraph( 'Changed' );
		$later  = $this->paragraph( 'Later writer' );
		$this->seed_part( $after, 9513, 'header' );
		WordPressTestState::$after_materialization_lock_delete = static function () use ( $later ): void {
			WordPressTestState::$posts[9513]->post_content                       = $later;
			WordPressTestState::$block_templates['wp_template_part'][0]->content = $later;
		};

		$result = TemplatePartApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame( $before, $result['after']['content'] );
		$this->assertSame( $later, WordPressTestState::$posts[9513]->post_content );
		$this->assertSame( [], self::materialization_locks() );
	}

	public function test_execute_fails_closed_on_unregistered_pattern_without_writing(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 99 );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/never-registered',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_execute_returns_error_when_no_operations(): void {
		$this->seed_part( $this->paragraph( 'Body' ), 99 );

		$result = TemplatePartApplyExecutor::execute( $this->entry( [] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_execute_fails_closed_when_part_changes_before_persist(): void {
		// Parity with StyleApplyExecutor's final unchanged gate: a concurrent Site
		// Editor / wp-cli save landing AFTER execute()'s initial read but BEFORE the
		// write must abort with zero writes, not silently clobber the live part.
		$group = '<!-- wp:group -->'
			. '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
			. $this->paragraph( 'Body' )
			. '<!-- /wp:group -->';
		$this->seed_part( $group, 7100, 'header' );

		// Append a sibling top-level block: the targeted remove [0,0] still
		// re-validates against the live tree, but the whole-part content hash moves.
		$changed                                       = $group . $this->paragraph( 'Concurrent edit' );
		WordPressTestState::$block_templates_read_hook = function () use ( $changed ): void {
			$this->seed_part( $changed, 7100, 'header' );
		};

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 0, 0 ],
						'expectedBlockName' => 'core/heading',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'A pre-persist concurrent change must not write.' );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	// ---------------------------------------------------------------------
	// apply_operations() — R1 single descending-pass ordering + fail-closed
	// guards in isolation (no validator rebuild).
	// ---------------------------------------------------------------------

	public function test_apply_operations_multi_insert_lands_both_at_intended_gaps(): void {
		$blocks = parse_blocks( $this->paragraph( 'AAA' ) . $this->paragraph( 'BBB' ) . $this->paragraph( 'CCC' ) );
		$this->register_pattern( 'fa-test/alpha', $this->paragraph( 'AlphaPat' ) );
		$this->register_pattern( 'fa-test/beta', $this->paragraph( 'BetaPat' ) );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/alpha',
					'placement'   => 'after_block_path',
					'targetPath'  => [ 0 ],
				],
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/beta',
					'placement'   => 'after_block_path',
					'targetPath'  => [ 2 ],
				],
			]
		);

		$this->assertIsArray( $result );
		$markup = serialize_blocks( $result );
		// Intended: AAA, AlphaPat, BBB, CCC, BetaPat — proves an earlier insert
		// never shifts a later op's frozen path (R1).
		self::assert_before( 'order', 'AAA', 'AlphaPat', $markup, 'alpha after [0]' );
		self::assert_before( 'order', 'AlphaPat', 'BBB', $markup, 'alpha sits in the [0] gap' );
		self::assert_before( 'order', 'CCC', 'BetaPat', $markup, 'beta after [2]' );
	}

	public function test_apply_operations_replace_then_insert_after_compose_correctly(): void {
		$blocks = parse_blocks(
			$this->paragraph( 'AAA' ) . $this->paragraph( 'BBB' ) . $this->paragraph( 'CCC' ) . $this->paragraph( 'DDD' )
		);
		$this->register_pattern( 'fa-test/twoblock', $this->paragraph( 'PPP' ) . $this->paragraph( 'QQQ' ) );
		$this->register_pattern( 'fa-test/beta', $this->paragraph( 'RRR' ) );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'              => 'replace_block_with_pattern',
					'patternName'       => 'fa-test/twoblock',
					'targetPath'        => [ 1 ],
					'expectedBlockName' => 'core/paragraph',
					'expectedTarget'    => [
						'name'       => 'core/paragraph',
						'childCount' => 0,
					],
				],
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/beta',
					'placement'   => 'after_block_path',
					'targetPath'  => [ 3 ],
				],
			]
		);

		$this->assertIsArray( $result );
		$markup = serialize_blocks( $result );
		// Intended: AAA, PPP, QQQ, CCC, DDD, RRR — a 1->N replace before [3]
		// must not shift the frozen insert-after-[3] anchor (R1).
		$this->assertStringNotContainsString( 'BBB', $markup );
		self::assert_before( 'order', 'AAA', 'PPP', $markup, 'replace expands in place' );
		self::assert_before( 'order', 'QQQ', 'CCC', $markup, 'replacement precedes survivors' );
		self::assert_before( 'order', 'DDD', 'RRR', $markup, 'insert after [3] stays anchored to DDD' );
	}

	public function test_apply_operations_mixed_remove_and_insert_compose_correctly(): void {
		$blocks = parse_blocks( $this->paragraph( 'AAA' ) . $this->paragraph( 'BBB' ) . $this->paragraph( 'CCC' ) );
		$this->register_pattern( 'fa-test/beta', $this->paragraph( 'BetaPat' ) );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/paragraph',
					'expectedTarget'    => [
						'name'       => 'core/paragraph',
						'childCount' => 0,
					],
				],
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/beta',
					'placement'   => 'after_block_path',
					'targetPath'  => [ 2 ],
				],
			]
		);

		$this->assertIsArray( $result );
		$markup = serialize_blocks( $result );
		$this->assertStringNotContainsString( 'AAA', $markup );
		self::assert_before( 'order', 'CCC', 'BetaPat', $markup, 'remove [0] must not shift the frozen insert-after-[2] anchor' );
	}

	public function test_apply_operations_fails_closed_on_child_count_drift(): void {
		$blocks = parse_blocks( '<!-- wp:group -->' . $this->paragraph( 'X' ) . '<!-- /wp:group -->' );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/group',
					'expectedTarget'    => [
						'name'       => 'core/group',
						'childCount' => 5,
					],
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertStringContainsString( 'X', serialize_blocks( $blocks ), 'Phase 1 must abort before any mutation.' );
	}

	public function test_apply_operations_fails_closed_on_block_type_drift(): void {
		$blocks = parse_blocks( $this->paragraph( 'X' ) );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/columns',
					'expectedTarget'    => [
						'name'       => 'core/columns',
						'childCount' => 0,
					],
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
	}

	public function test_apply_operations_fails_closed_on_unresolved_pattern(): void {
		$blocks = parse_blocks( $this->paragraph( 'Anchor' ) );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/missing',
					'placement'   => 'end',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_pattern_unavailable', $result->get_error_code() );
		$this->assertSame( $this->paragraph( 'Anchor' ), serialize_blocks( $blocks ), 'Phase 2 must abort before any mutation.' );
	}

	public function test_apply_operations_fails_closed_on_blockless_pattern_markup(): void {
		// A registered pattern whose markup carries no block delimiters resolves to
		// zero blocks after the freeform filter. Without the guard this would
		// silently degrade an insert into a delete; it must fail closed instead.
		$this->register_pattern( 'fa-test/blockless', 'Just plain prose, no block delimiters here.' );
		$blocks = parse_blocks( $this->paragraph( 'Anchor' ) );

		$result = self::apply_ops(
			$blocks,
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'fa-test/blockless',
					'placement'   => 'end',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_pattern_unavailable', $result->get_error_code() );
		$this->assertSame( $this->paragraph( 'Anchor' ), serialize_blocks( $blocks ), 'Blockless pattern must abort before any mutation.' );
	}

	// ---------------------------------------------------------------------
	// undo() — drift-checked content restore (mirrors StyleApplyExecutor::undo).
	// Writes are captured through the same $posts/$updated_posts stub the
	// execute() tests use; there is NO filter seam (R5).
	// ---------------------------------------------------------------------

	public function test_undo_restores_before_content_when_live_matches_after(): void {
		$before = $this->paragraph( 'Original' );
		$after  = $this->paragraph( 'Changed' );
		// Live part == after: this is the row we just applied, untouched since.
		$this->seed_part( $after, 55 );

		$result = TemplatePartApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame(
			serialize_blocks( parse_blocks( $before ) ),
			serialize_blocks( parse_blocks( (string) WordPressTestState::$posts[55]->post_content ) ),
			'undo must restore the before snapshot into the live part.'
		);
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_undo_fails_closed_on_drift_without_writing(): void {
		// Live part is neither before nor after: someone edited it after our apply.
		$this->seed_part( '<!-- wp:heading --><h2>Edited elsewhere</h2><!-- /wp:heading -->', 55 );

		$result = TemplatePartApplyExecutor::undo(
			self::executed_entry(
				$this->paragraph( 'Original' ),
				$this->paragraph( 'Changed' )
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_drift', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'A drift failure must not write.' );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_undo_is_idempotent_when_live_already_matches_before(): void {
		$before = $this->paragraph( 'Original' );
		$after  = $this->paragraph( 'Changed' );
		// Live part == before: already rolled back; undo must be a no-op.
		$this->seed_part( $before, 55 );

		$result = TemplatePartApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'already_undone', $result['result'] );
		$this->assertSame( $before, $result['after']['content'] );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'An already-undone row must not write.' );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_undo_snapshot_unsupported_when_content_missing(): void {
		// The part resolves fine, but the row lacks the after snapshot.
		$this->seed_part( $this->paragraph( 'Live' ), 55 );

		$result = TemplatePartApplyExecutor::undo(
			[
				'surface' => 'template-part',
				'target'  => [ 'templatePartId' => self::PART_ID ],
				'before'  => [ 'content' => $this->paragraph( 'Original' ) ],
				// 'after' content intentionally omitted.
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_snapshot_unsupported', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	/**
	 * R7 round-trip freshness: a real execute() followed by undo() must compose.
	 *
	 * execute() persists the after-content and busts the post cache; undo() then
	 * resolves the live part FRESH. We model the cache-busted DB re-read by
	 * re-seeding the same block-template stub the resolver reads. Because the
	 * static ServerCollector/TemplateRepository instances persist across both
	 * calls, the only way undo() returns 'undone' (rather than mis-reading the
	 * stale pre-apply 'before' as live and returning 'already_undone') is if
	 * resolve_part genuinely re-reads the post-apply content — guarding against a
	 * false-positive drift caused by a stale resolution cache on the round-trip.
	 */
	public function test_undo_after_execute_reads_fresh_content_and_restores_before(): void {
		$wp_id  = 770;
		$before = $this->paragraph( 'Keep' ) . $this->paragraph( 'DropMe' );
		$this->seed_part( $before, $wp_id );

		$executed = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'              => 'remove_block',
						'targetPath'        => [ 1 ],
						'expectedBlockName' => 'core/paragraph',
					],
				]
			)
		);

		$this->assertIsArray( $executed );
		$after = (string) $executed['after']['content'];
		$this->assertStringNotContainsString( 'DropMe', $after );
		$this->assertSame(
			$after,
			(string) WordPressTestState::$posts[ $wp_id ]->post_content,
			'execute() must persist the after-content to the live post.'
		);
		$this->assertNotEmpty( WordPressTestState::$cleaned_post_caches, 'execute() must bust the post cache (R7).' );

		// Model the cache-busted DB re-read: the live part now resolves to the
		// persisted after-content through the same stub the resolver reads.
		$this->seed_part( (string) WordPressTestState::$posts[ $wp_id ]->post_content, $wp_id );

		$undo = TemplatePartApplyExecutor::undo(
			self::executed_entry( (string) $executed['before']['content'], $after )
		);

		$this->assertIsArray( $undo );
		$this->assertSame( 'undone', $undo['result'], 'A fresh resolve must see the after-content as live and undo cleanly.' );
		$this->assertSame(
			serialize_blocks( parse_blocks( $before ) ),
			serialize_blocks( parse_blocks( (string) WordPressTestState::$posts[ $wp_id ]->post_content ) ),
			'undo must restore the original before-content into the live part.'
		);
	}

	public function test_undo_fails_closed_when_part_changes_before_restore_write(): void {
		// Parity with StyleApplyExecutor::undo's pre-write unchanged gate: even after
		// the live == after drift check passes, a concurrent save landing before the
		// restore write must abort with zero writes.
		$before = $this->paragraph( 'Original' );
		$after  = $this->paragraph( 'Changed' );
		$this->seed_part( $after, 7200 );

		$changed                                       = $after . $this->paragraph( 'Concurrent edit' );
		WordPressTestState::$block_templates_read_hook = function () use ( $changed ): void {
			$this->seed_part( $changed, 7200 );
		};

		$result = TemplatePartApplyExecutor::undo( self::executed_entry( $before, $after ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_changed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'A pre-restore concurrent change must not write.' );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	/**
	 * A canonical template-part ref is a byte-exact authorization and attestation
	 * subject. If core would normalize its slug on insert, the write would land on
	 * a different ref, so materialization must fail before creating the row.
	 */
	public function test_materialization_rejects_a_slug_that_would_change_the_canonical_ref(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'site--header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$deleted_posts );
	}

	/**
	 * target.* must describe the entity the write actually landed on, because it
	 * is what lands in the activity row and the Ring III attestation subject. The
	 * gate-2 re-resolve is the authority, not the start-of-execute read.
	 * Discriminating: reverting target to $part-> reports the pre-gate slug and
	 * area, and both assertions below fail.
	 */
	public function test_execute_reports_identity_from_the_regated_entity(): void {
		$content = $this->paragraph( 'Body' );
		$this->seed_part( $content, 9201, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		WordPressTestState::$block_templates_read_hook = static function () use ( $content ): void {
			WordPressTestState::$block_templates['wp_template_part'] = [
				(object) [
					'id'      => self::PART_ID,
					'wp_id'   => 9201,
					'slug'    => 'header-renamed',
					'area'    => 'uncategorized',
					'title'   => 'Header Renamed',
					'content' => $content,
				],
			];
		};

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'header-renamed', $result['target']['slug'] );
		$this->assertSame( 'uncategorized', $result['target']['area'] );
	}

	/**
	 * A failed post-insert read-back is not a slug collision. Falling into the
	 * collision arm would delete a row that was almost certainly written
	 * correctly and report a cause known to be false, so the executor must fail
	 * closed and LEAVE the row for an operator to reconcile.
	 */
	public function test_materialization_read_back_failure_leaves_the_row_and_reports_accurately(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 'wp_template_part' === ( $data['post_type'] ?? '' ) ) {
					WordPressTestState::$next_get_post_returns_null = true;
				}

				return $data;
			},
			10,
			4
		);

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_post_write_read_failed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$deleted_posts, 'A read-back failure must not delete the row.' );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
	}

	public function test_materialization_exact_identity_read_failure_leaves_the_row_for_reconciliation(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );
		add_action(
			'wp_after_insert_post',
			static function ( int $post_id, \WP_Post $post ): void {
				unset( $post_id );

				if ( 'wp_template_part' === $post->post_type ) {
					WordPressTestState::$next_get_block_template_returns_null = true;
				}
			},
			10,
			2
		);

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_post_write_read_failed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$deleted_posts );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$post_id = (int) WordPressTestState::$inserted_posts[0]['ID'];
		$this->assertArrayHasKey( $post_id, WordPressTestState::$posts );
	}

	/**
	 * wp_delete_post can return a WP_Post while a pre_delete_post filter
	 * short-circuits the actual deletion. Trusting the return value would strand
	 * the duplicate row AND then update the winning row too, so the executor
	 * must confirm the row is gone and fail closed when it is not.
	 */
	public function test_materialization_slug_conflict_fails_closed_when_the_orphan_cannot_be_removed(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		WordPressTestState::$delete_post_short_circuits = true;

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 'wp_template_part' === ( $data['post_type'] ?? '' ) ) {
					$data['post_name'] = 'header-2';
				}

				return $data;
			},
			10,
			4
		);

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_write_failed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'Failing to remove the orphan must not also update a winning row.' );
	}
}
