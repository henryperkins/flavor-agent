<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\RecommendationResolvedSignature;
use FlavorAgent\Support\RecommendationReviewSignature;
use FlavorAgent\Support\RecommendationSignature;
use PHPUnit\Framework\TestCase;

final class RecommendationSignatureTest extends TestCase {

	public function test_returns_sha256_hex_digest(): void {
		$hash = RecommendationSignature::from_payload( 'block', [ 'a' => 1 ] );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}

	public function test_is_deterministic_for_identical_input(): void {
		$payload = [
			'clientId'    => '01234567-89ab-cdef-0123-456789abcdef',
			'attributes'  => [
				'align'    => 'wide',
				'fontSize' => 'large',
			],
			'innerBlocks' => [ 'core/paragraph', 'core/heading' ],
		];

		$first  = RecommendationSignature::from_payload( 'block', $payload );
		$second = RecommendationSignature::from_payload( 'block', $payload );

		$this->assertSame( $first, $second );
	}

	public function test_normalizes_surface_via_sanitize_key(): void {
		$payload = [ 'a' => 1 ];

		$casing = RecommendationSignature::from_payload( 'Block-Surface', $payload );
		$lower  = RecommendationSignature::from_payload( 'block-surface', $payload );

		$this->assertSame( $lower, $casing );
	}

	public function test_different_surfaces_produce_different_hashes(): void {
		$payload = [ 'a' => 1 ];

		$block    = RecommendationSignature::from_payload( 'block', $payload );
		$template = RecommendationSignature::from_payload( 'template', $payload );

		$this->assertNotSame( $block, $template );
	}

	public function test_different_payloads_produce_different_hashes(): void {
		$one = RecommendationSignature::from_payload( 'block', [ 'a' => 1 ] );
		$two = RecommendationSignature::from_payload( 'block', [ 'a' => 2 ] );

		$this->assertNotSame( $one, $two );
	}

	public function test_associative_array_key_order_is_normalized(): void {
		$ordered  = RecommendationSignature::from_payload(
			'block',
			[
				'a' => 1,
				'b' => 2,
				'c' => 3,
			]
		);
		$reversed = RecommendationSignature::from_payload(
			'block',
			[
				'c' => 3,
				'b' => 2,
				'a' => 1,
			]
		);

		$this->assertSame( $ordered, $reversed );
	}

	public function test_nested_associative_arrays_are_recursively_normalized(): void {
		$ordered  = RecommendationSignature::from_payload(
			'block',
			[
				'attributes' => [
					'align'    => 'wide',
					'fontSize' => 'large',
				],
				'context'    => [
					'theme'  => 'twentytwentyfive',
					'locale' => 'en_US',
				],
			]
		);
		$reversed = RecommendationSignature::from_payload(
			'block',
			[
				'context'    => [
					'locale' => 'en_US',
					'theme'  => 'twentytwentyfive',
				],
				'attributes' => [
					'fontSize' => 'large',
					'align'    => 'wide',
				],
			]
		);

		$this->assertSame( $ordered, $reversed );
	}

	public function test_list_array_order_is_preserved(): void {
		$forward  = RecommendationSignature::from_payload(
			'block',
			[ 'innerBlocks' => [ 'core/paragraph', 'core/heading' ] ]
		);
		$reversed = RecommendationSignature::from_payload(
			'block',
			[ 'innerBlocks' => [ 'core/heading', 'core/paragraph' ] ]
		);

		$this->assertNotSame( $forward, $reversed );
	}

	public function test_objects_are_normalized_to_array_of_public_vars(): void {
		$object       = new \stdClass();
		$object->name = 'Hero';
		$object->slug = 'hero';

		$from_object = RecommendationSignature::from_payload(
			'block',
			[ 'pattern' => $object ]
		);
		$from_array  = RecommendationSignature::from_payload(
			'block',
			[
				'pattern' => [
					'name' => 'Hero',
					'slug' => 'hero',
				],
			]
		);

		$this->assertSame( $from_array, $from_object );
	}

	public function test_distinguishes_scalar_types_when_value_differs(): void {
		$as_string = RecommendationSignature::from_payload( 'block', [ 'count' => '1' ] );
		$as_int    = RecommendationSignature::from_payload( 'block', [ 'count' => 1 ] );

		$this->assertNotSame( $as_string, $as_int );
	}

	public function test_unrepresentable_values_collapse_to_null(): void {
		$resource = fopen( 'php://memory', 'rb' );
		self::assertNotFalse( $resource );

		$with_resource = RecommendationSignature::from_payload(
			'block',
			[ 'handle' => $resource ]
		);
		$with_null     = RecommendationSignature::from_payload(
			'block',
			[ 'handle' => null ]
		);

		fclose( $resource );

		$this->assertSame( $with_null, $with_resource );
	}

	public function test_empty_payload_still_produces_stable_hash(): void {
		$first  = RecommendationSignature::from_payload( 'block', [] );
		$second = RecommendationSignature::from_payload( 'block', [] );

		$this->assertSame( $first, $second );
		$this->assertNotSame(
			$first,
			RecommendationSignature::from_payload( 'block', [ 'present' => true ] )
		);
	}

	public function test_resolved_signature_delegates_to_recommendation_signature(): void {
		$payload = [
			'clientId'   => 'abc',
			'attributes' => [ 'align' => 'wide' ],
		];

		$this->assertSame(
			RecommendationSignature::from_payload( 'block', $payload ),
			RecommendationResolvedSignature::from_payload( 'block', $payload )
		);
	}

	public function test_review_signature_delegates_to_recommendation_signature(): void {
		$payload = [
			'reviewing' => true,
			'rev'       => 17,
		];

		$this->assertSame(
			RecommendationSignature::from_payload( 'block', $payload ),
			RecommendationReviewSignature::from_payload( 'block', $payload )
		);
	}
}
