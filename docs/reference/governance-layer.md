# Governance Layer

This document is the contract reference for Flavor Agent's AI governance layer — the controls every Flavor Agent-mediated AI action passes through before, while, and after it touches a live site.

Use it when you need to answer:

- which control bounds, reviews, attributes, or reverses an AI change
- where each control is enforced in PHP and JS, and where it is tested
- which surfaces run the full governed loop and which stop earlier by design
- what external agents get through the Abilities API and MCP versus the first-party editor

## Thesis

Flavor Agent lets AI work on a live WordPress site without unchecked control. Every AI action it mediates runs through one governance layer: operations validated against bounded schemas, structural changes gated behind review, every apply the plugin owns attributed and recorded server-side, every recorded change reversible with drift detection so an undo never clobbers later human edits. Humans get this through native Gutenberg and Site Editor surfaces; external agents get the same recommendation, validation, and freshness contracts through the Abilities API and MCP. Built on the WordPress 7.0 AI stack. The recommendation surfaces are the demonstration; the governance layer is the product.

## The Governed Loop

Every executable recommendation runs one loop:

1. **Generate** — the surface ability executes through the shared executor (`inc/Abilities/RecommendationAbilityExecution.php`), which injects guidelines, routes the provider, enforces the docs-grounding fail-closed rules, and writes a `request_diagnostic` activity row for every request — including advisory-only and externally invoked ones.
2. **Validate** — model output is parsed against strict response schemas and bounded operation catalogs. Rejected proposals are preserved as diagnostics (`rejectedOperations`, `validationReasons`), never silently dropped, and the client revalidates server-approved operations and fails closed on identity mismatch.
3. **Review** — structural and theme-level changes require an explicit review step before apply; inline-safe changes are limited to bounded attribute updates classified by the actionability tiers.
4. **Apply + record** — applies the plugin owns execute deterministically and write server-backed activity rows with provenance (provider path, model, prompt, route, token usage).
5. **Reverse** — undo revalidates the live document against the recorded post-apply state before reversing; drift blocks the undo instead of clobbering later human edits.

Freshness signatures thread through the loop: request, review, and resolved signatures detect client, server, and docs-grounding drift, and stale context blocks apply (`stale_blocked`) rather than acting on outdated state.

## Pillars

### Bounded

**Guarantee:** the model can only propose what the schemas and operation catalogs can express, and only theme- and block-supported values survive sanitization.

Enforced by:

- `inc/LLM/ResponseSchema.php` — strict JSON response schemas per surface
- `inc/Context/BlockOperationValidator.php` + `inc/Context/BlockRecommendationExecutionContract.php` — v1 structural catalog (`insert_pattern`, `replace_block_with_pattern`) with standardized rejection codes
- `shared/validation-reasons.json` mirrored by `inc/Support/ValidationReason.php` and `src/utils/validation-reasons.js` — versioned `validation-reasons-v1` vocabulary
- `src/utils/block-operation-catalog.js` + `src/utils/block-execution-contract.js` — client-side revalidation that fails closed with `client_server_operation_mismatch`
- `src/utils/template-operation-sequence.js` + `docs/reference/template-operations.md` — template and template-part operation vocabulary
- `src/utils/style-validation.js`, `src/utils/style-support-paths.js`, `inc/LLM/StyleContrastValidator.php` — `theme.json`-safe style paths, value sanitization, WCAG AA contrast floor (4.5)
- `inc/LLM/PromptBudget.php` and candidate caps such as `TEMPLATE_PATTERN_CANDIDATE_CAP` — bounded inputs, not just bounded outputs

Tested by: `tests/phpunit/ResponseSchemaTest.php`, `RegistrationSchemaTest.php`, `AbilitySchemaContractTest.php`, `BlockOperationValidatorTest.php`, `BlockOperationContextTest.php`, `PromptValidationReasonsTest.php`; `src/utils/__tests__/block-operation-catalog.test.js`.

### Reviewed

**Guarantee:** anything structural or theme-level is shown to a human before it is applied; only bounded attribute updates qualify as inline-safe.

Enforced by:

- `src/utils/recommendation-actionability.js` — tier classifier (`inline-safe` / `review-safe` / `advisory`) with reason codes
- `src/components/AIReviewSection.js` + `src/inspector/block-review-state.js` — review-before-apply confirmation and review-state tracking
- `src/store/executable-surfaces.js` / `src/store/executable-surface-runtime.js` — review-freshness thunks shared by template, template-part, Global Styles, and Style Book
- `inc/Support/RecommendationReviewSignature.php` — server review signatures covering server-owned context plus the docs-grounding fingerprint

Tested by: `src/utils/__tests__/recommendation-actionability.test.js`, `src/store/__tests__/executable-surface-runtime.test.js`; `tests/phpunit/SignatureBoundaryTest.php`.

### Attributed

**Guarantee:** every recommendation request and every owned apply leaves a server-side record with provenance; administrators can audit it without editor access.

Enforced by:

- `inc/Abilities/RecommendationAbilityExecution.php` — centralized `request_diagnostic` emission for every recommendation execution, regardless of caller
- `inc/Activity/Repository.php` / `Permissions.php` / `Serializer.php` — server-backed storage, contextual capability checks, provenance projection columns for audit filtering
- `POST /flavor-agent/v1/activity` — persists apply rows and scoped diagnostics with provider path, model, prompt, reference, token usage, and latency
- `inc/Admin/ActivityPage` + `src/admin/activity-log.js` — the read-only `Settings > AI Activity` audit surface

Tested by: `tests/phpunit/RecommendationAbilityExecutionTest.php`, `ActivityRepositoryTest.php`, `ActivityPermissionsTest.php`, `ActivitySerializerTest.php`, `ActivityPageTest.php`.

### Reversible

**Guarantee:** recorded changes can be undone from the UI, and an undo only executes when the live document still matches the recorded post-apply state.

Enforced by:

- `src/store/activity-undo.js` — cross-surface undo orchestration (block / template / template-part / Global Styles / Style Book)
- `src/store/block-targeting.js` — live-target resolution by clientId or blockPath, revalidated before undo
- `src/store/update-helpers.js` — undo snapshots taken at apply time
- `POST /flavor-agent/v1/activity/{id}/undo` — ordered undo-state transitions (`docs/reference/activity-state-machine.md`)

Tested by: `src/store/__tests__/activity-undo.test.js`, `src/store/__tests__/block-targeting.test.js`; drift-disabled undo cases in the WP 7.0 Playwright suite (`npm run test:e2e:wp70`).

### Fresh

**Guarantee:** a recommendation is applied or reversed only against the context it was generated for; drift is detected, surfaced, and blocking.

Enforced by:

- `inc/Support/RecommendationSignature.php` / `RecommendationReviewSignature.php` / `RecommendationResolvedSignature.php` — dedupe, review-time, and apply-time server signatures
- `src/utils/recommendation-request-signature.js` + `src/utils/context-signature.js` — client request signatures derived from live editor context
- `src/utils/recommendation-stale-reasons.js` + `src/components/StaleResultBanner.js` — effective stale-reason resolution and refresh CTA
- `src/store/recommendation-outcomes.js` — `stale_blocked` outcome records in the diagnostic stream
- Server `resolvedContextSignature` / `reviewContextSignature` on responses; the five `preview-recommend-*` abilities expose the same signatures as a side-effect-free dry-run

Tested by: `tests/phpunit/RecommendationSignatureTest.php`, `SignatureBoundaryTest.php`, `PreviewRecommendationAbilityTest.php`.

## Surface Coverage

| Surface | Loop coverage | Boundary |
| --- | --- | --- |
| Block Inspector | Full loop | Inline-safe attribute applies; structural operations review-gated |
| Template / Template part | Full loop | Review-before-apply; deterministic bounded operations |
| Global Styles / Style Book | Full loop | `theme.json`-bounded paths and theme-backed values |
| Pattern inserter | Generate → validate → freshness-revalidated core insert | Intentionally ranking/browse-only; direct inserts are signature-revalidated but not recorded in apply/undo |
| Content | Generate → validate | Editorial-only; a human copies text into the editor |
| Navigation | Generate → validate | Advisory-only; no executable operations |

Every surface — including the advisory, editorial, and browse-only ones — is attributed at the request level: each suggestion request writes a `request_diagnostic` activity row.

For the Global Styles and Style Book entries, the full loop now also runs **server-side for external agents**: request (`request-style-apply`) → human approval (`POST /flavor-agent/v1/activity/{id}/decision`) → server execute with freshness/operation re-validation → attributed activity row → server-side undo (`undo-activity`). An open Site Editor session does not live-refresh when an external apply lands; activity hydration shows it on the next load.

## External-Agent Parity

External agents reach the layer through the same permission callbacks as the first-party editor (`edit_posts` / `edit_theme_options`, escalating to `edit_post` when a post ID is resolvable):

- the seven `recommend-*` abilities (feature-gated) — exposed as first-class MCP tools on the dedicated server at `/wp-json/mcp/flavor-agent` (`inc/MCP/ServerBootstrap.php`)
- the five `preview-recommend-*` siblings — side-effect-free signature dry-runs, registered before the feature gate is enabled so operators can verify wiring
- nine public read helpers on the universal MCP default server (`meta.mcp.public = true`)
- the four external-apply abilities (feature-gated, dedicated server only): `request-style-apply` queues a review-gated style apply, `get-activity`/`list-activity` are the agent's attribution and status reads, and `undo-activity` is the server-side reverse path with ordered-undo and drift checks

Generation-side governance is caller-independent: external recommendation calls flow through the same executor, schemas, validators, freshness signatures, and request-diagnostic attribution as the editor.

The boundary, stated plainly: external agents can now request style applies, read their attribution, and undo executed style rows — but approval is never exposed to agents. Every external style apply is review-gated through `POST /flavor-agent/v1/activity/{id}/decision` (`manage_options` plus the row's mutation capability) in `Settings > AI Activity`, with freshness re-verified at request and again at approval. AI proposes; WordPress approves. Template, template-part, and block applies remain editor-owned (C2+), and admin-global activity reads stay REST-only.

## Foundation

The layer is built on the WordPress 7.0 AI stack: the WordPress AI plugin (feature registration and Guidelines), the AI Client + `Settings > Connectors` (all text generation), the Abilities API (all recommendation transport), and the MCP Adapter (external exposure). See `docs/reference/abilities-and-routes.md` for exact contracts and `docs/FEATURE_SURFACE_MATRIX.md` for the per-surface demonstration map.

## Update Triggers

Update this document when any of these change: response schemas or operation catalogs, the validation-reasons vocabulary, actionability tiers or review gating, the activity contract or audit projections, the undo lifecycle, freshness-signature composition, or the set of abilities and MCP tools exposed externally. Per `docs/README.md`, also update `docs/FEATURE_SURFACE_MATRIX.md` when loop coverage changes for a surface.
