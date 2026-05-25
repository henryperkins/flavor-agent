# Activity Log ↔ Core AI Request Logging Coexistence

**Status:** Shipped bridge design · **Date:** 2026-05-25 · **Driving issue:** `WordPress/ai#437` (merged, shipped in AI 1.0.0 on 2026-05-19)

## TL;DR

Core AI 1.0.0's Request Logging captures every AI Client HTTP request transparently via the SDK's HTTP transporter decorator. Flavor Agent's `Activity\Repository` captures applied editor changes (block_apply, template_apply, etc.) with undo. These are two different things at two different layers. The right design is **coexistence, not consolidation**:

1. **Enrich** core's Request Logging with Flavor Agent surface/scope/document context via the `wpai_request_log_context` filter so each `wpai_request_logs` row knows which Flavor Agent surface and editor scope produced the request.
2. **Stop persisting `request_diagnostic` rows** in `flavor_agent_activity` when core Request Logging is active — they become duplicative noise.
3. **Keep apply/undo rows** in `flavor_agent_activity` — Request Logging does not capture editor state transitions.
4. **Cross-link** the admin Activity page to the matching Request Logs row via the core `log_id` UUID, captured from `wpai_request_logged` and stored on Flavor Agent's apply rows.

This eliminates the duplication the audit flagged on 2026-05-24 without losing apply/undo data or the in-editor session history.

## Background

### What core Request Logging is

Per [WordPress/ai#437](https://github.com/WordPress/ai/pull/437) (architecture quoted from the PR description):

> Architecture uses the decorator pattern to wrap the SDK's HTTP transporter:
> - `Logging_Integration` wraps the transporter on `wp_loaded`/`admin_init` using the public `setHttpTransporter()` API
> - `Logging_Http_Transporter` decorates requests, capturing timing and delegating to `Log_Data_Extractor`
> - `Log_Data_Extractor` parses request/response payloads with 4 filter hooks for extensibility:
>   - `ai_request_log_providers` — customize provider detection
>   - `wpai_request_log_context` — filter context data
>   - `ai_request_log_tokens` — custom token extraction
>   - `ai_request_log_kind` — request type detection
> - `AI_Request_Log_Manager` coordinates schema, repository, and cost calculation

Schema (verified from [`includes/Logging/AI_Request_Log_Schema.php`](https://github.com/WordPress/ai/blob/trunk/includes/Logging/AI_Request_Log_Schema.php) at the time of writing):

```
wpai_request_logs:
  id                  BIGINT PK
  log_id              VARCHAR(36)   -- UUID; cross-system correlation handle
  timestamp           DATETIME
  type                VARCHAR(32)   -- request kind (text_generation, image_generation, etc.)
  operation           VARCHAR(255)  -- feature/operation name
  provider            VARCHAR(64)   -- 'openai', 'anthropic', etc.
  model               VARCHAR(128)
  duration_ms         INT
  tokens_input        INT
  tokens_output       INT
  tokens_total        INT
  status              VARCHAR(32)   -- success/error/...
  error_message       TEXT
  user_id             BIGINT
  context             LONGTEXT      -- arbitrary JSON; the wpai_request_log_context filter writes here
  request_preview     TEXT
  response_preview    TEXT
```

Indexed by `timestamp`, `type`, `status`, `user_id`, `log_id`, `provider`, `operation`, plus composite `(timestamp, type, status)` and `(timestamp, provider)`. Optional FULLTEXT index on `(operation, request_preview, response_preview)`.

Admin UI is at **Tools → AI Request Logs**, built with `@wordpress/dataviews` (same toolkit as Flavor Agent's admin Activity page).

### What Flavor Agent's Activity Repository is

`inc/Activity/Repository.php` is a schema-versioned (v4) custom table `flavor_agent_activity` that stores:

- **Apply rows** — block_apply, template_apply, template_part_apply, style_apply, global_styles_apply, style_book_apply, etc. Each row carries `before` / `after` state snapshots, an `undo` block with status + error + updatedAt, and a `target` (clientId / templateRef / blockPath / etc.).
- **Request diagnostic rows** — type `request_diagnostic`, emitted by `RecommendationAbilityExecution::persist_request_diagnostic_activity()` and `persist_request_diagnostic_failure_activity()` (lines 520 and 583 of that file). Carries the Flavor Agent request prompt, provider/model metadata from `WordPressAIClient::chat()`'s `requestMeta`, pipeline trace, drop reasons.
- **Docs grounding diagnostic rows** — emitted by `Cloudflare\AISearchClient` (line 1590). Different code path from the AI Client, but the same `type=request_diagnostic` shape.

90-day default retention, admin projection backfill, ordered undo, per-surface limits, full DataViews admin UI at `Settings → AI Activity` (`src/admin/activity-log.js`).

### Where the duplication lives

For any recommendation request that succeeds:

1. The AI Client makes an HTTP call. Core Request Logging (if active) writes a row to `wpai_request_logs` with provider, model, tokens, duration, status, user_id, context, and request/response previews.
2. `WordPressAIClient::chat()` returns; `RecommendationAbilityExecution` builds a payload and immediately calls `Activity\Repository::create()` with `type=request_diagnostic`, carrying essentially the same provider/model/latency metadata in the `request.ai` field.

If both are persisted, the same request is recorded twice with different shapes, and the admin has to look in two places to reconstruct what happened. The apply that follows (block_apply, template_apply, etc.) is *not* a duplicate — only the request_diagnostic row is.

## Design

### Three-layer model

```
┌─────────────────────────────────────────────────────────────────┐
│ Layer 3 — Flavor Agent Activity Repository                      │
│ flavor_agent_activity                                            │
│ - block_apply, template_apply, style_apply, navigation_apply,…   │
│ - Carries before/after state, undo journal, target, surface      │
│ - LINKS DOWN to Layer 1 via request.ai.requestLogId (the log UUID)│
└─────────────────────────────────────────────────────────────────┘
                              ▲
                              │ applied changes
                              │
┌─────────────────────────────────────────────────────────────────┐
│ Layer 2 — Flavor Agent surfaces                                  │
│ src/inspector/, src/templates/, src/global-styles/, …            │
│ - User reviews, accepts, applies a recommendation                │
└─────────────────────────────────────────────────────────────────┘
                              ▲
                              │ recommendation generated
                              │
┌─────────────────────────────────────────────────────────────────┐
│ Layer 1 — Core AI Request Log                                    │
│ wpai_request_logs                                                 │
│ - One row per AI Client HTTP call (chat() → wp_ai_client_prompt) │
│ - Captured TRANSPARENTLY by the SDK HTTP transporter decorator   │
│ - Enriched by Flavor Agent via wpai_request_log_context filter   │
└─────────────────────────────────────────────────────────────────┘
```

Layer 1 owns "what AI request happened, what did it cost, did it succeed."
Layer 3 owns "what change was applied to the editor, can I undo it, when."
Layer 2 is the recommendation surface that bridges them.

### Capability detection

A new `Activity\RequestLoggingBridge` helper (proposed new file: `inc/Activity/RequestLoggingBridge.php`) provides:

```php
public static function is_core_request_logging_available(): bool {
    return class_exists( '\WordPress\AI\Logging\AI_Request_Log_Schema' )
        && class_exists( '\WordPress\AI\Logging\AI_Request_Log_Manager' );
}

public static function is_core_request_logging_enabled(): bool {
    if ( ! self::is_core_request_logging_available() ) {
        return false;
    }
    // The Experiment-enabled flag follows the AI plugin's standard pattern.
    return (bool) apply_filters(
        'wpai_feature_ai-request-logging_enabled',
        (bool) get_option( 'wpai_feature_ai-request-logging_enabled', false )
    );
}
```

The shipped implementation also checks the master `wpai_features_enabled` option/filter before treating Request Logging as enabled.

### Context enrichment via `wpai_request_log_context`

Flavor Agent registers a filter that injects surface/scope/document/ability metadata into every Flavor Agent–originated AI request:

```php
// inc/Activity/RequestLoggingBridge.php (proposed)

add_filter( 'wpai_request_log_context', [ self::class, 'inject_flavor_agent_context' ], 10, 3 );

public static function inject_flavor_agent_context( array $context, array $decoded, array $log_data ): array {
    $tag = FlavorAgentRequestTag::current();
    if ( null === $tag ) {
        return $context;
    }

    $context['flavor_agent'] = [
        'surface'      => $tag->surface,
        'abilityName'  => $tag->ability_name,
        'scopeKey'     => $tag->scope_key,
        'documentRef'  => $tag->document_ref,
        'requestToken' => $tag->request_token,
        'pluginVersion'=> FLAVOR_AGENT_VERSION,
    ];

    return $context;
}
```

The `FlavorAgentRequestTag` is a request-scoped (per-PHP-process) value class that `RecommendationAbilityExecution` populates before calling `WordPressAIClient::chat()` and clears in a `finally`. This is the existing pattern Flavor Agent uses for `Support\RequestTrace::start()/finish()`; reuse the same idiom rather than inventing a new one.

### Capturing the core `log_id` back into Flavor Agent

Core Request Logging assigns a UUID `log_id` per request. Flavor Agent captures that UUID through the shipped `wpai_request_logged` action, which fires after the `wpai_request_logs` row persists and includes the inserted row data. `RequestLoggingBridge` maps the active Flavor Agent `requestToken` to that core UUID, and `RecommendationAbilityExecution` threads both values into `requestMeta` so later apply rows can persist `request.ai.requestLogId`.

### Stop persisting `request_diagnostic` rows when core logging is active

In `inc/Abilities/RecommendationAbilityExecution.php`, wrap the two `Activity\Repository::create()` calls (lines 550 and 599) with a capability check:

```php
if ( ! Activity\RequestLoggingBridge::is_core_request_logging_enabled() ) {
    Activity\Repository::create( [
        'type'    => 'request_diagnostic',
        // …existing payload…
    ] );
}
```

When core Request Logging is on, the AI request is already in `wpai_request_logs` with full Flavor Agent context (via the filter); the parallel `request_diagnostic` row adds no information. When core Request Logging is off (Experiment disabled or AI plugin missing), Flavor Agent continues to write its own diagnostic rows exactly as today — no behavior change.

The same wrap applies to `Cloudflare\AISearchClient::record_request_diagnostic()` (line 1590) — but docs grounding goes through Cloudflare's HTTP, **not** the AI Client transporter, so it is *not* automatically captured by core Request Logging. Decide separately:

- **Option A:** Keep emitting docs-grounding `request_diagnostic` rows always (since core Request Logging doesn't see them). This is the safest choice.
- **Option B:** Also forward docs-grounding events into `wpai_request_logs` by directly calling whatever public emit method core exposes (if any). Adds value, but creates a load-bearing dependency on core's public emit API which doesn't seem to exist as of PR #437 (the only documented integration is the decorator + filters, both of which sit at the SDK HTTP transporter layer).

**Recommend A** until core exposes a public emit hook usable from non-SDK code paths.

### Editor-inline activity panel — keep as-is

`src/components/AIActivitySection.js` and `src/components/ActivitySessionBootstrap.js` hydrate from `flavor_agent_activity` rows scoped to the current editor session. None of that changes. The session-scoped editor activity is specifically about applied changes (with undo affordances) — exactly what Layer 3 owns. Request Logging would never be the right backend for the in-editor history because it has no concept of editor scope or apply/undo state.

### Admin Activity page — add deep links

`Settings → AI Activity` (`src/admin/activity-log.js`) renders a row-detail action when `request.ai.requestLogId` is present:

- **Primary:** "View AI request" calls `GET /wp-json/ai/v1/logs/{uuid}` and renders provider, model, duration, tokens, and any request/response previews inline.
- **Secondary:** "Open in AI Request Logs" opens `tools.php?page=ai-request-logs` in a new tab.
- **Fallback:** if `request.ai.requestToken` exists but `request.ai.requestLogId` is empty, the detail panel says the AI request log is unavailable, which covers requests made while core logging was disabled.

The REST passthrough is necessary because the shipped AI Request Logs admin page does not expose a stable URL query shape for a specific `context.flavor_agent.*` filter.

### Schema deltas

No changes to `flavor_agent_activity` schema. The existing `request.ai` carrier can store `requestToken` and `requestLogId`; the bridge populates them from the core-assigned UUID captured through `wpai_request_logged`. SCHEMA_VERSION stays at 4.

### Settings story

The existing settings page now includes a read-only **AI Activity Storage** block in the AI Model section:

- **Not available:** Flavor Agent records request diagnostics in its own activity log and points users to WordPress AI 1.0.0+ for core request observability.
- **Available but disabled:** Flavor Agent keeps local request diagnostics and links to **Settings → AI** to enable the AI Request Logging experiment.
- **Enabled:** Flavor Agent forwards surface, scope, and document context into **Tools → AI Request Logs** and links there.

This makes the relationship visible without forcing the site owner to read this design doc.

## What this does *not* change

- Editor-side store (`@wordpress/data` `flavor-agent`) — unchanged. The store consumes Flavor Agent activity entries, not Request Log entries.
- Undo orchestration (`src/store/activity-undo.js`) — unchanged. Undo only operates on apply rows, which only live in `flavor_agent_activity`.
- Activity Permissions (`inc/Activity/Permissions.php`) — unchanged.
- Server-backed activity hydration on session bootstrap — unchanged.
- v4 schema, prune cron, admin projection backfill cron — all unchanged.

## Compatibility and fallback matrix

| AI plugin present | Request Logging experiment | Behavior |
|---|---|---|
| No | n/a | Flavor Agent writes request_diagnostic + apply rows. Unchanged from today. |
| Yes (≥1.0.0) | Off | Flavor Agent writes request_diagnostic + apply rows. Unchanged from today. |
| Yes (≥1.0.0) | On | Flavor Agent skips request_diagnostic, writes apply rows with `request.ai.requestLogId` = UUID. Core captures the AI request with Flavor Agent context attached via filter. |
| Yes, AI plugin downgraded mid-session | n/a → n/a | Bridge re-checks capability per request; behaves as the table above. No persistent state in Flavor Agent that depends on the bridge being on. |

## Open questions

1. **Cost data in Activity admin.** Core Request Logging includes cost-calculator data in its own admin surface. Flavor Agent's inline view currently limits itself to provider, model, duration, tokens, and previews.
2. **Docs-grounding forwarding.** Core Request Logging only captures AI Client HTTP traffic, so docs-grounding diagnostics remain local Flavor Agent activity rows unless upstream exposes a non-transporter emit API.
3. **`@wordpress/dataviews` version skew.** Both Flavor Agent's `src/admin/activity-log.js` and core's Request Logs use DataViews. Verify visual drift during release QA when both pages are active on the same install.

## Migration plan

1. **Phase 1 shipped:** `RequestLoggingBridge` and `FlavorAgentRequestTag` register `wpai_request_log_context` / `wpai_request_logged` and inject Flavor Agent context into core rows.
2. **Phase 2 shipped:** recommendation responses carry `requestToken` and `requestLogId`, and duplicate `request_diagnostic` rows are suppressed when core Request Logging is enabled.
3. **Phase 3 shipped:** Activity admin rows with `request.ai.requestLogId` can fetch and render the matching core log inline, with a secondary link to Tools → AI Request Logs.
4. **Phase 4 shipped:** Settings shows the AI Activity Storage read-only status and docs/status copy reflects the live bridge.

## Verification

- Code references in this doc point to verified file paths and line numbers as of 2026-05-25.
- Core Request Logging architecture summarized from [WordPress/ai#437](https://github.com/WordPress/ai/pull/437) (merged 2026-05-19 in AI 1.0.0).
- `wpai_request_logs` schema verified from [`AI_Request_Log_Schema.php`](https://github.com/WordPress/ai/blob/trunk/includes/Logging/AI_Request_Log_Schema.php) at the time of writing.
- The shipped hook names are `wpai_request_log_context` and `wpai_request_logged`; the experiment option is `wpai_feature_ai-request-logging_enabled` behind the master `wpai_features_enabled` gate.
- Cross-references the gap audit (`docs/reference/wp-ai-stack-gap-audit-2026-05-24.md`, item 2 in the prioritized list) and the roadmap tracking doc (`docs/reference/wordpress-ai-roadmap-tracking.md`, action implications #1 and #4).
