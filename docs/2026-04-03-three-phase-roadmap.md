# Flavor Agent Three-Phase Roadmap

Date: 2026-04-03

This roadmap translates the current repo analysis into a concrete execution sequence. It assumes:

- WordPress 7.0 is still pre-release and its final release date is now `TBD` after the 2026-03-31 Core post extending the cycle.
- No new WordPress 7.0 features beyond what is already in core should be assumed.
- Flavor Agent should continue to behave like Gutenberg and wp-admin became smarter, not like a second AI application.

Primary references:

- `docs/2026-04-03-wordpress-direction-review.md`
- `docs/2026-03-25-roadmap-aligned-execution-plan.md`
- `docs/SOURCE_OF_TRUTH.md`
- `STATUS.md`

## Phase 1: Stabilize, Verify, And Tighten Platform Alignment

Target window: now through the 7.0 stable cutover

### Goals

1. Remove stale release assumptions and lock the repo to the current Core reality.
2. Re-verify the plugin against the final WordPress 7.0 shape as soon as the stable image exists.
3. Tighten Connectors and AI Client readiness handling so every surface reports platform state consistently.
4. Deepen audit/provenance before broadening product scope.

### Main deliverables

1. Replace all lingering April 9, 2026 assumptions with "7.0 final timeline pending".
2. Swap the Docker-backed 7.0 harness from beta to stable as soon as the official image exists.
3. Re-run provider-backed end-to-end verification and record the outcome.
4. Improve `Settings > AI Activity` so operators can see what happened, why undo is or is not available, and which provider/model path was used.
5. Normalize connector-aware surface readiness and setup messaging.

### File targets

Docs and status:

- `docs/2026-03-25-roadmap-aligned-execution-plan.md`
- `docs/SOURCE_OF_TRUTH.md`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/flavor-agent-readme.md`
- `docs/wordpress-7.0-developer-docs-index.md`
- `docs/wordpress-7.0-gutenberg-22.8-reference.md`
- `STATUS.md`

7.0 harness and release verification:

- `playwright.wp70.config.js`
- `scripts/wp70-e2e.js`
- `docker-compose.yml`
- `docker/wordpress/Dockerfile`
- `tests/e2e/flavor-agent.smoke.spec.js`

Connector and readiness convergence:

- `inc/OpenAI/Provider.php`
- `inc/Abilities/InfraAbilities.php`
- `inc/Abilities/SurfaceCapabilities.php`
- `inc/Settings.php`
- `flavor-agent.php`
- `src/utils/capability-flags.js`
- `src/components/CapabilityNotice.js`

Audit and provenance:

- `inc/Activity/Repository.php`
- `inc/REST/Agent_Controller.php`
- `inc/Admin/ActivityPage.php`
- `src/admin/activity-log.js`
- `src/admin/activity-log-utils.js`
- `src/admin/activity-log.css`
- `src/components/AIActivitySection.js`

Tests:

- `tests/phpunit/InfraAbilitiesTest.php`
- `tests/phpunit/SettingsTest.php`
- `tests/phpunit/ActivityRepositoryTest.php`
- `tests/phpunit/ActivityPermissionsTest.php`
- `tests/phpunit/AgentControllerTest.php`
- `src/utils/__tests__/capability-flags.test.js`
- `src/admin/__tests__/activity-log.test.js`
- `src/admin/__tests__/activity-log-utils.test.js`
- `src/components/__tests__/AIActivitySection.test.js`

### Acceptance checks

Required automated checks:

```bash
vendor/bin/phpunit --filter '(InfraAbilitiesTest|SettingsTest|ActivityRepositoryTest|ActivityPermissionsTest|AgentControllerTest)'
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/utils/__tests__/capability-flags.test.js src/admin/__tests__/activity-log.test.js src/admin/__tests__/activity-log-utils.test.js src/components/__tests__/AIActivitySection.test.js
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run build
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:playground -- --reporter=line
```

Required release/harness checks:

```bash
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:wp70 -- --reporter=line
```

Required manual checks:

1. Confirm all docs and status pages stop implying April 9, 2026 is the final 7.0 release date.
2. Confirm each unavailable surface points to the correct owner: `Settings > Connectors` or `Settings > Flavor Agent`.
3. Confirm admin activity details show enough provenance to answer "what changed, by which backend path, and why undo is blocked".
4. Run at least one live provider-backed recommendation flow and record the result in `STATUS.md`.

### Exit criteria

1. The repo no longer encodes stale 7.0 release timing assumptions.
2. The WP 7.0 harness runs on the stable image once available.
3. Readiness and degraded-mode behavior are consistent across all first-party surfaces.
4. The admin audit view is strong enough to support future structural and agent-adjacent work.

## Phase 2: Deepen Structural Intelligence Without Expanding Trust Risk

Target window: immediately after Phase 1, once 7.0 stable verification is in place

### Goals

1. Improve recommendation quality around the structural nouns WordPress is actively strengthening.
2. Expand bounded template and template-part intelligence without creating free-form mutation flows.
3. Bring richer structural context into ranking, explanations, and executable validation.
4. Keep navigation advisory-first unless a tiny deterministic executor clearly proves safe.

### Main deliverables

1. Better structural context for templates, template parts, navigation, and style surfaces.
2. Pattern Overrides-aware recommendations for custom blocks, but recommendation-oriented first.
3. Viewport visibility-aware recommendations and safer handling of `metadata.blockVisibility`.
4. Navigation overlay-aware advisory reasoning.
5. More capable but still bounded template and template-part operation contracts.

### File targets

Structural context and abilities:

- `inc/Context/ServerCollector.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/NavigationAbilities.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/Registration.php`
- `inc/REST/Agent_Controller.php`

Prompt contracts:

- `inc/LLM/TemplatePrompt.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/LLM/NavigationPrompt.php`
- `inc/LLM/StylePrompt.php`
- `inc/LLM/Prompt.php`

Executors and client flows:

- `src/utils/template-operation-sequence.js`
- `src/utils/template-actions.js`
- `src/templates/template-recommender-helpers.js`
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/inspector/NavigationRecommendations.js`
- `src/patterns/PatternRecommender.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/store/index.js`
- `src/store/activity-history.js`

Docs:

- `docs/features/template-recommendations.md`
- `docs/features/template-part-recommendations.md`
- `docs/features/navigation-recommendations.md`
- `docs/features/style-and-theme-intelligence.md`
- `docs/features/pattern-recommendations.md`
- `docs/reference/abilities-and-routes.md`
- `docs/reference/template-operations.md`
- `docs/reference/activity-state-machine.md`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `STATUS.md`

Tests:

- `tests/phpunit/ServerCollectorTest.php`
- `tests/phpunit/RegistrationTest.php`
- `tests/phpunit/AgentControllerTest.php`
- `tests/phpunit/TemplatePromptTest.php`
- `tests/phpunit/TemplatePartPromptTest.php`
- `tests/phpunit/NavigationAbilitiesTest.php`
- `src/utils/__tests__/template-actions.test.js`
- `src/templates/__tests__/TemplateRecommender.test.js`
- `src/template-parts/__tests__/TemplatePartRecommender.test.js`
- `src/inspector/__tests__/NavigationRecommendations.test.js`
- `src/global-styles/__tests__/GlobalStylesRecommender.test.js`

### Acceptance checks

Required automated checks:

```bash
vendor/bin/phpunit --filter '(ServerCollectorTest|RegistrationTest|AgentControllerTest|TemplatePromptTest|TemplatePartPromptTest|NavigationAbilitiesTest)'
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/utils/__tests__/template-actions.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/inspector/__tests__/NavigationRecommendations.test.js src/global-styles/__tests__/GlobalStylesRecommender.test.js
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run build
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:playground -- --reporter=line
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:e2e:wp70 -- --reporter=line
```

Required manual checks:

1. Template and template-part recommendations remain preview-first and undoable after any executable contract expansion.
2. Navigation recommendations become more useful structurally without silently becoming executable.
3. Pattern Overrides and viewport visibility metadata improve ranking/explanations before they are used to widen mutation scope.
4. No new structural suggestion bypasses `template-operation-sequence.js` and the existing activity/undo path.

### Exit criteria

1. Structural recommendations are materially better without weakening deterministic validation.
2. Template and template-part execution remains bounded, previewable, and refresh-safe to undo.
3. Navigation is still advisory-only unless a separate bounded execution slice is explicitly accepted.
4. The docs and tests fully describe the widened contract.

## Phase 3: Add Interoperability And Narrow Admin-Side Agent Hooks

Target window: after Phase 2 is stable on WordPress 7.0 stable

### Goals

1. Expose Flavor Agent's capabilities more cleanly to admin-side and external agent flows without rewriting the editor runtime.
2. Introduce only narrow client-side Abilities usage where it removes duplication or improves admin-side discovery/actions.
3. Extend the audit and action layer into a more useful operator surface before considering a broader site agent.

### Main deliverables

1. Targeted admin-side use of `@wordpress/core-abilities` where it simplifies action discovery or execution.
2. Better machine-readable and human-readable alignment between abilities, REST routes, and audit entries.
3. Optional row-level admin actions only where they are deterministic, permission-safe, and fully auditable.
4. Clearer external-agent/MCP posture through the existing Abilities contract rather than a separate custom protocol layer.

### File targets

Abilities and admin integration:

- `inc/Abilities/Registration.php`
- `inc/Abilities/InfraAbilities.php`
- `inc/REST/Agent_Controller.php`
- `src/admin/activity-log.js`
- `src/admin/activity-log-utils.js`
- `src/admin/activity-log.css`
- `src/admin/sync-button.js`

Optional narrow client-side abilities usage:

- `src/index.js` only if a genuinely narrow integration is required
- any new admin-only module added for ability-driven actions

Audit and provenance continuation:

- `inc/Activity/Repository.php`
- `src/components/AIActivitySection.js`
- `src/store/activity-history.js`

Docs:

- `docs/reference/abilities-and-routes.md`
- `docs/features/activity-and-audit.md`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/SOURCE_OF_TRUTH.md`
- `STATUS.md`

Tests:

- `tests/phpunit/RegistrationTest.php`
- `tests/phpunit/ActivityRepositoryTest.php`
- `tests/phpunit/ActivityPermissionsTest.php`
- `tests/phpunit/AgentControllerTest.php`
- `src/admin/__tests__/activity-log.test.js`
- `src/admin/__tests__/activity-log-utils.test.js`
- `src/components/__tests__/AIActivitySection.test.js`

### Acceptance checks

Required automated checks:

```bash
vendor/bin/phpunit --filter '(RegistrationTest|ActivityRepositoryTest|ActivityPermissionsTest|AgentControllerTest)'
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js src/admin/__tests__/activity-log-utils.test.js src/components/__tests__/AIActivitySection.test.js
source ~/.nvm/nvm.sh && nvm use 20 >/dev/null && npm run build
```

Required manual checks:

1. Any new admin-side actions are visible through one clear contract path and leave auditable activity.
2. Client-side Abilities usage remains narrow and does not replace the existing first-party editor store/runtime model.
3. External-agent interoperability improves through the existing Abilities contract rather than a second overlapping abstraction.

### Exit criteria

1. Flavor Agent has a stronger admin-side operator surface and a cleaner machine-readable contract.
2. The plugin is better prepared for future MCP or agent integrations without becoming a chat shell.
3. First-party editor UX remains scoped, deterministic, and WordPress-native.

## Explicit Non-Goals Across All Phases

- A floating AI chat workspace.
- Free-form site generation inside this plugin.
- Broad autonomous mutation of site structure or content.
- Depending on unresolved collaboration internals before Core settles them.
- Front-end Interactivity API work before the plugin ships a front-end runtime surface that needs it.
- Tooling churn for its own sake.

## Recommended Sequence

1. Complete Phase 1 before starting Phase 2 contract expansion.
2. Complete Phase 2 before introducing any Phase 3 admin-side ability execution.
3. Revisit broader "site agent" ideas only after all three phases are complete and stable on WordPress 7.0+.
