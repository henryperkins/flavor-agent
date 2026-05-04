# Block Recommendations Release Surface

Source: [`../release-surface-scope-review.md`](../release-surface-scope-review.md#block-recommendations)

## Release Role

Block recommendations are the product-center surface. The selected block is the
native WordPress decision point, and the Inspector is the right home for
context-aware help.

Release verdict: keep as a central surface.

Release quality: release-ready for safe local attribute recommendations; not
release-ready for default-on structural apply.

## Stop Line

Canonical scope: [release-stop-lines.md](./release-stop-lines.md#block-recommendations)

No additional surface-specific deltas are currently defined.


## Next Steps

- [ ] Keep the Block Structural Actions admin setting off by default and keep
  `FLAVOR_AGENT_ENABLE_BLOCK_STRUCTURAL_ACTIONS` default false unless the
  release is explicitly labeled beta.
- [ ] If structural apply ships in any channel, document that it is review-first
  and limited to validated selected-block pattern insert/replace.
- [ ] Confirm stale selected-block results remain visible, marked stale, and
  non-executable.
- [ ] Confirm locked, content-only, missing, moved, or changed targets fail
  closed.
- [ ] Keep delegated settings/style subpanels passive and route refresh/apply
  back through the main block panel.
- [ ] Prevent delegated Inspector mirrors from becoming independent action
  surfaces.

## Verification Gate

- [ ] Re-run targeted JS tests for block operation catalog behavior.
- [ ] Re-run targeted JS tests for recommendation actionability.
- [ ] Re-run targeted JS tests for shared store actions.
- [ ] Re-run targeted JS tests for block panel behavior.
- [ ] Re-run Playground and WP 7.0 browser coverage if structural apply is
  enabled for any release channel.
