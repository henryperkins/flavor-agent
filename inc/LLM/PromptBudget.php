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
	 * Minimum useful budget for a partially retained optional section.
	 *
	 * Public so tests (and callers) can reason about the threshold without
	 * duplicating the literal.
	 */
	public const MIN_PARTIAL_SECTION_TOKENS = 120;

	/**
	 * Marker inserted where a partially retained section is truncated.
	 */
	public const PARTIAL_SECTION_TRUNCATION_MARKER = "\n\n[... section truncated for prompt budget ...]\n\n";

	/**
	 * @var int Maximum token budget for this instance.
	 */
	private int $max_tokens;

	/**
	 * @var array<int, array{key: string, content: string, priority: int, required: bool, trimmable: bool}>
	 */
	private array $sections = [];

	/**
	 * Section keys dropped by the most recent assemble() call, in drop order.
	 * Sections that were dropped and then restored by backfill are not listed.
	 *
	 * @var array<int, string>
	 */
	private array $dropped_section_keys = [];

	/**
	 * Section keys partially retained (trimmed) by the most recent assemble().
	 *
	 * @var array<int, string>
	 */
	private array $trimmed_section_keys = [];

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
	 * @param string $key       Unique section identifier.
	 * @param string $content   Section content.
	 * @param int    $priority  Higher = more important (100 max).
	 * @param bool   $required  Whether this section may not be dropped.
	 * @param bool   $trimmable Whether this section may be partially retained
	 *                          (trimmed to a bounded excerpt) instead of being
	 *                          dropped as a unit when budget is tight. Opt-in:
	 *                          sections default to drop-as-unit so callers that
	 *                          rely on a section disappearing entirely — voice
	 *                          samples, worked examples, docs grounding — keep
	 *                          that behavior. Ignored for required sections,
	 *                          which are never trimmed or dropped.
	 */
	public function add_section( string $key, string $content, int $priority = 50, bool $required = false, bool $trimmable = false ): self {
		if ( '' === trim( $content ) ) {
			return $this;
		}

		$this->sections[] = [
			'key'       => $key,
			'content'   => $content,
			'priority'  => max( 0, min( 100, $priority ) ),
			'required'  => $required,
			'trimmable' => $trimmable,
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
		$this->dropped_section_keys = [];
		$this->trimmed_section_keys = [];

		// Keyed by original insertion index so dropping (unset) and restoring
		// (re-assign + ksort) both preserve the documented section order.
		$included = $this->sections;
		$dropped  = [];

		while ( count( $included ) > 1 ) {
			$assembled = self::join_sections( $included );
			if ( self::estimate_tokens( $assembled ) <= $this->max_tokens ) {
				$included = self::backfill_dropped_sections( $included, $dropped, $this->max_tokens );
				$this->record_dropped_sections( $dropped, $included );

				return self::join_sections( $included );
			}

			$lowest_key = self::get_lowest_priority_removable_key( $included );
			if ( null === $lowest_key ) {
				// Only required sections remain and they still overflow. Restoring
				// anything would push an already-over-budget prompt further out.
				$this->record_dropped_sections( $dropped, $included );

				return $assembled;
			}

			$trimmed = self::trim_section_to_remaining_budget( $included, $lowest_key, $this->max_tokens );
			if ( null !== $trimmed ) {
				// Partial retention deliberately spends the budget down to the cap.
				// Backfilling into its rounding slack would change trim semantics.
				$this->trimmed_section_keys[] = $included[ $lowest_key ]['key'];
				$this->record_dropped_sections( $dropped, $included );

				return $trimmed;
			}

			$dropped[ $lowest_key ] = $included[ $lowest_key ];
			unset( $included[ $lowest_key ] );
		}

		$included = self::backfill_dropped_sections( $included, $dropped, $this->max_tokens );
		$this->record_dropped_sections( $dropped, $included );

		// Single section or empty — return as-is, even if still over budget.
		return self::join_sections( $included );
	}

	/**
	 * Section keys dropped by the most recent assemble() call.
	 *
	 * @return array<int, string>
	 */
	public function get_dropped_section_keys(): array {
		return $this->dropped_section_keys;
	}

	/**
	 * Section keys partially retained by the most recent assemble() call.
	 *
	 * @return array<int, string>
	 */
	public function get_trimmed_section_keys(): array {
		return $this->trimmed_section_keys;
	}

	/**
	 * Restore dropped sections that fit once a larger section has been removed.
	 *
	 * Without this, the greedy one-way drop loop discards small low-priority
	 * sections that would comfortably fit after a much larger one is gone —
	 * leaving budget unused and context lost for no benefit.
	 *
	 * @param array<int, array{key: string, content: string, priority: int, required: bool, trimmable: bool}> $included
	 * @param array<int, array{key: string, content: string, priority: int, required: bool, trimmable: bool}> $dropped
	 * @return array<int, array{key: string, content: string, priority: int, required: bool, trimmable: bool}>
	 */
	private static function backfill_dropped_sections( array $included, array $dropped, int $max_tokens ): array {
		if ( [] === $dropped ) {
			return $included;
		}

		// Reverse drop order is highest-priority-dropped first: the loop always
		// removes the current minimum priority, so drop priorities are
		// non-decreasing and their reverse is non-increasing.
		foreach ( array_reverse( $dropped, true ) as $index => $section ) {
			$candidate           = $included;
			$candidate[ $index ] = $section;
			ksort( $candidate );

			// Whole sections only, never re-trimmed, and measured on the fully
			// joined result so separators and per-section rounding are exact.
			if ( self::estimate_tokens( self::join_sections( $candidate ) ) <= $max_tokens ) {
				$included = $candidate;
			}

			// Deliberately no early exit: a large section that cannot fit must
			// not hide a smaller one that can.
		}

		return $included;
	}

	/**
	 * @param array<int, array{key: string, content: string, priority: int, required: bool, trimmable: bool}> $dropped
	 * @param array<int, array{key: string, content: string, priority: int, required: bool, trimmable: bool}> $included
	 */
	private function record_dropped_sections( array $dropped, array $included ): void {
		foreach ( $dropped as $index => $section ) {
			if ( ! array_key_exists( $index, $included ) ) {
				$this->dropped_section_keys[] = $section['key'];
			}
		}
	}

	/**
	 * @param array<int, array{key: string, content: string, priority: int, required: bool, trimmable: bool}> $sections
	 */
	private static function join_sections( array $sections ): string {
		$parts = [];
		foreach ( $sections as $section ) {
			$parts[] = $section['content'];
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Keep a useful excerpt from a removable section when dropping the whole
	 * section would leave otherwise-wasted budget. This avoids prompt-quality
	 * cliffs where large supplemental context disappears entirely even though a
	 * bounded summary can still fit beside higher-priority instructions.
	 *
	 * @param array<int, array{key: string, content: string, priority: int, required: bool, trimmable: bool}> $sections
	 */
	private static function trim_section_to_remaining_budget( array $sections, int $section_key, int $max_tokens ): ?string {
		if ( ! isset( $sections[ $section_key ] ) ) {
			return null;
		}

		$section = $sections[ $section_key ];
		if ( ! empty( $section['required'] ) ) {
			return null;
		}

		// Partial retention is opt-in. Only sections explicitly marked
		// trimmable are kept as a bounded excerpt; everything else is dropped
		// as a unit. This preserves the drop-as-unit semantics that callers
		// rely on for sections that are worse-than-useless when truncated —
		// voice samples, worked few-shot examples, docs grounding — which must
		// disappear entirely rather than leak a fragment back into a tight
		// prompt.
		if ( empty( $section['trimmable'] ) ) {
			return null;
		}

		$without_section = $sections;
		unset( $without_section[ $section_key ] );

		$base_prompt  = self::join_sections( $without_section );
		$base_tokens  = self::estimate_tokens( $base_prompt );
		$join_tokens  = '' === $base_prompt ? 0 : self::estimate_tokens( "\n\n" );
		$remaining    = $max_tokens - $base_tokens - $join_tokens;
		$original     = (string) $section['content'];
		$original_est = self::estimate_tokens( $original );

		if ( $remaining < self::MIN_PARTIAL_SECTION_TOKENS || $remaining >= $original_est ) {
			return null;
		}

		$sections[ $section_key ]['content'] = self::trim_to_tokens(
			$original,
			$remaining,
			self::PARTIAL_SECTION_TRUNCATION_MARKER
		);

		$assembled = self::join_sections( $sections );

		return self::estimate_tokens( $assembled ) <= $max_tokens ? $assembled : null;
	}

	/**
	 * @param array<int, array{key: string, content: string, priority: int, required: bool, trimmable: bool}> $sections
	 */
	private static function get_lowest_priority_removable_key( array $sections ): ?int {
		$lowest_key      = null;
		$lowest_priority = PHP_INT_MAX;

		// Descending insertion index with a strict comparison keeps "last inserted
		// drops first" among equal priorities, matching the original positional scan.
		$keys = array_keys( $sections );
		rsort( $keys );

		foreach ( $keys as $key ) {
			$section = $sections[ $key ];

			if ( ! empty( $section['required'] ) ) {
				continue;
			}

			$priority = (int) ( $section['priority'] ?? 0 );
			if ( $priority < $lowest_priority ) {
				$lowest_key      = $key;
				$lowest_priority = $priority;
			}
		}

		return $lowest_key;
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
