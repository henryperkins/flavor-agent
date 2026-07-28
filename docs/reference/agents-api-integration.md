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
