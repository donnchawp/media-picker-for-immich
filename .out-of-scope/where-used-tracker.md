# Where-Used Tracker for Cached Assets

This plugin will not include a "where is this asset used?" view that lists which posts or pages reference a given Immich-backed attachment.

## Why this is out of scope

WordPress does not index attachment-to-post relationships natively. Implementing this responsibly requires:

- Parsing every post's blocks on `save_post` to discover which attachments are referenced.
- Maintaining a per-attachment index (post meta or a custom table) that is kept consistent on post insert / update / delete / trash / untrash, on attachment delete, and on bulk operations.
- A one-shot rebuild routine for existing content, batched via WP-Cron to survive timeouts on large sites.
- A new admin page (most likely under Media → Immich Cache) with a sortable list table, filters, bulk actions, and pagination.
- Coverage for blocks beyond `core/image` and `core/video` — galleries, covers, columns containing media, and any third-party block that references attachments.

That body of work is wider than the rest of this plugin combined. If somebody wants a media-usage tracker for WordPress, it stands on its own much better as a dedicated plugin that works for any attachment, not just Immich-backed ones. The two concerns — picking media from Immich and tracking media usage across a site — are independent enough that combining them would harm both.

The plugin's focus stays on the Immich integration: pick assets, proxy or copy them, manage the cache.

## What we did instead

The picker UX issue ([#6](https://github.com/donnchawp/media-picker-for-immich/issues/6)) covers the two sub-requests that *do* belong here: a resizable split between live and cached panes, and clearer affordances on the cached pane. See `docs/superpowers/specs/2026-05-07-cached-assets-picker-ux-design.md`.

## Prior requests

- #6 — "Feature request: Improve assets cached interface" (the where-used portion only; the rest is being delivered).
