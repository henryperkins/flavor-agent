<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

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
		return [
			[
				'id'       => 'flavor-agent-overview',
				'title'    => __( 'Overview', 'flavor-agent' ),
				'content'  => implode(
					'',
					[
						'<p>' . esc_html__( 'This screen keeps inline copy short so the form stays focused on live settings and status.', 'flavor-agent' ) . '</p>',
						'<ol>',
						'<li>' . esc_html__( 'Configure chat first. Settings > Connectors is the primary path and the only required section.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Add Pattern Recommendations if you want vector-backed pattern search and sync.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Use Docs Grounding for grounding limits, diagnostics, and any Cloudflare override values.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Use Guidelines for core-first site, writing, image, and block notes; legacy fields stay available for migration.', 'flavor-agent' ) . '</li>',
						'</ol>',
						'<p>' . esc_html__( 'Use Activity Log to review requests, sync runs, and diagnostics after saving changes.', 'flavor-agent' ) . '</p>',
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
						'<p>' . esc_html__( 'Use Settings > Connectors for shared chat credentials and provider selection that Flavor Agent prefers at runtime.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Use this screen for Azure or OpenAI Native embeddings settings, Qdrant, sync tuning, docs grounding limits, and guidelines migration tooling.', 'flavor-agent' ) . '</p>',
						'<ul>',
						'<li>' . esc_html__( 'Azure and OpenAI Native fields on this page configure plugin-owned embeddings. Chat credentials and text generation live in Settings > Connectors.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'OpenAI Native can use the plugin override, Settings > Connectors, or OPENAI_API_KEY. The active source is shown inline.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Pattern Sync needs both an embeddings backend and Qdrant before it can run.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Docs Grounding uses the built-in public developer.wordpress.org endpoint by default.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Cloudflare override fields are only for older installs or explicit custom-endpoint use.', 'flavor-agent' ) . '</li>',
						'</ul>',
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
						'<p>' . esc_html__( 'When chat is unavailable, configure a text-generation provider in Settings > Connectors.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Azure and OpenAI Native settings on this page do not provide chat; they only serve plugin-owned embeddings until core exposes an embeddings provider path.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Pattern Sync stays unavailable until both an embeddings backend and Qdrant are configured. The sync panel explains stale reasons, technical details, and the current index state.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Docs Grounding diagnostics summarize runtime grounding health, warm-queue activity, and the last docs prewarm run.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Guidelines import fills the legacy form first. Save Changes persists imported values, and export uses the Gutenberg-compatible guideline_categories JSON shape for migration and rollback.', 'flavor-agent' ) . '</p>',
					]
				),
				'priority' => 30,
			],
		];
	}

	public static function get_contextual_help_sidebar(): string {
		$connectors_url = Utils::sanitize_url_value( admin_url( 'options-connectors.php' ) );
		$activity_url   = Utils::sanitize_url_value( admin_url( 'options-general.php?page=flavor-agent-activity' ) );

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

	private function __construct() {
	}
}
