# Uncommitted Review Remediation Implementation Plan

**Goal:** Resolve every confirmed issue from the uncommitted-changes review so the compact recommendation UI changes can ship without whitespace, packaging, or documentation-contract regressions.

**Architecture:** Keep the current compact recommendation UI direction as the intended product behavior, because the changed source and tests already assert that direction. The remediation updates quality gates, release hygiene, and source-of-truth documentation around that behavior rather than re-expanding the panels.

**Tech Stack:** WordPress plugin PHP, Gutenberg React components, `@wordpress/scripts` unit tests, PHPUnit, Bash release/docs scripts, repo markdown docs.

**Implementation guidance:** Follow `docs/reference/agentic-plan-implementation-guide.md` for task execution. Steps use checkbox syntax for tracking.

---

## Confirmed Findings Covered

1. `git diff --check` fails because new hunks in `src/editor.css` contain CRLF/trailing-whitespace markers.
2. Untracked root files `IMG_0115.jpg` and `IMG_0116.jpg` are unreferenced and would be copied into release packages if committed, because `.distignore` does not exclude arbitrary root JPEGs.
3. The compact UI changes remove or suppress documented shared-shell elements on Block, Content, and Template recommendation surfaces, but the recommendation UI docs still describe the previous `SurfacePanelIntro` and fresh-result `RecommendationHero` contract.

## File Map

- Modify: `src/editor.css`
  - Normalize line endings/whitespace only. Preserve the current visual CSS rules.
- Remove from working tree or intentionally relocate: `IMG_0115.jpg`, `IMG_0116.jpg`
  - Primary path: delete the unreferenced root artifacts before commit.
  - If the images are intentional validation evidence, move them under `output/` or a referenced docs location and update `.distignore` or docs accordingly.
- Modify: `docs/reference/recommendation-ui-consistency.md`
  - Update the shared component matrix and panel-order contract for compact Block, Content, and Template shells.
- Modify: `docs/reference/shared-internals.md`
  - Update shared component consumer notes so `SurfacePanelIntro` and `RecommendationHero` reflect opt-in/partial usage.
- Modify: `docs/SOURCE_OF_TRUTH.md`
  - Update the Template UI flow line so it no longer claims a fresh featured `RecommendationHero` when the compact shell renders explanation before lanes.
- Optional modify: `scripts/check-doc-drift.sh`
  - Only if the script is meant to become part of `npm run check:docs`; otherwise leave it as an explicit standalone governance helper.

---

## Task 1: Normalize `src/editor.css` Whitespace

**Files:**
- Modify: `src/editor.css`

- [ ] **Step 1: Confirm the current failure**

Run:

```bash
git diff --check -- src/editor.css
```

Expected before the fix: non-zero exit with trailing-whitespace reports beginning near `src/editor.css:153`.

- [ ] **Step 2: Normalize the file without changing CSS semantics**

Use a line-ending formatter or editor operation that converts the file to LF and strips trailing whitespace. Do not reorder selectors or change property values in this task.

Acceptable command:

```bash
perl -0pi -e 's/\r\n/\n/g; s/[ \t]+$//mg' src/editor.css
```

- [ ] **Step 3: Verify the whitespace gate**

Run:

```bash
git diff --check -- src/editor.css
```

Expected after the fix: no output and exit code `0`.

---

## Task 2: Remove Unreferenced Root JPEG Artifacts

**Files:**
- Remove: `IMG_0115.jpg`
- Remove: `IMG_0116.jpg`

- [ ] **Step 1: Reconfirm the images are unreferenced**

Run:

```bash
rg -n "IMG_0115|IMG_0116" .
```

Expected before removal: no output.

- [ ] **Step 2: Remove the untracked root artifacts**

Run:

```bash
rm -- IMG_0115.jpg IMG_0116.jpg
```

- [ ] **Step 3: Confirm release packaging can no longer pick them up**

Run:

```bash
git status --short
```

Expected after removal: neither `IMG_0115.jpg` nor `IMG_0116.jpg` appears.

Do not add a broad `*.jpg` exclusion to `.distignore` unless image assets become a supported plugin input, because a broad rule could silently drop future legitimate assets.

---

## Task 3: Align Recommendation UI Docs With The Compact Shell

**Files:**
- Modify: `docs/reference/recommendation-ui-consistency.md`
- Modify: `docs/reference/shared-internals.md`
- Modify: `docs/SOURCE_OF_TRUTH.md`

- [ ] **Step 1: Update the shared component matrix**

In `docs/reference/recommendation-ui-consistency.md`, update the rows for Block, Content, and Template:

```markdown
| Block inspector main panel | Yes | Partial | Yes | No | Yes | Yes | No | Yes | Yes | Yes | Compact block shell uses `SurfaceScopeBar` plus composer; `RecommendationHero` is reserved for stale refresh, while fresh results render explanation and lanes directly |
| Content document panel | No | Yes | Yes | No | Yes | Yes | No | No | Yes | Yes | Compact editorial-only panel; generated output uses `RecommendationHero` as the result container without the previous generated-guidance eyebrow |
| Template | Yes | Partial | Yes | Yes | Yes | Yes | No | Yes | Yes | Yes | Compact preview-first shell; `RecommendationHero` is reserved for stale refresh, while fresh results render explanation before review/advisory lanes |
```

- [ ] **Step 2: Update the executable/advisory copy table**

In the same file, update the Block, Content, and Template copy-pattern rows to match the new behavior:

```markdown
| Block inspector main panel | `Apply now` for inline-safe attributes; `Review first` for validator-approved structural operations, with `Stale` when stale | `Manual ideas` via `AIAdvisorySection` | Fresh results use lane labels; stale refresh uses the hero tone; advisory section shows `Advisory only` | Compact direct local-attribute apply plus reviewed structural apply |
| Content | None | `Editorial Notes` via `AIAdvisorySection` | Result hero uses only the generated title and mode pill; generated text can be copied manually | Editorial output and review notes, no apply lane |
| Template | `Review first` with tone `Review first` | `Manual ideas` via `AIAdvisorySection` | Fresh cards show operation summary and review state; stale refresh uses the hero tone; button uses `Review` / `Reviewing` | Preview-confirm flow with bounded advisory fallbacks |
```

- [ ] **Step 3: Replace the fixed full-panel order with opt-in compact-shell wording**

Replace the strict order in `docs/reference/recommendation-ui-consistency.md` with this contract:

```markdown
For full recommendation panels that still use the expanded shared shell, keep this order:

1. `SurfacePanelIntro`
2. `SurfaceScopeBar`
3. `SurfaceComposer`
4. `AIStatusNotice`
5. `RecommendationHero`
6. supporting explanation or rationale copy
7. executable lane
8. advisory lane
9. `AIReviewSection` when applicable
10. `AIActivitySection`

Compact recommendation panels may omit `SurfacePanelIntro` and the fresh-result featured hero when the scope bar, composer helper, result explanation, and lanes still preserve scope, freshness, capability, and apply/review boundaries. Block and Template use this compact shell and keep `RecommendationHero` for stale-refresh calls to action. Content keeps `RecommendationHero` as the generated-result container but no longer shows the previous eyebrow copy.
```

- [ ] **Step 4: Update shared component consumer notes**

In `docs/reference/shared-internals.md`, update the intro/hero bullets to:

```markdown
- `SurfacePanelIntro.js` renders the short surface-specific intro copy block for expanded full-panel shells. Compact shells may replace it with `SurfaceScopeBar`, composer helper text, and short inline notes when the surface contract remains clear.
- `RecommendationHero.js` renders either a featured fresh result on expanded shells, a stale-refresh call to action on compact executable shells, or the generated-result body on the content surface.
```

Also update the consumer counts so they describe current usage rather than fixed mandatory usage:

```markdown
**Consumers:** Template-Part, Global Styles, Style Book, plus Navigation standalone variants for `SurfacePanelIntro`; Block, Content, Template, Template-Part, Navigation, Global Styles, and Style Book consume the broader scope/composer shell pieces with surface-specific compact variants.
```

- [ ] **Step 5: Update the Template flow in `docs/SOURCE_OF_TRUTH.md`**

Change the Template UI flow line from:

```markdown
-> UI: RecommendationHero + review/advisory lanes + linked entity text + review state
```

to:

```markdown
-> UI: compact scope/composer shell + linked explanation + `Review first` / `Manual ideas` lanes + review state; stale results use a refresh `RecommendationHero`
```

- [ ] **Step 6: Search for stale claims**

Run a stale-copy search for the old content eyebrow, the old block intro-shell sentence, the old Template UI-flow line, and the old content hero-eyebrow sentence across `docs/`.

Expected after the docs update: no stale claims remain outside historical plan/spec files. If matches remain in live reference docs, update them in the same task.

---

## Task 4: Decide Whether The New Drift Script Is A Standalone Helper Or A Docs Gate

**Files:**
- Optional modify: `package.json`
- Optional modify: `scripts/check-doc-freshness.sh`
- Optional modify: `docs/reference/markdown-redundancy-and-drift-remediation-plan.md`

- [ ] **Step 1: Keep the script standalone unless governance wants it in the default docs gate**

No code change is required if `scripts/check-doc-drift.sh` is intentionally a manually run helper. The direct validation command remains:

```bash
bash scripts/check-doc-drift.sh
```

- [ ] **Step 2: If integrating it, wire it through `check:docs`**

Only if the team wants the drift scan to run on every docs check, update `scripts/check-doc-freshness.sh` near the end, before the final exit:

```bash
if ! bash "${repo_root}/scripts/check-doc-drift.sh"; then
	fail=1
fi
```

Then run:

```bash
npm run check:docs
```

Expected: the existing freshness checks and the drift scan both pass.

- [ ] **Step 3: If leaving it standalone, document that status**

In `docs/reference/markdown-redundancy-and-drift-remediation-plan.md`, update the optional governance section to say the script exists and is run manually until intentionally wired into `check:docs`.

---

## Task 5: Focused Verification

**Files:**
- No source modifications unless verification exposes a new confirmed issue.

- [ ] **Step 1: Run whitespace and docs checks**

Run:

```bash
git diff --check
npm run check:docs
bash scripts/check-doc-drift.sh
```

Expected: all three commands exit `0`.

- [ ] **Step 2: Re-run the focused JS suites touched by the compact UI changes**

Run:

```bash
npm run test:unit -- --runTestsByPath src/content/__tests__/ContentRecommender.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js src/templates/__tests__/TemplateRecommender.test.js
```

Expected: all three suites pass.

- [ ] **Step 3: Re-run the focused PHP recommendation suites**

Run:

```bash
vendor/bin/phpunit tests/phpunit/PatternAbilitiesTest.php tests/phpunit/AgentControllerTest.php --filter 'recommend_patterns|handle_recommend_patterns'
```

Expected: all filtered tests pass.

- [ ] **Step 4: Run the fast aggregate verifier**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: build, JS lint, plugin-check or explicit plugin-check environment handling, unit, and PHP gates pass. If plugin-check is unavailable because `WP_PLUGIN_CHECK_PATH` or host prerequisites are missing, rerun:

```bash
npm run verify -- --skip-e2e --skip=lint-plugin
```

Record the skip reason in the implementation closeout.

---

## Acceptance Criteria

- `git diff --check` exits `0`.
- `IMG_0115.jpg` and `IMG_0116.jpg` are absent from `git status --short`, or they are intentionally relocated and referenced.
- `docs/reference/recommendation-ui-consistency.md`, `docs/reference/shared-internals.md`, and `docs/SOURCE_OF_TRUTH.md` describe the compact Block, Content, and Template shells accurately.
- `npm run check:docs` and `bash scripts/check-doc-drift.sh` pass.
- The three focused JS suites and the filtered PHP recommendation tests pass.
- `npm run verify -- --skip-e2e` passes, or any intentional `lint-plugin` skip is explicitly recorded with the local prerequisite reason.
