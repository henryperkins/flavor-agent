<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\AttestationService;
use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Attestation\Repository;
use FlavorAgent\Attestation\Signer;
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

		$id = AttestationService::record_apply( $this->apply_context() );

		$this->assertNotNull( $id );

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

	public function test_record_apply_returns_null_without_key(): void {
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => '' );

		$this->assertNull(
			AttestationService::record_apply(
				[
					'surface'        => 'global-styles',
					'globalStylesId' => '81',
					'operations'     => [],
					'before'         => [ 'userConfig' => [] ],
					'after'          => [ 'userConfig' => [] ],
				]
			)
		);
	}

	public function test_revert_is_chained_to_prior_and_findable(): void {
		$this->configure_key();
		Repository::install();

		$apply_id = AttestationService::record_apply( $this->apply_context() );
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

		$revert_id = AttestationService::record_revert( $apply_id, $revert_context );

		$this->assertNotNull( $revert_id );
		$this->assertSame( $revert_id, Repository::find_by_reverts( $apply_id )['attestation_id'] );
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
					'path'  => [ 'color', 'background' ],
					'value' => 'var:preset|color|parchment-100',
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
