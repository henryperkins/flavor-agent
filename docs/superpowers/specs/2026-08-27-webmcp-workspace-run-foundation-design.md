# Flavor Agent WebMCP Implementation Series — Spec 1: Workspace, Context, and Run Foundation

- **Status:** Approved companion implementation specification; runtime implementation is not part of this document
- **Approved:** 2026-08-27
- **Series:** 1 of 5
- **Date:** 2026-08-27
- **Normative upstream:** `docs/superpowers/specs/2026-08-27-webmcp-recommendation-protocol-design.md`
- **Upstream snapshot:** commit `f8aae3014cc0b7009d6384e632f8fd303202be8e`, blob `8fead468cbe128d763bec1d1634ee394d136051f`
- **Implementation baseline:** commit `f8aae3014cc0b7009d6384e632f8fd303202be8e`
- **Scope:** Page-owned `RecommendationWorkspace`, bounded workspace-context capture, authenticated server-retained `RecommendationRun`, multi-surface generation, expiry projection, and run lifecycle storage

## 1. Purpose

This is the first implementation specification derived from the canonical Flavor Agent WebMCP Recommendation Protocol 1.0. The protocol remains authoritative for public behavior. This document fixes the concrete module boundaries, data ownership, storage, request flow, failure ordering, and verification needed to implement its workspace, context, and run foundation in the current plugin.

The implementation produced from this specification MUST make it possible to:

- create one page-scoped recommendation workspace for one top-level editor instance and primary editor scope
- atomically configure the context used by a recommendation request
- capture a bounded, deny-by-default context snapshot without exposing arbitrary Gutenberg selectors
- generate recommendations for one to nine wire surfaces by invoking the eight existing recommendation Abilities
- retain an immutable terminal run and private native result bindings on the server
- install that run as current only when the page workspace compare-and-swap still succeeds
- project run expiry without writing during a read
- leave stable interfaces for selection, review, planning, apply, undo, and imperative WebMCP tools without implementing those later concerns here

The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT**, and **MAY** are normative within this implementation series. If this document conflicts with the canonical protocol, the protocol wins and this specification must be corrected before implementation.

## 2. Position in the implementation series

The companion series is divided by state ownership and commit boundary, not by UI surface.

| Spec | Boundary | Depends on |
|---|---|---|
| **1. Workspace, Context, and Run Foundation** | Page workspace identity/CAS, context configuration and capture, run orchestration, retained runs, pure run availability | Canonical protocol only |
| **2. Selection, Review, and Plan Workspace** | Shared checkbox/selection owner, review leases, `EditorInteractionGuard`, plan derivation, human UI migration | Spec 1 |
| **3. Executor Bindings and Pattern Compiler** | Exact executor binding envelopes, `operation-digest-v1`, pattern-to-post-blocks compiler, target grouping | Specs 1–2 |
| **4. Governed Apply, Status, and Undo Projection** | Apply-request dedupe, double run-expiry commit check, pure activity projection, status and undo workflow adapters | Specs 1–3 |
| **5. WebMCP Projection and Release Gate** | Version negotiation, eight closed-schema imperative tools, registration/cleanup, capability gate, end-to-end evidence | Specs 1–4 |

The titles of later specifications may be refined, but these ownership boundaries are fixed. In particular:

- Spec 1 does not move the current block checkbox `Set` out of React component state.
- Spec 1 does not make a recommendation run WebMCP-applicable.
- Spec 1 does not register a subset of the eight public tools.
- Spec 1 does not create a second public Ability that wraps the eight domain recommendation Abilities.
- Spec 5 remains blocked until every canonical protocol P0 prerequisite is complete.

## 3. Decisions fixed by this specification

### 3.1 One page state owner

`RecommendationWorkspace` MUST be a slice of the existing `flavor-agent` `wp.data` store. The implementation MUST NOT register another recommendation store and MUST NOT add a mutable React `WorkspaceContext` provider.

The term `WorkspaceContextSnapshot` in this specification means one immutable, ephemeral capture value used for one generation attempt. It is not a React Context, a second store, a browser-global mutable object, or a server-persisted workspace.

Components consume workspace state through the existing `@wordpress/data` selectors and actions. Later WebMCP tools will dispatch those same actions.

### 3.2 Page workspace, server run

The page owns mutable workflow coordination:

- `workspaceId`
- `workspaceRevision`
- normalized `contextConfiguration`
- the current/superseded run relationship
- later selection, review, and derived plan state

The server owns generated evidence:

- terminal `RecommendationRun` payloads
- result references and immutable public units
- private native Ability results and exact per-surface request projections
- authenticated owner binding
- idempotency reservation and lease state
- active expiry and tombstone deadlines

There is no server `RecommendationWorkspace` row in protocol 1.0.

### 3.3 Existing Abilities remain the execution boundary

The run orchestrator MUST resolve each registered Ability with `wp_get_ability()` and execute it through the returned `WP_Ability` object. It MUST NOT call the underlying recommender callback directly, reproduce its permission logic, or submit a loop of caller-supplied generic Ability names.

`WP_Ability::execute()` is the authoritative input-validation, permission, hook, execution, and output-validation path. The orchestrator MAY call `check_permissions()` first to classify a surface as unavailable, but `execute()` still performs the authoritative check immediately before execution.

The run REST controller is a workflow transport for the first-party page. It is not a ninth recommendation Ability and is not exposed through the existing MCP Adapter.

### 3.4 Public and private run payloads are separate

The server MUST store two immutable terminal payloads:

- a public run projection safe to return to the authenticated owner and later WebMCP callers
- a private binding payload used only by server-side selection/apply adapters

The public payload never contains native operations, pattern markup, unrestricted context values, or target evidence hidden behind `resultRef`. The private payload is not returned by the run REST API.

### 3.5 No public WebMCP registration in Spec 1

Spec 1 reserves the imperative adapter seam but MUST NOT call `document.modelContext.registerTool()`. Unsupported browser behavior, registration lifecycle, closed tool schemas, and `AbortSignal` cleanup are Spec 5 concerns.

## 4. Current baseline and integration constraints

The implementation starts from these repository facts:

| Area | Current state | Spec 1 treatment |
|---|---|---|
| Page data | `src/store/index.js` registers one `flavor-agent` store and contains per-surface state | Add a composed workspace/run slice to this store; extract its pure reducer/actions/selectors into focused modules |
| Editor bootstrap | `src/index.js` mounts the plugin components once per editor page | Mount one workspace bootstrap before recommendation panels |
| Block selection | `BlockRecommendationsPanel.js` owns selected/applied key `Set` values locally | Preserve in Spec 1; migrate in Spec 2; do not dual-write |
| Recommendation execution | Eight registered recommendation Abilities, with style serving two scoped surfaces | Reuse via `WP_Ability::execute()` through a fixed surface registry |
| Client execution | `src/store/abilities-client.js` and `assets/abilities-bridge.js` run one Ability at a time | Preserve for legacy panels; the new run client calls the run workflow REST route |
| Persistent lifecycle | Activity and attestation repositories use plugin-owned tables, schema options, activation upgrades, prune hooks, and uninstall cleanup | Follow the same lifecycle conventions in a separate run table; do not overload the activity table |
| Apply/undo | Existing governed Abilities and activity rows already own request, decision, execution, and undo behavior | Do not alter in Spec 1 |

Spec 1 is additive. Existing per-surface panels continue to operate until a later specification migrates them to the shared run. Spec 1 introduces the shared coordinator but no new product button and no automatic replacement of a legacy panel's request path. No implementation may make a legacy panel and the new workspace both authoritative for the same selection or review value.

## 5. Deliverables and non-goals

### 5.1 Required deliverables

Spec 1 implementation includes:

- a versioned shared protocol manifest consumed by PHP and bundled JavaScript
- editor-scope resolution and workspace bootstrap
- pure context-configuration normalization in PHP and JavaScript with shared fixtures
- one `RecommendationWorkspace` store slice and immutable public run cache
- same-page compare-and-swap actions for context replacement and run installation
- a deny-by-default client context collector and server context-envelope builder
- a server-computed context receipt and `contextSignature`
- a fixed nine-surface registry mapped to the eight existing recommendation Abilities
- an authenticated run orchestration service and dedicated REST controller
- a plugin-owned run table, schema lifecycle, lease/idempotency handling, TTL, tombstones, and prune job
- pure run availability projection
- unit, integration, REST, repository, race, and browser evidence described in section 19

### 5.2 Explicit non-goals

Spec 1 does not implement:

- selection cardinality or component checkbox migration
- review ownership, review lease expiry, or human takeover
- `EditorInteractionGuard` typing/composition/drag/save predicates
- `applyPlan`, target groups, operation digests, or plan revisions
- the pattern-to-post-blocks executor compiler
- apply request creation, admin decision, target mutation, apply status, or undo
- protocol version negotiation in a WebMCP tool
- `document.modelContext.registerTool()`
- a background job system, cross-target transaction, compensation, or shared cross-tab workspace
- raw Gutenberg selector/action exposure or a generic Ability executor

## 6. Shared protocol manifest

Implementation MUST add `shared/recommendation-protocol-1.0.json` as the single checked-in machine-readable source for data used by both runtimes.

The manifest contains only static protocol data:

- protocol version and collector version
- the nine canonical wire surfaces and their canonical order
- the eight Ability mappings, including the two style scopes
- interaction modes and Spec 1 default execution-class policy
- context group and seam path/source allowlists
- mandatory context profiles by surface
- exact hard/soft invocation policy for every requested seam consumer
- final-receipt truncation limits and trusted docs URL/currentness policy
- closed configuration enums and default values
- string, collection, tree, request, result, and retained-payload limits
- public run and internal REST JSON Schemas
- error categories and retry dispositions used by this foundation

The manifest MUST NOT contain localized labels, credentials, dynamic capability results, Gutenberg selector names as public API, PHP callbacks, or JavaScript function names.

PHP loads it through `FlavorAgent\Recommendations\Protocol\V1Contract` with a request-local static cache. JavaScript imports the same JSON at build time through `src/recommendations/protocol/v1-contract.js`, validates its expected manifest version, and freezes the exported projection.

Failure to load or validate the manifest makes the run foundation unavailable. It MUST NOT silently fall back to duplicated hard-coded surface lists.

The manifest uses a closed JSON Schema 2020-12 subset implemented identically in PHP and JavaScript. Validation keywords are `$ref` to local `$defs`, `type` as a string or non-empty unique type array, `required`, `properties`, `additionalProperties` as a boolean or schema, `items`, `minItems`, `maxItems`, `uniqueItems`, `minLength`, `maxLength`, `pattern`, `format` (`uuid`, `date-time`, or `uri` only), `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`, `multipleOf`, `enum`, `const`, `anyOf`, `oneOf`, `allOf`, and `not`. `$schema`, `$id`, `$defs`, `title`, `description`, `default`, `examples`, `deprecated`, `readOnly`, and `writeOnly` are allowed metadata/definition keywords and do not independently validate an instance. Any other keyword makes the manifest invalid.

Registered Ability schemas remain Gutenberg/WordPress-compatible draft-04 schemas; the additive `workspaceContext` schema therefore uses singleton `enum` rather than `const` and does not inject `$defs`/`$ref`. They are not silently narrowed to a smaller vocabulary. `ClosedJsonSchemaValidator` has an explicit Ability-schema mode that preserves object openness where the existing schema permits it, supports union-valued `type` and `anyOf` in the current recommendation schemas, and rejects a value unless both the recursive validator and applicable WordPress REST schema validation accept it. Contract tests enumerate the live input and output schema for all eight recommendation Abilities, record every keyword encountered, and fail when a keyword has neither implemented validation semantics nor an explicit annotation-only classification. Manifest/runtime-wire schemas use the closed 2020-12 subset above; the two dialects are never conflated.

## 7. Workspace identity and editor-scope lifecycle

### 7.1 Identity values

The page creates `editorInstanceId` once when the editor bundle evaluates. It is a UUIDv4 held for that top-level page load only and is not restored from local storage, session storage, post meta, or the server. The first-party human workflow also creates one page-lifetime `humanActorSessionId`; later agent calls supply a distinct `actorSessionId`. Actor sessions identify request provenance, not authority or workspace identity.

The editor-scope resolver returns this closed value:

```json
{
  "key": "post:42",
  "kind": "post",
  "entityId": "42"
}
```

`kind` is one of:

```text
post
template
template_part
global_styles
style_book
temporary
```

For `style_book`, `entityId` is the Global Styles entity ID; the canonical block target is carried only by the final segment of `editorScope.key`.

The `key` uses the canonical protocol forms:

```text
post:<id>
wp_template:<id>
wp_template_part:<id>
global_styles:<id>
style_book:<id>:<block-name>
temporary:<editorInstanceId>:<entity-kind>
```

The resolver MUST validate and bound every segment. A selected content block, inserter position, navigation block, or control subsection within the same Style Book block target does not change the primary workspace unless the primary editor entity itself changes. The active Style Book block target is different: its canonical block name is part of `style_book:<id>:<block-name>`, so changing that target creates a new workspace.

For `temporary`, `entityId` is the page-lifetime `editorInstanceId`; the unresolved entity kind remains in the scope key. This keeps the closed scope shape non-empty without pretending an unsaved WordPress entity already has a persistent ID.

The final `entity-kind` segment of a temporary key is either a registered post-type slug or one of `wp_template`, `wp_template_part`, `global_styles`, and `style_book`. The server rejects any other value. A registered post type is authorized with that post type object's `edit_posts` capability; the four Site Editor kinds require `edit_theme_options`. POST and GET repeat this authorization. Temporary scopes may yield advisory or stage-only results where the source Ability permits them, but they can never yield a complete governed persistent binding.

### 7.2 Workspace creation

For each resolved primary scope, the page creates a random `workspaceId`. The composite identity is:

```text
editorInstanceId + editorScope.key -> workspaceId
```

The composite is an in-memory binding, not a deterministic hash. The random `workspaceId` prevents editor identity details from being inferred from the ID and allows a fresh workspace when a single-page editor navigates away and later returns.

On a primary scope transition:

1. abort all active client generation controllers
2. create a new `workspaceId`
3. reset `workspaceRevision` to `0`
4. install the new `editorScope`
5. reset context configuration, current run relationship, selection, review, and plan
6. preserve server runs only in the server repository; any page cache entry is disposable

An unsaved temporary scope is never promoted in place. First successful save resolves a canonical entity and creates a new workspace.

If the resolver temporarily has no valid primary scope, the bootstrap aborts all active generation controllers and dispatches `invalidateRecommendationWorkspace`. That lifecycle-only action installs the explicit unbound state (`workspaceId: null`, `editorScope: null`, `workspaceRevision: 0`) and clears configuration, run relationships, selection, review, plan, cache, and transient generation state. A later valid scope always receives a fresh random workspace ID. This null-scope transition prevents a late response from installing into the workspace that was current before Site Editor navigation entered an unresolved state.

### 7.3 Independence

Two tabs editing the same entity create different `editorInstanceId`, `workspaceId`, and revision sequences. The page revision never claims to guard WordPress document content or another tab. Server Ability permissions, entity versions, exact freshness signatures, locks, and later apply-time checks own those boundaries.

When a configured workspace uses `focus.scope: selected_block` or `focus.scope: selection`, a semantic Gutenberg selection change replaces `focus.clientIds` through the same context-configuration action. It does not create a workspace, but it does increment the current workspace revision once, supersede the current run relationship, and clear later selection/review/plan state. Selection changes are ignored for this purpose while the configuration is empty or its focus is `document`/`site_editor_entity`.

Focus synchronization is deterministic. `selected_block` keeps only the first live selected client ID in Gutenberg selection order; `selection` keeps the ordered, first-occurrence-deduplicated live IDs up to the protocol limit of 20. IDs that no longer resolve through the block editor are discarded. Either mode may normalize to an empty array after deselection, which is a semantic complete replacement when the previous array was non-empty.

## 8. Page state model

### 8.1 Store shape

The existing `flavor-agent` store gains these top-level slices:

```json
{
  "recommendationWorkspace": {
    "protocolVersion": "1.0",
    "workspaceId": "uuid",
    "workspaceRevision": 0,
    "editorScope": {
      "key": "post:42",
      "kind": "post",
      "entityId": "42"
    },
    "contextConfiguration": {},
    "currentRun": null,
    "selection": {},
    "review": {},
    "applyPlan": {}
  },
  "recommendationRunCache": {
    "byId": {},
    "payloadDigestsById": {}
  },
  "recommendationGeneration": {
    "requestToken": 0,
    "status": "idle",
    "baseWorkspaceId": null,
    "baseWorkspaceRevision": null,
    "idempotencyKey": null,
    "error": null
  }
}
```

Before a valid primary scope resolves, and during the invalidation interval described in section 7.2, the same shape uses `workspaceId: null`, `editorScope: null`, and `workspaceRevision: 0`; all other workspace values are their empty defaults. No generation or install action succeeds while the store is unbound.

An empty object is the only unconfigured `contextConfiguration` sentinel. It is not a valid generation configuration. The first successful configuration replacement installs the complete normalized shape from protocol section 10.

`recommendationRunCache.byId` contains only public run payloads. `payloadDigestsById` is an internal sidecar keyed by the same run IDs and is omitted from every public selector projection. An existing `runId` may be inserted once. A second payload with the same `runId` and a different digest is a fail-closed `run_payload_mismatch`; the reducer retains the first payload and digest.

The cache is bounded to ten runs. On insertion, it retains the current run plus the nine most recently completed non-current runs, with `completedAt` and then `runId` as deterministic ordering keys. Cache eviction is operational page state: it does not delete the server run, alter the current relationship, or increment `workspaceRevision`.

Generation state is operational UI state. Updating it does not increment `workspaceRevision` and it is not returned as part of the public workspace protocol.

The singular `recommendationGeneration` slice is the latest-request UI projection, not a count or registry of every active request. The coordinator owns the complete page-lifetime map of request tokens to active `AbortController` instances outside reducer state. A finish/fail action updates the projection only when its request token is still current; scope transition or invalidation aborts every controller.

Every workspace revision, expected/base workspace revision, generation request token, and Ability `clientRequest.requestToken` is an integer in the closed range `0..9007199254740991`. Increment at the upper bound fails closed with `workspace_revision_exhausted`; neither runtime wraps, rounds, converts the value to floating-point scientific notation, or dispatches the requested semantic mutation. The shared manifest and both REST/store validators own this same ceiling.

### 8.2 Module boundary

The workspace logic MUST be extracted rather than adding another large block to `src/store/index.js`.

| Target module | Responsibility |
|---|---|
| `src/recommendations/workspace/editor-instance.js` | Page-lifetime UUID and test injection |
| `src/recommendations/workspace/editor-scope.js` | Bounded primary-scope resolver |
| `src/recommendations/workspace/context-configuration.js` | Normalization, semantic equality, client validation |
| `src/recommendations/workspace/state.js` | Default state, action types, pure reducer, selectors, CAS predicates |
| `src/recommendations/workspace/coordinator.js` | Capture/generate/install orchestration and abort ownership |
| `src/recommendations/context/collector.js` | Deny-by-default client seam collection |
| `src/recommendations/runs/client.js` | Authenticated internal REST client and response normalization |
| `src/utils/canonical-json.js` | RFC 8785 canonical JSON for shared fixtures and client canonical values |
| `src/components/RecommendationWorkspaceBootstrap.js` | Scope lifecycle bridge mounted once by `src/index.js` |
| `src/store/index.js` | Compose the extracted defaults, reducer, actions, and selectors into the existing store |

No reducer state may contain an `AbortController`, Promise, registry object, selector function, React element, DOM node, or iframe reference. The coordinator owns those values in page-lifetime module state and aborts them on scope change or unmount.

### 8.3 Actions and revision effects

The store exposes these foundation actions. Names are implementation contracts for later specs.

| Action | CAS input | Revision effect |
|---|---|---|
| `initializeRecommendationWorkspace` | None; page lifecycle only | New workspace begins at `0` |
| `invalidateRecommendationWorkspace` | None; page lifecycle only | Unbound workspace resets to `0` |
| `replaceRecommendationContextConfiguration` | `workspaceId`, `expectedWorkspaceRevision` | No-op: none; semantic change: increment once |
| `synchronizeRecommendationFocus` | Current workspace ID/revision; human editor bridge only | Delegates one semantic focus replacement to the context action |
| `beginRecommendationGeneration` | Current workspace snapshot | None |
| `cacheRecommendationRun` | Immutable `runId`/payload digest | None |
| `installRecommendationRun` | `workspaceId`, `expectedWorkspaceRevision`, `runId` | First current installation: increment once |
| `finishRecommendationGeneration` | Matching transient request token | None |
| `failRecommendationGeneration` | Matching transient request token | None |

`replaceRecommendationContextConfiguration` performs one reducer commit that:

- compares the workspace and expected revision
- canonicalizes before semantic comparison
- returns the existing state for a no-op replacement
- replaces the complete configuration for a semantic change
- marks the existing current run relationship `superseded` with `context_configuration_changed`
- clears selection, review, and plan
- increments exactly once

The action MUST be a complete replacement, never a merge patch.

`synchronizeRecommendationFocus` is a narrow bootstrap adapter, not a second reducer mutation. It builds a complete configuration from the current normalized value plus the bounded live selection, then invokes `replaceRecommendationContextConfiguration`. Its selector subscription compares canonical client-ID arrays so render churn and repeated Gutenberg notifications remain no-ops.

`installRecommendationRun` performs one reducer commit that:

- validates that the run belongs to the same `workspaceId`
- validates `run.baseWorkspaceRevision === expectedWorkspaceRevision`
- validates the current page workspace/revision immediately before dispatch
- repeats those comparisons in the pure reducer
- installs `{ runId, relationship: "current", supersededReason: null }`
- clears the reserved selection/review/plan values
- increments exactly once

An exact retry is special-cased before generic conflict handling. If the same `runId` is already current with the same immutable payload digest, installation returns success without mutation or another revision increment. If that run is superseded, the retry does not revive it.

### 8.4 Synchronous page CAS

Every CAS action is an `@wordpress/data` thunk with a synchronous final sequence:

```text
select current workspace
compare workspaceId and expectedWorkspaceRevision
dispatch one guarded reducer action without awaiting
select committed workspace
return a structured success or conflict result
```

There is no asynchronous gap between the final comparison and guarded dispatch. The reducer independently refuses a mismatched action. Action results use machine codes rather than exceptions for expected races.

The later WebMCP adapter may call these thunks but may not reproduce their CAS logic.

### 8.5 Selectors and observational projection

The composed store exports these foundation selectors:

```text
getRecommendationWorkspace
getRecommendationWorkspaceId
getRecommendationWorkspaceRevision
getRecommendationEditorScope
getRecommendationContextConfiguration
getCurrentRecommendationRunRelationship
getRecommendationRun
getCurrentRecommendationRun
getRecommendationRunAvailability
getRecommendationWorkspaceProjection
getRecommendationGenerationState
isRecommendationGenerationRunning
```

Selectors that accept a run ID return public cached data only. `getRecommendationRunAvailability(runId, now)` and `getRecommendationWorkspaceProjection(now)` are pure projections over an injected time; they never dispatch cache eviction, expiry, relationship, or revision actions. If the required run is not cached, they report a bounded unresolved relationship and do not fetch it as a selector side effect.

`getCurrentRecommendationRun()` returns the cached public run for either a current or superseded relationship so stale evidence can remain visible. Eligibility is reported separately by the relationship and availability projections; the selector does not make a superseded run current by hiding or rewriting it. `getRecommendationRunAvailability()` returns exactly one of `active`, `expired`, or `unresolved`; the unresolved projection uses `reasonCode: run_not_cached` and never guesses server state.

## 9. Context configuration normalization

PHP and JavaScript normalizers MUST produce byte-equivalent canonical values for all valid protocol 1.0 configurations.

JSON object/array identity is part of that contract. JavaScript uses plain objects for JSON objects and arrays only for JSON arrays. PHP decodes the raw REST body with `json_decode($raw, false, 512, JSON_THROW_ON_ERROR)`, validates objects as `stdClass`, and may convert a non-empty object to an associative map only at a typed domain boundary. An empty JSON object remains `stdClass`; an empty PHP array always represents `[]`, never `{}`. `CanonicalJson` serializes `stdClass` and non-list associative maps as objects and list arrays as arrays. It rejects resources, callables, non-finite numbers, sparse numeric-key arrays, and any value whose JSON kind is ambiguous. Shared fixtures MUST include top-level and nested `{}` versus `[]` cases so PHP cannot accidentally normalize `surfaceParameters: {}` to an array.

Normalization rules are:

- reject unknown keys at every closed object boundary
- trim leading/trailing Unicode whitespace from user strings without rewriting internal wording
- reject invalid UTF-8, control characters other than permitted JSON whitespace, and strings over protocol limits
- reject duplicate `surfaceIds`, then sort valid values by the manifest's canonical surface order
- reject duplicate `additionalContextGroups`, then sort valid values by manifest order
- deduplicate `intent.constraints` while preserving first occurrence and user order
- deduplicate `focus.clientIds` while preserving editor selection order
- materialize optional string defaults as the manifest defines
- materialize `detailLevel`, `recentActivity`, and `content.mode` defaults
- when `recentActivity` is `outcomes_only`, materialize `recent_outcomes` in `additionalContextGroups`; when it is `none`, reject an explicitly supplied `recent_outcomes` group as contradictory
- reject `surfaceParameters.content` when `content` is not requested; otherwise materialize its normalized value
- preserve array order where it carries priority, selection, or operation meaning

Protocol 1.0 defaults are fixed:

| Field | Normalized default |
|---|---|
| `intent.audience` | `""` |
| `intent.tone` | `""` |
| `intent.constraints` | `[]` |
| `focus.clientIds` | `[]` |
| `additionalContextGroups` | `[]` |
| `detailLevel` | `"balanced"` |
| `recentActivity` | `"none"` |
| `surfaceParameters` when `content` is not requested | `{}` |
| `surfaceParameters` when `content` is requested and no mode is supplied | `{ "content": { "mode": "draft" } }` |

Semantic equality compares the canonical JSON bytes, not object identity, insertion order, or localized labels.

The canonical empty sentinel `{}` is permitted only in a newly initialized workspace. The run coordinator returns `context_not_configured` before capture if it remains present.

## 10. Workspace context capture

### 10.1 Trust split

Context capture is deliberately split:

```text
Gutenberg selectors ─> client WorkspaceContextSnapshot ─┐
                                                       ├─> server ContextEnvelopeBuilder
Server collectors/identity/permissions ────────────────┘          │
                                                                  ├─> Ability inputs
                                                                  ├─> context receipt
                                                                  └─> context signature
```

The client snapshot supplies current unsaved editor semantics that the server cannot observe. The server supplies authoritative site/user binding, saved entity identity, server-readable target data, and policy classification.

Client `can*`, lock, selection, and validity values are preflight evidence only. They never authorize an Ability or persistent target.

### 10.2 Snapshot request shape

The client collector returns an immutable value:

```json
{
  "collectorVersion": "recommendation-context-v1",
  "editorScopeKey": "post:42",
  "capturedAt": "2026-08-27T12:00:00Z",
  "seams": [
    {
      "path": "document.block_tree",
      "source": "core/block-editor",
      "disposition": "summarized",
      "strategy": "bounded_tree_v1",
      "sourceItemCount": 42,
      "value": {}
    }
  ]
}
```

The request schema is a path-discriminated closed union. Each allowed `path` has one declared `source`, value schema, maximum size, and permitted dispositions. There is no generic selector name, selector argument list, arbitrary store key, or open `values` object.

The client `capturedAt` value is provenance only. A malformed RFC 3339 value fails request validation. The server excludes it from signatures and authorization and normalizes a value more than five minutes before or after server receipt to its own receipt time. Server-collected seams always use server capture times.

The client may report `included`, `summarized`, `truncated`, or `unavailable`. It may not claim final `omitted`; the server derives omission from the requested seam set and policy. A supplied seam that is not requested is discarded before signature construction and recorded only as a bounded diagnostic count.

For `summarized`, `strategy` is not a caller-defined reason code. Each summarizable seam schema fixes its one permitted strategy string as a `const`; other dispositions forbid `strategy`. A client observation with `truncated` does not supply authoritative `limit`: the server obtains the exact positive safe-integer maximum and unit from the manifest and adds the numeric maximum to the final receipt. `sourceItemCount` is required for a truncated collection and for a summarized collection strategy, is a safe non-negative integer at least as large as the returned item count, and is forbidden otherwise.

### 10.3 Requested seam set

The server computes requested seams as the union of:

- each requested surface's mandatory context profile
- the focus profile implied by `focus.scope`
- the explicitly allowed `additionalContextGroups`
- mandatory identity, freshness, lock, permission-preflight, and operation-validation seams that optional configuration cannot remove

The initial mandatory group profiles are:

| Surface | Mandatory context groups |
|---|---|
| `block` | `document_identity`, `block_selection`, `block_constraints`, `block_registry`, `theme_tokens`, `save_publication_state`, `docs_grounding` |
| `content` | `document_identity`, `document_summary`, `save_publication_state` |
| `pattern` | `document_identity`, `block_selection`, `block_constraints`, `pattern_catalog`, `template_structure`, `save_publication_state`, `docs_grounding` |
| `navigation` | `document_identity`, `navigation_structure`, `block_constraints`, `save_publication_state`, `docs_grounding` |
| `template` | `document_identity`, `template_structure`, `pattern_catalog`, `theme_tokens`, `save_publication_state`, `docs_grounding` |
| `template_part` | `document_identity`, `template_structure`, `pattern_catalog`, `theme_tokens`, `save_publication_state`, `docs_grounding` |
| `post_blocks` | `document_identity`, `save_publication_state`, `docs_grounding` |
| `global_styles` | `document_identity`, `theme_tokens`, `theme_style_summary`, `template_structure`, `save_publication_state`, `docs_grounding` |
| `style_book` | `document_identity`, `theme_tokens`, `theme_style_summary`, `template_structure`, `block_selection`, `save_publication_state`, `docs_grounding` |

The focus profiles are also fixed:

| `focus.scope` | Added context groups |
|---|---|
| `selected_block` | `block_selection`, `block_constraints`, `block_registry` |
| `selection` | `block_selection`, `block_constraints`, `block_registry` |
| `document` | `document_summary`, `block_tree` |
| `site_editor_entity` | `template_structure`, `navigation_structure`, `theme_style_summary` |

These groups are unioned with surface-mandatory and explicitly requested groups, then deduplicated in manifest order. A group is collected for a surface only when the consumption registry later in this section gives that surface a consumer. An unavailable seam remains in the receipt as unavailable; focus never changes the declared source or substitutes a different group. A focus or optional group with no consumer among the requested surfaces is rejected as `context_configuration_invalid` rather than signed and ignored.

Mandatory capture and invocation criticality are separate. The manifest materializes an exact `hardContextPaths` array for every surface. For protocol 1.0, that array is the expansion of the surface's mandatory profile with `guidance.docs_grounding` removed; every other mandatory-profile path is hard. A hard path that is unavailable prevents that surface from starting and yields `surface_unavailable` with reason code `required_context_unavailable`. A path requested only by focus or `additionalContextGroups` is soft unless it is independently present in that surface's `hardContextPaths`; its absence remains visible in the receipt but does not block invocation. `activity.recent_outcomes` is always soft. `guidance.docs_grounding` is mandatory-capture-but-soft for its eight declared wire consumers and soft when content requests it explicitly: unavailable docs produce `workspaceContext.docsGrounding: null`, zero secondary lookup, and continued Ability execution. Tests assert the fully expanded per-surface arrays rather than recomputing this policy by convention.

The initial group-to-seam registry is closed as follows. `client` rows are the only paths accepted in `contextCapture.seams`; `server` rows are collected by `ContextEnvelopeBuilder` and a caller-supplied observation for one of those paths is discarded as unrequested input.

| Context group | Seam path | Declared source | Owner |
|---|---|---|---|
| `document_identity` | `document.identity` | `flavor-agent/editor-scope` | client |
| `document_identity` | `document.server_identity` | `flavor-agent/server` | server |
| `document_summary` | `document.summary` | `core/editor` | client |
| `document_summary` | `document.edited_content` | `core/editor` | client |
| `block_selection` | `document.block_selection` | `core/block-editor` | client |
| `block_tree` | `document.block_tree` | `core/block-editor` | client |
| `block_constraints` | `document.block_constraints` | `core/block-editor` | client |
| `block_registry` | `editor.block_registry` | `core/blocks` | client |
| `pattern_catalog` | `editor.visible_patterns` | `core/block-editor` | client |
| `pattern_catalog` | `server.pattern_catalog` | `flavor-agent/server` | server |
| `theme_tokens` | `theme.tokens` | `core/block-editor` | client |
| `theme_tokens` | `theme.server_tokens` | `flavor-agent/server` | server |
| `theme_style_summary` | `theme.style_summary` | `core` | client |
| `template_structure` | `document.template_structure` | `core/block-editor` | client |
| `navigation_structure` | `document.navigation_structure` | `core/block-editor` | client |
| `save_publication_state` | `document.save_publication_state` | `core/editor` | client |
| `recent_outcomes` | `activity.recent_outcomes` | `flavor-agent/activity` | server |
| `docs_grounding` | `guidance.docs_grounding` | `flavor-agent/docs-grounding` | server |

`flavor-agent/editor-scope` is the bounded primary-scope adapter over the relevant post editor, Site Editor, Global Styles, and Style Book inputs; it is not a public selector store. `document.summary` contains bounded unsaved semantic metadata such as title, excerpt, post type, and status. `document.edited_content` contains the bounded current unsaved block-serialized content needed by content edit/critique requests; its schema and hard limit prevent it from becoming an unrestricted selector result. A client path whose declared source is unavailable on the current editor screen is reported as `unavailable`, never silently replaced by a different source. In particular, Site Editor entities may report `document.summary`, `document.edited_content`, or `document.save_publication_state` unavailable until a source with the declared semantics exists.

The value grammar is also closed. Every object below has `additionalProperties: false`; every array has the manifest limit from section 11; every string has a path-specific maximum; all names/IDs are normalized strings; and nullability is exactly as written. `BlockRecord`, `BlockTypeRecord`, `PatternIdentity`, and `TokenRecord` are shared schema definitions:

```text
BlockRecord = {
  clientId: string,
  name: string,
  parentClientId: string | null,
  index: integer >= 0,
  depth: integer >= 0,
  serialized: string
}

BlockTypeRecord = {
  name: string,
  title: string,
  category: string,
  parent: string[],
  ancestor: string[],
  allowedBlocks: string[],
  enabledSupports: string[]
}

PatternIdentity = {
  name: string,
  title: string,
  categories: string[],
  blockTypes: string[],
  templateTypes: string[],
  inserter: boolean,
  source: string
}

TokenRecord = {
  path: string,
  value: string | number | boolean | null,
  origin: "default" | "theme" | "user"
}
```

The path-discriminated values are:

| Seam path | Exact value shape |
|---|---|
| `document.identity` | `{ editorScopeKey: string, kind: EditorScopeKind, entityId: string }` |
| `document.server_identity` | `{ editorScopeKey: string, targetKind: "post" | "template" | "template_part" | "global_styles" | "style_book" | "temporary", targetId: string | null, persistent: boolean, postType: string | null }` |
| `document.summary` | `{ postType: string, title: string, excerpt: string, slug: string, status: string }` |
| `document.edited_content` | `{ format: "block_markup", content: string }` |
| `document.block_selection` | `{ clientIds: string[], blocks: BlockRecord[] }` |
| `document.block_tree` | `{ blocks: BlockRecord[], totalCount: integer >= 0 }` |
| `document.block_constraints` | `{ rootClientId: string | null, templateLock: false | "all" | "insert" | "contentOnly" | null, canInsert: boolean, canMove: boolean, canRemove: boolean, allowedBlockTypes: string[] }` |
| `editor.block_registry` | `{ blockTypes: BlockTypeRecord[] }` |
| `editor.visible_patterns` | `{ patterns: PatternIdentity[] }` |
| `server.pattern_catalog` | `{ patterns: { name: string, title: string, categories: string[], source: string, synced: boolean, status: string }[] }` |
| `theme.tokens` | `{ tokens: TokenRecord[] }` |
| `theme.server_tokens` | `{ tokens: TokenRecord[] }` |
| `theme.style_summary` | `{ scope: "root" | "block", blockName: string | null, tokens: TokenRecord[] }` |
| `document.template_structure` | `{ entityKind: "template" | "template_part" | "post" | "temporary", entityId: string, blocks: BlockRecord[], totalCount: integer >= 0 }` |
| `document.navigation_structure` | `{ navigationId: string | null, blocks: BlockRecord[], totalCount: integer >= 0 }` |
| `document.save_publication_state` | `{ isDirty: boolean, isSaving: boolean, isAutosaving: boolean, isSaveable: boolean, hasPublishAction: boolean, status: string }` |
| `activity.recent_outcomes` | `{ outcomes: { activityId: string, surface: string, outcome: string, reasonCode: string | null, createdAt: RFC3339 string }[] }` |
| `guidance.docs_grounding` | `{ bySurface: { surfaceId: SurfaceId, queryFingerprint: 64-lowercase-hex string, disposition: "included" | "truncated" | "unavailable", reasonCode: DocsReasonCode | null, sourceItemCount: integer >= 0, items: { sourceId: string, title: string, url: string, lastModified: RFC3339 string | null, summary: string, fingerprint: 64-lowercase-hex string }[] }[] }` |

Serialized block markup is normalized and bounded per record; it is not recursively parsed into an open attribute map inside the protocol. `enabledSupports` is the sorted list of allowlisted support paths whose normalized value is truthy, never the raw block registry `supports` object. Token paths are dot-separated allowlisted theme setting/style paths, never arbitrary object traversal supplied by the caller. Recent outcomes exclude prompt, suggestion, before/after, request, and document payloads. When at least one docs consumer has usable guidance, docs grounding contains exactly one entry per declared consumer surface in canonical surface order, including unavailable entries for mixed outcomes. Each query is deterministically derived from normalized intent plus that surface's normalized seam projection, preserving its current WordPress guidance topics while eliminating a second lookup inside the Ability. Docs items are trusted-source-policy summaries and fingerprints; they exclude retrieved full text, credentials, provider diagnostics, and raw search responses.

`DocsReasonCode` is the closed set `docs_transport_unavailable`, `docs_empty`, and `docs_untrusted_or_stale`. Every docs surface entry includes its query fingerprint. Its remaining fields are cross-field exact: `included` has 1–8 items, `sourceItemCount` equal to `items.length`, and null `reasonCode`; `truncated` has exactly 8 items, `sourceItemCount` greater than 8, and null `reasonCode`; `unavailable` has zero items, `sourceItemCount: 0`, and one non-null `DocsReasonCode`. If every declared consumer is unavailable, the complete `guidance.docs_grounding` context value is absent and the receipt metadata described in section 10.4 carries all per-surface evidence.

The manifest defines one closed value schema and permitted dispositions for every row above, and maps each group to exactly these paths. Adding a seam path, changing a declared source or owner, or changing a value schema requires a collector-version change and shared fixtures. It does not silently inherit every selector Gutenberg later adds.

#### 10.3.1 Signed-context consumption registry

The context signature may attest only values that affect orchestration, authorization/preflight, or an invoked recommendation. Manifest validation therefore requires every seam path to have at least one fixed consumer, and envelope construction includes a value only when at least one requested surface resolves to one of those consumers. `unavailable` and `omitted` receipt entries carry no context value.

The eight recommendation Ability input schemas gain one optional, closed `workspaceContext` property. This is an additive compatibility field, not a ninth Ability. It has exactly these fields:

`SurfaceDocsGrounding` projects only a matching `included` or `truncated` `guidance.docs_grounding.bySurface[]` entry and contains exactly `queryFingerprint` and `items` under the same closed schema and limits. A matching `unavailable` entry, a missing entry, or an all-unavailable docs seam projects to null.

```text
workspaceContext = {
  schemaVersion: "recommendation-ability-context-v1",
  semanticSummary: string,
  recentOutcomeSummary: string,
  docsGrounding: SurfaceDocsGrounding | null
}
```

Only `schemaVersion` is required; empty optional fields are omitted except that `docsGrounding` is explicit `null` when its requested server source is unavailable. `semanticSummary` is a deterministic, bounded rendering of only the structural seam values routed to that surface. `recentOutcomeSummary` is a deterministic rendering of `activity.recent_outcomes`. Presence of `workspaceContext` identifies a run-based call and always suppresses the legacy internal docs lookup: a structured `docsGrounding` value is consumed exactly, `null` means requested but unavailable, and absence means not requested. A legacy direct caller that omits `workspaceContext` retains the current internal collection behavior.

Before a docs entry enters the envelope, a new server-only trusted-source gate requires normalized HTTPS, an exact allowlisted WordPress documentation host/path, a non-empty lowercase SHA-256 content fingerprint, and the currentness rule below. `DocsGroundingSourcePolicy` supplies labels only and is not treated as this gate. The adapter maps `sourceId` to native `id`/`sourceKey`, `summary` to `excerpt`, `fingerprint` to `contentHash`, `lastModified` to `publishedAt`, preserves the normalized URL/title, derives `sourceType` from the matching allowlist row, and creates a normal `DocsGuidanceResult` so existing attribution and fingerprints remain coherent.

The manifest owns this exact ordered allowlist; hosts are exact, not suffix matches:

| `sourceType` | Exact host | Accepted normalized path | Currentness |
|---|---|---|---|
| `developer-docs` | `developer.wordpress.org` | `/block-editor/`, `/rest-api/`, `/themes/`, or `/reference/`, including descendants | Stable; `lastModified` may be null; a supplied value must not be in the future |
| `developer-blog` | `developer.wordpress.org` | Dated document `^/news/[0-9]{4}/[0-9]{2}/(?:[0-9]{2}/)?[a-z0-9][a-z0-9-]*/$` | Time-sensitive |
| `make-core` | `make.wordpress.org` | Dated document `^/core/[0-9]{4}/[0-9]{2}/[0-9]{2}/(?!xpost-)[a-z0-9][a-z0-9-]*/$` | Time-sensitive |
| `make-ai` | `make.wordpress.org` | Dated document `^/ai/[0-9]{4}/[0-9]{2}/[0-9]{2}/(?!xpost-)[a-z0-9][a-z0-9-]*/$` | Time-sensitive |
| `wordpress-news` | `wordpress.org` | Dated document `^/news/[0-9]{4}/[0-9]{2}/[a-z0-9][a-z0-9-]*/$` | Time-sensitive |

URL normalization trims surrounding ASCII whitespace, parses one absolute URI, lowercases scheme and host, requires `https`, rejects user info, query, fragment, a trailing-dot host, any host other than the three exact values above, and every explicit port except `443` (which is removed). The raw path must have valid percent escapes, one leading slash, no repeated slash, and no backslash or control character. Each segment is percent-decoded exactly once for validation; a decoded slash/backslash/control, invalid UTF-8, or `.`/`..` segment is rejected. Remaining escapes use uppercase hex, path matching is case-sensitive, and one trailing slash is materialized before allowlist matching. Sibling domains, subdomains, encoded traversal/separators, archive/listing/tag paths, and unmatched roots fail closed.

For a time-sensitive row, `lastModified` is required, must be a valid server-normalized timestamp no later than the envelope's captured time, and must be at most `15,552,000` seconds (180 days) old. A stable row may use null; a supplied timestamp is still rejected when future-dated. Filtering every candidate is a soft per-surface docs-source failure: that surface receives the `docs_untrusted_or_stale` disposition, its Ability receives null docs, and recommendation generation continues. Transport and empty-result failures use the other two closed docs reason codes. Mixed outcomes are aggregated into one seam receipt by section 10.4; they never create duplicate receipt paths.

The fixed seam-to-consumer mapping is:

| Seam path | Native/preflight consumers | Other permitted consumer |
|---|---|---|
| `document.identity` | Every surface: validate scope and build its existing `document`/target fields | None |
| `document.server_identity` | Every surface: authoritative target and permission preflight | None |
| `document.summary` | `content.postContext`; document-bearing template/navigation/style inputs | `workspaceContext.semanticSummary` for `block`, `pattern`, `navigation`, `template`, `template_part`, `global_styles`, or `style_book` when explicitly/focus requested |
| `document.edited_content` | `content.postContext.content` only | None |
| `document.block_selection` | `block.selectedBlock`/`clientId`/`editorContext`; `pattern.blockContext`; `style_book.styleContext` | `workspaceContext.semanticSummary` for the same three surfaces only |
| `document.block_tree` | Template, template-part, navigation, block, pattern, and style structural input fields where their registered adapter declares the group | `workspaceContext.semanticSummary` for those same surfaces; never `post_blocks` |
| `document.block_constraints` | `block.editorContext`, `pattern.insertionContext`, and `navigation.editorContext` | `workspaceContext.semanticSummary` for those three surfaces |
| `editor.block_registry` | `block.editorContext` and `pattern.blockContext` | `workspaceContext.semanticSummary` for `block` and `pattern` |
| `editor.visible_patterns` | `pattern.visiblePatternNames`; template/template-part pattern-name inputs | `workspaceContext.semanticSummary` for `pattern`, `template`, and `template_part` |
| `server.pattern_catalog` | Pattern/template/template-part server-backed candidate input | `workspaceContext.semanticSummary` for `pattern`, `template`, and `template_part` |
| `theme.tokens` | Block/template/template-part/style context fields | `workspaceContext.semanticSummary` for `block`, `template`, `template_part`, `global_styles`, and `style_book` |
| `theme.server_tokens` | Same five server-backed context projections | `workspaceContext.semanticSummary` for those same surfaces |
| `theme.style_summary` | `global_styles.styleContext` and `style_book.styleContext` | `workspaceContext.semanticSummary` for those two surfaces |
| `document.template_structure` | Pattern/template/template-part/style structural fields | `workspaceContext.semanticSummary` for `pattern`, `template`, `template_part`, `global_styles`, and `style_book` |
| `document.navigation_structure` | Navigation target, markup, and editor-context fields | `workspaceContext.semanticSummary` for `navigation` only |
| `document.save_publication_state` | Every surface: freshness/preflight only | None |
| `activity.recent_outcomes` | None | `workspaceContext.recentOutcomeSummary` for every requested surface |
| `guidance.docs_grounding` | None | The matching per-surface entry becomes `workspaceContext.docsGrounding` for each declared consumer |

No generic path/value bag reaches an Ability. The manifest stores the table as closed per-path surface allowlists plus a named destination. `SurfaceInputAdapter` rejects a projection if a requested included value has no destination, and tests spy on every Ability to prove the projected field is read by its native adapter, prompt/context builder, permission preflight, or freshness check.

`post_blocks` is deliberately narrower. Its current Ability uses a fresh server-collected saved post context and produces its own resolved/review signatures. The shared collector therefore does not route live client block trees, constraints, pattern catalogs, or theme tokens to that surface. Before invocation, `document.save_publication_state` MUST show `isDirty: false`, `isSaving: false`, `isAutosaving: false`, and `isSaveable: true`; otherwise the surface is `unavailable` with reason `unsaved_editor_content` and the Ability is not executed. This prevents a shared signature over unsaved client structure from being presented as the saved structure consumed by `recommend-post-blocks`. `docs_grounding` is mandatory to preserve its existing guidance; optional `recent_outcomes` is permitted because the revised Ability consumes that bounded projection. The Ability's existing server context and surface-specific signatures remain authoritative for its document structure.

### 10.4 Receipt construction

`ContextEnvelopeBuilder` owns the final receipt. For every requested seam path it selects exactly one category in this order:

1. `unavailable` when the required source cannot be resolved or authorized
2. `omitted` when allowed policy or optional configuration excludes an available optional seam
3. `truncated` when the bounded value reached its declared hard limit
4. `summarized` when the declared summary strategy replaced the source representation
5. `included` when the normalized bounded value was supplied directly

The builder rejects duplicate path observations, source/path mismatches, invalid category metadata, and any final receipt that is not an exact partition. It sorts receipt entries by manifest seam order and sorts each entry's `consumerSurfaceIds` by canonical surface order.

Final receipt metadata is category-exact. `summarized` requires the manifest-fixed `strategy`; `truncated` requires numeric `limit` and `sourceItemCount` and forbids `strategy`; `omitted` and `unavailable` require `reasonCode`; `included` forbids all four generic category-only fields (`strategy`, `limit`, `sourceItemCount`, and `reasonCode`). The server derives `limit`, never the caller. It is a positive safe integer whose unit is fixed by the seam definition. The path-specific docs metadata below does not relax those generic top-level rules.

`guidance.docs_grounding` retains one receipt entry for the seam while exposing its multiplexed per-surface result. That entry always has a closed `surfaceDispositions` array with exactly one member for every surface in its `consumerSurfaceIds`, in the same canonical order:

```text
DocsSurfaceDisposition = {
  surfaceId: SurfaceId,
  disposition: "included" | "truncated" | "unavailable",
  reasonCode: DocsReasonCode | null,
  sourceItemCount: integer >= 0,
  limit?: 8
}
```

An `included` member has `sourceItemCount` in 1–8, null `reasonCode`, and no `limit`. A `truncated` member has `sourceItemCount` greater than 8, null `reasonCode`, and `limit: 8`. An `unavailable` member has `sourceItemCount: 0`, a non-null `DocsReasonCode`, and no `limit`. When a docs context value exists, these dispositions and counts exactly equal its `bySurface` metadata.

The single path-level category is deterministic. It is `unavailable` with top-level `reasonCode: docs_grounding_unavailable` only when every member is unavailable; in that case the context value is absent. It is `truncated` when at least one member is truncated; the top level has `limit: 8` and `sourceItemCount` equal to the safe-integer sum of every member's pre-truncation usable count. Otherwise it is `included` because at least one member is included, even when other members are unavailable. `summarized` and `omitted` are not permitted for this seam in protocol 1.0. Thus a mixed outcome changes neither the exact one-entry partition nor an unavailable surface's null Ability projection.

The numeric limits and their units are:

| Truncatable seam paths | `limit` unit |
|---|---|
| `document.block_selection`, `document.block_constraints`, `editor.block_registry`, `editor.visible_patterns`, `server.pattern_catalog`, `activity.recent_outcomes` | Items; respectively 20, 256, 256, 100, 100, and 20 |
| `guidance.docs_grounding` | Items per surface; 8 |
| `document.block_tree`, `document.template_structure`, `document.navigation_structure` | Nodes; respectively 200, 200, and 100 |
| `theme.tokens`, `theme.server_tokens`, `theme.style_summary` | Token leaves; 500 |

No other seam permits `truncated` in protocol 1.0. Oversized identity/save-state values are invalid, `document.edited_content` follows its explicit reject boundary, and `document.summary` uses its declared summary strategy. Shared fixtures prove the numeric `limit` and manifest unit for every truncatable seam.

Only the primary top-level collection/node/leaf count named in that table can produce `truncated`. If a nested record field must be reduced—such as one block type's support lists, one pattern's taxonomy lists, one block's serialized form, or one token's string value—the complete seam is `summarized` with its manifest-fixed strategy instead. This keeps one numeric `limit` unambiguous.

Receipt provenance is public. Context values are not copied into the receipt.

### 10.5 Context signature

The server captures the signature object's authoritative `capturedAt` while building the final envelope, then computes `contextSignature.value` as lowercase SHA-256 over RFC 8785 canonical JSON bytes for:

```json
{
  "signatureVersion": "recommendation-context-signature-v1",
  "protocolVersion": "1.0",
  "binding": {
    "siteScopeId": "1",
    "userId": "7"
  },
  "editorScopeKey": "post:42",
  "contextConfiguration": {},
  "context": {},
  "receiptDisposition": [
    {
      "path": "document.identity",
      "category": "included",
      "reasonCode": null
    }
  ],
  "collectorVersion": "recommendation-context-v1"
}
```

`binding` is never exported. Timestamps, random IDs, `actorSessionId`, `idempotencyKey`, transient editor-shell state, and `never_expose` values are excluded.

For protocol 1.0, `siteScopeId` is the canonical base-10 string form of the current blog ID captured once from `RecommendationRunStorageContext`. It is never derived from a request field, URL, host header, mutable ambient `$wpdb` prefix, or network ID.

The signature is computed after server normalization and before any Ability invocation. The public object contains only:

```json
{
  "algorithm": "sha256",
  "collectorVersion": "recommendation-context-v1",
  "value": "64-lowercase-hex-characters",
  "capturedAt": "2026-08-27T12:00:00Z"
}
```

The signature does not replace surface-specific review, resolved-context, target, lock, or baseline signatures returned by the existing Abilities.

### 10.6 Persistence rule

The complete shared context object is request-lifetime only. A terminal run stores:

- the intent snapshot in the public run and the normalized complete context configuration in the private payload
- the public receipt
- the public context signature
- per-surface bounded Ability input projections needed to interpret or later revalidate that result
- native Ability outputs and future executor bindings in the private payload

It MUST NOT store an unrestricted copy of the combined Gutenberg/server context envelope, raw recent activity, credentials, arbitrary selector output, or registry state.

## 11. Context and payload limits

These are hard protocol 1.0 implementation limits. The shared manifest owns the values.

String lengths below are Unicode code points after normalization; byte limits are UTF-8 byte counts over canonical JSON or the named string. ASCII regexes are applied to the complete normalized string. Enum/const-valued strings are bounded by their literal schema and are not repeated in the tables. Any non-enum protocol string that is not covered by a row below is invalid; implementations may not invent an implicit fallback limit.

| Value | Limit | Overflow behavior |
|---|---:|---|
| Internal run-create JSON request | 1 MiB UTF-8 | Reject before reservation |
| Complete client seam values | 768 KiB UTF-8 canonical JSON | Summarize/truncate by seam policy or reject malformed request |
| `document.edited_content` seam value | 576 KiB canonical JSON, including wrapper/escaping | Reject capture if the 256 KiB raw content cannot fit |
| Any other one client seam value | 256 KiB canonical JSON | Use declared summary/truncation; otherwise mark unavailable |
| One server seam value | 2 MiB canonical JSON | Mark unavailable or fail the affected surface; never truncate outside declared policy |
| Complete normalized context envelope | 4 MiB canonical JSON | Fail before any Ability invocation; retain only the fenced building reservation |
| Receipt entries | 18 | Internal invariant failure if the closed partition differs |
| Bounded non-navigation block/template tree | 200 nodes, depth 8 | `summarized` or `truncated` |
| Selected/focused client IDs | 20 | Configuration validation error |
| Pattern/catalog identities | 100 | `truncated` |
| Navigation items | 100 | `truncated` |
| Theme token leaf values | 500 | `summarized` or `truncated` |
| Recent outcome summaries | 20 | `truncated`; never include prompt/result content |
| Units in one surface result | 25 | Surface fails with `result_too_large` |
| Units in one run | 100 | Excess surface fails deterministically |
| One native Ability result | 1 MiB canonical JSON | That surface is `failed` |
| Public terminal run payload | 512 KiB canonical JSON | Finalization fails closed |
| Private terminal binding payload | 4 MiB canonical JSON | Finalization fails closed |
| Unit title | 160 characters | Truncate with warning |
| Unit summary | 1,000 characters | Truncate with warning |
| Public error/warning message | 240 characters | Truncate without provider payload |

Protocol identifier and request-string schemas are fixed:

| Field/path family | Length | Pattern/format |
|---|---:|---|
| `workspaceId`, `runId`, `editorInstanceId`, `actorSessionId` | 36 exactly | Both JSON Schema `uuid` format and canonical lowercase UUIDv4 pattern `^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$` |
| `idempotencyKey` | 16–128 | `^[A-Za-z0-9][A-Za-z0-9._~:-]{15,127}$` |
| `resultRef` | 35 exactly | `^rr_[0-9a-f]{32}$` |
| `unitId` | 35 exactly | `^ru_[0-9a-f]{32}$` |
| `editorScope.key` | 1–191 | Must be reconstructed exactly from the kind-specific grammar in section 7.1; no generic fallback regex |
| Safe decimal persistent entity/navigation ID | 1–16 | `^[1-9][0-9]{0,15}$` and numeric value at most `9007199254740991` |
| Template entity reference | 1–179 | `^[A-Za-z0-9._~-]+//[A-Za-z0-9._~-]+$`; prefix plus ref must fit the 191-character scope key |
| Template-part entity reference | 1–174 | Same reference pattern; prefix plus ref must fit the 191-character scope key |
| Registered post-type slug | 1–20 | `^[a-z0-9_][a-z0-9_-]{0,19}$` |
| Entity-kind/status/category/source slug | 1–64 | `^[a-z0-9][a-z0-9_-]{0,63}$` |
| Gutenberg block name | 3–128 | `^[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*$` |
| Pattern name | 1–191 | `^(?:[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*|core/block/[1-9][0-9]{0,15})$` |
| Gutenberg client/root/parent ID | 1–128 | `^[^\u0000-\u001F\u007F-\u009F]+$`; no path interpretation |
| `activityId`, native stable key, or docs source ID | 1–191 | Same printable-scalar pattern; invalid UTF-8 and lone surrogates are rejected |
| Error/reason/warning code | 1–64 | Strict wire snake case: `^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$` |
| SHA-256/fingerprint/signature/digest | 64 exactly | `^[0-9a-f]{64}$` |
| Caller RFC 3339 timestamp | 20–29 | Exact regex from the timestamp rule below plus semantic calendar validation |
| Server/public RFC 3339 timestamp | 20 exactly | UTC seconds: `^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]Z$` plus semantic calendar validation |
| Private `siteScopeId`/`userId` decimal string | 1–20 | `^[1-9][0-9]{0,19}$`, value at most unsigned BIGINT; these are not JavaScript integer fields |

Configuration and context strings are fixed:

| Field/path family | Maximum |
|---|---:|
| `intent.goal` | 1,000 code points; minimum 1 after trim |
| `intent.audience` | 300 code points |
| `intent.tone` | 200 code points |
| One `intent.constraints[]` entry | 240 code points; minimum 1 after trim |
| Document/pattern/block/unit title or label | 300 code points for document titles; 160 for all catalog/block/unit labels |
| Document excerpt or public/native bounded summary | 1,000 code points |
| Document slug | 0–200 code points; `^(?:[A-Za-z0-9._~-]|%[0-9a-f]{2})*$` after lowercase percent-hex normalization |
| `document.edited_content.content` | 256 KiB UTF-8 |
| One `BlockRecord.serialized` | 32 KiB UTF-8 |
| Pattern/template/navigation markup is not a public seam string | Not permitted outside the explicitly bounded edited-content or block-record fields |
| Token path or enabled-support path | 256 code points; dot-separated `^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$` |
| String-valued token | 1,000 code points |
| Docs `sourceId` | 128 code points; printable non-control Unicode |
| Docs title | 200 code points |
| Docs URL | 2,048 code points; absolute HTTPS URI passing the server trusted-source policy |
| Docs summary | 360 code points |
| `workspaceContext.semanticSummary` | 16 KiB UTF-8 |
| `workspaceContext.recentOutcomeSummary` | 4 KiB UTF-8 |

Caller timestamps use exactly `^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]{1,3})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])$`. Both runtimes additionally reject impossible calendar dates and leap seconds. Accepted offsets and fractional seconds are normalized to UTC whole seconds wherever the server owns output; parser-specific rollover such as February 30 is forbidden.

Collection schemas are fixed:

| Collection | Maximum items |
|---|---:|
| `surfaceIds` | 9, minimum 1, unique before canonical sorting |
| `intent.constraints` | 12 |
| `focus.clientIds` | `selected_block`: 0–1; `selection`: 0–20; `document`/`site_editor_entity`: exactly 0; unique and selection-ordered |
| `additionalContextGroups` | 14, unique before manifest-order sorting |
| Client capture seams / final receipt entries | 13 / 18 |
| Block/template tree | 200 nodes, maximum depth 8 |
| Navigation structure | 100 nodes, maximum depth 8 |
| Block-selection `clientIds` / `blocks` | 20 each; same count/order, IDs unique |
| `editor.block_registry.blockTypes` | 256, unique `name` |
| One block type's `parent`, `ancestor`, `allowedBlocks`, or `enabledSupports` list | 64 each, unique |
| `document.block_constraints.allowedBlockTypes` | 256, unique |
| Client/server pattern identities | 100, unique `name` |
| One pattern's categories, block types, or template types | 20 each, unique |
| Theme/style token records | 500, unique normalized `(path, origin)` |
| Recent outcome records | 20 |
| Docs grounding surface entries / items per entry | 9 / 8 |
| Docs receipt `surfaceDispositions` | 1–9, exactly the docs consumer surfaces once in canonical order |
| `RecommendationRun.results` | 1–9, exactly the requested surfaces once in canonical order |
| One `SurfaceResult.contextPaths` | 18, unique in seam order |
| One receipt category / complete receipt partition | 18 / exactly the requested paths once, at most 18 |
| Receipt `consumerSurfaceIds` | 1–9, unique in canonical order |
| Signature `receiptDisposition` | 18, unique path in seam order |
| Units per surface / run | 25 / 100 |
| Public warnings / dependencies on one unit | 20 / 25; dependencies unique, known, and not self-referential |

Every protocol integer, including revisions, request tokens, entity IDs, counts, indices, and source-item counts, is in `0..9007199254740991`; fields representing a persistent entity require at least `1`. Tree depth is additionally capped at `8`. A numeric token is finite and its absolute value is at most `1000000000000000`; `NaN` and infinities are rejected, while negative zero is valid input and canonicalizes to `0` under RFC 8785. Every tree `totalCount` is at least its returned record count and remains within the safe-integer range.

Shared records and derived projections have these additional constraints:

| Record/field | Contract |
|---|---|
| `BlockRecord` | `clientId`/nullable parent use the client-ID grammar; `name` uses BlockName; `index` is safe non-negative; `depth` is `0..8`; `serialized` is 0–32 KiB UTF-8 |
| `BlockTypeRecord` | `name` is BlockName; `title` is 1–160; `category` is a slug; all four string lists follow the collection rules above |
| `PatternIdentity` | `name` uses PatternName; `title` is 1–160; `source` is a slug; lists follow the collection rules above |
| `TokenRecord` | `path` is 1–256 and matches the token-path regex; string value is 0–1,000, numeric value follows the finite-number bound |
| Derived Ability `prompt` | At most 5,000 code points after intent and guidance composition; adapter failure, never truncation of normalized intent |
| `nativeStableKey` | 1–191 printable scalar and only from an adapter-declared stable property; otherwise use the canonical ordinal string |
| Unit `title` / `summary` | Title 1–160; summary 0–1,000 |
| Unit `executionClass` | Exactly `advisory` or `stage_only`; `executionBinding` is forbidden |
| Unit `operationCount` | Content/navigation/pattern `0`; block `0..1`; template `0..4`; template-part/post-blocks `0..3`; Global Styles/Style Book `0..25` |
| Unit warning | Closed `{ code, severity, message? }`; severity is `rejected`, `downgraded`, or `no_op`; message at most 240 |
| Surface error | Closed `{ code, category, message, retryDisposition, reasonCode }`; `reasonCode` is a wire Code or null and message is at most 240 |
| Surface readiness | `ready` requires `error: null`; `failed`/`unavailable` require a surface error |
| Surface freshness signatures | Both declared fields are required `string|null`; any non-null value is exactly lowercase SHA-256 |
| `resultRef` | Required and deterministic for every result, including `failed` and `unavailable` |

The registered Ability schemas remain compatibility schemas and may be intentionally open or wider. They do not validate the protocol projection. `SurfaceInputAdapter`, `WorkspaceContextGuidance`, and `SurfaceResultAdapter` independently enforce every closed limit above before invocation, hashing, or persistence. For selected-block input, the adapter also honors the current narrower projection limits of 3 siblings, 6 structural items, and depth 2; a larger shared tree may feed only the bounded semantic summary.

Limits are applied after normalization and before hashing or persistence. Detail level changes summary density inside these ceilings; it never raises them.

Surface adaptation maintains cumulative public/private byte and unit counts in canonical surface order. If adding one otherwise valid surface would exceed a run-wide limit, that surface becomes `failed` with `result_too_large` and contributes only its bounded error entry; later surfaces are still evaluated against the remaining budget. A final payload that still exceeds its cap indicates an adapter/accounting defect and fails terminal finalization rather than truncating immutable JSON after hashing.

## 12. Surface registry and result adaptation

### 12.1 Fixed registry

`FlavorAgent\Recommendations\Runs\SurfaceRegistry` is closed for protocol 1.0:

| Wire surface | Recommendation Ability | Invocation discriminator | Interaction mode | Spec 1 unit policy |
|---|---|---|---|---|
| `block` | `flavor-agent/recommend-block` | Selected/focused block context | `multi_select_batch` | Existing validated local units are `stage_only`; explanatory units may be `advisory` |
| `content` | `flavor-agent/recommend-content` | `content.mode` | `advisory` | `advisory` |
| `pattern` | `flavor-agent/recommend-patterns` | Pattern ranking context | `ranked_choice` | `stage_only` until Spec 3 compiler proves a governed binding |
| `navigation` | `flavor-agent/recommend-navigation` | Selected navigation scope | `advisory` | `advisory` |
| `template` | `flavor-agent/recommend-template` | Exact template scope | `single_review` | Mutation-shaped units are `stage_only`; explanation-only units are `advisory` |
| `template_part` | `flavor-agent/recommend-template-part` | Exact template-part scope | `single_multi_operation` | Mutation-shaped units are `stage_only`; explanation-only units are `advisory` |
| `post_blocks` | `flavor-agent/recommend-post-blocks` | Exact post/page scope | `single_multi_operation` | Mutation-shaped units are `stage_only`; explanation-only units are `advisory` |
| `global_styles` | `flavor-agent/recommend-style` | Closed Global Styles scope | `single_multi_operation` | Mutation-shaped units are `stage_only`; explanation-only units are `advisory` |
| `style_book` | `flavor-agent/recommend-style` | Closed Style Book block scope | `single_multi_operation` | Mutation-shaped units are `stage_only`; explanation-only units are `advisory` |

If both style surfaces are requested, the registry invokes `flavor-agent/recommend-style` twice with two independently validated scopes and emits two result entries.

`post_blocks` is both a genuine generating surface and a future executor target. Requesting it invokes its Ability and produces its own result. A future governed pattern binding does not create an unrequested extra result.

The future governed lane identifiers are reserved now so Spec 3 does not depend on display text or a later guess:

| Executor surface | Executor Ability | Operation schema version |
|---|---|---|
| `template` | `flavor-agent/request-template-apply` | `template-v1` |
| `template_part` | `flavor-agent/request-template-part-apply` | `template-part-v1` |
| `post_blocks` | `flavor-agent/request-post-blocks-apply` | `post-blocks-v1` |
| `global_styles` | `flavor-agent/request-style-apply` | `style-v1` |
| `style_book` | `flavor-agent/request-style-apply` | `style-v1` |

Spec 1 never emits those identifiers in a public unit and never emits `governed_apply`. Spec 3 defines the exact private binding envelope and operation digest for those version names. It does not rename them.

### 12.2 Input adapters

Each registry entry owns a typed `SurfaceInputAdapter`. It receives only:

- normalized `ContextConfiguration`
- normalized server context envelope
- exact editor scope
- run/actor diagnostic metadata

It returns the existing Ability's registered input shape. It MUST NOT accept an Ability ID or free-form operation payload from the REST caller.

The adapter builds `document`, scope, target, prompt, `clientRequest`, `workspaceContext`, and surface fields from allowlisted context. It follows the signed-context consumption registry in section 10.3.1 and rejects an included value that has no declared destination. `clientRequest.sessionId` uses the bounded `actorSessionId`, `abortId` uses the run ID, and `scopeKey` uses the validated editor scope. Diagnostic metadata is not authority.

The six preview Ability schemas derived from recommendation schemas explicitly remove `workspaceContext`; previews are not run consumers and must not advertise an input they ignore. Existing recommendation diagnostic activity also strips `semanticSummary`, `recentOutcomeSummary`, and docs items before persistence. It may retain only the workspace-context schema version plus query/content fingerprints and counts. The complete bounded projection remains only in the short-lived private run payload.

`clientRequest.requestToken` uses the non-negative `baseWorkspaceRevision`. Ability and surface identity remain part of the existing server transient key, so two style invocations do not overwrite each other's result classification. A later workspace revision naturally produces a newer token.

### 12.3 Ability invocation

For each requested surface in canonical order, the service:

1. validates target identity for that surface
2. enforces the surface's freshness prerequisites, including the clean-editor predicate for `post_blocks`
3. resolves the exact fixed Ability with `wp_get_ability()`
4. validates the adapted input against the Ability's registered input schema
5. classifies a missing Ability or failed prerequisite/permission preflight as `unavailable`
6. calls `WP_Ability::execute()` with the adapted input
7. classifies an attempted execution error as `failed`
8. independently validates and bounds the successful output through the registered Ability output schema
9. passes the result to the fixed `SurfaceResultAdapter`

The workflow cannot bypass, weaken, or cache an Ability permission decision. The explicit input and output validation around `execute()` is defense in depth for WordPress filters that can short-circuit Ability execution; it does not replace the registered `WP_Ability` path or make a direct callback valid.

Recommendation execution is logically read-only with respect to the target document. Existing request-diagnostic activity logging remains governed by `RecommendationAbilityExecution`; Spec 1 does not relabel that existing diagnostic write as target mutation or suppress it to make a hint look read-only.

### 12.4 Public result and private binding

The result adapter produces both:

```text
Public SurfaceResult
Private SurfaceBinding
```

The public result matches protocol section 13.4. The private binding contains:

- `resultRef`
- source surface and exact recommendation Ability ID
- digest of the normalized Ability input
- the allowlisted per-surface input projection needed for later freshness/executor work
- the validated native Ability output
- unit-to-native-result mapping
- exact existing resolved/review signatures and target evidence when present
- a binding schema version

It never stores credentials, arbitrary shared context, or client-supplied operations.

`resultRef` is generated as:

```text
rr_ + first 32 lowercase hex characters of
sha256("result-ref-v1\0" + runId + "\0" + surfaceId)
```

`unitId` is generated as:

```text
ru_ + first 32 lowercase hex characters of
sha256("unit-id-v1\0" + resultRef + "\0" + nativeStableKey + "\0" + ordinal)
```

`nativeStableKey` uses the existing suggestion/recommendation key when valid, otherwise the canonical ordinal string. Including the ordinal prevents duplicate native labels from colliding. IDs are opaque handles, not authorization.

### 12.5 Result completeness and run status

The terminal public run contains exactly one result for every requested surface in canonical order.

- A successful surface with zero units is `ready`.
- A surface prevented before Ability execution is `unavailable`.
- A surface whose Ability or adapter was attempted and failed is `failed`.
- At least one `ready` and at least one non-ready result produces run status `partial`.
- All results `ready` produces `ready`.
- No result `ready` produces `failed`.

Spec 1 emits only `advisory` and `stage_only` units. It does not possess the exact executor binding predicates or operation digest needed to classify any unit as `governed_apply`. Spec 3 may promote an eligible unit only before terminal finalization; a completed Spec 1 run is never retroactively modified.

### 12.6 Unit extraction

Surface adapters extract public units deterministically:

| Surface | Native-to-unit rule |
|---|---|
| `block` | One unit per validated selectable suggestion; existing recommendation-set membership is retained as bounded warning/grouping metadata, not a second unit |
| `content` | One advisory unit for the complete draft/edit/critique result; an empty valid result produces zero units |
| `pattern` | One ranked unit per returned pattern recommendation, keyed by canonical pattern identity and ordinal |
| `navigation` | One advisory unit per native suggestion |
| `template` | One unit per native suggestion; its ordered operations stay private and determine `operationCount` |
| `template_part` | One unit per native suggestion; its ordered operations stay private and determine `operationCount` |
| `post_blocks` | One unit per native suggestion; its ordered operations stay private and determine `operationCount` |
| `global_styles` | One unit per native style suggestion for the exact root scope |
| `style_book` | One unit per native style suggestion for the exact block scope |

The adapter maps a safe native label to `title`, a safe description/reason to `summary`, known validation reasons to bounded warnings, and initializes `dependencies` to an empty list unless a registered adapter can prove a dependency from schema-valid native data. Surface-level explanation and diagnostics remain private unless the public protocol explicitly defines a bounded field for them.

Spec 1 public units contain no `executionBinding`. Native operations, targets, signatures, pattern content, and request fields remain in the private `SurfaceBinding` so Spec 3 can validate and compile them before a future run finalizes.

## 13. Server run model

### 13.1 Public terminal payload

The public JSON is the canonical protocol `RecommendationRun`:

```json
{
  "protocolVersion": "1.0",
  "runId": "uuid",
  "workspaceId": "uuid",
  "baseWorkspaceRevision": 6,
  "status": "partial",
  "createdAt": "2026-08-27T12:00:00Z",
  "completedAt": "2026-08-27T12:00:05Z",
  "expiresAt": "2026-08-27T12:30:05Z",
  "intent": {},
  "contextSignature": {},
  "contextReceipt": {},
  "results": []
}
```

The public run has no `applyPlan`. It has no internal `building` status.

### 13.2 Internal storage states

The repository uses a separate closed storage enum:

```text
building
terminal
tombstone
```

- `building` is an idempotency reservation and generation lease, not a public run status.
- `terminal` contains one immutable public/private payload pair and wire status `ready`, `partial`, or `failed`.
- `tombstone` contains only minimal expired-run metadata and no result content.

The wire value `expired` is projected from authoritative time. A read does not need the row to have been physically converted to `tombstone`.

### 13.3 Server classes

| Target class | Responsibility |
|---|---|
| `Support\CanonicalJson` | RFC 8785 validation/serialization shared by context and later operation digests |
| `Recommendations\Protocol\V1Contract` | Shared manifest loading and schema access |
| `Recommendations\Context\ContextConfiguration` | PHP normalization and canonical bytes |
| `Recommendations\Context\ContextEnvelopeBuilder` | Requested seam set, server collection, exact receipt partition |
| `Recommendations\Context\ContextSignature` | Signature envelope and digest |
| `Recommendations\Runs\SurfaceRegistry` | Closed surface-to-Ability mapping |
| `Recommendations\Runs\AbilityInvoker` | `wp_get_ability()` resolution, preflight classification, `execute()` |
| `Recommendations\Runs\SurfaceResultAdapter` | Public/private result pair creation |
| `Recommendations\Runs\RecommendationRunService` | Request orchestration, status derivation, terminal finalization |
| `Recommendations\Runs\RecommendationRunRepository` | Schema, reservations, leases, immutable rows, prune operations |
| `Recommendations\Runs\RecommendationRunStorageContext` | Immutable database/blog/table owner across multisite switches |
| `Recommendations\Runs\RunAvailabilityProjector` | Pure active/expired/not-found projection |
| `REST\RecommendationRunsController` | Authenticated create/read routes and closed schemas |

No class above belongs under `Abilities\` because it composes Abilities as workflow rather than defining another domain Ability.

## 14. Run storage

### 14.1 Table and lifecycle

The plugin adds one per-site table:

```text
{$wpdb->prefix}flavor_agent_recommendation_runs
```

It uses:

```text
schema option: flavor_agent_recommendation_run_schema_version
prune hook:    flavor_agent_prune_recommendation_runs
```

The initial table shape is:

| Column | Type | Purpose |
|---|---|---|
| `id` | unsigned bigint, primary auto-increment | Internal key |
| `run_id` | varchar(64), unique | Opaque public run ID |
| `workspace_id` | varchar(64) | Page workspace binding |
| `user_id` | unsigned bigint | Authenticated owner |
| `protocol_version` | varchar(16) | Pinned run protocol |
| `editor_scope_key` | varchar(191) | Validated primary scope |
| `base_workspace_revision` | unsigned bigint | Page revision captured for installation |
| `context_configuration_digest` | char(64), nullable | Canonical normalized configuration digest; stripped at tombstone conversion |
| `context_signature` | char(64), nullable | Server context signature value; populated under the generation lease and stripped at tombstone conversion |
| `idempotency_scope_digest` | char(64), unique | Hash of site/user/workspace/raw-key scope used to detect key reuse |
| `generation_binding_digest` | char(64) | Hash of the canonical protocol generation binding |
| `storage_state` | varchar(24) | `building`, `terminal`, or `tombstone` |
| `wire_status` | varchar(16), nullable | Terminal `ready`, `partial`, or `failed` |
| `lease_token_hash` | char(64), nullable | Internal generation fencing token |
| `lease_expires_at` | datetime, nullable | Stale-reservation takeover deadline |
| `public_payload_json` | longtext, nullable | Immutable public terminal run |
| `private_binding_json` | longtext, nullable | Immutable private native bindings |
| `public_payload_digest` | char(64), nullable | Immutability/corruption check |
| `private_binding_digest` | char(64), nullable | Immutability/corruption check |
| `created_at` | datetime | Reservation time |
| `completed_at` | datetime, nullable | Terminal finalization time |
| `expires_at` | datetime, nullable | Active deadline |
| `tombstone_until` | datetime, nullable | Minimal-retention deadline |
| `updated_at` | datetime | Internal reservation/tombstone maintenance |

Required indexes are:

- unique `run_id`
- unique `idempotency_scope_digest`
- `(user_id, workspace_id)`
- `(storage_state, lease_expires_at)`
- `(storage_state, expires_at)`
- `(storage_state, tombstone_until)`

The repository follows the existing Activity repository conventions for `dbDelta()`, non-autoloaded schema version, activation install, early `init` upgrade, daily prune schedule, deactivation hook cleanup, and uninstall table/option removal.

All stored datetimes are UTC with one-second precision. API timestamps are RFC 3339 UTC strings derived from those values. Each reservation, finalization, projection, or prune batch captures its injected authoritative clock once so a single operation cannot straddle its own deadline comparisons.

`RecommendationRunStorageContext` captures the current `$wpdb` object, prefix, table name, options table, blog ID, and site URL before a multi-step repository operation. No ambient `switch_to_blog()` may redirect an in-flight reservation, finalization, or prune operation.

### 14.2 Why this is not the activity table

A run is short-lived generated evidence. An activity row is a durable governed request/outcome with a separate 90-day retention and undo lifecycle. Combining them would:

- make read-only generation create misleading apply activity
- couple 30-minute result expiry to 90-day audit retention
- overload activity execution states with internal generation leases
- make run tombstone cleanup risk apply/undo evidence

No activity schema migration is part of Spec 1.

### 14.3 Terminal immutability

Finalization is one conditional update:

```text
WHERE run_id = ?
  AND storage_state = 'building'
  AND lease_token_hash = ?
```

Before issuing the update, the repository requires a non-empty context signature, complete receipt, exactly one result per requested surface, valid terminal wire status, both bounded payloads, and both matching payload digests. It then writes both payloads, both digests, terminal wire status, `completed_at`, `expires_at`, and `tombstone_until` together. If exactly one row is not updated, finalization fails and no caller may claim that run as terminal.

After terminal finalization:

- public/private JSON and digests never change
- `workspaceId`, owner, scope, base revision, context digests, and wire status never change
- availability may be projected as expired
- the prune job may strip payloads and change only storage lifecycle fields to `tombstone`
- the prune job may later delete the tombstone

### 14.4 Idempotency reservation

The server never stores the raw caller `idempotencyKey`.

It computes a unique raw-key scope digest:

```text
idempotencyScopeDigest = sha256(JCS({
  siteScopeId,
  userId,
  workspaceId,
  idempotencyKey
}))
```

It then computes the canonical protocol generation binding:

```text
generationBindingDigest = sha256(JCS({
  protocolVersion,
  siteScopeId,
  userId,
  workspaceId,
  expectedWorkspaceRevision,
  contextConfigurationDigest,
  idempotencyKey
}))
```

`actorSessionId` is intentionally excluded.

The unique scope digest makes reuse of one raw key discoverable even when the caller changes configuration or expected revision. The stored generation binding is then compared to enforce the protocol rule: same key and binding returns the same run; the same key with a different normalized configuration/revision returns `idempotency_conflict`.

The stored `editor_scope_key` is also compared. A different primary scope under a reused `workspaceId` is an `idempotency_conflict`, because the page's workspace-to-scope binding cannot legitimately change in place.

Live context is deliberately not part of the idempotency binding. `workspaceRevision` does not guard document content, and an exact terminal retry must return the first completed run even if the editor has since changed. Freshness checks and intended regeneration use a new idempotency key.

Reservation behavior is deterministic:

| Existing row | Generation binding/scope | Behavior |
|---|---|---|
| None | Any valid request | Insert `building` row and acquire lease |
| Terminal active | Same binding and editor scope | Return existing run with `deduplicated: true` without recapturing context |
| Terminal or tombstone, `expiresAt <= now < tombstoneUntil` | Same binding and editor scope | Return `run_expired`; do not create another run |
| Terminal or tombstone, `now >= tombstoneUntil` | Same binding and editor scope | Return `run_not_found`; do not create another run even if physical prune lags |
| Building with active lease | Same binding and editor scope | Return `generation_in_progress` and bounded retry metadata |
| Building with expired lease | Same binding and editor scope | Compare-and-swap a new fencing lease and regenerate under the same `runId` |
| Any row | Different binding or editor scope | Return `idempotency_conflict` with zero Ability execution |

An active terminal dedupe response calls the same `authorize_run_read()` path as GET before returning: captured current-site storage context, exact owner match, current editor-scope authorization, public/private digest verification, and per-ready-result reauthorization from each retained input projection. It may not use a lighter reservation-time check. A terminal/physical-tombstone dedupe uses the same owner/scope authorization and availability projection as GET: the expiry window returns bounded `run_expired` metadata, and the tombstone deadline or later returns `run_not_found` even when physical cleanup lags. Stripped payloads have no ready-result projection to reauthorize. Idempotency never bypasses revoked permissions or corruption checks.

A caller intending a fresh generation uses a fresh idempotency key.

### 14.5 Lease behavior

The default generation lease is ten minutes. The service renews it immediately before and after each surface invocation. Every renewal and finalization compares the current lease token hash.

An expired-lease claimant replaces the token through one conditional update. A superseded handler may finish an Ability call, but its next renewal/finalization fails. It cannot overwrite the winning terminal payload.

No raw shared context is stored to resume a request. A stale-lease claimant may submit a newly captured live snapshot for the same generation binding. It replaces the nullable building-row context signature only while acquiring/holding the new fencing lease; no earlier attempt produced immutable terminal evidence. Once a terminal run exists, every exact retry returns it without recapture.

Abandoned `building` reservations older than 24 hours are deleted by the prune job. They are never projected as a failed public `RecommendationRun`, because they may not contain a complete receipt or exactly one result per requested surface.

## 15. Run generation and page installation flow

### 15.1 Page coordinator

The shared `completeRecommendationRequest()` coordinator accepts:

```text
workspaceId
expectedWorkspaceRevision
actorSessionId
idempotencyKey
```

It does not accept arbitrary Ability names, surface input objects, operations, or selector paths.

One call may refresh recommendation results for several UI surfaces, but generation does not mutate any WordPress document, template, style, navigation, or pattern target. The only semantic page mutation is installing the new current-run relationship after CAS; the only new server writes are the run reservation/terminal payload plus any pre-existing diagnostic logging performed by the invoked Abilities.

The coordinator executes:

1. read the workspace and compare ID/revision
2. require a valid non-empty normalized configuration
3. require the current primary editor scope to match the workspace scope
4. dispatch transient `beginRecommendationGeneration`
5. capture one `WorkspaceContextSnapshot`
6. synchronously recheck workspace ID/revision and scope before network dispatch
7. POST the workspace snapshot, configuration, capture, actor session, and idempotency key to the run controller
8. normalize and cache the returned public terminal run
9. synchronously call `installRecommendationRun` with the original expected revision
10. dispatch transient finish state and return only after store selectors observe the committed relationship or conflict

Spec 2 adds the exact editor-busy guard before step 5. Until then this coordinator is not exposed as the protocol's public `complete_recommendation_request` tool.

### 15.2 Race result

If the page workspace changes at step 6 or step 9:

- do not install or revive the run relationship
- do not change selection, review, or plan
- do not increment `workspaceRevision`
- retain a valid terminal server run until its ordinary TTL
- return `workspace_changed_during_generation` with the current workspace revision and the retained run ID only when safe for diagnostics

The server does not pretend to validate the page revision. It stores `baseWorkspaceRevision` as a binding; only the page store can perform the same-page CAS.

### 15.3 Multiple simultaneous requests

Starting generation is not a semantic workspace mutation, so two requests may capture the same base revision. The first different run that successfully installs increments the revision. The other run then loses the CAS and remains retained but non-current.

Two exact retries that resolve to the same `runId` are idempotent: once that run is current, the later install is a no-op success. A superseded same run is never reinstated.

### 15.4 Server orchestration

After validation and reservation, the server invokes requested surfaces in canonical registry order. Spec 1 does not require parallel provider execution or a background queue. Execution strategy may later change behind `RecommendationRunService` provided that:

- Ability permissions and schemas remain authoritative
- final result ordering remains canonical
- exactly one result exists per requested surface
- lease fencing and idempotency behavior remain unchanged
- only one terminal payload pair commits

A request lost after terminal commit is recovered by the same idempotency key. A request terminated before commit leaves a reclaimable reservation.

### 15.5 Failure and commit ordering

Request-level processing has this fixed order:

1. validate the raw and decoded closed REST shape, protocol version, UUIDs, limits, and editor-scope syntax
2. resolve authenticated site/user state
3. normalize configuration and verify the workspace-to-editor-scope request binding
4. completely validate the caller-owned context capture: collector/scope binding, unique client paths, declared source/path pairs, path-discriminated values, dispositions, timestamps, per-seam/cumulative caps, and requested/extra-path policy
5. compute idempotency scope and generation binding
6. return an existing terminal/expired result through `authorize_run_read()`, or return conflict, before new server context work
7. insert or acquire the fenced building lease
8. collect server-owned seams and build the final context envelope, receipt, and signature under that lease
9. for each surface, validate target identity and freshness, then permission/prerequisites, execute, and adapt
10. validate exact result completeness, payload caps, and public/private digests
11. atomically finalize the terminal row under the lease token
12. return the immutable public run
13. let the page perform the independent workspace CAS installation

Errors through step 4, including every `context_capture_invalid`, create no run row and invoke no Ability. Once step 7 reserves a row, server-source absence is represented by a receipt disposition and the manifest's exact consumer policy: a hard missing path yields deterministic affected-surface `unavailable` results, while a soft path—including docs grounding—uses its declared null/omission projection and continues. It is never relabeled as a caller capture error. A non-recoverable server envelope invariant/foundation failure may leave only the fenced `building` reservation for takeover/prune and cannot claim a terminal run. A surface error at step 9 becomes that surface's deterministic failed/unavailable result and does not erase other requested entries. An error after an Ability invocation may coexist with the existing bounded request-diagnostic activity write, but it cannot claim a terminal run unless step 11 commits. A failed page CAS at step 13 never rewrites or deletes the server run.

## 16. Internal REST contract

### 16.1 Routes

`RecommendationRunsController` registers:

```text
POST /flavor-agent/v1/recommendation-runs
GET  /flavor-agent/v1/recommendation-runs/<runId>
```

There is no list, update, delete, arbitrary result-reference, or generic execute route.

### 16.2 Authentication and authorization

Both routes require an authenticated WordPress user. Cookie-authenticated editor requests rely on the standard REST nonce supplied by `apiFetch`; alternate WordPress authentication remains governed by core.

The POST route performs coarse authenticated access only in its route permission callback, then each surface uses the exact registered Ability permission path. Target identity is validated before capability checks.

The GET route requires:

- current site/blog storage context
- exact `user_id` owner match
- current access to the primary editor scope
- reauthorization of every ready result through its retained bounded Ability input projection

If any ready result is no longer authorized, the route denies the complete run rather than returning a mutated/redacted version of immutable evidence. Tombstones reveal only `runId`, expired availability, and deadlines after owner/scope authorization.

### 16.3 Create request

The POST body is closed and contains:

```text
protocolVersion
workspaceId
expectedWorkspaceRevision
actorSessionId
editorScope
contextConfiguration
contextCapture
idempotencyKey
```

`contextCapture.seams` is the path-discriminated union from section 10. No `surfaceInputs`, Ability names, operations, result payloads, site ID, or user ID are accepted.

Success returns:

```json
{
  "run": {},
  "deduplicated": false
}
```

The route returns only a terminal public run. `generation_in_progress` is a bounded retryable error for a concurrent identical reservation; the later WebMCP coordinator may wait/retry it under the same idempotency key.

Internal HTTP status mapping is stable:

| Condition | Status |
|---|---:|
| New terminal run | `201` |
| Active terminal dedupe | `200` |
| Closed-schema/configuration error | `400` |
| Unauthenticated/authentication failure | Core `401`/`403` behavior |
| Authorization denied | `403` |
| Run not found, including `now >= tombstoneUntil` | `404` |
| Idempotency/finalization conflict or generation in progress | `409` |
| Expiry window, `expiresAt <= now < tombstoneUntil` | `410` |
| Stored run payload/digest mismatch | `500` |
| Storage/provider foundation unavailable before a terminal run | `503` |

### 16.4 Read request

GET calls `RunAvailabilityProjector` with an injected authoritative clock. It performs no install, prune, lazy expiry update, activity update, or store dispatch.

It returns:

- the immutable public run while `now < expiresAt`
- a `410 run_expired` error with only `runId`, `expiresAt`, and `tombstoneUntil` while `expiresAt <= now < tombstoneUntil`
- `404 run_not_found` at or after `tombstoneUntil`

Physical prune lag does not change those answers.

### 16.5 Foundation error mappings

Error codes remain an open protocol set, but this foundation fixes generic handling for every new code it introduces:

| Code | Category | Retry disposition | Effects |
|---|---|---|---|
| `recommendation_request_invalid` | `validation` | `do_not_retry` | Zero reservation, Ability, run, or workspace writes for the unchanged request |
| `context_configuration_invalid` | `validation` | `refresh_context` | Zero capture, Ability, run, or workspace writes |
| `context_not_configured` | `validation` | `refresh_context` | Zero capture, Ability, run, or workspace writes |
| `context_capture_invalid` | `validation` | `refresh_context` | Complete caller capture validation occurs before reservation; zero Ability or run writes |
| `generation_in_progress` | `busy` | `wait` | Existing building reservation only |
| `result_too_large` | `validation` | `regenerate` | One surface failure inside a terminal partial/failed run |
| `run_payload_mismatch` | `recovery` | `manual_recovery` | No payload returned and no workspace install |
| `recommendation_protocol_unavailable` | `unavailable` | `retry_same` | No capture, Ability execution, reservation, or claimed terminal run |
| `run_storage_unavailable` | `unavailable` | `retry_same` | No claimed terminal run |
| `run_finalization_conflict` | `conflict` | `retry_same` | Losing lease cannot commit or install |
| `workspace_revision_exhausted` | `conflict` | `refresh_workspace` | Zero requested semantic mutation; page lifecycle must create a new workspace |
| `workspace_changed_during_generation` | `conflict` | `refresh_workspace` | Retained run allowed; zero workspace semantic mutation |

The canonical `run_expired`, `run_not_found`, `surface_unavailable`, `authorization_failed`, and `idempotency_conflict` codes retain their protocol meanings. Clients branch on code/category/retry disposition, never localized messages.

Error `details` is a closed per-code union. A validation path is 1–512 characters and matches `^\$(?:\.[A-Za-z][A-Za-z0-9_]*|\[[0-9]+\])*$`. The foundation schemas are:

| Code family | Exact `details` shape |
|---|---|
| `recommendation_request_invalid`, `context_configuration_invalid`, `context_capture_invalid` | `{ path: ValidationPath, reasonCode: Code }` |
| `generation_in_progress` | `{ runId: RunId, retryAfterSeconds: integer 1..600 }` |
| `run_expired` | `{ runId: RunId, expiresAt: ServerTimestamp, tombstoneUntil: ServerTimestamp }` |
| `workspace_changed_during_generation` | `{ currentWorkspaceRevision: SafeInteger, runId: RunId | null }` |
| `workspace_revision_exhausted` | `{ currentWorkspaceRevision: 9007199254740991 }` |
| `result_too_large` | `{ surfaceId: SurfaceId }` |
| `run_payload_mismatch`, `run_finalization_conflict`, `run_not_found`, `idempotency_conflict` | `{ runId: RunId }` |
| `context_not_configured`, `recommendation_protocol_unavailable`, `run_storage_unavailable`, `authorization_failed` | `{}` |

No code may add provider data, SQL, capability names, stack traces, arbitrary nested metadata, or an unregistered detail member.

## 17. Expiry, tombstones, and cleanup

### 17.1 Deadlines

On terminal finalization:

```text
expiresAt      = completedAt + 30 minutes
tombstoneUntil = expiresAt + 24 hours
```

The server timestamps are authoritative. The active test is strict: a run is active only while `now < expiresAt`.

### 17.2 Pure availability projector

`RunAvailabilityProjector::project(row, now)` is a pure function:

```text
building                    -> generation_in_progress internal projection
terminal and now < expiry   -> stored ready/partial/failed run
terminal/tombstone in window -> expired tombstone projection
now >= tombstoneUntil       -> not found projection
```

It performs zero database writes and has no dependency on prune scheduling. The page equivalent derives expired/ineligible presentation without dispatching cleanup or incrementing `workspaceRevision`.

### 17.3 Prune job

The daily idempotent prune job operates in bounded batches:

1. delete abandoned `building` reservations older than 24 hours
2. convert expired terminal rows to `tombstone` by nulling public/private payloads, payload digests, lease data, and non-minimal context metadata
3. delete tombstones whose `tombstone_until` has elapsed

The job exposes a direct callable method for tests and WP-CLI diagnostics. A failed batch leaves remaining rows for the next invocation.

Deactivation clears the schedule but retains rows. Uninstall clears the hook, drops the table, and deletes the schema option. The user must be told that uninstall removes retained runs and tombstones.

## 18. Security and privacy requirements

- Run IDs, result references, unit IDs, and workspace IDs are opaque identifiers, never authorization.
- User and site bindings come from authenticated server state and are never accepted from the request.
- The route validates target identity before capability checks so malformed/divergent identities fail consistently.
- Every Ability executes through its own current permission callback and output schema.
- Generated text, titles, summaries, warnings, and native model outputs are untrusted.
- The public run never returns native operations or private bindings.
- The private run payload is accessible only inside server services; no direct REST field or debug flag returns it.
- Context capture is an allowlisted semantic projection. Arbitrary selectors/actions and raw registry access are absent.
- Recent activity context contains outcomes only; it excludes prompts, generated text, block attributes, post content, and before/after snapshots.
- Error normalization removes provider bodies, stack traces, SQL errors, credentials, decision tokens, and unauthorized content.
- Logs and hooks contain IDs, status, surface, duration, byte counts, and machine codes only. They do not log context values or native payloads.
- Payload caps are checked before hashing and database writes.
- Corrupt payload JSON or a stored digest mismatch fails closed as `run_payload_mismatch`; it is not returned partially.
- A temporary/unsaved editor scope cannot produce a governed persistent binding. Advisory/stage-only results may still be generated from bounded client state where the source Ability permits it.

## 19. Verification contract

### 19.1 Shared contract fixtures

Add checked-in fixtures consumed by Jest and PHPUnit for:

- configuration defaults, sorting, deduplication, and semantic no-op cases
- every invalid closed-object property and enum
- UTF-8/string/array limits
- canonical JSON and SHA-256 values
- receipt exact partition, category metadata, server-derived truncation limits/units, and one-path mixed docs surface aggregation
- expanded hard/soft consumer policy and trusted-docs normalization/currentness cases
- context-signature inclusion/exclusion rules
- eight-Ability-to-nine-surface mapping
- deterministic `resultRef` and `unitId`

PHP and JavaScript MUST produce identical canonical configuration JSON for every shared fixture.

### 19.2 JavaScript unit tests

Focused tests cover:

- editor instance created once per bundle lifetime
- every primary editor-scope shape and temporary-to-canonical transition
- selected block changes do not create a workspace; selected-focus changes replace configuration and supersede exactly once
- scope navigation creates a fresh workspace at revision `0`
- context semantic changes increment once and supersede/clear atomically
- no-op configuration replacement does not increment
- wrong workspace/revision changes nothing
- run cache immutability and payload mismatch
- first run installation increments once
- exact same-run retry is a no-op success
- superseded same run cannot be revived
- two different completions from the same base revision allow one install
- generation state updates never change workspace revision
- a late response after scope change cannot install
- collector path/source allowlist, bounds, and failure classification

Suggested locations are:

```text
src/recommendations/workspace/__tests__/
src/recommendations/context/__tests__/
src/recommendations/runs/__tests__/
src/store/__tests__/recommendation-workspace.test.js
```

### 19.3 PHP unit and repository tests

Focused PHPUnit coverage includes:

- manifest load/failure behavior
- configuration and context canonicalization fixtures
- JSON object/array identity, including nested `{}` versus `[]`
- schema-keyword coverage for every live recommendation Ability input/output schema
- exact receipt partition with included/summarized/truncated/omitted/unavailable
- exact truncated-receipt `limit`, unit lookup, source count, and forbidden metadata
- mixed docs receipt aggregation with one included, one unavailable, and two differently truncated surface results
- context signature determinism and private binding inputs
- exact seam-to-surface input consumption and legacy docs-collector fallback
- trusted docs hostile-URL/currentness rejection and soft failure for every docs consumer
- `post_blocks` unavailable while dirty/saving/autosaving, with zero Ability calls
- target-identity-before-permission ordering
- exact registered Ability resolution, permission denial, execution, and output validation
- style invoked separately for Global Styles and Style Book
- exactly one result per requested surface, including failure/unavailable entries
- zero-suggestion success, ready/partial/failed derivation
- native/public/private size failures
- schema install and upgrade idempotency
- per-blog storage context captured across an ambient multisite switch
- owner-scoped reads and permission revocation
- reservation insert races and idempotency conflicts
- active lease refusal, stale lease takeover, and fencing-token loss
- one-time terminal finalization and payload immutability
- payload digest corruption failure
- strict expiry boundary before/at/after `expiresAt`
- terminal and physical-tombstone dedupe before/at/after `tombstoneUntil`
- read projection with zero writes
- bounded idempotent prune and abandoned-reservation removal
- deactivation/uninstall lifecycle registration

Suggested locations are:

```text
tests/phpunit/RecommendationContextTest.php
tests/phpunit/RecommendationRunServiceTest.php
tests/phpunit/RecommendationRunRepositoryTest.php
tests/phpunit/RecommendationRunsControllerTest.php
tests/phpunit/RecommendationRunLifecycleTest.php
```

### 19.4 REST and integration tests

REST tests assert:

- unauthenticated requests fail
- unknown input fields fail
- caller-supplied site/user/Ability/operation fields fail
- one-to-nine surface validation
- same key/same generation binding returns the same run even after live editor context changes
- same key/different configuration, expected revision, or editor scope returns `idempotency_conflict`
- malformed or oversize complete client capture fails before any reservation write
- active terminal dedupe uses the exact GET owner/scope/digest/per-ready reauthorization path
- GET and POST dedupe map stored payload/digest corruption to the same bounded HTTP 500 response
- terminal or physical-tombstone rows project `run_not_found` at/after `tombstoneUntil`, independent of prune lag
- an expired building lease may adopt a fresh capture under a new fencing token, while an active or terminal first writer still wins
- lost-response retry reads the committed run
- GET never prunes or lazily expires
- private bindings never appear in any response or error
- a run completion after page CAS loss is retained but not current

Integration tests stub registered `WP_Ability` objects rather than recommender internals. At least one test per surface uses the real Flavor Agent registration/output adapter path.

### 19.5 Browser evidence

The matching Playground and Docker Site Editor Playwright harnesses must demonstrate the following at the versions pinned by their checked-in configuration. Both are currently pinned to WordPress 7.1; the `wp70` script/configuration name is historical and does not prove a WordPress 7.0 run.

- two tabs on the same post receive independent workspace IDs/revisions
- Site Editor navigation creates a new workspace
- changing selected blocks does not create a new workspace and, for selected focus, increments/supersedes the existing workspace exactly once
- a configuration change during an in-flight run prevents installation
- two different run completions from one base revision install only one
- a browser-harness invocation of the shared coordinator caches one entry for every requested surface, including a forced partial failure
- no `document.modelContext` tool is registered by Spec 1
- legacy per-surface recommendation panels continue to function without dual-writing shared selection

### 19.6 Repository gates

This work touches shared context, every recommendation surface, persistence, REST, and freshness contracts. It therefore requires:

- nearest targeted Jest and PHPUnit suites
- JavaScript and PHP lint for touched source
- `node scripts/verify.js --skip-e2e`
- `npm run check:docs`
- matching Playground and Docker Site Editor Playwright evidence at their checked-in pinned versions, with the actual WordPress versions recorded
- an explicit blocker or waiver for any unavailable browser harness

Green focused unit tests alone are not completion.

Evidence is tied to an immutable implementation candidate, not to a dirty working tree. After code, tests, stable documentation, and independent review fixes are committed, record that commit as `CANDIDATE_SHA`. Run every release gate from a fresh clean checkout of exactly `CANDIDATE_SHA`, record the archive SHA-256 and listing, and make no source, test, or stable-documentation changes during that run. Then create one evidence-only commit that updates only the validation ledger with the candidate SHA, commands, timestamps, versions, counts, archive digest, and classified blockers or waivers. The final handoff reports both `CANDIDATE_SHA` and `EVIDENCE_SHA`; the evidence commit is not misrepresented as the tested implementation SHA.

## 20. Acceptance criteria

Spec 1 is implemented only when all of the following are true:

1. The existing `flavor-agent` store contains the sole page-owned workspace slice; no mutable React context or second store exists.
2. Workspace identity follows `editorInstanceId + editorScopeKey`, resets on primary scope change, and remains tab-local.
3. Context replacement and run installation have exact same-page CAS behavior and revision effects from the canonical protocol.
4. A run completing after a workspace change is retained but cannot become current.
5. One normalized request can invoke any valid subset of the nine surfaces through the exact eight registered Abilities.
6. Both style surfaces are independent invocations and `post_blocks` remains requestable.
7. Every terminal run has one result per requested surface and a correct ready/partial/failed status.
8. Every requested seam appears in exactly one final receipt category with category-valid metadata, every included value has a declared consumer, and hard/soft absence follows the manifest without letting docs failure block a recommendation.
9. The server, not the client, computes the context signature and authenticated bindings.
10. Public and private run payloads are separated, immutable after finalization, bounded, and digest-checked.
11. Idempotency, lease takeover, and fencing prevent two different terminal payloads for one run.
12. Active TTL, tombstone retention, and not-found boundaries match protocol section 21.1.
13. Run reads project time without database writes or page-store dispatches.
14. Activation, upgrade, deactivation, prune, multisite storage context, and uninstall behavior have automated coverage.
15. Existing recommendation UI, activity storage, apply/approval, and undo behavior remain unchanged.
16. No WebMCP tools are registered and no partial protocol capability is advertised.
17. All section 19 verification evidence is recorded against one immutable implementation candidate, with any unavailable harness explicitly classified.

## 21. Handoff contracts to later specifications

Spec 1 exposes only these extension interfaces:

### 21.1 Spec 2

Spec 2 receives:

- the current immutable public run cache
- CAS actions and revision selectors
- `WorkspaceContextSnapshot` capture entry point
- placeholders for selection, review, and plan

It MUST migrate human checkbox/review state into the workspace without introducing a second owner. It adds `EditorInteractionGuard` before the coordinator's context capture and later workspace commits.

### 21.2 Spec 3

Spec 3 receives:

- private `SurfaceBinding` records
- deterministic result/unit IDs
- result adapter extension point executed before terminal finalization
- shared RFC 8785 canonical JSON utility

It adds exact executor binding versions, `operation-digest-v1`, target grouping, and the pattern compiler. It may promote a pattern unit from `stage_only` to `governed_apply` only before the terminal run commits.

### 21.3 Spec 4

Spec 4 receives:

- owner-scoped run dereference
- pure `RunAvailabilityProjector`
- server-retained exact native bindings
- run expiry and tombstone semantics

It adds apply-request idempotency/dedupe, the second commit-time run expiry check, activity status projection, and undo. A later apply request never modifies a terminal run.

### 21.4 Spec 5

Spec 5 receives:

- shared store actions/selectors
- the complete recommendation coordinator
- pure workspace/run read projections
- all later selection/apply/status/undo workflow services
- the shared version manifest and JSON Schemas

It registers exactly eight imperative WebMCP tools once per supported page lifecycle, cleans them up with an `AbortSignal`, returns concise JSON only after visible state settles, and fails closed when any prerequisite is unavailable.

## 22. Canonical protocol prerequisite accounting

Spec 1 advances, but does not overclaim completion of, the canonical P0 list:

| Canonical prerequisite | Spec 1 result |
|---|---|
| P0.1 pure projectors | Completes the run-availability projector only; activity projection remains Spec 4 |
| P0.2 operation digest | Establishes shared canonical JSON only; the envelope/digest and executor fixtures remain Spec 3 |
| P0.3 editor interaction guard | Deferred in full to Spec 2 |
| P0.4 negotiated version schemas | Establishes the 1.0 manifest; negotiation/downgrade registration remains Spec 5 |
| P0.5 retained runs/tombstones/apply dedupe/double expiry | Completes retained runs, run idempotency, and tombstones; apply dedupe and commit-time expiry remain Spec 4 |
| P0.6 pattern compiler | Deferred in full to Spec 3 |
| P0.7 workspace selection/review/CAS/grouping | Completes identity, context, current-run relationship, and base CAS; selection/review/grouping remain Specs 2–3 |

No P0 row is considered globally complete merely because its Spec 1 subset is implemented.

## 23. Source anchors

The local source anchors below were verified on 2026-08-27 against immutable commit `f8aae3014cc0b7009d6384e632f8fd303202be8e`.

| File | Snapshot blob | Grounding |
|---|---|---|
| `docs/superpowers/specs/2026-08-27-webmcp-recommendation-protocol-design.md` | `8fead468cbe128d763bec1d1634ee394d136051f` | Normative workspace, context, run, expiry, and prerequisite contract |
| `src/store/index.js` | `a4366e0b5b5fd9f413b333e1979a8f59a2035833` | Existing single store, per-surface state, and registration at lines 140–255 and 4500–4514 |
| `src/index.js` | `9ce29db3b4f73ed86fa19bb0514c47166484e484` | One editor plugin mount and component bootstrap at lines 15–48 |
| `src/inspector/BlockRecommendationsPanel.js` | `4676cd0b59414725ee2524da82dd0f5b9c02f168` | Component-local block selection at lines 544–594 |
| `src/store/executable-surfaces.js` | `3278881f178f47d43f810078ef540a32c16b9155` | Current surface definitions and reusable per-surface runtime patterns |
| `src/store/abilities-client.js` | `ea6a0e43f9980c3c1629cab1be7fa13ad022ef4c` | Existing client Ability execution and REST fallback |
| `assets/abilities-bridge.js` | `1f501f9969462303e7bb9156f310c7164e6cdf8a` | Existing page Ability bridge; not a run/workspace owner |
| `inc/Abilities/Registration.php` | `fa73c1ba44a1a08ff589b974a961d4168ea056b4` | Eight recommendation Abilities at lines 154–197 and governed lanes at lines 205–243 |
| `inc/Abilities/RecommendationAbilityExecution.php` | `a146abaced0a98b889dba0783fe6be0c4d0cf248` | Shared recommendation execution, request metadata, and diagnostics |
| `inc/AI/Abilities/RecommendationAbility.php` | `89a725a079b9a84ebd5bec184fe7663a47673184` | Canonical Ability class execution/permission callbacks |
| `inc/Activity/Repository.php` | `90ea756087019785e353a814a9b05ad8b7105cfe` | Existing custom-table schema/install/prune conventions at lines 10–135 |
| `inc/Activity/ActivityStorageContext.php` | `378234abe86122bc2abc67f3e61de8c0a97f3aa8` | Immutable database/blog ownership precedent |
| `inc/REST/Agent_Controller.php` | `6dbac95ed63740233df2f704fff4f44ca7eb7bf5` | Existing authenticated route registration conventions |
| `flavor-agent.php` | `f4fddec6bc73d73881df40d5ef71d4dde07da24e` | Activation, deactivation, init, and REST hooks at lines 73–145 |
| `inc/UninstallOptions.php` | `8b4481c0a3b872feb9913e148b1477d60c94692f` | Plugin-owned option cleanup registry |
| `uninstall.php` | `12af4e85ae68c5280bb5c0b7b0bf6687b7ee7c49` | Plugin-owned table and cron cleanup convention |
| `docs/reference/cross-surface-validation-gates.md` | `0b934609874af8b7785f65fa4aa993265aa57648` | Required shared-contract and multi-surface evidence |

Current WordPress documentation confirms that `wp_get_ability()` returns the registered `WP_Ability`, and that `WP_Ability::execute()` performs input validation and permission checking before execution. That public API is the reason this specification requires a server Ability invoker rather than direct callback reuse:

- [WordPress Abilities API PHP reference](https://developer.wordpress.org/apis/abilities-api/php-reference/)
- [`wp_get_ability()` code reference](https://developer.wordpress.org/reference/functions/wp_get_ability/)

## 24. Resolved design questions

- `workspaceRevision` guards only mutable same-page recommendation workflow state. It does not guard WordPress document content, server runs, activity rows, other tabs, or other users.
- `RecommendationWorkspace` is keyed by a page-lifetime editor instance plus primary editor scope and lives only in the existing page store.
- `WorkspaceContextSnapshot` is an ephemeral read adapter, not a mutable owner.
- `RecommendationRun` is server-retained and immutable only after terminal finalization; an internal `building` reservation is not a public run.
- A dedicated workflow REST controller composes registered Abilities; there is no ninth wrapper Ability.
- Server orchestration uses the registered `WP_Ability` execution path and never trusts client preflight as authorization.
- Eight recommendation Abilities create nine wire results because style has two closed scopes.
- `post_blocks` is a generating surface; pattern compiler output later targets it without inventing a result.
- `post_blocks` consumes its existing server-saved context only when the editor capture is clean; unsaved, saving, or autosaving state makes that surface unavailable.
- Every included signed seam has a fixed native, preflight, or `workspaceContext` consumer; precollected docs suppress a second internal lookup for run-based calls.
- Mandatory docs capture is soft for invocation: unavailable or fully filtered docs are signed/receipted as unavailable, projected as null, and never block an otherwise valid surface.
- Spec 1 emits no `governed_apply` unit or execution binding. Spec 3 may promote eligible units only before a future run finalizes.
- Context receipts and context signatures are distinct. The server owns both final forms.
- Public run data and private native bindings are stored separately and expire together.
- Run reads project expiry without persistence; cron owns storage cleanup.
- Spec 1 does not migrate checkbox state, calculate an apply plan, or expose WebMCP. Those omissions are explicit release blockers, not implicit fallback behavior.
