# Flavor Agent - Status

> Last updated: 2026-03-19

## Working

### Abilities API (WordPress 7.0+)

| Ability | Handler | Description |
| --- | --- | --- |
| `flavor-agent/recommend-block` | `BlockAbilities` | Block recommendation pipeline using `ServerCollector`, `Prompt`, and the WordPress AI Client |
| `flavor-agent/introspect-block` | `BlockAbilities` | Block type registry introspection |
| `flavor-agent/recommend-patterns` | `PatternAbilities` | Azure OpenAI embeddings + Qdrant retrieval + LLM reranking |
| `flavor-agent/recommend-template` | `TemplateAbilities` | Azure OpenAI template composition suggestions for Site Editor templates |
| `flavor-agent/list-patterns` | `PatternAbilities` | Pattern registry listing with filters |
| `flavor-agent/list-template-parts` | `TemplateAbilities` | Template part listing with optional area filter |
| `flavor-agent/search-wordpress-docs` | `WordPressDocsAbilities` | Official WordPress developer-doc grounding search backed by Cloudflare AI Search |
| `flavor-agent/get-theme-tokens` | `InfraAbilities` | Theme preset and global style token extraction |
| `flavor-agent/recommend-navigation` | `NavigationAbilities` | Navigation structure, overlay behavior, and organization recommendations |
| `flavor-agent/check-status` | `InfraAbilities` | Backend inventory plus current-user ability availability status |

### REST API

| Route | Permission | Description |
| --- | --- | --- |
| `POST /flavor-agent/v1/recommend-block` | `edit_posts` | Block recommendations from client-provided editor context |
| `POST /flavor-agent/v1/recommend-patterns` | `edit_posts` | Pattern recommendations for the inserter |
| `POST /flavor-agent/v1/recommend-template` | `edit_theme_options` | Template composition recommendations for the Site Editor |
| `POST /flavor-agent/v1/sync-patterns` | `manage_options` | Manual pattern index sync |

### Editor UI

- Inspector sidebar recommendation panel for selected, editable blocks with per-block loading and error state
- Content-only blocks keep the panel but only allow content-safe suggestions, and disabled blocks do not render AI controls
- Pattern inserter integration with a `Recommended` category, toolbar badge for high-confidence matches, and root-aware allowed-pattern scoping
- Site Editor template recommendation panel for `wp_template` documents with advisory-only review and browse actions
- Admin settings screen with Azure/Qdrant/Cloudflare configuration and pattern sync controls; block providers come from `Settings > Connectors`
- Settings saves now surface the standard Settings API success notice plus plugin-scoped Cloudflare validation errors
- WordPress docs grounding only accepts chunks sourced from `developer.wordpress.org`
- Cloudflare AI Search credentials are revalidated only when the account ID, instance ID, or token changes; the new credentials must target an enabled, unpaused instance that also returns trusted `developer.wordpress.org` guidance before they are saved
- Recommendation-time WordPress docs grounding remains cache-only and non-blocking; exact-query cache is authoritative and warmed block/template entity cache is only a fallback
- Explicit `flavor-agent/search-wordpress-docs` requests always seed the exact-query cache and only seed entity cache when a valid `entityKey` or legacy query inference resolves

## Known Issues

- `composer lint:php` is now green across `flavor-agent.php`, `inc/`, `tests/phpunit`, and `uninstall.php`, but `tests/phpunit/bootstrap.php` remains intentionally excluded because the multi-namespace stub harness is not a realistic WPCS target without a dedicated refactor.
- First-request WordPress docs grounding is still intentionally reduced: uncached `recommend-block` and `recommend-template` requests return without Cloudflare guidance until either an exact-query cache entry or the matching warmed entity cache is available.
- Azure OpenAI and Qdrant credentials are still accepted without live remote validation on save; only Cloudflare AI Search currently validates changed credentials against instance state plus trusted WordPress developer-doc compatibility.
- Live recommendation execution with valid LLM credentials and manual Site Editor smoke checks were not rerun in this pass.

## Recent Verification

- 2026-03-19 remediation: `npm run lint:js` passed.
- 2026-03-19 remediation: `npm run test:unit -- --runInBand` passed.
- 2026-03-19 remediation: `npm run build` passed.
- 2026-03-19 remediation: `vendor/bin/phpunit` passed (`51` tests, `231` assertions).
- 2026-03-19 remediation: `vendor/bin/phpcs --standard=phpcs.xml.dist inc/Cloudflare/AISearchClient.php inc/Settings.php tests/phpunit/AISearchClientTest.php tests/phpunit/SettingsTest.php` passed.
- 2026-03-19 remediation: `vendor/bin/phpcs --standard=phpcs.xml.dist flavor-agent.php inc/Abilities/BlockAbilities.php inc/Abilities/InfraAbilities.php inc/Abilities/Registration.php inc/Context/ServerCollector.php inc/LLM/Prompt.php inc/LLM/WordPressAIClient.php inc/Settings.php uninstall.php tests/phpunit/AgentControllerTest.php tests/phpunit/InfraAbilitiesTest.php tests/phpunit/PromptGuidanceTest.php tests/phpunit/RegistrationTest.php tests/phpunit/ServerCollectorTest.php` passed.

## Documentation

- **`docs/SOURCE_OF_TRUTH.md`** -- Definitive project reference: scope, architecture, inventory, roadmap, definition of done
- **`docs/flavor-agent-readme.md`** -- Architecture details and editor flow reference
- **`docs/2026-03-18-cloudflare-ai-search-grounding-assessment.md`** -- Cloudflare AI Search integration assessment

Historical docs (superseded early designs) have been moved to `docs/historical/`.
