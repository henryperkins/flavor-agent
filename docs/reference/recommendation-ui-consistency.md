# Recommendation UI Consistency Review

Cross-surface comparison of the Flavor Agent recommendation UI across:

- `src/inspector/BlockRecommendationsPanel.js`
- `src/inspector/NavigationRecommendations.js`
- `src/content/ContentRecommender.js`
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

That pattern is strongest in Style Book and Global Styles, mostly present in Template and Template-Part, fully present in the main Block panel, partially present in the advisory-only Navigation and Content shells, and intentionally reduced or absent in the Pattern inserter affordance and the passive block Inspector sub-panel mirrors.

## Prompt-Layer Contract

| Surface        | Prompt contract                                                                                                                                                                       | Real executable/advisory split       |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ |
| Block          | `Prompt.php` allows executable local block mutations plus advisory-only `structural_recommendation` and `pattern_replacement` items                                                   | Yes                                  |
| Block settings mirrors | Uses the grouped block recommendation payload but only as passive mirrored chips inside delegated Inspector sub-panels                                                             | No                                   |
| Block style mirrors   | Uses the grouped block recommendation payload but only as passive mirrored chips inside delegated Inspector sub-panels                                                             | No                                   |
| Content        | `WritingPrompt.php` returns draft, edit, or critique payloads with content, notes, and issues for editorial review only                                                   | Editorial/review-only               |
| Navigation     | `NavigationPrompt.php` returns advisory-only suggestion groups with validated `changes[]` metadata and no Flavor Agent apply path                                                     | Advisory-only                        |
| Pattern        | Ranking and inserter patching, not a review/apply prompt flow                                                                                                                         | No lane split                        |
| Template       | `TemplatePrompt.php` keeps `operations[]` as the executable source of truth when present and preserves validated advisory-only summaries when no safe deterministic apply path exists | Yes, with bounded advisory fallbacks |
| Template-part  | `TemplatePartPrompt.php` is advisory-first and only returns operations when the change is fully safe and deterministic                                                                | Yes                                  |
| Style Book     | `StylePrompt.php` returns `tone=executable` or `tone=advisory`; executable items must carry valid operations                                                                          | Yes                                  |
| Global Styles  | `StylePrompt.php` returns `tone=executable` or `tone=advisory`; executable items must carry valid operations                                                                          | Yes                                  |

## Shared Component Matrix

`NavigationRecommendations.js` supports both the embedded block subsection and a standalone fallback shell, so the matrix marks shared pieces as partial where those variants intentionally diverge. Delegated block Inspector sub-panels now use passive `SuggestionChips` mirrors only, so they are summarized as one lightweight row rather than separate executable surfaces.

| Surface                        | `RecommendationLane` | `RecommendationHero` | `AIAdvisorySection` | `AIReviewSection` | `AIActivitySection` | `SurfaceComposer` | `SurfacePanelIntro` | `SurfaceScopeBar` | `AIStatusNotice` | `CapabilityNotice` | Notes                                                                                |
| ------------------------------ | -------------------- | -------------------- | ------------------- | ----------------- | ------------------- | ----------------- | ------------------- | ----------------- | ---------------- | ------------------ | ------------------------------------------------------------------------------------ |
| Block inspector main panel     | Yes                  | Yes                  | Yes                 | No                | Yes                 | Yes               | Yes                 | Yes               | Yes              | Yes                | One-click apply plus stale-result hero and embedded navigation subsection            |
| Block inspector passive subpanels | No                | No                   | No                  | No                | No                  | No                | No                  | No                | No               | No                 | Passive mirrored `SuggestionChips` only; no direct apply, stale refresh, or activity surface |
| Content document panel         | No                   | Yes                  | Yes                 | No                | Yes                 | Yes               | Yes                 | No                | Yes              | Yes                | Editorial-only post/page panel with read-only request history                        |
| Navigation embedded / standalone | Partial            | Partial              | No                  | No                | No                  | Yes               | Partial             | Partial           | Yes              | Yes                | Advisory-only surface; standalone fallback uses scope/lane/hero, embedded flow uses lighter custom sections and stale banner |
| Pattern inserter affordance    | No                   | No                   | No                  | No                | No                  | No                | No                  | No                | No               | Yes                | Injected local shelf plus loading, empty, error, and setup notices                   |
| Template                       | Yes                  | Yes                  | Yes                 | Yes               | Yes                 | Yes               | Yes                 | Yes               | Yes              | Yes                | Preview-first structural surface                                                     |
| Template-part                  | Yes                  | Yes                  | Yes                 | Yes               | Yes                 | Yes               | Yes                 | Yes               | Yes              | Yes                | Preview-first structural surface                                                     |
| Style Book                     | Yes                  | Yes                  | Yes                 | Yes               | Yes                 | Yes               | Yes                 | Yes               | Yes              | Yes                | Portal-first Styles sidebar surface                                                  |
| Global Styles                  | Yes                  | Yes                  | Yes                 | Yes               | Yes                 | Yes               | Yes                 | Yes               | Yes              | Yes                | Portal-first Styles sidebar surface                                                  |

## Executable And Advisory Lane Copy

| Surface                        | Executable lane                                            | Advisory lane                           | Card or badge labels                                                                                  | Copy pattern                                           |
| ------------------------------ | ---------------------------------------------------------- | --------------------------------------- | ----------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| Block inspector main panel     | `Apply now` with tone `Apply now` or `Stale`               | `Manual ideas` via `AIAdvisorySection`   | Hero uses `Apply now` or `Manual ideas`; advisory section now also shows `Advisory only`              | Clear direct-apply wording for local block attributes  |
| Block inspector passive subpanels | None                                                   | None                                    | Mirror labels come from the delegated chip group title only                                            | Context-only mirrors of the main block result          |
| Content                        | None                                                       | `Editorial Notes` via `AIAdvisorySection` | Hero eyebrow is `Latest Content Recommendation`; mode pill is `Draft`, `Edit`, or `Critique`           | Editorial output and review notes, no apply lane       |
| Navigation                     | None                                                       | `Recommended Next Changes` in the unmounted standalone shell; embedded sections keep the same manual tone without `AIAdvisorySection` | Category pills plus change counts; wrapper titles are `Navigation Ideas` / `Recommended next change` in the mounted embedded flow | Advisory-only navigation guidance in a lighter nested shell |
| Pattern inserter               | None                                                       | None                                    | Summary and state notices use `Flavor Agent`, recommendation count pills, and retry text when needed  | Ranking affordance, not lane-based                     |
| Template                       | `Review first` with tone `Review first`                    | `Manual ideas` via `AIAdvisorySection`  | Card pills show `Review first`, `Manual ideas`, `Advisory only`, and `Review open`; button uses `Review` / `Reviewing` | Preview-confirm flow with bounded advisory fallbacks   |
| Template-part                  | `Review first` with tone `Review first`                    | `Manual ideas` via `AIAdvisorySection`  | Card pills show `Review first`, `Manual ideas`, `Advisory only`, and `Review open`; button uses `Review` / `Reviewing` | Best match for a true advisory-first structure surface |
| Style Book                     | `Review first` with tone `Review first`                    | `Manual ideas` via `AIAdvisorySection`  | Card badges use `Review first`, `Manual ideas`, `Advisory only`, and `Review open`                    | Cleanest standardized style-surface vocabulary         |
| Global Styles                  | `Review first` with tone `Review first`                    | `Manual ideas` via `AIAdvisorySection`  | Card badges use `Review first`, `Manual ideas`, `Advisory only`, and `Review open`                    | Mirrors Style Book vocabulary                          |

### Copy Inconsistencies

- The main recommendation surfaces now share the same three user-facing labels: `Apply now`, `Review first`, and `Manual ideas`.
- The advisory badge policy is now standardized through `AIAdvisorySection`; full-panel advisory surfaces all show `Advisory only` unless a future surface has a documented reason to suppress it.
- Navigation keeps `Navigation Ideas` as the mounted embedded wrapper title and `Recommended next change` for the featured card; the standalone component branch still uses `Recommended Next Changes` but is not mounted by the plugin.
- Block settings and block styles no longer act as second apply surfaces. They are passive mirrors of the main block panel result.

## Review-Before-Apply Contract

| Surface                        | Apply model                                         | Review model                                       | Confirm step           |
| ------------------------------ | --------------------------------------------------- | -------------------------------------------------- | ---------------------- |
| Block inspector main panel     | One-click apply for safe local block updates        | None                                               | No                     |
| Block inspector passive subpanels | No apply path                                   | None                                               | No                     |
| Content                        | No apply path                                       | Editorial review only                              | No                     |
| Navigation                     | No apply path                                       | No preview/apply review contract; advisory follow-through only | No         |
| Pattern inserter               | User inserts through core inserter UI               | Core pattern preview only                          | Core handles insertion |
| Template                       | Select suggestion for review                        | Separate `AIReviewSection` renders below the lanes | Yes                    |
| Template-part                  | Select suggestion for review                        | Separate `AIReviewSection` renders below the lanes | Yes                    |
| Style Book                     | Select suggestion for review                        | Separate `AIReviewSection` renders below the lanes | Yes                    |
| Global Styles                  | Select suggestion for review                        | Separate `AIReviewSection` renders below the lanes | Yes                    |

### Practical Difference

- The main Block panel is the only one-click apply block surface. Delegated native sub-panels now mirror the latest result but do not apply anything directly.
- Content remains editorial-only. It can generate drafts, edits, critiques, and review notes, but it does not mutate post content or enter preview/apply.
- Navigation remains advisory-only. It owns its own request and stale-refresh shell, but it does not participate in the review-before-apply contract.
- Template, Template-Part, Style Book, and Global Styles all preserve the review-before-apply contract and now present review with the same broad structure.
- Those preview-first surfaces use a dedicated review panel below the lanes.
- The active card stays visible as selected with `Review open`, while the shared lower panel owns confirm and cancel.

## Undo, Activity History, And Stale State

| Surface                        | Activity history | Undo model                                                                                        | Stale-result handling                                                                                                               |
| ------------------------------ | ---------------- | ------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| Block inspector main panel     | Yes              | Shared latest-valid tail undo; block snapshot must still match                                    | Keeps stale results visible, marks them stale, disables apply, offers refresh                                                       |
| Block inspector passive subpanels | No            | No surface-level undo                                                                             | Mirrors the latest result passively; stale treatment stays on the main block panel rather than creating a second stale-management surface |
| Content                        | Read-only request history | No Flavor Agent undo                                                                  | No stale UI                                                                                                                         |
| Navigation                     | No inline history; scoped audit rows only | No Flavor Agent undo                                                               | Keeps stale results visible, marks them stale, and offers refresh from the navigation surface                                       |
| Pattern inserter               | No inline history; scoped audit rows only | No Flavor Agent undo                                                              | No stale UI                                                                                                                         |
| Template                       | Yes              | Shared latest-valid tail undo; validated undo preparation must still succeed                      | Keeps stale results visible, marks them stale, disables apply, offers refresh                                                       |
| Template-part                  | Yes              | Shared latest-valid tail undo; validated undo preparation must still succeed                      | Keeps stale results visible, marks them stale, disables apply, offers refresh                                                       |
| Style Book                     | Yes              | Shared latest-valid tail undo; block style branch must still match the recorded post-apply config | Keeps stale results visible, marks them stale, disables apply, offers refresh                                                       |
| Global Styles                  | Yes              | Shared latest-valid tail undo; live config must still match the recorded post-apply config        | Keeps stale results visible, marks them stale, disables apply, offers refresh                                                       |

### Shared Undo Rule

All executable history surfaces depend on `src/store/activity-history.js` for ordered undo resolution. Only the newest still-valid AI action in a scope can be undone. Surface-specific runtime validators then decide whether the target still matches closely enough for automatic undo.

### Consistency Gaps

- Block, Navigation, Template, Template-Part, Style Book, and Global Styles now preserve the previous result, mark it stale, disable execution as needed, and offer refresh from the surface that owns the request lifecycle.
- Block settings and block styles preserve stale projected results, but they intentionally do not own refresh. They disable apply and send the user back to the main block `AI Recommendations` panel to refresh the source request.
- Shadow block suggestions use `panel: "shadow"` in the recommendation contract and are mirrored inside Gutenberg's Border/Shadow inspector group, because Gutenberg exposes shadow controls through the border group rather than a standalone shadow group.
- Content exposes inline read-only `Recent Content Requests`, but it has no undo affordance because no Flavor Agent-owned apply occurs.
- Navigation still exposes no inline activity section or undo affordance, though scoped read-only `request_diagnostic` rows now land in the admin audit surface.
- Pattern still exposes no inline activity or undo affordance, which keeps it separate from the fuller recommendation surfaces even though scoped read-only `request_diagnostic` rows can land in the admin audit surface.

## Prompting And Composer Gaps

| Surface                        | Starter prompts | Secondary helper text           | Submit hint | Scope bar |
| ------------------------------ | --------------- | ------------------------------- | ----------- | --------- |
| Block inspector main panel     | Yes             | Yes                             | Yes         | Yes       |
| Block inspector settings subpanel | No          | No                              | No          | No        |
| Block inspector style subpanel | No              | No                              | No          | No        |
| Content                        | Yes             | Yes                             | No          | No        |
| Navigation                     | Yes             | Yes                             | No          | Partial: standalone scope bar, embedded stale banner |
| Pattern inserter               | No              | No                              | No          | No        |
| Template                       | Yes             | Yes                             | Yes         | Yes       |
| Template-part                  | Yes             | Yes                             | Yes         | Yes       |
| Style Book                     | Yes             | Yes when no matching result yet | Yes         | Yes       |
| Global Styles                  | Yes             | Yes when no matching result yet | Yes         | Yes       |

### Intentional Loading Differences

- Template and Template-Part keep explicit `Analyzing … structure…` notices because the user is waiting on structural validation that may or may not yield executable operations.
- Block, Style Book, and Global Styles rely on the composer button's loading label while the rest of the surface shell stays visible, which is acceptable because those panels keep the active scope and lane context on screen during the request.
- Pattern remains a thin ranking surface and uses inserter-local loading, empty, error, and success notices rather than the shared panel status shell.

### Resolved Product-Model Decisions

- Block advisory suggestions now use `AIAdvisorySection`. The block panel remains a direct-apply exception only for executable local block updates.
- Navigation keeps `Navigation Ideas` as an embedded wrapper title and `Recommended next change` as the featured-card title, while the actual advisory taxonomy remains the shared manual-follow-through tone. Treat this as an intentional nested-surface exception, not drift.
- Delegated Settings and Styles sub-panels remain passive mirrors. They reflect safe local results from the main block request and intentionally do not own composer, refresh, capability, activity, or apply state.
- Pattern recommendations remain ranking/browse-only and intentionally stay outside the lane/review/apply/undo contract.
- Style Book and Global Styles remain portal-first Styles-sidebar surfaces with document-panel fallback; some mount-context divergence from inspector/document panels is expected and acceptable.

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
6. supporting explanation or rationale copy
7. executable lane
8. advisory lane
9. `AIReviewSection` when applicable
10. `AIActivitySection`

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

Keep stale results visible with a stale badge and refresh CTA on the surfaces that own their request lifecycle.
For the lightweight block settings and style projection surfaces, preserve the thinner UI, keep the same store-level freshness guard so stale direct-apply attempts fail safely, and route refresh back to the main block `AI Recommendations` panel instead of creating a second request lifecycle.

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
