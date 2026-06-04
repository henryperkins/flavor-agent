# Pattern Insertion-Context Message — Design

- **Date:** 2026-06-02
- **Status:** Approved (Approach B)
- **Scope:** Single surface (pattern recommender, client-only). Independent of PR #25.

## Problem

When the caret sits in a container that allows no inserter patterns (e.g. inside `core/buttons`), `getAllowedPatterns(rootClientId)` returns `[]`, so the client sends `visiblePatternNames: []`. `PatternAbilities::recommend_patterns()` short-circuits at line 164 with `{recommendations: []}`, and the UI renders the fallback *"Flavor Agent did not find a strong pattern match for this insertion point yet."* That is misleading: nothing was ranked because the insertion point accepts no patterns. The auto-fetch also re-fires on this empty context (observed: 6 empty activity rows in 18s).

The message must remain correct for the **genuine** empty-ranking case — visible patterns exist but ranking returned nothing.

## Approach (B): skip the fetch + distinct affordance

Client-only. The client already computes `visiblePatternNames`, so it can detect and handle the no-patterns insertion point without a server round-trip.

### Changes — `src/patterns/PatternRecommender.js`

1. **Derived state** (after `visiblePatternNames`, ~line 786):
   - `topLevelVisiblePatternNames = useSelect( select => getVisiblePatternNames( null, select( blockEditorStore ) ), [] )` — top-level allowed patterns, used to distinguish a zero-pattern container from not-yet-hydrated patterns.
   - `insertionPointAllowsNoPatterns = canRecommend && hasInsertionPoint && visiblePatternNames.length === 0 && topLevelVisiblePatternNames.length > 0`.

2. **Guard the auto-fetch effect** (`~line 1505`) and the search-input fetch (`~line 1517`): return early when `insertionPointAllowsNoPatterns` (add it to the effect deps). No request is sent for an insertion point that accepts no patterns.

3. **Render branch** (`~line 1641`, after the `shouldShowPatternShelf` check, before the `patternStatus` branches): when `insertionPointAllowsNoPatterns`, render `<PatternInserterNotice status="no-patterns" />`.

4. **`PatternInserterNotice`** (`~line 631`): handle `status === 'no-patterns'` — resolved message below, and no "No matches yet" pill (the existing pill checks are gated on `status === 'empty'`/`'error'`/`'loading'`, so `'no-patterns'` shows only the Flavor Agent lane pill + the copy).

### Message copy

> This spot doesn't accept block patterns. Click into the page body (or a container that allows patterns) to get recommendations.

### Not changing

- **Server / line 164** stays as a graceful fallback for direct/external MCP callers passing empty `visiblePatternNames`. (A `no_visible_patterns` reason for external callers is out of scope — YAGNI.)
- The genuine empty-ranking fallback message (`getPatternEmptyMessage` → `''` → "did not find a strong pattern match") is untouched; it still applies when `visiblePatternNames` is non-empty.

## Testing

- `src/patterns/__tests__/PatternRecommender.test.js`:
  - New: empty `visiblePatternNames` + non-empty top-level ⇒ the no-patterns message renders and `fetchPatternRecommendations` is **not** dispatched.
  - New (hydration guard): empty `visiblePatternNames` + empty top-level ⇒ no-patterns message does **not** render (falls through to normal loading/idle).
  - Keep the existing "did not find a strong pattern match" test for the non-empty-visible empty-ranking case.
- Verify: nearest JS suite (`PatternRecommender`), `node scripts/verify.js --skip-e2e`, and the Playground pattern smoke (or recorded waiver — same harness caveat as PR #25).

## Verification gates

Single-surface client change; not a shared-subsystem contract change. Targeted JS suite + `verify --skip-e2e` are the core gates; `check:docs` only if any contract/surfacing doc changes (none expected).
