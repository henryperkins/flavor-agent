# Global Styles Release Surface

Source: [`../release-surface-scope-review.md`](../release-surface-scope-review.md#global-styles)

## Release Role

Global Styles belongs because theme-wide visual decisions are native Site Editor
work. Flavor Agent can help when it reviews proposed `theme.json` changes before
applying them.

Release verdict: keep as guarded.

Release quality: release-ready for guarded `theme.json` review/apply/undo;
incomplete for strong accessibility or design-quality claims.

## Stop Line

Ship:

- Validated `theme.json` paths.
- Preset-backed values where required.
- Review-first apply.
- Undo only while live config matches the recorded post-apply state.
- Theme style variation handling where supported.

Do not ship:

- Raw CSS.
- `customCSS`.
- Arbitrary selector mutation.
- Full visual redesign generation.
- Provider-driven design system ownership.

## Next Steps

- [x] Add deterministic contrast/readability validation before executable color
  suggestions are treated as release-quality design recommendations.
  `StyleContrastValidator` performs WCAG AA checks server-side per the
  Stage B Design Commitments.
- [x] Prefer paired foreground/background operations when one color change alone
  could create poor contrast. `StylePrompt::build_system()` now nudges the
  model toward paired emission, and the validator evaluates within-suggestion
  pairs first.
- [x] Classify low-contrast or unsupported combined results as advisory.
  `StylePrompt::validate_suggestions()` downgrades via `$effective_operations`
  with a canonical `Contrast check:` or `Contrast check unavailable:`
  annotation.
- [x] Preserve grouped operations as one review-safe transaction when splitting
  would create a bad intermediate state. The server parser now downgrades any
  suggestion to advisory when validation drops part of its operation sequence,
  and the client applier still writes only after every grouped operation passes.
- [x] Keep design-quality claims limited until contrast/readability validation
  exists. WCAG AA contrast validation now ships as of Stage B.

## Stage B Design Commitments

Agreed contract decisions for the contrast/readability work that closes the
four open Next Steps above. These constrain the validator implementation; any
deviation should update this section first.

- **Authority** — `StylePrompt::validate_suggestions()` is the single
  place that downgrades a suggestion from executable to advisory for
  contrast reasons; suggestions reach the editor with their final tone
  already set. Apply-time freshness is enforced by the existing
  review/resolved signature checks —
  `StyleAbilities::build_review_context_signature()` already hashes the
  slug:hex `colors` list, so any palette change marks the suggestion
  stale before apply. No separate JS contrast drift guard is planned.
- **Threshold** — WCAG AA, single 4.5:1 ratio across body text, UI, and
  headings. Large-text exemptions, AAA, and color-blindness simulation are
  out of scope for v1.
- **Pairing source** — Element pairing is sourced from
  `themeTokens['elementStyles']` (already produced by
  `ThemeTokenCollector::collect_element_styles()`); do not duplicate the
  structure. Solo color ops evaluate against the merged complement at the
  same scope; when the complement resolves only through a CSS variable
  cascade with no recorded value, skip the check and downgrade the
  suggestion to advisory with an explicit reason in `description`.
- **Out of scope (v1)** — `set_theme_variation` (theme-authored, trusted),
  custom colors outside the palette, gradients, and duotone.
- **Implementation split** — Build a minimal PHP `StyleContrastValidator`
  (hex → relative luminance → ratio → AA check); core ships no PHP
  equivalent. No JS contrast utility is planned: contrast freshness rides
  on the existing review/resolved signature machinery and the apply path's
  `resolveSignatureOnly` gate, both of which already detect any palette or
  merged-config change that would invalidate a contrast result. Hex inputs
  for the server validator are already in the prompt context at
  `themeTokens['colorPresets'][].color`.
- **Prompt and copy** — `StylePrompt::build_system()` should encourage
  paired foreground/background operations whenever a color change is
  recommended, so partial emissions trip the existing Stage A drop guard.
  Advisory-downgraded suggestions annotate `description` with the failing
  pair and computed ratio.
- **Upstream alignment** — Project 240 snapshot 2026-04-28 shows no active
  contrast, accessibility, or readability work across `WordPress/ai`,
  `WordPress/wp-ai-client`, `WordPress/abilities-api`, or
  `WordPress/php-ai-client`; no PHP contrast helper exists in core today.
  Re-run the upstream check (per
  [`../wordpress-ai-roadmap-tracking.md`](../wordpress-ai-roadmap-tracking.md))
  before landing the validator if the snapshot is older than two weeks.

## Verification Gate

- [ ] Re-run Global Styles WP 7.0 flows after validator or copy changes.
