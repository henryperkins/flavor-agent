<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\LLM\ChatClient;
use FlavorAgent\LLM\ResponseSchema;
use FlavorAgent\LLM\WritingPrompt;
use FlavorAgent\Support\StringArray;

final class ContentAbilities {

	public static function recommend_content( mixed $input ): array|\WP_Error {
		$input = self::normalize_map( $input );
		$mode  = self::normalize_mode( $input['mode'] ?? 'draft' );

		$post_context  = self::sanitize_post_context( $input['postContext'] ?? [] );
		$prompt        = self::sanitize_editorial_text( $input['prompt'] ?? '' );
		$voice_profile = self::sanitize_editorial_text( $input['voiceProfile'] ?? '' );

		if ( '' === $prompt && '' === ( $post_context['content'] ?? '' ) && '' === ( $post_context['title'] ?? '' ) ) {
			return new \WP_Error(
				'missing_content_instruction',
				'Content recommendations require a prompt, an existing draft, or a working title.',
				[ 'status' => 400 ]
			);
		}

		if ( in_array( $mode, [ 'edit', 'critique' ], true ) && '' === ( $post_context['content'] ?? '' ) ) {
			return new \WP_Error(
				'missing_existing_content',
				'Edit and critique modes require existing postContext.content.',
				[ 'status' => 400 ]
			);
		}

		$context = [
			'mode'         => $mode,
			'postContext'  => $post_context,
			'voiceProfile' => $voice_profile,
		];

		$result = ChatClient::chat(
			WritingPrompt::build_system(),
			WritingPrompt::build_user( $context, $prompt ),
			ResponseSchema::get( 'content' ),
			'flavor_agent_content'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return WritingPrompt::parse_response( $result, $mode );
	}

	private static function sanitize_post_context( mixed $raw_context ): array {
		$context = self::normalize_map( $raw_context );

		return [
			'postType'        => sanitize_key( (string) ( $context['postType'] ?? '' ) ),
			'title'           => self::sanitize_editorial_text( $context['title'] ?? '' ),
			'excerpt'         => self::sanitize_editorial_text( $context['excerpt'] ?? '' ),
			'content'         => self::sanitize_editorial_text( $context['content'] ?? '' ),
			'slug'            => sanitize_text_field( (string) ( $context['slug'] ?? '' ) ),
			'status'          => sanitize_key( (string) ( $context['status'] ?? '' ) ),
			'audience'        => self::sanitize_editorial_text( $context['audience'] ?? '' ),
			'siteTitle'       => self::sanitize_editorial_text( $context['siteTitle'] ?? '' ),
			'siteDescription' => self::sanitize_editorial_text( $context['siteDescription'] ?? '' ),
			'categories'      => StringArray::sanitize( $context['categories'] ?? [] ),
			'tags'            => StringArray::sanitize( $context['tags'] ?? [] ),
		];
	}

	private static function normalize_mode( mixed $value ): string {
		$mode = sanitize_key( (string) $value );

		return in_array( $mode, [ 'draft', 'edit', 'critique' ], true )
			? $mode
			: 'draft';
	}

	private static function sanitize_editorial_text( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return sanitize_textarea_field( str_replace( "\r", '', $value ) );
	}

	private static function normalize_map( mixed $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			return get_object_vars( $value );
		}

		return [];
	}
}
