<?php

declare(strict_types=1);

namespace FlavorAgent;

use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;

final class Settings {

	private const OPTION_GROUP = 'flavor_agent_settings';
	private const PAGE_SLUG    = 'flavor-agent';

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
			'AI Provider Selection',
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
			'Qdrant Cloud (Vector Store)',
			[ __CLASS__, 'render_qdrant_section' ],
			self::PAGE_SLUG
		);

		add_settings_section(
			'flavor_agent_cloudflare',
			'Cloudflare AI Search (Official WordPress Docs Grounding)',
			[ __CLASS__, 'render_cloudflare_section' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			Provider::OPTION_NAME,
			'Provider',
			[ __CLASS__, 'render_select_field' ],
			self::PAGE_SLUG,
			'flavor_agent_openai_provider',
			[
				'option'      => Provider::OPTION_NAME,
				'choices'     => Provider::choices( Provider::get() ),
				'description' => 'Choose which provider Flavor Agent should prefer for recommendation surfaces. Direct Azure OpenAI and OpenAI Native backends are configured below. Any text-generation provider you have already configured in <code>Settings &gt; Connectors</code> also becomes selectable here for chat-backed surfaces. When the selected provider is missing a required capability, Flavor Agent automatically falls back to another compatible configured backend. Pattern recommendations still require a plugin-managed embedding backend below plus Qdrant.',
			]
		);

		// --- Azure OpenAI fields ---

		add_settings_field(
			'flavor_agent_azure_openai_endpoint',
			'Resource Endpoint',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'      => 'flavor_agent_azure_openai_endpoint',
				'type'        => 'url',
				'placeholder' => 'https://my-resource.openai.azure.com/',
				'description' => 'The base URL of your Azure OpenAI resource. Azure Portal &gt; your resource &gt; <strong>Endpoint</strong>.',
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
				'type'        => 'password',
				'placeholder' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
				'description' => 'Either KEY 1 or KEY 2 from your Azure OpenAI resource. Azure Portal &gt; your resource &gt; <strong>Keys and Endpoint</strong>.',
			]
		);
		add_settings_field(
			'flavor_agent_azure_embedding_deployment',
			'Embedding Deployment Name',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'      => 'flavor_agent_azure_embedding_deployment',
				'placeholder' => 'text-embedding-3-large',
				'description' => 'The deployment name (not the model name) for your embeddings model. Azure Portal &gt; your resource &gt; <strong>Model deployments</strong>.',
			]
		);
		add_settings_field(
			'flavor_agent_azure_chat_deployment',
			'Chat Deployment Name',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'      => 'flavor_agent_azure_chat_deployment',
				'placeholder' => 'gpt-5.4',
				'description' => 'The deployment name for your chat/responses model. Azure Portal &gt; your resource &gt; <strong>Model deployments</strong>.',
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
				'type'        => 'password',
				'placeholder' => 'sk-...',
				'description' => 'Optional override for the OpenAI API key. Leave blank to inherit the core OpenAI connector key from <code>Settings &gt; Connectors</code> or <code>OPENAI_API_KEY</code>. Flavor Agent uses <code>https://api.openai.com/v1</code> when this provider is selected.',
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
				'placeholder' => 'text-embedding-3-large',
				'description' => 'The OpenAI embeddings model ID used to vectorize patterns.',
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
				'placeholder' => 'gpt-5.4',
				'description' => 'The OpenAI Responses model ID used for pattern ranking plus template and navigation recommendations.',
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
				'type'        => 'url',
				'placeholder' => 'https://my-cluster.cloud.qdrant.io:6333',
				'description' => 'The REST endpoint of your Qdrant cluster including port. Qdrant Cloud console &gt; Clusters &gt; your cluster &gt; <strong>URL</strong>.',
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
				'type'        => 'password',
				'placeholder' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
				'description' => 'A Qdrant Cloud API key with read/write access to collections. Qdrant Cloud console &gt; Clusters &gt; your cluster &gt; <strong>API Keys</strong>.',
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
				'placeholder' => 'e.g. 1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d',
				'description' => 'Your 32-character hex Cloudflare account ID. Cloudflare dashboard &gt; any domain &gt; right sidebar &gt; <strong>Account ID</strong>, or the URL path after <code>/accounts/</code>.',
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
				'placeholder' => 'wordpress-developer-docs',
				'description' => 'The slug of your AI Search instance. Cloudflare dashboard &gt; AI &gt; AI Search &gt; your instance name.',
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
				'type'        => 'password',
				'placeholder' => 'Cloudflare API token',
				'description' => 'A Cloudflare API token with <strong>AI Search Run</strong> permission. <strong>AI Search Edit</strong> also works. Do not use an AI Gateway token. Create one at <code>dash.cloudflare.com/profile/api-tokens</code>.',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_ai_search_max_results',
			'Grounding Results',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option'      => 'flavor_agent_cloudflare_ai_search_max_results',
				'type'        => 'number',
				'placeholder' => '4',
				'description' => 'Number of documentation excerpts to include as grounding context per request (1&ndash;8). Higher values improve accuracy but increase latency.',
			]
		);
	}

	public static function render_page(): void {
		$activity_url   = admin_url( 'options-general.php?page=flavor-agent-activity' );
		$connectors_url = admin_url( 'options-connectors.php' );
		?>
		<div class="wrap flavor-agent-settings-page">
			<div class="flavor-agent-settings">
				<section class="flavor-agent-admin-hero flavor-agent-settings__hero">
					<div class="flavor-agent-admin-hero__content">
						<p class="flavor-agent-brand-note">
							<?php echo esc_html__( 'Lakefront Digital', 'flavor-agent' ); ?>
						</p>
						<p class="flavor-agent-wordmark">
							<?php echo esc_html__( 'Flavor Agent', 'flavor-agent' ); ?>
						</p>
						<h1 class="flavor-agent-admin-hero__title">
							<?php echo esc_html__( 'Orchestrate the provider stack behind every recommendation.', 'flavor-agent' ); ?>
						</h1>
						<p class="flavor-agent-admin-hero__copy">
							<?php echo esc_html__( 'Configure chat, embeddings, grounding, and pattern sync for editor-side guidance without leaving the native WordPress workflow.', 'flavor-agent' ); ?>
						</p>
						<div class="flavor-agent-admin-hero__actions">
							<a class="button button-primary" href="<?php echo esc_attr( self::sanitize_url_value( $activity_url ) ); ?>">
								<?php echo esc_html__( 'Review Activity', 'flavor-agent' ); ?>
							</a>
							<a class="button button-secondary" href="<?php echo esc_attr( self::sanitize_url_value( $connectors_url ) ); ?>">
								<?php echo esc_html__( 'Open Connectors', 'flavor-agent' ); ?>
							</a>
						</div>
					</div>
				</section>

				<?php self::render_settings_notices(); ?>

				<div class="flavor-agent-settings__bridge">
					<p>
						<?php
						echo esc_html__(
							'Settings > Connectors remains the core-managed home for WordPress AI Client providers and connector credentials. Settings > Flavor Agent now selects the active provider for recommendation surfaces while still owning the direct Azure OpenAI / OpenAI Native settings, Qdrant, Cloudflare grounding, and pattern sync.',
							'flavor-agent'
						);
						?>
					</p>
					<p>
						<?php
						echo esc_html__(
							'Block, template, template-part, navigation, Global Styles, and Style Book recommendations automatically use any compatible chat backend already configured here or in Settings > Connectors. Pattern recommendations still rely on a plugin-managed embedding backend configured on this screen plus Qdrant, but when chat comes from a Connectors provider Flavor Agent reuses any configured Azure OpenAI or OpenAI Native embedding backend automatically.',
							'flavor-agent'
						);
						?>
					</p>
				</div>

				<form method="post" action="options.php" class="flavor-agent-settings__form">
					<?php
					settings_fields( self::OPTION_GROUP );
					self::render_settings_sections();
					?>
					<div class="flavor-agent-settings__actions">
						<?php
						submit_button(
							__( 'Save Flavor Agent Settings', 'flavor-agent' ),
							'primary',
							'submit',
							false
						);
						?>
					</div>
				</form>

				<section class="flavor-agent-settings-section">
					<div class="flavor-agent-settings-section__header">
						<h2 class="flavor-agent-settings-section__title">
							<?php echo esc_html__( 'Sync Pattern Catalog', 'flavor-agent' ); ?>
						</h2>
						<p class="flavor-agent-settings__sync-copy">
							<?php echo esc_html__( 'Refresh the Qdrant-backed pattern index that powers ranked recommendations and pattern retrieval.', 'flavor-agent' ); ?>
						</p>
					</div>
					<div class="flavor-agent-settings-section__body">
						<?php self::render_sync_panel(); ?>
					</div>
				</section>
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
			?>
			<section class="flavor-agent-settings-section" id="<?php echo esc_attr( $section_id ); ?>">
				<div class="flavor-agent-settings-section__header">
					<h2 class="flavor-agent-settings-section__title">
						<?php echo esc_html( $section_title ); ?>
					</h2>
				</div>
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
			</section>
			<?php
		}
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

	/**
	 * @param 'env'|'constant'|'database'|'none' $source
	 */
	private static function format_openai_connector_key_source_label( string $source ): string {
		return match ( $source ) {
			'env'      => 'OPENAI_API_KEY environment variable',
			'constant' => 'OPENAI_API_KEY PHP constant',
			'database' => 'Settings > Connectors',
			default    => 'none',
		};
	}

	public static function render_azure_section(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Used when the provider is set to Azure OpenAI. These direct Responses and Embeddings credentials stay plugin-managed here; find them in the Azure Portal under your OpenAI resource > Keys and Endpoint.',
				'flavor-agent'
			)
		);
	}

	public static function render_openai_provider_section(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Settings > Connectors remains the core-managed home for provider plugins and credentials. This section chooses the provider Flavor Agent should prefer first: either the direct Azure OpenAI / OpenAI Native backends configured below, or any text-generation provider that is already configured in Settings > Connectors. If the selected provider cannot satisfy a surface, Flavor Agent falls back to another compatible configured backend automatically. Pattern recommendations still require the direct embedding backends below plus Qdrant.',
				'flavor-agent'
			)
		);
	}

	public static function render_openai_native_section(): void {
		$connector_status = Provider::openai_connector_status();
		$connector_note   = $connector_status['registered']
			? sprintf(
				'OpenAI connector: registered (key source: %s).',
				self::format_openai_connector_key_source_label( $connector_status['keySource'] )
			)
			: 'OpenAI connector: not registered.';

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Used when the provider is set to OpenAI Native. Flavor Agent sends requests directly to the official OpenAI Responses and Embeddings APIs. The API key can be stored here as a plugin-specific override or inherited from Settings > Connectors while model IDs remain plugin-managed on this screen.',
				'flavor-agent'
			)
		);
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					'Current effective API key source: %1$s. %2$s',
					self::format_openai_native_key_source_label( Provider::native_effective_api_key_source() ),
					$connector_note
				)
			)
		);
	}

	public static function render_qdrant_section(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Qdrant is plugin-managed infrastructure for pattern embeddings and similarity search; there is no core Connectors equivalent for it. Find your cluster URL and API key in the Qdrant Cloud console under Clusters > your cluster > Access.',
				'flavor-agent'
			)
		);
	}

	public static function render_cloudflare_section(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Flavor Agent only keeps Cloudflare grounding chunks whose source resolves to developer.wordpress.org. Mixed or private content in the configured index is ignored, and the configured account, instance, and token are validated only when those credentials change. Validation runs a lightweight probe search that must return trusted developer.wordpress.org guidance before new credentials are saved.',
				'flavor-agent'
			)
		);

		self::render_prewarm_diagnostics();
	}

	/**
	 * Generic text/password/url field renderer driven by $args.
	 */
	public static function render_text_field( array $args ): void {
		$option      = $args['option'] ?? '';
		$type        = $args['type'] ?? 'text';
		$placeholder = $args['placeholder'] ?? '';
		$description = $args['description'] ?? '';
		$value       = (string) get_option( $option, '' );

		printf(
			'<input type="%s" name="%s" value="%s" class="regular-text flavor-agent-settings-field" autocomplete="off" placeholder="%s" />',
			esc_attr( $type ),
			esc_attr( $option ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);

		if ( $description ) {
			printf( '<p class="description">%s</p>', wp_kses_post( $description ) );
		}
	}

	public static function render_select_field( array $args ): void {
		$option      = (string) ( $args['option'] ?? '' );
		$choices     = is_array( $args['choices'] ?? null ) ? $args['choices'] : [];
		$description = (string) ( $args['description'] ?? '' );
		$value       = (string) get_option(
			$option,
			$option === Provider::OPTION_NAME ? Provider::AZURE : ''
		);

		printf(
			'<select name="%s" class="flavor-agent-settings-field">',
			esc_attr( $option )
		);

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
			printf( '<p class="description">%s</p>', wp_kses_post( $description ) );
		}
	}

	public static function sanitize_grounding_result_count( mixed $value ): int {
		return max( 1, min( 8, (int) $value ) );
	}

	public static function sanitize_openai_provider( mixed $value ): string {
		return Provider::normalize_provider( (string) $value );
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
		$option_page = $_POST['option_page'] ?? null;

		if ( ! is_string( $option_page ) ) {
			return false;
		}

		$option_page = wp_unslash( $option_page );

		return sanitize_text_field( $option_page ) === self::OPTION_GROUP;
	}

	private static function get_submitted_openai_provider(): string {
		$provider = $_POST[ Provider::OPTION_NAME ] ?? get_option( Provider::OPTION_NAME, Provider::AZURE );

		if ( is_string( $provider ) ) {
			$provider = wp_unslash( $provider );
		}

		return Provider::normalize_provider( is_string( $provider ) ? $provider : Provider::AZURE );
	}

	private static function read_posted_cloudflare_value( string $option_name, string $fallback ): string {
		return self::read_posted_text_value( $option_name, $fallback );
	}

	private static function read_posted_text_value( string $option_name, string $fallback ): string {
		$value = $_POST[ $option_name ] ?? null;

		if ( ! is_string( $value ) ) {
			return sanitize_text_field( $fallback );
		}

		$value = wp_unslash( $value );

		return sanitize_text_field( $value );
	}

	private static function read_posted_url_value( string $option_name, string $fallback ): string {
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

	private static function sanitize_url_value( mixed $value ): string {
		return (string) sanitize_url( (string) $value );
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

		self::$cloudflare_validation_error_reported = true;
	}

	// ------------------------------------------------------------------
	// Prewarm diagnostics panel
	// ------------------------------------------------------------------

	private static function render_prewarm_diagnostics(): void {
		if ( ! AISearchClient::is_configured() ) {
			return;
		}

		$state = AISearchClient::get_prewarm_state();

		$status_labels = [
			'never'     => 'Never run',
			'ok'        => 'OK',
			'partial'   => 'Partial (some entities failed)',
			'failed'    => 'Failed',
			'throttled' => 'Throttled (skipped, too recent)',
		];

		$label = $status_labels[ $state['status'] ] ?? $state['status'];
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
						$state['warmed'],
						$state['failed']
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

	private static function render_sync_panel(): void {
		$state = PatternIndex::get_runtime_state();

		$status_labels = [
			'uninitialized' => 'Not synced yet',
			'indexing'      => 'Syncing…',
			'ready'         => 'Ready',
			'stale'         => 'Stale (usable, refresh pending)',
			'error'         => 'Error',
		];

		$label = $status_labels[ $state['status'] ] ?? $state['status'];
		?>
		<div class="flavor-agent-sync-panel">
			<div class="flavor-agent-sync-panel__metrics">
				<?php
				self::render_sync_metric(
					__( 'Status', 'flavor-agent' ),
					$label
				);
				self::render_sync_metric(
					__( 'Indexed Patterns', 'flavor-agent' ),
					(string) (int) $state['indexed_count']
				);
				self::render_sync_metric(
					__( 'Last Synced', 'flavor-agent' ),
					$state['last_synced_at'] ? (string) $state['last_synced_at'] : __( 'Not synced yet', 'flavor-agent' )
				);
				self::render_sync_metric(
					__( 'Qdrant Collection', 'flavor-agent' ),
					$state['qdrant_collection'] ? (string) $state['qdrant_collection'] : QdrantClient::get_collection_name()
				);

				if ( $state['last_error'] ) {
					self::render_sync_metric(
						__( 'Last Error', 'flavor-agent' ),
						(string) $state['last_error'],
						true
					);
				}
				?>
			</div>

			<div class="flavor-agent-sync-panel__actions">
				<button type="button" id="flavor-agent-sync-button" class="button button-secondary">
					<?php echo esc_html__( 'Sync Pattern Catalog', 'flavor-agent' ); ?>
				</button>
				<span id="flavor-agent-sync-spinner" class="spinner" aria-hidden="true"></span>
				<span id="flavor-agent-sync-status" class="flavor-agent-sync-panel__status"></span>
			</div>
			<div id="flavor-agent-sync-notice" class="flavor-agent-sync-panel__notice" aria-live="polite"></div>
		</div>
		<?php
	}

	private static function render_sync_metric(
		string $label,
		string $value,
		bool $is_error = false
	): void {
		?>
		<div class="flavor-agent-sync-panel__metric">
			<p class="flavor-agent-sync-panel__metric-label">
				<?php echo esc_html( $label ); ?>
			</p>
			<p class="flavor-agent-sync-panel__metric-value<?php echo $is_error ? ' flavor-agent-sync-panel__metric-value--error' : ''; ?>">
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
