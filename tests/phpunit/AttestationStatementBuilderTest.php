<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\StatementBuilder;
use PHPUnit\Framework\TestCase;

final class AttestationStatementBuilderTest extends TestCase {

	public function test_subject_digest_equals_after_digest(): void {
		$statement = json_decode( StatementBuilder::build( $this->params() ), true );

		$this->assertSame( $statement['subject'][0]['digest']['sha256'], $statement['predicate']['after']['sha256'] );
	}

	public function test_statement_freezes_the_owned_governance_lane(): void {
		$statement = json_decode( StatementBuilder::build( $this->params() ), true );

		$this->assertSame(
			[
				'approvalSurface' => 'settings-ai-activity',
				'claim'           => 'governed-change',
				'executor'        => 'bounded-server-style-apply',
				'lane'            => 'external-style-apply-v1',
			],
			$statement['predicate']['governance']
		);
	}

	public function test_excludes_non_allowlisted_fields(): void {
		$params                    = $this->params();
		$params['prompt']          = 'SECRET PROMPT';
		$params['displayName']     = 'Henry Perkins';
		$params['providerPayload'] = [ 'k' => 'v' ];

		$bytes = StatementBuilder::build( $params );

		$this->assertStringNotContainsString( 'SECRET PROMPT', $bytes );
		$this->assertStringNotContainsString( 'Henry Perkins', $bytes );
		$this->assertStringNotContainsString( 'providerPayload', $bytes );
	}

	public function test_canonical_json_is_key_order_stable(): void {
		$this->assertSame(
			StatementBuilder::canonical_json(
				[
					'b' => 1,
					'a' => 2,
				]
			),
			StatementBuilder::canonical_json(
				[
					'a' => 2,
					'b' => 1,
				]
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function params(): array {
		return [
			'attestationId'      => 'att_1',
			'surface'            => 'global-styles',
			'scope'              => 'global-styles',
			'subjectName'        => 'wp_global_styles:81',
			'governanceClaim'    => 'governed-change',
			'governanceLane'     => 'external-style-apply-v1',
			'approvalSurface'    => 'settings-ai-activity',
			'executor'           => 'bounded-server-style-apply',
			'operations'         => [
				[
					'path'  => [ 'color', 'background' ],
					'value' => 'var:preset|color|parchment-100',
				],
			],
			'beforeDigest'       => 'b',
			'afterDigest'        => 'a',
			'freshnessSignature' => 'f',
			'actorRole'          => 'administrator',
			'proposerVia'        => 'mcp/flavor-agent',
			'decision'           => 'approve',
			'requestedAt'        => '2026-06-22T00:00:00+00:00',
			'decidedAt'          => '2026-06-22T00:01:00+00:00',
			'siteUrl'            => 'https://example.com',
			'keyId'              => 'k1',
		];
	}
}
