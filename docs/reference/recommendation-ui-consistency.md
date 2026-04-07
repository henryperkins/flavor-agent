# Recommendation UI Consistency Review

Cross-surface comparison of the Flavor Agent recommendation UI across:

- `src/inspector/BlockRecommendationsPanel.js`
- `src/inspector/StylesRecommendations.js`
- `src/patterns/PatternRecommender.js`
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `inc/LLM/Prompt.php`
- `inc/LLM/TemplatePrompt.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/LLM/StylePrompt.php`

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the fast product view and the per-surface docs in `docs/features/` for full request and apply flows.

## Shared Interaction Pattern

The most complete executable surfaces follow the same broad skeleton:

1. intro and scope
2. prompt composer
3. status notice
4. featured hero
5. executable lane
6. advisory lane
7. review-before-apply section when required
8. recent activity and undo

That pattern is strongest in Style Book and Global Styles, mostly present in Template and Template-Part, now present in the main Block panel, and intentionally absent from the Pattern inserter affordance and the lightweight block style subpanel.

## Prompt-Layer Contract

| Surface       | Prompt contract                                                                                                                                                                       | Real executable/advisory split       |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ |
| Block         | `Prompt.php` allows executable local block mutations plus advisory-only `structural_recommendation` and `pattern_replacement` items                                                   | Yes                                  |
| Block styles  | Uses block recommendation payload grouped by inspector panel                                                                                                                          | Mostly executable only               |
| Pattern       | Ranking and inserter patching, not a review/apply prompt flow                                                                                                                         | No lane split                        |
| Template      | `TemplatePrompt.php` keeps `operations[]` as the executable source of truth when present and preserves validated advisory-only summaries when no safe deterministic apply path exists | Yes, with bounded advisory fallbacks |
| Template-part | `TemplatePartPrompt.php` is advisory-first and only returns operations when the change is fully safe and deterministic                                                                | Yes                                  |
| Style Book    | `StylePrompt.php` returns `tone=executable` or `tone=advisory`; executable items must carry valid operations                                                                          | Yes                                  |
| Global Styles | `StylePrompt.php` returns `tone=executable` or `tone=advisory`; executable items must carry valid operations                                                                          | Yes                                  |

## Shared Component Matrix

`StylesRecommendations.js` is a sub-surface inside the block inspector, so it is listed separately from the main block panel.

| Surface                        | `RecommendationLane` | `RecommendationHero` | `AIReviewSection` | `AIActivitySection` | `SurfaceComposer` | `SurfacePanelIntro` | `SurfaceScopeBar` | `AIStatusNotice` | `CapabilityNotice` | Notes                                                                                |
| ------------------------------ | -------------------- | -------------------- | ----------------- | ------------------- | ----------------- | ------------------- | ----------------- | ---------------- | ------------------ | ------------------------------------------------------------------------------------ |
| Block inspector main panel     | Yes                  | Yes                  | No                | Yes                 | Yes               | Yes                 | Yes               | Yes              | Yes                | One-click apply plus stale-result hero and embedded navigation subsection            |
| Block inspector style subpanel | Yes                  | No                   | No                | No                  | No                | Yes                 | No                | Yes              | No                 | Lightweight embedded style rows and variation buttons plus shared apply-error notice |
| Pattern inserter affordance    | No                   | No                   | No                | No                  | No                | No                  | No                | No               | Yes                | Injected loading, empty, error, success, and setup notices only                      |
| Template                       | Yes                  | Yes                  | Yes               | Yes                 | Yes               | Yes                 | Yes               | Yes              | Yes                | Also uses `AIAdvisorySection`                                                        |
| Template-part                  | Yes                  | Yes                  | Yes               | Yes                 | Yes               | Yes                 | Yes               | Yes              | Yes                | Also uses `AIAdvisorySection`                                                        |
| Style Book                     | Yes                  | Yes                  | Yes               | Yes                 | Yes               | Yes                 | Yes               | Yes              | Yes                | Most standardized panel flow                                                         |
| Global Styles                  | Yes                  | Yes                  | Yes               | Yes                 | Yes               | Yes                 | Yes               | Yes              | Yes                | Mirrors Style Book almost exactly                                                    |

## Executable And Advisory Lane Copy

| Surface                        | Executable lane                                            | Advisory lane                           | Card or badge labels                                                                                  | Copy pattern                                           |
| ------------------------------ | ---------------------------------------------------------- | --------------------------------------- | ----------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| Block inspector main panel     | `Apply now` with tone `Apply now` or `Stale`               | `Manual ideas` with tone `Manual ideas` | Hero uses `Apply now` or `Manual ideas`                                                               | Clear direct-apply wording for local block attributes  |
| Block inspector style subpanel | `Style Variations`, per-panel lanes, `Native Style Panels` | None                                    | Rows and delegated panel lane use `Apply now`                                                         | Direct apply beside native controls                    |
| Pattern inserter               | None                                                       | None                                    | Summary and state notices use `Flavor Agent`, recommendation count pills, and retry text when needed  | Ranking affordance, not lane-based                     |
| Template                       | `Review first` with tone `Review first`                    | `Manual ideas` via `AIAdvisorySection`  | Card pills show `Review first`, `Manual ideas`, and `Review open`; button uses `Review` / `Reviewing` | Preview-confirm flow with bounded advisory fallbacks   |
| Template-part                  | `Review first` with tone `Review first`                    | `Manual ideas` via `AIAdvisorySection`  | Card pills show `Review first`, `Manual ideas`, and `Review open`; button uses `Review` / `Reviewing` | Best match for a true advisory-first structure surface |
| Style Book                     | `Review first` with tone `Review first`                    | `Manual ideas` with tone `Manual ideas` | Card badges use `Review first`, `Manual ideas`, and `Review open`                                     | Cleanest standardized style-surface vocabulary         |
| Global Styles                  | `Review first` with tone `Review first`                    | `Manual ideas` with tone `Manual ideas` | Card badges use `Review first`, `Manual ideas`, and `Review open`                                     | Mirrors Style Book vocabulary                          |

### Copy Inconsistencies

- The main recommendation surfaces now share the same three user-facing labels: `Apply now`, `Review first`, and `Manual ideas`.
- Template and Template-Part now share the same tone-pill treatment and inline entity linking in explanation and description copy, but they still render advisory lanes through `AIAdvisorySection` while Style Book and Global Styles use `RecommendationLane`.
- Block styles remains a lightweight embedded surface and still skips the explicit executable/manual lane split used by the larger panels.

## Review-Before-Apply Contract

| Surface                        | Apply model                                         | Review model                                       | Confirm step           |
| ------------------------------ | --------------------------------------------------- | -------------------------------------------------- | ---------------------- |
| Block inspector main panel     | One-click apply for safe local block updates        | None                                               | No                     |
| Block inspector style subpanel | One-click apply for style variations and style rows | None                                               | No                     |
| Pattern inserter               | User inserts through core inserter UI               | Core pattern preview only                          | Core handles insertion |
| Template                       | Select suggestion for review                        | Separate `AIReviewSection` renders below the lanes | Yes                    |
| Template-part                  | Select suggestion for review                        | Separate `AIReviewSection` renders below the lanes | Yes                    |
| Style Book                     | Select suggestion for review                        | Separate `AIReviewSection` renders below the lanes | Yes                    |
| Global Styles                  | Select suggestion for review                        | Separate `AIReviewSection` renders below the lanes | Yes                    |

### Practical Difference

- The main Block panel and the lightweight block style subpanel are the only one-click apply surfaces. They apply immediately and use short-lived inline feedback beside the affected control.
- Template, Template-Part, Style Book, and Global Styles all preserve the review-before-apply contract and now present review with the same broad structure.
- Those preview-first surfaces use a dedicated review panel below the lanes.
- The active card stays visible as selected with `Review open`, while the shared lower panel owns confirm and cancel.

## Undo, Activity History, And Stale State

| Surface                        | Activity history | Undo model                                                                                        | Stale-result handling                                                                                                               |
| ------------------------------ | ---------------- | ------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| Block inspector main panel     | Yes              | Shared latest-valid tail undo; block snapshot must still match                                    | Keeps stale results visible, marks them stale, disables apply, offers refresh                                                       |
| Block inspector style subpanel | No               | No surface-level undo                                                                             | No scope bar or stale hero, but stale execution is blocked by the shared store-level freshness guard and surfaced as an apply error |
| Pattern inserter               | No               | No Flavor Agent undo                                                                              | No stale UI                                                                                                                         |
| Template                       | Yes              | Shared latest-valid tail undo; validated undo preparation must still succeed                      | Keeps stale results visible, marks them stale, disables apply, offers refresh                                                       |
| Template-part                  | Yes              | Shared latest-valid tail undo; validated undo preparation must still succeed                      | Keeps stale results visible, marks them stale, disables apply, offers refresh                                                       |
| Style Book                     | Yes              | Shared latest-valid tail undo; block style branch must still match the recorded post-apply config | Keeps stale results visible, marks them stale, disables apply, offers refresh                                                       |
| Global Styles                  | Yes              | Shared latest-valid tail undo; live config must still match the recorded post-apply config        | Keeps stale results visible, marks them stale, disables apply, offers refresh                                                       |

### Shared Undo Rule

All executable history surfaces depend on `src/store/activity-history.js` for ordered undo resolution. Only the newest still-valid AI action in a scope can be undone. Surface-specific runtime validators then decide whether the target still matches closely enough for automatic undo.

### Consistency Gaps

- Block, Template, Template-Part, Style Book, and Global Styles now share the same stale-result model: preserve the previous result, mark it stale, disable execution, and offer refresh.
- Block styles now shares the same store-level stale-apply guard as the other executable surfaces, even though it still omits scope freshness UI and activity history.
- Pattern still exposes no activity or undo affordance, which keeps it separate from the fuller recommendation surfaces even though its loading, empty, error, and success states are now explicit.

## Prompting And Composer Gaps

| Surface                        | Starter prompts | Secondary helper text           | Submit hint | Scope bar |
| ------------------------------ | --------------- | ------------------------------- | ----------- | --------- |
| Block inspector main panel     | Yes             | Yes                             | Yes         | Yes       |
| Block inspector style subpanel | No              | Intro only                      | No          | No        |
| Pattern inserter               | No              | No                              | No          | No        |
| Template                       | Yes             | Yes                             | Yes         | Yes       |
| Template-part                  | Yes             | Yes                             | Yes         | Yes       |
| Style Book                     | Yes             | Yes when no matching result yet | No          | Yes       |
| Global Styles                  | Yes             | Yes when no matching result yet | No          | Yes       |

### Main UX Gaps

- Pattern remains a useful but much thinner AI surface. It now exposes explicit loading, empty, error, and success states, but it still behaves like ranking assistance rather than a full recommendation workflow.
- Block styles is intentionally lightweight, but it means the block surface is split across two different interaction models.
- The main Block panel now shares the full shell framing, but it still differs intentionally by keeping one-click apply and rendering navigation guidance as a subordinate embedded section.

## Recommended Normalization Pass

### 1. Adopt one user-facing state vocabulary

Use the same three labels everywhere:

- `Apply now`: deterministic local mutation, no preview required
- `Review first`: deterministic change, preview required before apply
- `Manual ideas`: advisory only, no apply

Then remove surface-specific synonyms such as `Suggested`, `Executable`, and `Advisory` from user-facing copy unless they describe something materially different.

Status: completed in the current normalization pass.

### 2. Standardize the full-panel skeleton

For full recommendation panels, keep this order:

1. `SurfacePanelIntro`
2. `SurfaceScopeBar`
3. `SurfaceComposer`
4. `AIStatusNotice`
5. `RecommendationHero`
6. executable lane
7. advisory lane
8. `AIReviewSection` when applicable
9. `AIActivitySection`

Block now adopts `SurfacePanelIntro` so it matches the Site Editor surfaces more closely while still keeping its one-click apply contract and embedded navigation subsection.
For the main block panel specifically, the embedded navigation section is a subordinate exception that renders after the block lanes and before activity/history.

Status: completed in the current block shell alignment pass.

### 3. Keep Template advisory suggestions bounded

Template now preserves advisory-only summaries when it cannot validate a safe deterministic apply path. Keep that contract narrow:

- executable suggestions should still be driven by validated `operations[]`
- advisory-only suggestions should stay summary-based and non-mutating
- if the contract expands, update the UI helper and per-surface docs together

Status: completed in the current template contract pass.

### 4. Standardize stale-result behavior

Keep stale results visible with a stale badge and refresh CTA across all executable recommendation surfaces.
For the lightweight block style subpanel, preserve the thinner UI but keep the same store-level freshness guard so stale direct-apply attempts fail safely.

Status: completed in the current stale-state alignment pass.

### 5. Standardize review placement

Keep review in a dedicated shared review panel below the lanes for all preview-first surfaces.

Status: completed in the current review-placement alignment pass.

### 6. Fill the composer affordance gaps

Template and Template-Part should likely gain:

- 2-3 starter prompts
- short helper text about safe bounded operations
- an explicit submit hint if keyboard submission is expected

Status: completed for Template and Template-Part in the current normalization pass.

### 7. Document Pattern as intentionally different

Pattern should remain outside the full review/apply model unless the product explicitly wants a recommendation sidebar for patterns. Today it is better described as an inserter ranking assist surface than as a full AI recommendation panel.

Status: completed for the current thin-surface state pass. Pattern now keeps its inserter-ranking role while surfacing explicit loading, empty, error, and success states.
