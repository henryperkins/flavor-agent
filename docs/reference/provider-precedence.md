# Provider Precedence Reference

This document is the contract reference for how Flavor Agent resolves which AI backend handles a request.

Use it when you need to answer:

- which backend will serve a recommendation
- what credential sources are checked and in what order
- how Connectors owns chat, plugin settings own Cloudflare setup/validation, and managed AI Search owns catalog embeddings

## Ownership Split

After Workstream C of the WP 7.0 overlap remediation, Flavor Agent has three distinct setup concerns:

- **Chat runtime** is owned by Settings > Connectors via the WordPress AI Client. There is no plugin-managed chat endpoint, deployment, or chat model anymore.
- **Cloudflare setup and validation** are plugin-owned. The account/token/model settings feed a direct Workers AI embedding validation probe and managed AI Search setup; no current recommendation feature calls the direct embedding client. Previously saved OpenAI Native, Azure OpenAI, or connector-backed provider values are not rendered as embedding choices.
- **Pattern Storage** uses Cloudflare AI Search as its sole supported store. A private site-owner AI Search instance uses managed embeddings/indexing/search and the saved shared Cloudflare account/token. Managed AI Search owns catalog embedding generation.

The `flavor_agent_openai_provider` option still exists for migration compatibility and the hidden settings-field save path, but saved values no longer select chat or embeddings:

| Saved value             | Effect on chat                                                                                 | Effect on Cloudflare setup/validation                                             |
| ----------------------- | ---------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| `openai_native`         | Ignored; use the configured WordPress AI Client runtime without pinning a provider             | Ignored; setup/validation uses the saved Cloudflare fields            |
| `cloudflare_workers_ai` | Ignored; use the configured WordPress AI Client runtime without pinning Cloudflare             | Setup/validation uses the saved Cloudflare fields regardless of value |
| `azure_openai`          | Ignored; use the configured WordPress AI Client runtime without pinning Azure                  | Ignored; setup/validation uses the saved Cloudflare fields            |
| `<connector-id>`        | Ignored; use the configured WordPress AI Client runtime without pinning the saved connector ID | Ignored; setup/validation uses the saved Cloudflare fields            |

The admin Embedding Model section no longer renders this as a provider picker. It submits `cloudflare_workers_ai` as a hidden value on save and renders only the Cloudflare Workers AI embedding fields.

## Chat Runtime Chain

Flavor Agent chat requests enter through `ChatClient::chat()` for block/content and through direct `ResponsesClient::rank()` calls for pattern, template, template-part, navigation, Global Styles, and Style Book ranking. Both paths route through `WordPressAIClient::chat()`.

1. Flavor Agent ignores saved provider values from older settings screens.
2. Explicit provider arguments to `WordPressAIClient::chat()` have highest precedence and pin that request to the supplied provider.
3. When no explicit provider is supplied, Flavor Agent reads the AI plugin Developer Tools per-feature option `wpai_feature_flavor-agent_field_developer`. A selected provider pins the request to that provider; a selected model is resolved through the WordPress AI Client registry with provider-managed fallback if the model is unavailable.
4. If no explicit provider and no AI-plugin per-feature selection exists, chat requests use the configured WordPress AI Client runtime without pinning a provider.
5. When no WordPress AI Client text-generation runtime is available, `ChatClient::chat()` returns a `missing_text_generation_provider` `WP_Error` whose message is _"Configure a text-generation provider in Settings > Connectors to enable Flavor Agent recommendations."_

`ChatClient::is_supported()` returns `true` when the WordPress AI Client has a configured text-generation runtime. This is the gate for every chat-dependent ability: `flavor-agent/recommend-block`, `recommend-content`, `recommend-template`, `recommend-template-part`, `recommend-navigation`, `recommend-style`, and the pattern-ranking phase of `flavor-agent/recommend-patterns` for the pattern retrieval path.

`Provider::chat_configuration()` reports the unpinned WordPress AI Client chat runtime when that runtime is available. Per-request Activity Log metadata records the resolved runtime separately: `requestSummary.resolvedProvider`, `requestSummary.resolvedModel`, `requestSummary.modelSelectionSource`, and `requestSummary.modelResolutionStatus` show whether a request used an explicit provider, the AI plugin per-feature selection, or the default provider-managed runtime.

After generation, the AI Client result's `providerMetadata.id` and `modelMetadata.id` supply the actual provider/model for Activity Log diagnostics, including failed requests with no answer text. The request summary continues to describe the original selection policy. Results without this metadata retain the original provider-managed or explicitly selected labels.

## Output Budget and Incomplete Responses

AI Client chat requests try a `ModelConfig.maxTokens` budget of 16,384 unless the caller supplies a sanitized `max_tokens` option. If the default makes text generation unsupported, Flavor Agent builds a fresh prompt without that default and checks support again before generating. This lets connectors such as Codex, whose model metadata does not advertise `maxTokens`, serve recommendations. Explicit caller limits and other generation options remain required. The effective settings survive schema compatibility retries, and `requestSummary.maxOutputTokens` is recorded only when a limit was retained. Anthropic connector 1.0.4 otherwise defaults to 4,096 tokens. Sonnet 5 enables adaptive thinking by default, and thinking shares the output budget with answer text; a successful HTTP request can exhaust that default without returning an answer. See [the connector's parameter mapping](https://github.com/WordPress/ai-provider-for-anthropic/blob/1.0.4/src/Models/AnthropicTextGenerationModel.php) and [Sonnet 5 behavior changes](https://platform.claude.com/docs/en/models/sonnet-5/whats-new-sonnet-5).

The first AI Client candidate's finish reason is recorded as `responseSummary.finishReason` and displayed in AI Activity. A `length` finish returns `incomplete_response` with a token-limit explanation, including when partial answer text exists. Other empty results retain `empty_response`. Thinking text is never used as the answer or copied into diagnostics, and incomplete generation does not trigger an automatic retry. The 16,384-token default is a ceiling, not a guarantee of completion; explicit caller limits and the existing 90-second timeout (overridable with `flavor_agent_wordpress_ai_client_request_timeout`) still apply.

## Reasoning Effort Routing

`flavor_agent_reasoning_effort` is the neutral Connectors-routed chat preference used when Flavor Agent can express the setting through the selected provider's model configuration. Runtime calls use the explicit request value when supplied, then the saved neutral option, then a valid legacy `flavor_agent_azure_reasoning_effort` value as a one-way fallback/migration source, and finally `medium`. Flavor Agent does not read, copy, or submit connector credentials itself; authentication and final request dispatch remain owned by the WordPress AI Client and the selected provider plugin.

At request time, `WordPressAIClient::chat()` first tries any standardized WP AI Client reasoning methods that may exist in a future core/client version. When those methods are unavailable and the resolved provider argument is a known pinned connector, it falls back to `ModelConfig::customOptions` for provider plugins that already support the needed payload shape. The normal admin/runtime path ignores saved provider IDs from older settings screens and does not pin chat to a connector. The normal unpinned Connectors runtime can still receive the neutral reasoning preference through standardized WP AI Client methods, but provider-specific custom-option fallback is applied only when a caller explicitly supplies or resolves one of these pinned provider IDs:

| Selected chat provider | Fallback custom option     |
| ---------------------- | -------------------------- |
| `codex`                | `reasoningEffort` with one of `low`, `medium`, `high`, or `xhigh` |
| `openai`               | `reasoning.effort` with one of `low`, `medium`, `high`, or `xhigh` |

Anthropic is intentionally unmapped until its provider plugin documents the accepted reasoning/thinking payload contract; Flavor Agent should add that mapping with provider-specific tests when the contract is known.

Connectors-path chat requests initially resolve provider metadata as `wordpress_ai_client`, a sentinel `Provider::is_connector()` rejects. With no explicit provider argument and no AI-plugin per-feature selection, `resolve_provider_model_selection()` returns `''`, so `$provider` reaches `WordPressAIClient::prepare_output_schema()` empty. Output-schema mitigations split on that fact, and the split is deliberate:

- **Un-gated mitigations run without a provider slug, but only one of them is lossless.** Repeated-subschema sharing (`share_repeated_subschemas()`) hoists duplicate nodes into `$defs` and replaces them with `$ref`, preserving JSON Schema semantics exactly. Union compaction (`compact_schema_for_union_limit()`, reached only when the schema exceeds `SCHEMA_UNION_LIMIT`) does **not**: it hoists the nullable ranking-contract subschema into `$defs` intact, then `compact_nullable_schema_unions()` rewrites every remaining `["X","null"]` union to `"X"`, so those fields stop accepting `null` while `required` still lists them. Both stay un-gated on purpose — gating them on `anthropic` would mean they never ran on the Connectors path, the path that needs them — but treat union compaction as a narrowing of the response contract, not a formatting pass.
- **Lossy mitigations are intentionally gated on `anthropic`.** `normalize_output_schema_for_provider()` (strips `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`, `multipleOf`) and `prepare_anthropic_output_schema()` (strips `enum`) both early-return unless `'anthropic' === $provider`. Each gives up part of the response contract, so it stays behind a known-Anthropic slug rather than firing on a guess. Do not remove those guards to make them reach the Connectors path: that silently drops schema constraints for every other provider.

After a provider explicitly rejects numeric constraints in `output_format.schema` or `output_config.format.schema`, Flavor Agent retries once with `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`, and `multipleOf` removed from schema nodes. This also handles provider-managed requests whose underlying connector is unknown. The retry builds a fresh prompt with the same selection and generation settings, retains the schema's types, enums, required fields, and closed objects, and records `requestSummary.outputSchemaFallback: unsupported_numeric_constraints`. Server-side ranking normalization still bounds scores to 0–1. Unrelated errors, schemas without numeric constraints, and a failed retry are returned without another attempt. The existing grammar-limit retry remains schema-free; either fallback is limited to one retry per chat call.

`OUTPUT_SCHEMA_BYTE_LIMIT` (4096 bytes) is a local heuristic applied to every output schema — not a published Anthropic limit, and not specific to `recommend-block`.

## Cloudflare Setup And Validation Chain

`Provider::embedding_configuration()` resolves Cloudflare account/token/model configuration metadata for setup and diagnostics. The only current first-party caller of the direct embedding client is settings validation, which sends a fixed probe. Cloudflare AI Search uses managed embeddings/indexing for pattern sync and retrieval and does not call `EmbeddingClient` there. When Workers AI credentials change, the save flow validates them with `EmbeddingClient::validate_configuration()` before creating or adopting the managed AI Search instance; unchanged values may reuse the saved Workers AI configuration and validate the managed AI Search instance/signature instead of re-probing Workers AI.

1. Flavor Agent resolves setup/validation configuration from the Cloudflare Workers AI account ID, API token, and effective embedding model. A blank or missing model falls back to `@cf/qwen/qwen3-embedding-0.6b`.
2. Saved `openai_native`, `azure_openai`, or connector-backed provider values do not change the setup/validation configuration.
3. If Cloudflare account/token settings are incomplete, configuration metadata reports them unavailable. Pattern storage also requires the shared Cloudflare account/token and a validated managed index.
4. `EmbeddingClient::validate_configuration()` validates only the Cloudflare Workers AI account ID, API token, and effective embedding model.
5. The Cloudflare AI Search pattern backend can be ready when the Cloudflare Workers AI account ID, API token, normalized AI Search embedding model, and deterministic managed `flavor-agent-patterns-{site_hash}` AI Search pattern instance are validated because pattern sync/retrieval uses managed embeddings/indexing/search instead of `EmbeddingClient`; save-time setup validates changed shared Workers AI credentials before creating or adopting that managed instance and can reuse unchanged saved values. Blank or unsupported Embedding Model values normalize to Cloudflare AI Search's supported default `@cf/qwen/qwen3-embedding-0.6b` for this private index path.

## Pattern Storage Backend Chain

Cloudflare AI Search is the sole supported pattern store. The retained `flavor_agent_pattern_retrieval_backend` compatibility value is canonicalized to `cloudflare_ai_search`; it is no longer a user-selectable provider option.

Pattern sync uploads public-safe pattern markdown to the private managed `flavor-agent-patterns-{site_hash}` instance. Recommendation requests send query text plus `visiblePatternNames` as a nested AI Search metadata filter before reranking through Connectors chat. Readiness requires shared Cloudflare account/token, a normalized supported AI Search embedding model, a validated managed index, and a usable synced catalog. Chat is additionally required for recommendations.

Older Qdrant installations must configure Cloudflare AI Search and run a new sync. Local migration does not create a remote index, copy vectors or data, or start inference. Legacy cosine thresholds are not reused; the existing `flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search` option remains distinct. Old URL/key/threshold options are inactive database leftovers retained only for uninstall cleanup. See `pattern-storage-migration.md`.

Private pattern retrieval is separate from the built-in public Cloudflare AI Search endpoint used for WordPress developer-doc grounding.

## Legacy Provider Options

Azure OpenAI and OpenAI Native embedding settings are no longer first-party admin setup paths. The live settings UI, validation path, status abilities, and embedding client all use Cloudflare Workers AI. Older saved Native/Azure option values may remain in older databases and are cleaned up on uninstall, but they no longer have a runtime validation or credential-source path.

## Cloudflare Workers AI Settings Validation

Requires a Cloudflare account ID and API token, plus an effective embedding model. If the model field is blank or absent, Flavor Agent uses `@cf/qwen/qwen3-embedding-0.6b`.

| Option                                               | Purpose               |
| ---------------------------------------------------- | --------------------- |
| `flavor_agent_cloudflare_workers_ai_account_id`      | Cloudflare account ID |
| `flavor_agent_cloudflare_workers_ai_api_token`       | API token             |
| `flavor_agent_cloudflare_workers_ai_embedding_model` | Workers AI model name |

Authentication uses the `Authorization: Bearer` header against Cloudflare's OpenAI-compatible Workers AI endpoint. Workers AI credentials are read from Flavor Agent option storage only; this backend has no environment-variable, constant, or connector-credential fallback.

## Cloudflare AI Search Pattern Retrieval

Requires the saved Cloudflare account, API token, normalized supported AI Search embedding model, and managed pattern instance to be validated:

| Option                                                  | Purpose                                      |
| ------------------------------------------------------- | -------------------------------------------- |
| `flavor_agent_cloudflare_workers_ai_account_id`         | Shared Cloudflare account ID                 |
| `flavor_agent_cloudflare_workers_ai_api_token`          | Shared Cloudflare API token                  |
| `flavor_agent_cloudflare_pattern_ai_search_instance_id` | Managed pattern AI Search instance ID        |

Authentication uses the `Authorization: Bearer` header against Cloudflare's AI Search REST API. Managed pattern storage uses Cloudflare's `default` namespace for item upload/list/delete operations. Pattern sync uploads changed public-safe registered patterns and published user `wp_block` patterns across synced, partial, and unsynced states with `wait_for_completion=false`, uses each remote item `key` as the stable Flavor Agent pattern ID, and deletes stale/retryable items by Cloudflare's generated item `id`. Unknown remote items and the owner marker are preserved. The index is ready only after listed current pattern items report `completed`; pending, missing, or retryable item-processing failures schedule follow-up syncs. Recommendation search sends the query text and `ai_search_options.retrieval.filters.pattern_name` derived from `visiblePatternNames`.

## Old Provider Values

Older saved connector-backed, OpenAI Native, or Azure provider values do not select chat or embeddings. The settings screen writes `cloudflare_workers_ai` on save, the direct settings-validation probe uses Cloudflare Workers AI, and chat uses the configured WordPress AI Client runtime.

## Backend-to-Surface Map

| Surface | Chat (Connectors) | Direct Workers AI embedding call | Cloudflare AI Search |
| --- | --- | --- | --- |
| Block recommendations | Yes | No | No |
| Pattern recommendations | Yes | No; shared account/token/model setup only | Required private managed pattern store |
| Template recommendations | Yes | No | No |
| Template-part recommendations | Yes | No | No |
| Navigation recommendations | Yes | No | No |
| Global Styles recommendations | Yes | No | No |
| Style Book recommendations | Yes | No | No |
| WordPress docs search | No | No | Disabled by default in v0.1.3 |
| Cloudflare settings validation | No | Fixed validation probe on changed complete configuration | Managed-index setup and validation |

## Primary Source Files

- `inc/OpenAI/Provider.php`
- `inc/LLM/ChatClient.php`
- `inc/LLM/WordPressAIClient.php`
- `inc/AzureOpenAI/ResponsesClient.php` (Connectors facade)
- `inc/Embeddings/EmbeddingClient.php`
- `inc/Abilities/SurfaceCapabilities.php`
- `inc/Patterns/Retrieval/PatternRetrievalBackendFactory.php`
- `inc/Cloudflare/PatternSearchClient.php`
