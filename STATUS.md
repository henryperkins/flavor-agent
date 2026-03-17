# Flavor Agent - Status

> Last updated: 2026-03-17

## Working

### Abilities API (WordPress 6.9+)

| Ability | Handler | Description |
| --- | --- | --- |
| `flavor-agent/recommend-block` | `BlockAbilities` | Block recommendation pipeline using `ServerCollector`, `Prompt`, and the LLM client |
| `flavor-agent/introspect-block` | `BlockAbilities` | Block type registry introspection |
| `flavor-agent/recommend-patterns` | `PatternAbilities` | Azure OpenAI embeddings + Qdrant retrieval + LLM reranking |
| `flavor-agent/list-patterns` | `PatternAbilities` | Pattern registry listing with filters |
| `flavor-agent/list-template-parts` | `TemplateAbilities` | Template part listing with optional area filter |
| `flavor-agent/get-theme-tokens` | `InfraAbilities` | Theme preset and global style token extraction |
| `flavor-agent/check-status` | `InfraAbilities` | Backend configuration and ability availability status |

### REST API

| Route | Permission | Description |
| --- | --- | --- |
| `POST /flavor-agent/v1/recommend-block` | `edit_posts` | Block recommendations from client-provided editor context |
| `POST /flavor-agent/v1/recommend-patterns` | `edit_posts` | Pattern recommendations for the inserter |
| `POST /flavor-agent/v1/sync-patterns` | `manage_options` | Manual pattern index sync |

### Editor UI

- Inspector sidebar recommendation panel for selected, editable blocks
- Content-only blocks keep the panel but only allow content-safe suggestions
- Disabled blocks do not render AI controls
- Pattern inserter integration with a `Recommended` category and toolbar badge for high-confidence matches
- Admin settings screen with backend configuration and pattern sync controls

## Stubbed (501)

| Ability | Permission | What is missing |
| --- | --- | --- |
| `flavor-agent/recommend-template` | `edit_theme_options` | Template recommendation implementation |
| `flavor-agent/recommend-navigation` | `edit_theme_options` | Navigation recommendation implementation |

## Known Issues

- `recommend-block` still exists in two paths: the REST controller accepts richer client context, while the Abilities API path rebuilds context server-side. They should share one normalization and enforcement layer.
- There is no automated PHP test suite or PHPCS coverage in the repo yet; current verification is syntax checks plus JS lint/tests/build.

## Historical Docs

These docs describe earlier designs that are no longer the source of truth:

- `docs/LLM-WordPress-Assistant.md`
- `docs/LLM-WordPress-Assistant-Notes.md`
- `docs/LLM-WordPress-Phases.md`

Use `docs/flavor-agent-readme.md` and this file for the current architecture and feature inventory.
