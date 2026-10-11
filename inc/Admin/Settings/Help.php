<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

use FlavorAgent\Cloudflare\AISearchClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Help {

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

	/**
	 * @return array<int, array{id: string, title: string, content: string, priority: int}>
	 */
	public static function get_contextual_help_tabs(): array {
		$docs_grounding_enabled = AISearchClient::is_enabled();

		return [
			[
				'id'       => 'flavor-agent-overview',
				'title'    => __( 'Overview', 'flavor-agent' ),
				'content'  => implode(
					'',
					[
						'<p>' . esc_html__( 'Flavor Agent mediates AI changes through one governed loop: AI proposes; WordPress approves. This page configures the plugin-owned backends behind that loop.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Use Connectors for text generation. Flavor Agent shows the active chat path here.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Enable Flavor Agent in Settings > AI and configure a text-generation provider in Connectors. Then use Flavor Agent in the block editor: open a post, select a block, and open AI Recommendations in its settings. Opening the editor does not request recommendations, apply changes, or publish content.', 'flavor-agent' ) . '</p>',
						'<p>' . (
							$docs_grounding_enabled
								? esc_html__( 'Use this page for embedding credentials, pattern storage, developer-doc grounding limits, Guidelines, and AI Activity logging controls.', 'flavor-agent' )
								: esc_html__( 'Use this page for embedding credentials, pattern storage, Developer Docs status, Guidelines, and AI Activity logging controls.', 'flavor-agent' )
						) . '</p>',
						'<p>' . esc_html__( 'Shared Guidelines take precedence when they contain content. The Guidelines section identifies the active source and shows its guidance. Legacy fields, imports, exports, and Save Changes edit only the Flavor Agent fallback.', 'flavor-agent' ) . '</p>',
					]
				),
				'priority' => 10,
			],
			[
				'id'       => 'flavor-agent-configuration',
				'title'    => __( 'Models & Storage', 'flavor-agent' ),
				'content'  => implode(
					'',
					[
						'<p>' . esc_html__( 'The Cloudflare account, token, and model support pattern storage setup. Save Changes sends an embedding validation request; Cloudflare AI Search manages embeddings for the pattern catalog.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Configured means settings are present, not that the service is currently reachable. Changed credentials are validated when saved. Pattern Sync reports the latest index result; checking status may continue an already queued sync.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Cloudflare AI Search is the pattern catalog store. Save Cloudflare credentials to provision the managed index, then sync the pattern catalog.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Private AI Search pattern storage reuses the Cloudflare account and token from the Embedding Model section. Saving valid Cloudflare settings creates or revalidates a dedicated pattern index owned by Flavor Agent.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'The AI Search match threshold filters weaker pattern matches.', 'flavor-agent' ) . '</p>',
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
						'<p>' . (
							$docs_grounding_enabled
								? esc_html__( 'Developer Docs use the built-in developer.wordpress.org grounding path. Recommendations still run without grounding when the search backend is unreachable.', 'flavor-agent' )
								: esc_html__( 'Developer Docs grounding is temporarily disabled in this release by release policy. Recommendations remain available.', 'flavor-agent' )
						) . '</p>',
						'<p>' . esc_html__( 'The Developer Docs group shows compact runtime status. Detailed grounding diagnostics, fingerprints, and request history belong in AI Activity entries and validation logs rather than the primary settings form.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Pattern Sync stays unavailable until the selected storage path is configured. The sync panel shows stale reasons, last errors, and technical index details.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Save changed embedding or storage settings before starting Pattern Sync. If monitoring shows Status unavailable, the last known result is qualified: use Check sync status to reconnect before starting another sync. A failed request does not prove the background sync stopped.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Shared Guidelines take precedence when they contain content. Use the registered shared Guidelines management screen to change them; this page keeps the legacy fallback separate.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Guidelines import fills the fallback form. Save Changes persists those values, and export includes the current fallback form, including unsaved edits, in the Gutenberg-compatible guideline_categories JSON shape.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'AI Activity Dual Logging keeps Flavor Agent request diagnostics alongside core AI Request Logs when core logging is enabled.', 'flavor-agent' ) . '</p>',
					]
				),
				'priority' => 30,
			],
		];
	}

	public static function get_contextual_help_sidebar(): string {
		$connectors_url  = Utils::sanitize_url_value( admin_url( 'options-connectors.php' ) );
		$activity_url    = Utils::sanitize_url_value( admin_url( 'options-general.php?page=flavor-agent-activity' ) );
		$editor_url      = State::get_editor_handoff_url();
		$ai_settings_url = State::get_ai_settings_url();

		return implode(
			'',
			[
				'<p><strong>' . esc_html__( 'Quick Links', 'flavor-agent' ) . '</strong></p>',
				'' !== $editor_url ? sprintf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( $editor_url ),
					esc_html__( 'Open Post Editor', 'flavor-agent' )
				) : '',
				'' !== $ai_settings_url ? sprintf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( $ai_settings_url ),
					esc_html__( 'Open AI Settings', 'flavor-agent' )
				) : '',
				sprintf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( $connectors_url ),
					esc_html__( 'Open Connectors', 'flavor-agent' )
				),
				sprintf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( $activity_url ),
					esc_html__( 'Open Activity Log', 'flavor-agent' )
				),
			]
		);
	}

	private function __construct() {
	}
}
