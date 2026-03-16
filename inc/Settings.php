<?php

declare(strict_types=1);

namespace FlavorAgent;

final class Settings {

    private const OPTION_GROUP = 'flavor_agent_settings';
    private const PAGE_SLUG    = 'flavor-agent';

    public static function add_menu(): void {
        add_options_page(
            'Flavor Agent',
            'Flavor Agent',
            'manage_options',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting( self::OPTION_GROUP, 'flavor_agent_api_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        register_setting( self::OPTION_GROUP, 'flavor_agent_model', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'claude-sonnet-4-20250514',
        ] );

        add_settings_section(
            'flavor_agent_main',
            'LLM Configuration',
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'flavor_agent_api_key',
            'Anthropic API Key',
            [ __CLASS__, 'render_api_key_field' ],
            self::PAGE_SLUG,
            'flavor_agent_main'
        );

        add_settings_field(
            'flavor_agent_model',
            'Model',
            [ __CLASS__, 'render_model_field' ],
            self::PAGE_SLUG,
            'flavor_agent_main'
        );
    }

    public static function render_page(): void {
        ?>
        <div class="wrap">
            <h1>Flavor Agent Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::PAGE_SLUG );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public static function render_api_key_field(): void {
        $value = get_option( 'flavor_agent_api_key', '' );
        printf(
            '<input type="password" name="flavor_agent_api_key" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr( $value )
        );
    }

    public static function render_model_field(): void {
        $value = get_option( 'flavor_agent_model', 'claude-sonnet-4-20250514' );
        $models = [
            'claude-sonnet-4-20250514' => 'Claude Sonnet 4',
            'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5',
        ];
        echo '<select name="flavor_agent_model">';
        foreach ( $models as $id => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $id ),
                selected( $value, $id, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }
}
