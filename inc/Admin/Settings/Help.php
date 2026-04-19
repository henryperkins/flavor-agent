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
						'<li>' . esc_html__( 'Configure Chat Provider first. It is the only required section.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Add Pattern Recommendations if you want vector-backed pattern search and sync.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Use Docs Grounding for grounding limits, diagnostics, and any Cloudflare override values.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Use Guidelines for plugin-owned site, writing, image, and block notes.', 'flavor-agent' ) . '</li>',
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
						'<p>' . esc_html__( 'Use Settings > Connectors for shared credentials used by connector-backed providers.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Use this screen for direct Azure or OpenAI Native settings, Qdrant, sync tuning, docs grounding limits, and plugin-owned guidelines.', 'flavor-agent' ) . '</p>',
						'<ul>',
						'<li>' . esc_html__( 'Azure and OpenAI Native fields on this page are only for direct provider configuration.', 'flavor-agent' ) . '</li>',
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
						'<p>' . esc_html__( 'If the selected chat provider is incomplete, Flavor Agent can fall back to another configured chat path until you finish setup.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Pattern Sync stays unavailable until both an embeddings backend and Qdrant are configured. The sync panel explains stale reasons, technical details, and the current index state.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Docs Grounding diagnostics summarize runtime grounding health, warm-queue activity, and the last docs prewarm run.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Guidelines import fills the form first. Save Changes persists imported values, and export uses the Gutenberg-compatible guideline_categories JSON shape.', 'flavor-agent' ) . '</p>',
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
