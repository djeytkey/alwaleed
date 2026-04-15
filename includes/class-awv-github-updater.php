<?php

if (!defined('ABSPATH')) {
    exit;
}

final class AWV_GitHub_Updater
{
    private const CACHE_KEY = 'awv_github_latest_release';
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    public static function boot(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject_update'], 20);
        add_filter('plugins_api', [self::class, 'plugins_api'], 20, 3);
        add_filter('upgrader_post_install', [self::class, 'fix_plugin_folder'], 10, 3);
    }

    public static function inject_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        if (empty($transient->checked) || !isset($transient->checked[AWV_PLUGIN_BASENAME])) {
            return $transient;
        }

        $release = self::get_latest_release();
        if (!$release) {
            return $transient;
        }

        $remote_version = ltrim((string) ($release['tag_name'] ?? ''), 'v');
        $package_url = self::find_package_url($release);

        if ($remote_version === '' || $package_url === '') {
            return $transient;
        }

        if (version_compare(AWV_PLUGIN_VERSION, $remote_version, '>=')) {
            return $transient;
        }

        $transient->response[AWV_PLUGIN_BASENAME] = (object) [
            'slug' => dirname(AWV_PLUGIN_BASENAME),
            'plugin' => AWV_PLUGIN_BASENAME,
            'new_version' => $remote_version,
            'url' => 'https://github.com/' . AWV_GITHUB_REPOSITORY,
            'package' => $package_url,
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
        ];

        return $transient;
    }

    public static function plugins_api($result, string $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== dirname(AWV_PLUGIN_BASENAME)) {
            return $result;
        }

        $release = self::get_latest_release();
        if (!$release) {
            return $result;
        }

        $remote_version = ltrim((string) ($release['tag_name'] ?? ''), 'v');
        $package_url = self::find_package_url($release);

        return (object) [
            'name' => 'Alwaleed products',
            'slug' => dirname(AWV_PLUGIN_BASENAME),
            'version' => $remote_version !== '' ? $remote_version : AWV_PLUGIN_VERSION,
            'author' => '<a href="https://github.com/djeytkey">Alwaleed</a>',
            'homepage' => 'https://github.com/' . AWV_GITHUB_REPOSITORY,
            'download_link' => $package_url,
            'requires' => '6.4',
            'requires_php' => '7.4',
            'sections' => [
                'description' => __('Converts WooCommerce simple products to variable products and supports WPML-friendly conversion.', 'alwaleed-simple-to-variable'),
                'changelog' => wp_kses_post((string) ($release['body'] ?? '')),
            ],
        ];
    }

    public static function fix_plugin_folder(bool $response, array $hook_extra, array $result): bool
    {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== AWV_PLUGIN_BASENAME) {
            return $response;
        }

        if (empty($result['destination']) || empty($result['local_destination'])) {
            return $response;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            return $response;
        }

        $proper_destination = trailingslashit(WP_PLUGIN_DIR) . dirname(AWV_PLUGIN_BASENAME);
        if ($result['destination'] === $proper_destination) {
            return $response;
        }

        if ($wp_filesystem->is_dir($proper_destination)) {
            $wp_filesystem->delete($proper_destination, true);
        }

        $move_result = $wp_filesystem->move($result['destination'], $proper_destination);
        if (!$move_result) {
            return $response;
        }

        $result['destination'] = $proper_destination;
        return $response;
    }

    private static function get_latest_release(): ?array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(
            AWV_GITHUB_API_URL,
            [
                'timeout' => 20,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return null;
        }

        set_transient(self::CACHE_KEY, $data, self::CACHE_TTL);
        return $data;
    }

    private static function find_package_url(array $release): string
    {
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!is_array($asset) || empty($asset['name']) || empty($asset['browser_download_url'])) {
                    continue;
                }

                if ((string) $asset['name'] === AWV_GITHUB_RELEASE_ASSET) {
                    return (string) $asset['browser_download_url'];
                }
            }
        }

        return (string) ($release['zipball_url'] ?? '');
    }
}
