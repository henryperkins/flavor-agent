<?php

/**
 * Plugin-owned option names removed during uninstall.
 *
 * @package FlavorAgent
 */

declare(strict_types=1);

namespace FlavorAgent;

final class UninstallOptions {



	/**
	 * Return plugin-owned option names that should be deleted during uninstall.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		return [
			'flavor_agent_api_key',
			'flavor_agent_model',
			'flavor_agent_openai_provider',
			'flavor_agent_pattern_retrieval_backend',
			// Legacy Azure options are cleanup-only; settings UI no longer writes them.
			'flavor_agent_azure_openai_endpoint',
			'flavor_agent_azure_openai_key',
			'flavor_agent_azure_embedding_deployment',
			'flavor_agent_azure_chat_deployment',
			'flavor_agent_azure_reasoning_effort',
			'flavor_agent_reasoning_effort',
			'flavor_agent_openai_native_api_key',
			'flavor_agent_openai_native_embedding_model',
			'flavor_agent_cloudflare_workers_ai_account_id',
			'flavor_agent_cloudflare_workers_ai_api_token',
			'flavor_agent_cloudflare_workers_ai_embedding_model',
			'flavor_agent_qdrant_url',
			'flavor_agent_qdrant_key',
			'flavor_agent_pattern_recommendation_threshold',
			'flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search',
			'flavor_agent_pattern_max_recommendations',
			'flavor_agent_cloudflare_pattern_ai_search_account_id',
			'flavor_agent_cloudflare_pattern_ai_search_namespace',
			'flavor_agent_cloudflare_pattern_ai_search_instance_id',
			'flavor_agent_cloudflare_pattern_ai_search_api_token',
			// Legacy Developer Docs Cloudflare options are cleanup-only; runtime ignores them.
			'flavor_agent_cloudflare_ai_search_account_id',
			'flavor_agent_cloudflare_ai_search_instance_id',
			'flavor_agent_cloudflare_ai_search_api_token',
			'flavor_agent_cloudflare_ai_search_max_results',
			'flavor_agent_pattern_index_state',
			'flavor_agent_docs_prewarm_state',
			'flavor_agent_docs_runtime_state',
			'flavor_agent_docs_warm_queue',
			'flavor_agent_activity_schema_version',
			'flavor_agent_activity_retention_days',
			'flavor_agent_activity_admin_projection_backfill_cursor',
			'flavor_agent_guideline_site',
			'flavor_agent_guideline_copy',
			'flavor_agent_guideline_images',
			'flavor_agent_guideline_additional',
			'flavor_agent_guideline_blocks',
			'flavor_agent_guidelines_migration_status',
			'flavor_agent_block_structural_actions_enabled',
		];
	}

	private function __construct() {}
}
