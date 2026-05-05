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
						'<li>' . esc_html__( 'AI Model shows the text-generation provider configured in Settings > Connectors.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Embedding Model is configured once for Flavor Agent semantic features.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Patterns choose storage only; Qdrant uses the configured Embedding Model.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Developer Docs are already available through Flavor Agent\'s built-in public endpoint.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Use Guidelines for core-first site, writing, image, and block notes; legacy fields stay available for migration.', 'flavor-agent' ) . '</li>',
						'</ol>',
						'<p>' . esc_html__( 'Use Activity Log to review requests, sync runs, and diagnostics after saving changes.', 'flavor-agent' ) . '</p>',
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
						'<p>' . esc_html__( 'Use Settings > Connectors for shared chat credentials and text-generation provider selection.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Use this screen for the Flavor Agent embedding model, pattern storage, sync tuning, developer-doc source limits, and guidelines migration tooling.', 'flavor-agent' ) . '</p>',
						'<ul>',
						'<li>' . esc_html__( 'Cloudflare Workers AI fields configure the Flavor Agent embedding model. Chat credentials and text generation live in Settings > Connectors.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Saved older embedding-provider values are ignored by the runtime and overwritten with Cloudflare Workers AI on the next settings save.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Pattern Sync needs the selected Pattern Storage to be ready. Qdrant storage also needs the Embedding Model.', 'flavor-agent' ) . '</li>',
						'<li>' . esc_html__( 'Developer Docs uses Flavor Agent\'s built-in public developer.wordpress.org endpoint.', 'flavor-agent' ) . '</li>',
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
						'<p>' . esc_html__( 'Embedding settings on this page do not provide chat. They serve Flavor Agent semantic features.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Pattern Sync stays unavailable until the selected storage path is configured. Qdrant storage also needs the Embedding Model. The sync panel explains stale reasons, technical details, and the current index state.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Developer Docs diagnostics summarize runtime grounding health, warm-queue activity, and the last docs prewarm run.', 'flavor-agent' ) . '</p>',
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
