# Block Recommendations and Inspection Review Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve every confirmed finding from the 2026-08-26 block recommendations and Inspector review: prevent unsafe structural rollback and cached pattern-ID reuse, close the post-revalidation apply race, constrain untrusted colors at every CSS sink reachable from model output, align structural actions with Core's action-specific editor permissions, internationalize every Flavor Agent-owned string rendered by the block panel, and remove the PHPUnit cross-test state leak that made the recommendation execution baseline order-dependent.

**Architecture:** Treat the recommendation response, the live Gutenberg editor, and the rendered Inspector as three separate trust boundaries. PHP canonicalizes model-authored display metadata before it reaches the browser. Block apply thunks bind server revalidation to an immediately-before/immediately-after live editor-context signature. Structural operations recursively clone parsed pattern blocks before capturing identity, preflight WordPress Core's action-specific selectors, identify inserted blocks by fresh client-ID presence diffs, and roll back or undo only those exact IDs. Persisted structural activity remains audit history after reload, while exact-ID undo is deliberately limited to the editor runtime that created those IDs.

**Tech Stack:** PHP 8.2+, WordPress 7.0+/Gutenberg data selectors, JavaScript/React, `@wordpress/data`, `@wordpress/i18n`, Jest through `@wordpress/scripts`, PHPUnit 9, Playwright, Composer, npm verification runner.

**Spec:** The 2026-08-26 block recommendations and inspection review, [Block Recommendations](../../features/block-recommendations.md), [Abilities and Routes](../../reference/abilities-and-routes.md), and [Cross-Surface Validation Gates](../../reference/cross-surface-validation-gates.md).

## Global Constraints

- Implement against the current source behavior anchored at `10c3a857af35635fd1cb732605ff214d3c3ee2a7`. If the checkout advances, re-resolve every named function before editing rather than trusting the recorded line numbers.
- Preserve the separate, pre-existing untracked audit at `docs/flavor-agent-wordpress-context-audit.md`.
- Follow strict TDD for every production behavior change: add a discriminating test, run it and observe the intended failure, implement the smallest complete behavior, then rerun the focused suite.
- Edit source in `inc/`, `src/`, `tests/`, `docs/`, and `languages/`. Do not hand-edit `build/` or `dist/`.
- Do not broaden this remediation into document dirty/saveable/save-lock/save-success orchestration, global block-type enumeration, Navigation Recommendations copy cleanup, or a new editor policy layer. Those concerns were not defects in this review slice.
- Preserve model-authored labels, descriptions, explanations, prompts, and identifiers verbatim. Only Flavor Agent-owned product copy belongs in WordPress i18n calls.
- Accept preview colors only as trimmed `#RGB`, `#RGBA`, `#RRGGBB`, or `#RRGGBBAA`, normalized to lowercase. Reject every other CSS token as `null` on the server and client.
- Any CSS declaration that can resolve a URL — the `background` shorthand above all — is a network sink. Every such sink fed by model-derived text is in scope for F1, not only the Inspector chip. A guard for one of these sinks must be fully anchored; a prefix-only pattern is not a guard.
- Rebuild JS before any browser assertion. `build/` is gitignored, and neither `playwright.config.js` nor `playwright.wp70.config.js` compiles `src/` (the `--build` in `scripts/wp70-e2e.js:302` builds the Docker image, not the webpack bundles). A Playwright run invoked without a preceding `npm run build` is testing stale bundles and its result is void.
- Keep the response schema provider-compatible. Describe the preview format in schema metadata, but do not add a JSON Schema `pattern` requirement that a strict provider subset may reject; the PHP parser remains the authoritative server trust boundary.
- Treat WordPress Core selectors as authoritative for live insertion and removal permission. Do not reimplement ancestor, template-lock, section-block, or inserter policy traversal.
- Do not collapse `lock.move`, `lock.remove`, or a selected container's `templateLock` into a blanket target-lock boolean. Sibling insertion follows the destination root's `canInsertBlockType`; replacement additionally requires `canRemoveBlock(targetClientId) === true`.
- Fail closed if `canInsertBlockType`, rollback/undo `canRemoveBlocks`, or replacement `canRemoveBlock` is missing or does not return `true`.
- Clone every parsed top-level pattern block with `cloneBlock()` before capturing requested IDs or snapshots. Core's recursive clone supplies fresh top-level and inner `clientId` values on every apply, including repeated application of the same cached `pattern.blocks` array.
- Never roll back or undo structural operations by deleting a positional slice. Only client IDs proven absent before dispatch and present after dispatch may be removed.
- Structural undo is available only while the exact recorded runtime IDs still resolve in the current editor session. After reload, persisted activity remains visible for audit but must project `canUndo: false`; do not add path/snapshot reconciliation or guess replacement IDs in this remediation.
- Do not mutate the editor, rebaseline the result, persist activity, enqueue a success toast, or report success after an in-flight live-context mismatch.
- A known-red or unavailable browser harness is a recorded blocker or explicit waiver under `docs/reference/cross-surface-validation-gates.md`; it is never silently treated as passing.
- No commit, push, pull request, merge, tag, package, deployment, or publication is authorized by this planning task. The per-task commit commands below are instructions for a later authorized implementation session.

---

## Finding Coverage and Acceptance Matrix

| ID | Severity | Confirmed finding | Remediation task | Acceptance evidence |
| --- | --- | --- | --- | --- |
| F1 | P1 | Model-controlled `preview` reaches a CSS custom property and can cause a browser URL fetch. | Task 2 | PHP rejects CSS/URL payloads, JS rejects bypassed payloads in passive/selectable/interactive modes, and a browser probe records no request. |
| F1b | P1 | The same `background` URL sink exists on the AI Activity admin page, behind a prefix-only guard that admits a trailing `url(...)`. | Task 2 | `isLikelyCssColor()` is fully anchored, the admin swatch renders no value for a URL-bearing payload, and the reviewed corpus passes for both sinks. |
| F2 | P1 | Failed or partial structural insertion can roll back a requested-length slice containing pre-existing neighbors. Structural undo repeats the same assumption. | Task 4 | Zero insert removes nothing; partial insert removes only newly present requested IDs; replacement restores its target; undo requires exact runtime IDs. |
| F2b | P1 | Cached `pattern.blocks` are returned by reference and JSON cloning preserves their client IDs, so a repeated apply collides with the first insertion. | Task 4 | Every parsed top-level block is passed through recursive `cloneBlock()` before identity capture; applying the same nested pattern twice produces distinct top-level and inner IDs. |
| F3 | P1 | Single, batch, and structural block apply can mutate after the live block changes while `resolveSignatureOnly` is in flight. | Task 5 | Deferred-response tests for all three thunks preserve the user edit and produce client-stale state with no mutation/activity/toast/rebaseline. |
| F4 | P2 | Collector/catalog/apply logic collapses movement, removal, and selected-container template locks into a blanket target denial even though Core permissions are action-specific. | Tasks 3 and 4 | Move-only locks do not block sibling insertion or replacement, remove-only locks block replacement but not sibling insertion, selected-container `templateLock` does not govern the outer operation, and missing/false Core selectors cause zero mutation. |
| F5 | P2 | Flavor Agent-owned English remains untranslated in the block panel, request diagnostics, apply errors, structural failure copy, and actionability tier labels. | Task 6 | The complete rendered inventory across the panel, store, structural actions, and tier helper uses `__()`/`sprintf()`; dynamic values remain placeholders; the POT catalog contains the new messages. |
| F6 | P2 | `Provider` holds runtime chat configuration/metrics/diagnostics in one-shot private statics that nothing resets between PHPUnit tests, so one test's diagnostics leak into the next test's `active_chat_request_meta()`. | Task 1 | A probe that records diagnostics without consuming them no longer perturbs `RecommendationAbilityExecutionTest`, and the full suite stays green. |

## Baseline Evidence to Preserve

Measured at `10c3a857af35635fd1cb732605ff214d3c3ee2a7` with a clean tree. An earlier draft of this section reported numbers that did not reproduce; these replace them.

- The full PHP suite passes: **2,244 tests / 10,526 assertions, zero failures**.
- `RecommendationAbilityExecutionTest` passes in isolation (**32 tests / 146 assertions**) and inside the full suite. It also passes under every filtered slice tried: `Recommendation` (152), `Ability` (104), `Abilities` (343), `Recommend` (289), `Execution` (45), `RecommendationAbility` (47), and `WordPressAIClientTest|RecommendationAbilityExecutionTest` (83).
- The nine JS suites this remediation touches pass: **9 suites / 276 tests**.
- `git diff --check` passed before this plan was written.

**On the originally reported F6 failure.** The earlier draft recorded one failure at `RecommendationAbilityExecutionTest.php:56-59`, where the returned transport carried `host: wordpress-ai-client`, `path: /generate-text`, `timeoutSeconds: 90` in addition to the callback's `provider: test`. That failure is real but **order-dependent**, and the assertion is not at fault. Those three values are emitted by `WordPressAIClient` (`inc/LLM/WordPressAIClient.php:1882`, with `DEFAULT_REQUEST_TIMEOUT = 90` at `:26`) into `Provider`'s private statics. `Provider::active_chat_diagnostics()` (`inc/OpenAI/Provider.php:442`) is one-shot: it returns the recorded value and clears the fresh flag only when something reads it. A test that records diagnostics without consuming them leaves them armed for whichever test runs next.

This was reproduced deterministically by adding a probe test that sorts before `RecommendationAbilityExecutionTest` and records exactly those diagnostics without reading them back; the target test then fails at `:56` with precisely the reported diff. Task 1 fixes the leak. Do not weaken the assertion — relaxing it to accept `host`/`path`/`timeoutSeconds` was verified to make the class **fail** in isolation and in the full suite (`Failed asserting that null is identical to 'wordpress-ai-client'`), because in a clean process the callback supplies only `provider`.

These are starting observations, not final release evidence. Every affected gate must be rerun after implementation.

---

## File Structure

| File | Responsibility in this remediation |
| --- | --- |
| `tests/phpunit/bootstrap.php` | Clear `Provider`'s one-shot runtime chat statics in `WordPressTestState::reset()` so runtime diagnostics cannot leak between tests. |
| `tests/phpunit/RecommendationAbilityExecutionTest.php` | Unchanged production assertions; add a same-class regression that proves armed diagnostics no longer bleed into the callback transport map. |
| `inc/LLM/Prompt.php` | State the exact preview-color contract and canonicalize/reject preview metadata while normalizing all recommendation lanes. |
| `inc/LLM/ResponseSchema.php` | Describe the constrained preview format without relying on unsupported schema regex validation. |
| `tests/phpunit/PromptRulesTest.php` | Cover accepted preview forms, hostile/invalid values, all three lanes, and ranking behavior. |
| `tests/phpunit/ResponseSchemaTest.php` | Pin the provider-compatible preview schema description and absence of a `pattern` keyword. |
| `src/utils/suggestion-preview-color.js` | New pure client-side defense that canonicalizes only the four allowed hex forms. |
| `src/utils/__tests__/suggestion-preview-color.test.js` | Exhaustive valid/invalid client preview corpus. |
| `src/inspector/SuggestionChips.js` | Consume only the normalized preview value for custom properties and swatch visibility in all three render modes. |
| `src/inspector/__tests__/SuggestionChips.test.js` | Prove invalid metadata creates neither a CSS value nor a swatch in passive, selectable, and interactive chips. |
| `src/admin/activity-log-utils.js` | Fully anchor `isLikelyCssColor()` so a URL-bearing payload can no longer reach the admin swatch's `background` shorthand. |
| `src/admin/__tests__/activity-log-utils.test.js` | Run the shared hostile corpus through the admin colour-visual path. |
| `src/context/collector.js` | Build structural pattern context from the destination root and Core's action-specific insertion/removal selectors instead of selected-block lock attributes. |
| `src/context/__tests__/collector.test.js` | Cover move-only, remove-only, selected-container `templateLock`, and missing-selector collection behavior. |
| `src/utils/block-allowed-pattern-context.js` | Advertise sibling insertion independently from replacement and make replacement conditional on recommendation-time `canRemoveBlock`. |
| `src/utils/__tests__/block-allowed-pattern-context.test.js` | Pin the action-specific allowed-action matrix without a blanket target-lock gate. |
| `src/utils/block-operation-catalog.js` | Keep the browser validator grammar-focused and remove the blanket `isTargetLocked` rejection; live authorization belongs to Core selectors. |
| `src/utils/__tests__/block-operation-catalog.test.js` | Prove allowed actions, rather than a target-lock boolean, govern catalog validation. |
| `inc/Context/BlockOperationValidator.php` | Keep server validation in parity with the browser catalog by removing blanket selected-target lock authorization. |
| `inc/Abilities/BlockAbilities.php` | Stop normalizing the legacy blanket target-lock flag as structural authorization. |
| `tests/phpunit/BlockOperationValidatorTest.php` and `BlockOperationContextTest.php` | Pin action-specific server validation and normalized context parity. |
| `src/utils/block-structural-actions.js` | Recursively clone parsed pattern blocks, preflight Core permissions, apply by exact fresh IDs, roll back only proven insertions, and undo only current-session runtime IDs. |
| `src/utils/__tests__/block-structural-actions.test.js` | Apply one nested cached pattern twice, simulate zero/partial Core insertion, cover action-specific native permissions, replacement restoration, exact-ID undo, and reload-time undo denial. |
| `src/store/__tests__/activity-history.test.js` and `activity-undo.test.js` | Preserve activity projection/routing while making exact-ID/native-permission undo availability fail closed. |
| `src/store/index.js` | Bind all three block apply thunks to stable live context across awaited server revalidation and translate rendered block request/apply diagnostics. |
| `src/store/__tests__/store-actions.test.js` | Use deferred ability responses to prove the in-flight race closes for single, batch, and structural apply, and pin translated source-locale diagnostics/errors. |
| `src/inspector/BlockRecommendationsPanel.js` | Internationalize the complete rendered block-panel product-copy inventory. |
| `src/inspector/__tests__/BlockRecommendationsPanel.test.js` | Preserve rendered behavior, formatted dynamic copy, and the translated default-eyebrow sentinel. |
| `src/utils/recommendation-actionability.js` | Internationalize validator-owned tier labels. |
| `src/utils/__tests__/recommendation-actionability.test.js` | Pin the tier-label API after translation wrapping. |
| `languages/flavor-agent.pot` | Regenerated translation catalog containing the new panel, request-diagnostic, apply-error, structural-failure, and actionability messages. |
| `tests/e2e/flavor-agent.smoke.spec.js` | Add browser proof for the preview network defense and in-flight stale guard; prove selected-block movement/removal locks are interpreted per structural action. |
| `docs/features/block-recommendations.md` | Document the live-context race gate, recursive pattern cloning, action-specific Core permission preflight, exact-ID rollback, and current-session undo boundary. |
| `docs/features/activity-and-audit.md` | Distinguish persisted structural audit history from current-editor-session exact-ID undo availability. |
| `docs/reference/abilities-and-routes.md` | Document the normalized preview-color response contract. |

---

### Task 1: Remove the PHPUnit runtime-diagnostics state leak

**Finding:** F6

`Provider` keeps three pairs of private statics — `$last_runtime_chat_configuration` / `$has_fresh_runtime_chat_configuration` (`inc/OpenAI/Provider.php:28-30`), the matching metrics pair (`:35-37`), and the diagnostics pair (`:42-44`). Each is written by a `record_runtime_chat_*()` setter and drained by a one-shot reader that clears the fresh flag only on read. Nothing resets them between tests, so a test that records without reading arms the next test's `active_chat_request_meta()`.

Fix the leak centrally, not at the one assertion that happened to catch it. `WordPressTestState::reset()` (`tests/phpunit/bootstrap.php:455`) is already the shared per-test reset and already runs after `vendor/autoload.php` is required, so `Provider` resolves there.

**Files:**
- Modify: `tests/phpunit/bootstrap.php:455-457`
- Modify: `tests/phpunit/RecommendationAbilityExecutionTest.php`

**Interfaces:**
- Consumes: the existing public `Provider::record_runtime_chat_configuration()`, `record_runtime_chat_metrics()`, and `record_runtime_chat_diagnostics()` setters.
- Produces: a deterministic empty runtime-diagnostics baseline at the start of every test, with no production-source change.

- [ ] **Step 1: Reproduce the leak deterministically**

The failure does not reproduce from a filter alone — the class is green in isolation and in the full suite. Add a temporary probe that sorts before the target class and arms the statics without draining them:

```php
<?php
// tests/phpunit/AaaLeakProbeTest.php — TEMPORARY, deleted in Step 4.
declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AaaLeakProbeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
	}

	public function test_records_diagnostics_without_consuming_them(): void {
		Provider::record_runtime_chat_diagnostics(
			[
				'transport' => [
					'host'           => 'wordpress-ai-client',
					'path'           => '/generate-text',
					'timeoutSeconds' => 90,
				],
			]
		);

		$this->assertTrue( true );
	}
}
```

Run:

```bash
composer run test:php -- --filter 'AaaLeakProbeTest|RecommendationAbilityExecutionTest'
```

Expected: exactly one failure, at `RecommendationAbilityExecutionTest.php:56`, with `host`, `path`, and `timeoutSeconds` added to the expected `[ 'provider' => 'test' ]`. This is the originally reported failure, now on demand. The probe must sort before the target class; a name that sorts after it will not reproduce anything.

- [ ] **Step 2: Drain all three statics in the shared reset**

At the top of `WordPressTestState::reset()`, before the existing field assignments:

```php
			// Provider caches runtime chat configuration/metrics/diagnostics in
			// one-shot private statics that are cleared only when a consumer
			// reads them. Recording null drains all three so diagnostics
			// recorded by one test cannot leak into the next test's
			// active_chat_request_meta() call.
			\FlavorAgent\OpenAI\Provider::record_runtime_chat_configuration(null);
			\FlavorAgent\OpenAI\Provider::record_runtime_chat_metrics(null);
			\FlavorAgent\OpenAI\Provider::record_runtime_chat_diagnostics(null);
```

Each setter normalizes `null` to a stored `null` while marking the value fresh; every reader then returns its empty default and clears the flag. The observable result is identical to a pristine process, so no test that records its own runtime state in a test body is affected — `reset()` runs in `setUp()`, before the body.

Do not add a test-only reset method to `Provider`, and do not change any file under `inc/`. The leak is a test-harness defect and the existing public setters are sufficient to fix it.

- [ ] **Step 3: Confirm the leak is closed and nothing regressed**

```bash
composer run test:php -- --filter 'AaaLeakProbeTest|RecommendationAbilityExecutionTest'
composer run test:php
```

Expected: the probe pairing now passes, and the full suite reports **2,245 tests / 10,527 assertions** with zero failures while the one-test/one-assertion probe still exists. The original 2,244 / 10,526 baseline applies only before adding that temporary file.

- [ ] **Step 4: Replace the probe with a permanent same-class regression**

Delete `tests/phpunit/AaaLeakProbeTest.php`. A throwaway alphabetically-ordered file is not a durable guard. Add to `RecommendationAbilityExecutionTest` a test that arms the statics and then asserts the transport map within a single test, so the guarantee no longer depends on file ordering:

```php
	public function test_execute_does_not_inherit_unconsumed_runtime_diagnostics(): void {
		Provider::record_runtime_chat_diagnostics(
			[
				'transport' => [
					'host'           => 'wordpress-ai-client',
					'path'           => '/generate-text',
					'timeoutSeconds' => 90,
				],
			]
		);

		WordPressTestState::reset();

		$result = RecommendationAbilityExecution::execute(
			'template',
			'flavor-agent/recommend-template',
			[ 'templateRef' => 'theme//home' ],
			static fn(): array => [
				'suggestions' => [ [ 'label' => 'Clarify header hierarchy' ] ],
				'requestMeta' => [ 'transport' => [ 'provider' => 'test' ] ],
			]
		);

		$this->assertSame(
			[ 'provider' => 'test' ],
			$result['requestMeta']['transport'] ?? null
		);
	}
```

Import `FlavorAgent\OpenAI\Provider` in the test's use block. Leave the existing exact-map assertion at `:56-59` untouched — it is correct, and it is what surfaced the leak.

- [ ] **Step 5: Run the focused class and lint**

```bash
composer run test:php -- --filter 'RecommendationAbilityExecutionTest'
composer run lint:php
```

Expected: the class passes with 33 tests / 147 assertions, and PHPCS is clean. The full suite then stands at 2,245 tests / 10,527 assertions — the baseline plus this one regression.

Both halves of this task were validated against the anchor commit before the plan was written: the regression test fails with the reported three-key diff at its own assertion line without the `reset()` change, and passes with it, with no other test affected.

- [ ] **Step 6: Commit the isolated test-harness repair in the implementation session**

```bash
git add tests/phpunit/bootstrap.php tests/phpunit/RecommendationAbilityExecutionTest.php
git commit -m "Isolate provider runtime diagnostics between tests"
```

---

### Task 2: Constrain model-derived colors at every CSS network sink

**Findings:** F1 and F1b

Two sinks, one bug class. Both are the CSS `background` shorthand, which resolves `url(...)`:

1. `src/editor.css:1752` — `background: var(--flavor-agent-chip-preview, transparent)`, fed by three unguarded `s.preview` assignments in `SuggestionChips.js` (`:207`, `:245`, `:309`), fed in turn by `sanitize_optional_text_value()` on the server, which is `sanitize_text_field()` and passes `url(...)` through untouched.
2. `src/admin/activity-log.js:1668` — `style={ { background: visual.cssValue } }`, reached from `getColorVisualMetadata()` when `isLikelyCssColor()` (`src/admin/activity-log-utils.js:2142`) returns true. Two of that guard's three branches anchor only the prefix: `/^(?:rgb|hsl)a?\(/i` and `/^color-mix\(/i` both accept `rgb(0,0,0) url(https://preview-probe.invalid/pixel)`. The hex branch is correctly anchored at both ends.

Treat F1b as in scope even though the reachability of a hostile style value through apply-time validation is unproven — a prefix-only guard in front of a URL sink is a defect on its own terms, and the fix is contained to one function.

**Files:**
- Create: `src/utils/suggestion-preview-color.js`
- Create: `src/utils/__tests__/suggestion-preview-color.test.js`
- Modify: `inc/LLM/Prompt.php:119-155`, `:2928-2954`
- Modify: `inc/LLM/ResponseSchema.php:334-345`
- Modify: `tests/phpunit/PromptRulesTest.php`
- Modify: `tests/phpunit/ResponseSchemaTest.php`
- Modify: `src/inspector/SuggestionChips.js:195-332`
- Modify: `src/inspector/__tests__/SuggestionChips.test.js`
- Modify: `src/admin/activity-log-utils.js:2142-2152`
- Modify: `src/admin/__tests__/activity-log-utils.test.js`

**Interfaces:**
- Consumes: untrusted model `preview` strings from `settings`, `styles`, and `block` suggestions, plus model-derived style operation values rendered as admin swatches.
- Produces: `?string`/`string|null` normalized preview values that are either lowercase allowed hex colors or absent, and an admin colour guard that matches whole values only.

- [ ] **Step 1: Add server RED tests for the exact accepted and rejected corpus**

In `PromptRulesTest.php`, add a test that feeds one valid preview through each lane and expects canonical lowercase output:

```php
$expected = [
	'settings' => '#abc',
	'styles'   => '#abcd',
	'block'    => '#aabbccdd',
];
```

Use inputs with surrounding whitespace and uppercase digits/letters so the test distinguishes trimming and canonicalization. Add a data provider that expects `null` for each of:

```text
url(https://preview-probe.invalid/pixel)
linear-gradient(#fff, #000)
red
var(--wp--preset--color--contrast)
#12
#12345
#123456789
#ggg
#fff; background-image: url(https://preview-probe.invalid/pixel)
```

Run every rejected value through `settings`, `styles`, and `block`; no lane may retain a different CSS grammar.

Add a ranking regression with two otherwise identical suggestions: one has an invalid preview and one has no preview. Assert the invalid value normalizes to `null` and receives no `has_preview` score advantage.

- [ ] **Step 2: Add response-schema RED assertions**

In `ResponseSchemaTest.php`, locate the shared `preview` property for every suggestion lane and assert:

```php
$this->assertSame( 'string', $preview_schema['type'] ?? null );
$this->assertStringContainsString( '#RGB', $preview_schema['description'] ?? '' );
$this->assertArrayNotHasKey( 'pattern', $preview_schema );
```

Expected before implementation: `description` is absent.

- [ ] **Step 3: Add client RED tests before creating the normalizer**

Create `suggestion-preview-color.test.js` with a table test for:

```js
const valid = new Map( [
	[ '#ABC', '#abc' ],
	[ ' #AbC8 ', '#abc8' ],
	[ '#AABBCC', '#aabbcc' ],
	[ '#AABBCCDD', '#aabbccdd' ],
] );

const invalid = [
	null,
	undefined,
	'',
	'url(https://preview-probe.invalid/pixel)',
	'linear-gradient(#fff, #000)',
	'red',
	'var(--token)',
	'#12',
	'#12345',
	'#123456789',
	'#ggg',
	'#fff; background: url(https://preview-probe.invalid/pixel)',
];
```

Valid entries must return the mapped lowercase string; every invalid entry must return `null`.

In `SuggestionChips.test.js`, add one case for each render mode:

1. passive (`interactive={ false }`)
2. selectable (`selectable`)
3. interactive button (default)

For `preview: 'url(https://preview-probe.invalid/pixel)'`, assert the rendered row/button has no `--flavor-agent-chip-preview` value and contains no `.flavor-agent-chip__preview`. Add a positive control that `preview: ' #ABC '` yields `#abc` and a swatch where that mode normally shows one.

In `src/admin/__tests__/activity-log-utils.test.js`, add cases driving the colour-visual path with values that satisfy the current prefix-only guard while carrying a URL:

```text
rgb(0, 0, 0) url(https://preview-probe.invalid/pixel)
hsl(0 0% 0%) no-repeat url(https://preview-probe.invalid/pixel)
color-mix(in srgb, red, blue) url(https://preview-probe.invalid/pixel)
```

Each must yield a non-swatch result — no `cssValue` — while the existing well-formed `rgb(...)`, `hsla(...)`, `color-mix(...)`, and `#rrggbb` fixtures keep returning `type: 'swatch'` with their value intact. The existing tests at `:2193-2263` are the positive controls; do not weaken them.

- [ ] **Step 4: Run the preview tests and capture RED**

```bash
composer run test:php -- --filter 'PromptRulesTest|ResponseSchemaTest'
npm run test:unit -- --runInBand src/utils/__tests__/suggestion-preview-color.test.js src/inspector/__tests__/SuggestionChips.test.js src/admin/__tests__/activity-log-utils.test.js
```

Expected: the PHP parser retains hostile text, the schema lacks a description, the JS normalizer module/behavior does not exist, and the admin guard admits all three URL-bearing values.

- [ ] **Step 5: Implement the authoritative PHP normalizer**

Add a private helper beside `sanitize_optional_text_value()`:

```php
	private static function sanitize_preview_color( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$color = trim( $value );

		if ( 1 !== preg_match( '/\A\#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\z/D', $color ) ) {
			return null;
		}

		return strtolower( $color );
	}
```

Change `validate_suggestions()` so only `preview` uses this helper. Do not reuse general text sanitization for a CSS sink.

Update both prompt examples to say:

```text
Hex color in #RGB, #RGBA, #RRGGBB, or #RRGGBBAA form for the visual preview swatch, or empty string
```

Add the same statement as the `description` on `ResponseSchema::block_display_metadata_schema()['preview']`; retain `type: string` and do not add `pattern`.

- [ ] **Step 6: Implement the client defense and use it in all chip modes**

Create:

```js
const HEX_PREVIEW_COLOR = /^#(?:[\da-f]{3}|[\da-f]{4}|[\da-f]{6}|[\da-f]{8})$/i;

export function normalizeSuggestionPreviewColor( value ) {
	if ( typeof value !== 'string' ) {
		return null;
	}

	const color = value.trim();

	return HEX_PREVIEW_COLOR.test( color ) ? color.toLowerCase() : null;
}
```

Inside the existing `suggestions.map()`, compute `const previewColor = normalizeSuggestionPreviewColor( s.preview );` once. Replace every `s.preview` style assignment and swatch predicate with `previewColor`, retaining the existing stale/applied visibility rules. An invalid preview must produce neither a custom-property assignment nor a decorative swatch.

- [ ] **Step 7: Fully anchor the admin colour guard**

In `src/admin/activity-log-utils.js`, close both open-ended branches of `isLikelyCssColor()`:

```js
	return (
		/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test( value ) ||
		/^(?:rgb|hsl)a?\([^;{}()]*\)$/i.test( value ) ||
		/^color-mix\((?:[^;{}()]|(?:rgba?|hsla?)\([^;{}()]*\))*\)$/i.test(
			value
		)
	);
```

A terminal `\)$` alone is not sufficient — with a permissive character class it matches straight across `) url(…)` to the final paren, so `rgb(0,0,0) url(https://…/pixel)` still passes. Excluding parentheses from the class is what forces the match to end at the function's own closing paren. `color-mix` needs one nesting level, so its branch admits an inner `rgb()`/`hsl()` call by name only; that is what rejects `color-mix(in srgb, url(https://…/pixel), blue)`.

This exact triple was verified against a 12-value accept corpus (`#fff`, `#17232a`, `#ff0000ff`, `rgb(0,0,0)`, `rgba(0, 0, 0, 0.5)`, `hsl(0 0% 0%)`, `hsla(0,0%,0%,.5)`, `color-mix(in srgb, red, blue)`, `color-mix(in srgb, rgb(0 0 0), blue)`, `color-mix(in oklab, hsl(0 0% 0%) 40%, #fff)`, and nested `rgba`/`hsl` mixes) and a 12-value reject corpus (the three URL-bearing values above, bare `url(...)`, `red`, `var(--t)`, declaration-escape and rule-escape payloads, `rgb(url(...))`, `color-mix(in srgb, image-set("a.png"), blue)`, and a trailing-comma URL). Reuse both corpora as the test table. The alternation was also probed for catastrophic backtracking on repeated `rgb` prefixes with no closing paren; match time stayed flat.

Leave the hex branch alone; it is already anchored at both ends. Note it intentionally does not accept `#RGBA`, which is a separate display concern from the recommendation `preview` contract. The existing admin fixtures are hex-only, so no current test changes behavior.

Do not reuse `normalizeSuggestionPreviewColor()` here. The admin swatch legitimately renders `rgb()`, `hsl()`, and `color-mix()` values that the recommendation preview contract deliberately excludes; sharing one helper would silently narrow the admin surface.

- [ ] **Step 8: Run the focused PHP/JS suites and lint the touched source**

```bash
composer run test:php -- --filter 'PromptRulesTest|ResponseSchemaTest'
npm run test:unit -- --runInBand src/utils/__tests__/suggestion-preview-color.test.js src/inspector/__tests__/SuggestionChips.test.js src/admin/__tests__/activity-log-utils.test.js
npm run lint:js
composer run lint:php
```

Expected: all valid forms canonicalize, every hostile form is absent, all three chip render modes use the defense, the admin guard rejects URL-bearing values while preserving its existing swatch fixtures, and lint passes.

- [ ] **Step 9: Commit the colour boundary hardening in the implementation session**

```bash
git add inc/LLM/Prompt.php inc/LLM/ResponseSchema.php tests/phpunit/PromptRulesTest.php tests/phpunit/ResponseSchemaTest.php src/utils/suggestion-preview-color.js src/utils/__tests__/suggestion-preview-color.test.js src/inspector/SuggestionChips.js src/inspector/__tests__/SuggestionChips.test.js src/admin/activity-log-utils.js src/admin/__tests__/activity-log-utils.test.js
git commit -m "Constrain model-derived colors at CSS sinks"
```

---

### Task 3: Replace blanket lock gating with action-specific Core permissions

**Finding:** F4, recommendation/catalog half

`lock.move`, `lock.remove`, and `templateLock` describe different operations. A selected container's `templateLock` governs its children, not insertion beside or removal of the container itself. Do not create `block-structural-lock.js` or another boolean policy helper. Recommendation-time action availability comes from Core selectors at the destination root, the JS/PHP catalogs validate the resulting `allowedActions`, and Task 4 repeats the selectors immediately before mutation to close permission drift.

**Files:**
- Modify: `src/context/collector.js:123-149`, `:693-713`
- Modify: `src/context/__tests__/collector.test.js`
- Modify: `src/utils/block-allowed-pattern-context.js:63-108`, `:111-156`
- Modify: `src/utils/__tests__/block-allowed-pattern-context.test.js`
- Modify: `src/utils/block-operation-catalog.js:233-261`, `:299-409`
- Modify: `src/utils/__tests__/block-operation-catalog.test.js`
- Modify: `src/utils/block-structural-actions.js:232-240`, `:299-341`
- Modify: `src/utils/__tests__/block-structural-actions.test.js`
- Modify: `inc/Context/BlockOperationValidator.php:42-54`, `:127-267`
- Modify: `tests/phpunit/BlockOperationValidatorTest.php`
- Modify: `inc/Abilities/BlockAbilities.php:820-884`
- Modify: `tests/phpunit/BlockOperationContextTest.php`

**Interfaces:**
- Consumes: destination `rootClientId`, resolved top-level pattern block names, `canInsertBlockType(name, rootClientId)`, `canRemoveBlock(targetClientId)`, editing-mode/content-only state, and each pattern's existing `allowedActions` grammar.
- Produces: `buildAllowedPatternContext(patterns, target, permissions)` where `permissions.canInsertPattern(pattern) === true` enables sibling insertion and `permissions.canRemoveTarget === true` additionally enables replacement; neither catalog treats `isTargetLocked` as authorization.

- [ ] **Step 1: Add action-specific allowed-pattern and collector RED tests**

Extend `block-allowed-pattern-context.test.js` around one pattern compatible with the selected block:

1. `canInsertPattern: () => true`, `canRemoveTarget: true` produces `insert_before`, `insert_after`, and `replace`.
2. `canInsertPattern: () => true`, `canRemoveTarget: false` produces both sibling insertion actions but not `replace`.
3. `canInsertPattern: () => false` produces no allowed pattern.
4. Missing insertion permission fails closed with no allowed pattern; missing removal permission still permits insertion but omits replacement.
5. `editingMode: 'contentOnly'` continues to produce no structural actions.

In `collector.test.js`, make the block-editor double expose `canInsertBlockType` and `canRemoveBlock`, then add these cases:

| Selected block attributes | Selector results | Expected pattern actions |
| --- | --- | --- |
| `lock: { move: true, remove: false }` | insert `true`, remove `true` | insert before/after and replace |
| `lock: { move: false, remove: true }` | insert `true`, remove `false` | insert before/after only |
| `templateLock: 'all'` on the selected container | insert `true`, remove `true` | insert before/after and replace |

The selected container case must call `canInsertBlockType` with its **parent/destination** root, not the selected container's own client ID. Also cover a missing `canInsertBlockType` selector (no patterns) and a missing `canRemoveBlock` selector (insert actions only).

- [ ] **Step 2: Add JS/PHP catalog parity RED tests**

In `block-operation-catalog.test.js`, replace the generic “locked targets” rejection with proof that a legacy `isTargetLocked: true` field does not blanket-reject an otherwise allowed insert. Add/retain the operation-specific counterexample: replacement is rejected with `action_not_allowed` when the selected pattern's `allowedActions` contains only insertion actions. Content-only validation remains unchanged.

Mirror that contract in `BlockOperationValidatorTest.php`: remove the blanket `locked_target` invalid dataset, replace it with a test that legacy `isTargetLocked` input cannot override an allowed insertion, and retain the existing replacement/`action_not_allowed` case. Update `BlockOperationContextTest.php` so normalized real editor context no longer promises an `isTargetLocked` authorization field.

In `block-structural-actions.test.js`, replace the current `prepareBlockStructuralOperation()` lock rejection with three cases that pass catalog preparation regardless of selected-block `move`, `remove`, or own `templateLock` attributes. Task 4 will make the actual mutation decision through live selectors.

- [ ] **Step 3: Run the focused suites and capture RED**

```bash
npm run test:unit -- --runInBand src/utils/__tests__/block-allowed-pattern-context.test.js src/context/__tests__/collector.test.js src/utils/__tests__/block-operation-catalog.test.js src/utils/__tests__/block-structural-actions.test.js
composer run test:php -- --filter 'BlockOperationValidatorTest|BlockOperationContextTest'
```

Expected: current collection suppresses every pattern for truthy selected-block locks/template locks, current JS/PHP validators reject the legacy blanket flag, and `prepareBlockStructuralOperation()` still maps any nonempty selected lock object to `locked_target`.

- [ ] **Step 4: Build action-specific recommendation-time permissions**

Change the helper contract to:

```js
buildAllowedPatternContext( patterns, target, {
	canInsertPattern,
	canRemoveTarget,
} )
```

`getAllowedActionsForPattern()` returns no actions unless the existing target/editing-mode checks pass and `canInsertPattern(pattern) === true`. It then adds `insert_before` and `insert_after`; it adds `replace` only when `canRemoveTarget === true` and the existing `blockTypes` compatibility check passes. Delete the `target.isTargetLocked !== true` blanket check and remove `isTargetLocked` from `buildBlockOperationTargetSignature()`.

In `collector.js`, delete `hasStructuralLock()` and `isTargetLocked()`. Reuse `resolvePatternBlocks()` from `src/patterns/pattern-insertability.js` to define the permission callback:

```js
const canInsertPattern = ( pattern ) => {
	if ( typeof blockEditor?.canInsertBlockType !== 'function' ) {
		return false;
	}

	const blocks = resolvePatternBlocks( pattern );

	return (
		blocks.length > 0 &&
		blocks.every(
			( block ) =>
				Boolean( block?.name ) &&
				blockEditor.canInsertBlockType( block.name, rootClientId ) === true
		)
	);
};
```

Pass `canRemoveTarget: blockEditor.canRemoveBlock?.(clientId) === true`. Do not inspect selected-block `lock` or `templateLock` values directly; Core's selectors already apply the correct target/root/ancestor semantics.

- [ ] **Step 5: Remove blanket authorization from both catalogs and live preparation**

In `block-operation-catalog.js`, stop copying `isTargetLocked` into `buildBlockOperationValidationContext()` and delete the `context.isTargetLocked || context.locked` rejection from shared validation. In `BlockOperationValidator.php`, make the same normalization/validation change. In `BlockAbilities::normalize_block_operation_context()`, stop preserving `isTargetLocked` as part of the server contract.

Retain `BLOCK_OPERATION_ERROR_LOCKED_TARGET` / `ERROR_LOCKED_TARGET` and their display mapping for backward-compatible rendering of older persisted/server rejection payloads, but do not emit that code from the current editor block-operation validators.

In `block-structural-actions.js`, delete `hasLockedBlockAttribute()` and stop synthesizing `isTargetLocked` when calling `validateBlockOperationSequence()`. Keep stale-target, rollout, pattern/action, and content-only checks. Task 4 is the sole owner of live insertion/removal permission immediately before dispatch.

- [ ] **Step 6: Rerun the permission-model suites**

```bash
npm run test:unit -- --runInBand src/utils/__tests__/block-allowed-pattern-context.test.js src/context/__tests__/collector.test.js src/utils/__tests__/block-operation-catalog.test.js src/utils/__tests__/block-structural-actions.test.js
composer run test:php -- --filter 'BlockOperationValidatorTest|BlockOperationContextTest'
npm run lint:js
composer run lint:php
```

Expected: recommendation-time actions follow destination insertion and target removal independently; movement-only and selected-container template locks do not suppress valid outer actions; JS/PHP catalogs agree; missing selectors fail closed per action.

- [ ] **Step 7: Commit the action-specific permission contract in the implementation session**

```bash
git add src/context/collector.js src/context/__tests__/collector.test.js src/utils/block-allowed-pattern-context.js src/utils/__tests__/block-allowed-pattern-context.test.js src/utils/block-operation-catalog.js src/utils/__tests__/block-operation-catalog.test.js src/utils/block-structural-actions.js src/utils/__tests__/block-structural-actions.test.js inc/Context/BlockOperationValidator.php tests/phpunit/BlockOperationValidatorTest.php inc/Abilities/BlockAbilities.php tests/phpunit/BlockOperationContextTest.php
git commit -m "Use action-specific block permissions"
```

---

### Task 4: Make structural apply, rollback, and undo fresh-ID safe

**Findings:** F2, F2b, and F4, live-mutation half

**Files:**
- Modify: `src/utils/block-structural-actions.js:1-80`, `:358-620`, `:625-824`
- Modify: `src/utils/__tests__/block-structural-actions.test.js:109-201`, `:291-493`
- Modify: `src/store/__tests__/activity-history.test.js`
- Modify: `src/store/__tests__/activity-undo.test.js`

**Interfaces:**
- Consumes: parsed top-level pattern blocks, recursive `cloneBlock(block)`, live `canInsertBlockType(name, rootClientId)`, live `canRemoveBlock(clientId)` / `canRemoveBlocks(clientIds)`, editor dispatch, and the post-dispatch block tree.
- Produces: a fresh-ID block tree per apply; successful insert operations with `insertedClientIds`; successful replacement operations with `replacementClientIds`; exact snapshots/signatures; verified rollback/restoration; and current-session-only undo payloads.

- [ ] **Step 1: Upgrade the editor test double to model Core's partial insertion behavior**

Extend `createBlockEditor()` with:

```js
nextInsertBlockCount = null,
canInsertBlockType = () => true,
canRemoveBlock = () => true,
canRemoveBlocks = ( clientIds ) =>
	clientIds.every( ( clientId ) => canRemoveBlock( clientId ) ),
```

Expose all three permission functions on `blockEditorSelect`. In the `insertBlocks` mock, capture every attempted top-level and inner client ID before consuming `nextInsertBlockCount`; consume that count for one dispatch only, insert `blocksToInsert.slice( 0, count )`, then reset the option before a replacement rollback tries to restore the original block. Add independent one-shot no-op controls for `removeBlocks` and restoration insertion so post-dispatch verification is testable. Keep `failNextInsert` as a zero-insert convenience or implement it as `nextInsertBlockCount: 0`.

Add a two-block cached parser fixture whose source objects use stable IDs and whose first top-level block contains a nested block with its own stable ID. The fixture must return the **same array instance** on every call. Use initial trees with a named pre-existing neighbor on the rollback side so the tests detect accidental slice deletion. Later assertions refer to IDs captured from each attempted dispatch, never the cached fixture IDs.

- [ ] **Step 2: Add repeated nested-pattern fresh-ID RED tests**

Apply the same cached nested pattern twice beside the same selected block. Capture both successful operation payloads and the live inserted trees. Assert:

- both applies return `ok: true`
- neither apply uses any top-level or inner source-fixture client ID
- every first-apply top-level ID differs from the corresponding second-apply ID
- every first-apply inner ID differs from the corresponding second-apply ID
- `insertedClientIds` exactly matches the fresh top-level IDs dispatched for that apply
- the cached parser fixture remains unchanged

The regression must fail against `cloneBlockTree()`/JSON cloning because it preserves all cached IDs. Do not mock `cloneBlock()` to return predetermined IDs; the test is specifically proving Core's recursive identity behavior.

- [ ] **Step 3: Add zero/partial insertion RED tests for both positions**

For `insert_before` and `insert_after`, cover both `nextInsertBlockCount: 0` and `nextInsertBlockCount: 1`. Assert all of the following:

- result is `ok: false`
- the final ordered client-ID list exactly equals the initial list
- zero insertion never calls `removeBlocks` for rollback
- partial insertion calls `removeBlocks` with only the first **fresh attempted** ID
- the pre-existing neighbor's client ID is never present in any rollback removal call
- no successful operation payload is returned

These tests must fail against `removeInsertedSlice()`: with a requested count of two, its post-dispatch slice can contain the pre-existing neighbor.

- [ ] **Step 4: Add replacement rollback RED tests**

Use a target plus a pre-existing following neighbor and a two-block replacement pattern.

For zero insertion, expect the target restored at its original index and no rollback removal of a neighbor. For partial insertion, expect only the first fresh attempted replacement ID removed, then the original target restored with its original client ID/name/attributes/inner blocks. Assert the final root client-ID order exactly matches the initial order.

Also simulate a no-op restoration dispatch. Require `ok: false`, a rollback/restoration-specific error, and no successful operation. Do not hide an unconfirmed restoration behind `ok: true` or describe the tree as restored when verification failed.

- [ ] **Step 5: Add live Core permission RED tests**

Add these cases:

1. `canInsertBlockType` returns false for one top-level parsed block: insert-before/after returns false before `insertBlocks`.
2. `canRemoveBlock(targetClientId)` returns false: replacement returns false before either `removeBlocks` or `insertBlocks`.
3. `canInsertBlockType` returns false for the replacement pattern: replacement preserves the target and performs no dispatch.
4. `canInsertBlockType` returns false for the original target block type: replacement fails before removal because rollback could not be guaranteed.
5. An inserted/partially inserted runtime ID is rejected by `canRemoveBlocks`: cleanup does not guess or remove a neighbor, the operation returns a distinct rollback failure, and it never reports success.
6. Either required selector is missing: the relevant operation fails closed with no dispatch.
7. A selected block with `lock: { move: true, remove: false }` remains eligible for sibling insertion and replacement when Core's destination/removal selectors return `true`.
8. A selected block with `lock: { move: false, remove: true }` remains eligible for sibling insertion, while replacement fails before dispatch because `canRemoveBlock(targetClientId)` returns `false`.
9. A selected container with its own `templateLock: 'all'` remains eligible for sibling insertion and replacement when selectors for its parent/destination root return `true`.

Use Core selectors for every live-state decision. Do not add custom ancestor/template traversal. The only direct parsed-payload check permitted below is the top-level block's own `lock.remove` value, because a not-yet-inserted block has no client ID that Core selectors can query.

- [ ] **Step 6: Add successful runtime-ID and undo RED tests**

For successful insert, capture the fresh top-level IDs received by `insertBlocks` and assert `result.operations[0].insertedClientIds` equals that ordered list. For successful replacement, assert the corresponding fresh list under `replacementClientIds`, matching the existing rollback contract in `block-operation-catalog.js`. Also assert the operation IDs differ from the cached parser fixture IDs.

Add undo cases that prove:

- insert undo removes the exact recorded `insertedClientIds`
- replacement undo removes the exact recorded `replacementClientIds` and restores the original target
- an activity missing the operation-specific runtime-ID array fails before any dispatch
- an activity with empty, duplicate, unresolved, or wrong-root runtime IDs fails before any dispatch
- native `canRemoveBlocks` denial and missing selectors fail before any undo dispatch
- replacement undo fails before dispatch when the original target type cannot be reinserted
- a no-op `removeBlocks` dispatch returns false after verifying the exact runtime IDs are still present
- a no-op restoration dispatch returns false after verifying the original target was not restored at its recorded root/index
- `getBlockStructuralActivityUndoState()` reports `canUndo: false` for missing, empty, duplicate, unresolved, wrong-root, or currently unauthorized runtime IDs instead of exposing a doomed Undo action
- the existing post-apply structural-signature drift gate still blocks undo first
- a persisted structural activity reloaded against an otherwise identical block tree with regenerated top-level/inner IDs reports `canUndo: false`, and an attempted undo performs no dispatch

Legacy operations without exact runtime IDs must fail closed with the existing missing-recorded-structure style of error. Do not retain a positional/count fallback.

Treat the regenerated-ID case as an intentional editor-session boundary, not structural drift and not a reconciliation opportunity. Use stable localized copy such as “The recorded blocks are no longer available in this editor session, so this structural action cannot be undone automatically.” The activity row remains useful audit history after reload.

- [ ] **Step 7: Run the structural tests and capture RED**

```bash
npm run test:unit -- --runInBand src/utils/__tests__/block-structural-actions.test.js src/utils/__tests__/block-operation-catalog.test.js
```

Expected: both repeated applies reuse cached IDs, partial rollback deletes/targets a neighbor, runtime IDs are absent, permission denials do not preflight, and missing/unresolved-ID undo still relies on a slice.

- [ ] **Step 8: Clone parsed pattern identity, then preflight permissions**

Import `cloneBlock` from `@wordpress/blocks`. In `parseBlocksForOperation()`, after the parser returns a nonempty array and before any ID/snapshot helper runs, create:

```js
const freshBlocks = blocks.map( ( block ) => cloneBlock( block ) );
```

Use `freshBlocks` for every requested-ID check, permission check, snapshot, dispatch, and operation payload. `cloneBlock()` recursively assigns new IDs to inner blocks, so do not add a second recursive UUID helper. Keep the existing JSON-based `cloneBlockTree()` only for serializable activity snapshots and restoration paths that must preserve the removed block's original identity.

Add small internal helpers with these contracts:

```js
function getRequestedTopLevelClientIds( blocks = [] ) {
	const ids = blocks.map( ( block ) => block?.clientId || '' );

	return ids.every( Boolean ) && new Set( ids ).size === ids.length
		? ids
		: [];
}

function canInsertParsedBlocks( blocks, rootClientId, blockEditorSelect ) {
	if ( typeof blockEditorSelect?.canInsertBlockType !== 'function' ) {
		return false;
	}

	return blocks.every(
		( block ) =>
			Boolean( block?.name ) &&
			blockEditorSelect.canInsertBlockType( block.name, rootClientId ) ===
				true
	);
}
```

After cloning and before dispatch, require non-empty unique fresh top-level IDs, a callable `canRemoveBlocks` selector for rollback/undo verification, and capture which requested IDs already exist. Fail before mutation if any requested ID is already present; even a UUID collision cannot later be claimed as this operation's insertion.

Because parsed blocks do not yet have queryable store identities, mirror only Core's own per-block removal-lock predicate before insertion: a defined truthy `attributes.lock.remove` is not rollback-capable and must be rejected. Explicit `false` remains allowed. Add tests for `true`, `false`, and a malformed nonempty string so this narrow preflight cannot be confused with the Task 3 collector/apply target-lock contract. Ancestor, template, section, and inserter constraints remain exclusively Core-selector decisions.

For replacement, preflight all three facts before removing the target:

```text
canRemoveBlock(targetClientId) === true
all replacement block types are insertable into the live root
the original target block type is insertable into the live root for rollback
```

- [ ] **Step 9: Replace slice ownership with a before/after requested-ID presence diff**

After `insertBlocks`, derive ownership only from the requested IDs:

```js
const insertedClientIds = requestedClientIds.filter(
	( clientId ) =>
		! beforePresentClientIds.has( clientId ) &&
		Boolean( blockEditorSelect.getBlock?.( clientId ) )
);
```

Success requires all of the following:

- every requested ID is newly present
- the live root slice at the intended index has exactly the requested client IDs in order
- the slice snapshots match the parsed snapshots
- each inserted top-level block resolves under the intended root
- `canRemoveBlocks(insertedClientIds) === true`, so the recorded operation remains rollback/undo-capable

On failure, remove only `insertedClientIds`. Zero insertion therefore removes nothing. Before partial cleanup, require `canRemoveBlocks(insertedClientIds) === true`; if Core refuses, return a distinct rollback failure without dispatching a broader removal or claiming the initial tree was restored. After cleanup, verify every exact inserted ID is absent. A no-op or filtered removal is a rollback failure.

For replacement, run that exact cleanup and then restore the original block. After restoration, require all of the following before describing the rollback as restored:

- the original client ID resolves again
- it is under the recorded root at the recorded index
- `blockSnapshotsMatch( [ restoredBlock ], removedBlocksSnapshot )` is true

If any check fails, return `ok: false` with a restoration failure and never record a successful operation.

Define and test stable codes/messages instead of returning ad hoc English:

```js
const STRUCTURAL_ROLLBACK_ERROR = __(
	'Flavor Agent could not safely roll back the structural change. Review the block structure before continuing.',
	'flavor-agent'
);
const STRUCTURAL_RESTORE_ERROR = __(
	'Flavor Agent could not restore the replaced block after the structural change failed. Review the block structure before continuing.',
	'flavor-agent'
);
const STRUCTURAL_UNDO_PERMISSION_ERROR = __(
	'The current editor constraints do not allow this structural action to be undone automatically.',
	'flavor-agent'
);
const STRUCTURAL_UNDO_INCOMPLETE_ERROR = __(
	'The structural action could not be undone completely. Review the block structure before continuing.',
	'flavor-agent'
);
const STRUCTURAL_UNDO_SESSION_ERROR = __(
	'The recorded blocks are no longer available in this editor session, so this structural action cannot be undone automatically.',
	'flavor-agent'
);
```

Use `code: 'rollback_failed'` when inserted IDs cannot be removed/confirmed absent and `code: 'restore_failed'` when the original target cannot be confirmed restored. Add both codes to `getBlockStructuralActionErrorMessage()` with the constants above so store/UI callers receive the same localized copy. Undo permission preflight returns `STRUCTURAL_UNDO_PERMISSION_ERROR`; a filtered/no-op undo dispatch returns `STRUCTURAL_UNDO_INCOMPLETE_ERROR`; an otherwise signature-matching persisted row whose exact IDs no longer resolve returns `STRUCTURAL_UNDO_SESSION_ERROR`.

Delete `removeInsertedSlice()`. Retain positional slices only for non-destructive success verification, never for ownership or deletion.

On success, store:

- `insertedClientIds` for `insert_pattern`
- `replacementClientIds` for `replace_block_with_pattern`
- the normalized inserted snapshot already used by signatures
- the existing removed snapshot for replacement

- [ ] **Step 10: Make undo validate all exact current-session IDs before its first mutation**

After the existing structural-signature equality check, resolve the operation-specific runtime IDs for every operation. Require a non-empty unique list whose live blocks all exist under the recorded root, `canRemoveBlocks(runtimeIds) === true`, and, for replacement, `canInsertBlockType(originalBlockName, rootClientId) === true`. Abort the entire undo before dispatch if any operation fails validation or a selector is missing.

Use the same metadata validator in `getBlockStructuralActivityUndoState()` so malformed/legacy, reloaded/unresolved, wrong-root, or currently unauthorized activity never advertises `canUndo: true`. When every recorded runtime ID is unresolved but the snapshot-based structural signature still matches, return the current-editor-session error above; never search by index, path, name, attributes, or snapshot to recover replacement IDs.

Update activity-history/undo test selector fixtures with default `canRemoveBlocks: () => true` and `canInsertBlockType: () => true`; override them only in the denial cases so existing happy-path coverage remains representative of WordPress 7.0+.

When validation passes, reverse the operations as today, but remove only the recorded exact IDs. After every removal, verify those IDs no longer resolve. Replacement then restores its recorded removed snapshot and verifies client ID, root, index, and snapshot exactly as the apply rollback does. Return `ok: false` after any filtered/no-op dispatch. Never reconstruct deletion targets from `index` plus snapshot length and never return `{ ok: true }` merely because dispatch functions were called.

- [ ] **Step 11: Rerun the structural matrix**

```bash
npm run test:unit -- --runInBand src/utils/__tests__/block-structural-actions.test.js src/utils/__tests__/block-operation-catalog.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/activity-undo.test.js
npm run lint:js
```

Expected: repeated nested patterns have fresh identities, all zero/partial/action-specific-permission/success/undo/reload cases pass, existing drift behavior remains green, and no source reference to `removeInsertedSlice` remains:

```bash
rg -n "removeInsertedSlice" src tests
```

Expected `rg` exit status: 1 with no matches.

- [ ] **Step 12: Commit the structural safety boundary in the implementation session**

```bash
git add src/utils/block-structural-actions.js src/utils/__tests__/block-structural-actions.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/activity-undo.test.js
git commit -m "Make block structural rollback exact"
```

---

### Task 5: Close the live-context race across all block apply thunks

**Finding:** F3

**Files:**
- Modify: `src/store/index.js:579-724`, `:1467-1494`, `:1987-2257`, `:2281-2571`, `:2590-2821`
- Modify: `src/store/__tests__/store-actions.test.js`
- Modify: `docs/features/block-recommendations.md:38`, `:50`, `:63-65`, `:110-116`

**Interfaces:**
- Consumes: the exact `liveRequestInput.clientId`, non-empty `liveRequestInput.contextSignature` sent for server revalidation, and `getLiveBlockContextData(registry.select.bind(registry), clientId)` immediately before and immediately after `guardSurfaceApplyResolvedFreshness()`.
- Produces: a request-bound immutable live signature baseline for one apply attempt and a verified post-await live context used by the structural path.

- [ ] **Step 1: Add a deterministic deferred ability-response helper to the store tests**

Import the mocked `getLiveBlockContextData` and add a local deferred helper:

```js
function createDeferred() {
	let resolve;
	let reject;
	const promise = new Promise( ( promiseResolve, promiseReject ) => {
		resolve = promiseResolve;
		reject = promiseReject;
	} );

	return { promise, reject, resolve };
}
```

For each race test, return `deferred.promise` from the signature-only `apiFetch`, start the thunk without awaiting it, wait until the request has been observed, mutate the underlying test registry/editor block attributes or tree and change the mocked live signature, resolve the server response with the otherwise matching server signature, then await the thunk. Update existing successful/server-stale block-apply fixtures so each `liveRequestInput.contextSignature` matches that test's initial collector signature; production request builders already provide this field.

- [ ] **Step 2: Add the single-suggestion race RED test**

Configure `liveRequestInput.contextSignature` and the initial `getLiveBlockContextData` return to `live-before`. After the request is held, change the registry's real current content to `User edit during validation` and return `live-after` from the collector. Apply a suggestion that would otherwise replace that content.

Assert:

- thunk returns `false`
- `updateBlockAttributes` is never called
- apply state is `error` with `staleReason: client`
- a `stale_blocked` recommendation outcome is dispatched with reason `client`
- no `LOG_ACTIVITY`, toast enqueue, `SET_BLOCK_CLIENT_CONTEXT_BASELINE`, `SET_BLOCK_RESOLVED_REBASELINE_PENDING`, or `ADOPT_BLOCK_RESOLVED_CONTEXT_BASELINE` action is dispatched
- the registry still contains `User edit during validation` after the thunk settles

Add two separate preflight cases:

1. the initial live collector signature is non-empty but differs from `liveRequestInput.contextSignature`
2. signatures match, but `liveRequestInput.clientId` names a different block than the thunk's `clientId`

Both must produce client-stale state with no signature-only request, editor dispatch, activity, toast, rebaseline, or adoption.

- [ ] **Step 3: Add the batch and structural race RED tests**

Repeat the same deferred sequence for `applySelectedSuggestions()` and `applyBlockStructuralSuggestion()`.

For batch, assert no folded `updateBlockAttributes` call and preserve the batch suggestion key in the error state/outcome.

For structural, mutate the registry's underlying tree while the response is held and assert the changed tree remains byte-for-byte intact with no `removeBlocks` or `insertBlocks` dispatch. Seed the post-await collector return with a visibly different `blockOperationContext` and assert the stale guard stops before parsing or applying it.

Run:

```bash
npm run test:unit -- --runInBand src/store/__tests__/store-actions.test.js -t "live context changes during block apply revalidation"
```

Expected: all three tests fail because current code mutates after the awaited server response.

- [ ] **Step 4: Add block-specific capture and exact-verification helpers**

Add these internal helpers near the existing freshness helpers:

```js
function captureBlockApplyLiveContext( registry, clientId ) {
	if ( typeof registry?.select !== 'function' ) {
		return { context: null, signature: '' };
	}

	return getLiveBlockContextData(
		registry.select.bind( registry ),
		clientId
	);
}

function verifyBlockApplyLiveContextFreshness( {
	baselineSignature,
	clientId,
	registry,
	localDispatch,
	setApplyState,
} ) {
	const liveData = captureBlockApplyLiveContext( registry, clientId );
	const isFresh =
		Boolean( baselineSignature ) &&
		Boolean( liveData?.signature ) &&
		liveData.signature === baselineSignature;

	if ( isFresh ) {
		return { ok: true, liveData };
	}

	const error = buildClientStaleApplyErrorMessage( 'block' );
	localDispatch( setApplyState( 'error', error, 'client' ) );

	return { ok: false, error, staleReason: 'client' };
}
```

The verifier is always fail closed: a missing baseline, missing live signature, or mismatch is client-stale. Initial capture is a separate operation so a future caller cannot accidentally omit the baseline and receive a pass. Keep both helpers block-specific; do not alter unrelated executable surfaces whose collectors and review flows differ.

- [ ] **Step 5: Wrap the awaited server guard in all three thunks**

For `applySuggestion()`, `applySelectedSuggestions()`, and `applyBlockStructuralSuggestion()`:

1. after setting `applying`, call `captureBlockApplyLiveContext()`
2. require `liveRequestInput.clientId === clientId`, require both the captured signature and `liveRequestInput.contextSignature` to be non-empty, and require those signatures to be exactly equal; otherwise set client-stale state, record `stale_blocked: client`, and return before the server call
3. save the captured signature
4. immediately call `await guardSurfaceApplyResolvedFreshness()` with the same `liveRequestInput`
5. if the server guard returns `{ skipped: true }`, do not record a stale outcome; return false and reset apply state to idle only when the stored request token still belongs to this attempt
6. for any other `ok: false` result, preserve the existing server-stale/error behavior
7. immediately call `verifyBlockApplyLiveContextFreshness()` with the saved baseline
8. on mismatch, record `stale_blocked: client` and return before any other post-await side effect
9. only then continue to validation/mutation

There is no asynchronous gap between the request-bound live capture and the server await, and no post-await work before exact verification. Add a regression in which the server guard is skipped after a newer request token appears; assert the newer request owns the state and the old apply cannot leave or reset it to `applying`. Add the same-token abort-controller skip case and assert it returns the apply state to idle.

This guard closes the window; it does not make the window impossible to reopen. The verification is only as good as the absence of suspension points after it, and today all three thunks run synchronously from the guard through `updateBlockAttributes` / `applyBlockStructuralSuggestionOperations`. Record that as an explicit invariant at each call site:

```js
	// INVARIANT: everything from here to the editor dispatch must stay
	// synchronous. Any await introduced below reopens the live-context race
	// that verifyBlockApplyLiveContextFreshness() exists to close, and must be
	// paired with a fresh verification immediately before the mutation.
```

- [ ] **Step 6: Defer block resolved-signature adoption until after the second live guard**

The single and batch thunks currently pass `adoptResolvedContextSignature` into `guardSurfaceApplyResolvedFreshness()`. Stop passing that callback for these block paths. Let the server guard return `{ adopted: true, resolvedContextSignature }`, run the post-await live guard, and only then dispatch:

```js
actions.adoptBlockResolvedContextBaseline(
	clientId,
	resolvedFreshness.resolvedContextSignature
)
```

Dispatch adoption only when `resolvedFreshness.adopted === true` and `resolvedFreshness.resolvedContextSignature` is non-empty. This preserves self-apply rebaseline semantics for stable context while ensuring a client-stale result cannot rebaseline itself before it is rejected.

Add a positive-control test with equal pre/post signatures and `resolvedRebaselinePending: true`; assert adoption precedes mutation and the apply still succeeds.

- [ ] **Step 7: Use the verified post-await context for structural apply**

In `applyBlockStructuralSuggestion()`, source `blockContext` and `blockOperationContext` from the exact post-await verified live context. `blockOperationContext` is mandatory mutation authority: if it is absent, use the existing missing-structural-context validation error and return false. Never fall back to the pre-await `liveRequestInput`, stored recommendations, or attribution data for target signature, allowed pattern/action, editing mode, lock, or permission authority. Stored block context may be used only for non-authoritative activity attribution that the verified context does not carry.

The operation itself still must pass the Task 4 live target, lock, native permission, parsed pattern, and exact-ID checks.

- [ ] **Step 8: Run the focused and complete store suite**

```bash
npm run test:unit -- --runInBand src/store/__tests__/store-actions.test.js -t "live context changes during block apply revalidation"
npm run test:unit -- --runInBand src/store/__tests__/store-actions.test.js
npm run lint:js
```

Expected: the three race tests and stable-context positive control pass; existing server-stale, duplicate-call, rebaseline-order, activity, and undo tests remain green.

- [ ] **Step 9: Update the block recommendation behavior contract**

In `docs/features/block-recommendations.md`:

- change the freshness description from two layers to the client request signature, server resolved signature, and in-flight live editor-context stability gate
- state that apply-time revalidation is bound to the thunk's exact target `clientId` plus the non-empty request context signature
- state that single, batch, and structural apply capture the live context immediately around server revalidation
- state that a mismatch is client-stale and causes no editor mutation, activity, toast, or rebaseline
- state that structural context used for mutation is the verified post-await live context

Run:

```bash
npm run check:docs
```

- [ ] **Step 10: Commit the apply-race closure in the implementation session**

```bash
git add src/store/index.js src/store/__tests__/store-actions.test.js docs/features/block-recommendations.md
git commit -m "Guard block apply against live context races"
```

---

### Task 6: Internationalize the complete rendered block-panel inventory

**Finding:** F5

**Files:**
- Modify: `src/inspector/BlockRecommendationsPanel.js:67-84`, `:399-402`, `:648-660`, `:829-830`, `:895-897`, `:1005-1035`, `:1118-1152`, `:1304-1307`, `:1414-1597`
- Modify: `src/inspector/__tests__/BlockRecommendationsPanel.test.js`
- Modify: `src/store/index.js:256-259`, `:1128-1162`, `:1995-1998`, `:2313-2314`
- Modify: `src/store/__tests__/store-actions.test.js`
- Modify: `src/utils/block-structural-actions.js:509-515`, `:607-613`
- Modify: `src/utils/__tests__/block-structural-actions.test.js`
- Modify: `src/utils/recommendation-actionability.js:39-43`
- Modify: `src/utils/__tests__/recommendation-actionability.test.js`
- Modify: `languages/flavor-agent.pot`

**Interfaces:**
- Consumes: Flavor Agent-owned English from the panel, block request diagnostics, block apply errors, structural failure messages, and actionability labels; dynamic suggestion labels, transport/parser messages, pattern names, and reason lists remain data.
- Produces: WordPress-extractable `flavor-agent` translations with every dynamic model/runtime/validator value passed only through `sprintf()` placeholders.

- [ ] **Step 1: Add/extend tests for formatted behavior and tier labels**

In `recommendation-actionability.test.js`, import `getActionabilityLabel` and assert the source-locale results for `inline-safe`, `review-safe`, `advisory`, and an unknown tier.

In `BlockRecommendationsPanel.test.js`, pin:

- default selected-block rendering does not show the default intro as a secondary scope note
- `Last Selected Block` still shows its supplied intro note
- apply/undo status messages include the model-authored suggestion label once
- review button accessible names include the model-authored label once
- eligibility blocker output contains the joined translated reason labels
- stale/fresh structural buttons retain their current visible source-locale labels
- invalid-JSON and transport diagnostics supplied by the store render their source-locale title/detail text unchanged

In `store-actions.test.js`, pin the source-locale outputs for the invalid-JSON title/detail, transport detail, original parser message, single apply error, advisory apply error, and batch apply error. In `block-structural-actions.test.js`, pin insertion/replacement failure messages with a supplied pattern name and with the product-owned `unknown` fallback. These tests preserve output; POT/source scans below prove extractability.

The repository i18n test double returns source strings, so expected rendered English remains unchanged while `__()` and `sprintf()` become extractable in source.

- [ ] **Step 2: Internationalize module-level helper copy and prompt arrays**

Wrap each constant/array entry at lines 69-84 with `__( ..., 'flavor-agent' )`:

```text
One-click apply stays limited to safe local block changes.
Content-only mode avoids style and settings changes.
This block is content-restricted. Flavor Agent will stay within editable content and may keep broader block ideas as manual guidance only.
Improve clarity
More editorial
Simplify layout
Tighten the copy
Clarify the message
More concise
```

Define translated constants for the default eyebrow and intro:

```js
const SELECTED_BLOCK_EYEBROW = __( 'Selected Block', 'flavor-agent' );
const DEFAULT_BLOCK_INTRO_COPY = __(
	'Ask for a specific outcome or fetch recommendations based on the current block context.',
	'flavor-agent'
);
```

Use `SELECTED_BLOCK_EYEBROW` both as the default prop and in `shouldShowScopeNote` (`:1035`, currently `eyebrow !== 'Selected Block'`). Never compare a translated value with raw `'Selected Block'` — under any non-source locale that comparison is always true and the default scope note reappears as a duplicate.

These constants are evaluated at module scope, so their translations resolve at import time rather than per render. That is the pattern `REASON_LABELS` in `recommendation-actionability.js` already uses, and it is sound under `wp_set_script_translations()` because core prints the locale-data inline script ahead of the bundle. Keep it consistent rather than converting some constants to lazy getters; if translations ever regress in this panel, module-scope evaluation is the first thing to check.

- [ ] **Step 3: Internationalize static render-path copy**

Wrap the reviewed inventory with `__()`:

```text
No recommendations were returned for the current prompt.
suggestion
No block-lane suggestions returned
Recent request diagnostics and applied actions for this block.
Newest valid block action can be undone here.
What do you want to improve about this content?
What do you want to improve about this block?
Describe the content change you want for this block.
Describe the outcome you want for this block.
Server apply context is missing. Refresh before applying the previous result.
Server apply context changed. Refresh before applying the previous result.
Block or prompt changed. Refresh before applying the previous result.
Inline-safe changes can apply directly.
Review required before structural apply.
These ideas are shown for reference from the last request. Refresh before acting on them against the current block.
Manual follow-through only.
Suggestion
Refresh to review
Review
Applying structure
Apply reviewed structure
Structure
Pattern
Style variation
Manual idea
Inline-safe
Review-safe
Advisory
```

Do not wrap `suggestion.label`, `suggestion.description`, `recommendations.explanation`, the user prompt, operation IDs, block names, or pattern names.

- [ ] **Step 4: Convert dynamic sentences and accessible names to `sprintf()`**

Use translator comments and placeholders:

```js
sprintf(
	/* translators: %s: recommendation label. */
	__( 'Applied %s.', 'flavor-agent' ),
	latestBlockActivity?.suggestion || __( 'suggestion', 'flavor-agent' )
)

sprintf(
	/* translators: %s: recommendation label. */
	__( 'Undid %s.', 'flavor-agent' ),
	lastUndoneBlockActivity?.suggestion || __( 'suggestion', 'flavor-agent' )
)

sprintf(
	/* translators: %s: recommendation label. */
	isStale
		? __( 'Refresh to review %s', 'flavor-agent' )
		: __( 'Review %s', 'flavor-agent' ),
	label
)

sprintf(
	/* translators: %s: comma-separated eligibility blocker labels. */
	__( 'Eligibility blockers: %s.', 'flavor-agent' ),
	reasonLabels.join( ', ' )
)
```

The placeholder values are displayed unchanged; translating the sentence does not translate model-authored content or internal identifiers.

- [ ] **Step 5: Internationalize store diagnostics/apply errors and structural failures**

Import `sprintf` beside `__` in both `src/store/index.js` and `src/utils/block-structural-actions.js`.

In the store, wrap the two invalid-JSON constants, `Request failed.`, `Block request failed`, both apply-error strings, and the duplicated batch apply error with `__( ..., 'flavor-agent' )`. Convert the two dynamic diagnostics to translated format strings:

```js
detailLines.push(
	sprintf(
		/* translators: %s: transport error detail returned by the connector. */
		__( 'Transport detail: %s', 'flavor-agent' ),
		wrappedMessage
	)
);

detailLines.push(
	sprintf(
		/* translators: %s: original parser error message. */
		__( 'Original parser message: %s', 'flavor-agent' ),
		rawMessage
	)
);
```

The complete store-owned inventory in this path is:

```text
The block recommendation endpoint returned a non-JSON response.
WordPress REST returned a response the editor could not parse as JSON. Check the HTTP response body and PHP debug log for warning output, a fatal error page, or a proxy/auth HTML response.
Request failed.
Block request failed
Transport detail: %s
Original parser message: %s
This suggestion includes unsupported or unsafe attribute changes and could not be applied.
This suggestion is advisory and requires manual follow-through or a broader preview/apply flow.
```

In `block-structural-actions.js`, convert both ad hoc template literals to placeholders:

```js
sprintf(
	/* translators: %s: block pattern name. */
	__( 'Pattern “%s” could not be inserted for the selected block.', 'flavor-agent' ),
	operation.patternName || __( 'unknown', 'flavor-agent' )
)

sprintf(
	/* translators: %s: block pattern name. */
	__( 'Pattern “%s” could not replace the selected block.', 'flavor-agent' ),
	operation.patternName || __( 'unknown', 'flavor-agent' )
)
```

Do not translate a real pattern name, connector message, parser message, suggestion label, prompt, block name, or operation identifier. Only the surrounding Flavor Agent-owned sentence and product-owned fallback are translated.

- [ ] **Step 6: Run the focused JS tests and lint**

```bash
npm run test:unit -- --runInBand src/inspector/__tests__/BlockRecommendationsPanel.test.js src/store/__tests__/store-actions.test.js src/utils/__tests__/block-structural-actions.test.js src/utils/__tests__/recommendation-actionability.test.js
npm run lint:js
```

Expected: all rendered source-locale behavior remains stable and raw product strings in the panel, store diagnostics/apply paths, structural failures, and tier helper no longer bypass i18n.

- [ ] **Step 7: Regenerate and inspect the POT catalog**

Run with a WordPress CLI that provides the i18n command. Prefer the host binary if it resolves:

```bash
wp i18n make-pot . languages/flavor-agent.pot --domain=flavor-agent --exclude=build,dist,node_modules,vendor,output,.git,.worktrees
```

WP-CLI is not guaranteed on the host. Per `CLAUDE.md` it is available inside the running container, so fall back to that before declaring the step blocked:

```bash
npm run wp:start   # only if the stack is not already up
docker exec wordpress-wordpress-1 wp i18n make-pot \
  /var/www/html/wp-content/plugins/flavor-agent \
  /var/www/html/wp-content/plugins/flavor-agent/languages/flavor-agent.pot \
  --domain=flavor-agent \
  --exclude=build,dist,node_modules,vendor,output,.git,.worktrees \
  --allow-root
```

Confirm representative new entries and translator comments exist:

```bash
rg -n "Applied %s\.|Refresh to review %s|Eligibility blockers: %s\.|Transport detail: %s|Original parser message: %s|could not be inserted for the selected block|unsupported or unsafe attribute changes|What do you want to improve about this block" languages/flavor-agent.pot
```

Only if neither path is available, record that as a release blocker; do not hand-author a partial catalog and call the finding complete. A missing host `wp` alone is not a blocker while the container path works.

- [ ] **Step 8: Commit the block-panel i18n completion in the implementation session**

```bash
git add src/inspector/BlockRecommendationsPanel.js src/inspector/__tests__/BlockRecommendationsPanel.test.js src/store/index.js src/store/__tests__/store-actions.test.js src/utils/block-structural-actions.js src/utils/__tests__/block-structural-actions.test.js src/utils/recommendation-actionability.js src/utils/__tests__/recommendation-actionability.test.js languages/flavor-agent.pot
git commit -m "Internationalize block recommendation copy"
```

---

### Task 7: Add browser regressions and finish the public behavior docs

**Findings:** F1, F1b, F2, F2b, and F3-F5 integration evidence

**Files:**
- Modify: `tests/e2e/flavor-agent.smoke.spec.js:2140-3025`
- Modify: `docs/features/block-recommendations.md`
- Modify: `docs/features/activity-and-audit.md`
- Modify: `docs/reference/abilities-and-routes.md:365-435`

**Interfaces:**
- Consumes: mocked recommendation ability responses, a live WP 7.0+ block editor, Gutenberg action-specific lock attributes/selectors, and Playwright network observation.
- Produces: browser evidence that unit-level trust boundaries hold in the integrated editor.

- [ ] **Step 1: Make the structural smoke tests action-specific**

Rename `@wp70-site-editor block structural review applies, blocks locked targets, and undoes` so it describes action-specific permissions. Set the selected block to `lock: { move: true, remove: true }` **before** fetching recommendations, remove the late blanket-lock denial/reset branch, and let the existing `insert_pattern` review/apply/undo path succeed. Sibling insertion does not move or remove the selected block; Core's `canInsertBlockType` decision for the parent/destination root is authoritative.

In `@wp70-site-editor block structural replace applies and undoes`, set `lock: { move: true, remove: false }` before fetching recommendations and preserve the successful replacement/undo assertions. Movement locking does not prohibit removal, so replacement remains available when `canRemoveBlock` returns `true`.

The focused unit matrix from Tasks 3–4 carries the destructive counterexample: `lock.remove: true` maps to `canRemoveBlock === false`, blocking replacement before dispatch while leaving sibling insertion available. It also carries the selected-container `templateLock` case, because that requires a container fixture and is more discriminating at selector-call level than repeating it on the paragraph-based smoke fixture.

- [ ] **Step 2: Add a server-bypass preview network-defense smoke test**

Add `@wp70-site-editor block preview metadata cannot initiate a network request`:

1. intercept `recommend-block` and return a normal executable suggestion whose `preview` is `url(https://preview-probe.invalid/pixel)`; this intentionally bypasses PHP normalization
2. observe/route `https://preview-probe.invalid/**` and collect every attempted request
3. open the block Inspector and fetch recommendations
4. wait for the malicious suggestion label to render
5. assert its chip/row has an empty `--flavor-agent-chip-preview` property and no `.flavor-agent-chip__preview`
6. wait for two animation frames, then assert the collected request list is empty

This is defense-in-depth evidence. The server test proves the authoritative parser; this browser test proves a compromised or mocked response cannot reach the CSS URL sink.

- [ ] **Step 3: Add an in-flight live-context browser race test**

Add `@wp70-site-editor block apply aborts when live context changes during signature revalidation`:

1. seed a paragraph with `Original content`
2. return an inline-safe recommendation that would set content to `AI content`
3. hold only the `resolveSignatureOnly: true` route behind a test-controlled promise
4. start Apply and wait until that route is captured
5. dispatch a user edit setting the selected paragraph to `User edit during validation`
6. release the route with the otherwise matching server resolved signature
7. assert content remains `User edit during validation`
8. assert the block apply state is error/client-stale
9. assert no apply activity row was added for the recommendation and no success/undo toast appears

Do not use arbitrary multi-second sleeps. Synchronize on the captured signature-only request and use `expect.poll()` for the terminal editor/store state.

- [ ] **Step 4: Document the preview and structural contracts**

In `docs/reference/abilities-and-routes.md`, state that normalized recommendation `preview` metadata is optional and, when present, is a lowercase hex color with 3, 4, 6, or 8 digits (`#rgb`, `#rgba`, `#rrggbb`, or `#rrggbbaa`); all other values become `null`. Clarify that this field is display-only and never an arbitrary CSS expression.

In `docs/features/block-recommendations.md`, ensure the final text states:

- every parsed pattern block is recursively cloned with `cloneBlock()` before identity capture, so repeated cached patterns receive fresh top-level and inner IDs
- sibling insertion preflights `canInsertBlockType` at the parent/destination root; replacement additionally preflights `canRemoveBlock` for the selected target
- selected `lock.move`, `lock.remove`, and container `templateLock` values are not collapsed into a blanket target-lock boolean
- Core selectors, not custom ancestor policy, decide live permission
- rollback owns blocks by requested client-ID before/after presence, never by a requested-length slice
- successful runtime operations record exact inserted/replacement IDs
- structural undo requires those exact IDs plus the existing post-apply signature and is intentionally limited to the editor runtime that created them
- after reload, persisted structural activity remains audit history but regenerated Gutenberg IDs make the row non-undoable; no path/snapshot fallback is attempted
- legacy/malformed activity without exact IDs fails closed
- the new in-flight live-context gate covers single, batch, and structural apply

In `docs/features/activity-and-audit.md`, state that structural activity rows remain persisted audit history, but editor-side structural undo depends on the exact runtime client IDs recorded by the active editor session. After a reload regenerates those IDs, the hydrated row projects as non-undoable; the system does not infer replacements from position, path, or snapshots.

- [ ] **Step 5: Rebuild, then run the targeted WP 7.0 browser slice**

The build is mandatory and is not implied by the Playwright run. `build/` is gitignored, `playwright.wp70.config.js` has no build step, and `tests/e2e/wp70.global-setup.js` does not compile `src/` — the `docker compose up -d --build` at `scripts/wp70-e2e.js:302` rebuilds the container image only. Tasks 2 through 6 all change `src/`, so skipping this runs the browser assertions against pre-remediation bundles.

```bash
npm run build
npx playwright test -c playwright.wp70.config.js tests/e2e/flavor-agent.smoke.spec.js --grep "@wp70-site-editor block (inspector|preview metadata|apply aborts|structural)"
```

Expected: six tests — the existing inspector flow, structural action-specific insert/undo flow, default-enabled structural flow, structural replacement flow, plus the new malicious-preview defense and in-flight race. If the count is four, the two new tests were not picked up; check the titles against the grep before interpreting the result.

- [ ] **Step 6: Run docs freshness and commit integration evidence in the implementation session**

```bash
npm run check:docs
git add tests/e2e/flavor-agent.smoke.spec.js docs/features/block-recommendations.md docs/features/activity-and-audit.md docs/reference/abilities-and-routes.md
git commit -m "Add block recommendation safety regressions"
```

---

### Task 8: Run the additive release gates and record the result honestly

**Findings:** F1, F1b, F2, F2b, and F3-F6 final sign-off

**Files:**
- Verify: all files above
- Inspect: `output/verify/summary.json`
- Inspect: `STATUS.md` for current browser-harness health

**Interfaces:**
- Consumes: the complete implementation and repository verification runner.
- Produces: focused, aggregate, browser, and working-tree evidence sufficient to classify the remediation as passed, failed, or environment-blocked.

- [ ] **Step 1: Run the complete targeted PHP matrix**

```bash
composer run test:php -- --filter 'RecommendationAbilityExecutionTest|PromptRulesTest|ResponseSchemaTest|BlockOperationValidatorTest|BlockOperationContextTest'
composer run test:php
composer run lint:php
```

The full suite is required here, not optional. F6 is a cross-test isolation fix, so a filtered slice cannot demonstrate it; only a full run proves the shared `reset()` change did not perturb another test that depended on leaked runtime state. The hard floor after Task 1 is 2,245 tests / 10,527 assertions; Task 3 may replace or add PHP parity cases, so record the actual final count and require it never to fall below that floor.

- [ ] **Step 2: Run the complete targeted JS matrix**

```bash
npm run test:unit -- --runInBand src/utils/__tests__/suggestion-preview-color.test.js src/inspector/__tests__/SuggestionChips.test.js src/admin/__tests__/activity-log-utils.test.js src/utils/__tests__/block-allowed-pattern-context.test.js src/context/__tests__/collector.test.js src/utils/__tests__/block-structural-actions.test.js src/utils/__tests__/block-operation-catalog.test.js src/store/__tests__/store-actions.test.js src/store/__tests__/activity-history.test.js src/store/__tests__/activity-undo.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js src/utils/__tests__/recommendation-actionability.test.js
npm run lint:js
```

Expected: all affected behavior and adjacent activity/undo contracts pass. Record the final per-suite/test count; the touched pre-existing coverage must not disappear when the permission-helper plan is removed.

- [ ] **Step 3: Run documentation and non-browser aggregate gates**

```bash
npm run check:docs
node scripts/verify.js --skip-e2e
```

Inspect both the terminal `VERIFY_RESULT={...}` line and `output/verify/summary.json`. Required classification:

- `passed`: every requested non-browser step, including Plugin Check, completed successfully
- `failed`: a requested step ran and failed; fix the regression and rerun the nearest test plus this aggregate gate
- `incomplete`: a prerequisite such as `wp` or `WP_PLUGIN_CHECK_PATH` was unavailable; record the environment blocker and do not call it a pass

- [ ] **Step 4: Rebuild, then run the two browser harnesses required for the touched block surface**

Build first. Neither `npm run test:e2e:playground` nor `npm run test:e2e:wp70` compiles `src/`; both consume whatever is already in `build/`.

```bash
npm run build
```

Run the focused WP 7.0 slice first:

```bash
npx playwright test -c playwright.wp70.config.js tests/e2e/flavor-agent.smoke.spec.js --grep "@wp70-site-editor block (inspector|preview metadata|apply aborts|structural)"
```

Then run the Playground block Inspector coverage and the full WP 7.0 suite:

```bash
npm run test:e2e:playground
npm run test:e2e:wp70
```

If `npm run verify` (Step 5) has already run to completion in this session with no `src/` edits since, its build output is current and this rebuild is redundant. Any `src/` edit after a build invalidates every browser result taken before it.

If either harness is unavailable or known-red for an unrelated reason, record the exact command, error, affected gate, and waiver/blocker. Unit tests are not a substitute for browser proof.

- [ ] **Step 5: Run the full aggregate release command when prerequisites are available**

```bash
npm run verify
```

Expected: build, JS lint, Plugin Check, JS unit, PHP lint/test, Playground E2E, and WP 7.0 E2E complete in order with a passing final result. If the environment cannot run a hard gate, retain the incomplete classification rather than weakening or silently skipping it.

- [ ] **Step 6: Perform the final source and working-tree audit**

```bash
git diff --check
git status --short
rg -n "removeInsertedSlice|style=.*s\.preview|--flavor-agent-chip-preview.*s\.preview" src tests
rg -n "block-structural-lock|hasStructuralLockValue|hasLockedBlockAttribute|context\.isTargetLocked \|\| context\.locked" src inc tests
rg -n 'Transport detail: \$\{|Original parser message: \$\{|error: `Pattern' src
rg -n "AaaLeakProbeTest" tests
rg -n '\^\(\?:rgb\|hsl\)a\?\\\(\[\^;\{\}\]\*\\\)\$' src/admin
```

Expected:

- `git diff --check` prints nothing and exits 0
- only intended source/test/docs/catalog changes appear
- `docs/flavor-agent-wordpress-context-audit.md` remains untouched unless separately authorized
- `build/` and `dist/` contain no hand-edited or accidentally staged files
- the forbidden rollback helper and raw preview CSS assignments have no matches
- no proposed blanket structural-lock helper or selected-target blanket validator survives
- no block diagnostic/structural failure interpolates dynamic values outside translated `sprintf()` format strings
- the temporary leak probe from Task 1 Step 1 was deleted and has no matches
- no paren-permissive variant of the admin colour guard survives; `src/admin/activity-log-utils.js` must exclude `()` from the `rgb`/`hsl` class
- `inc/` is absent from the diff for Task 1 — the F6 fix is test-harness-only

- [ ] **Step 7: Review the final finding matrix before claiming completion**

Record one evidence line for each of F1, F1b, F2, F2b, F3, F4, F5, and F6 with its focused test, aggregate result, and browser result where applicable. Completion requires all eight findings implemented, their focused tests green, the non-browser aggregate classified, and browser blockers/waivers explicitly recorded. Deployment and publication remain outside this plan.

---

## Final Definition of Done

- Hostile or malformed preview metadata is absent after PHP parsing and remains inert if a test/mocked response bypasses PHP.
- No CSS declaration reachable from model-derived text can resolve a URL: the Inspector chip preview accepts only the four hex forms, and the AI Activity admin swatch guard matches whole colour values instead of prefixes.
- Structural insert and replacement cannot delete pre-existing neighboring blocks during zero/partial Core insertion.
- Reapplying the same cached nested pattern produces fresh top-level and inner client IDs on every attempt.
- Structural apply records operation-specific exact runtime IDs; structural undo refuses to guess when they are missing, invalid, or regenerated after reload.
- Persisted structural activity remains visible after reload, but exact-ID structural undo is explicitly unavailable outside the editor runtime that created the IDs.
- Native Core insertion/removal selectors are consulted immediately before structural mutation and a missing/false selector fails closed.
- Sibling insertion follows destination `canInsertBlockType`; replacement additionally follows target `canRemoveBlock`, so movement-only, removal-only, and selected-container `templateLock` cases match Core's action-specific semantics.
- Single, batch, and structural block apply reject an editor-context change that occurs during server revalidation without mutation, activity, toast, or rebaseline.
- Every reviewed Flavor Agent-owned block-panel, request-diagnostic, apply-error, structural-failure, and actionability-tier string is extractable through the `flavor-agent` text domain, while model/runtime-authored values stay verbatim in placeholders.
- `Provider`'s one-shot runtime chat statics are drained between PHPUnit tests, so `RecommendationAbilityExecutionTest`'s exact transport-map assertion holds under every execution order, and a same-class regression pins that guarantee without depending on file ordering.
- Every browser result was produced from a build that postdates the last `src/` edit.
- Targeted PHP/JS tests, the full PHP suite, lint, docs freshness, `verify.js --skip-e2e`, the matching Playwright harnesses, and the full verification runner are either green or explicitly classified with an environment blocker/waiver.
