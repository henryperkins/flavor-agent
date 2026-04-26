<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\Cloudflare\AISearchClient;
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
							<?php echo esc_html__( 'Configure site-specific settings here. Use Help for setup reference and troubleshooting.', 'flavor-agent' ); ?>
						</p>
						<div class="flavor-agent-admin-hero__actions">
							<a class="button button-primary" href="<?php echo esc_attr( Utils::sanitize_url_value( $primary_url ) ); ?>">
								<?php echo esc_html( $primary_label ); ?>
							</a>
							<a class="button button-secondary" href="<?php echo esc_attr( Utils::sanitize_url_value( $secondary_url ) ); ?>">
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
						__( '1. Chat Provider', 'flavor-agent' ),
						State::get_group_card_meta( Config::GROUP_CHAT, $state ),
						$open_group,
						static function () use ( $state, $feedback, $connectors_url ): void {
							self::render_chat_provider_group( $state, $feedback, $connectors_url );
						}
					);
					self::render_settings_section_group(
						Config::GROUP_PATTERNS,
						__( '2. Pattern Recommendations', 'flavor-agent' ),
						State::get_group_card_meta( Config::GROUP_PATTERNS, $state ),
						$open_group,
						static function () use ( $state, $feedback ): void {
							self::render_pattern_recommendations_group( $state, $feedback );
						}
					);
					self::render_settings_section_group(
						Config::GROUP_DOCS,
						__( '3. Docs Grounding', 'flavor-agent' ),
						State::get_group_card_meta( Config::GROUP_DOCS, $state ),
						$open_group,
						static function () use ( $state, $feedback ): void {
							self::render_docs_grounding_group( $state, $feedback );
						}
					);
					self::render_settings_section_group(
						Config::GROUP_GUIDELINES,
						__( '4. Guidelines', 'flavor-agent' ),
						State::get_group_card_meta( Config::GROUP_GUIDELINES, $state ),
						$open_group,
						static function () use ( $state, $feedback ): void {
							self::render_guidelines_group( $state, $feedback );
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
			esc_html( State::format_openai_native_key_source_label( Provider::native_effective_api_key_source() ) )
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

	public static function render_guidelines_section(): void {
		// Guidance now lives in the screen Help panel to keep the page focused on controls.
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
			$summary_lines[] = __( 'Chat provider saved.', 'flavor-agent' );
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
			$summary_lines[] = __( 'Docs grounding settings saved.', 'flavor-agent' );
		}

		if (
			! empty( $changed_sections[ Config::GROUP_GUIDELINES ] ) &&
			! Feedback::feedback_group_has_tone( $feedback, Config::GROUP_GUIDELINES, 'error' )
		) {
			$summary_lines[] = __( 'Guidelines saved.', 'flavor-agent' );
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

	private static function render_setup_status_cards( array $state ): void {
		$chat_status       = ! empty( $state['runtime_chat']['configured'] )
			? State::make_badge( __( 'Ready', 'flavor-agent' ), 'success' )
			: State::make_badge( __( 'Needs setup', 'flavor-agent' ), 'warning' );
		$pattern_status    = State::get_pattern_overview_status( $state );
		$docs_status       = State::get_docs_overview_status( $state );
		$guidelines_status = State::get_guidelines_overview_status( $state );
		?>
		<div class="flavor-agent-settings__glance">
			<?php
			self::render_setup_status_card(
				__( 'Chat Provider', 'flavor-agent' ),
				$chat_status['label'],
				$chat_status['tone'],
				'#' . State::get_section_dom_id( Config::GROUP_CHAT )
			);
			self::render_setup_status_card(
				__( 'Pattern Recommendations', 'flavor-agent' ),
				$pattern_status['label'],
				$pattern_status['tone'],
				'#' . State::get_section_dom_id( Config::GROUP_PATTERNS ),
				[
					'data-pattern-overview-status' => 'true',
				]
			);
			self::render_setup_status_card(
				__( 'Docs Grounding', 'flavor-agent' ),
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
	 */
	private static function render_settings_section_group(
		string $group,
		string $title,
		array $meta,
		string $open_group,
		callable $renderer
	): void {
		$dom_id  = State::get_section_dom_id( $group );
		$is_open = $open_group === $group;
		?>
		<section class="flavor-agent-settings-section" id="<?php echo esc_attr( $dom_id ); ?>">
			<details class="flavor-agent-settings-section__panel" data-flavor-agent-section="<?php echo esc_attr( $group ); ?>"<?php echo $is_open ? ' open' : ''; ?>>
				<summary class="flavor-agent-settings-section__summary">
					<span class="flavor-agent-settings-section__summary-main">
						<span class="flavor-agent-settings-section__title" role="heading" aria-level="2">
							<?php echo esc_html( $title ); ?>
						</span>
						<?php if ( '' !== (string) $meta['summary'] ) : ?>
							<span class="flavor-agent-settings-section__summary-text">
								<?php echo esc_html( $meta['summary'] ); ?>
							</span>
						<?php endif; ?>
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
	private static function render_chat_provider_group( array $state, array $feedback, string $connectors_url ): void {
		self::render_section_status_blocks( Config::GROUP_CHAT, $state, $feedback );
		self::render_registered_section_callback( 'flavor_agent_openai_provider' );
		self::render_registered_fields_table(
			'flavor_agent_openai_provider',
			[
				Provider::OPTION_NAME,
			]
		);
		?>
		<p class="description">
			<?php echo esc_html__( 'Shared chat credentials live in Settings > Connectors. The Azure and OpenAI fields below stay available as legacy direct fallback for chat and as plugin-owned embeddings configuration for pattern sync.', 'flavor-agent' ); ?>
		</p>
		<?php

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
				<a class="button button-secondary" href="<?php echo esc_attr( Utils::sanitize_url_value( $connectors_url ) ); ?>">
					<?php echo esc_html__( 'Open Connectors', 'flavor-agent' ); ?>
				</a>
			</p>
			<?php
			self::render_azure_direct_settings_fields();
			self::render_openai_native_direct_settings_fields();
			return;
		}

		if ( Provider::is_azure( (string) $state['selected_provider'] ) ) {
			self::render_azure_direct_settings_fields();
			return;
		}

		self::render_openai_native_direct_settings_fields();
	}

	private static function render_azure_direct_settings_fields(): void {
		self::render_subsection_heading(
			__( 'Legacy Direct Azure Settings', 'flavor-agent' ),
			__( 'Use these plugin-owned fields for direct Azure chat fallback or pattern embeddings.', 'flavor-agent' )
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
	}

	private static function render_openai_native_direct_settings_fields(): void {
		self::render_subsection_heading(
			__( 'Legacy Direct OpenAI Settings', 'flavor-agent' ),
			__( 'Use these plugin-owned fields for direct OpenAI chat fallback or pattern embeddings.', 'flavor-agent' )
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
		self::render_section_status_blocks( Config::GROUP_PATTERNS, $state, $feedback );
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
		self::render_section_status_blocks( Config::GROUP_DOCS, $state, $feedback );
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

	/**
	 * @param array<string, mixed> $feedback
	 */
	private static function render_guidelines_group( array $state, array $feedback ): void {
		self::render_section_status_blocks( Config::GROUP_GUIDELINES, $state, $feedback );
		self::render_registered_section_callback( 'flavor_agent_guidelines' );
		?>
		<div class="flavor-agent-guidelines" data-flavor-agent-guidelines-root>
			<div class="flavor-agent-guidelines__notice" data-guidelines-notice aria-live="polite"></div>
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
			self::render_guidelines_blocks_panel();
			self::render_guidelines_actions_panel();
			?>
		</div>
		<?php
	}

	private static function render_guidelines_blocks_panel(): void {
		$block_guidelines = Guidelines::get_block_guidelines();
		$block_options    = Guidelines::get_content_block_options();
		$guidelines_json  = Utils::encode_json_payload( $block_guidelines );
		$options_json     = Utils::encode_json_payload( $block_options, '[]', JSON_HEX_TAG );
		?>
		<details class="flavor-agent-settings-subpanel flavor-agent-guidelines__blocks-panel"<?php echo [] !== $block_guidelines ? ' open' : ''; ?>>
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
				<script type="application/json" data-guidelines-block-options><?php echo $options_json; ?></script>
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

	private static function render_cloudflare_legacy_override_panel(): void {
		$has_saved_legacy_values = Validation::has_saved_cloudflare_legacy_values();
		?>
		<details class="flavor-agent-settings-subpanel"<?php echo $has_saved_legacy_values ? ' open' : ''; ?>>
			<summary class="flavor-agent-settings-subpanel__summary">
				<?php echo esc_html__( 'Cloudflare Override', 'flavor-agent' ); ?>
			</summary>
			<div class="flavor-agent-settings-subpanel__body">
				<p class="description">
					<?php echo esc_html__( 'Older installs or explicit custom-endpoint overrides only. Leave these blank to use the built-in public docs endpoint.', 'flavor-agent' ); ?>
				</p>
				<?php if ( $has_saved_legacy_values ) : ?>
					<p class="description">
						<?php echo esc_html__( 'Saved override values are present. Clear all three fields to stop using the override.', 'flavor-agent' ); ?>
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
		$messages = State::get_section_status_blocks( $group, $state, $feedback );

		foreach ( $messages as $message ) {
			?>
			<div class="flavor-agent-settings-status flavor-agent-settings-status--<?php echo esc_attr( $message['tone'] ); ?>">
				<p><?php echo esc_html( $message['message'] ); ?></p>
			</div>
			<?php
		}
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

	private static function render_runtime_grounding_diagnostics(): void {
		if ( ! AISearchClient::is_configured() ) {
			return;
		}

		$state  = AISearchClient::get_runtime_state();
		$label  = State::get_runtime_grounding_status_label( (string) $state['status'] );
		$served = '';

		if ( '' !== (string) $state['lastServedAt'] ) {
			$served = sprintf(
				/* translators: 1: fallback type, 2: served mode, 3: timestamp */
				__( 'Last served guidance: %1$s via %2$s at %3$s UTC', 'flavor-agent' ),
				State::get_runtime_grounding_fallback_label( (string) $state['lastFallbackType'] ),
				State::get_runtime_grounding_mode_label( (string) $state['lastServedMode'] ),
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
				State::get_runtime_grounding_mode_label( (string) $state['lastTrustedSuccessMode'] )
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
						esc_html( State::get_runtime_grounding_mode_label( (string) $state['lastErrorMode'] ) ),
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

	private static function render_sync_panel( array $page_state ): void {
		$state                 = is_array( $page_state['pattern_state'] ?? null ) ? $page_state['pattern_state'] : PatternIndex::get_runtime_state();
		$has_prerequisites     = ! empty( $page_state['patterns_ready'] );
		$status_label          = $has_prerequisites
			? State::get_pattern_sync_status_label( (string) $state['status'] )
			: __( 'Needs setup', 'flavor-agent' );
		$status_tone           = ! $has_prerequisites
			? 'warning'
			: ( ! empty( $state['last_error'] ) ? 'error' : State::get_pattern_sync_status_tone( (string) $state['status'] ) );
		$last_synced_label     = $state['last_synced_at'] ? (string) $state['last_synced_at'] : __( 'Not synced yet', 'flavor-agent' );
		$stale_reason_label    = ! empty( $state['stale_reason'] )
			? State::get_pattern_sync_reason_label( (string) $state['stale_reason'] )
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
				<?php self::render_badge( State::make_badge( $status_label, $status_tone ), [ 'data-pattern-status-badge' => 'panel' ] ); ?>
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
					<button
						type="button"
						id="flavor-agent-sync-button"
						class="button button-primary"
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
			return __( 'Finish embeddings setup in Chat Provider and add Qdrant before syncing the pattern index.', 'flavor-agent' );
		}

		if ( ! $embedding_ready ) {
			return __( 'Finish embeddings setup in Chat Provider before syncing the pattern index.', 'flavor-agent' );
		}

		return __( 'Add the Qdrant URL and API key before syncing the pattern index.', 'flavor-agent' );
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
