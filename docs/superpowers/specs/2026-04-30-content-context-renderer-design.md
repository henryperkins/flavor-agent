# Content Recommender Context Renderer — Design

- **Date:** 2026-04-30
- **Surface:** Content Recommendations (`flavor-agent/recommend-content`)
- **Layer:** Layer 1 of the content-context expansion plan
- **Status:** Approved (revised after external review)

## Goal

Give the content recommender accurate visibility into the current post by rendering its blocks server-side and harvesting attribute-borne text (image alt, link URLs, ARIA labels, titles) from the rendered HTML before strip-tags wipes it. This closes two gaps in today's pipeline:

1. **Dynamic blocks are invisible.** The current path runs `getEditedPostAttribute('content')` → `sanitize_textarea_field`, which strips both HTML tags and HTML comments. Anything saved as a block comment with attributes (every dynamic block) is wiped before it reaches the prompt.
2. **Attribute-borne text is wiped.** Image alt text, link URLs, button labels, and ARIA labels live inside HTML attributes that `wp_strip_all_tags` removes.

After this change, the LLM sees what the user's readers will see, plus a deduped set of attribute-borne strings extracted from the rendered HTML.

## Scope

### In scope (Layer 1)

- Render the *current post's* blocks server-side via `parse_blocks` + per-top-level-block `render_block`, with the global `$post` set up so post-context-dependent blocks render correctly.
- Strip each block's rendered HTML separately and join with newlines, so block-level elements stay separated after strip-tags.
- Walk the rendered HTML to extract attribute-borne strings (`img[alt]`, `a[href]`, `[aria-label]`, `[title]`).
- Substitute self-referencing blocks (`core/post-content` / `core/post-title` / `core/post-excerpt`) at the top level so they don't recurse or read stale saved values during a recommendation.
- Fall back to today's `sanitize_textarea_field` behavior on truly empty input or empty render output.

### Out of scope (deferred)

- Cross-post context (Layer 2): pulling related/recent posts as voice samples.
- Vector index of all post content (Layer 3): site-wide semantic retrieval.
- `PromptBudget` integration in `WritingPrompt::build_user`. Separate refactor that benefits all four sections of the user prompt.
- Filter hook for plugins to opt out of having their blocks rendered. Defer until a real plugin needs it.
- Populating `categories`, `tags`, `audience`, `voiceProfile` from the editor — separate concern.
- Comment-source ("Path B") attribute extraction for custom blocks whose visible text only lives in JSON props. Custom blocks that don't render their string props aren't visible to readers either, so we don't claim to expose them.
- Self-ref block substitution at nested depths. Top-level only is sufficient; nested `core/post-title` inside a `core/group` in a post body is rare enough to accept the saved-value drift.

## Boundary check (release rule)

Per `docs/reference/release-surface-scope-review.md`:

| Check | Result |
|---|---|
| 1. Native surface where the user already makes the decision | Yes — post/page document panel. |
| 2. Improves the decision with context-aware recommendation | Yes — expands input visibility. |
| 3. Mutation bounds | N/A — content surface remains advisory-only; this changes input only. |
| 4. Degrades clearly when unavailable | Yes — fallback to today's `sanitize_textarea_field` path. |
| 5. Does not create a second product | Yes — silent infrastructure inside an existing surface. No new UI, endpoint, or public surface. |

## Architecture

### New file

`inc/Context/PostContentRenderer.php` — instance class, parallel in shape to `NavigationParser` and `TemplatePartContextCollector`.

```php
namespace FlavorAgent\Context;

final class PostContentRenderer {
    public function extract( string $post_content, array $context = [] ): string;
}
```

`$context` accepts:

- `postId` (int) — the post ID being recommended on. Used to set up the global `$post` so context-dependent blocks render correctly. Authorization is the caller's responsibility (see "Authorization" below); the renderer trusts whatever ID it's given.
- `stagedTitle` (string, may be empty) — the editor's session title. Empty string is an intentional staged value: when the user has cleared the title, the recommender should see the empty title, not the saved one. Self-ref `core/post-title` substitutes whatever string is provided, including `''`.
- `stagedExcerpt` (string, may be empty) — same semantics as `stagedTitle` for `core/post-excerpt`.

All keys are optional. When `postId` is missing or `0`, the renderer skips global setup and accepts that post-context-dependent blocks may render against stale state. When `stagedTitle` / `stagedExcerpt` keys are entirely absent (not present in the array), the renderer treats the values as empty strings and substitutes accordingly.

Returns a single string suitable for inlining under `## Existing draft` in the prompt.

### Facade

`inc/Context/ServerCollector.php` gains a static method that mirrors the convention used by every other context collector in the file:

```php
public static function for_post_content( string $post_content, array $context = [] ): string {
    return self::post_content_renderer()->extract( $post_content, $context );
}

private static function post_content_renderer(): PostContentRenderer {
    return self::$post_content_renderer ??= new PostContentRenderer();
}
```

### Wiring

The wiring change spans four files: the JS client (which currently drops `postId`), the ability input schema, the ContentAbilities entry point, and the activity persistence path.

#### Client (`src/content/ContentRecommender.js`)

`handleFetch` reads `postId` from the editor but does not include it in the dispatched payload (lines 192-205). Add `postId` to the dispatched `postContext`:

```js
fetchContentRecommendations( {
    mode: contentMode,
    prompt,
    postContext: {
        postId: postContext.postId,
        postType: postContext.postType,
        title: postContext.title,
        // ... existing fields
    },
} );
```

Without this change, the server-side renderer never sees `postId` and the post-globals setup never runs in the editor flow.

#### Ability input schema (`inc/Abilities/Registration.php`)

The `recommend-content` ability schema (lines 172-193) does not declare `postId`. Add it:

```php
'postContext' => self::open_object_schema(
    [
        'postId'    => [ 'type' => 'integer' ],
        'postType'  => [ 'type' => 'string' ],
        // ... existing fields
    ],
    'Optional post-editor context for drafting, editing, or critique.'
),
```

#### `inc/Abilities/ContentAbilities.php`

Add the import:

```php
use FlavorAgent\Context\ServerCollector;
```

…matching the pattern in `BlockAbilities`, `NavigationAbilities`, `PatternAbilities`, `StyleAbilities`, `TemplateAbilities`, and `InfraAbilities`.

In `recommend_content`:

```php
// Today:
$post_context = self::sanitize_post_context( $input['postContext'] ?? [] );

// After:
$raw_content  = is_string( $input['postContext']['content'] ?? null )
    ? (string) $input['postContext']['content']
    : '';
$post_id_raw  = $input['postContext']['postId'] ?? 0;
$post_id      = is_numeric( $post_id_raw ) ? (int) $post_id_raw : 0;

if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
    return new \WP_Error(
        'rest_forbidden_context',
        __( 'You cannot request content recommendations for that post.', 'flavor-agent' ),
        [ 'status' => 403 ]
    );
}

$post_context = self::sanitize_post_context( $input['postContext'] ?? [] );

$renderer_context = [
    'postId'        => $post_id,
    'stagedTitle'   => $post_context['title'],
    'stagedExcerpt' => $post_context['excerpt'],
];

$post_context['content'] = ServerCollector::for_post_content(
    $raw_content,
    $renderer_context
);
```

We need the *raw* (pre-sanitize) content for `parse_blocks` because today's `sanitize_textarea_field` strips block delimiters. `postId` is read directly from the request input (sanitize_post_context does not retain it). Title and excerpt are taken from the sanitized array since they're already cleaned for prompt inclusion.

`WritingPrompt::build_user` is unchanged — it still inlines `$post_context['content']` under `## Existing draft`.

### Authorization

Today's permission callback (`Registration.php:156`, `Agent_Controller.php:124`) is the broad `current_user_can( 'edit_posts' )`. That gate is appropriate for the *generic* recommendation surface — the user has some content to work with — but inadequate once we render saved post data: a user with `edit_posts` could request a render against another user's draft and exfiltrate its content through the LLM prompt and downstream activity logs.

The fix is per-post authorization in `ContentAbilities::recommend_content` (shown above): when `postId > 0`, require `current_user_can( 'edit_post', $post_id )`. On failure, return a 403 `WP_Error`. The broad `edit_posts` REST permission stays as the outer gate; per-post is the inner gate that activates when post identity is supplied.

When `postId` is `0`, missing, or invalid, the renderer skips globals setup and proceeds — the user is being recommended on whatever content they sent, with no claim about post identity.

### Activity persistence cap

`Agent_Controller::persist_request_diagnostic_activity` (lines 1047-1077) writes the full `$request_context` to `after.requestContext`. Today that includes the sanitize-stripped post content, which is small. After this design, `requestContext.postContext.content` is the rendered + attribute output — a `core/query` block could push it into hundreds of KB.

To prevent activity row bloat, cap the persisted content field before write. In the existing persist methods (`persist_request_diagnostic_activity` and `persist_request_diagnostic_failure_activity`), apply a constant-defined cap (proposed: 8000 chars) to `$request_context['postContext']['content']`. Truncated values append `\n\n[truncated for activity diagnostics]` so the marker is visible in the admin audit UI.

The cap applies *only* at activity persistence; the LLM prompt receives the full rendered content. This keeps diagnostics reproducible enough to debug a request without making each row the size of the post.

## Components

### 1. Top-level render pass with globals setup

```php
private function render_with_globals( array $blocks, array $context ): array
```

Returns `[ $stripped_chunks, $rendered_html ]` — the per-block stripped text chunks (for visible-text assembly) and the concatenated unstripped rendered HTML (for the attribute walk). Wraps the loop in `setup_postdata` / restore so post-context-dependent blocks render against the correct post.

For each top-level block:

1. If `blockName === 'core/post-content'`: skip (we are already rendering this post). No contribution to either return.
2. If `blockName === 'core/post-title'`: append `$context['stagedTitle'] ?? ''` to chunks (already a stripped string; no render needed). Append nothing to rendered HTML.
3. If `blockName === 'core/post-excerpt'`: append `$context['stagedExcerpt'] ?? ''` to chunks. Append nothing to rendered HTML.
4. Otherwise (including freeform blocks where `blockName === null`):
   - Call `render_block( $block )` inside `try { ... } catch ( \Throwable $e )`.
   - On exception, log via `error_log` with the block name and exception message; append `[block render failed: <name>]` to chunks. Append nothing to rendered HTML for that block.
   - On success, append the raw rendered HTML to the rendered-HTML accumulator and pass it through `strip_block_html` (component 3 below) for the chunks accumulator.

`render_block` recursively renders inner blocks itself, so we do not recurse for the render pass. Recursing here would double-count text from inner blocks of every static parent.

`render_block` on a freeform block (`blockName === null`) returns its `innerHTML` unchanged, so freeform blocks are handled with no special case.

The render loop is wrapped in:

```php
$original_post = $GLOBALS['post'] ?? null;
$post          = $context['postId'] ? get_post( $context['postId'] ) : null;

if ( $post instanceof \WP_Post ) {
    $GLOBALS['post'] = $post;
    setup_postdata( $post );
}

try {
    foreach ( $blocks as $block ) {
        // ... per-block logic above
    }
} finally {
    $GLOBALS['post'] = $original_post;
    if ( $original_post instanceof \WP_Post ) {
        setup_postdata( $original_post );
    } else {
        wp_reset_postdata();
    }
}
```

The `finally` block guarantees global restoration even if a render throws past our per-block catch (which it shouldn't, but defense in depth on globals is cheap).

### 2. HTML attribute walk

```php
private function extract_html_attributes( string $rendered_html ): array
```

Single pass over the *unstripped* rendered HTML using `DOMDocument` + `DOMXPath`. Returns a deduped array of trimmed non-empty strings.

```php
$doc                = new \DOMDocument();
$previous_libxml    = libxml_use_internal_errors( true );

try {
    $wrapped = '<?xml encoding="UTF-8"?><div>' . $rendered_html . '</div>';
    $loaded  = $doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

    if ( ! $loaded ) {
        return [];
    }

    $xpath   = new \DOMXPath( $doc );
    $strings = [];

    foreach ( [ 'alt', 'title', 'aria-label' ] as $attr ) {
        foreach ( $xpath->query( "//*[@{$attr}]" ) as $node ) {
            $value = trim( $node->getAttribute( $attr ) );
            if ( '' !== $value ) {
                $strings[] = $value;
            }
        }
    }

    foreach ( $xpath->query( '//a[@href]' ) as $node ) {
        $value = trim( $node->getAttribute( 'href' ) );
        if ( '' !== $value && '#' !== ( $value[0] ?? '' ) ) {
            $strings[] = $value;
        }
    }

    return array_values( array_unique( $strings ) );
} finally {
    libxml_clear_errors();
    libxml_use_internal_errors( $previous_libxml );
}
```

`libxml_use_internal_errors( true )` is a global setting; without restoration, this renderer would silently change libxml error behavior for the rest of the request. The `try/finally` captures the previous mode and restores it whether `loadHTML` succeeds, fails, or throws.

This catches everything the original explicit attribute map was meant to catch (image alt, button URL, link URL, etc.) plus anything else with the same attribute names, including from custom blocks. No per-block-name allowlist required because the values are extracted from rendered HTML, where source-defined attributes have already been materialized.

`DOMDocument` over regex: more robust on malformed HTML, easier to extend if we want to pull additional attributes later.

### 3. Boundary-preserving strip

```php
private function strip_block_html( string $html ): string
```

Inserts newlines before closing block-level tags so that, after `wp_strip_all_tags`, sibling block-level elements remain separated:

```php
$with_breaks = preg_replace(
    '#</(p|div|h[1-6]|li|tr|td|th|blockquote|article|section|aside|header|footer|main|figure|figcaption|nav|ul|ol|table|hr)\b[^>]*>#i',
    "\n$0",
    $html
);

return trim( wp_strip_all_tags( $with_breaks ) );
```

Used per top-level block. The renderer joins resulting non-empty chunks with `\n\n`, so output reads as separated paragraphs.

### 4. Top-level entry point

```php
public function extract( string $post_content, array $context = [] ): string {
    $post_content = str_replace( "\r", '', $post_content );
    $blocks       = parse_blocks( $post_content );

    if ( empty( $blocks ) ) {
        return self::fallback( $post_content );
    }

    [ $stripped_chunks, $rendered_html ] = $this->render_with_globals( $blocks, $context );
    $visible                              = trim( implode( "\n\n", array_filter( array_map( 'trim', $stripped_chunks ) ) ) );

    $attributes = $this->extract_html_attributes( $rendered_html );
    $attributes = $this->dedupe_against( $attributes, $visible );

    if ( '' === $visible && [] === $attributes ) {
        return self::fallback( $post_content );
    }

    return $this->assemble_output( $visible, $attributes );
}
```

`render_with_globals` is the wrapper around component 1 that returns both the per-block stripped chunks (for visible-text assembly) and the concatenated unstripped rendered HTML (for the attribute walk).

`dedupe_against` removes any attribute string that already appears (case-insensitive substring match) in the visible text.

`assemble_output` produces:

- Both visible and attributes: `"{visible}\n\n[Attribute references]\n- {attr1}\n- {attr2}"`
- Visible only: `"{visible}"`
- Attributes only: `"[Attribute references]\n- {attr1}\n- {attr2}"`

### 5. Fallback

```php
private static function fallback( string $post_content ): string {
    return sanitize_textarea_field( str_replace( "\r", '', $post_content ) );
}
```

Today's behavior. Triggers only when:

- `parse_blocks` returns `[]` (truly empty input — `parse_blocks` returns a freeform block for any non-empty content), or
- The render pass produces literally no visible text and no attributes.

Note: in production WordPress, `parse_blocks('Hello world')` returns a single freeform block with `blockName => null`, not `[]`. The current bootstrap stub diverges from this and returns `[]`. The implementation plan must update the stub (and audit existing test fixtures that may depend on the buggy behavior) so tests reflect production. This is finding 4 from the design review.

## Data flow

```
ContentAbilities::recommend_content
  ↓ raw $input['postContext']['content']  +  postId from input  +  sanitized title/excerpt
ServerCollector::for_post_content( $raw, { postId, stagedTitle, stagedExcerpt } )
  ↓
PostContentRenderer::extract
  ├─ str_replace("\r", "")
  ├─ parse_blocks → $blocks
  │     └─ if [] → fallback (truly empty input)
  ├─ render_with_globals (setup_postdata for $postId, finally-restore)
  │     ├─ per top-level block:
  │     │   ├─ self-ref guard (post-content/title/excerpt) → staged values
  │     │   └─ render_block (try/catch) → strip_block_html → stripped chunk
  │     └─ collect rendered HTML chunks for attribute walk
  ├─ implode chunks with "\n\n" → $visible
  ├─ extract_html_attributes( $rendered_html )  [DOMDocument/XPath walk]
  ├─ dedupe attributes against $visible
  └─ assemble_output  (or fallback if both empty)
  ↓
$post_context['content']
  ↓
WritingPrompt::build_user → "## Existing draft\n{content}"
```

## Error handling

| Failure | Behavior |
|---|---|
| Single `render_block` throws | Caught, logged via `error_log` with block name, replaced with `[block render failed: <name>]` marker. Sibling top-level blocks continue. |
| Render loop throws past per-block catch | `finally` restores `$GLOBALS['post']` and `wp_reset_postdata`. Exception propagates; `recommend_content` returns the natural error to the client. |
| `parse_blocks` returns `[]` | Falls back to `sanitize_textarea_field`. Truly empty input only. |
| `DOMDocument::loadHTML` fails | `extract_html_attributes` returns `[]`. Visible text is unaffected. |
| Attribute extraction encounters malformed HTML | `libxml_use_internal_errors(true)` swallows warnings; whatever parses, we extract from. |
| Render + extract both produce nothing | Falls back to `sanitize_textarea_field` so we never send empty content when input had bytes. |
| `render_callback` calls `wp_die` or `exit` | Cannot be caught by `Throwable`. Documented as a known limitation. |
| `get_post( $postId )` returns `null` for an invalid ID | Render loop runs without setting up globals (matches pre-Layer-1 behavior; no regression). |

## Testing

### New file: `tests/phpunit/PostContentRendererTest.php`

- Static block (paragraph) → text preserved through render + strip.
- Multiple sibling static blocks (paragraph + heading + list) → all visible text captured in document order, separated by blank lines (asserts the boundary-preserving strip works — finding 2).
- Nested static blocks (`core/group` containing `core/heading` + `core/paragraph`) → all inner text captured, no duplication (verifies single top-level render, not recursive).
- Dynamic block with stub `render_callback` registered via `register_block_type` in test setup → rendered output present.
- Block whose `render_callback` throws → marker `[block render failed: <name>]` present, sibling blocks survive, `error_log` invoked once.
- `core/image` rendered with realistic Gutenberg-saved markup (`alt` and `caption` in HTML, not in comment attrs) → both appear in `[Attribute references]` if not already in visible text; deduped when caption is rendered (finding 1).
- `core/button` rendered with `text` and `url` → text in visible, URL in references (finding 1, realistic markup).
- Self-ref `core/post-title` at top level with `stagedTitle = "Working title"` → "Working title" appears in output.
- Self-ref `core/post-content` at top level → empty contribution, no recursion.
- Freeform block (raw text with no comment delimiters parsed as `blockName === null`) → innerHTML rendered as visible text (finding 4).
- Truly empty input (`""`) → fallback path returned.
- Block whose render depends on global `$post` (e.g., a stub block that reads `get_the_title()`) → renders against the provided `postId`, not against `null` post (finding 3).
- `\r` characters in input → stripped before parse.

### Updated: `tests/phpunit/ContentAbilitiesTest.php`

- Existing fixtures' `postContext.content` strings need wrapping in block markup (e.g., `<!-- wp:paragraph --><p>{text}</p><!-- /wp:paragraph -->`) so the new path exercises rather than slipping through the empty-fallback branch.
- Sanitization assertions: today they check that script tags and HTML are stripped from the prompt. With the renderer, the same outcome holds (render + strip-tags is more aggressive, not less). Assertion targets shift from `sanitize_textarea_field` semantics to "after render and strip, no tags remain."
- New test: registered dynamic block's `render_callback` output reaches the prompt under `## Existing draft`.
- New test: `postId` from request is propagated; renders happen with that post as global.

### Bootstrap test-harness additions (`tests/phpunit/bootstrap.php`)

Required for the renderer tests to run (finding 5):

- `parse_blocks`: update the existing stub so it matches WP behavior for *all* freeform cases — non-block input, freeform text before any block, freeform text between blocks, and freeform text after the last block. Each freeform region produces its own freeform block (`blockName => null`, `innerHTML` set to the freeform text). The current stub silently drops freeform regions in any of those positions, so without this expansion the tests would validate behavior that production never follows. Audit existing tests for breakage from this change before merging.
- `register_block_type( $name, $args = [] )`: store name → render_callback (if provided) in a static map.
- `render_block( $block )`: look up registered type by `blockName`. If `render_callback` set, call it with `( $attrs, $content, $block )` arguments. If `blockName === null`, return `$block['innerHTML']`. Otherwise, return `$block['innerHTML']` as-is (matches WP's no-callback behavior for static blocks).
- `setup_postdata( $post )`: minimal stub that stores the current post in WordPressTestState; `wp_reset_postdata` clears it.
- `WP_Post` shim: minimal class-with-public-properties for `get_post` to return.

These stubs are targeted at this surface; if other tests need fuller fidelity later, the harness expands incrementally.

### Browser smoke

`tests/e2e/flavor-agent.smoke.spec.js` post-editor flow already exercises the content panel. The change is server-side only and the prompt input shape is unchanged, so no new browser steps are required.

## Implementation ordering

1. Add bootstrap stubs (`parse_blocks` full freeform fix, `register_block_type`, `render_block`, `setup_postdata`/`wp_reset_postdata`, `WP_Post` shim). Run existing tests; resolve any breakage from the parse_blocks fix.
2. Add `PostContentRenderer` class scaffold (constructor + public method signature) and a failing test for the static-paragraph case.
3. Implement `parse_blocks` + top-level render loop (no globals yet). Static block test passes.
4. Add `setup_postdata`/restore wrapper + test for global-dependent block. Implement.
5. Add render-failure test, then per-block try/catch.
6. Add boundary-preserving strip test (multiple sibling block-level elements with separators), then implement `strip_block_html`.
7. Add nested-blocks test (verify no double-render). Should pass without code changes — validates the design choice.
8. Add `extract_html_attributes` tests with realistic Gutenberg markup for `core/image` and `core/button`, including a libxml-error-mode-restoration test. Implement DOMDocument walk with `try/finally`.
9. Add self-ref guard tests + implementation. Include a test that empty `stagedTitle` produces an empty substitution (intentional staged value).
10. Add freeform-block test (raw text, mixed freeform + blocks) + verify it passes naturally.
11. Add `\r` strip and empty-fallback tests + implementation.
12. Wire `ServerCollector::for_post_content` facade.
13. Add `postId` to the `recommend-content` ability input schema in `Registration.php`. Add a schema-validation test.
14. Add per-post authorization in `ContentAbilities::recommend_content` (`current_user_can('edit_post', $post_id)` when `postId > 0`, returning 403 on failure). Add tests for: postId omitted (passes), postId valid + user authorized (passes), postId valid + user unauthorized (403), postId invalid (proceeds without globals).
15. Wire renderer into `ContentAbilities::recommend_content` (with `use` import). Update `ContentAbilitiesTest` fixtures and add the dynamic-block reach-prompt test plus the postId-propagation test.
16. Update `src/content/ContentRecommender.js` `handleFetch` to include `postId` in the dispatched payload. Update `src/content/__tests__/ContentRecommender.test.js` to assert that `postId` flows into `fetchContentRecommendations`.
17. Add the activity persistence cap in `Agent_Controller::persist_request_diagnostic_activity` and `persist_request_diagnostic_failure_activity` (truncate `requestContext.postContext.content` at 8000 chars with a marker). Add a PHPUnit test for the cap behavior.
18. Run `composer test:php` and `npm run test:unit -- --runInBand`.
19. Run `node scripts/verify.js --skip-e2e` and inspect `output/verify/summary.json`.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Third-party `render_callback` has render-time side effects (analytics, view counters) | Acceptable — same plugin runs on the frontend. Admin-side render of a draft is unusual but not pathological. Note in release notes if surface ships beta. |
| `core/query` in a post body produces large rendered output | Acceptable for Layer 1. If observed in practice, address by integrating `PromptBudget` in `WritingPrompt::build_user` (already-deferred follow-up). |
| Custom block `render_callback` uses `wp_die` or `exit` | `Throwable` cannot catch process exit. Treated as a third-party bug; document as a known limitation. |
| `setup_postdata` leaves globals in a different state if exception escapes the `finally` | `finally` always runs in PHP unless the process exits. Acceptable. |
| `DOMDocument` chokes on exotic HTML produced by a third-party block | `libxml_use_internal_errors(true)` swallows warnings; we return what does parse. Worst case is some attribute strings missed for that block, not a request failure. |
| Attribute walk pulls noisy `href` values like `mailto:` or `tel:` | These are visible to readers in their own way; they're useful signal for the LLM. Not filtered. |
| Self-ref blocks nested inside a wrapper render saved values, not staged | Documented limitation. Affects only post bodies that contain `core/post-title` etc. inside a group, which is rare. |
| Per-post authorization rejects a request with a stale `postId` (e.g., post got deleted between editor session start and request) | 403 surfaces in the existing `AIStatusNotice` error path; user re-opens the post. Acceptable. The alternative — silently dropping `postId` and proceeding without globals — would mask a permission state change and is worse for trust. |
| Activity row size grows with rendered content | Cap at persistence (step 17). LLM prompt content is unaffected. If the cap is hit frequently, raise it or move to derived-metadata-only diagnostics. |
| Bootstrap `parse_blocks` freeform fix changes existing tests that rely on the old behavior | Audit during step 1. Likely affects few tests because fixtures generally use complete block markup. If the blast radius is large, scope the stub change behind a flag and migrate tests incrementally. |

## Follow-up work (not part of Layer 1)

- `WritingPrompt::build_user` → `PromptBudget` adoption, applied to all four sections (guidelines, voice, draft, instruction) with appropriate priorities. Benefits all surfaces, not just content.
- Layer 2: bounded cross-post context (e.g., last N posts in same post type as voice samples).
- Layer 3: site-wide vector index — separate scope review required because of release-rule check 5.
- Comment-source attribute extraction for custom blocks that store visible text only in JSON props (Path B from the original design). Defer until a real plugin needs it.
