<?php
/**
 * Plugin Name: Lieska Multi-Language Alt Text – Free
 * Plugin URI:  https://www.lieska.net
 * Description: Manage alt texts for images in multiple languages. Works with WPML, Polylang, and other multilingual plugins. This free edition provides manual tools for multilingual alt texts without AI generation.
 * Version:     1.0.0
 * Author:      Lieska
 * Author URI:  https://www.lieska.net
 * License:     GPL-2.0-or-later
 * Text Domain: lieska-mlat
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LIESKA_MLAT_VERSION', '1.0.0' );
define( 'LIESKA_MLAT_FILE', __FILE__ );
define( 'LIESKA_MLAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIESKA_MLAT_URL', plugin_dir_url( __FILE__ ) );

require_once LIESKA_MLAT_DIR . 'includes/class-plugin.php';

function lieska_mlat() {
    return \Lieska_MLAT\Plugin::instance();
}
add_action( 'plugins_loaded', 'lieska_mlat' );
