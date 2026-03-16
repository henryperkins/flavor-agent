<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\Client;
use FlavorAgent\LLM\Prompt;

final class BlockAbilities {

    public static function recommend_block( array $input ): array|\WP_Error {
        $selected = $input['selectedBlock'] ?? [];
        $block_name   = $selected['blockName'] ?? '';
        $attributes   = $selected['attributes'] ?? [];
        $inner_blocks = $selected['innerBlocks'] ?? [];
        $prompt       = $input['prompt'] ?? '';

        if ( empty( $block_name ) ) {
            return new \WP_Error( 'missing_block_name', 'selectedBlock.blockName is required.', [ 'status' => 400 ] );
        }

        $api_key = get_option( 'flavor_agent_api_key', '' );
        if ( empty( $api_key ) ) {
            return new \WP_Error( 'missing_api_key', 'Configure your API key in Settings > Flavor Agent.', [ 'status' => 400 ] );
        }

        $context = ServerCollector::for_block( $block_name, $attributes, $inner_blocks );

        $system_prompt = Prompt::build_system();
        $user_prompt   = Prompt::build_user( $context, $prompt );

        $result = Client::chat( $system_prompt, $user_prompt, $api_key );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return Prompt::parse_response( $result );
    }

    public static function introspect_block( array $input ): array|\WP_Error {
        $block_name = $input['blockName'] ?? '';

        if ( empty( $block_name ) ) {
            return new \WP_Error( 'missing_block_name', 'blockName is required.', [ 'status' => 400 ] );
        }

        $manifest = ServerCollector::introspect_block_type( $block_name );

        if ( $manifest === null ) {
            return new \WP_Error( 'block_not_found', "Block type '{$block_name}' is not registered.", [ 'status' => 404 ] );
        }

        return $manifest;
    }
}
