# Connector Approvals Smoke Baseline

Date: 2026-05-21

## Installed AI Stack

- WordPress: 7.1-alpha-62388
- AI plugin: 1.0.0, active
- Provider connector: OpenAI 1.0.2, active; Anthropic 1.0.2, active
- Connector Approval experiment: available and enabled with `wpai_feature_connector-approval_enabled=1`

## First-Denial Pending Entry

```json
{
  "flavor-agent/flavor-agent.php::openai": {
    "caller_type": "plugin",
    "caller_basename": "flavor-agent/flavor-agent.php",
    "caller_name": "Flavor Agent",
    "connector_id": "openai",
    "attempts": 3,
    "first_seen": 1779343384,
    "last_seen": 1779343408
  },
  "flavor-agent/flavor-agent.php::anthropic": {
    "caller_type": "plugin",
    "caller_basename": "flavor-agent/flavor-agent.php",
    "caller_name": "Flavor Agent",
    "connector_id": "anthropic",
    "attempts": 1,
    "first_seen": 1779343384,
    "last_seen": 1779343384
  }
}
```

Expected key format: `caller_basename::connector_id`, for example `flavor-agent/flavor-agent.php::openai`.

## Outcome

- Caller attribution: `flavor-agent/flavor-agent.php`
- Notes: Baseline was captured from the local Docker WordPress stack using WP-CLI after enabling the `connector-approval` experiment and clearing pending approval state. A Flavor Agent chat path with the selected OpenAI model caused AI plugin 1.0.0 to record pending approval entries for Flavor Agent. The extra Anthropic pending entry came from the AI Client preferred-model discovery path before OpenAI model fallback. Final post-approval success still needs the Task 7 manual editor smoke.

## Implementation Verification

Date: 2026-05-21

- `composer run test:php -- --filter WordPressAIClientTest`: passed, 36 tests / 204 assertions.
- Focused connector approval JS suites: passed, 13 suites / 341 tests.
- `npm run check:docs`: passed.
- `node scripts/verify.js --skip-e2e`: passed with `VERIFY_RESULT={"status":"pass","summaryPath":"output/verify/summary.json","counts":{"total":9,"passed":6,"failed":0,"skipped":3}}`.

## Final Smoke Notes

- Pending approvals still record `flavor-agent/flavor-agent.php::openai` and `flavor-agent/flavor-agent.php::anthropic` in the local stack.
- A direct WP-CLI chat invocation after aggregate verification returned `missing_text_generation_provider` before reaching Connector Approval, so the final post-approval success smoke was not completed in this pass.
- The editor UI approval notice and non-admin handoff behavior are covered by unit tests; the local runtime post-approval retry remains pending a representative configured text-generation provider state.
