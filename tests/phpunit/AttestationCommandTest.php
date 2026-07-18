<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\AttestationService;
use FlavorAgent\Attestation\Repository as AttestationRepository;
use FlavorAgent\CLI\AttestationCommand;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AttestationCommandTest extends TestCase {

	private const GLOBAL_STYLES_ID = '81';

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->configure_key();
		AttestationRepository::install();
	}

	public function test_register_adds_the_nested_attestation_command(): void {
		AttestationCommand::register();

		$this->assertSame(
			AttestationCommand::class,
			WordPressTestState::$wp_cli_commands['flavor-agent attestation']['callable'] ?? null
		);
	}

	public function test_verify_uses_incomplete_exit_code_when_attestation_is_missing(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Attestation not found: att_missing' );

		try {
			( new AttestationCommand() )->verify( [ 'att_missing' ], [] );
		} finally {
			$this->assertSame( 3, WordPressTestState::$wp_cli_exit_code );
		}
	}

	public function test_verify_outputs_signature_and_live_match_outcomes(): void {
		$config = [
			'settings' => [],
			'styles'   => [
				'color' => [
					'background' => 'var:preset|color|parchment-100',
				],
			],
		];
		$this->seed_global_styles_post( $config );

		$id = AttestationService::record_apply(
			[
				'surface'            => 'global-styles',
				'globalStylesId'     => self::GLOBAL_STYLES_ID,
				'blockName'          => '',
				'operations'         => [],
				'before'             => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'              => [ 'userConfig' => $config ],
				'freshnessSignature' => 'f',
				'actorRole'          => 'administrator',
				'relatedActivityId'  => 'activity-1',
				'requestedAt'        => '2026-06-22T00:00:00+00:00',
				'decidedAt'          => '2026-06-22T00:01:00+00:00',
			]
		)->attestation_id();

		$this->assertIsString( $id );

		( new AttestationCommand() )->verify( [ $id ], [] );

		$json = $this->last_cli_message( 'line' );
		$data = json_decode( $json, true );

		$this->assertSame( $id, $data['attestationId'] ?? null );
		$this->assertContains( 'signature_valid', $data['outcomes'] ?? [] );
		$this->assertContains( 'live_matches_subject', $data['outcomes'] ?? [] );
		$this->assertSame( 'verified', $data['verificationStatus'] ?? null );
		$this->assertSame( $id, $data['terminalAttestationId'] ?? null );
		$this->assertSame( 0, $data['chainDepth'] ?? null );
		$this->assertSame( 'Attestation verified.', $this->last_cli_message( 'success' ) );
	}

	public function test_verify_reports_superseded_outcome_for_a_later_apply(): void {
		$first_config  = [
			'settings' => [],
			'styles'   => [
				'color' => [
					'background' => '#111111',
				],
			],
		];
		$second_config = [
			'settings' => [],
			'styles'   => [
				'color' => [
					'background' => '#222222',
				],
			],
		];

		$this->seed_global_styles_post( $first_config );
		$first_id = AttestationService::record_apply(
			[
				'surface'            => 'global-styles',
				'globalStylesId'     => self::GLOBAL_STYLES_ID,
				'blockName'          => '',
				'operations'         => [],
				'before'             => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'              => [ 'userConfig' => $first_config ],
				'freshnessSignature' => 'f',
				'actorRole'          => 'administrator',
				'relatedActivityId'  => 'activity-1',
				'requestedAt'        => '2026-06-22T00:00:00+00:00',
				'decidedAt'          => '2026-06-22T00:01:00+00:00',
			]
		)->attestation_id();

		$this->assertIsString( $first_id );

		$this->seed_global_styles_post( $second_config );
		$second_id = AttestationService::record_apply(
			[
				'surface'            => 'global-styles',
				'globalStylesId'     => self::GLOBAL_STYLES_ID,
				'blockName'          => '',
				'operations'         => [],
				'before'             => [ 'userConfig' => $first_config ],
				'after'              => [ 'userConfig' => $second_config ],
				'freshnessSignature' => 'f',
				'actorRole'          => 'administrator',
				'relatedActivityId'  => 'activity-2',
				'requestedAt'        => '2026-06-22T00:02:00+00:00',
				'decidedAt'          => '2026-06-22T00:03:00+00:00',
			]
		)->attestation_id();

		$this->assertIsString( $second_id );

		( new AttestationCommand() )->verify( [ $first_id ], [] );

		$json = $this->last_cli_message( 'line' );
		$data = json_decode( $json, true );

		$this->assertSame( $first_id, $data['attestationId'] ?? null );
		$this->assertContains( 'signature_valid', $data['outcomes'] ?? [] );
		$this->assertContains( 'superseded_by_attestation', $data['outcomes'] ?? [] );
		$this->assertSame( 'verified', $data['verificationStatus'] ?? null );
		$this->assertSame( $second_id, $data['terminalAttestationId'] ?? null );
		$this->assertSame( 1, $data['chainDepth'] ?? null );
	}

	public function test_verify_fails_when_live_subject_cannot_be_resolved(): void {
		$id = AttestationService::record_apply(
			[
				'surface'            => 'global-styles',
				'globalStylesId'     => self::GLOBAL_STYLES_ID,
				'blockName'          => '',
				'operations'         => [],
				'before'             => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'              => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'freshnessSignature' => 'f',
				'actorRole'          => 'administrator',
				'relatedActivityId'  => 'activity-1',
				'requestedAt'        => '2026-06-22T00:00:00+00:00',
				'decidedAt'          => '2026-06-22T00:01:00+00:00',
			]
		)->attestation_id();

		$this->assertIsString( $id );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Attestation verification incomplete.' );

		try {
			( new AttestationCommand() )->verify( [ $id ], [] );
		} finally {
			$json = $this->last_cli_message( 'line' );
			$data = json_decode( $json, true );

			$this->assertSame( $id, $data['attestationId'] ?? null );
			$this->assertContains( 'signature_valid', $data['outcomes'] ?? [] );
			$this->assertContains( 'live_subject_unavailable', $data['outcomes'] ?? [] );
			$this->assertSame( 'incomplete', $data['verificationStatus'] ?? null );
			$this->assertSame( 'subject_unavailable', $data['subjectError'] ?? null );
			$this->assertSame( 3, WordPressTestState::$wp_cli_exit_code );
			$this->assertSame(
				[],
				array_values(
					array_filter(
						WordPressTestState::$wp_cli_messages,
						static fn ( array $message ): bool => 'success' === $message['type']
					)
				)
			);
		}
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function seed_global_styles_post( array $config ): void {
		WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ] = new \WP_Post(
			[
				'ID'           => (int) self::GLOBAL_STYLES_ID,
				'post_type'    => 'wp_global_styles',
				'post_content' => (string) wp_json_encode( $config ),
			]
		);
	}

	private function configure_key(): void {
		$sk = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn(): string => $sk );
	}

	private function last_cli_message( string $type ): string {
		$messages = array_values(
			array_filter(
				WordPressTestState::$wp_cli_messages,
				static fn ( array $message ): bool => $type === $message['type']
			)
		);

		$this->assertNotSame( [], $messages );

		return (string) $messages[ count( $messages ) - 1 ]['message'];
	}
}
