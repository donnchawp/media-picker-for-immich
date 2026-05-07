# Cached Assets Picker UX — Design

Resolution of GitHub issue [#6](https://github.com/donnchawp/media-picker-for-immich/issues/6) ("Improve assets cached interface"). Two of the three sub-requests in the original issue are in scope; the third is recorded in `.out-of-scope/where-used-tracker.md`.

## Scope

In scope:

- **Resizable splitter** between the live Immich grid and the "Previously added" cached grid in the media picker.
- **Cached pane visual affordance** — clearer header, empty-state copy, and per-tile mode badges.

Out of scope:

- A "where is this asset used?" view of any kind. See `.out-of-scope/where-used-tracker.md` for the rationale.

## Current state

The media picker (`assets/js/media-picker-for-immich.js`) renders a single-column flex stack inside `.immich-browser`:

1. `.immich-toolbar` — search input, people select, action buttons.
2. `.immich-grid` — live Immich assets, infinite-scroll.
3. `.immich-used-divider` — a thin label "Previously added", hidden via inline `display:none` when there are no used assets.
4. `.immich-used-grid` — attachments with `_immich_asset_id` post meta, infinite-scroll.
5. `.immich-status` — spinner + status text.

`.immich-grid` and `.immich-used-grid` each have their own scroll container; their relative size is currently fixed by CSS (`flex: 1` on the live grid; `.immich-used-thumb` height 150 px on tiles in the cached grid).

The reporter's complaint is that on first load they see a clipped slice of Immich and an unclear strip below — they want to control the proportions and to understand what each strip is.

## Section A — Resizable splitter

### Behavior

- A drag handle sits between `.immich-grid` and the cached pane (the cached pane meaning the new sticky header from Section B together with `.immich-used-grid`).
- Mouse, touch, and keyboard all adjust the split.
- Position is expressed as the live grid's percentage of available vertical space, clamped to **10–90 %**.
- Default split: **70 / 30** (live / cached) on first use.
- Position persists per-user via user meta key `_immich_picker_split_pct` (integer 10–90). Server-side stored, not localStorage, so the preference follows the user across browsers.
- Save is debounced and fires on drag-end (not on every drag tick) via an authenticated AJAX call.
- Keyboard: handle is focusable (`role="separator"`, `aria-orientation="horizontal"`, `tabindex="0"`). Arrow keys move by 5 %. Home / End snap to 10 / 90.

### Implementation notes

- Replace the current flex layout with a CSS grid or explicit `flex-basis` on the two grids so the JS can adjust a single value.
- Drag uses pointer events (`pointerdown` / `pointermove` / `pointerup`) — works for mouse and touch with one code path, no jQuery UI.
- Persistence endpoint: new `wp_ajax_immich_save_picker_split` action, nonce-protected via the existing picker nonce, calls `update_user_meta( get_current_user_id(), '_immich_picker_split_pct', $clamped )`.
- Initial value bootstrapped via the existing `window.ImmichMediaPicker` config object (PHP reads user meta when localizing the script).
- Drag handle visual: 6 px tall band with hover affordance; cursor `ns-resize`. Matches WP admin grey palette.

### Acceptance criteria

- [ ] A drag handle is visible between the live grid and the cached pane.
- [ ] Dragging the handle resizes both grids proportionally; clamped to 10 % / 90 %.
- [ ] Position survives a page reload for the same user.
- [ ] Position is independent across users.
- [ ] Keyboard adjustment with arrows / Home / End works as specified.
- [ ] Touch drag works on a tablet-class viewport.
- [ ] Default is 70 / 30 for users with no saved preference.
- [ ] Saving the position uses a nonce-protected AJAX endpoint scoped to the current user.

## Section B — Cached pane visual affordance

### Sticky header

Replace `.immich-used-divider` with a sticky pill spanning the cached pane's width, visible whether or not the user has scrolled inside the cached grid.

- Text: **"Previously added (N)"** where N is the count of cached assets currently loaded.
- Trailing info icon (ⓘ); on hover/focus shows a short tooltip: "Assets you have previously selected or copied from Immich. Reuse them without round-tripping to the server."
- Sticky to the top of the cached pane via `position: sticky; top: 0;`.
- Always rendered (no `display:none` toggle). When N=0 the header still appears, with the empty state below.

### Empty state

When the cached query returns zero assets, render placeholder copy inside `.immich-used-grid`:

> **No assets yet.** Assets you Select or Copy from Immich will appear here for quick reuse.

The copy must use `__()` for translation. The empty state disappears as soon as the first asset is added in the same session (the existing `loadUsedAssets()` re-render path covers this).

### Per-tile mode badge

Each `.immich-used-thumb` gets a small corner badge indicating mode:

- **"S"** for Selected (proxy-only — no `_wp_attached_file` for the attachment, or marked at add-time via post meta `_immich_add_mode = 'select'`).
- **"C"** for Copied (the asset was downloaded into the Media Library — `_immich_add_mode = 'copy'`).

The badge is also a tooltip target — focusable element with `aria-label` "Selected (proxied)" / "Copied (in Media Library)".

This badge component must be reusable. Issue #9 ("Include Copied Assets in Previously Added") will introduce copied assets into the cached grid for the first time and uses the same badge, so the badge is implemented as a single helper that both surfaces consume.

The discriminator between Selected and Copied:

- The plugin's existing Copy path already creates a real WP attachment with `_wp_attached_file` set. Selected proxied assets that don't have a backing file will return false from `wp_get_original_image_path()`.
- For robustness, set `_immich_add_mode` post meta on the attachment at add-time (`'select'` or `'copy'`). Existing attachments without this meta are migrated in a one-shot upgrade routine: presence of `_wp_attached_file` → `'copy'`, absence → `'select'`.

### Acceptance criteria

- [ ] Sticky header shows "Previously added (N)" with the live count and an info-tooltip icon.
- [ ] Header remains visible when scrolling within the cached grid.
- [ ] Header is rendered even when N = 0 (replacing the previous hide-on-empty behavior).
- [ ] Empty state copy renders inside the cached grid when no assets exist.
- [ ] Each cached tile shows an "S" or "C" badge with a screen-reader-friendly label.
- [ ] Badge component is implemented as a single helper reused by issue #9.
- [ ] All visible strings are wrapped in `__()` / `_x()` for translation.
- [ ] One-shot upgrade routine populates `_immich_add_mode` for pre-existing attachments based on `_wp_attached_file` presence.

## Out of scope (explicit)

- Any "where is this asset used?" view, page, or column. See `.out-of-scope/where-used-tracker.md`.
- Per-tile previews (covered by issue #5).
- Showing copied assets in the cached grid (covered by issue #9 — but #9 must coordinate with the badge component defined here).
- Album browsing (covered by issue #7).
- Configurable import resolution (covered by issue #2).

## Cross-issue coordination

- **#9** ships the cached-grid query change to include copied assets. **This issue** ships the badge that distinguishes them. If #9 ships first, the badge falls back to "C" for everything in the cached grid (acceptable). If this issue ships first, the badge code is in place and #9 just makes copied assets appear.
- The `_immich_add_mode` meta + upgrade routine is a prerequisite for the badge. Whichever issue lands first must include it.
