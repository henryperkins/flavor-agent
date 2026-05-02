# Helper Abilities And REST Release Surface

Source: [`../release-surface-scope-review.md`](../release-surface-scope-review.md#helper-abilities-and-rest)

## Release Role

Helper abilities and REST routes are infrastructure for first-party
recommendation surfaces and compatible WordPress integrations. They are not an
open-ended external tool system.

Release verdict: keep as infrastructure.

Release quality: release-ready if public ability metadata is intentional and
permission gates are explicit.

## Stop Line

Ship:

- Surface-specific recommendation abilities.
- Read-only helper abilities that provide context, diagnostics, and
  discoverability.
- Permission-gated synced-pattern, theme, token, and docs grounding helpers.
- Accurate annotations for supported read-only behavior.

Do not ship:

- A general external tool catalog.
- Mutating helper abilities outside first-party review/apply contracts.
- Provider-routing abilities.
- Site-agent orchestration abilities.

## Next Steps

- [ ] Mark which abilities are release-supported public contracts and which are
  internal or diagnostic.
- [ ] Keep REST and Abilities contracts aligned.
- [ ] Confirm each ability has explicit capability and backend gating.
- [ ] Keep docs grounding cache-only for recommendation paths where required.
- [ ] Keep mutating ability behavior limited to first-party review/apply
  contracts.

## Verification Gate

- [ ] Re-run targeted PHP registration tests after metadata changes.
- [ ] Re-run targeted PHP route tests after metadata changes.

