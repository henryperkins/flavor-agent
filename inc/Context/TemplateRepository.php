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
		$candidates    = $this->normalize_template_ref_candidates( $template_part_ref );

		foreach ( $candidates as $candidate ) {
			if ( ! str_contains( $candidate, '//' ) ) {
				continue;
			}

			$template_part = get_block_template( $candidate, 'wp_template_part' );

			if ( $template_part ) {
				break;
			}
		}

		if ( ! $template_part ) {
			$template_part = $this->resolve_by_wp_id( $candidates, 'wp_template_part' );
		}

		if ( ! $template_part ) {
			foreach ( $candidates as $candidate ) {
				$slug = $this->extract_template_part_slug( $candidate );

				if ( '' === $slug ) {
					continue;
				}

				$template_parts = get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template_part' );
				$template_part  = $template_parts[0] ?? null;

				if ( $template_part ) {
					break;
				}
			}
		}

		return is_object( $template_part ) ? $template_part : null;
	}

	public function resolve_template_part_ref( string $requested_ref, object $template_part ): string {
		$resolved_id = (string) ( $template_part->id ?? '' );

		if ( '' !== $resolved_id ) {
			return $resolved_id;
		}

		$normalized_ref = $this->normalize_template_ref( $requested_ref );

		return '' !== $normalized_ref
			? $normalized_ref
			: (string) ( $template_part->slug ?? '' );
	}

	public function resolve_template_ref( string $requested_ref, object $template ): string {
		$resolved_id = (string) ( $template->id ?? '' );

		if ( '' !== $resolved_id ) {
			return $resolved_id;
		}

		$normalized_ref = $this->normalize_template_ref( $requested_ref );

		return '' !== $normalized_ref
			? $normalized_ref
			: (string) ( $template->slug ?? '' );
	}

	public function resolve_template( string $template_ref ): ?object {
		$template   = null;
		$candidates = $this->normalize_template_ref_candidates( $template_ref );

		foreach ( $candidates as $candidate ) {
			if ( ! str_contains( $candidate, '//' ) ) {
				continue;
			}

			$template = get_block_template( $candidate, 'wp_template' );

			if ( $template ) {
				break;
			}
		}

		if ( ! $template ) {
			$template = $this->resolve_by_wp_id( $candidates, 'wp_template' );
		}

		if ( ! $template ) {
			foreach ( $candidates as $candidate ) {
				$slug = $this->extract_template_slug( $candidate );

				if ( '' === $slug ) {
					continue;
				}

				$templates = get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template' );
				$template  = $templates[0] ?? null;

				if ( $template ) {
					break;
				}
			}
		}

		return is_object( $template ) ? $template : null;
	}

	/**
	 * @param string[] $candidates
	 */
	private function resolve_by_wp_id( array $candidates, string $template_type ): ?object {
		foreach ( $candidates as $candidate ) {
			if ( ! ctype_digit( $candidate ) ) {
				continue;
			}

			$wp_id = (int) $candidate;

			if ( $wp_id <= 0 ) {
				continue;
			}

			$templates = get_block_templates( [ 'wp_id' => $wp_id ], $template_type );
			$template  = $templates[0] ?? null;

			if ( $template ) {
				return is_object( $template ) ? $template : null;
			}
		}

		return null;
	}

	/**
	 * @return string[]
	 */
	private function normalize_template_ref_candidates( string $template_ref ): array {
		$normalized = $this->normalize_template_ref( $template_ref );
		$original   = trim( $template_ref );

		return array_values(
			array_unique(
				array_filter(
					[ $normalized, $original ],
					static fn( string $candidate ): bool => '' !== $candidate
				)
			)
		);
	}

	private function normalize_template_ref( string $template_ref ): string {
		$template_ref = trim( $template_ref );

		if ( '' === $template_ref ) {
			return '';
		}

		return trim( rawurldecode( $template_ref ) );
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
