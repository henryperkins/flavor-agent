# Canonical AI Integration Guide Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Flavor Agent's WordPress AI plugin integration self-documenting, release-safe, and test-backed by aligning `docs/INTEGRATION_GUIDE.md`, release notes, dependency metadata, MCP exposure, ability annotations, and WP 7.0 harness scope with the current code.

**Architecture:** Keep `docs/INTEGRATION_GUIDE.md` as the canonical Flavor Agent integration guide, not as a transplanted upstream guide. Preserve the useful upstream WordPress AI plugin contract details, but map every example to Flavor Agent's actual feature class, ability IDs, JS transport, route migration, permissions, and MCP surface. Code changes are limited to dependency metadata and a dedicated MCP server bootstrap with capability-safe transport access; tests lock the public contracts so docs cannot drift silently.

**Tech Stack:** WordPress 7.0+, WordPress AI plugin `ai` v0.8.0+ contracts, WordPress Abilities API, MCP Adapter v0.5.0+, PHP 8.0, PHPUnit, `@wordpress/scripts`, repo docs gate.

---

## Pre-flight

**Current branch state:** The checkout may already contain unrelated user changes such as `.vscode/settings.json`, `README.md`, `docs/releases/`, and the untracked remediation spec. Do not stage, rewrite, or remove unrelated files.

**Primary source files:**
- `docs/INTEGRATION_GUIDE.md`
- `docs/superpowers/specs/2026-05-05-canonical-ai-integration-remediation.md`
- `docs/superpowers/specs/2026-05-04-canonical-ai-integration-design.md`
- `docs/reference/abilities-and-routes.md`
- `docs/reference/gutenberg-feature-tracking.md`
- `docs/reference/wordpress-ai-roadmap-tracking.md`
- `docs/reference/local-environment-setup.md`
- `flavor-agent.php`
- `inc/AI/FeatureBootstrap.php`
- `inc/Abilities/Registration.php`
- `src/store/abilities-client.js`
- `tests/phpunit/RegistrationTest.php`
- `tests/phpunit/FeatureBootstrapTest.php`

**Verification ladder:**
- Targeted docs-only changes: `npm run check:docs`
- PHP contract changes: `vendor/bin/phpunit tests/phpunit/RegistrationTest.php tests/phpunit/FeatureBootstrapTest.php tests/phpunit/MCPServerBootstrapTest.php`
- Final non-browser gate: `npm run verify -- --skip-e2e`
- MCP live smoke, when a representative WordPress runtime is available: `tools/list` and `tools/call` against `/wp-json/mcp/flavor-agent`

**Commit isolation:** Keep the plan file and implementation commits separate. If executing inline from a dirty tree, use explicit `git add <path>` commands shown in each task.

---

## File Structure

- Modify `docs/INTEGRATION_GUIDE.md`: becomes the Flavor Agent integration guide. It must explain how Flavor Agent consumes the upstream AI plugin instead of documenting the upstream plugin as if this repo were `WordPress/ai`.
- Modify `docs/superpowers/specs/2026-05-05-canonical-ai-integration-remediation.md`: mark stale findings and point execution agents to this plan before they implement obsolete F3/F4/F5 actions.
- Modify `flavor-agent.php`: declare the `ai` plugin dependency and hook the MCP server bootstrap.
- Create `inc/MCP/ServerBootstrap.php`: optional MCP Adapter integration for a dedicated Flavor Agent server at `/wp-json/mcp/flavor-agent`.
- Create `tests/phpunit/MCPServerBootstrapTest.php`: unit coverage for the MCP server bootstrap and transport capability policy.
- Modify `tests/phpunit/RegistrationTest.php`: lock the recommendation annotation policy so the docs cannot reintroduce a false `readOnlyHint:true` claim.
- Modify `readme.txt`: dependency, migration, MCP integration, and changelog communication.
- Create `CHANGELOG.md`: release-facing migration and dependency notes outside the WordPress.org readme format.
- Modify `docs/reference/abilities-and-routes.md`: align ability routing, permissions, MCP server behavior, and annotation language.
- Modify `docs/reference/gutenberg-feature-tracking.md`: correct stale annotation tracking language.
- Modify `docs/reference/wordpress-ai-roadmap-tracking.md`: replace the "no separate MCP adapter" statement after the dedicated server lands.
- Modify `docs/reference/local-environment-setup.md`: clarify that the WP 7.0 E2E harness is not the full companion-plugin runtime unless an MCP/AI-plugin-specific test extends it.

---

## Task 1: Rewrite `docs/INTEGRATION_GUIDE.md` as the Flavor Agent integration guide

**Why:** The guide is useful because it captures upstream WordPress AI plugin contracts, but its current voice says this repo is the upstream AI plugin. That sends maintainers and integrators to `ai.php`, `includes/*`, `ai/*` abilities, and companion docs that are not Flavor Agent's local contract.

**Files:**
- Modify: `docs/INTEGRATION_GUIDE.md`

- [ ] **Step 1: Confirm the stale upstream-only anchors before editing**

```bash
cd /home/dev/flavor-agent
rg -n "WordPress AI Plugin|ai.php|includes/|src/utils/run-ability|ai/excerpt-generation|ARCHITECTURE_OVERVIEW|TESTING_REST_API|experiments/" docs/INTEGRATION_GUIDE.md
```

Expected: matches in the current guide. These are the sections to preserve only as upstream-reference context, not as Flavor Agent repo instructions.

- [ ] **Step 2: Replace the guide with a Flavor Agent-specific document**

Replace `docs/INTEGRATION_GUIDE.md` with the following document. Keep the exact ability IDs, route paths, dependency language, and MCP annotation policy.

```markdown
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
```

- [ ] **Step 3: Run the docs gate**

```bash
npm run check:docs
```

Expected: PASS. If the gate fails on markdown wording or anchors, fix the guide before continuing.

- [ ] **Step 4: Commit**

```bash
git add docs/INTEGRATION_GUIDE.md
git commit -m "docs: rewrite AI integration guide for Flavor Agent"
```

---

## Task 2: Refresh the stale remediation spec so agents do not execute obsolete findings

**Why:** The current remediation spec contains useful phase framing, but some findings are false against the current tree: the bridge already falls back to REST, dead `REQUEST_META_ROUTES` entries are gone, and the per-post denial test exists.

**Files:**
- Modify: `docs/superpowers/specs/2026-05-05-canonical-ai-integration-remediation.md`

- [ ] **Step 1: Add a status correction block after the existing header**

Insert this block immediately after the existing `**Status:** Plan of record` line:

```markdown

> **Execution note added 2026-05-05:** This spec remains useful background, but it is no longer the execution plan of record. Execute `docs/superpowers/plans/2026-05-05-canonical-ai-integration-guide-remediation.md` instead. The current working tree already invalidates F3/F4/F5 as written:
>
> - F3's per-post denial coverage exists in `tests/phpunit/RegistrationTest.php`.
> - F4's bridge-unavailable throw claim is stale; `src/store/abilities-client.js` falls back to Abilities REST when the bridge is absent or unusable.
> - F5's seven dead `REQUEST_META_ROUTES` entries are already gone; `inc/REST/Agent_Controller.php` only keeps `sync-patterns`.
>
> Do not implement F3/F4/F5 literally without re-checking the current source.
```

- [ ] **Step 2: Change the status line**

Replace:

```markdown
**Status:** Plan of record
```

with:

```markdown
**Status:** Superseded for execution by `../plans/2026-05-05-canonical-ai-integration-guide-remediation.md`
```

- [ ] **Step 3: Run the docs gate**

```bash
npm run check:docs
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-05-05-canonical-ai-integration-remediation.md
git commit -m "docs: supersede stale canonical AI remediation spec"
```

---

## Task 3: Declare the AI plugin dependency and publish migration notes

**Why:** Recommendation UI depends on upstream AI plugin feature contracts. The main plugin header and readme should say so, and the release notes need a clear migration path from removed private REST routes to the Abilities API.

**Files:**
- Modify: `flavor-agent.php`
- Modify: `readme.txt`
- Create: `CHANGELOG.md`

- [ ] **Step 1: Add the plugin dependency header**

In `flavor-agent.php`, add `Requires Plugins: ai` below `Requires PHP: 8.0`:

```php
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Requires Plugins: ai
 */
```

- [ ] **Step 2: Add dependency language to `readme.txt`**

In the `= Setup ownership =` list, insert this bullet before the Connectors bullet:

```text
* Recommendation surfaces require the WordPress AI plugin (`ai`) to be installed and active because Flavor Agent registers as a downstream AI feature and ability provider.
```

In `== Installation ==`, replace steps 2-5 with:

```text
2. Install and activate the WordPress AI plugin (`ai`).
3. Activate Flavor Agent through the `Plugins` screen in WordPress.
4. Open `Settings > Connectors` to configure the shared text-generation provider used by recommendation surfaces.
5. Optional: open `Settings > Flavor Agent` to configure a pattern retrieval backend, docs grounding limits/overrides, and guidelines.
6. Optional: run `Sync Pattern Catalog` only after the selected pattern backend is configured.
```

Add this FAQ after `= Where do I configure AI providers? =`:

```text
= How do external integrations call recommendation surfaces? =

Use the WordPress Abilities API. The legacy private `POST /wp-json/flavor-agent/v1/recommend-*` endpoints were removed before the 0.2.0 contract. Call `POST /wp-json/wp-abilities/v1/abilities/flavor-agent/recommend-*/run` with the Flavor Agent payload wrapped in `{ "input": { ... } }`.
```

Update the readme changelog to include a 0.2.0 entry above 0.1.0:

```text
= 0.2.0 =
* Breaking: removed private `POST /wp-json/flavor-agent/v1/recommend-*` endpoints. Recommendation integrations now use `POST /wp-json/wp-abilities/v1/abilities/flavor-agent/recommend-*/run`.
* Added explicit dependency on the WordPress AI plugin (`ai`) for recommendation UI and feature registration.
* Added dedicated MCP Adapter server support for direct Flavor Agent recommendation tools when the MCP Adapter is active.
* Documented the per-post `edit_post` permission check for post-scoped recommendation requests.
```

- [ ] **Step 3: Create a root changelog**

Create `CHANGELOG.md` with:

```markdown
# Changelog

## 0.2.0

- Breaking: removed private `POST /wp-json/flavor-agent/v1/recommend-*` endpoints. Use `POST /wp-json/wp-abilities/v1/abilities/flavor-agent/recommend-*/run` with `{ "input": { ... } }`.
- Added an explicit WordPress plugin dependency on `ai`, the WordPress AI plugin.
- Registered Flavor Agent as a downstream AI plugin feature through `wpai_default_feature_classes`.
- Kept recommendation ability execution on POST by leaving WordPress-format `readonly` annotations unset.
- Added dedicated MCP Adapter server support at `/wp-json/mcp/flavor-agent` for the seven recommendation tools.
- Documented post-scoped permission behavior: positive post IDs require `edit_post` for that post.

## 0.1.0

- Initial release.
```

- [ ] **Step 4: Verify the dependency metadata and migration text**

```bash
rg -n "Requires Plugins: ai|WordPress AI plugin|recommend-\\*|wp-abilities/v1/abilities/flavor-agent" flavor-agent.php readme.txt CHANGELOG.md
npm run check:docs
```

Expected: `rg` prints the new dependency and migration anchors. `npm run check:docs` passes.

- [ ] **Step 5: Commit**

```bash
git add flavor-agent.php readme.txt CHANGELOG.md
git commit -m "docs: publish canonical AI dependency and route migration"
```

---

## Task 4: Lock the recommendation annotation policy in tests and docs

**Why:** Current docs claim direct MCP `readOnlyHint:true`, but current code intentionally leaves recommendation abilities non-readonly for client POST routing and does not emit `readOnlyHint`. The safe policy is: recommendation tools are non-destructive, non-idempotent, and not declared read-only because execution can persist diagnostics and freshness tokens.

**Files:**
- Modify: `tests/phpunit/RegistrationTest.php`
- Modify: `docs/reference/abilities-and-routes.md`
- Modify: `docs/reference/gutenberg-feature-tracking.md`

- [ ] **Step 1: Strengthen the registration test**

In `tests/phpunit/RegistrationTest.php`, inside `test_register_abilities_emits_annotations_for_recommend_abilities()`, replace the two assertions at the end of the loop:

```php
$this->assertArrayNotHasKey( 'readonly', $annotations, "{$ability_id} must keep WP-format readonly unset so core executes it with POST." );
$this->assertSame( $expected, $annotations, "{$ability_id} should declare LLM-invoking annotations." );
```

with:

```php
$this->assertArrayNotHasKey( 'readonly', $annotations, "{$ability_id} must keep WP-format readonly unset so core executes it with POST." );
$this->assertArrayNotHasKey( 'readOnlyHint', $annotations, "{$ability_id} must not claim direct MCP readOnlyHint because execution persists diagnostics and freshness tokens." );
$this->assertSame( $expected, $annotations, "{$ability_id} should declare LLM-invoking annotations." );
```

- [ ] **Step 2: Run the focused failing/passing test**

```bash
vendor/bin/phpunit --filter test_register_abilities_emits_annotations_for_recommend_abilities tests/phpunit/RegistrationTest.php
```

Expected: PASS. The test should pass with current code; this locks the intended policy.

- [ ] **Step 3: Update `docs/reference/abilities-and-routes.md` annotation language**

Replace the bullet that currently claims direct MCP `readOnlyHint:true` with:

```markdown
- All twenty abilities declare behavior annotations. The seven AI recommendation abilities keep WP-format `meta.annotations.readonly` unset so core and `@wordpress/core-abilities` run calls stay POST for large prompt/editor payloads; they declare `destructive:false` and `idempotent:false`, and they do not claim direct MCP `readOnlyHint:true` because execution can persist request diagnostics and freshness tokens. The 13 data-read abilities declare WP-format `readonly:true`, `destructive:false`, and `idempotent:true`.
```

- [ ] **Step 4: Update `docs/reference/gutenberg-feature-tracking.md`**

Replace both stale occurrences of "equivalent MCP `readOnlyHint`" language with this wording:

```markdown
Recommendation abilities intentionally do not set WP-format `readonly` or direct MCP `readOnlyHint`; they stay on POST and advertise only `destructive:false` and `idempotent:false`. Data-read abilities set `readonly:true`, `destructive:false`, and `idempotent:true`.
```

- [ ] **Step 5: Run docs and PHP checks**

```bash
vendor/bin/phpunit --filter test_register_abilities_emits_annotations_for_recommend_abilities tests/phpunit/RegistrationTest.php
npm run check:docs
```

Expected: both commands pass.

- [ ] **Step 6: Commit**

```bash
git add tests/phpunit/RegistrationTest.php docs/reference/abilities-and-routes.md docs/reference/gutenberg-feature-tracking.md
git commit -m "docs: align MCP annotation policy with recommendation execution"
```

---

## Task 5: Add the dedicated Flavor Agent MCP server with a capability-safe transport gate

**Why:** The universal MCP default server can bridge abilities, but a dedicated server gives external agents direct `tools/list` visibility for Flavor Agent recommendation tools. The transport gate must not require only `edit_posts`, because template, template-part, navigation, and style tools are valid for `edit_theme_options` users.

**Files:**
- Create: `inc/MCP/ServerBootstrap.php`
- Modify: `flavor-agent.php`
- Create: `tests/phpunit/MCPServerBootstrapTest.php`
- Modify: `docs/reference/abilities-and-routes.md`
- Modify: `docs/reference/wordpress-ai-roadmap-tracking.md`

- [ ] **Step 1: Add the failing MCP bootstrap test**

Create `tests/phpunit/MCPServerBootstrapTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\MCP\ServerBootstrap;
use PHPUnit\Framework\TestCase;

final class MCPServerBootstrapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$options = [
			'wpai_features_enabled'               => true,
			'wpai_feature_flavor-agent_enabled'   => true,
		];
	}

	public function test_register_no_ops_when_mcp_adapter_is_missing(): void {
		ServerBootstrap::register();

		$this->assertTrue( true, 'Missing MCP Adapter should not throw or register anything.' );
	}

	public function test_register_creates_dedicated_server_with_recommendation_tools(): void {
		$adapter = new CapturingMcpAdapter();

		ServerBootstrap::register( $adapter );

		$this->assertCount( 1, $adapter->calls );
		$call = $adapter->calls[0];

		$this->assertSame( 'flavor-agent', $call[0] );
		$this->assertSame( 'mcp', $call[1] );
		$this->assertSame( 'flavor-agent', $call[2] );
		$this->assertContains( 'flavor-agent/recommend-block', $call[9] );
		$this->assertContains( 'flavor-agent/recommend-template', $call[9] );
		$this->assertContains( 'flavor-agent/recommend-style', $call[9] );
		$this->assertCount( 7, $call[9] );
		$this->assertSame( [], $call[10] );
		$this->assertSame( [], $call[11] );
		$this->assertIsCallable( $call[12] );
	}

	public function test_transport_gate_allows_post_or_theme_capability(): void {
		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$this->assertTrue( ServerBootstrap::can_access_transport() );

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertTrue( ServerBootstrap::can_access_transport() );

		WordPressTestState::$capabilities = [
			'edit_posts'          => false,
			'edit_theme_options'  => false,
		];
		$this->assertFalse( ServerBootstrap::can_access_transport() );
	}

	public function test_register_skips_when_feature_toggle_is_disabled(): void {
		WordPressTestState::$options['wpai_feature_flavor-agent_enabled'] = false;
		$adapter = new CapturingMcpAdapter();

		ServerBootstrap::register( $adapter );

		$this->assertSame( [], $adapter->calls );
	}
}

final class CapturingMcpAdapter {
	/** @var array<int, array<int, mixed>> */
	public array $calls = [];

	public function create_server( mixed ...$args ): self {
		$this->calls[] = $args;

		return $this;
	}
}
```

- [ ] **Step 2: Run the new test and confirm it fails because the class does not exist**

```bash
vendor/bin/phpunit tests/phpunit/MCPServerBootstrapTest.php
```

Expected: FAIL with a missing `FlavorAgent\MCP\ServerBootstrap` class.

- [ ] **Step 3: Add the MCP server bootstrap**

Create `inc/MCP/ServerBootstrap.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\MCP;

use FlavorAgent\Abilities\Registration;
use FlavorAgent\AI\FeatureBootstrap;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

final class ServerBootstrap {

	public static function register( ?object $adapter = null ): void {
		if ( ! FeatureBootstrap::canonical_contracts_available() || ! FeatureBootstrap::recommendation_feature_enabled() ) {
			return;
		}

		if ( null === $adapter ) {
			if ( ! \class_exists( McpAdapter::class ) ) {
				return;
			}

			$adapter = McpAdapter::instance();
		}

		if ( ! \method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$result = $adapter->create_server(
			'flavor-agent',
			'mcp',
			'flavor-agent',
			__( 'Flavor Agent', 'flavor-agent' ),
			__( 'AI-assisted WordPress recommendations across blocks, content, navigation, patterns, styles, templates, and template parts.', 'flavor-agent' ),
			FLAVOR_AGENT_VERSION,
			[ HttpTransport::class ],
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			\array_keys( Registration::recommendation_ability_classes() ),
			[],
			[],
			[ self::class, 'can_access_transport' ]
		);

		if ( \is_wp_error( $result ) ) {
			\error_log(
				\sprintf(
					'[flavor-agent] MCP server registration failed: %s - %s',
					$result->get_error_code(),
					$result->get_error_message()
				)
			);
		}
	}

	public static function can_access_transport( mixed $request = null ): bool {
		unset( $request );

		return \current_user_can( 'edit_posts' ) || \current_user_can( 'edit_theme_options' );
	}
}
```

- [ ] **Step 4: Hook the bootstrap from `flavor-agent.php`**

Add this hook near the other top-level integration hooks:

```php
add_action( 'mcp_adapter_init', [ FlavorAgent\MCP\ServerBootstrap::class, 'register' ] );
```

- [ ] **Step 5: Run the focused MCP bootstrap tests**

```bash
vendor/bin/phpunit tests/phpunit/MCPServerBootstrapTest.php
```

Expected: PASS.

- [ ] **Step 6: Update MCP docs**

In `docs/reference/abilities-and-routes.md`, after the existing MCP note bullets, add:

```markdown
- When the MCP Adapter is active, Flavor Agent also registers a dedicated server at `/wp-json/mcp/flavor-agent`. The dedicated server exposes the seven recommendation abilities as first-class MCP tools so external agents see them directly in `tools/list`; the universal MCP default server remains available for generic ability discovery and execution.
- The dedicated server transport gate allows callers with either `edit_posts` or `edit_theme_options`, then each tool's own ability permission callback applies the exact surface policy. This avoids blocking theme-scoped tools for users who can edit site styles/templates but do not have post-editing capability.
```

In `docs/reference/wordpress-ai-roadmap-tracking.md`, replace:

```markdown
It does not implement a separate custom MCP adapter; future upstream MCP routing work still pressures the plugin's provider and ability-routing layers.
```

with:

```markdown
It also registers a dedicated MCP Adapter server at `/wp-json/mcp/flavor-agent` when the MCP Adapter is active, so the seven recommendation tools appear directly in `tools/list`. Future upstream MCP routing work still pressures provider and ability-routing layers, but Flavor Agent no longer relies only on the universal default-server bridge.
```

- [ ] **Step 7: Run PHP and docs gates**

```bash
vendor/bin/phpunit tests/phpunit/MCPServerBootstrapTest.php tests/phpunit/RegistrationTest.php tests/phpunit/FeatureBootstrapTest.php
npm run check:docs
```

Expected: both commands pass.

- [ ] **Step 8: Commit**

```bash
git add inc/MCP/ServerBootstrap.php flavor-agent.php tests/phpunit/MCPServerBootstrapTest.php docs/reference/abilities-and-routes.md docs/reference/wordpress-ai-roadmap-tracking.md
git commit -m "feat: add dedicated Flavor Agent MCP server"
```

---

## Task 6: Document WP 7.0 harness scope instead of forcing companion-plugin parity

**Why:** The WP 7.0 browser harness is valuable for editor/Site Editor behavior, but it is not the same as the representative local runtime with AI plugin, provider connectors, MCP Adapter, Plugin Check, and Flavor Agent active. Do not silently claim MCP/AI-plugin integration is covered by that harness unless a test provisions those plugins.

**Files:**
- Modify: `docs/reference/local-environment-setup.md`
- Modify: `docs/INTEGRATION_GUIDE.md`

- [ ] **Step 1: Add the harness scope note**

In `docs/reference/local-environment-setup.md`, add this section after the companion plugin list:

```markdown
## WP 7.0 Browser Harness Scope

`scripts/wp70-e2e.js` provisions a deterministic Docker-backed browser harness for editor and Site Editor regressions. It is not the full representative local runtime described above unless a test explicitly extends it with companion plugins.

Current WP 7.0 browser specs exercise Flavor Agent editor behavior and selected Abilities API routes, but they do not validate the dedicated MCP server or the AI plugin Settings UI. Use the representative local runtime for MCP/AI-plugin manual checks, or extend `scripts/wp70-e2e.js` only when adding a dedicated MCP or AI-plugin Playwright spec.
```

- [ ] **Step 2: Link the scope note from the integration guide**

In `docs/INTEGRATION_GUIDE.md`, append this sentence to the `Local Verification` section:

```markdown
The WP 7.0 browser harness is not the MCP/AI-plugin parity environment unless a specific test provisions those companion plugins; use `docs/reference/local-environment-setup.md#wp-70-browser-harness-scope` when deciding whether MCP evidence is covered.
```

- [ ] **Step 3: Run docs gate**

```bash
npm run check:docs
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add docs/reference/local-environment-setup.md docs/INTEGRATION_GUIDE.md
git commit -m "docs: clarify WP70 harness scope for AI and MCP checks"
```

---

## Task 7: Final verification and release evidence

**Why:** The work touches docs, PHP plugin metadata, a new MCP integration class, and tests. Close it with the smallest complete local gate, then record live MCP verification only when the representative runtime is available.

**Files:**
- Modify only if needed: `docs/validation/2026-05-05-canonical-ai-integration-remediation.md`

- [ ] **Step 1: Run targeted PHPUnit**

```bash
vendor/bin/phpunit tests/phpunit/MCPServerBootstrapTest.php tests/phpunit/RegistrationTest.php tests/phpunit/FeatureBootstrapTest.php tests/phpunit/AgentRoutesTest.php
```

Expected: PASS. This covers MCP bootstrap, annotation policy, feature/ability registration, and the active REST route contract.

- [ ] **Step 2: Run docs gate**

```bash
npm run check:docs
```

Expected: PASS.

- [ ] **Step 3: Run the non-browser aggregate verifier**

```bash
npm run verify -- --skip-e2e
```

Expected: final `VERIFY_RESULT={...}` reports `status:"pass"` or reports a documented environment-only incomplete step. If Plugin Check is incomplete because `WP_PLUGIN_CHECK_PATH` is unavailable, rerun with the representative environment variables from `docs/reference/local-environment-setup.md` or record the incomplete step explicitly.

- [ ] **Step 4: Run live MCP smoke when the representative runtime is available**

Use the local WordPress runtime from `docs/reference/local-environment-setup.md`, with MCP Adapter active, then run:

```bash
curl -s -X POST "http://localhost:8881/wp-json/mcp/flavor-agent" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

Expected: response includes seven direct recommendation tools with names derived from:

```text
flavor-agent/recommend-block
flavor-agent/recommend-content
flavor-agent/recommend-patterns
flavor-agent/recommend-navigation
flavor-agent/recommend-style
flavor-agent/recommend-template
flavor-agent/recommend-template-part
```

Then call one minimal tool through `tools/call` using the tool name emitted by `tools/list`. If the runtime has no configured text-generation connector, the expected result is a structured provider-unavailable error from Flavor Agent, not `tool_not_found` or a transport-level permission failure.

- [ ] **Step 5: Record verification evidence**

Create `docs/validation/2026-05-05-canonical-ai-integration-remediation.md` only after running the commands:

```markdown
# Canonical AI Integration Remediation Validation - 2026-05-05

| Gate | Result | Notes |
|---|---|---|
| `vendor/bin/phpunit tests/phpunit/MCPServerBootstrapTest.php tests/phpunit/RegistrationTest.php tests/phpunit/FeatureBootstrapTest.php tests/phpunit/AgentRoutesTest.php` | PASS | Covers MCP bootstrap, annotation policy, AI feature bootstrap, and active REST route contract. |
| `npm run check:docs` | PASS | Confirms markdown/docs references. |
| `npm run verify -- --skip-e2e` | PASS | Non-browser aggregate gate; see `output/verify/summary.json`. |
| Live MCP `tools/list` on `/wp-json/mcp/flavor-agent` | PASS or NOT RUN | Use PASS only with response evidence. Use NOT RUN when the representative runtime was unavailable. |
| Live MCP `tools/call` on one recommendation tool | PASS or NOT RUN | A provider-unavailable Flavor Agent error is acceptable when connectors are intentionally unconfigured. |
```

Replace `PASS or NOT RUN` with the actual observed result before committing. Do not create this file with unverified PASS entries.

- [ ] **Step 6: Commit verification evidence**

```bash
git add docs/validation/2026-05-05-canonical-ai-integration-remediation.md output/verify/summary.json
git commit -m "docs: record canonical AI integration verification"
```

If `output/verify/summary.json` is ignored or unchanged, commit only the validation markdown.

---

## Self-Review Checklist

- [ ] `docs/INTEGRATION_GUIDE.md` uses Flavor Agent paths and `flavor-agent/*` ability IDs throughout.
- [ ] Upstream WordPress AI plugin details are retained only as dependency/reference material.
- [ ] The private REST route removal has migration examples in the guide, readme, and changelog.
- [ ] The AI plugin dependency is visible in `flavor-agent.php` and `readme.txt`.
- [ ] The stale remediation spec points to this execution plan and warns against literal F3/F4/F5 execution.
- [ ] Recommendation annotation docs do not claim `readOnlyHint:true`.
- [ ] Tests assert recommendation annotations omit both `readonly` and `readOnlyHint`.
- [ ] Dedicated MCP server transport access allows `edit_posts` or `edit_theme_options`.
- [ ] Dedicated MCP server docs distinguish universal default-server bridge from `/wp-json/mcp/flavor-agent`.
- [ ] WP 7.0 harness docs do not claim MCP/AI-plugin parity without explicit provisioning.
- [ ] `npm run check:docs` and targeted PHPUnit commands pass before final verification.
