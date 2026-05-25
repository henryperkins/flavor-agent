# Request Logging Bridge — Implementation Plan

**Status:** Implemented · **Date:** 2026-05-25 · **Pinned against:** WordPress/ai `1.0.0` (tag SHA `57846e17948088265a78d438f6f0a6c6e7b141e0`) · **Parent design:** [`activity-log-request-logging-coexistence.md`](activity-log-request-logging-coexistence.md)

## What changed since the design doc

The discovery pass against the shipped 1.0.0 source code answered the five open questions in the design doc, and one answer materially simplifies the implementation. Two original assumptions need correction:

1. **There IS a public post-write action.** `do_action( 'wpai_request_logged', $log_id, $insert_data )` fires in `AI_Request_Log_Repository::insert()` after the row persists. This eliminates the need for Flavor Agent to generate its own UUID (Option (b) in the design's "Capturing the core log_id back into Flavor Agent" section). Use the core-assigned UUID via that action.
2. **`context.source` is already auto-populated.** The `Logging_Http_Transporter::detect_request_source()` walks `debug_backtrace()`, skips infrastructure frames (the Logging directory, `/vendor/`, the AI Client wrappers, and connector provider plugins), and writes `context.source = { type: 'plugin', slug: 'flavor-agent', name: 'flavor-agent', file: 'flavor-agent/inc/...' }` to every Flavor Agent–originated AI Client call. Flavor Agent does NOT need to provide plugin attribution — only the surface/scope/document/ability metadata that core can't infer.

The rest of the design (coexistence over consolidation, suppress duplicative `request_diagnostic` rows when core logging is on, keep apply/undo rows local, deep-link the admin Activity page into Tools → AI Request Logs) is unchanged.

## Pinned upstream API surface

All references are against the 1.0.0 tag (SHA `57846e17948088265a78d438f6f0a6c6e7b141e0`).

### Experiment enablement
- **Class:** [`WordPress\AI\Experiments\AI_Request_Logging\AI_Request_Logging`](https://github.com/WordPress/ai/blob/1.0.0/includes/Experiments/AI_Request_Logging/AI_Request_Logging.php) extends `Abstract_Feature`.
- **Experiment ID:** `ai-request-logging`.
- **Option key (canonical):** `wpai_feature_ai-request-logging_enabled` (matches the convention Flavor Agent already follows for `wpai_feature_flavor-agent_enabled` at `inc/AI/FeatureBootstrap.php` line 60).
- **Filter (canonical):** `wpai_feature_ai-request-logging_enabled` (same name, applied to the option value).
- **Master gate:** `wpai_features_enabled` option + filter (controls whether ANY experiment runs; same pattern Flavor Agent already gates on).
- **Capability:** the experiment metadata declares `'capability' => 'none'`, so it doesn't add its own capability check — relies on the user being able to toggle the experiment in Settings → AI.

### Context-enrichment filter
- **Hook:** `wpai_request_log_context`.
- **Signature:** `(array $context, array $decoded, array $log_data) -> array`.
  - `$context` arrives populated with: `url`, `method`, `request_kind`, `input_preview`, optional `output_preview`, optional `media_*`, AND `source = { type, slug, name, file }`.
  - `$decoded` is the full JSON-decoded response body.
  - `$log_data` is the full row: `type=ai_client`, `operation`, `provider`, `model`, `context`, `tokens_input`, `tokens_output`.
- **Fires:** per response, BEFORE persistence, in [`Log_Data_Extractor::extract_response_data()`](https://github.com/WordPress/ai/blob/1.0.0/includes/Logging/Log_Data_Extractor.php#L155).
- **Persistence:** the return is cast to `(array)` and stored as `wp_json_encode($context)` in `wpai_request_logs.context` (LONGTEXT).

### Post-write action — the log_id channel
- **Hook:** `wpai_request_logged`.
- **Signature:** `(string $log_id, array $insert_data) -> void`.
- **Fires:** AFTER `$wpdb->insert()` succeeds, in [`AI_Request_Log_Repository::insert()`](https://github.com/WordPress/ai/blob/1.0.0/includes/Logging/AI_Request_Log_Repository.php#L113).
- **`$log_id`:** the `wp_generate_uuid4()` value the repository assigned.
- **`$insert_data`:** the full row including json-encoded `context` (so the Flavor Agent fields injected by the context filter are readable here).

### Admin and REST surface
- **Admin URL:** `admin_url( 'tools.php?page=ai-request-logs' )`.
- **Capability:** `manage_options`.
- **Single-log REST endpoint:** `GET /wp-json/ai/v1/logs/{uuid}` (regex `[a-f0-9\-]+`, validated with `wp_is_uuid`). Returns the formatted log row including the decoded `context`.
- **List REST endpoint:** `GET /wp-json/ai/v1/logs` with filter args `type`, `status`, `provider`, `operation`, `tokens_filter`, `user_id`, `date_from`, `date_to`, `search`, `page`, `per_page`, `orderby`, `order`, cursor args.
- **Important constraint:** no native URL-arg filter for `context.*`. The admin page mounts a React root that doesn't currently hydrate filters from query strings. Deep-linking to a *specific* row is therefore via REST passthrough (fetch one row, render inline) rather than a query-arg link.

## Architecture (revised)

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Layer 3 — Flavor Agent Activity Repository (flavor_agent_activity)        │
│   block_apply / template_apply / style_apply / … rows                     │
│   Carries: request.ai.requestLogId (NEW: the core-assigned UUID)          │
│   Admin page deep-links via GET /ai/v1/logs/{uuid} REST passthrough       │
└──────────────────────────────────────────────────────────────────────────┘
                              ▲
                              │ apply step writes activity row
                              │ (looks up stashed log_id by request tag)
                              │
┌──────────────────────────────────────────────────────────────────────────┐
│ Layer 2 — Flavor Agent surfaces                                           │
│   RecommendationAbilityExecution::execute()                                │
│     1. RequestTag::start(surface, scopeKey, abilityName, requestToken)     │
│     2. WordPressAIClient::chat() → triggers AI Client HTTP                │
│        → wpai_request_log_context filter injects flavor_agent.* into ctx  │
│        → wpai_request_logged action records (request_token → log_id)      │
│     3. Apply (if not advisory)                                             │
│     4. RequestTag::finish()                                                │
└──────────────────────────────────────────────────────────────────────────┘
                              ▲
                              │ HTTP transporter → SDK
                              │
┌──────────────────────────────────────────────────────────────────────────┐
│ Layer 1 — Core AI Request Log (wpai_request_logs)                          │
│   One row per HTTP call, captured transparently                            │
│   context.source = { slug: 'flavor-agent', ... }  (AUTO, free)            │
│   context.flavor_agent = { surface, scopeKey, abilityName, requestToken }  │
└──────────────────────────────────────────────────────────────────────────┘
```

## Files to add or modify

### New files
- **`inc/Activity/RequestLoggingBridge.php`** — capability detector + filter/action registrations.
- **`inc/Support/FlavorAgentRequestTag.php`** — per-request scope-key carrier (mirrors the pattern of `Support\RequestTrace`).
- **`tests/phpunit/RequestLoggingBridgeTest.php`** — unit coverage for the four-state compatibility matrix.
- **`tests/phpunit/FlavorAgentRequestTagTest.php`** — start/finish lifecycle.

### Modified files
- **`flavor-agent.php`** — register the bridge bootstrap on `init` priority 5 (same neighborhood as `Activity\Repository::maybe_install`). _Touched in Phase 1._
- **`inc/Abilities/RecommendationAbilityExecution.php`** — Phase 1 adds only the minimal `FlavorAgentRequestTag::start()` / `finish()` wrapper around the existing `WordPressAIClient::chat()` call so the context filter has a tag to read. Phase 2 wraps the two `Activity\Repository::create()` calls (lines 550 and 599) with `RequestLoggingBridge::should_persist_request_diagnostic()` and threads the captured `log_id` into `request.ai.requestLogId` on apply rows. _Touched in both phases; Phase 1 changes are a strict subset of Phase 2._
- **`inc/LLM/WordPressAIClient.php`** — no functional change; emit one `RequestTrace::event( 'ai.chat.log_id_captured', [ 'logId' => ... ] )` for diagnostic continuity when the bridge captures a log_id.
- **`inc/Cloudflare/AISearchClient.php`** — no change (docs grounding is not on the AI Client transporter; the `request_diagnostic` row remains the only record for that path).
- **`src/admin/activity-log.js`** + **`src/admin/activity-log-utils.js`** — render a "View AI request log" affordance on activity rows that carry `request.ai.requestLogId`; on click, fetch `GET /wp-json/ai/v1/logs/{uuid}` and render the core row's `provider`, `model`, `duration_ms`, `tokens_*`, `request_preview`, `response_preview` inline; secondary link button opens `tools.php?page=ai-request-logs` so the user can browse from there.
- **`inc/Admin/Settings/Page.php`** (or wherever the AI Activity sub-section sits) — add a one-line status: "Core AI Request Logging: Enabled — full request observability available at Tools → AI Request Logs" or "Disabled — Flavor Agent is keeping its own request diagnostics. To enable cost/token tracking, turn on the AI Request Logging experiment in Settings → AI."

## RequestLoggingBridge — implementation skeleton

```php
<?php
namespace FlavorAgent\Activity;

use FlavorAgent\Support\FlavorAgentRequestTag;

final class RequestLoggingBridge {

    private const EXPERIMENT_OPTION = 'wpai_feature_ai-request-logging_enabled';
    private const MASTER_OPTION     = 'wpai_features_enabled';

    /** Map of Flavor Agent request_token => core log_id, populated per-request. */
    private static array $captured_log_ids = [];

    public static function register(): void {
        if ( ! self::is_core_logging_class_available() ) {
            return;
        }
        add_filter( 'wpai_request_log_context', [ self::class, 'inject_flavor_agent_context' ], 10, 3 );
        add_action( 'wpai_request_logged',      [ self::class, 'capture_log_id' ], 10, 2 );
    }

    public static function is_core_logging_class_available(): bool {
        return class_exists( '\WordPress\AI\Logging\AI_Request_Log_Manager' )
            && class_exists( '\WordPress\AI\Experiments\AI_Request_Logging\AI_Request_Logging' );
    }

    public static function is_core_logging_enabled(): bool {
        if ( ! self::is_core_logging_class_available() ) {
            return false;
        }
        $master = (bool) apply_filters( self::MASTER_OPTION,     (bool) get_option( self::MASTER_OPTION, false ) );
        if ( ! $master ) {
            return false;
        }
        // The AI plugin filter name uses a hyphen; phpcs WordPress.NamingConventions.ValidHookName.UseUnderscores
        // ignore is fine here because we're matching upstream contract.
        return (bool) apply_filters( self::EXPERIMENT_OPTION, (bool) get_option( self::EXPERIMENT_OPTION, false ) );
    }

    /**
     * Whether Flavor Agent should still write its own request_diagnostic rows.
     * Returns false when core logging is active AND the bridge can forward context.
     */
    public static function should_persist_request_diagnostic(): bool {
        if ( ! self::is_core_logging_enabled() ) {
            return true; // core off → keep our own diagnostic
        }
        return (bool) apply_filters( 'flavor_agent_persist_request_diagnostic_with_core_logging', false );
    }

    public static function inject_flavor_agent_context( array $context, array $decoded, array $log_data ): array {
        // Defensive: confirm this is actually a Flavor Agent request.
        // Core auto-populates context.source via debug_backtrace, so we don't need to attribute,
        // but we also don't want to leak the tag onto a non-FA call that happened in the same process.
        $source_slug = (string) ( $context['source']['slug'] ?? '' );
        if ( 'flavor-agent' !== $source_slug ) {
            return $context;
        }
        $tag = FlavorAgentRequestTag::current();
        if ( null === $tag ) {
            return $context;
        }
        $context['flavor_agent'] = [
            'surface'       => $tag->surface,
            'abilityName'   => $tag->ability_name,
            'scopeKey'      => $tag->scope_key,
            'documentRef'   => $tag->document_ref,
            'requestToken'  => $tag->request_token,
            'pluginVersion' => defined( 'FLAVOR_AGENT_VERSION' ) ? FLAVOR_AGENT_VERSION : 'unknown',
        ];
        return $context;
    }

    public static function capture_log_id( string $log_id, array $insert_data ): void {
        $context = $insert_data['context'] ?? null;
        if ( is_string( $context ) ) {
            $context = json_decode( $context, true );
        }
        if ( ! is_array( $context ) ) {
            return;
        }
        $fa = $context['flavor_agent'] ?? null;
        if ( ! is_array( $fa ) ) {
            return;
        }
        $request_token = (string) ( $fa['requestToken'] ?? '' );
        if ( '' === $request_token ) {
            return;
        }
        self::$captured_log_ids[ $request_token ] = $log_id;
    }

    public static function consume_log_id( string $request_token ): ?string {
        if ( ! isset( self::$captured_log_ids[ $request_token ] ) ) {
            return null;
        }
        $log_id = self::$captured_log_ids[ $request_token ];
        unset( self::$captured_log_ids[ $request_token ] );
        return $log_id;
    }
}
```

The `$captured_log_ids` static is intentionally bounded by `consume_log_id()` removing entries. Cap defensively at, say, 50 entries with FIFO eviction so a bug that leaks tokens can't grow unbounded — add this to the spike PR.

## FlavorAgentRequestTag — minimal carrier

```php
<?php
namespace FlavorAgent\Support;

final class FlavorAgentRequestTag {
    private string $surface;
    private string $ability_name;
    private string $scope_key;
    private array $document_ref;
    private string $request_token;

    private static ?self $current = null;

    public function __construct(
        string $surface,
        string $ability_name,
        string $scope_key,
        array $document_ref,
        string $request_token
    ) {
        $this->surface       = $surface;
        $this->ability_name  = $ability_name;
        $this->scope_key     = $scope_key;
        $this->document_ref  = $document_ref;
        $this->request_token = $request_token;
    }

    public static function start( self $tag ): void { self::$current = $tag; }
    public static function current(): ?self        { return self::$current; }
    public static function finish(): void          { self::$current = null; }

    public function surface(): string       { return $this->surface; }
    public function ability_name(): string  { return $this->ability_name; }
    public function scope_key(): string     { return $this->scope_key; }
    public function document_ref(): array   { return $this->document_ref; }
    public function request_token(): string { return $this->request_token; }
}
```

Use explicit private properties plus getters so the class stays compatible with Flavor Agent's declared PHP 8.0 floor. Constructor property promotion is available in PHP 8.0, but `readonly` properties are PHP 8.1+ and must not be used unless the plugin raises `Requires PHP`.

`$request_token` is generated per ability execution — `wp_generate_uuid4()` is fine, or `bin2hex( random_bytes( 8 ) )` for a 16-char token, the only requirement is uniqueness within the PHP process.

## RecommendationAbilityExecution wiring

Two changes inside the executor's existing flow (`inc/Abilities/RecommendationAbilityExecution.php`):

```php
// Pseudocode — exact location at the chat() call site
$request_token = wp_generate_uuid4();
$tag = new FlavorAgentRequestTag(
    surface:       $surface,
    ability_name:  $ability_id,
    scope_key:     (string) ( $document['scopeKey'] ?? '' ),
    document_ref:  $document ?? [],
    request_token: $request_token,
);
FlavorAgentRequestTag::start( $tag );

try {
    $chat_result = WordPressAIClient::chat( $system_prompt, $user_prompt, … );
} finally {
    FlavorAgentRequestTag::finish();
}

// At the existing persist_request_diagnostic_activity() and persist_request_diagnostic_failure_activity()
// call sites (lines 550 and 599), gate the create() call:
if ( RequestLoggingBridge::should_persist_request_diagnostic() ) {
    ActivityRepository::create( [
        'type' => 'request_diagnostic',
        // … existing payload …
    ] );
}

// At the apply step that writes the apply row (block_apply, template_apply, …),
// thread the captured log_id into the activity payload:
$log_id = RequestLoggingBridge::consume_log_id( $request_token );
$payload['request']['ai']['requestLogId'] = $log_id ?? '';
$payload['request']['ai']['requestToken'] = $request_token;
ActivityRepository::create( $payload );
```

The apply step's exact location varies per surface (block applies happen via JS in the editor, template applies in `Agent_Controller`, etc.). For surfaces where the apply happens client-side over REST, the `request_token` and `log_id` must round-trip through the editor: include them in the ability's response payload, and have the JS store pass them back when it calls the activity persistence route. The current `request.ai` field already supports this carrier shape (see `inc/Abilities/RecommendationAbilityExecution.php` lines 560–564) — we extend `requestMeta` to include `requestToken` and the apply REST writer reads `requestLogId` from the captured map at apply-time.

## Admin Activity page cross-link UI

`src/admin/activity-log-utils.js` already formats rows. Add:

- A column or row-detail field "AI request log" that shows when `entry.request?.ai?.requestLogId` is non-empty.
- The value is rendered as a Button group:
  - **Primary**: "View AI request" → fetches `GET /wp-json/ai/v1/logs/{uuid}` and renders provider, model, duration, tokens, request/response previews in a modal or expanded row.
  - **Secondary**: "Open in AI Request Logs" → opens `admin.php?page=ai-request-logs` in a new tab so the user can browse from there.
- When `request.ai.requestLogId` is empty but `request.ai.requestToken` is present, render "AI request log unavailable (core logging may have been disabled at request time)" — the bridge couldn't capture an ID.
- When core Request Logging is not enabled at all, hide the column entirely (capability-flag this from server bootstrap data).

REST passthrough is exempt from CORS / nonce issues because it's same-origin admin-side; reuse the existing admin REST nonce (`wpApiSettings.nonce`).

## Settings sub-section

In the Flavor Agent settings page (`inc/Admin/Settings/Page.php` or `Registrar.php`), add a static read-only section "AI Activity Storage" with these states:

| Core logging | Display |
|---|---|
| Not available (AI plugin < 1.0 or missing) | "Flavor Agent records request diagnostics in its own activity log. Upgrade to WordPress AI 1.0.0+ to access core AI request observability." |
| Available but disabled | "Flavor Agent is recording request diagnostics in its own activity log. Enable the AI Request Logging experiment in Settings → AI to also capture provider, model, token, and cost data centrally." (Link out to Settings → AI.) |
| Enabled | "AI Request Logging is enabled. Flavor Agent forwards surface, scope, and document context into each Tools → AI Request Logs row." (Link out to Tools → AI Request Logs.) |

No new settings; pure status display. The state is computed from `RequestLoggingBridge::is_core_logging_enabled()` and `::is_core_logging_class_available()`.

## Phase breakdown

### Phase 1 — Capability + observability + minimal tag wiring (no user-visible behavior change)
**Files:** `inc/Activity/RequestLoggingBridge.php` (new), `inc/Support/FlavorAgentRequestTag.php` (new), `inc/Abilities/RecommendationAbilityExecution.php` (minimal tag start/finish only), `tests/phpunit/RequestLoggingBridgeTest.php` (new), `tests/phpunit/FlavorAgentRequestTagTest.php` (new), `tests/phpunit/RecommendationAbilityExecutionTagTest.php` (new), `flavor-agent.php` (register).

- Add `RequestLoggingBridge` class with capability detection, context-injection filter, and log_id capture action.
- Add `FlavorAgentRequestTag` carrier.
- Hook `wpai_request_log_context` (inject) and `wpai_request_logged` (capture).
- **In `RecommendationAbilityExecution.php`, add the minimal tag wiring around the chat call only**: build a `FlavorAgentRequestTag` from `(surface, ability_name, scope_key, document_ref, request_token = wp_generate_uuid4())`, `start()` it before `WordPressAIClient::chat()`, `finish()` it in a `finally` block. This is the smallest possible change that makes the gate achievable.
- **Explicitly NOT in Phase 1:** do NOT wrap the two `Activity\Repository::create()` calls with the suppression gate; do NOT thread `request_token` or captured `log_id` into the response payload or apply rows; do NOT add the transient store. Those all land in Phase 2.

Why the tag wiring belongs here, not in Phase 2: without it, `FlavorAgentRequestTag::current()` returns `null` inside the filter callback, so no `flavor_agent.*` keys ever land in `wpai_request_logs.context`, and the gate below cannot be verified. The tag wiring is the minimum behavior needed for the gate.

**Gate:** A local stack with AI plugin 1.0.0 and Request Logging enabled shows `context.flavor_agent.{surface, scopeKey, abilityName, requestToken, pluginVersion}` on every `wpai_request_logs` row triggered by a Flavor Agent recommendation. With the experiment disabled, no rows are written to `wpai_request_logs` (already true upstream) and `flavor_agent_activity` continues to receive `request_diagnostic` rows exactly as today.

**PHPUnit:** `RequestLoggingBridgeTest` exercises the four-state matrix:
1. AI plugin missing → `is_core_logging_class_available() === false`, `should_persist_request_diagnostic() === true`.
2. AI plugin present, experiment disabled → `is_core_logging_enabled() === false`, `should_persist_request_diagnostic() === true`.
3. AI plugin present, experiment enabled → `is_core_logging_enabled() === true`, `should_persist_request_diagnostic() === false`.
4. With the `flavor_agent_persist_request_diagnostic_with_core_logging` filter forced true, `should_persist_request_diagnostic() === true` even when core logging is on.

Plus filter and action coverage:
- `inject_flavor_agent_context()` returns `$context` unchanged when `FlavorAgentRequestTag::current()` is `null`.
- `inject_flavor_agent_context()` returns `$context` unchanged when `context.source.slug !== 'flavor-agent'` (defensive cross-pollution guard).
- `inject_flavor_agent_context()` adds the `flavor_agent` key with the expected shape when both gates pass.
- `capture_log_id()` populates the `$captured_log_ids` map keyed by `requestToken` and `consume_log_id()` removes the entry.
- The capture map enforces FIFO eviction at the configured cap (defend against leaked tokens).

**PHPUnit:** `FlavorAgentRequestTagTest` covers start → current → finish lifecycle, clearing on exception, and that nested start calls reflect the innermost tag (or document the chosen semantics if nesting is disallowed).

**PHPUnit:** `RecommendationAbilityExecutionTagTest` confirms the tag is active inside `chat()` and cleared after, including the exception path. This is a tight focused test on the tag wiring only; the broader execution behavior (`RecommendationAbilityExecutionTest`) is unchanged in Phase 1.

**E2E (manual or playground):** trigger one recommendation per surface against a 1.0.0 stack with Request Logging enabled, then read the latest `wpai_request_logs` row via `GET /wp-json/ai/v1/logs` and assert `context.flavor_agent.surface` matches and `context.flavor_agent.requestToken` is a UUID.

### Phase 2 — Suppress duplicative writes + capture log_id into apply rows
**Files:** `inc/Abilities/RecommendationAbilityExecution.php`, `tests/phpunit/RecommendationAbilityExecutionTest.php`.

- Wrap the two `Activity\Repository::create()` calls at lines 550 and 599 with `RequestLoggingBridge::should_persist_request_diagnostic()`.
- Surface the `request_token` (and, when available in the same request, the captured `log_id`) in the recommendation response's `requestMeta` so the editor can round-trip them to the apply step.
- For abilities that complete in a single PHP request (template/style/block server-side preview paths), the captured `log_id` is consumed in the same request and threaded into the response's `requestMeta`. The editor then stores it on the apply row when applying.
- For editor-driven applies (the user explicitly clicks Apply later), the `request_token` is part of the recommendation payload returned to the editor; the apply REST writer reads the matching `log_id` from a transient (TTL 5 min, defensible to raise to `HOUR_IN_SECONDS` after QA) keyed by token. The Phase 2 PR is the right place to introduce the transient store — minimal footprint, no schema.

**Gate:** Replaying a recommendation request → apply sequence with core logging enabled produces exactly one row in `wpai_request_logs` and one apply row in `flavor_agent_activity` with `request.ai.requestLogId` populated. Replaying with core logging disabled produces one `request_diagnostic` row and one apply row in `flavor_agent_activity` (today's behavior).

**PHPUnit:** regression test on `RecommendationAbilityExecution` proving the conditional skip of `request_diagnostic` rows and the threading of `request_token` / `requestLogId` into the response payload.
**E2E (playground smoke):** trigger a recommendation, assert exactly one `wpai_request_logs` row appears with `context.flavor_agent.surface` set (when enabled) and zero `request_diagnostic` rows in `flavor_agent_activity` for that request.

### Phase 3 — Admin cross-link
**Files:** `src/admin/activity-log.js`, `src/admin/activity-log-utils.js`, `src/admin/__tests__/activity-log-utils.test.js`.

- Add the "View AI request" inline expansion and "Open in AI Request Logs" link.
- Capability-flag the column on bootstrap data (Settings page localizes whether core logging is enabled).

**Gate:** Manual QA: clicking "View AI request" on a Flavor Agent activity row renders the core log fields without leaving the page.

### Phase 4 — Settings sub-section + docs
**Files:** `inc/Admin/Settings/Page.php` (or sibling), `docs/reference/activity-log-request-logging-coexistence.md` (update from "design" to "shipped"), `docs/reference/wordpress-ai-roadmap-tracking.md` (mark action implications #1 and #4 with the workstream marker), `STATUS.md` (move the Open Backlog bullet under Working).

## Rollback plan

The bridge is purely additive at each phase:

- Phase 1 adds new files, registers filter/action subscribers, and adds a small `try { …chat… } finally { FlavorAgentRequestTag::finish(); }` wrapper around the existing `WordPressAIClient::chat()` call in `RecommendationAbilityExecution.php`. The tag is process-local and has no persistence; removing the `flavor-agent.php` bootstrap call disables the bridge entirely, and reverting the `RecommendationAbilityExecution.php` hunk restores today's exact behavior. No schema or settings change.
- Phase 2 introduces a one-line gate around two `Repository::create()` calls and threads `request_token` / `requestLogId` into the response payload and apply rows. Reverting the conditional and the response-payload threading restores today's behavior. The new fields on activity payloads are ignored by older `activity-log.js` builds; no schema change required.
- Phase 3 is JS-only and degrades to "no inline expansion" if the REST call fails.
- Phase 4 is settings copy.

A site that disables the AI plugin entirely degrades to today's behavior on the next request because `is_core_logging_class_available()` flips false.

## Open questions remaining after discovery

1. **Master gate option key.** I've assumed `wpai_features_enabled` matches the option/filter name pair. Flavor Agent's own `FeatureBootstrap::recommendation_feature_enabled()` already uses this exact pair (`inc/AI/FeatureBootstrap.php` lines 48–61), so this should be safe — but verify in a local 1.0.0 stack by toggling features off entirely and confirming both flags need to be true.
2. **Persistence of captured log_ids across editor reloads.** The `RequestLoggingBridge::$captured_log_ids` static lives for one PHP request. For deferred applies (user generates a recommendation, leaves the editor for 10 min, comes back, clicks Apply), the captured map is gone. Phase 2's transient-store fallback handles this for up to 5 min — enough for normal use, gone if the user idles longer. Document that the cross-link becomes unavailable after the transient expires, the apply still works, and consider raising TTL to `HOUR_IN_SECONDS` if QA shows real-world idles bite. Not a blocker for shipping.
3. **Operation name granularity.** Core sets `operation = "<provider>:<endpoint_basename>"` (e.g. `openai:chat.completions`). This is generic per provider, not per Flavor Agent surface. The `wpai_request_log_kind` filter can override the broader category but not the operation. If the admin Activity list should be filterable by Flavor Agent surface within the core Request Logs page, a separate proposal (custom column or context-aware filter chip) needs to land upstream. Not in scope for this bridge.
4. **MCP path through Flavor Agent.** When `mcp_adapter_init` runs Flavor Agent's MCP server and an external MCP client calls `recommend-content`, the chat still goes through `WordPressAIClient::chat()` and the bridge captures it. But the `context.source.slug` from `debug_backtrace` may show `mcp-adapter` (or the MCP plugin slug) rather than `flavor-agent` if the call frame originates in MCP infrastructure. Verify in the gate by replaying via the MCP transport — if `source.slug !== 'flavor-agent'`, relax the defensive check in `inject_flavor_agent_context` to also accept calls where `FlavorAgentRequestTag::current()` is set (Flavor Agent owns the tag, so the tag's presence is the more authoritative signal). This is a one-line tweak if the gate exposes it.
5. **Cost data inclusion.** Core stores `tokens_input/output/total` plus an `AI_Request_Cost_Calculator` payload (visible in the React admin, not the schema). The cross-link UI in Phase 3 can show tokens directly. Whether to surface cost in Flavor Agent's admin Activity row is a UX call deferred to Phase 3 review.

## Verification

- All upstream class/method/hook references pinned to WordPress/ai 1.0.0 tag (SHA `57846e17948088265a78d438f6f0a6c6e7b141e0`).
- `wpai_request_log_context` signature verified at [`includes/Logging/Log_Data_Extractor.php` line 178](https://github.com/WordPress/ai/blob/1.0.0/includes/Logging/Log_Data_Extractor.php#L178).
- `wpai_request_logged` action verified at [`includes/Logging/AI_Request_Log_Repository.php` line 113](https://github.com/WordPress/ai/blob/1.0.0/includes/Logging/AI_Request_Log_Repository.php#L113).
- `tools.php?page=ai-request-logs` slug verified at [`includes/Logging/AI_Request_Log_Page.php` lines 30 and 44](https://github.com/WordPress/ai/blob/1.0.0/includes/Logging/AI_Request_Log_Page.php#L30).
- Single-log REST endpoint verified at [`includes/Logging/REST/AI_Request_Log_Controller.php` lines 99–116](https://github.com/WordPress/ai/blob/1.0.0/includes/Logging/REST/AI_Request_Log_Controller.php#L99).
- Experiment ID verified at [`includes/Experiments/AI_Request_Logging/AI_Request_Logging.php` line 33](https://github.com/WordPress/ai/blob/1.0.0/includes/Experiments/AI_Request_Logging/AI_Request_Logging.php#L33).
- Auto-source detection verified at [`includes/Logging/Logging_Http_Transporter.php` lines 152–169](https://github.com/WordPress/ai/blob/1.0.0/includes/Logging/Logging_Http_Transporter.php#L152).
- Streaming, Secrets Management, and experiment-init items remain watch-only per the user's direction; this plan deliberately does NOT introduce any of them.
