<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

use FlavorAgent\Support\StringArray;

final class BlockTypeIntrospector {

	/**
	 * Canonical mapping lives in shared/support-to-panel.json and is
	 * consumed by both this class and src/context/block-inspector.js.
	 * A PHPUnit assertion validates that the two copies stay in sync.
	 *
	 * @return array<string, string>
	 */
	public static function get_support_to_panel(): array {
		static $map = null;
		if ( null === $map ) {
			$json_path = dirname( __DIR__, 2 ) . '/shared/support-to-panel.json';
			$raw       = file_get_contents( $json_path );
			$decoded   = json_decode( (string) $raw, true );
			$map       = is_array( $decoded ) ? $decoded : [];
		}

		return $map;
	}

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

		return $this->build_block_manifest( $block_name, $block_type );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_registered_blocks(
		?string $search = '',
		?string $category = null,
		?int $limit = null,
		int $offset = 0,
		bool $include_variations = false,
		int $max_variations = 10
	): array {
		$manifests = $this->collect_registered_blocks(
			$search,
			$category,
			$include_variations,
			$max_variations
		);

		if ( $offset > 0 || null !== $limit ) {
			$manifests = array_slice(
				$manifests,
				max( 0, $offset ),
				null !== $limit ? max( 0, $limit ) : null
			);
		}

		return $manifests;
	}

	public function count_registered_blocks( ?string $search = '', ?string $category = null ): int {
		return count( $this->collect_registered_blocks( $search, $category, false, 0 ) );
	}

	public function resolve_inspector_panels( array $supports ): array {
		$panels = [];
		$flat   = $this->flatten_supports( $supports );

		foreach ( $flat as [ $path, $value ] ) {
			$panel_key = self::get_support_to_panel()[ $path ] ?? null;

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

		preg_match_all( '/\bis-style-([a-z0-9_-]+)\b/i', $class_name, $matches );
		$active_styles = array_fill_keys(
			array_map( 'sanitize_key', $matches[1] ?? [] ),
			true
		);

		foreach ( $styles as $style ) {
			$style_name = is_string( $style['name'] ?? null ) ? sanitize_key( $style['name'] ) : '';

			if ( '' !== $style_name && isset( $active_styles[ $style_name ] ) ) {
				return $style_name;
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

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_registered_blocks(
		?string $search,
		?string $category,
		bool $include_variations,
		int $max_variations
	): array {
		$registry          = \WP_Block_Type_Registry::get_instance();
		$registered_blocks = method_exists( $registry, 'get_all_registered' )
			? $registry->get_all_registered()
			: [];
		$manifests         = [];
		$search_term       = is_string( $search ) ? strtolower( sanitize_text_field( $search ) ) : '';
		$category_filter   = is_string( $category ) && '' !== $category
			? sanitize_key( $category )
			: '';

		foreach ( $registered_blocks as $block_name => $block_type ) {
			if ( ! is_object( $block_type ) ) {
				continue;
			}

			$manifest = $this->build_block_manifest(
				(string) $block_name,
				$block_type,
				$include_variations,
				$max_variations
			);

			if ( ! $this->matches_registered_block_filters( $manifest, $search_term, $category_filter ) ) {
				continue;
			}

			$manifests[] = $manifest;
		}

		usort(
			$manifests,
			static function ( array $left, array $right ): int {
				$left_title  = (string) ( $left['title'] ?? '' );
				$right_title = (string) ( $right['title'] ?? '' );

				$title_comparison = strcasecmp( $left_title, $right_title );

				if ( 0 !== $title_comparison ) {
					return $title_comparison;
				}

				return strcmp(
					(string) ( $left['name'] ?? '' ),
					(string) ( $right['name'] ?? '' )
				);
			}
		);

		return $manifests;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_block_manifest(
		string $block_name,
		object $block_type,
		bool $include_variations = true,
		int $max_variations = 10
	): array {
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
			'variations'          => $include_variations
				? array_map(
					static fn( $variation ) => [
						'name'        => $variation['name'] ?? '',
						'title'       => $variation['title'] ?? '',
						'description' => $variation['description'] ?? '',
						'scope'       => $variation['scope'] ?? null,
					],
					array_slice( $variations, 0, max( 0, $max_variations ) )
				)
				: [],
			'parent'              => $block_type->parent ?? null,
			'allowedBlocks'       => $block_type->allowed_blocks ?? null,
			'apiVersion'          => $block_type->api_version ?? 1,
		];
	}

	private function matches_registered_block_filters( array $manifest, string $search_term, string $category_filter ): bool {
		if ( '' !== $category_filter && sanitize_key( (string) ( $manifest['category'] ?? '' ) ) !== $category_filter ) {
			return false;
		}

		if ( '' === $search_term ) {
			return true;
		}

		$haystacks = [
			strtolower( (string) ( $manifest['name'] ?? '' ) ),
			strtolower( (string) ( $manifest['title'] ?? '' ) ),
		];

		foreach ( $haystacks as $haystack ) {
			if ( str_contains( $haystack, $search_term ) ) {
				return true;
			}
		}

		return false;
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
