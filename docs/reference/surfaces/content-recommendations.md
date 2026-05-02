# Content Recommendations Release Surface

Source: [`../release-surface-scope-review.md`](../release-surface-scope-review.md#content-recommendations)

## Release Role

Content recommendations belong in the post/page document panel when they stay
editorial. Drafting, editing, and critique improve writing without requiring
Flavor Agent to own document mutation.

Release verdict: keep as editorial-only.

Release quality: release-ready if positioned honestly as guidance or generated
text rather than an automatic patch.

## Stop Line

Ship:

- Draft, edit, and critique outputs.
- Advisory notes and issue cards.
- Read-only request history.
- Setup/capability notices.

Do not ship:

- Automatic replacement of post content.
- Partial document edits.
- Rich text mutation.
- Undo semantics without a full editor-aware apply contract.

## Next Steps

- [ ] Tighten copy so users understand results are editorial guidance or
  generated text, not automatic patches.
- [ ] Add or verify a clear manual handoff path for draft/edit output.
- [ ] Keep unsupported post types hidden or clearly unavailable.
- [ ] Confirm provider unavailability points to Connectors setup instead of
  plugin-owned provider routing.
- [ ] Avoid apply-like verbs unless a full editor-aware apply contract exists.

## Verification Gate

- [ ] Re-run content panel unit coverage after copy or mode changes.
- [ ] Re-run content panel browser smoke coverage after copy or mode changes.

