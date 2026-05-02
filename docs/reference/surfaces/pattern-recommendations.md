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

- [ ] Improve "why this pattern" explanation with source signal, matched
  category, allowed context, and nearby-block fit where available.
- [ ] Make empty-result diagnostics explicit for no visible allowed patterns,
  unavailable index, unavailable backend, all candidates filtered, and
  unreadable synced patterns.
- [ ] Confirm badge counts only reflect renderable recommendations.
- [ ] Preserve stricter request-time `read_post` checks for synced-pattern
  recommendation candidates.
- [ ] Keep helper browse fallback behavior separate from recommendation
  authorization.

## Verification Gate

- [ ] Re-run pattern unit tests.
- [ ] Re-run the inserter smoke path in
  `tests/e2e/flavor-agent.smoke.spec.js`.

