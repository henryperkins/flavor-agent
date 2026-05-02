<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

final class Config {

	public const OPTION_GROUP                        = 'flavor_agent_settings';
	public const PAGE_SLUG                           = 'flavor-agent';
	public const GROUP_CHAT                          = 'chat';
	public const GROUP_PATTERNS                      = 'patterns';
	public const GROUP_DOCS                          = 'docs';
	public const GROUP_GUIDELINES                    = 'guidelines';
	public const GROUP_EXPERIMENTS                   = 'experiments';
	public const OPTION_BLOCK_STRUCTURAL_ACTIONS     = 'flavor_agent_block_structural_actions_enabled';
	public const OPEN_SECTION_STORAGE_KEY            = 'flavor-agent-settings-open-section';
	public const PAGE_FEEDBACK_QUERY_KEY             = 'flavor_agent_settings_feedback_key';
	public const PAGE_FEEDBACK_FIELD_NAME            = 'flavor_agent_settings_feedback_key';
	public const PAGE_FEEDBACK_TRANSIENT_PREFIX      = 'flavor_agent_settings_page_feedback_';
	public const PAGE_FEEDBACK_TTL                   = 300;
	public const PATTERN_MAX_RECOMMENDATIONS_DEFAULT = 8;
	public const PATTERN_MAX_RECOMMENDATIONS_LIMIT   = 12;

	private function __construct() {
	}
}
