# Test Coverage Remediation Plan

Date: 2026-04-29

Status: proposed

Scope: Close the coverage gaps identified after the full `npm run verify` pass in `output/verify/summary.json`. This plan adds automated coverage only where the current suite does not exercise shipped behavior through the right boundary. Product behavior changes should be limited to fixes exposed by these tests.

## Findings Covered

1. Block Inspector apply, persistence, and undo has no active browser coverage because the existing Playground test is `test.fixme`.
2. Content recommendations have shallow unit/PHP coverage and no browser flow.
3. REST route registration, permission callbacks, required args, and sanitize callbacks are not exercised through a route dispatcher.
4. The admin AI Activity page browser spec uses a mocked REST response, so it does not prove real repository data reaches wp-admin.
5. The settings page browser spec checks layout and help behavior, but not form submission, persisted settings, validation notices, or feedback focus.
6. Plugin lifecycle, deactivation, and uninstall cleanup are not covered as wired from the plugin bootstrap.

## Target Outcomes

- Every shipped recommendation surface has at least one active browser test for its primary user workflow or a documented reason why it is API-only/advisory-only.
- REST routes are tested through a route boundary that enforces permissions, required args, validation, and sanitization.
- Admin surfaces have one mocked UI test for defensive rendering and one full-stack test for real server data or settings persistence.
- Activation, deactivation, cron wiring, option-change hooks, and uninstall cleanup have direct tests.
- `npm run verify` keeps passing with no additional silent skips for the remediated areas.
- `docs/reference/cross-surface-validation-gates.md` and `STATUS.md` can point to current green evidence instead of historical waivers.

## Workstream 1: Restore Block Inspector Browser Coverage

### Current Evidence

- The skipped browser test is `tests/e2e/flavor-agent.smoke.spec.js`, `block inspector smoke applies, persists, and undoes AI recommendations`.
- The latest Playground verify log reports this test as skipped.
- JS unit coverage exercises apply guards and activity logging, but not the live editor path.

### Complete Solution

1. Add an active Docker-backed WP 7.0 browser test tagged `@wp70-site-editor` for the post editor block inspector path.
   - Open `/wp-admin/post-new.php`.
   - Seed a paragraph block and select it.
   - Mock `POST /flavor-agent/v1/recommend-block` with an executable content update and a stable `resolvedContextSignature`.
   - Click `Get Suggestions`.
   - Apply the returned block suggestion.
   - Assert the live block content changes to the mocked recommendation.
   - Assert the inline apply success notice and `Recent AI Actions` row appear.
   - Save the post, reload the edit URL, reselect the paragraph, and assert the server-backed activity row hydrates.
   - Click `Undo` and assert the paragraph content returns to its original value and the activity undo status is `undone`.

2. Keep the existing Playground `test.fixme` only as a historical harness note until the Playground hydration issue is fixed.
   - Add a comment that the active equivalent lives in the WP 7.0 harness.
   - Do not count the skipped Playground test as release evidence.

3. Add one focused JS unit if the browser test exposes a selector timing hole.
   - Target `src/components/ActivitySessionBootstrap.js` or `src/store/activity-session.js` only if reload hydration fails due to store/session behavior.

### Acceptance Criteria

- `npm run test:e2e:wp70 -- tests/e2e/flavor-agent.smoke.spec.js` includes a passing block inspector apply/persist/undo test.
- The block workflow has active browser proof in `output/verify/e2e-wp70.stdout.log`.
- The skipped Playground test is no longer the only block apply browser coverage.

## Workstream 2: Deepen Content Recommendation Coverage

### Current Evidence

- `src/content/__tests__/ContentRecommender.test.js` verifies basic rendering, mode switching, request payloads, and unsupported entities.
- `tests/phpunit/ContentAbilitiesTest.php` verifies only edit-mode validation and one prompt contract.
- There is no E2E content recommendation flow.

### Complete Solution

1. Add `tests/e2e/flavor-agent.content.spec.js` for the post editor content panel.
   - Use the Playground harness unless the panel proves unstable there.
   - Open `/wp-admin/post-new.php`.
   - Set a title and body content.
   - Mock `POST /flavor-agent/v1/recommend-content`.
   - Verify draft mode renders generated title, summary, and multi-paragraph content.
   - Switch to edit mode and assert the request includes current post content.
   - Switch to critique mode and assert notes/issues render in the `Editorial Notes` section.
   - Mock a REST error and assert the surface status notice is visible and dismissible.

2. Expand `src/content/__tests__/ContentRecommender.test.js`.
   - Render ready-state title, summary, content paragraphs, notes, and issues.
   - Render empty-result status copy.
   - Render capability notice when `content.available` is false.
   - Render recent content request activity entries.

3. Expand `tests/phpunit/ContentAbilitiesTest.php`.
   - Missing instruction returns `missing_content_instruction`.
   - Invalid `mode` falls back to `draft`.
   - `edit` and `critique` require existing content.
   - `postContext` sanitizes multiline text, slugs/status, categories, and tags.
   - `voiceProfile` is included in the prompt but sanitized.

4. Add one REST controller assertion if the content route remains outside the route-dispatch workstream.
   - Verify `handle_recommend_content()` persists success and failure diagnostics with route metadata.

### Acceptance Criteria

- Content recommendations have unit, PHP, and browser evidence.
- Draft, edit, critique, error, empty, unavailable, and activity-display states are covered.
- The content surface remains editorial-only and does not auto-apply post content.

## Workstream 3: Add REST Route Boundary Tests

### Current Evidence

- `Agent_Controller::register_routes()` defines route permissions, required args, validation callbacks, and sanitize callbacks.
- Existing controller tests call handlers directly with `WP_REST_Request`, bypassing registration and permission behavior.
- The PHPUnit bootstrap has request/response stubs, but not a route registry or dispatcher.

### Complete Solution

1. Add lightweight REST route test infrastructure to `tests/phpunit/bootstrap.php`.
   - Stub `register_rest_route()` to store route definitions in `WordPressTestState`.
   - Add a small helper that dispatches a stored route by method/path.
   - The helper should:
     - Run the `permission_callback`.
     - Enforce required args.
     - Run `validate_callback`.
     - Run `sanitize_callback`.
     - Return `WP_Error` with a status matching WordPress REST semantics for permission and validation failures.

2. Add `tests/phpunit/AgentRoutesTest.php`.
   - Call `Agent_Controller::register_routes()` in `setUp()`.
   - Assert all expected routes are registered:
     - `POST /recommend-block`
     - `POST /recommend-content`
     - `POST /recommend-patterns`
     - `POST /recommend-navigation`
     - `POST /recommend-style`
     - `POST /recommend-template`
     - `POST /recommend-template-part`
     - `GET/POST /activity`
     - `POST /activity/{id}/undo`
     - `POST /sync-patterns`
   - Permission cases:
     - `edit_posts` required for block/content/pattern routes.
     - `edit_theme_options` required for navigation/style/template/template-part.
     - `manage_options` required for pattern sync and global admin activity reads.
     - Contextual activity permissions still route through `ActivityPermissions`.
   - Validation cases:
     - Missing `editorContext` for block returns a REST arg error.
     - Missing `postType` for patterns returns a REST arg error.
     - Empty `templateRef` and `templatePartRef` are rejected.
     - Missing `scope` or `styleContext` for style is rejected.
     - Non-string `visiblePatternNames` members are rejected.
   - Sanitization cases:
     - `prompt` is textarea-sanitized.
     - `clientId`, `templateRef`, `templatePartRef`, and `surface` are text-sanitized.
     - `resolveSignatureOnly` is boolean-sanitized.

3. Keep direct handler tests.
   - Handler tests remain valuable for detailed payload behavior and should not be rewritten into route tests.

### Acceptance Criteria

- A bad caller cannot bypass missing required args in tests.
- Route permission failures are covered separately from handler logic.
- Registered route definitions are treated as a public contract.

## Workstream 4: Add Full-Stack Admin Activity Browser Coverage

### Current Evidence

- `tests/e2e/flavor-agent.activity.spec.js` intercepts the activity REST endpoint and returns mocked entries.
- PHP tests cover `ActivityRepository` and `Agent_Controller`, but no browser test proves real persisted activity appears in wp-admin.

### Complete Solution

1. Keep the existing mocked activity page E2E test.
   - It remains useful for UI layout, client-side filtering, and error rendering.

2. Add a new full-stack activity admin test under the WP 7.0 harness.
   - Use WP-CLI before the test to reset the activity table and seed rows through `FlavorAgent\Activity\Repository::create()`.
   - Seed at least:
     - One block apply row.
     - One template apply row.
     - One failed request diagnostic.
     - One undone row.
   - Open `/wp-admin/options-general.php?page=flavor-agent-activity` with no route interception.
   - Assert summary cards reflect the seeded repository data.
   - Assert feed rows and the details sidebar show provider metadata, document scope, before/after summaries, and undo status.
   - Use the search box and one DataViews filter to prove the browser talks to the real REST route.

3. Add one permission-oriented browser check only if route tests are not enough.
   - Prefer PHPUnit for permissions.
   - Browser permission coverage is optional unless the admin menu or page load behavior changes.

4. Add docs or status evidence.
   - Update `STATUS.md` after the test is green to say admin activity has mocked UI coverage plus full-stack repository-to-wp-admin coverage.

### Acceptance Criteria

- Admin activity page has one mocked UI test and one real repository-backed browser test.
- Full-stack test fails if `ActivityRepository`, `Agent_Controller`, asset boot data, or the admin React app stop agreeing.

## Workstream 5: Add Settings Save and Validation Browser Coverage

### Current Evidence

- `tests/e2e/flavor-agent.settings.spec.js` checks the page IA, accordions, and Help tabs.
- `tests/phpunit/SettingsTest.php` covers sanitizers, validation feedback, rendering, and default section selection.
- No browser test submits the settings form or verifies persisted values and notices.

### Complete Solution

1. Keep the existing settings IA E2E test.

2. Add `tests/e2e/flavor-agent.settings-save.spec.js`.
   - Use the WP 7.0 harness because it already provisions the expected plugin stack.
   - Open `Settings > Flavor Agent`.
   - Submit a safe settings change that does not require outbound network:
     - Pattern recommendation threshold.
     - Pattern max recommendations.
     - Guidelines site/copy/images/additional fields.
     - Cloudflare max results.
   - Assert the WordPress Settings API success notice appears.
   - Reload the page and assert values persist.
   - Assert the expected section remains open or receives focus based on request-scoped feedback behavior.

3. Add one browser-level validation notice path that can be deterministic without external services.
   - Prefer local validation failures such as malformed Qdrant URL or invalid numeric bounds.
   - If the current sanitizers only fail after remote validation, keep the browser test to local persistence and rely on PHP for remote validation paths.

4. Add PHPUnit coverage for settings registration if route/lifecycle tests do not cover it.
   - Call `Registrar::register_settings()`.
   - Assert every current option has the expected option group, type, default, and sanitize callback.
   - This catches drift between rendered controls, registered settings, and uninstall cleanup.

### Acceptance Criteria

- At least one browser test submits the real settings form and verifies persistence after reload.
- Deterministic validation feedback is covered either in browser or explicitly in PHPUnit.
- Remote validation remains covered in PHPUnit with mocked `wp_remote_*` responses.

## Workstream 6: Cover Plugin Lifecycle and Uninstall Cleanup

### Current Evidence

- Activation/deactivation hooks are registered in `flavor-agent.php`.
- Cron hooks, option-change hooks, and pattern-index lifecycle hooks are also wired in `flavor-agent.php`.
- `uninstall.php` deletes selected legacy/current options and clears selected cron hooks.
- There is no targeted test for the bootstrap wiring or uninstall cleanup list.

### Complete Solution

1. Add hook-registration stubs to the PHPUnit bootstrap or a dedicated isolated bootstrap.
   - Record calls to `register_activation_hook()`.
   - Record calls to `register_deactivation_hook()`.
   - Record `add_action()` and `add_filter()` calls with hook, callback, priority, and accepted args.
   - Use `@runInSeparateProcess` for tests that include `flavor-agent.php` to avoid double-loading constants/functions.

2. Add `tests/phpunit/PluginLifecycleTest.php`.
   - Include `flavor-agent.php` in an isolated process.
   - Assert activation and deactivation callbacks are registered for `FLAVOR_AGENT_FILE`.
   - Invoke the activation callback and assert:
     - Activity table install path ran.
     - Activity prune schedule exists.
     - Pattern index activation/sync scheduling ran.
     - Docs prewarm and core roadmap warm schedules are registered when their environment guards allow it.
   - Invoke the deactivation callback and assert:
     - Pattern index deactivation ran.
     - Activity prune/backfill cron hooks are cleared.
     - Docs prewarm/context warm hooks are cleared.
     - Core roadmap warm hook is cleared.
   - Assert action/filter wiring:
     - `enqueue_block_editor_assets` -> `flavor_agent_enqueue_editor`.
     - `rest_api_init` -> `Agent_Controller::register_routes`.
     - Abilities API category and ability hooks.
     - Pattern index registry-change hooks.
     - Option dependency update hooks for provider, embedding, Qdrant, connector, and `home`.
     - `block_editor_settings_all` keeps the `recommended` pattern category first.

3. Add `tests/phpunit/UninstallTest.php`.
   - Run in a separate process.
   - Define `WP_UNINSTALL_PLUGIN`.
   - Seed all plugin options currently registered by `Registrar::register_settings()`, plus runtime state options used by docs, activity, and pattern indexing.
   - Seed cron hooks and the pattern sync lock transient.
   - Include `uninstall.php`.
   - Assert all expected plugin-owned options are deleted.
   - Assert all plugin-owned cron hooks are cleared.
   - Assert the sync-lock transient is deleted.

4. Patch `uninstall.php` if tests expose drift.
   - Include currently registered options such as provider selection, OpenAI Native embedding model, reasoning effort, pattern recommendation controls, guideline options, docs runtime state, and activity projection state if they are plugin-owned.
   - Keep cleanup scoped to Flavor Agent-owned data only.

### Acceptance Criteria

- Plugin bootstrap hook wiring is covered without relying on Plugin Check.
- Uninstall tests fail when a new plugin-owned option is registered but not cleaned up or intentionally retained.
- Any retention decision is documented in `uninstall.php` comments and this plan's follow-up status.

## Workstream 7: Prevent Coverage Drift

### Complete Solution

1. Update `docs/FEATURE_SURFACE_MATRIX.md`.
   - Add a "Current automated evidence" column or companion section.
   - Link each surface to its closest PHPUnit, JS unit, and Playwright tests.

2. Update `docs/reference/cross-surface-validation-gates.md`.
   - Replace the stale claim that Block Inspector has no current browser blocker only after Workstream 1 is green.
   - Add settings-save and full-stack admin activity evidence once Workstreams 4 and 5 are green.

3. Update `STATUS.md`.
   - Record the date, commands, and pass counts after the remediation is implemented.
   - Remove or downgrade the old browser-skip caveats only when active replacements exist.

4. Add a lightweight skipped-test audit to `scripts/verify.js` or a separate docs check only if the team wants a hard gate.
   - Start as report-only:
     - Count `test.fixme`, `test.skip`, and Playwright skipped tests.
     - Print their names in the verify summary.
   - Promote to a failure only after intentional skips have a tracked waiver format.

### Acceptance Criteria

- Future contributors can answer "what tests cover this surface?" without redoing manual grep work.
- Intentional skipped browser tests are visible in verification output or documented as waivers.

## Implementation Sequence

1. Workstream 3 first: route-boundary tests are fast and may expose registration contract issues.
2. Workstream 6 next: lifecycle/uninstall tests may require small bootstrap changes that are easier to review in isolation.
3. Workstream 2 next: content unit/PHP coverage before browser coverage.
4. Workstream 1 next: active block browser coverage, using WP 7.0 to avoid the known Playground reload limitation.
5. Workstream 4 next: full-stack admin activity browser coverage.
6. Workstream 5 next: settings save/persistence browser coverage.
7. Workstream 7 last: update docs/status after green evidence exists.

This order keeps test infrastructure changes ahead of browser work and avoids updating evidence documents before the evidence exists.

## Targeted Verification

Run focused checks as each workstream lands:

```bash
composer run test:php -- --filter '(AgentRoutesTest|AgentControllerTest|ActivityPermissionsTest)'
composer run test:php -- --filter '(PluginLifecycleTest|UninstallTest|SettingsTest)'
npm run test:unit -- --runInBand src/content/__tests__/ContentRecommender.test.js src/store/__tests__/store-actions.test.js src/store/__tests__/activity-history.test.js src/components/__tests__/ActivitySessionBootstrap.test.js
npm run test:e2e:playground -- tests/e2e/flavor-agent.content.spec.js tests/e2e/flavor-agent.activity.spec.js tests/e2e/flavor-agent.settings.spec.js
npm run test:e2e:wp70 -- tests/e2e/flavor-agent.smoke.spec.js tests/e2e/flavor-agent.settings-save.spec.js
```

Run aggregate checks before closing the remediation:

```bash
npm run build
npm run lint:js
composer run lint:php
npm run lint:plugin
composer run test:php
npm run test:unit
npm run test:e2e
npm run check:docs
npm run verify
```

If plugin-check prerequisites are unavailable, record the environment blocker and run:

```bash
npm run verify -- --skip=lint-plugin
```

Do not use a skipped browser test as evidence for a remediated browser finding.

## Definition of Done

- All six findings have targeted automated tests.
- Any product bugs exposed by the new tests are fixed in the same workstream.
- `output/verify/summary.json` reports `status: "pass"` for the aggregate run, or any `incomplete` status has an explicit environment reason.
- Playwright output shows no new skipped tests for the remediated coverage.
- `STATUS.md` and validation-gate docs reflect the new evidence.
- The final PR description lists each finding, the tests added, and the command evidence.

## Expected Files To Touch

- `tests/phpunit/bootstrap.php`
- `tests/phpunit/AgentRoutesTest.php`
- `tests/phpunit/PluginLifecycleTest.php`
- `tests/phpunit/UninstallTest.php`
- `tests/phpunit/ContentAbilitiesTest.php`
- `src/content/__tests__/ContentRecommender.test.js`
- `tests/e2e/flavor-agent.smoke.spec.js`
- `tests/e2e/flavor-agent.content.spec.js`
- `tests/e2e/flavor-agent.activity.spec.js`
- `tests/e2e/flavor-agent.settings-save.spec.js`
- `uninstall.php`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/reference/cross-surface-validation-gates.md`
- `STATUS.md`
