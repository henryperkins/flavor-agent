<?php

declare(strict_types=1);

use FlavorAgent\Support\MetricsNormalizer;
use PHPUnit\Framework\TestCase;

final class MetricsNormalizerTest extends TestCase {

	public function test_positive_integer_passes_through(): void {
		$this->assertSame( 42, MetricsNormalizer::normalize_metric_int( 42 ) );
	}

	public function test_zero_passes_through(): void {
		$this->assertSame( 0, MetricsNormalizer::normalize_metric_int( 0 ) );
	}

	public function test_negative_integer_returns_null(): void {
		$this->assertNull( MetricsNormalizer::normalize_metric_int( -5 ) );
	}

	public function test_positive_float_is_rounded(): void {
		$this->assertSame( 10, MetricsNormalizer::normalize_metric_int( 9.7 ) );
	}

	public function test_negative_float_returns_null(): void {
		$this->assertNull( MetricsNormalizer::normalize_metric_int( -1.5 ) );
	}

	public function test_numeric_string_is_parsed(): void {
		$this->assertSame( 100, MetricsNormalizer::normalize_metric_int( '100' ) );
	}

	public function test_negative_numeric_string_returns_null(): void {
		$this->assertNull( MetricsNormalizer::normalize_metric_int( '-3' ) );
	}

	public function test_non_numeric_string_returns_null(): void {
		$this->assertNull( MetricsNormalizer::normalize_metric_int( 'abc' ) );
	}

	public function test_empty_string_returns_null(): void {
		$this->assertNull( MetricsNormalizer::normalize_metric_int( '' ) );
	}

	public function test_null_returns_null(): void {
		$this->assertNull( MetricsNormalizer::normalize_metric_int( null ) );
	}

	public function test_bool_returns_null(): void {
		$this->assertNull( MetricsNormalizer::normalize_metric_int( true ) );
	}

	public function test_array_returns_null(): void {
		$this->assertNull( MetricsNormalizer::normalize_metric_int( [] ) );
	}
}
