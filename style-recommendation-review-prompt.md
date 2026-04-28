# Style Recommendation Review Prompt

Date: 2026-04-28

Purpose: Evidence-first review of style recommendations for security, contract, and behavior regressions.

```text
Review Flavor Agent style recommendations in this repository with the same evidence-first stance as the template recommendation review.

Treat this as a code review, not an implementation pass. Do not edit files. Findings must lead, ordered by severity, with exact file/line references and a clear explanation of the user-visible or security impact.

Scope:
- Primary surface: flavor-agent/recommend-style
- Include both first-party callers: Global Styles and Style Book.
- Include adjacent shared code only when contracts, validation, stale handling, activity, undo, or docs affect the style recommendation path.
- Trace the full path from route/ability registration through scope/styleContext collection, prompt construction, response parsing, client rendering, review/confirm/apply, stale-context handling, undo/activity, tests, and docs.

Start by reading the current checkout. Grep hits are only leads; do not flag anything unless you opened the relevant code and confirmed the runtime path.

Inspect at minimum:
- inc/Abilities/Registration.php
- inc/Abilities/StyleAbilities.php
- inc/LLM/StylePrompt.php
- inc/REST/Agent_Controller.php
- src/global-styles/GlobalStylesRecommender.js
- src/style-book/StyleBookRecommender.js
- src/style-book/dom.js
- src/style-surfaces/request-input.js
- src/style-surfaces/presentation.js
- src/utils/style-operations.js
- src/utils/style-validation.js
- related store/request state and activity/undo code
- tests/phpunit/StyleAbilitiesTest.php
- tests/phpunit/StylePromptTest.php
- tests/phpunit/AgentControllerTest.php
- related JS tests under src/global-styles/**, src/style-book/**, src/style-surfaces/**, src/utils/**, and src/store/**
- docs/features/style-and-theme-intelligence.md
- docs/reference/abilities-and-routes.md
- docs/reference/recommendation-ui-consistency.md
- docs/reference/template-operations.md
- docs/SOURCE_OF_TRUTH.md
- STATUS.md

Focus areas:
- permission and ability-schema drift
- Global Styles vs Style Book scope mismatches
- server/client contract mismatches for scope, styleContext, suggestions, and operations
- stale reviewContextSignature / resolvedContextSignature handling
- unsafe or under-validated style operations
- invalid theme.json paths, unsupported preset values, or raw values where presets are required
- Global Styles-only operations accidentally allowed on Style Book, especially set_theme_variation
- Style Book block-scoped operations applied to the wrong blockName or unsupported path
- LLM prompt instructions that allow operations the validator later rejects
- cases where failed or stale recommendations can still be reviewed/applied
- undo/activity entries that cannot safely restore or validate live style state
- missing tests for shared contracts, freshness guards, validation, scope resolution, or docs drift
- docs that overclaim behavior not enforced by code

Output format
1. Start with findings first, ordered by severity (`P0`, `P1`, `P2`, `P3`).
2. For each finding include: title, exact file/line references, observed behavior, impact, and the smallest practical fix.
3. Keep confirmed findings and open questions separate. Add an "Open Questions / Assumptions" section only if you verified a gap.
4. Include a short "Verification Reviewed" section naming commands you ran and commands you did not run.
5. If there are no findings, say so plainly and identify remaining test or environment gaps.
6. Keep conclusions checkout-specific and evidence-backed. Confirm behavior from live code paths and do not treat stale docs or plans as higher priority than code.
```
