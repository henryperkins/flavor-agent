# Current Trunk Gutenberg And AI Behavior Check

## Runtime

Local WordPress stack is running:

- WordPress: `7.1-alpha-62392`
- AI plugin: `1.0.0`
- Gutenberg: `23.1.1`, with `23.2.2` available
- OpenAI provider: `1.0.2`, update available
- Anthropic provider: `1.0.2`, update available

## wp-abilities/v1

Current runtime and core trunk expose these routes:

```text
/wp-abilities/v1
/wp-abilities/v1/categories
/wp-abilities/v1/categories/(?P<slug>...)
/wp-abilities/v1/abilities
/wp-abilities/v1/abilities/(?P<name>...)
/wp-abilities/v1/abilities/(?P<name>...)/run
```

Confirmed behavior:

- Authenticated `read` capability is required for list/retrieve.
- `show_in_rest` controls whether abilities appear and can run through REST.
- `per_page=-1` is invalid in runtime: REST returns `400 rest_invalid_param`.
- `per_page=100` worked locally and returned `23` abilities and `5` categories.
- Current source/runtime include the `/abilities/` route segment for item/run endpoints. The public REST docs appear partially stale/inconsistent on that detail.

Sources:
https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/src/wp-includes/rest-api/endpoints/class-wp-rest-abilities-v1-list-controller.php
https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/src/wp-includes/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php
https://developer.wordpress.org/apis/abilities-api/rest-api-endpoints/

## @wordpress/core-abilities

There is a compatibility hazard.

Local installed Gutenberg `23.1.1`, latest available Gutenberg `23.2.2`, and npm `@wordpress/core-abilities@0.11.0` still request categories/abilities with `per_page: -1`. Current REST rejects that.

Current Gutenberg trunk has shifted shape:

- Trunk exports lazy `initialize()`.
- Installed/current package exposes auto-initialized `ready`.
- Trunk still uses `per_page: -1`.

Impact on Flavor Agent:

- With installed package, hydration fails internally because of `per_page=-1`; `ready` still resolves after logging errors.
- With trunk package shape, Flavor Agent's bridge currently does not call `initialize()`, so store hydration would not happen through the bridge.
- Flavor Agent's `src/store/abilities-client.js` fallback to direct REST still protects `flavor-agent/*` calls when `executeAbility()` reports ability-not-found.

Sources:
https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/core-abilities/src/index.ts
https://developer.wordpress.org/block-editor/reference-guides/packages/packages-core-abilities/
https://downloads.wordpress.org/plugin/gutenberg.23.2.2.zip
https://www.npmjs.com/package/@wordpress/core-abilities

## Flavor Agent Abilities

Both abilities are registered and REST-visible locally:

```text
flavor-agent/recommend-block
flavor-agent/recommend-content
```

Shared metadata:

```json
{
  "show_in_rest": true,
  "mcp": { "public": true, "type": "tool" },
  "annotations": {
    "destructive": false,
    "idempotent": false
  }
}
```

Behavior confirmed:

- Both are update-style abilities, so `GET /run` returns `405 rest_ability_invalid_method`.
- REST execution requires `POST` with JSON body shaped as `{ "input": ... }`.
- `recommend-block` accepts `resolveSignatureOnly` and returned a deterministic context signature without making a model call.
- `recommend-content` rejected an invalid mode with `400 ability_invalid_input`.

## Provider Resolution

`wp_ai_client_prompt()` currently builds from `AiClient::defaultRegistry()`.

Resolution behavior:

- `usingProvider()` locks selection to a provider.
- `usingModelPreference()` applies preferred models in order.
- Explicit `usingModel()` wins.
- Without explicit provider/model, the client searches configured providers and falls back to the first supported candidate.
- Connector API keys are passed into the AI Client registry on `init` priority `20`.
- Environment/constant credentials take precedence over database connector keys.

Local registry:

```text
registered providers: anthropic, openai
anthropic configured: yes
openai configured: yes
google connector active: yes, but no google AI provider registered locally
```

Flavor Agent's current feature developer option is:

```json
{"provider":"openai","model":"gpt-5.4-mini"}
```

That resolved locally to:

```text
provider: openai
model: gpt-5.4-mini
class: WordPress\OpenAiAiProvider\Models\OpenAiTextGenerationModel
```

So Flavor Agent currently defaults to OpenAI `gpt-5.4-mini` through the AI plugin feature developer selection. If that model lookup fails, Flavor Agent falls back to provider-level OpenAI selection; an explicit provider argument still overrides the feature setting.

Source:
https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/
