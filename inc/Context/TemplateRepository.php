<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class TemplateRepository {

	public function for_template_parts( ?string $area = null, bool $include_content = true ): array {
		$query = [];

		if ( null !== $area && '' !== $area ) {
			$query['area'] = sanitize_key( $area );
		}

		$parts  = get_block_templates( $query, 'wp_template_part' );
		$result = [];

		foreach ( $parts as $part ) {
			$entry = [
				'slug'  => $part->slug ?? '',
				'title' => $part->title ?? '',
				'area'  => $part->area ?? '',
			];

			if ( $include_content ) {
				$entry['content'] = $part->content ?? '';
			}

			$result[] = $entry;
		}

		return $result;
	}

	/**
	 * @return array<string, string>
	 */
	public function for_template_part_areas(): array {
		$lookup = [];

		foreach ( $this->for_template_parts( null, false ) as $part ) {
			$slug = isset( $part['slug'] )
				? sanitize_key( (string) $part['slug'] )
				: '';
			$area = isset( $part['area'] )
				? sanitize_key( (string) $part['area'] )
				: '';

			if ( '' === $slug || '' === $area ) {
				continue;
			}

			$lookup[ $slug ] = $area;
		}

		return $lookup;
	}

	public function resolve_template_part( string $template_part_ref ): ?object {
		$template_part = null;

		if ( str_contains( $template_part_ref, '//' ) ) {
			$template_part = get_block_template( $template_part_ref, 'wp_template_part' );
		}

		if ( ! $template_part ) {
			$slug           = $this->extract_template_part_slug( $template_part_ref );
			$template_parts = '' !== $slug
				? get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template_part' )
				: [];
			$template_part  = $template_parts[0] ?? null;
		}

		return is_object( $template_part ) ? $template_part : null;
	}

	public function resolve_template_part_ref( string $requested_ref, object $template_part ): string {
		$resolved_id = (string) ( $template_part->id ?? '' );

		if ( '' !== $resolved_id ) {
			return $resolved_id;
		}

		return '' !== $requested_ref
			? $requested_ref
			: (string) ( $template_part->slug ?? '' );
	}

	public function resolve_template( string $template_ref ): ?object {
		$template = null;

		if ( str_contains( $template_ref, '//' ) ) {
			$template = get_block_template( $template_ref, 'wp_template' );
		}

		if ( ! $template ) {
			$slug      = $this->extract_template_slug( $template_ref );
			$templates = get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template' );
			$template  = $templates[0] ?? null;
		}

		return is_object( $template ) ? $template : null;
	}

	private function extract_template_part_slug( string $template_part_ref ): string {
		return str_contains( $template_part_ref, '//' )
			? substr( $template_part_ref, strpos( $template_part_ref, '//' ) + 2 )
			: $template_part_ref;
	}

	private function extract_template_slug( string $template_ref ): string {
		return str_contains( $template_ref, '//' )
			? substr( $template_ref, strpos( $template_ref, '//' ) + 2 )
			: $template_ref;
	}
}
