<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\NavigationAbilities;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\NavigationPrompt;
use PHPUnit\Framework\TestCase;
use FlavorAgent\Tests\Support\WordPressTestState;

final class NavigationAbilitiesTest extends TestCase {

	/** @var callable */
	private $disable_public_docs_filter;

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->disable_public_docs_filter    = static fn(): string => '';
		\add_filter(
			'flavor_agent_cloudflare_ai_search_public_search_url',
			$this->disable_public_docs_filter
		);
		WordPressTestState::$block_templates = [
			'wp_template_part' => [
				(object) [
					'slug'    => 'header',
					'title'   => 'Header',
					'area'    => 'header',
					'content' => '<!-- wp:navigation {"ref":42} /-->',
				],
				(object) [
					'slug'    => 'footer',
					'title'   => 'Footer',
					'area'    => 'footer',
					'content' => '<!-- wp:group -->Footer<!-- /wp:group -->',
				],
				(object) [
					'slug'    => 'mobile-overlay',
					'title'   => 'Mobile Overlay',
					'area'    => 'navigation-overlay',
					'content' => '<!-- wp:navigation /-->',
				],
			],
		];
	}

	protected function tearDown(): void {
		\remove_filter(
			'flavor_agent_cloudflare_ai_search_public_search_url',
			$this->disable_public_docs_filter
		);

		parent::tearDown();
	}

	public function test_recommend_navigation_rejects_missing_input(): void {
		$result = NavigationAbilities::recommend_navigation( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_navigation_input', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_recommend_navigation_accepts_object_input(): void {
		$result = NavigationAbilities::recommend_navigation( (object) [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_navigation_input', $result->get_error_code() );
	}

	public function test_recommend_navigation_rejects_zero_menu_id_without_markup(): void {
		$result = NavigationAbilities::recommend_navigation( [ 'menuId' => 0 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_navigation_input', $result->get_error_code() );
	}

	public function test_recommend_navigation_resolve_signature_only_returns_review_signature(): void {
		$result = NavigationAbilities::recommend_navigation(
			[
				'navigationMarkup'     => '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- /wp:navigation -->',
				'prompt'               => 'Simplify the header navigation.',
				'resolveSignatureOnly' => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				'reviewContextSignature' => $result['reviewContextSignature'] ?? null,
			],
			$result
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $result['reviewContextSignature'] ?? '' )
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_navigation_review_signature_changes_when_theme_constraints_change(): void {
		$method = new \ReflectionMethod( NavigationAbilities::class, 'build_review_context_signature' );
		$method->setAccessible( true );

		$baseline_signature = $method->invoke(
			null,
			[
				'location'    => 'header',
				'themeTokens' => [],
			]
		);
		$changed_signature  = $method->invoke(
			null,
			[
				'location'    => 'header',
				'themeTokens' => [
					'layout'          => [
						'content'                       => '650px',
						'wide'                          => '1200px',
						'allowEditing'                  => false,
						'allowCustomContentAndWideSize' => false,
					],
					'enabledFeatures' => [
						'backgroundColor' => false,
						'textColor'       => true,
					],
				],
			]
		);

		$this->assertIsString( $baseline_signature );
		$this->assertIsString( $changed_signature );
		$this->assertNotSame( $baseline_signature, $changed_signature );
	}

	public function test_navigation_review_signature_ignores_overlay_ordering_changes(): void {
		$method = new \ReflectionMethod( NavigationAbilities::class, 'build_review_context_signature' );
		$method->setAccessible( true );

		$baseline_signature = $method->invoke(
			null,
			[
				'location'       => 'header',
				'overlayContext' => [
					'usesOverlay'                  => true,
					'overlayMode'                  => 'mobile',
					'hasDedicatedOverlayParts'     => true,
					'overlayTemplatePartCount'     => 2,
					'overlayTemplatePartSlugs'     => [ 'mobile-overlay', 'header-overlay' ],
					'siteHasDedicatedOverlayParts' => true,
					'siteOverlayTemplatePartCount' => 2,
					'siteOverlayTemplatePartSlugs' => [ 'mobile-overlay', 'header-overlay' ],
					'overlayReferenceScope'        => 'scoped',
					'overlayReferenceSource'       => 'location-heuristic',
				],
				'overlayTemplateParts' => [
					[
						'slug'  => 'mobile-overlay',
						'title' => 'Mobile Overlay',
					],
					[
						'slug'  => 'header-overlay',
						'title' => 'Header Overlay',
					],
				],
			]
		);
		$changed_signature  = $method->invoke(
			null,
			[
				'location'       => 'header',
				'overlayContext' => [
					'usesOverlay'                  => true,
					'overlayMode'                  => 'mobile',
					'hasDedicatedOverlayParts'     => true,
					'overlayTemplatePartCount'     => 2,
					'overlayTemplatePartSlugs'     => [ 'header-overlay', 'mobile-overlay' ],
					'siteHasDedicatedOverlayParts' => true,
					'siteOverlayTemplatePartCount' => 2,
					'siteOverlayTemplatePartSlugs' => [ 'header-overlay', 'mobile-overlay' ],
					'overlayReferenceScope'        => 'scoped',
					'overlayReferenceSource'       => 'location-heuristic',
				],
				'overlayTemplateParts' => [
					[
						'slug'  => 'header-overlay',
						'title' => 'Header Overlay',
					],
					[
						'slug'  => 'mobile-overlay',
						'title' => 'Mobile Overlay',
					],
				],
			]
		);

		$this->assertIsString( $baseline_signature );
		$this->assertIsString( $changed_signature );
		$this->assertSame( $baseline_signature, $changed_signature );
	}

	public function test_recommend_navigation_review_signature_ignores_docs_guidance_changes(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$input   = [
			'navigationMarkup'     => '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- /wp:navigation -->',
			'prompt'               => 'Simplify the header navigation.',
			'resolveSignatureOnly' => true,
		];
		$context = ServerCollector::for_navigation( 0, $input['navigationMarkup'], [] );
		$this->assertIsArray( $context );

		$query_method = new \ReflectionMethod( NavigationAbilities::class, 'build_wordpress_docs_query' );
		$query_method->setAccessible( true );
		$query = $query_method->invoke( null, $context, $input['prompt'] );

		$cache_key_method = new \ReflectionMethod( AISearchClient::class, 'build_cache_key' );
		$cache_key_method->setAccessible( true );
		$cache_key = $cache_key_method->invoke( null, $query, 4 );

		WordPressTestState::$transients[ $cache_key ] = [
			[
				'id'        => 'nav-a',
				'title'     => 'Navigation reference',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
				'excerpt'   => 'Use responsive overlay menus for large navigation sets.',
				'score'     => 0.91,
			],
		];

		$baseline = NavigationAbilities::recommend_navigation( $input );

		WordPressTestState::$transients[ $cache_key ] = [
			[
				'id'        => 'nav-b',
				'title'     => 'Navigation accessibility',
				'sourceKey' => 'developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
				'url'       => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/navigation/',
				'excerpt'   => 'Keep top-level navigation labels short and predictable for keyboard users.',
				'score'     => 0.93,
			],
		];
		WordPressTestState::$last_remote_post         = [];

		$changed = NavigationAbilities::recommend_navigation( $input );

		$this->assertIsArray( $baseline );
		$this->assertIsArray( $changed );
		$this->assertSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_navigation_review_signature_changes_when_saved_menu_structure_changes_for_ref_only_markup(): void {
		$input = [
			'menuId'               => 42,
			'navigationMarkup'     => '<!-- wp:navigation {"ref":42} /-->',
			'prompt'               => 'Simplify the header navigation.',
			'resolveSignatureOnly' => true,
		];

		WordPressTestState::$posts[42] = (object) [
			'ID'           => 42,
			'post_type'    => 'wp_navigation',
			'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->'
				. '<!-- wp:navigation-link {"label":"Contact","url":"/contact"} /-->',
		];

		$baseline_result = NavigationAbilities::recommend_navigation( $input );

		WordPressTestState::$posts[42]->post_content =
			'<!-- wp:navigation-link {"label":"Home","url":"/"} /-->'
			. '<!-- wp:navigation-submenu {"label":"Company","url":"/company"} -->'
			. '<!-- wp:navigation-link {"label":"Team","url":"/company/team"} /-->'
			. '<!-- /wp:navigation-submenu -->';

		$changed_result = NavigationAbilities::recommend_navigation( $input );

		$this->assertIsArray( $baseline_result );
		$this->assertIsArray( $changed_result );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $baseline_result['reviewContextSignature'] ?? '' )
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $changed_result['reviewContextSignature'] ?? '' )
		);
		$this->assertNotSame(
			$baseline_result['reviewContextSignature'] ?? null,
			$changed_result['reviewContextSignature'] ?? null
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_build_wordpress_docs_query_includes_nested_navigation_guidance_for_deep_menus(): void {
		$method = new \ReflectionMethod( NavigationAbilities::class, 'build_wordpress_docs_query' );
		$method->setAccessible( true );

		$query = $method->invoke(
			null,
			[
				'location'             => 'header',
				'maxDepth'             => 3,
				'attributes'           => [],
				'overlayTemplateParts' => [],
				'structureSummary'     => [
					'hasPageList' => false,
				],
			],
			'Simplify the menu.'
		);

		$this->assertIsString( $query );
		$this->assertStringContainsString( 'nested navigation depth', $query );
	}

	public function test_prompt_build_user_includes_menu_structure(): void {
		$context = [
			'menuId'               => 42,
			'location'             => 'header',
			'locationDetails'      => [
				'area'   => 'header',
				'source' => 'template-part-scan',
			],
			'attributes'           => [
				'overlayMenu'         => 'mobile',
				'hasIcon'             => true,
				'openSubmenusOnClick' => false,
			],
			'menuItems'            => [
				[
					'type'  => 'navigation-link',
					'path'  => [ 0 ],
					'depth' => 0,
					'label' => 'Home',
					'url'   => '/',
				],
				[
					'type'     => 'navigation-submenu',
					'path'     => [ 1 ],
					'depth'    => 0,
					'label'    => 'About',
					'url'      => '/about',
					'children' => [
						[
							'type'  => 'navigation-link',
							'path'  => [ 1, 0 ],
							'depth' => 1,
							'label' => 'Team',
							'url'   => '/about/team',
						],
					],
				],
			],
			'targetInventory'      => [
				[
					'path'  => [ 0 ],
					'label' => 'Home',
					'type'  => 'navigation-link',
					'depth' => 0,
				],
				[
					'path'  => [ 1 ],
					'label' => 'About',
					'type'  => 'navigation-submenu',
					'depth' => 0,
				],
				[
					'path'  => [ 1, 0 ],
					'label' => 'Team',
					'type'  => 'navigation-link',
					'depth' => 1,
				],
			],
			'overlayTemplateParts' => [
				[
					'slug'  => 'mobile-overlay',
					'title' => 'Mobile Overlay',
					'area'  => 'navigation-overlay',
				],
			],
			'structureSummary'     => [
				'topLevelCount'  => 2,
				'submenuCount'   => 1,
				'topLevelLabels' => [ 'Home', 'About' ],
			],
			'menuItemCount'        => 3,
			'maxDepth'             => 2,
			'overlayContext'       => [
				'usesOverlay'                  => true,
				'overlayMode'                  => 'mobile',
				'hasDedicatedOverlayParts'     => true,
				'siteHasDedicatedOverlayParts' => true,
				'siteOverlayTemplatePartCount' => 2,
				'siteOverlayTemplatePartSlugs' => [ 'mobile-overlay', 'footer-overlay' ],
			],
			'editorContext'        => [
				'block'               => [
					'name'               => 'core/navigation',
					'title'              => 'Navigation',
					'structuralIdentity' => [
						'role'             => 'header-navigation',
						'location'         => 'header',
						'templateArea'     => 'header',
						'templatePartSlug' => 'site-header',
					],
				],
				'siblingsBefore'      => [ 'core/site-logo' ],
				'siblingsAfter'       => [ 'core/buttons' ],
				'structuralAncestors' => [
					[
						'block'            => 'core/template-part',
						'role'             => 'header-slot',
						'location'         => 'header',
						'templateArea'     => 'header',
						'templatePartSlug' => 'site-header',
					],
				],
				'structuralBranch'    => [
					[
						'block'            => 'core/template-part',
						'role'             => 'header-slot',
						'location'         => 'header',
						'templateArea'     => 'header',
						'templatePartSlug' => 'site-header',
						'children'         => [
							[
								'block'    => 'core/navigation',
								'role'     => 'header-navigation',
								'location' => 'header',
							],
						],
					],
				],
			],
			'themeTokens'          => [
				'colors'          => [ 'primary: #0073aa' ],
				'fontSizes'       => [ 'small: 0.875rem' ],
				'fontFamilies'    => [ 'inter: Inter, sans-serif' ],
				'spacing'         => [ '20: 0.5rem' ],
				'gradients'       => [ 'hero: linear-gradient(180deg, #fff, #ddd)' ],
				'shadows'         => [ 'natural: 6px 6px 9px rgba(0,0,0,0.2)' ],
				'layout'          => [
					'content'                       => '650px',
					'wide'                          => '1200px',
					'allowEditing'                  => true,
					'allowCustomContentAndWideSize' => true,
				],
				'enabledFeatures' => [
					'backgroundColor' => true,
					'textColor'       => true,
				],
			],
		];

		$prompt = NavigationPrompt::build_user( $context, 'Simplify the header nav.' );

		$this->assertStringContainsString( '## Navigation', $prompt );
		$this->assertStringContainsString( 'Location: header', $prompt );
		$this->assertStringContainsString( 'Menu id: 42', $prompt );
		$this->assertStringContainsString( 'Menu item count: 3', $prompt );
		$this->assertStringContainsString( 'Max depth: 2', $prompt );
		$this->assertStringContainsString( '## Location Context', $prompt );
		$this->assertStringContainsString( '`source`: template-part-scan', $prompt );
		$this->assertStringContainsString( '## Live Editor Context', $prompt );
		$this->assertStringContainsString( '`templatePartSlug`: site-header', $prompt );
		$this->assertStringContainsString( '`siblingsBefore`: core/site-logo', $prompt );
		$this->assertStringContainsString( '## Current Attributes', $prompt );
		$this->assertStringContainsString( '`overlayMenu`: mobile', $prompt );
		$this->assertStringContainsString( '## Menu Structure', $prompt );
		$this->assertStringContainsString( '"Home"', $prompt );
		$this->assertStringContainsString( '"About"', $prompt );
		$this->assertStringContainsString( '"Team"', $prompt );
		$this->assertStringContainsString( '## Current Menu Target Inventory', $prompt );
		$this->assertStringContainsString( '[1, 0] `navigation-link` "Team" depth=1', $prompt );
		$this->assertStringContainsString( '## Structure Summary', $prompt );
		$this->assertStringContainsString( '`topLevelCount`: 2', $prompt );
		$this->assertStringContainsString( '## Navigation Overlay Template Parts', $prompt );
		$this->assertStringContainsString( 'mobile-overlay', $prompt );
		$this->assertStringContainsString( '## Overlay Context', $prompt );
		$this->assertStringContainsString( '`hasDedicatedOverlayParts`: true', $prompt );
		$this->assertStringContainsString( '`siteOverlayTemplatePartCount`: 2', $prompt );
		$this->assertStringContainsString( '## Theme Design Tokens', $prompt );
		$this->assertStringContainsString( 'Gradients: hero: linear-gradient', $prompt );
		$this->assertStringContainsString( 'Shadows: natural: 6px 6px 9px rgba(0,0,0,0.2)', $prompt );
		$this->assertStringContainsString( 'Layout: {"content":"650px","wide":"1200px","allowEditing":true,"allowCustomContentAndWideSize":true}', $prompt );
		$this->assertStringContainsString( 'Enabled features: {"backgroundColor":true,"textColor":true}', $prompt );
		$this->assertStringContainsString( '## User Instruction', $prompt );
		$this->assertStringContainsString( 'Simplify the header nav.', $prompt );
	}

	public function test_navigation_prompt_build_system_includes_theme_capability_constraints(): void {
		$system = NavigationPrompt::build_system();

		$this->assertStringContainsString( 'Treat enabledFeatures and layout in Theme Design Tokens as hard capability constraints.', $system );
		$this->assertStringContainsString( 'do not recommend changes that rely on disabled features or unsupported layout capabilities.', $system );
	}

	public function test_prompt_build_user_handles_empty_menu(): void {
		$context = [
			'location'             => 'unknown',
			'attributes'           => [],
			'menuItems'            => [],
			'overlayTemplateParts' => [],
			'themeTokens'          => [],
		];

		$prompt = NavigationPrompt::build_user( $context );

		$this->assertStringContainsString( 'No menu items found', $prompt );
		$this->assertStringNotContainsString( '## Navigation Overlay', $prompt );
		$this->assertStringNotContainsString( '## User Instruction', $prompt );
	}

	public function test_prompt_build_user_includes_docs_guidance(): void {
		$context = [
			'location'             => 'header',
			'attributes'           => [],
			'menuItems'            => [],
			'overlayTemplateParts' => [],
			'themeTokens'          => [],
		];

		$prompt = NavigationPrompt::build_user(
			$context,
			'',
			[
				[
					'title'   => 'Navigation block reference',
					'excerpt' => 'The navigation block supports responsive overlay menus.',
				],
			]
		);

		$this->assertStringContainsString( '## WordPress Developer Guidance', $prompt );
		$this->assertStringContainsString( 'Navigation block reference: The navigation block supports responsive overlay menus.', $prompt );
	}

	public function test_parse_response_validates_suggestion_categories(): void {
		$context = [
			'targetInventory' => [
				[
					'path'  => [ 0 ],
					'label' => 'Home',
					'type'  => 'navigation-link',
					'depth' => 0,
				],
				[
					'path'  => [ 1 ],
					'label' => 'About',
					'type'  => 'navigation-submenu',
					'depth' => 0,
				],
			],
		];

		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Group related pages',
						'description' => 'Move About and Team under a single submenu.',
						'category'    => 'structure',
						'changes'     => [
							[
								'type'       => 'group',
								'targetPath' => [ 1 ],
								'target'     => 'About submenu',
								'detail'     => 'Create About submenu containing Team.',
							],
						],
					],
					[
						'label'       => 'Invalid category suggestion',
						'description' => 'This has a bad category.',
						'category'    => 'nonexistent',
						'changes'     => [
							[
								'type'       => 'reorder',
								'targetPath' => [ 0 ],
								'target'     => 'Home link',
								'detail'     => 'Move it.',
							],
						],
					],
				],
				'explanation' => 'Grouping simplifies the top-level menu.',
			]
		);

		$result = NavigationPrompt::parse_response( $raw, $context );

		$this->assertCount( 2, $result['suggestions'] );
		$this->assertSame( 'structure', $result['suggestions'][0]['category'] );
		$this->assertSame( [ 1 ], $result['suggestions'][0]['changes'][0]['targetPath'] );
		// Invalid category falls back to 'structure'.
		$this->assertSame( 'structure', $result['suggestions'][1]['category'] );
		$this->assertSame( 'Grouping simplifies the top-level menu.', $result['explanation'] );
	}

	public function test_parse_response_rejects_invalid_change_types(): void {
		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Bad changes only',
						'description' => 'All changes have invalid types.',
						'category'    => 'structure',
						'changes'     => [
							[
								'type'   => 'delete',
								'target' => 'Something',
								'detail' => 'Remove it.',
							],
						],
					],
					[
						'label'       => 'Mixed changes',
						'description' => 'One good, one bad.',
						'category'    => 'overlay',
						'changes'     => [
							[
								'type'   => 'invalid-type',
								'target' => 'Bad',
								'detail' => 'Nope.',
							],
							[
								'type'   => 'set-attribute',
								'target' => 'overlayMenu',
								'detail' => 'Set to always for full-screen overlay.',
							],
						],
					],
				],
				'explanation' => 'Testing validation.',
			]
		);

		$result = NavigationPrompt::parse_response( $raw, [] );

		// First suggestion has no valid changes -> filtered out.
		// Second suggestion keeps only the valid set-attribute change.
		$this->assertCount( 1, $result['suggestions'] );
		$this->assertSame( 'Mixed changes', $result['suggestions'][0]['label'] );
		$this->assertCount( 1, $result['suggestions'][0]['changes'] );
		$this->assertSame( 'set-attribute', $result['suggestions'][0]['changes'][0]['type'] );
	}

	public function test_parse_response_rejects_unknown_set_attribute_targets(): void {
		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Keep valid overlay changes only',
						'description' => 'Ignore unsupported set-attribute targets.',
						'category'    => 'overlay',
						'changes'     => [
							[
								'type'   => 'set-attribute',
								'target' => 'className',
								'detail' => 'This should be dropped.',
							],
							[
								'type'   => 'set-attribute',
								'target' => 'overlayMenu',
								'detail' => 'Use mobile overlay mode.',
							],
						],
					],
				],
			]
		);

		$result = NavigationPrompt::parse_response( $raw, [] );

		$this->assertCount( 1, $result['suggestions'] );
		$this->assertSame(
			[
				[
					'type'   => 'set-attribute',
					'target' => 'overlayMenu',
					'detail' => 'Use mobile overlay mode.',
				],
			],
			$result['suggestions'][0]['changes']
		);
	}

	public function test_parse_response_rejects_structural_changes_for_unknown_target_paths(): void {
		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Unknown target path',
						'description' => 'This should be dropped.',
						'category'    => 'structure',
						'changes'     => [
							[
								'type'       => 'group',
								'targetPath' => [ 9 ],
								'target'     => 'Missing branch',
								'detail'     => 'Group this branch.',
							],
						],
					],
				],
			]
		);

		$result = NavigationPrompt::parse_response(
			$raw,
			[
				'targetInventory' => [
					[
						'path'  => [ 0 ],
						'label' => 'Home',
						'type'  => 'navigation-link',
						'depth' => 0,
					],
				],
			]
		);

		$this->assertSame( [], $result['suggestions'] );
	}

	public function test_parse_response_handles_malformed_json(): void {
		$result = NavigationPrompt::parse_response( 'not json at all', [] );

		$this->assertSame( [], $result['suggestions'] );
		$this->assertSame( '', $result['explanation'] );
	}

	public function test_parse_response_strips_markdown_fences(): void {
		$json = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Fenced suggestion',
						'description' => 'Wrapped in markdown code fences.',
						'category'    => 'accessibility',
						'changes'     => [
							[
								'type'   => 'set-attribute',
								'target' => 'showSubmenuIcon',
								'detail' => 'Enable submenu indicators for keyboard users.',
							],
						],
					],
				],
				'explanation' => 'Accessibility improvement.',
			]
		);

		$fenced = "```json\n{$json}\n```";
		$result = NavigationPrompt::parse_response( $fenced, [] );

		$this->assertCount( 1, $result['suggestions'] );
		$this->assertSame( 'accessibility', $result['suggestions'][0]['category'] );
	}

	public function test_parse_response_limits_to_three_suggestions(): void {
		$suggestions = [];
		for ( $i = 1; $i <= 5; $i++ ) {
			$suggestions[] = [
				'label'       => "Suggestion {$i}",
				'description' => "Description for suggestion {$i}.",
				'category'    => 'structure',
				'changes'     => [
					[
						'type'       => 'reorder',
						'targetPath' => [ $i - 1 ],
						'target'     => "Item {$i}",
						'detail'     => "Move item {$i}.",
					],
				],
			];
		}

		$raw    = wp_json_encode(
			[
				'suggestions' => $suggestions,
				'explanation' => 'Five suggestions submitted.',
			]
		);
		$result = NavigationPrompt::parse_response(
			$raw,
			[
				'targetInventory' => [
					[
						'path'  => [ 0 ],
						'label' => 'Item 1',
						'type'  => 'navigation-link',
						'depth' => 0,
					],
					[
						'path'  => [ 1 ],
						'label' => 'Item 2',
						'type'  => 'navigation-link',
						'depth' => 0,
					],
					[
						'path'  => [ 2 ],
						'label' => 'Item 3',
						'type'  => 'navigation-link',
						'depth' => 0,
					],
					[
						'path'  => [ 3 ],
						'label' => 'Item 4',
						'type'  => 'navigation-link',
						'depth' => 0,
					],
					[
						'path'  => [ 4 ],
						'label' => 'Item 5',
						'type'  => 'navigation-link',
						'depth' => 0,
					],
				],
			]
		);

		$this->assertCount( 3, $result['suggestions'] );
	}

	public function test_system_prompt_mentions_navigation_overlay_area(): void {
		$system = NavigationPrompt::build_system();

		$this->assertStringContainsString( 'navigation-overlay', $system );
		$this->assertStringContainsString( 'template-part area', $system );
		$this->assertStringContainsString( 'WordPress 7.0', $system );
	}

	public function test_system_prompt_lists_allowed_change_types(): void {
		$system = NavigationPrompt::build_system();

		$this->assertStringContainsString( 'reorder', $system );
		$this->assertStringContainsString( 'group', $system );
		$this->assertStringContainsString( 'add-submenu', $system );
		$this->assertStringContainsString( 'set-attribute', $system );
		$this->assertStringContainsString( 'targetPath', $system );
		$this->assertStringContainsString( 'overlayMenu', $system );
		$this->assertStringContainsString( 'openSubmenusOnClick', $system );
	}

	public function test_parse_response_prefers_explicit_score_over_confidence_for_sorting(): void {
		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Explicit score navigation idea',
						'description' => 'This should sort first.',
						'category'    => 'structure',
						'score'       => 0.9,
						'confidence'  => 0.11,
						'changes'     => [
							[
								'type'       => 'reorder',
								'targetPath' => [ 1 ],
								'target'     => 'About link',
								'detail'     => 'Move About later.',
							],
						],
					],
					[
						'label'       => 'Confidence navigation idea',
						'description' => 'This should sort second.',
						'category'    => 'structure',
						'confidence'  => 0.82,
						'changes'     => [
							[
								'type'       => 'reorder',
								'targetPath' => [ 0 ],
								'target'     => 'Home link',
								'detail'     => 'Move Home later.',
							],
						],
					],
				],
				'explanation' => 'Explicit scores should drive ordering.',
			]
		);

		$result = NavigationPrompt::parse_response(
			$raw,
			[
				'targetInventory' => [
					[
						'path'  => [ 0 ],
						'label' => 'Home',
						'type'  => 'navigation-link',
						'depth' => 0,
					],
					[
						'path'  => [ 1 ],
						'label' => 'About',
						'type'  => 'navigation-link',
						'depth' => 0,
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Explicit score navigation idea', $result['suggestions'][0]['label'] );
		$this->assertSame( 0.9, $result['suggestions'][0]['ranking']['score'] );
	}

	public function test_parse_response_falls_back_when_nested_ranking_score_is_malformed(): void {
		$raw = wp_json_encode(
			[
				'suggestions' => [
					[
						'label'       => 'Fallback confidence navigation idea',
						'description' => 'A malformed nested score should not zero this out.',
						'category'    => 'structure',
						'ranking'     => [
							'score' => [],
						],
						'confidence'  => 0.86,
						'changes'     => [
							[
								'type'       => 'reorder',
								'targetPath' => [ 1 ],
								'target'     => 'About link',
								'detail'     => 'Move About later.',
							],
						],
					],
					[
						'label'       => 'Lower confidence navigation idea',
						'description' => 'This should sort second.',
						'category'    => 'structure',
						'confidence'  => 0.82,
						'changes'     => [
							[
								'type'       => 'reorder',
								'targetPath' => [ 0 ],
								'target'     => 'Home link',
								'detail'     => 'Move Home later.',
							],
						],
					],
				],
				'explanation' => 'Malformed nested scores should not suppress valid fallback confidence.',
			]
		);

		$result = NavigationPrompt::parse_response(
			$raw,
			[
				'targetInventory' => [
					[
						'path'  => [ 0 ],
						'label' => 'Home',
						'type'  => 'navigation-link',
						'depth' => 0,
					],
					[
						'path'  => [ 1 ],
						'label' => 'About',
						'type'  => 'navigation-link',
						'depth' => 0,
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Fallback confidence navigation idea', $result['suggestions'][0]['label'] );
		$this->assertSame( 0.86, $result['suggestions'][0]['ranking']['score'] );
	}
}
