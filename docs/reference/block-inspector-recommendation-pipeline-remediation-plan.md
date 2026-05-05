# Block Inspector Recommendation Pipeline Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make block recommendation request diagnostics durable across editor reloads and make scoped activity hydration return the latest bounded entries for every surface, even when one recommendation surface is noisy.

**Architecture:** The block inspector request path should carry a durable block locator (`blockPath`) from client context collection through Abilities request diagnostics and back into inline activity matching/admin projection. Activity hydration should use exact per-surface windows instead of one combined recent window that can be monopolized by a single surface.

**Tech Stack:** WordPress plugin PHP, WordPress Abilities API, `@wordpress/data`, Jest via `wp-scripts`, PHPUnit, plugin-owned activity table.

---

## Findings Addressed

1. Persisted block `request_diagnostic` entries only carry ephemeral `clientId`, so they can disappear from the inline block inspector after editor reloads because client IDs are regenerated.
2. `ActivityRepository::query_grouped_by_surface()` first fetches one combined recent window and then buckets it, so more than `surfaceLimit * MAX_KNOWN_SURFACES` newer diagnostics on one surface can exclude older executable history from other surfaces before bucketing.

## File Structure

- Modify `src/context/collector.js`
  - Add a durable block path helper using the block-editor store.
  - Include `block.blockPath` in the collected block recommendation context.
- Modify `src/context/__tests__/collector.test.js`
  - Add coverage that nested selected blocks receive a deterministic `blockPath`.
- Modify `src/store/index.js`
  - Preserve `blockPath` in fallback failure diagnostics and empty-result diagnostics.
- Modify `src/store/__tests__/store-actions.test.js`
  - Assert block recommendation diagnostics carry `blockPath`.
- Modify `src/inspector/BlockRecommendationsPanel.js`
  - Include diagnostic `blockPath` in locally synthesized inline activity rows.
- Modify `src/inspector/__tests__/BlockRecommendationsPanel.test.js`
  - Assert diagnostic activity rows expose `target.blockPath`.
- Modify `inc/Abilities/RecommendationAbilityExecution.php`
  - Persist block diagnostic targets with sanitized `blockPath` when the client sends one.
- Modify `tests/phpunit/RecommendationAbilityExecutionTest.php`
  - Assert persisted block diagnostics include sanitized `target.blockPath`.
- Modify `inc/Abilities/BlockAbilities.php`
  - Preserve `blockPath` inside normalized block context so the request payload, resolved signatures, and execution context agree.
- Modify `tests/phpunit/BlockAbilitiesTest.php`
  - Assert block context normalization keeps `blockPath` through the recommendation callback path.
- Modify `inc/Activity/Repository.php`
  - Replace combined-window grouped activity hydration with exact per-surface latest windows.
- Modify `tests/phpunit/AgentControllerTest.php`
  - Expand grouped activity coverage so one noisy surface cannot hide another surface.
- Modify `docs/reference/activity-state-machine.md`
  - Document that grouped scope hydration returns a bounded latest window per surface, not a best-effort combined recent window.

---

### Task 1: Add Failing Client Tests For Durable Block Diagnostics

**Files:**
- Modify: `src/context/__tests__/collector.test.js`
- Modify: `src/store/__tests__/store-actions.test.js`
- Modify: `src/inspector/__tests__/BlockRecommendationsPanel.test.js`

- [ ] **Step 1: Add collector coverage for block path**

Add this test near the existing `collectBlockContext` tests in `src/context/__tests__/collector.test.js`:

```js
test( 'includes a durable block path for nested selected blocks', () => {
	mockIntrospectBlockInstance.mockReturnValue( {
		name: 'core/paragraph',
		title: 'Paragraph',
		currentAttributes: { content: 'Nested copy' },
		inspectorPanels: {},
		bindableAttributes: [],
		styles: [],
		activeStyle: '',
		variations: [],
		supportsContentRole: false,
		contentAttributes: {},
		configAttributes: {},
		editingMode: 'default',
		isInsideContentOnly: false,
		blockVisibility: {},
		childCount: 0,
	} );
	mockIntrospectBlockTree.mockReturnValue( [
		{
			clientId: 'root-1',
			name: 'core/group',
			innerBlocks: [
				{
					clientId: 'child-1',
					name: 'core/heading',
					innerBlocks: [],
				},
				{
					clientId: 'target-1',
					name: 'core/paragraph',
					innerBlocks: [],
				},
			],
		},
	] );
	mockFindNodePath.mockReturnValue( [
		{ clientId: 'root-1', name: 'core/group' },
		{ clientId: 'target-1', name: 'core/paragraph' },
	] );
	mockFindBranchRoot.mockReturnValue( null );
	mockCollectThemeTokens.mockReturnValue( {} );
	mockSummarizeTokens.mockReturnValue( { summary: {} } );
	const blockEditor = {
		getBlockRootClientId: jest.fn( ( clientId ) =>
			clientId === 'target-1' ? 'root-1' : null
		),
		getBlockOrder: jest.fn( ( rootClientId = '' ) => {
			if ( rootClientId === '' ) {
				return [ 'root-1' ];
			}
			if ( rootClientId === 'root-1' ) {
				return [ 'child-1', 'target-1' ];
			}
			return [];
		} ),
		getBlockName: jest.fn( ( clientId ) =>
			clientId === 'target-1' ? 'core/paragraph' : 'core/group'
		),
		getBlockAttributes: jest.fn().mockReturnValue( {} ),
	};
	mockSelect.mockReturnValue( blockEditor );

	const result = collectBlockContext( 'target-1' );

	expect( result.block.blockPath ).toEqual( [ 0, 1 ] );
	expect( blockEditor.getBlockOrder ).toHaveBeenCalledWith( '' );
	expect( blockEditor.getBlockOrder ).toHaveBeenCalledWith( 'root-1' );
} );
```

- [ ] **Step 2: Add store diagnostics coverage for block path**

In `src/store/__tests__/store-actions.test.js`, extend the existing block recommendation failure diagnostics test so the mocked context includes a path and the stored diagnostics assert it:

```js
await actions.fetchBlockRecommendations(
	'block-1',
	{
		block: {
			name: 'core/paragraph',
			currentAttributes: { content: 'Hello world' },
			blockPath: [ 0, 1 ],
		},
	},
	'Make it tighter'
)( { dispatch, registry, select } );

expect( setBlockRecsAction.diagnostics ).toEqual(
	expect.objectContaining( {
		type: 'failure',
		blockName: 'core/paragraph',
		blockPath: [ 0, 1 ],
	} )
);
```

Also extend the empty-result diagnostics assertion to include:

```js
expect( setBlockRecsAction.diagnostics ).toEqual(
	expect.objectContaining( {
		hasEmptyBlockResult: true,
		blockPath: [ 0, 1 ],
	} )
);
```

- [ ] **Step 3: Add inspector activity row coverage**

In `src/inspector/__tests__/BlockRecommendationsPanel.test.js`, extend both diagnostic-row tests so `blockRequestDiagnostics['block-1']` includes `blockPath: [ 0, 1 ]`, then assert:

```js
expect( latestActivityProps.entries[ 0 ] ).toEqual(
	expect.objectContaining( {
		type: 'request_diagnostic',
		target: expect.objectContaining( {
			clientId: 'block-1',
			blockName: 'core/paragraph',
			blockPath: [ 0, 1 ],
		} ),
	} )
);
```

- [ ] **Step 4: Run the failing JS tests**

Run:

```bash
npm run test:unit -- src/context/__tests__/collector.test.js src/store/__tests__/store-actions.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js
```

Expected before implementation: at least the new `blockPath` assertions fail.

---

### Task 2: Implement Client-Side Block Path Propagation

**Files:**
- Modify: `src/context/collector.js`
- Modify: `src/store/index.js`
- Modify: `src/inspector/BlockRecommendationsPanel.js`

- [ ] **Step 1: Add block path helpers in `src/context/collector.js`**

Add these helpers near the other block-context helper functions:

```js
function normalizeBlockPath( path ) {
	if ( ! Array.isArray( path ) ) {
		return [];
	}

	return path
		.map( ( segment ) => Number.parseInt( segment, 10 ) )
		.filter( ( segment ) => Number.isInteger( segment ) && segment >= 0 );
}

function findBlockPathInEditor( blockEditor, clientId, rootClientId = '' ) {
	if ( ! blockEditor || ! clientId ) {
		return null;
	}

	const order = blockEditor.getBlockOrder?.( rootClientId ) || [];

	for ( let index = 0; index < order.length; index++ ) {
		const currentClientId = order[ index ];

		if ( currentClientId === clientId ) {
			return [ index ];
		}

		const childPath = findBlockPathInEditor(
			blockEditor,
			clientId,
			currentClientId
		);

		if ( childPath ) {
			return [ index, ...childPath ];
		}
	}

	return null;
}
```

- [ ] **Step 2: Include `blockPath` in collected block context**

In `collectBlockContext()`, reuse the already selected `blockEditor` and include the normalized path in the `context.block` object:

```js
const blockEditor = select( blockEditorStore );
const blockPath = normalizeBlockPath(
	findBlockPathInEditor( blockEditor, clientId )
);
```

Then add the field:

```js
blockPath,
```

inside `context.block`.

When structural actions are enabled later in the same function, reuse the existing `blockEditor` variable instead of redeclaring it.

- [ ] **Step 3: Preserve block path in block request diagnostics**

In `src/store/index.js`, add a local normalizer near `normalizeBlockRequestDiagnostics()`:

```js
function normalizeBlockPath( value ) {
	return Array.isArray( value )
		? value
				.map( ( segment ) => Number.parseInt( segment, 10 ) )
				.filter(
					( segment ) =>
						Number.isInteger( segment ) && segment >= 0
				)
		: [];
}
```

In `buildBlockRecommendationFailureDiagnostics()`, add:

```js
blockPath: normalizeBlockPath(
	requestData.editorContext?.block?.blockPath
),
```

In the successful `fetchBlockRecommendations()` path, add the same field to the diagnostics object:

```js
blockPath: normalizeBlockPath( blockContext?.blockPath ),
```

- [ ] **Step 4: Include diagnostic block path in the inspector activity row**

In `BlockRecommendationsPanel.js`, add `blockPath` to the `diagnosticActivityEntry.target` object:

```js
...( Array.isArray( requestDiagnostics.blockPath ) &&
requestDiagnostics.blockPath.length > 0
	? { blockPath: requestDiagnostics.blockPath }
	: {} ),
```

- [ ] **Step 5: Run JS tests**

Run:

```bash
npm run test:unit -- src/context/__tests__/collector.test.js src/store/__tests__/store-actions.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js
```

Expected: all selected JS suites pass.

---

### Task 3: Persist Server Block Diagnostics With Durable Locators

**Files:**
- Modify: `inc/Abilities/RecommendationAbilityExecution.php`
- Modify: `inc/Abilities/BlockAbilities.php`
- Modify: `tests/phpunit/RecommendationAbilityExecutionTest.php`
- Modify: `tests/phpunit/BlockAbilitiesTest.php`

- [ ] **Step 1: Add a failing persisted diagnostic test**

Add this test to `tests/phpunit/RecommendationAbilityExecutionTest.php`:

```php
public function test_execute_persists_block_request_diagnostic_with_block_path(): void {
	RecommendationAbilityExecution::execute(
		'block',
		'flavor-agent/recommend-block',
		[
			'editorContext' => [
				'block' => [
					'name'      => 'core/paragraph',
					'blockPath' => [ 0, '2', -1, 'bad' ],
				],
			],
			'clientId'      => 'block-a',
			'document'      => [
				'scopeKey' => 'post:42',
			],
			'clientRequest' => [
				'sessionId'    => 'session-1',
				'requestToken' => 1,
				'abortId'      => 'block-a',
				'scopeKey'     => 'post:42',
			],
		],
		static fn(): array => [
			'block'       => [],
			'settings'    => [],
			'styles'      => [],
			'explanation' => 'Block request diagnostic.',
		]
	);

	$entries = WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [];
	$this->assertCount( 1, $entries );

	$target = json_decode( (string) ( $entries[0]['target_json'] ?? '' ), true );
	$this->assertSame( [ 0, 2 ], $target['blockPath'] ?? null );
}
```

- [ ] **Step 2: Preserve block path through block ability context normalization**

Add a focused assertion to `tests/phpunit/BlockAbilitiesTest.php` using the existing successful recommend-block test setup. Include `blockPath` in the `editorContext.block` payload:

```php
'blockPath' => [ 0, '1', -1, 'bad' ],
```

Then assert the existing reflected helper return value includes the sanitized durable locator:

```php
$this->assertSame( [ 0, 1 ], $prepared['context']['block']['blockPath'] ?? null );
```

Use `invoke_prepare_recommend_block_input()` in the existing `test_prepare_recommend_block_input_normalizes_editor_context_payload()` flow; that keeps the assertion on the ability request contract instead of broadening the public `recommend_block()` response shape.

- [ ] **Step 3: Implement PHP block-path normalization**

In `inc/Abilities/RecommendationAbilityExecution.php`, add:

```php
/**
 * @return array<int, int>
 */
private static function sanitize_block_path( mixed $value ): array {
	if ( ! \is_array( $value ) ) {
		return [];
	}

	$path = [];

	foreach ( $value as $segment ) {
		if ( ! \is_numeric( $segment ) ) {
			continue;
		}

		$segment = (int) $segment;

		if ( $segment < 0 ) {
			continue;
		}

		$path[] = $segment;
	}

	return $path;
}
```

Then update `build_block_target()`:

```php
$block_path = self::sanitize_block_path( $block['blockPath'] ?? ( $input['blockPath'] ?? [] ) );
$target     = [
	'clientId'  => \sanitize_text_field( (string) ( $input['clientId'] ?? '' ) ),
	'blockName' => \sanitize_text_field( (string) ( $block['name'] ?? ( $input['selectedBlock']['blockName'] ?? '' ) ) ),
];

if ( [] !== $block_path ) {
	$target['blockPath'] = $block_path;
}

return $target;
```

- [ ] **Step 4: Preserve block path in `BlockAbilities` context**

In `inc/Abilities/BlockAbilities.php`, add a matching private helper:

```php
/**
 * @return array<int, int>
 */
private static function normalize_block_path( mixed $value ): array {
	if ( ! is_array( $value ) ) {
		return [];
	}

	$path = [];

	foreach ( $value as $segment ) {
		if ( ! is_numeric( $segment ) ) {
			continue;
		}

		$segment = (int) $segment;

		if ( $segment >= 0 ) {
			$path[] = $segment;
		}
	}

	return $path;
}
```

In `build_context_from_editor_context()`, after preserving `editingMode`, add:

```php
$block_path = self::normalize_block_path( $block['blockPath'] ?? [] );
if ( [] !== $block_path ) {
	$normalized['block']['blockPath'] = $block_path;
}
```

- [ ] **Step 5: Run PHP tests**

Run:

```bash
composer run test:php -- --filter 'RecommendationAbilityExecutionTest|BlockAbilitiesTest'
```

Expected: all selected PHP tests pass.

---

### Task 4: Make Grouped Activity Hydration Exact Per Surface

**Files:**
- Modify: `inc/Activity/Repository.php`
- Modify: `tests/phpunit/AgentControllerTest.php`

- [ ] **Step 1: Expand grouped hydration regression coverage**

Replace the loop in `test_handle_get_activity_grouped_by_surface_keeps_executable_history_when_diagnostics_are_newer()` with a noisy window large enough to exceed the current combined fetch bound:

```php
$base_timestamp = strtotime( '2026-03-24T10:01:00Z' );

for ( $index = 1; $index <= 181; ++$index ) {
	$pattern_entry               = $this->build_activity_entry( 'activity-pattern-' . $index );
	$pattern_entry['type']       = 'request_diagnostic';
	$pattern_entry['surface']    = 'pattern';
	$pattern_entry['target']     = [
		'requestRef' => 'pattern:' . $index,
	];
	$pattern_entry['suggestion'] = 'Pattern diagnostic ' . $index;
	$pattern_entry['timestamp']  = gmdate(
		'Y-m-d\TH:i:s\Z',
		$base_timestamp + $index
	);
	$pattern_entry['undo']       = [
		'canUndo' => false,
		'status'  => 'review',
	];

	ActivityRepository::create( $pattern_entry );
}
```

Keep these assertions:

```php
$this->assertContains( 'activity-template', array_column( $entries, 'id' ) );
$this->assertCount(
	20,
	array_filter(
		$entries,
		static fn( array $entry ): bool => 'pattern' === ( $entry['surface'] ?? '' )
	)
);
$this->assertContains( 'activity-pattern-181', array_column( $entries, 'id' ) );
$this->assertNotContains( 'activity-pattern-1', array_column( $entries, 'id' ) );
```

- [ ] **Step 2: Run the failing PHP regression**

Run:

```bash
composer run test:php -- --filter 'test_handle_get_activity_grouped_by_surface_keeps_executable_history_when_diagnostics_are_newer'
```

Expected before implementation: the template entry is missing because the combined latest window is filled by pattern diagnostics.

- [ ] **Step 3: Implement exact per-surface grouping**

In `inc/Activity/Repository.php`, replace the combined-window section in `query_grouped_by_surface()` with exact surface discovery plus one bounded query per surface:

```php
$surfaces = self::query_surfaces_for_grouped_activity( $filters );
$entries  = [];

foreach ( $surfaces as $entry_surface ) {
	$query_filters = array_merge(
		$filters,
		[
			'scopeKey' => $scope_key,
			'surface'  => $entry_surface,
			'limit'    => $surface_limit,
		]
	);
	unset( $query_filters['groupBySurface'], $query_filters['surfaceLimit'] );

	foreach ( self::query( $query_filters ) as $surface_entry ) {
		$entries[] = $surface_entry;
	}
}
```

Add this helper in the repository:

```php
/**
 * @param array<string, mixed> $filters
 * @return array<int, string>
 */
private static function query_surfaces_for_grouped_activity( array $filters ): array {
	global $wpdb;

	if ( ! is_object( $wpdb ) ) {
		return [];
	}

	$scope_key   = trim( (string) ( $filters['scopeKey'] ?? '' ) );
	$entity_type = trim( (string) ( $filters['entityType'] ?? '' ) );
	$entity_ref  = trim( (string) ( $filters['entityRef'] ?? '' ) );
	$user_id     = (int) ( $filters['userId'] ?? 0 );
	$conditions  = [];
	$args        = [];

	if ( '' !== $scope_key ) {
		$conditions[] = 'document_scope_key = %s';
		$args[]       = $scope_key;
	}

	if ( '' !== $entity_type ) {
		$conditions[] = 'entity_type = %s';
		$args[]       = $entity_type;
	}

	if ( '' !== $entity_ref ) {
		$conditions[] = 'entity_ref = %s';
		$args[]       = $entity_ref;
	}

	if ( $user_id > 0 ) {
		$conditions[] = 'user_id = %d';
		$args[]       = $user_id;
	}

	$sql = 'SELECT DISTINCT surface FROM ' . self::table_name();

	if ( [] !== $conditions ) {
		$sql .= ' WHERE ' . implode( ' AND ', $conditions );
	}

	$sql   .= ' ORDER BY surface ASC LIMIT %d';
	$args[] = self::MAX_KNOWN_SURFACES;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Query targets the plugin-owned activity table and is prepared in the same call.
	$surfaces = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

	return array_values(
		array_filter(
			array_map(
				static fn( mixed $surface ): string => trim( (string) $surface ),
				is_array( $surfaces ) ? $surfaces : []
			),
			static fn( string $surface ): bool => '' !== $surface
		)
	);
}
```

Keep the existing final `usort()` so the merged response remains oldest-first for client history.

- [ ] **Step 4: Run grouped hydration tests**

Run:

```bash
composer run test:php -- --filter 'AgentControllerTest'
```

Expected: all Agent Controller tests pass, including the expanded grouped hydration regression.

---

### Task 5: Update Docs And Verification

**Files:**
- Modify: `docs/reference/activity-state-machine.md`

- [ ] **Step 1: Document the exact grouped hydration contract**

In `docs/reference/activity-state-machine.md`, update the "Activity Entry Lifecycle" or "Scope Hydration Retry" section with:

```markdown
When server hydration requests `groupBySurface=true`, the repository returns the latest bounded window for each surface independently. A noisy read-only surface such as pattern request diagnostics must not evict executable history from template, block, style, or other surfaces in the same scope.
```

- [ ] **Step 2: Run the focused verification gate**

Run:

```bash
npm run test:unit -- src/context/__tests__/collector.test.js src/store/__tests__/store-actions.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js src/store/__tests__/activity-history.test.js
composer run test:php -- --filter 'RecommendationAbilityExecutionTest|BlockAbilitiesTest|AgentControllerTest'
npm run check:docs
git diff --check
```

Expected:

```text
Test Suites: all selected JS suites pass
PHPUnit exits 0 for the selected tests
docs check exits 0
git diff --check exits 0
```

- [ ] **Step 3: Run the broader fast gate**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: build, lint, plugin-check, JS unit, and PHP checks complete without introducing new failures. If plugin-check prerequisites are unavailable, rerun intentionally scoped verification and record the exact blocker from `output/verify/summary.json`.

## Acceptance Criteria

- Block recommendation request diagnostics persisted through Abilities include `target.blockPath` whenever the client can resolve one.
- Locally synthesized block request diagnostics include `target.blockPath` and therefore match the block inspector’s durable path fallback after reloads.
- Block context signatures include the durable locator so stale/fresh comparisons reflect the same target identity used by diagnostics and apply history.
- Grouped scoped activity hydration returns up to `surfaceLimit` entries for every discovered surface, even when one surface has more than `surfaceLimit * MAX_KNOWN_SURFACES` newer rows.
- `request_diagnostic` rows remain review-only and do not become undo blockers.
- Existing activity admin projection continues to receive block path metadata via the existing `target.blockPath` path.
- Targeted JS, PHP, docs, and whitespace checks pass.

## Implementation Notes

- Keep `clientId` in diagnostic targets for live-session precision; add `blockPath` as the durable fallback rather than replacing `clientId`.
- Do not broaden block structural apply behavior. This plan only fixes diagnostic identity and activity hydration correctness.
- Keep request-time diagnostic logging separate from apply/undo history. The fix should not make diagnostics undoable or executable.
- Prefer exact per-surface hydration over a single combined-window optimization. The maximum known surface count is intentionally small, and correctness matters more than saving a few plugin-owned table queries.
