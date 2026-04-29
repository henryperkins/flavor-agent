<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\BlockOperationValidator;
use PHPUnit\Framework\TestCase;

final class BlockOperationValidatorTest extends TestCase {

	private function base_context( array $overrides = [] ): array {
		return array_merge(
			[
				'enableBlockStructuralActions' => true,
				'targetClientId'               => 'block-1',
				'targetBlockName'              => 'core/group',
				'targetSignature'              => 'target-sig',
				'isTargetLocked'               => false,
				'isContentOnly'                => false,
				'editingMode'                  => 'default',
				'allowedPatterns'              => [
					[
						'name'           => 'theme/hero',
						'allowedActions' => [ 'insert_after', 'replace' ],
					],
					[
						'name'           => 'theme/text-band',
						'allowedActions' => [ 'insert_after' ],
					],
				],
			],
			$overrides
		);
	}

	public function test_valid_insert_pattern_returns_single_executable_operation(): void {
		$result = BlockOperationValidator::validate_sequence(
			[
				[
					'type'           => 'insert_pattern',
					'patternName'    => 'theme/hero',
					'targetClientId' => 'block-1',
					'position'       => 'insert_after',
				],
			],
			$this->base_context()
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'insert_pattern', $result['operations'][0]['type'] );
		$this->assertSame( 'target-sig', $result['operations'][0]['targetSignature'] );
		$this->assertSame(
			[
				'clientId' => 'block-1',
				'name'     => 'core/group',
			],
			$result['operations'][0]['expectedTarget']
		);
		$this->assertSame( [], $result['rejectedOperations'] );
	}

	public function test_valid_replace_pattern_returns_single_executable_operation(): void {
		$result = BlockOperationValidator::validate_sequence(
			[
				[
					'type'           => 'replace_block_with_pattern',
					'patternName'    => 'theme/hero',
					'targetClientId' => 'block-1',
				],
			],
			$this->base_context()
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'replace_block_with_pattern', $result['operations'][0]['type'] );
		$this->assertSame( 'replace', $result['operations'][0]['action'] );
		$this->assertSame( [], $result['rejectedOperations'] );
	}

	/**
	 * @dataProvider invalid_operation_provider
	 */
	public function test_invalid_operations_are_rejected( array $operations, array $context, string $expected_code ): void {
		$result = BlockOperationValidator::validate_sequence( $operations, $context );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( [], $result['operations'] );
		$this->assertSame( $expected_code, $result['rejectedOperations'][0]['code'] ?? null );
	}

	public function invalid_operation_provider(): array {
		$base = $this->base_context();

		return [
			'disabled flag'              => [
				[
					[
						'type'           => 'insert_pattern',
						'patternName'    => 'theme/hero',
						'targetClientId' => 'block-1',
						'position'       => 'insert_after',
					],
				],
				$this->base_context( [ 'enableBlockStructuralActions' => false ] ),
				BlockOperationValidator::ERROR_STRUCTURAL_ACTIONS_DISABLED,
			],
			'unknown type'               => [
				[
					[
						'type'           => 'remove_block',
						'patternName'    => 'theme/hero',
						'targetClientId' => 'block-1',
					],
				],
				$base,
				BlockOperationValidator::ERROR_UNKNOWN_OPERATION_TYPE,
			],
			'unknown pattern'            => [
				[
					[
						'type'           => 'insert_pattern',
						'patternName'    => 'theme/missing',
						'targetClientId' => 'block-1',
						'position'       => 'insert_after',
					],
				],
				$base,
				BlockOperationValidator::ERROR_PATTERN_NOT_AVAILABLE,
			],
			'target mismatch'            => [
				[
					[
						'type'           => 'insert_pattern',
						'patternName'    => 'theme/hero',
						'targetClientId' => 'other-block',
						'position'       => 'insert_after',
					],
				],
				$base,
				BlockOperationValidator::ERROR_STALE_TARGET,
			],
			'target signature mismatch'  => [
				[
					[
						'type'            => 'insert_pattern',
						'patternName'     => 'theme/hero',
						'targetClientId'  => 'block-1',
						'targetSignature' => 'old-sig',
						'position'        => 'insert_after',
					],
				],
				$base,
				BlockOperationValidator::ERROR_STALE_TARGET,
			],
			'cross surface'              => [
				[
					[
						'type'           => 'insert_pattern',
						'patternName'    => 'theme/hero',
						'targetClientId' => 'block-1',
						'targetSurface'  => 'template',
						'position'       => 'insert_after',
					],
				],
				$base,
				BlockOperationValidator::ERROR_CROSS_SURFACE_TARGET,
			],
			'locked target'              => [
				[
					[
						'type'           => 'insert_pattern',
						'patternName'    => 'theme/hero',
						'targetClientId' => 'block-1',
						'position'       => 'insert_after',
					],
				],
				$this->base_context( [ 'isTargetLocked' => true ] ),
				BlockOperationValidator::ERROR_LOCKED_TARGET,
			],
			'content only target'        => [
				[
					[
						'type'           => 'insert_pattern',
						'patternName'    => 'theme/hero',
						'targetClientId' => 'block-1',
						'position'       => 'insert_after',
					],
				],
				$this->base_context( [ 'editingMode' => 'contentOnly' ] ),
				BlockOperationValidator::ERROR_CONTENT_ONLY_TARGET,
			],
			'invalid insertion position' => [
				[
					[
						'type'           => 'insert_pattern',
						'patternName'    => 'theme/hero',
						'targetClientId' => 'block-1',
						'position'       => 'start',
					],
				],
				$base,
				BlockOperationValidator::ERROR_INVALID_INSERTION_POSITION,
			],
			'action not allowed'         => [
				[
					[
						'type'           => 'replace_block_with_pattern',
						'patternName'    => 'theme/text-band',
						'targetClientId' => 'block-1',
					],
				],
				$base,
				BlockOperationValidator::ERROR_ACTION_NOT_ALLOWED,
			],
			'multiple operations'        => [
				[
					[
						'type'           => 'insert_pattern',
						'patternName'    => 'theme/hero',
						'targetClientId' => 'block-1',
						'position'       => 'insert_after',
					],
					[
						'type'           => 'replace_block_with_pattern',
						'patternName'    => 'theme/hero',
						'targetClientId' => 'block-1',
					],
				],
				$base,
				BlockOperationValidator::ERROR_MULTI_OPERATION_UNSUPPORTED,
			],
		];
	}

	public function test_empty_sequence_returns_no_executable_operations_without_rejection_noise(): void {
		$result = BlockOperationValidator::validate_sequence( [], $this->base_context() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( [], $result['operations'] );
		$this->assertSame( [], $result['rejectedOperations'] );
		$this->assertSame( 0, $result['proposedCount'] );
	}

	public function test_normalize_context_adapts_request_context_to_validator_context(): void {
		$context = BlockOperationValidator::normalize_context(
			[
				'targetClientId'  => ' block-1 ',
				'targetBlockName' => 'core/group',
				'targetSignature' => 'target-sig',
				'editingMode'     => 'contentOnly',
				'allowedPatterns' => [
					[
						'name'           => 'theme/hero',
						'allowedActions' => [ 'insert_after', 'bogus' ],
					],
				],
			],
			true
		);

		$this->assertTrue( $context['enableBlockStructuralActions'] );
		$this->assertSame( 'block-1', $context['targetClientId'] );
		$this->assertTrue( $context['isContentOnly'] );
		$this->assertSame( [ 'insert_after' ], $context['allowedPatterns'][0]['allowedActions'] );
	}
}
