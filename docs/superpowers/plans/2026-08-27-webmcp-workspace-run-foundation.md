# WebMCP Workspace, Context, and Run Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Spec 1 as the sole page-owned recommendation workspace, a bounded client/server context contract, and an authenticated immutable server-run workflow that can generate any canonical subset of nine wire surfaces through the eight existing recommendation Abilities without exposing WebMCP tools or changing current apply/undo behavior.

**Architecture:** Keep mutable workflow coordination in one extracted slice composed into the existing flavor-agent data store. Capture unsaved Gutenberg state through a closed client seam registry, join it to server-owned identity and context, and sign the normalized envelope with true RFC 8785 canonical JSON. Orchestrate only fixed registered WP_Ability objects. Reserve, fence, finalize, project, tombstone, and prune immutable public/private run payloads in a dedicated per-site repository. The browser caches a completed run and installs its current relationship only through a same-page revision compare-and-swap.

**Tech Stack:** PHP 8.2, WordPress 7.0+ Abilities and REST APIs, MySQL/dbDelta, JavaScript/React, @wordpress/data, @wordpress/api-fetch, Gutenberg editor stores, Jest through @wordpress/scripts, PHPUnit 9, Playwright, Composer, and the repository verification runner.

**Spec:** [Spec 1: Workspace, Context, and Run Foundation](../specs/2026-08-27-webmcp-workspace-run-foundation-design.md), governed by [WebMCP Recommendation Protocol 1.0](../specs/2026-08-27-webmcp-recommendation-protocol-design.md) and [Cross-Surface Validation Gates](../../reference/cross-surface-validation-gates.md).

## Global Constraints

- Begin execution from the commit containing this approved plan and spec. Record the immutable starting SHA, then re-resolve every named source anchor if the checkout has advanced.
- Before creating the implementation worktree, stop unless the owner has committed the existing user changes to docs/reference/abilities-and-routes.md onto a known base, or exported them as a named patch and explicitly authorized applying that patch. A clean worktree from this plan commit will not contain those uncommitted edits, so silently editing the stale committed copy is forbidden. Keep the unrelated untracked plan and every other user-owned change untouched.
- After that prerequisite is resolved, use superpowers:using-git-worktrees and start from the commit containing this approved plan/spec plus the owner's resolved documentation base.
- Follow strict TDD for every production behavior: add a discriminating test, run it and observe the intended failure, implement the smallest complete behavior, then rerun the focused suite.
- Edit source and tests only. Do not hand-edit build/ or dist/. Run npm run build before browser evidence because the Playwright harnesses do not compile src/.
- Keep the current eight recommendation Abilities and 35 total Ability invariant. Do not create a ninth workflow Ability, add an MCP Adapter exposure, or register document.modelContext tools.
- Preserve every legacy recommendation panel and its current checkbox/review owner. Spec 1 adds no product button and performs no dual write to shared selection, review, or apply-plan state.
- Invoke recommendations only through the registered WP_Ability object returned by wp_get_ability(). Validate the fixed input before permission preflight, call execute(), then independently validate the output; never invoke recommender callbacks directly.
- Keep preflight input distinct from the exact input WordPress authorized. Gate the post-normalization validation boundary against protected target drift, require a scoped `wp_before_execute_ability` witness for every ready result, retain only its closed `retainedAuthorizationInput`, and fail a short-circuited apparent success closed.
- Add the closed optional workspaceContext field to the same eight recommendation Abilities and consume every included signed seam through its fixed native, preflight, or guidance destination. Run-based precollected docs suppress the legacy internal docs lookup; legacy direct calls without workspaceContext retain it.
- Read hard/soft context criticality from the manifest. Missing hard mandatory context makes only the affected surface unavailable; docs grounding and recent outcomes are soft, remain visible in the receipt, and never block recommendation execution.
- Spec 1 emits only advisory and stage_only units. Do not emit governed_apply or executionBinding until Spec 3 owns exact binding predicates and operation digests.
- Keep arbitrary Ability IDs, Gutenberg store names, selectors, selector arguments, operations, native results, credentials, site IDs, and user IDs out of client-controlled request shapes.
- Treat request route schemas as closed recursively. WordPress route args alone do not reject unknown JSON members; the controller must validate the raw body and complete decoded value before reservation.
- Preserve JSON kind in PHP: raw JSON objects decode as stdClass, empty objects remain stdClass through validation/canonicalization, and empty PHP arrays represent only JSON arrays.
- Preserve the vendor-less bootstrap guarantee. Do not add a runtime Composer dependency for canonical JSON or any other foundation class unless release packaging and the fallback bootstrap are deliberately redesigned and separately approved.
- Do not reuse inc/Attestation/Canonicalizer.php, inc/Support/RecommendationSignature.php, or src/utils/structural-equality.js for protocol digests. Their sorting/number behavior is not RFC 8785.
- Keep all revisions and request tokens in 0..9007199254740991. Overflow is workspace_revision_exhausted; wrapping or lossy comparison is forbidden.
- Key asynchronous generation cleanup by `{ workspaceId, requestToken }` and exact controller identity; a token from an invalidated workspace can never release or settle a fresh request.
- Keep request bodies and the internal REST wrapper closed, while normalizing nested public runs with the canonical compatible-output rule: select known fields, strip compatible optional extensions, and still reject reserved/private or lifecycle-incompatible members.
- Capture the authoritative clock once per reservation, lease renewal, finalization, projection, or prune batch. Store UTC seconds and expose RFC 3339 UTC.
- Keep public run JSON and private native bindings separate. No success, error, hook, log, or test snapshot may expose private payloads or unrestricted context.
- Reserve HTTP 503 for manifest/storage/foundation failure before a terminal run. Provider or adapter failures become bounded per-surface results whenever terminal finalization is still possible.
- A green focused suite is not completion. Execute both WordPress 7.1 browser harnesses, the non-browser aggregate, documentation checks, Plugin Check status, and archive inclusion checks from a fresh clean checkout of one immutable CANDIDATE_SHA; classify any unavailable harness as a blocker or explicit waiver.
- Per-task commit commands below are for the later authorized implementation session. Do not combine unrelated user changes with those commits.

---

## Acceptance Coverage Matrix

| Spec acceptance | Primary task | Required evidence |
| --- | --- | --- |
| AC1 sole page owner and no second store/context | Tasks 4–5 | Composed-store tests and source scan |
| AC2 tab-local identity and primary-scope reset | Tasks 3 and 5 | Independent page/actor UUIDs, scope, null-gap, and two-tab tests |
| AC3 exact context/install CAS effects | Task 4 | Pure reducer plus exported thunk tests |
| AC4 late run retained but not current | Tasks 4, 5, and 13–14 | Workspace-aware handle race and browser test |
| AC5 any valid one-to-nine surface subset | Tasks 1, 8, and 11 | Registry fixture and orchestration matrix |
| AC6 two style invocations and generating post_blocks | Tasks 1 and 8 | Mapping and invoker assertions |
| AC7 complete result set and status derivation | Tasks 8 and 11 | Ready/partial/failed adapter tests |
| AC8 receipt partition plus consumed values | Tasks 6–8 | Shared receipt fixtures and per-Ability consumption spies |
| AC9 server-owned signature and bindings | Tasks 7–8 | Inclusion/exclusion, protected post-normalization gate, execution witness, closed retained authorization input, and spoof rejection |
| AC10 bounded immutable public/private payloads | Tasks 8–13 | Size, digest, corruption, and compatible-response projection tests |
| AC11 idempotency, takeover, and fencing | Tasks 9–10 | Explicit interleaving repository fake |
| AC12 expiry and tombstone deadlines | Tasks 9–10 | Exact boundary tests |
| AC13 read projection performs no writes | Tasks 10–11 | Write-counter assertions |
| AC14 lifecycle, prune, multisite, uninstall | Tasks 9–10 | Lifecycle/repository tests |
| AC15 legacy recommendation/apply/undo unchanged | Tasks 5, 13, and 14 | Regression and browser evidence |
| AC16 no public WebMCP capability | Tasks 1, 5, and 14 | Ability count and registration counter |
| AC17 immutable-candidate verification ledger | Task 14 | Candidate SHA, evidence SHA, archive digest, and blocker status |

## File Structure

### Shared contract and fixtures

| File | Responsibility |
| --- | --- |
| shared/recommendation-protocol-1.0.json | Runtime-shipped protocol 1.0 manifest, schemas, order, limits, defaults, mappings, seam grammar, and errors |
| tests/fixtures/recommendation-protocol/manifest-valid.json | Loader parity and deep-freeze fixture |
| tests/fixtures/recommendation-protocol/manifest-invalid-version.json | Wrong-version fail-closed fixture |
| tests/fixtures/recommendation-protocol/manifest-missing-required-key.json | Incomplete manifest fail-closed fixture |
| tests/fixtures/recommendation-protocol/canonical-json-cases.json | RFC 8785 bytes and SHA-256 vectors |
| tests/fixtures/recommendation-protocol/configuration-cases.json | PHP/JS normalization parity cases |
| tests/fixtures/recommendation-protocol/context-cases.json | Seam, receipt, truncation, and signature cases |
| tests/fixtures/recommendation-protocol/run-cases.json | Registry, IDs, statuses, limits, and public-run cases |
| tests/fixtures/recommendation-protocol/rest-contract.json | Closed request and stable HTTP/error mapping cases |

### JavaScript

| File | Responsibility |
| --- | --- |
| src/recommendations/protocol/v1-contract.js | Validate and deeply freeze the imported manifest |
| src/utils/canonical-json.js | RFC 8785 serialization and SHA-256 |
| src/recommendations/workspace/context-configuration.js | Configuration normalization and semantic equality |
| src/recommendations/workspace/editor-instance.js | Secure page-lifetime IDs with injectable randomness |
| src/recommendations/workspace/editor-scope.js | Closed primary-scope resolver |
| src/recommendations/workspace/state.js | Default slices, pure reducer, CAS action creators, and selectors |
| src/recommendations/context/collector.js | Fixed client seam collectors and snapshot validation |
| src/recommendations/runs/client.js | Fixed authenticated POST/GET client and response validation |
| src/recommendations/workspace/coordinator.js | Capture, network, cache, install, race, and abort orchestration |
| src/components/RecommendationWorkspaceBootstrap.js | One mounted scope/focus lifecycle bridge |
| src/store/index.js | Compose the extracted state, actions, reducer, and selectors |
| src/index.js | Mount the workspace bootstrap before recommendation panels |

### PHP

| File | Responsibility |
| --- | --- |
| inc/Support/CanonicalJson.php | JCS validation, serialization, and digest entry point |
| inc/Support/EcmaScriptNumber.php | RFC 8785/ECMAScript finite-number formatting |
| inc/Support/JsonDuplicateKeyScanner.php | Duplicate-aware raw JSON object-key validation |
| inc/Support/ClosedJsonSchemaValidator.php | Recursive fail-closed validation for manifest protocol and Ability schemas |
| inc/Recommendations/Protocol/V1Contract.php | Request-local manifest loader and schema access |
| inc/Recommendations/Context/ContextConfiguration.php | Server normalization and canonical digest |
| inc/Recommendations/Context/EditorScope.php | Shared scope parsing and current-user authorization |
| inc/Recommendations/Context/ClientCaptureValidator.php | Complete caller-capture validation before reservation |
| inc/Recommendations/Context/WorkspaceDocsGroundingCollector.php | Canonical per-surface trusted docs queries and bounded results |
| inc/Recommendations/Context/ServerSeamCollector.php | Fixed server-owned seam collection and normalization |
| inc/Recommendations/Context/ContextEnvelopeBuilder.php | Requested seams, server collection, context, and receipt |
| inc/Recommendations/Context/ContextSignature.php | Authenticated context signature projection |
| inc/Support/TrustedDocsGroundingPolicy.php | Runtime HTTPS host/path and currentness gate for docs seams |
| inc/Support/WorkspaceContextGuidance.php | Deterministic semantic/outcome/docs consumption for the existing Abilities |
| inc/Recommendations/Runs/SurfaceRegistry.php | Closed nine-surface mapping |
| inc/Recommendations/Runs/SurfaceInputAdapter.php | Per-surface existing-Ability input construction |
| inc/Recommendations/Runs/AbilityInvoker.php | Registered Ability preflight, execution, and validation |
| inc/Recommendations/Runs/SurfaceResultAdapter.php | Public units and private bindings |
| inc/Recommendations/Runs/RecommendationRunStorageContext.php | Immutable per-blog database/table owner |
| inc/Recommendations/Runs/RecommendationRunRepository.php | Schema, reservation, lease, finalize, read, and prune |
| inc/Recommendations/Runs/RunAvailabilityProjector.php | Pure active/expired/not-found projection |
| inc/Recommendations/Runs/RecommendationRunService.php | Creation/read orchestration and authorization |
| inc/REST/RecommendationRunsController.php | Closed authenticated create/read routes |

### Verification and documentation

| File | Responsibility |
| --- | --- |
| tests/phpunit/RecommendationProtocolContractTest.php | Manifest and cross-runtime contract |
| tests/phpunit/CanonicalJsonTest.php | PHP JCS conformance |
| tests/phpunit/RecommendationContextTest.php | Scope, configuration, receipt, and signature |
| tests/phpunit/RecommendationRunRepositoryTest.php | Storage, races, fencing, immutability, and prune |
| tests/phpunit/RecommendationRunServiceTest.php | Surface orchestration and owner reauthorization |
| tests/phpunit/RecommendationRunsControllerTest.php | Raw closed REST contract and status mapping |
| tests/phpunit/RecommendationRunLifecycleTest.php | Activation, schedule, multisite, deactivation, uninstall |
| tests/phpunit/VendorlessRecommendationRunBootstrapTest.php | Fresh-process fallback-autoloader coverage for the shipped manifest/runtime classes |
| tests/e2e/flavor-agent.recommendation-workspace.spec.js | Page identity, CAS races, partial runs, and legacy coexistence |
| docs/reference/recommendation-workspace-and-runs.md | Stable implementation reference |
| docs/validation/2026-08-27-webmcp-workspace-run-foundation.md | Immutable verification ledger |

---

### Task 1: Establish the shared protocol manifest and loaders

**Files:**

- Create: shared/recommendation-protocol-1.0.json
- Create: tests/fixtures/recommendation-protocol/manifest-valid.json
- Create: tests/fixtures/recommendation-protocol/manifest-invalid-version.json
- Create: tests/fixtures/recommendation-protocol/manifest-missing-required-key.json
- Create: src/recommendations/protocol/v1-contract.js
- Create: src/recommendations/protocol/__tests__/v1-contract.test.js
- Create: inc/Recommendations/Protocol/V1Contract.php
- Create: inc/Support/JsonDuplicateKeyScanner.php
- Create: tests/phpunit/RecommendationProtocolContractTest.php
- Modify: tests/phpunit/AbilitySchemaContractTest.php
- Modify: tests/phpunit/RegistrationTest.php
- Verify only: .distignore

**Interfaces:**

    // JavaScript
    validateRecommendationProtocolV1Manifest(manifest)
    getRecommendationProtocolV1Contract()

    // PHP
    V1Contract::load(): array|\WP_Error
    V1Contract::protocol_version(): string|\WP_Error
    V1Contract::collector_version(): string|\WP_Error
    V1Contract::surface_ids(): array|\WP_Error
    V1Contract::surface(string $surface_id): array|\WP_Error
    V1Contract::seam(string $path): array|\WP_Error
    V1Contract::limits(): array|\WP_Error
    V1Contract::schema(string $name): array|\WP_Error
    JsonDuplicateKeyScanner::assert_unique_keys(string $json): void

- [ ] **Step 1: Write failing manifest contract tests**

Assert exactly this canonical surface order:

    block
    content
    pattern
    navigation
    template
    template_part
    post_blocks
    global_styles
    style_book

Assert exactly eight unique recommendation Ability IDs, two independently scoped style entries, the five reserved future executor-lane names and schema versions, no Spec 1 governed_apply policy, every interaction mode, all nine mandatory surface profiles and expanded hardContextPaths arrays, all four focus profiles, all 18 closed seam definitions, every seam consumer allowlist/destination/criticality, every truncation maximum/unit, the exact five-row trusted-docs allowlist/currentness policy, every surface's exact authorization-projection paths, the per-surface closed retained-authorization-input schemas and 1 MiB cap, the nested-response reserved-member list, fixed defaults, the safe-integer ceiling, every section 11 field/collection/byte limit and regex, public/internal REST schemas, the complete closed JSON Schema vocabulary, and foundation error metadata. Assert wrong/missing versions fail rather than falling back.

Require shared fixtures across Tasks 1–8 to cover every string minimum/maximum/maximum-plus-one and regex near-miss; every collection maximum/maximum-plus-one/duplicate/order rule; all six editor-scope unions and prefix-composed maxima; timestamp offset/milliseconds/impossible-date/leap-second cases; the edited-content escaping cap; exact receipt partition, category-only fields, server-derived truncation limits/units, and cross-field count mismatches; operation-count caps per surface; every error-details branch; `{}` versus `[]`; invalid UTF-8/lone surrogates; docs 8/9-item plus 360/361-character boundaries; and the one-path mixed docs aggregation with included, unavailable, and differently truncated surface counts. PHP and JavaScript must accept/reject the shared wire fixtures identically.

Run:

    npm run test:unit -- --runInBand src/recommendations/protocol/__tests__/v1-contract.test.js
    vendor/bin/phpunit tests/phpunit/RecommendationProtocolContractTest.php

Expected: both suites fail because the manifest and loaders do not exist.

- [ ] **Step 2: Add the manifest as the sole static source**

Use this root structure, with every nested object closed:

    {
      "manifestVersion": "recommendation-protocol-manifest-v1",
      "protocolVersion": "1.0",
      "collectorVersion": "recommendation-context-v1",
      "surfaceOrder": [],
      "surfaces": {},
      "executorLanes": {},
      "context": {
        "groupOrder": [],
        "groups": {},
        "seamOrder": [],
        "seams": {},
        "mandatoryProfiles": {},
        "consumerRegistry": {},
        "docsGroundingPolicy": {}
      },
      "configuration": {
        "defaults": {},
        "enums": {}
      },
      "limits": {},
      "schemas": {},
      "errors": {}
    }

Transcribe the approved spec exactly, including all per-field length/regex rules and the exact manifest JSON Schema keyword allowlist. Do not add localized labels, callbacks, selector names as caller input, or UI capability keys. The nine wire names, backend hyphenated surface names, and camel-case UI capability names remain distinct naming domains.

- [ ] **Step 3: Implement fail-closed loaders**

First implement JsonDuplicateKeyScanner so it tokenizes raw strings/escapes, arrays, and objects deeply enough to reject repeated decoded property names at each object level before json_decode() can collapse them. The PHP loader resolves FLAVOR_AGENT_DIR . 'shared/recommendation-protocol-1.0.json', scans for duplicate JSON keys, decodes with JSON_THROW_ON_ERROR, validates required invariant values, and caches success or failure by resolved path for the request. Missing, malformed, or wrong-contract data returns recommendation_protocol_unavailable with unavailable/retry_same semantics. Add a test-only path override that is explicit and resettable; production callers cannot pass a path.

The JavaScript loader imports the same JSON at build time, validates the expected versions and invariants, recursively freezes arrays and objects, and exports no mutable raw reference. Neither loader contains a fallback surface array.

- [ ] **Step 4: Repair existing duplicated Ability coverage**

Update AbilitySchemaContractTest.php and the relevant RegistrationTest.php curation/ranking/guideline loops so recommend-post-blocks is covered. Preserve exactly 35 total registered Abilities and 15 dedicated MCP tools. Add assertions that Spec 1 registers neither a wrapper Ability nor a WebMCP tool.

- [ ] **Step 5: Prove contract and package inclusion**

Run:

    npm run test:unit -- --runInBand src/recommendations/protocol/__tests__/v1-contract.test.js
    vendor/bin/phpunit tests/phpunit/RecommendationProtocolContractTest.php tests/phpunit/AbilitySchemaContractTest.php tests/phpunit/RegistrationTest.php
    npm run build
    npm run dist
    unzip -l dist/flavor-agent.zip | rg 'shared/recommendation-protocol-1.0.json'

Expected: all tests pass; the manifest appears once in the archive; no generated file is staged.

- [ ] **Step 6: Commit the contract**

    git add shared/recommendation-protocol-1.0.json tests/fixtures/recommendation-protocol/manifest-valid.json tests/fixtures/recommendation-protocol/manifest-invalid-version.json tests/fixtures/recommendation-protocol/manifest-missing-required-key.json src/recommendations/protocol/v1-contract.js src/recommendations/protocol/__tests__/v1-contract.test.js inc/Recommendations/Protocol/V1Contract.php inc/Support/JsonDuplicateKeyScanner.php tests/phpunit/RecommendationProtocolContractTest.php tests/phpunit/AbilitySchemaContractTest.php tests/phpunit/RegistrationTest.php
    git commit -m "Add recommendation protocol manifest"

---

### Task 2: Implement true RFC 8785 canonical JSON and duplicate-key rejection

**Files:**

- Create: tests/fixtures/recommendation-protocol/canonical-json-cases.json
- Create: src/utils/canonical-json.js
- Create: src/utils/__tests__/canonical-json.test.js
- Create: inc/Support/CanonicalJson.php
- Create: inc/Support/EcmaScriptNumber.php
- Modify: inc/Support/JsonDuplicateKeyScanner.php
- Create: tests/phpunit/CanonicalJsonTest.php

**Interfaces:**

    canonicalJsonStringify(value): string
    canonicalJsonSha256(value, { subtle } = {}): Promise<string>

    CanonicalJson::encode(mixed $value): string
    CanonicalJson::digest(mixed $value): string
    JsonDuplicateKeyScanner::assert_unique_keys(string $json): void

All PHP validation failures throw InvalidArgumentException. Service/controller boundaries convert those exceptions to bounded WP_Error objects.

- [ ] **Step 1: Add cross-runtime conformance fixtures**

Include exact expected UTF-8 bytes and lowercase SHA-256 for recursive object ordering, preserved array order, UTF-16 code-unit key ordering (including supplementary/private-use keys), escaping, unescaped slash, distinct NFC/NFD strings, and the RFC 8785 Appendix B number boundaries. Include at least:

    -0            -> 0
    5e-324        -> 5e-324
    1e+23         -> 1e+23
    1e-7          -> 1e-7
    1e-6          -> 0.000001
    1e20          -> 100000000000000000000

Add exact `{}` versus `[]` vectors at the root and nested under `surfaceParameters`, object properties, and arrays. Add programmatic rejection cases for NaN, positive/negative infinity, invalid UTF-8, lone surrogates, unsupported objects/resources, cycles, sparse numeric-key PHP arrays, ambiguous empty PHP containers, and duplicate raw JSON keys.

- [ ] **Step 2: Observe current implementations fail**

Write tests that compare the fixture outputs to the existing JSON behavior and specifically expose PHP/JavaScript differences for -0, 1.0e+30, 1.0e-7, and 1.0e-6.

Run:

    npm run test:unit -- --runInBand src/utils/__tests__/canonical-json.test.js
    vendor/bin/phpunit tests/phpunit/CanonicalJsonTest.php

Expected: failures prove no conforming utilities exist and PHP native encoding is insufficient.

- [ ] **Step 3: Implement the JavaScript utility**

Validate the I-JSON value graph, reject non-finite numbers and unsupported values, sort object keys by UTF-16 code units without localeCompare(), and use ECMAScript JSON number/string serialization. Hash TextEncoder output through an injected SubtleCrypto implementation and return exactly 64 lowercase hex characters. Do not mutate inputs.

- [ ] **Step 4: Implement the PHP utility without a runtime dependency**

CanonicalJson recursively validates the I-JSON data model, sorts property names by their UTF-16 code-unit sequences, escapes strings exactly as RFC 8785 requires, and delegates finite doubles to EcmaScriptNumber. Treat stdClass and non-list associative maps as JSON objects, list arrays as JSON arrays, and an empty PHP array as `[]`; an empty JSON object must remain stdClass from raw decode or be created explicitly as stdClass. Reject sparse numeric arrays and never infer `{}` from `[]`. EcmaScriptNumber must implement shortest round-trippable ECMAScript Number::toString formatting and the decimal/exponent thresholds proven by the shared fixture; ksort(), sprintf('%.17g'), and wp_json_encode() are not substitutes.

Harden JsonDuplicateKeyScanner with the shared malformed/escape/nesting corpus and prove it never materializes unrestricted values. Do not replace its pre-decode check with a decoded-array comparison.

- [ ] **Step 5: Verify parity and isolation**

Run:

    npm run test:unit -- --runInBand src/utils/__tests__/canonical-json.test.js
    vendor/bin/phpunit tests/phpunit/CanonicalJsonTest.php tests/phpunit/AttestationCanonicalizerTest.php

Expected: PHP and JavaScript match every shared byte/digest vector; existing attestation semantics remain unchanged.

- [ ] **Step 6: Commit canonicalization**

    git add tests/fixtures/recommendation-protocol/canonical-json-cases.json src/utils/canonical-json.js src/utils/__tests__/canonical-json.test.js inc/Support/CanonicalJson.php inc/Support/EcmaScriptNumber.php inc/Support/JsonDuplicateKeyScanner.php tests/phpunit/CanonicalJsonTest.php
    git commit -m "Add canonical recommendation JSON"

---

### Task 3: Normalize context configuration and editor scope in both runtimes

**Files:**

- Create: tests/fixtures/recommendation-protocol/configuration-cases.json
- Create: src/recommendations/workspace/context-configuration.js
- Create: src/recommendations/workspace/__tests__/context-configuration.test.js
- Create: src/recommendations/workspace/editor-instance.js
- Create: src/recommendations/workspace/editor-scope.js
- Create: src/recommendations/workspace/__tests__/editor-instance.test.js
- Create: src/recommendations/workspace/__tests__/editor-scope.test.js
- Create: inc/Recommendations/Context/ContextConfiguration.php
- Create: inc/Recommendations/Context/EditorScope.php
- Create: tests/phpunit/RecommendationContextTest.php

**Interfaces:**

    normalizeRecommendationContextConfiguration(input, { contract } = {})
    canonicalizeRecommendationContextConfiguration(input, options = {})
    areRecommendationContextConfigurationsEqual(left, right, options = {})
    isRecommendationContextConfigurationConfigured(value)

    createUuidV4({ getRandomValues } = {})
    createRecommendationPageIdentity(dependencies = {}): Readonly<{
      editorInstanceId: string,
      humanActorSessionId: string
    }>
    getRecommendationPageIdentity(): Readonly<{
      editorInstanceId: string,
      humanActorSessionId: string
    }>
    createRecommendationWorkspaceId(dependencies = {})

    selectRecommendationEditorScopeInputs(select)
    resolveRecommendationEditorScope(inputs, dependencies)
    getCurrentRecommendationEditorScope(registry, options)

    ContextConfiguration::normalize(mixed $configuration): array|\WP_Error
    ContextConfiguration::canonical_bytes(array $configuration): string|\WP_Error
    ContextConfiguration::digest(array $configuration): string|\WP_Error

    EditorScope::normalize(mixed $scope): array|\WP_Error
    EditorScope::from_key(string $scope_key): array|\WP_Error
    EditorScope::authorize(array $scope): true|\WP_Error

- [ ] **Step 1: Add shared normalization fixtures**

Cover fixed defaults, canonical surface/group ordering, duplicate rejection, order-preserving constraint/client-ID dedupe, Unicode edge trimming, forbidden control characters, unknown keys at every level, every section 11 min/max/pattern boundary, outcomes_only materializing recent_outcomes, none plus recent_outcomes rejection, content mode inclusion/exclusion, empty sentinel rejection for generation, revision ceiling, every persistent scope kind, temporary kinds, and malformed/traversal scope segments. Pin template refs at 179 characters, template-part refs at 174, safe decimal entity IDs at 16, and complete scope keys at 191. Include PHP/JS parity cases proving `surfaceParameters: {}` and every other empty object remain objects while empty lists remain arrays.

The expected configured value is always complete:

    {
      surfaceIds: [ 'block' ],
      intent: {
        goal: 'Improve the current selection',
        audience: '',
        tone: '',
        constraints: []
      },
      focus: {
        scope: 'document',
        clientIds: []
      },
      additionalContextGroups: [],
      detailLevel: 'balanced',
      recentActivity: 'none',
      surfaceParameters: {}
    }

When content is requested without a mode, surfaceParameters is exactly:

    { content: { mode: 'draft' } }

- [ ] **Step 2: Write failing PHP/JS parity tests**

Each valid case must emit identical canonical bytes and digest in PHP and JavaScript. Each invalid case must return context_configuration_invalid with the same path and reasonCode. Add secure-randomness tests that reject Math.random() fallback. Pin one frozen page-identity object per module lifetime: repeated reads return the identical pair; both members are lowercase UUIDv4 values from separate draws; the two values differ; workspace IDs use additional draws; and two isolated page/module harnesses receive four distinct identity values.

Run:

    npm run test:unit -- --runInBand src/recommendations/workspace/__tests__/context-configuration.test.js src/recommendations/workspace/__tests__/editor-instance.test.js src/recommendations/workspace/__tests__/editor-scope.test.js
    vendor/bin/phpunit tests/phpunit/RecommendationContextTest.php

Expected: fail because the normalizers/resolvers do not exist.

- [ ] **Step 3: Implement configuration parity**

Read enums, order, defaults, regexes, and limits only from V1Contract. Return typed JavaScript validation errors with code, path, and reasonCode; return bounded WP_Error data with the same fields in PHP. Treat {} as the sole unconfigured page sentinel, not a valid POST configuration. In PHP, preserve empty object-valued fields as stdClass and use arrays only for JSON lists; convert to domain maps only after canonical bytes and digest are fixed.

- [ ] **Step 4: Implement secure page identity and scope resolution**

Use crypto.getRandomValues(), set UUIDv4 version/variant bits, and fail closed without secure randomness. `createRecommendationPageIdentity()` performs and caches exactly two independent draws for `{ editorInstanceId, humanActorSessionId }`; `createRecommendationWorkspaceId()` never reuses either value. Resolver priority is Style Book target, Global Styles root, canonical Site Editor entity, regular saved post/CPT, then temporary entity.

Pin these subtleties:

- Style Book entityId is its Global Styles entity ID; block name is only in the key.
- Style Book block-target changes create a new scope, but subsection/blockTitle churn does not.
- Selected blocks and inserter position never change primary scope.
- Temporary-to-canonical first save creates a different scope.
- A temporary entity kind is a registered post-type slug or one of the four approved Site Editor kinds.
- EditorScope::authorize() uses the registered post type's edit_posts capability for a temporary post type and edit_theme_options for temporary Site Editor kinds.

- [ ] **Step 5: Verify and commit**

Run:

    npm run test:unit -- --runInBand src/recommendations/workspace/__tests__/context-configuration.test.js src/recommendations/workspace/__tests__/editor-instance.test.js src/recommendations/workspace/__tests__/editor-scope.test.js
    vendor/bin/phpunit tests/phpunit/RecommendationContextTest.php

Then:

    git add tests/fixtures/recommendation-protocol/configuration-cases.json src/recommendations/workspace/context-configuration.js src/recommendations/workspace/editor-instance.js src/recommendations/workspace/editor-scope.js src/recommendations/workspace/__tests__/context-configuration.test.js src/recommendations/workspace/__tests__/editor-instance.test.js src/recommendations/workspace/__tests__/editor-scope.test.js inc/Recommendations/Context/ContextConfiguration.php inc/Recommendations/Context/EditorScope.php tests/phpunit/RecommendationContextTest.php
    git commit -m "Normalize recommendation workspace context"

---

### Task 4: Add the pure workspace, cache, generation, and CAS slice

**Files:**

- Create: src/recommendations/workspace/state.js
- Create: src/recommendations/workspace/__tests__/state.test.js
- Create: src/store/__tests__/recommendation-workspace.test.js
- Modify: src/store/index.js

**Interfaces:**

    recommendationWorkspaceDefaultState
    createRecommendationWorkspaceActionCreators()
    reduceRecommendationWorkspaceState(state, action)
    recommendationWorkspaceSelectors

    initializeRecommendationWorkspace({ workspaceId, editorScope })
    invalidateRecommendationWorkspace()
    replaceRecommendationContextConfiguration({
      workspaceId,
      expectedWorkspaceRevision,
      contextConfiguration
    })
    synchronizeRecommendationFocus({
      workspaceId,
      expectedWorkspaceRevision,
      clientIds
    })
    beginRecommendationGeneration({
      workspaceId,
      expectedWorkspaceRevision,
      idempotencyKey
    }): { requestKey: { workspaceId, requestToken } } | error
    cacheRecommendationRun({ run, payloadDigest })
    installRecommendationRun({
      workspaceId,
      expectedWorkspaceRevision,
      runId
    })
    finishRecommendationGeneration({ requestKey, runId = null })
    failRecommendationGeneration({ requestKey, error })

- [ ] **Step 1: Write pure reducer failures first**

Cover unbound defaults; initialize and invalidate; semantic/no-op context replacement; wrong ID/revision no-op; revision overflow; atomic supersede/clear; selected focus replacement; generation state with no revision change; immutable cache insertion; same-ID/same-digest retry; same-ID/different-digest run_payload_mismatch; deterministic 10-run eviction; first install; exact current retry; superseded-run non-revival; and two different runs from one base revision. Pin generation begin returning the committed `{ workspaceId, requestToken }`, finish/fail requiring both values, stale old-workspace keys being no-ops even when the visible token matches, and request-token overflow returning `workspace_revision_exhausted` without changing state.

Run:

    npm run test:unit -- --runInBand src/recommendations/workspace/__tests__/state.test.js

Expected: fail because state.js does not exist.

- [ ] **Step 2: Implement the extracted pure state module**

Use this exact unbound default:

    {
      recommendationWorkspace: {
        protocolVersion: '1.0',
        workspaceId: null,
        workspaceRevision: 0,
        editorScope: null,
        contextConfiguration: {},
        currentRun: null,
        selection: {},
        review: {},
        applyPlan: {}
      },
      recommendationRunCache: {
        byId: {},
        payloadDigestsById: {}
      },
      recommendationGeneration: {
        requestToken: 0,
        status: 'idle',
        baseWorkspaceId: null,
        baseWorkspaceRevision: null,
        idempotencyKey: null,
        error: null
      }
    }

Reducers contain no controllers, Promises, registry references, selector functions, DOM, iframe, or React values. Retain the current run plus nine newest non-current runs; without a current relationship, retain the ten newest. Order by completedAt descending, then runId deterministically.

- [ ] **Step 3: Implement synchronous guarded thunks**

Every CAS thunk performs:

    select current workspace
    compare workspace ID and expected revision
    dispatch one guarded reducer action without awaiting
    select committed workspace
    return structured success or conflict

The reducer repeats the guard. Exact same-current-run/same-digest installation succeeds without mutation; a superseded run never revives.

`beginRecommendationGeneration` also checks the current token before dispatch. When it is already `9007199254740991`, return `workspace_revision_exhausted` with no action. Otherwise the guarded reducer increments it once and the thunk returns a frozen request key from the committed `baseWorkspaceId` and `requestToken`. Finish/fail compare both fields; token equality alone is never sufficient.

- [ ] **Step 4: Compose the existing store**

Import the extracted default-state factory near src/store/index.js imports, merge the three slices into DEFAULT_STATE, spread the extracted action creators with existing actions, run reduceRecommendationWorkspaceState() before the legacy reducer switch, and spread the exact selector names fixed by Spec 1. Do not register another store.

Public selectors must omit payloadDigestsById. getCurrentRecommendationRun() returns cached current or superseded evidence. Availability is exactly active, expired, or unresolved with reasonCode run_not_cached and performs no dispatch/fetch.

- [ ] **Step 5: Prove exported-store behavior**

In src/store/__tests__/recommendation-workspace.test.js, exercise the actual exported actions/selectors through the registered flavor-agent store. Assert the legacy state remains present, one store registration occurs, and the reserved empty selection/review/applyPlan values are not touched by existing panels.

Run:

    npm run test:unit -- --runInBand src/recommendations/workspace/__tests__/state.test.js src/store/__tests__/recommendation-workspace.test.js
    npm run lint:js

- [ ] **Step 6: Commit the store foundation**

    git add src/recommendations/workspace/state.js src/recommendations/workspace/__tests__/state.test.js src/store/index.js src/store/__tests__/recommendation-workspace.test.js
    git commit -m "Add recommendation workspace state"

---

### Task 5: Mount one scope and focus lifecycle bootstrap

**Files:**

- Create: src/components/RecommendationWorkspaceBootstrap.js
- Create: src/components/__tests__/RecommendationWorkspaceBootstrap.test.js
- Create: src/recommendations/workspace/coordinator.js
- Modify: src/index.js
- Verify unchanged: src/components/ActivitySessionBootstrap.js
- Verify unchanged: all legacy recommendation panels

**Interfaces:**

    normalizeRecommendationFocusClientIds({
      focusScope,
      selectedClientIds,
      blockEditor
    })

    registerRecommendationGenerationController(requestKey, controller): {
      requestKey,
      controller
    }
    releaseRecommendationGenerationController(requestHandle)
    abortRecommendationGenerationRequests()

    RecommendationWorkspaceBootstrap()

- [ ] **Step 1: Write failing lifecycle tests**

Use injected store selectors/actions, Style Book subscription, page identity, UUID factory, and abort function. Cover:

- first valid scope creates one random workspace at revision 0;
- two independently initialized page/module harnesses with the same saved post receive different page/workspace IDs;
- regular post, custom post type, template, template part, Global Styles, Style Book, and temporary scopes;
- a null resolver gap aborts all requests and invalidates the old workspace before any late install;
- returning from null creates a fresh ID;
- temporary-to-canonical save creates a fresh ID;
- Style Book core/paragraph to core/heading changes scope, while subsection and blockTitle churn do not;
- selected_block keeps only the first live ID;
- selection keeps ordered first occurrences, discards missing IDs, and caps at 20;
- deselection replaces a non-empty focused configuration with [];
- document/site focus ignores selection churn;
- unmount aborts all active requests;
- registering returns a frozen handle, release requires the same composite key and controller object, and a late release for an aborted old-workspace handle cannot delete a new controller whose visible token is equal.

Run:

    npm run test:unit -- --runInBand src/components/__tests__/RecommendationWorkspaceBootstrap.test.js

Expected: fail because the component does not exist.

- [ ] **Step 2: Implement a primitive-only selector bridge**

Use existing helpers from src/utils/editor-entity-contracts.js, src/global-styles/selectors.js, and src/style-book/dom.js. Select bounded primitives from core/editor, core/edit-site, core/interface, and core/block-editor; do not retain entire store objects or make ActivitySessionBootstrap an owner.

Keep the controller map module-local and key it by the canonical `workspaceId + "\0" + requestToken` composite. Store the exact frozen handle. `releaseRecommendationGenerationController()` deletes only when the current map entry has the same request-key values and the same controller object; stale or repeated release is a no-op. `abortRecommendationGenerationRequests()` aborts every current controller and clears the map before invalidation, but late promise cleanup remains harmless because it still holds only its obsolete handle.

On a key change, execute in this order:

    abortRecommendationGenerationRequests()
    invalidate old workspace when necessary
    create fresh UUIDv4 workspace ID
    initializeRecommendationWorkspace({ workspaceId, editorScope })

During a null gap, stop after invalidation. Never preserve the old ID for later restoration.

Create coordinator.js with the page-lifetime controller Map and the three lifecycle functions above. Task 13 extends this same module with the internal `coordinateRecommendationRequest()` seam and no-actor `completeRecommendationRequest()` store wrapper; there is no second abort registry.

- [ ] **Step 3: Implement deterministic focus synchronization**

Dispatch synchronizeRecommendationFocus only when a configured workspace uses selected_block or selection and the normalized canonical ID array changes. Pass the current workspace ID/revision into the thunk; render/store notification churn must be a no-op.

- [ ] **Step 4: Mount once without adding UI**

Import RecommendationWorkspaceBootstrap in src/index.js and mount it immediately after ActivitySessionBootstrap and before the recommendation panels. It renders null. Do not touch the legacy panels or add a Get Recommendation control in Spec 1.

- [ ] **Step 5: Verify and commit**

Run:

    npm run test:unit -- --runInBand src/components/__tests__/RecommendationWorkspaceBootstrap.test.js src/store/__tests__/recommendation-workspace.test.js
    npm run lint:js

Then:

    git add src/components/RecommendationWorkspaceBootstrap.js src/components/__tests__/RecommendationWorkspaceBootstrap.test.js src/recommendations/workspace/coordinator.js src/index.js
    git commit -m "Bootstrap recommendation workspaces"

---

### Task 6: Build the deny-by-default client context collector

**Files:**

- Create: tests/fixtures/recommendation-protocol/context-cases.json
- Create: src/recommendations/context/collector.js
- Create: src/recommendations/context/__tests__/collector.test.js
- Reuse: src/context/block-inspector.js
- Reuse: src/context/theme-tokens.js
- Reuse: src/context/theme-settings.js
- Reuse: src/utils/visible-patterns.js
- Reuse: src/templates/template-recommender-helpers.js
- Reuse: src/template-parts/template-part-recommender-helpers.js
- Do not call directly: src/context/collector.js global select path

**Interfaces:**

    collectRecommendationWorkspaceContext({
      registry,
      editorScope,
      contextConfiguration,
      now
    })

    getRequestedClientSeamDefinitions(contextConfiguration, contract)
    collectClientSeam(definition, context)
    validateWorkspaceContextSnapshot(snapshot, contract)

- [ ] **Step 1: Add failing path-discriminated tests**

Assert the private collector map contains exactly these client paths and declared sources:

    document.identity                 flavor-agent/editor-scope
    document.summary                  core/editor
    document.edited_content           core/editor
    document.block_selection          core/block-editor
    document.block_tree               core/block-editor
    document.block_constraints        core/block-editor
    editor.block_registry             core/blocks
    editor.visible_patterns           core/block-editor
    theme.tokens                      core/block-editor
    theme.style_summary               core
    document.template_structure       core/block-editor
    document.navigation_structure     core/block-editor
    document.save_publication_state   core/editor

Test mandatory-profile union; the exact selected_block, selection, document, and site_editor_entity group additions; allowed additional groups; canonical seam order; source/path mismatch; duplicate paths; unavailable stores/selectors; every closed value schema and exact field regex/limit boundary; all cross-field cardinality/uniqueness/order rules from section 11; block/tree/catalog/token limits; 576 KiB canonical edited-content seam, 256 KiB every other client seam, total 768 KiB, and 1 MiB request; exact timestamp offset/millisecond/impossible-date/leap-second cases; and deep immutability. Assert `post_blocks` alone requests identity and save/publication state but not a live client tree, constraints, catalog, or theme tokens. Reject a focus/optional group that has no declared consumer among the requested surfaces.

- [ ] **Step 2: Implement a fixed collector dispatch table**

The caller supplies registry, scope, configuration, and time only. It cannot supply a path, store, selector, selector arguments, or value. Get requested paths from the manifest, resolve only paths with at least one requested-surface consumer, filter to owner client, and dispatch only through the private fixed map.

Map existing Gutenberg data into the approved semantic records:

- flatten block selection/tree into bounded BlockRecord entries;
- serialize each bounded block rather than exposing an open attributes map;
- flatten enabled allowlisted block supports into string paths;
- expose pattern identities without markup;
- flatten theme tokens into path/value/origin entries;
- flatten template/navigation structures into BlockRecord entries;
- expose current edited post content as format block_markup;
- expose save/publication booleans as preflight evidence only.

- [ ] **Step 3: Apply deterministic bounds and dispositions**

For included, summarized, or truncated observations require value. For unavailable require a bounded reasonCode and forbid value. The client never emits omitted or authoritative limit metadata. A summarized seam accepts only its manifest const strategy and requires sourceItemCount only for a collection-summary strategy; other dispositions forbid strategy. A truncated primary collection requires sourceItemCount at least the returned count; the server later adds the numeric limit from the seam definition. Included/unavailable forbid sourceItemCount. Truncate top-level trees only after stable depth-first order and top-level token/pattern lists only after manifest ordering. A reduced nested list/string/serialized field makes the complete seam summarized, never truncated, so final numeric limit metadata remains unambiguous. Record sourceItemCount before collection reduction.

If one source is absent, emit unavailable for that requested path. If the capture is structurally invalid or still exceeds the total cap after declared strategies, fail the complete capture as context_capture_invalid; do not send a partially unvalidated request.

- [ ] **Step 4: Verify collector isolation**

Add a test registry containing extra hostile stores/selectors and assert none are called. Assert client can* and lock fields never grant permission and no raw registry, pattern markup, arbitrary theme object, or DOM node survives.

Run:

    npm run test:unit -- --runInBand src/recommendations/context/__tests__/collector.test.js
    npm run lint:js

- [ ] **Step 5: Commit client capture**

    git add tests/fixtures/recommendation-protocol/context-cases.json src/recommendations/context/collector.js src/recommendations/context/__tests__/collector.test.js
    git commit -m "Collect bounded recommendation context"

---

### Task 7: Build the authoritative server context envelope, receipt, and signature

**Files:**

- Create: inc/Recommendations/Context/ClientCaptureValidator.php
- Create: inc/Recommendations/Context/WorkspaceDocsGroundingCollector.php
- Create: inc/Recommendations/Context/ServerSeamCollector.php
- Create: inc/Recommendations/Context/ContextEnvelopeBuilder.php
- Create: inc/Recommendations/Context/ContextSignature.php
- Create: inc/Support/TrustedDocsGroundingPolicy.php
- Modify: inc/Activity/Repository.php
- Modify: tests/phpunit/RecommendationContextTest.php
- Reuse: inc/Context/ServerCollector.php
- Reuse: inc/Support/CollectsDocsGuidance.php
- Reuse: inc/Support/DocsGuidanceResult.php
- Reuse: inc/Support/DocsGroundingSourcePolicy.php

**Interfaces:**

    ClientCaptureValidator::validate(
      array $configuration,
      array $editor_scope,
      mixed $capture
    ): array|\WP_Error

    WorkspaceDocsGroundingCollector::collect(
      array $configuration,
      array $normalized_context,
      array $consumer_surface_ids,
      \DateTimeImmutable $now
    ): array|\WP_Error

    TrustedDocsGroundingPolicy::normalize(
      array $item,
      \DateTimeImmutable $now
    ): array|\WP_Error

    ServerSeamCollector::collect(
      array $definition,
      array $configuration,
      array $editor_scope,
      \DateTimeImmutable $now
    ): array|\WP_Error

    ContextEnvelopeBuilder::build(
      array $configuration,
      array $editor_scope,
      array $capture,
      int $user_id,
      string $site_scope_id,
      \DateTimeImmutable $now
    ): array|\WP_Error

    ContextSignature::build(
      array $configuration,
      array $context,
      array $receipt,
      array $binding,
      string $editor_scope_key,
      string $collector_version,
      \DateTimeImmutable $captured_at
    ): array|\WP_Error

    Activity\Repository::query_recent_outcome_summaries(
      string $scope_key,
      int $user_id,
      int $limit
    ): array

- [ ] **Step 1: Write exact-partition and signature failures**

Extend shared context fixtures and PHPUnit cases for:

- requested-seam union from all surfaces, focus, additional groups, and mandatory validation seams;
- duplicate client paths and source mismatches;
- caller-supplied server paths discarded and counted, never trusted;
- unrequested client paths discarded and counted;
- included, summarized, truncated, omitted, and unavailable category precedence;
- category-exact final metadata, including a server-derived numeric limit with the manifest unit for every truncatable seam and no caller-supplied limit trust;
- exact one-entry partition in manifest order;
- canonical consumerSurfaceIds ordering;
- malformed or more-than-five-minute-skewed client capturedAt normalization;
- site/user binding, scope, configuration, context, disposition, and collector-version inclusion;
- exclusion of IDs, idempotency, actor session, timestamps, transient shell state, and never_expose values;
- context changes changing the digest while excluded fields do not.
- complete client capture rejection before any repository reservation call;
- every included value resolving to at least one fixed consumer surface/destination.
- one canonically ordered docs-grounding entry per docs consumer, distinct Global Styles/Style Book queries, and no content entry unless explicitly requested;
- one mixed docs fixture in which one surface is included, one is unavailable, and two are truncated from different source counts; assert one receipt path, exact canonical `surfaceDispositions`, top-level truncated count aggregation, matching context metadata, and null projection only for the unavailable surface;
- hard-path absence blocking only affected surfaces, and docs/recent-outcome absence continuing under the exact soft policy.

Run:

    vendor/bin/phpunit tests/phpunit/RecommendationContextTest.php

Expected: new receipt/signature tests fail.

- [ ] **Step 2: Add minimal safe server seam projections**

ServerSeamCollector maps only:

    document.server_identity
    server.pattern_catalog
    theme.server_tokens
    activity.recent_outcomes
    guidance.docs_grounding

Reuse existing server collectors but normalize into the approved closed values. Add the narrow activity query so it selects only activity ID, surface, execution outcome/reason, and created time; do not hydrate prompt, suggestion, request, before/after, document, or undo payloads.

Build one deterministic docs query per consumer surface from normalized intent plus that surface's normalized seam projection. Preserve each current Ability's WordPress topic/query intent in shared query-builder methods, including separate Global Styles and Style Book entries. Implement the manifest's exact TrustedDocsGroundingPolicy table: exact hosts only; the four stable developer.wordpress.org roots; dated Developer Blog, Make/Core, Make/AI, and WordPress News document regexes; sourceType derived from the matching row; strict HTTPS/user-info/port/query/fragment/trailing-dot/repeated-slash/decoded-separator/traversal handling; and the 180-day non-future timestamp rule for time-sensitive rows. Add hostile sibling, subdomain, trailing-dot, encoded traversal/separator, archive/tag/root, future, stale, and malformed-percent fixtures, plus an accepted mixed-case host that normalizes to lowercase. DocsGroundingSourcePolicy may label an already accepted item but is never the gate. Require a lowercase SHA-256 fingerprint. Retain only source ID, title, normalized URL, lastModified, a GuidanceExcerpt-compatible 360-character summary, and fingerprint; cap each list at 8 and order entries canonically. For every consumer, emit the exact included/truncated/unavailable metadata and closed docs reason from Spec section 10.3; when any consumer succeeds, retain all consumer entries so a mixed unavailable result still maps to null for only that Ability. Do not pass raw search responses or credentials into the envelope. Task 8 selects the matching usable entry through workspaceContext and suppresses each Ability's legacy second lookup. Docs grounding is mandatory-capture-but-soft for the seven existing docs-consuming Ability implementations, representing eight wire surfaces because style has two scopes; content consumes it only when explicitly requested. Transport failure, an empty result, or all candidates being filtered emits the matching unavailable per-surface metadata and null docs for that surface, then continues recommendation execution. If all surfaces are unavailable, omit the docs context value and preserve their evidence in the one seam receipt.

- [ ] **Step 3: Implement envelope and receipt construction**

Implement ClientCaptureValidator as a complete, side-effect-free pre-reservation pass over collector/scope binding, unique paths, source/path pairs, dispositions, timestamps, discriminated value schemas, exact field/array/byte caps, and requested/extra-path policy. It returns a normalized capture only; every `context_capture_invalid` originates here and performs zero repository or Ability writes.

ContextEnvelopeBuilder accepts only that validated capture. Compute requested paths, consumerSurfaceIds, and the manifest-expanded hardContextPaths server-side. Once a building lease exists, server-source absence becomes a receipt disposition and follows the exact per-surface hard/soft policy; it is never returned as `context_capture_invalid`. Hard absence blocks only affected surfaces. Docs and recent outcomes are soft and use their declared null/omission projection. For every requested path select exactly one category in this order:

    unavailable
    omitted
    truncated
    summarized
    included

For final receipt metadata, summarized requires only the manifest strategy (plus sourceItemCount for a collection summary), truncated requires server-derived numeric limit plus sourceItemCount and forbids strategy, omitted/unavailable require reasonCode, and included forbids generic category-only fields. Assert the exact limit/unit table from Spec section 10.4. Special-case only the multiplexed docs seam as that section defines: always one canonically ordered `surfaceDispositions` array; all unavailable maps to one unavailable path with no context value; any truncated maps to one truncated path with limit 8 and the safe-integer aggregate source count; otherwise at least one included maps to one included path even if another surface is unavailable. Require any present `bySurface` metadata to match the receipt exactly. Return an ephemeral structure containing normalized context, public receipt, server capturedAt, per-surface unavailable reasons, and bounded discarded-observation diagnostics. Enforce 2 MiB per server seam and 4 MiB for the complete normalized envelope before Ability invocation. Never return unrestricted context from REST and never write it to the run row.

- [ ] **Step 4: Compute the server signature**

Derive siteScopeId from the decimal current blog ID already captured by the repository/storage context. Construct exactly the signature input from Spec 1, serialize with CanonicalJson, and return only algorithm, collectorVersion, lowercase digest, and server capturedAt publicly.

The native per-surface review/resolved/target signatures remain authoritative. The shared signature does not replace them.

- [ ] **Step 5: Verify privacy and commit**

Run:

    vendor/bin/phpunit tests/phpunit/RecommendationContextTest.php
    composer run lint:php

Then:

    git add inc/Recommendations/Context/ClientCaptureValidator.php inc/Recommendations/Context/WorkspaceDocsGroundingCollector.php inc/Recommendations/Context/ServerSeamCollector.php inc/Recommendations/Context/ContextEnvelopeBuilder.php inc/Recommendations/Context/ContextSignature.php inc/Support/TrustedDocsGroundingPolicy.php inc/Activity/Repository.php tests/phpunit/RecommendationContextTest.php tests/fixtures/recommendation-protocol/context-cases.json
    git commit -m "Build recommendation context envelopes"

---

### Task 8: Map nine surfaces to eight registered Abilities and adapt results

**Files:**

- Create: tests/fixtures/recommendation-protocol/run-cases.json
- Create: inc/Recommendations/Runs/SurfaceRegistry.php
- Create: inc/Recommendations/Runs/SurfaceInputAdapter.php
- Create: inc/Recommendations/Runs/AbilityInvoker.php
- Create: inc/Recommendations/Runs/AbilityInvocationGuardException.php
- Create: inc/Recommendations/Runs/SurfaceResultAdapter.php
- Create: inc/Support/ClosedJsonSchemaValidator.php
- Create: inc/Support/WorkspaceContextGuidance.php
- Modify: inc/Abilities/Registration.php
- Modify: inc/Abilities/RecommendationAbilityExecution.php
- Modify: inc/Abilities/BlockAbilities.php
- Modify: inc/Abilities/ContentAbilities.php
- Modify: inc/Abilities/PatternAbilities.php
- Modify: inc/Abilities/NavigationAbilities.php
- Modify: inc/Abilities/TemplateAbilities.php
- Modify: inc/Abilities/StyleAbilities.php
- Modify: inc/Abilities/PostBlocksAbilities.php
- Modify: inc/AI/Abilities/PreviewRecommendationAbility.php
- Modify: tests/phpunit/bootstrap.php
- Create: tests/phpunit/RecommendationRunServiceTest.php
- Modify: tests/phpunit/AbilitySchemaContractTest.php
- Modify: tests/phpunit/RegistrationTest.php
- Modify: tests/phpunit/PreviewRecommendationAbilityTest.php
- Modify: tests/phpunit/BlockAbilitiesTest.php
- Modify: tests/phpunit/ContentAbilitiesTest.php
- Modify: tests/phpunit/PatternAbilitiesTest.php
- Modify: tests/phpunit/NavigationAbilitiesTest.php
- Modify: tests/phpunit/TemplateAbilitiesTest.php
- Modify: tests/phpunit/StyleAbilitiesTest.php
- Modify: tests/phpunit/PostBlocksAbilitiesTest.php

**Interfaces:**

    SurfaceRegistry::definitions(): array|\WP_Error
    SurfaceRegistry::normalize_requested(array $surface_ids): array|\WP_Error
    SurfaceRegistry::definition(string $surface_id): array|\WP_Error

    SurfaceInputAdapter::adapt(
      string $surface_id,
      array $configuration,
      array $context,
      array $editor_scope,
      string $run_id,
      string $actor_session_id,
      int $base_revision
    ): array|\WP_Error

    SurfaceInputAdapter::authorization_projection(
      string $surface_id,
      array $ability_input,
      array $editor_scope
    ): array|\WP_Error
    SurfaceInputAdapter::retained_authorization_input(
      string $surface_id,
      array $ability_input,
      array $editor_scope
    ): array|\WP_Error

    AbilityInvoker::invoke(
      string $surface_id,
      array $adapted_input,
      array $editor_scope
    ): array{nativeOutput: mixed, retainedAuthorizationInput: array, retainedAuthorizationInputDigest: string}|\WP_Error
    AbilityInvoker::reauthorize(
      string $surface_id,
      array $retained_authorization_input,
      array $editor_scope
    ): true|\WP_Error

    SurfaceResultAdapter::adapt(
      string $run_id,
      string $surface_id,
      string $ability_id,
      array $retained_authorization_input,
      mixed $native_output,
      array $remaining_budget
    ): array|\WP_Error

    SurfaceResultAdapter::result_ref(string $run_id, string $surface_id): string
    SurfaceResultAdapter::unit_id(
      string $result_ref,
      string $native_stable_key,
      int $ordinal
    ): string

    ClosedJsonSchemaValidator::validate(
      mixed $value,
      array $schema,
      string $path = '$'
    ): true|\WP_Error

    WorkspaceContextGuidance::from_input(array $input): array|\WP_Error
    WorkspaceContextGuidance::prompt_suffix(
      array $state,
      bool $include_docs = false
    ): string
    WorkspaceContextGuidance::resolve_docs_result(
      array $state,
      ?callable $legacy_collector,
      bool $signature_only
    ): array

- [ ] **Step 1: Add a faithful WP_Ability test seam**

Extend tests/phpunit/bootstrap.php without breaking existing registration-array assertions. Add a faithful test WP_Ability object and wp_get_ability() registry that expose normalize_input(), validate_input(), check_permissions(), and execute() in WordPress 7.1 order, including scoped `wp_ability_normalize_input`, `wp_ability_validate_input`, `wp_pre_execute_ability`, and `wp_before_execute_ability` behavior, counters, and injected errors. Add reusable REST-schema validation stubs. Reset all new registries, hooks, and counters between tests. Add schema-corpus helpers that walk every live input/output schema for the eight recommendation Abilities and report every encountered validation or annotation keyword.

- [ ] **Step 2: Write failing registry and ordering tests**

Assert exact mapping:

| Wire surface | Ability ID | Required discriminator |
| --- | --- | --- |
| block | flavor-agent/recommend-block | focused/selected block |
| content | flavor-agent/recommend-content | content.mode |
| pattern | flavor-agent/recommend-patterns | ranking context |
| navigation | flavor-agent/recommend-navigation | navigation scope |
| template | flavor-agent/recommend-template | exact template |
| template_part | flavor-agent/recommend-template-part | exact template part |
| post_blocks | flavor-agent/recommend-post-blocks | exact post/page |
| global_styles | flavor-agent/recommend-style | Global Styles root |
| style_book | flavor-agent/recommend-style | exact Style Book block |

Assert one-to-nine unique input surfaces normalize to canonical order, post_blocks is generating, and requesting both style surfaces executes style twice with distinct scopes. Pin the exact Spec section 12.2 protected-projection table in the manifest and adapter: all post-ID aliases consumed by `RecommendationAbility::permission_callback`, navigation `menuId`, template/template-part refs, both style scope shapes, fixed surface, authoritative editor scope, and `clientRequest.scopeKey`. The retention projector returns only closed `{ surfaceId, editorScope, abilityInput }`; `abilityInput` is the exact normalized adapter output after validation against that surface's separate recursively closed retained-input schema, permits no additional properties at any level, and keeps the complete wrapper within 1 MiB canonical JSON. Reject conflicting post aliases or any protected projection that differs from the server identity before permission or execution.

- [ ] **Step 3: Implement closed per-surface input adapters**

Build the existing registered input fields plus the new closed optional workspaceContext compatibility field:

- block: selected BlockRecord, bounded editorContext, clientId, prompt, document, clientRequest;
- content: mode, bounded postContext summary/edited content, prompt, optional normalized voice guidance, document, clientRequest;
- pattern: post type, bounded block/insertion/template context, visible pattern names, prompt, document, clientRequest;
- navigation: resolved navigation ID/markup/context and selected client ID, prompt, document, clientRequest;
- template/template_part: exact ref, bounded structure/slots/pattern names/design semantics, prompt, document, clientRequest;
- post_blocks: exact persistent post ID, prompt, requestReference, document, clientRequest, and only permitted outcome/docs workspace context; never a client block tree;
- global_styles/style_book: exact closed scope and bounded styleContext, prompt, document, clientRequest.

Materialize one closed retained-input schema per wire surface from exactly those adapter fields plus the optional closed workspaceContext. Replace every intentionally open registered-schema object with the bounded server-built shape used by the adapter; do not copy `additionalProperties: true` into the retention schema. A normalized value with any undeclared root or nested field fails rather than being stripped or retained.

Construct workspaceContext exactly as Spec 1 section 10.3.1. Native fields consume their mapped seams; remaining allowed structural values are deterministically rendered to semanticSummary; activity becomes recentOutcomeSummary; the matching per-surface docs entry becomes docsGrounding. Reject an included seam with no declared destination. Bound the final derived prompt to 5,000 code points without truncating normalized intent. Set clientRequest.sessionId to actorSessionId, abortId to runId, scopeKey to editorScope.key, and requestToken to base revision. Validate target identity before any permission callback. A temporary scope cannot create a persistent executor binding.

For post_blocks, inspect the captured save/publication state immediately before invocation. If dirty, saving, autosaving, not saveable, absent, or malformed, emit surface unavailable with reasonCode unsaved_editor_content and call neither permission nor execute. The Ability continues to use its own fresh saved-server context and native resolved/review signatures.

- [ ] **Step 4: Implement fail-closed schema and Ability invocation**

Implement the complete closed manifest vocabulary from Spec 1 section 6: local $ref/$defs; string or union-array type; required/properties; boolean-or-schema additionalProperties; items and array constraints; string constraints and the three formats; numeric constraints; enum/const; anyOf/oneOf/allOf/not; and the declared annotation-only keywords. Unsupported protocol keywords fail manifest validation. Keep a distinct WordPress draft-04 Ability-schema mode: workspaceContext uses singleton enum rather than const, and existing object openness remains where registered schemas allow it.

Enumerate every live input/output schema for the eight recommendation Abilities. Tests fail if any encountered keyword is neither validated nor explicitly annotation-only. Add discriminating valid/invalid fixtures for union-valued type and the current anyOf schema, plus parity assertions against rest_validate_value_from_schema for every real registered schema. Never silently accept a branch because an unsupported keyword was ignored.

Resolve only the registry Ability ID and keep these values distinct:

    adaptedInput = SurfaceInputAdapter output
    preflightInput = ability.normalize_input(adaptedInput)
    expectedAuthorizationProjection = adapter projection from authoritative scope
    preflightAuthorizationProjection = projection from preflightInput

Reject a normalization error, registered-schema or closed-retention-schema failure, conflicting target alias, retained wrapper over 1 MiB canonical JSON, or projection mismatch before permission; a protected target change is bounded `ability_input_target_changed` and never reaches the recommendation callback. Validate `preflightInput` independently against get_input_schema() and the surface's closed retained schema, then call the Ability's validate_input() and check_permissions() on that exact value. Permission denial is unavailable; normalization/schema/projection errors are failed.

Immediately around execute(), install a final-priority active-invocation-token/name-scoped `wp_ability_validate_input` gate and a `PHP_INT_MIN` exact-object/name-scoped `wp_before_execute_ability` witness, and remove both in `finally`. Call execute() with `adaptedInput`, not the already-normalized value. The validation gate preserves an incoming WP_Error. Otherwise it sees the final execution-pass normalized input after Core schema validation, validates the complete value against the surface's closed retained schema, wraps it as `retainedAuthorizationInput`, enforces canonical serializability and the 1 MiB cap, and returns WP_Error if its protected projection differs from preflight/server authority. This gate is independent of normalizer priority; test a target-changing normalizer registered after invocation setup and prove neither permission nor recommendation callback runs.

The witness recomputes the closed retained value from Core's exact normalized input after authoritative permission, requires a canonical byte match with the gate candidate, and calls check_permissions() once with the byte-equivalent `retainedAuthorizationInput.abilityInput`. A mismatch or non-true result throws a private no-payload invocation-guard exception, caught by the invoker before Core reaches do_execute(). Accept a successful output only when exactly one witness completes. A schema-valid `wp_pre_execute_ability` return or any other apparently successful bypass with no witness becomes failed `ability_execution_short_circuited`; it creates no ready binding. Independently validate the accepted result against get_output_schema() because WordPress output-validation filters may override the built-in verdict. Strip provider bodies, hook/exception details, and stack traces.

Digest and pass only the witnessed `retainedAuthorizationInput` to SurfaceResultAdapter. `reauthorize()` verifies its digest, surface-specific closed schema, 1 MiB cap, and protected projection against the retained surface/editor scope, then calls check_permissions(retainedAuthorizationInput.abilityInput) only. It never calls normalize_input(), execute(), or the recommendation callback. Tests prove target validation occurs before any permission callback, the execution witness follows Core's authoritative permission check, every permission callback/filter receives byte-equivalent input on later reauthorization, an injected undeclared secret/grant flag fails before permission/callback, and temporary hooks are removed on every exit.

- [ ] **Step 5: Make all eight Abilities consume the signed projection**

Add optional workspaceContext to all eight registered input schemas without changing Ability IDs or count. Keep explicit `contextProvided` and `docsProvided` state so absent context, present docs, explicit null, and omitted docs cannot collapse together. RecommendationAbilityExecution retains workspaceContext for execution but strips its summaries/items from persisted activity requestContext, retaining only schema version, counts, and query/content fingerprints. The six derived preview Ability schemas explicitly unset workspaceContext and their tests prove it is not advertised.

In each domain Ability, append semanticSummary and recentOutcomeSummary to the generated model user context rather than the legacy `$prompt` field used by native resolved signatures. Presence of workspaceContext always suppresses CollectsDocsGuidance: map sourceId to id/sourceKey, summary to excerpt, fingerprint to contentHash, lastModified to publishedAt, preserve URL/title, derive the accepted sourceType, create DocsGuidanceResult::from_guidance(), and use no docs when absent or null. Retain the legacy collector only when workspaceContext itself is absent. Content consumes the summaries/docs in its prompt but does not add native output attribution fields in Spec 1.

Use distinct marker fixtures per seam destination to prove the model/prompt context or native adapter input changes without altering native resolved-signature prompt inputs. Use docs collector counters to prove exactly zero secondary lookups for run-based calls and one legacy lookup for direct calls. For each of the eight mandatory docs-consuming wire surfaces, plus content when docs are explicitly requested, prove docs transport failure, empty results, and all-untrusted results produce `docsGrounding: null`, retain unavailable receipt evidence, and still invoke the Ability once. Reuse the mixed docs fixture to prove the included and differently truncated entries reach only their matching Abilities, the unavailable entry alone becomes null, and all four invocations still occur under the single receipt path. Prove activity diagnostics contain no summaries/docs items, previews omit workspaceContext, post_blocks never receives the client tree, and post_blocks never executes while the captured editor is dirty.

- [ ] **Step 6: Implement public/private result adaptation**

Use the approved native-to-unit rules in canonical order. Bound title, summary, warnings, units, native output, and cumulative public/private sizes before hashing. Produce:

    {
      publicResult,
      privateBinding,
      publicBytes,
      privateBytes,
      unitCount
    }

PrivateBinding contains resultRef, source surface, Ability ID, the witnessed `retainedAuthorizationInput` digest, the closed at-most-1-MiB exact normalized retained value, validated native result, unit mapping, existing signatures/target evidence, and binding schema version. It contains neither preflightInput nor undeclared normalizer fields, credential/provider fields, or unrestricted combined context. PublicResult contains no operations, pattern markup, target evidence, or native payload. Independently enforce all public record/error/freshness/cardinality bounds from Spec section 11; the wider/open native Ability schema is never treated as the closed retention or protocol schema.

Derive IDs exactly:

    resultRef =
      'rr_' + first32hex(
        sha256('result-ref-v1\0' + runId + '\0' + surfaceId)
      )

    unitId =
      'ru_' + first32hex(
        sha256(
          'unit-id-v1\0' + resultRef + '\0' +
          nativeStableKey + '\0' + ordinal
        )
      )

Emit only advisory or stage_only in Spec 1. Mutation-shaped block, pattern, template, template-part, post-blocks, and style suggestions are stage_only; explanation-only results may be advisory. Emit no governed_apply and no executionBinding. Retain bounded native evidence privately for Spec 3.

- [ ] **Step 7: Verify every surface and commit**

At least one test per wire surface must use the real Flavor Agent registration/output path. Cover required resultRef even for failed/unavailable, exact contextPaths, warning/error unions, freshness signatures, per-surface operationCount caps, zero-suggestion ready, ready/partial/failed derivation inputs, native/public/private size failures, 25-unit surface cap, 100-unit run budget accounting, deterministic later-surface handling, all seam consumer destinations, precollected-docs suppression, dirty post_blocks unavailability, and zero Spec 1 governed_apply units.

Add discriminating execution-lifecycle cases: a preflight normalizer changes a post target; a stateful normalizer changes it only on the execute pass; a target-changing normalizer registered after invocation setup is caught by the validation gate before permission; a harmless change to a declared non-protected field succeeds and the exact closed witnessed value/digest is retained; an injected undeclared secret or permission-grant flag fails before permission/callback and is absent from storage/reauthorization; a `wp_ability_permission_result` rule that requires a declared retained field observes the byte-equivalent field/value during witness and later reauthorization; oversize/non-canonical input, normalization, or validation failure invokes neither permission nor callback; and `wp_pre_execute_ability` returns a schema-valid native result but produces no witness, so the surface fails with `ability_execution_short_circuited`. Assert zero ready binding for every rejected case and no leaked temporary hook in the following test.

Shared surface-error fixtures pin `ability_input_target_changed` to `validation`/`refresh_context` and `ability_execution_short_circuited` to `recovery`/`retry_same`; each uses the same bounded reason code, the containing surface ID, and no hook, provider, input, credential, or exception detail.

Run:

    vendor/bin/phpunit tests/phpunit/RecommendationRunServiceTest.php tests/phpunit/RegistrationTest.php tests/phpunit/AbilitySchemaContractTest.php tests/phpunit/PreviewRecommendationAbilityTest.php tests/phpunit/BlockAbilitiesTest.php tests/phpunit/ContentAbilitiesTest.php tests/phpunit/PatternAbilitiesTest.php tests/phpunit/NavigationAbilitiesTest.php tests/phpunit/TemplateAbilitiesTest.php tests/phpunit/StyleAbilitiesTest.php tests/phpunit/PostBlocksAbilitiesTest.php
    composer run lint:php

Then:

    git add tests/fixtures/recommendation-protocol/run-cases.json inc/Recommendations/Runs/SurfaceRegistry.php inc/Recommendations/Runs/SurfaceInputAdapter.php inc/Recommendations/Runs/AbilityInvoker.php inc/Recommendations/Runs/AbilityInvocationGuardException.php inc/Recommendations/Runs/SurfaceResultAdapter.php inc/Support/ClosedJsonSchemaValidator.php inc/Support/WorkspaceContextGuidance.php inc/Abilities/Registration.php inc/Abilities/RecommendationAbilityExecution.php inc/Abilities/BlockAbilities.php inc/Abilities/ContentAbilities.php inc/Abilities/PatternAbilities.php inc/Abilities/NavigationAbilities.php inc/Abilities/TemplateAbilities.php inc/Abilities/StyleAbilities.php inc/Abilities/PostBlocksAbilities.php inc/AI/Abilities/PreviewRecommendationAbility.php tests/phpunit/bootstrap.php tests/phpunit/RecommendationRunServiceTest.php tests/phpunit/AbilitySchemaContractTest.php tests/phpunit/RegistrationTest.php tests/phpunit/PreviewRecommendationAbilityTest.php tests/phpunit/BlockAbilitiesTest.php tests/phpunit/ContentAbilitiesTest.php tests/phpunit/PatternAbilitiesTest.php tests/phpunit/NavigationAbilitiesTest.php tests/phpunit/TemplateAbilitiesTest.php tests/phpunit/StyleAbilitiesTest.php tests/phpunit/PostBlocksAbilitiesTest.php
    git commit -m "Adapt recommendation run surfaces"

---

### Task 9: Install the dedicated run table and immutable storage context

**Files:**

- Create: inc/Recommendations/Runs/RecommendationRunStorageContext.php
- Create: inc/Recommendations/Runs/RecommendationRunRepository.php
- Create: tests/phpunit/RecommendationRunRepositoryTest.php
- Create: tests/phpunit/RecommendationRunLifecycleTest.php
- Modify: tests/phpunit/bootstrap.php
- Modify: flavor-agent.php
- Modify: inc/UninstallOptions.php
- Modify: uninstall.php
- Modify: tests/phpunit/PluginLifecycleTest.php
- Modify: tests/phpunit/UninstallTest.php

**Lifecycle constants:**

    SCHEMA_OPTION = flavor_agent_recommendation_run_schema_version
    SCHEMA_VERSION = 1
    PRUNE_CRON_HOOK = flavor_agent_prune_recommendation_runs
    TABLE_SUFFIX = flavor_agent_recommendation_runs
    LEASE_SECONDS = 600
    ACTIVE_TTL_SECONDS = 1800
    TOMBSTONE_TTL_SECONDS = 86400
    ABANDONED_BUILDING_SECONDS = 86400
    PRUNE_BATCH_SIZE = 100

**Interfaces:**

    RecommendationRunRepository::install(): void
    RecommendationRunRepository::maybe_install(): void
    RecommendationRunRepository::ensure_prune_schedule(): void
    RecommendationRunRepository::prune_current_site(): int
    RecommendationRunRepository::capture_storage_context():
      RecommendationRunStorageContext|\WP_Error
    RecommendationRunRepository::for_context(
      RecommendationRunStorageContext $context
    ): RecommendationRunRepository

    RecommendationRunStorageContext::database(): object
    RecommendationRunStorageContext::table_name(): string
    RecommendationRunStorageContext::prefix(): string
    RecommendationRunStorageContext::blog_id(): int
    RecommendationRunStorageContext::options_table(): string
    RecommendationRunStorageContext::site_url(): string
    RecommendationRunStorageContext::matches_current(): bool

- [ ] **Step 1: Write failing schema and storage-owner tests**

Assert install/maybe-install idempotency, missing-table repair, non-autoloaded schema option, exact columns/indexes, table prefix, and a captured database/blog/table/options owner that remains fixed across an ambient switch_to_blog(). Extend the fake wpdb only for the SQL and row behavior these tests require.

Run:

    vendor/bin/phpunit tests/phpunit/RecommendationRunRepositoryTest.php tests/phpunit/RecommendationRunLifecycleTest.php

Expected: fail because the repository classes/table do not exist.

- [ ] **Step 2: Implement the exact initial DDL**

Use dbDelta() with:

    id bigint(20) unsigned NOT NULL AUTO_INCREMENT
    run_id varchar(64) NOT NULL
    workspace_id varchar(64) NOT NULL
    user_id bigint(20) unsigned NOT NULL DEFAULT 0
    protocol_version varchar(16) NOT NULL
    editor_scope_key varchar(191) NOT NULL
    base_workspace_revision bigint(20) unsigned NOT NULL DEFAULT 0
    context_configuration_digest char(64) NULL
    context_signature char(64) NULL
    idempotency_scope_digest char(64) NOT NULL
    generation_binding_digest char(64) NOT NULL
    storage_state varchar(24) NOT NULL
    wire_status varchar(16) NULL
    lease_token_hash char(64) NULL
    lease_expires_at datetime NULL
    public_payload_json longtext NULL
    private_binding_json longtext NULL
    public_payload_digest char(64) NULL
    private_binding_digest char(64) NULL
    created_at datetime NOT NULL
    completed_at datetime NULL
    expires_at datetime NULL
    tombstone_until datetime NULL
    updated_at datetime NOT NULL

Indexes are primary id; unique run_id; unique idempotency_scope_digest; owner_workspace(user_id, workspace_id); state_lease(storage_state, lease_expires_at); state_expiry(storage_state, expires_at); and state_tombstone(storage_state, tombstone_until).

- [ ] **Step 3: Implement immutable storage ownership**

Capture the current wpdb object, prefix, run table, options table, blog ID, and site URL once. Every multi-step repository operation uses the captured object/table even if a hook changes the ambient blog. siteScopeId is the decimal captured blog ID; the URL is diagnostic only and never hashed as site identity.

- [ ] **Step 4: Wire activation, upgrade, cron, deactivation, and uninstall**

In flavor-agent.php:

- activation installs the table and ensures the daily schedule;
- init priority 5 calls maybe_install;
- init priority 6 calls ensure_prune_schedule;
- the prune hook calls static prune_current_site(), which captures one storage context/clock value and delegates to the instance prune method;
- deactivation clears only the schedule and retains rows.

In uninstall.php, use literal hook/table/option names so cleanup remains vendor-less safe. Clear the hook, drop each current-site run table using the established plugin uninstall pattern, and delete the schema option. Add the option to UninstallOptions. State in the later readme update that uninstall removes retained runs/tombstones.

- [ ] **Step 5: Verify lifecycle and commit**

Run:

    vendor/bin/phpunit tests/phpunit/RecommendationRunRepositoryTest.php tests/phpunit/RecommendationRunLifecycleTest.php tests/phpunit/PluginLifecycleTest.php tests/phpunit/UninstallTest.php
    composer run lint:php

Then:

    git add inc/Recommendations/Runs/RecommendationRunStorageContext.php inc/Recommendations/Runs/RecommendationRunRepository.php tests/phpunit/RecommendationRunRepositoryTest.php tests/phpunit/RecommendationRunLifecycleTest.php tests/phpunit/bootstrap.php flavor-agent.php inc/UninstallOptions.php uninstall.php tests/phpunit/PluginLifecycleTest.php tests/phpunit/UninstallTest.php
    git commit -m "Install recommendation run storage"

---

### Task 10: Implement reservation races, leases, immutable finalization, expiry, and prune

**Files:**

- Modify: inc/Recommendations/Runs/RecommendationRunRepository.php
- Create: inc/Recommendations/Runs/RunAvailabilityProjector.php
- Modify: tests/phpunit/RecommendationRunRepositoryTest.php
- Modify: tests/phpunit/RecommendationRunLifecycleTest.php
- Modify: tests/phpunit/bootstrap.php

**Repository interfaces:**

    reserve(array $reservation, \DateTimeImmutable $now): array|\WP_Error
    renew_lease(
      string $run_id,
      string $lease_token,
      \DateTimeImmutable $now
    ): true|\WP_Error
    store_context_signature(
      string $run_id,
      string $lease_token,
      string $context_signature,
      \DateTimeImmutable $now
    ): true|\WP_Error
    finalize(
      string $run_id,
      string $lease_token,
      array $public_payload,
      array $private_binding,
      \DateTimeImmutable $now
    ): array|\WP_Error
    find_by_run_id(string $run_id): array|null|\WP_Error
    find_by_idempotency_scope_digest(string $digest): array|null|\WP_Error
    prune(?\DateTimeImmutable $now = null): int

    RunAvailabilityProjector::project(
      array $row,
      \DateTimeImmutable $now
    ): array|\WP_Error

- [ ] **Step 1: Build an explicit interleaving storage fake**

The current generic wpdb fake does not enforce unique indexes. Extend the focused run-storage fake with unique run/idempotency keys, conditional update predicates, affected-row counts, write counters, and hooks that pause after read/before insert or before update. Use those hooks to model two reservations and two finalizers, not merely sequential calls.

- [ ] **Step 2: Write failing idempotency and lease tests**

Compute:

    idempotencyScopeDigest = sha256(JCS({
      siteScopeId,
      userId,
      workspaceId,
      idempotencyKey
    }))

    generationBindingDigest = sha256(JCS({
      protocolVersion,
      siteScopeId,
      userId,
      workspaceId,
      expectedWorkspaceRevision,
      contextConfigurationDigest,
      idempotencyKey
    }))

Assert raw key is never stored; actorSessionId and live capture are excluded; same active terminal binding deduplicates; changed configuration/revision/scope conflicts before Ability work; active building lease returns generation_in_progress; stale lease takeover replaces the fence under CAS; old token cannot renew/write/finalize; an insert race rereads the unique winner. Exercise terminal and physical-tombstone rows immediately before, exactly at, and after tombstoneUntil: the expiry window returns run_expired, while the deadline and later return run_not_found even when prune has not deleted the row.

- [ ] **Step 3: Implement reservation and lease fencing**

Generate run UUIDv4 and a 32-byte random lease token server-side; store only SHA-256 of the token. reserve() accepts a prevalidated allowlist matching storage columns and returns one closed state:

    { state: 'acquired', runId, leaseToken, deduplicated: false, row }
    { state: 'deduplicated', runId, deduplicated: true, row }

Expected busy/expired/conflict cases return protocol WP_Error. Every takeover, renewal, context-signature write, and finalize is one conditional update matching run ID, building state, and current lease hash.

- [ ] **Step 4: Implement one-time terminal finalization**

Before SQL, require non-empty context signature, exact requested-result completeness, terminal wire status, bounded public/private canonical bytes, and matching digests. The update writes public/private JSON, both digests, wire status, completed_at, expires_at, tombstone_until, and terminal state together:

    WHERE run_id = ?
      AND storage_state = 'building'
      AND lease_token_hash = ?

Exactly one affected row is success. Zero/multiple rows is run_finalization_conflict. Terminal payloads and binding metadata never update again.

- [ ] **Step 5: Implement pure availability boundaries**

Test before, exactly at, and after both deadlines:

    building                         -> generation_in_progress
    terminal and now < expires_at   -> active public run
    expires_at <= now < tombstone   -> expired metadata
    now >= tombstone_until          -> not found

RunAvailabilityProjector has no repository/database dependency and performs zero writes.

- [ ] **Step 6: Implement bounded idempotent prune**

In one captured-now batch:

1. delete building rows older than 24 hours;
2. convert expired terminal rows to tombstone;
3. delete elapsed tombstones.

Tombstone conversion retains only internal ID, run/workspace/owner/protocol/scope/base revision, idempotency and generation digests, immutable wire status, and lifecycle deadlines. It nulls configuration/context digests, lease fields, both payloads, and both payload digests. Use at most 100 rows per phase; a failed phase leaves remaining rows for the next call.

- [ ] **Step 7: Verify races, no-write reads, and commit**

Run:

    vendor/bin/phpunit tests/phpunit/RecommendationRunRepositoryTest.php tests/phpunit/RecommendationRunLifecycleTest.php
    composer run lint:php

Then:

    git add inc/Recommendations/Runs/RecommendationRunRepository.php inc/Recommendations/Runs/RunAvailabilityProjector.php tests/phpunit/RecommendationRunRepositoryTest.php tests/phpunit/RecommendationRunLifecycleTest.php tests/phpunit/bootstrap.php
    git commit -m "Fence recommendation run finalization"

---

### Task 11: Orchestrate terminal run creation and authorized reads

**Files:**

- Create: inc/Recommendations/Runs/RecommendationRunService.php
- Modify: tests/phpunit/RecommendationRunServiceTest.php

**Interfaces:**

    RecommendationRunService::create_default(): RecommendationRunService

    RecommendationRunService::__construct(
      RecommendationRunRepository $repository,
      ClientCaptureValidator $capture_validator,
      ContextEnvelopeBuilder $context_builder,
      ContextSignature $context_signature,
      SurfaceRegistry $surface_registry,
      SurfaceInputAdapter $input_adapter,
      AbilityInvoker $ability_invoker,
      SurfaceResultAdapter $result_adapter,
      RunAvailabilityProjector $availability_projector,
      \Closure $clock,
      \Closure $current_user_id
    )

    RecommendationRunService::create(array $request): array|\WP_Error
    RecommendationRunService::read(string $run_id): array|\WP_Error

- [ ] **Step 1: Write the failure-order orchestration test**

Pin this exact order with spies:

    validate closed request/version/UUIDs/limits/scope
    resolve authenticated site and user
    normalize configuration and scope binding
    validate the complete caller capture with zero side effects
    compute idempotency and generation binding
    resolve dedupe/expiry/conflict; dedupe calls the full read authorization path
    acquire fenced building lease
    build context/receipt/signature
    renew before each surface
    validate identity, authorize, execute, adapt
    renew after each surface
    validate complete payloads and digests
    finalize once under the fence
    return public run only

Errors before reservation invoke zero Ability work and create no row. Assert malformed paths, duplicate paths, wrong sources, bad dispositions/timestamps, schema errors, and per-seam/cumulative overflow all return context_capture_invalid before repository reserve(). After reservation, server-source absence becomes receipt metadata and follows the manifest's exact hard/soft policy rather than context_capture_invalid; docs/recent-outcome absence remains soft. Surface errors produce entries and do not erase other surfaces. Losing the lease prevents terminal claims.

- [ ] **Step 2: Implement request normalization and reservation**

Accept only protocolVersion, workspaceId, expectedWorkspaceRevision, actorSessionId, editorScope, contextConfiguration, contextCapture, and idempotencyKey. Derive current user and captured siteScopeId; reject request-supplied authority fields. Normalize and completely validate contextCapture before calling reserve(). Generate run ID on first reservation and use actor session only for diagnostics.

For an active terminal dedupe, call the exact same authorize_run_read() path used by read(): captured-site context, owner, current scope authorization, payload digests, availability, and per-ready witnessed retained-authorization-input reauthorization. Then return the existing run without recapturing server context. For a terminal or physical-tombstone dedupe, use the same owner/scope/availability path: `expiresAt <= now < tombstoneUntil` returns run_expired, while `now >= tombstoneUntil` returns run_not_found despite physical prune lag. A new result requires a fresh idempotency key.

- [ ] **Step 3: Orchestrate context and surfaces**

Store the context signature only while holding the lease. Iterate canonical requested surfaces. Renew immediately before and after every Ability. Enforce per-surface freshness predicates before permission, including post_blocks clean/saveable state. Emit exactly one result per surface:

- prevented before execute -> unavailable;
- attempted failure -> failed;
- valid zero-unit success -> ready.

Derive run status ready when all are ready, partial when ready and non-ready coexist, and failed when none are ready. Continue later surfaces after result_too_large using the remaining deterministic budget.

- [ ] **Step 4: Build immutable public/private payloads**

Public run contains protocolVersion, runId, workspaceId, baseWorkspaceRevision, wire status, created/completed/expires timestamps, normalized intent snapshot, public context signature/receipt, and canonical results. Private payload contains complete normalized configuration, each ready surface's exact closed witnessed `retainedAuthorizationInput` and digest, native outputs, mappings, and bindings—never preflight input, undeclared normalizer fields, credential/provider fields, or the unrestricted combined context.

Canonicalize and cap both payloads before finalize. Re-read and verify the committed row/digests before returning success.

- [ ] **Step 5: Implement fail-closed owner reads**

Implement one private/shared authorize_run_read() service path used by both read() and terminal create dedupe. Its order is:

    captured current-site storage context
    exact owner match
    EditorScope parse and current authorization
    availability projection
    public/private digest verification
    verify every ready retained-authorization-input digest/closed schema/protected projection
    call check_permissions() on each exact witnessed retainedAuthorizationInput.abilityInput
    return immutable public payload

If any ready result is no longer authorized, deny the complete run. Reauthorization calls neither normalize_input() nor execute(). Tombstones reveal only runId, expiresAt, and tombstoneUntil after owner/scope authorization. Corrupt JSON/digest mismatch returns run_payload_mismatch and no partial payload. Tests retain a witnessed target, revoke that target after completion, and prove both GET and active POST dedupe pass the byte-equivalent closed `abilityInput` to check_permissions(), deny identically, and perform zero normalization or execution.

- [ ] **Step 6: Verify all surfaces and commit**

Cover all 511 non-empty subsets of nine surfaces at the registry/service boundary without making real provider calls; use representative real registration/output tests per surface separately. Cover two style invocations, clean and dirty post_blocks, zero-unit success, provider failure as surface failure, payload caps, exact result order, exact witnessed retained-input read/dedupe permission revocation, pre-reservation capture rejection, context-signature persistence, and finalization loss.

Run:

    vendor/bin/phpunit tests/phpunit/RecommendationRunServiceTest.php
    composer run lint:php

Then:

    git add inc/Recommendations/Runs/RecommendationRunService.php tests/phpunit/RecommendationRunServiceTest.php
    git commit -m "Orchestrate recommendation runs"

---

### Task 12: Expose only the closed authenticated internal run REST routes

**Files:**

- Reuse: inc/Support/ClosedJsonSchemaValidator.php
- Create: inc/REST/RecommendationRunsController.php
- Create: tests/fixtures/recommendation-protocol/rest-contract.json
- Create: tests/phpunit/RecommendationRunsControllerTest.php
- Modify: tests/phpunit/bootstrap.php
- Modify: flavor-agent.php
- Modify: tests/phpunit/PluginLifecycleTest.php

**Interfaces:**

    ClosedJsonSchemaValidator::validate(
      mixed $value,
      array $schema,
      string $path = '$'
    ): true|\WP_Error

    RecommendationRunsController::register_routes(): void
    RecommendationRunsController::is_authenticated(
      \WP_REST_Request $request
    ): bool
    RecommendationRunsController::create(
      \WP_REST_Request $request
    ): \WP_REST_Response|\WP_Error
    RecommendationRunsController::read(
      \WP_REST_Request $request
    ): \WP_REST_Response|\WP_Error

- [ ] **Step 1: Extend request/response test support**

Add get_body(), get_json_params(), get_params(), headers, route attributes, and response status/header behavior to the PHPUnit REST doubles. Preserve every existing Agent_Controller and attestation test.

- [ ] **Step 2: Write failing route and closed-schema tests**

Assert exactly:

    POST /flavor-agent/v1/recommendation-runs
    GET  /flavor-agent/v1/recommendation-runs/<canonical-uuid>

Cover unauthenticated failure; 1 MiB raw-body rejection before decode/reservation; malformed JSON; duplicate keys; `{}` versus `[]` at every object/list boundary; unknown root/nested fields; invalid UUID/revision/scope/capture union; exact timestamp offset/milliseconds/impossible-date/leap-second rejection; and explicit rejection of siteId, userId, abilityId, surfaceInputs, operations, results, and native payloads. Every unchanged malformed request shape returns recommendation_request_invalid with validation/do_not_retry semantics; configuration normalization errors return context_configuration_invalid with validation/refresh_context semantics; complete capture errors return context_capture_invalid before reservation.

Exercise every closed per-code error-details schema from Spec section 16.5, including the 512-character ValidationPath grammar, retryAfterSeconds 1..600, expired deadlines, nullable retained run ID on workspace conflict, empty-detail codes, and rejection of an extra/provider/debug field.

Pin statuses:

| Condition | Status |
| --- | ---: |
| new terminal run | 201 |
| active terminal dedupe/read | 200 |
| closed-schema/configuration error | 400 |
| authentication/authorization denial | core 401/403 or 403 |
| unknown run or `now >= tombstoneUntil` | 404 |
| idempotency/finalization/busy conflict | 409 |
| `expiresAt <= now < tombstoneUntil` | 410 |
| stored run payload/digest mismatch | 500 |
| pre-terminal foundation unavailable | 503 |

- [ ] **Step 3: Implement recursive closed-schema validation**

Support the exact closed manifest vocabulary fixed in Spec 1 section 6, including local $ref/$defs, union-valued type, boolean-or-schema additionalProperties, anyOf/oneOf/allOf/not, all collection/string/numeric constraints, and the declared formats and annotation-only keywords. Reject unsupported schema keywords during manifest validation rather than silently ignoring them. Reuse the Task 8 live-Ability schema corpus so REST and Ability validation cannot drift.

Use JsonDuplicateKeyScanner on the raw POST body before json_decode(). Decode with `json_decode($raw, false, 512, JSON_THROW_ON_ERROR)` so JSON objects, especially `{}`, remain stdClass and arrays remain lists. Validate the complete typed JSON value before converting the non-empty top-level request object to a service map; preserve nested object/list kind through configuration/capture normalization and canonicalization. Do not trust WP REST route args to close nested objects.

- [ ] **Step 4: Register the dedicated controller**

register_routes() adds only POST collection and GET canonical-run routes under flavor-agent/v1. The permission callback checks coarse authentication only. create() delegates to RecommendationRunService and selects 201/200 from deduplicated. read() delegates to the service's owner/scope/digest/reauthorization path.

Set Cache-Control: no-store on every success and normalized error response. Map run_payload_mismatch to HTTP 500 for both GET and active POST dedupe; tests corrupt stored JSON and each digest independently and assert the same bounded response and header with no private payload. Never register list, update, delete, result-reference, generic execute, or private-debug routes.

- [ ] **Step 5: Prove GET is observational**

Use repository write counters to assert GET performs no prune, lazy expiry, row update, activity write, workspace install, Ability normalization, or Ability execute. Permission reauthorization may call check_permissions() only on the witnessed `retainedAuthorizationInput.abilityInput`. Assert privateBinding, native result, effectiveInput, retainedAuthorizationInput, preflight input, site binding, and user binding do not appear in any serialized response or error.

- [ ] **Step 6: Verify existing routes and commit**

Run:

    vendor/bin/phpunit tests/phpunit/RecommendationRunsControllerTest.php tests/phpunit/AgentRoutesTest.php tests/phpunit/AgentControllerTest.php tests/phpunit/PluginLifecycleTest.php
    composer run lint:php

Then:

    git add inc/REST/RecommendationRunsController.php tests/fixtures/recommendation-protocol/rest-contract.json tests/phpunit/RecommendationRunsControllerTest.php tests/phpunit/bootstrap.php flavor-agent.php tests/phpunit/PluginLifecycleTest.php
    git commit -m "Add recommendation run routes"

---

### Task 13: Add the fixed run client and complete page coordinator

**Files:**

- Create: src/recommendations/protocol/schema-validator.js
- Create: src/recommendations/runs/client.js
- Create: src/recommendations/runs/__tests__/client.test.js
- Modify: src/recommendations/workspace/coordinator.js
- Create: src/recommendations/workspace/__tests__/coordinator.test.js
- Modify: src/store/index.js
- Modify: src/store/__tests__/recommendation-workspace.test.js

**Interfaces:**

    validateRecommendationProtocolValue(value, schema, path = '$')

    createRecommendationRun(request, { signal } = {})
    readRecommendationRun(runId, { signal } = {})
    normalizeRecommendationRunResponse(response)
    normalizeRecommendationRunError(error)

    createRecommendationCoordinatorActionCreators(
      workspaceActions,
      {
        collectContext,
        createRun,
        resolveEditorScope,
        getRecommendationPageIdentity,
        digestRun,
        createAbortController
      }
    )

    coordinateRecommendationRequest({
      workspaceId,
      expectedWorkspaceRevision,
      actorSessionId,
      idempotencyKey
    })

    completeRecommendationRequest({
      workspaceId,
      expectedWorkspaceRevision,
      idempotencyKey
    })

- [ ] **Step 1: Write failing fixed-client tests**

Assert apiFetch receives only:

    {
      path: '/flavor-agent/v1/recommendation-runs',
      method: 'POST',
      data: validatedClosedRequest,
      signal
    }

and:

    {
      path: '/flavor-agent/v1/recommendation-runs/' + encodeURIComponent(runId),
      method: 'GET',
      signal
    }

Reject arbitrary paths/Ability names, non-terminal runs, missing/duplicate result surfaces, invalid IDs/timestamps/statuses, oversized public payloads, and error objects without the fixed base code/category/retry structure. Keep the outer success/error REST wrappers closed: an extra wrapper property fails.

For the nested public run, add paired fixtures proving a compatible unknown optional property at the run, result, unit, receipt, and error object boundaries is accepted then stripped, and the normalized object/digest equals the same run without those extensions. Recursively reject every exact reserved/private member from Spec section 13.1, including `applyBatchId`, `executionBinding`, `privateBinding`, `effectiveInput`, `retainedAuthorizationInput`, native payloads, operations, and authority/lease fields. Reject `compensated` and every other incompatible value of a known closed enum. Accept an unknown bounded error code when category/retryDisposition/base fields are valid; validate exact details for known codes and discard unsupported code-specific details for generic handling.

- [ ] **Step 2: Implement the JavaScript closed-schema validator and client**

Mirror the PHP validator vocabulary and error paths for closed requests and known response fields. Validate before POST; apiFetch supplies the cookie REST nonce. After response, first validate the exact outer wrapper and scan the nested run for reserved/private names, then recursively project only known protocol 1.0 fields while ignoring compatible optional extensions. Validate that known projection, compute its canonical digest, and cache only it. Normalize server errors to the common bounded envelope with open code strings and never cache an invalid or unprojected response.

- [ ] **Step 3: Write coordinator race tests before implementation**

Use deferred capture/network promises and the actual store actions to cover:

- wrong initial workspace/revision -> no capture/network;
- empty configuration -> context_not_configured;
- current scope mismatch -> no network;
- scope/configuration change before dispatch -> no network;
- scope/configuration change during POST -> terminal run cached but not installed;
- two different completions from one revision -> both cache, exactly one installs;
- same idempotent run twice -> one current relation and one revision increment;
- same run after supersession -> never revived;
- run_payload_mismatch -> first cache retained, no install;
- abort on scope change/unmount;
- context change does not abort but loses CAS;
- latest-request generation projection ignores stale finish/fail request keys;
- request A is aborted in workspace A, workspace B registers request B with the same visible token, then A settles: A's identity-checked release and finish/fail are no-ops, B's controller/loading state remain, and only B can release/settle them;
- request-token maximum returns workspace_revision_exhausted before controller allocation, capture, or network;
- the exported first-party store action has no actor override and always POSTs `getRecommendationPageIdentity().humanActorSessionId`;
- the separately named internal seam passes through a valid later-agent UUID only when it differs from both page identity values; an absent, malformed, `editorInstanceId`-equal, or `humanActorSessionId`-equal value creates no generation state, controller, capture, or POST.

Run:

    npm run test:unit -- --runInBand src/recommendations/runs/__tests__/client.test.js src/recommendations/workspace/__tests__/coordinator.test.js

Expected: coordinator tests fail because completeRecommendationRequest is absent.

- [ ] **Step 4: Implement capture/generate/cache/install ordering**

The coordinator performs exactly:

    read and compare workspace
    require configured normalized context
    require current resolved scope match
    first-party wrapper injects page humanActorSessionId; internal seam validates its required actorSessionId
    begin generation and receive GenerationRequestKey
    register AbortController and retain the returned identity-checked handle
    capture one immutable snapshot
    synchronously recheck ID/revision/scope
    POST fixed request
    validate and canonical-digest public terminal run
    cache immutable run/digest
    invoke install CAS with original expected revision
    observe committed relationship or conflict
    release the exact controller handle and finish/fail the matching request key in finally

There is no asynchronous gap between the final page comparison and guarded dispatch inside installRecommendationRun. Every asynchronous continuation closes over its original request key and controller handle. A failed install returns workspace_changed_during_generation and may include retained runId for safe diagnostics; it performs no semantic mutation.

- [ ] **Step 5: Compose the human wrapper into the sole store**

Import and spread createRecommendationCoordinatorActionCreators() into the existing flavor-agent actions. It exposes only the no-actor `completeRecommendationRequest()` wrapper; the separately named `coordinateRecommendationRequest()` export remains a non-store internal seam for later agent wiring and is not spread into the store. Do not register another store, React context, global callback, or WebMCP tool. Keep the controller Map module-local.

- [ ] **Step 6: Verify and commit**

Run:

    npm run test:unit -- --runInBand src/recommendations/runs/__tests__/client.test.js src/recommendations/workspace/__tests__/coordinator.test.js src/store/__tests__/recommendation-workspace.test.js src/components/__tests__/RecommendationWorkspaceBootstrap.test.js
    npm run lint:js
    npm run build

Then:

    git add src/recommendations/protocol/schema-validator.js src/recommendations/runs/client.js src/recommendations/runs/__tests__/client.test.js src/recommendations/workspace/coordinator.js src/recommendations/workspace/__tests__/coordinator.test.js src/store/index.js src/store/__tests__/recommendation-workspace.test.js
    git commit -m "Coordinate recommendation run installation"

---

### Task 14: Prove cross-surface behavior, document the foundation, and close release gates

**Files:**

- Create: tests/e2e/flavor-agent.recommendation-workspace.spec.js
- Create: tests/phpunit/VendorlessRecommendationRunBootstrapTest.php
- Create: docs/reference/recommendation-workspace-and-runs.md
- Create: docs/validation/2026-08-27-webmcp-workspace-run-foundation.md
- Modify after the global dirty-doc prerequisite: docs/reference/abilities-and-routes.md
- Modify: docs/reference/js-frontend-architecture.md
- Modify: docs/reference/php-backend-architecture.md
- Modify: docs/FEATURE_SURFACE_MATRIX.md
- Modify: docs/SOURCE_OF_TRUTH.md
- Modify: STATUS.md
- Modify: README.md
- Modify: docs/flavor-agent-readme.md
- Modify: readme.txt
- Modify: docs/reference/cross-surface-validation-gates.md
- Modify: scripts/check-doc-freshness.sh

- [ ] **Step 1: Add focused browser coverage in a new file**

Do not enlarge the existing 6,000-line smoke suite. Use tests/e2e/test-fixtures.js so any 5xx response is recorded. Since Spec 1 adds no button, invoke:

    wp.data.dispatch('flavor-agent').completeRecommendationRequest(...)

Intercept only the fixed run route. Add ordinary Playground tests and @wp70-site-editor cases for:

- two tabs, same post, distinct editor/workspace IDs and independent revisions;
- Site Editor template/template-part navigation creates a fresh revision-zero workspace;
- a resolver null gap invalidates before the new scope;
- Style Book target changes workspace while subsection churn does not;
- selected focus changes revision exactly once, while document focus ignores selection churn;
- context/config change during an in-flight run caches but does not install;
- two out-of-order completions from one base revision install exactly one;
- a forced partial run caches one result for every requested surface;
- legacy panels still work and never dual-write shared selection;
- a pre-bootstrap document.modelContext.registerTool counter remains zero.

- [ ] **Step 2: Update stable product/developer documentation**

Document:

- one page-owned workspace and exactly what workspaceRevision guards;
- normalized context levers and the deny-by-default seam table;
- nine wire surfaces versus eight UI recommendation experiences;
- the two internal workflow routes, explicitly not a ninth Ability or MCP surface;
- public/private retained payload boundary, 30-minute active TTL, 24-hour tombstone, and pure reads;
- activation/cron storage and uninstall deletion disclosure;
- unchanged legacy recommendation/apply/undo flows;
- no public WebMCP tools until Spec 5.

Do not reach this step until the global prerequisite for the user's existing docs/reference/abilities-and-routes.md edits is satisfied and those edits are present in the implementation branch. Reconcile against that exact resolved content. Update route totals only from a fresh source count. Keep 35 Abilities and 15 MCP tool invariants.

Replace stale dated evidence in cross-surface-validation-gates.md rather than appending contradictory claims. Add stable doc checks for the final route count, nine wire surfaces, eight recommendation Abilities, and zero public WebMCP tools.

- [ ] **Step 3: Add vendor-less coverage and run pre-candidate focused gates**

Before the aggregate run, add a subprocess test that stages the plugin bootstrap, inc/, assets needed at load, and shared/manifest into a temporary plugin directory without vendor/. Stub only the WordPress bootstrap functions/hooks required to load flavor-agent.php, require the staged plugin in a fresh PHP process, and assert V1Contract plus every new PSR-4 class resolves without a fatal error. This test must exercise the fallback autoloader rather than Composer-first test bootstrap.

Run:

    npm run test:unit -- --runInBand \
      src/utils/__tests__/canonical-json.test.js \
      src/recommendations/protocol/__tests__/v1-contract.test.js \
      src/recommendations/workspace/__tests__/context-configuration.test.js \
      src/recommendations/workspace/__tests__/editor-instance.test.js \
      src/recommendations/workspace/__tests__/editor-scope.test.js \
      src/recommendations/workspace/__tests__/state.test.js \
      src/recommendations/workspace/__tests__/coordinator.test.js \
      src/recommendations/context/__tests__/collector.test.js \
      src/recommendations/runs/__tests__/client.test.js \
      src/components/__tests__/RecommendationWorkspaceBootstrap.test.js \
      src/store/__tests__/recommendation-workspace.test.js

    vendor/bin/phpunit \
      tests/phpunit/CanonicalJsonTest.php \
      tests/phpunit/RecommendationProtocolContractTest.php \
      tests/phpunit/RecommendationContextTest.php \
      tests/phpunit/RecommendationRunServiceTest.php \
      tests/phpunit/RecommendationRunRepositoryTest.php \
      tests/phpunit/RecommendationRunsControllerTest.php \
      tests/phpunit/RecommendationRunLifecycleTest.php \
      tests/phpunit/VendorlessRecommendationRunBootstrapTest.php

    npm run lint:js
    composer run lint:php
    npm run check:docs

These are development-loop checks, not immutable release evidence. Fix any failure before code review.

- [ ] **Step 4: Request independent code review before freezing the candidate**

Use superpowers:requesting-code-review with the approved Spec 1 and this plan. Require the reviewer to map every AC1–AC17 row to current code/tests and classify implemented, partial, missing, or intentional later-spec deferral. Require explicit review of signed-seam consumption, post_blocks clean-state behavior, schema keyword coverage, PHP object/array identity, pre-reservation capture validation, POST-dedupe reauthorization, and the no-governed-apply boundary. Resolve all P0/P1 findings and rerun affected focused gates.

- [ ] **Step 5: Commit the immutable implementation candidate**

Stage only the new browser test, vendor-less test, and stable documentation changes from this task. Do not create or stage the validation ledger yet:

    git add tests/e2e/flavor-agent.recommendation-workspace.spec.js tests/phpunit/VendorlessRecommendationRunBootstrapTest.php docs/reference/recommendation-workspace-and-runs.md docs/reference/abilities-and-routes.md docs/reference/js-frontend-architecture.md docs/reference/php-backend-architecture.md docs/FEATURE_SURFACE_MATRIX.md docs/SOURCE_OF_TRUTH.md STATUS.md README.md docs/flavor-agent-readme.md readme.txt docs/reference/cross-surface-validation-gates.md scripts/check-doc-freshness.sh
    git commit -m "Document recommendation run foundation"
    git status --short
    git rev-parse HEAD

Require an empty status. Record that HEAD as CANDIDATE_SHA. No later source, test, manifest, stable-documentation, or generated-source change is allowed under that candidate identity; any such fix creates a new candidate and restarts this step.

- [ ] **Step 6: Verify exactly CANDIDATE_SHA in a fresh clean checkout**

Create a separate verification worktree at exactly CANDIDATE_SHA. Confirm its initial status is empty, install dependencies without changing lockfiles, and run all evidence there:

    flavor_candidate_sha=$(git rev-parse HEAD)
    flavor_verify_root=$(mktemp -d)
    git worktree add "$flavor_verify_root/candidate" "$flavor_candidate_sha"
    cd "$flavor_verify_root/candidate"
    test -z "$(git status --short)"
    composer install
    npm ci

    npm run build
    npm run test:e2e:playground -- tests/e2e/flavor-agent.recommendation-workspace.spec.js
    npm run test:e2e:wp70 -- tests/e2e/flavor-agent.recommendation-workspace.spec.js

Record the actual WordPress versions from both environments. Both configurations are currently pinned to WordPress 7.1; do not label the historical wp70 script as WordPress 7.0 evidence.

Then run:

    node scripts/verify.js --skip-e2e
    npm run check:docs
    npm run verify:strict
    npm run dist
    sha256sum dist/flavor-agent.zip
    unzip -l dist/flavor-agent.zip | rg 'shared/recommendation-protocol-1.0.json'
    unzip -Z1 dist/flavor-agent.zip | rg '^flavor-agent/vendor/'
    git diff --check
    git diff --exit-code "$flavor_candidate_sha" -- .

The vendor search is expected to return no matches (exit 1); record that expected result rather than treating it as a failed release gate. Confirm the archive contains the manifest and every runtime class, excludes tests/fixtures and vendor, and boots through the vendor-less path. Inspect output/verify/summary.json and the final VERIFY_RESULT line. `incomplete` is not pass. Record exact commands, exit status, test counts, timestamps, actual harness versions, Plugin Check prerequisite/result, archive SHA-256/listing, and any explicit blocker or waiver. Do not claim browser, Plugin Check, or strict verification if the recorded command was unavailable or incomplete.

After the commands, compare tracked files to CANDIDATE_SHA. Generated or dependency output may exist only where ignored; any tracked diff invalidates the evidence and requires a new candidate.

- [ ] **Step 7: Create the evidence-only commit and prove its boundary**

Back in the implementation worktree, create only docs/validation/2026-08-27-webmcp-workspace-run-foundation.md. Record CANDIDATE_SHA and the exact clean-checkout evidence from Step 6. Then run:

    npm run check:docs
    git diff --check
    git add docs/validation/2026-08-27-webmcp-workspace-run-foundation.md
    git diff --cached --name-only
    git commit -m "Record recommendation run evidence"
    git rev-parse HEAD
    git diff --name-only "$flavor_candidate_sha"..HEAD

The cached-name check must print exactly the validation ledger. Record the resulting HEAD as EVIDENCE_SHA and verify the only path changed from CANDIDATE_SHA to EVIDENCE_SHA is that ledger. The final handoff reports both SHAs and states unambiguously that all runtime/package evidence belongs to CANDIDATE_SHA.

---

## Completion Boundary

Do not mark Spec 1 implemented until every acceptance row is evidenced against the immutable CANDIDATE_SHA, the evidence-only EVIDENCE_SHA is identified separately, and both relevant working trees contain no unintended tracked changes. The implementation is still not permission to:

- migrate checkbox/review ownership;
- add EditorInteractionGuard;
- derive apply plans or operation digests;
- compile patterns into governed operations;
- mutate targets, create apply requests, or change undo;
- register any document.modelContext tool.

Those remain Specs 2–5. A successful Spec 1 handoff consists of CANDIDATE_SHA, EVIDENCE_SHA, the validation ledger, exact harness versions, candidate archive digest/listing, known waivers/blockers, and a concise list of later-spec interfaces now available.

## Execution Handoff

After approval of this plan, choose one:

1. **Subagent-driven (recommended):** Execute task-by-task in this session with a fresh worker per task and review checkpoints.
2. **Inline execution:** Use superpowers:executing-plans in this session, create a clean worktree, and run the plan in batches with review checkpoints.
