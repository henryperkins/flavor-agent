<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\AI\Abilities\PreviewRecommendationAbility;
use FlavorAgent\AI\Abilities\PreviewRecommendBlockAbility;
use FlavorAgent\AI\Abilities\PreviewRecommendNavigationAbility;
use FlavorAgent\AI\Abilities\PreviewRecommendStyleAbility;
use FlavorAgent\AI\Abilities\PreviewRecommendTemplateAbility;
use FlavorAgent\AI\Abilities\PreviewRecommendTemplatePartAbility;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use WordPress\AI\Abstracts\Abstract_Ability;

if ( ! \class_exists( PreviewRecommendationFakeParentAbility::class ) ) {
	final class PreviewRecommendationFakeParentAbility extends Abstract_Ability {

		/** @var array<int, mixed> */
		public static array $executions = [];

		/** @var array<int, mixed> */
		public static array $permission_calls = [];

		public static mixed $execution_result = [];

		public static bool $permission_result = true;

		public static function reset(): void {
			self::$executions        = [];
			self::$permission_calls  = [];
			self::$execution_result  = [];
			self::$permission_result = true;
		}

		public function execute_callback( mixed $input ): mixed {
			self::$executions[] = $input;

			return self::$execution_result;
		}

		public function permission_callback( mixed $input = null ): bool {
			self::$permission_calls[] = $input;

			return self::$permission_result;
		}
	}
}

if ( ! \class_exists( PreviewRecommendationFakePreviewAbility::class ) ) {
	final class PreviewRecommendationFakePreviewAbility extends PreviewRecommendationAbility {
		protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-fake';
		protected const PARENT_CLASS   = PreviewRecommendationFakeParentAbility::class;
		protected const PARENT_ABILITY = 'flavor-agent/recommend-fake';
		protected const SIGNATURE_KEYS = [ 'reviewContextSignature', 'resolvedContextSignature' ];
	}
}

final class PreviewRecommendationAbilityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		PreviewRecommendationFakeParentAbility::reset();
	}

	public function test_prepare_parent_input_forces_resolve_signature_only_true(): void {
		$result = PreviewRecommendationAbility::prepare_parent_input(
			[
				'prompt'               => 'hi',
				'resolveSignatureOnly' => false,
			]
		);

		$this->assertTrue( $result['resolveSignatureOnly'] );
	}

	public function test_prepare_parent_input_strips_client_request(): void {
		$result = PreviewRecommendationAbility::prepare_parent_input(
			[
				'prompt'        => 'hi',
				'clientRequest' => [
					'sessionId'    => 'sess-1',
					'requestToken' => 7,
				],
			]
		);

		$this->assertArrayNotHasKey( 'clientRequest', $result );
		$this->assertTrue( $result['resolveSignatureOnly'] );
	}

	public function test_prepare_parent_input_forces_true_even_when_caller_omits_the_field(): void {
		$result = PreviewRecommendationAbility::prepare_parent_input( [ 'prompt' => 'hi' ] );

		$this->assertTrue( $result['resolveSignatureOnly'] );
	}

	public function test_execute_callback_forwards_prepared_input_to_parent(): void {
		PreviewRecommendationFakeParentAbility::$execution_result = [
			'reviewContextSignature'   => 'rev-sig',
			'resolvedContextSignature' => 'res-sig',
		];

		$preview = new PreviewRecommendationFakePreviewAbility( 'flavor-agent/preview-recommend-fake', [] );

		$preview->execute_callback(
			[
				'prompt'               => 'hi',
				'resolveSignatureOnly' => false,
				'clientRequest'        => [ 'sessionId' => 'sess-1', 'requestToken' => 4 ],
			]
		);

		$this->assertCount( 1, PreviewRecommendationFakeParentAbility::$executions );
		$forwarded = PreviewRecommendationFakeParentAbility::$executions[0];

		$this->assertTrue( $forwarded['resolveSignatureOnly'] );
		$this->assertArrayNotHasKey( 'clientRequest', $forwarded );
		$this->assertSame( 'hi', $forwarded['prompt'] );
	}

	public function test_execute_callback_filters_output_to_signature_keys(): void {
		PreviewRecommendationFakeParentAbility::$execution_result = [
			'reviewContextSignature'   => 'rev-sig',
			'resolvedContextSignature' => 'res-sig',
			'suggestions'              => [ [ 'label' => 'do not return me' ] ],
			'requestMeta'              => [ 'model' => 'leaked' ],
		];

		$preview = new PreviewRecommendationFakePreviewAbility( 'flavor-agent/preview-recommend-fake', [] );

		$result = $preview->execute_callback( [ 'prompt' => 'hi' ] );

		$this->assertSame(
			[
				'reviewContextSignature'   => 'rev-sig',
				'resolvedContextSignature' => 'res-sig',
			],
			$result
		);
	}

	public function test_execute_callback_returns_wp_error_from_parent_unchanged(): void {
		PreviewRecommendationFakeParentAbility::$execution_result = new \WP_Error( 'boom', 'kaboom' );

		$preview = new PreviewRecommendationFakePreviewAbility( 'flavor-agent/preview-recommend-fake', [] );

		$result = $preview->execute_callback( [ 'prompt' => 'hi' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'boom', $result->get_error_code() );
	}

	public function test_permission_callback_delegates_to_parent(): void {
		PreviewRecommendationFakeParentAbility::$permission_result = true;

		$preview = new PreviewRecommendationFakePreviewAbility( 'flavor-agent/preview-recommend-fake', [] );

		$this->assertTrue( $preview->permission_callback( [ 'postId' => 42 ] ) );
		$this->assertCount( 1, PreviewRecommendationFakeParentAbility::$permission_calls );
		$this->assertSame( [ 'postId' => 42 ], PreviewRecommendationFakeParentAbility::$permission_calls[0] );
	}

	public function test_permission_callback_returns_false_when_parent_denies(): void {
		PreviewRecommendationFakeParentAbility::$permission_result = false;

		$preview = new PreviewRecommendationFakePreviewAbility( 'flavor-agent/preview-recommend-fake', [] );

		$this->assertFalse( $preview->permission_callback( [ 'postId' => 42 ] ) );
	}

	public function test_output_schema_lists_only_signature_keys(): void {
		$preview = new PreviewRecommendationFakePreviewAbility( 'flavor-agent/preview-recommend-fake', [] );

		$schema = $preview->output_schema();

		$this->assertSame(
			[ 'reviewContextSignature', 'resolvedContextSignature' ],
			\array_keys( $schema['properties'] ?? [] )
		);
		$this->assertFalse( $schema['additionalProperties'] ?? true );
	}

	public function test_input_schema_strips_resolve_signature_only_and_client_request(): void {
		foreach (
			[
				PreviewRecommendBlockAbility::class      => 'flavor-agent/preview-recommend-block',
				PreviewRecommendNavigationAbility::class => 'flavor-agent/preview-recommend-navigation',
				PreviewRecommendStyleAbility::class      => 'flavor-agent/preview-recommend-style',
				PreviewRecommendTemplateAbility::class   => 'flavor-agent/preview-recommend-template',
				PreviewRecommendTemplatePartAbility::class => 'flavor-agent/preview-recommend-template-part',
			] as $class => $name
		) {
			$preview    = new $class( $name, [] );
			$properties = $preview->input_schema()['properties'] ?? [];

			$this->assertArrayNotHasKey( 'resolveSignatureOnly', $properties, "{$name} input schema must strip resolveSignatureOnly." );
			$this->assertArrayNotHasKey( 'clientRequest', $properties, "{$name} input schema must strip clientRequest." );
		}
	}

	public function test_meta_declares_readonly_mcp_public_tool(): void {
		$preview = new PreviewRecommendationFakePreviewAbility( 'flavor-agent/preview-recommend-fake', [] );

		$meta = $preview->meta();

		$this->assertTrue( $meta['show_in_rest'] ?? false );
		$this->assertTrue( $meta['readonly'] ?? false );
		$this->assertSame( [ 'public' => true, 'type' => 'tool' ], $meta['mcp'] ?? null );
		$this->assertSame(
			[
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			$meta['annotations'] ?? null
		);
	}

	public function test_signature_keys_per_concrete_surface(): void {
		$expected = [
			'flavor-agent/preview-recommend-block'         => [ 'resolvedContextSignature' ],
			'flavor-agent/preview-recommend-navigation'    => [ 'reviewContextSignature' ],
			'flavor-agent/preview-recommend-style'         => [ 'reviewContextSignature', 'resolvedContextSignature' ],
			'flavor-agent/preview-recommend-template'      => [ 'reviewContextSignature', 'resolvedContextSignature' ],
			'flavor-agent/preview-recommend-template-part' => [ 'reviewContextSignature', 'resolvedContextSignature' ],
		];

		foreach (
			[
				PreviewRecommendBlockAbility::class      => 'flavor-agent/preview-recommend-block',
				PreviewRecommendNavigationAbility::class => 'flavor-agent/preview-recommend-navigation',
				PreviewRecommendStyleAbility::class      => 'flavor-agent/preview-recommend-style',
				PreviewRecommendTemplateAbility::class   => 'flavor-agent/preview-recommend-template',
				PreviewRecommendTemplatePartAbility::class => 'flavor-agent/preview-recommend-template-part',
			] as $class => $name
		) {
			$preview = new $class( $name, [] );
			$schema  = $preview->output_schema();

			$this->assertSame(
				$expected[ $name ],
				\array_keys( $schema['properties'] ?? [] ),
				"{$name} should declare exactly the signature keys per the Tier-2 spec."
			);
		}
	}
}
