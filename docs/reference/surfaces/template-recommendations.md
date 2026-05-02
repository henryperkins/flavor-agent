# Template Recommendations Release Surface

Source: [`../release-surface-scope-review.md`](../release-surface-scope-review.md#template-recommendations)

## Release Role

Template recommendations belong because templates are high-value, high-risk Site
Editor surfaces. The release interaction model is review-first operation preview,
not free-form rewrite.

Release verdict: keep as high value and high risk.

Release quality: conditional. The surface is release-credible only when bounded
operation validation, freshness, review, apply, undo, and WP 7.0 browser evidence
are current.

## Stop Line

Ship:

- Review-first deterministic operations.
- Explicit placement for bounded pattern insertion.
- Advisory fallback when the operation is unsupported or ambiguous.
- One-pattern insertion limits unless a future plan proves broader transaction
  safety.

Do not ship:

- Free-form template rewrite.
- Multi-region template surgery.
- Model-authored markup application.
- Broad pattern placement inference without deterministic validation.

## Next Steps

- [ ] Re-run WP 7.0 template browser flows before release.
- [ ] Confirm review, confirm-apply, activity, undo, stale refresh, and drift
  handling pass with current assets.
- [ ] Keep unsupported operations advisory and preserve useful manual guidance.
- [ ] Confirm one operation failure leaves the template unchanged.
- [ ] Keep entity links and preview language clear enough that users know where
  the change will land.
- [ ] Keep broad transaction behavior deferred until deterministic safety is
  proven.

## Verification Gate

- [ ] Re-run the WP 7.0 Site Editor template harness.
- [ ] Record browser evidence or mark the missing harness as a release blocker
  or explicit waiver.

