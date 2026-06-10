---
name: ui-theme-style-review
description: Use when reviewing Flavor Agent's editor/admin UI for confirmed theme-token, style, accessibility (WCAG AA contrast / :focus-visible / prefers-reduced-motion), stale-state-apply, capability-gating, abilities-bridge, DataViews, PHP-escaping, or maintainability issues. Produces a review-only P0–P3 findings report tied to opened runtime code — no edits, no redesigns.
---

# UI / Theme / Style Review (Flavor Agent)

## Overview

Act as a senior WordPress/Gutenberg UI reviewer for this plugin. Produce a **review-only** report of *confirmed* issues across theme/style tokens, accessibility, escaping/security, runtime contracts, stale-state safety, theming, performance, and maintainability — each tied to opened runtime code, severity-ordered `P0`→`P3`.

This is the in-harness operational form of the repo source docs — keep them aligned:

- `docs/reference/flavor-agent-ui-theme-style-review-prompt-fable-5.md` — the current canonical full prompt.
- `docs/reference/flavor-agent-ui-theme-style-review-prompt-gpt-55.md` — legacy runner variant kept aligned for shared scope.
- `docs/reference/review-response-protocol.md` — the required output/scope contract.

**Do not** modify files, rewrite components, run write-formatters, or propose broad redesigns. This is distinct from the generic `/code-review` and `/security-review` skills: it is scoped to this plugin's editor + admin UI surfaces and the theming/a11y/stale-state/contract categories below.

## Evidence discipline

- **Search hits are leads only.** Use `Grep`/`Glob` to locate, then `Read` the file and confirm a concrete runtime path before reporting. A grep-only observation is *not* a confirmed finding — list it under Open Questions instead.
- **Ignore generated output:** `build/` and release artifacts in `dist/` are out of scope.
- Use the **minimum** evidence needed to confirm a finding, then stop (see Stop rules).
- Tests/test utilities are in scope only when a confirmed runtime issue traces to stale mocks or harness behavior.

## Confirm runtime entry/wiring from

`webpack.config.js` · `flavor-agent.php` · `src/index.js` · `src/admin/settings-page.js` · `src/admin/activity-log.js` · `inc/Settings.php` · `inc/Admin/Settings/Assets.php` · `inc/Admin/ActivityPage.php`. For ability execution: `assets/abilities-bridge.js`, `src/store/abilities-client.js`, `inc/Abilities/Registration.php`, `inc/REST/Agent_Controller.php`.

## Surfaces & high-value files

| Area | Where to look |
|------|---------------|
| Editor recommendation surfaces | `src/inspector/*`, `src/patterns/*`, `src/content/*`, `src/templates/*`, `src/template-parts/*`, `src/global-styles/*`, `src/style-book/*`, `src/style-surfaces/*` |
| Shared UI / toasts / undo / stale-state / capability gating | `src/components/*`, `src/store/*`, `src/utils/*`, `src/context/*` |
| Tokens & styles | `src/tokens.css`, `src/editor.css`, `src/admin/{settings,brand,activity-log,wpds-runtime,dataviews-runtime}.css` |
| Admin (Settings + AI Activity, DataViews, Core AI Request Logs) | `inc/Admin/Settings/*`, `inc/Admin/ActivityPage.php`, `inc/Activity/*` (REST contract via `inc/Activity/Repository.php`; dual logging via `inc/Activity/RequestLoggingBridge.php`) |
| Style validation / theming server side | `inc/Context/*`, `inc/LLM/{StylePrompt,StyleContrastValidator,ThemeTokenFormatter}.php` |

## Review focus — report confirmed issues for

- Hard-coded color/spacing/typography/z-index bypassing tokens, `theme.json`, WPDS tokens, or `--wp--*` custom properties. Don't flag `var(--token, #fallback)` fallbacks unless the fallback causes a confirmed issue.
- Literal colors breaking brand/dark-mode/token theming; state distinctions relying on **color alone**.
- WCAG AA contrast failures, and client/server contrast-validation **drift** between `inc/LLM/StyleContrastValidator.php` and `src/utils/style-operations.js`.
- Stale-result UI that still permits apply after request/review/resolved context drift; apply flows that skip server apply-context revalidation before mutating templates, template parts, Global Styles, Style Book, or structural block ops.
- Missing/suppressed `:focus-visible`; motion ignoring `prefers-reduced-motion`.
- Responsive overflow in Inspector, Site Editor sidebars, Settings, AI Activity, DataViews, details panels, or validation states.
- iframe-aware focus/keyboard bugs, incl. `ToastRegion` `mod+alt+shift+u` undo-focus routing; undo-toast regressions (cap-3 eviction, skip-oldest-interacted, hover/focus pause, failed-undo state, surface→title mapping).
- Capability gating that prefers legacy `canRecommend*` flags over `flavorAgentData.capabilities.surfaces`.
- Abilities-bridge drift: readiness handling, REST fallback, signal handling, result payload normalization.
- DataViews contract drift (don't claim DataForm is in AI Activity runtime unless opened code proves it); Settings validation errors hidden in collapsed details/accordion.
- Core AI Request Logs / dual logging drift: `AiRequestLogPanel`, `ai/v1/logs/{id}` fetches, token-only unavailable states, Tools > AI Request Logs links, and Settings copy should match current core-logging and dual-logging behavior.
- PHP admin escaping (confirm context-specific escaping); duplicated runtime logic with concrete drift risk; dead exports / orphaned CSS **only** after confirming runtime entry/import paths.
- Direct `__experimental*` usage **outside** the compat/wrapper boundary: `src/patterns/pattern-settings.js`, `src/patterns/compat.js`, `src/context/theme-settings.js`, `src/context/theme-tokens.js`, `src/context/block-inspector.js`, `src/global-styles/selectors.js`.

## Severity

| Level | Meaning |
|-------|---------|
| `P0` | Security issue, data loss, broken critical flow, or can take down the UI |
| `P1` | Major user-visible bug, accessibility blocker, incorrect apply behavior, or serious contract drift |
| `P2` | Moderate a11y/responsive/stale-state/theming/perf/maintainability issue with credible user impact |
| `P3` | Minor but confirmed polish/fragility/duplication/dead-code with a clear minimal fix |

## Output contract

Start with `## Confirmed Findings`. For each: **Severity** · **file:line** · **Observed behavior** · **Impact** · **Minimal fix**.
Then `## Open Questions / Assumptions` (only items affecting confidence/scope).
End with `## Verification Reviewed` — files opened, runtime paths confirmed, and commands/harnesses **not** run. Anything seen only via grep must be named here and **not** counted as confirmed.

If nothing rises above `P3`, say so plainly. If no findings are confirmed, say that plainly and list residual risk.

## Verification commands (name what you did/didn't run)

This review reads code; it does not require a build. When a finding's confirmation would benefit from a run, prefer:

- `npm run build` — only if a finding depends on bundled output behavior.
- `npm run lint:js` / `composer lint:php` — for style/escaping findings.
- `npm run test:unit -- --runInBand <nearest suite>` / `composer test:php -- --filter <NameTest>` — for contract/stale-state/contrast findings.
- `npm run check:docs` — when prompt, contract, surfacing, operator, or contributor-facing docs changed.
- `npm run verify -- --skip-e2e` then inspect `output/verify/summary.json` — for shared-subsystem confidence (see the `verify-pipeline` skill).
- `npm run verify:strict` — when the docs-inclusive verifier should record optional docs checks.
- Playwright (`npm run test:e2e:playground` / `npm run test:e2e:wp70`) — only for user-visible regressions; record if not run.

State explicitly which of these were run versus skipped in `## Verification Reviewed`.

## Stop rules

Use the fewest useful search/read loops that still support a reliable review. After each evidence pass, ask: *can I now produce a useful, evidence-backed findings report?* If yes, stop and write it. Search again only when a required runtime path, owner, contract, line reference, or contradictory implementation detail is still missing.
