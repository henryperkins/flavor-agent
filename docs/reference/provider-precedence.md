# Provider Precedence Reference

This document is the contract reference for how Flavor Agent resolves which AI backend handles a request.

Use it when you need to answer:

- which backend will serve a recommendation
- what credential sources are checked and in what order
- how chat is owned by Settings > Connectors and how embeddings stay plugin-owned

## Ownership Split

After Workstream C of the WP 7.0 overlap remediation, Flavor Agent has two distinct provider concerns:

- **Chat runtime** is owned by Settings > Connectors via the WordPress AI Client. There is no plugin-managed chat endpoint, deployment, or chat model anymore.
- **Embedding runtime** is plugin-owned because Settings > Connectors does not expose embeddings yet. Pattern recommendations need embeddings, so the plugin keeps Azure OpenAI, OpenAI Native, and Cloudflare Workers AI configurations for that single purpose.

The `flavor_agent_openai_provider` option still exists, but its semantics narrowed:

| Value                                         | Effect on chat                                                                                                         | Effect on embeddings                                |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| `azure_openai`                                | No chat route unless a same-ID connector is selected/available; requests fail closed instead of using another provider | Use Azure OpenAI for embeddings                     |
| `openai_native`                               | Pin chat to the `openai` connector when that connector is available; otherwise fail closed                             | Use OpenAI Native for embeddings                    |
| `cloudflare_workers_ai`                       | Use the configured WordPress AI Client text-generation runtime without pinning a Cloudflare provider                   | Use Cloudflare Workers AI for embeddings            |
| `<connector-id>` (e.g. `openai`, `anthropic`) | Pin chat to this connector                                                                                             | Falls back to a configured direct embedding backend |

If the option is missing or invalid, it defaults to `azure_openai`.

## Chat Runtime Chain

`ChatClient::chat()` is the only chat entry point. After Workstream C, it is a thin wrapper around `ResponsesClient::rank()`, which always routes through `WordPressAIClient::chat()`.

1. Flavor Agent reads the selected provider from `flavor_agent_openai_provider`.
2. If the selected provider is a configured connector-backed provider, requests run through `wp_ai_client_prompt()->using_provider( $provider_id )` to pin chat to that connector.
3. If `openai_native` is selected, Flavor Agent pins chat to the `openai` connector when that connector is registered and supports text generation.
4. If `cloudflare_workers_ai` is selected, Flavor Agent treats that selection as embeddings-only and delegates chat to the configured WordPress AI Client runtime without calling `using_provider( 'cloudflare_workers_ai' )`.
5. Otherwise the request fails closed. Flavor Agent does **not** use the generic WordPress AI Client runtime as a provider fallback for Azure OpenAI, OpenAI Native, or connector-backed selections, so unselected providers such as Anthropic cannot handle those requests implicitly.
6. When no selected or matching Connectors-backed runtime is available, `ChatClient::chat()` returns a `missing_text_generation_provider` `WP_Error` whose message is _"Configure a text-generation provider in Settings > Connectors to enable Flavor Agent recommendations."_

`ChatClient::is_supported()` returns `true` only when the selected/matching chat connector is available. This is the gate for every chat-dependent ability: `flavor-agent/recommend-block`, `recommend-content`, `recommend-template`, `recommend-template-part`, `recommend-navigation`, `recommend-style`, and the pattern-ranking phase of `flavor-agent/recommend-patterns`.

`Provider::chat_configuration( $provider )` reports direct Azure providers as missing chat by design. `openai_native` resolves to the OpenAI connector when that connector is available. `cloudflare_workers_ai` resolves to the unpinned WordPress AI Client chat runtime because it is embeddings-only and has no corresponding chat connector. No other default runtime fallback is allowed.

## Reasoning Effort Routing

`flavor_agent_azure_reasoning_effort` is still legacy-named, but it applies to Connectors-routed chat when Flavor Agent can express the setting through the selected provider's model configuration. Flavor Agent does not read, copy, or submit connector credentials itself; authentication and final request dispatch remain owned by the WordPress AI Client and the selected provider plugin.

At request time, `WordPressAIClient::chat()` first tries any standardized WP AI Client reasoning methods that may exist in a future core/client version. When those methods are unavailable, it falls back to `ModelConfig::customOptions` for provider plugins that already support the needed payload shape:

| Selected chat provider | Fallback custom option                                      |
| ---------------------- | ----------------------------------------------------------- |
| `codex`                | `reasoningEffort: <low|medium|high|xhigh>`                  |
| `openai`               | `reasoning: { effort: <low|medium|high|xhigh> }`            |

The `openai_native` Flavor Agent setting resolves to the `openai` connector for chat, so it uses the OpenAI mapping above when the connector is available. Anthropic is intentionally unmapped until its provider plugin documents the accepted reasoning/thinking payload contract; Flavor Agent should add that mapping with provider-specific tests when the contract is known.

## Embedding Runtime Chain

`Provider::embedding_configuration()` resolves the active embedding backend. Embeddings require a fully configured Azure OpenAI, OpenAI Native, or explicitly selected Cloudflare Workers AI backend.

1. Flavor Agent reads the selected provider from `flavor_agent_openai_provider`.
2. If the selected provider is `azure_openai` and Azure embeddings are fully configured, that is the runtime.
3. If the selected provider is `openai_native` and Native embeddings are configured, that is the runtime.
4. If the selected provider is `cloudflare_workers_ai` and Workers AI embeddings are configured, that is the runtime.
5. If the selected provider is a connector ID (which has no embedding capability), Flavor Agent falls back to a configured Azure OpenAI or OpenAI Native backend.
6. Cloudflare Workers AI is intentionally skipped during fallback discovery. It must be explicitly selected so pattern text is not routed to Cloudflare without operator opt-in.
7. If no usable embedding backend is configured, embeddings are unavailable and `flavor-agent/recommend-patterns` is gated off.

## Azure OpenAI (embeddings only)

Requires all three options to be non-empty for embeddings:

| Option                                    | Purpose                     |
| ----------------------------------------- | --------------------------- |
| `flavor_agent_azure_openai_endpoint`      | Azure resource endpoint URL |
| `flavor_agent_azure_openai_key`           | API key                     |
| `flavor_agent_azure_embedding_deployment` | Embedding deployment name   |

Authentication uses the `api-key` header. Settings > Flavor Agent no longer accepts an Azure chat deployment field; existing values stored in the database from earlier releases are ignored at runtime.

## OpenAI Native (embeddings only)

Requires a non-empty API key and embedding model.

| Option                                       | Purpose                                    |
| -------------------------------------------- | ------------------------------------------ |
| `flavor_agent_openai_native_api_key`         | Plugin-specific API key (highest priority) |
| `flavor_agent_openai_native_embedding_model` | Embedding model name                       |

### API Key Fallback Chain

When `flavor_agent_openai_native_api_key` is blank, Flavor Agent reuses the WordPress Connectors API OpenAI connector lifecycle:

1. **Plugin override** — `flavor_agent_openai_native_api_key` option
2. **Environment variable** — `OPENAI_API_KEY` env var
3. **PHP constant** — `OPENAI_API_KEY` constant
4. **Connector database** — `connectors_ai_openai_api_key` option (set via Settings > Connectors)

The resolved source is tracked as `plugin_override`, `env`, `constant`, `connector_database`, or `none`. Authentication uses the `Authorization: Bearer` header.

Settings > Flavor Agent no longer accepts an OpenAI Native chat-model field.

## Cloudflare Workers AI (embeddings only)

Requires all three options to be non-empty for embeddings:

| Option                                                   | Purpose                 |
| -------------------------------------------------------- | ----------------------- |
| `flavor_agent_cloudflare_workers_ai_account_id`          | Cloudflare account ID   |
| `flavor_agent_cloudflare_workers_ai_api_token`           | API token               |
| `flavor_agent_cloudflare_workers_ai_embedding_model`     | Workers AI model name   |

Authentication uses the `Authorization: Bearer` header against Cloudflare's OpenAI-compatible Workers AI endpoint. Workers AI credentials are read from Flavor Agent option storage only; this backend has no environment-variable, constant, or connector-credential fallback.

## Connector-Backed Provider Pinning

Selecting a connector-backed provider pins chat to that connector but does not enable embeddings:

- availability is determined via `wp_ai_client_prompt()->using_provider( $provider_id )->is_supported_for_text_generation()`
- chat requests are routed via `wp_ai_client_prompt()->using_provider( $provider_id )`
- the active chat model is reported as `provider-managed`
- embedding generation remains unavailable through the connector, so pattern recommendations still require Azure OpenAI, OpenAI Native, or explicitly selected Cloudflare Workers AI to be fully configured for embeddings

When no specific connector-backed provider is selected and OpenAI Native cannot resolve the OpenAI connector, Flavor Agent reports chat as unconfigured instead of letting the generic WordPress AI Client choose another provider.

## Backend-to-Surface Map

| Surface                       | Chat (Connectors) | Embeddings (plugin) | Qdrant | Cloudflare AI Search |
| ----------------------------- | ----------------- | ------------------- | ------ | -------------------- |
| Block recommendations         | Yes               | No                  | No     | No                   |
| Pattern recommendations       | Yes               | Yes                 | Yes    | No                   |
| Template recommendations      | Yes               | No                  | No     | No                   |
| Template-part recommendations | Yes               | No                  | No     | No                   |
| Navigation recommendations    | Yes               | No                  | No     | No                   |
| Global Styles recommendations | Yes               | No                  | No     | No                   |
| Style Book recommendations    | Yes               | No                  | No     | No                   |
| WordPress docs search         | No                | No                  | No     | Yes                  |

## Primary Source Files

- `inc/OpenAI/Provider.php`
- `inc/LLM/ChatClient.php`
- `inc/LLM/WordPressAIClient.php`
- `inc/AzureOpenAI/ResponsesClient.php` (Connectors facade)
- `inc/AzureOpenAI/EmbeddingClient.php`
- `inc/Abilities/SurfaceCapabilities.php`
