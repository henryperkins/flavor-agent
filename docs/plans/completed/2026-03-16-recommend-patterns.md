# Recommend Patterns Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the `flavor-agent/recommend-patterns` ability with Azure OpenAI embeddings, Qdrant vector search, and GPT-5.4 LLM ranking.

**Architecture:** Two independent LLM paths coexist. The existing Anthropic path (`recommend-block`) is untouched. The new path uses Azure OpenAI for embeddings + ranking, Qdrant Cloud for vector storage. Pattern sync builds the index; query-time retrieves and ranks candidates.

**Tech Stack:** PHP 8.0+, WordPress 6.5+, Azure OpenAI (text-embedding-3-large, GPT-5.4), Qdrant Cloud, `@wordpress/scripts` v30 webpack.

**Spec:** `docs/specs/2026-03-16-recommend-patterns-design.md` is the authoritative source for all API contracts, error handling, and behavior. This plan implements that spec.

---

## File Map

### New Files
| File | Responsibility |
|------|---------------|
| `inc/AzureOpenAI/EmbeddingClient.php` | Azure OpenAI `/openai/v1/embeddings` HTTP client |
| `inc/AzureOpenAI/ResponsesClient.php` | Azure OpenAI `/openai/v1/responses` HTTP client (GPT-5.4) |
| `inc/AzureOpenAI/QdrantClient.php` | Qdrant Cloud REST API client |
| `inc/Patterns/PatternIndex.php` | Sync orchestrator: fingerprint, diff, embed, upsert |
| `webpack.config.js` | Multi-entry webpack extending @wordpress/scripts |
| `src/admin/sync-button.js` | Settings page sync button (enqueued only on settings screen) |

### Modified Files
| File | Changes |
|------|---------|
| `inc/Abilities/Registration.php:276-292` | Expand check-status output_schema with `backends` |
| `inc/Abilities/InfraAbilities.php:15-31` | Dynamic `availableAbilities`, `backends` object |
| `inc/Abilities/PatternAbilities.php:21-27` | Replace stub with full recommend_patterns() |
| `inc/Settings.php` | Add Azure OpenAI + Qdrant settings section, sync panel, admin JS enqueue |
| `inc/REST/Agent_Controller.php:14-38` | Add POST /flavor-agent/v1/sync-patterns route |
| `flavor-agent.php:25-31` | Add lifecycle hooks, cron hook registration |
| `docs/specs/2026-03-16-abilities-api-integration-design.md` | Update per spec's Adjacent Spec Update section |

---

## Chunk 1: Contract Alignment + Build Setup

### Task 1: Update check-status Schema in Registration.php

**Files:**
- Modify: `inc/Abilities/Registration.php:276-292`

- [ ] **Step 1: Expand check-status output_schema**

In `register_infra_abilities()`, replace the `check-status` registration's `output_schema` to include the additive `backends` property:

```php
'output_schema' => [
    'type'       => 'object',
    'properties' => [
        'configured'         => [ 'type' => 'boolean' ],
        'model'              => [ 'type' => ['string', 'null'] ],
        'availableAbilities' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
        'backends'           => [
            'type'       => 'object',
            'properties' => [
                'anthropic'    => [
                    'type'       => 'object',
                    'properties' => [
                        'configured' => [ 'type' => 'boolean' ],
                        'model'      => [ 'type' => ['string', 'null'] ],
                    ],
                ],
                'azure_openai' => [
                    'type'       => 'object',
                    'properties' => [
                        'configured'          => [ 'type' => 'boolean' ],
                        'chatDeployment'      => [ 'type' => ['string', 'null'] ],
                        'embeddingDeployment' => [ 'type' => ['string', 'null'] ],
                    ],
                ],
                'qdrant'       => [
                    'type'       => 'object',
                    'properties' => [
                        'configured' => [ 'type' => 'boolean' ],
                    ],
                ],
            ],
        ],
    ],
],
```

- [ ] **Step 2: Commit**

```bash
git add inc/Abilities/Registration.php
git commit -m "feat: expand check-status schema with backends metadata"
```

### Task 2: Make check_status() Dynamic in InfraAbilities.php

**Files:**
- Modify: `inc/Abilities/InfraAbilities.php`

- [ ] **Step 1: Rewrite check_status()**

Replace the entire `check_status()` method with dynamic computation:

```php
public static function check_status( array $input ): array {
    $anthropic_key = get_option( 'flavor_agent_api_key', '' );
    $anthropic_model = get_option( 'flavor_agent_model', 'claude-sonnet-4-20250514' );

    $azure_endpoint   = get_option( 'flavor_agent_azure_openai_endpoint', '' );
    $azure_key        = get_option( 'flavor_agent_azure_openai_key', '' );
    $azure_embedding  = get_option( 'flavor_agent_azure_embedding_deployment', '' );
    $azure_chat       = get_option( 'flavor_agent_azure_chat_deployment', '' );
    $qdrant_url       = get_option( 'flavor_agent_qdrant_url', '' );
    $qdrant_key       = get_option( 'flavor_agent_qdrant_key', '' );

    $anthropic_configured   = ! empty( $anthropic_key );
    $azure_configured       = ! empty( $azure_endpoint ) && ! empty( $azure_key ) && ! empty( $azure_embedding ) && ! empty( $azure_chat );
    $qdrant_configured      = ! empty( $qdrant_url ) && ! empty( $qdrant_key );

    $abilities = [
        'flavor-agent/introspect-block',
        'flavor-agent/list-patterns',
        'flavor-agent/list-template-parts',
        'flavor-agent/get-theme-tokens',
        'flavor-agent/check-status',
    ];

    if ( $anthropic_configured ) {
        $abilities[] = 'flavor-agent/recommend-block';
    }

    if ( $azure_configured && $qdrant_configured ) {
        $abilities[] = 'flavor-agent/recommend-patterns';
    }

    return [
        'configured'         => $anthropic_configured,
        'model'              => $anthropic_configured ? $anthropic_model : null,
        'availableAbilities' => $abilities,
        'backends'           => [
            'anthropic'    => [
                'configured' => $anthropic_configured,
                'model'      => $anthropic_configured ? $anthropic_model : null,
            ],
            'azure_openai' => [
                'configured'          => $azure_configured,
                'chatDeployment'      => $azure_configured ? $azure_chat : null,
                'embeddingDeployment' => $azure_configured ? $azure_embedding : null,
            ],
            'qdrant'       => [
                'configured' => $qdrant_configured,
            ],
        ],
    ];
}
```

- [ ] **Step 2: Commit**

```bash
git add inc/Abilities/InfraAbilities.php
git commit -m "feat: compute availableAbilities dynamically with backends metadata"
```

### Task 3: Create webpack.config.js

**Files:**
- Create: `webpack.config.js`

- [ ] **Step 1: Write webpack config with multi-entry**

```js
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'src/index.js' ),
		admin: path.resolve( __dirname, 'src/admin/sync-button.js' ),
	},
};
```

- [ ] **Step 2: Create src/admin/sync-button.js placeholder**

```js
( function () {
	const button = document.getElementById( 'flavor-agent-sync-button' );
	const status = document.getElementById( 'flavor-agent-sync-status' );

	if ( ! button || ! status ) {
		return;
	}

	button.addEventListener( 'click', async function () {
		button.disabled = true;
		status.textContent = 'Syncing…';

		try {
			const response = await fetch(
				window.flavorAgentAdmin.restUrl + 'flavor-agent/v1/sync-patterns',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': window.flavorAgentAdmin.nonce,
					},
				}
			);

			const data = await response.json();

			if ( ! response.ok ) {
				status.textContent =
					'Error: ' + ( data.message || 'Sync failed.' );
				status.style.color = '#d63638';
				return;
			}

			status.textContent =
				'Synced ' +
				data.indexed +
				' patterns, removed ' +
				data.removed +
				'. Status: ' +
				data.status;
			status.style.color = '#00a32a';
		} catch ( err ) {
			status.textContent = 'Error: ' + err.message;
			status.style.color = '#d63638';
		} finally {
			button.disabled = false;
		}
	} );
} )();
```

- [ ] **Step 3: Run build to verify both entries emit**

```bash
cd flavor-agent && npm run build
# Expected: build/index.js, build/index.asset.php, build/admin.js, build/admin.asset.php
```

- [ ] **Step 4: Commit**

```bash
git add webpack.config.js src/admin/sync-button.js build/
git commit -m "feat: add webpack multi-entry for admin sync button"
```

### Task 4: Extend Settings.php with Azure/Qdrant Fields + Admin JS

**Files:**
- Modify: `inc/Settings.php`

- [ ] **Step 1: Register new settings and fields**

Add 6 new settings registrations, a new settings section, field renderers, sync status panel, and admin JS enqueue. The full rewrite of Settings.php incorporates all new fields while preserving the existing Anthropic section.

- [ ] **Step 2: Verify settings page renders**

Load `http://localhost:8888/wp-admin/options-general.php?page=flavor-agent` and confirm:
- Anthropic section with API Key + Model (existing)
- Azure OpenAI section with Endpoint, API Key, Embedding Deployment, Chat Deployment
- Qdrant section with URL, API Key
- Sync Pattern Catalog panel with button and status
- No console errors, no asset 404s

- [ ] **Step 3: Commit**

```bash
git add inc/Settings.php
git commit -m "feat: add Azure OpenAI + Qdrant settings and sync panel"
```

---

## Chunk 2: HTTP Clients

### Task 5: Create EmbeddingClient.php

**Files:**
- Create: `inc/AzureOpenAI/EmbeddingClient.php`

- [ ] **Step 1: Write EmbeddingClient**

Follows existing `Client::chat()` patterns: credential validation, `wp_remote_post`, error handling with `is_wp_error()`, HTTP status check, JSON parse. Single retry on 429. Timeout 30s.

Public static method: `embed( string $input ): array|\WP_Error` — returns 3072-float vector.
Public static method: `embed_batch( array $inputs ): array|\WP_Error` — returns array of vectors.

- [ ] **Step 2: Commit**

```bash
git add inc/AzureOpenAI/EmbeddingClient.php
git commit -m "feat: add Azure OpenAI embedding client"
```

### Task 6: Create QdrantClient.php

**Files:**
- Create: `inc/AzureOpenAI/QdrantClient.php`

- [ ] **Step 1: Write QdrantClient**

Static methods:
- `ensure_collection(): true|\WP_Error` — GET /collections/{name}, PUT if missing with 3072-dim cosine config + payload indexes
- `upsert_points( array $points ): true|\WP_Error` — PUT /collections/{name}/points
- `delete_points( array $ids ): true|\WP_Error` — POST /collections/{name}/points/delete
- `search( array $vector, int $limit, array $filter = [] ): array|\WP_Error` — POST /collections/{name}/points/query
- `get_point_ids(): array|\WP_Error` — POST /collections/{name}/points/scroll to list existing IDs

All use `wp_remote_post`/`wp_remote_get` with 10s timeout, api-key header, JSON body.

- [ ] **Step 2: Commit**

```bash
git add inc/AzureOpenAI/QdrantClient.php
git commit -m "feat: add Qdrant Cloud REST client"
```

### Task 7: Create ResponsesClient.php

**Files:**
- Create: `inc/AzureOpenAI/ResponsesClient.php`

- [ ] **Step 1: Write ResponsesClient**

Public static method: `rank( string $instructions, string $input ): string|\WP_Error`

Posts to `{endpoint}/openai/v1/responses` with `model` = chat deployment, `instructions`, `input`. Returns the text output. Single retry on 429. Timeout 30s.

- [ ] **Step 2: Commit**

```bash
git add inc/AzureOpenAI/ResponsesClient.php
git commit -m "feat: add Azure OpenAI Responses API client"
```

---

## Chunk 3: Index Lifecycle

### Task 8: Create PatternIndex.php — State + Fingerprint

**Files:**
- Create: `inc/Patterns/PatternIndex.php`

- [ ] **Step 1: Write PatternIndex class with state management**

Constants: `COLLECTION_NAME`, `STATE_OPTION`, `LOCK_TRANSIENT`, `LOCK_TTL`, `EMBEDDING_RECIPE_VERSION`, `UUID_NAMESPACE`.

Static methods:
- `get_state(): array` — reads `flavor_agent_pattern_index_state` option
- `save_state( array $state ): void` — updates the option
- `compute_fingerprint( array $patterns ): string` — md5 of sorted pattern metadata including EMBEDDING_RECIPE_VERSION
- `pattern_uuid( string $name ): string` — deterministic UUID v5 from pattern name
- `build_embedding_text( array $pattern ): string` — condensed representation per spec
- `acquire_lock(): bool` — transient-based lock with 5-min TTL
- `release_lock(): void` — deletes transient
- `sync(): array|\WP_Error` — full sync orchestrator (steps 1-10 from spec)
- `schedule_sync(): void` — wp_schedule_single_event helper with cooldown + wp_next_scheduled check

- [ ] **Step 2: Commit**

```bash
git add inc/Patterns/PatternIndex.php
git commit -m "feat: add PatternIndex sync orchestrator"
```

### Task 9: Add REST Sync Route

**Files:**
- Modify: `inc/REST/Agent_Controller.php`

- [ ] **Step 1: Register POST /flavor-agent/v1/sync-patterns**

Add a new route registration in `register_routes()` and a handler `handle_sync_patterns()` that calls `PatternIndex::sync()` and returns `{ indexed, removed, fingerprint, status }`.

- [ ] **Step 2: Commit**

```bash
git add inc/REST/Agent_Controller.php
git commit -m "feat: add sync-patterns REST route"
```

### Task 10: Add Lifecycle Hooks in flavor-agent.php

**Files:**
- Modify: `flavor-agent.php`

- [ ] **Step 1: Register cron hook and lifecycle triggers**

Add:
- `add_action( 'flavor_agent_reindex_patterns', [ PatternIndex::class, 'sync' ] )`
- `after_switch_theme`, `activated_plugin`, `deactivated_plugin` → schedule background re-index via `PatternIndex::schedule_sync()`

- [ ] **Step 2: Commit**

```bash
git add flavor-agent.php
git commit -m "feat: add pattern index lifecycle hooks and cron registration"
```

---

## Chunk 4: Recommend Flow

### Task 11: Implement recommend_patterns() in PatternAbilities.php

**Files:**
- Modify: `inc/Abilities/PatternAbilities.php`

- [ ] **Step 1: Replace stub with full implementation**

Implements the 6-step recommend flow from the spec:
1. Staleness check (read index state, branch by status)
2. Build query string from input
3. Embed query via EmbeddingClient
4. Two-pass Qdrant search (Pass A pure semantic + Pass B structural)
5. Rank via ResponsesClient with pattern ranking system prompt
6. Parse, rehydrate from Qdrant payload, return

- [ ] **Step 2: Commit**

```bash
git add inc/Abilities/PatternAbilities.php
git commit -m "feat: implement recommend_patterns with vector search + LLM ranking"
```

---

## Chunk 5: Spec Updates + Regression

### Task 12: Update Adjacent Spec

**Files:**
- Modify: `docs/specs/2026-03-16-abilities-api-integration-design.md`

- [ ] **Step 1: Update per spec's Adjacent Spec Update section**

Update the file structure comments and check-status entry to reflect the implemented changes.

- [ ] **Step 2: Commit**

```bash
git add docs/specs/2026-03-16-abilities-api-integration-design.md
git commit -m "docs: update abilities spec to reflect recommend-patterns implementation"
```

### Task 13: Regression Verification

- [ ] **Step 1: Verify build outputs both entries**

```bash
npm run build
ls -la build/index.js build/index.asset.php build/admin.js build/admin.asset.php
```

- [ ] **Step 2: Verify settings page**

Load settings page, confirm all sections render.

- [ ] **Step 3: Verify check-status contract**

```bash
# Via WP-CLI or REST API
curl -s http://localhost:8888/wp-json/wp-abilities/v1/abilities | jq '.[] | select(.name == "flavor-agent/check-status")'
```

- [ ] **Step 4: Verify existing recommend-block is unaffected**

Confirm the Anthropic path still works when API key is configured.
