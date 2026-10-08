# UI Review Fixes Implementation Plan

> **For agentic workers:** Use the existing parallel implementation assignments and integrate the independently tested changes in this chat. Steps use checkbox syntax for tracking.

**Goal:** Resolve all seven confirmed UI review findings without changing the recommendation surfaces' intended modes.

**Architecture:** Preserve request-time editor block identity through structural validation. Recheck synchronous editor state and the latest panel request after asynchronous apply validation. Keep local drafts independent of completed request snapshots, serialize insertion, resume existing sync polling, and authorize decisions per activity row.

**Tech Stack:** WordPress PHP, Gutenberg data stores, React, Jest, PHPUnit, Playwright.

**Spec:** The seven confirmed findings in the October 8, 2026 UI/theme/style review and the user's authorization to address all findings comprehensively.

## Global Constraints

- Preserve pre-existing skill, prompt, configuration, and documentation edits.
- Edit application sources in `src/` and `inc/`; generate build assets through the build command.
- Retain block direct apply, template/style review-confirm, navigation advisory, and pattern insertion modes.
- Use a separate local fixture site for browser mutations; never deploy or change a production site.
- Run the additive checks in `docs/reference/cross-surface-validation-gates.md`.

## Review Focus

- Similar blocks reordered before review confirmation must never redirect a destructive operation.
- Editor edits, scope changes, prompt changes, and provider/theme setting changes during preflight must prevent mutation.
- Original and adapted pattern insertion share one busy lifecycle and verify the latest target.
- Draft edits survive response hydration, while untouched remounted panels still recover the submitted prompt.
- Post permissions and theme permissions remain distinct, with server authorization decisive for claims and decisions.

## Task 1: Structural target identity

**Owner:** apply_audit.

**Files:** template and template-part helpers, `src/utils/template-actions.js`, new `src/utils/editor-block-identity.js`, PHP structural normalization/grammar, nearest JS/PHP suites, new `tests/e2e/flavor-agent.ui-target-regressions.spec.js`.

**Interface:** `getEditorBlockIdentity(block)` returns `{ clientId, subtreeSignature }`; editor proof participates in request context, expected target, and execution validation.

- [x] Add regressions for reordered paragraphs, changed descendants, malformed proof, and valid unchanged targets; confirm failure before implementation.
- [x] Preserve proof through current client/server paths and reject drift before mutation.
- [x] Run nearest helper/executor/ability tests and browser regressions.

## Task 2: Pending apply freshness

**Owner:** root; component forwarding belongs to shared_audit.

**Files:** `src/store/executable-surface-runtime.js`, `src/store/executable-surfaces.js`, nearest store suites.

**Interface:** Store apply actions accept optional fourth `getLiveRequestState()` returning `{ requestSignature, requestInput }`. The runtime also captures current registry scope, full block identity, relevant editor settings, and style config without relying on a render.

- [x] Add deferred-preflight tests for block changes, scope changes, config changes, prompt changes, replaced results, and an unchanged successful apply; verify the new drift cases fail.
- [x] Compare current panel and registry state after preflight and immediately before execution; report client-stale failure without mutation or success/activity emission.
- [x] Run store and component suites for all four executable surfaces.

## Task 3: Pattern insertion and local drafts

**Owner:** shared_audit.

**Files:** PatternRecommender and helpers, six recommender components, any focused draft hook, nearest tests, new `tests/e2e/flavor-agent.ui-draft-pattern-regressions.spec.js`.

- [x] Add failing original/adapted insertion target-race and overlapping-click regressions.
- [x] Re-read insertion context before dispatch and hold a synchronous shared lock through verification; release it on every outcome.
- [x] Add failing draft edits during loading and untouched-remount hydration regressions across the six affected surfaces.
- [x] Preserve edited drafts, retain scope resets, and forward the Task 2 live-request callback.
- [x] Run closest component and insertion suites and browser regressions.

## Task 4: Admin polling and decision permissions

**Owner:** admin_audit.

**Files:** Settings controller, Activity UI, row serialization/authorization as needed, closest JS/PHP tests, new `tests/e2e/flavor-agent.ui-admin-regressions.spec.js`.

- [x] Add failing initial-indexing polling and post-operator permission regressions.
- [x] Resume polling on initialization and consume the server's row-specific decision authorization, failing closed on unavailable permission.
- [x] Run closest Settings/Activity JS and PHP tests and browser regressions.

## Task 5: Integration and verification

**Owner:** root.

- [x] Review the combined diff for omitted callers, normalization drift, async cleanup, and authorization gaps; obtain an independent review.
- [x] Run nearest targeted PHPUnit and JS suites for changed contracts.
- [x] Run `node scripts/verify.js --skip-e2e` and inspect `output/verify/summary.json`; retain Plugin Check availability failures as blockers.
- [x] Run `npm run check:docs` and update operator/freshness contract documentation where necessary.
- [x] Run matching Playground and isolated Docker Site Editor tests against rebuilt current-checkout assets; record any material unavailable evidence.
- [x] Report completed fixes, checks, and remaining limitations without claiming deployment readiness from incomplete gates.

**Validation record:** [UI theme and style review fixes](../../validation/2026-10-08-ui-theme-review-fixes.md). Every browser case is covered across the full runs and successful fixture-corrected focused reruns; the record preserves the earlier failures and does not label those full runs as passing.
