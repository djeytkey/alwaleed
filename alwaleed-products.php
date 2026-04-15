<?php
/**
 * Plugin Name: Alwaleed products
 * Plugin URI: https://github.com/djeytkey/alwaleed
 * Description: Converts WooCommerce simple products to variable products and supports self-updates from GitHub releases.
$11.0.002
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Alwaleed
 * Author URI: https://github.com/djeytkey
 * License: GPL-2.0-or-later
 * Text Domain: alwaleed-simple-to-variable
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AWV_PLUGIN_VERSION', '1.0.001');
define('AWV_PLUGIN_FILE', __FILE__);
define('AWV_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('AWV_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AWV_GITHUB_REPOSITORY', 'djeytkey/alwaleed');
define('AWV_GITHUB_RELEASE_ASSET', 'alwaleed-products.zip');
define('AWV_GITHUB_API_URL', 'https://api.github.com/repos/' . AWV_GITHUB_REPOSITORY . '/releases/latest');

add_action('plugins_loaded', static function () {
    if (!class_exists('WooCommerce')) {
        return;
    }

    require_once AWV_PLUGIN_DIR . 'includes/class-awv-converter.php';
    require_once AWV_PLUGIN_DIR . 'includes/class-awv-github-updater.php';

    AWV_Converter::boot();
    AWV_GitHub_Updater::boot();
});
