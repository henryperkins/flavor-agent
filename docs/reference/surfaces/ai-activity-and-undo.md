# AI Activity And Undo Release Surface

Source: [`../release-surface-scope-review.md`](../release-surface-scope-review.md#ai-activity-and-undo)

## Release Role

AI Activity belongs because review/apply/undo needs provenance. Inline activity
sections and the admin audit page support trust and recovery without becoming an
observability product.

Release verdict: keep as a support surface.

Release quality: release-ready as support infrastructure if it is not marketed
or expanded as observability.

## Stop Line

Ship:

- Inline recent actions for executable editor scopes.
- Ordered newest-valid-tail undo.
- Read-only admin audit.
- Search/filter/details for diagnostics and provenance.

Do not ship:

- A general observability product.
- Metrics dashboards.
- Provider latency/cost analytics.
- Admin row-action undo.
- Cross-user activity intervention.

## Next Steps

- [ ] Confirm `manage_options` gates the admin page.
- [ ] Confirm malformed filters fail closed.
- [ ] Confirm operation filters dedupe by effective value while row labels
  remain specific.
- [ ] Confirm retention/pruning expectations are documented or intentionally
  deferred.
- [ ] Keep admin activity copy framed as audit/provenance, not monitoring.
- [ ] Keep admin activity read-only.

## Verification Gate

- [ ] Re-run activity PHPUnit coverage after changes.
- [ ] Re-run admin JS coverage after changes.
- [ ] Re-run activity Playwright coverage after changes.

