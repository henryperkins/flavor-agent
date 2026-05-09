# Sync Pattern Catalog Indexing Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the `Sync Pattern Catalog` control disabled whenever the catalog runtime state is `indexing`, including initial PHP-rendered markup, JavaScript initialization, queued POST responses, and active polling.

**Architecture:** Treat `indexing` as first-class sync-panel state owned by the settings page markup and controller. The backend queue/cron path remains unchanged; the fix is a UI guard that mirrors the server runtime state and prevents duplicate admin sync POSTs while polling is active.

**Tech Stack:** WordPress plugin PHP, Settings admin JavaScript, Jest via `@wordpress/scripts`, PHPUnit.

---

## Confirmed Finding Covered

- The sync button can be used again while the catalog is still `indexing`. PHP only disables it when prerequisites are missing, not when the runtime state is `indexing`, and JS `canSyncNow()` only checks prerequisites plus backend-preview state. After a queued POST returns `runtimeState.status = "indexing"`, the click handler's `finally` calls `setBusy( false )`, so the button becomes enabled again while polling continues.

## File Map

- Modify `inc/Admin/Settings/Page.php`
  - Add `data-pattern-sync-status` to the sync panel body.
  - Render the sync button disabled when the current pattern runtime state is `indexing`.
  - Point `aria-describedby` to the existing sync summary while indexing, preserving prerequisite and backend-preview descriptions.
- Modify `tests/phpunit/SettingsTest.php`
  - Add direct render coverage for the PHP sync panel's indexing disabled state.
- Modify `src/admin/settings-page-controller.js`
  - Track current sync status from `data-pattern-sync-status`.
  - Make `canSyncNow()` fail when status is `indexing`.
  - Set the status to `indexing` before POST submission and keep the button disabled while polling is active.
  - Re-enable the button only after polling returns a non-indexing state or polling fails.
- Modify `src/admin/__tests__/settings-page-controller.test.js`
  - Add fixture support for initial sync status.
  - Add regressions for initial indexing, duplicate-click prevention during polling, and re-enable after ready.

## Non-Goals

- Do not change `PatternIndex::enqueue_sync()`, `PatternIndex::run_due_sync()`, or the REST route contract.
- Do not run sync work synchronously from the button. The POST route should continue queuing work and the GET route should continue polling/runtime refresh.
- Do not add new visible copy. Reuse the existing sync summary and status labels.

## Task 1: Add JavaScript Regression Coverage

**Files:**
- Modify: `src/admin/__tests__/settings-page-controller.test.js`

- [ ] **Step 1: Extend the settings-page fixture with sync status**

  _Why_: The controller needs to read the same runtime state that PHP will render.

  In `renderSettingsPage()`, add a `syncStatus` option:

  ```js
  function renderSettingsPage( {
  	defaultSection = 'chat',
  	forceSection = '',
  	includePatternBackendPreview = false,
  	prerequisiteMessage = '',
  	prerequisitesReady = '1',
  	syncStatus = 'uninitialized',
  } = {} ) {
  ```

  Add the status dataset to the `.flavor-agent-sync-panel` fixture:

  ```html
  <div
  	class="flavor-agent-settings-subpanel__body flavor-agent-sync-panel"
  	data-pattern-prerequisites-ready="${ prerequisitesReady }"
  	data-pattern-prerequisite-message="${ prerequisiteMessage }"
  	data-pattern-sync-status="${ syncStatus }"
  	data-pattern-backend-preview-matches-saved="1"
  >
  ```

- [ ] **Step 2: Add failing coverage for initial indexing state**

  _Why_: A page loaded while the catalog is already indexing must not enable the button before any REST response returns.

  Add this test near the existing sync-button tests:

  ```js
  test( 'sync button remains disabled when the initial runtime status is indexing', () => {
  	const root = renderSettingsPage( {
  		prerequisitesReady: '1',
  		prerequisiteMessage: '',
  		syncStatus: 'indexing',
  	} );

  	initializeSettingsPage( {
  		root,
  		fetchImpl: jest.fn(),
  		storage: createStorage(),
  	} );

  	const button = root.querySelector( '#flavor-agent-sync-button' );

  	expect( button.disabled ).toBe( true );
  	expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
  	expect( button.getAttribute( 'aria-describedby' ) ).toBe(
  		'flavor-agent-sync-summary'
  	);
  } );
  ```

  Run:

  ```bash
  npm run test:unit -- src/admin/__tests__/settings-page-controller.test.js
  ```

  Expected before implementation: this new test fails because `canSyncNow()` ignores `indexing`.

- [ ] **Step 3: Add failing coverage for duplicate POST prevention while polling**

  _Why_: This pins the reviewed regression directly: after POST returns `indexing`, the button must remain disabled and a second click must not enqueue another sync.

  Add this test near `queued sync polls until the live panel leaves indexing`:

  ```js
  test( 'queued sync keeps the button disabled and ignores another click while polling', async () => {
  	const root = renderSettingsPage();
  	const fetchImpl = jest
  		.fn()
  		.mockResolvedValueOnce( {
  			ok: true,
  			text: async () =>
  				JSON.stringify( {
  					queued: true,
  					scheduled: true,
  					runtimeState: {
  						status: 'indexing',
  						indexed_count: 0,
  						last_synced_at: null,
  					},
  					status: 'indexing',
  				} ),
  		} )
  		.mockImplementationOnce(
  			() =>
  				new Promise( () => {
  					// Keep the GET poll open so the control stays in the polling state.
  				} )
  		);

  	initializeSettingsPage( {
  		root,
  		fetchImpl,
  		storage: createStorage(),
  	} );

  	const button = root.querySelector( '#flavor-agent-sync-button' );

  	button.click();
  	await flushPromises();
  	await flushPromises();

  	expect( button.disabled ).toBe( true );
  	expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'true' );

  	button.click();

  	expect(
  		fetchImpl.mock.calls.filter(
  			( [ , options ] ) => options?.method === 'POST'
  		)
  	).toHaveLength( 1 );
  } );
  ```

  Run:

  ```bash
  npm run test:unit -- src/admin/__tests__/settings-page-controller.test.js
  ```

  Expected before implementation: this test fails because the button is re-enabled by the click handler's `finally`.

- [ ] **Step 4: Extend the ready-poll test**

  _Why_: The fix must not leave the button disabled after polling reaches a terminal ready state.

  In `queued sync polls until the live panel leaves indexing`, after the existing notice assertion, add:

  ```js
  expect( root.querySelector( '#flavor-agent-sync-button' ).disabled ).toBe(
  	false
  );
  expect(
  	root
  		.querySelector( '.flavor-agent-sync-panel' )
  		.dataset.patternSyncStatus
  ).toBe( 'ready' );
  ```

  Run:

  ```bash
  npm run test:unit -- src/admin/__tests__/settings-page-controller.test.js
  ```

  Expected before implementation: the dataset assertion fails because the controller does not persist the runtime status into the panel dataset.

- [ ] **Step 5: Extend the POST failure test**

  _Why_: The click handler will optimistically mark the panel as `indexing` before POST. If the POST fails, that temporary state must be cleared so the user can retry.

  In `sync failure keeps the panel open and surfaces the server error`, after the existing status assertion, add:

  ```js
  expect( root.querySelector( '#flavor-agent-sync-button' ).disabled ).toBe(
  	false
  );
  expect(
  	root
  		.querySelector( '.flavor-agent-sync-panel' )
  		.dataset.patternSyncStatus
  ).toBe( 'error' );
  ```

  Run:

  ```bash
  npm run test:unit -- src/admin/__tests__/settings-page-controller.test.js
  ```

  Expected before implementation: the dataset assertion fails because POST failure does not currently write a sync status to the panel.

## Task 2: Implement the JavaScript Runtime Guard

**Files:**
- Modify: `src/admin/settings-page-controller.js`
- Test: `src/admin/__tests__/settings-page-controller.test.js`

- [ ] **Step 1: Persist runtime status when the panel updates**

  _Why_: `updateSyncPanelState()` is the shared path used by both POST and GET responses, so it should keep the DOM state current for the sync guard.

  In `updateSyncPanelState()`, after the `state` object is assigned, add:

  ```js
  const currentStatus = normalizeText( state.status, 'uninitialized' );
  syncBody.dataset.patternSyncStatus = currentStatus;
  ```

  Then replace later repeated status normalization in this function with `currentStatus`:

  ```js
  getPatternSyncStatusTone( currentStatus )
  getPatternSyncStatusLabel( currentStatus )
  normalizeText( state.status ) === 'stale'
  normalizeText( state.status ) === 'indexing'
  ```

  The last two checks should become:

  ```js
  currentStatus === 'stale' || currentStatus === 'indexing'
  ```

- [ ] **Step 2: Add current-status helpers in `initializePatternSync()`**

  _Why_: The click handler and accessibility updater need one source of truth.

  After `const syncEndpoint = ...`, add:

  ```js
  let pollTimeout = null;

  const getCurrentSyncStatus = () =>
  	normalizeText(
  		syncBody.dataset.patternSyncStatus,
  		'uninitialized'
  	);

  const setCurrentSyncStatus = ( status ) => {
  	syncBody.dataset.patternSyncStatus = normalizeText(
  		status,
  		'uninitialized'
  	);
  };
  ```

  If `let pollTimeout = null;` already exists, replace that single line with the full block above.

- [ ] **Step 3: Disable sync while status is indexing**

  _Why_: Prerequisites and backend-preview state are necessary but not sufficient; active indexing is also a disabled state.

  Replace `canSyncNow()` with:

  ```js
  const havePatternPrerequisites = () =>
  	normalizeText( syncBody.dataset.patternPrerequisitesReady ) === '1';

  const canSyncNow = () => {
  	return (
  		havePatternPrerequisites() &&
  		isPreviewMatchingSaved() &&
  		getCurrentSyncStatus() !== 'indexing'
  	);
  };
  ```

- [ ] **Step 4: Preserve useful `aria-describedby` while indexing**

  _Why_: When the button is disabled by active indexing, the existing sync summary already explains the state.

  Add this constant beside `previewHint`:

  ```js
  const syncSummary = root.querySelector( '#flavor-agent-sync-summary' );
  ```

  In `updateButtonAccessibility()`, keep preview mismatch first, then prerequisite guidance, then indexing. Replace the current prerequisite/indexing description block after the preview-hint branch with:

  ```js
  if ( ! havePatternPrerequisites() && prerequisiteCopy ) {
  	if ( ! prerequisiteCopy.id ) {
  		prerequisiteCopy.id = 'flavor-agent-sync-prerequisites';
  	}
  	button.setAttribute( 'aria-describedby', prerequisiteCopy.id );
  	return;
  }

  if ( getCurrentSyncStatus() === 'indexing' && syncSummary ) {
  	if ( ! syncSummary.id ) {
  		syncSummary.id = 'flavor-agent-sync-summary';
  	}
  	button.setAttribute( 'aria-describedby', syncSummary.id );
  	return;
  }

  button.removeAttribute( 'aria-describedby' );
  return;
  ```

  Remove the old fallback block that assigns `aria-describedby` to `prerequisiteCopy` unconditionally after `if ( ! prerequisiteCopy )`.

- [ ] **Step 5: Keep the button disabled while a queued sync is polling**

  _Why_: The POST response may legitimately be `indexing`; `finally` must not blindly re-enable the button.

  In the button click handler, add this variable before `try`:

  ```js
  let keepBusyAfterRequest = false;
  ```

  Immediately after `setBusy( true );`, add:

  ```js
  setCurrentSyncStatus( 'indexing' );
  ```

  After successful payload parsing and panel update, replace the polling block with:

  ```js
  const responseStatus = normalizeText(
  	payload?.runtimeState?.status,
  	normalizeText( payload?.status, 'ready' )
  );
  setCurrentSyncStatus( responseStatus );
  keepBusyAfterRequest = responseStatus === 'indexing';

  if ( keepBusyAfterRequest ) {
  	pollSyncState();
  }
  ```

  Replace the `finally` block with:

  ```js
  } finally {
  	setBusy( keepBusyAfterRequest );
  }
  ```

  In the click handler's `catch` block, before `renderNotice( noticeRoot, 'error', message );`, add:

  ```js
  setCurrentSyncStatus( 'error' );
  ```

- [ ] **Step 6: Re-enable when polling reaches a terminal state**

  _Why_: Once GET reports `ready`, `stale`, `error`, or any non-indexing state, the user can sync again if prerequisites still pass.

  In `pollSyncState()`, replace the current polling continuation block with:

  ```js
  const keepPolling = shouldPollSyncState( payload?.runtimeState );

  if ( keepPolling ) {
  	setBusy( true );
  	pollTimeout = setTimeout(
  		pollSyncState,
  		PATTERN_SYNC_POLL_INTERVAL_MS
  	);
  	return;
  }

  setBusy( false );
  ```

  In the `catch` block for `pollSyncState()`, after updating the notice/live region, add:

  ```js
  setCurrentSyncStatus( 'error' );
  setBusy( false );
  ```

- [ ] **Step 7: Run focused JS tests**

  Run:

  ```bash
  npm run test:unit -- src/admin/__tests__/settings-page-controller.test.js
  ```

  Expected after implementation: PASS.

## Task 3: Add PHP Render Coverage

**Files:**
- Modify: `tests/phpunit/SettingsTest.php`

- [ ] **Step 1: Add failing PHP coverage for indexing markup**

  _Why_: JavaScript should not be responsible for fixing an initially clickable indexing button after page load.

  Add this test near the existing settings render tests:

  ```php
  public function test_sync_panel_renders_button_disabled_while_indexing(): void {
  	$state                   = $this->build_default_open_group_state();
  	$state['patterns_ready'] = true;
  	$state['pattern_state']  = array_merge(
  		PatternIndex::get_state(),
  		[
  			'status'         => 'indexing',
  			'last_synced_at' => null,
  			'indexed_count'  => 0,
  			'last_error'     => null,
  		]
  	);
  	$method                  = new \ReflectionMethod( Page::class, 'render_sync_panel' );
  	$method->setAccessible( true );

  	ob_start();
  	$method->invoke( null, $state );
  	$output = (string) ob_get_clean();

  	$this->assertStringContainsString( 'data-pattern-sync-status="indexing"', $output );
  	$this->assertMatchesRegularExpression(
  		'/<button(?=[^>]*id="flavor-agent-sync-button")(?=[^>]*disabled="disabled")(?=[^>]*aria-disabled="true")(?=[^>]*aria-describedby="flavor-agent-sync-summary")[^>]*>/',
  		$output
  	);
  }
  ```

  Run:

  ```bash
  composer run test:php -- --filter SettingsTest
  ```

  Expected before implementation: this test fails because the sync panel does not render the status dataset or disabled button for `indexing`.

## Task 4: Implement PHP Initial Markup Guard

**Files:**
- Modify: `inc/Admin/Settings/Page.php`
- Test: `tests/phpunit/SettingsTest.php`

- [ ] **Step 1: Compute the indexing state in `render_sync_panel()`**

  _Why_: The server-rendered button should reflect the real runtime state before JavaScript initializes.

  After `$sync_summary_sentence`, add:

  ```php
  $is_syncing = 'indexing' === sanitize_key( (string) ( $state['status'] ?? '' ) );
  ```

- [ ] **Step 2: Add status to the sync panel dataset**

  _Why_: JavaScript needs an initial status source without making a REST request first.

  In the `.flavor-agent-sync-panel` attributes, add:

  ```php
  data-pattern-sync-status="<?php echo esc_attr( sanitize_key( (string) ( $state['status'] ?? 'uninitialized' ) ) ); ?>"
  ```

- [ ] **Step 3: Disable the button when indexing**

  _Why_: Missing prerequisites and active indexing are both disabled states.

  Replace the current `$sync_button_attributes` definition with:

  ```php
  $sync_button_attributes = [
  	'type'          => 'button',
  	'id'            => 'flavor-agent-sync-button',
  	'class'         => 'button button-primary',
  	'aria-disabled' => ( $has_prerequisites && ! $is_syncing ) ? 'false' : 'true',
  ];
  ```

  Replace the existing disabled/prerequisite block with:

  ```php
  if ( ! $has_prerequisites || $is_syncing ) {
  	$sync_button_attributes['disabled'] = 'disabled';

  	if ( ! $has_prerequisites && '' !== $prerequisite_message ) {
  		$sync_button_attributes['aria-describedby'] = $prerequisite_id;
  	} elseif ( $is_syncing ) {
  		$sync_button_attributes['aria-describedby'] = 'flavor-agent-sync-summary';
  	}
  }
  ```

- [ ] **Step 4: Run focused PHP tests**

  Run:

  ```bash
  composer run test:php -- --filter SettingsTest
  ```

  Expected after implementation: PASS.

## Task 5: Verification and Documentation Gate

**Files:**
- Verify only unless the implementation changes contributor-facing behavior beyond the admin control state.

- [ ] **Step 1: Run formatting/diff hygiene**

  Run:

  ```bash
  git diff --check
  ```

  Expected: no output and exit code `0`.

- [ ] **Step 2: Run targeted JS and PHP tests**

  Run:

  ```bash
  npm run test:unit -- src/admin/__tests__/settings-page-controller.test.js
  composer run test:php -- --filter SettingsTest
  ```

  Expected: both commands pass.

- [ ] **Step 3: Run route/controller regression tests**

  _Why_: The fix should not change the `sync-patterns` REST route, but this confirms the queue/poll contract still holds.

  Run:

  ```bash
  composer run test:php -- --filter 'AgentControllerTest|AgentRoutesTest'
  ```

  Expected: PASS.

- [ ] **Step 4: Run the fast aggregate verifier**

  Run:

  ```bash
  npm run verify -- --skip-e2e
  ```

  Expected: `VERIFY_RESULT={"status":"pass",...}`.

- [ ] **Step 5: Check docs freshness only if docs or contributor-facing contracts changed**

  If implementation changes only `Page.php`, `settings-page-controller.js`, and their tests, skip this command and record why in the final response.

  If implementation also changes docs or contributor-facing route/contract text, run:

  ```bash
  npm run check:docs
  ```

  Expected: PASS.

## Completion Criteria

- The button is disabled in PHP-rendered markup when `pattern_state.status === "indexing"`.
- JavaScript initialization keeps an initially indexing button disabled before REST traffic.
- After POST returns `runtimeState.status === "indexing"`, the button remains disabled while GET polling is active.
- A second click during active polling does not issue another POST to `/flavor-agent/v1/sync-patterns`.
- If the initial POST fails, the temporary `indexing` state is cleared to `error` and the button can be retried.
- When polling returns `ready`, the button re-enables if prerequisites still pass.
- Existing prerequisite and backend-preview disabled states keep their current `aria-describedby` behavior.
- No backend sync, cron, or REST contract changes are required.
