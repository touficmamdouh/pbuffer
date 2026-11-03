=== FormatoCD Publish with Buffer ===
Contributors: formatocd
Tags: buffer, social media, auto publish, graphql
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.2.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generates Buffer posts automatically from WordPress posts. Connect your site to the Buffer GraphQL API.

== Description ==

FormatoCD Publish with Buffer is a powerful, lightweight plugin that automates the publishing of your WordPress posts to your social media channels using the modern Buffer GraphQL API. 

Stop copying and pasting links! With this plugin, you can easily share your content across multiple social networks as soon as you hit "Publish", or add them to your Buffer queue.

= 🚀 Features =

* **Automatic Publishing:** Automatically sends your WordPress posts to Buffer the moment they are published.
* **Multiple Publishing Modes:** Choose between Share Now or Add to Queue.
* **Customizable Message Templates:** Create your own message format using dynamic variables like `{title}`, `{url}`, `{author}`, and more.
* **Smart Hashtags:** Automatically converts your WordPress tags into #hashtags for social media.
* **Category Filtering:** Restrict automatic Buffer posting only to posts belonging to a specific category.
* **Featured Image Support:** Automatically attaches the post's featured image to the Buffer publication.
* **Duplicate Prevention:** Internally tracks when a post has already been shared to Buffer to avoid duplicate submissions upon post updates.
* **Translation Ready (i18n):** Fully prepared for translation. English and Spanish (`es_ES`) languages are included by default.

== Installation ==

1. Upload the `formatocd-publish-with-buffer` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Settings > Publish with Buffer** to configure your API Token and Channels.
4. Go to any Post and look for the "Publish with Buffer" meta box in the sidebar to start sharing.

== External Services ==

This plugin connects to the third-party Buffer GraphQL API (https://api.buffer.com) to automatically publish your social media posts.

Data is sent to Buffer ONLY when a WordPress post is published (or automatically published via WordPress cron) and the user has actively checked the "Send to Buffer on publish" option.

The specific data sent to the Buffer API includes:
* Post Title
* Post URL
* Post Excerpt
* Author Display Name
* Post Categories and Tags (formatted as #hashtags)
* Featured Image URL

This service is provided by Buffer. By using this plugin, you agree to their legal terms:
* Buffer Terms of Service: https://buffer.com/legal/terms
* Buffer Privacy Policy: https://buffer.com/legal/privacy

API Documentacion can be found here: https://developers.buffer.com

== Frequently Asked Questions ==

= Where do I get my Buffer API Token? =
You need to create a custom app in the Buffer Developer Portal to generate your personal access token. Note that this uses the new Buffer GraphQL API.

= How do I find my Channel IDs? =
The easiest way is to use our official command-line tool, [Buffer CLI](https://github.com/formatocd/buffer-cli), which instantly lists all your connected channels and their IDs. Alternatively, you can find them by querying the Buffer GraphQL API manually or by looking at the URL in your Buffer dashboard when viewing a specific channel.

== Screenshots ==

1. The Global Settings Panel where you configure your Buffer API and message templates.
2. The Meta Box inside the Post Editor to control the publishing mode per post.
3. Success message after a post has been successfully sent to Buffer.

== Changelog ==

= 1.2.1 - 2026-09-15 =
### Fixed
* Wrong versions and texts.

= 1.2.0 - 2026-09-14 =
### Added
* Full support for WordPress 7.1 and its new iframe-based block editor.

### Changed
* Replaced asynchronous API calls with synchronous ones, adding robust error logging to `debug.log` for failed Buffer posts.
* Updated settings registration to follow modern WordPress array standards.

### Removed
* Removed custom scheduled option for individual Buffer posts (non functional, the post is scheduled by WordPress).
* Deprecated `load_plugin_textdomain` function call.

### Fixed
* Updated the GraphQL `assets` input format to comply with Buffer API's May 25, 2026 breaking changes.
* Decoded HTML entities in post titles and excerpts for cleaner display on social networks.
* Suppressed `error_log` Plugin Check warnings using standard `phpcs:ignore` comments.

### Security
* Renamed all functions and hooks to use the `formatocd_buffer_` prefix to prevent conflicts.
* Masked the API Token field in settings for better privacy.

= 1.1.0 - 2026-05-30 =
### Changed
* Updated compatibility for WordPress 7.0.

= 1.0.0 - 2026-04-04 =
### Added
* Initial public release on the WordPress repository.
* Support for GraphQL Buffer API.
* Dynamic variables and UI improvements to prevent duplicate posts.