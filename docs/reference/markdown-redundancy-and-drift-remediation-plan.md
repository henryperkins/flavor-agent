# Markdown Redundancy & Drift Remediation Plan

## Objective
Eliminate copy-paste duplication in repository markdown that increases drift risk while preserving the current doc intent and navigation ergonomics.

## Findings (from review)
1. Repeated “agentic workers” plan preamble appears in multiple superpowers plan docs.
2. Repeated review-protocol instructions appear across review prompt docs.
3. Duplicate release `Ship / Do not Ship` bullet blocks are copied between `release-surface-scope-review.md` and per-surface docs under `docs/reference/surfaces/`.

## Desired End State
- One canonical source per repeated pattern.
- Surface-specific docs become explicit aliases + deltas, not full mirrors.
- Drift checks detect future duplication regressions.

---

## Canonicalization targets

### 1) Superpowers plan preamble
Create: `docs/reference/agentic-plan-implementation-guide.md`
- Include the shared preamble and rationale:
  - `For agentic workers` sentence
  - required sub-skill recommendation
  - checkbox tracking guidance
  - any global plan execution conventions

Update each of these files to replace the duplicated preamble block with a short pointer:
- `docs/reference/cloudflare-pattern-search-and-embeddings-plan.md`
- `docs/superpowers/plans/2026-05-03-review-remediation.md`
- `docs/superpowers/plans/2026-04-30-content-context-renderer.md`
- `docs/superpowers/plans/2026-04-29-ability-meta-annotations.md`
- `docs/superpowers/plans/2026-05-02-package-updates.md`
- `docs/superpowers/plans/2026-04-28-guidelines-bridge-do-now.md`
- `docs/superpowers/plans/2026-05-02-style-contrast-validator.md`
- `docs/superpowers/plans/2026-05-01-content-voice-samples.md`

Keep a one-line note in each plan after the link, e.g.:
- “Execution framing is defined in [Agentic Plan Implementation Guide](../reference/agentic-plan-implementation-guide.md#execution-brief).”

### 2) Review prompt protocol block
Create: `docs/reference/review-response-protocol.md`
- Consolidate shared review-response contract text:
  - “Treat this as a code review, not an implementation pass”
  - ordered severity requirement
  - findings-first output format
  - confirmed findings vs open questions separator

Update prompt files to rely on canonical protocol:
- `docs/prompts/style-recommendation-review-prompt.md`
- `docs/prompts/template-recommendation-review-prompt.md`
- `docs/prompts/template-part-recommendation-review-prompt.md`
- `docs/prompts/block-recommendation-review-prompt.md`
- `docs/prompts/ai-activity-log-review-prompt.md`
- `docs/prompts/admin-settings-page-review-prompt.md`

Retain only prompt-specific instructions below the shared protocol link.

### 3) Release-surface scope duplication
Create: `docs/reference/surfaces/release-stop-lines.md`
- Centralize shared stop/ship logic for each shipped surface:
  - `block-recommendations`
  - `template-recommendations`
  - `template-part-recommendations`
  - `navigation-recommendations`
  - `global-styles`
  - `helper-abilities-and-rest`

Refactor these files to reduce duplicated mirrored bullets and keep per-surface deltas:
- `docs/reference/release-surface-scope-review.md`
  - keep authoritative surface matrix and canonical decision table
- `docs/reference/surfaces/block-recommendations.md`
- `docs/reference/surfaces/template-recommendations.md`
- `docs/reference/surfaces/template-part-recommendations.md`
- `docs/reference/surfaces/navigation-recommendations.md`
- `docs/reference/surfaces/global-styles.md`
- `docs/reference/surfaces/helper-abilities-and-rest.md`

Suggested pattern:
- add a “Stops from canonical scope” link + a short surface-specific diff section (`Keep`, `Deviations`, `Notes`).

---

## Optional governance improvement
Create: `scripts/check-doc-drift.sh`
- Checks for high-risk duplicated phrases and emits file/location of drift candidates:
  - `for agentic workers: REQUIRED SUB-SKILL`
  - shared review protocol sentence block
  - exact surface stop-line phrase families (for example: `Activity and undo only...`, navigation-embedded recommendation patterns, and template-part review boundaries)
- Add to a lightweight docs check command or CI pre-commit if feasible.

Suggested script behavior:
1. Use exact-string scan first, then ignore allowed canonical files.
2. Fail if exact match appears in >1 non-canonical file.
3. Print remediation hint with target canonical path.

---

## Execution order
1. Add canonical artifacts (agentic guide, review protocol, stop-line catalog).
2. Update all plan files to remove duplicate preamble text.
3. Update all prompt files to use the shared protocol reference.
4. Refactor surface docs to use canonical stop-lines + deltas.
5. Add/extend drift-check script and run.
6. Run doc audit and verify no unresolved duplicates remain.

---

## Success criteria
- `rg`/search confirms the duplicated phrases above appear only in canonical files plus references.
- No markdown file contains full duplicated copies of the identified blocks.
- Release/surface docs can still be read independently (one-click path) without losing required context.
- New cross-reference links are valid and navigable.

---

## Verification checklist
- [ ] Canonical files exist at the three paths above.
- [ ] All eight superpowers plans contain canonical preamble references.
- [ ] All six review prompt docs reference shared review protocol.
- [ ] Surface docs now source stop-line logic from centralized surface catalog.
- [ ] Drift script added and run; output confirms zero hard-duplication hits.

---

## Open question
Should this repo move to strict single-source markdown blocks via a preprocessing step (e.g., template include) or keep this as documented cross-link references only?
