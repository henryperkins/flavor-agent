# Package Updates — Design

- **Date:** 2026-05-02
- **Surface:** Repository tooling (npm + composer dependencies)
- **Status:** Approved — pending implementation
- **Branch strategy:** Direct to `master`, one commit per stage

## Goal

Bring `package.json` and `package-lock.json` up to current versions safely. The composer side was in scope but its only outdated package (`squizlabs/php_codesniffer` 3 → 4) is upstream-blocked by `wp-coding-standards/wpcs` (see Deferred), so composer manifests stay unchanged. Each npm stage is independently revertible so a future regression can be bisected to a single dependency change.

This is silent infrastructure — there is no new product surface, no contract change, and no user-visible behavior change beyond what the upstream packages themselves introduce.

## Scope

### In scope

- All 11 npm packages within current `^` semver range (auto-applied by `npm update`).
- `@playwright/test` 1.58.2 → 1.59.1 (pinned-exact in `package.json`, so semver ranges miss it).
- WordPress major bumps where the current pin is older than the plugin's declared `Requires WP 7.0+`:
  - `@wordpress/icons` 10 → 13
  - `@wordpress/dataviews` 13 → 14, `@wordpress/fields` 0.35 → 0.37
  - `@wordpress/block-editor` 14 → 15, `@wordpress/blocks` 13 → 15
  - `@wordpress/components` 28 → 33
- `@wordpress/scripts` 30 → 32 (build toolchain).
- `package-lock.json` updates (composer.lock unchanged — see Deferred).
- Code adjustments **only when forced by a removal/rename** in an upgraded package. Adjustments stay minimal and live in the same commit as the bump that required them.

### Out of scope

- `engines.node`, `engines.npm`, or `composer.json` `platform.php` changes.
- "Modernization" refactors not forced by an upgrade (e.g. swapping working APIs for newer-but-equivalent ones).
- New dependencies; removal of existing ones.
- Changes to `flavor-agent.php` plugin headers (Requires/Tested versions).
- Documentation updates beyond what `npm run check:docs` flags.
- Plugin Check (`lint-plugin`) runs in the per-stage loop — requires WP-CLI + WP root that are not assumed present in this workflow. A final manual run is the user's call.

### Deferred (upstream-blocked)

- `squizlabs/php_codesniffer` 3 → 4. Blocked: `wp-coding-standards/wpcs` 3.3.0 (the latest released version) requires `squizlabs/php_codesniffer:^3.13.4`. There is no WPCS release compatible with PHPCS 4 as of 2026-05-02. Bumping PHPCS alone fails composer resolution; bumping WPCS to a non-existent version is impossible. Revisit when WPCS publishes a PHPCS 4-compatible release.

## Architecture

A linear sequence of seven commits on `master` (stage 0 is a verify-only baseline, no commit; stage 8 is deferred per the Scope section). Each commit:

1. Edits dependency manifest(s) and updates the lockfile in the same step (commands per stage below).
2. Includes any forced source-code adjustments.
3. Passes `node scripts/verify.js --skip=lint-plugin --skip-e2e` in isolation.
4. Has a commit message describing what bumped and what (if anything) needed adjusting.

```
Stage 0  baseline verify with E2E (no commit — establishes pre-existing state)
Stage 1  in-range minors (1 commit)
Stage 2  @playwright/test pinned bump (1 commit)
Stage 3  @wordpress/icons 10 → 13 (1 commit)
Stage 4  @wordpress/dataviews 13 → 14 + @wordpress/fields 0.35 → 0.37 (1 commit)
Stage 5  @wordpress/block-editor 14 → 15 + @wordpress/blocks 13 → 15 (1 commit)
Stage 6  @wordpress/components 28 → 33 (1 commit)
Stage 7  @wordpress/scripts 30 → 32 (1 commit)
Stage 8  squizlabs/php_codesniffer 3 → 4 — DEFERRED (upstream-blocked)
Final    full verify with E2E (no commit — sign-off step)
```

Order is risk-ascending: routine bumps validate the pipeline first; the largest deprecation surfaces (components, scripts) happen against an already-validated baseline so an unexpected red is attributable.

### Lockfile-update commands by stage type

`npm ci` and `composer install` **read** the lockfile and refuse to run when the manifest disagrees — they cannot update it. Use these instead:

| Stage type | Command | Why |
|---|---|---|
| In-range bumps (stage 1) | `npm update` | Walks all `^`/`~` ranges to latest matching version, writes lockfile. No manifest edit needed if the range already covers the target. |
| Pinned-exact bump (stage 2) | `npm install --save-exact @playwright/test@1.59.1` | Writes the exact pin to `package.json` and updates `package-lock.json` atomically. |
| Major bump per package (stages 3–7) | `npm install <pkg>@<version>` (one invocation per package, or one combined invocation for paired stages 4 and 5) | Writes the new caret range to `package.json` and updates `package-lock.json`. |
| Composer dep bump (stage 8 — deferred) | `composer require --dev <pkg>:<constraint>` (would update both `composer.json` and `composer.lock`) | Documented for completeness even though stage 8 is deferred. |

After any of the above, run `npm ci` (or `composer install`) once more **only** as a verification — it should now succeed cleanly because the manifest and lock are back in sync.

## Stage details

### Stage 0 — Baseline verify (with E2E)

Run **two** verifies against the current `master` (no changes):

1. `node scripts/verify.js --skip=lint-plugin --skip-e2e` — fast verify; copy `output/verify/summary.json` to `output/verify/baseline-fast.json`.
2. `node scripts/verify.js --skip=lint-plugin` — full verify including Playwright E2E suites; copy `output/verify/summary.json` to `output/verify/baseline-full.json`.

Also capture a build-artifact manifest: `mkdir -p output/verify && (cd build && find . -type f \( -name '*.js' -o -name '*.asset.php' -o -name '*.css' \) -print0 | xargs -0 sha256sum) | sort > output/verify/baseline-build.sha256` (after running `npm run build` if `build/` is empty).

Required: fast verify is `pass` or `incomplete` for documented reason. If fast verify is `fail`, **stop** — fix or surface to user before any upgrade work. The full E2E baseline is allowed to be partially red; we record it as the bar the final verify must match-or-beat.

No commit; baselines live under `output/verify/` (gitignored) and persist for the rest of this work.

### Stage 1 — In-range minors

```
@base-ui/react           1.3.0  → 1.4.1
@wordpress/api-fetch     7.43.0 → 7.45.0
@wordpress/compose       7.43.0 → 7.45.0
@wordpress/data         10.43.0 → 10.45.0
@wordpress/editor       14.42.0 → 14.45.0
@wordpress/element       6.43.0 → 6.45.0
@wordpress/hooks         4.43.0 → 4.45.0
@wordpress/plugins       7.42.0 → 7.45.0
@wordpress/rich-text     7.43.0 → 7.45.0
@wordpress/views         1.10.0 → 1.12.0
repomix                  1.13.1 → 1.14.0
```

Mechanism: `npm update` (respects existing `^` ranges, no `package.json` edits except possibly the one-line `repomix` semver-range bump if npm rewrites it). Lockfile updates.

### Stage 2 — Pinned tooling

`@playwright/test` 1.58.2 → 1.59.1. Pinned-exact in `package.json` — edit the version string directly, no `^` introduced.

### Stage 3 — Icons

`@wordpress/icons` 10 → 13. Three majors but historically additive; main risk is renamed/removed icon exports. Grep `src/` for `from '@wordpress/icons'` imports and verify each named import still resolves.

### Stage 4 — DataViews + Fields

`@wordpress/dataviews` 13 → 14, `@wordpress/fields` 0.35 → 0.37. Used together by `src/admin/activity-log.js`. Paired so the activity-log admin app is re-exercised once. `@wordpress/fields` is pre-1.0 — read changelog for both before bumping.

### Stage 5 — Editor core

`@wordpress/block-editor` 14 → 15, `@wordpress/blocks` 13 → 15. Highest-risk APIs the plugin uses:

- `editor.BlockEdit` filter via `createHigherOrderComponent` (`src/inspector/InspectorInjector.js`)
- `<InspectorControls group="...">` for tab-targeted panels (block, settings, styles, color, typography, dimensions, border)
- Experimental APIs in `src/patterns/compat.js`, `src/context/theme-tokens.js`, `src/context/block-inspector.js` (the `__experimental*` and `__experimentalAdditional*` keys these modules adapt are exactly what changes between block-editor majors)

If `__experimental*` keys were renamed or promoted to stable, `src/patterns/compat.js`'s three-tier resolver should already handle the stable case — but the experimental fallbacks may need updating.

### Stage 6 — Components

`@wordpress/components` 28 → 33. Five majors — the most likely place for forced code adjustments. Surface to audit:

- `ToolsPanel` / `ToolsPanelItem` — central to Inspector layout (chips use `grid-column: 1 / -1` per `CLAUDE.md` gotcha)
- `PanelBody`, form controls used in `src/inspector/`, `src/templates/`, `src/template-parts/`, `src/admin/settings-page-controller.js`
- Any `__experimental*` component imports

If components 33 has removed APIs the plugin uses, **stop and surface** rather than rewrite in-flight (per the "stop conditions" section below).

### Stage 7 — Build toolchain

`@wordpress/scripts` 30 → 32. Two majors. Ripples through:

- Webpack config (build output may shift — diff `build/` artifacts)
- Jest config (test discovery, transforms)
- ESLint config (new rules may flag existing code — fix or disable explicitly per rule, never wholesale)
- The three webpack entry points (`src/index.js`, `src/admin/settings-page.js`, `src/admin/activity-log.js`) must continue to produce `build/index.js`, `build/admin.js`, `build/activity-log.js`

### Stage 8 — PHPCS (deferred)

`squizlabs/php_codesniffer` 3 → 4 is **deferred**. `composer.lock` shows `wp-coding-standards/wpcs@3.3.0` (the latest released WPCS) requires `squizlabs/php_codesniffer:^3.13.4`. There is no WPCS release compatible with PHPCS 4, so `composer require --dev squizlabs/php_codesniffer:^4.0` would fail resolution. No code edits, no commit, no verify run for this stage. Revisit when WPCS publishes a PHPCS 4-compatible version (track upstream at `WordPress/WordPress-Coding-Standards`).

## Per-stage verification protocol

1. Apply the stage's manifest+lockfile change using the matching command from "Lockfile-update commands by stage type" above.
2. Sanity-check that the lockfile and manifest agree by running `npm ci` (or `composer install` for any composer stage) — this should succeed without modifying anything. If it fails, the lockfile update step was wrong; **stop**.
3. Run `node scripts/verify.js --skip=lint-plugin --skip-e2e`.
4. Read `output/verify/summary.json`. Require `status: "pass"`.
5. Capture a post-stage build manifest: `(cd build && find . -type f \( -name '*.js' -o -name '*.asset.php' -o -name '*.css' \) -print0 | xargs -0 sha256sum) | sort > output/verify/stage-<N>-build.sha256`. Compare against the previous stage's manifest with `diff`. Expected change profile per stage type:
   - Stages 1, 2, 3, 4, 8: minimal or no diff — chunk hashes for unrelated entry points should not move. Unexplained churn → investigate before committing.
   - Stages 5, 6: diffs scoped to chunks that include the upgraded packages are expected.
   - Stage 7: large diff is expected (new build toolchain). Record the diff in the commit message body but do not block on it.
6. For stages 5, 6, 7: spot-check the editor in a browser if the local Docker stack is running. Concretely, exercise each webpack entry point at least once — open the post editor and confirm the Inspector recommendations panel renders (`build/index.js`), open `Settings > Flavor Agent` and confirm the settings UI hydrates (`build/admin.js`), open `Settings > AI Activity` and confirm the DataViews app loads (`build/activity-log.js`). Type checks and unit tests don't catch UI regressions. If the stack is not running, document this gap in the commit message rather than skip silently.
7. Commit. Message format: `deps(<scope>): bump <pkg> <from> → <to>` plus a body line for any forced adjustments.

After stage 7: one full `node scripts/verify.js --skip=lint-plugin` (with E2E). Compare its summary against `output/verify/baseline-full.json` from stage 0. Per-suite E2E pass counts must be `>=` baseline; new failures (suites that passed at baseline and now fail) are blocking. Pre-existing failures that remain failing are not blocking — we are not fixing pre-existing E2E breakage as part of this work.

## Stop conditions

Halt the pipeline (do not proceed to the next stage, do not push past) when any of these occur:

- Verify reports `fail` or unexpected `incomplete`.
- A changelog or runtime error reveals an API removal that touches plugin code, **and** the adaptation is non-trivial (more than a rename or a single-line argument shape change). Surface to user; defer the stage.
- Build manifest diff (per the SHA256 capture in verification step 5) shows churn outside the expected profile for the stage type — e.g. an icons-only stage moves a `build/admin.js` chunk hash.
- `npm install <pkg>@<version>` (or composer equivalent) fails resolution — stop and report the conflict; do not retry with `--legacy-peer-deps` or `--force`.
- Post-update sanity `npm ci` / `composer install` fails — means the lockfile update was incomplete.
- New ESLint or PHPCS findings against existing code that aren't trivially fixable.

When halted: revert the in-progress stage's working changes (do not commit a broken state), document the blocker, hand back to user.

## Explicitly not allowed

- `--no-verify` to bypass commit hooks.
- `git commit --amend` on a previously committed stage.
- `git push --force` on `master`.
- Bundling multiple stages in one commit.
- Skipping stage 0 (baseline verify).
- "While I'm here" refactors unrelated to the bump.

## Success criteria

- `package.json` reflects the new versions for every in-scope npm package.
- `package-lock.json` updated.
- `composer.json` / `composer.lock` unchanged (stage 8 deferred upstream).
- Each stage commit passes `node scripts/verify.js --skip=lint-plugin --skip-e2e` in isolation.
- Final full `node scripts/verify.js --skip=lint-plugin` (with E2E) shows per-suite pass counts `>=` `output/verify/baseline-full.json` from stage 0. New E2E failures (passed at baseline → fail now) are blocking; same-as-baseline failures are not.
- No source-code change beyond what an upgrade forced.
- Commit history allows `git bisect` to attribute any future regression to a single stage.

## Artifact locations

- `output/verify/baseline-fast.json`, `output/verify/baseline-full.json`, `output/verify/baseline-build.sha256` — stage 0 baselines, kept for the duration of the work.
- `output/verify/stage-<N>-build.sha256` — per-stage build manifests for diff comparisons.
- `output/verify/summary.json` and `output/verify/<step>.{stdout,stderr}.log` — per verify run, overwritten each invocation (gitignored).
- Working files: `package.json`, `package-lock.json`, plus any forced source edits.

## Estimated cost

- Stage 0: ~15 min (two verifies + build hash capture).
- Stages 1–4: ~10 min each end-to-end, low chance of code adjustment.
- Stage 5: probable 1–3 file adjustments; ~30 min.
- Stage 6: highest-variance — could be 30 min or could be the multi-hour stage that triggers the stop condition.
- Stage 7: build-config adjustment risk; ~30 min.
- Stage 8: 0 min (deferred — no work, just documented in this spec).

If stage 6 hits a stop condition, the partial work (stages 1–5 and/or 7) still ships as completed commits and the deferred stage becomes a separate brainstorm.
