<?php

declare(strict_types=1);

use FlavorAgent\LLM\PromptBudget;
use PHPUnit\Framework\TestCase;

final class PromptBudgetTest extends TestCase {

	public function test_empty_budget_assembles_empty_string(): void {
		$budget = new PromptBudget();
		$this->assertSame( '', $budget->assemble() );
	}

	public function test_single_section_assembles_directly(): void {
		$budget = new PromptBudget();
		$budget->add_section( 'context', 'Block context here', 50 );

		$this->assertSame( 'Block context here', $budget->assemble() );
	}

	public function test_multiple_sections_joined_by_double_newline(): void {
		$budget = new PromptBudget();
		$budget->add_section( 'identity', 'You are an assistant.', 100 );
		$budget->add_section( 'context', 'Block: core/paragraph', 50 );

		$result = $budget->assemble();
		$this->assertStringContainsString( 'You are an assistant.', $result );
		$this->assertStringContainsString( 'Block: core/paragraph', $result );
		$this->assertStringContainsString( "\n\n", $result );
	}

	public function test_low_priority_sections_trimmed_when_over_budget(): void {
		// Budget under test: 2000 tokens.
		$budget = new PromptBudget( 2000 );
		$budget->add_section( 'critical', 'Critical context', 100 );
		$budget->add_section( 'docs', str_repeat( 'x', 10000 ), 10 );

		$result = $budget->assemble();
		$this->assertStringContainsString( 'Critical context', $result );
		$this->assertStringNotContainsString( str_repeat( 'x', 100 ), $result );
	}

	public function test_high_priority_sections_kept_over_low(): void {
		$budget = new PromptBudget( 2000 );
		$budget->add_section( 'docs', str_repeat( 'a', 5000 ), 10 );
		$budget->add_section( 'identity', 'Must keep this', 100 );
		$budget->add_section( 'tokens', str_repeat( 'b', 5000 ), 20 );

		$result = $budget->assemble();
		$this->assertStringContainsString( 'Must keep this', $result );
	}

	public function test_assemble_preserves_insertion_order(): void {
		$budget = new PromptBudget();
		$budget->add_section( 'context', 'Context first', 10 );
		$budget->add_section( 'instruction', 'Instruction second', 100 );

		$this->assertSame(
			"Context first\n\nInstruction second",
			$budget->assemble()
		);
	}

	public function test_assemble_keeps_last_remaining_section_even_when_it_exceeds_budget(): void {
		$budget = new PromptBudget( 2000 );
		$budget->add_section( 'critical', str_repeat( 'x', 10000 ), 100 );

		$this->assertSame( str_repeat( 'x', 10000 ), $budget->assemble() );
	}

	public function test_estimate_tokens_returns_positive_for_content(): void {
		$this->assertGreaterThan( 0, PromptBudget::estimate_tokens( 'Hello world' ) );
	}

	public function test_estimate_tokens_returns_zero_for_empty(): void {
		$this->assertSame( 0, PromptBudget::estimate_tokens( '' ) );
	}

	public function test_is_within_budget_when_small_content(): void {
		$budget = new PromptBudget( 5000 );
		$budget->add_section( 'small', 'Just a few words', 50 );

		$this->assertTrue( $budget->is_within_budget() );
	}

	public function test_is_not_within_budget_when_over(): void {
		$budget = new PromptBudget( 2000 );
		$budget->add_section( 'big', str_repeat( 'x', 20000 ), 50 );

		$this->assertFalse( $budget->is_within_budget() );
	}

	public function test_get_diagnostics_structure(): void {
		$budget = new PromptBudget( 5000 );
		$budget->add_section( 'a', 'Section A', 80 );
		$budget->add_section( 'b', 'Section B', 30 );

		$diag = $budget->get_diagnostics();

		$this->assertArrayHasKey( 'max_tokens', $diag );
		$this->assertArrayHasKey( 'current_tokens', $diag );
		$this->assertArrayHasKey( 'within_budget', $diag );
		$this->assertArrayHasKey( 'sections', $diag );
		$this->assertCount( 2, $diag['sections'] );
		$this->assertSame( 'a', $diag['sections'][0]['key'] );
		$this->assertSame( 80, $diag['sections'][0]['priority'] );
	}

	public function test_add_section_skips_empty_content(): void {
		$budget = new PromptBudget();
		$budget->add_section( 'empty', '', 50 );
		$budget->add_section( 'whitespace', '   ', 50 );

		$this->assertSame( '', $budget->assemble() );
	}

	public function test_fluent_interface(): void {
		$budget = new PromptBudget();
		$result = $budget
			->add_section( 'a', 'First', 100 )
			->add_section( 'b', 'Second', 50 );

		$this->assertInstanceOf( PromptBudget::class, $result );
		$this->assertStringContainsString( 'First', $budget->assemble() );
		$this->assertStringContainsString( 'Second', $budget->assemble() );
	}

	public function test_min_tokens_floor_enforced(): void {
		$budget = new PromptBudget( 10 );
		$this->assertSame( 2000, $budget->get_max_tokens() );
	}
}
