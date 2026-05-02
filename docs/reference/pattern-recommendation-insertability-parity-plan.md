# Pattern Recommendation Insertability Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the pattern inserter shelf, badge count, badge tooltip, and ready-empty message use the same insertability contract.

**Architecture:** Extract pattern block resolution and top-level insertability filtering from `PatternRecommender` into a shared pattern helper. `PatternRecommender` and `InserterBadge` will both build allowed recommendation pairs, then filter those pairs through the same `canInsertBlockType()` checks for the active inserter root. The ready-empty state will distinguish "not exposed by Gutenberg" from "exposed but not insertable here."

**Tech Stack:** WordPress block editor selectors, `@wordpress/blocks`, `@wordpress/data`, React hooks through `@wordpress/element`, Jest via `@wordpress/scripts`.

---

## Confirmed Finding

The uncommitted change filters rejected recommendations in `src/patterns/PatternRecommender.js`, but `src/patterns/InserterBadge.js` still counts the pre-filtered recommendation list. When a recommendation is exposed by `getAllowedPatterns()` but rejected by `canInsertBlockType()`, the badge can show a ready count while the shelf renders no item. The ready-empty message also says Gutenberg is not exposing the pattern, even though Gutenberg exposed it and the new insertability check rejected it.

## File Structure

- Create: `src/patterns/pattern-insertability.js`
  - Owns pattern-to-block resolution and shared top-level insertability filtering.
- Create: `src/patterns/__tests__/pattern-insertability.test.js`
  - Pins helper behavior independently from DOM-mounted shelf and badge tests.
- Modify: `src/patterns/PatternRecommender.js`
  - Removes local insertability helpers, imports shared helpers, and sends matched/insertable counts to the empty-state copy helper.
- Modify: `src/patterns/__tests__/PatternRecommender.test.js`
  - Adds the ready-empty regression for exposed-but-not-insertable recommendations.
- Modify: `src/patterns/InserterBadge.js`
  - Filters badge-ready recommendations through the same shared insertability helper.
- Modify: `src/patterns/__tests__/InserterBadge.test.js`
  - Adds `canInsertBlockType()` to the block-editor selector mock and pins the hidden-badge regression.

## Task 1: Extract Shared Insertability Helper

**Files:**
- Create: `src/patterns/pattern-insertability.js`
- Create: `src/patterns/__tests__/pattern-insertability.test.js`

- [ ] **Step 1: Write the failing helper tests**

Create `src/patterns/__tests__/pattern-insertability.test.js`:

```js
import {
	filterInsertableRecommendedPatterns,
	getRejectedPatternBlockNames,
	resolvePatternBlocks,
} from '../pattern-insertability';

describe( 'resolvePatternBlocks', () => {
	test( 'resolves synced user patterns to a core/block reference', () => {
		expect(
			resolvePatternBlocks( {
				type: 'user',
				syncStatus: 'fully',
				id: 77,
			} )[ 0 ]
		).toMatchObject( {
			name: 'core/block',
			attributes: {
				ref: 77,
			},
		} );
	} );

	test( 'uses already parsed pattern blocks before parsing content', () => {
		const blocks = [ { name: 'core/paragraph', attributes: {} } ];

		expect( resolvePatternBlocks( { blocks } ) ).toBe( blocks );
	} );
} );

describe( 'getRejectedPatternBlockNames', () => {
	test( 'returns rejected top-level block names for the inserter root', () => {
		const blockEditor = {
			canInsertBlockType: jest.fn(
				( blockName ) => blockName !== 'core/template-part'
			),
		};
		const pattern = {
			name: 'theme/template',
			blocks: [
				{ name: 'core/template-part', attributes: { slug: 'header' } },
				{ name: 'core/group', attributes: {} },
				{ name: 'core/template-part', attributes: { slug: 'footer' } },
			],
		};

		expect(
			getRejectedPatternBlockNames( pattern, 'root-a', blockEditor )
		).toEqual( [ 'core/template-part', 'core/template-part' ] );
		expect( blockEditor.canInsertBlockType ).toHaveBeenCalledWith(
			'core/template-part',
			'root-a'
		);
	} );
} );

describe( 'filterInsertableRecommendedPatterns', () => {
	test( 'keeps only recommendation pairs insertable at the active root', () => {
		const rejectedPair = {
			pattern: {
				name: 'theme/template',
				blocks: [ { name: 'core/template-part', attributes: {} } ],
			},
			recommendation: { name: 'theme/template', reason: 'Template.' },
		};
		const acceptedPair = {
			pattern: {
				name: 'theme/hero',
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
			recommendation: { name: 'theme/hero', reason: 'Hero.' },
		};
		const blockEditor = {
			canInsertBlockType: jest.fn(
				( blockName ) => blockName !== 'core/template-part'
			),
		};

		expect(
			filterInsertableRecommendedPatterns(
				[ rejectedPair, acceptedPair ],
				'root-a',
				blockEditor
			)
		).toEqual( [ acceptedPair ] );
	} );
} );
```

- [ ] **Step 2: Run the helper test to verify it fails**

Run:

```bash
npm run test:unit -- src/patterns/__tests__/pattern-insertability.test.js --runInBand
```

Expected: FAIL because `src/patterns/pattern-insertability.js` does not exist yet.

- [ ] **Step 3: Add the shared helper implementation**

Create `src/patterns/pattern-insertability.js`:

```js
import { createBlock, parse } from '@wordpress/blocks';

export function resolvePatternBlocks( pattern ) {
	if (
		pattern?.type === 'user' &&
		pattern?.syncStatus !== 'unsynced' &&
		pattern?.id
	) {
		return [ createBlock( 'core/block', { ref: pattern.id } ) ];
	}

	if ( Array.isArray( pattern?.blocks ) && pattern.blocks.length > 0 ) {
		return pattern.blocks;
	}

	if ( typeof pattern?.content === 'string' && pattern.content.trim() ) {
		try {
			return parse( pattern.content );
		} catch ( error ) {
			return [];
		}
	}

	return [];
}

export function getRejectedPatternBlockNames(
	pattern,
	rootClientId,
	blockEditor
) {
	if ( typeof blockEditor?.canInsertBlockType !== 'function' ) {
		return [];
	}

	const rejected = [];

	for ( const block of resolvePatternBlocks( pattern ) ) {
		if ( ! block?.name ) {
			continue;
		}

		if (
			! blockEditor.canInsertBlockType(
				block.name,
				rootClientId ?? null
			)
		) {
			rejected.push( block.name );
		}
	}

	return rejected;
}

export function filterInsertableRecommendedPatterns(
	recommendedPatterns,
	rootClientId,
	blockEditor
) {
	if ( ! Array.isArray( recommendedPatterns ) ) {
		return [];
	}

	return recommendedPatterns.filter(
		( { pattern } ) =>
			getRejectedPatternBlockNames(
				pattern,
				rootClientId,
				blockEditor
			).length === 0
	);
}
```

- [ ] **Step 4: Run the helper test to verify it passes**

Run:

```bash
npm run test:unit -- src/patterns/__tests__/pattern-insertability.test.js --runInBand
```

Expected: PASS.

## Task 2: Rewire PatternRecommender And Empty-State Copy

**Files:**
- Modify: `src/patterns/PatternRecommender.js`
- Modify: `src/patterns/__tests__/PatternRecommender.test.js`

- [ ] **Step 1: Add the failing ready-empty regression**

In `src/patterns/__tests__/PatternRecommender.test.js`, add this test near the existing insertability tests:

```js
test( 'explains when allowed recommendations are rejected by insertability checks', () => {
	const inserterContainer = document.createElement( 'div' );
	const blockedPattern = {
		name: 'theme/template-with-parts',
		title: 'Template with parts',
		blocks: [
			{ name: 'core/template-part', attributes: { slug: 'header' } },
		],
	};

	inserterContainer.className = 'block-editor-inserter__panel-content';
	document.body.appendChild( inserterContainer );
	state.store.patternStatus = 'ready';
	state.store.patternRecommendations = [
		{
			name: blockedPattern.name,
			score: 0.96,
			reason: 'Strong template match.',
		},
	];
	state.allowedPatterns = [ blockedPattern ];
	mockCanInsertBlockType.mockReturnValue( false );
	mockFindInserterContainer.mockReturnValue( inserterContainer );

	renderComponent();

	expect( document.body.textContent ).toContain(
		'Flavor Agent found ranked patterns, but the matched pattern blocks are not allowed at this insertion point.'
	);
	expect( document.body.textContent ).not.toContain(
		'Gutenberg is not currently exposing those patterns'
	);
	expect(
		inserterContainer.querySelector(
			'.flavor-agent-pattern-shelf__item'
		)
	).toBeNull();
} );
```

- [ ] **Step 2: Run the focused PatternRecommender test to verify it fails**

Run:

```bash
npm run test:unit -- src/patterns/__tests__/PatternRecommender.test.js --runInBand
```

Expected: FAIL because the current ready-empty message still receives only raw recommendations.

- [ ] **Step 3: Import the shared helper and remove local duplicate code**

In `src/patterns/PatternRecommender.js`, change the blocks import:

```js
import { cloneBlock } from '@wordpress/blocks';
```

Add the shared helper import:

```js
import {
	filterInsertableRecommendedPatterns,
	getRejectedPatternBlockNames,
	resolvePatternBlocks,
} from './pattern-insertability';
```

Delete the local `resolvePatternBlocks()` and `getRejectedPatternBlockNames()` definitions from `src/patterns/PatternRecommender.js`.

- [ ] **Step 4: Make the ready-empty message aware of matched versus insertable counts**

Replace `getPatternEmptyMessage()` in `src/patterns/PatternRecommender.js` with:

```js
function getPatternEmptyMessage(
	recommendations,
	diagnostics,
	{
		matchedRecommendationCount = 0,
		insertableRecommendationCount = 0,
	} = {}
) {
	const unreadableMessage = getUnreadableSyncedPatternMessage( diagnostics );

	if ( unreadableMessage ) {
		return unreadableMessage;
	}

	if (
		matchedRecommendationCount > 0 &&
		insertableRecommendationCount === 0
	) {
		return 'Flavor Agent found ranked patterns, but the matched pattern blocks are not allowed at this insertion point.';
	}

	return Array.isArray( recommendations ) && recommendations.length > 0
		? 'Flavor Agent found ranked patterns, but Gutenberg is not currently exposing those patterns for this insertion point.'
		: '';
}
```

Update the recommended-pattern filter:

```js
const recommendedPatterns = useSelect(
	( select ) => {
		const blockEditor = select( blockEditorStore );

		return filterInsertableRecommendedPatterns(
			builtRecommendedPatterns,
			inserterRootClientId,
			blockEditor
		);
	},
	[ builtRecommendedPatterns, inserterRootClientId ]
);
```

Update the ready-empty render call:

```js
message={ getPatternEmptyMessage(
	recommendations,
	patternDiagnostics,
	{
		matchedRecommendationCount: builtRecommendedPatterns.length,
		insertableRecommendationCount: recommendedPatterns.length,
	}
) }
```

- [ ] **Step 5: Run PatternRecommender tests**

Run:

```bash
npm run test:unit -- src/patterns/__tests__/PatternRecommender.test.js --runInBand
```

Expected: PASS.

## Task 3: Rewire InserterBadge To Use The Same Filter

**Files:**
- Modify: `src/patterns/InserterBadge.js`
- Modify: `src/patterns/__tests__/InserterBadge.test.js`

- [ ] **Step 1: Add the failing badge regression**

In `src/patterns/__tests__/InserterBadge.test.js`, add a mock at the top:

```js
const mockCanInsertBlockType = jest.fn();
```

In `beforeEach()`, reset and default it:

```js
mockCanInsertBlockType.mockReset();
mockCanInsertBlockType.mockReturnValue( true );
```

In `setSelectState()`, return it from the `core/block-editor` selector:

```js
canInsertBlockType: ( ...args ) => mockCanInsertBlockType( ...args ),
```

Add this test near the existing ready-count tests:

```js
test( 'hides ready badge when allowed matches are not insertable at the active root', () => {
	const anchor = document.createElement( 'div' );
	const button = document.createElement( 'button' );

	anchor.appendChild( button );
	document.body.appendChild( anchor );
	mockFindInserterToggle.mockReturnValue( button );
	mockCanInsertBlockType.mockImplementation(
		( blockName ) => blockName !== 'core/template-part'
	);
	setSelectState( {
		recommendations: [
			{
				name: 'theme/template-with-parts',
				score: 0.95,
				reason: 'Template match.',
			},
		],
		allowedPatterns: [
			{
				name: 'theme/template-with-parts',
				title: 'Template with parts',
				blocks: [
					{
						name: 'core/template-part',
						attributes: { slug: 'header' },
					},
				],
			},
		],
	} );

	renderComponent();

	expect(
		document.querySelector( '.flavor-agent-inserter-badge--ready' )
	).toBeNull();
	expect( mockFindInserterToggle ).not.toHaveBeenCalled();
	expect( mockCanInsertBlockType ).toHaveBeenCalledWith(
		'core/template-part',
		'root-a'
	);
} );
```

- [ ] **Step 2: Run the focused badge test to verify it fails**

Run:

```bash
npm run test:unit -- src/patterns/__tests__/InserterBadge.test.js --runInBand
```

Expected: FAIL because `InserterBadge` still counts recommendations matched only by `getAllowedPatterns()`.

- [ ] **Step 3: Filter badge recommendations through the shared helper**

In `src/patterns/InserterBadge.js`, import the helper:

```js
import { filterInsertableRecommendedPatterns } from './pattern-insertability';
```

Replace the separate `allowedPatterns` select plus `renderableRecommendations` memo with:

```js
const renderableRecommendations = useSelect(
	( select ) => {
		const editor = select( blockEditorStore );
		const insertionPoint = editor.getBlockInsertionPoint?.() || null;
		const rootClientId = insertionPoint?.rootClientId ?? null;
		const allowedPatterns = getAllowedPatterns( rootClientId, editor );

		return filterInsertableRecommendedPatterns(
			buildRecommendedPatterns(
				patternState.recommendations,
				allowedPatterns
			),
			rootClientId,
			editor
		).map( ( { recommendation } ) => recommendation );
	},
	[ patternState.recommendations ]
);
```

Keep the existing `badgeState` memo:

```js
const badgeState = useMemo(
	() =>
		getInserterBadgeState( {
			status: patternState.status,
			recommendations: renderableRecommendations,
			badge: getPatternBadgeReason( renderableRecommendations ),
			error: patternState.error,
		} ),
	[ patternState.error, patternState.status, renderableRecommendations ]
);
```

- [ ] **Step 4: Run badge tests**

Run:

```bash
npm run test:unit -- src/patterns/__tests__/InserterBadge.test.js --runInBand
```

Expected: PASS.

## Task 4: Final Verification

**Files:**
- Verify: `src/patterns/pattern-insertability.js`
- Verify: `src/patterns/PatternRecommender.js`
- Verify: `src/patterns/InserterBadge.js`
- Verify: `src/patterns/__tests__/pattern-insertability.test.js`
- Verify: `src/patterns/__tests__/PatternRecommender.test.js`
- Verify: `src/patterns/__tests__/InserterBadge.test.js`

- [ ] **Step 1: Run the focused unit suite**

Run:

```bash
npm run test:unit -- --runTestsByPath src/patterns/__tests__/pattern-insertability.test.js src/patterns/__tests__/PatternRecommender.test.js src/patterns/__tests__/InserterBadge.test.js --runInBand
```

Expected: PASS for all three test files.

- [ ] **Step 2: Run JS lint for changed files**

Run:

```bash
npm run lint:js -- src/patterns/pattern-insertability.js src/patterns/PatternRecommender.js src/patterns/InserterBadge.js src/patterns/__tests__/pattern-insertability.test.js src/patterns/__tests__/PatternRecommender.test.js src/patterns/__tests__/InserterBadge.test.js
```

Expected: PASS.

- [ ] **Step 3: Run whitespace checks**

Run:

```bash
git diff --check -- src/patterns/pattern-insertability.js src/patterns/PatternRecommender.js src/patterns/InserterBadge.js src/patterns/__tests__/pattern-insertability.test.js src/patterns/__tests__/PatternRecommender.test.js src/patterns/__tests__/InserterBadge.test.js
```

Expected: no output and exit code 0.

- [ ] **Step 4: Run docs check if this plan remains part of the branch**

Run:

```bash
npm run check:docs
```

Expected: PASS.

- [ ] **Step 5: Commit only if requested**

If the user asks for a commit, run:

```bash
git add src/patterns/pattern-insertability.js src/patterns/PatternRecommender.js src/patterns/InserterBadge.js src/patterns/__tests__/pattern-insertability.test.js src/patterns/__tests__/PatternRecommender.test.js src/patterns/__tests__/InserterBadge.test.js docs/reference/pattern-recommendation-insertability-parity-plan.md
git commit -m "Align pattern insertability across shelf and badge"
```

Expected: one commit containing the shared helper, shelf and badge rewiring, regressions, and this plan.

## Self-Review

- The single confirmed finding is covered by Task 2 for shelf and empty-state behavior and Task 3 for badge behavior.
- The helper is shared so the badge cannot drift from the shelf after this patch.
- Existing synced/user pattern insertion stays covered because `resolvePatternBlocks()` preserves the existing `core/block` reference behavior.
- Existing "not currently exposing" copy remains for raw recommendations that do not match any current `getAllowedPatterns()` item.
- The plan does not expand the browse-only pattern recommendation surface into a deterministic apply/undo system.
