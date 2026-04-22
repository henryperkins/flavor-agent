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
				'Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent, or configure a text-generation provider in Settings > Connectors and select it here, to enable block recommendations.',
				'flavor-agent'
			)
			: __(
				'Block recommendations are not configured yet. Ask an administrator to configure Flavor Agent or Connectors for this site.',
				'flavor-agent'
			);
		$pattern_message       = $can_manage_settings
			? __(
				'Pattern recommendations need a compatible embedding backend and Qdrant in Settings > Flavor Agent. Chat can come from Settings > Flavor Agent or Settings > Connectors, and Flavor Agent automatically reuses any configured Azure OpenAI or OpenAI Native embedding backend for pattern search.',
				'flavor-agent'
			)
			: __(
				'Pattern recommendations are not configured yet. Ask an administrator to configure Flavor Agent or Connectors for this site.',
				'flavor-agent'
			);
		$content_message       = $can_manage_settings
			? __(
				'Content recommendations use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this lane.',
				'flavor-agent'
			)
			: __(
				'Content recommendations are not configured yet. Ask an administrator to configure Flavor Agent or Connectors for this site.',
				'flavor-agent'
			);
		$template_message      = $can_manage_settings
			? __(
				'Template recommendations use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this surface.',
				'flavor-agent'
			)
			: __(
				'Template recommendations are not configured yet. Ask an administrator to configure Flavor Agent or Connectors for this site.',
				'flavor-agent'
			);
		$template_part_message = $can_manage_settings
			? __(
				'Template-part recommendations use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this surface.',
				'flavor-agent'
			)
			: __(
				'Template-part recommendations are not configured yet. Ask an administrator to configure Flavor Agent or Connectors for this site.',
				'flavor-agent'
			);
		$navigation_message    = $can_manage_settings
			? __(
				'Navigation recommendations use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this surface.',
				'flavor-agent'
			)
			: __(
				'Navigation recommendations are not configured yet. Ask an administrator to configure Flavor Agent or Connectors for this site.',
				'flavor-agent'
			);
		$global_styles_message = $can_manage_settings
			? __(
				'Global Styles recommendations use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this surface.',
				'flavor-agent'
			)
			: __(
				'Global Styles recommendations are not configured yet. Ask an administrator to configure Flavor Agent or Connectors for this site.',
				'flavor-agent'
			);
		$style_book_message    = $can_manage_settings
			? __(
				'Style Book recommendations use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this surface.',
				'flavor-agent'
			)
			: __(
				'Style Book recommendations are not configured yet. Ask an administrator to configure Flavor Agent or Connectors for this site.',
				'flavor-agent'
			);

		return [
			'block'        => self::build_surface(
				$block_available,
				$block_available ? 'ready' : 'block_backend_unconfigured',
				'plugin_or_core',
				$block_message,
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
				)
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
				'plugin_or_core',
				$content_message,
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
			'template'     => self::build_surface(
				$chat_available,
				$chat_available ? 'ready' : 'plugin_provider_unconfigured',
				'plugin_or_core',
				$template_message,
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
			'templatePart' => self::build_surface(
				$chat_available,
				$chat_available ? 'ready' : 'plugin_provider_unconfigured',
				'plugin_or_core',
				$template_part_message,
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
			'navigation'   => self::build_surface(
				$chat_available && $can_edit_theme,
				! $can_edit_theme
					? 'missing_theme_capability'
					: ( $chat_available ? 'ready' : 'plugin_provider_unconfigured' ),
				'plugin_or_core',
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
							'label' => 'Settings > Flavor Agent',
							'href'  => $settings_url,
						],
						[
							'label' => 'Settings > Connectors',
							'href'  => $connectors_url,
						],
					]
				),
				( $can_manage_settings && $can_edit_theme ) ? 'Settings > Flavor Agent' : '',
				( $can_manage_settings && $can_edit_theme ) ? $settings_url : '',
				true
			),
			'globalStyles' => self::build_surface(
				$chat_available && $can_edit_theme,
				! $can_edit_theme
					? 'missing_theme_capability'
					: ( $chat_available ? 'ready' : 'plugin_provider_unconfigured' ),
				'plugin_or_core',
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
							'label' => 'Settings > Flavor Agent',
							'href'  => $settings_url,
						],
						[
							'label' => 'Settings > Connectors',
							'href'  => $connectors_url,
						],
					]
				),
				( $can_manage_settings && $can_edit_theme ) ? 'Settings > Flavor Agent' : '',
				( $can_manage_settings && $can_edit_theme ) ? $settings_url : ''
			),
			'styleBook'    => self::build_surface(
				$chat_available && $can_edit_theme,
				! $can_edit_theme
					? 'missing_theme_capability'
					: ( $chat_available ? 'ready' : 'plugin_provider_unconfigured' ),
				'plugin_or_core',
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
							'label' => 'Settings > Flavor Agent',
							'href'  => $settings_url,
						],
						[
							'label' => 'Settings > Connectors',
							'href'  => $connectors_url,
						],
					]
				),
				( $can_manage_settings && $can_edit_theme ) ? 'Settings > Flavor Agent' : '',
				( $can_manage_settings && $can_edit_theme ) ? $settings_url : ''
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
