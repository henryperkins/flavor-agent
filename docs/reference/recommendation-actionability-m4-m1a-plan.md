# Recommendation Actionability M1A And M4 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the remaining recommendation-actionability work by first improving template-part operation yield, then adding guarded block structural apply/undo behind the existing feature flag.

**Architecture:** M1A stays inside the existing template-part prompt/parser/review/apply flow and should not introduce new mutation primitives. M4 adds a block-only structural executor that consumes the M2/M3 validator-approved operation contract, revalidates live editor state immediately before mutation, records structural activity only after success, and disables undo when post-apply state diverges.

**Tech Stack:** WordPress plugin, PHP 8+, Gutenberg block editor data store, `@wordpress/scripts` Jest, PHPUnit, Playwright, existing Flavor Agent REST/Abilities routes and activity history.

---

## Execution Order

Implement M1A first. It is a lower-risk product improvement on an already transactional surface and should be independently shippable. Implement M4 second because it introduces block structural mutation and must satisfy the full structural-apply validation gate.

## File Map

### M1A: Template-Part Yield Improvement

- Modify: `inc/LLM/TemplatePartPrompt.php`
  - Tighten system and user prompt language so valid `operations[]` are preferred when executable targets and patterns exist.
  - Improve operation target and insertion anchor formatting with explicit copy/paste-safe operation snippets.
  - Keep parser behavior advisory for invalid or ambiguous `patternSuggestions`.
- Modify: `tests/phpunit/TemplatePartPromptTest.php`
  - Add prompt-content tests for target-specific operation guidance.
  - Add parser tests proving invalid pattern suggestions stay advisory while valid operations survive.
- Modify: `src/template-parts/template-part-recommender-helpers.js`
  - Include stable target labels and expected target metadata in client-side template-part request context when missing.
- Modify: `src/template-parts/__tests__/template-part-recommender-helpers.test.js`
  - Cover operation targets, insertion anchors, and signature stability.
- Verify unchanged behavior: `src/template-parts/TemplatePartRecommender.js`, `src/utils/template-actions.js`, and `src/utils/__tests__/template-actions.test.js`.

### M4: Block Structural Apply And Undo

- Create: `src/utils/block-structural-actions.js`
  - Prepare, validate, apply, rollback, and undo selected-block structural operations.
- Create: `src/utils/__tests__/block-structural-actions.test.js`
  - Unit coverage for all apply/rollback/undo edge cases before wiring UI.
- Modify: `src/store/activity-undo.js`
  - Add structural block activity entry metadata and undo routing.
  - Preserve current inline block attribute undo semantics.
- Modify: `src/store/activity-history.js`
  - If needed, extend activity normalization only for structural operation metadata.
- Modify: `src/store/index.js`
  - Add an M4 `applyBlockStructuralSuggestion` thunk or extend block apply with a separate structural path.
  - Keep existing `applySuggestion` for inline-safe attributes unchanged.
- Modify: `src/inspector/BlockRecommendationsPanel.js`
  - Add a disabled-by-default M4 confirm/apply control inside the existing review details.
  - Keep stale/disabled review cards visible but non-mutating.
- Modify: `src/admin/activity-log-utils.js` and tests if operation labels need UI differentiation.
- Modify: `tests/e2e/flavor-agent.smoke.spec.js`
  - Add one happy-path block structural apply/undo smoke and at least one stale/blocked smoke or record a waiver.
- Modify docs only after behavior is stable:
  - `docs/features/block-recommendations.md`
  - `docs/reference/recommendation-ui-consistency.md`
  - `docs/FEATURE_SURFACE_MATRIX.md`
  - `docs/reference/abilities-and-routes.md` if REST/Abilities response contracts change.

## Phase 1: M1A Template-Part Yield Improvement

### Task 1: Make Template-Part Prompt Targets Operation-First

**Files:**
- Modify: `inc/LLM/TemplatePartPrompt.php`
- Test: `tests/phpunit/TemplatePartPromptTest.php`

- [ ] **Step 1: Add failing prompt test for operation-ready context**

Add a test in `TemplatePartPromptTest.php` that builds a context with one available pattern, one insertion anchor, and one executable operation target. Assert the prompt includes explicit operation snippets and tells the model to return `operations[]` when the change maps to those snippets.

```php
public function test_template_part_prompt_shows_copy_safe_operation_examples_for_executable_targets(): void {
	$prompt = TemplatePartPrompt::build_user(
		[
			'templatePartRef' => 'theme//header',
			'slug' => 'header',
			'title' => 'Header',
			'area' => 'header',
			'blockTree' => [
				[
					'path' => [ 0 ],
					'name' => 'core/group',
					'label' => 'Group',
				],
			],
			'patterns' => [
				[
					'name' => 'theme/header-utility',
					'title' => 'Header Utility',
				],
			],
			'operationTargets' => [
				[
					'path' => [ 0 ],
					'name' => 'core/group',
					'label' => 'Header group',
					'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
					'allowedInsertions' => [ 'before_block_path', 'after_block_path' ],
				],
			],
			'insertionAnchors' => [
				[
					'placement' => 'before_block_path',
					'targetPath' => [ 0 ],
					'blockName' => 'core/group',
					'label' => 'Before Header group',
				],
			],
		],
		'Add a utility row'
	);

	$this->assertStringContainsString( '## Executable Operation Examples', $prompt );
	$this->assertStringContainsString( '"type":"insert_pattern"', $prompt );
	$this->assertStringContainsString( '"patternName":"theme/header-utility"', $prompt );
	$this->assertStringContainsString( '"placement":"before_block_path"', $prompt );
	$this->assertStringContainsString( '"targetPath":[0]', $prompt );
	$this->assertStringContainsString( '"type":"replace_block_with_pattern"', $prompt );
}
```

- [ ] **Step 2: Run the prompt test and verify it fails**

Run:

```bash
composer test:php -- --filter TemplatePartPromptTest::test_template_part_prompt_shows_copy_safe_operation_examples_for_executable_targets
```

Expected: FAIL because `## Executable Operation Examples` is not emitted yet.

- [ ] **Step 3: Add operation-example formatting**

In `TemplatePartPrompt.php`, add a private formatter near the existing target/anchor formatters:

```php
private static function format_operation_examples( array $patterns, array $targets, array $anchors ): string {
	$first_pattern = '';
	foreach ( $patterns as $pattern ) {
		if ( is_array( $pattern ) && ! empty( $pattern['name'] ) ) {
			$first_pattern = sanitize_text_field( (string) $pattern['name'] );
			break;
		}
	}

	if ( '' === $first_pattern ) {
		return '';
	}

	$examples = [];

	foreach ( $anchors as $anchor ) {
		if ( ! is_array( $anchor ) ) {
			continue;
		}

		$placement = sanitize_key( (string) ( $anchor['placement'] ?? '' ) );
		$path      = self::sanitize_block_path( $anchor['targetPath'] ?? null );

		if ( in_array( $placement, [ 'before_block_path', 'after_block_path' ], true ) && null !== $path ) {
			$examples[] = wp_json_encode(
				[
					'type'        => 'insert_pattern',
					'patternName' => $first_pattern,
					'placement'   => $placement,
					'targetPath'  => $path,
				],
				JSON_UNESCAPED_SLASHES
			);
			break;
		}
	}

	foreach ( $targets as $target ) {
		if ( ! is_array( $target ) ) {
			continue;
		}

		$path = self::sanitize_block_path( $target['path'] ?? null );
		$name = sanitize_text_field( (string) ( $target['name'] ?? '' ) );
		$allowed = is_array( $target['allowedOperations'] ?? null ) ? $target['allowedOperations'] : [];

		if ( null !== $path && '' !== $name && in_array( 'replace_block_with_pattern', $allowed, true ) ) {
			$examples[] = wp_json_encode(
				[
					'type'              => 'replace_block_with_pattern',
					'patternName'       => $first_pattern,
					'targetPath'        => $path,
					'expectedBlockName' => $name,
				],
				JSON_UNESCAPED_SLASHES
			);
			break;
		}
	}

	return implode( "\n", array_filter( $examples ) );
}
```

Then call it from `build_user()` after insertion anchors:

```php
$operation_examples = self::format_operation_examples( $patterns, $operation_targets, $insertion_anchors );
if ( '' !== $operation_examples ) {
	$budget->add_section(
		'operation_examples',
		"## Executable Operation Examples\n{$operation_examples}\nUse these shapes when the user request maps to an executable target. Keep invalid or ambiguous ideas in patternSuggestions/blockHints only.",
		62
	);
}
```

- [ ] **Step 4: Verify the prompt test passes**

Run:

```bash
composer test:php -- --filter TemplatePartPromptTest::test_template_part_prompt_shows_copy_safe_operation_examples_for_executable_targets
```

Expected: PASS.

### Task 2: Preserve Template-Part Advisory Pattern Behavior

**Files:**
- Modify: `tests/phpunit/TemplatePartPromptTest.php`
- Modify only if needed: `inc/LLM/TemplatePartPrompt.php`

- [ ] **Step 1: Add parser regression for mixed valid operation plus invalid pattern suggestion**

Add a test proving a valid operation survives while invalid pattern suggestions remain advisory-only and do not become executable operations.

```php
public function test_template_part_parser_keeps_valid_operations_when_pattern_suggestions_are_mixed(): void {
	$context = [
		'patterns' => [
			[
				'name' => 'theme/header-utility',
			],
		],
		'blockTree' => [
			[
				'path' => [ 0 ],
				'name' => 'core/group',
				'label' => 'Header group',
				'attributes' => [],
				'childCount' => 0,
			],
		],
		'insertionAnchors' => [
			[
				'placement' => 'after_block_path',
				'targetPath' => [ 0 ],
				'label' => 'After Header group',
			],
		],
		'operationTargets' => [
			[
				'path' => [ 0 ],
				'name' => 'core/group',
				'allowedOperations' => [ 'replace_block_with_pattern', 'remove_block' ],
			],
		],
	];

	$raw = wp_json_encode(
		[
			'suggestions' => [
				[
					'label' => 'Add utility row',
					'description' => 'Add a compact utility row after the header group.',
					'patternSuggestions' => [ 'theme/header-utility', 'theme/missing' ],
					'operations' => [
						[
							'type' => 'insert_pattern',
							'patternName' => 'theme/header-utility',
							'placement' => 'after_block_path',
							'targetPath' => [ 0 ],
						],
					],
				],
			],
		]
	);

	$result = TemplatePartPrompt::parse_response( $raw, $context );

	$this->assertIsArray( $result );
	$this->assertSame( [ 'theme/header-utility' ], $result['suggestions'][0]['patternSuggestions'] );
	$this->assertSame( 'insert_pattern', $result['suggestions'][0]['operations'][0]['type'] );
	$this->assertSame( 'theme/header-utility', $result['suggestions'][0]['operations'][0]['patternName'] );
}
```

- [ ] **Step 2: Run parser regression**

Run:

```bash
composer test:php -- --filter TemplatePartPromptTest::test_template_part_parser_keeps_valid_operations_when_pattern_suggestions_are_mixed
```

Expected: PASS if current parser already satisfies this. If it fails, update only `TemplatePartPrompt::parse_response()` normalization so valid operations are not discarded because one `patternSuggestions[]` item is invalid.

### Task 3: Improve Client Template-Part Target Context

**Files:**
- Modify: `src/template-parts/template-part-recommender-helpers.js`
- Test: `src/template-parts/__tests__/template-part-recommender-helpers.test.js`

- [ ] **Step 1: Add failing JS test for expected target metadata in operation targets**

Add a test that builds a template-part structure with a target block and asserts each executable target includes enough metadata for server prompt examples and parser fingerprints.

```js
test( 'buildEditorTemplatePartStructureSnapshot includes expected target metadata for executable paths', () => {
	const snapshot = buildEditorTemplatePartStructureSnapshot( [
		{
			name: 'core/group',
			attributes: { className: 'header-shell' },
			innerBlocks: [],
		},
	] );

	expect( snapshot.operationTargets[ 0 ] ).toEqual(
		expect.objectContaining( {
			path: [ 0 ],
			name: 'core/group',
			label: 'Group',
			expectedTarget: expect.objectContaining( {
				name: 'core/group',
				childCount: 0,
			} ),
		} )
	);
} );
```

- [ ] **Step 2: Run the JS test and verify it fails**

Run:

```bash
npm run test:unit -- src/template-parts/__tests__/template-part-recommender-helpers.test.js --runInBand
```

Expected: FAIL if `expectedTarget` is missing from `operationTargets`.

- [ ] **Step 3: Add `expectedTarget` to operation targets**

In `collectTemplatePartOperationTargets()`, add:

```js
expectedTarget: {
	name: block.name,
	childCount: getInnerBlocks( block ).length,
	attributes: summarizeTemplatePartBlockAttributes( attributes ),
},
```

Keep the context signature test expectations aligned because `operationTargets` are part of `buildTemplatePartRecommendationContextSignature()`.

- [ ] **Step 4: Run the template-part helper tests**

Run:

```bash
npm run test:unit -- src/template-parts/__tests__/template-part-recommender-helpers.test.js --runInBand
```

Expected: PASS.

### Task 4: M1A Focused Verification

**Files:**
- No code changes unless a check fails.

- [ ] **Step 1: Run focused M1A tests**

Run:

```bash
composer test:php -- --filter TemplatePartPromptTest
npm run test:unit -- src/template-parts/__tests__/template-part-recommender-helpers.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/utils/__tests__/template-actions.test.js --runInBand
```

Expected: PASS.

- [ ] **Step 2: Run docs check if prompt/context docs changed**

Run:

```bash
npm run check:docs
```

Expected: PASS.

## Phase 2: M4 Transactional Block Structural Apply And Undo

### Task 5: Create Structural Snapshot And Operation Preparation Utilities

**Files:**
- Create: `src/utils/block-structural-actions.js`
- Create: `src/utils/__tests__/block-structural-actions.test.js`

- [ ] **Step 1: Add failing tests for live validation**

Create `src/utils/__tests__/block-structural-actions.test.js` with tests for deleted target, stale signature, disabled flag, locked/content-only target, missing pattern, and invalid action.

```js
import {
	prepareBlockStructuralOperation,
} from '../block-structural-actions';

const baseOperation = {
	catalogVersion: 1,
	type: 'insert_pattern',
	patternName: 'theme/hero',
	targetClientId: 'block-1',
	position: 'insert_after',
	targetSignature: 'target-sig',
	expectedTarget: {
		clientId: 'block-1',
		name: 'core/group',
	},
};

const baseContext = {
	enableBlockStructuralActions: true,
	targetClientId: 'block-1',
	targetBlockName: 'core/group',
	targetSignature: 'target-sig',
	allowedPatterns: [
		{
			name: 'theme/hero',
			title: 'Hero',
			allowedActions: [ 'insert_after', 'insert_before', 'replace' ],
		},
	],
};

describe( 'block structural actions', () => {
	test( 'prepareBlockStructuralOperation rejects a missing live target', () => {
		const result = prepareBlockStructuralOperation( {
			operation: baseOperation,
			blockOperationContext: baseContext,
			blockEditorSelect: {
				getBlock: () => null,
			},
		} );

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				code: 'target_missing',
			} )
		);
	} );
} );
```

- [ ] **Step 2: Run the new test and verify it fails**

Run:

```bash
npm run test:unit -- src/utils/__tests__/block-structural-actions.test.js --runInBand
```

Expected: FAIL because `block-structural-actions.js` does not exist.

- [ ] **Step 3: Implement preparation without mutation**

Create `src/utils/block-structural-actions.js` with pure preparation exports:

```js
import { validateBlockOperationSequence } from './block-operation-catalog';

export function getBlockStructuralActionErrorMessage( code = '' ) {
	const messages = {
		target_missing: 'The selected block is no longer available. Refresh recommendations and try again.',
		target_mismatch: 'The selected block no longer matches the reviewed operation. Refresh recommendations and try again.',
		pattern_missing: 'The recommended pattern is no longer available. Refresh recommendations and try again.',
		operation_invalid: 'The structural operation is no longer valid. Refresh recommendations and try again.',
	};

	return messages[ code ] || messages.operation_invalid;
}

export function prepareBlockStructuralOperation( {
	operation,
	blockOperationContext,
	blockEditorSelect,
} ) {
	const liveBlock = blockEditorSelect?.getBlock?.( operation?.targetClientId );

	if ( ! liveBlock?.clientId ) {
		return { ok: false, code: 'target_missing' };
	}

	if (
		operation?.expectedTarget?.name &&
		liveBlock.name !== operation.expectedTarget.name
	) {
		return { ok: false, code: 'target_mismatch' };
	}

	const validation = validateBlockOperationSequence( [ operation ], {
		...blockOperationContext,
		targetBlockName: liveBlock.name,
	} );

	if ( ! validation.ok || validation.operations.length !== 1 ) {
		return { ok: false, code: 'operation_invalid', validation };
	}

	return {
		ok: true,
		operation: validation.operations[ 0 ],
		liveBlock,
	};
}
```

- [ ] **Step 4: Run preparation tests**

Run:

```bash
npm run test:unit -- src/utils/__tests__/block-structural-actions.test.js --runInBand
```

Expected: PASS for preparation tests.

### Task 6: Implement Transactional Block Structural Apply

**Files:**
- Modify: `src/utils/block-structural-actions.js`
- Modify: `src/utils/__tests__/block-structural-actions.test.js`

- [ ] **Step 1: Add failing apply tests**

Add tests for:

- `insert_pattern` after selected block inserts parsed pattern blocks at the correct root/index.
- `insert_pattern` before selected block inserts at the selected block index.
- `replace_block_with_pattern` removes the selected block and inserts parsed pattern blocks at the same index.
- If insertion fails after removal, removed block snapshots are restored.

Use mocked editor selectors/dispatchers patterned after `src/utils/__tests__/template-actions.test.js`.

- [ ] **Step 2: Run apply tests and verify they fail**

Run:

```bash
npm run test:unit -- src/utils/__tests__/block-structural-actions.test.js --runInBand
```

Expected: FAIL because apply exports do not exist.

- [ ] **Step 3: Add apply exports**

Add exports with these signatures:

```js
export function applyBlockStructuralSuggestionOperations( {
	suggestion,
	blockOperationContext,
	blockEditorSelect,
	blockEditorDispatch,
	parsePatternBlocks,
} ) {
	// Returns { ok: true, operations, beforeSignature, afterSignature }
}

export function undoBlockStructuralSuggestionOperations( activity, registry ) {
	// Returns { ok: true } or { ok: false, error }
}
```

Implementation rules:

- Read exactly one executable operation from `suggestion.actionability.executableOperations` or `suggestion.eligibility.executableOperations`.
- Re-run `prepareBlockStructuralOperation()` immediately before mutation.
- Parse pattern blocks from the current pattern registry, not from model payload.
- Capture a pre-apply structural snapshot containing selected block, parent/root clientId, index, target signature, and relevant block snapshots.
- For replace, snapshot the removed target before `removeBlocks()`.
- After mutation, verify the expected inserted slice exists.
- On any failure, call rollback before returning `{ ok: false }`.
- Return recorded operation metadata only after success.

- [ ] **Step 4: Run apply tests**

Run:

```bash
npm run test:unit -- src/utils/__tests__/block-structural-actions.test.js --runInBand
```

Expected: PASS.

### Task 7: Wire M4 Apply Through Store And Inspector UI

**Files:**
- Modify: `src/store/index.js`
- Modify: `src/inspector/BlockRecommendationsPanel.js`
- Modify: `src/inspector/__tests__/BlockRecommendationsPanel.test.js`
- Modify: `src/store/__tests__/store-actions.test.js`

- [ ] **Step 1: Add store tests for structural apply routing**

Add tests proving:

- Inline-safe `applySuggestion()` behavior is unchanged.
- Review-safe structural suggestions use the new structural executor.
- Stale request signature blocks structural apply before mutation.
- Server resolved-context drift blocks structural apply before mutation.
- Activity is logged only after successful structural mutation.

- [ ] **Step 2: Run store tests and verify they fail**

Run:

```bash
npm run test:unit -- src/store/__tests__/store-actions.test.js --runInBand
```

Expected: FAIL for missing structural apply thunk/UI dispatch path.

- [ ] **Step 3: Add a separate structural apply thunk**

Prefer a separate action over extending inline apply:

```js
applyBlockStructuralSuggestion(
	clientId,
	suggestion,
	currentRequestSignature = null,
	liveRequestInput = null
) {
	return async ( { dispatch: localDispatch, registry, select } ) => {
		// Same freshness guards as applySuggestion().
		// Set block apply state to applying.
		// Execute applyBlockStructuralSuggestionOperations().
		// Log structural activity only on success.
	};
}
```

Keep `applySuggestion()` scoped to inline-safe attribute updates. This preserves the current one-click apply contract.

- [ ] **Step 4: Add inspector confirm/apply control**

Inside the active review details in `BlockRecommendationsPanel.js`, render an M4 apply button only when all are true:

- result is not stale,
- review state is current,
- feature flag is enabled,
- suggestion is review-safe,
- structural apply thunk exists.

Use copy that clearly distinguishes it from one-click inline apply, for example `Apply reviewed structure`.

- [ ] **Step 5: Run panel and store tests**

Run:

```bash
npm run test:unit -- src/inspector/__tests__/BlockRecommendationsPanel.test.js src/store/__tests__/store-actions.test.js --runInBand
```

Expected: PASS.

### Task 8: Add Structural Activity And Undo Semantics

**Files:**
- Modify: `src/store/activity-undo.js`
- Modify: `src/store/activity-history.js` only if normalization needs a schema addition.
- Modify: `src/store/__tests__/activity-history.test.js`
- Modify: `src/store/__tests__/store-actions.test.js`
- Modify: `src/admin/__tests__/activity-log-utils.test.js` if labels change.

- [ ] **Step 1: Add failing undo-state tests**

Cover:

- structural block activity is undoable when live post-apply signature matches,
- undo is disabled when user edits after apply,
- undo restores inserted/replaced block snapshots,
- inline block attribute path drift remains diagnostic-only under the accepted contract.

- [ ] **Step 2: Run undo tests and verify they fail**

Run:

```bash
npm run test:unit -- src/store/__tests__/activity-history.test.js src/store/__tests__/store-actions.test.js --runInBand
```

Expected: FAIL until structural undo routing exists.

- [ ] **Step 3: Add structural activity builder**

Extend `buildBlockActivityEntry()` or add `buildBlockStructuralActivityEntry()` with:

```js
{
	type: 'apply_block_structural_suggestion',
	surface: 'block',
	target: {
		clientId,
		blockName,
		blockPath,
	},
	before: {
		structuralSignature,
		operations: buildDocumentOperationBeforeState( operations ),
	},
	after: {
		structuralSignature,
		operations,
	},
}
```

Do not change existing `apply_suggestion` entries for inline-safe attributes.

- [ ] **Step 4: Route structural undo**

In `undoBlockActivity()`, branch by activity type:

- `apply_suggestion`: keep current attribute undo behavior.
- `apply_block_structural_suggestion`: call `undoBlockStructuralSuggestionOperations()`.

Require the current live structural signature to match `activity.after.structuralSignature` before undo. Return manual recovery guidance when it does not.

- [ ] **Step 5: Run undo tests**

Run:

```bash
npm run test:unit -- src/store/__tests__/activity-history.test.js src/store/__tests__/store-actions.test.js src/utils/__tests__/block-structural-actions.test.js --runInBand
```

Expected: PASS.

### Task 9: Cover M4 Negative Cases And Browser Evidence

**Files:**
- Modify: `src/utils/__tests__/block-structural-actions.test.js`
- Modify: `tests/e2e/flavor-agent.smoke.spec.js`
- Modify docs after evidence is known.

- [ ] **Step 1: Add unit negative tests required by the canonical plan**

Add or confirm tests for:

- deleted selected block,
- moved target into locked parent,
- target lock transition,
- content-only transition,
- pattern disappearance,
- pattern action invalidation,
- user edits before undo,
- partial failure rollback.

- [ ] **Step 2: Add one Playwright happy path**

In `tests/e2e/flavor-agent.smoke.spec.js`, add a flag-enabled scenario that:

- selects a block,
- serves a review-safe structural recommendation,
- opens `Review first`,
- applies reviewed structure,
- asserts the expected pattern blocks appear,
- asserts the activity row identifies structural apply,
- undoes when no drift occurred.

- [ ] **Step 3: Add one Playwright stale/blocked path or record waiver**

Preferred smoke:

- open review,
- mutate/delete/lock the target before apply,
- assert apply is blocked and no structural mutation occurs.

If the browser harness cannot reliably model the lock/content-only transition, record a waiver in the final implementation notes and keep unit coverage.

### Task 10: M4 Docs And Release Validation

**Files:**
- Modify: `docs/features/block-recommendations.md`
- Modify: `docs/reference/recommendation-ui-consistency.md`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`
- Modify: `docs/reference/abilities-and-routes.md` only if response contract changes.

- [ ] **Step 1: Update feature docs after code behavior is stable**

Document:

- `Apply now` remains inline-safe local attributes.
- `Review first` can apply only validator-approved selected-block structural operations when the flag is enabled.
- Structural activity and undo are drift-checked.
- Native inserter usage remains owned by Gutenberg.

- [ ] **Step 2: Run full non-E2E verifier**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: `VERIFY_RESULT` status is `pass`, or blockers are recorded.

- [ ] **Step 3: Run browser evidence**

Run:

```bash
npm run test:e2e:playground
```

Expected: PASS for block/pattern post-editor behavior.

Run only if Site Editor/template/style surfaces were touched:

```bash
npm run test:e2e:wp70
```

Expected: PASS or explicit waiver with blocker details.

## Final Acceptance Checklist

- [ ] M1A produces more valid template-part `operations[]` without broadening the operation vocabulary.
- [ ] Invalid or ambiguous template-part `patternSuggestions` remain advisory.
- [ ] Existing template-part review/apply/undo behavior remains unchanged.
- [ ] M4 applies only one validator-approved selected-block structural operation per review.
- [ ] M4 revalidates live target, locks, pattern availability, feature flag, and operation validity immediately before mutation.
- [ ] M4 structural mutation is transactional and rolls back on failure.
- [ ] M4 activity is recorded only after successful apply and distinguishes structural block applies from inline attribute applies.
- [ ] M4 undo is disabled on post-apply live-state divergence.
- [ ] Required race and negative tests are green.
- [ ] Docs and feature matrix reflect final behavior.
- [ ] `npm run verify -- --skip-e2e` passes or blockers are recorded.
- [ ] Playwright evidence exists or explicit waivers are documented.
