# Provider Precedence Reference

This document is the contract reference for how Flavor Agent resolves which AI backend handles a request.

Use it when you need to answer:

- which backend will serve a recommendation
- what credential sources are checked and in what order
- when the WordPress AI Client fallback activates

## Provider Selection

The `flavor_agent_openai_provider` option selects the active provider. Valid values:

| Value | Label | Default |
|---|---|---|
| `azure_openai` | Azure OpenAI | Yes |
| `openai_native` | OpenAI Native | No |

If the option is missing or invalid, the provider defaults to `azure_openai`.

## Chat Fallback Chain

`ChatClient::chat()` is the only entry point for block recommendations. It checks two tiers:

1. **Plugin-managed provider** (`Provider::chat_configured()`) — uses the selected provider's chat configuration via `ResponsesClient::rank()`
2. **WordPress AI Client** (`WordPressAIClient::is_supported()`) — falls back to `wp_ai_client_prompt()` when the plugin-managed provider is not configured

`ChatClient::is_supported()` returns `true` if either tier is available. This is the gate for the `flavor-agent/recommend-block` ability.

All other recommendation surfaces (patterns, templates, template-parts, navigation, styles) use `ResponsesClient::rank()` directly and do not fall through to the WordPress AI Client.

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
