# Provider Precedence Reference

This document is the contract reference for how Flavor Agent resolves which AI backend handles a request.

Use it when you need to answer:

- which backend will serve a recommendation
- what credential sources are checked and in what order
- when the WordPress AI Client fallback activates

## Provider Selection

The `flavor_agent_openai_provider` option selects the active provider. Valid values include the built-in direct backends plus any registered Connectors API AI provider:

| Value | Label | Default |
|---|---|---|
| `azure_openai` | Azure OpenAI | Yes |
| `openai_native` | OpenAI Native | No |
| `<connector-id>` | Configured WordPress AI Client provider surfaced from `Settings > Connectors` | No |

If the option is missing or invalid, the provider defaults to `azure_openai`. The settings UI only lists connector-backed providers when they are currently usable for text generation, but the stored option may still retain a registered connector ID that later becomes unavailable.

## Chat Fallback Chain

`ChatClient::chat()` is the only entry point for block recommendations.

1. Flavor Agent first tries the selected provider from `flavor_agent_openai_provider`.
2. If that selected provider is configured, requests run through `ResponsesClient::rank()`, which delegates connector-backed providers to `wp_ai_client_prompt()->using_provider( $provider_id )` and direct providers to the configured Responses API endpoint.
3. If the selected provider is not configured, Flavor Agent tries the other direct provider (`azure_openai` or `openai_native`) before using the generic WordPress AI Client fallback.
4. Only when no direct provider is configured does block chat fall back to the generic WordPress AI Client path (`WordPressAIClient::is_supported()`) with the synthetic `wordpress_ai_client` runtime provider.

`ChatClient::is_supported()` returns `true` if either tier is available. This is the gate for the `flavor-agent/recommend-block` ability.

Template, template-part, navigation, Global Styles, and Style Book recommendations also use the selected provider. When the selected provider is connector-backed, `ResponsesClient::rank()` delegates to `wp_ai_client_prompt()->using_provider( $provider_id )`. Pattern recommendations remain direct-backend only because Flavor Agent still owns embedding generation and Qdrant indexing itself.

## Azure OpenAI Configuration

Requires all three options to be non-empty:

| Option | Purpose |
|---|---|
| `flavor_agent_azure_openai_endpoint` | Azure resource endpoint URL |
| `flavor_agent_azure_openai_key` | API key |
| `flavor_agent_azure_chat_deployment` | Chat deployment name |

Embedding also requires `flavor_agent_azure_embedding_deployment`.

Authentication uses the `api-key` header.

## OpenAI Native Configuration

Chat requires a non-empty API key and model. Embedding requires a non-empty API key and embedding model.

| Option | Purpose |
|---|---|
| `flavor_agent_openai_native_api_key` | Plugin-specific API key (highest priority) |
| `flavor_agent_openai_native_chat_model` | Chat model name |
| `flavor_agent_openai_native_embedding_model` | Embedding model name |

### API Key Fallback Chain

When the plugin-specific key (`flavor_agent_openai_native_api_key`) is blank, the plugin falls back to the WordPress Connectors API OpenAI connector lifecycle:

1. **Plugin override** — `flavor_agent_openai_native_api_key` option
2. **Environment variable** — `OPENAI_API_KEY` env var
3. **PHP constant** — `OPENAI_API_KEY` constant
4. **Connector database** — `connectors_ai_openai_api_key` option (set via Settings > Connectors)

The resolved source is tracked as `plugin_override`, `env`, `constant`, `connector_database`, or `none`.

Authentication uses the `Authorization: Bearer` header.

## Connector-Backed Provider Configuration

Connector-backed providers do not use the plugin's direct endpoint settings. Flavor Agent treats them as chat-only providers backed by the WordPress AI Client:

- availability is determined via `wp_ai_client_prompt()->using_provider( $provider_id )->is_supported_for_text_generation()`
- requests are routed via `wp_ai_client_prompt()->using_provider( $provider_id )`
- the active chat model is reported as `provider-managed`
- embedding generation remains unavailable, so pattern recommendations still require `azure_openai` or `openai_native`

## Backend-to-Surface Map

| Surface | Chat required | Embeddings required | Qdrant required | Cloudflare AI Search required |
|---|---|---|---|---|
| Block recommendations | Yes (ChatClient, with WP AI Client fallback) | No | No | No |
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
- `inc/AzureOpenAI/ResponsesClient.php`
- `inc/AzureOpenAI/EmbeddingClient.php`
- `inc/Abilities/SurfaceCapabilities.php`
