=== Social Posts Sync ===
Contributors: hylbee
Tags: facebook, instagram, social media, sync, custom post type
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.2.2
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fetches posts from your social media platforms and saves them as custom WordPress post types.

== Description ==

Sync Facebook Pages and Instagram Business accounts to WordPress via the Meta Graph API.

= Features =

* OAuth 2.0 authentication with Meta — App ID/Secret stored securely, access token encrypted (AES-256-CBC + HMAC-SHA256 encrypt-then-MAC)
* Facebook — fetch posts from public pages (no admin rights required)
* Instagram — fetch posts from your own Business accounts (direct) or from any public Business account (Business Discovery)
* Custom Post Type `social_post` with rich metadata (platform, likes, author, permalink, media, video, gallery)
* Automatic media sideloading — images are downloaded and added to the WordPress media library, with deduplication and MIME type whitelist validation
* Incremental sync — only fetches new posts since the last run
* WP-Cron scheduling — hourly, every 6 h, every 12 h, or daily
* Rate-limit back-off — exponential back-off from 15 min to 4 h when the API throttles requests
* Elementor dynamic tags — 7 tags to display social post data inside Elementor templates
* Admin UI — four-tab settings page: API connection, sources, sync, advanced
* Sync lock — prevents concurrent syncs; manual unlock available

== Installation ==

1. Upload the `social-posts-sync` folder to `/wp-content/plugins/`.
2. Activate the plugin from **Plugins > Installed Plugins**.
3. Go to **Settings > Social Posts Sync** to configure it.

== Configuration ==

= API tab =

1. Enter your Meta App ID and App Secret (Settings > Social Posts Sync > Configuration API).
2. Copy the Callback URI shown on that screen and add it to your Meta App's Valid OAuth Redirect URIs.
3. Click Connect with Meta and complete the OAuth flow.

= Sources tab =

Add the Facebook pages or Instagram accounts you want to sync by numeric page ID, numeric IG Business Account ID, or Instagram username.

= Sync tab =

* Max posts per source — 1 to 100 (default 20)
* Cron frequency — hourly / every 6 h / every 12 h / daily
* Manual sync — trigger an immediate sync from the admin
* Sync log — last 10 sync runs with success/error counts per source

= Advanced tab =

* CPT slug (default: social-posts)
* Media timeout — timeout for downloading images (10–120 s, default 30 s)
* Store raw data — save the full raw API response in `_scps_raw_data` (disabled by default)

== Frequently Asked Questions ==

= What Meta App permissions are required? =

`public_profile`, `pages_show_list`, `pages_read_engagement`, `pages_read_user_content`, `instagram_basic`, `instagram_content_publish`

= Does the plugin work without Elementor? =

Yes. Elementor dynamic tags are registered only when Elementor is active. All other features work independently.

= Is AUTH_KEY required? =

Yes. `AUTH_KEY` must be defined in your `wp-config.php` (it is in every standard WordPress install). The plugin uses it to derive the AES-256-CBC encryption key for stored tokens.

== Changelog ==

= 1.2.2 =
* Fix: replace cURL with WP HTTP API (wp_remote_get) in media sideloader
* Fix: replace unlink() with wp_delete_file(), parse_url() with wp_parse_url()
* Fix: wrap error_log() calls with WP_DEBUG condition
* Fix: missing translators comments in GalleryMetabox and SettingsPage
* Fix: EscapeOutput false positive in MetaApiClient (int code passed to exception)
* Fix: phpcs ignore with justification for meta_query (covered by DB index)
* Fix: remove schema change (CREATE INDEX) on activation — incompatible with WordPress.org guidelines
* Add: readme.txt for WordPress.org compatibility

= 1.2.1 =
* Fix: correct Publications count in sync stats by introducing _scps_account_id

= 1.2.0 =
* Enhance encryption: AES-256-CBC + HMAC-SHA256, optional SCPS_ENCRYPTION_SALT for key rotation
* Refacto: inject PostSyncer via constructor in SyncRunner

== Upgrade Notice ==

= 1.2.2 =
WordPress.org Plugin Check compliance fixes. No breaking changes.
