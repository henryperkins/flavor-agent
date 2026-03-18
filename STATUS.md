# Flavor Agent - Status

> Last updated: 2026-03-18

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
- Recommendation-time WordPress docs grounding is cache-only; explicit `flavor-agent/search-wordpress-docs` requests perform the Cloudflare fetch and seed later recommendation prompts

## Stubbed (501)

| Ability | Permission | What is missing |
| --- | --- | --- |
| `flavor-agent/recommend-navigation` | `edit_theme_options` | Navigation recommendation implementation |

## Known Issues

- `composer lint:php` is now green across `flavor-agent.php`, `inc/`, `tests/phpunit`, and `uninstall.php`, but `tests/phpunit/bootstrap.php` remains intentionally excluded because the multi-namespace stub harness is not a realistic WPCS target without a dedicated refactor.
- First-request WordPress docs grounding is intentionally reduced: uncached `recommend-block` and `recommend-template` requests now return without Cloudflare guidance until an explicit `flavor-agent/search-wordpress-docs` request seeds cache.
- Live recommendation execution with valid LLM credentials was not rerun in this pass. Live verification covered ability schema validation and authenticated ability discovery, not end-to-end editor submissions.

## Recent Verification

- 2026-03-18 remediation: `vendor/bin/phpunit --filter 'AISearchClientTest|InfraAbilitiesTest|PromptGuidanceTest|ServerCollectorTest|BlockAbilitiesTest|RegistrationTest'` passed.
- 2026-03-18 remediation: `npm run test:unit -- --runInBand src/patterns/__tests__/find-inserter-search-input.test.js src/patterns/__tests__/inserter-badge-state.test.js src/store/__tests__/pattern-status.test.js src/utils/__tests__/structural-identity.test.js src/utils/__tests__/template-part-areas.test.js` passed.
- 2026-03-18 remediation: `npm run lint:js` and `npm run build` passed.
- 2026-03-18 remediation: `vendor/bin/phpcs --standard=phpcs.xml.dist inc/Cloudflare/AISearchClient.php inc/Abilities/Registration.php inc/LLM/TemplatePrompt.php tests/phpunit/AISearchClientTest.php tests/phpunit/RegistrationTest.php tests/phpunit/bootstrap.php` passed.
- 2026-03-18 remediation: widened `phpcs.xml.dist` coverage and reran `composer lint:php`; it passed.
- 2026-03-18 remediation: `composer test:php` passed after the repo-wide PHPCS cleanup.
- 2026-03-18 remediation: `wp --path=/home/hperkins-wp/htdocs/wp.hperkins.com eval '...'` validated `flavor-agent/recommend-block` input with the new structural fields and returned `bool(true)`.
- 2026-03-18 remediation: authenticated `rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' ) )` returned `200`, and the exported `selectedBlock` keys included `editingMode`, `childCount`, `structuralIdentity`, `structuralAncestors`, and `structuralBranch`.

## Historical Docs

These docs describe earlier designs that are no longer the source of truth:

- `docs/LLM-WordPress-Assistant.md`
- `docs/LLM-WordPress-Assistant-Notes.md`
- `docs/LLM-WordPress-Phases.md`

Use `docs/flavor-agent-readme.md` and this file for the current architecture and feature inventory.
