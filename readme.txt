=== LightWeb2 Publish with Buffer ===
Contributors: lightweb2
Tags: buffer, social media, auto publish, graphql, scheduling
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.15
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish WordPress posts to Buffer channels with per-channel tracking, duplicate prevention, and safe retries.

== Description ==

LightWeb2 Publish with Buffer connects WordPress to the Buffer GraphQL API using a personal Buffer API key.

Choose your channels once, enable Buffer on a post, and publish. The plugin schedules the delivery in the background, tracks each channel independently, and retries temporary failures without duplicating posts that already succeeded.

= Features =

* Connect with a Buffer personal API key.
* Test the connection before publishing.
* Load organizations and channels from Buffer.
* Choose one or more target channels.
* Send immediately or add to the Buffer queue.
* Template variables: `{title}`, `{url}`, `{excerpt}`, `{author}`, `{category}`, `{tags}`.
* Convert WordPress tags to hashtags.
* Attach the featured image.
* Filter by category.
* Per-channel delivery status.
* Automatic retries for temporary failures.
* Activity log with manual retry.
* Translation-ready with RTL support.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open LightWeb2 > Publish with Buffer.
4. Paste a Buffer personal API key and save.
5. Test the connection, load channels, select channels, and save.
6. Edit a post, enable Send to Buffer on publish, and publish.

== Frequently Asked Questions ==

= Where do I get a Buffer API key? =

Open Buffer, go to Settings > API, create a personal API key, and paste it into the Connection card.

= Does the plugin block the editor while publishing? =

No. Publishing schedules a background WP-Cron task. The editor request returns immediately.

= What happens when one channel fails? =

Each channel has its own delivery record. A failed channel is retried without resending to channels that already succeeded.

= Why is my delivery still pending? =

Queue mode dispatches the first delivery immediately and keeps WP-Cron as a safety net. A real system cron job is still recommended for retries and cleanup on low-traffic sites.

= Does the plugin use OAuth? =

No. Version 1.0.0 uses a Buffer personal API key that acts on the account that created it.

== External Services ==

This plugin connects to the Buffer GraphQL API at `https://api.buffer.com`.

The plugin sends data only when a published post has Send to Buffer on publish enabled and Buffer is connected.

Data sent to Buffer:

* Post title.
* Post URL.
* Post excerpt.
* Author display name.
* Category name.
* Tags formatted as hashtags.
* Featured image URL.
* Selected channel IDs.
* Publishing mode.

Buffer Terms of Service: https://buffer.com/legal/terms

Buffer Privacy Policy: https://buffer.com/legal/privacy

Developer documentation: https://developers.buffer.com

The Buffer API key is stored in your WordPress database and is never sent to LightWeb2 servers. The plugin performs no analytics or tracking.

== Changelog ==

= 1.0.14 =
* Refined the admin workspace into a two-column desktop dashboard with a compact article list and side controls.
* Moved the message editor and live preview below the dashboard with responsive mobile behavior.
* Preserved existing Buffer publishing, settings, AJAX, and security functionality.

= 1.0.13 =
* Redesigned the admin workspace with a responsive RTL-friendly dashboard layout.
* Added bulk publishing for the latest 20 published posts.
* Added live previews based on the latest article and one-click template variables.

= 1.0.12 =
* Fixed cleanup of the fallback Cron event after immediate delivery.

= 1.0.11 =
* Fixed the fallback Cron event timestamp while retaining immediate delivery.

= 1.0.10 =
* Fixed deterministic delivery scheduling in the automated test suite.

= 1.0.9 =
* Fixed CI compatibility across supported WordPress and PHP versions.
* Kept immediate queue dispatch covered by the delivery tests.
* Tightened Twitter message length to stay safely below the platform limit.

= 1.0.8 =
* Published the initial GitHub release package for manual installation.

= 1.0.7 =
* Dispatches the first queued delivery immediately when WP-Cron cannot start it.
* Keeps WP-Cron as a fallback for interrupted requests and future retries.

= 1.0.6 =
* Triggers WP-Cron immediately after scheduling a delivery.

= 1.0.5 =
* Added manual publishing for the latest 20 published posts.
* Refreshed the admin interface and LightWeb2 branding.

= 1.0.0 =
* Initial LightWeb2 release.
* Buffer personal API key connection.
* Channel discovery and selection.
* Asynchronous publishing with per-channel tracking.
* Duplicate prevention and safe retries.
* Activity log and manual retry.
