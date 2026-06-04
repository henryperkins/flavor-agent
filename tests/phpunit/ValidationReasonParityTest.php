<?php
declare(strict_types=1);

use FlavorAgent\Context\BlockOperationValidator;
use FlavorAgent\Support\ValidationReason;
use PHPUnit\Framework\TestCase;

final class ValidationReasonParityTest extends TestCase {

	/** @return array<string, string> */
	private function jsonReasons(): array {
		$path = dirname( __DIR__, 2 ) . '/shared/validation-reasons.json';
		$data = json_decode( (string) file_get_contents( $path ), true );

		$map = [];
		foreach ( (array) ( $data['reasons'] ?? [] ) as $code => $meta ) {
			$map[ (string) $code ] = (string) ( $meta['severity'] ?? '' );
		}
		ksort( $map );

		return $map;
	}

	public function test_php_vocabulary_matches_json(): void {
		$php = ValidationReason::vocabulary();
		ksort( $php );

		$this->assertSame( $this->jsonReasons(), $php );
	}

	public function test_version_matches_json(): void {
		$path = dirname( __DIR__, 2 ) . '/shared/validation-reasons.json';
		$data = json_decode( (string) file_get_contents( $path ), true );

		$this->assertSame( $data['version'], ValidationReason::VERSION );
	}

	public function test_block_codes_are_a_subset_of_the_vocabulary(): void {
		$reflection = new ReflectionClass( BlockOperationValidator::class );
		foreach ( $reflection->getConstants() as $name => $value ) {
			if ( str_starts_with( $name, 'ERROR_' ) ) {
				$this->assertArrayHasKey(
					(string) $value,
					ValidationReason::vocabulary(),
					"Block code {$value} missing from validation-reasons vocabulary"
				);
			}
		}
	}
}
