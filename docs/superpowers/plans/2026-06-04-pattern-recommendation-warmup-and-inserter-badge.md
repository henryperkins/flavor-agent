# Pattern Recommendation Warm-Up and Inserter Badge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move pattern ranking from passive editor load to inserter intent, add target-scoped ranking cache with runtime-signature freshness, preserve no-model diagnostics accurately, and anchor the inserter badge only to the real block inserter button.

**Architecture:** Implement the server contract first so the client can rely on stable schemas, no-model markers, and a read-only `patternRuntimeSignature` from bootstrap/check-status. Then add store-level pattern cache hydration with monotonic request-token protection, wire `PatternRecommender` to use inserter intent and cache keys, and finish with precise badge DOM matching plus browser evidence. Spec: `docs/superpowers/specs/2026-06-04-pattern-recommendation-warmup-and-inserter-badge-design.md`.

**Tech Stack:** WordPress plugin PHP, WordPress Abilities API, Gutenberg data stores, React via `@wordpress/element`, Jest via `@wordpress/scripts`, PHPUnit, Playwright.

---

## File Map

- Modify: `inc/Abilities/PatternAbilities.php`
  - Owns `requestPurpose`, no-model response markers, and the canonical pattern runtime signature helper.
- Modify: `inc/Abilities/Registration.php`
  - Keeps `recommend-patterns` input/output schemas and `check-status` output schema aligned with the new contract.
- Modify: `inc/Abilities/RecommendationAbilityExecution.php`
  - Persists allow-listed `diagnostics.modelRequest` to `request_diagnostic.after.modelRequest`.
- Modify: `inc/Abilities/SurfaceCapabilities.php`
  - Exposes `patternRuntimeSignature` on the pattern surface without calling `recommend-patterns`.
- Modify: `flavor-agent.php`
  - No direct logic change expected beyond existing bootstrap consuming `SurfaceCapabilities::build()`. Keep in the commit scope if tests require a small bootstrap assertion update.
- Modify: `src/store/index.js`
  - Adds pattern ranking cache state/actions/selectors, `patternRuntimeSignature` state, cache hydration, and `diagnostics.modelRequest` normalization.
- Modify: `src/utils/capability-flags.js`
  - Preserves `patternRuntimeSignature` on `getSurfaceCapability( 'pattern' )`.
- Modify: `src/patterns/PatternRecommender.js`
  - Gates ranking behind inserter intent, builds all live ranking/apply input through one input builder, and uses the store cache.
- Modify: `src/patterns/inserter-dom.js`
  - Tightens positive block-inserter matching and explicit deny-list behavior.
- Modify: `src/patterns/InserterBadge.js`
  - Creates/reuses a dedicated adjacent badge anchor instead of portaling into `button.parentElement`.
- Modify: `src/admin/activity-log-utils.js`
  - Normalizes persisted no-model markers to `entry.modelRequest`.
- Modify: `src/admin/activity-log.js`
  - Shows no-model copy before token/log-id unavailable copy.
- Modify: `docs/features/pattern-recommendations.md`
  - Documents warm-up versus inserter-triggered real ranking and target-scoped cache freshness.
- Test: `tests/phpunit/PatternAbilitiesTest.php`
- Test: `tests/phpunit/RegistrationTest.php`
- Test: `tests/phpunit/RecommendationAbilityExecutionTest.php`
- Test: `tests/phpunit/EditorSurfaceCapabilitiesTest.php`
- Test: `tests/phpunit/InfraAbilitiesTest.php`
- Test: `src/store/__tests__/store-actions.test.js`
- Test: `src/utils/__tests__/capability-flags.test.js`
- Test: `src/patterns/__tests__/PatternRecommender.test.js`
- Test: `src/patterns/__tests__/inserter-dom.test.js`
- Test: `src/patterns/__tests__/compat.test.js`
- Test: `src/patterns/__tests__/InserterBadge.test.js`
- Test: `src/admin/__tests__/activity-log-utils.test.js`
- Test: `src/admin/__tests__/activity-log.test.js`
- Test: `tests/e2e/flavor-agent.smoke.spec.js`

## Setup

- [ ] **Step 1: Confirm the worktree state**

Run:

```bash
git status --short
git diff --check
```

Expected: note any pre-existing dirty files. `git diff --check` must not show whitespace errors in files this plan will touch.

- [ ] **Step 2: Run the current targeted baseline**

Run:

```bash
npm run test:unit -- src/patterns/__tests__/PatternRecommender.test.js src/store/__tests__/store-actions.test.js src/patterns/__tests__/inserter-dom.test.js src/patterns/__tests__/InserterBadge.test.js src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js --runInBand
composer run test:php -- --filter 'PatternAbilitiesTest|RegistrationTest|RecommendationAbilityExecutionTest|EditorSurfaceCapabilitiesTest|InfraAbilitiesTest'
```

Expected: current baseline is green before feature work. If a test is already red, capture the exact failing test name and do not reinterpret it as caused by this plan.

## Task 1: Server Pattern Runtime Signature and Schemas

**Files:**
- Modify: `inc/Abilities/PatternAbilities.php`
- Modify: `inc/Abilities/Registration.php`
- Modify: `inc/Abilities/SurfaceCapabilities.php`
- Test: `tests/phpunit/PatternAbilitiesTest.php`
- Test: `tests/phpunit/RegistrationTest.php`
- Test: `tests/phpunit/EditorSurfaceCapabilitiesTest.php`
- Test: `tests/phpunit/InfraAbilitiesTest.php`

- [ ] **Step 1: Write failing PHP tests for runtime signature exposure**

Add these test cases.

In `tests/phpunit/PatternAbilitiesTest.php`, near the existing signature tests:

```php
public function test_recommend_patterns_returns_pattern_runtime_signature_for_signature_only_runtime_state(): void {
	$this->save_index_state(
		[
			'fingerprint'          => 'catalog-fingerprint-runtime-a',
			'pattern_fingerprints' => [
				'theme/hero' => 'pattern-fingerprint-a',
			],
		]
	);

	$result = PatternAbilities::recommend_patterns(
		[
			'postType'             => 'page',
			'visiblePatternNames'  => [ 'theme/hero' ],
			'resolveSignatureOnly' => true,
		]
	);

	$this->assertIsArray( $result );
	$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) ( $result['patternRuntimeSignature'] ?? '' ) );

	$this->save_index_state(
		[
			'fingerprint'          => 'catalog-fingerprint-runtime-b',
			'pattern_fingerprints' => [
				'theme/hero' => 'pattern-fingerprint-b',
			],
		]
	);

	$changed = PatternAbilities::recommend_patterns(
		[
			'postType'             => 'page',
			'visiblePatternNames'  => [ 'theme/hero' ],
			'resolveSignatureOnly' => true,
		]
	);

	$this->assertNotSame(
		$result['patternRuntimeSignature'] ?? null,
		$changed['patternRuntimeSignature'] ?? null
	);
}

public function test_current_pattern_runtime_signature_is_blank_before_runtime_state_is_usable(): void {
	PatternIndex::save_state(
		array_merge(
			PatternIndex::get_state(),
			[
				'status'         => 'uninitialized',
				'last_synced_at' => '',
				'fingerprint'    => '',
			]
		)
	);

	$this->assertSame( '', PatternAbilities::current_pattern_runtime_signature() );
}
```

In `tests/phpunit/RegistrationTest.php`, extend the existing `recommend-patterns` schema test or add:

```php
public function test_recommend_patterns_schema_exposes_request_purpose_model_request_and_runtime_signature(): void {
	Registration::register();

	$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-patterns'] ?? null;
	$this->assertIsArray( $ability );

	$input_properties = $ability['input_schema']['properties'] ?? [];
	$this->assertArrayHasKey( 'requestPurpose', $input_properties );
	$this->assertSame( 'string', $input_properties['requestPurpose']['type'] ?? null );
	$this->assertTrue( $ability['input_schema']['additionalProperties'] ?? false );

	$output_properties = $ability['output_schema']['properties'] ?? [];
	$this->assertArrayHasKey( 'patternRuntimeSignature', $output_properties );
	$this->assertSame( 'string', $output_properties['patternRuntimeSignature']['type'] ?? null );
	$this->assertArrayHasKey( 'modelRequest', $output_properties['diagnostics']['properties'] ?? [] );
}
```

In `tests/phpunit/EditorSurfaceCapabilitiesTest.php`, add:

```php
public function test_pattern_surface_exposes_runtime_signature_when_index_is_usable(): void {
	WordPressTestState::$capabilities = [
		'edit_theme_options' => true,
		'manage_options'     => true,
	];
	WordPressTestState::$options      = [
		'flavor_agent_openai_provider'                 => 'cloudflare_workers_ai',
		'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
		'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
		'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
		'flavor_agent_qdrant_url'                      => 'https://example.cloud.qdrant.io:6333',
		'flavor_agent_qdrant_key'                      => 'qdrant-key',
		'connectors_ai_openai_api_key'                 => 'connector-key',
		'wpai_features_enabled'                        => true,
		'wpai_feature_flavor-agent_enabled'            => true,
	];
	WordPressTestState::$connectors   = [
		'openai' => [
			'name'           => 'OpenAI',
			'description'    => 'OpenAI connector',
			'type'           => 'ai_provider',
			'authentication' => [
				'method'       => 'api_key',
				'setting_name' => 'connectors_ai_openai_api_key',
			],
		],
	];
	WordPressTestState::$ai_client_supported = true;

	PatternIndex::save_state(
		array_merge(
			PatternIndex::get_state(),
			[
				'status'         => 'ready',
				'fingerprint'    => 'fingerprint-123',
				'last_synced_at' => '2026-06-04T00:00:00+00:00',
				'indexed_count'  => 3,
			]
		)
	);

	$capabilities = \flavor_agent_get_editor_surface_capabilities(
		'https://example.test/wp-admin/options-general.php?page=flavor-agent',
		'https://example.test/wp-admin/options-connectors.php'
	);

	$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $capabilities['pattern']['patternRuntimeSignature'] ?? '' );
}
```

In `tests/phpunit/InfraAbilitiesTest.php`, add the same assertion against `$status['surfaces']['pattern']['patternRuntimeSignature']` inside a configured-pattern status case.

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
composer run test:php -- --filter 'PatternAbilitiesTest|RegistrationTest|EditorSurfaceCapabilitiesTest|InfraAbilitiesTest'
```

Expected: failures mention missing `current_pattern_runtime_signature()`, missing `patternRuntimeSignature`, and missing `requestPurpose` schema.

- [ ] **Step 3: Implement the runtime signature helper**

In `inc/Abilities/PatternAbilities.php`, add constants near the top of the class:

```php
private const PATTERN_REQUEST_PURPOSE_INSERTER_RANKING = 'inserter_ranking';
private const PATTERN_MODEL_REQUEST_NO_RANKABLE_CANDIDATES = 'no_rankable_candidates';
private const PATTERN_MODEL_REQUEST_MISSING_VISIBLE_PATTERNS = 'missing_visible_patterns';
```

Add these helpers near `build_pattern_catalog_signature_context()`:

```php
public static function current_pattern_runtime_signature(): string {
	$state = PatternIndex::get_runtime_state();

	return self::pattern_runtime_signature_from_state( $state );
}

private static function pattern_runtime_signature_from_state( array $state ): string {
	if ( ! PatternIndex::has_usable_index( $state ) ) {
		return '';
	}

	return sanitize_text_field(
		RecommendationResolvedSignature::from_payload(
			'pattern-runtime',
			self::build_pattern_catalog_signature_context( $state )
		)
	);
}
```

After `$state = PatternIndex::get_runtime_state();` in `recommend_patterns()`, define:

```php
$pattern_runtime_signature = self::pattern_runtime_signature_from_state( $state );
```

Pass `$pattern_runtime_signature` to every `pattern_recommendation_response()` call that occurs after `$state` is available, including signature-only, no-rankable, and success branches.

- [ ] **Step 4: Implement schemas and surface exposure**

In `inc/Abilities/Registration.php`, add `requestPurpose` to the `flavor-agent/recommend-patterns` input schema:

```php
'requestPurpose'       => [
	'type'        => 'string',
	'description' => 'Optional client request purpose. The editor sends "inserter_ranking" only for real inserter-triggered pattern ranking requests.',
],
```

In `patterns_recommendation_output_schema()`, add:

```php
'modelRequest'      => self::open_object_schema(
	[
		'attempted' => [ 'type' => 'boolean' ],
		'reason'    => [ 'type' => 'string' ],
	]
),
```

inside the `diagnostics` properties, and add:

```php
'patternRuntimeSignature' => [ 'type' => 'string' ],
```

beside `reviewContextSignature` and `resolvedContextSignature`.

In `inc/Abilities/SurfaceCapabilities.php`, build the pattern surface first and add the signature only to that surface:

```php
$pattern_surface = self::build_surface(
	$pattern_available,
	$pattern_available ? 'ready' : self::pattern_unavailable_reason( $chat_available, $pattern_reason ),
	'plugin_settings',
	$pattern_message,
	self::build_actions(
		$can_manage_settings,
		[
			[
				'label' => 'Settings > Flavor Agent',
				'href'  => $settings_url,
			],
			[
				'label' => 'Settings > Connectors',
				'href'  => $connectors_url,
			],
		]
	),
	$can_manage_settings ? 'Settings > Flavor Agent' : '',
	$can_manage_settings ? $settings_url : ''
);
$pattern_surface['patternRuntimeSignature'] = PatternAbilities::current_pattern_runtime_signature();
```

Then use `'pattern' => $pattern_surface` in the returned surface map.

In `SurfaceCapabilities::output_schema()`, add:

```php
'patternRuntimeSignature' => [ 'type' => 'string' ],
```

- [ ] **Step 5: Run tests to verify pass**

Run:

```bash
composer run test:php -- --filter 'PatternAbilitiesTest|RegistrationTest|EditorSurfaceCapabilitiesTest|InfraAbilitiesTest'
```

Expected: the new runtime signature and schema tests pass.

- [ ] **Step 6: Commit Task 1**

Run:

```bash
git add inc/Abilities/PatternAbilities.php inc/Abilities/Registration.php inc/Abilities/SurfaceCapabilities.php tests/phpunit/PatternAbilitiesTest.php tests/phpunit/RegistrationTest.php tests/phpunit/EditorSurfaceCapabilitiesTest.php tests/phpunit/InfraAbilitiesTest.php
git commit -m "Add pattern runtime signature contract"
```

## Task 2: Server No-Model Diagnostics and Activity Persistence

**Files:**
- Modify: `inc/Abilities/PatternAbilities.php`
- Modify: `inc/Abilities/RecommendationAbilityExecution.php`
- Test: `tests/phpunit/PatternAbilitiesTest.php`
- Test: `tests/phpunit/RecommendationAbilityExecutionTest.php`

- [ ] **Step 1: Write failing tests for `requestPurpose` and no-model markers**

In `tests/phpunit/PatternAbilitiesTest.php`, add:

```php
public function test_recommend_patterns_keeps_direct_empty_visible_scope_response_without_no_model_marker(): void {
	$result = PatternAbilities::recommend_patterns(
		[
			'postType' => 'page',
		]
	);

	$this->assertSame( [ 'recommendations' => [] ], $result );
}

public function test_recommend_patterns_marks_missing_visible_patterns_only_for_inserter_ranking(): void {
	$result = PatternAbilities::recommend_patterns(
		[
			'postType'       => 'page',
			'requestPurpose' => 'inserter_ranking',
		]
	);

	$this->assertSame( [], $result['recommendations'] ?? null );
	$this->assertSame(
		[
			'attempted' => false,
			'reason'    => 'missing_visible_patterns',
		],
		$result['diagnostics']['modelRequest'] ?? null
	);
}

public function test_recommend_patterns_sanitizes_unknown_request_purpose_as_omitted(): void {
	$result = PatternAbilities::recommend_patterns(
		[
			'postType'       => 'page',
			'requestPurpose' => 'inserter ranking<script>',
		]
	);

	$this->assertSame( [ 'recommendations' => [] ], $result );
}
```

In `tests/phpunit/RecommendationAbilityExecutionTest.php`, add:

```php
public function test_execute_persists_pattern_no_model_marker_on_request_diagnostic_after(): void {
	WordPressTestState::$options['flavor_agent_dual_log_request_diagnostics'] = true;

	RecommendationAbilityExecution::execute(
		'pattern',
		'flavor-agent/recommend-patterns',
		[
			'postType' => 'page',
			'document' => [
				'scopeKey' => 'post:42',
			],
			'clientRequest' => [
				'sessionId'    => 'session-a',
				'requestToken' => 7,
				'abortId'      => '',
				'aborted'      => false,
				'scopeKey'     => 'post:42',
			],
		],
		static fn(): array => [
			'recommendations' => [],
			'diagnostics'     => [
				'modelRequest' => [
					'attempted' => false,
					'reason'    => 'no_rankable_candidates',
				],
			],
		]
	);

	$entries = WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [];
	$this->assertCount( 1, $entries );

	$after = json_decode( (string) ( $entries[0]['after_json'] ?? '' ), true );
	$this->assertSame(
		[
			'attempted' => false,
			'reason'    => 'no_rankable_candidates',
		],
		$after['modelRequest'] ?? null
	);
	$this->assertArrayNotHasKey( 'modelRequest', $after['requestContext'] ?? [] );
}
```

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
composer run test:php -- --filter 'PatternAbilitiesTest|RecommendationAbilityExecutionTest'
```

Expected: failures mention missing `diagnostics.modelRequest` and missing persisted `after.modelRequest`.

- [ ] **Step 3: Implement request-purpose normalization and no-model markers**

In `PatternAbilities::recommend_patterns()`, sanitize request purpose immediately after `$resolve_signature_only`:

```php
$request_purpose = isset( $input['requestPurpose'] ) && is_string( $input['requestPurpose'] )
	? sanitize_key( $input['requestPurpose'] )
	: '';
$is_inserter_ranking_request = self::PATTERN_REQUEST_PURPOSE_INSERTER_RANKING === $request_purpose;
```

Replace the early missing visible-pattern branch:

```php
if ( ! $resolve_signature_only && ( null === $visible_pattern_names || [] === $visible_pattern_names ) ) {
	if ( ! $is_inserter_ranking_request ) {
		return [ 'recommendations' => [] ];
	}

	return self::pattern_recommendation_response(
		[],
		self::empty_pattern_recommendation_diagnostics(),
		[],
		'',
		'',
		[
			'attempted' => false,
			'reason'    => self::PATTERN_MODEL_REQUEST_MISSING_VISIBLE_PATTERNS,
		]
	);
}
```

When candidates are empty, pass the no-rankable marker:

```php
return self::pattern_recommendation_response(
	[],
	$diagnostics,
	$docs_result,
	$review_context_signature,
	$resolved_context_signature,
	[
		'attempted' => false,
		'reason'    => self::PATTERN_MODEL_REQUEST_NO_RANKABLE_CANDIDATES,
	],
	$pattern_runtime_signature
);
```

Extend the response helper signature:

```php
private static function pattern_recommendation_response(
	array $recommendations,
	array $diagnostics,
	array $docs_result = [],
	string $review_context_signature = '',
	string $resolved_context_signature = '',
	?array $model_request = null,
	string $pattern_runtime_signature = ''
): array {
	$response = [
		'recommendations'          => $recommendations,
		'docsGrounding'            => [] !== $docs_result ? DocsGuidanceResult::public_summary( $docs_result ) : null,
		'docsGroundingFingerprint' => (string) ( $docs_result['fingerprint'] ?? '' ),
		'reviewContextSignature'   => $review_context_signature,
		'resolvedContextSignature' => $resolved_context_signature,
		'diagnostics'              => [
			'filteredCandidates' => [
				'unreadableSyncedPatterns' => max(
					0,
					(int) ( $diagnostics['filteredCandidates']['unreadableSyncedPatterns'] ?? 0 )
				),
			],
			'pipelineTrace'      => self::sanitize_pattern_pipeline_trace( $diagnostics['pipelineTrace'] ?? [] ),
			'dropReasons'        => self::sanitize_pattern_drop_reasons( $diagnostics['dropReasons'] ?? [] ),
		],
	];

	$normalized_model_request = self::sanitize_pattern_model_request_marker( $model_request );
	if ( [] !== $normalized_model_request ) {
		$response['diagnostics']['modelRequest'] = $normalized_model_request;
	}

	if ( '' !== $pattern_runtime_signature ) {
		$response['patternRuntimeSignature'] = $pattern_runtime_signature;
	}

	return $response;
}
```

Add the sanitizer:

```php
private static function sanitize_pattern_model_request_marker( ?array $model_request ): array {
	if ( ! is_array( $model_request ) || false !== ( $model_request['attempted'] ?? null ) ) {
		return [];
	}

	$reason = isset( $model_request['reason'] ) && is_string( $model_request['reason'] )
		? sanitize_key( $model_request['reason'] )
		: '';

	if ( ! in_array( $reason, [ self::PATTERN_MODEL_REQUEST_NO_RANKABLE_CANDIDATES, self::PATTERN_MODEL_REQUEST_MISSING_VISIBLE_PATTERNS ], true ) ) {
		return [];
	}

	return [
		'attempted' => false,
		'reason'    => $reason,
	];
}
```

- [ ] **Step 4: Persist no-model marker on request diagnostics**

In `RecommendationAbilityExecution::persist_request_diagnostic_activity()`, after pipeline-drop persistence, add:

```php
$model_request = self::sanitize_request_diagnostic_model_request( $payload );
if ( [] !== $model_request ) {
	$after['modelRequest'] = $model_request;
}
```

Add helper near other request diagnostic builders:

```php
private static function sanitize_request_diagnostic_model_request( array $payload ): array {
	$diagnostics = \is_array( $payload['diagnostics'] ?? null ) ? $payload['diagnostics'] : [];
	$model_request = \is_array( $diagnostics['modelRequest'] ?? null ) ? $diagnostics['modelRequest'] : [];

	if ( false !== ( $model_request['attempted'] ?? null ) ) {
		return [];
	}

	$reason = isset( $model_request['reason'] ) && \is_string( $model_request['reason'] )
		? \sanitize_key( $model_request['reason'] )
		: '';

	if ( ! \in_array( $reason, [ 'no_rankable_candidates', 'missing_visible_patterns' ], true ) ) {
		return [];
	}

	return [
		'attempted' => false,
		'reason'    => $reason,
	];
}
```

- [ ] **Step 5: Run tests to verify pass**

Run:

```bash
composer run test:php -- --filter 'PatternAbilitiesTest|RecommendationAbilityExecutionTest|RegistrationTest'
```

Expected: no-model marker tests pass, and schema coercion tests still pass.

- [ ] **Step 6: Commit Task 2**

Run:

```bash
git add inc/Abilities/PatternAbilities.php inc/Abilities/RecommendationAbilityExecution.php tests/phpunit/PatternAbilitiesTest.php tests/phpunit/RecommendationAbilityExecutionTest.php
git commit -m "Mark pattern no-model diagnostics explicitly"
```

## Task 3: Admin Activity No-Model Normalization and Copy

**Files:**
- Modify: `src/admin/activity-log-utils.js`
- Modify: `src/admin/activity-log.js`
- Test: `src/admin/__tests__/activity-log-utils.test.js`
- Test: `src/admin/__tests__/activity-log.test.js`

- [ ] **Step 1: Write failing admin tests**

In `src/admin/__tests__/activity-log-utils.test.js`, add:

```js
test( 'normalizeActivityEntries exposes allow-listed no-model request markers', () => {
	const entries = normalizeActivityEntries( [
		{
			type: 'request_diagnostic',
			after: {
				modelRequest: {
					attempted: false,
					reason: 'no_rankable_candidates',
				},
			},
			request: {
				ai: {
					requestToken: 'token-only',
				},
			},
		},
	] );

	expect( entries[ 0 ].modelRequest ).toEqual( {
		attempted: false,
		reason: 'no_rankable_candidates',
	} );
} );

test( 'normalizeActivityEntries drops malformed no-model request markers', () => {
	const entries = normalizeActivityEntries( [
		{
			type: 'request_diagnostic',
			after: {
				modelRequest: {
					attempted: true,
					reason: 'no_rankable_candidates',
				},
			},
		},
		{
			type: 'request_diagnostic',
			after: {
				modelRequest: {
					attempted: false,
					reason: 'not_allowed',
				},
			},
		},
	] );

	expect( entries[ 0 ].modelRequest ).toBeNull();
	expect( entries[ 1 ].modelRequest ).toBeNull();
} );
```

In `src/admin/__tests__/activity-log.test.js`, add after the token-only unavailable test:

```js
test( 'renders no-model copy before unavailable request-log copy', async () => {
	await renderApp( [
		createEntry( {
			id: 'activity-no-model',
			suggestion: 'No rankable patterns',
			after: {
				modelRequest: {
					attempted: false,
					reason: 'no_rankable_candidates',
				},
			},
			request: {
				ai: {
					requestToken: '7a85fe6b-ad73-4c0f-931b-0b0a70bc09c0',
					requestLogId: '',
				},
			},
		} ),
	] );

	expect( getContainer().textContent ).toContain(
		'No model request was attempted for this diagnostic.'
	);
	expect( getContainer().textContent ).not.toContain(
		'AI request log unavailable'
	);
	expect( apiFetch ).toHaveBeenCalledTimes( 1 );
} );
```

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
npm run test:unit -- src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js --runInBand
```

Expected: new tests fail because `modelRequest` is not normalized and `AiRequestLogPanel` still renders unavailable copy.

- [ ] **Step 3: Implement no-model normalization**

In `src/admin/activity-log-utils.js`, add:

```js
const MODEL_REQUEST_REASONS = new Set( [
	'no_rankable_candidates',
	'missing_visible_patterns',
] );

function normalizeModelRequestMarker( entry ) {
	const marker =
		entry?.after?.modelRequest || entry?.response?.diagnostics?.modelRequest;

	if (
		! marker ||
		marker.attempted !== false ||
		! MODEL_REQUEST_REASONS.has( marker.reason )
	) {
		return null;
	}

	return {
		attempted: false,
		reason: marker.reason,
	};
}
```

In `normalizeActivityEntry()`, add to the returned object:

```js
modelRequest: normalizeModelRequestMarker( entry ),
```

- [ ] **Step 4: Implement no-model request-log panel copy**

In `src/admin/activity-log.js`, add this check at the start of `AiRequestLogPanel()` before `if ( ! requestLogId && ! requestToken )`:

```js
if ( entry?.modelRequest?.attempted === false ) {
	return (
		<div className="flavor-agent-activity-log__request-log flavor-agent-activity-log__request-log--no-model">
			<p className="flavor-agent-activity-log__copy">
				{ __(
					'No model request was attempted for this diagnostic.',
					'flavor-agent'
				) }
			</p>
		</div>
	);
}
```

- [ ] **Step 5: Run tests to verify pass**

Run:

```bash
npm run test:unit -- src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js --runInBand
```

Expected: no-model copy tests pass and token-only unavailable copy still passes for model-backed diagnostics.

- [ ] **Step 6: Commit Task 3**

Run:

```bash
git add src/admin/activity-log-utils.js src/admin/activity-log.js src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js
git commit -m "Render no-model pattern diagnostics honestly"
```

## Task 4: Store Pattern Cache, Runtime Signature State, and Diagnostics Normalization

**Files:**
- Modify: `src/store/index.js`
- Test: `src/store/__tests__/store-actions.test.js`

- [ ] **Step 1: Write failing store tests**

Add tests near existing pattern request tests in `src/store/__tests__/store-actions.test.js`:

```js
test( 'setPatternRecommendations preserves allow-listed modelRequest diagnostics and pattern runtime signature', () => {
	const action = actions.setPatternRecommendations(
		[ { name: 'theme/hero' } ],
		3,
		'request-signature',
		{
			modelRequest: {
				attempted: false,
				reason: 'no_rankable_candidates',
			},
		},
		'insertion-signature',
		null,
		'resolved-context',
		'pattern-runtime-a'
	);
	const state = reducer( undefined, action );

	expect( state.patternDiagnostics.modelRequest ).toEqual( {
		attempted: false,
		reason: 'no_rankable_candidates',
	} );
	expect( state.patternRuntimeSignature ).toBe( 'pattern-runtime-a' );
} );

test( 'setPatternRecommendations drops malformed modelRequest diagnostics', () => {
	const state = reducer(
		undefined,
		actions.setPatternRecommendations(
			[],
			1,
			'request-signature',
			{
				modelRequest: {
					attempted: true,
					reason: 'no_rankable_candidates',
				},
			}
		)
	);

	expect( state.patternDiagnostics.modelRequest ).toBeUndefined();
} );

test( 'hydratePatternRecommendationsFromCache uses a fresh token and aborts in-flight pattern requests', () => {
	const abort = jest.fn();
	actions._patternAbort = { abort };
	const dispatch = jest.fn();
	const select = {
		getPatternRequestToken: jest.fn( () => 4 ),
	};

	actions.hydratePatternRecommendationsFromCache( {
		recommendations: [ { name: 'theme/hero' } ],
		diagnostics: {
			modelRequest: {
				attempted: false,
				reason: 'no_rankable_candidates',
			},
		},
		requestSignature: 'request-cache',
		insertionTargetSignature: 'target-cache',
		resolvedContextSignature: 'resolved-cache',
		docsGroundingWarning: { status: 'grounded' },
		patternRuntimeSignature: 'runtime-cache',
	} )( { dispatch, select } );

	expect( abort ).toHaveBeenCalled();
	expect( actions._patternAbort ).toBeNull();
	expect( dispatch ).toHaveBeenNthCalledWith(
		1,
		expect.objectContaining( {
			type: 'SET_PATTERN_RECS',
			requestToken: 5,
			requestSignature: 'request-cache',
			insertionTargetSignature: 'target-cache',
			resolvedContextSignature: 'resolved-cache',
			patternRuntimeSignature: 'runtime-cache',
		} )
	);
	expect( dispatch ).toHaveBeenNthCalledWith(
		2,
		expect.objectContaining( {
			type: 'SET_PATTERN_STATUS',
			status: 'ready',
			requestToken: 5,
			requestSignature: 'request-cache',
			insertionTargetSignature: 'target-cache',
		} )
	);
} );

test( 'hydratePatternRecommendationsFromCache rejects incomplete cache entries', () => {
	const dispatch = jest.fn();
	const select = {
		getPatternRequestToken: jest.fn( () => 4 ),
	};

	const result = actions.hydratePatternRecommendationsFromCache( {
		recommendations: [ { name: 'theme/hero' } ],
		requestSignature: 'request-cache',
		insertionTargetSignature: 'target-cache',
		resolvedContextSignature: '',
		patternRuntimeSignature: 'runtime-cache',
	} )( { dispatch, select } );

	expect( result ).toBe( false );
	expect( dispatch ).not.toHaveBeenCalled();
} );

test( 'setPatternRankingCacheEntry stores only complete cache entries', () => {
	let state = reducer(
		undefined,
		actions.setPatternRankingCacheEntry( 'cache-key-a', {
			recommendations: [ { name: 'theme/hero' } ],
			requestSignature: 'request-cache',
			insertionTargetSignature: 'target-cache',
			resolvedContextSignature: 'resolved-cache',
			patternRuntimeSignature: 'runtime-cache',
		} )
	);

	expect( state.patternRankingCache[ 'cache-key-a' ] ).toEqual(
		expect.objectContaining( {
			requestSignature: 'request-cache',
			insertionTargetSignature: 'target-cache',
			resolvedContextSignature: 'resolved-cache',
			patternRuntimeSignature: 'runtime-cache',
		} )
	);

	state = reducer(
		state,
		actions.setPatternRankingCacheEntry( 'cache-key-b', {
			recommendations: [ { name: 'theme/cards' } ],
			requestSignature: 'request-cache-b',
			insertionTargetSignature: 'target-cache-b',
			resolvedContextSignature: '',
			patternRuntimeSignature: 'runtime-cache',
		} )
	);

	expect( state.patternRankingCache[ 'cache-key-b' ] ).toBeUndefined();
} );
```

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
npm run test:unit -- src/store/__tests__/store-actions.test.js --runInBand
```

Expected: failures mention missing `patternRuntimeSignature`, missing cache hydration action, and dropped `modelRequest`.

- [ ] **Step 3: Implement state fields and diagnostics sanitizer**

In `DEFAULT_STATE`, add:

```js
patternRuntimeSignature: '',
patternRankingCache: {},
```

Add constants and helper near `normalizePatternDiagnostics()`:

```js
const PATTERN_MODEL_REQUEST_REASONS = new Set( [
	'no_rankable_candidates',
	'missing_visible_patterns',
] );

function normalizePatternModelRequest( marker = null ) {
	if (
		! marker ||
		marker.attempted !== false ||
		! PATTERN_MODEL_REQUEST_REASONS.has( marker.reason )
	) {
		return null;
	}

	return {
		attempted: false,
		reason: marker.reason,
	};
}
```

In `normalizePatternDiagnostics()`, include:

```js
const modelRequest = normalizePatternModelRequest( diagnostics?.modelRequest );
```

and return:

```js
...( modelRequest ? { modelRequest } : {} ),
```

- [ ] **Step 4: Implement cache entry validation and hydration action**

Add helper:

```js
function normalizePatternCacheEntry( entry = null ) {
	if ( ! isPlainObject( entry ) ) {
		return null;
	}

	const requestSignature = normalizeStringMessage( entry.requestSignature );
	const insertionTargetSignature = normalizeStringMessage(
		entry.insertionTargetSignature
	);
	const resolvedContextSignature = normalizeStringMessage(
		entry.resolvedContextSignature
	);
	const patternRuntimeSignature = normalizeStringMessage(
		entry.patternRuntimeSignature
	);

	if (
		! requestSignature ||
		! insertionTargetSignature ||
		! resolvedContextSignature ||
		! patternRuntimeSignature
	) {
		return null;
	}

	return {
		recommendations: Array.isArray( entry.recommendations )
			? entry.recommendations
			: [],
		diagnostics: entry.diagnostics || null,
		docsGroundingWarning: entry.docsGroundingWarning || null,
		requestSignature,
		insertionTargetSignature,
		resolvedContextSignature,
		patternRuntimeSignature,
	};
}
```

Add action:

```js
setPatternRankingCacheEntry( cacheKey = '', entry = null ) {
	return {
		type: 'SET_PATTERN_RANKING_CACHE_ENTRY',
		cacheKey,
		entry: normalizePatternCacheEntry( entry ),
	};
},

hydratePatternRecommendationsFromCache( entry ) {
	return ( { dispatch, select } ) => {
		const normalized = normalizePatternCacheEntry( entry );

		if ( ! normalized ) {
			return false;
		}

		if ( actions._patternAbort ) {
			actions._patternAbort.abort?.();
			actions._patternAbort = null;
		}

		const requestToken = ( select.getPatternRequestToken?.() || 0 ) + 1;

		dispatch(
			actions.setPatternRecommendations(
				normalized.recommendations,
				requestToken,
				normalized.requestSignature,
				normalized.diagnostics,
				normalized.insertionTargetSignature,
				normalized.docsGroundingWarning,
				normalized.resolvedContextSignature,
				normalized.patternRuntimeSignature
			)
		);
		dispatch(
			actions.setPatternStatus(
				'ready',
				null,
				requestToken,
				normalized.requestSignature,
				normalized.insertionTargetSignature
			)
		);

		return true;
	};
},
```

- [ ] **Step 5: Thread runtime signature through pattern recommendation actions and selectors**

Extend `setPatternRecommendations()` signature:

```js
setPatternRecommendations(
	recommendations,
	requestToken = null,
	requestSignature = '',
	diagnostics = null,
	insertionTargetSignature = '',
	docsGroundingWarning = null,
	resolvedContextSignature = '',
	patternRuntimeSignature = ''
) {
	return {
		type: 'SET_PATTERN_RECS',
		recommendations,
		requestToken,
		requestSignature,
		diagnostics,
		insertionTargetSignature,
		docsGroundingWarning,
		resolvedContextSignature,
		patternRuntimeSignature,
	};
},
```

Add:

```js
function getPatternRuntimeSignatureFromResponse( result = null ) {
	return normalizeStringMessage(
		result?.payload?.patternRuntimeSignature || result?.patternRuntimeSignature
	);
}
```

In `fetchPatternRecommendations.onSuccess`, pass:

```js
getPatternRuntimeSignatureFromResponse( result )
```

as the final argument to `setPatternRecommendations()`.

In `SET_PATTERN_RECS`, add:

```js
patternRuntimeSignature:
	typeof action.patternRuntimeSignature === 'string'
		? action.patternRuntimeSignature
		: '',
```

In `SET_PATTERN_STATUS`, clear it on loading or error:

```js
patternRuntimeSignature:
	action.status === 'loading' || action.status === 'error'
		? ''
		: state.patternRuntimeSignature,
```

Add selector:

```js
getPatternRuntimeSignature: ( state ) => state.patternRuntimeSignature || '',
getPatternRankingCacheEntry: ( state, cacheKey = '' ) =>
	state.patternRankingCache?.[ cacheKey ] || null,
```

Add reducer handling:

```js
case 'SET_PATTERN_RANKING_CACHE_ENTRY':
	if ( ! action.cacheKey || ! action.entry ) {
		return state;
	}

	return {
		...state,
		patternRankingCache: {
			...state.patternRankingCache,
			[ action.cacheKey ]: action.entry,
		},
	};
```

- [ ] **Step 6: Run tests to verify pass**

Run:

```bash
npm run test:unit -- src/store/__tests__/store-actions.test.js --runInBand
```

Expected: cache hydration and diagnostics tests pass.

- [ ] **Step 7: Commit Task 4**

Run:

```bash
git add src/store/index.js src/store/__tests__/store-actions.test.js
git commit -m "Add store-backed pattern ranking cache hydration"
```

## Task 5: PatternRecommender Inserter Intent, Shared Input Shape, and Cache Use

**Files:**
- Modify: `src/utils/capability-flags.js`
- Modify: `src/patterns/PatternRecommender.js`
- Test: `src/utils/__tests__/capability-flags.test.js`
- Test: `src/patterns/__tests__/PatternRecommender.test.js`

- [ ] **Step 1: Write failing capability and recommender tests**

In `src/utils/__tests__/capability-flags.test.js`, add:

```js
test( 'getSurfaceCapability preserves patternRuntimeSignature for pattern surface', () => {
	window.flavorAgentData = {
		capabilities: {
			surfaces: {
				pattern: {
					available: true,
					reason: 'ready',
					patternRuntimeSignature: 'runtime-a',
				},
			},
		},
	};

	expect( getSurfaceCapability( 'pattern' ).patternRuntimeSignature ).toBe(
		'runtime-a'
	);
} );
```

In `src/patterns/__tests__/PatternRecommender.test.js`, update the default bootstrap:

```js
window.flavorAgentData = {
	canRecommendPatterns: true,
	capabilities: {
		surfaces: {
			pattern: {
				available: true,
				reason: 'ready',
				patternRuntimeSignature: 'runtime-a',
			},
		},
	},
};
```

Add tests:

```js
test( 'does not dispatch a real ranking before inserter intent', () => {
	state.isInserterOpen = false;

	renderComponent();

	expect( mockFetchPatternRecommendations ).not.toHaveBeenCalled();
} );

test( 'dispatches first real ranking on inserter open with requestPurpose and selected block context', () => {
	state.blockEditor.selectedBlockClientId = 'selected-a';
	state.blockEditor.selectedBlockName = 'core/heading';

	renderComponent();

	expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith(
		expect.objectContaining( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			requestPurpose: 'inserter_ranking',
			blockContext: {
				blockName: 'core/heading',
			},
		} )
	);
} );

test( 'does not rank when current insertion point has no visible pattern scope', () => {
	state.visiblePatternNames = [];
	state.topLevelAllowedPatterns = [
		{
			name: 'theme/hero',
		},
	];

	renderComponent();

	expect( mockFetchPatternRecommendations ).not.toHaveBeenCalled();
	expect( document.body.textContent ).toContain(
		'This insertion point does not accept patterns.'
	);
} );

test( 'bypasses cache and real ranking when runtime signature is unavailable', () => {
	window.flavorAgentData.capabilities.surfaces.pattern.patternRuntimeSignature = '';

	renderComponent();

	expect( mockFetchPatternRecommendations ).not.toHaveBeenCalled();
} );
```

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
npm run test:unit -- src/utils/__tests__/capability-flags.test.js src/patterns/__tests__/PatternRecommender.test.js --runInBand
```

Expected: new tests fail because `patternRuntimeSignature` is dropped, editor load still fetches, and `requestPurpose` is absent.

- [ ] **Step 3: Preserve pattern runtime signature in capability helper**

In `src/utils/capability-flags.js`, include this in the returned object from `getSurfaceCapability()`:

```js
patternRuntimeSignature:
	surface === 'pattern'
		? normalizeString( structuredCapability?.patternRuntimeSignature )
		: '',
```

- [ ] **Step 4: Make `buildBaseInput()` the single live input source**

In `src/patterns/PatternRecommender.js`, update `buildBaseInput()`:

```js
const buildBaseInput = useCallback( () => {
	const input = {
		postType: effectivePostType,
		visiblePatternNames,
	};

	if ( templateType ) {
		input.templateType = templateType;
	}

	if ( insertionContext ) {
		input.insertionContext = insertionContext;
	}

	if ( selectedBlockName ) {
		input.blockContext = { blockName: selectedBlockName };
	}

	return input;
}, [
	effectivePostType,
	templateType,
	visiblePatternNames,
	insertionContext,
	selectedBlockName,
] );
```

Remove the separate `selectedBlockName` mutation in `handleSearchInput()` because `buildBaseInput()` now owns it.

- [ ] **Step 5: Gate real fetch behind inserter intent and runtime signature**

At the top of the component after `canRecommend`, derive:

```js
const patternCapability = getSurfaceCapability( 'pattern' );
const canRecommend = patternCapability.available;
const currentPatternRuntimeSignature = patternCapability.patternRuntimeSignature || '';
```

Update `fetchPatternRecommendationsForCurrentTarget` to always add `requestPurpose`:

```js
const fetchPatternRecommendationsForCurrentTarget = useCallback(
	( input = buildBaseInput() ) =>
		fetchPatternRecommendations(
			{
				...input,
				requestPurpose: 'inserter_ranking',
			},
			{
				insertionTargetSignature: currentInsertionTargetSignature,
			}
		),
	[
		buildBaseInput,
		currentInsertionTargetSignature,
		fetchPatternRecommendations,
	]
);
```

Update the initial fetch effect:

```js
useEffect( () => {
	if (
		! canRecommend ||
		! isInserterOpen ||
		! effectivePostType ||
		! currentPatternRuntimeSignature ||
		insertionPointAllowsNoPatterns ||
		visiblePatternNames.length === 0
	) {
		return;
	}

	fetchPatternRecommendationsForCurrentTarget();
}, [
	canRecommend,
	currentPatternRuntimeSignature,
	effectivePostType,
	fetchPatternRecommendationsForCurrentTarget,
	insertionPointAllowsNoPatterns,
	isInserterOpen,
	visiblePatternNames.length,
] );
```

Update `handleRetry()` and `handleSearchInput()` with the same runtime signature and inserter guards.

- [ ] **Step 6: Run tests to verify pass for intent/input changes**

Run:

```bash
npm run test:unit -- src/utils/__tests__/capability-flags.test.js src/patterns/__tests__/PatternRecommender.test.js --runInBand
```

Expected: updated expectations pass, including tests that previously expected editor-load fetches.

- [ ] **Step 7: Add cache-key tests and implementation**

Add a test that mocks `hydratePatternRecommendationsFromCache` and confirms same-target reopening uses cache. Extend the test dispatch mock:

```js
const mockHydratePatternRecommendationsFromCache = jest.fn();
```

Extend the default store state:

```js
patternRankingCache: {},
```

Extend the `flavor-agent` selector map:

```js
getPatternRankingCacheEntry: jest.fn(
	( cacheKey ) => state.store.patternRankingCache?.[ cacheKey ] || null
),
```

Extend the dispatch map:

```js
hydratePatternRecommendationsFromCache: ( entry ) =>
	mockHydratePatternRecommendationsFromCache( entry ),
```

Add test:

```js
test( 'hydrates cached recommendations for the same target and runtime signature', () => {
	state.store.patternRankingCache = {
		'cache-key-a': {
			recommendations: [ { name: 'theme/hero' } ],
			requestSignature: 'request-cache',
			insertionTargetSignature: 'target-cache',
			resolvedContextSignature: 'resolved-cache',
			patternRuntimeSignature: 'runtime-a',
		},
	};

	renderComponent();

	expect( mockHydratePatternRecommendationsFromCache ).toHaveBeenCalledWith(
		expect.objectContaining( {
			recommendations: [ { name: 'theme/hero' } ],
			patternRuntimeSignature: 'runtime-a',
		} )
	);
	expect( mockFetchPatternRecommendations ).not.toHaveBeenCalled();
} );
```

Build a cache key in `PatternRecommender.js` with `buildPatternRecommendationRequestSignature()` over the live base input plus `patternRuntimeSignature`:

```js
const currentPatternCacheKey = useMemo(
	() =>
		currentPatternRuntimeSignature
			? buildPatternRecommendationRequestSignature( {
					...buildBaseInput(),
					patternRuntimeSignature: currentPatternRuntimeSignature,
			  } )
			: '',
	[ buildBaseInput, currentPatternRuntimeSignature ]
);
```

Because `buildPatternRecommendationRequestSignature()` currently ignores `patternRuntimeSignature`, update `src/utils/recommendation-request-signature.js` to include:

```js
patternRuntimeSignature: normalizeStringValue(
	normalizedInput.patternRuntimeSignature
),
```

Add/update `src/utils/__tests__/recommendation-request-signature.test.js` to assert runtime signature changes the pattern request signature.

In the fetch effect, before network fetch:

```js
const cacheEntry =
	currentPatternCacheKey && getPatternRankingCacheEntry?.( currentPatternCacheKey );
if ( cacheEntry && hydratePatternRecommendationsFromCache( cacheEntry ) ) {
	return;
}
```

After a successful network response, `fetchPatternRecommendations.onSuccess` stores the completed result by dispatching:

```js
localDispatch(
	actions.setPatternRankingCacheEntry( requestContext.cacheKey, {
		recommendations: result.recommendations || [],
		diagnostics: result.diagnostics || null,
		requestSignature,
		insertionTargetSignature,
		resolvedContextSignature:
			getResolvedContextSignatureFromResponse( result ) || '',
		docsGroundingWarning: normalizeDocsGroundingWarning(
			result.docsGrounding
		),
		patternRuntimeSignature: getPatternRuntimeSignatureFromResponse(
			result
		),
	} )
);
```

Thread `cacheKey: currentPatternCacheKey` through the `requestContext` object passed from `PatternRecommender` into `fetchPatternRecommendations()`. If `requestContext.cacheKey` is empty, skip `setPatternRankingCacheEntry()`.

- [ ] **Step 8: Run pattern and signature tests**

Run:

```bash
npm run test:unit -- src/utils/__tests__/recommendation-request-signature.test.js src/patterns/__tests__/PatternRecommender.test.js src/store/__tests__/store-actions.test.js --runInBand
```

Expected: cache key changes when runtime signature, visible patterns, prompt, insertion context, or block context changes.

- [ ] **Step 9: Commit Task 5**

Run:

```bash
git add src/utils/capability-flags.js src/utils/recommendation-request-signature.js src/patterns/PatternRecommender.js src/utils/__tests__/capability-flags.test.js src/utils/__tests__/recommendation-request-signature.test.js src/patterns/__tests__/PatternRecommender.test.js src/store/__tests__/store-actions.test.js
git commit -m "Rank patterns on inserter intent with target cache"
```

## Task 6: Inserter Toggle Matching and Dedicated Badge Anchor

**Files:**
- Modify: `src/patterns/inserter-dom.js`
- Modify: `src/patterns/compat.js`
- Modify: `src/patterns/InserterBadge.js`
- Test: `src/patterns/__tests__/inserter-dom.test.js`
- Test: `src/patterns/__tests__/compat.test.js`
- Test: `src/patterns/__tests__/InserterBadge.test.js`

- [ ] **Step 1: Write failing DOM selector tests**

In `src/patterns/__tests__/inserter-dom.test.js`, replace the broad fallback expectation with:

```js
test( 'falls back only to explicit block inserter labels', () => {
	document.body.innerHTML = `
		<div class="edit-site-header__start">
			<button id="undo" aria-label="Undo"></button>
			<button id="ins" aria-label="Toggle block inserter"></button>
		</div>
	`;

	expect( findInserterToggle( document )?.id ).toBe( 'ins' );
} );

test( 'rejects list view and document outline buttons near the inserter', () => {
	document.body.innerHTML = `
		<div class="edit-post-header-toolbar">
			<button id="outline" aria-label="Document overview"></button>
			<button id="list" aria-label="List View"></button>
			<button id="ins" class="block-editor-inserter__toggle" aria-label="Toggle block inserter"></button>
		</div>
	`;

	expect( findInserterToggle( document )?.id ).toBe( 'ins' );
} );

test( 'does not use generic inserter labels without block intent', () => {
	document.body.innerHTML = `
		<div class="edit-post-header-toolbar">
			<button id="generic" aria-label="Open inserter tools"></button>
		</div>
	`;

	expect( findInserterToggle( document ) ).toBeNull();
} );
```

Mirror these cases in `src/patterns/__tests__/compat.test.js` because `compat.js` re-exports the helper.

- [ ] **Step 2: Write failing badge anchor test**

In `src/patterns/__tests__/InserterBadge.test.js`, add:

```js
test( 'portals into a dedicated adjacent anchor instead of the shared toolbar parent', async () => {
	const toolbar = document.createElement( 'div' );
	const button = document.createElement( 'button' );
	const sibling = document.createElement( 'button' );

	toolbar.className = 'edit-post-header-toolbar';
	button.className = 'block-editor-inserter__toggle';
	sibling.setAttribute( 'aria-label', 'Document overview' );
	toolbar.appendChild( button );
	toolbar.appendChild( sibling );
	document.body.appendChild( toolbar );
	mockFindInserterToggle.mockReturnValue( button );

	renderComponent();

	const anchor = toolbar.querySelector(
		'.flavor-agent-inserter-badge-anchor'
	);
	expect( anchor ).not.toBeNull();
	expect( anchor.previousSibling ).toBe( button );
	expect( anchor.parentElement ).toBe( toolbar );
	expect( toolbar.querySelector( '.flavor-agent-inserter-badge--ready' ) ).toBe(
		anchor.querySelector( '.flavor-agent-inserter-badge--ready' )
	);
} );
```

- [ ] **Step 3: Run tests to verify failure**

Run:

```bash
npm run test:unit -- src/patterns/__tests__/inserter-dom.test.js src/patterns/__tests__/compat.test.js src/patterns/__tests__/InserterBadge.test.js --runInBand
```

Expected: selector tests fail on generic fallback and badge test fails because the current anchor is `button.parentElement`.

- [ ] **Step 4: Implement strict inserter matching**

In `src/patterns/inserter-dom.js`, add:

```js
const INSERTER_ALLOW_LABEL = /^(toggle\s+)?(add\s+)?block\s+inserter$/i;
const INSERTER_DENY_LABEL = /list\s*view|outline|document\s*overview|hierarchy|structure/i;

function getButtonLabel( button ) {
	return button?.getAttribute?.( 'aria-label' ) || button?.textContent || '';
}

function isDeniedToolbarButton( button ) {
	const label = getButtonLabel( button );
	const className = button?.className || '';

	return INSERTER_DENY_LABEL.test( `${ label } ${ className }` );
}

function isAllowedInserterButton( button ) {
	if ( ! button || isDeniedToolbarButton( button ) ) {
		return false;
	}

	if ( button.matches?.( INSERTER_TOGGLE_SELECTOR ) ) {
		return true;
	}

	return INSERTER_ALLOW_LABEL.test( getButtonLabel( button ) );
}
```

Update `findInserterToggle()`:

```js
const primary = root.querySelector( INSERTER_TOGGLE_SELECTOR );

if ( isAllowedInserterButton( primary ) ) {
	return primary;
}

const allButtons = root.querySelectorAll(
	INSERTER_TOGGLE_TOOLBAR_SELECTORS.map(
		( selector ) => `${ selector } button`
	).join( ', ' )
);

for ( const button of allButtons ) {
	if ( isAllowedInserterButton( button ) ) {
		return button;
	}
}

return null;
```

- [ ] **Step 5: Implement dedicated badge anchor**

In `src/patterns/InserterBadge.js`, add:

```js
const BADGE_ANCHOR_CLASS = 'flavor-agent-inserter-badge-anchor';

function getOrCreateBadgeAnchor( button ) {
	if ( ! button?.parentElement ) {
		return null;
	}

	const next = button.nextElementSibling;
	if ( next?.classList?.contains( BADGE_ANCHOR_CLASS ) ) {
		return next;
	}

	const anchor = document.createElement( 'span' );
	anchor.className = BADGE_ANCHOR_CLASS;
	button.insertAdjacentElement( 'afterend', anchor );

	return anchor;
}
```

Change `refreshAnchor()`:

```js
const button = findInserterToggle();
const nextAnchor = getOrCreateBadgeAnchor( button );
```

When clearing the anchor, remove only anchors this component created and leave non-empty external nodes alone:

```js
if (
	anchorRef.current?.classList?.contains( BADGE_ANCHOR_CLASS ) &&
	anchorRef.current.childNodes.length === 0
) {
	anchorRef.current.remove();
}
```

- [ ] **Step 6: Run tests to verify pass**

Run:

```bash
npm run test:unit -- src/patterns/__tests__/inserter-dom.test.js src/patterns/__tests__/compat.test.js src/patterns/__tests__/InserterBadge.test.js --runInBand
```

Expected: strict selector and dedicated anchor tests pass.

- [ ] **Step 7: Commit Task 6**

Run:

```bash
git add src/patterns/inserter-dom.js src/patterns/compat.js src/patterns/InserterBadge.js src/patterns/__tests__/inserter-dom.test.js src/patterns/__tests__/compat.test.js src/patterns/__tests__/InserterBadge.test.js
git commit -m "Anchor pattern badge to block inserter"
```

## Task 7: Contributor Docs and Validation Gates

**Files:**
- Modify: `docs/features/pattern-recommendations.md`
- Test: docs freshness scripts

- [ ] **Step 1: Update feature docs**

In `docs/features/pattern-recommendations.md`, update the behavior section to include this exact contract:

```markdown
Pattern recommendations do not run a model-backed ranking request on editor load. The editor may warm capability, backend, connector, docs-grounding, and visible-pattern readiness state, but the first real `recommend-patterns` call is sent only after block inserter intent and only when the current insertion point exposes non-empty `visiblePatternNames`.

Completed pattern rankings are cached per insertion target. The cache key includes post type, template type, root client ID, insertion index, insertion context, visible pattern scope, prompt/search text, selected block context, and the server-provided `patternRuntimeSignature`. Cache hits hydrate the store with a fresh request token and preserve the stored request signature, insertion-target signature, server `resolvedContextSignature`, docs-grounding warning, diagnostics, and runtime signature. Insert still revalidates the server apply context before dispatching blocks.

When a real inserter-intent request ends before a model call, diagnostics carry `diagnostics.modelRequest.attempted === false` with an allow-listed reason such as `no_rankable_candidates` or `missing_visible_patterns`. Activity renders that as a no-model diagnostic instead of implying a missing core AI request log.
```

- [ ] **Step 2: Run docs check**

Run:

```bash
npm run check:docs
```

Expected: docs freshness check passes.

- [ ] **Step 3: Run cross-surface local gates**

Run:

```bash
npm run test:unit -- src/patterns/__tests__/PatternRecommender.test.js src/store/__tests__/store-actions.test.js src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js src/patterns/__tests__/inserter-dom.test.js src/patterns/__tests__/InserterBadge.test.js --runInBand
composer run test:php -- --filter 'PatternAbilitiesTest|RegistrationTest|RecommendationAbilityExecutionTest|EditorSurfaceCapabilitiesTest|InfraAbilitiesTest'
node scripts/verify.js --skip-e2e
```

Expected: all targeted tests pass and `VERIFY_RESULT` reports success or intentional incomplete only for unavailable plugin-check prerequisites.

- [ ] **Step 4: Commit Task 7**

Run:

```bash
git add docs/features/pattern-recommendations.md
git commit -m "Document inserter-driven pattern ranking"
```

## Task 8: Browser Evidence for Badge Placement and Inserter Timing

**Files:**
- Modify: `tests/e2e/flavor-agent.smoke.spec.js`
- Evidence: `output/` screenshot/video artifacts generated by Playwright

- [ ] **Step 1: Capture live toolbar DOM before writing the assertion**

Run:

```bash
npm run wp:start
npm run --silent wp:browser-url
```

Open the post editor using the printed browser URL. Capture the toolbar HTML for the inserter and neighboring list-view/document-overview controls with Playwright or the browser console. Save the exact snippet into the test fixture or assertion comments only if it is short enough to explain selector intent.

Expected: the actual block inserter button has either `button.block-editor-inserter__toggle` or an explicit block-inserter label, while the neighboring list/document controls contain list/outline/document-overview labels.

- [ ] **Step 2: Add Playwright assertion**

In `tests/e2e/flavor-agent.smoke.spec.js`, extend the existing pattern inserter smoke (`pattern surface smoke uses the inserter search to fetch recommendations`) or add a sibling test:

```js
test( 'pattern badge attaches to the block inserter button, not document overview', async ( { page } ) => {
	await openPostEditor( page );

	const inserter = page.locator( 'button.block-editor-inserter__toggle' ).first();
	await expect( inserter ).toBeVisible();

	const badgeAnchor = page.locator( '.flavor-agent-inserter-badge-anchor' ).first();
	await expect( badgeAnchor ).toBeVisible();

	const previousButtonLabel = await badgeAnchor.evaluate( ( anchor ) => {
		const previous = anchor.previousElementSibling;
		return previous?.getAttribute( 'aria-label' ) || previous?.textContent || '';
	} );
	expect( previousButtonLabel ).toMatch( /block inserter/i );

	const attachedToOverview = await badgeAnchor.evaluate( ( anchor ) => {
		const previous = anchor.previousElementSibling;
		const label = previous?.getAttribute( 'aria-label' ) || previous?.textContent || '';
		return /list\s*view|outline|document\s*overview|hierarchy|structure/i.test( label );
	} );
	expect( attachedToOverview ).toBe( false );
} );
```

Use existing local helper names in the file. If `openPostEditor` is not present, use the helper already used by the pattern inserter smoke; do not introduce a second editor boot helper.

- [ ] **Step 3: Run browser smoke**

Run:

```bash
npm run test:e2e:playground -- tests/e2e/flavor-agent.smoke.spec.js --grep "pattern"
```

Expected: pattern smoke passes. If the local browser harness is unavailable, record the exact command output and keep the Playwright test committed as pending review evidence.

- [ ] **Step 4: Check WP 7.0 site-editor coverage**

Run:

```bash
npm run test:e2e:wp70 -- --grep "pattern"
```

Expected: either pattern/site-editor coverage passes, or the failure is recorded as an explicit environment blocker with command output. Do not silently skip this if the harness is known-red.

- [ ] **Step 5: Commit Task 8**

Run:

```bash
git add tests/e2e/flavor-agent.smoke.spec.js
git commit -m "Verify pattern badge placement in editor smoke"
```

## Final Verification

- [ ] **Step 1: Run full non-browser gate**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: build, JS lint, plugin check unless unavailable, JS unit tests, and PHP tests pass. If plugin-check prerequisites are unavailable, rerun with:

```bash
npm run verify -- --skip=lint-plugin --skip-e2e
```

and record the reason.

- [ ] **Step 2: Run docs check**

Run:

```bash
npm run check:docs
```

Expected: docs freshness passes.

- [ ] **Step 3: Run browser evidence or record blocker**

Run:

```bash
npm run test:e2e:playground -- tests/e2e/flavor-agent.smoke.spec.js --grep "pattern"
npm run test:e2e:wp70 -- --grep "pattern"
```

Expected: browser evidence passes, or an explicit blocker/waiver is recorded with the exact failing command and reason.

- [ ] **Step 4: Final diff checks**

Run:

```bash
git diff --check
git status --short
```

Expected: no whitespace errors. Dirty files should be exactly this feature's intended changes plus any pre-existing unrelated files noted in Setup.

## Spec Coverage Checklist

- Opening editor alone does not start model-backed pattern ranking: Task 5.
- First real ranking is tied to inserter intent and live insertion target: Task 5.
- Search refinements use the same live input shape: Task 5.
- `blockContext.blockName` is included for first-open ranking, search, retry, and apply-time signature resolution: Task 5.
- Current `patternRuntimeSignature` is exposed before cache lookup without calling `recommend-patterns`: Task 1.
- Cache key includes insertion target, visible pattern scope, prompt, selected block context, and runtime signature: Tasks 4 and 5.
- Cache hydration uses a fresh request token and neutralizes older in-flight responses, including same-key requests: Task 4.
- Cache entries without insertion-target signature, resolved context signature, or runtime signature are misses: Task 4.
- Direct/external empty visible-pattern calls still return the existing empty response: Task 2.
- Inserter-intent missing visible-pattern calls get a no-model marker: Task 2.
- No-rankable real requests get a no-model marker: Task 2.
- Activity copies `diagnostics.modelRequest` to `after.modelRequest`, not `after.requestContext`: Task 2.
- Admin renders no-model copy before unavailable-log fallback: Task 3.
- Badge targets the actual block inserter and uses a dedicated adjacent anchor: Task 6.
- Contributor docs and cross-surface validation gates are updated and run: Task 7.
- Live Gutenberg toolbar evidence is captured: Task 8.

## Self-Review Checks

- Every spec acceptance criterion maps to at least one task in the coverage checklist above.
- Every code-changing task starts with a failing test step, then an implementation step, then a targeted verification step.
- Every command in this plan is an existing repo command or a direct `git` command.
- The plan avoids unresolved placeholders and hand-wavy implementation instructions.
