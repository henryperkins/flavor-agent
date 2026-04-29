<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\LLM\ChatClient;
use FlavorAgent\OpenAI\Provider;

final class SurfaceCapabilities {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function build( string $settings_url = '', string $connectors_url = '' ): array {
		$block_available     = ChatClient::is_supported();
		$chat_available      = Provider::chat_configured();
		$pattern_available   = (bool) (
			Provider::embedding_configured()
			&& $chat_available
			&& get_option( 'flavor_agent_qdrant_url' )
			&& get_option( 'flavor_agent_qdrant_key' )
		);
		$can_edit_theme      = current_user_can( 'edit_theme_options' );
		$can_manage_settings = current_user_can( 'manage_options' );

		$block_message         = $can_manage_settings
			? __(
				'Configure a text-generation provider in Settings > Connectors to enable block recommendations.',
				'flavor-agent'
			)
			: __(
				'Block recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
				'flavor-agent'
			);
		$pattern_message       = $can_manage_settings
			? __(
				'Pattern recommendations need a compatible embedding backend and Qdrant in Settings > Flavor Agent, plus a usable text-generation provider in Settings > Connectors.',
				'flavor-agent'
			)
			: __(
				'Pattern recommendations are not configured yet. Ask an administrator to configure Flavor Agent pattern backends and a text-generation provider in Settings > Connectors.',
				'flavor-agent'
			);
		$content_message       = $can_manage_settings
			? __(
				'Configure a text-generation provider in Settings > Connectors to enable content recommendations.',
				'flavor-agent'
			)
			: __(
				'Content recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
				'flavor-agent'
			);
		$template_message      = $can_manage_settings
			? __(
				'Configure a text-generation provider in Settings > Connectors to enable template recommendations.',
				'flavor-agent'
			)
			: __(
				'Template recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
				'flavor-agent'
			);
		$template_part_message = $can_manage_settings
			? __(
				'Configure a text-generation provider in Settings > Connectors to enable template-part recommendations.',
				'flavor-agent'
			)
			: __(
				'Template-part recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
				'flavor-agent'
			);
		$navigation_message    = $can_manage_settings
			? __(
				'Configure a text-generation provider in Settings > Connectors to enable navigation recommendations.',
				'flavor-agent'
			)
			: __(
				'Navigation recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
				'flavor-agent'
			);
		$global_styles_message = $can_manage_settings
			? __(
				'Configure a text-generation provider in Settings > Connectors to enable Global Styles recommendations.',
				'flavor-agent'
			)
			: __(
				'Global Styles recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
				'flavor-agent'
			);
		$style_book_message    = $can_manage_settings
			? __(
				'Configure a text-generation provider in Settings > Connectors to enable Style Book recommendations.',
				'flavor-agent'
			)
			: __(
				'Style Book recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
				'flavor-agent'
			);

		return [
			'block'        => self::build_surface(
				$block_available,
				$block_available ? 'ready' : 'block_backend_unconfigured',
				'connectors',
				$block_message,
				self::build_actions(
					$can_manage_settings,
					[
						[
							'label' => 'Settings > Connectors',
							'href'  => $connectors_url,
						],
					]
				),
				$can_manage_settings ? 'Settings > Connectors' : '',
				$can_manage_settings ? $connectors_url : ''
			),
			'pattern'      => self::build_surface(
				$pattern_available,
				$pattern_available ? 'ready' : 'pattern_backend_unconfigured',
				'plugin_settings',
				$pattern_message,
				self::build_actions(
					$can_manage_settings,
					[
						[
							'label' => 'Settings > Flavor Agent',
							'href'  => $settings_url,
						],
						[
							'label' => 'Settings > Connectors',
							'href'  => $connectors_url,
						],
					]
				),
				$can_manage_settings ? 'Settings > Flavor Agent' : '',
				$can_manage_settings ? $settings_url : ''
			),
			'content'      => self::build_surface(
				$chat_available,
				$chat_available ? 'ready' : 'plugin_provider_unconfigured',
				'connectors',
				$content_message,
				self::build_actions(
					$can_manage_settings,
					[
						[
							'label' => 'Settings > Connectors',
							'href'  => $connectors_url,
						],
					]
				),
				$can_manage_settings ? 'Settings > Connectors' : '',
				$can_manage_settings ? $connectors_url : ''
			),
			'template'     => self::build_surface(
				$chat_available,
				$chat_available ? 'ready' : 'plugin_provider_unconfigured',
				'connectors',
				$template_message,
				self::build_actions(
					$can_manage_settings,
					[
						[
							'label' => 'Settings > Connectors',
							'href'  => $connectors_url,
						],
					]
				),
				$can_manage_settings ? 'Settings > Connectors' : '',
				$can_manage_settings ? $connectors_url : ''
			),
			'templatePart' => self::build_surface(
				$chat_available,
				$chat_available ? 'ready' : 'plugin_provider_unconfigured',
				'connectors',
				$template_part_message,
				self::build_actions(
					$can_manage_settings,
					[
						[
							'label' => 'Settings > Connectors',
							'href'  => $connectors_url,
						],
					]
				),
				$can_manage_settings ? 'Settings > Connectors' : '',
				$can_manage_settings ? $connectors_url : ''
			),
			'navigation'   => self::build_surface(
				$chat_available && $can_edit_theme,
				! $can_edit_theme
					? 'missing_theme_capability'
					: ( $chat_available ? 'ready' : 'plugin_provider_unconfigured' ),
				'connectors',
				! $can_edit_theme
					? __(
						'Navigation recommendations require the edit_theme_options capability.',
						'flavor-agent'
					)
					: $navigation_message,
				self::build_actions(
					$can_manage_settings && $can_edit_theme,
					[
						[
							'label' => 'Settings > Connectors',
							'href'  => $connectors_url,
						],
					]
				),
				( $can_manage_settings && $can_edit_theme ) ? 'Settings > Connectors' : '',
				( $can_manage_settings && $can_edit_theme ) ? $connectors_url : '',
				true
			),
			'globalStyles' => self::build_surface(
				$chat_available && $can_edit_theme,
				! $can_edit_theme
					? 'missing_theme_capability'
					: ( $chat_available ? 'ready' : 'plugin_provider_unconfigured' ),
				'connectors',
				! $can_edit_theme
					? __(
						'Global Styles recommendations require the edit_theme_options capability.',
						'flavor-agent'
					)
					: $global_styles_message,
				self::build_actions(
					$can_manage_settings && $can_edit_theme,
					[
						[
							'label' => 'Settings > Connectors',
							'href'  => $connectors_url,
						],
					]
				),
				( $can_manage_settings && $can_edit_theme ) ? 'Settings > Connectors' : '',
				( $can_manage_settings && $can_edit_theme ) ? $connectors_url : ''
			),
			'styleBook'    => self::build_surface(
				$chat_available && $can_edit_theme,
				! $can_edit_theme
					? 'missing_theme_capability'
					: ( $chat_available ? 'ready' : 'plugin_provider_unconfigured' ),
				'connectors',
				! $can_edit_theme
					? __(
						'Style Book recommendations require the edit_theme_options capability.',
						'flavor-agent'
					)
					: $style_book_message,
				self::build_actions(
					$can_manage_settings && $can_edit_theme,
					[
						[
							'label' => 'Settings > Connectors',
							'href'  => $connectors_url,
						],
					]
				),
				( $can_manage_settings && $can_edit_theme ) ? 'Settings > Connectors' : '',
				( $can_manage_settings && $can_edit_theme ) ? $connectors_url : ''
			),
		];
	}

	public static function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'available'          => [ 'type' => 'boolean' ],
				'reason'             => [ 'type' => 'string' ],
				'owner'              => [ 'type' => 'string' ],
				'actions'            => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'label' => [ 'type' => 'string' ],
							'href'  => [ 'type' => 'string' ],
						],
					],
				],
				'configurationLabel' => [ 'type' => 'string' ],
				'configurationUrl'   => [ 'type' => 'string' ],
				'message'            => [ 'type' => 'string' ],
				'advisoryOnly'       => [ 'type' => 'boolean' ],
			],
		];
	}

	public static function surfaces_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'block'        => self::output_schema(),
				'pattern'      => self::output_schema(),
				'content'      => self::output_schema(),
				'template'     => self::output_schema(),
				'templatePart' => self::output_schema(),
				'navigation'   => self::output_schema(),
				'globalStyles' => self::output_schema(),
				'styleBook'    => self::output_schema(),
			],
		];
	}

	/**
	 * @param array<int, array<string, string>> $actions
	 * @return array<string, mixed>
	 */
	private static function build_surface(
		bool $available,
		string $reason,
		string $owner,
		string $message,
		array $actions = [],
		string $configuration_label = '',
		string $configuration_url = '',
		bool $advisory_only = false
	): array {
		return [
			'available'          => $available,
			'reason'             => $reason,
			'owner'              => $owner,
			'actions'            => $actions,
			'configurationLabel' => $configuration_label,
			'configurationUrl'   => $configuration_url,
			'message'            => $message,
			'advisoryOnly'       => $advisory_only,
		];
	}

	/**
	 * @param array<int, array<string, string>> $actions
	 * @return array<int, array<string, string>>
	 */
	private static function build_actions( bool $enabled, array $actions ): array {
		if ( ! $enabled ) {
			return [];
		}

		return array_values(
			array_filter(
				$actions,
				static fn( array $action ): bool => ! empty( $action['label'] ) && ! empty( $action['href'] )
			)
		);
	}
}
