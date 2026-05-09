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
- **Pattern Storage** is selected independently for pattern recommendations. Qdrant uses the configured Embedding Model plus Qdrant. Cloudflare AI Search uses a private site-owner AI Search pattern instance with Cloudflare-managed embeddings/indexing/search and does not use plugin-owned embeddings or Qdrant.

The `flavor_agent_openai_provider` option still exists for migration compatibility and the hidden settings-field save path, but saved values no longer select chat or embeddings:

| Saved value             | Effect on chat                                                                                 | Effect on embeddings                                             |
| ----------------------- | ---------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| `openai_native`         | Ignored; use the configured WordPress AI Client runtime without pinning a provider             | Ignored; runtime embeddings use Cloudflare Workers AI            |
| `cloudflare_workers_ai` | Ignored; use the configured WordPress AI Client runtime without pinning Cloudflare             | Runtime embeddings use Cloudflare Workers AI regardless of value |
| `azure_openai`          | Ignored; use the configured WordPress AI Client runtime without pinning Azure                  | Ignored; runtime embeddings use Cloudflare Workers AI            |
| `<connector-id>`        | Ignored; use the configured WordPress AI Client runtime without pinning the saved connector ID | Ignored; runtime embeddings use Cloudflare Workers AI            |

The admin Embedding Model section no longer renders this as a provider picker. It submits `cloudflare_workers_ai` as a hidden value on save and renders only the Cloudflare Workers AI embedding fields.

## Chat Runtime Chain

Flavor Agent chat requests enter through `ChatClient::chat()` for block/content and through direct `ResponsesClient::rank()` calls for pattern, template, template-part, navigation, Global Styles, and Style Book ranking. Both paths route through `WordPressAIClient::chat()`.

1. Flavor Agent ignores saved provider values from older settings screens.
2. Explicit provider arguments to `WordPressAIClient::chat()` have highest precedence and pin that request to the supplied provider.
3. When no explicit provider is supplied, Flavor Agent reads the AI plugin Developer Tools per-feature option `wpai_feature_flavor-agent_field_developer`. A selected provider pins the request to that provider; a selected model is resolved through the WordPress AI Client registry with provider-managed fallback if the model is unavailable.
4. If no explicit provider and no AI-plugin per-feature selection exists, chat requests use the configured WordPress AI Client runtime without pinning a provider.
5. When no WordPress AI Client text-generation runtime is available, `ChatClient::chat()` returns a `missing_text_generation_provider` `WP_Error` whose message is _"Configure a text-generation provider in Settings > Connectors to enable Flavor Agent recommendations."_

`ChatClient::is_supported()` returns `true` when the WordPress AI Client has a configured text-generation runtime. This is the gate for every chat-dependent ability: `flavor-agent/recommend-block`, `recommend-content`, `recommend-template`, `recommend-template-part`, `recommend-navigation`, `recommend-style`, and the pattern-ranking phase of `flavor-agent/recommend-patterns` for both pattern retrieval backends.

`Provider::chat_configuration()` reports the unpinned WordPress AI Client chat runtime when that runtime is available. Per-request Activity Log metadata records the resolved runtime separately: `requestSummary.resolvedProvider`, `requestSummary.resolvedModel`, `requestSummary.modelSelectionSource`, and `requestSummary.modelResolutionStatus` show whether a request used an explicit provider, the AI plugin per-feature selection, or the default provider-managed runtime.

## Reasoning Effort Routing

`flavor_agent_reasoning_effort` is the neutral Connectors-routed chat preference used when Flavor Agent can express the setting through the selected provider's model configuration. Runtime calls use the explicit request value when supplied, then the saved neutral option, then a valid legacy `flavor_agent_azure_reasoning_effort` value as a one-way fallback/migration source, and finally `medium`. Flavor Agent does not read, copy, or submit connector credentials itself; authentication and final request dispatch remain owned by the WordPress AI Client and the selected provider plugin.

At request time, `WordPressAIClient::chat()` first tries any standardized WP AI Client reasoning methods that may exist in a future core/client version. When those methods are unavailable and the resolved provider argument is a known pinned connector, it falls back to `ModelConfig::customOptions` for provider plugins that already support the needed payload shape. The normal admin/runtime path ignores saved provider IDs from older settings screens and does not pin chat to a connector. The normal unpinned Connectors runtime can still receive the neutral reasoning preference through standardized WP AI Client methods, but provider-specific custom-option fallback is applied only when a caller explicitly supplies or resolves one of these pinned provider IDs:

| Selected chat provider | Fallback custom option     |
| ---------------------- | -------------------------- |
| `codex`                | `reasoningEffort` with one of `low`, `medium`, `high`, or `xhigh` |
| `openai`               | `reasoning.effort` with one of `low`, `medium`, `high`, or `xhigh` |

Anthropic is intentionally unmapped until its provider plugin documents the accepted reasoning/thinking payload contract; Flavor Agent should add that mapping with provider-specific tests when the contract is known.

## Embedding Runtime Chain

`Provider::embedding_configuration()` resolves the active plugin-owned Embedding Model. These embeddings are used by Flavor Agent semantic features that need plugin-managed vectors. The Qdrant pattern storage backend uses this Embedding Model. The Cloudflare AI Search pattern backend uses Cloudflare AI Search managed embeddings/indexing for pattern sync and retrieval and does not call `EmbeddingClient` there. When Workers AI credentials change, the save flow validates them with `EmbeddingClient::validate_configuration()` before creating or adopting the managed AI Search instance; unchanged values may reuse the saved Workers AI configuration and validate the managed AI Search instance/signature instead of re-probing Workers AI.

1. Flavor Agent resolves runtime embeddings from the Cloudflare Workers AI account ID, API token, and effective embedding model. A blank or missing model falls back to `@cf/qwen/qwen3-embedding-0.6b`.
2. Saved `openai_native`, `azure_openai`, or connector-backed provider values do not change the runtime embedding path.
3. If Cloudflare Workers AI is incomplete, Embedding Model status is unavailable and Qdrant Pattern Storage is gated off.
4. `EmbeddingClient::validate_configuration()` validates only the Cloudflare Workers AI account ID, API token, and effective embedding model.
5. When validation sees a Workers AI dimension that differs from the saved Qdrant pattern index dimension, the settings screen warns that patterns must be re-synced before Qdrant-backed recommendations are reliable.
6. The Cloudflare AI Search pattern backend can be ready when the Cloudflare Workers AI account ID, API token, normalized AI Search embedding model, and deterministic managed `flavor-agent-patterns-{site_hash}` AI Search pattern instance are validated because pattern sync/retrieval uses managed embeddings/indexing/search instead of `EmbeddingClient`; save-time setup validates changed shared Workers AI credentials before creating or adopting that managed instance and can reuse unchanged saved values. Blank or unsupported Embedding Model values normalize to Cloudflare AI Search's supported default `@cf/qwen/qwen3-embedding-0.6b` for this private index path.

## Pattern Storage Backend Chain

`flavor_agent_pattern_retrieval_backend` selects the pattern retrieval backend. Settings reads and UI readiness normalize missing or invalid values to `qdrant`; the request-time retrieval factory still fails closed with `unsupported_pattern_retrieval_backend` for an unknown non-empty saved value so recommendations do not silently use the wrong backend after state corruption.

| Value                  | Retrieval behavior                                                                                                                                                                                                                        | Required setup                                                                  |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `qdrant`               | Pattern sync embeds changed pattern text with the active Embedding Model, stores vectors and payloads in Qdrant, and recommendation requests query Qdrant before reranking through Connectors chat.                                       | Embedding Model; Qdrant URL/key; Connectors chat.                               |
| `cloudflare_ai_search` | Pattern sync uploads public-safe pattern markdown items to a private Cloudflare AI Search instance, and recommendation requests send query text plus `visiblePatternNames` as a nested AI Search retrieval metadata filter before reranking through Connectors chat. | Cloudflare Workers AI account/token/normalized-AI-Search-model signature; validated managed `flavor-agent-patterns-{site_hash}` instance; Connectors chat. |

Cloudflare AI Search pattern retrieval is separate from the built-in public Cloudflare AI Search endpoint used for WordPress developer-doc grounding.

## Legacy Provider Options

Azure OpenAI and OpenAI Native embedding settings are no longer first-party admin setup paths. The live settings UI, validation path, status abilities, and embedding client all use Cloudflare Workers AI. Older saved Native/Azure option values may remain in older databases and are cleaned up on uninstall, but they no longer have a runtime validation or credential-source path.

## Cloudflare Workers AI (embeddings only)

Requires a Cloudflare account ID and API token, plus an effective embedding model. If the model field is blank or absent, Flavor Agent uses `@cf/qwen/qwen3-embedding-0.6b`.

| Option                                               | Purpose               |
| ---------------------------------------------------- | --------------------- |
| `flavor_agent_cloudflare_workers_ai_account_id`      | Cloudflare account ID |
| `flavor_agent_cloudflare_workers_ai_api_token`       | API token             |
| `flavor_agent_cloudflare_workers_ai_embedding_model` | Workers AI model name |

Authentication uses the `Authorization: Bearer` header against Cloudflare's OpenAI-compatible Workers AI endpoint. Workers AI credentials are read from Flavor Agent option storage only; this backend has no environment-variable, constant, or connector-credential fallback.

## Cloudflare AI Search Pattern Retrieval

Requires the saved Cloudflare Workers AI account, API token, effective embedding model, and managed pattern AI Search instance option to be validated when `flavor_agent_pattern_retrieval_backend` is `cloudflare_ai_search`:

| Option                                                  | Purpose                                      |
| ------------------------------------------------------- | -------------------------------------------- |
| `flavor_agent_cloudflare_workers_ai_account_id`         | Shared Cloudflare account ID                 |
| `flavor_agent_cloudflare_workers_ai_api_token`          | Shared Cloudflare API token                  |
| `flavor_agent_cloudflare_pattern_ai_search_instance_id` | Managed pattern AI Search instance ID        |

Authentication uses the `Authorization: Bearer` header against Cloudflare's AI Search REST API. Pattern sync uploads changed public-safe registered patterns and published user `wp_block` patterns across synced, partial, and unsynced states with `wait_for_completion=false`, uses each remote item `key` as the stable Flavor Agent pattern ID, and deletes stale/retryable items by Cloudflare's generated item `id`. Unknown remote items and the owner marker are preserved. The index is ready only after listed current pattern items report `completed`; pending, missing, or retryable item-processing failures schedule follow-up syncs. Recommendation search sends the query text and `ai_search_options.retrieval.filters.pattern_name` derived from `visiblePatternNames`.

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
