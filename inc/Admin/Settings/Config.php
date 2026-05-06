<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

final class Config {


	public const OPTION_GROUP                                    = 'flavor_agent_settings';
	public const PAGE_SLUG                                       = 'flavor-agent';
	public const GROUP_CHAT                                      = 'chat';
	public const GROUP_EMBEDDINGS                                = 'embeddings';
	public const GROUP_PATTERNS                                  = 'patterns';
	public const GROUP_DOCS                                      = 'docs';
	public const GROUP_GUIDELINES                                = 'guidelines';
	public const GROUP_EXPERIMENTS                               = 'experiments';
	public const OPTION_REASONING_EFFORT                         = 'flavor_agent_reasoning_effort';
	public const OPTION_LEGACY_AZURE_REASONING_EFFORT            = 'flavor_agent_azure_reasoning_effort';
	public const OPTION_BLOCK_STRUCTURAL_ACTIONS                 = 'flavor_agent_block_structural_actions_enabled';
	public const OPTION_PATTERN_RETRIEVAL_BACKEND                = 'flavor_agent_pattern_retrieval_backend';
	public const OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_ACCOUNT_ID  = 'flavor_agent_cloudflare_pattern_ai_search_account_id';
	public const OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE   = 'flavor_agent_cloudflare_pattern_ai_search_namespace';
	public const OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID = 'flavor_agent_cloudflare_pattern_ai_search_instance_id';
	public const OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN   = 'flavor_agent_cloudflare_pattern_ai_search_api_token';
	public const DEFAULT_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE  = 'patterns';
	public const OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH = 'flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search';
	public const PATTERN_BACKEND_QDRANT                                       = 'qdrant';
	public const PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH                         = 'cloudflare_ai_search';
	public const PATTERN_BACKENDS                    = [
		self::PATTERN_BACKEND_QDRANT,
		self::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
	];
	public const OPEN_SECTION_STORAGE_KEY            = 'flavor-agent-settings-open-section';
	public const PAGE_FEEDBACK_QUERY_KEY             = 'flavor_agent_settings_feedback_key';
	public const PAGE_FEEDBACK_FIELD_NAME            = 'flavor_agent_settings_feedback_key';
	public const PAGE_FEEDBACK_TRANSIENT_PREFIX      = 'flavor_agent_settings_page_feedback_';
	public const PAGE_FEEDBACK_TTL                   = 300;
	public const PATTERN_MAX_RECOMMENDATIONS_DEFAULT = 8;
	public const PATTERN_MAX_RECOMMENDATIONS_LIMIT   = 12;
	public const PATTERN_AI_SEARCH_THRESHOLD_DEFAULT = 0.2;

	private function __construct() {}
}
