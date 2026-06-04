<?php
declare(strict_types=1);

use FlavorAgent\Support\ValidationReason;
use PHPUnit\Framework\TestCase;

final class ValidationReasonTest extends TestCase {

	public function test_version_constant(): void {
		$this->assertSame( 'validation-reasons-v1', ValidationReason::VERSION );
	}

	public function test_normalize_keeps_known_codes_and_assigns_default_severity(): void {
		$out = ValidationReason::normalize(
			[
				[
					'code'    => 'Unsupported Scope',
					'message' => 'x',
				],
			]
		);

		$this->assertSame( 'unsupported_scope', $out[0]['code'] );
		$this->assertSame( 'rejected', $out[0]['severity'] );
		$this->assertSame( 'x', $out[0]['message'] );
	}

	public function test_normalize_respects_explicit_severity_when_valid(): void {
		$out = ValidationReason::normalize(
			[
				[
					'code'     => 'failed_contrast',
					'severity' => 'downgraded',
				],
			]
		);
		$this->assertSame( 'downgraded', $out[0]['severity'] );
	}

	public function test_normalize_drops_blank_codes_and_bounds_message(): void {
		$out = ValidationReason::normalize(
			[
				[
					'code'    => '',
					'message' => 'dropped',
				],
				[
					'code'    => 'no_op',
					'message' => str_repeat( 'a', 500 ),
				],
			]
		);

		$this->assertCount( 1, $out );
		$this->assertSame( 'no_op', $out[0]['code'] );
		$this->assertLessThanOrEqual( 191, strlen( $out[0]['message'] ) );
	}

	public function test_normalize_bounds_multibyte_message_without_splitting_characters(): void {
		$out = ValidationReason::normalize(
			[
				[
					'code'    => 'no_op',
					'message' => str_repeat( 'é', 300 ),
				],
			]
		);

		$message = $out[0]['message'];
		$this->assertTrue( mb_check_encoding( $message, 'UTF-8' ) ); // not split mid-character.
		$this->assertLessThanOrEqual( 191, mb_strlen( $message, 'UTF-8' ) ); // bounded by characters.
	}

	public function test_primary_picks_highest_severity_then_first(): void {
		$primary = ValidationReason::primary(
			[
				[
					'code'     => 'failed_contrast',
					'severity' => 'downgraded',
				],
				[
					'code'     => 'unsupported_path',
					'severity' => 'rejected',
				],
				[
					'code'     => 'no_op',
					'severity' => 'no_op',
				],
			]
		);

		$this->assertSame( 'unsupported_path', $primary['code'] );
	}

	public function test_primary_prefers_first_reason_when_severity_ties(): void {
		$primary = ValidationReason::primary(
			[
				[
					'code'     => 'failed_contrast',
					'severity' => 'downgraded',
				],
				[
					'code'     => 'advisory_only',
					'severity' => 'downgraded',
				],
			]
		);

		$this->assertSame( 'failed_contrast', $primary['code'] );
		$this->assertSame( 'downgraded', $primary['severity'] );
	}

	public function test_primary_returns_empty_array_for_no_reasons(): void {
		$this->assertSame( [], ValidationReason::primary( [] ) );
	}
}
