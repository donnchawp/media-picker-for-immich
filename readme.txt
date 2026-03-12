=== Immich Media Picker ===
Contributors: donncha
Tags: immich, media, photos, self-hosted, gallery
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.1.0
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

== Installation ==

1. Upload the `immich-media-picker` folder to `wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **Settings > Immich** and enter your Immich server URL and API key.
4. Generate an API key from the Immich web UI under **Account Settings > API Keys**.

== Frequently Asked Questions ==

= What is Immich? =

[Immich](https://immich.app/) is a self-hosted photo and video management solution, similar to Google Photos. It runs on your own server and provides AI-powered search, facial recognition, and more.

= What is the difference between "Use" and "Copy"? =

**Use Selected** creates a virtual attachment that serves images and videos through your WordPress server as a proxy — no files are stored locally. This keeps your WordPress uploads directory lean.

**Copy Selected** downloads the full original file into `wp-content/uploads/` as a standard WordPress attachment. Use this when you want a local copy independent of your Immich server.

= Does my Immich server need to be publicly accessible? =

Your Immich server must be accessible from your WordPress server, but it does not need to be publicly accessible on the internet. The plugin proxies all media through WordPress, so visitors never connect to Immich directly.

= Can different users have their own API keys? =

Yes. If no site-wide API key is set in **Settings > Immich**, each user can add their own key on their **Profile** page. The proxy serves media using the key of the user who added the asset.

= Does the lightbox work automatically? =

Yes. Posts containing proxied Immich images automatically get a lightbox. Clicking an image opens the full-resolution original in an overlay. Press Escape or click anywhere to close.

== Screenshots ==

1. The Immich tab in the WordPress media picker, showing recent photos with search and people filter.
2. The Immich settings page where you configure your server URL and API key.

== Changelog ==

= 0.1.0 =
* Initial release.
