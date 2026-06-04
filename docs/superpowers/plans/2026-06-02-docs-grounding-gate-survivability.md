# Docs-Grounding Coverage Gate Survivability — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the docs-grounding coverage gate from hard-blocking recommendations (HTTP 503 `flavor_agent_docs_grounding_unavailable`) when the corpus merely lacks a recent make-core/dev-blog release-cycle source; degrade-to-warn instead, and remove the now-dead coverage-gate grace machinery and its diagnostic surface.

**Architecture:** One behavioral change in `DocsGuidanceResult::resolve_status()` (release-cycle-only deficiency → actionable status, not `unavailable`), with `missing-developer-docs` and coverage-probe transport errors still blocking. The coverage-gate grace window, its last-known-current snapshot, the `withinGrace`/`grace*` fields, the `record_coverage_gate_blocked` diagnostic, and the Settings warning it fed all become dead and are removed. The client already renders the degraded coverage warning — no JS change. Spec: `docs/superpowers/specs/2026-06-02-docs-grounding-gate-survivability-design.md`.

**Tech Stack:** PHP 8.0+ (PSR-4 `FlavorAgent\`), PHPUnit (`vendor/bin/phpunit`, custom `WordPressTestState` mocks — no live WP), `@wordpress/scripts` build for the unchanged JS.

---

## Setup (do once before Task 1)

- [ ] **Branch off `master`** (repo default; never commit to it directly)

```bash
cd /home/dev/flavor-agent
git checkout -b fix/docs-grounding-gate-survivability
```

- [ ] **Ensure PHP deps for the test runner**

```bash
composer install
vendor/bin/phpunit tests/phpunit/DocsGuidanceResultTest.php
```
Expected: PASS (baseline green before any change).

## File Structure

- `inc/Support/DocsGuidanceResult.php` — gate status resolution. **Modify:** `resolve_status()`, replace `coverage_satisfies_required_gate()` with `coverage_indicates_hard_block()`, drop grace keys from `normalize_coverage()` and `fingerprint()`.
- `inc/Cloudflare/AISearchClient.php` — coverage probe/cache + runtime state. **Modify:** `get_current_source_coverage()`, `write_source_coverage_cache()`, `normalize_source_coverage_summary()`, `get_runtime_state()` (default + projection), the normalized runtime projection (~line 1172); **delete:** `maybe_decorate_with_coverage_grace()`, `read_last_known_current_coverage_snapshot()`, `write_last_known_current_coverage_snapshot()`, `record_coverage_gate_blocked()`, constants `SOURCE_COVERAGE_GRACE_TTL` + `SOURCE_COVERAGE_LAST_KNOWN_CURRENT_OPTION`, the `lastCoverageGateBlocked*` fields. **Keep untouched:** mechanism B (`LAST_KNOWN_CURRENT_GRACE_TTL`, `get_last_known_current_guidance_for_grace()`, `lastKnownCurrentAt/Guidance`) and the probe/cache/TTL helpers.
- `flavor-agent.php` — **Modify:** remove the `flavor_agent_docs_grounding_unavailable → record_coverage_gate_blocked` hook.
- `inc/Admin/Settings/State.php` — **Modify:** remove the release-cycle coverage-gate warning block.
- `inc/Abilities/Registration.php` — **Modify:** drop `withinGrace`/`graceLastKnownCurrentAt`/`graceExpiresAt` from the docs-grounding output schema.
- `inc/UninstallOptions.php` — **Modify:** add the orphaned snapshot option name.
- Tests — **Modify:** `tests/phpunit/DocsGuidanceResultTest.php`, `tests/phpunit/BlockAbilitiesTest.php`, `tests/phpunit/AISearchClientTest.php`, `tests/phpunit/RegistrationTest.php`, `tests/phpunit/SettingsTest.php`.
- Docs — **Modify:** `docs/reference/developer-docs-public-corpus-runbook.md`, `docs/features/settings-backends-and-sync.md`, `docs/reference/external-service-disclosure.md`.

---

### Task 1: Degrade-to-warn in the gate (the one behavioral change)

**Files:**
- Modify: `inc/Support/DocsGuidanceResult.php` (`resolve_status` ~214, `coverage_satisfies_required_gate` ~241, `normalize_coverage` ~190, `fingerprint` ~98)
- Test: `tests/phpunit/DocsGuidanceResultTest.php`, `tests/phpunit/BlockAbilitiesTest.php`

- [ ] **Step 1: Update the unit tests first (red)**

In `tests/phpunit/DocsGuidanceResultTest.php`:

(a) Replace `test_required_source_coverage_blocks_stable_docs_only_guidance` with this degrade-to-warn version:

```php
	public function test_required_source_coverage_degrades_to_warn_for_missing_release_cycle(): void {
		$result = DocsGuidanceResult::from_guidance(
			[
				[
					'sourceType'  => 'developer-docs',
					'url'         => 'https://developer.wordpress.org/block-editor/',
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'freshness'   => 'current',
				],
			],
			'recommendation',
			'foreground-allowed',
			[
				'requireCurrentSourceCoverage' => true,
				'sourceCoverage'               => [
					'status'                 => 'missing-current-release-cycle',
					'hasDeveloperDocs'       => true,
					'hasCurrentReleaseCycle' => false,
					'sourceTypes'            => [ 'developer-docs' ],
					'freshness'              => [ 'current' ],
					'checkedAt'              => '2026-05-11 00:00:00',
					'errorCode'              => 'missing_current_release_cycle',
					'errorMessage'           => 'Developer Docs grounding is missing current WordPress release-cycle sources.',
				],
			]
		);

		$this->assertSame( 'grounded', $result['status'] );
		$this->assertTrue( DocsGuidanceResult::is_actionable( $result ) );
		// The coverage warning still rides along for the UI.
		$this->assertSame( 'missing-current-release-cycle', $result['coverage']['status'] ?? null );
	}
```

(b) Add two narrow-block tests (the retained hard-blocks):

```php
	public function test_required_coverage_still_blocks_when_developer_docs_missing(): void {
		$result = DocsGuidanceResult::from_guidance(
			[
				[
					'sourceType'  => 'make-core',
					'url'         => 'https://make.wordpress.org/core/2026/05/05/example/',
					'publishedAt' => '2026-05-05T14:00:00Z',
					'freshness'   => 'current',
				],
			],
			'recommendation',
			'foreground-allowed',
			[
				'requireCurrentSourceCoverage' => true,
				'sourceCoverage'               => [
					'status'           => 'missing-developer-docs',
					'hasDeveloperDocs' => false,
					'errorCode'        => 'missing_developer_docs',
					'errorMessage'     => 'Developer Docs grounding did not return a trusted stable Developer Docs source.',
				],
			]
		);

		$this->assertSame( 'unavailable', $result['status'] );
		$this->assertFalse( DocsGuidanceResult::is_actionable( $result ) );
	}

	public function test_required_coverage_still_blocks_on_transport_unavailable(): void {
		$result = DocsGuidanceResult::from_guidance(
			[
				[
					'sourceType'  => 'developer-docs',
					'url'         => 'https://developer.wordpress.org/block-editor/',
					'retrievedAt' => '2026-05-08T14:00:00Z',
					'freshness'   => 'current',
				],
			],
			'recommendation',
			'foreground-allowed',
			[
				'requireCurrentSourceCoverage' => true,
				'sourceCoverage'               => [
					'status'       => 'unavailable',
					'errorCode'    => 'cloudflare_ai_search_coverage_failed',
					'errorMessage' => 'Developer Docs coverage probe failed.',
				],
			]
		);

		$this->assertSame( 'unavailable', $result['status'] );
		$this->assertFalse( DocsGuidanceResult::is_actionable( $result ) );
	}
```

(c) Delete these three now-obsolete grace tests entirely: `test_within_grace_coverage_satisfies_required_gate`, `test_within_grace_only_satisfies_missing_current_release_cycle_gate`, `test_within_grace_coverage_surfaces_in_public_summary`. Leave `test_non_required_source_coverage_warns_without_blocking_stable_guidance` and `test_current_source_coverage_allows_stable_guidance_to_proceed` unchanged.

- [ ] **Step 2: Run the gate tests to verify the new behavior fails**

```bash
vendor/bin/phpunit --filter test_required_source_coverage_degrades_to_warn_for_missing_release_cycle tests/phpunit/DocsGuidanceResultTest.php
```
Expected: FAIL — actual status is `unavailable` (current code still blocks). The two narrow-block tests PASS already.

- [ ] **Step 3: Implement the gate change in `inc/Support/DocsGuidanceResult.php`**

In `resolve_status()`, replace the coverage branch:

```php
		if ( $requires_coverage && ! self::coverage_satisfies_required_gate( $coverage ) ) {
			return 'unavailable';
		}
```

with:

```php
		if ( $requires_coverage && self::coverage_indicates_hard_block( $coverage ) ) {
			return 'unavailable';
		}
```

Replace the whole `coverage_satisfies_required_gate()` method:

```php
	/**
	 * @param array<string, mixed> $coverage
	 */
	private static function coverage_satisfies_required_gate( array $coverage ): bool {
		$status = sanitize_key( (string) ( $coverage['status'] ?? '' ) );

		if ( 'current' === $status ) {
			return true;
		}

		return 'missing-current-release-cycle' === $status && ! empty( $coverage['withinGrace'] );
	}
```

with:

```php
	/**
	 * Hard-block only when the coverage probe shows no trusted stable Developer Docs
	 * (`missing-developer-docs`) or a probe transport failure (`unavailable`). Missing
	 * release-cycle currency alone degrades-to-warn: the coverage summary still carries
	 * the warning downstream, so the surface proceeds with a "review current docs" notice.
	 *
	 * @param array<string, mixed> $coverage
	 */
	private static function coverage_indicates_hard_block( array $coverage ): bool {
		return in_array(
			sanitize_key( (string) ( $coverage['status'] ?? '' ) ),
			[ 'missing-developer-docs', 'unavailable' ],
			true
		);
	}
```

In `normalize_coverage()`, remove these three lines:

```php
			'withinGrace'             => ! empty( $coverage['withinGrace'] ),
			'graceLastKnownCurrentAt' => sanitize_text_field( (string) ( $coverage['graceLastKnownCurrentAt'] ?? '' ) ),
			'graceExpiresAt'          => sanitize_text_field( (string) ( $coverage['graceExpiresAt'] ?? '' ) ),
```

In `fingerprint()`, remove this line from the `'coverage'` sub-array:

```php
				'withinGrace'            => ! empty( $coverage['withinGrace'] ),
```

- [ ] **Step 4: Run the gate tests (green)**

```bash
vendor/bin/phpunit tests/phpunit/DocsGuidanceResultTest.php
```
Expected: PASS (degrade-to-warn + both narrow-block tests pass; grace tests gone).

- [ ] **Step 5: Update the ability-level integration test**

In `tests/phpunit/BlockAbilitiesTest.php`, replace `test_recommend_block_enforces_missing_release_cycle_coverage_only_when_release_gate_is_enabled` with:

```php
	public function test_recommend_block_warns_on_missing_release_cycle_coverage_even_when_release_gate_is_enabled(): void {
		$this->configure_text_generation_connector();
		WordPressTestState::$transients['flavor_agent_docs_source_coverage_v2'] = [
			'status'                 => 'missing-current-release-cycle',
			'hasDeveloperDocs'       => true,
			'hasCurrentReleaseCycle' => false,
			'sourceTypes'            => [ 'developer-docs' ],
			'freshness'              => [ 'current' ],
			'checkedAt'              => '2026-05-11 00:00:00',
			'errorCode'              => 'missing_current_release_cycle',
			'errorMessage'           => 'Developer Docs grounding is missing current WordPress release-cycle sources.',
		];
		WordPressTestState::$ai_client_generate_text_result                     = wp_json_encode(
			[
				'settings'    => [],
				'styles'      => [],
				'block'       => [],
				'explanation' => 'Model still runs: release-cycle currency is now advisory, not a block.',
			]
		);

		add_filter( 'flavor_agent_docs_grounding_require_current_coverage', '__return_true' );

		try {
			$result = BlockAbilities::recommend_block(
				[
					'selectedBlock' => [
						'blockName'  => 'core/paragraph',
						'attributes' => [
							'content' => 'Hello world',
						],
					],
				]
			);
		} finally {
			remove_filter( 'flavor_agent_docs_grounding_require_current_coverage', '__return_true' );
		}

		$this->assertIsArray( $result );
		$this->assertSame(
			'missing-current-release-cycle',
			$result['docsGrounding']['coverage']['status'] ?? null
		);
		// The model runs even with the release gate enabled.
		$this->assertNotSame( [], WordPressTestState::$last_ai_client_prompt );
	}
```

- [ ] **Step 6: Run the ability test (green)**

```bash
vendor/bin/phpunit tests/phpunit/BlockAbilitiesTest.php
```
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add inc/Support/DocsGuidanceResult.php tests/phpunit/DocsGuidanceResultTest.php tests/phpunit/BlockAbilitiesTest.php
git commit -m "fix: degrade-to-warn on missing release-cycle coverage instead of blocking

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Remove the coverage-gate grace machinery (mechanism A)

**Files:**
- Modify: `inc/Cloudflare/AISearchClient.php` (constants ~18-23; `get_current_source_coverage` ~296; `write_source_coverage_cache` ~2352; `normalize_source_coverage_summary` ~2332; delete `maybe_decorate_with_coverage_grace`/`read_last_known_current_coverage_snapshot`/`write_last_known_current_coverage_snapshot`)
- Test: `tests/phpunit/AISearchClientTest.php`

- [ ] **Step 1: Delete the obsolete grace tests (keep the suite honest)**

In `tests/phpunit/AISearchClientTest.php`, delete these four methods entirely: `test_current_coverage_probe_seeds_last_known_current_snapshot`, `test_missing_current_release_cycle_within_grace_window_decorates_coverage`, `test_missing_current_release_cycle_past_grace_window_stays_raw`, `test_missing_developer_docs_status_never_gets_grace`, and `test_cached_missing_current_release_cycle_read_applies_grace`. Leave the probe/TTL tests (`test_source_coverage_probe_*`, `test_validate_configuration_*`) intact.

- [ ] **Step 2: Strip the grace wrapper from `get_current_source_coverage()`**

Replace the method body so each return no longer wraps in `maybe_decorate_with_coverage_grace(...)`:

```php
	public static function get_current_source_coverage( bool $allow_probe = false ): array {
		$cached = get_transient( self::SOURCE_COVERAGE_CACHE_KEY );

		if ( is_array( $cached ) ) {
			return self::normalize_source_coverage_summary( $cached );
		}

		if ( ! $allow_probe ) {
			return self::normalize_source_coverage_summary(
				[
					'status'       => 'unknown',
					'errorCode'    => 'coverage_not_checked',
					'errorMessage' => 'Developer Docs source coverage has not been checked yet.',
				]
			);
		}

		$config = self::get_config();
		if ( is_wp_error( $config ) ) {
			return self::write_source_coverage_cache(
				[
					'status'       => 'unavailable',
					'errorCode'    => $config->get_error_code(),
					'errorMessage' => $config->get_error_message(),
				]
			);
		}

		$coverage = self::probe_current_source_coverage( $config );

		return self::write_source_coverage_cache( $coverage );
	}
```

- [ ] **Step 3: Stop seeding the snapshot in `write_source_coverage_cache()`**

Remove this block (lines ~2355-2357):

```php
		if ( 'current' === sanitize_key( (string) ( $coverage['status'] ?? '' ) ) ) {
			self::write_last_known_current_coverage_snapshot( $coverage );
		}
```

- [ ] **Step 4: Drop the grace keys from `normalize_source_coverage_summary()`**

Remove these three lines:

```php
			'withinGrace'             => ! empty( $coverage['withinGrace'] ),
			'graceLastKnownCurrentAt' => sanitize_text_field( (string) ( $coverage['graceLastKnownCurrentAt'] ?? '' ) ),
			'graceExpiresAt'          => sanitize_text_field( (string) ( $coverage['graceExpiresAt'] ?? '' ) ),
```

- [ ] **Step 5: Delete the three grace methods and two constants**

Delete the methods `maybe_decorate_with_coverage_grace()`, `read_last_known_current_coverage_snapshot()`, and `write_last_known_current_coverage_snapshot()` (and their docblocks). Delete the two constants:

```php
	private const SOURCE_COVERAGE_LAST_KNOWN_CURRENT_OPTION = 'flavor_agent_docs_source_coverage_last_known_current';
	private const SOURCE_COVERAGE_GRACE_TTL                 = 604800;
```

Leave `LAST_KNOWN_CURRENT_GRACE_TTL`, `get_last_known_current_guidance_for_grace()`, and the `lastKnownCurrentAt`/`lastKnownCurrentGuidance` state (mechanism B) untouched.

- [ ] **Step 6: Verify no dangling references**

```bash
grep -rn "maybe_decorate_with_coverage_grace\|last_known_current_coverage_snapshot\|SOURCE_COVERAGE_GRACE_TTL\|SOURCE_COVERAGE_LAST_KNOWN_CURRENT_OPTION" inc/
```
Expected: no output.

- [ ] **Step 7: Run the AI Search tests (green)**

```bash
vendor/bin/phpunit tests/phpunit/AISearchClientTest.php
```
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add inc/Cloudflare/AISearchClient.php tests/phpunit/AISearchClientTest.php
git commit -m "refactor: remove coverage-gate grace window (no longer gates anything)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Remove the now-dead gate-block diagnostic

**Files:**
- Modify: `inc/Cloudflare/AISearchClient.php` (`record_coverage_gate_blocked` ~284; runtime-state default ~795-798; live projection ~827-830; normalized projection ~1172-1175)
- Modify: `flavor-agent.php` (hook ~131-134)
- Modify: `inc/Admin/Settings/State.php` (warning block ~457-473)
- Test: `tests/phpunit/AISearchClientTest.php`, `tests/phpunit/SettingsTest.php`

- [ ] **Step 1: Delete the obsolete diagnostic tests**

Delete `tests/phpunit/AISearchClientTest.php::test_record_coverage_gate_blocked_records_diagnostics_without_clobbering_search_health` and `tests/phpunit/SettingsTest.php::test_render_page_shows_coverage_gate_blocked_runtime_warning` entirely.

- [ ] **Step 2: Delete `record_coverage_gate_blocked()`**

Remove the whole method (and its docblock) from `inc/Cloudflare/AISearchClient.php`:

```php
	public static function record_coverage_gate_blocked( array $result ): void {
		$coverage = is_array( $result['coverage'] ?? null ) ? $result['coverage'] : [];
		$state    = self::read_runtime_state();

		$state['lastCoverageGateBlockedAt']      = self::current_runtime_timestamp();
		$state['lastCoverageGateBlockedStatus']  = sanitize_key( (string) ( $coverage['status'] ?? '' ) );
		$state['lastCoverageGateBlockedReason']  = sanitize_key( (string) ( $coverage['errorCode'] ?? '' ) );
		$state['lastCoverageGateBlockedInGrace'] = ! empty( $coverage['withinGrace'] );

		self::write_runtime_state( $state );
	}
```

- [ ] **Step 3: Remove the `lastCoverageGateBlocked*` runtime-state fields**

In `get_runtime_state()`, remove these four lines from the not-configured default block (~795-798):

```php
				'lastCoverageGateBlockedAt'      => '',
				'lastCoverageGateBlockedStatus'  => '',
				'lastCoverageGateBlockedReason'  => '',
				'lastCoverageGateBlockedInGrace' => false,
```

and these four from the live projection (~827-830):

```php
			'lastCoverageGateBlockedAt'      => (string) ( $state['lastCoverageGateBlockedAt'] ?? '' ),
			'lastCoverageGateBlockedStatus'  => (string) ( $state['lastCoverageGateBlockedStatus'] ?? '' ),
			'lastCoverageGateBlockedReason'  => (string) ( $state['lastCoverageGateBlockedReason'] ?? '' ),
			'lastCoverageGateBlockedInGrace' => ! empty( $state['lastCoverageGateBlockedInGrace'] ),
```

and these four from the normalized projection (~1172-1175):

```php
			'lastCoverageGateBlockedAt'      => self::normalize_runtime_timestamp( $state['lastCoverageGateBlockedAt'] ?? '' ),
			'lastCoverageGateBlockedStatus'  => sanitize_key( (string) ( $state['lastCoverageGateBlockedStatus'] ?? '' ) ),
			'lastCoverageGateBlockedReason'  => sanitize_key( (string) ( $state['lastCoverageGateBlockedReason'] ?? '' ) ),
			'lastCoverageGateBlockedInGrace' => ! empty( $state['lastCoverageGateBlockedInGrace'] ),
```

(Do not touch the adjacent `lastKnownCurrentAt`/`lastKnownCurrentGuidance` lines — mechanism B.)

- [ ] **Step 4: Remove the action hook in `flavor-agent.php`**

Delete:

```php
add_action(
	'flavor_agent_docs_grounding_unavailable',
	[ FlavorAgent\Cloudflare\AISearchClient::class, 'record_coverage_gate_blocked' ]
);
```

- [ ] **Step 5: Remove the Settings warning in `inc/Admin/Settings/State.php`**

Delete this block (~457-473):

```php
			$coverage_gate_status     = (string) ( $state['runtime_docs_grounding']['lastCoverageGateBlockedStatus'] ?? '' );
			$coverage_gate_blocked_at = (string) ( $state['runtime_docs_grounding']['lastCoverageGateBlockedAt'] ?? '' );
			$last_trusted_success_at  = (string) ( $state['runtime_docs_grounding']['lastTrustedSuccessAt'] ?? '' );

			// Surface the release-cycle coverage gate as its own warning, distinct from
			// the search-transport status. Shown only while the gate-block is the most
			// recent docs outcome (blocked-at >= last trusted success), so it self-heals
			// once a later request passes the gate.
			if (
				'missing-current-release-cycle' === $coverage_gate_status &&
				$coverage_gate_blocked_at >= $last_trusted_success_at
			) {
				$status_blocks[] = [
					'tone'    => 'warning',
					'message' => __( 'Developer Docs recommendations are paused by the release-cycle coverage gate: the corpus is missing current WordPress release-cycle sources and the coverage grace window has lapsed. Docs search itself is healthy and resumes recommendations once current Make/Core or Developer Blog sources return.', 'flavor-agent' ),
				];
			}

```

- [ ] **Step 6: Verify no dangling references**

```bash
grep -rn "record_coverage_gate_blocked\|lastCoverageGateBlocked" inc/ flavor-agent.php
```
Expected: no output.

- [ ] **Step 7: Run the affected suites (green)**

```bash
vendor/bin/phpunit tests/phpunit/AISearchClientTest.php tests/phpunit/SettingsTest.php
```
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add inc/Cloudflare/AISearchClient.php flavor-agent.php inc/Admin/Settings/State.php tests/phpunit/AISearchClientTest.php tests/phpunit/SettingsTest.php
git commit -m "refactor: remove dead release-cycle gate-block diagnostic surface

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Drop grace fields from the ability output schema

**Files:**
- Modify: `inc/Abilities/Registration.php` (~583-585)
- Test: `tests/phpunit/RegistrationTest.php` (~1469, ~1516)

- [ ] **Step 1: Remove the three grace properties from the schema**

In `inc/Abilities/Registration.php`, inside the `'coverage'` object `'properties'`, delete:

```php
						'withinGrace'             => [ 'type' => 'boolean' ],
						'graceLastKnownCurrentAt' => [ 'type' => 'string' ],
						'graceExpiresAt'          => [ 'type' => 'string' ],
```

Leave `hasCurrentReleaseCycle`, `status`, `hasDeveloperDocs`, `sourceTypes`, `freshness`, `checkedAt`, `errorCode`, `errorMessage`.

- [ ] **Step 2: Convert the schema assertions to negative assertions in `RegistrationTest.php`**

Do not merely delete the positive grace assertions — a bare deletion lets a forgotten field in the closed schema (`Registration.php`, `additionalProperties: false`) pass silently. Replace them with `assertArrayNotHasKey` so the test fails if the fields linger.

There are two methods. First, `test_recommendation_output_schemas_expose_docs_grounding_contract` (loops the six recommend abilities, already binds `$coverage_properties`). Replace these three lines:

```php
			$this->assertSame( 'boolean', $coverage_properties['withinGrace']['type'] ?? null, $ability_id );
			$this->assertSame( 'string', $coverage_properties['graceLastKnownCurrentAt']['type'] ?? null, $ability_id );
			$this->assertSame( 'string', $coverage_properties['graceExpiresAt']['type'] ?? null, $ability_id );
```

with:

```php
			$this->assertArrayNotHasKey( 'withinGrace', $coverage_properties, $ability_id );
			$this->assertArrayNotHasKey( 'graceLastKnownCurrentAt', $coverage_properties, $ability_id );
			$this->assertArrayNotHasKey( 'graceExpiresAt', $coverage_properties, $ability_id );
```

Keep the adjacent `assertSame( 'boolean', $coverage_properties['hasCurrentReleaseCycle']['type'] … )` line — that field stays.

Second, `test_register_abilities_exposes_wordpress_docs_entity_key_schema` (the WordPress-docs ability, uses the long nested path). Replace these three blocks:

```php
		$this->assertSame(
			'boolean',
			$ability['output_schema']['properties']['docsGrounding']['properties']['coverage']['properties']['withinGrace']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['output_schema']['properties']['docsGrounding']['properties']['coverage']['properties']['graceLastKnownCurrentAt']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['output_schema']['properties']['docsGrounding']['properties']['coverage']['properties']['graceExpiresAt']['type'] ?? null
		);
```

with:

```php
		$wordpress_docs_coverage_properties =
			$ability['output_schema']['properties']['docsGrounding']['properties']['coverage']['properties'] ?? [];
		$this->assertArrayNotHasKey( 'withinGrace', $wordpress_docs_coverage_properties );
		$this->assertArrayNotHasKey( 'graceLastKnownCurrentAt', $wordpress_docs_coverage_properties );
		$this->assertArrayNotHasKey( 'graceExpiresAt', $wordpress_docs_coverage_properties );
```

Then confirm no positive grace assertion remains:

```bash
grep -n "withinGrace\|graceLastKnownCurrentAt\|graceExpiresAt" tests/phpunit/RegistrationTest.php
```
Expected: only the three `assertArrayNotHasKey` lines plus the new `$wordpress_docs_coverage_properties` references.

- [ ] **Step 3: Run the registration tests (green)**

```bash
vendor/bin/phpunit tests/phpunit/RegistrationTest.php
```
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add inc/Abilities/Registration.php tests/phpunit/RegistrationTest.php
git commit -m "refactor: drop grace fields from docs-grounding ability output schema

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: Clean up the orphaned snapshot option

**Files:**
- Modify: `inc/UninstallOptions.php`

- [ ] **Step 1: Add the option name to the uninstall list**

In `inc/UninstallOptions.php::names()`, add this entry next to the other `flavor_agent_docs_*` options (after `'flavor_agent_docs_warm_queue',`):

```php
			'flavor_agent_docs_source_coverage_last_known_current',
```

- [ ] **Step 2: Sanity-check the list parses**

```bash
php -l inc/UninstallOptions.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add inc/UninstallOptions.php
git commit -m "chore: remove orphaned docs coverage snapshot option on uninstall

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: Update the docs

**Files:**
- Modify: `docs/reference/developer-docs-public-corpus-runbook.md`
- Modify: `docs/features/settings-backends-and-sync.md`
- Modify: `docs/reference/external-service-disclosure.md`

- [ ] **Step 1: Find the claims that changed**

```bash
grep -rn "grace\|REQUIRE_CURRENT_COVERAGE\|require_current_coverage\|release-cycle coverage gate\|paused by the release-cycle\|hard-block\|503" docs/reference/developer-docs-public-corpus-runbook.md docs/features/settings-backends-and-sync.md docs/reference/external-service-disclosure.md
```

- [ ] **Step 2: Apply the new framing**

Update each hit so the docs state the new behavior. The canonical replacement points:

- `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE` (and the `flavor_agent_docs_grounding_require_current_coverage` filter) now mean: *"probe for current WordPress release-cycle coverage and surface a degraded warning when it is missing — it no longer hard-blocks recommendations on the release-cycle dimension."*
- Remove descriptions of the **7-day coverage grace window** and the **"recommendations paused by the release-cycle coverage gate" Settings warning** — both are gone.
- State that a `missing-current-release-cycle` corpus now yields recommendations **with** a "Developer Docs grounding is trusted, but current release-cycle sources have not been confirmed — review current WordPress docs" warning. Genuine hard-blocks remain for exactly three cases: **no trusted official guidance at all** (`! has_official_guidance`), **no stable Developer Docs source** (`missing-developer-docs` — make-core/developer-blog may be present, but the trusted stable Developer Docs backbone is absent), and **coverage-probe transport failure** (`unavailable`). Do not describe the retained block as currency-related — `missing-developer-docs` is a distinct "no stable docs" state, not a release-cycle gap.
- In `external-service-disclosure.md`, ensure no claim remains that recommendations are withheld pending current release-cycle sources.

- [ ] **Step 3: Run the docs freshness guard**

```bash
npm run check:docs
```
Expected: PASS (no stale-doc failures for the touched files).

- [ ] **Step 4: Commit**

```bash
git add docs/reference/developer-docs-public-corpus-runbook.md docs/features/settings-backends-and-sync.md docs/reference/external-service-disclosure.md
git commit -m "docs: reflect degrade-to-warn coverage gate and removed grace window

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 7: Full verification

**Files:** none (verification only)

This is a shared-subsystem change, so it triggers Gate 7 (multi-surface release matrix) in `docs/reference/cross-surface-validation-gates.md`. Browser harness evidence is a hard stop here — running the harnesses **or** recording an explicit waiver is mandatory; a silent skip does not clear the gate.

- [ ] **Step 1: Run the shared-subsystem PHP suites**

```bash
vendor/bin/phpunit tests/phpunit/DocsGuidanceResultTest.php tests/phpunit/AISearchClientTest.php tests/phpunit/BlockAbilitiesTest.php tests/phpunit/RegistrationTest.php tests/phpunit/SettingsTest.php
```
Expected: PASS, no skips related to coverage.

- [ ] **Step 2: Run the aggregate verify (fast loop) and inspect the summary**

```bash
node scripts/verify.js --skip-e2e
cat output/verify/summary.json
```
Expected: final line `VERIFY_RESULT={..."status":"pass"...}`; `summary.json` `status: "pass"`. Per the gates doc, if `summary.json` is `incomplete` because `lint-plugin` could not run (`wp` or `WP_PLUGIN_CHECK_PATH` unavailable), treat that as an **environment blocker to record**, not a pass — note it in the sign-off.

- [ ] **Step 3: Confirm the docs freshness guard (contracts + operator docs changed)**

```bash
npm run check:docs
```
Expected: PASS. (Already run in Task 6; re-run here as the Baseline-Evidence gate item since the abilities output-schema contract and operator docs changed.)

- [ ] **Step 4: Confirm the JS docs-grounding behavior is unregressed**

```bash
npm run test:unit -- --runInBand --testPathPattern "docs-grounding|DocsGrounding"
```
Expected: PASS (no JS source changed; warning rendering is reused).

- [ ] **Step 5: Run the matching Playwright harnesses — or record an explicit waiver**

Both harnesses match the touched caller set (docs grounding feeds post-editor and Site Editor surfaces):

```bash
npm run test:e2e:playground
npm run test:e2e:wp70
```
Expected: PASS — proves the recommendation surfaces still load and function (no regression).

These harnesses verify no-regression but **cannot** directly exercise this change's gate behavior: their fixtures run without the `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE` constant and without a `missing-current-release-cycle` corpus, so the degrade-to-warn path is not reachable in-harness. The targeted behavioral evidence for the gate change is the `DocsGuidanceResultTest` unit cases and the `BlockAbilitiesTest` integration case (Task 1), plus the live dev-container `wp eval` (Step 6).

If either harness is unavailable or known-red in this environment, **do not silently skip** — record an explicit waiver in the PR/sign-off, e.g.:

> Waiver (cross-surface-validation-gates.md, Baseline item 6 / Gate 7): `test:e2e:wp70` [unavailable | known-red] here because [reason]. Behavioral evidence for the docs-grounding gate change: `tests/phpunit/DocsGuidanceResultTest.php` (degrade-to-warn + the two narrow-block cases), `tests/phpunit/BlockAbilitiesTest.php` (ability proceeds with the release gate enabled), and the live-container `wp eval` re-confirm (Step 6). Surface no-regression for [the unavailable harness] is waived pending [CI run | manual container check].

- [ ] **Step 6: Live re-confirm in the dev container (the original repro)**

With `wordpress-wordpress-1` running and `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE` still `true` in its `wp-config.php` (PHP is live-mounted — no build needed), exercise the ability directly:

```bash
docker exec wordpress-wordpress-1 wp eval '
$r = \FlavorAgent\Abilities\BlockAbilities::recommend_block( [ "selectedBlock" => [ "blockName" => "core/paragraph", "attributes" => [ "content" => "Hello" ] ] ] );
echo is_wp_error( $r ) ? ( "WP_ERROR: " . $r->get_error_code() ) : ( "OK coverage=" . ( $r["docsGrounding"]["coverage"]["status"] ?? "none" ) );
' --allow-root
```
Expected: `OK coverage=missing-current-release-cycle` (proceeds with the warning) — **not** `WP_ERROR: flavor_agent_docs_grounding_unavailable`. (Pattern surface requires a non-empty `visiblePatternNames`; the block ability is the cleaner gate probe.)

- [ ] **Step 7: Final review of the diff**

```bash
git diff master --stat
```
Confirm only the files listed in File Structure changed.

---

## Self-Review (run before handing off)

**1. Spec coverage:**
- Degrade-to-warn for `missing-current-release-cycle` → Task 1 ✓
- Narrow hard-block kept (`missing-developer-docs` + transport) → Task 1 (`coverage_indicates_hard_block` + two tests) ✓
- Constant redefinition → Task 6 docs ✓ (no code gate beyond Task 1; constant only toggles the probe/warning)
- Drop grace machinery (mechanism A) → Task 2 ✓
- Remove dead gate-block diagnostic → Task 3 ✓
- Output-schema contract (`Registration.php`) → Task 4 ✓
- Orphaned-option cleanup → Task 5 ✓
- Keep mechanism B + probe/cache → Tasks 2/3 explicitly leave them ✓
- No client change → confirmed (Task 7 Step 4 guards JS regressions) ✓
- Cross-surface release gate (Playwright harnesses or explicit waiver) → Task 7 Step 5 ✓

**2. Placeholder scan:** No TBD/TODO. Test removals name exact methods; new/rewritten and negative-assertion tests carry full code; doc edits carry the substantive new framing including the retained `missing-developer-docs` block. ✓

**3. Type/name consistency:** `coverage_indicates_hard_block` defined and referenced in Task 1; status strings (`missing-developer-docs`, `unavailable`, `missing-current-release-cycle`) consistent across resolve_status, tests, and the live probe; removed symbols verified absent via grep steps (Task 2 Step 6, Task 3 Step 6). ✓
