<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\StyleAbilities;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ApplyAbilitiesTest extends TestCase {

	private const GLOBAL_STYLES_ID  = '17';
	private const TEMPLATE_REF      = 'twentytwentyfive//home';
	private const TEMPLATE_PART_REF = 'twentytwentyfive//header';

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		WordPressTestState::$capabilities    = [
			'edit_theme_options' => true,
			'edit_posts'         => true,
			'edit_post'          => true,
		];
		Repository::install();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [],
			]
		);
		WordPressTestState::$global_settings = [
			'color' => [
				'palette'    => [
					'theme' => [
						[
							'slug'  => 'accent',
							'name'  => 'Accent',
							'color' => '#111111',
						],
						[
							'slug'  => 'base',
							'name'  => 'Base',
							'color' => '#fefefe',
						],
					],
				],
				'background' => true,
				'text'       => true,
			],
		];
		// A resolvable merged background complement so a solo text-color
		// operation can pass the executor's contrast check at approval time.
		WordPressTestState::$global_styles = [
			'color' => [ 'background' => '#fefefe' ],
		];
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function seed_global_styles_post( array $config ): void {
		WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ] = new \WP_Post(
			[
				'ID'           => (int) self::GLOBAL_STYLES_ID,
				'post_type'    => 'wp_global_styles',
				'post_content' => (string) wp_json_encode(
					array_merge(
						[
							'version'                     => 3,
							'isGlobalStylesUserThemeJSON' => true,
						],
						$config
					)
				),
			]
		);
	}

	private function seed_template_post( string $content, int $wp_id ): void {
		WordPressTestState::$active_theme                   = [ 'stylesheet' => 'twentytwentyfive' ];
		WordPressTestState::$block_templates['wp_template'] = [
			(object) [
				'id'      => self::TEMPLATE_REF,
				'wp_id'   => $wp_id,
				'slug'    => 'home',
				'title'   => 'Home',
				'content' => $content,
			],
		];
		WordPressTestState::$posts[ $wp_id ]                = new \WP_Post(
			[
				'ID'           => $wp_id,
				'post_type'    => 'wp_template',
				'post_name'    => 'home',
				'post_content' => $content,
			]
		);
	}

	private function seed_template_part_post( string $content, int $wp_id ): void {
		WordPressTestState::$active_theme                        = [ 'stylesheet' => 'twentytwentyfive' ];
		WordPressTestState::$block_templates['wp_template_part'] = [
			(object) [
				'id'      => self::TEMPLATE_PART_REF,
				'wp_id'   => $wp_id,
				'slug'    => 'header',
				'area'    => 'header',
				'title'   => 'Header',
				'content' => $content,
			],
		];
		WordPressTestState::$posts[ $wp_id ]                     = new \WP_Post(
			[
				'ID'           => $wp_id,
				'post_type'    => 'wp_template_part',
				'post_name'    => 'header',
				'post_content' => $content,
			]
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function agent_request_input( array $overrides = [] ): array {
		$scope         = [
			'surface'        => 'global-styles',
			'globalStylesId' => self::GLOBAL_STYLES_ID,
		];
		$style_context = [
			'currentConfig' => [
				'settings' => [],
				'styles'   => [],
			],
			'mergedConfig'  => [
				'settings' => [],
				'styles'   => [],
			],
		];
		$signatures    = StyleAbilities::recommend_style(
			[
				'scope'                => $scope,
				'styleContext'         => $style_context,
				'prompt'               => 'darker',
				'resolveSignatureOnly' => true,
			]
		);
		$this->assertIsArray( $signatures );

		return array_replace_recursive(
			[
				'scope'            => $scope,
				'styleContext'     => $style_context,
				'prompt'           => 'darker',
				'operations'       => [
					[
						'type'       => 'set_styles',
						'path'       => [ 'color', 'text' ],
						'value'      => 'var:preset|color|accent',
						'valueType'  => 'preset',
						'presetType' => 'color',
						'presetSlug' => 'accent',
					],
				],
				'signatures'       => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
				'suggestion'       => [ 'label' => 'Use the accent text preset' ],
				'requestReference' => 'agent-req-1',
			],
			$overrides
		);
	}

	public function test_request_style_apply_creates_a_pending_row_with_lifecycle_payload(): void {
		$result = ApplyAbilities::request_style_apply( $this->agent_request_input() );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertNotSame( '', (string) $result['activityId'] );
		$this->assertNotSame( '', (string) $result['expiresAt'] );

		$entry = Repository::find( (string) $result['activityId'] );
		$this->assertIsArray( $entry );
		$this->assertSame( 'apply_global_styles_suggestion', $entry['type'] );
		$this->assertSame( 'global-styles', $entry['surface'] );
		$this->assertSame( 'pending', $entry['executionResult'] );
		$this->assertSame( 'not_applicable', $entry['undo']['status'] );
		$this->assertSame( [], $entry['before'] );
		$this->assertSame( 7, $entry['apply']['requestedBy'] );
		$this->assertSame( 'agent-req-1', $entry['apply']['requestReference'] );
		$this->assertSame( 'global_styles:17', $entry['document']['scopeKey'] );
		$this->assertCount( 1, $entry['apply']['operations'] );
		$this->assertSame( 64, strlen( (string) $entry['apply']['signatures']['baselineConfigHash'] ) );
	}

	public function test_request_style_apply_rejects_mismatched_signatures_and_records_stale_blocked(): void {
		$input = $this->agent_request_input();
		$input['signatures']['resolvedContextSignature'] = str_repeat( 'f', 64 );

		$result = ApplyAbilities::request_style_apply( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );

		$diagnostics = Repository::query(
			[
				'scopeKey'           => 'global_styles:17',
				'includeDiagnostics' => true,
			]
		);
		$outcomes    = array_values(
			array_filter(
				$diagnostics,
				static fn ( array $entry ): bool => 'recommendation_outcome' === (string) ( $entry['type'] ?? '' )
			)
		);
		$this->assertCount( 1, $outcomes );
		$this->assertSame( 'stale_blocked', $outcomes[0]['after']['outcome']['event'] );
	}

	public function test_request_style_apply_rejects_when_the_live_entity_drifted_from_the_claimed_config(): void {
		$input = $this->agent_request_input();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#444444' ] ],
			]
		);

		$result = ApplyAbilities::request_style_apply( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );
	}

	public function test_request_style_apply_pending_cap_counts_an_active_decision_claim(): void {
		add_filter( 'flavor_agent_external_apply_pending_cap', static fn(): int => 1 );

		$first = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $first );
		$claim = Repository::claim_external_apply_decision( (string) $first['activityId'] );
		$this->assertIsString( $claim );

		$second = ApplyAbilities::request_style_apply( $this->agent_request_input() );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'flavor_agent_apply_queue_full', $second->get_error_code() );
	}

	public function test_request_style_apply_rejects_operations_failing_the_live_contract_without_creating_a_row(): void {
		$result = ApplyAbilities::request_style_apply(
			$this->agent_request_input(
				[
					'operations' => [
						[
							'type'       => 'set_styles',
							'path'       => [ 'color', 'text' ],
							'value'      => 'var:preset|color|nope',
							'valueType'  => 'preset',
							'presetType' => 'color',
							'presetSlug' => 'nope',
						],
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame(
			[],
			Repository::query( [ 'scopeKey' => 'global_styles:17' ] ),
			'Invalid operations must not enqueue a pending row.'
		);
	}

	public function test_request_style_apply_requires_a_supported_scope(): void {
		$result = ApplyAbilities::request_style_apply(
			$this->agent_request_input( [ 'scope' => [ 'surface' => 'navigation' ] ] )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_style_scope', $result->get_error_code() );
	}

	public function test_get_activity_returns_the_entry_and_lazily_expires_overdue_pending_rows(): void {
		$result = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $result );

		$fetched = ApplyAbilities::get_activity( [ 'activityId' => (string) $result['activityId'] ] );
		$this->assertIsArray( $fetched );
		$this->assertSame( 'pending', $fetched['entry']['apply']['status'] );

		// An overdue pending row (seeded directly, past its expiresAt) must
		// lazily expire on read — the agent observes 'expired', persisted.
		$overdue = Repository::create(
			[
				'type'            => 'apply_global_styles_suggestion',
				'surface'         => 'global-styles',
				'target'          => [ 'globalStylesId' => self::GLOBAL_STYLES_ID ],
				'suggestion'      => 'Overdue request',
				'before'          => [],
				'after'           => [],
				'executionResult' => 'pending',
				'undo'            => [ 'status' => 'not_applicable' ],
				'request'         => [
					'apply' => [
						'status'    => 'pending',
						'expiresAt' => gmdate( 'c', time() - 60 ),
					],
				],
				'document'        => [ 'scopeKey' => 'global_styles:17' ],
			]
		);
		$this->assertIsArray( $overdue );

		$expired = ApplyAbilities::get_activity( [ 'activityId' => (string) $overdue['id'] ] );
		$this->assertSame( 'expired', $expired['entry']['apply']['status'] );
		$this->assertSame(
			'expired',
			Repository::find( (string) $overdue['id'] )['apply']['status'],
			'Lazy expiry must persist.'
		);
	}

	public function test_get_activity_includes_attestation_reference_when_available(): void {
		$result = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $result );

		\FlavorAgent\Attestation\Repository::install();
		\FlavorAgent\Attestation\Repository::insert(
			[
				'attestation_id'      => 'att_activity',
				'surface'             => 'global-styles',
				'subject_name'        => 'wp_global_styles:' . self::GLOBAL_STYLES_ID,
				'subject_scope'       => 'global-styles',
				'after_digest'        => str_repeat( 'a', 64 ),
				'statement_bytes'     => '{}',
				'signature_b64'       => 'sig',
				'key_id'              => 'kid',
				'related_activity_id' => (string) $result['activityId'],
			]
		);

		$fetched = ApplyAbilities::get_activity( [ 'activityId' => (string) $result['activityId'] ] );

		$this->assertIsArray( $fetched );
		$this->assertSame( 'att_activity', $fetched['entry']['attestation']['id'] );
		$this->assertSame( $fetched['entry']['attestation'], $fetched['attestation'] );
		$this->assertSame(
			'https://example.test/wp-json/flavor-agent/v1/attestations/att_activity',
			$fetched['entry']['attestation']['verifyUrl']
		);
		$this->assertSame(
			'https://example.test/wp-json/flavor-agent/v1/attestations/att_activity/subject-state',
			$fetched['entry']['attestation']['subjectStateUrl']
		);
	}

	public function test_get_activity_returns_not_found_for_unknown_ids(): void {
		$result = ApplyAbilities::get_activity( [ 'activityId' => 'missing' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_not_found', $result->get_error_code() );
	}

	public function test_list_activity_requires_a_scope_key(): void {
		$result = ApplyAbilities::list_activity( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_invalid_entry', $result->get_error_code() );
	}

	public function test_list_activity_filters_by_scope_and_status(): void {
		$first = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $first );
		$second = ApplyAbilities::request_style_apply(
			$this->agent_request_input( [ 'requestReference' => 'agent-req-2' ] )
		);
		$this->assertIsArray( $second );
		\FlavorAgent\Activity\Repository::transition_external_apply(
			(string) $second['activityId'],
			[ 'applyStatus' => 'rejected' ]
		);

		$pending = ApplyAbilities::list_activity(
			[
				'scopeKey' => 'global_styles:17',
				'status'   => 'pending',
			]
		);
		$this->assertIsArray( $pending );
		$this->assertCount( 1, $pending['entries'] );
		$this->assertSame( (string) $first['activityId'], $pending['entries'][0]['id'] );

		$rejected = ApplyAbilities::list_activity(
			[
				'scopeKey' => 'global_styles:17',
				'status'   => 'rejected',
			]
		);
		$this->assertCount( 1, $rejected['entries'] );

		$all = ApplyAbilities::list_activity( [ 'scopeKey' => 'global_styles:17' ] );
		$this->assertCount( 2, $all['entries'] );
	}

	public function test_active_decision_claim_projects_as_pending_for_get_and_list_activity(): void {
		$created = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $created );
		$activity_id = (string) $created['activityId'];
		$claim       = Repository::claim_external_apply_decision( $activity_id );
		$this->assertIsString( $claim );

		$fetched = ApplyAbilities::get_activity( [ 'activityId' => $activity_id ] );

		$this->assertIsArray( $fetched );
		$this->assertSame( 'pending', $fetched['entry']['executionResult'] );
		$this->assertSame( 'pending', $fetched['entry']['apply']['status'] );

		$pending = ApplyAbilities::list_activity(
			[
				'scopeKey' => 'global_styles:17',
				'status'   => 'pending',
			]
		);

		$this->assertIsArray( $pending );
		$this->assertSame( [ $activity_id ], array_column( $pending['entries'], 'id' ) );
		$this->assertSame( 'pending', $pending['entries'][0]['executionResult'] );
	}

	/**
	 * Persist an executed editor-shaped Global Styles row whose snapshots
	 * match the seeded entity state.
	 *
	 * @return array<string, mixed>
	 */
	private function create_executed_style_row(): array {
		$created = \FlavorAgent\Activity\Repository::create(
			[
				'type'       => 'apply_global_styles_suggestion',
				'surface'    => 'global-styles',
				'target'     => [ 'globalStylesId' => self::GLOBAL_STYLES_ID ],
				'suggestion' => 'Accent text',
				'before'     => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'      => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
					],
					'operations' => [],
				],
				'undo'       => [ 'status' => 'available' ],
				'document'   => [
					'entityId' => self::GLOBAL_STYLES_ID,
					'postType' => 'global_styles',
					'scopeKey' => 'global_styles:17',
				],
			]
		);
		$this->assertIsArray( $created );

		return $created;
	}

	private function seed_post_blocks_post( int $post_id, string $content ): void {
		WordPressTestState::$posts[ $post_id ] = new \WP_Post(
			[
				'ID'           => $post_id,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Post ' . $post_id,
				'post_content' => $content,
			]
		);
	}

	/**
	 * @param array<string, mixed> $target_overrides
	 * @return array<string, mixed>
	 */
	private function create_executed_post_blocks_row(
		string $id,
		int $document_post_id,
		int $target_post_id,
		array $target_overrides = []
	): array {
		$before = '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->';
		$after  = '<!-- wp:heading --><h2>After</h2><!-- /wp:heading -->';
		$target = array_replace(
			[
				'postId'   => $target_post_id,
				'postType' => 'post',
			],
			$target_overrides
		);
		$row    = Repository::create(
			[
				'id'              => $id,
				'type'            => 'apply_post_blocks_suggestion',
				'surface'         => 'post-blocks',
				'target'          => $target,
				'suggestion'      => 'Replace the paragraph',
				'before'          => [ 'content' => $before ],
				'after'           => [
					'content'    => $after,
					'operations' => [],
				],
				'executionResult' => 'applied',
				'undo'            => [ 'status' => 'available' ],
				'document'        => [
					'entityId' => (string) $document_post_id,
					'postType' => 'post',
					'scopeKey' => 'post:' . $document_post_id,
				],
			]
		);
		$this->assertIsArray( $row );

		return $row;
	}

	public function test_undo_activity_restores_the_entity_and_persists_undone(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame( 'undone', $result['entry']['undo']['status'] );
		$this->assertSame( 'not_applicable', $result['entry']['undo']['attestationStatus'] );

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( [], $written['styles'] );
	}

	public function test_undo_activity_requires_recovery_when_a_newer_action_appears_during_finalization(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);
		$newer                                    = null;
		WordPressTestState::$after_wp_update_post = function () use ( &$newer ): void {
			$newer = $this->create_executed_style_row();
		};

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );

		$this->assertIsArray( $newer );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_recovery_required', $result->get_error_code() );
		$this->assertSame( 'activity_finalization', $result->get_error_data()['phase'] ?? null );
		$this->assertSame( 'available', Repository::find( (string) $row['id'] )['undo']['status'] ?? null );
		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( [], $written['styles'], 'The target changed before the activity finalization lost ordered-tail eligibility.' );
	}

	public function test_undo_activity_requires_recovery_when_final_activity_storage_fails(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);
		WordPressTestState::$before_activity_table_update = static fn (): bool => false;

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_recovery_required', $result->get_error_code() );
		$this->assertSame( 'activity_finalization', $result->get_error_data()['phase'] ?? null );
		$this->assertSame( 'available', Repository::find( (string) $row['id'] )['undo']['status'] ?? null );
		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( [], $written['styles'], 'The target changed even though its activity row could not be finalized.' );
	}

	public function test_undo_activity_reports_finalization_context_drift_without_writing_the_wrong_site_row(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);

		$original_database                                = $GLOBALS['wpdb'];
		$original_blog                                    = WordPressTestState::$current_blog_id;
		$activity_table                                   = Repository::table_name();
		$wrong_database                                   = new \wpdb();
		$wrong_table                                      = 'wp_2_flavor_agent_activity';
		$wrong_database->prefix                           = 'wp_2_';
		$wrong_database->options                          = 'wp_2_options';
		$wrong_database->posts                            = 'wp_2_posts';
		$wrong_database->postmeta                         = 'wp_2_postmeta';
		WordPressTestState::$db_tables[ $wrong_table ]    = WordPressTestState::$db_tables[ $activity_table ];
		WordPressTestState::$before_activity_table_update = static function () use ( $wrong_database ): void {
			$GLOBALS['wpdb']                     = $wrong_database;
			WordPressTestState::$current_blog_id = 2;
		};

		try {
			$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );
		} finally {
			$GLOBALS['wpdb']                     = $original_database;
			WordPressTestState::$current_blog_id = $original_blog;
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_recovery_required', $result->get_error_code() );
		$this->assertSame( 'finalization_context', $result->get_error_data()['phase'] ?? null );
		$this->assertSame( 'undone', Repository::find( (string) $row['id'] )['undo']['status'] ?? null );
		$this->assertSame(
			'available',
			json_decode( (string) ( WordPressTestState::$db_tables[ $wrong_table ][0]['undo_state'] ?? '' ), true )['status'] ?? null
		);
		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( [], $written['styles'] );
	}

	public function test_undo_activity_reports_finalization_context_when_the_activity_update_throws_after_site_drift(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);

		$original_database                                = $GLOBALS['wpdb'];
		$original_blog                                    = WordPressTestState::$current_blog_id;
		$activity_table                                   = Repository::table_name();
		$wrong_database                                   = new \wpdb();
		$wrong_table                                      = 'wp_2_flavor_agent_activity';
		$wrong_database->prefix                           = 'wp_2_';
		$wrong_database->options                          = 'wp_2_options';
		$wrong_database->posts                            = 'wp_2_posts';
		$wrong_database->postmeta                         = 'wp_2_postmeta';
		WordPressTestState::$db_tables[ $wrong_table ]    = WordPressTestState::$db_tables[ $activity_table ];
		WordPressTestState::$before_activity_table_update = static function () use ( $wrong_database ): void {
			$GLOBALS['wpdb']                     = $wrong_database;
			WordPressTestState::$current_blog_id = 2;

			throw new \RuntimeException( 'final undo activity update interrupted' );
		};

		try {
			$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );
		} finally {
			$GLOBALS['wpdb']                     = $original_database;
			WordPressTestState::$current_blog_id = $original_blog;
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_recovery_required', $result->get_error_code() );
		$this->assertSame( 'finalization_context', $result->get_error_data()['phase'] ?? null );
		$this->assertSame( 'available', Repository::find( (string) $row['id'] )['undo']['status'] ?? null );
		$this->assertSame(
			'available',
			json_decode( (string) ( WordPressTestState::$db_tables[ $wrong_table ][0]['undo_state'] ?? '' ), true )['status'] ?? null
		);
		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( [], $written['styles'] );
	}

	public function test_undo_activity_does_not_terminalize_when_context_changes_during_revert_attestation(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);
		\FlavorAgent\Attestation\Repository::install();
		\FlavorAgent\Attestation\Repository::insert(
			[
				'attestation_id'      => 'att_undo_context_prior',
				'surface'             => 'global-styles',
				'subject_name'        => 'wp_global_styles:' . self::GLOBAL_STYLES_ID,
				'subject_scope'       => 'global-styles',
				'after_digest'        => str_repeat( 'a', 64 ),
				'statement_bytes'     => '{}',
				'signature_b64'       => 'signature',
				'key_id'              => 'key',
				'related_activity_id' => (string) $row['id'],
			]
		);

		$original_database        = $GLOBALS['wpdb'];
		$original_blog            = WordPressTestState::$current_blog_id;
		$original_home            = WordPressTestState::$home_url;
		$activity_table           = Repository::table_name();
		$wrong_database           = new \wpdb();
		$wrong_database->prefix   = 'wp_2_';
		$wrong_database->options  = 'wp_2_options';
		$wrong_database->posts    = 'wp_2_posts';
		$wrong_database->postmeta = 'wp_2_postmeta';
		$wrong_activity_table     = 'wp_2_flavor_agent_activity';
		$wrong_attestation_table  = 'wp_2_flavor_agent_attestations';
		WordPressTestState::$db_tables[ $wrong_activity_table ]    = WordPressTestState::$db_tables[ $activity_table ];
		WordPressTestState::$db_tables[ $wrong_attestation_table ] = [];
		WordPressTestState::$db_tables['wp_2_options']             = [];
		$secret_key = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		$switched   = false;
		add_filter(
			'flavor_agent_attest_private_key',
			static function () use ( $secret_key, $wrong_database, &$switched ): string {
				if ( ! $switched ) {
					$switched                            = true;
					$GLOBALS['wpdb']                     = $wrong_database;
					WordPressTestState::$current_blog_id = 2;
					WordPressTestState::$home_url        = 'https://wrong-site.example';
				}

				return $secret_key;
			}
		);

		try {
			$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );
		} finally {
			$GLOBALS['wpdb']                     = $original_database;
			WordPressTestState::$current_blog_id = $original_blog;
			WordPressTestState::$home_url        = $original_home;
		}

		$this->assertTrue( $switched );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_recovery_required', $result->get_error_code() );
		$this->assertSame( 'attestation_context', $result->get_error_data()['phase'] ?? null );
		$this->assertSame( 'available', Repository::find( (string) $row['id'] )['undo']['status'] ?? null );
		$this->assertSame( 'available', json_decode( WordPressTestState::$db_tables[ $wrong_activity_table ][0]['undo_state'], true )['status'] ?? null );
		$this->assertCount( 0, WordPressTestState::$db_tables[ $wrong_attestation_table ] );

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( [], $written['styles'], 'The executor succeeded, but the drifted request must remain unterminated for recovery.' );
	}

	public function test_undo_activity_persists_failed_on_drift(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#999999' ] ],
			]
		);

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['result'] );
		$this->assertSame( 'failed', $result['entry']['undo']['status'] );
		$this->assertNotSame( '', (string) $result['entry']['undo']['error'] );
	}

	public function test_undo_activity_requires_recovery_when_failed_drift_cannot_be_persisted(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#999999' ] ],
			]
		);
		WordPressTestState::$before_activity_table_update = static fn (): bool => false;

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_recovery_required', $result->get_error_code() );
		$this->assertSame( 'activity_finalization', $result->get_error_data()['phase'] ?? null );
		$this->assertSame( 'available', Repository::find( (string) $row['id'] )['undo']['status'] ?? null );
		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( '#999999', $written['styles']['color']['text'] ?? null );
	}

	public function test_undo_activity_reports_context_drift_after_persisting_failed_drift_on_the_owner_site(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#999999' ] ],
			]
		);

		$original_database                                = $GLOBALS['wpdb'];
		$original_blog                                    = WordPressTestState::$current_blog_id;
		$activity_table                                   = Repository::table_name();
		$wrong_database                                   = new \wpdb();
		$wrong_table                                      = 'wp_2_flavor_agent_activity';
		$wrong_database->prefix                           = 'wp_2_';
		$wrong_database->options                          = 'wp_2_options';
		$wrong_database->posts                            = 'wp_2_posts';
		$wrong_database->postmeta                         = 'wp_2_postmeta';
		WordPressTestState::$db_tables[ $wrong_table ]    = WordPressTestState::$db_tables[ $activity_table ];
		WordPressTestState::$before_activity_table_update = static function () use ( $wrong_database ): void {
			$GLOBALS['wpdb']                     = $wrong_database;
			WordPressTestState::$current_blog_id = 2;
		};

		try {
			$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );
		} finally {
			$GLOBALS['wpdb']                     = $original_database;
			WordPressTestState::$current_blog_id = $original_blog;
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_recovery_required', $result->get_error_code() );
		$this->assertSame( 'finalization_context', $result->get_error_data()['phase'] ?? null );
		$this->assertSame( 'failed', Repository::find( (string) $row['id'] )['undo']['status'] ?? null );
		$this->assertSame(
			'available',
			json_decode( (string) ( WordPressTestState::$db_tables[ $wrong_table ][0]['undo_state'] ?? '' ), true )['status'] ?? null
		);
		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( '#999999', $written['styles']['color']['text'] ?? null );
	}

	public function test_undo_activity_reports_persisted_undone_rows_idempotently_without_rewrite(): void {
		$row = $this->create_executed_style_row();
		\FlavorAgent\Activity\Repository::update_undo_status( (string) $row['id'], 'undone' );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'already_undone', $result['result'] );
		$this->assertSame( 'undone', $result['entry']['undo']['status'] );
	}

	public function test_undo_activity_rejects_non_executed_rows(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $pending['activityId'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_not_undoable', $result->get_error_code() );
	}

	public function test_undo_activity_enforces_the_ordered_undo_rule(): void {
		$older = $this->create_executed_style_row();
		$newer = $this->create_executed_style_row();
		$this->assertNotSame( $older['id'], $newer['id'] );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $older['id'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_undo_blocked', $result->get_error_code() );
	}

	public function test_undo_activity_denies_a_divergent_topmost_post_target_without_content_or_row_changes(): void {
		$document_content = '<!-- wp:paragraph --><p>Document 100</p><!-- /wp:paragraph -->';
		$target_content   = '<!-- wp:heading --><h2>After</h2><!-- /wp:heading -->';
		$this->seed_post_blocks_post( 100, $document_content );
		$this->seed_post_blocks_post( 200, $target_content );
		WordPressTestState::$capabilities = [
			'edit_post:100' => true,
			'edit_post:200' => true,
		];
		$row                              = $this->create_executed_post_blocks_row( 'post-target-mismatch-undo', 100, 200 );
		$before_row                       = Repository::find( (string) $row['id'] );
		$this->assertIsArray( $before_row );

		WordPressTestState::$get_post_calls      = [];
		WordPressTestState::$get_post_type_calls = [];
		WordPressTestState::$updated_posts       = [];
		WordPressTestState::$capability_checks   = [];
		$result                                  = ApplyAbilities::undo_activity(
			[ 'activityId' => (string) $row['id'] ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_mismatch', $result->get_error_code() );
		$after_row = Repository::find( (string) $row['id'] );
		$this->assertIsArray( $after_row );
		$this->assertSame( $before_row, $after_row );
		$this->assertSame( 'available', $after_row['undo']['status'] );
		$this->assertSame( $document_content, (string) WordPressTestState::$posts[100]->post_content );
		$this->assertSame( $target_content, (string) WordPressTestState::$posts[200]->post_content );
		$this->assertSame( [], WordPressTestState::$get_post_calls );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertNull( \FlavorAgent\Attestation\Repository::find_by_related_activity( (string) $row['id'] ) );
	}

	public function test_undo_activity_denies_a_coherent_forbidden_post_target_without_content_or_row_changes(): void {
		$target_content = '<!-- wp:heading --><h2>After</h2><!-- /wp:heading -->';
		$this->seed_post_blocks_post( 200, $target_content );
		WordPressTestState::$capabilities = [ 'edit_post:200' => false ];
		$row                              = $this->create_executed_post_blocks_row( 'post-target-forbidden-undo', 200, 200 );
		$before_row                       = Repository::find( (string) $row['id'] );
		$this->assertIsArray( $before_row );

		WordPressTestState::$get_post_calls      = [];
		WordPressTestState::$get_post_type_calls = [];
		WordPressTestState::$updated_posts       = [];
		WordPressTestState::$capability_checks   = [];
		$result                                  = ApplyAbilities::undo_activity(
			[ 'activityId' => (string) $row['id'] ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_forbidden', $result->get_error_code() );
		$this->assertSame( $before_row, Repository::find( (string) $row['id'] ) );
		$this->assertSame( $target_content, (string) WordPressTestState::$posts[200]->post_content );
		$this->assertSame( [], WordPressTestState::$get_post_calls );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertNull( \FlavorAgent\Attestation\Repository::find_by_related_activity( (string) $row['id'] ) );
	}

	public function test_undo_activity_fails_before_a_hook_time_site_switch_can_undo_another_site_same_id_target(): void {
		$after = '<!-- wp:heading --><h2>After</h2><!-- /wp:heading -->';
		$this->seed_post_blocks_post( 200, $after );
		$row = $this->create_executed_post_blocks_row( 'undo-site-context-owner', 200, 200 );

		$original_database                = $GLOBALS['wpdb'];
		$original_blog                    = WordPressTestState::$current_blog_id;
		$original_post                    = WordPressTestState::$posts[200];
		$wrong_post                       = new \WP_Post(
			[
				'ID'           => 200,
				'post_type'    => 'post',
				'post_content' => $after,
			]
		);
		$wrong_database                   = new \wpdb();
		$wrong_database->prefix           = 'wp_2_';
		$wrong_database->options          = 'wp_2_options';
		$wrong_database->posts            = 'wp_2_posts';
		$wrong_database->postmeta         = 'wp_2_postmeta';
		$switched                         = false;
		$wrong_persisted_content          = null;
		WordPressTestState::$capabilities = [
			'edit_post' => static function () use ( $wrong_database, $wrong_post, &$switched ): bool {
				if ( ! $switched ) {
					$switched                            = true;
					$GLOBALS['wpdb']                     = $wrong_database;
					WordPressTestState::$current_blog_id = 2;
					WordPressTestState::$posts[200]      = $wrong_post;
				}

				return true;
			},
		];

		try {
			$result                  = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );
			$wrong_persisted_content = (string) WordPressTestState::$posts[200]->post_content;
		} finally {
			$GLOBALS['wpdb']                     = $original_database;
			WordPressTestState::$current_blog_id = $original_blog;
			WordPressTestState::$posts[200]      = $original_post;
		}

		$this->assertTrue( $switched );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_recovery_required', $result->get_error_code() );
		$this->assertSame( 'pre_execution_context', $result->get_error_data()['phase'] ?? null );
		$this->assertSame( $after, $wrong_persisted_content, 'The same-ID target from the switched site must not be undone.' );
		$this->assertSame( $after, (string) WordPressTestState::$posts[200]->post_content );
		$this->assertSame( 'available', Repository::find( (string) $row['id'] )['undo']['status'] ?? null );
	}

	public function test_undo_activity_captures_the_owner_site_before_filterable_id_sanitization(): void {
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);
		$row = $this->create_executed_style_row();

		$original_database        = $GLOBALS['wpdb'];
		$original_blog            = WordPressTestState::$current_blog_id;
		$original_home            = WordPressTestState::$home_url;
		$original_post            = WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ];
		$activity_table           = Repository::table_name();
		$wrong_database           = new \wpdb();
		$wrong_post               = new \WP_Post(
			[
				'ID'           => (int) self::GLOBAL_STYLES_ID,
				'post_type'    => 'wp_global_styles',
				'post_content' => (string) wp_json_encode(
					[
						'version'                     => 3,
						'isGlobalStylesUserThemeJSON' => true,
						'settings'                    => [],
						'styles'                      => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
					]
				),
			]
		);
		$wrong_database->prefix   = 'wp_2_';
		$wrong_database->options  = 'wp_2_options';
		$wrong_database->posts    = 'wp_2_posts';
		$wrong_database->postmeta = 'wp_2_postmeta';
		$wrong_activity_table     = 'wp_2_flavor_agent_activity';
		WordPressTestState::$db_tables[ $wrong_activity_table ] = WordPressTestState::$db_tables[ $activity_table ];
		$switched                = false;
		$wrong_persisted_content = null;
		$sanitize_filter         = static function ( string $filtered ) use ( $wrong_database, $wrong_post, &$switched ): string {
			if ( ! $switched ) {
				$switched                            = true;
				$GLOBALS['wpdb']                     = $wrong_database;
				WordPressTestState::$current_blog_id = 2;
				WordPressTestState::$home_url        = 'https://wrong-site.example';
				WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ] = $wrong_post;
			}

			return $filtered;
		};
		add_filter( 'sanitize_text_field', $sanitize_filter );

		try {
			$result                  = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );
			$wrong_persisted_content = (string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content;
		} finally {
			remove_filter( 'sanitize_text_field', $sanitize_filter );
			$GLOBALS['wpdb']                     = $original_database;
			WordPressTestState::$current_blog_id = $original_blog;
			WordPressTestState::$home_url        = $original_home;
			WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ] = $original_post;
		}

		$this->assertTrue( $switched );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_recovery_required', $result->get_error_code() );
		$this->assertSame( 'pre_execution_context', $result->get_error_data()['phase'] ?? null );
		$this->assertSame( (string) $wrong_post->post_content, $wrong_persisted_content );
		$this->assertSame( (string) $original_post->post_content, (string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content );
		$this->assertSame( 'available', Repository::find( (string) $row['id'] )['undo']['status'] ?? null );
	}

	public function test_undo_activity_fails_before_a_posts_table_only_redirect_can_undo_another_target(): void {
		$after = '<!-- wp:heading --><h2>After</h2><!-- /wp:heading -->';
		$this->seed_post_blocks_post( 200, $after );
		$row = $this->create_executed_post_blocks_row( 'undo-posts-table-owner', 200, 200 );

		$database                         = $GLOBALS['wpdb'];
		$original_posts_table             = $database->posts;
		$original_post                    = WordPressTestState::$posts[200];
		$redirected_post                  = new \WP_Post(
			[
				'ID'           => 200,
				'post_type'    => 'post',
				'post_content' => $after,
			]
		);
		$redirected_content               = null;
		$redirected                       = false;
		WordPressTestState::$capabilities = [
			'edit_post' => static function () use ( $database, $redirected_post, &$redirected ): bool {
				if ( ! $redirected ) {
					$redirected                     = true;
					$database->posts                = 'wp_2_posts';
					WordPressTestState::$posts[200] = $redirected_post;
				}

				return true;
			},
		];

		try {
			$result             = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );
			$redirected_content = (string) WordPressTestState::$posts[200]->post_content;
		} finally {
			$database->posts                = $original_posts_table;
			WordPressTestState::$posts[200] = $original_post;
		}

		$this->assertTrue( $redirected );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_recovery_required', $result->get_error_code() );
		$this->assertSame( 'pre_execution_context', $result->get_error_data()['phase'] ?? null );
		$this->assertSame( $after, $redirected_content, 'Changing only wpdb->posts must not redirect an undo write.' );
		$this->assertSame( $after, (string) WordPressTestState::$posts[200]->post_content );
		$this->assertSame( 'available', Repository::find( (string) $row['id'] )['undo']['status'] ?? null );
	}

	public function test_ordered_undo_blocking_precedes_authorization_of_an_older_malformed_row(): void {
		$after = '<!-- wp:heading --><h2>After</h2><!-- /wp:heading -->';
		$this->seed_post_blocks_post( 100, $after );
		$this->seed_post_blocks_post( 200, $after );
		$older = $this->create_executed_post_blocks_row(
			'post-target-malformed-older',
			100,
			100,
			[ 'postType' => 'page' ]
		);
		$newer = $this->create_executed_post_blocks_row( 'post-target-valid-newer', 100, 100 );
		$this->assertNotSame( $older['id'], $newer['id'] );

		WordPressTestState::$get_post_calls      = [];
		WordPressTestState::$get_post_type_calls = [];
		WordPressTestState::$updated_posts       = [];
		WordPressTestState::$capability_checks   = [];
		$result                                  = ApplyAbilities::undo_activity(
			[ 'activityId' => (string) $older['id'] ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_undo_blocked', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$get_post_type_calls );
		$this->assertSame( [], WordPressTestState::$capability_checks );
		$this->assertSame( [], WordPressTestState::$get_post_calls );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$after_row = Repository::find( (string) $older['id'] );
		$this->assertIsArray( $after_row );
		$this->assertSame( 'available', $after_row['undo']['status'] );
	}

	public function test_undo_activity_rejects_unsupported_surfaces(): void {
		$created = \FlavorAgent\Activity\Repository::create(
			[
				'type'       => 'apply_suggestion',
				'surface'    => 'block',
				'target'     => [ 'blockName' => 'core/paragraph' ],
				'suggestion' => 'Block apply',
				'before'     => [ 'attributes' => [] ],
				'after'      => [ 'attributes' => [] ],
				'undo'       => [ 'status' => 'available' ],
				'document'   => [ 'scopeKey' => 'post:5' ],
			]
		);
		$this->assertIsArray( $created );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $created['id'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_surface_unsupported', $result->get_error_code() );
	}

	public function test_undo_activity_works_for_approved_external_rows(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );
		\FlavorAgent\Activity\Repository::transition_external_apply(
			(string) $pending['activityId'],
			[
				'applyStatus' => 'available',
				'executedAt'  => gmdate( 'c' ),
				'before'      => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'       => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
					],
					'operations' => [],
				],
				'target'      => [ 'globalStylesId' => self::GLOBAL_STYLES_ID ],
			]
		);
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);
		$this->configure_attestation_key();
		\FlavorAgent\Attestation\Repository::install();
		\FlavorAgent\Attestation\Repository::insert(
			[
				'attestation_id'      => 'att_prior_apply',
				'surface'             => 'global-styles',
				'subject_name'        => 'wp_global_styles:' . self::GLOBAL_STYLES_ID,
				'subject_scope'       => 'global-styles',
				'after_digest'        => str_repeat( 'a', 64 ),
				'statement_bytes'     => '{}',
				'signature_b64'       => 'sig',
				'key_id'              => 'kid',
				'related_activity_id' => (string) $pending['activityId'],
			]
		);

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $pending['activityId'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame( 'recorded', $result['entry']['undo']['attestationStatus'] );
		$this->assertNull( $result['entry']['undo']['attestationErrorCode'] );
		$this->assertIsArray( \FlavorAgent\Attestation\Repository::find_by_reverts( 'att_prior_apply' ) );
	}

	public function test_undo_activity_records_a_template_revert_attestation_chain(): void {
		$before = '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->';
		$after  = '<!-- wp:heading --><h1>After</h1><!-- /wp:heading -->';
		$this->seed_template_post( $after, 7100 );

		$activity = Repository::create(
			[
				'id'              => 'template-undo-row',
				'type'            => 'apply_template_suggestion',
				'surface'         => 'template',
				'target'          => [
					'templateRef'  => self::TEMPLATE_REF,
					'templateType' => 'home',
					'slug'         => 'home',
					'title'        => 'Home',
				],
				'suggestion'      => 'Add a hero',
				'before'          => [ 'content' => $before ],
				'after'           => [
					'content'    => $after,
					'operations' => [],
				],
				'executionResult' => 'applied',
				'undo'            => [ 'status' => 'available' ],
				'document'        => [
					'scopeKey'   => 'wp_template:' . self::TEMPLATE_REF,
					'postType'   => 'wp_template',
					'entityId'   => self::TEMPLATE_REF,
					'entityKind' => 'postType',
					'entityName' => 'wp_template',
				],
			]
		);
		$this->assertIsArray( $activity );

		$this->configure_attestation_key();
		\FlavorAgent\Attestation\Repository::install();

		$apply_attestation_id = \FlavorAgent\Attestation\AttestationService::record_apply(
			[
				'surface'            => 'template',
				'templateRef'        => self::TEMPLATE_REF,
				'operations'         => [],
				'before'             => [ 'content' => $before ],
				'after'              => [ 'content' => $after ],
				'freshnessSignature' => 'template-f',
				'actorRole'          => 'administrator',
				'requestedAt'        => '2026-06-22T00:00:00+00:00',
				'decidedAt'          => '2026-06-22T00:01:00+00:00',
				'relatedActivityId'  => (string) $activity['id'],
			]
		)->attestation_id();
		$this->assertIsString( $apply_attestation_id );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( isset( $data['post_content'] ) ) {
					$data['post_content'] = str_replace( '>Before<', '>Before saved<', (string) $data['post_content'] );
				}

				return $data;
			},
			10,
			4
		);

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $activity['id'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame( 'recorded', $result['entry']['undo']['attestationStatus'] );
		$persisted_before = (string) WordPressTestState::$posts[7100]->post_content;
		$this->assertStringContainsString( '>Before saved<', $persisted_before );

		$revert = \FlavorAgent\Attestation\Repository::find_by_reverts( $apply_attestation_id );
		$this->assertIsArray( $revert );

		$statement = json_decode( (string) $revert['statement_bytes'], true );
		$this->assertIsArray( $statement );
		$this->assertSame( 'external-template-apply-v1', $statement['predicate']['governance']['lane'] );
		$this->assertSame( $apply_attestation_id, $statement['predicate']['revertsAttestationId'] );
		$this->assertSame(
			\FlavorAgent\Attestation\BlockContentCanonicalizer::digest( $after ),
			$statement['predicate']['before']['sha256']
		);
		$this->assertSame(
			\FlavorAgent\Attestation\BlockContentCanonicalizer::digest( $persisted_before ),
			$statement['predicate']['after']['sha256']
		);
	}

	public function test_undo_activity_records_a_template_part_revert_attestation_chain(): void {
		$before = '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->';
		$after  = '<!-- wp:heading --><h2>After</h2><!-- /wp:heading -->';
		$this->seed_template_part_post( $after, 7101 );

		$activity = Repository::create(
			[
				'id'              => 'template-part-undo-row',
				'type'            => 'apply_template_part_suggestion',
				'surface'         => 'template-part',
				'target'          => [
					'templatePartId' => '  ' . self::TEMPLATE_PART_REF . '  ',
					'slug'           => 'header',
					'area'           => 'header',
				],
				'suggestion'      => 'Simplify the header',
				'before'          => [ 'content' => $before ],
				'after'           => [
					'content'    => $after,
					'operations' => [],
				],
				'executionResult' => 'applied',
				'undo'            => [ 'status' => 'available' ],
				'document'        => [
					'scopeKey'   => 'wp_template_part:' . self::TEMPLATE_PART_REF,
					'postType'   => 'wp_template_part',
					'entityId'   => self::TEMPLATE_PART_REF,
					'entityKind' => 'postType',
					'entityName' => 'wp_template_part',
				],
			]
		);
		$this->assertIsArray( $activity );
		$identity = \FlavorAgent\Apply\TemplatePartApplyExecutor::resolve_target_identity( $activity );
		$this->assertIsArray( $identity );
		$expected_subject   = 'wp_template_part:' . $identity['target']['templatePartRef'];
		$raw_target         = is_array( $activity['target'] ?? null ) ? $activity['target'] : [];
		$old_fallback_value = (string) ( $raw_target['templatePartRef'] ?? $raw_target['templatePartId'] ?? '' );
		$old_raw_subject    = 'wp_template_part:' . $old_fallback_value;
		$this->assertSame( self::TEMPLATE_PART_REF, $identity['target']['templatePartRef'] );
		$this->assertSame( 'wp_template_part:  ' . self::TEMPLATE_PART_REF . '  ', $old_raw_subject );
		$this->assertNotSame( $expected_subject, $old_raw_subject );

		$this->configure_attestation_key();
		\FlavorAgent\Attestation\Repository::install();

		$apply_attestation_id = \FlavorAgent\Attestation\AttestationService::record_apply(
			[
				'surface'            => 'template-part',
				'templateRef'        => self::TEMPLATE_PART_REF,
				'operations'         => [],
				'before'             => [ 'content' => $before ],
				'after'              => [ 'content' => $after ],
				'freshnessSignature' => 'template-part-f',
				'actorRole'          => 'administrator',
				'requestedAt'        => '2026-06-22T00:00:00+00:00',
				'decidedAt'          => '2026-06-22T00:01:00+00:00',
				'relatedActivityId'  => (string) $activity['id'],
			]
		)->attestation_id();
		$this->assertIsString( $apply_attestation_id );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $activity['id'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame( 'recorded', $result['entry']['undo']['attestationStatus'] );
		$this->assertSame( $before, (string) WordPressTestState::$posts[7101]->post_content );

		$revert = \FlavorAgent\Attestation\Repository::find_by_reverts( $apply_attestation_id );
		$this->assertIsArray( $revert );

		$statement = json_decode( (string) $revert['statement_bytes'], true );
		$this->assertIsArray( $statement );
		$this->assertSame( 'external-template-part-apply-v1', $statement['predicate']['governance']['lane'] );
		$this->assertSame( $expected_subject, $statement['subject'][0]['name'] );
		$this->assertSame( $apply_attestation_id, $statement['predicate']['revertsAttestationId'] );
	}

	public function test_decision_approve_executes_and_transitions_to_available(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$entry = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'approve',
			'Reviewed and safe'
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'available', $entry['apply']['status'] );
		$this->assertSame( 'applied', $entry['executionResult'] );
		$this->assertSame( 'available', $entry['undo']['status'] );
		$this->assertSame( 'not_configured', $entry['apply']['attestationStatus'] );
		$this->assertNull( $entry['apply']['attestationErrorCode'] );
		$this->assertSame( 'Reviewed and safe', $entry['apply']['decisionNote'] );
		$this->assertSame( 7, $entry['apply']['decidedBy'] );
		$this->assertNotSame( '', (string) $entry['apply']['executedAt'] );
		$this->assertSame(
			'var:preset|color|accent',
			$entry['after']['userConfig']['styles']['color']['text']
		);

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( 'var:preset|color|accent', $written['styles']['color']['text'] );
	}

	public function test_decision_approve_surfaces_attestation_recording_failures(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$failure_filter  = static function (): string {
			throw new \RuntimeException( 'attestation signing unavailable' );
		};
		$captured_event  = null;
		$captured_error  = null;
		$log_file        = \tempnam( \sys_get_temp_dir(), 'flavor-agent-attestation-log-' );
		$previous_log    = \ini_get( 'error_log' );
		$previous_errors = \ini_get( 'log_errors' );

		\add_filter( 'flavor_agent_attest_private_key', $failure_filter );
		\add_action(
			'flavor_agent_attestation_record_failed',
			static function ( array $event, \Throwable $error ) use ( &$captured_event, &$captured_error ): void {
				$captured_event = $event;
				$captured_error = $error;
			},
			10,
			2
		);
		\ini_set( 'log_errors', '1' );
		\ini_set( 'error_log', $log_file );

		try {
			$entry = \FlavorAgent\Apply\PendingApplyDecision::decide(
				(string) $pending['activityId'],
				'approve'
			);
		} finally {
			\remove_filter( 'flavor_agent_attest_private_key', $failure_filter );
			\ini_set( 'error_log', false === $previous_log ? '' : (string) $previous_log );
			\ini_set( 'log_errors', false === $previous_errors ? '' : (string) $previous_errors );
		}

		$contents = \is_string( $log_file ) && \file_exists( $log_file )
			? (string) \file_get_contents( $log_file )
			: '';

		if ( \is_string( $log_file ) && \file_exists( $log_file ) ) {
			\unlink( $log_file );
		}

		$this->assertIsArray( $entry );
		$this->assertSame( 'available', $entry['apply']['status'] );
		$this->assertSame( 'failed', $entry['apply']['attestationStatus'] );
		$this->assertSame( 'unexpected_failure', $entry['apply']['attestationErrorCode'] );
		$this->assertIsArray( $captured_event );
		$this->assertSame( 'apply', $captured_event['operation'] );
		$this->assertSame( (string) $pending['activityId'], $captured_event['activityId'] );
		$this->assertSame( 'unexpected_failure', $captured_event['errorCode'] );
		$this->assertSame( \RuntimeException::class, $captured_event['exceptionClass'] );
		$this->assertSame( 'attestation signing unavailable', $captured_event['message'] );
		$this->assertInstanceOf( \RuntimeException::class, $captured_error );
		$this->assertStringContainsString( '[flavor-agent] Attestation recording failed during apply', $contents );
		$this->assertStringContainsString( 'attestation signing unavailable', $contents );
	}

	public function test_decision_approve_fails_closed_when_the_entity_drifted_after_the_request(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		// Simulate a Site Editor session changing Global Styles before approval.
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#abcdef' ] ],
			]
		);

		$entry = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'approve'
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'failed', $entry['apply']['status'] );
		$this->assertSame( 'failed', $entry['executionResult'] );
		$this->assertSame( 'flavor_agent_apply_stale', $entry['apply']['failureCode'] );
		$this->assertSame( 'not_applicable', $entry['undo']['status'] );
	}

	public function test_decision_approve_fails_closed_when_the_baseline_hash_is_missing(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$table = Repository::table_name();

		foreach ( WordPressTestState::$db_tables[ $table ] as $index => $row ) {
			if ( (string) ( $row['activity_id'] ?? '' ) !== (string) $pending['activityId'] ) {
				continue;
			}

			$request = json_decode( (string) ( $row['request_json'] ?? '{}' ), true );
			unset( $request['apply']['signatures']['baselineConfigHash'] );
			WordPressTestState::$db_tables[ $table ][ $index ]['request_json'] = (string) wp_json_encode( $request );
			break;
		}

		$entry = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'approve'
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'failed', $entry['apply']['status'] );
		$this->assertSame( 'flavor_agent_apply_stale', $entry['apply']['failureCode'] );
		$this->assertSame(
			'The baseline configuration hash is missing from this external apply request.',
			$entry['apply']['failureMessage']
		);
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_decision_approve_records_resolve_failures_separately_from_stale_hashes(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );
		unset( WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ] );

		$entry = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'approve'
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'failed', $entry['apply']['status'] );
		$this->assertSame( 'flavor_agent_apply_resolve_failed', $entry['apply']['failureCode'] );
	}

	public function test_decision_reject_records_provenance_without_executing(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$entry = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'reject',
			'Not aligned with brand'
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'rejected', $entry['apply']['status'] );
		$this->assertSame( 'Not aligned with brand', $entry['apply']['decisionNote'] );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'Reject must not write the entity.' );
	}

	public function test_decision_rejects_non_pending_rows_and_expired_rows(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );
		\FlavorAgent\Apply\PendingApplyDecision::decide( (string) $pending['activityId'], 'reject' );

		$again = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $pending['activityId'], 'approve' );
		$this->assertInstanceOf( \WP_Error::class, $again );
		$this->assertSame( 'flavor_agent_apply_not_pending', $again->get_error_code() );

		$expired = ApplyAbilities::request_style_apply(
			$this->agent_request_input( [ 'requestReference' => 'agent-req-3' ] )
		);
		$this->assertIsArray( $expired );
		\FlavorAgent\Activity\Repository::transition_external_apply(
			(string) $expired['activityId'],
			[ 'applyStatus' => 'expired' ]
		);
		$late = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $expired['activityId'], 'approve' );
		$this->assertInstanceOf( \WP_Error::class, $late );
		$this->assertSame( 'flavor_agent_apply_expired', $late->get_error_code() );
	}

	public function test_decision_validates_the_decision_value(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$result = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $pending['activityId'], 'maybe' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_invalid_decision', $result->get_error_code() );
	}

	public function test_request_style_apply_ability_requires_edit_theme_options(): void {
		$ability = new \FlavorAgent\AI\Abilities\RequestStyleApplyAbility(
			\FlavorAgent\AI\Abilities\RequestStyleApplyAbility::ABILITY_NAME,
			[]
		);

		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$this->assertFalse( $ability->permission_callback( [] ) );

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertTrue( $ability->permission_callback( [] ) );
	}

	public function test_request_template_apply_ability_requires_edit_theme_options(): void {
		$ability = new \FlavorAgent\AI\Abilities\RequestTemplateApplyAbility(
			\FlavorAgent\AI\Abilities\RequestTemplateApplyAbility::ABILITY_NAME,
			[]
		);

		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$this->assertFalse( $ability->permission_callback( [] ) );

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertTrue( $ability->permission_callback( [] ) );
	}

	public function test_undo_activity_ability_enforces_the_row_capability_contextually(): void {
		$row     = $this->create_executed_style_row();
		$ability = new \FlavorAgent\AI\Abilities\UndoActivityAbility(
			\FlavorAgent\AI\Abilities\UndoActivityAbility::ABILITY_NAME,
			[]
		);
		$input   = [ 'activityId' => (string) $row['id'] ];

		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$this->assertFalse(
			$ability->permission_callback( $input ),
			'Style rows resolve to edit_theme_options through the contextual check.'
		);

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertTrue( $ability->permission_callback( $input ) );
	}

	private function configure_attestation_key(): void {
		$sk = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $sk );
	}

	public function test_undo_activity_ability_fails_closed_for_missing_rows(): void {
		$ability = new \FlavorAgent\AI\Abilities\UndoActivityAbility(
			\FlavorAgent\AI\Abilities\UndoActivityAbility::ABILITY_NAME,
			[]
		);

		WordPressTestState::$capabilities = [ 'edit_posts' => true ];

		$this->assertFalse(
			$ability->permission_callback( [ 'activityId' => 'missing-row' ] ),
			'Missing rows should not fall back to broad editor capabilities.'
		);
	}

	public function test_list_activity_ability_gates_on_the_scope_context(): void {
		$ability = new \FlavorAgent\AI\Abilities\ListActivityAbility(
			\FlavorAgent\AI\Abilities\ListActivityAbility::ABILITY_NAME,
			[]
		);

		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$this->assertFalse(
			$ability->permission_callback( [ 'scopeKey' => 'global_styles:17' ] )
		);
		$this->assertFalse( $ability->permission_callback( [] ), 'A scopeKey is required.' );

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertTrue(
			$ability->permission_callback( [ 'scopeKey' => 'global_styles:17' ] )
		);
	}
}
