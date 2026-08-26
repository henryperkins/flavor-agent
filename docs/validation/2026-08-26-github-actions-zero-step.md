# GitHub Actions zero-step diagnosis - 2026-08-26

Checked at 2026-08-26T06:30Z against repository
`henryperkins/flavor-agent` and commit
`4831b4e000b1f004e00baccdfdfaacffc4bfa861`.

## Result

Status: **hosted CI unavailable before checkout**.

GitHub did not assign a runner or execute any repository-controlled step for
the current `Verify`, WordPress.com artifact, or scheduled corpus-update jobs.
Each examined job reports:

- `runner_id: 0`
- an empty `runner_name`
- `steps: []`
- conclusion `failure`
- annotation: `The job was not started because your account is locked due to a billing issue.`

The annotation is GitHub's scheduling decision and occurs before checkout.
These failures are therefore neither passing evidence nor evidence of a Flavor
Agent build/test regression. Editing workflow YAML cannot repair an account
billing lock.

## Runs and jobs

### Verify

- Run: [32878176704](https://github.com/henryperkins/flavor-agent/actions/runs/32878176704)
- Event: `push`
- Created: `2026-08-25T17:29:23Z`
- Head SHA: `4831b4e000b1f004e00baccdfdfaacffc4bfa861`

| Job | Job ID | Runner | Executed steps | Conclusion |
| --- | ---: | ---: | ---: | --- |
| Build, lint, and test | `97901097436` | `0` | `0` | failure |
| Playground smoke suite | `97901097528` | `0` | `0` | failure |
| WordPress 7.1 Site Editor suite | `97901097608` | `0` | `0` | failure |

### WordPress.com artifact

- Run: [32878176591](https://github.com/henryperkins/flavor-agent/actions/runs/32878176591)
- Event: `push`
- Created: `2026-08-25T17:29:23Z`
- Head SHA: `4831b4e000b1f004e00baccdfdfaacffc4bfa861`

| Job | Job ID | Runner | Executed steps | Conclusion |
| --- | ---: | ---: | ---: | --- |
| Build WordPress.com plugin artifact | `97901096250` | `0` | `0` | failure |

### Scheduled corpus updater control

- Run: [32935515070](https://github.com/henryperkins/flavor-agent/actions/runs/32935515070)
- Event: `schedule`
- Created: `2026-08-26T05:48:47Z`
- Head SHA: `4831b4e000b1f004e00baccdfdfaacffc4bfa861`

| Job | Job ID | Runner | Executed steps | Conclusion |
| --- | ---: | ---: | ---: | --- |
| Update wp-dev-docs corpus | `98075868442` | `0` | `0` | failure |

The independent scheduled workflow reproduces the same zero-runner,
zero-step, billing-lock signature. This rules out a failure specific to either
release workflow's commands.

## Recovery and release disposition

Preferred recovery:

1. The GitHub account owner resolves the Actions billing/account lock.
2. Re-run `Verify` and `Build WordPress.com Plugin Artifact` on the intended
   release commit.
3. Require every repository-controlled step to execute and pass; a newly queued
   zero-step failure does not improve the evidence.

Conditional fallback if the account cannot be unlocked before the release:

- Treat only runner scheduling as waived; do not waive any build, lint, test,
  browser, Plugin Check, packaging, inventory, or checksum gate.
- Run the checked-in commands locally from one clean immutable candidate and
  retain their complete stdout/stderr logs plus `output/verify/summary.json`.
- Record environment versions, command lines, candidate SHA, artifact SHA-256,
  archive inventory, and Plugin Check dispositions.
- Rebuild and reverify from the exact tag or prove the attached archive is
  byte-identical to the verified candidate artifact.
- Obtain an explicit maintainer decision accepting this infrastructure-only
  exception before creating or publishing `v0.1.0`.

## Maintainer waiver decision

Decision recorded: `2026-08-26T08:02:32Z`.

Status: **approved for final `v0.1.0`, infrastructure scope only**.

The maintainer confirmed during the release session that GitHub Actions will
not execute and that restoration of the account is not expected in the
foreseeable future. Waiting for a hosted rerun is therefore not a viable
`v0.1.0` release path. This statement approves the conditional fallback above.

The waiver covers only the absence of GitHub-hosted runner execution. It does
not reclassify any zero-step GitHub check as passing, and it does not waive:

- strict build, lint, unit, PHP, docs, Plugin Check, or browser verification;
- WordPress 7.1 Playground and Docker Site Editor coverage;
- live public-corpus, connector-backed Anthropic, or minimum visual evidence;
- clean-commit artifact construction, archive inventory, dependency/license
  review, or checksum recording;
- verification against the exact final tag; or
- attachment of the verified zip and complete local log bundle to the release.

The compensating pre-tag controls are recorded in
[`2026-08-26-v0.1.0-candidate-artifact.md`](2026-08-26-v0.1.0-candidate-artifact.md):
clean commit `e38fb57` passed all nine strict local steps with zero skips, its
209-file zip payload passed Plugin Check with zero errors and zero warnings,
and the complete log bundle and artifact have recorded SHA-256 identities.

Before publication, the final tag must still be created at the intended
immutable SHA, checked out independently, strictly reverified, and rebuilt (or
the attached zip must be proven byte-identical to the verified build). The zip,
complete local logs, and this waiver record must then be attached or linked in
the final release. This approval does not itself create a tag or publish a
release.
