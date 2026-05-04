---
title: Canonical AI Integration — Design Spec
date: 2026-05-04
status: approved
---

# Canonical AI Integration

## Background

Flavor Agent currently registers 20 Abilities API entries with `meta.show_in_rest = true`, so the core REST exposure exists. The integration gap is that the plugin still treats its own surfaces as canonical:

- editor and admin recommendation calls use `flavor-agent/v1/recommend-*` REST routes;
- ability registrations use plain `execute_callback` arrays rather than AI plugin `Abstract_Ability` subclasses;
- Flavor Agent is not registered as an AI plugin Feature, so Settings > AI cannot list, gate, or toggle it;
- credential checks use local provider probing and connector walking instead of the canonical `WordPress\AI\has_ai_credentials()` / `WordPress\AI\has_valid_ai_credentials()` helpers;
- fallback prompt creation through `wp_ai_client_prompt()` does not apply model preferences.

The approved direction is a cold-turkey migration: the AI plugin Feature framework and the Abilities API become the authoritative contract. Private recommendation routes stop being first-class compatibility paths.

## Goals

1. Make Settings > AI the source of truth for Flavor Agent feature visibility and enabled state.
2. Register recommendation abilities through `WordPress\AI\Abstracts\Abstract_Ability` subclasses.
3. Execute recommendation requests from JavaScript through `@wordpress/abilities` `executeAbility()`.
4. Remove editor dependence on `flavor-agent/v1/recommend-*` endpoints.
5. Use canonical AI credential helpers for capability checks.
6. Fail closed with clear setup state when the AI plugin contracts are unavailable.

## Non-goals

- Preserving custom recommendation REST routes for backward compatibility.
- Rewriting recommendation generation logic or response payload semantics.
- Migrating activity-log, pattern-sync, or other non-recommendation REST endpoints unless a direct Abilities API replacement already exists.
- Replacing the existing WordPress AI Client prompt implementation beyond capability detection and model-preference fallback correctness.
- Solving the long-term public Guidelines write API. Runtime prompt consumption moves canonical-first; write migration can follow the upstream API when stable.

## Breaking Contract

This migration intentionally breaks consumers that call these custom REST routes:

- `POST /wp-json/flavor-agent/v1/recommend-block`
- `POST /wp-json/flavor-agent/v1/recommend-content`
- `POST /wp-json/flavor-agent/v1/recommend-patterns`
- `POST /wp-json/flavor-agent/v1/recommend-navigation`
- `POST /wp-json/flavor-agent/v1/recommend-style`
- `POST /wp-json/flavor-agent/v1/recommend-template`
- `POST /wp-json/flavor-agent/v1/recommend-template-part`

The replacement contract is:

```text
executeAbility( 'flavor-agent/recommend-block', input )
executeAbility( 'flavor-agent/recommend-content', input )
executeAbility( 'flavor-agent/recommend-patterns', input )
executeAbility( 'flavor-agent/recommend-navigation', input )
executeAbility( 'flavor-agent/recommend-style', input )
executeAbility( 'flavor-agent/recommend-template', input )
executeAbility( 'flavor-agent/recommend-template-part', input )
```

Server-side REST execution remains available through the canonical Abilities API route family. Implementation must verify URL encoding for slash-containing ability names before recording literal paths in metadata:

```text
/wp-json/wp-abilities/v1/abilities/{ability-name}/run
```

## Architecture

### Feature Registration

Add a Flavor Agent Feature class that extends `WordPress\AI\Abstracts\Abstract_Feature`.

Feature ID:

```text
flavor-agent
```

Metadata:

- label: `Flavor Agent`
- description: AI-assisted recommendations for blocks, content, patterns, navigation, styles, templates, and template parts.
- category: `editor`
- stability: `experimental`

Registration:

- hook into `wpai_default_feature_classes`;
- guard with `class_exists( '\WordPress\AI\Abstracts\Abstract_Feature' )`;
- register early enough for the AI plugin loader to collect it;
- do not initialize recommendation UI or recommendation ability execution when the Feature is disabled.

The Feature's `register()` method owns Flavor Agent's AI integration hooks:

- recommendation ability registration;
- editor asset enqueueing for recommendation UI;
- any AI-specific setup state needed by the editor bootstrap.

The shared `flavor-agent` ability category and read-only helper ability registration can remain outside the Feature lifecycle so helper REST/MCP tools do not disappear when the AI recommendation Feature is disabled.

Non-AI plugin lifecycle hooks such as activity repository maintenance, pattern index cron, and settings sanitization can remain in the plugin bootstrap.

### Ability Classes

Replace direct `execute_callback` registrations with `ability_class` registrations for recommendation abilities.

Each ability class extends `WordPress\AI\Abstracts\Abstract_Ability` and provides:

- `input_schema(): array`
- `output_schema(): array`
- `execute_callback( mixed $input ): mixed`
- `permission_callback( mixed $input ): bool`
- `meta(): array`
- `category(): string`
- `guideline_categories(): array` where content generation should consume Guidelines

Recommendation ability `meta()` must force POST execution through the Abilities JavaScript runtime. Recommendation requests carry large editor context and user prompts, so they must not be routed as GET requests.

Recommendation meta requirements:

- `show_in_rest: true`;
- no top-level `readonly: true`;
- no `annotations.readonly: true`;
- no `annotations.readOnlyHint: true`;
- `annotations.destructive: false`;
- `annotations.idempotent: false`.

Read-only annotations are reserved for helper abilities that do not call a model and do not accept large or sensitive editor context.

Initial implementation may delegate execution to the existing focused classes, for example:

```php
final class Recommend_Block_Ability extends \WordPress\AI\Abstracts\Abstract_Ability {
    public function execute_callback( mixed $input ): mixed {
        return Recommendation_Ability_Execution::execute(
            'block',
            'flavor-agent/recommend-block',
            $input,
            [ BlockAbilities::class, 'recommend_block' ]
        );
    }
}
```

The ability must not return the raw focused-class payload directly. Today the REST controller wraps successful and failed recommendation results with `requestMeta`, provider metadata, pattern backend metadata where relevant, and request diagnostic activity persistence. That behavior moves into a shared recommendation ability execution service before custom routes are removed.

Schema construction can temporarily reuse the existing `Registration` schema helpers, but the long-term owner is the ability class, not the registration aggregator.

Guideline categories:

| Ability | Categories |
|---|---|
| `recommend-block` | `site`, `copy`, `additional` |
| `recommend-content` | `site`, `copy`, `additional` |
| `recommend-patterns` | `site`, `additional` |
| `recommend-navigation` | `site`, `copy`, `additional` |
| `recommend-style` | `site`, `additional` |
| `recommend-template` | `site`, `copy`, `additional` |
| `recommend-template-part` | `site`, `copy`, `additional` |
| read-only helper abilities | empty |

The current focused callbacks build their own prompts and do not call `Abstract_Ability::load_system_instruction_from_file()`. During the delegation phase, `guideline_categories()` documents the intended canonical integration but does not by itself affect the actual prompt. Do not remove the existing manual Guidelines injection until either:

- prompt construction is owned by the ability classes and uses the AI plugin system-instruction flow; or
- the shared recommendation execution wrapper has an explicit, tested handoff that passes the AI plugin Guidelines output into the existing prompt builders.

Until one of those handoff paths exists, manual Guidelines injection remains the runtime source and tests must prove Guidelines still reach the model prompt.

### Registration Aggregator

Keep `FlavorAgent\Abilities\Registration` as a thin registry coordinator only:

- register the `flavor-agent` ability category;
- map recommendation ability IDs to AI plugin ability class names;
- keep read-only helper abilities registered outside the Flavor Agent Feature gate unless a separate breaking contract explicitly removes them;
- keep shared schema fragments only where they avoid duplication and remain testable.

It should no longer encode every ability's callback contract inline.

This revises the "all 20 abilities" conversion target into two groups:

- the seven recommendation abilities move to AI plugin `Abstract_Ability` subclasses and are gated by the Flavor Agent Feature;
- read-only helper abilities remain discoverable through REST/MCP when the Feature is disabled, because they expose site/theme metadata and diagnostics rather than model execution.

If a future implementation intentionally gates helper abilities too, that must be documented as a separate breaking contract before implementation.

### Recommendation Execution Wrapper

Add a shared server-side wrapper for all recommendation abilities. The wrapper owns the behavior currently embedded in `Agent_Controller` around the raw recommendation callbacks:

- normalize the input enough to identify `resolveSignatureOnly`, document context, prompt, target, and surface;
- call the focused recommendation callback;
- for `resolveSignatureOnly`, return only the signature payload and do not persist request diagnostic activity;
- for success, append `requestMeta` before returning to the Abilities API client;
- for failure, append equivalent request metadata to the `WP_Error` data;
- persist request diagnostic activity for successful non-signature recommendation requests when a document scope is present;
- persist request diagnostic failure activity for failed non-signature recommendation requests when a document scope is present;
- preserve request tracing without route-specific event names becoming the public contract.

Request metadata fields for new entries:

- `ability`: canonical ability name, for example `flavor-agent/recommend-block`;
- `executionTransport`: `wp-abilities`;
- `route`: logical execution route identifier, for example `wp-abilities:flavor-agent/recommend-block`;
- provider metadata from `Provider::active_chat_request_meta()` for text-generation surfaces;
- pattern backend metadata for pattern recommendations, including selected backend and embedding provider metadata where applicable.

Do not write `requestMeta.transport` for Abilities API execution. That key is reserved for provider/request transport diagnostics returned by `Provider::active_chat_request_meta()` and may be a structured array.

Do not make a literal REST path an acceptance criterion until implementation verifies the Abilities API route matcher and URL encoding behavior for slash-containing ability names. If a literal path is useful for diagnostics after verification, store it under a distinct key such as `abilityRestPath`, not `route`.

The existing route controller can temporarily call the same wrapper while routes still exist. That keeps activity payloads consistent during the migration and prevents route removal from deleting telemetry.

### JavaScript Execution

Execute server abilities through the WordPress-provided Abilities runtime, not a bundled private copy of `@wordpress/abilities`.

The current editor bundle is enqueued as a classic script with `wp_enqueue_script()`, so the implementation must first choose one compatible delivery model:

- preferred: add a small script-module bridge that imports `executeAbility` from WordPress' `@wordpress/abilities`, ensures `@wordpress/core-abilities` is loaded, and exposes a single Flavor Agent helper to the existing classic bundle;
- acceptable: convert the editor bundle to a script module and use WordPress' module dependency graph directly;
- not allowed: bundle a separate npm copy of `@wordpress/abilities` into the classic script and execute against an isolated or empty abilities store.

The classic-bundle bridge shape is:

```js
window.flavorAgentAbilities.executeAbility( 'flavor-agent/recommend-block', data )
```

If the bridge is not ready, the store returns a setup-state error instead of falling back to `apiFetch()`.

After the delivery model is in place, recommendation calls go through one local helper that invokes WordPress' `executeAbility()` from the chosen delivery model. The helper may use a direct module import only if the editor bundle itself is a script module:

```js
import { executeAbility } from '@wordpress/abilities';
```

The existing classic bundle should instead call the script-module bridge:

```js
window.flavorAgentAbilities.executeAbility( abilityName, data )
```

All recommendation request paths in `src/store/index.js` and `src/store/executable-surface-runtime.js` switch from:

```js
apiFetch( {
    path: '/flavor-agent/v1/recommend-block',
    method: 'POST',
    data,
} )
```

to:

```js
executeFlavorAgentAbility( 'flavor-agent/recommend-block', data )
```

Surface definitions store `abilityName` instead of `endpoint` for recommendation surfaces.

Result normalization must accept the canonical Abilities API execution result. The UI can continue consuming the existing recommendation payload shape (`executionContract`, `resolvedContextSignature`, `preFilteringCounts`, `suggestions`, etc.), but normalization should happen in one helper, not at every call site.

`resolveSignatureOnly` remains part of the ability input contract during this migration because apply freshness checks depend on server-side signature recomputation. It is still executed through `executeAbility()`, not a custom route.

### Custom REST Route Removal

Remove active registration for the seven recommendation routes from `FlavorAgent\REST\Agent_Controller`.

Keep routes that do not duplicate recommendation abilities:

- activity log routes;
- undo routes;
- pattern sync routes;
- settings/admin support routes that have no Abilities API equivalent.

Activity request metadata must already be owned by the shared recommendation ability execution wrapper before these routes are removed. Route metadata should stop presenting `POST /flavor-agent/v1/recommend-*` as the request path for new entries. Historical entries can remain as stored.

### Capability Detection

Production capability detection has two layers.

Global AI availability uses canonical helpers when available:

```php
\WordPress\AI\has_valid_ai_credentials()
\WordPress\AI\has_ai_credentials()
```

Global rules:

- `has_valid_ai_credentials()` is preferred for "does this site have usable AI at all?" checks.
- `has_ai_credentials()` can support setup messaging where validation is too expensive or unavailable.
- private connector walking is not the authority for global AI availability.
- if the helper is missing, the site is treated as unsupported for canonical integration, not silently supported through private fallback.

Per-surface readiness remains separate and must not be replaced by global credential success. A site can have valid AI credentials while the selected Flavor Agent surface is still unavailable.

Per-surface rules:

- text-generation surfaces require the selected chat runtime to be configured and supported, including any selected connector pinning;
- pattern recommendations require the selected pattern retrieval backend to be configured and usable;
- Qdrant-backed pattern recommendations require the selected embedding backend plus Qdrant URL/key;
- Cloudflare AI Search pattern recommendations require the Cloudflare AI Search backend configuration;
- pattern recommendations also require the selected chat runtime for ranking/explanation;
- surface-specific errors should name the missing selected dependency, not just say global AI credentials are missing.

The existing connector-specific diagnostics can remain as diagnostics and as per-surface readiness inputs. They must not override the global Settings > AI Feature gate.

### Prompt Builder Fallback

The primary path remains:

```php
\WordPress\AI\get_ai_service()->create_textgen_prompt( $user_prompt, $options )
```

If that path is unavailable but `wp_ai_client_prompt()` exists, the fallback must apply model preferences in addition to model config:

```php
$prompt = wp_ai_client_prompt( $user_prompt )
    ->using_model_preference( ... );
```

Only call `using_model_preference()` when the builder supports it. Preserve current `using_model_config()` handling for supported option keys.

### Settings

Settings > AI owns the high-level Flavor Agent Feature toggle.

The existing Flavor Agent settings page can remain for infrastructure-specific settings that are not AI Feature controls, such as:

- pattern retrieval backend;
- Cloudflare AI Search configuration;
- Qdrant configuration;
- legacy provider diagnostics.

Guidelines runtime source becomes canonical-first:

- read through AI plugin / Gutenberg Guidelines integration when available;
- treat Flavor Agent guideline options as legacy import/export and fallback storage only;
- do not present dual guideline storage as equal runtime truth.

If Flavor Agent needs feature-specific settings on Settings > AI, implement them through the Feature's `register_settings()` and `get_settings_fields()` using AI plugin option naming helpers.

## Data Flow

### Editor Recommendation Request

1. UI builds the same request input it uses today.
2. Store dispatch calls `executeAbility( abilityName, input )`.
3. `@wordpress/core-abilities` dispatches to `/wp-abilities/v1/abilities/{name}/run`.
4. The server resolves the `Abstract_Ability` class.
5. Permission callback runs.
6. Input schema validation runs through the Abilities API.
7. The shared recommendation execution wrapper runs per-surface readiness checks.
8. Existing recommendation logic executes.
9. The wrapper appends canonical request metadata and persists request diagnostic activity when applicable.
10. Output schema validation runs.
11. JS normalizes the ability execution result to the existing recommendation payload shape.
12. UI renders recommendations and apply affordances as today.

### Apply Freshness Request

1. Store strips the stored context signature from the original input.
2. Store calls the same ability through `executeAbility()` with `resolveSignatureOnly: true`.
3. Ability recomputes the server signature and returns `resolvedContextSignature`.
4. The wrapper skips request metadata and activity persistence for the signature-only response.
5. Store compares it with the stored signature.
6. Apply proceeds only when the signatures match.

## Error Handling

Feature unavailable:

- editor UI does not enqueue recommendation controls when the Feature is disabled;
- if a stale page already loaded the UI, ability execution failures surface as setup-state errors, not generic request failures.

AI contracts unavailable:

- no private fallback route is used;
- setup messaging should name the missing dependency: WordPress 7.0+ AI Client / AI plugin Feature framework / Abilities API as appropriate.

Credential invalid:

- use canonical helper result for availability state;
- recommendation calls return a `WP_Error` with a stable code and actionable setup message.

Ability execution error:

- normalize Abilities API errors into the store's existing error slots;
- preserve error codes where available for tests and support diagnostics.

Output mismatch:

- treat invalid output as a server bug and show a concise failure;
- log enough diagnostic context through existing request tracing without leaking full prompts.

## Testing

### PHP Unit

- Feature registration adds `FlavorAgent` to `wpai_default_feature_classes` when `Abstract_Feature` exists.
- Feature registration is a no-op when the AI plugin class is missing.
- Feature metadata returns expected ID, label, description, category, and stability.
- Feature `register()` attaches recommendation ability and editor integration hooks only when enabled by the AI plugin framework.
- The seven recommendation abilities register with AI plugin `ability_class`, not direct `execute_callback`.
- The seven recommendation ability classes extend `WordPress\AI\Abstracts\Abstract_Ability`.
- Read-only helper abilities remain registered and REST/MCP-discoverable when the Flavor Agent Feature is disabled.
- Recommendation ability classes expose the same input and output schema currently asserted in `RegistrationTest`.
- Recommendation ability `meta()` does not set readonly annotations and therefore routes through POST in the Abilities JavaScript runtime.
- Read-only helper ability `meta()` may keep readonly annotations and GET routing.
- Recommendation ability classes delegate through the shared recommendation execution wrapper, not directly to raw focused callbacks.
- The wrapper appends `requestMeta.ability`, `requestMeta.executionTransport`, logical `requestMeta.route`, and provider metadata to successful non-signature responses.
- The wrapper does not overwrite structured `requestMeta.transport` provider diagnostics.
- The wrapper appends pattern backend and embedding metadata for pattern recommendation responses.
- The wrapper appends equivalent request metadata to `WP_Error` data for failed non-signature responses.
- The wrapper persists request diagnostic activity for successful non-signature recommendation requests when document scope exists.
- The wrapper persists request diagnostic failure activity for failed non-signature recommendation requests when document scope exists.
- The wrapper skips metadata persistence for `resolveSignatureOnly` responses.
- `guideline_categories()` returns the expected categories per recommendation ability.
- Manual Guidelines injection remains active during the delegation phase, or an explicit wrapper/ability handoff test proves Guidelines reach the focused prompt builders.
- Credential checks prefer `WordPress\AI\has_valid_ai_credentials()` when available.
- Per-surface readiness still fails when the selected chat connector, embedding backend, Qdrant, or Cloudflare AI Search backend required by a surface is unavailable.
- Fallback prompt builder calls `using_model_preference()` when supported.

### JavaScript Unit

- Recommendation fetch thunks call the approved Abilities bridge/helper with the expected ability name and input.
- No recommendation thunk calls `apiFetch()` with `flavor-agent/v1/recommend-*`.
- The Abilities bridge uses the WordPress-provided Abilities runtime and fails closed when unavailable.
- Executable surface definitions use `abilityName`.
- Ability result normalization handles direct payloads and canonical ability execution wrappers.
- Freshness checks call `executeAbility()` with `resolveSignatureOnly: true`.
- Error normalization preserves useful Abilities API error codes/messages.

### E2E

- Helper ability smoke tests continue to call `/wp-abilities/v1/abilities/{name}/run`.
- Editor recommendation smoke tests intercept or observe canonical ability execution, not `flavor-agent/v1/recommend-*`.
- Settings > AI lists Flavor Agent.
- Disabling global AI features hides or disables Flavor Agent recommendation UI.
- Disabling the Flavor Agent Feature hides or disables Flavor Agent recommendation UI.
- With invalid credentials, the UI shows setup state and does not attempt private route fallback.

### Verification Commands

Targeted first pass:

```sh
composer run test:php -- --filter RegistrationTest
npm run test:unit -- --runInBand src/store
npm run build
```

Broader release evidence:

```sh
node scripts/verify.js --skip-e2e
npm run check:docs
```

Run the relevant Playwright harnesses before release because this changes editor request plumbing and Settings > AI visibility.

## Migration Sequence

1. Add Feature class and registration hook.
2. Add the shared recommendation execution wrapper and move request metadata/activity persistence into it while existing routes still work.
3. Add ability class base/helper structure.
4. Convert the seven recommendation ability registrations to `ability_class`.
5. Update global AI availability and per-surface readiness checks.
6. Add the approved WordPress Abilities delivery model and JS execution helper.
7. Switch recommendation thunks and executable surfaces to the Abilities helper.
8. Move activity request metadata to canonical ability execution naming for new entries.
9. Remove active custom recommendation route registration.
10. Update unit and E2E tests.
11. Update contributor-facing docs that mention recommendation routes or settings location.

## Open Implementation Notes

- The exact AI plugin category constant should be resolved from the installed AI plugin source during implementation. If the constant is unavailable, use the documented string category only behind a guard.
- Existing recommendation payload fields remain valid outputs. This migration changes execution transport and registration contract, not UI payload semantics.
- Existing activity history may contain old route strings. Do not migrate stored historical activity entries unless a separate data migration is approved.
- If `@wordpress/core-abilities` is not automatically present in the editor context, enqueue the script module explicitly with the editor assets.
- Do not switch JS callers until a runtime check proves the classic bundle is using WordPress' Abilities runtime, not a bundled duplicate store.

## Acceptance Criteria

- Settings > AI can list and gate Flavor Agent.
- Recommendation abilities are registered through `ability_class` subclasses.
- Read-only helper abilities remain discoverable when the Flavor Agent Feature is disabled.
- Recommendation ability metadata forces POST routing by avoiding readonly annotations.
- Editor recommendation requests use `executeAbility()`.
- Recommendation ability execution preserves request metadata and request diagnostic activity logging.
- JavaScript ability execution uses the WordPress-provided Abilities runtime, not an isolated bundled copy.
- No active editor recommendation request targets `flavor-agent/v1/recommend-*`.
- The seven custom recommendation REST routes are not registered as first-class active routes.
- Canonical credential helpers determine global AI availability, and per-surface readiness still enforces selected connector/backend requirements.
- Existing recommendation UI behavior remains functionally equivalent once abilities execute successfully.
- Tests cover the new Feature, ability classes, JS execution helper, and route removal.
