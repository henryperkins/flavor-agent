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

- `postId` (int) — the post ID being recommended on. **Required for any block rendering.** When absent or `0`, the renderer short-circuits to `fallback()` (today's `sanitize_textarea_field` path) without parsing or rendering any blocks. Authorization is the caller's responsibility (see "Authorization" below); the renderer trusts whatever positive ID it's given but treats `0`/missing as "no authorized post — do not render."
- `stagedTitle` (string, may be empty) — the editor's session title. Empty string is an intentional staged value: when the user has cleared the title, the recommender should see the empty title, not the saved one. Self-ref `core/post-title` substitutes whatever string is provided, including `''`.
- `stagedExcerpt` (string, may be empty) — same semantics as `stagedTitle` for `core/post-excerpt`.

When `stagedTitle` / `stagedExcerpt` keys are entirely absent (not present in the array), the renderer treats the values as empty strings and substitutes accordingly.

Returns a single string suitable for inlining under `## Existing draft` in the prompt.

**Why postId-gated rendering:** `render_block` executes dynamic-block callbacks (e.g., `core/query`, `core/latest-posts`, custom dynamic blocks), which can read site data beyond the current post. Without an authorized post identity, we have no defensible "current post only" boundary — running those callbacks would surface unauthorized cross-post or plugin-internal data to the LLM and downstream activity logs. The fallback path uses today's behavior verbatim (no rendering), which is safe and a pure no-regression for the no-postId case (typically new unsaved posts).

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

The wiring change spans three files: the JS client (which currently drops `postId`), the ability input schema, and the ContentAbilities entry point.

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

The fix is per-post authorization in `ContentAbilities::recommend_content` (shown above). The complete decision table:

| Input `postId` | `current_user_can('edit_post', $id)` | Behavior |
|---|---|---|
| Absent or `0` | n/a | Skip renderer; use today's `sanitize_textarea_field` path. New-post UX preserved. |
| `> 0` | `true` | Call renderer with the authorized `postId`; full render + globals setup. |
| `> 0` (deleted, missing, or unauthorized) | `false` | Return `WP_Error` with status 403. No rendering, no fallback. |

The broad `edit_posts` REST permission stays as the outer gate; per-post is the inner gate that activates whenever a positive post identity is supplied. Deleted/nonexistent post IDs naturally fail `current_user_can('edit_post', ...)`, so they take the 403 path — no separate "post not found" branch is needed.

The renderer itself short-circuits to `fallback()` whenever `$context['postId']` is missing or `0`, providing defense in depth: even if a future caller forgets the auth check, no block rendering happens without a positive postId.

**Note: there is no activity-persistence cap in this design.** `Agent_Controller` persists its local `$input` (which is the pre-render request payload) to `requestContext`, and PHP's pass-by-value semantics mean the rendered content produced inside `ContentAbilities` never reaches the persist methods. Activity row size is therefore unchanged by this design. If a separate concern emerges about raw-content row size, address it independently.

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

Single pass over the *unstripped* rendered HTML using `DOMDocument` + `DOMXPath`. Returns a deduped, length-capped, count-capped array of trimmed non-empty strings.

Constants on the class:

```php
private const MAX_ATTR_LENGTH = 500;
private const MAX_ATTR_COUNT  = 100;
private const ALLOWED_HREF_SCHEMES = [ 'http://', 'https://', 'mailto:', 'tel:' ];
```

Implementation:

```php
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
        $value = trim( $value );
        if ( '' === $value ) return true;
        $value = self::truncate_attribute_value( $value );
        $strings[] = $value;
        return count( $strings ) < self::MAX_ATTR_COUNT;
    };

    foreach ( [ 'alt', 'title', 'aria-label' ] as $attr ) {
        foreach ( $xpath->query( "//*[@{$attr}]" ) as $node ) {
            if ( ! $append( $node->getAttribute( $attr ) ) ) break 2;
        }
    }

    foreach ( $xpath->query( '//a[@href]' ) as $node ) {
        $href = trim( $node->getAttribute( 'href' ) );
        if ( '' === $href || '#' === ( $href[0] ?? '' ) ) continue;
        if ( ! $this->is_allowed_href_scheme( $href ) ) continue;
        if ( ! $append( $href ) ) break;
    }

    return array_values( array_unique( $strings ) );
} finally {
    libxml_clear_errors();
    libxml_use_internal_errors( $previous_libxml );
}
```

```php
private function is_allowed_href_scheme( string $href ): bool {
    foreach ( self::ALLOWED_HREF_SCHEMES as $scheme ) {
        if ( 0 === stripos( $href, $scheme ) ) return true;
    }
    // Allow relative paths (no scheme).
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
```

**Guards:**

- `class_exists` check at the top: PHP's DOM extension is enabled by default but not guaranteed (`composer.json` does not declare `ext-dom`). Without it, `new \DOMDocument()` would fatal. We return `[]` instead — visible text is unaffected, attribute references are simply absent for that request.
- `MAX_ATTR_LENGTH = 500` per string with ellipsis on overflow: prevents oversized values like `data:image/...;base64,...` URLs (which can be hundreds of KB each) from inflating the prompt. Truncation uses `mb_strlen` / `mb_substr` when available so multibyte attribute text is not cut mid-codepoint.
- `MAX_ATTR_COUNT = 100` total: prevents a 1000-image gallery from exporting 1000 alt strings.
- `ALLOWED_HREF_SCHEMES`: only `http`, `https`, `mailto`, `tel`, and relative paths. Excludes `data:`, `javascript:`, `blob:`, `vbscript:` — these don't carry useful editorial signal and could be huge or hostile.
- `try/finally` for `libxml_use_internal_errors` restoration as before.

This catches what the original explicit attribute map was meant to catch (image alt, button URL, link URL) plus anything else with the same attribute names, including from custom blocks. No per-block-name allowlist required because values are extracted from rendered HTML, where source-defined attributes have already been materialized.

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
    $post_id      = (int) ( $context['postId'] ?? 0 );

    // postId-gated rendering: without an authorized post, do not execute
    // dynamic block callbacks. Caller (ContentAbilities) is responsible for
    // authorization; this is defense in depth.
    if ( $post_id <= 0 ) {
        return self::fallback( $post_content );
    }

    $blocks = parse_blocks( $post_content );
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

Today's behavior. Triggers when any of:

- `$context['postId']` is missing or `0` (postId-gated rendering — see component 4 and Authorization).
- `parse_blocks` returns `[]` (truly empty input — `parse_blocks` returns a freeform block for any non-empty content).
- The render pass produces literally no visible text and no attributes.

Note: in production WordPress, `parse_blocks('Hello world')` returns a single freeform block with `blockName => null`, not `[]`. The current bootstrap stub diverges from this and returns `[]`. The implementation plan updates the stub so tests reflect production.

## Data flow

```
ContentAbilities::recommend_content
  ├─ extract postId from input
  ├─ if postId > 0 && ! current_user_can('edit_post', $id) → return 403
  ├─ if postId == 0 → use sanitize_post_context output (no rendering)
  └─ if postId > 0 && authorized →
       ServerCollector::for_post_content( $raw, { postId, stagedTitle, stagedExcerpt } )
         ↓
       PostContentRenderer::extract
         ├─ str_replace("\r", "")
         ├─ if postId <= 0 → fallback (defense in depth; should not happen via ContentAbilities)
         ├─ parse_blocks → $blocks
         │     └─ if [] → fallback (truly empty input)
         ├─ render_with_globals (setup_postdata for $postId, finally-restore)
         │     ├─ per top-level block:
         │     │   ├─ self-ref guard (post-content/title/excerpt) → staged values
         │     │   └─ render_block (try/catch) → strip_block_html → stripped chunk
         │     └─ collect rendered HTML chunks for attribute walk
         ├─ implode chunks with "\n\n" → $visible
         ├─ extract_html_attributes( $rendered_html )
         │     ├─ guard on DOMDocument/DOMXPath availability
         │     ├─ DOMDocument/XPath walk
         │     ├─ apply MAX_ATTR_LENGTH and MAX_ATTR_COUNT
         │     └─ filter href by ALLOWED_HREF_SCHEMES
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
| `DOMDocument` / `DOMXPath` unavailable | `extract_html_attributes` short-circuits via `class_exists` guard and returns `[]`. Visible text unaffected. |
| `DOMDocument::loadHTML` fails | `extract_html_attributes` returns `[]`. Visible text unaffected. |
| Attribute extraction encounters malformed HTML | `libxml_use_internal_errors(true)` swallows warnings; whatever parses, we extract from. |
| Render + extract both produce nothing | Falls back to `sanitize_textarea_field` so we never send empty content when input had bytes. |
| `render_callback` calls `wp_die` or `exit` | Cannot be caught by `Throwable`. Documented as a known limitation. |
| `postId` absent or `0` | Renderer short-circuits to `fallback()` (no rendering). ContentAbilities also keeps `sanitize_post_context` content as-is. |
| `postId > 0` but `current_user_can` returns false (deleted, missing, or unauthorized) | ContentAbilities returns 403 `WP_Error`. Renderer is never called. |
| `MAX_ATTR_LENGTH` exceeded for a single attribute | Truncate to length, append `…`. |
| `MAX_ATTR_COUNT` reached during walk | Stop appending; return what we have. |
| `href` value uses a disallowed scheme (`data:`, `javascript:`, `blob:`, `vbscript:`, etc.) | Skipped silently. |

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
- Self-ref `core/post-title` with empty `stagedTitle` → empty substitution (intentional staged value).
- Freeform block (raw text with no comment delimiters parsed as `blockName === null`) → innerHTML rendered as visible text.
- Mixed freeform + block content (text before, between, and after blocks) → freeform regions surface as visible text.
- Truly empty input (`""`) → fallback path returned.
- Block whose render depends on global `$post` (e.g., a stub block that reads `get_the_title()`) → renders against the provided `postId`, not against `null` post.
- Block nested inside `core/group` with a `render_callback` → callback executes with the rendered inner content (verifies the recursive render_block stub).
- `\r` characters in input → stripped before parse.

postId-gated rendering:

- `postId` absent → renderer returns `fallback()` immediately; no `parse_blocks`, no `render_block`.
- `postId === 0` → same as absent.
- `postId > 0` but no globals can be set up (`get_post` returns null) → renderer should not have been called; tests assert `extract_html_attributes` and the render loop are not invoked. (Practical guard — ContentAbilities's auth check should prevent this case.)

Attribute walk guards:

- DOMDocument unavailable (test by stubbing `class_exists`) → returns `[]`.
- `data:image/...;base64,...` href → skipped (disallowed scheme).
- `javascript:alert(1)` href → skipped.
- `mailto:author@example.com` href → kept.
- Relative path `/about` href → kept.
- 600-character `alt` value → truncated to 500 + `…`.
- Multibyte `alt` value crossing the 500-character boundary → truncated without producing malformed UTF-8.
- 200 `img` elements with `alt` → at most 100 entries returned.
- libxml internal-error mode is restored after the call (assert `libxml_use_internal_errors(false)` returns the same value before and after).

### Updated: `tests/phpunit/ContentAbilitiesTest.php`

- Existing fixtures' `postContext.content` strings need wrapping in block markup (e.g., `<!-- wp:paragraph --><p>{text}</p><!-- /wp:paragraph -->`) so the new path exercises rather than slipping through the empty-fallback branch.
- Sanitization assertions: today they check that script tags and HTML are stripped from the prompt. With the renderer, the same outcome holds (render + strip-tags is more aggressive, not less). Assertion targets shift from `sanitize_textarea_field` semantics to "after render and strip, no tags remain."
- New test: registered dynamic block's `render_callback` output reaches the prompt under `## Existing draft`.
- New test: `postId` from request is propagated; renders happen with that post as global.

### Bootstrap test-harness additions (`tests/phpunit/bootstrap.php`)

Required for the renderer tests to run (finding 5):

- `parse_blocks`: update the existing stub so it matches WP behavior for *all* freeform cases — non-block input, freeform text before any block, freeform text between blocks, and freeform text after the last block. Each freeform region produces its own freeform block (`blockName => null`, `innerHTML` set to the freeform text). The current stub silently drops freeform regions in any of those positions, so without this expansion the tests would validate behavior that production never follows. Audit existing tests for breakage from this change before merging.
- `register_block_type( $name, $args = [] )`: store name → render_callback (if provided) in a static map.
- `render_block( $block )`: rebuild rendered output by walking `innerContent` and recursively rendering `innerBlocks`. Required so dynamic blocks nested inside static containers actually execute their render_callbacks during tests:

    ```php
    function render_block( array $block ): string {
        $name = $block['blockName'] ?? null;

        if ( $name === null ) {
            return (string) ( $block['innerHTML'] ?? '' );
        }

        // Walk innerContent, substituting nulls with rendered inner blocks.
        $rendered_inner   = '';
        $inner_blocks     = $block['innerBlocks'] ?? [];
        $inner_block_idx  = 0;
        foreach ( $block['innerContent'] ?? [ $block['innerHTML'] ?? '' ] as $chunk ) {
            if ( is_string( $chunk ) ) {
                $rendered_inner .= $chunk;
            } else {
                $next = $inner_blocks[ $inner_block_idx++ ] ?? null;
                if ( is_array( $next ) ) {
                    $rendered_inner .= render_block( $next );
                }
            }
        }

        $registered = WordPressTestState::$registered_block_types[ $name ] ?? null;
        if ( is_array( $registered ) && is_callable( $registered['render_callback'] ?? null ) ) {
            return (string) call_user_func(
                $registered['render_callback'],
                $block['attrs'] ?? [],
                $rendered_inner,
                $block
            );
        }

        return $rendered_inner;
    }
    ```

  Without this recursion, a `core/group` containing a dynamic inner block would silently swallow the inner render in tests, hiding bugs.

- `setup_postdata( $post )`: minimal stub that stores the current post in WordPressTestState; `wp_reset_postdata` clears it.
- `WP_Post` shim: minimal class-with-public-properties for `get_post` to return.

These stubs are targeted at this surface; if other tests need fuller fidelity later, the harness expands incrementally.

### Browser smoke

`tests/e2e/flavor-agent.smoke.spec.js` post-editor flow already exercises the content panel. The change is server-side only and the prompt input shape is unchanged, so no new browser steps are required.

## Implementation ordering

1. Add bootstrap stubs (`parse_blocks` full freeform fix, `register_block_type`, recursive `render_block`, `setup_postdata`/`wp_reset_postdata`, `WP_Post` shim). Run existing tests; resolve any breakage from the parse_blocks fix.
2. Add `PostContentRenderer` class scaffold (constructor + public method signature, postId-gate short-circuit at the top of `extract`) and failing tests for: static paragraph (with valid postId), no postId (returns fallback), postId === 0 (returns fallback).
3. Implement `parse_blocks` + top-level render loop (no globals yet). Static block test passes.
4. Add `setup_postdata`/restore wrapper + test for global-dependent block. Implement.
5. Add render-failure test, then per-block try/catch.
6. Add boundary-preserving strip test (multiple sibling block-level elements with separators), then implement `strip_block_html`.
7. Add nested-blocks test, including a dynamic block nested inside `core/group` (validates the recursive render_block stub from step 1). Should pass without renderer code changes.
8. Add `extract_html_attributes` tests:
   - Realistic Gutenberg markup for `core/image` and `core/button`.
   - libxml-error-mode-restoration assertion.
   - DOM unavailable (stub `class_exists` to return false) → `[]`.
   - Length cap at 500 chars with ellipsis.
   - Count cap at 100 entries.
   - `data:`, `javascript:`, `blob:`, `vbscript:` href schemes skipped.
   - `http`, `https`, `mailto`, `tel`, relative path schemes kept.

   Implement DOMDocument walk with `try/finally`, the `class_exists` guard, the length/count caps, and `is_allowed_href_scheme`.
9. Add self-ref guard tests + implementation. Include the empty-`stagedTitle` test.
10. Add freeform-block tests (raw text, mixed freeform + blocks) + verify they pass naturally with the recursive stub.
11. Add `\r` strip and empty-fallback tests + implementation.
12. Wire `ServerCollector::for_post_content` facade.
13. Add `postId` to the `recommend-content` ability input schema in `Registration.php`. Add a schema-validation test.
14. Add per-post authorization in `ContentAbilities::recommend_content`. Tests:
    - `postId` omitted → no auth check, no rendering, fallback path used.
    - `postId === 0` → no auth check, no rendering, fallback path used.
    - `postId > 0` + user authorized → renderer called.
    - `postId > 0` + user unauthorized → 403.
    - `postId > 0` + post deleted → 403 (cap returns false).
15. Wire renderer into `ContentAbilities::recommend_content` (with `use` import). Update `ContentAbilitiesTest` fixtures and add the dynamic-block reach-prompt test plus the postId-propagation test.
16. Update `src/content/ContentRecommender.js` `handleFetch` to include `postId` in the dispatched payload. Update `src/content/__tests__/ContentRecommender.test.js` to assert that `postId` flows into `fetchContentRecommendations`.
17. Run `composer test:php` and `npm run test:unit -- --runInBand`.
18. Run `node scripts/verify.js --skip-e2e` and inspect `output/verify/summary.json`.

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
| Per-post authorization rejects a request with a stale `postId` (e.g., post got deleted between editor session start and request) | 403 surfaces in the existing `AIStatusNotice` error path; user re-opens the post. Acceptable. The alternative — silently dropping `postId` and proceeding — would mask a permission state change and is worse for trust. |
| New unsaved post (`postId === 0`) doesn't get the rendered-context improvement | Acceptable for Layer 1. Falls back to today's `sanitize_textarea_field` path; no regression. Once the post auto-saves it gets a real `postId` and benefits from the rendered path. |
| `MAX_ATTR_LENGTH` truncates a legitimately long alt or caption | 500 chars covers the vast majority of meaningful captions. Truncation marker preserves visibility that something was cut. UTF-8-safe truncation avoids malformed prompt strings. If observed in real recommendations, raise the limit. |
| `MAX_ATTR_COUNT = 100` clips a gallery with many images | Acceptable for Layer 1. Galleries with 100+ images are rare; limit prevents runaway. If observed, address by per-block budgeting. |
| Bootstrap `parse_blocks` freeform fix changes existing tests that rely on the old behavior | Audit during step 1. Likely affects few tests because fixtures generally use complete block markup. If the blast radius is large, scope the stub change behind a flag and migrate tests incrementally. |
| Recursive `render_block` stub diverges subtly from real WP behavior in edge cases | Acceptable for unit-test coverage. Browser smoke + integration paths against real WP catch divergence. Keep the stub minimal but recursive enough for nested-dynamic coverage. |
| DOMDocument extension missing on a host | Attribute extraction silently returns `[]`; visible text path unaffected. Document the degraded-attributes behavior in release notes if observed. |

## Follow-up work (not part of Layer 1)

- `WritingPrompt::build_user` → `PromptBudget` adoption, applied to all four sections (guidelines, voice, draft, instruction) with appropriate priorities. Benefits all surfaces, not just content.
- Layer 2: bounded cross-post context (e.g., last N posts in same post type as voice samples).
- Layer 3: site-wide vector index — separate scope review required because of release-rule check 5.
- Comment-source attribute extraction for custom blocks that store visible text only in JSON props (Path B from the original design). Defer until a real plugin needs it.
