# Immich Media Picker — WordPress Plugin Design

## Overview

WordPress plugin that adds an "Immich" tab to the media picker modal, allowing users to search, browse, and import photos from an Immich server directly into the WordPress media library.

## Requirements

- WordPress 6.4+, PHP 8.0+
- Regular plugin (not mu-plugin)
- wp-env for development
- Immich server reachable from WordPress backend (default: `http://immich-server:2283`)

## File Structure

```
immich-media-picker/
├── immich-media-picker.php           # Plugin header + Immich_Media_Picker class
├── assets/js/immich-media-picker.js  # Media modal integration
├── .wp-env.json                      # wp-env dev environment
└── readme.txt
```

## Architecture

Single class (`Immich_Media_Picker`) bootstrapped on `plugins_loaded`. All PHP lives in the main plugin file. JS is a separate file enqueued when the media modal loads.

## API Key Resolution

Resolution order: site-wide key (if set) → user's personal key → error.

- **Admin settings** (Settings > Immich): Immich API URL + optional site-wide API key. When a site-wide key is set, it overrides all per-user keys.
- **User profile field**: "Immich API Key" stored as user meta (`immich_api_key`). Only displayed when no site-wide key is configured.
- Helper method `get_api_key()` resolves the key for the current user.

## Settings Page

Under Settings > Immich. Uses Settings API.

Fields:
- `immich_api_url` — text field, default `http://immich-server:2283`
- `immich_api_key` — password field, optional site-wide key

Stored as single option: `immich_settings` (associative array).
Capability: `manage_options`.

## AJAX Endpoints

All require `upload_files` capability + nonce verification via `check_ajax_referer()`.

| Action | HTTP | Proxied Immich endpoint | Returns |
|---|---|---|---|
| `immich_search` | POST | `POST /api/search/smart` or `POST /api/search/metadata` (when personIds present) | `[{id, thumbUrl, filename}]` |
| `immich_people` | GET | `GET /api/people` | `[{id, name, thumbUrl}]` |
| `immich_thumbnail` | GET | `GET /api/assets/{id}/thumbnail` | Binary image stream with correct Content-Type |
| `immich_import` | POST | `GET /api/assets/{id}/original` | `{attachmentId}` after importing into WP media library |

Thumbnail URLs in search/people results point to the local proxy: `admin-ajax.php?action=immich_thumbnail&id=ASSET_ID`.

## Import Flow

1. PHP fetches original file from Immich (`GET /api/assets/{id}/original`)
2. Saves to uploads directory via `wp_upload_bits()`
3. Creates attachment post via `wp_insert_attachment()`
4. Generates metadata via `wp_generate_attachment_metadata()` + `wp_update_attachment_metadata()`
5. Returns attachment ID to JS
6. JS creates `wp.media.model.Attachment` and selects it in the media frame

## JS: Media Modal Integration

Extends `wp.media.view` to add an Immich router/content tab:

- **Router** — "Immich" tab alongside "Upload Files" and "Media Library"
- **Content view** — search bar, people dropdown, thumbnail grid, select button
- **Search** — debounced input fires AJAX to `immich_search`; people dropdown populated on tab open via `immich_people`
- **Selection** — click toggles selected state; respects multi-select mode from media frame
- **Import** — "Select" button fires `immich_import` for each selected asset, then selects the resulting attachments in the frame

## Security

- All AJAX: `check_ajax_referer()` + `current_user_can('upload_files')`
- Thumbnail proxy: validate asset ID is UUID format
- Settings page: `manage_options` capability
- API key never exposed to browser
- Import: sanitize filename before `wp_upload_bits()`
- API key stored as option/user meta (not in code)

## wp-env Configuration

```json
{
  "core": null,
  "plugins": ["."],
  "env": {
    "development": {
      "port": 8888
    }
  }
}
```
