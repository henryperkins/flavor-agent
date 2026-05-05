# Surface Review Prompt

Single generic review prompt with surface-specific inspect lists and focus area deltas. Replaces the previous one-prompt-per-surface set.

## Generic Prompt

```text
Review the named Flavor Agent surface in this repository as a review-only pass: do not modify files, do not rewrite components, and do not propose broad redesigns unless you can tie them to a concrete bug, security issue, privacy leak, validation issue, runtime-contract mismatch, accessibility/responsive issue, missing test, or documented contract drift.

Follow the shared review protocol at [../reference/review-response-protocol.md](../reference/review-response-protocol.md).

Default scope rules
- Focus on the primary surface listed below.
- Include adjacent shared code only when contracts, validation, stale handling, activity, undo, or docs affect the named surface.
- Trace the full path from route/ability registration through context collection, prompt construction (when applicable), response parsing, client rendering, review/confirm/apply, stale-context handling, undo/activity, tests, and docs.
- Start by reading the current checkout. Grep hits are leads, not findings; open the file and confirm the actual runtime path before flagging.

Default focus areas
- Permission and ability-schema drift.
- Server/client request or response contract mismatches.
- Stale `reviewContextSignature` / `resolvedContextSignature` handling and demote-on-stale behavior.
- Unsafe or under-validated operations and apply paths that bypass server validators.
- LLM prompt instructions that allow operations the validator later rejects.
- Cases where failed, advisory, or stale recommendations can still mutate state.
- Undo/activity entries that cannot safely restore or validate live state.
- Missing tests for shared contracts, freshness guards, validation, scope resolution, undo, or docs drift.
- Docs that overclaim behavior not enforced by code.

Substitute the surface-specific delta from `surface-review-prompt.md` (inspect list, primary surface, surface-specific focus areas) into the prompt. Cross-surface changes touching shared subsystems require the additive gates in `docs/reference/cross-surface-validation-gates.md`.

Output format: follow [../reference/review-response-protocol.md](../reference/review-response-protocol.md).
```

## Surface Deltas

### Block Recommendations

- **Primary surface:** `flavor-agent/recommend-block`
- **Embedded:** `recommend-navigation` only when shared code, contracts, request state, stale handling, docs, or UI placement affect the block path.
- **Inspect:** `inc/Abilities/Registration.php`, `inc/Abilities/BlockAbilities.php`, `inc/Context/BlockContextCollector.php`, `inc/Context/BlockRecommendationExecutionContract.php`, `inc/Context/BlockTypeIntrospector.php`, `inc/LLM/Prompt.php`, `inc/REST/Agent_Controller.php`, `src/inspector/BlockRecommendationsPanel.js`, `src/inspector/block-recommendation-request.js`, `src/inspector/SuggestionChips.js`, `src/inspector/NavigationRecommendations.js`, `src/utils/block-recommendation-context.js`, `src/utils/block-execution-contract.js`, `src/utils/recommendation-request-signature.js`, `src/store/index.js`, `src/store/update-helpers.js`, `src/store/activity-history.js`, `src/store/block-targeting.js`, related tests under `src/inspector/**`, `src/store/**`, `src/utils/**`, `src/components/**`, `tests/phpunit/BlockAbilitiesTest.php`, `tests/e2e/*`, `docs/features/block-recommendations.md`, `docs/features/navigation-recommendations.md`.
- **Surface-specific focus:** `contentOnly`, locked-block, unsupported-supports, or capability restrictions bypassed by apply; direct-apply behavior that should be advisory-only; delegated native sub-panels accidentally creating second apply/refresh/activity paths; undo snapshot correctness when block moved/changed/disappeared; embedded navigation state leaking into block apply/activity behavior.

### Template Recommendations

- **Primary surface:** `flavor-agent/recommend-template`
- **Inspect:** `inc/Abilities/Registration.php`, `inc/Abilities/TemplateAbilities.php`, `inc/LLM/TemplatePrompt.php`, `inc/REST/Agent_Controller.php`, `src/templates/TemplateRecommender.js`, `src/utils/template-actions.js`, related store/request state and activity/undo code, `tests/phpunit/TemplateAbilitiesTest.php`, related JS tests under `src/templates/**` and `src/utils/**`, `docs/features/template-recommendations.md`, `docs/reference/abilities-and-routes.md`.
- **Surface-specific focus:** invalid anchors; template-part assignments; unsafe template operations; deterministic executor mismatches between preview validation and live apply.

### Template Part Recommendations

- **Primary surface:** `flavor-agent/recommend-template-part`
- **Inspect:** `inc/Abilities/Registration.php`, `inc/Abilities/TemplateAbilities.php`, `inc/Context/TemplatePartContextCollector.php`, `inc/Context/ServerCollector.php`, `inc/LLM/TemplatePartPrompt.php`, `inc/REST/Agent_Controller.php`, `src/template-parts/TemplatePartRecommender.js`, `src/template-parts/template-part-recommender-helpers.js`, `src/utils/template-actions.js`, `src/utils/template-operation-sequence.js`, `src/utils/template-part-areas.js`, `src/utils/visible-patterns.js`, `tests/phpunit/TemplateAbilitiesTest.php`, `tests/phpunit/TemplatePartPromptTest.php`, related JS tests, `docs/features/template-part-recommendations.md`.
- **Surface-specific focus:** active template-part scope/ref/slug/area mismatches; saved entity vs live editor structure (especially when live structure is empty); `visiblePatternNames` filtering including explicit empty filters; `targetPath` validation gaps; insertion-anchor correctness for `start`, `end`, `before_block_path`, `after_block_path`.

### Style Recommendations (Global Styles + Style Book)

- **Primary surface:** `flavor-agent/recommend-style` (both first-party callers)
- **Inspect:** `inc/Abilities/Registration.php`, `inc/Abilities/StyleAbilities.php`, `inc/LLM/StylePrompt.php`, `inc/REST/Agent_Controller.php`, `src/global-styles/GlobalStylesRecommender.js`, `src/style-book/StyleBookRecommender.js`, `src/style-book/dom.js`, `src/style-surfaces/request-input.js`, `src/style-surfaces/presentation.js`, `src/utils/style-operations.js`, `src/utils/style-validation.js`, `tests/phpunit/StyleAbilitiesTest.php`, `tests/phpunit/StylePromptTest.php`, related JS tests under `src/global-styles/**`, `src/style-book/**`, `src/style-surfaces/**`, `docs/features/style-and-theme-intelligence.md`.
- **Surface-specific focus:** Global Styles vs Style Book scope mismatches; invalid theme.json paths, unsupported preset values, raw values where presets are required; Global-Styles-only operations (especially `set_theme_variation`) accidentally allowed on Style Book; Style Book block-scoped operations applied to wrong `blockName` or unsupported path.

### Admin Settings Page

- **Primary surface:** `Settings > Flavor Agent` (`options-general.php?page=flavor-agent`)
- **Inspect:** `flavor-agent.php`, `inc/Settings.php`, `inc/Admin/Settings/{Config,Assets,Help,Page,Registrar,Fields,Validation,Feedback,State,Utils}.php`, `inc/OpenAI/Provider.php`, `inc/LLM/{ChatClient,WordPressAIClient}.php`, `inc/AzureOpenAI/{ResponsesClient,EmbeddingClient,QdrantClient}.php`, `inc/Cloudflare/AISearchClient.php`, `inc/Patterns/PatternIndex.php`, `inc/REST/Agent_Controller.php`, `inc/Abilities/{InfraAbilities,SurfaceCapabilities}.php`, `src/admin/settings-page.js`, `src/admin/settings-page-controller.js`, `src/admin/{settings,brand,wpds-runtime,dataviews-runtime}.css`, `src/utils/capability-flags.js`, `tests/phpunit/{SettingsTest,ProviderTest,AzureBackendValidationTest,AgentControllerTest,InfraAbilitiesTest}.php`, `src/admin/__tests__/settings-page-controller.test.js`, `src/utils/__tests__/capability-flags.test.js`, `tests/e2e/flavor-agent.settings.spec.js`, `docs/features/settings-backends-and-sync.md`, `docs/reference/provider-precedence.md`, `readme.txt`.
- **Surface-specific focus:**
  - Admin access (`manage_options`), menu/asset enqueue scoping, contextual Help, escaped output.
  - Settings API end-to-end: every option from `Registrar::register_settings()` to its renderer, sanitizer, and saved feedback flow.
  - `Validation` for OpenAI Native, Cloudflare Workers AI, Qdrant, Cloudflare override, pattern thresholds, reasoning effort, guidelines.
  - Provider precedence: Connectors-first chat contract, OpenAI Native key-source precedence, dropdown choices/diagnostics not overclaiming a direct provider when a connector serves chat.
  - Status cards/sections: `State::get_page_state()`, default-open-section, accordion persistence vs urgent validation.
  - Pattern sync: REST nonce/permissions, duplicate-click, runtime-state updates, no full reload.
  - Guidelines import/export, block guideline storage and escaping.
  - Docs grounding (built-in + Cloudflare override) prewarm/diagnostics.
  - WPDS shims, overflow/responsive, color-independent state distinguishability.

### AI Activity Log

- **Primary surface:** `Settings > AI Activity` (`settings_page/flavor-agent-activity`) and the server-backed audit log.
- **Inspect:** `inc/Admin/ActivityPage.php`, `inc/Activity/{Repository,Permissions,Serializer}.php`, `inc/REST/Agent_Controller.php`, `src/admin/activity-log.js`, `src/admin/activity-log-utils.js`, `src/admin/{activity-log,dataviews-runtime,wpds-runtime}.css`, `src/components/{ActivitySessionBootstrap,AIActivitySection}.js`, `src/store/{index,activity-history,activity-undo,activity-session}.js`, `src/utils/{template-actions,style-operations}.js`, `tests/phpunit/{ActivityRepositoryTest,ActivityPermissionsTest,ActivityPageTest,AgentControllerTest}.php`, `src/admin/__tests__/activity-log{,-utils}.test.js`, `src/store/__tests__/{activity-history,activity-history-state,store-actions}.test.js`, `src/components/__tests__/{AIActivitySection,ActivitySessionBootstrap}.test.js`, `tests/e2e/flavor-agent.activity.spec.js`, `docs/features/activity-and-audit.md`, `docs/reference/activity-state-machine.md`.
- **Surface-specific focus:**
  - Access control: global vs scoped activity boundary; nonces; admin-only `manage_options`; no raw secrets/credentials in payload metadata.
  - REST contract: `GET /activity`, `POST /activity`, undo/update — argument defaults, sanitization, enum/boolean parsing, `per_page` caps, sort fields, response shape stability.
  - Repository correctness: bounded queries, predictable pagination, total counts, projection backfill freshness, SQL placeholders, timezone/midnight behavior, summary counts computed over filtered set (not paged).
  - Activity state model and undo semantics matching `docs/reference/activity-state-machine.md`.
  - DataViews/DataForm rendering: filter mapping, search, sort, pagination, per-page, persisted view state, target links.
  - Metadata/provenance consistency across create/update/undo/query/serialization.
  - Six-card summary grid responsive states; review/blocked/failed/undone/applied distinguishability without color.

## Verification Commands By Surface

Use these as targeted re-runs after the review:

- **Block:** `composer run test:php -- --filter '(BlockAbilitiesTest|AgentControllerTest)'`; `npm run test:unit -- --runInBand src/inspector src/utils/block-* src/utils/recommendation-* src/store`; `npx playwright test tests/e2e/flavor-agent.smoke.spec.js`.
- **Template / Template Part:** `composer run test:php -- --filter '(TemplateAbilitiesTest|TemplatePartPromptTest|AgentControllerTest)'`; `npm run test:unit -- --runInBand src/templates src/template-parts src/utils/template-*`; WP 7.0 harness for Site Editor flows.
- **Style:** `composer run test:php -- --filter '(StyleAbilitiesTest|StylePromptTest|AgentControllerTest)'`; `npm run test:unit -- --runInBand src/global-styles src/style-book src/style-surfaces src/utils/style-*`; WP 7.0 harness.
- **Settings:** `composer run test:php -- --filter '(SettingsTest|ProviderTest|AzureBackendValidationTest|AgentControllerTest|InfraAbilitiesTest)'`; `npm run test:unit -- --runInBand src/admin/__tests__/settings-page-controller.test.js src/utils/__tests__/capability-flags.test.js`; `npx playwright test tests/e2e/flavor-agent.settings.spec.js`.
- **Activity:** `composer run test:php -- --filter '(ActivityRepositoryTest|ActivityPermissionsTest|ActivityPageTest|AgentControllerTest)'`; `npm run test:unit -- --runInBand src/admin/__tests__/activity-log src/store/__tests__/activity-* src/components/__tests__/AIActivitySection src/components/__tests__/ActivitySessionBootstrap`; `npx playwright test tests/e2e/flavor-agent.activity.spec.js`.
- **Always:** `npm run build`; `npm run lint:js -- --quiet`; `npm run check:docs`; `git diff --check`.
