# External Apply Target Authorization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure every governed external apply and server-side undo authorizes the exact canonical WordPress target that the executor will read, mutate, and attest.

**Architecture:** Expand `ExternalApplyExecutor` with canonical identity resolution and target authorization. Each executor becomes the single parser for its security-relevant `target`, and its baseline, execute, and undo methods consume that resolver. Approval authorizes before baseline collection; undo preserves lifecycle and ordered-undo precedence, then authorizes before executor content resolution. Apply and revert attestations consume only canonical executor targets.

**Tech Stack:** PHP 8.2+, WordPress 7.0+, PSR-4 `FlavorAgent\`, WordPress Abilities API, PHPUnit 9 with repository WordPress stubs, Composer, npm verification runner.

## Global Constraints

- Work only in `.worktrees/release-hardening-v0-1-0`; preserve the two already modified specification files.
- Implement only the target-authorization security slice. Corpus, provider, release, deployment, and adaptive-ranking work remain out of scope.
- Do not commit, push, open or update a pull request, merge, deploy, tag, publish, or make external calls under the current authorization.
- Follow strict TDD: add a discriminating test, run it and capture the expected failure, then change production code.
- Identity validation precedes capability evaluation inside `authorize_target()`.
- Use `flavor_agent_apply_target_mismatch` with HTTP 409 for missing, malformed, non-canonical, or divergent identities.
- Use `flavor_agent_apply_target_forbidden` with HTTP 403 for a coherent target that the current user cannot edit.
- Do not add a production filter, executor override, or caller-controlled authorization seam. Test-only observation belongs in `tests/phpunit/bootstrap.php`.
- WordPress core may hydrate post metadata for `get_post_type()` and meta-capability mapping, but Flavor Agent must not collect, inspect, parse, hash, compare, or mutate target content before authorization succeeds.
- Use tabs in PHP and keep `declare(strict_types=1);`.

---

## File Structure

| File | Responsibility |
| --- | --- |
| `inc/Apply/ExternalApplyExecutor.php` | Declares canonical identity and authorization contracts. |
| `inc/Apply/StyleApplyExecutor.php` | Canonical Global Styles / Style Book identity and theme capability. |
| `inc/Apply/TemplateApplyExecutor.php` | Canonical template ref/document identity and theme capability. |
| `inc/Apply/TemplatePartApplyExecutor.php` | Required executor ID, equal optional alias, canonical ref/document identity, and theme capability. |
| `inc/Apply/PostBlocksApplyExecutor.php` | Positive post ID, actual post-type binding, canonical document identity, and target meta-capability. |
| `inc/Apply/PendingApplyDecision.php` | Approval-time authorization before baseline resolution and canonical apply-attestation target use. |
| `inc/Abilities/ApplyAbilities.php` | Undo-time authorization after ordered-undo checks and canonical revert-attestation target use. |
| `tests/phpunit/bootstrap.php` | Test-only metadata/capability/content-read call counters and `get_post_type()` stub. |
| `tests/phpunit/*ApplyExecutorTest.php` | Direct resolver/authorizer matrix and regression coverage for all four executors. |
| `tests/phpunit/ExternalApplyLifecycleTest.php` | Approval ordering, failed-row persistence, happy paths, and canonical attestation coverage. |
| `tests/phpunit/ApplyAbilitiesTest.php` | Undo ordering, no-transition denial, compatibility rows, and canonical revert attestation. |
| `tests/phpunit/ActivityPermissionsTest.php` | Outer permission denials remain transport-level and do not transition rows. |
| `docs/superpowers/specs/2026-08-02-external-apply-target-authorization-design.md` | Records local implementation/verification status without claiming integration or release. |
| `docs/superpowers/specs/2026-08-02-v0-1-0-release-hardening-program-design.md` | Records Slice 1 local status while leaving later slices and final-SHA gates open. |

---

### Task 1: Add test-only observation seams and direct executor RED tests

**Files:**
- Modify: `tests/phpunit/bootstrap.php:18-180`, `:2411-2438`, `:3393-3409`
- Modify: `tests/phpunit/StyleApplyExecutorTest.php`
- Modify: `tests/phpunit/TemplateApplyExecutorTest.php`
- Modify: `tests/phpunit/TemplatePartApplyExecutorTest.php`
- Modify: `tests/phpunit/PostBlocksApplyExecutorTest.php`

**Interfaces:**
- Consumes: `WordPressTestState::$posts`, `::$capabilities`, `::$updated_posts`, and each suite's existing entry builder.
- Produces: `WordPressTestState::$capability_checks`, `::$get_post_calls`, and `::$get_post_type_calls`; direct tests for `resolve_target_identity(array): array|WP_Error` and `authorize_target(array): true|WP_Error`.

- [ ] **Step 1: Add test-only counters and a core metadata stub**

Add and reset these properties in `WordPressTestState`:

```php
	/** @var array<int, array{capability: string, args: array<int, mixed>}> */
	public static array $capability_checks = [];

	/** @var array<int, int> */
	public static array $get_post_calls = [];

	/** @var array<int, int> */
	public static array $get_post_type_calls = [];
```

Record calls at the start of the existing `current_user_can()` and `get_post()` stubs. Add a `get_post_type()` stub that records the ID and returns `WordPressTestState::$posts[$id]->post_type` without invoking the Flavor Agent collector:

```php
	if (! function_exists('get_post_type')) {
		function get_post_type($post = null)
		{
			$id = (int) (is_object($post) ? ($post->ID ?? 0) : $post);
			WordPressTestState::$get_post_type_calls[] = $id;

			return isset(WordPressTestState::$posts[$id])
				? (string) (WordPressTestState::$posts[$id]->post_type ?? '')
				: false;
		}
	}
```

- [ ] **Step 2: Make legitimate direct-executor fixtures canonical**

Update the entry/executed-entry builders so they carry the identity their executor is expected to derive. Examples:

```php
// Template.
'document' => [
	'entityId' => self::TEMPLATE_REF,
	'postType' => 'wp_template',
	'scopeKey' => 'wp_template:' . self::TEMPLATE_REF,
],

// Template part. Keep ID-only fixtures where the compatibility rule is under test.
'target' => [
	'templatePartId'  => self::PART_ID,
	'templatePartRef' => self::PART_ID,
],
'document' => [
	'entityId' => self::PART_ID,
	'postType' => 'wp_template_part',
	'scopeKey' => 'wp_template_part:' . self::PART_ID,
],

// Post blocks.
'target' => [ 'postId' => $post_id, 'postType' => $post_type ],
'document' => [
	'entityId' => (string) $post_id,
	'postType' => $post_type,
	'scopeKey' => $post_type . ':' . $post_id,
],
```

For styles, construct the scope with `StyleAbilities::canonical_scope_key_for()` and require `document.postType = global_styles`.

- [ ] **Step 3: Add the theme-territory identity/capability matrix**

In the style, template, and template-part suites add tests that call the new public methods directly. The template test has this exact assertion shape; use its surface-specific canonical target/document values in the style and template-part files:

```php
$identity = \FlavorAgent\Apply\TemplateApplyExecutor::resolve_target_identity( $entry );
$this->assertIsArray( $identity );
$this->assertSame(
	[ 'templateRef' => self::TEMPLATE_REF, 'templateType' => 'home' ],
	$identity['target']
);
$this->assertSame(
	[
		'entityId' => self::TEMPLATE_REF,
		'postType' => 'wp_template',
		'scopeKey' => 'wp_template:' . self::TEMPLATE_REF,
	],
	$identity['document']
);

WordPressTestState::$capabilities['edit_theme_options'] = true;
$this->assertTrue( \FlavorAgent\Apply\TemplateApplyExecutor::authorize_target( $entry ) );
```

Clone the canonical entry and independently change/remove `document.entityId`, `document.postType`, and `document.scopeKey`; each must return `flavor_agent_apply_target_mismatch`. With the canonical entry and `edit_theme_options = false`, assert `flavor_agent_apply_target_forbidden` with status 403.

For template parts add five explicit fixtures: equal dual aliases accepted; ID-only accepted and canonicalized to both aliases; ref-only rejected; both missing rejected; unequal aliases rejected. On alias mismatch assert `WordPressTestState::$capability_checks === []` and no block-template/content read occurred.

- [ ] **Step 4: Add the post-block target/object authorization matrix**

Seed posts 100 and 200. Add direct tests covering:

```php
$entry = $this->entry( 200, [], 'page' );
WordPressTestState::$capabilities['edit_post:200'] = false;

$result = PostBlocksApplyExecutor::authorize_target( $entry );

$this->assertInstanceOf( \WP_Error::class, $result );
$this->assertSame( 'flavor_agent_apply_target_forbidden', $result->get_error_code() );
$this->assertSame( [ 200 ], WordPressTestState::$get_post_type_calls );
$this->assertSame( [], WordPressTestState::$get_post_calls );
$this->assertSame( [ [ 'capability' => 'edit_post', 'args' => [ 200 ] ] ], WordPressTestState::$capability_checks );
$this->assertSame( [], WordPressTestState::$updated_posts );
```

Also assert: positive canonical ID succeeds; document 100 with target 200 mismatches even when both posts are editable; actual type `page` with stored target/document type `post` mismatches before capability evaluation; missing/non-positive ID, missing target post type, missing document post type, and non-canonical scope all mismatch.

- [ ] **Step 5: Run the direct tests and capture RED**

Run each command separately so PHPUnit discovers exactly the intended file:

```powershell
composer run test:php -- tests/phpunit/StyleApplyExecutorTest.php
composer run test:php -- tests/phpunit/TemplateApplyExecutorTest.php
composer run test:php -- tests/phpunit/TemplatePartApplyExecutorTest.php
composer run test:php -- tests/phpunit/PostBlocksApplyExecutorTest.php
```

Expected: failures because the four executor classes do not yet define `resolve_target_identity()` or `authorize_target()`. Existing tests should not fail for unrelated fixture drift after Step 2.

---

### Task 2: Implement canonical resolution and authorization in every executor

**Files:**
- Modify: `inc/Apply/ExternalApplyExecutor.php`
- Modify: `inc/Apply/StyleApplyExecutor.php`
- Modify: `inc/Apply/TemplateApplyExecutor.php`
- Modify: `inc/Apply/TemplatePartApplyExecutor.php`
- Modify: `inc/Apply/PostBlocksApplyExecutor.php`

**Interfaces:**
- Consumes: direct RED tests and WordPress core `get_post_type()` / `current_user_can()`.
- Produces: the expanded `ExternalApplyExecutor` contract and canonical targets used later by approval, undo, and attestations.

- [ ] **Step 1: Expand the executor interface**

Insert before `resolve_baseline()`:

```php
	/**
	 * @return array{
	 *     target: array<string, int|string>,
	 *     document: array{entityId: string, postType: string, scopeKey: string}
	 * }|\WP_Error
	 */
	public static function resolve_target_identity( array $entry ): array|\WP_Error;

	public static function authorize_target( array $entry ): true|\WP_Error;
```

- [ ] **Step 2: Implement Global Styles / Style Book resolution and authorization**

Normalize only the two supported surfaces; require a non-empty trimmed Global Styles ID and, for Style Book, a non-empty sanitized block name. Return the canonical target and:

```php
'document' => [
	'entityId' => $global_styles_id,
	'postType' => 'global_styles',
	'scopeKey' => StyleAbilities::canonical_scope_key_for( $surface, $global_styles_id, $block_name ),
],
```

`authorize_target()` compares the persisted document to that exact array before calling `current_user_can( 'edit_theme_options' )`. Return the two stable errors from Global Constraints. Refactor `resolve_baseline()`, `execute()`, and `undo()` to obtain the ID/block name from `resolve_target_identity()` rather than re-reading `entry.target`.

- [ ] **Step 3: Implement template resolution and authorization**

Trim `target.templateRef`, require `surface === template`, and return:

```php
'target' => array_merge(
	[ 'templateRef' => $ref ],
	'' !== $type ? [ 'templateType' => $type ] : []
),
'document' => [
	'entityId' => $ref,
	'postType' => 'wp_template',
	'scopeKey' => 'wp_template:' . $ref,
],
```

Compare exact document fields, then require `edit_theme_options`. Replace `template_ref($entry)` calls in `resolve_baseline()`, `execute()`, and `undo()` with canonical resolver output. Successful `execute()` must return the resolver target enriched only with labels from the re-gated live entity.

The live template resolver must also bind the returned object's `id` to the canonical ref. Do not permit `TemplateRepository`'s `wp_id`/slug compatibility fallback to turn ref A into entity B; use exact resolution or return `flavor_agent_apply_target_mismatch` before consuming B's content.

- [ ] **Step 4: Implement the template-part alias rule exactly once**

Trim `target.templatePartId` and, only when the key exists, trim `target.templatePartRef`. Reject empty ID, ref-only rows, and unequal dual aliases. Return both aliases set to the ID:

```php
'target' => [
	'templatePartId'  => $ref,
	'templatePartRef' => $ref,
],
'document' => [
	'entityId' => $ref,
	'postType' => 'wp_template_part',
	'scopeKey' => 'wp_template_part:' . $ref,
],
```

Compare exact document fields, then require `edit_theme_options`. Remove `part_ref()` as an independent parser; make baseline, execute, and undo consume the resolver. Preserve ID-only legacy acceptance by returning both aliases from the canonical result.

As with templates, exact live resolution is part of the security contract: a same-slug part with a different theme-qualified `id` must fail with `flavor_agent_apply_target_mismatch` before content access or mutation.

- [ ] **Step 5: Implement post-block identity binding before content access**

Parse a positive integer from `target.postId`, obtain the actual type with `get_post_type($post_id)`, require a non-empty canonical stored `target.postType` that exactly equals the actual type, and return:

```php
'target' => [
	'postId'   => $post_id,
	'postType' => $actual_post_type,
],
'document' => [
	'entityId' => (string) $post_id,
	'postType' => $actual_post_type,
	'scopeKey' => $actual_post_type . ':' . $post_id,
],
```

After exact document comparison, call `current_user_can( 'edit_post', $post_id )`. Refactor baseline, execute, and undo to consume the returned canonical post ID/type; none may call `ServerCollector` until the resolver has succeeded. Return the canonical target from execute.

- [ ] **Step 6: Run direct suites GREEN and lint the changed PHP**

```powershell
composer run test:php -- tests/phpunit/StyleApplyExecutorTest.php
composer run test:php -- tests/phpunit/TemplateApplyExecutorTest.php
composer run test:php -- tests/phpunit/TemplatePartApplyExecutorTest.php
composer run test:php -- tests/phpunit/PostBlocksApplyExecutorTest.php
composer run lint:php
```

Expected: all four test files pass; PHPCS exits 0. Revert one document-field comparison locally, rerun its new mismatch test to prove the test fails, then restore the comparison and rerun GREEN.

---

### Task 3: Enforce authorization at approval/undo dispatch and canonicalize attestations

**Files:**
- Modify: `tests/phpunit/ExternalApplyLifecycleTest.php`
- Modify: `tests/phpunit/ApplyAbilitiesTest.php`
- Modify: `tests/phpunit/ActivityPermissionsTest.php`
- Modify: `inc/Apply/PendingApplyDecision.php:82-129`, `:149-173`
- Modify: `inc/Abilities/ApplyAbilities.php:942-1002`

**Interfaces:**
- Consumes: `ExternalApplyExecutor::authorize_target()` and `::resolve_target_identity()` from Task 2.
- Produces: service-layer approval/undo ordering, stable persistence semantics, and canonical apply/revert attestation subjects.

- [ ] **Step 1: Add approval adversarial tests**

Create a pending post-block row whose document names post 100 and target names post 200, with a matching request-time baseline for 200. Call `PendingApplyDecision::decide($id, 'approve')` directly. Assert the returned/stored row has `apply.status = failed`, `failureCode = flavor_agent_apply_target_mismatch`, decision attribution is preserved, both posts are byte-identical, `get_post_calls` and `updated_posts` are empty, and no attestation is recorded.

Add a coherent target-200 row with `edit_post:200 = false`; assert failed persistence with `flavor_agent_apply_target_forbidden`. Add the both-editable divergent case to prove capability cannot bless dishonest identity.

- [ ] **Step 2: Add undo adversarial and precedence tests**

For a topmost available executed post-block row with document 100/target 200, call `ApplyAbilities::undo_activity()` directly and assert the returned error code is `flavor_agent_apply_target_mismatch`; the row remains `undo.status = available`, target content and undo metadata are unchanged, `get_post_calls` / `updated_posts` are empty, and no revert attestation is recorded.

Add a coherent target-200 row denied `edit_post:200` and expect `flavor_agent_apply_target_forbidden` with the same no-transition guarantees. Add an older blocked row with malformed identity and assert the existing `flavor_agent_activity_undo_blocked` error wins and `get_post_type_calls` / `capability_checks` stay empty.

- [ ] **Step 3: Add outer permission-boundary tests**

Exercise the existing REST decision permission callback and Ability permission callback with denied access. Assert their existing generic permission error, no target authorizer counters, and unchanged pending/available rows. These tests distinguish transport-level denial from direct service target errors.

- [ ] **Step 4: Add canonical attestation tests**

For template-part approval, use an ID-only legacy row with a complete canonical document, approve it, and assert the stored execution target contains equal `templatePartId` and `templatePartRef`; the apply attestation subject uses that canonical ref. For undo, assert the revert attestation uses `resolve_target_identity($entry)['target']['templatePartRef']` and never falls back from ref to ID at the call site. Add a corrupt dual-alias row and assert neither attestation recorder is reached.

- [ ] **Step 5: Run lifecycle suites and capture RED**

```powershell
composer run test:php -- tests/phpunit/ExternalApplyLifecycleTest.php
composer run test:php -- tests/phpunit/ApplyAbilitiesTest.php
composer run test:php -- tests/phpunit/ActivityPermissionsTest.php
```

Expected: the new direct-service tests fail because approval currently reaches baseline resolution and undo currently reaches executor undo without first calling target authorization; the outer-permission tests should already pass.

- [ ] **Step 6: Authorize approval before baseline resolution**

Immediately after executor lookup, add:

```php
$authorized = $executor::authorize_target( $entry );

if ( is_wp_error( $authorized ) ) {
	return ActivityRepository::transition_external_apply(
		$activity_id,
		[
			'applyStatus'    => 'failed',
			'decidedBy'      => $decided_by,
			'decidedByName'  => $decided_by_name,
			'decidedAt'      => $decided_at,
			'decisionNote'   => $note,
			'failureCode'    => (string) $authorized->get_error_code(),
			'failureMessage' => (string) $authorized->get_error_message(),
		]
	);
}
```

Only then call `resolve_baseline()` and `execute()`.

- [ ] **Step 7: Authorize undo after ordered-undo validation and before executor undo**

Insert after `can_perform_ordered_undo()` succeeds:

```php
$authorized = $executor::authorize_target( $entry );

if ( is_wp_error( $authorized ) ) {
	return $authorized;
}

$identity = $executor::resolve_target_identity( $entry );

if ( is_wp_error( $identity ) ) {
	return $identity;
}
```

Keep this before `$executor::undo($entry)`. Do not update undo state for either authorization error.

- [ ] **Step 8: Remove attestation alias fallbacks**

Approval consumes the canonical execution result:

```php
$attestation_context['templateRef'] = 'template-part' === $surface
	? (string) ( $result_target['templatePartRef'] ?? '' )
	: (string) ( $result_target['templateRef'] ?? '' );
```

Undo consumes the canonical `$identity['target']` resolved before executor undo:

```php
$canonical_target = $identity['target'];

$attestation_context['templateRef'] = 'template-part' === $surface
	? (string) ( $canonical_target['templatePartRef'] ?? '' )
	: (string) ( $canonical_target['templateRef'] ?? '' );
```

Use `$canonical_target` for style IDs/block names as well.

- [ ] **Step 9: Run lifecycle suites GREEN**

```powershell
composer run test:php -- tests/phpunit/ExternalApplyLifecycleTest.php
composer run test:php -- tests/phpunit/ApplyAbilitiesTest.php
composer run test:php -- tests/phpunit/ActivityPermissionsTest.php
```

Expected: all three files pass; adversarial approval persists failed rows, adversarial undo returns errors without transitions, and ordered/outer permission precedence remains unchanged.

---

### Task 4: Close compatibility gaps, update spec status, and verify

**Files:**
- Modify: any legitimate fixtures in the seven primary suites still missing canonical `document`/`target` fields
- Modify: `docs/superpowers/specs/2026-08-02-external-apply-target-authorization-design.md:4`
- Modify: `docs/superpowers/specs/2026-08-02-v0-1-0-release-hardening-program-design.md:39-50`
- Verify: all source, tests, plans, and specs changed by this slice

**Interfaces:**
- Consumes: the completed executor/dispatch contract.
- Produces: a locally verified, uncommitted security slice and accurate blocker status.

- [ ] **Step 1: Run all seven primary suites separately and repair only intended compatibility fixtures**

```powershell
composer run test:php -- tests/phpunit/ExternalApplyLifecycleTest.php
composer run test:php -- tests/phpunit/ApplyAbilitiesTest.php
composer run test:php -- tests/phpunit/ActivityPermissionsTest.php
composer run test:php -- tests/phpunit/StyleApplyExecutorTest.php
composer run test:php -- tests/phpunit/TemplateApplyExecutorTest.php
composer run test:php -- tests/phpunit/TemplatePartApplyExecutorTest.php
composer run test:php -- tests/phpunit/PostBlocksApplyExecutorTest.php
```

Expected: all pass. Keep explicit legacy tests incomplete on purpose: ID-only template-part rows with complete document identity pass; ref-only, missing-document, conflicting-alias, and wrong-actual-post-type rows fail closed.

- [ ] **Step 2: Update the two approved specs with bounded local status**

After every targeted suite passes, change the security design status to `Implemented and locally verified; integration and final-SHA gates remain open`. Under Slice 1 in the program design, add a short dated implementation note listing the targeted local evidence and explicitly retaining these open states: uncommitted, unpushed, no PR, unmerged, undeployed, no browser evidence, and no final-SHA verifier evidence. Do not change the overall program status or mark Slice 2, 3, or 4 started.

- [ ] **Step 3: Run documentation and whitespace gates**

```powershell
npm run check:docs
git diff --check
```

Expected: both exit 0.

- [ ] **Step 4: Run the repository verification loop without browser suites**

```powershell
npm run verify -- --skip-e2e
Get-Content -Raw output/verify/summary.json
```

Expected: build, JS lint, unit tests, and PHP tests pass. If Plugin Check prerequisites are absent, record aggregate status `incomplete`; do not relabel it as pass.

- [ ] **Step 5: Run the full PHP suite and inspect the immutable local diff**

```powershell
composer run test:php
git status --short
git diff --stat
git diff -- docs/superpowers/specs docs/superpowers/plans inc/Apply inc/Abilities tests/phpunit
```

Expected: the full PHP suite passes; status contains only the two pre-existing modified specs plus this plan and files needed for the security slice. No generated `build/`, `dist/`, remote, or release changes are introduced.

- [ ] **Step 6: Stop at the authorized boundary**

Report the local worktree state, exact commands/results, any `incomplete` verifier prerequisites, and remaining external/merge/release gates. Do not create a commit or perform any remote action.

---

## Spec Coverage Review

- Canonical resolver shared by authorization/baseline/execute/undo: Tasks 1-2.
- Exact document identity and post metadata binding: Tasks 1-2.
- Template-part alias compatibility/fail-closed matrix: Tasks 1-2 and Task 4.
- Identity-before-capability ordering and stable error codes: Tasks 1-2.
- Approval failed-row semantics and pre-content ordering: Task 3.
- Undo lifecycle/ordered precedence and no-transition denial: Task 3.
- Outer REST/Ability permission boundary: Task 3.
- Canonical apply/revert attestation target: Task 3.
- No production test bypasses and explicit content/capability counters: Task 1.
- Focused and aggregate verification with `incomplete` handled honestly: Task 4.
- Local-only authority boundary: Global Constraints and Task 4 Step 6.
