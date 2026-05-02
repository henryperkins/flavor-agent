# Pattern Recommendations Release Surface

Source: [`../release-surface-scope-review.md`](../release-surface-scope-review.md#pattern-recommendations)

## Release Role

Pattern recommendations belong in the native inserter because that is where
users already browse patterns. Flavor Agent adds ranking and explanation, not a
competing insertion path.

Release verdict: keep as a thin surface.

Release quality: close, if judged on recommendation relevance, allowed-pattern
filtering, setup clarity, and badge accuracy.

## Stop Line

Ship:

- Ranking visible, allowed, renderable patterns.
- Native inserter shelf and badge.
- Clear empty/setup/error states.
- `visiblePatternNames` and readable synced-pattern constraints.

Do not ship:

- Flavor Agent-owned pattern insertion.
- Pattern apply/undo history.
- A lane or review UI for ordinary pattern browsing.
- Registry rewriting beyond necessary compatibility behavior.
- Pattern-management UI.

## Next Steps

- [x] Improve "why this pattern" explanation with source signal, matched
  category, allowed context, and nearby-block fit where available. The server
  already returns `sourceSignals`, `rankingHint`, and context-aware reason text;
  the remaining gap is surfacing that metadata in the inserter shelf without
  adding lanes, review state, or a custom apply path.
- [x] Make the unreadable-synced-pattern diagnostic explicit. The current
  surface already covers no visible allowed patterns, unavailable index,
  unavailable backend, and all-candidates-filtered states; unreadable synced
  candidates now report a non-identifying aggregate count when request-time
  `read_post` fails for candidates in the current visible-pattern scope.
- [x] Confirm badge counts only reflect renderable recommendations.
- [x] Preserve stricter request-time `read_post` checks for synced-pattern
  recommendation candidates.
- [x] Keep helper browse fallback behavior separate from recommendation
  authorization.

## Verification Gate

- [x] Re-run pattern unit tests.
- [x] Re-run the inserter smoke path in
  `tests/e2e/flavor-agent.smoke.spec.js`.
