# Recommendation Phase 2 Design Semantics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the existing `designSemantics` contract beyond style surfaces so block, template, and template-part recommendations receive a compact, normalized design diagnosis that also participates in freshness signatures and evaluation gates.

**Architecture:** Keep the style surfaces as the canonical existing implementation of `designSemantics`, add a bounded client-side semantic projection for block/template/template-part requests, and normalize the same projection server-side before prompt assembly or signature generation. Prompt sections stay small through `PromptBudget`, and deterministic PHPUnit/JS tests prove semantic changes stale cached recommendations without increasing Phase 0 `noiseRate` or `invalidOperationRate`.

**Tech Stack:** WordPress plugin PHP, PHPUnit, `FlavorAgent\Support\RecommendationResolvedSignature`, `FlavorAgent\Support\RecommendationReviewSignature`, block editor JavaScript utilities, `@wordpress/scripts` unit tests, `PromptBudget`, existing Phase 0 `RecommendationEvaluationTest`.

---

## Scope

This plan covers only Phase 2 from `improving-levers.md`: design semantics summaries for block, template, and template-part recommendation surfaces.

In scope:

- Derive compact `designSemantics` from existing editor context and structure snapshots.
- Reuse the existing style-surface `designSemantics` path instead of introducing a competing `designContext` field.
- Normalize and cap semantic payloads server-side.
- Add prompt sections for block, template, and template-part prompts.
- Include normalized semantic projections in local context signatures and server resolved/review signatures.
- Extend the Phase 0 evaluation harness with a Phase 2 gate that records unchanged `noiseRate` and `invalidOperationRate`.

Out of scope:

- Composite ranking changes from Phase 1 or later ranking phases.
- Provider calls, prompt-response fixture generation, or credentials.
- UI layout changes.
- Generated `build/` and `dist/` artifacts.
- Broad fixture-matrix expansion beyond the minimum Phase 2 metrics gate.

## Files

Create:

- `src/utils/recommendation-design-semantics.js` - shared client helper that builds the compact semantic projection for block/template/template-part requests.
- `src/utils/__tests__/recommendation-design-semantics.test.js` - unit coverage for semantic derivation, caps, unsupported-control negative signals, and stable sorting.
- `inc/Support/DesignSemantics.php` - shared PHP normalizer/formatter for bounded design semantic payloads.
- `tests/phpunit/DesignSemanticsTest.php` - PHP coverage for sanitizer caps, scalar coercion, nested surface keys, and prompt-line formatting.
- `tests/phpunit/fixtures/recommendation-evaluation-phase-2-fixtures.php` - provider-free semantic fixtures for the Phase 2 metric gate.

Modify:

- `src/context/collector.js` - attach block-level `designSemantics` to first-party editor context.
- `src/context/__tests__/collector.test.js` - verify collected block context includes bounded semantics.
- `src/utils/block-recommendation-context.js` - include semantic projection in the block local signature.
- `src/utils/__tests__/block-recommendation-context.test.js` - verify block signatures change when semantic role/contrast/rhythm changes and stay stable on object-key reorder.
- `src/templates/template-recommender-helpers.js` - build/pass template `designSemantics` and include it in template signatures.
- `src/templates/TemplateRecommender.js` - compute template semantic projection from editor slots and structure.
- `src/templates/__tests__/template-recommender-helpers.test.js` - verify template fetch input and signature behavior.
- `src/templates/__tests__/TemplateRecommender.test.js` - verify request payload contains template semantics.
- `src/template-parts/template-part-recommender-helpers.js` - build/pass template-part `designSemantics` and include it in template-part signatures.
- `src/template-parts/TemplatePartRecommender.js` - compute template-part semantic projection from editor structure.
- `src/template-parts/__tests__/template-part-recommender-helpers.test.js` - verify template-part fetch input and signature behavior.
- `src/template-parts/__tests__/TemplatePartRecommender.test.js` - verify request payload contains template-part semantics.
- `src/utils/style-operations.js` - keep existing style semantics in signatures; add no new schema unless a regression test exposes drift.
- `src/utils/__tests__/style-operations.test.js` - keep and, if needed, strengthen the existing design-semantics signature test for Global Styles and Style Book.
- `inc/Abilities/Registration.php` - declare `designSemantics` input for template and template-part requests; keep style schema unchanged.
- `inc/Abilities/BlockAbilities.php` - normalize `editorContext.designSemantics` into canonical block context before resolved signatures and prompts.
- `inc/Abilities/TemplateAbilities.php` - normalize template/template-part `designSemantics` into context before resolved and review signatures.
- `inc/LLM/Prompt.php` - add the block `## Design semantic context` prompt section.
- `inc/LLM/TemplatePrompt.php` - add the template `## Design semantic context` prompt section.
- `inc/LLM/TemplatePartPrompt.php` - add the template-part `## Design semantic context` prompt section.
- `tests/phpunit/BlockAbilitiesTest.php` - verify block resolved signatures and prompt inputs include normalized semantics.
- `tests/phpunit/TemplateAbilitiesTest.php` - verify template and template-part resolved/review signatures include normalized semantics.
- `tests/phpunit/PromptGuidanceTest.php` - verify block prompt rules treat negative signals as constraints.
- `tests/phpunit/StylePromptTest.php` - preserve existing style prompt semantics behavior.
- `tests/phpunit/TemplatePromptTest.php` - verify template semantic prompt section and budget cap.
- `tests/phpunit/TemplatePartPromptTest.php` - verify template-part semantic prompt section and budget cap.
- `tests/phpunit/PromptBudgetTest.php` - add per-surface budget assertions when no closer prompt-specific test already covers the cap.
- `tests/phpunit/RecommendationEvaluationTest.php` - load Phase 2 semantic fixtures and assert unchanged metrics.
- `improving-levers.md` - update only if implementation discovers a material Phase 2 contract correction.

## Shared Contract

Use `designSemantics` everywhere. Do not introduce `designContext`.

All new client projections must use these bounded shared keys:

```json
{
  "surface": "block|template|template-part",
  "sectionRole": "hero|footer|card|sidebar|post-body|cta|archive-list|unknown",
  "visualDensity": "sparse|balanced|dense|unknown",
  "contrastContext": "dark-parent|light-parent|image-overlay|unknown",
  "layoutRhythm": "constrained|full-width|grid|stacked|media-text|sidebar|unknown",
  "typographyRole": "heading|body|metadata|navigation|callout|unknown",
  "tokenAffinity": {
    "color": ["base", "contrast", "accent"],
    "spacing": ["medium", "large"],
    "fontSize": ["body", "heading"]
  },
  "existingDesignScore": 0.72,
  "mainDesignIssue": "contrast|spacing|hierarchy|rhythm|alignment|consistency|accessibility|none|unknown",
  "negativeSignals": [
    "no-typography-support",
    "parent-already-supplies-contrast"
  ],
  "block": {},
  "template": {},
  "templatePart": {}
}
```

Surface-specific data must live under `block`, `template`, or `templatePart`. Style surfaces keep their existing richer `designSemantics` structures from `src/utils/style-design-semantics.js`.

Caps:

- `negativeSignals`: max 6 strings.
- `tokenAffinity.color`: max 6 strings.
- `tokenAffinity.spacing`: max 6 strings.
- `tokenAffinity.fontSize`: max 6 strings.
- Surface-specific nested maps: max 8 scalar leaves per surface key.
- Prompt output: max 80 estimated tokens per new semantic prompt section.

## Task 1: Add Shared Client Design Semantics Helper

**Files:**

- Create: `src/utils/recommendation-design-semantics.js`
- Create: `src/utils/__tests__/recommendation-design-semantics.test.js`

- [ ] **Step 1: Write failing tests for block semantic derivation**

Add `src/utils/__tests__/recommendation-design-semantics.test.js`:

```js
import {
	buildBlockDesignSemantics,
	buildTemplateDesignSemantics,
	buildTemplatePartDesignSemantics,
	normalizeDesignSemantics,
} from '../recommendation-design-semantics';

describe( 'recommendation-design-semantics', () => {
	test( 'buildBlockDesignSemantics derives role, contrast, rhythm, tokens, and negative signals', () => {
		const semantics = buildBlockDesignSemantics( {
			block: {
				name: 'core/paragraph',
				title: 'Paragraph',
				currentAttributes: {
					textColor: 'contrast',
					fontSize: 'small',
				},
				inspectorPanels: {
					styles: [ 'color' ],
				},
				structuralIdentity: {
					role: 'footer-paragraph',
					location: 'footer',
					job: 'supporting-copy',
				},
			},
			parentContext: {
				block: 'core/group',
				role: 'footer',
				visualHints: {
					backgroundColor: 'contrast',
					layout: {
						type: 'constrained',
					},
				},
			},
			siblingSummariesBefore: [
				{
					block: 'core/heading',
					role: 'footer-heading',
					visualHints: {
						textAlign: 'center',
					},
				},
			],
			themeTokens: {
				colors: [
					{ slug: 'base' },
					{ slug: 'contrast' },
					{ slug: 'accent' },
				],
				fontSizes: [
					{ slug: 'small' },
					{ slug: 'heading' },
				],
				spacingSizes: [
					{ slug: 'medium' },
					{ slug: 'large' },
				],
			},
			blockOperationContext: {
				allowedActions: [],
				patterns: [],
			},
		} );

		expect( semantics ).toMatchObject( {
			surface: 'block',
			sectionRole: 'footer',
			visualDensity: 'balanced',
			contrastContext: 'dark-parent',
			layoutRhythm: 'constrained',
			typographyRole: 'body',
			mainDesignIssue: 'none',
			block: {
				name: 'core/paragraph',
				role: 'footer-paragraph',
				parentBlock: 'core/group',
			},
		} );
		expect( semantics.tokenAffinity.color ).toEqual(
			expect.arrayContaining( [ 'contrast' ] )
		);
		expect( semantics.tokenAffinity.fontSize ).toEqual(
			expect.arrayContaining( [ 'small' ] )
		);
		expect( semantics.negativeSignals ).toEqual(
			expect.arrayContaining( [ 'no-structural-pattern-actions' ] )
		);
	} );

	test( 'normalizeDesignSemantics caps arrays and removes unknown top-level keys', () => {
		expect(
			normalizeDesignSemantics( {
				surface: 'block',
				sectionRole: 'hero',
				unknownKey: 'dropped',
				tokenAffinity: {
					color: [
						'base',
						'contrast',
						'accent',
						'primary',
						'secondary',
						'tertiary',
						'extra',
					],
				},
				negativeSignals: [
					'a',
					'b',
					'c',
					'd',
					'e',
					'f',
					'g',
				],
			} )
		).toEqual( {
			surface: 'block',
			sectionRole: 'hero',
			visualDensity: 'unknown',
			contrastContext: 'unknown',
			layoutRhythm: 'unknown',
			typographyRole: 'unknown',
			tokenAffinity: {
				color: [
					'accent',
					'base',
					'contrast',
					'primary',
					'secondary',
					'tertiary',
				],
				spacing: [],
				fontSize: [],
			},
			existingDesignScore: 0,
			mainDesignIssue: 'unknown',
			negativeSignals: [ 'a', 'b', 'c', 'd', 'e', 'f' ],
		} );
	} );
} );
```

- [ ] **Step 2: Run the new test and confirm the missing helper failure**

Run:

```bash
npm run test:unit -- --runInBand src/utils/__tests__/recommendation-design-semantics.test.js
```

Expected: FAIL with a module-not-found or missing-export error for `src/utils/recommendation-design-semantics.js`.

- [ ] **Step 3: Implement the helper exports**

Create `src/utils/recommendation-design-semantics.js` with these exports:

```js
export const DESIGN_SEMANTICS_LIST_CAP = 6;
export const DESIGN_SEMANTICS_SURFACE_LEAF_CAP = 8;

export function normalizeDesignSemantics( value = {}, surface = '' ) {}

export function buildBlockDesignSemantics( context = {} ) {}

export function buildTemplateDesignSemantics( {
	editorSlots,
	editorStructure,
	visiblePatternNames,
} = {} ) {}

export function buildTemplatePartDesignSemantics( {
	editorStructure,
	visiblePatternNames,
} = {} ) {}
```

Implementation rules:

- `normalizeDesignSemantics()` returns an empty object only when the input is not an object.
- Unknown enum values become `unknown` except `mainDesignIssue`, which becomes `unknown` unless it is one of `contrast`, `spacing`, `hierarchy`, `rhythm`, `alignment`, `consistency`, `accessibility`, `none`, or `unknown`.
- `existingDesignScore` is a number clamped to `0..1`.
- String arrays are trimmed, deduplicated, sorted, and capped.
- `block`, `template`, and `templatePart` keep only scalar strings, booleans, and finite numbers, capped to 8 leaves each.
- The block builder derives:
  - `sectionRole` from `block.structuralIdentity.location`, `block.structuralIdentity.role`, parent role, and structural ancestors.
  - `contrastContext` from parent/background visual hints.
  - `layoutRhythm` from parent layout hints and sibling summaries.
  - `typographyRole` from block name and structural role.
  - `negativeSignals` from missing panels, content-only/disabled editing context, absent pattern actions, and parent-supplied contrast.
- Template and template-part builders derive role/density/rhythm from structure stats, top-level block names, empty areas, assigned parts, operation targets, pattern overrides, viewport visibility, and visible pattern names.

- [ ] **Step 4: Run the helper test to green**

Run:

```bash
npm run test:unit -- --runInBand src/utils/__tests__/recommendation-design-semantics.test.js
```

Expected: PASS.

## Task 2: Wire Block Collection And Local Signature Freshness

**Files:**

- Modify: `src/context/collector.js`
- Modify: `src/context/__tests__/collector.test.js`
- Modify: `src/utils/block-recommendation-context.js`
- Modify: `src/utils/__tests__/block-recommendation-context.test.js`

- [ ] **Step 1: Add failing signature coverage**

In `src/utils/__tests__/block-recommendation-context.test.js`, add a test that proves semantic drift changes the local signature while key order does not:

```js
test( 'includes normalized design semantic context in the block signature', () => {
	const baseContext = {
		block: { name: 'core/paragraph' },
		designSemantics: {
			surface: 'block',
			sectionRole: 'hero',
			contrastContext: 'dark-parent',
			negativeSignals: [ 'parent-already-supplies-contrast' ],
		},
	};

	expect(
		buildBlockRecommendationContextSignature( baseContext )
	).toBe(
		buildBlockRecommendationContextSignature( {
			block: { name: 'core/paragraph' },
			designSemantics: {
				negativeSignals: [ 'parent-already-supplies-contrast' ],
				contrastContext: 'dark-parent',
				sectionRole: 'hero',
				surface: 'block',
			},
		} )
	);

	expect(
		buildBlockRecommendationContextSignature( baseContext )
	).not.toBe(
		buildBlockRecommendationContextSignature( {
			...baseContext,
			designSemantics: {
				...baseContext.designSemantics,
				sectionRole: 'footer',
			},
		} )
	);
} );
```

- [ ] **Step 2: Add failing collector coverage**

In `src/context/__tests__/collector.test.js`, add a test that collects a selected block context with parent visual hints and asserts:

```js
expect( context.designSemantics ).toMatchObject( {
	surface: 'block',
	contrastContext: 'dark-parent',
	layoutRhythm: 'constrained',
} );
expect( context.designSemantics.negativeSignals ).toEqual(
	expect.arrayContaining( [ 'no-structural-pattern-actions' ] )
);
```

- [ ] **Step 3: Run block tests and confirm failure**

Run:

```bash
npm run test:unit -- --runInBand src/context/__tests__/collector.test.js src/utils/__tests__/block-recommendation-context.test.js
```

Expected: FAIL because `designSemantics` is not collected and not included in `buildBlockRecommendationContextSignature()`.

- [ ] **Step 4: Wire the helper into block context**

In `src/context/collector.js`, import `buildBlockDesignSemantics` and assign:

```js
context.designSemantics = buildBlockDesignSemantics( context );
```

Place this after the collector has populated block, parent, sibling, structural, theme-token, and block-operation context so the semantic builder can use all available inputs.

In `src/utils/block-recommendation-context.js`, import `normalizeDesignSemantics` and add:

```js
designSemantics: normalizeDesignSemantics(
	context?.designSemantics || {},
	'block'
),
```

to the object passed into `buildContextSignature()`.

- [ ] **Step 5: Run block tests to green**

Run:

```bash
npm run test:unit -- --runInBand src/context/__tests__/collector.test.js src/utils/__tests__/block-recommendation-context.test.js
```

Expected: PASS.

## Task 3: Wire Template And Template-Part Request Semantics

**Files:**

- Modify: `src/templates/template-recommender-helpers.js`
- Modify: `src/templates/TemplateRecommender.js`
- Modify: `src/templates/__tests__/template-recommender-helpers.test.js`
- Modify: `src/templates/__tests__/TemplateRecommender.test.js`
- Modify: `src/template-parts/template-part-recommender-helpers.js`
- Modify: `src/template-parts/TemplatePartRecommender.js`
- Modify: `src/template-parts/__tests__/template-part-recommender-helpers.test.js`
- Modify: `src/template-parts/__tests__/TemplatePartRecommender.test.js`

- [ ] **Step 1: Add failing helper tests for template signatures and fetch input**

In `src/templates/__tests__/template-recommender-helpers.test.js`, add:

```js
test( 'template recommendation signatures and fetch input include design semantics', () => {
	const designSemantics = {
		surface: 'template',
		sectionRole: 'archive-list',
		layoutRhythm: 'grid',
		template: {
			emptyAreaCount: 1,
		},
	};

	const firstSignature = buildTemplateRecommendationContextSignature( {
		editorSlots: { emptyAreas: [ 'footer' ] },
		editorStructure: { structureStats: { blockCount: 6 } },
		visiblePatternNames: [ 'theme/query-card' ],
		designSemantics,
	} );
	const secondSignature = buildTemplateRecommendationContextSignature( {
		editorSlots: { emptyAreas: [ 'footer' ] },
		editorStructure: { structureStats: { blockCount: 6 } },
		visiblePatternNames: [ 'theme/query-card' ],
		designSemantics: {
			...designSemantics,
			layoutRhythm: 'stacked',
		},
	} );

	expect( firstSignature ).not.toBe( secondSignature );
	expect(
		buildTemplateFetchInput( {
			templateRef: 'twentytwentyfive//archive',
			templateType: 'archive',
			prompt: '',
			editorSlots: { emptyAreas: [ 'footer' ] },
			editorStructure: { structureStats: { blockCount: 6 } },
			visiblePatternNames: [],
			designSemantics,
			contextSignature: firstSignature,
		} ).designSemantics
	).toEqual( expect.objectContaining( designSemantics ) );
} );
```

- [ ] **Step 2: Add failing helper tests for template-part signatures and fetch input**

In `src/template-parts/__tests__/template-part-recommender-helpers.test.js`, add:

```js
test( 'template-part recommendation signatures and fetch input include design semantics', () => {
	const designSemantics = {
		surface: 'template-part',
		sectionRole: 'footer',
		contrastContext: 'dark-parent',
		templatePart: {
			area: 'footer',
		},
	};

	const firstSignature = buildTemplatePartRecommendationContextSignature( {
		editorStructure: { structureStats: { blockCount: 4 } },
		visiblePatternNames: [ 'theme/footer' ],
		designSemantics,
	} );
	const secondSignature = buildTemplatePartRecommendationContextSignature( {
		editorStructure: { structureStats: { blockCount: 4 } },
		visiblePatternNames: [ 'theme/footer' ],
		designSemantics: {
			...designSemantics,
			contrastContext: 'light-parent',
		},
	} );

	expect( firstSignature ).not.toBe( secondSignature );
	expect(
		buildTemplatePartFetchInput( {
			templatePartRef: 'twentytwentyfive//footer',
			prompt: '',
			editorStructure: { structureStats: { blockCount: 4 } },
			visiblePatternNames: [],
			designSemantics,
			contextSignature: firstSignature,
		} ).designSemantics
	).toEqual( expect.objectContaining( designSemantics ) );
} );
```

- [ ] **Step 3: Run helper tests and confirm failure**

Run:

```bash
npm run test:unit -- --runInBand src/templates/__tests__/template-recommender-helpers.test.js src/template-parts/__tests__/template-part-recommender-helpers.test.js
```

Expected: FAIL because template and template-part helpers do not accept or pass `designSemantics`.

- [ ] **Step 4: Update helper signatures and fetch input**

In `src/templates/template-recommender-helpers.js`, add a `designSemantics` parameter to `buildTemplateRecommendationContextSignature()` and `buildTemplateFetchInput()`. Normalize and include it in both the context signature and request input:

```js
designSemantics: normalizeDesignSemantics(
	designSemantics || {},
	'template'
),
```

In `src/template-parts/template-part-recommender-helpers.js`, add the same shape using surface `template-part`.

- [ ] **Step 5: Compute semantics in React request components**

In `src/templates/TemplateRecommender.js`, import `buildTemplateDesignSemantics` and compute:

```js
const designSemantics = useMemo(
	() =>
		buildTemplateDesignSemantics( {
			editorSlots,
			editorStructure,
			visiblePatternNames,
		} ),
	[ editorSlots, editorStructure, visiblePatternNames ]
);
```

Pass `designSemantics` to `buildTemplateRecommendationContextSignature()` and `buildTemplateFetchInput()`.

In `src/template-parts/TemplatePartRecommender.js`, import `buildTemplatePartDesignSemantics` and compute:

```js
const designSemantics = useMemo(
	() =>
		buildTemplatePartDesignSemantics( {
			editorStructure,
			visiblePatternNames,
		} ),
	[ editorStructure, visiblePatternNames ]
);
```

Pass `designSemantics` to `buildTemplatePartRecommendationContextSignature()` and `buildTemplatePartFetchInput()`.

- [ ] **Step 6: Verify template request tests**

Run:

```bash
npm run test:unit -- --runInBand src/templates/__tests__/template-recommender-helpers.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/template-part-recommender-helpers.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js
```

Expected: PASS.

## Task 4: Add Server-Side Design Semantics Normalization

**Files:**

- Create: `inc/Support/DesignSemantics.php`
- Create: `tests/phpunit/DesignSemanticsTest.php`
- Modify: `inc/Abilities/Registration.php`
- Modify: `inc/Abilities/BlockAbilities.php`
- Modify: `inc/Abilities/TemplateAbilities.php`

- [ ] **Step 1: Write PHP sanitizer tests**

Create `tests/phpunit/DesignSemanticsTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\DesignSemantics;
use WP_UnitTestCase;

final class DesignSemanticsTest extends WP_UnitTestCase {
	public function test_normalizes_and_caps_shared_design_semantics(): void {
		$normalized = DesignSemantics::normalize(
			[
				'surface'             => 'block',
				'sectionRole'         => 'hero',
				'visualDensity'       => 'dense',
				'contrastContext'     => 'dark-parent',
				'layoutRhythm'        => 'grid',
				'typographyRole'      => 'heading',
				'existingDesignScore' => 1.5,
				'mainDesignIssue'     => 'contrast',
				'tokenAffinity'       => [
					'color'    => [ 'contrast', 'base', 'contrast', 'accent', 'muted', 'primary', 'secondary' ],
					'spacing'  => [ 'large' ],
					'fontSize' => [ 'heading' ],
				],
				'negativeSignals'     => [ 'a', 'b', 'c', 'd', 'e', 'f', 'g' ],
				'unknown'             => '<script>alert(1)</script>',
				'block'               => [
					'name'        => 'core/group',
					'unsupported' => [ 'drop' ],
					'visible'     => true,
				],
			],
			'block'
		);

		$this->assertSame( 'block', $normalized['surface'] );
		$this->assertSame( 'hero', $normalized['sectionRole'] );
		$this->assertSame( 1.0, $normalized['existingDesignScore'] );
		$this->assertArrayNotHasKey( 'unknown', $normalized );
		$this->assertCount( 6, $normalized['tokenAffinity']['color'] );
		$this->assertCount( 6, $normalized['negativeSignals'] );
		$this->assertSame( 'core/group', $normalized['block']['name'] );
		$this->assertTrue( $normalized['block']['visible'] );
		$this->assertArrayNotHasKey( 'unsupported', $normalized['block'] );
	}

	public function test_formats_prompt_lines_without_raw_json_dump(): void {
		$lines = DesignSemantics::format_prompt_lines(
			[
				'surface'         => 'template-part',
				'sectionRole'     => 'footer',
				'visualDensity'   => 'balanced',
				'contrastContext' => 'dark-parent',
				'layoutRhythm'    => 'constrained',
				'typographyRole'  => 'body',
				'mainDesignIssue' => 'contrast',
				'negativeSignals' => [ 'parent-already-supplies-contrast' ],
				'templatePart'    => [
					'area' => 'footer',
				],
			]
		);

		$this->assertContains( 'Role: footer', $lines );
		$this->assertContains( 'Contrast: dark-parent', $lines );
		$this->assertContains( 'Main issue: contrast', $lines );
		$this->assertContains( 'Negative signals: parent-already-supplies-contrast', $lines );
		$this->assertContains( 'Template part: area=footer', $lines );
	}
}
```

- [ ] **Step 2: Run sanitizer tests and confirm failure**

Run:

```bash
composer run test:php -- --filter DesignSemanticsTest
```

Expected: FAIL because `FlavorAgent\Support\DesignSemantics` does not exist.

- [ ] **Step 3: Implement `DesignSemantics`**

Create `inc/Support/DesignSemantics.php` with:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class DesignSemantics {
	public static function normalize( mixed $value, string $surface = '' ): array {}

	public static function format_prompt_lines( array $semantics ): array {}
}
```

Implementation rules:

- Use `NormalizesInput` helpers only if they keep this class dependency-light; otherwise sanitize directly with WordPress sanitizers.
- Allowed surfaces: `block`, `template`, `template-part`, `global-styles`, `style-book`.
- Allowed shared enum keys and fallback values must match the shared contract in this plan.
- `surface` falls back to the method argument when input omits it.
- `tokenAffinity` returns `color`, `spacing`, and `fontSize` arrays even when empty.
- `block`, `template`, and `templatePart` preserve only capped scalar leaf values.
- `format_prompt_lines()` must output human-readable lines, not raw JSON, and must omit empty/default-only sections.

- [ ] **Step 4: Add request schemas**

In `inc/Abilities/Registration.php`:

- Add `designSemantics` as `self::open_object_schema()` to `flavor-agent/recommend-template`.
- Add `designSemantics` as `self::open_object_schema()` to `flavor-agent/recommend-template-part`.
- Do not change `flavor-agent/recommend-style` except to keep the existing `styleContext.designSemantics` schema.

- [ ] **Step 5: Normalize ability input before signatures**

In `inc/Abilities/BlockAbilities.php`:

- Import `FlavorAgent\Support\DesignSemantics`.
- During `build_context_from_editor_context()`, normalize `$context['designSemantics'] ?? []` with surface `block`.
- Store the normalized value at `$context['designSemantics']` when non-empty.

In `inc/Abilities/TemplateAbilities.php`:

- Import `FlavorAgent\Support\DesignSemantics`.
- In `recommend_template()`, normalize `$input['designSemantics'] ?? []` with surface `template` after editor slots/structure are merged into `$context` and before `RecommendationResolvedSignature::from_payload()`.
- In `recommend_template_part()`, normalize `$input['designSemantics'] ?? []` with surface `template-part` before resolved and review signatures.

- [ ] **Step 6: Run server normalization tests**

Run:

```bash
composer run test:php -- --filter 'DesignSemanticsTest|BlockAbilitiesTest|TemplateAbilitiesTest'
```

Expected: PASS after ability-specific tests are added in Task 6.

## Task 5: Add Prompt Sections With Budget Caps

**Files:**

- Modify: `inc/LLM/Prompt.php`
- Modify: `inc/LLM/TemplatePrompt.php`
- Modify: `inc/LLM/TemplatePartPrompt.php`
- Modify: `tests/phpunit/PromptGuidanceTest.php`
- Modify: `tests/phpunit/TemplatePromptTest.php`
- Modify: `tests/phpunit/TemplatePartPromptTest.php`
- Modify: `tests/phpunit/StylePromptTest.php`
- Modify: `tests/phpunit/PromptBudgetTest.php`

- [ ] **Step 1: Add failing block prompt tests**

In `tests/phpunit/PromptGuidanceTest.php` or the closest block prompt test file, assert:

```php
$prompt = \FlavorAgent\LLM\Prompt::build_user(
	[
		'block'           => [
			'name'            => 'core/paragraph',
			'title'           => 'Paragraph',
			'inspectorPanels' => [
				'styles' => [ 'color' ],
			],
		],
		'designSemantics' => [
			'surface'         => 'block',
			'sectionRole'     => 'footer',
			'visualDensity'   => 'balanced',
			'contrastContext' => 'dark-parent',
			'layoutRhythm'    => 'constrained',
			'typographyRole'  => 'body',
			'mainDesignIssue' => 'contrast',
			'negativeSignals' => [ 'parent-already-supplies-contrast' ],
			'block'           => [
				'name' => 'core/paragraph',
			],
		],
	],
	''
);

$this->assertStringContainsString( '## Design semantic context', $prompt );
$this->assertStringContainsString( 'Role: footer', $prompt );
$this->assertStringContainsString( 'Negative signals: parent-already-supplies-contrast', $prompt );
```

- [ ] **Step 2: Add failing template prompt tests**

In `tests/phpunit/TemplatePromptTest.php`, add assertions equivalent to:

```php
$prompt = \FlavorAgent\LLM\TemplatePrompt::build_user(
	[
		'templateRef'     => 'twentytwentyfive//archive',
		'templateType'    => 'archive',
		'title'           => 'Archive',
		'designSemantics' => [
			'surface'         => 'template',
			'sectionRole'     => 'archive-list',
			'visualDensity'   => 'dense',
			'contrastContext' => 'unknown',
			'layoutRhythm'    => 'grid',
			'typographyRole'  => 'body',
			'mainDesignIssue' => 'rhythm',
			'template'        => [
				'emptyAreaCount' => 1,
			],
		],
	],
	''
);

$this->assertStringContainsString( '## Design semantic context', $prompt );
$this->assertStringContainsString( 'Role: archive-list', $prompt );
$this->assertStringContainsString( 'Template: emptyAreaCount=1', $prompt );
```

- [ ] **Step 3: Add failing template-part prompt tests**

In `tests/phpunit/TemplatePartPromptTest.php`, add assertions equivalent to:

```php
$prompt = \FlavorAgent\LLM\TemplatePartPrompt::build_user(
	[
		'templatePartRef' => 'twentytwentyfive//footer',
		'slug'            => 'footer',
		'title'           => 'Footer',
		'area'            => 'footer',
		'designSemantics' => [
			'surface'         => 'template-part',
			'sectionRole'     => 'footer',
			'visualDensity'   => 'balanced',
			'contrastContext' => 'dark-parent',
			'layoutRhythm'    => 'constrained',
			'typographyRole'  => 'body',
			'mainDesignIssue' => 'contrast',
			'templatePart'    => [
				'area' => 'footer',
			],
		],
	],
	''
);

$this->assertStringContainsString( '## Design semantic context', $prompt );
$this->assertStringContainsString( 'Role: footer', $prompt );
$this->assertStringContainsString( 'Template part: area=footer', $prompt );
```

- [ ] **Step 4: Run prompt tests and confirm failure**

Run:

```bash
composer run test:php -- --filter 'PromptGuidanceTest|TemplatePromptTest|TemplatePartPromptTest|StylePromptTest|PromptBudgetTest'
```

Expected: FAIL because the block/template/template-part prompt builders do not yet add the new section.

- [ ] **Step 5: Add prompt sections**

In each prompt builder, normalize existing context with `DesignSemantics::normalize()` and format it with `DesignSemantics::format_prompt_lines()`.

Use section key `design_semantics` and heading:

```php
$budget->add_section(
	'design_semantics',
	"## Design semantic context\n" . implode( "\n", $design_semantic_lines ),
	80
);
```

Keep `StylePrompt.php` on its existing `designSemantics` path. Do not duplicate the style prompt formatter unless the shared formatter can replace existing output without changing current style prompt tests.

- [ ] **Step 6: Add budget assertions**

Add or update a prompt budget test to set:

```php
add_filter(
	'flavor_agent_prompt_budget_max_tokens',
	static fn(): int => 220
);
```

Then build each new prompt with a verbose `designSemantics` payload and assert the section either fits under the cap or is omitted by `PromptBudget` without dropping higher-priority identity sections.

- [ ] **Step 7: Run prompt tests to green**

Run:

```bash
composer run test:php -- --filter 'PromptGuidanceTest|TemplatePromptTest|TemplatePartPromptTest|StylePromptTest|PromptBudgetTest'
```

Expected: PASS.

## Task 6: Add Server And Client Freshness Regression Tests

**Files:**

- Modify: `tests/phpunit/BlockAbilitiesTest.php`
- Modify: `tests/phpunit/TemplateAbilitiesTest.php`
- Modify: `tests/phpunit/RecommendationSignatureTest.php`
- Modify: `src/utils/__tests__/style-operations.test.js`

- [ ] **Step 1: Add block resolved signature coverage**

In `tests/phpunit/BlockAbilitiesTest.php`, call `BlockAbilities::recommend_block()` twice with `resolveSignatureOnly: true` and identical context except for `editorContext.designSemantics.sectionRole`.

Assert:

```php
$this->assertNotSame(
	$first['resolvedContextSignature'],
	$second['resolvedContextSignature']
);
```

Also assert an object-key reorder inside `designSemantics` does not change the signature.

- [ ] **Step 2: Add template and template-part signature coverage**

In `tests/phpunit/TemplateAbilitiesTest.php`, add one test for `recommend_template()` and one for `recommend_template_part()` using `resolveSignatureOnly: true`.

For each surface:

- Same normalized semantic payload with different object-key order yields the same resolved signature.
- Different `sectionRole`, `contrastContext`, or `layoutRhythm` yields a different resolved signature.
- Different semantic payload changes the review context signature because review/apply freshness must fail when design role, rhythm, or contrast shifts.

- [ ] **Step 3: Keep generic signature helper behavior stable**

In `tests/phpunit/RecommendationSignatureTest.php`, add a small payload-level test showing nested `designSemantics` values are handled by the existing signature helpers through canonical payload encoding. This protects against future changes that strip semantic fields before hashing.

- [ ] **Step 4: Verify style signature regression remains green**

Run the existing style signature test:

```bash
npm run test:unit -- --runInBand src/utils/__tests__/style-operations.test.js
```

Expected: PASS, including `buildGlobalStylesRecommendationContextSignature changes when design semantic context changes`.

- [ ] **Step 5: Run all freshness tests**

Run:

```bash
composer run test:php -- --filter 'BlockAbilitiesTest|TemplateAbilitiesTest|RecommendationSignatureTest'
npm run test:unit -- --runInBand src/utils/__tests__/block-recommendation-context.test.js src/templates/__tests__/template-recommender-helpers.test.js src/template-parts/__tests__/template-part-recommender-helpers.test.js src/utils/__tests__/style-operations.test.js
```

Expected: PASS.

## Task 7: Add Phase 2 Metrics Gate

**Files:**

- Create: `tests/phpunit/fixtures/recommendation-evaluation-phase-2-fixtures.php`
- Modify: `tests/phpunit/RecommendationEvaluationTest.php`

- [ ] **Step 1: Add semantic fixtures**

Create `tests/phpunit/fixtures/recommendation-evaluation-phase-2-fixtures.php` with a small provider-free fixture set:

```php
<?php

declare(strict_types=1);

return [
	'block_semantics_parent_contrast_constraint' => [
		'surface'         => 'block',
		'alreadyGood'     => true,
		'designSemantics' => [
			'surface'         => 'block',
			'sectionRole'     => 'footer',
			'contrastContext' => 'dark-parent',
			'mainDesignIssue' => 'none',
			'negativeSignals' => [ 'parent-already-supplies-contrast' ],
		],
		'suggestions'     => [],
	],
	'template_semantics_no_invalid_operations' => [
		'surface'         => 'template',
		'alreadyGood'     => false,
		'designSemantics' => [
			'surface'         => 'template',
			'sectionRole'     => 'archive-list',
			'layoutRhythm'    => 'grid',
			'mainDesignIssue' => 'rhythm',
		],
		'suggestions'     => [
			[
				'label'      => 'Use existing query-card pattern rhythm',
				'operations' => [],
			],
		],
	],
	'template_part_semantics_no_invalid_operations' => [
		'surface'         => 'template-part',
		'alreadyGood'     => false,
		'designSemantics' => [
			'surface'         => 'template-part',
			'sectionRole'     => 'footer',
			'contrastContext' => 'dark-parent',
			'mainDesignIssue' => 'contrast',
		],
		'suggestions'     => [
			[
				'label'      => 'Use existing footer contrast tokens',
				'operations' => [],
			],
		],
	],
];
```

- [ ] **Step 2: Add metric assertions**

In `tests/phpunit/RecommendationEvaluationTest.php`, add a test that loads the Phase 2 fixtures and evaluates them with the same `evaluate()` helper used by Phase 0.

Assert:

```php
$this->assertSame( 0.0, $metrics['invalidOperationRate'] );
$this->assertSame( 0.0, $metrics['noiseRate'] );
```

Also assert the original Phase 0 fixture baseline remains unchanged after semantic fixtures are loaded in their own test:

```php
$this->assertSame( 0.3333, $baseline['invalidOperationRate'] );
$this->assertSame( 1.0, $baseline['noiseRate'] );
```

- [ ] **Step 3: Run metrics gate**

Run:

```bash
composer run test:php -- --filter RecommendationEvaluationTest
```

Expected: PASS with Phase 0 baseline unchanged and Phase 2 semantic fixtures recording no increase in `noiseRate` or `invalidOperationRate`.

## Task 8: Final Verification

**Files:**

- All files listed above.

- [ ] **Step 1: Run targeted JS tests**

Run:

```bash
npm run test:unit -- --runInBand src/utils/__tests__/recommendation-design-semantics.test.js src/context/__tests__/collector.test.js src/utils/__tests__/block-recommendation-context.test.js src/templates/__tests__/template-recommender-helpers.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/template-part-recommender-helpers.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/utils/__tests__/style-operations.test.js
```

Expected: PASS.

- [ ] **Step 2: Run targeted PHP tests**

Run:

```bash
composer run test:php -- --filter 'DesignSemanticsTest|RecommendationEvaluationTest|BlockAbilitiesTest|TemplateAbilitiesTest|RecommendationSignatureTest|PromptGuidanceTest|StylePromptTest|TemplatePromptTest|TemplatePartPromptTest|PromptBudgetTest'
```

Expected: PASS.

- [ ] **Step 3: Run docs check**

Run:

```bash
npm run check:docs
```

Expected: PASS.

- [ ] **Step 4: Run fast aggregate verification**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: PASS or a clearly reported pre-existing blocker unrelated to Phase 2. If a blocker appears, record the failing step, command, and log path from `output/verify/summary.json`.

- [ ] **Step 5: Check whitespace and generated artifacts**

Run:

```bash
git diff --check
git status --short
```

Expected:

- `git diff --check` reports no whitespace errors.
- `build/` and `dist/` are unchanged.
- Staged or modified files are limited to the Phase 2 file list unless the operator explicitly expanded scope.

## Definition Of Done

- Block, template, and template-part recommendation requests include normalized `designSemantics`.
- Style recommendations keep using the existing `styleContext.designSemantics` path.
- New prompt sections use the exact heading `## Design semantic context`.
- New prompt sections are capped through `PromptBudget` and do not displace identity/context sections under a constrained budget.
- Local JS signatures and server resolved/review signatures stale when semantic role, rhythm, or contrast changes.
- Object-key reordering inside semantic payloads does not change signatures.
- Phase 0 metrics remain unchanged.
- Phase 2 semantic fixtures record no higher `noiseRate` and no higher `invalidOperationRate`.
- `npm run check:docs` passes.
- `npm run verify -- --skip-e2e` passes or reports an unrelated existing blocker with evidence.
