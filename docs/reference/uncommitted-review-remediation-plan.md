# Uncommitted Review Remediation Plan

Date: 2026-04-29

Status: implemented

Scope: Address all findings from the uncommitted-change review, including the pattern recommendation regressions, the block undo regression, and failing local validation gates.

Implementation result: completed in this changeset. Targeted PHP/JS suites, full JS unit tests, full PHP unit tests, JS lint, PHP lint, docs freshness, and build passed. `node scripts/verify.js --skip-e2e` reported `incomplete` only because `lint-plugin` could not find a WordPress root via `WP_PLUGIN_CHECK_PATH` and both E2E suites were intentionally skipped.

## Relationship To The Pattern Audit Plan

`docs/reference/pattern-recommendation-audit-remediation-plan.md` addresses the original pattern audit findings:

- Synced pattern recommendation access leakage.
- Inserter badge counts unrenderable pattern recommendations.
- Pattern docs grounding should not perform foreground AI Search work.

That document was not a complete solution for the later review findings. The staged implementation at the time introduced additional regressions that are now resolved:

- `PatternAbilitiesTest` is red because many recommendation tests now run with a hidden default `visiblePatternNames` scope of only `theme/hero`.
- The synced-pattern indexing change now excludes published `partial` and `unsynced` user pattern posts, even when those `core/block/{id}` patterns are public-safe and visible in Gutenberg.
- Block activity undo now fails when the same block moved paths even if the block type and attributes still match the recorded applied state.
- JS and PHP linters are failing.

The audit plan has since been aligned so its "public-safe indexing" language means published `wp_block` user patterns across sync states, not only published patterns whose sync status is `synced`.

## Target Outcome

- Pattern recommendations fail closed when no `visiblePatternNames` scope is supplied.
- Tests that exercise normal recommendation ranking provide realistic visible scopes and pass.
- The pattern index contains only public-safe published user patterns, while still preserving published `synced`, `partial`, and `unsynced` pattern posts as recommendation candidates.
- Synced/user candidates are always rehydrated and authorized with current `read_post` access before ranking or response output.
- Block undo remains safe but does not reject a valid target solely because its path changed.
- Block execution-contract filtering rejects unsupported local attributes without dropping valid content/config attributes when a partial contract omits local key lists.
- Inserter badge state is derived from renderable recommendations for the current allowed-pattern scope, not a raw store-level badge cache.
- `npm run lint:js`, `composer run lint:php`, and the targeted PHP/JS suites pass.

## Fix 1: Repair Pattern Visible-Scope Test Coverage

### Problem

`PatternAbilities::recommend_patterns()` now correctly returns empty recommendations when `visiblePatternNames` is missing. The test helper at `tests/phpunit/PatternAbilitiesTest.php` hides that new contract by injecting `['theme/hero']` for every test that omits the field. Tests that mock other candidate names now filter every candidate out and fail before ranking.

### Implementation

1. Replace the hidden default in `recommend_patterns()` test helper.
   - Do not silently inject `['theme/hero']` for all callers.
   - Prefer a helper such as `recommend_patterns_with_visible_scope( array $input, array $visible_pattern_names )`.
   - Keep direct calls to `PatternAbilities::recommend_patterns()` for the missing-scope and explicit-empty-scope tests.

2. Add explicit `visiblePatternNames` to each ranking-path test.
   - For structural tests, include the exact mocked candidate names, such as `theme/header-utility`, `theme/root-section`, `theme/post-feature`, and `theme/template-part-shell`.
   - For override tests, include `theme/override-ready`, `theme/custom-block-generic`, and any competing candidates needed to prove filtering.
   - For cap/sort/threshold tests, include all mocked candidate names so max-result and threshold assertions exercise ranking rather than visibility filtering.

3. Preserve fail-closed production behavior.
   - Keep the existing missing-scope ability and REST tests.
   - Assert no embedding, Qdrant, docs, or ranker calls occur when the visible scope is missing or explicitly empty.

### Verification

```bash
composer run test:php -- --filter PatternAbilitiesTest
composer run test:php -- --filter AgentControllerTest
```

## Fix 2: Index Public-Safe User Patterns Without Dropping Published Partial Or Unsynced Patterns

### Problem

The current implementation changes `SyncedPatternRepository::for_indexable_patterns()` to `for_patterns( 'synced', ..., 'publish' )`. This prevents private/draft/trash content from entering Qdrant, but it also drops published user pattern posts whose `wp_pattern_sync_status` is `partial` or `unsynced`.

Published `partial` and `unsynced` user patterns can still be Gutenberg-visible `core/block/{id}` inserter candidates. Dropping them makes recommendations incomplete.

### Implementation

1. Change indexability to mean public-safe publication state, not only sync status.
   - Use `post_status = publish`.
   - Use `syncStatus = all` so published `synced`, `partial`, and `unsynced` user pattern posts remain indexable.
   - Keep `include_content = true` for indexing payloads.
   - Keep `enforce_access = false` for indexing because the global corpus must not depend on the cron/admin user.

2. Keep request-time authorization strict.
   - Continue treating Qdrant synced/user payloads as untrusted.
   - Resolve `core/block/{id}`, `source: synced`, `type: user`, or `syncedPatternId` candidates to the current `wp_block` post.
   - Require `current_user_can( 'read_post', $post_id )` before building ranking hints, ranker input, or response output.
   - Rehydrate title/content/sync metadata from the current post after access passes.

3. Update tests and docs.
   - Update `PatternIndexTest` to assert published `synced`, published `partial`, and published `unsynced` posts are indexed.
   - Assert private, draft, and trash posts are absent from Qdrant upserts and remote request bodies.
   - Update `docs/reference/pattern-recommendation-audit-remediation-plan.md` and `docs/features/pattern-recommendations.md` so "public-safe" is documented as published user patterns, not synced-only user patterns.

### Verification

```bash
composer run test:php -- --filter PatternIndexTest
composer run test:php -- --filter PatternAbilitiesTest
```

## Fix 3: Preserve Safe Undo When A Block Moves

### Problem

`hasResolvedActivityBlockMoved()` is currently treated as a hard failure in `activity-history.js` and `activity-undo.js`. This blocks undo for a valid `clientId` target that moved within the block tree, even when:

- The block still exists.
- The block name still matches.
- Current attributes still match the recorded "after" snapshot.

The existing attribute snapshot check is the stronger safety gate for undoing attribute-only suggestions.

### Implementation

1. Stop using path drift as a hard failure when the block resolves by `clientId`.
   - In `undoBlockActivity()`, remove `hasResolvedActivityBlockMoved()` from the hard failure condition.
   - Continue failing when the block is missing, block name changed, current attributes are unavailable, or current attributes no longer match the recorded after snapshot.

2. Keep path fallback behavior for missing `clientId`.
   - If `clientId` lookup fails and `blockPath` lookup resolves a block, keep the existing name and attribute snapshot checks before allowing undo.
   - Do not undo solely because a block exists at the old path.

3. Adjust activity state tests.
   - Replace the "moved block fails" expectation with "moved block remains undoable when type and attributes match".
   - Keep or add tests for moved/replaced targets where the block name differs or the after snapshot differs; those must still fail.

4. Consider diagnostics separately.
   - If path drift is useful for UI context, expose it as non-blocking metadata rather than an undo failure reason.

### Verification

```bash
npm run test:unit -- src/store/__tests__/activity-history.test.js src/store/__tests__/store-actions.test.js --runInBand
```

## Fix 4: Clear Lint Failures

### Problem

The current staged changes fail both JS and PHP linting:

- `src/patterns/__tests__/InserterBadge.test.js` has Prettier indentation/wrapping errors.
- `inc/Support/CollectsDocsGuidance.php`, `inc/Context/SyncedPatternRepository.php`, and `tests/phpunit/PatternAbilitiesTest.php` have PHPCS alignment warnings.

### Implementation

1. Format the JS test.
   - Run the repo formatter/linter fix for the affected test or manually apply the Prettier output.
   - Keep test semantics unchanged.

2. Fix PHPCS alignment.
   - Run `vendor/bin/phpcbf` on the affected PHP files or manually align assignments to match the repo rules.
   - Keep changes limited to formatting.

### Verification

```bash
npm run lint:js
composer run lint:php
```

## Fix 5: Close Review Follow-Ups

### Problem

The follow-up review found no blocking regressions, but identified two contract risks:

- Partial execution contracts could drop otherwise valid block-local attributes when `contentAttributeKeys` or `configAttributeKeys` were omitted.
- `patternBadge` store state could drift from the renderable inserter badge because it was derived from raw recommendations instead of the current allowed-pattern scope.

### Implementation

1. Merge partial execution contracts with block-local attribute keys before filtering.
   - JS derives missing local keys from the stored block context when a server contract is partial.
   - PHP derives missing local keys from the block context passed to `Prompt::enforce_block_context_rules()`.
   - Unsupported local attributes are still rejected when the complete local key set is known.

2. Remove raw `patternBadge` state from the store.
   - `InserterBadge` reads raw recommendations and allowed patterns separately, then memoizes the renderable recommendation list and badge reason.
   - Ready badge count and tooltip reflect only recommendations that Gutenberg can render at the current inserter root.

3. Keep security and readability cleanup intact.
   - `lock` remains blocked by theme-safety filtering and execution-contract filtering.
   - Pattern recommendation visible-scope checks now use a single fail-closed branch.

### Verification

```bash
npm run test:unit -- src/store/update-helpers.test.js src/patterns/__tests__/InserterBadge.test.js src/store/__tests__/pattern-status.test.js --runInBand
vendor/bin/phpunit --filter PromptRulesTest
```

## Fix 6: Re-run Cross-Surface Gates

After the targeted fixes pass, run the additive local gates required by the repository guidance for shared recommendation and apply/undo code.

```bash
npm run test:unit -- src/patterns/__tests__/InserterBadge.test.js src/patterns/__tests__/recommendation-utils.test.js src/store/update-helpers.test.js src/store/__tests__/store-actions.test.js src/store/__tests__/activity-history.test.js --runInBand
vendor/bin/phpunit --filter 'PatternAbilitiesTest|PatternIndexTest|PromptRulesTest|AISearchClientTest|AgentControllerTest'
npm run lint:js
composer run lint:php
npm run check:docs
node scripts/verify.js --skip-e2e
```

If a browser harness is available, run the pattern inserter smoke coverage as release evidence:

```bash
npx playwright test tests/e2e/flavor-agent.smoke.spec.js -g "pattern surface smoke uses the inserter search to fetch recommendations"
```

If Playwright or the local WordPress environment is unavailable, record that as an explicit blocker or waiver rather than silently skipping it.

## Completion Checklist

- [x] Pattern missing-scope and explicit-empty-scope tests pass and assert no remote calls.
- [x] Ranking-path tests pass with explicit visible scopes.
- [x] Published `synced`, `partial`, and `unsynced` user patterns are indexed; private/draft/trash user patterns are not.
- [x] Synced/user recommendation candidates are rehydrated and authorized before ranking.
- [x] Moved block targets remain undoable when `clientId`, block name, and after-attribute snapshot still match.
- [x] Replaced or mutated block targets still fail undo safely.
- [x] Partial execution contracts preserve valid block-local content/config attributes while still rejecting unsupported local attributes.
- [x] Inserter badge derives count and reason from renderable allowed-pattern matches and no longer uses raw `patternBadge` store state.
- [x] JS lint passes.
- [x] PHP lint passes.
- [x] Targeted PHP and JS tests pass.
- [x] Docs reflect the final implemented behavior.
