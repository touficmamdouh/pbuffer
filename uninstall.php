<?php
/**
 * Plugin uninstall routine.
 *
 * @package LightWeb2\PublishWithBuffer
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-installer.php';

\LightWeb2\PublishWithBuffer\Installer::uninstall();
