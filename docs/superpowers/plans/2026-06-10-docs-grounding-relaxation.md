# Docs Grounding Relaxation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make developer-docs grounding best-effort prompt context that never blocks a recommendation — the only failure mode is the backend being down — by deleting the runtime freshness/coverage/grace/timeout machinery and delegating trust + currency to `scripts/update-docs-ai-search.js`.

**Architecture:** Each recommendation surface runs one cached best-effort corpus search; chunks are attached to the prompt if present, absent otherwise; nothing about grounding returns a `WP_Error`. The grading/coverage/grace/warm subsystems and the runtime trusted-source re-classifier are removed; `DocsGuidanceResult` and `DocsGroundingSourcePolicy` collapse to content-fingerprinting and non-gating source labeling.

**Tech Stack:** PHP 8.2 (PSR-4 `FlavorAgent\`), PHPUnit; `@wordpress/scripts` + Jest for JS; WordPress transients + AI Client.

**Spec:** `docs/superpowers/specs/2026-06-10-docs-grounding-relaxation-design.md`

**Conventions used throughout this plan:**
- Run PHP tests: `vendor/bin/phpunit --filter <TestName>`
- Run all PHP tests: `vendor/bin/phpunit`
- Run JS tests: `npm run test:unit -- --runInBand -t "<name>"`
- Build JS before any WP manual check: `npm run build`
- Each task ends green + one commit. Commit messages use the repo convention; end every commit body with `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

**Test harness (PHPUnit):** these suites use the in-repo `WordPressTestState` double, not real HTTP/WP.
- Stub the AI Search HTTP response: `WordPressTestState::$remote_post_response = $this->trusted_docs_response( ... )` (a canned chunk body; see `AISearchClientTest`), or a sequence via `WordPressTestState::$remote_post_responses = [ ... ]`. Simulate backend-down with `WordPressTestState::$remote_post_response = new \WP_Error( 'http_request_failed', 'down' )`. Inspect calls via `WordPressTestState::$remote_post_calls` / `$last_remote_post`.
- Stub the model: `WordPressTestState::$ai_client_generate_text_result = wp_json_encode( [ ... ] )`; assert it was (not) called via `WordPressTestState::$last_ai_client_prompt`.
- Prime the grounding cache directly: `WordPressTestState::$transients[ $this->build_cache_key( $query, 4 ) ] = [ ...chunks ]` (`build_cache_key` is a per-file helper; `4` is the default max-results). Empty `WordPressTestState::$transients = []` → cache miss.
- Connector setup: `$this->configure_text_generation_connector()`.

---

## Names locked for cross-task consistency

These signatures are referenced by multiple tasks. Use them verbatim.

- `AISearchClient::maybe_search_best_effort( string $query, ?int $max_results = null ): array` — cache → live search (20s) → cache → guidance, or `[]` on transport error. Records the minimal runtime signal.
- `AISearchClient::maybe_search( string $query, ?int $max_results = null ): array` — **kept**, cache-only read (already exists), used by the signature path.
- `AISearchClient::get_runtime_state(): array` — returns `[ 'status' => 'ok'|'unreachable'|'off', 'lastSearchAt' => string, 'lastResultCount' => int ]`.
- `DocsGuidanceResult::from_guidance( array $guidance, string $mode, string $transport ): array` — returns `[ 'mode', 'transport', 'guidance', 'sourceTypes', 'count', 'available', 'fingerprint' ]`. No `$options`, no `status`, no `coverage`, no `freshness`.
- `DocsGuidanceResult::guidance( array $result ): array` — **kept** unchanged.
- `DocsGuidanceResult::public_summary( array $result ): array` — returns `[ 'available' => bool, 'sourceTypes' => string[], 'count' => int ]`.
- `DocsGroundingSourcePolicy::label_for_url( string $url ): string` — non-gating label: `developer-blog` for `developer.wordpress.org/news/...`, `make-core` for `make.wordpress.org/core/...`, else `developer-docs`. Never returns `''` to drop a chunk; labeling only.
- `CollectsDocsGuidance::collect_result( callable $build_query, array $context, string $prompt, array $options = [] ): array` — one query builder only; `$options['mode']` is `'recommendation'` (live) or `'signature'` (cache-only).

---

## Task 1: Remove the 503 gate from all five abilities

This is the immediate unblock. After this task recommendations never 503 on grounding; the rest of the plan removes now-dead machinery.

**Files:**
- Modify: `inc/Abilities/BlockAbilities.php:103-105`
- Modify: `inc/Abilities/PatternAbilities.php:565-567`
- Modify: `inc/Abilities/StyleAbilities.php:139-141`
- Modify: `inc/Abilities/NavigationAbilities.php:83-85`
- Modify: `inc/Abilities/TemplateAbilities.php:96-98` and `:227-229`
- Test: `tests/phpunit/BlockAbilitiesTest.php`, `tests/phpunit/PatternAbilitiesTest.php`, `tests/phpunit/StyleAbilitiesTest.php`, `tests/phpunit/NavigationAbilitiesTest.php`, `tests/phpunit/TemplateAbilitiesTest.php`

- [ ] **Step 1: Replace the block "fails-closed" test with a "proceeds" test**

In `tests/phpunit/BlockAbilitiesTest.php`, replace `test_recommend_block_fails_closed_when_docs_grounding_is_unavailable` (line 630) with the test below. It keeps that test's exact setup (`configure_text_generation_connector`, empty `$transients`, the same model stub) but flips the expectation: with no grounding the recommendation proceeds and the model **is** called. With `$transients = []` and recommendation mode, `maybe_search_best_effort` does a cache-miss live search; `WordPressTestState::$remote_post_response` is unset here so the search yields no chunks → `available: false` — exactly the "empty grounding" path.

```php
public function test_recommend_block_proceeds_when_docs_grounding_is_empty(): void {
    $this->configure_text_generation_connector();
    WordPressTestState::$transients                     = [];
    WordPressTestState::$ai_client_generate_text_result = wp_json_encode(
        [ 'settings' => [], 'styles' => [], 'block' => [], 'explanation' => 'Proceeds without the grounding gate.' ]
    );

    $result = BlockAbilities::recommend_block(
        [ 'selectedBlock' => [ 'blockName' => 'core/paragraph', 'attributes' => [ 'content' => 'Hello world' ] ] ]
    );

    $this->assertIsArray( $result );
    $this->assertFalse( is_wp_error( $result ), 'grounding must never block a recommendation' );
    $this->assertFalse( $result['docsGrounding']['available'] ?? true );
    $this->assertSame( 0, $result['docsGrounding']['count'] ?? -1 );
    $this->assertNotSame( [], WordPressTestState::$last_ai_client_prompt, 'model must be called' );
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter test_recommend_block_proceeds_when_docs_grounding_is_empty`
Expected: FAIL — currently returns `WP_Error` `flavor_agent_docs_grounding_unavailable` (503).

- [ ] **Step 3: Remove the gate in `BlockAbilities`**

Delete these three lines at `inc/Abilities/BlockAbilities.php:103-105`:

```php
		if ( ! DocsGuidanceResult::is_actionable( $docs_result ) ) {
			return DocsGuidanceResult::unavailable_error( $docs_result );
		}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter test_recommend_block_proceeds_when_docs_grounding_is_empty`
Expected: PASS

- [ ] **Step 5: Remove the gate in the other four abilities and update their tests the same way**

Delete the identical 3-line `if ( ! DocsGuidanceResult::is_actionable( ... ) ) { return DocsGuidanceResult::unavailable_error( ... ); }` block at each remaining site:
- `inc/Abilities/PatternAbilities.php:565-567`
- `inc/Abilities/StyleAbilities.php:139-141`
- `inc/Abilities/NavigationAbilities.php:83-85`
- `inc/Abilities/TemplateAbilities.php:96-98`
- `inc/Abilities/TemplateAbilities.php:227-229`

In each of `PatternAbilitiesTest`, `StyleAbilitiesTest`, `NavigationAbilitiesTest`, `TemplateAbilitiesTest`: rename/convert any `*_fails_closed_when_docs_grounding_is_unavailable` test to a `*_proceeds_when_docs_grounding_is_empty` test asserting `assertFalse( is_wp_error( $result ) )` and the surface's normal payload key (e.g. templates: `operations`; navigation: the advisory payload key; pattern: `recommendations`). Leave the signature-only assertions for Step-of-Task-4.

- [ ] **Step 6: Run the five ability suites**

Run: `vendor/bin/phpunit --filter "BlockAbilitiesTest|PatternAbilitiesTest|StyleAbilitiesTest|NavigationAbilitiesTest|TemplateAbilitiesTest"`
Expected: PASS, except any tests asserting the old `docsGrounding.status`/coverage shape — those are updated in Tasks 4–5. If a test references `docsGrounding.status` or `flavor_agent_docs_grounding_require_current_coverage`, mark it `@group docs-grounding-legacy` and skip with `$this->markTestSkipped('updated in Task 4/5')` so the suite stays green; the skip is removed when that task lands.

- [ ] **Step 7: Commit**

```bash
git add inc/Abilities tests/phpunit
git commit -m "Remove docs-grounding 503 gate from recommendation abilities"
```

---

## Task 2: Collapse the grounding fetch to one cached best-effort search

**Files:**
- Modify: `inc/Support/CollectsDocsGuidance.php`
- Modify: `inc/Abilities/BlockAbilities.php` (`collect_wordpress_docs_guidance_result`, remove `build_wordpress_docs_entity_key` + `build_wordpress_docs_family_context`)
- Modify: `inc/Abilities/PatternAbilities.php`, `inc/Abilities/StyleAbilities.php`, `inc/Abilities/NavigationAbilities.php`, `inc/Abilities/TemplateAbilities.php` (same)
- Add: `AISearchClient::maybe_search_best_effort` (implemented in Task 3; here just call it)
- Test: `tests/phpunit/CollectsDocsGuidanceTest.php` (create if absent)

- [ ] **Step 1: Write a test for the simplified collect path**

Create `tests/phpunit/CollectsDocsGuidanceTest.php` (copy a `build_cache_key` helper + `trusted_docs_response` from `AISearchClientTest`, or call `AISearchClient` through the same `WordPressTestState` doubles):

```php
public function test_collect_result_recommendation_mode_uses_best_effort_and_wraps_result(): void {
    WordPressTestState::$transients           = [];
    WordPressTestState::$remote_post_response = $this->trusted_docs_response( 2 ); // canned 2-chunk AI Search body
    $result = CollectsDocsGuidance::collect_result(
        static fn( array $c, string $p ): string => 'paragraph typography',
        [ 'block' => [ 'name' => 'core/paragraph' ] ],
        'make it punchier',
        [ 'mode' => 'recommendation' ]
    );

    $this->assertTrue( $result['available'] );
    $this->assertSame( 2, $result['count'] );
    $this->assertNotSame( '', $result['fingerprint'] );
}
```

(`trusted_docs_response( int $count )` builds a canned Cloudflare AI Search HTTP body; copy it from `AISearchClientTest`.)

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter test_collect_result_recommendation_mode_uses_best_effort_and_wraps_result`
Expected: FAIL — `collect_result` still has the old 4-arg/coverage signature.

- [ ] **Step 3: Rewrite `CollectsDocsGuidance::collect` and `collect_result`**

Replace both methods with:

```php
public static function collect( callable $build_query, array $context, string $prompt, array $options = [] ): array {
    $query = (string) $build_query( $context, $prompt );

    if ( '' === $query ) {
        $docs_guidance = [];
    } elseif ( 'signature' === (string) ( $options['mode'] ?? 'recommendation' ) ) {
        $docs_guidance = AISearchClient::maybe_search( $query );
    } else {
        $docs_guidance = AISearchClient::maybe_search_best_effort( $query );
    }

    $roadmap_guidance = CoreRoadmapGuidance::collect( $context, [ 'sideEffects' => false ] );

    if ( [] === $roadmap_guidance ) {
        return $docs_guidance;
    }
    if ( [] === $docs_guidance ) {
        return $roadmap_guidance;
    }
    return self::merge_guidance_chunks( $docs_guidance, $roadmap_guidance );
}

public static function collect_result( callable $build_query, array $context, string $prompt, array $options = [] ): array {
    $guidance = self::collect( $build_query, $context, $prompt, $options );

    return DocsGuidanceResult::from_guidance(
        $guidance,
        (string) ( $options['mode'] ?? 'recommendation' ),
        'best-effort'
    );
}
```

Keep `merge_guidance_chunks` and `order_guidance_chunks` as-is. Remove the `MAX_ROADMAP_CHUNKS_BEFORE_DOCS`-only logic only if it becomes unused (it does not — `order_guidance_chunks` still uses it).

- [ ] **Step 4: Simplify each ability's `collect_wordpress_docs_guidance_result`**

In `BlockAbilities.php` replace the method body with (other four abilities: identical except the `build_wordpress_docs_query` is theirs):

```php
private static function collect_wordpress_docs_guidance_result( array $context, string $prompt, array $options = [] ): array {
    return CollectsDocsGuidance::collect_result(
        static fn( array $request_context, string $request_prompt ): string => self::build_wordpress_docs_query( $request_context, $request_prompt ),
        $context,
        $prompt,
        [ 'mode' => empty( $options['signatureOnly'] ) ? 'recommendation' : 'signature' ]
    );
}
```

Then delete the now-unused `build_wordpress_docs_entity_key` and `build_wordpress_docs_family_context` from each of the five abilities. (Grep each file for those two method names; remove the definitions. `build_wordpress_docs_query` stays.)

- [ ] **Step 5: Run the collect test + the five ability suites**

Run: `vendor/bin/phpunit --filter "CollectsDocsGuidanceTest|BlockAbilitiesTest|PatternAbilitiesTest|StyleAbilitiesTest|NavigationAbilitiesTest|TemplateAbilitiesTest"`
Expected: PASS (legacy-shape tests still skipped from Task 1).

- [ ] **Step 6: Commit**

```bash
git add inc/Support/CollectsDocsGuidance.php inc/Abilities tests/phpunit/CollectsDocsGuidanceTest.php
git commit -m "Collapse docs-grounding fetch to one cached best-effort search"
```

---

## Task 3: Strip `AISearchClient` to search + cache + minimal signal

**Files:**
- Modify: `inc/Cloudflare/AISearchClient.php`
- Modify: lifecycle/cron wiring (grep targets below)
- Modify: `inc/Abilities/InfraAbilities.php` (runtime-state consumer)
- Test: `tests/phpunit/AISearchClientTest.php`

- [ ] **Step 1: Write the best-effort + runtime-signal tests**

In `tests/phpunit/AISearchClientTest.php` add:

```php
public function test_maybe_search_best_effort_returns_chunks_on_success(): void {
    WordPressTestState::$transients           = [];
    WordPressTestState::$remote_post_response = $this->trusted_docs_response( 2 );
    $guidance = AISearchClient::maybe_search_best_effort( 'block editor typography' );
    $this->assertCount( 2, $guidance );
    $this->assertSame( 'ok', AISearchClient::get_runtime_state()['status'] );
}

public function test_maybe_search_best_effort_returns_empty_and_marks_unreachable_on_transport_error(): void {
    WordPressTestState::$transients           = [];
    WordPressTestState::$remote_post_response = new \WP_Error( 'http_request_failed', 'down' );
    $guidance = AISearchClient::maybe_search_best_effort( 'block editor typography' );
    $this->assertSame( [], $guidance );
    $this->assertSame( 'unreachable', AISearchClient::get_runtime_state()['status'] );
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter "test_maybe_search_best_effort"`
Expected: FAIL — method does not exist.

- [ ] **Step 3: Add `maybe_search_best_effort` + minimal runtime signal; delete the machinery**

Add:

```php
public static function maybe_search_best_effort( string $query, ?int $max_results = null ): array {
    $query = sanitize_textarea_field( $query );
    if ( $query === '' || ! self::is_configured() ) {
        return [];
    }

    $limit  = self::normalize_max_results( $max_results );
    $cached = self::read_cached_guidance( $query, $limit );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    $result = self::search_live( $query, $limit );
    if ( is_wp_error( $result ) ) {
        self::write_runtime_signal( 'unreachable', 0 );
        return [];
    }

    self::write_runtime_signal( 'ok', count( $result['guidance'] ) );
    return $result['guidance'];
}

private static function write_runtime_signal( string $status, int $count ): void {
    update_option(
        self::RUNTIME_STATE_OPTION,
        [
            'status'          => $status,
            'lastSearchAt'    => gmdate( 'Y-m-d H:i:s' ),
            'lastResultCount' => $count,
        ],
        false
    );
}

public static function get_runtime_state(): array {
    if ( ! self::is_configured() ) {
        return [ 'status' => 'off', 'lastSearchAt' => '', 'lastResultCount' => 0 ];
    }
    $state = get_option( self::RUNTIME_STATE_OPTION, [] );
    return [
        'status'          => in_array( (string) ( $state['status'] ?? '' ), [ 'ok', 'unreachable' ], true ) ? (string) $state['status'] : 'ok',
        'lastSearchAt'    => (string) ( $state['lastSearchAt'] ?? '' ),
        'lastResultCount' => (int) ( $state['lastResultCount'] ?? 0 ),
    ];
}
```

Reduce `search_live` to: sanitize → `get_config` (on error return it) → `request_search` (on error return it) → `normalize_chunks` → `write_cached_guidance` → return `[ 'query', 'guidance' ]`. Remove the grace fallback (`get_last_known_current_guidance_for_grace`), the `record_runtime_search_*` calls, and the `$runtime_mode`/`$timeout` params (use the 20s default in `request_search`).

Delete these methods/constants entirely (grep to confirm no remaining callers after Task 2):
`get_current_source_coverage`, `requires_current_source_coverage`, `probe_current_source_coverage`, `normalize_source_coverage_summary`, `write_source_coverage_cache`, `source_coverage_cache_ttl`, `SOURCE_COVERAGE_*` consts, `maybe_search_with_cache_fallbacks`, `maybe_search_family`, `maybe_search_entity`, `warm_entity`, `cache_entity_guidance`, `cache_family_guidance`, `warm_context`, `maybe_foreground_warm_context`, `resolve_foreground_warm_timeout`, `get_last_known_current_guidance_for_grace`, `resolve_generic_entity_fallback`, `build_family_cache_key`, `build_entity_cache_key`, `read_cached_guidance_by_key`(if now unused), `schedule_context_warm`, `process_context_warm_queue`, `resolve_next_context_warm_attempt`, `read_context_warm_queue`, `write_context_warm_queue`, `sync_context_warm_schedule`, `normalize_context_warm_queue_entry`, `build_context_warm_queue_key`, `build_retry_context_warm_queue_entry`, `prewarm`, `schedule_prewarm`, `should_prewarm`, `get_prewarm_state`, `get_warm_set`, `record_runtime_search_error`, `record_runtime_search_empty_result`, `record_runtime_search_success`, `record_runtime_served_guidance`, `apply_runtime_guidance_diagnostics`, `resolve_runtime_status`, `resolve_runtime_state_status`, `read_runtime_state`, `write_runtime_state`, `derive_prewarm_status`, and the `FOREGROUND_WARM_*`, `FAMILY_CACHE_TTL`, `ENTITY_CACHE_TTL`, `LAST_KNOWN_CURRENT_GRACE_TTL`, `WARM_QUEUE_OPTION`, `PREWARM_STATE_OPTION`, `CONTEXT_WARM_CRON_HOOK` consts. Keep `CACHE_TTL`, `RUNTIME_STATE_OPTION`, `search`, `maybe_search`, `search_live`, `request_search`, `extract_search_chunks`, `normalize_chunks`, `read/write_cached_guidance`, `get_config`, `normalize_max_results`, `is_configured`, `configured_instance_id`, `validate_configuration`.

- [ ] **Step 4: Remove the lifecycle/cron wiring**

Grep across `flavor-agent.php` and `inc/`:
`grep -rn "flavor_agent_warm_docs_context\|schedule_context_warm\|process_context_warm_queue\|->prewarm\|schedule_prewarm\|should_prewarm\|get_prewarm_state\|CONTEXT_WARM_CRON" flavor-agent.php inc/`
Remove each `add_action`/cron registration/activation hook that references the deleted methods. Update `inc/Abilities/InfraAbilities.php` to consume the new `get_runtime_state()` shape (status/lastSearchAt/lastResultCount only; drop `lastFreshness`/`queueDepth`/prewarm fields).

- [ ] **Step 5: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter "AISearchClientTest|InfraAbilitiesTest"`
Expected: PASS. Remove deleted-API tests from `AISearchClientTest` (coverage/warm/prewarm/grace); keep search + cache + best-effort + runtime-signal tests. Delete `tests/phpunit/DocsGroundingEntityCacheTest.php` entirely — it tests the entity/family cache fan-out removed in this task. Grep `tests/phpunit/` for any remaining references to the deleted methods/consts and remove those cases.

- [ ] **Step 6: Commit**

```bash
git add inc/Cloudflare/AISearchClient.php inc/Abilities/InfraAbilities.php flavor-agent.php tests/phpunit/AISearchClientTest.php
git commit -m "Strip AISearchClient to best-effort search, cache, and ok/unreachable signal"
```

---

## Task 4: Simplify `DocsGuidanceResult`

**Files:**
- Modify: `inc/Support/DocsGuidanceResult.php`
- Modify: `inc/Abilities/Registration.php` (`docs_grounding_output_schema`)
- Test: `tests/phpunit/DocsGuidanceResultTest.php`

- [ ] **Step 1: Rewrite the result test for the collapsed shape**

Replace the status/coverage/freshness assertions in `tests/phpunit/DocsGuidanceResultTest.php` with:

```php
public function test_from_guidance_reports_available_with_labels_and_fingerprint(): void {
    $guidance = [
        [ 'url' => 'https://developer.wordpress.org/block-editor/', 'sourceType' => 'developer-docs', 'excerpt' => 'x', 'contentHash' => 'a' ],
        [ 'url' => 'https://make.wordpress.org/core/2026/05/07/x/', 'sourceType' => 'make-core', 'excerpt' => 'y', 'contentHash' => 'b' ],
    ];
    $result = DocsGuidanceResult::from_guidance( $guidance, 'recommendation', 'best-effort' );

    $this->assertTrue( $result['available'] );
    $this->assertSame( 2, $result['count'] );
    $this->assertEqualsCanonicalizing( [ 'developer-docs', 'make-core' ], $result['sourceTypes'] );
    $this->assertNotSame( '', $result['fingerprint'] );

    $summary = DocsGuidanceResult::public_summary( $result );
    $this->assertSame( [ 'available', 'sourceTypes', 'count' ], array_keys( $summary ) );
}

public function test_from_guidance_empty_is_unavailable_with_stable_fingerprint(): void {
    $a = DocsGuidanceResult::from_guidance( [], 'recommendation', 'best-effort' );
    $b = DocsGuidanceResult::from_guidance( [], 'recommendation', 'best-effort' );
    $this->assertFalse( $a['available'] );
    $this->assertSame( 0, $a['count'] );
    $this->assertSame( $a['fingerprint'], $b['fingerprint'] );
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter DocsGuidanceResultTest`
Expected: FAIL — old shape returns `status`, new keys absent.

- [ ] **Step 3: Rewrite `DocsGuidanceResult`**

Replace the class body with these methods (delete `resolve_status`, `normalize_coverage`, `coverage_indicates_hard_block`, `source_coverage_summary`, `has_official_guidance`, `is_actionable`, `unavailable_error`, `official_source_types`, and the coverage/freshness branches):

```php
public static function from_guidance( array $guidance, string $mode, string $transport ): array {
    $normalized   = self::normalize_guidance( $guidance );
    $source_types = self::extract_source_types( $normalized );

    return [
        'mode'        => sanitize_key( $mode ),
        'transport'   => sanitize_key( $transport ),
        'guidance'    => $normalized,
        'sourceTypes' => $source_types,
        'count'       => count( $normalized ),
        'available'   => [] !== $normalized,
        'fingerprint' => self::fingerprint( $normalized ),
    ];
}

public static function guidance( array $result ): array {
    return is_array( $result['guidance'] ?? null ) ? $result['guidance'] : [];
}

public static function public_summary( array $result ): array {
    return [
        'available'   => ! empty( $result['available'] ),
        'sourceTypes' => array_values( array_map( 'sanitize_key', (array) ( $result['sourceTypes'] ?? [] ) ) ),
        'count'       => (int) ( $result['count'] ?? 0 ),
    ];
}

private static function fingerprint( array $guidance ): string {
    $payload = array_map(
        static fn ( array $c ): array => [
            'url'         => (string) ( $c['url'] ?? '' ),
            'sourceType'  => (string) ( $c['sourceType'] ?? '' ),
            'contentHash' => (string) ( $c['contentHash'] ?? '' ),
        ],
        $guidance
    );
    $encoded = wp_json_encode( $payload );
    return hash( 'sha256', false === $encoded ? 'docs-grounding' : $encoded );
}
```

Keep `normalize_guidance` and `extract_source_types` (drop `extract_freshness_values` if now unused).

- [ ] **Step 4: Update the output schema**

In `inc/Abilities/Registration.php`, change `docs_grounding_output_schema()` to:

```php
private static function docs_grounding_output_schema(): array {
    return [
        'type'       => 'object',
        'properties' => [
            'available'   => [ 'type' => 'boolean' ],
            'sourceTypes' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            'count'       => [ 'type' => 'integer' ],
        ],
    ];
}
```

- [ ] **Step 5: Un-skip and fix the signature-only tests**

Remove the `markTestSkipped` guards added in Task 1. Update each ability's `*_resolve_signature_only_includes_docs_grounding_fingerprint` test to assert `$baseline['docsGrounding']['available']` is a bool and `docsGroundingFingerprint` is a non-empty string (drop the `'unavailable' === status` assertion). Delete the coverage tests that use `flavor_agent_docs_grounding_require_current_coverage` (e.g. `BlockAbilitiesTest` ~869-934).

- [ ] **Step 6: Run to verify pass**

Run: `vendor/bin/phpunit --filter "DocsGuidanceResultTest|BlockAbilitiesTest|PatternAbilitiesTest|StyleAbilitiesTest|NavigationAbilitiesTest|TemplateAbilitiesTest"`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add inc/Support/DocsGuidanceResult.php inc/Abilities/Registration.php tests/phpunit
git commit -m "Collapse DocsGuidanceResult to content fingerprint + availability summary"
```

---

## Task 5: Reduce `DocsGroundingSourcePolicy` to a non-gating label + de-trust `normalize_chunks`

**Files:**
- Modify: `inc/Support/DocsGroundingSourcePolicy.php`
- Modify: `inc/Cloudflare/AISearchClient.php` (`normalize_chunks`, `normalize_cached_guidance_item`, remove `is_allowed_guidance_source`/`normalize_trusted_url`/`source_key_matches_trusted_host`)
- Test: `tests/phpunit/DocsGroundingSourcePolicyTest.php`, `tests/phpunit/AISearchClientTest.php`

- [ ] **Step 1: Write the label test**

Replace `DocsGroundingSourcePolicyTest` trust/freshness cases with:

```php
public function test_label_for_url_labels_without_dropping(): void {
    $this->assertSame( 'developer-blog', DocsGroundingSourcePolicy::label_for_url( 'https://developer.wordpress.org/news/2026/05/x/' ) );
    $this->assertSame( 'make-core', DocsGroundingSourcePolicy::label_for_url( 'https://make.wordpress.org/core/2026/05/x/' ) );
    $this->assertSame( 'developer-docs', DocsGroundingSourcePolicy::label_for_url( 'https://developer.wordpress.org/block-editor/' ) );
    $this->assertSame( 'developer-docs', DocsGroundingSourcePolicy::label_for_url( 'https://example.com/whatever' ) );
}
```

Add an `AISearchClientTest` case proving an untrusted-host chunk is **kept** (trust is the ingestion script's job): set `WordPressTestState::$remote_post_response` to a body whose chunk URL is `https://example.com/x`, call `AISearchClient::search( 'q' )`, and assert the returned guidance has count 1 with `sourceType` `developer-docs` (the `label_for_url` fallback).

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter "DocsGroundingSourcePolicyTest|test_normalize_chunks"`
Expected: FAIL — `label_for_url` absent; untrusted chunk currently dropped.

- [ ] **Step 3: Replace the policy class body**

```php
final class DocsGroundingSourcePolicy {
    public const SOURCE_DEVELOPER_DOCS = 'developer-docs';
    public const SOURCE_DEVELOPER_BLOG = 'developer-blog';
    public const SOURCE_MAKE_CORE      = 'make-core';

    public static function label_for_url( string $url ): string {
        $parts = wp_parse_url( trim( $url ) );
        if ( ! is_array( $parts ) ) {
            return self::SOURCE_DEVELOPER_DOCS;
        }
        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        $path = (string) ( $parts['path'] ?? '' );
        if ( 'make.wordpress.org' === $host ) {
            return self::SOURCE_MAKE_CORE;
        }
        if ( 'developer.wordpress.org' === $host && str_starts_with( $path, '/news/' ) ) {
            return self::SOURCE_DEVELOPER_BLOG;
        }
        return self::SOURCE_DEVELOPER_DOCS;
    }
}
```

Delete `TRUSTED_SCOPES`, `classify_url`, `is_trusted_url`, `normalize_trusted_url`, `freshness_status`, `best_freshness_timestamp`, `current_policy_fingerprint`, `CURRENT_RELEASE_PUBLIC_DATE`, `source_coverage_summary`, `normalize_coverage`, and the `path_contains_untrusted_segments` helper **if** it is unused after `normalize_chunks` is updated (grep; it may still guard URL parsing in `normalize_guidance_url` — keep it there if so).

- [ ] **Step 4: De-trust `normalize_chunks` (and the cached-item normalizer)**

In `AISearchClient::normalize_chunks` (~2463): keep a chunk when `text !== '' && url !== ''` (remove the `is_allowed_guidance_source` clause), set `'sourceType' => DocsGroundingSourcePolicy::label_for_url( $url )`, and drop `retrievedAt`/`publishedAt`/`contentHash`/`freshness` derivation from the stored shape — store `[ id, title, sourceKey, sourceType, url, excerpt, score, contentHash ]` (keep `contentHash` from metadata for the fingerprint; drop freshness/dates). In `normalize_cached_guidance_item` (~2400) apply the same: keep on `url`+`excerpt`, label `sourceType` via `label_for_url`, drop the freshness/date branch. Remove `is_allowed_guidance_source`, `source_key_matches_trusted_host`, and `normalize_trusted_url` from `AISearchClient`.

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/phpunit --filter "DocsGroundingSourcePolicyTest|AISearchClientTest"`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add inc/Support/DocsGroundingSourcePolicy.php inc/Cloudflare/AISearchClient.php tests/phpunit
git commit -m "Replace runtime trust/freshness classification with non-gating source labels"
```

---

## Task 6: Collapse the Settings docs indicator

**Files:**
- Modify: `inc/Admin/Settings/State.php:413-463` (docs status block)
- Modify: `inc/Admin/Settings/State.php:37,59,112-113,289,300` (runtime-state reads of removed keys)
- Test: `tests/phpunit/SettingsTest.php` (the existing settings-state coverage; add a focused case there)

- [ ] **Step 1: Write/adjust the settings-signal test**

In `tests/phpunit/SettingsTest.php`, add a test asserting that with `get_runtime_state()` status `ok` the docs group yields no warning block, and with `unreachable` it yields exactly one warning containing "temporarily unavailable". Drive the state via `WordPressTestState::$options[ AISearchClient::RUNTIME_STATE_OPTION ]` (or the option setter the suite already uses) set to `[ 'status' => 'unreachable', ... ]`.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter SettingsTest`
Expected: FAIL

- [ ] **Step 3: Replace the docs status block**

Replace `inc/Admin/Settings/State.php:413-463` with:

```php
if ( Config::GROUP_DOCS === $group && ! empty( $state['docs_configured'] ) ) {
    if ( 'unreachable' === (string) ( $state['runtime_docs_grounding']['status'] ?? 'ok' ) ) {
        $status_blocks[] = [
            'tone'    => 'warning',
            'message' => __( 'Developer Docs grounding is temporarily unavailable (search backend unreachable). Recommendations still run without it.', 'flavor-agent' ),
        ];
    }
}
```

Update the surrounding `runtime_docs_grounding` reads (lines ~37, 59, 112-113, 289, 300) to the new three-key shape; delete references to `lastFreshness`, `prewarm_state`, `queueDepth`, and the `degraded`/`stale`/`retrying`/`warming` arrays.

- [ ] **Step 4: Run to verify pass; then build the admin bundle**

Run: `vendor/bin/phpunit --filter SettingsTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add inc/Admin/Settings/State.php tests/phpunit
git commit -m "Collapse Settings docs indicator to ok/unreachable"
```

---

## Task 7: Simplify the JS docs-grounding handling

**Files:**
- Modify: `src/utils/docs-grounding-warning.js`
- Modify: `src/components/DocsGroundingNotice.js`
- Modify: `src/store/executable-surfaces.js`, `src/store/executable-surface-runtime.js`, `src/store/index.js`
- Modify: `src/inspector/BlockRecommendationsPanel.js`, `src/inspector/NavigationRecommendations.js`, `src/patterns/PatternRecommender.js`, `src/templates/TemplateRecommender.js`, `src/style-book/StyleBookRecommender.js`
- Test: `src/store/__tests__/executable-surfaces.test.js`, `src/store/__tests__/executable-surface-runtime.test.js`, `src/style-book/__tests__/StyleBookRecommender.test.js`

- [ ] **Step 1: Rewrite the warning derivation test**

In `docs-grounding-warning.js`'s test (or add one) assert: `available: false` → a single soft notice object; `available: true` → `null`; no `status`/`coverage`/`stale`/`degraded` branches remain.

- [ ] **Step 2: Run to verify failure**

Run: `npm run test:unit -- --runInBand -t "docs grounding warning"`
Expected: FAIL

- [ ] **Step 3: Rewrite `docs-grounding-warning.js`**

```js
export function deriveDocsGroundingWarning( docsGrounding ) {
	if ( ! docsGrounding || docsGrounding.available !== false ) {
		return null;
	}
	return {
		tone: 'info',
		message: __(
			'Suggestions are running without developer-docs grounding right now. They are still usable; grounding will return when the search backend is reachable.',
			'flavor-agent'
		),
	};
}
```

- [ ] **Step 4: Update `DocsGroundingNotice` and the store/surface consumers**

`DocsGroundingNotice.js`: render the single notice from `deriveDocsGroundingWarning` (drop the stale/degraded/coverage variants). In `executable-surfaces.js` / `executable-surface-runtime.js` / `store/index.js` and the five recommender components, remove any branching on `docsGrounding.status`/`coverage`/`freshness`; key only on `docsGrounding.available`. Delete dead imports.

- [ ] **Step 5: Update the Jest suites**

In the three `__tests__` files, replace `status: 'unavailable'|'stale'|'degraded'` fixtures with `available: false`/`available: true` and drop assertions on the removed branches.

- [ ] **Step 6: Run JS tests + lint + build**

Run: `npm run test:unit -- --runInBand` then `npm run lint:js` then `npm run build`
Expected: PASS / clean / build emits `build/index.js`, `build/admin.js`, `build/activity-log.js`

- [ ] **Step 7: Commit**

```bash
git add src
git commit -m "Simplify JS docs-grounding handling to a single soft notice"
```

---

## Task 8: Documentation + governance updates

**Files:**
- Modify: `docs/reference/governance-layer.md` (line ~33 fail-closed framing; the vocabulary map row if it names docs-grounding status)
- Modify: `CLAUDE.md` (docs-grounding lifecycle, External Services docs row, Support/ notes referencing coverage/prewarm/CoreRoadmap freshness, AISearchClient currentness rules)
- Modify: `docs/FEATURE_SURFACE_MATRIX.md`, `docs/reference/abilities-and-routes.md` (remove the 503 / `flavor_agent_docs_grounding_unavailable`)
- Modify: `STATUS.md` if it logs the gate

- [ ] **Step 1: Rewrite the governance framing**

In `docs/reference/governance-layer.md`, change the executor description from "enforces the docs-grounding **fail-closed** rules" to a best-effort statement, e.g. "attaches best-effort developer-docs grounding when the search backend is reachable (grounding never blocks a recommendation; trust and currency are owned by `scripts/update-docs-ai-search.js`)." Remove any docs-grounding `status`/coverage vocabulary from the thesis→code map.

- [ ] **Step 2: Update `CLAUDE.md` and the reference docs**

Edit the **Docs grounding lifecycle** bullet, the External Services "Cloudflare AI Search docs grounding" row, and any `Support\`/`AISearchClient`/`DocsGroundingSourcePolicy` descriptions that reference coverage, prewarm, freshness windows, or the runtime state machine. Remove the 503 row from `abilities-and-routes.md` and `FEATURE_SURFACE_MATRIX.md`. (Note: some docs `.md` are mixed CRLF — `flavor-agent-readme`, `STATUS`; if editing those, restore HEAD then re-apply with `perl`/`awk` per the repo gotcha, and verify with `git diff --check`.)

- [ ] **Step 3: Run the docs freshness guard**

Run: `npm run check:docs`
Expected: PASS (no stale-doc failures for the edited files)

- [ ] **Step 4: Commit**

```bash
git add docs CLAUDE.md STATUS.md
git commit -m "Document best-effort docs grounding; remove fail-closed/503 references"
```

---

## Task 9: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Aggregate verify (no E2E)**

Run: `node scripts/verify.js --skip-e2e`
Then inspect `output/verify/summary.json` — `status` must be `pass`; `build`, `lint-js`, `unit`, `lint-php`, `test-php` all `pass`. Fix any failures before proceeding. (Use `--skip=lint-plugin` only if WP-CLI/WP root is unavailable; record the skip.)

- [ ] **Step 2: Targeted Playwright (if harnesses available)**

Run: `npm run test:e2e:playground` (block/pattern/navigation) and, if the WP 7.0 harness is up, `npm run test:e2e:wp70` (template/style). If a harness is known-red or unavailable, record the blocker per the cross-surface gates doc rather than silently skipping.

- [ ] **Step 3: Manual confirmation on a live runtime**

`npm run build`, then on the nightly container and on `wp-hperkins-com`: trigger a block recommendation and confirm it returns suggestions on the current index with **no 503**, and that `Settings > AI` Developer Docs shows the ok (or single "temporarily unavailable") indicator. Spot-check: `wp --path=/home/dev/wp-hperkins-com eval 'echo \FlavorAgent\Cloudflare\AISearchClient::get_runtime_state()["status"];'` returns `ok` after a successful recommendation.

- [ ] **Step 4: Final commit (if any verification fixups were needed)**

```bash
git add -A
git commit -m "Verification fixups for docs-grounding relaxation"
```

---

## Self-review notes (for the implementer)

- The five abilities are intentionally edited in lockstep (Tasks 1, 2, 4) — the gate removal, the `collect_wordpress_docs_guidance_result` simplification, and the signature-only test update each touch all five. Do not leave one ability on the old shape.
- `docsGroundingFingerprint` stays in ability output and feeds `RecommendationResolvedSignature`; only its inputs change (content hash). Do not remove it from payloads or schemas.
- Build green is enforced by ordering: Task 1 removes the gate but leaves `is_actionable`/`unavailable_error` defined; they are deleted in Task 4, after the last caller is gone.
- `CoreRoadmapGuidance` and the `request_diagnostic` activity row are untouched.
