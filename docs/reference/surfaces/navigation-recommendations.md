# Navigation Recommendations Release Surface

Source: [`../release-surface-scope-review.md`](../release-surface-scope-review.md#navigation-recommendations)

## Release Role

Navigation recommendations are useful only when subordinate to block
recommendations. The selected `core/navigation` block provides real context, but
WordPress navigation editing is still sensitive enough that this surface should
stay non-mutating.

Release verdict: keep as advisory-only.

Release quality: release-ready if the nested surface stays lightweight and
clearly non-mutating.

## Stop Line

Ship:

- Embedded `Navigation Ideas` inside the selected navigation block
  recommendation surface.
- Standalone/fallback advisory shell only where already supported.
- Server-side freshness/signature checks.
- Read-only diagnostic activity rows.

Do not ship:

- Apply.
- Undo.
- Menu restructuring.
- Site-wide navigation planner.
- Separate navigation agent identity.

## Next Steps

- [x] Keep navigation copy advisory and avoid apply-like verbs.
- [ ] Confirm stale menu or overlay drift keeps previous advice visible but
  non-executable.
- [ ] Keep embedded navigation visually subordinate to the main block
  recommendation flow.
- [ ] Validate clear degradation for missing menu ID, missing markup, and
  unavailable capability.
- [ ] Keep diagnostic activity read-only.

## Verification Gate

- [ ] Re-run navigation smoke coverage in the Playground harness.
