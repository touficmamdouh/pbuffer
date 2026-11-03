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
