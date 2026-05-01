# Content Voice Samples — Design

- **Date:** 2026-05-01
- **Surface:** Content Recommendations (`flavor-agent/recommend-content`)
- **Layer:** Layer 2 of the content-context expansion plan
- **Status:** Approved — pending implementation
- **Predecessor:** `2026-04-30-content-context-renderer-design.md` (Layer 1)

## Goal

Add a bounded "site voice samples" section to the content recommender's user prompt so drafts and edits sound like the rest of the site, not just like an isolated request. The samples are evidence of how this author has sounded before, drawn from their own published posts of the same post type, rendered through Layer 1's `PostContentRenderer` and capped to a small budget.

This is silent infrastructure — there is no new UI surface, no new endpoint, and no user-visible diagnostic. Layer 2 either improves recommendations invisibly or degrades to Layer 1 behavior when no samples qualify.

## Scope

### In scope

- Select up to 3 candidate posts authored by the current draft's author, of the same post type, with `post_status=publish`, ordered by date descending, excluding the current post.
- Filter candidates by `! empty( $candidate->post_password )` (skip password-protected) and `current_user_can( 'read_post', $candidate_id )` per candidate.
- Render each candidate via `PostContentRenderer`, taking only the visible-text portion (no `[Attribute references]` block).
- Truncate each sample to ~1500 chars, paragraph-snapped where possible, UTF-8-safe ellipsis when the first paragraph alone exceeds the cap.
- Inject samples into a new `## Site voice samples` section in `WritingPrompt::build_user`, distinct from `voiceProfile`.
- Adopt `PromptBudget` across the full user prompt with explicit priority discipline. Voice samples is the only removable section; required sections never drop.
- Loosen the editor frontend's panel gate to "supported post type" rather than "saved post ID," so brand-new posts can benefit from voice priming.

### Out of scope

- Curation UI ("voice anchor" pinning). Defer until automatic sampling proves insufficient.
- Shared-taxonomy ranking (sample posts that share categories or tags with the current draft). Refinement, not Layer 2.
- Cross-author samples (any sampling beyond the same-author boundary). Different feature; needs its own design.
- Activity / response diagnostics for samples (e.g., "3 samples included"). Layer 2 stays silent unless real demand surfaces.
- Layer 3 (site-wide vector index) — separate scope review.
- Visible-text budget for the existing draft itself. If a long draft alone exceeds the prompt budget, that's a separate problem and not something Layer 2 should hide.

## Boundary check (release rule)

Per `docs/reference/release-surface-scope-review.md`:

| Check | Result |
|---|---|
| 1. Native surface where the user already makes the decision | Yes — post/page document panel. |
| 2. Improves the decision with context-aware recommendation | Yes — broader voice context. |
| 3. Mutation bounds | N/A — content surface remains advisory-only; Layer 2 changes input only. |
| 4. Degrades clearly when unavailable | Yes — section omitted entirely when zero samples qualify. |
| 5. Does not create a second product | Yes — silent infrastructure inside an existing surface. No new UI, endpoint, ability, or capability flag. |

## Architecture

### New file

`inc/Context/PostVoiceSampleCollector.php` — instance class, parallel in shape to `PostContentRenderer`, `NavigationContextCollector`, and `TemplatePartContextCollector`.

```php
namespace FlavorAgent\Context;

final class PostVoiceSampleCollector {

    public function __construct(
        private PostContentRenderer $post_content_renderer
    ) {}

    /**
     * @return array<int, array{title: string, published: string, opening: string}>
     */
    public function for_post( int $post_id, string $post_type ): array;
}
```

`for_post` accepts:

- `$post_id` — the current post's ID. `0` for an unsaved post; positive for a saved post.
- `$post_type` — the resolved post type. For `$post_id > 0`, this is `get_post( $post_id )->post_type` (canonical). For `$post_id <= 0`, this is the sanitized request `postContext.postType`. Caller resolves before calling.

Returns a list of zero to three sample dictionaries. Each sample has a UTF-8-safe `title`, ISO date `published`, and rendered/trimmed `opening`. The list is empty when no candidates qualify; the caller (WritingPrompt) treats an empty list as "omit the section entirely."

### Facade

`inc/Context/ServerCollector.php` gains:

```php
public static function for_post_voice_samples( int $post_id, string $post_type ): array {
    return self::post_voice_sample_collector()->for_post( $post_id, $post_type );
}

private static function post_voice_sample_collector(): PostVoiceSampleCollector {
    return self::$post_voice_sample_collector ??= new PostVoiceSampleCollector(
        self::post_content_renderer()
    );
}
```

`PostVoiceSampleCollector` reuses the existing `PostContentRenderer` instance via the lazy-singleton pattern that the rest of `ServerCollector` already uses.

### Wiring

#### `inc/Abilities/ContentAbilities.php`

After the existing per-post auth check and before `ChatClient::chat`:

```php
$resolved_post_type = $post_id > 0
    ? (string) ( get_post( $post_id )?->post_type ?? '' )
    : $post_context['postType'];

$voice_samples = ServerCollector::for_post_voice_samples( $post_id, $resolved_post_type );

$context = [
    'mode'         => $mode,
    'postContext'  => $post_context,
    'voiceProfile' => $voice_profile,
    'voiceSamples' => $voice_samples,
];
```

The `voiceSamples` key is added to the existing `$context` array. `WritingPrompt::build_user` reads it.

#### `inc/LLM/WritingPrompt.php`

`build_user` is restructured to assemble through `PromptBudget` rather than direct concatenation. Section order and content are unchanged for existing sections, but each existing section is added via `add_section( $key, $content, $priority, true )` (explicit `true` for the new fourth argument). The new voice-samples section is added via `add_section( 'voice_samples', $content, 10, false )`.

Section priority and required flags:

| Section | Priority | Required |
|---|---|---|
| `task` (mode, post type, status, slug) | 100 | true |
| `site` (title, description) | 80 | true |
| `working_draft_metadata` (title, excerpt) | 80 | true |
| `audience` | 70 | true |
| `taxonomy` (categories, tags) | 70 | true |
| `voice_profile` (user's `voiceProfile`) | 80 | true |
| `existing_draft` | 90 | true |
| `voice_samples` | 10 | false |
| `guidelines` | 60 | true |
| `instruction` | 100 | true |

`existing_draft` sits below `instruction` and `task` because user-provided instruction must always be honored — but above all other context because the model needs the draft to do edit/critique. With `required = true` it never drops regardless of priority; the priority gradient is informational and would only matter if `required` were lowered later.

Empty sections are skipped before reaching `PromptBudget` (its existing behavior already handles trim-empty content; we rely on that).

#### `inc/LLM/PromptBudget.php`

Add a `$required` flag to `add_section`. Required sections are never removed during `assemble()`. Existing call sites that omit the new parameter retain today's behavior.

```php
public function add_section(
    string $key,
    string $content,
    int $priority = 50,
    bool $required = false
): self;
```

`assemble()` loops as today, but `get_lowest_priority_index` is replaced by `get_lowest_priority_removable_index`, which only considers sections with `required === false`. When all remaining sections are required, the loop exits and returns whatever assembled string exists — even if it exceeds budget. This is the documented escape valve for "the existing draft alone is too large"; Layer 2 does not silently strip required content to fit.

The `get_diagnostics()` payload is extended with a `required` boolean per section.

#### `src/content/ContentRecommender.js`

The `hasSupportedPost` gate at lines 170-172 loosens:

```js
const hasSupportedPost = SUPPORTED_POST_TYPES.has( postContext.postType );
```

The `Boolean( postContext.postId )` requirement is dropped. Layer 1's `PostContentRenderer` already falls back safely when `postId === 0`, and Layer 2 derives author identity from `get_current_user_id()` server-side. Drafting a brand-new post that has not yet auto-saved becomes a supported flow.

The unit test at `src/content/__tests__/ContentRecommender.test.js` that asserts the panel hides for unsupported entities continues to pass; a new test asserts the panel renders when `postId === 0` for a `post`/`page` type.

### Authorization

Layer 1 establishes per-post `edit_post` authorization for the *current* post when `postId > 0`. Layer 2 reads candidate posts owned by the same author (or the current user, for new posts) — different operation, different cap.

Layer 2 authorization rules:

| Step | Cap | Behavior on fail |
|---|---|---|
| Outer REST gate | `edit_posts` | 403, no Layer 2 reached. |
| Per-current-post auth (Layer 1) | `edit_post` on `postId` (when `postId > 0`) | 403, request rejected. |
| Author resolution | `get_post( $postId )->post_author` if `> 0`, else `get_current_user_id()` | If resolved author is `0` or empty, return no samples. |
| Password gate | `! empty( $candidate->post_password )` | Skip candidate. |
| Read gate | `current_user_can( 'read_post', $candidate_id )` | Skip candidate. |

The selection query is bounded to `posts_per_page = 3` in the same query (after exclusions), so worst-case 3 candidate rows per request; we do not backfill if `read_post` rejects one of the 3. Backfill complexity isn't justified for same-author own posts where read_post is approximately always true.

### Author identity is server-side only

The client never supplies an author ID. The server resolves it from the canonical post (when saved) or the current user (when unsaved). No new ability schema fields. This prevents request forgery from sampling another user's posts.

## Components

### 1. Author resolution

```php
private function resolve_author_id( int $post_id ): int
```

- `$post_id > 0`: returns `(int) ( get_post( $post_id )?->post_author ?? 0 )`.
- `$post_id <= 0`: returns `get_current_user_id()`.

If the resolved value is `0`, the collector short-circuits and returns `[]` immediately.

### 2. Candidate selection

Single `WP_Query` with conservative flags:

```php
$query = new \WP_Query( [
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
] );
```

`has_password => false` is the WordPress query argument that excludes password-protected posts at the SQL layer. The per-candidate `! empty( $candidate->post_password )` check is defense in depth for older WP versions and unusual storage shapes.

Suppression of meta and term cache updates keeps the query lean — Layer 2 only needs `ID`, `post_title`, `post_date_gmt`, `post_content`, `post_password`, and `post_type` from the result.

### 3. Per-candidate rendering and truncation

For each candidate that passes the password and `read_post` checks:

1. Run `PostContentRenderer::extract( $candidate->post_content, [ 'postId' => $candidate->ID ] )`.
2. The rendered output may include an `[Attribute references]` block at the tail; split on `"\n\n[Attribute references]\n"` and discard the trailing block. Visible text only.
3. Truncate the visible text:
   - If the visible text is `<= 1500` chars (UTF-8), use as-is.
   - Otherwise, find the latest paragraph break (`"\n\n"`) at position `<= 1500`. Truncate at that boundary.
   - If no paragraph break exists below `1500` (the first paragraph alone exceeds the cap), UTF-8-safely truncate to 1500 chars and append `…`.
4. Build the sample dict:

```php
[
    'title'     => sanitize_text_field( $candidate->post_title ),
    'published' => mysql2date( 'Y-m-d', $candidate->post_date_gmt ?: $candidate->post_date ),
    'opening'   => $truncated_visible_text,
]
```

If the resulting `opening` is empty after truncation (the candidate rendered to nothing), the sample is dropped from the result. We do not include zero-content slots.

### 4. Sample formatting in prompt

Inside `WritingPrompt::build_user`, when `$context['voiceSamples']` is a non-empty array:

```
## Site voice samples

These are same-author posts from this site. Use them only as voice and style evidence. Do not copy phrases, claims, anecdotes, or facts unless they also appear in the current draft or user instruction.

### Sample: {title}
Published: {YYYY-MM-DD}
Opening:
{opening text}

### Sample: {title}
Published: {YYYY-MM-DD}
Opening:
{opening text}
```

When the array is empty, the section is omitted entirely — no preamble, no header, no `[no samples]` placeholder.

The samples appear in the order returned by the collector (most recent first). Existing-draft and other content sections are unchanged in placement.

### 5. PromptBudget integration

`WritingPrompt::build_user` constructs a `PromptBudget` instance with the default 12000-token budget. Each section is added via `add_section( $key, $content, $priority, $required )` per the table above. The final string is `$budget->assemble()`.

Empty sections (no content after trim) are not added — `PromptBudget::add_section` already short-circuits on empty content.

If `voice_samples` is dropped by the budget, the rest of the prompt is unaffected. If only required sections remain and they exceed the budget, `assemble()` returns the over-budget string unmodified — the request proceeds and any provider-side handling applies.

## Data flow

```
ContentAbilities::recommend_content
  ├─ resolve $post_id, run per-post auth (Layer 1)
  ├─ render current post via PostContentRenderer (Layer 1)
  ├─ resolve $resolved_post_type:
  │    postId > 0  → get_post($postId)->post_type
  │    postId <= 0 → sanitized request postContext.postType
  ├─ ServerCollector::for_post_voice_samples( $post_id, $resolved_post_type )
  │    └─ PostVoiceSampleCollector::for_post
  │         ├─ resolve_author_id (post_author or current_user_id)
  │         ├─ if author_id == 0 → return []
  │         ├─ WP_Query (3 most recent same-author publish posts, no password)
  │         ├─ per-candidate:
  │         │    ├─ skip if ! empty(post_password)  (defense in depth)
  │         │    ├─ skip if ! current_user_can('read_post', $id)
  │         │    ├─ PostContentRenderer::extract → strip attribute-refs tail
  │         │    ├─ truncate to ~1500 chars, paragraph-snapped, ellipsis on overflow
  │         │    └─ if empty after truncation, drop the sample
  │         └─ return sample dicts
  ├─ build $context with voiceSamples
  └─ ChatClient::chat with WritingPrompt::build_system + build_user
       └─ build_user assembles via PromptBudget
            ├─ add required sections (priority 60-100)
            ├─ add voice_samples (priority 10, required=false)
            └─ assemble → drops voice_samples first if budget exceeded
```

## Error handling

| Failure | Behavior |
|---|---|
| No saved post (`postId === 0`) | Author = current user. Layer 1 still falls back to text-only; Layer 2 still runs. |
| Resolved author ID is 0 | Return `[]` from collector. Section omitted. |
| `WP_Query` returns empty | Return `[]`. Section omitted. |
| All candidates fail password / `read_post` checks | Return `[]`. Section omitted. |
| `PostContentRenderer::extract` returns empty for a candidate | That sample is dropped; remaining samples included. |
| `PostContentRenderer::extract` throws | The throw propagates; Layer 1's `try/finally` already restores globals. ContentAbilities returns the natural error. (Same posture as Layer 1.) |
| `voice_samples` is dropped by `PromptBudget` overflow | Rest of prompt unaffected; request proceeds. |
| Required sections alone exceed budget | `assemble()` returns over-budget string; request proceeds. Layer 2 does not silently strip required content. |
| Database is read-only / `WP_Query` fails | Catches no specific error; an empty result is treated as "no samples." |

## Testing

### New file: `tests/phpunit/PostVoiceSampleCollectorTest.php`

- Returns `[]` when author ID resolves to 0 (no logged-in user, no postId).
- Returns `[]` when the author has no published posts of the requested type.
- Returns up to 3 most-recent published posts authored by the same author, post type, ordered by date DESC.
- Excludes the current post when `postId > 0`.
- Skips password-protected candidates (both query-level via `has_password=false` and defense-in-depth field check).
- Skips candidates where `current_user_can('read_post', $id)` returns false.
- Does not backfill when a candidate is filtered out (a 3-result query that loses 1 to filters returns 2 samples, not 3).
- Truncates each sample's `opening` to ~1500 chars, paragraph-snapped.
- UTF-8-safe ellipsis when the first paragraph alone exceeds 1500 chars.
- Strips the `[Attribute references]` tail from rendered output before truncation.
- Drops samples whose `opening` truncates to empty.
- For `postId === 0` and a logged-in user, samples come from the current user's published posts in the requested post type.
- For `postId > 0` and an authorized user, samples come from `post_author` of that post (which may differ from current user on multi-author sites).

### Updated: `tests/phpunit/ContentAbilitiesTest.php`

- New test: `recommend_content` includes the `## Site voice samples` section in the user prompt when the author has eligible samples.
- New test: when no eligible samples exist, the prompt does not contain the section header or preamble.
- New test: per-post auth on the current post is unchanged; Layer 2 does not bypass it.
- New test: `voiceSamples` context entry is empty when the resolved post type isn't supported.

### Updated: `tests/phpunit/PromptBudgetTest.php`

- Required sections are never dropped, even when over budget.
- Non-required sections drop in priority order (lowest first).
- When all remaining sections are required and they exceed the budget, `assemble()` returns the over-budget string unchanged.
- Diagnostics include the `required` flag per section.

### Updated: `src/content/__tests__/ContentRecommender.test.js`

- Existing test "does not render for unsupported editor entities" still passes (the gate still excludes templates and parts).
- New test: panel renders on a `post` post type with `postId === 0` (brand-new unsaved post).
- New test: `fetchContentRecommendations` is dispatched correctly with `postId === 0` and the resolved post type in the payload.

### Bootstrap test-harness additions (`tests/phpunit/bootstrap.php`)

- `WP_Query` shim: minimal class supporting the args used by `PostVoiceSampleCollector` (`post_type`, `author`, `post_status`, `posts_per_page`, `orderby`, `order`, `post__not_in`, `has_password`). Returns a `posts` array of `WP_Post` objects from `WordPressTestState`. Filters by author/type/status/has_password in the stub.
- `mysql2date` shim: returns a date string in the requested format from a `Y-m-d H:i:s` input, sufficient for the `Y-m-d` format Layer 2 uses.
- `WP_Post` shim from Layer 1 already exists; extend with `post_password`, `post_date_gmt`, `post_author` properties (zero-value defaults).

### Browser smoke

`tests/e2e/flavor-agent.smoke.spec.js` already exercises the content panel. Add one assertion that the panel renders for a brand-new post (after the gate change).

## Implementation ordering

1. Extend `PromptBudget::add_section` with the optional `bool $required = false` parameter; introduce `get_lowest_priority_removable_index`. Add tests asserting required sections never drop. Existing call sites and tests are unchanged because the default is `false` (today's behavior).
2. Add bootstrap stubs (`WP_Query`, `mysql2date`, extended `WP_Post`).
3. Create `PostVoiceSampleCollector` skeleton with `for_post( int, string ): array` returning `[]`. Add the failing tests for empty author / no candidates / unsupported type.
4. Implement author resolution and the WP_Query candidate fetch. Pass the empty / no-candidate tests.
5. Add password and `read_post` filtering. Tests for both gates.
6. Wire `PostContentRenderer` injection. Strip `[Attribute references]` from output. Tests for the strip.
7. Implement truncation: paragraph-snap → first-paragraph fallback with UTF-8-safe ellipsis. Tests for both branches and for the "empty after truncate → drop sample" path.
8. Wire facade: `ServerCollector::for_post_voice_samples`. Smoke test through the facade.
9. Refactor `WritingPrompt::build_user` onto `PromptBudget` with the priority/required table above. Existing prompt-shape tests assert section ordering and content; add new tests that voice samples appears when present and is omitted when absent.
10. Wire `voiceSamples` into `ContentAbilities::recommend_content` between Layer 1's renderer call and `ChatClient::chat`. Add the `ContentAbilitiesTest` cases.
11. Loosen the frontend gate in `src/content/ContentRecommender.js` and add the `postId === 0` test.
12. Run `composer test:php`, `npm run test:unit -- --runInBand`, `node scripts/verify.js --skip-e2e`.
13. Update `docs/features/content-recommendations.md` to mention the new section and its empty-state behavior. Update `docs/SOURCE_OF_TRUTH.md` and `docs/reference/abilities-and-routes.md` if the recommendation contract surfaces the new section. Run `npm run check:docs`.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Three `PostContentRenderer::extract` calls per request adds latency | Each render is bounded (single post, postId-gated). Worst case is roughly Layer 1's cost × 3. Acceptable for a user-initiated editorial flow. Measure if observed in practice. |
| Same-author filter yields zero on a multi-author site for a contributor's first post | Section omitted. No regression vs. Layer 1. The brand-new-author case reverts to "Henry-in-isolation" voice, which is the pre-Layer-2 baseline. |
| A long-running author has 3 most-recent posts that are atypical for the site voice | Same-author + recency baseline; the user can introduce shared-taxonomy ranking later if drift is observed. |
| Voice samples leak factual content into recommendations despite the anti-mining preamble | Preamble is the disclaimer; the model is the actor. If observed in practice, escalate the language or move samples behind a `voiceSamples` system-prompt addition. Not a Layer 2 blocker. |
| `WP_Query` `has_password` argument is not honored on older WP versions | Defense-in-depth `! empty( $candidate->post_password )` check filters survivors. |
| `PromptBudget` extension breaks an existing call site | Default `$required = false` preserves today's behavior. New code paths opt in to required marking. Tests assert no behavior change for unmodified call sites. |
| Brand-new post (`postId === 0`) drafted by a brand-new user with zero published posts | Section omitted. Layer 1 still works. The user has lost nothing. |
| `PostContentRenderer` produces large rendered output for a `core/query` block in a candidate | Truncation cap of ~1500 chars per sample contains the blast radius regardless of source-block size. |
| Frontend gate change causes the panel to show for an unsupported entity (template, template-part) | The `SUPPORTED_POST_TYPES` set still gates by post type. Templates have `postType === 'wp_template'`, which is not in the set. Existing test asserts this. |
| `mysql2date` formatting differs across timezones for the `published` field | The collector reads `post_date_gmt` first, falling back to `post_date`. The displayed date is illustrative for the model, not authoritative. |
| User edits another user's post on a multi-author site (Layer 2 author = `post_author`, not editor) | This is the correct behavior: the *post's* author defines its voice context, not the current editor. If a contributor edits an editor's post, the samples reflect the editor's site voice. Documented behavior. |
| Author resolution uses `get_current_user_id()` for `postId === 0`, but the user is impersonating | WordPress impersonation surfaces only via plugins that override `get_current_user_id`; the collector trusts the WordPress-canonical answer. Out of scope for Layer 2. |

## Follow-up work (not part of Layer 2)

- Shared-taxonomy ranking: prefer same-author candidates that share categories or tags with the current draft. Refinement, low-risk extension.
- Visible-text / current-draft budget. The Layer 2 design explicitly does not solve "the existing draft is too long for the budget." A separate spec should add a draft-truncation policy if real overflow is observed.
- Layer 3 (site-wide vector index) — separate scope review against release rule check 5.
- Diagnostic surfacing: `meta.samplesIncluded` int in the recommendation response if editorial visibility becomes a real need.
- Curation UI: pinned "voice anchor" posts. Defer until automatic sampling proves insufficient.
- Cross-post-type sampling for sites that use CPTs as a primary content vehicle. Defer until demand surfaces.
