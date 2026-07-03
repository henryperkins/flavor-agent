<?php
declare(strict_types=1);

use FlavorAgent\LLM\PostBlocksPrompt;
use PHPUnit\Framework\TestCase;

final class PostBlocksPromptApplyValidationTest extends TestCase {

	/**
	 * A post-blocks context with one free paragraph, one lock-carrying
	 * paragraph, and a heading, matching the collector's target shape.
	 */
	private function context(): array {
		return [
			'blockTree'        => [
				[
					'path'       => [ 0 ],
					'name'       => 'core/paragraph',
					'label'      => 'Paragraph',
					'attributes' => [],
					'childCount' => 0,
				],
				[
					'path'       => [ 1 ],
					'name'       => 'core/paragraph',
					'label'      => 'Pinned paragraph',
					'attributes' => [],
					'childCount' => 0,
				],
				[
					'path'       => [ 2 ],
					'name'       => 'core/heading',
					'label'      => 'Heading',
					'attributes' => [],
					'childCount' => 0,
				],
			],
			'operationTargets' => [
				[
					'path'              => [ 0 ],
					'name'              => 'core/paragraph',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
					'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
				],
				[
					'path'              => [ 1 ],
					'name'              => 'core/paragraph',
					'allowedOperations' => [],
					'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
					'locked'            => [
						'remove'       => true,
						'move'         => false,
						'templateLock' => '',
					],
				],
				[
					'path'              => [ 2 ],
					'name'              => 'core/heading',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
					'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
				],
			],
			'insertionAnchors' => [
				[ 'placement' => 'start' ],
				[ 'placement' => 'end' ],
				[
					'placement'  => 'after_block_path',
					'targetPath' => [ 2 ],
				],
			],
			'patterns'         => [
				[ 'name' => 'demo/cta' ],
			],
		];
	}

	private function reason_codes( array $result ): array {
		return array_column( $result['reasons'], 'code' );
	}

	public function test_valid_remove_block_survives_apply_revalidation(): void {
		$result = PostBlocksPrompt::validate_operations_for_apply(
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/paragraph',
				],
			],
			$this->context()
		);

		$this->assertCount( 1, $result['operations'] );
		$this->assertSame( 'remove_block', $result['operations'][0]['type'] );
		$this->assertSame( 'core/paragraph', $result['operations'][0]['expectedTarget']['name'] );
	}

	public function test_remove_of_locked_target_is_rejected_with_target_locked(): void {
		$result = PostBlocksPrompt::validate_operations_for_apply(
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 1 ],
					'expectedBlockName' => 'core/paragraph',
				],
			],
			$this->context()
		);

		$this->assertCount( 0, $result['operations'] );
		$this->assertContains( 'target_locked', $this->reason_codes( $result ) );
	}

	public function test_replace_of_locked_target_is_rejected_with_target_locked(): void {
		$result = PostBlocksPrompt::validate_operations_for_apply(
			[
				[
					'type'              => 'replace_block_with_pattern',
					'targetPath'        => [ 1 ],
					'expectedBlockName' => 'core/paragraph',
					'patternName'       => 'demo/cta',
				],
			],
			$this->context()
		);

		$this->assertCount( 0, $result['operations'] );
		$this->assertContains( 'target_locked', $this->reason_codes( $result ) );
	}

	public function test_insert_after_anchor_survives(): void {
		$result = PostBlocksPrompt::validate_operations_for_apply(
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'demo/cta',
					'placement'   => 'after_block_path',
					'targetPath'  => [ 2 ],
				],
			],
			$this->context()
		);

		$this->assertCount( 1, $result['operations'] );
		$this->assertSame( 'insert_pattern', $result['operations'][0]['type'] );
	}

	public function test_insert_at_missing_anchor_is_rejected(): void {
		$result = PostBlocksPrompt::validate_operations_for_apply(
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'demo/cta',
					'placement'   => 'before_block_path',
					'targetPath'  => [ 1 ],
				],
			],
			$this->context()
		);

		$this->assertCount( 0, $result['operations'] );
		$this->assertContains( 'invalid_anchor', $this->reason_codes( $result ) );
	}

	public function test_more_than_three_operations_are_rejected(): void {
		$op     = [
			'type'              => 'remove_block',
			'targetPath'        => [ 0 ],
			'expectedBlockName' => 'core/paragraph',
		];
		$result = PostBlocksPrompt::validate_operations_for_apply(
			[ $op, $op, $op, $op ],
			$this->context()
		);

		$this->assertCount( 0, $result['operations'] );
		$this->assertContains( 'too_many_operations', $this->reason_codes( $result ) );
	}

	public function test_overlapping_paths_reject_the_whole_plan(): void {
		$result = PostBlocksPrompt::validate_operations_for_apply(
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/paragraph',
				],
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/paragraph',
				],
			],
			$this->context()
		);

		$this->assertCount( 0, $result['operations'] );
		$this->assertContains( 'overlapping_block_paths', $this->reason_codes( $result ) );
	}

	public function test_unknown_pattern_is_rejected(): void {
		$result = PostBlocksPrompt::validate_operations_for_apply(
			[
				[
					'type'        => 'insert_pattern',
					'patternName' => 'demo/unregistered',
					'placement'   => 'end',
				],
			],
			$this->context()
		);

		$this->assertCount( 0, $result['operations'] );
		$this->assertContains( 'unknown_pattern', $this->reason_codes( $result ) );
	}

	public function test_block_name_mismatch_is_rejected(): void {
		$result = PostBlocksPrompt::validate_operations_for_apply(
			[
				[
					'type'              => 'remove_block',
					'targetPath'        => [ 0 ],
					'expectedBlockName' => 'core/heading',
				],
			],
			$this->context()
		);

		$this->assertCount( 0, $result['operations'] );
		$this->assertContains( 'invalid_anchor', $this->reason_codes( $result ) );
	}

	public function test_build_user_prompt_carries_document_contract_sections(): void {
		$context               = $this->context();
		$context['postId']     = 42;
		$context['postType']   = 'post';
		$context['postStatus'] = 'draft';
		$context['title']      = 'Hello world';

		$user = PostBlocksPrompt::build_user( $context, 'Add a call to action' );

		$this->assertStringContainsString( '## Document', $user );
		$this->assertStringContainsString( 'Post ID: 42', $user );
		$this->assertStringContainsString( '## Current Block Tree', $user );
		$this->assertStringContainsString( '## Executable Operation Targets', $user );
		$this->assertStringContainsString( '## Executable Insertion Anchors', $user );
		$this->assertStringContainsString( 'Add a call to action', $user );
	}

	public function test_build_system_documents_the_shared_grammar(): void {
		$system = PostBlocksPrompt::build_system();

		$this->assertStringContainsString( 'insert_pattern', $system );
		$this->assertStringContainsString( 'replace_block_with_pattern', $system );
		$this->assertStringContainsString( 'remove_block', $system );
		$this->assertStringContainsString( 'at most 3 entries', $system );
	}
}
