<?php

declare(strict_types=1);

namespace FlavorAgent;

use FlavorAgent\Admin\Settings\Assets;
use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Admin\Settings\Fields;
use FlavorAgent\Admin\Settings\Help;
use FlavorAgent\Admin\Settings\Page;
use FlavorAgent\Admin\Settings\Registrar;
use FlavorAgent\Admin\Settings\Validation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public static function add_menu(): void {
		$hook = add_options_page(
			'Flavor Agent',
			'Flavor Agent',
			'manage_options',
			Config::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);

		if ( $hook ) {
			foreach ( Assets::get_known_page_hooks( $hook ) as $known_hook ) {
				add_action( "load-$known_hook", [ __CLASS__, 'handle_page_load' ] );
			}
		}
	}

	public static function handle_page_load(): void {
		self::register_contextual_help();
	}

	public static function maybe_enqueue_admin_assets( string $page_hook ): void {
		Assets::maybe_enqueue_assets( $page_hook );
	}

	public static function register_contextual_help(): void {
		Help::register_contextual_help();
	}

	public static function register_settings(): void {
		Registrar::register_settings();
	}

	public static function render_page(): void {
		Page::render_page();
	}

	public static function render_azure_section(): void {
		Page::render_azure_section();
	}

	public static function render_openai_provider_section(): void {
		Page::render_openai_provider_section();
	}

	public static function render_openai_native_section(): void {
		Page::render_openai_native_section();
	}

	public static function render_cloudflare_workers_ai_section(): void {
		Page::render_cloudflare_workers_ai_section();
	}

	public static function render_pattern_retrieval_section(): void {
		Page::render_pattern_retrieval_section();
	}

	public static function render_qdrant_section(): void {
		Page::render_qdrant_section();
	}

	public static function render_cloudflare_pattern_ai_search_section(): void {
		Page::render_cloudflare_pattern_ai_search_section();
	}

	public static function render_pattern_recommendations_section(): void {
		Page::render_pattern_recommendations_section();
	}

	public static function render_cloudflare_section(): void {
		Page::render_cloudflare_section();
	}

	public static function render_guidelines_section(): void {
		Page::render_guidelines_section();
	}

	public static function render_experimental_features_section(): void {
		Page::render_experimental_features_section();
	}

	public static function render_text_field( array $args ): void {
		Fields::render_text_field( $args );
	}

	public static function render_textarea_field( array $args ): void {
		Fields::render_textarea_field( $args );
	}

	public static function render_select_field( array $args ): void {
		Fields::render_select_field( $args );
	}

	public static function render_checkbox_field( array $args ): void {
		Fields::render_checkbox_field( $args );
	}

	public static function sanitize_grounding_result_count( mixed $value ): int {
		return Validation::sanitize_grounding_result_count( $value );
	}

	public static function sanitize_pattern_recommendation_threshold( mixed $value ): float {
		return Validation::sanitize_pattern_recommendation_threshold( $value );
	}

	public static function sanitize_pattern_recommendation_threshold_cloudflare_ai_search( mixed $value ): float {
		return Validation::sanitize_pattern_recommendation_threshold_cloudflare_ai_search( $value );
	}

	public static function sanitize_pattern_retrieval_backend( mixed $value ): string {
		return Validation::sanitize_pattern_retrieval_backend( $value );
	}

	public static function sanitize_pattern_max_recommendations( mixed $value ): int {
		return Validation::sanitize_pattern_max_recommendations( $value );
	}

	public static function sanitize_block_structural_actions_enabled( mixed $value ): bool {
		return Validation::sanitize_block_structural_actions_enabled( $value );
	}

	public static function sanitize_azure_reasoning_effort( mixed $value ): string {
		return Validation::sanitize_azure_reasoning_effort( $value );
	}

	public static function sanitize_openai_provider( mixed $value ): string {
		return Validation::sanitize_openai_provider( $value );
	}

	public static function sanitize_azure_openai_endpoint( mixed $value ): string {
		return Validation::sanitize_azure_openai_endpoint( $value );
	}

	public static function sanitize_azure_openai_key( mixed $value ): string {
		return Validation::sanitize_azure_openai_key( $value );
	}

	public static function sanitize_azure_embedding_deployment( mixed $value ): string {
		return Validation::sanitize_azure_embedding_deployment( $value );
	}

	public static function sanitize_openai_native_api_key( mixed $value ): string {
		return Validation::sanitize_openai_native_api_key( $value );
	}

	public static function sanitize_openai_native_embedding_model( mixed $value ): string {
		return Validation::sanitize_openai_native_embedding_model( $value );
	}

	public static function sanitize_cloudflare_workers_ai_account_id( mixed $value ): string {
		return Validation::sanitize_cloudflare_workers_ai_account_id( $value );
	}

	public static function sanitize_cloudflare_workers_ai_api_token( mixed $value ): string {
		return Validation::sanitize_cloudflare_workers_ai_api_token( $value );
	}

	public static function sanitize_cloudflare_workers_ai_embedding_model( mixed $value ): string {
		return Validation::sanitize_cloudflare_workers_ai_embedding_model( $value );
	}

	public static function sanitize_qdrant_url( mixed $value ): string {
		return Validation::sanitize_qdrant_url( $value );
	}

	public static function sanitize_qdrant_key( mixed $value ): string {
		return Validation::sanitize_qdrant_key( $value );
	}

	public static function sanitize_cloudflare_pattern_ai_search_account_id( mixed $value ): string {
		return Validation::sanitize_cloudflare_pattern_ai_search_account_id( $value );
	}

	public static function sanitize_cloudflare_pattern_ai_search_namespace( mixed $value ): string {
		return Validation::sanitize_cloudflare_pattern_ai_search_namespace( $value );
	}

	public static function sanitize_cloudflare_pattern_ai_search_instance_id( mixed $value ): string {
		return Validation::sanitize_cloudflare_pattern_ai_search_instance_id( $value );
	}

	public static function sanitize_cloudflare_pattern_ai_search_api_token( mixed $value ): string {
		return Validation::sanitize_cloudflare_pattern_ai_search_api_token( $value );
	}

	public static function sanitize_cloudflare_account_id( mixed $value ): string {
		return Validation::sanitize_cloudflare_account_id( $value );
	}

	public static function sanitize_cloudflare_instance_id( mixed $value ): string {
		return Validation::sanitize_cloudflare_instance_id( $value );
	}

	public static function sanitize_cloudflare_api_token( mixed $value ): string {
		return Validation::sanitize_cloudflare_api_token( $value );
	}

	public static function sanitize_guideline_site( mixed $value ): string {
		return Validation::sanitize_guideline_site( $value );
	}

	public static function sanitize_guideline_copy( mixed $value ): string {
		return Validation::sanitize_guideline_copy( $value );
	}

	public static function sanitize_guideline_images( mixed $value ): string {
		return Validation::sanitize_guideline_images( $value );
	}

	public static function sanitize_guideline_additional( mixed $value ): string {
		return Validation::sanitize_guideline_additional( $value );
	}

	/**
	 * @return array<string, string>
	 */
	public static function sanitize_guideline_blocks( mixed $value ): array {
		return Validation::sanitize_guideline_blocks( $value );
	}
}
