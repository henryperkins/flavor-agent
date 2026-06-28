# Ring III Attestation Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the current Ring III audit gaps by making admin verification real, resolving supersession chains, and adding automated coverage for the standalone stranger-facing verifier path.

**Architecture:** Keep the signed attestation record as the source of proof, and make chain state explicit in every public projection: reverted, superseded, or ordinary drift. Add a public site-run verification summary for the admin UX, while preserving `tools/attestation-verify.php` as the independent HTTP verifier that a stranger can run without WordPress credentials. Move the HTTP verifier's core behavior into a small testable PHP class so the script becomes a thin wrapper.

**Tech Stack:** PHP 8.2, WordPress REST API, Ed25519 via sodium, PHPUnit 9, `@wordpress/api-fetch`, `@wordpress/scripts` Jest.

## Review Closure

This plan must also close the implementation gaps discovered in review, not just the originally listed audit issues:

- Writer-side supersession is required. Read-side lookup alone is insufficient because the current runtime never writes `supersedesAttestationId`. Add `inc/Attestation/AttestationService.php` to derive the latest prior subject attestation for non-revert applies and persist `supersedes_attestation_id` automatically.
- Local verification must stay aligned with public verification semantics. Add `inc/CLI/AttestationCommand.php` and `tests/phpunit/AttestationCommandTest.php` so WP-CLI reports supersession outcomes too.
- The PHPUnit DB shim must understand the new column. Add `tests/phpunit/bootstrap.php` so `supersedes_attestation_id` filters behave like the existing attestation chain filters.
- Existing tests that assert full attestation artifacts or attestation query counts must be updated, not only new tests added. That includes `tests/phpunit/ActivitySerializerTest.php`, `tests/phpunit/ActivityRepositoryTest.php`, and both attestation-focused admin UI tests in `src/admin/__tests__/activity-log.test.js`.

---

## File Structure

- Modify: `inc/Attestation/Repository.php`
  - Bump schema version, add the missing `supersedes_attestation_id` index, add lookup methods mirroring revert lookups, and expose latest-by-subject lookup for writer-side supersession.
- Modify: `inc/Attestation/AttestationService.php`
  - Automatically persist `supersedes_attestation_id` for later non-revert applies so the new read-side chain logic has real data.
- Modify: `inc/Attestation/Verifier.php`
  - Accept optional supersession metadata and emit `superseded_by_attestation` when the live subject no longer matches but a later attestation supersedes the current one.
- Create: `inc/Attestation/RemoteVerifier.php`
  - Testable HTTP-verifier core. Given a base URL, attestation id, and JSON fetcher callable, fetch envelope, JWKS, and subject-state from public endpoints and call `Verifier::evaluate()`.
- Modify: `inc/CLI/AttestationCommand.php`
  - Keep the local verifier aligned with the public and standalone verifiers by passing through supersession metadata and asserting the new outcome in PHPUnit.
- Modify: `tools/attestation-verify.php`
  - Thin CLI wrapper around `RemoteVerifier`, preserving current usage and exit codes while gaining test coverage for the implementation path.
- Modify: `inc/REST/AttestationController.php`
  - Return `superseded_by_attestation_id` in the envelope and add `GET /attestations/{id}/verification` for a site-run verification summary.
- Modify: `inc/Activity/Repository.php`
  - Batch-hydrate supersession metadata alongside revert metadata for admin/activity projections.
- Modify: `inc/Activity/Serializer.php`
  - Expose `verificationUrl`, `supersededByAttestationId`, and `supersededByVerifyUrl`.
- Modify: `src/admin/activity-log.js`
  - Replace the current presence-only "Verify envelope" interaction with a real verification result fetch, and rename raw endpoint fetch affordances so they do not imply cryptographic verification.
- Modify: `tests/phpunit/*`
  - Add focused tests for supersession lookup, controller envelope/verification output, verifier outcome precedence, serializer projection, remote verifier URL/failure behavior, and CLI wrapper compatibility.
- Modify: `tests/phpunit/bootstrap.php`
  - Extend the fake database attestation-column filtering to include `supersedes_attestation_id` so repository/controller/activity tests verify the real query behavior.
- Modify: `tests/phpunit/AttestationServiceTest.php`
  - Assert a later apply actually records `supersedes_attestation_id` against the latest prior attestation for the same subject.
- Modify: `tests/phpunit/AttestationCommandTest.php`
  - Assert the WP-CLI verifier reports `superseded_by_attestation` when a later attested apply changes the live subject.
- Modify: `src/admin/__tests__/activity-log.test.js`
  - Assert the admin UI calls the new verification URL and renders actual outcomes, and update the second attestation test so it expects `Run verification` plus the raw endpoint affordance rename.
- Modify docs:
  - `docs/reference/governance-layer.md`
  - `docs/features/activity-and-audit.md`
  - `docs/flavoragentportfoliopackage.md`
  - `docs/reference/abilities-and-routes.md`
  - `docs/reference/current-open-work.md`
  - These must state that wp-admin runs a site-served verification summary, while independent verification remains the standalone HTTP tool.

---

### Task 1: Supersession Chain Support

**Files:**
- Modify: `inc/Attestation/Repository.php`
- Modify: `inc/Attestation/Verifier.php`
- Modify: `inc/REST/AttestationController.php`
- Modify: `inc/Activity/Repository.php`
- Modify: `inc/Activity/Serializer.php`
- Test: `tests/phpunit/AttestationRepositoryTest.php`
- Test: `tests/phpunit/AttestationVerifierTest.php`
- Test: `tests/phpunit/AttestationControllerTest.php`
- Test: `tests/phpunit/ActivitySerializerTest.php`
- Test: `tests/phpunit/ActivityRepositoryTest.php`

- [ ] **Step 1: Write failing repository tests for supersession lookup**

Add this test to `tests/phpunit/AttestationRepositoryTest.php`:

```php
public function test_find_by_supersedes_locates_chained_successor(): void {
	Repository::install();
	Repository::insert(
		[
			'attestation_id'             => 'att_2',
			'surface'                    => 'global-styles',
			'subject_name'               => 's',
			'subject_scope'              => 'global-styles',
			'after_digest'               => 'a2',
			'statement_bytes'            => '{}',
			'signature_b64'              => 'x',
			'key_id'                     => 'k1',
			'supersedes_attestation_id'  => 'att_1',
		]
	);

	$this->assertSame( 'att_2', Repository::find_by_supersedes( 'att_1' )['attestation_id'] );
}

public function test_find_supersedes_by_attestation_ids_indexes_latest_successor(): void {
	Repository::install();
	Repository::insert(
		[
			'attestation_id'             => 'att_successor',
			'surface'                    => 'global-styles',
			'subject_name'               => 'wp_global_styles:81',
			'subject_scope'              => 'global-styles',
			'after_digest'               => 'b',
			'statement_bytes'            => '{}',
			'signature_b64'              => 'sig',
			'key_id'                     => 'k1',
			'supersedes_attestation_id'  => 'att_apply',
		]
	);

	$rows = Repository::find_supersedes_by_attestation_ids( [ 'att_apply' ] );

	$this->assertSame( 'att_successor', $rows['att_apply']['attestation_id'] );
}
```

- [ ] **Step 2: Run the repository tests and verify they fail**

Run:

```bash
vendor/bin/phpunit --filter 'AttestationRepositoryTest'
```

Expected: FAIL with `Call to undefined method FlavorAgent\Attestation\Repository::find_by_supersedes()`.

- [ ] **Step 3: Implement repository supersession lookups and schema index**

In `inc/Attestation/Repository.php`, make these changes:

```php
public const SCHEMA_VERSION = 2;
```

Add the index to the table DDL next to the existing chain indexes:

```php
KEY reverts_attestation_id (reverts_attestation_id),
KEY supersedes_attestation_id (supersedes_attestation_id),
KEY related_activity_id (related_activity_id)
```

Add these methods after `find_reverts_by_attestation_ids()`:

```php
/**
 * @return array<string, mixed>|null
 */
public static function find_by_supersedes( string $id ): ?array {
	global $wpdb;

	if ( ! is_object( $wpdb ) ) {
		return null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Read from plugin-owned attestation table with prepared id.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE supersedes_attestation_id = %s ORDER BY created_at DESC', $id ), ARRAY_A );

	return is_array( $row ) ? $row : null;
}

/**
 * @param array<int, string> $ids
 * @return array<string, array<string, mixed>>
 */
public static function find_supersedes_by_attestation_ids( array $ids ): array {
	global $wpdb;

	if ( ! is_object( $wpdb ) ) {
		return [];
	}

	$ids = self::normalize_id_list( $ids );

	if ( [] === $ids ) {
		return [];
	}

	$in_list = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
	$sql     = 'SELECT * FROM ' . self::table_name() . " WHERE supersedes_attestation_id IN ({$in_list}) ORDER BY created_at DESC";
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL parameter list is generated from a bounded id list.
	$sql = $wpdb->prepare( $sql, $ids );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Batch read from plugin-owned attestation table; $sql is prepared above.
	$rows = $wpdb->get_results( $sql, ARRAY_A );

	return self::index_latest_rows(
		is_array( $rows ) ? $rows : [],
		'supersedes_attestation_id'
	);
}
```

- [ ] **Step 4: Run the repository tests and verify they pass**

Run:

```bash
vendor/bin/phpunit --filter 'AttestationRepositoryTest'
```

Expected: PASS.

- [ ] **Step 5: Write failing verifier supersession test**

Add this test to `tests/phpunit/AttestationVerifierTest.php`:

```php
public function test_superseded_change_is_accountable_not_ordinary_drift(): void {
	$original = [
		'settings' => [],
		'styles'   => [ 'color' => [ 'background' => '#111111' ] ],
	];
	$live     = [
		'settings' => [],
		'styles'   => [ 'color' => [ 'background' => '#222222' ] ],
	];
	$bytes    = $this->statement_bytes( 'att_1', Canonicalizer::digest( $original ) );
	$signed   = Signer::sign( $bytes );
	$this->assertNotNull( $signed );

	$outcomes = Verifier::evaluate(
		$bytes,
		$signed['signature'],
		KeyManager::jwks(),
		Canonicalizer::canonical_bytes( $live ),
		null,
		'att_successor'
	);

	$this->assertContains( 'signature_valid', $outcomes );
	$this->assertContains( 'superseded_by_attestation', $outcomes );
	$this->assertNotContains( 'live_changed_since_attestation', $outcomes );
}
```

- [ ] **Step 6: Run the verifier test and verify it fails**

Run:

```bash
vendor/bin/phpunit --filter 'AttestationVerifierTest'
```

Expected: FAIL because `Verifier::evaluate()` accepts only five arguments.

- [ ] **Step 7: Implement verifier supersession outcome**

Change the `Verifier::evaluate()` signature in `inc/Attestation/Verifier.php`:

```php
public static function evaluate(
	string $statement_bytes,
	string $signature_raw,
	array $jwks,
	?string $live_subject_bytes,
	?string $reverted_by_id,
	?string $superseded_by_id = null
): array {
```

Replace the current mismatch branch:

```php
$outcomes[] = null !== $reverted_by_id && '' !== $reverted_by_id
	? 'reverted_by_attestation'
	: 'live_changed_since_attestation';
```

with:

```php
if ( null !== $reverted_by_id && '' !== $reverted_by_id ) {
	$outcomes[] = 'reverted_by_attestation';
} elseif ( null !== $superseded_by_id && '' !== $superseded_by_id ) {
	$outcomes[] = 'superseded_by_attestation';
} else {
	$outcomes[] = 'live_changed_since_attestation';
}
```

- [ ] **Step 8: Run verifier tests**

Run:

```bash
vendor/bin/phpunit --filter 'AttestationVerifierTest'
```

Expected: PASS.

- [ ] **Step 9: Write failing controller and serializer tests**

Add this assertion block to `tests/phpunit/AttestationControllerTest.php` in a new test:

```php
public function test_get_attestation_exposes_superseded_by_attestation_id(): void {
	$id = $this->record_apply();
	Repository::insert(
		[
			'attestation_id'             => 'att_successor',
			'surface'                    => 'global-styles',
			'subject_name'               => 'wp_global_styles:81',
			'subject_scope'              => 'global-styles',
			'after_digest'               => str_repeat( 'b', 64 ),
			'statement_bytes'            => '{}',
			'signature_b64'              => 'sig',
			'key_id'                     => 'k1',
			'supersedes_attestation_id'  => $id,
		]
	);

	$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/attestations/' . $id );
	$request->set_param( 'id', $id );

	$data = ( new AttestationController() )->get_attestation( $request )->get_data();

	$this->assertSame( 'att_successor', $data['superseded_by_attestation_id'] );
}
```

Add this assertion block to `tests/phpunit/ActivitySerializerTest.php` in a new test:

```php
public function test_normalize_attestation_artifact_exposes_superseded_apply_reference(): void {
	$artifact = Serializer::normalize_attestation_artifact(
		[
			'attestation_id'                => 'att_apply',
			'surface'                       => 'global-styles',
			'subject_name'                  => 'wp_global_styles:81',
			'subject_scope'                 => 'global-styles',
			'key_id'                        => 'key-1',
			'created_at'                    => '2026-06-22 00:00:00',
			'superseded_by_attestation_id'  => 'att_successor',
		]
	);

	$this->assertSame( 'att_successor', $artifact['supersededByAttestationId'] );
	$this->assertSame( 'https://example.test/wp-json/flavor-agent/v1/attestations/att_successor', $artifact['supersededByVerifyUrl'] );
}
```

- [ ] **Step 10: Run controller and serializer tests and verify they fail**

Run:

```bash
vendor/bin/phpunit --filter 'AttestationControllerTest|ActivitySerializerTest'
```

Expected: FAIL because the new fields are not projected.

- [ ] **Step 11: Implement controller and serializer supersession projection**

In `inc/REST/AttestationController.php`, after `$reverted_by` add:

```php
$superseded_by = Repository::find_by_supersedes( (string) $row['attestation_id'] );
```

Add this field to the response array:

```php
'superseded_by_attestation_id' => $superseded_by['attestation_id'] ?? null,
```

In `inc/Activity/Serializer.php`, after `$reverted_by_url` setup, add:

```php
$superseded_by     = self::normalize_nullable_string( $row['superseded_by_attestation_id'] ?? null );
$superseded_by_url = null;

if ( null !== $superseded_by && function_exists( 'rest_url' ) ) {
	$superseded_by_url = \rest_url( 'flavor-agent/v1/attestations/' . rawurlencode( $superseded_by ) );
}
```

Add these fields to the returned artifact:

```php
'verificationUrl'             => function_exists( 'rest_url' ) ? \rest_url( 'flavor-agent/v1/attestations/' . rawurlencode( $attestation_id ) . '/verification' ) : '',
'supersededByAttestationId'   => $superseded_by,
'supersededByVerifyUrl'       => $superseded_by_url,
```

- [ ] **Step 12: Implement activity repository batch hydration for supersession**

In `inc/Activity/Repository.php`, inside `attestations_for_activity_ids()`, after the `$reverts` query, add:

```php
$supersedes = AttestationRepository::find_supersedes_by_attestation_ids(
	array_values(
		array_map(
			static fn ( array $row ): string => (string) ( $row['attestation_id'] ?? '' ),
			$attestations
		)
	)
);
```

Inside the existing loop, after setting `reverted_by_attestation_id`, add:

```php
if ( isset( $supersedes[ $attestation_id ] ) ) {
	$attestations[ $activity_id ]['superseded_by_attestation_id'] = $supersedes[ $attestation_id ]['attestation_id'] ?? null;
}
```

Update or add an `ActivityRepositoryTest` assertion that a page entry hydrated from `query_admin_with_reports()` includes:

```php
$this->assertSame( 'att_successor', $result['entries'][0]['attestation']['supersededByAttestationId'] ?? null );
```

- [ ] **Step 13: Run chain projection tests**

Run:

```bash
vendor/bin/phpunit --filter 'AttestationRepositoryTest|AttestationVerifierTest|AttestationControllerTest|ActivitySerializerTest|ActivityRepositoryTest'
```

Expected: PASS.

- [ ] **Step 14: Commit supersession support**

```bash
git add inc/Attestation/Repository.php inc/Attestation/Verifier.php inc/REST/AttestationController.php inc/Activity/Repository.php inc/Activity/Serializer.php tests/phpunit/AttestationRepositoryTest.php tests/phpunit/AttestationVerifierTest.php tests/phpunit/AttestationControllerTest.php tests/phpunit/ActivitySerializerTest.php tests/phpunit/ActivityRepositoryTest.php
git commit -m "feat: resolve attestation supersession chains"
```

---

### Task 2: Public Verification Summary for wp-admin UX

**Files:**
- Modify: `inc/REST/AttestationController.php`
- Modify: `inc/Attestation/Verifier.php`
- Modify: `inc/Activity/Serializer.php`
- Modify: `src/admin/activity-log.js`
- Test: `tests/phpunit/AttestationControllerTest.php`
- Test: `src/admin/__tests__/activity-log.test.js`

- [ ] **Step 1: Write failing REST verification route test**

Add this test to `tests/phpunit/AttestationControllerTest.php`:

```php
public function test_get_verification_returns_actual_outcomes(): void {
	$config = [
		'settings' => [],
		'styles'   => [
			'color' => [
				'background' => 'var:preset|color|parchment-100',
			],
		],
	];
	$this->seed_global_styles_post( $config );
	$id = $this->record_apply( $config );

	$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/attestations/' . $id . '/verification' );
	$request->set_param( 'id', $id );

	$response = ( new AttestationController() )->get_verification( $request );
	$data     = $response->get_data();

	$this->assertSame( $id, $data['attestationId'] );
	$this->assertContains( 'signature_valid', $data['outcomes'] );
	$this->assertContains( 'live_matches_subject', $data['outcomes'] );
	$this->assertNull( $data['subjectError'] );
}
```

- [ ] **Step 2: Run the REST verification route test and verify it fails**

Run:

```bash
vendor/bin/phpunit --filter 'AttestationControllerTest'
```

Expected: FAIL because `get_verification()` does not exist.

- [ ] **Step 3: Register and implement the verification route**

In `inc/REST/AttestationController.php`, add this route after the subject-state route:

```php
\register_rest_route(
	self::NAMESPACE,
	'/attestations/(?P<id>att_[A-Za-z0-9]+)/verification',
	[
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => [ $self, 'get_verification' ],
	]
);
```

Add this method:

```php
public function get_verification( \WP_REST_Request $request ): \WP_REST_Response {
	$id  = (string) $request->get_param( 'id' );
	$row = Repository::find( $id );

	if ( null === $row ) {
		return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
	}

	$subject_response = $this->get_subject_state( $request );
	$subject_error    = null;
	$subject_bytes    = null;

	if ( $subject_response->get_status() < 200 || $subject_response->get_status() >= 300 ) {
		$subject_data  = $subject_response->get_data();
		$subject_error = is_array( $subject_data ) ? (string) ( $subject_data['error'] ?? 'subject_unavailable' ) : 'subject_unavailable';
	} else {
		$subject_data = $subject_response->get_data();
		$subject_b64  = is_array( $subject_data ) ? (string) ( $subject_data['subject_canonical_b64'] ?? '' ) : '';
		$subject_bytes = '' !== $subject_b64 ? self::b64url_decode( $subject_b64 ) : null;
	}

	$reverted_by   = Repository::find_by_reverts( $id );
	$superseded_by = Repository::find_by_supersedes( $id );
	$outcomes      = \FlavorAgent\Attestation\Verifier::evaluate(
		(string) $row['statement_bytes'],
		self::b64url_decode( (string) $row['signature_b64'] ),
		KeyManager::jwks(),
		$subject_bytes,
		isset( $reverted_by['attestation_id'] ) ? (string) $reverted_by['attestation_id'] : null,
		isset( $superseded_by['attestation_id'] ) ? (string) $superseded_by['attestation_id'] : null
	);

	if ( null !== $subject_error ) {
		$outcomes[] = 'live_subject_unavailable';
	}

	return new \WP_REST_Response(
		[
			'attestationId'             => $id,
			'outcomes'                  => $outcomes,
			'subjectError'              => $subject_error,
			'revertedByAttestationId'   => $reverted_by['attestation_id'] ?? null,
			'supersededByAttestationId' => $superseded_by['attestation_id'] ?? null,
		],
		200
	);
}
```

Add this private helper to the controller:

```php
private static function b64url_decode( string $value ): string {
	$decoded = base64_decode(
		strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ),
		true
	);

	return false === $decoded ? '' : $decoded;
}
```

- [ ] **Step 4: Run REST verification route test**

Run:

```bash
vendor/bin/phpunit --filter 'AttestationControllerTest'
```

Expected: PASS.

- [ ] **Step 5: Write failing admin UI test for real verification**

In `src/admin/__tests__/activity-log.test.js`, update the attestation fixture in `renders attestation verification affordances for executed external applies` to include:

```js
verificationUrl:
	'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123/verification',
supersededByAttestationId: '',
supersededByVerifyUrl: '',
```

Change the verification mock for the button click to return actual outcomes:

```js
apiFetch.mockResolvedValueOnce( {
	attestationId: 'att_abc123',
	outcomes: [ 'signature_valid', 'live_matches_subject' ],
	subjectError: null,
} );
```

Replace the button expectation with:

```js
const verifyButton = Array.from(
	getContainer().querySelectorAll( 'button' )
).find( ( button ) => button.textContent === 'Run verification' );

fireEvent.click( verifyButton );

await waitFor( () => {
	expect( apiFetch ).toHaveBeenLastCalledWith( {
		url: 'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123/verification',
	} );
} );

expect( getContainer().textContent ).toContain( 'Signature valid' );
expect( getContainer().textContent ).toContain( 'Live subject matches' );
```

- [ ] **Step 6: Run the admin UI test and verify it fails**

Run:

```bash
npm run test:unit -- --runTestsByPath src/admin/__tests__/activity-log.test.js --testNamePattern=attestation
```

Expected: FAIL because the UI still looks for the old button and only displays envelope field presence.

- [ ] **Step 7: Implement admin verification UX**

In `src/admin/activity-log.js`, extend `getAttestationArtifact()`:

```js
verificationUrl: getAttestationString( artifact, 'verificationUrl' ),
supersededByAttestationId: getAttestationString(
	artifact,
	'supersededByAttestationId'
),
supersededByVerifyUrl: getAttestationString(
	artifact,
	'supersededByVerifyUrl'
),
```

Replace the envelope presence-result logic with outcome formatting:

```js
const ATTESTATION_OUTCOME_LABELS = {
	signature_valid: __( 'Signature valid', 'flavor-agent' ),
	record_tampered: __( 'Record tampered', 'flavor-agent' ),
	live_matches_subject: __( 'Live subject matches', 'flavor-agent' ),
	reverted_by_attestation: __( 'Reverted by attestation', 'flavor-agent' ),
	superseded_by_attestation: __( 'Superseded by attestation', 'flavor-agent' ),
	live_changed_since_attestation: __(
		'Live subject changed since attestation',
		'flavor-agent'
	),
	live_subject_unavailable: __( 'Live subject unavailable', 'flavor-agent' ),
};

function getVerificationCheckDetails( payload ) {
	const outcomes = Array.isArray( payload?.outcomes ) ? payload.outcomes : [];
	const details = outcomes.map(
		( outcome ) => ATTESTATION_OUTCOME_LABELS[ outcome ] || outcome
	);
	const subjectError = getAttestationResultString( payload, 'subjectError' );

	if ( subjectError ) {
		details.push(
			sprintf(
				/* translators: %s: attestation subject-state error code. */
				__( 'Subject error: %s', 'flavor-agent' ),
				subjectError
			)
		);
	}

	return {
		message: __( 'Verification completed using the public attestation endpoints.', 'flavor-agent' ),
		status: outcomes.includes( 'record_tampered' ) ? 'error' : 'success',
		details,
	};
}
```

In `AttestationActions`, use `artifact.verificationUrl`, change the button text to `Run verification`, and call `getVerificationCheckDetails()` for that button. Keep the raw envelope and subject-state links, but do not label their fetch as verification. If the old envelope/subject buttons remain, label them `Load envelope` and `Load live subject`.

When rendering `Notice`, use `checkResult.status`:

```jsx
<Notice
	className="flavor-agent-activity-log__attestation-result"
	status={ checkResult.status }
	isDismissible={ false }
>
```

Add `Superseded by` to `attestationRows`:

```js
[
	__( 'Superseded by', 'flavor-agent' ),
	artifact.supersededByAttestationId,
],
```

- [ ] **Step 8: Run admin UI test**

Run:

```bash
npm run test:unit -- --runTestsByPath src/admin/__tests__/activity-log.test.js --testNamePattern=attestation
```

Expected: PASS.

- [ ] **Step 9: Run focused PHP and JS tests**

Run:

```bash
vendor/bin/phpunit --filter 'Attestation|ActivitySerializer|ActivityRepository'
npm run test:unit -- --runTestsByPath src/admin/__tests__/activity-log.test.js --testNamePattern=attestation
```

Expected: both PASS.

- [ ] **Step 10: Commit admin verification UX**

```bash
git add inc/REST/AttestationController.php inc/Activity/Serializer.php src/admin/activity-log.js tests/phpunit/AttestationControllerTest.php tests/phpunit/ActivitySerializerTest.php src/admin/__tests__/activity-log.test.js
git commit -m "fix: run real attestation verification in admin"
```

---

### Task 3: Testable Standalone HTTP Verifier

**Files:**
- Create: `inc/Attestation/RemoteVerifier.php`
- Modify: `tools/attestation-verify.php`
- Test: `tests/phpunit/AttestationRemoteVerifierTest.php`
- Test: `tests/phpunit/AttestationVerifierTest.php`

- [ ] **Step 1: Write failing remote verifier tests**

Create `tests/phpunit/AttestationRemoteVerifierTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\Canonicalizer;
use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Attestation\RemoteVerifier;
use FlavorAgent\Attestation\Signer;
use FlavorAgent\Attestation\StatementBuilder;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AttestationRemoteVerifierTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$sk = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $sk );
		KeyManager::ensure_registered();
	}

	public function test_verify_fetches_public_urls_and_returns_outcomes(): void {
		$config    = [ 'settings' => [], 'styles' => [ 'color' => [ 'background' => '#111111' ] ] ];
		$statement = StatementBuilder::build(
			[
				'attestationId'      => 'att_abc',
				'surface'            => 'global-styles',
				'scope'              => 'global-styles',
				'subjectName'        => 'wp_global_styles:81',
				'operations'         => [],
				'beforeDigest'       => str_repeat( '0', 64 ),
				'afterDigest'        => Canonicalizer::digest( $config ),
				'freshnessSignature' => 'f',
				'actorRole'          => 'administrator',
				'requestedAt'        => '2026-06-22T00:00:00+00:00',
				'decidedAt'          => '2026-06-22T00:01:00+00:00',
				'siteUrl'            => 'https://example.test',
				'keyId'              => KeyManager::key_id(),
			]
		);
		$signed = Signer::sign( $statement );
		$this->assertNotNull( $signed );

		$seen   = [];
		$result = RemoteVerifier::verify(
			'https://example.test/',
			'att_abc',
			static function ( string $url ) use ( &$seen, $statement, $signed, $config ): array {
				$seen[] = $url;

				return match ( $url ) {
					'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc' => [
						'statement_b64' => KeyManager::b64url( $statement ),
						'signature_b64' => KeyManager::b64url( $signed['signature'] ),
					],
					'https://example.test/wp-json/flavor-agent/v1/attestations/keys' => KeyManager::jwks(),
					'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc/subject-state' => [
						'subject_canonical_b64' => KeyManager::b64url( Canonicalizer::canonical_bytes( $config ) ),
					],
					default => [],
				};
			}
		);

		$this->assertSame(
			[
				'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc',
				'https://example.test/wp-json/flavor-agent/v1/attestations/keys',
				'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc/subject-state',
			],
			$seen
		);
		$this->assertContains( 'signature_valid', $result['outcomes'] );
		$this->assertContains( 'live_matches_subject', $result['outcomes'] );
	}

	public function test_verify_reports_invalid_subject_state(): void {
		$result = RemoteVerifier::verify(
			'https://example.test',
			'att_abc',
			static fn ( string $url ): array => str_ends_with( $url, '/subject-state' ) ? [] : [ 'keys' => [] ]
		);

		$this->assertSame( 'invalid_subject_state', $result['error'] );
		$this->assertSame( 3, $result['exitCode'] );
	}
}
```

- [ ] **Step 2: Run the remote verifier tests and verify they fail**

Run:

```bash
vendor/bin/phpunit --filter 'AttestationRemoteVerifierTest'
```

Expected: FAIL because `FlavorAgent\Attestation\RemoteVerifier` does not exist.

- [ ] **Step 3: Implement `RemoteVerifier`**

Create `inc/Attestation/RemoteVerifier.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

final class RemoteVerifier {

	/**
	 * @param callable(string): array<string, mixed> $get_json
	 * @return array<string, mixed>
	 */
	public static function verify( string $base_url, string $attestation_id, callable $get_json ): array {
		$base_url       = rtrim( $base_url, '/' );
		$attestation_id = trim( $attestation_id );

		$env  = $get_json( "{$base_url}/wp-json/flavor-agent/v1/attestations/{$attestation_id}" );
		$jwks = $get_json( "{$base_url}/wp-json/flavor-agent/v1/attestations/keys" );
		$subj = $get_json( "{$base_url}/wp-json/flavor-agent/v1/attestations/{$attestation_id}/subject-state" );

		if ( ! isset( $subj['subject_canonical_b64'] ) || '' === (string) $subj['subject_canonical_b64'] ) {
			return [
				'attestationId' => $attestation_id,
				'outcomes'      => [ 'live_subject_unavailable' ],
				'error'         => 'invalid_subject_state',
				'exitCode'      => 3,
			];
		}

		$outcomes = Verifier::evaluate(
			self::b64url_decode( (string) ( $env['statement_b64'] ?? '' ) ),
			self::b64url_decode( (string) ( $env['signature_b64'] ?? '' ) ),
			$jwks,
			self::b64url_decode( (string) $subj['subject_canonical_b64'] ),
			isset( $env['reverted_by_attestation_id'] ) ? (string) $env['reverted_by_attestation_id'] : null,
			isset( $env['superseded_by_attestation_id'] ) ? (string) $env['superseded_by_attestation_id'] : null
		);

		return [
			'attestationId' => $attestation_id,
			'outcomes'      => $outcomes,
			'error'         => null,
			'exitCode'      => in_array( 'record_tampered', $outcomes, true ) ? 1 : 0,
		];
	}

	private static function b64url_decode( string $value ): string {
		$decoded = base64_decode(
			strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ),
			true
		);

		return false === $decoded ? '' : $decoded;
	}

	private function __construct() {}
}
```

- [ ] **Step 4: Refactor the standalone script around `RemoteVerifier`**

Replace the middle of `tools/attestation-verify.php` with:

```php
require __DIR__ . '/../vendor/autoload.php';

$get = static function ( string $url ): array {
	$context = stream_context_create(
		[
			'http' => [
				'timeout'       => 15,
				'ignore_errors' => true,
			],
		]
	);
	$raw     = @file_get_contents( $url, false, $context );

	if ( false === $raw ) {
		fwrite( STDERR, "error: request_failed: {$url}\n" );
		exit( 3 );
	}

	$status_line = $http_response_header[0] ?? '';

	if ( '' !== $status_line && ! preg_match( '#\s2\d\d\s#', $status_line ) ) {
		fwrite( STDERR, "error: http_error: {$status_line}\n" );
		exit( 3 );
	}

	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		fwrite( STDERR, "error: invalid_json: {$url}\n" );
		exit( 3 );
	}

	return $data;
};

$result = \FlavorAgent\Attestation\RemoteVerifier::verify( $base, $id, $get );

if ( is_string( $result['error'] ?? null ) && '' !== $result['error'] ) {
	fwrite( STDERR, 'error: ' . $result['error'] . "\n" );
}

echo json_encode(
	[
		'attestationId' => $result['attestationId'],
		'outcomes'      => $result['outcomes'],
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
),
"\n";

exit( (int) $result['exitCode'] );
```

Keep the existing usage parsing at the top exactly as:

```php
// Usage: php tools/attestation-verify.php https://site.example att_xxx
[, $base, $id] = $argv + [ null, null, null ];

if ( ! is_string( $base ) || '' === $base || ! is_string( $id ) || '' === $id ) {
	fwrite( STDERR, "usage: attestation-verify.php <baseUrl> <attestationId>\n" );
	exit( 2 );
}
```

- [ ] **Step 5: Run remote verifier tests**

Run:

```bash
vendor/bin/phpunit --filter 'AttestationRemoteVerifierTest|AttestationVerifierTest'
```

Expected: PASS.

- [ ] **Step 6: Run a script syntax check**

Run:

```bash
php -l tools/attestation-verify.php
```

Expected: `No syntax errors detected in tools/attestation-verify.php`.

- [ ] **Step 7: Commit remote verifier coverage**

```bash
git add inc/Attestation/RemoteVerifier.php tools/attestation-verify.php tests/phpunit/AttestationRemoteVerifierTest.php tests/phpunit/AttestationVerifierTest.php
git commit -m "test: cover standalone attestation verifier"
```

---

### Task 4: Documentation and Claim Alignment

**Files:**
- Modify: `docs/reference/governance-layer.md`
- Modify: `docs/features/activity-and-audit.md`
- Modify: `docs/flavoragentportfoliopackage.md`
- Modify: `docs/reference/current-open-work.md`

- [ ] **Step 1: Update governance wording**

In `docs/reference/governance-layer.md`, change the `inc/CLI/AttestationCommand.php` bullet to:

```markdown
- `inc/CLI/AttestationCommand.php` and `tools/attestation-verify.php` - local and stranger-facing verifiers that report signature, live-state, revert, and supersession outcomes
```

Change the `src/admin/activity-log.js` bullet to:

```markdown
- `src/admin/activity-log.js` - AI Activity detail affordances for the site-run verification summary, raw public endpoint links, and attestation chain context
```

Change the Attest split sentence so it ends with:

```markdown
... signed against the resulting state, and later verified as intact, changed, reverted, or superseded. The wp-admin verification affordance is a convenience summary served by the site; independent verification remains the standalone HTTP verifier against the public envelope, keys, and subject-state endpoints.
```

- [ ] **Step 2: Update activity-and-audit feature wording**

In `docs/features/activity-and-audit.md`, replace flow item 9 with:

```markdown
9. For an attested external style apply, the detail view exposes the attestation id, a site-run verification summary, raw signed-statement and subject-state URLs, and any revert/supersede chain context so a reviewer can move from wp-admin audit evidence to external verification without confusing endpoint loading for cryptographic verification
```

- [ ] **Step 3: Update portfolio package wording**

In `docs/flavoragentportfoliopackage.md`, replace any sentence that says the admin affordance itself verifies independently with:

```markdown
The admin detail view can run a site-served verification summary for convenience, while independent verification is performed with `php tools/attestation-verify.php <baseUrl> <attestationId>` against the public envelope, key, and subject-state endpoints.
```

- [ ] **Step 4: Update current open work**

In `docs/reference/current-open-work.md`, add a dated line near the Attest positioning entry:

```markdown
2026-06-22 Ring III remediation planned: close the supersession-chain gap, replace presence-only admin endpoint checks with a site-run verification summary, and cover the standalone HTTP verifier core in PHPUnit while keeping independent verification separate from wp-admin convenience UX.
```

- [ ] **Step 5: Run docs grep**

Run:

```bash
rg -n "Verify envelope|supersede chain|independently verify|site-run verification|standalone HTTP verifier" docs src/admin
```

Expected:
- No stale admin wording that claims endpoint presence is verification.
- Docs explicitly distinguish site-run admin verification from the standalone HTTP verifier.
- Docs mention supersession wherever they mention revert chain context.

- [ ] **Step 6: Commit documentation alignment**

```bash
git add docs/reference/governance-layer.md docs/features/activity-and-audit.md docs/flavoragentportfoliopackage.md docs/reference/current-open-work.md
git commit -m "docs: align Ring III verification claims"
```

---

### Task 5: Final Verification

**Files:**
- Read only.

- [ ] **Step 1: Run PHP syntax checks**

Run:

```bash
for f in inc/Attestation/*.php inc/REST/AttestationController.php inc/CLI/AttestationCommand.php inc/Activity/Repository.php inc/Activity/Serializer.php tools/attestation-verify.php flavor-agent.php uninstall.php; do php -l "$f" >/dev/null || exit 1; done; echo "php-lint-ok"
```

Expected:

```text
php-lint-ok
```

- [ ] **Step 2: Run focused PHP tests**

Run:

```bash
vendor/bin/phpunit --filter 'Attestation|ApplyAbilities|ActivitySerializer|ActivityRepository'
```

Expected: PASS.

- [ ] **Step 3: Run focused admin JS test**

Run:

```bash
npm run test:unit -- --runTestsByPath src/admin/__tests__/activity-log.test.js --testNamePattern=attestation
```

Expected: PASS.

- [ ] **Step 4: Run aggregate non-browser verifier**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: `VERIFY_RESULT` reports success. If the local environment blocks Plugin Check or another external prerequisite, capture the final `VERIFY_RESULT={...}` line and the log path in the implementation closeout.

- [ ] **Step 5: Inspect final diff**

Run:

```bash
git diff --stat HEAD~4..HEAD
git diff --check HEAD~4..HEAD
```

Expected:
- Diff contains only Ring III remediation code, tests, and docs.
- `git diff --check` produces no whitespace errors.

- [ ] **Step 6: Final commit or squash decision**

If the four task commits are clean and reviewable, leave them separate. If the branch needs one PR commit, squash with:

```bash
git reset --soft HEAD~4
git commit -m "fix: complete Ring III attestation verification"
```

Use the non-squashed commits for review if there is any uncertainty about the admin UX, supersession schema migration, or verifier coverage.

---

## Self-Review

- Spec coverage: all three audit findings are covered. Task 1 resolves supersession chain data and outcomes. Task 2 replaces the presence-only admin verification interaction with a real verification summary. Task 3 adds automated coverage for the standalone HTTP verifier path. Task 4 aligns docs so the product claim matches the implementation.
- Red-flag scan: no task depends on future design decisions. Each behavior has file paths, code snippets, commands, and expected results.
- Type consistency: chain fields use snake_case in REST/storage (`superseded_by_attestation_id`) and camelCase in serialized/admin artifacts (`supersededByAttestationId`, `supersededByVerifyUrl`). Verifier outcome string is `superseded_by_attestation`.
