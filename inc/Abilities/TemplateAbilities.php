<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\TemplatePrompt;
use FlavorAgent\Support\StringArray;

final class TemplateAbilities {

	public static function list_template_parts( array $input ): array {
		$area = $input['area'] ?? null;

		return [
			'templateParts' => ServerCollector::for_template_parts( $area ),
		];
	}

	/**
	 * Recommend template-part composition and patterns for a template.
	 *
	 * @param array $input { templateRef: string, templateType?: string, prompt?: string, visiblePatternNames?: string[] }
	 * @return array|\WP_Error Suggestions payload or error.
	 */
	public static function recommend_template( array $input ): array|\WP_Error {
		$template_ref = isset( $input['templateRef'] )
			? trim( (string) $input['templateRef'] )
			: '';
		$template_type = isset( $input['templateType'] ) && is_string( $input['templateType'] ) && $input['templateType'] !== ''
			? $input['templateType']
			: null;
		$prompt                = isset( $input['prompt'] ) ? (string) $input['prompt'] : '';
		$visible_pattern_names = array_key_exists( 'visiblePatternNames', $input )
			? StringArray::sanitize( $input['visiblePatternNames'] )
			: null;

		if ( $template_ref === '' ) {
			return new \WP_Error(
				'missing_template_ref',
				'A templateRef is required.',
				[ 'status' => 400 ]
			);
		}

		$context = ServerCollector::for_template(
			$template_ref,
			$template_type,
			$visible_pattern_names
		);
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$system = TemplatePrompt::build_system();
		$user   = TemplatePrompt::build_user( $context, $prompt );
		$result = ResponsesClient::rank( $system, $user );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return TemplatePrompt::parse_response( $result, $context );
	}
}
