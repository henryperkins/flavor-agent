<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Apply\StyleApplyExecutor;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class StyleApplyExecutorTest extends TestCase {

	private const GLOBAL_STYLES_ID = '17';

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [],
			]
		);
		$this->seed_theme_contract();
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function seed_global_styles_post( array $config ): void {
		WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ] = new \WP_Post(
			[
				'ID'           => (int) self::GLOBAL_STYLES_ID,
				'post_type'    => 'wp_global_styles',
				'post_content' => (string) wp_json_encode(
					array_merge(
						[
							'version'                     => 3,
							'isGlobalStylesUserThemeJSON' => true,
						],
						$config
					)
				),
			]
		);
	}

	/**
	 * Theme tokens that make color.text/color.background supported preset
	 * paths with an accent (#111111) and base (#fefefe) palette, mirroring the
	 * shapes ServerCollector::for_tokens() derives from wp_get_global_settings().
	 */
	private function seed_theme_contract(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				'palette'    => [
					'theme' => [
						[
							'slug'  => 'accent',
							'name'  => 'Accent',
							'color' => '#111111',
						],
						[
							'slug'  => 'base',
							'name'  => 'Base',
							'color' => '#fefefe',
						],
					],
				],
				'background' => true,
				'text'       => true,
			],
		];
		WordPressTestState::$global_styles   = [];
	}

	public function test_apply_writes_validated_preset_operations_and_returns_editor_shaped_snapshots(): void {
		$result = StyleApplyExecutor::apply(
			'global-styles',
			self::GLOBAL_STYLES_ID,
			[
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'text' ],
					'value'      => 'var:preset|color|accent',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'accent',
					'cssVar'     => 'var(--wp--preset--color--accent)',
				],
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|color|base',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'base',
					'cssVar'     => 'var(--wp--preset--color--base)',
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ 'globalStylesId' => self::GLOBAL_STYLES_ID ], $result['target'] );
		$this->assertSame( [], $result['before']['userConfig']['styles'] );
		$this->assertSame(
			'var:preset|color|accent',
			$result['after']['userConfig']['styles']['color']['text']
		);
		$this->assertCount( 2, $result['after']['operations'] );
		$this->assertNull( $result['after']['operations'][0]['beforeValue'] );

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( 'var:preset|color|accent', $written['styles']['color']['text'] );
		$this->assertTrue( $written['isGlobalStylesUserThemeJSON'] );
	}

	public function test_apply_rejects_operations_that_fail_the_live_execution_contract(): void {
		$result = StyleApplyExecutor::apply(
			'global-styles',
			self::GLOBAL_STYLES_ID,
			[
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'text' ],
					'value'      => 'var:preset|color|missing-slug',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'missing-slug',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'Invalid operations must not write.' );
	}

	public function test_apply_blocks_failing_contrast_pairs(): void {
		// Near-white text on near-white background fails WCAG AA 4.5.
		WordPressTestState::$global_settings['color']['palette']['theme'][0]['color'] = '#fdfdfd';

		$result = StyleApplyExecutor::apply(
			'global-styles',
			self::GLOBAL_STYLES_ID,
			[
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'text' ],
					'value'      => 'var:preset|color|accent',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'accent',
				],
				[
					'type'       => 'set_styles',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|color|base',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'base',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_contrast_failed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	public function test_apply_resolves_theme_variations_and_replaces_the_user_config(): void {
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#000000' ] ],
			]
		);
		add_filter(
			'flavor_agent_external_apply_theme_variations',
			static fn(): array => [
				[
					'title'    => 'Midnight',
					'settings' => [ 'custom' => [ 'mood' => 'dark' ] ],
					'styles'   => [ 'color' => [ 'background' => '#101010' ] ],
				],
			]
		);

		$result = StyleApplyExecutor::apply(
			'global-styles',
			self::GLOBAL_STYLES_ID,
			[
				[
					'type'           => 'set_theme_variation',
					'variationIndex' => 0,
					'variationTitle' => 'Midnight',
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			[ 'color' => [ 'background' => '#101010' ] ],
			$result['after']['userConfig']['styles']
		);
		$this->assertSame(
			[ 'color' => [ 'text' => '#000000' ] ],
			$result['before']['userConfig']['styles']
		);
	}

	public function test_style_book_apply_targets_the_block_branch_and_trims_snapshots(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'core/paragraph',
			[
				'title'    => 'Paragraph',
				'supports' => [
					'color' => [
						'background' => true,
						'text'       => true,
					],
				],
			]
		);

		$result = StyleApplyExecutor::apply(
			'style-book',
			self::GLOBAL_STYLES_ID,
			[
				[
					'type'       => 'set_block_styles',
					'blockName'  => 'core/paragraph',
					'path'       => [ 'color', 'text' ],
					'value'      => 'var:preset|color|accent',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'accent',
				],
				[
					'type'       => 'set_block_styles',
					'blockName'  => 'core/paragraph',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|color|base',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'base',
				],
			],
			'core/paragraph'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'core/paragraph', $result['target']['blockName'] );
		$this->assertSame( [], $result['before']['userConfig'], 'Absent branch trims to an empty before snapshot.' );
		$this->assertSame(
			'var:preset|color|accent',
			$result['after']['userConfig']['styles']['blocks']['core/paragraph']['color']['text']
		);
		$this->assertArrayNotHasKey(
			'color',
			$result['after']['userConfig']['styles'],
			'Style Book snapshots must contain only the block branch.'
		);

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame(
			'var:preset|color|base',
			$written['styles']['blocks']['core/paragraph']['color']['background']
		);
	}

	public function test_apply_fails_when_the_entity_is_missing(): void {
		$result = StyleApplyExecutor::apply( 'global-styles', '999', [ [ 'type' => 'set_styles' ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}
}
