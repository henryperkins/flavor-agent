# Recommendation Outcome Regression Remediation Implementation Plan

> Status: Archived 2026-06-05. Shipped with the recommendation outcome diagnostics remediation; retained only as historical execution context.

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the new recommendation outcome diagnostics accurate, retrievable, retry-safe, and test-pinned without changing Flavor Agent's existing apply/undo ownership boundaries.

**Architecture:** Fix the client-side outcome identity helpers first, because every later persistence and analytics path depends on stable recommendation set and suggestion keys. Then wire the REST opt-in filter through the scoped activity endpoint, move pattern "shown" recording to actual attached shelf visibility, and make outcome dedupe distinguish in-flight writes from persisted diagnostics so failed writes remain retryable without allowing duplicate concurrent POSTs. Keep diagnostics out of normal inline activity by default while preserving explicit admin/debug/evaluation access.

**Tech Stack:** WordPress plugin PHP, REST API controller/repository, WordPress data store thunks, Gutenberg React components, Jest via `@wordpress/scripts`, PHPUnit 9.6, repo docs verification.

---

## Review Findings Covered

| Finding | Root Issue | Current Proof | Remediation Task |
|---|---|---|---|
| `topSuggestionKeys` are corrupted | `Array.prototype.map()` passes `(value, index)`, but `cleanString` treats the second argument as `maxLength` | `npx wp-scripts test-unit-js src/store/__tests__/recommendation-outcomes.test.js --runInBand` currently fails: expected `['a','b','c']`, received `['b','c','d']` | Task 1 |
| Generated suggestion keys collide across lanes | `decorateRecommendationPayload()` restarts fallback keys for each list and omits the list name from the fallback identity | The same JS test currently fails because settings/styles/block suggestions all summarize as `block:1` | Task 1 |
| `includeDiagnostics=true` cannot retrieve scoped outcomes | `Agent_Controller::handle_get_activity()` registers the param but does not pass it into `$activity_filters`; `Repository::query()` excludes `recommendation_outcome` unless the flag is set | Code trace: `inc/REST/Agent_Controller.php:486-504` drops the flag; `inc/Activity/Repository.php:258-260` filters diagnostics out | Task 2 |
| Pattern `shown` outcomes overcount unseen shelves | The effect records `shown` when recommendations become `ready`, but the shelf is only visible when the inserter portal slot is attached to the live inserter DOM | Code trace: `src/patterns/PatternRecommender.js:903-911` records readiness; `src/patterns/PatternRecommender.js:478-482` creates a detached slot; `src/patterns/PatternRecommender.js:962-970` attaches it only when the inserter container exists; `src/patterns/PatternRecommender.js:1108-1134` gates shelf rendering | Task 3 |
| Outcome dedupe can suppress retry after POST failure or allow duplicate concurrent writes if moved naively | `recordRecommendationOutcome()` marks dedupe before awaiting persistence; diagnostic entries are not retained in local `activityLog`; a persisted-only fix would leave identical in-flight calls unguarded | Code trace: `src/store/index.js:1277-1283` marks before write; `src/store/activity-session.js:524-570` returns a local entry after failed persistence; `src/store/index.js:2972-2975` drops diagnostics from local reducer state | Task 4 |

## Files And Responsibilities

- Modify `src/store/recommendation-outcomes.js`: build lane-stable suggestion keys, preserve top suggestion strings without callback arity bugs, manage pending/persisted outcome dedupe keys, and keep privacy-safe bounded values.
- Modify `src/store/__tests__/recommendation-outcomes.test.js`: pin top suggestion order, dedupe, truncation, and lane-unique fallback identities.
- Modify `inc/REST/Agent_Controller.php`: pass `includeDiagnostics` into scoped activity repository filters for both grouped and non-grouped reads.
- Modify `tests/phpunit/AgentControllerTest.php`: prove scoped default reads hide outcome diagnostics and `includeDiagnostics=true` returns them.
- Modify `src/patterns/PatternRecommender.js`: record pattern `shown` only when the rendered shelf is actually attached to the inserter portal.
- Modify `src/patterns/__tests__/PatternRecommender.test.js`: prove hidden/closed inserter readiness and detached portal slots do not record `shown`, and visible shelf readiness does.
- Modify `src/store/index.js`: use pending dedupe while the POST is in flight, mark persisted dedupe only after server persistence succeeds, and leave failed attempts retryable.
- Modify `src/store/__tests__/store-actions.test.js`: prove failed diagnostic POST does not burn the dedupe key, a later attempt persists, and concurrent duplicate attempts do not issue duplicate POSTs.
- Modify docs only if behavior wording changes after implementation: `docs/features/activity-and-audit.md`, `docs/reference/activity-state-machine.md`, `docs/SOURCE_OF_TRUTH.md`.

## Success Criteria

1. `topSuggestionKeys` keep the first three unique input strings in order, collapse duplicates before truncation, and never lose the first key because of array-index truncation.
2. Fallback suggestion keys are stable and unique across `settings`, `styles`, `block`, and `suggestions` lanes.
3. Scoped `GET /flavor-agent/v1/activity` hides `recommendation_outcome` rows by default and returns them when `includeDiagnostics=true`.
4. Grouped scoped activity reads also honor `includeDiagnostics=true`.
5. Pattern `shown` outcomes are recorded only when the Flavor Agent pattern shelf is actually attached to the live inserter DOM.
6. A closed inserter and an open inserter with no discoverable inserter container both avoid `shown` recording.
7. A transient outcome diagnostic POST failure does not suppress a later retry for the same dedupe key.
8. Concurrent duplicate outcome attempts share one in-flight dedupe key and issue at most one diagnostic POST.
9. Targeted PHPUnit and Jest suites pass, `git diff --check` passes, docs freshness passes if docs are changed, and `node scripts/verify.js --skip-e2e` passes or records an unrelated blocker in `output/verify/summary.json`.

---

## Task 1: Fix Outcome Helper Identity And Top-Suggestion Normalization

**Files:**
- Modify: `src/store/recommendation-outcomes.js`
- Modify: `src/store/__tests__/recommendation-outcomes.test.js`

- [ ] **Step 1: Strengthen failing helper tests**

In `src/store/__tests__/recommendation-outcomes.test.js`, keep the existing privacy test and replace the final aggregate summary test with this stricter test:

```js
	test( 'summarizes decorated payloads with lane-unique fallback keys', () => {
		const payload = decorateRecommendationPayload(
			{
				settings: [ { label: 'Use preset spacing' } ],
				styles: [ { label: 'Improve contrast' } ],
				block: [ { label: 'Insert supported pattern' } ],
				suggestions: [ { label: 'Use concise copy' } ],
			},
			{
				surface: 'block',
				recommendationSetId: 'block:2:set',
				sourceRequestSignature: 'signature',
			}
		);

		expect( payload.settings[ 0 ].suggestionKey ).toBe( 'block:settings:1' );
		expect( payload.styles[ 0 ].suggestionKey ).toBe( 'block:styles:1' );
		expect( payload.block[ 0 ].suggestionKey ).toBe( 'block:block:1' );
		expect( payload.suggestions[ 0 ].suggestionKey ).toBe(
			'block:suggestions:1'
		);
		expect( getRecommendationOutcomeSummaryFromPayload( payload ) ).toEqual(
			expect.objectContaining( {
				recommendationSetId: 'block:2:set',
				sourceRequestSignature: expect.stringMatching( /^hash_/ ),
				resultCount: 4,
				topSuggestionKeys: [
					'block:settings:1',
					'block:styles:1',
					'block:block:1',
				],
			} )
		);
	} );
```

Keep the existing assertion in `builds a diagnostic shown entry without raw prompt or generated text`:

```js
expect( entry.after.outcome.topSuggestionKeys ).toEqual( [ 'a', 'b', 'c' ] );
```

Add this test after it so the implementation must dedupe before applying the cap, not merely fix the `Array.prototype.map()` callback arity:

```js
	test( 'dedupes top suggestion keys before truncating', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
				postType: 'post',
				entityId: '42',
			},
			event: 'shown',
			surface: 'block',
			recommendationSetId: 'block:1:set',
			topSuggestionKeys: [
				'alpha',
				'alpha',
				'bravo',
				'charlie',
				'delta',
			],
		} );

		expect( entry.after.outcome.topSuggestionKeys ).toEqual( [
			'alpha',
			'bravo',
			'charlie',
		] );
	} );
```

- [ ] **Step 2: Run tests and verify the current failure**

Run:

```bash
npx wp-scripts test-unit-js src/store/__tests__/recommendation-outcomes.test.js --runInBand
```

Expected before implementation: FAIL. The top-suggestion test should still show the current `['b','c','d']` truncation, the new dedupe test should fail until duplicate keys are collapsed before truncation, and the aggregate summary should show colliding fallback keys.

- [ ] **Step 3: Fix lane-aware decoration and shared top-suggestion normalization**

In `src/store/recommendation-outcomes.js`, add this helper near `getSuggestionOutcomeKey()` so every top-suggestion path has the same map-arity-safe, order-preserving dedupe behavior:

```js
function normalizeTopSuggestionKeys( keys = [] ) {
	if ( ! Array.isArray( keys ) ) {
		return [];
	}

	return Array.from(
		new Set(
			keys.map( ( key ) => cleanString( key ) ).filter( Boolean )
		)
	).slice( 0, TOP_SUGGESTION_CAP );
}
```

Then replace the current `decorateList` helper and reducer body in `decorateRecommendationPayload()` with this implementation:

```js
	const decorateList = ( list = [], listKey = 'suggestions' ) =>
		Array.isArray( list )
			? list.map( ( suggestion, index ) => {
					if ( ! suggestion || typeof suggestion !== 'object' ) {
						return suggestion;
					}

					const fallbackKey = `${ surface || 'suggestion' }:${ listKey }:${
						index + 1
					}`;
					const suggestionKey = getSuggestionOutcomeKey(
						suggestion,
						fallbackKey
					);

					return {
						...suggestion,
						suggestionKey:
							cleanString( suggestion.suggestionKey ) ||
							suggestionKey,
						recommendationOutcome: {
							recommendationSetId: setId,
							suggestionKey,
							sourceRequestSignature: sourceSignature,
							rank: index + 1,
							resultCount:
								Number.isInteger( resultCount ) &&
								resultCount >= 0
									? resultCount
									: list.length,
							topSuggestionKeys: normalizeTopSuggestionKeys(
								list.map( ( item, itemIndex ) =>
									getSuggestionOutcomeKey(
										item,
										`${ surface || 'suggestion' }:${ listKey }:${
											itemIndex + 1
										}`
									)
								)
							),
						},
					};
			  } )
			: [];

	return keys.reduce(
		( nextPayload, key ) =>
			Array.isArray( nextPayload[ key ] )
				? {
						...nextPayload,
						[ key ]: decorateList( nextPayload[ key ], key ),
				  }
				: nextPayload,
		{
			...payload,
			recommendationOutcome: {
				recommendationSetId: setId,
				sourceRequestSignature: sourceSignature,
			},
		}
	);
```

- [ ] **Step 4: Use shared top-suggestion normalization in summary and entry builders**

In `getRecommendationOutcomeSummaryFromPayload()`, replace:

```js
topSuggestionKeys: suggestions
	.slice( 0, TOP_SUGGESTION_CAP )
	.map( ( suggestion, index ) =>
		getSuggestionOutcomeKey( suggestion, `suggestion:${ index + 1 }` )
	)
	.filter( Boolean ),
```

with:

```js
topSuggestionKeys: normalizeTopSuggestionKeys(
	suggestions.map( ( suggestion, index ) =>
		getSuggestionOutcomeKey( suggestion, `suggestion:${ index + 1 }` )
	)
),
```

Then, in `buildRecommendationOutcomeEntry()`, replace:

```js
topSuggestionKeys: ( identity.topSuggestionKeys || [] )
	.map( cleanString )
	.filter( Boolean )
	.slice( 0, TOP_SUGGESTION_CAP ),
```

with:

```js
topSuggestionKeys: normalizeTopSuggestionKeys( identity.topSuggestionKeys ),
```

- [ ] **Step 5: Run the helper tests**

Run:

```bash
npx wp-scripts test-unit-js src/store/__tests__/recommendation-outcomes.test.js --runInBand
```

Expected: PASS. The suite should report all tests in `recommendation-outcomes.test.js` passing.

- [ ] **Step 6: Commit Task 1**

```bash
git add src/store/recommendation-outcomes.js src/store/__tests__/recommendation-outcomes.test.js
git commit -m "Fix recommendation outcome identity helpers"
```

---

## Task 2: Wire Scoped Activity Diagnostics Opt-In Through REST

**Files:**
- Modify: `inc/REST/Agent_Controller.php`
- Modify: `tests/phpunit/AgentControllerTest.php`

- [ ] **Step 1: Add REST regression coverage**

In `tests/phpunit/AgentControllerTest.php`, add this test after `test_handle_get_activity_filters_by_scope_key()`:

```php
	public function test_handle_get_activity_includes_scoped_outcome_diagnostics_only_when_requested(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		ActivityRepository::create( $this->build_activity_entry( 'activity-1' ) );
		ActivityRepository::create(
			[
				'id'         => 'outcome-1',
				'type'       => 'recommendation_outcome',
				'surface'    => 'pattern',
				'target'     => [
					'recommendationSetId' => 'set-1',
					'patternKey'          => 'theme/hero',
				],
				'suggestion' => 'Recommendations shown',
				'after'      => [
					'outcome' => [
						'event'               => 'shown',
						'recommendationSetId' => 'set-1',
						'visibility'          => 'diagnostic',
					],
				],
				'document'   => [
					'scopeKey' => 'wp_template:theme//home',
					'postType' => 'wp_template',
					'entityId' => 'theme//home',
				],
				'timestamp'  => '2026-03-24T10:00:01Z',
			]
		);

		$default_request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$default_request->set_param( 'scopeKey', 'wp_template:theme//home' );

		$default_response = Agent_Controller::handle_get_activity( $default_request );
		$default_entries  = $default_response instanceof \WP_REST_Response
			? ( $default_response->get_data()['entries'] ?? [] )
			: [];

		$this->assertContains( 'activity-1', array_column( $default_entries, 'id' ) );
		$this->assertNotContains( 'outcome-1', array_column( $default_entries, 'id' ) );

		$diagnostic_request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$diagnostic_request->set_param( 'scopeKey', 'wp_template:theme//home' );
		$diagnostic_request->set_param( 'includeDiagnostics', true );

		$diagnostic_response = Agent_Controller::handle_get_activity( $diagnostic_request );
		$diagnostic_entries  = $diagnostic_response instanceof \WP_REST_Response
			? ( $diagnostic_response->get_data()['entries'] ?? [] )
			: [];

		$this->assertContains( 'activity-1', array_column( $diagnostic_entries, 'id' ) );
		$this->assertContains( 'outcome-1', array_column( $diagnostic_entries, 'id' ) );
	}
```

Add this grouped-path companion after `test_handle_get_activity_grouped_by_surface_keeps_executable_history_when_diagnostics_are_newer()`:

```php
	public function test_handle_get_activity_grouped_by_surface_honors_include_diagnostics(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		ActivityRepository::create( $this->build_activity_entry( 'activity-template' ) );
		ActivityRepository::create(
			[
				'id'         => 'outcome-pattern',
				'type'       => 'recommendation_outcome',
				'surface'    => 'pattern',
				'target'     => [
					'recommendationSetId' => 'set-pattern',
					'patternKey'          => 'theme/hero',
				],
				'suggestion' => 'Recommendations shown',
				'after'      => [
					'outcome' => [
						'event'               => 'shown',
						'recommendationSetId' => 'set-pattern',
						'visibility'          => 'diagnostic',
					],
				],
				'document'   => [
					'scopeKey' => 'wp_template:theme//home',
					'postType' => 'wp_template',
					'entityId' => 'theme//home',
				],
				'timestamp'  => '2026-03-24T10:00:01Z',
			]
		);

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'wp_template:theme//home' );
		$request->set_param( 'groupBySurface', true );
		$request->set_param( 'surfaceLimit', 20 );
		$request->set_param( 'includeDiagnostics', true );

		$response = Agent_Controller::handle_get_activity( $request );
		$entries  = $response instanceof \WP_REST_Response
			? ( $response->get_data()['entries'] ?? [] )
			: [];

		$this->assertContains( 'activity-template', array_column( $entries, 'id' ) );
		$this->assertContains( 'outcome-pattern', array_column( $entries, 'id' ) );
	}
```

- [ ] **Step 2: Run tests and verify the current failure**

Run:

```bash
vendor/bin/phpunit tests/phpunit/AgentControllerTest.php --filter 'outcome_diagnostics|honors_include_diagnostics'
```

Expected before implementation: FAIL because `outcome-1` and `outcome-pattern` are not returned. The filter must run both `test_handle_get_activity_includes_scoped_outcome_diagnostics_only_when_requested` and `test_handle_get_activity_grouped_by_surface_honors_include_diagnostics`; if PHPUnit reports only one test, run the full `vendor/bin/phpunit tests/phpunit/AgentControllerTest.php` file before changing code.

- [ ] **Step 3: Pass `includeDiagnostics` through scoped filters**

In `inc/REST/Agent_Controller.php`, change the scoped `$activity_filters` block to include the new parameter:

```php
		$activity_filters = [
			'scopeKey'           => $request->get_param( 'scopeKey' ),
			'surface'            => $request->get_param( 'surface' ),
			'entityType'         => $request->get_param( 'entityType' ),
			'entityRef'          => $request->get_param( 'entityRef' ),
			'userId'             => $request->get_param( 'userId' ),
			'limit'              => $request->get_param( 'limit' ),
			'includeDiagnostics' => true === $request->get_param( 'includeDiagnostics' ),
		];
```

No repository changes are needed for this task because `Repository::query()` already reads `includeDiagnostics`, and `query_grouped_by_surface()` passes filter arrays through to `query()`.

- [ ] **Step 4: Run the REST tests**

Run:

```bash
vendor/bin/phpunit tests/phpunit/AgentControllerTest.php --filter 'outcome_diagnostics|honors_include_diagnostics'
```

Expected: PASS.

- [ ] **Step 5: Run nearby activity tests**

Run:

```bash
vendor/bin/phpunit tests/phpunit/AgentControllerTest.php tests/phpunit/ActivitySerializerTest.php tests/phpunit/RecommendationOutcomeTest.php tests/phpunit/RecommendationOutcomeEvaluationTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit Task 2**

```bash
git add inc/REST/Agent_Controller.php tests/phpunit/AgentControllerTest.php
git commit -m "Wire scoped activity diagnostic opt-in"
```

---

## Task 3: Record Pattern `shown` Outcomes Only For Attached Shelves

**Files:**
- Modify: `src/patterns/PatternRecommender.js`
- Modify: `src/patterns/__tests__/PatternRecommender.test.js`

- [ ] **Step 1: Add closed-inserter hidden-shelf regression test**

In `src/patterns/__tests__/PatternRecommender.test.js`, add this test near the existing shelf rendering tests:

```js
	test( 'does not record pattern shown outcomes when the inserter shelf is not visible', () => {
		state.isInserterOpen = false;
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				reason: 'Matches this insertion point.',
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/group' } ],
			},
		];
		mockFindInserterContainer.mockReturnValue( null );

		renderComponent();

		expect( mockRecordRecommendationOutcome ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'shown',
				surface: 'pattern',
			} )
		);
	} );
```

- [ ] **Step 2: Add detached-portal regression test**

In the same test file, add:

```js
	test( 'does not record pattern shown outcomes when the inserter slot is detached', () => {
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				reason: 'Matches this insertion point.',
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/group' } ],
			},
		];
		mockFindInserterContainer.mockReturnValue( null );

		renderComponent();

		expect( document.body.textContent ).not.toContain( 'Hero' );
		expect( mockRecordRecommendationOutcome ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'shown',
				surface: 'pattern',
			} )
		);
	} );
```

- [ ] **Step 3: Add visible-shelf proof test**

In the same test file, add:

```js
	test( 'records pattern shown outcomes when the shelf renders in the inserter', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				reason: 'Matches this insertion point.',
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/group' } ],
			},
		];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain( 'Hero' );
		expect( mockRecordRecommendationOutcome ).toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'shown',
				surface: 'pattern',
				reason: 'recommendation_set_visible',
				resultCount: 1,
				patternKey: expect.any( String ),
			} )
		);
	} );
```

- [ ] **Step 4: Run tests and verify the current failure**

Run:

```bash
npx wp-scripts test-unit-js src/patterns/__tests__/PatternRecommender.test.js --runInBand
```

Expected before implementation: FAIL for `does not record pattern shown outcomes when the inserter shelf is not visible` and `does not record pattern shown outcomes when the inserter slot is detached`, because the current effect records on `ready` even when the shelf is not attached to the live inserter DOM.

- [ ] **Step 5: Replace readiness-only shown recording with attached-shelf recording**

In `src/patterns/PatternRecommender.js`, update the WordPress element import to include `useState`:

```js
import {
	createPortal,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
```

After the existing refs, add state that represents whether the portal slot is attached to the live inserter container:

```js
	const [ isPatternShelfAttached, setIsPatternShelfAttached ] =
		useState( false );
```

Remove the current effect that starts with:

```js
	useEffect( () => {
		if ( patternStatus !== 'ready' || recommendedPatterns.length === 0 ) {
			return;
		}

		recordPatternOutcome( 'shown', {
			reason: 'recommendation_set_visible',
		} );
	}, [ patternStatus, recommendedPatterns.length, recordPatternOutcome ] );
```

Inside the existing notice-slot attachment effect, update `cleanupNotice()`, `attachNotice()`, and `syncNotice()` so detached slots cannot count as visible:

```js
		const cleanupNotice = () => {
			if ( noticeObserverRef.current ) {
				noticeObserverRef.current.disconnect();
				noticeObserverRef.current = null;
			}

			if ( noticeSlot?.parentNode ) {
				noticeSlot.parentNode.removeChild( noticeSlot );
			}

			setIsPatternShelfAttached( false );
		};

		const attachNotice = ( inserterContainer ) => {
			if ( ! noticeSlot || ! inserterContainer ) {
				setIsPatternShelfAttached( false );
				return;
			}

			if ( noticeSlot.parentNode === inserterContainer ) {
				setIsPatternShelfAttached( true );
				return;
			}

			if ( noticeSlot.parentNode ) {
				noticeSlot.parentNode.removeChild( noticeSlot );
			}

			inserterContainer.insertBefore(
				noticeSlot,
				inserterContainer.firstChild
			);
			setIsPatternShelfAttached( true );
		};

		const syncNotice = () => {
			const inserterContainer = findInserterContainer( document );

			if ( ! inserterContainer ) {
				setIsPatternShelfAttached( false );
				return;
			}

			attachNotice( inserterContainer );
		};
```

Create a stable render gate before the portal render block:

```js
		const shouldShowPatternShelf =
			shouldRenderInserterAffordance &&
			canRecommend &&
			! connectorApprovalNotice &&
			patternStatus === 'ready' &&
			recommendedPatterns.length > 0;
```

Add this effect after `shouldShowPatternShelf` is defined. It must depend on the attached-slot state, not just `noticeSlotRef.current`, because `noticeSlotRef.current` is created before it is inserted into the document:

```js
	useEffect( () => {
		if ( ! shouldShowPatternShelf || ! isPatternShelfAttached ) {
			return;
		}

		recordPatternOutcome( 'shown', {
			reason: 'recommendation_set_visible',
		} );
	}, [
		isPatternShelfAttached,
		shouldShowPatternShelf,
		recordPatternOutcome,
	] );
```

Then replace the existing render branch condition:

```js
			} else if (
				patternStatus === 'ready' &&
				recommendedPatterns.length > 0
			) {
```

with:

```js
			} else if ( shouldShowPatternShelf ) {
```

- [ ] **Step 6: Run the pattern component tests**

Run:

```bash
npx wp-scripts test-unit-js src/patterns/__tests__/PatternRecommender.test.js --runInBand
```

Expected: PASS.

- [ ] **Step 7: Commit Task 3**

```bash
git add src/patterns/PatternRecommender.js src/patterns/__tests__/PatternRecommender.test.js
git commit -m "Record pattern shown outcomes on attached shelf render"
```

---

## Task 4: Make Outcome Dedupe Persistence-Acknowledged

**Files:**
- Modify: `src/store/recommendation-outcomes.js`
- Modify: `src/store/index.js`
- Modify: `src/store/__tests__/store-actions.test.js`

- [ ] **Step 1: Add failed-POST retry and in-flight dedupe regression tests**

In `src/store/__tests__/store-actions.test.js`, add `resetRecommendationOutcomeDedupeForTests` to the imports:

```js
import { resetRecommendationOutcomeDedupeForTests } from '../recommendation-outcomes';
```

In the existing `beforeEach()`, call the reset helper immediately after `jest.clearAllMocks()`:

```js
		resetRecommendationOutcomeDedupeForTests();
```

Then add these helpers and tests near the activity persistence tests:

```js
	const createRecommendationOutcomeThunkContext = () => ( {
		dispatch: jest.fn( ( action ) => action ),
		select: {
			getActivityScopeKey: jest.fn().mockReturnValue( 'post:42' ),
			getActivityLog: jest.fn().mockReturnValue( [] ),
		},
		registry: {
			select: jest.fn( ( storeName ) =>
				storeName === 'core/editor'
					? {
							getCurrentPostType: () => 'post',
							getCurrentPostId: () => 42,
					  }
					: {}
			),
		},
	} );

	const createShownOutcomePayload = () => ( {
		event: 'shown',
		surface: 'block',
		recommendationSetId: 'set-1',
		suggestionKey: 'suggestion-1',
		topSuggestionKeys: [ 'suggestion-1' ],
		resultCount: 1,
	} );

	const createServerOutcomeEntry = ( id = 'outcome-server-1' ) => ( {
		id,
		type: 'recommendation_outcome',
		surface: 'block',
		diagnostic: true,
		executionResult: 'diagnostic',
		target: {
			recommendationSetId: 'set-1',
			suggestionKey: 'suggestion-1',
		},
		after: {
			outcome: {
				event: 'shown',
				visibility: 'diagnostic',
				recommendationSetId: 'set-1',
			},
		},
		request: {
			recommendation: {
				recommendationSetId: 'set-1',
				suggestionKey: 'suggestion-1',
			},
		},
		document: {
			scopeKey: 'post:42',
			postType: 'post',
			entityId: '42',
		},
		undo: {
			canUndo: false,
			status: 'not_applicable',
			error: null,
			updatedAt: '2026-03-24T10:00:00+00:00',
			undoneAt: null,
		},
		persistence: {
			status: 'server',
		},
	} );

	test( 'recordRecommendationOutcome retries after a failed diagnostic persistence attempt', async () => {
		apiFetch
			.mockRejectedValueOnce( new Error( 'temporary activity write failure' ) )
			.mockResolvedValueOnce( {
				entry: createServerOutcomeEntry(),
			} );

		const context = createRecommendationOutcomeThunkContext();
		const outcome = createShownOutcomePayload();

		const failedResult = await actions.recordRecommendationOutcome( outcome )(
			context
		);
		const retryResult = await actions.recordRecommendationOutcome( outcome )(
			context
		);

		expect( failedResult ).toEqual(
			expect.objectContaining( {
				type: 'recommendation_outcome',
				diagnostic: true,
			} )
		);
		expect( retryResult ).toEqual(
			expect.objectContaining( {
				id: 'outcome-server-1',
				persistence: expect.objectContaining( {
					status: 'server',
				} ),
			} )
		);
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( apiFetch ).toHaveBeenNthCalledWith(
			1,
			expect.objectContaining( {
				path: '/flavor-agent/v1/activity',
				method: 'POST',
			} )
		);
		expect( apiFetch ).toHaveBeenNthCalledWith(
			2,
			expect.objectContaining( {
				path: '/flavor-agent/v1/activity',
				method: 'POST',
			} )
		);
	} );

	test( 'recordRecommendationOutcome suppresses duplicate in-flight diagnostic persistence attempts', async () => {
		let resolvePersistedOutcome;
		apiFetch.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					resolvePersistedOutcome = resolve;
				} )
		);

		const context = createRecommendationOutcomeThunkContext();
		const outcome = createShownOutcomePayload();
		const firstAttempt =
			actions.recordRecommendationOutcome( outcome )( context );
		const secondAttempt =
			actions.recordRecommendationOutcome( outcome )( context );

		await expect( secondAttempt ).resolves.toBeNull();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		resolvePersistedOutcome( {
			entry: createServerOutcomeEntry(),
		} );

		await expect( firstAttempt ).resolves.toEqual(
			expect.objectContaining( {
				id: 'outcome-server-1',
				persistence: expect.objectContaining( {
					status: 'server',
				} ),
			} )
		);

		const persistedDuplicate =
			await actions.recordRecommendationOutcome( outcome )( context );

		expect( persistedDuplicate ).toBeNull();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );
```

- [ ] **Step 2: Run tests and verify the current failure**

Run:

```bash
npx wp-scripts test-unit-js src/store/__tests__/store-actions.test.js --runInBand --testNamePattern="recordRecommendationOutcome"
```

Expected before implementation: FAIL. The retry test should fail because the second sequential call returns `null` and the second POST is skipped after dedupe was marked by the failed attempt. The in-flight test should also expose that a persisted-only dedupe change would be insufficient because duplicate calls made before the first POST resolves need a pending guard.

- [ ] **Step 3: Add pending outcome dedupe helpers**

In `src/store/recommendation-outcomes.js`, add a pending set next to the existing recorded set:

```js
const recordedOutcomeKeys = new Set();
const pendingOutcomeKeys = new Set();
```

Add these helpers next to the existing dedupe helper exports:

```js
export function hasPendingRecommendationOutcome( dedupeKey ) {
	return pendingOutcomeKeys.has( cleanString( dedupeKey ) );
}

export function markRecommendationOutcomePending( dedupeKey ) {
	const normalized = cleanString( dedupeKey );

	if ( normalized ) {
		pendingOutcomeKeys.add( normalized );
	}
}

export function clearRecommendationOutcomePending( dedupeKey ) {
	const normalized = cleanString( dedupeKey );

	if ( normalized ) {
		pendingOutcomeKeys.delete( normalized );
	}
}
```

Update `resetRecommendationOutcomeDedupeForTests()` so tests start with both dedupe sets empty:

```js
export function resetRecommendationOutcomeDedupeForTests() {
	recordedOutcomeKeys.clear();
	pendingOutcomeKeys.clear();
}
```

- [ ] **Step 4: Use pending dedupe around persistence and persisted dedupe after server acknowledgement**

In `src/store/index.js`, update the recommendation outcome imports:

```js
import {
	buildRecommendationOutcomeDedupeKey,
	buildRecommendationOutcomeEntry,
	buildRecommendationSetId,
	clearRecommendationOutcomePending,
	decorateRecommendationPayload,
	getRecommendationOutcomeSummaryFromPayload,
	hasPendingRecommendationOutcome,
	hasRecordedRecommendationOutcome,
	markRecommendationOutcomePending,
	markRecommendationOutcomeRecorded,
} from './recommendation-outcomes';
```

Then replace the current dedupe/persistence block:

In `src/store/index.js`, replace:

```js
			markRecommendationOutcomeRecorded( dedupeKey );

			return logStoreActivityEntry( localDispatch, select, entry );
```

with:

```js
			if ( hasPendingRecommendationOutcome( dedupeKey ) ) {
				return null;
			}

			markRecommendationOutcomePending( dedupeKey );

			try {
				const persistedEntry = await logStoreActivityEntry(
					localDispatch,
					select,
					entry
				);

				if ( persistedEntry?.persistence?.status === 'server' ) {
					markRecommendationOutcomeRecorded( dedupeKey );
				}

				return persistedEntry;
			} finally {
				clearRecommendationOutcomePending( dedupeKey );
			}
```

Preserve the existing preceding recorded check:

```js
			if ( hasRecordedRecommendationOutcome( dedupeKey ) ) {
				return null;
			}
```

The final flow should be: return `null` for persisted duplicates, return `null` for in-flight duplicates, mark pending before the POST, mark recorded only when `persistedEntry.persistence.status === 'server'`, and always clear pending in `finally`. This keeps the existing behavior that diagnostic entries do not enter local inline history, while allowing a later retry if the server write failed.

- [ ] **Step 5: Run the dedupe retry and in-flight tests**

Run:

```bash
npx wp-scripts test-unit-js src/store/__tests__/store-actions.test.js --runInBand --testNamePattern="recordRecommendationOutcome"
```

Expected: PASS.

- [ ] **Step 6: Run broader store tests that cover activity persistence**

Run:

```bash
npx wp-scripts test-unit-js src/store/__tests__/store-actions.test.js src/store/__tests__/recommendation-outcomes.test.js --runInBand
```

Expected: PASS.

- [ ] **Step 7: Commit Task 4**

```bash
git add src/store/recommendation-outcomes.js src/store/index.js src/store/__tests__/store-actions.test.js
git commit -m "Retry recommendation outcome diagnostics after failed persistence"
```

---

## Task 5: Documentation And Cross-Surface Verification

**Files:**
- Modify only if implementation changes wording: `docs/features/activity-and-audit.md`
- Modify only if implementation changes wording: `docs/reference/activity-state-machine.md`
- Modify only if implementation changes wording: `docs/SOURCE_OF_TRUTH.md`

- [ ] **Step 1: Check docs wording after code is fixed**

Run:

```bash
rg -n "recommendation_outcome|includeDiagnostics|shown|not_applicable|pattern insertions" docs/SOURCE_OF_TRUTH.md docs/features/activity-and-audit.md docs/reference/activity-state-machine.md
```

Expected: Docs still say diagnostics are hidden by default, available to opt-in audit/debug/evaluation callers, and pattern insertions from the shelf are diagnostic only. If the text claims all ready pattern recommendation sets count as shown, change it to attached visible shelf wording.

- [ ] **Step 2: Run targeted PHP verification**

Run:

```bash
vendor/bin/phpunit tests/phpunit/AgentControllerTest.php tests/phpunit/ActivitySerializerTest.php tests/phpunit/RecommendationOutcomeTest.php tests/phpunit/RecommendationOutcomeEvaluationTest.php
```

Expected: PASS.

- [ ] **Step 3: Run targeted JS verification**

Run:

```bash
npx wp-scripts test-unit-js src/store/__tests__/recommendation-outcomes.test.js src/store/__tests__/store-actions.test.js src/patterns/__tests__/PatternRecommender.test.js --runInBand
```

Expected: PASS.

- [ ] **Step 4: Run JS lint for touched source**

Run:

```bash
npm run lint:js -- --quiet
```

Expected: PASS or only pre-existing unrelated lint failures. If unrelated failures exist, record the exact files and messages before proceeding.

- [ ] **Step 5: Run docs checks if docs changed**

If any `docs/` file changed during execution, run:

```bash
npm run check:docs
```

Expected: PASS.

- [ ] **Step 6: Run whitespace and aggregate non-E2E verification**

Run:

```bash
git diff --check
node scripts/verify.js --skip-e2e
```

Expected: `git diff --check` PASS. `node scripts/verify.js --skip-e2e` prints a final `VERIFY_RESULT={...}` line with `"status":"pass"`, or records a real unrelated blocker in `output/verify/summary.json`.

- [ ] **Step 7: Run browser release evidence when the local stack is representative**

Because this change touches multiple recommendation surfaces and the pattern inserter UI, run the matching browser harnesses before release:

```bash
npm run test:e2e:playground
npm run test:e2e:wp70
```

Expected: PASS. If a harness is known-red or unavailable on this host, record the command, failure, and waiver/blocker in the implementation summary rather than silently skipping it.

- [ ] **Step 8: Commit Task 5**

If docs changed:

```bash
git add docs/SOURCE_OF_TRUTH.md docs/features/activity-and-audit.md docs/reference/activity-state-machine.md
git commit -m "Document recommendation outcome diagnostic behavior"
```

If no docs changed, do not create an empty commit.

---

## Final Acceptance Checklist

- [ ] `recommendation-outcomes.test.js` passes and proves top suggestion order, dedupe-before-truncation, and lane-unique fallback keys.
- [ ] `AgentControllerTest.php` passes and proves scoped diagnostics opt-in for non-grouped and grouped reads.
- [ ] `PatternRecommender.test.js` passes and proves pattern `shown` requires an attached visible shelf, not only a ready status or detached portal node.
- [ ] `store-actions.test.js` passes and proves failed diagnostic persistence attempts remain retryable and concurrent duplicates do not create duplicate POSTs.
- [ ] `git diff --check` passes.
- [ ] `node scripts/verify.js --skip-e2e` passes or records an unrelated blocker.
- [ ] Browser E2E release evidence is run or explicitly waived with a concrete blocker.

## Self-Review Notes

- Spec coverage: All five original review findings plus the three plan-review corrections are mapped to a task with a failing proof and a concrete fix.
- Placeholder scan: This plan contains no forbidden placeholder tokens, no deferred edge-case language, and no implementation-shortcut references to other tasks.
- Type consistency: The plan uses existing names from the current diff: `recommendation_outcome`, `includeDiagnostics`, `recommendationSetId`, `suggestionKey`, `not_applicable`, `recordRecommendationOutcome`, and `PatternRecommender`.
