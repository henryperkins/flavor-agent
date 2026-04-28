# Template Recommendation Review Prompt

```text
Review Flavor Agent template recommendations in this repository with the same evidence-first stance as the block recommendation review.

Treat this as a code review, not an implementation pass. Do not edit files. Findings must lead, ordered by severity, with exact file/line references and a clear explanation of the user-visible or security impact.

Scope:
- Primary surface: flavor-agent/recommend-template
- Include recommend-template-part only when shared code, contracts, validation, or docs affect the template recommendation path.
- Trace the full path from route/ability registration through prompt construction, response parsing, client rendering, review/confirm/apply, stale-context handling, undo/activity, tests, and docs.

Start by reading the current checkout. Grep hits are only leads; do not flag anything unless you opened the relevant code and confirmed the runtime path.

Inspect at minimum:
- inc/Abilities/Registration.php
- inc/Abilities/TemplateAbilities.php
- inc/LLM/TemplatePrompt.php
- inc/REST/Agent_Controller.php
- src/templates/TemplateRecommender.js
- src/utils/template-actions.js
- related store/request state and activity/undo code
- tests/phpunit/TemplateAbilitiesTest.php
- related JS tests under src/templates/** and src/utils/**
- docs/contracts in docs/features/*, docs/reference/abilities-and-routes.md, docs/SOURCE_OF_TRUTH.md, and STATUS.md

Focus areas:
- permission and ability-schema drift
- server/client contract mismatches
- stale reviewContextSignature / resolvedContextSignature handling
- unsafe or under-validated template operations
- invalid anchors, template-part assignments, or pattern insertion paths
- LLM prompt instructions that allow operations the validator later rejects
- cases where failed or stale recommendations can still be reviewed/applied
- missing tests for shared contracts, freshness guards, validation, or docs drift
- docs that overclaim behavior not enforced by code

Output format:
1. Findings first, ordered by severity (P0, P1, P2, P3).
2. Each finding must include exact file/line references, impact, and the smallest credible fix direction.
3. Add "Open Questions / Assumptions" only if needed.
4. Add a short "Verification Reviewed" section listing the tests/docs you inspected and any commands you ran.
5. If no findings are confirmed, say that plainly and identify remaining test gaps or residual risk.
```
