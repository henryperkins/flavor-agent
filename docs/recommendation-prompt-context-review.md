# Recommendation Prompt Context Review

This review traces the recommendation surfaces that assemble context for LLM calls, then calls out gaps, weak routes, and underused levers. It focuses on prompt inputs and model-routing decisions, not response validation or apply execution.

## Current context and prompt routes

| Surface | Context source | Prompt assembly | LLM route | Notable strengths |
| --- | --- | --- | --- | --- |
| Block inspector | Client-selected block snapshot is normalized, enriched with docs guidance, signed, and sent through `Prompt::build_user()`. | `Prompt::build_system()` and `Prompt::build_user()` receive the execution contract, docs guidance, selected block, editor context, structural summaries, design semantics, theme tokens, and block-operation context. | `ChatClient::chat()` delegates to the configured text-generation provider. | Strongest live-editor signal: selected block controls, parent/sibling structure, design semantics, allowed structural actions, resolved-context signatures, and WordPress docs grounding. |
| Content writing | Server/client post context plus prompt are assembled by `WritingPrompt::build_user()`. | Voice-specific writing prompt with bounded existing-draft content. | Text-generation provider through the shared recommendation execution wrapper. | Clear voice contract and draft/edit/critique modes; less dependent on WordPress structural signals. |
| Pattern inserter | Retrieval backend first narrows candidates, then `ranking_system_prompt()` ranks candidate patterns against insertion context, visible scope, design summary, Pattern Overrides metadata, and docs guidance. | Pattern-specific ranking prompt asks for `{name, score, reason}` only; source metadata is reattached after ranking. | `ResponsesClient::rank()`. | Good two-stage architecture: semantic retrieval handles breadth, LLM handles contextual ranking within the allowed candidate set. |
| Navigation | `NavigationContextCollector::for_navigation()` parses saved/live navigation markup, location details, overlay template parts, target inventory, structure summary, editor context, and theme tokens. | `NavigationPrompt::build_user()` turns identity, menu structure, overlay context, tokens, and docs guidance into a priority-budgeted prompt. | `ResponsesClient::rank()`. | Good handling of WordPress 7.0 navigation-overlay template parts and explicit target inventory for safe structural advice. |
| Global Styles / Style Book | `StyleAbilities::build_shared_style_context()` rebuilds theme tokens server-side, normalizes configs, supported paths, variations, target block manifest, template structure, visibility, and design semantics. | `StylePrompt::build_user()` includes scope, current/merged configs, target data, supported paths, tokens, variations, docs guidance, and optional semantic context. | `ResponsesClient::rank()`. | Strong safety contract: supported style paths and server-collected tokens are treated as hard constraints. |
| Template | `TemplateContextCollector::for_template()` resolves the template, assigned/empty part slots, available parts, top-level tree, Pattern Overrides, viewport visibility, insertion anchors, candidate patterns, and tokens. | `TemplatePrompt::build_user()` presents template identity, slots, structure, patterns, tokens, overrides, visibility, and docs guidance. | `ResponsesClient::rank()`. | Good separation between template-part assignment operations and top-level pattern insertion. |
| Template part | Server-collected single-part context supplies top-level blocks, tree, operation targets/anchors, candidate patterns, Pattern Overrides, visibility, and tokens. | `TemplatePartPrompt::build_user()` shares the structural operation grammar with post-blocks. | `ResponsesClient::rank()`. | Best surface for focused structural changes inside one reusable part; operation grammar keeps apply paths bounded. |
| Post blocks | `ServerCollector::for_post_blocks()` ignores client-supplied trees and collects the live document tree, operation targets/anchors, candidate patterns, and tokens. | `PostBlocksPrompt::build_user()` mirrors template-part structural prompting for a post/page document. | `ResponsesClient::rank()`. | Trust boundary is strong because document structure is server-collected, not client-provided. |

## Gaps and weak routes

1. **Docs grounding is best-effort and single-query, so broad prompts can miss current guidance.** The shared grounding helper builds one query, accepts empty/cache-miss results, and never blocks the recommendation flow. That is the right product behavior, but it leaves broad requests with no second retrieval pass when the first query is too generic or when signature mode misses the cache. The result is especially weak for template, style, and navigation prompts where current WordPress behavior is a major part of the system instructions.

2. **Context strength varies sharply between block and non-block surfaces.** The block inspector gets rich live editor context, parent/sibling visual hints, allowed pattern context, and design semantics. Template, template-part, post-blocks, and navigation rely more heavily on server parse summaries and usually do not carry the same live selection intent, recent user interaction, inserter search text, or viewport/editor mode that the block surface receives.

3. **Pattern recommendations rank only candidates returned by retrieval.** The LLM is explicitly told the visible scope is already filtered and to rank within that allowed subset. That protects capability boundaries, but it means the model cannot recover if semantic retrieval under-selects because the query underweights insertion role, template area, sibling intent, or user instruction. The second stage explains and orders; it does not broaden.

4. **Learning-loop telemetry is not yet a prompt lever.** Recommendation outcomes are persisted with recommendation-set IDs, ranking data, pattern traits, and learning attribution, but prompt builders do not feed aggregate acceptance, dismissal, stale-context, or undo signals back into ranking. That makes the system mostly stateless across sessions even where outcome data could safely tune future suggestions.

5. **User/project guidelines enter as a global system-instruction prepend, but surface-specific prompts rarely expose why a guideline applies.** `RecommendationAbilityExecution` can prepend formatted guideline context through the `flavor_agent_recommendation_system_instruction` filter. The route is valuable, but the surface prompts do not pair guidelines with structured context such as affected block names, categories, or examples, so models may treat them as generic policy rather than a local ranking signal.

6. **Prompt budgets protect token windows but can hide the reason a model made a weak choice.** All major prompt builders use `PromptBudget` with priority sections, yet diagnostics exposed to users mostly cover docs grounding and signatures, not which context sections were omitted or truncated. When a recommendation ignores a candidate pattern, token, or structure detail, it is hard to tell whether the LLM missed it or never saw it.

7. **Style recommendations have strong capability data but limited content intent.** The style route knows current/merged style config, supported paths, variations, tokens, visibility, and sometimes design semantics. It has much less site-content signal: representative page roles, brand voice, recent editor focus, and before/after user goals are mostly reduced to the optional prompt and sparse template structure.

8. **Navigation recommendations prohibit adding missing items.** The safety rule avoids inventing pages and keeps operations applyable against the existing menu. It also means common user intents such as “make this menu more useful” can only reorganize current items unless a separate route first proposes content/page creation or lets the user approve candidate new menu items.

9. **Content writing is disconnected from design and structural recommendation context.** `WritingPrompt` has a strong voice contract and draft budget, but it does not appear to receive the same theme tokens, block structure, pattern context, or WordPress docs grounding used by structural surfaces. That can make generated copy less aware of actual block affordances, page layout, or design vocabulary.

## Underutilized levers

1. **Expose grounding quality as a ranking signal, not just metadata.** The public docs-grounding summary could be included in the user prompt as a confidence cue: grounded, cache-only, cache miss, roadmap-only, or unavailable. Prompts could ask for lower confidence/advisory suggestions when guidance is absent for current-feature requests.

2. **Add retrieval fallback queries for broad or low-signal requests.** When a docs query is empty/generic or pattern retrieval produces few high-confidence candidates, run one or two bounded fallback queries based on canonical surface facts: template type + operation vocabulary, block name + supported controls, navigation location + overlay state, or insertion root + template area.

3. **Feed outcome aggregates back into ranking.** Safe aggregates could include “users often insert/dismiss this pattern in header areas,” “style suggestions involving this path frequently fail contrast,” or “template-part replacements in this area are often undone.” Keep them anonymous, bounded, and separate from raw activity logs.

4. **Promote prompt-budget diagnostics.** Store or expose section inclusion/truncation metadata beside recommendation request diagnostics. This would turn weak recommendations into debuggable prompt assembly issues instead of opaque model behavior.

5. **Share a common design-intent envelope across surfaces.** Block design semantics, template visibility, Pattern Overrides, theme token diagnostics, viewport/editor mode, and user intent could be normalized into a compact cross-surface envelope. Pattern, template-part, post-blocks, navigation, and style prompts would then use the same vocabulary for density, emphasis, role, viewport relevance, and constraints.

6. **Let the pattern reranker request a retrieval miss note.** Without broadening the candidate set, the model could return a lightweight “missing intent” reason when none of the candidates fit the user prompt. The server would still return allowed candidates only, but the UI/admin diagnostics could tell users to sync patterns, adjust scope, or add a missing pattern category.

7. **Bridge content and structure.** Content recommendations should optionally receive page/block context and target block affordances when launched from editor surfaces. Structural recommendations should optionally receive content brief summaries so pattern and template suggestions reflect the actual message, not just block shapes.

8. **Make guidelines structured per surface.** Instead of only prepending guideline prose, pass a small `guidelineContext` object into surface prompts: applicable categories, matching block names, severity, and examples. This gives ranking and explanations a clear reason to prefer or avoid certain suggestions.

## Suggested priority order

1. Add prompt-budget diagnostics and docs-grounding quality cues to request diagnostics and prompts.
2. Add bounded fallback retrieval for docs and pattern candidates when primary routes are low-signal.
3. Normalize a cross-surface design-intent envelope and wire it into pattern, template-part, post-blocks, navigation, and style prompts.
4. Feed safe aggregate outcome signals into ranking contexts.
5. Bridge content recommendations with block/page structure when launched from editor contexts.
