# Recommendation Phase 2 Design Semantics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the existing `designSemantics` contract beyond style surfaces so block, template, and template-part recommendations receive a compact, normalized design diagnosis that also participates in freshness signatures and evaluation gates.

**Architecture:** Keep the style surfaces as the canonical existing implementation of `designSemantics`, add a bounded client-side semantic projection for block/template/template-part requests, and normalize the same projection server-side before prompt assembly or signature generation. Prompt sections stay small through `DesignSemantics` formatter caps before `PromptBudget` assembly, and deterministic PHPUnit/JS tests prove semantic changes stale cached recommendations without increasing Phase 0 `noiseRate` or `invalidOperationRate`.

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
- Extend the Phase 0 evaluation harness with a parser-backed Phase 2 gate that records unchanged `noiseRate` and `invalidOperationRate`.

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
- `tests/phpunit/fixtures/recommendation-evaluation-phase-2-parser-fixtures.php` - provider-free parser-backed semantic fixtures for the Phase 2 metric gate.

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
- `src/template-parts/TemplatePartRecommender.js` - compute template-part semantic projection from template-part identity and editor structure.
- `src/template-parts/__tests__/template-part-recommender-helpers.test.js` - verify template-part fetch input and signature behavior.
- `src/template-parts/__tests__/TemplatePartRecommender.test.js` - verify request payload contains template-part semantics.
- `src/utils/style-operations.js` - keep existing style semantics in signatures; add no new schema unless a regression test exposes drift.
- `src/utils/__tests__/style-operations.test.js` - keep and, if needed, strengthen the existing design-semantics signature test for Global Styles and Style Book.
- `inc/Abilities/Registration.php` - declare `designSemantics` input for template and template-part requests; keep style schema unchanged.
- `tests/phpunit/RegistrationTest.php` - verify template and template-part ability input schemas expose the new request field.
- `inc/Abilities/BlockAbilities.php` - normalize `editorContext.designSemantics` into canonical block context before resolved signatures and prompts.
- `inc/Abilities/TemplateAbilities.php` - normalize template/template-part `designSemantics` into context and review-context payloads before resolved and review signatures.
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
- `tests/phpunit/RecommendationEvaluationTest.php` - load Phase 2 parser-backed semantic fixtures, assert prompt wiring for semantic context, and assert unchanged metrics.
- `improving-levers.md` - update only if implementation discovers a material Phase 2 contract correction.

## Shared Contract

Use `designSemantics` everywhere. Do not introduce `designContext`.

All new client projections must use these bounded shared keys:

```json
{
  "surface": "block|template|template-part",
  "sectionRole": "hero|header|footer|card|sidebar|post-body|cta|archive-list|unknown",
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
- Prompt output: max 80 estimated tokens per new semantic prompt section. This cap belongs in `DesignSemantics::format_prompt_lines()` before sections are added to `PromptBudget`; `PromptBudget::add_section()` uses its third argument as priority, not a per-section token limit.
- Header template parts are first-class semantic roles. Tests must prove `theme//header` or equivalent header identities normalize to `sectionRole: "header"` and preserve `templatePart.area: "header"` instead of falling through to `unknown`.

## Task 1: Add Shared Client Design Semantics Helper

**Files:**

- Create: `src/utils/recommendation-design-semantics.js`
- Create: `src/utils/__tests__/recommendation-design-semantics.test.js`

- [x] **Step 1: Write failing tests for block semantic derivation**

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
					'base: #ffffff',
					'contrast: #111111',
					'accent: #3858e9',
				],
				colorPresets: [
					{ slug: 'base', color: '#ffffff' },
					{ slug: 'contrast', color: '#111111' },
					{ slug: 'accent', color: '#3858e9' },
				],
				fontSizes: [ 'small: 0.875rem', 'heading: 2rem' ],
				fontSizePresets: [
					{ slug: 'small', size: '0.875rem' },
					{ slug: 'heading', size: '2rem' },
				],
				spacing: [ 'medium: 1.5rem', 'large: 3rem' ],
				spacingPresets: [
					{ slug: 'medium', size: '1.5rem' },
					{ slug: 'large', size: '3rem' },
				],
			},
			blockOperationContext: {
				targetClientId: 'target-1',
				targetBlockName: 'core/paragraph',
				targetSignature: 'target-signature',
				allowedPatterns: [],
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

	test( 'buildTemplatePartDesignSemantics preserves template-part identity and area role', () => {
		const semantics = buildTemplatePartDesignSemantics( {
			templatePartRef: 'twentytwentyfive//footer',
			slug: 'footer',
			area: 'footer',
			editorStructure: {
				topLevelBlocks: [ 'core/group' ],
				structureStats: {
					blockCount: 4,
					hasNavigation: true,
					containsSocialLinks: true,
					hasSingleWrapperGroup: true,
				},
			},
			visiblePatternNames: [ 'twentytwentyfive/footer' ],
		} );

		expect( semantics ).toMatchObject( {
			surface: 'template-part',
			sectionRole: 'footer',
			layoutRhythm: 'constrained',
			templatePart: {
				ref: 'twentytwentyfive//footer',
				slug: 'footer',
				area: 'footer',
			},
		} );
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

- [x] **Step 2: Run the new test and confirm the missing helper failure**

Run:

```bash
npm run test:unit -- --runInBand src/utils/__tests__/recommendation-design-semantics.test.js
```

Expected: FAIL with a module-not-found or missing-export error for `src/utils/recommendation-design-semantics.js`.

- [x] **Step 3: Implement the helper exports**

Create `src/utils/recommendation-design-semantics.js` with these exports:

```js
export const DESIGN_SEMANTICS_LIST_CAP = 6;
export const DESIGN_SEMANTICS_SURFACE_LEAF_CAP = 8;

export function normalizeDesignSemantics( value = {}, surface = '' ) {}

export function buildBlockDesignSemantics( context = {} ) {}

export function buildTemplateDesignSemantics( {
	templateType,
	editorSlots,
	editorStructure,
	visiblePatternNames,
} = {} ) {}

export function buildTemplatePartDesignSemantics( {
	templatePartRef,
	slug,
	area,
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
  - `negativeSignals` from missing panels, content-only/disabled editing context, absent structural pattern actions, and parent-supplied contrast. Read structural actions from the real collector shape: `blockOperationContext.allowedPatterns[*].allowedActions`, not a top-level `allowedActions` array.
- Token affinity must read the current `summarizeTokens()` shape from `src/context/theme-tokens.js`: `colorPresets`, `fontSizePresets`, and `spacingPresets` carry slug metadata, while `colors`, `fontSizes`, and `spacing` are compact prompt strings.
- Template and template-part builders derive role/density/rhythm from structure stats, top-level block names, empty areas, assigned parts, operation targets, pattern overrides, viewport visibility, and visible pattern names.
- Template-part builders must also receive and preserve the current template-part identity (`templatePartRef`, normalized `slug`, and resolved `area`) so header/footer/sidebar semantics are not inferred from structure alone.

- [x] **Step 4: Run the helper test to green**

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

- [x] **Step 1: Add failing signature coverage**

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

- [x] **Step 2: Add failing collector coverage**

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

- [x] **Step 3: Run block tests and confirm failure**

Run:

```bash
npm run test:unit -- --runInBand src/context/__tests__/collector.test.js src/utils/__tests__/block-recommendation-context.test.js
```

Expected: FAIL because `designSemantics` is not collected and not included in `buildBlockRecommendationContextSignature()`.

- [x] **Step 4: Wire the helper into block context**

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

- [x] **Step 5: Run block tests to green**

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

- [x] **Step 1: Add failing helper tests for template signatures and fetch input**

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

- [x] **Step 2: Add failing helper tests for template-part signatures and fetch input**

In `src/template-parts/__tests__/template-part-recommender-helpers.test.js`, add:

```js
test( 'template-part recommendation signatures and fetch input include design semantics', () => {
	const designSemantics = {
		surface: 'template-part',
		sectionRole: 'footer',
		contrastContext: 'dark-parent',
		templatePart: {
			ref: 'twentytwentyfive//footer',
			slug: 'footer',
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

- [x] **Step 3: Run helper tests and confirm failure**

Run:

```bash
npm run test:unit -- --runInBand src/templates/__tests__/template-recommender-helpers.test.js src/template-parts/__tests__/template-part-recommender-helpers.test.js
```

Expected: FAIL because template and template-part helpers do not accept or pass `designSemantics`.

- [x] **Step 4: Update helper signatures and fetch input**

In `src/templates/template-recommender-helpers.js`, add a `designSemantics` parameter to `buildTemplateRecommendationContextSignature()` and `buildTemplateFetchInput()`. Normalize and include it in both the context signature and request input:

```js
designSemantics: normalizeDesignSemantics(
	designSemantics || {},
	'template'
),
```

In `src/template-parts/template-part-recommender-helpers.js`, add the same shape using surface `template-part`.

- [x] **Step 5: Compute semantics in React request components**

In `src/templates/TemplateRecommender.js`, import `buildTemplateDesignSemantics` and compute:

```js
const designSemantics = useMemo(
	() =>
		buildTemplateDesignSemantics( {
			templateType,
			editorSlots,
			editorStructure,
			visiblePatternNames,
		} ),
	[ editorSlots, editorStructure, templateType, visiblePatternNames ]
);
```

Pass `designSemantics` to `buildTemplateRecommendationContextSignature()` and `buildTemplateFetchInput()`.

In `src/template-parts/TemplatePartRecommender.js`, import `buildTemplatePartDesignSemantics`. Compute this after `templatePartRef`, `slug`, and `area` have been resolved so the semantic projection can distinguish header/footer/sidebar template parts without guessing from block structure alone:

```js
const designSemantics = useMemo(
	() =>
		buildTemplatePartDesignSemantics( {
			templatePartRef,
			slug,
			area,
			editorStructure,
			visiblePatternNames,
		} ),
	[ area, editorStructure, slug, templatePartRef, visiblePatternNames ]
);
```

Pass `designSemantics` to `buildTemplatePartRecommendationContextSignature()` and `buildTemplatePartFetchInput()`.

- [x] **Step 6: Verify template request tests**

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
- Modify: `tests/phpunit/RegistrationTest.php`
- Modify: `inc/Abilities/BlockAbilities.php`
- Modify: `inc/Abilities/TemplateAbilities.php`

- [x] **Step 1: Write PHP sanitizer tests**

Create `tests/phpunit/DesignSemanticsTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\DesignSemantics;
use PHPUnit\Framework\TestCase;

final class DesignSemanticsTest extends TestCase {
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

	public function test_formats_prompt_lines_under_estimated_token_cap(): void {
		$lines = DesignSemantics::format_prompt_lines(
			[
				'surface'         => 'block',
				'sectionRole'     => 'hero',
				'visualDensity'   => 'dense',
				'contrastContext' => 'image-overlay',
				'layoutRhythm'    => 'grid',
				'typographyRole'  => 'heading',
				'mainDesignIssue' => 'accessibility',
				'negativeSignals' => [
					'parent-already-supplies-contrast',
					'no-typography-support',
					'content-only-context',
					'locked-editing-mode',
				],
				'block'           => [
					'name'        => 'core/group',
					'role'        => 'hero-card',
					'parentBlock' => 'core/cover',
				],
			],
			12
		);

		$text = implode( "\n", $lines );

		$this->assertLessThanOrEqual(
			12,
			(int) ceil( strlen( $text ) / 4 )
		);
	}
}
```

- [x] **Step 2: Run sanitizer tests and confirm failure**

Run:

```bash
composer run test:php -- --filter DesignSemanticsTest
```

Expected: FAIL because `FlavorAgent\Support\DesignSemantics` does not exist.

- [x] **Step 3: Implement `DesignSemantics`**

Create `inc/Support/DesignSemantics.php` with:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class DesignSemantics {
	public static function normalize( mixed $value, string $surface = '' ): array {}

	public static function format_prompt_lines( array $semantics, int $max_estimated_tokens = 80 ): array {}
}
```

Implementation rules:

- Use `NormalizesInput` helpers only if they keep this class dependency-light; otherwise sanitize directly with WordPress sanitizers.
- Base the test on `PHPUnit\Framework\TestCase`; the repo-local PHPUnit bootstrap does not define `WP_UnitTestCase`.
- Allowed surfaces: `block`, `template`, `template-part`, `global-styles`, `style-book`.
- Allowed shared enum keys and fallback values must match the shared contract in this plan.
- `surface` falls back to the method argument when input omits it.
- `tokenAffinity` returns `color`, `spacing`, and `fontSize` arrays even when empty.
- `block`, `template`, and `templatePart` preserve only capped scalar leaf values.
- `format_prompt_lines()` must output human-readable lines, not raw JSON, and must omit empty/default-only sections.
- `format_prompt_lines()` must keep output under the `$max_estimated_tokens` budget by adding lines in priority order and stopping before the next line would exceed the cap. Use the same `ceil( strlen( $text ) / 4 )` estimate as `PromptBudget` so the cap is deterministic without introducing an `inc/Support` dependency on `FlavorAgent\LLM\PromptBudget`.

- [x] **Step 4: Add request schemas**

In `inc/Abilities/Registration.php`:

- Add `designSemantics` as `self::open_object_schema()` to `flavor-agent/recommend-template`.
- Add `designSemantics` as `self::open_object_schema()` to `flavor-agent/recommend-template-part`.
- Do not change `flavor-agent/recommend-style` except to keep the existing `styleContext.designSemantics` schema.

In `tests/phpunit/RegistrationTest.php`, extend the existing template and template-part schema tests so the request contract cannot drift:

```php
$this->assertSame(
	'object',
	$ability['input_schema']['properties']['designSemantics']['type'] ?? null
);
```

- [x] **Step 5: Normalize ability input before signatures**

In `inc/Abilities/BlockAbilities.php`:

- Import `FlavorAgent\Support\DesignSemantics`.
- During `build_context_from_editor_context()`, normalize `$context['designSemantics'] ?? []` with surface `block`.
- Store the normalized value at `$context['designSemantics']` when non-empty.

In `inc/Abilities/TemplateAbilities.php`:

- Import `FlavorAgent\Support\DesignSemantics`.
- In `recommend_template()`, normalize `$input['designSemantics'] ?? []` with surface `template` after editor slots/structure are merged into `$context` and before `RecommendationResolvedSignature::from_payload()`.
- When the normalized template semantics are non-empty, store them on both `$context['designSemantics']` and `$review_context['designSemantics']` before calling `RecommendationResolvedSignature::from_payload()` and `build_template_review_context_signature()`.
- In `recommend_template_part()`, normalize `$input['designSemantics'] ?? []` with surface `template-part` after live editor structure is merged into `$context`.
- When the normalized template-part semantics are non-empty, store them on both `$context['designSemantics']` and `$review_context['designSemantics']` before calling `RecommendationResolvedSignature::from_payload()` and `build_template_part_review_context_signature()`.
- Update `build_template_review_context_signature()` and `build_template_part_review_context_signature()` so each review payload includes normalized `designSemantics` when present:

```php
$design_semantics = DesignSemantics::normalize(
	$context['designSemantics'] ?? [],
	'template'
);

if ( ! empty( $design_semantics ) ) {
	$payload['designSemantics'] = $design_semantics;
}
```

Use surface `template-part` in `build_template_part_review_context_signature()`. Do not rely on resolved-context freshness alone; review/apply freshness must fail when role, rhythm, or contrast semantics change.

- [x] **Step 6: Run server normalization tests**

Run:

```bash
composer run test:php -- --filter 'DesignSemanticsTest|RegistrationTest|BlockAbilitiesTest|TemplateAbilitiesTest'
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

- [x] **Step 1: Add failing block prompt tests**

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

- [x] **Step 2: Add failing template prompt tests**

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

- [x] **Step 3: Add failing template-part prompt tests**

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

- [x] **Step 4: Run prompt tests and confirm failure**

Run:

```bash
composer run test:php -- --filter 'PromptGuidanceTest|TemplatePromptTest|TemplatePartPromptTest|StylePromptTest|PromptBudgetTest'
```

Expected: FAIL because the block/template/template-part prompt builders do not yet add the new section.

- [x] **Step 5: Add prompt sections**

In each prompt builder, import `FlavorAgent\Support\DesignSemantics`, normalize existing context with `DesignSemantics::normalize()`, and format it with `DesignSemantics::format_prompt_lines( ..., 80 )`.

Use section key `design_semantics` and heading. The `80` cap belongs in `format_prompt_lines()`; the third `add_section()` argument is only the section priority:

```php
$design_semantics = DesignSemantics::normalize(
	$context['designSemantics'] ?? [],
	'template'
);

$design_semantic_lines = DesignSemantics::format_prompt_lines(
	$design_semantics,
	80
);

if ( ! empty( $design_semantic_lines ) ) {
	$budget->add_section(
		'design_semantics',
		"## Design semantic context\n" . implode( "\n", $design_semantic_lines ),
		58
	);
}
```

Use surface `block` in `Prompt::build_user()`, `template` in `TemplatePrompt::build_user()`, and `template-part` in `TemplatePartPrompt::build_user()`. Priority `58` keeps the section above low-value supplemental context such as theme tokens, docs guidance, viewport summaries, and optional pattern lists, while keeping it below identity, constraints, operation examples, insertion targets, parent context, and primary structure sections. Do not use priority `72`; in the live prompt builders that would rank semantic hints above block structural branches, template structure summaries, and template-part structural constraints.

Keep `StylePrompt.php` on its existing `designSemantics` path. Do not duplicate the style prompt formatter unless the shared formatter can replace existing output without changing current style prompt tests.

- [x] **Step 6: Add budget assertions**

Add or update prompt budget tests for two separate guarantees:

1. `DesignSemantics::format_prompt_lines( $semantics, 80 )` keeps the rendered semantic body under 80 estimated tokens.
2. `PromptBudget` still preserves identity/context sections when a verbose prompt must trim lower-priority sections.

Do not set the filter to `220`; `PromptBudget` clamps any positive budget to a 2000-token minimum. Use the minimum directly and make the fixture verbose enough to exceed it:

```php
add_filter(
	'flavor_agent_prompt_budget_max_tokens',
	static fn(): int => 2000
);
```

Then build each new prompt with a verbose `designSemantics` payload plus enough low-priority context to trigger trimming. Assert the design semantic section is either present with an 80-token-capped body or omitted by `PromptBudget`, and assert higher-priority identity, constraints, operation examples, insertion targets, parent context, and primary structure sections remain present.

- [x] **Step 7: Run prompt tests to green**

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

- [x] **Step 1: Add block resolved signature coverage**

In `tests/phpunit/BlockAbilitiesTest.php`, call `BlockAbilities::recommend_block()` twice with `resolveSignatureOnly: true` and identical context except for `editorContext.designSemantics.sectionRole`.

Assert:

```php
$this->assertNotSame(
	$first['resolvedContextSignature'],
	$second['resolvedContextSignature']
);
```

Also assert an object-key reorder inside `designSemantics` does not change the signature.

- [x] **Step 2: Add template and template-part signature coverage**

In `tests/phpunit/TemplateAbilitiesTest.php`, add one test for `recommend_template()` and one for `recommend_template_part()` using `resolveSignatureOnly: true`.

For each surface:

- Same normalized semantic payload with different object-key order yields the same resolved signature.
- Different `sectionRole`, `contrastContext`, or `layoutRhythm` yields a different resolved signature.
- Different semantic payload changes the review context signature because review/apply freshness must fail when design role, rhythm, or contrast shifts.
- The review-signature assertion must compare the returned `reviewContextSignature`, not only `resolvedContextSignature`; this proves `designSemantics` was threaded into the `$review_context` payload used by `build_template_review_context_signature()` and `build_template_part_review_context_signature()`.

- [x] **Step 3: Keep generic signature helper behavior stable**

In `tests/phpunit/RecommendationSignatureTest.php`, add a small payload-level test showing nested `designSemantics` values are handled by the existing signature helpers through canonical payload encoding. This protects against future changes that strip semantic fields before hashing.

- [x] **Step 4: Verify style signature regression remains green**

Run the existing style signature test:

```bash
npm run test:unit -- --runInBand src/utils/__tests__/style-operations.test.js
```

Expected: PASS, including `buildGlobalStylesRecommendationContextSignature changes when design semantic context changes`.

- [x] **Step 5: Run all freshness tests**

Run:

```bash
composer run test:php -- --filter 'BlockAbilitiesTest|TemplateAbilitiesTest|RecommendationSignatureTest'
npm run test:unit -- --runInBand src/utils/__tests__/block-recommendation-context.test.js src/templates/__tests__/template-recommender-helpers.test.js src/template-parts/__tests__/template-part-recommender-helpers.test.js src/utils/__tests__/style-operations.test.js
```

Expected: PASS.

## Task 7: Add Phase 2 Prompt Wiring And Parser Metrics Gate

**Files:**

- Create: `tests/phpunit/fixtures/recommendation-evaluation-phase-2-parser-fixtures.php`
- Modify: `tests/phpunit/RecommendationEvaluationTest.php`

- [x] **Step 1: Add parser-backed semantic fixtures**

Create `tests/phpunit/fixtures/recommendation-evaluation-phase-2-parser-fixtures.php` with a small provider-free fixture set that uses the same parser-fixture shape as `recommendation-evaluation-parser-fixtures.php`. Do not add Phase 2 fixtures that only contain pre-normalized `suggestions`; static suggestion fixtures do not exercise the real prompt parsers and can pass even when parser behavior regresses. Fixture responses must also obey the current strict ranking contract: use `ranking` as `null` when no structured ranking object is needed, or include the full object shape (`score`, `reason`, `sourceSignals`, `designPrinciple`, and `risk`) with `null` placeholders for unknown values.

These parser fixtures are not sufficient by themselves to prove block `designSemantics` prompt wiring. The current block parser entrypoint accepts only the raw model response, while template and template-part parsers accept context for validation. The Phase 2 gate must therefore assert prompt construction separately before evaluating parser metrics.

```php
<?php

declare(strict_types=1);

return [
	'block_semantics_parent_contrast_constraint' => [
		'surface'         => 'block',
		'alreadyGood'     => true,
		'parser'          => 'block',
		'lane'            => 'block',
		'context'         => [
			'block'           => [
				'name' => 'core/paragraph',
			],
			'designSemantics' => [
				'surface'         => 'block',
				'sectionRole'     => 'footer',
				'contrastContext' => 'dark-parent',
				'mainDesignIssue' => 'none',
				'negativeSignals' => [ 'parent-already-supplies-contrast' ],
			],
		],
		'response'        => [
			'block' => [],
		],
		'expectedMetrics' => [
			'fixtures'             => 1,
			'suggestions'          => 0,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
	],
	'template_semantics_no_invalid_operations' => [
		'surface'                  => 'template',
		'alreadyGood'              => false,
		'parser'                   => 'template',
		'rankedMetricProbe'        => true,
		'context'                  => [
			'templateRef'     => 'twentytwentyfive//archive',
			'templateType'    => 'archive',
			'patterns'        => [
				[
					'name'  => 'twentytwentyfive/query-card',
					'title' => 'Query Card',
				],
			],
			'designSemantics' => [
				'surface'         => 'template',
				'sectionRole'     => 'archive-list',
				'layoutRhythm'    => 'grid',
				'mainDesignIssue' => 'rhythm',
			],
		],
		'response'                 => [
			'suggestions' => [
				[
					'label'              => 'Use query-card rhythm',
					'description'        => 'Preserve the archive grid rhythm with existing query-card patterns.',
					'operations'         => [],
					'patternSuggestions' => [ 'twentytwentyfive/query-card' ],
					'ranking'            => null,
				],
			],
		],
		'expectedMetrics'          => [
			'fixtures'             => 1,
			'suggestions'          => 1,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
		'expectedTopRankedMetrics' => [
			'fixtures'             => 1,
			'suggestions'          => 1,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
	],
	'template_part_semantics_no_invalid_operations' => [
		'surface'                  => 'template-part',
		'alreadyGood'              => false,
		'parser'                   => 'template_part',
		'rankedMetricProbe'        => true,
		'context'                  => [
			'templatePartRef' => 'twentytwentyfive//footer',
			'slug'            => 'footer',
			'area'            => 'footer',
			'patterns'        => [
				[
					'name'  => 'twentytwentyfive/footer',
					'title' => 'Footer',
				],
			],
			'designSemantics' => [
				'surface'         => 'template-part',
				'sectionRole'     => 'footer',
				'contrastContext' => 'dark-parent',
				'mainDesignIssue' => 'contrast',
				'templatePart'    => [
					'ref'  => 'twentytwentyfive//footer',
					'slug' => 'footer',
					'area' => 'footer',
				],
			],
		],
		'response'                 => [
			'suggestions' => [
				[
					'label'              => 'Preserve footer contrast',
					'description'        => 'Use footer-safe pattern guidance without emitting invalid operations.',
					'operations'         => [],
					'patternSuggestions' => [ 'twentytwentyfive/footer' ],
					'ranking'            => null,
				],
			],
		],
		'expectedMetrics'          => [
			'fixtures'             => 1,
			'suggestions'          => 1,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
		'expectedTopRankedMetrics' => [
			'fixtures'             => 1,
			'suggestions'          => 1,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
	],
];
```

- [x] **Step 2: Add prompt-wiring assertions for the Phase 2 semantic fixtures**

In `tests/phpunit/RecommendationEvaluationTest.php`, add a helper that builds the prompt for each Phase 2 fixture and proves the semantic prompt section is present. This closes the gap where `Prompt::parse_response()` for block fixtures cannot prove context wiring because it does not accept a context argument.

```php
/**
 * @param array<string, mixed> $fixture
 */
private static function assert_phase_two_prompt_includes_design_semantics( string $name, array $fixture ): void {
	$parser  = is_string( $fixture['parser'] ?? null ) ? $fixture['parser'] : '';
	$context = is_array( $fixture['context'] ?? null ) ? $fixture['context'] : [];

	self::assertIsArray(
		$context['designSemantics'] ?? null,
		"{$name} must include designSemantics context."
	);

	$prompt = match ( $parser ) {
		'block'         => Prompt::build_user( $context, '' ),
		'template'      => TemplatePrompt::build_user( $context, '' ),
		'template_part' => TemplatePartPrompt::build_user( $context, '' ),
		default         => self::fail( "Unsupported Phase 2 prompt fixture: {$parser}" ),
	};

	self::assertStringContainsString( '## Design semantic context', $prompt, $name );

	if ( is_string( $context['designSemantics']['sectionRole'] ?? null ) ) {
		self::assertStringContainsString(
			'Role: ' . $context['designSemantics']['sectionRole'],
			$prompt,
			$name
		);
	}

	if ( ! empty( $context['designSemantics']['negativeSignals'] ) ) {
		self::assertStringContainsString( 'Negative signals:', $prompt, $name );
	}
}
```

Call the helper inside the new Phase 2 test before materializing each parser fixture:

```php
$materialized_fixtures = [];

foreach ( $fixtures as $name => $fixture ) {
	$this->assertIsArray( $fixture );
	self::assert_phase_two_prompt_includes_design_semantics(
		is_string( $name ) ? $name : 'phase_2_fixture',
		$fixture
	);

	$materialized_fixtures[] = self::materialize_parser_fixture( $fixture );
}
```

- [x] **Step 3: Add parser-backed metric assertions**

In `tests/phpunit/RecommendationEvaluationTest.php`, add a test that loads the Phase 2 parser fixtures, materializes each fixture through the existing `materialize_parser_fixture()` helper, and evaluates the materialized output with the same `evaluate()` helper used by Phase 0. This must execute `Prompt::parse_response()`, `TemplatePrompt::parse_response()`, and `TemplatePartPrompt::parse_response()` through `parse_fixture_response()`.

For each fixture, assert its recorded metrics match the materialized parser output:

```php
$materialized = self::materialize_parser_fixture( $fixture );

$this->assertSame(
	self::normalize_expected_metrics( $fixture['expectedMetrics'] ),
	self::round_metric_values( self::evaluate( [ $materialized ] ) )
);
```

When `rankedMetricProbe` is true, also assert `expectedTopRankedMetrics` against `self::top_ranked_fixture( $materialized )`, matching the Phase 0 parser-backed gate.

Add an aggregate assertion over all materialized Phase 2 parser fixtures:

```php
$metrics = self::round_metric_values( self::evaluate( $materialized_fixtures ) );

$this->assertSame( 0.0, $metrics['invalidOperationRate'] );
$this->assertSame( 0.0, $metrics['noiseRate'] );
```

Also assert the original Phase 0 fixture baseline remains unchanged after parser-backed semantic fixtures are loaded in their own test:

```php
$this->assertSame( 0.3333, $baseline['invalidOperationRate'] );
$this->assertSame( 1.0, $baseline['noiseRate'] );
```

- [x] **Step 4: Run metrics gate**

Run:

```bash
composer run test:php -- --filter RecommendationEvaluationTest
```

Expected: PASS with Phase 2 prompt construction proving the `## Design semantic context` section is wired, Phase 0 baseline unchanged, and Phase 2 parser-backed semantic fixtures recording no increase in `noiseRate` or `invalidOperationRate`.

## Task 8: Final Verification

**Files:**

- All files listed above.

- [x] **Step 1: Run targeted JS tests**

Run:

```bash
npm run test:unit -- --runInBand src/utils/__tests__/recommendation-design-semantics.test.js src/context/__tests__/collector.test.js src/utils/__tests__/block-recommendation-context.test.js src/templates/__tests__/template-recommender-helpers.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/template-part-recommender-helpers.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/utils/__tests__/style-operations.test.js
```

Expected: PASS.

- [x] **Step 2: Run targeted PHP tests**

Run:

```bash
composer run test:php -- --filter 'DesignSemanticsTest|RegistrationTest|RecommendationEvaluationTest|BlockAbilitiesTest|TemplateAbilitiesTest|RecommendationSignatureTest|PromptGuidanceTest|StylePromptTest|TemplatePromptTest|TemplatePartPromptTest|PromptBudgetTest'
```

Expected: PASS.

- [x] **Step 3: Run docs check**

Run:

```bash
npm run check:docs
```

Expected: PASS.

- [x] **Step 4: Run fast aggregate verification**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: PASS or a clearly reported pre-existing blocker unrelated to Phase 2. If a blocker appears, record the failing step, command, and log path from `output/verify/summary.json`.

- [x] **Step 5: Run targeted browser release evidence**

This change crosses block, template, template-part, shared freshness, ability schemas, and prompt taxonomy, so it triggers `docs/reference/cross-surface-validation-gates.md` Gates 1, 3, 5, and 7. Run both harnesses unless the operator records an explicit blocker or waiver:

```bash
npm run test:e2e:playground
npm run test:e2e:wp70
```

Expected:

- `npm run test:e2e:playground` passes block Inspector stale/fresh behavior and post-editor recommendation smoke coverage.
- `npm run test:e2e:wp70` passes Site Editor template/template-part freshness, review/apply, and activity coverage.
- If either harness is known-red or unavailable, record the command, failing spec or environment blocker, log/artifact path, and explicit waiver reason before treating the implementation as complete.

- [x] **Step 6: Check whitespace and generated artifacts**

Run:

```bash
git diff --check
git status --short
```

Expected:

- `git diff --check` reports no whitespace errors.
- `build/` and `dist/` are unchanged.
- Staged or modified files are limited to the Phase 2 file list unless the operator explicitly expanded scope.

## Task 9: Remediate Review Finding 1 - Runtime Template Slot Shape

**Finding:** `buildTemplateDesignSemantics()` currently reads `editorSlots.assignedTemplateParts` and `editorSlots.templateType`, but the live template panel passes the runtime slot snapshot from `buildEditorTemplateSlotSnapshot()`, which exposes `assignedParts`, `emptyAreas`, and `allowedAreas`. `TemplateRecommender` also already has the real `templateType` but does not pass it into the semantic builder. Live requests can therefore send `template.templateType: ""`, `template.hasHeader: false`, and `template.hasFooter: false` in `designSemantics` for templates that actually have header/footer parts.

**Files:**

- Modify: `src/utils/recommendation-design-semantics.js`
- Modify: `src/utils/__tests__/recommendation-design-semantics.test.js`
- Modify: `src/templates/TemplateRecommender.js`
- Modify: `src/templates/__tests__/TemplateRecommender.test.js`

- [x] **Step 1: Add a failing utility regression for the runtime slot shape**

In `src/utils/__tests__/recommendation-design-semantics.test.js`, add this test next to the existing template semantic test:

```js
test( 'buildTemplateDesignSemantics reads runtime assigned parts and explicit template type', () => {
	const semantics = buildTemplateDesignSemantics( {
		templateType: 'archive',
		editorSlots: {
			assignedParts: [
				{ slug: 'site-header', area: 'header' },
				{ slug: 'site-footer', area: 'footer' },
			],
			emptyAreas: [],
			allowedAreas: [ 'header', 'footer' ],
		},
		editorStructure: {
			topLevelBlockTree: [
				{ name: 'core/template-part' },
				{ name: 'core/query' },
				{ name: 'core/template-part' },
			],
			structureStats: {
				blockCount: 9,
				hasQuery: true,
				hasSingleWrapperGroup: false,
			},
		},
		visiblePatternNames: [ 'twentytwentyfive/query-card' ],
	} );

	expect( semantics ).toMatchObject( {
		surface: 'template',
		sectionRole: 'archive-list',
		layoutRhythm: 'grid',
		template: {
			templateType: 'archive',
			hasHeader: true,
			hasFooter: true,
			visiblePatternCount: 1,
		},
	} );
} );
```

Run:

```bash
npm run test:unit -- --runTestsByPath src/utils/__tests__/recommendation-design-semantics.test.js --runInBand
```

Expected: FAIL because `buildTemplateDesignSemantics()` ignores `assignedParts` and the explicit `templateType`.

- [x] **Step 2: Update the semantic builder to accept real template inputs**

In `src/utils/recommendation-design-semantics.js`, change the template builder signature and assigned-part normalization:

```js
function hasAssignedPartArea( assignedParts = [], area = '' ) {
	return Array.isArray( assignedParts )
		? assignedParts.some( ( part ) => cleanSlug( part?.area ) === area )
		: false;
}

export function buildTemplateDesignSemantics( {
	templateType,
	editorSlots,
	editorStructure,
	visiblePatternNames,
} = {} ) {
	const slots = isPlainObject( editorSlots ) ? editorSlots : {};
	const structure = isPlainObject( editorStructure ) ? editorStructure : {};
	const stats = getStructureStats( structure );
	const topLevelBlocks = getTopLevelBlocks( structure );
	const patterns = uniqueSortedStrings( visiblePatternNames );
	const assignedParts = Array.isArray( slots.assignedParts )
		? slots.assignedParts
		: [];
	const normalizedTemplateType =
		cleanString( templateType ) ||
		cleanString( slots.templateType ) ||
		cleanString( slots.type ) ||
		cleanString( slots.slug );

	return normalizeDesignSemantics( {
		surface: 'template',
		sectionRole: deriveTemplateSectionRole( {
			templateType: normalizedTemplateType,
			stats,
			topLevelBlocks,
		} ),
		visualDensity: deriveVisualDensityFromCount( stats.blockCount ),
		contrastContext: 'unknown',
		layoutRhythm: deriveTemplateRhythm( {
			stats,
			topLevelBlocks,
			visiblePatternNames: patterns,
		} ),
		typographyRole: 'unknown',
		tokenAffinity: {},
		existingDesignScore: 0,
		mainDesignIssue: stats.hasQuery ? 'rhythm' : 'unknown',
		negativeSignals: patterns.length === 0 ? [ 'no-visible-patterns' ] : [],
		template: {
			templateType: normalizedTemplateType,
			hasHeader: hasAssignedPartArea( assignedParts, 'header' ),
			hasFooter: hasAssignedPartArea( assignedParts, 'footer' ),
			blockCount: Number.isFinite( Number( stats.blockCount ) )
				? Number( stats.blockCount )
				: 0,
			topLevelBlockCount: topLevelBlocks.length,
			visiblePatternCount: patterns.length,
			hasQuery: Boolean( stats.hasQuery ),
		},
	} );
}
```

- [x] **Step 3: Pass the real `templateType` from the template panel**

In `src/templates/TemplateRecommender.js`, update the `designSemantics` memo:

```js
const designSemantics = useMemo(
	() =>
		buildTemplateDesignSemantics( {
			templateType,
			editorSlots,
			editorStructure,
			visiblePatternNames,
		} ),
	[ editorSlots, editorStructure, templateType, visiblePatternNames ]
);
```

- [x] **Step 4: Strengthen the mounted template panel request test**

In `src/templates/__tests__/TemplateRecommender.test.js`, extend the existing `mockFetchTemplateRecommendations` assertion so it proves the live request sends non-empty template identity and runtime slot-derived header/footer semantics:

```js
expect( mockFetchTemplateRecommendations ).toHaveBeenCalledWith(
	expect.objectContaining( {
		templateRef: TEMPLATE_REF,
		designSemantics: expect.objectContaining( {
			surface: 'template',
			template: expect.objectContaining( {
				templateType: 'home',
				hasHeader: true,
				hasFooter: true,
				visiblePatternCount: 2,
			} ),
		} ),
	} )
);
```

If the fixture template type is not `home`, use the actual `normalizeTemplateType( TEMPLATE_REF )` value already produced by the test fixture instead of hard-coding a different slug.

- [x] **Step 5: Run targeted JS tests**

Run:

```bash
npm run test:unit -- --runTestsByPath src/utils/__tests__/recommendation-design-semantics.test.js src/templates/__tests__/TemplateRecommender.test.js src/templates/__tests__/template-recommender-helpers.test.js --runInBand
```

Expected: PASS.

## Task 10: Remediate Review Finding 2 - Defensive PHP Semantic Normalization

**Finding:** `DesignSemantics::normalize_enum()` casts arbitrary `mixed` input directly to string. Because `designSemantics` is intentionally exposed as an open object in ability schemas, malformed enum leaves such as arrays or objects must fall back to `unknown` or the surface fallback without warnings or fatal conversion errors.

**Files:**

- Modify: `inc/Support/DesignSemantics.php`
- Modify: `tests/phpunit/DesignSemanticsTest.php`

- [x] **Step 1: Add a failing warning/fatal regression**

In `tests/phpunit/DesignSemanticsTest.php`, add:

```php
public function test_normalizes_malformed_enum_values_without_warnings_or_fatals(): void {
	set_error_handler(
		static function ( int $severity, string $message, string $file, int $line ): bool {
			throw new \ErrorException( $message, 0, $severity, $file, $line );
		}
	);

	try {
		$normalized = DesignSemantics::normalize(
			[
				'surface'         => [ 'template' ],
				'sectionRole'     => [ 'footer' ],
				'visualDensity'   => new \stdClass(),
				'contrastContext' => [ 'dark-parent' ],
				'layoutRhythm'    => new \stdClass(),
				'typographyRole'  => [ 'body' ],
				'mainDesignIssue' => new \stdClass(),
			],
			'template'
		);
	} finally {
		restore_error_handler();
	}

	$this->assertSame( 'template', $normalized['surface'] );
	$this->assertSame( 'unknown', $normalized['sectionRole'] );
	$this->assertSame( 'unknown', $normalized['visualDensity'] );
	$this->assertSame( 'unknown', $normalized['contrastContext'] );
	$this->assertSame( 'unknown', $normalized['layoutRhythm'] );
	$this->assertSame( 'unknown', $normalized['typographyRole'] );
	$this->assertSame( 'unknown', $normalized['mainDesignIssue'] );
}
```

Run:

```bash
composer run test:php -- --filter DesignSemanticsTest
```

Expected: FAIL on the current direct cast in `DesignSemantics::normalize_enum()`.

- [x] **Step 2: Harden enum normalization**

In `inc/Support/DesignSemantics.php`, replace `normalize_enum()` with a scalar-safe version:

```php
private static function normalize_enum(
	mixed $value,
	array $allowed,
	string $fallback = 'unknown'
): string {
	if ( ! is_scalar( $value ) && null !== $value ) {
		return $fallback;
	}

	$normalized = strtolower( sanitize_text_field( (string) $value ) );

	return in_array( $normalized, $allowed, true )
		? $normalized
		: $fallback;
}
```

Keep `normalize_string_list()` as the list sanitizer for `tokenAffinity` and `negativeSignals`; it already skips non-scalar entries.

- [x] **Step 3: Run targeted PHP tests**

Run:

```bash
composer run test:php -- --filter 'DesignSemanticsTest|BlockAbilitiesTest|TemplateAbilitiesTest|PromptGuidanceTest|TemplatePromptTest|TemplatePartPromptTest|PromptBudgetTest'
```

Expected: PASS.

## Task 11: Reconcile The Plan And Acceptance Criteria

**Files:**

- Modify: `docs/reference/2026-05-19-recommendation-phase-2-design-semantics-plan.md`

- [x] **Step 1: Update the existing template semantic examples**

Confirm every template semantic helper example uses the runtime slot shape from `buildEditorTemplateSlotSnapshot()`:

```js
templateType: 'archive',
editorSlots: {
	assignedParts: [
		{ slug: 'header', area: 'header' },
		{ slug: 'footer', area: 'footer' },
	],
	emptyAreas: [],
	allowedAreas: [ 'header', 'footer' ],
},
```

- [x] **Step 2: Add the new defensive-normalization requirement to Definition Of Done**

Ensure the Definition Of Done includes both of these bullets:

```markdown
- Template semantic payloads derive `template.templateType`, `template.hasHeader`, and `template.hasFooter` from the same runtime inputs used by `TemplateRecommender`.
- Malformed open-object `designSemantics` enum leaves fall back without PHP warnings, fatal conversion errors, or schema weakening.
```

- [x] **Step 3: Run docs validation**

Run:

```bash
npm run check:docs
git diff --check -- docs/reference/2026-05-19-recommendation-phase-2-design-semantics-plan.md
```

Expected: PASS with no markdown freshness or whitespace errors.

## Task 12: Final Verification After Review-Finding Remediation

**Files:**

- `src/utils/recommendation-design-semantics.js`
- `src/utils/__tests__/recommendation-design-semantics.test.js`
- `src/templates/TemplateRecommender.js`
- `src/templates/__tests__/TemplateRecommender.test.js`
- `inc/Support/DesignSemantics.php`
- `tests/phpunit/DesignSemanticsTest.php`
- `docs/reference/2026-05-19-recommendation-phase-2-design-semantics-plan.md`

- [x] **Step 1: Run focused JS verification**

Run:

```bash
npm run test:unit -- --runTestsByPath src/utils/__tests__/recommendation-design-semantics.test.js src/templates/__tests__/TemplateRecommender.test.js src/templates/__tests__/template-recommender-helpers.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/template-parts/__tests__/template-part-recommender-helpers.test.js src/utils/__tests__/block-recommendation-context.test.js --runInBand
```

Expected: PASS.

- [x] **Step 2: Run focused PHP verification**

Run:

```bash
composer run test:php -- --filter 'DesignSemanticsTest|RegistrationTest|RecommendationEvaluationTest|BlockAbilitiesTest|TemplateAbilitiesTest|RecommendationSignatureTest|PromptGuidanceTest|TemplatePromptTest|TemplatePartPromptTest|PromptBudgetTest'
```

Expected: PASS.

- [x] **Step 3: Run lint and docs gates**

Run:

```bash
npm run lint:js -- src/
composer run lint:php
npm run check:docs
git diff --check
```

Expected: PASS. If repo-wide lint exposes unrelated pre-existing failures, record the exact command, file, and failure text, then rerun the nearest scoped lint gate for the files changed by Tasks 9-11.

- [x] **Step 4: Run aggregate fast verification**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: PASS. If the aggregate verifier is blocked by local environment prerequisites, record the final `VERIFY_RESULT={...}` line and the failing log path under `output/verify/summary.json`.

- [x] **Step 5: Run or explicitly waive browser release evidence**

Because this work still crosses block, template, template-part, shared signatures, and ability schemas, run:

```bash
npm run test:e2e:playground
npm run test:e2e:wp70
```

Expected: PASS. If a harness is unavailable or known-red, record the command, failing spec or environment blocker, artifact/log path, and explicit waiver reason before marking the remediation complete.

## Definition Of Done

- Block, template, and template-part recommendation requests include normalized `designSemantics`.
- Style recommendations keep using the existing `styleContext.designSemantics` path.
- Template semantic payloads derive `template.templateType`, `template.hasHeader`, and `template.hasFooter` from the same runtime inputs used by `TemplateRecommender`.
- Malformed open-object `designSemantics` enum leaves fall back without PHP warnings, fatal conversion errors, or schema weakening.
- New prompt sections use the exact heading `## Design semantic context`.
- New prompt section bodies are capped by `DesignSemantics::format_prompt_lines()` before `PromptBudget` assembly and do not displace identity/context sections under a constrained budget.
- Local JS signatures and server resolved/review signatures stale when semantic role, rhythm, or contrast changes.
- Object-key reordering inside semantic payloads does not change signatures.
- Phase 0 metrics remain unchanged.
- Phase 2 prompt-wiring assertions prove the semantic prompt section is present for block, template, and template-part fixtures before parser metrics are evaluated.
- Phase 2 parser-backed semantic fixtures record no higher `noiseRate` and no higher `invalidOperationRate`.
- `npm run check:docs` passes.
- `npm run verify -- --skip-e2e` passes or reports an unrelated existing blocker with evidence.
- `npm run test:e2e:playground` and `npm run test:e2e:wp70` pass, or each unavailable/known-red harness has a recorded blocker or explicit waiver with log/artifact paths.
