# Flavor Agent

Flavor Agent is a customer-facing AI system for WordPress teams that need useful recommendations without giving a model unchecked control of their site. It brings contextual assistance into Gutenberg and wp-admin surfaces where editors already work: blocks, content, patterns, templates, navigation, Global Styles, and Style Book. I built it to prove AI can be practical product infrastructure, not a chatbot pasted onto a workflow: multi-provider text generation, Cloudflare-backed embeddings and search, bounded apply semantics, undo, activity audit, and explicit service ownership make every recommendation reviewable, traceable, and safe to ship.

> **Release status:** `0.1.0` is release-candidate ready in this repository. See [`STATUS.md`](STATUS.md) for the full working state and validation log.

## See it

The repository does not yet include committed screenshots. Before publishing the GitHub release, add still images under `docs/screenshots/` and replace this placeholder with the strongest Inspector-panel screenshot:

- `docs/screenshots/inspector-recommendation.png` — selected block with a contextual recommendation visible in the Inspector.
- `docs/screenshots/template-review.png` — Site Editor template recommendation in review-first mode.
- `docs/screenshots/activity-audit.png` — `Settings > AI Activity` audit detail view.

That first screenshot is the repo’s handshake. Ship the code without a GIF if necessary, but do not ship the public release without at least one still screenshot.

## What it does

- Adds contextual AI recommendations to native WordPress editor surfaces: selected blocks, post/page content, the pattern inserter, Site Editor templates and template parts, navigation blocks, Global Styles, and Style Book.
- Keeps risky changes review-first: structural template, template-part, Global Styles, and Style Book suggestions are previewed, validated, recorded, and undoable where Flavor Agent owns the apply path.
- Gives administrators a server-backed `Settings > AI Activity` audit page for recent Flavor Agent actions, provider-path details, undo state, and affected-entity links.

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
5. Configure text generation in `Settings > Connectors`; optionally configure pattern retrieval, embeddings, docs grounding, and guidelines in `Settings > Flavor Agent`.

For a representative development environment, use the local setup notes in [`docs/reference/local-environment-setup.md`](docs/reference/local-environment-setup.md). WordPress 7.0 is still pre-release as of this draft; the Docker-backed Site Editor harness pins a pre-release image until the stable 7.0 image exists.

## Current status

- Version: `0.1.0`
- WordPress: requires and tests against WordPress 7.0+
- PHP: requires PHP 8.0+
- JavaScript toolchain: Node 20/npm 10 or Node 24/npm 11
- Canonical status log: [`STATUS.md`](STATUS.md)
- Release notes draft: [`docs/releases/v0.1.0.md`](docs/releases/v0.1.0.md)

Automated evidence currently recorded in the repository includes:

- `node scripts/verify.js --skip-e2e` passing build, JS lint, Plugin Check, JS unit, PHP lint, and PHPUnit on 2026-05-02.
- `npm run test:e2e:playground` passing `9` tests with `2` intentionally skipped in the Playground smoke harness on 2026-04-22.
- `npm run test:e2e:wp70` passing `20` tests in the Docker-backed WordPress 7.0 Site Editor harness on 2026-05-02.

Re-run the verification gates on the exact commit you tag.

## Architecture at a glance

Flavor Agent is a WordPress plugin with a PHP backend under `inc/`, editor/admin apps under `src/`, and compiled assets in `build/`. The runtime registers 20 WordPress Abilities across recommendation, helper, docs, style, pattern, template, navigation, and infrastructure categories, while the remaining plugin REST API stays intentionally thin for activity persistence, undo, and pattern sync.

The editor app mounts first-party UI into native Gutenberg and Site Editor locations: block Inspector panels, post/page document panels, the pattern inserter, template and template-part panels, Global Styles, Style Book, and navigation-block advisory sections. Activity records are written server-side and reused by inline editor history plus the wp-admin audit page.

Provider ownership is explicit: text generation flows through the WordPress AI Client and `Settings > Connectors`; plugin-owned settings cover embeddings, Qdrant, private Cloudflare AI Search pattern retrieval, public/override WordPress docs grounding, guidelines, and pattern sync.

## Provider matrix

| Capability               | OpenAI                                                 | Azure OpenAI                                                                             | Anthropic and other Connectors          | Cloudflare Workers AI                                         | Cloudflare AI Search                                | Qdrant                          |
| ------------------------ | ------------------------------------------------------ | ---------------------------------------------------------------------------------------- | --------------------------------------- | ------------------------------------------------------------- | --------------------------------------------------- | ------------------------------- |
| Text generation          | Via `Settings > Connectors`                            | Via connector when available                                                             | Via `Settings > Connectors`             | Not used for chat by Flavor Agent                             | Not used for chat                                   | Not used for chat               |
| Embeddings               | Plugin-owned OpenAI Native option for Qdrant           | Legacy saved Azure embedding options for older Qdrant installs; no new editable settings | Not used for embeddings                 | Explicitly selected plugin-owned embedding backend for Qdrant | Not used for embeddings                             | Stores/searches vectors         |
| Pattern retrieval        | Reranking can use connector-backed chat                | Reranking can use connector-backed chat                                                  | Reranking can use connector-backed chat | Embeddings only when explicitly selected                      | Private pattern retrieval backend option            | Vector retrieval backend option |
| WordPress docs grounding | Not used                                               | Not used                                                                                 | Not used                                | Not used                                                      | Trusted `developer.wordpress.org` grounding         | Not used                        |
| Configuration owner      | Connectors or `Settings > Flavor Agent` for embeddings | Legacy saved Flavor Agent options only; new setup uses OpenAI Native or Workers AI       | `Settings > Connectors`                 | `Settings > Flavor Agent`                                     | `Settings > Flavor Agent` or built-in docs endpoint | `Settings > Flavor Agent`       |

See the external-service disclosure in [`readme.txt`](readme.txt) and [`docs/reference/external-service-disclosure.md`](docs/reference/external-service-disclosure.md) for service-specific data and trigger details.

## Recommendation surfaces

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
| AI Activity    | Read-only admin audit                                        | Server-backed activity feed and detail panel, not a general observability product. |

## Develop and verify

Common commands:

- `npm ci` and `composer install` to install dependencies.
- `npm run build` to create production assets in `build/`.
- `npm run lint:js` and `composer lint:php` for linting.
- `npm run test:unit -- --runInBand` and `vendor/bin/phpunit` for unit tests.
- `npm run test:e2e:playground` for the fast Playground smoke suite.
- `npm run test:e2e:wp70` for the Docker-backed WordPress 7.0 Site Editor suite.
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

Flavor Agent is licensed under the GPLv2 or later. See [`readme.txt`](readme.txt) for the WordPress.org-style license header.
