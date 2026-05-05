---
description: "Diagnose Flavor Agent embedding model option, configuration, and runtime usage issues"
name: "Diagnose Embedding Model Config"
argument-hint: "Symptom, failing test, option name, provider, or observed runtime behavior"
agent: "agent"
---

Diagnose an issue with Flavor Agent's embedding model options, configuration, validation, or runtime use.

Use the invocation arguments as the reported symptom. If the arguments are missing or too vague, ask one concise clarifying question before changing code.

## Investigation focus

Work from observed behavior back to the source of truth:

1. Identify the affected provider path:
   - Runtime Cloudflare Workers AI embeddings: `flavor_agent_cloudflare_workers_ai_account_id`, `flavor_agent_cloudflare_workers_ai_api_token`, `flavor_agent_cloudflare_workers_ai_embedding_model`
   - Legacy OpenAI Native embedding helpers: `flavor_agent_openai_native_api_key`, `flavor_agent_openai_native_embedding_model` (preserved for older installs, diagnostics, and tests, but not selected by the runtime embedding path)
   - Legacy Azure OpenAI embeddings: `flavor_agent_azure_openai_endpoint`, `flavor_agent_azure_openai_key`, `flavor_agent_azure_embedding_deployment`
   - Pattern retrieval backend choice: Qdrant with runtime plugin-owned Workers AI embeddings vs private Cloudflare AI Search
2. Confirm whether the issue is in settings rendering, sanitization, saved option preservation, capability/readiness reporting, pattern indexing, query embedding, Qdrant search, or docs/operator messaging.
3. Trace the active embedding configuration from settings input to runtime use; do not assume chat connector behavior is the embedding path.
4. Compare implementation, tests, and docs before proposing changes.

## Files to inspect first

Prioritize these files when relevant to the symptom:

- `inc/OpenAI/Provider.php` — legacy provider helpers, runtime embedding configuration, unpinned chat configuration, active request metadata
- `inc/Cloudflare/WorkersAIEmbeddingConfiguration.php` — Workers AI embedding endpoint/model configuration
- `inc/Admin/Settings/Validation.php` — sanitization and remote validation behavior
- `inc/Admin/Settings/Page.php`, `inc/Admin/Settings/Fields.php`, `inc/Admin/Settings/Config.php`, `inc/Admin/Settings/State.php`, `inc/Admin/Settings/Feedback.php` — settings UI, copy, live state, and notices
- `inc/Embeddings/EmbeddingClient.php` and `inc/Embeddings/QdrantClient.php` — embedding request and Qdrant integration
- `inc/Patterns/PatternIndex.php` and `inc/Patterns/Retrieval/` — indexing/retrieval backend use of embeddings
- `inc/Abilities/SurfaceCapabilities.php` and `inc/Abilities/InfraAbilities.php` — readiness/diagnostic ability output
- `tests/phpunit/SettingsTest.php`, `tests/phpunit/ProviderTest.php`, `tests/phpunit/EmbeddingBackendValidationTest.php`, `tests/phpunit/InfraAbilitiesTest.php` — closest PHP coverage
- `docs/SOURCE_OF_TRUTH.md`, `docs/flavor-agent-readme.md`, `docs/reference/abilities-and-routes.md`, `docs/reference/cross-surface-validation-gates.md` — expected behavior and validation gates

## Diagnostic checklist

When investigating, verify:

- The saved `flavor_agent_openai_provider` value is ignored by chat and embedding runtime paths; it should not make obsolete provider settings trigger pattern-index dependency work.
- Workers AI is the only first-party plugin-owned embedding runtime, and it is configured by the Workers AI account/token/model options rather than by saved provider selection.
- OpenAI Native credential resolution remains a legacy helper for diagnostics/tests only and must not drive active embedding readiness or Qdrant indexing.
- Legacy Azure embedding values are preserved for existing installs but are not newly rendered or save-validated.
- Blank posted embedding model fields clear only the rendered Workers AI value when intended, while unposted legacy-provider values are preserved.
- Remote validation only runs when submitted values change and is deduplicated during a single save request.
- Failed validation keeps the previous saved value and surfaces a Settings API error.
- Qdrant-backed pattern indexing and retrieval use the active plugin-owned embedding configuration.
- Cloudflare AI Search pattern retrieval does not require `EmbeddingClient` or `QdrantClient`.
- Capability and setup notices distinguish embedding model readiness, Pattern Storage readiness, and Connectors/chat readiness.
- Docs match the actual behavior if the issue affects operator-visible configuration or contracts.

## Expected output

Return a concise diagnosis with this structure:

1. **Symptom understood** — restate the specific issue and affected provider/backend.
2. **Root cause** — name the code path and why it causes the behavior.
3. **Evidence** — list the key files/tests/docs checked with relevant symbols or option names.
4. **Fix plan** — give the smallest safe change set, including any tests/docs to update.
5. **Validation** — list targeted commands to run, preferring:
   - `vendor/bin/phpunit tests/phpunit/SettingsTest.php`
   - `vendor/bin/phpunit tests/phpunit/ProviderTest.php`
   - `vendor/bin/phpunit tests/phpunit/EmbeddingBackendValidationTest.php`
   - `vendor/bin/phpunit tests/phpunit/InfraAbilitiesTest.php`
   - `npm run check:docs` when docs or operator-facing contracts change
   - `node scripts/verify.js --skip-e2e` for shared provider/backend changes

If code changes are requested or clearly necessary, implement them incrementally, add/update the nearest tests, and summarize the verification results. If credentials or external services are required, avoid printing secrets and use test doubles or documented placeholders.
