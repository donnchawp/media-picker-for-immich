# Media Picker for Immich

A WordPress plugin that adds an **Immich** tab to the WordPress media picker and the Media Library, letting you use photos and videos from your [Immich](https://immich.app/) server in WordPress — either proxied directly or imported into the media library.

## Features

- **Use or Copy** — "Use" proxies media directly from Immich (no files copied to WordPress); "Copy" downloads the original into the media library
- **Photo and video support** — images are proxied with full-resolution originals; videos stream with seeking support
- **Smart search** — find media using Immich's AI-powered search
- **People filter** — browse by recognized people from your Immich library
- **Multi-select** — use or import multiple items at once with infinite scroll
- **Lightbox** — full-resolution lightbox on Immich images in posts (click to enlarge, Escape to close)
- **Media Library integration** — browse and import Immich assets directly from the Media Library grid view (Media > Library)
- **Previously added** — the Immich tab shows assets you've already used, ready to re-select
- **Album Gallery block** — embed a live Immich album as a gallery, with per-block columns, image size, sort, limit, captions, and lightbox options
- **Secure API proxy** — all Immich API calls happen server-side; the API key is never exposed to the browser
- **Per-user API keys** — each user can configure their own Immich API key; the proxy uses the post author's key so media displays correctly regardless of who is viewing
- **Local proxy cache** — proxied media is cached on disk after the first request, with optional automatic cleanup and a Cache Files admin page to review and clear it
- **Copy Resolution setting** — choose the resolution used when copying originals into the media library
- **Test Connection** — verify your server URL and API key from the Settings page before saving

## Requirements

- WordPress 6.5+
- PHP 8.0+
- An [Immich](https://immich.app/) server accessible from your WordPress server

## Installation

1. Download or clone this repository into `wp-content/plugins/media-picker-for-immich/`
2. Activate the plugin in **Plugins > Installed Plugins**
3. Go to **Settings > Immich** and enter your Immich server URL (e.g. `http://192.168.1.100:2283`)

## Configuration

### Site-wide API key

An admin can set a single API key in **Settings > Immich** that all users share. When set, per-user keys are hidden.

### Per-user API keys

If no site-wide key is configured, each user can add their own Immich API key on their **Profile** page. Generate an API key from the Immich web UI under **Account Settings > API Keys**.

When per-user keys are in use, the proxy serves media using the key of the user who added the asset — so posts by different authors each pull from the correct Immich account.

### Required API key permissions

When you create the Immich API key, grant only these permissions — nothing else is needed:

| Permission | What it enables |
| ---------- | --------------- |
| `asset.read` | List asset metadata and run library searches (browse, search by query, search by person). |
| `asset.view` | Stream thumbnails and video playback through the proxy. |
| `asset.download` | Fetch full-resolution originals for the proxy and the Copy/import path. |
| `person.read` | Populate the people filter dropdown and people thumbnails. |
| `album.read` | List albums in the picker and fetch their assets for the Album Gallery block. |

The Settings page and per-user profile field display the same list inline, so you can copy the slugs straight from there into the Immich API key UI.

### Proxy cache

When you use "Use Selected", proxied media is cached locally on the WordPress server the first time it's requested. Subsequent requests are served directly from disk without contacting your Immich server. Cached files are stored in `wp-content/cache/immich/` organised by type (`thumbnail/`, `original/`, `video/`).

Concurrent requests for the same asset are blocked until the first request completes, so each file is only fetched once from Immich.

To enable automatic cleanup, check **Cache Cleanup** in **Settings > Immich** and set a **Cache Lifetime** in hours (default 24). The plugin uses WP-Cron to delete cached files older than the configured lifetime once per hour. Disable the checkbox to keep cached files indefinitely.

## Usage

### Adding media to a post

1. Open the media picker (e.g. click **Add Media** on a post)
2. Click the **Immich** tab
3. Browse recent photos, search by keyword, or filter by person
4. Click thumbnails to select them
5. Click **Use Selected** to proxy directly from Immich, or **Copy Selected** to download into the media library
6. The media is added to your post

### Browsing from the Media Library

1. Go to **Media > Library** (grid mode)
2. Click the **Immich** tab at the top of the page
3. Browse, search, or filter by person — the same interface as the media picker
4. Click **Use Selected** or **Copy Selected** to add assets to your WordPress media library
5. Switch back to the **Media Library** tab to see the newly added items

**Use Selected** creates a virtual attachment that serves images and videos through your WordPress server as a proxy — no files are stored locally. This is ideal for keeping your WordPress uploads directory lean.

**Copy Selected** downloads the full original file into `wp-content/uploads/` as a standard WordPress attachment. Use this when you want a local copy independent of your Immich server.

### Previously added assets

The bottom of the Immich tab shows assets you've previously added. Click them to re-select without searching again.

### Lightbox

Posts containing Immich images automatically get a lightbox. Clicking an image opens the full-resolution original in an overlay. Press Escape or click anywhere to close.

## Album Gallery block

This plugin ships an "Immich Album Gallery" Gutenberg block (`immich/album-gallery`) that embeds a live Immich album as a gallery. Insert the block, click "Pick album", and choose an album from your Immich server.

Per-block options (sidebar):

- **Columns** (1–8) — grid density.
- **Image size** — `thumbnail`, `preview` (default), or `fullsize`.
- **Sort order** — Album order (default), oldest, newest, or random.
- **Limit** — cap to N images; 0 means all up to the global cap.
- **Lightbox** — click an image to open the fullsize variant in a centred overlay.
- **Show captions** — render the asset filename below each image.
- **Show "View on Immich" link** (default off) — when more assets exist in Immich than are rendered, append a link to the album in the Immich web UI. Defaults off because Immich is often only reachable from your LAN/VPN; turn this on only if your Immich URL is reachable from your visitors' browsers.

The album is fetched live on each page render and cached for 5 minutes. Logged-in editors visiting the published post see a small "Refresh this album from Immich" link below the gallery (the link carries a per-album nonce so the cache-flush endpoint is CSRF-safe). Cached album lists also appear, and can be cleared, on Media → Cache Files.

Filters:

- `immich_album_cache_ttl` (int seconds, default `300`)
- `immich_album_max_assets` (int, default `100`)

## Development

Requires [Node.js](https://nodejs.org/) for the wp-env development environment.

```bash
# Start the development environment
make start

# Open http://localhost:8888/wp-admin (admin / password)

# Other commands
make stop        # Stop wp-env
make restart     # Restart wp-env
make status      # Show plugin status
make logs        # Show WordPress debug log
make shell       # Shell into the container
make cli CMD="option list"  # Run WP-CLI commands
make check       # Build the release zip and run Plugin Check against it
```

Run `make check` before opening a PR or merging to `main`. It builds `dist/<plugin>-<version>.zip`, extracts it under a temporary slug inside wp-env, and runs the [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin via WP-CLI. Pass extra flags with `ARGS=`, e.g. `make check ARGS="--categories=security"`.

### Releasing

Releases are cut in two steps, with a PR review in between:

```bash
# 1. Prepare the release PR (run from a clean, up-to-date main)
make pre-build VERSION=0.3.0
```

`pre-build` creates a `release/<version>` branch, bumps the version in three
places (the `Version:` header, the `IMMICH_MEDIA_PICKER_VERSION` constant, and
the readme.txt `Stable tag`), drafts a changelog entry from the commits since
the last release tag and opens `readme.txt` in `$EDITOR` so you can tidy it,
runs `make check`, then commits, pushes, and opens a PR.

```bash
# 2. After the PR is reviewed and merged, from the updated main:
make publish VERSION=0.3.0
```

`publish` rebuilds the zip, creates an annotated git tag and a GitHub release
(using the matching changelog section as the notes), then — after a `svn status`
review and confirmation — publishes the build to the WordPress.org SVN repo
(`trunk/` plus a new `tags/<version>/`, and `screenshots/` into `assets/`).
It needs the `gh` and `svn` CLIs authenticated.

## License

GPL-2.0-or-later
