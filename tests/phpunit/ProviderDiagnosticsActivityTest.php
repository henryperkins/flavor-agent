<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\RecommendationAbilityExecution;
use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ProviderDiagnosticsActivityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		ActivityRepository::install();
	}

	public function test_recommendation_execution_merges_runtime_diagnostics_into_partial_request_meta(): void {
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
			static function (): array {
				Provider::record_runtime_chat_configuration(
					[
						'provider' => 'wordpress_ai_client',
						'model'    => 'provider-managed',
					]
				);
				Provider::record_runtime_chat_metrics(
					[
						'tokenUsage' => [
							'input'  => 120,
							'output' => 30,
							'total'  => 150,
						],
						'latencyMs'  => 420,
					]
				);
				Provider::record_runtime_chat_diagnostics(
					[
						'transport'       => [
							'host'           => 'wordpress-ai-client',
							'path'           => '/generate-text',
							'timeoutSeconds' => 120,
						],
						'requestSummary'  => [
							'bodyBytes'             => 1024,
							'resolvedProvider'      => 'anthropic',
							'resolvedModel'         => 'claude-sonnet-4-6',
							'modelSelectionSource'  => 'ai_plugin_feature_developer',
							'modelResolutionStatus' => 'model',
						],
						'responseSummary' => [
							'httpStatus'        => 200,
							'providerRequestId' => 'request-123',
						],
					]
				);

				return [
					'suggestions' => [
						[
							'label' => 'Clarify header hierarchy',
						],
					],
					'explanation' => 'Use fewer competing sections.',
					'requestMeta' => [
						'transport' => [
							'provider' => 'test-transport',
						],
					],
				];
			}
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'WordPress AI Client', $result['requestMeta']['backendLabel'] ?? null );
		$this->assertSame( 'wordpress-ai-client', $result['requestMeta']['transport']['host'] ?? null );
		$this->assertSame( 'test-transport', $result['requestMeta']['transport']['provider'] ?? null );
		$this->assertSame( 'anthropic', $result['requestMeta']['requestSummary']['resolvedProvider'] ?? null );
		$this->assertSame( 'claude-sonnet-4-6', $result['requestMeta']['requestSummary']['resolvedModel'] ?? null );
		$this->assertSame( 150, $result['requestMeta']['tokenUsage']['total'] ?? null );
		$this->assertSame( 420, $result['requestMeta']['latencyMs'] ?? null );

		$rows = WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [];
		$this->assertCount( 1, $rows );

		$request = json_decode( (string) ( $rows[0]['request_json'] ?? '' ), true );
		$this->assertSame( 'wordpress-ai-client', $request['ai']['transport']['host'] ?? null );
		$this->assertSame( 'test-transport', $request['ai']['transport']['provider'] ?? null );
		$this->assertSame( 'anthropic', $request['ai']['requestSummary']['resolvedProvider'] ?? null );
		$this->assertSame( 'claude-sonnet-4-6', $request['ai']['requestSummary']['resolvedModel'] ?? null );
	}

	public function test_admin_projection_prefers_resolved_chat_provider_over_legacy_provider_fields(): void {
		$entry = [
			'id'         => 'provider-diagnostic-activity',
			'type'       => 'request_diagnostic',
			'surface'    => 'template',
			'target'     => [
				'templateRef' => 'theme//home',
			],
			'suggestion' => 'Template recommendation request',
			'before'     => [],
			'after'      => [],
			'request'    => [
				'prompt'    => 'Tighten the structure.',
				'reference' => 'template:theme//home:1',
				'ai'        => [
					'backendLabel'          => 'WordPress AI Client',
					'provider'              => 'wordpress_ai_client',
					'model'                 => 'provider-managed',
					'pathLabel'             => 'WordPress AI Client via Settings > Connectors',
					'ownerLabel'            => 'Settings > Connectors',
					'credentialSourceLabel' => 'Provider-managed',
					'selectedProviderLabel' => 'Cloudflare Workers AI',
					'requestSummary'        => [
						'resolvedProvider'      => 'anthropic',
						'resolvedModel'         => 'claude-sonnet-4-6',
						'modelSelectionSource'  => 'ai_plugin_feature_developer',
						'modelResolutionStatus' => 'model',
					],
				],
			],
			'document'   => [
				'scopeKey' => 'wp_template:theme//home',
				'postType' => 'wp_template',
				'entityId' => 'theme//home',
			],
			'timestamp'  => '2026-07-20T12:00:00Z',
		];

		$method = new \ReflectionMethod( ActivityRepository::class, 'build_admin_projection_from_entry' );
		$method->setAccessible( true );
		$projection = $method->invoke( null, $entry );

		$this->assertIsArray( $projection );
		$this->assertSame( 'anthropic', $projection['admin_provider'] ?? null );
		$this->assertSame( 'claude-sonnet-4-6', $projection['admin_model'] ?? null );
		$this->assertSame( 'anthropic', $projection['admin_selected_provider'] ?? null );
	}
}
