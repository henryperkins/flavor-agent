# Flavor Agent — Submission readiness, 2026-05-02

**Target:** approval and listing in the WordPress.org plugin directory by 2026-05-31
**Supporting tracker:** [`docs/reference/release-submission-and-review.md`](./release-submission-and-review.md)

Flavor Agent is a WordPress plugin that adds AI-assisted recommendations to the WordPress editor and admin interface. The May 31 objective is public release through the official WordPress.org plugin directory.

**The plugin reads green at the surface and red one layer below.** Our build and test pipeline passes. The platform's automated review tool reports one error and seven warnings — but the pipeline wasn't gating on those findings, so they were invisible behind the green light. Catching that gap by populating the KPI table this week, rather than learning it from a rejection email, is exactly what the measurement framework is for.

**The largest single task between today and submission-ready is third-party disclosure.** The plugin causes data to reach six distinct external services; the public listing names zero of them today. That is the highest-probability rejection vector for an AI plugin in 2026 and the biggest rewrite ahead. Paired with it: a pass/fail check that the plugin makes no outbound calls before the user has finished setup. Both land in one focused week.

**May 31 is achievable with disciplined scope.** One of eight editing surfaces is fully ready (Template); the others have stop lines defined but release actions still open. Four weeks leaves room for one round of reviewer feedback, not two. Risk concentrates in the disclosure rewrite and the gating-gap fix; everything else is mechanical follow-through.

| KPI | Today | What good looks like |
| --- | --- | --- |
| Automated review pass | 1 error, 7 warnings, gating gap | Zero errors; every warning dispositioned; pipeline gates on findings |
| Third-party disclosure | 0 of 6 services named in listing | All 6 named, with what data is sent and when |
| No-data-before-setup | 4 background tasks unaudited | Zero outbound calls before user setup; pass/fail |
| Scope discipline | 1 of 8 surfaces ready, 71 actions open | Stop lines held, trend converges before May 31 |

Detailed gates, in-flight signals, and per-event tracking live in the supporting tracker.
