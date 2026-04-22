<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class ServerCollector {

	public const TEMPLATE_PATTERN_CANDIDATE_CAP = 30;

	private static ?BlockTypeIntrospector $block_type_introspector = null;

	private static ?ThemeTokenCollector $theme_token_collector = null;

	private static ?BlockContextCollector $block_context_collector = null;

	private static ?TemplateStructureAnalyzer $template_structure_analyzer = null;

	private static ?PatternOverrideAnalyzer $pattern_override_analyzer = null;

	private static ?PatternCatalog $pattern_catalog = null;

	private static ?PatternCandidateSelector $pattern_candidate_selector = null;

	private static ?TemplateRepository $template_repository = null;

	private static ?TemplateTypeResolver $template_type_resolver = null;

	private static ?ViewportVisibilityAnalyzer $viewport_visibility_analyzer = null;

	private static ?TemplateContextCollector $template_context_collector = null;

	private static ?TemplatePartContextCollector $template_part_context_collector = null;

	private static ?NavigationParser $navigation_parser = null;

	private static ?NavigationContextCollector $navigation_context_collector = null;

	public static function introspect_block_type( string $block_name ): ?array {
		return self::block_type_introspector()->introspect_block_type( $block_name );
	}

	public static function resolve_inspector_panels( array $supports ): array {
		return self::block_type_introspector()->resolve_inspector_panels( $supports );
	}

	public static function for_tokens(): array {
		return self::theme_token_collector()->for_tokens();
	}

	public static function for_block(
		string $block_name,
		array $attributes = [],
		array $inner_blocks = [],
		bool $is_inside_content_only = false,
		array $parent_context = [],
		array $sibling_summaries_before = [],
		array $sibling_summaries_after = []
	): array {
		return self::block_context_collector()->for_block(
			$block_name,
			$attributes,
			$inner_blocks,
			$is_inside_content_only,
			$parent_context,
			$sibling_summaries_before,
			$sibling_summaries_after
		);
	}

	public static function for_patterns( ?array $categories = null, ?array $block_types = null, ?array $template_types = null ): array {
		return self::pattern_catalog()->for_patterns( $categories, $block_types, $template_types );
	}

	public static function for_template_parts( ?string $area = null, bool $include_content = true ): array {
		return self::template_repository()->for_template_parts( $area, $include_content );
	}

	/**
	 * @return array<string, string>
	 */
	public static function for_template_part_areas(): array {
		return self::template_repository()->for_template_part_areas();
	}

	public static function for_template_part( string $template_part_ref, ?array $visible_pattern_names = null ): array|\WP_Error {
		return self::template_part_context_collector()->for_template_part( $template_part_ref, $visible_pattern_names );
	}

	public static function for_template(
		string $template_ref,
		?string $template_type = null,
		?array $visible_pattern_names = null
	): array|\WP_Error {
		return self::template_context_collector()->for_template(
			$template_ref,
			$template_type,
			$visible_pattern_names
		);
	}

	public static function for_navigation( int $menu_id = 0, string $markup = '', array $editor_context = [] ): array|\WP_Error {
		return self::navigation_context_collector()->for_navigation( $menu_id, $markup, $editor_context );
	}

	private static function block_type_introspector(): BlockTypeIntrospector {
		return self::$block_type_introspector ??= new BlockTypeIntrospector();
	}

	private static function theme_token_collector(): ThemeTokenCollector {
		return self::$theme_token_collector ??= new ThemeTokenCollector();
	}

	private static function block_context_collector(): BlockContextCollector {
		return self::$block_context_collector ??= new BlockContextCollector(
			self::block_type_introspector(),
			self::theme_token_collector()
		);
	}

	private static function template_structure_analyzer(): TemplateStructureAnalyzer {
		return self::$template_structure_analyzer ??= new TemplateStructureAnalyzer();
	}

	private static function pattern_override_analyzer(): PatternOverrideAnalyzer {
		return self::$pattern_override_analyzer ??= new PatternOverrideAnalyzer(
			self::block_type_introspector(),
			self::template_structure_analyzer()
		);
	}

	private static function pattern_catalog(): PatternCatalog {
		return self::$pattern_catalog ??= new PatternCatalog(
			self::pattern_override_analyzer()
		);
	}

	private static function pattern_candidate_selector(): PatternCandidateSelector {
		return self::$pattern_candidate_selector ??= new PatternCandidateSelector(
			self::pattern_catalog()
		);
	}

	private static function template_repository(): TemplateRepository {
		return self::$template_repository ??= new TemplateRepository();
	}

	private static function template_type_resolver(): TemplateTypeResolver {
		return self::$template_type_resolver ??= new TemplateTypeResolver();
	}

	private static function viewport_visibility_analyzer(): ViewportVisibilityAnalyzer {
		return self::$viewport_visibility_analyzer ??= new ViewportVisibilityAnalyzer(
			self::template_structure_analyzer()
		);
	}

	private static function template_context_collector(): TemplateContextCollector {
		return self::$template_context_collector ??= new TemplateContextCollector(
			self::template_repository(),
			self::template_type_resolver(),
			self::template_structure_analyzer(),
			self::pattern_override_analyzer(),
			self::viewport_visibility_analyzer(),
			self::pattern_candidate_selector(),
			self::theme_token_collector()
		);
	}

	private static function template_part_context_collector(): TemplatePartContextCollector {
		return self::$template_part_context_collector ??= new TemplatePartContextCollector(
			self::template_repository(),
			self::template_structure_analyzer(),
			self::pattern_override_analyzer(),
			self::pattern_candidate_selector(),
			self::theme_token_collector()
		);
	}

	private static function navigation_parser(): NavigationParser {
		return self::$navigation_parser ??= new NavigationParser();
	}

	private static function navigation_context_collector(): NavigationContextCollector {
		return self::$navigation_context_collector ??= new NavigationContextCollector(
			self::navigation_parser(),
			self::template_repository(),
			self::theme_token_collector()
		);
	}
}
