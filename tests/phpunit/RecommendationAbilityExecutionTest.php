<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\RecommendationAbilityExecution;
use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class RecommendationAbilityExecutionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		ActivityRepository::install();
	}

	public function test_execute_appends_canonical_request_meta_and_persists_diagnostic_activity(): void {
		$result = RecommendationAbilityExecution::execute(
			'template',
			'flavor-agent/recommend-template',
			[
				'templateRef' => 'theme//home',
				'prompt'      => 'Tighten the structure.',
				'document'    => [
					'scopeKey' => 'wp_template:theme//home',
					'postType' => 'wp_template',
					'entityId' => 'theme//home',
				],
			],
			static fn( array $input ): array => [
				'suggestions' => [
					[
						'label' => 'Clarify header hierarchy',
					],
				],
				'explanation' => 'Use fewer competing sections.',
				'requestMeta' => [
					'transport' => [
						'provider' => 'test',
					],
				],
				'seenInput'   => $input,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'flavor-agent/recommend-template', $result['requestMeta']['ability'] ?? null );
		$this->assertSame( 'wp-abilities', $result['requestMeta']['executionTransport'] ?? null );
		$this->assertSame( 'wp-abilities:flavor-agent/recommend-template', $result['requestMeta']['route'] ?? null );
		$this->assertSame(
			[ 'provider' => 'test' ],
			$result['requestMeta']['transport'] ?? null
		);
		$this->assertArrayNotHasKey( 'document', $result['seenInput'] );

		$entries = WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [];

		$this->assertCount( 1, $entries );
		$this->assertSame( 'request_diagnostic', $entries[0]['activity_type'] ?? null );
		$this->assertSame( 'template', $entries[0]['surface'] ?? null );
		$request = json_decode( (string) ( $entries[0]['request_json'] ?? '' ), true );
		$this->assertSame(
			'flavor-agent/recommend-template',
			$request['ai']['ability'] ?? null
		);
	}

	public function test_signature_only_execute_skips_request_meta_and_activity(): void {
		$result = RecommendationAbilityExecution::execute(
			'template',
			'flavor-agent/recommend-template',
			[
				'templateRef'          => 'theme//home',
				'resolveSignatureOnly' => true,
				'document'             => [
					'scopeKey' => 'wp_template:theme//home',
				],
			],
			static fn(): array => [
				'resolvedContextSignature' => 'signature',
			]
		);

		$this->assertSame( 'signature', $result['resolvedContextSignature'] ?? null );
		$this->assertArrayNotHasKey( 'requestMeta', $result );
		$this->assertSame( [], WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [] );
	}

	public function test_execute_temporarily_prepends_canonical_system_instruction(): void {
		$seen_instruction = null;

		$result = RecommendationAbilityExecution::execute(
			'block',
			'flavor-agent/recommend-block',
			[],
			static function () use ( &$seen_instruction ): array {
				$seen_instruction = apply_filters(
					'flavor_agent_recommendation_system_instruction',
					'Existing prompt instruction.'
				);

				return [
					'resolvedContextSignature' => 'signature',
				];
			},
			'Canonical ability instruction.'
		);

		$this->assertSame( 'signature', $result['resolvedContextSignature'] ?? null );
		$this->assertSame( 'flavor-agent/recommend-block', $result['requestMeta']['ability'] ?? null );
		$this->assertSame(
			"Canonical ability instruction.\n\nExisting prompt instruction.",
			$seen_instruction
		);
		$this->assertSame(
			'After callback.',
			apply_filters( 'flavor_agent_recommendation_system_instruction', 'After callback.' )
		);
	}

	public function test_execute_strips_client_request_from_callback_input(): void {
		$seen_input = null;

		RecommendationAbilityExecution::execute(
			'content',
			'flavor-agent/recommend-content',
			[
				'prompt'        => 'Draft intro.',
				'clientRequest' => [
					'sessionId'    => 'session-1',
					'requestToken' => 4,
				],
			],
			static function ( array $input ) use ( &$seen_input ): array {
				$seen_input = $input;

				return [
					'content' => 'Drafted intro.',
				];
			}
		);

		$this->assertIsArray( $seen_input );
		$this->assertArrayNotHasKey( 'clientRequest', $seen_input );
	}

	public function test_execute_skips_stale_request_diagnostic_activity(): void {
		$document = [
			'scopeKey' => 'wp_template:theme//home',
		];

		RecommendationAbilityExecution::execute(
			'template',
			'flavor-agent/recommend-template',
			[
				'templateRef'   => 'theme//home',
				'document'      => $document,
				'clientRequest' => [
					'sessionId'    => 'session-1',
					'requestToken' => 2,
					'scopeKey'     => 'wp_template:theme//home',
				],
			],
			static fn(): array => [
				'suggestions' => [],
			]
		);

		RecommendationAbilityExecution::execute(
			'template',
			'flavor-agent/recommend-template',
			[
				'templateRef'   => 'theme//home',
				'document'      => $document,
				'clientRequest' => [
					'sessionId'    => 'session-1',
					'requestToken' => 1,
					'scopeKey'     => 'wp_template:theme//home',
				],
			],
			static fn(): array => [
				'suggestions' => [],
			]
		);

		$entries = WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [];

		$this->assertCount( 1, $entries );
	}

	public function test_execute_keeps_request_diagnostics_isolated_by_abort_id(): void {
		$document = [
			'scopeKey' => 'post:42',
		];

		RecommendationAbilityExecution::execute(
			'block',
			'flavor-agent/recommend-block',
			[
				'editorContext' => [
					'block' => [
						'name' => 'core/paragraph',
					],
				],
				'clientId'      => 'block-a',
				'document'      => $document,
				'clientRequest' => [
					'sessionId'    => 'session-1',
					'requestToken' => 2,
					'abortId'      => 'block-a',
					'scopeKey'     => 'post:42',
				],
			],
			static fn(): array => [
				'block'       => [],
				'settings'    => [],
				'styles'      => [],
				'explanation' => 'First block.',
			]
		);

		RecommendationAbilityExecution::execute(
			'block',
			'flavor-agent/recommend-block',
			[
				'editorContext' => [
					'block' => [
						'name' => 'core/heading',
					],
				],
				'clientId'      => 'block-b',
				'document'      => $document,
				'clientRequest' => [
					'sessionId'    => 'session-1',
					'requestToken' => 1,
					'abortId'      => 'block-b',
					'scopeKey'     => 'post:42',
				],
			],
			static fn(): array => [
				'block'       => [],
				'settings'    => [],
				'styles'      => [],
				'explanation' => 'Second block.',
			]
		);

		$entries = WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [];

		$this->assertCount( 2, $entries );
	}
}
