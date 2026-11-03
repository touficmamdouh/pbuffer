# LightWeb2 Publish with Buffer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `LightWeb2 Publish with Buffer` as a branded, translation-ready WordPress.org plugin that connects with a Buffer personal API key, discovers channels, publishes asynchronously, prevents duplicates per channel, retries failures, and exposes delivery activity.

**Architecture:** Native WordPress admin with focused PHP classes in the `LightWeb2\PublishWithBuffer` namespace. One settings option stores all configuration atomically with autoload disabled. A custom deliveries table provides per-channel idempotency. Publishing is scheduled through WP-Cron and never blocks the editor.

**Tech Stack:** PHP 7.4+, WordPress 6.5+, native JavaScript, CSS, Composer for development tools only, PHPUnit with `wp-phpunit/wp-phpunit`, WordPress Coding Standards, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-11-03-lightweb2-design.md`

## Global Constraints

- Minimum WordPress: 6.5.
- Minimum PHP: 7.4.
- Tested up to WordPress 7.1.
- Plugin slug and text domain: `lightweb2-publish-with-buffer`.
- Namespace: `LightWeb2\PublishWithBuffer`.
- WordPress identifiers use the `lightweb2_` prefix.
- No runtime Composer dependencies.
- No React, Vite, build step, external fonts, or CDN assets.
- One settings option: `lightweb2_settings`, created with autoload disabled.
- Settings and Activity require `manage_options`.
- Meta box writes require `edit_post`, a valid nonce, and reject autosave and revisions.
- Token is never rendered in HTML, JavaScript, logs, or error messages.
- Buffer requests use `wp_safe_remote_post` with SSL verification.
- Publishing is asynchronous through WP-Cron.
- Duplicate delivery is prevented by the unique key `(post_id, channel_id)`.
- Successful channels are never retried.
- No migration from FormatoCD options.
- Multisite support is per-site configuration only; no network-wide settings page.
- Every task ends with a focused Git commit.

## File Map

| File | Responsibility |
| --- | --- |
| `lightweb2-publish-with-buffer.php` | Plugin header, constants, file loading, activation hooks |
| `includes/class-plugin.php` | Wires components and cron cleanup |
| `includes/class-installer.php` | Activation, schema, deactivation, uninstall |
| `includes/class-settings.php` | Settings option, defaults, sanitization, catalog key |
| `includes/class-admin.php` | Menu, settings page, Activity page, assets, AJAX |
| `includes/class-meta-box.php` | Per-post toggle, mode, delivery status |
| `includes/class-buffer-client.php` | Buffer GraphQL requests and error normalization |
| `includes/class-message-builder.php` | Template rendering, excerpt, hashtags, image |
| `includes/class-publisher.php` | Publish transition, scheduling, worker, retries |
| `includes/class-deliveries.php` | Delivery table queries and state transitions |
| `assets/admin.css` | LightWeb2 admin styling and RTL-safe layout |
| `assets/admin.js` | Connection tests, channel loading, preview, confirmation actions |
| `assets/icon.svg` | LightWeb2 menu mark |
| `tests/bootstrap.php` | WP PHPUnit bootstrap and plugin loading |
| `tests/wp-tests-config.php` | Environment-driven test database configuration |
| `tests/test-*.php` | Integration tests |
| `readme.txt` | WordPress.org listing and external service disclosure |
| `uninstall.php` | Data removal on uninstall |
| `.github/workflows/ci.yml` | PHP and WordPress compatibility matrix |
| `bin/build.sh` | Production ZIP build |

---

## Task 1: Repository Baseline, Test Harness, Bootstrap, Installer

**Files:**
- Create: `.gitignore`, `.distignore`, `composer.json`, `phpunit.xml.dist`
- Create: `tests/bootstrap.php`, `tests/wp-tests-config.php`, `tests/test-installer.php`
- Create: `lightweb2-publish-with-buffer.php`, `includes/class-settings.php`, `includes/class-installer.php`, `includes/class-plugin.php`
- Delete: `formatocd-publish-with-buffer.php`, `js/buffer-admin.js`, `languages/formatocd-publish-with-buffer.po`, `languages/formatocd-publish-with-buffer.mo`, `languages/formatocd-publish-with-buffer.pot`

**Interfaces:**
- Produces: `LightWeb2\PublishWithBuffer\Installer::activate(): void`
- Produces: `LightWeb2\PublishWithBuffer\Installer::deactivate(): void`
- Produces: `LightWeb2\PublishWithBuffer\Installer::uninstall(): void`
- Produces: `LightWeb2\PublishWithBuffer\Settings::OPTION`
- Produces: `LightWeb2\PublishWithBuffer\Settings::defaults(): array`
- Produces: `LightWeb2\PublishWithBuffer\Plugin::instance(): Plugin`

- [ ] **Step 1: Capture the current plugin in Git before replacing it**

```bash
git init
git add .
git commit -m "chore: capture baseline FormatoCD plugin"
```

Expected: one commit containing the original plugin and the approved spec.

- [ ] **Step 2: Create development configuration and the failing installer test**

`.gitignore`:

```gitignore
/vendor/
/wordpress/
/node_modules/
/build/
.phpunit.result.cache
.DS_Store
```

`.distignore`:

```text
/.git
/.github
/tests
/docs
/vendor
/wordpress
/node_modules
/bin
/build
/composer.json
/composer.lock
/phpunit.xml.dist
/phpcs.xml.dist
/.distignore
/.gitignore
```

`composer.json`:

```json
{
    "name": "lightweb2/lightweb2-publish-with-buffer",
    "description": "Publish WordPress posts to Buffer channels.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=7.4"
    },
    "require-dev": {
        "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
        "phpcompatibility/phpcompatibility-wp": "^2.1",
        "phpunit/phpunit": "^9.6",
        "roots/wordpress": "6.5.13",
        "roots/wordpress-core-installer": "^4.0",
        "wp-coding-standards/wpcs": "^3.1",
        "wp-phpunit/wp-phpunit": "6.5.13",
        "yoast/phpunit-polyfills": "^2.0"
    },
    "config": {
        "platform": {
            "php": "7.4.33"
        },
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true,
            "roots/wordpress-core-installer": true
        }
    },
    "scripts": {
        "test": "phpunit",
        "phpcs": "phpcs"
    }
}
```

`phpunit.xml.dist`:

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    backupGlobals="false"
    colors="true"
    convertErrorsToExceptions="true"
    convertWarningsToExceptions="true"
    convertNoticesToExceptions="true"
    convertDeprecationsToExceptions="true"
>
    <testsuites>
        <testsuite name="integration">
            <directory prefix="test-" suffix=".php">./tests/</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="WP_PHPUNIT__TESTS_CONFIG" value="tests/wp-tests-config.php" />
    </php>
</phpunit>
```

`tests/bootstrap.php`:

```php
<?php
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';

tests_add_filter(
    'muplugins_loaded',
    static function (): void {
        require dirname( __DIR__ ) . '/lightweb2-publish-with-buffer.php';
    }
);

require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';
```

`tests/wp-tests-config.php`:

```php
<?php
declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__ ) . '/wordpress/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'lightweb2-test-auth-key' );
define( 'SECURE_AUTH_KEY', 'lightweb2-test-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'lightweb2-test-logged-in-key' );
define( 'NONCE_KEY', 'lightweb2-test-nonce-key' );
define( 'AUTH_SALT', 'lightweb2-test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'lightweb2-test-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'lightweb2-test-logged-in-salt' );
define( 'NONCE_SALT', 'lightweb2-test-nonce-salt' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'LightWeb2 Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
```

`tests/test-installer.php`:

```php
<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Installer;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Installer extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        Installer::activate();
    }

    public function test_deliveries_table_is_created(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'lightweb2_deliveries';
        $found = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
        );

        $this->assertSame( $table, $found );
    }

    public function test_default_settings_are_created_without_autoload(): void {
        global $wpdb;

        $settings = get_option( Settings::OPTION );

        $this->assertSame( 'addToQueue', $settings['default_mode'] );
        $this->assertSame( 30, $settings['retention_days'] );
        $this->assertSame(
            'no',
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
                    Settings::OPTION
                )
            )
        );
    }

    public function test_deactivation_preserves_settings_and_deliveries(): void {
        global $wpdb;

        update_option( Settings::OPTION, [ 'token' => 'keep-me' ] + Settings::defaults() );
        $wpdb->insert(
            $wpdb->prefix . 'lightweb2_deliveries',
            [
                'post_id'    => 123,
                'channel_id' => 'channel-1',
                'status'     => 'pending',
                'created_at' => gmdate( 'Y-m-d H:i:s' ),
                'updated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );

        Installer::deactivate();

        $this->assertSame( 'keep-me', get_option( Settings::OPTION )['token'] );
        $this->assertSame(
            '1',
            $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}lightweb2_deliveries WHERE post_id = 123"
            )
        );
    }
}
```

- [ ] **Step 3: Install dependencies and verify the test fails**

```bash
composer install --no-interaction
mysql -u root -e "CREATE DATABASE IF NOT EXISTS wordpress_test;"
composer test
```

Expected: FAIL because the plugin classes do not exist yet.

- [ ] **Step 4: Replace the old plugin files and implement the bootstrap, settings defaults, installer, and plugin wiring**

Delete the old plugin files:

```bash
rm -f formatocd-publish-with-buffer.php js/buffer-admin.js
rm -f languages/formatocd-publish-with-buffer.po
rm -f languages/formatocd-publish-with-buffer.mo
rm -f languages/formatocd-publish-with-buffer.pot
rmdir js 2>/dev/null || true
```

`lightweb2-publish-with-buffer.php`:

```php
<?php
/**
 * Plugin Name: LightWeb2 Publish with Buffer
 * Description: Publish WordPress posts to Buffer channels.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: LightWeb2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lightweb2-publish-with-buffer
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LIGHTWEB2_VERSION', '1.0.0' );
define( 'LIGHTWEB2_FILE', __FILE__ );
define( 'LIGHTWEB2_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIGHTWEB2_URL', plugin_dir_url( __FILE__ ) );

require_once LIGHTWEB2_DIR . 'includes/class-settings.php';
require_once LIGHTWEB2_DIR . 'includes/class-installer.php';
require_once LIGHTWEB2_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, [ '\LightWeb2\PublishWithBuffer\Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\LightWeb2\PublishWithBuffer\Installer', 'deactivate' ] );

add_action(
    'plugins_loaded',
    static function (): void {
        \LightWeb2\PublishWithBuffer\Plugin::instance()->boot();
    }
);
```

`includes/class-settings.php`:

```php
<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Settings {

    public const OPTION = 'lightweb2_settings';

    public static function defaults(): array {
        return [
            'token'           => '',
            'channels'        => [],
            'default_mode'    => 'addToQueue',
            'template'        => "{title}\n\n{excerpt}\n\n{url}\n\n{tags}",
            'category_filter' => 0,
            'retention_days'  => 30,
        ];
    }

    public function get(): array {
        $value = get_option( self::OPTION, [] );
        $value = is_array( $value ) ? $value : [];

        return wp_parse_args( $value, self::defaults() );
    }

    public static function catalog_key( string $token ): string {
        return 'lightweb2_channels_' . substr( md5( $token ), 0, 12 );
    }
}
```

`includes/class-installer.php`:

```php
<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Installer {

    public const DB_VERSION = '1.0.0';

    public static function activate(): void {
        self::create_table();

        if ( false === get_option( Settings::OPTION ) ) {
            add_option( Settings::OPTION, Settings::defaults(), '', 'no' );
        }

        update_option( 'lightweb2_db_version', self::DB_VERSION, false );

        if ( ! wp_next_scheduled( 'lightweb2_cleanup_logs' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lightweb2_cleanup_logs' );
        }

        if ( ! wp_next_scheduled( 'lightweb2_recover_stale' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'lightweb2_recover_stale' );
        }
    }

    public static function create_table(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = $wpdb->prefix . 'lightweb2_deliveries';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
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
        ) {$charset};";

        dbDelta( $sql );
    }

    public static function deactivate(): void {
        wp_unschedule_hook( 'lightweb2_deliver_channel' );
        wp_unschedule_hook( 'lightweb2_cleanup_logs' );
        wp_unschedule_hook( 'lightweb2_recover_stale' );
    }

    public static function uninstall(): void {
        global $wpdb;

        self::deactivate();

        delete_option( Settings::OPTION );
        delete_option( 'lightweb2_db_version' );
        delete_post_meta_by_key( '_lightweb2_enabled' );
        delete_post_meta_by_key( '_lightweb2_mode' );

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_lightweb2_channels_%'
                OR option_name LIKE '_transient_timeout_lightweb2_channels_%'"
        );

        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lightweb2_deliveries" );
    }
}
```

`includes/class-plugin.php`:

```php
<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Plugin {

    private static ?Plugin $instance = null;

    private Settings $settings;

    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void {
        $this->settings = new Settings();
        $this->settings->register();
    }
}
```

- [ ] **Step 5: Run the installer tests**

```bash
composer test -- --filter Test_Installer
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "feat: add LightWeb2 bootstrap, installer, and test harness"
```

---

## Task 2: Settings Sanitization

**Files:**
- Modify: `includes/class-settings.php`
- Create: `tests/test-settings.php`

**Interfaces:**
- Consumes: `Settings::OPTION`, `Settings::defaults()`, `Settings::catalog_key()`
- Produces: `Settings::register(): void`
- Produces: `Settings::get(): array`
- Produces: `Settings::sanitize( $input ): array`
- Produces: `Settings::sanitize_channels( $input ): array`

- [ ] **Step 1: Write the failing settings tests**

`tests/test-settings.php`:

```php
<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Settings;

final class Test_Settings extends WP_UnitTestCase {

    private Settings $settings;

    public function set_up(): void {
        parent::set_up();
        $this->settings = new Settings();
        update_option(
            Settings::OPTION,
            [
                'token'           => 'old-token',
                'channels'        => [
                    [ 'id' => 'c1', 'name' => 'One', 'service' => 'linkedin' ],
                ],
                'default_mode'    => 'shareNow',
                'template'        => '{title}',
                'category_filter' => 0,
                'retention_days'  => 30,
            ]
        );
    }

    public function test_empty_token_preserves_existing_token_and_channels(): void {
        $result = $this->settings->sanitize(
            [
                'token'  => '',
                'channels' => [],
            ]
        );

        $this->assertSame( 'old-token', $result['token'] );
        $this->assertSame( 'c1', $result['channels'][0]['id'] );
    }

    public function test_changed_token_clears_channels(): void {
        $result = $this->settings->sanitize(
            [
                'token'             => 'new-token',
                'channels'          => [
                    [ 'id' => 'c2', 'name' => 'Two', 'service' => 'x' ],
                ],
                'channels_submitted' => 1,
            ]
        );

        $this->assertSame( 'new-token', $result['token'] );
        $this->assertSame( [], $result['channels'] );
    }

    public function test_submitted_empty_channels_clear_existing_channels(): void {
        $result = $this->settings->sanitize(
            [
                'token'              => '',
                'channels'           => [],
                'channels_submitted' => 1,
            ]
        );

        $this->assertSame( 'old-token', $result['token'] );
        $this->assertSame( [], $result['channels'] );
    }

    public function test_invalid_mode_falls_back_to_add_to_queue(): void {
        $result = $this->settings->sanitize(
            [
                'token'        => '',
                'default_mode' => 'invalid-mode',
            ]
        );

        $this->assertSame( 'addToQueue', $result['default_mode'] );
    }

    public function test_valid_share_now_mode_is_preserved(): void {
        $result = $this->settings->sanitize(
            [
                'token'        => '',
                'default_mode' => 'shareNow',
            ]
        );

        $this->assertSame( 'shareNow', $result['default_mode'] );
    }

    public function test_retention_days_are_clamped(): void {
        $low  = $this->settings->sanitize( [ 'retention_days' => 0 ] );
        $high = $this->settings->sanitize( [ 'retention_days' => 9999 ] );

        $this->assertSame( 1, $low['retention_days'] );
        $this->assertSame( 365, $high['retention_days'] );
    }

    public function test_channels_are_sanitized_and_deduplicated(): void {
        $result = $this->settings->sanitize(
            [
                'token'              => '',
                'channels_submitted' => 1,
                'channels'           => [
                    [ 'id' => ' c1 ', 'name' => '<b>One</b>', 'service' => 'LinkedIn' ],
                    [ 'id' => 'c1', 'name' => 'Duplicate', 'service' => 'x' ],
                    [ 'id' => 'c2', 'name' => 'Two', 'service' => 'instagram' ],
                ],
            ]
        );

        $this->assertCount( 2, $result['channels'] );
        $this->assertSame( 'c1', $result['channels'][0]['id'] );
        $this->assertSame( 'One', $result['channels'][0]['name'] );
        $this->assertSame( 'linkedin', $result['channels'][0]['service'] );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
composer test -- --filter Test_Settings
```

Expected: FAIL because `register()` and `sanitize()` do not exist.

- [ ] **Step 3: Implement the settings registration and sanitization**

Replace `includes/class-settings.php` with:

```php
<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Settings {

    public const OPTION = 'lightweb2_settings';

    public function register(): void {
        add_action( 'admin_init', [ $this, 'register_setting' ] );
    }

    public function register_setting(): void {
        register_setting(
            'lightweb2_settings_group',
            self::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => self::defaults(),
            ]
        );
    }

    public static function defaults(): array {
        return [
            'token'           => '',
            'channels'        => [],
            'default_mode'    => 'addToQueue',
            'template'        => "{title}\n\n{excerpt}\n\n{url}\n\n{tags}",
            'category_filter' => 0,
            'retention_days'  => 30,
        ];
    }

    public function get(): array {
        $value = get_option( self::OPTION, [] );
        $value = is_array( $value ) ? $value : [];

        return wp_parse_args( $value, self::defaults() );
    }

    public static function catalog_key( string $token ): string {
        return 'lightweb2_channels_' . substr( md5( $token ), 0, 12 );
    }

    public function sanitize( $input ): array {
        $input   = is_array( $input ) ? $input : [];
        $current = $this->get();

        $token = isset( $input['token'] )
            ? sanitize_text_field( (string) $input['token'] )
            : '';

        if ( '' === $token ) {
            $token = (string) $current['token'];
        }

        $token_changed = $token !== (string) $current['token'];

        if ( $token_changed ) {
            delete_transient( self::catalog_key( (string) $current['token'] ) );
            delete_transient( self::catalog_key( $token ) );
            $channels = [];
            if ( function_exists( 'add_settings_error' ) ) {
                add_settings_error(
                    'lightweb2',
                    'lightweb2_token_changed',
                    __( 'The Buffer token changed. Load and select your channels again.', 'lightweb2-publish-with-buffer' ),
                    'warning'
                );
            }
        } elseif ( ! empty( $input['channels_submitted'] ) ) {
            $channels = $this->sanitize_channels( $input['channels'] ?? [] );
        } else {
            $channels = is_array( $current['channels'] ) ? $current['channels'] : [];
        }

        $mode = isset( $input['default_mode'] )
            ? sanitize_text_field( (string) $input['default_mode'] )
            : (string) $current['default_mode'];

        if ( ! in_array( $mode, [ 'shareNow', 'addToQueue' ], true ) ) {
            $mode = 'addToQueue';
        }

        $template = isset( $input['template'] )
            ? sanitize_textarea_field( (string) $input['template'] )
            : (string) $current['template'];

        if ( '' === trim( $template ) ) {
            $template = self::defaults()['template'];
        }

        $retention = isset( $input['retention_days'] )
            ? absint( $input['retention_days'] )
            : (int) $current['retention_days'];

        $retention = max( 1, min( 365, $retention ) );

        return [
            'token'           => $token,
            'channels'        => $channels,
            'default_mode'    => $mode,
            'template'        => $template,
            'category_filter' => isset( $input['category_filter'] )
                ? absint( $input['category_filter'] )
                : (int) $current['category_filter'],
            'retention_days'  => $retention,
        ];
    }

    public function sanitize_channels( $input ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }

        $channels = [];
        $seen     = [];

        foreach ( $input as $channel ) {
            if ( ! is_array( $channel ) ) {
                continue;
            }

            $id = isset( $channel['id'] )
                ? substr( sanitize_text_field( (string) $channel['id'] ), 0, 64 )
                : '';

            if ( '' === $id || isset( $seen[ $id ] ) ) {
                continue;
            }

            $seen[ $id ] = true;
            $channels[]  = [
                'id'      => $id,
                'name'    => isset( $channel['name'] )
                    ? substr( sanitize_text_field( (string) $channel['name'] ), 0, 191 )
                    : '',
                'service' => isset( $channel['service'] )
                    ? substr( sanitize_key( (string) $channel['service'] ), 0, 32 )
                    : '',
            ];
        }

        return $channels;
    }
}
```

- [ ] **Step 4: Run the settings tests**

```bash
composer test -- --filter Test_Settings
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-settings.php tests/test-settings.php
git commit -m "feat: add settings sanitization and token preservation"
```

---

## Task 3: Branded Settings Shell

**Files:**
- Create: `includes/class-admin.php`, `assets/admin.css`, `assets/admin.js`, `assets/icon.svg`
- Modify: `includes/class-plugin.php`
- Create: `tests/test-admin.php`

**Interfaces:**
- Consumes: `Settings::get()`, `Settings::OPTION`
- Produces: `Admin::__construct( Settings $settings )`
- Produces: `Admin::register(): void`
- Produces: `Admin::render_settings_page(): void`

- [ ] **Step 1: Write the failing render test**

`tests/test-admin.php`:

```php
<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Admin;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Admin extends WP_UnitTestCase {

    public function test_settings_page_renders_without_printing_the_token(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        update_option(
            Settings::OPTION,
            [
                'token'    => 'secret-token-value',
                'channels' => [],
            ] + Settings::defaults()
        );

        $admin = new Admin( new Settings() );

        ob_start();
        $admin->render_settings_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'LightWeb2', $html );
        $this->assertStringContainsString( 'lightweb2_settings[token]', $html );
        $this->assertStringContainsString( 'Token is set', $html );
        $this->assertStringNotContainsString( 'secret-token-value', $html );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
composer test -- --filter Test_Admin
```

Expected: FAIL because `Admin` does not exist.

- [ ] **Step 3: Implement `Admin`, the settings shell, and brand assets**

`includes/class-admin.php`:

```php
<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Admin {

    private Settings $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menu(): void {
        add_menu_page(
            __( 'LightWeb2', 'lightweb2-publish-with-buffer' ),
            __( 'LightWeb2', 'lightweb2-publish-with-buffer' ),
            'manage_options',
            'lightweb2',
            [ $this, 'render_settings_page' ],
            LIGHTWEB2_URL . 'assets/icon.svg',
            80
        );

        add_submenu_page(
            'lightweb2',
            __( 'Publish with Buffer', 'lightweb2-publish-with-buffer' ),
            __( 'Publish with Buffer', 'lightweb2-publish-with-buffer' ),
            'manage_options',
            'lightweb2',
            [ $this, 'render_settings_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        $is_plugin_screen = in_array(
            $hook,
            [ 'toplevel_page_lightweb2', 'lightweb2_page_lightweb2-activity' ],
            true
        );
        $is_post_screen   = in_array( $hook, [ 'post.php', 'post-new.php' ], true );

        if ( ! $is_plugin_screen && ! $is_post_screen ) {
            return;
        }

        wp_enqueue_style(
            'lightweb2-admin',
            LIGHTWEB2_URL . 'assets/admin.css',
            [],
            LIGHTWEB2_VERSION
        );

        if ( ! $is_plugin_screen ) {
            return;
        }

        wp_enqueue_script(
            'lightweb2-admin',
            LIGHTWEB2_URL . 'assets/admin.js',
            [],
            LIGHTWEB2_VERSION,
            true
        );
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->settings->get();
        $has_token = '' !== (string) $settings['token'];
        ?>
        <div class="wrap lightweb2-wrap">
            <header class="lightweb2-header">
                <div>
                    <p class="lightweb2-eyebrow"><?php esc_html_e( 'LightWeb2', 'lightweb2-publish-with-buffer' ); ?></p>
                    <h1><?php esc_html_e( 'Publish with Buffer', 'lightweb2-publish-with-buffer' ); ?></h1>
                    <p class="lightweb2-lede"><?php esc_html_e( 'Connect Buffer, choose your channels, and share WordPress posts without the busywork.', 'lightweb2-publish-with-buffer' ); ?></p>
                </div>
                <span class="lightweb2-version">v<?php echo esc_html( LIGHTWEB2_VERSION ); ?></span>
            </header>

            <?php settings_errors(); ?>

            <form action="options.php" method="post" class="lightweb2-form">
                <?php settings_fields( 'lightweb2_settings_group' ); ?>

                <section class="lightweb2-card">
                    <h2><?php esc_html_e( 'Connection', 'lightweb2-publish-with-buffer' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Create a personal API key in Buffer, then paste it here.', 'lightweb2-publish-with-buffer' ); ?></p>
                    <label for="lightweb2-token" class="lightweb2-label"><?php esc_html_e( 'Buffer API Token', 'lightweb2-publish-with-buffer' ); ?></label>
                    <input
                        type="password"
                        id="lightweb2-token"
                        name="lightweb2_settings[token]"
                        value=""
                        placeholder="<?php echo esc_attr( $has_token ? '********' : '' ); ?>"
                        class="regular-text"
                        autocomplete="new-password"
                    />
                    <p class="lightweb2-status <?php echo $has_token ? 'is-connected' : ''; ?>" id="lightweb2-connection-status">
                        <?php echo $has_token
                            ? esc_html__( 'Token is set. Enter a new one to replace it.', 'lightweb2-publish-with-buffer' )
                            : esc_html__( 'Not connected yet.', 'lightweb2-publish-with-buffer' ); ?>
                    </p>
                </section>

                <section class="lightweb2-card">
                    <h2><?php esc_html_e( 'Channels', 'lightweb2-publish-with-buffer' ); ?></h2>
                    <input type="hidden" name="lightweb2_settings[channels_submitted]" value="1" />
                    <div id="lightweb2-channels" class="lightweb2-channels">
                        <?php if ( empty( $settings['channels'] ) ) : ?>
                            <p class="description"><?php esc_html_e( 'No channels selected. Save your token, then load channels.', 'lightweb2-publish-with-buffer' ); ?></p>
                        <?php else : ?>
                            <?php foreach ( $settings['channels'] as $channel ) : ?>
                                <label class="lightweb2-channel">
                                    <input
                                        type="checkbox"
                                        name="lightweb2_settings[channels][]"
                                        value="<?php echo esc_attr( $channel['id'] ); ?>"
                                        checked
                                    />
                                    <span><?php echo esc_html( $channel['name'] ?: $channel['id'] ); ?></span>
                                    <small><?php echo esc_html( $channel['service'] ); ?></small>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="lightweb2-card">
                    <h2><?php esc_html_e( 'Publishing defaults', 'lightweb2-publish-with-buffer' ); ?></h2>
                    <label for="lightweb2-mode" class="lightweb2-label"><?php esc_html_e( 'Default mode', 'lightweb2-publish-with-buffer' ); ?></label>
                    <select id="lightweb2-mode" name="lightweb2_settings[default_mode]">
                        <option value="addToQueue" <?php selected( $settings['default_mode'], 'addToQueue' ); ?>><?php esc_html_e( 'Add to Queue', 'lightweb2-publish-with-buffer' ); ?></option>
                        <option value="shareNow" <?php selected( $settings['default_mode'], 'shareNow' ); ?>><?php esc_html_e( 'Share Now', 'lightweb2-publish-with-buffer' ); ?></option>
                    </select>

                    <label for="lightweb2-template" class="lightweb2-label"><?php esc_html_e( 'Message template', 'lightweb2-publish-with-buffer' ); ?></label>
                    <textarea id="lightweb2-template" name="lightweb2_settings[template]" rows="5" class="large-text"><?php echo esc_textarea( $settings['template'] ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Variables:', 'lightweb2-publish-with-buffer' ); ?>
                        <code>{title}</code> <code>{url}</code> <code>{excerpt}</code>
                        <code>{author}</code> <code>{category}</code> <code>{tags}</code>
                    </p>
                    <label class="lightweb2-label"><?php esc_html_e( 'Preview', 'lightweb2-publish-with-buffer' ); ?></label>
                    <pre id="lightweb2-preview" class="lightweb2-preview"></pre>
                </section>

                <section class="lightweb2-card">
                    <h2><?php esc_html_e( 'Filtering', 'lightweb2-publish-with-buffer' ); ?></h2>
                    <label for="lightweb2-category" class="lightweb2-label"><?php esc_html_e( 'Category filter', 'lightweb2-publish-with-buffer' ); ?></label>
                    <?php
                    wp_dropdown_categories(
                        [
                            'show_option_all' => __( 'All categories', 'lightweb2-publish-with-buffer' ),
                            'name'            => 'lightweb2_settings[category_filter]',
                            'id'              => 'lightweb2-category',
                            'selected'        => (int) $settings['category_filter'],
                            'value_field'     => 'term_id',
                            'hide_empty'      => false,
                        ]
                    );
                    ?>
                </section>

                <section class="lightweb2-card">
                    <h2><?php esc_html_e( 'Tools', 'lightweb2-publish-with-buffer' ); ?></h2>
                    <label for="lightweb2-retention" class="lightweb2-label"><?php esc_html_e( 'Keep delivery history for', 'lightweb2-publish-with-buffer' ); ?></label>
                    <input type="number" id="lightweb2-retention" name="lightweb2_settings[retention_days]" min="1" max="365" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>" class="small-text" />
                    <span><?php esc_html_e( 'days', 'lightweb2-publish-with-buffer' ); ?></span>
                </section>

                <?php submit_button( __( 'Save Settings', 'lightweb2-publish-with-buffer' ) ); ?>
            </form>
        </div>
        <?php
    }
}
```

Update `includes/class-plugin.php` so `boot()` creates `Settings` and `Admin`, and calls both `register()` methods:

```php
<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Plugin {

    private static ?Plugin $instance = null;

    private Settings $settings;
    private Admin $admin;

    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void {
        $this->settings = new Settings();
        $this->admin    = new Admin( $this->settings );

        $this->settings->register();
        $this->admin->register();
    }
}
```

Add the require in `lightweb2-publish-with-buffer.php`:

```php
require_once LIGHTWEB2_DIR . 'includes/class-admin.php';
```

`assets/icon.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
  <path fill="#155EEF" d="M2.5 15.5 8.2 4h3.1L5.6 15.5H2.5Zm7.2 0L15.4 4h3.1l-5.7 11.5h-3.1Z"/>
</svg>
```

`assets/admin.css`:

```css
:root {
  --lightweb2-ink: #0e1b2c;
  --lightweb2-primary: #155eef;
  --lightweb2-aqua: #7af0c8;
  --lightweb2-surface: #f8fafc;
  --lightweb2-border: #e2e8f0;
  --lightweb2-muted: #64748b;
}

.lightweb2-wrap {
  max-width: 960px;
  color: var(--lightweb2-ink);
}

.lightweb2-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 24px;
  padding: 28px 0 20px;
  border-bottom: 3px solid transparent;
  border-image: linear-gradient(90deg, var(--lightweb2-primary), var(--lightweb2-aqua)) 1;
}

.lightweb2-header h1 {
  margin: 0 0 6px;
  font-size: 30px;
  letter-spacing: -0.02em;
}

.lightweb2-eyebrow {
  margin: 0 0 4px;
  color: var(--lightweb2-primary);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.lightweb2-lede {
  max-width: 620px;
  margin: 0;
  color: var(--lightweb2-muted);
  font-size: 15px;
}

.lightweb2-version {
  color: var(--lightweb2-muted);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 12px;
}

.lightweb2-card {
  margin: 20px 0;
  padding: 20px 22px;
  background: #fff;
  border: 1px solid var(--lightweb2-border);
  border-radius: 8px;
  box-shadow: 0 1px 2px rgb(15 23 42 / 4%);
}

.lightweb2-card h2 {
  margin: 0 0 8px;
  font-size: 18px;
}

.lightweb2-label {
  display: block;
  margin: 14px 0 6px;
  font-weight: 600;
}

.lightweb2-status {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin: 10px 0 0;
  color: var(--lightweb2-muted);
}

.lightweb2-status::before {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #94a3b8;
  content: "";
}

.lightweb2-status.is-connected {
  color: #15803d;
}

.lightweb2-status.is-connected::before {
  background: #15803d;
}

.lightweb2-channels {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 10px;
  margin-top: 12px;
}

.lightweb2-channel {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  background: var(--lightweb2-surface);
  border: 1px solid var(--lightweb2-border);
  border-radius: 6px;
}

.lightweb2-channel small {
  margin-inline-start: auto;
  color: var(--lightweb2-muted);
  text-transform: capitalize;
}

.lightweb2-preview {
  min-height: 96px;
  margin: 6px 0 0;
  padding: 14px;
  overflow: auto;
  white-space: pre-wrap;
  background: var(--lightweb2-surface);
  border: 1px solid var(--lightweb2-border);
  border-radius: 6px;
}

@media (max-width: 782px) {
  .lightweb2-header {
    flex-direction: column;
  }

  .lightweb2-card {
    padding: 16px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .lightweb2-wrap * {
    scroll-behavior: auto !important;
    transition: none !important;
    animation: none !important;
  }
}
```

- [ ] **Step 4: Run the render test**

```bash
composer test -- --filter Test_Admin
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-admin.php includes/class-plugin.php assets tests/test-admin.php lightweb2-publish-with-buffer.php
git commit -m "feat: add branded settings shell"
```

---

## Task 4: Buffer GraphQL Client

**Files:**
- Create: `includes/class-buffer-client.php`
- Create: `tests/test-buffer-client.php`

**Interfaces:**
- Produces: `Buffer_Client::test_connection( string $token ): array`
- Produces: `Buffer_Client::get_channels( string $token, string $organization_id ): array`
- Produces: `Buffer_Client::create_post( string $token, array $input ): array`
- Success shape: `[ 'success' => true, 'data' => array ]`
- Failure shape: `[ 'success' => false, 'code' => string, 'message' => string, 'retryable' => bool, 'retry_after' => int ]`

- [ ] **Step 1: Write the failing client tests**

`tests/test-buffer-client.php`:

```php
<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Buffer_Client;

final class Test_Buffer_Client extends WP_UnitTestCase {

    private Buffer_Client $client;

    public function set_up(): void {
        parent::set_up();
        $this->client = new Buffer_Client();
    }

    private function mock_response( array $body, int $status = 200, array $headers = [] ): void {
        add_filter(
            'pre_http_request',
            static function () use ( $body, $status, $headers ): array {
                return [
                    'headers'  => $headers,
                    'body'     => wp_json_encode( $body ),
                    'response' => [
                        'code'    => $status,
                        'message' => 200 === $status ? 'OK' : 'Error',
                    ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );
    }

    public function test_test_connection_sends_bearer_token_and_returns_data(): void {
        $captured = [];

        add_filter(
            'pre_http_request',
            static function ( $pre, $args ) use ( &$captured ): array {
                $captured = $args;

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(
                        [
                            'data' => [
                                'account' => [
                                    'id'            => 'acct-1',
                                    'name'          => 'Acme',
                                    'organizations' => [
                                        [ 'id' => 'org-1', 'name' => 'Acme Org' ],
                                    ],
                                ],
                            ],
                        ]
                    ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            },
            10,
            2
        );

        $result = $this->client->test_connection( 'test-token' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'Acme', $result['data']['account']['name'] );
        $this->assertSame( 'Bearer test-token', $captured['headers']['Authorization'] );
    }

    public function test_unauthorized_is_not_retryable(): void {
        $this->mock_response( [ 'errors' => [ [ 'message' => 'Not authorized' ] ] ], 401 );

        $result = $this->client->test_connection( 'bad-token' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'unauthorized', $result['code'] );
        $this->assertFalse( $result['retryable'] );
        $this->assertStringNotContainsString( 'bad-token', $result['message'] );
    }

    public function test_rate_limit_is_retryable_and_reads_retry_after(): void {
        $this->mock_response(
            [ 'errors' => [ [ 'message' => 'Too many requests' ] ] ],
            429,
            [ 'retry-after' => '120' ]
        );

        $result = $this->client->test_connection( 'test-token' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'rate_limited', $result['code'] );
        $this->assertTrue( $result['retryable'] );
        $this->assertSame( 120, $result['retry_after'] );
    }

    public function test_graphql_rate_limit_code_is_retryable(): void {
        $this->mock_response(
            [
                'errors' => [
                    [
                        'message'    => 'Rate limit exceeded',
                        'extensions' => [ 'code' => 'RATE_LIMIT_EXCEEDED' ],
                    ],
                ],
            ]
        );

        $result = $this->client->test_connection( 'test-token' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'rate_limit_exceeded', $result['code'] );
        $this->assertTrue( $result['retryable'] );
    }

    public function test_create_post_returns_buffer_post_id(): void {
        $this->mock_response(
            [
                'data' => [
                    'createPost' => [
                        'post' => [ 'id' => 'buffer-post-1', 'text' => 'Hello' ],
                    ],
                ],
            ]
        );

        $result = $this->client->create_post(
            'test-token',
            [
                'text'           => 'Hello',
                'channelId'      => 'channel-1',
                'schedulingType' => 'automatic',
                'mode'           => 'addToQueue',
            ]
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'buffer-post-1', $result['data']['createPost']['post']['id'] );
    }

    public function test_create_post_typed_error_is_not_retryable(): void {
        $this->mock_response(
            [
                'data' => [
                    'createPost' => [
                        'message' => 'Text is required',
                    ],
                ],
            ]
        );

        $result = $this->client->create_post( 'test-token', [ 'text' => '' ] );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'mutation_error', $result['code'] );
        $this->assertSame( 'Text is required', $result['message'] );
        $this->assertFalse( $result['retryable'] );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
composer test -- --filter Test_Buffer_Client
```

Expected: FAIL because `Buffer_Client` does not exist.

- [ ] **Step 3: Implement the client**

`includes/class-buffer-client.php`:

```php
<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Buffer_Client {

    public const ENDPOINT = 'https://api.buffer.com';

    public function test_connection( string $token ): array {
        return $this->request(
            $token,
            'query LightWeb2Account {
                account {
                    id
                    name
                    organizations {
                        id
                        name
                    }
                }
            }'
        );
    }

    public function get_channels( string $token, string $organization_id ): array {
        return $this->request(
            $token,
            'query LightWeb2Channels($organizationId: OrganizationId!) {
                channels(input: { organizationId: $organizationId }) {
                    id
                    name
                    service
                }
            }',
            [ 'organizationId' => $organization_id ]
        );
    }

    public function create_post( string $token, array $input ): array {
        $result = $this->request(
            $token,
            'mutation LightWeb2CreatePost($input: CreatePostInput!) {
                createPost(input: $input) {
                    ... on PostActionSuccess {
                        post {
                            id
                            text
                        }
                    }
                    ... on MutationError {
                        message
                    }
                }
            }',
            [ 'input' => $input ]
        );

        if ( ! $result['success'] ) {
            return $result;
        }

        $mutation = $result['data']['createPost'] ?? [];

        if ( isset( $mutation['message'] ) && ! isset( $mutation['post'] ) ) {
            return [
                'success'    => false,
                'code'       => 'mutation_error',
                'message'    => sanitize_text_field( (string) $mutation['message'] ),
                'retryable'  => false,
                'retry_after' => 0,
            ];
        }

        if ( empty( $mutation['post']['id'] ) ) {
            return [
                'success'    => false,
                'code'       => 'invalid_response',
                'message'    => __( 'Buffer returned an unexpected response.', 'lightweb2-publish-with-buffer' ),
                'retryable'  => true,
                'retry_after' => 0,
            ];
        }

        return $result;
    }

    private function request( string $token, string $query, array $variables = [] ): array {
        $response = wp_safe_remote_post(
            self::ENDPOINT,
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'body'        => wp_json_encode(
                    [
                        'query'     => $query,
                        'variables' => $variables,
                    ]
                ),
                'data_format' => 'body',
                'timeout'     => 15,
                'sslverify'   => true,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $this->failure(
                'wp_error',
                $response->get_error_message(),
                true
            );
        }

        $status  = (int) wp_remote_retrieve_response_code( $response );
        $body    = (string) wp_remote_retrieve_body( $response );
        $decoded = json_decode( $body, true );

        if ( 401 === $status ) {
            return $this->failure(
                'unauthorized',
                __( 'The token is invalid or expired. Reconnect Buffer.', 'lightweb2-publish-with-buffer' ),
                false
            );
        }

        if ( 403 === $status ) {
            return $this->failure(
                'forbidden',
                __( 'Buffer rejected this account. Reconnect Buffer.', 'lightweb2-publish-with-buffer' ),
                false
            );
        }

        if ( 429 === $status ) {
            $retry_after = absint( wp_remote_retrieve_header( $response, 'retry-after' ) );

            return $this->failure(
                'rate_limited',
                __( 'Buffer rate limit reached. The delivery will be retried.', 'lightweb2-publish-with-buffer' ),
                true,
                $retry_after
            );
        }

        if ( $status >= 500 ) {
            return $this->failure(
                'server_error',
                __( 'Buffer is temporarily unavailable.', 'lightweb2-publish-with-buffer' ),
                true
            );
        }

        if ( $status >= 400 ) {
            return $this->failure(
                'http_error',
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __( 'Buffer returned HTTP %d.', 'lightweb2-publish-with-buffer' ),
                    $status
                ),
                false
            );
        }

        if ( ! is_array( $decoded ) ) {
            return $this->failure(
                'invalid_json',
                __( 'Buffer returned an invalid response.', 'lightweb2-publish-with-buffer' ),
                true
            );
        }

        if ( ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
            $error   = $decoded['errors'][0];
            $code    = isset( $error['extensions']['code'] )
                ? sanitize_key( (string) $error['extensions']['code'] )
                : 'graphql_error';
            $message = isset( $error['message'] )
                ? sanitize_text_field( (string) $error['message'] )
                : __( 'Buffer returned an error.', 'lightweb2-publish-with-buffer' );
            $retry   = in_array( $code, [ 'rate_limit_exceeded', 'unexpected' ], true );

            return $this->failure( $code, $message, $retry );
        }

        if ( empty( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
            return $this->failure(
                'invalid_response',
                __( 'Buffer returned an unexpected response.', 'lightweb2-publish-with-buffer' ),
                true
            );
        }

        return [
            'success' => true,
            'data'    => $decoded['data'],
        ];
    }

    private function failure(
        string $code,
        string $message,
        bool $retryable,
        int $retry_after = 0
    ): array {
        return [
            'success'     => false,
            'code'        => $code,
            'message'     => $message,
            'retryable'   => $retryable,
            'retry_after' => $retry_after,
        ];
    }
}
```

Add the require in `lightweb2-publish-with-buffer.php`:

```php
require_once LIGHTWEB2_DIR . 'includes/class-buffer-client.php';
```

- [ ] **Step 4: Run the client tests**

```bash
composer test -- --filter Test_Buffer_Client
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-buffer-client.php tests/test-buffer-client.php lightweb2-publish-with-buffer.php
git commit -m "feat: add Buffer GraphQL client"
```

---

## Task 5: Connection AJAX and Channel Picker

**Files:**
- Modify: `includes/class-settings.php`
- Modify: `includes/class-admin.php`
- Modify: `includes/class-plugin.php`
- Modify: `lightweb2-publish-with-buffer.php`
- Modify: `assets/admin.js`
- Create: `tests/test-admin-ajax.php`
- Modify: `tests/test-settings.php`

**Interfaces:**
- Consumes: `Buffer_Client`, `Settings::catalog_key()`
- Produces: `Admin::__construct( Settings $settings, Buffer_Client $client )`
- Produces: AJAX actions `lightweb2_test_connection`, `lightweb2_fetch_channels`, `lightweb2_disconnect`
- Produces: `Settings::sanitize_channels( $input, string $token = '' ): array`

- [ ] **Step 1: Add the failing channel-ID sanitization test**

Append to `tests/test-settings.php`:

```php
    public function test_channel_ids_are_resolved_from_the_catalog_transient(): void {
        set_transient(
            Settings::catalog_key( 'old-token' ),
            [
                [
                    'id'       => 'org-1',
                    'name'     => 'Acme',
                    'channels' => [
                        [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
                        [ 'id' => 'c2', 'name' => 'Company Instagram', 'service' => 'instagram' ],
                    ],
                ],
            ],
            12 * HOUR_IN_SECONDS
        );

        $result = $this->settings->sanitize(
            [
                'token'              => '',
                'channels_submitted' => 1,
                'channels'           => [ 'c2', 'missing' ],
            ]
        );

        $this->assertCount( 2, $result['channels'] );
        $this->assertSame( 'c2', $result['channels'][0]['id'] );
        $this->assertSame( 'Company Instagram', $result['channels'][0]['name'] );
        $this->assertSame( 'instagram', $result['channels'][0]['service'] );
        $this->assertSame( 'missing', $result['channels'][1]['id'] );
        $this->assertSame( '', $result['channels'][1]['name'] );
    }
```

- [ ] **Step 2: Write the failing AJAX tests**

`tests/test-admin-ajax.php`:

```php
<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Admin;
use LightWeb2\PublishWithBuffer\Buffer_Client;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Admin_Ajax extends WP_Ajax_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        update_option(
            Settings::OPTION,
            [
                'token'    => 'saved-token',
                'channels' => [],
            ] + Settings::defaults()
        );
    }

    private function admin(): Admin {
        return new Admin( new Settings(), new Buffer_Client() );
    }

    private function prepare_nonce(): void {
        $nonce                   = wp_create_nonce( 'lightweb2_admin' );
        $_REQUEST['nonce']       = $nonce;
        $_POST['nonce']          = $nonce;
    }

    public function test_test_connection_requires_manage_options(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $this->prepare_nonce();
        $_POST['token'] = 'test-token';

        $requested = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$requested ): array {
                $requested = true;

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( [ 'data' => [ 'account' => [ 'name' => 'Should not happen' ] ] ] ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );

        try {
            $this->_handleAjax( 'lightweb2_test_connection' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected after wp_send_json_error().
        }

        $response = json_decode( $this->_last_response, true );

        $this->assertFalse( $requested );
        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'not allowed', $response['data']['message'] );
    }

    public function test_test_connection_rejects_missing_nonce(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        unset( $_POST['nonce'], $_REQUEST['nonce'] );
        $_POST['token'] = 'test-token';

        $requested = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$requested ): array {
                $requested = true;

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( [ 'data' => [ 'account' => [ 'name' => 'Should not happen' ] ] ] ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );

        $die_message = '';

        try {
            $this->_handleAjax( 'lightweb2_test_connection' );
        } catch ( WPAjaxDieStopException $e ) {
            $die_message = $e->getMessage();
        }

        $this->assertFalse( $requested );
        $this->assertSame( '-1', $die_message );
    }

    public function test_test_connection_returns_account_name(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->prepare_nonce();
        $_POST['token'] = 'test-token';

        add_filter(
            'pre_http_request',
            static function (): array {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(
                        [
                            'data' => [
                                'account' => [
                                    'id'            => 'acct-1',
                                    'name'          => 'Acme',
                                    'organizations' => [
                                        [ 'id' => 'org-1', 'name' => 'Acme Org' ],
                                    ],
                                ],
                            ],
                        ]
                    ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );

        $this->_handleAjax( 'lightweb2_test_connection' );
        $response = json_decode( $this->_last_response, true );

        $this->assertTrue( $response['success'] );
        $this->assertSame( 'Acme', $response['data']['account'] );
        $this->assertSame( 1, $response['data']['organizations'] );
    }

    public function test_fetch_channels_returns_grouped_channels(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->prepare_nonce();
        $_POST['token'] = 'test-token';

        $responses = [
            [
                'data' => [
                    'account' => [
                        'id'            => 'acct-1',
                        'name'          => 'Acme',
                        'organizations' => [
                            [ 'id' => 'org-1', 'name' => 'Acme Org' ],
                        ],
                    ],
                ],
            ],
            [
                'data' => [
                    'channels' => [
                        [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
                    ],
                ],
            ],
        ];

        add_filter(
            'pre_http_request',
            static function () use ( &$responses ): array {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( array_shift( $responses ) ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );

        $this->_handleAjax( 'lightweb2_fetch_channels' );
        $response = json_decode( $this->_last_response, true );

        $this->assertTrue( $response['success'] );
        $this->assertSame( 'org-1', $response['data']['organizations'][0]['id'] );
        $this->assertSame( 'Company LinkedIn', $response['data']['organizations'][0]['channels'][0]['name'] );
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

```bash
composer test -- --filter 'Test_Settings|Test_Admin_Ajax'
```

Expected: FAIL because the AJAX actions and channel ID resolution do not exist.

- [ ] **Step 4: Update settings channel sanitization**

Replace `sanitize_channels()` in `includes/class-settings.php` with:

```php
    public function sanitize_channels( $input, string $token = '' ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }

        $catalog = [];
        $cache   = '' !== $token ? get_transient( self::catalog_key( $token ) ) : false;

        $current_channels = $this->get()['channels'];

        if ( is_array( $current_channels ) ) {
            foreach ( $current_channels as $existing ) {
                if ( isset( $existing['id'] ) ) {
                    $catalog[ (string) $existing['id'] ] = $existing;
                }
            }
        }

        if ( is_array( $cache ) ) {
            foreach ( $cache as $organization ) {
                foreach ( $organization['channels'] ?? [] as $channel ) {
                    if ( isset( $channel['id'] ) ) {
                        $catalog[ (string) $channel['id'] ] = $channel;
                    }
                }
            }
        }

        $channels = [];
        $seen     = [];

        foreach ( $input as $channel ) {
            if ( is_string( $channel ) ) {
                $id = substr( sanitize_text_field( $channel ), 0, 64 );
                $channel = $catalog[ $id ] ?? [ 'id' => $id, 'name' => '', 'service' => '' ];
            }

            if ( ! is_array( $channel ) ) {
                continue;
            }

            $id = isset( $channel['id'] )
                ? substr( sanitize_text_field( (string) $channel['id'] ), 0, 64 )
                : '';

            if ( '' === $id || isset( $seen[ $id ] ) ) {
                continue;
            }

            $seen[ $id ] = true;
            $channels[]  = [
                'id'      => $id,
                'name'    => isset( $channel['name'] )
                    ? substr( sanitize_text_field( (string) $channel['name'] ), 0, 191 )
                    : '',
                'service' => isset( $channel['service'] )
                    ? substr( sanitize_key( (string) $channel['service'] ), 0, 32 )
                    : '',
            ];
        }

        return $channels;
    }
```

Update the call inside `sanitize()`:

```php
$channels = $this->sanitize_channels( $input['channels'] ?? [], (string) $current['token'] );
```

- [ ] **Step 5: Add AJAX endpoints and localize the admin script**

Update `includes/class-admin.php`:

```php
private Buffer_Client $client;

public function __construct( Settings $settings, Buffer_Client $client ) {
    $this->settings = $settings;
    $this->client   = $client;
}

public function register(): void {
    add_action( 'admin_menu', [ $this, 'register_menu' ] );
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    add_action( 'wp_ajax_lightweb2_test_connection', [ $this, 'ajax_test_connection' ] );
    add_action( 'wp_ajax_lightweb2_fetch_channels', [ $this, 'ajax_fetch_channels' ] );
    add_action( 'wp_ajax_lightweb2_disconnect', [ $this, 'ajax_disconnect' ] );
}

private function verify_ajax(): void {
    check_ajax_referer( 'lightweb2_admin', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error(
            [ 'message' => __( 'You are not allowed to manage Buffer settings.', 'lightweb2-publish-with-buffer' ) ],
            403
        );
    }
}

private function request_token(): string {
    $token = isset( $_POST['token'] )
        ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) )
        : '';

    if ( '' === $token ) {
        $token = (string) $this->settings->get()['token'];
    }

    return $token;
}

public function ajax_test_connection(): void {
    $this->verify_ajax();
    $token = $this->request_token();

    if ( '' === $token ) {
        wp_send_json_error(
            [ 'message' => __( 'Add a Buffer token first.', 'lightweb2-publish-with-buffer' ) ],
            400
        );
    }

    $result = $this->client->test_connection( $token );

    if ( ! $result['success'] ) {
        wp_send_json_error( [ 'message' => $result['message'] ], 400 );
    }

    $account = $result['data']['account'] ?? [];

    wp_send_json_success(
        [
            'account'       => sanitize_text_field( (string) ( $account['name'] ?? '' ) ),
            'organizations' => count( $account['organizations'] ?? [] ),
        ]
    );
}

public function ajax_fetch_channels(): void {
    $this->verify_ajax();
    $token = $this->request_token();

    if ( '' === $token ) {
        wp_send_json_error(
            [ 'message' => __( 'Add a Buffer token first.', 'lightweb2-publish-with-buffer' ) ],
            400
        );
    }

    $account = $this->client->test_connection( $token );

    if ( ! $account['success'] ) {
        wp_send_json_error( [ 'message' => $account['message'] ], 400 );
    }

    $catalog = [];

    foreach ( $account['data']['account']['organizations'] ?? [] as $organization ) {
        $organization_id = sanitize_text_field( (string) ( $organization['id'] ?? '' ) );

        if ( '' === $organization_id ) {
            continue;
        }

        $channels = $this->client->get_channels( $token, $organization_id );

        if ( ! $channels['success'] ) {
            wp_send_json_error( [ 'message' => $channels['message'] ], 400 );
        }

        $normalized = [];

        foreach ( $channels['data']['channels'] ?? [] as $channel ) {
            $normalized[] = [
                'id'      => sanitize_text_field( (string) ( $channel['id'] ?? '' ) ),
                'name'    => sanitize_text_field( (string) ( $channel['name'] ?? '' ) ),
                'service' => sanitize_key( (string) ( $channel['service'] ?? '' ) ),
            ];
        }

        $catalog[] = [
            'id'       => $organization_id,
            'name'     => sanitize_text_field( (string) ( $organization['name'] ?? '' ) ),
            'channels' => $normalized,
        ];
    }

    set_transient( Settings::catalog_key( $token ), $catalog, 12 * HOUR_IN_SECONDS );

    wp_send_json_success( [ 'organizations' => $catalog ] );
}

public function ajax_disconnect(): void {
    $this->verify_ajax();

    $settings = $this->settings->get();
    delete_transient( Settings::catalog_key( (string) $settings['token'] ) );

    // Clear first so sanitize() does not preserve the existing token.
    delete_option( Settings::OPTION );

    update_option(
        Settings::OPTION,
        [
            'token'    => '',
            'channels' => [],
        ] + $settings,
        false
    );

    wp_send_json_success();
}
```

Update `enqueue_assets()` to localize data:

```php
$settings = $this->settings->get();

wp_localize_script(
    'lightweb2-admin',
    'lightweb2Admin',
    [
        'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
        'nonce'            => wp_create_nonce( 'lightweb2_admin' ),
        'selectedChannels' => array_values(
            array_map(
                static fn( array $channel ): string => (string) $channel['id'],
                $settings['channels']
            )
        ),
        'strings'          => [
            'testing'            => __( 'Testing connection…', 'lightweb2-publish-with-buffer' ),
            'loading'            => __( 'Loading channels…', 'lightweb2-publish-with-buffer' ),
            'connected'          => __( 'Connected to Buffer as %s.', 'lightweb2-publish-with-buffer' ),
            'noChannels'         => __( 'No channels found in this Buffer account.', 'lightweb2-publish-with-buffer' ),
            'error'              => __( 'Could not reach Buffer. Check the token and try again.', 'lightweb2-publish-with-buffer' ),
            'confirmDisconnect'  => __( 'Disconnect Buffer and clear the selected channels?', 'lightweb2-publish-with-buffer' ),
            'confirmClearLogs'   => __( 'Delete all delivery history? This cannot be undone.', 'lightweb2-publish-with-buffer' ),
        ],
    ]
);
```

Add the connection buttons to the settings page connection card:

```html
<p class="lightweb2-actions">
    <button type="button" class="button" id="lightweb2-test"><?php esc_html_e( 'Test connection', 'lightweb2-publish-with-buffer' ); ?></button>
    <button type="button" class="button" id="lightweb2-load-channels"><?php esc_html_e( 'Load channels', 'lightweb2-publish-with-buffer' ); ?></button>
    <button type="button" class="button-link-delete" id="lightweb2-disconnect"><?php esc_html_e( 'Disconnect', 'lightweb2-publish-with-buffer' ); ?></button>
</p>
<p class="lightweb2-ajax-status" id="lightweb2-ajax-status" role="status" aria-live="polite"></p>
```

Update `includes/class-plugin.php` to create the client and pass it to `Admin`:

```php
private Buffer_Client $client;
private Admin $admin;

public function boot(): void {
    $this->settings = new Settings();
    $this->client   = new Buffer_Client();
    $this->admin    = new Admin( $this->settings, $this->client );

    $this->settings->register();
    $this->admin->register();
}
```

Update the `Admin` construction in `tests/test-admin.php`:

```php
$admin = new Admin( new Settings(), new Buffer_Client() );
```

Add the require in `lightweb2-publish-with-buffer.php` before `class-plugin.php`:

```php
require_once LIGHTWEB2_DIR . 'includes/class-buffer-client.php';
```

- [ ] **Step 6: Implement the admin JavaScript**

`assets/admin.js`:

```js
(() => {
  const cfg = window.lightweb2Admin;

  if (!cfg) {
    return;
  }

  const byId = (id) => document.getElementById(id);
  const tokenInput = byId('lightweb2-token');
  const channelsBox = byId('lightweb2-channels');
  const statusBox = byId('lightweb2-connection-status');
  const ajaxStatus = byId('lightweb2-ajax-status');
  const template = byId('lightweb2-template');
  const preview = byId('lightweb2-preview');
  const selected = new Set(cfg.selectedChannels || []);

  const setStatus = (message, connected = false) => {
    if (statusBox) {
      statusBox.textContent = message;
      statusBox.classList.toggle('is-connected', connected);
    }
  };

  const setAjaxStatus = (message, isError = false) => {
    if (ajaxStatus) {
      ajaxStatus.textContent = message;
      ajaxStatus.classList.toggle('is-error', isError);
    }
  };

  const request = async (action, extra = {}) => {
    const body = new FormData();
    body.append('action', action);
    body.append('nonce', cfg.nonce);
    body.append('token', tokenInput ? tokenInput.value : '');

    Object.entries(extra).forEach(([key, value]) => body.append(key, value));

    const response = await fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body,
    });
    const json = await response.json();

    if (!json.success) {
      throw new Error(json?.data?.message || cfg.strings.error);
    }

    return json.data;
  };

  const renderChannels = (organizations) => {
    if (!channelsBox) {
      return;
    }

    channelsBox.innerHTML = '';

    if (!organizations.length) {
      const empty = document.createElement('p');
      empty.className = 'description';
      empty.textContent = cfg.strings.noChannels;
      channelsBox.appendChild(empty);
      return;
    }

    organizations.forEach((organization) => {
      const group = document.createElement('fieldset');
      group.className = 'lightweb2-channel-group';

      const legend = document.createElement('legend');
      legend.textContent = organization.name || organization.id;
      group.appendChild(legend);

      (organization.channels || []).forEach((channel) => {
        const label = document.createElement('label');
        label.className = 'lightweb2-channel';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'lightweb2_settings[channels][]';
        checkbox.value = channel.id;
        checkbox.checked = selected.has(channel.id);
        label.appendChild(checkbox);

        const name = document.createElement('span');
        name.textContent = channel.name || channel.id;
        label.appendChild(name);

        const service = document.createElement('small');
        service.textContent = channel.service || '';
        label.appendChild(service);

        group.appendChild(label);
      });

      channelsBox.appendChild(group);
    });
  };

  byId('lightweb2-test')?.addEventListener('click', async () => {
    setAjaxStatus(cfg.strings.testing);

    try {
      const data = await request('lightweb2_test_connection');
      const message = cfg.strings.connected.replace('%s', data.account || 'Buffer');
      setStatus(message, true);
      setAjaxStatus(message);
    } catch (error) {
      setStatus(cfg.strings.error, false);
      setAjaxStatus(error.message, true);
    }
  });

  byId('lightweb2-load-channels')?.addEventListener('click', async () => {
    setAjaxStatus(cfg.strings.loading);

    try {
      const data = await request('lightweb2_fetch_channels');
      renderChannels(data.organizations || []);
      setAjaxStatus('');
    } catch (error) {
      setAjaxStatus(error.message, true);
    }
  });

  byId('lightweb2-disconnect')?.addEventListener('click', async () => {
    if (!window.confirm(cfg.strings.confirmDisconnect)) {
      return;
    }

    try {
      await request('lightweb2_disconnect');
      window.location.reload();
    } catch (error) {
      setAjaxStatus(error.message, true);
    }
  });

  const updatePreview = () => {
    if (!template || !preview) {
      return;
    }

    const sample = {
      '{title}': 'A clearer way to publish',
      '{url}': 'https://example.com/article',
      '{excerpt}': 'A short summary of the article.',
      '{author}': 'LightWeb2',
      '{category}': 'Product',
      '{tags}': '#WordPress #Buffer',
    };

    preview.textContent = template.value.replace(
      /{[a-z]+}/g,
      (match) => sample[match] || match
    );
  };

  template?.addEventListener('input', updatePreview);
  updatePreview();

  if (channelsBox) {
    channelsBox.addEventListener('change', (event) => {
      if (event.target instanceof HTMLInputElement && event.target.type === 'checkbox') {
        if (event.target.checked) {
          selected.add(event.target.value);
        } else {
          selected.delete(event.target.value);
        }
      }
    });
  }
})();
```

Add the following styles to `assets/admin.css`:

```css
.lightweb2-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 14px 0 0;
}

.lightweb2-ajax-status {
  min-height: 24px;
  margin: 10px 0 0;
  color: var(--lightweb2-muted);
}

.lightweb2-ajax-status.is-error {
  color: #b91c1c;
}

.lightweb2-channel-group {
  grid-column: 1 / -1;
  margin: 0 0 12px;
  padding: 0;
  border: 0;
}

.lightweb2-channel-group legend {
  margin-bottom: 8px;
  font-weight: 600;
}

.lightweb2-channel-group .lightweb2-channel {
  margin-bottom: 6px;
}
```

- [ ] **Step 7: Run the settings and AJAX tests**

```bash
composer test -- --filter 'Test_Settings|Test_Admin_Ajax|Test_Admin'
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add includes/class-settings.php includes/class-admin.php includes/class-plugin.php assets/admin.js tests
git commit -m "feat: add connection testing and channel picker"
```

---

## Task 6: Message Builder

**Files:**
- Create: `includes/class-message-builder.php`
- Create: `tests/test-message-builder.php`

**Interfaces:**
- Produces: `Message_Builder::build( int $post_id, string $template ): string`
- Produces: `Message_Builder::image_url( int $post_id ): ?string`

- [ ] **Step 1: Write the failing message tests**

`tests/test-message-builder.php`:

```php
<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Message_Builder;

final class Test_Message_Builder extends WP_UnitTestCase {

    private Message_Builder $builder;

    public function set_up(): void {
        parent::set_up();
        $this->builder = new Message_Builder();
    }

    public function test_all_template_variables_are_replaced(): void {
        $user_id = self::factory()->user->create(
            [
                'display_name' => 'Ada Lovelace',
                'role'         => 'author',
            ]
        );
        $category_id = self::factory()->category->create( [ 'name' => 'Engineering' ] );
        $post_id     = self::factory()->post->create(
            [
                'post_author'   => $user_id,
                'post_title'    => 'A &amp; B',
                'post_content'  => 'This is the content.',
                'post_excerpt'  => 'A short excerpt.',
                'post_category' => [ $category_id ],
            ]
        );
        wp_set_post_tags( $post_id, [ 'WordPress News', 'Buffer' ] );

        $message = $this->builder->build(
            $post_id,
            "{title}\n{excerpt}\n{author}\n{category}\n{tags}\n{url}"
        );

        $this->assertStringContainsString( 'A & B', $message );
        $this->assertStringContainsString( 'A short excerpt.', $message );
        $this->assertStringContainsString( 'Ada Lovelace', $message );
        $this->assertStringContainsString( 'Engineering', $message );
        $this->assertStringContainsString( '#WordPressNews', $message );
        $this->assertStringContainsString( '#Buffer', $message );
        $this->assertStringContainsString( get_permalink( $post_id ), $message );
    }

    public function test_excerpt_falls_back_to_trimmed_content(): void {
        $post_id = self::factory()->post->create(
            [
                'post_title'   => 'Fallback',
                'post_content' => '<p>' . str_repeat( 'word ', 40 ) . '</p>',
                'post_excerpt' => '',
            ]
        );

        $message = $this->builder->build( $post_id, '{excerpt}' );

        $this->assertStringNotContainsString( '<p>', $message );
        $this->assertStringContainsString( 'word', $message );
        $this->assertLessThan( 200, strlen( $message ) );
    }

    public function test_unicode_tags_become_hashtags(): void
    {
        $post_id = self::factory()->post->create( [ 'post_title' => 'Arabic' ] );
        wp_set_post_tags( $post_id, [ 'تقنية', 'ويب ٢' ] );

        $message = $this->builder->build( $post_id, '{tags}' );

        $this->assertStringContainsString( '#تقنية', $message );
        $this->assertStringContainsString( '#ويب٢', $message );
    }

    public function test_plain_text_fields_are_decoded_and_stripped(): void
    {
        $user_id = self::factory()->user->create(
            [
                'display_name' => 'Ada &amp; Co',
                'role'         => 'author',
            ]
        );
        $category_id = self::factory()->category->create( [ 'name' => 'Research &amp; Development' ] );
        $post_id     = self::factory()->post->create(
            [
                'post_author'   => $user_id,
                'post_title'    => 'Launch &lt;em&gt;now&lt;/em&gt;',
                'post_excerpt'  => '<p>Hello &amp; welcome</p>',
                'post_category' => [ $category_id ],
            ]
        );
        wp_set_post_tags( $post_id, [ 'R&D' ] );

        $message = $this->builder->build(
            $post_id,
            "{title}\n{excerpt}\n{author}\n{category}\n{tags}"
        );

        $this->assertStringNotContainsString( '<em>', $message );
        $this->assertStringNotContainsString( '<p>', $message );
        $this->assertStringContainsString( 'Launch now', $message );
        $this->assertStringContainsString( 'Hello & welcome', $message );
        $this->assertStringContainsString( 'Ada & Co', $message );
        $this->assertStringContainsString( 'Research & Development', $message );
        $this->assertStringContainsString( '#RD', $message );
        $this->assertStringNotContainsString( '#RampD', $message );
    }

    public function test_excerpt_fallback_strips_scripts_and_keeps_block_spacing(): void
    {
        $post_id = self::factory()->post->create(
            [
                'post_title'   => 'Blocks',
                'post_content' => '<p>First</p><script>alert(1)</script><p>Second</p>',
                'post_excerpt' => '',
            ]
        );

        $message = $this->builder->build( $post_id, '{excerpt}' );

        $this->assertStringContainsString( 'First Second', $message );
        $this->assertStringNotContainsString( 'alert(1)', $message );
        $this->assertStringNotContainsString( '<script>', $message );
    }

    public function test_positive_thumbnail_returns_image_url(): void
    {
        $post_id = self::factory()->post->create();
        $attachment_id = self::factory()->attachment->create(
            [
                'post_mime_type' => 'image/jpeg',
                'post_parent'    => $post_id,
            ]
        );
        set_post_thumbnail( $post_id, $attachment_id );

        add_filter(
            'post_thumbnail_url',
            static function (): string {
                return 'https://example.com/image.jpg';
            }
        );

        $this->assertSame(
            'https://example.com/image.jpg',
            $this->builder->image_url( $post_id )
        );
    }

    public function test_image_url_is_null_without_a_thumbnail(): void
    {
        $post_id = self::factory()->post->create();

        $this->assertNull( $this->builder->image_url( $post_id ) );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
composer test -- --filter Test_Message_Builder
```

Expected: FAIL because `Message_Builder` does not exist.

- [ ] **Step 3: Implement the builder**

`includes/class-message-builder.php`:

```php
<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Message_Builder {

    public function build( int $post_id, string $template ): string {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return '';
        }

        $title = wp_strip_all_tags(
            html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' )
        );

        if ( has_excerpt( $post_id ) ) {
            $excerpt = get_the_excerpt( $post_id );
        } else {
            $content = html_entity_decode(
                strip_shortcodes( (string) $post->post_content ),
                ENT_QUOTES,
                'UTF-8'
            );
            $content = preg_replace(
                '/<\/(p|div|h[1-6]|li|blockquote|tr)>/i',
                '$0 ',
                $content
            );
            $excerpt = wp_trim_words( wp_strip_all_tags( $content ), 20 );
        }

        $excerpt = wp_strip_all_tags(
            html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' )
        );
        $author  = wp_strip_all_tags(
            html_entity_decode(
                (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
                ENT_QUOTES,
                'UTF-8'
            )
        );
        $categories = get_the_category( $post_id );
        $category   = ! empty( $categories )
            ? wp_strip_all_tags(
                html_entity_decode( $categories[0]->name, ENT_QUOTES, 'UTF-8' )
            )
            : '';

        $hashtags = [];

        foreach ( get_the_tags( $post_id ) ?: [] as $tag ) {
            $clean = preg_replace(
                '/[^\p{L}\p{N}_]/u',
                '',
                html_entity_decode( (string) $tag->name, ENT_QUOTES, 'UTF-8' )
            );

            if ( is_string( $clean ) && '' !== $clean ) {
                $hashtags[] = '#' . $clean;
            }
        }

        $replacements = [
            '{title}'    => $title,
            '{url}'      => (string) get_permalink( $post_id ),
            '{excerpt}'  => $excerpt,
            '{author}'   => (string) $author,
            '{category}' => (string) $category,
            '{tags}'     => implode( ' ', $hashtags ),
        ];

        $message = strtr( $template, $replacements );
        $message = preg_replace( "/\n{3,}/", "\n\n", $message );

        return trim( is_string( $message ) ? $message : '' );
    }

    public function image_url( int $post_id ): ?string {
        if ( ! has_post_thumbnail( $post_id ) ) {
            return null;
        }

        $url = get_the_post_thumbnail_url( $post_id, 'full' );

        return is_string( $url ) && '' !== $url ? $url : null;
    }
}
```

Add the require in `lightweb2-publish-with-buffer.php`:

```php
require_once LIGHTWEB2_DIR . 'includes/class-message-builder.php';
```

- [ ] **Step 4: Run the message tests**

```bash
composer test -- --filter Test_Message_Builder
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-message-builder.php tests/test-message-builder.php lightweb2-publish-with-buffer.php
git commit -m "feat: add message template builder"
```

---

## Task 7: Deliveries Repository

**Files:**
- Create: `includes/class-deliveries.php`
- Create: `tests/test-deliveries.php`

**Interfaces:**
- Produces: `Deliveries::create_or_get( int $post_id, array $channel, string $mode ): array`
- Produces: `Deliveries::get( int $id ): ?array`
- Produces: `Deliveries::get_by_post_channel( int $post_id, string $channel_id ): ?array`
- Produces: `Deliveries::get_for_post( int $post_id ): array`
- Produces: `Deliveries::increment_attempts( int $id ): int`
- Produces: `Deliveries::mark_sent( int $id, string $buffer_post_id ): void`
- Produces: `Deliveries::mark_failed( int $id, string $code, string $message ): void`
- Produces: `Deliveries::mark_canceled( int $id ): void`
- Produces: `Deliveries::get_recent( array $args = [] ): array`
- Produces: `Deliveries::count_recent( array $args = [] ): int`
- Produces: `Deliveries::clear(): int`
- Produces: `Deliveries::delete_older_than_days( int $days ): int`

- [ ] **Step 1: Write the failing delivery tests**

`tests/test-deliveries.php`:

```php
<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Deliveries;
use LightWeb2\PublishWithBuffer\Installer;

final class Test_Deliveries extends WP_UnitTestCase {

    private Deliveries $deliveries;

    public function set_up(): void {
        parent::set_up();
        Installer::create_table();
        $this->deliveries = new Deliveries();
        $this->deliveries->clear();
    }

    private function channel( string $id = 'channel-1' ): array {
        return [
            'id'      => $id,
            'name'    => 'Company LinkedIn',
            'service' => 'linkedin',
        ];
    }

    public function test_create_or_get_prevents_duplicates(): void {
        $first  = $this->deliveries->create_or_get( 10, $this->channel(), 'addToQueue' );
        $second = $this->deliveries->create_or_get( 10, $this->channel(), 'shareNow' );

        $this->assertSame( $first['id'], $second['id'] );
        $this->assertSame( 'pending', $first['status'] );
        $this->assertSame( 'Company LinkedIn', $first['channel_name'] );
    }

    public function test_status_transitions_and_attempts(): void {
        $row = $this->deliveries->create_or_get( 11, $this->channel(), 'addToQueue' );

        $this->assertSame( 1, $this->deliveries->increment_attempts( (int) $row['id'] ) );
        $this->deliveries->mark_failed( (int) $row['id'], 'server_error', 'Try later' );

        $failed = $this->deliveries->get( (int) $row['id'] );
        $this->assertSame( 'failed', $failed['status'] );
        $this->assertSame( 'server_error', $failed['error_code'] );

        $this->deliveries->mark_sent( (int) $row['id'], 'buffer-1' );

        $sent = $this->deliveries->get( (int) $row['id'] );
        $this->assertSame( 'sent', $sent['status'] );
        $this->assertSame( 'buffer-1', $sent['buffer_post_id'] );
    }

    public function test_recent_rows_can_be_filtered_by_status(): void {
        $sent   = $this->deliveries->create_or_get( 12, $this->channel( 'c1' ), 'addToQueue' );
        $failed = $this->deliveries->create_or_get( 12, $this->channel( 'c2' ), 'addToQueue' );

        $this->deliveries->mark_sent( (int) $sent['id'], 'buffer-1' );
        $this->deliveries->mark_failed( (int) $failed['id'], 'server_error', 'Try later' );

        $rows = $this->deliveries->get_recent( [ 'status' => 'failed' ] );

        $this->assertCount( 1, $rows );
        $this->assertSame( 'failed', $rows[0]['status'] );
        $this->assertSame( 1, $this->deliveries->count_recent( [ 'status' => 'failed' ] ) );
    }

    public function test_clear_and_retention_cleanup(): void {
        $this->deliveries->create_or_get( 13, $this->channel(), 'addToQueue' );
        $this->assertSame( 1, $this->deliveries->count_recent() );

        $this->assertSame( 0, $this->deliveries->delete_older_than_days( 30 ) );
        $this->assertSame( 1, $this->deliveries->clear() );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
composer test -- --filter Test_Deliveries
```

Expected: FAIL because `Deliveries` does not exist.

- [ ] **Step 3: Implement the repository**

`includes/class-deliveries.php`:

```php
<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Deliveries {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'lightweb2_deliveries';
    }

    public function create_or_get( int $post_id, array $channel, string $mode ): array {
        global $wpdb;

        $existing = $this->get_by_post_channel( $post_id, (string) $channel['id'] );

        if ( null !== $existing ) {
            return $existing;
        }

        $now = current_time( 'mysql', true );

        $inserted = $wpdb->insert(
            $this->table,
            [
                'post_id'      => $post_id,
                'channel_id'   => (string) $channel['id'],
                'channel_name' => (string) ( $channel['name'] ?? '' ),
                'service'      => (string) ( $channel['service'] ?? '' ),
                'mode'         => $mode,
                'status'       => 'pending',
                'attempts'     => 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return $this->get_by_post_channel( $post_id, (string) $channel['id'] ) ?? [];
        }

        $row = $this->get( (int) $wpdb->insert_id );

        return $row ?? [];
    }

    public function get( int $id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    public function get_by_post_channel( int $post_id, string $channel_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE post_id = %d AND channel_id = %s",
                $post_id,
                $channel_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    public function get_for_post( int $post_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE post_id = %d ORDER BY id ASC",
                $post_id
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    public function increment_attempts( int $id ): int {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET attempts = attempts + 1, updated_at = %s
                 WHERE id = %d",
                current_time( 'mysql', true ),
                $id
            )
        );

        $row = $this->get( $id );

        return $row ? (int) $row['attempts'] : 0;
    }

    public function mark_sent( int $id, string $buffer_post_id ): void {
        $this->update(
            $id,
            [
                'status'         => 'sent',
                'buffer_post_id' => $buffer_post_id,
                'error_code'     => null,
                'error_message'  => null,
            ]
        );
    }

    public function mark_failed( int $id, string $code, string $message ): void {
        $this->update(
            $id,
            [
                'status'        => 'failed',
                'error_code'    => $code,
                'error_message' => wp_html_excerpt( $message, 1000, '' ),
            ]
        );
    }

    public function mark_canceled( int $id ): void {
        $this->update( $id, [ 'status' => 'canceled' ] );
    }

    public function get_recent( array $args = [] ): array {
        global $wpdb;

        [ $where, $params ] = $this->build_where( $args );
        $per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;
        $params[] = $per_page;
        $params[] = $offset;

        $sql = "SELECT * FROM {$this->table} {$where}
                ORDER BY created_at DESC, id DESC
                LIMIT %d OFFSET %d";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    public function count_recent( array $args = [] ): int {
        global $wpdb;

        [ $where, $params ] = $this->build_where( $args );
        $sql = "SELECT COUNT(*) FROM {$this->table} {$where}";

        if ( empty( $params ) ) {
            return (int) $wpdb->get_var( $sql );
        }

        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    }

    public function clear(): int {
        global $wpdb;

        return (int) $wpdb->query( "DELETE FROM {$this->table}" );
    }

    public function delete_older_than_days( int $days ): int {
        global $wpdb;

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table} WHERE created_at < %s",
                $cutoff
            )
        );
    }

    private function update( int $id, array $data ): void {
        global $wpdb;

        $data['updated_at'] = current_time( 'mysql', true );
        $wpdb->update( $this->table, $data, [ 'id' => $id ] );
    }

    private function build_where( array $args ): array {
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = (string) $args['status'];
        }

        if ( ! empty( $args['channel_id'] ) ) {
            $where[]  = 'channel_id = %s';
            $params[] = (string) $args['channel_id'];
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'created_at >= %s';
            $params[] = (string) $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'created_at <= %s';
            $params[] = (string) $args['date_to'];
        }

        return [ 'WHERE ' . implode( ' AND ', $where ), $params ];
    }
}
```

Add the require in `lightweb2-publish-with-buffer.php`:

```php
require_once LIGHTWEB2_DIR . 'includes/class-deliveries.php';
```

WordPress PHPCS flags direct queries against custom tables. Add targeted `phpcs:ignore` comments for the plugin-owned table name where the sniff requires it. Do not disable the database sniffs globally.

- [ ] **Step 4: Run the delivery tests**

```bash
composer test -- --filter Test_Deliveries
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-deliveries.php tests/test-deliveries.php lightweb2-publish-with-buffer.php
git commit -m "feat: add delivery repository with idempotency"
```

---

## Task 8: Per-Post Meta Box

**Files:**
- Create: `includes/class-meta-box.php`
- Create: `tests/test-meta-box.php`
- Modify: `includes/class-plugin.php`
- Modify: `lightweb2-publish-with-buffer.php`
- Modify: `includes/class-admin.php`
- Modify: `assets/admin.css`
- Modify: `tests/test-admin.php`

**Interfaces:**
- Consumes: `Settings::get()`, `Deliveries::get_for_post()`
- Produces: `Meta_Box::__construct( Settings $settings, Deliveries $deliveries )`
- Produces: `Meta_Box::register(): void`
- Produces: `Meta_Box::render( WP_Post $post ): void`
- Produces: `Meta_Box::save( int $post_id ): void`

- [ ] **Step 1: Write the failing meta box tests**

`tests/test-meta-box.php`:

```php
<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Deliveries;
use LightWeb2\PublishWithBuffer\Installer;
use LightWeb2\PublishWithBuffer\Meta_Box;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Meta_Box extends WP_UnitTestCase {

    private Meta_Box $meta_box;

    public function set_up(): void {
        parent::set_up();
        Installer::create_table();
        update_option( Settings::OPTION, Settings::defaults() );
        $this->meta_box = new Meta_Box( new Settings(), new Deliveries() );
    }

    public function tear_down(): void {
        unset(
            $_POST['lightweb2_meta_nonce'],
            $_POST['lightweb2_enabled'],
            $_POST['lightweb2_mode']
        );
        parent::tear_down();
    }

    public function test_admin_can_enable_sending_and_select_mode(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );
        $post_id = self::factory()->post->create( [ 'post_author' => $user_id ] );

        $_POST['lightweb2_meta_nonce'] = wp_create_nonce( 'lightweb2_meta' );
        $_POST['lightweb2_enabled']    = 'yes';
        $_POST['lightweb2_mode']       = 'shareNow';

        $this->meta_box->save( $post_id );

        $this->assertSame( 'yes', get_post_meta( $post_id, '_lightweb2_enabled', true ) );
        $this->assertSame( 'shareNow', get_post_meta( $post_id, '_lightweb2_mode', true ) );
    }

    public function test_invalid_mode_falls_back_to_add_to_queue(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );
        $post_id = self::factory()->post->create( [ 'post_author' => $user_id ] );

        $_POST['lightweb2_meta_nonce'] = wp_create_nonce( 'lightweb2_meta' );
        $_POST['lightweb2_enabled']    = 'yes';
        $_POST['lightweb2_mode']       = 'invalid';

        $this->meta_box->save( $post_id );

        $this->assertSame( 'addToQueue', get_post_meta( $post_id, '_lightweb2_mode', true ) );
    }

    public function test_subscriber_cannot_change_meta(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $user_id );
        $post_id = self::factory()->post->create();

        $_POST['lightweb2_meta_nonce'] = wp_create_nonce( 'lightweb2_meta' );
        $_POST['lightweb2_enabled']    = 'yes';

        $this->meta_box->save( $post_id );

        $this->assertSame( '', get_post_meta( $post_id, '_lightweb2_enabled', true ) );
    }

    public function test_page_cannot_change_post_meta(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );
        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_author' => $user_id,
            ]
        );

        $_POST['lightweb2_meta_nonce'] = wp_create_nonce( 'lightweb2_meta' );
        $_POST['lightweb2_enabled']    = 'yes';

        $this->meta_box->save( $page_id );

        $this->assertSame( '', get_post_meta( $page_id, '_lightweb2_enabled', true ) );
    }

    public function test_missing_nonce_does_not_change_meta(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );
        $post_id = self::factory()->post->create( [ 'post_author' => $user_id ] );

        $_POST['lightweb2_enabled'] = 'yes';

        $this->meta_box->save( $post_id );

        $this->assertSame( '', get_post_meta( $post_id, '_lightweb2_enabled', true ) );
    }
}
```

Add this test to `tests/test-admin.php` to cover the post-editor stylesheet:

```php
    public function test_post_editor_enqueues_admin_style_only(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $admin = new Admin( new Settings(), new Buffer_Client() );
        $admin->enqueue_assets( 'post.php' );

        $this->assertTrue( wp_style_is( 'lightweb2-admin', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'lightweb2-admin', 'enqueued' ) );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
composer test -- --filter Test_Meta_Box
```

Expected: FAIL because `Meta_Box` does not exist.

- [ ] **Step 3: Implement the meta box and wiring**

`includes/class-meta-box.php`:

```php
<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Meta_Box {

    private Settings $settings;
    private Deliveries $deliveries;

    public function __construct( Settings $settings, Deliveries $deliveries ) {
        $this->settings   = $settings;
        $this->deliveries = $deliveries;
    }

    public function register(): void {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post', [ $this, 'save' ] );
    }

    public function add_meta_box(): void {
        add_meta_box(
            'lightweb2-meta-box',
            __( 'LightWeb2', 'lightweb2-publish-with-buffer' ),
            [ $this, 'render' ],
            'post',
            'side',
            'high'
        );
    }

    public function render( \WP_Post $post ): void {
        $settings = $this->settings->get();
        $enabled  = get_post_meta( $post->ID, '_lightweb2_enabled', true );
        $mode     = get_post_meta( $post->ID, '_lightweb2_mode', true );
        $mode     = in_array( $mode, [ 'shareNow', 'addToQueue' ], true )
            ? $mode
            : (string) $settings['default_mode'];
        $rows     = $this->deliveries->get_for_post( (int) $post->ID );

        wp_nonce_field( 'lightweb2_meta', 'lightweb2_meta_nonce' );
        ?>
        <?php if ( '' === (string) $settings['token'] ) : ?>
            <p>
                <?php esc_html_e( 'Buffer is not connected.', 'lightweb2-publish-with-buffer' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lightweb2' ) ); ?>">
                    <?php esc_html_e( 'Open settings', 'lightweb2-publish-with-buffer' ); ?>
                </a>
            </p>
        <?php endif; ?>

        <p>
            <label>
                <input type="checkbox" name="lightweb2_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?> />
                <?php esc_html_e( 'Send to Buffer on publish', 'lightweb2-publish-with-buffer' ); ?>
            </label>
        </p>

        <p>
            <label for="lightweb2-mode-field"><?php esc_html_e( 'Publishing mode', 'lightweb2-publish-with-buffer' ); ?></label>
            <select name="lightweb2_mode" id="lightweb2-mode-field" class="widefat">
                <option value="addToQueue" <?php selected( $mode, 'addToQueue' ); ?>><?php esc_html_e( 'Add to Queue', 'lightweb2-publish-with-buffer' ); ?></option>
                <option value="shareNow" <?php selected( $mode, 'shareNow' ); ?>><?php esc_html_e( 'Share Now', 'lightweb2-publish-with-buffer' ); ?></option>
            </select>
        </p>

        <?php if ( ! empty( $rows ) ) : ?>
            <p><strong><?php esc_html_e( 'Delivery status', 'lightweb2-publish-with-buffer' ); ?></strong></p>
            <ul class="lightweb2-delivery-list">
                <?php foreach ( $rows as $row ) : ?>
                    <li class="lightweb2-delivery is-<?php echo esc_attr( $row['status'] ); ?>">
                        <span><?php echo esc_html( $row['channel_name'] ?: $row['channel_id'] ); ?></span>
                        <strong><?php echo esc_html( ucfirst( $row['status'] ) ); ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php
    }

    public function save( int $post_id ): void {
        if ( ! isset( $_POST['lightweb2_meta_nonce'] )
            || ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( (string) $_POST['lightweb2_meta_nonce'] ) ),
                'lightweb2_meta'
            )
        ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( 'post' !== get_post_type( $post_id ) ) {
            return;
        }

        $enabled = isset( $_POST['lightweb2_enabled'] ) ? 'yes' : 'no';
        $mode    = isset( $_POST['lightweb2_mode'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['lightweb2_mode'] ) )
            : 'addToQueue';

        if ( ! in_array( $mode, [ 'shareNow', 'addToQueue' ], true ) ) {
            $mode = 'addToQueue';
        }

        update_post_meta( $post_id, '_lightweb2_enabled', $enabled );
        update_post_meta( $post_id, '_lightweb2_mode', $mode );
    }
}
```

Update `includes/class-plugin.php` to create `Deliveries` and `Meta_Box`:

```php
private Deliveries $deliveries;
private Meta_Box $meta_box;

public function boot(): void {
    $this->settings   = new Settings();
    $this->client     = new Buffer_Client();
    $this->deliveries = new Deliveries();
    $this->admin      = new Admin( $this->settings, $this->client );
    $this->meta_box   = new Meta_Box( $this->settings, $this->deliveries );

    $this->settings->register();
    $this->admin->register();
    $this->meta_box->register();
}
```

Add the require in `lightweb2-publish-with-buffer.php` before `class-plugin.php`:

```php
require_once LIGHTWEB2_DIR . 'includes/class-deliveries.php';
require_once LIGHTWEB2_DIR . 'includes/class-meta-box.php';
```

Add status styles to `assets/admin.css`:

```css
.lightweb2-delivery-list {
  margin: 0;
}

.lightweb2-delivery {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  padding: 6px 0;
  border-bottom: 1px solid var(--lightweb2-border);
}

.lightweb2-delivery.is-sent strong {
  color: #15803d;
}

.lightweb2-delivery.is-failed strong {
  color: #b91c1c;
}

.lightweb2-delivery.is-canceled strong {
  color: var(--lightweb2-muted);
}
```

- [ ] **Step 4: Run the meta box and installer tests**

```bash
composer test -- --filter 'Test_Meta_Box|Test_Installer'
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-meta-box.php includes/class-plugin.php lightweb2-publish-with-buffer.php assets/admin.css tests/test-meta-box.php
git commit -m "feat: add per-post Buffer controls"
```

---

## Task 9: Publishing Engine and Retries

**Files:**
- Create: `includes/class-publisher.php`
- Create: `tests/test-publisher.php`
- Modify: `includes/class-deliveries.php`
- Modify: `includes/class-installer.php`
- Modify: `includes/class-plugin.php`
- Modify: `lightweb2-publish-with-buffer.php`

**Interfaces:**
- Consumes: `Settings`, `Deliveries`, `Buffer_Client`, `Message_Builder`
- Produces: `Publisher::__construct( Settings $settings, Deliveries $deliveries, Buffer_Client $client, Message_Builder $builder )`
- Produces: `Publisher::register(): void`
- Produces: `Publisher::maybe_publish( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before ): void`
- Produces: `Publisher::deliver( int $post_id, string $channel_id, int $attempt = 1 ): void`
- Produces: `Publisher::retry( int $delivery_id ): bool`

- [ ] **Step 1: Write the failing publisher tests**

`tests/test-publisher.php`:

```php
<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Buffer_Client;
use LightWeb2\PublishWithBuffer\Deliveries;
use LightWeb2\PublishWithBuffer\Installer;
use LightWeb2\PublishWithBuffer\Message_Builder;
use LightWeb2\PublishWithBuffer\Publisher;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Publisher extends WP_UnitTestCase {

    private Deliveries $deliveries;
    private Settings $settings;
    private Publisher $publisher;

    public function set_up(): void {
        parent::set_up();
        Installer::create_table();

        $this->deliveries = new Deliveries();
        $this->deliveries->clear();
        $this->settings = new Settings();

        update_option(
            Settings::OPTION,
            [
                'token'    => 'test-token',
                'channels' => [
                    [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
                ],
            ] + Settings::defaults()
        );

        $this->publisher = new Publisher(
            $this->settings,
            $this->deliveries,
            new Buffer_Client(),
            new Message_Builder()
        );

        wp_clear_scheduled_hook( 'lightweb2_deliver_channel' );
    }

    public function tear_down(): void {
        wp_clear_scheduled_hook( 'lightweb2_deliver_channel' );
        parent::tear_down();
    }

    private function published_post( bool $enabled = true ): int {
        $post_id = self::factory()->post->create(
            [
                'post_status' => 'publish',
                'post_title'  => 'A Buffer post',
                'post_content' => 'Hello from WordPress.',
            ]
        );

        update_post_meta( $post_id, '_lightweb2_enabled', $enabled ? 'yes' : 'no' );
        update_post_meta( $post_id, '_lightweb2_mode', 'addToQueue' );

        return $post_id;
    }

    private function draft_before(): WP_Post {
        return self::factory()->post->create_and_get( [ 'post_status' => 'draft' ] );
    }

    private function mock_success(): void {
        add_filter(
            'pre_http_request',
            static function (): array {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(
                        [
                            'data' => [
                                'createPost' => [
                                    'post' => [
                                        'id'   => 'buffer-1',
                                        'text' => 'Hello',
                                    ],
                                ],
                            ],
                        ]
                    ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );
    }

    private function mock_server_error(): void {
        add_filter(
            'pre_http_request',
            static function (): array {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( [ 'errors' => [ [ 'message' => 'Outage' ] ] ] ),
                    'response' => [ 'code' => 500, 'message' => 'Server error' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );
    }

    public function test_publish_creates_delivery_and_schedules_worker(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );

        $rows = $this->deliveries->get_for_post( $post_id );

        $this->assertCount( 1, $rows );
        $this->assertSame( 'pending', $rows[0]['status'] );
        $this->assertNotFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ $post_id, 'c1', 1 ] )
        );
    }

    public function test_disabled_post_is_skipped(): void {
        $post_id = $this->published_post( false );
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );

        $this->assertCount( 0, $this->deliveries->get_for_post( $post_id ) );
    }

    public function test_category_filter_skips_other_posts(): void {
        $allowed = self::factory()->category->create( [ 'name' => 'Allowed' ] );
        update_option(
            Settings::OPTION,
            [
                'token'           => 'test-token',
                'channels'        => [
                    [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
                ],
                'category_filter' => $allowed,
            ] + Settings::defaults()
        );

        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );

        $this->assertCount( 0, $this->deliveries->get_for_post( $post_id ) );
    }

    public function test_success_marks_delivery_sent(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );
        $this->mock_success();

        $this->publisher->deliver( $post_id, 'c1', 1 );
        $row = $this->deliveries->get_by_post_channel( $post_id, 'c1' );

        $this->assertSame( 'sent', $row['status'] );
        $this->assertSame( 'buffer-1', $row['buffer_post_id'] );
    }

    public function test_temporary_failure_schedules_retry(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );
        $this->mock_server_error();

        $this->publisher->deliver( $post_id, 'c1', 1 );
        $row = $this->deliveries->get_by_post_channel( $post_id, 'c1' );

        $this->assertSame( 'failed', $row['status'] );
        $this->assertSame( 'server_error', $row['error_code'] );
        $this->assertNotFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ $post_id, 'c1', 2 ] )
        );
    }

    public function test_sent_delivery_is_not_retried(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );

        $requests = 0;

        add_filter(
            'pre_http_request',
            static function () use ( &$requests ): array {
                ++$requests;

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(
                        [
                            'data' => [
                                'createPost' => [
                                    'post' => [ 'id' => 'buffer-1', 'text' => 'Hello' ],
                                ],
                            ],
                        ]
                    ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
        );

        $this->publisher->deliver( $post_id, 'c1', 1 );
        $this->publisher->deliver( $post_id, 'c1', 2 );

        $this->assertSame( 1, $requests );
    }

    public function test_unpublished_post_is_canceled(): void {
        $post_id = $this->published_post();
        $this->publisher->maybe_publish( $post_id, get_post( $post_id ), true, $this->draft_before() );

        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
        $this->publisher->deliver( $post_id, 'c1', 1 );

        $row = $this->deliveries->get_by_post_channel( $post_id, 'c1' );
        $this->assertSame( 'canceled', $row['status'] );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
composer test -- --filter Test_Publisher
```

Expected: FAIL because `Publisher` does not exist.

- [ ] **Step 3: Implement the publisher**

`includes/class-publisher.php`:

```php
<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Publisher {

    private Settings $settings;
    private Deliveries $deliveries;
    private Buffer_Client $client;
    private Message_Builder $builder;

    public function __construct(
        Settings $settings,
        Deliveries $deliveries,
        Buffer_Client $client,
        Message_Builder $builder
    ) {
        $this->settings   = $settings;
        $this->deliveries = $deliveries;
        $this->client     = $client;
        $this->builder    = $builder;
    }

    public function register(): void {
        add_action( 'wp_after_insert_post', [ $this, 'maybe_publish' ], 10, 4 );
        add_action( 'lightweb2_deliver_channel', [ $this, 'deliver' ], 10, 3 );
        add_action( 'lightweb2_cleanup_logs', [ $this, 'cleanup_logs' ] );
        add_action( 'lightweb2_recover_stale', [ $this, 'recover_stale' ] );
    }

    public function maybe_publish(
        int $post_id,
        \WP_Post $post,
        bool $update,
        ?\WP_Post $post_before
    ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( 'publish' !== $post->post_status ) {
            return;
        }

        if ( $post_before instanceof \WP_Post && 'publish' === $post_before->post_status ) {
            return;
        }

        if ( 'yes' !== get_post_meta( $post_id, '_lightweb2_enabled', true ) ) {
            return;
        }

        $settings = $this->settings->get();

        if ( '' === (string) $settings['token'] || empty( $settings['channels'] ) ) {
            return;
        }

        $filter = (int) $settings['category_filter'];

        if ( $filter > 0 ) {
            $categories = array_map( 'intval', wp_get_post_categories( $post_id ) );

            if ( ! in_array( $filter, $categories, true ) ) {
                return;
            }
        }

        $mode = get_post_meta( $post_id, '_lightweb2_mode', true );
        $mode = in_array( $mode, [ 'shareNow', 'addToQueue' ], true )
            ? $mode
            : (string) $settings['default_mode'];
        $message = $this->builder->build( $post_id, (string) $settings['template'] );

        foreach ( $settings['channels'] as $channel ) {
            $row = $this->deliveries->create_or_get( $post_id, $channel, $mode );

            if ( empty( $row ) || in_array( $row['status'], [ 'sent', 'canceled' ], true ) ) {
                continue;
            }

            if ( '' === $message ) {
                $this->deliveries->mark_failed(
                    (int) $row['id'],
                    'empty_message',
                    __( 'The message template produced an empty post.', 'lightweb2-publish-with-buffer' )
                );
                continue;
            }

            $this->schedule_delivery( $post_id, (string) $channel['id'], (int) $row['attempts'] + 1 );
        }
    }

    public function deliver( int $post_id, string $channel_id, int $attempt = 1 ): void {
        $row = $this->deliveries->get_by_post_channel( $post_id, $channel_id );

        if ( null === $row || in_array( $row['status'], [ 'sent', 'canceled' ], true ) ) {
            return;
        }

        if ( ! $this->deliveries->claim( (int) $row['id'], $attempt ) ) {
            return;
        }

        $post = get_post( $post_id );

        if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
            $this->deliveries->mark_canceled( (int) $row['id'] );
            return;
        }

        $settings = $this->settings->get();

        if ( '' === (string) $settings['token'] ) {
            $this->deliveries->mark_failed(
                (int) $row['id'],
                'missing_token',
                __( 'Reconnect Buffer before publishing.', 'lightweb2-publish-with-buffer' )
            );
            return;
        }

        $message = $this->builder->build( $post_id, (string) $settings['template'] );

        if ( '' === $message ) {
            $this->deliveries->mark_failed(
                (int) $row['id'],
                'empty_message',
                __( 'The message template produced an empty post.', 'lightweb2-publish-with-buffer' )
            );
            return;
        }

        $input = [
            'text'           => $message,
            'channelId'      => $channel_id,
            'schedulingType' => 'automatic',
            'mode'           => in_array( $row['mode'], [ 'shareNow', 'addToQueue' ], true )
                ? $row['mode']
                : (string) $settings['default_mode'],
        ];

        $image = $this->builder->image_url( $post_id );

        if ( null !== $image ) {
            $input['assets'] = [
                [
                    'image' => [ 'url' => $image ],
                ],
            ];
        }

        $result = $this->client->create_post( (string) $settings['token'], $input );

        if ( $result['success'] ) {
            $buffer_id = (string) ( $result['data']['createPost']['post']['id'] ?? '' );

            if ( '' === $buffer_id ) {
                $this->deliveries->mark_failed(
                    (int) $row['id'],
                    'invalid_response',
                    __( 'Buffer did not return a post ID.', 'lightweb2-publish-with-buffer' )
                );
                return;
            }

            $this->deliveries->mark_sent( (int) $row['id'], $buffer_id );
            return;
        }

        $this->deliveries->mark_failed(
            (int) $row['id'],
            (string) $result['code'],
            (string) $result['message']
        );

        if ( ! $result['retryable'] || $attempt >= 4 ) {
            return;
        }

        $delays = [
            1 => 5 * MINUTE_IN_SECONDS,
            2 => 15 * MINUTE_IN_SECONDS,
            3 => HOUR_IN_SECONDS,
        ];
        $delay = (int) $result['retry_after'] > 0
            ? (int) $result['retry_after']
            : ( $delays[ $attempt ] ?? HOUR_IN_SECONDS );

        $this->schedule_delivery( $post_id, $channel_id, $attempt + 1, $delay );
    }

    public function retry( int $delivery_id ): bool {
        $row = $this->deliveries->get( $delivery_id );

        if ( null === $row || 'failed' !== $row['status'] ) {
            return false;
        }

        $this->clear_delivery_events(
            (int) $row['post_id'],
            (string) $row['channel_id']
        );

        if ( ! $this->deliveries->reset_for_retry( $delivery_id ) ) {
            return false;
        }

        if ( ! $this->schedule_delivery( (int) $row['post_id'], (string) $row['channel_id'], 1 ) ) {
            $this->deliveries->mark_failed(
                $delivery_id,
                'schedule_failed',
                __( 'Could not schedule the retry.', 'lightweb2-publish-with-buffer' )
            );
            return false;
        }

        return true;
    }

    public function recover_stale(): void {
        foreach ( $this->deliveries->get_stale_sending( 15 ) as $row ) {
            if ( ! $this->deliveries->mark_stale( (int) $row['id'], 15 ) ) {
                continue;
            }

            if ( (int) $row['attempts'] < 4 ) {
                $this->schedule_delivery(
                    (int) $row['post_id'],
                    (string) $row['channel_id'],
                    (int) $row['attempts'] + 1
                );
            }
        }
    }

    public function cleanup_logs(): void {
        $days = (int) $this->settings->get()['retention_days'];
        $this->deliveries->delete_older_than_days( $days );
    }

    private function clear_delivery_events( int $post_id, string $channel_id ): void {
        for ( $attempt = 1; $attempt <= 4; $attempt++ ) {
            wp_clear_scheduled_hook(
                'lightweb2_deliver_channel',
                [ $post_id, $channel_id, $attempt ]
            );
        }
    }

    private function schedule_delivery(
        int $post_id,
        string $channel_id,
        int $attempt,
        int $delay = 1
    ): bool {
        $args = [ $post_id, $channel_id, $attempt ];

        if ( false !== wp_next_scheduled( 'lightweb2_deliver_channel', $args ) ) {
            return true;
        }

        return (bool) wp_schedule_single_event(
            time() + max( 1, $delay ),
            'lightweb2_deliver_channel',
            $args
        );
    }
}
```

- [ ] **Step 4: Add the atomic claim and stale recovery methods**

Add these methods to `includes/class-deliveries.php`:

```php
    public function claim( int $id, int $attempt ): bool {
        global $wpdb;

        if ( $attempt < 1 ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
        $updated = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
                "UPDATE {$this->table}
                 SET status = 'sending', attempts = attempts + 1, updated_at = %s
                 WHERE id = %d
                   AND status IN ('pending', 'failed')
                   AND attempts = %d
                   AND attempts < 4",
                current_time( 'mysql', true ),
                $id,
                $attempt - 1
            )
        );

        return 1 === (int) $updated;
    }

    public function reset_for_retry( int $id ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
        $updated = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
                "UPDATE {$this->table}
                 SET status = 'pending', attempts = 0,
                     error_code = NULL, error_message = NULL, updated_at = %s
                 WHERE id = %d AND status = 'failed'",
                current_time( 'mysql', true ),
                $id
            )
        );

        return 1 === (int) $updated;
    }

    public function mark_stale( int $id, int $minutes = 15 ): bool {
        global $wpdb;

        $cutoff = gmdate(
            'Y-m-d H:i:s',
            time() - ( max( 1, $minutes ) * MINUTE_IN_SECONDS )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
        $updated = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
                "UPDATE {$this->table}
                 SET status = 'failed', error_code = 'stale_claim',
                     error_message = %s, updated_at = %s
                 WHERE id = %d
                   AND status = 'sending'
                   AND updated_at < %s",
                __( 'The delivery was interrupted and will be retried.', 'lightweb2-publish-with-buffer' ),
                current_time( 'mysql', true ),
                $id,
                $cutoff
            )
        );

        return 1 === (int) $updated;
    }

    public function get_stale_sending( int $minutes = 15 ): array {
        global $wpdb;

        $cutoff = gmdate(
            'Y-m-d H:i:s',
            time() - ( max( 1, $minutes ) * MINUTE_IN_SECONDS )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
                "SELECT * FROM {$this->table}
                 WHERE status = 'sending' AND updated_at < %s",
                $cutoff
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }
```

- [ ] **Step 5: Wire the publisher**

Update `includes/class-plugin.php` so `boot()` creates the publisher and passes it to the future Activity page:

```php
private Buffer_Client $client;
private Deliveries $deliveries;
private Message_Builder $builder;
private Publisher $publisher;
private Admin $admin;
private Meta_Box $meta_box;

public function boot(): void {
    $this->settings   = new Settings();
    $this->client     = new Buffer_Client();
    $this->deliveries = new Deliveries();
    $this->builder    = new Message_Builder();
    $this->publisher  = new Publisher(
        $this->settings,
        $this->deliveries,
        $this->client,
        $this->builder
    );
    $this->admin      = new Admin( $this->settings, $this->client );
    $this->meta_box   = new Meta_Box( $this->settings, $this->deliveries );

    $this->settings->register();
    $this->admin->register();
    $this->meta_box->register();
    $this->publisher->register();
}
```

Add the require in `lightweb2-publish-with-buffer.php` before `class-plugin.php`:

```php
require_once LIGHTWEB2_DIR . 'includes/class-message-builder.php';
require_once LIGHTWEB2_DIR . 'includes/class-publisher.php';
```

- [ ] **Step 6: Run the publisher tests**

```bash
composer test -- --filter Test_Publisher
```

Expected: PASS.

- [ ] **Step 7: Add the concurrency and retry-limit regression tests**

Add these focused tests before running the full suite:

- `Test_Deliveries`: `claim()` succeeds once, rejects a second claim while the row is `sending`, and rejects a stale attempt number.
- `Test_Deliveries`: `reset_for_retry()` clears attempts and error fields and sets `pending`.
- `Test_Deliveries`: `reset_for_retry()` returns false and leaves a `sending` row untouched.
- `Test_Deliveries`: `get_stale_sending()` returns only claimed rows older than the cutoff.
- `Test_Publisher`: a 401 or typed mutation failure marks `failed` and schedules no retry.
- `Test_Publisher`: four failed attempts leave `attempts = 4` and schedule no fifth automatic event.
- `Test_Publisher`: a stale event attempt makes no HTTP call and leaves the row untouched.
- `Test_Publisher`: manual retry resets attempts to zero and schedules attempt one.
- `Test_Publisher`: manual retry clears old attempt events for the same post/channel.
- `Test_Publisher`: `recover_stale()` marks an old `sending` row failed and schedules the next attempt.
- `Test_Publisher`: `recover_stale()` does not overwrite a row that became `sent` after the stale query.
- `Test_Publisher`: `maybe_publish()` does not schedule dead events for `canceled` rows.

Expected: all focused tests pass, including the existing happy-path tests.

- [ ] **Step 8: Run the full suite to catch constructor changes**

```bash
composer test
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add includes/class-publisher.php includes/class-deliveries.php includes/class-installer.php includes/class-plugin.php lightweb2-publish-with-buffer.php tests
git commit -m "feat: add asynchronous publishing with per-channel retries"
```

---

## Task 10: Activity Page, Manual Retry, and Cleanup

**Files:**
- Modify: `includes/class-admin.php`
- Modify: `includes/class-admin.php` menu and AJAX registration
- Modify: `assets/admin.js`
- Modify: `assets/admin.css`
- Create: `tests/test-activity.php`

**Interfaces:**
- Consumes: `Deliveries::get_recent()`, `Deliveries::count_recent()`, `Publisher::retry()`
- Produces: `Admin::render_activity_page(): void`
- Produces: AJAX actions `lightweb2_retry_delivery`, `lightweb2_clear_logs`

- [ ] **Step 1: Write the failing Activity tests**

`tests/test-activity.php`:

```php
<?php
declare( strict_types=1 );

use LightWeb2\PublishWithBuffer\Admin;
use LightWeb2\PublishWithBuffer\Buffer_Client;
use LightWeb2\PublishWithBuffer\Deliveries;
use LightWeb2\PublishWithBuffer\Installer;
use LightWeb2\PublishWithBuffer\Message_Builder;
use LightWeb2\PublishWithBuffer\Publisher;
use LightWeb2\PublishWithBuffer\Settings;

final class Test_Activity extends WP_UnitTestCase {

    private Deliveries $deliveries;
    private Publisher $publisher;
    private Admin $admin;

    public function set_up(): void {
        parent::set_up();
        Installer::create_table();

        $settings         = new Settings();
        $this->deliveries = new Deliveries();
        $this->deliveries->clear();
        $this->publisher  = new Publisher(
            $settings,
            $this->deliveries,
            new Buffer_Client(),
            new Message_Builder()
        );
        $this->admin      = new Admin(
            $settings,
            new Buffer_Client(),
            $this->deliveries,
            $this->publisher
        );

        wp_clear_scheduled_hook( 'lightweb2_deliver_channel' );
    }

    public function test_activity_page_renders_failed_delivery_and_retry_button(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $row = $this->deliveries->create_or_get(
            50,
            [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
            'addToQueue'
        );
        $this->deliveries->mark_failed( (int) $row['id'], 'server_error', 'Try later' );

        ob_start();
        $this->admin->render_activity_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Activity', $html );
        $this->assertStringContainsString( 'Company LinkedIn', $html );
        $this->assertStringContainsString( 'lightweb2-retry', $html );
    }

    public function test_retry_resets_failed_delivery_and_schedules_worker(): void {
        $row = $this->deliveries->create_or_get(
            51,
            [ 'id' => 'c1', 'name' => 'Company LinkedIn', 'service' => 'linkedin' ],
            'addToQueue'
        );
        $this->deliveries->mark_failed( (int) $row['id'], 'server_error', 'Try later' );

        $this->assertTrue( $this->publisher->retry( (int) $row['id'] ) );

        $updated = $this->deliveries->get( (int) $row['id'] );
        $this->assertSame( 'pending', $updated['status'] );
        $this->assertNotFalse(
            wp_next_scheduled( 'lightweb2_deliver_channel', [ 51, 'c1', 1 ] )
        );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
composer test -- --filter Test_Activity
```

Expected: FAIL because `render_activity_page()` does not exist.

- [ ] **Step 3: Update the Admin constructor, menu, and AJAX registration**

Update `includes/class-admin.php`:

```php
private Settings $settings;
private Buffer_Client $client;
private Deliveries $deliveries;
private Publisher $publisher;

public function __construct(
    Settings $settings,
    Buffer_Client $client,
    Deliveries $deliveries,
    Publisher $publisher
) {
    $this->settings   = $settings;
    $this->client     = $client;
    $this->deliveries = $deliveries;
    $this->publisher  = $publisher;
}

public function register(): void {
    add_action( 'admin_menu', [ $this, 'register_menu' ] );
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    add_action( 'wp_ajax_lightweb2_test_connection', [ $this, 'ajax_test_connection' ] );
    add_action( 'wp_ajax_lightweb2_fetch_channels', [ $this, 'ajax_fetch_channels' ] );
    add_action( 'wp_ajax_lightweb2_disconnect', [ $this, 'ajax_disconnect' ] );
    add_action( 'wp_ajax_lightweb2_retry_delivery', [ $this, 'ajax_retry_delivery' ] );
    add_action( 'wp_ajax_lightweb2_clear_logs', [ $this, 'ajax_clear_logs' ] );
}
```

Update `includes/class-plugin.php` so the new `Admin` dependencies are passed:

```php
$this->admin = new Admin( $this->settings, $this->client, $this->deliveries, $this->publisher );
```

Update the `Admin` construction in `tests/test-admin.php` and `tests/test-admin-ajax.php` to the same four arguments used in `tests/test-activity.php`.

Add the Activity submenu inside `register_menu()`:

```php
add_submenu_page(
    'lightweb2',
    __( 'Activity', 'lightweb2-publish-with-buffer' ),
    __( 'Activity', 'lightweb2-publish-with-buffer' ),
    'manage_options',
    'lightweb2-activity',
    [ $this, 'render_activity_page' ]
);
```

- [ ] **Step 4: Implement the Activity page and AJAX actions**

Add to `includes/class-admin.php`:

```php
public function render_activity_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $status     = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
    $channel_id = isset( $_GET['channel_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['channel_id'] ) ) : '';
    $date_from  = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date_from'] ) ) : '';
    $date_to    = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date_to'] ) ) : '';
    $page       = isset( $_GET['paged'] )
        ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) )
        : 1;
    $per_page   = 20;

    $args = [
        'status'     => $status,
        'channel_id' => $channel_id,
        'date_from'  => $date_from ? $date_from . ' 00:00:00' : '',
        'date_to'    => $date_to ? $date_to . ' 23:59:59' : '',
        'per_page'   => $per_page,
        'page'       => $page,
    ];

    $rows  = $this->deliveries->get_recent( $args );
    $total = $this->deliveries->count_recent( $args );
    $pages = max( 1, (int) ceil( $total / $per_page ) );
    ?>
    <div class="wrap lightweb2-wrap">
        <header class="lightweb2-header">
            <div>
                <p class="lightweb2-eyebrow"><?php esc_html_e( 'LightWeb2', 'lightweb2-publish-with-buffer' ); ?></p>
                <h1><?php esc_html_e( 'Activity', 'lightweb2-publish-with-buffer' ); ?></h1>
                <p class="lightweb2-lede"><?php esc_html_e( 'Every Buffer delivery attempt, with the reason it failed and a safe retry action.', 'lightweb2-publish-with-buffer' ); ?></p>
            </div>
            <button type="button" class="button" id="lightweb2-clear-logs"><?php esc_html_e( 'Clear history', 'lightweb2-publish-with-buffer' ); ?></button>
        </header>

        <form method="get" class="lightweb2-card lightweb2-filters">
            <input type="hidden" name="page" value="lightweb2-activity" />
            <label>
                <span><?php esc_html_e( 'Status', 'lightweb2-publish-with-buffer' ); ?></span>
                <select name="status">
                    <option value=""><?php esc_html_e( 'All statuses', 'lightweb2-publish-with-buffer' ); ?></option>
                    <?php foreach ( [ 'pending', 'sending', 'sent', 'failed', 'canceled' ] as $option ) : ?>
                        <option value="<?php echo esc_attr( $option ); ?>" <?php selected( $status, $option ); ?>><?php echo esc_html( ucfirst( $option ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php esc_html_e( 'Channel ID', 'lightweb2-publish-with-buffer' ); ?></span>
                <input type="text" name="channel_id" value="<?php echo esc_attr( $channel_id ); ?>" />
            </label>
            <label>
                <span><?php esc_html_e( 'From', 'lightweb2-publish-with-buffer' ); ?></span>
                <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
            </label>
            <label>
                <span><?php esc_html_e( 'To', 'lightweb2-publish-with-buffer' ); ?></span>
                <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
            </label>
            <?php submit_button( __( 'Filter', 'lightweb2-publish-with-buffer' ), '', '', false ); ?>
        </form>

        <div class="lightweb2-card">
            <?php if ( empty( $rows ) ) : ?>
                <p><?php esc_html_e( 'No deliveries yet. Publish a post with Buffer enabled to see activity here.', 'lightweb2-publish-with-buffer' ); ?></p>
            <?php else : ?>
                <table class="widefat striped lightweb2-activity-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'lightweb2-publish-with-buffer' ); ?></th>
                            <th><?php esc_html_e( 'Post', 'lightweb2-publish-with-buffer' ); ?></th>
                            <th><?php esc_html_e( 'Channel', 'lightweb2-publish-with-buffer' ); ?></th>
                            <th><?php esc_html_e( 'Mode', 'lightweb2-publish-with-buffer' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'lightweb2-publish-with-buffer' ); ?></th>
                            <th><?php esc_html_e( 'Attempts', 'lightweb2-publish-with-buffer' ); ?></th>
                            <th><?php esc_html_e( 'Details', 'lightweb2-publish-with-buffer' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'lightweb2-publish-with-buffer' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row['created_at'] . ' UTC' ) ) ); ?></td>
                                <td>
                                    <?php
                                    $edit_link = get_edit_post_link( (int) $row['post_id'] );
                                    $title     = get_the_title( (int) $row['post_id'] );
                                    ?>
                                    <?php if ( $edit_link ) : ?>
                                        <a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $title ?: '#' . $row['post_id'] ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $title ?: '#' . $row['post_id'] ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $row['channel_name'] ?: $row['channel_id'] ); ?><br /><small><?php echo esc_html( $row['service'] ); ?></small></td>
                                <td><?php echo esc_html( $row['mode'] ); ?></td>
                                <td><span class="lightweb2-badge is-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( ucfirst( $row['status'] ) ); ?></span></td>
                                <td><?php echo esc_html( (string) $row['attempts'] ); ?></td>
                                <td>
                                    <?php if ( $row['buffer_post_id'] ) : ?>
                                        <code><?php echo esc_html( $row['buffer_post_id'] ); ?></code>
                                    <?php endif; ?>
                                    <?php if ( $row['error_message'] ) : ?>
                                        <span class="lightweb2-error"><?php echo esc_html( $row['error_message'] ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( 'failed' === $row['status'] ) : ?>
                                        <button type="button" class="button button-small lightweb2-retry" data-delivery-id="<?php echo esc_attr( (string) $row['id'] ); ?>"><?php esc_html_e( 'Retry', 'lightweb2-publish-with-buffer' ); ?></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $pages > 1 ) : ?>
                    <div class="tablenav"><div class="tablenav-pages"><?php
                    echo wp_kses_post(
                        paginate_links(
                            [
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'current'   => $page,
                                'total'     => $pages,
                                'type'      => 'plain',
                            ]
                        )
                    );
                    ?></div></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <p class="lightweb2-ajax-status" id="lightweb2-ajax-status" role="status" aria-live="polite"></p>
    </div>
    <?php
}

public function ajax_retry_delivery(): void {
    $this->verify_ajax();
    $delivery_id = isset( $_POST['delivery_id'] )
        ? absint( wp_unslash( (string) $_POST['delivery_id'] ) )
        : 0;

    if ( $delivery_id < 1 || ! $this->publisher->retry( $delivery_id ) ) {
        wp_send_json_error(
            [ 'message' => __( 'This delivery cannot be retried.', 'lightweb2-publish-with-buffer' ) ],
            400
        );
    }

    wp_send_json_success();
}

public function ajax_clear_logs(): void {
    $this->verify_ajax();
    $this->deliveries->clear();
    wp_send_json_success();
}
```

Add badge and filter styles to `assets/admin.css`:

```css
.lightweb2-filters {
  display: flex;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: 16px;
}

.lightweb2-filters label {
  display: grid;
  gap: 4px;
}

.lightweb2-badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 999px;
  background: #f1f5f9;
  font-size: 12px;
  font-weight: 600;
}

.lightweb2-badge.is-sent {
  color: #15803d;
  background: #dcfce7;
}

.lightweb2-badge.is-failed {
  color: #b91c1c;
  background: #fee2e2;
}

.lightweb2-badge.is-pending {
  color: #b45309;
  background: #fef3c7;
}

.lightweb2-badge.is-sending {
  color: #1d4ed8;
  background: #dbeafe;
}

.lightweb2-badge.is-canceled {
  color: #475569;
  background: #e2e8f0;
}

.lightweb2-error {
  display: block;
  margin-top: 4px;
  color: #b91c1c;
}

.lightweb2-activity-table td {
  vertical-align: top;
}

@media (max-width: 782px) {
  .lightweb2-filters {
    display: grid;
  }
}
```

- [ ] **Step 5: Add Activity actions to the admin script**

Append to `assets/admin.js` inside the existing IIFE, before the preview block:

```js
  document.querySelectorAll('.lightweb2-retry').forEach((button) => {
    button.addEventListener('click', async () => {
      button.disabled = true;
      setAjaxStatus(cfg.strings.loading);

      try {
        await request('lightweb2_retry_delivery', {
          delivery_id: button.dataset.deliveryId,
        });
        window.location.reload();
      } catch (error) {
        button.disabled = false;
        setAjaxStatus(error.message, true);
      }
    });
  });

  byId('lightweb2-clear-logs')?.addEventListener('click', async () => {
    if (!window.confirm(cfg.strings.confirmClearLogs)) {
      return;
    }

    try {
      await request('lightweb2_clear_logs');
      window.location.reload();
    } catch (error) {
      setAjaxStatus(error.message, true);
    }
  });
```

- [ ] **Step 6: Run the Activity and full test suites**

```bash
composer test -- --filter 'Test_Activity|Test_Publisher|Test_Admin'
composer test
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/class-admin.php assets/admin.css assets/admin.js tests/test-activity.php
git commit -m "feat: add delivery activity and manual retry"
```

---

## Task 11: Release Hardening, i18n, CI, and ZIP

**Files:**
- Create: `uninstall.php`, `.phpcs.xml.dist`
- Modify: `readme.txt`
- Delete: `languages/formatocd-publish-with-buffer-es_ES.po`, `languages/formatocd-publish-with-buffer-es_ES.mo`
- Create: `.github/workflows/ci.yml`, `bin/build.sh`
- Create: `languages/lightweb2-publish-with-buffer.pot` generated by WP-CLI
- Modify: `tests/test-installer.php`

**Interfaces:**
- Consumes: `Installer::uninstall()`
- Produces: production ZIP and WordPress.org-ready metadata

- [ ] **Step 1: Add the failing uninstall test**

Append to `tests/test-installer.php`:

```php
    public function test_uninstall_removes_settings_and_deliveries_table(): void {
        global $wpdb;

        Installer::activate();
        Installer::uninstall();

        $this->assertFalse( get_option( Settings::OPTION ) );
        $this->assertNull(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SHOW TABLES LIKE %s',
                    $wpdb->esc_like( $wpdb->prefix . 'lightweb2_deliveries' )
                )
            )
        );

        Installer::activate();
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
composer test -- --filter test_uninstall_removes_settings_and_deliveries_table
```

Expected: FAIL because `uninstall.php` and the uninstall behavior are not complete.

- [ ] **Step 3: Add uninstall handling**

`uninstall.php`:

```php
<?php
declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-installer.php';

\LightWeb2\PublishWithBuffer\Installer::uninstall();
```

- [ ] **Step 4: Add WordPress coding standards configuration**

`.phpcs.xml.dist`:

```xml
<?xml version="1.0"?>
<ruleset name="LightWeb2 Publish with Buffer">
    <description>WordPress coding standards for LightWeb2.</description>
    <file>.</file>
    <exclude-pattern>/vendor/</exclude-pattern>
    <exclude-pattern>/wordpress/</exclude-pattern>
    <exclude-pattern>/node_modules/</exclude-pattern>
    <exclude-pattern>/build/</exclude-pattern>
    <exclude-pattern>/tests/</exclude-pattern>
    <arg value="sp"/>
    <arg name="basepath" value="./"/>
    <arg name="colors"/>
    <arg name="extensions" value="php"/>
    <arg name="parallel" value="8"/>
    <config name="testVersion" value="7.4-"/>
    <config name="minimum_supported_wp_version" value="6.5"/>
    <rule ref="WordPress"/>
    <rule ref="PHPCompatibilityWP"/>
    <rule ref="WordPress.NamingConventions.PrefixAllGlobals">
        <properties>
            <property name="prefixes" type="array" value="lightweb2"/>
        </properties>
    </rule>
    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array" value="lightweb2-publish-with-buffer"/>
        </properties>
    </rule>
</ruleset>
```

- [ ] **Step 5: Add the WordPress.org readme**

`readme.txt`:

```text
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

== Screenshots ==

1. The LightWeb2 connection and channel settings.
2. The channel picker loaded from Buffer.
3. The per-post LightWeb2 meta box.
4. The Activity page with delivery status and retry.

== Changelog ==

= 1.0.0 =
* Initial LightWeb2 release.
* Buffer personal API key connection.
* Channel discovery and selection.
* Asynchronous publishing with per-channel tracking.
* Duplicate prevention and safe retries.
* Activity log and manual retry.
```

- [ ] **Step 6: Add CI**

`.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['7.4', '8.3']
        wp: ['6.5.13', '7.1.3']
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ALLOW_EMPTY_PASSWORD: 'yes'
          MYSQL_DATABASE: wordpress_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -u root"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          tools: composer
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - run: composer require --dev roots/wordpress:${{ matrix.wp }} wp-phpunit/wp-phpunit:${{ matrix.wp }} --with-all-dependencies --no-interaction
      - run: mysql -h 127.0.0.1 -u root -e "CREATE DATABASE IF NOT EXISTS wordpress_test;"
      - run: composer test
        env:
          WP_TESTS_DB_HOST: 127.0.0.1
          WP_TESTS_DB_USER: root
          WP_TESTS_DB_PASSWORD: ''
          WP_TESTS_DB_NAME: wordpress_test
      - run: composer phpcs
```

- [ ] **Step 7: Add the build script**

`bin/build.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"
SLUG="lightweb2-publish-with-buffer"

rm -rf "$BUILD"
mkdir -p "$BUILD/$SLUG"

rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$BUILD/$SLUG/"

(cd "$BUILD" && zip -qr "$SLUG.zip" "$SLUG")

echo "Built $BUILD/$SLUG.zip"
```

Make it executable:

```bash
chmod +x bin/build.sh
```

- [ ] **Step 8: Generate the translation template**

```bash
wp i18n make-pot . languages/lightweb2-publish-with-buffer.pot \
  --domain=lightweb2-publish-with-buffer \
  --exclude=vendor,wordpress,tests,docs,node_modules,build
```

Expected: `languages/lightweb2-publish-with-buffer.pot` contains all user-facing strings.

- [ ] **Step 9: Run the full verification suite**

```bash
composer test
composer phpcs
bash bin/build.sh
```

Expected: all tests pass, PHPCS reports no errors, and `build/lightweb2-publish-with-buffer.zip` is created.

- [ ] **Step 10: Run the manual QA matrix**

Verify each item on a disposable WordPress installation:

- WordPress 6.5 and WordPress 7.1.
- PHP 7.4 and PHP 8.3.
- Block Editor and Classic Editor.
- A scheduled post transitioning from Future to Publish.
- A category that matches the filter and a post that does not.
- A valid token, an invalid token, and no token.
- Two channels where one succeeds and one fails.
- Retry the failed channel and confirm the successful channel is not sent again.
- Activity filters, pagination, retry, and clear history.
- A mobile admin width around 375px.
- An RTL locale.
- Keyboard-only navigation with visible focus.

Expected: every item behaves as described in the spec.

- [ ] **Step 11: Run WordPress Plugin Check on a local test site**

```bash
wp plugin install plugin-check --activate
wp plugin check lightweb2-publish-with-buffer
```

Expected: no errors. Warnings must be reviewed and either fixed or documented.

- [ ] **Step 12: Run the live Buffer smoke test**

On a local test site with a real Buffer personal API key provided by the owner:

1. Save the token and confirm `Connected`.
2. Load channels and select one channel.
3. Publish a post with `Add to Queue` and confirm the delivery becomes `sent`.
4. Publish a second post with `Share Now` and confirm the delivery becomes `sent`.
5. Replace the token with an invalid value and confirm the UI shows the reconnect error without retrying permanently.

Expected: the live deliveries appear in Buffer and the invalid token produces a clear, non-retrying failure.

- [ ] **Step 13: Commit**

```bash
git add .
git commit -m "chore: prepare LightWeb2 1.0.0 release"
```

---

## Plan Self-Review

### Spec Coverage

| Spec area | Plan task |
| --- | --- |
| Brand, distribution, compatibility, language | Global constraints, Task 1, Task 3, Task 11 |
| One settings option and autoload | Task 1, Task 2 |
| Token preservation and changed-token channel clearing | Task 2, Task 5 |
| Channel discovery and test connection | Task 4, Task 5 |
| Async publish and `wp_after_insert_post` | Task 9 |
| Per-channel idempotency and unique key | Task 7, Task 9 |
| Retry policy, manual retry, cancellation | Task 9, Task 10 |
| Activity log and filters | Task 10 |
| Meta box and per-post mode | Task 8 |
| Branded UI, RTL, accessibility | Task 3, Task 10, Task 11 |
| Security and token handling | Global constraints, Task 2, Task 4, Task 8, Task 9 |
| Uninstall, readme, i18n, CI | Task 11 |
| Compatibility matrix | Task 11 |

### Consistency Checks

- `lightweb2_settings` is the only settings option in every task.
- `Settings::catalog_key()` is used by both fetch and sanitization.
- `Buffer_Client` failure arrays always contain `success`, `code`, `message`, `retryable`, and `retry_after`.
- `Deliveries` statuses are limited to `pending`, `sent`, `failed`, and `canceled`.
- `Publisher` uses the same `lightweb2_deliver_channel` hook and argument order everywhere.
- `Admin` constructor changes in Task 9 are reflected in Task 10 tests.
- No task writes the token to HTML, JavaScript, logs, or error text.

### Known Execution Notes

- The local test database must be created before running PHPUnit.
- A live Buffer token is required only for the final smoke test, not for the automated suite.
- Commit steps are included because the execution skills require frequent commits. Do not run them unless the repository owner has approved commits for the execution session.
