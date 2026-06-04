<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\BlockOperationValidator;
use FlavorAgent\LLM\Prompt;
use FlavorAgent\Support\ValidationReason;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PromptValidationReasonsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		require dirname( __DIR__, 2 ) . '/flavor-agent.php';

		\WP_Block_Type_Registry::get_instance()->register(
			'core/paragraph',
			[
				'title'      => 'Paragraph',
				'category'   => 'text',
				'attributes' => [
					'content' => [
						'type' => 'string',
					],
				],
			]
		);
	}

	public function test_block_suggestion_derives_validation_reasons_from_rejected_operations(): void {
		// Force structural actions off so the proposed op is rejected with that code.
		add_filter( 'flavor_agent_enable_block_structural_actions', '__return_false' );

		$payload = [
			'settings' => [],
			'styles'   => [],
			'block'    => [
				[
					'label'              => 'Insert hero',
					'proposedOperations' => [
						[
							'type'           => 'insert_pattern',
							'patternName'    => 'theme/hero',
							'targetClientId' => 'paragraph-1',
							'position'       => 'insert_after',
						],
					],
				],
			],
		];

		$result = Prompt::enforce_block_context_rules(
			$payload,
			[ 'editingMode' => 'default' ],
			[],
			$this->block_operation_context()
		);

		$suggestion = $result['block'][0];

		$this->assertSame(
			[ BlockOperationValidator::ERROR_STRUCTURAL_ACTIONS_DISABLED ],
			array_column( $suggestion['validationReasons'], 'code' )
		);
		$this->assertSame(
			ValidationReason::SEVERITY_REJECTED,
			$suggestion['validationReasons'][0]['severity']
		);

		// rejectedOperations is untouched (zero regression).
		$this->assertNotEmpty( $suggestion['rejectedOperations'] );
		$this->assertSame(
			BlockOperationValidator::ERROR_STRUCTURAL_ACTIONS_DISABLED,
			$suggestion['rejectedOperations'][0]['code'] ?? null
		);
	}

	public function test_block_suggestion_emits_empty_validation_reasons_when_no_rejections(): void {
		$payload = [
			'settings' => [],
			'styles'   => [],
			'block'    => [
				[
					'label'              => 'Insert hero',
					'proposedOperations' => [
						[
							'type'           => 'insert_pattern',
							'patternName'    => 'theme/hero',
							'targetClientId' => 'paragraph-1',
							'position'       => 'insert_after',
						],
					],
				],
			],
		];

		$result = Prompt::enforce_block_context_rules(
			$payload,
			[ 'editingMode' => 'default' ],
			[],
			$this->block_operation_context()
		);

		$suggestion = $result['block'][0];

		$this->assertSame( [], $suggestion['rejectedOperations'] );
		$this->assertSame( [], $suggestion['validationReasons'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function block_operation_context(): array {
		return [
			'targetClientId'  => 'paragraph-1',
			'targetBlockName' => 'core/paragraph',
			'targetSignature' => 'signature-123',
			'allowedPatterns' => [
				[
					'name'           => 'theme/hero',
					'allowedActions' => [ 'insert_after', 'replace' ],
				],
			],
		];
	}
}
