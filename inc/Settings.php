<?php

declare(strict_types=1);

namespace FlavorAgent;

use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\Patterns\PatternIndex;

final class Settings {

    private const OPTION_GROUP = 'flavor_agent_settings';
    private const PAGE_SLUG    = 'flavor-agent';

    public static function add_menu(): void {
        $hook = add_options_page(
            'Flavor Agent',
            'Flavor Agent',
            'manage_options',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );

        if ( $hook ) {
            add_action( 'admin_enqueue_scripts', function ( string $page_hook ) use ( $hook ) {
                if ( $page_hook !== $hook ) {
                    return;
                }
                self::enqueue_admin_assets();
            } );
        }
    }

    public static function register_settings(): void {
        // Anthropic.
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

        // Azure OpenAI.
        register_setting( self::OPTION_GROUP, 'flavor_agent_azure_openai_endpoint', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_url',
            'default'           => '',
        ] );
        register_setting( self::OPTION_GROUP, 'flavor_agent_azure_openai_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( self::OPTION_GROUP, 'flavor_agent_azure_embedding_deployment', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( self::OPTION_GROUP, 'flavor_agent_azure_chat_deployment', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        // Qdrant.
        register_setting( self::OPTION_GROUP, 'flavor_agent_qdrant_url', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_url',
            'default'           => '',
        ] );
        register_setting( self::OPTION_GROUP, 'flavor_agent_qdrant_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        // --- Sections ---

        add_settings_section(
            'flavor_agent_main',
            'Anthropic (Block Recommendations)',
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_section(
            'flavor_agent_azure',
            'Azure OpenAI (Pattern Recommendations)',
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_section(
            'flavor_agent_qdrant',
            'Qdrant Cloud (Vector Store)',
            '__return_false',
            self::PAGE_SLUG
        );

        // --- Anthropic fields ---

        add_settings_field( 'flavor_agent_api_key', 'Anthropic API Key',
            [ __CLASS__, 'render_api_key_field' ], self::PAGE_SLUG, 'flavor_agent_main' );
        add_settings_field( 'flavor_agent_model', 'Model',
            [ __CLASS__, 'render_model_field' ], self::PAGE_SLUG, 'flavor_agent_main' );

        // --- Azure OpenAI fields ---

        add_settings_field( 'flavor_agent_azure_openai_endpoint', 'Endpoint',
            [ __CLASS__, 'render_text_field' ], self::PAGE_SLUG, 'flavor_agent_azure',
            [ 'option' => 'flavor_agent_azure_openai_endpoint', 'type' => 'url', 'placeholder' => 'https://....openai.azure.com/' ] );
        add_settings_field( 'flavor_agent_azure_openai_key', 'API Key',
            [ __CLASS__, 'render_text_field' ], self::PAGE_SLUG, 'flavor_agent_azure',
            [ 'option' => 'flavor_agent_azure_openai_key', 'type' => 'password' ] );
        add_settings_field( 'flavor_agent_azure_embedding_deployment', 'Embedding Deployment',
            [ __CLASS__, 'render_text_field' ], self::PAGE_SLUG, 'flavor_agent_azure',
            [ 'option' => 'flavor_agent_azure_embedding_deployment', 'placeholder' => 'text-embedding-3-large' ] );
        add_settings_field( 'flavor_agent_azure_chat_deployment', 'Chat Deployment',
            [ __CLASS__, 'render_text_field' ], self::PAGE_SLUG, 'flavor_agent_azure',
            [ 'option' => 'flavor_agent_azure_chat_deployment', 'placeholder' => 'gpt-5.4' ] );

        // --- Qdrant fields ---

        add_settings_field( 'flavor_agent_qdrant_url', 'Qdrant URL',
            [ __CLASS__, 'render_text_field' ], self::PAGE_SLUG, 'flavor_agent_qdrant',
            [ 'option' => 'flavor_agent_qdrant_url', 'type' => 'url', 'placeholder' => 'https://....cloud.qdrant.io:6333' ] );
        add_settings_field( 'flavor_agent_qdrant_key', 'API Key',
            [ __CLASS__, 'render_text_field' ], self::PAGE_SLUG, 'flavor_agent_qdrant',
            [ 'option' => 'flavor_agent_qdrant_key', 'type' => 'password' ] );
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

            <hr />
            <h2>Sync Pattern Catalog</h2>
            <?php self::render_sync_panel(); ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Field renderers
    // ------------------------------------------------------------------

    public static function render_api_key_field(): void {
        $value = get_option( 'flavor_agent_api_key', '' );
        printf(
            '<input type="password" name="flavor_agent_api_key" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr( $value )
        );
    }

    public static function render_model_field(): void {
        $value  = get_option( 'flavor_agent_model', 'claude-sonnet-4-20250514' );
        $models = [
            'claude-sonnet-4-20250514'  => 'Claude Sonnet 4',
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
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

    /**
     * Generic text/password/url field renderer driven by $args.
     */
    public static function render_text_field( array $args ): void {
        $option      = $args['option'] ?? '';
        $type        = $args['type'] ?? 'text';
        $placeholder = $args['placeholder'] ?? '';
        $value       = get_option( $option, '' );

        printf(
            '<input type="%s" name="%s" value="%s" class="regular-text" autocomplete="off" placeholder="%s" />',
            esc_attr( $type ),
            esc_attr( $option ),
            esc_attr( $value ),
            esc_attr( $placeholder )
        );
    }

    // ------------------------------------------------------------------
    // Sync status panel
    // ------------------------------------------------------------------

    private static function render_sync_panel(): void {
        $state = PatternIndex::get_runtime_state();

        $status_labels = [
            'uninitialized' => 'Not synced yet',
            'indexing'      => 'Syncing…',
            'ready'         => 'Ready',
            'stale'         => 'Stale (usable, refresh pending)',
            'error'         => 'Error',
        ];

        $label = $status_labels[ $state['status'] ] ?? $state['status'];
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">Status</th>
                <td><strong><?php echo esc_html( $label ); ?></strong></td>
            </tr>
            <?php if ( $state['indexed_count'] > 0 ) : ?>
            <tr>
                <th scope="row">Indexed Patterns</th>
                <td><?php echo (int) $state['indexed_count']; ?></td>
            </tr>
            <?php endif; ?>
            <?php if ( $state['last_synced_at'] ) : ?>
            <tr>
                <th scope="row">Last Synced</th>
                <td><?php echo esc_html( $state['last_synced_at'] ); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ( $state['last_error'] ) : ?>
            <tr>
                <th scope="row">Last Error</th>
                <td style="color:#d63638"><?php echo esc_html( $state['last_error'] ); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th scope="row">Qdrant Collection</th>
                <td><code><?php echo esc_html( $state['qdrant_collection'] ?: QdrantClient::get_collection_name() ); ?></code></td>
            </tr>
        </table>

        <p>
            <button type="button" id="flavor-agent-sync-button" class="button button-secondary">
                Sync Pattern Catalog
            </button>
            <span id="flavor-agent-sync-spinner" class="spinner" aria-hidden="true"></span>
            <span id="flavor-agent-sync-status" style="margin-left:10px"></span>
        </p>
        <div id="flavor-agent-sync-notice" aria-live="polite"></div>
        <?php
    }

    // ------------------------------------------------------------------
    // Admin JS
    // ------------------------------------------------------------------

    private static function enqueue_admin_assets(): void {
        $asset_path = FLAVOR_AGENT_DIR . 'build/admin.asset.php';
        if ( ! file_exists( $asset_path ) ) {
            return;
        }

        $asset = include $asset_path;

        wp_enqueue_script(
            'flavor-agent-admin',
            FLAVOR_AGENT_URL . 'build/admin.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_localize_script( 'flavor-agent-admin', 'flavorAgentAdmin', [
            'restUrl' => rest_url(),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ] );
    }
}
