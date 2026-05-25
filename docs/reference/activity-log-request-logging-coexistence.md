# Activity Log ↔ Core AI Request Logging Coexistence

**Status:** Design proposal · **Date:** 2026-05-24 · **Driving issue:** `WordPress/ai#437` (merged, shipped in AI 1.0.0 on 2026-05-19)

## TL;DR

Core AI 1.0.0's Request Logging captures every AI Client HTTP request transparently via the SDK's HTTP transporter decorator. Flavor Agent's `Activity\Repository` captures applied editor changes (block_apply, template_apply, etc.) with undo. These are two different things at two different layers. The right design is **coexistence, not consolidation**:

1. **Enrich** core's Request Logging with Flavor Agent surface/scope/document context via the `ai_request_log_context` filter so each `wpai_request_logs` row knows which Flavor Agent surface and editor scope produced the request.
2. **Stop persisting `request_diagnostic` rows** in `flavor_agent_activity` when core Request Logging is active — they become duplicative noise.
3. **Keep apply/undo rows** in `flavor_agent_activity` — Request Logging does not capture editor state transitions.
4. **Cross-link** the admin Activity page to the matching Request Logs row via the core `log_id` UUID, captured from the AI Client response and stored on Flavor Agent's apply rows.

This eliminates the duplication the audit flagged on 2026-05-24 without losing apply/undo data or the in-editor session history.

## Background

### What core Request Logging is

Per [WordPress/ai#437](https://github.com/WordPress/ai/pull/437) (architecture quoted from the PR description):

> Architecture uses the decorator pattern to wrap the SDK's HTTP transporter:
> - `Logging_Integration` wraps the transporter on `wp_loaded`/`admin_init` using the public `setHttpTransporter()` API
> - `Logging_Http_Transporter` decorates requests, capturing timing and delegating to `Log_Data_Extractor`
> - `Log_Data_Extractor` parses request/response payloads with 4 filter hooks for extensibility:
>   - `ai_request_log_providers` — customize provider detection
>   - `ai_request_log_context` — filter context data
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
  context             LONGTEXT      -- arbitrary JSON; the ai_request_log_context filter writes here
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
│ - Enriched by Flavor Agent via ai_request_log_context filter     │
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
    // Confirm exact option key from the shipped 1.0.0 build before implementing.
    return (bool) apply_filters(
        'wpai_experiment_request_logging_enabled',
        (bool) get_option( 'wpai_experiment_request_logging_enabled', false )
    );
}
```

The `is_*_enabled()` exact option key needs to be verified against shipped AI 1.0.0 — the contract in the audit's "Action implications" #2 (in `wordpress-ai-roadmap-tracking.md`) should follow it for consistency.

### Context enrichment via `ai_request_log_context`

Flavor Agent registers a filter that injects surface/scope/document/ability metadata into every Flavor Agent–originated AI request:

```php
// inc/Activity/RequestLoggingBridge.php (proposed)

add_filter( 'ai_request_log_context', [ self::class, 'inject_flavor_agent_context' ], 10, 2 );

public static function inject_flavor_agent_context( array $context, array $request ): array {
    $tag = FlavorAgentRequestTag::current();
    if ( null === $tag ) {
        return $context;
    }

    $context['flavor_agent'] = [
        'surface'      => $tag->surface,
        'abilityName'  => $tag->ability_name,
        'scopeKey'     => $tag->scope_key,
        'documentRef'  => $tag->document_ref,
        'requestRef'   => $tag->request_reference,
        'pluginVersion'=> FLAVOR_AGENT_VERSION,
    ];

    return $context;
}
```

The `FlavorAgentRequestTag` is a request-scoped (per-PHP-process) value class that `RecommendationAbilityExecution` populates before calling `WordPressAIClient::chat()` and clears in a `finally`. This is the existing pattern Flavor Agent uses for `Support\RequestTrace::start()/finish()`; reuse the same idiom rather than inventing a new one.

### Capturing the core `log_id` back into Flavor Agent

Core Request Logging assigns a UUID `log_id` per request. Flavor Agent needs that UUID to link from its apply rows back to the matching Request Log row.

Two options:

**(a) Read it from a transient/static after the chat returns.** Core Request Logging would have to expose either a filter that fires with the assigned `log_id` after persistence, or a static accessor like `AI_Request_Log_Manager::last_log_id_for_current_request()`. **Not currently part of the public API as documented in PR #437.** Track this — if it doesn't exist yet, it's worth a small upstream issue.

**(b) Have Flavor Agent generate the UUID itself**, attach it to the `ai_request_log_context` payload as `context.flavor_agent.requestRef` (already part of the proposed shape), and also include it in the apply row's `request.ai.requestLogId`. Then admin links go from Flavor Agent's apply row → Request Log filter on `context->flavor_agent.requestRef`. This works today without any upstream change.

**Recommend (b).** It's a one-line UUID generation in `RecommendationAbilityExecution`, and the cross-link is a `JSON_EXTRACT(context, '$.flavor_agent.requestRef') = ?` lookup against `wpai_request_logs`. It also degrades gracefully — if core Request Logging is disabled, the `requestRef` is still useful as Flavor Agent's own diagnostic handle (the field already exists today).

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

`Settings → AI Activity` (`src/admin/activity-log.js`) should gain a column or row-detail action that opens **Tools → AI Request Logs** filtered by the matching `requestRef`. Two implementation paths:

- **Direct URL with query args** — `admin.php?page=ai-request-logs&context_filter=flavor_agent.requestRef:UUID`. Requires core Request Logs admin to support this query-arg shape. Likely works since the page is DataViews-based and DataViews supports URL-encoded filters.
- **REST passthrough** — Flavor Agent's admin page calls core's Request Logs REST endpoint to fetch the matching row and renders the cost / token / preview inline. Heavier; defer until there's a UX justification.

**Recommend direct URL.** Cross-page navigation is the right separation; embedding core's data inside Flavor Agent's page would create coupling.

### Schema deltas

No changes to `flavor_agent_activity` schema. The existing `request.ai.requestLogId` and `request.reference` fields already exist (see `RecommendationAbilityExecution::persist_request_diagnostic_activity()`); the bridge just populates them with the UUID that's also pushed into `ai_request_log_context`. SCHEMA_VERSION stays at 4.

### Settings story

A new "AI Activity" sub-section in `Settings → Flavor Agent → AI Activity` (or a row in the existing settings page) that shows:

- "Core AI Request Logging: **Enabled** [link to Tools → AI Request Logs]"  ←when active
- "Core AI Request Logging: **Disabled.** Flavor Agent is keeping its own request diagnostics. To enable richer cost tracking and provider observability, turn on the Request Logging experiment in **Settings → AI**." ← when inactive
- Always: "Editor applies and undo journal stay in `Settings → AI Activity` regardless."

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

1. **Exact Experiment option key.** Verify `wpai_experiment_request_logging_enabled` against the shipped AI 1.0.0 build. The PR description didn't pin the option name; the implementation may use a different key.
2. **Does core expose a `log_id` accessor or filter after persistence?** Option (b) above sidesteps the need, but if a clean accessor lands, the design can become slightly simpler (Flavor Agent stops generating its own UUID and reads the core-assigned one).
3. **Cost data in Activity admin.** Core Request Logging includes `AI_Request_Cost_Calculator` results. Worth surfacing in Flavor Agent's admin Activity row detail? Probably not in v1 — keep the cross-link and let users follow the Tools → AI Request Logs link for cost.
4. **Docs-grounding forwarding.** Confirm whether core exposes any non-transporter emit path before considering Option B in the docs-grounding section above.
5. **`@wordpress/dataviews` version skew.** Both Flavor Agent's `src/admin/activity-log.js` and core's Request Logs use DataViews. Verify which version each bundles — if core's WP 7.0 build pulls a newer DataViews than Flavor Agent's `package.json` declares, there could be styling drift on a site that has both pages open.

## Migration plan

1. **Land the bridge helper** (`inc/Activity/RequestLoggingBridge.php`) with capability detection. No behavior change yet.
2. **Wire the `ai_request_log_context` filter** to inject Flavor Agent context. Verify in a local stack by triggering a recommendation and inspecting a `wpai_request_logs` row — `context.flavor_agent.surface` should appear.
3. **Wrap the two `request_diagnostic` `create()` calls** with the capability check.
4. **Add the requestRef UUID generation** in `RecommendationAbilityExecution` and thread it into both the activity row's `request.ai.requestLogId` and the context filter payload.
5. **Add the admin cross-link** in `src/admin/activity-log.js` (link out when `requestLogId` is present and core Request Logging is enabled).
6. **Add the settings sub-section** explaining the relationship.
7. **PHPUnit:** `RequestLoggingBridgeTest` for capability detection (all four matrix rows above) + `RecommendationAbilityExecutionTest` regression for the conditional skip.
8. **E2E (playground):** smoke that a triggered recommendation produces exactly one `wpai_request_logs` row (when enabled) and zero `flavor_agent_activity` request_diagnostic rows.

## Verification

- Code references in this doc point to verified file paths and line numbers as of 2026-05-24.
- Core Request Logging architecture summarized from [WordPress/ai#437](https://github.com/WordPress/ai/pull/437) (merged 2026-05-19 in AI 1.0.0).
- `wpai_request_logs` schema verified from [`AI_Request_Log_Schema.php`](https://github.com/WordPress/ai/blob/trunk/includes/Logging/AI_Request_Log_Schema.php) at the time of writing.
- Cross-references the gap audit (`docs/reference/wp-ai-stack-gap-audit-2026-05-24.md`, items 2 in the prioritized list) and the roadmap tracking doc (`docs/reference/wordpress-ai-roadmap-tracking.md`, action implications #1 and #4).
