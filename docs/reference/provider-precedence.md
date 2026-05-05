# Provider Precedence Reference

This document is the contract reference for how Flavor Agent resolves which AI backend handles a request.

Use it when you need to answer:

- which backend will serve a recommendation
- what credential sources are checked and in what order
- how chat is owned by Settings > Connectors and how embeddings stay plugin-owned

## Ownership Split

After Workstream C of the WP 7.0 overlap remediation, Flavor Agent has three distinct setup concerns:

- **Chat runtime** is owned by Settings > Connectors via the WordPress AI Client. There is no plugin-managed chat endpoint, deployment, or chat model anymore.
- **Embedding runtime** is plugin-owned because Settings > Connectors does not expose embeddings yet. Flavor Agent uses Cloudflare Workers AI as the single first-party embedding configuration for semantic features that need plugin-managed vectors. Previously saved OpenAI Native, Azure OpenAI, or connector-backed provider values are not rendered as embedding choices.
- **Pattern Storage** is selected independently for pattern recommendations. Qdrant uses the configured Embedding Model plus Qdrant. Cloudflare AI Search uses a private site-owner AI Search pattern instance with Cloudflare-managed indexing/search and does not use plugin-owned embeddings or Qdrant.

The `flavor_agent_openai_provider` option still exists for migration compatibility and the hidden settings-field save path, but saved values no longer select chat or embeddings:

| Saved value             | Effect on chat                                                                                 | Effect on embeddings                                             |
| ----------------------- | ---------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| `openai_native`         | Ignored; use the configured WordPress AI Client runtime without pinning a provider             | Ignored; runtime embeddings use Cloudflare Workers AI            |
| `cloudflare_workers_ai` | Ignored; use the configured WordPress AI Client runtime without pinning Cloudflare             | Runtime embeddings use Cloudflare Workers AI regardless of value |
| `azure_openai`          | Ignored; use the configured WordPress AI Client runtime without pinning Azure                  | Ignored; runtime embeddings use Cloudflare Workers AI            |
| `<connector-id>`        | Ignored; use the configured WordPress AI Client runtime without pinning the saved connector ID | Ignored; runtime embeddings use Cloudflare Workers AI            |

The admin Embedding Model section no longer renders this as a provider picker. It submits `cloudflare_workers_ai` as a hidden value on save and renders only the Cloudflare Workers AI embedding fields.

## Chat Runtime Chain

`ChatClient::chat()` is the only chat entry point. After Workstream C, it is a thin wrapper around `ResponsesClient::rank()`, which always routes through `WordPressAIClient::chat()`.

1. Flavor Agent ignores saved provider values from older settings screens.
2. Chat requests use the configured WordPress AI Client runtime without pinning a provider.
3. When no WordPress AI Client text-generation runtime is available, `ChatClient::chat()` returns a `missing_text_generation_provider` `WP_Error` whose message is _"Configure a text-generation provider in Settings > Connectors to enable Flavor Agent recommendations."_

`ChatClient::is_supported()` returns `true` when the WordPress AI Client has a configured text-generation runtime. This is the gate for every chat-dependent ability: `flavor-agent/recommend-block`, `recommend-content`, `recommend-template`, `recommend-template-part`, `recommend-navigation`, `recommend-style`, and the pattern-ranking phase of `flavor-agent/recommend-patterns` for both pattern retrieval backends.

`Provider::chat_configuration()` reports the unpinned WordPress AI Client chat runtime when that runtime is available.

## Reasoning Effort Routing

`flavor_agent_reasoning_effort` is the neutral Connectors-routed chat preference used when Flavor Agent can express the setting through the selected provider's model configuration. Valid legacy `flavor_agent_azure_reasoning_effort` values are read only as a one-way fallback/migration source. Flavor Agent does not read, copy, or submit connector credentials itself; authentication and final request dispatch remain owned by the WordPress AI Client and the selected provider plugin.

At request time, `WordPressAIClient::chat()` first tries any standardized WP AI Client reasoning methods that may exist in a future core/client version. When those methods are unavailable, it falls back to `ModelConfig::customOptions` for provider plugins that already support the needed payload shape:

| Selected chat provider | Fallback custom option     |
| ---------------------- | -------------------------- | ------ | ---- | --------- |
| `codex`                | `reasoningEffort: <low     | medium | high | xhigh>`   |
| `openai`               | `reasoning: { effort: <low | medium | high | xhigh> }` |

When chat resolves to the `openai` connector, it uses the OpenAI mapping above. Anthropic is intentionally unmapped until its provider plugin documents the accepted reasoning/thinking payload contract; Flavor Agent should add that mapping with provider-specific tests when the contract is known.

## Embedding Runtime Chain

`Provider::embedding_configuration()` resolves the active plugin-owned Embedding Model. These embeddings are used by Flavor Agent semantic features that need plugin-managed vectors. The Qdrant pattern storage backend uses this Embedding Model; the Cloudflare AI Search pattern backend uses Cloudflare AI Search managed embeddings/indexing and does not call `EmbeddingClient`.

1. Flavor Agent resolves runtime embeddings from the Cloudflare Workers AI account ID, API token, and embedding model options.
2. Saved `openai_native`, `azure_openai`, or connector-backed provider values do not change the runtime embedding path.
3. If Cloudflare Workers AI is incomplete, Embedding Model status is unavailable and Qdrant Pattern Storage is gated off.
4. `EmbeddingClient::validate_configuration()` validates only the Cloudflare Workers AI account ID, API token, and embedding model.
5. When validation sees a Workers AI dimension that differs from the saved Qdrant pattern index dimension, the settings screen warns that patterns must be re-synced before Qdrant-backed recommendations are reliable.
6. The Cloudflare AI Search pattern backend can still be ready when its private AI Search credentials are configured because it uses managed indexing/search instead of `EmbeddingClient`.

## Pattern Storage Backend Chain

`flavor_agent_pattern_retrieval_backend` selects the pattern retrieval backend. Missing or invalid values default to `qdrant`.

| Value                  | Retrieval behavior                                                                                                                                                                                                                        | Required setup                                                                  |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `qdrant`               | Pattern sync embeds changed pattern text with the active Embedding Model, stores vectors and payloads in Qdrant, and recommendation requests query Qdrant before reranking through Connectors chat.                                       | Embedding Model; Qdrant URL/key; Connectors chat.                               |
| `cloudflare_ai_search` | Pattern sync uploads public-safe pattern markdown items to a private Cloudflare AI Search instance, and recommendation requests send query text plus `visiblePatternNames` as a metadata filter before reranking through Connectors chat. | Private Cloudflare AI Search account/namespace/instance/token; Connectors chat. |

Cloudflare AI Search pattern retrieval is separate from the built-in public Cloudflare AI Search endpoint used for WordPress developer-doc grounding.

## Legacy Provider Options

Azure OpenAI and OpenAI Native embedding settings are no longer first-party admin setup paths. The live settings UI, validation path, status abilities, and embedding client all use Cloudflare Workers AI. Older saved Native/Azure option values may remain in older databases and are cleaned up on uninstall, but they no longer have a runtime validation or credential-source path.

## Cloudflare Workers AI (embeddings only)

Requires all three options to be non-empty for embeddings:

| Option                                               | Purpose               |
| ---------------------------------------------------- | --------------------- |
| `flavor_agent_cloudflare_workers_ai_account_id`      | Cloudflare account ID |
| `flavor_agent_cloudflare_workers_ai_api_token`       | API token             |
| `flavor_agent_cloudflare_workers_ai_embedding_model` | Workers AI model name |

Authentication uses the `Authorization: Bearer` header against Cloudflare's OpenAI-compatible Workers AI endpoint. Workers AI credentials are read from Flavor Agent option storage only; this backend has no environment-variable, constant, or connector-credential fallback.

## Cloudflare AI Search Pattern Retrieval

Requires all four options to be non-empty when `flavor_agent_pattern_retrieval_backend` is `cloudflare_ai_search`:

| Option                                                  | Purpose                               |
| ------------------------------------------------------- | ------------------------------------- |
| `flavor_agent_cloudflare_pattern_ai_search_account_id`  | Cloudflare account ID                 |
| `flavor_agent_cloudflare_pattern_ai_search_namespace`   | AI Search namespace                   |
| `flavor_agent_cloudflare_pattern_ai_search_instance_id` | Private pattern AI Search instance ID |
| `flavor_agent_cloudflare_pattern_ai_search_api_token`   | API token                             |

Authentication uses the `Authorization: Bearer` header against Cloudflare's AI Search REST API. Pattern sync uses stable item IDs, uploads changed public-safe registered and published synced/user patterns with `wait_for_completion=true`, and deletes stale remote items. Recommendation search sends the query text and `filters.pattern_name` derived from `visiblePatternNames`.

## Old Provider Values

Older saved connector-backed, OpenAI Native, or Azure provider values do not select chat or embeddings. The settings screen writes `cloudflare_workers_ai` on save, runtime embeddings always use Cloudflare Workers AI, and chat uses the configured WordPress AI Client runtime.

## Backend-to-Surface Map

| Surface                       | Chat (Connectors) | Embeddings (plugin) | Qdrant              | Cloudflare AI Search             |
| ----------------------------- | ----------------- | ------------------- | ------------------- | -------------------------------- |
| Block recommendations         | Yes               | No                  | No                  | No                               |
| Pattern recommendations       | Yes               | Qdrant backend only | Qdrant backend only | Optional private pattern backend |
| Template recommendations      | Yes               | No                  | No                  | No                               |
| Template-part recommendations | Yes               | No                  | No                  | No                               |
| Navigation recommendations    | Yes               | No                  | No                  | No                               |
| Global Styles recommendations | Yes               | No                  | No                  | No                               |
| Style Book recommendations    | Yes               | No                  | No                  | No                               |
| WordPress docs search         | No                | No                  | No                  | Built-in public docs endpoint    |

## Primary Source Files

- `inc/OpenAI/Provider.php`
- `inc/LLM/ChatClient.php`
- `inc/LLM/WordPressAIClient.php`
- `inc/AzureOpenAI/ResponsesClient.php` (Connectors facade)
- `inc/Embeddings/EmbeddingClient.php`
- `inc/Embeddings/QdrantClient.php`
- `inc/Abilities/SurfaceCapabilities.php`
- `inc/Patterns/Retrieval/PatternRetrievalBackendFactory.php`
- `inc/Cloudflare/PatternSearchClient.php`
