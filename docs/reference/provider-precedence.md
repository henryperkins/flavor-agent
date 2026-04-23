# Provider Precedence Reference

This document is the contract reference for how Flavor Agent resolves which AI backend handles a request.

Use it when you need to answer:

- which backend will serve a recommendation
- what credential sources are checked and in what order
- when the Connectors-first runtime activates

## Provider Selection

The `flavor_agent_openai_provider` option selects the active provider. Valid values include the built-in direct backends plus any registered Connectors API AI provider:

| Value | Label | Default |
|---|---|---|
| `azure_openai` | Azure OpenAI | Yes |
| `openai_native` | OpenAI Native | No |
| `<connector-id>` | Configured WordPress AI Client provider surfaced from `Settings > Connectors` | No |

If the option is missing or invalid, the provider defaults to `azure_openai`. The settings UI only lists connector-backed providers when they are currently usable for text generation, but the stored option may still retain a registered connector ID that later becomes unavailable.

## Chat Runtime Chain

`ChatClient::chat()` is the only entry point for block recommendations.

1. Flavor Agent reads the selected provider from `flavor_agent_openai_provider`.
2. If that selected provider is a configured connector-backed provider, requests run through `wp_ai_client_prompt()->using_provider( $provider_id )`.
3. Otherwise, if the generic WordPress AI Client path is available, Flavor Agent uses that Connectors-backed runtime first and reports the synthetic `wordpress_ai_client` runtime provider.
4. Only when no Connectors-backed runtime is available does Flavor Agent use a configured direct provider from its own settings (`azure_openai` or `openai_native`).
5. If the selected direct provider is not configured, Flavor Agent tries the other direct provider before returning the unconfigured selected provider config (`configured: false`).
6. When no chat runtime is configured anywhere, `ChatClient::is_supported()` returns `false` and the `recommend-block` ability gate prevents requests from reaching the unconfigured provider.

`ChatClient::is_supported()` returns `true` if either tier is available. This is the gate for the `flavor-agent/recommend-block` ability.

Template, template-part, navigation, Global Styles, and Style Book recommendations use the same runtime chat chain. Pattern recommendations still require a direct embeddings backend because Flavor Agent continues to own embedding generation and Qdrant indexing itself.

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

When no specific connector-backed provider is selected, Flavor Agent can still use the generic WordPress AI Client runtime whenever `WordPressAIClient::is_supported()` returns `true`.

## Backend-to-Surface Map

| Surface | Chat required | Embeddings required | Qdrant required | Cloudflare AI Search required |
|---|---|---|---|---|
| Block recommendations | Yes (ChatClient, Connectors-first) | No | No | No |
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
