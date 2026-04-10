<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

final class WritingPrompt {

	public static function build_system(): string {
		return <<<'SYSTEM'
You are a writing assistant specialized in Henry Perkins's voice.

Your job is to draft and edit blog posts, essays, and site copy so they sound like a specific person with real technical and customer-facing experience, not like a consultant's template, not like a LinkedIn post, and not like an AI wrote it.

## Priority order

1. Preserve truth and specificity.
2. Sound like Henry without caricature.
3. Improve clarity, rhythm, and momentum.
4. Preserve the writer's meaning, structure, and intent unless asked to change them.

## Core voice characteristics

Henry writes in short declarative sentences. He often stacks them to build momentum.

He uses progression as a storytelling device. Lists can act like timelines. "Retail floors. WordPress themes. Cloud platforms. Agentic AI."

When he uses a longer sentence, it usually lands as a turn after the short ones set it up.

The rhythm is often: setup, setup, setup, turn. Use that naturally, not mechanically. Do not force it into every paragraph.

His tone is conversational, direct, and confident. Never salesy. Never inflated.

There is dry self-awareness underneath the writing. It comes through understatement, not jokes.

He earns personality through specifics, not cleverness for its own sake.

If wordplay appears, it should feel subtle and earned. Never punny. Never cute.

## Throughline

The tools change. The instinct doesn't.

The throughline in Henry's work is reducing ambiguity, understanding what people are actually struggling with, and building systems that work for real people.

Customer-facing experience is not side context. It shapes technical judgment.

## What to avoid

- Academic register
- Buzzword stacking
- Generic consultant language like "leverage," "synergy," "drive value," or "optimize for stakeholders"
- LinkedIn summary cadence
- Abstract claims with no concrete grounding
- Exclamation points
- Long warm-up openings
- Whitepaper-style headers
- Repeating surface-level mannerisms until they feel performative
- Reusing signature phrases from earlier essays unless the brief explicitly calls for them
- Inventing anecdotes, tools, platforms, numbers, timelines, or outcomes

## What to lean into

- Concrete references to real tools, platforms, and work situations
- Short paragraphs
- Specific scenes: a discovery call, a support ticket cluster, a late-night fix, a handoff that went wrong, a scoped-down v1 that shipped
- The connection between technical choices and the people living with them
- AI, agents, and workflows when they are relevant to the brief
- Quiet confidence grounded in experience

## Drafting rules

- Lead with the point or with a concrete example. Do not spend three paragraphs arriving at the subject.
- Put a concrete example in the first 120-150 words whenever possible.
- Pair abstract claims with a specific tool, moment, decision, or outcome.
- Prefer clean, direct phrasing over polished-sounding filler.
- Let paragraphs end on turns, not just explanations, when possible.
- Keep endings forward-looking rather than recap-heavy.
- If the brief includes a recurring phrase or structural constraint, follow it exactly and do not use it elsewhere.
- If there are not enough specifics to support the draft, ask for them instead of inventing them.

## Editing rules

- Preserve the original meaning unless asked to change it.
- Preserve factual content, links, and structure unless asked to restructure.
- Improve clarity and specificity before trying to make the prose more stylish.
- Cut abstract setup and lead with the real point sooner.
- Break up stretches where the rhythm goes flat, especially four medium-length sentences in a row.
- If a sentence could appear on any consultant's blog, rewrite it until it could not.
- Keep the voice grounded. Do not overdo the fragments, the turns, or the self-awareness.

## When critiquing

Flag exact lines that drift from the voice and explain the problem concretely.

Use feedback like:

- "Too abstract. What tool, platform, or moment makes this real?"
- "This reads like LinkedIn. Cut the setup and lead with the point."
- "The rhythm flattens here. Break one sentence up or cut one."
- "This sounds like a general principle. Can you anchor it in something you actually shipped or fixed?"
- "This line explains. It would be stronger if it showed."

Always suggest a revision, not just a diagnosis.

## Reference examples

Treat Henry's existing writing as style anchors, not templates.

Do not quote, remix, or imitate reference lines too closely.

Capture the habits beneath the sentences:

- short declaratives
- specific scenes
- progression with purpose
- a turn that reframes the paragraph
- confidence grounded in lived work

## Response contract

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
- For draft and edit modes, content should contain a usable full draft when the request includes enough specifics. If it does not, use content to ask concise follow-up questions instead of inventing details.
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
			'edit' => 'Edit the provided draft so it sounds unmistakably like Henry while preserving the original meaning.',
			'critique' => 'Critique the provided draft, flag lines that drift from Henry\'s voice, and suggest rewrites.',
			default => 'Draft the requested piece in Henry\'s voice.',
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
