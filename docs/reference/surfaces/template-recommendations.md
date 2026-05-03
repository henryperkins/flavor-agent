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

Closeout status: release-closed for the bounded template scope as of
2026-05-02. No additional template-surface release work remains inside the stop
line below; broader transaction behavior remains deferred.

## Stop Line

Canonical scope: [release-stop-lines.md](./release-stop-lines.md#template-recommendations)

No additional surface-specific deltas are currently defined.


## Next Steps

- [x] Re-run WP 7.0 template browser flows before release.
- [x] Confirm review, confirm-apply, activity, undo, stale refresh, and drift
  handling pass with current assets.
- [x] Keep unsupported operations advisory and preserve useful manual guidance.
- [x] Confirm one operation failure leaves the template unchanged.
- [x] Keep entity links and preview language clear enough that users know where
  the change will land.
- [x] Keep broad transaction behavior deferred until deterministic safety is
  proven.

## Closeout Evidence

Recorded in
[`../../validation/2026-05-02-template-surface-release-closeout.md`](../../validation/2026-05-02-template-surface-release-closeout.md).

- `npm run test:e2e:wp70` passed on 2026-05-02 with 20 tests, including
  template preview/apply/activity, refresh-safe undo, and drift-disabled undo.
- Targeted Playground coverage passed the stale-refresh and advisory-only
  template cases.
- Targeted template JS and PHPUnit coverage passed, including rollback behavior
  for failed template operations.
- Read-only helper ability calls now match the WP 7.0 Abilities REST method and
  input contract, so helper ability smoke coverage no longer blocks the
  template release gate.

## Verification Gate

- [x] Re-run the WP 7.0 Site Editor template harness.
- [x] Record browser evidence or mark the missing harness as a release blocker
  or explicit waiver.
