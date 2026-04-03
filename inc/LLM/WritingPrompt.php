<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

final class WritingPrompt {

	public static function build_system(): string {
		return <<<'SYSTEM'
You are a writing assistant specialized in Henry Perkins's voice. Your job is to draft and edit blog post content that sounds like him, not like a consultant's template, not like a LinkedIn post, and not like an AI wrote it.

## Voice characteristics

Henry writes in short declarative sentences, often stacked in sequence to build momentum. He uses progression as a storytelling device. Lists can double as timelines. "Retail floors. WordPress themes. Cloud platforms. Agentic AI." Longer sentences work best as a turn after short sentences establish the rhythm.

His tone is conversational, specific, and direct. He is confident without sounding salesy. There is dry self-awareness underneath. Personality comes through specificity, not cleverness for its own sake.

His signature rhythm is setup, setup, setup, then turn. The progression builds expectation and the last line reframes it.

## What to avoid

- Academic register, jargon, or buzzword stacking
- Anything that reads like a LinkedIn summary or pitch deck
- Generic consultant language like "leverage," "synergize," or "drive value"
- Abstract claims without concrete grounding
- Exclamation points
- Whitepaper headers or filler transitions

## What to lean into

- Concrete references to real tools, platforms, and experiences
- The throughline: two decades of customer-facing work, the tools change, the instinct does not
- AI, agents, and workflows tied back to making things work for real people
- Dry humor through understatement
- Short paragraphs that breathe

When editing or critiquing, flag specific lines that drift from this voice and suggest an actual rewrite. Do not stop at diagnosis.

Return ONLY a JSON object with this exact shape:

{
  "mode": "draft|edit|critique",
  "title": "Optional revised title",
  "summary": "One-sentence editorial read",
  "content": "Drafted or revised post content in Markdown/plain text",
  "notes": ["Short editorial notes"],
  "issues": [
    {
      "original": "Specific line that drifts",
      "problem": "Why it misses the voice",
      "revision": "Suggested rewrite in Henry's voice"
    }
  ]
}

Rules:
- Use short paragraphs.
- Keep the writing specific and concrete.
- Do not mention being an AI.
- For draft and edit modes, content should contain a usable full draft.
- For critique mode, include issues whenever the source text drifts from the voice.
- Keep notes brief and useful.
SYSTEM;
	}

	public static function build_user( array $context, string $prompt = '' ): string {
		$mode         = self::normalize_mode( $context['mode'] ?? 'draft' );
		$post_context = is_array( $context['postContext'] ?? null ) ? $context['postContext'] : [];
		$sections     = [];

		$sections[] = '## Task';
		$sections[] = 'Mode: ' . $mode;

		if ( ! empty( $post_context['postType'] ) ) {
			$sections[] = 'Post type: ' . (string) $post_context['postType'];
		}

		if ( ! empty( $post_context['status'] ) ) {
			$sections[] = 'Status: ' . (string) $post_context['status'];
		}

		if ( ! empty( $post_context['slug'] ) ) {
			$sections[] = 'Slug: ' . (string) $post_context['slug'];
		}

		if ( ! empty( $post_context['siteTitle'] ) || ! empty( $post_context['siteDescription'] ) ) {
			$sections[] = '';
			$sections[] = '## Site';
			if ( ! empty( $post_context['siteTitle'] ) ) {
				$sections[] = 'Title: ' . (string) $post_context['siteTitle'];
			}
			if ( ! empty( $post_context['siteDescription'] ) ) {
				$sections[] = 'Description: ' . (string) $post_context['siteDescription'];
			}
		}

		if ( ! empty( $post_context['title'] ) || ! empty( $post_context['excerpt'] ) ) {
			$sections[] = '';
			$sections[] = '## Working draft metadata';
			if ( ! empty( $post_context['title'] ) ) {
				$sections[] = 'Title: ' . (string) $post_context['title'];
			}
			if ( ! empty( $post_context['excerpt'] ) ) {
				$sections[] = 'Excerpt: ' . (string) $post_context['excerpt'];
			}
		}

		if ( ! empty( $post_context['audience'] ) ) {
			$sections[] = '';
			$sections[] = '## Audience';
			$sections[] = (string) $post_context['audience'];
		}

		if ( ! empty( $post_context['categories'] ) || ! empty( $post_context['tags'] ) ) {
			$sections[] = '';
			$sections[] = '## Taxonomy';
			if ( ! empty( $post_context['categories'] ) ) {
				$sections[] = 'Categories: ' . implode( ', ', (array) $post_context['categories'] );
			}
			if ( ! empty( $post_context['tags'] ) ) {
				$sections[] = 'Tags: ' . implode( ', ', (array) $post_context['tags'] );
			}
		}

		if ( ! empty( $context['voiceProfile'] ) ) {
			$sections[] = '';
			$sections[] = '## Extra voice notes';
			$sections[] = (string) $context['voiceProfile'];
		}

		if ( ! empty( $post_context['content'] ) ) {
			$sections[] = '';
			$sections[] = '## Existing draft';
			$sections[] = (string) $post_context['content'];
		}

		$sections[] = '';
		$sections[] = '## User instruction';
		$sections[] = '' !== trim( $prompt )
			? trim( $prompt )
			: self::default_instruction_for_mode( $mode );

		return implode( "\n", $sections );
	}

	public static function parse_response( string $raw, string $mode = 'draft' ): array|\WP_Error {
		$cleaned = preg_replace( '/^```(?:json)?\s*\n?|\n?```\s*$/m', '', trim( $raw ) );
		$data    = json_decode( is_string( $cleaned ) ? $cleaned : '', true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'parse_error',
				'Failed to parse content recommendation response as JSON: ' . json_last_error_msg(),
				[
					'status' => 502,
					'raw'    => substr( $raw, 0, 500 ),
				]
			);
		}

		return [
			'mode'    => self::normalize_mode( $data['mode'] ?? $mode ),
			'title'   => self::sanitize_editorial_text( $data['title'] ?? '' ),
			'summary' => sanitize_text_field( (string) ( $data['summary'] ?? '' ) ),
			'content' => self::sanitize_editorial_text( $data['content'] ?? '' ),
			'notes'   => self::sanitize_lines( $data['notes'] ?? [] ),
			'issues'  => self::sanitize_issues( $data['issues'] ?? [] ),
		];
	}

	private static function default_instruction_for_mode( string $mode ): string {
		return match ( $mode ) {
			'edit' => 'Edit the provided draft so it sounds unmistakably like Henry.',
			'critique' => 'Critique the provided draft, flag lines that drift from Henry\'s voice, and suggest rewrites.',
			default => 'Draft a post in Henry\'s voice.',
		};
	}

	private static function normalize_mode( mixed $value ): string {
		$mode = sanitize_key( (string) $value );

		return in_array( $mode, [ 'draft', 'edit', 'critique' ], true )
			? $mode
			: 'draft';
	}

	private static function sanitize_editorial_text( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return trim( str_replace( "\r", '', $value ) );
	}

	/**
	 * @return string[]
	 */
	private static function sanitize_lines( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$lines = array_map(
			static fn( mixed $line ): string => sanitize_text_field( is_string( $line ) ? $line : '' ),
			$value
		);

		return array_values(
			array_filter(
				$lines,
				static fn( string $line ): bool => '' !== $line
			)
		);
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private static function sanitize_issues( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$issues = [];

		foreach ( array_slice( $value, 0, 8 ) as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}

			$entry = [
				'original' => self::sanitize_editorial_text( $issue['original'] ?? '' ),
				'problem'  => sanitize_text_field( (string) ( $issue['problem'] ?? '' ) ),
				'revision' => self::sanitize_editorial_text( $issue['revision'] ?? '' ),
			];

			if ( '' === $entry['original'] && '' === $entry['problem'] && '' === $entry['revision'] ) {
				continue;
			}

			$issues[] = $entry;
		}

		return $issues;
	}
}
