<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\AttestationService;
use FlavorAgent\Attestation\Canonicalizer;
use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Attestation\Repository;
use FlavorAgent\Attestation\Signer;
use FlavorAgent\REST\AttestationController;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AttestationControllerTest extends TestCase {

	private const GLOBAL_STYLES_ID = '81';

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->configure_key();
		Repository::install();
	}

	public function test_get_attestation_returns_byte_exact_verifiable_envelope(): void {
		$id      = $this->record_apply();
		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/attestations/' . $id );
		$request->set_param( 'id', $id );

		$response = ( new AttestationController() )->get_attestation( $request );
		$data     = $response->get_data();

		$statement = self::b64url_decode( (string) $data['statement_b64'] );
		$signature = self::b64url_decode( (string) $data['signature_b64'] );

		$this->assertTrue( Signer::verify( $statement, $signature, (string) KeyManager::public_key() ) );
		$this->assertSame( json_decode( $statement, true ), $data['statement_json'] );
	}

	public function test_keys_route_returns_jwks(): void {
		$this->record_apply();

		$data = ( new AttestationController() )->get_keys( new \WP_REST_Request() )->get_data();

		$this->assertSame( 'OKP', $data['keys'][0]['kty'] );
		$this->assertSame( 'Ed25519', $data['keys'][0]['crv'] );
	}

	public function test_subject_state_returns_live_canonical_subject(): void {
		$config = [
			'settings' => [],
			'styles'   => [
				'color' => [
					'background' => 'var:preset|color|parchment-100',
				],
			],
		];
		$this->seed_global_styles_post( $config );
		$id      = $this->record_apply( $config );
		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/attestations/' . $id . '/subject-state' );
		$request->set_param( 'id', $id );

		$response = ( new AttestationController() )->get_subject_state( $request );
		$data     = $response->get_data();

		$this->assertSame( 'global-styles', $data['scope'] );
		$this->assertSame( Canonicalizer::digest( $config ), $data['subject_digest'] );
		$this->assertSame( Canonicalizer::canonical_bytes( $config ), self::b64url_decode( (string) $data['subject_canonical_b64'] ) );
	}

	/**
	 * @param array<string, mixed>|null $after_config
	 */
	private function record_apply( ?array $after_config = null ): string {
		$after_config ??= [
			'settings' => [],
			'styles'   => [
				'color' => [
					'background' => 'var:preset|color|parchment-100',
				],
			],
		];

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
				'after'              => [ 'userConfig' => $after_config ],
				'freshnessSignature' => 'f',
				'actorRole'          => 'administrator',
				'relatedActivityId'  => 'act_1',
				'requestedAt'        => '2026-06-22T00:00:00+00:00',
				'decidedAt'          => '2026-06-22T00:01:00+00:00',
			]
		);

		$this->assertIsString( $id );

		return $id;
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
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $sk );
	}

	private static function b64url_decode( string $value ): string {
		return (string) base64_decode(
			strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ),
			true
		);
	}
}
