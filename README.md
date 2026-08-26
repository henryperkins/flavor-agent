# Flavor Agent

Flavor Agent lets AI work on a live WordPress site without unchecked control. Every AI action it mediates runs through one governance layer: operations validated against bounded schemas, structural changes gated behind review, every apply the plugin owns attributed and recorded server-side, every recorded change reversible with drift detection so an undo never clobbers later human edits. Humans get this through native Gutenberg and Site Editor surfaces — blocks, content, patterns, templates, template parts, navigation, Global Styles, and Style Book; external agents get the same recommendation, validation, and freshness contracts through the WordPress Abilities API and MCP. Built on the WordPress 7.1 AI stack while retaining a WordPress 7.0 minimum. The recommendation surfaces are the demonstration; the governance layer is the product — its contract map lives in [`docs/reference/governance-layer.md`](docs/reference/governance-layer.md).

I built it to prove AI can be practical product infrastructure, not a chatbot pasted onto a workflow: Connectors-owned text generation, Cloudflare-backed embeddings and search, bounded apply semantics, undo, activity audit, and explicit service ownership make every recommendation reviewable, traceable, and safe to ship.

> **Release status:** implementation hardening, current live-corpus evidence, the connector-backed runtime smoke, and the minimum visual proof gate are closed on the current `0.1.0` candidate, but exact-tag verification is outstanding. RC1–RC3 are published; there is no final `v0.1.0` tag or release yet. Executable CI or an explicit waiver, a clean release artifact, and verification against the final immutable SHA remain release gates. See [`STATUS.md`](STATUS.md) for the full working state and validation log.

1.0 when the core Abilities/AI Client surfaces stabilize.

## See it

Two minimum release stills are available. `docs/screenshots/activity-audit.png` shows a WordPress 7.0 `Settings > AI Activity` row with a pending external style apply open in the approval/audit panel. `docs/screenshots/content-recommendation.png` shows a fresh Anthropic-backed recommendation in the native WordPress 7.1 post editor. Together they show the governed proposal/review model at both the editor and administration layers.

The minimum pair exists; these remaining stills are optional additions to the public demo sequence:

- `docs/screenshots/inspector-recommendation.png` — selected block recommendation in the native Inspector.
- `docs/screenshots/global-styles-review.png` — Global Styles or Style Book operation in review-first mode.
- `docs/screenshots/template-review.png` — Site Editor template recommendation in review-first mode.
- `docs/screenshots/pattern-inserter.png` — ranked patterns in the native inserter shelf.
- `docs/screenshots/settings-readiness.png` — Connectors/plugin-owned backend readiness in wp-admin.

Ship the code without a GIF if necessary, but do not ship the public release without the governance-console proof plus at least one strong native editor still.

**As of 2026-08-26 the minimum visual gate is closed.** The WordPress 7.1 editor still came from a fresh Anthropic-backed `recommend-content` request; its environment, interaction, credential boundary, browser health, and checksum are recorded in [`docs/validation/2026-08-26-wordpress-7.1-anthropic-editor-proof.md`](docs/validation/2026-08-26-wordpress-7.1-anthropic-editor-proof.md). Per-asset status remains in [`docs/releases/v0.1.0-proof-assets.md`](docs/releases/v0.1.0-proof-assets.md).

## What it does

- Mediates AI work through one governed loop: bounded operation schemas, freshness checks, review gates for structural/theme changes, server-side attribution, and drift-safe undo.
- Gives administrators `Settings > AI Activity` as the human approval and audit surface for governed external applies, recent Flavor Agent actions, provider-path details, undo state, and affected-entity links.
- Lets external agents use the same WordPress Abilities API / MCP contracts to request recommendations, validation, diagnostics, and review-gated applies across four lanes: Global Styles / Style Book, templates, template parts, and post blocks.
- Demonstrates the governance layer through native WordPress surfaces: selected blocks, post/page content, the pattern inserter, Site Editor templates and template parts, navigation blocks, Global Styles, and Style Book.

## What it does not do

- It does not auto-publish content, silently rewrite posts, or contact site visitors.
- It does not phone home on activation.
- It does not own text-generation credentials; recommendation chat runs through WordPress `Settings > Connectors` and the WordPress AI Client.
- It does not replace Gutenberg’s native pattern inserter, navigation editor, template editor, or style system.

## Who it is for

Flavor Agent is for WordPress builders, editors, and plugin developers who want AI-assisted decisions inside the surfaces where those decisions already happen — without turning the site into an autonomous agent or handing unreviewed mutations to a model.

## Install locally

1. Clone or download this repository into `wp-content/plugins/flavor-agent`.
2. Install dependencies with Node 20/npm 10 or Node 24/npm 11, plus Composer.
3. Build production assets with `npm run build`.
4. Activate **Flavor Agent** in WordPress.
5. Configure text generation in `Settings > Connectors`; optionally configure pattern retrieval, embeddings, developer-doc grounding limits/diagnostics, and guidelines in `Settings > Flavor Agent`.

For a representative development environment, use the local setup notes in [`docs/reference/local-environment-setup.md`](docs/reference/local-environment-setup.md). WordPress 7.1 is released; the Docker-backed Site Editor harness pins the exact stable `wordpress:7.1.0-php8.2-apache` image so harness runs stay reproducible.

## Current status

- Version: `0.1.0`
- WordPress: requires WordPress 7.0+; release browser gates target WordPress 7.1
- PHP: requires PHP 8.2+
- JavaScript toolchain: Node 20/npm 10 or Node 24/npm 11
- Canonical status log: [`STATUS.md`](STATUS.md)
- Release notes draft: [`docs/releases/v0.1.0.md`](docs/releases/v0.1.0.md)

Automated evidence currently recorded in the repository includes:

- The 2026-08-25 candidate commit records strict build, JS/PHP lint, docs checks, `1,750` JS tests across `112` suites, and `2,244` PHP tests / `10,526` assertions passing.
- Plugin Check passed against the correctly staged `209`-file release tree on 2026-08-25.
- Playwright harnesses passed on WordPress 7.1 on 2026-08-25: `test:e2e:playground` `17 passed / 0 failed` and `test:e2e:wp70` `30 passed / 0 failed`, followed by a deliberate cold-boot `30 passed / 0 failed` rerun.
- A real Anthropic-backed `flavor-agent/recommend-content` request resolved `claude-sonnet-4-6` and completed without an unexpected error on 2026-08-26; see [`docs/validation/2026-08-26-anthropic-connector-smoke.md`](docs/validation/2026-08-26-anthropic-connector-smoke.md).
- A second fresh Anthropic-backed request rendered successfully in the native WordPress 7.1 post editor and produced the minimum editor still; see [`docs/validation/2026-08-26-wordpress-7.1-anthropic-editor-proof.md`](docs/validation/2026-08-26-wordpress-7.1-anthropic-editor-proof.md).
- A targeted public-corpus updater run settled its WordPress 7.1 source with zero pending items or deletions, then returned current stable Developer Docs and current Make/Core evidence in the same bounded query; see [`docs/validation/2026-08-26-public-corpus-validation.md`](docs/validation/2026-08-26-public-corpus-validation.md).

These are candidate working-tree results, not exact-tag release evidence. Re-run the full gates — strict verify including Plugin Check, both Playwright harnesses, `npm run check:docs`, and `npm run dist` — on the exact commit you tag, and record the results before publishing.

## Architecture at a glance

Flavor Agent is a WordPress plugin with a PHP backend under `inc/`, editor/admin apps under `src/`, and compiled assets in `build/`. The runtime defines 35 WordPress Ability contracts across recommendation, helper/read, docs-search, preview, external-apply, style, pattern, template, navigation, and infrastructure categories; helper/read abilities and the six signature-only `preview-recommend-*` siblings register whenever their core contracts are available, while recommendation and external-apply abilities also require the WordPress AI feature gate. The remaining plugin REST API stays intentionally thin for activity persistence, external-apply decisions, undo, and pattern sync.

The editor app mounts first-party UI into native Gutenberg and Site Editor locations: block Inspector panels, post/page document panels, the pattern inserter, template and template-part panels, Global Styles, Style Book, and navigation-block advisory sections. Activity records are written server-side and reused by inline editor history plus the wp-admin approval/audit page.

Provider ownership is explicit: text generation flows through the WordPress AI Client and `Settings > Connectors`; plugin-owned settings cover embeddings, Qdrant, private Cloudflare AI Search pattern retrieval, built-in public WordPress docs grounding limits/diagnostics, guidelines, and pattern sync.

## Provider matrix

| Capability               | OpenAI and Azure OpenAI Connectors          | Anthropic and other Connectors          | Cloudflare Workers AI                                         | Cloudflare AI Search                                | Qdrant                          |
| ------------------------ | ------------------------------------------- | --------------------------------------- | ------------------------------------------------------------- | --------------------------------------------------- | ------------------------------- |
| Text generation          | Via `Settings > Connectors`                 | Via `Settings > Connectors`             | Not used for chat by Flavor Agent                             | Not used for chat                                   | Not used for chat               |
| Embeddings               | Not used for embeddings by Flavor Agent     | Not used for embeddings                 | Only plugin-owned embedding backend for Qdrant                | Managed embeddings/indexing for private patterns    | Stores/searches vectors         |
| Pattern retrieval        | Reranking can use connector-backed chat     | Reranking can use connector-backed chat | Embeddings only when the Qdrant backend is selected           | Private pattern retrieval backend option            | Vector retrieval backend option |
| WordPress docs grounding | Not used                                    | Not used                                | Not used                                                      | Trusted `developer.wordpress.org` grounding         | Not used                        |
| Configuration owner      | `Settings > Connectors`                     | `Settings > Connectors`                 | `Settings > Flavor Agent`                                     | `Settings > Flavor Agent` or built-in docs endpoint | `Settings > Flavor Agent`       |

See the external-service disclosure in [`readme.txt`](readme.txt) and [`docs/reference/external-service-disclosure.md`](docs/reference/external-service-disclosure.md) for service-specific data and trigger details.

## Surface Boundaries

| Surface        | Interaction model                                            | Notes                                                                              |
| -------------- | ------------------------------------------------------------ | ---------------------------------------------------------------------------------- |
| Blocks         | Safe local direct apply; structural actions guarded/reviewed | Selected-block Inspector context with stale/freshness checks.                      |
| Content        | Editorial-only                                               | Draft, edit, and critique output without automatic post mutation.                  |
| Patterns       | Browse/rank only                                             | Local Flavor Agent shelf inside the native inserter; no registry rewriting.        |
| Templates      | Review-first apply/undo                                      | Bounded deterministic operations in the Site Editor.                               |
| Template parts | Review-first apply/undo                                      | Header/footer/sidebar-scoped recommendations with validated operations.            |
| Navigation     | Advisory-only                                                | Guidance for selected `core/navigation` blocks; no apply contract in `0.1.0`.      |
| Global Styles  | Review-first apply/undo                                      | Validated `theme.json` operations only; no raw CSS or `customCSS`.                 |
| Style Book     | Review-first apply/undo                                      | Block-example scoped style recommendations.                                        |
| AI Activity    | Admin approval and audit                                     | Pending external style, template, template-part, and post-blocks apply decisions, server-backed activity feed, detail panel, and undo state; not a general observability product. |

## Develop and verify

Common commands:

- `npm ci` and `composer install` to install dependencies.
- `npm run build` to create production assets in `build/`.
- `npm run lint:js` and `composer lint:php` for linting.
- `npm run test:unit -- --runInBand` and `vendor/bin/phpunit` for unit tests.
- `npm run test:e2e:playground` for the fast Playground smoke suite.
- `npm run test:e2e:wp70` for the Docker-backed WordPress 7.1 Site Editor suite.
- `npm run verify` for the aggregate verification runner.

Release packaging is available through `npm run dist`.

## Documentation

Start here:

- [`docs/README.md`](docs/README.md) — documentation map and ownership rules.
- [`docs/SOURCE_OF_TRUTH.md`](docs/SOURCE_OF_TRUTH.md) — product scope, architecture, and definition of done.
- [`docs/FEATURE_SURFACE_MATRIX.md`](docs/FEATURE_SURFACE_MATRIX.md) — shipped surfaces, apply/undo paths, and validation gates.
- [`docs/reference/abilities-and-routes.md`](docs/reference/abilities-and-routes.md) — Abilities and REST contracts.
- [`docs/reference/release-surface-scope-review.md`](docs/reference/release-surface-scope-review.md) — release stop lines.
- [`docs/reference/release-submission-and-review.md`](docs/reference/release-submission-and-review.md) — WordPress.org submission path.

## License

Flavor Agent is licensed under the GPL-2.0-or-later. The full license text ships in [`LICENSE`](LICENSE); [`readme.txt`](readme.txt) carries the WordPress.org-style license header.
