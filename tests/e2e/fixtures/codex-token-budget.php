<?php

declare(strict_types=1);

use FlavorAgent\AI\FeatureModelSelection;
use FlavorAgent\LLM\WordPressAIClient;
use FlavorAgent\OpenAI\Provider;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

// Synthetic Codex metadata exercises core's real option matching without a
// sidecar, credentials, or a generation HTTP request.
final class FlavorAgentTokenBudgetFixtureProvider extends AbstractProvider {

	public static array $generated_configs = [];

	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( 'codex', 'Codex fixture', ProviderTypeEnum::server() );
	}

	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new class() implements ProviderAvailabilityInterface {
			public function isConfigured(): bool {
				return true;
			}
		};
	}

	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new class() implements ModelMetadataDirectoryInterface {
			public function listModelMetadata(): array {
				return [ $this->getModelMetadata( 'gpt-6.1-sol' ) ];
			}

			public function hasModelMetadata( string $model_id ): bool {
				return 'gpt-6.1-sol' === $model_id;
			}

			public function getModelMetadata( string $model_id ): ModelMetadata {
				return new ModelMetadata(
					$model_id,
					'GPT 6.1 Sol fixture',
					[ CapabilityEnum::textGeneration() ],
					[
						new SupportedOption( OptionEnum::inputModalities(), [ [ ModalityEnum::text() ] ] ),
						new SupportedOption( OptionEnum::systemInstruction() ),
						new SupportedOption( OptionEnum::customOptions() ),
					]
				);
			}
		};
	}

	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		return new class( $model_metadata, $provider_metadata ) extends AbstractApiBasedModel implements TextGenerationModelInterface {
			public function generateTextResult( array $prompt ): GenerativeAiResult {
				unset( $prompt );
				FlavorAgentTokenBudgetFixtureProvider::$generated_configs[] = $this->getConfig()->toArray();
				return new GenerativeAiResult(
					'codex-token-budget-fixture',
					[ new Candidate( new ModelMessage( [ new MessagePart( '{"explanation":"OK."}' ) ] ), FinishReasonEnum::stop() ) ],
					new TokenUsage( 5, 5, 10 ),
					$this->providerMetadata(),
					$this->metadata()
				);
			}
		};
	}
}

AiClient::defaultRegistry()->registerProvider( FlavorAgentTokenBudgetFixtureProvider::class );
$previous = get_option( FeatureModelSelection::OPTION_NAME, '__missing__' );
try {
	update_option(
		FeatureModelSelection::OPTION_NAME,
		[
			'provider' => 'codex',
			'model'    => 'gpt-6.1-sol',
		]
	);
	$model    = AiClient::defaultRegistry()->getProviderModel( 'codex', 'gpt-6.1-sol' );
	$baseline = wp_ai_client_prompt( 'User.' )->using_model( $model )->is_supported_for_text_generation();
	$limited  = wp_ai_client_prompt( 'User.' )->using_model( $model )->using_max_tokens( 16384 )->is_supported_for_text_generation();
	$result   = WordPressAIClient::chat( 'System.', 'User.', null, 'medium' );
	$meta     = Provider::active_chat_request_meta();
	$explicit = WordPressAIClient::chat( 'System.', 'User.', null, null, null, [ 'max_tokens' => 500 ] );
	echo wp_json_encode(
		[
			'baselineSupported' => $baseline,
			'limitedSupported'  => $limited,
			'result'            => is_wp_error( $result ) ? $result->get_error_code() : $result,
			'configs'           => FlavorAgentTokenBudgetFixtureProvider::$generated_configs,
			'requestSummary'    => $meta['requestSummary'] ?? [],
			'explicitError'     => is_wp_error( $explicit ) ? $explicit->get_error_code() : '',
		]
	);
} finally {
	if ( '__missing__' === $previous ) {
		delete_option( FeatureModelSelection::OPTION_NAME );
	} else {
		update_option( FeatureModelSelection::OPTION_NAME, $previous );
	}
}
