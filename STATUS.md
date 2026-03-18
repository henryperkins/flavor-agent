# Flavor Agent - Status

> Last updated: 2026-03-17

## Working

### Abilities API (WordPress 6.9+)

| Ability | Handler | Description |
| --- | --- | --- |
| `flavor-agent/recommend-block` | `BlockAbilities` | Block recommendation pipeline using `ServerCollector`, `Prompt`, and the LLM client |
| `flavor-agent/introspect-block` | `BlockAbilities` | Block type registry introspection |
| `flavor-agent/recommend-patterns` | `PatternAbilities` | Azure OpenAI embeddings + Qdrant retrieval + LLM reranking |
| `flavor-agent/recommend-template` | `TemplateAbilities` | Azure OpenAI template composition suggestions for Site Editor templates |
| `flavor-agent/list-patterns` | `PatternAbilities` | Pattern registry listing with filters |
| `flavor-agent/list-template-parts` | `TemplateAbilities` | Template part listing with optional area filter |
| `flavor-agent/search-wordpress-docs` | `WordPressDocsAbilities` | Official WordPress developer-doc grounding search backed by Cloudflare AI Search |
| `flavor-agent/get-theme-tokens` | `InfraAbilities` | Theme preset and global style token extraction |
| `flavor-agent/check-status` | `InfraAbilities` | Backend inventory plus current-user ability availability status |

### REST API

| Route | Permission | Description |
| --- | --- | --- |
| `POST /flavor-agent/v1/recommend-block` | `edit_posts` | Block recommendations from client-provided editor context |
| `POST /flavor-agent/v1/recommend-patterns` | `edit_posts` | Pattern recommendations for the inserter |
| `POST /flavor-agent/v1/recommend-template` | `edit_theme_options` | Template composition recommendations for the Site Editor |
| `POST /flavor-agent/v1/sync-patterns` | `manage_options` | Manual pattern index sync |

### Editor UI

- Inspector sidebar recommendation panel for selected, editable blocks
- Content-only blocks keep the panel but only allow content-safe suggestions
- Disabled blocks do not render AI controls
- Pattern inserter integration with a `Recommended` category and toolbar badge for high-confidence matches
- Site Editor template recommendation panel for `wp_template` documents
- Admin settings screen with backend configuration and pattern sync controls
- WordPress docs grounding only accepts chunks sourced from `developer.wordpress.org`

## Stubbed (501)

| Ability | Permission | What is missing |
| --- | --- | --- |
| `flavor-agent/recommend-navigation` | `edit_theme_options` | Navigation recommendation implementation |

## Known Issues

- Automated coverage is still partial: the repo has focused PHPUnit and JS unit suites, but Abilities API registration/execution is not covered directly and still needs live WordPress verification. PHPCS tooling exists, but it is not part of the routine verification path documented here.

## Historical Docs

These docs describe earlier designs that are no longer the source of truth:

- `docs/LLM-WordPress-Assistant.md`
- `docs/LLM-WordPress-Assistant-Notes.md`
- `docs/LLM-WordPress-Phases.md`

Use `docs/flavor-agent-readme.md` and this file for the current architecture and feature inventory.
