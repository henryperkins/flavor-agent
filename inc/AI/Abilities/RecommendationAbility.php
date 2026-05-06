<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\RecommendationAbilityExecution;
use FlavorAgent\Abilities\Registration;
use WordPress\AI\Abstracts\Abstract_Ability;

abstract class RecommendationAbility extends Abstract_Ability {

	protected const ABILITY_NAME = '';

	protected const SURFACE = '';

	protected const CAPABILITY = 'edit_posts';

	protected const CALLBACK = null;

	protected const GUIDELINE_CATEGORIES = [];

	public function input_schema(): array {
		return Registration::recommendation_input_schema( static::ABILITY_NAME );
	}

	public function output_schema(): array {
		return Registration::recommendation_output_schema( static::ABILITY_NAME );
	}

	public function execute_callback( mixed $input ): mixed {
		$callback = static::CALLBACK;

		if ( ! \is_callable( $callback ) ) {
			return new \WP_Error(
				'flavor_agent_invalid_ability_callback',
				'Flavor Agent recommendation ability callback is unavailable.',
				[ 'status' => 500 ]
			);
		}

		return RecommendationAbilityExecution::execute(
			static::SURFACE,
			static::ABILITY_NAME,
			$input,
			$callback,
			$this->get_recommendation_guideline_context( $input )
		);
	}

	public function permission_callback( mixed $input = null ): bool {
		if ( ! \current_user_can( static::CAPABILITY ) ) {
			return false;
		}

		if ( ! self::uses_post_scoped_permission() ) {
			return true;
		}

		$post_id = $this->post_id_from_input( $input );

		if ( $post_id > 0 ) {
			return \current_user_can( 'edit_post', $post_id );
		}

		return true;
	}

	private static function uses_post_scoped_permission(): bool {
		return \in_array( static::SURFACE, [ 'block', 'content', 'pattern' ], true );
	}

	public function meta(): array {
		return Registration::recommendation_meta();
	}

	public function category(): string {
		return 'flavor-agent';
	}

	protected function guideline_categories(): array {
		return static::GUIDELINE_CATEGORIES;
	}

	/**
	 * @return array{categories: array<int, string>, blockName: string}
	 */
	private function get_recommendation_guideline_context( mixed $input ): array {
		return [
			'categories' => static::GUIDELINE_CATEGORIES,
			'blockName'  => $this->block_name_from_input( $input ),
		];
	}

	private function block_name_from_input( mixed $input ): string {
		$input      = $this->normalize_map( $input );
		$block_name = '';

		if ( 'block' === static::SURFACE ) {
			$editor_context = $this->normalize_map( $input['editorContext'] ?? [] );
			$block          = $this->normalize_map( $editor_context['block'] ?? [] );
			$selected_block = $this->normalize_map( $input['selectedBlock'] ?? [] );
			$block_name     = (string) ( $block['name'] ?? $selected_block['blockName'] ?? $selected_block['name'] ?? '' );
		} elseif ( 'style' === static::SURFACE ) {
			$scope      = $this->normalize_map( $input['scope'] ?? [] );
			$block_name = 'style-book' === (string) ( $scope['surface'] ?? '' )
				? (string) ( $scope['blockName'] ?? '' )
				: '';
		}

		return \sanitize_text_field( $block_name );
	}

	private function post_id_from_input( mixed $input ): int {
		$input = $this->normalize_map( $input );

		$post_id = $this->post_id_from_context(
			$input['postContext'] ?? null,
			[ 'postId', 'post_id', 'id' ]
		);

		if ( $post_id > 0 ) {
			return $post_id;
		}

		$post_id = $this->post_id_from_context(
			$input['editorContext'] ?? null,
			[ 'postId', 'post_id' ]
		);

		if ( $post_id > 0 ) {
			return $post_id;
		}

		$post_id = $this->post_id_from_document( $input['document'] ?? null );

		if ( $post_id > 0 ) {
			return $post_id;
		}

		foreach ( [ 'postId', 'post_id' ] as $field ) {
			$post_id = $this->positive_int( $input[ $field ] ?? null );

			if ( $post_id > 0 ) {
				return $post_id;
			}
		}

		return 0;
	}

	private function post_id_from_document( mixed $document ): int {
		$document = $this->normalize_map( $document );

		if ( [] === $document ) {
			return 0;
		}

		$post_id = $this->post_id_from_context(
			$document,
			[ 'postId', 'post_id', 'id', 'entityId' ]
		);

		if ( $post_id > 0 ) {
			return $post_id;
		}

		return $this->post_id_from_scope_key( $document );
	}

	/**
	 * @param string[] $fields
	 */
	private function post_id_from_context( mixed $context, array $fields ): int {
		$context = $this->normalize_map( $context );

		foreach ( $fields as $field ) {
			$post_id = $this->positive_int( $context[ $field ] ?? null );

			if ( $post_id > 0 ) {
				return $post_id;
			}
		}

		return 0;
	}

	private function post_id_from_scope_key( mixed $context ): int {
		$context = $this->normalize_map( $context );

		$scope_key = \is_string( $context['scopeKey'] ?? null ) ? (string) $context['scopeKey'] : '';

		if ( \preg_match( '/^[A-Za-z0-9_-]+:(\d+)$/', $scope_key, $matches ) ) {
			return (int) $matches[1];
		}

		return 0;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function normalize_map( mixed $value ): array {
		if ( \is_array( $value ) ) {
			return $value;
		}

		if ( \is_object( $value ) ) {
			return \get_object_vars( $value );
		}

		return [];
	}

	private function positive_int( mixed $value ): int {
		if ( ! \is_numeric( $value ) ) {
			return 0;
		}

		$value = (int) $value;

		return $value > 0 ? $value : 0;
	}
}
