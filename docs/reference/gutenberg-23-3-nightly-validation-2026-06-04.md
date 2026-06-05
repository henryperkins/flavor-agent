# Gutenberg 23.3.x Nightly Runtime Validation — Results (2026-06-04)

Execution record for the pass defined by
[`gutenberg-23-3-nightly-validation-checklist.md`](./gutenberg-23-3-nightly-validation-checklist.md).
Driven via Playwright browser automation against the local nightly dev
container, with WP-CLI and REST introspection for ground truth. Every item is
recorded `pass` / `fail` / `not-testable`; a missing harness or absent runtime
prerequisite is an explicit **waiver with a reason**, not a silent skip.

## Headline

Flavor Agent passes the runtime-sensitive checks on **both** the React 19 build
that the container started on **and** the React 18.3.1 build that current
Gutenberg has reverted to. No functional regressions. Two non-blocking
follow-ups and one harness-limited section (Site Editor) carried forward.

## Two runtimes (the React-19 revert)

Gutenberg's React version changed mid-cycle, so the pass spans two runtimes:

| Pass | Gutenberg | React | Scope |
| ---- | --------- | -------- | ----- |
| 1 | 23.3.0 | **19.2.4** | Container's installed version at start — full A–G sweep. |
| 2 (re-run) | 23.3.2 | **18.3.1** | Section A + every React-version-sensitive item re-run. |

**Why it matters.** GB **23.3.1** (compat backports: legacy
`render`/`hydrate`/`unmountComponentAtNode` polyfills, `ReactCurrentOwner` shim,
getter-based `@wordpress/build` exports restored) and **23.3.2** shipped during
this work. **23.3.2 reverts the React 19 upgrade → React/ReactDOM 18.3.1**
(bundled `react/jsx-runtime` produced legacy element symbols React 19 rejected —
WordPress/gutenberg#78940). Flavor Agent externalizes React via
`@wordpress/scripts`, so its **runtime** React is always whatever core ships;
the FA React-19 graph bump (`7b12a7b`) is build/test-only and does **not** drive
runtime. The container was upgraded 23.3.0 → 23.3.2 and left there (current
release).

## Environment

- WordPress `7.1-alpha-62456` (nightly), container `wordpress-wordpress-1`, origin `http://localhost:8888`.
- Active companions: `ai` (v1.0.1), `ai-provider-for-{anthropic,google,openai}`, `mcp-adapter`, `plugin-check`, `wordpress-beta-tester`, `flavor-agent`.
- Connectors **approved** for Flavor Agent (OpenAI + Anthropic); chat routed to OpenAI `gpt-5.4-mini`. All eight surfaces reported `available / ready`.
- `build/*` confirmed current (Phase 3 markers `validation-reasons-v1`, `validationReasons`, `rankingSet` present in `build/index.js`; webpack `compareBeforeEmit` skipped a byte-identical rewrite, so the stale mtime was a false alarm).

## Results by section

### A — React runtime smoke — **PASS** (both runtimes)

- Post editor loads with **0 errors**. Block panel ("AI Recommendations") renders in Block → Settings.
- Live block fetch → rich result: model advisory, **docs-grounding warn** notice (the #25 degrade-to-warn path, not a 503 block), **Inline-safe** apply lane, style-suggestion lane.
- **No duplicate `/run` calls.** One AI generation + one `resolveSignatureOnly:true` resolve (apply-time freshness baseline) — verified by request body, not assumed. Identical on both runtimes.
- Apply → real attribute mutation (`is-style-text-subtitle`) + inline pill; post-apply **stale gate** trips and disables stale suggestions; **undo reverts cleanly** (`className` → null). Block-switch drops the prior prompt.
- Provider routing surfaced as **OpenAI · gpt-5.4-mini**.
- **Console warnings are all core-origin** (iframe-CSS notice, `MediaUpload` class deprecation) plus the FA `useSelect` hint (Finding 1). None are React ref/lifecycle warnings; no FA runtime errors.

### B — Style-state inspector — **PASS (core)** + labeled follow-up

- GB 23.3 style states present without a separate experiment flag: **Viewport** (Default/Tablet/Mobile) + **Pseudo state** (Default/Hover/Focus/Focus-visible/Active).
- Entering Hover: **0 errors**; inspector correctly narrows to supported controls (#78280). FA injects **nothing orphaned/duplicated**, no broken `grid-column: 1 / -1` span, and **hides cleanly** when there are no suggestions.
- **Not exercised:** in-state delegated-chip *apply→base* with populated suggestions — the checklist's own "forward-compat follow-up, not a release blocker" item. FA writes only base `attributes.style.*` by construction (proven on the paragraph apply).

### C — Abilities bridge defer — **PASS**

- `window.flavorAgentAbilities.ready` resolves and `executeAbility` dispatches to registered abilities **with the command palette never opened** (`paletteOpenedAfter: false`). The historical palette-defer bug is absent.
- Read-only helpers returned correct **semantic** rejections ("Read-only abilities require GET method") — proof the bridge reached the registered abilities, not a defer failure.
- Panel → `store/abilities-client.js` → REST recommend path works end-to-end (the block fetch).

### D — Pattern inserter — **PASS**

- FA pattern slot mounts; `inserter-dom.js` selectors (sidebar content, search input) resolve. The "no patterns at this insertion point" state (#26) showed correctly for a constrained context.
- 5 recommendations populated with rationale at a root-level insertion point.
- Real Insert landed the pattern as a `core/group` at **root index 1** (intended location); **`orphanCount: 0`** (tree fully reachable from root; root 3→4, total 4→13). The null-root orphan fix holds.

### E — Style Book & Global Styles DOM — **NOT-TESTABLE (waiver)**

- Site Editor SPA + FA store boot with **0 errors**; FA injects a "Flavor Agent recent changes" region + toast region into the Site Editor shell.
- **Waived:** GlobalStylesRecommender / StyleBookRecommender mounts, the `.edit-site-global-styles-screen-style-book` / `.editor-style-book__iframe` locators, and the Global Styles fetch/apply/undo cycle. **Reason:** the `editor-canvas` iframe did not render in the headless automation context (`iframeCount: 0` across the hub, `?p=/styles`, and `?canvas=edit`), and these surfaces mount only inside the canvas-backed styles editing view. **Complete in an interactive (real-browser) nightly pass.**

### F — Connectors graduation + chat — **PASS**

- Settings → Connectors present and functional on the nightly: lists Anthropic / Google / OpenAI, "API keys… stored here and shared across plugins," **no PHP errors**.
- Chat returns **live results via the approved connector** (proven by the block + pattern fetches).
- **Revoke → graceful degrade:** sanctioned waiver — the checklist states this path is unit-covered (`request-error-details.test.js`) and "need not be forced"; the shared container's connector approval was not mutated.
- WP-7.0-exact Connectors screen on the `:9404` harness: minor deferred item (nightly 7.1-alpha already demonstrates the graduated screen persists).

### G — Low-risk UI watch — **PASS**

- **AI Activity DataViews renders** (date-grouped list, summary cards, populated detail panel with Overview/Diagnostics/Request/Undo/State snapshots) and **first-click row selection works** (clicking a row immediately switched the detail panel).
- Dimensions/style chips render in the block inspector lanes without layout breakage (no broken ToolsPanel grid span — see B).
- Template/Template-Part tooltips: Site-Editor-bound → folded into the E waiver.

### Activity / request-diagnostic contract — **CONFIRMED** (bonus)

The activity REST repository holds **146 entries** (summary: applied 6 / undone 3 / review 133 / blocked 0 / failed 4); page 1 is the test session (`post:82`, pattern + block surfaces, including the subtitle apply→undone). The request-diagnostic + activity contract works end-to-end on both runtimes.

## Findings

### Finding 1 — FA `useSelect` returns-different-values warnings — **React-agnostic, non-blocking**

`@wordpress/data` emits "`useSelect` hook returns different values… Non-equal value keys" for three FA selector groups: **`recommendations`**, **`blockActivityLog, currentBlockPath`**, **`getBlock, getBlockAttributes, getBlocks`** (the live-context hook). The selectors return fresh object/array references each render. **Fires on both React 19.2.4 and 18.3.1** → it is a `@wordpress/data` hygiene warning, not a React-19 artifact. A perf hint, not an error. **Follow-up:** memoize those selector returns (extends the #24 memoization work).

### Finding 2 — `inert` string boolean-attribute error — **React-19-only, currently moot**

On React 19.2.4, the AI Activity page logged a `react-dom` error: *"Received the string for the boolean attribute `inert`… Did you mean inert={true}?"* **Clears on React 18.3.1** (React 18 accepts string `inert` silently) — re-verified 0 errors after the same load → Refresh → toggle-detail sequence. **Attribution:** FA *source* contains no `inert` (`grep src/` empty); the string is in `build/activity-log.js` (20×) because **`@wordpress/dataviews` is bundled, not externalized**, so it is upstream DataViews code. **Forward-compat only:** resurfaces if/when core re-lands React 19; the fix is an updated bundled `@wordpress/dataviews`, not FA's own code.

### Observation — AI Activity admin view scoping — **benign**

The admin screen initially showed only 4 "failed" rows with summary cards scoped to that subset (vs 146 in the repo). Root cause: a **persisted `"failed"` search term** in the DataViews search box (prior-session view state), not data loss and not a default-view bug. Optional product check: whether summary-card counts should reflect the filtered subset or the total.

## Carried-forward work

1. **Manual real-browser pass for Section E** (Site Editor Global Styles + Style Book mounts/DOM/apply/undo) and the in-state style-state apply→base (B) — both blocked only by the headless canvas limitation.
2. **Memoize the three FA `useSelect` returns** (Finding 1).
3. **Bundled-DataViews `inert`** (Finding 2) — revisit only when core retries React 19.

Currentness note, 2026-06-05: the `useSelect` memoization follow-up is no longer open in the current checkout; `STATUS.md` records the 2026-06-02 referential-stability pass and its verification. The manual real-browser Site Editor pass and React 19 / bundled DataViews watch remain the live takeaways.

## Evidence appendix

- React: `wp.element.version` 19.2.4 (23.3.0) → `null` + `window.React.version` 18.3.1, `react`/`react-dom` `?ver=18.3.1` (23.3.2).
- Block fetch request bodies: generation carries `clientRequest.requestToken`; the sibling call carries `resolveSignatureOnly:true` with `clientRequest` stripped — distinct purposes, not a duplicate.
- Pattern insert: root order 3→4, descendants 4→13, `orphanCount: 0`.
- Abilities bridge: `ready` resolved, `executeAbility` dispatched, palette never opened.
- Activity REST `summary`: `{ total: 146, applied: 6, undone: 3, review: 133, blocked: 0, failed: 4 }`.
