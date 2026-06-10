<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\StyleAbilities;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ApplyAbilitiesTest extends TestCase {

	private const GLOBAL_STYLES_ID = '17';

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		WordPressTestState::$capabilities    = [
			'edit_theme_options' => true,
			'edit_posts'         => true,
		];
		Repository::install();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [],
			]
		);
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
		// A resolvable merged background complement so a solo text-color
		// operation can pass the executor's contrast check at approval time.
		WordPressTestState::$global_styles = [
			'color' => [ 'background' => '#fefefe' ],
		];
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
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function agent_request_input( array $overrides = [] ): array {
		$scope         = [
			'surface'        => 'global-styles',
			'globalStylesId' => self::GLOBAL_STYLES_ID,
		];
		$style_context = [
			'currentConfig' => [
				'settings' => [],
				'styles'   => [],
			],
			'mergedConfig'  => [
				'settings' => [],
				'styles'   => [],
			],
		];
		$signatures    = StyleAbilities::recommend_style(
			[
				'scope'                => $scope,
				'styleContext'         => $style_context,
				'prompt'               => 'darker',
				'resolveSignatureOnly' => true,
			]
		);
		$this->assertIsArray( $signatures );

		return array_replace_recursive(
			[
				'scope'            => $scope,
				'styleContext'     => $style_context,
				'prompt'           => 'darker',
				'operations'       => [
					[
						'type'       => 'set_styles',
						'path'       => [ 'color', 'text' ],
						'value'      => 'var:preset|color|accent',
						'valueType'  => 'preset',
						'presetType' => 'color',
						'presetSlug' => 'accent',
					],
				],
				'signatures'       => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
				'suggestion'       => [ 'label' => 'Use the accent text preset' ],
				'requestReference' => 'agent-req-1',
			],
			$overrides
		);
	}

	public function test_request_style_apply_creates_a_pending_row_with_lifecycle_payload(): void {
		$result = ApplyAbilities::request_style_apply( $this->agent_request_input() );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertNotSame( '', (string) $result['activityId'] );
		$this->assertNotSame( '', (string) $result['expiresAt'] );

		$entry = Repository::find( (string) $result['activityId'] );
		$this->assertIsArray( $entry );
		$this->assertSame( 'apply_global_styles_suggestion', $entry['type'] );
		$this->assertSame( 'global-styles', $entry['surface'] );
		$this->assertSame( 'pending', $entry['executionResult'] );
		$this->assertSame( 'not_applicable', $entry['undo']['status'] );
		$this->assertSame( [], $entry['before'] );
		$this->assertSame( 7, $entry['apply']['requestedBy'] );
		$this->assertSame( 'agent-req-1', $entry['apply']['requestReference'] );
		$this->assertSame( 'global_styles:17', $entry['document']['scopeKey'] );
		$this->assertCount( 1, $entry['apply']['operations'] );
		$this->assertSame( 64, strlen( (string) $entry['apply']['signatures']['baselineConfigHash'] ) );
	}

	public function test_request_style_apply_rejects_mismatched_signatures_and_records_stale_blocked(): void {
		$input = $this->agent_request_input();
		$input['signatures']['resolvedContextSignature'] = str_repeat( 'f', 64 );

		$result = ApplyAbilities::request_style_apply( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );

		$diagnostics = Repository::query(
			[
				'scopeKey'           => 'global_styles:17',
				'includeDiagnostics' => true,
			]
		);
		$outcomes    = array_values(
			array_filter(
				$diagnostics,
				static fn ( array $entry ): bool => 'recommendation_outcome' === (string) ( $entry['type'] ?? '' )
			)
		);
		$this->assertCount( 1, $outcomes );
		$this->assertSame( 'stale_blocked', $outcomes[0]['after']['outcome']['event'] );
	}

	public function test_request_style_apply_rejects_when_the_live_entity_drifted_from_the_claimed_config(): void {
		$input = $this->agent_request_input();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#444444' ] ],
			]
		);

		$result = ApplyAbilities::request_style_apply( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );
	}

	public function test_request_style_apply_enforces_the_per_user_pending_cap(): void {
		add_filter( 'flavor_agent_external_apply_pending_cap', static fn(): int => 1 );

		$first = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $first );

		$second = ApplyAbilities::request_style_apply( $this->agent_request_input() );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'flavor_agent_apply_queue_full', $second->get_error_code() );
	}

	public function test_request_style_apply_rejects_operations_failing_the_live_contract_without_creating_a_row(): void {
		$result = ApplyAbilities::request_style_apply(
			$this->agent_request_input(
				[
					'operations' => [
						[
							'type'       => 'set_styles',
							'path'       => [ 'color', 'text' ],
							'value'      => 'var:preset|color|nope',
							'valueType'  => 'preset',
							'presetType' => 'color',
							'presetSlug' => 'nope',
						],
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame(
			[],
			Repository::query( [ 'scopeKey' => 'global_styles:17' ] ),
			'Invalid operations must not enqueue a pending row.'
		);
	}

	public function test_request_style_apply_requires_a_supported_scope(): void {
		$result = ApplyAbilities::request_style_apply(
			$this->agent_request_input( [ 'scope' => [ 'surface' => 'navigation' ] ] )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_style_scope', $result->get_error_code() );
	}
}
