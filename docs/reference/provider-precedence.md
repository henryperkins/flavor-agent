# Provider Precedence Reference

This document is the contract reference for how Flavor Agent resolves which AI backend handles a request.

Use it when you need to answer:

- which backend will serve a recommendation
- what credential sources are checked and in what order
- how chat is owned by Settings > Connectors and how embeddings stay plugin-owned

## Ownership Split

After Workstream C of the WP 7.0 overlap remediation, Flavor Agent has two distinct provider concerns:

- **Chat runtime** is owned by Settings > Connectors via the WordPress AI Client. There is no plugin-managed chat endpoint, deployment, or chat model anymore.
- **Embedding runtime** is plugin-owned because Settings > Connectors does not expose embeddings yet. Pattern recommendations need embeddings, so the plugin keeps Azure OpenAI and OpenAI Native configurations for that single purpose.

The `flavor_agent_openai_provider` option still exists, but its semantics narrowed:

| Value | Effect on chat | Effect on embeddings |
|---|---|---|
| `azure_openai` | Ignored (chat goes to Connectors) | Use Azure OpenAI for embeddings |
| `openai_native` | Ignored (chat goes to Connectors) | Use OpenAI Native for embeddings |
| `<connector-id>` (e.g. `openai`, `anthropic`) | Pin chat to this connector | Falls back to a configured direct embedding backend |

If the option is missing or invalid, it defaults to `azure_openai`.

## Chat Runtime Chain

`ChatClient::chat()` is the only chat entry point. After Workstream C, it is a thin wrapper around `ResponsesClient::rank()`, which always routes through `WordPressAIClient::chat()`.

1. Flavor Agent reads the selected provider from `flavor_agent_openai_provider`.
2. If the selected provider is a configured connector-backed provider, requests run through `wp_ai_client_prompt()->using_provider( $provider_id )` to pin chat to that connector.
3. Otherwise the generic WordPress AI Client runtime is used. The synthetic `wordpress_ai_client` provider is reported.
4. When no Connectors-backed runtime is available, `ChatClient::chat()` returns a `missing_text_generation_provider` `WP_Error` whose message is *"Configure a text-generation provider in Settings > Connectors to enable Flavor Agent recommendations."*

`ChatClient::is_supported()` returns `true` only when `WordPressAIClient::is_supported()` returns `true`. This is the gate for every chat-dependent ability: `flavor-agent/recommend-block`, `recommend-content`, `recommend-template`, `recommend-template-part`, `recommend-navigation`, `recommend-style`, and the pattern-ranking phase of `flavor-agent/recommend-patterns`.

`Provider::chat_configuration( $provider )` reports direct Azure/OpenAI Native providers as missing chat by design. Default runtime chat can still fall back to the generic WordPress AI Client when no connector-specific provider is pinned.

## Embedding Runtime Chain

`Provider::embedding_configuration()` resolves the active embedding backend. Embeddings require a fully configured Azure OpenAI or OpenAI Native backend.

1. Flavor Agent reads the selected provider from `flavor_agent_openai_provider`.
2. If the selected provider is `azure_openai` and Azure embeddings are fully configured, that is the runtime.
3. If the selected provider is `openai_native` and Native embeddings are configured, that is the runtime.
4. If the selected provider is a connector ID (which has no embedding capability), Flavor Agent falls back to the other configured direct embedding backend.
5. If neither direct backend is configured, embeddings are unavailable and `flavor-agent/recommend-patterns` is gated off.

## Azure OpenAI (embeddings only)

Requires all three options to be non-empty for embeddings:

| Option | Purpose |
|---|---|
| `flavor_agent_azure_openai_endpoint` | Azure resource endpoint URL |
| `flavor_agent_azure_openai_key` | API key |
| `flavor_agent_azure_embedding_deployment` | Embedding deployment name |

Authentication uses the `api-key` header. Settings > Flavor Agent no longer accepts an Azure chat deployment field; existing values stored in the database from earlier releases are ignored at runtime.

## OpenAI Native (embeddings only)

Requires a non-empty API key and embedding model.

| Option | Purpose |
|---|---|
| `flavor_agent_openai_native_api_key` | Plugin-specific API key (highest priority) |
| `flavor_agent_openai_native_embedding_model` | Embedding model name |

### API Key Fallback Chain

When `flavor_agent_openai_native_api_key` is blank, Flavor Agent reuses the WordPress Connectors API OpenAI connector lifecycle:

1. **Plugin override** — `flavor_agent_openai_native_api_key` option
2. **Environment variable** — `OPENAI_API_KEY` env var
3. **PHP constant** — `OPENAI_API_KEY` constant
4. **Connector database** — `connectors_ai_openai_api_key` option (set via Settings > Connectors)

The resolved source is tracked as `plugin_override`, `env`, `constant`, `connector_database`, or `none`. Authentication uses the `Authorization: Bearer` header.

Settings > Flavor Agent no longer accepts an OpenAI Native chat-model field.

## Connector-Backed Provider Pinning

Selecting a connector-backed provider pins chat to that connector but does not enable embeddings:

- availability is determined via `wp_ai_client_prompt()->using_provider( $provider_id )->is_supported_for_text_generation()`
- chat requests are routed via `wp_ai_client_prompt()->using_provider( $provider_id )`
- the active chat model is reported as `provider-managed`
- embedding generation remains unavailable through the connector, so pattern recommendations still require Azure OpenAI or OpenAI Native to be fully configured for embeddings

When no specific connector-backed provider is selected, Flavor Agent uses the generic WordPress AI Client runtime whenever `WordPressAIClient::is_supported()` returns `true`.

## Backend-to-Surface Map

| Surface | Chat (Connectors) | Embeddings (plugin) | Qdrant | Cloudflare AI Search |
|---|---|---|---|---|
| Block recommendations | Yes | No | No | No |
| Pattern recommendations | Yes | Yes | Yes | No |
| Template recommendations | Yes | No | No | No |
| Template-part recommendations | Yes | No | No | No |
| Navigation recommendations | Yes | No | No | No |
| Global Styles recommendations | Yes | No | No | No |
| Style Book recommendations | Yes | No | No | No |
| WordPress docs search | No | No | No | Yes |

## Primary Source Files

- `inc/OpenAI/Provider.php`
- `inc/LLM/ChatClient.php`
- `inc/LLM/WordPressAIClient.php`
- `inc/AzureOpenAI/ResponsesClient.php` (Connectors facade)
- `inc/AzureOpenAI/EmbeddingClient.php`
- `inc/Abilities/SurfaceCapabilities.php`
