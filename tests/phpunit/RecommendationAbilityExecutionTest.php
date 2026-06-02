<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\RecommendationAbilityExecution;
use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Activity\RequestLoggingBridge;
use FlavorAgent\Support\FlavorAgentRequestTag;
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

	public function test_execute_coerces_empty_object_typed_output_fields_to_json_objects(): void {
		$result = RecommendationAbilityExecution::execute(
			'pattern',
			'flavor-agent/recommend-patterns',
			[
				'postType' => 'page',
			],
			static fn(): array => [
				'recommendations' => [
					[
						'name'             => 'theme/hero',
						'patternOverrides' => [],
					],
				],
				'diagnostics'     => [
					'filteredCandidates' => [ 'unreadableSyncedPatterns' => 0 ],
					'pipelineTrace'      => [ 'backendRetrieved' => 0 ],
					'dropReasons'        => [],
				],
			]
		);

		$this->assertIsArray( $result );

		$json = wp_json_encode( $result );
		$this->assertIsString( $json );

		// Every empty object-typed field in a recommendation output must be
		// coerced to {} (per the ability output_schema) so the ajv-draft-04
		// bridge validator accepts it; none may serialize as an empty array.
		$this->assertStringContainsString( '"patternOverrides":{}', $json );
		$this->assertStringContainsString( '"dropReasons":{}', $json );
		$this->assertStringNotContainsString( '"patternOverrides":[]', $json );
		$this->assertStringNotContainsString( '"dropReasons":[]', $json );
	}

	public function test_execute_preserves_empty_arrays_when_output_schema_allows_arrays(): void {
		$result = RecommendationAbilityExecution::execute(
			'block',
			'flavor-agent/recommend-block',
			[
				'selectedBlock' => [
					'blockName' => 'core/group',
				],
			],
			static fn(): array => [
				'settings'    => [],
				'styles'      => [],
				'block'       => [
					[
						'label'            => 'Review variation',
						'attributeUpdates' => [],
						'currentValue'     => [],
						'suggestedValue'   => [],
					],
				],
				'explanation' => 'Empty list values should remain lists when the schema permits arrays.',
			]
		);

		$this->assertIsArray( $result );

		$json = wp_json_encode( $result );
		$this->assertIsString( $json );

		$this->assertStringContainsString( '"attributeUpdates":{}', $json );
		$this->assertStringContainsString( '"currentValue":[]', $json );
		$this->assertStringContainsString( '"suggestedValue":[]', $json );
		$this->assertStringNotContainsString( '"currentValue":{}', $json );
		$this->assertStringNotContainsString( '"suggestedValue":{}', $json );
	}

	public function test_execute_coerces_signature_only_output_object_fields(): void {
		$result = RecommendationAbilityExecution::execute(
			'pattern',
			'flavor-agent/recommend-patterns',
			[
				'postType'             => 'page',
				'resolveSignatureOnly' => true,
			],
			static fn(): array => [
				'recommendations'          => [],
				'resolvedContextSignature' => 'sig',
				'diagnostics'              => [
					'dropReasons' => [],
				],
			]
		);

		$this->assertIsArray( $result );

		$json = wp_json_encode( $result );
		$this->assertIsString( $json );

		// The signature-only early-return path must coerce object-typed maps too,
		// while leaving the (empty) recommendations list as a JSON array.
		$this->assertStringContainsString( '"dropReasons":{}', $json );
		$this->assertStringNotContainsString( '"dropReasons":[]', $json );
		$this->assertStringContainsString( '"recommendations":[]', $json );
	}

	public function test_execute_threads_core_request_log_identifiers_and_suppresses_duplicate_diagnostic_activity(): void {
		\add_filter( 'flavor_agent_core_request_logging_class_available', '__return_true' );
		WordPressTestState::$options = [
			'wpai_features_enabled'                   => true,
			'wpai_feature_ai-request-logging_enabled' => true,
		];

		$log_id = '22222222-2222-4222-8222-222222222222';

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
			static function () use ( $log_id ): array {
				$tag = FlavorAgentRequestTag::current();
				if ( ! $tag instanceof FlavorAgentRequestTag ) {
					throw new \RuntimeException( 'Missing active Flavor Agent request tag.' );
				}

				RequestLoggingBridge::capture_log_id(
					$log_id,
					[
						'context' => [
							'flavor_agent' => [
								'requestToken' => $tag->request_token(),
							],
						],
					]
				);

				return [
					'suggestions' => [
						[
							'label' => 'Clarify header hierarchy',
						],
					],
					'requestMeta' => [
						'provider' => 'openai',
						'model'    => 'gpt-5.4-mini',
					],
				];
			}
		);

		$this->assertIsArray( $result );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
			$result['requestMeta']['requestToken'] ?? ''
		);
		$this->assertSame( $log_id, $result['requestMeta']['requestLogId'] ?? null );
		$this->assertNull( RequestLoggingBridge::consume_log_id( (string) $result['requestMeta']['requestToken'] ) );
		$this->assertSame( [], WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [] );
	}

	public function test_execute_persists_resolved_provider_fields_in_request_diagnostic_activity(): void {
		RecommendationAbilityExecution::execute(
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
			static fn(): array => [
				'suggestions' => [],
				'explanation' => 'Use fewer competing sections.',
				'requestMeta' => [
					'provider'       => 'anthropic',
					'model'          => 'claude-sonnet-4-6',
					'requestSummary' => [
						'resolvedProvider'      => 'anthropic',
						'resolvedModel'         => 'claude-sonnet-4-6',
						'modelSelectionSource'  => 'ai_plugin_feature_developer',
						'modelResolutionStatus' => 'model',
					],
				],
			]
		);

		$entries = WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [];

		$this->assertCount( 1, $entries );
		$this->assertSame( 'request_diagnostic', $entries[0]['activity_type'] ?? null );

		$request = json_decode( (string) ( $entries[0]['request_json'] ?? '' ), true );
		$this->assertSame( 'anthropic', $request['ai']['provider'] ?? null );
		$this->assertSame( 'claude-sonnet-4-6', $request['ai']['model'] ?? null );
		$this->assertSame( 'anthropic', $request['ai']['requestSummary']['resolvedProvider'] ?? null );
		$this->assertSame( 'claude-sonnet-4-6', $request['ai']['requestSummary']['resolvedModel'] ?? null );
		$this->assertSame( 'ai_plugin_feature_developer', $request['ai']['requestSummary']['modelSelectionSource'] ?? null );
		$this->assertSame( 'model', $request['ai']['requestSummary']['modelResolutionStatus'] ?? null );
	}

	public function test_execute_persists_pattern_pipeline_trace_in_request_diagnostic_activity(): void {
		RecommendationAbilityExecution::execute(
			'pattern',
			'flavor-agent/recommend-patterns',
			[
				'prompt'   => 'Find a hero pattern.',
				'document' => [
					'scopeKey' => 'post:42',
					'postType' => 'post',
					'entityId' => '42',
				],
			],
			static fn(): array => [
				'recommendations' => [
					[
						'name'  => 'theme/hero',
						'title' => 'Hero',
					],
				],
				'diagnostics'     => [
					'pipelineTrace'      => [
						'backendRetrieved'        => 3,
						'visibleScopeDropped'     => 1,
						'rehydrationDropped'      => 1,
						'candidatePool'           => 1,
						'diversityDropped'        => 0,
						'llmReturned'             => 2,
						'llmNameMismatchDropped'  => 1,
						'belowThresholdDropped'   => 0,
						'returnedRecommendations' => 1,
					],
					'dropReasons'        => [
						'visible_scope'     => 1,
						'llm_name_mismatch' => 1,
						'rawPatternTitle'   => 'Private launch hero',
					],
					'filteredCandidates' => [
						'unreadableSyncedPatterns' => 1,
					],
				],
			]
		);

		$entries = WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [];
		$this->assertCount( 1, $entries );

		$after = json_decode( (string) ( $entries[0]['after_state'] ?? '' ), true );
		$this->assertSame(
			3,
			$after['pipelineTrace']['backendRetrieved'] ?? null
		);
		$this->assertSame(
			1,
			$after['pipelineTrace']['llmNameMismatchDropped'] ?? null
		);
		$this->assertSame(
			[
				'visible_scope'     => 1,
				'llm_name_mismatch' => 1,
			],
			$after['pipelineDropReasons'] ?? null
		);
		$this->assertStringNotContainsString( 'Private launch hero', wp_json_encode( $after ) );
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

	public function test_execute_exposes_flavor_agent_request_tag_during_callback_and_clears_after(): void {
		$this->assertTrue( \class_exists( FlavorAgentRequestTag::class ) );

		$seen_tag = null;

		$result = RecommendationAbilityExecution::execute(
			'template',
			'flavor-agent/recommend-template',
			[
				'templateRef'   => 'theme//home',
				'document'      => [
					'scopeKey' => 'wp_template:theme//home',
					'postType' => 'wp_template',
					'entityId' => 'theme//home',
				],
				'clientRequest' => [
					'sessionId'    => 'session-1',
					'requestToken' => 4,
					'scopeKey'     => 'wp_template:theme//home',
				],
			],
			static function () use ( &$seen_tag ): array {
				$seen_tag = FlavorAgentRequestTag::current();

				return [
					'suggestions' => [],
				];
			}
		);

		$this->assertIsArray( $result );
		$this->assertInstanceOf( FlavorAgentRequestTag::class, $seen_tag );
		$this->assertSame( 'template', $seen_tag->surface() );
		$this->assertSame( 'flavor-agent/recommend-template', $seen_tag->ability_name() );
		$this->assertSame( 'wp_template:theme//home', $seen_tag->scope_key() );
		$this->assertSame(
			[
				'scopeKey' => 'wp_template:theme//home',
				'postType' => 'wp_template',
				'entityId' => 'theme//home',
			],
			$seen_tag->document_ref()
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
			$seen_tag->request_token()
		);
		$this->assertNull( FlavorAgentRequestTag::current() );
	}

	public function test_execute_clears_flavor_agent_request_tag_when_callback_throws(): void {
		$this->assertTrue( \class_exists( FlavorAgentRequestTag::class ) );

		try {
			RecommendationAbilityExecution::execute(
				'content',
				'flavor-agent/recommend-content',
				[
					'prompt'   => 'Draft intro.',
					'document' => [
						'scopeKey' => 'post:42',
					],
				],
				static function (): array {
					$this_tag = FlavorAgentRequestTag::current();
					if ( ! $this_tag instanceof FlavorAgentRequestTag ) {
						throw new \RuntimeException( 'Missing active tag.' );
					}

					throw new \RuntimeException( 'Generation failed.' );
				}
			);
			$this->fail( 'Expected the callback exception to bubble.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'Generation failed.', $exception->getMessage() );
		}

		$this->assertNull( FlavorAgentRequestTag::current() );
	}

	public function test_execute_preserves_image_guidelines_when_category_declares_images(): void {
		WordPressTestState::$options = [
			\FlavorAgent\Guidelines::OPTION_SITE       => 'Use a calm site voice.',
			\FlavorAgent\Guidelines::OPTION_IMAGES     => 'Prefer documentary screenshots.',
			\FlavorAgent\Guidelines::OPTION_ADDITIONAL => 'Avoid hype.',
		];

		$seen_instruction = null;

		$result = RecommendationAbilityExecution::execute(
			'template-part',
			'flavor-agent/recommend-template-part',
			[ 'resolveSignatureOnly' => true ],
			static function () use ( &$seen_instruction ): array {
				$seen_instruction = apply_filters(
					'flavor_agent_recommendation_system_instruction',
					'Existing prompt instruction.'
				);

				return [
					'resolvedContextSignature' => 'signature',
				];
			},
			[
				'categories' => [ 'site', 'copy', 'images', 'additional' ],
				'blockName'  => '',
			]
		);

		$this->assertSame( 'signature', $result['resolvedContextSignature'] ?? null );
		$this->assertStringContainsString( 'Site: Use a calm site voice.', (string) $seen_instruction );
		$this->assertStringContainsString( 'Images: Prefer documentary screenshots.', (string) $seen_instruction );
		$this->assertStringContainsString( 'Additional: Avoid hype.', (string) $seen_instruction );
		$this->assertStringContainsString( 'Existing prompt instruction.', (string) $seen_instruction );
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
