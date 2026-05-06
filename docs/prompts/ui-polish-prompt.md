# Flavor Agent UI Polish Prompt

You are working in the `henryperkins/flavor-agent` WordPress plugin repository. Polish the UI, styling, and interaction quality across Flavor Agent's editor and admin recommendation surfaces without changing core product contracts or generated build artifacts.

## Goal

Improve visual consistency, clarity, accessibility, and perceived reliability across these areas:

1. Block editor UI/styling
2. Admin settings dashboard
3. AI Activity admin screen
4. Apply/undo workflow
5. Pattern recommendation panels
6. Block style inspection
7. Template recommendations
8. Global Styles / Style Book style-apply workflow
9. Content generation panel

The result should feel cohesive across Gutenberg editor panels, Site Editor panels, wp-admin screens, recommendation cards, stale-result states, review-before-apply flows, inline feedback, and undo toasts.

## Repository context

This is a WordPress plugin requiring WP 7.0+ and PHP 8.0+. Editor/admin source lives in `src/`; PHP backend lives in `inc/`. Do not hand-edit `build/`, `dist/`, or other generated artifacts.

Key files and areas to inspect before changing code:

- `src/editor.css`
- `src/admin/settings.css`
- `src/admin/activity-log.js`
- `src/admin/activity-log-utils.js`
- `src/admin/settings-page.js`
- `src/admin/settings-page-controller.js`
- `src/components/*`
- `src/inspector/BlockRecommendationsPanel.js`
- `src/inspector/NavigationRecommendations.js`
- `src/patterns/PatternRecommender.js`
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`
- `src/content/ContentRecommender.js`
- `src/store/activity-undo.js`
- `src/store/toasts.js`
- `src/store/executable-surfaces.js`

## Design direction

Use the existing Flavor Agent visual language and WordPress admin/editor conventions. Prefer small, systematic improvements over large redesigns.

Focus on:

- Cleaner hierarchy: clearer section titles, metadata, scope labels, empty states, and action affordances.
- Consistent spacing: reuse existing editor/admin spacing tokens where available.
- Better recommendation cards: clearer primary action, secondary actions, confidence/freshness/status indicators, and explanatory text.
- Better stale/error/loading states: make refresh/retry paths obvious and reduce ambiguous messaging.
- Better apply/undo feedback: make applied state, undo availability, and stale-before-apply warnings more legible.
- Better admin settings dashboard: improve grouping, scanability, status panels, provider/backend readiness messaging, and sync controls.
- Better AI Activity screen: improve filters, summaries, row/card readability, action labels, and empty/error states.
- Better content-generation UX: make draft/edit/critique modes easier to distinguish and make generated output feel editorial/reviewable rather than auto-applied.
- Better template/style workflows: distinguish advisory suggestions from executable operations, make review-before-apply clearer, and improve inline undo confidence.
- Better pattern recommendation panels: ensure the local Flavor Agent inserter shelf feels intentional, scoped, and non-conflicting with Gutenberg's native pattern registry.
- Better block style inspection: improve visual grouping of inspector-derived suggestions and style-panel guidance.

## Constraints

- Preserve existing behavior and contracts unless a change is explicitly needed for polish.
- Do not mutate Gutenberg's native pattern registry, pattern metadata, or categories.
- Do not bypass review-before-apply protections for executable template/style operations.
- Do not weaken capability checks, nonces, REST/Abilities contracts, stale-result handling, or undo validation.
- Keep `contentOnly` and `disabled` editing restrictions intact.
- Preserve nested-merge behavior for `metadata` and `style` attribute updates.
- Keep CSS line endings consistent with the existing files.
- Prefer shared components over one-off UI.
- Avoid broad `try/catch` blocks or silent fallback behavior.
- Add or update tests when logic changes. Pure CSS-only polish may not need tests.

## Implementation approach

1. Audit the shared UI components first:
   - `SurfacePanelIntro`
   - `SurfaceScopeBar`
   - `SurfaceComposer`
   - `RecommendationHero`
   - `RecommendationLane`
   - `AIStatusNotice`
   - `AIReviewSection`
   - `AIAdvisorySection`
   - `InlineActionFeedback`
   - `StaleResultBanner`
   - `ToastRegion`
   - `UndoToast`
   - `CapabilityNotice`

2. Identify reusable polish opportunities:
   - shared class names
   - shared spacing
   - shared tone/status treatment
   - shared empty-state language
   - shared action layout
   - shared recommendation card structure

3. Apply improvements across surfaces rather than fixing only one panel:
   - block recommendations
   - pattern recommendations
   - content recommendations
   - template recommendations
   - template-part recommendations
   - navigation recommendations
   - Global Styles recommendations
   - Style Book recommendations
   - admin settings
   - AI Activity

4. Keep the patch surgical:
   - update source files only
   - avoid unrelated refactors
   - preserve existing APIs
   - keep labels translatable where existing code uses WordPress i18n

## Acceptance criteria

The polish is complete when:

- Editor panels feel visually consistent across all recommendation surfaces.
- Apply/review/undo states are clearer and harder to misinterpret.
- Stale results, capability notices, and unavailable-backend states are easier to act on.
- Admin settings and AI Activity screens are easier to scan and understand.
- Pattern recommendations remain scoped to Flavor Agent's local inserter shelf.
- Template/style operations still require review where expected.
- Content generation remains editorial-only and does not mutate post content automatically.
- No existing tests regress.
- No generated files are manually edited.

## Verification

Run the closest practical validation commands for the changed files:

```bash
npm run build
npm run test:unit -- --runInBand
npm run lint:js
composer lint:php
vendor/bin/phpunit
```

Because this touches multiple recommendation surfaces and shared UI/workflow infrastructure, also run:

```bash
node scripts/verify.js --skip-e2e
```

If contracts, contributor docs, or surface behavior docs change, run:

```bash
npm run check:docs
```

If browser validation is available, run the matching Playwright suites:

```bash
npm run test:e2e:playground
npm run test:e2e:wp70
```

If a browser harness is unavailable or known-red, record that explicitly instead of silently skipping it.

## Final response

Summarize the meaningful UI/UX changes, list the files touched, and report the validation commands that were run with their outcomes.
