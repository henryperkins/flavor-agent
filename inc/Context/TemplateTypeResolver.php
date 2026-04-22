<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class TemplateTypeResolver {

	private const KNOWN_TEMPLATE_TYPES = [
		'index',
		'home',
		'front-page',
		'singular',
		'single',
		'page',
		'archive',
		'author',
		'category',
		'tag',
		'taxonomy',
		'date',
		'search',
		'404',
	];

	public function derive_template_type( string $ref ): ?string {
		$slug = str_contains( $ref, '//' )
			? substr( $ref, strpos( $ref, '//' ) + 2 )
			: $ref;

		if ( '' === $slug ) {
			return null;
		}

		if ( in_array( $slug, self::KNOWN_TEMPLATE_TYPES, true ) ) {
			return $slug;
		}

		$base = explode( '-', $slug )[0];

		if ( in_array( $base, self::KNOWN_TEMPLATE_TYPES, true ) ) {
			return $base;
		}

		return null;
	}
}
