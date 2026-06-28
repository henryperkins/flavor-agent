<?php
declare(strict_types=1);

use FlavorAgent\LLM\TemplatePartPrompt;
use PHPUnit\Framework\TestCase;

final class TemplatePartPromptApplyValidationTest extends TestCase {

	private function context(): array {
		return [
			'blockTree'        => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/navigation',
					'label'      => 'Navigation',
					'attributes' => [],
					'childCount' => 0,
				],
			],
			'operationTargets' => [
				[
					'path'              => [ 0 ],
					'name'              => 'core/navigation',
					'allowedOperations' => [ 'remove_block' ],
					'allowedInsertions' => [],
				],
			],
			'insertionAnchors' => [],
			'patterns'         => [],
		];
	}

	public function test_valid_remove_block_survives_apply_revalidation(): void {
		$result = TemplatePartPrompt::validate_operations_for_apply(
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/navigation',
				],
			],
			$this->context()
		);
		$this->assertCount( 1, $result['operations'] );
		$this->assertSame( 'remove_block', $result['operations'][0]['type'] );
	}

	public function test_block_name_mismatch_is_rejected(): void {
		$result = TemplatePartPrompt::validate_operations_for_apply(
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/paragraph',
				],
			],
			$this->context()
		);
		$this->assertCount( 0, $result['operations'] );
	}
}
