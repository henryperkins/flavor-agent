# Pattern Adapted Preview Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an explicit "Preview adapted" path to the pattern inserter shelf that builds a detached clone of a non-synced recommended pattern, applies only deterministic cosmetic mutations, previews that exact clone in Gutenberg `BlockPreview`, and inserts those same instances after the existing freshness/safety gates still pass — while leaving the direct insert, synced patterns, ranking, retrieval, indexing, ability schemas, and external apply/undo unchanged.

**Architecture:** A new pure module `src/patterns/pattern-adaptation.js` owns the deterministic mutation engine (`buildPatternAdaptationPreview`). A new pure reader `src/patterns/pattern-adaptation-context.js` collects a client-only `adaptationContext` (nearby heading levels + container/sibling alignment) from the live editor at preview time — never sent to ranking. `PatternRecommender.js` gains adapted-preview state, renders a new `PatternAdaptationPreview` component inside the existing inserter portal, and routes both original and adapted insertion through one shared guarded insert helper. Diagnostics for the new events are added to both the JS and PHP outcome allowlists.

**Tech Stack:** `@wordpress/scripts` (webpack/Jest), React via `@wordpress/element`, `@wordpress/block-editor` (`BlockPreview`, block-editor store), `@wordpress/blocks` (`cloneBlock`, block registry), PHP 8.2 PHPUnit, Playwright (Playground smoke).

## Global Constraints

- Requires WordPress 7.0+, PHP 8.2+, Node 24 / npm 11 (Node 20 / npm 10 also supported).
- Build with `@wordpress/scripts`; never hand-edit `build/`.
- Text domain is exactly `flavor-agent` for every translatable string.
- JS formatting is fixed only via `npm run lint:js -- --fix` (project Prettier lives behind ESLint). Never run `npx prettier` or `wp-scripts format`.
- Deterministic mutations only: no model/LLM call, no generated block HTML, no content-text adaptation, no synced-pattern detachment.
- No changes to `recommend-patterns` ability input/output schemas, pattern retrieval backends, pattern indexing, ranking, or reranker prompts.
- No Flavor Agent-owned apply/undo/activity contract for pattern insertion: insertion stays a native `insertBlocks` operation; diagnostics stay `recommendation_outcome` rows.
- `adaptationContext` is client-only: it must NOT be added to `buildBaseInput()` and must NOT reach the server or the ranking/freshness request signatures. It is only an input to adaptation and a component of the local `adaptationSignature`.
- The adapted `blocks` array returned by `buildPatternAdaptationPreview` is the single source of truth for insertion: the adapted path inserts those exact instances (no re-clone, no re-resolve of blocks).
- Reuse existing helpers (`resolvePatternBlocks`, `collectThemeTokensFromSettings`, `buildContextSignature`, the existing freshness/verify/rollback logic) — do not add parallel implementations.
- New outcome events must be added to BOTH `src/store/recommendation-outcomes.js` and `inc/Activity/RecommendationOutcome.php`; reason codes are free-form (slugified) on both sides and need no allowlisting.

## Canonical Interfaces (referenced across tasks)

```js
// src/patterns/pattern-adaptation.js
buildPatternAdaptationPreview( {
	pattern,                    // recommendation/pattern object (for name + synced detection)
	sourceBlocks,               // resolved block array from resolvePatternBlocks( pattern )
	adaptationContext,          // client-only signal (see pattern-adaptation-context.js)
	insertionTargetSignature,   // string captured at fetch time
	resolvedContextSignature,   // server resolvedContextSignature
	themeTokens,                // collectThemeTokensFromSettings( settings )
	blockRegistry,              // { getBlockType(name), getBlockStyles(name) }
} ) => AdaptationResult

// AdaptationResult — ready:
// { status: 'ready', blocks, plan: { version, sourcePatternName, targetSignature, changes }, adaptationSignature }
// AdaptationResult — blocked:
// { status: 'blocked', reason, blocks: [], plan: null, adaptationSignature: '' }
// change: { path: (number|string)[], blockName, attribute, from, to, reason }

// src/patterns/pattern-adaptation-context.js
buildPatternAdaptationContext( editor, { inserterRootClientId, insertionIndex, siblingOrder } ) =>
	{ precedingHeadingLevel: number|null, nearbyHeadingLevels: number[], rootAlign: string, siblingAligns: string[] }

// src/patterns/pattern-insertability.js (new exports)
getRejectedResolvedBlockNames( blocks, rootClientId, blockEditor ) => string[]
isSyncedPatternReference( pattern, sourceBlocks? ) => boolean  // shared synced detection
```

---

### Task 1: Add adapted outcome events to the JS and PHP allowlists

**Files:**
- Modify: `src/store/recommendation-outcomes.js:10-35` (`OUTCOME_EVENTS`, `OUTCOME_LABELS`)
- Modify: `inc/Activity/RecommendationOutcome.php:18-45` (`EVENTS`, `EVENT_LABELS`)
- Test: `src/store/__tests__/recommendation-outcomes.test.js`
- Test: `tests/phpunit/RecommendationOutcomeTest.php`

**Interfaces:**
- Produces: four new accepted outcome events — `adapted_preview_shown`, `adapted_inserted_from_preview`, `adaptation_blocked`, `adapted_insert_failed` — usable by `buildRecommendationOutcomeEntry` (JS) and `RecommendationOutcome::normalize_entry` (PHP).

- [ ] **Step 1: Write the failing JS test**

Add to `src/store/__tests__/recommendation-outcomes.test.js` inside the top-level `describe( 'recommendation outcomes', ... )`:

```js
test.each( [
	[ 'adapted_preview_shown', 'Adapted pattern preview shown' ],
	[
		'adapted_inserted_from_preview',
		'Adapted pattern inserted from preview',
	],
	[ 'adaptation_blocked', 'Pattern adaptation blocked' ],
	[ 'adapted_insert_failed', 'Adapted pattern insertion failed' ],
] )( 'accepts adapted outcome event %s', ( event, label ) => {
	const entry = buildRecommendationOutcomeEntry( {
		document: { scopeKey: 'post:42', postType: 'post', entityId: '42' },
		event,
		surface: 'pattern',
		recommendationSetId: 'pattern:1:set',
		suggestionKey: 'theme/hero',
		reason: 'adapted_preview_stale',
	} );

	expect( entry ).not.toBeNull();
	expect( entry.after.outcome.event ).toBe( event );
	expect( entry.suggestion ).toBe( label );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:unit -- --runInBand src/store/__tests__/recommendation-outcomes.test.js -t "accepts adapted outcome event"`
Expected: FAIL — `buildRecommendationOutcomeEntry` returns `null` because the event is not in `OUTCOME_EVENT_SET`.

- [ ] **Step 3: Add the events and labels in `src/store/recommendation-outcomes.js`**

Replace the `OUTCOME_EVENTS` array (currently ends with `'pattern_inserted_from_shelf'`):

```js
export const OUTCOME_EVENTS = Object.freeze( [
	'shown',
	'selected_for_review',
	'stale_blocked',
	'validation_blocked',
	'insert_failed',
	'pattern_inserted_from_shelf',
	'adapted_preview_shown',
	'adapted_inserted_from_preview',
	'adaptation_blocked',
	'adapted_insert_failed',
] );
```

Replace the `OUTCOME_LABELS` object:

```js
const OUTCOME_LABELS = Object.freeze( {
	shown: 'Recommendations shown',
	selected_for_review: 'Recommendation selected for review',
	stale_blocked: 'Recommendation blocked by stale context',
	validation_blocked: 'Recommendation blocked by validation',
	insert_failed: 'Pattern insertion failed',
	pattern_inserted_from_shelf: 'Pattern inserted from recommendation shelf',
	adapted_preview_shown: 'Adapted pattern preview shown',
	adapted_inserted_from_preview: 'Adapted pattern inserted from preview',
	adaptation_blocked: 'Pattern adaptation blocked',
	adapted_insert_failed: 'Adapted pattern insertion failed',
} );
```

- [ ] **Step 4: Run the JS test to verify it passes**

Run: `npm run test:unit -- --runInBand src/store/__tests__/recommendation-outcomes.test.js -t "accepts adapted outcome event"`
Expected: PASS (4 cases).

- [ ] **Step 5: Write the failing PHP test**

Add to `tests/phpunit/RecommendationOutcomeTest.php`:

```php
/**
 * @dataProvider provide_adapted_events
 */
public function test_accepts_adapted_outcome_event( string $event, string $label ): void {
	$entry = RecommendationOutcome::normalize_entry(
		[
			'type'    => 'recommendation_outcome',
			'surface' => 'pattern',
			'after'   => [
				'outcome' => [
					'event'  => $event,
					'reason' => 'adapted preview stale',
				],
			],
		]
	);

	$this->assertIsArray( $entry );
	$this->assertSame( $event, $entry['after']['outcome']['event'] );
	$this->assertSame( $label, $entry['suggestion'] );
	$this->assertSame( 'adapted_preview_stale', $entry['after']['outcome']['reason'] );
}

/**
 * @return array<int, array{0: string, 1: string}>
 */
public static function provide_adapted_events(): array {
	return [
		[ 'adapted_preview_shown', 'Adapted pattern preview shown' ],
		[ 'adapted_inserted_from_preview', 'Adapted pattern inserted from preview' ],
		[ 'adaptation_blocked', 'Pattern adaptation blocked' ],
		[ 'adapted_insert_failed', 'Adapted pattern insertion failed' ],
	];
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter test_accepts_adapted_outcome_event`
Expected: FAIL — event rejected (`normalize_enum` returns `''`), so `normalize_entry` returns a `WP_Error`.

- [ ] **Step 7: Add the events and labels in `inc/Activity/RecommendationOutcome.php`**

Replace the `EVENTS` constant:

```php
	private const EVENTS = [
		'shown',
		'selected_for_review',
		'stale_blocked',
		'validation_blocked',
		'pattern_inserted_from_shelf',
		'insert_failed',
		'adapted_preview_shown',
		'adapted_inserted_from_preview',
		'adaptation_blocked',
		'adapted_insert_failed',
	];
```

Replace the `EVENT_LABELS` constant:

```php
	private const EVENT_LABELS = [
		'shown'                         => 'Recommendations shown',
		'selected_for_review'           => 'Recommendation selected for review',
		'stale_blocked'                 => 'Recommendation blocked by stale context',
		'validation_blocked'            => 'Recommendation blocked by validation',
		'pattern_inserted_from_shelf'   => 'Pattern inserted from recommendation shelf',
		'insert_failed'                 => 'Pattern insertion failed',
		'adapted_preview_shown'         => 'Adapted pattern preview shown',
		'adapted_inserted_from_preview' => 'Adapted pattern inserted from preview',
		'adaptation_blocked'            => 'Pattern adaptation blocked',
		'adapted_insert_failed'         => 'Adapted pattern insertion failed',
	];
```

- [ ] **Step 8: Run the PHP test to verify it passes**

Run: `vendor/bin/phpunit --filter test_accepts_adapted_outcome_event`
Expected: PASS (4 cases).

- [ ] **Step 9: Commit**

```bash
git add src/store/recommendation-outcomes.js inc/Activity/RecommendationOutcome.php src/store/__tests__/recommendation-outcomes.test.js tests/phpunit/RecommendationOutcomeTest.php
git commit -m "feat(patterns): allowlist adapted-preview outcome events (JS + PHP)"
```

---

### Task 2: Add resolved-block-array insertability helpers

**Files:**
- Modify: `src/patterns/pattern-insertability.js` (add two exports; reuse existing `getRejectedPatternBlockNames` logic)
- Test: `src/patterns/__tests__/pattern-insertability.test.js`

**Interfaces:**
- Consumes: the live `blockEditor` (`canInsertBlockType`) — same dependency as existing helpers.
- Produces: `getRejectedResolvedBlockNames( blocks, rootClientId, blockEditor ): string[]` (used by the shared freshness gate in Task 9 to validate an already-resolved/adapted block array with a visible disallowed-block notice) and `isSyncedPatternReference( pattern, sourceBlocks? ): boolean` (the single synced-detection source of truth shared by the adaptation engine and the shelf).

- [ ] **Step 1: Write the failing test**

Add to `src/patterns/__tests__/pattern-insertability.test.js`:

```js
import {
	getRejectedResolvedBlockNames,
	isSyncedPatternReference,
} from '../pattern-insertability';

describe( 'getRejectedResolvedBlockNames', () => {
	test( 'returns top-level block names not insertable at the root', () => {
		const blockEditor = {
			canInsertBlockType: jest.fn(
				( name ) => name !== 'core/template-part'
			),
		};
		const blocks = [
			{ name: 'core/template-part', attributes: {} },
			{ name: 'core/group', attributes: {} },
		];

		expect(
			getRejectedResolvedBlockNames( blocks, 'root-a', blockEditor )
		).toEqual( [ 'core/template-part' ] );
		expect( blockEditor.canInsertBlockType ).toHaveBeenCalledWith(
			'core/group',
			'root-a'
		);
	} );
} );

describe( 'isSyncedPatternReference', () => {
	test( 'is true for a user pattern by type/id/syncStatus', () => {
		expect(
			isSyncedPatternReference( {
				type: 'user',
				syncStatus: 'fully',
				id: 7,
			} )
		).toBe( true );
	} );

	test( 'is true for a resolved single core/block reference', () => {
		expect(
			isSyncedPatternReference( { name: 'theme/ref' }, [
				{ name: 'core/block', attributes: { ref: 7 } },
			] )
		).toBe( true );
	} );

	test( 'is false for a normal multi-block pattern', () => {
		expect(
			isSyncedPatternReference( { name: 'theme/hero' }, [
				{ name: 'core/heading', attributes: {} },
			] )
		).toBe( false );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-insertability.test.js -t "getRejectedResolvedBlockNames|isSyncedPatternReference"`
Expected: FAIL — `getRejectedResolvedBlockNames`/`isSyncedPatternReference` are not exported.

- [ ] **Step 3: Add the helpers in `src/patterns/pattern-insertability.js`**

Append:

```js
export function getRejectedResolvedBlockNames( blocks, rootClientId, blockEditor ) {
	if (
		typeof blockEditor?.canInsertBlockType !== 'function' ||
		! Array.isArray( blocks )
	) {
		return [];
	}

	const rejected = [];

	for ( const block of blocks ) {
		if ( ! block?.name ) {
			continue;
		}

		if (
			! blockEditor.canInsertBlockType( block.name, rootClientId ?? null )
		) {
			rejected.push( block.name );
		}
	}

	return rejected;
}

// Single source of truth for "this recommendation is a synced/user reference".
// Mirrors resolvePatternBlocks: a user pattern by type/id, OR a pattern whose
// resolved blocks are a single core/block reference. Used by both the adaptation
// engine and the shelf so they never disagree about which patterns are synced.
export function isSyncedPatternReference(
	pattern,
	sourceBlocks = resolvePatternBlocks( pattern )
) {
	if (
		pattern?.type === 'user' &&
		pattern?.syncStatus !== 'unsynced' &&
		pattern?.id
	) {
		return true;
	}

	return (
		Array.isArray( sourceBlocks ) &&
		sourceBlocks.length === 1 &&
		sourceBlocks[ 0 ]?.name === 'core/block'
	);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-insertability.test.js`
Expected: PASS (all existing + new).

- [ ] **Step 5: Commit**

```bash
git add src/patterns/pattern-insertability.js src/patterns/__tests__/pattern-insertability.test.js
git commit -m "feat(patterns): add resolved-block-array insertability helpers"
```

---

### Task 3: Add the client-only adaptation-context reader

**Files:**
- Create: `src/patterns/pattern-adaptation-context.js`
- Test: `src/patterns/__tests__/pattern-adaptation-context.test.js`

**Interfaces:**
- Consumes: a live block-editor `editor` (the same selector object used by `usePatternInsertionContext`: `getBlockName`, `getBlockAttributes`, `getBlockOrder`, `getBlockRootClientId`).
- Produces: `buildPatternAdaptationContext( editor, { inserterRootClientId, insertionIndex, siblingOrder } )` returning `{ precedingHeadingLevel, nearbyHeadingLevels, rootAlign, siblingAligns }`. Consumed by `PatternRecommender.js` (Task 10) and passed into `buildPatternAdaptationPreview` (Tasks 4-7).

- [ ] **Step 1: Write the failing test**

Create `src/patterns/__tests__/pattern-adaptation-context.test.js`:

```js
import { buildPatternAdaptationContext } from '../pattern-adaptation-context';

function makeEditor( blocks, order, roots = {} ) {
	return {
		getBlockName: jest.fn( ( id ) => blocks[ id ]?.name || '' ),
		getBlockAttributes: jest.fn( ( id ) => blocks[ id ]?.attributes || {} ),
		getBlockOrder: jest.fn( ( root ) => order[ root ?? '' ] || [] ),
		getBlockRootClientId: jest.fn( ( id ) => roots[ id ] ?? null ),
	};
}

describe( 'buildPatternAdaptationContext', () => {
	test( 'reads preceding heading level, nearby levels, and aligns', () => {
		const blocks = {
			h1: { name: 'core/heading', attributes: { level: 2 } },
			p1: { name: 'core/paragraph', attributes: {} },
			img: { name: 'core/image', attributes: { align: 'wide' } },
			root: { name: 'core/group', attributes: { align: 'full' } },
		};
		const order = { '': [ 'h1', 'p1', 'img' ] };

		const ctx = buildPatternAdaptationContext(
			makeEditor( blocks, order ),
			{ inserterRootClientId: null, insertionIndex: 3, siblingOrder: order[ '' ] }
		);

		expect( ctx.precedingHeadingLevel ).toBe( 2 );
		expect( ctx.nearbyHeadingLevels ).toEqual( [ 2 ] );
		expect( ctx.siblingAligns ).toEqual( [ 'wide' ] );
	} );

	test( 'reads root align from the inserter root block', () => {
		const blocks = {
			root: { name: 'core/group', attributes: { align: 'full' } },
		};
		const ctx = buildPatternAdaptationContext(
			makeEditor( blocks, { root: [] } ),
			{ inserterRootClientId: 'root', insertionIndex: 0, siblingOrder: [] }
		);

		expect( ctx.rootAlign ).toBe( 'full' );
		expect( ctx.precedingHeadingLevel ).toBeNull();
	} );

	test( 'returns empty signal for a missing editor', () => {
		expect( buildPatternAdaptationContext( null, {} ) ).toEqual( {
			precedingHeadingLevel: null,
			nearbyHeadingLevels: [],
			rootAlign: '',
			siblingAligns: [],
		} );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-adaptation-context.test.js`
Expected: FAIL — module does not exist.

- [ ] **Step 3: Create `src/patterns/pattern-adaptation-context.js`**

```js
/**
 * Client-only adaptation context.
 *
 * Reads nearby heading levels and container/sibling alignment from the LIVE
 * editor at preview time so deterministic adaptation can match the surrounding
 * page. This signal is intentionally NOT part of the ranking request
 * (`buildBaseInput`) or any server/freshness signature — it only feeds
 * adaptation and the local adaptation signature.
 */

const NEARBY_RANGE = 3;

function readLevel( editor, clientId ) {
	if ( editor.getBlockName?.( clientId ) !== 'core/heading' ) {
		return null;
	}

	const level = Number( editor.getBlockAttributes?.( clientId )?.level );

	return Number.isInteger( level ) && level >= 1 && level <= 6 ? level : null;
}

function readAlign( editor, clientId ) {
	const align = editor.getBlockAttributes?.( clientId )?.align;

	return typeof align === 'string' && align.trim() ? align.trim() : '';
}

export function buildPatternAdaptationContext(
	editor,
	{ inserterRootClientId = null, insertionIndex, siblingOrder } = {}
) {
	const empty = {
		precedingHeadingLevel: null,
		nearbyHeadingLevels: [],
		rootAlign: '',
		siblingAligns: [],
	};

	if ( ! editor ) {
		return empty;
	}

	const order = Array.isArray( siblingOrder )
		? siblingOrder
		: editor.getBlockOrder?.( inserterRootClientId ) || [];
	const insertIndex = Number.isInteger( insertionIndex )
		? insertionIndex
		: order.length;
	const start = Math.max( 0, insertIndex - NEARBY_RANGE );
	const end = Math.min( order.length, insertIndex + NEARBY_RANGE );

	const nearbyHeadingLevels = [];
	const siblingAligns = [];
	let precedingHeadingLevel = null;

	for ( let i = start; i < end; i++ ) {
		const clientId = order[ i ];
		const level = readLevel( editor, clientId );

		if ( level !== null ) {
			nearbyHeadingLevels.push( level );

			if ( i < insertIndex ) {
				precedingHeadingLevel = level;
			}
		}

		const align = readAlign( editor, clientId );
		if ( align ) {
			siblingAligns.push( align );
		}
	}

	return {
		precedingHeadingLevel,
		nearbyHeadingLevels,
		rootAlign: inserterRootClientId
			? readAlign( editor, inserterRootClientId )
			: '',
		siblingAligns,
	};
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-adaptation-context.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/patterns/pattern-adaptation-context.js src/patterns/__tests__/pattern-adaptation-context.test.js
git commit -m "feat(patterns): add client-only adaptation-context reader"
```

---

### Task 4: Adaptation engine scaffold (signature, synced refusal, clone-once, blocked path)

**Files:**
- Create: `src/patterns/pattern-adaptation.js`
- Test: `src/patterns/__tests__/pattern-adaptation.test.js`

**Interfaces:**
- Consumes: `resolvePatternBlocks` output as `sourceBlocks`; `adaptationContext` from Task 3; `themeTokens`; injected `blockRegistry`; `buildContextSignature` from `../utils/context-signature`; `cloneBlock` from `@wordpress/blocks`.
- Produces: `buildPatternAdaptationPreview( params ): AdaptationResult` (see Canonical Interfaces). In this task the rule set is empty, so a non-synced pattern always returns `status: 'blocked'` with `reason: 'unsupported_block_support'` (or `missing_theme_tokens` when the theme exposes no presets at all). Synced references return `unsupported_synced_reference`. Tasks 5-7 add rules that produce `status: 'ready'`.

- [ ] **Step 1: Write the failing test**

Create `src/patterns/__tests__/pattern-adaptation.test.js`:

```js
const mockCloneBlock = jest.fn();

jest.mock( '@wordpress/blocks', () => ( {
	cloneBlock: ( ...args ) => mockCloneBlock( ...args ),
} ) );

// Task 6 imports getBlockStyleSupportedStylePathsFromTokens from
// ../context/theme-tokens, which transitively imports `select` from
// @wordpress/data at module scope. The support helper is pure and never calls
// it; mock keeps this unit test hermetic.
jest.mock( '@wordpress/data', () => ( {
	select: jest.fn(),
} ) );

import { buildPatternAdaptationPreview } from '../pattern-adaptation';

function deepClone( block ) {
	return JSON.parse( JSON.stringify( block ) );
}

const THEME_TOKENS = {
	color: {
		palette: [
			{ slug: 'base' },
			{ slug: 'contrast' },
			{ slug: 'primary' },
		],
		backgroundEnabled: true,
		textEnabled: true,
	},
	spacing: {
		spacingSizes: [
			{ slug: '20' },
			{ slug: '40' },
			{ slug: '60' },
		],
	},
};

const EMPTY_THEME_TOKENS = {
	color: { palette: [], backgroundEnabled: true, textEnabled: true },
	spacing: { spacingSizes: [] },
};

const REGISTRY = {
	getBlockType: jest.fn( () => ( { supports: {} } ) ),
	getBlockStyles: jest.fn( () => [] ),
};

const BASE_CTX = {
	precedingHeadingLevel: null,
	nearbyHeadingLevels: [],
	rootAlign: '',
	siblingAligns: [],
};

beforeEach( () => {
	mockCloneBlock.mockReset();
	mockCloneBlock.mockImplementation( deepClone );
	REGISTRY.getBlockType.mockReset();
	REGISTRY.getBlockType.mockReturnValue( { supports: {} } );
	REGISTRY.getBlockStyles.mockReset();
	REGISTRY.getBlockStyles.mockReturnValue( [] );
} );

function run( overrides = {} ) {
	return buildPatternAdaptationPreview( {
		pattern: { name: 'theme/hero' },
		sourceBlocks: [ { name: 'core/paragraph', attributes: {} } ],
		adaptationContext: BASE_CTX,
		insertionTargetSignature: 'target-sig',
		resolvedContextSignature: 'resolved-sig',
		themeTokens: THEME_TOKENS,
		blockRegistry: REGISTRY,
		...overrides,
	} );
}

describe( 'buildPatternAdaptationPreview scaffold', () => {
	test( 'refuses synced/user pattern references', () => {
		const result = run( {
			pattern: { name: 'core/block/12', type: 'user', id: 12 },
			sourceBlocks: [
				{ name: 'core/block', attributes: { ref: 12 } },
			],
		} );

		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'unsupported_synced_reference' );
		expect( result.blocks ).toEqual( [] );
		expect( result.plan ).toBeNull();
		expect( result.adaptationSignature ).toBe( '' );
	} );

	test( 'blocks when no rule applies (no theme presets needed)', () => {
		const result = run();

		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'unsupported_block_support' );
	} );

	test( 'reports missing_theme_tokens when the theme exposes no presets', () => {
		const result = run( {
			sourceBlocks: [
				{
					name: 'core/group',
					attributes: { backgroundColor: 'off-theme' },
				},
			],
			themeTokens: EMPTY_THEME_TOKENS,
		} );

		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'missing_theme_tokens' );
	} );

	test( 'clones source blocks exactly once and never mutates the source', () => {
		const sourceBlocks = [ { name: 'core/paragraph', attributes: {} } ];
		run( { sourceBlocks } );

		expect( mockCloneBlock ).toHaveBeenCalledTimes( sourceBlocks.length );
		expect( sourceBlocks[ 0 ].attributes ).toEqual( {} );
	} );

	test( 'blocks an empty source block array', () => {
		const result = run( { sourceBlocks: [] } );

		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'adapted_blocks_not_insertable' );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-adaptation.test.js`
Expected: FAIL — module does not exist.

- [ ] **Step 3: Create `src/patterns/pattern-adaptation.js`**

```js
/**
 * Deterministic pattern adaptation engine.
 *
 * Clones a non-synced pattern's resolved block tree exactly once and applies
 * only bounded, attribute-level cosmetic mutations that align the clone to the
 * current theme tokens and local insertion context. No block names change, no
 * blocks are added/removed, no bindings or generated HTML are written. The
 * returned `blocks` array is the source of truth for insertion; the `plan` is
 * explanation/diagnostics/stale-checking only.
 */
import { cloneBlock } from '@wordpress/blocks';

import { buildContextSignature } from '../utils/context-signature';
import { isSyncedPatternReference } from './pattern-insertability';

export const ADAPTATION_PLAN_VERSION = 'pattern-adaptation-v1';

// Mutation rules are registered in later tasks. Each rule:
//   ( block, { adaptationContext, themeTokens, blockRegistry } ) =>
//     null | { attribute, from, to, reason }
const ADAPTATION_RULES = [];

function themeHasAnyPreset( themeTokens ) {
	const palette = themeTokens?.color?.palette;
	const spacing = themeTokens?.spacing?.spacingSizes;

	return (
		( Array.isArray( palette ) && palette.length > 0 ) ||
		( Array.isArray( spacing ) && spacing.length > 0 )
	);
}

function applyRulesToTree( blocks, env, basePath = [] ) {
	const changes = [];

	blocks.forEach( ( block, index ) => {
		const path = [ ...basePath, index ];

		for ( const rule of ADAPTATION_RULES ) {
			const change = rule( block, env );

			if ( ! change ) {
				continue;
			}

			block.attributes = {
				...( block.attributes || {} ),
				[ change.attribute ]: change.to,
			};
			changes.push( {
				path,
				blockName: block.name,
				attribute: change.attribute,
				from: change.from,
				to: change.to,
				reason: change.reason,
			} );
		}

		if ( Array.isArray( block.innerBlocks ) && block.innerBlocks.length ) {
			changes.push(
				...applyRulesToTree( block.innerBlocks, env, [
					...path,
					'innerBlocks',
				] )
			);
		}
	} );

	return changes;
}

function blocked( reason ) {
	return {
		status: 'blocked',
		reason,
		blocks: [],
		plan: null,
		adaptationSignature: '',
	};
}

export function buildPatternAdaptationPreview( {
	pattern = null,
	sourceBlocks = [],
	adaptationContext = {},
	insertionTargetSignature = '',
	resolvedContextSignature = '',
	themeTokens = {},
	blockRegistry = null,
} = {} ) {
	if ( isSyncedPatternReference( pattern, sourceBlocks ) ) {
		return blocked( 'unsupported_synced_reference' );
	}

	if ( ! Array.isArray( sourceBlocks ) || sourceBlocks.length === 0 ) {
		return blocked( 'adapted_blocks_not_insertable' );
	}

	const clonedBlocks = sourceBlocks.map( ( block ) => cloneBlock( block ) );
	const env = { adaptationContext, themeTokens, blockRegistry };
	const changes = applyRulesToTree( clonedBlocks, env );

	if ( changes.length === 0 ) {
		return blocked(
			themeHasAnyPreset( themeTokens )
				? 'unsupported_block_support'
				: 'missing_theme_tokens'
		);
	}

	const sourcePatternName = pattern?.name || '';
	const targetSignature = buildContextSignature( {
		insertionTargetSignature,
		resolvedContextSignature,
	} );

	return {
		status: 'ready',
		blocks: clonedBlocks,
		plan: {
			version: ADAPTATION_PLAN_VERSION,
			sourcePatternName,
			targetSignature,
			changes,
		},
		adaptationSignature: buildContextSignature( {
			sourcePatternName,
			insertionTargetSignature,
			resolvedContextSignature,
			adaptationContext,
			changes: changes.map( ( change ) => ( {
				path: change.path,
				attribute: change.attribute,
				to: change.to,
				reason: change.reason,
			} ) ),
		} ),
	};
}

// Internal — exported only for rule registration in later tasks/tests.
export const __ADAPTATION_RULES = ADAPTATION_RULES;
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-adaptation.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/patterns/pattern-adaptation.js src/patterns/__tests__/pattern-adaptation.test.js
git commit -m "feat(patterns): add adaptation engine scaffold (clone, signature, blocked path)"
```

---

### Task 5: Adaptation rules — heading level and alignment

**Files:**
- Modify: `src/patterns/pattern-adaptation.js` (register the two rules into `ADAPTATION_RULES`)
- Test: `src/patterns/__tests__/pattern-adaptation.test.js`

**Interfaces:**
- Consumes: `env.adaptationContext.precedingHeadingLevel`, `env.adaptationContext.rootAlign`, `env.blockRegistry.getBlockType(name).supports.align`.
- Produces: change objects with `attribute: 'level'`, `reason: 'nearby_heading_hierarchy'` and `attribute: 'align'`, `reason: 'match_container_alignment'`.

- [ ] **Step 1: Write the failing test**

Add to `src/patterns/__tests__/pattern-adaptation.test.js`:

```js
describe( 'heading level + alignment rules', () => {
	test( 'sets heading level to nearest preceding heading + 1', () => {
		const result = run( {
			sourceBlocks: [ { name: 'core/heading', attributes: { level: 4 } } ],
			adaptationContext: { ...BASE_CTX, precedingHeadingLevel: 2 },
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.level ).toBe( 3 );
		expect( result.plan.changes ).toContainEqual(
			expect.objectContaining( {
				attribute: 'level',
				from: 4,
				to: 3,
				reason: 'nearby_heading_hierarchy',
			} )
		);
	} );

	test( 'clamps heading level to 6 and skips when already aligned', () => {
		const aligned = run( {
			sourceBlocks: [ { name: 'core/heading', attributes: { level: 3 } } ],
			adaptationContext: { ...BASE_CTX, precedingHeadingLevel: 2 },
		} );
		expect( aligned.status ).toBe( 'blocked' );

		const clamped = run( {
			sourceBlocks: [ { name: 'core/heading', attributes: { level: 2 } } ],
			adaptationContext: { ...BASE_CTX, precedingHeadingLevel: 6 },
		} );
		expect( clamped.blocks[ 0 ].attributes.level ).toBe( 6 );
	} );

	test( 'matches container alignment when the block supports it', () => {
		REGISTRY.getBlockType.mockImplementation( ( name ) =>
			name === 'core/image'
				? { supports: { align: [ 'wide', 'full' ] } }
				: { supports: {} }
		);

		const result = run( {
			sourceBlocks: [ { name: 'core/image', attributes: {} } ],
			adaptationContext: { ...BASE_CTX, rootAlign: 'full' },
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.align ).toBe( 'full' );
		expect( result.plan.changes ).toContainEqual(
			expect.objectContaining( {
				attribute: 'align',
				to: 'full',
				reason: 'match_container_alignment',
			} )
		);
	} );

	test( 'does not apply an alignment the block does not support', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { align: [ 'wide' ] },
		} );

		const result = run( {
			sourceBlocks: [ { name: 'core/image', attributes: {} } ],
			adaptationContext: { ...BASE_CTX, rootAlign: 'full' },
		} );

		expect( result.status ).toBe( 'blocked' );
	} );

	test( 'falls back to the most frequent sibling align with no root align', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { align: [ 'wide', 'full' ] },
		} );

		const result = run( {
			sourceBlocks: [ { name: 'core/image', attributes: {} } ],
			adaptationContext: {
				...BASE_CTX,
				rootAlign: '',
				siblingAligns: [ 'wide', 'full', 'wide' ],
			},
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.align ).toBe( 'wide' );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-adaptation.test.js -t "heading level"`
Expected: FAIL — no rules registered yet, so all return `blocked`.

- [ ] **Step 3: Register the rules in `src/patterns/pattern-adaptation.js`**

Add above the `ADAPTATION_RULES` constant (replace the empty array declaration and the rule helpers):

```js
const ALL_ALIGNMENTS = [ 'left', 'center', 'right', 'wide', 'full' ];

function clampLevel( level ) {
	return Math.max( 1, Math.min( 6, level ) );
}

function supportedAlignments( blockRegistry, blockName ) {
	const align = blockRegistry?.getBlockType?.( blockName )?.supports?.align;

	if ( align === true ) {
		return ALL_ALIGNMENTS;
	}

	return Array.isArray( align ) ? align : [];
}

function headingLevelRule( block, { adaptationContext } ) {
	if ( block?.name !== 'core/heading' ) {
		return null;
	}

	const preceding = adaptationContext?.precedingHeadingLevel;

	if ( ! Number.isInteger( preceding ) ) {
		return null;
	}

	const from = Number.isInteger( block?.attributes?.level )
		? block.attributes.level
		: 2;
	const to = clampLevel( preceding + 1 );

	return to === from
		? null
		: { attribute: 'level', from, to, reason: 'nearby_heading_hierarchy' };
}

// Deterministic sibling fallback: most frequent nearby align, first-occurrence
// tie-break. Used only when the container itself carries no align.
function mostFrequentAlign( aligns ) {
	if ( ! Array.isArray( aligns ) || aligns.length === 0 ) {
		return '';
	}

	const counts = new Map();
	for ( const align of aligns ) {
		counts.set( align, ( counts.get( align ) || 0 ) + 1 );
	}

	let best = '';
	let bestCount = 0;
	for ( const align of aligns ) {
		const count = counts.get( align );
		if ( count > bestCount ) {
			best = align;
			bestCount = count;
		}
	}

	return best;
}

function alignmentRule( block, { adaptationContext, blockRegistry } ) {
	// Spec: prefer the insertion root's own align, else the nearby sibling
	// alignment. rootAlign is empty for top-level insertions, where the sibling
	// signal is the only available cue.
	const target =
		adaptationContext?.rootAlign ||
		mostFrequentAlign( adaptationContext?.siblingAligns );

	if ( ! target || ! block?.name ) {
		return null;
	}

	if ( ! supportedAlignments( blockRegistry, block.name ).includes( target ) ) {
		return null;
	}

	const from = block?.attributes?.align ?? null;

	return from === target
		? null
		: {
				attribute: 'align',
				from,
				to: target,
				reason: 'match_container_alignment',
		  };
}
```

Then replace `const ADAPTATION_RULES = [];` with:

```js
const ADAPTATION_RULES = [ headingLevelRule, alignmentRule ];
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-adaptation.test.js`
Expected: PASS (scaffold + heading/align).

- [ ] **Step 5: Commit**

```bash
git add src/patterns/pattern-adaptation.js src/patterns/__tests__/pattern-adaptation.test.js
git commit -m "feat(patterns): add heading-level and alignment adaptation rules"
```

---

### Task 6: Adaptation rules — color and spacing preset remap

**Files:**
- Modify: `src/patterns/pattern-adaptation.js` (add color + spacing rules; register them)
- Test: `src/patterns/__tests__/pattern-adaptation.test.js`

**Interfaces:**
- Consumes: `env.themeTokens.color.palette[].slug`, `env.themeTokens.spacing.spacingSizes[].slug`, the block's `backgroundColor` / `textColor` named attributes and `style.spacing` preset values, and `env.blockRegistry.getBlockType(name).supports.color`/`.spacing`.
- Produces: change objects with `reason: 'theme_color_alignment'` and `reason: 'theme_spacing_alignment'`. Color remap uses a fixed role-synonym table; spacing remap maps a numeric off-theme slug to the nearest numeric theme slug. When a slug cannot be confidently remapped, no change is produced (leave unchanged).

- [ ] **Step 1: Write the failing test**

Add to `src/patterns/__tests__/pattern-adaptation.test.js`:

```js
describe( 'color + spacing remap rules', () => {
	test( 'remaps an off-theme background slug to a same-role theme slug', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { color: { background: true, text: true } },
		} );

		const result = run( {
			sourceBlocks: [
				{
					name: 'core/group',
					attributes: { backgroundColor: 'accent' },
				},
			],
		} );

		expect( result.status ).toBe( 'ready' );
		// 'accent' maps to role "primary"; theme exposes slug 'primary'.
		expect( result.blocks[ 0 ].attributes.backgroundColor ).toBe(
			'primary'
		);
		expect( result.plan.changes ).toContainEqual(
			expect.objectContaining( {
				attribute: 'backgroundColor',
				from: 'accent',
				to: 'primary',
				reason: 'theme_color_alignment',
			} )
		);
	} );

	test( 'keeps an in-theme color slug unchanged', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { color: { background: true } },
		} );

		const result = run( {
			sourceBlocks: [
				{
					name: 'core/group',
					attributes: { backgroundColor: 'primary' },
				},
			],
		} );

		expect( result.status ).toBe( 'blocked' );
	} );

	test( 'remaps a numeric off-theme spacing slug to the nearest theme slug', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { spacing: { padding: true } },
		} );

		const result = run( {
			sourceBlocks: [
				{
					name: 'core/group',
					attributes: {
						style: {
							spacing: {
								padding: {
									top: 'var:preset|spacing|50',
								},
							},
						},
					},
				},
			],
		} );

		expect( result.status ).toBe( 'ready' );
		// theme slugs are 20/40/60; nearest to 50 is 40 (tie -> lower).
		expect(
			result.blocks[ 0 ].attributes.style.spacing.padding.top
		).toBe( 'var:preset|spacing|40' );
		expect( result.plan.changes ).toContainEqual(
			expect.objectContaining( {
				attribute: 'style',
				reason: 'theme_spacing_alignment',
			} )
		);
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-adaptation.test.js -t "color + spacing"`
Expected: FAIL — color/spacing rules not registered.

- [ ] **Step 3: Add the rules in `src/patterns/pattern-adaptation.js`**

First add this import alongside the existing imports at the top of the file (it is the same exact-support-path + theme-preset contract the block-style apply path already enforces — reuse it, do not re-derive support):

```js
import { getBlockStyleSupportedStylePathsFromTokens } from '../context/theme-tokens';
```

Add the helpers and rules above the `ADAPTATION_RULES` declaration:

```js
// Deterministic v1 role table: groups of conventional preset slugs -> role.
const COLOR_ROLE_SYNONYMS = {
	background: [ 'background', 'base', 'base-2', 'white', 'light' ],
	foreground: [ 'foreground', 'contrast', 'contrast-2', 'text', 'dark', 'black' ],
	primary: [ 'primary', 'accent', 'accent-1', 'brand' ],
	secondary: [ 'secondary', 'accent-2', 'accent-3', 'tertiary' ],
};

function roleForColorSlug( slug ) {
	for ( const [ role, slugs ] of Object.entries( COLOR_ROLE_SYNONYMS ) ) {
		if ( slugs.includes( slug ) ) {
			return role;
		}
	}

	return '';
}

function themeColorSlugs( themeTokens ) {
	const palette = themeTokens?.color?.palette;

	return Array.isArray( palette )
		? palette.map( ( entry ) => entry?.slug ).filter( Boolean )
		: [];
}

function remapColorSlug( slug, themeTokens ) {
	const slugs = themeColorSlugs( themeTokens );

	if ( slugs.includes( slug ) ) {
		return '';
	}

	const role = roleForColorSlug( slug );

	if ( ! role ) {
		return '';
	}

	return slugs.find( ( candidate ) => roleForColorSlug( candidate ) === role ) || '';
}

function supportsStylePath( themeTokens, blockSupports, path ) {
	return getBlockStyleSupportedStylePathsFromTokens(
		themeTokens,
		blockSupports || {}
	).some(
		( entry ) =>
			entry.path.length === path.length &&
			entry.path.every( ( segment, index ) => segment === path[ index ] )
	);
}

// `path` is the style support path (e.g. [ 'color', 'background' ]); the mutated
// attribute is the named block attribute that carries the preset slug.
function colorRule( attribute, path ) {
	return ( block, { themeTokens, blockRegistry } ) => {
		const blockSupports = blockRegistry?.getBlockType?.(
			block?.name
		)?.supports;

		// Exact-support-path + theme-preset gate, identical to the apply
		// contract. Omitted facets are NOT treated as enabled.
		if ( ! supportsStylePath( themeTokens, blockSupports, path ) ) {
			return null;
		}

		const from = block?.attributes?.[ attribute ];

		if ( typeof from !== 'string' || ! from ) {
			return null;
		}

		const to = remapColorSlug( from, themeTokens );

		return to ? { attribute, from, to, reason: 'theme_color_alignment' } : null;
	};
}

function themeSpacingSlugs( themeTokens ) {
	const sizes = themeTokens?.spacing?.spacingSizes;

	return Array.isArray( sizes )
		? sizes.map( ( entry ) => entry?.slug ).filter( Boolean )
		: [];
}

function nearestNumericSlug( slug, themeSlugs ) {
	const source = Number( slug );
	const numeric = themeSlugs
		.map( ( candidate ) => ( {
			slug: candidate,
			value: Number( candidate ),
		} ) )
		.filter( ( entry ) => Number.isFinite( entry.value ) );

	if ( ! Number.isFinite( source ) || numeric.length === 0 ) {
		return '';
	}

	// Tie -> lower value (sort by distance, then by value ascending).
	numeric.sort(
		( a, b ) =>
			Math.abs( a.value - source ) - Math.abs( b.value - source ) ||
			a.value - b.value
	);

	return numeric[ 0 ].slug;
}

const SPACING_PRESET_RE = /^var:preset\|spacing\|(.+)$/;

function remapSpacingValue( value, themeTokens ) {
	if ( typeof value !== 'string' ) {
		return value;
	}

	const match = value.match( SPACING_PRESET_RE );

	if ( ! match ) {
		return value;
	}

	const slug = match[ 1 ];
	const themeSlugs = themeSpacingSlugs( themeTokens );

	if ( themeSlugs.includes( slug ) ) {
		return value;
	}

	const replacement = nearestNumericSlug( slug, themeSlugs );

	return replacement ? `var:preset|spacing|${ replacement }` : value;
}

function remapSpacingTree( node, themeTokens, state ) {
	if ( Array.isArray( node ) ) {
		return node.map( ( item ) =>
			remapSpacingTree( item, themeTokens, state )
		);
	}

	if ( node && typeof node === 'object' ) {
		return Object.fromEntries(
			Object.entries( node ).map( ( [ key, value ] ) => [
				key,
				remapSpacingTree( value, themeTokens, state ),
			] )
		);
	}

	const next = remapSpacingValue( node, themeTokens );

	if ( next !== node ) {
		state.changed = true;
	}

	return next;
}

function spacingFacetSupported( blockSupports, facet ) {
	const value = blockSupports?.spacing?.[ facet ];

	if ( value === true ) {
		return true;
	}

	return Array.isArray( value ) && value.length > 0;
}

function spacingRule( block, { themeTokens, blockRegistry } ) {
	const blockSupports = blockRegistry?.getBlockType?.(
		block?.name
	)?.supports;
	const spacing = block?.attributes?.style?.spacing;

	if ( ! spacing || typeof spacing !== 'object' ) {
		return null;
	}

	// Remap only the spacing facets ( padding / margin / blockGap ) the block
	// type actually declares support for — never the whole spacing subtree.
	const state = { changed: false };
	const nextSpacing = { ...spacing };

	for ( const facet of [ 'padding', 'margin', 'blockGap' ] ) {
		if (
			spacing[ facet ] === undefined ||
			! spacingFacetSupported( blockSupports, facet )
		) {
			continue;
		}

		nextSpacing[ facet ] = remapSpacingTree(
			spacing[ facet ],
			themeTokens,
			state
		);
	}

	if ( ! state.changed ) {
		return null;
	}

	return {
		attribute: 'style',
		from: block.attributes.style,
		to: { ...block.attributes.style, spacing: nextSpacing },
		reason: 'theme_spacing_alignment',
	};
}
```

Then update the registration line to:

```js
const ADAPTATION_RULES = [
	headingLevelRule,
	alignmentRule,
	colorRule( 'backgroundColor', [ 'color', 'background' ] ),
	colorRule( 'textColor', [ 'color', 'text' ] ),
	spacingRule,
];
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-adaptation.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/patterns/pattern-adaptation.js src/patterns/__tests__/pattern-adaptation.test.js
git commit -m "feat(patterns): add theme color and spacing preset remap rules"
```

---

### Task 7: Adaptation rule — button style variation

**Files:**
- Modify: `src/patterns/pattern-adaptation.js` (add `buttonStyleRule`; register it)
- Test: `src/patterns/__tests__/pattern-adaptation.test.js`

**Interfaces:**
- Consumes: `env.blockRegistry.getBlockStyles('core/button')` returning `[{ name, label, isDefault }]`.
- Produces: a change with `attribute: 'className'`, `reason: 'theme_button_style'`, applied only to `core/button` blocks that have no existing `is-style-*` token, setting the first registered non-default variation.

- [ ] **Step 1: Write the failing test**

Add to `src/patterns/__tests__/pattern-adaptation.test.js`:

```js
describe( 'button style rule', () => {
	test( 'applies the first registered non-default button style', () => {
		REGISTRY.getBlockStyles.mockImplementation( ( name ) =>
			name === 'core/button'
				? [
						{ name: 'fill', isDefault: true },
						{ name: 'outline' },
				  ]
				: []
		);

		const result = run( {
			sourceBlocks: [ { name: 'core/button', attributes: {} } ],
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.className ).toBe(
			'is-style-outline'
		);
		expect( result.plan.changes ).toContainEqual(
			expect.objectContaining( {
				attribute: 'className',
				to: 'is-style-outline',
				reason: 'theme_button_style',
			} )
		);
	} );

	test( 'leaves a button that already has an explicit style', () => {
		REGISTRY.getBlockStyles.mockReturnValue( [
			{ name: 'fill', isDefault: true },
			{ name: 'outline' },
		] );

		const result = run( {
			sourceBlocks: [
				{
					name: 'core/button',
					attributes: { className: 'is-style-squared' },
				},
			],
		} );

		expect( result.status ).toBe( 'blocked' );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-adaptation.test.js -t "button style"`
Expected: FAIL — `buttonStyleRule` not registered.

- [ ] **Step 3: Add the rule in `src/patterns/pattern-adaptation.js`**

Add above the `ADAPTATION_RULES` declaration:

```js
function hasStyleVariation( className ) {
	return /(^|\s)is-style-[\w-]+/.test(
		typeof className === 'string' ? className : ''
	);
}

function buttonStyleRule( block, { blockRegistry } ) {
	if ( block?.name !== 'core/button' ) {
		return null;
	}

	const className = block?.attributes?.className || '';

	if ( hasStyleVariation( className ) ) {
		return null;
	}

	const styles = blockRegistry?.getBlockStyles?.( 'core/button' );

	if ( ! Array.isArray( styles ) ) {
		return null;
	}

	const variation = styles.find( ( style ) => style && ! style.isDefault );

	if ( ! variation?.name ) {
		return null;
	}

	const token = `is-style-${ variation.name }`;
	const to = className ? `${ className } ${ token }`.trim() : token;

	return {
		attribute: 'className',
		from: className || null,
		to,
		reason: 'theme_button_style',
	};
}
```

Update the registration to include it last:

```js
const ADAPTATION_RULES = [
	headingLevelRule,
	alignmentRule,
	colorRule( 'backgroundColor', [ 'color', 'background' ] ),
	colorRule( 'textColor', [ 'color', 'text' ] ),
	spacingRule,
	buttonStyleRule,
];
```

- [ ] **Step 4: Run the full adaptation test to verify it passes**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/pattern-adaptation.test.js`
Expected: PASS (scaffold + all five mutation kinds).

- [ ] **Step 5: Commit**

```bash
git add src/patterns/pattern-adaptation.js src/patterns/__tests__/pattern-adaptation.test.js
git commit -m "feat(patterns): add button style variation adaptation rule"
```

---

### Task 8: PatternAdaptationPreview component

**Files:**
- Create: `src/patterns/PatternAdaptationPreview.js`
- Test: `src/patterns/__tests__/PatternAdaptationPreview.test.js`

**Interfaces:**
- Consumes (props): `{ title, status, changes, blocks, isStale, onInsertAdapted, onInsertOriginal, onClose }` where `status` is `'ready' | 'blocked' | 'stale'`, `changes` is `plan.changes`, `blocks` is the adapted block array.
- Produces: a presentational panel rendering `BlockPreview` (ready, non-stale only), a change-reason list, and `Insert adapted` / `Insert original` / `Close` buttons. `Insert adapted` is disabled when `status !== 'ready'` or `isStale`.

- [ ] **Step 1: Write the failing test**

Create `src/patterns/__tests__/PatternAdaptationPreview.test.js`:

```js
const mockBlockPreview = jest.fn( () => null );

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/block-editor', () => ( {
	__experimentalBlockPreview: ( props ) => mockBlockPreview( props ),
	BlockPreview: ( props ) => mockBlockPreview( props ),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( value ) => value,
	sprintf: ( template, ...values ) => {
		let i = 0;
		return template.replace( /%s/g, () => values[ i++ ] ?? '' );
	},
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import PatternAdaptationPreview from '../PatternAdaptationPreview';

const { getRoot } = setupReactTest();

function render( props ) {
	const { createRoot } = require( '@wordpress/element' );
	let root;
	act( () => {
		root = createRoot( getRoot() );
		root.render(
			<PatternAdaptationPreview
				title="Hero"
				status="ready"
				changes={ [
					{ reason: 'theme_color_alignment', blockName: 'core/group' },
				] }
				blocks={ [ { name: 'core/group' } ] }
				isStale={ false }
				onInsertAdapted={ jest.fn() }
				onInsertOriginal={ jest.fn() }
				onClose={ jest.fn() }
				{ ...props }
			/>
		);
	} );
	return root;
}

beforeEach( () => {
	mockBlockPreview.mockClear();
} );

describe( 'PatternAdaptationPreview', () => {
	test( 'renders BlockPreview with the adapted blocks when ready', () => {
		render();
		expect( mockBlockPreview ).toHaveBeenCalledWith(
			expect.objectContaining( { blocks: [ { name: 'core/group' } ] } )
		);
		expect( getRoot().textContent ).toContain( 'Insert adapted' );
		expect( getRoot().textContent ).toContain( 'Insert original' );
	} );

	test( 'invokes onInsertAdapted from the adapted button', () => {
		const onInsertAdapted = jest.fn();
		render( { onInsertAdapted } );
		const button = [ ...getRoot().querySelectorAll( 'button' ) ].find(
			( node ) => node.textContent === 'Insert adapted'
		);
		act( () => {
			button.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
		} );
		expect( onInsertAdapted ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'disables Insert adapted and hides the preview when stale', () => {
		render( { isStale: true, status: 'stale' } );
		const button = [ ...getRoot().querySelectorAll( 'button' ) ].find(
			( node ) => node.textContent === 'Insert adapted'
		);
		expect( button.disabled ).toBe( true );
		expect( mockBlockPreview ).not.toHaveBeenCalled();
	} );

	test( 'shows a blocked message and only original/close when blocked', () => {
		render( { status: 'blocked', blocks: [], changes: [] } );
		expect( getRoot().textContent ).toContain( 'Insert original' );
		expect( mockBlockPreview ).not.toHaveBeenCalled();
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/PatternAdaptationPreview.test.js`
Expected: FAIL — component does not exist.

- [ ] **Step 3: Create `src/patterns/PatternAdaptationPreview.js`**

```js
/**
 * Presentational adapted-preview panel rendered inside the inserter portal.
 *
 * Renders the exact adapted block array via Gutenberg BlockPreview and exposes
 * Insert adapted / Insert original / Close. It owns no insertion, freshness, or
 * activity logic — those stay in PatternRecommender.
 */
import * as blockEditor from '@wordpress/block-editor';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

// BlockPreview is stable in current WP; fall back to the experimental alias for
// older block-editor builds the harness may load.
const ResolvedBlockPreview =
	blockEditor.BlockPreview || blockEditor.__experimentalBlockPreview;

const CHANGE_REASON_LABELS = {
	nearby_heading_hierarchy: __( 'Heading level matched to nearby headings', 'flavor-agent' ),
	match_container_alignment: __( 'Alignment matched to the container', 'flavor-agent' ),
	theme_color_alignment: __( 'Colors aligned to theme presets', 'flavor-agent' ),
	theme_spacing_alignment: __( 'Spacing aligned to theme presets', 'flavor-agent' ),
	theme_button_style: __( 'Button style matched to the theme', 'flavor-agent' ),
};

function describeChange( change ) {
	return (
		CHANGE_REASON_LABELS[ change?.reason ] ||
		__( 'Cosmetic adjustment', 'flavor-agent' )
	);
}

export default function PatternAdaptationPreview( {
	title = '',
	status = 'ready',
	changes = [],
	blocks = [],
	isStale = false,
	onInsertAdapted,
	onInsertOriginal,
	onClose,
} ) {
	const isReady = status === 'ready' && ! isStale;
	const uniqueReasons = [
		...new Set( changes.map( ( change ) => describeChange( change ) ) ),
	];

	return (
		<div className="flavor-agent-pattern-adaptation">
			<div className="flavor-agent-pattern-adaptation__header">
				<span className="flavor-agent-pill flavor-agent-pill--lane">
					{ __( 'Adapted preview', 'flavor-agent' ) }
				</span>
				<span className="flavor-agent-pattern-adaptation__title">
					{ title }
				</span>
			</div>

			{ isStale && (
				<p
					className="flavor-agent-pattern-adaptation__status"
					role="status"
				>
					{ __(
						'The insertion point changed, so this adapted preview is out of date. Insert the original or close and try again.',
						'flavor-agent'
					) }
				</p>
			) }

			{ status === 'blocked' && ! isStale && (
				<p
					className="flavor-agent-pattern-adaptation__status"
					role="status"
				>
					{ __(
						'Flavor Agent could not build a safe adaptation for this pattern. You can still insert the original.',
						'flavor-agent'
					) }
				</p>
			) }

			{ isReady && uniqueReasons.length > 0 && (
				<ul className="flavor-agent-pattern-adaptation__changes">
					{ uniqueReasons.map( ( reason ) => (
						<li key={ reason }>{ reason }</li>
					) ) }
				</ul>
			) }

			{ isReady && ResolvedBlockPreview && (
				<div className="flavor-agent-pattern-adaptation__preview">
					<ResolvedBlockPreview
						blocks={ blocks }
						viewportWidth={ 800 }
					/>
				</div>
			) }

			<div className="flavor-agent-pattern-adaptation__actions">
				<Button
					variant="primary"
					size="small"
					disabled={ ! isReady }
					onClick={ onInsertAdapted }
					aria-label={ sprintf(
						/* translators: %s: pattern title. */
						__( 'Insert adapted %s', 'flavor-agent' ),
						title
					) }
				>
					{ __( 'Insert adapted', 'flavor-agent' ) }
				</Button>
				<Button
					variant="secondary"
					size="small"
					onClick={ onInsertOriginal }
				>
					{ __( 'Insert original', 'flavor-agent' ) }
				</Button>
				<Button variant="tertiary" size="small" onClick={ onClose }>
					{ __( 'Close', 'flavor-agent' ) }
				</Button>
			</div>
		</div>
	);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/PatternAdaptationPreview.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/patterns/PatternAdaptationPreview.js src/patterns/__tests__/PatternAdaptationPreview.test.js
git commit -m "feat(patterns): add PatternAdaptationPreview component"
```

---

### Task 9: Extract a shared freshness gate and guarded insert helper (behavior-preserving)

**Files:**
- Modify: `src/patterns/PatternRecommender.js` (refactor `handleInsertPattern` into a reusable awaited freshness gate + an async guarded insert helper)
- Modify: `src/patterns/PatternRecommender.js` import of `./pattern-insertability` (add `getRejectedResolvedBlockNames`)
- Test: `src/patterns/__tests__/PatternRecommender.test.js` (no new behavior — the entire existing suite must stay green)

**Interfaces:**
- Produces `runPatternFreshnessGate( { pattern, recommendation, blocks, liveInput } ): Promise<boolean>` — the FULL awaited revalidation chain (insertion-target signature, pre/post allowed-block re-check, missing-resolved-context, awaited `resolvePatternRecommendationSignature()` server `resolvedContextSignature` comparison), recording the existing `stale_blocked`/`validation_blocked` outcomes + notices + refresh on failure. `blocks` is the resolved block array to validate (top-level names; adaptation never changes names, so adapted and original blocks validate identically).
- Produces `runGuardedInsert( { pattern, recommendation, blocks, e2eFailureMode, successEvent, failureEvent } ): Promise<boolean>` — **async** clone-free snapshot → `await insertBlocks` → verify → rollback → outcome/notice. `blocks` are the FINAL instances to insert (already cloned).
- Both are consumed by `handleInsertPattern` (direct) here and by `handleInsertAdapted` (adapted) in Task 10. This is the fix for the P1 finding: the adapted path MUST run the same awaited server-resolved freshness gate the direct path runs, not just compare stored signatures.

> **Why this differs from a pure insert-only extraction:** the spec's "Insert Adapted" steps 2-5 (target signature, awaited `resolveSignatureOnly` revalidation, server `resolvedContextSignature` match, allowed-block re-check) are governance-critical freshness gates. Extracting only the insert/verify/rollback would let adapted blocks insert after server-context drift. The gate is therefore extracted as a first-class shared helper.

- [ ] **Step 1: Confirm the existing suite is green before refactor**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/PatternRecommender.test.js`
Expected: PASS (baseline before refactor).

- [ ] **Step 2: Extend the `./pattern-insertability` import**

In `src/patterns/PatternRecommender.js`, add `getRejectedResolvedBlockNames` to the existing import:

```js
import {
	filterInsertableRecommendedPatterns,
	getRejectedPatternBlockNames,
	getRejectedResolvedBlockNames,
	getUnsafePatternBindingSourceNames,
	resolvePatternBlocks,
} from './pattern-insertability';
```

- [ ] **Step 3: Add `finalizeGuardedInsert` (unchanged success/rollback/notice logic)**

Add this `useCallback` inside the component (before `runGuardedInsert`). It is the success/rollback/notice block from current lines ~1560-1615, parameterized by event names:

```js
const finalizeGuardedInsert = useCallback(
	( {
		pattern,
		recommendation,
		insertionVerified,
		insertedClientIds,
		successEvent,
		failureEvent,
	} ) => {
		if ( ! insertionVerified ) {
			const insertedOutsideTarget = insertedClientIds.length > 0;
			if ( insertedOutsideTarget && typeof removeBlocks === 'function' ) {
				removeBlocks( insertedClientIds, false );
			}
			const failureMessage = insertedOutsideTarget
				? sprintf(
						/* translators: %s: block pattern title. */
						__(
							'Cannot insert pattern "%s" at the requested location. Gutenberg inserted it somewhere else, so Flavor Agent removed those blocks.',
							'flavor-agent'
						),
						getPatternTitle( pattern )
				  )
				: sprintf(
						/* translators: %s: block pattern title. */
						__(
							'Cannot confirm pattern "%s" was inserted. Gutenberg did not report the inserted blocks at the target location.',
							'flavor-agent'
						),
						getPatternTitle( pattern )
				  );

			recordPatternOutcome( failureEvent, {
				pattern,
				recommendation,
				reason: insertedOutsideTarget
					? 'insert_blocks_wrong_target'
					: 'insert_blocks_noop',
			} );
			createErrorNotice( failureMessage, {
				type: 'snackbar',
				id: 'inserter-notice',
			} );
			return false;
		}

		recordPatternOutcome( successEvent, {
			pattern,
			recommendation,
			reason: 'insert_blocks_success',
		} );
		createSuccessNotice(
			sprintf(
				/* translators: %s: pattern title. */
				__( 'Pattern "%s" inserted.', 'flavor-agent' ),
				getPatternTitle( pattern )
			),
			{ type: 'snackbar', id: 'inserter-notice' }
		);
		return true;
	},
	[ createErrorNotice, createSuccessNotice, recordPatternOutcome, removeBlocks ]
);
```

- [ ] **Step 4: Add the async `runGuardedInsert`**

This is the insert/verify/rollback block from current lines ~1460-1558, made `async` (the original `handleInsertPattern` already `await`s `insertBlocks`; the helper must do the same — `insertBlocks(...).then(...)` would throw on the test's synchronous mock and turn every success into a caught failure):

```js
const runGuardedInsert = useCallback(
	async ( {
		pattern,
		recommendation = null,
		blocks,
		e2eFailureMode = '',
		successEvent,
		failureEvent,
	} ) => {
		let insertionVerified = false;
		let insertedClientIds = [];

		try {
			if ( e2eFailureMode === 'insert_blocks_exception' ) {
				throw new Error( 'E2E forced insertBlocks exception' );
			}

			const beforeBlockEditor = registry?.select?.( blockEditorStore );
			const beforeInsertSnapshot = getBlockListSnapshot(
				beforeBlockEditor,
				inserterRootClientId
			);
			const beforeBlockPresence = getBlockPresenceSnapshot(
				beforeBlockEditor,
				blocks
			);

			if ( e2eFailureMode !== 'insert_blocks_noop' ) {
				let dispatchInsertionIndex = insertionIndex;
				const insertRootClientId = inserterRootClientId ?? '';

				if ( e2eFailureMode === 'insert_blocks_wrong_target' ) {
					dispatchInsertionIndex =
						insertionIndex === 0 ? Number.MAX_SAFE_INTEGER : 0;
				}

				await insertBlocks(
					blocks,
					dispatchInsertionIndex,
					insertRootClientId,
					true
				);

				const afterBlockEditor = registry?.select?.( blockEditorStore );
				const afterInsertSnapshot = getBlockListSnapshot(
					afterBlockEditor,
					inserterRootClientId
				);
				const afterBlockPresence = getBlockPresenceSnapshot(
					afterBlockEditor,
					blocks
				);

				insertionVerified = didInsertBlocksAtTarget(
					beforeInsertSnapshot,
					afterInsertSnapshot,
					blocks,
					insertionIndex
				);
				if ( ! insertionVerified ) {
					insertedClientIds = getInsertedBlockClientIds(
						beforeBlockPresence,
						afterBlockPresence,
						beforeInsertSnapshot,
						afterInsertSnapshot,
						blocks
					);
				}
			}
		} catch {
			recordPatternOutcome( failureEvent, {
				pattern,
				recommendation,
				reason: 'insert_blocks_exception',
			} );
			createErrorNotice(
				sprintf(
					/* translators: %s: block pattern title. */
					__(
						'Cannot insert pattern "%s" because Gutenberg rejected the insertion request.',
						'flavor-agent'
					),
					getPatternTitle( pattern )
				),
				{ type: 'snackbar', id: 'inserter-notice' }
			);
			return false;
		}

		return finalizeGuardedInsert( {
			pattern,
			recommendation,
			insertionVerified,
			insertedClientIds,
			successEvent,
			failureEvent,
		} );
	},
	[
		createErrorNotice,
		finalizeGuardedInsert,
		insertBlocks,
		insertionIndex,
		inserterRootClientId,
		recordPatternOutcome,
		registry,
	]
);
```

- [ ] **Step 5: Add `runPatternFreshnessGate` (the awaited revalidation chain)**

Extract steps 2-6 of the current `handleInsertPattern` (target-signature check, pre allowed-block check, missing-resolved-context, awaited server revalidation, post allowed-block check) into one shared gate. It records the exact same outcomes/notices the inline code does today, so the direct path stays behavior-identical:

```js
const runPatternFreshnessGate = useCallback(
	async ( { pattern, recommendation = null, blocks, liveInput } ) => {
		// 1. Insertion-target signature still matches the ranked target.
		if (
			patternInsertionTargetSignature &&
			currentInsertionTargetSignature &&
			effectivePostType &&
			currentInsertionTargetSignature !== patternInsertionTargetSignature
		) {
			recordPatternOutcome( 'stale_blocked', {
				pattern,
				recommendation,
				reason: 'insertion_target_changed',
			} );
			createErrorNotice(
				sprintf(
					/* translators: %s: block pattern title. */
					__(
						'Cannot insert pattern "%s" because the insertion point has changed since these recommendations were ranked. Refreshing now — try again in a moment.',
						'flavor-agent'
					),
					getPatternTitle( pattern )
				),
				{ type: 'snackbar', id: 'inserter-notice' }
			);
			fetchPatternRecommendationsForCurrentTarget( liveInput );
			return false;
		}

		// Allowed-block re-check against the live editor (runs pre- and
		// post-await; core silently drops disallowed blocks otherwise).
		const rejectIfBlocksDisallowed = () => {
			const rejected = getRejectedResolvedBlockNames(
				blocks,
				inserterRootClientId,
				registry?.select?.( blockEditorStore )
			);

			if ( rejected.length === 0 ) {
				return false;
			}

			recordPatternOutcome( 'validation_blocked', {
				pattern,
				recommendation,
				reason: 'disallowed_block_types',
			} );
			createErrorNotice(
				sprintf(
					/* translators: 1: pattern title 2: comma-separated block names. */
					__(
						'Cannot insert pattern "%1$s" here. The following blocks are not allowed at this insertion point: %2$s.',
						'flavor-agent'
					),
					getPatternTitle( pattern ),
					rejected.join( ', ' )
				),
				{ type: 'snackbar', id: 'inserter-notice' }
			);
			return true;
		};

		if ( rejectIfBlocksDisallowed() ) {
			return false;
		}

		// 2. Missing resolved context / no resolver.
		if (
			! patternResolvedContextSignature ||
			typeof resolvePatternRecommendationSignature !== 'function'
		) {
			recordPatternOutcome( 'stale_blocked', {
				pattern,
				recommendation,
				reason: 'missing_resolved_context',
			} );
			createErrorNotice(
				sprintf(
					/* translators: %s: block pattern title. */
					__(
						'Cannot insert pattern "%s" because Flavor Agent could not verify the current server apply context. Refreshing now — try again in a moment.',
						'flavor-agent'
					),
					getPatternTitle( pattern )
				),
				{ type: 'snackbar', id: 'inserter-notice' }
			);
			fetchPatternRecommendationsForCurrentTarget( liveInput );
			return false;
		}

		// 3. Awaited server-resolved context revalidation.
		try {
			const resolved =
				await resolvePatternRecommendationSignature( liveInput );
			const currentResolvedContextSignature =
				getResolvedContextSignatureFromResponse( resolved );

			if (
				! currentResolvedContextSignature ||
				currentResolvedContextSignature !==
					patternResolvedContextSignature
			) {
				recordPatternOutcome( 'stale_blocked', {
					pattern,
					recommendation,
					reason: 'resolved_context_changed',
				} );
				createErrorNotice(
					sprintf(
						/* translators: %s: block pattern title. */
						__(
							'Cannot insert pattern "%s" because the server-resolved apply context has changed since these recommendations were ranked. Refreshing now — try again in a moment.',
							'flavor-agent'
						),
						getPatternTitle( pattern )
					),
					{ type: 'snackbar', id: 'inserter-notice' }
				);
				fetchPatternRecommendationsForCurrentTarget( liveInput );
				return false;
			}
		} catch {
			recordPatternOutcome( 'stale_blocked', {
				pattern,
				recommendation,
				reason: 'revalidation_failed',
			} );
			createErrorNotice(
				sprintf(
					/* translators: %s: block pattern title. */
					__(
						'Cannot insert pattern "%s" because Flavor Agent could not revalidate the current server apply context. Try again or refresh recommendations.',
						'flavor-agent'
					),
					getPatternTitle( pattern )
				),
				{ type: 'snackbar', id: 'inserter-notice' }
			);
			return false;
		}

		// 4. Post-await allowed-block re-check (state can drift during await).
		if ( rejectIfBlocksDisallowed() ) {
			return false;
		}

		return true;
	},
	[
		createErrorNotice,
		currentInsertionTargetSignature,
		effectivePostType,
		fetchPatternRecommendationsForCurrentTarget,
		inserterRootClientId,
		patternInsertionTargetSignature,
		patternResolvedContextSignature,
		recordPatternOutcome,
		registry,
		resolvePatternRecommendationSignature,
	]
);
```

- [ ] **Step 6: Replace the body of `handleInsertPattern` to route through the gate + guarded insert**

Replace the entire `handleInsertPattern` callback body (the empty-check, target-signature check, both `rejectIfBlocksDisallowed` calls, the resolved-context checks, and the insert/verify/rollback block) with:

```js
const handleInsertPattern = useCallback(
	async ( pattern, recommendation = null ) => {
		const blocks = resolvePatternBlocks( pattern );

		if ( blocks.length === 0 ) {
			recordPatternOutcome( 'validation_blocked', {
				pattern,
				recommendation,
				reason: 'empty_pattern_blocks',
			} );
			createErrorNotice(
				sprintf(
					/* translators: %s: block pattern title. */
					__(
						'Cannot insert pattern "%s" because Gutenberg did not provide insertable block content for it.',
						'flavor-agent'
					),
					getPatternTitle( pattern )
				),
				{ type: 'snackbar', id: 'inserter-notice' }
			);
			return;
		}

		const liveInput = buildBaseInput();

		if (
			! ( await runPatternFreshnessGate( {
				pattern,
				recommendation,
				blocks,
				liveInput,
			} ) )
		) {
			return;
		}

		const clonedBlocks = blocks.map( ( block ) => cloneBlock( block ) );

		await runGuardedInsert( {
			pattern,
			recommendation,
			blocks: clonedBlocks,
			e2eFailureMode: consumeE2EPatternInsertFailureMode( pattern ),
			successEvent: 'pattern_inserted_from_shelf',
			failureEvent: 'insert_failed',
		} );
	},
	[
		buildBaseInput,
		createErrorNotice,
		recordPatternOutcome,
		runGuardedInsert,
		runPatternFreshnessGate,
	]
);
```

- [ ] **Step 7: Run the full PatternRecommender suite to verify behavior is preserved**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/PatternRecommender.test.js`
Expected: PASS — identical outcomes/notices/reasons for the direct path (the refactor is behavior-preserving; the awaited `insertBlocks` matches the original control flow).

- [ ] **Step 8: Lint the changed file**

Run: `npm run lint:js -- --fix src/patterns/PatternRecommender.js`
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add src/patterns/PatternRecommender.js
git commit -m "refactor(patterns): extract shared freshness gate + async guarded insert"
```

---

### Task 10: Wire Preview adapted / Insert original into the shelf and adapted insertion

**Files:**
- Modify: `src/patterns/PatternRecommender.js` (shelf actions, adapted-preview state, lazy adaptation, adapted outcomes, stale blocking)
- Test: `src/patterns/__tests__/PatternRecommender.test.js`

**Interfaces:**
- Consumes: `buildPatternAdaptationPreview` (Task 4-7), `buildPatternAdaptationContext` (Task 3), `collectThemeTokensFromSettings` (`../context/theme-tokens`), `isSyncedPatternReference` (Task 2, shared synced detection), `runPatternFreshnessGate` + `runGuardedInsert` + `recordPatternOutcome` (Task 9), and the `core/blocks` registry select for `blockRegistry`.
- Produces: a `PatternShelf` that renders `Preview adapted` + `Insert original` for non-synced insertable recommendations (synced keep the single `Insert` action); a `PatternAdaptationPreview` mounted in the portal; adapted insertion via `runGuardedInsert` with `successEvent: 'adapted_inserted_from_preview'`, `failureEvent: 'adapted_insert_failed'`; outcomes `adapted_preview_shown` / `adaptation_blocked`; stale handling recording `stale_blocked` with `adapted_preview_stale`.

- [ ] **Step 1: Write the failing test (shelf offers Preview adapted for non-synced)**

Add to `src/patterns/__tests__/PatternRecommender.test.js` a test in the shelf-rendering describe block that drives a ready non-synced recommendation and asserts a `Preview adapted` control plus an `Insert original` control are rendered (model on the existing "renders the recommendation shelf" test; reuse its state setup). Sketch:

```js
test( 'offers Preview adapted and Insert original for non-synced patterns', () => {
	primeReadyShelf( {
		recommendation: { name: 'theme/hero', reason: 'Hero.' },
		pattern: {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [ { name: 'core/heading', attributes: { level: 2 } } ],
		},
	} ); // existing helper that sets state + renders an open inserter with one ready pattern

	const labels = [ ...getRoot().querySelectorAll( 'button' ) ].map(
		( node ) => node.textContent
	);
	expect( labels ).toContain( 'Preview adapted' );
	expect( labels ).toContain( 'Insert original' );
} );
```

> If `primeReadyShelf` does not already exist, factor the setup from the existing "renders the recommendation shelf" test into a local helper in this file and reuse it.

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/PatternRecommender.test.js -t "Preview adapted"`
Expected: FAIL — only a single `Insert` button is rendered today.

- [ ] **Step 3: Add imports and adaptation wiring in `src/patterns/PatternRecommender.js`**

Add these new module imports near the existing pattern imports:

```js
import { collectThemeTokensFromSettings } from '../context/theme-tokens';
import { buildPatternAdaptationContext } from './pattern-adaptation-context';
import { buildPatternAdaptationPreview } from './pattern-adaptation';
import PatternAdaptationPreview from './PatternAdaptationPreview';
```

For the synced-detection helper, **extend the existing `./pattern-insertability` import** (the same statement Task 9 already extended with `getRejectedResolvedBlockNames`) — do not add a second import from that module:

```js
import {
	filterInsertableRecommendedPatterns,
	getRejectedPatternBlockNames,
	getRejectedResolvedBlockNames,
	getUnsafePatternBindingSourceNames,
	isSyncedPatternReference,
	resolvePatternBlocks,
} from './pattern-insertability';
```

The shelf reuses `isSyncedPatternReference` (the same helper the adaptation engine uses), so synced detection cannot diverge between the two. No local `isSyncedPattern` helper is added.

Add adapted-preview component state and a memoized block registry inside the component:

```js
const [ adaptedPreview, setAdaptedPreview ] = useState( null );
const blockRegistry = useMemo(
	() => registry.select( 'core/blocks' ),
	[ registry ]
);
const blockEditorSettings = useSelect(
	( select ) => select( blockEditorStore ).getSettings?.() || {},
	[]
);
```

Add a `buildCurrentAdaptation` callback that builds an adaptation for the current live target. Both the preview action and the insert-time freshness recheck call it, so the `adaptationSignature` is computed identically in both places:

```js
const buildCurrentAdaptation = useCallback(
	( pattern ) => {
		const sourceBlocks = resolvePatternBlocks( pattern );
		const liveEditor = registry?.select?.( blockEditorStore );
		const adaptationContext = buildPatternAdaptationContext( liveEditor, {
			inserterRootClientId,
			insertionIndex,
			siblingOrder: insertionSiblingOrder,
		} );

		return buildPatternAdaptationPreview( {
			pattern,
			sourceBlocks,
			adaptationContext,
			insertionTargetSignature: currentInsertionTargetSignature,
			resolvedContextSignature: patternResolvedContextSignature,
			themeTokens: collectThemeTokensFromSettings( blockEditorSettings ),
			blockRegistry,
		} );
	},
	[
		blockEditorSettings,
		blockRegistry,
		currentInsertionTargetSignature,
		insertionIndex,
		inserterRootClientId,
		insertionSiblingOrder,
		patternResolvedContextSignature,
		registry,
	]
);
```

Add a `handlePreviewAdapted` callback that builds the adaptation lazily, records the diagnostic, and opens the panel:

```js
const handlePreviewAdapted = useCallback(
	( pattern, recommendation = null ) => {
		const result = buildCurrentAdaptation( pattern );

		if ( result.status === 'blocked' ) {
			recordPatternOutcome( 'adaptation_blocked', {
				pattern,
				recommendation,
				reason: result.reason,
			} );
		} else {
			recordPatternOutcome( 'adapted_preview_shown', {
				pattern,
				recommendation,
				reason: 'adapted_preview_ready',
			} );
		}

		setAdaptedPreview( {
			pattern,
			recommendation,
			status: result.status,
			blocks: result.blocks,
			changes: result.plan?.changes || [],
			adaptationSignature: result.adaptationSignature,
		} );
	},
	[ buildCurrentAdaptation, recordPatternOutcome ]
);
```

> The stored `adaptationSignature` already folds in the insertion-target signature, the resolved-context signature, AND the client-only `adaptationContext`, so it is the single value the insert-time recheck compares against — no separate signature fields need to be stored on `adaptedPreview`.

Add an adapted-insert callback that re-checks adaptation freshness, runs the shared server gate, and inserts the exact previewed instances:

```js
const handleInsertAdapted = useCallback( async () => {
	if ( ! adaptedPreview || adaptedPreview.status !== 'ready' ) {
		return;
	}

	const { pattern, recommendation, blocks } = adaptedPreview;

	// Layer 1 — adaptation freshness (P1 fix). adaptationSignature folds in the
	// insertion-target signature, the resolved-context signature, AND the
	// client-only adaptationContext (heading levels / align values). Rebuild
	// against the live editor and compare: a heading/align edit after preview —
	// which moves NEITHER the insertion-target nor the resolved-context
	// signature — still invalidates the preview here. This single comparison
	// subsumes the narrower target/resolved-context-only check.
	const fresh = buildCurrentAdaptation( pattern );
	if (
		fresh.status !== 'ready' ||
		fresh.adaptationSignature !== adaptedPreview.adaptationSignature
	) {
		recordPatternOutcome( 'stale_blocked', {
			pattern,
			recommendation,
			reason: 'adapted_preview_stale',
		} );
		setAdaptedPreview( { ...adaptedPreview, status: 'stale' } );
		fetchPatternRecommendationsForCurrentTarget( buildBaseInput() );
		return;
	}

	// Layer 2 — the SAME awaited server-resolved freshness gate the direct path
	// runs: re-runs resolvePatternRecommendationSignature, compares the live
	// server resolvedContextSignature, and re-checks allowed blocks WITH a
	// visible disallowed-block notice (no silent failure). Catches server-context
	// drift since the preview was built.
	if (
		! ( await runPatternFreshnessGate( {
			pattern,
			recommendation,
			blocks,
			liveInput: buildBaseInput(),
		} ) )
	) {
		return;
	}

	const inserted = await runGuardedInsert( {
		pattern,
		recommendation,
		blocks,
		e2eFailureMode: consumeE2EPatternInsertFailureMode( pattern ),
		successEvent: 'adapted_inserted_from_preview',
		failureEvent: 'adapted_insert_failed',
	} );

	if ( inserted ) {
		setAdaptedPreview( null );
	}
}, [
	adaptedPreview,
	buildBaseInput,
	buildCurrentAdaptation,
	fetchPatternRecommendationsForCurrentTarget,
	recordPatternOutcome,
	runGuardedInsert,
	runPatternFreshnessGate,
] );
```

> Note: the adapted path inserts the SAME previewed instances (no re-clone) per the Global Constraints — `runGuardedInsert` does not clone, so pass `adaptedPreview.blocks` directly. Layer 1 rebuilds the adaptation only to compare its `adaptationSignature`; because adaptation is deterministic, an unchanged signature guarantees the previewed blocks are still the correct output. Disallowed-block feedback is owned by the shared gate (Layer 2), which surfaces the same notice the direct path shows — there is no silent precheck.

- [ ] **Step 4: Update `PatternShelf` to render the two actions and mount the preview panel**

Change the `PatternShelf` signature to accept the adapted handlers and render per-item actions:

```js
function PatternShelf( {
	items,
	onInsert,
	onPreviewAdapted,
	diagnostics,
} ) {
	// ...unchanged header/status/filtered-note...
	// Replace the single Insert <Button> with (shared synced detection so the
	// shelf and the adaptation engine never disagree, including resolved
	// single-core/block references):
	const synced = isSyncedPatternReference( pattern );
	return (
		// ...item wrapper + body unchanged...
		<div className="flavor-agent-pattern-shelf__actions">
			{ ! synced && (
				<Button
					variant="secondary"
					size="small"
					onClick={ () => onPreviewAdapted( pattern, recommendation ) }
					className="flavor-agent-card__apply"
				>
					{ __( 'Preview adapted', 'flavor-agent' ) }
				</Button>
			) }
			<Button
				variant={ synced ? 'secondary' : 'tertiary' }
				size="small"
				onClick={ () => onInsert( pattern, recommendation ) }
				className="flavor-agent-card__apply"
				aria-label={ sprintf(
					/* translators: %s: block pattern title. */
					synced
						? __( 'Insert %s', 'flavor-agent' )
						: __( 'Insert original %s', 'flavor-agent' ),
					patternTitle
				) }
			>
				{ synced
					? __( 'Insert', 'flavor-agent' )
					: __( 'Insert original', 'flavor-agent' ) }
			</Button>
		</div>
	);
}
```

In the render branch where `<PatternShelf ... />` is created, pass `onPreviewAdapted={ handlePreviewAdapted }`. Then render the adapted-preview panel inside the portal, after `{ notice }`:

```js
return (
	<PatternInserterPortal onAttached={ recordShownPatternOutcome }>
		{ docsGroundingNotice }
		{ notice }
		{ adaptedPreview && (
			<PatternAdaptationPreview
				title={ getPatternTitle( adaptedPreview.pattern ) }
				status={ adaptedPreview.status }
				changes={ adaptedPreview.changes }
				blocks={ adaptedPreview.blocks }
				isStale={ adaptedPreview.status === 'stale' }
				onInsertAdapted={ handleInsertAdapted }
				onInsertOriginal={ () => {
					handleInsertPattern(
						adaptedPreview.pattern,
						adaptedPreview.recommendation
					);
					setAdaptedPreview( null );
				} }
				onClose={ () => setAdaptedPreview( null ) }
			/>
		) }
	</PatternInserterPortal>
);
```

- [ ] **Step 5: Add adapted-flow tests**

Add to `src/patterns/__tests__/PatternRecommender.test.js`:
- a test that clicking `Preview adapted` for a non-synced heading pattern (with a preceding heading level in state) records an `adapted_preview_shown` outcome and renders the preview panel;
- a test that clicking `Insert adapted` **calls `mockResolvePatternRecommendationSignature`** (proving the adapted path runs the awaited server freshness gate, not just stored-signature comparison), then inserts the previewed blocks through `mockInsertBlocks` and records `adapted_inserted_from_preview`;
- a test that `Insert adapted` records `stale_blocked` / `resolved_context_changed` and does NOT call `mockInsertBlocks` when `mockResolvePatternRecommendationSignature` resolves a different `resolvedContextSignature` than the previewed one (server drift);
- **(P1) a test that mutating a nearby heading's `level` in `state.blockEditor` AFTER `Preview adapted` (without changing block names or the resolved-context signature) causes `Insert adapted` to record `stale_blocked` / `adapted_preview_stale` and NOT call `mockInsertBlocks`** — proving the adaptation-signature recheck catches `adaptationContext` drift the target/resolved-context signatures miss;
- **(P2) a test that a disallowed adapted insert surfaces the gate's notice** — when `mockCanInsertBlockType` returns false at insert time, `Insert adapted` calls `mockCreateErrorNotice` and records `validation_blocked` (no silent return);
- a test that a normal multi-block pattern shows `Preview adapted`, **and a test that a resolved single `core/block` reference pattern (no `type`/`id`) shows only a single `Insert` action (no `Preview adapted`)** — covering the shared `isSyncedPatternReference` path;
- a test that a `type: 'user'` synced pattern shows only a single `Insert` action.

Model each on the existing direct-insert tests (they already assert `mockInsertBlocks` / `mockResolvePatternRecommendationSignature` / `mockCreateErrorNotice` / `mockRecordRecommendationOutcome` calls). Drive the preceding-heading signal by populating `state.blockEditor.blocks`/`blockOrder`/`blockNames` so the live-editor read in `buildPatternAdaptationContext` returns a `precedingHeadingLevel`. Add a `'core/blocks'` entry to `createSelectMap()` exposing `getBlockType`/`getBlockStyles` so `blockRegistry` resolves in the adapted tests.

- [ ] **Step 6: Run the full suite to verify it passes**

Run: `npm run test:unit -- --runInBand src/patterns/__tests__/PatternRecommender.test.js`
Expected: PASS (existing direct-path tests + new adapted tests).

- [ ] **Step 7: Lint the changed file**

Run: `npm run lint:js -- --fix src/patterns/PatternRecommender.js`
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add src/patterns/PatternRecommender.js src/patterns/__tests__/PatternRecommender.test.js
git commit -m "feat(patterns): wire adapted preview + insert into the inserter shelf"
```

---

### Task 11: Scoped styles for the adapted-preview panel

**Files:**
- Modify: `src/editor.css` (add `flavor-agent-pattern-adaptation*` and `flavor-agent-pattern-shelf__actions` rules under the existing pattern namespace)

**Interfaces:**
- Consumes: existing `flavor-agent-pattern-*` and `flavor-agent-pill*` class conventions.
- Produces: scoped layout for the preview panel and BlockPreview container. No new global selectors.

- [ ] **Step 1: Add the styles**

Append to the pattern section of `src/editor.css`:

```css
.flavor-agent-pattern-shelf__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.flavor-agent-pattern-adaptation {
	margin-top: 12px;
	padding: 12px;
	border: 1px solid var( --wp-admin-theme-color, #3858e9 );
	border-radius: 4px;
	background: #fff;
}

.flavor-agent-pattern-adaptation__header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.flavor-agent-pattern-adaptation__title {
	font-weight: 600;
}

.flavor-agent-pattern-adaptation__status {
	margin: 0 0 8px;
	font-size: 12px;
}

.flavor-agent-pattern-adaptation__changes {
	margin: 0 0 8px;
	padding-left: 18px;
	font-size: 12px;
}

.flavor-agent-pattern-adaptation__preview {
	max-height: 320px;
	overflow: auto;
	border: 1px solid #e0e0e0;
	border-radius: 2px;
	margin-bottom: 8px;
}

.flavor-agent-pattern-adaptation__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
```

- [ ] **Step 2: Build to verify CSS compiles into the bundle**

Run: `npm run build`
Expected: build succeeds; `build/index.js` regenerated.

- [ ] **Step 3: Commit**

```bash
git add src/editor.css
git commit -m "feat(patterns): scoped styles for adapted-preview panel"
```

---

### Task 12: Extend the pattern E2E smoke for adapted insertion

**Files:**
- Modify: `tests/e2e/flavor-agent.smoke.spec.js` (extend the existing pattern-insert harness to cover an adapted insertion path)

**Interfaces:**
- Consumes: the existing `window.flavorAgentData.e2ePatternInsertFailureHarness` + `window.__flavorAgentPatternInsertFailures` hook (already consumed by `consumeE2EPatternInsertFailureMode`, which `runGuardedInsert` uses for both paths).
- Produces: a Playground smoke assertion that `Preview adapted` opens the panel and `Insert adapted` inserts blocks, plus that the existing forced-failure modes still roll back under the adapted path.

- [ ] **Step 1: Add the adapted-path smoke**

In `tests/e2e/flavor-agent.smoke.spec.js`, locate the existing pattern-insert smoke (search for `Insert` shelf interactions / `__flavorAgentPatternInsertFailures`). Add a test that:
1. opens the inserter so the Flavor Agent shelf renders a non-synced ranked pattern,
2. clicks `Preview adapted` and asserts the `.flavor-agent-pattern-adaptation` panel appears with a BlockPreview,
3. clicks `Insert adapted` and asserts the editor block count increased at the target,
4. reuses the forced `insert_blocks_wrong_target` harness to assert the adapted path rolls back and surfaces the wrong-target notice.

Follow the existing spec's selectors and helpers (it already drives the inserter and reads notices). Keep new assertions additive — do not modify the existing direct-insert smoke.

- [ ] **Step 2: Run the Playground smoke**

Run: `npm run test:e2e:playground`
Expected: PASS, including the new adapted-path assertions. If `BlockPreview`/iframe rendering is unreliable in Playground, gate the preview-render assertion behind the WP70 harness and record the reason in the closeout (run `npm run test:e2e:wp70 -- tests/e2e/flavor-agent.smoke.spec.js` instead for that assertion).

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/flavor-agent.smoke.spec.js
git commit -m "test(patterns): e2e smoke for adapted preview insertion"
```

---

### Task 13: Documentation updates and cross-surface validation

**Files:**
- Modify: `docs/features/pattern-recommendations.md` (document adapted preview, unchanged original insertion, unchanged synced behavior)
- Modify: `docs/features/pattern-recommendations-adapted-preview.md` (mark v1 slice shipped; keep deferred content-adaptation / synced-detachment decisions explicit)
- Modify: `docs/reference/current-open-work.md` (move "Pattern adapted preview" out of Current Implementation Candidates)
- Modify (only if generic enough): `docs/reference/block-operation-pipeline-extension-notes.md` (pointer to the shared mutation module)

**Interfaces:**
- Consumes: the shipped behavior from Tasks 1-12.
- Produces: docs aligned with `npm run check:docs` and the cross-surface validation gates.

- [ ] **Step 1: Check line endings before editing docs**

Run: `file docs/features/pattern-recommendations.md docs/features/pattern-recommendations-adapted-preview.md docs/reference/current-open-work.md`
If any reports `CRLF`, edit with a CR-preserving method (restore from HEAD then re-apply with `perl`/`awk`) and re-check with `git diff --check`. `pattern-recommendations.md` is LF in the current tree; treat the others defensively.

- [ ] **Step 2: Update the three docs**

- `docs/features/pattern-recommendations.md`: add an "Adapted preview" subsection describing the `Preview adapted` action, the deterministic mutation set (heading level, alignment, color/spacing preset remap, button style), the client-only `adaptationContext`, the stale/blocked behavior, and that original + synced insertion are unchanged. List the new outcome events.
- `docs/features/pattern-recommendations-adapted-preview.md`: mark the v1 slice shipped (date 2026-06-19), note deferred content adaptation and synced detachment remain explicit follow-ups.
- `docs/reference/current-open-work.md`: remove the "Pattern adapted preview" row from "Current Implementation Candidates" and add a dated refresh line recording the ship + plan archive path.

- [ ] **Step 3: Run the docs freshness guard**

Run: `npm run check:docs`
Expected: PASS (no stale-doc failures).

- [ ] **Step 4: Run the aggregate non-E2E verification (cross-surface gate)**

Run: `node scripts/verify.js --skip-e2e`
Then inspect `output/verify/summary.json` and confirm `status: "pass"`.
Expected: build, lint-js, unit, lint-php, test-php all pass. (Use `--skip=lint-plugin` if WP-CLI / WP root is unavailable, and record that in the closeout.)

- [ ] **Step 5: Run the matching Playwright harness (pattern surface)**

Run: `npm run test:e2e:playground`
Expected: PASS. If a harness is known-red or unavailable, record the blocker or an explicit waiver in the closeout instead of silently skipping (per `docs/reference/cross-surface-validation-gates.md`).

- [ ] **Step 6: Confirm no stray line-ending churn**

Run: `git diff --check`
Expected: no output (no whitespace/CR errors).

- [ ] **Step 7: Commit**

```bash
git add docs/features/pattern-recommendations.md docs/features/pattern-recommendations-adapted-preview.md docs/reference/current-open-work.md docs/reference/block-operation-pipeline-extension-notes.md
git commit -m "docs(patterns): document shipped adapted preview v1 and update open-work"
```

---

## Self-Review

**Spec coverage** (against `docs/superpowers/specs/2026-06-18-pattern-adapted-preview-design.md`):
- Shelf actions (Preview adapted / Insert original; synced unchanged) → Task 10. ✔
- Adaptation module + public shape + ready/blocked return → Tasks 4-7. ✔
- Safe mutation rules (heading, align, color, spacing, button; preset-only; no name/structure/binding changes) → Tasks 5-7 (color/spacing remap restricted to existing theme presets; button limited to registered variations). ✔
- Preview UI (BlockPreview, change reasons, Insert adapted/original/Close, stale handling) → Task 8 + Task 10. ✔
- Shared freshness gate + guarded insert (target-signature + awaited server `resolvedContextSignature` revalidation + allowed-block re-check, then dispatch, verify, rollback) → Task 9 extracts both `runPatternFreshnessGate` and async `runGuardedInsert`; BOTH the direct path (Task 9) and the adapted path (Task 10) run the identical awaited server gate before dispatch. ✔
- Synced handling (never offer adapted; original still available) → one shared `isSyncedPatternReference` helper (Task 2) used by BOTH the shelf (Task 10) and the adaptation engine (Task 4), covering `type`/`id` user patterns AND resolved single `core/block` references; `unsupported_synced_reference` blocked-reason in Task 4. ✔
- Diagnostics: events `adapted_preview_shown` / `adapted_inserted_from_preview` / `adaptation_blocked` / `adapted_insert_failed` and new reason codes → Task 1 (allowlist) + Tasks 4/10 (emission). Reason codes are free-form on both sides, so `adapted_preview_stale`, `missing_theme_tokens`, `unsupported_block_support`, `adapted_blocks_not_insertable`, `unsupported_synced_reference`, `insert_blocks_*` all pass normalization. ✔
- Files & responsibilities (PatternRecommender, PatternAdaptationPreview, pattern-adaptation, pattern-insertability, theme-tokens reuse, recommendation-outcomes JS+PHP, editor.css, tests, e2e, docs) → all mapped across Tasks 1-13. ✔
- Testing strategy (layered unit → php → build → lint → check:docs → git diff --check) → folded into per-task steps and Task 13. ✔
- Rollout (additive, no flag/migration, fail-closed preview) → adapted action only renders for already-insertable non-synced shelf items; blocked/stale leave original available. ✔

> Deviation from the spec, by explicit user decision (2026-06-19): the design left heading/alignment signal and per-mutation targets open. This plan adds a **client-only `adaptationContext`** (Task 3) for heading/alignment signal and uses **active local/theme match** target rules (heading = preceding+1, align = container align, color/spacing = role/nearest remap, button = first non-default variation). `adaptationContext` is excluded from ranking/freshness inputs and folded into `adaptationSignature` only.

**Placeholder scan:** No "TBD"/"add validation"/"similar to Task N" steps; each code step shows real code. Task 10 Step 5 and Task 12 Step 1 describe test/spec additions modeled on existing in-file patterns rather than pasting the full multi-hundred-line harness — acceptable because the harness setup is large and file-local; the asserted behaviors and the exact outcome events/handlers they verify are specified.

**Type consistency:** `buildPatternAdaptationPreview` return shape (`status`/`blocks`/`plan`/`adaptationSignature`) is consistent across Tasks 4-10. `runPatternFreshnessGate( { pattern, recommendation, blocks, liveInput } )` and `runGuardedInsert`/`finalizeGuardedInsert` event params (`successEvent`/`failureEvent`) match between Task 9 (definition) and Task 10 (adapted call). Outcome event names match exactly across Task 1 (allowlist), Task 4/10 (emission), and the design's diagnostics list. `adaptationContext` field names (`precedingHeadingLevel`, `nearbyHeadingLevels`, `rootAlign`, `siblingAligns`) match between Task 3 (producer) and Tasks 5-6 (consumers). `colorRule( attribute, path )` path signature matches its registration in Tasks 6-7.

## Review Pass 1 — 2026-06-19

Applied fixes from a plan review (all verified against current source):
- **P1 (freshness):** the adapted path now runs the same awaited `runPatternFreshnessGate` (re-runs `resolvePatternRecommendationSignature` + server `resolvedContextSignature` comparison) as the direct path — Task 9 extracts the gate as a shared helper; Task 10 calls it; a server-drift test is required (Task 10 Step 5).
- **P1 (refactor break):** `runGuardedInsert` is `async`/`await insertBlocks(...)`, not `.then(...)`, so the synchronous-mock direct-insert tests stay green.
- **P2 (support contract):** `colorRule` reuses the tested `getBlockStyleSupportedStylePathsFromTokens` exact-path gate; `spacingRule` remaps only the spacing facets (`padding`/`margin`/`blockGap`) the block type declares support for. No looser "omitted = enabled" check.
- **P3 (sibling align):** `alignmentRule` falls back to the most-frequent nearby `siblingAligns` when the container has no align, with a dedicated test.

## Review Pass 2 — 2026-06-19

Applied fixes from a second plan review (all verified against current source):
- **P1 (adaptation-context staleness):** `handleInsertAdapted` now rebuilds the adaptation (`buildCurrentAdaptation`) and compares `adaptationSignature` before insert. Because that signature folds in the insertion-target signature, resolved-context signature, AND the client-only `adaptationContext` (heading levels / align values), a heading/align edit after preview — which moves neither insertion-target nor resolved-context signature — now blocks the stale clone. This single comparison replaces the narrower two-signature check. New regression test required (Task 10 Step 5).
- **P2 (silent not-insertable):** removed the duplicate `isResolvedBlockArrayInsertable` precheck (which returned with no notice). Disallowed adapted blocks now flow through the shared gate's allowed-block path, which shows the same `validation_blocked` notice the direct path shows. `isResolvedBlockArrayInsertable` is dropped from Task 2 (it had no remaining caller).
- **P2 (synced detection divergence):** introduced one shared `isSyncedPatternReference( pattern, sourceBlocks? )` in `pattern-insertability.js`, used by both the shelf and the adaptation engine. It matches `resolvePatternBlocks` semantics, so resolved single-`core/block` references are treated as synced everywhere. Shelf test for a resolved `core/block` reference added (Task 10 Step 5).
- **P3 (duplicate import):** Task 10 now extends the existing `./pattern-insertability` import (the one Task 9 already touched) with `isSyncedPatternReference` instead of adding a second import statement — no duplicate-import lint churn.
