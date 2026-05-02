# Package Updates — Design

- **Date:** 2026-05-02
- **Surface:** Repository tooling (npm + composer dependencies)
- **Status:** Approved — pending implementation
- **Branch strategy:** Direct to `master`, one commit per stage

## Goal

Bring `package.json`, `composer.json`, and their lockfiles up to current versions safely. Each stage is independently revertible so a future regression can be bisected to a single dependency change.

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
- `squizlabs/php_codesniffer` 3 → 4 (composer dev dep).
- Lockfile updates (`package-lock.json`, `composer.lock`).
- Code adjustments **only when forced by a removal/rename** in an upgraded package. Adjustments stay minimal and live in the same commit as the bump that required them.

### Out of scope

- `engines.node`, `engines.npm`, or `composer.json` `platform.php` changes.
- "Modernization" refactors not forced by an upgrade (e.g. swapping working APIs for newer-but-equivalent ones).
- New dependencies; removal of existing ones.
- Changes to `flavor-agent.php` plugin headers (Requires/Tested versions).
- Documentation updates beyond what `npm run check:docs` flags.
- Plugin Check (`lint-plugin`) runs in the per-stage loop — requires WP-CLI + WP root that are not assumed present in this workflow. A final manual run is the user's call.

## Architecture

A linear sequence of eight commits on `master` (stage 0 is a verify-only baseline, no commit). Each commit:

1. Edits dependency manifest(s).
2. Updates lockfile via `npm ci` or `composer install` (or `npm update` for stage 1).
3. Includes any forced source-code adjustments.
4. Passes `node scripts/verify.js --skip=lint-plugin --skip-e2e` in isolation.
5. Has a commit message describing what bumped and what (if anything) needed adjusting.

```
Stage 0  baseline verify (no commit — establishes pre-existing state)
Stage 1  in-range minors (1 commit)
Stage 2  @playwright/test pinned bump (1 commit)
Stage 3  @wordpress/icons 10 → 13 (1 commit)
Stage 4  @wordpress/dataviews 13 → 14 + @wordpress/fields 0.35 → 0.37 (1 commit)
Stage 5  @wordpress/block-editor 14 → 15 + @wordpress/blocks 13 → 15 (1 commit)
Stage 6  @wordpress/components 28 → 33 (1 commit)
Stage 7  @wordpress/scripts 30 → 32 (1 commit)
Stage 8  squizlabs/php_codesniffer 3 → 4 (1 commit)
Final    full verify with E2E (no commit — sign-off step)
```

Order is risk-ascending: routine bumps validate the pipeline first; the largest deprecation surfaces (components, scripts, PHPCS) happen against an already-validated baseline so an unexpected red is attributable.

## Stage details

### Stage 0 — Baseline verify

Run `node scripts/verify.js --skip=lint-plugin --skip-e2e` against the current `master` (no changes). Record `output/verify/summary.json` `status`. Required: `pass` or known-good `incomplete` reason. If the baseline is already `fail`, **stop** — fix or surface to user before any upgrade work.

No commit.

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

### Stage 8 — PHPCS

`squizlabs/php_codesniffer` 3 → 4 (composer dev). Possible new findings against existing PHP. If WPCS 3.x is still pinned in composer and incompatible with PHPCS 4, we may need to either (a) hold PHPCS at 3 and document why, or (b) bump WPCS as well. Read changelogs first.

## Per-stage verification protocol

1. Apply the stage's manifest edits.
2. Reinstall: `npm ci` (stages 1–7) or `composer install` (stage 8).
3. Run `node scripts/verify.js --skip=lint-plugin --skip-e2e`.
4. Read `output/verify/summary.json`. Require `status: "pass"`.
5. For stages 5, 6, 7: spot-check the editor in a browser if the local Docker stack is running. Concretely, exercise each webpack entry point at least once — open the post editor and confirm the Inspector recommendations panel renders (`build/index.js`), open `Settings > Flavor Agent` and confirm the settings UI hydrates (`build/admin.js`), open `Settings > AI Activity` and confirm the DataViews app loads (`build/activity-log.js`). Type checks and unit tests don't catch UI regressions. If the stack is not running, document this gap in the commit message rather than skip silently.
6. Commit. Message format: `deps(<scope>): bump <pkg> <from> → <to>` plus a body line for any forced adjustments.

After stage 8: one full `npm run verify` (with E2E). If E2E was already red on the stage-0 baseline, the same E2E suite must remain no-worse-than-baseline; we don't fix pre-existing E2E failures as part of this work.

## Stop conditions

Halt the pipeline (do not proceed to the next stage, do not push past) when any of these occur:

- Verify reports `fail` or unexpected `incomplete`.
- A changelog or runtime error reveals an API removal that touches plugin code, **and** the adaptation is non-trivial (more than a rename or a single-line argument shape change). Surface to user; defer the stage.
- Build output (`build/*.js`) diff is unexplained.
- `npm ci` or `composer install` fails resolution.
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

- `package.json` and `composer.json` reflect the new versions for every in-scope package.
- `package-lock.json` and `composer.lock` updated.
- Each stage commit passes `node scripts/verify.js --skip=lint-plugin --skip-e2e` in isolation.
- Final full `npm run verify` (with E2E) passes, **or** matches the stage-0 baseline if E2E was already red there.
- No source-code change beyond what an upgrade forced.
- Commit history allows `git bisect` to attribute any future regression to a single stage.

## Artifact locations

- `output/verify/summary.json` and `output/verify/<step>.{stdout,stderr}.log` per verify run (gitignored).
- Working files: `package.json`, `package-lock.json`, `composer.json`, `composer.lock`, plus any forced source edits.

## Estimated cost

- Stages 0–4: ~10 min each end-to-end, low chance of code adjustment.
- Stage 5: probable 1–3 file adjustments; ~30 min.
- Stage 6: highest-variance — could be 30 min or could be the multi-hour stage that triggers the stop condition.
- Stage 7: build-config adjustment risk; ~30 min.
- Stage 8: lint-fix risk; ~30 min.

If stage 6 or 8 hits a stop condition, the partial work (stages 1–5 and/or 7) still ships as completed commits and the deferred stage(s) become a separate brainstorm.
