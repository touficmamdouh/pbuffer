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
