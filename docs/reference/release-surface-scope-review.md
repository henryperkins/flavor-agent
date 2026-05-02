# Release Surface Scope Review

Date: 2026-04-30

This document defines the release stopping point for Flavor Agent across its current editor, admin, and programmatic surfaces.

Companion: [`release-submission-and-review.md`](./release-submission-and-review.md) covers the WordPress.org submission, guideline audit, reviewer-cycle, and approval gates that follow once the plugin is internally release-quality.

## Core Identity

Flavor Agent is a WordPress-native recommendation and review layer for editing surfaces.

The product promise is:

> Context-aware recommendations plus bounded review/apply/undo inside native editing surfaces.

Flavor Agent should help a user make better block, content, pattern, template, navigation, and style decisions without becoming the general system that owns the site, providers, logs, pattern insertion, or arbitrary mutations.

## Product Boundaries

Flavor Agent owns:

- Context gathering for the active WordPress editing surface.
- Recommendation quality and explanation.
- Deterministic validation of executable suggestions.
- Native Gutenberg, Site Editor, inserter, settings, and admin affordances.
- Bounded apply paths only where the plugin can review, validate, record, and undo.
- Clear setup and degraded-state messaging when providers, embeddings, Qdrant, capabilities, or WordPress surface support are missing.

Flavor Agent does not own:

- A site-wide autonomous agent.
- A frontend chat agent.
- A provider router or model picker.
- A general observability console.
- Gutenberg pattern inserter ownership.
- A general-purpose mutation framework.
- Free-form execution of model output.

If a feature makes Flavor Agent broader than context-aware recommendations plus bounded native review/apply/undo, it should stop.

## Release Rule

A surface merits release presence only if it passes all of these checks:

1. It appears in a native WordPress surface where the user already makes the relevant decision.
2. It improves that decision with context-aware recommendation, review, explanation, or setup feedback.
3. Any mutation is bounded by deterministic validation, freshness checks, recorded activity, and undo where Flavor Agent owns the apply.
4. It degrades clearly when the surface, capability, provider, or backend is unavailable.
5. It does not create a second product inside Flavor Agent.

If a surface fails check 1 or 2, remove or hide it. If it fails check 3, keep it advisory. If it fails check 4, harden it before release. If it fails check 5, stop development in that direction.

## Current Release Blockers

- Block structural apply should remain default-off unless the release explicitly labels it beta and carries fresh WP 7.0 browser evidence.
- Style and Style Book can claim validated `theme.json` operations today, but should not make strong design-quality or accessibility-quality claims until deterministic contrast/readability validation exists.
- Unmerged remote feature branches are behind current `master`; cherry-pick small proven changes only. Do not merge broad stale branches as-is.

## Feature Branch Stop Points

| Branch | Reasonable goal | Stop line |
| --- | --- | --- |
| `origin/codex/docs-audit-updates` | Keep only durable docs-audit or freshness-script improvements that clarify existing contracts. | Do not reopen product scope or rewrite surface ownership. |
| `origin/codex/locate-panel-wrapper-and-child-sections` | CSS spacing polish for existing panels. | Do not change panel hierarchy, lane ownership, or request lifecycle. |
| `origin/codex/locate-textarea/input-in-flavor-agent-section` | Composer textarea accessibility and keyboard polish. | Do not add new composer modes or mutation paths. |
| `origin/codex/task-title` | WPDS toggle/content-mode polish where it improves existing setup or content flows. | Do not turn settings or content into a new primary workflow. |
| `origin/codex/task-title-g69iyw` | Sidebar typography hierarchy polish. | Do not redesign the product shell or introduce new page structure. |
| `origin/codex/task-title-pni71d` | Starter prompt wrapping and compact UI polish. | Do not add new prompt systems or surface-specific prompt taxonomies. |
| `origin/codex/task-title-vqvwyt` | Settings sync copy/layout readability. | Do not expand settings into a provider-router console. |
| `origin/dependabot/npm_and_yarn/npm_and_yarn-6466fe8eb4` | Dependency maintenance after normal test gates pass. | Do not bundle with product-scope changes. |
| `origin/pr-11-review` | Harvest any still-useful freshness, review-signature, or docs corrections. | Do not merge broad navigation/template/surface rewrites from the stale branch. |

## Surface Verdicts

Per-surface next-step docs live in
[`docs/reference/surfaces/`](surfaces/README.md). Use those files for
surface-specific release planning while keeping this review as the overall
product-boundary source of truth.

| Surface | Release verdict | Why it belongs | Release stop |
| --- | --- | --- | --- |
| Block recommendations | Keep, central surface | Native block decisions benefit from immediate context-aware help. | One-click apply only for safe local attributes; structural apply default-off unless beta. |
| Pattern recommendations | Keep, thin surface | Native inserter ranking helps users choose from available patterns. | Browse/rank only; no Flavor Agent pattern insertion ownership or undo. |
| Content recommendations | Keep, editorial-only | Draft/edit/critique belongs in the post/page editor. | No automatic post mutation until a full reviewed apply/undo contract exists. |
| Navigation recommendations | Keep, advisory-only | Navigation advice helps when scoped to selected navigation blocks. | No apply until the Gutenberg navigation editing surface is stable enough. |
| Template recommendations | Keep, high value, high risk | Site Editor templates need review-first structural guidance. | Only bounded reviewed operations; no free-form template rewrites. |
| Template-part recommendations | Keep, strongest structural Site Editor surface | Header/footer/sidebar parts are focused enough for bounded review/apply/undo. | Improve operation yield without broadening operation vocabulary prematurely. |
| Global Styles | Keep, guarded | Theme-level choices merit native review-first assistance. | `theme.json`-safe operations only; add contrast validation before stronger claims. |
| Style Book | Keep, narrower than Global Styles | Block-example style review fits native style inspection. | Block-example scoped changes only; no general visual design generator. |
| AI Activity | Keep as support surface | Users need provenance and undo state. | Read-only admin audit; no observability console. |
| Settings and sync | Keep as support surface | Setup must be understandable for recommendations to work. | No provider router; only plugin-owned backend setup and connector readiness messaging. |
| Helper abilities and REST | Keep as infrastructure | The plugin needs structured capabilities for native surfaces and integrations. | Mark release-supported vs internal; do not expose a general tool catalog. |

## Block Recommendations

### Current Fit

This is the clearest product-center surface. The selected block is the right context, the Inspector is the right native home, and the current model already has a useful distinction between safe local attribute apply, review-first structural suggestions, and manual ideas.

### Release Quality Assessment

Good enough to merit release presence: yes.

Release-quality as-is: yes for safe local attribute recommendations; not yet for default-on structural apply.

The surface is coherent when it remains the direct-apply exception. It becomes incoherent if structural pattern insertion and replacement feel like unreviewed AI edits or if delegated Inspector subpanels become separate apply surfaces.

### Stop Line

Stop at:

- Safe one-click local attribute apply.
- Review-first structural operations behind a rollout flag.
- Passive delegated Inspector subpanel mirrors.
- Activity and undo only for the main block-owned action path.

Do not add:

- General block-tree mutation.
- Multi-block rewrite.
- Free-form pattern insertion from model text.
- Apply buttons in passive settings/style mirrors.
- Site-wide block remediation.

### Release Actions

- [ ] Keep the Block Structural Actions admin setting off by default and keep `FLAVOR_AGENT_ENABLE_BLOCK_STRUCTURAL_ACTIONS` default false for the release unless the release is explicitly beta.
- [ ] If structural apply ships, add release notes that it is review-first and limited to validated selected-block pattern insert/replace.
- [ ] Confirm stale selected-block results remain visible, marked stale, and non-executable.
- [ ] Confirm locked, content-only, missing, moved, or changed targets fail closed.
- [ ] Keep delegated settings/style subpanels passive and route refresh/apply back to the main block panel.
- [ ] Re-run targeted JS tests for block operation catalog, recommendation actionability, store actions, and block panel behavior.
- [ ] Re-run Playground and WP 7.0 browser coverage if structural apply is enabled for any release channel.

## Pattern Recommendations

### Current Fit

Pattern recommendations belong because the inserter is where users already browse patterns. The plugin adds ranking and explanation, not a competing insertion system.

### Release Quality Assessment

Good enough to merit release presence: yes, if kept thin.

Release-quality as-is: close. The surface should be judged on recommendation relevance, allowed-pattern filtering, setup clarity, and badge accuracy, not on whether it has review/apply/undo.

### Stop Line

Stop at:

- Ranking visible, allowed, renderable patterns.
- Native inserter shelf and badge.
- Clear empty/setup/error states.
- `visiblePatternNames` and readable synced-pattern constraints.

Do not add:

- Flavor Agent-owned pattern insertion.
- Pattern apply/undo history.
- A lane/review UI for ordinary pattern browsing.
- Registry rewriting beyond necessary compatibility behavior.
- Pattern-management UI.

### Release Actions

- [ ] Improve "why this pattern" explanation with source signal, matched category, allowed context, and nearby-block fit where available.
- [ ] Make empty-result diagnostics explicit: no visible allowed patterns, index unavailable, backend unavailable, all candidates filtered, or synced pattern unreadable.
- [ ] Confirm badge counts only reflect renderable recommendations.
- [ ] Preserve stricter request-time `read_post` checks for synced-pattern recommendation candidates.
- [ ] Keep helper browse fallback behavior separate from recommendation authorization.
- [ ] Re-run pattern unit tests and the inserter smoke path in `tests/e2e/flavor-agent.smoke.spec.js`.

## Content Recommendations

### Current Fit

Content recommendations belong in the post/page document panel when they stay editorial. Drafting, editing, and critique improve the writing workflow without needing Flavor Agent to own document mutation.

### Release Quality Assessment

Good enough to merit release presence: yes.

Release-quality as-is: yes if positioned honestly as editorial output. It becomes weak if users expect "apply this edit" behavior and the surface only provides copy without clear handoff.

### Stop Line

Stop at:

- Draft, edit, and critique outputs.
- Advisory notes and issue cards.
- Read-only request history.
- Setup/capability notices.

Do not add:

- Automatic replacement of post content.
- Partial document edits.
- Rich text mutation.
- Undo semantics without a full editor-aware apply contract.

### Release Actions

- [ ] Tighten copy so the user understands the result is editorial guidance or generated text, not an automatic patch.
- [ ] Add or verify a clear manual handoff path for draft/edit output.
- [ ] Keep unsupported post types hidden or clearly unavailable.
- [ ] Confirm provider unavailability points to Connectors setup rather than plugin-owned provider routing.
- [ ] Re-run content panel unit/browser smoke coverage after copy or mode changes.

## Navigation Recommendations

### Current Fit

Navigation recommendations are useful but should remain subordinate to block recommendations. The selected `core/navigation` block provides real context, but WordPress navigation editing remains sensitive and easy to destabilize.

### Release Quality Assessment

Good enough to merit release presence: yes, as advisory-only.

Release-quality as-is: yes if the nested surface stays lightweight and clearly non-mutating.

### Stop Line

Stop at:

- Embedded `Navigation Ideas` inside the selected navigation block recommendation surface.
- Standalone/fallback advisory shell only where already supported.
- Server-side freshness/signature checks.
- Read-only diagnostic activity rows.

Do not add:

- Apply.
- Undo.
- Menu restructuring.
- Site-wide navigation planner.
- Separate navigation agent identity.

### Release Actions

- [ ] Keep navigation copy advisory and avoid apply-like verbs.
- [ ] Confirm stale menu or overlay drift keeps previous advice visible but non-executable.
- [ ] Keep embedded navigation visually subordinate to the main block recommendation flow.
- [ ] Validate that missing menu ID, missing markup, or unavailable capability degrades clearly.
- [ ] Re-run navigation smoke coverage in the Playground harness.

## Template Recommendations

### Current Fit

Template recommendations belong because templates are high-value, high-risk editing surfaces. The native Site Editor panel is the right home, and review-first operation preview is the right interaction model.

### Release Quality Assessment

Good enough to merit release presence: yes.

Release-quality as-is: conditional. It is release-credible only if bounded operation validation, freshness, review, apply, undo, and WP 7.0 browser evidence are current.

Release closeout: complete for the bounded template scope as of 2026-05-02. See [`docs/validation/2026-05-02-template-surface-release-closeout.md`](../validation/2026-05-02-template-surface-release-closeout.md).

### Stop Line

Stop at:

- Review-first deterministic operations.
- Explicit placement for bounded pattern insertion.
- Advisory fallback when the operation is unsupported or ambiguous.
- One-pattern insertion limits unless a future plan proves broader transaction safety.

Do not add:

- Free-form template rewrite.
- Multi-region template surgery.
- Model-authored markup application.
- Broad pattern placement inference without deterministic validation.

### Release Actions

- [x] Re-run WP 7.0 template browser flows before release.
- [x] Confirm review, confirm-apply, activity, undo, stale refresh, and drift handling all pass with current assets.
- [x] Keep unsupported operations advisory and preserve useful manual guidance.
- [x] Confirm one operation failure leaves the template unchanged.
- [x] Keep entity links and preview language clear enough that the user knows where the change will land.

## Template-Part Recommendations

### Current Fit

Template parts are the strongest Site Editor fit because headers, footers, and sidebars are structural but bounded. The user often wants guidance, and the editing scope is smaller than a full template.

### Release Quality Assessment

Good enough to merit release presence: yes.

Release-quality as-is: close, assuming current browser evidence is refreshed. The most valuable improvement is better operation yield, not broader operation authority.

### Stop Line

Stop at:

- Review-first deterministic operations.
- Focus-block links.
- Pattern browse links.
- Advisory fallback where safe operations cannot be formed.
- Activity and undo for validated applies.

Do not add:

- Full header/footer redesign automation.
- Multi-part coordination.
- Pattern override or block binding mutation until core APIs are stable and a narrow plan exists.
- Site-wide template-part governance.

### Release Actions

- [ ] Improve recommendation yield by giving the prompt better bounded context, not by loosening validators.
- [ ] Confirm unsupported suggestions stay useful as manual ideas.
- [ ] Re-run WP 7.0 template-part browser flows.
- [ ] Watch Block Bindings and Pattern Overrides before investing in deeper executable behavior.
- [ ] Keep operation vocabulary synchronized with `docs/reference/template-operations.md`.

## Global Styles

### Current Fit

Global Styles belongs because theme-wide visual decisions are native Site Editor work, and Flavor Agent can review proposed `theme.json` changes before applying them.

### Release Quality Assessment

Good enough to merit release presence: yes.

Release-quality as-is: yes for guarded `theme.json` review/apply/undo; incomplete for strong accessibility or design-quality claims.

### Stop Line

Stop at:

- Validated `theme.json` paths.
- Preset-backed values where required.
- Review-first apply.
- Undo only while live config matches the recorded post-apply state.
- Theme style variation handling where supported.

Do not add:

- Raw CSS.
- `customCSS`.
- Arbitrary selector mutation.
- Full visual redesign generation.
- Provider-driven design system ownership.

### Release Actions

- [ ] Add deterministic contrast/readability validation before executable color suggestions are treated as release-quality design recommendations.
- [ ] Prefer paired foreground/background operations when one color change alone could create poor contrast.
- [ ] Classify low-contrast or unsupported combined results as advisory.
- [x] Preserve grouped operations as one review-safe transaction when splitting would create a bad intermediate state. The server parser downgrades partial validation drops to advisory, and the client applier keeps the cumulative write all-or-nothing.
- [ ] Re-run Global Styles WP 7.0 flows after validator or copy changes.

## Style Book

### Current Fit

Style Book belongs as a narrower style surface. It helps users evaluate block-specific examples inside the native style inspection workflow.

### Release Quality Assessment

Good enough to merit release presence: yes.

Release-quality as-is: close. The surface should be narrower than Global Styles and judged on target detection, block-example relevance, and safe reviewed operations.

### Stop Line

Stop at:

- Active Style Book target block.
- Validated block-style `theme.json` operations.
- Review-first apply.
- Advisory fallback when no stable target or valid operation exists.

Do not add:

- General visual design generation.
- Whole-theme redesign.
- Screenshot-based visual diffs as a release dependency.
- Unsupported selector mutation.

### Release Actions

- [ ] Improve target detection and unavailable-state copy.
- [ ] Share contrast/readability validation with Global Styles.
- [ ] Confirm active block example context is included in the prompt and review details.
- [ ] Re-run Style Book WP 7.0 flows after target or validator changes.

## AI Activity And Undo

### Current Fit

Activity belongs because review/apply/undo needs provenance. The inline activity sections and admin audit page support trust and recovery.

### Release Quality Assessment

Good enough to merit release presence: yes.

Release-quality as-is: yes as support infrastructure. It should not be marketed or expanded as observability.

### Stop Line

Stop at:

- Inline recent actions for executable editor scopes.
- Ordered newest-valid-tail undo.
- Read-only admin audit.
- Search/filter/details for diagnostics and provenance.

Do not add:

- A general observability product.
- Metrics dashboards.
- Provider latency/cost analytics.
- Admin row-action undo.
- Cross-user activity intervention.

Per-entry token usage and latency may remain in read-only details when they
serve provenance or diagnostics. Do not aggregate them into dashboards, cost
reports, provider rankings, or observability workflows.

### Release Actions

- [x] Confirm `manage_options` gates the admin page.
- [x] Confirm malformed filters fail closed.
- [x] Confirm operation filters dedupe by effective value while row labels remain specific.
- [x] Confirm retention/pruning expectations are documented or intentionally deferred.
- [x] Keep admin activity copy framed as audit/provenance, not monitoring.
- [x] Re-run activity PHPUnit, admin JS, and Playwright coverage after changes.

Release evidence recorded 2026-05-02:

- `composer run test:php -- --filter 'ActivityRepositoryTest|ActivityPermissionsTest|AgentControllerTest|ActivityPageTest|PluginLifecycleTest'`
  passed with 94 tests and 488 assertions.
- `npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js src/admin/__tests__/activity-log-utils.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/activity-history-state.test.js src/store/__tests__/store-actions.test.js src/components/__tests__/AIActivitySection.test.js src/components/__tests__/ActivitySessionBootstrap.test.js`
  passed with 7 suites and 144 tests.
- `npx playwright test tests/e2e/flavor-agent.activity.spec.js` passed with
  2 tests.
- `npm run check:docs` passed.
- `node scripts/verify.js --skip-e2e` passed with
  `VERIFY_RESULT={"status":"pass","summaryPath":"output/verify/summary.json","counts":{"total":8,"passed":6,"failed":0,"skipped":2}}`.
- `npm run verify` passed with
  `VERIFY_RESULT={"status":"pass","summaryPath":"output/verify/summary.json","counts":{"total":8,"passed":8,"failed":0,"skipped":0}}`.

## Settings And Pattern Sync

### Current Fit

Settings belongs because recommendations need setup, credentials, backend diagnostics, and pattern sync. It is a support surface, not the product center.

### Release Quality Assessment

Good enough to merit release presence: yes.

Release-quality as-is: yes if setup is understandable and validation preserves prior credentials on error.

### Stop Line

Stop at:

- Embeddings and Qdrant setup for plugin-owned pattern recommendations.
- Connector readiness messaging for text generation.
- Pattern recommendation sync status and manual sync.
- Guidelines import/export where already supported.
- Credential-source diagnostics.

Do not add:

- Provider router UI.
- Model selector console.
- General connector administration.
- Fine-grained observability dashboards.
- Settings as a primary product workflow.

### Release Actions

- [ ] Make backend ownership labels explicit: `Settings > Flavor Agent` for plugin-owned embeddings/Qdrant and `Settings > Connectors` for text generation providers.
- [ ] Keep validation errors from replacing prior saved credentials.
- [ ] Confirm manual sync status and stale/error states are understandable.
- [ ] Defer DataForm modernization unless current setup UX blocks release.
- [ ] Re-run settings Playwright coverage after copy/layout changes.

## Helper Abilities And REST

### Current Fit

Helper abilities and REST routes belong as infrastructure for first-party recommendation surfaces and compatible WordPress integrations.

### Release Quality Assessment

Good enough to merit release presence: yes.

Release-quality as-is: yes if public ability metadata is intentional and permission gates are explicit.

### Stop Line

Stop at:

- Surface-specific recommendation abilities.
- Read-only helper abilities that provide context, diagnostics, and discoverability.
- Permission-gated synced-pattern, theme, token, and docs grounding helpers.
- Accurate annotations for supported read-only behavior.

Do not add:

- A general external tool catalog.
- Mutating helper abilities outside first-party review/apply contracts.
- Provider-routing abilities.
- Site-agent orchestration abilities.

### Release Actions

- [ ] Mark which abilities are release-supported public contracts and which are internal or diagnostic.
- [ ] Keep REST and Abilities contracts aligned.
- [ ] Confirm each ability has explicit capability and backend gating.
- [ ] Keep docs grounding cache-only for recommendation paths where required.
- [ ] Re-run targeted PHP registration and route tests after metadata changes.

## Release Readiness Checklist

### Scope Freeze

- [ ] Block structural apply default-off unless explicitly beta.
- [ ] Pattern recommendations browse/rank-only.
- [ ] Content recommendations editorial-only.
- [ ] Navigation recommendations advisory-only.
- [ ] Template, template-part, Global Styles, and Style Book review-first only.
- [ ] AI Activity read-only in admin.
- [ ] Settings limited to setup, sync, and diagnostics.
- [ ] Helper abilities limited to recommendation support and read-only diagnostics unless backed by a first-party apply contract.

### Worktree Hygiene

- [ ] Split product/schema/actionability changes from local Docker/runtime fixes.
- [ ] Keep `.vscode` settings out of release commits unless intentionally scoped.
- [ ] Treat unmerged stale branches as cherry-pick sources, not merge targets.
- [ ] Update only the feature docs and reference docs that correspond to changed behavior.

### Validation

- [ ] Run nearest targeted PHPUnit and JS unit tests for the touched subsystem.
- [ ] Run `node scripts/verify.js --skip-e2e`.
- [ ] Inspect `output/verify/summary.json`.
- [ ] Run `npm run check:docs` when docs, contracts, surface rules, or contributor guidance changed.
- [ ] Run `npm run test:e2e:playground` for post editor, block Inspector, pattern inserter, content, or navigation changes.
- [ ] Run `npm run test:e2e:wp70` for Site Editor, template, template-part, Global Styles, or Style Book changes.
- [ ] Record known-red or unavailable browser harnesses as blockers or explicit waivers.

## Release Messaging

Use this framing:

> Flavor Agent adds reviewable AI recommendations to native WordPress editing surfaces, with bounded apply and undo where changes can be validated.

Avoid this framing:

- "Autonomous site agent."
- "AI site operator."
- "Provider router."
- "Observability dashboard."
- "AI pattern inserter."
- "General mutation framework."

## Final Stopping Point

The release-quality stopping point is a coherent plugin that helps users decide and safely apply only what it can bound:

- Blocks: recommend and safely apply local attributes; keep structural changes reviewed and flagged.
- Patterns: recommend what to browse and insert through core.
- Content: draft, edit, and critique without mutating.
- Navigation: advise only.
- Templates and template parts: preview, validate, apply, and undo bounded operations.
- Styles: review and apply validated `theme.json` changes; add deterministic readability checks before stronger design claims.
- Activity: record provenance and support scoped undo without becoming observability.
- Settings: make setup understandable without becoming provider administration.
- Abilities and REST: support these surfaces without becoming an open-ended tool system.

That is enough product for release. Anything beyond that should be deferred until it can be described as improving context-aware recommendations or bounded native review/apply/undo.
