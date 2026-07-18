# Streaming Recommendations — Design Proposal

**Status:** Design proposal · **Date:** 2026-05-24 · **Targets:** AI Plugin 7.1 cycle and `WordPress/php-ai-client` milestone 1.4.0 · **Update 2026-07-18:** `1.4.0` shipped 2026-07-15 *without* streaming (embedding APIs only); the implementation is now open PR [#255](https://github.com/WordPress/php-ai-client/pull/255) under review, with WordPress `Requests`-library maintenance constraints (current direction: the change lands inside `Requests`, not as a separate WP-core implementation). The trigger condition below is re-based accordingly.

## TL;DR

The 20 May 2026 contributor summary marked **native streaming the critical priority for WordPress AI 7.1**. The php-ai-client tracking issue ([#100](https://github.com/WordPress/php-ai-client/issues/100)) is milestoned for **php-ai-client 1.4.0**. Streaming is **not yet implemented** in the AI Client and explicitly removed from interfaces in PR #170 / milestone 0.4.0.

For Flavor Agent:

- **Two surfaces benefit and can adopt streaming first:** `recommend-content` (drafting) and `recommend-template` (long structural suggestions). Both have noticeable wait times (multi-second) and produce output that is human-readable as it generates.
- **Five surfaces should NOT stream:** `recommend-block`, `recommend-pattern`, `recommend-style`, `recommend-navigation`, `recommend-template-part`. Their value is ranked structured options (JSON arrays / typed operations), not progressive prose. Showing partial ranked lists is misleading.
- **The review-before-apply gate is preserved as a final-payload-only check.** The streamed text is for display; the apply path runs against the final, schema-validated payload that hashes to the server-issued review signature. Streaming changes the *display* of generation, not the *apply* contract.
- **WordPress HTTP is buffered.** Per [php-ai-client#237](https://github.com/WordPress/php-ai-client/issues/237), `wp_remote_*` does not natively pipe a `text/event-stream` to the browser. The server-side stream must terminate on the WordPress server (buffered SSE parsed inside the provider model), and the editor → server hop has to use either chunked HTTP, Server-Sent Events on a long-poll endpoint, or websockets to deliver progressive updates to the JS store. This constraint dictates the Flavor Agent transport more than the AI Client design does.
- **Adopt only after AI Client 7.1 lands.** Building on the pre-streaming AI Client today buys nothing — the constraints we'd commit to (mocked deltas, fake stream protocol) almost certainly won't match what ships.

## Background

### What "streaming" means in WP 7.1 contributor language

From the [20 May 2026 contributor summary](https://make.wordpress.org/ai/2026/05/21/ai-contributor-weekly-summary-20-may-2026/):

> Native streaming data support remains the top requested missing feature among early 7.0 testers. Building out deep streaming infrastructure is officially marked as a critical priority for the 7.1 cycle.

From [php-ai-client#100](https://github.com/WordPress/php-ai-client/issues/100) (Felix Arntz, milestoned for 1.4.0):

> A crucial piece of functionality still missing in our SDK is response streaming - a quite complex piece of functionality to get right, especially with the incomplete documentation of how different providers implement it.
>
> What we'll need to do is:
> - Implement the primitives needed for streaming (likely some kind of stream response class, potentially a new stream method on `HttpTransporter`).
> - Implement actual streaming for the three providers (which can fortunately be centralized in the OpenAI API compatible implementation for now).

The earlier scaffolding was removed in [PR #170](https://github.com/WordPress/php-ai-client/pull/170) (merged 2026-01-16) on the basis that "it might be a better solution to have a standalone interface for a model that can stream text — not every provider necessarily supports both regular text generation and text streaming." That signals streaming will likely be a **separate model interface** (e.g. `StreamingTextGenerationModelInterface`) rather than an added method on `TextGenerationModelInterface`. Plan accordingly.

### What Flavor Agent does today

`WordPressAIClient::chat()` (`inc/LLM/WordPressAIClient.php`) is strictly request/response:

```php
$result = self::call_prompt_method_with_request_timeout(
    $prompt,
    'generate_text_result',
    [],
    $request_timeout_seconds
);
```

One-shot HTTP, full payload returned, then parsed for the `text` field. The JS store consumes a complete recommendation object via the REST/abilities client (`src/store/abilities-client.js`).

For each surface, the response is validated by:

- **Structured output schema** — `WordPressAIClient::as_json_response()` with the per-surface `ResponseSchema` (`inc/LLM/ResponseSchema.php`).
- **Review signature** — `Support\RecommendationReviewSignature::from_payload()` produces a deterministic SHA-256 hash of `{surface, normalized-payload}` with sorted keys (`inc/Support/RecommendationSignature.php`). The editor stores this hash; on apply, the server re-checks that the payload still produces the same hash (freshness check).
- **Resolved-context signature** — `Support\RecommendationResolvedSignature` for surfaces whose apply path requires the surrounding editor context to be unchanged since the recommendation was generated.

The review-signature gate is a **whole-payload contract**. It is the apply-time guarantee that the user is approving the thing the model actually produced. Streaming cannot weaken this.

## Surface adoption matrix

| Surface | Adopt streaming? | Why |
|---|---|---|
| `recommend-content` (draft mode) | **Yes — first** | Multi-second waits, output is long prose, progressive display is how every other LLM writing tool works. Highest UX payoff. |
| `recommend-content` (edit / critique mode) | **Yes — second** | Edit produces rewrites; critique produces a list of issues with revisions. Both are long enough to feel slow; both render well incrementally. |
| `recommend-template` (long structural suggestions) | **Yes — third** | Template suggestions with multiple operations can take 5–15 s and produce explainable text alongside the operations array. Stream the explanation text, render operations only when the final payload validates. |
| `recommend-template-part` | No | Short payload (one part); wait is rarely noticeable; partial operation lists mislead. |
| `recommend-block` | No | Output is a ranked list of attribute/style changes. Showing the list as it builds suggests an ordering that isn't real (the model may revise its top pick after seeing all candidates). |
| `recommend-pattern` | No | Same as block — ranked candidates. The pipeline trace is the right diagnostic surface, not progressive display. |
| `recommend-navigation` | No | Advisory-only; payload is structured suggestions. Same misleading-ranking concern. |
| `recommend-style`, `recommend-global-styles`, `recommend-style-book` | No | Operations targeting a strict design semantics surface. Operations must clear schema + WCAG AA contrast (`Support\StyleContrastValidator`) before display; partial operations would either appear and disappear, or appear invalid. |
| docs grounding (`search-wordpress-docs`) | No | Not LLM generation; ranked search results. |
| helper/read abilities | No | Direct reads, no generation. |

**Streaming candidates: content (draft/edit/critique) and template recommendations.** Everything else stays one-shot.

## The streaming-vs-schema reconciliation

The hard part: how does a JSON-schema-validated structured response coexist with token-by-token streaming?

### Pattern 1 (recommended): Side-channel streamed explanation, schema-validated final payload

Most of the content recommender's value is the long-form `content` / `summary` / `notes` fields. The operations and rankings are short. So:

- Use a **dual-channel response**:
  - Channel A — streamed plaintext (the `content` for `recommend-content`, or the `explanation` field for `recommend-template`). Rendered live as it arrives.
  - Channel B — the final structured JSON payload (`mode`, `title`, `summary`, `notes`, `issues`, `operations`, `requestMeta`). Arrives at end-of-stream, fully validated, hashes to the server-issued review signature.
- The UI shows the streamed text in the recommendation panel as it generates. The apply button stays disabled until Channel B arrives and the signature matches.
- On any provider error mid-stream, drop the partial text and show the normalized `WP_Error` from `WordPressAIClient::chat()`.

This preserves the apply contract — apply is still gated on a complete, schema-clearing payload — while delivering the perceived-latency win where it matters (the prose).

This pattern aligns with how Felix's prior `ai-services` implementation handled it (linked from [#100](https://github.com/WordPress/php-ai-client/issues/100)) and how the OpenAI Responses API exposes streamed deltas alongside a final structured response object.

### Pattern 2 (rejected): Streamed JSON object

Stream the JSON payload itself as it generates. Parse incrementally with a streaming JSON parser. Display the partial fields as they materialize.

**Rejected because:**
- The review signature is a hash of the *normalized* payload. Partial JSON cannot hash. The apply gate would have to wait for end-of-stream anyway, eliminating most of the streaming benefit.
- WordPress' core JSON tooling has no streaming parser. We'd ship one in `vendor/` or write one. Not worth the dependency surface for a UX feature that Pattern 1 already delivers.
- Partial structured display (half-rendered `operations` arrays, half-loaded `issues` lists) is misleading rather than helpful.

### Pattern 3 (rejected): Apply on streamed deltas

Allow apply to fire on an intermediate streamed state.

**Rejected unconditionally.** The whole point of the review-before-apply pattern (`inc/AI/Abilities/RecommendBlockAbility.php` and siblings, `src/components/AIReviewSection.js`) is that the user is approving a deterministic, server-validated payload. A streaming apply path is a different feature (live drafting; agent-on-rails), not a streaming optimization to the existing recommendation surface, and would be its own design.

## Transport — the WordPress HTTP buffering problem

Per [php-ai-client#237](https://github.com/WordPress/php-ai-client/issues/237):

> WordPress Core HTTP is buffered; providers that must send `stream=true` can still parse a fully buffered `text/event-stream` response in their own model implementation.

This is a load-bearing constraint. The server-side path looks like:

```
Provider (OpenAI/Anthropic/…)
    │   text/event-stream over HTTPS
    ▼
php-ai-client StreamingHttpTransporter (new in 1.4.0)
    │   parses SSE deltas as they arrive
    ▼
Provider model implementation (e.g. OpenAI text-generation model)
    │   emits PHP generator yielding partial deltas
    ▼
WordPressAIClient::stream_chat() (NEW in Flavor Agent)
    │   ???
    ▼
Editor JS store
```

The `???` step is the real design question. WordPress' `WP_Http` does not transparently pipe an upstream `text/event-stream` out to the client. Three options for the editor → server hop:

### Transport A — Chunked HTTP response from the Flavor Agent endpoint

A new REST/abilities endpoint that returns `Transfer-Encoding: chunked` and `Content-Type: text/event-stream`, flushing each delta as it's received from the provider. PHP supports this via `ob_implicit_flush(true)` and manual `flush()`, but:
- Apache/NGINX buffering may delay flushes unpredictably (varies by host).
- The PHP process holds an open connection for the full generation duration (5–30 s).
- The Abilities API REST endpoint shape doesn't have a documented chunked-response pattern; the run endpoint is request/response.

Works on well-configured hosts; ugly on shared hosting. Probably the right default once the AI Client supports it.

### Transport B — Polling endpoint that returns deltas since cursor

The Flavor Agent endpoint kicks off a job, returns a job ID. The editor polls `/flavor-agent/v1/stream/{jobId}?cursor=N` every 100–250 ms to fetch the deltas accumulated server-side since cursor N. Generation runs in a separate process (a single long-running request that writes deltas to a transient or a session-scoped table).

- Works on any host.
- Higher latency (polling interval).
- Requires server-side delta storage (transient or new table).
- Connection cost is the polling rate, not the full generation duration.

Defensive choice for the "works everywhere" path. Probably the right v1.

### Transport C — WebSocket

Out of scope. WordPress has no native WebSocket support. Defer indefinitely.

**Recommend: Transport B as v1, with a configuration switch to upgrade to Transport A on hosts that support it cleanly.** A capability detector (`is_chunked_streaming_supported()`) can probe by checking server software / `gzip_off` / `X-Accel-Buffering: no` support and default Transport B when uncertain.

This decision can be revisited after the AI Client lands its streaming primitives in 1.4.0 — if `php-ai-client` ships a recommended editor-side transport reference, prefer alignment.

## Server-side architecture

Two new server-side components:

### `inc/LLM/WordPressAIClient::stream_chat()` (new)

Parallel to `chat()`. Returns a `\Generator|\WP_Error` rather than `string|\WP_Error`. Each yielded value is a partial delta `array{ text?: string, schemaPayload?: array, done?: bool, requestMeta?: array }`. The generator runs to completion regardless of which transport consumes it.

Gated on:

```php
public static function is_streaming_supported( ?string $provider = null ): bool {
    if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
        return false;
    }
    // Streaming requires php-ai-client 1.4.0+ AND a model that implements StreamingTextGenerationModelInterface.
    $prompt = self::make_prompt( 'Flavor Agent streaming check.' );
    if ( is_wp_error( $prompt ) ) {
        return false;
    }
    if ( ! is_callable( [ $prompt, 'is_supported_for_text_streaming' ] ) ) {
        return false; // pre-1.4.0 AI Client
    }
    return (bool) $prompt->is_supported_for_text_streaming();
}
```

The exact builder method name (`is_supported_for_text_streaming`, `is_supported_for_streamed_text_generation`, etc.) is TBD until [#100](https://github.com/WordPress/php-ai-client/issues/100) lands. The check pattern matches the existing `is_supported_for_text_generation()` shape.

The fallback path is: if streaming is not supported, the new content/template abilities transparently fall back to `chat()` and return a synthetic "complete" delta. The frontend always sees the same shape; the experience just isn't streamed.

### `inc/Streaming/JobStore.php` (new — only if Transport B is chosen)

A small store keyed by `jobId` (UUID) that the polling endpoint reads from. Schema is intentionally minimal:

```
flavor_agent_stream_jobs:
  job_id            VARCHAR(36) PK
  user_id           BIGINT
  surface           VARCHAR(32)
  started_at        DATETIME
  completed_at      DATETIME NULL
  status            VARCHAR(16)    -- pending | streaming | complete | error
  cursor            INT UNSIGNED   -- next-to-write delta index
  deltas            LONGTEXT       -- JSON array of yielded deltas
  final_payload     LONGTEXT       -- the final schema-validated payload
  review_signature  VARCHAR(64)    -- the SHA-256 set by RecommendationReviewSignature
  error             LONGTEXT       -- WP_Error JSON on failure
```

Retention: 24 hours, prune via cron. **Do not write to `flavor_agent_activity`** — these are transient generation buffers, not user activity.

If Transport A is chosen, this table doesn't exist; the deltas go straight to the connection.

## Client-side architecture

A new `src/store/streaming-runtime.js` slice handles:

- `startStream(surface, request)` → returns a `streamId`; opens the chunked HTTP or polling channel.
- `appendDelta(streamId, delta)` — merges streamed text into the recommendation panel state.
- `completeStream(streamId, finalPayload, reviewSignature)` — validates signature, transitions the recommendation to "ready to apply", populates the panel's final state.
- `errorStream(streamId, error)` — surfaces `WP_Error` through the existing `AIStatusNotice` component.
- `cancelStream(streamId)` — user-initiated cancel; calls a `DELETE /flavor-agent/v1/stream/{streamId}` endpoint and aborts the connection.

The existing per-surface store slices (`store/executable-surface-runtime.js`, `store/executable-surfaces.js`) stay unchanged. Streaming is layered: the content and template surfaces *use* the streaming runtime when available, fall through to today's request/response runtime otherwise. The review and apply slices don't care which path produced the payload.

UI delta:

- **`content/ContentRecommender.js`** — replaces the `loading` spinner with a live-rendered text view. Apply button remains disabled until `completeStream` fires.
- **`templates/TemplateRecommender.js`** — streams the `explanation` text into the suggestion preview area; the operations list is hidden until the final payload arrives, then materialized in one render.

No changes to `components/AIReviewSection.js`, `components/RecommendationLane.js`, `components/SurfaceComposer.js`, `components/AIActivitySection.js`, `components/InlineActionFeedback.js`. Streaming is a property of the fetch path, not the review/apply pipeline.

## What does NOT change

- The Abilities API endpoints (`flavor-agent/recommend-content`, `flavor-agent/recommend-template`) keep their existing request/response signatures. The streamed variant is **a separate ability** (e.g. `flavor-agent/recommend-content-stream`) or a query-arg switch on the existing one, so MCP tool registration is unaffected and non-streaming callers continue to work.
- `ResponseSchema`, `RecommendationReviewSignature`, `RecommendationResolvedSignature`, `StyleContrastValidator` — unchanged. Streaming consumers eventually feed the same final payload through these gates.
- The activity log — unchanged. The applied row is written after `completeStream` exactly as today.
- Connector Approvals, the `wp_ai_client_prevent_prompt` filter — unchanged. The streamed path still goes through `WordPressAIClient`, which still enforces these.

## What this does NOT solve

- **Streaming for ranked structured outputs.** Block/pattern/style/navigation surfaces stay one-shot. If a future redesign moves any of those to long-form prose output (unlikely), revisit.
- **Live agentic apply.** "User types a request, the editor edits as the model reasons" is a different product feature, not a streaming optimization. Outside this design.
- **Streaming for non-AI-Client backends.** The Cloudflare AI Search docs grounding path is not request/response generation; it's vector search. Not a streaming candidate.

## When to start building

**Not now.** Three reasons:

1. `php-ai-client` issue [#100](https://github.com/WordPress/php-ai-client/issues/100) milestoned for 1.4.0 with no fixed date. Building against a streaming API that doesn't exist forces design assumptions that almost certainly won't match what ships.
2. The 20 May summary explicitly tied streaming to the 7.1 cycle of the AI Plugin — that's downstream of php-ai-client 1.4.0. Two cycles of upstream movement need to settle.
3. The leadership transition noted in the gap audit (Felix and James stepping back) makes any "build ahead of the API" investment higher-risk; the strategic re-survey may reshape the streaming roadmap.

**Trigger condition to start (re-based 2026-07-18; `1.4.0` shipped 2026-07-15 without streaming):** [php-ai-client#255](https://github.com/WordPress/php-ai-client/pull/255) merges, streaming primitives ship in a tagged `php-ai-client` release, AND the AI plugin lands a streaming-enabled Experiment in trunk. At that point, return to this doc, confirm the assumed surface (`is_supported_for_text_streaming()`, dual-channel response shape, separate model interface, and how the change-inside-`Requests` direction constrains the transport options above), and commit a workstream.

**Hold posture until then:** track [#100](https://github.com/WordPress/php-ai-client/issues/100) and any AI plugin streaming PR; do NOT add `is_streaming_supported()`-style stubs to the codebase, because they'd commit Flavor Agent to a streaming contract that doesn't exist.

## Verification

- AI Client streaming status verified from [php-ai-client#100](https://github.com/WordPress/php-ai-client/issues/100) (open, milestone 1.4.0), [php-ai-client#166](https://github.com/WordPress/php-ai-client/issues/166) + [#170](https://github.com/WordPress/php-ai-client/pull/170) (scaffolding removed 2026-01-16), and [php-ai-client#237](https://github.com/WordPress/php-ai-client/issues/237) (WordPress HTTP buffering constraint, 2026-05-19).
- WP AI 7.1 streaming priority confirmed in the [20 May 2026 contributor summary](https://make.wordpress.org/ai/2026/05/21/ai-contributor-weekly-summary-20-may-2026/).
- Slip verified 2026-07-18: the `1.4.0` release tag (2026-07-15) contains the embedding-generation APIs ([#244](https://github.com/WordPress/php-ai-client/pull/244)) but no streaming; issue [#100](https://github.com/WordPress/php-ai-client/issues/100) remains open and the implementation is open PR [#255](https://github.com/WordPress/php-ai-client/pull/255) under review. `Requests`-library maintenance constraints and the change-lands-inside-`Requests` direction per the [2026-07-15 contributor summary](https://make.wordpress.org/ai/2026/07/17/ai-contributor-weekly-summary-15-july-2026/).
- `WordPressAIClient::chat()` signature verified at `inc/LLM/WordPressAIClient.php` lines 49–278.
- Review-signature gate behavior verified at `inc/Support/RecommendationSignature.php` and `inc/Support/RecommendationReviewSignature.php`.
- Recommendation ability schemas verified in `inc/Abilities/Registration.php` (`recommendation_output_schema()`).
- Cross-references the gap audit (`docs/reference/wp-ai-stack-gap-audit-2026-05-24.md`, item 5 in the prioritized list).
