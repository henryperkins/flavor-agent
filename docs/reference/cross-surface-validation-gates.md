# Cross-Surface Validation Gates

This is the release-validation reference for Flavor Agent changes that touch multiple recommendation surfaces or any shared subsystem that multiple surfaces depend on.

Use it when you need to answer:

- which release gates a change triggered
- which targeted tests and aggregate checks are required before sign-off
- which Playwright harnesses count as release evidence
- how to record a blocker or waiver when browser proof is unavailable

## Baseline Evidence

Use these gates as additive hard stops, not choose-one checks. A single change can trigger more than one gate.

Before sign-off on any triggered gate:

1. Run the nearest targeted PHPUnit and JS unit suites for the touched subsystem.
2. Run `node scripts/verify.js --skip-e2e`.
3. Inspect or attach `output/verify/summary.json`.
4. Run `npm run check:docs` when contracts, surfacing rules, operator paths, or contributor docs changed.
5. Run the targeted Playwright harnesses that match the touched surfaces.
6. If a browser harness is known-red or unavailable, record that blocker or an explicit waiver. Do not silently skip it.

Full local sign-off also requires `lint-plugin` to run. If `output/verify/summary.json` is `incomplete` because `wp` or `WP_PLUGIN_CHECK_PATH` is unavailable, treat that as an environment blocker rather than a pass.

## Validation Gates

| Gate | Trigger | Required evidence |
| --- | --- | --- |
| 1. REST and shared contracts | REST routes, abilities, `SurfaceCapabilities`, shared entity contracts, or response fields changed | Validate every current caller surface against the updated contract, run targeted PHPUnit plus JS store/client tests, and run `npm run check:docs` if the contract or surfacing rules changed |
| 2. Provider and backend routing | Provider selection, settings validation, `ResponsesClient`, `EmbeddingClient`, Qdrant, or Cloudflare grounding changed | Validate the selected connector path when applicable, the generic Connectors-first runtime, the legacy direct-provider fallback, and the embedding/Qdrant path together when pattern work is touched |
| 3. Freshness and request state | Collectors, request signatures, `resolveSignatureOnly`, store request state, or panel visibility logic changed | Validate stale and fresh behavior on each touched surface: prior result stays visible, stale state is marked, apply is disabled when required, and refresh comes from the surface that owns the request |
| 4. Apply, preview, activity, and undo | Apply helpers, operation validators, preview builders, activity persistence, or undo logic changed | Validate end to end on every touched executable surface: block direct-apply plus template, template-part, Global Styles, and Style Book review-confirm-apply where applicable; confirm activity persistence, ordered undo blocking, and admin-audit visibility |
| 5. Shared UI taxonomy and mode | Shared UI components or prompt taxonomy changed | Validate that each touched surface still keeps its intended mode: block `Apply now`, template/template-part/style `Review first`, navigation advisory-only, patterns ranking-only, and delegated block Inspector sub-panels passive-only |
| 6. Operator and admin paths | Settings, capability notices, pattern sync, or `AI Activity` admin changed | Validate correct owner messaging (`Settings > Flavor Agent` vs `Settings > Connectors`), settings validation notices, and admin activity provenance fields |
| 7. Multi-surface release matrix | The change crosses more than one recommendation surface or any shared subsystem above | No deploy until the release matrix is complete: run `node scripts/verify.js --skip-e2e`, attach `output/verify/summary.json`, and run the targeted Playwright harnesses that match the touched surfaces; a known-red or unavailable harness is a recorded blocker or explicit waiver, not a silent skip |

## Harness Mapping

- `npm run test:e2e:playground` covers post editor, block Inspector, pattern inserter, and navigation flows.
- `npm run test:e2e:wp70` covers Site Editor template, template-part, Global Styles, Style Book, and refresh/drift-sensitive flows.
- A shared-subsystem change often needs both harnesses because the caller set spans both post editor and Site Editor surfaces.

## Release Matrix

Fill this before deployment when Gate 7 triggers. The matrix below is the current shared-interface sign-off snapshot for this checkout and should be replaced by the next dated rerun.

| Surface or subsystem | Triggered gate(s) | Targeted PHPUnit / JS suites | `verify.js --skip-e2e` | Browser evidence | Blocker or waiver |
| --- | --- | --- | --- | --- | --- |
| Block Inspector | 1, 3, 5, 7 | 2026-04-22 shared-contract PHPUnit filter plus targeted JS shared-contract list | `incomplete` because `lint-plugin` cannot run locally; `build`, `lint-js`, `unit`, `lint-php`, and `test-php` all passed | `npm run test:e2e:playground` 2026-04-22: covered by green `9 passed / 2 skipped / 0 failed` run | No current browser blocker in this surface |
| Pattern Inserter | 2, 3, 5, 7 | 2026-04-22 shared-contract PHPUnit filter plus targeted JS shared-contract list | Same non-browser result as above | `npm run test:e2e:playground` 2026-04-22: covered by green `9 passed / 2 skipped / 0 failed` run | No current browser blocker in this surface |
| Navigation | 1, 5, 7 | 2026-04-22 shared-contract PHPUnit filter plus targeted JS shared-contract list | Same non-browser result as above | `npm run test:e2e:playground` 2026-04-22: covered by green `9 passed / 2 skipped / 0 failed` run | No current browser blocker; apply remains intentionally out of scope |
| Template | 1, 2, 3, 4, 5, 7 | 2026-04-22 shared-contract PHPUnit filter plus targeted JS shared-contract list | Same non-browser result as above | Playground 2026-04-22: stale, advisory-only, and unavailable-backend paths are green. WP 7.0 2026-04-22: two failing template undo/apply tests remain | Blocked by `@wp70-site-editor template undo survives a Site Editor refresh when the template has not drifted` and `@wp70-site-editor template undo is disabled after inserted pattern content changes`; the latter currently errors with `The anchored insertion target at path 1 > 0 no longer matches the expected Heading. Regenerate recommendations and try again.` |
| Template Part | 1, 2, 3, 4, 5, 7 | 2026-04-22 shared-contract PHPUnit filter plus targeted JS shared-contract list | Same non-browser result as above | WP 7.0 2026-04-22: stale and advisory-only paths are green, but executable smoke still fails | Blocked by `@wp70-site-editor template-part surface smoke previews, applies, and undoes executable recommendations`; the apply toast exposes `Undo`, but no `.flavor-agent-activity-row` Undo control becomes available |
| Global Styles | 1, 2, 3, 4, 5, 7 | 2026-04-22 shared-contract PHPUnit filter plus targeted JS shared-contract list | Same non-browser result as above | WP 7.0 2026-04-22: defaults and stale-state coverage are green, but executable smoke still fails | Blocked by `@wp70-site-editor global styles surface previews, applies, and undoes executable recommendations`; the visible UI shows an undone action, but the harness state probe still reports `undoStatus: 'idle'` with empty `settings`/`styles` arrays |
| Style Book | 2, 3, 5, 7 | 2026-04-22 shared-contract PHPUnit filter plus targeted JS shared-contract list | Same non-browser result as above | `npm run test:e2e:wp70` 2026-04-22: Style Book stale-state coverage passed in the `6 passed / 4 failed` rerun | No current browser blocker in this surface |
| Settings / AI Activity / shared subsystem | 1, 2, 4, 6, 7 | 2026-04-22 shared-contract PHPUnit filter plus targeted JS shared-contract list, plus current activity/settings Playwright specs | `incomplete` because `lint-plugin` cannot run locally; executable non-browser steps passed | `npm run test:e2e:playground` 2026-04-22: activity and settings specs passed | Environment blocker remains until `wp` and `WP_PLUGIN_CHECK_PATH` are available for a real `lint-plugin` run |

## Notes

- "All affected surfaces" means all current callers of the changed contract, not automatically all seven recommendation surfaces unless the code path is truly shared across the full map.
- Use `STATUS.md` as the source of truth for current browser-harness health before treating Playwright evidence as green.
- `npm run verify` remains the full aggregate pipeline. This reference uses `node scripts/verify.js --skip-e2e` as the baseline non-browser gate because Gate 7 handles browser proof explicitly.
- Current saved evidence for the 2026-04-22 snapshot lives at `output/verify/summary.json`, `output/playwright/playground-run-2026-04-22.log`, and `output/playwright-wp70/wp70-run-2026-04-22-rerun.log`.

## Primary Source Files

- `scripts/verify.js`
- `STATUS.md`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/reference/abilities-and-routes.md`
- `docs/reference/provider-precedence.md`
- `docs/reference/recommendation-ui-consistency.md`
- `docs/reference/activity-state-machine.md`
