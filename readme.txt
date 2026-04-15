=== Alwaleed products ===
Contributors: djeytkey
Tags: woocommerce, wpml, products, variable products
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.001
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert all WooCommerce simple products to variable products with one click, including translated WPML products.

== Description ==

Alwaleed products adds a WooCommerce admin tool that:

1. Finds all products of type "simple".
2. Converts each one to "variable".
3. Creates a default variation and migrates key product data (price, stock, dimensions, image, downloadable flags).
4. Keeps WPML translated products compatible by converting each translated product as its own WooCommerce product.

The plugin also supports updates from GitHub releases.

== Installation ==

1. Upload the plugin folder `alwaleed-products` to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **WooCommerce > Convert To Variable**.
4. Run a dry-run first, then run the real conversion.

== Frequently Asked Questions ==

= Is this WPML compatible? =
Yes. Each translated product is converted independently, which matches how WPML stores translated products.

= Where do I run conversion? =
WooCommerce > Convert To Variable.

= How does update from GitHub work? =
Create GitHub releases with a zip asset named `alwaleed-products.zip`.
WordPress will check the latest release and show an available update.

== Changelog ==

= 1.0.001 =
* Renamed plugin folder and main file to `alwaleed-products`.
* Updated release and push scripts to use the new folder and archive name.
* Updated plugin display name to `Alwaleed products`.

= 1.0.0 =
* Initial release.
