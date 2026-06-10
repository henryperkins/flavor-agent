# Governance Repositioning Completion Prompt

> Written 2026-06-10. Open the next session with this prompt to complete the governance-layer repositioning. The thesis below is the canonical positioning statement; treat any older copy that conflicts with it as a bug in the copy, not in the thesis.

## Canonical thesis

Flavor Agent lets AI work on a live WordPress site without unchecked control. Every AI action runs through one governance layer: operations validated against bounded schemas, structural changes gated behind review, every apply attributed and recorded server-side, every change reversible with drift detection so an undo never clobbers later human edits. Humans get this through native Gutenberg and Site Editor surfaces; external agents get the same contracts through the Abilities API and MCP. Built on the WordPress 7.0 AI stack. The recommendation surfaces are the demonstration; the governance layer is the product.

## Prompt

```text
Complete the repositioning of Flavor Agent around the canonical thesis in
docs/prompts/2026-06-10-governance-repositioning-completion.md. The narrative
docs pass already exists as uncommitted changes in the working tree — review
and land it, then sweep the remaining positioning surfaces that ship at
runtime. Do not re-derive the thesis or rewrite copy that already matches it.

Step 1 — Land the in-flight working tree.
- The tree mixes two workstreams: the docs repositioning pass (README.md,
  readme.txt, CLAUDE.md, STATUS.md, docs/**) and newer src/admin/* +
  approvals-spec changes that look like early C1.1 governance-console work.
  Identify the boundary first; commit them separately. Check the admin
  changes against the task list in
  docs/superpowers/plans/2026-06-10-ai-activity-governance-console-c1-1.md
  before assuming they are a complete slice.
- Review the docs diff for internal consistency with the thesis: one
  governance vocabulary, no half-migrated "AI recommendations plugin"
  framing, no claims beyond what code enforces.
- Honesty hedges are intentional — keep them: "every apply the plugin owns",
  editorial-only content surface, review-first "where supported". The thesis
  is the elevator pitch; per-surface docs keep precise scope.

Step 2 — Sweep runtime- and agent-visible copy. This is the unswept layer:
external agents and operators read positioning from the running plugin, not
from README.
- inc/Abilities/Registration.php: the ability category description still
  reads "LLM-assisted editing, pattern, template, and diagnostic abilities
  for the WordPress editor" — pre-thesis framing, and it is the first thing
  an external MCP client sees via discover-abilities. Reframe around the
  governed contracts.
- Ability `description` fields across inc/Abilities/*Abilities.php,
  inc/AI/Abilities/Recommend*Ability.php, PreviewRecommend*Ability.php, and
  ApplyAbilities: each should state its governance contract in one sentence
  (bounded schema, review gate, attribution, reversibility, or read-only
  preview), not only what it recommends.
- Operator-facing copy: settings page intro/help
  (inc/Admin/Settings/Page.php, Help.php, Feedback.php), the FeatureBootstrap
  editor-runtime admin notice, and Settings > AI Activity strings
  (src/admin/activity-log.js, activity-log-utils.js). Framing should match
  "AI proposes; WordPress approves."
- Editor panel intro copy (SurfacePanelIntro / surface-labels across the
  eight surfaces): change only where old framing actively contradicts the
  thesis; do not churn copy without updating the tests that assert on it.
- composer.json description; readme.txt short description and FAQ re-read
  end-to-end against WP.org plugin-directory guidelines.
- List as manual operator chores, do not attempt: GitHub repo
  description/topics/About text, WP.org listing assets.

Step 3 — Canonicalize vocabulary by mapping, not renaming.
- The thesis says "drift detection"; the code says freshness / resolved
  signatures / "stale". Add an explicit vocabulary map to
  docs/reference/governance-layer.md: drift detection = resolved-context
  signature revalidation at apply/undo/decision time; review gate =
  review-context signature + AIReviewSection + pending-decision path;
  bounded schemas = ResponseSchema + operation validators + execution
  contracts; attribution = server-side activity rows + RequestTrace.
- Do not rename PHP/JS symbols, options, abilities, hooks, or DB fields for
  positioning reasons. Copy and docs only.

Constraints
- CRLF gotcha: docs/flavor-agent-readme.md,
  docs/reference/pattern-recommendation-debugging.md, and STATUS.md are
  mixed-CRLF; edit via perl/awk per the established workflow, never plain
  Edit. readme.txt is LF.
- Copy-asserting tests (Jest + tests/e2e/*.spec.js) update in the same
  commit as the copy they assert on.
- All PHP copy stays inside __()/esc_html__() with the flavor-agent text
  domain.
- Out of scope: implementing C1.1 (execute its own plan in a separate
  session), any Governance\ namespace or code-architecture refactor, new
  abilities or routes, and the release screenshot set (tracked in
  docs/reference/current-open-work.md).

Verification — this touches shared copy and admin paths, so the
cross-surface gates in docs/reference/cross-surface-validation-gates.md
apply:
- npm run check:docs
- node scripts/verify.js --skip-e2e, then inspect output/verify/summary.json
- targeted Jest suites for src/admin/** and any component whose copy changed
- npm run test:e2e:wp70 -- tests/e2e/flavor-agent.approvals.spec.js if AI
  Activity copy changed; the playground suite if editor panel copy changed
- record any harness blocker or waiver explicitly instead of skipping
  silently.
```
