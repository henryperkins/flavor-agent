<?php

declare(strict_types=1);

use FlavorAgent\LLM\PromptBudget;
use FlavorAgent\Support\DesignSemantics;
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

	public function test_trim_to_tokens_preserves_in_budget_text(): void {
		$text = 'Short draft context.';

		$this->assertSame( $text, PromptBudget::trim_to_tokens( $text, 100 ) );
	}

	public function test_trim_to_tokens_caps_long_text_and_preserves_head_and_tail(): void {
		$text   = 'Opening context. ' . str_repeat( 'Middle context. ', 1200 ) . 'Final context.';
		$result = PromptBudget::trim_to_tokens(
			$text,
			200,
			"\n\n[... draft truncated for prompt budget ...]\n\n"
		);

		$this->assertLessThanOrEqual( 200, PromptBudget::estimate_tokens( $result ) );
		$this->assertStringContainsString( 'Opening context.', $result );
		$this->assertStringContainsString( '[... draft truncated for prompt budget ...]', $result );
		$this->assertStringContainsString( 'Final context.', $result );
		$this->assertNotSame( $text, $result );
	}

	public function test_design_semantic_formatter_caps_prompt_body_tokens(): void {
		$lines = DesignSemantics::format_prompt_lines(
			[
				'surface'         => 'template-part',
				'sectionRole'     => 'footer',
				'visualDensity'   => 'dense',
				'contrastContext' => 'dark-parent',
				'layoutRhythm'    => 'grid',
				'typographyRole'  => 'navigation',
				'mainDesignIssue' => 'accessibility',
				'negativeSignals' => [
					'parent-already-supplies-contrast',
					'no-typography-support',
					'content-only-context',
					'locked-editing-mode',
				],
				'templatePart'    => [
					'ref'                => 'twentytwentyfive//footer',
					'slug'               => 'footer',
					'area'               => 'footer',
					'visiblePatternName' => 'twentytwentyfive/footer-with-navigation-and-social-links',
				],
			],
			80
		);

		$this->assertLessThanOrEqual(
			80,
			PromptBudget::estimate_tokens( implode( "\n", $lines ) )
		);
	}

	public function test_design_semantic_section_does_not_displace_higher_priority_context(): void {
		$lines  = DesignSemantics::format_prompt_lines(
			[
				'surface'         => 'template',
				'sectionRole'     => 'archive-list',
				'visualDensity'   => 'dense',
				'contrastContext' => 'dark-parent',
				'layoutRhythm'    => 'grid',
				'typographyRole'  => 'body',
				'mainDesignIssue' => 'rhythm',
				'negativeSignals' => [
					'parent-already-supplies-contrast',
					'no-typography-support',
					'content-only-context',
					'locked-editing-mode',
				],
				'template'        => [
					'templateType'        => 'archive',
					'emptyAreaCount'      => 1,
					'visiblePatternCount' => 6,
				],
			],
			80
		);
		$budget = new PromptBudget( 2000 );

		$budget->add_section( 'identity', "## Template\nType: archive", 100 );
		$budget->add_section( 'primary_context', '## Structure Summary', 70 );
		$budget->add_section(
			'design_semantics',
			"## Design semantic context\n" . implode( "\n", $lines ),
			58
		);
		$budget->add_section( 'theme_tokens', str_repeat( 'token ', 12000 ), 30 );

		$result = $budget->assemble();

		$this->assertStringContainsString( '## Template', $result );
		$this->assertStringContainsString( '## Structure Summary', $result );
		$this->assertStringNotContainsString( str_repeat( 'token ', 100 ), $result );

		if ( str_contains( $result, '## Design semantic context' ) ) {
			$semantic_body = trim( substr( $result, strpos( $result, '## Design semantic context' ) ) );
			$this->assertLessThanOrEqual( 120, PromptBudget::estimate_tokens( $semantic_body ) );
		}
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
		$budget->add_section( 'floor', str_repeat( 'x', 4000 ), 50 );

		$this->assertTrue( $budget->is_within_budget() );
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
		$this->assertGreaterThan( 2000, PromptBudget::estimate_tokens( $result ) );
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
