# Release Surface Next Steps

This directory breaks the release scope in
[`../release-surface-scope-review.md`](../release-surface-scope-review.md) into
one working document per Flavor Agent surface.

Use these files when planning, reviewing, or validating a release-bound change
for a specific surface. The source of truth for the overall product boundary is
still the release surface scope review.

## Surface Docs

| Surface | Doc |
| --- | --- |
| Block recommendations | [`block-recommendations.md`](block-recommendations.md) |
| Pattern recommendations | [`pattern-recommendations.md`](pattern-recommendations.md) |
| Content recommendations | [`content-recommendations.md`](content-recommendations.md) |
| Navigation recommendations | [`navigation-recommendations.md`](navigation-recommendations.md) |
| Template recommendations | [`template-recommendations.md`](template-recommendations.md) |
| Template-part recommendations | [`template-part-recommendations.md`](template-part-recommendations.md) |
| Global Styles | [`global-styles.md`](global-styles.md) |
| Style Book | [`style-book.md`](style-book.md) |
| AI Activity and undo | [`ai-activity-and-undo.md`](ai-activity-and-undo.md) |
| Settings and pattern sync | [`settings-and-pattern-sync.md`](settings-and-pattern-sync.md) |
| Helper abilities and REST | [`helper-abilities-and-rest.md`](helper-abilities-and-rest.md) |

## Shared Release Rules

- Keep every surface inside native WordPress decision points.
- Keep advisory surfaces advisory.
- Only ship mutation where Flavor Agent can validate, review, record, and undo
  the change.
- Degrade clearly when providers, embeddings, Qdrant, capabilities, or
  WordPress surface support are unavailable.
- Do not turn any surface into a second product such as a provider router,
  observability dashboard, site agent, pattern inserter, or general mutation
  framework.

