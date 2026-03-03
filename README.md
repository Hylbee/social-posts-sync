# Social Posts Sync

Sync Facebook Pages and Instagram Business accounts to WordPress via the Meta Graph API.

**Stable Tag:** 1.2.2
**Tested up to:** 6.7
**Requires at least:** 6.0
**Requires PHP:** 8.0
**License:** GPL-2.0-or-later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

---

## Features

- **OAuth 2.0 authentication** with Meta — App ID/Secret stored securely, access token encrypted (AES-256-CBC + HMAC-SHA256 encrypt-then-MAC)
- **Facebook** — fetch posts from public pages (no admin rights required)
- **Instagram** — fetch posts from your own Business accounts (direct) or from any public Business account (Business Discovery)
- **Custom Post Type** `social_post` with rich metadata (platform, likes, author, permalink, media, video, gallery)
- **Automatic media sideloading** — images are downloaded and added to the WordPress media library, with deduplication, MIME type whitelist validation, and parallel curl_multi downloads
- **Incremental sync** — only fetches new posts since the last run
- **WP-Cron scheduling** — hourly, every 6 h, every 12 h, or daily
- **Rate-limit back-off** — exponential back-off from 15 min to 4 h when the API throttles requests
- **Elementor dynamic tags** — 7 tags to display social post data inside Elementor templates
- **Admin UI** — four-tab settings page: API connection, sources, sync, advanced
- **Sync lock** — prevents concurrent syncs; manual unlock available

---

## Requirements

| Requirement | Minimum version |
|---|---|
| WordPress | 6.0 |
| PHP | 8.0 |
| Elementor *(optional)* | any recent version |
| Meta Developer App | with the permissions listed below |

### Required Meta App Permissions

`public_profile`, `pages_show_list`, `pages_read_engagement`, `pages_read_user_content`, `instagram_basic`, `instagram_content_publish`

> **Note:** `AUTH_KEY` must be defined in your `wp-config.php` (it is in every standard WordPress install). The plugin uses it to derive the AES-256-CBC encryption key and the HMAC-SHA256 key for stored tokens.
>
> **Optional:** define `SCPS_ENCRYPTION_SALT` in `wp-config.php` to use a custom salt for encryption key rotation instead of the default `AUTH_KEY`-derived salt.

---

## Installation

1. Upload the `social-posts-sync` folder to `/wp-content/plugins/`.
2. Activate the plugin from **Plugins > Installed Plugins**.
3. Go to **Settings > Social Posts Sync** to configure it.

---

## Configuration

### 1 — API tab

1. Enter your **Meta App ID** and **App Secret** (Settings > Social Posts Sync > Configuration API).
2. Copy the **Callback URI** shown on that screen and add it to your Meta App's Valid OAuth Redirect URIs.
3. Click **Connect with Meta** and complete the OAuth flow.

The connected account name and token expiry date are shown once you are authenticated. The plugin warns you 7 days before the token expires.

### 2 — Sources tab

Add the Facebook pages or Instagram accounts you want to sync:

| Identifier type | Platform | Example |
|---|---|---|
| Numeric page ID | Facebook | `123456789012345` |
| Numeric IG Business Account ID | Instagram (own account) | `987654321098765` |
| Instagram username | Instagram (Business Discovery) | `mybrand` |

You can load your own pages/accounts from the connected Meta account, or add any public page or Instagram Business account by entering its ID or username.

### 3 — Sync tab

- **Max posts per source** — 1 to 100 (default 20)
- **Cron frequency** — hourly / every 6 h / every 12 h / daily
- **Manual sync** — trigger an immediate sync from the admin
- **Sync log** — last 10 sync runs with success/error counts per source

### 4 — Advanced tab

| Setting | Default | Description |
|---|---|---|
| CPT slug | `social-posts` | Base URL for social post archives |
| Media timeout | 30 s | Timeout for downloading images (10–120 s) |
| Store raw data | `false` | Save the full raw API response in `_scps_raw_data` (disabled by default to save space) |

**Danger zone:**
- **Reset sync timestamps** — forces a full re-fetch on next sync (does not delete existing posts)
- **Purge posts** — deletes all `social_post` entries and their sideloaded media
- **Full reset** — purge + delete connection, settings, and all plugin options

---

## Custom Post Type

Posts are saved as the `social_post` post type and tagged with the `scps_platform` taxonomy (Facebook / Instagram).

### Meta fields

| Meta key | Description |
|---|---|
| `_scps_platform` | `facebook` or `instagram` |
| `_scps_source_id` | Original post ID on the platform |
| `_scps_content` | Caption / message |
| `_scps_permalink` | URL to the original post |
| `_scps_published_at` | Publication date (ISO 8601) |
| `_scps_media_urls` | JSON array of image URLs |
| `_scps_media_ids` | JSON array of sideloaded attachment IDs |
| `_scps_gallery_ids` | Comma-separated gallery attachment IDs |
| `_scps_video_url` | Video URL (not sideloaded) |
| `_scps_author_name` | Page or account name |
| `_scps_author_avatar` | Avatar URL |
| `_scps_likes_count` | Like count |
| `_scps_raw_data` | Full raw API response (JSON) |

---

## Elementor Dynamic Tags

When Elementor is active, the plugin registers a **Social Posts Sync** group with 7 dynamic tags usable inside any Elementor template applied to `social_post`:

| Tag | Output |
|---|---|
| Lien vers post | URL — original post permalink |
| Plateforme | Text — `facebook` / `instagram` |
| Date pub. | Text — formatted publication date |
| Nom page/compte | Text — author name |
| Avatar | Image — author avatar |
| Likes | Number — like count |
| URL vidéo | URL — video URL |
| Galerie | Gallery — sideloaded media attachments |

---

## Developer Hooks

### Actions

```php
// Fired just before a sync run starts.
// @param array $sources The enabled sources array.
do_action( 'scps_before_sync', $sources );

// Fired after a sync run completes.
// @param array $log The sync log entry for this run.
do_action( 'scps_after_sync', $log );
```

### Filters

```php
// Modify a normalized post before it is saved.
// @param array $normalized  The normalized post data.
// @param array $raw         The raw API response.
// @return array
apply_filters( 'scps_normalize_post', $normalized, $raw );
```

### Dependency injection

`SyncRunner` accepts an optional `PostSyncer` instance via its constructor, making it testable and extensible:

```php
$syncer = new PostSyncer();
$runner = new SyncRunner( $syncer );
$runner->run();
```

When called without arguments, `SyncRunner` instantiates its own `PostSyncer` internally.

### Normalized post structure

```php
[
    'platform'      => 'facebook' | 'instagram',
    'source_id'     => string,   // Platform post ID
    'content'       => string,   // Caption / message
    'permalink'     => string,   // URL to original post
    'published_at'  => string,   // ISO 8601
    'media_urls'    => string[], // Image URLs
    'video_url'     => string,   // Video URL or ''
    'author_name'   => string,
    'author_avatar' => string,
    'likes_count'   => int,
    'raw'           => array,    // Original API data
]
```

---

## Directory Structure

```
social-posts-sync/
├── social-posts-sync.php
└── includes/
    ├── helpers.php
    ├── ScpsHelpers.php
    ├── Auth/
    │   ├── MetaOAuth.php
    │   └── TokenStorage.php
    ├── Api/
    │   ├── FeedInterface.php
    │   ├── MetaApiClient.php
    │   ├── MetaApiException.php
    │   ├── FacebookFeed.php
    │   └── InstagramFeed.php
    ├── CPT/
    │   └── SocialPostCPT.php
    ├── Sync/
    │   ├── PostSyncer.php
    │   ├── SyncRunner.php
    │   └── CronSync.php
    ├── Cron/
    │   └── CronScheduler.php
    ├── Helpers/
    │   ├── Encryption.php
    │   ├── FacebookPostNormalizer.php
    │   ├── InstagramPostNormalizer.php
    │   ├── MediaSideloader.php
    │   └── ProxyClient.php
    ├── Admin/
    │   ├── SettingsPage.php
    │   ├── AjaxHandlers.php
    │   ├── AssetLoader.php
    │   ├── Tabs/
    │   │   ├── ApiTab.php
    │   │   ├── SourcesTab.php
    │   │   ├── SyncTab.php
    │   │   └── AdvancedTab.php
    │   └── Metaboxes/
    │       ├── SocialInfoMetabox.php
    │       └── GalleryMetabox.php
    └── Elementor/
        ├── DynamicTags.php
        └── Tags/
            ├── ScpsPermalinkTag.php
            ├── ScpsPlatformTag.php
            ├── ScpsPublishedAtTag.php
            ├── ScpsAuthorNameTag.php
            ├── ScpsAuthorAvatarTag.php
            ├── ScpsGalleryTag.php
            ├── ScpsLikesCountTag.php
            └── ScpsVideoUrlTag.php
```

---

## License

GPL-2.0-or-later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
