# Recommendation Stale-State Alignment Plan

> Scope: align stale-result behavior across executable recommendation surfaces without weakening current safety, preview, or undo contracts.

## Goal

Make stale recommendation handling understandable and consistent across:

- block inspector
- template
- template-part
- style book
- global styles

Users should not have to infer whether results disappeared, became unsafe, or merely need a refresh.

## Recommended Decision

Standardize on an explicit stale state with disabled execution and a refresh action.

That means:

1. preserve the most recent result for the current scope when only live context drifted
2. mark the result stale instead of silently clearing it
3. disable `Apply now`, `Review first`, preview-open, and confirm actions while stale
4. expose a single refresh affordance that re-fetches against the current scope
5. keep stale results visible through refresh loading and refresh failure; only replace them after a successful fresh response or an intentional clear
6. enforce freshness again inside the shared apply actions so stale execution stays blocked even if a UI path misses a disabled state

Clear results only when the user has actually moved to a different scope, such as a different selected block, template, template part, or style-book target.

## Why This Choice

1. It is more transparent than silent clearing.
2. It preserves the safety model because stale results cannot execute.
3. It matches what users already see in the block inspector, which is the most direct recommendation surface.
4. It makes context invalidation visible instead of feeling like a fetch or rendering bug.

## Files In Scope

- `src/components/SurfaceScopeBar.js`
- `src/components/AIStatusNotice.js`
- `src/inspector/BlockRecommendationsPanel.js`
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/store/index.js`
- `src/editor.css`
- surface-specific tests under `src/**/__tests__/`
- `docs/reference/recommendation-ui-consistency.md`
- affected surface docs in `docs/features/`

## Implementation Plan

### Phase 1: Shared stale presentation contract

1. Reuse `SurfaceScopeBar` as the single stale-state shell for these surfaces.
2. Standardize the stale copy shape per surface:
   - stale badge in the scope bar
   - surface-specific stale reason
   - a single refresh CTA in the scope bar
3. Keep stale messaging out of the main status notice path unless there is a separate request/apply/undo event to surface.
4. Keep the stale styling and language aligned with the normalized vocabulary already in use.

### Phase 2: Result-state model

1. Treat the last successful scoped result as present even while a same-scope refresh is loading or has failed.
2. Decouple visible-result presence from transient request status for:
   - template
   - template-part
   - style book
   - global styles
3. Preserve stale explanations, cards, and any already-open review content for comparison, but gate execution affordances while stale.
4. Continue clearing everything on hard scope changes so stale cards never travel across scopes.

### Phase 3: Surface invalidation rules

1. Keep hard scope changes as clearing events.
2. Treat same-scope context drift as a stale event.
3. Define scope boundaries explicitly per surface:
   - block: selected block identity
   - template: template ref
   - template-part: template-part ref
   - style book: selected target block identity
   - global styles: global styles scope

### Phase 4: Action gating

1. Disable one-click apply while stale on block surfaces.
2. Disable review-open and confirm-apply while stale on preview-first surfaces.
3. Preserve advisory-only visibility while stale, but label it stale and prevent execution affordances from implying freshness.
4. Add store-level freshness guards inside the shared apply actions so stale operations fail safely even if the UI is bypassed.

### Phase 5: Refresh path

1. Reuse existing fetch actions instead of introducing a second request path.
2. Refresh should re-fetch with the current prompt and current scope/context signature.
3. Starting a same-scope refresh must not wipe the preserved stale result.
4. A failed refresh must surface the request error without replacing the preserved stale result with an empty response.
5. Clear stale state only after a successful fresh response or an intentional clear.

### Phase 6: Notice precedence

1. Let request, apply, and undo notices continue to surface normally while stale.
2. Suppress empty-result and advisory-ready notices while stale so they do not contradict the stale badge and refresh CTA.
3. Avoid duplicating stale messaging in both the scope bar and the status notice unless later user testing shows a clear discoverability problem.

## Open Questions

1. Should stale advisory-only suggestions remain fully readable long-term, or should some surfaces collapse them behind a stale summary once the behavior ships and gets product review?
2. Should stale state survive editor reloads, or remain strictly in-memory?

## Verification

1. Add targeted tests for same-scope context drift on each executable surface.
2. Verify that stale results never execute.
3. Verify that stale results remain visible during refresh loading and after refresh failure.
4. Verify that refresh restores fresh actions and clears stale labels after a successful fresh response.
5. Verify that switching to a different scope still clears results instead of carrying stale cards across scopes.
6. Verify that stale-state notice precedence suppresses contradictory empty/advisory copy.
