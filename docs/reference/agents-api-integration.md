# Agents API Integration Evaluation

This document is the contract reference for how Flavor Agent should evaluate and adopt Automattic's Agents API substrate.

Use it when you need to answer:

- Which Agents API responsibilities complement Flavor Agent rather than replace it?
- Which integration seam should be implemented first?
- Which Flavor Agent governance guarantees must remain authoritative?
- What evidence is required before Agents API becomes a runtime dependency?

## Evaluation Snapshot

This evaluation is grounded in [Automattic/agents-api](https://github.com/Automattic/agents-api) `main` at commit [`d3e6094`](https://github.com/Automattic/agents-api/commit/d3e60942139f9c07433af6419c82c79309fcbb10) (2026-07-23), plus the repository's published `v0.7.0` release. Re-evaluate the public signatures and release requirements before implementation because the project is pre-1.0 and `main` may be ahead of its latest tag.

**Recommendation:** integrate, but initially as an optional runtime adapter rather than a hard dependency or a replacement for Flavor Agent's existing recommendation and apply paths.

Agents API fills a real gap above the Abilities API: registered agent identity, multi-turn execution, ability-backed tool mediation, principals and capability ceilings, sessions and transcripts, external channels, run control, and workflow primitives. Flavor Agent already supplies the product-specific part that Agents API deliberately does not own: grounded WordPress recommendations, bounded operation schemas, freshness checks, review UX, activity attribution, drift-safe apply, and undo.

The clean boundary is:

```text
wp-ai-client / existing provider adapters
    -> model execution
Agents API
    -> agent identity, conversation loop, principal, session, tool mediation
Flavor Agent Abilities API abilities
    -> governed recommendation, apply-request, activity, and undo tools
Flavor Agent UI and stores
    -> review decisions, audit history, attribution, freshness, execution, undo
```

## Implementation Status

Phases 0 and 1 are implemented. Phase 2 (governed apply requests) and Phase 3 (external channels) are not started, and the deferred memory/workflow work remains deferred.

| Phase | State | Evidence |
| --- | --- | --- |
| 0 — contract fixture | Shipped | `inc/AgentsAPI/Compatibility.php`, `tests/phpunit/AgentsApiAdapterAbsentTest.php`, `tests/phpunit/AgentsApiCompatibilityTest.php`, `tests/phpunit/AgentsApiUpstreamContractTest.php` |
| 1 — read/recommend agent | Shipped | `inc/AgentsAPI/AgentDefinition.php`, `inc/AgentsAPI/Registration.php`, `inc/AgentsAPI/RunContext.php`, `inc/AgentsAPI/DispatchGuard.php`, `tests/phpunit/AgentsApiRegistrationTest.php`, `tests/phpunit/AgentsApiRunContextTest.php`, `tests/phpunit/AgentsApiDispatchGuardTest.php` |
| 2 — governed apply requests | Not started | — |
| 3 — optional external channels | Not started | — |

**What the tests prove, and what they do not.** Most of the suite runs against stubs in `tests/phpunit/support/agents-api-stubs.php`, written from a reading of the upstream source. Those prove the adapter's own logic but not that the reading was correct — a misread would be encoded identically on both sides and stay green. `AgentsApiUpstreamContractTest` closes that gap by loading upstream's real registry, definition object, and tool-policy resolver and driving them with the definition the plugin ships. It skips unless `FLAVOR_AGENT_AGENTS_API_PATH` points at a checkout:

```bash
git clone --branch v0.7.0 --depth 1 https://github.com/Automattic/agents-api.git /tmp/agents-api
FLAVOR_AGENT_AGENTS_API_PATH=/tmp/agents-api vendor/bin/phpunit --filter AgentsApiUpstreamContractTest
```

Treat it as required evidence when raising `MINIMUM_VERSION`; a skip is not a pass. What remains unproven by any test in this repo: that a live `agents/chat` run dispatches these tools and that a real provider turn completes. That needs a running site, which is what the evidence harness below is for.

**Evidence harness (`scripts/demo-agents-api.php`).** Dev-only, `.distignore`'d. Answers "is the Agents API actually driving Flavor Agent on this site, right now?" with observations rather than assertions, in three tiers:

```bash
wp eval-file scripts/demo-agents-api.php                       # tiers A + C: free, deterministic
FA_DEMO_TIERS=A,B,C wp eval-file scripts/demo-agents-api.php   # adds a real provider call
```

- **Tier A — wiring.** Runtime version, agent registered, tool profile shape, deny list survived registration, dispatch guard attached, `check-status` reporting `agentRuntime`.
- **Tier C — boundary.** Generic dispatch of `undo-activity` refused; `request-style-apply` still reachable (proving the guard is scoped, not blanket); no mutation or dispatch tool in the agent profile.
- **Tier B — live run.** A real `agents/chat` turn. Evidence that the runtime dispatched Flavor Agent abilities comes from upstream's own `metadata.agents_api.tool_observability.calls[]`, not from parsing the reply; the run id is then correlated against activity rows. B1 requires `completed === true` and not merely a run id — upstream returns `completed: false` when the agent expects further work, which is a live possibility here because `max_turns` is deliberately 6 against an upstream default of 12, and a truncated conversation is not the gate.

The default tier B prompt drives the full read → recommend loop (list templates, pick one, recommend an improvement) rather than stopping at enumeration. That is deliberate: read-only tools persist no activity row, so a discovery-only prompt leaves B3 with nothing to correlate and the run can never satisfy the gate it exists to test. Override with `FA_DEMO_PROMPT`.

Tier B is opt-in because it spends a real provider call. It reports `inconclusive` rather than `fail` when the model simply chose not to call a tool — that is a model outcome, not a wiring defect. Tier B3 reads `RequestLoggingBridge::should_persist_request_diagnostic()` first and reports `skip` with the setting to change when core AI Request Logging is on and dual logging is off, because in that configuration no diagnostic row is written and a missing correlation would otherwise read as a broken attribution bridge.

The last stdout line is always `DEMO_RESULT={...}` (`schemaVersion`, `status`, `tiers`, `counts`, per-check `{id, label, status, detail}`), matching the shape `scripts/verify.js` emits. Overall `status` is `pass` / `fail` / `incomplete`, and `pass` means the evidence was gathered rather than merely that nothing broke: when tier B is requested, **every** tier B check must pass for the overall to be `pass`. A `skip` counts against the gate exactly as an `inconclusive` does — B3 skips when core AI Request Logging is on and dual logging is off, a real configuration in which B1 and B2 can both pass while no activity-row correlation was ever gathered. Since that correlation *is* the exit gate, reporting it as `pass` would be the harness lying about its own subject.

**Live-install confirmation (2026-07-28).** An Atomic install running WordPress 7.1-beta3 reports Agents API `0.7.0` active — matching `MINIMUM_VERSION` exactly — alongside AI `1.2.0`, MCP Adapter `0.5.0`, Gutenberg `23.5.3`, and the Anthropic / Google / OpenAI provider plugins. Its REST index registers `agents-api/v1` (with `POST /agents-api/v1/chat` taking `agent` + `message`, the shape `scripts/demo-agents-api.php` dispatches), `wp-abilities/v1`, and both MCP servers (`/mcp/flavor-agent`, `/mcp/mcp-adapter-default-server`). `GET`/`POST` to `/mcp/flavor-agent` returns `401 rest_forbidden` unauthenticated, confirming `ServerBootstrap::can_access_transport()` enforces in production. Everything past that point — tool counts, `check-status.agentRuntime`, a live chat turn — requires an authenticated principal and is not observable from outside.

**Pinned release.** `Compatibility::MINIMUM_VERSION` is `0.7.0`. The adapter is written against the upstream registration and tool-policy contracts as of that tag; raise the constant only alongside a re-read of `src/Registry/register-agents.php`, `src/Channels/register-default-agents-chat-handler.php`, and `src/Tools/class-wp-agent-tool-policy.php` upstream plus a passing contract-test run.

**Activation boundary.** Three independent gates, all required: a supported Agents API runtime, `FeatureBootstrap::canonical_contracts_available()`, and the Flavor Agent feature gate. Agents API is deliberately absent from `canonical_contracts_available()` itself, so a Jetpack-only site keeps the existing non-agent runtime and never advertises agent readiness. The gate fails closed on a missing symbol (`class:WP_Agent`, `const:WP_Agent_Tool_Policy::MODE_ALLOW`, …) or an undetectable version, not just on an old version string. Readiness is reported by `flavor-agent/check-status` under a top-level `agentRuntime` key, separate from `backends`.

**Tool profile.** `default_config.enabled_tools` carries status, discovery, preview, recommendation, and scoped activity-read abilities. Three bounds apply on top of it, in order: `Registration::resolve_tools()` strips denied names before they reach the definition; allow-mode `tool_policy` hides anything outside the list; and an unconditional `deny` list is applied last by upstream, so it still holds if the first two are bypassed. The deny list covers the four `request-*-apply` abilities, `undo-activity`, **and upstream's two dispatch meta-abilities** (see below). These are layered, not redundant — the deny list is only load-bearing when the allowlist is contaminated, which is exactly the case `AgentsApiUpstreamContractTest::test_deny_list_survives_a_contaminated_allowlist` simulates against the real resolver. Beneath all three, each ability's own permission callback runs at dispatch. The `flavor_agent_agents_api_tool_allowlist` filter can extend the profile but cannot add a denied ability — the deny list is applied after the filter — and names that do not resolve to a registered ability are dropped rather than declared as broken tools.

This deviates from the Phase 1 bullet above in one respect, deliberately: six read helpers (`get-active-theme`, `get-theme-tokens`, `get-theme-styles`, `list-templates`, `list-template-parts`, `list-patterns`) are allowlisted alongside status/preview/recommendation/activity-read. `recommend-template` and `recommend-template-part` take a `templateRef` / `templatePartRef` an agent cannot invent, and `recommend-style` declares `scope` + `styleContext` required, so without these the Phase 1 exit gate is unreachable for those surfaces. All six are existing read-only abilities already marked `mcp.public`, so this widens discovery, not privilege.

**Tool-profile closure.** A closed allowlist is not only a privilege boundary — it is the model's entire world. A recommendation tool whose supplier is missing stays advertised and fails at call time on an input the model cannot obtain, with nothing failing loudly: registration succeeds and the lane is quietly dead. `get-theme-styles` was omitted in the first cut of this adapter for exactly that reason, leaving the style lane able only to fabricate a scope or fail with `missing_style_scope`. `AgentsApiToolClosureTest` now classifies every required input of every declared tool as supplier / model-suppliable / explicitly unresolved, and fails on anything new — the same property `MCPServerDiscoveryClosureTest` asserts for the dedicated MCP server. One input remains genuinely unresolved: `postId`, because Flavor Agent registers no post-listing ability.

**The `agents/ability-call` escape hatch.** Upstream registers `agents/ability-call`, which invokes *any* registered ability by name. It has no target allowlist — its only guard is a self-recursion check (`src/Tools/register-agent-ability-meta-abilities.php`). A run that can see it reaches every ability on the site, including the five this phase excludes, whatever `enabled_tools` says.

Allow-mode does not stop it. A tool marked `mandatory` by any policy provider is preserved through the allow filter by `WP_Agent_Tool_Policy::resolve()`; only `deny`, applied last via `array_diff_key` with no preserve-check, is unconditional. Both dispatch meta-abilities are therefore named in `deny` rather than merely left out of the allowlist, and `AgentsApiUpstreamContractTest::test_mandatory_dispatch_meta_ability_cannot_survive_the_deny_list` drives the real resolver with a `mandatory` dispatch tool injected to prove it.

Scope of the exposure, stated precisely. What never broke: the target ability's own permission callback still runs under `WP_Ability::execute()`, `agents/ability-call` itself requires `manage_options`, and the four `request-*-apply` abilities only create pending rows an administrator still has to approve. What did break without the deny entry: this agent's declared tool profile, which is the boundary Phase 1 is defined by — and `undo-activity` is the sharp edge, because it reverses an executed apply with no second approval.

Because the deny list names upstream abilities by string literal, an upstream rename would silently disarm it. `test_denied_dispatch_ability_names_still_match_upstream` pins both literals to upstream's own `AGENTS_ABILITY_CALL_ABILITY` / `AGENTS_ABILITY_SEARCH_ABILITY` constants so a rename fails at version-bump time instead of at runtime.

**`DispatchGuard` — the site-wide half.** The deny list above binds one agent definition. It does nothing for a run driven by any *other* agent registered on the site, and a site with the Agents API installed generally has several. `inc/AgentsAPI/DispatchGuard.php` binds the ability instead: it filters upstream's `agents_ability_call_permission` and refuses destructive Flavor Agent abilities regardless of which agent is running.

Registration is deliberately unconditional — not gated on `Compatibility::supported()`. A site on an Agents API version this plugin does not otherwise integrate with still has `agents/ability-call`; hardening that declines to apply itself on an unsupported runtime protects the wrong thing. When Agents API is absent the filter simply never fires.

Scope is narrow, and the narrowness is the design. "Reached through the Abilities API" is not by itself a threat — several Flavor Agent abilities are *designed* to be called that way:

| Path | Guarded | Why |
| --- | --- | --- |
| `agents/ability-call` | Yes | The target name is chosen by a model mid-conversation. Nothing about that choice was authored, reviewed, or declared. |
| Workflow `ability` steps | No | `WP_Agent_Workflow_Runner::default_ability_handler()` dispatches a name written into a workflow spec — a deliberate grant, the same class of act as adding a tool to an agent definition. |
| Declared agent tools | No (bounded elsewhere) | Held by the agent's own tool policy. |
| MCP and REST external-apply lanes | No | The shipped, attested surface. Guarding these would break a released feature. |

What is guarded is narrower still: only abilities whose own declared annotations say `destructive`. Today that is exactly one, `undo-activity`. The four `request-*-apply` abilities are **not** guarded — queuing a pending row *is* the governed loop working, an administrator still decides it in `Settings > AI Activity`, and they are the external-apply surface. `undo-activity` is the one that executes on the live site with no second approval.

Note what this is and is not. `agents/ability-call` requires `manage_options`, and `undo-activity` resolves to a contextual check that `manage_options` already satisfies, so no capability boundary was ever crossed — an administrator could always have undone the row from the admin screen. What the guard restores is *deliberateness*: the difference between an administrator deciding to undo and a model picking `undo-activity` out of the ability registry mid-conversation.

Classification uses two sources because neither alone suffices: a static derivation from first-party registration data (works with no WordPress runtime, and is what the unit tests pin), plus live `destructive` annotation introspection (catches an ability registered inline in `Registration::register_abilities()`, which the static derivation cannot enumerate). The guard only ever narrows — a decision that already denied the call is returned untouched. `flavor_agent_agents_api_dispatch_allowed` is the escape hatch and requires a strict `true`; `flavor_agent_agents_api_dispatch_denied` fires on refusal.

The guard depends entirely on one undocumented upstream filter name and one input key, so `AgentsApiUpstreamContractTest::test_the_dispatch_permission_seam_still_exists_upstream` pins both against real source — a rename would otherwise disarm the guard in total silence, with every unit test still green. `test_generic_dispatch_still_has_no_target_allowlist` asserts the premise the guard rests on, so that if upstream grows its own allowlist the guard is re-argued rather than kept out of habit.

**Budgets.** `max_turns` is 6, below the upstream default of 12. `tool_call_rules` rate-limits read tools after any read anchor (`max_calls` 8) before the run has to commit to a recommendation. Completion is not gated: answering "which templates exist?" is a legitimate run that never needs a recommendation.

**Attribution lifecycle.** The seam is a *pre*-call hook and upstream exposes no post-call counterpart, so nothing signals that a tool call finished. A captured context therefore has to bound itself, or a finished run's identifiers sit in a static for the rest of the process and get stamped onto whatever recommendation runs next — a long-lived WP-CLI process, or a workflow that dispatches an ability step after a chat step. Attribution that is confidently wrong is worse than absent.

Capture is also conditional on the call actually dispatching. Upstream's mediation vocabulary is `proceed` / `reject` / `replace_result` / `pending`, and only `proceed` reaches the ability — the other three short-circuit with a synthesized result. Capturing on those would bind a context to an ability that never ran, which a later non-agent call of that same ability could then consume. The observer runs at `PHP_INT_MAX` so it sees the most final decision available; that is best effort rather than a guarantee, and the two bounds below are what limit the damage if a later callback still rejects.

Two bounds replace the missing signal. The context is bound to the ability the runtime said it was about to dispatch, resolved with upstream's own order (`tool_declaration.ability` → `ability_name` → `metadata.ability_name` → `tool_name`, since a declaration may map a model-facing name onto a different registered ability); and `RunContext::consume()` clears it on read, so one pre-call hook yields at most one attribution. Consumption happens at ability entry in `RecommendationAbilityExecution::execute()`, not inside the request-meta builders — `execute()` returns early for signature-only resolution and for non-array results, and those paths never reach the builders, so consuming there would leave the context set to attribute a later call of the same ability. Both halves are pinned against real upstream source by `test_tool_call_ability_resolution_order_still_matches_upstream` and `test_mediation_context_still_carries_the_keys_run_context_reads` — a drift there would otherwise bind attribution to a name that never executes and stop correlating in silence.

**Attribution.** `RunContext` observes `agents_api_pre_tool_call_decision` and returns the decision unchanged, capturing only agent slug, run id, and session id — normalized and capped at 128 bytes each. Those reach activity rows as `requestMeta.agentRun`; `executionTransport` stays `wp-abilities` because the agent runtime mediates the tool call rather than replacing the ability dispatch path. Bearer tokens, the execution principal, caller context, tool parameters, and transcript content are never read. The hook fires inside the canonical conversation loop, so it covers any runtime driving that loop; a consumer runtime that replaces the loop wholesale would need its own bridge, and correlation is best-effort by design — the activity row remains the audit record either way.

**Packaging.** No production Composer requirement and no `Requires Plugins` relationship, per the dependency decision below. Agents API is a companion plugin; when it is absent the hooks in `flavor-agent.php` never fire.

## Fit Assessment

| Agents API capability | Current Flavor Agent capability | Decision |
| --- | --- | --- |
| Agent registration and provenance | No canonical registered agent identity | Adopt for an optional `flavor-agent` agent definition. |
| Ability-backed tool execution | Flavor Agent exposes recommendation and governed apply abilities | Adopt; this is the strongest low-duplication seam. |
| Principal and capability ceiling | Ability permission callbacks and WordPress capabilities | Layer the ceiling above existing callbacks; never bypass or weaken the ability's own permission check. |
| Conversation loop, budgets, spin detection | Recommendation requests are bounded single executions | Adopt only for conversational/external-agent entry points. Do not route native editor requests through a loop without a product need. |
| Sessions and transcripts | Request tokens are stale-response controls; activity rows are governance records | Keep all three concepts separate. An Agents API session is not an activity record and a Flavor Agent `sessionId` is not automatically a durable conversation ID. |
| Pending-action approvals | Flavor Agent has surface-specific pending applies, admin review, freshness revalidation, execution, attestation, and undo | Do not replace. At most project Flavor Agent decisions into Agents API status/events later. |
| Memory and composable context | Theme/site context collectors, guidelines, docs grounding, activity outcomes | Defer. Persisting inferred preferences changes consent, retention, authority, and deletion contracts. |
| Channels, bridges, and JSON-RPC chat | MCP exposes abilities but Flavor Agent has no shared conversational transport | Strong phase-two value after the tool boundary is proven. |
| Workflows and routines | No general workflow product; apply is an explicit governed state machine | Defer until a concrete workflow is designed. Never express apply/undo invariants only as a generic workflow spec. |
| Agent packages and subagents | No current product requirement | Do not adopt now. |

## Proposed First Integration

### 1. Register one optional agent

When Agents API is active, register a `flavor-agent` definition during `wp_agents_api_init`. Feature detection should be the activation boundary; Flavor Agent must continue to load and serve its existing UI, Abilities API, REST, and MCP surfaces when Agents API is absent.

The definition should include:

- a concise system instruction that positions the agent as a governed WordPress change assistant;
- `meta.source_plugin`, `meta.source_type`, and `meta.source_version` provenance;
- an explicit allowlist of Flavor Agent ability names rather than every discoverable site ability;
- conservative turn and tool-call budgets;
- no memory seed or cross-session personalization in the first phase.

Treat the exact registration keys as versioned upstream API, not as a copied example. Confirm them against the pinned release before coding.

### 2. Expose a least-privilege tool profile

Start with read/recommendation abilities:

- capability and backend status;
- recommendation preview and recommendation abilities;
- activity reads limited by their existing permission callbacks.

Then enable the four `request-*-apply` abilities only after end-to-end principal propagation is proven. These abilities create pending work; they must not be presented or described as direct mutation tools. `undo-activity` should be a separately gated tool because its permission, ordering, drift, and audit implications differ from recommendation.

Do not expose internal REST routes as tools. Agents API already mediates Abilities API abilities, which preserves Flavor Agent's canonical schemas and avoids a second tool contract.

### 3. Preserve the governed mutation boundary

An agent run may recommend and request, but the existing Flavor Agent services remain authoritative for mutation:

1. recommendation abilities collect live context and return signed freshness/review context;
2. request-apply abilities revalidate those signatures and write a bounded pending activity row;
3. a site administrator decides in the existing AI Activity surface;
4. Flavor Agent's executor performs the write and records attribution/attestation;
5. Flavor Agent's ordered, drift-checked undo remains the only supported reversal path.

Agents API's generic action policy or pending-action types may add a runtime-level stop before a tool call, but they must not substitute for this domain-specific state machine. Two independent approval records for one apply would create ambiguous authority and recovery behavior. If the generic approval UI is later useful, implement an adapter over the Flavor Agent activity ID and state rather than creating a second pending action.

### 4. Add conversation transport only after the adapter passes

Once ability mediation is verified, the canonical `agents/chat` surface could provide a conversational entry point for external clients without expanding Flavor Agent's native editor UI into a chat product. Direct channels, the bridge queue, or the JSON-RPC/SSE endpoint should remain optional companion surfaces.

This matches the product guardrail: Flavor Agent remains a governance layer integrated into Gutenberg and wp-admin. The Agents API chat runtime is an external-agent access path, not a new floating editor workspace.

## Provider Strategy

Agents API deliberately separates its runtime loop from provider-specific execution and places provider/model calls in `wp-ai-client`. That aligns with Flavor Agent's preferred WordPress AI Client path.

The first prototype should use the upstream default provider turn adapter only when the pinned Agents API release and the representative WordPress 7.0 environment expose compatible provider/model identifiers. Flavor Agent should not duplicate its prompt assembly inside both `WordPressAIClient` and an Agents API adapter.

Jetpack AI is the important compatibility exception. Flavor Agent currently supports it when canonical WordPress AI feature contracts are absent, while Agents API's realistic provider path assumes `wp-ai-client`. Therefore:

- Agents API integration must not become part of Flavor Agent's existing `canonical_contracts_available()` check;
- Jetpack-only sites keep the current non-agent runtime and do not advertise Agents API chat readiness;
- a Jetpack turn adapter is a separate future project, justified only if the upstream runtime accepts a stable consumer-supplied adapter and the behavior can be tested without weakening provider precedence.

## Identity, Permissions, and Audit Mapping

The execution principal must resolve to an acting WordPress user and a capability ceiling. Tool declarations should state the required WordPress capability, but the registered Flavor Agent ability permission callback remains the final authorization check.

Every agent-originated ability call should preserve or derive:

- WordPress user ID;
- agent slug and run ID;
- session ID when applicable;
- request reference/ability/route already captured by Flavor Agent;
- provider/model/configuration provenance from the actual model execution path.

Add those identifiers to Flavor Agent attribution only through a normalized, size-bounded metadata contract. Never persist bearer tokens, raw caller context, full transcripts, secrets, or unredacted tool parameters in activity rows.

Flavor Agent activity remains the audit source for recommendations, apply decisions, mutations, and undo. Agents API transcripts explain the conversation; they do not prove what changed on the site. Agents API lifecycle events can later link to an activity ID, but should not mirror full activity payloads.

## Dependency and Packaging Decision

Do not add `wordpress/agents-api` to Flavor Agent's production Composer requirements in the first implementation. WordPress.org packages do not install Composer dependencies for users, and bundling a fast-moving shared plugin would complicate ownership and version skew.

Use the normal companion-plugin shape first:

- feature-detect `wp_agents_api_init` and `wp_register_agent`;
- register nothing when unavailable;
- show agent-runtime readiness separately from recommendation/backend readiness;
- document and test the minimum supported Agents API release;
- pin an exact released tag in CI or the local integration fixture;
- fail closed when a required method, hook, or schema is missing.

A hard `Requires Plugins` relationship is appropriate only if a future release makes the agent/chat surface core product behavior, Agents API has a stable compatible release line, and the Jetpack-only support decision has been revisited explicitly.

## Phased Delivery Plan

### Phase 0 — contract fixture

1. Add the pinned Agents API plugin to a dedicated integration environment.
2. Write compatibility tests for registration timing, configured tool allowlists, ability dispatch errors, principal propagation, and absence of the dependency.
3. Record the supported Agents API version in the local environment guide.

**Exit gate:** Flavor Agent behaves identically when Agents API is inactive, and compatibility failures disable only the adapter.

### Phase 1 — read/recommend agent

1. Register the `flavor-agent` definition.
2. Allowlist status, preview, recommendation, and permitted activity-read abilities.
3. Enforce low iteration/tool budgets and test malformed model arguments.
4. Bridge run/session identifiers into redaction-safe request attribution.

**Exit gate:** a least-privilege principal can complete a recommendation through `agents/chat`, while a denied ability and a forged capability ceiling both fail closed.

### Phase 2 — governed apply requests

1. Add request-apply tools with language that clearly states they create pending review items.
2. Exercise stale signatures, pending limits, expiry, duplicate decisions, approval execution failure, and drift-safe undo.
3. Link chat run/session metadata to the Flavor Agent activity ID without duplicating state.

**Exit gate:** no model or external channel can cause a write without the same server-side validation and admin decision required by today's MCP path.

### Phase 3 — optional external channels

1. Evaluate Agents API JSON-RPC/SSE, direct channel, or remote bridge transports against a concrete client requirement.
2. Define token issuance/revocation, session retention, transcript consent, rate limits, webhook replay protection, and incident logging.
3. Keep channel setup outside the native recommendation UI unless a user-facing product decision changes that boundary.

**Exit gate:** transport authentication, capability ceilings, idempotency, cancellation, retention, and redaction have automated coverage and an operator runbook.

### Deferred — memory and workflows

Adopt memory only after there is a user-visible memory policy with consent, authority, retention, deletion, and conflict-resolution rules. Adopt workflows only for a named multi-step user outcome with per-step governance and recovery semantics. Neither should be enabled merely because the substrate provides it.

## Risks and Required Controls

| Risk | Required control |
| --- | --- |
| Pre-1.0 API churn and `main`/tag skew | Pin a released version, use a compatibility adapter, and run contract tests before upgrades. |
| Expanded attack surface through chat, tokens, or bridges | Optional activation, explicit principals, capability ceilings, rate limits, revocation, and least-privilege ability allowlists. |
| Model calls a mutating ability directly | Expose only request-apply abilities; exclude private executors/routes; retain server-side permission and freshness checks. |
| Generic runtime dispatch bypasses the tool profile | Name `agents/ability-call` and `agents/ability-search` in `tool_policy.deny` (allow-mode alone is bypassable by a `mandatory` tool); pin the literals to upstream constants in a contract test. |
| Another agent on the site reaches a destructive ability by reflection | `DispatchGuard` filters `agents_ability_call_permission` site-wide, refusing Flavor Agent abilities annotated `destructive` whichever agent is running; pin the upstream filter name and input key in a contract test. |
| Duplicate approval systems | Keep Flavor Agent pending applies authoritative; adapt status instead of mirroring actions. |
| Audit/transcript data leakage | Store redaction-safe identifiers only; keep secrets, raw inputs, and transcripts out of activity metadata. |
| Session semantics collide with stale-response tokens | Name and model them separately; do not reuse IDs without an explicit mapping table/contract. |
| Provider behavior diverges | Prefer the canonical WordPress AI Client adapter; keep Jetpack fallback independent until separately supported. |
| Product becomes a chat application | Treat chat/channels as optional external entry points; keep Gutenberg/wp-admin recommendation UX unchanged. |

## Validation Matrix for an Implementation PR

The implementation is not complete without automated evidence for:

- Agents API absent, inactive, supported, and unsupported-version boot cases;
- registration occurs only in the upstream registration window and carries provenance;
- tool declarations contain only the intended ability allowlist;
- recommendation success plus schema-invalid and provider-error responses;
- principal user/capability ceiling enforcement and existing ability permission callbacks;
- stale and forged recommendation signatures;
- pending apply cap, expiration, decision race, and execution failure;
- ordered undo, already-undone behavior, and post-apply drift rejection;
- run/session/activity correlation with sensitive fields redacted;
- transcript and activity retention remaining independent;
- relevant MCP and native editor behavior remaining unchanged.

Because this crosses the provider, ability, apply, freshness, and activity subsystems, follow the repository's cross-surface validation gates: targeted PHPUnit and JS tests, `node scripts/verify.js --skip-e2e`, docs checks, and the matching external-agent/apply Playwright evidence. An unavailable browser harness must be recorded as a blocker or explicit waiver.

## Decision Summary

Agents API should become Flavor Agent's optional agent-runtime substrate, not its governance implementation. The initial value is narrow and compelling: register a Flavor Agent identity and let the shared runtime mediate a least-privilege set of existing Flavor Agent abilities. The existing recommendation, review, apply, activity, attestation, and undo contracts stay in place.

Proceed with a small compatibility adapter against a pinned release. Do not add a hard dependency, generic memory, workflows, a second approval store, or a new native chat UI in the first integration.
