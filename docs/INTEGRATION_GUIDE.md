# Flavor Agent WordPress AI Integration Guide

This guide documents how Flavor Agent integrates with the WordPress AI plugin, the WordPress AI Client, the WordPress Abilities API, and the MCP Adapter.

It is not a copy of the upstream `WordPress/ai` plugin guide. Upstream AI plugin concepts are included only where Flavor Agent depends on them.

## 1. Integration Summary

Flavor Agent is a downstream WordPress plugin. It registers one downstream AI plugin feature named `flavor-agent`, registers Flavor Agent abilities under the `flavor-agent/*` namespace, and executes recommendation surfaces through the WordPress Abilities API.

Primary local files:

| Need | Flavor Agent file |
|---|---|
| Plugin bootstrap and hooks | `flavor-agent.php` |
| Downstream AI feature registration | `inc/AI/FeatureBootstrap.php` |
| AI plugin feature class | `inc/AI/FlavorAgentFeature.php` |
| Recommendation ability classes | `inc/AI/Abilities/*Ability.php` |
| Ability registration inventory | `inc/Abilities/Registration.php` |
| Recommendation execution coordinator | `inc/Abilities/RecommendationAbilityExecution.php` |
| Editor ability transport | `src/store/abilities-client.js` |
| Packaged abilities bridge | `assets/abilities-bridge.js` |
| Programmatic contract reference | `docs/reference/abilities-and-routes.md` |

## 2. Required Runtime Components

Flavor Agent recommendation surfaces require these canonical WordPress AI contracts:

- WordPress 7.0+ with the Abilities API available.
- The WordPress AI plugin (`ai`) active, with `WordPress\AI\Abstracts\Abstract_Feature` and `WordPress\AI\Abstracts\Abstract_Ability` available.
- A connector-backed text-generation provider configured in `Settings > Connectors`.
- Flavor Agent activated and configured in `Settings > Flavor Agent` where a surface needs plugin-owned pattern retrieval or docs grounding settings.

Flavor Agent checks these contracts in `FlavorAgent\AI\FeatureBootstrap`. If the contracts are missing, recommendation UI is unavailable and administrators see a setup notice.

## 3. AI Plugin Feature Registration

Flavor Agent uses the AI plugin's class-registration filter:

```php
add_filter( 'wpai_default_feature_classes', [ FlavorAgent\AI\FeatureBootstrap::class, 'register_feature_class' ] );
```

`FeatureBootstrap::register_feature_class()` adds `FlavorAgent\AI\FlavorAgentFeature::class` under the feature ID `flavor-agent` only when the AI plugin feature contracts are available.

`FlavorAgentFeature::register()` owns the editor hooks for the first-party UI. The plugin does not register itself as an upstream `ai/*` experiment and does not place code under upstream `includes/*` paths.

## 4. Ability Inventory

The seven recommendation abilities are:

| Ability | Primary capability | Surface |
|---|---|---|
| `flavor-agent/recommend-block` | `edit_posts`, or `edit_post` when a positive post ID is present | Block Inspector recommendations |
| `flavor-agent/recommend-content` | `edit_posts`, or `edit_post` when a positive post ID is present | Content recommendations |
| `flavor-agent/recommend-patterns` | `edit_posts`, plus configured pattern backend for useful output | Pattern recommendations |
| `flavor-agent/recommend-navigation` | `edit_theme_options` | Navigation guidance |
| `flavor-agent/recommend-style` | `edit_theme_options` | Global Styles and Style Book |
| `flavor-agent/recommend-template` | `edit_theme_options` | Template recommendations |
| `flavor-agent/recommend-template-part` | `edit_theme_options` | Template-part recommendations |

Helper abilities such as `flavor-agent/introspect-block`, `flavor-agent/list-patterns`, `flavor-agent/get-theme-styles`, and `flavor-agent/check-status` remain registered in `inc/Abilities/Registration.php`. The complete inventory lives in `docs/reference/abilities-and-routes.md`.

## 5. REST Route Migration

The legacy private recommendation routes are removed:

- `POST /wp-json/flavor-agent/v1/recommend-block`
- `POST /wp-json/flavor-agent/v1/recommend-content`
- `POST /wp-json/flavor-agent/v1/recommend-patterns`
- `POST /wp-json/flavor-agent/v1/recommend-navigation`
- `POST /wp-json/flavor-agent/v1/recommend-style`
- `POST /wp-json/flavor-agent/v1/recommend-template`
- `POST /wp-json/flavor-agent/v1/recommend-template-part`

Use the Abilities API instead:

```bash
curl -X POST "https://example.test/wp-json/wp-abilities/v1/abilities/flavor-agent/recommend-block/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"input":{"editorContext":{"postId":123},"prompt":"Improve the selected block spacing."}}'
```

The request body must wrap Flavor Agent input under the top-level `input` key. The first-party editor helper `executeFlavorAgentAbility()` already sends this shape.

`/wp-json/flavor-agent/v1/sync-patterns` and `/wp-json/flavor-agent/v1/activity` remain Flavor Agent REST routes. Recommendation execution no longer uses `/flavor-agent/v1/recommend-*`.

## 6. JavaScript Transport

The editor bundle calls `executeFlavorAgentAbility()` from `src/store/abilities-client.js`.

Transport policy:

- Prefer `window.flavorAgentAbilities.executeAbility()` when the packaged bridge is loaded and no abort signal is required.
- Fall back to `@wordpress/api-fetch` against `/wp-abilities/v1/abilities/{ability}/run` when the bridge is absent, when an abort signal is required, or when the bridge reports an ability-not-found condition.
- Keep recommendation abilities non-readonly in WordPress-format annotations so the client uses POST for large editor payloads.

The bridge is shipped from `assets/abilities-bridge.js` and enqueued through `wp_enqueue_script_module()` in `flavor-agent.php`.

## 7. MCP Exposure

Flavor Agent recommendation abilities opt into MCP exposure through ability meta:

```php
[
    'show_in_rest' => true,
    'mcp'          => [
        'public' => true,
        'type'   => 'tool',
    ],
    'annotations'  => [
        'destructive' => false,
        'idempotent'  => false,
    ],
]
```

Recommendation abilities intentionally do not set WordPress-format `readonly` because that would push client execution toward GET and break large recommendation payloads. They also do not claim direct MCP `readOnlyHint:true`; execution may persist request diagnostics and freshness tokens even though it does not publish content or mutate site configuration.

When the MCP Adapter is active, Flavor Agent may expose a dedicated server at:

```text
/wp-json/mcp/flavor-agent
```

The dedicated server lists the seven recommendation tools directly. Its transport gate must allow either `edit_posts` or `edit_theme_options`; per-tool permission callbacks still enforce the specific ability capability.

## 8. Upstream WordPress AI Plugin Concepts Flavor Agent Uses

Flavor Agent depends on these upstream AI plugin concepts:

- `wpai_default_feature_classes` for downstream feature registration.
- `WordPress\AI\Abstracts\Abstract_Feature` for the `flavor-agent` feature wrapper.
- `WordPress\AI\Abstracts\Abstract_Ability` for recommendation ability classes.
- AI plugin feature toggles: `wpai_features_enabled` and `wpai_feature_flavor-agent_enabled`.
- WordPress AI Client helpers for connector-backed text generation.

Use the upstream `WordPress/ai` source when those contracts change, but keep Flavor Agent examples in the `flavor-agent/*` namespace.

## 9. Local Verification

Recommended targeted checks after integration changes:

```bash
npm run check:docs
vendor/bin/phpunit tests/phpunit/RegistrationTest.php tests/phpunit/FeatureBootstrapTest.php
npm run verify -- --skip-e2e
```

Representative local runtime checks require the companion plugin stack documented in `docs/reference/local-environment-setup.md`, including the AI plugin, AI Services, provider connectors, MCP Adapter, Gutenberg, Plugin Check, and Flavor Agent.

The WP 7.0 browser harness is not the MCP/AI-plugin parity environment unless a specific test provisions those companion plugins; use `docs/reference/local-environment-setup.md#wp-70-browser-harness-scope` when deciding whether MCP evidence is covered.
