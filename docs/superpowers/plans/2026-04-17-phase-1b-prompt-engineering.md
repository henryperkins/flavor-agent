# Phase 1B — Prompt Engineering Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Raise recommendation quality across every LLM-backed surface by (1) wrapping all prompt classes in the existing `PromptBudget`, (2) constraining LLM output with JSON-schema `response_format`, and (3) adding compact few-shot exemplars per surface.

**Architecture:** Navigation already uses `PromptBudget` and is the reference implementation — every other `*Prompt` class copies that pattern. A new `ResponseSchema` registry is the **single source of truth** for JSON schemas, keyed by surface name; prompt classes do NOT own schemas (avoids drift from `build_system()`). `ResponsesClient::rank()` gains an optional schema parameter and emits a Responses-API `text.format` json_schema block; `WordPressAIClient` calls the WP 7.0 builder method `as_json_response( ?array $schema )` (docs/wordpress-7.0-gutenberg-22.8-reference.md:227) when a schema is provided, falling back to in-prompt instructions only when `as_json_response` is not callable on the builder. Few-shot exemplars live next to each prompt class as a `get_few_shot_examples()` helper added as low-priority `PromptBudget` sections so they're trimmed first under token pressure. Tests drive budget truncation deterministically via a new `flavor_agent_prompt_budget_max_tokens` filter — no mega-fixtures required.

**Tech Stack:** PHP 8.0+, `@wordpress/scripts` / Jest, PHPUnit, Azure OpenAI Responses API + OpenAI Native Responses API + WordPress 7.0 AI Client.

---

## Context & Current State

- **What already works.** `inc/LLM/PromptBudget.php` is implemented (200 lines, 133 lines of tests in `tests/phpunit/PromptBudgetTest.php`). `inc/LLM/NavigationPrompt.php` is the reference — `build_user()` assembles budget sections by priority (100 = identity, 95 = user instruction, 90 = menu structure, 70 = current attrs, …, 20 = docs guidance) and returns `$budget->assemble()`.
- **What's missing (verified by `grep` on `inc/`):** `json_schema`, `response_format`, `structured`, `few_shot` — all zero matches. Five prompt classes do not yet use `PromptBudget`: `Prompt.php` (block), `TemplatePrompt.php`, `TemplatePartPrompt.php`, `StylePrompt.php`, `WritingPrompt.php`.
- **Caller topology.** `inc/LLM/ChatClient.php:20` routes block + content recommendations through `ResponsesClient::rank()`, falling back to `WordPressAIClient::chat()` when the provider is a WP 7.0 connector. Template/template-part/navigation/style/pattern abilities call `ResponsesClient::rank()` directly.
- **Responses-API shape used today.** `ResponsesClient::rank()` posts `{ model, instructions, input, reasoning }`. There is no `text.format` / `response_format` key yet. Adding one is purely additive.
- **Existing tests per prompt:** `PromptFormattingTest`, `PromptRulesTest`, `PromptGuidanceTest` (block), `TemplatePromptTest`, `TemplatePartPromptTest`, `StylePromptTest`, `WritingPromptTest`, `NavigationAbilitiesTest`. Add new tests alongside these.
- **Scope exclusions.** `PatternAbilities::rank()` (inline prompt for vector-ranking) is deferred to Phase 1C and out of scope here. E2E/Playwright changes are out of scope — covered by Phase 4A.

---

## File Structure

### New files

| Path | Responsibility |
|---|---|
| `inc/LLM/ResponseSchema.php` | Static registry mapping surface name → JSON schema used by `response_format`. Pure data + one `get()` helper. |
| `tests/phpunit/ResponseSchemaTest.php` | Asserts every surface's schema is syntactically valid JSON Schema and matches the surface's `parse_response` contract. |
| `tests/phpunit/PromptFewShotTest.php` | Asserts each prompt's exemplars (a) fit a compact budget (<800 tokens each) and (b) demonstrate a valid output per `ResponseSchema`. |

### Modified files

| Path | Change |
|---|---|
| `inc/LLM/Prompt.php` (block) | `build_user()` reassembled via `PromptBudget` sections; new `get_few_shot_examples()` returning compact exemplars. Schema lives in `ResponseSchema`, not here. |
| `inc/LLM/TemplatePrompt.php` | Same as above for template surface. |
| `inc/LLM/TemplatePartPrompt.php` | Same for template-part surface. |
| `inc/LLM/StylePrompt.php` | Same for Global Styles + Style Book surfaces. |
| `inc/LLM/WritingPrompt.php` | Minimal budget wrap + one exemplar (scaffold surface). |
| `inc/LLM/NavigationPrompt.php` | Already uses `PromptBudget`. Add `get_few_shot_examples()` only; thread the new filter through the existing `new PromptBudget()` call. |
| `inc/AzureOpenAI/ResponsesClient.php` | `rank()` accepts optional `?array $schema` + `?string $schema_name`; if schema is non-null, adds `text.format = { type: 'json_schema', name, schema, strict: true }` to the request body. Existing callers unaffected. |
| `inc/LLM/ChatClient.php` | Thread optional schema through to `ResponsesClient::rank()` and `WordPressAIClient::chat()`. |
| `inc/LLM/WordPressAIClient.php` | `chat()` accepts optional `?array $schema`; when non-null and the prompt builder's `as_json_response` method is callable, call `$prompt->as_json_response( $schema )`. |
| `inc/Abilities/BlockAbilities.php` | Pass `ResponseSchema::get( 'block' )` + name `flavor_agent_block` to `ChatClient::chat()`. |
| `inc/Abilities/TemplateAbilities.php` | Both callsites (template + template-part) pass `ResponseSchema::get( 'template' )` / `ResponseSchema::get( 'template_part' )` to `ResponsesClient::rank()`. Executor: `grep -rn "TemplatePartPrompt::" inc/Abilities` to find the template-part callsite. |
| `inc/Abilities/StyleAbilities.php` | Pass `ResponseSchema::get( 'style' )`. |
| `inc/Abilities/NavigationAbilities.php` | Pass `ResponseSchema::get( 'navigation' )`. |
| `inc/Abilities/ContentAbilities.php` | Pass `ResponseSchema::get( 'content' )`. |
| `tests/phpunit/bootstrap.php:225` | Extend the `WP_AI_Client_Prompt_Builder::__call()` switch (the `__call` entry around line 225) to handle `as_json_response`, storing the received schema into `WordPressTestState::$last_ai_client_prompt['json_schema']` and returning `$this`. Without this the `BadMethodCallException` at line 260 will fire during schema-path tests. |
| `docs/reference/shared-internals.md` | Add `ResponseSchema` + `PromptBudget` coverage matrix under existing LLM section. |
| `docs/FEATURE_SURFACE_MATRIX.md` | Update "Structured output" column (add one if missing) to reflect which surfaces enforce a schema. |

---

## Task Decomposition

Three parts, sequenced for dependency safety:
- **Part A** — Propagate `PromptBudget` (independent of B/C).
- **Part B** — Structured JSON output via `ResponseSchema` registry + `ResponsesClient` extension (depends on no prior part).
- **Part C** — Few-shot exemplars per surface (depends on Part A — exemplars are added as low-priority budget sections).
- **Part D** — Integration verification and docs.

Parts A and B are parallelizable; Part C must follow A.

---

### Task A1: Wrap block `Prompt.php` in `PromptBudget`

**Files:**
- Modify: `inc/LLM/Prompt.php:138-438` (the `build_user()` body; current section-assembly lines)
- Test: `tests/phpunit/PromptFormattingTest.php` (extend with a budget-truncation assertion) or `tests/phpunit/PromptRulesTest.php` — pick whichever currently asserts `build_user()` output composition.

- [ ] **Step 1: Identify current sections.** Read `inc/LLM/Prompt.php` (it exceeds one read chunk — use `offset`/`limit`). Enumerate every `$user .= "...";` or equivalent append. Write down `(section name, priority, mandatory?)` for each. The canonical priority ladder from `NavigationPrompt.php` is:

  | Priority | Section type |
  |---:|---|
  | 100 | Block identity / block name / attributes snapshot |
  | 95  | User instruction |
  | 90  | Block content / primary structural data |
  | 70  | Current attributes summary |
  | 60  | Location / ancestry / structural branch |
  | 55  | Live editor context |
  | 50  | Sibling summaries |
  | 45  | Parent layout constraints |
  | 40  | Visual hints / design semantics |
  | 30  | Theme design tokens |
  | 20  | Docs guidance chunks |

  Map each current block-prompt section onto this ladder.

- [ ] **Step 2: Write the failing test.**

  The `PromptBudget::DEFAULT_MAX_TOKENS` is 12000 (inc/LLM/PromptBudget.php:28), so a realistic fixture cannot blow past it without being absurdly large. Instead, the test constrains the budget via the `flavor_agent_prompt_budget_max_tokens` filter installed by `build_user()` in Step 4. Put the assertion in `tests/phpunit/PromptRulesTest.php` (already covers `build_user` contract):

  ```php
  public function test_block_prompt_trims_lowest_priority_docs_guidance_under_constrained_budget(): void {
      add_filter(
          'flavor_agent_prompt_budget_max_tokens',
          static fn( int $tokens, string $surface ): int => 'block' === $surface ? 2500 : $tokens,
          10,
          2
      );

      $context  = $this->make_realistic_block_context(); // existing helper or small new one
      $guidance = [
          [ 'title' => 'Heading hierarchy', 'snippet' => str_repeat( 'Keep H1 unique. ', 80 ) ],
          [ 'title' => 'Color tokens',      'snippet' => str_repeat( 'Prefer preset slugs. ', 80 ) ],
          [ 'title' => 'Layout constraints','snippet' => str_repeat( 'Respect container.  ', 80 ) ],
      ];

      $user = Prompt::build_user( $context, 'tighten the heading hierarchy', $guidance );

      // Identity and user instruction are the highest-priority sections — always kept.
      $this->assertStringContainsString( 'Name: ', $user );
      $this->assertStringContainsString( '## User Instruction', $user );
      // Docs guidance is priority 20 — first trimmed under constraint.
      $this->assertStringNotContainsString( '## WordPress Developer Guidance', $user );

      remove_all_filters( 'flavor_agent_prompt_budget_max_tokens' );
  }
  ```

  The three guidance chunks of ~1300 chars each (~325 tokens) plus identity + attributes + instructions cleanly exceed 2500 tokens. When the budget is 12000 (the non-filtered default), the same assertion should not apply — so do NOT skip the `add_filter` call.

- [ ] **Step 3: Run test to verify it fails.**

  ```bash
  vendor/bin/phpunit --filter test_block_prompt_trims_lowest_priority_docs_guidance_under_constrained_budget
  ```
  Expected: FAIL — current implementation concatenates regardless of budget and does not yet honour the filter.

- [ ] **Step 4: Refactor `build_user()` to use `PromptBudget` with the filter hook.**

  Replace the current string accumulation with:

  ```php
  public static function build_user(
      array $context,
      string $prompt = '',
      array $docs_guidance = [],
      array $execution_contract = []
  ): string {
      $max_tokens = (int) apply_filters( 'flavor_agent_prompt_budget_max_tokens', 0, 'block' );
      $budget     = new PromptBudget( $max_tokens );

      // Priority 100 — identity.
      $budget->add_section( 'identity', self::format_identity( $context ), 100 );

      // Priority 95 — user instruction.
      if ( '' !== trim( $prompt ) ) {
          $budget->add_section( 'user_prompt', "## User Instruction\n{$prompt}", 95 );
      }

      // Priority 90 — current attributes / bindable / content-role.
      $attr_section = self::format_attributes( $context );
      if ( '' !== $attr_section ) {
          $budget->add_section( 'attributes', $attr_section, 90 );
      }

      // ...one add_section() call per section, priority per ladder in Step 1...

      // Priority 20 — docs guidance (lowest; trimmed first).
      if ( [] !== $docs_guidance ) {
          $budget->add_section( 'docs_guidance', self::format_docs_guidance( $docs_guidance ), 20 );
      }

      // Priority 10 — few-shot exemplars (populated in Task C1; no-op today).
      foreach ( self::get_few_shot_examples() as $index => $example ) {
          $budget->add_section( "few_shot_{$index}", $example, 10 );
      }

      return $budget->assemble();
  }

  public static function get_few_shot_examples(): array {
      // Populated in Task C1.
      return [];
  }
  ```

  Extract the string-building code that existed inline into small `private static function format_*()` helpers — one per section — so each `add_section()` call is one line. `format_identity()`, `format_attributes()`, `format_docs_guidance()` etc. The `execution_contract` argument is preserved for callers that already pass it (see `inc/Abilities/BlockAbilities.php`).

- [ ] **Step 5: Run the test and the full prompt-formatting suite.**

  ```bash
  vendor/bin/phpunit --filter Prompt
  ```
  Expected: all existing assertions still green; new assertion green. If `PromptFormattingTest` asserted exact section ordering/spacing, verify the budget's `"\n\n"` join matches. If it doesn't, update the test's expected spacing rather than contorting the implementation.

- [ ] **Step 6: Commit.**

  ```bash
  git add inc/LLM/Prompt.php tests/phpunit/PromptFormattingTest.php tests/phpunit/PromptRulesTest.php
  git commit -m "refactor(prompt): wrap block prompt assembly in PromptBudget"
  ```

---

### Task A2: Wrap `TemplatePrompt.php` in `PromptBudget`

Follow the exact pattern from Task A1 applied to `inc/LLM/TemplatePrompt.php:110-280` (the `build_user()` body). Sections for template prompts include template type, current blocks tree, target inventory, pattern inventory, empty areas, unused template parts, viewport visibility, theme tokens, docs guidance. Call the filter as `apply_filters( 'flavor_agent_prompt_budget_max_tokens', 0, 'template' )`.

**Files:** Modify `inc/LLM/TemplatePrompt.php`; extend `tests/phpunit/TemplatePromptTest.php`.

- [ ] Write failing test using the `flavor_agent_prompt_budget_max_tokens` filter with the `'template'` surface key (same shape as A1).
- [ ] Run → FAIL.
- [ ] Refactor `build_user()` to `$budget->add_section()` calls, priorities per ladder, filter threaded as shown in A1.
- [ ] Run `vendor/bin/phpunit --filter TemplatePrompt` → green.
- [ ] Commit: `refactor(prompt): wrap template prompt assembly in PromptBudget`.

---

### Task A3: Wrap `TemplatePartPrompt.php` in `PromptBudget`

Same as A2 applied to `inc/LLM/TemplatePartPrompt.php`, surface key `'template_part'`. Extend `tests/phpunit/TemplatePartPromptTest.php`. Commit: `refactor(prompt): wrap template-part prompt assembly in PromptBudget`.

---

### Task A4: Wrap `StylePrompt.php` in `PromptBudget`

Same pattern for `inc/LLM/StylePrompt.php`, surface key `'style'`. Style prompts have two modes (Global Styles and Style Book) — ensure both code paths route through the budget. Extend `tests/phpunit/StylePromptTest.php`. Commit: `refactor(prompt): wrap style prompt assembly in PromptBudget`.

---

### Task A5: Wrap `WritingPrompt.php` in `PromptBudget`

`WritingPrompt.php` is the scaffold surface — its `build_user()` is small. Wrap it anyway for consistency, surface key `'content'`. Extend `tests/phpunit/WritingPromptTest.php`. Commit: `refactor(prompt): wrap writing prompt assembly in PromptBudget`.

---

### Task A6: Retrofit `NavigationPrompt.php` to honor the budget filter

NavigationPrompt already uses `PromptBudget` but instantiates it with the hard-coded default. Thread the filter so navigation behaves consistently with the other surfaces and can be driven in tests.

**Files:** Modify `inc/LLM/NavigationPrompt.php:77`; extend `tests/phpunit/NavigationAbilitiesTest.php` (or add a new `tests/phpunit/NavigationPromptTest.php` if the existing file does not cover `build_user()`).

- [ ] Write failing test using the filter with `'navigation'` key — assert docs guidance is trimmed under pressure, identity kept.
- [ ] Run → FAIL.
- [ ] Change `$budget = new PromptBudget();` at `inc/LLM/NavigationPrompt.php:77` to:
  ```php
  $max_tokens = (int) apply_filters( 'flavor_agent_prompt_budget_max_tokens', 0, 'navigation' );
  $budget     = new PromptBudget( $max_tokens );
  ```
- [ ] Run → green.
- [ ] Commit: `refactor(prompt): thread budget filter through navigation prompt`.

---

### Task B1: Create `ResponseSchema` registry

**Files:**
- Create: `inc/LLM/ResponseSchema.php`
- Create: `tests/phpunit/ResponseSchemaTest.php`

- [ ] **Step 1: Write the failing test.**

  ```php
  namespace FlavorAgent\Tests\LLM;

  use FlavorAgent\LLM\ResponseSchema;
  use PHPUnit\Framework\TestCase;

  final class ResponseSchemaTest extends TestCase {
      public function test_returns_schema_for_each_supported_surface(): void {
          foreach ( [ 'block', 'template', 'template_part', 'style', 'navigation', 'content' ] as $surface ) {
              $schema = ResponseSchema::get( $surface );
              $this->assertIsArray( $schema );
              $this->assertSame( 'object', $schema['type'] ?? null, "surface {$surface} schema must be an object" );
              $this->assertArrayHasKey( 'properties', $schema );
              $this->assertArrayHasKey( 'required', $schema );
              $this->assertTrue( ( $schema['additionalProperties'] ?? true ) === false, 'strict mode requires additionalProperties=false' );
          }
      }

      public function test_returns_null_for_unknown_surface(): void {
          $this->assertNull( ResponseSchema::get( 'nonexistent' ) );
      }
  }
  ```

- [ ] **Step 2: Run → FAIL (class not defined).**

  ```bash
  vendor/bin/phpunit --filter ResponseSchemaTest
  ```

- [ ] **Step 3: Implement.** Derive each schema from the surface's existing `build_system()` contract + `parse_response()` validation. The block schema is the most elaborate — its top-level shape is `{ settings[], styles[], block[], explanation }`, NOT a generic `{ suggestions[] }` wrapper. See `inc/LLM/Prompt.php:53-94` for the item shape and `inc/LLM/Prompt.php:695-700` for parser validation.

  ```php
  <?php
  declare(strict_types=1);

  namespace FlavorAgent\LLM;

  final class ResponseSchema {
      public static function get( string $surface ): ?array {
          return match ( $surface ) {
              'block'         => self::block_schema(),
              'template'      => self::template_schema(),
              'template_part' => self::template_part_schema(),
              'style'         => self::style_schema(),
              'navigation'    => self::navigation_schema(),
              'content'       => self::content_schema(),
              default         => null,
          };
      }

      private static function block_schema(): array {
          $panel_enum = [
              'general', 'layout', 'position', 'advanced', 'bindings', 'list',
              'color', 'filter', 'typography', 'dimensions', 'border', 'shadow', 'background',
          ];
          $settings_styles_item = [
              'type'                 => 'object',
              'additionalProperties' => false,
              'required'             => [ 'label', 'description', 'panel', 'attributeUpdates' ],
              'properties'           => [
                  'label'            => [ 'type' => 'string' ],
                  'description'      => [ 'type' => 'string' ],
                  'panel'            => [ 'type' => 'string', 'enum' => $panel_enum ],
                  'type'             => [ 'type' => 'string', 'enum' => [ 'attribute_change', 'style_variation' ] ],
                  'attributeUpdates' => [ 'type' => 'object' ], // free-form by design; parse_response validates keys
                  'currentValue'     => [],
                  'suggestedValue'   => [],
                  'isCurrentStyle'   => [ 'type' => 'boolean' ],
                  'isRecommended'    => [ 'type' => 'boolean' ],
                  'confidence'       => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ],
                  'preview'          => [ 'type' => 'string' ],
                  'presetSlug'       => [ 'type' => 'string' ],
                  'cssVar'           => [ 'type' => 'string' ],
              ],
          ];
          $block_item = [
              'type'                 => 'object',
              'additionalProperties' => false,
              'required'             => [ 'label', 'description' ],
              'properties'           => [
                  'label'            => [ 'type' => 'string' ],
                  'description'      => [ 'type' => 'string' ],
                  'type'             => [
                      'type' => 'string',
                      'enum' => [ 'attribute_change', 'style_variation', 'structural_recommendation', 'pattern_replacement' ],
                  ],
                  'attributeUpdates' => [ 'type' => 'object' ],
                  'panel'            => [ 'type' => 'string', 'enum' => $panel_enum ],
                  'currentValue'     => [],
                  'suggestedValue'   => [],
                  'isCurrentStyle'   => [ 'type' => 'boolean' ],
                  'isRecommended'    => [ 'type' => 'boolean' ],
                  'confidence'       => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ],
                  'preview'          => [ 'type' => 'string' ],
                  'presetSlug'       => [ 'type' => 'string' ],
                  'cssVar'           => [ 'type' => 'string' ],
              ],
          ];

          return [
              'type'                 => 'object',
              'additionalProperties' => false,
              'required'             => [ 'settings', 'styles', 'block', 'explanation' ],
              'properties'           => [
                  'settings'    => [ 'type' => 'array', 'items' => $settings_styles_item ],
                  'styles'      => [ 'type' => 'array', 'items' => $settings_styles_item ],
                  'block'       => [ 'type' => 'array', 'items' => $block_item ],
                  'explanation' => [ 'type' => 'string' ],
              ],
          ];
      }

      // ... one per surface. Each derived from the surface's `build_system()` "Return ONLY a JSON object with this exact shape" block:
      //   - template / template_part → top-level `suggestions` or `operations` per the surface; see inc/LLM/TemplatePrompt.php::build_system()
      //   - navigation → { suggestions[] { label, description, category(enum), changes[] { type(enum), targetPath, target, detail } }, explanation }
      //   - style      → operations[] { path, value, description } (derived from StylePrompt::build_system())
      //   - content    → suggestions[] (scaffold; see WritingPrompt::build_system())
  }
  ```

  Executor notes:
  1. For each surface, open the corresponding `*Prompt.php`, read `build_system()` end-to-end, and translate the JSON-shape documentation block verbatim.
  2. OpenAI/Azure strict mode: `strict: true` requires `additionalProperties: false` at every object level AND every listed property in `required`. `attributeUpdates`, `currentValue`, `suggestedValue`, and `value` are intentionally free-form (no `additionalProperties` locked to `false` on sub-objects). If the provider rejects the schema with a strict-mode error, lower to `strict: false` in `ResponsesClient` — document this decision in the task's PR description.
  3. If `ResponseSchemaTest::test_returns_schema_for_each_supported_surface()` (Step 1) fails because one surface cannot satisfy `additionalProperties: false` at the top level, do NOT weaken the test — adjust the schema so top-level is locked and only inner free-form slots stay unconstrained.

- [ ] **Step 4: Run test → PASS.**
- [ ] **Step 5: Commit.**

  ```bash
  git add inc/LLM/ResponseSchema.php tests/phpunit/ResponseSchemaTest.php
  git commit -m "feat(llm): add ResponseSchema registry for structured output"
  ```

---

### Task B2: Extend `ResponsesClient::rank()` with optional schema

**Files:**
- Modify: `inc/AzureOpenAI/ResponsesClient.php:73` (method signature) and `:101-108` (request body assembly)
- Modify/Create: `tests/phpunit/ResponsesClientSchemaTest.php` (if no existing file covers `rank()` body serialization, create this)

- [ ] **Step 1: Write the failing test.** Use a body-serialization assertion: spy/filter `wp_remote_post` (or use WP's existing HTTP mocking pattern as in `AzureBackendValidationTest.php`) and assert the outgoing JSON body contains `text.format.type = "json_schema"` when a schema is passed.

  ```php
  public function test_rank_emits_json_schema_response_format_when_schema_provided(): void {
      $captured_body = null;
      add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured_body ) {
          $captured_body = $args['body'];
          return [ 'response' => [ 'code' => 200 ], 'body' => '{"output_text":"{\\"suggestions\\":[]}"}' ];
      }, 10, 3 );

      ResponsesClient::rank( 'system', 'input', null, ResponseSchema::get( 'block' ) );

      $decoded = json_decode( $captured_body, true );
      $this->assertSame( 'json_schema', $decoded['text']['format']['type'] ?? null );
      $this->assertSame( 'flavor_agent_block', $decoded['text']['format']['name'] ?? null );
      $this->assertTrue( $decoded['text']['format']['strict'] ?? false );
      $this->assertIsArray( $decoded['text']['format']['schema'] ?? null );
  }
  ```

  Also add a regression assertion: when no schema is passed (existing callers), no `text.format` key appears.

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement.** Change `rank()` signature to:

  ```php
  public static function rank(
      string $instructions,
      string $input,
      ?string $reasoning_effort = null,
      ?array $schema = null,
      ?string $schema_name = null
  ): string|\WP_Error {
  ```

  In the body assembly (`:101-108`), conditionally add the `text.format` block:

  ```php
  $payload = [
      'model'        => $config['model'],
      'instructions' => $instructions,
      'input'        => $input,
      'reasoning'    => self::reasoning_options( $resolved_reasoning_effort, $provider ),
  ];

  if ( is_array( $schema ) && [] !== $schema ) {
      $payload['text'] = [
          'format' => [
              'type'   => 'json_schema',
              'name'   => sanitize_key( $schema_name ?? 'flavor_agent_response' ),
              'schema' => $schema,
              'strict' => true,
          ],
      ];
  }

  $body = wp_json_encode( $payload );
  ```

  Also update `WordPressAIClient::chat()` branch (`:81-88`) to pass the schema through — see Task B3.

- [ ] **Step 4: Run → PASS. Also run `ChatClientTest` and `AzureBackendValidationTest` to confirm existing callers still work.**

- [ ] **Step 5: Commit.**

  ```bash
  git add inc/AzureOpenAI/ResponsesClient.php tests/phpunit/ResponsesClientSchemaTest.php
  git commit -m "feat(llm): thread json_schema response_format through ResponsesClient"
  ```

---

### Task B3: Thread schema through `ChatClient` and `WordPressAIClient`

**Files:**
- Modify: `inc/LLM/ChatClient.php` (`chat()` signature + call to `ResponsesClient::rank()` + call to `WordPressAIClient::chat()`)
- Modify: `inc/LLM/WordPressAIClient.php` (`chat()` signature)
- Modify/extend: `tests/phpunit/ChatClientTest.php`, `tests/phpunit/WordPressAIClientTest.php`

- [ ] **Step 1: Write the failing test** — `ChatClientTest::test_passes_schema_to_responses_client_when_provided()` asserting the rank call receives the schema arg. Use whatever mock pattern that file already uses.

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Update `ChatClient::chat()` signature:**

  ```php
  public static function chat(
      string $system_prompt,
      string $user_prompt,
      ?array $schema = null,
      ?string $schema_name = null
  ): string|\WP_Error {
      return 'wordpress_ai_client' === Provider::chat_configuration()['provider']
          ? WordPressAIClient::chat( $system_prompt, $user_prompt, null, null, $schema )
          : ResponsesClient::rank( $system_prompt, $user_prompt, null, $schema, $schema_name );
  }
  ```

- [ ] **Step 4: Update `WordPressAIClient::chat()` to accept `?array $schema`.** The WP 7.0 builder method for JSON output is `as_json_response( ?array $schema )` — see `docs/wordpress-7.0-gutenberg-22.8-reference.md:227` ("Common Builder Methods" table). After `apply_reasoning_effort()`, apply the schema via a new helper:

  ```php
  private static function apply_output_schema( object $prompt, ?array $schema ): object|\WP_Error {
      if ( null === $schema || [] === $schema ) {
          return $prompt;
      }

      if ( ! is_callable( [ $prompt, 'as_json_response' ] ) ) {
          // Older core or builder variant: fall back to in-prompt JSON rules; system prompt already enforces shape.
          return $prompt;
      }

      $updated = self::call_prompt_method( $prompt, 'as_json_response', [ $schema ] );

      if ( is_wp_error( $updated ) ) {
          return $updated;
      }

      if ( ! is_object( $updated ) ) {
          return new \WP_Error(
              'wp_ai_client_invalid_prompt',
              'WordPress AI Client did not return a prompt builder from as_json_response.',
              [ 'status' => 500 ]
          );
      }

      return $updated;
  }
  ```

  Wire it into `chat()` right after `apply_reasoning_effort()`:

  ```php
  $prompt = self::apply_output_schema( $prompt, $schema );
  if ( is_wp_error( $prompt ) ) {
      return $prompt;
  }
  ```

- [ ] **Step 4b: Extend the PHPUnit WP AI client stub** at `tests/phpunit/bootstrap.php:225` so the stub's `WP_AI_Client_Prompt_Builder::__call()` switch handles `as_json_response`:

  ```php
  case 'as_json_response':
      WordPressTestState::$last_ai_client_prompt['json_schema'] = is_array( $arguments[0] ?? null )
          ? $arguments[0]
          : null;

      return $this;
  ```

  Without this addition the existing `BadMethodCallException` at `tests/phpunit/bootstrap.php:260` will fire as soon as a schema-path test runs. Also add a reset of `json_schema` in `WordPressTestState::reset()` (wherever the other `$last_ai_client_prompt` keys are cleared between tests) so schema state does not leak across test methods.

- [ ] **Step 5: Run all ChatClient/WordPressAIClient tests → green.**

- [ ] **Step 6: Commit.**

  ```bash
  git add inc/LLM/ChatClient.php inc/LLM/WordPressAIClient.php tests/phpunit/ChatClientTest.php tests/phpunit/WordPressAIClientTest.php
  git commit -m "feat(llm): thread response schema through ChatClient and WordPress AI Client"
  ```

---

### Task B4: Wire schemas into ability handlers

**Files:** One modification per surface; one test assertion per surface.

- [ ] **Step 1: For each of these callsites, pass the schema:**

  | Callsite | New call shape |
  |---|---|
  | `inc/Abilities/BlockAbilities.php:74` | `ChatClient::chat( $system, $user, ResponseSchema::get( 'block' ), 'flavor_agent_block' )` |
  | `inc/Abilities/ContentAbilities.php:43` | `ChatClient::chat( $system, $user, ResponseSchema::get( 'content' ), 'flavor_agent_content' )` |
  | `inc/Abilities/NavigationAbilities.php:76` | `ResponsesClient::rank( $system, $user, null, ResponseSchema::get( 'navigation' ), 'flavor_agent_navigation' )` |
  | `inc/Abilities/StyleAbilities.php:131` | `ResponsesClient::rank( $system, $user, null, ResponseSchema::get( 'style' ), 'flavor_agent_style' )` |
  | `inc/Abilities/TemplateAbilities.php:118` (template) | `ResponsesClient::rank( $system, $user, null, ResponseSchema::get( 'template' ), 'flavor_agent_template' )` |
  | `inc/Abilities/TemplateAbilities.php:207` (template-part) | `ResponsesClient::rank( $system, $user, null, ResponseSchema::get( 'template_part' ), 'flavor_agent_template_part' )` |

- [ ] **Step 2: Extend each surface's ability test** (`BlockAbilitiesTest`, `TemplateAbilitiesTest`, etc.) with one assertion: the rank/chat mock captures the schema argument.

- [ ] **Step 3: Run → green.**

- [ ] **Step 4: Commit.**

  ```bash
  git add inc/Abilities tests/phpunit/*AbilitiesTest.php
  git commit -m "feat(abilities): enforce json_schema response_format per surface"
  ```

---

### Task C1: Add block few-shot exemplars

**Files:**
- Modify: `inc/LLM/Prompt.php` — implement `get_few_shot_examples(): array`.
- Extend: `tests/phpunit/PromptFewShotTest.php`

- [ ] **Step 1: Write the failing test.**

  ```php
  public function test_block_few_shot_examples_are_compact_and_shape_valid(): void {
      $examples = Prompt::get_few_shot_examples();

      $this->assertNotEmpty( $examples );
      $this->assertLessThanOrEqual( 3, count( $examples ), 'cap at 3 exemplars to keep budget headroom' );

      foreach ( $examples as $example ) {
          $this->assertLessThanOrEqual( 800, PromptBudget::estimate_tokens( $example ), 'each exemplar under ~800 tokens' );
          // Exemplars must contain an inline response that matches the block contract: top-level settings/styles/block/explanation.
          $this->assertMatchesRegularExpression( '/"settings"\s*:\s*\[/', $example );
          $this->assertMatchesRegularExpression( '/"styles"\s*:\s*\[/', $example );
          $this->assertMatchesRegularExpression( '/"block"\s*:\s*\[/', $example );
          $this->assertMatchesRegularExpression( '/"explanation"\s*:/', $example );
      }
  }
  ```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement.** Write 2-3 compact exemplars. Each shows one representative block (e.g., core/heading, core/image) with input context and a realistic output JSON that matches the real block contract from `Prompt.php:53-94`:

  ```php
  public static function get_few_shot_examples(): array {
      return [
          <<<'EXAMPLE'
  ## Example — core/heading, main area, dark parent container

  Input context:
  - Block: core/heading, currentAttributes: { "level": 3, "content": "Our Services", "align": null }
  - Inspector panels: { "general": true, "typography": true, "color": true }
  - Structural identity: role=page-section-heading, templateArea=main, first heading after hero.
  - Parent container: style.background uses preset slug "contrast" (dark).
  - Theme tokens: color.palette includes slugs "primary","accent","contrast","base"; typography.fluid=true.

  Expected response:
  {"settings":[{"label":"Promote to H2","description":"This is the first section heading after the hero; H1 is reserved for the page title.","panel":"general","type":"attribute_change","attributeUpdates":{"level":2},"currentValue":3,"suggestedValue":2,"confidence":0.85}],"styles":[{"label":"Use base text color for contrast","description":"Parent background uses the contrast preset, so the heading needs a light/base text color for AA contrast.","panel":"color","type":"attribute_change","attributeUpdates":{"textColor":"base"},"presetSlug":"base","cssVar":"var(--wp--preset--color--base)","confidence":0.8}],"block":[],"explanation":"Raise heading priority and invert text color for the dark container."}
  EXAMPLE,
          // Add 1-2 more exemplars — one with a style_variation and one with a structural_recommendation in the block[] array — to cover both executable and advisory paths.
      ];
  }
  ```

  These get added to the budget by `build_user()` at priority 10 (lowest — trimmed before docs guidance) via the loop installed in Task A1.

- [ ] **Step 4: Run full block prompt suite → green.**

  ```bash
  vendor/bin/phpunit --filter Prompt
  ```

- [ ] **Step 5: Commit.**

  ```bash
  git add inc/LLM/Prompt.php tests/phpunit/PromptFewShotTest.php
  git commit -m "feat(prompt): add block few-shot exemplars"
  ```

---

### Task C2–C6: Few-shot exemplars for remaining surfaces

One task per surface. Each follows Task C1 exactly, applied to the corresponding prompt class:

- **C2** — `TemplatePrompt::get_few_shot_examples()`: 2 exemplars (one per major operation like `assign_part` / `insert_block`). Commit: `feat(prompt): add template few-shot exemplars`.
- **C3** — `TemplatePartPrompt::get_few_shot_examples()`: 2 exemplars. Commit: `feat(prompt): add template-part few-shot exemplars`.
- **C4** — `StylePrompt::get_few_shot_examples()`: 2 exemplars (one Global Styles, one Style Book). Commit: `feat(prompt): add style few-shot exemplars`.
- **C5** — `NavigationPrompt::get_few_shot_examples()` + wire into its existing `build_user()` at priority 10. 2 exemplars (one reorder, one overlay recommendation). Commit: `feat(prompt): add navigation few-shot exemplars`.
- **C6** — `WritingPrompt::get_few_shot_examples()`: 1 minimal exemplar (scaffold surface). Commit: `feat(prompt): add content few-shot exemplar`.

---

### Task D1: Full-suite verification

- [ ] Run unit tests, both PHP and JS.

  ```bash
  vendor/bin/phpunit
  ```
  Expected: ALL green. Record the passing test count in the commit message for the next doc task.

  ```bash
  npm run test:unit -- --runInBand
  ```
  Expected: ALL green. Nothing in this plan touches JS — any JS regression is a red flag, investigate before proceeding.

- [ ] Run linters.

  ```bash
  composer lint:php
  npm run lint:js
  ```
  Expected: zero new issues.

- [ ] Run PHP build + JS build to confirm nothing was introduced that breaks packaging.

  ```bash
  npm run build
  ```

- [ ] If anything fails, stop and fix before the next task. Do not proceed to D2.

---

### Task D2: Optional live-API smoke

Gated — only runs if the developer has Azure OpenAI or OpenAI Native credentials configured locally via `.env`.

- [ ] Start the Docker WordPress container (`npm run wp:start`).
- [ ] Open the block editor, trigger a recommendation on a `core/heading` block.
- [ ] Open the Network tab and confirm:
  - Outgoing request to `/openai/v1/responses` (Azure) or `api.openai.com/v1/responses` (native) contains `"text":{"format":{"type":"json_schema",...`.
  - Incoming response parses cleanly (no fallback error notice in the editor).
- [ ] Do the same for Navigation and Template surfaces.
- [ ] If any surface returns malformed JSON with a strict schema, check the model — `strict: true` requires a model that supports Structured Outputs. Fall back to `strict: false` only if upstream docs confirm the target model doesn't support strict.

No commit for this task — it's verification-only.

---

### Task D3: Docs update

**Files:**
- Modify: `docs/reference/shared-internals.md` (append "Structured output enforcement" subsection)
- Modify: `docs/FEATURE_SURFACE_MATRIX.md` (confirm or add "Structured output" column; mark block/template/template-part/navigation/style/content as ✅, pattern as ⚠️ deferred to 1C)
- Modify: `docs/SOURCE_OF_TRUTH.md` (if Phase 1B has a line item, flip it to done; otherwise skip)
- Modify: `STATUS.md` (append a `2026-MM-DD phase-1b-closeout` entry with the verified test counts from Task D1)

- [ ] Run `npm run check:docs` → green.
- [ ] Commit.

  ```bash
  git add docs/ STATUS.md
  git commit -m "docs: record phase 1B prompt-engineering closeout"
  ```

---

## Definition of Done

- All prompt classes (`Prompt`, `TemplatePrompt`, `TemplatePartPrompt`, `StylePrompt`, `WritingPrompt`, `NavigationPrompt`) assemble user prompts via `PromptBudget`.
- `inc/LLM/ResponseSchema.php` exists; every non-pattern surface has a schema; schemas pass `additionalProperties=false` strict checks.
- `ResponsesClient::rank()` emits `text.format = { type: 'json_schema', strict: true, … }` when a schema is passed; signature-compatible with existing callers.
- `ChatClient::chat()` and `WordPressAIClient::chat()` thread the schema; the WP AI Client path calls `as_json_response( $schema )` on the builder when available (and silently falls through when not).
- `tests/phpunit/bootstrap.php` WP AI client stub handles `as_json_response` so schema-path tests execute without `BadMethodCallException`.
- Every surface's budget can be constrained deterministically in tests via `add_filter( 'flavor_agent_prompt_budget_max_tokens', …, 10, 2 )`.
- Every LLM-backed ability handler passes its surface's schema.
- Each prompt class has 1–3 compact few-shot exemplars contributing as priority-10 `PromptBudget` sections (trimmed first under pressure).
- `vendor/bin/phpunit` and `npm run test:unit` both green; `composer lint:php` and `npm run lint:js` clean; `npm run build` succeeds.
- `STATUS.md` has a phase-1b-closeout entry; `docs/FEATURE_SURFACE_MATRIX.md` reflects structured-output coverage.

## Out of scope (defer to later phases)

- Pattern ranking schema (Phase 1C).
- Admin visualization of prompt budget diagnostics (nice-to-have; no spec requirement).
- Token-count telemetry emitted to the activity log (touches `Activity\Serializer`; separate plan).
- Replacing hand-crafted exemplars with activity-log-sourced real examples (Phase 5 territory).
