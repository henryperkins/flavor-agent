# Flavor Agent WebMCP Recommendation Protocol 1.0

- **Status:** Canonical design contract; runtime implementation is not part of this document
- **Protocol version:** `1.0`
- **Date:** 2026-08-27
- **Updated:** 2026-09-04
- **WebMCP annotation source:** [`webmachinelearning/webmcp` commit `7b3f50f31848b529e69bedbbdf8da0edccba055f`](https://github.com/webmachinelearning/webmcp/commit/7b3f50f31848b529e69bedbbdf8da0edccba055f) (`ToolAnnotations` snapshot, 2026-09-03)
- **Scope:** Flavor Agent recommendation context, selection, review, governed apply status, and governed undo projected into an editor page through imperative WebMCP tools

## 1. Purpose

This document is the normative protocol for exposing Flavor Agent's recommendation workflow to an AI agent running against a WordPress editor page.

It defines:

- one shared contract for block, content, pattern, navigation, template, template-part, post-blocks, Global Styles, and Style Book recommendations
- a page-scoped mutable `RecommendationWorkspace`
- immutable, server-retained `RecommendationRun` payloads
- context configuration, provenance receipts, and freshness signatures
- selection and review concurrency between the human and one or more agents on the same page
- a fail-closed projection over Flavor Agent's existing Abilities and activity lifecycle
- eight stable imperative WebMCP tools
- distinct apply and undo state machines
- protocol negotiation, expiry, idempotency, error, and compatibility rules
- the protocol 1.0 boundary around multi-target work

The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT**, and **MAY** are normative.

## 2. Implementation status

This is a design contract, not a claim that WebMCP support exists in the current plugin.

The current repository already contains:

- eight recommendation Abilities in `Registration::recommendation_ability_classes()`
- four governed request-apply Abilities plus activity read/list/undo Abilities in `Registration::external_apply_ability_classes()`
- server permission callbacks, operation schemas, freshness signatures, pending approval rows, decision claims, executor-specific apply, activity reads, and ordered undo
- a per-page `wp.data` store and surface-specific recommendation UI

The current repository does not contain:

- `document.modelContext.registerTool()` registration
- the eight WebMCP tools specified here
- `RecommendationWorkspace`, `workspaceRevision`, or a shared selection/review owner
- the server-retained run envelope, context receipt, protocol negotiation, or run TTL specified here
- the wire projections and reason-code vocabulary specified here

Implementation MUST project over existing storage where possible. It MUST NOT rename or migrate the activity table lifecycle merely to make its stored names match the wire vocabulary.

## 3. Non-goals

Protocol 1.0 does not provide:

- raw `wp.data.select()`, `wp.data.dispatch()`, arbitrary store names, selector names, or action names
- a generic WordPress entity read/write API
- a generic Ability executor
- publication, scheduling, trashing, plugin installation, or arbitrary `savePost()` operations
- mouse, keyboard, drag, hover, focus, modal, sidebar, inserter, or editor-shell emulation
- WebMCP execution of legacy editor-local recommendation mutations
- shared selection state across browser tabs or users
- cross-target atomicity, compensation, rollback sagas, or an `applyBatchId`
- undo of an undo
- a replacement for the existing dedicated MCP server or its Ability projection

Semantic editor tools such as a future guarded `wordpress.editor.insert_block` interface are a separate module. They are not hidden extensions of this recommendation protocol.

## 4. Load-bearing invariants

1. **Gutenberg owns live editor state.** WebMCP reads a bounded semantic projection and never manipulates Gutenberg infrastructure directly.
2. **Abilities own domain execution and authorization.** A WebMCP tool is a workflow projection, not a new permission system.
3. **The page store is the sole owner of mutable recommendation workspace state.** The human UI and WebMCP use the same actions and reducer.
4. **Runs are generated evidence; plans are derived state.** `applyPlan` is not part of an immutable run.
5. **Apply is not save.** A pending activity row proves only that an apply request was persisted. It does not prove that the recommended target change persisted.
6. **Client `can*` values are preflight only.** Every governed Ability reauthorizes the exact live server target in its `permission_callback` and executor.
7. **Advisory units never become applied and never acquire undo state.**
8. **Stage-only units are not WebMCP-applicable in protocol 1.0.** A human may use an existing first-party editor flow, but `complete_recommendation_apply_request` MUST refuse them.
9. **Every requested context seam appears in exactly one receipt category.** Silent context loss is a protocol violation.
10. **Generated text and model-proposed operations are untrusted.** Apply dereferences a server-retained result and revalidates its bounded operations; it never accepts arbitrary replacement operations from a tool caller.
11. **One protocol 1.0 apply request targets one executor, one independently revalidatable baseline, and one persistent target.**
12. **Any uncertainty after a persistent mutation may have begun is `recovery_required`.** It MUST NOT be reported as ordinary success or `failed_no_writes`.
13. **Tools named `read_*` are observational.** They may project time-based expiry but MUST NOT persist expiry, prune rows, dispatch store cleanup, or otherwise change state during their execute callbacks.

## 5. Architecture and ownership

```text
Human UI ───────────────┐
                       ├─> RecommendationWorkspace in the page wp.data store
WebMCP projection ──────┘          │
                                  ├─> recommendation Abilities
                                  │       └─> server-retained RecommendationRun/results
                                  │
                                  └─> governed request-apply Ability
                                          └─> pending activity row
                                                  │
Settings > AI Activity admin decision ────────────┤
                                                  └─> executor + persisted outcome
                                                          │
WebMCP status/undo projection ────────────────────────────┘
```

### 5.1 Domain layer

Flavor Agent Abilities remain transport-neutral use-case contracts. Their input schemas, permission callbacks, validators, executors, and output schemas remain authoritative.

### 5.2 Workflow layer

The recommendation workspace composes context configuration, one or more recommendation Abilities, selection, review, and target grouping into a user- and agent-meaningful workflow.

### 5.3 Projection layer

The eight WebMCP tools expose the workflow to an agent in the current editor page. They MUST be registered through the imperative `document.modelContext.registerTool()` interface, registered once per page lifecycle, and removed with an `AbortSignal` when the owning UI unmounts or the editor scope changes.

Every tool descriptor MUST explicitly provide the complete section 16 annotation record: `readOnlyHint`, `untrustedContentHint`, and `consequentialHint`. In the pinned upstream contract, `consequentialHint: true` signals that executing the tool has significant, real-world, or non-reversible consequences and lets a client or agent selectively require user confirmation. All three annotations are behavioral hints, not authorization controls; Flavor Agent still enforces every permission and target boundary itself.

## 6. Wire conventions and identifiers

JSON field names use `camelCase`. Enum values, state values, error codes, and reason codes use lowercase `snake_case`.

| Identifier | Meaning |
|---|---|
| `workspaceId` | Opaque UUID for one page/editor-scope workspace |
| `workspaceRevision` | Monotonic compare-and-swap revision for mutable page workspace state |
| `actorSessionId` | Opaque UUID identifying one human or agent interaction session within a page |
| `runId` | Opaque UUID for one immutable recommendation generation |
| `resultRef` | Opaque server-retained reference to one surface result; not a target identity and not a bearer authorization token |
| `unitId` | Stable opaque identifier for one recommendation unit within a run |
| `planRevision` | The exact `workspaceRevision` from which an `applyPlan` was derived |
| `targetGroupKey` | Server-derived key for one independently revalidatable executor/target/baseline group |
| `applyRequestId` | Durable identifier for one governed pending/apply activity row |
| `idempotencyKey` | Caller-generated opaque key bound by the server to one normalized operation |
| `applyBatchId` | Reserved for protocol 2.0; MUST NOT appear in protocol 1.0 responses |

An opaque identifier MUST NOT be treated as proof of access. Reads and writes recheck the authenticated WordPress user and target permissions.

## 7. Protocol negotiation and compatibility

### 7.1 Negotiation

The first call MUST be `read_recommendation_workspace` with a non-empty ordered `supportedProtocolVersions` array.

The server selects the first mutually supported version and returns it as `protocolVersion`. If there is no match, it returns `unsupported_protocol_version`, includes `supportedProtocolVersions`, and changes no state.

Every later tool input MUST include the exact `protocolVersion` selected for the workspace/run. A run is pinned to that version until it expires.

### 7.2 Compatibility rules

- Lifecycle enums, `SurfaceId`, `InteractionMode`, `ExecutionClass`, required fields, and field semantics are closed. Adding, removing, or changing them requires a new major protocol version.
- A minor version MAY add optional fields whose absence preserves existing behavior.
- `error.code`, `reason.code`, and `warning.code` are open string sets. Minor versions MAY add codes. Clients MUST tolerate unknown codes and use `category` plus `retryDisposition` for generic behavior.
- `additionalProperties: false` applies to the exact negotiated-version input schema, not to an optimistic union of minor versions. A client that supports `1.1` but negotiates `1.0` MUST serialize the exact `1.0` input and strip every `1.1`-only field. A `1.0` server MUST reject those fields rather than silently accepting them.
- When one page bundle registers more than one supported minor version, its public input schema MUST use version-discriminated `oneOf` branches (or semantically equivalent separate registrations). Every branch remains closed with `additionalProperties: false`; validation selects only the branch named by `protocolVersion`.
- Clients MUST ignore unknown optional **response** properties allowed by a compatible minor version and MUST NOT infer permission from them. This output-tolerance rule does not weaken closed input validation.
- Protocol 1.0 clients MUST reject an `applyBatchId` or a lifecycle value such as `compensated` as an incompatible major-version response.

## 8. Surface and interaction contract

### 8.1 Surface identifiers

```text
block
content
pattern
navigation
template
template_part
post_blocks
global_styles
style_book
```

Current internal hyphenated keys such as `template-part`, `global-styles`, and `style-book` are adapter details. The wire values are normalized to snake case.

`SurfaceId` identifies a wire result/editor scope; it is not required to map one-to-one to an Ability ID. The eight current recommendation Abilities map to the nine wire surfaces as follows:

| Recommendation Ability | Wire surface result |
|---|---|
| `flavor-agent/recommend-block` | `block` |
| `flavor-agent/recommend-content` | `content` |
| `flavor-agent/recommend-patterns` | `pattern` |
| `flavor-agent/recommend-navigation` | `navigation` |
| `flavor-agent/recommend-style` | `global_styles` or `style_book`, selected by the invocation's closed style scope |
| `flavor-agent/recommend-template` | `template` |
| `flavor-agent/recommend-template-part` | `template_part` |
| `flavor-agent/recommend-post-blocks` | `post_blocks` |

If one run requests both `global_styles` and `style_book`, the workflow invokes `flavor-agent/recommend-style` separately for each scope and emits one result for each requested `SurfaceId`. `post_blocks` is a genuine generating surface as well as an executor surface; it MUST remain requestable and MUST receive its own result entry when requested.

### 8.2 Interaction modes

```text
multi_select_batch
single_review
ranked_choice
advisory
single_multi_operation
```

| Surface | Interaction mode | Typical unit execution classes | Protocol 1.0 behavior |
|---|---|---|---|
| `block` | `multi_select_batch` | `stage_only`, `advisory` | Multiple bounded suggestions may be selected; the existing human editor may stage safe local changes, but WebMCP cannot execute them |
| `content` | `advisory` | `advisory` | Generated/editorial output only |
| `pattern` | `ranked_choice` | `stage_only`, `governed_apply` | Select one ranked pattern; native insertion remains available, and a unit is governed only when the completed run retained a valid `post_blocks` executor binding |
| `navigation` | `advisory` | `advisory` | Guidance only |
| `template` | `single_review` | `governed_apply`, `advisory` | Select and review one bounded recommendation |
| `template_part` | `single_multi_operation` | `governed_apply`, `advisory` | Select one recommendation containing an ordered bounded operation list |
| `post_blocks` | `single_multi_operation` | `governed_apply`, `advisory` | Select one recommendation containing an ordered bounded operation list for one post/page |
| `global_styles` | `single_multi_operation` | `governed_apply`, `advisory` | Select one reviewed style recommendation |
| `style_book` | `single_multi_operation` | `governed_apply`, `advisory` | Select one reviewed block-style recommendation |

Pattern is part of the canonical shared surface contract even though it is absent from the current `SURFACE_INTERACTION_CONTRACT` object.

The execution-class column is descriptive, not a per-surface capability gate. Section 8.3 and each immutable unit's validated execution binding are authoritative.

### 8.3 Execution classes

Execution class belongs to each `RecommendationUnit`, not to the whole surface result. This permits a result to contain safe actionable and advisory units without lying about either.

```text
advisory
stage_only
governed_apply
```

- `advisory`: may be read or acknowledged; never enters an executable target group; apply status and undo status are `not_applicable`.
- `stage_only`: has an existing editor-local/human execution path; may be selected and reviewed; it is excluded from executable groups with reason `stage_only_not_webmcp_applicable`.
- `governed_apply`: may enter an apply target group and can be requested only through the relevant Ability-backed governed lane.

The legacy name `editor_mutation` MUST NOT appear on the wire.

For a `pattern` unit, `governed_apply` has one narrow meaning:

1. `flavor-agent/recommend-patterns` ranks an allowlisted pattern for the current context.
2. Before the run becomes immutable, the server-side pattern executor compiler resolves one exact editable post/page target and one exact server-collected insertion anchor, creates a bounded `insert_pattern` operation, validates it through the post-blocks structural grammar, resolves the post-blocks freshness/baseline evidence, and retains the result in the same format required by `flavor-agent/request-post-blocks-apply`.
3. Only a unit with that complete retained binding is emitted as `governed_apply`. Its `sourceSurfaceId` is `pattern`; its `executorSurfaceId` is `post_blocks`; and its executor Ability is `flavor-agent/request-post-blocks-apply`.
4. If the target or insertion anchor is absent, ambiguous, stale, locked, unsupported, or invalid, the compiler performs no fallback inference and emits the pattern unit as `stage_only`.

The compiler is not a second public result surface and does not accept caller-supplied operations. If `post_blocks` was not requested, its internal retained executor binding MUST NOT create an extra `results` entry; section 13.3 still requires exactly one entry only for each explicitly requested `SurfaceId`. A directly generated `post_blocks` unit has both `sourceSurfaceId` and `executorSurfaceId` equal to `post_blocks`.

## 9. Gutenberg seam policy

The context bridge is deny-by-default. Raw Gutenberg selectors and actions are implementation inputs, not public tool names.

| Policy level | Meaning in this protocol |
|---|---|
| `expose_read_only` | Serialize bounded semantic state into an allowlisted context path |
| `expose` | Low-risk reversible editor interaction; outside the eight recommendation tools unless explicitly defined by this document |
| `wrap_with_guard` | Use internally behind scope, capability, lock, size, and postcondition checks; never expose the raw selector/action |
| `require_confirmation` | Route through a specialized permission-checked workflow with explicit human confirmation/approval |
| `never_expose` | Keep implementation, registry, lifecycle, generic CRUD, editor-shell, and transient human-input APIs behind the boundary |

The following rules are mandatory:

- Never expose arbitrary `select(store, selector, args)` or `dispatch(store, action, args)`.
- Never serialize functions, React elements, edit/save components, callbacks, transforms containing functions, or registry objects.
- Block trees, post content, patterns, variations, inserter items, settings, and notices MUST be bounded and sanitized.
- `canEditBlock`, insertion/move/removal checks, locks, allowed-block information, and template validity MAY inform constraints, but remain preflight rather than authorization.
- Typing, dragging, hover, caret internals, editor setup/reset, optimistic transaction internals, store receive actions, registry mutation, preferences, modals, sidebars, keyboard shortcuts, and viewport UI MUST NOT cross the public boundary.
- Persistent entity mutation MUST use a specialized Ability-backed lane rather than generic core-data CRUD.

## 10. Context configuration and levers

`ContextConfiguration` is mutable workspace state. `configure_recommendation_context` replaces it atomically; it does not accept arbitrary selector paths.

```json
{
  "surfaceIds": ["block", "pattern"],
  "intent": {
    "goal": "Improve the campaign landing section",
    "audience": "First-time visitors",
    "tone": "Direct and confident",
    "constraints": ["Keep the existing call to action"]
  },
  "focus": {
    "scope": "selected_block",
    "clientIds": ["opaque-client-id"]
  },
  "additionalContextGroups": ["theme_tokens", "recent_outcomes"],
  "detailLevel": "balanced",
  "recentActivity": "outcomes_only",
  "surfaceParameters": {}
}
```

### 10.1 Required fields and limits

| Field | Contract |
|---|---|
| `surfaceIds` | One to nine unique `SurfaceId` values |
| `intent.goal` | Required non-empty string, maximum 1,000 characters |
| `intent.audience` | Optional string, maximum 300 characters |
| `intent.tone` | Optional string, maximum 200 characters |
| `intent.constraints` | At most 12 strings, each at most 240 characters |
| `focus.scope` | `selected_block`, `selection`, `document`, or `site_editor_entity` |
| `focus.clientIds` | At most 20 IDs; valid only for selected-block/selection focus; exact live IDs are re-resolved before use |
| `additionalContextGroups` | Unique values from the allowlist below; adds optional context but cannot suppress mandatory safety context |
| `detailLevel` | `compact`, `balanced`, or `detailed`; changes presentation within hard caps and never raises the hard caps |
| `recentActivity` | `none` or `outcomes_only`; raw activity payloads and prompts are never model context |
| `surfaceParameters` | Closed object described below; omitted means all defaults |

Protocol 1.0 permits only this optional surface parameter:

```json
{
  "content": {
    "mode": "draft"
  }
}
```

`content.mode` is `draft`, `edit`, or `critique`. No other `surfaceParameters` key is valid in protocol 1.0. Pattern visibility, editor targets, template/style scope, available operations, and safety flags are collected from the live editor/server context; a caller cannot inject them through this object.

### 10.2 Context group allowlist

```text
document_identity
document_summary
block_selection
block_tree
block_constraints
block_registry
pattern_catalog
theme_tokens
theme_style_summary
template_structure
navigation_structure
save_publication_state
recent_outcomes
docs_grounding
```

Every surface owns a mandatory minimum context profile. An agent may add optional groups or request a lower/higher detail projection, but it MUST NOT remove identity, freshness, permission-preflight, lock, or operation-validation context required by the surface.

## 11. Context receipt and signature

### 11.1 Receipt categories

Every requested seam path MUST occur exactly once across these arrays:

```text
included
summarized
truncated
omitted
unavailable
```

| Category | Meaning |
|---|---|
| `included` | The bounded normalized value was supplied without further reduction |
| `summarized` | A declared summary was supplied instead of the source value |
| `truncated` | A bounded subset was supplied because a hard limit was reached |
| `omitted` | The value was available but policy or explicit optional-context configuration excluded it |
| `unavailable` | The value could not be resolved, read, or authorized at capture time |

A receipt reports provenance, not the context values themselves.

```json
{
  "included": [
    {
      "path": "document.identity",
      "source": "core/editor",
      "capturedAt": "2026-08-27T12:00:00Z",
      "consumerSurfaceIds": ["block", "pattern"]
    }
  ],
  "summarized": [
    {
      "path": "document.block_tree",
      "source": "core/block-editor",
      "capturedAt": "2026-08-27T12:00:00Z",
      "strategy": "bounded_tree_v1",
      "sourceItemCount": 42,
      "consumerSurfaceIds": ["block", "pattern"]
    }
  ],
  "truncated": [],
  "omitted": [],
  "unavailable": []
}
```

Category-specific requirements:

- `summarized` MUST include `strategy`.
- `truncated` MUST include `limit` and MAY include `sourceItemCount` when that count is safe to disclose.
- `omitted` and `unavailable` MUST include a `reasonCode`.
- Every entry MUST include `path`, `source`, `capturedAt`, and `consumerSurfaceIds`.
- Paths and sources MUST come from allowlists. They MUST NOT reveal arbitrary entity content or selector arguments.

### 11.2 Run context signature

```json
{
  "algorithm": "sha256",
  "collectorVersion": "recommendation-context-v1",
  "value": "lowercase-hex-digest",
  "capturedAt": "2026-08-27T12:00:00Z"
}
```

The digest MUST cover canonical JSON containing:

- `protocolVersion`
- authenticated site/user scope as non-exported binding data
- `editorScopeKey`
- the normalized `ContextConfiguration`
- the normalized context actually supplied to recommendation generation
- the receipt category/reason for requested seams that were omitted or unavailable
- the collector version

Timestamps, random IDs, transient editor-shell state, and values classified `never_expose` MUST NOT be signature inputs.

The run signature proves which shared snapshot informed generation. It does not replace each Ability's exact target/review/resolved signatures. Governed apply MUST use the server-owned target signatures and live baseline checks stored with the selected result.

## 12. `RecommendationWorkspace`

### 12.1 Identity and scope

`RecommendationWorkspace` is keyed to one editor page instance and its primary editor scope:

```text
workspaceKey = editorInstanceId + editorScopeKey
```

- `editorInstanceId` is a random UUID created for each top-level editor tab/page load.
- `editorScopeKey` identifies the primary editing context, such as `post:42`, `wp_template:<id>`, `wp_template_part:<id>`, `global_styles:<id>`, or `style_book:<id>:<block-name>`.
- `workspaceId` is the opaque public UUID for that composite binding.
- Site and authenticated WordPress user are implicit security bindings. They are not accepted from the caller as authority.

Changing selected blocks does not create a new workspace. It changes context configuration and supersedes the current run. Navigating the same editor application to a different primary entity creates a new workspace. An unsaved entity uses an editor-instance-bound temporary scope; first save creates a new workspace bound to the canonical entity identity.

Protocol 1.0 does not synchronize workspace state between tabs. Two tabs editing the same entity have independent `workspaceId` and `workspaceRevision` values.

### 12.2 Revision scope

`workspaceRevision` starts at `0` and increments once for every successful semantic mutation to:

- normalized context configuration
- current-run installation or supersession relationship
- selection
- review state/ownership
- derived apply plan

It does not guard:

- WordPress document content or post revisions
- immutable run payloads
- activity/apply/undo records
- cached apply-status hydration
- another browser tab or another user's editor

Cross-tab and cross-user document changes are detected by WordPress entity versions, live context signatures, target baselines, locks, and server reauthorization at request and execution time.

Revision effects are exact:

| Tool/event | `workspaceRevision` effect |
|---|---|
| `read_recommendation_workspace` | None |
| Semantic `configure_recommendation_context` change | Increment once |
| No-op context replacement | None |
| Successful current-run installation | Increment once |
| Generation that loses its compare-and-swap race | None |
| Successful `configure_recommendation_selection` | Increment once, including its derived-plan replacement |
| Successful `start_recommendation_review` or human review takeover | Increment once |
| `complete_recommendation_apply_request` | None; it validates the revision but stores the resulting activity/status outside workspace CAS state |
| Apply-status hydration or `read_recommendation_apply_status` | None |
| `complete_recommendation_undo_request` | None |

A single action that changes selection and its derived plan increments once, not once per field.

### 12.3 Workspace shape

```json
{
  "protocolVersion": "1.0",
  "workspaceId": "uuid",
  "workspaceRevision": 7,
  "editorScope": {
    "key": "post:42",
    "kind": "post",
    "entityId": "42"
  },
  "contextConfiguration": {},
  "currentRun": {
    "runId": "uuid",
    "relationship": "current",
    "supersededReason": null
  },
  "selection": {},
  "review": {},
  "applyPlan": {}
}
```

`currentRun.relationship` is `current` or `superseded`. Supersession is a workspace relationship, not a mutation of the immutable run payload.

### 12.4 Context changes and in-flight generation

After canonical normalization:

- A no-op configuration replacement does not increment `workspaceRevision`.
- A semantic configuration change increments the revision, clears selection/review/apply plan, and marks the current run relationship `superseded` with reason `context_configuration_changed`.
- A superseded run remains server-retained until its TTL, but it is not apply-eligible through that workspace.
- A pending `applyRequestId` already created from the old run is independent. It is neither canceled nor rewritten; approval still performs live server revalidation.
- Recommendation generation captures `expectedWorkspaceRevision`. If the workspace changes before generation finishes, the resulting run may be retained for bounded diagnostics, but it MUST NOT become current. The tool returns `workspace_changed_during_generation` and zero selection/plan changes.

## 13. `RecommendationRun` and results

### 13.1 Run shape

```json
{
  "protocolVersion": "1.0",
  "runId": "uuid",
  "workspaceId": "uuid",
  "baseWorkspaceRevision": 6,
  "status": "ready",
  "createdAt": "2026-08-27T12:00:00Z",
  "completedAt": "2026-08-27T12:00:05Z",
  "expiresAt": "2026-08-27T12:30:05Z",
  "intent": {},
  "contextSignature": {},
  "contextReceipt": {},
  "results": []
}
```

The generated payload—intent snapshot, context signature/receipt, results, units, and retained native payload—is immutable after completion. Lifecycle availability may later project the run as `expired`.

### 13.2 Run status

```text
ready
partial
failed
expired
```

- `ready`: every requested surface has a `ready` result.
- `partial`: at least one requested surface is `ready`, and at least one is `failed` or `unavailable`.
- `failed`: no requested surface produced a `ready` result.
- `expired`: the active run TTL elapsed. Result references can no longer be dereferenced for a new apply request.

A surface that succeeds with zero suggestions is still `ready` and returns an empty `units` array.

### 13.3 Partial-run rules

- `results` MUST contain exactly one entry for every requested surface, including failures and unavailable surfaces. Failed surfaces MUST NOT disappear from the array.
- A `partial` run MAY produce apply groups from `ready` results.
- A group is blocked if it depends on a failed/unavailable result or missing unit.
- The plan MUST display failed/unavailable surface entries and blocking reasons; it MUST NOT silently omit them.
- The run `contextSignature` covers the shared captured envelope, including receipt entries marked omitted/unavailable. Each successful result also carries its server-owned exact freshness fields.
- If the shared context identity or mandatory target identity cannot be established, executable groups MUST be blocked even when an advisory surface succeeded.

### 13.4 Surface result shape

```json
{
  "surfaceId": "template_part",
  "status": "ready",
  "resultRef": "opaque-server-reference",
  "interactionMode": "single_multi_operation",
  "contextPaths": ["document.identity", "document.template_structure"],
  "freshness": {
    "resolvedContextSignature": "opaque-signature",
    "reviewContextSignature": "opaque-signature"
  },
  "units": [],
  "error": null
}
```

`SurfaceResult.status` is the closed enum:

```text
ready
failed
unavailable
```

`failed` means generation was attempted and failed. `unavailable` means prerequisites, permission, capability, target, or provider conditions prevented generation from starting.

### 13.5 Recommendation unit shape

```json
{
  "unitId": "opaque-unit-id",
  "sourceSurfaceId": "pattern",
  "title": "Add a compact utility row",
  "summary": "Adds a bounded utility row before the navigation block.",
  "executionClass": "governed_apply",
  "executionBinding": {
    "executorSurfaceId": "post_blocks",
    "executorAbilityId": "flavor-agent/request-post-blocks-apply",
    "operationSchemaVersion": "post-blocks-v1"
  },
  "operationCount": 1,
  "dependencies": [],
  "warnings": []
}
```

`sourceSurfaceId` MUST equal the containing result's `surfaceId`. `executionBinding` is required for `governed_apply` and absent for `advisory` and `stage_only`; it identifies the governed lane but does not reveal the retained result reference or operations. A run MUST NOT add, remove, or replace an execution binding after completion.

Agent-facing unit text is bounded and untrusted. Full native operations and any internal executor-result reference remain behind `resultRef`; apply uses the retained payload and Ability schema rather than operations supplied by the caller.

## 14. Selection, review, and apply-plan derivation

### 14.1 Selection

Selection is a complete replacement, not a merge patch:

```json
{
  "runId": "uuid",
  "selectedUnitIds": ["unit-a", "unit-b"],
  "acknowledgedAdvisoryUnitIds": ["unit-c"]
}
```

The store validates the surface's `InteractionMode` atomically:

- `multi_select_batch`: zero or more selectable unit IDs up to the surface limit
- `single_review`, `ranked_choice`, and `single_multi_operation`: at most one selected unit for that surface
- `advisory`: no executable selection; acknowledgement is optional and never enters a plan group

Unknown, expired, superseded, failed-surface, or duplicate unit IDs cause the entire selection change to fail with zero state mutation.

### 14.2 Review coordination

`start_recommendation_review` creates page-local review state:

```json
{
  "state": "active",
  "ownerKind": "agent",
  "ownerActorSessionId": "uuid",
  "unitIds": ["unit-a"],
  "leaseExpiresAt": "2026-08-27T12:05:00Z"
}
```

- The default page-local review lease is five minutes.
- The owner MAY renew it through another successful owner review action.
- A second agent receives `review_owned_by_other_actor` until the lease expires or the human takes over.
- Direct human selection/review input wins: it cancels an agent lease, increments the workspace revision, and causes stale agent writes to receive `workspace_conflict`.
- An agent MUST NOT capture recommendation context or mutate selection/review while the editor interaction guard is busy. The tool returns `editor_busy` with one derived `busyReason`, never raw selector values.
- Review ownership is coordination only. It does not authorize apply and is unrelated to the admin activity review claim.

The protocol 1.0 editor interaction guard is codeable and fail-closed. One page-owned `EditorInteractionGuard` MUST:

- attach `compositionstart`, `compositionend`, `beforeinput`, and `input` listeners to the editor shell document and each same-origin editor-canvas iframe document; only trusted human events update its state
- remove every listener through the page registration `AbortSignal`
- use a monotonic clock and a fixed 750 ms quiet window after the latest trusted `beforeinput` or `input`
- take one synchronous registry snapshot of the supported Gutenberg selectors when evaluated
- treat a missing selector/store, detached canvas, listener failure, or thrown selector as `guard_unavailable` rather than assuming idle

After required guard inputs resolve, a primary scope-key mismatch returns `workspace_scope_changed` before busy classification. Otherwise the guard evaluates the following table from top to bottom and returns the first true `busyReason`, making simultaneous conditions deterministic:

| `busyReason` | Exact predicate |
|---|---|
| `guard_unavailable` | Any required guard input cannot be observed reliably |
| `editor_scope_transition` | The primary editor entity/scope key is temporarily unresolved during navigation |
| `composition_active` | A tracked editor document received `compositionstart` without the matching `compositionend` or abort |
| `entity_save_active` | The current post is saving/autosaving, the exact scoped entity is reported by core-data as saving, or non-post entity changes are currently saving |
| `block_drag_active` | Gutenberg `isDraggingBlocks()` is true |
| `multi_select_active` | Gutenberg `isMultiSelecting()` is true |
| `recent_human_input` | The monotonic quiet-window deadline has not elapsed, or Gutenberg `isTyping()` is true while that trusted-input window is active |

For `recent_human_input`, `details.retryAfterMs` is the remaining quiet-window duration rounded up and clamped to `0..750`; other reasons omit it. The guard is evaluated immediately before context capture in `complete_recommendation_request`, immediately before the page-state commit in `configure_recommendation_selection` and `start_recommendation_review`, and before apply-request preflight. A failed check performs zero workspace or activity mutation. Server target freshness, lock, permission, and baseline checks still run independently; this page guard is not authorization.

`workspace_busy` is not a protocol 1.0 condition. Live human/editor transitions use `editor_busy`; same-page lost races use `workspace_conflict`; and review-lease contention uses `review_owned_by_other_actor`.

### 14.3 Apply plan

`applyPlan` is derived from the current run plus current selection. It has no durable ID.

```json
{
  "runId": "uuid",
  "planRevision": 8,
  "groups": [
    {
      "targetGroupKey": "opaque-group-key",
      "sourceSurfaceIds": ["pattern"],
      "executorSurfaceId": "post_blocks",
      "executorAbilityId": "flavor-agent/request-post-blocks-apply",
      "operationSchemaVersion": "post-blocks-v1",
      "unitIds": ["unit-a"],
      "operationDigest": "64-lowercase-hex-characters",
      "blockingReasons": []
    }
  ],
  "excludedUnits": [
    {
      "unitId": "unit-b",
      "executionClass": "stage_only",
      "reason": {
        "code": "stage_only_not_webmcp_applicable",
        "category": "validation",
        "message": "This unit uses the existing human editor flow.",
        "retryDisposition": "do_not_retry",
        "details": {}
      }
    }
  ],
  "blockedUnits": []
}
```

A target group contains only units that:

- use `governed_apply`
- share one retained execution binding; `sourceSurfaceIds` are derived from its units while `executorSurfaceId` identifies the Ability-backed lane
- resolve to one canonical persistent target
- execute through one executor invocation
- share one independently revalidatable baseline
- can be applied atomically by that executor
- have all dependencies satisfied

Two operations against the same entity are separate groups when the second requires context produced by the first. A target that does not exist until an earlier operation completes can never be in the earlier group.

`excludedUnits` contains selected advisory/stage-only units and one reason object per unit. `blockedUnits` uses the same shape plus the reasons that prevented a nominally governed unit from entering a group.

If selection contains no executable group, plan derivation still succeeds and records advisory/stage-only exclusions. A later apply request returns the single deterministic top-level error `no_executable_group`. `advisory_not_applicable` and `stage_only_not_webmcp_applicable` are per-unit plan exclusion reason codes, never competing top-level errors.

### 14.4 Operation digest

`operationDigest` is a server-computed SHA-256 digest over this normalized envelope:

```json
{
  "digestVersion": "operation-digest-v1",
  "protocolVersion": "1.0",
  "operationSchemaVersion": "post-blocks-v1",
  "executorAbilityId": "flavor-agent/request-post-blocks-apply",
  "executorSurfaceId": "post_blocks",
  "canonicalTarget": {
    "siteScopeId": "1",
    "targetKind": "post",
    "targetId": "42",
    "scopeKey": "post:42",
    "subtargetKey": null
  },
  "baselineSignatures": {},
  "operations": []
}
```

`canonicalTarget` is a closed normalized object. `siteScopeId` is the current server-owned site/blog binding; `targetKind` is one of `post`, `template`, `template_part`, `global_styles`, or `style_book`; `targetId` is the canonical string identity of the persistent entity; `scopeKey` is the exact activity/workspace target scope; and `subtargetKey` is `null` except for an executor-defined subtarget such as the Style Book block name. `baselineSignatures` is also closed per `operationSchemaVersion`: it contains every and only the resolved-context, review-context, target, and lock/version signature that the named request-apply Ability requires, using their schema-defined keys.

The server MUST first validate and normalize `canonicalTarget`, `baselineSignatures`, and `operations` through the exact executor/Ability schemas named by `operationSchemaVersion`. It then serializes the envelope with the JSON Canonicalization Scheme defined by RFC 8785: object properties sorted per that scheme, list order preserved, UTF-8 strings and JSON numbers serialized canonically, and no insignificant whitespace. Duplicate object keys, invalid UTF-8, non-finite numbers, unsupported value types, or a value that cannot be represented by the selected operation schema fail plan derivation closed. The final wire value is the lowercase 64-character hexadecimal SHA-256 digest of those canonical JSON bytes.

Display title/summary/rationale, confidence/ranking metadata, warnings, timestamps, random IDs, actor/session IDs, idempotency keys, lifecycle/decision state, `resultRef`, and `targetGroupKey` are excluded. Target identity, operation order, normalized operation values, operation schema version, executor identity, and every baseline/freshness signature required by that executor are included.

The client never supplies or calculates authoritative digest input. The server computes the digest when deriving the plan, then dereferences the retained execution binding, normalizes it again, and recomputes the digest during apply-request validation. A mismatch returns `plan_stale` with zero writes. Live target-baseline comparison is a separate subsequent check; a mismatched live baseline returns `stale_context` even when the retained digest is internally consistent.

Implementation MUST extract a shared canonical-JSON/digest utility rather than reuse an attestation-private helper by convention. The current `StatementBuilder::canonical_json()` key sorting and list-order behavior are useful grounding, but protocol conformance additionally requires the complete RFC 8785 number/string rules and the explicit envelope and exclusion list above.

## 15. Common tool result envelope

Success:

```json
{
  "ok": true,
  "protocolVersion": "1.0",
  "data": {}
}
```

Failure:

```json
{
  "ok": false,
  "protocolVersion": "1.0",
  "error": {
    "code": "workspace_conflict",
    "category": "conflict",
    "message": "The recommendation workspace changed.",
    "retryDisposition": "refresh_workspace",
    "details": {}
  }
}
```

`protocolVersion` MAY be `null` only when initial negotiation fails. A failure response MUST describe zero, known-complete, or potentially uncertain effects. It MUST NOT return a bare `ok: false` after a possible mutation.

## 16. The eight WebMCP tools

Tool names are stable for protocol 1.x.

The annotation records below are complete. Implementations MUST NOT rely on upstream defaults. All eight tools may return generated or externally derived content and therefore use `untrustedContentHint: true`; only the two observational tools use `readOnlyHint: true`; and only the persistent undo operation uses `consequentialHint: true`.

### 16.1 `read_recommendation_workspace`

**Kind:** read

**Annotations:** `readOnlyHint: true`, `untrustedContentHint: true`, `consequentialHint: false`

Input:

```json
{
  "supportedProtocolVersions": ["1.0"]
}
```

Returns the selected protocol version, supported versions, current workspace snapshot, surface capabilities, current run relationship, selection/review/plan, and run-expiry projection. It MUST NOT create or adopt a run implicitly.

### 16.2 `configure_recommendation_context`

**Kind:** configure/stage

**Annotations:** `readOnlyHint: false`, `untrustedContentHint: true`, `consequentialHint: false`

Required input:

```text
protocolVersion
workspaceId
expectedWorkspaceRevision
actorSessionId
contextConfiguration
```

Atomically replaces the normalized context configuration. On a semantic change it supersedes the current workspace/run relationship and clears selection, review, and plan as defined in section 12.4.

Returns the new workspace revision, normalized configuration, and any superseded run ID.

### 16.3 `complete_recommendation_request`

**Kind:** complete a generation task

**Annotations:** `readOnlyHint: false`, `untrustedContentHint: true`, `consequentialHint: false`

Required input:

```text
protocolVersion
workspaceId
expectedWorkspaceRevision
actorSessionId
idempotencyKey
```

Captures the context envelope, invokes the requested surface Abilities, retains results server-side, and returns only after the run reaches `ready`, `partial`, or `failed`.

The run becomes current only if `expectedWorkspaceRevision` still matches at installation. A retry with the same idempotency key and normalized configuration returns the same run. The same key with a different configuration returns `idempotency_conflict`.

### 16.4 `configure_recommendation_selection`

**Kind:** configure/stage

**Annotations:** `readOnlyHint: false`, `untrustedContentHint: true`, `consequentialHint: false`

Required input:

```text
protocolVersion
workspaceId
expectedWorkspaceRevision
actorSessionId
runId
selectedUnitIds
acknowledgedAdvisoryUnitIds
```

Validates and atomically replaces selection, then derives a new plan. It returns the new workspace revision, normalized selection, and plan.

### 16.5 `start_recommendation_review`

**Kind:** start/navigate within the supported product workflow

**Annotations:** `readOnlyHint: false`, `untrustedContentHint: true`, `consequentialHint: false`

Required input:

```text
protocolVersion
workspaceId
expectedWorkspaceRevision
actorSessionId
runId
unitIds
```

Validates that the units are selected, reviewable, current, and fresh enough to preview. It creates/renews the page-local review lease and updates the visible first-party review state before returning.

It does not authorize apply, create an activity row, or mutate site content.

### 16.6 `complete_recommendation_apply_request`

**Kind:** complete a governed request-creation task

**Annotations:** `readOnlyHint: false`, `untrustedContentHint: true`, `consequentialHint: false`

Required input:

```text
protocolVersion
workspaceId
expectedWorkspaceRevision
actorSessionId
runId
planRevision
idempotencyKey
```

Optional input:

```text
targetGroupKey
```

Rules:

- `expectedWorkspaceRevision`, `planRevision`, and the current workspace revision MUST be equal before any request row is created; otherwise the tool returns `plan_stale` with zero writes.
- If the plan has zero executable groups, the tool returns `no_executable_group` and performs zero writes, regardless of whether the selected exclusions are advisory, stage-only, blocked, or mixed. This check takes precedence over optional `targetGroupKey` validation.
- If the plan has one executable group, `targetGroupKey` MAY be omitted.
- If the plan has more than one executable group, omitting it returns `multiple_target_groups` and performs zero writes.
- The caller can name exactly one group. Arrays or comma-separated groups are invalid; a supplied key that is not in the current plan returns `target_group_not_found`.
- Advisory and stage-only units are never included.
- Before requiring an active run, the tool checks the authenticated durable idempotency/dedupe index for an already accepted exact request. An exact hit returns the existing `applyRequestId`, even if the source run has since expired; it creates no new row.
- For a new request, the tool requires `now < run.expiresAt` when dereferencing the retained execution binding, resolves operations from `resultRef`, revalidates the run, recomputes `operationDigest`, checks the exact target, current permissions, and request-time freshness, then invokes the matching request-apply Ability.
- Immediately before the pending-row insert, after all asynchronous validation and in the same commit boundary as the final workspace revision comparison, the server reads its authoritative clock again. If `now >= run.expiresAt`, it returns `run_expired` and creates no row. This second check is mandatory even if dereference passed, and MUST live inside the Ability/repository row-creation boundary rather than only in the page adapter before its request.
- Success creates or deduplicates one pending activity row and returns `applyRequestId`, `applyStatus: pending`, `expiresAt`, `deduplicated`, and `attributionPreserved`.
- It does not approve the request and does not mutate the target.

### 16.7 `read_recommendation_apply_status`

**Kind:** read

**Annotations:** `readOnlyHint: true`, `untrustedContentHint: true`, `consequentialHint: false`

Required input:

```text
protocolVersion
applyRequestId
```

Returns the normalized apply lifecycle, decision metadata visible to the current user, failure/recovery reasons, normalized undo status, and blocked reasons. It remains usable after the source run expires, subject to activity retention and permission.

This tool MUST be a no-write projection. The current admin feed already demonstrates a pure overdue projection, but the current get/list Ability path calls `ActivityRepository::maybe_expire_pending_apply()` and can persist expiry. Before either public `read_*` tool is registered, implementation MUST extract/reuse a pure status projector, remove mutating expiry from every WebMCP read dependency, and derive `expired` from `expiresAt`; cron or an authorized decision/action path may persist that transition separately. Until that prerequisite is complete, the eight-tool protocol surface MUST remain unregistered because this tool cannot truthfully use `readOnlyHint: true`.

### 16.8 `complete_recommendation_undo_request`

**Kind:** complete a persistent reversal

**Annotations:** `readOnlyHint: false`, `untrustedContentHint: true`, `consequentialHint: true`

Required input:

```text
protocolVersion
applyRequestId
idempotencyKey
```

The host SHOULD obtain explicit user confirmation because undo persistently changes the target. A schema boolean is not proof of confirmation and MUST NOT replace permission checks.

The tool invokes the governed undo Ability for the activity row, enforces ordered undo, reauthorizes the target, verifies the recorded after-state, executes the reverse operation, verifies the result, and persists the one-way terminal outcome.

Repeating the same completed undo returns `undoStatus: undone` with result code `already_undone`.

## 17. Workspace concurrency

Every mutating page tool MUST compare `workspaceId` and `expectedWorkspaceRevision` immediately before commit.

```text
expected revision matches
    -> commit the complete semantic mutation
    -> increment exactly once

expected revision differs
    -> workspace_conflict
    -> return current revision/snapshot reference
    -> perform zero mutation
```

This protects human-versus-agent and agent-versus-agent changes in the same page. It does not claim cross-tab collaboration.

For `complete_recommendation_apply_request`, the final workspace comparison and the final `run.expiresAt` comparison occur after validation and immediately before the pending-row insert. Neither an earlier workspace read nor an earlier run dereference satisfies these commit-time checks.

The human UI MUST dispatch through the same workspace actions. Component-local checkbox state must move into the shared workspace before WebMCP selection is enabled.

## 18. Idempotency and attribution

### 18.1 Recommendation generation

The generation idempotency binding is:

```text
authenticated site/user
+ workspaceId
+ expectedWorkspaceRevision
+ normalized ContextConfiguration digest
+ idempotencyKey
```

### 18.2 Apply request

The server computes a dedupe fingerprint over:

```text
authenticated site/user
+ runId
+ planRevision
+ targetGroupKey
+ operationDigest
```

`operationDigest` is the authoritative server value defined in section 14.4. Dedupe never hashes presentation text or caller-supplied operation JSON.

`actorSessionId` is deliberately not part of that fingerprint. Two actors submitting the exact same plan/group get one `applyRequestId`.

Attribution follows first-writer-wins:

- The row records the authenticated WordPress user and first accepted `actorSessionId`/request provenance.
- A deduplicated caller does not overwrite requester attribution, prompt, timestamps, or reference fields.
- The duplicate response sets `deduplicated: true` and `attributionPreserved: true`.
- Actor details are returned only when the current user is authorized to see them.
- Reusing an idempotency key or fingerprint with a different normalized payload returns `idempotency_conflict`.

## 19. Governed apply lifecycle

### 19.1 Wire state enum

```text
pending
executing
applied
rejected
expired
failed_no_writes
recovery_required
```

### 19.2 Valid transitions

```text
pending   -> rejected
pending   -> expired
pending   -> executing
executing -> applied
executing -> failed_no_writes
executing -> recovery_required
```

`rejected` is an admin decision on a pending request. `expired` is the pending TTL elapsing. Neither is reachable from `executing`.

### 19.3 No `approved` state in protocol 1.0

Admin approval claims and executes the request synchronously in one decision transaction:

```text
pending
  -> acquire exact internal decision claim
  -> reauthorize and revalidate
  -> execute
  -> verify and persist one terminal outcome
```

There is no durable approved-but-not-started window, so protocol 1.0 does not invent an `approved` state. A poll may observe `pending`, then `executing`, then a terminal state; fast execution may skip an observable `executing` response.

The internal `claim:<token>` owner projects as `executing` without exposing its token. A malformed or unverifiable claim MUST fail closed as `recovery_required` or a status-reconciliation error; it MUST NOT project as applied.

If approval becomes asynchronous in a future protocol, adding an `approved` state requires a major version.

### 19.4 Terminal semantics

- `applied`: target postconditions and durable activity outcome both verify.
- `failed_no_writes`: the system proves no persistent target write occurred.
- `recovery_required`: a target write may have occurred but final target/activity consistency cannot be proven.
- `rejected`: an authorized administrator rejected the pending request; no target write occurred.
- `expired`: the pending request expired before execution; no target write occurred.

An existing stored `failed` value may project as `failed_no_writes` only when stored phase/evidence proves no writes. Otherwise it projects as `recovery_required`.

Approval remains the existing admin REST/UI action. It is deliberately not one of the eight WebMCP tools and not an agent-callable approval Ability.

## 20. Undo lifecycle

### 20.1 Wire state enum

```text
not_applicable
available
blocked
undoing
undone
failed
recovery_required
```

### 20.2 Valid transitions and projections

```text
not_applicable                         (advisory or never executed)
available <-> blocked                  (runtime projection)
available -> undoing                   (tool operation begins)
undoing  -> undone
undoing  -> failed
undoing  -> recovery_required
```

- `blocked` is a reversible runtime projection; it does not rewrite persisted undo state.
- `undoing` is an operation-level transient and need not be persisted.
- `failed` means the undo attempt is terminal under the existing one-way activity contract and no uncertain successful rollback is being claimed.
- `recovery_required` means a rollback write may have occurred but the target/activity record cannot be reconciled automatically.
- Existing diagnostic activity state `review` is not an undo state and MUST NOT appear here.

### 20.3 Blocked reason object

```json
{
  "code": "newer_activity",
  "category": "ordering",
  "message": "Undo newer AI activity on this target first.",
  "retryDisposition": "resolve_newer_activity",
  "details": {}
}
```

Initial blocked reason codes include:

```text
newer_activity
target_drift
target_missing
permission_denied
post_locked
template_locked
editor_busy
executor_unavailable
legacy_activity_unsupported
status_reconciliation_required
```

These codes are an open set. Unknown codes remain blocking and are handled through `category` and `retryDisposition`.

### 20.4 Undo is terminal

An undo does not create a recursively undoable recommendation activity. `undone` is one-way and terminal.

To apply a similar change again, the user or agent creates a fresh recommendation run against fresh context. Native editor redo is local Gutenberg behavior and is not a governed protocol transition.

## 21. Expiry and retention

### 21.1 Recommendation run

- Default active run TTL: 30 minutes from completion.
- The server returns authoritative `expiresAt`; clients MUST NOT calculate eligibility from the default alone.
- `resultRef` expires with the run for new apply requests. A run is active only while the authoritative server time is strictly earlier than `expiresAt`.
- A new apply request checks that condition both when dereferencing its retained binding and again in the commit boundary immediately before pending-row creation. Expiry at either check returns `run_expired` with zero new rows.
- An exact retry/dedupe lookup for an apply request already accepted before expiry runs first and MAY return its existing `applyRequestId`; this is a read of existing durable state, not a new request created from an expired run.
- An expired run retains a minimal tombstone for 24 hours after expiry. During that period, reads return `run_expired` and the original `runId` without result content.
- After tombstone retention, reads return `run_not_found`.
- Page selectors project an expired run's plan as empty/ineligible without mutating the workspace. A `read_recommendation_workspace` call MUST NOT dispatch expiry cleanup or increment the revision.

### 21.2 Apply request and activity

- Pending apply TTL remains independent, defaulting to the existing 24 hours.
- Creating an apply request before the run deadline copies the validated normalized operations, signatures, digest envelope fields, and target evidence required by the activity row. Once that row commits, later run expiry does not cancel the request.
- `read_recommendation_apply_status` is keyed by `applyRequestId`, so it continues to work after run expiry.
- Activity retention remains independent, defaulting to the existing 90 days.

No API may report a reaped run as an apply-status failure when the caller supplied a valid retained `applyRequestId`.

## 22. Errors, reasons, and retry behavior

### 22.1 Error categories

```text
validation
conflict
busy
authorization
stale
expired
unavailable
rate_limited
internal
recovery
```

### 22.2 Retry dispositions

```text
retry_same
refresh_workspace
refresh_context
regenerate
wait
resolve_newer_activity
request_permission
manual_recovery
do_not_retry
```

### 22.3 Initial error-code registry

```text
unsupported_protocol_version
workspace_not_found
workspace_scope_changed
workspace_conflict
workspace_changed_during_generation
editor_busy
review_owned_by_other_actor
run_superseded
run_expired
run_not_found
surface_unavailable
partial_dependency_failed
selection_invalid
plan_stale
no_executable_group
multiple_target_groups
target_group_not_found
stale_context
authorization_failed
idempotency_conflict
apply_request_not_found
undo_blocked
recovery_required
```

Error/reason codes are open. Clients MUST display the bounded message, preserve unknown code values for diagnostics, and follow the known `retryDisposition` instead of treating an unfamiliar code as success.

Clients MUST NOT branch on localized `message` text.

`no_executable_group` has category `validation` and retry disposition `do_not_retry` for the unchanged plan; its bounded details include only counts of governed, excluded, and blocked units. `editor_busy` has category `busy` and retry disposition `wait`. These mappings are stable for protocol 1.x.

### 22.4 Plan exclusion reason codes

These initial codes explain why a selected unit did not enter an executable group:

```text
advisory_not_applicable
stage_only_not_webmcp_applicable
dependency_not_satisfied
execution_binding_unavailable
```

They are per-unit `reason.code` values in the plan, not top-level apply errors. If every selected unit is excluded or blocked, `complete_recommendation_apply_request` always returns `no_executable_group`.

## 23. Confirmation and authorization

The five-level seam policy and WordPress permissions serve different purposes:

- Host or product confirmation communicates user intent for destructive/persistent operations.
- Ability `permission_callback` and executor checks authorize the authenticated user and exact target.
- Context and Gutenberg `can*` checks are UX/preflight evidence.
- WebMCP annotations help the host describe behavior. In particular, `consequentialHint: true` may cause a host to require confirmation, but it neither records nor proves that confirmation.

None substitutes for another.

`complete_recommendation_apply_request` has `consequentialHint: false`: its invocation creates only a pending, reviewable request and relies on a separate authorized admin approval before target mutation. `complete_recommendation_undo_request` has `consequentialHint: true` because its invocation performs a persistent reversal and SHOULD receive explicit host/user confirmation, but a `confirmed: true` input would not be security and is intentionally absent.

## 24. Worked protocol 1.0 examples

### 24.1 Add a campaign section, then refine it

This is sequential because the refinement target does not exist before insertion.

1. Configure the `pattern` surface for the current post/page and request recommendations.
2. The completed pattern result includes a ranked unit whose immutable execution binding contains a validated `post_blocks` `insert_pattern` operation for one exact insertion anchor. If that binding could not be built, the unit is `stage_only` and this governed path stops.
3. Select the governed pattern unit. The derived group has `sourceSurfaceIds: ["pattern"]`, `executorSurfaceId: "post_blocks"`, and executor Ability `flavor-agent/request-post-blocks-apply`.
4. Call `complete_recommendation_apply_request` for that single post target group.
5. Poll `read_recommendation_apply_status` until `applied`, `rejected`, `expired`, `failed_no_writes`, or `recovery_required`.
6. After `applied`, refresh editor context and create a new run. Do not reuse the old signature or plan.
7. Select the new refinement unit.
8. If it is `governed_apply`, create a second apply request. If it is `stage_only`, WebMCP stops and the human may apply it through the existing editor UI.

There is no batch ID, cross-step atomicity, or automatic compensation if the second step fails.

### 24.2 Make this hero clearer and adjust Global Styles

This mixes a block-local stage-only unit with a governed Global Styles unit.

1. The run returns a `block` result and a `global_styles` result.
2. Selection records both, but the plan puts the block unit in `excludedUnits` with reason `stage_only_not_webmcp_applicable` and the Global Styles unit in one executable group.
3. The human reviews/stages the block change in Gutenberg. WebMCP cannot report it as applied.
4. The changed block context supersedes the old run. Generate a new run before requesting the Global Styles change.
5. Select and request the fresh Global Styles target group.

One `complete_recommendation_apply_request` never claims both changes.

### 24.3 A partial run

1. Block and content results succeed; pattern generation is unavailable because its index is not ready.
2. The run status is `partial` and contains three result entries.
3. The pattern result is `unavailable` with a reason and empty units.
4. Content remains advisory. Block units remain stage-only in protocol 1.0.
5. No governed apply group exists, so `complete_recommendation_apply_request` deterministically returns `no_executable_group` with zero writes. The plan retains `advisory_not_applicable` and `stage_only_not_webmcp_applicable` only as unit exclusion reasons.

If a ready governed surface had no dependency on the unavailable pattern result, its target group would remain requestable.

## 25. Current storage-to-wire projection

### 25.1 Apply

| Current storage/public concept | Protocol 1.0 wire value |
|---|---|
| `apply.status: pending` | `pending` |
| valid internal decision `claim:<token>` | `executing`; token omitted |
| `apply.status: available` plus `execution_result: applied` | `applied` |
| `rejected` | `rejected` |
| `expired` | `expired` |
| `failed` with proof no write began | `failed_no_writes` |
| `failed`, invalid claim, or uncertain post-mutation/storage phase without proof | `recovery_required` |

### 25.2 Undo

| Current concept | Protocol 1.0 wire value |
|---|---|
| `available` | `available` |
| runtime ordered-undo block | `blocked` plus reasons |
| `undone` | `undone` |
| persisted `failed` with no uncertain rollback claim | `failed` |
| governed undo recovery error | `recovery_required` |
| `not_applicable` | `not_applicable` |
| diagnostic `review` | excluded from the undo enum; represented by activity kind |

Client-only presentation values such as `idle`, `applying`, `success`, `error`, and `stale` are not protocol lifecycle states.

## 26. Security and privacy requirements

- All public tool schemas use `additionalProperties: false` at every closed object boundary.
- All strings, arrays, trees, operation counts, and retained payloads have explicit size limits.
- `resultRef` dereference is scoped to the authenticated site/user and run; it is not bearer access.
- Generated recommendation text is untrusted content and never becomes executable code or unvalidated block markup.
- Apply accepts IDs/digests, not arbitrary operation replacement payloads.
- Permission checks occur at discovery/read where needed, request creation, admin decision, execution, status read, and undo.
- Target identity validation precedes capability checks so malformed/divergent identities fail consistently.
- Context receipts disclose paths and bounded provenance, not secret values, credentials, private prompts, or unrestricted content.
- Recent-activity context is outcomes-only and excludes raw prompts, generated text, block attributes, post content, and before/after payloads.
- Errors are bounded and MUST NOT expose decision claim tokens, stack traces, credentials, provider payloads, or content the caller cannot read.
- Registration failure, unsupported WebMCP, or a missing Ability causes tools to be absent or return unavailable. The bridge MUST NOT fall back to raw store dispatch or generic REST.

## 27. Hard implementation prerequisites and ordering

These are required work items, not descriptive caveats. Protocol 1.0's eight-tool registration MUST remain absent until every P0 item below is implemented and its focused tests pass; a partially truthful subset MUST NOT advertise itself as this protocol version.

| Order | Required work item | Completion gate |
|---|---|---|
| P0.1 | Extract pure `projectApplyLifecycle(entry, now)` and `projectRunAvailability(run, now)` functions. Route each WebMCP `read_*` tool and every read dependency through the applicable projector. Remove `maybe_expire_pending_apply()` from the WebMCP get/list path; keep persistent expiry only in cron and authorized action/decision paths. | Read tests assert zero database writes, zero store dispatches, and stable projected expiry before, at, and after the deadline. This item is a hard prerequisite for sections 16.1 and 16.7. |
| P0.2 | Implement a shared `operation-digest-v1` canonicalizer and typed envelope builder as specified in section 14.4; do not leave it inside an attestation-specific class. | Cross-language fixtures cover key ordering, list ordering, Unicode, every supported numeric form, rejected non-finite/invalid input, operation-order sensitivity, exclusions, and all four governed executors. |
| P0.3 | Implement the page-owned `EditorInteractionGuard` and iframe lifecycle from section 14.2. | Deterministic fake-clock and selector-failure tests cover every `busyReason`, the 750 ms boundary, abort cleanup, scope mismatch, and fail-closed unavailable state. |
| P0.4 | Implement exact negotiated-version schema branches and downgrade serializers from section 7.2. | A `1.1`-capable client negotiating `1.0` emits no `1.1` fields; a `1.0` branch rejects every unknown input property while compatible clients tolerate optional response additions. |
| P0.5 | Implement authenticated retained runs, tombstones, and the durable apply-request idempotency/dedupe index, including the double run-expiry check from sections 16.6 and 21. | Race tests pause between dereference and insert; a deadline reached before insert creates zero rows, while an exact already-created request remains readable/deduplicable after source-run expiry. |
| P0.6 | Implement the pattern-to-post-blocks executor compiler from section 8.3. | A governed pattern unit exists only with one server-collected target/anchor, a grammar-valid `insert_pattern`, complete post-blocks signatures, and an immutable retained binding; every ambiguity yields `stage_only` and no hidden result-array entry. |
| P0.7 | Move human checkbox selection/review into the sole workspace store, implement page-scope CAS, and derive target groups using source/executor bindings. | Human/agent races, nine-surface result completeness, style's two scoped invocations, pattern executor routing, and deterministic zero-group behavior pass before WebMCP registration. |

After P0, implementation order is:

1. register the eight closed-schema tools behind one protocol-capability gate, with the exact three-field annotation records in section 16
2. connect them only to the completed workspace/run/projector/compiler primitives
3. run the section 28 contract, repository, and browser evidence
4. enable the capability only where every required Ability and editor dependency is available

Missing a prerequisite fails closed by leaving protocol 1.0 unregistered; it does not fall back to raw Gutenberg dispatch, generic Ability execution, a mutating read, or stage-only execution.

## 28. Verification contract

Implementation is not conformant until the following evidence exists.

### 28.1 Pure contract tests

- Protocol-version negotiation and mismatch with zero writes
- Closed enum validation and unknown open reason/error-code tolerance
- Receipt exact-partition invariant across all five categories
- Context signature determinism and exclusion of timestamps/transient UI state
- `operationDigest` canonical-envelope fixtures, exclusions, and recomputation mismatch
- Exact negotiated-version downgrade with closed input branches
- Surface/mode selection cardinality
- Per-unit execution-class plan partitioning
- Eight-Ability-to-nine-surface mapping, including separate style-scope results
- Governed pattern source binding to a post-blocks executor without an extra result entry
- Partial-run result completeness and dependency blocking
- Target-group derivation, source/executor distinction, deterministic `no_executable_group`, and multi-group refusal
- Storage-to-wire apply/undo projection
- Run expiry/tombstone behavior and the dereference-to-insert expiry race
- Observational read projections, including expiry, perform zero writes

### 28.2 Workspace tests

- Same-page human/agent and agent/agent compare-and-swap conflicts
- Two tabs on the same entity remain independent workspaces
- Entity navigation creates a new workspace
- Context changes supersede the current relationship and clear selection/review/plan
- Generation completing after a workspace change cannot install itself
- Human takeover cancels agent review ownership
- Every exact editor-busy predicate, quiet-window boundary, iframe cleanup, and unavailable dependency fails closed
- `editor_busy`, `workspace_conflict`, and `review_owned_by_other_actor` remain non-overlapping conditions; `workspace_busy` never appears
- Component checkbox state and WebMCP reads observe the same store owner

### 28.3 Ability and activity tests

- Every governed request reuses the existing Ability and exact permission callback
- Request creation writes one pending row and performs no target mutation
- Rejection/expiry branch directly from pending
- Approval has no durable `approved` state and projects a valid claim as `executing`
- `failed_no_writes` requires explicit proof; ambiguous failures become `recovery_required`
- First-writer attribution survives deduplication
- Repeated exact request returns the same `applyRequestId`
- A run expiring after dereference but before pending-row insert creates no row
- An exact accepted apply request deduplicates after its source run expires
- Reused idempotency key with different payload is rejected
- Undo blocked reasons and retry dispositions are stable
- Repeated completed undo returns `already_undone`
- No undo-of-undo activity is created

### 28.4 WebMCP tests

- Exactly eight tools register on supported editor pages
- Tool names and schemas match this document
- Read/configure/start/complete semantics match their names
- Registration is cleaned up with `AbortSignal`
- Unsupported browsers and registration failures fail closed
- Each execute callback waits for visible workspace state to settle before returning
- Results are concise and JSON-serializable; every descriptor's complete annotation object exactly matches section 16, including `consequentialHint: true` only for `complete_recommendation_undo_request`
- No raw Gutenberg selector/action or generic Ability executor is exposed

### 28.5 Repository gates

Because this crosses every recommendation surface plus shared context, freshness, apply, activity, and undo contracts, implementation triggers all applicable gates in `docs/reference/cross-surface-validation-gates.md`:

- nearest targeted PHP and JS suites
- `node scripts/verify.js --skip-e2e`
- `npm run check:docs`
- Playground and WP 7.0 Playwright harnesses matching all touched surfaces
- an explicit blocker or waiver for any unavailable browser harness

## 29. Protocol 2.0 boundary

Protocol 2.0 may introduce:

- server-retained collaborative workspaces shared across tabs/users
- `applyBatchId`
- multiple child `applyRequestId` values under one batch
- all-target preflight
- ordered multi-target execution
- compensation policy and `compensated`/`compensation_required` states
- asynchronous approval with a durable `approved` state
- governed WebMCP execution of editor-local operations after they have exact target IDs, capability checks, postconditions, activity, and undo contracts

None of those semantics may be partially implemented under protocol 1.0 names.

## 30. Source anchors

The upstream WebMCP annotation contract was verified on 2026-09-04 against [`webmachinelearning/webmcp` commit `7b3f50f31848b529e69bedbbdf8da0edccba055f`](https://github.com/webmachinelearning/webmcp/commit/7b3f50f31848b529e69bedbbdf8da0edccba055f), which adds `consequentialHint` to `ToolAnnotations` with a default of `false`.

The repository anchors below were reverified on 2026-09-04 against tracked `master` commit `4728d2a7588ff554293b60a900e387372b32f3a9`. Line 205 in `Registration.php` is the `external_apply_ability_classes()` declaration. Blob IDs pin the reviewed committed bytes even when later line numbers drift.

| File | Snapshot blob |
|---|---|
| `src/store/index.js` | `af96d00dfb1924ff9cb88d5c1b0c7b349eece9ed` |
| `src/inspector/BlockRecommendationsPanel.js` | `117cee61ee562e492721e1377d1a8c646cbc7ea9` |
| `src/store/activity-history.js` | `349280a2dae62ea3023accc3f173ff60475d7bc3` |
| `inc/Abilities/Registration.php` | `1e0b723b50aee8967809e9078e00a10caa8448f3` |
| `inc/Abilities/StyleAbilities.php` | `c2225d792b4f99dbb48a2df6c9a64937317af2f8` |
| `inc/Abilities/PostBlocksAbilities.php` | `f80c2857e7cdfe4e420d67f250de2f845933bf12` |
| `inc/Attestation/StatementBuilder.php` | `f120990dda9b155c10b37be8c6e80dc20f7004f3` |
| `inc/Activity/Repository.php` | `90ea756087019785e353a814a9b05ad8b7105cfe` |
| `inc/Abilities/ApplyAbilities.php` | `7c93bc1f091a39ccb191bd5e2861ec554b1b85cd` |
| `docs/reference/activity-state-machine.md` | `a3a4cd2835248f4fcf072757c4855ab643ce4efd` |
| `docs/reference/abilities-and-routes.md` | `825d3bcf47d3ee2a0be5ab169593eec4bdd89e51` |
| `docs/reference/cross-surface-validation-gates.md` | `0b934609874af8b7785f65fa4aa993265aa57648` |

Grounding details:

- `src/store/index.js:1-6` — current recommendation state is per tab
- `src/store/index.js:198-255` — current shared surface contract; pattern is missing
- `src/inspector/BlockRecommendationsPanel.js:555-605` — block selected/applied key `Set` state and its local reset/toggle handling are currently component-local
- `src/store/activity-history.js:404-468` — current entity/style scope-key conventions
- `inc/Abilities/Registration.php:154-197` — eight recommendation Ability registrations
- `inc/Abilities/Registration.php:205-243` — governed external apply/read/undo Ability registrations; line 200 is documentation and line 205 is the method declaration
- `inc/Abilities/Registration.php:266-284` — current external-apply annotations
- `inc/Abilities/StyleAbilities.php:81-103` — one recommendation Ability validates either Global Styles or Style Book scope
- `inc/Abilities/PostBlocksAbilities.php:17-34` — post-blocks is a recommendation surface with the structural insertion grammar
- `inc/Attestation/StatementBuilder.php:340-373` — existing deterministic key sorting/list-order behavior that informs, but does not complete, `operation-digest-v1`
- `inc/Activity/Repository.php:551-560` — current admin-feed pure-read overdue guard
- `inc/Activity/Repository.php:1265-1291` — current mutating lazy-expiry helper
- `inc/Abilities/ApplyAbilities.php:830-870` — current activity get/list reads invoke the mutating helper and therefore require P0.1
- `docs/reference/activity-state-machine.md:23-41` — current pending approval/decision claim lifecycle
- `docs/reference/activity-state-machine.md:43-100` — current undo states, transitions, and ordered-undo behavior
- `docs/reference/abilities-and-routes.md` — current Ability schemas, permissions, dedicated MCP exposure, and REST decision route
- `docs/reference/cross-surface-validation-gates.md` — required multi-surface release evidence

Each table row identifies committed snapshot bytes; uncommitted working-tree content is never source-anchor evidence.

## 31. Design decisions resolved by this document

- `RecommendationWorkspace` is page/editor-scope keyed; `workspaceRevision` is same-page CAS, not document-global CAS.
- `RecommendationRun` contains both `contextSignature` and `contextReceipt`; `applyPlan` belongs to the workspace.
- Eight recommendation Abilities produce nine wire surfaces because `recommend-style` is invoked independently for Global Styles and Style Book; `post_blocks` remains a genuine generator.
- Execution class belongs to an immutable unit. A governed pattern unit uses a retained `post_blocks` executor binding; a pattern without that exact binding remains stage-only.
- `applyRequestId` is the only durable protocol 1.0 apply identifier. `bundleId` and `applyPlanId` are retired.
- `operationDigest` is a server-owned RFC 8785 canonical-envelope SHA-256 value with versioned inputs and exclusions.
- Apply and undo have distinct state enums.
- Rejection and expiry branch from `pending`, not `executing`.
- Protocol 1.0 intentionally has no durable `approved` state.
- `partial` is defined and conditionally applicable per dependency-complete target group.
- A plan with zero executable groups always returns `no_executable_group`; advisory/stage-only codes are unit reasons only.
- Undo `blocked` carries machine-readable open reason codes and retry dispositions.
- Any semantic context-configuration change supersedes the current run relationship and clears its plan.
- Apply-request attribution is first-writer-wins under actor-independent deduplication.
- Error/reason codes are open sets; lifecycle and capability enums remain closed.
- Exact negotiated-version input schemas stay closed; a newer client must fully down-convert after negotiating an older minor version.
- `editor_busy` has an exact page guard; `workspace_busy` is not a protocol 1.0 response code.
- Both `read_*` tools require pure expiry projection before registration.
- Every WebMCP tool explicitly carries all three pinned annotations; only persistent undo is consequential, while pending apply-request creation is not target mutation and remains non-consequential.
- New apply requests check run expiry at dereference and again immediately before row creation; exact pre-existing requests remain deduplicable after run expiry.
- Mixed-surface examples are explicit sequential workflows, not cross-target atomic promises.
- `editor_mutation` is replaced by `stage_only`, which is not WebMCP-applicable in protocol 1.0.
- Compensation and cross-target sagas are absent from protocol 1.0.
- Context receipts retain `included`, `summarized`, `truncated`, `omitted`, and `unavailable`.
- Undo is one-way and terminal.
