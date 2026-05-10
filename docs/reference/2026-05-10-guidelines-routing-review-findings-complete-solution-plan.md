# Guidelines Routing Review Findings Complete Solution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore complete guideline category coverage after moving guideline injection out of prompt builders, and update docs/tests so the runtime contract is explicit and protected.

**Architecture:** Keep guideline injection centralized in `RecommendationAbilityExecution`, which prepends formatted guidelines to the recommendation system instruction for Ability executions. Fix the regression by making each recommendation ability declare the guideline categories it previously received through direct prompt-builder calls, then document that new routing path instead of reintroducing duplicate prompt-builder injection.

**Tech Stack:** WordPress plugin PHP, WordPress AI Abilities API, PHPUnit, markdown docs.

---

## Review Findings Covered

- Image guidelines are filtered out because `PromptGuidelinesFormatter::format()` honors the provided category allow-list, while every recommendation ability omits `images` from `GUIDELINE_CATEGORIES`.
- `docs/features/settings-backends-and-sync.md` still says prompt builders call `Guidelines::format_prompt_context()`, but the current code routes guideline context through `RecommendationAbilityExecution` and the `flavor_agent_recommendation_system_instruction` filter.

## File Responsibility Map

- `inc/AI/Abilities/RecommendBlockAbility.php`: Declares guideline categories for block recommendations.
- `inc/AI/Abilities/RecommendContentAbility.php`: Declares guideline categories for content recommendations.
- `inc/AI/Abilities/RecommendPatternsAbility.php`: Declares guideline categories for pattern recommendations.
- `inc/AI/Abilities/RecommendNavigationAbility.php`: Declares guideline categories for navigation recommendations.
- `inc/AI/Abilities/RecommendStyleAbility.php`: Declares guideline categories for style recommendations.
- `inc/AI/Abilities/RecommendTemplateAbility.php`: Declares guideline categories for template recommendations.
- `inc/AI/Abilities/RecommendTemplatePartAbility.php`: Declares guideline categories for template-part recommendations.
- `tests/phpunit/RegistrationTest.php`: Protects ability-level guideline category routing.
- `tests/phpunit/RecommendationAbilityExecutionTest.php`: Protects the runtime system-instruction prepend path.
- `docs/features/settings-backends-and-sync.md`: Documents the current guideline bridge flow.

---

### Task 1: Add failing tests for full guideline category routing

**Files:**
- Modify: `tests/phpunit/RegistrationTest.php`
- Modify: `tests/phpunit/RecommendationAbilityExecutionTest.php`

- [ ] **Step 1: Update the existing block guideline category test expectation**

In `tests/phpunit/RegistrationTest.php`, update `test_recommendation_ability_system_instruction_uses_declared_guideline_categories()` so the expected category list includes `images`:

```php
$this->assertSame(
	[
		[
			'categories' => [ 'site', 'copy', 'images', 'additional' ],
			'blockName'  => 'core/paragraph',
		],
	],
	WordPressTestState::$wpai_guideline_calls
);
```

- [ ] **Step 2: Update the selected-block alias test expectation**

In `tests/phpunit/RegistrationTest.php`, update `test_recommendation_ability_guideline_context_accepts_selected_block_alias()` so the expected category list also includes `images`:

```php
$this->assertSame(
	[
		[
			'categories' => [ 'site', 'copy', 'images', 'additional' ],
			'blockName'  => 'core/paragraph',
		],
	],
	WordPressTestState::$wpai_guideline_calls
);
```

- [ ] **Step 3: Add a cross-surface category contract test**

In `tests/phpunit/RegistrationTest.php`, add this method after `test_recommendation_ability_guideline_context_accepts_selected_block_alias()`:

```php
public function test_recommendation_abilities_include_image_guidelines_in_declared_categories(): void {
	Registration::register_category();
	Registration::register_recommendation_abilities();

	$cases = [
		'flavor-agent/recommend-block'         => [
			'input'      => [
				'selectedBlock'        => [
					'blockName'  => 'core/image',
					'attributes' => [],
				],
				'resolveSignatureOnly' => true,
			],
			'blockName'  => 'core/image',
			'categories' => [ 'site', 'copy', 'images', 'additional' ],
		],
		'flavor-agent/recommend-content'       => [
			'input'      => [
				'postContext'          => [
					'postType' => 'post',
				],
				'resolveSignatureOnly' => true,
			],
			'blockName'  => '',
			'categories' => [ 'site', 'copy', 'images', 'additional' ],
		],
		'flavor-agent/recommend-patterns'      => [
			'input'      => [
				'postType'             => 'page',
				'resolveSignatureOnly' => true,
			],
			'blockName'  => '',
			'categories' => [ 'site', 'images', 'additional' ],
		],
		'flavor-agent/recommend-navigation'    => [
			'input'      => [
				'navigationMarkup'     => '<!-- wp:navigation --><!-- /wp:navigation -->',
				'resolveSignatureOnly' => true,
			],
			'blockName'  => '',
			'categories' => [ 'site', 'copy', 'images', 'additional' ],
		],
		'flavor-agent/recommend-style'         => [
			'input'      => [
				'scope'                => [
					'surface' => 'global-styles',
				],
				'resolveSignatureOnly' => true,
			],
			'blockName'  => '',
			'categories' => [ 'site', 'images', 'additional' ],
		],
		'flavor-agent/recommend-template'      => [
			'input'      => [
				'document'             => [
					'entityId' => 77,
				],
				'resolveSignatureOnly' => true,
			],
			'blockName'  => '',
			'categories' => [ 'site', 'copy', 'images', 'additional' ],
		],
		'flavor-agent/recommend-template-part' => [
			'input'      => [
				'document'             => [
					'entityId' => 77,
				],
				'resolveSignatureOnly' => true,
			],
			'blockName'  => '',
			'categories' => [ 'site', 'copy', 'images', 'additional' ],
		],
	];

	foreach ( $cases as $ability_id => $case ) {
		WordPressTestState::$wpai_formatted_guidelines = '<guidelines>Respect imagery.</guidelines>';
		WordPressTestState::$wpai_guideline_calls      = [];

		$ability = WordPressTestState::$registered_abilities[ $ability_id ]['execute_callback'][0] ?? null;

		$this->assertInstanceOf( \FlavorAgent\AI\Abilities\RecommendationAbility::class, $ability, "Expected ability object for {$ability_id}." );

		$result = $ability->execute_callback( $case['input'] );

		$this->assertIsArray( $result, "Expected signature-only execution for {$ability_id}." );
		$this->assertSame(
			[
				[
					'categories' => $case['categories'],
					'blockName'  => $case['blockName'],
				],
			],
			WordPressTestState::$wpai_guideline_calls,
			"Expected {$ability_id} to request the complete guideline category set."
		);
	}
}
```

- [ ] **Step 4: Add a runtime filtering regression test**

In `tests/phpunit/RecommendationAbilityExecutionTest.php`, add this method after `test_execute_temporarily_prepends_canonical_system_instruction()`:

```php
public function test_execute_preserves_image_guidelines_when_category_declares_images(): void {
	WordPressTestState::$options = [
		\FlavorAgent\Guidelines::OPTION_SITE       => 'Use a calm site voice.',
		\FlavorAgent\Guidelines::OPTION_IMAGES     => 'Prefer documentary screenshots.',
		\FlavorAgent\Guidelines::OPTION_ADDITIONAL => 'Avoid hype.',
	];

	$seen_instruction = null;

	$result = RecommendationAbilityExecution::execute(
		'template-part',
		'flavor-agent/recommend-template-part',
		[ 'resolveSignatureOnly' => true ],
		static function () use ( &$seen_instruction ): array {
			$seen_instruction = apply_filters(
				'flavor_agent_recommendation_system_instruction',
				'Existing prompt instruction.'
			);

			return [
				'resolvedContextSignature' => 'signature',
			];
		},
		[
			'categories' => [ 'site', 'copy', 'images', 'additional' ],
			'blockName'  => '',
		]
	);

	$this->assertSame( 'signature', $result['resolvedContextSignature'] ?? null );
	$this->assertStringContainsString( 'Site: Use a calm site voice.', (string) $seen_instruction );
	$this->assertStringContainsString( 'Images: Prefer documentary screenshots.', (string) $seen_instruction );
	$this->assertStringContainsString( 'Additional: Avoid hype.', (string) $seen_instruction );
	$this->assertStringContainsString( 'Existing prompt instruction.', (string) $seen_instruction );
}
```

- [ ] **Step 5: Run the targeted failing tests**

Run:

```bash
composer run test:php -- --filter 'RegistrationTest|RecommendationAbilityExecutionTest'
```

Expected before implementation: failures showing `images` is missing from one or more expected category arrays.

---

### Task 2: Restore image guideline category declarations

**Files:**
- Modify: `inc/AI/Abilities/RecommendBlockAbility.php`
- Modify: `inc/AI/Abilities/RecommendContentAbility.php`
- Modify: `inc/AI/Abilities/RecommendPatternsAbility.php`
- Modify: `inc/AI/Abilities/RecommendNavigationAbility.php`
- Modify: `inc/AI/Abilities/RecommendStyleAbility.php`
- Modify: `inc/AI/Abilities/RecommendTemplateAbility.php`
- Modify: `inc/AI/Abilities/RecommendTemplatePartAbility.php`

- [ ] **Step 1: Update content-like surfaces to include images**

In each listed file, replace the existing category constant with the value shown:

`inc/AI/Abilities/RecommendBlockAbility.php`

```php
protected const GUIDELINE_CATEGORIES = [ 'site', 'copy', 'images', 'additional' ];
```

`inc/AI/Abilities/RecommendContentAbility.php`

```php
protected const GUIDELINE_CATEGORIES = [ 'site', 'copy', 'images', 'additional' ];
```

`inc/AI/Abilities/RecommendNavigationAbility.php`

```php
protected const GUIDELINE_CATEGORIES = [ 'site', 'copy', 'images', 'additional' ];
```

`inc/AI/Abilities/RecommendTemplateAbility.php`

```php
protected const GUIDELINE_CATEGORIES = [ 'site', 'copy', 'images', 'additional' ];
```

`inc/AI/Abilities/RecommendTemplatePartAbility.php`

```php
protected const GUIDELINE_CATEGORIES = [ 'site', 'copy', 'images', 'additional' ];
```

- [ ] **Step 2: Update non-copy recommendation surfaces to include images**

In each listed file, replace the existing category constant with the value shown:

`inc/AI/Abilities/RecommendPatternsAbility.php`

```php
protected const GUIDELINE_CATEGORIES = [ 'site', 'images', 'additional' ];
```

`inc/AI/Abilities/RecommendStyleAbility.php`

```php
protected const GUIDELINE_CATEGORIES = [ 'site', 'images', 'additional' ];
```

- [ ] **Step 3: Run the category routing tests**

Run:

```bash
composer run test:php -- --filter 'RegistrationTest|RecommendationAbilityExecutionTest'
```

Expected after implementation: all selected tests pass.

---

### Task 3: Update guideline bridge documentation

**Files:**
- Modify: `docs/features/settings-backends-and-sync.md`

- [ ] **Step 1: Replace the stale bridge flow**

In `docs/features/settings-backends-and-sync.md`, replace the current `## Guidelines Bridge Flow` list with:

```markdown
## Guidelines Bridge Flow

1. Recommendation abilities declare the guideline categories they need through their `GUIDELINE_CATEGORIES` constants.
2. `FlavorAgent\AI\Abilities\RecommendationAbility::execute_callback()` passes those categories, plus any scoped block name, into `RecommendationAbilityExecution`.
3. `RecommendationAbilityExecution` calls `Guidelines::format_prompt_context()` and temporarily prepends the formatted guidance to the recommendation system instruction through the `flavor_agent_recommendation_system_instruction` filter.
4. `Guidelines` resolves the active repository through the `flavor_agent_guidelines_repository` filter, then core/Gutenberg Guidelines storage, then legacy Flavor Agent options.
5. Core/Gutenberg storage is detected through the emerging `wp_guideline` post type and `wp_guideline_type` taxonomy model, with a read-only fallback for the older `wp_content_guideline` experiment shape.
6. Legacy options are preserved even when core storage is available. The current migration status is tracked separately so a future write migration can avoid repeated imports.
7. The settings screen keeps the legacy fields, block guideline editor, and JSON import/export available as migration/admin tooling when core Guidelines storage is detected.
```

- [ ] **Step 2: Update the primary functions table wording**

In the same file, replace the `Guidelines bridge` row role text with:

```markdown
| Guidelines bridge     | `RecommendationAbilityExecution` + `Guidelines::format_prompt_context()` | Reads core Guidelines first when available, falls back to legacy options, and prepends formatted guidance to recommendation system instructions |
```

- [ ] **Step 3: Run the docs check**

Run:

```bash
npm run check:docs
```

Expected: command exits successfully with no broken docs references.

---

### Task 4: Run focused validation gates

**Files:**
- No source edits.

- [ ] **Step 1: Run focused PHP tests for the changed contract**

Run:

```bash
composer run test:php -- --filter 'RegistrationTest|RecommendationAbilityExecutionTest'
```

Expected: all selected tests pass.

- [ ] **Step 2: Run the docs gate**

Run:

```bash
npm run check:docs
```

Expected: docs check passes.

- [ ] **Step 3: Run the fast aggregate verifier**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: `VERIFY_RESULT={...}` reports success, or reports only pre-existing unrelated failures. If `lint-plugin` is blocked by local prerequisites, rerun intentionally scoped:

```bash
npm run verify -- --skip-e2e --skip=lint-plugin
```

Expected for scoped rerun: `VERIFY_RESULT={...}` reports success for the included gates.

---

### Task 5: Final review and commit

**Files:**
- Review only the files touched by Tasks 1-3.

- [ ] **Step 1: Inspect the final diff**

Run:

```bash
git diff -- inc/AI/Abilities/RecommendBlockAbility.php inc/AI/Abilities/RecommendContentAbility.php inc/AI/Abilities/RecommendPatternsAbility.php inc/AI/Abilities/RecommendNavigationAbility.php inc/AI/Abilities/RecommendStyleAbility.php inc/AI/Abilities/RecommendTemplateAbility.php inc/AI/Abilities/RecommendTemplatePartAbility.php tests/phpunit/RegistrationTest.php tests/phpunit/RecommendationAbilityExecutionTest.php docs/features/settings-backends-and-sync.md
```

Expected: only the guideline category constants, targeted tests, and guideline bridge docs changed.

- [ ] **Step 2: Commit the remediation**

Run:

```bash
git add inc/AI/Abilities/RecommendBlockAbility.php inc/AI/Abilities/RecommendContentAbility.php inc/AI/Abilities/RecommendPatternsAbility.php inc/AI/Abilities/RecommendNavigationAbility.php inc/AI/Abilities/RecommendStyleAbility.php inc/AI/Abilities/RecommendTemplateAbility.php inc/AI/Abilities/RecommendTemplatePartAbility.php tests/phpunit/RegistrationTest.php tests/phpunit/RecommendationAbilityExecutionTest.php docs/features/settings-backends-and-sync.md
git commit -m "Restore image guideline routing for recommendations"
```

Expected: one focused commit containing only the remediation.

---

## Completion Criteria

- Image guideline text saved in settings or core/Gutenberg Guidelines storage reaches recommendation system instructions when the active ability declares `images`.
- Block, content, navigation, template, and template-part recommendation abilities declare `[ 'site', 'copy', 'images', 'additional' ]`.
- Pattern and style recommendation abilities declare `[ 'site', 'images', 'additional' ]`.
- The docs describe ability-level guideline routing through `RecommendationAbilityExecution`, not direct prompt-builder injection.
- Targeted PHPUnit tests pass.
- `npm run check:docs` passes.
- `npm run verify -- --skip-e2e` passes, or any skipped/blocked gate is explicitly recorded with the command output.
