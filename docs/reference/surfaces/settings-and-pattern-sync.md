# Settings And Pattern Sync Release Surface

Source: [`../release-surface-scope-review.md`](../release-surface-scope-review.md#settings-and-pattern-sync)

## Release Role

Settings belongs because recommendations need setup, credentials, backend
diagnostics, and pattern sync. It is a support surface, not the product center.

Release verdict: keep as a support surface.

Release quality: release-ready if setup is understandable and validation
preserves prior credentials on error.

## Stop Line

Ship:

- Embeddings and Qdrant setup for plugin-owned pattern recommendations.
- Connector readiness messaging for text generation.
- Pattern recommendation sync status and manual sync.
- Guidelines import/export where already supported.
- Credential-source diagnostics.

Do not ship:

- Provider router UI.
- Model selector console.
- General connector administration.
- Fine-grained observability dashboards.
- Settings as a primary product workflow.

## Next Steps

- [ ] Make backend ownership labels explicit: `Settings > Flavor Agent` for
  plugin-owned embeddings/Qdrant and `Settings > Connectors` for text
  generation providers.
- [ ] Keep validation errors from replacing prior saved credentials.
- [ ] Confirm manual sync status and stale/error states are understandable.
- [ ] Defer DataForm modernization unless current setup UX blocks release.
- [ ] Keep settings copy out of provider-router or model-selector framing.

## Verification Gate

- [ ] Re-run settings Playwright coverage after copy/layout changes.

