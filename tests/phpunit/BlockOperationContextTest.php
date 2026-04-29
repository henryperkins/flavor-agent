<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\BlockAbilities;
use FlavorAgent\Context\BlockOperationValidator;
use FlavorAgent\LLM\Prompt;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class BlockOperationContextTest extends TestCase {

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

	public function test_editor_context_preserves_block_operation_context_when_rollout_is_enabled(): void {
		add_filter( 'flavor_agent_enable_block_structural_actions', '__return_true' );

		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block'                 => [
						'name'              => 'core/paragraph',
						'currentAttributes' => [ 'content' => 'Hello world' ],
					],
					'blockOperationContext' => [
						'targetClientId'   => 'paragraph-1',
						'targetBlockName'  => 'core/paragraph',
						'targetSignature'  => 'signature-123',
						'isTargetLocked'   => true,
						'isContentOnly'    => false,
						'editingMode'      => 'default',
						'allowedPatterns'  => [
							[
								'name'           => 'theme/hero',
								'title'          => 'Hero',
								'source'         => 'theme',
								'categories'     => [ 'featured', '', 'featured' ],
								'blockTypes'     => [ 'core/paragraph' ],
								'allowedActions' => [ 'insert_before', 'replace', 'delete_block' ],
							],
							[
								'name'           => '',
								'allowedActions' => [ 'insert_after' ],
							],
						],
						'untrustedPayload' => [ 'drop' => true ],
					],
				],
			]
		);

		$this->assertSame(
			[
				'targetClientId'  => 'paragraph-1',
				'targetBlockName' => 'core/paragraph',
				'targetSignature' => 'signature-123',
				'isTargetLocked'  => true,
				'isContentOnly'   => false,
				'editingMode'     => 'default',
				'allowedPatterns' => [
					[
						'name'           => 'theme/hero',
						'title'          => 'Hero',
						'source'         => 'theme',
						'categories'     => [ 'featured' ],
						'blockTypes'     => [ 'core/paragraph' ],
						'allowedActions' => [ 'insert_before', 'replace' ],
					],
				],
			],
			$prepared['context']['blockOperationContext']
		);
	}

	public function test_editor_context_drops_block_operation_context_when_rollout_is_disabled(): void {
		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block'                 => [
						'name'              => 'core/paragraph',
						'currentAttributes' => [ 'content' => 'Hello world' ],
					],
					'blockOperationContext' => [
						'targetClientId'  => 'paragraph-1',
						'targetBlockName' => 'core/paragraph',
						'targetSignature' => 'signature-123',
						'allowedPatterns' => [
							[
								'name'           => 'theme/hero',
								'allowedActions' => [ 'insert_before' ],
							],
						],
					],
				],
			]
		);

		$this->assertArrayNotHasKey( 'blockOperationContext', $prepared['context'] );
	}

	public function test_prompt_includes_normalized_allowed_pattern_action_context(): void {
		add_filter( 'flavor_agent_enable_block_structural_actions', '__return_true' );

		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'editorContext' => [
					'block'                 => [
						'name'              => 'core/paragraph',
						'currentAttributes' => [ 'content' => 'Hello world' ],
					],
					'blockOperationContext' => [
						'targetClientId'  => 'paragraph-1',
						'targetBlockName' => 'core/paragraph',
						'targetSignature' => 'signature-123',
						'allowedPatterns' => [
							[
								'name'           => 'theme/hero',
								'title'          => 'Hero',
								'source'         => 'theme',
								'categories'     => [ 'featured' ],
								'blockTypes'     => [ 'core/paragraph' ],
								'allowedActions' => [ 'insert_before', 'replace' ],
							],
						],
					],
				],
			]
		);

		$prompt = Prompt::build_user( $prepared['context'] );

		$this->assertStringContainsString( '## Allowed block pattern actions', $prompt );
		$this->assertStringContainsString( '"targetBlockName":"core/paragraph"', $prompt );
		$this->assertStringContainsString( '"name":"theme/hero"', $prompt );
		$this->assertStringContainsString( '"allowedActions":["insert_before","replace"]', $prompt );
		$this->assertStringContainsString( 'Catalog:', $prompt );
		$this->assertStringContainsString( 'insert_pattern: patternName, targetClientId, position insert_before|insert_after', $prompt );
		$this->assertStringContainsString( 'replace_block_with_pattern: patternName, targetClientId', $prompt );
		$this->assertStringContainsString( 'Return at most one operation per block suggestion.', $prompt );
		$this->assertStringNotContainsString( 'delete_block', $prompt );
	}

	public function test_parse_response_preserves_proposed_operations_without_authorizing_them(): void {
		$result = Prompt::parse_response(
			wp_json_encode(
				[
					'block'       => [
						[
							'label'            => 'Add a hero after this block',
							'description'      => 'The page needs a stronger CTA after the intro.',
							'type'             => 'pattern_replacement',
							'attributeUpdates' => [ 'content' => 'Should be stripped' ],
							'operations'       => [
								[
									'type'           => 'insert_pattern',
									'patternName'    => 'theme/hero',
									'targetClientId' => 'paragraph-1',
									'position'       => 'insert_after',
								],
							],
						],
					],
					'explanation' => 'Pattern idea.',
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['block'][0]['attributeUpdates'] );
		$this->assertSame( [], $result['block'][0]['operations'] );
		$this->assertSame(
			[
				[
					'type'           => 'insert_pattern',
					'patternName'    => 'theme/hero',
					'targetClientId' => 'paragraph-1',
					'position'       => 'insert_after',
				],
			],
			$result['block'][0]['proposedOperations']
		);
		$this->assertSame( [], $result['block'][0]['rejectedOperations'] );
	}

	public function test_enforce_block_context_rules_authorizes_valid_proposed_operations_when_enabled(): void {
		add_filter( 'flavor_agent_enable_block_structural_actions', '__return_true' );

		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [
					[
						'label'              => 'Add a hero after this block',
						'description'        => 'The page needs a stronger CTA after the intro.',
						'type'               => 'pattern_replacement',
						'attributeUpdates'   => [],
						'operations'         => [],
						'proposedOperations' => [
							[
								'type'           => 'insert_pattern',
								'patternName'    => 'theme/hero',
								'targetClientId' => 'paragraph-1',
								'position'       => 'insert_after',
							],
						],
						'rejectedOperations' => [],
					],
				],
				'explanation' => 'Pattern idea.',
			],
			[ 'editingMode' => 'default' ],
			[],
			$this->block_operation_context()
		);

		$this->assertSame( 'insert_pattern', $result['block'][0]['operations'][0]['type'] );
		$this->assertSame( 'signature-123', $result['block'][0]['operations'][0]['targetSignature'] );
		$this->assertSame( [], $result['block'][0]['rejectedOperations'] );
	}

	public function test_enforce_block_context_rules_rejects_operations_without_dropping_safe_attribute_updates(): void {
		add_filter( 'flavor_agent_enable_block_structural_actions', '__return_true' );

		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [
					[
						'label'              => 'Update copy and add missing pattern',
						'description'        => 'The copy can be clearer and the pattern might help.',
						'type'               => 'attribute_change',
						'attributeUpdates'   => [ 'content' => 'Get started' ],
						'operations'         => [],
						'proposedOperations' => [
							[
								'type'           => 'insert_pattern',
								'patternName'    => 'theme/missing',
								'targetClientId' => 'paragraph-1',
								'position'       => 'insert_after',
							],
						],
						'rejectedOperations' => [],
					],
				],
				'explanation' => 'Mixed idea.',
			],
			[
				'editingMode'       => 'default',
				'contentAttributes' => [
					'content' => [ 'type' => 'string' ],
				],
			],
			[
				'contentAttributeKeys' => [ 'content' ],
				'isAuthoritative'      => true,
			],
			$this->block_operation_context()
		);

		$this->assertSame( [ 'content' => 'Get started' ], $result['block'][0]['attributeUpdates'] );
		$this->assertSame( [], $result['block'][0]['operations'] );
		$this->assertSame(
			BlockOperationValidator::ERROR_PATTERN_NOT_AVAILABLE,
			$result['block'][0]['rejectedOperations'][0]['code'] ?? null
		);
	}

	public function test_enforce_block_context_rules_rejects_operations_when_flag_is_disabled(): void {
		$result = Prompt::enforce_block_context_rules(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [
					[
						'label'              => 'Add a hero after this block',
						'description'        => 'The page needs a stronger CTA after the intro.',
						'type'               => 'pattern_replacement',
						'attributeUpdates'   => [],
						'operations'         => [],
						'proposedOperations' => [
							[
								'type'           => 'insert_pattern',
								'patternName'    => 'theme/hero',
								'targetClientId' => 'paragraph-1',
								'position'       => 'insert_after',
							],
						],
						'rejectedOperations' => [],
					],
				],
				'explanation' => 'Pattern idea.',
			],
			[ 'editingMode' => 'default' ],
			[],
			$this->block_operation_context()
		);

		$this->assertSame( [], $result['block'][0]['operations'] );
		$this->assertSame(
			BlockOperationValidator::ERROR_STRUCTURAL_ACTIONS_DISABLED,
			$result['block'][0]['rejectedOperations'][0]['code'] ?? null
		);
	}

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

	/**
	 * @return array{context: array, prompt: string}
	 */
	private function invoke_prepare_recommend_block_input( array $input ): array {
		$method = new ReflectionMethod( BlockAbilities::class, 'prepare_recommend_block_input' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $input );

		$this->assertIsArray( $result );

		return $result;
	}
}
