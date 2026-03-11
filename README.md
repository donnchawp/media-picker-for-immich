# Immich Media Picker

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
- **Secure API proxy** — all Immich API calls happen server-side; the API key is never exposed to the browser
- **Per-user API keys** — each user can configure their own Immich API key; the proxy uses the post author's key so media displays correctly regardless of who is viewing

## Requirements

- WordPress 6.4+
- PHP 8.0+
- An [Immich](https://immich.app/) server accessible from your WordPress server

## Installation

1. Download or clone this repository into `wp-content/plugins/immich-media-picker/`
2. Activate the plugin in **Plugins > Installed Plugins**
3. Go to **Settings > Immich** and enter your Immich server URL (e.g. `http://192.168.1.100:2283`)

## Configuration

### Site-wide API key

An admin can set a single API key in **Settings > Immich** that all users share. When set, per-user keys are hidden.

### Per-user API keys

If no site-wide key is configured, each user can add their own Immich API key on their **Profile** page. Generate an API key from the Immich web UI under **Account Settings > API Keys**.

When per-user keys are in use, the proxy serves media using the key of the user who added the asset — so posts by different authors each pull from the correct Immich account.

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
```

## License

GPL-2.0-or-later
