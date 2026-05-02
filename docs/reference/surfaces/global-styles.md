# Global Styles Release Surface

Source: [`../release-surface-scope-review.md`](../release-surface-scope-review.md#global-styles)

## Release Role

Global Styles belongs because theme-wide visual decisions are native Site Editor
work. Flavor Agent can help when it reviews proposed `theme.json` changes before
applying them.

Release verdict: keep as guarded.

Release quality: release-ready for guarded `theme.json` review/apply/undo;
incomplete for strong accessibility or design-quality claims.

## Stop Line

Ship:

- Validated `theme.json` paths.
- Preset-backed values where required.
- Review-first apply.
- Undo only while live config matches the recorded post-apply state.
- Theme style variation handling where supported.

Do not ship:

- Raw CSS.
- `customCSS`.
- Arbitrary selector mutation.
- Full visual redesign generation.
- Provider-driven design system ownership.

## Next Steps

- [ ] Add deterministic contrast/readability validation before executable color
  suggestions are treated as release-quality design recommendations.
- [ ] Prefer paired foreground/background operations when one color change alone
  could create poor contrast.
- [ ] Classify low-contrast or unsupported combined results as advisory.
- [ ] Preserve grouped operations as one review-safe transaction when splitting
  would create a bad intermediate state.
- [ ] Keep design-quality claims limited until contrast/readability validation
  exists.

## Verification Gate

- [ ] Re-run Global Styles WP 7.0 flows after validator or copy changes.

