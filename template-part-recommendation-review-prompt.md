# Template Part Recommendation Review Prompt

```text
Review Flavor Agent template-part recommendations in /home/ubuntu/flavor-agent with the same evidence-first stance as the template and style recommendation reviews.

Treat this as a code review, not an implementation pass. Do not edit files. Findings must lead, ordered by severity, with exact file/line references and a clear explanation of the user-visible or security impact.

Scope:
- Primary surface: flavor-agent/recommend-template-part
- First-party caller: Template Part recommender in the Site Editor.
- Include adjacent shared code only when contracts, validation, stale handling, activity, undo, visible-pattern filtering, helper abilities, or docs affect the template-part recommendation path.
- Trace the full path from route/ability registration through request input, scope/context collection, prompt construction, response parsing, client rendering, review/confirm/apply, stale-context handling, undo/activity, tests, and docs.

Start by reading the current checkout. Grep hits are only leads; do not flag anything unless you opened the relevant code and confirmed the runtime path.

Inspect at minimum:
- inc/Abilities/Registration.php
- inc/Abilities/TemplateAbilities.php
- inc/Context/TemplatePartContextCollector.php
- inc/Context/ServerCollector.php
- inc/LLM/TemplatePartPrompt.php
- inc/REST/Agent_Controller.php
- src/template-parts/TemplatePartRecommender.js
- src/template-parts/template-part-recommender-helpers.js
- src/utils/template-actions.js
- src/utils/template-operation-sequence.js
- src/utils/template-part-areas.js
- src/utils/visible-patterns.js
- related store/request state and activity/undo code
- tests/phpunit/TemplateAbilitiesTest.php
- tests/phpunit/TemplatePartPromptTest.php
- tests/phpunit/AgentControllerTest.php
- related JS tests under src/template-parts/**, src/utils/**, and src/store/**
- docs/features/template-part-recommendations.md
- docs/reference/abilities-and-routes.md
- docs/reference/recommendation-ui-consistency.md
- docs/reference/template-operations.md
- docs/features/activity-and-audit.md
- docs/SOURCE_OF_TRUTH.md
- STATUS.md

Focus areas:
- permission and ability-schema drift
- REST vs Abilities input-shape mismatches
- active template-part scope/ref/slug/area mismatches
- saved entity structure vs live editor structure, especially when live structure is empty
- visiblePatternNames filtering, including explicit empty filters
- prompt instructions that allow operations the parser or client validator later rejects
- server/client contract mismatches for suggestions, focusBlocks, patternSuggestions, and operations
- unsupported or ambiguous insert_pattern, replace_block_with_pattern, and remove_block operations
- missing or stale placement anchors for start, end, before_block_path, and after_block_path
- targetPath validation gaps, especially deep paths, expectedBlockName, and allowedOperations
- cases where failed, advisory, or stale recommendations can still be reviewed/applied
- reviewContextSignature / resolvedContextSignature drift handling
- deterministic executor mismatches between preview validation and live apply
- undo/activity entries that cannot safely restore or validate live template-part state
- missing tests for shared contracts, freshness guards, operation validation, scope resolution, visible-pattern filtering, or docs drift
- docs that overclaim behavior not enforced by code

Output format:
1. Findings first, ordered by severity (P0, P1, P2, P3).
2. Each finding must include exact file/line references, impact, and the smallest credible fix direction.
3. Add "Open Questions / Assumptions" only if needed.
4. Add a short "Verification Reviewed" section listing the tests/docs you inspected and any commands you ran.
5. If no findings are confirmed, say that plainly and identify remaining test gaps or residual risk.
```
