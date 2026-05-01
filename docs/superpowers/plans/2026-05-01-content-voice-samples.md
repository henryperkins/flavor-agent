# Layer 2 Content Voice Samples Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a bounded same-author "site voice samples" section to the content recommender's user prompt, gated by post-type allowlist, rendered through Layer 1's `PostContentRenderer`, and assembled via `PromptBudget` so voice samples is the only droppable section.

**Architecture:** New `PostVoiceSampleCollector` under `inc/Context/`, exposed via `ServerCollector`. `PromptBudget` gains a `$required` flag so existing prompt sections never drop. `WritingPrompt::build_user` is refactored onto `PromptBudget` with the surface-keyed budget filter. Frontend gate on the content panel loosens to "supported post type only" so brand-new posts can benefit. No new REST routes, abilities, or capability flags.

**Tech Stack:** PHP 8.0+ (PSR-4 `FlavorAgent\`), PHPUnit 9.6, WordPress core APIs (`get_posts`, `current_user_can`, `mysql2date`), Jest + React Testing harness for `src/content/`, `@wordpress/scripts` build.

**Spec:** `docs/superpowers/specs/2026-05-01-content-voice-samples-design.md`

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `inc/Context/PostVoiceSampleCollector.php` | Create | Same-author publish-only candidate selection, password + read_post filtering, render via `PostContentRenderer`, truncate to ~1500 chars, return sample dicts. |
| `inc/Context/ServerCollector.php` | Modify | Add `for_post_voice_samples()` static facade and lazy-singleton `post_voice_sample_collector()`. |
| `inc/LLM/PromptBudget.php` | Modify | Add `bool $required = false` parameter to `add_section`; replace `get_lowest_priority_index` with `get_lowest_priority_removable_index`; add `required` to `get_diagnostics` payload. |
| `inc/LLM/WritingPrompt.php` | Modify | Refactor `build_user` to assemble via `PromptBudget` with the priority/required table from the spec; add `## Site voice samples` section when `$context['voiceSamples']` is non-empty. |
| `inc/Abilities/ContentAbilities.php` | Modify | Resolve canonical `$post_type` from `get_post()` for saved posts, call `ServerCollector::for_post_voice_samples()`, pass result via `voiceSamples` key in `$context`. |
| `src/content/ContentRecommender.js` | Modify | Loosen `hasSupportedPost` gate from `Boolean(postId) && SUPPORTED.has(postType)` to `SUPPORTED.has(postType)` only. |
| `tests/phpunit/PostVoiceSampleCollectorTest.php` | Create | Tests for allowlist, author resolution, get_posts wiring, password gate, read_post gate, render-throw catch, truncation, dict shape. |
| `tests/phpunit/PromptBudgetTest.php` | Modify | Tests for `$required` parameter behavior. |
| `tests/phpunit/ContentAbilitiesTest.php` | Modify | Tests asserting `## Site voice samples` appears when samples exist, omitted otherwise, post-type allowlist enforcement. |
| `tests/phpunit/bootstrap.php` | Modify | Extend `get_posts` stub (`author`, `post__not_in`, `has_password`); add `mysql2date` shim; extend `WP_Post` shim with `post_password`, `post_date_gmt`, `post_date`, `post_author`. |
| `src/content/__tests__/ContentRecommender.test.js` | Modify | Tests asserting panel renders for `postId === 0` + supported post type; existing unsupported-entity test continues to pass. |
| `docs/features/content-recommendations.md` | Modify | Document the new `## Site voice samples` section, empty-state behavior, and the `flavor_agent_prompt_budget_max_tokens` filter scope. |

---

## Task 1: Extend PromptBudget with required-section flag

**Files:**
- Modify: `inc/LLM/PromptBudget.php`
- Test: `tests/phpunit/PromptBudgetTest.php`

The current `assemble()` drops the lowest-priority section repeatedly until the prompt fits or one section remains. Layer 2 needs an explicit "never drop this" marker so a long current draft can't be silently stripped.

- [ ] **Step 1: Write failing tests for required-section behavior**

Append to `tests/phpunit/PromptBudgetTest.php` before the closing brace:

```php
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

public function test_optional_sections_drop_in_priority_order_when_required_present(): void {
    $budget = new PromptBudget( 2000 );
    $budget->add_section( 'required', 'required content', 100, true );
    $budget->add_section( 'low', str_repeat( 'l', 5000 ), 10, false );
    $budget->add_section( 'medium', str_repeat( 'm', 5000 ), 50, false );

    $result = $budget->assemble();

    $this->assertStringContainsString( 'required content', $result );
    $this->assertStringNotContainsString( str_repeat( 'l', 100 ), $result );
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
```

- [ ] **Step 2: Run tests to verify they fail**

```
vendor/bin/phpunit --filter PromptBudgetTest
```

Expected: 4 failures — `add_section` does not accept a 4th parameter; `get_diagnostics` does not return `required`.

- [ ] **Step 3: Update PromptBudget**

Replace the `add_section`, `assemble`, `get_diagnostics`, and `get_lowest_priority_index` methods in `inc/LLM/PromptBudget.php`. Update the `$sections` doc-block.

The `$sections` field doc-block:

```php
/**
 * @var array<int, array{key: string, content: string, priority: int, required: bool}>
 */
private array $sections = [];
```

`add_section`:

```php
public function add_section(
    string $key,
    string $content,
    int $priority = 50,
    bool $required = false
): self {
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
```

`assemble`:

```php
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

    return self::join_sections( $included );
}
```

Replace `get_lowest_priority_index` with:

```php
/**
 * @param array<int, array{key: string, content: string, priority: int, required: bool}> $sections
 */
private static function get_lowest_priority_removable_index( array $sections ): ?int {
    $lowest_index    = null;
    $lowest_priority = PHP_INT_MAX;

    foreach ( $sections as $index => $section ) {
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
```

`get_diagnostics`:

```php
public function get_diagnostics(): array {
    $section_diagnostics = [];
    foreach ( $this->sections as $section ) {
        $section_diagnostics[] = [
            'key'      => $section['key'],
            'tokens'   => self::estimate_tokens( $section['content'] ),
            'priority' => $section['priority'],
            'required' => (bool) ( $section['required'] ?? false ),
        ];
    }

    return [
        'max_tokens'     => $this->max_tokens,
        'current_tokens' => $this->get_current_tokens(),
        'within_budget'  => $this->is_within_budget(),
        'sections'       => $section_diagnostics,
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

```
vendor/bin/phpunit --filter PromptBudgetTest
```

Expected: All PromptBudgetTest tests pass (existing + 4 new).

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/PromptBudget.php tests/phpunit/PromptBudgetTest.php
git commit -m "feat(prompt-budget): add required flag to never-drop sections

Adds a fourth bool $required = false parameter to add_section.
Required sections are skipped when picking the next section to
remove, so assemble() can return an over-budget string rather
than dropping content the caller marked as load-bearing.
Existing call sites omit the parameter and retain today's
behavior. get_diagnostics now includes the required flag."
```

---

## Task 2: Extend bootstrap test stubs for Layer 2

**Files:**
- Modify: `tests/phpunit/bootstrap.php`

The existing `get_posts` stub honors `post_type`, `post_status`, `s`, `orderby`, `order`, `offset`, `posts_per_page`. Layer 2 needs `author`, `post__not_in`, `has_password`. Also need a `mysql2date` shim and three new `WP_Post` properties.

- [ ] **Step 1: Locate the WP_Post shim**

Open `tests/phpunit/bootstrap.php` and find the `if ( ! class_exists( 'WP_Post' ) )` block (was added in Layer 1). Find the `get_posts` stub (around line 2006) and the `WordPressTestState` class (around line 100).

- [ ] **Step 2: Extend the WP_Post shim**

Replace the existing `WP_Post` class definition with:

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

        public string $post_password = '';

        public string $post_date = '';

        public string $post_date_gmt = '';

        /**
         * @param array<string, mixed> $fields
         */
        public function __construct( array $fields = [] ) {
            foreach ( $fields as $key => $value ) {
                if ( property_exists( $this, $key ) ) {
                    $this->{$key} = $value;
                }
            }
        }
    }
}
```

- [ ] **Step 3: Add the mysql2date shim**

Below the WP_Post block, add:

```php
if ( ! function_exists( 'mysql2date' ) ) {
    function mysql2date( string $format, string $date, bool $translate = true ): string {
        unset( $translate );

        if ( '' === $date ) {
            return '';
        }

        $timestamp = strtotime( $date );
        if ( false === $timestamp ) {
            return '';
        }

        return date( $format, $timestamp );
    }
}
```

- [ ] **Step 4: Extend get_posts stub with author, post__not_in, has_password**

In `tests/phpunit/bootstrap.php`, replace the `get_posts` function body. Find the existing `if ( '' !== $post_type ) { ... }` block and the line that reads `$posts = array_values(...)` filtering by `post_status`. Right after the `post_status` filter and before the search filter (around line 2040), insert:

```php
if ( isset( $args['author'] ) ) {
    $author_id = (int) $args['author'];
    $posts     = array_values(
        array_filter(
            $posts,
            static fn ( object $post ): bool => (int) ( $post->post_author ?? 0 ) === $author_id
        )
    );
}

if ( ! empty( $args['post__not_in'] ) && is_array( $args['post__not_in'] ) ) {
    $excluded = array_map( 'intval', $args['post__not_in'] );
    $posts    = array_values(
        array_filter(
            $posts,
            static fn ( object $post ) use ( $excluded ): bool => ! in_array( (int) ( $post->ID ?? 0 ), $excluded, true )
        )
    );
}

if ( isset( $args['has_password'] ) && false === $args['has_password'] ) {
    $posts = array_values(
        array_filter(
            $posts,
            static fn ( object $post ): bool => '' === (string) ( $post->post_password ?? '' )
        )
    );
}
```

- [ ] **Step 5: Run all PHPUnit tests to confirm no regression**

```
vendor/bin/phpunit
```

Expected: All existing tests pass. The new shims are unused so far, so they cannot break anything.

- [ ] **Step 6: Commit**

```bash
git add tests/phpunit/bootstrap.php
git commit -m "test(bootstrap): extend stubs for Layer 2 voice samples

- WP_Post gains post_password, post_date, post_date_gmt
  (post_author already existed).
- get_posts honors author, post__not_in, has_password=false.
- mysql2date shim returns date() against strtotime() so the
  collector can format Published: lines."
```

---

## Task 3: PostVoiceSampleCollector skeleton with allowlist guard

**Files:**
- Create: `inc/Context/PostVoiceSampleCollector.php`
- Create: `tests/phpunit/PostVoiceSampleCollectorTest.php`

Lay down the class, the `SUPPORTED_POST_TYPES` constant, and the early-exit path. No author resolution or query yet.

- [ ] **Step 1: Write the failing tests**

Create `tests/phpunit/PostVoiceSampleCollectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\PostContentRenderer;
use FlavorAgent\Context\PostVoiceSampleCollector;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PostVoiceSampleCollectorTest extends TestCase {

    private PostVoiceSampleCollector $collector;

    protected function setUp(): void {
        parent::setUp();

        WordPressTestState::reset();
        $this->collector = new PostVoiceSampleCollector( new PostContentRenderer() );
    }

    public function test_returns_empty_for_unsupported_post_type(): void {
        $result = $this->collector->for_post( 0, 'wp_template' );

        $this->assertSame( [], $result );
        $this->assertSame( [], WordPressTestState::$get_posts_calls );
    }

    public function test_returns_empty_for_unknown_post_type(): void {
        $result = $this->collector->for_post( 0, 'completely-made-up' );

        $this->assertSame( [], $result );
        $this->assertSame( [], WordPressTestState::$get_posts_calls );
    }

    public function test_supported_post_types_include_post_and_page(): void {
        $this->assertContains( 'post', PostVoiceSampleCollector::SUPPORTED_POST_TYPES );
        $this->assertContains( 'page', PostVoiceSampleCollector::SUPPORTED_POST_TYPES );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```
vendor/bin/phpunit --filter PostVoiceSampleCollectorTest
```

Expected: All three tests fail — class does not exist.

- [ ] **Step 3: Create the collector skeleton**

Create `inc/Context/PostVoiceSampleCollector.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class PostVoiceSampleCollector {

    public const SUPPORTED_POST_TYPES = [ 'post', 'page' ];

    public function __construct(
        private PostContentRenderer $post_content_renderer
    ) {
    }

    /**
     * @return array<int, array{title: string, published: string, opening: string}>
     */
    public function for_post( int $post_id, string $post_type ): array {
        if ( ! in_array( $post_type, self::SUPPORTED_POST_TYPES, true ) ) {
            return [];
        }

        return [];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```
vendor/bin/phpunit --filter PostVoiceSampleCollectorTest
```

Expected: All three tests pass.

- [ ] **Step 5: Commit**

```bash
git add inc/Context/PostVoiceSampleCollector.php tests/phpunit/PostVoiceSampleCollectorTest.php
git commit -m "feat(content-context): scaffold PostVoiceSampleCollector

Adds the class with SUPPORTED_POST_TYPES = ['post', 'page'] and
an early-return guard for unsupported types. for_post returns
[] for now; subsequent commits add author resolution, candidate
selection, and rendering."
```

---

## Task 4: Author resolution (postId-positive and postId-zero paths)

**Files:**
- Modify: `inc/Context/PostVoiceSampleCollector.php`
- Modify: `tests/phpunit/PostVoiceSampleCollectorTest.php`

`postId > 0` resolves to `get_post($postId)->post_author`; `postId <= 0` resolves to `get_current_user_id()`. Author ID `0` short-circuits to `[]`.

- [ ] **Step 1: Write failing tests**

Append to `PostVoiceSampleCollectorTest.php` before the closing brace:

```php
public function test_returns_empty_when_current_user_is_zero_and_post_id_is_zero(): void {
    WordPressTestState::$current_user_id = 0;

    $result = $this->collector->for_post( 0, 'post' );

    $this->assertSame( [], $result );
    $this->assertSame( [], WordPressTestState::$get_posts_calls );
}

public function test_returns_empty_when_post_author_is_zero(): void {
    WordPressTestState::$posts[42] = new \WP_Post(
        [
            'ID'          => 42,
            'post_type'   => 'post',
            'post_author' => 0,
        ]
    );

    $result = $this->collector->for_post( 42, 'post' );

    $this->assertSame( [], $result );
    $this->assertSame( [], WordPressTestState::$get_posts_calls );
}

public function test_resolves_author_from_post_for_positive_post_id(): void {
    WordPressTestState::$posts[100] = new \WP_Post(
        [
            'ID'          => 100,
            'post_type'   => 'post',
            'post_author' => 7,
        ]
    );

    $this->collector->for_post( 100, 'post' );

    $this->assertCount( 1, WordPressTestState::$get_posts_calls );
    $this->assertSame( 7, WordPressTestState::$get_posts_calls[0]['author'] ?? null );
}

public function test_resolves_author_from_current_user_for_unsaved_post(): void {
    WordPressTestState::$current_user_id = 11;

    $this->collector->for_post( 0, 'page' );

    $this->assertCount( 1, WordPressTestState::$get_posts_calls );
    $this->assertSame( 11, WordPressTestState::$get_posts_calls[0]['author'] ?? null );
}
```

Verify `WordPressTestState` already has `$current_user_id` (used by Layer 1 tests). If not, add it during Step 3 below.

- [ ] **Step 2: Run tests to verify they fail**

```
vendor/bin/phpunit --filter PostVoiceSampleCollectorTest
```

Expected: 4 failures — `for_post` does not yet call `get_posts`.

- [ ] **Step 3: Implement author resolution and a stub get_posts call**

Replace `for_post` in `inc/Context/PostVoiceSampleCollector.php`:

```php
public function for_post( int $post_id, string $post_type ): array {
    if ( ! in_array( $post_type, self::SUPPORTED_POST_TYPES, true ) ) {
        return [];
    }

    $author_id = $this->resolve_author_id( $post_id );
    if ( $author_id <= 0 ) {
        return [];
    }

    $candidates = get_posts(
        [
            'post_type'              => $post_type,
            'author'                 => $author_id,
            'post_status'            => 'publish',
            'posts_per_page'         => 3,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'post__not_in'           => $post_id > 0 ? [ $post_id ] : [],
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'has_password'           => false,
            'suppress_filters'       => false,
        ]
    );

    return [];
}

private function resolve_author_id( int $post_id ): int {
    if ( $post_id > 0 ) {
        $post = get_post( $post_id );

        return $post instanceof \WP_Post ? (int) $post->post_author : 0;
    }

    return (int) get_current_user_id();
}
```

If `WordPressTestState::$current_user_id` does not yet exist in `tests/phpunit/bootstrap.php`, add it next to the other static properties (under the `$capabilities` declaration) and add `self::$current_user_id = 0;` to the `reset()` method. Then ensure the `get_current_user_id` shim in the global namespace returns `WordPressTestState::$current_user_id`.

- [ ] **Step 4: Run tests to verify they pass**

```
vendor/bin/phpunit --filter PostVoiceSampleCollectorTest
```

Expected: All 7 tests pass.

- [ ] **Step 5: Commit**

```bash
git add inc/Context/PostVoiceSampleCollector.php tests/phpunit/PostVoiceSampleCollectorTest.php tests/phpunit/bootstrap.php
git commit -m "feat(content-context): resolve author and call get_posts

Author resolution: post_author for positive post IDs, current
user for unsaved posts. Author 0 short-circuits to []. Candidates
fetched via get_posts with publish-only, same-author, no-password,
and current-post-excluded filters. Sample assembly arrives in
the next commit."
```

---

## Task 5: Password gate and read_post gate

**Files:**
- Modify: `inc/Context/PostVoiceSampleCollector.php`
- Modify: `tests/phpunit/PostVoiceSampleCollectorTest.php`

`get_posts` already filters at the SQL layer via `has_password=false`, but Layer 2 keeps a defense-in-depth field check. `current_user_can( 'read_post', $id )` filters per candidate.

- [ ] **Step 1: Write failing tests**

Append to `PostVoiceSampleCollectorTest.php`:

```php
public function test_skips_password_protected_candidates_in_field_check(): void {
    WordPressTestState::$current_user_id = 5;
    WordPressTestState::$posts[201]      = new \WP_Post(
        [
            'ID'            => 201,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'Open',
            'post_content'  => '<!-- wp:paragraph --><p>Open body.</p><!-- /wp:paragraph -->',
            'post_password' => '',
            'post_date_gmt' => '2026-04-15 09:00:00',
        ]
    );
    WordPressTestState::$posts[202] = new \WP_Post(
        [
            'ID'            => 202,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'Locked',
            'post_content'  => '<!-- wp:paragraph --><p>Locked body.</p><!-- /wp:paragraph -->',
            'post_password' => 'shibboleth',
            'post_date_gmt' => '2026-04-16 09:00:00',
        ]
    );

    WordPressTestState::$capabilities['read_post:201'] = true;
    WordPressTestState::$capabilities['read_post:202'] = true;

    $samples = $this->collector->for_post( 0, 'post' );

    $titles = array_column( $samples, 'title' );
    $this->assertContains( 'Open', $titles );
    $this->assertNotContains( 'Locked', $titles );
}

public function test_skips_candidates_failing_read_post_capability(): void {
    WordPressTestState::$current_user_id = 5;
    WordPressTestState::$posts[210]      = new \WP_Post(
        [
            'ID'            => 210,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'Allowed',
            'post_content'  => '<!-- wp:paragraph --><p>Allowed body.</p><!-- /wp:paragraph -->',
            'post_date_gmt' => '2026-04-15 09:00:00',
        ]
    );
    WordPressTestState::$posts[211] = new \WP_Post(
        [
            'ID'            => 211,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'Forbidden',
            'post_content'  => '<!-- wp:paragraph --><p>Forbidden body.</p><!-- /wp:paragraph -->',
            'post_date_gmt' => '2026-04-16 09:00:00',
        ]
    );

    WordPressTestState::$capabilities['read_post:210'] = true;
    WordPressTestState::$capabilities['read_post:211'] = false;

    $samples = $this->collector->for_post( 0, 'post' );

    $titles = array_column( $samples, 'title' );
    $this->assertContains( 'Allowed', $titles );
    $this->assertNotContains( 'Forbidden', $titles );
}

public function test_does_not_backfill_when_candidates_filtered_out(): void {
    WordPressTestState::$current_user_id = 5;
    foreach ( [ 220, 221, 222, 223 ] as $offset => $id ) {
        WordPressTestState::$posts[ $id ] = new \WP_Post(
            [
                'ID'            => $id,
                'post_type'     => 'post',
                'post_status'   => 'publish',
                'post_author'   => 5,
                'post_title'    => "Post {$id}",
                'post_content'  => "<!-- wp:paragraph --><p>Body {$id}.</p><!-- /wp:paragraph -->",
                'post_date_gmt' => sprintf( '2026-04-%02d 09:00:00', 10 + $offset ),
            ]
        );
        WordPressTestState::$capabilities[ "read_post:{$id}" ] = true;
    }
    WordPressTestState::$capabilities['read_post:222'] = false;

    $samples = $this->collector->for_post( 0, 'post' );

    $this->assertCount( 2, $samples );
}
```

- [ ] **Step 2: Run tests to verify they fail**

```
vendor/bin/phpunit --filter PostVoiceSampleCollectorTest
```

Expected: 3 failures — collector still returns `[]` after the get_posts call.

- [ ] **Step 3: Implement candidate iteration and gates**

Replace the body of `for_post` after the `get_posts(...)` call. The full method becomes:

```php
public function for_post( int $post_id, string $post_type ): array {
    if ( ! in_array( $post_type, self::SUPPORTED_POST_TYPES, true ) ) {
        return [];
    }

    $author_id = $this->resolve_author_id( $post_id );
    if ( $author_id <= 0 ) {
        return [];
    }

    $candidates = get_posts(
        [
            'post_type'              => $post_type,
            'author'                 => $author_id,
            'post_status'            => 'publish',
            'posts_per_page'         => 3,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'post__not_in'           => $post_id > 0 ? [ $post_id ] : [],
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'has_password'           => false,
            'suppress_filters'       => false,
        ]
    );

    $samples = [];
    foreach ( $candidates as $candidate ) {
        if ( ! $candidate instanceof \WP_Post ) {
            continue;
        }

        if ( '' !== (string) ( $candidate->post_password ?? '' ) ) {
            continue;
        }

        if ( ! current_user_can( 'read_post', (int) $candidate->ID ) ) {
            continue;
        }

        $samples[] = [
            'title'     => sanitize_text_field( (string) ( $candidate->post_title ?? '' ) ),
            'published' => '',
            'opening'   => '',
        ];
    }

    return $samples;
}
```

The `published` and `opening` are empty placeholders for now. Tasks 6-7 fill them in.

- [ ] **Step 4: Run tests to verify they pass**

```
vendor/bin/phpunit --filter PostVoiceSampleCollectorTest
```

Expected: All 10 tests pass.

- [ ] **Step 5: Commit**

```bash
git add inc/Context/PostVoiceSampleCollector.php tests/phpunit/PostVoiceSampleCollectorTest.php
git commit -m "feat(content-context): apply password and read_post gates

Per-candidate filtering: skip non-empty post_password (defense
in depth on top of has_password=false in get_posts), then skip
when current_user_can('read_post', $id) is false. No backfill —
filtered candidates are not replaced. Sample dicts get title
populated; published and opening land in subsequent commits."
```

---

## Task 6: Render candidate via PostContentRenderer, detect failures

**Files:**
- Modify: `inc/Context/PostVoiceSampleCollector.php`
- Modify: `tests/phpunit/PostVoiceSampleCollectorTest.php`

Each candidate goes through `PostContentRenderer::extract`. Two failure modes need handling, plus the `[Attribute references]` tail strip:

1. **Catastrophic failure** (e.g., `parse_blocks` corruption, OOM) — `extract` throws. Outer `try { ... } catch ( \Throwable $e )` logs and drops the candidate.
2. **Per-block render failure** — Layer 1's `PostContentRenderer::extract` catches per-block exceptions internally (`inc/Context/PostContentRenderer.php:92-108`) and inserts a `[block render failed: <name>]` marker into the rendered output. For voice samples, that marker would pollute the prompt, so Layer 2 detects the marker and drops the whole sample. Layer 1's behavior is unchanged because Layer 1's caller (current-post rendering) wants the partial result; Layer 2 doesn't.

The strip of `[Attribute references]` runs regardless.

- [ ] **Step 1: Write failing tests**

Append to `PostVoiceSampleCollectorTest.php`:

```php
public function test_strips_attribute_references_tail_from_rendered_output(): void {
    WordPressTestState::$current_user_id = 5;
    WordPressTestState::$posts[300]      = new \WP_Post(
        [
            'ID'            => 300,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'With image',
            'post_content'  => '<!-- wp:paragraph --><p>Visible prose.</p><!-- /wp:paragraph -->'
                . '<!-- wp:image -->'
                . '<figure><img src="https://example.test/img.jpg" alt="Reference text" /></figure>'
                . '<!-- /wp:image -->',
            'post_date_gmt' => '2026-04-15 09:00:00',
        ]
    );
    WordPressTestState::$capabilities['read_post:300'] = true;

    $samples = $this->collector->for_post( 0, 'post' );

    $this->assertCount( 1, $samples );
    $this->assertStringContainsString( 'Visible prose', $samples[0]['opening'] );
    $this->assertStringNotContainsString( '[Attribute references]', $samples[0]['opening'] );
    $this->assertStringNotContainsString( 'Reference text', $samples[0]['opening'] );
}

public function test_render_throw_drops_candidate_and_keeps_siblings(): void {
    WordPressTestState::$current_user_id = 5;

    register_block_type(
        'flavor-agent-test/voice-sample-explody',
        [
            'render_callback' => static function (): string {
                throw new \RuntimeException( 'voice sample render boom' );
            },
        ]
    );

    WordPressTestState::$posts[310] = new \WP_Post(
        [
            'ID'            => 310,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'Survives',
            'post_content'  => '<!-- wp:paragraph --><p>Survives.</p><!-- /wp:paragraph -->',
            'post_date_gmt' => '2026-04-15 09:00:00',
        ]
    );
    WordPressTestState::$posts[311] = new \WP_Post(
        [
            'ID'            => 311,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'Throws',
            'post_content'  => '<!-- wp:flavor-agent-test/voice-sample-explody /-->',
            'post_date_gmt' => '2026-04-16 09:00:00',
        ]
    );

    WordPressTestState::$capabilities['read_post:310'] = true;
    WordPressTestState::$capabilities['read_post:311'] = true;

    $samples = $this->collector->for_post( 0, 'post' );

    $titles = array_column( $samples, 'title' );
    $this->assertContains( 'Survives', $titles );
    $this->assertNotContains( 'Throws', $titles );
}
```

- [ ] **Step 2: Run tests to verify they fail**

```
vendor/bin/phpunit --filter PostVoiceSampleCollectorTest
```

Expected: 2 failures — `opening` is still empty.

- [ ] **Step 3: Implement render call with strip and try/catch**

Replace the loop body inside `for_post`:

```php
$samples = [];
foreach ( $candidates as $candidate ) {
    if ( ! $candidate instanceof \WP_Post ) {
        continue;
    }

    if ( '' !== (string) ( $candidate->post_password ?? '' ) ) {
        continue;
    }

    if ( ! current_user_can( 'read_post', (int) $candidate->ID ) ) {
        continue;
    }

    try {
        $rendered = $this->post_content_renderer->extract(
            (string) ( $candidate->post_content ?? '' ),
            [ 'postId' => (int) $candidate->ID ]
        );
    } catch ( \Throwable $e ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surface sample-render failures without aborting recommendations.
        error_log(
            sprintf(
                '[flavor-agent] PostVoiceSampleCollector: render failed for post %d - %s',
                (int) $candidate->ID,
                $e->getMessage()
            )
        );
        continue;
    }

    if ( str_contains( $rendered, '[block render failed:' ) ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surface per-block sample render failures without aborting recommendations.
        error_log(
            sprintf(
                '[flavor-agent] PostVoiceSampleCollector: dropping post %d due to block render failure marker',
                (int) $candidate->ID
            )
        );
        continue;
    }

    $opening = self::strip_attribute_references( $rendered );

    $samples[] = [
        'title'     => sanitize_text_field( (string) ( $candidate->post_title ?? '' ) ),
        'published' => '',
        'opening'   => $opening,
    ];
}

return $samples;
```

Add the helper at the bottom of the class:

```php
private static function strip_attribute_references( string $rendered ): string {
    $marker   = "\n\n[Attribute references]\n";
    $position = strpos( $rendered, $marker );

    if ( false === $position ) {
        return $rendered;
    }

    return substr( $rendered, 0, $position );
}
```

The marker-detection check uses Layer 1's literal failure marker (`[block render failed:`). It runs after the catastrophic-failure catch but before strip + truncation, so a contaminated sample never reaches the prompt. Both detection paths log to `error_log` so operators can correlate dropped samples with the underlying block-render diagnostic that Layer 1 already emits.

- [ ] **Step 4: Run tests to verify they pass**

```
vendor/bin/phpunit --filter PostVoiceSampleCollectorTest
```

Expected: All 12 tests pass. The render-throw test prints the `[flavor-agent] ...` line to stderr — that is expected.

- [ ] **Step 5: Commit**

```bash
git add inc/Context/PostVoiceSampleCollector.php tests/phpunit/PostVoiceSampleCollectorTest.php
git commit -m "feat(content-context): render samples and strip attribute refs

Each candidate goes through PostContentRenderer::extract inside
try { ... } catch ( \\Throwable ). On throw, error_log is invoked
with the candidate ID and the candidate is dropped; siblings
continue. The rendered output's [Attribute references] tail is
stripped because voice samples should expose prose only."
```

---

## Task 7: Truncation with paragraph snap and UTF-8-safe ellipsis

**Files:**
- Modify: `inc/Context/PostVoiceSampleCollector.php`
- Modify: `tests/phpunit/PostVoiceSampleCollectorTest.php`

Each opening is capped to ~1500 chars. If a paragraph break exists at or before 1500, snap to it. Otherwise UTF-8-safely truncate and append `…`. Empty-after-truncate samples are dropped.

- [ ] **Step 1: Write failing tests**

Append to `PostVoiceSampleCollectorTest.php`:

```php
public function test_short_opening_passes_through_untruncated(): void {
    WordPressTestState::$current_user_id = 5;
    WordPressTestState::$posts[400]      = new \WP_Post(
        [
            'ID'            => 400,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'Short',
            'post_content'  => '<!-- wp:paragraph --><p>Short body.</p><!-- /wp:paragraph -->',
            'post_date_gmt' => '2026-04-15 09:00:00',
        ]
    );
    WordPressTestState::$capabilities['read_post:400'] = true;

    $samples = $this->collector->for_post( 0, 'post' );

    $this->assertSame( 'Short body.', $samples[0]['opening'] );
}

public function test_truncates_at_paragraph_boundary_when_possible(): void {
    WordPressTestState::$current_user_id = 5;

    $first  = str_repeat( 'A', 800 );
    $second = str_repeat( 'B', 1200 );
    $third  = str_repeat( 'C', 1200 );

    WordPressTestState::$posts[401] = new \WP_Post(
        [
            'ID'            => 401,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'ParagraphSnap',
            'post_content'  => sprintf(
                '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->'
                . '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->'
                . '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->',
                $first,
                $second,
                $third
            ),
            'post_date_gmt' => '2026-04-15 09:00:00',
        ]
    );
    WordPressTestState::$capabilities['read_post:401'] = true;

    $samples = $this->collector->for_post( 0, 'post' );

    $opening = $samples[0]['opening'];
    $this->assertStringNotContainsString( str_repeat( 'C', 10 ), $opening );
    $this->assertLessThanOrEqual( 1500, mb_strlen( $opening, 'UTF-8' ) );
    $this->assertStringEndsWith( $second, $opening );
    $this->assertStringNotContainsString( '…', $opening );
}

public function test_first_paragraph_over_cap_truncates_with_ellipsis_utf8_safe(): void {
    WordPressTestState::$current_user_id = 5;

    $body = str_repeat( 'A', 1499 ) . "\u{1F642}" . str_repeat( 'B', 200 );

    WordPressTestState::$posts[402] = new \WP_Post(
        [
            'ID'            => 402,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'Ellipsis',
            'post_content'  => sprintf(
                '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->',
                $body
            ),
            'post_date_gmt' => '2026-04-15 09:00:00',
        ]
    );
    WordPressTestState::$capabilities['read_post:402'] = true;

    $samples = $this->collector->for_post( 0, 'post' );

    $opening = $samples[0]['opening'];
    $this->assertSame( 1, preg_match( '//u', $opening ) );
    $this->assertStringEndsWith( '…', $opening );
    $this->assertStringContainsString( str_repeat( 'A', 1499 ) . "\u{1F642}", $opening );
    $this->assertStringNotContainsString( 'B', $opening );
}

public function test_drops_sample_whose_opening_truncates_to_empty(): void {
    WordPressTestState::$current_user_id = 5;

    WordPressTestState::$posts[403] = new \WP_Post(
        [
            'ID'            => 403,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'EmptyAfterStrip',
            'post_content'  => '<!-- wp:html --><div></div><!-- /wp:html -->',
            'post_date_gmt' => '2026-04-15 09:00:00',
        ]
    );
    WordPressTestState::$capabilities['read_post:403'] = true;

    $samples = $this->collector->for_post( 0, 'post' );

    $titles = array_column( $samples, 'title' );
    $this->assertNotContains( 'EmptyAfterStrip', $titles );
}
```

- [ ] **Step 2: Run tests to verify they fail**

```
vendor/bin/phpunit --filter PostVoiceSampleCollectorTest
```

Expected: 3 failures (the empty-after-strip test may already pass if the renderer returns ''; the others fail because nothing is truncated).

- [ ] **Step 3: Add the truncation helper and apply it**

Add a class constant and helper to `PostVoiceSampleCollector`:

```php
private const OPENING_MAX_CHARS = 1500;

private static function truncate_opening( string $text ): string {
    $text = trim( $text );
    if ( '' === $text ) {
        return '';
    }

    $length = function_exists( 'mb_strlen' )
        ? mb_strlen( $text, 'UTF-8' )
        : strlen( $text );

    if ( $length <= self::OPENING_MAX_CHARS ) {
        return $text;
    }

    $window      = function_exists( 'mb_substr' )
        ? mb_substr( $text, 0, self::OPENING_MAX_CHARS, 'UTF-8' )
        : substr( $text, 0, self::OPENING_MAX_CHARS );
    $last_break  = strrpos( $window, "\n\n" );

    if ( false !== $last_break && $last_break > 0 ) {
        return rtrim( substr( $window, 0, $last_break ) );
    }

    return $window . '…';
}
```

Update the loop to apply truncation and drop empty:

```php
$opening = self::strip_attribute_references( $rendered );
$opening = self::truncate_opening( $opening );

if ( '' === $opening ) {
    continue;
}

$samples[] = [
    'title'     => sanitize_text_field( (string) ( $candidate->post_title ?? '' ) ),
    'published' => '',
    'opening'   => $opening,
];
```

- [ ] **Step 4: Run tests to verify they pass**

```
vendor/bin/phpunit --filter PostVoiceSampleCollectorTest
```

Expected: All 16 tests pass.

- [ ] **Step 5: Commit**

```bash
git add inc/Context/PostVoiceSampleCollector.php tests/phpunit/PostVoiceSampleCollectorTest.php
git commit -m "feat(content-context): truncate sample openings to ~1500 chars

Paragraph-snaps to the latest \\n\\n boundary at or before the
1500-char cap when possible. Falls back to UTF-8-safe truncation
with an ellipsis when the first paragraph alone exceeds the cap.
Empty-after-truncate samples are dropped — Layer 2 never emits
zero-content slots."
```

---

## Task 8: Published date and ServerCollector facade

**Files:**
- Modify: `inc/Context/PostVoiceSampleCollector.php`
- Modify: `inc/Context/ServerCollector.php`
- Modify: `tests/phpunit/PostVoiceSampleCollectorTest.php`

Fill in the `published` field via `mysql2date( 'Y-m-d', ... )` with `post_date_gmt` and `post_date` fallback. Wire the static facade so `ContentAbilities` can call `ServerCollector::for_post_voice_samples()`.

- [ ] **Step 1: Write failing tests**

Append to `PostVoiceSampleCollectorTest.php`:

```php
public function test_published_uses_post_date_gmt_when_available(): void {
    WordPressTestState::$current_user_id = 5;
    WordPressTestState::$posts[500]      = new \WP_Post(
        [
            'ID'            => 500,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'Dated',
            'post_content'  => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
            'post_date'     => '2026-04-12 12:00:00',
            'post_date_gmt' => '2026-04-12 16:00:00',
        ]
    );
    WordPressTestState::$capabilities['read_post:500'] = true;

    $samples = $this->collector->for_post( 0, 'post' );

    $this->assertSame( '2026-04-12', $samples[0]['published'] );
}

public function test_published_falls_back_to_post_date_when_gmt_empty(): void {
    WordPressTestState::$current_user_id = 5;
    WordPressTestState::$posts[501]      = new \WP_Post(
        [
            'ID'            => 501,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'NoGmt',
            'post_content'  => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
            'post_date'     => '2026-04-12 12:00:00',
            'post_date_gmt' => '',
        ]
    );
    WordPressTestState::$capabilities['read_post:501'] = true;

    $samples = $this->collector->for_post( 0, 'post' );

    $this->assertSame( '2026-04-12', $samples[0]['published'] );
}

public function test_facade_routes_to_collector(): void {
    WordPressTestState::$current_user_id = 5;
    WordPressTestState::$posts[510]      = new \WP_Post(
        [
            'ID'            => 510,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'Routed',
            'post_content'  => '<!-- wp:paragraph --><p>Routed body.</p><!-- /wp:paragraph -->',
            'post_date_gmt' => '2026-04-15 09:00:00',
        ]
    );
    WordPressTestState::$capabilities['read_post:510'] = true;

    $samples = \FlavorAgent\Context\ServerCollector::for_post_voice_samples( 0, 'post' );

    $this->assertCount( 1, $samples );
    $this->assertSame( 'Routed', $samples[0]['title'] );
}
```

- [ ] **Step 2: Run tests to verify they fail**

```
vendor/bin/phpunit --filter PostVoiceSampleCollectorTest
```

Expected: 3 failures — `published` is empty; `ServerCollector::for_post_voice_samples` does not exist.

- [ ] **Step 3: Populate published in the collector**

In `inc/Context/PostVoiceSampleCollector.php`, replace the `published` line in the sample dict:

```php
$samples[] = [
    'title'     => sanitize_text_field( (string) ( $candidate->post_title ?? '' ) ),
    'published' => self::format_published( $candidate ),
    'opening'   => $opening,
];
```

Add the helper:

```php
private static function format_published( \WP_Post $candidate ): string {
    $source = (string) ( $candidate->post_date_gmt ?? '' );
    if ( '' === $source ) {
        $source = (string) ( $candidate->post_date ?? '' );
    }

    if ( '' === $source ) {
        return '';
    }

    return (string) mysql2date( 'Y-m-d', $source );
}
```

- [ ] **Step 4: Add the ServerCollector facade**

In `inc/Context/ServerCollector.php`, add the static field next to the other collector fields (around line 41):

```php
private static ?PostVoiceSampleCollector $post_voice_sample_collector = null;
```

Add the public method next to `for_post_content` (around line 240):

```php
/**
 * @return array<int, array{title: string, published: string, opening: string}>
 */
public static function for_post_voice_samples( int $post_id, string $post_type ): array {
    return self::post_voice_sample_collector()->for_post( $post_id, $post_type );
}
```

Add the lazy-singleton private method next to `post_content_renderer()` (around line 334):

```php
private static function post_voice_sample_collector(): PostVoiceSampleCollector {
    return self::$post_voice_sample_collector ??= new PostVoiceSampleCollector(
        self::post_content_renderer()
    );
}
```

- [ ] **Step 5: Run tests to verify they pass**

```
vendor/bin/phpunit --filter PostVoiceSampleCollectorTest
```

Expected: All 19 tests pass.

- [ ] **Step 6: Commit**

```bash
git add inc/Context/PostVoiceSampleCollector.php inc/Context/ServerCollector.php tests/phpunit/PostVoiceSampleCollectorTest.php
git commit -m "feat(content-context): wire facade and Published date

published uses mysql2date('Y-m-d', post_date_gmt or post_date).
ServerCollector::for_post_voice_samples is the static facade
that ContentAbilities will call. Lazy-singleton mirrors the
existing PostContentRenderer pattern."
```

---

## Task 9: Refactor WritingPrompt::build_user onto PromptBudget

**Files:**
- Modify: `inc/LLM/WritingPrompt.php`
- Modify: `tests/phpunit/WritingPromptTest.php`

Existing direct concatenation is replaced by `PromptBudget`. Each existing section is added with `required = true` and a priority from the spec table. The new `voice_samples` section is added with `priority = 10, required = false`. Budget is sourced from `apply_filters( 'flavor_agent_prompt_budget_max_tokens', 0, 'content' )`.

Tests live at the `WritingPromptTest` level and pass synthetic `voiceSamples` arrays directly to `build_user`, so this task is independently green without depending on Task 10's `ContentAbilities` wiring.

- [ ] **Step 1: Write failing tests in WritingPromptTest**

Append to `tests/phpunit/WritingPromptTest.php` before the closing brace:

```php
public function test_build_user_renders_voice_samples_section_when_provided(): void {
    $prompt = WritingPrompt::build_user(
        [
            'mode'         => 'edit',
            'postContext'  => [
                'postType' => 'post',
                'title'    => 'Current',
                'content'  => 'Existing body.',
            ],
            'voiceSamples' => [
                [
                    'title'     => 'Earlier post',
                    'published' => '2026-04-12',
                    'opening'   => 'Retail floors. WordPress themes.',
                ],
                [
                    'title'     => 'Older post',
                    'published' => '2026-03-05',
                    'opening'   => 'Cloud platforms. Agentic AI.',
                ],
            ],
        ],
        'Tighten the opener.'
    );

    $this->assertStringContainsString( '## Site voice samples', $prompt );
    $this->assertStringContainsString( 'Use them only as voice and style evidence.', $prompt );
    $this->assertStringContainsString( '### Sample: Earlier post', $prompt );
    $this->assertStringContainsString( 'Published: 2026-04-12', $prompt );
    $this->assertStringContainsString( 'Opening:', $prompt );
    $this->assertStringContainsString( 'Retail floors. WordPress themes.', $prompt );
    $this->assertStringContainsString( '### Sample: Older post', $prompt );
}

public function test_build_user_omits_voice_samples_section_when_array_empty(): void {
    $prompt = WritingPrompt::build_user(
        [
            'mode'         => 'edit',
            'postContext'  => [
                'postType' => 'post',
                'title'    => 'Current',
                'content'  => 'Existing body.',
            ],
            'voiceSamples' => [],
        ],
        'Tighten.'
    );

    $this->assertStringNotContainsString( '## Site voice samples', $prompt );
    $this->assertStringNotContainsString( '### Sample:', $prompt );
}

public function test_build_user_omits_voice_samples_section_when_key_missing(): void {
    $prompt = WritingPrompt::build_user(
        [
            'mode'        => 'draft',
            'postContext' => [
                'postType' => 'post',
                'title'    => 'New piece',
            ],
        ],
        'Sketch it.'
    );

    $this->assertStringNotContainsString( '## Site voice samples', $prompt );
}

public function test_build_user_uses_content_scoped_budget_filter(): void {
    $captured = [];
    $filter   = static function ( int $value, string $surface ) use ( &$captured ): int {
        $captured[] = $surface;
        return $value;
    };

    add_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10, 2 );

    try {
        WritingPrompt::build_user(
            [
                'mode'        => 'draft',
                'postContext' => [ 'postType' => 'post' ],
            ],
            'Anything.'
        );
    } finally {
        remove_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10 );
    }

    $this->assertContains( 'content', $captured );
}

public function test_build_user_drops_voice_samples_first_under_budget_pressure(): void {
    $existing_draft = str_repeat( 'A', 6000 );
    $voice_opening  = str_repeat( 'B', 6000 );

    $filter = static fn (): int => 2000;
    add_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10 );

    try {
        $prompt = WritingPrompt::build_user(
            [
                'mode'         => 'edit',
                'postContext'  => [
                    'postType' => 'post',
                    'content'  => $existing_draft,
                ],
                'voiceSamples' => [
                    [
                        'title'     => 'Sample',
                        'published' => '2026-04-12',
                        'opening'   => $voice_opening,
                    ],
                ],
            ],
            'Tighten.'
        );
    } finally {
        remove_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10 );
    }

    $this->assertStringContainsString( str_repeat( 'A', 100 ), $prompt );
    $this->assertStringNotContainsString( str_repeat( 'B', 100 ), $prompt );
    $this->assertStringNotContainsString( '## Site voice samples', $prompt );
}
```

- [ ] **Step 2: Run tests to verify they fail**

```
vendor/bin/phpunit --filter WritingPromptTest
```

Expected: Five new tests fail. Existing WritingPromptTest tests still pass.

- [ ] **Step 3: Refactor WritingPrompt::build_user**

Replace the entire `build_user` method body in `inc/LLM/WritingPrompt.php`. Add the import at the top:

```php
use FlavorAgent\LLM\PromptBudget;
```

Replace `build_user`:

```php
public static function build_user( array $context, string $prompt = '' ): string {
    $mode         = self::normalize_mode( $context['mode'] ?? 'draft' );
    $post_context = is_array( $context['postContext'] ?? null ) ? $context['postContext'] : [];

    $max_tokens = (int) apply_filters( 'flavor_agent_prompt_budget_max_tokens', 0, 'content' );
    $budget     = new PromptBudget( $max_tokens );

    $task_lines = [ '## Task', 'Mode: ' . $mode ];
    if ( ! empty( $post_context['postType'] ) ) {
        $task_lines[] = 'Post type: ' . (string) $post_context['postType'];
    }
    if ( ! empty( $post_context['status'] ) ) {
        $task_lines[] = 'Status: ' . (string) $post_context['status'];
    }
    if ( ! empty( $post_context['slug'] ) ) {
        $task_lines[] = 'Slug: ' . (string) $post_context['slug'];
    }
    $budget->add_section( 'task', implode( "\n", $task_lines ), 100, true );

    if ( ! empty( $post_context['siteTitle'] ) || ! empty( $post_context['siteDescription'] ) ) {
        $site_lines = [ '## Site' ];
        if ( ! empty( $post_context['siteTitle'] ) ) {
            $site_lines[] = 'Title: ' . (string) $post_context['siteTitle'];
        }
        if ( ! empty( $post_context['siteDescription'] ) ) {
            $site_lines[] = 'Description: ' . (string) $post_context['siteDescription'];
        }
        $budget->add_section( 'site', implode( "\n", $site_lines ), 80, true );
    }

    if ( ! empty( $post_context['title'] ) || ! empty( $post_context['excerpt'] ) ) {
        $meta_lines = [ '## Working draft metadata' ];
        if ( ! empty( $post_context['title'] ) ) {
            $meta_lines[] = 'Title: ' . (string) $post_context['title'];
        }
        if ( ! empty( $post_context['excerpt'] ) ) {
            $meta_lines[] = 'Excerpt: ' . (string) $post_context['excerpt'];
        }
        $budget->add_section( 'working_draft_metadata', implode( "\n", $meta_lines ), 80, true );
    }

    if ( ! empty( $post_context['audience'] ) ) {
        $budget->add_section(
            'audience',
            "## Audience\n" . (string) $post_context['audience'],
            70,
            true
        );
    }

    if ( ! empty( $post_context['categories'] ) || ! empty( $post_context['tags'] ) ) {
        $tax_lines = [ '## Taxonomy' ];
        if ( ! empty( $post_context['categories'] ) ) {
            $tax_lines[] = 'Categories: ' . implode( ', ', (array) $post_context['categories'] );
        }
        if ( ! empty( $post_context['tags'] ) ) {
            $tax_lines[] = 'Tags: ' . implode( ', ', (array) $post_context['tags'] );
        }
        $budget->add_section( 'taxonomy', implode( "\n", $tax_lines ), 70, true );
    }

    if ( ! empty( $context['voiceProfile'] ) ) {
        $budget->add_section(
            'voice_profile',
            "## Extra voice notes\n" . (string) $context['voiceProfile'],
            80,
            true
        );
    }

    if ( ! empty( $post_context['content'] ) ) {
        $budget->add_section(
            'existing_draft',
            "## Existing draft\n" . (string) $post_context['content'],
            90,
            true
        );
    }

    $voice_samples_section = self::format_voice_samples_section( $context['voiceSamples'] ?? [] );
    if ( '' !== $voice_samples_section ) {
        $budget->add_section( 'voice_samples', $voice_samples_section, 10, false );
    }

    $guidelines_context = \FlavorAgent\Guidelines::format_prompt_context();
    if ( '' !== $guidelines_context ) {
        $budget->add_section( 'guidelines', $guidelines_context, 60, true );
    }

    $instruction = '' !== trim( $prompt )
        ? trim( $prompt )
        : self::default_instruction_for_mode( $mode );
    $budget->add_section(
        'instruction',
        "## User instruction\n" . $instruction,
        100,
        true
    );

    return $budget->assemble();
}

private static function format_voice_samples_section( mixed $samples ): string {
    if ( ! is_array( $samples ) || [] === $samples ) {
        return '';
    }

    $lines = [
        '## Site voice samples',
        '',
        'These are same-author posts from this site. Use them only as voice and style evidence. Do not copy phrases, claims, anecdotes, or facts unless they also appear in the current draft or user instruction.',
    ];

    foreach ( $samples as $sample ) {
        if ( ! is_array( $sample ) ) {
            continue;
        }

        $title     = (string) ( $sample['title'] ?? '' );
        $published = (string) ( $sample['published'] ?? '' );
        $opening   = (string) ( $sample['opening'] ?? '' );

        if ( '' === $opening ) {
            continue;
        }

        $lines[] = '';
        $lines[] = '### Sample: ' . ( '' !== $title ? $title : 'Untitled' );
        if ( '' !== $published ) {
            $lines[] = 'Published: ' . $published;
        }
        $lines[] = 'Opening:';
        $lines[] = $opening;
    }

    return implode( "\n", $lines );
}
```

- [ ] **Step 4: Run all PHPUnit tests**

```
vendor/bin/phpunit
```

Expected: All tests pass. The five new `WritingPromptTest` cases now go green. Existing `WritingPromptTest` cases continue to pass because `PromptBudget::assemble` preserves insertion order. Existing `ContentAbilitiesTest` cases asserting prompt content (e.g., `Mode: critique`, `Categories: ...`) still pass because section content is unchanged. If any existing test breaks because of strict section-ordering assertions, inspect the assertions and decide whether the test was over-specifying order — section order produced by `PromptBudget::assemble` matches the previous insertion order in `build_user`.

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/WritingPrompt.php tests/phpunit/WritingPromptTest.php
git commit -m "feat(content-prompt): refactor build_user onto PromptBudget

Each existing section now passes through add_section with
required=true and the priority gradient from the spec.
The new voice_samples section is added with priority=10 and
required=false so the budget drops it first under pressure.
Budget is sourced via the surface-keyed filter
flavor_agent_prompt_budget_max_tokens, scope='content',
matching TemplatePrompt/TemplatePartPrompt/StylePrompt.
Tests live in WritingPromptTest and pass synthetic voiceSamples
arrays so this task is green without ContentAbilities wiring."
```

---

## Task 10: Wire voiceSamples into ContentAbilities

**Files:**
- Modify: `inc/Abilities/ContentAbilities.php`
- Modify: `tests/phpunit/ContentAbilitiesTest.php`

`ContentAbilities::recommend_content` resolves the canonical post type (from saved post when `postId > 0`, request payload otherwise) and calls the facade. The result joins `$context` under `voiceSamples`. Per-post auth on the current post is unchanged.

- [ ] **Step 1: Write failing tests for the integration**

Append to `tests/phpunit/ContentAbilitiesTest.php` before the closing brace. These four cover the wiring between `ContentAbilities`, the collector, and the prompt assembler:

```php
public function test_recommend_content_includes_voice_samples_section_when_present(): void {
    WordPressTestState::$current_user_id = 5;

    WordPressTestState::$capabilities['edit_post:600'] = true;
    WordPressTestState::$posts[600]                   = new \WP_Post(
        [
            'ID'           => 600,
            'post_type'    => 'post',
            'post_status'  => 'draft',
            'post_author'  => 5,
            'post_title'   => 'Current',
            'post_content' => '<!-- wp:paragraph --><p>Current body.</p><!-- /wp:paragraph -->',
        ]
    );

    WordPressTestState::$posts[601] = new \WP_Post(
        [
            'ID'            => 601,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 5,
            'post_title'    => 'Published Sample',
            'post_content'  => '<!-- wp:paragraph --><p>Sample paragraph.</p><!-- /wp:paragraph -->',
            'post_date_gmt' => '2026-04-12 09:00:00',
        ]
    );
    WordPressTestState::$capabilities['read_post:601'] = true;

    $this->stub_successful_content_response(
        [
            'mode'    => 'edit',
            'title'   => 'OK',
            'summary' => '',
            'content' => 'X',
        ]
    );

    ContentAbilities::recommend_content(
        [
            'mode'        => 'edit',
            'prompt'      => 'Tighten.',
            'postContext' => [
                'postId'   => 600,
                'postType' => 'post',
                'title'    => 'Current',
                'content'  => '<!-- wp:paragraph --><p>Current body.</p><!-- /wp:paragraph -->',
            ],
        ]
    );

    $prompt = WordPressTestState::$last_ai_client_prompt['text'] ?? '';

    $this->assertStringContainsString( '## Site voice samples', $prompt );
    $this->assertStringContainsString( '### Sample: Published Sample', $prompt );
    $this->assertStringContainsString( 'Published: 2026-04-12', $prompt );
    $this->assertStringContainsString( 'Opening:', $prompt );
    $this->assertStringContainsString( 'Sample paragraph.', $prompt );
}

public function test_recommend_content_omits_voice_samples_section_when_no_samples(): void {
    WordPressTestState::$current_user_id = 7;

    WordPressTestState::$capabilities['edit_post:610'] = true;
    WordPressTestState::$posts[610]                   = new \WP_Post(
        [
            'ID'           => 610,
            'post_type'    => 'post',
            'post_status'  => 'draft',
            'post_author'  => 7,
            'post_title'   => 'Lonely',
            'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
        ]
    );

    $this->stub_successful_content_response(
        [
            'mode'    => 'edit',
            'title'   => 'OK',
            'summary' => '',
            'content' => 'X',
        ]
    );

    ContentAbilities::recommend_content(
        [
            'mode'        => 'edit',
            'prompt'      => 'Tighten.',
            'postContext' => [
                'postId'   => 610,
                'postType' => 'post',
                'title'    => 'Lonely',
                'content'  => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
            ],
        ]
    );

    $prompt = WordPressTestState::$last_ai_client_prompt['text'] ?? '';

    $this->assertStringNotContainsString( '## Site voice samples', $prompt );
    $this->assertStringNotContainsString( '### Sample:', $prompt );
}

public function test_recommend_content_omits_samples_for_unsupported_post_type(): void {
    WordPressTestState::$current_user_id = 9;

    WordPressTestState::$posts[700] = new \WP_Post(
        [
            'ID'            => 700,
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => 9,
            'post_title'    => 'Should Not Appear',
            'post_content'  => '<!-- wp:paragraph --><p>Hidden body.</p><!-- /wp:paragraph -->',
            'post_date_gmt' => '2026-04-12 09:00:00',
        ]
    );
    WordPressTestState::$capabilities['read_post:700'] = true;

    $this->stub_successful_content_response(
        [
            'mode'    => 'draft',
            'title'   => 'OK',
            'summary' => '',
            'content' => 'X',
        ]
    );

    ContentAbilities::recommend_content(
        [
            'mode'        => 'draft',
            'prompt'      => 'Sketch.',
            'postContext' => [
                'postId'   => 0,
                'postType' => 'wp_template',
                'title'    => 'Brand new template',
            ],
        ]
    );

    $prompt = WordPressTestState::$last_ai_client_prompt['text'] ?? '';

    $this->assertStringNotContainsString( '## Site voice samples', $prompt );
    $this->assertStringNotContainsString( 'Should Not Appear', $prompt );
}

public function test_recommend_content_uses_canonical_post_type_for_saved_post(): void {
    WordPressTestState::$current_user_id = 9;

    WordPressTestState::$capabilities['edit_post:710'] = true;
    WordPressTestState::$posts[710]                    = new \WP_Post(
        [
            'ID'            => 710,
            'post_type'     => 'page',
            'post_status'   => 'draft',
            'post_author'   => 9,
            'post_title'    => 'Saved Page',
            'post_content'  => '<!-- wp:paragraph --><p>Page draft.</p><!-- /wp:paragraph -->',
        ]
    );

    WordPressTestState::$posts[711] = new \WP_Post(
        [
            'ID'            => 711,
            'post_type'     => 'page',
            'post_status'   => 'publish',
            'post_author'   => 9,
            'post_title'    => 'Sister page',
            'post_content'  => '<!-- wp:paragraph --><p>Sister body.</p><!-- /wp:paragraph -->',
            'post_date_gmt' => '2026-04-10 09:00:00',
        ]
    );
    WordPressTestState::$capabilities['read_post:711'] = true;

    $this->stub_successful_content_response(
        [
            'mode'    => 'edit',
            'title'   => 'OK',
            'summary' => '',
            'content' => 'X',
        ]
    );

    ContentAbilities::recommend_content(
        [
            'mode'        => 'edit',
            'prompt'      => 'Tighten.',
            'postContext' => [
                'postId'   => 710,
                'postType' => 'post', // stale client value; saved post is a page
                'title'    => 'Saved Page',
                'content'  => '<!-- wp:paragraph --><p>Page draft.</p><!-- /wp:paragraph -->',
            ],
        ]
    );

    $prompt = WordPressTestState::$last_ai_client_prompt['text'] ?? '';

    $this->assertStringContainsString( '### Sample: Sister page', $prompt );
}
```

- [ ] **Step 2: Run tests to verify they fail**

```
vendor/bin/phpunit --filter ContentAbilitiesTest
```

Expected: Both new tests fail — `ContentAbilities` does not yet call the voice-samples facade.

- [ ] **Step 3: Wire voice samples into ContentAbilities**

Open `inc/Abilities/ContentAbilities.php`. The current `recommend_content` is roughly: normalize → per-post auth → render current content → validate → call ChatClient. Insert the voice-samples resolution between the renderer call and the third validation check.

Inside `recommend_content`, after the line:

```php
$post_context['content'] = ServerCollector::for_post_content(
    $raw_content,
    [
        'postId'        => $post_id,
        'stagedTitle'   => $post_context['title'],
        'stagedExcerpt' => $post_context['excerpt'],
    ]
);
```

…and before the `if ( in_array( $mode, [ 'edit', 'critique' ], true ) ...` check, add:

```php
$resolved_post_type = $post_id > 0
    ? (string) ( get_post( $post_id )?->post_type ?? '' )
    : (string) ( $post_context['postType'] ?? '' );

$voice_samples = ServerCollector::for_post_voice_samples( $post_id, $resolved_post_type );
```

Update the `$context` assignment (at the bottom of the function, just before `ChatClient::chat`) to include `voiceSamples`:

```php
$context = [
    'mode'         => $mode,
    'postContext'  => $post_context,
    'voiceProfile' => $voice_profile,
    'voiceSamples' => $voice_samples,
];
```

- [ ] **Step 4: Run all PHPUnit tests**

```
vendor/bin/phpunit
```

Expected: All tests pass, including the two new wiring tests, the prompt-structure tests from Task 9, and the existing per-post auth tests (which remain unchanged).

- [ ] **Step 5: Commit**

```bash
git add inc/Abilities/ContentAbilities.php tests/phpunit/ContentAbilitiesTest.php
git commit -m "feat(content-abilities): include same-author voice samples

Resolved post type is canonical (get_post when saved, sanitized
client value when unsaved). voiceSamples is passed to
WritingPrompt via the existing context array. Unsupported post
types (saved or unsaved) get an empty samples list because the
collector enforces the allowlist server-side."
```

---

## Task 11: Loosen the editor frontend gate

**Files:**
- Modify: `src/content/ContentRecommender.js`
- Modify: `src/content/__tests__/ContentRecommender.test.js`

The current panel hides for any post lacking a saved `postId`. After this change, the panel renders for any supported post type, with or without a `postId`.

- [ ] **Step 1: Add a failing JS test for unsaved-post rendering**

Open `src/content/__tests__/ContentRecommender.test.js`. Append to the inner `describe` block:

```js
test( 'renders for a brand-new unsaved post in a supported type', () => {
    currentState = createState( {
        editor: {
            postId: 0,
            postType: 'post',
            attributes: {
                title: '',
                excerpt: '',
                content: '',
                slug: '',
                status: 'auto-draft',
            },
        },
    } );

    act( () => {
        getRoot().render( <ContentRecommender /> );
    } );

    const text = getContainer().textContent;

    expect( text ).toContain( 'Generate Draft' );
    expect( getContainer().querySelector( 'textarea' ) ).not.toBeNull();
} );
```

- [ ] **Step 2: Run JS tests to verify the new test fails**

```
npm run test:unit -- --runInBand --testPathPattern=ContentRecommender
```

Expected: The new test fails — panel currently returns `null` when `postId` is 0.

- [ ] **Step 3: Loosen the gate**

In `src/content/ContentRecommender.js`, replace the `hasSupportedPost` derivation. Find:

```js
const hasSupportedPost =
    Boolean( postContext.postId ) &&
    SUPPORTED_POST_TYPES.has( postContext.postType );
```

Replace with:

```js
const hasSupportedPost = SUPPORTED_POST_TYPES.has( postContext.postType );
```

- [ ] **Step 4: Run JS tests to verify all pass**

```
npm run test:unit -- --runInBand --testPathPattern=ContentRecommender
```

Expected: All tests pass — the new postId=0 test, the existing "does not render for unsupported editor entities" test (still gates on `postType`), and all others.

- [ ] **Step 5: Add a brand-new-post assertion to the E2E smoke spec**

Open `tests/e2e/flavor-agent.smoke.spec.js`. Locate the existing content-panel block that navigates to `wp-admin/post.php?post=${ postId }&action=edit` (around line 2358). Add a new `test( ... )` block adjacent to it that verifies the panel renders for an unsaved post:

```js
test( 'content panel renders for a brand-new unsaved post', async ( { page } ) => {
    await page.goto( '/wp-admin/post-new.php?post_type=post', {
        waitUntil: 'domcontentloaded',
    } );
    await waitForWordPressReady( page );
    await waitForFlavorAgent( page );
    await dismissWelcomeGuide( page );
    await ensurePostDocumentSettingsSidebarOpen( page );

    const promptInput = page
        .locator( '.flavor-agent-content-recommender textarea' )
        .first();

    await ensurePanelOpen( page, 'Content Recommendations', promptInput );
    await expect( promptInput ).toBeVisible();
    await expect(
        page.getByRole( 'button', { name: 'Generate Draft' } )
    ).toBeVisible();
} );
```

Use whatever helper imports the existing `wp-admin/post.php` test relies on; they should already be in scope at the top of the file.

- [ ] **Step 6: Run the playground E2E smoke suite**

```
npm run test:e2e:playground
```

Expected: The new test passes alongside the existing content-panel test. If the playground harness isn't available locally (it requires Node 24 + the WordPress Playground CLI), record this as a deferred check and pick it up in Task 13.

- [ ] **Step 7: Commit**

```bash
git add src/content/ContentRecommender.js src/content/__tests__/ContentRecommender.test.js tests/e2e/flavor-agent.smoke.spec.js
git commit -m "feat(content-ui): show panel for brand-new unsaved posts

The panel now renders when the post type is supported, regardless
of whether the post has been saved yet. Layer 1's renderer falls
back safely at postId=0; Layer 2's collector resolves author from
the current user. Brand-new drafts can benefit from voice priming
without waiting for an autosave. Adds matching Jest unit test and
a Playwright smoke assertion that the panel renders on
post-new.php."
```

---

## Task 12: Update feature documentation

**Files:**
- Modify: `docs/features/content-recommendations.md`

Capture the Layer 2 surface in user-facing docs: section name, what it contains, when it appears, and the budget filter.

- [ ] **Step 1: Add the Site voice samples section to the feature doc**

Open `docs/features/content-recommendations.md`. In Section 3 ("End-To-End Flow"), update step 5 from:

```markdown
5. `WritingPrompt` builds the Henry-voice system prompt plus the request-specific user prompt, including the rendered current-post text under `Existing draft`
```

…to:

```markdown
5. `WritingPrompt` builds the Henry-voice system prompt plus the request-specific user prompt via `PromptBudget`. The user prompt includes the rendered current-post text under `Existing draft` and, when the author has eligible same-author published posts in the same post type, a `## Site voice samples` section with up to three openings (~1500 chars each, paragraph-snapped) drawn through `PostVoiceSampleCollector`. Voice samples is the only section the budget will drop under pressure.
```

In Section 5 ("Guardrails And Failure Modes"), append:

```markdown
- Voice samples are same-author, publish-only, password-protected-excluded, and `read_post`-gated per candidate. Render failures for an individual sample drop only that sample, never the parent recommendation.
- The `## Site voice samples` section is omitted entirely when no candidates qualify (new authors, unsupported post types, all candidates filtered out, or all renders failed).
- Sites can tune the prompt token budget via the `flavor_agent_prompt_budget_max_tokens` filter, scope `'content'`.
```

In Section 6 ("Primary Functions, Routes, And Abilities"), add `inc/Context/PostVoiceSampleCollector.php` to the list before the abilities entries.

- [ ] **Step 2: Run the docs freshness check**

```
npm run check:docs
```

Expected: Pass. If it complains about specific sections, follow the script's pointer to fix them.

- [ ] **Step 3: Commit**

```bash
git add docs/features/content-recommendations.md
git commit -m "docs(content-recommendations): document voice samples section"
```

---

## Task 13: Final verification pass

**Files:** None

Run the full agent-executable verification pipeline and inspect the summary.

- [ ] **Step 1: Run scoped JS unit tests**

```
npm run test:unit -- --runInBand
```

Expected: All Jest tests pass.

- [ ] **Step 2: Run the full PHPUnit suite**

```
composer test:php
```

Expected: All PHPUnit tests pass (a substantial number more than the pre-Layer-2 baseline of 755).

- [ ] **Step 3: Run the verify pipeline without browser harnesses**

```
node scripts/verify.js --skip-e2e
```

Expected: `output/verify/summary.json` shows `status: "pass"`. Inspect `summary.json` if any step fails; the per-step `stdoutPath`/`stderrPath` point to the full logs.

- [ ] **Step 4: Run the Playground E2E smoke suite**

```
npm run test:e2e:playground
```

Expected: All Playwright tests pass, including the new "content panel renders for a brand-new unsaved post" assertion added in Task 11. If the harness isn't available locally — running this requires Node 24 and the WordPress Playground CLI — record the gap explicitly. The cross-surface validation gates document treats a known-red or unavailable harness as something that must be called out, not silently skipped (`docs/reference/cross-surface-validation-gates.md`).

If you are deferring the run, capture it in the final commit body:

```
[verification waiver] tests/e2e/flavor-agent.smoke.spec.js added
the brand-new-post assertion but the Playground harness was
unavailable in this environment. Maintainer to confirm before
merging or run the suite in CI.
```

- [ ] **Step 5: Inspect the verify summary**

```
cat output/verify/summary.json
```

Confirm `counts` shows zero failed steps and that `lint-plugin` is either passing or explicitly skipped (it requires a resolvable WordPress root). If `lint-plugin` is skipped because the prerequisite is missing, that flips the run to `incomplete` — record this as a waiver in the commit body if intentional.

- [ ] **Step 6: Commit any documentation updates surfaced by check:docs**

If `npm run check:docs` flagged additional files needing a refresh (`docs/SOURCE_OF_TRUTH.md`, `docs/reference/abilities-and-routes.md`, etc.), edit them inline and commit. Otherwise no commit needed for this step.

```bash
git status
git add docs/
git commit -m "docs: refresh source-of-truth references for Layer 2"
```

---

## Self-Review Checklist (run before declaring done)

- [ ] Spec section "In scope" — every bullet has a corresponding task.
- [ ] Spec section "Authorization" — auth checks are in `ContentAbilities` (Layer 1, untouched), the password gate is in the collector (Task 5), the `read_post` cap is in the collector (Task 5).
- [ ] Spec section "Components" — allowlist (Task 3), author resolution (Task 4), candidate selection (Task 4), per-candidate render + try/catch (Task 6), truncation (Task 7), sample formatting (Task 9), PromptBudget integration (Task 9). `published` covered in Task 8.
- [ ] Spec section "Bootstrap test-harness additions" — get_posts extension, mysql2date shim, WP_Post property additions all in Task 2.
- [ ] Spec section "Frontend gate" — Task 11.
- [ ] Spec section "Risks and mitigations" — render-failure mitigation lives in Task 6's catch block; budget-overflow mitigation lives in Task 1's `$required` flag.
- [ ] Implementation ordering matches the spec ordering 1:1 with one consolidation: Tasks 4 and 5 split the spec's step 4-5 into "author + query" and "filtering", which is finer-grained but covers the same ground.

