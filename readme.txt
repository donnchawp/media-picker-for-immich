=== Media Picker for Immich ===
Contributors: donncha
Tags: immich, media, photos, self-hosted, gallery
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use photos and videos from your Immich server in WordPress without copying files, or import them into the media library.

== Description ==

Adds an "Immich" tab to the WordPress media picker modal and the Media Library grid view. Search and browse your self-hosted [Immich](https://immich.app/) photo library, then import selected photos directly into WordPress or proxy them without copying files.

**Features:**

* **Use or Copy** — "Use" proxies media directly from Immich (no files copied); "Copy" downloads the original into the media library
* **Photo and video support** — images are proxied with full-resolution originals; videos stream with seeking support
* **Smart search** — find media using Immich's AI-powered search
* **People filter** — browse by recognized people from your Immich library
* **Multi-select** — use or import multiple items at once with infinite scroll
* **Lightbox** — full-resolution lightbox on Immich images in posts
* **Media Library integration** — browse and import Immich assets directly from the Media Library grid view
* **Previously added** — the Immich tab shows assets you've already used, ready to re-select
* **Secure API proxy** — all Immich API calls happen server-side; the API key is never exposed to the browser
* **Per-user API keys** — each user can configure their own Immich API key
* **Local proxy cache** — proxied media is cached on disk after the first request; optional automatic cleanup with configurable lifetime, managed from a Cache Files admin page
* **Copy Resolution setting** — choose the resolution used when copying originals into the media library
* **Test Connection** — verify your server URL and API key from the Settings page before saving

This plugin also ships an "Immich Album Gallery" block: insert it in any post, pick an Immich album, and the post renders a live gallery of that album using the core Gallery markup (so it inherits your theme's styling and works with the WordPress core lightbox).

== Installation ==

1. Upload the `media-picker-for-immich` folder to `wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **Settings > Immich** and enter your Immich server URL and API key.
4. Generate an API key from the Immich web UI under **Account Settings > API Keys**. The plugin needs only these permissions:
   * `asset.read` — list asset metadata and run library searches.
   * `asset.view` — stream thumbnails and video playback through the proxy.
   * `asset.download` — fetch full-resolution originals for the proxy and the Copy/import path.
   * `person.read` — populate the people filter dropdown and people thumbnails.
   * `album.read` — list albums in the picker and fetch their assets for the Album Gallery block.

== Frequently Asked Questions ==

= What is Immich? =

[Immich](https://immich.app/) is a self-hosted photo and video management solution, similar to Google Photos. It runs on your own server and provides AI-powered search, facial recognition, and more.

= What is the difference between "Use" and "Copy"? =

**Use Selected** creates a virtual attachment that serves images and videos through your WordPress server as a proxy — no files are stored locally. This keeps your WordPress uploads directory lean.

**Copy Selected** downloads the full original file into `wp-content/uploads/` as a standard WordPress attachment. Use this when you want a local copy independent of your Immich server.

= Does my Immich server need to be publicly accessible? =

Your Immich server must be accessible from your WordPress server, but it does not need to be publicly accessible on the internet. The plugin proxies all media through WordPress, so visitors never connect to Immich directly.

= Which Immich API key permissions does the plugin need? =

Grant the API key these five permissions — nothing else is required:

* `asset.read` — list asset metadata and run library searches (browse, search by query, search by person).
* `asset.view` — stream thumbnails and video playback through the proxy.
* `asset.download` — fetch full-resolution originals for the proxy and the Copy/import path.
* `person.read` — populate the people filter dropdown and people thumbnails.
* `album.read` — list albums in the picker and fetch their assets for the Album Gallery block.

The same list is shown inline on the Settings page and the per-user profile API key field for easy copy-paste into Immich.

= Can different users have their own API keys? =

Yes. If no site-wide API key is set in **Settings > Immich**, each user can add their own key on their **Profile** page. The proxy serves media using the key of the user who added the asset.

= How does the proxy cache work? =

When a proxied image or video is requested for the first time, the plugin fetches it from Immich and saves a copy in `wp-content/cache/immich/`. All subsequent requests are served from the local cache without contacting your Immich server. To enable automatic cleanup, check **Cache Cleanup** in **Settings > Immich** and set a lifetime in hours (default 24). When disabled, cached files are kept indefinitely.

= Does the lightbox work automatically? =

Yes. Posts containing proxied Immich images automatically get a lightbox. Clicking an image opens the full-resolution original in an overlay. Press Escape or click anywhere to close.

= How do I embed a whole Immich album in a post? =

Add the "Immich Album Gallery" block from the inserter, click "Pick album", and choose an album from your Immich server. The gallery renders live and is cached for 5 minutes; you can override per-block options like columns, image size, sort, and lightbox in the block sidebar.

For large albums where the global cap (default 100) trims the rendered set, the block has an optional "Show 'View on Immich' link" toggle that appends a link to the album in the Immich web UI. Leave it off unless your Immich URL is reachable from your visitors' browsers — many self-hosted setups put Immich behind a VPN or on a Docker-internal hostname that won't resolve outside the LAN.

= I use Nginx — do I need extra server config? =

Yes. The plugin caches proxied Immich binaries under `wp-content/uploads/immich-cache/`, and the proxy endpoint enforces post-status authorisation on every request. On Apache the plugin drops a deny-all `.htaccess` into that directory automatically so nothing else can serve the cached files. Nginx ignores `.htaccess`, so you need an equivalent rule in your site's server block:

`location ^~ /wp-content/uploads/immich-cache/ { deny all; return 404; }`

Without that, a visitor who captured a cached asset URL from rendered HTML could fetch the file directly even after the post is unpublished or made private.

== Screenshots ==

1. The Immich tab in the WordPress media picker, showing recent photos with search and people filter.
2. The Immich settings page where you configure your server URL and API key.

== External services ==

This plugin connects to a self-hosted [Immich](https://immich.app/) server that you configure in **Settings > Immich**. Immich is a self-hosted photo and video management solution — it runs on your own infrastructure and is not a third-party cloud service, but the connection is disclosed here for transparency.

= What data is sent and when =

* **Browsing and searching:** When a logged-in WordPress user opens the Immich media picker or searches for photos, the plugin sends API requests (search queries, page numbers, and person filter IDs) to your Immich server.
* **Importing or using media:** When a user selects an asset to import or use, the plugin fetches the original file or metadata from the Immich server using the asset's UUID.
* **Proxying media to visitors:** When a site visitor views a page containing Immich-proxied images or videos, WordPress fetches the media from your Immich server on their behalf. Visitor data is not sent to Immich — only the stored asset UUID and your API key are used server-side.

All communication uses the API key you configure in WordPress. The API key is never exposed to browsers.

= Immich project links =

* [Immich website](https://immich.app/)
* [Immich privacy policy](https://immich.app/privacy-policy)
* [Immich license (AGPL-3.0)](https://github.com/immich-app/immich/blob/main/LICENSE)

Since Immich is self-hosted, the terms of use and privacy practices are determined by whoever operates the Immich server you connect to.

== Changelog ==

= 0.2.0 =
* New: **Immich Album Gallery block** — embed a live Immich album as a gallery using the core Gallery markup, with per-block columns, image size, sort order, limit, captions, and lightbox options. Album data is cached for 5 minutes with a manual "Refresh from Immich" link for editors.
* New: **Local proxy cache** — proxied images and videos are saved to disk on first request and served locally afterwards, with optional automatic cleanup and a configurable lifetime.
* New: **Cache Files admin page** (Media → Cache Files) to review and clear cached thumbnails and album lists.
* New: **Copy Resolution setting** — choose the resolution used when copying/importing originals into the media library.
* New: **Test Connection button** on the Settings page to verify your server URL and API key before saving.
* New: Per-tile previews and video badges in the picker, a resizable picker split, and other picker UX refinements.
* Improved: Required Immich API key permissions are now documented inline on the Settings and Profile pages.
* Improved: Picker type filtering is pushed into the Immich query, and previously-added assets of a mismatched type are shown but disabled.
* Security & hardening: private Cache-Control on per-user proxy paths, per-user API key UI gated on the `upload_files` capability, and assorted Plugin Check fixes.
* Tested up to WordPress 7.0.

= 0.1.0 =
* Initial release.
