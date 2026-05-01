# Content Context Layers 2 and 3 — Outline

- **Date:** 2026-04-30
- **Status:** Future work. Not approved. Captured to preserve design thinking from the Layer 1 conversation.
- **Predecessor:** `2026-04-30-content-context-renderer-design.md` (Layer 1)

## Why this file exists

Layer 1 establishes a `postId`-gated server-side block renderer for the **current post**. Layers 2 and 3 extend the recommender's input visibility beyond the current post, with increasing scope, cost, and architectural risk. This document captures the design intent, tradeoffs, and open questions for both layers so they can be picked up without re-deriving the conversation.

These are **not approved designs**. Layer 2 needs a brainstorming pass and design spec before implementation. Layer 3 specifically requires a separate scope review against `docs/reference/release-surface-scope-review.md` check 5 ("does this create a second product?") before any code lands.

## Driving framing

The user's goal: *"For it to be useful it needs to be able to gather all copy/text the site owner intends to be visible to the site user. Ideally not just in the current block editor workspace, but from other pages and posts not currently open but published/drafted (and the blocks in those post types)."*

Mapping that goal to layers:

| Layer | Scope | Risk |
|---|---|---|
| 1 | Current post — render its blocks accurately | Low; postId-gated, in-product, no new subsystem |
| 2 | A bounded sample of other recent/relevant posts | Medium; bounded reads, no persistence, per-post auth |
| 3 | Site-wide vector index of all post copy | High; new subsystem, drafts in vector DB, scope-rule borderline |

## Layer 2 — bounded cross-post sample

### Goal

Add a small, fixed-budget secondary context section that exposes a handful of the user's other posts as voice/style samples. Helps the recommender produce content that sounds like the rest of the site without claiming to "know" the entire corpus.

### Approach sketch

- Server-side: select N posts (default proposal: 3) using a heuristic. Render each through Layer 1's `PostContentRenderer` with the per-post authorized `postId`. Truncate to a per-post character budget (proposed: 1500 chars). Inline as samples in the prompt under a "Site voice samples" section.
- Either auto-populate the existing-but-unused `voiceProfile` field, or add a new `voiceSamples` section in `WritingPrompt::build_user`. Decide during design.
- No persistence. Re-fetch per request. Caching is premature optimization; revisit if perf becomes a concern.

### Selection heuristic — open question

Three candidates:

1. **Recency only** — last N published posts in the same post-type. Simple, defensible, biased toward recent voice.
2. **Recency + shared taxonomy** — last N posts sharing at least one category or tag with the current post. More relevant; more query complexity.
3. **User curation** — let the user pin "voice anchor" posts in plugin settings. Most accurate; requires UI.

Recommended first cut: option 1. Move to option 2 if results aren't varied enough. Defer option 3 unless there's demand.

### Permissions

- `current_user_can('read_post', $id)` per candidate post. Don't rely on the surface-level `edit_posts` gate.
- `current_user_can('edit_post', $id)` is too strict — users edit their own posts but read others.
- Honor `password_required($id)`. Skip `private` posts the user can't read. Skip trashed posts.

### Token budget

- Per-sample cap: ~1500 chars (truncate with ellipsis marker).
- Total cap across samples: ~4500 chars.
- Apply `PromptBudget` (already extant) when integrating into `WritingPrompt`. This is the natural moment to do the deferred `WritingPrompt` → `PromptBudget` refactor mentioned in Layer 1's follow-up work.

### Boundary check (release rule)

| Check | Result |
|---|---|
| 1. Native surface | Same as Layer 1 — post/page document panel. |
| 2. Improves the decision | Yes — broader voice context. |
| 3. Mutation bounds | N/A — input only. |
| 4. Degrades clearly | Yes — if no other posts qualify, the section is omitted; recommendations still work. |
| 5. No second product | Yes — silent infrastructure, no new UI surface. |

### Open questions

- Surface the sample post titles to the user in the panel UI for transparency? Tradeoff: trust signal vs. UI noise.
- Dedupe sample content against the user's current draft (avoid the LLM mimicking phrasing already in the draft)?
- Does this need its own capability gate, or is it implied by content recommendation availability?
- Multi-author sites: filter to "this author's posts only" for voice consistency, or include everyone?

### Risks

- **Performance:** N+1 post renders per request. Cap N tightly; measure if observed.
- **Privacy:** other-author content shipped to the LLM provider. Site owner should understand the broader exposure pattern.
- **Voice drift:** if the user's last 3 posts are atypical, the LLM mimics atypical voice. Heuristic options 2 or 3 mitigate.
- **Stale samples:** post-modification freshness isn't enforced (Layer 2 has no caching). Acceptable.

### Out of scope for Layer 2

- Vector retrieval (Layer 3).
- Cross-post-type sampling unless a real need surfaces.
- Cross-site / multisite federation.

## Layer 3 — site-wide content vector index

### Boundary check — REQUIRED BEFORE IMPLEMENTATION

This layer is gated on a separate scope review. Release rule check 5 ("does this create a second product?") is borderline here, and the answer determines whether Layer 3 is in product or out:

- **In product:** silent infrastructure feeding the recommender. The user never sees the index directly; it just makes recommendations smarter when the corpus is rich.
- **Out of product:** the moment a UI surfaces the index ("ask AI about your site"), or the moment another surface depends on it (template recommendations cross-referencing post copy, pattern recommendations using post text), it tips into a separate "site content intelligence" product. The release rule explicitly tells us to question and stop development in that direction.

**Pre-implementation gate:** the scope review must commit to:
1. No UI surfacing the index.
2. No other recommendation surfaces consume the corpus.
3. If either constraint loosens, the work is re-scoped as a distinct product.

If those commitments cannot be made cleanly, Layer 3 is deferred indefinitely.

### Goal

Embed every published and drafted post/page/CPT, then at recommendation time semantic-search the corpus for snippets relevant to the current draft. Lets the recommender reference any existing site copy regardless of post or post type, with relevance ranking instead of recency heuristics.

### Approach sketch

- **New Qdrant collection:** proposed `flavor_agent_content_corpus`. Separate from the existing patterns collection.
- **Sync pipeline:** mirror `Patterns\PatternIndex`. Hooks: `save_post`, `delete_post`, `wp_trash_post`, `transition_post_status`. Each triggers a debounced re-embed.
- **Initial backfill:** WP cron event walks all posts of supported types, embeds in batches.
- **Embedding source:** the Layer 1 render pipeline per post, with authorized `postId`. Reuses infrastructure end-to-end.
- **Retrieval:** at recommendation time, embed a query derived from the current draft (title + first N words). Search top-K (proposed: 5). Include matched snippets in the prompt under "Related site copy."

### Reuses existing infrastructure

| Existing | Layer 3 use |
|---|---|
| `AzureOpenAI\EmbeddingClient` / `OpenAI\Provider` | Same embedding pipeline as patterns. |
| `AzureOpenAI\QdrantClient` | Same vector DB client. |
| `Patterns\PatternIndex` | Direct architectural template (sync, backfill, diagnostics). |
| `Settings` admin UI | Extend for content corpus status alongside pattern sync. |
| Layer 1 `PostContentRenderer` | Single source of truth for "what does the user see in this post." |

### Permissions

- **Backfill** runs as the system; respects post status (skip private to non-author-readable, password-protected, trashed).
- **Retrieval** respects per-requesting-user permissions: filter top-K results to post IDs the requester can `read_post`. Without this, an Author could see Editor-only drafts via vector search.

### New failure modes

- Qdrant unavailable for the content collection (separate signal from patterns).
- Embeddings stale (post modified since last embed) — show pending count in diagnostics.
- Initial backfill incomplete — Settings badge.
- Drafts opt-out — setting to exclude drafts from indexing for users who don't want unfinished content shipped externally.

Each new failure surfaces through the existing CapabilityNotice plumbing.

### Privacy and cost

- **Drafts in vector DB:** significant privacy decision. Site owner must understand that draft content (potentially sensitive, unfinished, NDA-bound) is being embedded externally. Explicit opt-out required, default decision pending review.
- **Cost on activation:** embedding API calls × N posts. A 500-post site costs 500 embeddings up front. Each subsequent save costs one. Worth a dry-run estimator in Settings.
- **Storage:** Qdrant rows × N posts. Modest, but worth measuring.

### Sync diagnostics

Mirror `Patterns\PatternIndex` admin UI:

- Last sync timestamp.
- Indexed / total counts.
- Pending re-embed count.
- Failed count with last error inline.
- Manual sync button.

### Open questions

- Embedding query construction: title + content prefix? Title only? LLM-generated query summary?
- Snippet length on retrieval: full matched paragraph, or fixed window around match?
- Re-embed cadence: every save (debounced N seconds), or async via cron only?
- CPT eligibility: opt-in or opt-out? Default set?
- Multi-language sites: per-language collections, or shared?
- Recency weighting: pure relevance, or relevance + recency boost?

### Risks

- **Scope creep into a second product** — primary risk per release rule. Discipline required.
- **Cost surprise on activation** — large sites get a big initial embedding bill. Setup must communicate clearly; dry-run estimator helps.
- **Drift between corpus and live posts** — sync gaps mean the recommender references stale copy. Cron backstop mitigates.
- **Draft privacy** — needs explicit consent path; defaults matter.
- **Retrieval performance** — Qdrant ANN search is fast; not expected to bottleneck. Measure to confirm.
- **Permission filtering at retrieval** — easy to forget. Tests must explicitly cover cross-user retrieval cases.

### Out of scope for Layer 3

- Cross-site corpus (multisite federation).
- LLM-generated summaries stored alongside embeddings.
- User-facing "recently embedded" list.
- Author-specific filtering toggles unless real demand emerges.

## Sequencing

If both layers are pursued, Layer 2 first:

1. Layer 2 is smaller, less architectural, and validates whether broader context actually improves recommendations.
2. If Layer 2 alone is sufficient, Layer 3 is unnecessary.
3. If Layer 2 proves insufficient — e.g., users want to reference posts beyond the recency window, or large sites can't get useful coverage from a small sample — that's the trigger for the Layer 3 scope review.

This front-loads the cheaper experiment and preserves optionality.

## What stopping at Layer 1 forever costs

Layer 1 alone gives the recommender accurate visibility into the current post: dynamic blocks visible, attribute-borne text harvested, post-context-dependent renders correct.

Layer 1 alone cannot:

- Match voice across the site beyond the current draft.
- Cross-reference existing site copy.
- Avoid the LLM duplicating phrasing already used in published posts.

Whether those gaps matter depends on usage patterns observed after Layer 1 ships. The honest first decision is: ship Layer 1, watch what users actually want, then decide whether 2 or 3 (or neither) earns the work.

## Related deferred items from Layer 1

These were explicitly deferred during Layer 1 review and are worth holding alongside the layer roadmap:

- **`WritingPrompt::build_user` → `PromptBudget` adoption** for all four sections (guidelines, voice, draft, instruction). Becomes load-bearing in Layer 2 when sample budgets need to share space with the current draft.
- **Comment-source attribute extraction** (the dropped Path B from Layer 1's design): for custom blocks that store visible text only in JSON props without rendering it. Defer until a real plugin needs it; not blocked by Layer 2 or 3.
- **Filter hook for plugins to opt out** of having their blocks rendered. Same posture — defer until a real plugin asks.
