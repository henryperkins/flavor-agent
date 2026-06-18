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
	 * @var array<int, array{key: string, content: string, priority: int, required: bool}>
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
	 * Get the normalized maximum token budget for this instance.
	 */
	public function get_max_tokens(): int {
		return $this->max_tokens;
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
	 * @param bool   $required Whether this section may not be dropped.
	 */
	public function add_section( string $key, string $content, int $priority = 50, bool $required = false ): self {
		if ( '' === trim( $content ) ) {
			return $this;
		}

		$this->sections[] = [
			'key'      => $key,
			'content'  => $content,
			'priority' => max( 0, min( 100, $priority ) ),
			'required' => $required,
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

		return (int) ceil( self::string_length( $text ) / self::CHARS_PER_TOKEN );
	}

	/**
	 * Trim text to an estimated token cap while preserving beginning and end context.
	 *
	 * @param string $text            Input text.
	 * @param int    $max_tokens      Maximum estimated tokens to keep.
	 * @param string $omission_marker Marker inserted between preserved head/tail text.
	 */
	public static function trim_to_tokens(
		string $text,
		int $max_tokens,
		string $omission_marker = "\n\n[... truncated for prompt budget ...]\n\n"
	): string {
		if ( '' === $text || $max_tokens <= 0 ) {
			return '';
		}

		if ( self::estimate_tokens( $text ) <= $max_tokens ) {
			return $text;
		}

		$max_chars     = max( 1, $max_tokens * self::CHARS_PER_TOKEN );
		$marker_length = self::string_length( $omission_marker );

		if ( $max_chars <= $marker_length + 2 ) {
			return self::substring( $text, 0, $max_chars );
		}

		$available_chars = $max_chars - $marker_length;
		$head_chars      = max( 1, (int) floor( $available_chars * 0.65 ) );
		$tail_chars      = max( 1, $available_chars - $head_chars );

		$head = rtrim( self::substring( $text, 0, $head_chars ) );
		$tail = ltrim( self::substring( $text, -$tail_chars ) );

		return $head . $omission_marker . $tail;
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

			$lowest_index = self::get_lowest_priority_removable_index( $included );
			if ( null === $lowest_index ) {
				return $assembled;
			}

			array_splice( $included, $lowest_index, 1 );
		}

		// Single section or empty — return as-is, even if still over budget.
		return self::join_sections( $included );
	}

	/**
	 * @param array<int, array{key: string, content: string, priority: int, required: bool}> $sections
	 */
	private static function join_sections( array $sections ): string {
		$parts = [];
		foreach ( $sections as $section ) {
			$parts[] = $section['content'];
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * @param array<int, array{key: string, content: string, priority: int, required: bool}> $sections
	 */
	private static function get_lowest_priority_removable_index( array $sections ): ?int {
		$lowest_index    = null;
		$lowest_priority = PHP_INT_MAX;

		for ( $index = count( $sections ) - 1; $index >= 0; --$index ) {
			$section = $sections[ $index ];

			if ( ! empty( $section['required'] ) ) {
				continue;
			}

			$priority = (int) ( $section['priority'] ?? 0 );
			if ( $priority < $lowest_priority ) {
				$lowest_index    = $index;
				$lowest_priority = $priority;
			}
		}

		return $lowest_index;
	}

	private static function string_length( string $text ): int {
		return function_exists( 'mb_strlen' )
			? mb_strlen( $text, 'UTF-8' )
			: strlen( $text );
	}

	private static function substring( string $text, int $start, ?int $length = null ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return null === $length
				? mb_substr( $text, $start, null, 'UTF-8' )
				: mb_substr( $text, $start, $length, 'UTF-8' );
		}

		return null === $length
			? substr( $text, $start )
			: substr( $text, $start, $length );
	}
}
