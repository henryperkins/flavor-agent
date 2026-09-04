# WordPress Editor Context Audit

This document records which WordPress editor identity, block, permission, and save-lifecycle signals Flavor Agent currently uses. It reflects production source as of 2026-09-04 and should be refreshed when the editor context collector or an apply contract changes.

Use it when you need to answer:

- which editor facts are collected directly from WordPress data stores
- which permissions are enforced by native selectors or server capability checks
- whether an apply result represents an editor mutation or a persisted document save
- where a new preflight or verification requirement belongs

## Current Coverage

Flavor Agent queries most editor context, but some answers remain scoped to the operations it implements. The largest cross-surface gap is the native save lifecycle: a normal editor "Apply" means that Flavor Agent changed editor state and recorded the operation, not that WordPress saved the document.

| Question | Answer | Current behavior |
| --- | --- | --- |
| What document am I editing? | **Yes** | Reads the current post/entity type, ID, and edited attributes. `src/content/ContentRecommender.js:256-277`, `src/utils/editor-entity-contracts.js:48-90` |
| What blocks exist? | **Yes** | Recursively reads the current block tree. `src/context/block-inspector.js:366-390` |
| What block is selected? | **Yes** | Reads the selected client ID and selected block. `src/inspector/BlockRecommendationsPanel.js:1681-1691` |
| What blocks are allowed here? | **Partial** | Gets contextual allowed patterns and tests their block candidates with `canInsertBlockType()`. It does not enumerate every contextually allowed block type. The `list-allowed-blocks` ability returns the site-wide registered-block inventory. `src/patterns/pattern-settings.js:79-96`, `src/patterns/pattern-insertability.js:43-65`, `inc/Abilities/BlockAbilities.php:201-227` |
| What attributes does this block have? | **Yes** | Reads live values and the registered attribute schema. `src/context/block-inspector.js:205-217`, `src/context/block-inspector.js:306-335` |
| Can this block be edited? | **Partial, operation-scoped** | Reads the selected block's editing mode and ancestor `contentOnly` state, then applies those signals to the supported structural-operation validation. There is no single cross-surface editability preflight. `src/context/block-inspector.js:306-335`, `src/utils/block-structural-actions.js:340-379` |
| Can it be moved? | **Not as a direct Flavor Agent operation** | The block operation catalog exposes sibling insertion and replacement, not a direct move action, so production does not query a `canMoveBlock(s)` selector. `src/utils/block-operation-catalog.js:1-73` |
| Can it be removed? | **Yes for supported block structural operations** | Collection records `canRemoveBlock()` and subscribes to that selector. Replacement requires `canRemoveBlock()` before dispatch; insertion rollback, replacement verification, and undo require `canRemoveBlocks()`. Template and template-part flows separately enforce explicit removal and template locks. `src/context/collector.js:666-706`, `src/context/collector.js:736-759`, `src/utils/block-structural-actions.js:525-541`, `src/utils/block-structural-actions.js:700-782`, `src/utils/template-actions.js:182-227` |
| What block types are registered? | **Yes** | A helper ability reads WordPress's registered-block inventory; first-party editor JavaScript generally requests only relevant named types. `inc/Context/BlockTypeIntrospector.php:58-85`, `inc/Context/BlockTypeIntrospector.php:179-205`, `inc/Abilities/BlockAbilities.php:201-227` |
| What variations exist? | **Yes** | Reads registered variations for the relevant block types. `src/context/block-inspector.js:205-217` |
| What template constraints apply? | **Partial, substantial** | Reads root/container template locks and validates blocks against templates, but has no single complete constraint manifest shared by every surface. `src/utils/template-actions.js:117-155`, `src/utils/template-actions.js:780-813` |
| Is the document dirty? | **No** | Production apply code does not query `isEditedPostDirty()`. |
| Can it be saved? | **No** | Production apply code does not query the editor's saveability state. |
| Is saving locked? | **No** | Structural flows check operation and template locks, not WordPress post-saving locks. |
| Did the save actually succeed? | **Not for normal editor Apply** | Ordinary block Apply dispatches editor-store mutations and records success without calling or verifying `savePost()`. `src/store/index.js:2307-2319`, `src/store/index.js:2353-2395` |
| Can this user perform the underlying operation? | **Yes at server-write boundaries; operation-scoped in the editor** | Recommendation abilities and persisted writes check capabilities, including target-specific `edit_post`, and reauthorize before persistence. Local block replacement and rollback also use Core's removal selectors; editability remains distributed across each supported operation rather than one universal preflight. `inc/AI/Abilities/RecommendationAbility.php:51-70`, `inc/Apply/PostBlocksApplyExecutor.php:67-78`, `src/utils/block-structural-actions.js:700-782` |

## Persistence Boundary

The governed external-apply path for an existing post is an important exception to the normal editor Apply behavior. It performs guarded server persistence and verifies the written post by reading it back before reporting success. `inc/Apply/ExistingPostContentWriter.php:319-334`, `inc/Apply/ExistingPostContentWriter.php:457-490`

Flavor Agent therefore does not implement one mandatory preflight-and-verification object before every mutation. Native removal capability is collected and enforced for supported block structural operations; the remaining common gaps are a unified editability contract and native dirty/saveable/save-locked/save-succeeded state for ordinary editor applies.
