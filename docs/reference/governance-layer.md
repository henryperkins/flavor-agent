# Governance Layer

This document is the contract reference for Flavor Agent's AI governance layer — the controls every Flavor Agent-mediated AI action passes through before, while, and after it touches a live site.

Use it when you need to answer:

- which control bounds, reviews, attributes, or reverses an AI change
- where each control is enforced in PHP and JS, and where it is tested
- which surfaces run the full governed loop and which stop earlier by design
- what external agents get through the Abilities API and MCP versus the first-party editor

## Thesis

Flavor Agent lets AI work on a live WordPress site without unchecked control. Every AI action it mediates runs through one governance layer: operations validated against bounded schemas, structural changes gated behind review, every apply the plugin owns attributed and recorded server-side, every recorded change reversible with drift detection so an undo never clobbers later human edits. Humans get this through native Gutenberg and Site Editor surfaces; external agents get the same recommendation, validation, and freshness contracts through the Abilities API and MCP. Built on the WordPress 7.0 AI stack. The recommendation surfaces are the demonstration; the governance layer is the product.

Public shorthand: AI proposes. WordPress approves. Operations are bounded, structural/theme changes are reviewed, applies are recorded server-side, and undo is drift-safe.

Attest shorthand: WordPress is pursuing artifact provenance through C2PA; Flavor Agent owns **governed-change attestation** for three WordPress-approved external mutation lanes: `external-style-apply-v1`, `external-template-apply-v1`, and `external-template-part-apply-v1`. A pending apply is approved in `Settings > AI Activity`, executed through its bounded server-side executor, and signed against the resulting lane-specific subject digest. Post-blocks remains excluded because its potentially non-public content has no safe public subject-state contract. This boundary is deliberately narrower than general AI governance attestation and deliberately different from C2PA content credentials.

## Vocabulary Map

The thesis uses positioning vocabulary; the code uses freshness/signature vocabulary. The two map onto each other — positioning language never renames code symbols, options, abilities, hooks, or DB fields.

| Thesis term | Code meaning | Enforcing identifiers |
| --- | --- | --- |
| Drift detection / drift-safe undo | Resolved-context signature revalidation at apply, undo, and decision time; the code says "freshness", "resolved signature", and "stale" | `inc/Support/RecommendationResolvedSignature.php`; live-state revalidation in `src/store/activity-undo.js`; the second freshness check in `inc/Apply/StyleApplyExecutor.php` + `inc/Apply/PendingApplyDecision.php`; `stale_blocked` outcomes |
| Review gate | Review-context signature plus the review-before-apply UI plus the pending-decision path for external applies | `inc/Support/RecommendationReviewSignature.php`; `src/components/AIReviewSection.js` + `src/inspector/block-review-state.js`; `POST /flavor-agent/v1/activity/{id}/decision` |
| Bounded schemas / bounded operations | Strict response schemas plus operation validators plus execution contracts | `inc/LLM/ResponseSchema.php`; `inc/Context/BlockOperationValidator.php` + `inc/Context/BlockRecommendationExecutionContract.php`; template/template-part and style operation vocabularies |
| Attribution | Server-side activity rows plus request tracing | `inc/Activity/Repository.php` and the `request_diagnostic` rows emitted by `inc/Abilities/RecommendationAbilityExecution.php`; `inc/Support/RequestTrace.php` |
| Governed-change attestation | A signed, durable assertion over a reviewed external style, template, or template-part apply, its public-safe operations, and the digest of the state FA produced; self-signed by the site's configured key, not a third-party identity credential | `inc/Attestation/*`; `GET /flavor-agent/v1/attestations/{id}`; `GET /flavor-agent/v1/attestations/keys`; `GET /flavor-agent/v1/attestations/{id}/subject-state`; `wp flavor-agent attestation verify` |

## The Governed Loop

Every executable recommendation runs one loop:

1. **Generate** — the surface ability executes through the shared executor (`inc/Abilities/RecommendationAbilityExecution.php`), which injects guidelines, routes the provider, attaches best-effort developer-docs grounding when the search backend is reachable (grounding never blocks a recommendation; trust and currency are owned by `scripts/update-docs-ai-search.js`), and writes a `request_diagnostic` activity row for every request — including advisory-only and externally invoked ones.
2. **Validate** — model output is parsed against strict response schemas and bounded operation catalogs. Rejected proposals are preserved as diagnostics (`rejectedOperations`, `validationReasons`), never silently dropped, and the client revalidates server-approved operations and fails closed on identity mismatch.
3. **Review** — structural and theme-level changes require an explicit review step before apply; inline-safe changes are limited to bounded attribute updates classified by the actionability tiers.
4. **Apply + record** — applies the plugin owns execute deterministically and write server-backed activity rows with provenance (provider path, model, prompt, route).
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
- `inc/Activity/GovernanceLearningReport.php` — optional bounded aggregate `learningReport` payload for global admin activity reads, with sanitized outcome rates and representative activity ids only
- `POST /flavor-agent/v1/activity` — persists apply rows and scoped diagnostics with provider path, model, prompt, reference, and route; token usage and request latency are not projected into any column and are recorded only inside the request.ai JSON blob of `request_diagnostic` rows
- `inc/Admin/ActivityPage` + `src/admin/activity-log.js` — the `Settings > AI Activity` approval/audit/attestation-discovery surface (decision controls for pending external applies; non-pending rows stay inspection-only)

Tested by: `tests/phpunit/RecommendationAbilityExecutionTest.php`, `ActivityRepositoryTest.php`, `ActivityPermissionsTest.php`, `ActivitySerializerTest.php`, `ActivityPageTest.php`.

### Attested

**Guarantee:** an attested external Global Styles, Style Book, template, or template-part apply produces a durable, public-safe, site-key-signed governed-change statement that can be verified outside wp-admin. The statement binds the approval, lane-specific bounded operations, before/after digests, public key id, and optional revert/supersede chain. Flavor Agent names each owned boundary explicitly as `external-style-apply-v1`, `external-template-apply-v1`, or `external-template-part-apply-v1` so the claim cannot blur into general AI governance. This is tamper-evident self-attestation rooted in the site's published key, not C2PA emission, third-party identity, or a transparency log.

Owned claim, precisely:

- WordPress approved a pending external style, template, or template-part apply in `Settings > AI Activity`.
- Flavor Agent executed that lane's bounded operation set server-side for the request.
- The resulting canonical style or block-content subject hashed to the signed digest at attestation time.
- Later verification can show that subject as intact, changed, reverted, or superseded.

Not claimed by this attestation:

- that all AI activity on the site complied with policy
- that a provider, model, or agent was broadly safe or approved for every use
- that upstream C2PA credentials were validated or that content is true, unbiased, or human-authored

Enforced by:

- `inc/Attestation/Canonicalizer.php` — canonical Global Styles / Style Book subject serialization and digest computation
- `inc/Attestation/BlockContentCanonicalizer.php` — canonical template / template-part block serialization and digest computation, shared with executor drift checks
- `inc/Attestation/StatementBuilder.php` — public-safe in-toto statement builder and allowlist boundary
- `inc/Attestation/Signer.php` + `inc/Attestation/KeyManager.php` — Ed25519 signing and published key registry support
- `inc/Attestation/Repository.php` — append-only, activity-retention-independent attestation rows and revert/supersede lookup
- `inc/Attestation/AttestationService.php` — records attestations after approved applies and chained undos in each registered attestation lane
- `inc/REST/AttestationController.php` — unauthenticated envelope, verification-summary, keys, and subject-state routes for external verification
- `inc/CLI/AttestationCommand.php` and `tools/attestation-verify.php` — local and stranger-facing verifiers that report signature, live-state, revert, and supersession outcomes
- `src/admin/activity-log.js` — AI Activity detail affordances for the site-run verification summary, raw public endpoint links, and attestation chain context

Tested by: `tests/phpunit/AttestationCanonicalizerTest.php`, `BlockContentCanonicalizerTest.php`, `AttestationStatementBuilderTest.php`, `AttestationSignerTest.php`, `AttestationKeyManagerTest.php`, `AttestationRepositoryTest.php`, `AttestationServiceTest.php`, `AttestationControllerTest.php`, `AttestationVerifierTest.php`, `AttestationCommandTest.php`, `AttestationRemoteVerifierTest.php`, `ExternalApplyLifecycleTest.php`, and `ApplyAbilitiesTest.php`, plus admin projection coverage in `ActivitySerializerTest.php`, `ActivityRepositoryTest.php`, and `src/admin/__tests__/activity-log.test.js`.

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
- Server `resolvedContextSignature` / `reviewContextSignature` on responses; the six `preview-recommend-*` abilities expose the same signatures as a side-effect-free dry-run

Tested by: `tests/phpunit/RecommendationSignatureTest.php`, `SignatureBoundaryTest.php`, `PreviewRecommendationAbilityTest.php`.

## Surface Coverage

| Surface | Loop coverage | Boundary |
| --- | --- | --- |
| Block Inspector | Full loop | Inline-safe attribute applies; structural operations review-gated |
| Template / Template part | Full loop | Review-before-apply; deterministic bounded operations |
| Post-blocks (post/page structure) | Full loop | Review-before-apply; lock-aware bounded operations; external-agent surface only, no editor UI |
| Global Styles / Style Book | Full loop | `theme.json`-bounded paths and theme-backed values |
| Pattern inserter | Generate → validate → freshness-revalidated core insert | Intentionally ranking/browse-only; direct inserts are signature-revalidated but not recorded in apply/undo |
| Content | Generate → validate | Editorial-only; a human copies text into the editor |
| Navigation | Generate → validate | Advisory-only; no executable operations |

Every surface — including the advisory, editorial, and browse-only ones — is attributed at the request level: each suggestion request writes a `request_diagnostic` activity row.

For the Global Styles and Style Book entries, the full loop now also runs **server-side for external agents**: request (`request-style-apply`) → human approval (`POST /flavor-agent/v1/activity/{id}/decision`) → server execute with freshness/operation re-validation → attributed activity row → server-side undo (`undo-activity`). An open Site Editor session does not live-refresh when an external apply lands; activity hydration shows it on the next load.

The **template-part** surface now has the same server-side external lane: request (`request-template-part-apply`) → human approval (`POST /flavor-agent/v1/activity/{id}/decision`) → server execute of ≤3 path-addressed bounded operations against one `wp_template_part`, atomic and re-validated with drift gates → attributed activity row → Ring III attestation in `external-template-part-apply-v1` → server-side undo (`undo-activity`, dispatched through the shared executor registry) with a linked revert attestation.

The **page-level template** surface now has a third governed external lane: request (`request-template-apply`) → human approval (`POST /flavor-agent/v1/activity/{id}/decision`) → server execute of one bounded `insert_pattern` against one `wp_template`, atomic and re-validated with drift gates → attributed activity row → Ring III attestation in `external-template-apply-v1` → server-side undo (`undo-activity`, also dispatched through the shared executor registry) with a linked revert attestation. The block surface remains editor-owned and is not yet exposed as an external apply lane.

The **post-blocks** surface adds a fourth governed external lane, extending the loop to individual posts and pages rather than theme-territory documents: request (`request-post-blocks-apply`) → human approval (`POST /flavor-agent/v1/activity/{id}/decision`, gated by `manage_options` plus `edit_post` on the target post) → server re-collects the live document target contract (post-type/status allowlist plus lock-aware target exclusion for `attrs.lock`/`templateLock`) and executes ≤3 path-addressed bounded operations against the post's `post_content` atomically through the same structural-operation grammar and apply pass the template-part lane uses → attributed activity row (scope key `{postType}:{postId}`) → server-side undo (`undo-activity`). It remains **not attested** because the public `subject-state` route cannot expose potentially non-public `post_content`; it needs a separate conditional-subject-state and ID-only subject design. This surface has no first-party editor UI; it exists to demonstrate the governance layer's parity boundary extends to arbitrary content documents, not only theme-territory ones.

Ring III attestation coverage is intentionally narrower than the full governed-loop map: v1 attaches to external style, template, and template-part approval paths because each has a durable human decision and canonical artifact bytes. Advisory/editorial surfaces, editor-owned applies, and post-blocks remain Govern evidence unless promoted through a separate surface plan with a safe public subject contract, real artifact digest, and approval moment.

## External-Agent Parity

External agents reach the layer through the same permission callbacks as the first-party editor (`edit_posts` / `edit_theme_options`, escalating to `edit_post` when a post ID is resolvable):

- the eight `recommend-*` abilities (feature-gated) — exposed as first-class MCP tools on the dedicated server at `/wp-json/mcp/flavor-agent` (`inc/MCP/ServerBootstrap.php`)
- the six `preview-recommend-*` siblings — side-effect-free signature dry-runs, registered before the feature gate is enabled so operators can verify wiring
- ten public read helpers on the universal MCP default server (`meta.mcp.public = true`)
- the seven external-apply abilities (feature-gated, dedicated server only): `request-style-apply` queues a review-gated style apply, `request-template-apply` queues a review-gated page-level template structural apply, `request-template-part-apply` queues a review-gated template-part structural apply, `request-post-blocks-apply` queues a review-gated post/page structural apply, `get-activity`/`list-activity` are the agent's attribution and status reads, and `undo-activity` is the server-side reverse path with ordered-undo and drift checks across all four lanes

Generation-side governance is caller-independent: external recommendation calls flow through the same executor, schemas, validators, freshness signatures, and request-diagnostic attribution as the editor.

The boundary, stated plainly: external agents can now request style, template, template-part, and post-blocks applies, read their attribution, and undo executed style, template, template-part, and post-blocks rows — but approval is never exposed to agents. Every external apply is review-gated through `POST /flavor-agent/v1/activity/{id}/decision` (`manage_options` plus the row's mutation capability) in `Settings > AI Activity`, with freshness re-verified at request and again at approval. AI proposes; WordPress approves. The block surface still remains editor-owned, and admin-global activity reads stay REST-only.

### Recommendation context trust boundary

Recommendation context has two provenance paths with different trust postures. The **first-party `editorContext`** path trusts the client-supplied block manifest — introspected `inspectorPanels`, `bindableAttributes`, and content/config attribute keys — as the source for the execution contract (`BlockRecommendationExecutionContract`) that gates which suggestions survive validation. The **external `selectedBlock`** path does not: it re-introspects the block type server-side (`ServerCollector::for_block`), so an external caller cannot fabricate capabilities.

Trusting the first-party manifest is safe because recommendations are advisory: the local apply still runs through the block editor's real `supports`/lock enforcement, and **no governed write consumes a recommendation-supplied execution contract**. The four external-apply lanes (style, template, template-part, post-blocks) re-collect and re-validate their target contract server-side at request and again at approval, with no filter seam. A recommendation's `executionContract` is an advisory shaping and attribution artifact, never an apply authority.

### Data flow to the provider

Generating a recommendation sends the relevant document slice to the site-configured AI provider through core `Settings > Connectors`. For the block surface that includes the selected block's current attributes (which can carry authored content, image URLs, and other values); for the structural surfaces it includes the block-tree structure with per-node attributes. This is inherent to advising on a block or document, and it follows the site's own Connectors configuration — Flavor Agent adds no separate provider credential for chat. Every generation is attributed server-side with a request-level activity row.

## Foundation

The layer is built on the WordPress 7.0 AI stack: the WordPress AI plugin (feature registration and Guidelines), the AI Client + `Settings > Connectors` (all text generation), the Abilities API (all recommendation transport), and the MCP Adapter (external exposure). See `docs/reference/abilities-and-routes.md` for exact contracts and `docs/FEATURE_SURFACE_MATRIX.md` for the per-surface demonstration map.

## Upstream Governance Context

The WordPress AI roadmap is moving toward shared governance primitives in Core and the canonical AI plugin: global provider discovery, AI Request Logs, Connector Approvals, ability exposure controls, model/provider routing, usage safeguards, and a proposed unified AI Management layer. The public `Automattic/agents-api` package adds a concrete agent-runtime substrate to watch above AI Client and Abilities API: agent registration, conversation loops, tool mediation, principals, memory/transcripts/sessions, channels, workflows, and pending-action envelopes.

Flavor Agent should treat those as the **outer policy plane**. When those upstream contracts stabilize, Flavor Agent should plug into them for site-wide permission, metering, routing, request-log, and ability-exposure decisions rather than duplicating them.

This document owns the **inner mutation-governance contract**: for changes Flavor Agent mediates, the plugin still has to bound the operation, gather the right context, expose a review gate when the operation is structural or theme-level, attribute the request and apply, verify freshness before execution, and block unsafe undo after drift. Core can decide whether a plugin may use AI; Flavor Agent still decides whether a proposed block/template/style mutation is safe to apply to the current document.

The same split applies to Attest. Upstream C2PA text/image provenance work can attest artifact provenance facts such as publisher identity, creation/editing, and ingredient history. Request logs, service accounts, visual revisions, and Site Agent concepts provide evidence and identity primitives. They do not yet define a general AI-governance attestation workflow. Flavor Agent's attestation boundary is therefore the governed-change assertion it can prove locally: a specific FA-mediated mutation on the `external-style-apply-v1`, `external-template-apply-v1`, or `external-template-part-apply-v1` lane was requested, reviewed by WordPress, applied through its bounded executor, signed against the resulting state, and later verified as intact, changed, reverted, or superseded. The wp-admin verification affordance is a convenience summary served by the site; independent verification remains the standalone HTTP verifier against the public envelope, keys, and subject-state endpoints.

That split is intentional product positioning. The upstream Site Agent / AI Workspace direction, now with Agents API as a named substrate candidate, validates the external-agent path, but it does not make approval an agent capability. AI can propose actions through abilities and MCP; WordPress holds the human approval decision in `Settings > AI Activity` for Flavor Agent-owned external applies. If Flavor Agent later integrates with Agents API, it should feature-detect `wp_register_agent()` / `wp_agents_api_init` and keep product UX plus mutation apply/approve/undo policy local.

## Update Triggers

Update this document when any of these change: response schemas or operation catalogs, the validation-reasons vocabulary, actionability tiers or review gating, the activity contract or audit projections, the undo lifecycle, freshness-signature composition, or the set of abilities and MCP tools exposed externally. Per `docs/README.md`, also update `docs/FEATURE_SURFACE_MATRIX.md` when loop coverage changes for a surface.
