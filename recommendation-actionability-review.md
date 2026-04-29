# Review: Converting Advisory Pattern and Block Recommendations into Insertable Actions

## Verdict

The report is directionally strong and mostly matches the current Flavor Agent architecture. Its core recommendation is sound: keep existing safe block attribute updates as the low-friction path, and move structural or pattern-based mutations into a deterministic review/apply/undo lifecycle rather than making them one-click actions.

The main revision needed is upstream alignment. The report should more explicitly constrain this work so Flavor Agent remains an editor-bound recommendation layer, not a parallel Site Agent, observability system, provider router, or replacement for core pattern/editor infrastructure.

## What the report gets right

- Block recommendations are currently only executable when they resolve to safe local attribute updates. Structural and pattern-replacement block suggestions are intentionally advisory.
- The standalone pattern shelf is already insertable through the editor model. The missing piece is not basic insertion; it is whether Flavor Agent should own review, activity, and undo for pattern-related suggestions embedded in broader flows.
- Template-part recommendations are the right implementation model. They already validate operation sequences, split executable suggestions from manual ideas, show a review step, block stale applies, apply transactionally, and record undoable activity.
- The three-tier model is the right product grammar:
  - **Inline-safe** for bounded local attribute updates.
  - **Review-safe** for deterministic structural operations.
  - **Advisory** for vague, stale, multi-target, unavailable, or policy-ineligible suggestions.
- The security premise is correct: prompt output should be a proposal, not authorization. Execution should be gated by allow-listed operations, local validation, freshness checks, locks, user confirmation, activity logging, and rollback.

## Corrections and gaps

### Block structural actions need pattern context first

The report under-specifies the data required to make block-level pattern operations safe. Block prompts do not currently have the same available-pattern contract used by template and template-part prompts. Before block suggestions can emit `insert_pattern` or `replace_block_with_pattern`, the implementation needs a clear path for:

- visible or allowed pattern names for the current insertion context,
- pattern availability validation,
- operation schema changes in `inc/LLM/ResponseSchema.php`,
- prompt instructions in `inc/LLM/Prompt.php`,
- parser and contract enforcement in the block ability layer,
- client-side validation against the live editor state.

Without that, the model can name patterns that are not insertable in the current context.

### Do not reuse template-part helpers without extraction

`validateTemplatePartOperationSequence()` and `applyTemplatePartSuggestionOperations()` are good precedents, but they are template-part-specific. Their naming, error copy, target assumptions, root resolution, and activity semantics are scoped to template parts.

The safer implementation is to extract a shared structural operation core, then wrap it with surface-specific resolvers:

- template-part resolver,
- future block resolver keyed by selected `clientId`,
- possibly template resolver where behavior overlaps.

This avoids leaking template-part assumptions into the block Inspector.

### Block review state is not a drop-in executable surface

The existing executable-surface runtime works well for template, template-part, Global Styles, and Style Book flows, but those surfaces are not keyed by block `clientId` in the same way as block recommendations. Block structural actions need either:

- a keyed extension of the executable-surface runtime, or
- a block-specific review state that preserves the same freshness/apply/activity semantics.

The report should call this out explicitly so the implementation does not force-fit block actions into an incompatible state model.

### Adding `operations[]` to block schema is a contract change

The block response schema is strict. Adding `operations[]` means the prompt, schema, parser, normalizer, and tests must all agree on what non-structural suggestions return. For example, attribute-only suggestions may need to emit an empty operations array consistently.

This is a REST/Abilities/client contract change and should trigger documentation and validation gates.

### Rollout flags need a concrete source

The report suggests adding rollout flags in `SurfaceCapabilities.php`. That class currently models readiness and capability messaging, not a full feature-flag framework. A real rollout control should define:

- where the flag is stored or configured,
- whether it is user-, site-, or environment-scoped,
- how it is localized to JS,
- what happens in REST/Abilities when the flag is disabled.

### First scope should be narrower

The proposed review-safe operation set is too broad for a first pass. Start with the two clearest operations:

- insert an allowed pattern before or after the selected block,
- replace the selected block with an allowed pattern.

Defer remove-block, arbitrary container start/end insertion, broader structural rewrites, native pattern shelf activity ownership, and navigation mutation until the shared operation engine and undo story are proven.

## Alignment with WordPress AI roadmap tracking

The report should explicitly reference the upstream boundaries tracked in `docs/reference/wordpress-ai-roadmap-tracking.md`.

### Site Agent and observability

`WordPress/ai#419` makes core's Site Agent and Observability Logger the likely canonical direction for agentic/admin mutation and AI event logging. Flavor Agent should not expand this project into a competing general-purpose site agent or a second long-term observability dashboard.

Recommended wording:

> Insertable Flavor Agent actions remain editor-bound recommendation applies. Activity writes should stay compatible with a future core Observability Logger bridge, and admin audit expansion should be avoided until the upstream logging surface settles.

### Ability exposure and lifecycle filters

The report should account for the active upstream pressure around abilities:

- ability consolidation and router patterns,
- per-surface ability exposure controls,
- REST-as-ability unification,
- ability lifecycle filters for permission/logging,
- `meta.annotations.{readonly, destructive, idempotent}`.

Any new or changed apply-capable ability should declare accurate annotations and avoid adding new globally exposed tools unless the surface truly needs them.

### Provider ownership

The report should explicitly preserve the completed provider ownership migration:

- chat stays owned by Connectors / WP AI Client,
- Flavor Agent keeps plugin-owned embeddings and Qdrant only for pattern search,
- no new plugin-owned chat credentials, model routing, or provider selectors should be introduced for this work.

## Alignment with Gutenberg feature tracking

The report should also incorporate the constraints from `docs/reference/gutenberg-feature-tracking.md`.

### Pattern and block infrastructure

New template-part or pattern-related work should avoid betting on standalone Pattern Overrides. Gutenberg is moving that area toward Block Fields and Block Bindings. If structural insertions interact with pattern overrides or bound content, the implementation should follow the emerging Block Fields / Bindings shape rather than deepening old assumptions.

### Freshness under RTC

Real-Time Collaboration changes the risk profile for structural mutations. The report already recommends freshness checks, but it should specifically require a final live-state check immediately before mutation, especially for block structural applies.

### Core revisions

Gutenberg 23.0 adds revisions rows for templates, template parts, and patterns. Flavor Agent's activity-state-machine still provides ordered latest-valid undo, which core revisions do not fully replace, but the report should acknowledge that future template/template-part/pattern undo may partially hand off to core revisions.

### Editor constraints

The report should explicitly include these Gutenberg-driven constraints in the eligibility model:

- viewport block visibility should not be interpreted as missing content,
- `contentOnly` behavior is still under audit,
- theme.json-disabled controls and locks must drop out of executable suggestions,
- new Inspector controls from Block Supports & Design Tools may change support mapping,
- template lock and block lock checks remain mandatory.

## Alignment with the productivity plan

The implementation described in the report is exactly the kind of work that triggers the productivity plan's personal preflight and formal release gates. It touches multiple recommendation surfaces, shared store/runtime behavior, REST/Abilities contracts, activity/undo, and upstream-shaped APIs.

The report should add an explicit validation section requiring:

1. Run the personal 6-item preflight before any implementation is shared.
2. Satisfy `docs/reference/cross-surface-validation-gates.md` for every triggered surface.
3. Verify REST/Abilities/client contract propagation.
4. Run targeted JS and PHP tests for the changed surfaces.
5. Run `node scripts/verify.js --skip-e2e` for a non-browser aggregate pass.
6. Run `npm run check:docs` when contracts, contributor docs, or surface docs change.
7. Run matching Playwright harnesses, or record a known-red/unavailable waiver.
8. Add an upstream-alignment note to `upstream-log.md` if implementation proceeds.

This also fits the plan's over-hardening guard: every validation guard should name a concrete failure mode. If a guard does not correspond to an actual block/editor/core risk, it should not be added.

## Recommended revised implementation sequence

### Phase 1: Foundation and instrumentation

- Add a feature flag with a concrete source and JS localization.
- Extend the block schema only if the parser, prompt, REST/Abilities contract, and tests can be updated together.
- Add diagnostics to measure how many advisory structural suggestions could become deterministic operations.
- Add ability annotations if any ability contract changes.
- Do not add new provider routing or logging infrastructure.

### Phase 2: Template-part promotion

- Improve template-part prompt/context behavior so more suggestions arrive with valid `operations[]`.
- Keep invalid or ambiguous `patternSuggestions` advisory.
- Reuse the existing template-part review/apply/undo flow.
- Avoid new work based on standalone Pattern Overrides; track Block Fields / Bindings.

### Phase 3: Block structural review actions

- Introduce a block-scoped structural operation contract.
- Add allowed/visible pattern context to block recommendation requests.
- Support only insert-before/after-selected-block and replace-selected-block-with-pattern at first.
- Use review-first UX, final freshness checks, operation validation, activity logging, and undo.
- Keep one-click apply only for safe local attribute updates.

### Phase 4: Productionization

- Align docs and UI labels around Inline-safe, Review-safe, and Advisory.
- Decide how activity writes will bridge to core Observability Logger if upstream lands first.
- Reassess ability exposure controls against the latest WordPress AI roadmap.
- Reassess undo ownership against core revisions.

## Bottom line

The report's architecture is right, but it should be edited to emphasize restraint: make more suggestions executable only when Flavor Agent can reduce them to deterministic, locally validated, editor-scoped operations. Avoid expanding into areas core is actively claiming: Site Agent, observability, provider routing, ability exposure policy, and long-term revision/undo infrastructure.

The first shippable win should be narrow and defensible: promote more template-part suggestions that already fit the operation model, then add a small block-level review-safe pattern action path for selected-block insert/replace. Everything else should remain advisory until upstream APIs and the shared mutation core are ready.
