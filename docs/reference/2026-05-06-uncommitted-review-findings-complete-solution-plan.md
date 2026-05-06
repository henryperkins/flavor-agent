# Uncommitted Review Findings Complete Solution Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve the confirmed uncommitted-change review follow-up by aligning the Cloudflare AI Search owner-marker contract with current Cloudflare documentation and removing stale private pattern-index setup instructions from public/operator docs.

**Architecture:** Keep `PatternSearchInstanceManager` as the single owner of managed Cloudflare AI Search instance adoption and owner-marker validation. Current Cloudflare Items List documentation includes `metadata_filter`, so the runtime follow-up is a contract reconciliation and regression-test hardening task, not a production-code removal task. Documentation should describe the managed `flavor-agent-patterns-{site_hash}` pattern instance consistently across release-facing and operator-facing surfaces.

**Tech Stack:** WordPress plugin PHP, PHPUnit, WordPress docs/readme markdown, Cloudflare AI Search REST API, repo-native docs validation.

---

## Confirmed Review Follow-Up

1. The review finding about `metadata_filter` being unsupported must be corrected before implementation. Current official Cloudflare Items List documentation lists `metadata_filter`, `item_id`, `page`, `per_page`, `search`, `sort_by`, `source`, and `status` as query parameters for instance items: <https://developers.cloudflare.com/api/resources/ai_search/subresources/namespaces/subresources/instances/subresources/items/methods/list>. The complete solution is to preserve the current filtered owner-marker lookup and add a focused test/documentation note so future changes do not remove the documented filter or replace it with brittle first-page scanning.

2. Public/operator docs still mention a manually saved private pattern index name even though the uncommitted settings/runtime path now creates or adopts the deterministic managed `flavor-agent-patterns-{site_hash}` AI Search instance from the saved Embedding Model account, token, and model.

## File Responsibility Map

- `inc/Cloudflare/PatternSearchInstanceManager.php`: Owns managed instance ID derivation, create/adopt behavior, owner-marker upload, and owner-marker validation through the documented Cloudflare Items List `metadata_filter` query.
- `tests/phpunit/CloudflarePatternSearchInstanceManagerTest.php`: Pins the Cloudflare owner-marker validation request shape so adoption remains filtered by the Flavor Agent owner marker and does not depend on unfiltered item-list pagination.
- `readme.txt`: Release-facing setup and external-service disclosure text for WordPress.org/plugin users.
- `docs/reference/external-service-disclosure.md`: Canonical external-service disclosure inventory and background job disclosure.
- `docs/reference/abilities-and-routes.md`: Ability readiness and backend matrix contract.
- `docs/reference/provider-precedence.md`: Runtime ownership contract for chat, embeddings, Qdrant, and Cloudflare AI Search.
- `docs/reference/pattern-recommendation-debugging.md`: Operator debugging flow for pattern storage readiness, sync, retrieval, and Cloudflare AI Search conflict recovery.

## Task 1: Reconcile And Pin The Cloudflare Owner-Marker API Contract

**Files:**
- Modify: `tests/phpunit/CloudflarePatternSearchInstanceManagerTest.php`
- Review only unless tests expose drift: `inc/Cloudflare/PatternSearchInstanceManager.php`

- [ ] **Step 1: Confirm the current documented query contract**

Use the current official Cloudflare Items List API reference before changing code:

```text
https://developers.cloudflare.com/api/resources/ai_search/subresources/namespaces/subresources/instances/subresources/items/methods/list
```

Expected contract:

```text
metadata_filter is documented as an optional query parameter.
per_page is documented and supports the current minimum value of 5.
The owner-marker validation request should keep using metadata_filter instead of unfiltered pagination.
```

- [ ] **Step 2: Strengthen the owner-marker request-shape test**

In `tests/phpunit/CloudflarePatternSearchInstanceManagerTest.php`, update `test_ensure_managed_instance_creates_when_missing()` after the existing `remote_get_calls[1]` assertions so the test verifies the exact documented filter payload and `per_page` value.

```php
$owner_marker_url = WordPressTestState::$remote_get_calls[1]['url'];
$query            = [];

parse_str( (string) parse_url( $owner_marker_url, PHP_URL_QUERY ), $query );

$this->assertSame( '5', (string) ( $query['per_page'] ?? '' ) );
$this->assertArrayHasKey( 'metadata_filter', $query );

$filter = json_decode( (string) $query['metadata_filter'], true );

$this->assertIsArray( $filter );
$this->assertSame(
	[ '$eq' => PatternSearchInstanceManager::OWNER_MARKER_NAME ],
	$filter['pattern_name'] ?? null
);
$this->assertSame( [ '$eq' => 'flavor_agent_owner' ], $filter['candidate_type'] ?? null );
$this->assertSame( [ '$eq' => 'flavor_agent' ], $filter['source'] ?? null );
$this->assertSame(
	[ '$eq' => PatternSearchInstanceManager::site_hash() ],
	$filter['synced_id'] ?? null
);
```

- [ ] **Step 3: Keep production code unchanged unless the test reveals drift**

Review `inc/Cloudflare/PatternSearchInstanceManager.php`.

Expected current implementation to preserve:

```php
'metadata_filter' => self::encode_json( self::owner_marker_filter() ),
'per_page'        => 5,
```

Do not replace this with unfiltered item pagination. If the test from Step 2 fails because the current request shape has drifted, update only `owner_marker_list_url()` so it emits the documented `metadata_filter` and `per_page=5` query.

- [ ] **Step 4: Run the focused manager test**

Run:

```bash
composer run test:php -- --filter 'CloudflarePatternSearchInstanceManagerTest'
```

Expected:

```text
OK
```

## Task 2: Replace Stale Private Pattern Index Name Setup Text

**Files:**
- Modify: `readme.txt`
- Modify: `docs/reference/external-service-disclosure.md`
- Modify: `docs/reference/abilities-and-routes.md`
- Modify: `docs/reference/provider-precedence.md`
- Modify: `docs/reference/pattern-recommendation-debugging.md`

- [ ] **Step 1: Update `readme.txt` setup ownership**

Replace the Cloudflare AI Search sentence in `readme.txt` setup ownership with text that describes managed instance creation/adoption:

```text
* One embedding model is configured in `Settings > Flavor Agent` for Flavor Agent semantic features. Pattern storage is configured separately: Qdrant uses that embedding model plus Qdrant, while Cloudflare AI Search reuses the same Cloudflare credentials to create or adopt a managed private `flavor-agent-patterns-{site_hash}` AI Search pattern instance.
```

- [ ] **Step 2: Update `readme.txt` external service disclosure**

Replace the Cloudflare AI Search private pattern retrieval bullet with:

```text
* Cloudflare AI Search for private pattern retrieval — Used when the Cloudflare AI Search pattern backend is selected and the Cloudflare Workers AI Embedding Model account ID, API token, and embedding model are saved in `Settings > Flavor Agent`. Flavor Agent creates or adopts the deterministic managed `flavor-agent-patterns-{site_hash}` AI Search pattern instance in the `patterns` namespace and validates its metadata schema, owner marker, and embedding model before using it. Requests can include managed-instance list/create calls, owner-marker reads/uploads, pattern item uploads with title, description, categories, block/template metadata, inferred traits, public-safe pattern content, synced identifiers/status, recommendation query text, and visible pattern names as nested AI Search retrieval filters. Sync uploads only public-safe registered patterns and published user `wp_block` patterns across synced, partial, and unsynced states, deletes only stale remote items previously recorded by Flavor Agent, and preserves unknown remote items and the owner marker; recommendation requests re-check current synced-pattern status/readability before ranking or returning results. Cloudflare Terms: https://www.cloudflare.com/terms/ Privacy: https://www.cloudflare.com/privacypolicy/
```

- [ ] **Step 3: Update `docs/reference/external-service-disclosure.md`**

Revise the private pattern retrieval row setup column so it says:

```text
Requires selecting the Cloudflare AI Search pattern backend and saving Cloudflare Workers AI Embedding Model account ID, API token, and embedding model in `Settings > Flavor Agent`. Flavor Agent creates or adopts the deterministic managed `flavor-agent-patterns-{site_hash}` AI Search pattern instance in the `patterns` namespace, validates its metadata schema, owner marker, and embedding model, and stores the validated signature before sync/retrieval use. This private pattern backend is separate from the built-in public WordPress developer-docs AI Search endpoint. Pattern reranking still requires chat through `Settings > Connectors`.
```

Revise the `flavor_agent_reindex_patterns` scheduling row so its Cloudflare AI Search gate says:

```text
No. `schedule_sync()` returns unless the selected pattern backend is configured: embeddings plus Qdrant for Qdrant, or Cloudflare Workers AI account/token/model signature plus a validated managed Cloudflare AI Search pattern instance for Cloudflare AI Search.
```

- [ ] **Step 4: Update `docs/reference/abilities-and-routes.md` readiness text**

Replace the `flavor-agent/recommend-patterns` Cloudflare AI Search readiness fragment with:

```text
Cloudflare AI Search backend requires Cloudflare Workers AI account/token/model signature validation plus the validated managed `flavor-agent-patterns-{site_hash}` AI Search pattern instance
```

Replace the `configured` paragraph's Cloudflare AI Search sentence with:

```text
For Cloudflare AI Search, that means the Cloudflare Workers AI account ID, API token, embedding model, and validated managed `flavor-agent-patterns-{site_hash}` AI Search pattern instance are ready.
```

Replace the Cloudflare AI Search row in the backend matrix with:

```markdown
| Cloudflare AI Search | AI Search managed embedding model | Cloudflare AI Search | Cloudflare AI Search | AI Search `ai_search_options.retrieval.filters.pattern_name` | Cloudflare Workers AI account/token plus embedding-model signature validation, validated managed `flavor-agent-patterns-{site_hash}` instance, Connectors chat |
```

- [ ] **Step 5: Update `docs/reference/provider-precedence.md`**

Replace the readiness bullet with:

```text
6. The Cloudflare AI Search pattern backend can be ready when the Cloudflare Workers AI account ID, API token, embedding model, and deterministic managed `flavor-agent-patterns-{site_hash}` AI Search pattern instance are validated because pattern sync/retrieval uses managed indexing/search instead of `EmbeddingClient`; save-time setup still validates the shared Workers AI credentials before creating or adopting that managed instance.
```

Replace the `cloudflare_ai_search` table setup cell with:

```text
Cloudflare Workers AI account/token/model signature; validated managed `flavor-agent-patterns-{site_hash}` instance; Connectors chat.
```

- [ ] **Step 6: Update `docs/reference/pattern-recommendation-debugging.md`**

Replace the fast mental model Cloudflare AI Search path with:

```text
Inspect the Embedding Model Cloudflare credentials, managed `flavor-agent-patterns-{site_hash}` instance validation, filterable metadata schema, owner marker, item sync state, search chunks, filters, and synced-pattern rehydration.
```

Replace the missing-credentials interpretation with:

```text
- if validation reports missing credentials, fix the Embedding Model account ID, API token, or embedding model first, then save Pattern Storage again so Flavor Agent can create or adopt the managed AI Search pattern instance
```

Replace the unavailable-backend setup text with:

```text
- or the selected Cloudflare AI Search pattern backend does not have Cloudflare Workers AI account/token/model signature validation and a validated managed AI Search pattern instance
```

Replace the setup checklist item with:

```text
- configure the selected pattern backend in `Settings > Flavor Agent`: plugin-owned embeddings and Qdrant for Qdrant, or Cloudflare Workers AI account/token/model signature validation plus the validated managed Cloudflare AI Search pattern instance for AI Search
```

Replace the diagnostic metadata bullet with:

```text
- embedding model for Qdrant or Cloudflare AI Search managed instance validation
```

Replace the namespace/instance confirmation with:

```text
- confirm the AI Search namespace is the fixed `patterns` namespace and the runtime instance is the deterministic managed `flavor-agent-patterns-{site_hash}` instance
```

- [ ] **Step 7: Search for remaining stale setup wording**

Run:

```bash
rg -n "private pattern index name|pattern index name" readme.txt docs/reference/external-service-disclosure.md docs/reference/abilities-and-routes.md docs/reference/provider-precedence.md docs/reference/pattern-recommendation-debugging.md
```

Expected:

```text
No matches.
```

If the command reports a line that still instructs users to save or configure a private pattern index name, replace it with managed-instance language consistent with the snippets above.

## Task 3: Run Focused Validation And Record The Result

**Files:**
- Verify only: `tests/phpunit/CloudflarePatternSearchInstanceManagerTest.php`
- Verify only: `readme.txt`
- Verify only: `docs/reference/external-service-disclosure.md`
- Verify only: `docs/reference/abilities-and-routes.md`
- Verify only: `docs/reference/provider-precedence.md`
- Verify only: `docs/reference/pattern-recommendation-debugging.md`

- [ ] **Step 1: Run whitespace checks**

Run:

```bash
git diff --check
git diff --cached --check
```

Expected:

```text
No output and exit code 0 from both commands.
```

- [ ] **Step 2: Run the focused PHP regression test**

Run:

```bash
composer run test:php -- --filter 'CloudflarePatternSearchInstanceManagerTest'
```

Expected:

```text
OK
```

- [ ] **Step 3: Run the docs gate**

Run:

```bash
npm run check:docs
```

Expected:

```text
docs check passes with exit code 0.
```

- [ ] **Step 4: Run the broader targeted PHP suite if production PHP changed**

Run this only if implementation changed `inc/Cloudflare/PatternSearchInstanceManager.php` beyond test-only assertions:

```bash
composer run test:php -- --filter 'CloudflarePatternSearchInstanceManagerTest|CloudflarePatternSearchClientTest|PatternIndexTest|SettingsTest|SettingsRegistrarTest|InfraAbilitiesTest|PatternAbilitiesTest'
```

Expected:

```text
OK
```

- [ ] **Step 5: Close the review finding record**

In the implementation closeout, state both outcomes plainly:

```text
Cloudflare owner-marker API contract: reconciled against current Cloudflare docs; metadata_filter is documented and preserved; request-shape coverage added.
Documentation drift: stale private pattern index name setup instructions replaced with managed instance setup/validation language.
```
