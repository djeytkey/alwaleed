<?php

if (!defined('ABSPATH')) {
    exit;
}

final class AWV_Converter
{
    private const ATTRIBUTE_SLUG = 'awv_option';
    private const ATTRIBUTE_LABEL = 'Conversion Option';
    private const ATTRIBUTE_TERM = 'Default option';
    private const NONCE_ACTION = 'awv_convert_products';
    private const MENU_SLUG = 'awv-convert-products';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_awv_convert_products', [self::class, 'handle_conversion_request']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Convert Simple Products', 'alwaleed-simple-to-variable'),
            __('Convert To Variable', 'alwaleed-simple-to-variable'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $stats = self::collect_stats();
        $message = '';
        $status = '';

        if (isset($_GET['awv_message'], $_GET['awv_status'])) {
            $message = sanitize_text_field(wp_unslash($_GET['awv_message']));
            $status = sanitize_text_field(wp_unslash($_GET['awv_status']));
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Simple To Variable Converter', 'alwaleed-simple-to-variable'); ?></h1>
            <p><?php esc_html_e('This tool converts all simple products into variable products and creates one default variation while keeping existing prices, stock, and media data.', 'alwaleed-simple-to-variable'); ?></p>
            <p><?php esc_html_e('WPML note: each translated product is handled as its own WooCommerce product, so all languages are converted.', 'alwaleed-simple-to-variable'); ?></p>

            <?php if ($message !== '') : ?>
                <div class="notice notice-<?php echo esc_attr($status === 'success' ? 'success' : 'error'); ?>">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <table class="widefat striped" style="max-width: 700px; margin-top: 20px;">
                <tbody>
                <tr>
                    <th><?php esc_html_e('Simple products', 'alwaleed-simple-to-variable'); ?></th>
                    <td><?php echo esc_html((string) $stats['simple_count']); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Variable products', 'alwaleed-simple-to-variable'); ?></th>
                    <td><?php echo esc_html((string) $stats['variable_count']); ?></td>
                </tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 24px;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="awv_convert_products">
                <p>
                    <label>
                        <input type="checkbox" name="awv_dry_run" value="1" checked>
                        <?php esc_html_e('Dry run only (preview without changing products)', 'alwaleed-simple-to-variable'); ?>
                    </label>
                </p>
                <?php submit_button(__('Run conversion', 'alwaleed-simple-to-variable'), 'primary'); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_conversion_request(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'alwaleed-simple-to-variable'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $dry_run = isset($_POST['awv_dry_run']) && sanitize_text_field(wp_unslash($_POST['awv_dry_run'])) === '1';
        $result = self::convert_all_simple_products($dry_run);

        $message = sprintf(
            /* translators: 1: converted count, 2: skipped count, 3: failed count */
            __('Done. Converted: %1$d, Skipped: %2$d, Failed: %3$d', 'alwaleed-simple-to-variable'),
            $result['converted'],
            $result['skipped'],
            $result['failed']
        );

        if ($dry_run) {
            $message = sprintf(
                /* translators: 1: candidate count */
                __('Dry run complete. Products that would be converted: %1$d', 'alwaleed-simple-to-variable'),
                $result['candidates']
            );
        }

        wp_safe_redirect(add_query_arg(
            [
                'page' => self::MENU_SLUG,
                'awv_message' => $message,
                'awv_status' => $result['failed'] > 0 ? 'error' : 'success',
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    private static function collect_stats(): array
    {
        return [
            'simple_count' => self::count_products_by_type('simple'),
            'variable_count' => self::count_products_by_type('variable'),
        ];
    }

    private static function count_products_by_type(string $type): int
    {
        $ids = wc_get_products(
            [
                'return' => 'ids',
                'status' => ['publish', 'draft', 'private', 'pending'],
                'type' => $type,
                'limit' => -1,
            ]
        );

        return is_array($ids) ? count($ids) : 0;
    }

    private static function convert_all_simple_products(bool $dry_run): array
    {
        $simple_ids = wc_get_products(
            [
                'return' => 'ids',
                'type' => 'simple',
                'status' => ['publish', 'draft', 'private', 'pending'],
                'limit' => -1,
            ]
        );

        if (!is_array($simple_ids)) {
            $simple_ids = [];
        }

        if ($dry_run) {
            return [
                'candidates' => count($simple_ids),
                'converted' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];
        }

        $attribute_taxonomy = self::ensure_attribute_taxonomy();
        $attribute_term = self::ensure_attribute_term($attribute_taxonomy);

        $converted = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($simple_ids as $product_id) {
            $product = wc_get_product($product_id);

            if (!$product instanceof WC_Product_Simple) {
                $skipped++;
                continue;
            }

            $is_converted = self::convert_single_product($product, $attribute_taxonomy, $attribute_term);

            if ($is_converted === true) {
                $converted++;
            } elseif ($is_converted === null) {
                $skipped++;
            } else {
                $failed++;
            }
        }

        return [
            'candidates' => count($simple_ids),
            'converted' => $converted,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    private static function ensure_attribute_taxonomy(): string
    {
        $taxonomy = wc_attribute_taxonomy_name(self::ATTRIBUTE_SLUG);
        $attribute_id = wc_attribute_taxonomy_id_by_name(self::ATTRIBUTE_SLUG);

        if ($attribute_id > 0) {
            return $taxonomy;
        }

        $created_id = wc_create_attribute(
            [
                'name' => self::ATTRIBUTE_LABEL,
                'slug' => self::ATTRIBUTE_SLUG,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ]
        );

        if (is_wp_error($created_id)) {
            return $taxonomy;
        }

        delete_transient('wc_attribute_taxonomies');
        WC_Cache_Helper::incr_cache_prefix('woocommerce-attributes');

        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy(
                $taxonomy,
                apply_filters('woocommerce_taxonomy_objects_' . $taxonomy, ['product']),
                apply_filters(
                    'woocommerce_taxonomy_args_' . $taxonomy,
                    [
                        'labels' => ['name' => self::ATTRIBUTE_LABEL],
                        'hierarchical' => true,
                        'show_ui' => false,
                        'query_var' => true,
                        'rewrite' => false,
                    ]
                )
            );
        }

        return $taxonomy;
    }

    private static function ensure_attribute_term(string $taxonomy): array
    {
        $existing = get_term_by('name', self::ATTRIBUTE_TERM, $taxonomy);

        if ($existing && !is_wp_error($existing)) {
            return [
                'id' => (int) $existing->term_id,
                'slug' => $existing->slug,
            ];
        }

        $inserted = wp_insert_term(self::ATTRIBUTE_TERM, $taxonomy);

        if (is_wp_error($inserted)) {
            return [
                'id' => 0,
                'slug' => sanitize_title(self::ATTRIBUTE_TERM),
            ];
        }

        $term = get_term((int) $inserted['term_id'], $taxonomy);

        if (!$term || is_wp_error($term)) {
            return [
                'id' => 0,
                'slug' => sanitize_title(self::ATTRIBUTE_TERM),
            ];
        }

        return [
            'id' => (int) $term->term_id,
            'slug' => $term->slug,
        ];
    }

    /**
     * Returns true on converted, null on skipped, false on failed.
     */
    private static function convert_single_product(
        WC_Product_Simple $product,
        string $attribute_taxonomy,
        array $attribute_term
    ): ?bool {
        $product_id = $product->get_id();
        $attribute_term_slug = (string) ($attribute_term['slug'] ?? '');
        $attribute_term_id = (int) ($attribute_term['id'] ?? 0);

        if ($product->get_parent_id() > 0) {
            return null;
        }

        $children = wc_get_products(
            [
                'return' => 'ids',
                'type' => 'variation',
                'parent' => $product_id,
                'limit' => 1,
            ]
        );

        if (is_array($children) && count($children) > 0) {
            return null;
        }

        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        $sku = $product->get_sku();
        $manage_stock = $product->get_manage_stock();
        $stock_quantity = $product->get_stock_quantity();
        $stock_status = $product->get_stock_status();
        $weight = $product->get_weight();
        $length = $product->get_length();
        $width = $product->get_width();
        $height = $product->get_height();
        $virtual = $product->get_virtual();
        $downloadable = $product->get_downloadable();
        $downloads = $product->get_downloads();
        $download_limit = $product->get_download_limit();
        $download_expiry = $product->get_download_expiry();
        $image_id = $product->get_image_id();

        wp_set_object_terms($product_id, 'variable', 'product_type');
        wp_set_object_terms($product_id, $attribute_term_slug, $attribute_taxonomy, false);

        $attribute = new WC_Product_Attribute();
        $attribute_id = wc_attribute_taxonomy_id_by_name(self::ATTRIBUTE_SLUG);
        $attribute->set_id($attribute_id);
        $attribute->set_name($attribute_taxonomy);
        $attribute->set_options($attribute_term_id > 0 ? [$attribute_term_id] : [$attribute_term_slug]);
        $attribute->set_position(0);
        $attribute->set_visible(true);
        $attribute->set_variation(true);

        $variable = new WC_Product_Variable($product_id);
        $variable->set_attributes([$attribute]);
        $variable->set_default_attributes([$attribute_taxonomy => $attribute_term_slug]);
        $variable->save();

        $variation_post_id = wp_insert_post(
            [
                'post_title' => sprintf(__('Variation #%d of %s', 'alwaleed-simple-to-variable'), 1, $variable->get_name()),
                'post_name' => 'product-' . $product_id . '-variation-1',
                'post_status' => 'publish',
                'post_parent' => $product_id,
                'post_type' => 'product_variation',
                'guid' => home_url('/?product_variation=product-' . $product_id . '-variation-1'),
                'menu_order' => 0,
            ],
            true
        );

        if (is_wp_error($variation_post_id)) {
            return false;
        }

        $variation = new WC_Product_Variation($variation_post_id);
        $variation->set_parent_id($product_id);
        $variation->set_attributes([$attribute_taxonomy => $attribute_term_slug]);
        $variation->set_regular_price($regular_price);
        $variation->set_sale_price($sale_price);
        $variation->set_manage_stock($manage_stock);
        $variation->set_stock_quantity($manage_stock ? $stock_quantity : null);
        $variation->set_stock_status($stock_status);
        $variation->set_weight($weight);
        $variation->set_length($length);
        $variation->set_width($width);
        $variation->set_height($height);
        $variation->set_virtual($virtual);
        $variation->set_downloadable($downloadable);
        $variation->set_downloads($downloads);
        $variation->set_download_limit($download_limit);
        $variation->set_download_expiry($download_expiry);
        $variation->set_image_id($image_id);

        if (!empty($sku)) {
            $variation->set_sku($sku . '-var');
            $variable->set_sku('');
        }

        $variation->save();
        $variable->save();

        wc_delete_product_transients($product_id);

        do_action('wpml_sync_custom_field', '_product_attributes', $product_id);
        do_action('wpml_sync_custom_field', '_default_attributes', $product_id);

        return true;
    }
}
