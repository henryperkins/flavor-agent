# Block Recommendation Review Prompt

```text
Review Flavor Agent block recommendations in /home/ubuntu/flavor-agent with the same evidence-first stance as the template recommendation review.

Treat this as a code review, not an implementation pass. Do not edit files. Findings must lead, ordered by severity, with exact file/line references and a clear explanation of the user-visible or security impact.

Scope:
- Primary surface: flavor-agent/recommend-block
- Include embedded recommend-navigation only when shared code, contracts, request state, stale handling, docs, or UI placement affect the block recommendation path.
- Trace the full path from route/ability registration through context collection, prompt construction, provider call, response parsing/sanitization, client rendering, direct apply, stale-context handling, undo/activity, tests, and docs.

Start by reading the current checkout. Grep hits are only leads; do not flag anything unless you opened the relevant code and confirmed the runtime path.

Inspect at minimum:
- inc/Abilities/Registration.php
- inc/Abilities/BlockAbilities.php
- inc/Context/BlockContextCollector.php
- inc/Context/BlockRecommendationExecutionContract.php
- inc/Context/BlockTypeIntrospector.php
- inc/LLM/Prompt.php
- inc/REST/Agent_Controller.php
- src/inspector/BlockRecommendationsPanel.js
- src/inspector/block-recommendation-request.js
- src/inspector/SuggestionChips.js
- src/inspector/NavigationRecommendations.js, only for embedded/shared behavior
- src/utils/block-recommendation-context.js
- src/utils/block-execution-contract.js
- src/utils/recommendation-request-signature.js
- src/store/index.js
- src/store/update-helpers.js
- src/store/activity-history.js
- src/store/block-targeting.js
- related component tests under src/inspector/**, src/store/**, src/utils/**, and src/components/**
- tests/phpunit/BlockAbilitiesTest.php
- related e2e coverage in tests/e2e/*
- docs/features/block-recommendations.md, docs/features/navigation-recommendations.md, docs/reference/abilities-and-routes.md, docs/SOURCE_OF_TRUTH.md, and STATUS.md

Focus areas:
- permission and ability-schema drift
- server/client request or response contract mismatches
- prompt instructions that allow updates the sanitizer or apply path later rejects
- unsafe or under-validated attribute updates
- contentOnly, locked-block, unsupported-supports, or capability restrictions bypassed by apply
- direct-apply behavior that should be advisory-only
- delegated native sub-panels accidentally creating second apply/refresh/activity paths
- stale client request signature or resolvedContextSignature handling
- cases where failed or stale recommendations can still mutate attributes
- undo snapshot correctness when the block moved, changed, disappeared, or was already reverted by native undo
- activity/audit metadata gaps for successful applies, failed requests, provider fallback, or undo state
- embedded navigation state leaking into block apply/activity behavior
- missing tests for shared contracts, freshness guards, contentOnly restrictions, validation, undo, activity, or docs drift
- docs that overclaim behavior not enforced by code

Output format:
1. Findings first, ordered by severity (P0, P1, P2, P3).
2. Each finding must include exact file/line references, impact, and the smallest credible fix direction.
3. Add "Open Questions / Assumptions" only if needed.
4. Add a short "Verification Reviewed" section listing the tests/docs you inspected and any commands you ran.
5. If no findings are confirmed, say that plainly and identify remaining test gaps or residual risk.
```
