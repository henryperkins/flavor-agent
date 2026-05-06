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
						'<p>' . esc_html__( 'Use Connectors for text generation. Flavor Agent shows the active chat path here.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Use this page for embedding credentials, pattern storage, developer-doc grounding limits, Guidelines, and beta feature toggles.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'When core Guidelines are available, Flavor Agent reads them first. Legacy fields remain available for migration and rollback.', 'flavor-agent' ) . '</p>',
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
						'<p>' . esc_html__( 'Embedding credentials power semantic matching, including Qdrant pattern recommendations.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Pattern Storage chooses where the pattern catalog is indexed. Qdrant uses the configured Embedding Model.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Private AI Search pattern storage reuses the Embedding Model account and token, then only needs a unique pattern index name.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Qdrant and AI Search scores use different scales, so tune thresholds separately.', 'flavor-agent' ) . '</p>',
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
						'<p>' . esc_html__( 'Developer Docs use the built-in developer.wordpress.org grounding path. Runtime warnings identify grounding, warm queue, or prewarm states that need attention.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Pattern Sync stays unavailable until the selected storage path is configured. The sync panel shows stale reasons, last errors, and technical index details.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'When core Guidelines are available, Flavor Agent reads them first.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Guidelines import fills the form. Save Changes persists imported values, and export uses the Gutenberg-compatible guideline_categories JSON shape.', 'flavor-agent' ) . '</p>',
						'<p>' . esc_html__( 'Structural block actions are beta controls. Leave them off unless testing review-first insert and replace flows.', 'flavor-agent' ) . '</p>',
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
