<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

use FlavorAgent\Activity\RequestLoggingBridge;
use FlavorAgent\AI\FeatureBootstrap;
use FlavorAgent\Cloudflare\WorkersAIEmbeddingConfiguration;
use FlavorAgent\Cloudflare\PatternSearchInstanceManager;
use FlavorAgent\Embeddings\QdrantClient;
use FlavorAgent\Guidelines;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Page {

	public static function render_page(): void {
		self::ensure_settings_api_registered();

		$state                = State::get_page_state();
		$feedback             = Feedback::consume_settings_page_feedback();
		$feedback_request_key = Feedback::generate_request_key();
		$activity_url         = admin_url( 'options-general.php?page=flavor-agent-activity' );
		$connectors_url       = admin_url( 'options-connectors.php' );
		$default_group        = State::determine_default_open_group( $state );
		$attention_group      = State::determine_runtime_attention_group( $state );
		$forced_group         = is_string( $feedback['focus_section'] ?? null ) ? $feedback['focus_section'] : '';
		$chat_ready           = ! empty( $state['runtime_chat']['configured'] );
		$primary_url          = $chat_ready ? $activity_url : $connectors_url;
		$primary_label        = $chat_ready ? __( 'Open Activity Log', 'flavor-agent' ) : __( 'Open Connectors', 'flavor-agent' );
		$secondary_url        = $chat_ready ? $connectors_url : $activity_url;
		$secondary_label      = $chat_ready ? __( 'Open Connectors', 'flavor-agent' ) : __( 'Open Activity Log', 'flavor-agent' );
		$open_group           = '' !== $forced_group ? $forced_group : $default_group;
		?>
		<div class="wrap flavor-agent-settings-page">
			<div
				class="flavor-agent-settings"
				data-attention-section="<?php echo esc_attr( $attention_group ); ?>"
				data-default-section="<?php echo esc_attr( $default_group ); ?>"
				data-force-section="<?php echo esc_attr( $forced_group ); ?>"
				data-open-section-storage-key="<?php echo esc_attr( Config::OPEN_SECTION_STORAGE_KEY ); ?>"
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
							<?php echo esc_html__( 'Configure setup, storage, docs, and guidance.', 'flavor-agent' ); ?>
						</p>
						<div class="flavor-agent-admin-hero__actions">
							<a class="button button-primary" href="<?php echo esc_url( Utils::sanitize_url_value( $primary_url ) ); ?>">
								<?php echo esc_html( $primary_label ); ?>
							</a>
							<a class="button button-secondary" href="<?php echo esc_url( Utils::sanitize_url_value( $secondary_url ) ); ?>">
								<?php echo esc_html( $secondary_label ); ?>
							</a>
						</div>
					</div>
				</header>

				<?php self::render_setup_status_cards( $state ); ?>
				<?php self::render_settings_notices(); ?>
				<?php self::render_settings_save_summary( $feedback ); ?>

				<form method="post" action="options.php" class="flavor-agent-settings__form">
					<?php
					settings_fields( Config::OPTION_GROUP );
					Feedback::render_feedback_request_fields( $feedback_request_key );
					self::render_settings_section_group(
						Config::GROUP_CHAT,
						__( '1. AI Model', 'flavor-agent' ),
						State::get_group_card_meta( Config::GROUP_CHAT, $state ),
						$open_group,
						$feedback,
						static function () use ( $state, $feedback, $connectors_url ): void {
							self::render_ai_model_group( $state, $feedback, $connectors_url );
						}
					);
					self::render_settings_section_group(
						Config::GROUP_EMBEDDINGS,
						__( '2. Embedding Model', 'flavor-agent' ),
						State::get_group_card_meta( Config::GROUP_EMBEDDINGS, $state ),
						$open_group,
						$feedback,
						static function () use ( $state, $feedback ): void {
							self::render_embedding_model_group( $state, $feedback );
						}
					);
					self::render_settings_section_group(
						Config::GROUP_PATTERNS,
						__( '3. Patterns', 'flavor-agent' ),
						State::get_group_card_meta( Config::GROUP_PATTERNS, $state ),
						$open_group,
						$feedback,
						static function () use ( $state, $feedback ): void {
							self::render_pattern_recommendations_group( $state, $feedback );
						}
					);
					self::render_settings_section_group(
						Config::GROUP_DOCS,
						__( '4. Developer Docs', 'flavor-agent' ),
						State::get_group_card_meta( Config::GROUP_DOCS, $state ),
						$open_group,
						$feedback,
						static function () use ( $state, $feedback ): void {
							self::render_docs_grounding_group( $state, $feedback );
						}
					);
					self::render_settings_section_group(
						Config::GROUP_GUIDELINES,
						__( '5. Guidelines', 'flavor-agent' ),
						State::get_group_card_meta( Config::GROUP_GUIDELINES, $state ),
						$open_group,
						$feedback,
						static function () use ( $state, $feedback ): void {
							self::render_guidelines_group( $state, $feedback );
						}
					);
					self::render_settings_section_group(
						Config::GROUP_EXPERIMENTS,
						__( '6. Experimental Features', 'flavor-agent' ),
						State::get_group_card_meta( Config::GROUP_EXPERIMENTS, $state ),
						$open_group,
						$feedback,
						static function () use ( $state, $feedback ): void {
							self::render_experimental_features_group( $state, $feedback );
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

	public static function render_cloudflare_workers_ai_section(): void {
		// Guidance now lives in the subsection heading to keep the page compact.
	}

	public static function render_pattern_retrieval_section(): void {
		// Guidance now lives in the field description to keep the page focused on controls.
	}

	public static function render_qdrant_section(): void {
		// Guidance now lives in the screen Help panel to keep the page focused on controls.
	}

	public static function render_cloudflare_pattern_ai_search_section(): void {
		// Guidance now lives in the subsection heading to keep the page compact.
	}

	public static function render_pattern_recommendations_section(): void {
		// Guidance now lives in the screen Help panel to keep the page focused on controls.
	}

	public static function render_cloudflare_section(): void {
		// Guidance now lives in the screen Help panel to keep the page focused on controls.
	}

	public static function render_guidelines_section(): void {
		// Guidance now lives in the screen Help panel to keep the page focused on controls.
	}

	public static function render_experimental_features_section(): void {
		printf(
			'<p class="flavor-agent-settings-inline-meta">%s</p>',
			esc_html__( 'AI Activity logging controls.', 'flavor-agent' )
		);
	}

	public static function render_settings_notices(): void {
		settings_errors( 'general' );
		settings_errors( Config::OPTION_GROUP );
	}

	private static function ensure_settings_api_registered(): void {
		global $wp_settings_fields, $wp_settings_sections;

		if (
			! empty( $wp_settings_sections[ Config::PAGE_SLUG ] ) &&
			! empty( $wp_settings_fields[ Config::PAGE_SLUG ] )
		) {
			return;
		}

		Registrar::register_settings();
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	public static function render_settings_save_summary( array $feedback ): void {
		if ( ! Feedback::has_settings_updated_query_flag() ) {
			return;
		}

		$changed_sections = is_array( $feedback['changed_sections'] ?? null )
			? $feedback['changed_sections']
			: [];
		$summary_lines    = [];

		if (
			! empty( $changed_sections[ Config::GROUP_CHAT ] ) &&
			! Feedback::feedback_group_has_tone( $feedback, Config::GROUP_CHAT, 'error' )
		) {
			$summary_lines[] = __( 'AI model settings saved.', 'flavor-agent' );
		}

		if (
			! empty( $changed_sections[ Config::GROUP_EMBEDDINGS ] ) &&
			! Feedback::feedback_group_has_tone( $feedback, Config::GROUP_EMBEDDINGS, 'error' )
		) {
			$summary_lines[] = __( 'Embedding model settings saved.', 'flavor-agent' );
		}

		if (
			! empty( $changed_sections[ Config::GROUP_PATTERNS ] ) &&
			! Feedback::feedback_group_has_tone( $feedback, Config::GROUP_PATTERNS, 'error' )
		) {
			$summary_lines[] = PatternIndex::recommendation_backends_configured()
				? __( 'Pattern settings saved. Run Pattern Sync to update the index.', 'flavor-agent' )
				: __( 'Pattern settings saved.', 'flavor-agent' );
		}

		if (
			! empty( $changed_sections[ Config::GROUP_DOCS ] ) &&
			! Feedback::feedback_group_has_tone( $feedback, Config::GROUP_DOCS, 'error' )
		) {
			$summary_lines[] = __( 'Developer docs settings saved.', 'flavor-agent' );
		}

		if (
			! empty( $changed_sections[ Config::GROUP_GUIDELINES ] ) &&
			! Feedback::feedback_group_has_tone( $feedback, Config::GROUP_GUIDELINES, 'error' )
		) {
			$summary_lines[] = __( 'Guidelines saved.', 'flavor-agent' );
		}

		if (
			! empty( $changed_sections[ Config::GROUP_EXPERIMENTS ] ) &&
			! Feedback::feedback_group_has_tone( $feedback, Config::GROUP_EXPERIMENTS, 'error' )
		) {
			$summary_lines[] = __( 'Experimental feature settings saved.', 'flavor-agent' );
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

	/**
	 * Describe which AI runtime is currently serving text generation.
	 *
	 * On WordPress.com managed hosting the WordPress AI Client ("ai") feature
	 * plugin cannot be installed, and Flavor Agent falls back to Jetpack AI.
	 * This surfaces that distinction at a glance.
	 *
	 * @return array{label: string, tone: string}
	 */
	private static function get_ai_runtime_overview_status(): array {
		if ( FeatureBootstrap::jetpack_ai_runtime_active() ) {
			return State::make_badge( __( 'Jetpack AI', 'flavor-agent' ), 'success' );
		}

		if ( FeatureBootstrap::canonical_contracts_available() ) {
			return State::make_badge( __( 'WordPress AI Client', 'flavor-agent' ), 'success' );
		}

		return State::make_badge( __( 'Not available', 'flavor-agent' ), 'warning' );
	}

	private static function render_setup_status_cards( array $state ): void {
		$chat_status        = ! empty( $state['runtime_chat']['configured'] )
			? State::make_badge( __( 'Ready', 'flavor-agent' ), 'success' )
			: State::make_badge( __( 'Needs setup', 'flavor-agent' ), 'warning' );
		$embedding_status   = State::get_embedding_overview_status( $state );
		$pattern_status     = State::get_pattern_overview_status( $state );
		$docs_status        = State::get_docs_overview_status( $state );
		$guidelines_status  = State::get_guidelines_overview_status( $state );
		$experiments_status = State::get_experiments_overview_status( $state );
		$runtime_status     = self::get_ai_runtime_overview_status();
		?>
		<div class="flavor-agent-settings__glance">
			<?php
			self::render_setup_status_card(
				__( 'AI Runtime', 'flavor-agent' ),
				$runtime_status['label'],
				$runtime_status['tone'],
				'#' . State::get_section_dom_id( Config::GROUP_CHAT )
			);
			self::render_setup_status_card(
				__( 'AI Model', 'flavor-agent' ),
				$chat_status['label'],
				$chat_status['tone'],
				'#' . State::get_section_dom_id( Config::GROUP_CHAT )
			);
			self::render_setup_status_card(
				__( 'Embedding Model', 'flavor-agent' ),
				$embedding_status['label'],
				$embedding_status['tone'],
				'#' . State::get_section_dom_id( Config::GROUP_EMBEDDINGS )
			);
			self::render_setup_status_card(
				__( 'Patterns', 'flavor-agent' ),
				$pattern_status['label'],
				$pattern_status['tone'],
				'#' . State::get_section_dom_id( Config::GROUP_PATTERNS ),
				[
					'data-pattern-overview-status' => 'true',
				]
			);
			self::render_setup_status_card(
				__( 'Developer Docs', 'flavor-agent' ),
				$docs_status['label'],
				$docs_status['tone'],
				'#' . State::get_section_dom_id( Config::GROUP_DOCS )
			);
			self::render_setup_status_card(
				__( 'Guidelines', 'flavor-agent' ),
				$guidelines_status['label'],
				$guidelines_status['tone'],
				'#' . State::get_section_dom_id( Config::GROUP_GUIDELINES )
			);
			self::render_setup_status_card(
				__( 'Experimental Features', 'flavor-agent' ),
				$experiments_status['label'],
				$experiments_status['tone'],
				'#' . State::get_section_dom_id( Config::GROUP_EXPERIMENTS )
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
		string $url,
		array $attributes = []
	): void {
		$card_attributes = Utils::merge_html_attributes(
			[
				'class' => 'flavor-agent-settings__glance-item flavor-agent-settings__glance-item--' . $tone,
				'href'  => Utils::sanitize_url_value( $url ),
			],
			$attributes
		);
		?>
		<a<?php Utils::render_html_attributes( $card_attributes ); ?>>
			<p class="flavor-agent-settings__glance-label">
				<?php echo esc_html( $title ); ?>
			</p>
			<p class="flavor-agent-settings__glance-value">
				<?php echo esc_html( $status ); ?>
			</p>
		</a>
		<?php
	}

	/**
	 * @param array{summary: string, badges: array<int, array{label: string, tone: string}>, status: array{label: string, tone: string}, open: bool} $meta
	 * @param array<string, mixed> $feedback
	 */
	private static function render_settings_section_group(
		string $group,
		string $title,
		array $meta,
		string $open_group,
		array $feedback,
		callable $renderer
	): void {
		$dom_id             = State::get_section_dom_id( $group );
		$has_feedback_error = Feedback::feedback_group_has_tone( $feedback, $group, 'error' );
		$is_open            = $open_group === $group || $has_feedback_error;
		$details_attributes = [
			'class'                     => 'flavor-agent-settings-section__panel',
			'data-flavor-agent-section' => $group,
		];

		if ( $has_feedback_error ) {
			$details_attributes['data-flavor-agent-validation-error'] = 'true';
		}
		?>
		<section class="flavor-agent-settings-section" id="<?php echo esc_attr( $dom_id ); ?>">
			<details<?php Utils::render_html_attributes( $details_attributes ); ?><?php echo $is_open ? ' open' : ''; ?>>
				<summary class="flavor-agent-settings-section__summary">
					<div class="flavor-agent-settings-section__summary-main">
						<h2 class="flavor-agent-settings-section__title">
							<?php echo esc_html( $title ); ?>
						</h2>
						<?php if ( '' !== (string) $meta['summary'] ) : ?>
							<span class="flavor-agent-settings-section__summary-text">
								<?php echo esc_html( $meta['summary'] ); ?>
							</span>
						<?php endif; ?>
					</div>
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

		$badge_attributes = Utils::merge_html_attributes(
			[
				'class' => 'flavor-agent-settings-section__badge flavor-agent-settings-section__badge--' . $badge['tone'],
			],
			$attributes
		);
		?>
		<span<?php Utils::render_html_attributes( $badge_attributes ); ?>>
			<?php echo esc_html( $badge['label'] ); ?>
		</span>
		<?php
	}

	/**
	 * @param array<int, string> $field_ids
	 */
	private static function render_registered_fields_table( string $section_id, array $field_ids ): void {
		global $wp_settings_fields;

		if ( empty( $wp_settings_fields[ Config::PAGE_SLUG ][ $section_id ] ) ) {
			return;
		}
		?>
		<table class="form-table flavor-agent-settings-table" role="presentation">
			<?php
			foreach ( $field_ids as $field_id ) {
				if ( ! isset( $wp_settings_fields[ Config::PAGE_SLUG ][ $section_id ][ $field_id ] ) ) {
					continue;
				}

				$field     = $wp_settings_fields[ Config::PAGE_SLUG ][ $section_id ][ $field_id ];
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

		if ( empty( $wp_settings_sections[ Config::PAGE_SLUG ][ $section_id ]['callback'] ) ) {
			return;
		}

		$section_callback = $wp_settings_sections[ Config::PAGE_SLUG ][ $section_id ]['callback'];

		if ( is_callable( $section_callback ) ) {
			call_user_func(
				$section_callback,
				$wp_settings_sections[ Config::PAGE_SLUG ][ $section_id ]
			);
		}
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function render_ai_model_group( array $state, array $feedback, string $connectors_url ): void {
		self::render_section_status_blocks( Config::GROUP_CHAT, $state, $feedback );
		$runtime_chat_label = trim( (string) ( $state['runtime_chat']['label'] ?? '' ) );
		$runtime_chat_label = '' !== $runtime_chat_label ? $runtime_chat_label : __( 'Not configured', 'flavor-agent' );
		?>
		<p class="description">
			<?php echo esc_html__( 'Text generation is managed in Connectors.', 'flavor-agent' ); ?>
		</p>
		<p class="flavor-agent-settings-inline-meta">
			<?php
			printf(
				/* translators: %s: runtime chat provider label */
				esc_html__( 'Current: %s.', 'flavor-agent' ),
				esc_html( $runtime_chat_label )
			);
			?>
		</p>
		<p>
			<a class="button button-secondary" href="<?php echo esc_url( Utils::sanitize_url_value( $connectors_url ) ); ?>">
				<?php echo esc_html__( 'Open Connectors', 'flavor-agent' ); ?>
			</a>
		</p>
		<?php
		self::render_ai_activity_storage_status();
	}

	private static function render_ai_activity_storage_status(): void {
		$core_logging_available = RequestLoggingBridge::is_core_logging_class_available();
		$core_logging_enabled   = RequestLoggingBridge::is_core_logging_enabled();
		$tone                   = 'warning';
		$message                = __( 'Flavor Agent records request diagnostics in its own activity log. Upgrade to WordPress AI 1.0.0+ to access core AI request observability.', 'flavor-agent' );
		$links                  = [];

		if ( $core_logging_enabled ) {
			$dual_logging = function_exists( '\\flavor_agent_dual_log_request_diagnostics_enabled' )
				? \flavor_agent_dual_log_request_diagnostics_enabled()
				: (bool) get_option( Config::OPTION_DUAL_LOG_REQUEST_DIAGNOSTICS, true );
			$tone         = 'success';
			$message      = $dual_logging
				? __( 'AI Request Logging is enabled. Flavor Agent also records its own request diagnostics here and forwards surface, scope, and document context into each Tools > AI Request Logs row (dual logging).', 'flavor-agent' )
				: __( 'AI Request Logging is enabled. Flavor Agent defers to core logging and forwards surface, scope, and document context into each Tools > AI Request Logs row.', 'flavor-agent' );
			$links[]      = [
				'url'   => admin_url( 'tools.php?page=ai-request-logs' ),
				'label' => __( 'Open AI Request Logs', 'flavor-agent' ),
			];
			if ( $dual_logging ) {
				$links[] = [
					'url'   => admin_url( 'options-general.php?page=flavor-agent-activity' ),
					'label' => __( 'Open AI Activity', 'flavor-agent' ),
				];
			}
		} elseif ( $core_logging_available ) {
			$message = __( 'Flavor Agent is recording request diagnostics in its own activity log. Enable the AI Request Logging experiment in Settings > AI to also capture provider, model, token, and cost data centrally.', 'flavor-agent' );
			$links[] = [
				'url'   => admin_url( 'options-general.php?page=ai-wp-admin' ),
				'label' => __( 'Open AI settings', 'flavor-agent' ),
			];
		}

		self::render_subsection_heading(
			__( 'AI Activity Storage', 'flavor-agent' ),
			__( 'Read-only status for recommendation request observability.', 'flavor-agent' )
		);
		?>
		<div class="flavor-agent-settings-status flavor-agent-settings-status--<?php echo esc_attr( $tone ); ?>">
			<p><?php echo esc_html( $message ); ?></p>
			<?php foreach ( $links as $link ) : ?>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url( Utils::sanitize_url_value( $link['url'] ) ); ?>">
						<?php echo esc_html( $link['label'] ); ?>
					</a>
				</p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function render_embedding_model_group( array $state, array $feedback ): void {
		self::render_section_status_blocks( Config::GROUP_EMBEDDINGS, $state, $feedback );
		?>
		<input type="hidden" name="<?php echo esc_attr( Provider::OPTION_NAME ); ?>" value="<?php echo esc_attr( WorkersAIEmbeddingConfiguration::PROVIDER ); ?>" />
			<p class="flavor-agent-settings-inline-meta">
				<?php
				printf(
					/* translators: %s: embedding provider label */
					esc_html__( 'Current embedding provider: %s.', 'flavor-agent' ),
					esc_html( Provider::label( WorkersAIEmbeddingConfiguration::PROVIDER ) )
				);
				?>
			</p>
		<?php
		self::render_cloudflare_workers_ai_direct_settings_fields();
	}

	private static function render_cloudflare_workers_ai_direct_settings_fields(): void {
		self::render_subsection_heading(
			__( 'Cloudflare Workers AI', 'flavor-agent' ),
			__( 'Used for embeddings.', 'flavor-agent' )
		);
		self::render_registered_section_callback( 'flavor_agent_cloudflare_workers_ai' );
		self::render_registered_fields_table(
			'flavor_agent_cloudflare_workers_ai',
			[
				'flavor_agent_cloudflare_workers_ai_account_id',
				'flavor_agent_cloudflare_workers_ai_api_token',
				'flavor_agent_cloudflare_workers_ai_embedding_model',
			]
		);
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function render_pattern_recommendations_group( array $state, array $feedback ): void {
		self::render_section_status_blocks( Config::GROUP_PATTERNS, $state, $feedback );

		$selected_backend   = (string) ( $state['selected_pattern_backend'] ?? Config::PATTERN_BACKEND_QDRANT );
		$qdrant_active      = Config::PATTERN_BACKEND_QDRANT === $selected_backend;
		$cloudflare_active  = Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH === $selected_backend;
		$has_feedback_error = Feedback::feedback_group_has_tone( $feedback, Config::GROUP_PATTERNS, 'error' );
		$ranking_attributes = [
			'class'                          => 'flavor-agent-settings-subpanel',
			'data-flavor-agent-nested-panel' => 'pattern-ranking',
		];

		if ( $has_feedback_error ) {
			$ranking_attributes['data-flavor-agent-validation-error'] = 'true';
		}
		?>
		<p class="description">
			<?php echo esc_html__( 'Choose where the pattern catalog is stored.', 'flavor-agent' ); ?>
		</p>
		<?php
		self::render_registered_section_callback( 'flavor_agent_pattern_retrieval' );
		self::render_pattern_backend_segmented_control( $selected_backend );
		?>
		<div
			class="flavor-agent-pattern-backend-block"
			data-pattern-backend="<?php echo esc_attr( Config::PATTERN_BACKEND_QDRANT ); ?>"
			<?php echo $qdrant_active ? '' : 'hidden'; ?>
		>
			<?php
			self::render_subsection_heading(
				__( 'Qdrant Pattern Storage', 'flavor-agent' ),
				__( 'Vector storage for the pattern index.', 'flavor-agent' )
			);
			self::render_qdrant_pattern_storage_status_panel( $state );
			self::render_registered_section_callback( 'flavor_agent_qdrant' );
			self::render_registered_fields_table(
				'flavor_agent_qdrant',
				[
					'flavor_agent_qdrant_url',
					'flavor_agent_qdrant_key',
				]
			);
			?>
		</div>
		<div
			class="flavor-agent-pattern-backend-block"
			data-pattern-backend="<?php echo esc_attr( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH ); ?>"
			<?php echo $cloudflare_active ? '' : 'hidden'; ?>
		>
			<?php
			self::render_subsection_heading(
				__( 'Cloudflare AI Search Pattern Storage', 'flavor-agent' ),
				__( 'Managed pattern index using the saved Cloudflare credentials from the Embedding Model section.', 'flavor-agent' )
			);
			self::render_cloudflare_pattern_ai_search_status_panel( $state, $feedback );
			self::render_registered_section_callback( 'flavor_agent_cloudflare_pattern_ai_search' );
			?>
		</div>
		<details<?php Utils::render_html_attributes( $ranking_attributes ); ?><?php echo $has_feedback_error ? ' open' : ''; ?>>
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
						Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH,
						'flavor_agent_pattern_max_recommendations',
					]
				);
				?>
			</div>
		</details>
		<?php self::render_sync_panel( $state, $has_feedback_error ); ?>
		<?php
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function render_docs_grounding_group( array $state, array $feedback ): void {
		self::render_section_status_blocks( Config::GROUP_DOCS, $state, $feedback );
		?>
		<p class="description">
			<?php echo esc_html__( 'Built-in developer.wordpress.org grounding is active.', 'flavor-agent' ); ?>
		</p>
		<?php
		$runtime_state = is_array( $state['runtime_docs_grounding'] ?? null ) ? $state['runtime_docs_grounding'] : [];
		$diagnostics   = [];

		if ( '' !== (string) ( $runtime_state['lastSearchAt'] ?? '' ) ) {
			$diagnostics[] = sprintf(
				/* translators: %s: last docs search timestamp. */
				__( 'Last search: %s.', 'flavor-agent' ),
				(string) ( $runtime_state['lastSearchAt'] ?? '' )
			);
			$diagnostics[] = sprintf(
				/* translators: %d: number of guidance chunks returned by the last docs search. */
				__( 'Last result count: %d.', 'flavor-agent' ),
				(int) ( $runtime_state['lastResultCount'] ?? 0 )
			);
		}

		if ( [] !== $diagnostics ) :
			?>
			<p class="flavor-agent-settings-inline-meta">
				<?php echo esc_html( implode( ' ', $diagnostics ) ); ?>
			</p>
			<?php
		endif;
		?>
		<?php
		self::render_registered_section_callback( 'flavor_agent_cloudflare' );
		self::render_registered_fields_table(
			'flavor_agent_cloudflare',
			[
				'flavor_agent_cloudflare_ai_search_max_results',
			]
		);
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function render_guidelines_group( array $state, array $feedback ): void {
		self::render_section_status_blocks( Config::GROUP_GUIDELINES, $state, $feedback );
		self::render_registered_section_callback( 'flavor_agent_guidelines' );
		$has_feedback_error = Feedback::feedback_group_has_tone( $feedback, Config::GROUP_GUIDELINES, 'error' );
		?>
		<div class="flavor-agent-guidelines" data-flavor-agent-guidelines-root>
			<div class="flavor-agent-guidelines__notice" data-guidelines-notice aria-live="polite"></div>
			<?php if ( ! empty( $state['guidelines_storage']['core_available'] ) ) : ?>
				<div class="flavor-agent-settings-status flavor-agent-settings-status--accent">
					<p>
						<?php echo esc_html__( 'Core Guidelines connected.', 'flavor-agent' ); ?>
					</p>
				</div>
			<?php endif; ?>
			<?php
			self::render_registered_fields_table(
				'flavor_agent_guidelines',
				[
					Guidelines::OPTION_SITE,
					Guidelines::OPTION_COPY,
					Guidelines::OPTION_IMAGES,
					Guidelines::OPTION_ADDITIONAL,
				]
			);
			self::render_guidelines_blocks_panel( $has_feedback_error );
			self::render_guidelines_actions_panel();
			?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function render_experimental_features_group( array $state, array $feedback ): void {
		self::render_section_status_blocks( Config::GROUP_EXPERIMENTS, $state, $feedback );
		self::render_registered_section_callback( 'flavor_agent_experimental_features' );
		self::render_registered_fields_table(
			'flavor_agent_experimental_features',
			[
				Config::OPTION_DUAL_LOG_REQUEST_DIAGNOSTICS,
			]
		);
	}

	private static function render_guidelines_blocks_panel( bool $has_feedback_error = false ): void {
		$block_guidelines = Guidelines::get_block_guidelines();
		$block_options    = Guidelines::get_content_block_options();
		$guidelines_json  = Utils::encode_json_payload( $block_guidelines );
		$options_json     = Utils::encode_json_payload( $block_options, '[]', JSON_HEX_TAG );
		$panel_attributes = [
			'class'                          => 'flavor-agent-settings-subpanel flavor-agent-guidelines__blocks-panel',
			'data-flavor-agent-nested-panel' => 'block-guidelines',
		];
		$is_open          = [] !== $block_guidelines || $has_feedback_error;

		if ( $has_feedback_error ) {
			$panel_attributes['data-flavor-agent-validation-error'] = 'true';
		}
		?>
		<details<?php Utils::render_html_attributes( $panel_attributes ); ?><?php echo $is_open ? ' open' : ''; ?>>
			<summary class="flavor-agent-settings-subpanel__summary">
				<?php echo esc_html__( 'Block Guidelines', 'flavor-agent' ); ?>
			</summary>
			<div class="flavor-agent-settings-subpanel__body">
				<p class="description">
					<?php echo esc_html__( 'Add extra rules for a specific block when needed.', 'flavor-agent' ); ?>
				</p>
				<textarea
					id="<?php echo esc_attr( Guidelines::OPTION_BLOCKS ); ?>"
					name="<?php echo esc_attr( Guidelines::OPTION_BLOCKS ); ?>"
					class="flavor-agent-guidelines__blocks-input"
					data-guidelines-block-input
					hidden
				><?php echo esc_textarea( $guidelines_json ); ?></textarea>
				<script type="application/json" data-guidelines-block-options><?php echo $options_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_TAG keeps the inline JSON script inert while preserving parseable JSON. ?></script>
				<div class="flavor-agent-guidelines__block-list" data-guidelines-block-list></div>
				<div class="flavor-agent-guidelines__block-editor">
					<div class="flavor-agent-guidelines__block-editor-grid">
						<div class="flavor-agent-guidelines__field">
							<label class="flavor-agent-guidelines__field-label" for="flavor-agent-guidelines-block-select">
								<?php echo esc_html__( 'Block', 'flavor-agent' ); ?>
							</label>
							<select
								id="flavor-agent-guidelines-block-select"
								class="flavor-agent-settings-field"
								data-guidelines-block-select
							></select>
						</div>
						<div class="flavor-agent-guidelines__field flavor-agent-guidelines__field--wide">
							<label class="flavor-agent-guidelines__field-label" for="flavor-agent-guidelines-block-text">
								<?php echo esc_html__( 'Guideline text', 'flavor-agent' ); ?>
							</label>
							<textarea
								id="flavor-agent-guidelines-block-text"
								class="flavor-agent-settings-field flavor-agent-settings-field--textarea"
								rows="5"
								placeholder="<?php echo esc_attr__( 'Enter guidelines for how this block should be used...', 'flavor-agent' ); ?>"
								data-guidelines-block-text
							></textarea>
						</div>
					</div>
					<div class="flavor-agent-guidelines__block-actions">
						<button type="button" class="button button-secondary" data-guidelines-block-cancel hidden>
							<?php echo esc_html__( 'Cancel', 'flavor-agent' ); ?>
						</button>
						<button type="button" class="button button-primary" data-guidelines-block-save>
							<?php echo esc_html__( 'Add Block Guideline', 'flavor-agent' ); ?>
						</button>
					</div>
				</div>
			</div>
		</details>
		<?php
	}

	private static function render_guidelines_actions_panel(): void {
		?>
		<div class="flavor-agent-guidelines__actions-panel">
			<div class="flavor-agent-guidelines__actions-row">
				<button type="button" class="button button-secondary" data-guidelines-import-button>
					<?php echo esc_html__( 'Import JSON', 'flavor-agent' ); ?>
				</button>
				<button type="button" class="button button-secondary" data-guidelines-export-button>
					<?php echo esc_html__( 'Export JSON', 'flavor-agent' ); ?>
				</button>
				<input type="file" accept=".json,application/json" data-guidelines-file-input hidden />
			</div>
			<p class="description">
				<?php echo esc_html__( 'Import fills the form. Save Changes to persist.', 'flavor-agent' ); ?>
			</p>
		</div>
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
		$messages = State::get_section_status_blocks( $group, $state, $feedback );

		foreach ( $messages as $message ) {
			?>
			<div class="flavor-agent-settings-status flavor-agent-settings-status--<?php echo esc_attr( $message['tone'] ); ?>">
				<p><?php echo esc_html( $message['message'] ); ?></p>
			</div>
			<?php
		}
	}

	private static function render_pattern_backend_segmented_control( string $saved_backend ): void {
		$option  = Config::OPTION_PATTERN_RETRIEVAL_BACKEND;
		$hint_id = $option . '-preview-hint';
		$choices = [
			Config::PATTERN_BACKEND_QDRANT               => __( 'Qdrant', 'flavor-agent' ),
			Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH => __( 'Cloudflare AI Search', 'flavor-agent' ),
		];
		?>
		<fieldset
			class="flavor-agent-pattern-backend-segments"
			data-flavor-agent-pattern-backend-segments
			data-saved-backend="<?php echo esc_attr( $saved_backend ); ?>"
			aria-describedby="<?php echo esc_attr( $hint_id ); ?>"
		>
			<legend class="screen-reader-text">
				<?php echo esc_html__( 'Pattern storage backend', 'flavor-agent' ); ?>
			</legend>
			<?php foreach ( $choices as $value => $label ) : ?>
				<?php
				$is_saved   = $value === $saved_backend;
				$input_id   = $option . '-' . str_replace( '_', '-', $value );
				$segment_id = $input_id . '-segment';
				?>
				<label
					id="<?php echo esc_attr( $segment_id ); ?>"
					class="flavor-agent-pattern-backend-segment<?php echo $is_saved ? ' is-preview-selected' : ''; ?>"
					for="<?php echo esc_attr( $input_id ); ?>"
				>
					<input type="radio" class="flavor-agent-pattern-backend-segment__input" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $is_saved ); ?> />
					<span class="flavor-agent-pattern-backend-segment__label">
						<?php echo esc_html( $label ); ?>
					</span>
					<?php if ( $is_saved ) : ?>
						<span
							class="flavor-agent-pattern-backend-segment__pill"
							data-pattern-backend-active-pill
						>
							<?php echo esc_html__( 'Active', 'flavor-agent' ); ?>
						</span>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<p
			id="<?php echo esc_attr( $hint_id ); ?>"
			class="flavor-agent-pattern-backend-hint"
			data-pattern-backend-preview-hint
			aria-live="polite"
			hidden
		>
			<?php echo esc_html__( 'Save Changes to switch pattern storage.', 'flavor-agent' ); ?>
		</p>
		<?php
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function render_qdrant_pattern_storage_status_panel( array $state ): void {
		$configured = ! empty( $state['qdrant_configured'] );
		$tone       = $configured ? 'success' : 'warning';
		$message    = $configured
			? __( 'Qdrant pattern storage ready.', 'flavor-agent' )
			: __( 'Add Qdrant URL and API key to sync the pattern catalog.', 'flavor-agent' );
		?>
		<div class="flavor-agent-settings-status flavor-agent-settings-status--<?php echo esc_attr( $tone ); ?>">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $state
	 * @param array<string, mixed> $feedback
	 */
	private static function render_cloudflare_pattern_ai_search_status_panel( array $state, array $feedback ): void {
		$instance_id         = trim( (string) get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID, '' ) );
		$embedding_ready     = ! empty( $state['runtime_embedding']['configured'] );
		$storage_ready       = ! empty( $state['cloudflare_pattern_ai_search_configured'] );
		$provisioning        = is_array( $state['cloudflare_pattern_ai_search_provisioning'] ?? null )
			? $state['cloudflare_pattern_ai_search_provisioning']
			: [];
		$provisioning_status = (string) ( $provisioning['status'] ?? '' );
		$error_code          = self::latest_pattern_ai_search_error_code( $feedback );

		if ( '' === $error_code && 'error' === $provisioning_status ) {
			$error_code = (string) ( $provisioning['last_error_code'] ?? '' );
		}

		if ( $storage_ready ) {
			self::render_cloudflare_pattern_ai_search_status(
				'success',
				__( 'Managed pattern index ready.', 'flavor-agent' ),
				$instance_id
			);
			return;
		}

		if ( ! $embedding_ready ) {
			self::render_cloudflare_pattern_ai_search_status(
				'warning',
				__( 'Needs Cloudflare credentials from the Embedding Model section.', 'flavor-agent' ),
				$instance_id
			);
			return;
		}

		if ( 'provisioning' === $provisioning_status ) {
			self::render_cloudflare_pattern_ai_search_status(
				'warning',
				__( 'Managed pattern index is provisioning in the background. Refresh this page shortly.', 'flavor-agent' ),
				$instance_id
			);
			return;
		}

		if ( self::is_specific_pattern_ai_search_status_code( $error_code ) ) {
			self::render_cloudflare_pattern_ai_search_status(
				'error',
				sprintf(
					/* translators: %s: deterministic Cloudflare AI Search instance ID */
					__( 'Managed pattern index needs attention. Flavor Agent will not adopt %s until ownership and schema can be proven. Fix or remove the conflicting Cloudflare AI Search instance, then save settings again.', 'flavor-agent' ),
					PatternSearchInstanceManager::managed_instance_id()
				),
				$instance_id,
				[
					'error_code'    => $error_code,
					'error_message' => (string) ( $provisioning['last_error'] ?? '' ),
				]
			);
			return;
		}

		if ( ! empty( $state['cloudflare_pattern_ai_search_signature_mismatch'] ) ) {
			self::render_cloudflare_pattern_ai_search_status(
				'warning',
				__( 'Saved managed pattern index needs re-validation for the current Embedding Model credentials. Save settings again to re-validate.', 'flavor-agent' ),
				$instance_id
			);
			return;
		}

		if ( 'error' === $provisioning_status ) {
			self::render_cloudflare_pattern_ai_search_status(
				'error',
				__( 'Managed pattern index provisioning failed. Check the Cloudflare credentials from Embedding Model, then save settings again.', 'flavor-agent' ),
				$instance_id,
				[
					'error_code'    => (string) ( $provisioning['last_error_code'] ?? '' ),
					'error_message' => (string) ( $provisioning['last_error'] ?? '' ),
				]
			);
			return;
		}

		if ( '' !== $error_code ) {
			self::render_cloudflare_pattern_ai_search_status(
				'error',
				__( 'Managed pattern index needs attention.', 'flavor-agent' ),
				$instance_id,
				[
					'error_code' => $error_code,
				]
			);
			return;
		}

		self::render_cloudflare_pattern_ai_search_status(
			'warning',
			__( 'Create managed pattern index.', 'flavor-agent' ),
			$instance_id
		);
	}

	/**
	 * @param array{error_code?: string, error_message?: string} $details
	 */
	private static function render_cloudflare_pattern_ai_search_status( string $tone, string $message, string $instance_id, array $details = [] ): void {
		$advanced_details = [];

		if ( '' !== $instance_id ) {
			$advanced_details[] = sprintf(
				/* translators: %s: Cloudflare AI Search instance ID. */
				__( 'Instance ID: %s', 'flavor-agent' ),
				$instance_id
			);
		}

		$error_code = sanitize_key( (string) ( $details['error_code'] ?? '' ) );

		if ( '' !== $error_code ) {
			$advanced_details[] = sprintf(
				/* translators: %s: error code returned while provisioning Cloudflare AI Search. */
				__( 'Error code: %s', 'flavor-agent' ),
				$error_code
			);
		}

		$error_message = trim( sanitize_text_field( (string) ( $details['error_message'] ?? '' ) ) );

		if ( '' !== $error_message ) {
			$advanced_details[] = sprintf(
				/* translators: %s: error message returned while provisioning Cloudflare AI Search. */
				__( 'Error message: %s', 'flavor-agent' ),
				$error_message
			);
		}

		$advanced_attributes = [
			'data-flavor-agent-status-details' => 'cloudflare-pattern-ai-search',
		];

		?>
		<div class="flavor-agent-settings-status flavor-agent-settings-status--<?php echo esc_attr( $tone ); ?>">
			<p><?php echo esc_html( $message ); ?></p>
			<?php if ( [] !== $advanced_details ) : ?>
				<details<?php Utils::render_html_attributes( $advanced_attributes ); ?><?php echo 'error' === $tone ? ' open' : ''; ?>>
					<summary><?php echo esc_html__( 'Advanced details', 'flavor-agent' ); ?></summary>
					<?php foreach ( $advanced_details as $detail ) : ?>
						<p><?php echo esc_html( $detail ); ?></p>
					<?php endforeach; ?>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function latest_pattern_ai_search_error_code( array $feedback ): string {
		$feedback_code = self::latest_pattern_ai_search_error_code_from_entries(
			Feedback::get_feedback_message_entries( $feedback, Config::GROUP_PATTERNS )
		);

		if ( '' !== $feedback_code ) {
			return $feedback_code;
		}

		return self::latest_pattern_ai_search_error_code_from_entries(
			get_settings_errors( Config::OPTION_GROUP )
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $entries
	 */
	private static function latest_pattern_ai_search_error_code_from_entries( array $entries ): string {
		$fallback_code = '';

		foreach ( array_reverse( $entries ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$code = self::normalize_pattern_ai_search_error_code( (string) ( $entry['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			if ( self::is_specific_pattern_ai_search_status_code( $code ) ) {
				return $code;
			}

			if ( '' === $fallback_code ) {
				$fallback_code = $code;
			}
		}

		return $fallback_code;
	}

	private static function normalize_pattern_ai_search_error_code( string $code ): string {
		$code = sanitize_key( $code );

		if ( str_contains( $code, 'cloudflare_pattern_ai_search' ) ) {
			return $code;
		}

		return '';
	}

	private static function is_specific_pattern_ai_search_status_code( string $code ): bool {
		return in_array(
			$code,
			[
				'cloudflare_pattern_ai_search_incompatible_schema',
				'cloudflare_pattern_ai_search_owner_marker_missing',
				'cloudflare_pattern_ai_search_owner_marker_mismatch',
				'cloudflare_pattern_ai_search_embedding_model_mismatch',
			],
			true
		);
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

	private static function render_sync_panel( array $page_state, bool $has_feedback_error = false ): void {
		$state              = is_array( $page_state['pattern_state'] ?? null ) ? $page_state['pattern_state'] : PatternIndex::get_runtime_state();
		$saved_backend      = (string) ( $page_state['selected_pattern_backend'] ?? Config::PATTERN_BACKEND_QDRANT );
		$has_prerequisites  = ! empty( $page_state['patterns_ready'] );
		$status_label       = $has_prerequisites
			? State::get_pattern_sync_status_label( (string) $state['status'] )
			: self::get_pattern_sync_prerequisite_status_label( $page_state );
		$status_tone        = ! $has_prerequisites
			? 'warning'
			: ( ! empty( $state['last_error'] ) ? 'error' : State::get_pattern_sync_status_tone( (string) $state['status'] ) );
		$last_synced_label  = $state['last_synced_at'] ? (string) $state['last_synced_at'] : __( 'Not synced yet', 'flavor-agent' );
		$stale_reason_label = ! empty( $state['stale_reason'] )
			? State::get_pattern_sync_reason_label( (string) $state['stale_reason'] )
			: '';
		$collection_name    = $state['qdrant_collection']
			? (string) $state['qdrant_collection']
			: QdrantClient::get_collection_name(
				[
					'signature_hash' => (string) ( $state['embedding_signature'] ?? '' ),
				]
			);

		$prerequisite_message   = self::get_pattern_sync_prerequisite_message( $page_state );
		$prerequisite_id        = 'flavor-agent-sync-prerequisites';
		$sync_summary_sentence  = self::get_pattern_sync_status_sentence( $page_state );
		$is_syncing             = 'indexing' === sanitize_key( (string) ( $state['status'] ?? '' ) );
		$panel_attributes       = [
			'class'                        => 'flavor-agent-settings-subpanel flavor-agent-settings-subpanel--sync',
			'data-flavor-agent-sync-panel' => 'true',
		];
		$is_open                = $has_feedback_error || self::should_open_sync_panel( $page_state );
		$sync_button_attributes = [
			'type'          => 'button',
			'id'            => 'flavor-agent-sync-button',
			'class'         => 'button button-primary',
			'aria-disabled' => ( $has_prerequisites && ! $is_syncing ) ? 'false' : 'true',
		];

		if ( ! $has_prerequisites || $is_syncing ) {
			$sync_button_attributes['disabled'] = 'disabled';

			if ( ! $has_prerequisites && '' !== $prerequisite_message ) {
				$sync_button_attributes['aria-describedby'] = $prerequisite_id;
			} elseif ( $is_syncing ) {
				$sync_button_attributes['aria-describedby'] = 'flavor-agent-sync-summary';
			}
		}

		if ( $has_feedback_error ) {
			$panel_attributes['data-flavor-agent-validation-error'] = 'true';
		}
		?>
		<details<?php Utils::render_html_attributes( $panel_attributes ); ?><?php echo $is_open ? ' open' : ''; ?>>
			<summary class="flavor-agent-settings-subpanel__summary">
				<span><?php echo esc_html__( 'Sync Pattern Catalog', 'flavor-agent' ); ?></span>
				<?php self::render_badge( State::make_badge( $status_label, $status_tone ), [ 'data-pattern-status-badge' => 'panel' ] ); ?>
			</summary>
			<div
				class="flavor-agent-settings-subpanel__body flavor-agent-sync-panel"
				data-pattern-prerequisites-ready="<?php echo $has_prerequisites ? '1' : '0'; ?>"
				data-pattern-prerequisite-message="<?php echo esc_attr( $prerequisite_message ); ?>"
				data-pattern-sync-status="<?php echo esc_attr( sanitize_key( (string) ( $state['status'] ?? 'uninitialized' ) ) ); ?>"
				data-pattern-saved-backend="<?php echo esc_attr( $saved_backend ); ?>"
				data-pattern-backend-preview-matches-saved="1"
			>
				<p id="flavor-agent-sync-summary" class="flavor-agent-sync-panel__summary">
					<?php echo esc_html( $sync_summary_sentence ); ?>
				</p>
				<?php if ( '' !== $prerequisite_message ) : ?>
					<p id="<?php echo esc_attr( $prerequisite_id ); ?>" class="flavor-agent-sync-panel__prerequisites" data-pattern-prerequisite-copy>
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
						<?php echo esc_html__( 'Technical details', 'flavor-agent' ); ?>
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
					<button<?php Utils::render_html_attributes( $sync_button_attributes ); ?>>
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
		if ( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH === (string) ( $page_state['selected_pattern_backend'] ?? '' ) ) {
			if ( ! empty( $page_state['cloudflare_pattern_ai_search_configured'] ) ) {
				return '';
			}

			$provisioning = is_array( $page_state['cloudflare_pattern_ai_search_provisioning'] ?? null )
				? $page_state['cloudflare_pattern_ai_search_provisioning']
				: [];
			$status       = (string) ( $provisioning['status'] ?? '' );

			if ( 'provisioning' === $status ) {
				return __( 'Managed pattern index is provisioning in the background. Wait for validation before syncing.', 'flavor-agent' );
			}

			if ( ! empty( $page_state['cloudflare_pattern_ai_search_signature_mismatch'] ) ) {
				return __( 'Saved managed pattern index needs re-validation for the current Embedding Model credentials. Save settings again to re-validate.', 'flavor-agent' );
			}

			if ( 'error' === $status ) {
				return __( 'Managed pattern index provisioning failed. Check Cloudflare credentials in Embedding Model, then save settings again.', 'flavor-agent' );
			}

			return __( 'Save Cloudflare credentials in Embedding Model and create the managed pattern index before syncing.', 'flavor-agent' );
		}

		$embedding_ready = ! empty( $page_state['runtime_embedding']['configured'] );
		$qdrant_ready    = ! empty( $page_state['qdrant_configured'] );

		if ( $embedding_ready && $qdrant_ready ) {
			return '';
		}

		if ( ! $embedding_ready && ! $qdrant_ready ) {
			return __( 'Complete Embedding Model and Qdrant Pattern Storage before syncing.', 'flavor-agent' );
		}

		if ( ! $embedding_ready ) {
			return __( 'Complete Embedding Model before syncing.', 'flavor-agent' );
		}

		return __( 'Add the Qdrant URL and API key before syncing.', 'flavor-agent' );
	}

	private static function get_pattern_sync_prerequisite_status_label( array $page_state ): string {
		if ( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH === (string) ( $page_state['selected_pattern_backend'] ?? '' ) ) {
			if ( ! empty( $page_state['cloudflare_pattern_ai_search_configured'] ) ) {
				return __( 'Ready', 'flavor-agent' );
			}

			$provisioning = is_array( $page_state['cloudflare_pattern_ai_search_provisioning'] ?? null )
				? $page_state['cloudflare_pattern_ai_search_provisioning']
				: [];

			if ( 'provisioning' === (string) ( $provisioning['status'] ?? '' ) ) {
				return __( 'Provisioning', 'flavor-agent' );
			}

			return __( 'Needs pattern storage', 'flavor-agent' );
		}

		$embedding_ready = ! empty( $page_state['runtime_embedding']['configured'] );
		$qdrant_ready    = ! empty( $page_state['qdrant_configured'] );

		if ( ! $embedding_ready && ! $qdrant_ready ) {
			return __( 'Needs model & storage', 'flavor-agent' );
		}

		if ( ! $embedding_ready ) {
			return __( 'Needs embedding model', 'flavor-agent' );
		}

		return __( 'Needs pattern storage', 'flavor-agent' );
	}

	private static function get_pattern_sync_status_sentence( array $page_state ): string {
		$state = is_array( $page_state['pattern_state'] ?? null ) ? $page_state['pattern_state'] : [];

		if ( '' !== self::get_pattern_sync_prerequisite_message( $page_state ) ) {
			return __( 'Pattern recommendations stay unavailable until setup is complete.', 'flavor-agent' );
		}

		if ( ! empty( $state['last_error'] ) ) {
			return __( 'Pattern recommendations need attention before use.', 'flavor-agent' );
		}

		return match ( (string) ( $state['status'] ?? 'uninitialized' ) ) {
			'ready' => __( 'Pattern recommendations are ready.', 'flavor-agent' ),
			'stale' => __( 'Pattern recommendations are available but out of date.', 'flavor-agent' ),
			'indexing' => __( 'Pattern recommendations are syncing now.', 'flavor-agent' ),
			default => __( 'Pattern recommendations stay unavailable until you sync the catalog.', 'flavor-agent' ),
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
		<div<?php Utils::render_html_attributes( $metric_attributes ); ?>>
			<p class="flavor-agent-sync-panel__metric-label">
				<?php echo esc_html( $label ); ?>
			</p>
			<p<?php Utils::render_html_attributes( $value_attributes ); ?>>
				<?php echo esc_html( $value ); ?>
			</p>
		</div>
		<?php
	}

	private function __construct() {
	}
}
