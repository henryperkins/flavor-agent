# Content Recommendation Latest Recommendation Workspace

**Status:** Approved direction, awaiting implementation
**Date:** 2026-05-22
**Author:** Henry Perkins (with Codex)
**Tracker:** Content Recommendations UI overload review (this session)

## Context

The post/page `Content Recommendations` document panel currently puts the request composer, the latest result, editorial notes, and recent request diagnostics into one narrow vertical stack. After one critique request, the user sees the prompt controls, generated critique, many note/issue cards, and request history at nearly the same visual priority.

That creates cognitive overload. The content lane is editorial-only and has no apply/undo flow, so the panel should make the latest recommendation easy to read and act on, while moving secondary evidence and audit detail behind intentional disclosure.

The stale-result contract is already present in the current checkout: stored content request signatures are compared against live mode, prompt, and post context, stale results are marked, and copying stale generated content is disabled. This design keeps that trust model and changes the information architecture around it.

## Goal

Turn the content recommendation panel into a **Latest Recommendation Workspace**:

- Before a request, help the user ask for a draft, edit, or critique with minimal setup.
- After a request, make the latest recommendation the primary object on screen.
- Keep refinement, notes, issues, and request diagnostics available without making them compete with the result.
- Preserve the editorial-only contract: no automatic content mutation, no apply path, and no Flavor Agent undo for content.

## Non-goals

- Adding an apply, preview, or undo flow for content recommendations.
- Changing the `flavor-agent/recommend-content` ability input/output schema.
- Changing model prompting or response parsing in `WritingPrompt`.
- Removing request diagnostics from the admin activity/audit surface.
- Reworking other recommendation surfaces.

## Product Principles

1. **One decision at a time.** After generation, the panel should answer: "What did Flavor Agent just recommend, and what can I do with it?"
2. **Result first, evidence second.** `contentRecommendation.content` is the main answer, especially for critique mode. `notes[]` and `issues[]` support the answer.
3. **Refinement is available, not dominant.** Users should be able to adjust the prompt or mode, but the prompt form should not stay louder than the result after a successful request.
4. **Audit stays out of the way.** Recent request diagnostics remain accessible, but inline history should not become a feed inside the writing workspace.
5. **Trust interruptions stay prominent.** Setup, connector approvals, errors, and stale-result banners remain above or adjacent to the result because they affect whether the user can rely on the output.

## Interaction Design

### Empty State

When there is no stored result:

- Show the compact document context at the top:
  - document title or an untitled fallback
  - post type chip
  - status chip when available
- Show the full `SurfaceComposer`:
  - mode selector: `Draft`, `Edit`, `Critique`
  - prompt textarea
  - starter prompts
  - mode-specific generate button
- Show capability or connector notices only when they are relevant.
- Hide the activity section when there are no matching entries.

### Latest Result State

When a stored result exists:

- Keep the same compact document context.
- Replace the always-open composer with a **Refine request** disclosure:
  - collapsed by default after a successful request
  - shows the active mode and a one-line prompt summary
  - opens the full composer for prompt/mode changes
  - keeps starter prompts available only when expanded
- Render the latest recommendation as the primary card:
  - title from `contentRecommendation.title` or a mode fallback
  - summary when present
  - body from `contentRecommendation.content`
  - copy action only when body text exists and the result is fresh
- Render notes/issues in a secondary **Editorial notes** disclosure:
  - collapsed by default
  - header shows the total note/issue count
  - when opened, show the first three note/issue cards and a `Show more` affordance for overflow
  - issue cards should be visually quieter than the result card
- Render recent request diagnostics in a secondary **Recent content requests** disclosure:
  - collapsed by default
  - show only the latest inline entry before `Show more`
  - keep per-entry `View activity` links for deeper audit detail

### Critique Mode

Critique responses should not look like a pile of independent notes. The primary critique text from `contentRecommendation.content` is the main read. `notes[]` and `issues[]` are supporting review material that can be expanded when the user wants more detail.

### Error And Stale States

- Connector setup and connector approval notices stay above the workspace.
- Request errors stay visible through `AIStatusNotice` and do not require opening the composer.
- Stale-result banners stay above the stored result and retain the refresh action.
- A stale result can remain readable, but copy stays disabled until refreshed.
- Expanding the composer for a stale result should make it clear that generating again will refresh the workspace.

## Component Design

### `ContentRecommender.js`

Add a small local workspace state:

- `isComposerOpen`: defaults to `false` when a stored result exists and `true` otherwise; it also collapses after a newly successful fresh result.
- `hasResult`: continues to come from `getContentRecommendationFreshness()`.
- `isStaleResult`: continues to gate stale messaging and copy disabling.

Add small internal render helpers rather than broad new abstractions:

- `ContentRequestSummary`: collapsed row for mode, prompt summary, and refine action.
- `ContentIssueCard`: keep existing responsibility, but allow quieter compact styling.
- Optional `ContentEditorialNotes`: thin wrapper around `AIAdvisorySection` if the JSX becomes hard to read.

Keep the store contract unchanged. The component should continue to dispatch `fetchContentRecommendations()` with `mode`, `prompt`, and live `postContext`.

### `SurfaceComposer`

No functional contract change is required. The content panel can choose whether to render it expanded or collapsed. If implementation reveals repeated disclosure needs across surfaces, add a minimal prop only after proving it is not content-specific.

### `AIAdvisorySection`

Use existing progressive disclosure:

- `initialOpen={ false }`
- `maxVisible={ 3 }` for content notes/issues

Avoid changing global defaults unless another surface needs the same behavior.

### `AIActivitySection`

Use existing progressive disclosure:

- `initialOpen={ false }`
- `maxVisible={ 1 }` for content

If a section-level admin activity link is needed, add it as a backward-compatible optional prop. Do not remove existing per-entry links.

### CSS

Update `src/editor.css` for content-specific hierarchy:

- compact refine summary row
- quieter secondary note/issue cards under the content panel
- tighter spacing between document context, stale/status notices, and latest result
- no new decorative backgrounds or heavy cards inside cards

Stay within the existing WordPress/editor token palette and the current Flavor Agent component classes.

## Data Flow

The request and freshness flow stays the same:

1. User selects a mode and prompt.
2. `ContentRecommender` dispatches `fetchContentRecommendations()`.
3. The store calls `flavor-agent/recommend-content`.
4. The store saves the recommendation and request signature.
5. `ContentRecommender` compares stored signature to the live mode, prompt, and post context.
6. The workspace renders the latest stored result, marking it stale when the signatures differ.

No generated text, prompt text, or issue text should be written to activity diagnostics beyond the existing privacy-safe request diagnostic behavior.

## Accessibility

- The refine disclosure must use a real `button` with `aria-expanded`.
- The notes and activity disclosures continue to use accessible toggle buttons.
- The collapsed prompt summary must not be the only place the selected mode is exposed; the expanded composer still has the labeled mode group.
- Copy buttons remain disabled with native disabled semantics when stale.
- Text must wrap cleanly in the narrow editor sidebar.

## Test Plan

### Unit Tests

Update `src/content/__tests__/ContentRecommender.test.js`:

- Before any request, the full composer is visible.
- After a ready result, the result text is visible and the composer is collapsed behind `Refine request`.
- Clicking `Refine request` reopens the composer with the stored prompt and active mode.
- Fresh generated content can still be copied.
- Stale generated content still shows the stale banner and copy remains disabled.
- Editorial notes are collapsed by default after a result and can be expanded.
- Recent content requests are collapsed by default and show only the latest inline entry before overflow.
- Critique mode treats `contentRecommendation.content` as the primary visible result while issues remain secondary.

### E2E Tests

Update the content recommendation section in `tests/e2e/flavor-agent.smoke.spec.js`:

- Generate a draft/edit/critique as today.
- Assert the latest result remains visible after generation.
- Assert the refine control is available after generation.
- Assert editorial notes are not all dumped open by default; expand them before checking note/issue text.

### Verification Commands

```bash
npm run test:unit -- src/content/__tests__/ContentRecommender.test.js
npx wp-scripts lint-js src/content/ContentRecommender.js src/content/__tests__/ContentRecommender.test.js
git diff --check
```

If implementation touches shared components or docs, add:

```bash
node scripts/verify.js --skip-e2e
npm run check:docs
```

If implementation changes E2E assertions, run the relevant WP 7.0 harness when the local WordPress environment is representative.

## Documentation Updates

Implementation should update:

- `docs/features/content-recommendations.md` - describe the Latest Recommendation Workspace behavior.
- `docs/SOURCE_OF_TRUTH.md` - update the content recommendation UI summary if wording changes.
- `docs/FEATURE_SURFACE_MATRIX.md` - update the content surface row if disclosure/default state changes are documented there.
- `docs/reference/recommendation-ui-consistency.md` - replace the outdated "Content has no stale UI" row with the current stale-banner behavior and document the compact workspace state.

Run `npm run check:docs` if any contributor-facing behavior text changes.

## File Inventory

| Action | File |
|---|---|
| Edit | `src/content/ContentRecommender.js` |
| Edit | `src/content/__tests__/ContentRecommender.test.js` |
| Edit | `src/editor.css` |
| Edit | `tests/e2e/flavor-agent.smoke.spec.js` |
| Edit | `docs/features/content-recommendations.md` |
| Edit | `docs/SOURCE_OF_TRUTH.md` |
| Edit | `docs/FEATURE_SURFACE_MATRIX.md` |
| Edit | `docs/reference/recommendation-ui-consistency.md` |

Shared component edits are optional and should happen only when they reduce duplication without changing other surfaces unexpectedly.

## Risks

1. **Hidden controls can feel lost.** The collapsed composer must clearly expose `Refine request`, not bury the ability to ask again.
2. **Test assumptions may rely on open notes.** Existing unit and E2E tests that assert note text immediately after generation need to expand notes first or check the count/header.
3. **Shared component churn.** Changing `AIAdvisorySection`, `AIActivitySection`, or `SurfaceComposer` globally could affect many surfaces. Prefer content-local composition unless a shared change is obviously backward-compatible.
4. **History still grows.** Limiting inline activity to one visible row reduces overload, but the admin activity page remains the correct full audit surface.

## Out Of Scope Followups

- A dedicated full-screen writing review surface.
- Ranking or prioritizing notes server-side.
- Copying individual notes/issues.
- Inserting generated content into the editor automatically.
- Applying the same workspace model to executable recommendation surfaces.
