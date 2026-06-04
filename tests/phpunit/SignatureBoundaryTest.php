<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\StyleAbilities;
use FlavorAgent\Abilities\TemplateAbilities;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * Guards the call-site boundary between response-side suggestion annotations
 * (validationReasons / validationVocabularyVersion) and the request-side
 * context inputs that the executable abilities hash into their applicability
 * signatures (RecommendationResolvedSignature / RecommendationReviewSignature).
 *
 * The guarantee is a property of the call sites, not of
 * RecommendationSignature::from_payload(): the abilities build their signature
 * inputs from the request context before parse_response() ever decorates a
 * suggestion, so the validation vocabulary must never appear in those inputs.
 * These tests assert the genuine payload arrays handed to from_payload(),
 * captured via thin *_for_tests seams that mirror the production call path.
 */
final class SignatureBoundaryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->prime_current_docs_source_coverage();
		WordPressTestState::$block_templates = [
			'wp_template'      => [
				(object) [
					'id'      => 'theme//home',
					'slug'    => 'home',
					'title'   => 'Home',
					'content' => '<!-- wp:group {"tagName":"main"} --><div>Main</div><!-- /wp:group -->',
				],
			],
			'wp_template_part' => [
				(object) [
					'id'      => 'theme//header',
					'slug'    => 'header',
					'title'   => 'Header',
					'area'    => 'header',
					'content' => '<!-- wp:group {"tagName":"header"} --><div>Header</div><!-- /wp:group -->',
				],
			],
		];
		WordPressTestState::$global_settings = [
			'color' => [
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#ff5500',
					],
				],
			],
		];
		$this->configure_text_generation_connector();
	}

	public function test_style_resolved_signature_inputs_contain_no_validation_annotations(): void {
		$payloads = StyleAbilities::build_signature_payloads_for_tests(
			$this->style_request()
		);

		$this->assertNoValidationAnnotations( $payloads['resolved'] ?? null, 'style resolved' );
	}

	public function test_style_review_signature_inputs_contain_no_validation_annotations(): void {
		$payloads = StyleAbilities::build_signature_payloads_for_tests(
			$this->style_request()
		);

		$this->assertNoValidationAnnotations( $payloads['review'] ?? null, 'style review' );
	}

	public function test_template_resolved_signature_inputs_contain_no_validation_annotations(): void {
		$payloads = TemplateAbilities::build_template_signature_payloads_for_tests(
			$this->template_request()
		);

		$this->assertNoValidationAnnotations( $payloads['resolved'] ?? null, 'template resolved' );
	}

	public function test_template_review_signature_inputs_contain_no_validation_annotations(): void {
		$payloads = TemplateAbilities::build_template_signature_payloads_for_tests(
			$this->template_request()
		);

		$this->assertNoValidationAnnotations( $payloads['review'] ?? null, 'template review' );
	}

	/**
	 * The seam must reflect the real production payload, not an empty stub, or
	 * the absence assertions would pass vacuously. Anchor on a context key that
	 * the genuine builders always include so a future regression that swaps the
	 * seam for an empty array fails loudly.
	 */
	public function test_style_signature_payloads_reflect_real_request_context(): void {
		$payloads = StyleAbilities::build_signature_payloads_for_tests(
			$this->style_request()
		);

		$this->assertIsArray( $payloads['resolved'] ?? null );
		$this->assertIsArray( $payloads['resolved']['context']['styleContext'] ?? null );
		$this->assertArrayHasKey( 'themeTokens', $payloads['resolved']['context']['styleContext'] );
	}

	public function test_template_signature_payloads_reflect_real_request_context(): void {
		$payloads = TemplateAbilities::build_template_signature_payloads_for_tests(
			$this->template_request()
		);

		$this->assertIsArray( $payloads['resolved'] ?? null );
		$this->assertSame( 'home', $payloads['resolved']['context']['templateType'] ?? null );
		$this->assertIsArray( $payloads['review'] ?? null );
		$this->assertArrayHasKey( 'template', $payloads['review'] );
	}

	/**
	 * @param mixed $payload
	 */
	private function assertNoValidationAnnotations( $payload, string $label ): void {
		$this->assertIsArray( $payload, $label . ' payload should be an array' );

		$json = (string) wp_json_encode( $payload );

		$this->assertStringNotContainsString(
			'validationReasons',
			$json,
			$label . ' signature inputs must not carry validationReasons'
		);
		$this->assertStringNotContainsString(
			'validationVocabularyVersion',
			$json,
			$label . ' signature inputs must not carry validationVocabularyVersion'
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function style_request(): array {
		return [
			'scope'        => [
				'surface'        => 'global-styles',
				'scopeKey'       => 'global_styles:17',
				'globalStylesId' => '17',
			],
			'styleContext' => [
				'currentConfig'         => [ 'styles' => [] ],
				'mergedConfig'          => [
					'styles' => [
						'color' => [
							'background' => '#000000',
							'text'       => '#000000',
						],
					],
				],
				'availableVariations'   => [],
				'themeTokenDiagnostics' => [
					'source'      => 'stable',
					'settingsKey' => 'features',
					'reason'      => 'stable-parity',
				],
			],
			'prompt'       => 'Make the palette warmer.',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function template_request(): array {
		return [
			'templateRef'  => 'theme//home',
			'templateType' => 'home',
			'prompt'       => 'Tighten the homepage composition.',
		];
	}

	private function configure_text_generation_connector(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => 'openai',
		];
		WordPressTestState::$connectors                 = [
			'openai' => [
				'name'           => 'OpenAI',
				'description'    => 'OpenAI connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [
			'openai' => true,
		];
	}

	private function prime_current_docs_source_coverage(): void {
		WordPressTestState::$transients['flavor_agent_docs_source_coverage_v2'] = [
			'status'                 => 'current',
			'hasDeveloperDocs'       => true,
			'hasCurrentReleaseCycle' => true,
			'sourceTypes'            => [ 'developer-docs', 'make-core' ],
			'freshness'              => [ 'current' ],
			'checkedAt'              => '2026-05-11 00:00:00',
			'errorCode'              => '',
			'errorMessage'           => '',
		];
	}
}
