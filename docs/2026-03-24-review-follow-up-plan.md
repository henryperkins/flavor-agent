# 2026-03-24 Review Follow-Up Plan

Generated from the review of commits `10e5776` and `d6ec6b1`.

This document turns the review findings into concrete follow-up work. Each section includes the problem statement, implementation plan, validation plan, and the expected completion signal.

## Scope

Review findings covered here:

1. Template recommendations do not invalidate when root-scoped visible patterns change.
2. WP 7.0 support mappings are inconsistent between client and server block collectors.
3. Top-level documentation is internally inconsistent after the WP 7.0 migration docs update.

## 1. Template Recommendation Invalidation Drift

### Problem

Template recommendations are now fetched with root-scoped `visiblePatternNames`, but existing recommendations are only cleared when:

- the template ref changes
- the editor slot snapshot changes

They are **not** cleared when the effective inserter root changes and therefore the visible pattern set changes.

Current behavior:

- request input includes `visiblePatternNames`
- preview/apply messaging tells the user pattern insertion uses the current insertion point
- apply-time validation re-resolves the pattern against the current insertion root

This creates a stale-result window:

1. user fetches recommendations in one root
2. user changes selection or insertion root
3. panel still shows old recommendations
4. apply either fails or inserts into a different context than the one used for recommendation generation

### Files Involved

- [src/templates/TemplateRecommender.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/templates/TemplateRecommender.js)
- [src/templates/template-recommender-helpers.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/templates/template-recommender-helpers.js)
- [src/utils/template-actions.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/utils/template-actions.js)
- [src/templates/__tests__/template-recommender-helpers.test.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/templates/__tests__/template-recommender-helpers.test.js)
- Potentially store tests if state reset behavior changes

### Implementation Plan

#### Step 1: Decide the invalidation source of truth

Use a recommendation-context signature that reflects every input which can materially change recommendation applicability.

Minimum candidates:

- `editorSlots`
- insertion-root identity, if directly available
- normalized `visiblePatternNames`

Recommended direction:

- include normalized `visiblePatternNames` in the context signature
- keep the signature stable by sorting and deduping names before serialization
- avoid depending on transient array ordering from selectors

Rationale:

- recommendations are filtered by visible patterns before they are sent to the server
- the currently visible pattern set is the actual applicability boundary for pattern insert operations

#### Step 2: Update helper utilities

In [src/templates/template-recommender-helpers.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/templates/template-recommender-helpers.js):

- extend `buildTemplateRecommendationContextSignature()` to accept `visiblePatternNames`
- normalize with the existing `normalizeVisiblePatternNames()` helper before serialization
- serialize a small stable object:
  - `editorSlots`
  - `visiblePatternNames`

#### Step 3: Wire the new signature into the panel lifecycle

In [src/templates/TemplateRecommender.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/templates/TemplateRecommender.js):

- pass `visiblePatternNames` into `buildTemplateRecommendationContextSignature()`
- keep the existing recommendation-clearing effect, but trigger it when the new signature changes
- confirm that changing insertion root clears:
  - previous recommendations
  - preview state
  - any stale apply affordance tied to the previous result

Do not clear the user-entered prompt when only the context changes. The current behavior of clearing prompt only on template change is correct and should stay.

#### Step 4: Review user-facing copy

Re-check whether the preview hint remains accurate after the behavior change.

Desired UX:

- if insertion point changes before apply, old recommendations should disappear or be clearly invalidated
- users should not be able to preview/apply stale pattern-insertion suggestions silently

If recommendations are auto-cleared, existing copy in the preview hint may already be sufficient.

#### Step 5: Confirm no hidden state dependencies

Inspect whether `clearTemplateRecommendations()` also resets:

- selected suggestion key
- apply status / apply error
- result token / result ref

If not, tighten that reset path so a context shift cannot leave stale status banners or stale selected-card UI behind.

### Test Plan

#### Unit tests

Update [src/templates/__tests__/template-recommender-helpers.test.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/templates/__tests__/template-recommender-helpers.test.js):

- replace the current expectation that the signature ignores `visiblePatternNames`
- assert dedupe + stable ordering behavior
- assert that changing visible pattern sets changes the signature

Add or extend component/store tests to verify:

- same `editorSlots` + different `visiblePatternNames` causes recommendation clearing
- unchanged normalized `visiblePatternNames` does not churn state

#### Integration coverage

If there is a practical JS-level component test path:

- simulate recommendations loaded
- simulate inserter root change via selector mocks
- verify panel state resets before apply

#### Browser coverage

Add a future Playwright scenario once a WP 7.0-capable harness is available:

1. fetch template recommendations in one insertion context
2. move insertion point to a different context with a different allowed pattern set
3. verify stale suggestions are cleared or refreshed before apply

### Definition Of Done

- Recommendation context includes `visiblePatternNames`.
- Changing insertion root/visible patterns invalidates stale template recommendations.
- Tests explicitly cover the new invalidation behavior.
- No stale preview/apply state remains after context drift.

### Risk Notes

- Over-eager invalidation could make the panel feel noisy if visible patterns churn frequently.
- This is why normalization and stable serialization are important.

## 2. Client/Server WP 7.0 Support Mapping Drift

### Problem

WP 7.0 support mappings were updated in the client collector, but the server collector still uses the older support-to-panel table.

New client-only mappings:

- `customCSS -> advanced`
- `listView -> settings`

Current risk:

- editor-collected block context can expose these capabilities
- server-built manifests for the same block type cannot
- runtime behavior diverges depending on which collection path is used

That inconsistency is especially risky because Flavor Agent supports both:

- editor-side context collection
- server-side introspection via abilities and internal server builders

### Files Involved

- [src/context/block-inspector.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/context/block-inspector.js)
- [inc/Context/ServerCollector.php](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/inc/Context/ServerCollector.php)
- [tests/phpunit/ServerCollectorTest.php](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/tests/phpunit/ServerCollectorTest.php)
- Potentially JS tests for block inspector coverage

### Implementation Plan

#### Step 1: Mirror the new mappings in the server collector

In [inc/Context/ServerCollector.php](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/inc/Context/ServerCollector.php):

- add `customCSS => advanced`
- add `listView => settings`

Keep naming aligned exactly with the client collector.

#### Step 2: Review for other known drift

While touching the mapping tables, do a quick parity pass between:

- `src/context/block-inspector.js`
- `inc/Context/ServerCollector.php`

Goal:

- ensure both support maps represent the same feature set where parity is intended
- note any deliberate differences inline if they must diverge

If parity is intended across the board, add a short maintenance comment in both files saying the maps must stay synchronized.

#### Step 3: Consider centralization

This issue exists because the mapping is duplicated in JS and PHP.

Short-term fix:

- mirror the missing entries now

Follow-up design option:

- define a single canonical mapping in a JSON or PHP-exportable structure and generate one side from the other
- or add explicit parity tests that compare the two maps through fixtures

Centralization is not required for the immediate fix, but the duplication should be called out as a maintenance hotspot.

### Test Plan

#### PHP tests

Extend [tests/phpunit/ServerCollectorTest.php](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/tests/phpunit/ServerCollectorTest.php):

- register a fake block with `supports.customCSS = true`
- assert `advanced` includes `customCSS`
- register a fake block with `supports.listView = true`
- assert `settings` includes `listView`

#### JS tests

If no test currently covers these support keys in the client path, add a focused JS test for `resolveInspectorPanels()`:

- `customCSS` maps to `advanced`
- `listView` maps to `settings`

### Definition Of Done

- Client and server support maps include the same WP 7.0 additions.
- PHP tests cover the new server mappings.
- JS tests cover the client mappings if not already covered.
- A short note exists for future maintainers about keeping the maps aligned.

### Risk Notes

- Low implementation risk.
- Main failure mode is incomplete parity if other entries have drifted unnoticed.

## 3. Documentation Drift After Migration Notes Update

### Problem

The docs-only commit added a new migration note saying `__experimentalRole` fallback was removed, but two existing top-level docs still state that it remains in active use.

This leaves the repository with conflicting guidance about the same compatibility boundary.

Current mismatch:

- new migration doc says fallback removed
- `flavor-agent-readme.md` still says `__experimentalRole` remains a direct integration
- `SOURCE_OF_TRUTH.md` still says the same

### Files Involved

- [docs/wp7-migration-opportunities.md](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/docs/wp7-migration-opportunities.md)
- [docs/flavor-agent-readme.md](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/docs/flavor-agent-readme.md)
- [docs/SOURCE_OF_TRUTH.md](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/docs/SOURCE_OF_TRUTH.md)
- Optionally [STATUS.md](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/STATUS.md) if it still references the old state elsewhere

### Implementation Plan

#### Step 1: Update compatibility notes

In [docs/flavor-agent-readme.md](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/docs/flavor-agent-readme.md):

- replace the statement that `__experimentalRole` is still a direct integration
- leave `__experimentalFeatures` as the remaining known direct experimental dependency if that is still true

#### Step 2: Update source-of-truth statements

In [docs/SOURCE_OF_TRUTH.md](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/docs/SOURCE_OF_TRUTH.md):

- revise the “Two experimental APIs remain outside compat scope” line
- make it accurate to current code

Likely replacement:

- only `__experimentalFeatures` remains as an active direct dependency

#### Step 3: Cross-check nearby docs

Search for other stale references to:

- `__experimentalRole`
- “two experimental APIs remain”
- any wording that implies the plugin still reads `__experimentalRole`

Update or remove stale statements in:

- top-level docs
- status docs
- architecture summaries

#### Step 4: Tighten wording around version assumptions

Where docs mention the change, keep the wording explicit:

- the plugin now targets WP 7.0+
- `role` is the only supported attribute key in Flavor Agent code
- third-party block compatibility with deprecated `__experimentalRole` is intentionally no longer preserved by the plugin

This avoids future confusion about whether the fallback was removed intentionally or accidentally.

### Test / Verification Plan

This is doc-only work, so verification should be a consistency pass:

- search the repo for stale `__experimentalRole` prose
- confirm all top-level docs tell the same story
- ensure code comments, if any, match the new behavior

Suggested verification commands:

```bash
rg -n "__experimentalRole|Two experimental APIs remain|direct integrations" docs STATUS.md src inc
```

### Definition Of Done

- Top-level docs no longer contradict the migration note.
- Documentation accurately describes current compatibility behavior.
- No stale top-level references remain to `__experimentalRole` as an active Flavor Agent integration.

### Risk Notes

- No runtime risk.
- Main risk is leaving partial doc drift in secondary files.

## Suggested Execution Order

1. Fix issue 2 first.
   Reason: low risk, fast parity win, immediate runtime consistency improvement.
2. Fix issue 1 next.
   Reason: user-facing behavior risk and stale apply path.
3. Fix issue 3 last.
   Reason: doc-only cleanup after runtime behavior is settled.

## Rollout Notes

- Issue 2 can ship independently.
- Issue 1 should ship with tests in the same change.
- Issue 3 can be bundled with either runtime fix or shipped separately as doc cleanup.

## Acceptance Checklist

- [ ] Template recommendation invalidation includes visible pattern scope changes.
- [ ] Template tests cover context drift caused by visible pattern changes.
- [ ] Server support mapping includes `customCSS` and `listView`.
- [ ] Server tests cover the new WP 7.0 support mappings.
- [ ] Top-level docs no longer claim `__experimentalRole` remains in use.
- [ ] Repo-wide search confirms no stale high-level docs remain for these findings.
