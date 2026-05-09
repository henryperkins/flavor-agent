# Gutenberg 23.1 And AI 0.9 Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Flavor Agent reliable against the current test-container release line: Gutenberg `23.1.1`, AI plugin `0.9.0`, and WordPress nightly `7.1-alpha-62341`.

**Architecture:** Keep Flavor Agent on the canonical WordPress AI Client and Abilities paths. The Abilities readiness and Guidelines query work is already shipped and should only be verified; the remaining code work is the per-feature model-selection glue plus a non-destructive Settings state contract. Treat upstream Guidelines and Connectors churn as contracts to verify locally, with one upstream AI-plugin follow-up for its untyped Guidelines service.

**Tech Stack:** WordPress plugin PHP, Gutenberg `@wordpress/core-abilities` readiness plus `@wordpress/abilities` execution, WordPress AI Client, AI plugin feature settings, PHPUnit, Jest via `@wordpress/scripts`, WP-CLI inside the Docker test container.

---

## Evidence Baseline

- Current container target: Gutenberg `23.1.1`, AI plugin `0.9.0`, WordPress `7.1-alpha-62341`.
- Current hard contracts to preserve:
  - Guidelines CPT: `wp_guideline`
  - Guidelines taxonomy: `wp_guideline_type`
  - Flavor Agent content guideline query: taxonomy slug `content`
  - AI plugin per-feature developer option: `wpai_feature_flavor-agent_field_developer`
  - Client ability bridge global: `window.flavorAgentAbilities`
- Current verified runtime facts:
  - `wp post-type list --format=json` includes `wp_guideline`.
  - `wp taxonomy list --format=json` includes `wp_guideline_type`.
  - A temporary `wp_guideline` assigned `wp_guideline_type=content` is returned by Flavor Agent's expected tax query.
  - `wpai_feature_flavor-agent_field_developer` is currently unset until an admin chooses a feature-level provider/model in AI plugin Developer Tools.

## File Structure

- Already shipped in `assets/abilities-bridge.js`: `ready` is read from `@wordpress/core-abilities`; `executeAbility` is imported from `@wordpress/abilities`; the frozen bridge global is in place.
- Already shipped in `src/store/abilities-client.js`: the no-AbortSignal bridge path waits for bridge readiness and falls through to REST if readiness rejects.
- Already shipped in `src/store/__tests__/abilities-client.test.js`: bridge readiness wait and readiness-rejection REST fallback are covered.
- Already shipped in `src/store/__tests__/abilities-bridge.test.js`: the bridge asset contract covers readiness and the fallback ready promise.
- Already shipped in `tests/phpunit/GuidelinesTest.php`: the `wp_guideline_type=content` tax query is locked in PHPUnit.
- Create `inc/AI/FeatureModelSelection.php`: sanitize and expose AI plugin's per-feature developer provider/model option for Flavor Agent.
- Modify `inc/LLM/WordPressAIClient.php`: apply per-feature provider/model selection when no explicit provider argument is supplied, and include the selected values in diagnostics.
- Modify `inc/OpenAI/Provider.php`: add a public runtime chat-configuration recorder so Activity Log metadata can report the provider/model actually selected for the request.
- Modify `tests/phpunit/bootstrap.php`: extend the AI Client test double with `using_model()` and a fake `WordPress\AiClient\AiClient::defaultRegistry()->getProviderModel()` path.
- Modify `tests/phpunit/WordPressAIClientTest.php`: cover developer provider/model precedence, provider-only fallback, explicit-provider precedence, and Activity Log metadata.
- Modify `tests/phpunit/RecommendationAbilityExecutionTest.php`: assert the resolved provider/model request metadata persists into `request_diagnostic` activity rows.
- Modify `tests/phpunit/SettingsTest.php`: add a non-destructive Settings state contract for provider-managed chat readiness.
- Modify `docs/README.md`: index the retained validation record if the dated validation doc remains in `docs/reference/`.
- Modify `docs/reference/gutenberg-feature-tracking.md`: update the snapshot row for Gutenberg `23.1.1` and record no-action watch items.
- Modify `docs/reference/wordpress-ai-roadmap-tracking.md`: update the AI plugin overlay to `0.9.0` and record the developer model-selection integration.
- Create `docs/reference/2026-05-09-gutenberg-23-1-ai-0-9-validation.md`: record container versions, WP-CLI smoke output summaries, upstream issue link, and verification commands.

---

### Task 1: Lock Container Release Baseline

**Files:**
- Create: `docs/reference/2026-05-09-gutenberg-23-1-ai-0-9-validation.md`
- Modify: `docs/README.md`
- Modify: `docs/reference/gutenberg-feature-tracking.md`
- Modify: `docs/reference/wordpress-ai-roadmap-tracking.md`

- [ ] **Step 1: Confirm active plugin versions in the running container**

Run:

```bash
docker compose exec -T wordpress wp plugin list --name=gutenberg,ai --format=json --allow-root
docker compose exec -T wordpress wp core version --allow-root
```

Expected:

```text
The plugin list JSON contains Gutenberg 23.1.1 and AI 0.9.0, both active.
The core version command prints 7.1-alpha-62341 or a newer nightly from the same trunk line.
```

- [ ] **Step 2: Confirm there are no pending updates for the two release plugins**

Run:

```bash
docker compose exec -T wordpress wp plugin list --name=gutenberg,ai --fields=name,status,version,update --format=json --allow-root
```

Expected:

```text
Both rows have "update":"none".
```

- [ ] **Step 3: Create the validation record**

Add this file:

````markdown
# Gutenberg 23.1 And AI 0.9 Validation

Snapshot date: 2026-05-09.

## Runtime Versions

| Component | Expected value | Observed value |
| --- | --- | --- |
| WordPress core | `7.1-alpha-62341` or newer trunk nightly | `7.1-alpha-62341` |
| Gutenberg plugin | `23.1.1` | `23.1.1` |
| AI plugin | `0.9.0` | `0.9.0` |

## WP-CLI Smoke Checks

| Check | Command | Expected outcome | Observed outcome |
| --- | --- | --- | --- |
| Release plugins active | `wp plugin list --name=gutenberg,ai --fields=name,status,version,update --format=json` | Gutenberg and AI active with no pending update | Gutenberg `23.1.1` active with `update:none`; AI `0.9.0` active with `update:none` |
| Guidelines post type | `wp post-type list --format=json` | includes `wp_guideline`; does not require `wp_content_guideline` | `wp_guideline` exists; `wp_content_guideline` is not required |
| Guidelines taxonomy | `wp taxonomy list --format=json` | includes `wp_guideline_type` | `wp_guideline_type` exists |
| Content guideline query | temporary `wp_guideline` with `wp_guideline_type=content` | Flavor Agent query returns exactly the content guideline row | temporary `content` row was returned and then deleted |
| AI developer option | `wp option get wpai_feature_flavor-agent_field_developer --format=json` | unset or sanitized provider/model object | option unset in the baseline container |

## Upstream Follow-Up

AI plugin `0.9.0` includes an internal Guidelines service that queries the latest `wp_guideline` without filtering `wp_guideline_type=content`. File the upstream issue described in Task 6 and add the final GitHub issue URL to this section before the implementation branch is complete.

## Verification Commands

```bash
npx wp-scripts lint-js assets/abilities-bridge.js src/store/abilities-client.js src/store/__tests__/abilities-client.test.js src/store/__tests__/abilities-bridge.test.js
npm run test:unit -- src/store/__tests__/abilities-client.test.js src/store/__tests__/abilities-bridge.test.js --runInBand
vendor/bin/phpunit --filter GuidelinesTest
vendor/bin/phpunit --filter WordPressAIClientTest
vendor/bin/phpunit --filter RecommendationAbilityExecutionTest::test_execute_persists_resolved_provider_fields_in_request_diagnostic_activity
vendor/bin/phpunit --filter SettingsTest::test_page_state_treats_wordpress_ai_client_runtime_as_ready_for_chat
npm run test:e2e:playground
npm run check:docs
node scripts/verify.js --skip-e2e
```
````

Because this validation record is retained under `docs/reference/`, add it to the `docs/README.md` reference-doc index:

```markdown
Programmatic and UI contract docs: `docs/reference/` (abilities-and-routes, shared-internals, recommendation-ui-consistency, cross-surface-validation-gates, release-surface-scope-review, surfaces/release-stop-lines, release-submission-and-review, pattern-recommendation-debugging, provider-precedence, external-service-disclosure, template-operations, activity-state-machine, local-environment-setup, wordpress-ai-roadmap-tracking, gutenberg-feature-tracking, 2026-05-09-gutenberg-23-1-ai-0-9-validation, agentic-plan-implementation-guide)
```

- [ ] **Step 4: Update the Gutenberg tracking snapshot**

In `docs/reference/gutenberg-feature-tracking.md`, change:

```markdown
Snapshot date: 2026-04-29.
Latest Gutenberg release at snapshot: `23.0.0` (2026-04-22).
```

to:

```markdown
Snapshot date: 2026-05-09.
Latest Gutenberg release at snapshot: `23.1.1` (verified in the local test container).
```

Then add a `23.1` row to **Versions Tracked**:

```markdown
| 23.1 | 2026-05 | Core Abilities exposes a readiness promise; Guidelines classes/routes moved from content-guidelines naming to guidelines naming while retaining `wp_guideline` and `wp_guideline_type`; Guidelines became type-aware; network-active connector plugins count as active. |
```

- [ ] **Step 5: Update the AI tracking snapshot**

In `docs/reference/wordpress-ai-roadmap-tracking.md`, change:

```markdown
- Snapshot date: 2026-04-28
```

to:

```markdown
- Snapshot date: 2026-05-09
```

Then update the AI plugin milestone overlay line so it says `refreshed: 2026-05-09` and records `WordPress/ai` milestone #17 as `0.9.0` verified in the local container.

Append this paragraph after the milestone overlay intro:

```markdown
AI plugin `0.9.0` was verified in the local test container on 2026-05-09. Flavor Agent now treats the AI plugin Developer Tools per-feature option `wpai_feature_flavor-agent_field_developer` as the canonical feature-level provider/model preference when present, while explicit per-call provider arguments keep highest precedence.
```

- [ ] **Step 6: Run documentation checks**

Run:

```bash
npm run check:docs
```

Expected:

```text
The docs check exits 0.
```

---

### Task 2: Already Shipped - Verify The Core Abilities Readiness Race

**Files:**
- Verify: `assets/abilities-bridge.js`
- Verify: `src/store/abilities-client.js`
- Verify: `src/store/__tests__/abilities-client.test.js`
- Verify: `src/store/__tests__/abilities-bridge.test.js`

- [x] **Step 1: Confirm the shipped bridge imports the correct packages**

Current contract:

```js
import * as coreAbilities from '@wordpress/core-abilities';
import { executeAbility } from '@wordpress/abilities';
```

`ready` belongs to `@wordpress/core-abilities`; `executeAbility` belongs to `@wordpress/abilities` in the current WP 7.x test line. Do not collapse both imports onto `@wordpress/core-abilities`.

- [x] **Step 2: Confirm the shipped client waits for readiness before bridge execution**

Current contract:

```js
if ( typeof bridge === 'function' && ! signal ) {
	if ( await isBridgeReady( bridgeApi ) ) {
		try {
			return normalizeAbilityExecutionResult(
				await bridge( abilityName, data )
			);
		} catch ( error ) {
			if ( ! shouldFallbackToRest( error ) ) {
				throw error;
			}
		}
	}
}
```

- [ ] **Step 3: Run bridge verification**

Run:

```bash
npx wp-scripts lint-js assets/abilities-bridge.js src/store/abilities-client.js src/store/__tests__/abilities-client.test.js src/store/__tests__/abilities-bridge.test.js
npm run test:unit -- src/store/__tests__/abilities-client.test.js src/store/__tests__/abilities-bridge.test.js --runInBand
```

Expected:

```text
Both commands exit 0.
```

---

### Task 3: Already Shipped - Verify Guidelines Type-Aware Query Compatibility

**Files:**
- Verify: `tests/phpunit/GuidelinesTest.php`
- Verify: `inc/Guidelines/CoreGuidelinesRepository.php`
- Modify: `docs/reference/2026-05-09-gutenberg-23-1-ai-0-9-validation.md`

- [x] **Step 1: Confirm the shipped production query filters content guidelines**

Current `inc/Guidelines/CoreGuidelinesRepository.php` contract:

```php
$args['tax_query'] = [
	[
		'taxonomy' => self::TAXONOMY,
		'field'    => 'slug',
		'terms'    => 'content',
	],
];
```

- [x] **Step 2: Confirm the shipped PHPUnit assertion locks the same query**

Current `tests/phpunit/GuidelinesTest.php` contract:

```php
$this->assertSame( 'wp_guideline', WordPressTestState::$get_posts_calls[0]['post_type'] ?? '' );
$this->assertSame(
	[
		[
			'taxonomy' => 'wp_guideline_type',
			'field'    => 'slug',
			'terms'    => 'content',
		],
	],
	WordPressTestState::$get_posts_calls[0]['tax_query'] ?? []
);
```

- [ ] **Step 3: Run the targeted PHPUnit suite**

Run:

```bash
vendor/bin/phpunit --filter GuidelinesTest
```

Expected:

```text
The GuidelinesTest suite exits 0.
```

- [ ] **Step 4: Run post type and taxonomy smoke checks in the container**

Run:

```bash
docker compose exec -T wordpress wp post-type list --format=json --allow-root
docker compose exec -T wordpress wp taxonomy list --format=json --allow-root
```

Expected:

```text
The post type JSON includes wp_guideline.
The taxonomy JSON includes wp_guideline_type.
The post type JSON does not need to include wp_content_guideline.
```

- [ ] **Step 5: Prove the content tax query against the live database**

Run:

```bash
docker compose exec -T wordpress wp term create wp_guideline_type content --slug=content --allow-root
docker compose exec -T wordpress wp post create --post_type=wp_guideline --post_status=publish --post_title='Flavor Agent content guideline smoke' --porcelain --allow-root
```

Store the printed post ID in `GUIDELINE_ID`, then run:

```bash
docker compose exec -T wordpress wp post term set "$GUIDELINE_ID" wp_guideline_type content --by=slug --allow-root
docker compose exec -T wordpress wp post list --post_type=wp_guideline --tax_query='[{"taxonomy":"wp_guideline_type","field":"slug","terms":"content"}]' --format=json --allow-root
docker compose exec -T wordpress wp post delete "$GUIDELINE_ID" --force --allow-root
```

Expected:

```text
The post list command returns the temporary smoke post before cleanup.
The cleanup command deletes the temporary smoke post.
```

- [ ] **Step 6: Record the live smoke output summary**

In `docs/reference/2026-05-09-gutenberg-23-1-ai-0-9-validation.md`, replace the observed outcome cells for Guidelines with:

```markdown
`wp_guideline` exists, `wp_guideline_type` exists, and a temporary `wp_guideline_type=content` row was returned by the expected tax query before cleanup.
```

---

### Task 4: Honor AI Plugin Per-Feature Developer Provider And Model

**Files:**
- Create: `inc/AI/FeatureModelSelection.php`
- Modify: `inc/LLM/WordPressAIClient.php`
- Modify: `inc/OpenAI/Provider.php`
- Modify: `tests/phpunit/bootstrap.php`
- Modify: `tests/phpunit/WordPressAIClientTest.php`
- Modify: `tests/phpunit/RecommendationAbilityExecutionTest.php`

- [ ] **Step 1: Confirm the WordPress AI Client model API in the active AI plugin source**

Run:

```bash
docker compose exec -T wordpress sh -lc "nl -ba /var/www/html/wp-content/plugins/ai/includes/Abstracts/Abstract_Ability.php | sed -n '296,330p'"
docker compose exec -T wordpress sh -lc "nl -ba /var/www/html/wp-content/plugins/ai/includes/helpers.php | sed -n '330,344p'"
```

Expected:

```text
AI plugin 0.9 reads get_feature_developer_model_config( $feature_class::get_id() ).
When provider and model are both present it calls $prompt_builder->using_model( AiClient::defaultRegistry()->getProviderModel( $provider, $model ) ).
When only provider is present it calls $prompt_builder->using_provider( $provider ).
```

- [ ] **Step 2: Write the failing provider/model tests**

Add these tests to `tests/phpunit/WordPressAIClientTest.php`:

```php
private static function register_ai_provider_connector( string $provider, string $name ): void {
	WordPressTestState::$connectors[ $provider ] = [
		'name'           => $name,
		'description'    => $name . ' connector',
		'type'           => 'ai_provider',
		'authentication' => [
			'method'       => 'api_key',
			'setting_name' => 'connectors_ai_' . $provider . '_api_key',
		],
	];
}

public function test_chat_uses_ai_plugin_feature_developer_provider_and_model_when_no_provider_is_explicit(): void {
	self::register_ai_provider_connector( 'anthropic', 'Anthropic' );
	WordPressTestState::$options = [
		'wpai_feature_flavor-agent_field_developer' => [
			'provider' => 'anthropic',
			'model'    => 'claude-sonnet-4-6',
		],
	];
	WordPressTestState::$ai_client_provider_support     = [
		'anthropic' => true,
	];
	WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';

	$this->assertSame( 'anthropic', \FlavorAgent\OpenAI\Provider::chat_configuration( 'anthropic' )['provider'] ?? null );

	$result = WordPressAIClient::chat( 'System.', 'User.' );
	$meta   = \FlavorAgent\OpenAI\Provider::active_chat_request_meta();

	$this->assertSame( '{"explanation":"OK."}', $result );
	$this->assertSame( 'anthropic', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
	$this->assertSame( 'claude-sonnet-4-6', WordPressTestState::$last_ai_client_prompt['model'] ?? null );
	$this->assertSame( 'anthropic', $meta['provider'] ?? null );
	$this->assertSame( 'claude-sonnet-4-6', $meta['model'] ?? null );
	$this->assertSame( 'anthropic', $meta['requestSummary']['resolvedProvider'] ?? null );
	$this->assertSame( 'claude-sonnet-4-6', $meta['requestSummary']['resolvedModel'] ?? null );
	$this->assertSame( 'ai_plugin_feature_developer', $meta['requestSummary']['modelSelectionSource'] ?? null );
	$this->assertSame( 'model', $meta['requestSummary']['modelResolutionStatus'] ?? null );
	$this->assertArrayNotHasKey( 'selectedProvider', $meta['requestSummary'] ?? [] );
}

public function test_chat_uses_ai_plugin_feature_developer_provider_without_model(): void {
	self::register_ai_provider_connector( 'openai', 'OpenAI' );
	WordPressTestState::$options = [
		'wpai_feature_flavor-agent_field_developer' => [
			'provider' => 'openai',
			'model'    => '',
		],
	];
	WordPressTestState::$ai_client_provider_support     = [
		'openai' => true,
	];
	WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';

	$this->assertSame( 'openai', \FlavorAgent\OpenAI\Provider::chat_configuration( 'openai' )['provider'] ?? null );

	$result = WordPressAIClient::chat( 'System.', 'User.' );
	$meta   = \FlavorAgent\OpenAI\Provider::active_chat_request_meta();

	$this->assertSame( '{"explanation":"OK."}', $result );
	$this->assertSame( 'openai', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
	$this->assertArrayNotHasKey( 'model', WordPressTestState::$last_ai_client_prompt );
	$this->assertSame( 'openai', $meta['provider'] ?? null );
	$this->assertSame( 'provider-managed', $meta['model'] ?? null );
	$this->assertSame( 'openai', $meta['requestSummary']['resolvedProvider'] ?? null );
	$this->assertSame( 'provider-managed', $meta['requestSummary']['resolvedModel'] ?? null );
	$this->assertSame( 'ai_plugin_feature_developer', $meta['requestSummary']['modelSelectionSource'] ?? null );
	$this->assertSame( 'provider', $meta['requestSummary']['modelResolutionStatus'] ?? null );
}

public function test_chat_explicit_provider_overrides_ai_plugin_feature_developer_selection(): void {
	self::register_ai_provider_connector( 'anthropic', 'Anthropic' );
	self::register_ai_provider_connector( 'openai', 'OpenAI' );
	WordPressTestState::$options = [
		'wpai_feature_flavor-agent_field_developer' => [
			'provider' => 'anthropic',
			'model'    => 'claude-sonnet-4-6',
		],
	];
	WordPressTestState::$ai_client_provider_support     = [
		'openai' => true,
	];
	WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';

	$result = WordPressAIClient::chat( 'System.', 'User.', 'openai' );
	$meta   = \FlavorAgent\OpenAI\Provider::active_chat_request_meta();

	$this->assertSame( '{"explanation":"OK."}', $result );
	$this->assertSame( 'openai', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
	$this->assertArrayNotHasKey( 'model', WordPressTestState::$last_ai_client_prompt );
	$this->assertSame( 'openai', $meta['provider'] ?? null );
	$this->assertSame( 'provider-managed', $meta['model'] ?? null );
	$this->assertSame( 'openai', $meta['requestSummary']['resolvedProvider'] ?? null );
	$this->assertSame( 'provider-managed', $meta['requestSummary']['resolvedModel'] ?? null );
	$this->assertSame( 'explicit', $meta['requestSummary']['modelSelectionSource'] ?? null );
	$this->assertSame( 'provider', $meta['requestSummary']['modelResolutionStatus'] ?? null );
}

public function test_chat_uses_developer_selected_provider_for_reasoning_effort_custom_options(): void {
	self::register_ai_provider_connector( 'codex', 'Codex' );
	WordPressTestState::$options = [
		'wpai_feature_flavor-agent_field_developer' => [
			'provider' => 'codex',
			'model'    => '',
		],
	];
	WordPressTestState::$ai_client_provider_support     = [
		'codex' => true,
	];
	WordPressTestState::$ai_client_feature_support      = [
		'reasoning' => false,
	];
	WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';

	$result = WordPressAIClient::chat( 'System.', 'User.', null, 'high' );

	$this->assertSame( '{"explanation":"OK."}', $result );
	$this->assertArrayNotHasKey( 'reasoning', WordPressTestState::$last_ai_client_prompt );
	$this->assertSame(
		[ 'reasoningEffort' => 'high' ],
		WordPressTestState::$last_ai_client_prompt['customOptions'] ?? null
	);
}

public function test_chat_trace_context_uses_developer_selected_provider(): void {
	self::register_ai_provider_connector( 'codex', 'Codex' );
	WordPressTestState::$options = [
		'wpai_feature_flavor-agent_field_developer' => [
			'provider' => 'codex',
			'model'    => '',
		],
	];
	WordPressTestState::$ai_client_provider_support     = [
		'codex' => true,
	];
	WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';
	$events = [];

	add_filter( 'flavor_agent_diagnostic_trace_enabled', '__return_false' );
	add_action(
		'flavor_agent_diagnostic_trace',
		static function ( array $entry ) use ( &$events ): void {
			$events[] = $entry;
		}
	);

	WordPressAIClient::chat( 'System.', 'User.', null, 'high' );

	$this->assertNotEmpty( $events );
	$this->assertSame( 'codex', $events[0]['context']['provider'] ?? null );
	$this->assertSame( 'high', $events[0]['context']['reasoningEffort'] ?? null );
}
```

The provider-specific tests deliberately register developer-selected providers as `ai_provider` connectors before asserting Activity Log metadata. `Provider::active_chat_request_meta()` normalizes unregistered provider strings to Flavor Agent's direct fallback provider, while the AI plugin Developer Tools selector only offers registered provider connectors in production.

Add this persistence assertion to `tests/phpunit/RecommendationAbilityExecutionTest.php`:

```php
public function test_execute_persists_resolved_provider_fields_in_request_diagnostic_activity(): void {
	RecommendationAbilityExecution::execute(
		'template',
		'flavor-agent/recommend-template',
		[
			'templateRef' => 'theme//home',
			'prompt'      => 'Tighten the structure.',
			'document'    => [
				'scopeKey' => 'wp_template:theme//home',
				'postType' => 'wp_template',
				'entityId' => 'theme//home',
			],
		],
		static fn(): array => [
			'suggestions' => [],
			'explanation' => 'Use fewer competing sections.',
			'requestMeta' => [
				'provider'       => 'anthropic',
				'model'          => 'claude-sonnet-4-6',
				'requestSummary' => [
					'resolvedProvider'     => 'anthropic',
					'resolvedModel'        => 'claude-sonnet-4-6',
					'modelSelectionSource'  => 'ai_plugin_feature_developer',
					'modelResolutionStatus' => 'model',
				],
			],
		]
	);

	$entries = WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? [];

	$this->assertCount( 1, $entries );
	$this->assertSame( 'request_diagnostic', $entries[0]['activity_type'] ?? null );

	$request = json_decode( (string) ( $entries[0]['request_json'] ?? '' ), true );
	$this->assertSame( 'anthropic', $request['ai']['provider'] ?? null );
	$this->assertSame( 'claude-sonnet-4-6', $request['ai']['model'] ?? null );
	$this->assertSame( 'anthropic', $request['ai']['requestSummary']['resolvedProvider'] ?? null );
	$this->assertSame( 'claude-sonnet-4-6', $request['ai']['requestSummary']['resolvedModel'] ?? null );
	$this->assertSame( 'ai_plugin_feature_developer', $request['ai']['requestSummary']['modelSelectionSource'] ?? null );
	$this->assertSame( 'model', $request['ai']['requestSummary']['modelResolutionStatus'] ?? null );
}
```

- [ ] **Step 3: Run the tests and verify they fail before implementation**

Run:

```bash
vendor/bin/phpunit --filter WordPressAIClientTest
vendor/bin/phpunit --filter RecommendationAbilityExecutionTest::test_execute_persists_resolved_provider_fields_in_request_diagnostic_activity
```

Expected before implementation:

```text
At least the new provider/model assertions fail because Flavor Agent does not read wpai_feature_flavor-agent_field_developer.
```

- [ ] **Step 4: Add the feature model-selection reader**

Create `inc/AI/FeatureModelSelection.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FeatureModelSelection {

	public const OPTION_NAME = 'wpai_feature_flavor-agent_field_developer';

	/**
	 * @return array{provider: string, model: string}
	 */
	public static function get(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return self::empty();
		}

		$value = get_option( self::OPTION_NAME, [] );

		if ( ! is_array( $value ) ) {
			return self::empty();
		}

		$provider = sanitize_key( (string) ( $value['provider'] ?? '' ) );
		$model    = self::sanitize_model( $value['model'] ?? '' );

		return [
			'provider' => $provider,
			'model'    => $model,
		];
	}

	public static function has_selection(): bool {
		$selection = self::get();

		return '' !== $selection['provider'];
	}

	/**
	 * @return array{provider: string, model: string}
	 */
	private static function empty(): array {
		return [
			'provider' => '',
			'model'    => '',
		];
	}

	private static function sanitize_model( mixed $model ): string {
		if ( ! is_scalar( $model ) ) {
			return '';
		}

		$model = trim( (string) $model );

		if ( '' === $model ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9._:\\/-]/', '', $model ) ?? '';
	}
}
```

- [ ] **Step 5: Extend both test AI Client doubles**

In `tests/phpunit/bootstrap.php`, add a fake registry in namespace `WordPress\AiClient`:

```php
namespace WordPress\AiClient {

	if ( ! class_exists( AiClient::class ) ) {
		final class AiClient {
			public static function defaultRegistry(): FakeProviderModelRegistry {
				return new FakeProviderModelRegistry();
			}
		}
	}

	if ( ! class_exists( FakeProviderModelRegistry::class ) ) {
		final class FakeProviderModelRegistry {
			public function getProviderModel( string $provider, string $model ): object {
				return (object) [
					'provider' => $provider,
					'model'    => $model,
				];
			}
		}
	}
}
```

In `WordPress\AI_Client\FakePromptBuilder`, add this method:

```php
public function using_model( object $model ): self {
	WordPressTestState::$last_ai_client_prompt['provider'] = (string) ( $model->provider ?? '' );
	WordPressTestState::$last_ai_client_prompt['model']    = (string) ( $model->model ?? '' );

	return $this;
}
```

In `WP_AI_Client_Prompt_Builder::__call()`, add this case:

```php
case 'using_model':
	$model = $arguments[0] ?? null;

	if ( is_object( $model ) ) {
		$this->state['provider'] = (string) ( $model->provider ?? '' );
		$this->state['model']    = (string) ( $model->model ?? '' );
	}
	$this->sync_state();

	return $this;
```

- [ ] **Step 6: Add runtime chat-configuration recording**

In `inc/OpenAI/Provider.php`, add this public method next to the existing runtime metric and diagnostic recorders:

```php
/**
 * @param array<string, mixed>|null $configuration
 */
public static function record_runtime_chat_configuration( ?array $configuration ): void {
	if ( ! is_array( $configuration ) ) {
		self::$last_runtime_chat_configuration      = null;
		self::$has_fresh_runtime_chat_configuration = true;

		return;
	}

	$provider = self::normalize_provider_for_request_meta(
		(string) ( $configuration['provider'] ?? self::WORDPRESS_AI_CLIENT_PROVIDER )
	);
	$model = trim( (string) ( $configuration['model'] ?? '' ) );
	$label = self::provider_label_for_request_meta( $provider );

	self::$last_runtime_chat_configuration = [
		'provider'   => $provider,
		'endpoint'   => '',
		'api_key'    => '',
		'model'      => '' !== $model ? $model : 'provider-managed',
		'configured' => true,
		'headers'    => [],
		'url'        => '',
		'label'      => $label,
	];
	self::$has_fresh_runtime_chat_configuration = true;
}
```

- [ ] **Step 7: Rewire chat() to use one resolved provider/model selection**

In `inc/LLM/WordPressAIClient.php`, import:

```php
use FlavorAgent\AI\FeatureModelSelection;
use WordPress\AiClient\AiClient;
```

In `chat()`, resolve selection before policy context is built, then use the resolved provider for policy, provider/model selection, reasoning-effort custom options, request timeout metadata, diagnostics, and trace context:

```php
$selection         = self::resolve_provider_model_selection( $provider );
$resolved_provider = $selection['provider'];
$model_options     = WordPressAIPolicy::sanitize_text_generation_options( $model_options ?? [] );
$system_prompt     = WordPressAIPolicy::system_instruction(
	$system_prompt,
	$ability_name,
	[
		'provider'        => $resolved_provider,
		'reasoningEffort' => self::normalize_reasoning_effort_value( $reasoning_effort ),
		'hasSchema'       => is_array( $schema ) && [] !== $schema,
	]
);
```

Replace the current provider-selection call:

```php
$prompt = self::apply_provider_selection( $prompt, $provider );
```

with:

```php
$prompt = self::apply_provider_model_selection( $prompt, $selection );
```

Then update these existing calls exactly:

```php
$prompt = self::apply_reasoning_effort( $prompt, $resolved_provider, $reasoning_effort );

$request_timeout_seconds = self::request_timeout_seconds(
	$resolved_provider,
	$reasoning_effort,
	$schema
);
$request_diagnostics     = self::build_request_diagnostics(
	$system_prompt,
	$user_prompt,
	$resolved_provider,
	$reasoning_effort,
	$schema,
	$request_timeout_seconds,
	$selection
);

$chat_trace_context = $trace_consumed
	? self::build_chat_trace_context(
		$system_prompt,
		$user_prompt,
		$resolved_provider,
		$reasoning_effort,
		$schema,
		$request_timeout_seconds,
		$schema_union_count
	)
	: [];
```

At the start of `chat()`, add a third stale runtime chat-configuration reset before the existing metrics and diagnostics resets. Do not remove the existing metrics or diagnostics reset calls:

```php
Provider::record_runtime_chat_configuration( null );
Provider::record_runtime_chat_metrics( null );
Provider::record_runtime_chat_diagnostics( null );
```

The raw `$provider` argument is only used by `resolve_provider_model_selection()` so explicit per-call providers keep highest precedence.

- [ ] **Step 8: Add provider/model selection helpers**

Add these private helpers:

```php
/**
 * @return array{provider: string, model: string, source: string, modelResolutionStatus: string}
 */
private static function resolve_provider_model_selection( ?string $provider ): array {
	$explicit_provider = is_string( $provider ) ? sanitize_key( $provider ) : '';

	if ( '' !== $explicit_provider ) {
		return [
			'provider'              => $explicit_provider,
			'model'                 => '',
			'source'                => 'explicit',
			'modelResolutionStatus' => 'provider',
		];
	}

	$developer_selection = FeatureModelSelection::get();

	if ( '' !== $developer_selection['provider'] ) {
		return [
			'provider'              => $developer_selection['provider'],
			'model'                 => $developer_selection['model'],
			'source'                => 'ai_plugin_feature_developer',
			'modelResolutionStatus' => '' !== $developer_selection['model'] ? 'requested_model' : 'provider',
		];
	}

	return [
		'provider'              => '',
		'model'                 => '',
		'source'                => 'default',
		'modelResolutionStatus' => 'default',
	];
}

/**
 * @param array{provider: string, model: string, source: string, modelResolutionStatus: string, modelResolutionError?: string} $selection
 * @return object|\WP_Error
 */
private static function apply_provider_model_selection( object $prompt, array &$selection ) {
	$provider = $selection['provider'];
	$model    = $selection['model'];

	if ( '' === $provider ) {
		Provider::record_runtime_chat_configuration( null );

		return $prompt;
	}

	if ( '' !== $model && class_exists( AiClient::class ) && is_callable( [ AiClient::class, 'defaultRegistry' ] ) && is_callable( [ $prompt, 'using_model' ] ) ) {
		try {
			$registry       = AiClient::defaultRegistry();
			$provider_model = is_object( $registry ) && is_callable( [ $registry, 'getProviderModel' ] )
				? $registry->getProviderModel( $provider, $model )
				: null;

			if ( is_object( $provider_model ) ) {
				$updated_prompt = self::call_prompt_method( $prompt, 'using_model', [ $provider_model ] );

				if ( is_object( $updated_prompt ) ) {
					$selection['modelResolutionStatus'] = 'model';
					Provider::record_runtime_chat_configuration(
						[
							'provider' => $provider,
							'model'    => $model,
						]
					);

					return $updated_prompt;
				}
			}
		} catch ( \Throwable $throwable ) {
			$selection['modelResolutionStatus'] = 'model_resolution_failed_provider_fallback';
			$selection['modelResolutionError']  = $throwable->getMessage();
			$model                              = '';
		}
	}

	$updated_prompt = self::apply_provider_selection( $prompt, $provider );

	if ( is_wp_error( $updated_prompt ) ) {
		return $updated_prompt;
	}

	if ( 'requested_model' === (string) ( $selection['modelResolutionStatus'] ?? '' ) ) {
		$selection['modelResolutionStatus'] = 'provider';
	}

	Provider::record_runtime_chat_configuration(
		[
			'provider' => $provider,
			'model'    => '',
		]
	);

	return $updated_prompt;
}
```

- [ ] **Step 9: Include resolved provider/model in request diagnostics**

Extend `build_request_diagnostics()` to accept `$selection`, and use `resolvedProvider` / `resolvedModel` / `modelSelectionSource` / `modelResolutionStatus` in `requestSummary`. Do not add `requestSummary.selectedProvider`; top-level Activity Log metadata already uses `selectedProvider` for the saved provider selection.

```php
/**
 * @param array<string, mixed>|null $schema
 * @param array{provider: string, model: string, source: string, modelResolutionStatus: string, modelResolutionError?: string} $selection
 * @return array<string, mixed>
 */
private static function build_request_diagnostics(
	string $system_prompt,
	string $user_prompt,
	?string $provider,
	?string $reasoning_effort,
	?array $schema,
	int $timeout_seconds,
	array $selection
): array {
	$provider         = is_string( $provider ) ? sanitize_key( $provider ) : '';
	$request_payload  = [
		'provider'     => $provider,
		'instructions' => $system_prompt,
		'input'        => $user_prompt,
	];
	$reasoning_effort = self::normalize_reasoning_effort_value( $reasoning_effort );
	$resolved_model   = trim( (string) ( $selection['model'] ?? '' ) );
	$resolution_error = trim( (string) ( $selection['modelResolutionError'] ?? '' ) );

	if ( null !== $reasoning_effort ) {
		$request_payload['reasoning'] = [ 'effort' => $reasoning_effort ];
	}

	if ( is_array( $schema ) && [] !== $schema ) {
		$request_payload['text'] = [
			'format' => [
				'type'   => 'json_schema',
				'schema' => $schema,
			],
		];
	}

	return [
		'transport'      => [
			'host'           => 'wordpress-ai-client',
			'path'           => '/generate-text',
			'timeoutSeconds' => max( 1, $timeout_seconds ),
		],
		'requestSummary' => array_filter(
			[
				'bodyBytes'            => self::json_byte_length( $request_payload ),
				'instructionsChars'    => strlen( $system_prompt ),
				'inputChars'           => strlen( $user_prompt ),
				'reasoningEffort'      => $reasoning_effort,
				'resolvedProvider'     => $provider,
				'resolvedModel'        => '' !== $provider
					? ( '' !== $resolved_model ? $resolved_model : 'provider-managed' )
					: '',
				'modelSelectionSource'  => sanitize_key( (string) ( $selection['source'] ?? '' ) ),
				'modelResolutionStatus' => sanitize_key( (string) ( $selection['modelResolutionStatus'] ?? '' ) ),
				'modelResolutionError'  => $resolution_error,
			],
			static fn ( mixed $value ): bool => null !== $value && '' !== $value
		),
	];
}
```

Expected Activity Log behavior:

```text
When the AI plugin developer option selects anthropic/claude-sonnet-4-6, Activity Log backend metadata reports provider anthropic and model claude-sonnet-4-6.
When only provider is selected, model reports provider-managed.
When chat() receives an explicit provider, the explicit provider wins and the developer option is ignored for that request.
The request summary includes modelResolutionStatus so stale or failed model resolution is visible instead of silently looking like a successful model pin.
```

- [ ] **Step 10: Run PHP verification**

Run:

```bash
vendor/bin/phpunit --filter WordPressAIClientTest
vendor/bin/phpunit --filter RecommendationAbilityExecutionTest::test_execute_persists_resolved_provider_fields_in_request_diagnostic_activity
```

Expected:

```text
Both PHP commands exit 0.
```

---

### Task 5: Prove Connectors Network-Active Plugin Handling

**Files:**
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `docs/reference/2026-05-09-gutenberg-23-1-ai-0-9-validation.md`

- [ ] **Step 1: Inspect the current Connectors state code path**

Run:

```bash
sed -n '1,260p' inc/Admin/Settings/State.php
rg -n "is_plugin_active_for_network|network|connector|active" inc/Admin inc/REST inc/OpenAI src/admin tests/phpunit
```

Expected:

```text
The inspection confirms Flavor Agent settings state consumes the WordPress AI Client / connector registry status; it should not add direct plugin-activation checks unless a current code path actually calls for them.
```

- [ ] **Step 2: Add a non-destructive Settings state contract**

Add this test to `tests/phpunit/SettingsTest.php`:

```php
public function test_page_state_treats_wordpress_ai_client_runtime_as_ready_for_chat(): void {
	WordPressTestState::$ai_client_supported = true;

	$state = State::get_page_state();
	$meta  = State::get_group_card_meta( Config::GROUP_CHAT, $state );

	$this->assertSame( 'wordpress_ai_client', $state['runtime_chat']['provider'] ?? null );
	$this->assertTrue( $state['runtime_chat']['configured'] ?? false );
	$this->assertSame( 'Ready', $meta['status']['label'] ?? null );
	$this->assertSame( 'success', $meta['status']['tone'] ?? null );
}
```

This verifies Flavor Agent's downstream settings state without resetting the local WordPress database. If inspection reveals a direct `is_plugin_active_for_network()` branch in a future tree, replace this with the nearest mock-backed assertion for that branch.

- [ ] **Step 3: Run the Settings state contract**

Run:

```bash
vendor/bin/phpunit --filter SettingsTest::test_page_state_treats_wordpress_ai_client_runtime_as_ready_for_chat
```

Expected:

```text
The test exits 0 and proves Settings > Flavor Agent treats the WordPress AI Client runtime as ready when the AI Client reports text-generation support.
```

- [ ] **Step 4: Optionally run a disposable multisite smoke check**

Do not run this on the normal local stack. Use it only if the operator confirms the stack is disposable and the database can be destroyed.

> DISPOSABLE STACK ONLY:
>
> ```bash
> npm run wp:reset
> docker compose exec -T wordpress wp core multisite-convert --title='Flavor Agent Network' --allow-root
> docker compose exec -T wordpress wp plugin activate ai --network --allow-root
> docker compose exec -T wordpress wp plugin status ai --allow-root
> ```

Expected:

```text
If run, the AI plugin status reports Network Active, and the Settings state remains ready/provider-managed.
```

- [ ] **Step 5: Record the result**

In `docs/reference/2026-05-09-gutenberg-23-1-ai-0-9-validation.md`, add:

```markdown
## Multisite Connector State

`vendor/bin/phpunit --filter SettingsTest::test_page_state_treats_wordpress_ai_client_runtime_as_ready_for_chat` passed. Flavor Agent settings state treats the WordPress AI Client runtime as ready when the AI Client reports text-generation support. No product code change was required for Gutenberg 23.1 connector active-state handling.
```

If the optional disposable multisite smoke check is run, append the WP-CLI status summary. If either check fails, replace the final sentence with:

```markdown
Flavor Agent settings state did not treat the network-active AI plugin as active. The failing command output is recorded below, and implementation must update `inc/Admin/Settings/State.php` before release.
```

---

### Task 6: File Upstream AI Plugin Guidelines Service Follow-Up

**Files:**
- Modify: `docs/reference/2026-05-09-gutenberg-23-1-ai-0-9-validation.md`
- Modify: `docs/reference/wordpress-ai-roadmap-tracking.md`

- [ ] **Step 1: Confirm the upstream service still lacks a content type filter**

Run from the WordPress plugin checkout or mounted plugin directory:

```bash
rg -n "class Guidelines|wp_guideline|wp_guideline_type|tax_query" "$(docker compose exec -T wordpress wp plugin path ai --allow-root)"
```

Expected:

```text
The AI plugin Guidelines service queries wp_guideline and does not add a wp_guideline_type=content tax_query.
```

- [ ] **Step 2: File the upstream issue**

Create an issue in `WordPress/ai` with this body:

```markdown
## Summary

AI plugin 0.9.0's internal Guidelines service can read the latest `wp_guideline` without filtering by `wp_guideline_type=content`. Gutenberg 23.1 made Guidelines type-aware and default artifact guidelines can exist alongside content guidelines, so an artifact guideline can shadow the content guideline in prompt assembly.

## Observed behavior

The service queries the latest `wp_guideline` row without a `tax_query`. In a site with both `artifact` and `content` guideline terms, the newest artifact guideline can be selected for content prompt grounding.

## Expected behavior

When the `wp_guideline_type` taxonomy exists, the content prompt Guidelines service should query `wp_guideline_type=content`.

## Flavor Agent impact

Flavor Agent's local `FlavorAgent\Guidelines\CoreGuidelinesRepository` already filters `wp_guideline_type=content`, and its PHPUnit coverage locks that contract. This issue is upstream because the AI plugin's own Guidelines service can still shadow content guidelines.

## Reproduction

1. Install Gutenberg 23.1.x and AI plugin 0.9.0.
2. Create one `wp_guideline` assigned `wp_guideline_type=artifact`.
3. Create one `wp_guideline` assigned `wp_guideline_type=content`.
4. Make the artifact guideline newer than the content guideline.
5. Invoke the AI plugin Guidelines formatting service for content prompt assembly.
6. Observe that the newest artifact guideline can be selected.
```

- [ ] **Step 3: Record the upstream link**

In `docs/reference/2026-05-09-gutenberg-23-1-ai-0-9-validation.md`, update **Upstream Follow-Up** with one sentence that starts with `Upstream issue filed:` and ends with the full GitHub issue URL returned after the issue is filed.

In `docs/reference/wordpress-ai-roadmap-tracking.md`, add this row to the Guidelines or content surface section:

```markdown
| AI plugin Guidelines service content type filter | Filed upstream after 0.9.0 verification | Flavor Agent filters `wp_guideline_type=content` locally; upstream AI plugin service needs the same content type guard to avoid artifact-guideline shadowing. |
```

---

### Task 7: Record Lower-Priority No-Action Watch Items

**Files:**
- Modify: `docs/reference/gutenberg-feature-tracking.md`
- Modify: `docs/reference/2026-05-09-gutenberg-23-1-ai-0-9-validation.md`

- [ ] **Step 1: Confirm Flavor Agent does not reference Tabs block names**

Run:

```bash
rg -n "\\bcore/(tabs|tab)\\b" inc src tests docs readme.txt STATUS.md
```

Expected:

```text
No matches.
```

- [ ] **Step 2: Confirm viewport visibility reads the persisted shape**

Run:

```bash
sed -n '80,130p' inc/Context/ViewportVisibilityAnalyzer.php
```

Expected:

```text
The analyzer reads metadata.blockVisibility.viewport as persisted key => bool data and does not depend on Gutenberg Inspector UI prop names.
```

- [ ] **Step 3: Confirm Flavor Agent does not emit per-block custom CSS recommendations**

Run:

```bash
rg -n "custom CSS|customCss|cssText|style\\.css|has-custom-css|edit_css" inc src tests
```

Expected:

```text
No active recommendation writer emits per-block custom CSS requiring the new edit_css save restriction.
```

- [ ] **Step 4: Record the watch item table**

Add this table to `docs/reference/gutenberg-feature-tracking.md` under **Action Implications For Flavor Agent**:

```markdown
| Gutenberg 23.1 item | Flavor Agent decision | Release gate |
| --- | --- | --- |
| Tabs block WCAG rename | No action; Flavor Agent does not reference `core/tabs` or `core/tab` block names. | Run `rg -n "\\bcore/(tabs|tab)\\b" inc src tests docs readme.txt STATUS.md` during release validation. |
| `react-dom/client` externalized | No action until the repo bumps `@wordpress/scripts` for the WP 7.x toolchain. | Run `npm run build` after dependency bumps. |
| Design Tools viewport visibility key/value swap | No action; Flavor Agent reads persisted `metadata.blockVisibility.viewport.{key}: bool`, not the Inspector control prop shape. | Keep `ViewportVisibilityAnalyzer` tests green. |
| Strip per-block custom CSS on save without `edit_css` | No action; Flavor Agent does not generate per-block custom CSS recommendations today. | Revisit before adding scoped custom CSS operations. |
```

- [ ] **Step 5: Record AI plugin no-action watch items**

Add this paragraph to `docs/reference/wordpress-ai-roadmap-tracking.md`:

```markdown
AI plugin 0.9.0 also shipped adjacent experiments and surfaces including Comment Moderation, Content Resizing, WP-CLI alt-text plumbing, and settings UI work. The only required Flavor Agent code integration from this release is honoring the per-feature developer provider/model setting; the other shipped surfaces remain watch items because Flavor Agent does not call those experiments directly.
```

---

### Task 8: Run The Complete Verification Ladder

**Files:**
- Modify: `docs/reference/2026-05-09-gutenberg-23-1-ai-0-9-validation.md`

- [ ] **Step 1: Run targeted JS checks**

Run:

```bash
npx wp-scripts lint-js assets/abilities-bridge.js src/store/abilities-client.js src/store/__tests__/abilities-client.test.js src/store/__tests__/abilities-bridge.test.js
npm run test:unit -- src/store/__tests__/abilities-client.test.js src/store/__tests__/abilities-bridge.test.js --runInBand
```

Expected:

```text
Both commands exit 0.
```

- [ ] **Step 2: Run targeted PHP checks**

Run:

```bash
vendor/bin/phpunit --filter GuidelinesTest
vendor/bin/phpunit --filter WordPressAIClientTest
vendor/bin/phpunit --filter RecommendationAbilityExecutionTest::test_execute_persists_resolved_provider_fields_in_request_diagnostic_activity
vendor/bin/phpunit --filter SettingsTest::test_page_state_treats_wordpress_ai_client_runtime_as_ready_for_chat
```

Expected:

```text
All four commands exit 0.
```

- [ ] **Step 3: Run docs check**

Run:

```bash
npm run check:docs
```

Expected:

```text
The command exits 0.
```

- [ ] **Step 4: Run the fast aggregate verifier**

Run:

```bash
node scripts/verify.js --skip-e2e
```

Expected:

```text
The verifier prints a final VERIFY_RESULT JSON line with a success status.
If unrelated repo-wide lint debt fails this command, record the failing step and keep the targeted checks above as release evidence for this plan.
```

- [ ] **Step 5: Run or explicitly waive browser evidence**

Provider/model routing is a shared subsystem under `docs/reference/cross-surface-validation-gates.md`, so do not silently skip browser evidence.

Run at least the Playground harness:

```bash
npm run test:e2e:playground
```

Expected:

```text
The command exits 0.
```

If the harness is unavailable or known-red in the current local environment, add this waiver row to the validation record instead of marking it Pass:

```markdown
| `npm run test:e2e:playground` | Waived: <specific blocker, environment error, or linked known-red issue> |
```

Run `npm run test:e2e:wp70` too if Task 4 changes are exercised through Site Editor surfaces in this branch. If it is not run, record why in the validation document.

- [ ] **Step 6: Update the validation record**

In `docs/reference/2026-05-09-gutenberg-23-1-ai-0-9-validation.md`, append the final verification table. Use `Pass` only for commands that exit 0. If unrelated repo-wide verifier debt blocks the aggregate verifier, replace that row with the real failing verifier step name from `output/verify/summary.json`.

```markdown
## Final Verification

| Command | Result |
| --- | --- |
| `npx wp-scripts lint-js assets/abilities-bridge.js src/store/abilities-client.js src/store/__tests__/abilities-client.test.js src/store/__tests__/abilities-bridge.test.js` | Pass |
| `npm run test:unit -- src/store/__tests__/abilities-client.test.js src/store/__tests__/abilities-bridge.test.js --runInBand` | Pass |
| `vendor/bin/phpunit --filter GuidelinesTest` | Pass |
| `vendor/bin/phpunit --filter WordPressAIClientTest` | Pass |
| `vendor/bin/phpunit --filter RecommendationAbilityExecutionTest::test_execute_persists_resolved_provider_fields_in_request_diagnostic_activity` | Pass |
| `vendor/bin/phpunit --filter SettingsTest::test_page_state_treats_wordpress_ai_client_runtime_as_ready_for_chat` | Pass |
| `npm run test:e2e:playground` | Pass |
| `npm run check:docs` | Pass |
| `node scripts/verify.js --skip-e2e` | Pass |
```

---

## Coverage Matrix

| Finding | Plan coverage |
| --- | --- |
| Gutenberg 23.1 Core Abilities readiness promise can race cold loads | Task 2 treats the shipped bridge/client work as complete and verifies the existing readiness and REST fallback coverage. |
| Guidelines CPT/routes/classes renamed and made type-aware | Task 3 treats the shipped `wp_guideline` and `wp_guideline_type=content` contract as complete, then runs PHPUnit and live WP-CLI smoke checks. |
| AI plugin 0.9 per-feature developer provider/model setting | Task 4 reads `wpai_feature_flavor-agent_field_developer`, applies provider/model through the AI Client, and records accurate Activity Log metadata. |
| AI plugin Guidelines service can shadow content guidelines with artifact guidelines | Task 6 files the upstream issue and records the local safety guard. |
| Network-active connector plugins now counted as active | Task 5 verifies the downstream Settings state with PHPUnit first, leaving the destructive multisite smoke check as an explicitly fenced disposable-stack-only option. |
| Tabs block WCAG rename | Task 7 records no action because Flavor Agent has no string references. |
| `react-dom/client` externalized | Task 7 records dependency-bump watch only. |
| Design Tools viewport visibility key/value swap | Task 7 records no action because Flavor Agent reads persisted metadata. |
| Per-block custom CSS stripped for users without `edit_css` | Task 7 records no action because Flavor Agent does not emit scoped custom CSS operations. |
| AI plugin 0.9 adjacent experiments and settings UI changes | Task 7 records watch-only status; Task 4 covers the one required integration. |
