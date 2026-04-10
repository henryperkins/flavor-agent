<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

use FlavorAgent\Support\StringArray;

final class BlockTypeIntrospector {

	// Keep this map in sync with src/context/block-inspector.js.
	private const SUPPORT_TO_PANEL = [
		'color.background'           => 'color',
		'color.text'                 => 'color',
		'color.link'                 => 'color',
		'color.heading'              => 'color',
		'color.button'               => 'color',
		'color.gradients'            => 'color',
		'typography.fontSize'        => 'typography',
		'typography.fitText'         => 'typography',
		'typography.lineHeight'      => 'typography',
		'typography.textAlign'       => 'typography',
		'typography.textIndent'      => 'typography',
		'spacing.margin'             => 'dimensions',
		'spacing.padding'            => 'dimensions',
		'spacing.blockGap'           => 'dimensions',
		'dimensions.aspectRatio'     => 'dimensions',
		'dimensions.minHeight'       => 'dimensions',
		'dimensions.height'          => 'dimensions',
		'dimensions.width'           => 'dimensions',
		'border.color'               => 'border',
		'border.radius'              => 'border',
		'border.style'               => 'border',
		'border.width'               => 'border',
		'shadow'                     => 'shadow',
		'filter.duotone'             => 'filter',
		'background.backgroundImage' => 'background',
		'background.backgroundSize'  => 'background',
		'position.sticky'            => 'position',
		'position.fixed'             => 'position',
		'layout'                     => 'layout',
		'anchor'                     => 'advanced',
		'customCSS'                  => 'advanced',
		'listView'                   => 'list',
	];

	private const GENERAL_PANEL_EXCLUDED_ATTRIBUTES = [
		'className' => true,
		'metadata'  => true,
		'style'     => true,
		'lock'      => true,
	];

	private const DEFAULT_BINDABLE_ATTRIBUTES = [
		'core/paragraph' => [ 'content' ],
		'core/heading'   => [ 'content' ],
		'core/image'     => [ 'id', 'url', 'title', 'alt' ],
		'core/button'    => [ 'url', 'text', 'linkTarget', 'rel' ],
	];

	public function introspect_block_type( string $block_name ): ?array {
		$registry   = \WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( $block_name );

		if ( ! $block_type ) {
			return null;
		}

		$supports              = $block_type->supports ?? [];
		$supports_content_role = ! empty( $supports['contentRole'] );
		$attributes            = $block_type->attributes ?? [];
		$styles                = $block_type->styles ?? [];
		$variations            = $block_type->variations ?? [];
		$bindable_attributes   = $this->resolve_bindable_attributes( $block_name );
		$content_attrs         = [];
		$config_attrs          = [];

		foreach ( $attributes as $name => $definition ) {
			$role  = $this->resolve_attribute_role( $definition );
			$entry = [
				'type'    => $definition['type'] ?? null,
				'default' => $definition['default'] ?? null,
				'role'    => $role,
			];

			if ( isset( $definition['enum'] ) ) {
				$entry['enum'] = $definition['enum'];
			}

			if ( isset( $definition['source'] ) ) {
				$entry['source'] = $definition['source'];
			}

			if ( 'content' === $role ) {
				$content_attrs[ $name ] = $entry;
			} else {
				$config_attrs[ $name ] = $entry;
			}
		}

		return [
			'name'                => $block_name,
			'title'               => $block_type->title ?? '',
			'category'            => $block_type->category ?? '',
			'description'         => $block_type->description ?? '',
			'supports'            => $supports,
			'supportsContentRole' => $supports_content_role,
			'inspectorPanels'     => $this->merge_bindings_inspector_panel(
				$this->merge_general_inspector_panel(
					$this->resolve_inspector_panels( $supports ),
					$config_attrs
				),
				$bindable_attributes
			),
			'bindableAttributes'  => $bindable_attributes,
			'contentAttributes'   => $content_attrs,
			'configAttributes'    => $config_attrs,
			'styles'              => array_map(
				static fn( $style ) => [
					'name'      => $style['name'] ?? '',
					'label'     => $style['label'] ?? '',
					'isDefault' => $style['isDefault'] ?? false,
				],
				$styles
			),
			'variations'          => array_map(
				static fn( $variation ) => [
					'name'        => $variation['name'] ?? '',
					'title'       => $variation['title'] ?? '',
					'description' => $variation['description'] ?? '',
					'scope'       => $variation['scope'] ?? null,
				],
				array_slice( $variations, 0, 10 )
			),
			'parent'              => $block_type->parent ?? null,
			'allowedBlocks'       => $block_type->allowed_blocks ?? null,
			'apiVersion'          => $block_type->api_version ?? 1,
		];
	}

	public function resolve_inspector_panels( array $supports ): array {
		$panels = [];
		$flat   = $this->flatten_supports( $supports );

		foreach ( $flat as [ $path, $value ] ) {
			$panel_key = self::SUPPORT_TO_PANEL[ $path ] ?? null;

			if ( $panel_key && $this->is_truthy( $value ) ) {
				$panels[ $panel_key ]   = $panels[ $panel_key ] ?? [];
				$panels[ $panel_key ][] = $path;
			}
		}

		return $panels;
	}

	/**
	 * @return string[]
	 */
	public function resolve_bindable_attributes( string $block_name ): array {
		if ( function_exists( 'get_block_bindings_supported_attributes' ) ) {
			return StringArray::sanitize( \get_block_bindings_supported_attributes( $block_name ) );
		}

		return StringArray::sanitize( self::DEFAULT_BINDABLE_ATTRIBUTES[ $block_name ] ?? [] );
	}

	public function extract_active_style( string $class_name, array $styles ): ?string {
		if ( $class_name === '' ) {
			return null;
		}

		foreach ( $styles as $style ) {
			if ( str_contains( $class_name, 'is-style-' . ( $style['name'] ?? '' ) ) ) {
				return $style['name'];
			}
		}

		return null;
	}

	/**
	 * @param string[] $bindable_attributes
	 */
	private function merge_bindings_inspector_panel( array $inspector_panels, array $bindable_attributes ): array {
		if ( [] === $bindable_attributes ) {
			return $inspector_panels;
		}

		$inspector_panels['bindings'] = $bindable_attributes;

		return $inspector_panels;
	}

	private function merge_general_inspector_panel( array $inspector_panels, array $config_attributes ): array {
		$general_attributes = array_values(
			array_filter(
				array_keys( $config_attributes ),
				static fn( $attribute_name ): bool =>
					is_string( $attribute_name )
					&& $attribute_name !== ''
					&& ! isset( self::GENERAL_PANEL_EXCLUDED_ATTRIBUTES[ $attribute_name ] )
			)
		);

		if ( [] === $general_attributes ) {
			return $inspector_panels;
		}

		$existing_general = StringArray::sanitize( $inspector_panels['general'] ?? [] );

		$inspector_panels['general'] = array_values(
			array_unique(
				array_merge( $existing_general, $general_attributes )
			)
		);

		return $inspector_panels;
	}

	private function flatten_supports( array $supports, string $prefix = '' ): array {
		$entries = [];

		foreach ( $supports as $key => $value ) {
			$path = $prefix !== '' ? "{$prefix}.{$key}" : $key;

			if ( is_bool( $value ) || is_string( $value ) || ( is_array( $value ) && $this->is_list_array( $value ) ) ) {
				$entries[] = [ $path, $value ];
			} elseif ( is_array( $value ) ) {
				$entries = array_merge( $entries, $this->flatten_supports( $value, $path ) );
			}
		}

		return $entries;
	}

	private function is_truthy( mixed $value ): bool {
		if ( true === $value ) {
			return true;
		}

		if ( false === $value || null === $value ) {
			return false;
		}

		if ( is_array( $value ) ) {
			return count( $value ) > 0;
		}

		return (bool) $value;
	}

	private function resolve_attribute_role( array $definition ): ?string {
		$role = $definition['role'] ?? null;

		return is_string( $role ) && '' !== $role ? $role : null;
	}

	private function is_list_array( array $values ): bool {
		$expected_index = 0;

		foreach ( $values as $key => $_value ) {
			if ( $key !== $expected_index ) {
				return false;
			}

			++$expected_index;
		}

		return true;
	}
}
