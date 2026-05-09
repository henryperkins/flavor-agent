# Tier 2 — Read-only preview recommendation abilities

**Status:** Approved direction, awaiting implementation
**Date:** 2026-05-09
**Author:** Henry Perkins (with Claude)
**Tracker:** Flavor Agent Ability Explorer / MCP review (this session)

## Context

The seven `flavor-agent/recommend-*` abilities are the plugin's primary AI surface. Five of them — block, navigation, style, template, template-part — accept a `resolveSignatureOnly: true` boolean that short-circuits `RecommendationAbilityExecution::execute()` before any chat call, returning only the freshness signature(s).

The Ability Explorer (karmatosed/abilitiesexplorer) auto-generates example input from `input_schema`, picking the type-default (`false`) for booleans. Operators clicking "Run" therefore hit the chat backend even when they only want to verify wiring. The same gap shows up over MCP: external agents see one polymorphic tool that may or may not call the model depending on a flag they have to discover.

Tier 1 (already shipped) added an `enum`/`default` on closed-vocabulary fields plus a one-line description hint pointing at `resolveSignatureOnly`. That helps an informed reader but doesn't change the click-Run UX or the MCP tool taxonomy.

## Goal

Give Ability Explorer humans and MCP agents a clearly-marked, click-to-run preflight surface for the five executable recommendation abilities — without touching the existing `recommend-*` contracts, without writing to the activity log, and without depending on a configured AI Connector.

## Non-goals

- Previewing `recommend-content` (no dry-run path; would require new code in `ContentAbilities`).
- Previewing `recommend-patterns` (the existing `flavor-agent/list-patterns` already serves this purpose).
- Exposing previews through the dedicated Flavor Agent MCP server (see "MCP server behavior" below).
- Any change to existing `recommend-*` runtime behavior.

## Design

### New abilities

| Ability ID | Wraps | Signature output |
|---|---|---|
| `flavor-agent/preview-recommend-block` | `RecommendBlockAbility` | `resolvedContextSignature` |
| `flavor-agent/preview-recommend-navigation` | `RecommendNavigationAbility` | `reviewContextSignature` |
| `flavor-agent/preview-recommend-style` | `RecommendStyleAbility` | `reviewContextSignature`, `resolvedContextSignature` |
| `flavor-agent/preview-recommend-template` | `RecommendTemplateAbility` | `reviewContextSignature`, `resolvedContextSignature` |
| `flavor-agent/preview-recommend-template-part` | `RecommendTemplatePartAbility` | `reviewContextSignature`, `resolvedContextSignature` |

Per-surface signature presence mirrors what `RecommendationAbilityExecution::execute()` actually returns on the dry-run path. Implementation must verify against the parent's existing output schema before locking each preview's `output_schema()`.

Labels use the form **"Preview {surface} recommendation signatures"** (e.g. *"Preview block recommendation signatures"*) to make clear the abilities return signatures, not recommendation content.

### Class hierarchy

```
PreviewRecommendationAbility            (new abstract, ~80 lines)
├── const PARENT_CLASS                  → Recommend*Ability::class to wrap
├── input_schema()                      → parent's schema MINUS resolveSignatureOnly
│                                         AND MINUS clientRequest
├── output_schema()                     → signature-subset (per table above)
├── meta()                              → readonly:true / idempotent:true /
│                                         destructive:false / show_in_rest:true /
│                                         mcp.public:true / mcp.type:'tool'
├── permission_callback($input)         → instantiates parent, delegates to
│                                         parent->permission_callback($input)
└── execute_callback($input)            → forces resolveSignatureOnly:true,
                                          unsets clientRequest,
                                          delegates to parent->execute_callback(),
                                          filters output to allowed signature keys

PreviewRecommendBlockAbility            (5 concrete subclasses, ~10 lines each)
PreviewRecommendNavigationAbility
PreviewRecommendStyleAbility
PreviewRecommendTemplateAbility
PreviewRecommendTemplatePartAbility
```

### Input contract

The preview's public `input_schema()` is the parent's input schema **minus two fields**:

1. **`resolveSignatureOnly`** — forced to `true` server-side; not a caller-tunable.
2. **`clientRequest`** — removed because `RecommendationAbilityExecution::execute()` calls `latest_request_token()` before its early return, which writes a transient when `clientRequest.sessionId + requestToken` are present. A "preview" ability that mutates server-side request-token state breaks the read-only/idempotent promise.

Both fields are stripped from the schema **and** unset from the input dict before delegation.

### Output contract

Strict signature-only:

```json
{
  "reviewContextSignature": "string?",       // present per table above
  "resolvedContextSignature": "string?"      // present per table above
}
```

**Not promised:** `requestMeta`, `diagnostics`, `suggestions`, `operations`, `explanation`. The parent's `RecommendationAbilityExecution::execute()` returns immediately on `resolveSignatureOnly:true` *before* `append_request_meta()` runs, so these are not populated on the dry-run path. Promising them in the preview schema would require the wrapper to call `Provider::active_chat_request_meta()` — which would blur the "no chat backend noise" promise. Keep the contract honest instead.

If wrapper-supplied static metadata (`parentAbility`, `previewAbility`, `executionTransport: 'wp_ability'`) becomes useful later, add it explicitly in a follow-up rather than retrofitting.

### Permission delegation

`PreviewRecommendationAbility::permission_callback($input)` instantiates the parent class (using `PARENT_CLASS::class`) and forwards the call. Do **not** copy capability constants. Two reasons:

1. Preserves post-scoped permission behavior (block/content/pattern surfaces escalate to `current_user_can('edit_post', $post_id)` when a post ID is extractable from input).
2. Future-proofs against parent permission changes.

The parent ability is constructed with the parent's registration args; it never executes — only its `permission_callback()` is called.

### Registration timing

Register from `Registration::register_abilities()` (the always-on helper path), **not** `register_recommendation_abilities()`. Wrap the call in:

```php
if ( FeatureBootstrap::canonical_contracts_available() ) {
    self::register_preview_recommendation_abilities();
}
```

`canonical_contracts_available()` checks both `wp_register_ability` (Abilities API) **and** `WordPress\AI\Abstracts\Abstract_Ability` (AI plugin contracts). Both are required because `PreviewRecommendationAbility` extends `Abstract_Ability`. Without the guard, environments lacking the AI plugin would fatal on class load.

This places preview abilities at exactly the right tier: **available before the Flavor Agent feature gate is flipped, but not before the AI contract stack exists.** That matches the operator workflow Tier 2 is meant to support — verify wiring, then enable recommendations.

### MCP server behavior

**Default MCP ability bridge: yes.** Preview abilities carry `meta.mcp.public = true` and `meta.mcp.type = 'tool'` and are discoverable through whatever bridge is wired to the Abilities API.

**Dedicated `/wp-json/mcp/flavor-agent` server: no, in Tier 2.** `inc/MCP/ServerBootstrap.php` currently allow-lists `Registration::recommendation_ability_classes()` and is gated on `FeatureBootstrap::recommendation_feature_enabled()`. Leaving the dedicated server unchanged keeps it a "production AI surface" rather than a preflight tool. If operator preflight via the dedicated server becomes desired later, add `preview_recommendation_ability_classes()` to the allow-list and move the server's gating decision to its own follow-up — it is a separate design concern.

### Status surface integration

Add the five preview ability IDs to `InfraAbilities::available_abilities()` so `flavor-agent/check-status` reports them when the user has the relevant capability. Without this, an operator running `check-status` to confirm wiring before flipping the feature gate will see the preview abilities silently missing from the inventory — defeating the purpose of registering them outside the feature gate.

### Annotations

| Field | Value |
|---|---|
| `meta.show_in_rest` | `true` |
| `meta.readonly` | `true` (top-level, matches existing read-helper meta style) |
| `meta.annotations.readonly` | `true` |
| `meta.annotations.destructive` | `false` |
| `meta.annotations.idempotent` | `true` |
| `meta.mcp.public` | `true` |
| `meta.mcp.type` | `'tool'` |

**Caveat (documented, not blocked):** WP 7.0 client plumbing dispatches `readonly:true` server abilities via GET. Preview inputs can be large/nested (`editorContext`, `styleContext`, `editorStructure` for templates). For the intended consumers — Ability Explorer, MCP, operator tooling — this is fine. **Do not** wire these previews into first-party editor JS expecting reliable GET dispatch with full editor payloads. If an editor surface needs preview semantics later, give it a dedicated path.

## Test plan

**Extend `tests/phpunit/RegistrationTest.php`:**

- `test_register_preview_recommendation_abilities()` — all 5 register with category `flavor-agent`, correct labels, and the per-surface output signatures from the table above.
- `test_preview_recommendation_abilities_are_readonly_and_mcp_public()` — annotation matrix per "Annotations" section.
- `test_preview_recommendation_abilities_are_available_without_feature_gate()` — register when `FeatureBootstrap::recommendation_feature_enabled()` is false but `canonical_contracts_available()` is true.
- `test_recommendation_abilities_remain_feature_gated()` — invariant: parent abilities still require the feature gate.
- `test_preview_recommendation_abilities_strip_resolve_signature_only_and_client_request_from_schema()` — schema-level assertion both keys are absent.
- Update existing "annotations coverage" loops to include the 5 preview IDs (count goes from 20 to 25 abilities).

**New `tests/phpunit/PreviewRecommendationAbilityTest.php`:**

- For each of the 5 concrete classes:
  - `test_execute_callback_forces_resolve_signature_only_and_strips_client_request()` — pass an input with both fields set to non-default values; assert the parent callback receives `resolveSignatureOnly: true` and no `clientRequest` key. Use a fake parent (test double) so the test does not need a real model/Provider/PatternRetrievalBackend.
  - `test_execute_callback_filters_output_to_allowed_signature_keys()` — fake parent returns a payload with `suggestions`, `operations`, `requestMeta`, `reviewContextSignature`, `resolvedContextSignature`; assert only the per-surface signature subset is returned.
  - `test_permission_callback_delegates_to_parent()` — assert the preview's permission_callback returns the same boolean the parent would for an identical input. Cover the post-scoped block/content/pattern path.
- `test_preview_execution_does_not_invoke_chat_backend()` — fake-parent assertion that `Provider::generate_chat_completion()` (or equivalent) is never called during preview execution.

**Status surface coverage:**

- `tests/phpunit/InfraAbilitiesTest.php` — assert `check-status.availableAbilities` includes the 5 preview IDs when the calling user has appropriate caps.

**Verification commands (per CLAUDE.md):**

```bash
vendor/bin/phpunit --filter "Registration|PreviewRecommendation|InfraAbilities"
node scripts/verify.js --skip-e2e
npm run check:docs
```

## Doc updates

Required in the implementation PR:

- `CLAUDE.md` — Abilities API section: "20 abilities" → "25 abilities"; add the five new IDs to the surface inventory.
- `docs/reference/abilities-and-routes.md` — canonical Abilities map: add 5 entries with category, capability, output shape.

Likely additional updates flagged by `npm run check:docs`:

- `STATUS.md`, `docs/SOURCE_OF_TRUTH.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/flavor-agent-readme.md`, `docs/reference/wordpress-ai-roadmap-tracking.md`, `.github/copilot-instructions.md` — wherever ability counts or "always-on helper" inventories appear.

The implementation PR is responsible for stale-doc hygiene; the spec commit only touches files in `docs/superpowers/`.

## File inventory

| Action | File |
|---|---|
| New | `inc/AI/Abilities/PreviewRecommendationAbility.php` (~80 lines, abstract) |
| New | `inc/AI/Abilities/PreviewRecommendBlockAbility.php` (~10 lines) |
| New | `inc/AI/Abilities/PreviewRecommendNavigationAbility.php` (~10 lines) |
| New | `inc/AI/Abilities/PreviewRecommendStyleAbility.php` (~10 lines) |
| New | `inc/AI/Abilities/PreviewRecommendTemplateAbility.php` (~10 lines) |
| New | `inc/AI/Abilities/PreviewRecommendTemplatePartAbility.php` (~10 lines) |
| Edit | `inc/Abilities/Registration.php` — add `register_preview_recommendation_abilities()`, `preview_recommendation_ability_classes()`, `preview_recommendation_meta()`, `preview_recommendation_output_schema(string $ability_id)`, plus the `canonical_contracts_available()` guard call from `register_abilities()`. |
| Edit | `inc/Abilities/InfraAbilities.php` — extend `available_abilities()` to include preview IDs. |
| Edit | `tests/phpunit/RegistrationTest.php` — new test methods + count updates in existing loops. |
| New | `tests/phpunit/PreviewRecommendationAbilityTest.php` (~150 lines). |
| Edit | `tests/phpunit/InfraAbilitiesTest.php` — assertion that preview IDs surface in `check-status`. |
| Edit | `CLAUDE.md` — count + inventory. |
| Edit | `docs/reference/abilities-and-routes.md` — canonical map. |
| Edit (likely) | Other docs flagged by `check:docs`. |

Estimated diff: ~250 production LOC, ~200 test LOC, ~50 doc LOC.

## Risks

1. **Per-surface signature presence is asserted but not yet verified.** Implementation MUST read each parent's existing `output_schema()` and the actual `RecommendationAbilityExecution::execute()` return path before locking each preview's `output_schema()`. The table above is the asserted intent; treat any mismatch as a spec bug to flag back, not silently accommodate.
2. **Permission delegation via parent instantiation** assumes the parent's constructor is side-effect-free. `Abstract_Ability::__construct()` only calls `parent::__construct()` with collected properties; verify no parent class adds heavier side effects before relying on this.
3. **Signature-only output stability.** The two signature values are derived from input; equal inputs yield equal signatures, satisfying `idempotent:true`. If a future signature contributor introduces non-deterministic input (e.g. timestamps), the annotation becomes a lie. Add a regression test that calls the same preview twice with identical input and asserts identical signatures.

## Out of scope (followups)

- Content/pattern previews (covered under non-goals).
- Dedicated MCP server allow-list expansion (covered under MCP server behavior).
- Wrapper-supplied static metadata in preview output (future enrichment if needed).
- A unified `flavor-agent/preview-recommendation` ability with a `surface` enum (rejected during brainstorming — sibling-per-surface is the chosen shape).
