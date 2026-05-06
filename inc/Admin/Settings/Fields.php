<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

use FlavorAgent\Cloudflare\WorkersAIEmbeddingConfiguration;
use FlavorAgent\OpenAI\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Fields {

	public static function render_text_field( array $args ): void {
		$option             = (string) ( $args['option'] ?? '' );
		$type               = $args['type'] ?? 'text';
		$placeholder        = $args['placeholder'] ?? '';
		$description        = $args['description'] ?? '';
		$default            = (string) ( $args['default'] ?? '' );
		$value              = (string) get_option( $option, $default );
		$field_id           = (string) ( $args['label_for'] ?? $option );
		$is_password        = 'password' === (string) $type;
		$has_saved_password = $is_password && '' !== $value;

		if ( $has_saved_password ) {
			$value       = '';
			$description = '' !== (string) $description
				? sprintf(
					'%1$s<br />%2$s',
					(string) $description,
					esc_html__( 'Saved value hidden. Leave blank to keep it, or enter a replacement.', 'flavor-agent' )
				)
				: esc_html__( 'Saved value hidden. Leave blank to keep it, or enter a replacement.', 'flavor-agent' );
		}

		$description_id = '' !== $description ? $field_id . '-description' : '';
		$attributes     = [
			'type'        => (string) $type,
			'id'          => $field_id,
			'name'        => $option,
			'value'       => $value,
			'class'       => 'regular-text flavor-agent-settings-field',
			'placeholder' => (string) $placeholder,
		];

		foreach ( [ 'step', 'min', 'max' ] as $attribute ) {
			if ( ! array_key_exists( $attribute, $args ) ) {
				continue;
			}

			$attribute_value = (string) $args[ $attribute ];

			if ( '' === $attribute_value ) {
				continue;
			}

			$attributes[ $attribute ] = $attribute_value;
		}

		if ( isset( $args['inputmode'] ) && '' !== (string) $args['inputmode'] ) {
			$attributes['inputmode'] = (string) $args['inputmode'];
		}

		if ( '' !== $description_id ) {
			$attributes['aria-describedby'] = $description_id;
		}

		$autocomplete = array_key_exists( 'autocomplete', $args )
			? (string) $args['autocomplete']
			: '';

		if ( '' !== $autocomplete ) {
			$attributes['autocomplete'] = $autocomplete;
		}

		if ( $has_saved_password ) {
			$attributes['data-saved-secret'] = 'true';
		}
		?>
		<?php if ( $has_saved_password ) : ?>
			<span class="flavor-agent-settings-secret-field">
		<?php endif; ?>
		<input<?php Utils::render_html_attributes( $attributes ); ?> />
		<?php if ( $has_saved_password ) : ?>
			<span class="flavor-agent-settings-secret-field__status" aria-hidden="true">
				<?php echo esc_html__( 'Saved', 'flavor-agent' ); ?>
			</span>
			</span>
		<?php endif; ?>
		<?php

		if ( $description ) {
			printf(
				'<p class="description" id="%s">%s</p>',
				esc_attr( $description_id ),
				wp_kses_post( $description )
			);
		}
	}

	public static function render_textarea_field( array $args ): void {
		$option         = (string) ( $args['option'] ?? '' );
		$placeholder    = (string) ( $args['placeholder'] ?? '' );
		$description    = (string) ( $args['description'] ?? '' );
		$default        = (string) ( $args['default'] ?? '' );
		$value          = (string) get_option( $option, $default );
		$field_id       = (string) ( $args['label_for'] ?? $option );
		$description_id = '' !== $description ? $field_id . '-description' : '';
		$attributes     = [
			'id'          => $field_id,
			'name'        => $option,
			'class'       => 'flavor-agent-settings-field flavor-agent-settings-field--textarea',
			'placeholder' => $placeholder,
			'rows'        => (string) ( $args['rows'] ?? '6' ),
		];

		if ( '' !== $description_id ) {
			$attributes['aria-describedby'] = $description_id;
		}
		?>
		<textarea<?php Utils::render_html_attributes( $attributes ); ?>><?php echo esc_textarea( $value ); ?></textarea>
		<?php

		if ( '' !== $description ) {
			printf(
				'<p class="description" id="%s">%s</p>',
				esc_attr( $description_id ),
				wp_kses_post( $description )
			);
		}
	}

	public static function render_select_field( array $args ): void {
		$option         = (string) ( $args['option'] ?? '' );
		$choices        = is_array( $args['choices'] ?? null ) ? $args['choices'] : [];
		$description    = (string) ( $args['description'] ?? '' );
		$default        = (string) ( $args['default'] ?? '' );
		$field_id       = (string) ( $args['label_for'] ?? $option );
		$description_id = '' !== $description ? $field_id . '-description' : '';
		$attributes     = [
			'id'    => $field_id,
			'name'  => $option,
			'class' => 'flavor-agent-settings-field',
		];
		$value          = (string) get_option(
			$option,
			$option === Provider::OPTION_NAME ? WorkersAIEmbeddingConfiguration::PROVIDER : $default
		);
		$autocomplete   = array_key_exists( 'autocomplete', $args )
			? (string) $args['autocomplete']
			: '';

		if ( '' !== $autocomplete ) {
			$attributes['autocomplete'] = $autocomplete;
		}

		if ( '' !== $description_id ) {
			$attributes['aria-describedby'] = $description_id;
		}
		?>
		<select<?php Utils::render_html_attributes( $attributes ); ?>>
			<?php foreach ( $choices as $choice_value => $choice_label ) : ?>
				<option value="<?php echo esc_attr( (string) $choice_value ); ?>" <?php echo selected( $value, (string) $choice_value, false ); ?>>
					<?php echo esc_html( (string) $choice_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php

		if ( $description ) {
			printf(
				'<p class="description" id="%s">%s</p>',
				esc_attr( $description_id ),
				wp_kses_post( $description )
			);
		}
	}

	public static function render_checkbox_field( array $args ): void {
		$option         = (string) ( $args['option'] ?? '' );
		$field_id       = (string) ( $args['label_for'] ?? $option );
		$label          = (string) ( $args['label'] ?? '' );
		$description    = (string) ( $args['description'] ?? '' );
		$value          = self::parse_boolean_field_value( get_option( $option, false ) );
		$description_id = '' !== $description ? $field_id . '-description' : '';
		$attributes     = [
			'type'  => 'checkbox',
			'id'    => $field_id,
			'name'  => $option,
			'value' => '1',
		];

		if ( '' !== $description_id ) {
			$attributes['aria-describedby'] = $description_id;
		}

		if ( $value ) {
			$attributes['checked'] = 'checked';
		}
		?>
		<input type="hidden" name="<?php echo esc_attr( $option ); ?>" value="0" />
		<label class="flavor-agent-settings-checkbox" for="<?php echo esc_attr( $field_id ); ?>">
			<input<?php Utils::render_html_attributes( $attributes ); ?> />
			<?php echo esc_html( $label ); ?>
		</label>
		<?php

		if ( '' !== $description ) {
			printf(
				'<p class="description" id="%s">%s</p>',
				esc_attr( $description_id ),
				wp_kses_post( $description )
			);
		}
	}

	private static function parse_boolean_field_value( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 1 === $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'on' ], true );
		}

		return false;
	}

	private function __construct() {
	}
}
