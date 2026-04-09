<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\TemplateAbilities;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TemplateAbilitiesTest extends TestCase {

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
			'wp_template'      => [
				(object) [
					'id'      => 'theme//home',
					'slug'    => 'home',
					'title'   => 'Home',
					'content' => '<!-- wp:group {"tagName":"main"} --><div>Main</div><!-- /wp:group -->',
				],
			],
			'wp_template_part' => [
				(object) [
					'id'      => 'theme//header',
					'slug'    => 'header',
					'title'   => 'Header',
					'area'    => 'header',
					'content' => '<!-- wp:group {"tagName":"header"} --><div>Header</div><!-- /wp:group -->',
				],
			],
		];
		WordPressTestState::$global_settings = [
			'color'      => [
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#ff5500',
					],
				],
			],
			'typography' => [
				'fontSizes' => [
					[
						'slug' => 'body',
						'size' => '1rem',
					],
				],
			],
			'spacing'    => [
				'spacingSizes' => [
					[
						'slug' => '50',
						'size' => '1.5rem',
					],
				],
			],
		];

		$this->register_pattern(
			'theme/hero',
			[
				'title'         => 'Hero',
				'templateTypes' => [ 'home' ],
				'content'       => '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->',
			]
		);
		$this->register_pattern(
			'theme/header-utility',
			[
				'title'      => 'Header Utility',
				'categories' => [ 'header' ],
				'blockTypes' => [ 'core/template-part/header' ],
				'content'    => '<!-- wp:paragraph --><p>Header Utility</p><!-- /wp:paragraph -->',
			]
		);
	}

	protected function tearDown(): void {
		\remove_filter(
			'flavor_agent_cloudflare_ai_search_public_search_url',
			$this->disable_public_docs_filter
		);

		parent::tearDown();
	}

	public function test_recommend_template_resolve_signature_only_returns_review_and_resolved_signatures(): void {
		$result = TemplateAbilities::recommend_template(
			[
				'templateRef'          => 'theme//home',
				'templateType'         => 'home',
				'prompt'               => 'Make the home template feel more editorial.',
				'visiblePatternNames'  => [ 'theme/hero' ],
				'resolveSignatureOnly' => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				'reviewContextSignature'   => $result['reviewContextSignature'] ?? null,
				'resolvedContextSignature' => $result['resolvedContextSignature'] ?? null,
			],
			$result
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $result['reviewContextSignature'] ?? '' )
		);
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			(string) ( $result['resolvedContextSignature'] ?? '' )
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_recommend_template_review_and_resolved_signatures_ignore_docs_guidance_changes(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$input   = [
			'templateRef'          => 'theme//home',
			'templateType'         => 'home',
			'prompt'               => 'Make the home template feel more editorial.',
			'visiblePatternNames'  => [ 'theme/hero' ],
			'resolveSignatureOnly' => true,
		];
		$context = ServerCollector::for_template( 'theme//home', 'home', [ 'theme/hero' ] );
		$this->assertIsArray( $context );
		$context['visiblePatternNames'] = [ 'theme/hero' ];
		$query                          = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_wordpress_docs_query',
			[
				$context,
				$input['prompt'],
			]
		);

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [
			[
				'id'        => 'chunk-template-a',
				'title'     => 'Template reference',
				'sourceKey' => 'developer.wordpress.org/themes/templates/introduction-to-templates/',
				'url'       => 'https://developer.wordpress.org/themes/templates/introduction-to-templates/',
				'excerpt'   => 'Use template structure to reinforce the hierarchy of the page.',
				'score'     => 0.91,
			],
		];

		$baseline = TemplateAbilities::recommend_template( $input );

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [
			[
				'id'        => 'chunk-template-b',
				'title'     => 'Template part guidance',
				'sourceKey' => 'developer.wordpress.org/themes/templates/template-parts/',
				'url'       => 'https://developer.wordpress.org/themes/templates/template-parts/',
				'excerpt'   => 'Favor reusable template parts for stable structural sections.',
				'score'     => 0.93,
			],
		];
		WordPressTestState::$last_remote_post                                  = [];

		$changed = TemplateAbilities::recommend_template( $input );

		$this->assertIsArray( $baseline );
		$this->assertIsArray( $changed );
		$this->assertSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
		$this->assertSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_recommend_template_part_review_and_resolved_signatures_ignore_docs_guidance_changes(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$input   = [
			'templatePartRef'      => 'theme//header',
			'prompt'               => 'Give the header a cleaner utility rhythm.',
			'visiblePatternNames'  => [ 'theme/header-utility' ],
			'resolveSignatureOnly' => true,
		];
		$context = ServerCollector::for_template_part( 'theme//header', [ 'theme/header-utility' ] );
		$this->assertIsArray( $context );
		$context['visiblePatternNames'] = [ 'theme/header-utility' ];
		$query                          = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_part_wordpress_docs_query',
			[
				$context,
				$input['prompt'],
			]
		);

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [
			[
				'id'        => 'chunk-template-part-a',
				'title'     => 'Header template parts',
				'sourceKey' => 'developer.wordpress.org/themes/templates/template-parts/',
				'url'       => 'https://developer.wordpress.org/themes/templates/template-parts/',
				'excerpt'   => 'Keep header parts focused on branding, navigation, and lightweight utility actions.',
				'score'     => 0.89,
			],
		];

		$baseline = TemplateAbilities::recommend_template_part( $input );

		WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [
			[
				'id'        => 'chunk-template-part-b',
				'title'     => 'Block theme guidance',
				'sourceKey' => 'developer.wordpress.org/themes/global-settings-and-styles/',
				'url'       => 'https://developer.wordpress.org/themes/global-settings-and-styles/',
				'excerpt'   => 'Use theme tokens and restrained patterns so the header remains coherent with the overall site styles.',
				'score'     => 0.92,
			],
		];
		WordPressTestState::$last_remote_post                                  = [];

		$changed = TemplateAbilities::recommend_template_part( $input );

		$this->assertIsArray( $baseline );
		$this->assertIsArray( $changed );
		$this->assertSame(
			$baseline['resolvedContextSignature'] ?? null,
			$changed['resolvedContextSignature'] ?? null
		);
		$this->assertSame(
			$baseline['reviewContextSignature'] ?? null,
			$changed['reviewContextSignature'] ?? null
		);
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	/**
	 * @dataProvider template_review_theme_token_signature_provider
	 */
	public function test_template_review_signature_changes_when_formatter_relevant_theme_tokens_change( array $baseline_theme_tokens, array $changed_theme_tokens ): void {
		$baseline_signature = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				[
					'patterns'    => [],
					'themeTokens' => $baseline_theme_tokens,
				],
			]
		);
		$changed_signature  = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				[
					'patterns'    => [],
					'themeTokens' => $changed_theme_tokens,
				],
			]
		);

		$this->assertNotSame( $baseline_signature, $changed_signature );
	}

	/**
	 * @dataProvider template_review_theme_token_signature_provider
	 */
	public function test_template_part_review_signature_changes_when_formatter_relevant_theme_tokens_change( array $baseline_theme_tokens, array $changed_theme_tokens ): void {
		$baseline_signature = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_part_review_context_signature',
			[
				[
					'patterns'    => [],
					'themeTokens' => $baseline_theme_tokens,
				],
			]
		);
		$changed_signature  = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_part_review_context_signature',
			[
				[
					'patterns'    => [],
					'themeTokens' => $changed_theme_tokens,
				],
			]
		);

		$this->assertNotSame( $baseline_signature, $changed_signature );
	}

	public function test_template_review_signature_ignores_theme_token_changes_removed_by_formatter_budget(): void {
		$baseline_signature = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				[
					'patterns'    => [],
					'themeTokens' => [
						'layout'          => [
							'content' => str_repeat( 'x', 2100 ),
						],
						'enabledFeatures' => [
							'backgroundColor' => true,
						],
					],
				],
			]
		);
		$changed_signature  = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				[
					'patterns'    => [],
					'themeTokens' => [
						'layout'          => [
							'content' => str_repeat( 'y', 2100 ),
						],
						'enabledFeatures' => [
							'backgroundColor' => true,
						],
					],
				],
			]
		);

		$this->assertSame( $baseline_signature, $changed_signature );
	}

	public function test_template_review_signature_changes_when_server_template_inventory_changes(): void {
		$baseline_signature = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				$this->build_template_review_signature_context(),
			]
		);
		$changed_signature  = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				$this->build_template_review_signature_context(
					[
						'allowedAreas'  => [ 'footer', 'header' ],
						'availableParts' => [
							[
								'slug'  => 'site-header',
								'title' => 'Site Header',
								'area'  => 'header',
							],
							[
								'slug'  => 'footer-secondary',
								'title' => 'Footer Secondary',
								'area'  => 'footer',
							],
						],
					]
				),
			]
		);

		$this->assertNotSame( $baseline_signature, $changed_signature );
	}

	public function test_template_review_signature_changes_when_template_identity_changes(): void {
		$baseline_signature = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				$this->build_template_review_signature_context(),
			]
		);
		$changed_signature  = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_review_context_signature',
			[
				$this->build_template_review_signature_context(
					[
						'templateType' => 'front-page',
						'title'        => 'Front Page',
					]
				),
			]
		);

		$this->assertNotSame( $baseline_signature, $changed_signature );
	}

	public function test_template_part_review_signature_changes_when_template_part_identity_changes(): void {
		$baseline_signature = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_part_review_context_signature',
			[
				$this->build_template_part_review_signature_context(),
			]
		);
		$changed_signature  = $this->invoke_private_string_method(
			TemplateAbilities::class,
			'build_template_part_review_context_signature',
			[
				$this->build_template_part_review_signature_context(
					[
						'title' => 'Utility Header',
						'area'  => 'navigation-overlay',
					]
				),
			]
		);

		$this->assertNotSame( $baseline_signature, $changed_signature );
	}

	/**
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function template_review_theme_token_signature_provider(): array {
		return [
			'gradients'          => [
				[],
				[
					'gradients' => [ 'hero: linear-gradient(180deg, #fff, #ddd)' ],
				],
			],
			'shadows'            => [
				[],
				[
					'shadows' => [ 'natural: 6px 6px 9px rgba(0,0,0,0.2)' ],
				],
			],
			'preset refs'        => [
				[],
				[
					'colorPresets' => [
						[
							'slug'   => 'accent',
							'cssVar' => 'var(--wp--preset--color--accent)',
						],
					],
				],
			],
			'layout'             => [
				[],
				[
					'layout' => [
						'content'                       => '650px',
						'wide'                          => '1200px',
						'allowEditing'                  => true,
						'allowCustomContentAndWideSize' => true,
					],
				],
			],
			'enabled features'   => [
				[],
				[
					'enabledFeatures' => [
						'backgroundColor' => true,
						'textColor'       => true,
					],
				],
			],
			'element style keys' => [
				[],
				[
					'elementStyles' => [
						'link'   => [ 'color' => [ 'text' => '#ff5500' ] ],
						'button' => [ 'color' => [ 'text' => '#ffffff' ] ],
					],
				],
			],
		];
	}

	private function register_pattern( string $name, array $properties ): void {
		\WP_Block_Patterns_Registry::get_instance()->register(
			$name,
			$properties
		);
	}

	private function build_cache_key( string $query, int $max_results ): string {
		$method = new ReflectionMethod( AISearchClient::class, 'build_cache_key' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $query, $max_results );

		$this->assertIsString( $result );

		return $result;
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function build_template_review_signature_context( array $overrides = [] ): array {
		return array_merge(
			[
				'templateType'  => 'home',
				'title'         => 'Home',
				'assignedParts' => [
					[
						'slug' => 'site-header',
						'area' => 'header',
					],
				],
				'allowedAreas'  => [ 'header' ],
				'availableParts' => [
					[
						'slug'  => 'site-header',
						'title' => 'Site Header',
						'area'  => 'header',
					],
					[
						'slug'  => 'footer-main',
						'title' => 'Footer Main',
						'area'  => 'footer',
					],
				],
				'patterns'      => [],
				'themeTokens'   => [],
			],
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function build_template_part_review_signature_context( array $overrides = [] ): array {
		return array_merge(
			[
				'slug'       => 'header',
				'title'      => 'Header',
				'area'       => 'header',
				'patterns'   => [],
				'themeTokens' => [],
			],
			$overrides
		);
	}

	/**
	 * @param array<int, mixed> $arguments
	 */
	private function invoke_private_string_method( string $class_name, string $method_name, array $arguments ): string {
		$method = new ReflectionMethod( $class_name, $method_name );
		$method->setAccessible( true );

		$result = $method->invokeArgs( null, $arguments );

		$this->assertIsString( $result );

		return $result;
	}
}
