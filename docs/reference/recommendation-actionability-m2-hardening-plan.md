# Recommendation Actionability M2 Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Workstream D / M2 safe to implement by turning block structural operations into validator-owned proposals, not model-owned authority.

**Architecture:** Add a PHP block operation validator as the authoritative server-side gate, mirror the same catalog in JS for client diagnostics, and keep the normalized recommendation payload explicit about proposed, executable, and rejected operations. M2 stops before review UI and mutation: it prepares a trustworthy `operations[]` contract for M3/M4.

**Tech Stack:** WordPress plugin requiring PHP 8.0+, current host PHP 8.5 test runtime, PHPUnit, `@wordpress/scripts` Jest, existing Gutenberg block editor context, Flavor Agent block prompt/schema/parser pipeline.

---

## Source And Remaining Scope

Source plan: `docs/reference/recommendation-actionability-implementation-plan.md`.

This plan extracts the remaining M2 work after the current branch's M0/M1 foundation:

- `RecommendationEligibility` metadata and the JS block operation catalog already exist.
- The default-off block structural actions flag already flows to JS.
- Rollout-gated allowed-pattern context already reaches the block prompt path.
- The remaining M2 gap is the `operations[]` response contract, parser enforcement, PHP validator, JS diagnostic mirror updates, normalized operation metadata, docs, and focused verification.

This plan intentionally does not implement Workstream E/M3 review UI, Workstream F/M4 structural mutation or undo, template-part yield improvements, or later backlog items from the source implementation plan.

## Findings Covered

1. The M2 response shape mixes `title`/`rationale` examples with the current `label`/`description` block parser contract.
2. JS currently has a block operation catalog, but PHP must be the first authoritative validator before REST returns payloads.
3. `blockOperationContext` is nested in request context, while the JS validator consumes a flatter operation-validation context.
4. Multiple valid structural operations could be promoted to `review-safe` before the transaction engine exists.
5. Prompt and schema changes must stay flag-aware so disabled environments request and accept only `operations: []`.
6. Server-side stale-target checks must be distinguished from later live-editor drift checks.
7. Mixed suggestions need separate handling so rejected structural proposals do not invalidate safe local attribute updates.

## Target Contract

Use the existing block suggestion item shape and add required `operations: []` to block-lane items. Do not introduce `title` or `rationale` for block recommendations in M2.

Model-facing block suggestion item:

```json
{
  "label": "Add a hero pattern after this group",
  "description": "The selected area has room for a stronger CTA.",
  "type": "pattern_replacement",
  "attributeUpdates": {},
  "panel": null,
  "operations": [
    {
      "type": "insert_pattern",
      "patternName": "theme/hero",
      "targetClientId": "selected-block-client-id",
      "position": "insert_after",
      "targetSignature": "optional-recommendation-time-signature",
      "targetSurface": "block",
      "targetType": "block"
    }
  ]
}
```

Normalized server response item:

```json
{
  "label": "Add a hero pattern after this group",
  "description": "The selected area has room for a stronger CTA.",
  "type": "pattern_replacement",
  "attributeUpdates": {},
  "panel": null,
  "operations": [],
  "proposedOperations": [
    {
      "type": "insert_pattern",
      "patternName": "theme/hero",
      "targetClientId": "selected-block-client-id",
      "position": "insert_after"
    }
  ],
  "rejectedOperations": [
    {
      "code": "block_structural_actions_disabled",
      "message": "Block structural actions are disabled for this environment.",
      "operation": {
        "type": "insert_pattern",
        "patternName": "theme/hero",
        "targetClientId": "selected-block-client-id",
        "position": "insert_after"
      }
    }
  ]
}
```

Rules:

- `operations` in normalized payload means executable, validator-approved structural operations.
- `proposedOperations` is sanitized model input retained for diagnostics.
- `rejectedOperations` is sanitized validator rejection metadata.
- Multiple proposed structural operations in one suggestion produce no executable structural operations in M2; all proposed operations are rejected with a multi-operation reason.
- Valid `attributeUpdates` remain independently executable as `inline-safe` even when structural operations are rejected.

## File Map

- Create `inc/Context/BlockOperationValidator.php`
  - PHP source of truth for catalog version, operation constants, allowed action validation, context adapter, and rejection metadata.
- Modify `inc/LLM/ResponseSchema.php`
  - Add `operations` to block-lane suggestion schema using nullable fields plus parser-enforced required fields by operation type.
- Modify `inc/LLM/Prompt.php`
  - Prompt for `operations: []` on every block-lane item.
  - When the rollout flag is enabled and allowed patterns exist, describe the two catalog operations.
  - Parse raw block `operations`, then call `BlockOperationValidator`.
- Modify `inc/Abilities/BlockAbilities.php`
  - Pass normalized `blockOperationContext` and flag state into the parser/enforcement path.
- Modify `src/utils/block-operation-catalog.js`
  - Add a nested-context adapter from `blockContext.blockOperationContext` to validator context.
  - Add a multi-operation rejection that mirrors PHP.
- Modify `src/store/update-helpers.js`
  - Preserve `operations`, `proposedOperations`, and `rejectedOperations`.
  - Feed validated operation results into actionability without letting rejected operations erase safe attribute updates.
- Modify `src/utils/recommendation-actionability.js`
  - Map validator rejection codes to specific blocker reasons.
- Tests:
  - Create `tests/phpunit/BlockOperationValidatorTest.php`.
  - Update `tests/phpunit/PromptFormattingTest.php`.
  - Update `tests/phpunit/RegistrationTest.php`.
  - Update `src/utils/__tests__/block-operation-catalog.test.js`.
  - Update `src/store/update-helpers.test.js`.
  - Update `src/utils/__tests__/recommendation-actionability.test.js`.
- Docs:
  - Update `docs/reference/recommendation-actionability-implementation-plan.md`.
  - Update `docs/reference/abilities-and-routes.md`.
  - Update `docs/features/block-recommendations.md`.
  - Update `docs/reference/shared-internals.md`.

## Task 1: Lock The Block Response Shape

**Files:**
- Modify: `inc/LLM/ResponseSchema.php`
- Modify: `tests/phpunit/RegistrationTest.php`

- [ ] **Step 1: Add failing schema coverage**

Add assertions to the block ability schema test proving block-lane items expose `operations` as an array and that each operation object has the full nullable parser-input shape.

Expected asserted schema properties:

```php
$block_ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-block'] ?? [];
$suggestion    = $block_ability['output_schema']['properties']['block']['items'] ?? [];
$operation     = $suggestion['properties']['operations']['items'] ?? [];

$this->assertSame( 'array', $suggestion['properties']['operations']['type'] ?? null );
$this->assertSame(
	[ 'insert_pattern', 'replace_block_with_pattern', null ],
	$operation['properties']['type']['enum'] ?? null
);
$this->assertSame( [ 'string', 'null' ], $operation['properties']['patternName']['type'] ?? null );
$this->assertSame( [ 'string', 'null' ], $operation['properties']['targetClientId']['type'] ?? null );
$this->assertSame( [ 'string', 'null' ], $operation['properties']['position']['type'] ?? null );
$this->assertSame( [ 'string', 'null' ], $operation['properties']['targetSignature']['type'] ?? null );
$this->assertSame( [ 'string', 'null' ], $operation['properties']['targetSurface']['type'] ?? null );
$this->assertSame( [ 'string', 'null' ], $operation['properties']['targetType']['type'] ?? null );
```

- [ ] **Step 2: Run the schema test and confirm failure**

Run:

```bash
vendor/bin/phpunit tests/phpunit/RegistrationTest.php
```

Expected: failure because block-lane item schema does not define `operations`.

- [ ] **Step 3: Add the block operation schema**

In `ResponseSchema::block_block_item_schema()`, add:

```php
'operations' => [
	'type'  => 'array',
	'items' => self::block_operation_schema(),
],
```

Add:

```php
private static function block_operation_schema(): array {
	return self::strict_object(
		[
			'type'            => [
				'type' => [ 'string', 'null' ],
				'enum' => [ 'insert_pattern', 'replace_block_with_pattern', null ],
			],
			'patternName'     => self::nullable_string(),
			'targetClientId'  => self::nullable_string(),
			'position'        => self::nullable_string(),
			'targetSignature' => self::nullable_string(),
			'targetSurface'   => self::nullable_string(),
			'targetType'      => self::nullable_string(),
		]
	);
}
```

- [ ] **Step 4: Run schema test and PHP lint**

Run:

```bash
vendor/bin/phpunit tests/phpunit/RegistrationTest.php
vendor/bin/phpcs inc/LLM/ResponseSchema.php tests/phpunit/RegistrationTest.php
```

Expected: pass.

## Task 2: Add The Authoritative PHP Validator

**Files:**
- Create: `inc/Context/BlockOperationValidator.php`
- Create: `tests/phpunit/BlockOperationValidatorTest.php`

- [ ] **Step 1: Add failing validator tests**

Create PHPUnit coverage for:

- Valid `insert_pattern` with `insert_after`.
- Valid `replace_block_with_pattern`.
- Disabled feature flag rejects all operations.
- Unknown operation type rejected.
- Unknown pattern name rejected.
- Target client mismatch rejected as stale.
- Target signature mismatch rejected as stale.
- Cross-surface target rejected.
- Locked target rejected.
- Content-only target rejected.
- Invalid insertion position rejected.
- Multiple proposed operations rejected in M2.

Representative test:

```php
public function test_valid_insert_pattern_returns_single_executable_operation(): void {
	$context = [
		'enableBlockStructuralActions' => true,
		'targetClientId'               => 'block-1',
		'targetBlockName'              => 'core/group',
		'targetSignature'              => 'target-sig',
		'allowedPatterns'              => [
			[
				'name'           => 'theme/hero',
				'allowedActions' => [ 'insert_after', 'replace' ],
			],
		],
	];

	$result = BlockOperationValidator::validate_sequence(
		[
			[
				'type'           => 'insert_pattern',
				'patternName'    => 'theme/hero',
				'targetClientId' => 'block-1',
				'position'       => 'insert_after',
			],
		],
		$context
	);

	$this->assertTrue( $result['ok'] );
	$this->assertSame( 'insert_pattern', $result['operations'][0]['type'] );
	$this->assertSame( 'target-sig', $result['operations'][0]['targetSignature'] );
	$this->assertSame( [], $result['rejectedOperations'] );
}
```

- [ ] **Step 2: Run validator tests and confirm failure**

Run:

```bash
vendor/bin/phpunit tests/phpunit/BlockOperationValidatorTest.php
```

Expected: class not found.

- [ ] **Step 3: Implement validator constants and context adapter**

Create `FlavorAgent\Context\BlockOperationValidator` with:

```php
final class BlockOperationValidator {
	public const CATALOG_VERSION = 1;
	public const INSERT_PATTERN = 'insert_pattern';
	public const REPLACE_BLOCK_WITH_PATTERN = 'replace_block_with_pattern';
	public const ACTION_INSERT_BEFORE = 'insert_before';
	public const ACTION_INSERT_AFTER = 'insert_after';
	public const ACTION_REPLACE = 'replace';
	public const ERROR_STRUCTURAL_ACTIONS_DISABLED = 'block_structural_actions_disabled';
	public const ERROR_MULTI_OPERATION_UNSUPPORTED = 'multi_operation_unsupported';
	public const ERROR_UNKNOWN_OPERATION_TYPE = 'unknown_operation_type';
	public const ERROR_PATTERN_NOT_AVAILABLE = 'pattern_not_available';
	public const ERROR_STALE_TARGET = 'stale_target';
	public const ERROR_CROSS_SURFACE_TARGET = 'cross_surface_target';
	public const ERROR_LOCKED_TARGET = 'locked_target';
	public const ERROR_CONTENT_ONLY_TARGET = 'content_only_target';
	public const ERROR_INVALID_INSERTION_POSITION = 'invalid_insertion_position';
	public const ERROR_ACTION_NOT_ALLOWED = 'action_not_allowed';
}
```

Add `normalize_context( array $block_operation_context, bool $enabled ): array` returning the flat shape used by both PHP and JS:

```php
[
	'enableBlockStructuralActions' => $enabled,
	'targetClientId'               => 'block-1',
	'targetBlockName'              => 'core/group',
	'targetSignature'              => 'target-sig',
	'isTargetLocked'               => false,
	'isContentOnly'                => false,
	'editingMode'                  => 'default',
	'allowedPatterns'              => [],
]
```

- [ ] **Step 4: Implement sequence validation**

Validation behavior:

- Empty operations: return `ok: false`, no executable operations, no rejection unless the caller wants diagnostics.
- Disabled flag: reject every proposed operation with `block_structural_actions_disabled`.
- More than one proposed operation: reject every proposed operation with `multi_operation_unsupported`.
- Unknown type: reject with `unknown_operation_type`.
- Missing or unknown pattern: reject with `pattern_not_available` or missing-pattern code.
- `targetClientId` must match context target.
- Optional operation `targetSignature` must match context signature when provided.
- `targetSurface` defaults to `block`; any non-`block` value is rejected.
- `targetType` defaults to `block`; any non-`block` value is rejected.
- Locked or content-only context rejects.
- Insert positions must be `insert_before` or `insert_after`.
- Replace requires the pattern allowed action `replace`.

Normalized executable operation:

```php
[
	'catalogVersion'  => 1,
	'type'            => 'insert_pattern',
	'patternName'     => 'theme/hero',
	'targetClientId'  => 'block-1',
	'targetType'      => 'block',
	'targetSignature' => 'target-sig',
	'position'        => 'insert_after',
	'expectedTarget'  => [
		'clientId' => 'block-1',
		'name'     => 'core/group',
	],
]
```

- [ ] **Step 5: Run validator tests and PHPCS**

Run:

```bash
vendor/bin/phpunit tests/phpunit/BlockOperationValidatorTest.php
vendor/bin/phpcs inc/Context/BlockOperationValidator.php tests/phpunit/BlockOperationValidatorTest.php
```

Expected: pass.

## Task 3: Integrate Parser And Prompt Without Making Proposals Authoritative

**Files:**
- Modify: `inc/LLM/Prompt.php`
- Modify: `inc/Abilities/BlockAbilities.php`
- Modify: `tests/phpunit/PromptFormattingTest.php`
- Modify or create: `tests/phpunit/BlockOperationContextTest.php`

- [ ] **Step 1: Add failing parser tests**

Add tests proving:

- `Prompt::parse_response()` preserves sanitized `proposedOperations`.
- Server enforcement turns valid proposals into executable `operations`.
- Disabled flag returns empty executable `operations` and populated `rejectedOperations`.
- Mixed suggestions keep valid `attributeUpdates` even when structural operation is rejected.
- Advisory-only `structural_recommendation` and `pattern_replacement` still cannot carry attribute updates.

Representative assertion:

```php
$this->assertSame(
	[ 'content' => 'Get started' ],
	$result['block'][0]['attributeUpdates']
);
$this->assertSame( [], $result['block'][0]['operations'] );
$this->assertSame(
	'pattern_not_available',
	$result['block'][0]['rejectedOperations'][0]['code'] ?? null
);
```

- [ ] **Step 2: Update prompt instructions**

In `Prompt::build_system()`, add block-lane instructions:

```text
Every block-lane item must include operations. Use [] for ordinary attribute-only recommendations.
When allowed block pattern actions are provided, you may propose at most one operation from the catalog.
Use insert_pattern only with position insert_before or insert_after.
Use replace_block_with_pattern only when the allowed pattern lists replace.
Never treat operations as authorization; the plugin validator may reject them.
When structural actions are not described in the prompt, return operations: [].
```

- [ ] **Step 3: Update allowed-pattern prompt section**

Keep the current `## Allowed block pattern actions` section, but append the exact operation catalog only when allowed patterns are non-empty:

```text
Catalog:
- insert_pattern: patternName, targetClientId, position insert_before|insert_after.
- replace_block_with_pattern: patternName, targetClientId.
Return at most one operation per block suggestion.
Use only targetClientId and patternName values shown above.
```

When allowed patterns are empty:

```text
Allowed patterns: none for this target. Return operations: [].
```

- [ ] **Step 4: Parse raw operations separately from executable operations**

In `validate_suggestions()`, normalize raw `operations` to sanitized objects and set:

```php
'operations'         => [],
'proposedOperations' => $proposed_operations,
'rejectedOperations' => [],
```

Do not mark proposed operations executable inside `parse_response()`.

- [ ] **Step 5: Enforce operations with context**

Change the block enforcement path to accept block operation context:

```php
$payload = Prompt::enforce_block_context_rules(
	$payload,
	$context['block'] ?? [],
	$execution_contract,
	$context['blockOperationContext'] ?? []
);
```

Inside enforcement, call:

```php
$validation = BlockOperationValidator::validate_sequence(
	$suggestion['proposedOperations'] ?? [],
	BlockOperationValidator::normalize_context(
		$block_operation_context,
		function_exists( '\\flavor_agent_block_structural_actions_enabled' )
			&& \flavor_agent_block_structural_actions_enabled()
	)
);

$suggestion['operations']         = $validation['operations'];
$suggestion['rejectedOperations'] = $validation['rejectedOperations'];
```

- [ ] **Step 6: Run PHP parser/prompt tests**

Run:

```bash
vendor/bin/phpunit tests/phpunit/PromptFormattingTest.php tests/phpunit/BlockOperationContextTest.php tests/phpunit/BlockOperationValidatorTest.php
vendor/bin/phpcs inc/LLM/Prompt.php inc/Abilities/BlockAbilities.php
```

Expected: pass.

## Task 4: Mirror Validation In JS Without Promoting Too Early

**Files:**
- Modify: `src/utils/block-operation-catalog.js`
- Modify: `src/utils/__tests__/block-operation-catalog.test.js`

- [ ] **Step 1: Add failing JS catalog tests**

Add tests matching PHP fixtures:

- Nested `blockOperationContext` adapts to flat validator context.
- Multiple proposed operations are rejected.
- Disabled flag rejects all.
- Missing pattern context rejects all.
- Valid insert/replace outputs exactly one normalized operation.

Representative test:

```js
const validation = validateBlockOperationSequence(
	[
		{
			type: 'insert_pattern',
			patternName: 'theme/hero',
			targetClientId: 'block-1',
			position: 'insert_after',
		},
	],
	buildBlockOperationValidationContext( {
		blockOperationContext: {
			targetClientId: 'block-1',
			targetBlockName: 'core/group',
			targetSignature: 'target-sig',
			allowedPatterns: [
				{
					name: 'theme/hero',
					allowedActions: [ 'insert_after' ],
				},
			],
		},
		enableBlockStructuralActions: true,
	} )
);

expect( validation.operations ).toEqual( [
	expect.objectContaining( {
		type: 'insert_pattern',
		targetSignature: 'target-sig',
	} ),
] );
```

- [ ] **Step 2: Implement adapter and multi-operation cap**

Add:

```js
export function buildBlockOperationValidationContext( blockContext = {} ) {
	const operationContext = blockContext.blockOperationContext || blockContext;
	return {
		enableBlockStructuralActions:
			blockContext.enableBlockStructuralActions === true ||
			isBlockStructuralActionsEnabled(),
		targetClientId: operationContext.targetClientId || '',
		targetBlockName: operationContext.targetBlockName || '',
		targetSignature: operationContext.targetSignature || '',
		allowedPatterns: operationContext.allowedPatterns || [],
		isTargetLocked: operationContext.isTargetLocked === true,
		isContentOnly:
			operationContext.isContentOnly === true ||
			operationContext.editingMode === 'contentOnly',
		editingMode: operationContext.editingMode || 'default',
	};
}
```

In `validateBlockOperationSequence()`, reject `rawOperations.length > 1` with `multi_operation_unsupported`.

- [ ] **Step 3: Run JS catalog tests**

Run:

```bash
npm run test:unit -- src/utils/__tests__/block-operation-catalog.test.js --runInBand
npx wp-scripts lint-js src/utils/block-operation-catalog.js src/utils/__tests__/block-operation-catalog.test.js
```

Expected: pass.

## Task 5: Preserve Mixed Recommendation Semantics In Client Normalization

**Files:**
- Modify: `src/store/update-helpers.js`
- Modify: `src/store/update-helpers.test.js`
- Modify: `src/utils/recommendation-actionability.js`
- Modify: `src/utils/__tests__/recommendation-actionability.test.js`

- [ ] **Step 1: Add failing client normalization tests**

Cover:

- Inline-safe attribute update survives rejected structural operation.
- Valid structural operation becomes `review-safe` only when there are no inline-safe updates and exactly one validator-approved operation.
- Multiple proposed operations remain advisory/rejected.
- Rejection codes map to blocker reasons:
  - `pattern_not_available` -> `pattern-not-available`
  - `stale_target` -> `target-stale`
  - `locked_target` -> `locked-target`
  - `content_only_target` -> `locked-target`
  - `cross_surface_target` -> `multi-target-structural-change`
  - `multi_operation_unsupported` -> `multi-target-structural-change`

- [ ] **Step 2: Preserve operation metadata in sanitization**

When normalizing block suggestions, preserve:

```js
operations: Array.isArray( suggestion.operations ) ? suggestion.operations : [],
proposedOperations: Array.isArray( suggestion.proposedOperations )
	? suggestion.proposedOperations
	: Array.isArray( suggestion.operations )
		? suggestion.operations
		: [],
rejectedOperations: Array.isArray( suggestion.rejectedOperations )
	? suggestion.rejectedOperations
	: [],
```

If the server already returned executable `operations`, do not re-authorize them blindly. Re-run JS validation for diagnostics using `buildBlockOperationValidationContext( blockContext )`, then intersect by serialized normalized operation identity.

- [ ] **Step 3: Keep inline-safe precedence explicit**

Actionability resolution:

```js
if ( hasAllowedUpdates ) {
	return inlineSafeActionabilityWithRejectedOperationsPreserved;
}

if ( validatedOperations.length === 1 ) {
	return reviewSafeActionability;
}

return advisoryActionabilityWithRejectedOperations;
```

Do not allow structural operations to convert an attribute update into review-safe. Attribute apply remains one-click and local; structural operations wait for M3 review state.

- [ ] **Step 4: Run focused JS tests**

Run:

```bash
npm run test:unit -- src/store/update-helpers.test.js src/utils/__tests__/recommendation-actionability.test.js --runInBand
npx wp-scripts lint-js src/store/update-helpers.js src/store/update-helpers.test.js src/utils/recommendation-actionability.js src/utils/__tests__/recommendation-actionability.test.js
```

Expected: pass.

## Task 6: Update Docs And Canonical Milestone Status

**Files:**
- Modify: `docs/reference/recommendation-actionability-implementation-plan.md`
- Modify: `docs/reference/abilities-and-routes.md`
- Modify: `docs/features/block-recommendations.md`
- Modify: `docs/reference/shared-internals.md`

- [ ] **Step 1: Update canonical M2 language**

Record that M2 defines:

- existing block item shape plus `operations: []`
- PHP validator as source of truth
- JS validator as diagnostic mirror
- no review UI
- no structural mutation
- multiple structural operations rejected until M4 transaction support exists

- [ ] **Step 2: Update REST/Abilities contract docs**

Document normalized block suggestion operation fields:

```ts
type NormalizedBlockSuggestion = {
  label: string;
  description: string;
  attributeUpdates: Record<string, unknown>;
  operations: BlockOperation[];
  proposedOperations: BlockOperationProposal[];
  rejectedOperations: BlockOperationRejection[];
};
```

Document that `operations` is executable only after validator approval and still requires review/apply gates in later milestones.

- [ ] **Step 3: Update feature docs**

Clarify user-facing behavior:

- structural operation ideas may appear only as review-safe metadata behind the flag
- no structural apply UI exists in M2
- inline-safe local attribute suggestions remain unchanged

- [ ] **Step 4: Run docs check**

Run:

```bash
npm run check:docs
```

Expected: pass.

## Task 7: Final Verification Gate

**Files:**
- No new files; verify the full M2 patch set.

- [ ] **Step 1: Run focused PHP tests**

Run:

```bash
vendor/bin/phpunit tests/phpunit/BlockOperationValidatorTest.php tests/phpunit/BlockOperationContextTest.php tests/phpunit/PromptFormattingTest.php tests/phpunit/RegistrationTest.php
```

Expected: pass.

- [ ] **Step 2: Run focused JS tests**

Run:

```bash
npm run test:unit -- src/utils/__tests__/block-operation-catalog.test.js src/store/update-helpers.test.js src/utils/__tests__/recommendation-actionability.test.js --runInBand
```

Expected: pass.

- [ ] **Step 3: Run lint and docs checks**

Run:

```bash
vendor/bin/phpcs inc/Context/BlockOperationValidator.php inc/LLM/Prompt.php inc/LLM/ResponseSchema.php inc/Abilities/BlockAbilities.php tests/phpunit/BlockOperationValidatorTest.php
npx wp-scripts lint-js src/utils/block-operation-catalog.js src/store/update-helpers.js src/utils/recommendation-actionability.js
npm run check:docs
git diff --check
```

Expected: pass.

- [ ] **Step 4: Run aggregate local verifier without E2E**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: `VERIFY_RESULT` with `"status":"pass"`, with E2E skipped by flag.

## Acceptance Criteria

- The block response schema supports `operations[]` without changing the existing `label`/`description` item contract.
- PHP validates every structural proposal before REST returns the normalized payload.
- JS validation mirrors PHP and has matching fixtures for catalog decisions.
- Disabled rollout flag keeps executable structural operations empty.
- Missing or invalid pattern context keeps executable structural operations empty.
- Multiple structural proposals in one block suggestion are rejected in M2.
- Server target validation is recommendation-time only; live editor drift is explicitly left to M3/M4.
- Safe local `attributeUpdates` survive rejected structural proposals.
- Normalized payload separates `operations`, `proposedOperations`, and `rejectedOperations`.
- No structural review UI or structural mutation is introduced by M2.
