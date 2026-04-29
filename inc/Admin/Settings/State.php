<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Guidelines;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class State {

	/**
	 * @return array<string, mixed>
	 */
	public static function get_page_state(): array {
		$selected_provider       = Provider::get();
		$selected_chat           = Provider::chat_configuration( $selected_provider );
		$runtime_chat            = Provider::chat_configuration();
		$selected_embedding      = Provider::embedding_configuration( $selected_provider );
		$runtime_embedding       = Provider::embedding_configuration();
		$qdrant_configured       = '' !== (string) get_option( 'flavor_agent_qdrant_url', '' )
			&& '' !== (string) get_option( 'flavor_agent_qdrant_key', '' );
		$pattern_state           = PatternIndex::get_runtime_state();
		$patterns_ready_for_sync = PatternIndex::recommendation_backends_configured();
		$docs_configured         = AISearchClient::is_configured();
		$prewarm_state           = AISearchClient::get_prewarm_state();
		$runtime_docs_grounding  = AISearchClient::get_runtime_state();
		$guidelines_enabled      = Guidelines::has_any();
		$guidelines_storage      = Guidelines::storage_status();

		return [
			'selected_provider'      => $selected_provider,
			'selected_chat'          => $selected_chat,
			'runtime_chat'           => $runtime_chat,
			'selected_embedding'     => $selected_embedding,
			'runtime_embedding'      => $runtime_embedding,
			'qdrant_configured'      => $qdrant_configured,
			'pattern_state'          => $pattern_state,
			'patterns_ready'         => $patterns_ready_for_sync,
			'docs_configured'        => $docs_configured,
			'prewarm_state'          => $prewarm_state,
			'runtime_docs_grounding' => $runtime_docs_grounding,
			'guidelines_enabled'     => $guidelines_enabled,
			'guidelines_storage'     => $guidelines_storage,
		];
	}

	public static function determine_default_open_group( array $state ): string {
		if ( empty( $state['runtime_chat']['configured'] ) ) {
			return Config::GROUP_CHAT;
		}

		if (
			! empty( $state['pattern_state']['last_error'] ) ||
			'stale' === (string) ( $state['pattern_state']['status'] ?? '' ) ||
			( ! empty( $state['patterns_ready'] ) && 'uninitialized' === (string) ( $state['pattern_state']['status'] ?? '' ) ) ||
			self::pattern_backends_partially_configured( $state )
		) {
			return Config::GROUP_PATTERNS;
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
			return Config::GROUP_DOCS;
		}

		return Config::GROUP_CHAT;
	}

	public static function get_section_dom_id( string $group ): string {
		return 'flavor-agent-section-' . sanitize_html_class( $group );
	}

	/**
	 * @return array{summary: string, badges: array<int, array{label: string, tone: string}>, status: array{label: string, tone: string}, open: bool}
	 */
	public static function get_group_card_meta( string $group, array $state ): array {
		$pattern_status    = self::get_pattern_overview_status( $state );
		$docs_status       = self::get_docs_overview_status( $state );
		$guidelines_status = self::get_guidelines_overview_status( $state );

		return match ( $group ) {
			Config::GROUP_CHAT => [
				'summary' => __( 'Required. Chat is handled by Settings > Connectors; this screen configures embeddings and supporting services.', 'flavor-agent' ),
				'badges'  => [
					self::make_badge( __( 'Required', 'flavor-agent' ), 'neutral' ),
					self::make_badge( self::runtime_chat_label( $state ), 'accent' ),
				],
				'status'  => self::make_badge(
					empty( $state['runtime_chat']['configured'] )
						? __( 'Needs setup', 'flavor-agent' )
						: (
							! empty( $state['selected_chat']['configured'] ) || self::runtime_chat_uses_connectors( $state )
								? __( 'Ready', 'flavor-agent' )
								: __( 'Partial', 'flavor-agent' )
						),
					empty( $state['runtime_chat']['configured'] )
						? 'warning'
						: (
							! empty( $state['selected_chat']['configured'] ) || self::runtime_chat_uses_connectors( $state )
								? 'success'
								: 'warning'
						)
				),
				'open'    => false,
			],
			Config::GROUP_PATTERNS => [
				'summary' => __( 'Optional. Add vector search for pattern recommendations.', 'flavor-agent' ),
				'badges'  => [
					self::make_badge( __( 'Optional', 'flavor-agent' ), 'neutral' ),
				],
				'status'  => $pattern_status,
				'open'    => false,
			],
			Config::GROUP_DOCS => [
				'summary' => __( 'Optional. Ground responses with developer.wordpress.org docs.', 'flavor-agent' ),
				'badges'  => [
					self::make_badge( __( 'Optional', 'flavor-agent' ), 'neutral' ),
				],
				'status'  => $docs_status,
				'open'    => false,
			],
			Config::GROUP_GUIDELINES => [
				'summary' => ! empty( $state['guidelines_storage']['core_available'] )
					? __( 'Optional. Read from core Guidelines when available; legacy fields remain migration tooling.', 'flavor-agent' )
					: __( 'Optional. Store plugin-owned site, writing, image, and block guidance.', 'flavor-agent' ),
				'badges'  => array_values(
					array_filter(
						[
							self::make_badge( __( 'Optional', 'flavor-agent' ), 'neutral' ),
							! empty( $state['guidelines_storage']['core_available'] )
								? self::make_badge( __( 'Core bridge', 'flavor-agent' ), 'accent' )
								: null,
						]
					)
				),
				'status'  => $guidelines_status,
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
	public static function get_pattern_overview_status( array $state ): array {
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
	public static function get_docs_overview_status( array $state ): array {
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

	/**
	 * @return array{label: string, tone: string}
	 */
	public static function get_guidelines_overview_status( array $state ): array {
		if ( empty( $state['guidelines_enabled'] ) ) {
			return self::make_badge( __( 'Off', 'flavor-agent' ), 'neutral' );
		}

		return self::make_badge( __( 'On', 'flavor-agent' ), 'success' );
	}

	/**
	 * @param array<string, mixed> $feedback
	 * @return array<int, array{tone: string, message: string}>
	 */
	public static function get_section_status_blocks( string $group, array $state, array $feedback ): array {
		$status_blocks = Feedback::get_feedback_message_entries( $feedback, $group );

		if ( Config::GROUP_CHAT === $group ) {
			if ( empty( $state['runtime_chat']['configured'] ) ) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => __( 'No chat path is ready yet. Configure a text-generation provider in Settings > Connectors.', 'flavor-agent' ),
				];
			} elseif (
				self::runtime_chat_uses_connectors( $state )
				&& ! Provider::is_connector( (string) $state['selected_provider'] )
			) {
				$status_blocks[] = [
					'tone'    => 'accent',
					'message' => sprintf(
						/* translators: 1: runtime chat label, 2: selected provider label */
						__( '%1$s is currently handling chat through Settings > Connectors. The %2$s settings on this page only configure embeddings.', 'flavor-agent' ),
						self::runtime_chat_label( $state ),
						Provider::label( (string) $state['selected_provider'] )
					),
				];
			} elseif ( empty( $state['selected_chat']['configured'] ) ) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => sprintf(
						/* translators: 1: provider label, 2: runtime chat label */
						__( '%1$s is selected, but Flavor Agent is currently using %2$s for chat until the selected path is available.', 'flavor-agent' ),
						Provider::label( (string) $state['selected_provider'] ),
						self::runtime_chat_label( $state )
					),
				];
			}

			if (
				Provider::is_native( (string) $state['selected_provider'] ) &&
				'' === Provider::native_effective_api_key()
			) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => __( 'OpenAI Native embeddings are selected, but no API key source is available yet. Add a plugin key, Settings > Connectors key, or OPENAI_API_KEY.', 'flavor-agent' ),
				];
			}
		}

		if ( Config::GROUP_PATTERNS === $group ) {
			if ( ! empty( $state['qdrant_configured'] ) && empty( $state['runtime_embedding']['configured'] ) ) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => __( 'Qdrant is configured, but pattern recommendations still need a configured embeddings backend.', 'flavor-agent' ),
				];
			} elseif ( empty( $state['qdrant_configured'] ) && ! empty( $state['runtime_embedding']['configured'] ) ) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => __( 'Embeddings are ready, but pattern recommendations still need a Qdrant connection before you can sync.', 'flavor-agent' ),
				];
			}
		}

		if (
			Config::GROUP_DOCS === $group &&
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

	/**
	 * @return array{label: string, tone: string}
	 */
	public static function make_badge( string $label, string $tone = 'neutral' ): array {
		return [
			'label' => $label,
			'tone'  => $tone,
		];
	}

	public static function get_pattern_sync_status_label( string $status ): string {
		return match ( $status ) {
			'indexing'      => __( 'Syncing', 'flavor-agent' ),
			'ready'         => __( 'Ready', 'flavor-agent' ),
			'stale'         => __( 'Refresh needed', 'flavor-agent' ),
			'error'         => __( 'Error', 'flavor-agent' ),
			'uninitialized' => __( 'Not synced', 'flavor-agent' ),
			default         => $status,
		};
	}

	public static function get_pattern_sync_status_tone( string $status ): string {
		return match ( $status ) {
			'indexing' => 'accent',
			'ready'    => 'success',
			'stale'    => 'warning',
			'error'    => 'error',
			default    => 'neutral',
		};
	}

	public static function get_pattern_sync_reason_label( string $reason ): string {
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

	public static function get_prewarm_status_label( string $status ): string {
		return match ( $status ) {
			'never'     => __( 'Never run', 'flavor-agent' ),
			'ok'        => __( 'OK', 'flavor-agent' ),
			'partial'   => __( 'Partial', 'flavor-agent' ),
			'failed'    => __( 'Failed', 'flavor-agent' ),
			'throttled' => __( 'Throttled', 'flavor-agent' ),
			default     => $status,
		};
	}

	public static function get_prewarm_status_tone( string $status ): string {
		return match ( $status ) {
			'ok'      => 'success',
			'partial' => 'warning',
			'failed'  => 'error',
			default   => 'neutral',
		};
	}

	public static function get_runtime_grounding_status_label( string $status ): string {
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

	public static function get_runtime_grounding_mode_label( string $mode ): string {
		return match ( $mode ) {
			'cache'      => __( 'cache', 'flavor-agent' ),
			'direct'     => __( 'direct search', 'flavor-agent' ),
			'foreground' => __( 'foreground warm', 'flavor-agent' ),
			'async'      => __( 'async warm', 'flavor-agent' ),
			'prewarm'    => __( 'prewarm', 'flavor-agent' ),
			default      => str_replace( '_', ' ', $mode ),
		};
	}

	public static function get_runtime_grounding_fallback_label( string $fallback_type ): string {
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
	public static function format_openai_native_key_source_label( string $source ): string {
		return match ( $source ) {
			'plugin_override'    => 'Flavor Agent plugin setting',
			'env'                => 'OPENAI_API_KEY environment variable',
			'constant'           => 'OPENAI_API_KEY PHP constant',
			'connector_database' => 'Settings > Connectors',
			default              => 'none',
		};
	}

	private static function pattern_backends_partially_configured( array $state ): bool {
		$qdrant_configured    = ! empty( $state['qdrant_configured'] );
		$embedding_configured = ! empty( $state['runtime_embedding']['configured'] );

		return $qdrant_configured !== $embedding_configured;
	}

	private static function runtime_chat_uses_connectors( array $state ): bool {
		$runtime_provider = is_string( $state['runtime_chat']['provider'] ?? null )
			? $state['runtime_chat']['provider']
			: '';

		return Provider::is_connector( $runtime_provider ) || Provider::is_wordpress_ai_client( $runtime_provider );
	}

	private static function runtime_chat_label( array $state ): string {
		$runtime_label = trim( (string) ( $state['runtime_chat']['label'] ?? '' ) );

		if ( '' !== $runtime_label ) {
			return $runtime_label;
		}

		return Provider::label( (string) $state['selected_provider'] );
	}

	private function __construct() {
	}
}
