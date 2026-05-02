# Style Book Release Surface

Source: [`../release-surface-scope-review.md`](../release-surface-scope-review.md#style-book)

## Release Role

Style Book belongs as a narrower style surface. It helps users evaluate
block-specific examples inside the native style inspection workflow.

Release verdict: keep as narrower than Global Styles.

Release quality: close, judged on target detection, block-example relevance, and
safe reviewed operations.

## Stop Line

Ship:

- Active Style Book target block.
- Validated block-style `theme.json` operations.
- Review-first apply.
- Advisory fallback when no stable target or valid operation exists.

Do not ship:

- General visual design generation.
- Whole-theme redesign.
- Screenshot-based visual diffs as a release dependency.
- Unsupported selector mutation.

## Next Steps

- [ ] Improve target detection and unavailable-state copy.
- [ ] Share contrast/readability validation with Global Styles.
- [ ] Confirm active block example context is included in the prompt and review
  details.
- [ ] Keep this surface narrower than Global Styles.
- [ ] Keep unsupported selector mutation advisory or unavailable.

## Verification Gate

- [ ] Re-run Style Book WP 7.0 flows after target or validator changes.

