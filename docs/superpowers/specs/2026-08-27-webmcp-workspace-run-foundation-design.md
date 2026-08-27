# Flavor Agent WebMCP Implementation Series — Spec 1: Workspace, Context, and Run Foundation

- **Status:** Draft companion implementation specification; runtime implementation is not part of this document
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
- unit, integration, REST, repository, race, and browser evidence described in section 18

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
- closed configuration enums and default values
- string, collection, tree, request, result, and retained-payload limits
- public run and internal REST JSON Schemas
- error categories and retry dispositions used by this foundation

The manifest MUST NOT contain localized labels, credentials, dynamic capability results, Gutenberg selector names as public API, PHP callbacks, or JavaScript function names.

PHP loads it through `FlavorAgent\Recommendations\Protocol\V1Contract` with a request-local static cache. JavaScript imports the same JSON at build time through `src/recommendations/protocol/v1-contract.js`, validates its expected manifest version, and freezes the exported projection.

Failure to load or validate the manifest makes the run foundation unavailable. It MUST NOT silently fall back to duplicated hard-coded surface lists.

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

The `key` uses the canonical protocol forms:

```text
post:<id>
wp_template:<id>
wp_template_part:<id>
global_styles:<id>
style_book:<id>:<block-name>
temporary:<editorInstanceId>:<entity-kind>
```

The resolver MUST validate and bound every segment. A selected block, inserter position, navigation block, or Style Book subsection does not change the primary workspace unless the primary editor entity itself changes.

### 7.2 Workspace creation

For each resolved primary scope, the page creates a random `workspaceId`. The composite identity is:

```text
editorInstanceId + editorScope.key -> workspaceId
```

The composite is an in-memory binding, not a deterministic hash. The random `workspaceId` prevents editor identity details from being inferred from the ID and allows a fresh workspace when a single-page editor navigates away and later returns.

On a primary scope transition:

1. abort the active client generation controller
2. create a new `workspaceId`
3. reset `workspaceRevision` to `0`
4. install the new `editorScope`
5. reset context configuration, current run relationship, selection, review, and plan
6. preserve server runs only in the server repository; any page cache entry is disposable

An unsaved temporary scope is never promoted in place. First successful save resolves a canonical entity and creates a new workspace.

### 7.3 Independence

Two tabs editing the same entity create different `editorInstanceId`, `workspaceId`, and revision sequences. The page revision never claims to guard WordPress document content or another tab. Server Ability permissions, entity versions, exact freshness signatures, locks, and later apply-time checks own those boundaries.

When a configured workspace uses `focus.scope: selected_block` or `focus.scope: selection`, a semantic Gutenberg selection change replaces `focus.clientIds` through the same context-configuration action. It does not create a workspace, but it does increment the current workspace revision once, supersede the current run relationship, and clear later selection/review/plan state. Selection changes are ignored for this purpose while the configuration is empty or its focus is `document`/`site_editor_entity`.

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
    "byId": {}
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

An empty object is the only unconfigured `contextConfiguration` sentinel. It is not a valid generation configuration. The first successful configuration replacement installs the complete normalized shape from protocol section 10.

`recommendationRunCache.byId` contains only public run payloads. An existing `runId` may be inserted once. A second payload with the same `runId` and a different digest is a fail-closed `run_payload_mismatch`; the reducer retains the first payload.

The cache is bounded to ten runs. On insertion, it retains the current run plus the nine most recently completed non-current runs, with `completedAt` and then `runId` as deterministic ordering keys. Cache eviction is operational page state: it does not delete the server run, alter the current relationship, or increment `workspaceRevision`.

Generation state is operational UI state. Updating it does not increment `workspaceRevision` and it is not returned as part of the public workspace protocol.

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

## 9. Context configuration normalization

PHP and JavaScript normalizers MUST produce byte-equivalent canonical values for all valid protocol 1.0 configurations.

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
- reject `surfaceParameters.content` when `content` is not requested; otherwise materialize its normalized value
- preserve array order where it carries priority, selection, or operation meaning

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

### 10.3 Requested seam set

The server computes requested seams as the union of:

- each requested surface's mandatory context profile
- the focus profile implied by `focus.scope`
- the explicitly allowed `additionalContextGroups`
- mandatory identity, freshness, lock, permission-preflight, and operation-validation seams that optional configuration cannot remove

The initial mandatory group profiles are:

| Surface | Mandatory context groups |
|---|---|
| `block` | `document_identity`, `block_selection`, `block_constraints`, `block_registry`, `theme_tokens`, `save_publication_state` |
| `content` | `document_identity`, `document_summary`, `save_publication_state` |
| `pattern` | `document_identity`, `block_selection`, `block_constraints`, `pattern_catalog`, `template_structure`, `save_publication_state` |
| `navigation` | `document_identity`, `navigation_structure`, `block_constraints`, `save_publication_state` |
| `template` | `document_identity`, `template_structure`, `pattern_catalog`, `theme_tokens`, `save_publication_state` |
| `template_part` | `document_identity`, `template_structure`, `pattern_catalog`, `theme_tokens`, `save_publication_state` |
| `post_blocks` | `document_identity`, `block_tree`, `block_constraints`, `pattern_catalog`, `save_publication_state` |
| `global_styles` | `document_identity`, `theme_tokens`, `theme_style_summary`, `template_structure`, `save_publication_state` |
| `style_book` | `document_identity`, `theme_tokens`, `theme_style_summary`, `template_structure`, `block_selection`, `save_publication_state` |

The manifest maps groups to exact seam paths. Adding a seam path or changing a value schema requires a collector-version change and shared fixtures. It does not silently inherit every selector Gutenberg later adds.

### 10.4 Receipt construction

`ContextEnvelopeBuilder` owns the final receipt. For every requested seam path it selects exactly one category in this order:

1. `unavailable` when the required source cannot be resolved or authorized
2. `omitted` when allowed policy or optional configuration excludes an available optional seam
3. `truncated` when the bounded value reached its declared hard limit
4. `summarized` when the declared summary strategy replaced the source representation
5. `included` when the normalized bounded value was supplied directly

The builder rejects duplicate path observations, source/path mismatches, invalid category metadata, and any final receipt that is not an exact partition. It sorts receipt entries by manifest seam order and sorts each entry's `consumerSurfaceIds` by canonical surface order.

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

| Value | Limit | Overflow behavior |
|---|---:|---|
| Internal run-create JSON request | 1 MiB UTF-8 | Reject before reservation |
| Complete client seam values | 768 KiB UTF-8 canonical JSON | Summarize/truncate by seam policy or reject malformed request |
| One client seam value | 256 KiB | Use declared summary/truncation; otherwise mark unavailable |
| Receipt entries | 128 | Reject configuration as unsupported |
| Bounded block/template tree | 200 nodes, depth 8 | `summarized` or `truncated` |
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
| `template` | `flavor-agent/recommend-template` | Exact template scope | `single_review` | `governed_apply` only when the current native result already contains all required exact target/freshness fields; otherwise `advisory` |
| `template_part` | `flavor-agent/recommend-template-part` | Exact template-part scope | `single_multi_operation` | Same fail-closed governed rule |
| `post_blocks` | `flavor-agent/recommend-post-blocks` | Exact post/page scope | `single_multi_operation` | Same fail-closed governed rule |
| `global_styles` | `flavor-agent/recommend-style` | Closed Global Styles scope | `single_multi_operation` | Same fail-closed governed rule |
| `style_book` | `flavor-agent/recommend-style` | Closed Style Book block scope | `single_multi_operation` | Same fail-closed governed rule |

If both style surfaces are requested, the registry invokes `flavor-agent/recommend-style` twice with two independently validated scopes and emits two result entries.

`post_blocks` is both a genuine generating surface and a future executor target. Requesting it invokes its Ability and produces its own result. A future governed pattern binding does not create an unrequested extra result.

The initial governed lane identifiers are fixed now so an immutable Spec 1 run does not depend on display text or a later guess:

| Executor surface | Executor Ability | Operation schema version |
|---|---|---|
| `template` | `flavor-agent/request-template-apply` | `template-v1` |
| `template_part` | `flavor-agent/request-template-part-apply` | `template-part-v1` |
| `post_blocks` | `flavor-agent/request-post-blocks-apply` | `post-blocks-v1` |
| `global_styles` | `flavor-agent/request-style-apply` | `style-v1` |
| `style_book` | `flavor-agent/request-style-apply` | `style-v1` |

Spec 3 defines the exact private binding envelope and operation digest for those version names. It does not rename them.

### 12.2 Input adapters

Each registry entry owns a typed `SurfaceInputAdapter`. It receives only:

- normalized `ContextConfiguration`
- normalized server context envelope
- exact editor scope
- run/actor diagnostic metadata

It returns the existing Ability's registered input shape. It MUST NOT accept an Ability ID or free-form operation payload from the REST caller.

The adapter builds `document`, scope, target, prompt, `clientRequest`, and surface fields from allowlisted context. `clientRequest.sessionId` uses the bounded `actorSessionId`, `abortId` uses the run ID, and `scopeKey` uses the validated editor scope. Diagnostic metadata is not authority.

`clientRequest.requestToken` uses the non-negative `baseWorkspaceRevision`. Ability and surface identity remain part of the existing server transient key, so two style invocations do not overwrite each other's result classification. A later workspace revision naturally produces a newer token.

### 12.3 Ability invocation

For each requested surface in canonical order, the service:

1. validates target identity for that surface
2. resolves the exact fixed Ability with `wp_get_ability()`
3. classifies a missing Ability or failed prerequisite/permission preflight as `unavailable`
4. calls `WP_Ability::execute()` with the adapted input
5. classifies an attempted execution error as `failed`
6. validates and bounds the successful output through the registered Ability output schema
7. passes the result to the fixed `SurfaceResultAdapter`

The workflow cannot bypass, weaken, or cache an Ability permission decision.

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

Spec 1 may emit a `governed_apply` unit only when the existing surface result already proves a complete executor binding. It does not compute a plan or make that unit applicable. Pattern remains `stage_only` until Spec 3 runs its compiler before terminal finalization.

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

A public `governed_apply` unit includes only the executor surface, executor Ability, and operation schema version from the fixed table above. Native operations, targets, signatures, pattern content, and request fields remain in its private `SurfaceBinding`.

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
| Terminal expired/tombstone | Same binding and editor scope | Return `run_expired`; do not create another run |
| Building with active lease | Same binding and editor scope | Return `generation_in_progress` and bounded retry metadata |
| Building with expired lease | Same binding and editor scope | Compare-and-swap a new fencing lease and regenerate under the same `runId` |
| Any row | Different binding or editor scope | Return `idempotency_conflict` with zero Ability execution |

A terminal/expired dedupe response still passes the GET route's current owner/scope authorization policy before returning any payload or tombstone metadata. Idempotency never bypasses revoked permissions.

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

1. validate the closed REST shape, protocol version, UUIDs, limits, and editor-scope syntax
2. resolve authenticated site/user state
3. normalize configuration and verify the workspace-to-editor-scope request binding
4. compute idempotency scope and generation binding
5. return an existing terminal/expired result or conflict before new context work
6. insert or acquire the fenced building lease
7. build the final context envelope, receipt, and signature under that lease
8. for each surface, validate target identity, then permission/prerequisites, then execute and adapt
9. validate exact result completeness, payload caps, and public/private digests
10. atomically finalize the terminal row under the lease token
11. return the immutable public run
12. let the page perform the independent workspace CAS installation

Errors before step 6 create no run row and invoke no Ability. A surface error at step 8 becomes that surface's deterministic failed/unavailable result and does not erase other requested entries. An error after an Ability invocation may coexist with the existing bounded request-diagnostic activity write, but it cannot claim a terminal run unless step 10 commits. A failed page CAS at step 12 never rewrites or deletes the server run.

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
| Run not found | `404` |
| Idempotency/finalization conflict or generation in progress | `409` |
| Run expired/tombstone | `410` |
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
| `context_not_configured` | `validation` | `refresh_context` | Zero capture, Ability, run, or workspace writes |
| `context_capture_invalid` | `validation` | `refresh_context` | Zero Ability or run writes |
| `generation_in_progress` | `busy` | `wait` | Existing building reservation only |
| `result_too_large` | `validation` | `regenerate` | One surface failure inside a terminal partial/failed run |
| `run_payload_mismatch` | `recovery` | `manual_recovery` | No payload returned and no workspace install |
| `run_storage_unavailable` | `unavailable` | `retry_same` | No claimed terminal run |
| `run_finalization_conflict` | `conflict` | `retry_same` | Losing lease cannot commit or install |
| `workspace_changed_during_generation` | `conflict` | `refresh_workspace` | Retained run allowed; zero workspace semantic mutation |

The canonical `run_expired`, `run_not_found`, `surface_unavailable`, `authorization_failed`, and `idempotency_conflict` codes retain their protocol meanings. Clients branch on code/category/retry disposition, never localized messages.

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
- receipt exact partition and category metadata
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
- exact receipt partition with included/summarized/truncated/omitted/unavailable
- context signature determinism and private binding inputs
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
- tombstone boundary before/at/after `tombstoneUntil`
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
- an expired building lease may adopt a fresh capture under a new fencing token, while an active or terminal first writer still wins
- lost-response retry reads the committed run
- GET never prunes or lazily expires
- private bindings never appear in any response or error
- a run completion after page CAS loss is retained but not current

Integration tests stub registered `WP_Ability` objects rather than recommender internals. At least one test per surface uses the real Flavor Agent registration/output adapter path.

### 19.5 Browser evidence

The matching Playground and WordPress 7.0 harnesses must demonstrate:

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
- matching Playground and WordPress 7.0 Playwright evidence
- an explicit blocker or waiver for any unavailable browser harness

Green focused unit tests alone are not completion.

## 20. Acceptance criteria

Spec 1 is implemented only when all of the following are true:

1. The existing `flavor-agent` store contains the sole page-owned workspace slice; no mutable React context or second store exists.
2. Workspace identity follows `editorInstanceId + editorScopeKey`, resets on primary scope change, and remains tab-local.
3. Context replacement and run installation have exact same-page CAS behavior and revision effects from the canonical protocol.
4. A run completing after a workspace change is retained but cannot become current.
5. One normalized request can invoke any valid subset of the nine surfaces through the exact eight registered Abilities.
6. Both style surfaces are independent invocations and `post_blocks` remains requestable.
7. Every terminal run has one result per requested surface and a correct ready/partial/failed status.
8. Every requested seam appears in exactly one final receipt category.
9. The server, not the client, computes the context signature and authenticated bindings.
10. Public and private run payloads are separated, immutable after finalization, bounded, and digest-checked.
11. Idempotency, lease takeover, and fencing prevent two different terminal payloads for one run.
12. Active TTL, tombstone retention, and not-found boundaries match protocol section 21.1.
13. Run reads project time without database writes or page-store dispatches.
14. Activation, upgrade, deactivation, prune, multisite storage context, and uninstall behavior have automated coverage.
15. Existing recommendation UI, activity storage, apply/approval, and undo behavior remain unchanged.
16. No WebMCP tools are registered and no partial protocol capability is advertised.
17. All section 19 verification evidence is recorded with any unavailable harness explicitly classified.

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
- Context receipts and context signatures are distinct. The server owns both final forms.
- Public run data and private native bindings are stored separately and expire together.
- Run reads project expiry without persistence; cron owns storage cleanup.
- Spec 1 does not migrate checkbox state, calculate an apply plan, or expose WebMCP. Those omissions are explicit release blockers, not implicit fallback behavior.
