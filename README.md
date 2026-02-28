# Immich Media Picker

A WordPress plugin that adds an **Immich** tab to the media picker modal, letting you search, browse, and import photos from your [Immich](https://immich.app/) server directly into the WordPress media library.

## Features

- **Smart search** — Find photos using Immich's AI-powered search
- **People filter** — Browse by recognized people from your Immich library
- **One-click import** — Selected photos are downloaded and imported as standard WordPress media library attachments
- **Multi-select** — Import multiple photos at once
- **Secure API proxy** — All Immich API calls happen server-side; the API key is never exposed to the browser
- **Per-user API keys** — Each WordPress user can configure their own Immich API key, or an admin can set a site-wide key

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

If no site-wide key is configured, each user can add their own Immich API key on their **Profile** page. Any Immich user can generate an API key from the Immich web UI under **Account Settings > API Keys**.

## Usage

1. Open the media picker (e.g. click **Add Media** on a post)
2. Click the **Immich** tab
3. Search for photos or select a person from the dropdown
4. Click thumbnails to select them
5. Click **Import Selected**
6. The photos are imported into your WordPress media library and selected in the modal

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

## Plugin structure

```
immich-media-picker/
├── immich-media-picker.php        # Plugin class: settings, AJAX endpoints, API client
├── assets/js/immich-media-picker.js  # Media modal tab (wp.media.view extension)
├── .wp-env.json                   # wp-env config
├── Makefile                       # Development commands
└── readme.txt                     # WordPress plugin readme
```

## License

GPL-2.0-or-later
