<?php

namespace FluentCart\App\Services\PluginInstaller;

class AddonManager
{
    public function installAddon($sourceType, $sourceLink, $pluginSlug)
    {
        if (!current_user_can('install_plugins')) {
            return new \WP_Error('permission_denied', __('You do not have permission to install plugins.', 'fluent-cart'));
        }

        $backgroundInstaller = new BackgroundInstaller();
        if ($sourceType === 'wordpress') {
            $result = $backgroundInstaller->installPlugin($pluginSlug);
        } else if ($sourceType === 'github') {
            $result = $backgroundInstaller->installFromGithub($sourceLink, $pluginSlug);
        } else {
            return new \WP_Error('invalid_source', __('Invalid addon source type.', 'fluent-cart'));
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    public function activateAddon($pluginFile)
    {
        if (!current_user_can('activate_plugins')) {
            return new \WP_Error('permission_denied', __('You do not have permission to activate plugins.', 'fluent-cart'));
        }

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin($pluginFile);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    public function getAddonStatus($pluginSlug, $pluginFile)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $is_installed = isset($all_plugins[$pluginFile]);
        $is_active = is_plugin_active($pluginFile);

        return [
            'is_installed' => $is_installed,
            'is_active'    => $is_active,
            'plugin_slug'  => $pluginSlug,
            'plugin_file'  => $pluginFile
        ];
    }
}