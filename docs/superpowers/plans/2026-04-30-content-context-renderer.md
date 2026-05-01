# Content Context Renderer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Render the current post's blocks server-side and harvest attribute-borne text so the content recommender sees what readers see, instead of strip-tagged saved content that wipes dynamic blocks and HTML attributes.

**Architecture:** A new `inc/Context/PostContentRenderer.php` class parses block markup, renders each top-level block via `render_block` inside `setup_postdata`, walks the rendered HTML with `DOMDocument` to harvest `alt`/`title`/`aria-label`/`href`, and joins everything into a single string. `ServerCollector` exposes the renderer through `for_post_content`. `ContentAbilities::recommend_content` performs per-post `current_user_can('edit_post')` and passes the staged title/excerpt + authorized `postId` to the renderer; without an authorized `postId`, the renderer short-circuits to today's `sanitize_textarea_field` fallback. The JS client and ability schema both gain `postId` so the editor flow actually exercises the new path.

**Tech Stack:** PHP 8.0+ with the WordPress block parser (`parse_blocks`, `render_block`, `setup_postdata`), `DOMDocument` + `DOMXPath` for attribute harvesting, PHPUnit 9.6 for backend tests, Jest + React test utilities for the JS client. No new runtime dependencies.

**Spec:** `docs/superpowers/specs/2026-04-30-content-context-renderer-design.md` (Approved, revised after external review).

**Source-of-truth invariants the engineer must preserve:**

- New unsaved posts (no `postId`) must keep working — the fallback path is `sanitize_textarea_field( str_replace( "\r", '', $post_content ) )`, which is byte-for-byte today's behavior.
- The renderer must never run a `render_block` callback unless an authorized `postId > 0` is provided. The auth check lives in `ContentAbilities`; the renderer's postId-gate is defense in depth.
- `$GLOBALS['post']` must always be restored even if a render throws — use a `try { ... } finally { ... }` block.
- The attribute walk must degrade gracefully when `DOMDocument`/`DOMXPath` is unavailable (returns `[]`, visible text is unaffected).
- Existing test fixtures rely on the current `parse_blocks` stub returning `[]` for non-block content. Audit step (Task 1.5) is mandatory before merging the stub change — do not skip it.

---

## File map

| File | Action | Responsibility |
|---|---|---|
| `inc/Context/PostContentRenderer.php` | Create | Block-level render, strip, attribute walk, assemble |
| `inc/Context/ServerCollector.php` | Modify | Add `for_post_content` static facade method + lazy instance accessor |
| `inc/Abilities/ContentAbilities.php` | Modify | Per-post auth, raw content extraction, renderer wiring |
| `inc/Abilities/Registration.php` | Modify | Add `postId` to `recommend-content` `postContext` schema |
| `src/content/ContentRecommender.js` | Modify | Include `postId` in dispatched `postContext` |
| `tests/phpunit/bootstrap.php` | Modify | Update `parse_blocks` stub for freeform; add `register_block_type` (delegating to the existing `WP_Block_Type_Registry`), `render_block`, `setup_postdata`, `wp_reset_postdata`, `WP_Post` shim, plus `WordPressTestState::$current_post` |
| `tests/phpunit/PostContentRendererTest.php` | Create | Unit tests for the renderer |
| `tests/phpunit/ContentAbilitiesTest.php` | Modify | Wrap fixtures in block markup; add postId/auth/renderer-propagation tests |
| `tests/phpunit/RegistrationTest.php` | Modify | Add `postId` schema-presence assertion |
| `src/content/__tests__/ContentRecommender.test.js` | Modify | Assert `postId` flows into `fetchContentRecommendations` |

---

## Conventions for this plan

- All `vendor/bin/phpunit` invocations run from the repo root (`/home/dev/flavor-agent`).
- All `npm test` invocations run from the repo root.
- After every GREEN step, the corresponding `git commit` step uses Conventional Commits in the imperative mood. Match the existing repo's style: lowercase type prefix, no scope unless necessary.
- "RED" = write failing test first. "GREEN" = minimal code to pass. "REFACTOR" = optional cleanup with tests still green. Most tasks here go RED → GREEN → COMMIT (no refactor needed).
- Where a task changes ContentAbilities and would also fail unrelated `ContentAbilitiesTest` cases, fix those fixtures in the same task — do not let red tests linger.

---

## Task 1: Bootstrap stubs — `parse_blocks` freeform fix, block-type registry, recursive `render_block`, postdata, `WP_Post` shim

**Why:** The current `parse_blocks` stub silently drops freeform regions and returns `[]` for plain text, while production WP returns a single `blockName => null` freeform block. Without recursive `render_block` and `setup_postdata` stubs, no renderer test can drive the production code path. This is foundational — every subsequent task depends on it.

**Files:**
- Modify: `tests/phpunit/bootstrap.php`

### Task 1.1: Add `WordPressTestState::$current_post` field

We do **not** add a parallel `$registered_block_types` map — block registrations already go through `WP_Block_Type_Registry::get_instance()->register()` (defined at bootstrap.php earlier), and `render_block` will read from that single source of truth in Task 1.4.

- [ ] **Step 1.1.1: Add the new static field**

In `tests/phpunit/bootstrap.php`, locate `WordPressTestState` (around line 18) and add one new static field next to the existing ones (e.g., after `$ai_client_generate_text_result`):

```php
		public static ?object $current_post = null;
```

- [ ] **Step 1.1.2: Reset it in `WordPressTestState::reset()`**

In the same file, locate `reset()` (around line 283) and append before the closing brace:

```php
			self::$current_post = null;
```

- [ ] **Step 1.1.3: Run the full PHP test suite to confirm no regression from adding the field**

Run: `vendor/bin/phpunit --testdox 2>&1 | tail -40`

Expected: same pass/fail as before (you have not changed any function behavior yet — just added unused storage).

- [ ] **Step 1.1.4: Commit**

```bash
git add tests/phpunit/bootstrap.php
git commit -m "test: track current post for setup_postdata stub"
```

### Task 1.2: Add `WP_Post` shim, `setup_postdata`, `wp_reset_postdata`, `register_block_type`

- [ ] **Step 1.2.1: Add stubs**

Locate the `if ( ! function_exists( 'parse_blocks' ) ) { ... }` block in `tests/phpunit/bootstrap.php` (around line 2411). Insert the following stubs **immediately before** that block:

```php
	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public int $ID = 0;
			public string $post_title = '';
			public string $post_content = '';
			public string $post_excerpt = '';
			public string $post_status = 'publish';
			public string $post_type = 'post';
			public int $post_author = 0;

			public function __construct( array $fields = [] ) {
				foreach ( $fields as $key => $value ) {
					if ( property_exists( $this, $key ) ) {
						$this->{$key} = $value;
					}
				}
			}
		}
	}

	if ( ! function_exists( 'setup_postdata' ) ) {
		function setup_postdata( $post ): bool {
			if ( $post instanceof WP_Post ) {
				WordPressTestState::$current_post = $post;
				return true;
			}
			return false;
		}
	}

	if ( ! function_exists( 'wp_reset_postdata' ) ) {
		function wp_reset_postdata(): void {
			WordPressTestState::$current_post = null;
		}
	}

	if ( ! function_exists( 'register_block_type' ) ) {
		function register_block_type( string $name, array $args = [] ): object {
			\WP_Block_Type_Registry::get_instance()->register( $name, $args );
			$registered = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
			return is_object( $registered )
				? $registered
				: (object) array_merge( $args, [ 'name' => $name ] );
		}
	}
```

- [ ] **Step 1.2.2: Run full PHP suite to confirm no regression**

Run: `vendor/bin/phpunit 2>&1 | tail -10`

Expected: same pass/fail as before (no production code yet calls these stubs).

- [ ] **Step 1.2.3: Commit**

```bash
git add tests/phpunit/bootstrap.php
git commit -m "test: add WP_Post shim, postdata stubs, and block-type registry"
```

### Task 1.3: Replace `parse_blocks` stub with freeform-aware version

- [ ] **Step 1.3.1: Write the failing tests for the new `parse_blocks` behavior**

Create the file `tests/phpunit/ParseBlocksStubTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use PHPUnit\Framework\TestCase;

final class ParseBlocksStubTest extends TestCase {

	public function test_empty_string_returns_empty_array(): void {
		$this->assertSame( [], parse_blocks( '' ) );
	}

	public function test_plain_text_returns_single_freeform_block(): void {
		$blocks = parse_blocks( 'Hello world' );

		$this->assertCount( 1, $blocks );
		$this->assertNull( $blocks[0]['blockName'] );
		$this->assertSame( 'Hello world', $blocks[0]['innerHTML'] );
		$this->assertSame( [ 'Hello world' ], $blocks[0]['innerContent'] );
		$this->assertSame( [], $blocks[0]['innerBlocks'] );
		$this->assertSame( [], $blocks[0]['attrs'] );
	}

	public function test_freeform_before_block(): void {
		$blocks = parse_blocks( 'Intro<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->' );

		$this->assertCount( 2, $blocks );
		$this->assertNull( $blocks[0]['blockName'] );
		$this->assertSame( 'Intro', $blocks[0]['innerHTML'] );
		$this->assertSame( 'core/paragraph', $blocks[1]['blockName'] );
		$this->assertSame( '<p>Body</p>', $blocks[1]['innerHTML'] );
	}

	public function test_freeform_between_blocks(): void {
		$blocks = parse_blocks(
			'<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->Middle<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->'
		);

		$this->assertCount( 3, $blocks );
		$this->assertSame( 'core/paragraph', $blocks[0]['blockName'] );
		$this->assertNull( $blocks[1]['blockName'] );
		$this->assertSame( 'Middle', $blocks[1]['innerHTML'] );
		$this->assertSame( 'core/paragraph', $blocks[2]['blockName'] );
	}

	public function test_freeform_after_block(): void {
		$blocks = parse_blocks( '<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->Trailing' );

		$this->assertCount( 2, $blocks );
		$this->assertSame( 'core/paragraph', $blocks[0]['blockName'] );
		$this->assertNull( $blocks[1]['blockName'] );
		$this->assertSame( 'Trailing', $blocks[1]['innerHTML'] );
	}

	public function test_nested_blocks_split_inner_content_with_nulls(): void {
		$content = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';

		$blocks = parse_blocks( $content );

		$this->assertCount( 1, $blocks );
		$group = $blocks[0];
		$this->assertSame( 'core/group', $group['blockName'] );
		$this->assertCount( 2, $group['innerBlocks'] );
		$this->assertSame( 'core/heading', $group['innerBlocks'][0]['blockName'] );
		$this->assertSame( 'core/paragraph', $group['innerBlocks'][1]['blockName'] );
		$this->assertSame( '<h2>Title</h2>', $group['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( '<p>Body</p>', $group['innerBlocks'][1]['innerHTML'] );

		// innerContent must alternate string fragments and nulls (one null per inner block).
		$this->assertContains( null, $group['innerContent'] );
		$null_count = count( array_filter( $group['innerContent'], static fn( $chunk ) => null === $chunk ) );
		$this->assertSame( 2, $null_count );

		// innerHTML must NOT contain inner block markers.
		$this->assertStringNotContainsString( '<!-- wp:', $group['innerHTML'] );
		$this->assertStringNotContainsString( '<!-- /wp:', $group['innerHTML'] );
	}

	public function test_self_closing_same_name_child_does_not_increase_parent_depth(): void {
		$blocks = parse_blocks(
			'<!-- wp:group --><div><!-- wp:group /--></div><!-- /wp:group -->'
		);

		$this->assertCount( 1, $blocks );
		$group = $blocks[0];
		$this->assertSame( 'core/group', $group['blockName'] );
		$this->assertSame( '<div></div>', $group['innerHTML'] );
		$this->assertCount( 1, $group['innerBlocks'] );
		$this->assertSame( 'core/group', $group['innerBlocks'][0]['blockName'] );
		$this->assertSame( [ '<div>', null, '</div>' ], $group['innerContent'] );
	}

	public function test_self_closing_block_has_empty_inner(): void {
		$blocks = parse_blocks( '<!-- wp:post-content /-->' );

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'core/post-content', $blocks[0]['blockName'] );
		$this->assertSame( '', $blocks[0]['innerHTML'] );
		$this->assertSame( [], $blocks[0]['innerContent'] );
		$this->assertSame( [], $blocks[0]['innerBlocks'] );
	}

	public function test_attrs_are_decoded(): void {
		$blocks = parse_blocks( '<!-- wp:image {"id":42,"sizeSlug":"large"} --><figure>img</figure><!-- /wp:image -->' );

		$this->assertCount( 1, $blocks );
		$this->assertSame( [ 'id' => 42, 'sizeSlug' => 'large' ], $blocks[0]['attrs'] );
	}
}
```

- [ ] **Step 1.3.2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/phpunit/ParseBlocksStubTest.php`

Expected: at minimum `test_plain_text_returns_single_freeform_block`, `test_freeform_before_block`, `test_freeform_between_blocks`, `test_freeform_after_block`, and `test_nested_blocks_split_inner_content_with_nulls` FAIL. Existing-stub-passing assertions (empty string, attrs decode, self-closing) may PASS.

- [ ] **Step 1.3.3: Replace the `parse_blocks` stub**

In `tests/phpunit/bootstrap.php`, replace the entire `if ( ! function_exists( 'parse_blocks' ) ) { ... }` block (around lines 2411-2474) with:

```php
	if ( ! function_exists( 'parse_blocks' ) ) {
		function parse_blocks( string $content ): array {
			if ( '' === $content ) {
				return [];
			}

			$blocks = [];
			$offset = 0;
			$length = strlen( $content );

			while ( $offset < $length ) {
				$next = _flavor_agent_parse_next_block( $content, $offset );

				if ( null === $next ) {
					$remainder = substr( $content, $offset );
					if ( '' !== $remainder ) {
						$blocks[] = _flavor_agent_make_freeform_block( $remainder );
					}
					break;
				}

				if ( $next['start'] > $offset ) {
					$freeform = substr( $content, $offset, $next['start'] - $offset );
					if ( '' !== $freeform ) {
						$blocks[] = _flavor_agent_make_freeform_block( $freeform );
					}
				}

				$blocks[] = $next['parsed'];
				$offset   = $next['end'];
			}

			return $blocks;
		}
	}

	if ( ! function_exists( '_flavor_agent_make_freeform_block' ) ) {
		function _flavor_agent_make_freeform_block( string $html ): array {
			return [
				'blockName'    => null,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => $html,
				'innerContent' => [ $html ],
			];
		}
	}

	if ( ! function_exists( '_flavor_agent_parse_next_block' ) ) {
		function _flavor_agent_parse_next_block( string $content, int $offset ): ?array {
			$pattern = '/<!--\s+wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)\s*(\{.*?\})?\s*(\/)?-->/s';

			if ( ! preg_match( $pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
				return null;
			}

			$full_match   = $match[0][0];
			$match_pos    = $match[0][1];
			$short_name   = $match[1][0];
			$block_name   = str_contains( $short_name, '/' ) ? $short_name : 'core/' . $short_name;
			$attrs_json   = $match[2][0] ?? '';
			$self_closing = ! empty( $match[3][0] );

			$attrs = [];
			if ( '' !== $attrs_json ) {
				$decoded = json_decode( $attrs_json, true );
				if ( is_array( $decoded ) ) {
					$attrs = $decoded;
				}
			}

			$opening_end = $match_pos + strlen( $full_match );

			if ( $self_closing ) {
				return [
					'start'  => $match_pos,
					'end'    => $opening_end,
					'parsed' => [
						'blockName'    => $block_name,
						'attrs'        => $attrs,
						'innerBlocks'  => [],
						'innerHTML'    => '',
						'innerContent' => [],
					],
				];
			}

			$close_tag       = '<!-- /wp:' . $short_name . ' -->';
			$same_open_regex = '/<!--\s+wp:' . preg_quote( $short_name, '/' ) . '(?:\s+\{.*?\})?\s*(\/)?-->/s';

			// Find matching close, accounting for same-name nesting.
			$depth     = 1;
			$scan_pos  = $opening_end;
			$close_pos = -1;

			while ( $scan_pos < strlen( $content ) ) {
				$next_open  = preg_match( $same_open_regex, $content, $same_open_match, PREG_OFFSET_CAPTURE, $scan_pos )
					? $same_open_match[0][1]
					: false;
				$next_close = strpos( $content, $close_tag, $scan_pos );

				if ( false === $next_close ) {
					break;
				}

				if ( false !== $next_open && $next_open < $next_close ) {
					if ( empty( $same_open_match[1][0] ) ) {
						++$depth;
					}
					$scan_pos = $next_open + strlen( (string) $same_open_match[0][0] );
					continue;
				}

				--$depth;
				if ( 0 === $depth ) {
					$close_pos = $next_close;
					break;
				}
				$scan_pos = $next_close + strlen( $close_tag );
			}

			if ( $close_pos < 0 ) {
				return [
					'start'  => $match_pos,
					'end'    => $opening_end,
					'parsed' => [
						'blockName'    => $block_name,
						'attrs'        => $attrs,
						'innerBlocks'  => [],
						'innerHTML'    => '',
						'innerContent' => [],
					],
				];
			}

			$inner_start  = $opening_end;
			$inner_length = $close_pos - $inner_start;

			// Walk the inner range, splitting at child block ranges so innerContent
			// alternates string chunks with nulls (one null per child top-level block).
			$inner_offset  = $inner_start;
			$inner_end     = $close_pos;
			$inner_content = [];
			$inner_html    = '';
			$inner_blocks  = [];

			while ( $inner_offset < $inner_end ) {
				$child = _flavor_agent_parse_next_block( $content, $inner_offset );

				if ( null === $child || $child['start'] >= $inner_end ) {
					$tail = substr( $content, $inner_offset, $inner_end - $inner_offset );
					if ( '' !== $tail ) {
						$inner_content[] = $tail;
						$inner_html     .= $tail;
					}
					break;
				}

				if ( $child['start'] > $inner_offset ) {
					$prefix = substr( $content, $inner_offset, $child['start'] - $inner_offset );
					if ( '' !== $prefix ) {
						$inner_content[] = $prefix;
						$inner_html     .= $prefix;
					}
				}

				$inner_content[] = null;
				$inner_blocks[]  = $child['parsed'];
				$inner_offset    = $child['end'];
			}

			return [
				'start'  => $match_pos,
				'end'    => $close_pos + strlen( $close_tag ),
				'parsed' => [
					'blockName'    => $block_name,
					'attrs'        => $attrs,
					'innerBlocks'  => $inner_blocks,
					'innerHTML'    => $inner_html,
					'innerContent' => $inner_content,
				],
			];
		}
	}
```

- [ ] **Step 1.3.4: Run the new test file to verify it passes**

Run: `vendor/bin/phpunit tests/phpunit/ParseBlocksStubTest.php`

Expected: PASS (7 tests).

- [ ] **Step 1.3.5: Commit**

```bash
git add tests/phpunit/bootstrap.php tests/phpunit/ParseBlocksStubTest.php
git commit -m "test: add freeform-aware parse_blocks stub with proper innerContent splits"
```

### Task 1.4: Add recursive `render_block` stub

- [ ] **Step 1.4.1: Write failing tests**

Create `tests/phpunit/RenderBlockStubTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class RenderBlockStubTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
	}

	public function test_freeform_block_returns_inner_html(): void {
		$block = [
			'blockName'    => null,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => 'Hello',
			'innerContent' => [ 'Hello' ],
		];

		$this->assertSame( 'Hello', render_block( $block ) );
	}

	public function test_static_block_returns_concatenated_inner_content(): void {
		$block = [
			'blockName'    => 'core/paragraph',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '<p>Body</p>',
			'innerContent' => [ '<p>Body</p>' ],
		];

		$this->assertSame( '<p>Body</p>', render_block( $block ) );
	}

	public function test_static_parent_renders_inner_blocks_at_null_positions(): void {
		$inner_paragraph = [
			'blockName'    => 'core/paragraph',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '<p>Inside</p>',
			'innerContent' => [ '<p>Inside</p>' ],
		];

		$group = [
			'blockName'    => 'core/group',
			'attrs'        => [],
			'innerBlocks'  => [ $inner_paragraph ],
			'innerHTML'    => '<div></div>',
			'innerContent' => [ '<div>', null, '</div>' ],
		];

		$this->assertSame( '<div><p>Inside</p></div>', render_block( $group ) );
	}

	public function test_dynamic_block_render_callback_receives_attrs_and_inner(): void {
		register_block_type(
			'flavor-agent-test/echo-attrs',
			[
				'render_callback' => static fn( array $attrs, string $inner ): string => sprintf(
					'<echo data-label="%s">%s</echo>',
					(string) ( $attrs['label'] ?? '' ),
					$inner
				),
			]
		);

		$block = [
			'blockName'    => 'flavor-agent-test/echo-attrs',
			'attrs'        => [ 'label' => 'hi' ],
			'innerBlocks'  => [],
			'innerHTML'    => 'inner',
			'innerContent' => [ 'inner' ],
		];

		$this->assertSame( '<echo data-label="hi">inner</echo>', render_block( $block ) );
	}

	public function test_dynamic_block_inside_static_group_executes_callback(): void {
		register_block_type(
			'flavor-agent-test/marker',
			[
				'render_callback' => static fn(): string => '<marker>HIT</marker>',
			]
		);

		$inner_dynamic = [
			'blockName'    => 'flavor-agent-test/marker',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$group = [
			'blockName'    => 'core/group',
			'attrs'        => [],
			'innerBlocks'  => [ $inner_dynamic ],
			'innerHTML'    => '<div></div>',
			'innerContent' => [ '<div>', null, '</div>' ],
		];

		$this->assertSame( '<div><marker>HIT</marker></div>', render_block( $group ) );
	}
}
```

- [ ] **Step 1.4.2: Run tests to verify they fail with "render_block undefined"**

Run: `vendor/bin/phpunit tests/phpunit/RenderBlockStubTest.php`

Expected: FAIL (`Error: Call to undefined function render_block()`).

- [ ] **Step 1.4.3: Add the `render_block` stub**

In `tests/phpunit/bootstrap.php`, immediately before the `if ( ! function_exists( 'parse_blocks' ) )` block (which is now after the new helpers), add:

```php
	if ( ! function_exists( 'render_block' ) ) {
		function render_block( array $block ): string {
			$name = $block['blockName'] ?? null;

			if ( null === $name ) {
				return (string) ( $block['innerHTML'] ?? '' );
			}

			$rendered_inner  = '';
			$inner_blocks    = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];
			$inner_block_idx = 0;
			$inner_content   = $block['innerContent'] ?? [ $block['innerHTML'] ?? '' ];

			if ( ! is_array( $inner_content ) ) {
				$inner_content = [ (string) $inner_content ];
			}

			foreach ( $inner_content as $chunk ) {
				if ( is_string( $chunk ) ) {
					$rendered_inner .= $chunk;
					continue;
				}

				$next = $inner_blocks[ $inner_block_idx++ ] ?? null;
				if ( is_array( $next ) ) {
					$rendered_inner .= render_block( $next );
				}
			}

			$registered      = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
			$render_callback = is_object( $registered ) ? ( $registered->render_callback ?? null ) : null;

			if ( is_callable( $render_callback ) ) {
				return (string) call_user_func(
					$render_callback,
					$block['attrs'] ?? [],
					$rendered_inner,
					$block
				);
			}

			return $rendered_inner;
		}
	}
```

- [ ] **Step 1.4.4: Run the test file to verify it passes**

Run: `vendor/bin/phpunit tests/phpunit/RenderBlockStubTest.php`

Expected: PASS (5 tests).

- [ ] **Step 1.4.5: Commit**

```bash
git add tests/phpunit/bootstrap.php tests/phpunit/RenderBlockStubTest.php
git commit -m "test: add recursive render_block stub with block-type registry callbacks"
```

### Task 1.5: Audit existing PHP suite for breakage from the new stubs (gating step)

- [ ] **Step 1.5.1: Run the full PHP suite**

Run: `vendor/bin/phpunit 2>&1 | tail -40`

Expected: ideally PASS for everything that previously passed. The new `parse_blocks` may produce freeform blocks where the old stub returned `[]`, which most consumers (`PatternOverrideAnalyzer::collect_pattern_override_metadata`, `TemplatePartContextCollector`, `NavigationParser::parse_navigation_source`, `NavigationContextCollector`) handle by inspecting `$block['blockName']` — a `null` blockName is a no-op for the override walker and a non-navigation block for the navigation lookup, so behavior should be preserved.

- [ ] **Step 1.5.2: If any test fails, triage**

For each failure:
1. Identify whether the test fixture relied on `parse_blocks` returning `[]` for content that now produces a freeform block.
2. If the production code under test now over-counts because of a freeform block, fix the fixture (use `''` for "no blocks" or an explicit block-marked fixture for "no static blocks").
3. Do **NOT** add `if ( null === $blockName ) continue;` guards to production code unless the spec explicitly calls for it. The intent is to model production WP behavior in the stub; production code already handles freeform blocks correctly when it skips by `blockName`.
4. Re-run after each fix.

- [ ] **Step 1.5.3: Once green, commit any test fixture fixes**

```bash
git add tests/phpunit/<modified-files>
git commit -m "test: align fixtures with freeform-aware parse_blocks stub"
```

(If no fixture changes were needed, skip this commit.)

---

## Task 2: `PostContentRenderer` scaffold + postId-gate

**Why:** Establishes the class, the public method signature, and the highest-priority safety property: no rendering without an authorized postId. Also adds the simplest passing test (a static paragraph) so the class has a baseline to grow from.

**Files:**
- Create: `inc/Context/PostContentRenderer.php`
- Create: `tests/phpunit/PostContentRendererTest.php`

### Task 2.1: Write failing scaffolding tests

- [ ] **Step 2.1.1: Create the test file with three failing tests**

Create `tests/phpunit/PostContentRendererTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\PostContentRenderer;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PostContentRendererTest extends TestCase {

	private PostContentRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		$this->renderer = new PostContentRenderer();
		$this->seed_post( 100, 'Working draft', 'post' );
	}

	public function test_extract_falls_back_when_postid_is_missing(): void {
		$out = $this->renderer->extract( "<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->\r\n" );

		// Fallback path = sanitize_textarea_field after \r strip. Block markers survive
		// because sanitize_textarea_field does not strip HTML comments.
		$this->assertStringContainsString( 'Hello', $out );
		$this->assertStringNotContainsString( '<p>', $out );
	}

	public function test_extract_does_not_run_render_callback_when_postid_is_missing(): void {
		// Sentinel: a side-effecting render_callback registered for a dynamic block
		// must NOT run when no postId is provided. The fallback assertion above
		// (assertStringContainsString 'Hello') would still pass if a regression
		// silently called render_block, so this test pins the actual invariant.
		$called = false;
		register_block_type(
			'flavor-agent-test/sentinel-missing-postid',
			[
				'render_callback' => static function () use ( &$called ): string {
					$called = true;
					return 'should not appear';
				},
			]
		);

		$this->renderer->extract( '<!-- wp:flavor-agent-test/sentinel-missing-postid /-->' );

		$this->assertFalse( $called );
	}

	public function test_extract_falls_back_when_postid_is_zero(): void {
		$out = $this->renderer->extract(
			'<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			[ 'postId' => 0 ]
		);

		$this->assertStringContainsString( 'Hello', $out );
		$this->assertStringNotContainsString( '<p>', $out );
	}

	public function test_extract_does_not_run_render_callback_when_postid_is_zero(): void {
		$called = false;
		register_block_type(
			'flavor-agent-test/sentinel-zero-postid',
			[
				'render_callback' => static function () use ( &$called ): string {
					$called = true;
					return 'should not appear';
				},
			]
		);

		$this->renderer->extract(
			'<!-- wp:flavor-agent-test/sentinel-zero-postid /-->',
			[ 'postId' => 0 ]
		);

		$this->assertFalse( $called );
	}

	public function test_extract_falls_back_when_post_does_not_exist(): void {
		// Defense in depth: positive postId but get_post returns null (no seeded post for 9999).
		// The renderer must not execute any render_callback when there is no valid post identity.
		$called = false;
		register_block_type(
			'flavor-agent-test/sentinel-no-post',
			[
				'render_callback' => static function () use ( &$called ): string {
					$called = true;
					return 'should not appear';
				},
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/sentinel-no-post /-->',
			[ 'postId' => 9999 ]
		);

		$this->assertFalse( $called, 'render_callback must not run when get_post returns null.' );
		$this->assertStringNotContainsString( 'should not appear', $out );
	}

	public function test_extract_renders_a_static_paragraph_when_postid_is_authorized(): void {
		$out = $this->renderer->extract(
			'<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
			[ 'postId' => 100 ]
		);

		$this->assertStringContainsString( 'Hello world', $out );
		$this->assertStringNotContainsString( '<p>', $out );
		$this->assertStringNotContainsString( '<!-- wp:', $out );
	}

	private function seed_post( int $id, string $title, string $post_type ): void {
		WordPressTestState::$posts[ $id ] = new \WP_Post(
			[
				'ID'         => $id,
				'post_title' => $title,
				'post_type'  => $post_type,
			]
		);
	}

	private function require_dom_extension(): void {
		if ( ! class_exists( \DOMDocument::class ) || ! class_exists( \DOMXPath::class ) ) {
			$this->markTestSkipped( 'ext-dom is required for attribute-walk tests; renderer fallback path is exercised elsewhere.' );
		}
	}
}
```

- [ ] **Step 2.1.2: Run the tests to verify they fail with "class not found"**

Run: `vendor/bin/phpunit tests/phpunit/PostContentRendererTest.php`

Expected: ERROR — `Error: Class "FlavorAgent\Context\PostContentRenderer" not found`.

### Task 2.2: Create the renderer class with postId-gated extract

- [ ] **Step 2.2.1: Create `inc/Context/PostContentRenderer.php`**

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class PostContentRenderer {

	private const MAX_ATTR_LENGTH = 500;

	private const MAX_ATTR_COUNT = 100;

	private const ALLOWED_HREF_SCHEMES = [ 'http://', 'https://', 'mailto:', 'tel:' ];

	/**
	 * @param array<string, mixed> $context
	 */
	public function extract( string $post_content, array $context = [] ): string {
		$post_content = str_replace( "\r", '', $post_content );
		$post_id      = (int) ( $context['postId'] ?? 0 );

		if ( $post_id <= 0 ) {
			return self::fallback( $post_content );
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			// Defense in depth: caller said "render against postId N" but no such post
			// exists (deleted, race, malformed input). Don't execute render_callbacks
			// without a valid post identity. ContentAbilities's edit_post auth check
			// is the primary gate — this is the inner one.
			return self::fallback( $post_content );
		}

		$blocks = parse_blocks( $post_content );
		if ( [] === $blocks ) {
			return self::fallback( $post_content );
		}

		// Implementation grows in subsequent tasks.
		// For now, render every top-level block with render_block and join.
		$chunks = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$chunks[] = wp_strip_all_tags( (string) render_block( $block ) );
		}

		$visible = trim( implode( "\n\n", array_filter( array_map( 'trim', $chunks ) ) ) );

		if ( '' === $visible ) {
			return self::fallback( $post_content );
		}

		return $visible;
	}

	private static function fallback( string $post_content ): string {
		return sanitize_textarea_field( $post_content );
	}
}
```

- [ ] **Step 2.2.2: Run the test file to verify it passes**

Run: `vendor/bin/phpunit tests/phpunit/PostContentRendererTest.php`

Expected: PASS (6 tests).

- [ ] **Step 2.2.3: Commit**

```bash
git add inc/Context/PostContentRenderer.php tests/phpunit/PostContentRendererTest.php
git commit -m "feat: scaffold PostContentRenderer with postId-gated rendering"
```

---

## Task 3: Static-block rendering with multiple siblings

**Why:** Validates the per-top-level-block render loop produces correct output for the simplest realistic case (paragraph + heading + list), which is what most blog posts look like.

**Files:**
- Modify: `tests/phpunit/PostContentRendererTest.php`

### Task 3.1: Add a sibling-static-blocks test

- [ ] **Step 3.1.1: Append test**

Add to `tests/phpunit/PostContentRendererTest.php` (before the private `seed_post` helper):

```php
	public function test_extract_captures_sibling_static_blocks_in_document_order(): void {
		$content = '<!-- wp:paragraph --><p>First paragraph.</p><!-- /wp:paragraph -->'
			. '<!-- wp:heading --><h2>Second heading.</h2><!-- /wp:heading -->'
			. '<!-- wp:list --><ul><li>Third item.</li></ul><!-- /wp:list -->';

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertStringContainsString( 'First paragraph.', $out );
		$this->assertStringContainsString( 'Second heading.', $out );
		$this->assertStringContainsString( 'Third item.', $out );

		$first_pos  = strpos( $out, 'First paragraph.' );
		$second_pos = strpos( $out, 'Second heading.' );
		$third_pos  = strpos( $out, 'Third item.' );

		$this->assertNotFalse( $first_pos );
		$this->assertNotFalse( $second_pos );
		$this->assertNotFalse( $third_pos );
		$this->assertLessThan( $second_pos, $first_pos );
		$this->assertLessThan( $third_pos, $second_pos );
	}
```

- [ ] **Step 3.1.2: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter test_extract_captures_sibling_static_blocks_in_document_order tests/phpunit/PostContentRendererTest.php`

Expected: PASS — the simple loop in Task 2.2 already produces this. (If it does not pass because heading/list strip-tags collapse too aggressively, that surfaces the need for boundary-preserving strip in Task 6 — for this assertion we only check substring + ordering, not separation.)

- [ ] **Step 3.1.3: Commit**

```bash
git add tests/phpunit/PostContentRendererTest.php
git commit -m "test: assert sibling static blocks render in document order"
```

---

## Task 4: `setup_postdata` wrapping for global-dependent blocks

**Why:** Dynamic blocks (`core/post-title`, `core/query` row callbacks, custom plugins) read `$GLOBALS['post']`. Without setup_postdata, they render against null and produce wrong output. This also locks in the `try { ... } finally { ... }` pattern so a render exception cannot leak global state.

**Files:**
- Modify: `tests/phpunit/PostContentRendererTest.php`
- Modify: `inc/Context/PostContentRenderer.php`

### Task 4.1: Failing test — render-time globals are present and restored

- [ ] **Step 4.1.1: Append test**

Add to `tests/phpunit/PostContentRendererTest.php` (before `seed_post`):

```php
	public function test_extract_sets_up_post_globals_during_render_and_restores_after(): void {
		$captured = [ 'post_id' => null ];
		register_block_type(
			'flavor-agent-test/global-aware',
			[
				'render_callback' => static function () use ( &$captured ): string {
					$post              = $GLOBALS['post'] ?? null;
					$captured['post_id'] = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : null;
					return '<global>seen</global>';
				},
			]
		);

		$pre_post = $GLOBALS['post'] ?? null;
		$pre_state_post = WordPressTestState::$current_post;

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/global-aware /-->',
			[ 'postId' => 100 ]
		);

		$this->assertSame( 100, $captured['post_id'], 'render_callback must see $GLOBALS[\'post\'] set to the rendering post.' );
		$this->assertStringContainsString( 'seen', $out );
		$this->assertSame( $pre_post, $GLOBALS['post'] ?? null, '$GLOBALS[\'post\'] must be restored after render.' );
		$this->assertSame( $pre_state_post, WordPressTestState::$current_post, 'setup_postdata wrapper must call wp_reset_postdata.' );
	}
```

- [ ] **Step 4.1.2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter test_extract_sets_up_post_globals_during_render_and_restores_after tests/phpunit/PostContentRendererTest.php`

Expected: FAIL — `$captured['post_id']` is `null` because we have not set globals yet.

### Task 4.2: Add globals wrapper to extract

- [ ] **Step 4.2.1: Refactor `PostContentRenderer::extract` to use a per-block render helper**

Replace the body of `extract()` in `inc/Context/PostContentRenderer.php` with the version that sets up globals and uses a dedicated render method:

```php
	/**
	 * @param array<string, mixed> $context
	 */
	public function extract( string $post_content, array $context = [] ): string {
		$post_content = str_replace( "\r", '', $post_content );
		$post_id      = (int) ( $context['postId'] ?? 0 );

		if ( $post_id <= 0 ) {
			return self::fallback( $post_content );
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			return self::fallback( $post_content );
		}

		$blocks = parse_blocks( $post_content );
		if ( [] === $blocks ) {
			return self::fallback( $post_content );
		}

		[ $stripped_chunks, $rendered_html ] = $this->render_with_globals( $blocks, $post, $context );

		$visible = trim( implode( "\n\n", array_filter( array_map( 'trim', $stripped_chunks ) ) ) );

		if ( '' === $visible ) {
			return self::fallback( $post_content );
		}

		return $visible;
	}

	/**
	 * @param array<int, mixed>     $blocks
	 * @param array<string, mixed>  $context
	 * @return array{0: array<int, string>, 1: string}
	 */
	private function render_with_globals( array $blocks, \WP_Post $post, array $context ): array {
		$had_global    = array_key_exists( 'post', $GLOBALS );
		$original_post = $had_global ? $GLOBALS['post'] : null;

		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		$stripped_chunks = [];
		$rendered_html   = '';

		try {
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				$rendered = (string) render_block( $block );
				$rendered_html .= $rendered;
				$stripped_chunks[] = wp_strip_all_tags( $rendered );
			}
		} finally {
			if ( $had_global ) {
				$GLOBALS['post'] = $original_post;
				if ( $original_post instanceof \WP_Post ) {
					setup_postdata( $original_post );
				} else {
					wp_reset_postdata();
				}
			} else {
				unset( $GLOBALS['post'] );
				wp_reset_postdata();
			}
		}

		return [ $stripped_chunks, $rendered_html ];
	}
```

- [ ] **Step 4.2.2: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter test_extract_sets_up_post_globals_during_render_and_restores_after tests/phpunit/PostContentRendererTest.php`

Expected: PASS.

- [ ] **Step 4.2.3: Run the renderer test class to confirm no regression**

Run: `vendor/bin/phpunit tests/phpunit/PostContentRendererTest.php`

Expected: PASS (8 tests).

- [ ] **Step 4.2.4: Commit**

```bash
git add inc/Context/PostContentRenderer.php tests/phpunit/PostContentRendererTest.php
git commit -m "feat: wrap PostContentRenderer render loop in setup_postdata/restore"
```

---

## Task 5: Per-block render-failure handling

**Why:** A buggy plugin's `render_callback` should not blow up the entire recommendation request. The renderer must catch `Throwable`, log via `error_log`, and substitute a marker so siblings still surface.

**Files:**
- Modify: `tests/phpunit/PostContentRendererTest.php`
- Modify: `inc/Context/PostContentRenderer.php`

### Task 5.1: Failing test — failed block is replaced with marker, siblings survive

- [ ] **Step 5.1.1: Append test**

Add to `tests/phpunit/PostContentRendererTest.php`:

```php
	public function test_extract_replaces_failed_block_with_marker_and_continues(): void {
		register_block_type(
			'flavor-agent-test/explody',
			[
				'render_callback' => static function () {
					throw new \RuntimeException( 'boom' );
				},
			]
		);

		$content = '<!-- wp:paragraph --><p>Survivor before.</p><!-- /wp:paragraph -->'
			. '<!-- wp:flavor-agent-test/explody /-->'
			. '<!-- wp:paragraph --><p>Survivor after.</p><!-- /wp:paragraph -->';

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertStringContainsString( 'Survivor before.', $out );
		$this->assertStringContainsString( 'Survivor after.', $out );
		$this->assertStringContainsString( '[block render failed: flavor-agent-test/explody]', $out );
	}
```

- [ ] **Step 5.1.2: Run the test to verify it fails with an unhandled exception**

Run: `vendor/bin/phpunit --filter test_extract_replaces_failed_block_with_marker_and_continues tests/phpunit/PostContentRendererTest.php`

Expected: FAIL — exception bubbles out of `render_block`.

### Task 5.2: Add try/catch around per-block render

- [ ] **Step 5.2.1: Replace the render loop body in `render_with_globals`**

In `inc/Context/PostContentRenderer.php`, replace the `try { ... }` body inside `render_with_globals` with:

```php
		try {
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				$block_name = (string) ( $block['blockName'] ?? '' );

				try {
					$rendered = (string) render_block( $block );
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Defense-in-depth log so a buggy third-party render_callback surfaces in PHP error logs without aborting the recommendation. No structured logger is wired in this surface yet.
					error_log(
						sprintf(
							'[flavor-agent] PostContentRenderer: render_block failed for %s — %s',
							'' !== $block_name ? $block_name : 'freeform',
							$e->getMessage()
						)
					);
					$marker            = sprintf(
						'[block render failed: %s]',
						'' !== $block_name ? $block_name : 'freeform'
					);
					$stripped_chunks[] = $marker;
					continue;
				}

				$rendered_html    .= $rendered;
				$stripped_chunks[] = wp_strip_all_tags( $rendered );
			}
		} finally {
```

(The `} finally {` line stays the same — we are only changing the `try` body.)

- [ ] **Step 5.2.2: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter test_extract_replaces_failed_block_with_marker_and_continues tests/phpunit/PostContentRendererTest.php`

Expected: PASS.

- [ ] **Step 5.2.3: Run the renderer test class to confirm no regression**

Run: `vendor/bin/phpunit tests/phpunit/PostContentRendererTest.php`

Expected: PASS (9 tests).

- [ ] **Step 5.2.4: Commit**

```bash
git add inc/Context/PostContentRenderer.php tests/phpunit/PostContentRendererTest.php
git commit -m "feat: catch per-block render failures and replace with marker"
```

---

## Task 6: Boundary-preserving strip (`strip_block_html`)

**Why:** `wp_strip_all_tags` runs glued together when sibling block-level elements share text — `<p>One</p><p>Two</p>` becomes `OneTwo`. Inserting a newline before each block-level closing tag preserves separation through strip.

**Files:**
- Modify: `tests/phpunit/PostContentRendererTest.php`
- Modify: `inc/Context/PostContentRenderer.php`

### Task 6.1: Failing test — multiple block-level elements stay separated post-strip

- [ ] **Step 6.1.1: Append test**

Add to `tests/phpunit/PostContentRendererTest.php`:

```php
	public function test_extract_keeps_block_level_elements_separated_after_strip(): void {
		// One block whose render output has multiple sibling block-level elements.
		register_block_type(
			'flavor-agent-test/glued',
			[
				'render_callback' => static fn(): string => '<p>One</p><p>Two</p><h2>Three</h2><li>Four</li>',
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/glued /-->',
			[ 'postId' => 100 ]
		);

		$this->assertStringContainsString( 'One', $out );
		$this->assertStringContainsString( 'Two', $out );
		$this->assertStringContainsString( 'Three', $out );
		$this->assertStringContainsString( 'Four', $out );
		// Strip must not glue them: there must be at least one whitespace
		// boundary between the four tokens.
		$this->assertMatchesRegularExpression( '/One\s+Two/', $out );
		$this->assertMatchesRegularExpression( '/Two\s+Three/', $out );
		$this->assertMatchesRegularExpression( '/Three\s+Four/', $out );
	}

	public function test_extract_preserves_br_separator_in_visible_text(): void {
		register_block_type(
			'flavor-agent-test/br-glued',
			[
				'render_callback' => static fn(): string => '<p>One<br>Two<br/>Three</p>',
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/br-glued /-->',
			[ 'postId' => 100 ]
		);

		$this->assertMatchesRegularExpression( '/One\s+Two/', $out );
		$this->assertMatchesRegularExpression( '/Two\s+Three/', $out );
	}

	public function test_extract_preserves_hr_separator_in_visible_text(): void {
		register_block_type(
			'flavor-agent-test/hr-glued',
			[
				'render_callback' => static fn(): string => '<p>Above</p><hr><p>Below</p>',
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/hr-glued /-->',
			[ 'postId' => 100 ]
		);

		$this->assertMatchesRegularExpression( '/Above\s+Below/', $out );
	}
```

- [ ] **Step 6.1.2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter "block_level_elements_separated|br_separator|hr_separator" tests/phpunit/PostContentRendererTest.php`

Expected: FAIL — output contains `OneTwo` / `AboveBelow` etc., the regex fails on missing whitespace.

### Task 6.2: Add `strip_block_html` and route per-block strips through it

- [ ] **Step 6.2.1: Add the helper and call it from the render loop**

In `inc/Context/PostContentRenderer.php`, add a new private method (above `private static function fallback`):

```php
	private function strip_block_html( string $html ): string {
		// Insert a newline before each block-level closing tag so siblings stay
		// separated after wp_strip_all_tags. `hr` is intentionally NOT in this
		// list — it's a void element with no closing form.
		$with_breaks = preg_replace(
			'#</(p|div|h[1-6]|li|tr|td|th|blockquote|article|section|aside|header|footer|main|figure|figcaption|nav|ul|ol|table)\b[^>]*>#i',
			"\n$0",
			$html
		);

		if ( null === $with_breaks ) {
			$with_breaks = $html;
		}

		// Insert newlines around void separators (<br>, <hr>) — both standard and
		// XHTML-style. Without this, "One<br>Two" strips to "OneTwo".
		$with_breaks = preg_replace(
			'#<(br|hr)\b[^>]*/?>#i',
			"\n$0\n",
			$with_breaks
		) ?? $with_breaks;

		return trim( wp_strip_all_tags( $with_breaks ) );
	}
```

In `render_with_globals`, replace the line:

```php
				$stripped_chunks[] = wp_strip_all_tags( $rendered );
```

with:

```php
				$stripped_chunks[] = $this->strip_block_html( $rendered );
```

- [ ] **Step 6.2.2: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter "block_level_elements_separated|br_separator|hr_separator" tests/phpunit/PostContentRendererTest.php`

Expected: PASS (3 tests).

- [ ] **Step 6.2.3: Run the renderer test class to confirm no regression**

Run: `vendor/bin/phpunit tests/phpunit/PostContentRendererTest.php`

Expected: PASS (12 tests).

- [ ] **Step 6.2.4: Commit**

```bash
git add inc/Context/PostContentRenderer.php tests/phpunit/PostContentRendererTest.php
git commit -m "feat: insert newlines before closing block-level tags before strip"
```

---

## Task 7: Nested-blocks coverage (sanity)

**Why:** Verify that `render_block` walking innerContent + innerBlocks yields all nested static text without recursion or duplication. This should pass without renderer code changes — it validates the bootstrap stub from Task 1.4.

**Files:**
- Modify: `tests/phpunit/PostContentRendererTest.php`

### Task 7.1: Add the test

- [ ] **Step 7.1.1: Append test**

Add to `tests/phpunit/PostContentRendererTest.php`:

```php
	public function test_extract_captures_nested_static_blocks_without_duplication(): void {
		$content = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:heading --><h2>Section title.</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Section body.</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertSame(
			1,
			substr_count( $out, 'Section title.' ),
			'Inner heading must appear exactly once.'
		);
		$this->assertSame(
			1,
			substr_count( $out, 'Section body.' ),
			'Inner paragraph must appear exactly once.'
		);
	}

	public function test_extract_runs_dynamic_callback_inside_static_group(): void {
		register_block_type(
			'flavor-agent-test/nested-dynamic',
			[
				'render_callback' => static fn(): string => '<aside>Dynamic inner.</aside>',
			]
		);

		$content = '<!-- wp:group --><div>'
			. '<!-- wp:flavor-agent-test/nested-dynamic /-->'
			. '</div><!-- /wp:group -->';

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertStringContainsString( 'Dynamic inner.', $out );
	}
```

- [ ] **Step 7.1.2: Run the new tests to verify they pass**

Run: `vendor/bin/phpunit --filter "nested" tests/phpunit/PostContentRendererTest.php`

Expected: PASS (2 tests). If `test_extract_captures_nested_static_blocks_without_duplication` fails, the parse_blocks stub's innerContent/innerHTML split is producing duplicated text — return to Task 1.3 and double-check the inner_html accumulation skips child block ranges.

- [ ] **Step 7.1.3: Commit**

```bash
git add tests/phpunit/PostContentRendererTest.php
git commit -m "test: assert nested static blocks and inner dynamic callbacks render once"
```

---

## Task 8: Attribute walk (`extract_html_attributes`)

**Why:** `alt`, `aria-label`, `title`, and `href` carry editorial signal that strip-tags wipes. We harvest them from rendered HTML using `DOMDocument`/`DOMXPath`, with hard caps and an href-scheme allowlist.

This task is the largest — it has eight sub-tasks. Each adds a single behavior with its own test. Implement them in this order so each red→green cycle is small.

**Files:**
- Modify: `inc/Context/PostContentRenderer.php`
- Modify: `tests/phpunit/PostContentRendererTest.php`

### Task 8.1: Realistic Gutenberg image — `alt` reaches the output

- [ ] **Step 8.1.1: Failing test**

Add to `tests/phpunit/PostContentRendererTest.php`:

```php
	public function test_extract_harvests_image_alt_attribute(): void {
		$this->require_dom_extension();

		$content = '<!-- wp:image {"id":42} -->'
			. '<figure class="wp-block-image"><img src="https://example.test/image.jpg" alt="A meaningful description" /><figcaption>Caption text.</figcaption></figure>'
			. '<!-- /wp:image -->';

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertStringContainsString( '[Attribute references]', $out );
		$this->assertStringContainsString( 'A meaningful description', $out );
		$this->assertStringContainsString( 'Caption text.', $out );
		// img[src] is intentionally NOT extracted per spec — only img[alt], a[href],
		// [aria-label], and [title]. Asserting absence here documents the boundary.
		$ref_section = strstr( $out, '[Attribute references]' );
		$this->assertNotFalse( $ref_section );
		$this->assertStringNotContainsString( 'https://example.test/image.jpg', $ref_section );
	}
```

- [ ] **Step 8.1.2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter test_extract_harvests_image_alt_attribute tests/phpunit/PostContentRendererTest.php`

Expected: FAIL — output has the caption (visible text) but no `[Attribute references]` block.

### Task 8.2: Realistic button — `href` reaches the output

- [ ] **Step 8.2.1: Failing test**

Append to the test file:

```php
	public function test_extract_harvests_button_url(): void {
		$this->require_dom_extension();

		$content = '<!-- wp:button -->'
			. '<div class="wp-block-button"><a class="wp-block-button__link" href="https://example.test/destination">Click me</a></div>'
			. '<!-- /wp:button -->';

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertStringContainsString( 'Click me', $out );
		$this->assertStringContainsString( '[Attribute references]', $out );
		$this->assertStringContainsString( 'https://example.test/destination', $out );
	}
```

### Task 8.3: Implement `extract_html_attributes` minimum

- [ ] **Step 8.3.1: Add the helper**

In `inc/Context/PostContentRenderer.php`, add these methods (above `private static function fallback`):

```php
	/**
	 * @return array<int, string>
	 */
	private function extract_html_attributes( string $rendered_html ): array {
		if ( '' === $rendered_html ) {
			return [];
		}

		if ( ! class_exists( \DOMDocument::class ) || ! class_exists( \DOMXPath::class ) ) {
			return [];
		}

		$doc             = new \DOMDocument();
		$previous_libxml = libxml_use_internal_errors( true );

		try {
			$wrapped = '<?xml encoding="UTF-8"?><div>' . $rendered_html . '</div>';
			$loaded  = $doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

			if ( ! $loaded ) {
				return [];
			}

			$xpath   = new \DOMXPath( $doc );
			$strings = [];

			$append = function ( string $value ) use ( &$strings ): bool {
				if ( count( $strings ) >= self::MAX_ATTR_COUNT ) {
					return false;
				}
				// Normalize control chars and collapse whitespace runs to a single
				// space. Without this, an attribute containing newlines or markdown
				// punctuation (e.g., alt="\n\n## Fake instruction") could reshape
				// the prompt's section structure when emitted as a bullet line.
				$value = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) ?? $value;
				$value = preg_replace( '/\s+/', ' ', $value ) ?? $value;
				$value = trim( $value );
				if ( '' === $value ) {
					return true;
				}
				$value = self::truncate_attribute_value( $value );
				$strings[] = $value;
				return true;
			};

			foreach ( [ 'alt', 'title', 'aria-label' ] as $attr ) {
				if ( count( $strings ) >= self::MAX_ATTR_COUNT ) {
					break;
				}
				$nodes = $xpath->query( '//*[@' . $attr . ']' );
				if ( false === $nodes ) {
					continue;
				}
				foreach ( $nodes as $node ) {
					if ( ! ( $node instanceof \DOMElement ) ) {
						continue;
					}
					if ( ! $append( $node->getAttribute( $attr ) ) ) {
						break 2;
					}
				}
			}

			if ( count( $strings ) < self::MAX_ATTR_COUNT ) {
				$href_nodes = $xpath->query( '//a[@href]' );
				if ( false !== $href_nodes ) {
					foreach ( $href_nodes as $node ) {
						if ( ! ( $node instanceof \DOMElement ) ) {
							continue;
						}
						$href = trim( $node->getAttribute( 'href' ) );
						if ( '' === $href || '#' === ( $href[0] ?? '' ) ) {
							continue;
						}
						if ( ! $this->is_allowed_href_scheme( $href ) ) {
							continue;
						}
						if ( ! $append( $href ) ) {
							break;
						}
					}
				}
			}

			return array_values( array_unique( $strings ) );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_libxml );
		}
	}

	private function is_allowed_href_scheme( string $href ): bool {
		foreach ( self::ALLOWED_HREF_SCHEMES as $scheme ) {
			if ( 0 === stripos( $href, $scheme ) ) {
				return true;
			}
		}
		return ! preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $href );
	}

	private static function truncate_attribute_value( string $value ): string {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $value, 'UTF-8' ) > self::MAX_ATTR_LENGTH
				? mb_substr( $value, 0, self::MAX_ATTR_LENGTH, 'UTF-8' ) . '…'
				: $value;
		}

		return strlen( $value ) > self::MAX_ATTR_LENGTH
			? substr( $value, 0, self::MAX_ATTR_LENGTH ) . '…'
			: $value;
	}

	/**
	 * @param array<int, string> $attributes
	 */
	private function dedupe_against( array $attributes, string $visible ): array {
		if ( '' === $visible || [] === $attributes ) {
			return $attributes;
		}

		$lower_visible = strtolower( $visible );

		return array_values(
			array_filter(
				$attributes,
				static fn( string $attr ): bool => '' !== $attr
					&& false === stripos( $lower_visible, strtolower( $attr ) )
			)
		);
	}

	/**
	 * @param array<int, string> $attributes
	 */
	private function assemble_output( string $visible, array $attributes ): string {
		if ( '' !== $visible && [] !== $attributes ) {
			return $visible
				. "\n\n[Attribute references]\n- "
				. implode( "\n- ", $attributes );
		}

		if ( '' !== $visible ) {
			return $visible;
		}

		if ( [] !== $attributes ) {
			return "[Attribute references]\n- " . implode( "\n- ", $attributes );
		}

		return '';
	}
```

- [ ] **Step 8.3.2: Wire `extract_html_attributes` + `dedupe_against` + `assemble_output` into `extract`**

Replace the body of `extract()` after `[ $stripped_chunks, $rendered_html ] = $this->render_with_globals(...)` with:

```php
		$visible = trim( implode( "\n\n", array_filter( array_map( 'trim', $stripped_chunks ) ) ) );

		$attributes = $this->extract_html_attributes( $rendered_html );
		$attributes = $this->dedupe_against( $attributes, $visible );

		if ( '' === $visible && [] === $attributes ) {
			return self::fallback( $post_content );
		}

		return $this->assemble_output( $visible, $attributes );
```

- [ ] **Step 8.3.3: Run the new tests**

Run: `vendor/bin/phpunit --filter "harvests" tests/phpunit/PostContentRendererTest.php`

Expected: PASS (2 tests). If `test_extract_harvests_image_alt_attribute` produces duplicated `Caption text.` (one in visible, one in attributes from `figcaption`'s text), confirm `figcaption` does not have its own `alt`/`title`/`aria-label`/`href` — it should not be duplicated. Caption text is the visible content of `<figcaption>`, which is in `$visible`, not in `$attributes`.

- [ ] **Step 8.3.4: Run the full renderer test class to confirm no regression**

Run: `vendor/bin/phpunit tests/phpunit/PostContentRendererTest.php`

Expected: PASS (16 tests).

- [ ] **Step 8.3.5: Commit**

```bash
git add inc/Context/PostContentRenderer.php tests/phpunit/PostContentRendererTest.php
git commit -m "feat: harvest alt/title/aria-label/href via DOMDocument and assemble output"
```

### Task 8.4: Length cap test — single attribute over 500 chars truncates with ellipsis

- [ ] **Step 8.4.1: Add test**

```php
	public function test_extract_truncates_oversized_attribute_value(): void {
		$this->require_dom_extension();

		$long = str_repeat( 'A', 600 );
		register_block_type(
			'flavor-agent-test/long-alt',
			[
				'render_callback' => static fn() => sprintf(
					'<img src="https://example.test/img.png" alt="%s" />',
					$long
				),
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/long-alt /-->',
			[ 'postId' => 100 ]
		);

		$this->assertStringContainsString( '…', $out );
		$this->assertStringContainsString( str_repeat( 'A', 500 ), $out );
		$this->assertStringNotContainsString( str_repeat( 'A', 501 ), $out );
	}

	public function test_extract_truncates_attribute_values_without_breaking_utf8(): void {
		$this->require_dom_extension();

		$long = str_repeat( 'A', 499 ) . "\u{1F642}" . 'tail';
		register_block_type(
			'flavor-agent-test/utf8-attr',
			[
				'render_callback' => static fn(): string => '<img alt="' . $long . '" />',
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/utf8-attr /-->',
			[ 'postId' => 100 ]
		);

		$this->assertSame( 1, preg_match( '//u', $out ) );
		$this->assertStringContainsString( str_repeat( 'A', 499 ) . "\u{1F642}" . '…', $out );
		$this->assertStringNotContainsString( 'tail', $out );
	}
```

- [ ] **Step 8.4.2: Run to verify it passes** (already implemented in Task 8.3)

Run: `vendor/bin/phpunit --filter test_extract_truncates_oversized_attribute_value tests/phpunit/PostContentRendererTest.php`

Expected: PASS.

### Task 8.5: Count cap test — 200 images yields at most 100 attributes

- [ ] **Step 8.5.1: Add test**

```php
	public function test_extract_caps_attribute_count_at_one_hundred(): void {
		$this->require_dom_extension();

		$imgs = '';
		for ( $i = 0; $i < 200; $i++ ) {
			$imgs .= sprintf(
				'<img src="https://example.test/img-%1$d.png" alt="Alt %1$d" />',
				$i
			);
		}

		register_block_type(
			'flavor-agent-test/many-images',
			[
				'render_callback' => static fn() => $imgs,
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/many-images /-->',
			[ 'postId' => 100 ]
		);

		$ref_section = strstr( $out, '[Attribute references]' );
		$this->assertNotFalse( $ref_section );

		$line_count = substr_count( $ref_section, "\n- " );
		$this->assertLessThanOrEqual( 100, $line_count );
	}
```

- [ ] **Step 8.5.2: Run to verify it passes**

Run: `vendor/bin/phpunit --filter test_extract_caps_attribute_count_at_one_hundred tests/phpunit/PostContentRendererTest.php`

Expected: PASS.

### Task 8.6: Disallowed href schemes are dropped

- [ ] **Step 8.6.1: Add test**

```php
	public function test_extract_drops_disallowed_href_schemes(): void {
		$this->require_dom_extension();

		register_block_type(
			'flavor-agent-test/dangerous-links',
			[
				'render_callback' => static fn() => '<a href="javascript:alert(1)">x</a>'
					. '<a href="data:text/html,bad">y</a>'
					. '<a href="blob:https://x.test/abc">z</a>'
					. '<a href="vbscript:msgbox(0)">w</a>'
					. '<a href="https://kept.example.test/page">kept</a>'
					. '<a href="mailto:author@example.test">mail</a>'
					. '<a href="tel:+15551234567">call</a>'
					. '<a href="/relative/path">rel</a>',
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/dangerous-links /-->',
			[ 'postId' => 100 ]
		);

		$this->assertStringNotContainsString( 'javascript:', $out );
		$this->assertStringNotContainsString( 'data:', $out );
		$this->assertStringNotContainsString( 'blob:', $out );
		$this->assertStringNotContainsString( 'vbscript:', $out );
		$this->assertStringContainsString( 'https://kept.example.test/page', $out );
		$this->assertStringContainsString( 'mailto:author@example.test', $out );
		$this->assertStringContainsString( 'tel:+15551234567', $out );
		$this->assertStringContainsString( '/relative/path', $out );
	}
```

- [ ] **Step 8.6.2: Run to verify it passes**

Run: `vendor/bin/phpunit --filter test_extract_drops_disallowed_href_schemes tests/phpunit/PostContentRendererTest.php`

Expected: PASS.

### Task 8.7: libxml internal-error mode is restored

- [ ] **Step 8.7.1: Add test**

```php
	public function test_extract_restores_libxml_internal_error_mode(): void {
		$this->require_dom_extension();

		$before = libxml_use_internal_errors( false );

		// Restore baseline if some other test left it on.
		libxml_use_internal_errors( false );
		$this->assertFalse( libxml_use_internal_errors( false ) );

		$this->renderer->extract(
			'<!-- wp:paragraph --><p>Anything.</p><!-- /wp:paragraph -->',
			[ 'postId' => 100 ]
		);

		$this->assertFalse(
			libxml_use_internal_errors( false ),
			'Renderer must restore libxml internal-error mode to its prior state.'
		);

		// Best-effort restore of the original outer-test value.
		libxml_use_internal_errors( $before );
	}
```

- [ ] **Step 8.7.2: Run to verify it passes**

Run: `vendor/bin/phpunit --filter test_extract_restores_libxml_internal_error_mode tests/phpunit/PostContentRendererTest.php`

Expected: PASS.

### Task 8.8: Dedupe — attribute already present in visible text is dropped

- [ ] **Step 8.8.1: Add test**

```php
	public function test_extract_dedupes_attribute_already_in_visible_text(): void {
		$this->require_dom_extension();

		register_block_type(
			'flavor-agent-test/duplicated',
			[
				'render_callback' => static fn() => '<p>Visit https://kept.example.test/page for details.</p>'
					. '<a href="https://kept.example.test/page">link</a>',
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/duplicated /-->',
			[ 'postId' => 100 ]
		);

		$this->assertSame(
			1,
			substr_count( $out, 'https://kept.example.test/page' ),
			'href that already appears in visible text must not be duplicated under [Attribute references].'
		);
	}
```

- [ ] **Step 8.8.2: Run to verify it passes**

Run: `vendor/bin/phpunit --filter test_extract_dedupes_attribute_already_in_visible_text tests/phpunit/PostContentRendererTest.php`

Expected: PASS.

- [ ] **Step 8.8.3: Run the renderer test class**

Run: `vendor/bin/phpunit tests/phpunit/PostContentRendererTest.php`

Expected: PASS (21 tests).

- [ ] **Step 8.8.4: Commit (single commit covering Tasks 8.4–8.8)**

```bash
git add inc/Context/PostContentRenderer.php tests/phpunit/PostContentRendererTest.php
git commit -m "test: cover attribute walk caps, scheme allowlist, libxml restore, dedupe"
```

### Task 8.9: Attribute values cannot reshape the prompt structure

The bullet-list output is `"\n- {attr1}\n- {attr2}"`. An attribute containing literal `"\n\n## Some heading"` would visually break that structure when the prompt is rendered. The Task 8.3.1 implementation now normalizes control characters and collapses whitespace runs to a single space — this test locks in that behavior.

- [ ] **Step 8.9.1: Add test**

```php
	public function test_extract_normalizes_newlines_and_control_chars_in_attribute_values(): void {
		$this->require_dom_extension();

		register_block_type(
			'flavor-agent-test/sneaky-alt',
			[
				'render_callback' => static fn(): string => '<img src="https://example.test/x.png" alt="' . "Real description\n\n## Injected heading\nMore text" . '" />',
			]
		);

		$out = $this->renderer->extract(
			'<!-- wp:flavor-agent-test/sneaky-alt /-->',
			[ 'postId' => 100 ]
		);

		$ref_section = strstr( $out, '[Attribute references]' );
		$this->assertNotFalse( $ref_section );

		// The attribute value must appear collapsed onto a single bullet line.
		$this->assertStringContainsString(
			'- Real description ## Injected heading More text',
			$ref_section
		);
		// Critical: no bullet line begins with "## " or any newline-introduced fragment.
		$this->assertStringNotContainsString( "\n## ", $ref_section );
		$this->assertStringNotContainsString( "\n\n", $ref_section );
	}
```

- [ ] **Step 8.9.2: Run to verify it passes**

Run: `vendor/bin/phpunit --filter test_extract_normalizes_newlines_and_control_chars_in_attribute_values tests/phpunit/PostContentRendererTest.php`

Expected: PASS — the normalization in Task 8.3.1's `$append` closure handles this.

- [ ] **Step 8.9.3: Commit**

```bash
git add tests/phpunit/PostContentRendererTest.php
git commit -m "test: lock in newline/control-char normalization in attribute values"
```

---

## Task 9: Self-ref guards (`core/post-title`, `core/post-content`, `core/post-excerpt`)

**Why:** A post body that contains `core/post-title` could read the saved title (stale during an in-progress edit) or recurse via `core/post-content`. We substitute staged values from the editor at the top level. Empty staged values are intentional (the user cleared the field) and must propagate.

**Files:**
- Modify: `inc/Context/PostContentRenderer.php`
- Modify: `tests/phpunit/PostContentRendererTest.php`

### Task 9.1: Failing tests

- [ ] **Step 9.1.1: Append tests**

Add to `tests/phpunit/PostContentRendererTest.php`:

```php
	public function test_extract_substitutes_staged_title_for_self_ref_post_title(): void {
		$content = '<!-- wp:post-title /-->'
			. '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->';

		$out = $this->renderer->extract(
			$content,
			[ 'postId' => 100, 'stagedTitle' => 'Working title from editor' ]
		);

		$this->assertStringContainsString( 'Working title from editor', $out );
		$this->assertStringContainsString( 'Body.', $out );
	}

	public function test_extract_uses_empty_staged_title_when_user_cleared_field(): void {
		$content = '<!-- wp:post-title /-->'
			. '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->';

		$out = $this->renderer->extract(
			$content,
			[ 'postId' => 100, 'stagedTitle' => '' ]
		);

		// Self-ref produced empty string; no saved-title leak. Body still surfaces.
		$this->assertStringNotContainsString( 'Working draft', $out );
		$this->assertStringContainsString( 'Body.', $out );
	}

	public function test_extract_substitutes_staged_excerpt_for_self_ref_post_excerpt(): void {
		$content = '<!-- wp:post-excerpt /-->'
			. '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->';

		$out = $this->renderer->extract(
			$content,
			[ 'postId' => 100, 'stagedExcerpt' => 'Staged short summary.' ]
		);

		$this->assertStringContainsString( 'Staged short summary.', $out );
	}

	public function test_extract_skips_self_ref_post_content_block_to_avoid_recursion(): void {
		$content = '<!-- wp:post-content /-->'
			. '<!-- wp:paragraph --><p>Real body.</p><!-- /wp:paragraph -->';

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertStringContainsString( 'Real body.', $out );
		// post-content self-ref must not have rendered anything (no infinite loop, no leak).
		// Unique marker check: the only thing in $out should be 'Real body.' modulo whitespace.
		$this->assertSame( 'Real body.', trim( $out ) );
	}
```

- [ ] **Step 9.1.2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter "self_ref|staged" tests/phpunit/PostContentRendererTest.php`

Expected: at least the staged-title tests fail because `core/post-title` is being rendered via the registered block type registry, returning `''`. The post-content test will likely produce extra content.

### Task 9.2: Add self-ref interception in render loop

- [ ] **Step 9.2.1: Update the render loop in `render_with_globals`**

In `inc/Context/PostContentRenderer.php`, replace the foreach body inside `render_with_globals`:

```php
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				$block_name = (string) ( $block['blockName'] ?? '' );

				if ( 'core/post-content' === $block_name ) {
					continue;
				}

				if ( 'core/post-title' === $block_name ) {
					$staged = (string) ( $context['stagedTitle'] ?? '' );
					if ( '' !== $staged ) {
						$stripped_chunks[] = $staged;
					}
					continue;
				}

				if ( 'core/post-excerpt' === $block_name ) {
					$staged = (string) ( $context['stagedExcerpt'] ?? '' );
					if ( '' !== $staged ) {
						$stripped_chunks[] = $staged;
					}
					continue;
				}

				try {
					$rendered = (string) render_block( $block );
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Defense-in-depth log so a buggy third-party render_callback surfaces in PHP error logs without aborting the recommendation. No structured logger is wired in this surface yet.
					error_log(
						sprintf(
							'[flavor-agent] PostContentRenderer: render_block failed for %s — %s',
							'' !== $block_name ? $block_name : 'freeform',
							$e->getMessage()
						)
					);
					$marker            = sprintf(
						'[block render failed: %s]',
						'' !== $block_name ? $block_name : 'freeform'
					);
					$stripped_chunks[] = $marker;
					continue;
				}

				$rendered_html    .= $rendered;
				$stripped_chunks[] = $this->strip_block_html( $rendered );
			}
```

- [ ] **Step 9.2.2: Run new tests to verify they pass**

Run: `vendor/bin/phpunit --filter "self_ref|staged" tests/phpunit/PostContentRendererTest.php`

Expected: PASS (4 tests).

- [ ] **Step 9.2.3: Run the renderer test class to confirm no regression**

Run: `vendor/bin/phpunit tests/phpunit/PostContentRendererTest.php`

Expected: PASS (26 tests).

- [ ] **Step 9.2.4: Commit**

```bash
git add inc/Context/PostContentRenderer.php tests/phpunit/PostContentRendererTest.php
git commit -m "feat: substitute staged title/excerpt for self-ref blocks at top level"
```

---

## Task 10: Freeform mixed content

**Why:** Real posts contain freeform regions (text outside any block). The renderer must surface those.

**Files:**
- Modify: `tests/phpunit/PostContentRendererTest.php`

### Task 10.1: Failing test — freeform-only and mixed scenarios

- [ ] **Step 10.1.1: Append tests**

```php
	public function test_extract_renders_freeform_only_content(): void {
		$out = $this->renderer->extract( 'Plain text post body.', [ 'postId' => 100 ] );

		$this->assertStringContainsString( 'Plain text post body.', $out );
	}

	public function test_extract_renders_mixed_freeform_and_blocks(): void {
		$content = 'Intro text. '
			. '<!-- wp:paragraph --><p>Inside block.</p><!-- /wp:paragraph -->'
			. ' Middle text. '
			. '<!-- wp:paragraph --><p>Second block.</p><!-- /wp:paragraph -->'
			. ' Trailing text.';

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertStringContainsString( 'Intro text.', $out );
		$this->assertStringContainsString( 'Inside block.', $out );
		$this->assertStringContainsString( 'Middle text.', $out );
		$this->assertStringContainsString( 'Second block.', $out );
		$this->assertStringContainsString( 'Trailing text.', $out );
	}
```

- [ ] **Step 10.1.2: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "freeform" tests/phpunit/PostContentRendererTest.php`

Expected: PASS (2 tests). They should pass naturally — the freeform-aware parse_blocks stub from Task 1.3 emits freeform blocks for these regions, and `render_block` returns their `innerHTML` as-is, which `strip_block_html` then leaves intact (no tags to strip).

- [ ] **Step 10.1.3: Commit**

```bash
git add tests/phpunit/PostContentRendererTest.php
git commit -m "test: cover freeform-only and mixed freeform-plus-block content"
```

---

## Task 11: `\r` strip and empty-input fallback

**Why:** Authors copy-pasting from Word produce `\r\n` line endings. `parse_blocks` regex is `\s`-tolerant but production WP normalizes too. Truly empty input must take the fallback path.

**Files:**
- Modify: `tests/phpunit/PostContentRendererTest.php`

### Task 11.1: Add tests

- [ ] **Step 11.1.1: Append tests**

```php
	public function test_extract_strips_carriage_returns_before_parse(): void {
		$content = "<!-- wp:paragraph -->\r\n<p>One.</p>\r\n<!-- /wp:paragraph -->";

		$out = $this->renderer->extract( $content, [ 'postId' => 100 ] );

		$this->assertStringNotContainsString( "\r", $out );
		$this->assertStringContainsString( 'One.', $out );
	}

	public function test_extract_falls_back_for_empty_input(): void {
		$out = $this->renderer->extract( '', [ 'postId' => 100 ] );

		$this->assertSame( '', $out );
	}
```

- [ ] **Step 11.1.2: Run tests**

Run: `vendor/bin/phpunit --filter "carriage|empty_input" tests/phpunit/PostContentRendererTest.php`

Expected: PASS (2 tests). The `\r` strip happens at the top of `extract`; empty input takes the `[] === parse_blocks(...)` fallback path.

- [ ] **Step 11.1.3: Run the full renderer test class**

Run: `vendor/bin/phpunit tests/phpunit/PostContentRendererTest.php`

Expected: PASS (30 tests).

- [ ] **Step 11.1.4: Commit**

```bash
git add tests/phpunit/PostContentRendererTest.php
git commit -m "test: cover carriage-return strip and empty-input fallback"
```

---

## Task 12: `ServerCollector::for_post_content` facade

**Why:** All other context entry points go through `ServerCollector` for consistency. Add the method + lazy accessor mirroring the existing pattern.

**Files:**
- Modify: `inc/Context/ServerCollector.php`
- Modify: `tests/phpunit/PostContentRendererTest.php` (or create a small ServerCollector test snippet)

### Task 12.1: Failing test for the facade

- [ ] **Step 12.1.1: Add test**

Append to `tests/phpunit/PostContentRendererTest.php`:

```php
	public function test_server_collector_for_post_content_routes_to_renderer(): void {
		$out = \FlavorAgent\Context\ServerCollector::for_post_content(
			'<!-- wp:paragraph --><p>Routed.</p><!-- /wp:paragraph -->',
			[ 'postId' => 100 ]
		);

		$this->assertStringContainsString( 'Routed.', $out );
	}
```

- [ ] **Step 12.1.2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter test_server_collector_for_post_content_routes_to_renderer tests/phpunit/PostContentRendererTest.php`

Expected: ERROR — undefined method `for_post_content`.

### Task 12.2: Add the facade

- [ ] **Step 12.2.1: Add to `inc/Context/ServerCollector.php`**

In `inc/Context/ServerCollector.php`, add a new private static field next to the others:

```php
	private static ?PostContentRenderer $post_content_renderer = null;
```

Add the public method (place it next to other `for_*` methods, e.g., after `for_navigation`):

```php
	/**
	 * @param array<string, mixed> $context
	 */
	public static function for_post_content( string $post_content, array $context = [] ): string {
		return self::post_content_renderer()->extract( $post_content, $context );
	}
```

Add the private accessor (place it next to other lazy accessors, e.g., after `navigation_context_collector`):

```php
	private static function post_content_renderer(): PostContentRenderer {
		return self::$post_content_renderer ??= new PostContentRenderer();
	}
```

- [ ] **Step 12.2.2: Run to verify it passes**

Run: `vendor/bin/phpunit --filter test_server_collector_for_post_content_routes_to_renderer tests/phpunit/PostContentRendererTest.php`

Expected: PASS.

- [ ] **Step 12.2.3: Commit**

```bash
git add inc/Context/ServerCollector.php tests/phpunit/PostContentRendererTest.php
git commit -m "feat: add ServerCollector::for_post_content facade"
```

---

## Task 13: Add `postId` to the `recommend-content` ability schema

**Why:** Without the schema, `postId` is dropped from incoming requests, even if the JS client sends it. The schema is what makes the contract observable to tests, MCP, and the WP 7.0 client-abilities store.

**Files:**
- Modify: `inc/Abilities/Registration.php`
- Modify: `tests/phpunit/RegistrationTest.php`

### Task 13.1: Failing schema-presence test

- [ ] **Step 13.1.1: Locate the existing recommend-content registration test in `tests/phpunit/RegistrationTest.php`**

Run: `grep -n "recommend-content" tests/phpunit/RegistrationTest.php` to find the relevant test method (likely something like `test_register_abilities_registers_recommend_content` or contained within an iterating coverage test).

- [ ] **Step 13.1.2: Append a focused schema test**

Add at the end of `tests/phpunit/RegistrationTest.php`'s class body:

```php
	public function test_recommend_content_input_schema_includes_post_id(): void {
		\FlavorAgent\Abilities\Registration::register_category();
		\FlavorAgent\Abilities\Registration::register_abilities();

		$ability = \FlavorAgent\Tests\Support\WordPressTestState::$registered_abilities['flavor-agent/recommend-content'] ?? null;

		$this->assertIsArray( $ability );

		$post_context_schema = $ability['input_schema']['properties']['postContext']['properties'] ?? null;

		$this->assertIsArray( $post_context_schema );
		$this->assertArrayHasKey( 'postId', $post_context_schema );
		$this->assertSame( 'integer', $post_context_schema['postId']['type'] ?? null );
	}
```

- [ ] **Step 13.1.3: Run to verify it fails**

Run: `vendor/bin/phpunit --filter test_recommend_content_input_schema_includes_post_id tests/phpunit/RegistrationTest.php`

Expected: FAIL — `assertArrayHasKey 'postId'` fails because the schema lacks it.

### Task 13.2: Add `postId` to the schema

- [ ] **Step 13.2.1: Edit `inc/Abilities/Registration.php`**

In the `postContext` schema for `recommend-content` (around lines 172-193), add `postId` as the first property:

```php
							'postContext'  => self::open_object_schema(
								[
									'postId'          => [
										'type'        => 'integer',
										'description' => 'Numeric post ID being recommended on. Required for server-side block render; absent or 0 falls back to today\'s strip-tags path.',
									],
									'postType'        => [ 'type' => 'string' ],
									'title'           => [ 'type' => 'string' ],
									'excerpt'         => [ 'type' => 'string' ],
									'content'         => [ 'type' => 'string' ],
									'slug'            => [ 'type' => 'string' ],
									'status'          => [ 'type' => 'string' ],
									'audience'        => [ 'type' => 'string' ],
									'siteTitle'       => [ 'type' => 'string' ],
									'siteDescription' => [ 'type' => 'string' ],
									'categories'      => [
										'type'  => 'array',
										'items' => [ 'type' => 'string' ],
									],
									'tags'            => [
										'type'  => 'array',
										'items' => [ 'type' => 'string' ],
									],
								],
								'Optional post-editor context for drafting, editing, or critique.'
							),
```

- [ ] **Step 13.2.2: Run to verify it passes**

Run: `vendor/bin/phpunit --filter test_recommend_content_input_schema_includes_post_id tests/phpunit/RegistrationTest.php`

Expected: PASS.

- [ ] **Step 13.2.3: Run the full RegistrationTest to confirm no regression**

Run: `vendor/bin/phpunit tests/phpunit/RegistrationTest.php`

Expected: PASS.

- [ ] **Step 13.2.4: Commit**

```bash
git add inc/Abilities/Registration.php tests/phpunit/RegistrationTest.php
git commit -m "feat(abilities): add postId to recommend-content postContext input schema"
```

---

## Task 14: Per-post `current_user_can` authorization in `ContentAbilities::recommend_content`

**Why:** The outer `edit_posts` REST gate is too broad once we render saved post content — a user with `edit_posts` could request a render against another user's draft. The per-post check is the inner gate.

**Files:**
- Modify: `inc/Abilities/ContentAbilities.php`
- Modify: `tests/phpunit/ContentAbilitiesTest.php`

### Task 14.1: Failing tests

- [ ] **Step 14.1.1: Append tests**

Add to `tests/phpunit/ContentAbilitiesTest.php`:

```php
	public function test_recommend_content_allows_request_when_post_id_is_zero(): void {
		// No edit_post capability seeded — request should still proceed (no auth check).
		$this->stub_successful_content_response(
			[ 'mode' => 'draft', 'title' => 'OK', 'summary' => '', 'content' => 'X' ]
		);

		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'draft',
				'prompt'      => 'Sketch something.',
				'postContext' => [
					'title'   => 'New post',
					'content' => '',
				],
			]
		);

		$this->assertIsArray( $result );
	}

	public function test_recommend_content_allows_request_when_user_can_edit_post(): void {
		WordPressTestState::$capabilities['edit_post:42'] = true;
		$this->stub_successful_content_response(
			[ 'mode' => 'draft', 'title' => 'OK', 'summary' => '', 'content' => 'X' ]
		);

		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'draft',
				'prompt'      => 'Refine.',
				'postContext' => [
					'postId'  => 42,
					'title'   => 'Existing post',
					'content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				],
			]
		);

		$this->assertIsArray( $result );
	}

	public function test_recommend_content_returns_403_when_user_cannot_edit_post(): void {
		WordPressTestState::$capabilities['edit_post:42'] = false;

		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Refine.',
				'postContext' => [
					'postId'  => 42,
					'title'   => 'Stranger\'s post',
					'content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_context', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	public function test_recommend_content_returns_403_when_post_is_deleted(): void {
		// edit_post on a deleted/missing post returns false.
		WordPressTestState::$capabilities['edit_post:9999'] = false;

		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Refine.',
				'postContext' => [
					'postId'  => 9999,
					'title'   => 'Ghost post',
					'content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_context', $result->get_error_code() );
	}
```

(The test class needs `use FlavorAgent\Tests\Support\WordPressTestState;` — confirm it is present at the top, add it if not.)

- [ ] **Step 14.1.2: Run new tests to verify they fail**

Run: `vendor/bin/phpunit --filter "edit_post|user_can|deleted" tests/phpunit/ContentAbilitiesTest.php`

Expected: the two 403 tests fail (no auth check yet). The two allow tests may pass for the wrong reason (no check is being performed); they'll regress to the right reason after Task 14.2.

### Task 14.2: Add the per-post auth check

- [ ] **Step 14.2.1: Edit `inc/Abilities/ContentAbilities.php`**

Add the auth check at the top of `recommend_content`, just after the `$mode` line:

```php
	public static function recommend_content( mixed $input ): array|\WP_Error {
		$input = self::normalize_map( $input );
		$mode  = self::normalize_mode( $input['mode'] ?? 'draft' );

		$post_id_raw = self::normalize_map( $input['postContext'] ?? [] )['postId'] ?? 0;
		$post_id     = is_numeric( $post_id_raw ) ? (int) $post_id_raw : 0;

		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_forbidden_context',
				__( 'You cannot request content recommendations for that post.', 'flavor-agent' ),
				[ 'status' => 403 ]
			);
		}

		$post_context  = self::sanitize_post_context( $input['postContext'] ?? [] );
		$prompt        = self::sanitize_editorial_text( $input['prompt'] ?? '' );
		$voice_profile = self::sanitize_editorial_text( $input['voiceProfile'] ?? '' );

		// ... rest unchanged
```

- [ ] **Step 14.2.2: Run new tests to verify they pass**

Run: `vendor/bin/phpunit --filter "edit_post|user_can|deleted" tests/phpunit/ContentAbilitiesTest.php`

Expected: PASS (4 tests).

- [ ] **Step 14.2.3: Run the full `ContentAbilitiesTest`**

Run: `vendor/bin/phpunit tests/phpunit/ContentAbilitiesTest.php`

Expected: PASS — pre-existing tests do not seed `postId`, so they take the "no auth check" path and remain green.

- [ ] **Step 14.2.4: Commit**

```bash
git add inc/Abilities/ContentAbilities.php tests/phpunit/ContentAbilitiesTest.php
git commit -m "feat(content): require edit_post for the requested postId before rendering"
```

---

## Task 15: Wire the renderer into `ContentAbilities::recommend_content`

**Why:** This is where the new path actually goes live. Pull the raw (pre-sanitize) content for `parse_blocks`, route through the renderer with the staged title/excerpt + authorized postId, then drop the result into `$post_context['content']` for the existing prompt builder.

**Files:**
- Modify: `inc/Abilities/ContentAbilities.php`
- Modify: `tests/phpunit/ContentAbilitiesTest.php`

### Task 15.1: Failing test — dynamic block content reaches the prompt

- [ ] **Step 15.1.1: Append test**

Add to `tests/phpunit/ContentAbilitiesTest.php`:

```php
	public function test_recommend_content_renders_dynamic_block_into_existing_draft_section(): void {
		register_block_type(
			'flavor-agent-test/dynamic-text',
			[
				'render_callback' => static fn(): string => '<p>Rendered dynamic content sentinel.</p>',
			]
		);

		WordPressTestState::$capabilities['edit_post:77'] = true;
		WordPressTestState::$posts[77] = new \WP_Post(
			[
				'ID'         => 77,
				'post_title' => 'Working title',
				'post_type'  => 'post',
			]
		);

		$this->stub_successful_content_response(
			[ 'mode' => 'edit', 'title' => 'OK', 'summary' => '', 'content' => 'X' ]
		);

		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Tighten.',
				'postContext' => [
					'postId'  => 77,
					'title'   => 'Working title',
					'content' => '<!-- wp:flavor-agent-test/dynamic-text /-->',
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString(
			'Rendered dynamic content sentinel.',
			WordPressTestState::$last_ai_client_prompt['text'] ?? ''
		);
	}

	public function test_recommend_content_propagates_post_id_to_renderer_globals(): void {
		$captured_id = null;
		register_block_type(
			'flavor-agent-test/global-capture',
			[
				'render_callback' => static function () use ( &$captured_id ): string {
					$post        = $GLOBALS['post'] ?? null;
					$captured_id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : null;
					return 'captured';
				},
			]
		);

		WordPressTestState::$capabilities['edit_post:88'] = true;
		WordPressTestState::$posts[88] = new \WP_Post(
			[ 'ID' => 88, 'post_title' => 'X', 'post_type' => 'post' ]
		);

		$this->stub_successful_content_response(
			[ 'mode' => 'edit', 'title' => 'OK', 'summary' => '', 'content' => 'X' ]
		);

		ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Anything.',
				'postContext' => [
					'postId'  => 88,
					'title'   => 'X',
					'content' => '<!-- wp:flavor-agent-test/global-capture /-->',
				],
			]
		);

		$this->assertSame( 88, $captured_id );
	}
```

- [ ] **Step 15.1.2: Append the render-then-validate edge-case test**

Add to `tests/phpunit/ContentAbilitiesTest.php`:

```php
	public function test_recommend_content_returns_missing_existing_content_when_tag_only_content_renders_empty(): void {
		// Tag-only content has bytes (so the cheap early guard passes), but
		// strips to '' through both the renderer's fallback and any potential
		// rendered output. Edit/critique modes must still fail closed.
		// Fixture choice: <div></div> strips to '' under both production
		// wp_strip_all_tags AND the bootstrap's plain-strip_tags stub. <script>
		// content is preserved by strip_tags() (only production wp_strip_all_tags
		// removes script bodies), so a <script>...</script> fixture would not
		// reproduce the empty-render condition under PHPUnit.
		WordPressTestState::$capabilities['edit_post:42'] = true;
		WordPressTestState::$posts[42] = new \WP_Post(
			[ 'ID' => 42, 'post_title' => 'X', 'post_type' => 'post' ]
		);

		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'edit',
				'prompt'      => 'Tighten.',
				'postContext' => [
					'postId'  => 42,
					'title'   => 'X',
					'content' => '<div></div>',
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_existing_content', $result->get_error_code() );
	}

	public function test_recommend_content_returns_missing_content_instruction_when_draft_content_renders_empty(): void {
		// Draft mode with a comment-only fixture, no prompt, no title, no postId.
		// Today's behavior would 400 because sanitize_textarea_field strips the
		// comment and leaves nothing; the no-postId fallback invariant requires
		// the same outcome after this change.
		$result = ContentAbilities::recommend_content(
			[
				'mode'        => 'draft',
				'postContext' => [
					'content' => '<!-- random comment -->',
					// no prompt, no title, no postId
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_content_instruction', $result->get_error_code() );
	}
```

- [ ] **Step 15.1.3: Run to verify all four fail**

Run: `vendor/bin/phpunit --filter "dynamic_block|propagates_post_id|tag_only_content_renders_empty|draft_content_renders_empty" tests/phpunit/ContentAbilitiesTest.php`

Expected: FAIL — the dynamic-block test fails because content is still routed through `sanitize_textarea_field` and the rendered sentinel never reaches the prompt; the tag-only and draft-empty tests fail because the existing pre-Task-15 code does not run the post-render guards; the propagates-post-id test fails because no globals are set up yet.

### Task 15.2: Wire the renderer

- [ ] **Step 15.2.1: Edit `inc/Abilities/ContentAbilities.php`**

Add the import next to existing `use` statements:

```php
use FlavorAgent\Context\ServerCollector;
```

Replace the entire body of `recommend_content` (including the auth check from Task 14) with:

```php
	public static function recommend_content( mixed $input ): array|\WP_Error {
		$input              = self::normalize_map( $input );
		$mode               = self::normalize_mode( $input['mode'] ?? 'draft' );
		$post_context_input = self::normalize_map( $input['postContext'] ?? [] );

		$post_id_raw = $post_context_input['postId'] ?? 0;
		$post_id     = is_numeric( $post_id_raw ) ? (int) $post_id_raw : 0;

		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_forbidden_context',
				__( 'You cannot request content recommendations for that post.', 'flavor-agent' ),
				[ 'status' => 403 ]
			);
		}

		$raw_content   = is_string( $post_context_input['content'] ?? null )
			? (string) $post_context_input['content']
			: '';
		$post_context  = self::sanitize_post_context( $post_context_input );
		$prompt        = self::sanitize_editorial_text( $input['prompt'] ?? '' );
		$voice_profile = self::sanitize_editorial_text( $input['voiceProfile'] ?? '' );

		// Cheap early guard: nothing to work with. trim() handles whitespace-only
		// content; non-whitespace bytes (including dynamic block comments) keep us
		// in scope so the renderer can surface them.
		if (
			'' === $prompt
			&& '' === trim( $raw_content )
			&& '' === ( $post_context['title'] ?? '' )
		) {
			return new \WP_Error(
				'missing_content_instruction',
				'Content recommendations require a prompt, an existing draft, or a working title.',
				[ 'status' => 400 ]
			);
		}

		// Render once. For postId <= 0 or a missing post, the renderer's fallback
		// returns sanitize_textarea_field( raw ) — byte-for-byte today's behavior.
		// For an authorized postId > 0 with a valid post, blocks render and
		// attribute-borne text is harvested.
		$post_context['content'] = ServerCollector::for_post_content(
			$raw_content,
			[
				'postId'        => $post_id,
				'stagedTitle'   => $post_context['title'],
				'stagedExcerpt' => $post_context['excerpt'],
			]
		);

		// Post-render guard for ALL modes. The cheap early guard let bytes through
		// (e.g., a non-block comment, a tag-only string), but if those reduce to ''
		// after rendering AND there's no prompt or title, we have nothing to send.
		// This preserves today's no-postId fallback invariant: the request would have
		// failed before; it must still fail now.
		if (
			'' === $prompt
			&& '' === $post_context['content']
			&& '' === ( $post_context['title'] ?? '' )
		) {
			return new \WP_Error(
				'missing_content_instruction',
				'Content recommendations require a prompt, an existing draft, or a working title.',
				[ 'status' => 400 ]
			);
		}

		// Edit/critique need existing content to operate on, regardless of prompt.
		if ( in_array( $mode, [ 'edit', 'critique' ], true ) && '' === $post_context['content'] ) {
			return new \WP_Error(
				'missing_existing_content',
				'Edit and critique modes require existing postContext.content.',
				[ 'status' => 400 ]
			);
		}

		$context = [
			'mode'         => $mode,
			'postContext'  => $post_context,
			'voiceProfile' => $voice_profile,
		];

		$result = ChatClient::chat(
			WritingPrompt::build_system(),
			WritingPrompt::build_user( $context, $prompt ),
			ResponseSchema::get( 'content' ),
			'flavor_agent_content'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return WritingPrompt::parse_response( $result, $mode );
	}
```

**Why render-then-validate for `missing_existing_content`:** PHP's `strip_tags` strips HTML comments, and block delimiters are HTML comments. A post whose content is exclusively dynamic blocks therefore sanitizes to `''` and would previously trip `missing_existing_content` before the renderer ever ran. The fix is not "use raw bytes" — that lets whitespace-only / tag-only / non-block-comment-only content slip through. Instead, render first (the fallback path is byte-for-byte today's behavior for unauthorized callers) and validate against the actual content the LLM will see. This preserves today's "edit needs something to operate on" semantics across all three input shapes (plain text, dynamic-block-only, and mixed).

**Why `trim($raw_content)` for `missing_content_instruction`:** the early guard's job is "no work to do at all" — we should not even spin up the renderer for a request with no prompt, no title, and only whitespace bytes in the content field. `trim` is precise here: non-whitespace bytes (block comments, text, tags) all count as "the user gave us something."

**Why `normalize_map` once on `$post_context_input`:** the lifted variable normalizes at the top so both the auth check and the raw-content extraction work whether `postContext` arrived as an array or a stdClass. `sanitize_post_context` also calls `normalize_map` internally — passing it the already-normalized array is idempotent and harmless.

- [ ] **Step 15.2.2: Run new tests to verify they pass**

Run: `vendor/bin/phpunit --filter "dynamic_block|propagates_post_id" tests/phpunit/ContentAbilitiesTest.php`

Expected: PASS (2 tests).

- [ ] **Step 15.2.3: Run the full `ContentAbilitiesTest`**

Run: `vendor/bin/phpunit tests/phpunit/ContentAbilitiesTest.php`

Expected: PASS for all tests. Some pre-existing tests use plain (non-block) content fixtures — these now go through the renderer for `postId > 0` cases, but those tests do not seed `postId`, so they hit the renderer's postId=0 fallback path which is byte-for-byte today's behavior. If any pre-existing assertion fails:
1. Verify it doesn't seed `postId`. If it doesn't, a regression in the fallback is the cause.
2. If it does seed `postId`, wrap the fixture content in `<!-- wp:paragraph --><p>{text}</p><!-- /wp:paragraph -->` so the renderer surfaces the same string.

- [ ] **Step 15.2.4: Update existing `test_recommend_content_sanitizes_context_and_voice_profile_before_prompting` if needed**

This test uses non-block fixture content like `"Line one.\r\n\r\n<script>nope</script>Line two."`. It does not seed `postId`, so the fallback path runs and the existing assertions hold. Verify by running:

Run: `vendor/bin/phpunit --filter test_recommend_content_sanitizes_context_and_voice_profile_before_prompting tests/phpunit/ContentAbilitiesTest.php`

Expected: PASS. If it fails, the cause is non-fallback handling of postId=0; revisit Task 2.2.

- [ ] **Step 15.2.5: Commit**

```bash
git add inc/Abilities/ContentAbilities.php tests/phpunit/ContentAbilitiesTest.php
git commit -m "feat(content): route postContext.content through PostContentRenderer"
```

---

## Task 16: JS client — propagate `postId` from editor to ability dispatch

**Why:** The server schema accepts `postId`, but the JS `handleFetch` currently drops it. Until this changes, the editor flow never exercises the new path even after a real post is saved.

**Files:**
- Modify: `src/content/ContentRecommender.js`
- Modify: `src/content/__tests__/ContentRecommender.test.js`

### Task 16.1: Failing JS test

- [ ] **Step 16.1.1: Update the existing dispatch assertion**

In `src/content/__tests__/ContentRecommender.test.js`, find the test:

```js
test( 'renders on supported posts and sends current post context with requests', () => {
```

Update its `expect( mockFetchContentRecommendations ).toHaveBeenCalledWith(...)` block to include `postId`:

```js
expect( mockFetchContentRecommendations ).toHaveBeenCalledWith( {
    mode: 'draft',
    prompt: 'Tighten the opening.',
    postContext: {
        postId: 42,
        postType: 'post',
        title: 'Working draft',
        excerpt: '',
        content: 'Retail floors. WordPress themes.',
        slug: 'working-draft',
        status: 'draft',
    },
} );
```

- [ ] **Step 16.1.2: Run to verify it fails**

Run: `npm test -- --runInBand src/content/__tests__/ContentRecommender.test.js`

Expected: FAIL — the dispatched payload omits `postId`.

### Task 16.2: Add `postId` to the dispatched payload

- [ ] **Step 16.2.1: Edit `src/content/ContentRecommender.js`**

In `handleFetch` (around lines 192-205), include `postId`:

```js
const handleFetch = useCallback( () => {
    fetchContentRecommendations( {
        mode: contentMode,
        prompt,
        postContext: {
            postId: postContext.postId,
            postType: postContext.postType,
            title: postContext.title,
            excerpt: postContext.excerpt,
            content: postContext.content,
            slug: postContext.slug,
            status: postContext.status,
        },
    } );
}, [ contentMode, fetchContentRecommendations, postContext, prompt ] );
```

- [ ] **Step 16.2.2: Run JS test to verify it passes**

Run: `npm test -- --runInBand src/content/__tests__/ContentRecommender.test.js`

Expected: PASS.

- [ ] **Step 16.2.3: Run the full JS unit suite**

Run: `npm run test:unit -- --runInBand`

Expected: PASS (no regressions).

- [ ] **Step 16.2.4: Commit**

```bash
git add src/content/ContentRecommender.js src/content/__tests__/ContentRecommender.test.js
git commit -m "feat(content): include postId in dispatched recommendation payload"
```

---

## Task 17: Targeted PHP/JS suite re-run

**Why:** Cross-surface validation gates in `docs/reference/cross-surface-validation-gates.md` require running the nearest targeted suites before claiming completion.

### Task 17.1: PHP suite

- [ ] **Step 17.1.1: Run the full PHP suite**

Run: `composer test:php 2>&1 | tail -20`

Expected: PASS for everything that previously passed plus all new tests added in Tasks 1–15.

If failures appear in tests outside this plan's scope, investigate whether the parse_blocks stub change (Task 1.3) altered behavior in a downstream test. Consult Task 1.5 — the audit step should have caught these earlier; if it did not, fix the test fixture now and add a note to `tests/phpunit/<file>.php` explaining the fixture change.

### Task 17.2: JS suite

- [ ] **Step 17.2.1: Run the full JS unit suite**

Run: `npm run test:unit -- --runInBand`

Expected: PASS for everything plus the new `postId`-propagation assertion.

### Task 17.3: Commit nothing — this is a verification-only step

If both suites pass, no commit. If a fix was required, commit it under a descriptive message and re-run.

---

## Task 18: Verify pipeline

**Why:** `node scripts/verify.js --skip-e2e` is the project's aggregate validation. It runs build + lint:js + plugin-check + unit + lint:php + test:php in one shot and writes `output/verify/summary.json`. This is the final gate.

### Task 18.1: Run the verify pipeline (E2E skipped)

- [ ] **Step 18.1.1: Run**

Run: `node scripts/verify.js --skip-e2e 2>&1 | tail -30`

Expected: final line of output contains `VERIFY_RESULT={"status":"pass",...}`.

- [ ] **Step 18.1.2: Inspect the structured summary**

Run: `cat output/verify/summary.json | python3 -m json.tool 2>&1 | head -60`

(Or use `jq` if installed: `jq . output/verify/summary.json`.)

Expected: top-level `"status": "pass"`. If `"status": "incomplete"` appears, check which step's required tool was unavailable (likely `lint-plugin` requiring WP-CLI) and re-run with `--skip=lint-plugin` if so:

Run: `node scripts/verify.js --skip-e2e --skip=lint-plugin`

Expected: `"status": "pass"`.

### Task 18.2: Final commit (if any incidental fixes were made during verify)

If the verify run surfaced lint warnings (e.g., WPCS) on files this plan touched, fix them and commit:

```bash
git add inc/Context/PostContentRenderer.php inc/Context/ServerCollector.php inc/Abilities/ContentAbilities.php inc/Abilities/Registration.php tests/phpunit/PostContentRendererTest.php tests/phpunit/ContentAbilitiesTest.php tests/phpunit/RegistrationTest.php tests/phpunit/bootstrap.php src/content/ContentRecommender.js src/content/__tests__/ContentRecommender.test.js
git commit -m "chore: address verify-pipeline lint findings"
```

If no fixes were needed, this commit is unnecessary — skip.

---

## Self-review checklist

Run through these before declaring the plan complete:

1. **Spec coverage:**
   - Goal (render blocks server-side + harvest attributes): Tasks 2, 3, 4, 6, 7, 8, 10
   - postId-gated rendering: Task 2.2 + Task 14
   - Self-ref guards: Task 9
   - Authorization: Task 14
   - Wiring (3 files: JS client, schema, ContentAbilities): Tasks 13, 15, 16
   - Bootstrap stubs: Task 1
   - All 18 spec implementation-ordering steps: Tasks 1–18 are 1:1 with spec steps 1–18 (this plan adds extra TDD sub-steps within each).

2. **No placeholders:** Every code block is concrete. The only "if needed" branches are explicitly conditional on a prior test run failing — those branches give specific fix recipes.

3. **Type/name consistency:**
   - `PostContentRenderer::extract( string $post_content, array $context = [] ): string` — used identically in Tasks 2.2, 4.2, 8.3, 9.2, 12.2.
   - `ServerCollector::for_post_content( string $post_content, array $context = [] ): string` — Task 12.
   - `register_block_type` stub (Task 1.2) delegates to `WP_Block_Type_Registry::get_instance()->register()`; `render_block` (Task 1.4) reads `render_callback` from the same registry. No parallel block-type map in `WordPressTestState`.
   - `WordPressTestState::$current_post` — added in Task 1.1, set/cleared in Task 1.2 stubs.
   - `current_user_can('edit_post', $id)` — Task 14 production code; tests seed `WordPressTestState::$capabilities['edit_post:42']` matching the existing `current_user_can` stub at bootstrap.php:1605.
   - `setup_postdata` / `wp_reset_postdata` — defined in Task 1.2, used in Task 4.2's `render_with_globals`.
   - Self-ref interception guards (`core/post-title`, `core/post-content`, `core/post-excerpt`) — defined in Task 9.2; tests in Task 9.1 use those exact block names.

4. **Cross-surface validation gates:** `docs/reference/cross-surface-validation-gates.md` requires (a) targeted suites — Task 17, (b) `node scripts/verify.js --skip-e2e` — Task 18, (c) `npm run check:docs` if contracts changed — N/A here because the spec does not introduce a new public contract beyond an additive optional schema field, and (d) Playwright when applicable. The change is server-side only and the prompt input shape is unchanged (per spec "Browser smoke" section), so Playwright is not required for this layer.

5. **Risks the engineer should keep in mind during implementation:**
   - The parse_blocks rewrite in Task 1.3 has the largest blast radius. If Task 1.5 audit fails, do not paper over by adding `null` checks in production code; fix the test fixture instead.
   - Per-block `try/catch` in Task 5 catches `Throwable` — `wp_die` and `exit` cannot be caught and remain documented limitations (per spec).
   - The DOMDocument walk in Task 8.3 declares no extra Composer constraint; the `class_exists` guard and the `loaded === false` branch are the only protection. Do not assume `ext-dom` on hosts.
   - Per-post auth in Task 14 must run **before** any work that touches the renderer — otherwise an unauthorized request still triggers `parse_blocks`/`render_block` execution. Place the check immediately after `$mode` is normalized.
