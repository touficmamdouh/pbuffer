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
require_once LIGHTWEB2_DIR . 'includes/class-admin.php';
require_once LIGHTWEB2_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, [ '\LightWeb2\PublishWithBuffer\Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\LightWeb2\PublishWithBuffer\Installer', 'deactivate' ] );

add_action(
    'plugins_loaded',
    static function (): void {
        \LightWeb2\PublishWithBuffer\Plugin::instance()->boot();
    }
);
