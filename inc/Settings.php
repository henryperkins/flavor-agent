<?php

declare(strict_types=1);

namespace FlavorAgent;

use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	private const OPTION_GROUP = 'flavor_agent_settings';
	private const PAGE_SLUG    = 'flavor-agent';
	private const GROUP_CHAT   = 'chat';
	private const GROUP_PATTERNS = 'patterns';
	private const GROUP_DOCS   = 'docs';
	private const OPEN_SECTION_STORAGE_KEY = 'flavor-agent-settings-open-section';
	private const PAGE_FEEDBACK_QUERY_KEY = 'flavor_agent_settings_feedback_key';
	private const PAGE_FEEDBACK_FIELD_NAME = 'flavor_agent_settings_feedback_key';
	private const PAGE_FEEDBACK_TRANSIENT_PREFIX = 'flavor_agent_settings_page_feedback_';

	/**
	 * @var array{fingerprint: string, values: array<string, string>, error: \WP_Error|null}|null
	 */
	private static ?array $cloudflare_validation_state = null;

	private static bool $cloudflare_validation_error_reported = false;

	/**
	 * @var array{fingerprint: string, values: array<string, string>, error: \WP_Error|null}|null
	 */
	private static ?array $azure_validation_state = null;

	private static bool $azure_validation_error_reported = false;

	/**
	 * @var array{fingerprint: string, values: array<string, string>, error: \WP_Error|null}|null
	 */
	private static ?array $native_openai_validation_state = null;

	private static bool $native_openai_validation_error_reported = false;

	/**
	 * @var array{fingerprint: string, values: array<string, string>, error: \WP_Error|null}|null
	 */
	private static ?array $qdrant_validation_state = null;

	private static bool $qdrant_validation_error_reported = false;

	public static function add_menu(): void {
		$hook = add_options_page(
			'Flavor Agent',
			'Flavor Agent',
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);

		if ( $hook ) {
			add_action( "load-$hook", [ __CLASS__, 'register_contextual_help' ] );
			add_action(
				'admin_enqueue_scripts',
				function ( string $page_hook ) use ( $hook ) {
					if ( $page_hook !== $hook ) {
						return;
					}
					self::enqueue_admin_assets();
				}
			);
		}
	}

	public static function register_contextual_help(): void {
		$screen = get_current_screen();

		if ( ! is_object( $screen ) || ! method_exists( $screen, 'add_help_tab' ) ) {
			return;
		}

		foreach ( self::get_contextual_help_tabs() as $help_tab ) {
			$screen->add_help_tab( $help_tab );
		}

		if ( method_exists( $screen, 'set_help_sidebar' ) ) {
			$screen->set_help_sidebar( self::get_contextual_help_sidebar() );
		}
	}

	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Provider::OPTION_NAME,
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_openai_provider' ],
				'default'           => Provider::AZURE,
			]
		);

		// Azure OpenAI.
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_azure_openai_endpoint',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_azure_openai_endpoint' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_azure_openai_key',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_azure_openai_key' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_azure_embedding_deployment',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_azure_embedding_deployment' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_azure_chat_deployment',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_azure_chat_deployment' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_azure_reasoning_effort',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_azure_reasoning_effort' ],
				'default'           => 'medium',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_openai_native_api_key',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_openai_native_api_key' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_openai_native_embedding_model',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_openai_native_embedding_model' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_openai_native_chat_model',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_openai_native_chat_model' ],
				'default'           => '',
			]
		);

		// Qdrant.
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_qdrant_url',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_qdrant_url' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_qdrant_key',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_qdrant_key' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_pattern_recommendation_threshold',
			[
				'type'              => 'number',
				'sanitize_callback' => [ __CLASS__, 'sanitize_pattern_recommendation_threshold' ],
				'default'           => 0.3,
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_pattern_max_recommendations',
			[
				'type'              => 'integer',
				'sanitize_callback' => [ __CLASS__, 'sanitize_pattern_max_recommendations' ],
				'default'           => 8,
			]
		);
		// Cloudflare AI Search.
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_account_id',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_cloudflare_account_id' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_instance_id',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_cloudflare_instance_id' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_api_token',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_cloudflare_api_token' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_max_results',
			[
				'type'              => 'integer',
				'sanitize_callback' => [ __CLASS__, 'sanitize_grounding_result_count' ],
				'default'           => 4,
			]
		);

		// --- Sections ---

		add_settings_section(
			'flavor_agent_openai_provider',
			'AI Provider',
			[ __CLASS__, 'render_openai_provider_section' ],
			self::PAGE_SLUG
		);

		add_settings_section(
			'flavor_agent_azure',
			'Azure OpenAI',
			[ __CLASS__, 'render_azure_section' ],
			self::PAGE_SLUG
		);

		add_settings_section(
			'flavor_agent_openai_native',
			'OpenAI Native',
			[ __CLASS__, 'render_openai_native_section' ],
			self::PAGE_SLUG
		);

		add_settings_section(
			'flavor_agent_qdrant',
			'Qdrant Cloud',
			[ __CLASS__, 'render_qdrant_section' ],
			self::PAGE_SLUG
		);
		add_settings_section(
			'flavor_agent_pattern_recommendations',
			'Pattern Recommendations',
			[ __CLASS__, 'render_pattern_recommendations_section' ],
			self::PAGE_SLUG
		);

		add_settings_section(
			'flavor_agent_cloudflare',
			'Cloudflare AI Search',
			[ __CLASS__, 'render_cloudflare_section' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			Provider::OPTION_NAME,
			'Chat Provider',
			[ __CLASS__, 'render_select_field' ],
			self::PAGE_SLUG,
			'flavor_agent_openai_provider',
			[
				'option'      => Provider::OPTION_NAME,
				'label_for'   => Provider::OPTION_NAME,
				'choices'     => Provider::choices( Provider::get() ),
				'description' => 'Required for chat. Choose the path Flavor Agent should prefer first.',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);

		// --- Azure OpenAI fields ---

		add_settings_field(
			'flavor_agent_azure_openai_endpoint',
			'Azure Endpoint',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'      => 'flavor_agent_azure_openai_endpoint',
				'label_for'   => 'flavor_agent_azure_openai_endpoint',
				'type'        => 'url',
				'placeholder' => 'https://my-resource.openai.azure.com/',
				'description' => 'Required for Azure chat and embeddings. Use the Azure OpenAI resource endpoint.',
				'autocomplete' => 'url',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_azure_openai_key',
			'API Key',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'      => 'flavor_agent_azure_openai_key',
				'label_for'   => 'flavor_agent_azure_openai_key',
				'type'        => 'password',
				'placeholder' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
				'description' => 'Required for Azure chat and embeddings. Use Key 1 or Key 2 from the same Azure resource.',
				'autocomplete' => 'new-password',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_azure_embedding_deployment',
			'Embedding Deployment',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'      => 'flavor_agent_azure_embedding_deployment',
				'label_for'   => 'flavor_agent_azure_embedding_deployment',
				'placeholder' => 'text-embedding-3-large',
				'description' => 'Required for pattern recommendations when Azure provides embeddings.',
				'autocomplete' => 'off',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_azure_chat_deployment',
			'Responses Deployment',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'      => 'flavor_agent_azure_chat_deployment',
				'label_for'   => 'flavor_agent_azure_chat_deployment',
				'placeholder' => 'gpt-5.4',
				'description' => 'Required for chat when Azure is the selected direct provider.',
				'autocomplete' => 'off',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_azure_reasoning_effort',
			'Reasoning Effort',
			[ __CLASS__, 'render_select_field' ],
			self::PAGE_SLUG,
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
				'description' => 'Optional. Sets the default reasoning effort for Azure ranking calls.',
			]
		);

		// --- OpenAI Native fields ---

		add_settings_field(
			'flavor_agent_openai_native_api_key',
			'API Key',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_openai_native',
			[
				'option'      => 'flavor_agent_openai_native_api_key',
				'label_for'   => 'flavor_agent_openai_native_api_key',
				'type'        => 'password',
				'placeholder' => 'sk-...',
				'description' => 'Required only when you do not want to reuse Settings > Connectors or OPENAI_API_KEY.',
				'autocomplete' => 'new-password',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_openai_native_embedding_model',
			'Embedding Model',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_openai_native',
			[
				'option'      => 'flavor_agent_openai_native_embedding_model',
				'label_for'   => 'flavor_agent_openai_native_embedding_model',
				'placeholder' => 'text-embedding-3-large',
				'description' => 'Required for pattern recommendations when OpenAI Native provides embeddings.',
				'autocomplete' => 'off',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_openai_native_chat_model',
			'Responses Model',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_openai_native',
			[
				'option'      => 'flavor_agent_openai_native_chat_model',
				'label_for'   => 'flavor_agent_openai_native_chat_model',
				'placeholder' => 'gpt-5.4',
				'description' => 'Required for chat when OpenAI Native is the selected direct provider.',
				'autocomplete' => 'off',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);

		// --- Qdrant fields ---

		add_settings_field(
			'flavor_agent_qdrant_url',
			'Cluster URL',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_qdrant',
			[
				'option'      => 'flavor_agent_qdrant_url',
				'label_for'   => 'flavor_agent_qdrant_url',
				'type'        => 'url',
				'placeholder' => 'https://my-cluster.cloud.qdrant.io:6333',
				'description' => 'Required for pattern recommendations. Use the Qdrant cluster URL, including the port.',
				'autocomplete' => 'url',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_qdrant_key',
			'API Key',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_qdrant',
			[
				'option'      => 'flavor_agent_qdrant_key',
				'label_for'   => 'flavor_agent_qdrant_key',
				'type'        => 'password',
				'placeholder' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
				'description' => 'Required for pattern recommendations. The key needs read and write access.',
				'autocomplete' => 'new-password',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_pattern_recommendation_threshold',
			'Ranking Threshold',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
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
				'description' => 'Optional tuning. Higher values drop weaker matches after reranking.',
				'inputmode'   => 'decimal',
			]
		);
		add_settings_field(
			'flavor_agent_pattern_max_recommendations',
			'Max Results',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_pattern_recommendations',
			[
				'option'      => 'flavor_agent_pattern_max_recommendations',
				'label_for'   => 'flavor_agent_pattern_max_recommendations',
				'type'        => 'number',
				'default'     => '8',
				'step'        => '1',
				'min'         => '1',
				'max'         => '12',
				'placeholder' => '8',
				'description' => 'Optional tuning. Controls how many pattern recommendations are returned.',
				'inputmode'   => 'numeric',
			]
		);
		// --- Cloudflare AI Search fields ---

		add_settings_field(
			'flavor_agent_cloudflare_ai_search_account_id',
			'Account ID',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option'      => 'flavor_agent_cloudflare_ai_search_account_id',
				'label_for'   => 'flavor_agent_cloudflare_ai_search_account_id',
				'placeholder' => 'e.g. 1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d',
				'description' => 'Required for docs grounding. Use the Cloudflare account ID that owns the AI Search instance.',
				'autocomplete' => 'off',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_ai_search_instance_id',
			'AI Search Instance ID',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option'      => 'flavor_agent_cloudflare_ai_search_instance_id',
				'label_for'   => 'flavor_agent_cloudflare_ai_search_instance_id',
				'placeholder' => 'wordpress-developer-docs',
				'description' => 'Required for docs grounding. Use the AI Search instance ID that serves developer.wordpress.org content.',
				'autocomplete' => 'off',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_ai_search_api_token',
			'API Token',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option'      => 'flavor_agent_cloudflare_ai_search_api_token',
				'label_for'   => 'flavor_agent_cloudflare_ai_search_api_token',
				'type'        => 'password',
				'placeholder' => 'Cloudflare API token',
				'description' => 'Required for docs grounding. Needs AI Search Run or AI Search Edit.',
				'autocomplete' => 'new-password',
				'class'       => 'flavor-agent-settings-row--critical',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_ai_search_max_results',
			'Max Grounding Sources',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option'      => 'flavor_agent_cloudflare_ai_search_max_results',
				'label_for'   => 'flavor_agent_cloudflare_ai_search_max_results',
				'type'        => 'number',
				'placeholder' => '4',
				'description' => 'Optional. Controls how many developer docs can be added per grounded request.',
				'inputmode'   => 'numeric',
			]
		);
	}

	public static function render_page(): void {
		self::ensure_settings_api_registered();

		$state                = self::get_page_state();
		$feedback             = self::consume_settings_page_feedback();
		$feedback_request_key = self::generate_settings_page_feedback_request_key();
		$activity_url         = admin_url( 'options-general.php?page=flavor-agent-activity' );
		$connectors_url       = admin_url( 'options-connectors.php' );
		$default_group        = self::determine_default_open_group( $state );
		$forced_group         = is_string( $feedback['focus_section'] ?? null ) ? $feedback['focus_section'] : '';
		$chat_ready           = ! empty( $state['runtime_chat']['configured'] );
		$primary_url          = $chat_ready ? $activity_url : $connectors_url;
		$primary_label        = $chat_ready ? __( 'Activity Log', 'flavor-agent' ) : __( 'Connectors', 'flavor-agent' );
		$secondary_url        = $chat_ready ? $connectors_url : $activity_url;
		$secondary_label      = $chat_ready ? __( 'Connectors', 'flavor-agent' ) : __( 'Activity Log', 'flavor-agent' );
		$open_group           = '' !== $forced_group ? $forced_group : $default_group;
		?>
		<div class="wrap flavor-agent-settings-page">
			<div
				class="flavor-agent-settings"
				data-default-section="<?php echo esc_attr( $default_group ); ?>"
				data-force-section="<?php echo esc_attr( $forced_group ); ?>"
				data-open-section-storage-key="<?php echo esc_attr( self::OPEN_SECTION_STORAGE_KEY ); ?>"
			>
				<header class="flavor-agent-admin-hero flavor-agent-settings__hero">
					<div class="flavor-agent-admin-hero__content">
						<p class="flavor-agent-wordmark">
							<?php echo esc_html__( 'Flavor Agent', 'flavor-agent' ); ?>
						</p>
						<h1 class="flavor-agent-admin-hero__title">
							<?php echo esc_html__( 'Flavor Agent Settings', 'flavor-agent' ); ?>
						</h1>
						<p class="flavor-agent-admin-hero__copy">
							<?php echo esc_html__( 'Set up chat first. Pattern recommendations and docs grounding are optional. Use Help for setup guidance and troubleshooting.', 'flavor-agent' ); ?>
						</p>
						<div class="flavor-agent-admin-hero__actions">
							<a class="button button-primary" href="<?php echo esc_attr( self::sanitize_url_value( $primary_url ) ); ?>">
								<?php echo esc_html( $primary_label ); ?>
							</a>
							<a class="button button-secondary" href="<?php echo esc_attr( self::sanitize_url_value( $secondary_url ) ); ?>">
								<?php echo esc_html( $secondary_label ); ?>
							</a>
						</div>
					</div>
				</header>

				<?php self::render_setup_status_cards( $state, $activity_url ); ?>
				<?php self::render_settings_notices(); ?>
				<?php self::render_settings_save_summary( $feedback ); ?>

				<form method="post" action="options.php" class="flavor-agent-settings__form">
					<?php
					settings_fields( self::OPTION_GROUP );
					self::render_feedback_request_fields( $feedback_request_key );
					self::render_settings_section_group(
						self::GROUP_CHAT,
						__( '1. Chat Provider', 'flavor-agent' ),
						self::get_group_card_meta( self::GROUP_CHAT, $state ),
						$open_group,
						static function () use ( $state, $feedback, $connectors_url ): void {
							self::render_chat_provider_group( $state, $feedback, $connectors_url );
						}
					);
					self::render_settings_section_group(
						self::GROUP_PATTERNS,
						__( '2. Pattern Recommendations', 'flavor-agent' ),
						self::get_group_card_meta( self::GROUP_PATTERNS, $state ),
						$open_group,
						static function () use ( $state, $feedback ): void {
							self::render_pattern_recommendations_group( $state, $feedback );
						}
					);
					self::render_settings_section_group(
						self::GROUP_DOCS,
						__( '3. Docs Grounding', 'flavor-agent' ),
						self::get_group_card_meta( self::GROUP_DOCS, $state ),
						$open_group,
						static function () use ( $state, $feedback ): void {
							self::render_docs_grounding_group( $state, $feedback );
						}
					);
					?>
					<div class="flavor-agent-settings__actions">
						<?php
						submit_button(
							__( 'Save Changes', 'flavor-agent' ),
							'primary',
							'submit',
							false
						);
						?>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Field renderers
	// ------------------------------------------------------------------

	private static function render_settings_notices(): void {
		// Render all Settings API notices so core success messages and plugin-specific
		// validation errors both survive the post-save redirect.
		settings_errors();
	}

	private static function ensure_settings_api_registered(): void {
		global $wp_settings_fields, $wp_settings_sections;

		if (
			! empty( $wp_settings_sections[ self::PAGE_SLUG ] ) &&
			! empty( $wp_settings_fields[ self::PAGE_SLUG ] )
		) {
			return;
		}

		self::register_settings();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function get_page_state(): array {
		$selected_provider         = Provider::get();
		$selected_chat             = Provider::chat_configuration( $selected_provider );
		$runtime_chat              = Provider::chat_configuration();
		$selected_embedding        = Provider::embedding_configuration( $selected_provider );
		$runtime_embedding         = Provider::embedding_configuration();
		$qdrant_configured         = '' !== (string) get_option( 'flavor_agent_qdrant_url', '' )
			&& '' !== (string) get_option( 'flavor_agent_qdrant_key', '' );
		$pattern_state             = PatternIndex::get_runtime_state();
		$patterns_ready_for_sync   = PatternIndex::recommendation_backends_configured();
		$docs_configured           = AISearchClient::is_configured();
		$prewarm_state             = AISearchClient::get_prewarm_state();
		$runtime_docs_grounding    = AISearchClient::get_runtime_state();

		return [
			'selected_provider'   => $selected_provider,
			'selected_chat'       => $selected_chat,
			'runtime_chat'        => $runtime_chat,
			'selected_embedding'  => $selected_embedding,
			'runtime_embedding'   => $runtime_embedding,
			'qdrant_configured'   => $qdrant_configured,
			'pattern_state'       => $pattern_state,
			'patterns_ready'      => $patterns_ready_for_sync,
			'docs_configured'     => $docs_configured,
			'prewarm_state'       => $prewarm_state,
			'runtime_docs_grounding' => $runtime_docs_grounding,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function consume_settings_page_feedback(): array {
		$storage_key = self::get_settings_page_feedback_storage_key(
			self::get_settings_page_feedback_request_key_from_query()
		);

		if ( '' === $storage_key ) {
			return self::get_default_settings_page_feedback();
		}

		$feedback = get_transient( $storage_key );

		if ( is_array( $feedback ) ) {
			delete_transient( $storage_key );

			return array_merge(
				self::get_default_settings_page_feedback(),
				$feedback
			);
		}

		return self::get_default_settings_page_feedback();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function get_default_settings_page_feedback(): array {
		return [
			'changed_sections' => [],
			'messages'         => [],
			'focus_section'    => '',
		];
	}

	private static function generate_settings_page_feedback_request_key(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $exception ) {
			return substr( hash( 'sha256', uniqid( '', true ) ), 0, 16 );
		}
	}

	private static function render_feedback_request_fields( string $feedback_request_key ): void {
		printf(
			'<input type="hidden" name="%1$s" value="%2$s" /><input type="hidden" name="_wp_http_referer" value="%3$s" />',
			esc_attr( self::PAGE_FEEDBACK_FIELD_NAME ),
			esc_attr( $feedback_request_key ),
			esc_attr( self::get_settings_page_form_referer( $feedback_request_key ) )
		);
	}

	private static function get_settings_page_form_referer( string $feedback_request_key ): string {
		return self::sanitize_url_value(
			admin_url(
				sprintf(
					'options-general.php?page=%1$s&%2$s=%3$s',
					self::PAGE_SLUG,
					self::PAGE_FEEDBACK_QUERY_KEY,
					rawurlencode( $feedback_request_key )
				)
			)
		);
	}

	private static function get_settings_page_feedback_request_key_from_post(): string {
		if ( ! self::has_valid_settings_submission_nonce() ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated above; the value is unslashed and sanitized immediately below.
		$feedback_key = wp_unslash( $_POST[ self::PAGE_FEEDBACK_FIELD_NAME ] ?? '' );

		return self::sanitize_settings_page_feedback_request_key( $feedback_key );
	}

	private static function get_settings_page_feedback_request_key_from_query(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only query param used to match request-scoped feedback after the save redirect.
		$feedback_key = wp_unslash( $_GET[ self::PAGE_FEEDBACK_QUERY_KEY ] ?? '' );

		return self::sanitize_settings_page_feedback_request_key( $feedback_key );
	}

	private static function sanitize_settings_page_feedback_request_key( mixed $feedback_key ): string {
		if ( ! is_string( $feedback_key ) ) {
			return '';
		}

		return substr( sanitize_key( $feedback_key ), 0, 32 );
	}

	private static function get_settings_page_feedback_storage_key( string $feedback_request_key ): string {
		if ( '' === $feedback_request_key ) {
			return '';
		}

		return sprintf(
			'%s%d_%s',
			self::PAGE_FEEDBACK_TRANSIENT_PREFIX,
			max( 0, get_current_user_id() ),
			$feedback_request_key
		);
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function render_settings_save_summary( array $feedback ): void {
		if ( ! self::has_settings_updated_query_flag() ) {
			return;
		}

		$changed_sections = is_array( $feedback['changed_sections'] ?? null )
			? $feedback['changed_sections']
			: [];
		$messages         = is_array( $feedback['messages'] ?? null )
			? $feedback['messages']
			: [];
		$summary_lines    = [];

		if (
			! empty( $changed_sections[ self::GROUP_CHAT ] ) &&
			(
				empty( $messages[ self::GROUP_CHAT ]['tone'] ) ||
				( $messages[ self::GROUP_CHAT ]['tone'] ?? '' ) !== 'error'
			)
		) {
			$summary_lines[] = __( 'Chat provider saved.', 'flavor-agent' );
		}

		if (
			! empty( $changed_sections[ self::GROUP_PATTERNS ] ) &&
			(
				empty( $messages[ self::GROUP_PATTERNS ]['tone'] ) ||
				( $messages[ self::GROUP_PATTERNS ]['tone'] ?? '' ) !== 'error'
			)
		) {
			$summary_lines[] = PatternIndex::recommendation_backends_configured()
				? __( 'Pattern settings saved. Run Pattern Sync to update the index.', 'flavor-agent' )
				: __( 'Pattern settings saved.', 'flavor-agent' );
		}

		if (
			! empty( $changed_sections[ self::GROUP_DOCS ] ) &&
			(
				empty( $messages[ self::GROUP_DOCS ]['tone'] ) ||
				( $messages[ self::GROUP_DOCS ]['tone'] ?? '' ) !== 'error'
			)
		) {
			$summary_lines[] = __( 'Docs grounding settings saved.', 'flavor-agent' );
		}

		$summary_lines = array_values(
			array_filter(
				$summary_lines,
				static fn( $line ): bool => is_string( $line ) && '' !== $line
			)
		);

		if ( [] === $summary_lines ) {
			return;
		}
		?>
		<div class="notice notice-success inline flavor-agent-settings-save-summary">
			<?php foreach ( $summary_lines as $summary_line ) : ?>
				<p><?php echo esc_html( $summary_line ); ?></p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function determine_default_open_group( array $state ): string {
		if ( empty( $state['runtime_chat']['configured'] ) ) {
			return self::GROUP_CHAT;
		}

		if (
			! empty( $state['pattern_state']['last_error'] ) ||
			'stale' === (string) ( $state['pattern_state']['status'] ?? '' ) ||
			( ! empty( $state['patterns_ready'] ) && 'uninitialized' === (string) ( $state['pattern_state']['status'] ?? '' ) ) ||
			( ! empty( $state['qdrant_configured'] ) xor ! empty( $state['runtime_embedding']['configured'] ) )
		) {
			return self::GROUP_PATTERNS;
		}

		if (
			! empty( $state['docs_configured'] ) &&
			(
				in_array( (string) ( $state['prewarm_state']['status'] ?? '' ), [ 'failed', 'partial' ], true ) ||
				in_array(
					(string) ( $state['runtime_docs_grounding']['status'] ?? '' ),
					[ 'degraded', 'error', 'retrying' ],
					true
				)
			)
		) {
			return self::GROUP_DOCS;
		}

		return self::GROUP_CHAT;
	}

	private static function get_section_dom_id( string $group ): string {
		return 'flavor-agent-section-' . sanitize_html_class( $group );
	}

	/**
	 * @return array{summary: string, badges: array<int, array{label: string, tone: string}>, status: array{label: string, tone: string}, open: bool}
	 */
	private static function get_group_card_meta( string $group, array $state ): array {
		$pattern_status = self::get_pattern_overview_status( $state );
		$docs_status    = self::get_docs_overview_status( $state );

		return match ( $group ) {
			self::GROUP_CHAT => [
				'summary' => __( 'Required. Choose the chat path Flavor Agent should prefer.', 'flavor-agent' ),
				'badges'  => [
					self::make_badge( __( 'Required', 'flavor-agent' ), 'neutral' ),
					self::make_badge( Provider::label( (string) $state['selected_provider'] ), 'accent' ),
				],
				'status'  => self::make_badge(
					! empty( $state['selected_chat']['configured'] )
						? __( 'Ready', 'flavor-agent' )
						: ( ! empty( $state['runtime_chat']['configured'] ) ? __( 'Partial', 'flavor-agent' ) : __( 'Needs setup', 'flavor-agent' ) ),
					! empty( $state['selected_chat']['configured'] )
						? 'success'
						: ( ! empty( $state['runtime_chat']['configured'] ) ? 'warning' : 'warning' )
				),
				'open'    => false,
			],
			self::GROUP_PATTERNS => [
				'summary' => __( 'Optional. Add vector search for pattern recommendations.', 'flavor-agent' ),
				'badges'  => [
					self::make_badge( __( 'Optional', 'flavor-agent' ), 'neutral' ),
				],
				'status'  => $pattern_status,
				'open'    => false,
			],
			self::GROUP_DOCS => [
				'summary' => __( 'Optional. Ground responses with developer.wordpress.org docs.', 'flavor-agent' ),
				'badges'  => [
					self::make_badge( __( 'Optional', 'flavor-agent' ), 'neutral' ),
				],
				'status'  => $docs_status,
				'open'    => false,
			],
			default => [
				'summary' => '',
				'badges'  => [],
				'status'  => self::make_badge( '', 'neutral' ),
				'open'    => false,
			],
		};
	}

	/**
	 * @return array{label: string, tone: string}
	 */
	private static function get_pattern_overview_status( array $state ): array {
		$pattern_state = is_array( $state['pattern_state'] ?? null ) ? $state['pattern_state'] : [];

		if ( empty( $state['patterns_ready'] ) ) {
			return self::make_badge( __( 'Needs embeddings & Qdrant', 'flavor-agent' ), 'warning' );
		}

		if ( ! empty( $pattern_state['last_error'] ) ) {
			return self::make_badge( __( 'Needs attention', 'flavor-agent' ), 'error' );
		}

		return match ( (string) ( $pattern_state['status'] ?? 'uninitialized' ) ) {
			'ready' => self::make_badge( __( 'Ready', 'flavor-agent' ), 'success' ),
			'stale' => self::make_badge( __( 'Refresh needed', 'flavor-agent' ), 'warning' ),
			'indexing' => self::make_badge( __( 'Syncing', 'flavor-agent' ), 'accent' ),
			default => self::make_badge( __( 'Needs sync', 'flavor-agent' ), 'warning' ),
		};
	}

	/**
	 * @return array{label: string, tone: string}
	 */
	private static function get_docs_overview_status( array $state ): array {
		if ( empty( $state['docs_configured'] ) ) {
			return self::make_badge( __( 'Off', 'flavor-agent' ), 'neutral' );
		}

		$prewarm_status = (string) ( $state['prewarm_state']['status'] ?? 'never' );
		$runtime_status = (string) ( $state['runtime_docs_grounding']['status'] ?? 'idle' );

		if ( 'retrying' === $runtime_status ) {
			return self::make_badge( __( 'Retrying', 'flavor-agent' ), 'warning' );
		}

		if ( 'warming' === $runtime_status ) {
			return self::make_badge( __( 'Warming', 'flavor-agent' ), 'accent' );
		}

		if (
			in_array( $runtime_status, [ 'degraded', 'error' ], true ) ||
			in_array( $prewarm_status, [ 'failed', 'partial' ], true )
		) {
			return self::make_badge( __( 'Needs attention', 'flavor-agent' ), 'warning' );
		}

		return self::make_badge( __( 'On', 'flavor-agent' ), 'success' );
	}

	private static function render_setup_status_cards( array $state, string $activity_url ): void {
		$chat_status    = ! empty( $state['runtime_chat']['configured'] )
			? self::make_badge( __( 'Ready', 'flavor-agent' ), 'success' )
			: self::make_badge( __( 'Needs setup', 'flavor-agent' ), 'warning' );
		$pattern_status = self::get_pattern_overview_status( $state );
		$docs_status    = self::get_docs_overview_status( $state );
		?>
		<div class="flavor-agent-settings__glance">
			<?php
			self::render_setup_status_card(
				__( 'Chat Provider', 'flavor-agent' ),
				$chat_status['label'],
				$chat_status['tone'],
				__( 'Start here. Chat must be ready before the rest of the page matters.', 'flavor-agent' ),
				'#' . self::get_section_dom_id( self::GROUP_CHAT )
			);
			self::render_setup_status_card(
				__( 'Pattern Recommendations', 'flavor-agent' ),
				$pattern_status['label'],
				$pattern_status['tone'],
				__( 'Optional second step for vector-based pattern recommendations.', 'flavor-agent' ),
				'#' . self::get_section_dom_id( self::GROUP_PATTERNS ),
				[
					'data-pattern-overview-status' => 'true',
				]
			);
			self::render_setup_status_card(
				__( 'Docs Grounding', 'flavor-agent' ),
				$docs_status['label'],
				$docs_status['tone'],
				__( 'Optional third step for developer.wordpress.org grounding.', 'flavor-agent' ),
				'#' . self::get_section_dom_id( self::GROUP_DOCS )
			);
			self::render_setup_status_card(
				__( 'Recent Activity', 'flavor-agent' ),
				__( 'View log', 'flavor-agent' ),
				'neutral',
				__( 'Review requests, sync runs, and diagnostics in the activity log.', 'flavor-agent' ),
				$activity_url
			);
			?>
		</div>
		<?php
	}

	/**
	 * @param array<string, string> $attributes
	 */
	private static function render_setup_status_card(
		string $title,
		string $status,
		string $tone,
		string $description,
		string $url,
		array $attributes = []
	): void {
		$card_attributes = array_merge(
			[
				'class' => 'flavor-agent-settings__glance-item flavor-agent-settings__glance-item--' . $tone,
				'href'  => self::sanitize_url_value( $url ),
			],
			$attributes
		);
		?>
		<a<?php self::render_html_attributes( $card_attributes ); ?>>
			<p class="flavor-agent-settings__glance-label">
				<?php echo esc_html( $title ); ?>
			</p>
			<p class="flavor-agent-settings__glance-value">
				<?php echo esc_html( $status ); ?>
			</p>
			<p class="flavor-agent-settings__glance-copy">
				<?php echo esc_html( $description ); ?>
			</p>
		</a>
		<?php
	}

	/**
	 * @param array{summary: string, badges: array<int, array{label: string, tone: string}>, status: array{label: string, tone: string}, open: bool} $meta
	 */
	private static function render_settings_section_group(
		string $group,
		string $title,
		array $meta,
		string $open_group,
		callable $renderer
	): void {
		$dom_id  = self::get_section_dom_id( $group );
		$is_open = $open_group === $group;
		?>
		<section class="flavor-agent-settings-section" id="<?php echo esc_attr( $dom_id ); ?>">
			<details class="flavor-agent-settings-section__panel" data-flavor-agent-section="<?php echo esc_attr( $group ); ?>"<?php echo $is_open ? ' open' : ''; ?>>
				<summary class="flavor-agent-settings-section__summary">
					<span class="flavor-agent-settings-section__summary-main">
						<span class="flavor-agent-settings-section__title" role="heading" aria-level="2">
							<?php echo esc_html( $title ); ?>
						</span>
						<span class="flavor-agent-settings-section__summary-text">
							<?php echo esc_html( $meta['summary'] ); ?>
						</span>
					</span>
					<span class="flavor-agent-settings-section__summary-side">
						<?php self::render_section_badges( $meta['badges'] ); ?>
						<?php self::render_badge( $meta['status'], [ 'data-flavor-agent-status-badge' => $group ] ); ?>
						<span class="flavor-agent-settings-section__toggle" aria-hidden="true"></span>
					</span>
				</summary>
				<div class="flavor-agent-settings-section__body">
					<?php call_user_func( $renderer ); ?>
				</div>
			</details>
		</section>
		<?php
	}

	/**
	 * @param array{label: string, tone: string} $badge
	 * @param array<string, string> $attributes
	 */
	private static function render_badge( array $badge, array $attributes = [] ): void {
		if ( '' === $badge['label'] ) {
			return;
		}

		$badge_attributes = array_merge(
			[
				'class' => 'flavor-agent-settings-section__badge flavor-agent-settings-section__badge--' . $badge['tone'],
			],
			$attributes
		);
		?>
		<span<?php self::render_html_attributes( $badge_attributes ); ?>>
			<?php echo esc_html( $badge['label'] ); ?>
		</span>
		<?php
	}

	/**
	 * @param array<int, string> $field_ids
	 */
	private static function render_registered_fields_table( string $section_id, array $field_ids ): void {
		global $wp_settings_fields;

		if ( empty( $wp_settings_fields[ self::PAGE_SLUG ][ $section_id ] ) ) {
			return;
		}
		?>
		<table class="form-table flavor-agent-settings-table" role="presentation">
			<?php
			foreach ( $field_ids as $field_id ) {
				if ( ! isset( $wp_settings_fields[ self::PAGE_SLUG ][ $section_id ][ $field_id ] ) ) {
					continue;
				}

				$field = $wp_settings_fields[ self::PAGE_SLUG ][ $section_id ][ $field_id ];
				$row_class = is_string( $field['args']['class'] ?? null ) ? $field['args']['class'] : '';
				$label_for = is_string( $field['args']['label_for'] ?? null ) ? $field['args']['label_for'] : '';
				?>
				<tr<?php echo '' !== $row_class ? ' class="' . esc_attr( $row_class ) . '"' : ''; ?>>
					<th scope="row">
						<?php if ( '' !== $label_for ) : ?>
							<label for="<?php echo esc_attr( $label_for ); ?>">
								<?php echo esc_html( (string) $field['title'] ); ?>
							</label>
						<?php else : ?>
							<?php echo esc_html( (string) $field['title'] ); ?>
						<?php endif; ?>
					</th>
					<td>
						<?php
						if ( is_callable( $field['callback'] ?? null ) ) {
							call_user_func( $field['callback'], $field['args'] ?? [] );
						}
						?>
					</td>
				</tr>
				<?php
			}
			?>
		</table>
		<?php
	}

	private static function render_registered_section_callback( string $section_id ): void {
		global $wp_settings_sections;

		if ( empty( $wp_settings_sections[ self::PAGE_SLUG ][ $section_id ]['callback'] ) ) {
			return;
		}

		$section_callback = $wp_settings_sections[ self::PAGE_SLUG ][ $section_id ]['callback'];

		if ( is_callable( $section_callback ) ) {
			call_user_func(
				$section_callback,
				$wp_settings_sections[ self::PAGE_SLUG ][ $section_id ]
			);
		}
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function render_chat_provider_group( array $state, array $feedback, string $connectors_url ): void {
		self::render_section_status_blocks( self::GROUP_CHAT, $state, $feedback );
		self::render_registered_section_callback( 'flavor_agent_openai_provider' );
		self::render_registered_fields_table(
			'flavor_agent_openai_provider',
			[
				Provider::OPTION_NAME,
			]
		);

		if ( Provider::is_connector( (string) $state['selected_provider'] ) ) {
			?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: provider label */
					esc_html__( '%s is connector-backed. Configure its shared credentials in Settings > Connectors.', 'flavor-agent' ),
					esc_html( Provider::label( (string) $state['selected_provider'] ) )
				);
				?>
			</p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_attr( self::sanitize_url_value( $connectors_url ) ); ?>">
					<?php echo esc_html__( 'Open Connectors', 'flavor-agent' ); ?>
				</a>
			</p>
			<?php
			return;
		}

		if ( Provider::is_azure( (string) $state['selected_provider'] ) ) {
			self::render_subsection_heading(
				__( 'Direct Azure Settings', 'flavor-agent' )
			);
			self::render_registered_section_callback( 'flavor_agent_azure' );
			self::render_registered_fields_table(
				'flavor_agent_azure',
				[
					'flavor_agent_azure_openai_endpoint',
					'flavor_agent_azure_openai_key',
					'flavor_agent_azure_embedding_deployment',
					'flavor_agent_azure_chat_deployment',
					'flavor_agent_azure_reasoning_effort',
				]
			);
			return;
		}

		self::render_subsection_heading(
			__( 'Direct OpenAI Settings', 'flavor-agent' )
		);
		self::render_registered_section_callback( 'flavor_agent_openai_native' );
		self::render_registered_fields_table(
			'flavor_agent_openai_native',
			[
				'flavor_agent_openai_native_api_key',
				'flavor_agent_openai_native_embedding_model',
				'flavor_agent_openai_native_chat_model',
			]
		);
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function render_pattern_recommendations_group( array $state, array $feedback ): void {
		self::render_section_status_blocks( self::GROUP_PATTERNS, $state, $feedback );
		self::render_registered_section_callback( 'flavor_agent_qdrant' );
		self::render_registered_fields_table(
			'flavor_agent_qdrant',
			[
				'flavor_agent_qdrant_url',
				'flavor_agent_qdrant_key',
			]
		);
		?>
		<details class="flavor-agent-settings-subpanel">
			<summary class="flavor-agent-settings-subpanel__summary">
				<?php echo esc_html__( 'Advanced Ranking', 'flavor-agent' ); ?>
			</summary>
			<div class="flavor-agent-settings-subpanel__body">
				<?php self::render_registered_section_callback( 'flavor_agent_pattern_recommendations' ); ?>
				<?php
				self::render_registered_fields_table(
					'flavor_agent_pattern_recommendations',
					[
						'flavor_agent_pattern_recommendation_threshold',
						'flavor_agent_pattern_max_recommendations',
					]
				);
				?>
			</div>
		</details>
		<?php self::render_sync_panel( $state ); ?>
		<?php
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function render_docs_grounding_group( array $state, array $feedback ): void {
		self::render_section_status_blocks( self::GROUP_DOCS, $state, $feedback );
		self::render_registered_section_callback( 'flavor_agent_cloudflare' );
		self::render_registered_fields_table(
			'flavor_agent_cloudflare',
			[
				'flavor_agent_cloudflare_ai_search_max_results',
			]
		);
		self::render_cloudflare_legacy_override_panel();
		self::render_prewarm_diagnostics_panel( $state );
	}

	private static function render_cloudflare_legacy_override_panel(): void {
		$has_saved_legacy_values = self::has_saved_cloudflare_legacy_values();
		?>
		<details class="flavor-agent-settings-subpanel"<?php echo $has_saved_legacy_values ? ' open' : ''; ?>>
			<summary class="flavor-agent-settings-subpanel__summary">
				<?php echo esc_html__( 'Legacy Cloudflare Override', 'flavor-agent' ); ?>
			</summary>
			<div class="flavor-agent-settings-subpanel__body">
				<p class="description">
					<?php echo esc_html__( 'Optional. Update or clear older Cloudflare AI Search credentials here. Leave all three fields blank to use the managed public endpoint.', 'flavor-agent' ); ?>
				</p>
				<?php if ( $has_saved_legacy_values ) : ?>
					<p class="description">
						<?php echo esc_html__( 'Saved legacy values are present on this site. Clearing them removes the legacy override without requiring a manual database edit.', 'flavor-agent' ); ?>
					</p>
				<?php endif; ?>
				<?php
				self::render_registered_fields_table(
					'flavor_agent_cloudflare',
					[
						'flavor_agent_cloudflare_ai_search_account_id',
						'flavor_agent_cloudflare_ai_search_instance_id',
						'flavor_agent_cloudflare_ai_search_api_token',
					]
				);
				?>
			</div>
		</details>
		<?php
	}

	private static function render_prewarm_diagnostics_panel( array $state ): void {
		if ( empty( $state['docs_configured'] ) ) {
			return;
		}
		?>
		<details class="flavor-agent-settings-subpanel flavor-agent-settings-subpanel--diagnostics">
			<summary class="flavor-agent-settings-subpanel__summary">
				<?php echo esc_html__( 'Diagnostics', 'flavor-agent' ); ?>
			</summary>
			<div class="flavor-agent-settings-subpanel__body">
				<?php self::render_runtime_grounding_diagnostics(); ?>
				<?php self::render_prewarm_diagnostics(); ?>
			</div>
		</details>
		<?php
	}

	private static function render_subsection_heading( string $title, string $description = '' ): void {
		?>
		<div class="flavor-agent-settings-subheading">
			<h3 class="flavor-agent-settings-subheading__title">
				<?php echo esc_html( $title ); ?>
			</h3>
			<?php if ( '' !== $description ) : ?>
				<p class="flavor-agent-settings-subheading__description">
					<?php echo esc_html( $description ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function render_section_status_blocks( string $group, array $state, array $feedback ): void {
		$messages = self::get_section_status_blocks( $group, $state, $feedback );

		foreach ( $messages as $message ) {
			?>
			<div class="flavor-agent-settings-status flavor-agent-settings-status--<?php echo esc_attr( $message['tone'] ); ?>">
				<p><?php echo esc_html( $message['message'] ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * @return array<int, array{tone: string, message: string}>
	 */
	private static function get_section_status_blocks( string $group, array $state, array $feedback ): array {
		$messages       = is_array( $feedback['messages'] ?? null ) ? $feedback['messages'] : [];
		$status_blocks  = [];
		$feedback_block = is_array( $messages[ $group ] ?? null ) ? $messages[ $group ] : null;

		if ( is_array( $feedback_block ) && ! empty( $feedback_block['message'] ) && ! empty( $feedback_block['tone'] ) ) {
			$status_blocks[] = [
				'tone'    => (string) $feedback_block['tone'],
				'message' => (string) $feedback_block['message'],
			];
		}

		if ( self::GROUP_CHAT === $group ) {
			if ( empty( $state['runtime_chat']['configured'] ) ) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => __( 'No chat path is ready yet. Choose a provider and complete the selected provider setup.', 'flavor-agent' ),
				];
			} elseif ( empty( $state['selected_chat']['configured'] ) ) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => sprintf(
						/* translators: %s: provider label */
						__( '%s is selected, but Flavor Agent is currently falling back to another configured chat path until this setup is complete.', 'flavor-agent' ),
						Provider::label( (string) $state['selected_provider'] )
					),
				];
			}

			if (
				Provider::is_native( (string) $state['selected_provider'] ) &&
				'' === Provider::native_effective_api_key()
			) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => __( 'OpenAI Native is selected, but no API key source is available yet. Add a plugin key, Settings > Connectors key, or OPENAI_API_KEY.', 'flavor-agent' ),
				];
			}
		}

		if ( self::GROUP_PATTERNS === $group ) {
			if ( ! empty( $state['qdrant_configured'] ) && empty( $state['runtime_embedding']['configured'] ) ) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => __( 'Qdrant is configured, but pattern recommendations still need an embeddings backend from Chat Provider.', 'flavor-agent' ),
				];
			} elseif ( empty( $state['qdrant_configured'] ) && ! empty( $state['runtime_embedding']['configured'] ) ) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => __( 'Embeddings are ready, but pattern recommendations still need a Qdrant connection before you can sync.', 'flavor-agent' ),
				];
			}
		}

		if (
			self::GROUP_DOCS === $group &&
			! empty( $state['docs_configured'] )
		) {
			$runtime_status = (string) ( $state['runtime_docs_grounding']['status'] ?? 'idle' );
			$last_error     = (string) ( $state['runtime_docs_grounding']['lastErrorMessage'] ?? '' );

			if ( 'retrying' === $runtime_status ) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => '' !== $last_error
						? sprintf(
							/* translators: %s: last runtime grounding error message */
							__( 'Docs grounding is retrying fresh warm requests after a runtime search failure: %s', 'flavor-agent' ),
							$last_error
						)
						: __( 'Docs grounding is retrying fresh warm requests after a runtime search failure.', 'flavor-agent' ),
				];
			} elseif ( 'warming' === $runtime_status ) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => __( 'Docs grounding is warming more specific guidance in the background. Broad cached guidance may still be used until the queue drains.', 'flavor-agent' ),
				];
			} elseif ( in_array( $runtime_status, [ 'degraded', 'error' ], true ) ) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => '' !== $last_error
						? sprintf(
							/* translators: %s: last runtime grounding error message */
							__( 'Docs grounding is on, but live grounding needs attention: %s', 'flavor-agent' ),
							$last_error
						)
						: __( 'Docs grounding is on, but live grounding is currently falling back to broad cached guidance.', 'flavor-agent' ),
				];
			}

			if ( in_array( (string) ( $state['prewarm_state']['status'] ?? '' ), [ 'failed', 'partial' ], true ) ) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => __( 'Docs prewarm did not finish cleanly. Review the diagnostics below for the last prewarm run.', 'flavor-agent' ),
				];
			}
		}

		return $status_blocks;
	}

	private static function render_settings_sections(): void {
		global $wp_settings_sections, $wp_settings_fields;

		if (
			empty( $wp_settings_sections[ self::PAGE_SLUG ] ) ||
			! is_array( $wp_settings_sections[ self::PAGE_SLUG ] )
		) {
			return;
		}

		foreach ( $wp_settings_sections[ self::PAGE_SLUG ] as $section ) {
			$section_id    = is_string( $section['id'] ?? null ) ? $section['id'] : '';
			$section_title = is_string( $section['title'] ?? null ) ? $section['title'] : '';
			$section_meta  = self::get_section_card_meta( $section_id );
			?>
			<section class="flavor-agent-settings-section" id="<?php echo esc_attr( $section_id ); ?>">
				<details class="flavor-agent-settings-section__panel"<?php echo ! empty( $section_meta['open'] ) ? ' open' : ''; ?>>
					<summary class="flavor-agent-settings-section__summary">
						<span class="flavor-agent-settings-section__summary-main">
							<span class="flavor-agent-settings-section__title" role="heading" aria-level="2">
								<?php echo esc_html( $section_title ); ?>
							</span>
							<?php if ( '' !== $section_meta['summary'] ) : ?>
								<span class="flavor-agent-settings-section__summary-text">
									<?php echo esc_html( $section_meta['summary'] ); ?>
								</span>
							<?php endif; ?>
						</span>
						<span class="flavor-agent-settings-section__summary-side">
							<?php self::render_section_badges( $section_meta['badges'] ); ?>
							<span class="flavor-agent-settings-section__toggle" aria-hidden="true"></span>
						</span>
					</summary>
					<div class="flavor-agent-settings-section__body">
						<?php
						if ( ! empty( $section['callback'] ) && is_callable( $section['callback'] ) ) {
							call_user_func( $section['callback'], $section );
						}

						if ( ! empty( $wp_settings_fields[ self::PAGE_SLUG ][ $section_id ] ) ) {
							?>
							<table class="form-table flavor-agent-settings-table" role="presentation">
								<?php do_settings_fields( self::PAGE_SLUG, $section_id ); ?>
							</table>
							<?php
						}
						?>
					</div>
				</details>
			</section>
			<?php
		}
	}

	/**
	 * @return array{summary: string, badges: array<int, array{label: string, tone: string}>, open: bool}
	 */
	private static function get_section_card_meta( string $section_id ): array {
		$selected_provider          = Provider::get();
		$selected_chat_configured   = (bool) Provider::chat_configuration( $selected_provider )['configured'];
		$azure_chat_configured      = (bool) Provider::chat_configuration( Provider::AZURE )['configured'];
		$azure_embedding_configured = (bool) Provider::embedding_configuration( Provider::AZURE )['configured'];
		$native_chat_configured     = (bool) Provider::chat_configuration( Provider::NATIVE )['configured'];
		$native_embed_configured    = (bool) Provider::embedding_configuration( Provider::NATIVE )['configured'];
		$qdrant_configured          = '' !== (string) get_option( 'flavor_agent_qdrant_url', '' )
			&& '' !== (string) get_option( 'flavor_agent_qdrant_key', '' );
		$cloudflare_configured      = AISearchClient::is_configured();

		switch ( $section_id ) {
			case 'flavor_agent_openai_provider':
				return [
					'summary' => __( 'Pick the chat backend Flavor Agent tries first.', 'flavor-agent' ),
					'badges'  => [
						self::make_badge( Provider::label( $selected_provider ), 'accent' ),
						self::make_badge(
							$selected_chat_configured ? __( 'Ready', 'flavor-agent' ) : __( 'Needs setup', 'flavor-agent' ),
							$selected_chat_configured ? 'success' : 'warning'
						),
					],
					'open'    => Provider::is_connector( $selected_provider ),
				];

			case 'flavor_agent_azure':
				return [
					'summary' => __( 'Direct Azure chat and embeddings.', 'flavor-agent' ),
					'badges'  => array_values(
						array_filter(
							[
								Provider::is_azure( $selected_provider ) ? self::make_badge( __( 'Selected', 'flavor-agent' ), 'accent' ) : null,
								self::get_dual_configuration_badge( $azure_chat_configured, $azure_embedding_configured ),
							]
						)
					),
					'open'    => Provider::is_azure( $selected_provider ),
				];

			case 'flavor_agent_openai_native':
				return [
					'summary' => __( 'Direct OpenAI chat and embeddings.', 'flavor-agent' ),
					'badges'  => array_values(
						array_filter(
							[
								Provider::is_native( $selected_provider ) ? self::make_badge( __( 'Selected', 'flavor-agent' ), 'accent' ) : null,
								self::get_dual_configuration_badge( $native_chat_configured, $native_embed_configured ),
							]
						)
					),
					'open'    => Provider::is_native( $selected_provider ),
				];

			case 'flavor_agent_qdrant':
				return [
					'summary' => __( 'Vector store for pattern search.', 'flavor-agent' ),
					'badges'  => [
						self::make_badge(
							$qdrant_configured ? __( 'Connected', 'flavor-agent' ) : __( 'Needs setup', 'flavor-agent' ),
							$qdrant_configured ? 'success' : 'neutral'
						),
					],
					'open'    => false,
				];

			case 'flavor_agent_cloudflare':
				$badges = [
					self::make_badge(
						$cloudflare_configured ? __( 'On', 'flavor-agent' ) : __( 'Off', 'flavor-agent' ),
						$cloudflare_configured ? 'success' : 'neutral'
					),
				];

				if ( $cloudflare_configured ) {
					$prewarm_status = (string) AISearchClient::get_prewarm_state()['status'];
					$badges[]       = self::make_badge(
						sprintf(
							/* translators: %s: prewarm status label */
							__( 'Prewarm: %s', 'flavor-agent' ),
							self::get_prewarm_status_label( $prewarm_status )
						),
						self::get_prewarm_status_tone( $prewarm_status )
					);
				}

				return [
					'summary' => __( 'Ground responses with developer.wordpress.org docs.', 'flavor-agent' ),
					'badges'  => $badges,
					'open'    => false,
				];
		}

		return [
			'summary' => '',
			'badges'  => [],
			'open'    => false,
		];
	}

	/**
	 * @return array{label: string, tone: string}
	 */
	private static function make_badge( string $label, string $tone = 'neutral' ): array {
		return [
			'label' => $label,
			'tone'  => $tone,
		];
	}

	/**
	 * @return array{label: string, tone: string}
	 */
	private static function get_dual_configuration_badge( bool $chat_configured, bool $embedding_configured ): array {
		if ( $chat_configured && $embedding_configured ) {
			return self::make_badge( __( 'Ready', 'flavor-agent' ), 'success' );
		}

		if ( $chat_configured || $embedding_configured ) {
			return self::make_badge( __( 'Partial', 'flavor-agent' ), 'warning' );
		}

		return self::make_badge( __( 'Not configured', 'flavor-agent' ), 'neutral' );
	}

	/**
	 * @param array<int, array{label: string, tone: string}> $badges
	 */
	private static function render_section_badges( array $badges ): void {
		if ( [] === $badges ) {
			return;
		}

		?>
		<span class="flavor-agent-settings-section__badges">
			<?php foreach ( $badges as $badge ) : ?>
				<span class="flavor-agent-settings-section__badge flavor-agent-settings-section__badge--<?php echo esc_attr( $badge['tone'] ); ?>">
					<?php echo esc_html( $badge['label'] ); ?>
				</span>
			<?php endforeach; ?>
		</span>
		<?php
	}

	private static function get_pattern_sync_status_label( string $status ): string {
		return match ( $status ) {
			'indexing'      => __( 'Syncing', 'flavor-agent' ),
			'ready'         => __( 'Ready', 'flavor-agent' ),
			'stale'         => __( 'Refresh needed', 'flavor-agent' ),
			'error'         => __( 'Error', 'flavor-agent' ),
			'uninitialized' => __( 'Not synced', 'flavor-agent' ),
			default         => $status,
		};
	}

	private static function get_pattern_sync_status_tone( string $status ): string {
		return match ( $status ) {
			'indexing' => 'accent',
			'ready'    => 'success',
			'stale'    => 'warning',
			'error'    => 'error',
			default    => 'neutral',
		};
	}

	private static function get_pattern_sync_reason_label( string $reason ): string {
		return match ( $reason ) {
			'embedding_signature_changed' => __( 'Embedding provider, model, or vector size changed.', 'flavor-agent' ),
			'collection_name_changed' => __( 'Pattern index collection naming changed and needs a rebuild.', 'flavor-agent' ),
			'collection_missing' => __( 'Pattern index collection is missing and needs a rebuild.', 'flavor-agent' ),
			'collection_size_mismatch' => __( 'Pattern index collection vector size no longer matches the active embedding configuration.', 'flavor-agent' ),
			'qdrant_url_changed' => __( 'Qdrant endpoint changed.', 'flavor-agent' ),
			'openai_endpoint_changed' => __( 'Embedding endpoint changed.', 'flavor-agent' ),
			'pattern_registry_changed' => __( 'Registered patterns changed.', 'flavor-agent' ),
			default => $reason,
		};
	}

	private static function get_prewarm_status_label( string $status ): string {
		return match ( $status ) {
			'never'     => __( 'Never run', 'flavor-agent' ),
			'ok'        => __( 'OK', 'flavor-agent' ),
			'partial'   => __( 'Partial', 'flavor-agent' ),
			'failed'    => __( 'Failed', 'flavor-agent' ),
			'throttled' => __( 'Throttled', 'flavor-agent' ),
			default     => $status,
		};
	}

	private static function get_prewarm_status_tone( string $status ): string {
		return match ( $status ) {
			'ok'      => 'success',
			'partial' => 'warning',
			'failed'  => 'error',
			default   => 'neutral',
		};
	}

	private static function get_runtime_grounding_status_label( string $status ): string {
		return match ( $status ) {
			'off'      => __( 'Off', 'flavor-agent' ),
			'idle'     => __( 'Idle', 'flavor-agent' ),
			'cache'    => __( 'Cache ready', 'flavor-agent' ),
			'healthy'  => __( 'Healthy', 'flavor-agent' ),
			'warming'  => __( 'Warming', 'flavor-agent' ),
			'retrying' => __( 'Retrying', 'flavor-agent' ),
			'degraded' => __( 'Degraded', 'flavor-agent' ),
			'error'    => __( 'Error', 'flavor-agent' ),
			default    => $status,
		};
	}

	private static function get_runtime_grounding_mode_label( string $mode ): string {
		return match ( $mode ) {
			'cache'      => __( 'cache', 'flavor-agent' ),
			'direct'     => __( 'direct search', 'flavor-agent' ),
			'foreground' => __( 'foreground warm', 'flavor-agent' ),
			'async'      => __( 'async warm', 'flavor-agent' ),
			'prewarm'    => __( 'prewarm', 'flavor-agent' ),
			default      => str_replace( '_', ' ', $mode ),
		};
	}

	private static function get_runtime_grounding_fallback_label( string $fallback_type ): string {
		return match ( $fallback_type ) {
			'exact'   => __( 'exact cache', 'flavor-agent' ),
			'family'  => __( 'family cache', 'flavor-agent' ),
			'entity'  => __( 'entity cache', 'flavor-agent' ),
			'generic' => __( 'generic guidance', 'flavor-agent' ),
			'fresh'   => __( 'fresh live warm', 'flavor-agent' ),
			'none'    => __( 'no guidance', 'flavor-agent' ),
			default   => str_replace( '_', ' ', $fallback_type ),
		};
	}

	/**
	 * @param 'plugin_override'|'env'|'constant'|'connector_database'|'none' $source
	 */
	private static function format_openai_native_key_source_label( string $source ): string {
		return match ( $source ) {
			'plugin_override'    => 'Flavor Agent plugin setting',
			'env'                => 'OPENAI_API_KEY environment variable',
			'constant'           => 'OPENAI_API_KEY PHP constant',
			'connector_database' => 'Settings > Connectors',
			default              => 'none',
		};
	}

	public static function render_azure_section(): void {
		// Guidance now lives in the screen Help panel to keep the page focused on controls.
	}

	public static function render_openai_provider_section(): void {
		// Guidance now lives in the screen Help panel to keep the page focused on controls.
	}

	public static function render_openai_native_section(): void {
		printf(
			'<p class="flavor-agent-settings-inline-meta">%s <strong>%s</strong>.</p>',
			esc_html__( 'Current effective OpenAI key source:', 'flavor-agent' ),
			esc_html( self::format_openai_native_key_source_label( Provider::native_effective_api_key_source() ) )
		);
	}

	public static function render_qdrant_section(): void {
		// Guidance now lives in the screen Help panel to keep the page focused on controls.
	}

	public static function render_pattern_recommendations_section(): void {
		// Guidance now lives in the screen Help panel to keep the page focused on controls.
	}

	public static function render_cloudflare_section(): void {
		// Guidance now lives in the screen Help panel to keep the page focused on controls.
	}

	/**
	 * @return array<int, array{id: string, title: string, content: string, priority: int}>
	 */
	private static function get_contextual_help_tabs(): array {
		return [
			[
				'id'       => 'flavor-agent-overview',
				'title'    => __( 'Overview', 'flavor-agent' ),
				'content'  => implode(
					'',
					[
						'<p>' . esc_html__( 'Flavor Agent has one required setup step and two optional ones.', 'flavor-agent' ) . '</p>',
						'<ol>',
						'<li>' . esc_html__( 'Choose and configure Chat Provider first.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Add Pattern Recommendations only if you want vector-based pattern search.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Add Docs Grounding only if you want developer.wordpress.org context in responses.', 'flavor-agent' ) . '</li>',
						'</ol>',
					]
				),
				'priority' => 10,
			],
			[
				'id'       => 'flavor-agent-configuration',
				'title'    => __( 'Connectors & Overrides', 'flavor-agent' ),
				'content'  => implode(
					'',
					[
						'<p>' . esc_html__( 'Use Settings > Connectors for shared credentials used by connector-backed providers.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Use this screen for direct Azure or OpenAI Native settings, Qdrant, pattern ranking, pattern sync, and docs grounding controls.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Older installs may also have a legacy Cloudflare override here. Leave those fields blank unless you explicitly need that override.', 'flavor-agent' ) . '</p>',
					]
				),
				'priority' => 20,
			],
			[
				'id'       => 'flavor-agent-troubleshooting',
				'title'    => __( 'Troubleshooting', 'flavor-agent' ),
				'content'  => implode(
					'',
					[
						'<p>' . esc_html__( 'If the selected chat provider is incomplete, Flavor Agent can fall back to another configured chat path until you finish setup.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Pattern Sync stays unavailable until both an embeddings backend and Qdrant are configured.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Use Activity Log to review requests, sync runs, and diagnostics after saving changes.', 'flavor-agent' ) . '</p>',
					]
				),
				'priority' => 30,
			],
		];
	}

	private static function get_contextual_help_sidebar(): string {
		$connectors_url = self::sanitize_url_value( admin_url( 'options-connectors.php' ) );
		$activity_url   = self::sanitize_url_value( admin_url( 'options-general.php?page=flavor-agent-activity' ) );

		return implode(
			'',
			[
				'<p><strong>' . esc_html__( 'Quick Links', 'flavor-agent' ) . '</strong></p>',
				sprintf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_attr( $connectors_url ),
					esc_html__( 'Open Connectors', 'flavor-agent' )
				),
				sprintf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_attr( $activity_url ),
					esc_html__( 'Open Activity Log', 'flavor-agent' )
				),
			]
		);
	}

	/**
	 * Generic text/password/url field renderer driven by $args.
	 */
	public static function render_text_field( array $args ): void {
		$option         = (string) ( $args['option'] ?? '' );
		$type           = $args['type'] ?? 'text';
		$placeholder    = $args['placeholder'] ?? '';
		$description    = $args['description'] ?? '';
		$default        = (string) ( $args['default'] ?? '' );
		$value          = (string) get_option( $option, $default );
		$field_id       = (string) ( $args['label_for'] ?? $option );
		$description_id = '' !== $description ? $field_id . '-description' : '';
		$attributes     = [
			'type'        => (string) $type,
			'id'          => $field_id,
			'name'        => $option,
			'value'       => $value,
			'class'       => 'regular-text flavor-agent-settings-field',
			'placeholder' => (string) $placeholder,
		];

		foreach ( [ 'step', 'min', 'max' ] as $attribute ) {
			if ( ! array_key_exists( $attribute, $args ) ) {
				continue;
			}

			$attribute_value = (string) $args[ $attribute ];

			if ( '' === $attribute_value ) {
				continue;
			}

			$attributes[ $attribute ] = $attribute_value;
		}

		if ( isset( $args['inputmode'] ) && '' !== (string) $args['inputmode'] ) {
			$attributes['inputmode'] = (string) $args['inputmode'];
		}

		if ( '' !== $description_id ) {
			$attributes['aria-describedby'] = $description_id;
		}

		$autocomplete = array_key_exists( 'autocomplete', $args )
			? (string) $args['autocomplete']
			: '';

		if ( '' !== $autocomplete ) {
			$attributes['autocomplete'] = $autocomplete;
		}

		?>
		<input<?php self::render_html_attributes( $attributes ); ?> />
		<?php

		if ( $description ) {
			printf(
				'<p class="description" id="%s">%s</p>',
				esc_attr( $description_id ),
				wp_kses_post( $description )
			);
		}
	}

	public static function render_select_field( array $args ): void {
		$option         = (string) ( $args['option'] ?? '' );
		$choices        = is_array( $args['choices'] ?? null ) ? $args['choices'] : [];
		$description    = (string) ( $args['description'] ?? '' );
		$default        = (string) ( $args['default'] ?? '' );
		$field_id       = (string) ( $args['label_for'] ?? $option );
		$description_id = '' !== $description ? $field_id . '-description' : '';
		$attributes     = [
			'id'    => $field_id,
			'name'  => $option,
			'class' => 'flavor-agent-settings-field',
		];
		$value          = (string) get_option(
			$option,
			$option === Provider::OPTION_NAME ? Provider::AZURE : $default
		);
		$autocomplete   = array_key_exists( 'autocomplete', $args )
			? (string) $args['autocomplete']
			: '';

		if ( '' !== $autocomplete ) {
			$attributes['autocomplete'] = $autocomplete;
		}

		if ( '' !== $description_id ) {
			$attributes['aria-describedby'] = $description_id;
		}

		?>
		<select<?php self::render_html_attributes( $attributes ); ?>>
		<?php

		foreach ( $choices as $choice_value => $choice_label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $choice_value ),
				selected( $value, (string) $choice_value, false ),
				esc_html( (string) $choice_label )
			);
		}

		echo '</select>';

		if ( $description ) {
			printf(
				'<p class="description" id="%s">%s</p>',
				esc_attr( $description_id ),
				wp_kses_post( $description )
			);
		}
	}

	public static function sanitize_grounding_result_count( mixed $value ): int {
		$count = max( 1, min( 8, (int) $value ) );
		self::mark_section_changed_by_option( 'flavor_agent_cloudflare_ai_search_max_results', $count );

		return $count;
	}

	public static function sanitize_pattern_recommendation_threshold( mixed $value ): float {
		$threshold = (float) $value;

		$threshold = max( 0.0, min( 1.0, round( $threshold, 2 ) ) );
		self::mark_section_changed_by_option( 'flavor_agent_pattern_recommendation_threshold', $threshold );

		return $threshold;
	}

	public static function sanitize_pattern_max_recommendations( mixed $value ): int {
		$count = max( 1, min( 12, (int) $value ) );
		self::mark_section_changed_by_option( 'flavor_agent_pattern_max_recommendations', $count );

		return $count;
	}

	public static function sanitize_azure_reasoning_effort( mixed $value ): string {
		$effort = sanitize_key( (string) $value );
		$effort = in_array( $effort, [ 'low', 'medium', 'high', 'xhigh' ], true ) ? $effort : 'medium';
		self::mark_section_changed_by_option( 'flavor_agent_azure_reasoning_effort', $effort );

		return $effort;
	}

	public static function sanitize_openai_provider( mixed $value ): string {
		$provider = Provider::normalize_provider( (string) $value );
		self::mark_section_changed_by_option( Provider::OPTION_NAME, $provider );

		return $provider;
	}

	public static function sanitize_azure_openai_endpoint( mixed $value ): string {
		return self::sanitize_azure_url_option(
			$value,
			'flavor_agent_azure_openai_endpoint'
		);
	}

	public static function sanitize_azure_openai_key( mixed $value ): string {
		return self::sanitize_azure_text_option(
			$value,
			'flavor_agent_azure_openai_key'
		);
	}

	public static function sanitize_azure_embedding_deployment( mixed $value ): string {
		return self::sanitize_azure_text_option(
			$value,
			'flavor_agent_azure_embedding_deployment'
		);
	}

	public static function sanitize_azure_chat_deployment( mixed $value ): string {
		return self::sanitize_azure_text_option(
			$value,
			'flavor_agent_azure_chat_deployment'
		);
	}

	public static function sanitize_openai_native_api_key( mixed $value ): string {
		return self::sanitize_openai_native_text_option(
			$value,
			'flavor_agent_openai_native_api_key'
		);
	}

	public static function sanitize_openai_native_embedding_model( mixed $value ): string {
		return self::sanitize_openai_native_text_option(
			$value,
			'flavor_agent_openai_native_embedding_model'
		);
	}

	public static function sanitize_openai_native_chat_model( mixed $value ): string {
		return self::sanitize_openai_native_text_option(
			$value,
			'flavor_agent_openai_native_chat_model'
		);
	}

	public static function sanitize_qdrant_url( mixed $value ): string {
		$sanitized_value = self::sanitize_url_value( $value );
		self::mark_section_changed_by_option( 'flavor_agent_qdrant_url', $sanitized_value );
		$resolved_values = self::resolve_qdrant_submission_values(
			[
				'flavor_agent_qdrant_url' => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_qdrant_validation_error( $resolved_values );

			return (string) get_option( 'flavor_agent_qdrant_url', '' );
		}

		return $resolved_values['flavor_agent_qdrant_url'] ?? $sanitized_value;
	}

	public static function sanitize_qdrant_key( mixed $value ): string {
		$sanitized_value = sanitize_text_field( $value );
		self::mark_section_changed_by_option( 'flavor_agent_qdrant_key', $sanitized_value );
		$resolved_values = self::resolve_qdrant_submission_values(
			[
				'flavor_agent_qdrant_key' => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_qdrant_validation_error( $resolved_values );

			return (string) get_option( 'flavor_agent_qdrant_key', '' );
		}

		return $resolved_values['flavor_agent_qdrant_key'] ?? $sanitized_value;
	}

	public static function sanitize_cloudflare_account_id( mixed $value ): string {
		return self::sanitize_cloudflare_text_option(
			$value,
			'flavor_agent_cloudflare_ai_search_account_id'
		);
	}

	public static function sanitize_cloudflare_instance_id( mixed $value ): string {
		return self::sanitize_cloudflare_text_option(
			$value,
			'flavor_agent_cloudflare_ai_search_instance_id'
		);
	}

	public static function sanitize_cloudflare_api_token( mixed $value ): string {
		return self::sanitize_cloudflare_text_option(
			$value,
			'flavor_agent_cloudflare_ai_search_api_token'
		);
	}

	private static function sanitize_azure_url_option( mixed $value, string $option_name ): string {
		$sanitized_value = self::sanitize_url_value( $value );
		self::mark_section_changed_by_option( $option_name, $sanitized_value );
		$resolved_values = self::resolve_azure_submission_values(
			[
				$option_name => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_azure_validation_error( $resolved_values );

			return (string) get_option( $option_name, '' );
		}

		return $resolved_values[ $option_name ] ?? $sanitized_value;
	}

	private static function sanitize_azure_text_option( mixed $value, string $option_name ): string {
		$sanitized_value = sanitize_text_field( $value );
		self::mark_section_changed_by_option( $option_name, $sanitized_value );
		$resolved_values = self::resolve_azure_submission_values(
			[
				$option_name => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_azure_validation_error( $resolved_values );

			return (string) get_option( $option_name, '' );
		}

		return $resolved_values[ $option_name ] ?? $sanitized_value;
	}

	private static function sanitize_openai_native_text_option( mixed $value, string $option_name ): string {
		$sanitized_value = sanitize_text_field( $value );
		self::mark_section_changed_by_option( $option_name, $sanitized_value );
		$resolved_values = self::resolve_openai_native_submission_values(
			[
				$option_name => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_openai_native_validation_error( $resolved_values );

			return (string) get_option( $option_name, '' );
		}

		return $resolved_values[ $option_name ] ?? $sanitized_value;
	}

	private static function sanitize_cloudflare_text_option( mixed $value, string $option_name ): string {
		$sanitized_value = sanitize_text_field( $value );
		self::mark_section_changed_by_option( $option_name, $sanitized_value );
		$resolved_values = self::resolve_cloudflare_submission_values(
			[
				$option_name => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_cloudflare_validation_error( $resolved_values );

			return (string) get_option( $option_name, '' );
		}

		return $resolved_values[ $option_name ] ?? $sanitized_value;
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array<string, string>|\WP_Error
	 */
	private static function resolve_azure_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_azure_values();
		$values         = [
			'flavor_agent_azure_openai_endpoint'      => self::read_posted_url_value(
				'flavor_agent_azure_openai_endpoint',
				$current_values['flavor_agent_azure_openai_endpoint']
			),
			'flavor_agent_azure_openai_key'           => self::read_posted_text_value(
				'flavor_agent_azure_openai_key',
				$current_values['flavor_agent_azure_openai_key']
			),
			'flavor_agent_azure_embedding_deployment' => self::read_posted_text_value(
				'flavor_agent_azure_embedding_deployment',
				$current_values['flavor_agent_azure_embedding_deployment']
			),
			'flavor_agent_azure_chat_deployment'      => self::read_posted_text_value(
				'flavor_agent_azure_chat_deployment',
				$current_values['flavor_agent_azure_chat_deployment']
			),
		];

		foreach ( $overrides as $option_name => $override_value ) {
			$values[ $option_name ] = 'flavor_agent_azure_openai_endpoint' === $option_name
				? self::sanitize_url_value( $override_value )
				: sanitize_text_field( $override_value );
		}

		if ( ! self::should_validate_provider_submission() ) {
			return $values;
		}

		if ( self::get_submitted_openai_provider() !== Provider::AZURE ) {
			return $values;
		}

		if (
			'' === $values['flavor_agent_azure_openai_endpoint'] ||
			'' === $values['flavor_agent_azure_openai_key'] ||
			'' === $values['flavor_agent_azure_embedding_deployment'] ||
			'' === $values['flavor_agent_azure_chat_deployment']
		) {
			return $values;
		}

		if ( ! self::values_require_validation( $values, $current_values ) ) {
			return $values;
		}

		$fingerprint = self::build_validation_fingerprint( $values );

		if (
			is_array( self::$azure_validation_state ) &&
			( self::$azure_validation_state['fingerprint'] ?? '' ) === $fingerprint
		) {
			return self::$azure_validation_state['error'] instanceof \WP_Error
				? self::$azure_validation_state['error']
				: self::$azure_validation_state['values'];
		}

		$validation = EmbeddingClient::validate_configuration(
			$values['flavor_agent_azure_openai_endpoint'],
			$values['flavor_agent_azure_openai_key'],
			$values['flavor_agent_azure_embedding_deployment'],
			Provider::AZURE
		);

		if ( ! is_wp_error( $validation ) ) {
			$validation = ResponsesClient::validate_configuration(
				$values['flavor_agent_azure_openai_endpoint'],
				$values['flavor_agent_azure_openai_key'],
				$values['flavor_agent_azure_chat_deployment'],
				Provider::AZURE
			);
		}

		self::$azure_validation_state = [
			'fingerprint' => $fingerprint,
			'values'      => $values,
			'error'       => is_wp_error( $validation ) ? $validation : null,
		];

		return is_wp_error( $validation ) ? $validation : $values;
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array<string, string>|\WP_Error
	 */
	private static function resolve_openai_native_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_openai_native_values();
		$values         = [
			'flavor_agent_openai_native_api_key'         => self::read_posted_text_value(
				'flavor_agent_openai_native_api_key',
				$current_values['flavor_agent_openai_native_api_key']
			),
			'flavor_agent_openai_native_embedding_model' => self::read_posted_text_value(
				'flavor_agent_openai_native_embedding_model',
				$current_values['flavor_agent_openai_native_embedding_model']
			),
			'flavor_agent_openai_native_chat_model'      => self::read_posted_text_value(
				'flavor_agent_openai_native_chat_model',
				$current_values['flavor_agent_openai_native_chat_model']
			),
		];

		foreach ( $overrides as $option_name => $override_value ) {
			$values[ $option_name ] = sanitize_text_field( $override_value );
		}

		$current_effective_api_key = Provider::native_effective_api_key( $current_values );
		$effective_api_key         = Provider::native_effective_api_key( $values );

		if ( ! self::should_validate_provider_submission() ) {
			return $values;
		}

		if ( self::get_submitted_openai_provider() !== Provider::NATIVE ) {
			return $values;
		}

		if (
			'' === $effective_api_key ||
			'' === $values['flavor_agent_openai_native_embedding_model'] ||
			'' === $values['flavor_agent_openai_native_chat_model']
		) {
			return $values;
		}

		$comparison_values                                       = $values;
		$comparison_values['flavor_agent_openai_native_api_key'] = $effective_api_key;

		$current_comparison_values                                       = $current_values;
		$current_comparison_values['flavor_agent_openai_native_api_key'] = $current_effective_api_key;

		if ( ! self::values_require_validation( $comparison_values, $current_comparison_values ) ) {
			return $values;
		}

		$fingerprint = self::build_validation_fingerprint( $comparison_values );

		if (
			is_array( self::$native_openai_validation_state ) &&
			( self::$native_openai_validation_state['fingerprint'] ?? '' ) === $fingerprint
		) {
			return self::$native_openai_validation_state['error'] instanceof \WP_Error
				? self::$native_openai_validation_state['error']
				: self::$native_openai_validation_state['values'];
		}

		$validation = EmbeddingClient::validate_configuration(
			null,
			$effective_api_key,
			$values['flavor_agent_openai_native_embedding_model'],
			Provider::NATIVE
		);

		if ( ! is_wp_error( $validation ) ) {
			$validation = ResponsesClient::validate_configuration(
				null,
				$effective_api_key,
				$values['flavor_agent_openai_native_chat_model'],
				Provider::NATIVE
			);
		}

		self::$native_openai_validation_state = [
			'fingerprint' => $fingerprint,
			'values'      => $values,
			'error'       => is_wp_error( $validation ) ? $validation : null,
		];

		return is_wp_error( $validation ) ? $validation : $values;
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array<string, string>|\WP_Error
	 */
	private static function resolve_qdrant_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_qdrant_values();
		$values         = [
			'flavor_agent_qdrant_url' => self::read_posted_url_value(
				'flavor_agent_qdrant_url',
				$current_values['flavor_agent_qdrant_url']
			),
			'flavor_agent_qdrant_key' => self::read_posted_text_value(
				'flavor_agent_qdrant_key',
				$current_values['flavor_agent_qdrant_key']
			),
		];

		foreach ( $overrides as $option_name => $override_value ) {
			$values[ $option_name ] = 'flavor_agent_qdrant_url' === $option_name
				? self::sanitize_url_value( $override_value )
				: sanitize_text_field( $override_value );
		}

		if ( ! self::should_validate_provider_submission() ) {
			return $values;
		}

		if (
			'' === $values['flavor_agent_qdrant_url'] ||
			'' === $values['flavor_agent_qdrant_key']
		) {
			return $values;
		}

		if ( ! self::values_require_validation( $values, $current_values ) ) {
			return $values;
		}

		$fingerprint = self::build_validation_fingerprint( $values );

		if (
			is_array( self::$qdrant_validation_state ) &&
			( self::$qdrant_validation_state['fingerprint'] ?? '' ) === $fingerprint
		) {
			return self::$qdrant_validation_state['error'] instanceof \WP_Error
				? self::$qdrant_validation_state['error']
				: self::$qdrant_validation_state['values'];
		}

		$validation = QdrantClient::validate_configuration(
			$values['flavor_agent_qdrant_url'],
			$values['flavor_agent_qdrant_key']
		);

		self::$qdrant_validation_state = [
			'fingerprint' => $fingerprint,
			'values'      => $values,
			'error'       => is_wp_error( $validation ) ? $validation : null,
		];

		return is_wp_error( $validation ) ? $validation : $values;
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array<string, string>|\WP_Error
	 */
	private static function resolve_cloudflare_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_cloudflare_values();
		$values         = [
			'flavor_agent_cloudflare_ai_search_account_id' => self::read_posted_cloudflare_value(
				'flavor_agent_cloudflare_ai_search_account_id',
				$current_values['flavor_agent_cloudflare_ai_search_account_id']
			),
			'flavor_agent_cloudflare_ai_search_instance_id' => self::read_posted_cloudflare_value(
				'flavor_agent_cloudflare_ai_search_instance_id',
				$current_values['flavor_agent_cloudflare_ai_search_instance_id']
			),
			'flavor_agent_cloudflare_ai_search_api_token'  => self::read_posted_cloudflare_value(
				'flavor_agent_cloudflare_ai_search_api_token',
				$current_values['flavor_agent_cloudflare_ai_search_api_token']
			),
		];

		foreach ( $overrides as $option_name => $override_value ) {
			$values[ $option_name ] = sanitize_text_field( $override_value );
		}

		if ( ! self::should_validate_provider_submission() ) {
			return $values;
		}

		if (
			$values['flavor_agent_cloudflare_ai_search_account_id'] === '' ||
			$values['flavor_agent_cloudflare_ai_search_instance_id'] === '' ||
			$values['flavor_agent_cloudflare_ai_search_api_token'] === ''
		) {
			return $values;
		}

		if ( ! self::values_require_validation( $values, $current_values ) ) {
			return $values;
		}

		$fingerprint = self::build_validation_fingerprint( $values );

		if (
			is_array( self::$cloudflare_validation_state ) &&
			( self::$cloudflare_validation_state['fingerprint'] ?? '' ) === $fingerprint
		) {
			return self::$cloudflare_validation_state['error'] instanceof \WP_Error
				? self::$cloudflare_validation_state['error']
				: self::$cloudflare_validation_state['values'];
		}

		$validation = AISearchClient::validate_configuration(
			$values['flavor_agent_cloudflare_ai_search_account_id'],
			$values['flavor_agent_cloudflare_ai_search_instance_id'],
			$values['flavor_agent_cloudflare_ai_search_api_token']
		);

		self::$cloudflare_validation_state = [
			'fingerprint' => $fingerprint,
			'values'      => $values,
			'error'       => is_wp_error( $validation ) ? $validation : null,
		];

		if ( ! is_wp_error( $validation ) ) {
			AISearchClient::schedule_prewarm(
				$values['flavor_agent_cloudflare_ai_search_account_id'],
				$values['flavor_agent_cloudflare_ai_search_instance_id'],
				$values['flavor_agent_cloudflare_ai_search_api_token']
			);
		}

		return is_wp_error( $validation ) ? $validation : $values;
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_current_azure_values(): array {
		return [
			'flavor_agent_azure_openai_endpoint'      => self::sanitize_url_value(
				(string) get_option( 'flavor_agent_azure_openai_endpoint', '' )
			),
			'flavor_agent_azure_openai_key'           => sanitize_text_field(
				(string) get_option( 'flavor_agent_azure_openai_key', '' )
			),
			'flavor_agent_azure_embedding_deployment' => sanitize_text_field(
				(string) get_option( 'flavor_agent_azure_embedding_deployment', '' )
			),
			'flavor_agent_azure_chat_deployment'      => sanitize_text_field(
				(string) get_option( 'flavor_agent_azure_chat_deployment', '' )
			),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_current_openai_native_values(): array {
		return [
			'flavor_agent_openai_native_api_key'         => sanitize_text_field(
				(string) get_option( 'flavor_agent_openai_native_api_key', '' )
			),
			'flavor_agent_openai_native_embedding_model' => sanitize_text_field(
				(string) get_option( 'flavor_agent_openai_native_embedding_model', '' )
			),
			'flavor_agent_openai_native_chat_model'      => sanitize_text_field(
				(string) get_option( 'flavor_agent_openai_native_chat_model', '' )
			),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_current_qdrant_values(): array {
		return [
			'flavor_agent_qdrant_url' => self::sanitize_url_value(
				(string) get_option( 'flavor_agent_qdrant_url', '' )
			),
			'flavor_agent_qdrant_key' => sanitize_text_field(
				(string) get_option( 'flavor_agent_qdrant_key', '' )
			),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_current_cloudflare_values(): array {
		return [
			'flavor_agent_cloudflare_ai_search_account_id' => sanitize_text_field(
				(string) get_option( 'flavor_agent_cloudflare_ai_search_account_id', '' )
			),
			'flavor_agent_cloudflare_ai_search_instance_id' => sanitize_text_field(
				(string) get_option( 'flavor_agent_cloudflare_ai_search_instance_id', '' )
			),
			'flavor_agent_cloudflare_ai_search_api_token'  => sanitize_text_field(
				(string) get_option( 'flavor_agent_cloudflare_ai_search_api_token', '' )
			),
		];
	}

	private static function has_saved_cloudflare_legacy_values(): bool {
		foreach ( self::get_current_cloudflare_values() as $value ) {
			if ( '' !== trim( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, string> $values
	 * @param array<string, string> $current_values
	 */
	private static function values_require_validation( array $values, array $current_values ): bool {
		foreach ( $current_values as $option_name => $current_value ) {
			if ( ( $values[ $option_name ] ?? '' ) !== $current_value ) {
				return true;
			}
		}

		return false;
	}

	private static function should_validate_provider_submission(): bool {
		if ( ! self::has_valid_settings_submission_nonce() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated above; the value is unslashed and sanitized immediately below.
		$option_page = $_POST['option_page'] ?? null;

		if ( ! is_string( $option_page ) ) {
			return false;
		}

		$option_page = wp_unslash( $option_page );

		return sanitize_text_field( $option_page ) === self::OPTION_GROUP;
	}

	private static function get_submitted_openai_provider(): string {
		if ( ! self::has_valid_settings_submission_nonce() ) {
			return Provider::normalize_provider( get_option( Provider::OPTION_NAME, Provider::AZURE ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated above; the value is unslashed before normalization.
		$provider = $_POST[ Provider::OPTION_NAME ] ?? get_option( Provider::OPTION_NAME, Provider::AZURE );

		if ( is_string( $provider ) ) {
			$provider = wp_unslash( $provider );
		}

		return Provider::normalize_provider( is_string( $provider ) ? $provider : Provider::AZURE );
	}

	private static function has_valid_settings_submission_nonce(): bool {
		if ( defined( 'FLAVOR_AGENT_TESTS_RUNNING' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is unslashed and sanitized before verification.
		$nonce = $_POST['_wpnonce'] ?? null;

		if ( ! is_string( $nonce ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $nonce ) );

		return (bool) wp_verify_nonce( $nonce, self::OPTION_GROUP . '-options' );
	}

	private static function read_posted_cloudflare_value( string $option_name, string $fallback ): string {
		return self::read_posted_text_value( $option_name, $fallback );
	}

	private static function read_posted_text_value( string $option_name, string $fallback ): string {
		if ( ! self::has_valid_settings_submission_nonce() ) {
			return sanitize_text_field( $fallback );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated above; the value is unslashed and sanitized immediately below.
		$value = $_POST[ $option_name ] ?? null;

		if ( ! is_string( $value ) ) {
			return sanitize_text_field( $fallback );
		}

		$value = wp_unslash( $value );

		return sanitize_text_field( $value );
	}

	private static function read_posted_url_value( string $option_name, string $fallback ): string {
		if ( ! self::has_valid_settings_submission_nonce() ) {
			return self::sanitize_url_value( $fallback );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated above; the value is unslashed and sanitized immediately below.
		$value = $_POST[ $option_name ] ?? null;

		if ( ! is_string( $value ) ) {
			return self::sanitize_url_value( $fallback );
		}

		$value = wp_unslash( $value );

		return self::sanitize_url_value( $value );
	}

	/**
	 * @param array<string, string> $values
	 */
	private static function build_validation_fingerprint( array $values ): string {
		$fingerprint_payload = wp_json_encode( $values );

		if ( ! is_string( $fingerprint_payload ) || '' === $fingerprint_payload ) {
			$fingerprint_payload = implode( '|', $values );
		}

		return md5( $fingerprint_payload );
	}

	private static function has_settings_updated_query_flag(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only query arg used only to render post-save notices.
		$settings_updated = wp_unslash( $_GET['settings-updated'] ?? '' );

		if ( ! is_string( $settings_updated ) ) {
			return false;
		}

		return '' !== sanitize_text_field( $settings_updated );
	}

	private static function sanitize_url_value( mixed $value ): string {
		return (string) sanitize_url( (string) $value );
	}

	/**
	 * @param array<string, string> $attributes
	 */
	private static function render_html_attributes( array $attributes ): void {
		foreach ( $attributes as $attribute_name => $attribute_value ) {
			printf(
				' %s="%s"',
				esc_attr( $attribute_name ),
				esc_attr( $attribute_value )
			);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function get_settings_page_feedback(): array {
		$storage_key = self::get_settings_page_feedback_storage_key(
			self::get_settings_page_feedback_request_key_from_post()
		);

		if ( '' === $storage_key ) {
			return self::get_default_settings_page_feedback();
		}

		$feedback = get_transient( $storage_key );

		if ( is_array( $feedback ) ) {
			return array_merge(
				self::get_default_settings_page_feedback(),
				$feedback
			);
		}

		return self::get_default_settings_page_feedback();
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function persist_settings_page_feedback( array $feedback ): void {
		$storage_key = self::get_settings_page_feedback_storage_key(
			self::get_settings_page_feedback_request_key_from_post()
		);

		if ( '' === $storage_key ) {
			return;
		}

		set_transient( $storage_key, $feedback, 300 );
	}

	private static function mark_section_changed_by_option( string $option_name, mixed $next_value ): void {
		if ( ! self::should_validate_provider_submission() ) {
			return;
		}

		$current_value = get_option( $option_name, '' );

		if ( (string) $current_value === (string) $next_value ) {
			return;
		}

		$feedback = self::get_settings_page_feedback();

		foreach ( self::get_feedback_groups_for_option( $option_name ) as $group ) {
			$feedback['changed_sections'][ $group ] = true;
		}

		self::persist_settings_page_feedback( $feedback );
	}

	/**
	 * @return array<int, string>
	 */
	private static function get_feedback_groups_for_option( string $option_name ): array {
		return match ( $option_name ) {
			Provider::OPTION_NAME,
			'flavor_agent_azure_chat_deployment',
			'flavor_agent_azure_reasoning_effort',
			'flavor_agent_openai_native_chat_model' => [ self::GROUP_CHAT ],
			'flavor_agent_pattern_recommendation_threshold',
			'flavor_agent_pattern_max_recommendations',
			'flavor_agent_qdrant_url',
			'flavor_agent_qdrant_key',
			'flavor_agent_azure_embedding_deployment',
			'flavor_agent_openai_native_embedding_model' => [ self::GROUP_PATTERNS ],
			'flavor_agent_cloudflare_ai_search_account_id',
			'flavor_agent_cloudflare_ai_search_instance_id',
			'flavor_agent_cloudflare_ai_search_api_token',
			'flavor_agent_cloudflare_ai_search_max_results' => [ self::GROUP_DOCS ],
			'flavor_agent_azure_openai_endpoint',
			'flavor_agent_azure_openai_key',
			'flavor_agent_openai_native_api_key' => [ self::GROUP_CHAT, self::GROUP_PATTERNS ],
			default => [],
		};
	}

	private static function record_section_feedback_message(
		string $section,
		string $tone,
		string $message,
		bool $focus = false
	): void {
		$feedback = self::get_settings_page_feedback();
		$feedback['messages'][ $section ] = [
			'tone'    => $tone,
			'message' => $message,
		];

		if ( $focus ) {
			$feedback['focus_section'] = $section;
		}

		self::persist_settings_page_feedback( $feedback );
	}

	private static function report_azure_validation_error( \WP_Error $error ): void {
		if ( self::$azure_validation_error_reported ) {
			return;
		}

		add_settings_error(
			self::OPTION_GROUP,
			'flavor_agent_azure_validation',
			$error->get_error_message(),
			'error'
		);

		self::record_section_feedback_message(
			self::GROUP_CHAT,
			'error',
			__( 'We kept your previous Azure settings because validation failed.', 'flavor-agent' ),
			true
		);
		self::$azure_validation_error_reported = true;
	}

	private static function report_openai_native_validation_error( \WP_Error $error ): void {
		if ( self::$native_openai_validation_error_reported ) {
			return;
		}

		add_settings_error(
			self::OPTION_GROUP,
			'flavor_agent_openai_native_validation',
			$error->get_error_message(),
			'error'
		);

		self::record_section_feedback_message(
			self::GROUP_CHAT,
			'error',
			__( 'We kept your previous OpenAI Native settings because validation failed.', 'flavor-agent' ),
			true
		);
		self::$native_openai_validation_error_reported = true;
	}

	private static function report_qdrant_validation_error( \WP_Error $error ): void {
		if ( self::$qdrant_validation_error_reported ) {
			return;
		}

		add_settings_error(
			self::OPTION_GROUP,
			'flavor_agent_qdrant_validation',
			$error->get_error_message(),
			'error'
		);

		self::record_section_feedback_message(
			self::GROUP_PATTERNS,
			'error',
			__( 'We kept your previous Qdrant settings because validation failed.', 'flavor-agent' ),
			true
		);
		self::$qdrant_validation_error_reported = true;
	}

	private static function report_cloudflare_validation_error( \WP_Error $error ): void {
		if ( self::$cloudflare_validation_error_reported ) {
			return;
		}

		add_settings_error(
			self::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_validation',
			$error->get_error_message(),
			'error'
		);

		self::record_section_feedback_message(
			self::GROUP_DOCS,
			'error',
			__( 'We kept your previous docs grounding settings because validation failed.', 'flavor-agent' ),
			true
		);
		self::$cloudflare_validation_error_reported = true;
	}

	// ------------------------------------------------------------------
	// Prewarm diagnostics panel
	// ------------------------------------------------------------------

	private static function render_runtime_grounding_diagnostics(): void {
		if ( ! AISearchClient::is_configured() ) {
			return;
		}

		$state  = AISearchClient::get_runtime_state();
		$label  = self::get_runtime_grounding_status_label( (string) $state['status'] );
		$served = '';

		if ( '' !== (string) $state['lastServedAt'] ) {
			$served = sprintf(
				/* translators: 1: fallback type, 2: served mode, 3: timestamp */
				__( 'Last served guidance: %1$s via %2$s at %3$s UTC', 'flavor-agent' ),
				self::get_runtime_grounding_fallback_label( (string) $state['lastFallbackType'] ),
				self::get_runtime_grounding_mode_label( (string) $state['lastServedMode'] ),
				(string) $state['lastServedAt']
			);
		}

		$queue_summary = '';

		if ( (int) $state['queueDepth'] > 0 ) {
			$queue_summary = sprintf(
				/* translators: 1: queue depth, 2: next attempt timestamp */
				__( 'Warm queue: %1$d pending. Next attempt: %2$s UTC', 'flavor-agent' ),
				absint( (int) $state['queueDepth'] ),
				'' !== (string) $state['nextQueueAttemptAt']
					? (string) $state['nextQueueAttemptAt']
					: __( 'pending', 'flavor-agent' )
			);
		}

		$success_summary = '';

		if ( '' !== (string) $state['lastTrustedSuccessAt'] ) {
			$success_summary = sprintf(
				/* translators: 1: timestamp, 2: runtime mode label */
				__( 'Last trusted success: %1$s UTC via %2$s', 'flavor-agent' ),
				(string) $state['lastTrustedSuccessAt'],
				self::get_runtime_grounding_mode_label( (string) $state['lastTrustedSuccessMode'] )
			);
		}
		?>
		<div class="flavor-agent-settings-diagnostic">
			<div class="flavor-agent-settings-diagnostic__header">
				<p class="flavor-agent-settings-diagnostic__title">
					<?php echo esc_html__( 'Runtime Grounding', 'flavor-agent' ); ?>
				</p>
				<p class="flavor-agent-settings-diagnostic__status">
					<?php echo esc_html( $label ); ?>
				</p>
			</div>
			<?php if ( '' !== $served ) : ?>
				<p class="flavor-agent-settings-diagnostic__meta">
					<?php echo esc_html( $served ); ?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $queue_summary ) : ?>
				<p class="flavor-agent-settings-diagnostic__meta">
					<?php echo esc_html( $queue_summary ); ?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $success_summary ) : ?>
				<p class="flavor-agent-settings-diagnostic__meta">
					<?php echo esc_html( $success_summary ); ?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== (string) $state['lastErrorMessage'] ) : ?>
				<p class="flavor-agent-settings-diagnostic__meta">
					<?php
					printf(
						/* translators: 1: runtime mode label, 2: error message */
						esc_html__( 'Last error (%1$s): %2$s', 'flavor-agent' ),
						esc_html( self::get_runtime_grounding_mode_label( (string) $state['lastErrorMode'] ) ),
						esc_html( (string) $state['lastErrorMessage'] )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_prewarm_diagnostics(): void {
		if ( ! AISearchClient::is_configured() ) {
			return;
		}

		$state = AISearchClient::get_prewarm_state();
		$label = match ( $state['status'] ) {
			'never'     => __( 'Never run', 'flavor-agent' ),
			'ok'        => __( 'OK', 'flavor-agent' ),
			'partial'   => __( 'Partial (some entities failed)', 'flavor-agent' ),
			'failed'    => __( 'Failed', 'flavor-agent' ),
			'throttled' => __( 'Throttled (skipped, too recent)', 'flavor-agent' ),
			default     => (string) $state['status'],
		};
		?>
		<div class="flavor-agent-settings-diagnostic">
			<div class="flavor-agent-settings-diagnostic__header">
				<p class="flavor-agent-settings-diagnostic__title">
					<?php echo esc_html__( 'Docs Prewarm', 'flavor-agent' ); ?>
				</p>
				<p class="flavor-agent-settings-diagnostic__status">
					<?php echo esc_html( $label ); ?>
				</p>
			</div>
			<?php if ( $state['timestamp'] !== '' ) : ?>
				<p class="flavor-agent-settings-diagnostic__meta">
					<?php
					printf(
						/* translators: %s: prewarm timestamp */
						esc_html__( 'Last prewarm run: %s UTC', 'flavor-agent' ),
						esc_html( $state['timestamp'] )
					);
					?>
				</p>
			<?php endif; ?>
				<?php if ( $state['warmed'] > 0 || $state['failed'] > 0 ) : ?>
					<p class="flavor-agent-settings-diagnostic__meta">
						<?php
						printf(
							/* translators: 1: warmed count, 2: failed count */
							esc_html__( '%1$d warmed, %2$d failed', 'flavor-agent' ),
							absint( $state['warmed'] ),
							absint( $state['failed'] )
						);
						?>
					</p>
				<?php endif; ?>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Sync status panel
	// ------------------------------------------------------------------

	private static function render_sync_panel( array $page_state ): void {
		$state                 = is_array( $page_state['pattern_state'] ?? null ) ? $page_state['pattern_state'] : PatternIndex::get_runtime_state();
		$has_prerequisites     = ! empty( $page_state['patterns_ready'] );
		$status_label          = $has_prerequisites
			? self::get_pattern_sync_status_label( (string) $state['status'] )
			: __( 'Needs setup', 'flavor-agent' );
		$status_tone           = ! $has_prerequisites
			? 'warning'
			: ( ! empty( $state['last_error'] ) ? 'error' : self::get_pattern_sync_status_tone( (string) $state['status'] ) );
		$last_synced_label     = $state['last_synced_at'] ? (string) $state['last_synced_at'] : __( 'Not synced yet', 'flavor-agent' );
		$stale_reason_label    = ! empty( $state['stale_reason'] )
			? self::get_pattern_sync_reason_label( (string) $state['stale_reason'] )
			: '';
		$collection_name       = $state['qdrant_collection']
			? (string) $state['qdrant_collection']
			: QdrantClient::get_collection_name(
				[
					'signature_hash' => (string) ( $state['embedding_signature'] ?? '' ),
				]
			);
		$prerequisite_message  = self::get_pattern_sync_prerequisite_message( $page_state );
		$sync_summary_sentence = self::get_pattern_sync_status_sentence( $page_state );
		?>
		<details class="flavor-agent-settings-subpanel flavor-agent-settings-subpanel--sync" data-flavor-agent-sync-panel<?php echo self::should_open_sync_panel( $page_state ) ? ' open' : ''; ?>>
			<summary class="flavor-agent-settings-subpanel__summary">
				<span><?php echo esc_html__( 'Sync Pattern Catalog', 'flavor-agent' ); ?></span>
				<?php self::render_badge( self::make_badge( $status_label, $status_tone ), [ 'data-pattern-status-badge' => 'panel' ] ); ?>
			</summary>
			<div
				class="flavor-agent-settings-subpanel__body flavor-agent-sync-panel"
				data-pattern-prerequisites-ready="<?php echo $has_prerequisites ? '1' : '0'; ?>"
				data-pattern-prerequisite-message="<?php echo esc_attr( $prerequisite_message ); ?>"
			>
				<p id="flavor-agent-sync-summary" class="flavor-agent-sync-panel__summary">
					<?php echo esc_html( $sync_summary_sentence ); ?>
				</p>
				<?php if ( '' !== $prerequisite_message ) : ?>
					<p class="flavor-agent-sync-panel__prerequisites" data-pattern-prerequisite-copy>
						<?php echo esc_html( $prerequisite_message ); ?>
					</p>
				<?php endif; ?>
				<div class="flavor-agent-sync-panel__metrics">
					<?php
					self::render_sync_metric(
						__( 'Status', 'flavor-agent' ),
						$status_label,
						false,
						'status'
					);
					self::render_sync_metric(
						__( 'Indexed Patterns', 'flavor-agent' ),
						(string) (int) $state['indexed_count'],
						false,
						'indexed_count'
					);
					self::render_sync_metric(
						__( 'Last Synced', 'flavor-agent' ),
						$last_synced_label,
						false,
						'last_synced_at'
					);
					self::render_sync_metric(
						__( 'Refresh Needed Because', 'flavor-agent' ),
						$stale_reason_label,
						false,
						'stale_reason',
						'' !== $stale_reason_label
					);
					self::render_sync_metric(
						__( 'Last Error', 'flavor-agent' ),
						(string) ( $state['last_error'] ?? '' ),
						true,
						'last_error',
						! empty( $state['last_error'] )
					);
					?>
				</div>
				<details class="flavor-agent-sync-panel__technical">
					<summary class="flavor-agent-sync-panel__technical-summary">
						<?php echo esc_html__( 'Technical Details', 'flavor-agent' ); ?>
					</summary>
					<div class="flavor-agent-sync-panel__technical-body">
						<?php
						self::render_sync_metric(
							__( 'Qdrant Collection', 'flavor-agent' ),
							$collection_name,
							false,
							'qdrant_collection'
						);
						self::render_sync_metric(
							__( 'Embedding Dimension', 'flavor-agent' ),
							(string) max( 0, (int) ( $state['embedding_dimension'] ?? 0 ) ),
							false,
							'embedding_dimension'
						);
						?>
					</div>
				</details>
				<div class="flavor-agent-sync-panel__actions">
					<button
						type="button"
						id="flavor-agent-sync-button"
						class="button button-secondary"
						<?php echo $has_prerequisites ? '' : 'disabled'; ?>
					>
						<?php echo esc_html__( 'Sync Pattern Catalog', 'flavor-agent' ); ?>
					</button>
					<span id="flavor-agent-sync-spinner" class="spinner" aria-hidden="true"></span>
					<span id="flavor-agent-sync-status" class="flavor-agent-sync-panel__status" aria-hidden="true"></span>
					<span id="flavor-agent-sync-live-region" class="screen-reader-text" aria-live="polite"></span>
				</div>
				<div id="flavor-agent-sync-notice" class="flavor-agent-sync-panel__notice" aria-live="polite"></div>
			</div>
		</details>
		<?php
	}

	private static function render_sync_section(): void {
		self::render_sync_panel( self::get_page_state() );
	}

	private static function should_open_sync_panel( array $page_state ): bool {
		$state = is_array( $page_state['pattern_state'] ?? null ) ? $page_state['pattern_state'] : [];

		if ( ! empty( $state['last_error'] ) ) {
			return true;
		}

		if ( 'stale' === (string) ( $state['status'] ?? '' ) ) {
			return true;
		}

		return ! empty( $page_state['patterns_ready'] ) && 'uninitialized' === (string) ( $state['status'] ?? '' );
	}

	private static function get_pattern_sync_prerequisite_message( array $page_state ): string {
		$embedding_ready = ! empty( $page_state['runtime_embedding']['configured'] );
		$qdrant_ready    = ! empty( $page_state['qdrant_configured'] );

		if ( $embedding_ready && $qdrant_ready ) {
			return '';
		}

		if ( ! $embedding_ready && ! $qdrant_ready ) {
			return __( 'Complete the embeddings setup in Chat Provider and add Qdrant before you sync the pattern index.', 'flavor-agent' );
		}

		if ( ! $embedding_ready ) {
			return __( 'Complete the embeddings setup in Chat Provider before you sync the pattern index.', 'flavor-agent' );
		}

		return __( 'Add the Qdrant URL and API key before you sync the pattern index.', 'flavor-agent' );
	}

	private static function get_pattern_sync_status_sentence( array $page_state ): string {
		$state = is_array( $page_state['pattern_state'] ?? null ) ? $page_state['pattern_state'] : [];

		if ( ! empty( self::get_pattern_sync_prerequisite_message( $page_state ) ) ) {
			return __( 'Pattern recommendations are not available until the required setup is complete.', 'flavor-agent' );
		}

		if ( ! empty( $state['last_error'] ) ) {
			return __( 'Pattern recommendations need attention before they can be trusted.', 'flavor-agent' );
		}

		return match ( (string) ( $state['status'] ?? 'uninitialized' ) ) {
			'ready' => __( 'Pattern recommendations are ready.', 'flavor-agent' ),
			'stale' => __( 'Pattern recommendations are usable but out of date.', 'flavor-agent' ),
			'indexing' => __( 'Pattern recommendations are syncing now.', 'flavor-agent' ),
			default => __( 'Pattern recommendations are not available until you sync the catalog.', 'flavor-agent' ),
		};
	}

	private static function render_sync_metric(
		string $label,
		string $value,
		bool $is_error = false,
		string $metric = '',
		bool $is_visible = true
	): void {
		$metric_attributes = [
			'class' => 'flavor-agent-sync-panel__metric' . ( ! $is_visible ? ' is-hidden' : '' ),
		];
		$value_attributes  = [
			'class' => 'flavor-agent-sync-panel__metric-value' . ( $is_error ? ' flavor-agent-sync-panel__metric-value--error' : '' ),
		];

		if ( '' !== $metric ) {
			$metric_attributes['data-pattern-metric']      = $metric;
			$value_attributes['data-pattern-metric-value'] = $metric;
		}

		if ( ! $is_visible ) {
			$metric_attributes['hidden'] = 'hidden';
		}

		?>
		<div<?php self::render_html_attributes( $metric_attributes ); ?>>
			<p class="flavor-agent-sync-panel__metric-label">
				<?php echo esc_html( $label ); ?>
			</p>
			<p<?php self::render_html_attributes( $value_attributes ); ?>>
				<?php echo esc_html( $value ); ?>
			</p>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Admin JS
	// ------------------------------------------------------------------

	private static function enqueue_admin_assets(): void {
		$asset_path = FLAVOR_AGENT_DIR . 'build/admin.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		$asset    = include $asset_path;
		$css_path = FLAVOR_AGENT_DIR . 'build/admin.css';

		wp_enqueue_script(
			'flavor-agent-admin',
			FLAVOR_AGENT_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'flavor-agent-admin',
				FLAVOR_AGENT_URL . 'build/admin.css',
				[],
				$asset['version']
			);
		}

		wp_localize_script(
			'flavor-agent-admin',
			'flavorAgentAdmin',
			[
				'restUrl' => rest_url(),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
	}
}
