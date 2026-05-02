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

- 11 npm packages within current `^` semver range, applied via per-package `npm install <pkg>@<exact-version>` (not `npm update` — see "Mechanism choice" below).
- `@playwright/test` 1.58.2 → 1.59.1 (pinned-exact in `package.json`, so semver ranges miss it).
- WordPress major bumps aligned to the plugin's declared `Requires WP 7.0+`. These are **dep-coupled** and must land in a single commit (peer-dep coupling documented under Stage 3):
  - `@wordpress/components` 28 → 33
  - `@wordpress/icons` 10 → 13
  - `@wordpress/blocks` 13 → 15
  - `@wordpress/block-editor` 14 → 15
  - `@wordpress/dataviews` 13 → 14
  - `@wordpress/fields` 0.35 → 0.37
- `@wordpress/scripts` 30 → 32 (build toolchain — semantically independent of the WP API surface, so a separate stage).
- `package-lock.json` updates (composer.lock unchanged — see Deferred).
- Code adjustments **only when forced by a removal/rename** in an upgraded package. Adjustments stay minimal and live in the same commit as the bump that required them.

### Mechanism choice — why not `npm update`

`npm update --dry-run` against this lockfile does not just walk in-range minors — it pulls hundreds of transitive entries including `@wordpress/components@33`, `@wordpress/dataviews@14.2`, `@wordpress/block-editor@15.18`, `@wordpress/icons@13`. That collapses the staging model: stage 1's "low risk in-range only" guarantee fails, and the build diff for stage 1 would be huge. So this spec uses targeted per-package `npm install <pkg>@<exact-version>` invocations everywhere — every changed line in `package-lock.json` is then traceable to a deliberate request, and unexpected transitive churn becomes a stop signal rather than silent.

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

A linear sequence of four commits on `master` (stage 0 is a verify-only baseline, no commit; stage 5 is deferred per the Scope section). Each commit:

1. Edits dependency manifest(s) and updates the lockfile in the same step (commands per stage below).
2. Includes any forced source-code adjustments.
3. Passes `node scripts/verify.js --skip=lint-plugin --skip-e2e` in isolation.
4. Has a commit message describing what bumped and what (if anything) needed adjusting.

```
Stage 0  baseline verify with E2E + lockfile size capture (no commit)
Stage 1  named in-range bumps (11 packages) (1 commit)
Stage 2  @playwright/test 1.58.2 → 1.59.1 (1 commit)
Stage 3  WordPress 7.0 alignment — single commit covering all six coupled
         WP majors: components 28→33, icons 10→13, blocks 13→15,
         block-editor 14→15, dataviews 13→14, fields 0.35→0.37 (1 commit)
Stage 4  @wordpress/scripts 30 → 32 (build toolchain) (1 commit)
Stage 5  squizlabs/php_codesniffer 3 → 4 — DEFERRED (upstream-blocked)
Final    full verify with E2E (no commit — sign-off step)
```

Order is risk-ascending: routine bumps validate the pipeline first; the high-variance WP alignment lands once everything else is green so any failure is attributable. The previous design's per-WP-major staging was abandoned because the target packages have peer-dep coupling that npm resolves atomically (see Stage 3) — separate commits would be theatre, not safety.

### Lockfile-update commands by stage type

`npm ci` and `composer install` **read** the lockfile and refuse to run when the manifest disagrees — they cannot update it. Use these instead:

| Stage | Command | Why |
|---|---|---|
| Stage 1 (in-range bumps) | One `npm install` invocation listing all 11 packages with exact targets: `npm install @base-ui/react@1.4.1 @wordpress/api-fetch@7.45.0 @wordpress/compose@7.45.0 @wordpress/data@10.45.0 @wordpress/editor@14.45.0 @wordpress/element@6.45.0 @wordpress/hooks@4.45.0 @wordpress/plugins@7.45.0 @wordpress/rich-text@7.45.0 @wordpress/views@1.12.0 repomix@1.14.0` | Targeted per-package install keeps lockfile changes attributable. `npm update` is **not** used (see "Mechanism choice" in Scope). |
| Stage 2 (pinned-exact bump) | `npm install --save-exact @playwright/test@1.59.1` | Writes the exact pin to `package.json` and updates `package-lock.json` atomically. |
| Stage 3 (WP 7.0 alignment) | One combined invocation: `npm install @wordpress/components@33.0.0 @wordpress/icons@13.0.0 @wordpress/blocks@15.18.0 @wordpress/block-editor@15.18.0 @wordpress/dataviews@14.2.0 @wordpress/fields@0.37.0` | Peer-dep coupling forces them together — `dataviews@14` requires `components@^33` + `icons@^13`; `fields@0.37` requires `blocks@^15.18` + `components@^33`; `block-editor@15` requires `components@^33`. A single invocation lets npm's resolver place all six in one consistent state. |
| Stage 4 (build toolchain) | `npm install @wordpress/scripts@32.1.0` | Independent of the WP API surface (build/test tooling only). |
| Stage 5 (composer — deferred) | `composer require --dev <pkg>:<constraint>` (would update both `composer.json` and `composer.lock`) | Documented for completeness even though stage 5 is deferred. |

After any of the above, run `npm ci` (or `composer install`) once more **only** as a verification — it should now succeed cleanly because the manifest and lock are back in sync.

## Stage details

### Stage 0 — Baseline verify (with E2E)

Run **two** verifies against the current `master` (no changes):

1. `node scripts/verify.js --skip=lint-plugin --skip-e2e` — fast verify; copy `output/verify/summary.json` to `output/verify/baseline-fast.json`.
2. `node scripts/verify.js --skip=lint-plugin` — full verify including Playwright E2E suites; copy `output/verify/summary.json` to `output/verify/baseline-full.json`.

Also capture:

- **Build manifest** (after running `npm run build` if `build/` is empty): `mkdir -p output/verify && (cd build && find . -type f \( -name '*.js' -o -name '*.asset.php' -o -name '*.css' \) -print0 | xargs -0 sha256sum) | sort > output/verify/baseline-build.sha256`.
- **Lockfile size + entry count**: `wc -l package-lock.json > output/verify/baseline-lockfile.txt` and `node -e "const lf = require('./package-lock.json'); console.log('packages:', Object.keys(lf.packages).length)" >> output/verify/baseline-lockfile.txt`. This gives a quick signal for unexpected transitive churn in later stages — Stage 1 should add ~11 entries, Stage 3 may rewrite many but the net delta should be predictable.

Required: fast verify is `pass` or `incomplete` for documented reason. If fast verify is `fail`, **stop** — fix or surface to user before any upgrade work. The full E2E baseline is allowed to be partially red; we record it as the bar the final verify must match-or-beat.

No commit; baselines live under `output/verify/` (gitignored) and persist for the rest of this work.

### Stage 1 — Named in-range bumps

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

Mechanism: single `npm install` invocation listing all 11 targets at exact versions (see Lockfile-update commands table). After install, `git diff package-lock.json | wc -l` should be in the low hundreds (these are minor bumps with small dep graphs); a multi-thousand-line diff suggests transitive churn into WP majors and trips the stop condition.

### Stage 2 — Pinned tooling

`@playwright/test` 1.58.2 → 1.59.1. Run `npm install --save-exact @playwright/test@1.59.1` — this preserves the exact pin (no `^` introduced) and updates the lockfile in one step. Do not hand-edit `package.json`; that would leave `package-lock.json` stale and break the post-update sanity `npm ci` in verification step 2.

### Stage 3 — WordPress 7.0 alignment

Six packages bumped together because their peer dependencies couple them:

- `@wordpress/components` 28 → 33 — required by every other package in this stage.
- `@wordpress/icons` 10 → 13 — required by `dataviews@14` (`peer: ^13`).
- `@wordpress/blocks` 13 → 15 — required by `fields@0.37` (`peer: ^15.18`).
- `@wordpress/block-editor` 14 → 15 — requires `components@^33`.
- `@wordpress/dataviews` 13 → 14 — requires `components@^33` and `icons@^13`.
- `@wordpress/fields` 0.35 → 0.37 — requires `blocks@^15.18` and `components@^33`. Pre-1.0; read changelog.

Mechanism: single combined `npm install` invocation per the table above. Splitting them across commits is not bisectable in practice because npm's resolver places them atomically — separate commits would either fail resolution or pull the others as transitive updates anyway.

Surface to audit (forced code adjustments expected here, not in earlier stages):

- `editor.BlockEdit` filter via `createHigherOrderComponent` (`src/inspector/InspectorInjector.js`)
- `<InspectorControls group="...">` for tab-targeted panels (block, settings, styles, color, typography, dimensions, border)
- Experimental block-editor APIs in `src/patterns/compat.js`, `src/context/theme-tokens.js`, `src/context/block-inspector.js` (the `__experimental*` and `__experimentalAdditional*` keys these modules adapt). `src/patterns/compat.js`'s three-tier resolver should already handle stable promotions; experimental fallbacks may need updating.
- `ToolsPanel` / `ToolsPanelItem` — central to Inspector layout (chips use `grid-column: 1 / -1` per `CLAUDE.md` gotcha).
- `PanelBody` and form controls used in `src/inspector/`, `src/templates/`, `src/template-parts/`, `src/admin/settings-page-controller.js`.
- DataViews and Fields integration in `src/admin/activity-log.js`.
- Any `__experimental*` `@wordpress/components` imports.
- `@wordpress/icons` named imports — grep `src/` for `from '@wordpress/icons'` and verify each named import still resolves.

If any of the above has API removals the plugin uses non-trivially, **stop and surface** rather than rewrite in-flight (per the "stop conditions" section below). This is the highest-variance stage.

### Stage 4 — Build toolchain

`@wordpress/scripts` 30 → 32. Two majors. Ripples through:

- Webpack config (build output may shift)
- Jest config (test discovery, transforms)
- ESLint config (new rules may flag existing code — fix or disable explicitly per rule, never wholesale)
- The three webpack entry points (`src/index.js`, `src/admin/settings-page.js`, `src/admin/activity-log.js`) must continue to produce `build/index.js`, `build/admin.js`, `build/activity-log.js`

This stage runs **after** Stage 3 because the new `@wordpress/scripts` build chain compiles against the new WP API surface; bumping the toolchain first would mask whether a build failure came from old-WP-against-new-toolchain or new-toolchain-against-new-WP.

### Stage 5 — PHPCS (deferred)

`squizlabs/php_codesniffer` 3 → 4 is **deferred**. `composer.lock` shows `wp-coding-standards/wpcs@3.3.0` (the latest released WPCS) requires `squizlabs/php_codesniffer:^3.13.4`. There is no WPCS release compatible with PHPCS 4, so `composer require --dev squizlabs/php_codesniffer:^4.0` would fail resolution. No code edits, no commit, no verify run for this stage. Revisit when WPCS publishes a PHPCS 4-compatible version (track upstream at `WordPress/WordPress-Coding-Standards`).

## Per-stage verification protocol

1. Apply the stage's manifest+lockfile change using the matching command from "Lockfile-update commands by stage type" above.
2. Sanity-check that the lockfile and manifest agree by running `npm ci` (or `composer install` for any composer stage) — this should succeed without modifying anything. If it fails, the lockfile update step was wrong; **stop**.
3. Run `node scripts/verify.js --skip=lint-plugin --skip-e2e`.
4. Read `output/verify/summary.json`. Require `status: "pass"`.
5. Capture a post-stage build manifest: `(cd build && find . -type f \( -name '*.js' -o -name '*.asset.php' -o -name '*.css' \) -print0 | xargs -0 sha256sum) | sort > output/verify/stage-<N>-build.sha256`. Compare against the previous stage's manifest with `diff`. Also capture lockfile size: `wc -l package-lock.json > output/verify/stage-<N>-lockfile.txt`. Expected change profile per stage:
   - **Stage 1**: small build diff (minor bumps). Lockfile diff in the low hundreds of lines. A multi-thousand-line lockfile diff means transitive WP majors got pulled in and is a stop signal.
   - **Stage 2**: near-zero build diff (Playwright is dev-only, doesn't affect the bundled output). Lockfile diff small.
   - **Stage 3**: large build diff expected (new WP component implementations) and large lockfile diff. Document the diff scope (which chunks moved) in the commit message body.
   - **Stage 4**: large build diff expected (new build toolchain rebuilds everything with new chunk hashes). Document but do not block on it.
6. For stages 3 and 4: spot-check the editor in a browser if the local Docker stack is running. Concretely, exercise each webpack entry point at least once — open the post editor and confirm the Inspector recommendations panel renders (`build/index.js`), open `Settings > Flavor Agent` and confirm the settings UI hydrates (`build/admin.js`), open `Settings > AI Activity` and confirm the DataViews app loads (`build/activity-log.js`). Type checks and unit tests don't catch UI regressions. If the stack is not running, document this gap in the commit message rather than skip silently.
7. Commit. Message format: `deps(<scope>): bump <summary>` plus a body listing each upgraded package and any forced adjustments.

After stage 4: one full `node scripts/verify.js --skip=lint-plugin` (with E2E). Compare its summary against `output/verify/baseline-full.json` from stage 0. Per-suite E2E pass counts must be `>=` baseline; new failures (suites that passed at baseline and now fail) are blocking. Pre-existing failures that remain failing are not blocking — we are not fixing pre-existing E2E breakage as part of this work.

## Stop conditions

Halt the pipeline (do not proceed to the next stage, do not push past) when any of these occur:

- Verify reports `fail` or unexpected `incomplete`.
- A changelog or runtime error reveals an API removal that touches plugin code, **and** the adaptation is non-trivial (more than a rename or a single-line argument shape change). Surface to user; defer the stage.
- Build manifest diff (per the SHA256 capture in verification step 5) shows churn outside the expected profile for the stage — e.g. Stage 1 produces a multi-thousand-line lockfile diff, or Stage 3's diff includes chunks that don't import from any of the upgraded packages.
- `npm install <pkg>@<version>` fails resolution — stop and report the conflict; do not retry with `--legacy-peer-deps` or `--force`.
- Post-update sanity `npm ci` fails — means the lockfile update was incomplete.
- New ESLint or PHPCS findings against existing code that aren't trivially fixable.

When halted: revert the in-progress stage's working changes (do not commit a broken state), document the blocker, hand back to user.

**Stage-specific stop semantics.** If Stage 3 (WP alignment) hits a stop condition, **Stage 4 does not proceed.** `@wordpress/scripts@32` is built against the new WP API surface and may fail or produce subtly broken output if linked against the old WP packages. Stages 1 and 2 ship as the partial result; Stage 3 (and consequently 4) becomes a separate brainstorm. If Stage 4 hits a stop condition with Stage 3 already shipped, Stages 1–3 still ship and Stage 4 becomes a separate brainstorm.

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
- `composer.json` / `composer.lock` unchanged (stage 5 deferred upstream).
- Each stage commit passes `node scripts/verify.js --skip=lint-plugin --skip-e2e` in isolation.
- Final full `node scripts/verify.js --skip=lint-plugin` (with E2E) shows per-suite pass counts `>=` `output/verify/baseline-full.json` from stage 0. New E2E failures (passed at baseline → fail now) are blocking; same-as-baseline failures are not.
- No source-code change beyond what an upgrade forced.
- Commit history allows `git bisect` to attribute any future regression to a single stage commit.

## Artifact locations

- `output/verify/baseline-fast.json`, `output/verify/baseline-full.json`, `output/verify/baseline-build.sha256`, `output/verify/baseline-lockfile.txt` — stage 0 baselines, kept for the duration of the work.
- `output/verify/stage-<N>-build.sha256`, `output/verify/stage-<N>-lockfile.txt` — per-stage manifests for diff comparisons.
- `output/verify/summary.json` and `output/verify/<step>.{stdout,stderr}.log` — per verify run, overwritten each invocation (gitignored).
- Working files: `package.json`, `package-lock.json`, plus any forced source edits.

## Estimated cost

- Stage 0: ~15 min (two verifies + build/lockfile capture).
- Stage 1: ~10 min (named installs + verify + commit).
- Stage 2: ~5 min (single dev-dep bump).
- Stage 3: high-variance — could be 1 hour if the WP API surface still works as-imported, or several hours if forced code adjustments span Inspector + admin + content surfaces. Most likely place to hit the stop condition.
- Stage 4: ~30 min (build-config adjustment risk if `@wordpress/scripts@32` changed defaults).
- Stage 5: 0 min (deferred — no work).

If Stage 3 stops, Stages 1–2 ship; Stage 3 (and consequently 4) becomes a separate brainstorm. If Stage 4 stops with Stage 3 already shipped, Stages 1–3 ship and Stage 4 becomes a separate brainstorm.
