<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

use FlavorAgent\Guidelines;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Registrar {

	public static function register_settings(): void {
		register_setting(
			Config::OPTION_GROUP,
			Provider::OPTION_NAME,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_openai_provider' ],
				'default'           => Provider::AZURE,
			]
		);

		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_azure_openai_endpoint',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_azure_openai_endpoint' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_azure_openai_key',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_azure_openai_key' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_azure_embedding_deployment',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_azure_embedding_deployment' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_azure_chat_deployment',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_azure_chat_deployment' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_azure_reasoning_effort',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_azure_reasoning_effort' ],
				'default'           => 'medium',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_openai_native_api_key',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_openai_native_api_key' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_openai_native_embedding_model',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_openai_native_embedding_model' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_openai_native_chat_model',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_openai_native_chat_model' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_qdrant_url',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_qdrant_url' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_qdrant_key',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_qdrant_key' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_pattern_recommendation_threshold',
			[
				'type'              => 'number',
				'sanitize_callback' => [ Settings::class, 'sanitize_pattern_recommendation_threshold' ],
				'default'           => 0.3,
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_pattern_max_recommendations',
			[
				'type'              => 'integer',
				'sanitize_callback' => [ Settings::class, 'sanitize_pattern_max_recommendations' ],
				'default'           => Config::PATTERN_MAX_RECOMMENDATIONS_DEFAULT,
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_account_id',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_cloudflare_account_id' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_instance_id',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_cloudflare_instance_id' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_api_token',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_cloudflare_api_token' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_max_results',
			[
				'type'              => 'integer',
				'sanitize_callback' => [ Settings::class, 'sanitize_grounding_result_count' ],
				'default'           => 4,
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			Guidelines::OPTION_SITE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_guideline_site' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			Guidelines::OPTION_COPY,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_guideline_copy' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			Guidelines::OPTION_IMAGES,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_guideline_images' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			Guidelines::OPTION_ADDITIONAL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_guideline_additional' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			Guidelines::OPTION_BLOCKS,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'sanitize_guideline_blocks' ],
				'default'           => [],
			]
		);

		add_settings_section(
			'flavor_agent_openai_provider',
			'AI Provider',
			[ Settings::class, 'render_openai_provider_section' ],
			Config::PAGE_SLUG
		);
		add_settings_section(
			'flavor_agent_azure',
			'Azure OpenAI',
			[ Settings::class, 'render_azure_section' ],
			Config::PAGE_SLUG
		);
		add_settings_section(
			'flavor_agent_openai_native',
			'OpenAI Native',
			[ Settings::class, 'render_openai_native_section' ],
			Config::PAGE_SLUG
		);
		add_settings_section(
			'flavor_agent_qdrant',
			'Qdrant Cloud',
			[ Settings::class, 'render_qdrant_section' ],
			Config::PAGE_SLUG
		);
		add_settings_section(
			'flavor_agent_pattern_recommendations',
			'Pattern Recommendations',
			[ Settings::class, 'render_pattern_recommendations_section' ],
			Config::PAGE_SLUG
		);
		add_settings_section(
			'flavor_agent_cloudflare',
			'Cloudflare AI Search',
			[ Settings::class, 'render_cloudflare_section' ],
			Config::PAGE_SLUG
		);
		add_settings_section(
			'flavor_agent_guidelines',
			'Guidelines',
			[ Settings::class, 'render_guidelines_section' ],
			Config::PAGE_SLUG
		);

		add_settings_field(
			Provider::OPTION_NAME,
			'Chat Provider',
			[ Settings::class, 'render_select_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_openai_provider',
			[
				'option'      => Provider::OPTION_NAME,
				'label_for'   => Provider::OPTION_NAME,
				'choices'     => Provider::choices( Provider::get() ),
				'description' => 'Choose the provider Flavor Agent should try first.',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_azure_openai_endpoint',
			'Azure Endpoint',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'       => 'flavor_agent_azure_openai_endpoint',
				'label_for'    => 'flavor_agent_azure_openai_endpoint',
				'type'         => 'url',
				'placeholder'  => 'https://my-resource.openai.azure.com/',
				'description'  => 'Azure OpenAI resource URL.',
				'autocomplete' => 'url',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_azure_openai_key',
			'API Key',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'       => 'flavor_agent_azure_openai_key',
				'label_for'    => 'flavor_agent_azure_openai_key',
				'type'         => 'password',
				'placeholder'  => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
				'description'  => 'Key 1 or Key 2 for that resource.',
				'autocomplete' => 'new-password',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_azure_embedding_deployment',
			'Embedding Deployment',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'       => 'flavor_agent_azure_embedding_deployment',
				'label_for'    => 'flavor_agent_azure_embedding_deployment',
				'placeholder'  => 'text-embedding-3-large',
				'description'  => 'Embedding deployment name.',
				'autocomplete' => 'off',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_azure_chat_deployment',
			'Responses Deployment',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'       => 'flavor_agent_azure_chat_deployment',
				'label_for'    => 'flavor_agent_azure_chat_deployment',
				'placeholder'  => 'gpt-5.4',
				'description'  => 'Responses deployment name.',
				'autocomplete' => 'off',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_azure_reasoning_effort',
			'Reasoning Effort',
			[ Settings::class, 'render_select_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'      => 'flavor_agent_azure_reasoning_effort',
				'label_for'   => 'flavor_agent_azure_reasoning_effort',
				'default'     => 'medium',
				'choices'     => [
					'low'    => 'Low',
					'medium' => 'Medium',
					'high'   => 'High',
					'xhigh'  => 'XHigh',
				],
				'description' => 'Default reasoning effort for Azure ranking calls.',
			]
		);
		add_settings_field(
			'flavor_agent_openai_native_api_key',
			'API Key',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_openai_native',
			[
				'option'       => 'flavor_agent_openai_native_api_key',
				'label_for'    => 'flavor_agent_openai_native_api_key',
				'type'         => 'password',
				'placeholder'  => 'sk-...',
				'description'  => 'Leave blank to use Connectors or OPENAI_API_KEY.',
				'autocomplete' => 'new-password',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_openai_native_embedding_model',
			'Embedding Model',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_openai_native',
			[
				'option'       => 'flavor_agent_openai_native_embedding_model',
				'label_for'    => 'flavor_agent_openai_native_embedding_model',
				'placeholder'  => 'text-embedding-3-large',
				'description'  => 'Embedding model ID.',
				'autocomplete' => 'off',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_openai_native_chat_model',
			'Responses Model',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_openai_native',
			[
				'option'       => 'flavor_agent_openai_native_chat_model',
				'label_for'    => 'flavor_agent_openai_native_chat_model',
				'placeholder'  => 'gpt-5.4',
				'description'  => 'Responses model ID.',
				'autocomplete' => 'off',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_qdrant_url',
			'Cluster URL',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_qdrant',
			[
				'option'       => 'flavor_agent_qdrant_url',
				'label_for'    => 'flavor_agent_qdrant_url',
				'type'         => 'url',
				'placeholder'  => 'https://my-cluster.cloud.qdrant.io:6333',
				'description'  => 'Qdrant cluster URL, including the port.',
				'autocomplete' => 'url',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_qdrant_key',
			'API Key',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_qdrant',
			[
				'option'       => 'flavor_agent_qdrant_key',
				'label_for'    => 'flavor_agent_qdrant_key',
				'type'         => 'password',
				'placeholder'  => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
				'description'  => 'API key with read and write access.',
				'autocomplete' => 'new-password',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_pattern_recommendation_threshold',
			'Ranking Threshold',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_pattern_recommendations',
			[
				'option'      => 'flavor_agent_pattern_recommendation_threshold',
				'label_for'   => 'flavor_agent_pattern_recommendation_threshold',
				'type'        => 'number',
				'default'     => '0.3',
				'step'        => '0.01',
				'min'         => '0',
				'max'         => '1',
				'placeholder' => '0.30',
				'description' => 'Higher values drop weaker matches.',
				'inputmode'   => 'decimal',
			]
		);
		add_settings_field(
			'flavor_agent_pattern_max_recommendations',
			'Max Results',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_pattern_recommendations',
			[
				'option'      => 'flavor_agent_pattern_max_recommendations',
				'label_for'   => 'flavor_agent_pattern_max_recommendations',
				'type'        => 'number',
				'default'     => (string) Config::PATTERN_MAX_RECOMMENDATIONS_DEFAULT,
				'step'        => '1',
				'min'         => '1',
				'max'         => (string) Config::PATTERN_MAX_RECOMMENDATIONS_LIMIT,
				'placeholder' => (string) Config::PATTERN_MAX_RECOMMENDATIONS_DEFAULT,
				'description' => 'Maximum recommendations returned.',
				'inputmode'   => 'numeric',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_ai_search_account_id',
			'Account ID',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option'       => 'flavor_agent_cloudflare_ai_search_account_id',
				'label_for'    => 'flavor_agent_cloudflare_ai_search_account_id',
				'placeholder'  => 'e.g. 1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d',
				'description'  => 'Optional override. Cloudflare account ID for older installs or custom endpoints.',
				'autocomplete' => 'off',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_ai_search_instance_id',
			'AI Search Instance ID',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option'       => 'flavor_agent_cloudflare_ai_search_instance_id',
				'label_for'    => 'flavor_agent_cloudflare_ai_search_instance_id',
				'placeholder'  => 'wordpress-developer-docs',
				'description'  => 'Optional override. AI Search instance ID for older installs or custom endpoints.',
				'autocomplete' => 'off',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_ai_search_api_token',
			'API Token',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option'       => 'flavor_agent_cloudflare_ai_search_api_token',
				'label_for'    => 'flavor_agent_cloudflare_ai_search_api_token',
				'type'         => 'password',
				'placeholder'  => 'Cloudflare API token',
				'description'  => 'Optional override. Needs AI Search Run or Edit for older installs or custom endpoints.',
				'autocomplete' => 'new-password',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_ai_search_max_results',
			'Max Grounding Sources',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option'      => 'flavor_agent_cloudflare_ai_search_max_results',
				'label_for'   => 'flavor_agent_cloudflare_ai_search_max_results',
				'type'        => 'number',
				'placeholder' => '4',
				'description' => 'Maximum docs sources per grounded request.',
				'inputmode'   => 'numeric',
			]
		);
		add_settings_field(
			Guidelines::OPTION_SITE,
			'Site Context',
			[ Settings::class, 'render_textarea_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_guidelines',
			[
				'option'      => Guidelines::OPTION_SITE,
				'label_for'   => Guidelines::OPTION_SITE,
				'rows'        => '5',
				'placeholder' => 'Describe your site purpose, goals, audience, products, services, or editorial context.',
			]
		);
		add_settings_field(
			Guidelines::OPTION_COPY,
			'Copy Guidelines',
			[ Settings::class, 'render_textarea_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_guidelines',
			[
				'option'      => Guidelines::OPTION_COPY,
				'label_for'   => Guidelines::OPTION_COPY,
				'rows'        => '6',
				'placeholder' => 'Tone, voice, formatting, banned phrases, preferred reading level, CTA style, spelling conventions...',
			]
		);
		add_settings_field(
			Guidelines::OPTION_IMAGES,
			'Image Guidelines',
			[ Settings::class, 'render_textarea_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_guidelines',
			[
				'option'      => Guidelines::OPTION_IMAGES,
				'label_for'   => Guidelines::OPTION_IMAGES,
				'rows'        => '5',
				'placeholder' => 'Preferred imagery, dimensions, mood, composition, accessibility requirements, illustration vs photography...',
			]
		);
		add_settings_field(
			Guidelines::OPTION_ADDITIONAL,
			'Additional Guidelines',
			[ Settings::class, 'render_textarea_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_guidelines',
			[
				'option'      => Guidelines::OPTION_ADDITIONAL,
				'label_for'   => Guidelines::OPTION_ADDITIONAL,
				'rows'        => '5',
				'placeholder' => 'Anything else Flavor Agent should consistently honor.',
			]
		);
	}
}
