=== LightWeb2 Publish with Buffer ===
Contributors: lightweb2
Tags: buffer, social media, auto publish, graphql, scheduling
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
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

WP-Cron depends on site traffic or a system cron job. Configure a real cron job on low-traffic sites.

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

= 1.0.0 =
* Initial LightWeb2 release.
* Buffer personal API key connection.
* Channel discovery and selection.
* Asynchronous publishing with per-channel tracking.
* Duplicate prevention and safe retries.
* Activity log and manual retry.
