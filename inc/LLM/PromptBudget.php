<?php
/**
 * Prompt budget estimator and section manager.
 *
 * Provides a token-budget-aware assembler for prompt sections so each
 * surface can dynamically trim lower-priority context when the payload
 * approaches model limits, rather than relying on static line limits
 * in ThemeTokenFormatter alone.
 */

declare(strict_types=1);

namespace FlavorAgent\LLM;

final class PromptBudget {

	/**
	 * Approximate characters per token for English/mixed-code content.
	 * GPT-family tokenizers average ~3.5-4.5 chars/token; 4 is a safe
	 * middle ground for budget estimation.
	 */
	private const CHARS_PER_TOKEN = 4;

	/**
	 * Default maximum token budget for user prompts.
	 * Conservative to leave headroom for system prompt and response.
	 */
	private const DEFAULT_MAX_TOKENS = 12000;

	/**
	 * Minimum token budget — never trim below this floor.
	 */
	private const MIN_TOKENS = 2000;

	/**
	 * @var int Maximum token budget for this instance.
	 */
	private int $max_tokens;

	/**
	 * @var array<int, array{key: string, content: string, priority: int}>
	 */
	private array $sections = [];

	/**
	 * @param int $max_tokens Maximum token budget (0 uses the default).
	 */
	public function __construct( int $max_tokens = 0 ) {
		$this->max_tokens = $max_tokens > 0
			? max( self::MIN_TOKENS, $max_tokens )
			: self::DEFAULT_MAX_TOKENS;
	}

	/**
	 * Add a named prompt section with a priority.
	 *
	 * Higher priority sections are kept when budget is tight.
	 * Priority 100 = critical (identity, instructions), 50 = normal
	 * context, 10 = supplemental (docs guidance, examples).
	 *
	 * @param string $key      Unique section identifier.
	 * @param string $content  Section content.
	 * @param int    $priority Higher = more important (100 max).
	 */
	public function add_section( string $key, string $content, int $priority = 50 ): self {
		if ( '' === trim( $content ) ) {
			return $this;
		}

		$this->sections[] = [
			'key'      => $key,
			'content'  => $content,
			'priority' => max( 0, min( 100, $priority ) ),
		];

		return $this;
	}

	/**
	 * Estimate the token count for a string.
	 *
	 * @param string $text Input text.
	 * @return int Estimated token count.
	 */
	public static function estimate_tokens( string $text ): int {
		if ( '' === $text ) {
			return 0;
		}

		$length = function_exists( 'mb_strlen' )
			? mb_strlen( $text, 'UTF-8' )
			: strlen( $text );

		return (int) ceil( $length / self::CHARS_PER_TOKEN );
	}

	/**
	 * Get the maximum token budget for this instance.
	 */
	public function get_max_tokens(): int {
		return $this->max_tokens;
	}

	/**
	 * Get the current total estimated token count across all sections.
	 */
	public function get_current_tokens(): int {
		$total = '';
		foreach ( $this->sections as $section ) {
			$total .= $section['content'] . "\n\n";
		}

		return self::estimate_tokens( rtrim( $total ) );
	}

	/**
	 * Check whether the current sections fit within budget.
	 */
	public function is_within_budget(): bool {
		return $this->get_current_tokens() <= $this->max_tokens;
	}

	/**
	 * Assemble the final prompt, trimming lowest-priority removable
	 * sections until the result fits within budget or only one section
	 * remains. Preserves the original insertion order of kept sections.
	 *
	 * @return string Assembled prompt with sections joined by double newlines.
	 */
	public function assemble(): string {
		$included = $this->sections;

		while ( count( $included ) > 1 ) {
			$assembled = self::join_sections( $included );
			if ( self::estimate_tokens( $assembled ) <= $this->max_tokens ) {
				return $assembled;
			}

			$lowest_index = self::get_lowest_priority_index( $included );
			array_splice( $included, $lowest_index, 1 );
		}

		// Single section or empty — return as-is, even if still over budget.
		return self::join_sections( $included );
	}

	/**
	 * Get a diagnostic summary of sections and their budget impact.
	 *
	 * @return array{max_tokens: int, current_tokens: int, within_budget: bool, sections: array<int, array{key: string, tokens: int, priority: int}>}
	 */
	public function get_diagnostics(): array {
		$section_diagnostics = [];
		foreach ( $this->sections as $section ) {
			$section_diagnostics[] = [
				'key'      => $section['key'],
				'tokens'   => self::estimate_tokens( $section['content'] ),
				'priority' => $section['priority'],
			];
		}

		return [
			'max_tokens'     => $this->max_tokens,
			'current_tokens' => $this->get_current_tokens(),
			'within_budget'  => $this->is_within_budget(),
			'sections'       => $section_diagnostics,
		];
	}

	/**
	 * @param array<int, array{key: string, content: string, priority: int}> $sections
	 */
	private static function join_sections( array $sections ): string {
		$parts = [];
		foreach ( $sections as $section ) {
			$parts[] = $section['content'];
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * @param array<int, array{key: string, content: string, priority: int}> $sections
	 */
	private static function get_lowest_priority_index( array $sections ): int {
		$count           = count( $sections );
		$lowest_index    = $count - 1;
		$lowest_priority = $sections[ $lowest_index ]['priority'] ?? 0;

		for ( $index = $count - 2; $index >= 0; --$index ) {
			$priority = $sections[ $index ]['priority'] ?? 0;
			if ( $priority < $lowest_priority ) {
				$lowest_index    = $index;
				$lowest_priority = $priority;
			}
		}

		return $lowest_index;
	}
}
