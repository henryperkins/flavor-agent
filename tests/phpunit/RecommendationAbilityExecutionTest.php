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

	/**
	 * @dataProvider request_diagnostic_title_cases
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $payload
	 */
	public function test_execute_persists_generic_request_diagnostic_titles(
		string $surface,
		string $ability_name,
		array $input,
		array $payload,
		string $expected_title
	): void {
		RecommendationAbilityExecution::execute(
			$surface,
			$ability_name,
			$input,
			static fn(): array => $payload
		);

		$entries = WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [];

		$this->assertCount( 1, $entries );
		$this->assertSame( $expected_title, $entries[0]['suggestion'] ?? null );

		$after = json_decode( (string) ( $entries[0]['after_state'] ?? '' ), true );
		$this->assertIsArray( $after );
		$this->assertNotSame( '', trim( (string) ( $after['diagnosticDetail'] ?? '' ) ) );
	}

	public function test_execute_persists_failed_request_diagnostic_title_without_raw_message(): void {
		$result = RecommendationAbilityExecution::execute(
			'block',
			'flavor-agent/recommend-block',
			[
				'editorContext' => [
					'block' => [
						'name' => 'core/paragraph',
					],
				],
				'clientId'      => 'block-a',
				'document'      => [
					'scopeKey' => 'post:42',
				],
			],
			static fn(): \WP_Error => new \WP_Error(
				'timeout',
				'Azure OpenAI responses request timed out after 180 seconds.'
			)
		);

		$this->assertTrue( \is_wp_error( $result ) );

		$entries = WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [];

		$this->assertCount( 1, $entries );
		$this->assertSame( 'Block request failed', $entries[0]['suggestion'] ?? null );

		$request = json_decode( (string) ( $entries[0]['request_json'] ?? '' ), true );
		$this->assertSame(
			'Azure OpenAI responses request timed out after 180 seconds.',
			$request['error']['message'] ?? null
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

	public function test_execute_persists_block_request_diagnostic_with_block_path(): void {
		RecommendationAbilityExecution::execute(
			'block',
			'flavor-agent/recommend-block',
			[
				'editorContext' => [
					'block' => [
						'name'      => 'core/paragraph',
						'blockPath' => [ 0, '2', -1, 'bad' ],
					],
				],
				'clientId'      => 'block-a',
				'document'      => [
					'scopeKey' => 'post:42',
				],
				'clientRequest' => [
					'sessionId'    => 'session-1',
					'requestToken' => 1,
					'abortId'      => 'block-a',
					'scopeKey'     => 'post:42',
				],
			],
			static fn(): array => [
				'block'       => [],
				'settings'    => [],
				'styles'      => [],
				'explanation' => 'Block request diagnostic.',
			]
		);

		$entries = WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [];
		$this->assertCount( 1, $entries );

		$target = json_decode( (string) ( $entries[0]['target_json'] ?? '' ), true );
		$this->assertSame( [ 0, 2 ], $target['blockPath'] ?? null );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>, 4: string}>
	 */
	public function request_diagnostic_title_cases(): array {
		return [
			'content title payload'       => [
				'content',
				'flavor-agent/recommend-content',
				[
					'prompt'   => 'Draft intro.',
					'document' => [
						'scopeKey' => 'post:42',
					],
				],
				[
					'title'   => 'Model-generated content headline',
					'summary' => 'Model-generated content summary.',
				],
				'Content recommendation request',
			],
			'navigation label payload'    => [
				'navigation',
				'flavor-agent/recommend-navigation',
				[
					'blockClientId' => 'nav-1',
					'document'      => [
						'scopeKey' => 'wp_template:theme//home',
					],
				],
				[
					'suggestions' => [
						[
							'label' => 'Group utility links',
						],
					],
				],
				'Navigation recommendation request',
			],
			'pattern title payload'       => [
				'pattern',
				'flavor-agent/recommend-patterns',
				[
					'postType' => 'page',
					'document' => [
						'scopeKey' => 'post:42',
					],
				],
				[
					'recommendations' => [
						[
							'title' => 'Hero pattern',
						],
					],
				],
				'Pattern recommendation request',
			],
			'block explanation payload'   => [
				'block',
				'flavor-agent/recommend-block',
				[
					'editorContext' => [
						'block' => [
							'name' => 'core/paragraph',
						],
					],
					'clientId'      => 'block-a',
					'document'      => [
						'scopeKey' => 'post:42',
					],
				],
				[
					'block'       => [],
					'settings'    => [],
					'styles'      => [],
					'explanation' => 'With no mapped Inspector panels available, the most reliable improvements are structural.',
				],
				'Block recommendation request',
			],
			'template label payload'      => [
				'template',
				'flavor-agent/recommend-template',
				[
					'templateRef' => 'theme//home',
					'document'    => [
						'scopeKey' => 'wp_template:theme//home',
					],
				],
				[
					'suggestions' => [
						[
							'label' => 'Clarify header hierarchy',
						],
					],
				],
				'Template recommendation request',
			],
			'template-part label payload' => [
				'template-part',
				'flavor-agent/recommend-template-part',
				[
					'templatePartRef' => 'theme//header',
					'document'        => [
						'scopeKey' => 'wp_template_part:theme//header',
					],
				],
				[
					'suggestions' => [
						[
							'label' => 'Add utility row',
						],
					],
				],
				'Template-part recommendation request',
			],
			'style book label payload'    => [
				'style',
				'flavor-agent/recommend-style',
				[
					'scope'    => [
						'surface'  => 'style-book',
						'scopeKey' => 'style_book:17:core/paragraph',
					],
					'document' => [
						'scopeKey' => 'style_book:17:core/paragraph',
					],
				],
				[
					'suggestions' => [
						[
							'label' => 'Tighten paragraph rhythm',
						],
					],
				],
				'Style Book recommendation request',
			],
		];
	}
}
