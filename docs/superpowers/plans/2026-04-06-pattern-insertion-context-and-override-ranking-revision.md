# Pattern Insertion Context And Override Ranking Revision

> Scope: this is the corrected implementation plan for the "Deeper Insertion Context" and "Smarter Pattern Overrides" work. It incorporates the review findings from the previous draft and keeps the changes bounded to pattern recommendation, Qdrant payload indexing, and ability/schema alignment.

## Corrections From The Previous Draft

1. The `recommend-patterns` ability schema must accept `insertionContext`, not just the REST route.
2. Template-part area detection must reuse the existing inference helpers instead of relying only on raw `attrs.area`.
3. Core blocks are in scope for override matching, not only custom blocks.
4. PHP tests must cover the schema, retrieval, and ranking changes.
5. The Qdrant `traits` index change is forward-only and takes effect after the next sync.
6. The registered `recommend-patterns` output schema is already stale and must declare `overrideCapabilities` and its nested fields in the same change set.

## Goal

Make pattern recommendations structurally aware at retrieval time, not just in the LLM prompt, and make pattern override ranking work for both core and custom blocks without changing mutation behavior.

## Current Constraints

1. `src/patterns/PatternRecommender.js` is the inserter-side entrypoint for this flow.
2. `inc/REST/Agent_Controller.php` already accepts `insertionContext` for `recommend-patterns`.
3. `inc/Abilities/Registration.php` still needs its ability schema updated to match that route.
4. `inc/Patterns/PatternIndex.php` already stores `traits` in the indexed payload.
5. `src/utils/template-part-areas.js` and `src/utils/structural-identity.js` already contain the area inference logic we should reuse.
6. `ServerCollector::introspect_block_type()` already resolves bindable attributes for core blocks via `BlockTypeIntrospector`.

## Workstream 1: Schema And Context Alignment

### Files

- Modify: `src/patterns/PatternRecommender.js`
- Modify: `src/patterns/__tests__/PatternRecommender.test.js`
- Modify: `inc/Abilities/Registration.php`
- Modify: `tests/phpunit/RegistrationTest.php`
- Modify: `tests/phpunit/AgentControllerTest.php` if the pass-through shape needs explicit coverage

### Steps

1. Extend the inserter-side insertion context.
   - Read `getBlockAttributes()` for the inserter root and ancestor chain.
   - Derive `templatePartArea` using the existing area inference helpers and the serialized `window.flavorAgentData.templatePartAreas` lookup.
   - Capture `templatePartSlug` from the nearest template-part context.
   - Capture `containerLayout` from the root block's `layout.type`.
   - Return `rootBlock`, `ancestors`, `nearbySiblings`, `templatePartArea`, `templatePartSlug`, and `containerLayout`.

2. Update the ability schema to match the route.
   - Add `insertionContext` to the `recommend-patterns` ability input schema.
   - Add nested properties for the new insertion-context fields so the registered ability contract matches the REST route.

3. Update test coverage for the new shape.
   - `src/patterns/__tests__/PatternRecommender.test.js` should assert the richer insertion context, including ancestor-derived template-part area and slug.
   - `tests/phpunit/RegistrationTest.php` should assert the ability schema includes `insertionContext` and the output schema includes `overrideCapabilities`.
   - Add or extend a REST test if needed to confirm the route still passes the context through unchanged.

## Workstream 2: Retrieval-Level Trait Awareness

### Files

- Modify: `inc/AzureOpenAI/QdrantClient.php`
- Modify: `inc/Abilities/PatternAbilities.php`
- Modify: `tests/phpunit/PatternAbilitiesTest.php`
- Modify: `tests/phpunit/PatternIndexTest.php` or `tests/phpunit/AzureBackendValidationTest.php` if payload-index coverage is easier there

### Steps

1. Index traits in Qdrant.
   - Add `traits` to `QdrantClient::ensure_payload_indexes()`.
   - Treat this as a forward-only change that is picked up on the next sync.

2. Parse the new insertion context in `PatternAbilities::recommend_patterns()`.
   - Read `templatePartArea`, `templatePartSlug`, and `containerLayout` from `insertionContext`.
   - Keep the existing `rootBlock`, `ancestors`, and `nearbySiblings` behavior intact.

3. Add trait-aware Pass B retrieval.
   - Add a soft `should` clause for `traits = simple` when the insertion area is constrained.
   - Add a soft `should` clause for `traits = site-chrome` when the template-part area is `header` or `footer`.
   - Keep layout-to-trait mappings conservative; if a mapping is not deterministic enough to explain in tests, leave it as ranking-only instead of retrieval-only.

4. Keep `traits` available to the LLM.
   - Continue forwarding the indexed `traits` payload into the candidate JSON.

## Workstream 3: Override Ranking For Core And Custom Blocks

### Files

- Modify: `inc/Abilities/PatternAbilities.php`
- Modify: `inc/Abilities/Registration.php`
- Modify: `inc/Context/BlockTypeIntrospector.php` only if the core bindable map needs expansion
- Modify: `tests/phpunit/PatternAbilitiesTest.php`
- Modify: `tests/phpunit/RegistrationTest.php`

### Steps

1. Remove the custom-block-only gate in `build_candidate_ranking_hint()`.
   - Evaluate override metadata for every block name, including core blocks.
   - Use `ServerCollector::introspect_block_type()` to resolve bindable attributes.
   - Compare the bindable list with `patternOverrides.overrideAttributes`.

2. Make the general override signal explicit.
   - Record the exact attribute overlap in `matchesNearbyBlock`.
   - Record the overlapping attribute names in `nearbyBlockOverlapAttrs`.
   - Apply a small bonus whenever the pattern is override-ready for the current block, regardless of whether the block is core or custom.

3. Preserve the custom-block path without double-counting.
   - Keep the existing custom-block matching and generic custom-block bonuses.
   - Define the scoring contract so the general override signal and the custom-block-specific signal are separate but capped, rather than silently stacking without limit.

4. Add sibling override awareness.
   - Inspect up to four nearby sibling block types.
   - Deduplicate repeated sibling block names before counting.
   - Count each unique sibling block type with override support once.
   - Expose that count as `siblingOverrideCount`.
   - Apply only a small capped bonus so sibling awareness nudges ranking without dominating semantic relevance.

5. Keep the ranking hint and output contract stable for the LLM and MCP consumers.
   - Update `prepare_candidate_ranking_hint_for_llm()` to include:
     - `matchesNearbyBlock`
     - `nearbyBlockOverlapAttrs`
     - `siblingOverrideCount`
   - Update `build_override_capabilities()` with the same fields so the LLM can explain the overlap in the reason text.
   - Update `inc/Abilities/Registration.php` so the `recommend-patterns` output schema declares `overrideCapabilities` and its nested fields.

## Workstream 4: Prompt And Explanation Shaping

### Files

- Modify: `inc/Abilities/PatternAbilities.php`
- Modify: `tests/phpunit/PatternAbilitiesTest.php`

### Steps

1. Expand the insertion context section in the LLM prompt.
   - Include area, template-part slug, and container layout when known.
   - Keep the existing root block, ancestor chain, and nearby block lines.
   - Preserve the constrained-area note for compact-pattern contexts.

2. Update the ranking system prompt.
   - Tell the model to score patterns higher when their Pattern Overrides metadata overlaps the nearby block or its siblings.
   - Make the instruction explicit that this applies to both core and custom blocks.
   - Ask the model to mention the specific attribute overlap in the reason text.

3. Keep the prompt contract aligned with the hint payload.
   - The model should see the same override signals that the ranking code used.
   - Do not add extra hint fields unless the PHP side also computes and tests them.

## Workstream 5: Tests And Verification

### PHP

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit --filter '(PatternAbilitiesTest|RegistrationTest|AgentControllerTest|AzureBackendValidationTest|PatternIndexTest)'
```

Add or update cases for:

1. The ability schema includes `insertionContext`.
2. The registered output schema includes `overrideCapabilities`, `matchesNearbyBlock`, `nearbyBlockOverlapAttrs`, and `siblingOverrideCount`.
3. The inserter context includes template-part and layout metadata.
4. Trait-aware Pass B retrieval includes the expected Qdrant `should` clauses.
5. Core blocks such as `core/image` or `core/heading` participate in override matching.
6. Sibling override counts are deduped by block type and capped.
7. The `traits` payload index is requested during collection setup or sync.

### JS

Run:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
npm run test:unit -- --runInBand src/patterns/__tests__/PatternRecommender.test.js
npm run lint:js
npm run build
```

Add or update cases for:

1. `getBlockAttributes()` is used for the inserter root and the nearest template-part ancestor.
2. The fixture includes a nested template-part ancestor with explicit `area` and `slug`.
3. The fetch payload includes the richer `insertionContext` shape.
4. Template-part area and layout metadata appear in the context sent to the backend and are derived from the ancestor path, not the root block alone.

## Exit Criteria

1. The UI sends richer insertion context without depending on raw template-part attributes alone.
2. Qdrant retrieval can prefer simple or site-chrome patterns based on insertion context.
3. Core blocks participate in override-aware ranking when pattern metadata overlaps their bindable attributes.
4. The ability schema in `Registration.php` matches the REST route and the JS caller.
5. The registered output schema exposes the returned override-capability contract.
6. PHPUnit and JS coverage document the new behavior.

## Open Questions

1. What is the exact cap for total override-related bonus?
2. Should `containerLayout` map to a specific trait at retrieval time, or stay ranking-only for the first pass?
