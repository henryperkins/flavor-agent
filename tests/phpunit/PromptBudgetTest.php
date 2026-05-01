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

	public function test_required_sections_are_never_dropped_even_over_budget(): void {
		$budget = new PromptBudget( 2000 );
		$budget->add_section( 'required_a', str_repeat( 'a', 5000 ), 100, true );
		$budget->add_section( 'required_b', str_repeat( 'b', 5000 ), 100, true );
		$budget->add_section( 'optional', 'optional content', 10, false );

		$result = $budget->assemble();

		$this->assertStringContainsString( str_repeat( 'a', 100 ), $result );
		$this->assertStringContainsString( str_repeat( 'b', 100 ), $result );
		$this->assertStringNotContainsString( 'optional content', $result );
		$this->assertGreaterThan( $budget->get_max_tokens(), PromptBudget::estimate_tokens( $result ) );
	}

	public function test_lower_priority_required_outlasts_higher_priority_optional(): void {
		$budget = new PromptBudget( 2000 );
		$budget->add_section( 'required_low', 'required content', 10, true );
		$budget->add_section( 'optional_high', str_repeat( 'h', 8000 ), 100, false );

		$result = $budget->assemble();

		$this->assertStringContainsString( 'required content', $result );
		$this->assertStringNotContainsString( str_repeat( 'h', 100 ), $result );
	}

	public function test_default_required_false_preserves_existing_behavior(): void {
		$budget = new PromptBudget( 2000 );
		$budget->add_section( 'high', str_repeat( 'h', 5000 ), 100 );
		$budget->add_section( 'low', str_repeat( 'l', 5000 ), 10 );

		$result = $budget->assemble();

		$this->assertStringContainsString( str_repeat( 'h', 100 ), $result );
		$this->assertStringNotContainsString( str_repeat( 'l', 100 ), $result );
	}

	public function test_diagnostics_includes_required_flag(): void {
		$budget = new PromptBudget();
		$budget->add_section( 'critical', 'kept', 100, true );
		$budget->add_section( 'optional', 'maybe', 10, false );

		$diagnostics = $budget->get_diagnostics();

		$this->assertTrue( $diagnostics['sections'][0]['required'] );
		$this->assertFalse( $diagnostics['sections'][1]['required'] );
	}

	public function test_equal_priority_drops_last_inserted_first(): void {
		$budget = new PromptBudget( 2000 );
		$budget->add_section( 'identity', 'identity content', 100 );
		$budget->add_section( 'few_shot_0', str_repeat( '0', 4000 ), 10 );
		$budget->add_section( 'few_shot_1', str_repeat( '1', 4000 ), 10 );
		$budget->add_section( 'few_shot_2', str_repeat( '2', 4000 ), 10 );

		$result = $budget->assemble();

		$this->assertStringContainsString( 'identity content', $result );
		$this->assertStringContainsString( str_repeat( '0', 100 ), $result );
		$this->assertStringNotContainsString( str_repeat( '2', 100 ), $result );
	}
}
