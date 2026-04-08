# Server Freshness And Contract Remediation Plan

> Scope: address the verified findings from the 2026-04-08 uncommitted-changes review. The findings are: (1) docs-grounding cache churn can falsely invalidate results during server-side freshness revalidation, (2) `flavor-agent/recommend-patterns` advertises `resolveSignatureOnly` without implementing it, and (3) disabled block requests now do docs/signature work before short-circuiting.

> This document is an implementation brief for an LLM. Do not stop at analysis. Make the code changes, update tests and docs, and verify the final behavior end to end.

## Goal

Make server-side freshness mean "the stored result still matches the current server-normalized apply context and prompt" rather than "the fully grounded prompt text is byte-for-byte identical."

The end state must be:

1. `resolvedContextSignature` remains the wire field used by the client store, but its semantics change to "server-resolved apply-context signature."
2. Background docs-cache warming must not cause false server-stale apply failures when the editor state and user prompt are unchanged.
3. `resolveSignatureOnly` remains supported only on review/apply recommendation surfaces: `block`, `template`, `template-part`, `global-styles`, and `style-book`.
4. `pattern` no longer advertises `resolveSignatureOnly` anywhere unless it actually implements it.
5. Disabled block requests short-circuit before docs lookup, prompt construction, and model calls.
6. Current client-side stale checks stay in place and still run before any server revalidation.

## Findings In Scope

1. Server freshness currently hashes the final grounded prompt, so a docs-cache improvement can make a previously fetched result look stale even though the live editor state did not change.
2. The `flavor-agent/recommend-patterns` ability schema advertises `resolveSignatureOnly`, but the ability and REST endpoint do not implement that contract.
3. `BlockAbilities::recommend_block()` now does docs-grounding work before the disabled-block short-circuit, which regresses the performance of a path that should stay cheap.

## Mandatory Decisions

These are implementation decisions, not open questions.

### 1. Keep the existing response field name

Do not rename the client-visible field:

- `resolvedContextSignature`

The JS store, selectors, tests, and UI already depend on that name. Change its semantics, not its wire name.

### 2. Redefine `resolvedContextSignature` as a deterministic apply-freshness hash

Do not hash:

- grounded docs excerpts
- final `userPrompt` strings
- final `systemPrompt` strings
- `requestMeta`
- timestamps
- request ids
- model output

Instead, hash a stable server-normalized payload that represents:

- surface
- normalized server context after any server-only collection and live overlay
- sanitized user prompt

The signature must capture server-only context that the client cannot fully reconstruct, but it must exclude cache-sensitive prompt enrichments such as docs guidance text.

### 3. `resolveSignatureOnly` must skip docs grounding and model calls

For `block`, `template`, `template-part`, and `style`, a signature-only request must:

1. normalize and collect the same apply-relevant server context used for real recommendations
2. compute the deterministic freshness signature
3. return the minimal payload
4. skip docs guidance lookup
5. skip prompt building if it is no longer needed for the signature
6. skip `ChatClient::chat()` / `ResponsesClient::rank()`

### 4. `pattern` stays out of this contract

Do not add server freshness revalidation to pattern recommendations in this patch.

Instead:

1. remove `resolveSignatureOnly` from the pattern ability schema
2. keep the REST route unchanged
3. update docs so the published contract matches runtime behavior

### 5. Disabled block requests keep a cheap signature path

Disabled block requests may still return an empty payload plus `resolvedContextSignature`, but that signature must be derived from normalized context plus prompt only.

They must not:

- query docs caches
- schedule docs warms
- build grounded prompts
- call the model layer

### 6. Keep the client stale fast path

Do not replace the current client-side request-signature stale check.

The order must stay:

1. compare client request signature first
2. if client-stale, stop immediately
3. only if client-fresh, call `resolveSignatureOnly`
4. compare stored server freshness signature
5. if server-stale, stop
6. otherwise proceed with apply

## Non-Goals

1. Do not redesign `pattern` into a review/apply surface.
2. Do not add server revalidation to `content` or `navigation`.
3. Do not rename existing REST routes.
4. Do not make docs grounding part of apply freshness again under a different name.
5. Do not widen the store contract beyond what is needed to fix the verified findings.

## Files In Scope

Primary server files:

- `inc/Support/RecommendationResolvedSignature.php`
- `inc/Abilities/BlockAbilities.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Abilities/Registration.php`
- `inc/REST/Agent_Controller.php`

Primary client files:

- `src/store/index.js`
- `src/inspector/BlockRecommendationsPanel.js`
- `src/inspector/SuggestionChips.js`
- `src/templates/TemplateRecommender.js`
- `src/template-parts/TemplatePartRecommender.js`
- `src/global-styles/GlobalStylesRecommender.js`
- `src/style-book/StyleBookRecommender.js`

Primary docs:

- `docs/reference/abilities-and-routes.md`
- `docs/reference/shared-internals.md`
- `docs/features/block-recommendations.md`
- `docs/features/template-recommendations.md`
- `docs/features/template-part-recommendations.md`
- `docs/features/style-and-theme-intelligence.md`
- `docs/features/pattern-recommendations.md`

Primary tests:

- `tests/phpunit/AgentControllerTest.php`
- `tests/phpunit/BlockAbilitiesTest.php`
- `tests/phpunit/StyleAbilitiesTest.php`
- `tests/phpunit/RegistrationTest.php` if present
- `src/store/__tests__/store-actions.test.js`

## Workstream 1: Replace Prompt-Hash Freshness With Deterministic Context Freshness

### Objective

Stop treating docs-grounding cache churn as an apply freshness signal.

### Desired End State

1. Every review/apply surface computes `resolvedContextSignature` from a structured payload, not from the final grounded prompt text.
2. The same live editor state plus the same prompt produces the same server freshness signature even if docs guidance changes between requests.
3. Server-only collector output that materially affects safe application still participates in the signature.

### Implementation Steps

1. Refactor `inc/Support/RecommendationResolvedSignature.php`.
   Add a payload-based API, for example:
   - `from_payload( string $surface, array $payload ): string`
   - optional private normalizer helpers for stable hashing

2. Normalize the hash input recursively.
   Requirements:
   - associative-array keys sorted deterministically
   - scalar values preserved as JSON-safe values
   - list ordering preserved where semantic ordering matters
   - no transient metadata mixed into the payload

3. Build explicit freshness payloads per surface.

   `block`:
   - normalized context from `prepare_recommend_block_input()`
   - sanitized prompt

   `template`:
   - collector context after `ServerCollector::for_template()`
   - explicit `visiblePatternNames` overlay
   - live `editorSlots` and `editorStructure` overlays
   - sanitized prompt

   `template-part`:
   - collector context after `ServerCollector::for_template_part()`
   - explicit `visiblePatternNames` overlay
   - live `editorStructure` overlay
   - sanitized prompt

   `global-styles` and `style-book`:
   - output of `build_context_for_surface()`
   - sanitized prompt

4. Remove prompt-string hashing from all recommendation abilities in this patch.
   `from_prompt()` can be deleted if no longer used, or kept only if another unrelated path still needs it. It must no longer drive apply freshness.

### Tests To Add Or Update

1. `BlockAbilitiesTest`:
   - the signature-only result stays the same before and after docs cache warms for the same block context and prompt

2. `StyleAbilitiesTest`:
   - the signature changes when the prompt changes
   - the signature does not change only because docs grounding data changes

3. If direct template/template-part ability coverage is easier via controller tests than new test classes, cover them there instead of adding a new test fixture.

### Exit Criteria

1. Docs cache state no longer changes server freshness by itself.
2. Prompt changes and real server-context changes still change the signature.

## Workstream 2: Rewire Signature-Only Requests To Use The New Contract

### Objective

Make `resolveSignatureOnly` resolve only the apply-freshness signature, with no docs or model work.

### Implementation Steps

1. `BlockAbilities::recommend_block()`
   - normalize input
   - prepare canonical context
   - compute deterministic freshness signature from normalized context plus prompt
   - if `resolveSignatureOnly`, return the minimal payload immediately
   - if the block is disabled, return the empty payload plus signature immediately
   - only after those checks, collect docs guidance and call the model

2. `TemplateAbilities::recommend_template()`
   - build collector context and live overlays first
   - compute deterministic freshness signature from that context plus prompt
   - if `resolveSignatureOnly`, return the minimal payload
   - only then collect docs guidance and rank

3. `TemplateAbilities::recommend_template_part()`
   - follow the same sequence as `recommend_template()`

4. `StyleAbilities::recommend_style()`
   - build the normalized surface context first
   - compute deterministic freshness signature from that context plus prompt
   - if `resolveSignatureOnly`, return the minimal payload
   - only then collect docs guidance and rank

5. Keep response shapes stable.
   - `block` may continue returning the wrapped `{ payload, clientId }` response shape from the controller
   - `template`, `template-part`, and `style` may continue returning the minimal payload directly
   - the store already supports both forms via `getResolvedContextSignatureFromResponse()`

6. Update controller comments and docs so `resolveSignatureOnly` is described as "server apply-freshness resolution," not "final prompt hashing."

### Tests To Add Or Update

1. `AgentControllerTest`
   - block/style/template/template-part signature-only requests return only `resolvedContextSignature`
   - signature-only requests do not hit the model layer
   - signature-only requests do not trigger docs network work

2. `store-actions.test.js`
   - keep existing server-stale tests
   - update any wording or assumptions that still imply the signature is a prompt hash

### Exit Criteria

1. Signature-only requests are cheap.
2. The store can continue reusing the current `resolvedContextSignature` plumbing without additional compatibility glue.

## Workstream 3: Remove The Unsupported Pattern Signature Contract

### Objective

Bring the published pattern ability contract back into sync with runtime behavior.

### Implementation Steps

1. Remove `resolveSignatureOnly` from `flavor-agent/recommend-patterns` in `inc/Abilities/Registration.php`.

2. Do not add `resolveSignatureOnly` to `/flavor-agent/v1/recommend-patterns`.
   The REST route currently does not accept it, and this patch should keep that true.

3. Leave `PatternAbilities::recommend_patterns()` unchanged apart from any cleanup needed to remove dead references or comments.

4. Update docs:
   - `docs/reference/abilities-and-routes.md`
   - `docs/features/pattern-recommendations.md`

   The docs should explicitly say that pattern recommendations are request-time ranked results, not review/apply surfaces, and they do not participate in `resolveSignatureOnly`.

### Tests To Add Or Update

1. `RegistrationTest` or equivalent:
   - assert the pattern ability input schema does not include `resolveSignatureOnly`

2. `AgentControllerTest` route registration coverage, if present:
   - assert the REST args for `/recommend-patterns` do not advertise the flag

### Exit Criteria

1. No published pattern contract claims support that does not exist.
2. Abilities docs and REST behavior say the same thing.

## Workstream 4: Restore The Disabled-Block Fast Path

### Objective

Make disabled block requests cheap again without regressing payload shape consistency.

### Implementation Steps

1. In `BlockAbilities::recommend_block()`, move the disabled-path short-circuit so it happens before docs guidance collection and model prompt construction.

2. Keep the return payload consistent.
   The disabled response should remain:
   - empty `settings`
   - empty `styles`
   - empty `block`
   - empty `explanation`
   - deterministic `resolvedContextSignature`

3. Ensure the disabled path does not schedule docs warms as a side effect.
   The signature must come entirely from normalized block context plus prompt.

4. Keep all existing rule-enforcement behavior for non-disabled blocks unchanged.

### Tests To Add Or Update

1. `BlockAbilitiesTest`
   - disabled blocks still return the empty payload shape
   - disabled blocks still include `resolvedContextSignature`
   - disabled blocks do not hit AI or docs network paths even when docs grounding is configured

2. If needed, add a controller-level regression test that mirrors the REST path instead of only the ability method.

### Exit Criteria

1. Disabled block requests do no remote work.
2. Existing callers still get a stable payload shape.

## Workstream 5: Client, Docs, And Verification Cleanup

### Objective

Make the client wording, docs, and verification suite match the new semantics.

### Implementation Steps

1. Update internal docs language everywhere `resolvedContextSignature` is described.
   It should now be described as:
   - "server-resolved apply-context signature"
   - not "final grounded prompt signature"

2. Update block/template/template-part/style feature docs to explain:
   - client stale checks protect against live editor drift
   - server revalidation protects against server-only context drift
   - docs warming alone does not invalidate a result

3. Review client stale UI text only where necessary.
   The current "server-resolved context" wording is still acceptable; it does not need to say "prompt."

4. Keep the existing store flow unless a touched test proves otherwise.
   The current store contract is already sound:
   - client stale first
   - server revalidation second
   - surface-specific stale reason persisted in state

5. Run targeted verification, not repo-wide unrelated cleanup.
   Required commands:

```bash
cd /home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent
vendor/bin/phpunit tests/phpunit/AgentControllerTest.php tests/phpunit/BlockAbilitiesTest.php tests/phpunit/StyleAbilitiesTest.php
npm run test:unit -- --runInBand src/store/__tests__/store-actions.test.js
npm run build
```

6. Run additional targeted PHPUnit coverage if touched:
   - `tests/phpunit/RegistrationTest.php`
   - any new template/template-part ability tests added for this patch

7. Do not treat current repo-wide JS lint noise as part of this patch unless touched files introduce new lint failures.
   If needed, run targeted lint or formatting on touched files only.

### Exit Criteria

1. Docs describe the new freshness contract correctly.
2. Targeted server and store tests pass.
3. Build still succeeds.

## Recommended Execution Order

1. Refactor `RecommendationResolvedSignature` to accept normalized payloads.
2. Update `BlockAbilities`, `TemplateAbilities`, and `StyleAbilities` to compute the new signature before docs/model work.
3. Restore the disabled-block fast path as part of the `BlockAbilities` rewrite.
4. Remove `resolveSignatureOnly` from the pattern ability schema and update docs.
5. Update PHP tests to assert docs changes no longer affect freshness signatures.
6. Re-run the JS store suite and build.
7. Update reference and feature docs last, once runtime behavior is locked.

## Done Means

This plan is complete only when all of the following are true:

1. A background docs-cache warm cannot by itself make an unchanged result fail server freshness revalidation.
2. `pattern` no longer advertises unsupported signature-only behavior.
3. Disabled block requests return cheaply without docs or model work.
4. `resolvedContextSignature` remains backward-compatible at the JSON/store level.
5. The targeted PHPUnit suite, store unit suite, and production build all pass after the implementation.
