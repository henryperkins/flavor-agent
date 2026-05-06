# Managed Cloudflare AI Search Pattern Storage Design

## Status

Approved target configuration captured on 2026-05-06.

## Problem

Cloudflare AI Search pattern storage currently asks administrators for a private pattern index name after they save Cloudflare Workers AI credentials for the Embedding Model. Cloudflare AI Search instances created after April 16, 2026 include built-in storage and a built-in Vectorize-backed index, so Flavor Agent can remove that manual setup step for the Cloudflare AI Search pattern backend.

The feature must not adopt or clean up arbitrary AI Search instances. Pattern sync currently treats every remote item not in the local pattern catalog as stale, so adopting a shared AI Search instance could delete unrelated built-in-storage items. The managed path must therefore create or adopt only an instance that Flavor Agent can prove it owns.

## Goals

- Reuse the Cloudflare account ID and API token saved under Embedding Model.
- Create a dedicated Cloudflare AI Search instance for Flavor Agent pattern content when none exists.
- Adopt an existing instance only when it matches Flavor Agent ownership and compatibility checks.
- Keep Pattern Storage as infrastructure, separate from AI Model and Embedding Model setup.
- Preserve the current Cloudflare AI Search retrieval contract: filtered `pattern_name` search, hybrid retrieval, no plugin-owned embedding call, downstream Flavor Agent ranking.

## Non-Goals

- Do not create or manage standalone Vectorize indexes directly.
- Do not configure R2 buckets, website crawling, service API tokens, or sync schedules.
- Do not expose public AI Search, chat completions, or MCP endpoints for private pattern content.
- Do not make Cloudflare AI Search mandatory for Qdrant users.
- Do not enable query rewriting for the current single-message pattern retrieval flow.

## Managed Instance Configuration

Flavor Agent should create instances in the `patterns` namespace with a deterministic managed ID:

```text
flavor-agent-patterns-{site_hash}
```

`site_hash` should be a lowercase hexadecimal hash derived from the normalized site URL and truncated to keep the instance ID below Cloudflare's 64-character limit. The ID must contain only lowercase alphanumeric characters and hyphens.

The create request should omit `type`, `source`, `source_params`, `sync_interval`, and `token_id` so the instance uses built-in storage only. Cloudflare-managed R2 and Vectorize resources remain implementation details owned by Cloudflare.

Target create payload:

```json
{
  "id": "flavor-agent-patterns-{site_hash}",
  "embedding_model": "@cf/qwen/qwen3-embedding-0.6b",
  "chunk": true,
  "chunk_size": 1024,
  "chunk_overlap": 15,
  "custom_metadata": [
    { "field_name": "pattern_name", "data_type": "text" },
    { "field_name": "candidate_type", "data_type": "text" },
    { "field_name": "source", "data_type": "text" },
    { "field_name": "synced_id", "data_type": "text" },
    { "field_name": "public_safe", "data_type": "boolean" }
  ],
  "fusion_method": "rrf",
  "index_method": {
    "keyword": true,
    "vector": true
  },
  "indexing_options": {
    "keyword_tokenizer": "porter"
  },
  "max_num_results": 50,
  "retrieval_options": {
    "keyword_match_mode": "or"
  },
  "rewrite_query": false,
  "reranking": false,
  "cache": false,
  "public_endpoint_params": {
    "enabled": false,
    "search_endpoint": {
      "disabled": true
    },
    "chat_completions_endpoint": {
      "disabled": true
    },
    "mcp": {
      "disabled": true
    }
  }
}
```

The `embedding_model` value should use the saved Embedding Model option when it is one of Cloudflare AI Search's supported embedding models, otherwise fall back to `@cf/qwen/qwen3-embedding-0.6b`.

## Query Rewrite

Keep query rewriting disabled. Flavor Agent sends a single structured pattern-retrieval query and already disables query rewrite at request time. Cloudflare's query-rewrite flow is primarily useful for follow-up conversational queries, and enabling it would add a second model call and another retrieval variable without current evidence that pattern recall needs it.

If later telemetry shows single-query pattern recall is weak because editor-generated queries do not match pattern markdown, query rewrite can become an explicit advanced setting or experiment.

## Chunking

Use recursive chunking with a 1024-token chunk size and 15 percent overlap. Pattern markdown combines title, metadata, traits, and block content. A 1024-token chunk keeps most patterns intact while still allowing large pattern markup to split on natural boundaries. The 15 percent overlap protects boundary context without approaching Cloudflare's 30 percent maximum.

## Ownership Model

Cloudflare's `created_by` field should not be treated as application ownership. It identifies a Cloudflare actor, not Flavor Agent.

Flavor Agent ownership is proven by all of the following:

- Instance ID matches the managed Flavor Agent ID for this site.
- Instance custom metadata schema exactly matches the five approved fields and data types.
- The Items API works for the instance, proving built-in storage support.
- A reserved owner marker item exists and matches the current install identity.

Reserved owner marker:

```text
__flavor_agent_owner__
```

The owner marker should be stored through the Items API with metadata that fits the five-field schema:

```json
{
  "pattern_name": "__flavor_agent_owner__",
  "candidate_type": "flavor_agent_owner",
  "source": "flavor_agent",
  "synced_id": "{site_hash}",
  "public_safe": true
}
```

The owner marker must be excluded from stale cleanup, indexed pattern counts, and recommendation retrieval output.

## Adoption Rules

When Pattern Storage is set to Cloudflare AI Search and Embedding Model credentials are present:

1. List instances in the `patterns` namespace.
2. Look for the deterministic managed instance ID.
3. If it exists, validate the schema, Items API, and owner marker.
4. If all checks pass, save the instance ID and mark Cloudflare AI Search Pattern Storage configured.
5. If no managed instance exists, create one with the approved payload, upload the owner marker, validate it, save the instance ID, and mark storage configured.
6. If the managed ID exists but fails ownership or schema checks, do not adopt it automatically. Surface a repair/create-new action and leave the previous saved instance in place.

Instances outside the managed ID pattern must not be auto-selected, even if they are the only AI Search instances in the account.

## Runtime Sync

Pattern sync continues to upload public-safe registered and synced pattern markdown through the Items API. The sync cleanup step must preserve the owner marker and only delete remote IDs that are known Flavor Agent pattern item IDs.

The current search request remains intentionally filtered:

```json
{
  "ai_search_options": {
    "query_rewrite": {
      "enabled": false
    },
    "retrieval": {
      "retrieval_type": "hybrid",
      "max_num_results": 50,
      "match_threshold": 0.2,
      "context_expansion": 0,
      "fusion_method": "rrf",
      "return_on_failure": true,
      "filters": {
        "pattern_name": {
          "$in": ["visible/pattern-name"]
        }
      }
    }
  }
}
```

## Admin Experience

The Cloudflare AI Search Pattern Storage setup should stop presenting the index name as the primary required setup field. Instead:

- Show whether a managed pattern index is available, creating, ready, incompatible, or failed.
- Provide a primary setup action such as "Create managed pattern index" when credentials are available and no owned instance exists.
- Keep the raw instance ID visible as advanced/debug information after setup.
- Keep Qdrant settings unchanged.
- Keep text-generation provider setup in `Settings > Connectors`.

## Error Handling

- Missing Cloudflare account ID/token: keep Pattern Storage gated and point the user to Embedding Model.
- Token lacks AI Search permissions: show a Cloudflare AI Search permission error without rolling back valid Embedding Model credentials.
- Existing managed ID has incompatible schema: block adoption and explain that the schema must match the five Flavor Agent fields.
- Existing managed ID lacks built-in storage or Items API support: block adoption and offer to create a new managed instance.
- Owner marker mismatch: block adoption to avoid deleting unrelated content.
- Create conflict: re-list, re-validate, and adopt only if ownership checks now pass.

## Verification

Implementation should include focused PHPUnit coverage for instance create/adopt/error paths, owner marker preservation during sync cleanup, settings readiness messaging, and unchanged retrieval request semantics. Docs validation should run because local setup and source-of-truth docs need to describe the new managed path.

## Cloudflare References

- Built-in storage: https://developers.cloudflare.com/ai-search/configuration/data-source/built-in-storage/
- Create instance API: https://developers.cloudflare.com/api/resources/ai_search/subresources/namespaces/subresources/instances/methods/create/
- Metadata schema and filters: https://developers.cloudflare.com/ai-search/configuration/indexing/metadata/
- Chunking: https://developers.cloudflare.com/ai-search/configuration/indexing/chunking/
- Query rewriting: https://developers.cloudflare.com/ai-search/configuration/retrieval/query-rewriting/
