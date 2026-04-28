# Block And Pattern Recommendation Surfaces Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the reviewed block inspector and block/pattern recommendation surface defects so stale pattern responses cannot overwrite current inserter results, inserter search remains active after Gutenberg DOM replacement, active block style context is exact, and shadow recommendations have an explicit delegated-surface decision.

**Architecture:** Keep the existing surface model intact. Add request identity to the pattern store using the same token/signature pattern already used by block, navigation, template, style book, and global styles surfaces; keep DOM attachment logic local to `PatternRecommender`; keep block context fixes inside the context collector path; and make shadow delegation match the current Gutenberg Border/Shadow panel shape.

**Tech Stack:** WordPress plugin, Gutenberg data stores, React via `@wordpress/element`, `@wordpress/scripts` Jest tests, Playwright smoke coverage, PHP unchanged.

---

## Context

The review found four issues:

- Pattern recommendation responses are stored without a request token or request signature, so stale async completions can replace the current inserter shelf.
- The active inserter search input listener attaches once and does not reattach when Gutenberg replaces the search field while the inserter stays open.
- `extractActiveStyle()` matches style classes with substring checks, so `is-style-outline-large` can be reported as `outline`.
- Shadow recommendations are accepted by the block recommendation contract as `panel: "shadow"` but have no passive delegated chip surface. Gutenberg renders shadow controls in the native Border/Shadow group, so the fix is to delegate `panel: "shadow"` into `InspectorControls group="border"`.

Commit steps below are checkpoint suggestions for an isolated worktree. In the shared repo workspace, do not commit until `git status --short` confirms only task-owned files are staged; otherwise leave the edits uncommitted and report the verification output.

WordPress Core AI planning note: this plan intentionally stays on stable plugin and Gutenberg extension points. The April 25, 2026 WordPress 7.1 planning post treats WP AI REST endpoints, the `@wordpress/ai` JavaScript client, broader Abilities API filtering/execution hooks, and WebMCP as open or future work, so these tasks must not depend on those surfaces. Future feature detection for core AI client JavaScript belongs in a separate adapter plan.

## File Structure

- Modify `src/utils/recommendation-request-signature.js`: add a pattern request signature builder that covers prompt, post/template scope, visible patterns, insertion context, selected block context, and activity document scope.
- Modify `src/store/index.js`: add pattern request metadata, stale reducer guards, selector accessors, and token/signature threading through `fetchPatternRecommendations()`.
- Modify `src/store/__tests__/pattern-status.test.js`: prove stale pattern reducer completions are ignored.
- Modify `src/store/__tests__/store-actions.test.js`: prove async pattern requests dispatch token/signature metadata and carry the expected API payload.
- Modify `src/patterns/PatternRecommender.js`: keep the search-input observer alive while the inserter is open and reattach when the resolved input element changes.
- Modify `src/patterns/__tests__/PatternRecommender.test.js`: prove search listener reattachment after input replacement and update observer-count assumptions.
- Modify `src/context/block-inspector.js`: tokenize `className` before matching active block styles.
- Modify `src/context/__tests__/block-inspector.test.js`: prove prefix style names do not create false active style matches.
- Modify `src/inspector/panel-delegation.js`: add a shadow delegation that renders `panel: "shadow"` suggestions in the native `border` group.
- Modify `src/inspector/InspectorInjector.js`: make delegated style sub-panel keys include both `group` and `panel` so border and shadow can share the native border group without duplicate React keys.
- Modify `src/inspector/__tests__/panel-delegation.test.js` and `src/inspector/__tests__/InspectorInjector.test.js`: prove shadow is delegated and rendered passively.
- Modify `docs/features/block-recommendations.md` and `docs/reference/recommendation-ui-consistency.md`: document that shadow chips are mirrored inside the Border/Shadow group.

---

### Task 1: Add Pattern Request Signatures And Reducer Guards

**Files:**
- Modify: `src/utils/recommendation-request-signature.js`
- Modify: `src/store/index.js`
- Test: `src/store/__tests__/pattern-status.test.js`

- [ ] **Step 1: Write the failing reducer tests**

Add these tests to `src/store/__tests__/pattern-status.test.js` inside `describe( 'pattern status store contract', ... )`:

```js
test( 'stale pattern completions are ignored', () => {
	let state = reducer(
		undefined,
		actions.setPatternStatus( 'loading', null, 1, 'signature-a' )
	);
	state = reducer(
		state,
		actions.setPatternStatus( 'loading', null, 2, 'signature-b' )
	);
	state = reducer(
		state,
		actions.setPatternRecommendations(
			[
				{
					name: 'theme/fresh',
					reason: 'Fresh result.',
					score: 0.98,
				},
			],
			2,
			'signature-b'
		)
	);
	state = reducer(
		state,
		actions.setPatternStatus( 'ready', null, 2, 'signature-b' )
	);

	const staleRecommendationsState = reducer(
		state,
		actions.setPatternRecommendations(
			[
				{
					name: 'theme/stale',
					reason: 'Stale result.',
					score: 0.99,
				},
			],
			1,
			'signature-a'
		)
	);
	const staleStatusState = reducer(
		staleRecommendationsState,
		actions.setPatternStatus(
			'error',
			'Old request failed.',
			1,
			'signature-a'
		)
	);

	expect( staleStatusState.patternRecommendations ).toEqual( [
		{
			name: 'theme/fresh',
			reason: 'Fresh result.',
			score: 0.98,
		},
	] );
	expect( staleStatusState.patternStatus ).toBe( 'ready' );
	expect( staleStatusState.patternError ).toBeNull();
	expect( staleStatusState.patternRequestToken ).toBe( 2 );
	expect( staleStatusState.patternRequestSignature ).toBe( 'signature-b' );
} );

test( 'same-token pattern completions with mismatched signatures are ignored', () => {
	let state = reducer(
		undefined,
		actions.setPatternStatus( 'loading', null, 5, 'signature-current' )
	);
	state = reducer(
		state,
		actions.setPatternRecommendations(
			[
				{
					name: 'theme/current',
					reason: 'Current result.',
					score: 0.98,
				},
			],
			5,
			'signature-current'
		)
	);
	state = reducer(
		state,
		actions.setPatternStatus( 'ready', null, 5, 'signature-current' )
	);

	const staleRecommendationsState = reducer(
		state,
		actions.setPatternRecommendations(
			[
				{
					name: 'theme/same-token-stale',
					reason: 'Same token, stale signature.',
					score: 0.99,
				},
			],
			5,
			'signature-stale'
		)
	);
	const staleStatusState = reducer(
		staleRecommendationsState,
		actions.setPatternStatus(
			'error',
			'Same-token stale request failed.',
			5,
			'signature-stale'
		)
	);

	expect( staleStatusState.patternRecommendations ).toEqual( [
		{
			name: 'theme/current',
			reason: 'Current result.',
			score: 0.98,
		},
	] );
	expect( staleStatusState.patternStatus ).toBe( 'ready' );
	expect( staleStatusState.patternError ).toBeNull();
	expect( staleStatusState.patternRequestToken ).toBe( 5 );
	expect( staleStatusState.patternRequestSignature ).toBe(
		'signature-current'
	);
} );
```

- [ ] **Step 2: Run the focused test and confirm it fails**

Run:

```bash
npm run test:unit -- --runTestsByPath src/store/__tests__/pattern-status.test.js
```

Expected: FAIL because `setPatternStatus()` and `setPatternRecommendations()` do not accept request metadata yet, and stale pattern completions are not ignored.

- [ ] **Step 3: Add a pattern request signature builder**

In `src/utils/recommendation-request-signature.js`, add this helper near `normalizeStringValue()`:

```js
function normalizeStringArray( value ) {
	if ( ! Array.isArray( value ) ) {
		return [];
	}

	return [
		...new Set(
			value
				.map( ( entry ) => normalizeStringValue( entry ) )
				.filter( Boolean )
		),
	].sort();
}
```

Add this export after `buildNavigationRecommendationRequestSignature()`:

```js
export function buildPatternRecommendationRequestSignature( input = {} ) {
	const normalizedInput =
		input && typeof input === 'object' && ! Array.isArray( input )
			? input
			: {};

	return buildContextSignature( {
		surface: 'pattern',
		postType: normalizeStringValue( normalizedInput.postType ),
		templateType: normalizeStringValue( normalizedInput.templateType ),
		prompt: normalizeStringValue( normalizedInput.prompt ),
		visiblePatternNames: normalizeStringArray(
			normalizedInput.visiblePatternNames
		),
		insertionContext: normalizedInput.insertionContext || null,
		blockContext: normalizedInput.blockContext || null,
		document: normalizedInput.document || null,
	} );
}
```

- [ ] **Step 4: Add pattern request metadata to store state and actions**

In `src/store/index.js`, extend the import from `../utils/recommendation-request-signature`:

```js
import {
	buildBlockRecommendationRequestSignature,
	buildNavigationRecommendationRequestSignature,
	buildPatternRecommendationRequestSignature,
} from '../utils/recommendation-request-signature';
```

Add fields to `initialState` beside the existing pattern state:

```js
patternRecommendations: [],
patternStatus: 'idle',
patternError: null,
patternBadge: null,
patternRequestToken: 0,
patternResultToken: 0,
patternRequestSignature: '',
```

Add this stale guard near `isStaleNavigationRequest()`:

```js
function isStalePatternRequest(
	state,
	requestToken,
	requestSignature = ''
) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	const currentToken = state.patternRequestToken || 0;

	if ( requestToken < currentToken ) {
		return true;
	}

	return (
		requestToken === currentToken &&
		Boolean( requestSignature ) &&
		Boolean( state.patternRequestSignature ) &&
		requestSignature !== state.patternRequestSignature
	);
}
```

Replace the pattern action creators with:

```js
setPatternStatus(
	status,
	error = null,
	requestToken = null,
	requestSignature = ''
) {
	return {
		type: 'SET_PATTERN_STATUS',
		status,
		error,
		requestToken,
		requestSignature,
	};
},

setPatternRecommendations(
	recommendations,
	requestToken = null,
	requestSignature = ''
) {
	return {
		type: 'SET_PATTERN_RECS',
		recommendations,
		requestToken,
		requestSignature,
	};
},
```

Replace the pattern reducer cases with:

```js
case 'SET_PATTERN_STATUS':
	if (
		isStalePatternRequest(
			state,
			action.requestToken,
			action.requestSignature
		)
	) {
		return state;
	}

	return {
		...state,
		patternStatus: action.status,
		patternError: action.error ?? null,
		patternRequestToken:
			action.requestToken ?? state.patternRequestToken,
		patternRequestSignature:
			action.requestSignature || state.patternRequestSignature,
	};
```

```js
case 'SET_PATTERN_RECS':
	if (
		isStalePatternRequest(
			state,
			action.requestToken,
			action.requestSignature
		)
	) {
		return state;
	}

	return {
		...state,
		patternRecommendations: action.recommendations,
		patternBadge: getPatternBadgeReason( action.recommendations ),
		patternError: null,
		patternRequestToken:
			action.requestToken ?? state.patternRequestToken,
		patternResultToken: state.patternResultToken + 1,
		patternRequestSignature:
			action.requestSignature || state.patternRequestSignature,
	};
```

Add selectors near the existing pattern selectors:

```js
getPatternRequestToken: ( state ) => state.patternRequestToken,
getPatternResultToken: ( state ) => state.patternResultToken,
getPatternRequestSignature: ( state ) => state.patternRequestSignature,
```

- [ ] **Step 5: Run the reducer test and confirm it passes**

Run:

```bash
npm run test:unit -- --runTestsByPath src/store/__tests__/pattern-status.test.js
```

Expected: PASS.

- [ ] **Step 6: Commit the reducer guard**

```bash
git add src/utils/recommendation-request-signature.js src/store/index.js src/store/__tests__/pattern-status.test.js
git commit -m "Add stale guards for pattern recommendations"
```

---

### Task 2: Thread Pattern Request Identity Through Async Fetch

**Files:**
- Modify: `src/store/index.js`
- Test: `src/store/__tests__/store-actions.test.js`

- [ ] **Step 1: Write the failing async action test**

Add `buildPatternRecommendationRequestSignature` to the import list in `src/store/__tests__/store-actions.test.js`:

```js
import {
	buildBlockRecommendationRequestSignature,
	buildGlobalStylesRecommendationRequestSignature,
	buildNavigationRecommendationRequestSignature,
	buildPatternRecommendationRequestSignature,
	buildStyleBookRecommendationRequestSignature,
	buildTemplatePartRecommendationRequestSignature,
	buildTemplateRecommendationRequestSignature,
} from '../../utils/recommendation-request-signature';
```

Add this test near the existing `fetchPatternRecommendations aborts the previous request and ignores abort errors` test:

```js
test( 'fetchPatternRecommendations tags loading and success with request identity', async () => {
	apiFetch.mockResolvedValue( {
		recommendations: [
			{
				name: 'theme/hero',
				reason: 'Matches this insertion point.',
				score: 0.97,
			},
		],
	} );

	const input = {
		postType: 'page',
		templateType: 'front-page',
		visiblePatternNames: [ 'theme/hero', 'theme/cards' ],
		insertionContext: {
			rootBlock: 'core/group',
			ancestors: [ 'core/group' ],
			nearbySiblings: [ 'core/paragraph' ],
		},
		prompt: 'hero',
		blockContext: {
			blockName: 'core/heading',
		},
	};
	const document = {
		scopeKey: 'page:42',
		postType: 'page',
		entityId: '42',
		entityKind: '',
		entityName: '',
		stylesheet: '',
	};
	const requestData = {
		...input,
		document,
	};
	const requestSignature =
		buildPatternRecommendationRequestSignature( requestData );
	const dispatch = jest.fn();
	const select = {
		getPatternRequestToken: jest.fn().mockReturnValue( 4 ),
	};

	await actions.fetchPatternRecommendations( input )( {
		dispatch,
		registry: {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/editor'
					? {
							getCurrentPostType: () => 'page',
							getCurrentPostId: () => 42,
					  }
					: {}
			),
		},
		select,
	} );

	expect( select.getPatternRequestToken ).toHaveBeenCalled();
	expect( apiFetch ).toHaveBeenCalledWith(
		expect.objectContaining( {
			path: '/flavor-agent/v1/recommend-patterns',
			method: 'POST',
			data: requestData,
		} )
	);
	expect( dispatch ).toHaveBeenNthCalledWith(
		1,
		actions.setPatternStatus( 'loading', null, 5, requestSignature )
	);
	expect( dispatch ).toHaveBeenNthCalledWith(
		2,
		actions.setPatternRecommendations(
			[
				{
					name: 'theme/hero',
					reason: 'Matches this insertion point.',
					score: 0.97,
				},
			],
			5,
			requestSignature
		)
	);
	expect( dispatch ).toHaveBeenNthCalledWith(
		3,
		actions.setPatternStatus( 'ready', null, 5, requestSignature )
	);
} );
```

- [ ] **Step 2: Run the async action test and confirm it fails**

Run:

```bash
npm run test:unit -- --runTestsByPath src/store/__tests__/store-actions.test.js --testNamePattern="fetchPatternRecommendations tags"
```

Expected: FAIL because the thunk does not calculate or dispatch pattern request identity yet.

- [ ] **Step 3: Thread token and signature through `fetchPatternRecommendations()`**

In `src/store/index.js`, replace the `buildRequest` block inside `fetchPatternRecommendations()` with:

```js
buildRequest: ( { input: requestInput, select: registrySelect } ) => {
	const requestData = {
		...( requestInput || {} ),
		document: getRequestDocumentFromScope(
			getCurrentActivityScope( registry )
		),
	};
	const requestToken =
		( registrySelect.getPatternRequestToken?.() || 0 ) + 1;
	const requestSignature =
		buildPatternRecommendationRequestSignature( requestData );

	return {
		requestData,
		requestToken,
		requestSignature,
	};
},
```

Update the `onError`, `onLoading`, and `onSuccess` callbacks to pass request metadata:

```js
onError: ( {
	dispatch: localDispatch,
	err,
	requestSignature,
	requestToken,
} ) => {
	localDispatch(
		actions.setPatternRecommendations(
			[],
			requestToken,
			requestSignature
		)
	);
	localDispatch(
		actions.setPatternStatus(
			'error',
			err?.message || 'Pattern recommendation request failed.',
			requestToken,
			requestSignature
		)
	);
	return reloadStoreActivitySession(
		localDispatch,
		registry,
		select
	);
},
onLoading: ( {
	dispatch: localDispatch,
	requestSignature,
	requestToken,
} ) => {
	localDispatch(
		actions.setPatternStatus(
			'loading',
			null,
			requestToken,
			requestSignature
		)
	);
},
onSuccess: ( {
	dispatch: localDispatch,
	requestSignature,
	requestToken,
	result,
} ) => {
	localDispatch(
		actions.setPatternRecommendations(
			result.recommendations || [],
			requestToken,
			requestSignature
		)
	);
	localDispatch(
		actions.setPatternStatus(
			'ready',
			null,
			requestToken,
			requestSignature
		)
	);
	return reloadStoreActivitySession(
		localDispatch,
		registry,
		select
	);
},
```

- [ ] **Step 4: Run the async action tests**

Run:

```bash
npm run test:unit -- --runTestsByPath src/store/__tests__/store-actions.test.js --testNamePattern="fetchPatternRecommendations"
```

Expected: PASS for the new request identity test and the existing abort-error test.

- [ ] **Step 5: Run the pattern store tests together**

Run:

```bash
npm run test:unit -- --runTestsByPath src/store/__tests__/pattern-status.test.js src/store/__tests__/store-actions.test.js --testNamePattern="pattern|fetchPatternRecommendations"
```

Expected: PASS.

- [ ] **Step 6: Commit the async fetch wiring**

```bash
git add src/store/index.js src/store/__tests__/store-actions.test.js
git commit -m "Thread pattern request identity through fetches"
```

---

### Task 3: Reattach Inserter Search Listeners After DOM Replacement

**Files:**
- Modify: `src/patterns/PatternRecommender.js`
- Test: `src/patterns/__tests__/PatternRecommender.test.js`

- [ ] **Step 1: Write the failing replacement test**

Add this test to `src/patterns/__tests__/PatternRecommender.test.js` after `keeps search-triggered fetches debounced and includes the selected block context`:

```js
test( 'reattaches the inserter search listener when Gutenberg replaces the input', () => {
	const firstSearchInput = {
		addEventListener: jest.fn(),
		removeEventListener: jest.fn(),
	};
	const secondSearchInput = {
		addEventListener: jest.fn(),
		removeEventListener: jest.fn(),
	};
	const observerCallbacks = [];
	let currentSearchInput = firstSearchInput;
	let secondInputListener = null;

	state.blockEditor.selectedBlockClientId = 'block-1';
	state.blockEditor.selectedBlockName = 'core/heading';
	mockFindInserterSearchInput.mockImplementation( () => currentSearchInput );
	secondSearchInput.addEventListener.mockImplementation(
		( event, listener ) => {
			if ( event === 'input' ) {
				secondInputListener = listener;
			}
		}
	);
	window.MutationObserver = class MockMutationObserver {
		constructor( callback ) {
			this.observe = jest.fn();
			this.disconnect = jest.fn();
			observerCallbacks.push( callback );
		}
	};

	renderComponent();

	expect( firstSearchInput.addEventListener ).toHaveBeenCalledWith(
		'input',
		expect.any( Function )
	);

	currentSearchInput = secondSearchInput;
	act( () => {
		observerCallbacks.forEach( ( callback ) => callback( [] ) );
	} );

	expect( firstSearchInput.removeEventListener ).toHaveBeenCalledWith(
		'input',
		firstSearchInput.addEventListener.mock.calls[ 0 ][ 1 ]
	);
	expect( secondSearchInput.addEventListener ).toHaveBeenCalledWith(
		'input',
		expect.any( Function )
	);

	act( () => {
		secondInputListener( {
			target: {
				value: 'gallery',
			},
		} );
		jest.advanceTimersByTime( 400 );
	} );

	expect( mockFetchPatternRecommendations ).toHaveBeenLastCalledWith( {
		postType: 'page',
		visiblePatternNames: [ 'theme/hero' ],
		insertionContext: {
			rootBlock: 'core/group',
			ancestors: [ 'core/group' ],
			nearbySiblings: [],
		},
		prompt: 'gallery',
		blockContext: {
			blockName: 'core/heading',
		},
	} );
} );
```

- [ ] **Step 2: Run the pattern component test and confirm it fails**

Run:

```bash
npm run test:unit -- --runTestsByPath src/patterns/__tests__/PatternRecommender.test.js --testNamePattern="reattaches the inserter search listener"
```

Expected: FAIL because the search observer disconnects after the first input is found and no observer exists for later replacement.

- [ ] **Step 3: Keep the search observer active while the inserter is open**

In `src/patterns/PatternRecommender.js`, remove the `OBSERVER_TIMEOUT_MS` constant if it becomes unused:

```js
const SEARCH_DEBOUNCE_MS = 400;
const INSERTER_SLOT_CLASS = 'flavor-agent-pattern-inserter-slot';
```

Replace the body of the search-input `useEffect()` with this implementation:

```js
useEffect( () => {
	const cleanupBindings = () => {
		clearSearchDebounce();

		if ( observerRef.current ) {
			observerRef.current.disconnect();
			observerRef.current = null;
		}

		if ( listenerRef.current ) {
			listenerRef.current.el.removeEventListener(
				'input',
				listenerRef.current.fn
			);
			listenerRef.current = null;
		}
	};

	if ( ! canRecommend || ! isInserterOpen ) {
		cleanupBindings();
		return undefined;
	}

	function attachToSearch( searchInput ) {
		if ( listenerRef.current?.el === searchInput ) {
			return;
		}

		if ( listenerRef.current ) {
			listenerRef.current.el.removeEventListener(
				'input',
				listenerRef.current.fn
			);
		}

		const fn = ( event ) => handleSearchInput( event.target.value );

		searchInput.addEventListener( 'input', fn );
		listenerRef.current = { el: searchInput, fn };
	}

	function syncSearchInput() {
		const input = findInserterSearchInput( document );

		if ( ! input ) {
			return;
		}

		attachToSearch( input );
	}

	syncSearchInput();

	if ( ! window.MutationObserver ) {
		return cleanupBindings;
	}

	const observer = new window.MutationObserver( () => {
		syncSearchInput();
	} );

	observer.observe( document.body, {
		childList: true,
		subtree: true,
	} );
	observerRef.current = observer;

	return () => {
		cleanupBindings();
	};
}, [
	canRecommend,
	clearSearchDebounce,
	isInserterOpen,
	handleSearchInput,
] );
```

- [ ] **Step 4: Update observer-count tests that assumed only the notice observer remained**

In `src/patterns/__tests__/PatternRecommender.test.js`, update `reattaches the inserter shelf when Gutenberg replaces the container` so it collects all observer callbacks:

```js
const observerCallbacks = [];
```

Use this mock:

```js
window.MutationObserver = class MockMutationObserver {
	constructor( callback ) {
		this.observe = jest.fn();
		this.disconnect = jest.fn();
		observerInstances.push( this );
		observerCallbacks.push( callback );
	}
};
```

Replace:

```js
expect( observerInstances ).toHaveLength( 1 );
```

with:

```js
expect( observerInstances ).toHaveLength( 2 );
```

Replace the single callback trigger with:

```js
act( () => {
	observerCallbacks.forEach( ( callback ) => callback( [] ) );
} );
```

Replace the final disconnect assertion with:

```js
observerInstances.forEach( ( observer ) => {
	expect( observer.disconnect ).toHaveBeenCalled();
} );
```

- [ ] **Step 5: Run the pattern component tests**

Run:

```bash
npm run test:unit -- --runTestsByPath src/patterns/__tests__/PatternRecommender.test.js
```

Expected: PASS.

- [ ] **Step 6: Commit the search reattachment fix**

```bash
git add src/patterns/PatternRecommender.js src/patterns/__tests__/PatternRecommender.test.js
git commit -m "Reattach pattern search listener after DOM replacement"
```

---

### Task 4: Match Active Block Styles By Class Token

**Files:**
- Modify: `src/context/block-inspector.js`
- Test: `src/context/__tests__/block-inspector.test.js`

- [ ] **Step 1: Write the failing active-style test**

Add `introspectBlockInstance` to the import in `src/context/__tests__/block-inspector.test.js`:

```js
const {
	introspectBlockInstance,
	introspectBlockType,
	resolveInspectorPanels,
} = require( '../block-inspector' );
```

Add this test inside the existing `describe( 'resolveInspectorPanels', ... )` block:

```js
test( 'matches active block styles by complete class token', () => {
	blocksSelectors.getBlockType.mockReturnValue( {
		title: 'Button',
		category: 'design',
		description: 'Button block',
		supports: {},
		attributes: {
			className: {
				type: 'string',
			},
		},
	} );
	blocksSelectors.getBlockStyles.mockReturnValue( [
		{
			name: 'outline',
			label: 'Outline',
		},
		{
			name: 'outline-large',
			label: 'Outline Large',
		},
	] );
	blockEditorSelectors.getBlockName = jest
		.fn()
		.mockReturnValue( 'core/button' );
	blockEditorSelectors.getBlockAttributes = jest.fn().mockReturnValue( {
		className:
			'wp-block-button has-text-align-center is-style-outline-large',
	} );
	blockEditorSelectors.getBlockEditingMode = jest
		.fn()
		.mockReturnValue( 'default' );
	blockEditorSelectors.getBlockParents = jest.fn().mockReturnValue( [] );
	blockEditorSelectors.getBlockCount = jest.fn().mockReturnValue( 0 );

	const manifest = introspectBlockInstance( 'button-1' );

	expect( manifest.activeStyle ).toBe( 'outline-large' );
} );
```

- [ ] **Step 2: Run the active-style test and confirm it fails**

Run:

```bash
npm run test:unit -- --runTestsByPath src/context/__tests__/block-inspector.test.js --testNamePattern="complete class token"
```

Expected: FAIL because `outline` is returned before `outline-large`.

- [ ] **Step 3: Tokenize class names before matching styles**

Replace `extractActiveStyle()` in `src/context/block-inspector.js` with:

```js
function extractActiveStyle( className, registeredStyles = [] ) {
	if ( ! className ) {
		return null;
	}

	const classTokens = new Set(
		String( className )
			.split( /\s+/ )
			.map( ( token ) => token.trim() )
			.filter( Boolean )
	);

	for ( const style of registeredStyles ) {
		const styleName =
			typeof style?.name === 'string' ? style.name.trim() : '';

		if ( styleName && classTokens.has( `is-style-${ styleName }` ) ) {
			return styleName;
		}
	}

	return null;
}
```

- [ ] **Step 4: Run the context tests**

Run:

```bash
npm run test:unit -- --runTestsByPath src/context/__tests__/block-inspector.test.js src/context/__tests__/collector.test.js
```

Expected: PASS.

- [ ] **Step 5: Commit the active-style fix**

```bash
git add src/context/block-inspector.js src/context/__tests__/block-inspector.test.js
git commit -m "Match block styles by complete class token"
```

---

### Task 5: Delegate Shadow Suggestions Into The Native Border/Shadow Group

**Files:**
- Modify: `src/inspector/panel-delegation.js`
- Modify: `src/inspector/InspectorInjector.js`
- Modify: `docs/features/block-recommendations.md`
- Modify: `docs/reference/recommendation-ui-consistency.md`
- Test: `src/inspector/__tests__/panel-delegation.test.js`
- Test: `src/inspector/__tests__/InspectorInjector.test.js`

- [ ] **Step 1: Write the failing delegation tests**

In `src/inspector/__tests__/panel-delegation.test.js`, insert this object after the `border` delegation in the expected `STYLE_PANEL_DELEGATIONS` array:

```js
{
	group: 'border',
	panel: 'shadow',
	label: 'AI shadow suggestions',
	title: 'Shadow',
},
```

Update the expected `DELEGATED_STYLE_PANELS` set:

```js
expect( DELEGATED_STYLE_PANELS ).toEqual(
	new Set( [
		'color',
		'typography',
		'dimensions',
		'border',
		'shadow',
		'filter',
		'background',
	] )
);
```

Update the delegated panel test:

```js
expect( isDelegatedStylePanel( 'shadow' ) ).toBe( true );
```

Remove `shadow` from the non-delegated assertion list so that only non-delegated panels remain:

```js
expect( isDelegatedStylePanel( 'general' ) ).toBe( false );
expect( isDelegatedStylePanel( 'effects' ) ).toBe( false );
```

In `src/inspector/__tests__/InspectorInjector.test.js`, add `shadow` to one existing style-projection scenario by changing the style recommendations to:

```js
styles: [
	{ label: 'Use accent color', panel: 'color' },
	{ label: 'Use soft shadow', panel: 'shadow' },
],
```

Add this expectation next to the existing color expectation:

```js
expect( getContainer().textContent ).toContain(
	'AI shadow suggestions passive'
);
```

- [ ] **Step 2: Run delegation tests and confirm they fail**

Run:

```bash
npm run test:unit -- --runTestsByPath src/inspector/__tests__/panel-delegation.test.js src/inspector/__tests__/InspectorInjector.test.js --testNamePattern="delegated|shadow|projected"
```

Expected: FAIL because `shadow` is not in `STYLE_PANEL_DELEGATIONS`.

- [ ] **Step 3: Add shadow delegation**

In `src/inspector/panel-delegation.js`, add this object after the `border` delegation:

```js
{
	group: 'border',
	panel: 'shadow',
	label: 'AI shadow suggestions',
	title: 'Shadow',
},
```

The `group` is `border` because current Gutenberg renders shadow controls through the Border/Shadow inspector group. The `panel` remains `shadow` so existing backend and execution-contract payloads with `panel: "shadow"` filter into the passive chip lane.

In `src/inspector/InspectorInjector.js`, change the delegated style key so the existing border mirror and the new shadow mirror can both render inside `group="border"` without duplicate React keys. Replace:

```js
key={ `styles-${ config.group }` }
```

with:

```js
key={ `styles-${ config.group }-${ config.panel }` }
```

- [ ] **Step 4: Update docs**

In `docs/features/block-recommendations.md`, replace the delegated sub-panel list with:

```md
  - passive mirrored `SuggestionChips` injected into delegated native sub-panels such as position, advanced, bindings, list, color, typography, dimensions, border, shadow, filter, and background so the user can see the current result beside the matching core controls without creating a second apply surface. Shadow suggestions render inside Gutenberg's native Border/Shadow group.
```

In `docs/reference/recommendation-ui-consistency.md`, add this bullet under `### Consistency Gaps` after the block settings/styles bullet:

```md
- Shadow block suggestions use `panel: "shadow"` in the recommendation contract and are mirrored inside Gutenberg's Border/Shadow inspector group, because Gutenberg exposes shadow controls through the border group rather than a standalone shadow group.
```

- [ ] **Step 5: Run inspector tests**

Run:

```bash
npm run test:unit -- --runTestsByPath src/inspector/__tests__/panel-delegation.test.js src/inspector/__tests__/InspectorInjector.test.js src/inspector/__tests__/SuggestionChips.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js
```

Expected: PASS.

- [ ] **Step 6: Run docs freshness check**

Run:

```bash
npm run check:docs
```

Expected: PASS.

- [ ] **Step 7: Commit shadow delegation**

```bash
git add src/inspector/panel-delegation.js src/inspector/InspectorInjector.js src/inspector/__tests__/panel-delegation.test.js src/inspector/__tests__/InspectorInjector.test.js docs/features/block-recommendations.md docs/reference/recommendation-ui-consistency.md
git commit -m "Mirror shadow recommendations in inspector"
```

---

### Task 6: Surface-Level Verification

**Files:**
- No source files.
- Test: focused JS suites, lint, docs, aggregate local verification, and one Playwright smoke when the browser harness is available.

- [ ] **Step 1: Run focused unit coverage for every changed surface**

Run:

```bash
npm run test:unit -- --runTestsByPath src/store/__tests__/pattern-status.test.js src/store/__tests__/store-actions.test.js src/patterns/__tests__/PatternRecommender.test.js src/context/__tests__/block-inspector.test.js src/context/__tests__/collector.test.js src/inspector/__tests__/panel-delegation.test.js src/inspector/__tests__/InspectorInjector.test.js src/inspector/__tests__/SuggestionChips.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js src/store/__tests__/block-request-state.test.js
```

Expected: PASS.

- [ ] **Step 2: Run JavaScript lint**

Run:

```bash
npm run lint:js
```

Expected: PASS.

- [ ] **Step 3: Run docs freshness check**

Run:

```bash
npm run check:docs
```

Expected: PASS.

- [ ] **Step 4: Run aggregate verification without browser suites**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: `VERIFY_RESULT={"status":"pass",...}`. If plugin-check prerequisites are missing, rerun:

```bash
npm run verify -- --skip-e2e --skip=lint-plugin
```

Expected: `VERIFY_RESULT={"status":"pass",...}` with plugin-check intentionally omitted.

- [ ] **Step 5: Run targeted Playwright smoke for pattern search**

Run:

```bash
npm run test:e2e:playground -- --grep "pattern surface smoke uses the inserter search"
```

Expected: PASS. This confirms the native inserter search still triggers pattern recommendations after the DOM-listener changes.

- [ ] **Step 6: Inspect the final diff**

Run:

```bash
git diff --check
git diff --stat HEAD
```

Expected: no whitespace errors; changed files limited to the files listed in this plan.

---

## Completion Criteria

- Stale pattern completions with older request tokens or same-token mismatched signatures do not change `patternRecommendations`, `patternStatus`, `patternError`, `patternBadge`, or pattern request metadata.
- Pattern fetches dispatch `loading`, `recommendations`, and `ready/error` actions with the same request token and request signature.
- Pattern active-search fetches still include `postType`, `visiblePatternNames`, `templateType` when present, `insertionContext`, `prompt`, `blockContext`, and activity `document`.
- Pattern search remains attached after Gutenberg replaces the inserter search input while the inserter remains open.
- Active block style context matches only a complete `is-style-*` class token.
- Shadow suggestions with `panel: "shadow"` render passively in the native Border/Shadow group.
- Block, pattern, context, and store unit tests pass.
- `npm run lint:js`, `npm run check:docs`, and `npm run verify -- --skip-e2e` pass or produce an explicitly documented plugin-check prerequisite omission.
- The targeted Playwright pattern search smoke passes when the local browser harness is available.

## Self-Review

- Spec coverage: all four review findings have implementation tasks and verification steps.
- Placeholder scan: no unresolved placeholder tokens are used in the implementation steps.
- Type consistency: pattern request metadata uses `requestToken` and `requestSignature` consistently across actions, reducers, selectors, tests, and the async thunk.
