# Review Findings Remediation Plan

> **For agentic workers:** Use checkbox steps (`- [ ]`) as the execution contract. Do not fold unrelated user changes into this work. Keep logic fixes, packaging fixes, and doc/status cleanup as separate reviewable chunks.

**Goal:** Close the current March 18 review findings by making WordPress-doc grounding truly non-blocking on recommendation requests, restoring Abilities API schema parity with the current structural editor context, eliminating the tracked/import packaging gap, and correcting the stale quality-status claims that no longer match the branch.

**Architecture:** Execute this in five ordered workstreams:
1. Re-baseline the branch and lock the exact current failure boundaries.
2. Split explicit WordPress-doc search from recommendation-time grounding so recommendation requests never wait on Cloudflare.
3. Extend and verify the `flavor-agent/recommend-block` Abilities schema so the new structural fields survive validation.
4. Resolve the tracked/untracked packaging gap around the inserter search helper and prove a tracked-only build works.
5. Clean up new quality/doc drift, rerun the verification matrix, and update status docs only with commands that actually passed.

**Tech Stack:** PHP, WordPress Abilities API, WordPress REST API, PHPUnit, WPCS/PHPCS, JavaScript, `@wordpress/scripts`, Markdown docs.

**Review Items Addressed:**
- Recommendation requests now perform synchronous Cloudflare AI Search lookups on the request path, so a slow or failing index can stall `recommend-block` and `recommend-template`.
- The Abilities API `recommend-block` schema still reflects the older `selectedBlock` shape and does not admit `editingMode`, `childCount`, `structuralIdentity`, `structuralAncestors`, or `structuralBranch`.
- `src/patterns/PatternRecommender.js` imports `src/patterns/find-inserter-search-input.js`, but that helper and its test are still untracked.
- Doc/verification drift is present: the old March 18 plan draft addressed different findings, `STATUS.md` claims `composer lint:php` passed even though current WPCS checks are still red, and `inc/LLM/TemplatePrompt.php` currently has new WPCS drift beyond `HEAD`.

**Success Criteria:**
- `AISearchClient::maybe_search()` no longer makes a network request on the recommendation path. `AISearchClient::search()` remains the explicit blocking path for `flavor-agent/search-wordpress-docs`.
- WordPress-doc grounding failures or slowdowns no longer delay `recommend-block` or `recommend-template`; focused PHPUnit coverage proves that `maybe_search()` returns quickly without calling `wp_remote_post()`.
- The `flavor-agent/recommend-block` Abilities schema accepts the full current structural payload and a live WordPress validation check confirms the input is no longer rejected as invalid.
- The inserter helper and its test are tracked, or the import is removed. No tracked file depends on an untracked source file.
- `STATUS.md` and adjacent docs only claim verification that was actually rerun in this branch. If `composer lint:php` still fails because of broader baseline debt such as `inc/Settings.php`, the status copy says that explicitly instead of claiming green.
- The final remediation reruns and records: targeted PHPUnit, targeted JS unit tests, `npm run lint:js`, `npm run build`, and `vendor/bin/phpcs` on the remediation-touched files.

---

## File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Modify | `inc/Cloudflare/AISearchClient.php` | Separate explicit network search from non-blocking recommendation-time grounding |
| Modify | `tests/phpunit/AISearchClientTest.php` | Add cache/non-blocking regression coverage and preserve explicit-search coverage |
| Modify | `tests/phpunit/bootstrap.php` | Add transient and ability-registration test doubles needed by the new unit coverage |
| Modify | `inc/Abilities/Registration.php` | Expand `recommend-block` input schema to match the current structural payload |
| Create | `tests/phpunit/RegistrationTest.php` | Assert the registered Abilities schema admits the current structural fields |
| Modify | `docs/specs/2026-03-16-abilities-api-integration-design.md` | Keep the published Abilities contract aligned with the schema changes if it still documents the older payload |
| Track or Modify | `src/patterns/find-inserter-search-input.js` | Ensure the helper imported by tracked code is itself tracked and reviewable |
| Track or Modify | `src/patterns/__tests__/find-inserter-search-input.test.js` | Ensure the helper’s regression test is tracked with the implementation |
| Modify | `src/patterns/PatternRecommender.js` | Only if import paths or helper wiring need adjustment while resolving the packaging gap |
| Modify | `inc/LLM/TemplatePrompt.php` | Fix new WPCS drift introduced by the current diff before claiming PHP quality gates are green |
| Modify | `STATUS.md` | Replace overstated verification claims with the actual rerun matrix and residual baseline notes |
| Replace | `docs/superpowers/plans/2026-03-18-review-findings-remediation-plan.md` | Supersede the stale older draft with the current findings and execution plan |

---

## Chunk 0: Re-Baseline the Current Branch

### Task 0: Confirm the reviewed findings and isolate new drift from older baseline debt

**Files:**
- `git index / staged worktree`
- `STATUS.md`
- `inc/LLM/TemplatePrompt.php`

- [ ] **Step 1: Snapshot the exact current branch state before changing behavior**

Run:

```bash
git status --short
git diff --stat HEAD
```

Expected:
- The current remediation work starts from the same reviewed file set.
- The existing untracked helper/test gap and any unrelated user edits are visible before implementation begins.

- [ ] **Step 2: Re-run the currently green targeted checks so the plan has a current baseline**

Run:

```bash
vendor/bin/phpunit --filter 'AISearchClientTest|InfraAbilitiesTest|PromptGuidanceTest|ServerCollectorTest|BlockAbilitiesTest'
npm run test:unit -- --runInBand src/patterns/__tests__/inserter-badge-state.test.js src/patterns/__tests__/find-inserter-search-input.test.js src/store/__tests__/pattern-status.test.js src/utils/__tests__/structural-identity.test.js src/utils/__tests__/template-part-areas.test.js
npm run lint:js
npm run build
```

Expected:
- These commands pass before the new fixes start, confirming the branch is functionally stable apart from the review findings and the PHP-quality drift.

- [ ] **Step 3: Measure the current PHP-quality drift instead of assuming repo-wide PHPCS is green**

Run:

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist inc/LLM/TemplatePrompt.php
git show HEAD:inc/LLM/TemplatePrompt.php | vendor/bin/phpcs --standard=phpcs.xml.dist --stdin-path=inc/LLM/TemplatePrompt.php -
vendor/bin/phpcs --standard=phpcs.xml.dist inc/Settings.php
```

Expected:
- `inc/LLM/TemplatePrompt.php` shows new drift relative to `HEAD`.
- `inc/Settings.php` confirms there is broader baseline WPCS debt outside the current review items.
- This establishes that the remediation should fix newly introduced drift and narrow the status claims, not blindly promise repo-wide PHPCS closure unless you explicitly take on the larger cleanup.

---

## Chunk 1: Make Recommendation-Time Grounding Truly Non-Blocking

### Task 1: Split explicit docs search from recommendation-time prompt grounding

**Files:**
- `inc/Cloudflare/AISearchClient.php`
- `tests/phpunit/AISearchClientTest.php`
- `tests/phpunit/bootstrap.php`

- [ ] **Step 1: Lock the intended contract before editing code**

Implement this contract:
- `AISearchClient::search()` stays authoritative and may perform a real blocking Cloudflare request. This is the path used by `flavor-agent/search-wordpress-docs`.
- `AISearchClient::maybe_search()` becomes recommendation-safe and must never call `wp_remote_post()` directly.
- Recommendation-time grounding is best-effort only. If no cached guidance exists, the recommendation path returns `[]` immediately rather than waiting on Cloudflare.

Expected:
- The code comment and implementation finally match the current “never blocks recommendation flows” intent.

- [ ] **Step 2: Add cache helpers that support the non-blocking path**

In `AISearchClient`, add helpers for:
- Building a deterministic cache key from query text plus max-results input.
- Reading cached guidance.
- Writing sanitized guidance after a successful explicit `search()`.
- Clearing or skipping bad cache entries safely.

Preferred storage:
- Use WordPress transients or object-cache-compatible helpers so the behavior is available in both production and tests.

Expected:
- `search()` can populate cache.
- `maybe_search()` can satisfy prompt-grounding from cache without touching the network.

- [ ] **Step 3: Extend the PHPUnit bootstrap with transient stubs**

Update `tests/phpunit/bootstrap.php` so the test harness can model:
- `get_transient()`
- `set_transient()`
- `delete_transient()`

Add storage fields on `WordPressTestState` and reset them in `reset()`.

Expected:
- Unit tests can assert cache-hit and cache-miss behavior without requiring a live WordPress runtime.

- [ ] **Step 4: Rewrite `maybe_search()` around the new contract**

Preferred implementation order:
1. Return `[]` immediately when Cloudflare is not configured.
2. Look for a cached normalized guidance payload.
3. Return cached guidance when present.
4. On cache miss, return `[]` immediately.

Do **not** fall back to a live `search()` call from `maybe_search()`.

Expected:
- `BlockAbilities` and `TemplateAbilities` keep calling `maybe_search()`, but recommendation requests no longer wait on remote HTTP.

- [ ] **Step 5: Ensure explicit `search()` still performs and caches the authoritative fetch**

Keep `search()` responsible for:
- Configuration checks.
- HTTP request execution.
- Response parsing and source allowlisting.
- Writing the sanitized result to cache after success.

Expected:
- The admin-only docs-search ability still works as before, and it now seeds the cache for later recommendation calls.

- [ ] **Step 6: Add regression tests for the new non-blocking behavior**

Add PHPUnit cases covering:
- `maybe_search()` cache miss returns `[]` and does not populate `WordPressTestState::$last_remote_post`.
- `maybe_search()` cache hit returns the cached guidance and does not perform a remote request.
- `search()` still performs the remote request and stores the normalized result in cache.
- A failing explicit `search()` still returns `WP_Error` while `maybe_search()` remains safe and quiet.

Run:

```bash
vendor/bin/phpunit --filter AISearchClientTest
```

Expected:
- The targeted suite pins the non-blocking contract instead of relying on comments or manual timing assumptions.

---

## Chunk 2: Restore Abilities API Schema Parity for `recommend-block`

### Task 2: Expand the registered Abilities schema to match the current structural payload

**Files:**
- `inc/Abilities/Registration.php`
- `inc/Abilities/BlockAbilities.php`
- `tests/phpunit/bootstrap.php`
- `tests/phpunit/RegistrationTest.php`
- `docs/specs/2026-03-16-abilities-api-integration-design.md`

- [ ] **Step 1: Enumerate the actual producer/consumer field set before editing the schema**

Run:

```bash
rg -n "structuralIdentity|structuralAncestors|structuralBranch|childCount|editingMode" src/context/collector.js inc/Abilities/BlockAbilities.php inc/Abilities/Registration.php
```

Expected:
- One concrete field list drives the schema update instead of guessing from memory.

- [ ] **Step 2: Expand `selectedBlock` in `Registration::register_block_abilities()`**

Add the structural fields now consumed by `BlockAbilities`:
- `editingMode`
- `childCount`
- `structuralIdentity`
- `structuralAncestors`
- `structuralBranch`

Also re-check the existing dynamic object fields:
- `attributes`
- Any nested structural objects that may evolve (`position`, `evidence`, branch summary items)

Preferred schema approach:
- Use explicit `properties` for the stable top-level fields.
- Add `additionalProperties` deliberately where dynamic nested shapes are expected, instead of relying on implicit object behavior.

Expected:
- The Abilities API no longer strips or rejects the exact structural payload produced by the current editor collector.

- [ ] **Step 3: Add a minimal ability-registration test harness**

Extend `tests/phpunit/bootstrap.php` with:
- A store for registered abilities and categories.
- `wp_register_ability()` test double.
- `wp_register_ability_category()` test double.

Reset those stores in `WordPressTestState::reset()`.

Expected:
- The plugin can unit-test its own registration arrays without needing a full Abilities runtime in PHPUnit.

- [ ] **Step 4: Create `tests/phpunit/RegistrationTest.php`**

Add a focused test that:
1. Calls `Registration::register_category()` and `Registration::register_abilities()`.
2. Reads the stored config for `flavor-agent/recommend-block`.
3. Asserts the input schema includes the new structural keys.
4. Asserts any dynamic object fields that must remain open are explicitly documented as open.

Expected:
- A future schema regression is caught before the live Abilities route breaks again.

- [ ] **Step 5: Reconcile the published Abilities spec if it still documents the older payload**

Review and update `docs/specs/2026-03-16-abilities-api-integration-design.md` if needed so it reflects:
- The expanded `selectedBlock` structural fields.
- The fact that schema parity with the JS editor collector is required whenever new fields are added.

Run:

```bash
rg -n "selectedBlock|recommend-block|structuralIdentity|structuralAncestors|structuralBranch|childCount|editingMode" docs/specs/2026-03-16-abilities-api-integration-design.md
```

Expected:
- The published design doc no longer advertises a stale or incomplete `recommend-block` contract.

- [ ] **Step 6: Perform one live WordPress validation check against the registered ability**

Use WP-CLI to validate the registered ability input directly:

```bash
wp eval '
$ability = wp_get_ability( "flavor-agent/recommend-block" );
$input = [
	"selectedBlock" => [
		"blockName" => "core/navigation",
		"attributes" => [],
		"editingMode" => "default",
		"childCount" => 1,
		"structuralIdentity" => [
			"role" => "footer-navigation",
			"location" => "footer",
		],
		"structuralAncestors" => [
			[
				"block" => "core/template-part",
				"role" => "footer-slot",
			],
		],
		"structuralBranch" => [
			[
				"block" => "core/template-part",
				"role" => "footer-slot",
			],
		],
	],
];
var_dump( $ability->validate_input( $ability->normalize_input( $input ) ) );
'
```

Expected:
- Validation returns `bool(true)` instead of a schema error.

Optional REST-path confirmation:

```bash
curl -s -X POST "$SITE_URL/wp-json/wp-abilities/v1/abilities/flavor-agent/recommend-block/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: $NONCE" \
  --data '{"input":{"selectedBlock":{"blockName":"core/navigation","attributes":{},"editingMode":"default","childCount":1,"structuralIdentity":{"role":"footer-navigation","location":"footer"},"structuralAncestors":[{"block":"core/template-part","role":"footer-slot"}],"structuralBranch":[{"block":"core/template-part","role":"footer-slot"}]}}}'
```

Expected:
- The request is no longer rejected with `ability_invalid_input`. If API keys are missing, a later business-logic error such as `missing_api_key` is acceptable for this verification step.

---

## Chunk 3: Fix the Tracked/Untracked Packaging Gap

### Task 3: Ensure tracked code no longer depends on untracked source files

**Files:**
- `src/patterns/PatternRecommender.js`
- `src/patterns/find-inserter-search-input.js`
- `src/patterns/__tests__/find-inserter-search-input.test.js`

- [ ] **Step 1: Decide whether the helper remains extracted or is folded back inline**

Preferred direction:
- Keep the extracted helper because the focused test already exists and the separation is useful.

Alternative only if necessary:
- Inline the logic back into `PatternRecommender.js` and remove the helper import entirely.

Expected:
- There is exactly one supported implementation shape, and it is fully tracked.

- [ ] **Step 2: Make the dependency graph fully tracked**

If keeping the helper:

```bash
git add src/patterns/find-inserter-search-input.js src/patterns/__tests__/find-inserter-search-input.test.js
```

If inlining instead:
- Remove the import from `PatternRecommender.js`.
- Delete the stale helper/test files from the worktree.

Expected:
- A tracked file is no longer importing a `??` path.

- [ ] **Step 3: Verify the tracked-only state explicitly**

Run:

```bash
git status --short
git ls-files src/patterns/find-inserter-search-input.js src/patterns/__tests__/find-inserter-search-input.test.js
rg -n "find-inserter-search-input" src/patterns/PatternRecommender.js src/patterns/__tests__/find-inserter-search-input.test.js
npm run build
```

Expected:
- The helper/test are either listed by `git ls-files` or no longer referenced.
- `git status --short` no longer shows the required helper as `??`.
- The build succeeds from tracked inputs.

---

## Chunk 4: Clean Up New Quality and Documentation Drift

### Task 4: Fix newly introduced drift and narrow the status claims to actual evidence

**Files:**
- `inc/LLM/TemplatePrompt.php`
- `STATUS.md`
- `docs/superpowers/plans/2026-03-18-review-findings-remediation-plan.md`

- [ ] **Step 1: Fix the new WPCS drift in remediation-touched files**

At minimum, clean up the files whose new diff introduced extra WPCS failures:
- `inc/LLM/TemplatePrompt.php`
- Any new/changed PHPUnit files added by this remediation

Do **not** silently expand scope to repo-wide legacy files such as `inc/Settings.php` unless the user asks for the broader cleanup.

Run:

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist inc/LLM/TemplatePrompt.php tests/phpunit/AISearchClientTest.php tests/phpunit/RegistrationTest.php tests/phpunit/bootstrap.php
```

Expected:
- Newly touched remediation files are green even if older baseline files elsewhere still are not.

- [ ] **Step 2: Update `STATUS.md` only after the final rerun matrix is complete**

Revise `STATUS.md` so it:
- Keeps the feature inventory accurate.
- Lists only the verification commands actually rerun in this branch.
- Removes or narrows the blanket `composer lint:php` green claim if broader WPCS baseline debt remains.

Preferred wording if full repo-wide PHPCS still fails:
- “Targeted PHPCS on remediation-touched files passed; broader baseline WPCS debt remains in older files such as `inc/Settings.php`.”

Expected:
- `STATUS.md` becomes a truthful branch snapshot rather than an aspirational one.

- [ ] **Step 3: Treat this plan file as the replacement for the stale earlier March 18 draft**

Do not keep competing March 18 remediation narratives in the same path. If additional follow-up findings appear later, append them as a new dated plan file instead of silently mutating this one into a different review again.

Expected:
- The plan path stays single-purpose and traceable.

---

## Chunk 5: Final Verification and Closure

### Task 5: Re-run the final matrix and record residual risk boundaries

**Files:**
- `inc/Cloudflare/AISearchClient.php`
- `inc/Abilities/Registration.php`
- `tests/phpunit/*`
- `src/patterns/*`
- `STATUS.md`

- [ ] **Step 1: Run the focused PHP verification slice**

Run:

```bash
vendor/bin/phpunit --filter 'AISearchClientTest|InfraAbilitiesTest|PromptGuidanceTest|ServerCollectorTest|BlockAbilitiesTest|RegistrationTest'
```

Expected:
- Explicit search, non-blocking grounding, and registration-schema changes all pass together.

- [ ] **Step 2: Run the focused JS verification slice**

Run:

```bash
npm run test:unit -- --runInBand src/patterns/__tests__/find-inserter-search-input.test.js src/patterns/__tests__/inserter-badge-state.test.js src/store/__tests__/pattern-status.test.js src/utils/__tests__/structural-identity.test.js src/utils/__tests__/template-part-areas.test.js
npm run lint:js
npm run build
```

Expected:
- The helper packaging fix and existing pattern-related coverage still hold.

- [ ] **Step 3: Run WPCS on the remediation-touched files and decide whether to widen scope**

Run:

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist inc/Cloudflare/AISearchClient.php inc/Abilities/Registration.php inc/LLM/TemplatePrompt.php tests/phpunit/AISearchClientTest.php tests/phpunit/RegistrationTest.php tests/phpunit/bootstrap.php
```

Expected:
- The new remediation files are clean.
- If broader `composer lint:php` is still desired, treat that as a conscious extra scope item rather than assuming it is part of this bugfix by default.

- [ ] **Step 4: Perform one live Abilities API smoke check and one live tracked-state check**

Run:

```bash
curl -s "$SITE_URL/wp-json/wp-abilities/v1/abilities" | jq '.[] | select(.id == "flavor-agent/recommend-block")'
git status --short
```

Expected:
- The Abilities listing shows the updated `recommend-block` ability.
- The worktree no longer contains untracked files required by tracked imports.

- [ ] **Step 5: Record residual risks explicitly before closing**

If any of these remain true, record them in `STATUS.md` or the final implementation summary:
- Full repo-wide `composer lint:php` still fails because of older baseline files.
- Live REST-path execution was not tested with valid LLM credentials.
- Cache warm-up behavior for first-time grounding is intentionally reduced compared with the old blocking implementation and may merit a separate follow-up if higher-quality first-request grounding is required.

Expected:
- Closure distinguishes fixed regressions from intentionally deferred follow-up work.
