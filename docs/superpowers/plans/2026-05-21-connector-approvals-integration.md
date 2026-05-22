# Connector Approvals Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Date:** 2026-05-21
**Owner:** Henry Perkins
**Status:** Implemented - non-E2E verification passed; final post-approval runtime smoke pending representative text-generation provider availability
**Upstream trigger:** `WordPress/ai#467` shipped the Connector Approval experiment in AI plugin 1.0.0 on 2026-05-19.

**Goal:** When the canonical WordPress AI plugin denies a Flavor Agent chat request because Connector Approval is enabled, show an actionable request-time editor notice that names the connector and caller, links to the AI plugin approvals page, and avoids the generic request-error path.

**Architecture:** Normalize Connector Approval denials at the `WordPressAIClient::chat()` boundary, preserve structured connector/caller metadata through the abilities/REST client error object, and render a dedicated request-time `CapabilityNotice` variant from per-surface error details. Do not inspect the AI plugin `Approvals_Store` during editor bootstrap; the upstream HTTP guard records pending requests on first denial.

**Tech Stack:** WordPress plugin PHP, WordPress AI Client / AI plugin, WordPress data store, Gutenberg editor React components, Jest, PHPUnit, local Docker WordPress stack.

---

## Success Criteria

1. A blocked Flavor Agent chat call produces a request-time `CapabilityNotice` variant that names the denied connector and names `Flavor Agent` or `flavor-agent/flavor-agent.php`.
2. The generic request error notice is suppressed for Connector Approval denials so users do not see duplicate messages.
3. The first denied request seeds the AI plugin pending approvals queue with `caller_basename=flavor-agent/flavor-agent.php` on an AI plugin build whose caller attribution includes `WordPress/ai#595` or equivalent.
4. If the installed AI plugin still attributes the pending request to `ai/ai.php` or an AI provider connector plugin, the implementation records that as an upstream-version blocker instead of claiming Flavor Agent approval works.
5. After an admin approves Flavor Agent for the denied connector, the next chat call succeeds with no Flavor Agent code change.
6. Admin users see an `Open approvals page` action that links to `Tools > Connector Approvals`; non-admin editors see request-time copy that asks them to contact an administrator and never receives or renders a dead admin link.
7. Targeted PHPUnit and Jest suites pass, and `node scripts/verify.js --skip-e2e` passes.

## Current Upstream Contract

**Experiment:** `connector-approval`, category `Experiment_Category::ADMIN`, opt-in via `Settings > AI`.

**Enforcement layer:** `WordPress\AI\Connector_Approval\Http_Guard` on `pre_http_request`. Flavor Agent does not control the pending-approval write; it only receives the denied request result and surfaces it.

**Canonical rejection shape from `Http_Guard::maybe_block_request`:**

```php
return new WP_Error(
	'wpai_connector_not_approved',
	sprintf(
		__( 'The "%1$s" AI connector has not been approved for use by "%2$s".', 'ai' ),
		$connector_id,
		$caller['basename']
	),
	array(
		'status'       => 403,
		'connector_id' => $connector_id,
		'caller'       => $caller, // array{type:string, basename:string, name:string}
	)
);
```

**Caller-attribution caveat:** AI plugin 1.0.0 can over-attribute Connector Approval denials to the AI plugin or provider connector plugin. `WordPress/ai#595` is open for AI plugin 1.1.0 and changes caller matching from the first extension stack frame to the deepest originating extension frame. Execution must prove that the installed AI plugin records `flavor-agent/flavor-agent.php` before treating "approve Flavor Agent and retry" as verified.

**Admin URL:** Current upstream `Admin_Page::url()` returns `admin_url( 'tools.php?page=ai-connector-approval' )`.

## Decisions

| # | Decision | Choice | Reason |
|---|----------|--------|--------|
| 1 | Detection layer | `WordPressAIClient::chat()` / `normalize_ai_client_error()` | This is the existing choke point for `WP_Error` results from AI generation. It already records diagnostics and feeds all chat-backed abilities. |
| 2 | Throwable wrapper handling | Parse only the exact upstream denial message when structured data is missing | The current `call_prompt_method()` catches all throwables and wraps them as `wp_ai_client_request_failed`; direct `WP_Error` coverage alone does not protect that path. |
| 3 | Editor bootstrap lookup | No proactive `Approvals_Store` read | Pending approvals are written by upstream on denial. Boot-time option inspection would couple Flavor Agent to AI plugin internals and cannot know the denied connector before a request. |
| 4 | JS surface | Request-time error details, not bootstrap availability flags | `getCapabilityNotice()` currently returns `null` when the surface is configured. Connector Approval blocks configured connectors, so the UI must use request-error metadata. |
| 5 | Notice component | Reuse `CapabilityNotice` with an explicit request notice | This keeps setup-style CTA rendering consistent while avoiding `AIStatusNotice`/generic request-error copy for this denial type. |
| 6 | Upstream caller proof | Mandatory smoke/version gate | Success criteria 2-5 depend on upstream caller attribution, not just Flavor Agent code. |

## Files And Responsibilities

- Modify `inc/LLM/WordPressAIClient.php`: detect Connector Approval errors, preserve structured metadata, parse exact wrapped upstream messages, expose admin URL helper, and omit approval admin URLs for users without `manage_options`.
- Modify `flavor-agent.php`: expose `connectorApprovalUrl` in `flavorAgentData` only for users with `manage_options`; keep the key present but empty for non-admins.
- Create `src/store/request-error-details.js`: normalize REST/bridge errors into stable `{ code, message, data, connectorApproval }` objects.
- Modify `src/store/index.js`: store request error details beside existing request error strings for block, content, pattern, and navigation surfaces; pass details into `getSurfaceStatusNotice()`.
- Modify `src/store/executable-surfaces.js`: add per-surface `errorDetailsKey` state and selectors for template, template-part, global-styles, and style-book.
- Modify `src/store/executable-surface-runtime.js`: pass normalized error details through generic executable-surface status actions.
- Modify `src/utils/capability-flags.js`: add `getConnectorApprovalNotice()` that builds admin and non-admin dedicated request-time notices from error details and bootstrap data.
- Modify `src/components/CapabilityNotice.js`: accept an explicit `notice` prop in addition to the existing `surface` lookup.
- Modify chat-backed surfaces: render the connector approval notice from request error details and suppress the generic request-error notice for the same error.
- Modify tests under `tests/phpunit/`, `src/store/__tests__/`, `src/utils/__tests__/`, and surface component tests.
- Modify docs only after code behavior is pinned by tests.

---

## Task 1: Add Upstream Runtime Probe And Release Gate

**Files:**
- Modify: `docs/validation/2026-05-21-connector-approvals-smoke.md`
- Modify during smoke only: local WordPress container options/admin state

- [ ] **Step 1: Record installed AI plugin version and experiment availability**

Run:

```bash
npm run wp:start
docker compose exec wordpress wp plugin list --allow-root | grep -E '^(ai|ai-provider-for-openai|ai-provider-for-anthropic|ai-provider-for-google)\s'
docker compose exec wordpress wp option get wpai_experiments --allow-root
```

Expected: The AI plugin is installed and active. If the plugin version is 1.0.0, continue but treat caller attribution as suspect until the smoke proves otherwise. If the Connector Approval experiment is unavailable, stop and update the validation note with the blocker.

- [ ] **Step 2: Enable Connector Approval and run one denied Flavor Agent request**

Use the admin UI when possible:

1. Open `Settings > AI`.
2. Enable the `connector-approval` experiment.
3. Remove any existing approval for Flavor Agent and the selected connector under `Tools > Connector Approvals`.
4. Trigger one Flavor Agent recommendation request from the editor.

Fallback inspection command after the denied request:

```bash
docker compose exec wordpress wp option get wpai_connector_approval_pending --format=json --allow-root
```

Expected for code execution to continue as a fully shippable integration:

```json
{
  "flavor-agent/flavor-agent.php::openai": {
    "caller_type": "plugin",
    "caller_basename": "flavor-agent/flavor-agent.php",
    "caller_name": "Flavor Agent",
    "connector_id": "openai",
    "attempts": 1,
    "first_seen": 1779321600,
    "last_seen": 1779321600
  }
}
```

The pending key separator is `::`, matching `WordPress\AI\Connector_Approval\Approvals_Store::pending_key()`. Treat `attempts`, `first_seen`, and `last_seen` as runtime values: record the exact observed values in the smoke note, but assert the caller and connector fields semantically.

If the pending entry is for `ai/ai.php` or a provider connector plugin, keep the Flavor Agent UI/error-normalization work but mark final runtime approval verification blocked on AI plugin 1.1.0 or a local build containing `WordPress/ai#595`.

- [ ] **Step 3: Save the smoke baseline**

Create or update `docs/validation/2026-05-21-connector-approvals-smoke.md` with:

````markdown
# Connector Approvals Smoke Baseline

Date: 2026-05-21

## Installed AI Stack

- WordPress:
- AI plugin:
- Provider connector:
- Connector Approval experiment:

## First-Denial Pending Entry

```json
{}
```

Expected key format: `caller_basename::connector_id`, for example `flavor-agent/flavor-agent.php::openai`.

## Outcome

- Caller attribution: `flavor-agent/flavor-agent.php` / blocked on upstream caller attribution
- Notes:
````

Do not proceed to a final "works after approval" claim unless the caller attribution is Flavor Agent.

---

## Task 2: Normalize PHP Connector Approval Errors

**Files:**
- Modify: `inc/LLM/WordPressAIClient.php`
- Modify: `tests/phpunit/bootstrap.php`
- Test: `tests/phpunit/WordPressAIClientTest.php`

- [ ] **Step 1: Write direct `WP_Error` regression**

Add a PHPUnit test that configures a supported AI client and returns the canonical upstream error:

```php
public function test_chat_preserves_connector_approval_error_data(): void {
	WordPressTestState::$ai_client_supported            = true;
	WordPressTestState::$ai_client_generate_text_result = new \WP_Error(
		'wpai_connector_not_approved',
		'The "openai" AI connector has not been approved for use by "flavor-agent/flavor-agent.php".',
		[
			'status'       => 403,
			'connector_id' => 'openai',
			'caller'       => [
				'type'     => 'plugin',
				'basename' => 'flavor-agent/flavor-agent.php',
				'name'     => 'Flavor Agent',
			],
		]
	);

	$result = WordPressAIClient::chat(
		'WordPress Gutenberg block styling and configuration assistant.',
		'Recommend a better block.'
	);

	$this->assertInstanceOf( \WP_Error::class, $result );
	$this->assertSame( 'wpai_connector_not_approved', $result->get_error_code() );

	$data = $result->get_error_data();
	$this->assertSame( 'openai', $data['connector_id'] ?? null );
	$this->assertSame( 'flavor-agent/flavor-agent.php', $data['caller']['basename'] ?? null );
	$this->assertSame( 'openai', $data['connectorApproval']['connectorId'] ?? null );
	$this->assertSame( 'flavor-agent/flavor-agent.php', $data['connectorApproval']['callerBasename'] ?? null );
	$this->assertStringContainsString(
		'tools.php?page=ai-connector-approval',
		$data['connectorApproval']['adminUrl'] ?? ''
	);
}
```

Run:

```bash
composer run test:php -- --filter WordPressAIClientTest::test_chat_preserves_connector_approval_error_data
```

Expected before implementation: FAIL because `connectorApproval` metadata is absent.

- [ ] **Step 2: Add throwable-wrapper regression**

Extend `tests/phpunit/bootstrap.php` with a nullable throwable hook for the fake prompt builder:

```php
public static ?\Throwable $ai_client_generate_text_throws = null;
```

Reset it in `WordPressTestState::reset()` and throw it inside the fake `generate_text` / `generate_text_result` branch before reading `$ai_client_generate_text_result`.

Add the test:

```php
public function test_chat_recovers_connector_approval_details_from_wrapped_throwable(): void {
	WordPressTestState::$ai_client_supported             = true;
	WordPressTestState::$ai_client_generate_text_throws  = new \RuntimeException(
		'The "openai" AI connector has not been approved for use by "flavor-agent/flavor-agent.php".'
	);

	$result = WordPressAIClient::chat(
		'WordPress Gutenberg block styling and configuration assistant.',
		'Recommend a better block.'
	);

	$this->assertInstanceOf( \WP_Error::class, $result );
	$this->assertSame( 'wpai_connector_not_approved', $result->get_error_code() );
	$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	$this->assertSame( 'openai', $result->get_error_data()['connectorApproval']['connectorId'] ?? null );
	$this->assertSame(
		'flavor-agent/flavor-agent.php',
		$result->get_error_data()['connectorApproval']['callerBasename'] ?? null
	);
}
```

Expected before implementation: FAIL because current `call_prompt_method()` wraps all throwables as `wp_ai_client_request_failed`.

- [ ] **Step 3: Implement PHP normalization**

In `WordPressAIClient`, add:

```php
private const CONNECTOR_NOT_APPROVED_CODE = 'wpai_connector_not_approved';

public static function is_connector_approval_error( \WP_Error $error ): bool {
	if ( self::CONNECTOR_NOT_APPROVED_CODE === $error->get_error_code() ) {
		return true;
	}

	$data = $error->get_error_data();
	return is_array( $data )
		&& 403 === (int) ( $data['status'] ?? 0 )
		&& is_string( $data['connector_id'] ?? null )
		&& '' !== $data['connector_id']
		&& is_array( $data['caller'] ?? null )
		&& is_string( $data['caller']['basename'] ?? null )
		&& '' !== $data['caller']['basename'];
}

public static function connector_approval_admin_url(): string {
	$url = admin_url( 'tools.php?page=ai-connector-approval' );

	return (string) apply_filters(
		'flavor_agent_connector_approval_admin_url',
		$url
	);
}
```

Add a private helper that returns original error data plus a normalized `connectorApproval` payload:

```php
private static function connector_approval_error_data( \WP_Error $error ): array {
	$data   = $error->get_error_data();
	$data   = is_array( $data ) ? $data : [];
	$caller = is_array( $data['caller'] ?? null ) ? $data['caller'] : [];
	$admin_url = current_user_can( 'manage_options' )
		? self::connector_approval_admin_url()
		: '';

	$data['status']              = 403;
	$data['connectorApproval']   = [
		'code'           => self::CONNECTOR_NOT_APPROVED_CODE,
		'connectorId'    => (string) ( $data['connector_id'] ?? '' ),
		'callerBasename' => (string) ( $caller['basename'] ?? '' ),
		'callerName'     => (string) ( $caller['name'] ?? 'Flavor Agent' ),
		'adminUrl'       => $admin_url,
	];

	return $data;
}
```

Add a non-admin regression in `WordPressAIClientTest`: set `WordPressTestState::$capabilities['manage_options'] = false`, return the canonical upstream approval error, and assert `connectorApproval.adminUrl` is `''`. Keep the admin-path test asserting the default URL.

Update `normalize_ai_client_error()` so connector approval errors are rebuilt even when the message did not change:

```php
if ( self::is_connector_approval_error( $error ) ) {
	return new \WP_Error(
		self::CONNECTOR_NOT_APPROVED_CODE,
		$normalized_message,
		self::connector_approval_error_data( $error )
	);
}
```

Add a private `connector_approval_error_from_throwable()` that matches only the upstream sentence:

```php
private static function connector_approval_error_from_throwable( \Throwable $throwable ): ?\WP_Error {
	$message = self::normalize_ai_client_error_message( $throwable->getMessage() );

	if (
		1 !== preg_match(
			'/^The "([^"]+)" AI connector has not been approved for use by "([^"]+)".$/',
			$message,
			$matches
		)
	) {
		return null;
	}

	$caller_basename = (string) $matches[2];

	return new \WP_Error(
		self::CONNECTOR_NOT_APPROVED_CODE,
		$message,
		[
			'status'       => 403,
			'connector_id' => (string) $matches[1],
			'caller'       => [
				'type'     => str_contains( $caller_basename, '/' ) ? 'plugin' : '',
				'basename' => $caller_basename,
				'name'     => 'flavor-agent/flavor-agent.php' === $caller_basename ? 'Flavor Agent' : $caller_basename,
			],
		]
	);
}
```

In `call_prompt_method()` throwable handling, check this before the generic `wp_ai_client_request_failed` wrapper:

```php
$approval_error = self::connector_approval_error_from_throwable( $throwable );
if ( $approval_error ) {
	return self::normalize_ai_client_error( $approval_error );
}
```

- [ ] **Step 4: Add URL helper regression**

Add a test that asserts the default URL and filter override:

```php
public function test_connector_approval_admin_url_uses_default_and_filter_override(): void {
	$this->assertStringContainsString(
		'tools.php?page=ai-connector-approval',
		WordPressAIClient::connector_approval_admin_url()
	);

	add_filter(
		'flavor_agent_connector_approval_admin_url',
		static fn (): string => 'https://example.test/wp-admin/tools.php?page=custom-approvals'
	);

	$this->assertSame(
		'https://example.test/wp-admin/tools.php?page=custom-approvals',
		WordPressAIClient::connector_approval_admin_url()
	);
}
```

Run:

```bash
composer run test:php -- --filter WordPressAIClientTest
```

Expected: PASS.

---

## Task 3: Expose Connector Approval URL To The Editor

**Files:**
- Modify: `flavor-agent.php`
- Test: `tests/phpunit/EditorSurfaceCapabilitiesTest.php` or a new bootstrap-data test in the same suite

- [ ] **Step 1: Add failing bootstrap-data test**

Add admin and non-admin tests that call `flavor_agent_get_editor_bootstrap_data()`.

For an admin user:

```php
WordPressTestState::$capabilities['manage_options'] = true;

$data = flavor_agent_get_editor_bootstrap_data(
	'https://example.test/wp-admin/options-general.php?page=flavor-agent',
	'https://example.test/wp-admin/options-connectors.php'
);

$this->assertArrayHasKey( 'connectorApprovalUrl', $data );
$this->assertStringContainsString(
	'tools.php?page=ai-connector-approval',
	$data['connectorApprovalUrl']
);
```

For a non-admin user:

```php
WordPressTestState::$capabilities['manage_options'] = false;

$data = flavor_agent_get_editor_bootstrap_data(
	'https://example.test/wp-admin/options-general.php?page=flavor-agent',
	'https://example.test/wp-admin/options-connectors.php'
);

$this->assertArrayHasKey( 'connectorApprovalUrl', $data );
$this->assertSame( '', $data['connectorApprovalUrl'] );
```

Expected before implementation: FAIL.

- [ ] **Step 2: Add bootstrap key**

In `flavor_agent_get_editor_bootstrap_data()`, add:

```php
'connectorApprovalUrl' => $can_manage_settings
	? FlavorAgent\LLM\WordPressAIClient::connector_approval_admin_url()
	: '',
```

Keep `connectorsUrl` unchanged. `connectorsUrl` means no usable connector is configured; `connectorApprovalUrl` means a configured connector denied this caller and the current user can manage approvals. Non-admin editors still receive the key for stable bootstrap shape, but its value must be an empty string.

- [ ] **Step 3: Run focused PHP test**

Run:

```bash
composer run test:php -- --filter EditorSurfaceCapabilitiesTest
```

Expected: PASS.

---

## Task 4: Preserve Request Error Details In The JS Store

**Files:**
- Create: `src/store/request-error-details.js`
- Modify: `src/store/index.js`
- Modify: `src/store/executable-surfaces.js`
- Modify: `src/store/executable-surface-runtime.js`
- Test: `src/store/__tests__/request-error-details.test.js`
- Test: `src/store/__tests__/store-actions.test.js`
- Test: `src/store/__tests__/executable-surfaces.test.js`

- [ ] **Step 1: Write normalization tests**

Create `src/store/__tests__/request-error-details.test.js`:

```js
import {
	CONNECTOR_NOT_APPROVED_CODE,
	normalizeRequestErrorDetails,
} from '../request-error-details';

describe( 'normalizeRequestErrorDetails', () => {
	test( 'normalizes canonical connector approval REST errors', () => {
		const details = normalizeRequestErrorDetails( {
			code: CONNECTOR_NOT_APPROVED_CODE,
			message:
				'The "openai" AI connector has not been approved for use by "flavor-agent/flavor-agent.php".',
			data: {
				status: 403,
				connector_id: 'openai',
				caller: {
					basename: 'flavor-agent/flavor-agent.php',
					name: 'Flavor Agent',
				},
				connectorApproval: {
					connectorId: 'openai',
					callerBasename: 'flavor-agent/flavor-agent.php',
					callerName: 'Flavor Agent',
					adminUrl:
						'https://example.test/wp-admin/tools.php?page=ai-connector-approval',
				},
			},
		} );

		expect( details ).toMatchObject( {
			code: CONNECTOR_NOT_APPROVED_CODE,
			message:
				'The "openai" AI connector has not been approved for use by "flavor-agent/flavor-agent.php".',
			connectorApproval: {
				connectorId: 'openai',
				callerBasename: 'flavor-agent/flavor-agent.php',
				callerName: 'Flavor Agent',
				adminUrl:
					'https://example.test/wp-admin/tools.php?page=ai-connector-approval',
			},
		} );
	} );

	test( 'falls back to parsing the upstream message when structured details are missing', () => {
		const details = normalizeRequestErrorDetails( {
			code: 'wp_ai_client_request_failed',
			message:
				'The "openai" AI connector has not been approved for use by "flavor-agent/flavor-agent.php".',
			data: { status: 500 },
		} );

		expect( details.connectorApproval ).toMatchObject( {
			connectorId: 'openai',
			callerBasename: 'flavor-agent/flavor-agent.php',
		} );
	} );
} );
```

Expected before implementation: FAIL.

- [ ] **Step 2: Implement `request-error-details.js`**

Implement a pure helper:

```js
export const CONNECTOR_NOT_APPROVED_CODE = 'wpai_connector_not_approved';

function normalizeString( value ) {
	return typeof value === 'string' && value.trim() ? value.trim() : '';
}

function parseConnectorApprovalMessage( message ) {
	const match = normalizeString( message ).match(
		/^The "([^"]+)" AI connector has not been approved for use by "([^"]+)".$/
	);

	return match
		? { connectorId: match[ 1 ], callerBasename: match[ 2 ] }
		: null;
}

export function normalizeRequestErrorDetails( error = null ) {
	const data = error && typeof error.data === 'object' ? error.data : {};
	const direct =
		data.connectorApproval && typeof data.connectorApproval === 'object'
			? data.connectorApproval
			: {};
	const parsed = parseConnectorApprovalMessage( error?.message ) || {};
	const connectorId = normalizeString(
		direct.connectorId || data.connector_id || parsed.connectorId
	);
	const caller = data.caller && typeof data.caller === 'object' ? data.caller : {};
	const callerBasename = normalizeString(
		direct.callerBasename || caller.basename || parsed.callerBasename
	);
	const adminUrl = normalizeString(
		direct.adminUrl ||
			( typeof window !== 'undefined'
				? window.flavorAgentData?.connectorApprovalUrl
				: '' )
	);
	const code = normalizeString( error?.code || data.code );
	const message = normalizeString( error?.message );
	const connectorApproval =
		connectorId && callerBasename
			? {
					code: CONNECTOR_NOT_APPROVED_CODE,
					connectorId,
					callerBasename,
					callerName: normalizeString(
						direct.callerName || caller.name || 'Flavor Agent'
					),
					adminUrl,
			  }
			: null;

	return {
		code: connectorApproval ? CONNECTOR_NOT_APPROVED_CODE : code,
		message,
		data,
		connectorApproval,
	};
}
```

- [ ] **Step 3: Add store state for details without replacing string errors**

Preserve existing string selectors like `getContentError()` and `getPatternError()`. Add parallel detail state:

```js
contentErrorDetails: null,
patternErrorDetails: null,
navigationErrorDetails: null,
```

For blocks, extend `DEFAULT_BLOCK_REQUEST_STATE`:

```js
errorDetails: null,
```

For generic executable surfaces, add each `errorDetailsKey` in `EXECUTABLE_SURFACE_DEFS`, for example:

```js
errorDetailsKey: 'templateErrorDetails',
```

and default it to `null`.

- [ ] **Step 4: Pass details through status actions**

Update status action creators so they accept a fourth `errorDetails = null` argument and keep the existing `error` string:

```js
setContentStatus( status, error = null, requestToken = null, errorDetails = null ) {
	return { type: 'SET_CONTENT_STATUS', status, error, requestToken, errorDetails };
}
```

Apply the same pattern to pattern, navigation, block request state, and executable surfaces. On `loading`, `ready`, clear, and successful recommendations, reset `*ErrorDetails` to `null`.

- [ ] **Step 5: Normalize errors at each request failure site**

In each `onError` handler, call `normalizeRequestErrorDetails( err )` once and pass both the existing string message and the details object:

```js
const errorDetails = normalizeRequestErrorDetails( err );
localDispatch(
	setErrorState(
		errorDetails.message || requestErrorMessage,
		requestToken,
		errorDetails
	)
);
```

For block diagnostics, include `connectorApproval` in `buildBlockRecommendationFailureDiagnostics()` so AI Activity can show the denied connector if needed:

```js
connectorApproval: errorDetails.connectorApproval || null,
```

- [ ] **Step 6: Add selectors**

Add selectors:

```js
getBlockErrorDetails: ( state, clientId ) =>
	getStoredBlockRequestState( state, clientId ).errorDetails,
getContentErrorDetails: ( state ) => state.contentErrorDetails,
getPatternErrorDetails: ( state ) => state.patternErrorDetails,
getNavigationErrorDetails: ( state, blockClientId = null ) =>
	blockClientId && state.navigationBlockClientId !== blockClientId
		? null
		: state.navigationErrorDetails,
```

Generic executable surfaces should get generated selectors from `errorDetailsKey`.

- [ ] **Step 7: Run focused JS store tests**

Run:

```bash
npx wp-scripts test-unit-js --runTestsByPath src/store/__tests__/request-error-details.test.js src/store/__tests__/store-actions.test.js src/store/__tests__/executable-surfaces.test.js
```

Expected: PASS.

---

## Task 5: Render Dedicated Request-Time Capability Notice

**Files:**
- Modify: `src/utils/capability-flags.js`
- Modify: `src/components/CapabilityNotice.js`
- Modify: `src/content/ContentRecommender.js`
- Modify: `src/inspector/BlockRecommendationsPanel.js`
- Modify: `src/inspector/NavigationRecommendations.js`
- Modify: `src/patterns/PatternRecommender.js`
- Modify: `src/templates/TemplateRecommender.js`
- Modify: `src/template-parts/TemplatePartRecommender.js`
- Modify: `src/global-styles/GlobalStylesRecommender.js`
- Modify: `src/style-book/StyleBookRecommender.js`
- Test: `src/utils/__tests__/capability-flags.test.js`
- Test: component tests for each modified surface

- [ ] **Step 1: Add notice-builder tests**

Extend `src/utils/__tests__/capability-flags.test.js`:

```js
import {
	getConnectorApprovalNotice,
	getCapabilityNotice,
} from '../capability-flags';

test( 'builds a request-time connector approval notice while the surface is configured', () => {
	window.flavorAgentData = {
		canRecommendContent: true,
		canManageFlavorAgentSettings: true,
		connectorApprovalUrl:
			'https://example.test/wp-admin/tools.php?page=ai-connector-approval',
		capabilities: {
			surfaces: {
				content: { available: true, reason: 'ready', actions: [] },
			},
		},
	};

	const notice = getConnectorApprovalNotice( 'content', {
		connectorApproval: {
			connectorId: 'openai',
			callerBasename: 'flavor-agent/flavor-agent.php',
			callerName: 'Flavor Agent',
			adminUrl:
				'https://example.test/wp-admin/tools.php?page=ai-connector-approval',
		},
	} );

	expect( getCapabilityNotice( 'content' ) ).toBeNull();
	expect( notice ).toMatchObject( {
		status: 'warning',
		message:
			'Flavor Agent needs administrator approval to use the openai connector. An approval request for flavor-agent/flavor-agent.php has been submitted.',
		actions: [
			{
				label: 'Open approvals page',
				href:
					'https://example.test/wp-admin/tools.php?page=ai-connector-approval',
			},
		],
	} );
} );

test( 'builds a non-admin connector approval notice without an admin link', () => {
	window.flavorAgentData = {
		canRecommendContent: true,
		canManageFlavorAgentSettings: false,
		connectorApprovalUrl: '',
		capabilities: {
			surfaces: {
				content: { available: true, reason: 'ready', actions: [] },
			},
		},
	};

	const notice = getConnectorApprovalNotice( 'content', {
		connectorApproval: {
			connectorId: 'openai',
			callerBasename: 'flavor-agent/flavor-agent.php',
			callerName: 'Flavor Agent',
			adminUrl:
				'https://example.test/wp-admin/tools.php?page=ai-connector-approval',
		},
	} );

	expect( notice ).toMatchObject( {
		status: 'warning',
		message:
			'Flavor Agent needs administrator approval to use the openai connector. An approval request for flavor-agent/flavor-agent.php has been submitted. Ask an administrator to review it in Connector Approvals.',
		actions: [],
		actionHref: '',
	} );
} );
```

Expected before implementation: FAIL.

- [ ] **Step 2: Implement `getConnectorApprovalNotice()`**

In `src/utils/capability-flags.js`, export:

```js
export function getConnectorApprovalNotice( surface, errorDetails = null, input = null ) {
	const data = getFlavorAgentData( input );
	const approval = errorDetails?.connectorApproval || null;

	if ( ! approval?.connectorId || ! approval?.callerBasename ) {
		return null;
	}

	const canManageApprovals = data.canManageFlavorAgentSettings !== false;
	const href = canManageApprovals
		? normalizeString( approval.adminUrl || data.connectorApprovalUrl )
		: '';
	const message = canManageApprovals
		? sprintf(
				__(
					'Flavor Agent needs administrator approval to use the %1$s connector. An approval request for %2$s has been submitted.',
					'flavor-agent'
				),
				approval.connectorId,
				approval.callerBasename
		  )
		: sprintf(
				__(
					'Flavor Agent needs administrator approval to use the %1$s connector. An approval request for %2$s has been submitted. Ask an administrator to review it in Connector Approvals.',
					'flavor-agent'
				),
				approval.connectorId,
				approval.callerBasename
		  );

	return {
		surface,
		available: false,
		reason: 'connector_not_approved',
		status: 'warning',
		message,
		actionLabel: href ? __( 'Open approvals page', 'flavor-agent' ) : '',
		actionHref: href,
		actions: href
			? [
					{
						label: __( 'Open approvals page', 'flavor-agent' ),
						href,
					},
			  ]
			: [],
	};
}
```

Import `sprintf` from `@wordpress/i18n`, add or reuse a local `normalizeString()` helper, and update the existing i18n mock tests accordingly. The frontend guard must ignore `approval.adminUrl` when `canManageFlavorAgentSettings === false`, even if a stale backend payload accidentally includes an admin URL.

- [ ] **Step 3: Let `CapabilityNotice` render an explicit notice**

Update `src/components/CapabilityNotice.js`:

```js
export default function CapabilityNotice( { surface, data = null, notice = null } ) {
	const resolvedNotice = notice || getCapabilityNotice( surface, data );

	if ( ! resolvedNotice ) {
		return null;
	}

	// Existing rendering path uses resolvedNotice instead of notice.
}
```

Add a component test that passes a real `notice` object and asserts the message and link render without mocking `getCapabilityNotice()`.

- [ ] **Step 4: Suppress generic request notice for connector approval**

In `getSurfaceStatusNotice()`, if `options.requestErrorDetails?.connectorApproval` is present, return `null` for the request-error branch:

```js
const connectorApproval = options.requestErrorDetails?.connectorApproval || null;
if ( connectorApproval ) {
	return null;
}
```

Then continue with the existing string error branch for all other request errors.

- [ ] **Step 5: Render per-surface connector approval notices**

For each chat-backed surface, select the matching `*ErrorDetails`, compute:

```js
const connectorApprovalNotice = useMemo(
	() => getConnectorApprovalNotice( 'content', contentErrorDetails ),
	[ contentErrorDetails ]
);
```

Render:

```jsx
{ connectorApprovalNotice && (
	<CapabilityNotice surface="content" notice={ connectorApprovalNotice } />
) }
<AIStatusNotice
	notice={ connectorApprovalNotice ? null : statusNotice }
	onDismiss={ clearContentError }
/>
```

Apply the same pattern to:

- `block`
- `content`
- `navigation`
- `template`
- `template-part`
- `global-styles`
- `style-book`

For `pattern`, render the connector approval notice in the inserter notice slot before the generic `PatternInserterNotice status="error"` branch.

- [ ] **Step 6: Add surface regressions**

Add or extend tests to cover at least:

- `src/content/__tests__/ContentRecommender.test.js`: admin content renders "Open approvals page" and does not render the generic request error.
- `src/content/__tests__/ContentRecommender.test.js` or `src/utils/__tests__/capability-flags.test.js`: non-admin content renders the administrator handoff copy, no "Open approvals page" action, and no generic request error.
- `src/inspector/__tests__/BlockRecommendationsPanel.test.js`: block renders the request-time `CapabilityNotice` while `canRecommendBlocks` is true.
- `src/patterns/__tests__/PatternRecommender.test.js`: pattern inserter slot renders the approval notice instead of the generic error notice.
- `src/templates/__tests__/TemplateRecommender.test.js`: one generated executable-surface path proves the shared state wiring works.

Run:

```bash
npx wp-scripts test-unit-js --runTestsByPath \
  src/utils/__tests__/capability-flags.test.js \
  src/components/__tests__/CapabilityNotice.test.js \
  src/content/__tests__/ContentRecommender.test.js \
  src/inspector/__tests__/BlockRecommendationsPanel.test.js \
  src/patterns/__tests__/PatternRecommender.test.js \
  src/templates/__tests__/TemplateRecommender.test.js
```

Expected: PASS.

---

## Task 6: Docs Updates

**Files:**
- Modify: `docs/SOURCE_OF_TRUTH.md`
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`
- Modify: `docs/reference/wordpress-ai-roadmap-tracking.md`
- Modify if contracts change: `docs/reference/abilities-and-routes.md`
- Test: docs check

- [ ] **Step 1: Update source-of-truth docs**

Add a short runtime dependency note:

```markdown
When the WordPress AI plugin Connector Approval experiment is enabled, Flavor Agent chat-backed recommendation surfaces require administrator approval for the selected connector. A first denied request is expected to create a pending approval entry in the AI plugin. Flavor Agent surfaces that denial as a request-time editor notice; administrators get a link to `Tools > Connector Approvals`, while non-admin editors are told to ask an administrator to review the pending request.
```

- [ ] **Step 2: Update feature matrix**

For all chat-backed surfaces, add a shared dependency note:

```markdown
Connector Approval can gate this surface even when the connector is configured. The denial is surfaced as a request-time setup notice, not as an unavailable bootstrap capability.
```

- [ ] **Step 3: Update roadmap tracking**

Add `WordPress/ai#467` as integrated with a caveat:

```markdown
`WordPress/ai#467` introduced the Connector Approval experiment in AI plugin 1.0.0. Flavor Agent integration depends on the caller-attribution behavior from `WordPress/ai#595` or equivalent before final runtime smoke can verify `flavor-agent/flavor-agent.php` pending approvals.
```

- [ ] **Step 4: Run docs check**

Run:

```bash
npm run check:docs
```

Expected: PASS.

---

## Task 7: Full Verification And Manual Approval Smoke

**Files:**
- Modify: `docs/validation/2026-05-21-connector-approvals-smoke.md`

- [ ] **Step 1: Run focused PHP and JS tests**

Run:

```bash
composer run test:php -- --filter WordPressAIClientTest
npx wp-scripts test-unit-js --runTestsByPath \
  src/store/__tests__/request-error-details.test.js \
  src/store/__tests__/store-actions.test.js \
  src/store/__tests__/executable-surfaces.test.js \
  src/utils/__tests__/capability-flags.test.js \
  src/components/__tests__/CapabilityNotice.test.js
```

Expected: PASS.

- [ ] **Step 2: Run aggregate non-E2E verification**

Run:

```bash
node scripts/verify.js --skip-e2e
```

Expected: `VERIFY_RESULT={"status":"pass",...}`.

- [ ] **Step 3: Run manual Connector Approval smoke**

In the local WordPress stack:

1. Enable the `connector-approval` experiment in `Settings > AI`.
2. Remove Flavor Agent approval for the selected connector.
3. Reload the editor.
4. Trigger one recommendation request.
5. As an admin-capable user, confirm the editor shows a `CapabilityNotice` that includes the connector id, caller basename, and "Open approvals page".
6. Confirm the generic request error notice is absent.
7. Inspect `wpai_connector_approval_pending`.
8. Confirm the pending option uses the key shape `flavor-agent/flavor-agent.php::openai` and carries `caller_type`, `caller_basename`, `caller_name`, `connector_id`, `attempts`, `first_seen`, and `last_seen`.
9. As a non-admin editor, trigger the same denied request and confirm the editor shows the administrator handoff copy with no "Open approvals page" action.
10. Approve Flavor Agent under `Tools > Connector Approvals`.
11. Trigger the same recommendation request again.
12. Confirm the request succeeds.

Record the exact pending option and outcome in `docs/validation/2026-05-21-connector-approvals-smoke.md`.

If the pending option records a caller other than `flavor-agent/flavor-agent.php`, record the smoke as blocked on upstream caller attribution and do not mark success criteria 3-5 complete.

---

## Risks And Mitigations

1. **Upstream caller attribution drift.** AI plugin 1.0.0 can attribute denials to the AI plugin or provider connector. Mitigation: mandatory smoke/version gate before claiming approval works.
2. **SDK error wrapping.** Direct `WP_Error` propagation is not guaranteed. Mitigation: preserve direct data and parse only the exact upstream denial message from wrapped throwables.
3. **False-positive connector approval parsing.** Any broad `connector_id` check could misclassify unrelated errors. Mitigation: require code match, or `status=403` plus connector id and caller basename, or exact upstream sentence match.
4. **Duplicate notices.** Existing surfaces already render request errors. Mitigation: `getSurfaceStatusNotice()` returns `null` for request errors that have `connectorApproval` metadata, while `CapabilityNotice` renders the CTA.
5. **URL slug stability.** Current slug is `tools.php?page=ai-connector-approval`. Mitigation: PHP helper exposes `flavor_agent_connector_approval_admin_url` filter and JS falls back to `flavorAgentData.connectorApprovalUrl`.
6. **Non-admin dead links.** The approvals page requires `manage_options`. Mitigation: `connectorApprovalUrl` is empty for non-admin bootstrap data, PHP connector-approval error data omits `adminUrl` for non-admin users, and JS refuses to render an approval-page action when `canManageFlavorAgentSettings === false`.

## Out Of Scope

- Programmatic approval or rejection from Flavor Agent.
- Reading or modifying the AI plugin `Approvals_Store` during editor bootstrap.
- Per-surface approval granularity. Upstream approval is per caller and connector.
- Changing the existing `prompt_prevented` UX.
