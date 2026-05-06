<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

use FlavorAgent\Cloudflare\WorkersAIEmbeddingConfiguration;
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
				'default'           => WorkersAIEmbeddingConfiguration::PROVIDER,
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_pattern_retrieval_backend' ],
				'default'           => Config::PATTERN_BACKEND_QDRANT,
			]
		);

		register_setting(
			Config::OPTION_GROUP,
			Config::OPTION_REASONING_EFFORT,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_reasoning_effort' ],
				'default'           => 'medium',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_cloudflare_workers_ai_account_id',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_cloudflare_workers_ai_account_id' ],
				'default'           => '',
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_cloudflare_workers_ai_api_token',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_cloudflare_workers_ai_api_token' ],
				'default'           => '',
				'autoload'          => false,
			]
		);
		register_setting(
			Config::OPTION_GROUP,
			'flavor_agent_cloudflare_workers_ai_embedding_model',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_cloudflare_workers_ai_embedding_model' ],
				'default'           => WorkersAIEmbeddingConfiguration::DEFAULT_MODEL,
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
				'autoload'          => false,
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
			Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH,
			[
				'type'              => 'number',
				'sanitize_callback' => [ Settings::class, 'sanitize_pattern_recommendation_threshold_cloudflare_ai_search' ],
				'default'           => Config::PATTERN_AI_SEARCH_THRESHOLD_DEFAULT,
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
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID,
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_cloudflare_pattern_ai_search_instance_id' ],
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
		register_setting(
			Config::OPTION_GROUP,
			Config::OPTION_BLOCK_STRUCTURAL_ACTIONS,
			[
				'type'              => 'boolean',
				'sanitize_callback' => [ Settings::class, 'sanitize_block_structural_actions_enabled' ],
				'default'           => false,
			]
		);

		add_settings_section(
			'flavor_agent_cloudflare_workers_ai',
			'Cloudflare Workers AI Embeddings',
			[ Settings::class, 'render_cloudflare_workers_ai_section' ],
			Config::PAGE_SLUG
		);
		add_settings_section(
			'flavor_agent_pattern_retrieval',
			'Pattern Storage',
			[ Settings::class, 'render_pattern_retrieval_section' ],
			Config::PAGE_SLUG
		);
		add_settings_section(
			'flavor_agent_qdrant',
			'Qdrant Pattern Storage',
			[ Settings::class, 'render_qdrant_section' ],
			Config::PAGE_SLUG
		);
		add_settings_section(
			'flavor_agent_cloudflare_pattern_ai_search',
			'Cloudflare AI Search Pattern Storage',
			[ Settings::class, 'render_cloudflare_pattern_ai_search_section' ],
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
		add_settings_section(
			'flavor_agent_experimental_features',
			'Experimental Features',
			[ Settings::class, 'render_experimental_features_section' ],
			Config::PAGE_SLUG
		);

		add_settings_field(
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND,
			'Pattern Storage',
			[ Settings::class, 'render_select_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_pattern_retrieval',
			[
				'option'      => Config::OPTION_PATTERN_RETRIEVAL_BACKEND,
				'label_for'   => Config::OPTION_PATTERN_RETRIEVAL_BACKEND,
				'default'     => Config::PATTERN_BACKEND_QDRANT,
				'choices'     => [
					Config::PATTERN_BACKEND_QDRANT => 'Qdrant vector storage',
					Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH => 'Cloudflare AI Search managed index',
				],
				'description' => 'Choose where the pattern catalog is stored.',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_workers_ai_account_id',
			'Account ID',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_cloudflare_workers_ai',
			[
				'option'       => 'flavor_agent_cloudflare_workers_ai_account_id',
				'label_for'    => 'flavor_agent_cloudflare_workers_ai_account_id',
				'placeholder'  => 'Cloudflare account ID',
				'description'  => 'Cloudflare account for Workers AI.',
				'autocomplete' => 'off',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_workers_ai_api_token',
			'API Token',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_cloudflare_workers_ai',
			[
				'option'       => 'flavor_agent_cloudflare_workers_ai_api_token',
				'label_for'    => 'flavor_agent_cloudflare_workers_ai_api_token',
				'type'         => 'password',
				'placeholder'  => 'Cloudflare API token',
				'description'  => 'Workers AI API token.',
				'autocomplete' => 'new-password',
				'class'        => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_workers_ai_embedding_model',
			'Embedding Model',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_cloudflare_workers_ai',
			[
				'option'       => 'flavor_agent_cloudflare_workers_ai_embedding_model',
				'label_for'    => 'flavor_agent_cloudflare_workers_ai_embedding_model',
				'default'      => WorkersAIEmbeddingConfiguration::DEFAULT_MODEL,
				'placeholder'  => WorkersAIEmbeddingConfiguration::DEFAULT_MODEL,
				'description'  => 'Workers AI embedding model ID.',
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
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID,
			'Index Name',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_cloudflare_pattern_ai_search',
			[
				'option'       => Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID,
				'label_for'    => Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID,
				'placeholder'  => 'pattern-index',
				'description'  => 'Unique Cloudflare AI Search pattern index name.',
				'autocomplete' => 'off',
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
			Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH,
			'AI Search Match Threshold',
			[ Settings::class, 'render_text_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_pattern_recommendations',
			[
				'option'      => Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH,
				'label_for'   => Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH,
				'type'        => 'number',
				'default'     => (string) Config::PATTERN_AI_SEARCH_THRESHOLD_DEFAULT,
				'step'        => '0.01',
				'min'         => '0',
				'max'         => '1',
				'placeholder' => '0.20',
				'description' => 'AI Search match threshold.',
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
		add_settings_field(
			Config::OPTION_BLOCK_STRUCTURAL_ACTIONS,
			'Block Structural Actions',
			[ Settings::class, 'render_checkbox_field' ],
			Config::PAGE_SLUG,
			'flavor_agent_experimental_features',
			[
				'option'      => Config::OPTION_BLOCK_STRUCTURAL_ACTIONS,
				'label_for'   => Config::OPTION_BLOCK_STRUCTURAL_ACTIONS,
				'label'       => 'Enable structural block actions',
				'description' => 'Adds review-first insert and replace actions for the selected block.',
			]
		);
	}
}
