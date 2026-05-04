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

Server-side REST execution remains available through the canonical Abilities API route:

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
- do not initialize recommendation UI or ability execution when the Feature is disabled.

The Feature's `register()` method owns Flavor Agent's AI integration hooks:

- ability category registration;
- ability registration;
- editor asset enqueueing for recommendation UI;
- any AI-specific setup state needed by the editor bootstrap.

Non-AI plugin lifecycle hooks such as activity repository maintenance, pattern index cron, and settings sanitization can remain in the plugin bootstrap.

### Ability Classes

Replace direct `execute_callback` registrations with `ability_class` registrations.

Each ability class extends `WordPress\AI\Abstracts\Abstract_Ability` and provides:

- `input_schema(): array`
- `output_schema(): array`
- `execute_callback( mixed $input ): mixed`
- `permission_callback( mixed $input ): bool`
- `meta(): array`
- `category(): string`
- `guideline_categories(): array` where content generation should consume Guidelines

Initial implementation may delegate execution to the existing focused classes, for example:

```php
final class Recommend_Block_Ability extends \WordPress\AI\Abstracts\Abstract_Ability {
    public function execute_callback( mixed $input ): mixed {
        return BlockAbilities::recommend_block( $input );
    }
}
```

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

If a recommendation prompt already manually injects guidelines, remove that duplication after the corresponding `Abstract_Ability` subclass is active.

### Registration Aggregator

Keep `FlavorAgent\Abilities\Registration` as a thin registry coordinator only:

- register the `flavor-agent` ability category;
- map stable ability IDs to ability class names;
- keep shared schema fragments only where they avoid duplication and remain testable.

It should no longer encode every ability's callback contract inline.

### JavaScript Execution

Add `@wordpress/abilities` as a runtime dependency and execute server abilities with:

```js
import { executeAbility } from '@wordpress/abilities';
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
executeAbility( 'flavor-agent/recommend-block', data )
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

Activity request metadata should record the canonical ability name and canonical execution surface. Route metadata should stop presenting `POST /flavor-agent/v1/recommend-*` as the request path for new entries. Historical entries can remain as stored.

### Capability Detection

Production capability detection uses canonical helpers when available:

```php
\WordPress\AI\has_valid_ai_credentials()
\WordPress\AI\has_ai_credentials()
```

Rules:

- `has_valid_ai_credentials()` is preferred for "can execute now" checks.
- `has_ai_credentials()` can support setup messaging where validation is too expensive or unavailable.
- private connector walking is not the authority for global AI availability.
- if the helper is missing, the site is treated as unsupported for canonical integration, not silently supported through private fallback.

The existing connector-specific diagnostics can remain as diagnostics only; they must not decide whether the Feature is enabled or executable.

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
7. Existing recommendation logic executes.
8. Output schema validation runs.
9. JS normalizes the ability execution result to the existing recommendation payload shape.
10. UI renders recommendations and apply affordances as today.

### Apply Freshness Request

1. Store strips the stored context signature from the original input.
2. Store calls the same ability through `executeAbility()` with `resolveSignatureOnly: true`.
3. Ability recomputes the server signature and returns `resolvedContextSignature`.
4. Store compares it with the stored signature.
5. Apply proceeds only when the signatures match.

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
- Feature `register()` attaches ability/category registration hooks only when enabled by the AI plugin framework.
- All 20 abilities register with `ability_class`, not direct `execute_callback`.
- All 20 ability classes extend `WordPress\AI\Abstracts\Abstract_Ability`.
- Recommendation ability classes expose the same input and output schema currently asserted in `RegistrationTest`.
- Recommendation ability classes delegate to the existing execution classes.
- `guideline_categories()` returns the expected categories per recommendation ability.
- Credential checks prefer `WordPress\AI\has_valid_ai_credentials()` when available.
- Fallback prompt builder calls `using_model_preference()` when supported.

### JavaScript Unit

- Recommendation fetch thunks call `executeAbility()` with the expected ability name and input.
- No recommendation thunk calls `apiFetch()` with `flavor-agent/v1/recommend-*`.
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
2. Add ability class base/helper structure.
3. Convert all ability registrations to `ability_class`.
4. Update credential detection to canonical helpers.
5. Add `@wordpress/abilities` dependency and JS execution helper.
6. Switch recommendation thunks and executable surfaces to `executeAbility()`.
7. Remove active custom recommendation route registration.
8. Move activity request metadata to canonical ability execution naming.
9. Update unit and E2E tests.
10. Update contributor-facing docs that mention recommendation routes or settings location.

## Open Implementation Notes

- The exact AI plugin category constant should be resolved from the installed AI plugin source during implementation. If the constant is unavailable, use the documented string category only behind a guard.
- Existing recommendation payload fields remain valid outputs. This migration changes transport and registration contract, not UI payload semantics.
- Existing activity history may contain old route strings. Do not migrate stored historical activity entries unless a separate data migration is approved.
- If `@wordpress/core-abilities` is not automatically present in the editor context, enqueue the script module explicitly with the editor assets.

## Acceptance Criteria

- Settings > AI can list and gate Flavor Agent.
- Recommendation abilities are registered through `ability_class` subclasses.
- Editor recommendation requests use `executeAbility()`.
- No active editor recommendation request targets `flavor-agent/v1/recommend-*`.
- The seven custom recommendation REST routes are not registered as first-class active routes.
- Canonical credential helpers determine AI availability.
- Existing recommendation UI behavior remains functionally equivalent once abilities execute successfully.
- Tests cover the new Feature, ability classes, JS execution helper, and route removal.
