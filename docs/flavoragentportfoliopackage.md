# Flavor Agent portfolio package

## 1) One-pager

### Flavor Agent — governance layer for AI-mediated WordPress changes

**Role:** Builder / AI systems engineer / WordPress product engineer
**Status:** `0.1.0` release candidate / pre-tag
**Core stack:** WordPress plugin, PHP 8.2, Gutenberg, Site Editor, WordPress Abilities API, MCP, WordPress REST for activity/decisions/sync/attestation, Ed25519 site-key attestations, `@wordpress/*` packages (React), DataViews, PHPUnit, Jest, Playwright
**Retrieval & grounding (optional infrastructure):** WordPress AI Client / Connectors for text generation; optional Cloudflare AI Search for private pattern retrieval and WordPress developer-doc grounding; optional Cloudflare Workers AI embeddings plus Qdrant for vector-backed pattern retrieval
**License:** GPL-2.0-or-later

Flavor Agent is a WordPress plugin that lets AI work on a live site without unchecked control. It routes AI-mediated work through a governed loop: generate, validate, review, apply, record and optionally attest, then reverse safely when the live document still matches the recorded post-apply state.

The user experience stays inside WordPress instead of becoming a separate chatbot. Flavor Agent appears in native Gutenberg and wp-admin surfaces: Block Inspector recommendations, pattern inserter ranking, content recommendations, navigation guidance, template and template-part recommendations, Global Styles, Style Book, and `Settings > AI Activity`.

The programmatic layer exposes the same governance model through the WordPress Abilities API and MCP. The registry defines **35 ability contracts**: eight recommendation abilities, six signature-only preview/preflight abilities, fourteen helper/search/infra abilities, and seven governed external-apply abilities.

**Proof points**

- **8 first-party recommendation surfaces** plus one admin approval/audit surface.
- **35 defined Ability contracts**, reconciled by category:

| Category                                   |  Count |
| ------------------------------------------ | -----: |
| Recommendation abilities                   |      8 |
| Preview / preflight abilities              |      6 |
| Public read / helper abilities             |     10 |
| Abilities-API-only helper / read abilities |      3 |
| Docs-search ability                        |      1 |
| Governed external-apply abilities          |      7 |
| **Total**                                  | **35** |

Of the fourteen helper/search/infra contracts, ten are public read helpers exposed through the universal MCP discovery path; three (`list-synced-patterns`, `get-synced-pattern`, `check-status`) stay Abilities-API-only; and `search-wordpress-docs` is a separate `manage_options` docs-search tool.

- **Governed external applies:** agents can request style, template, template-part, or post-blocks changes, but only admins approve or reject them.
- **Server-backed audit and undo:** activity rows store provenance, request metadata, before/after state, undo state, and admin projection fields.
- **Self-signed attestation layer:** approved external style, template, and template-part applies can emit tamper-evident site-key statements, public key material, live subject-state checks, and chained revert attestations when a signing key is configured. Post-blocks remains excluded pending a public-safe subject design.
- **Verification evidence:** `npm run verify -- --skip-e2e` recorded `pass` on 2026-07-18 with build, JS lint, Plugin Check, 1,644 JS tests, PHP lint, and 1,827 PHP tests / 8,335 assertions green. The targeted WP 7.0 approval spec passed 4/4, and its real template attestation returned `signature_valid` plus `live_matches_subject` with the live digest equal to the signed after digest.

**Ethics / governance note**

The main risk is overconfident AI mutating a publishing system. Flavor Agent mitigates that by bounding operations, review-gating structural/theme changes, attributing every request/apply, enforcing freshness checks, optionally emitting signed site-key attestations for governed external style, template, and template-part applies, and blocking undo when the site has drifted.

> Agent governance isn't a WordPress-shaped problem — any system that lets AI act on live state needs the same guarantees: bounded operations, gated review, attribution, provable rollback. I built the layer on WordPress because that's where my depth is; the pattern is portable.

---

## 2) Annotated case study

### Context

AI tools for WordPress often start as chat interfaces: ask for help, get generic advice, then manually copy changes back into the editor. Flavor Agent explores a more product-native path. It treats WordPress itself as the workflow surface and asks: **How can AI help edit a live site without becoming an unchecked autonomous actor?**

The repo's answer is a governance layer. Recommendations are the visible demo, but the deeper product is a contract for safe AI-mediated change: bounded schemas, review gates, activity attribution, freshness signatures, site-key attestation, and drift-safe undo.

### Objective

Build a WordPress-native AI system that can:

1. Use live editor, theme, block, pattern, and site context.
2. Suggest useful changes across real Gutenberg and Site Editor surfaces.
3. Keep structural and theme-level changes reviewable.
4. Record AI actions server-side with provenance.
5. Reverse changes only when the live state still matches the recorded post-apply state.
6. Expose safe programmatic contracts to external agents through Abilities API and MCP.
7. Emit self-signed public attestations for approved external style, template, and template-part applies when a site signing key is configured.

### Approach

Flavor Agent uses one governed loop for executable recommendations.

**Generate.** Recommendation abilities run through a shared executor that normalizes input, attaches guidelines, routes provider metadata, and writes request diagnostics for non-signature-only requests.

**Validate.** Model output is constrained by strict JSON schemas and operation catalogs. The response schema uses strict objects with `additionalProperties: false`, and executable operations are limited to known vocabularies such as block `insert_pattern` / `replace_block_with_pattern`, style `set_styles` / `set_block_styles` / `set_theme_variation`, and bounded template operations.

**Review.** Structural and theme-level changes are review-gated. Inline-safe changes are separated from review-safe and advisory suggestions so the UI can avoid pretending every recommendation is executable.

**Apply and record.** Applies owned by Flavor Agent write server-backed activity rows with request metadata, before/after state, undo state, document scope, and admin projection fields for filtering.

**Attest.** Approved external Global Styles, Style Book, template, and template-part applies can produce an in-toto-style statement over the public-safe applied operations, before/after digests, actor role, decision timing, and related activity id. The statement is signed with the site's configured Ed25519 key, stored in a retention-independent attestation table, and exposed with public verification routes. No key means no attestation, never a fake placeholder. Post-blocks remains deliberately un-attested because its potentially non-public content has no safe public subject-state contract.

**Reverse.** Undo checks whether the live document still matches the recorded post-apply state. If the site changed after Flavor Agent acted, undo fails closed instead of clobbering human edits.

### Product surfaces

Flavor Agent currently demonstrates this governance layer across:

- Block Inspector recommendations.
- Pattern inserter ranking shelf.
- Content recommendation panel.
- Navigation advisory guidance.
- Site Editor template recommendations.
- Template-part recommendations.
- Global Styles recommendations.
- Style Book recommendations.
- `Settings > AI Activity` approval/audit page.

The boundaries are explicit. Content is editorial-only, pattern recommendations are browse/rank-first, navigation is advisory-only, and governed external-agent applies are limited to style, template, template-part, and post-blocks operations with admin-only approval.

### Programmatic surface

The registry defines 35 Abilities API contracts. The first-party UI executes recommendation abilities through the WordPress Abilities API, while REST remains for activity creation, admin decisions, claim coordination, undo transitions, manual pattern sync, and public attestation reads.

The dedicated MCP server exposes the eight recommendation abilities and seven external-apply abilities as direct tools. Ten read helpers are public through the universal MCP discovery path; three helper/read contracts stay Abilities-API-only because they expose synced-pattern or backend inventory details. The default MCP/Abilities path keeps preview and helper abilities available for safer preflight and discovery.

### External-agent governance

External agents can request governed style, template, template-part, or post-blocks applies, read status/attribution, list scoped activity, and undo executed rows. Approval is deliberately **not** exposed as an ability. The admin decision stays in `Settings > AI Activity`, guarded by `manage_options` and the row's contextual mutation capability.

The decision service performs a second freshness check at approval time. If the target entity changed after the request was created, approval fails closed rather than applying stale operations.

When `FLAVOR_AGENT_ATTEST_PRIVATE_KEY` or the `flavor_agent_attest_private_key` filter provides an Ed25519 secret key, approved external style, template, and template-part applies create companion attestation rows. Public routes expose `/attestations/{id}`, `/attestations/{id}/verification`, `/attestations/keys`, and `/attestations/{id}/subject-state`; the verifier checks signature integrity and whether the lane-specific live subject still matches the attested digest. The admin detail view can run a site-served verification summary for convenience, while independent verification is performed with `php tools/attestation-verify.php <baseUrl> <attestationId>` against the public envelope, key, and subject-state endpoints. Undoing an attested row can record a chained revert attestation that points back to the prior attestation id. Post-blocks remains outside this public proof boundary.

### Ranking and feedback loop

Flavor Agent includes contextual ranking infrastructure rather than relying only on model confidence. `RecommendationContextScorer` uses evidence such as prompt match, operation fit, support fit, docs freshness, pattern readiness, accessibility fit, design semantics, contrast preservation, preset adherence, responsive sanity, and complexity fit.

The blend contract combines model, deterministic, and contextual scores with default weights of `0.30`, `0.45`, and `0.25`.

Outcome diagnostics track recommendation events such as `shown`, `selected_for_review`, `stale_blocked`, `validation_blocked`, `insert_failed`, and `pattern_inserted_from_shelf`.

### Evaluation

The repo records meaningful engineering evidence rather than adoption metrics.

Current non-browser evidence: `npm run verify -- --skip-e2e` generated `output/verify/summary.json` with status `pass` on **2026-07-18**. Build, JS lint, Plugin Check, JS unit, PHP lint, and PHPUnit all passed; the recorded counts are **109 JS suites / 1,644 tests** and **1,827 PHP tests / 8,335 assertions**. The docs and both E2E suites were intentionally outside that aggregate command.

Current targeted attestation evidence: the cross-surface PHPUnit target passed **213 tests / 874 assertions**, the AI Activity helper Jest target passed **75 tests**, and `npm run check:docs` passed on **2026-07-18**.

Current targeted browser governance evidence: `npm run test:e2e:wp70 -- tests/e2e/flavor-agent.approvals.spec.js` passed **4/4** on **2026-07-18**, covering reject-with-note, fail-closed drift, and real template-attestation approval. The resulting public verification response reported `signature_valid` and `live_matches_subject`; the live subject digest exactly matched the signed after digest, and the spec removed its temporary signing key afterward.

Latest full recorded browser-suite evidence is historical: Playground passed across two isolated full-suite runs on **2026-06-11**, and the Docker-backed WordPress 7.0 Site Editor suite passed on **2026-05-02** with `20 passed / 0 failed`. Full browser gates are re-run on the exact release commit before tagging.

Browser evidence is split intentionally: Playground covers fast post-editor smoke tests, while a Docker-backed WordPress 7.0 Site Editor harness covers refresh/drift-sensitive flows.

### Known limits

Flavor Agent is a release-candidate project, not a production-scale observability product. The admin activity page is a first governance-console slice with external style, template, template-part, and post-blocks decisions, structured diff/before-after summaries, selected-row actions, and a first rich visual diff layer for style-governance rows, but broader cross-operator workflow and observability work remains open.

Attestation v1 is self-signed site-key attestation scoped to governed external style, template, and template-part applies and their attested reverts. It is not C2PA, not third-party identity, and not a transparency log. No attestation is emitted unless the site has a valid signing key configured, and post-blocks remains excluded pending a public-safe subject design.

The roadmap still includes broader audit/discovery, tighter audit metadata, cross-operator workflows, learning attribution, learning reports, fixture harvest, bounded local ranking feedback, and editable site preference summaries.

---

## 3) Three-slide explainer

### Slide 1 — Problem → product thesis

**Title:** AI proposes. WordPress approves.

**Main visual:**
Unchecked agent path vs governed WordPress path.

Unchecked path:
Prompt → model output → direct mutation → unclear provenance → risky undo

Flavor Agent path:
WordPress context → recommendation ability → schema validation → review gate → server-backed activity → optional site-key attestation → drift-safe undo

**Callouts**

- Native Gutenberg and Site Editor surfaces.
- 8 recommendation surfaces.
- 35 defined Abilities API contracts.
- Admin approval/audit page.
- External agents can request governed style, template, template-part, and post-blocks applies, but admins decide.

### Slide 2 — Architecture

**Title:** One governed loop across human and agent workflows

**Diagram**

Editor / MCP client
→ WordPress Abilities API
→ Recommendation executor
→ strict response schemas
→ operation validators
→ review-safe / inline-safe / advisory classification
→ activity repository
→ attestation repository when key configured
→ admin audit / inline undo
→ freshness-checked apply or undo

**Small table**

| Control               | What it prevents                           |
| --------------------- | ------------------------------------------ |
| Strict schemas        | Free-form model output becoming executable |
| Operation validators  | Unsupported block/style/template mutations |
| Review gates          | Silent structural or theme-level changes   |
| Server activity       | Untraceable AI actions                     |
| Freshness signatures  | Applying or undoing against stale state    |
| Site-key attestations | Mutable audit rows posing as public proof  |

### Slide 3 — Proof, boundaries, next

**Title:** A safer mutation layer for WordPress AI

**Proof**

- `0.1.0` release candidate / pre-tag.
- 35 defined ability contracts.
- Server-backed activity table.
- Admin approval flow for external style, template, template-part, and post-blocks applies.
- Self-signed attestation layer for approved external style, template, and template-part applies and attested reverts.
- Current recorded non-browser run with Plugin Check green (2026-07-18): 1,644 JS tests and 1,827 PHP tests / 8,335 assertions.
- Targeted WP 7.0 approval proof green (4/4), including a live template attestation that verifies as `signature_valid` + `live_matches_subject`.
- Playground and WP 7.0 browser harness coverage.

**Boundaries**

- No auto-publishing.
- No silent post rewrites.
- No phone-home on activation.
- No external-agent approval power.
- No C2PA, third-party identity, or transparency log in attestation v1.
- Content, navigation, and patterns stop earlier in the governed loop by design.

**Next**

- Broader audit/discovery actions.
- Cross-operator workflow follow-through.
- Learning attribution and reports.
- Bounded local ranking feedback.
- Swap the WordPress 7.0 harness onto the released "Armstrong" stable image.

---

## 4) README / portfolio section

Paste the following directly into the repo's portfolio section. Do not wrap it in an outer fence — only the command block is fenced.

---

## Portfolio summary

Flavor Agent is a governance layer for AI-mediated WordPress changes.

It lets AI propose block, pattern, content, template, template-part, navigation, Global Styles, and Style Book recommendations inside native WordPress editing surfaces, while WordPress keeps control of validation, review, attribution, freshness checks, and undo.

The key design principle is: AI proposes; WordPress approves.

**Status:** `0.1.0` release candidate / pre-tag.

## What I built

- Native Gutenberg and Site Editor recommendation surfaces.
- A server-backed activity/audit system for AI requests and applied changes.
- Drift-safe undo for Flavor Agent-owned executable changes.
- Strict response schemas and bounded operation validators.
- Contextual ranking with model, deterministic, and context score components.
- Recommendation outcome diagnostics for shown, selected, blocked, failed, and inserted states.
- WordPress Abilities API contracts for recommendations, previews, helpers, docs search, infra, and governed external apply.
- MCP exposure for external agents, with admin-only approval retained in WordPress.
- A self-signed attestation layer for approved external style, template, and template-part applies, with public verification endpoints for signed statements, site keys, and live subject-state digests; post-blocks remains excluded pending a public-safe subject design.

## Safety boundaries

Flavor Agent does not auto-publish content, silently rewrite posts, contact site visitors, or own text-generation credentials. Text generation runs through the WordPress AI Client and `Settings > Connectors`, and falls back to Jetpack AI over an existing Jetpack connection where the WordPress AI Client runtime is absent. Plugin-owned settings cover embeddings, pattern retrieval, docs grounding, guidelines, and sync.

Attestation v1 is site-key self-attestation only: no C2PA, no third-party identity, no transparency log, and no prompts, provider payloads, or PII in signed statements.

## Verify locally

Representative local verification depends on the prepared nightly/trunk WordPress environment described in [reference/local-environment-setup.md](reference/local-environment-setup.md): Docker, companion plugins, `Settings > Connectors`, Playwright browsers, and the host-side Plugin Check prerequisites are all part of the contract. From that prepared environment, use:

```bash
npm run verify -- --skip-e2e      # fast non-browser loop
npm run test:e2e:playground       # Playground smoke
npm run test:e2e:wp70             # Docker-backed WP 7.0 harness
npm run verify                    # full recorded pipeline when prerequisites are ready
```

If Plugin Check prerequisites are unavailable locally, `npm run verify -- --skip=lint-plugin` is an explicit coverage waiver for iteration, not release-signoff evidence.

## Demo sequence

### In-editor (governed UI)

1. Show `Settings > Flavor Agent` readiness: Connectors-owned text generation plus plugin-owned pattern/docs/embedding configuration.
2. Show a Block Inspector recommendation with bounded inline apply and undo.
3. Show a Global Styles or Style Book recommendation in review-first mode.
4. Show `Settings > AI Activity` with a pending external style apply.
5. Approve or reject the pending row and inspect provenance, freshness evidence, before/proposed/after comparison, undo state, and — when a signing key is configured — the row's attestation verify affordance.
6. Show pattern recommendations in the native inserter shelf as browse/rank-first, not plugin-owned mutation.

### Programmatic (external agent via MCP)

The same governance model an outside agent sees — verified end-to-end against a live site.

1. **Discover.** `discover-abilities` on the universal default server returns the public MCP surface: ten read helpers, six signature-only `preview-recommend-*` dry runs, and the separate `search-wordpress-docs` tool (17 public abilities total). `get-ability-info` shows each contract's input/output schema. The eight `recommend-*` and seven apply/activity tools live on the dedicated `flavor-agent` MCP server; neither server exposes an approve/decision tool — approval stays admin-only in wp-admin.
2. **Read.** `execute-ability` on a read-only ability (e.g. `get-active-theme`, or a `preview-recommend-*` signature preflight) executes without invoking the AI Connector.
3. **Propose.** `recommend-style` returns a bounded, preset-backed operation plus review/apply freshness signatures, and records a diagnostic activity row.
4. **Request.** `request-style-apply` with the operation, the live `currentConfig`, and those signatures creates a pending row and mutates nothing. Freshness is enforced on two gates — recomputed signatures and `currentConfig` equal to the live entity; stale input is rejected as drift (re-derive with `preview-recommend-style`).
5. **Approve.** An administrator approves the pending row in `Settings > AI Activity`; a second freshness check runs against the live entity before the change applies.
6. **Attest & verify.** `get-activity` returns the row's attestation links for the public envelope, live subject-state, and the admin-facing site-run verification summary. With a signing key configured, independent verification is still `php tools/attestation-verify.php <baseUrl> <attestationId>` against the public envelope, JWKS (`/attestations/keys`), and live subject-state endpoints — no credentials — reporting signature validity, live-subject match, revert/supersession accountability, and tamper detection.
7. **Reverse.** `undo-activity` restores the before-state when the live document still matches the recorded after-state, and emits a chained revert attestation.

> **Presenter notes:** attestation is key-gated — without `FLAVOR_AGENT_ATTEST_PRIVATE_KEY` (base64 Ed25519 secret) it records no attestation rather than a fake one. The external MCP client authenticates with a WordPress Application Password over HTTPS.

---

> **Authoring note:** built with Claude-assisted development held to the standard this project applies to AI — every AI-drafted change reviewed before merge, attributed in version control, and reversible. The governance pattern isn't only the product; it's how the product was made.

---

## 5) LinkedIn project section

**Flavor Agent — Governance layer for AI-mediated WordPress changes**

Agent governance isn't a WordPress-shaped problem — any system that lets AI act on live state needs the same guarantees: bounded operations, gated review, attribution, provable rollback. I built the layer on WordPress because that's where my depth is; the pattern is portable.

Built a WordPress plugin that lets AI propose site-editing changes without giving the model unchecked control. Flavor Agent integrates into native Gutenberg, Site Editor, and wp-admin surfaces, including block recommendations, pattern ranking, content suggestions, template/template-part recommendations, Global Styles, Style Book, navigation guidance, and `Settings > AI Activity`.

The core work is a governed mutation loop: strict schemas, bounded operation validators, review gates for structural/theme changes, server-backed activity attribution, freshness checks, optional site-key attestation, and drift-safe undo. The registry defines 35 WordPress Ability contracts and exposes MCP tools for external agents while keeping approval admin-only inside WordPress.

**Highlights:** WordPress Abilities API, MCP, Ed25519 self-attestation, PHP 8.2, Gutenberg, Site Editor, `@wordpress/*` packages (React), DataViews, Playwright, PHPUnit, Jest. Optional retrieval/grounding infrastructure: Cloudflare AI Search, Cloudflare Workers AI, Qdrant.

---

## 6) Resume bullets

- Built **Flavor Agent**, a WordPress governance layer for AI-mediated editing that routes recommendations through bounded schemas, review gates, server-side attribution, freshness checks, and drift-safe undo.
- Implemented **35 WordPress Abilities API contracts** across recommendations, preview preflights, helper/read tools, docs search, infrastructure checks, and governed external apply.
- Designed native Gutenberg/Site Editor surfaces for block, pattern, content, navigation, template, template-part, Global Styles, and Style Book recommendations.
- Built `Settings > AI Activity`, a wp-admin approval/audit surface for external style, template, template-part, and post-blocks apply requests, provenance inspection, freshness evidence, and undo state.
- Added a self-signed attestation layer for governed external style, template, and template-part applies, exposing signed statements, public site keys, live subject-state digests, and chained revert attestations for independently checkable site-key proof.
- Added contextual ranking and outcome diagnostics so recommendations carry model, deterministic, and context score evidence plus events such as shown, selected for review, stale blocked, validation blocked, and inserted from shelf.
- Maintained verification gates across JS build/lint/unit, PHP lint/PHPUnit, Plugin Check, docs freshness checks, Playground browser smoke tests, and Docker-backed WordPress 7.0 Site Editor tests.

---

## 7) Claims to avoid in public copy

Do **not** say:

- "Autonomous WordPress agent."
- "Automatically edits/publishes your site."
- "REST API recommendation endpoints."
- "External agents can apply any WordPress change."
- "Cloudflare docs grounding blocks unsafe recommendations."
- "Full observability product."
- "C2PA Content Credentials."
- "Third-party verified provenance."
- "Transparency-log-backed attestations."
- "Attestations for every recommendation/apply surface."
- "Production adoption metrics" unless you have real usage data.
- "Production" as a maturity claim while the project is pre-tag with no installs.

Say instead:

> Flavor Agent is a WordPress-native governance layer for AI-mediated changes: bounded recommendations, review-gated mutation, server-side attribution, optional self-signed site-key attestation for governed external style, template, and template-part applies, and drift-safe undo.
