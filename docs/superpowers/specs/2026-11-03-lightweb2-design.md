# LightWeb2 Publish with Buffer: Design Spec

- Date: 2026-11-03
- Status: Approved for planning
- Product owner: LightWeb2
- Plugin slug: `lightweb2-publish-with-buffer`
- Text domain: `lightweb2-publish-with-buffer`

## 1. Context

The current project is a single-file WordPress plugin that publishes posts to Buffer through the Buffer GraphQL API. It has no Git history, no tests, and no separation between settings, API communication, publishing, and UI.

The existing plugin was tested on a clean WordPress 7.1.4 installation with PHP 8.3. The API token saved correctly, appeared in the database, and produced the `Token is set` state after reloading the settings page. The reported token failure is therefore treated as an environment issue, most likely a security plugin, cache, permissions problem, or a stale copy of the plugin. The new plugin will add explicit connection diagnostics so this class of failure is visible instead of silent.

The current implementation also has structural problems that must be fixed:

- Publishing is synchronous and can block the editor for up to 15 seconds per channel.
- A failure on one channel can cause duplicate posts on channels that already succeeded.
- There is no channel discovery, connection test, retry policy, or delivery log.
- Channel IDs must be pasted manually.
- `js/buffer-admin.js` is never enqueued.
- The plugin has no automated tests or release process.

This is a new, independent product. No data migration from the existing FormatoCD plugin is required.

## 2. Product Decisions

| Decision | Choice |
| --- | --- |
| Brand | LightWeb2 |
| Product | Free WordPress.org plugin |
| Authentication | Buffer personal API key |
| First release scope | Professional baseline |
| Minimum WordPress | 6.5 |
| Minimum PHP | 7.4 |
| Tested up to | WordPress 7.1 |
| UI language | English, fully translation-ready |
| Admin stack | Native WordPress admin, no build step |
| Migration | None |
| Token encryption | Not included in 1.0.0 |

## 3. Goals

- Save and validate a Buffer personal API key with clear connection status.
- Discover Buffer organizations and channels instead of requiring manual channel IDs.
- Let administrators select target channels and publish settings once.
- Let editors enable or disable Buffer publishing per post.
- Publish asynchronously so saving a post is never blocked by Buffer.
- Prevent duplicate delivery per post and channel.
- Retry temporary failures without duplicating successful deliveries.
- Show delivery status and actionable errors in the WordPress admin.
- Deliver a branded, accessible, translation-ready plugin that passes WordPress.org checks.

## 4. Non-Goals

- OAuth or acting on behalf of other Buffer users.
- Per-channel message templates.
- Buffer custom scheduling or queue management beyond `shareNow` and `addToQueue`.
- Analytics or engagement reporting.
- A SaaS dashboard or central multi-site control plane.
- Network-wide Multisite administration.
- Migration from the existing FormatoCD options.

## 5. Architecture

The plugin uses native WordPress APIs and small PHP classes. There is no React application, no Vite build, no runtime Composer dependency, and no external font or CDN request.

All PHP classes live in the `LightWeb2\PublishWithBuffer` namespace. WordPress-facing identifiers use the `lightweb2_` prefix.

```text
lightweb2-publish-with-buffer/
├── lightweb2-publish-with-buffer.php
├── includes/
│   ├── class-plugin.php
│   ├── class-installer.php
│   ├── class-settings.php
│   ├── class-admin.php
│   ├── class-meta-box.php
│   ├── class-buffer-client.php
│   ├── class-message-builder.php
│   ├── class-publisher.php
│   └── class-deliveries.php
├── assets/
│   ├── admin.css
│   ├── admin.js
│   └── icon.svg
├── languages/
│   └── lightweb2-publish-with-buffer.pot
├── tests/
├── docs/
├── readme.txt
├── uninstall.php
├── .gitignore
└── .distignore
```

The bootstrap file defines constants, requires the class files, and starts `Plugin`. A custom autoloader is not needed for this number of classes.

### Component Responsibilities

| Component | Responsibility |
| --- | --- |
| `Plugin` | Wires hooks and coordinates the other components |
| `Installer` | Activation, database schema, defaults, deactivation, uninstall |
| `Settings` | Registers the settings option and sanitizes all values |
| `Admin` | Menu, settings page, assets, admin notices, AJAX endpoints |
| `Meta_Box` | Per-post toggle, mode, and delivery status |
| `Buffer_Client` | GraphQL requests, authentication, response normalization |
| `Message_Builder` | Template rendering, excerpt, hashtags, featured image |
| `Publisher` | Publish transitions, delivery creation, cron workers, retries |
| `Deliveries` | Database access and delivery state transitions |

## 6. Data Model

### Settings Option

All settings are stored in one option named `lightweb2_settings` so a token change and its dependent channel selection are saved atomically.

The option is created during activation with autoload disabled. It is never autoloaded on normal front-end requests.

```php
[
    'token'           => '',
    'channels'        => [
        [
            'id'      => 'buffer_channel_id',
            'name'    => 'Company LinkedIn',
            'service' => 'linkedin',
        ],
    ],
    'default_mode'    => 'addToQueue',
    'template'        => "{title}\n\n{excerpt}\n\n{url}\n\n{tags}",
    'category_filter' => 0,
    'retention_days'  => 30,
]
```

Rules:

- Saving an empty token preserves the existing token.
- Saving a different token clears the channel selection and invalidates the channel catalog.
- Channels are stored as objects, not bare IDs, so the UI can show a readable name even if Buffer is temporarily unreachable.
- `default_mode` must be `shareNow` or `addToQueue`. Invalid values fall back to `addToQueue`.
- The per-post checkbox is unchecked by default. Enabling it uses `default_mode`.
- `category_filter` is a term ID, with `0` meaning all categories.
- `retention_days` is an integer between 1 and 365, default 30.

### Database Version

A separate `lightweb2_db_version` option stores the installed schema version. It is updated by `Installer` when the schema changes.

### Channel Catalog Transient

Fetched organizations and channels are cached for 12 hours in a transient whose name includes a hash of the current token. The transient is deleted when the token changes. The transient is a convenience cache only, never the source of truth for selected channels.

### Post Meta

| Key | Values | Purpose |
| --- | --- | --- |
| `_lightweb2_enabled` | `yes` or `no` | Whether this post should be sent on publish |
| `_lightweb2_mode` | `shareNow` or `addToQueue` | Per-post publishing mode |

The meta box uses a nonce named `lightweb2_meta_nonce`. Saving checks autosave, revision, and `edit_post` capability before updating meta.

### Deliveries Table

Table name: `{$wpdb->prefix}lightweb2_deliveries`.

```sql
CREATE TABLE {$wpdb->prefix}lightweb2_deliveries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  channel_id varchar(64) NOT NULL,
  channel_name varchar(191) NOT NULL DEFAULT '',
  service varchar(32) NOT NULL DEFAULT '',
  mode varchar(32) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  buffer_post_id varchar(64) DEFAULT NULL,
  error_code varchar(64) DEFAULT NULL,
  error_message text NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY post_channel (post_id, channel_id),
  KEY status (status),
  KEY created_at (created_at)
) {$charset_collate};
```

Allowed statuses:

- `pending`: created and waiting for the worker.
- `sending`: atomically claimed by one worker and currently in flight.
- `sent`: Buffer accepted the post.
- `failed`: the last attempt failed, with `error_code` and `error_message`.
- `canceled`: the post was no longer publishable when the worker ran.

The unique key on `(post_id, channel_id)` is the final duplicate guard. A successful delivery is never selected for retry.

## 7. Data Flow

### Settings Save

1. The administrator submits the native Settings API form.
2. `Settings::sanitize` validates every field.
3. An empty token preserves the existing token.
4. A changed token clears channels and the catalog transient.
5. The complete settings array is written once.
6. The page redirects with a standard WordPress settings notice.

Disconnect is a separate nonce-protected admin action. It clears the token, selected channels, and the catalog transient while preserving delivery history. The UI requires confirmation before disconnecting.

### Test Connection

`wp_ajax_lightweb2_test_connection` handles the request.

1. Verify the `lightweb2_admin` nonce.
2. Verify `manage_options`.
3. Use the submitted token if present; otherwise use the saved token.
4. Query the Buffer account and organizations.
5. Return a JSON result with the account name and organization count, or a sanitized error.
6. The test never saves the token.

### Fetch Channels

`wp_ajax_lightweb2_fetch_channels` handles the request with the same permission checks.

1. Query the Buffer account and organizations.
2. Query channels for each organization.
3. Normalize each channel to `id`, `name`, and `service`.
4. Cache the catalog for 12 hours.
5. Return organizations and channels to the settings UI.

### Publish Flow

The plugin listens to `wp_after_insert_post`, not `save_post`, so meta box values are already saved when publishing is evaluated.

```text
post published
  -> confirm transition from non-publish to publish
  -> confirm _lightweb2_enabled = yes
  -> confirm token and selected channels exist
  -> confirm category filter matches
  -> build message and featured image
  -> insert one pending delivery per selected channel
  -> schedule one cron event per channel
```

The editor request ends immediately after scheduling. Buffer is not called during the editor request.

### Delivery Worker

The cron hook is `lightweb2_deliver_channel` with arguments `post_id`, `channel_id`, and `attempt`.

1. Load the delivery row.
2. Return immediately if the status is `sent` or `canceled`.
3. Atomically claim the row with a conditional update. The claim succeeds only when the status is `pending` or `failed`, the stored attempt count matches the event's expected attempt, and `attempts < 4`.
4. The successful claim sets the status to `sending` and increments `attempts` in the same query.
5. Confirm the post is still published. If not, mark `canceled`.
6. Call `Buffer_Client::create_post`.
7. On success, store the Buffer post ID and mark `sent`.
8. On failure, store the normalized error and schedule a retry when allowed.

Because the claim is a single database update, two workers cannot both call Buffer for the same delivery. A duplicate or stale cron event fails the conditional claim and exits without an API call.

### Retry Policy

| Failure | Retry | Notes |
| --- | --- | --- |
| Network error, timeout, `5xx` | Yes | Delays: 5 minutes, 15 minutes, 60 minutes |
| HTTP `429` | Yes | Use `Retry-After` when present, otherwise 15 minutes |
| HTTP `401` or `403` | No | Mark failed and show "Reconnect Buffer" |
| GraphQL `UNAUTHORIZED` or `FORBIDDEN` | No | Mark failed and show "Reconnect Buffer" |
| GraphQL typed `MutationError` | No | Show the returned message |
| Invalid or missing channel | No | Mark failed |
| Post no longer published | No | Mark canceled |
| Empty rendered message | No | Mark failed with a configuration error |

Maximum automatic attempts: four total, meaning the first attempt plus up to three retries.

Every retry uses the same delivery row and the same unique key. A successful channel is never retried.

### Manual Retry

`wp_ajax_lightweb2_retry_delivery` accepts a delivery ID.

1. Verify nonce and `manage_options`.
2. Load the row.
3. Allow retry only when the status is `failed`.
4. Clear pending delivery events for that post and channel so an old attempt cannot join the new cycle.
5. Atomically reset the row to `pending` and the attempt cycle to zero only if it is still `failed`.
6. Schedule attempt one immediately.

Manual retry is not blocked by the automatic maximum. It resets the stored attempt count to zero, marks the row pending, and schedules attempt one. The automatic four-attempt limit applies again to the new manual cycle.

### Stale Worker Recovery

A worker can be interrupted after claiming a row and before writing the result. An hourly `lightweb2_recover_stale` event finds rows in `sending` state older than 15 minutes. It marks a row failed with code `stale_claim` using a conditional update that succeeds only while the row is still stale and `sending`, then schedules the next attempt when the stored attempt count is below four. The conditional update prevents recovery from overwriting a row that finished as `sent` or `canceled` after the stale query.

### Log Retention

A daily `lightweb2_cleanup_logs` cron event deletes rows older than `retention_days`.

Clear logs is a nonce-protected admin action with a confirmation step. It deletes delivery rows only, not posts or settings.

### Activation and Deactivation

Activation creates the table, adds the default settings option with autoload disabled, stores the database version, and schedules cleanup.

Activation schedules the daily log cleanup and hourly stale-worker recovery. Deactivation unschedules all plugin cron hooks. It does not delete settings, deliveries, or post meta.

Uninstall deletes the settings option, database version option, catalog transients, deliveries table, and the `_lightweb2_enabled` and `_lightweb2_mode` post meta keys.

## 8. Buffer API Contract

The client uses `wp_safe_remote_post` against `https://api.buffer.com`.

Required headers:

- `Content-Type: application/json`
- `Authorization: Bearer {token}`

Timeout is 15 seconds. SSL verification remains enabled.

The client exposes:

| Method | Purpose |
| --- | --- |
| `test_connection( $token )` | Query account and organizations |
| `get_organizations( $token )` | Return organizations for the account |
| `get_channels( $token, $organization_id )` | Return channels for one organization |
| `create_post( $token, $input )` | Create one Buffer post |

The `createPost` mutation includes both `PostActionSuccess` and `MutationError` fragments. The client checks:

1. WordPress HTTP errors.
2. HTTP status codes.
3. GraphQL top-level `errors`.
4. Typed mutation errors in `data.createPost`.
5. Missing or malformed success payloads.

Errors are normalized to a code, a user-safe message, and a `retryable` boolean. Authorization headers and raw tokens are never logged.

## 9. UI and Brand

### Admin Navigation

One top-level menu named `LightWeb2` with two subpages:

- `Publish with Buffer`
- `Activity`

The menu uses a small SVG icon. The plugin does not add unrelated admin pages.

### Settings Page

The page is divided into native WordPress cards:

1. **Connection**: token field, connection status, Test connection, Load channels, Disconnect.
2. **Channels**: organization and service grouping, checkboxes, selected count.
3. **Publishing defaults**: mode selector, template editor, variable reference, live preview.
4. **Filtering**: category selector.
5. **Tools**: retention days, clear logs, plugin version.

The token field is always empty on render. When a token exists, the UI shows `Connected` and a masked placeholder. The saved token is never printed into HTML, JavaScript, or an attribute.

### Meta Box

The meta box appears in the post sidebar:

- LightWeb2 heading.
- `Send to Buffer on publish` checkbox.
- Mode selector.
- Connection warning with a link to settings when needed.
- Per-channel delivery badges for this post.
- Link to the Activity page for errors.

### Activity Page

The page lists deliveries with:

- Date and time.
- Post title, linked to the post editor.
- Channel name and service.
- Mode.
- Status.
- Attempts.
- Buffer post ID when sent.
- Sanitized error message when failed.

Filters: status, channel, and date range. Pagination defaults to 20 rows. A Retry action appears only for failed rows. Clear logs requires confirmation and a nonce.

### Brand Direction

The mark is a simple geometric light-beam monogram for `LightWeb2`. It must remain legible at 20px in the WordPress admin menu.

Palette:

| Token | Value | Use |
| --- | --- | --- |
| Ink | `#0E1B2C` | Headings and strong text |
| Primary | `#155EEF` | Primary actions and links |
| Aqua | `#7AF0C8` | Brand accent and highlights |
| Amber | `#B45309` | Warnings |
| Success | `#15803D` | Success states |
| Danger | `#B91C1C` | Errors |
| Surface | `#F8FAFC` | Cards and panels |
| Border | `#E2E8F0` | Dividers and card borders |

Design rules:

- Use the WordPress admin font stack. Do not load external fonts.
- Use color plus text or icon for every status. Never rely on color alone.
- Respect `prefers-reduced-motion`.
- Use logical CSS properties so RTL works without a separate stylesheet.
- Keep the interface native to WordPress. Branding comes from the mark, header treatment, palette, and copy, not from replacing WordPress controls.

### Copy Voice

Direct and practical. Explain what happened and what to do next. Avoid hype, emojis, and vague success messages.

Examples:

- `Connected to Buffer as {account}.`
- `Buffer rejected this channel. Reload channels and select it again.`
- `The token is invalid or expired. Reconnect Buffer.`
- `No deliveries yet. Publish a post with Buffer enabled to see activity here.`

## 10. Security and Privacy

- Settings and Activity require `manage_options`.
- Meta box save requires `edit_post`, a valid nonce, and a non-revision, non-autosave request.
- All AJAX endpoints require a nonce and capability check.
- All input is unslashed and sanitized. All output is escaped.
- Token storage uses one settings option with autoload disabled.
- The token is never written to HTML, JavaScript, logs, or error messages.
- Buffer requests use `wp_safe_remote_post` with SSL verification.
- Error messages are sanitized and truncated before display or logging.
- Only published posts with the per-post option enabled are sent.
- The readme discloses the external service, the data sent, and links to Buffer's terms and privacy policy.
- The plugin performs no analytics, tracking, or calls to LightWeb2 servers.

Token encryption is intentionally not included in the first release. It would make recovery depend on WordPress salts, and rotating those salts would silently break saved connections. Access is constrained by capability checks, autoload is disabled, and the token is never rendered.

## 11. Compatibility and Internationalization

- WordPress 6.5 or newer.
- PHP 7.4 or newer.
- Tested up to WordPress 7.1.
- Block editor and Classic Editor meta boxes.
- Scheduled posts transitioning from `future` to `publish`.
- Quick Edit and bulk publish transitions.
- Multisite: per-site configuration only. Network activation does not provide a shared network settings page.
- All user-facing strings use the `lightweb2-publish-with-buffer` text domain.
- The `.pot` file is generated and shipped.
- Dates use `wp_date` and the site timezone.
- No external fonts, scripts, or styles.

## 12. Testing

### Automated Tests

Use PHPUnit with the WordPress test suite. HTTP calls are replaced through the `pre_http_request` filter.

| Area | Tests |
| --- | --- |
| Settings | Defaults, empty token preserves token, changed token clears channels, invalid mode falls back, retention bounds, channel sanitization |
| Buffer client | Successful account query, successful channel query, successful post, `401`, `403`, `429`, `5xx`, GraphQL errors, malformed payload |
| Message builder | All variables, excerpt fallback, HTML entity decoding, tags to hashtags including Unicode, missing values, featured image |
| Publisher | Publish transition creates deliveries, category mismatch skips, disabled post skips, sent channel is skipped, temporary failure schedules retry, permanent failure does not retry, max attempts respected, unpublished post cancels |
| Deliveries | Unique key prevents duplicates, atomic claim prevents concurrent work, stale events are rejected, status transitions, attempt increments, retention cleanup |
| Concurrency | Two workers cannot both claim one delivery; a stale or duplicate event makes no Buffer call; a claimed row is recovered after the stale timeout |
| Retry limits | 401/typed mutation errors do not retry, four failed attempts schedule no fifth automatic event, manual retry resets the attempt cycle |
| Installer | Table creation, default option, autoload disabled, deactivation preserves data, uninstall removes data |

### Static Checks

- PHPCS with the WordPress standard.
- PHPCompatibility for PHP 7.4.
- WordPress Plugin Check.
- JavaScript syntax check.

### Manual QA Matrix

- Fresh WordPress 6.5 and WordPress 7.1.
- PHP 7.4 and PHP 8.3.
- Block editor and Classic Editor.
- Scheduled post publishing through WP-Cron.
- Category filter matching and non-matching.
- Valid token, invalid token, missing token.
- One channel succeeds and another fails.
- Retry succeeds without duplicating the successful channel.
- Activity filters, pagination, retry, and clear logs.
- Mobile admin width.
- RTL locale.
- Keyboard-only navigation and visible focus.

### Live Smoke Test

A real Buffer test account and token are required before release. The live test covers connection, channel loading, one `addToQueue` delivery, one `shareNow` delivery, and an invalid-token failure. Automated tests use mocks and do not require Buffer credentials.

## 13. Implementation Phases

### Phase 1: Foundation and Settings

- Initialize Git and `.gitignore`.
- Create the new plugin bootstrap and class files.
- Add `Installer`, activation defaults, and the deliveries table.
- Add the single settings option and sanitization.
- Build the branded settings shell.
- Add token save, empty-token preservation, and changed-token channel clearing.
- Add settings tests and the token diagnostic state.

Acceptance: token saves, empty save preserves it, changed token clears channels, data survives deactivation, and tests pass.

### Phase 2: Buffer Connection and Channels

- Implement `Buffer_Client` queries.
- Add Test connection and Load channels AJAX endpoints.
- Add the channel catalog transient.
- Build the organization and channel picker.
- Add error states and accessible loading feedback.
- Add client and AJAX tests.

Acceptance: a valid token loads organizations and channels, invalid tokens show a clear error, and selected channel objects survive reload.

### Phase 3: Publishing Pipeline

- Implement `Message_Builder`.
- Add the meta box and save handling.
- Add `wp_after_insert_post` publishing.
- Implement delivery creation, cron workers, locks, retries, and status transitions.
- Add publisher and deliveries tests.

Acceptance: publishing returns immediately, one row is created per channel, failed channels retry, successful channels never repeat, and the editor is never blocked.

### Phase 4: Activity and UI Polish

- Build the Activity page with filters and pagination.
- Add retry and clear-log actions.
- Add per-post status badges.
- Finish the LightWeb2 visual treatment.
- Add responsive, RTL, keyboard, and reduced-motion checks.

Acceptance: every delivery is visible and actionable, errors are understandable, and the interface passes the accessibility checks.

### Phase 5: Release Hardening

- Add `uninstall.php`.
- Generate the `.pot` file.
- Write `readme.txt` with external service disclosure.
- Add PHPCS, PHPCompatibility, Plugin Check, and CI.
- Run the full QA matrix and the live Buffer smoke test.
- Build the release ZIP and prepare WordPress.org SVN submission.

Acceptance: all checks pass, no token leaks, the ZIP contains only production files, and the live smoke test succeeds.

## 14. Release

- Version: `1.0.0`.
- Plugin name: `LightWeb2 Publish with Buffer`.
- Plugin slug and text domain: `lightweb2-publish-with-buffer`.
- Author: `LightWeb2`.
- No Author URI until a verified domain exists.
- License: GPL-2.0-or-later.
- No runtime Composer dependencies.
- Development dependencies are excluded from the release ZIP.
- The ZIP excludes `tests/`, `docs/`, `.git/`, `node_modules/`, and development configuration.
- The WordPress.org listing includes screenshots for settings, channel selection, meta box, and activity.
- The public name is reviewed against WordPress.org trademark rules before submission.

## 15. Success Criteria

- A clean WordPress install saves the token and shows a connected state.
- An empty token save does not erase an existing token.
- A changed token forces channel reselection.
- Channels are loaded from Buffer and selected without manual IDs.
- Publishing is asynchronous and does not block the editor.
- Each channel has an independent delivery record.
- A failed channel retries without duplicating a successful channel.
- Authorization failures stop retrying and show a reconnect action.
- The Activity page explains what happened for every delivery.
- The token never appears in HTML, JavaScript, logs, or error messages.
- The plugin passes PHPCS, PHPCompatibility, Plugin Check, automated tests, and the manual QA matrix.
- The plugin is usable with keyboard only and at mobile admin widths.

## 16. Risks and Dependencies

| Risk | Mitigation |
| --- | --- |
| WP-Cron is unreliable on low-traffic sites | Document system Cron and show delivery status clearly |
| Buffer changes the GraphQL schema | Keep all API details inside `Buffer_Client` and cover errors with tests |
| Rate limits affect bulk publishing | Respect `429`, `Retry-After`, and retry backoff |
| A live Buffer account is needed for the final smoke test | Provide a test token before Phase 5 |
| WordPress.org rejects the use of the Buffer name | Review the public name before submission and switch to `for Buffer` wording if required |
| Plain token storage is exposed through a database leak | Capability checks, no autoload, no rendering, no logging, and documented risk |
| Cron worker runs while the plugin is deactivated | Deactivation unschedules all plugin hooks |
| A post is unpublished before delivery | Worker marks the delivery canceled |

## 17. Out of Scope for 1.0.0

- OAuth 2.0 and refresh-token storage.
- Multiple Buffer accounts per site.
- Per-channel templates or network-specific metadata.
- Buffer ideas, drafts, tags, threads, or custom scheduled times.
- Engagement metrics and reports.
- A central LightWeb2 server or SaaS dashboard.
- Migration from FormatoCD settings.
- Network-wide Multisite settings.
