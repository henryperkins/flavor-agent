# Flavor Agent Portfolio Notes

Flavor Agent is a WordPress 7.0+ plugin that embeds AI-assisted recommendations into native Gutenberg, Site Editor, and wp-admin workflows without creating a separate AI workspace.

## Current Portfolio Story

Flavor Agent demonstrates production-style WordPress AI engineering across:

- WordPress AI Client and Settings > Connectors chat integration
- WordPress Abilities API registration for external agents
- Gutenberg block Inspector, inserter, post editor, Site Editor, Global Styles, and Style Book surfaces
- Provider ownership migration from direct chat fields to Connectors-owned chat plus plugin-owned embeddings
- Pattern vector search with Qdrant and Azure/OpenAI Native embeddings
- Server-backed AI activity persistence, provenance, audit, and ordered undo
- Guidelines bridge work for core-first site and writing guidance
- Cross-surface validation gates across PHP, JavaScript, docs, and browser harnesses

## Evidence To Keep Fresh

- `docs/SOURCE_OF_TRUTH.md`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/reference/provider-precedence.md`
- `docs/reference/abilities-and-routes.md`
- `docs/reference/wordpress-ai-roadmap-tracking.md`
- `docs/reference/cross-surface-validation-gates.md`
- `STATUS.md`
- `output/verify/summary.json`
- dated Playwright logs or explicit browser-harness waivers

## Draft Positioning

I built Flavor Agent to work with WordPress's emerging AI primitives instead of bypassing them: chat runs through Settings > Connectors and the WordPress AI Client, external agents can discover structured abilities, and plugin-owned settings are limited to gaps core does not expose yet, such as embeddings for pattern search.

The project shows how to ship AI assistance inside native WordPress surfaces while preserving capability checks, user review boundaries, provider provenance, undo safety, and release evidence.

## Gaps Before Sharing

- Refresh browser evidence for both Playwright harnesses or record a clear waiver.
- Keep the upstream log current after each WordPress AI roadmap scan.
- Add screenshots or short recordings of the post editor, pattern inserter, Site Editor, Settings, and AI Activity surfaces.
