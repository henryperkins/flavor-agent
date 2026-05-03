<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\ChatClient;
use FlavorAgent\LLM\ResponseSchema;
use FlavorAgent\LLM\WritingPrompt;
use FlavorAgent\Support\StringArray;
use FlavorAgent\Support\WordPressAIPolicy;

final class ContentAbilities {

	public static function recommend_content( mixed $input ): array|\WP_Error {
		$input              = self::normalize_map( $input );
		$mode               = self::normalize_mode( $input['mode'] ?? 'draft' );
		$post_context_input = self::normalize_map( $input['postContext'] ?? [] );

		$post_id_raw = $post_context_input['postId'] ?? 0;
		$post_id     = is_numeric( $post_id_raw ) ? (int) $post_id_raw : 0;

		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_forbidden_context',
				__( 'You cannot request content recommendations for that post.', 'flavor-agent' ),
				[ 'status' => 403 ]
			);
		}

		$raw_content   = is_string( $post_context_input['content'] ?? null )
			? (string) $post_context_input['content']
			: '';
		$post_context  = self::sanitize_post_metadata_context( $post_context_input );
		$prompt        = self::sanitize_editorial_text( $input['prompt'] ?? '' );
		$voice_profile = self::sanitize_editorial_text( $input['voiceProfile'] ?? '' );

		if ( '' === $prompt && '' === trim( $raw_content ) && '' === ( $post_context['title'] ?? '' ) ) {
			return self::missing_content_instruction_error();
		}

		$post_context['content'] = ServerCollector::for_post_content(
			$raw_content,
			[
				'postId'        => $post_id,
				'stagedTitle'   => $post_context['title'],
				'stagedExcerpt' => $post_context['excerpt'],
			]
		);

		if ( '' === $prompt && '' === $post_context['content'] && '' === ( $post_context['title'] ?? '' ) ) {
			return self::missing_content_instruction_error();
		}

		if ( in_array( $mode, [ 'edit', 'critique' ], true ) && '' === ( $post_context['content'] ?? '' ) ) {
			return new \WP_Error(
				'missing_existing_content',
				'Edit and critique modes require existing postContext.content.',
				[ 'status' => 400 ]
			);
		}

		$resolved_post_type = $post_id > 0
			? (string) ( get_post( $post_id )?->post_type ?? '' )
			: (string) ( $post_context['postType'] ?? '' );
		$voice_samples      = ServerCollector::for_post_voice_samples( $post_id, $resolved_post_type );

		$context = [
			'mode'         => $mode,
			'postContext'  => $post_context,
			'voiceProfile' => $voice_profile,
			'voiceSamples' => $voice_samples,
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

	private static function sanitize_post_metadata_context( mixed $raw_context ): array {
		$context = self::normalize_map( $raw_context );

		return [
			'postType'        => sanitize_key( (string) ( $context['postType'] ?? '' ) ),
			'title'           => self::sanitize_editorial_text( $context['title'] ?? '' ),
			'excerpt'         => self::sanitize_editorial_text( $context['excerpt'] ?? '' ),
			'slug'            => sanitize_text_field( (string) ( $context['slug'] ?? '' ) ),
			'status'          => sanitize_key( (string) ( $context['status'] ?? '' ) ),
			'audience'        => self::sanitize_editorial_text( $context['audience'] ?? '' ),
			'siteTitle'       => self::sanitize_editorial_text( $context['siteTitle'] ?? '' ),
			'siteDescription' => self::sanitize_editorial_text( $context['siteDescription'] ?? '' ),
			'categories'      => StringArray::sanitize( $context['categories'] ?? [] ),
			'tags'            => StringArray::sanitize( $context['tags'] ?? [] ),
		];
	}

	private static function missing_content_instruction_error(): \WP_Error {
		return new \WP_Error(
			'missing_content_instruction',
			'Content recommendations require a prompt, an existing draft, or a working title.',
			[ 'status' => 400 ]
		);
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

		return WordPressAIPolicy::sanitize_textarea_content( $value );
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
