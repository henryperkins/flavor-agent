# Verified Findings Remediation Plan

> Scope: this plan addresses only the findings that were verified against the current codebase on 2026-04-05. Partially verified and unverified items are listed in a separate follow-up section and are intentionally excluded from the implementation sequence below.

## Verification Basis

The issue inventory below is based on direct code inspection plus a targeted PHPUnit pass:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit --filter '(ChatClientTest|AzureBackendValidationTest|ServerCollectorTest|TemplatePromptTest|ActivityRepositoryTest|RegistrationTest)'
```

Verified issues in scope:

1. Embedding and Qdrant compatibility is brittle.
2. `TemplatePrompt` accepts non-executable suggestions despite its prompt contract.
3. `TemplatePartPrompt` does not validate `start` and `end` insertion anchors against the live anchor list.
4. Route and schema drift exists around `editorStructure`.
5. `Retry-After` handling is incomplete, duplicated, and blocks request threads.
6. `update_undo_status()` allows terminal-state rewrites on the failure path.
7. Navigation location inference can false-match menu IDs.
8. `NavigationPrompt` validation is looser than the other prompt parsers.
9. Admin relative time filtering is day-granular even for hour-based filters.
10. Pattern candidate collection reparses pattern override metadata on each request.
11. `TemplatePrompt` enforces a single pattern insert without documenting that limit.
12. `Repository::query_admin()` is the first obvious scaling hotspot.
13. `AISearchClient::sanitize_excerpt()` is not UTF-8 safe.
14. `Serializer::normalize_positive_int()` is misnamed because it permits `0`.

## Out Of Scope For This Session

These items need investigation or should be handled in a different session:

1. Navigation fallback for ref-only navigation blocks.
   The code already falls back correctly for self-closing ref-only markup, and there is an existing test that intentionally preserves explicitly empty wrapper markup as empty. This needs a product-contract decision before changing behavior.
2. Chat dispatch likely branches on the wrong signal.
   This was not reproduced as a defect. `ResponsesClient::rank()` already reroutes connector-backed and WordPress AI Client traffic, and existing tests cover that path. No code change is planned here.
3. Normalization helper duplication across classes.
   This is a maintenance cleanup, not a verified correctness defect. Handle in a later refactor session.
4. Duplicated insertion-anchor helper logic across template code paths.
   This is maintainability work and should be grouped with a later shared-utility cleanup.

## Delivery Strategy

Deliver the fixes in five ordered workstreams:

1. Lock vector compatibility first because it can silently corrupt or break pattern recommendations.
2. Tighten prompt contracts next so executable and advisory semantics stop drifting.
3. Align route, parser, and navigation validation contracts.
4. Fix retry, undo, and time-filter correctness.
5. Finish with performance hardening and low-risk maintenance fixes.

The implementation should remain contract-first:

1. Update validators and schemas before widening behavior.
2. Add failing tests first where the current behavior is clearly wrong.
3. Keep `Agent_Controller` thin; put behavior in collectors, prompt parsers, backend clients, or repository helpers.
4. Avoid introducing hidden migrations or destructive cleanup. Prefer explicit stale-state detection, rebuild, and forward-only compatibility.

---

## Workstream 1: Embedding And Qdrant Compatibility

### Goal

Ensure pattern indexing and pattern search can never mix vectors and collections that were built with incompatible embedding signatures.

### Desired End State

1. The active embedding signature is explicit and includes provider, model, and dimension.
2. Qdrant collections are named from site scope plus the embedding signature, not site scope alone.
3. Collection creation and use both verify vector size compatibility.
4. Pattern search does not query a stale-but-usable index when the current embedding signature is incompatible with the indexed collection.
5. Changing the embedding model or vector size produces a clean rebuild path instead of undefined behavior.

### Files

Modify:

- `inc/AzureOpenAI/EmbeddingClient.php`
- `inc/AzureOpenAI/ConfigurationValidator.php`
- `inc/AzureOpenAI/QdrantClient.php`
- `inc/Patterns/PatternIndex.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Settings.php`
- `tests/phpunit/AzureBackendValidationTest.php`
- `tests/phpunit/PatternIndexTest.php`
- `tests/phpunit/PatternAbilitiesTest.php`
- `tests/phpunit/SettingsTest.php`

### Step-By-Step Solution

1. Introduce an explicit embedding-signature helper.
   Add a small runtime helper that returns:
   - `provider`
   - `model`
   - `dimension`
   - `signature_hash`

2. Teach `EmbeddingClient` to validate returned vector shapes.
   `embed_batch()` must:
   - reject empty embedding arrays
   - reject non-array embeddings
   - reject mixed-dimension batches
   - return a `WP_Error` when the batch dimension is inconsistent

3. Capture embedding dimension during validation and sync.
   The safest implementation is:
   - during settings validation, inspect the returned embedding length and surface it in the validation result
   - during pattern sync, derive the active dimension from a real embed result before collection creation or reuse

4. Extend `PatternIndex` state with the active embedding dimension and a single embedding signature field.
   The saved state should include:
   - `embedding_dimension`
   - `embedding_signature`
   - `qdrant_collection`

5. Change Qdrant collection identity to include the embedding signature.
   Replace the current site-scope-only collection name with a name derived from:
   - site scope
   - embedding provider
   - embedding model
   - embedding dimension

6. Verify the live collection definition before indexing or querying.
   `QdrantClient::ensure_collection()` should:
   - inspect an existing collection when it already exists
   - verify the declared vector size matches the active embedding dimension
   - fail safely if the existing collection definition does not match the expected size

7. Block incompatible stale-index search.
   `PatternIndex::get_runtime_state()` should return explicit stale reasons, for example:
   - `embedding_signature_changed`
   - `qdrant_url_changed`
   - `collection_name_changed`

   `PatternAbilities::recommend_patterns()` should continue using a stale-but-usable index only for non-compatibility drift. If the stale reason is vector compatibility drift, it must:
   - schedule a rebuild
   - return the existing warming or unavailable response
   - avoid querying the old collection with a new vector

8. Keep migration non-destructive in the first patch.
   Do not delete legacy collections automatically. The first implementation should:
   - build into a new compatible collection name
   - leave cleanup manual or handle it in a later maintenance task

9. Surface the reason clearly in admin UX.
   Settings and sync status should report that the pattern index is stale because the embedding configuration changed, not just that it is stale.

### Tests To Add

1. Changing embedding model or dimension marks the index stale with a compatibility reason.
2. A stale index caused by provider/model/dimension drift is not used for live search.
3. `embed_batch()` rejects mixed-dimension responses.
4. `ensure_collection()` rejects or rebuilds around a vector-size mismatch.
5. A new collection name is derived when provider, model, or dimension changes.

### Exit Criteria

1. Pattern search never mixes a query vector with an incompatible indexed collection.
2. Model changes produce a deterministic rebuild path.
3. The dimension contract is tested at settings time, sync time, and request time.

---

## Workstream 2: Template Prompt Contract Alignment

### Goal

Make template recommendations fully consistent with their own prompt contract: executable suggestions stay executable, and parser rules match the prompt text exactly.

### Desired End State

1. `TemplatePrompt` rejects advisory-only suggestions when `operations[]` is empty after validation.
2. The one-pattern-insert limit is either documented or removed. This plan keeps the current validator limit and documents it.
3. Legacy advisory-only summaries are no longer accepted as valid template suggestions.

### Files

Modify:

- `inc/LLM/TemplatePrompt.php`
- `tests/phpunit/TemplatePromptTest.php`
- `docs/features/template-recommendations.md`
- `docs/reference/template-operations.md`

### Step-By-Step Solution

1. Tighten the parser, not the UI contract.
   Keep the existing system-prompt rule that `operations[]` is the executable source of truth.

2. Reject empty-operation suggestions after validation.
   In `validate_template_suggestions()`:
   - validate raw operations
   - derive operations from validated template-part summaries where possible
   - reject the suggestion if the final operation list is still empty

3. Keep pattern summaries aligned with validated operations only.
   `patternSuggestions` must be an advisory summary of already-validated executable operations, not a fallback path that keeps an otherwise non-executable suggestion alive.

4. Update the system prompt to describe the one-insert rule explicitly.
   Add a rule such as:
   - each suggestion may contain at most one `insert_pattern` operation

5. Rewrite the legacy advisory-only test coverage.
   The current test that accepts advisory-only pattern summaries should be replaced with a rejection test.

6. Update user-facing docs so the parser contract and prompt contract match.

### Tests To Add Or Update

1. Suggestions with empty `operations` and only `patternSuggestions` are rejected.
2. Suggestions with valid derived template-part operations still pass.
3. Multiple `insert_pattern` operations are rejected and the prompt docs reflect that rule.

### Exit Criteria

1. No template suggestion survives validation unless it is executable.
2. Prompt text and parser behavior say the same thing.

---

## Workstream 3: Template-Part, Navigation, And REST Contract Alignment

### Goal

Bring the template-part, navigation, and route-level contracts back into alignment with the same level of determinism already used by the stronger prompt parsers.

### Desired End State

1. `TemplatePartPrompt` validates all insertion placements against the live insertion-anchor list.
2. REST routes and ability schemas agree on `editorStructure`.
3. Navigation location inference uses parsed block attributes, not raw substring matching.
4. Navigation suggestions validate against real menu targets from the current structure rather than free-form target text alone.

### Files

Modify:

- `inc/LLM/TemplatePartPrompt.php`
- `inc/Context/NavigationContextCollector.php`
- `inc/Context/NavigationParser.php`
- `inc/LLM/NavigationPrompt.php`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/Registration.php`
- `tests/phpunit/TemplatePartPromptTest.php`
- `tests/phpunit/NavigationAbilitiesTest.php`
- `tests/phpunit/ServerCollectorTest.php`
- `tests/phpunit/AgentControllerTest.php`
- `tests/phpunit/RegistrationTest.php`
- `docs/features/navigation-recommendations.md`
- `docs/reference/abilities-and-routes.md`

### Step-By-Step Solution

#### 3A. Validate `start` And `end` Template-Part Anchors

1. Reuse the existing insertion-anchor lookup.
   `build_insertion_anchor_lookup()` already creates placement-only keys for `start` and `end`.

2. Update `validate_operations()` so `insert_pattern` requires:
   - `start` to exist in the lookup when placement is `start`
   - `end` to exist in the lookup when placement is `end`
   - current path-key validation for `before_block_path` and `after_block_path`

3. Add explicit tests for missing `start` and missing `end`.

#### 3B. Fix `editorStructure` Route And Schema Drift

1. Add `editorStructure` to `/recommend-template-part` route args.
   Match the handler and ability schema already in place.

2. Remove `editorStructure` from `/recommend-patterns` REST args for now.
   The pattern handler and ability schema ignore it today. Removing it is the lower-risk change and restores contract trust immediately.

3. Update request-meta and registration tests so REST routes and ability schemas stay aligned.

#### 3C. Fix Navigation Location Inference

1. Stop scanning raw content with `str_contains()`.

2. Parse each template part’s block tree and inspect `core/navigation` block attrs.
   Compare `attrs.ref` numerically to the target menu ID.

3. Return the first matching area only when the parsed `ref` matches exactly.

4. Add a regression test where menu ID `12` must not match a template part containing `123`.

#### 3D. Add Context-Aware Navigation Validation

1. Expand navigation context with a target lookup.
   The collector/parser should produce a stable path-based inventory of current menu items and branches. Each target entry should include:
   - path
   - label
   - type
   - depth

2. Extend the navigation prompt contract so structural changes reference real targets.
   For example:
   - structural change types should include `targetPath`
   - `set-attribute` changes may continue using the current attribute target model

3. Update `NavigationPrompt::build_user()` to include the target inventory.

4. Update `NavigationPrompt::parse_response()` to validate:
   - `targetPath` exists
   - suggested structural changes reference real nodes or real branches
   - attribute changes only target allowlisted navigation attributes

5. Preserve advisory-only scope.
   This does not add navigation execution. It only improves trustworthiness and determinism of the advisory response.

### Tests To Add Or Update

1. `TemplatePartPrompt` rejects `start` insertions when no `start` anchor exists.
2. `TemplatePartPrompt` rejects `end` insertions when no `end` anchor exists.
3. `/recommend-template-part` accepts and sanitizes `editorStructure`.
4. `/recommend-patterns` no longer advertises unused `editorStructure`.
5. Navigation location inference compares parsed numeric refs exactly.
6. Navigation parser rejects structural changes that do not map to real current menu targets.

### Exit Criteria

1. Template-part insertion placements are validated uniformly.
2. REST and ability schemas are trustworthy again.
3. Navigation advice references real current structure instead of free-form guesses.

---

## Workstream 4: Shared Retry, Undo, And Time-Filter Correctness

### Goal

Fix request retry behavior, undo-state transitions, and relative-time filtering so runtime behavior matches HTTP and repository contracts.

### Desired End State

1. `Retry-After` parsing supports both delta-seconds and HTTP-date.
2. Retry parsing lives in one shared helper.
3. Request paths do not block PHP workers with `sleep()` for interactive editor traffic.
4. Undo transitions only permit `available -> undone` and `available -> failed`.
5. Hour-based admin filters use full timestamps rather than day keys.

### Files

Modify:

- `inc/AzureOpenAI/BaseHttpClient.php`
- `inc/AzureOpenAI/QdrantClient.php`
- `inc/Activity/Repository.php`
- `tests/phpunit/AzureBackendValidationTest.php`
- `tests/phpunit/ActivityRepositoryTest.php`
- `docs/reference/activity-state-machine.md`
- `docs/features/activity-and-audit.md`

### Step-By-Step Solution

#### 4A. Centralize And Correct `Retry-After`

1. Add a shared retry-header parser.
   It should:
   - accept a header string or false
   - support integer delta-seconds
   - support HTTP-date headers
   - return a bounded positive integer delay or `null`

2. Replace the duplicate casts in `BaseHttpClient` and `QdrantClient`.

3. Remove blocking `sleep()` from interactive request flow.
   Preferred behavior:
   - return a retryable `WP_Error`
   - include status `429`
   - include parsed `retry_after` data for callers and UI

4. Keep background rebuild behavior explicit.
   Pattern sync and other background flows may decide later whether to retry, but the low-level transport should stop blocking frontend/editor requests by default.

5. Add tests for both header forms.
   Include:
   - `Retry-After: 3`
   - `Retry-After: Wed, 21 Oct 2015 07:28:00 GMT`

#### 4B. Lock Undo State Transitions

1. In `update_undo_status()`, require the current persisted undo state to still be `available` before allowing either `undone` or `failed`.

2. Reject terminal rewrites such as:
   - `undone -> failed`
   - `failed -> failed`
   - `failed -> undone`

3. Keep runtime “effective available again” behavior client-side only, as already documented.

4. Update docs to make the server-side transition rules explicit.

#### 4C. Fix Hour-Based Admin Filtering

1. Expand timestamp metadata beyond `dayKey`.
   Store or derive a localized comparable timestamp for filter evaluation.

2. Keep date-only filtering for:
   - `on`
   - `before`
   - `after`
   - `between`

3. Use full timestamp comparisons for:
   - `inThePast`
   - `over`
   - especially when `dayRelativeUnit` is `hours`

4. Add tests around midnight boundaries so “past 6 hours” behaves correctly across day changes.

### Tests To Add Or Update

1. HTTP-date `Retry-After` is parsed correctly.
2. Interactive request paths return retryable errors instead of sleeping.
3. `update_undo_status()` rejects terminal-state rewrites.
4. “Past 6 hours” filters by timestamp, not just by date.

### Exit Criteria

1. Retry behavior is standards-compliant and centralized.
2. Undo writes match the documented state machine.
3. Relative-time filtering is actually time-based when the user chooses hours.

---

## Workstream 5: Performance Hardening And Low-Risk Maintenance Fixes

### Goal

Address the verified performance and cleanup findings after correctness fixes are stable.

### Desired End State

1. Pattern override metadata is cached instead of reparsed repeatedly in the same request path.
2. `query_admin()` pushes more filtering into SQL before PHP sorting and pagination.
3. Excerpt truncation is multibyte-safe.
4. The non-negative integer helper is named accurately.

### Files

Modify:

- `inc/Context/PatternCatalog.php`
- `inc/Context/PatternOverrideAnalyzer.php`
- `inc/Context/PatternCandidateSelector.php`
- `inc/Activity/Repository.php`
- `inc/Cloudflare/AISearchClient.php`
- `inc/Activity/Serializer.php`
- `tests/phpunit/ActivityRepositoryTest.php`
- any PHPUnit coverage touching `PatternCatalog` or `AISearchClient`

### Step-By-Step Solution

#### 5A. Cache Pattern Override Metadata

1. Add request-local caching keyed by pattern name plus content hash.

2. Put the cache at the lowest useful layer.
   The most practical first patch is inside `PatternCatalog` or `PatternOverrideAnalyzer`, not in each caller.

3. Reuse the cached result when:
   - template candidate collection runs
   - template-part candidate collection runs
   - multiple recommendation surfaces hit the same registered patterns in a single request

4. Keep the first implementation in-memory only.
   Persistent cross-request caching can be considered later if profiling shows it is worth the added invalidation complexity.

#### 5B. Reduce `query_admin()` PHP-Only Work

1. Keep current behavior intact first, but move obvious filters into SQL.
   Start with:
   - user ID
   - date or timestamp window
   - operation type where practical

2. Preserve PHP-side status resolution until the query model is redesigned.
   Status still depends on sibling-row evaluation, so not everything should move into SQL immediately.

3. Add a second-stage optimization only if needed.
   If the first pass still leaves summary or filter-option work too expensive, introduce separate count and filter-option queries rather than `SELECT *` over the full candidate set.

4. Treat this as a contained optimization patch, not a repository redesign.

#### 5C. Make Excerpt Truncation UTF-8 Safe

1. Replace `strlen()` and `substr()` with:
   - `mb_strlen()` and `mb_substr()` when available
   - a safe fallback when `mbstring` is unavailable

2. Keep the same visible length budget and ellipsis behavior.

#### 5D. Rename The Non-Negative Integer Helper

1. Rename `normalize_positive_int()` to `normalize_non_negative_int()`.

2. Update call sites and any affected tests or docs.

3. Do not change numeric behavior in this patch; fix naming only.

### Tests To Add Or Update

1. Pattern override metadata is not recomputed repeatedly within the same request path.
2. Admin query filtering still returns the same results after moving filters into SQL.
3. Excerpt truncation preserves multibyte characters cleanly.
4. Serializer behavior remains unchanged after the helper rename.

### Exit Criteria

1. The main repeated reparse path is eliminated.
2. Admin queries scale better without changing output semantics.
3. Low-risk cleanup items are complete and tested.

---

## Cross-Cutting Test Plan

The implementation should land with focused test additions in the same patch series:

1. PHPUnit
   - `AzureBackendValidationTest`
   - `PatternIndexTest`
   - `PatternAbilitiesTest`
   - `TemplatePromptTest`
   - `TemplatePartPromptTest`
   - `ServerCollectorTest`
   - `NavigationAbilitiesTest`
   - `AgentControllerTest`
   - `RegistrationTest`
   - `ActivityRepositoryTest`

2. Docs and schema checks
   - keep `docs/reference/abilities-and-routes.md` aligned with route changes
   - keep `docs/reference/template-operations.md` aligned with template parser changes
   - keep `docs/reference/activity-state-machine.md` aligned with undo behavior

3. Suggested verification sequence after implementation

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit --filter '(AzureBackendValidationTest|PatternIndexTest|PatternAbilitiesTest|TemplatePromptTest|TemplatePartPromptTest|ServerCollectorTest|NavigationAbilitiesTest|AgentControllerTest|RegistrationTest|ActivityRepositoryTest)'
vendor/bin/phpunit
```

---

## Recommended Patch Breakdown

Use separate patches or PR-sized commits in this order:

1. Embedding/Qdrant compatibility and stale-index gating.
2. Template prompt contract tightening and docs alignment.
3. Template-part anchor validation plus route/schema cleanup.
4. Navigation location inference and context-aware navigation validation.
5. Shared retry parsing, undo-state lock-down, and hour-based filter fix.
6. Pattern metadata caching, admin query optimization, UTF-8 excerpt truncation, and helper rename.

This order keeps the highest-risk correctness fixes first and leaves the performance and cleanup work for the final pass.

## Completion Definition

This remediation plan is complete when:

1. Every verified correctness issue above has a merged code fix and matching tests.
2. Prompt, parser, route, and docs contracts agree again.
3. Pattern search cannot run against an incompatible vector store.
4. No partially verified or unverified issue is changed opportunistically without its own follow-up decision.
