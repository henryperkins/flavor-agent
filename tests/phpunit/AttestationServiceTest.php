<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Attestation\AttestationService;
use FlavorAgent\Attestation\BlockContentCanonicalizer;
use FlavorAgent\Attestation\Canonicalizer;
use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Attestation\RecordResult;
use FlavorAgent\Attestation\Repository;
use FlavorAgent\Attestation\Signer;
use FlavorAgent\Attestation\Verifier;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AttestationServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_record_apply_persists_a_verifiable_attestation(): void {
		$this->configure_key();
		Repository::install();

		$id = AttestationService::record_apply( $this->apply_context() )->attestation_id();

		$this->assertNotNull( $id );
		$this->assertMatchesRegularExpression( '/^att_[a-f0-9]{32}$/', $id );

		$row = Repository::find( $id );

		$this->assertIsArray( $row );
		$this->assertTrue(
			Signer::verify(
				(string) $row['statement_bytes'],
				self::b64url_decode( (string) $row['signature_b64'] ),
				(string) KeyManager::public_key()
			)
		);
	}

	public function test_record_apply_returns_a_typed_recorded_result(): void {
		$this->configure_key();
		Repository::install();

		$result = AttestationService::record_apply( $this->apply_context() );

		$this->assertInstanceOf( RecordResult::class, $result );
		$this->assertSame( RecordResult::STATUS_RECORDED, $result->status() );
		$this->assertMatchesRegularExpression( '/^att_[a-f0-9]{32}$/', (string) $result->attestation_id() );
		$this->assertNull( $result->error_code() );
	}

	public function test_record_apply_invalidates_a_cached_missing_key_registry(): void {
		$this->configure_key();
		Repository::install();
		$storage_context = ActivityRepository::capture_storage_context();
		$this->assertNotInstanceOf( \WP_Error::class, $storage_context );
		$this->assertSame( [], KeyManager::jwks()['keys'] );

		$result = AttestationService::record_apply( $this->apply_context(), $storage_context );

		$this->assertSame( RecordResult::STATUS_RECORDED, $result->status() );
		$this->assertCount( 1, KeyManager::jwks()['keys'] );
	}

	public function test_public_jwks_reads_a_bound_registration_after_context_drift_despite_a_cached_miss(): void {
		$this->configure_key();
		Repository::install();
		$storage_context = ActivityRepository::capture_storage_context();
		$this->assertNotInstanceOf( \WP_Error::class, $storage_context );

		$this->assertSame( [], get_option( 'flavor_agent_attestation_public_keys', [] ) );
		$this->assertSame( [], KeyManager::jwks()['keys'] );

		$original_database        = $GLOBALS['wpdb'];
		$original_blog            = WordPressTestState::$current_blog_id;
		$original_home            = WordPressTestState::$home_url;
		$wrong_database           = new \wpdb();
		$wrong_database->prefix   = 'wp_2_';
		$wrong_database->options  = 'wp_2_options';
		$wrong_database->posts    = 'wp_2_posts';
		$wrong_database->postmeta = 'wp_2_postmeta';
		$context                  = $this->apply_context();

		try {
			$GLOBALS['wpdb']                     = $wrong_database;
			WordPressTestState::$current_blog_id = 2;
			WordPressTestState::$home_url        = 'https://wrong-site.example';
			$result                              = AttestationService::record_apply( $context, $storage_context );
		} finally {
			$GLOBALS['wpdb']                     = $original_database;
			WordPressTestState::$current_blog_id = $original_blog;
			WordPressTestState::$home_url        = $original_home;
		}

		$this->assertSame( RecordResult::STATUS_RECORDED, $result->status() );
		$this->assertCount( 1, KeyManager::jwks( $storage_context )['keys'] );

		$attestation_id = $result->attestation_id();
		$this->assertNotNull( $attestation_id );
		$row = Repository::find( $attestation_id, $storage_context );
		$this->assertIsArray( $row );

		$public_jwks = KeyManager::jwks();
		$this->assertCount( 1, $public_jwks['keys'] );
		$this->assertSame( (string) $row['key_id'], $public_jwks['keys'][0]['kid'] );

		$verification = Verifier::verify(
			[
				'statement_b64' => KeyManager::b64url( (string) $row['statement_bytes'] ),
				'signature_b64' => (string) $row['signature_b64'],
				'key_id'        => (string) $row['key_id'],
			],
			$public_jwks,
			Canonicalizer::canonical_bytes( $context['after']['userConfig'] ),
			$attestation_id,
			'https://example.test'
		);

		$this->assertSame( 'verified', $verification['verificationStatus'] );
		$this->assertContains( 'signature_valid', $verification['outcomes'] );
		$this->assertContains( 'live_matches_subject', $verification['outcomes'] );
	}

	public function test_record_apply_fails_when_the_public_key_cannot_be_registered(): void {
		$this->configure_key();
		Repository::install();
		$storage_context = ActivityRepository::capture_storage_context();
		$this->assertNotInstanceOf( \WP_Error::class, $storage_context );

		$failure_event   = null;
		$disable_logging = static fn (): bool => false;
		add_action(
			'flavor_agent_attestation_record_failed',
			static function ( array $event ) use ( &$failure_event ): void {
				$failure_event = $event;
			}
		);
		add_filter( 'flavor_agent_attestation_failure_logging_enabled', $disable_logging );
		WordPressTestState::$option_insert_fails = true;

		try {
			$result = AttestationService::record_apply( $this->apply_context(), $storage_context );
		} finally {
			WordPressTestState::$option_insert_fails = false;
			remove_filter( 'flavor_agent_attestation_failure_logging_enabled', $disable_logging );
		}

		$this->assertSame( RecordResult::STATUS_FAILED, $result->status() );
		$this->assertSame( 'signing_failed', $result->error_code() );
		$this->assertNull( $result->attestation_id() );
		$this->assertNull( Repository::find_by_related_activity( 'act_9', $storage_context ) );
		$this->assertSame( [], KeyManager::jwks( $storage_context )['keys'] );
		$this->assertIsArray( $failure_event );
		$this->assertSame( 'apply', $failure_event['operation'] ?? null );
		$this->assertSame( 'act_9', $failure_event['activityId'] ?? null );
		$this->assertSame( 'signing_failed', $failure_event['errorCode'] ?? null );
		$this->assertSame( 'Flavor Agent could not sign the attestation statement.', $failure_event['message'] ?? null );
	}

	public function test_record_apply_does_not_notify_ambient_site_observers_after_bound_context_drift(): void {
		$this->configure_key();

		$original_database                   = $GLOBALS['wpdb'];
		$original_blog                       = WordPressTestState::$current_blog_id;
		$original_home                       = WordPressTestState::$home_url;
		$origin_database                     = new class() extends \wpdb {
			/** @var callable|null */
			public $before_option_insert = null;

			public function insert( string $table, array $data, array $format = [] ) {
				if ( $this->options === $table && is_callable( $this->before_option_insert ) ) {
					$callback                   = $this->before_option_insert;
					$this->before_option_insert = null;
					$callback();
				}

				return parent::insert( $table, $data, $format );
			}
		};
		$wrong_database                      = new \wpdb();
		$wrong_database->prefix              = 'wp_2_';
		$wrong_database->options             = 'wp_2_options';
		$wrong_database->posts               = 'wp_2_posts';
		$wrong_database->postmeta            = 'wp_2_postmeta';
		$GLOBALS['wpdb']                     = $origin_database;
		WordPressTestState::$current_blog_id = 1;

		Repository::install();
		$storage_context = ActivityRepository::capture_storage_context();
		$this->assertNotInstanceOf( \WP_Error::class, $storage_context );

		$switched                              = false;
		$failure_event                         = null;
		$logging_filter_calls                  = 0;
		$failure_observer                      = static function ( array $event ) use ( &$failure_event ): void {
			$failure_event = $event;
		};
		$logging_observer                      = static function ( bool $enabled ) use ( &$logging_filter_calls ): bool {
			++$logging_filter_calls;

			return false;
		};
		$origin_database->before_option_insert = static function () use ( $wrong_database, &$switched ): void {
			$switched                            = true;
			$GLOBALS['wpdb']                     = $wrong_database;
			WordPressTestState::$current_blog_id = 2;
			WordPressTestState::$home_url        = 'https://wrong-site.example';
		};
		add_action( 'flavor_agent_attestation_record_failed', $failure_observer );
		add_filter( 'flavor_agent_attestation_failure_logging_enabled', $logging_observer );
		WordPressTestState::$option_insert_fails = true;

		try {
			$result = AttestationService::record_apply( $this->apply_context(), $storage_context );
		} finally {
			WordPressTestState::$option_insert_fails = false;
			remove_action( 'flavor_agent_attestation_record_failed', $failure_observer );
			remove_filter( 'flavor_agent_attestation_failure_logging_enabled', $logging_observer );
			$GLOBALS['wpdb']                     = $original_database;
			WordPressTestState::$current_blog_id = $original_blog;
			WordPressTestState::$home_url        = $original_home;
		}

		$this->assertTrue( $switched );
		$this->assertSame( RecordResult::STATUS_FAILED, $result->status() );
		$this->assertSame( 'signing_failed', $result->error_code() );
		$this->assertNull( $failure_event );
		$this->assertSame( 0, $logging_filter_calls );
		$this->assertNull( Repository::find_by_related_activity( 'act_9', $storage_context ) );
		$this->assertSame( [], KeyManager::jwks( $storage_context )['keys'] );
	}

	public function test_record_apply_returns_not_configured_without_key(): void {
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => '' );

		$result = AttestationService::record_apply(
			[
				'surface'        => 'global-styles',
				'globalStylesId' => '81',
				'operations'     => [],
				'before'         => [ 'userConfig' => [] ],
				'after'          => [ 'userConfig' => [] ],
			]
		);

		$this->assertInstanceOf( RecordResult::class, $result );
		$this->assertSame( RecordResult::STATUS_NOT_CONFIGURED, $result->status() );
		$this->assertNull( $result->attestation_id() );
		$this->assertNull( $result->error_code() );
	}

	public function test_record_apply_rejects_a_surface_outside_the_owned_lanes(): void {
		$this->configure_key();

		$context            = $this->apply_context();
		$context['surface'] = 'post-blocks';

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Flavor Agent only attests governed external style, template, and template-part apply lanes.' );

		AttestationService::record_apply( $context );
	}

	public function test_record_apply_requires_a_related_activity_for_the_owned_lane(): void {
		$this->configure_key();

		$context = $this->apply_context();
		unset( $context['relatedActivityId'] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Flavor Agent attestations require the related activity id from the governed external apply lane.' );

		AttestationService::record_apply( $context );
	}

	public function test_revert_is_chained_to_prior_and_findable(): void {
		$this->configure_key();
		Repository::install();

		$apply_id = AttestationService::record_apply( $this->apply_context() )->attestation_id();
		$this->assertNotNull( $apply_id );

		$revert_context               = $this->apply_context();
		$revert_context['before']     = $revert_context['after'];
		$revert_context['after']      = [
			'userConfig' => [
				'settings' => [],
				'styles'   => [],
			],
		];
		$revert_context['decidedAt']  = '2026-06-22T00:02:00+00:00';
		$revert_context['operations'] = [];

		$revert_id = AttestationService::record_revert( $apply_id, $revert_context )->attestation_id();

		$this->assertNotNull( $revert_id );
		$this->assertSame( $revert_id, Repository::find_by_reverts( $apply_id )['attestation_id'] );
	}

	public function test_later_apply_supersedes_the_latest_prior_attestation_for_the_subject(): void {
		$this->configure_key();
		Repository::install();

		$first_id = AttestationService::record_apply( $this->apply_context() )->attestation_id();
		$this->assertNotNull( $first_id );

		$second_context              = $this->apply_context();
		$second_context['after']     = [
			'userConfig' => [
				'settings' => [],
				'styles'   => [
					'color' => [
						'background' => '#111111',
					],
				],
			],
		];
		$second_context['decidedAt'] = '2026-06-22T00:02:00+00:00';

		$second_id = AttestationService::record_apply( $second_context )->attestation_id();
		$this->assertNotNull( $second_id );

		$this->assertSame( $second_id, Repository::find_by_supersedes( $first_id )['attestation_id'] );
	}

	public function test_template_apply_uses_the_template_lane_subject_scope_and_digests(): void {
		$this->configure_key();
		Repository::install();

		$context = $this->template_context();
		$id      = AttestationService::record_apply( $context )->attestation_id();

		$this->assertNotNull( $id );

		$row       = Repository::find( $id );
		$statement = $this->statement_from_row( $row );

		$this->assertSame( 'template', $row['surface'] );
		$this->assertSame( 'wp_template:twentytwentyfive//home', $row['subject_name'] );
		$this->assertSame( 'template', $row['subject_scope'] );
		$this->assertSame( BlockContentCanonicalizer::digest( $context['after']['content'] ), $row['after_digest'] );
		$this->assertSame( 'external-template-apply-v1', $statement['predicate']['governance']['lane'] );
		$this->assertSame( 'bounded-server-template-apply', $statement['predicate']['governance']['executor'] );
		$this->assertSame( 'wp_template:twentytwentyfive//home', $statement['subject'][0]['name'] );
		$this->assertSame( 'template', $statement['subject'][0]['scope'] );
		$this->assertSame(
			BlockContentCanonicalizer::digest( $context['before']['content'] ),
			$statement['predicate']['before']['sha256']
		);
		$this->assertSame(
			BlockContentCanonicalizer::digest( $context['after']['content'] ),
			$statement['predicate']['after']['sha256']
		);
	}

	public function test_template_part_apply_uses_the_template_part_lane_mapping(): void {
		$this->configure_key();
		Repository::install();

		$context                      = $this->template_context();
		$context['surface']           = 'template-part';
		$context['templateRef']       = 'twentytwentyfive//header';
		$context['operations']        = [
			[
				'type'              => 'remove_block',
				'expectedBlockName' => 'core/paragraph',
				'expectedTarget'    => [
					'name'       => 'core/paragraph',
					'label'      => 'Paragraph',
					'attributes' => [],
					'childCount' => 0,
				],
				'targetPath'        => [ 0 ],
			],
		];
		$context['after']             = [ 'content' => '' ];
		$context['decidedAt']         = '2026-06-22T00:02:00+00:00';
		$context['relatedActivityId'] = 'act_template_part';

		$id = AttestationService::record_apply( $context )->attestation_id();

		$this->assertNotNull( $id );

		$row       = Repository::find( $id );
		$statement = $this->statement_from_row( $row );

		$this->assertSame( 'wp_template_part:twentytwentyfive//header', $row['subject_name'] );
		$this->assertSame( 'template-part', $row['subject_scope'] );
		$this->assertSame( 'external-template-part-apply-v1', $statement['predicate']['governance']['lane'] );
		$this->assertSame( 'bounded-server-template-part-apply', $statement['predicate']['governance']['executor'] );
	}

	public function test_later_template_apply_supersedes_the_same_template_subject(): void {
		$this->configure_key();
		Repository::install();

		$first_id = AttestationService::record_apply( $this->template_context() )->attestation_id();
		$this->assertNotNull( $first_id );

		$second_context                      = $this->template_context();
		$second_context['before']            = $second_context['after'];
		$second_context['after']             = [ 'content' => '<!-- wp:heading --><h2>Second</h2><!-- /wp:heading -->' ];
		$second_context['decidedAt']         = '2026-06-22T00:02:00+00:00';
		$second_context['relatedActivityId'] = 'act_template_2';

		$second_id = AttestationService::record_apply( $second_context )->attestation_id();
		$this->assertNotNull( $second_id );
		$this->assertSame( $second_id, Repository::find_by_supersedes( $first_id )['attestation_id'] );
	}

	public function test_template_revert_swaps_content_digests_and_links_the_prior_attestation(): void {
		$this->configure_key();
		Repository::install();

		$apply_context = $this->template_context();
		$apply_id      = AttestationService::record_apply( $apply_context )->attestation_id();
		$this->assertNotNull( $apply_id );

		$revert_context                      = $apply_context;
		$revert_context['before']            = $apply_context['after'];
		$revert_context['after']             = $apply_context['before'];
		$revert_context['operations']        = [];
		$revert_context['decidedAt']         = '2026-06-22T00:03:00+00:00';
		$revert_context['relatedActivityId'] = 'act_template_revert';

		$revert_id = AttestationService::record_revert( $apply_id, $revert_context )->attestation_id();

		$this->assertNotNull( $revert_id );

		$statement = $this->statement_from_row( Repository::find( $revert_id ) );

		$this->assertSame( 'revert', $statement['predicate']['decision'] );
		$this->assertSame( $apply_id, $statement['predicate']['revertsAttestationId'] );
		$this->assertSame(
			BlockContentCanonicalizer::digest( $apply_context['after']['content'] ),
			$statement['predicate']['before']['sha256']
		);
		$this->assertSame(
			BlockContentCanonicalizer::digest( $apply_context['before']['content'] ),
			$statement['predicate']['after']['sha256']
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function apply_context(): array {
		return [
			'surface'            => 'global-styles',
			'globalStylesId'     => '81',
			'blockName'          => '',
			'operations'         => [
				[
					'type'       => 'set_styles',
					'blockName'  => '',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|color|parchment-100',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'parchment-100',
					'cssVar'     => 'var(--wp--preset--color--parchment-100)',
				],
			],
			'before'             => [
				'userConfig' => [
					'settings' => [],
					'styles'   => [],
				],
			],
			'after'              => [
				'userConfig' => [
					'settings' => [],
					'styles'   => [
						'color' => [
							'background' => 'var:preset|color|parchment-100',
						],
					],
				],
			],
			'freshnessSignature' => 'f',
			'actorRole'          => 'administrator',
			'relatedActivityId'  => 'act_9',
			'requestedAt'        => '2026-06-22T00:00:00+00:00',
			'decidedAt'          => '2026-06-22T00:01:00+00:00',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function template_context(): array {
		return [
			'surface'            => 'template',
			'templateRef'        => 'twentytwentyfive//home',
			'operations'         => [
				[
					'type'        => 'insert_pattern',
					'patternName' => 'twentytwentyfive/hero',
					'placement'   => 'start',
				],
			],
			'before'             => [
				'content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
			],
			'after'              => [
				'content' => '<!-- wp:heading --><h1>After</h1><!-- /wp:heading -->',
			],
			'freshnessSignature' => 'template-f',
			'actorRole'          => 'administrator',
			'relatedActivityId'  => 'act_template_1',
			'requestedAt'        => '2026-06-22T00:00:00+00:00',
			'decidedAt'          => '2026-06-22T00:01:00+00:00',
		];
	}

	/**
	 * @param array<string, mixed>|null $row
	 * @return array<string, mixed>
	 */
	private function statement_from_row( ?array $row ): array {
		$this->assertIsArray( $row );

		$statement = json_decode( (string) $row['statement_bytes'], true );
		$this->assertIsArray( $statement );

		return $statement;
	}

	private function configure_key(): void {
		$sk = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $sk );
	}

	private static function b64url_decode( string $value ): string {
		return (string) base64_decode(
			strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ),
			true
		);
	}
}
