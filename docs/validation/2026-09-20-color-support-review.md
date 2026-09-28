# Color Support Review Verification — 2026-09-20

This records local verification of the uncommitted work on `master` above `f7d3b2d`. It covers adapted-pattern preview states and diagnostics, the shared client color-support predicate, and the matching server Style Book allowlist. It is not a release record.

## Changes and regression evidence

- Pattern adaptation and the client Style Book contract use `src/utils/block-color-support.js`. The server allowlist in `StyleAbilities` follows the same WordPress defaults.
- Text/background are enabled when color support is declared, including an empty object. Missing color support, explicit block/theme opt-outs, and missing theme palettes still keep the corresponding Style Book paths unavailable.
- New JavaScript cases failed before the client fix (10 failures), and new PHP cases failed before the server fix (7 failures). Both passed after their respective fixes.
- A live WordPress call to `StyleAbilities::supported_style_paths_for_block()` with the registered `core/paragraph` supports returned both color paths.
- Canonical pattern and style surface docs, the adapted-preview design, and `STATUS.md` now describe the shipped behavior.

## Checks

| Check | Result |
| --- | --- |
| Targeted JavaScript suites: theme tokens, style operations, pattern adaptation/preview/recommender, Style Book, Global Styles | 7 suites, 235 tests passed |
| Targeted PHP: `ThemeTokenCollectorTest`, `StyleApplyExecutorTest`, `StyleAbilitiesTest`, `StylePromptTest`, `EditorSurfaceCapabilitiesTest` | 166 tests, 600 assertions passed |
| `COMPOSE_PROJECT_NAME=flavor-agent-wp70 PLUGIN_CHECK_USE_DOCKER=1 node scripts/verify.js --skip-e2e` | Pass: all 6 included steps; build, JS lint, Plugin Check, unit, PHP lint, PHP tests |
| Full JavaScript unit suite | 117 suites, 1,984 tests passed |
| Full PHPUnit suite | 2,361 tests, 11,103 assertions passed |
| `npm run check:docs` | Passed with real ripgrep; no shim |
| Playground `--grep 'pattern surface'` | 7 passed, 1 failed at native catalog hydration; isolated retry of that case passed |
| Docker `--grep 'style book\|global styles surface'` | Existing 6 style cases and authentication passed; new apply/undo fixture corrected and rerun separately |
| Docker `--grep 'style book applies and undoes'` after fixture correction | 2 passed, including authentication; confirms default paragraph support, review, text/background apply, Activity, and undo |
| Changed E2E file ESLint and `git diff --check` | Passed |

The Playground retry used `--grep 'pattern surface inserts a recommended pattern at the top-level root' --output=output/playwright-pattern-retry`. The initial failure was an empty native allowed-pattern catalog before a recommendation request. The retry passed without product changes. This remains an observed harness flake, not a clean first-pass browser result.

The new Style Book test required its full accessible Review button name and explicit `blockName` fields in its mocked operations. Its final fixture uses the theme's contrasting ink/paper presets. All 8 selected pattern cases and all 7 selected style cases have passing evidence across these runs; the complete browser suites and bundled-editor leg were not rerun.

## Runtime and artifacts

Docker used WordPress 7.1 with Gutenberg 23.9.0 active, AI 1.3.0, and Plugin Check 2.1.0. The DHI MariaDB default returned an anonymous registry 401. The documented `MARIADB_IMAGE=mariadb:11.4` fallback provisioned the isolated harness successfully. Subsequent targeted runs used `FLAVOR_AGENT_WP70_RESET=0`.

Plugin Check initially lacked a host runtime. After harness provisioning, `docker exec flavor-agent-wp70-wordpress-1 wp plugin install plugin-check --activate --allow-root` enabled Docker-backed verification; the final Plugin Check reported no errors. The build retained its three webpack performance warnings. The temporary Docker harness was torn down after verification.

The aggregate report is `output/verify/summary.json` (`status: pass`, 6 passed, 0 failed, 3 intentionally excluded). Docs and browser checks ran separately. Copies of the summary and run logs are under `output/review-color-support/`, including `verify.log`, `playground-initial.log`, `pattern-retry.log`, `styles-final-batch.log`, and `style-book-final.log`.

## Follow-up — 2026-09-24

Review of this work found two adaptation defects, now fixed:

- Color roles matched any role word inside a palette name. Twenty Twenty-Four's **Accent / Two**, **Base / Two**, and **Contrast / Two** made `primary`, `foreground`, and `background` ambiguous, and names such as core's **Light green cyan** or a **Primary Dark** variant claimed the `light` and `dark` roles. Roles now match only explicit labels: an exact slug or display name, or a parenthetical label such as **Evergreen (brand)**.
- A zero-change preview reported **No changes needed** even when the pattern used presets outside the color and spacing rules. The engine now reports missing font size, font family, gradient, border color, Cover/Navigation/Social Icons color, and `style` preset references as `unresolved_theme_preset`, which blocks a zero-change result.

| Check | Result |
| --- | --- |
| New adaptation tests against the pre-fix engine | 19 of the new cases failed before the fix; all 59 adaptation tests pass after |
| `node scripts/verify.js --strict --skip-e2e --output=output/review-color-support-followup` | `incomplete`: build, JS lint, unit (117 suites, 2,016 tests), PHP lint, docs check, and PHP tests (2,361 tests) passed; Plugin Check did not run because no WordPress root or `WP_PLUGIN_CHECK_PATH` was available |
| Playground `--grep 'pattern surface' --output=output/playwright-color-support-followup` | 8 passed on the first run, including the catalog-hydration case that needed a retry above |

PHP tests ran with `date.timezone=UTC`. Under this host's `Europe/Berlin` PHP default, three `ActivitySerializerTest` cases fail on timestamp formatting; that suite is unaffected by this work. The Docker Site Editor harness and bundled-editor leg were not rerun because this follow-up changes only the pattern surface.

## Follow-up — 2026-09-28

The September 27–28 review reproduced and fixed two further issues:

- Border preset diagnostics checked only `supports.border.color`, while core Group declares `supports.__experimentalBorder.color`. Both keys now work, with explicit opt-outs and unsupported attributes covered by regression tests.
- Adaptation checked every block against only global presets. The token collector now accepts an optional block type and includes that type's presets alongside inherited globals. Each scope resolves default, theme, and custom origins before block presets override matching global slugs. The resolver covers palette, gradient, duotone, font size, font family, spacing, and shadow presets, honors block-specific color opt-outs, and retains experimental block settings when the stable source has only global parity. Each nested block uses its own type's settings. Global-only callers keep their existing behavior.

Pattern color/spacing adjustments and missing-preset diagnostics share the resolved tokens. The per-type cache lasts for one preview build; insertion revalidation collects the current settings again, and a changed mapping blocks stale adapted insertion. Browser coverage verifies that a missing core Group border preset produces its diagnostic while a valid paragraph-only font preset renders at 32px, remains unchanged, and inserts with its attributes intact.

| Check | Result |
| --- | --- |
| Regression tests before the product fix | Collector/adaptation run reproduced 14 failures; the new recommender integration cases reproduced 3 failures |
| Targeted JavaScript: theme tokens, pattern adaptation, pattern recommender | 3 suites, 192 tests passed |
| Targeted PHP: `ThemeTokenCollectorTest`, `StyleApplyExecutorTest`, `StyleAbilitiesTest`, `StylePromptTest`, `EditorSurfaceCapabilitiesTest` | 166 tests, 600 assertions passed |
| `node scripts/verify.js --strict --skip-e2e --output=output/verify-preset-fix-2026-09-27` with Docker Plugin Check and a temporary UTC PHP configuration | Pass: all 7 included steps; build, JS lint, Plugin Check, unit, PHP lint, docs, PHP tests |
| Full JavaScript unit suite | 117 suites, 2,037 tests passed |
| Full PHPUnit suite | 2,361 tests, 11,103 assertions passed |
| Playground `--grep 'pattern surface' --output=output/playwright-preset-fix` | 10 passed on the first run, including both new regressions and insertion rollback |
| Docker `--grep 'style book\|global styles surface' --output=output/playwright-preset-fix-styles` | 8 passed on the first run: 7 style cases plus authentication |
| Changed E2E file ESLint and `git diff --check` | Passed |

The aggregate report is `output/verify-preset-fix-2026-09-27/summary.json`: `status: pass`, 7 passed, 0 failed, 2 explicitly excluded browser steps. Browser runs used the separate commands above. The report directory retains each aggregate step's stdout/stderr. The full browser suites and WordPress bundled-editor leg were not rerun; this is targeted regression evidence, not a release qualification.

Docker verification used the isolated `flavor-agent-preset-review` compose project on port 19404, WordPress 7.1, Gutenberg 23.9.0, and Plugin Check 2.1.0, with `MARIADB_IMAGE=mariadb:11.4`. Plugin Check reported no errors. The build retained its three webpack performance warnings. PHP tests used an isolated temporary `date.timezone=UTC` override without changing host configuration. The temporary Docker project was torn down after verification.

## Scoped settings and inherited colors — 2026-09-28

The next review found three issues in the uncommitted work, all corrected in this follow-up:

- Style Book checked global theme controls even when `settings.blocks[blockName]` disabled the target block's text or background control. Client and server now resolve the target block's controls and presets for recommendation allowlists and apply validation. Block opt-ins, global preset inheritance, per-origin precedence, cache isolation, and unrelated block scopes have regression coverage.
- Pattern diagnostics rejected valid CSS preset variables inherited from a Group ancestor. The traversal now carries CSS variable availability down each ancestor branch, preserves inherited spacing values, and keeps sibling branches, preset classes, and control permissions separate. Coverage includes color, gradient, font size, font family, spacing, and shadow variables; duotone SVG filters do not inherit as CSS variables.
- Pattern diagnostics compared CSS identifiers to raw preset slugs. The engine now uses the bundled `@wordpress/kebab-case` helper to recognize generated CSS names such as `brand-blue` for `brandBlue`. Literal CSS variable names remain case-sensitive; encoded style presets and raw preset attributes retain their original serialization. The dependency is pinned to the already-installed and locked version, `1.1.0`.

Contrast validation also uses the scoped palette for block operations and explicit block colors. Independent review caught a further edge case during implementation: a missing block color inherited from the root must still use the global palette, even if the block overrides the same slug. The client and server now carry both token scopes. Tests cover rejecting unreadable pairs and allowing readable pairs for text and background, explicit block complements, and root fallbacks. Both server freshness signatures include global tokens so a global change hidden by a block override invalidates the result; request-supplied token maps cannot replace server-collected tokens.

Regression tests reproduced 7 client Style Book failures and 13 pattern failures before the initial fixes, plus 20 server failures. The inherited-root contrast correction reproduced 4 further client failures and 13 server failures before its fix. An independent final review reproduced the original unsafe apply as a contrast rejection with zero writes and reported no remaining findings in the reviewed scope.

| Check | Result |
| --- | --- |
| Targeted JavaScript: theme tokens, style operations, pattern adaptation/preview/recommender, Style Book, Global Styles | 7 suites, 318 tests passed |
| Targeted PHP: `ThemeTokenCollectorTest`, `StyleApplyExecutorTest`, `StyleAbilitiesTest`, `StyleContrastValidatorTest`, `StylePromptTest` | 256 tests, 775 assertions passed |
| `node scripts/verify.js --strict --skip-e2e --output=output/verify-review-findings-final-2026-09-28` with Docker Plugin Check and isolated UTC PHP settings | All 7 included steps passed: build, JS lint, Plugin Check, unit, PHP lint, docs, PHP tests |
| Full JavaScript unit suite | 117 suites, 2,067 tests passed |
| Full PHPUnit suite | 2,398 tests, 11,238 assertions passed |
| Playground `--grep 'pattern surface' --output=output/playwright-review-findings-patterns` | 12 passed on the first run, including both new inherited/normalized preset regressions |
| Docker Gutenberg `--grep 'style book\|global styles surface' --output=output/playwright-review-findings-styles` | 12 passed on the first run: 11 style cases plus authentication |
| Docker bundled editor, same grep, `--output=output/playwright-review-findings-styles-bundled` | 12 passed on the first run: the same 11 style cases plus authentication, with Gutenberg confirmed inactive |
| Changed E2E file syntax/ESLint and `git diff --check` | Passed |

The final aggregate summary is `output/verify-review-findings-final-2026-09-28/summary.json`: `status: pass`, 7 passed, 0 failed, and the 2 browser steps explicitly excluded for separate targeted runs. Logs and screenshots are retained under the corresponding `output/` directories; command-level logs are in `output/verify-review-findings-2026-09-28/`. Plugin Check reported no errors. The build retained the three existing webpack performance warnings. PHP used an isolated `date.timezone=UTC` override without changing host configuration.

Docker used the isolated `flavor-agent-findings-20260928` compose project on ports 19414/19415, WordPress 7.1, Gutenberg 23.9.0, and Plugin Check 2.1.0, with the documented MariaDB 11.4 fallback. After the Gutenberg run, Gutenberg was explicitly deactivated before the bundled-editor run; `FLAVOR_AGENT_WP70_RESET=0` reused only this temporary project. Browser assertions cover rendered pattern colors, preserved inserted attributes, refusing disabled controls without writes or activity, inherited-root contrast rejection, and existing apply/undo and stale-result behavior. These are targeted regression runs; the complete browser suites were not rerun and this is not a release qualification.

The temporary Docker project was torn down after verification. No commits, pushes, or deployments were made during this verification pass.
