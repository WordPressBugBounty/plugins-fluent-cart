<?php

namespace FluentCart\App\Services\PluginInstaller;

class PaymentAddonManager
{
    public static function getAddonStatus($pluginSlug, $pluginFile)
    {
        return (new AddonManager())->getAddonStatus($pluginSlug, $pluginFile);
    }


    public function installAddon($sourceType, $sourceLink, $pluginSlug)
    {
        return (new AddonManager())->installAddon($sourceType, $sourceLink, $pluginSlug);
    }


    public function activateAddon($pluginFile)
    {
        return (new AddonManager())->activateAddon($pluginFile);

    }

    /**
     * Check for updates based on source type
     * 
     * @param string $sourceType Source type (github, wordpress, other)
     * @param string $sourceLink Source URL (for github, other sources)
     * @param string $pluginFile Plugin file path
     * @param string $pluginSlug Plugin slug (for wordpress)
     * @return array|\WP_Error
     */
    public function checkForUpdate($sourceType, $sourceLink, $pluginFile, $pluginSlug = '')
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Get current plugin version
        $pluginPath = WP_PLUGIN_DIR . '/' . $pluginFile;
        if (!file_exists($pluginPath)) {
            return new \WP_Error('plugin_not_found', __('Plugin file not found.', 'fluent-cart'));
        }

        $pluginData = get_plugin_data($pluginPath);
        $currentVersion = $pluginData['Version'] ?? '1.0.0';

        // Route to appropriate update check method
        switch ($sourceType) {
            case 'github':
                return $this->checkUpdateFromGitHub($sourceLink, $currentVersion);
                
            case 'wordpress':
                return $this->checkUpdateFromWordPress($pluginFile, $currentVersion);
                
            default:
                return $this->checkUpdateFromOther($sourceType, $sourceLink, $currentVersion);
        }
    }

  
    /**
     * Check for updates from GitHub
     * 
     * @param string $sourceLink releases/latest URL
     * @param string $currentVersion Current plugin version
     * @return array|\WP_Error
     */
    private function checkUpdateFromGitHub($sourceLink, $currentVersion)
    {
        if (!$sourceLink) {
            return new \WP_Error('invalid_url', __('Source link is required', 'fluent-cart'));
        }
        // Check GitHub for latest version
        $latestVersionInfo = $this->getLatestGitHubVersion($sourceLink);
        
        if (is_wp_error($latestVersionInfo)) {
            return $latestVersionInfo;
        }

        $latestVersion = $latestVersionInfo['version'];
        $hasUpdate = version_compare($latestVersion, $currentVersion, '>');

        return [
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'has_update' => $hasUpdate,
            'download_url' => $latestVersionInfo['download_url'],
            'release_notes' => $latestVersionInfo['release_notes'] ?? '',
            'source_type' => 'github'
        ];
    }


    private function checkUpdateFromWordPress($pluginFile, $currentVersion)
    {
        // Force refresh of update transients
        wp_update_plugins();
        
        // Get available updates
        $update_plugins = get_site_transient('update_plugins');
        
        if (!$update_plugins || !isset($update_plugins->response)) {
            return new \WP_Error('no_updates', __('Unable to check for updates from WordPress.org', 'fluent-cart'));
        }

        // Check if this plugin has an update available
        if (isset($update_plugins->response[$pluginFile])) {
            $update = $update_plugins->response[$pluginFile];
            
            return [
                'current_version' => $currentVersion,
                'latest_version' => $update->new_version,
                'has_update' => true,
                'download_url' => $update->package ?? '',
                'release_notes' => $update->upgrade_notice ?? '',
                'source_type' => 'wordpress'
            ];
        }

        // No update available
        return [
            'current_version' => $currentVersion,
            'latest_version' => $currentVersion,
            'has_update' => false,
            'download_url' => '',
            'release_notes' => '',
            'source_type' => 'wordpress'
        ];
    }

    /**
     * Check for updates from other sources (placeholder)
     * 
     * @param string $sourceType Source type
     * @param string $currentVersion Current plugin version
     * @return array|\WP_Error
     */
    private function checkUpdateFromOther($sourceType, $sourceLink, $currentVersion)
    {
        return new \WP_Error(
            'not_supported', 
            sprintf(
                __('Update check for source type "%s" is not currently available.', 'fluent-cart'),
                $sourceType
            )
        );
    }

    private function getLatestGitHubVersion($releasesUrl)
    {
        preg_match('#github\.com/([^/]+)/([^/]+)/releases#', $releasesUrl, $matches);
        
        if (empty($matches[1]) || empty($matches[2])) {
            return new \WP_Error('invalid_url', __('Invalid GitHub releases URL', 'fluent-cart'));
        }

        $owner = $matches[1];
        $repo = $matches[2];

        $api_url = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
        
        $response = wp_remote_get($api_url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'FluentCart/' . FLUENTCART_VERSION
            ]
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('api_error', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('api_error', 'Github API error with status code: ' . $code);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['tag_name']) || empty($data['zipball_url'])) {
            return new \WP_Error('no_release', 'No release found. Please ensure the repository has published releases.');
        }

        // Extract version number from tag (remove 'v' prefix if present)
        $version = ltrim($data['tag_name'], 'vV');

        return [
            'version' => $version,
            'download_url' => $data['zipball_url'],
            'release_notes' => $data['body'] ?? ''
        ];
    }

    /**
     * Update addon based on source type
     * 
     * @param string $sourceType Source type (github, wordpress, other)
     * @param string $sourceLink Source URL (for github)
     * @param string $pluginSlug Plugin slug
     * @param string $pluginFile Plugin file path
     * @return bool|\WP_Error
     */
    public function updateAddon($sourceType, $sourceLink, $pluginSlug, $pluginFile)
    {
        if (!current_user_can('update_plugins')) {
            return new \WP_Error('permission_denied', __('You do not have permission to update plugins.', 'fluent-cart'));
        }

        // Route to appropriate update method
        switch ($sourceType) {
            case 'github':
                return $this->updateFromGitHub($sourceLink, $pluginSlug, $pluginFile);
                
            case 'wordpress':
                return $this->updateFromWordPress($pluginFile);
                
            default:
                return $this->updateFromOther($sourceType);
        }
    }


    private function updateFromGitHub($sourceLink, $pluginSlug, $pluginFile)
    {
        $updateCheck = $this->checkUpdateFromGitHub($sourceLink, '0.0.0'); // Version will be fetched from file
        
        if (is_wp_error($updateCheck)) {
            return $updateCheck;
        }

        if (!$updateCheck['has_update']) {
            return new \WP_Error('no_update', __('Plugin is already up to date.', 'fluent-cart'));
        }

        // Deactivate plugin before updating
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        deactivate_plugins($pluginFile);

        // Download and prepare the new version from GitHub (download to temp location first)
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        WP_Filesystem();

        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \WP_Upgrader($skin);

        ob_start();

        try {
            $download = $upgrader->download_package($updateCheck['download_url']);
            
            if (is_wp_error($download)) {
                ob_end_clean();
                activate_plugin($pluginFile);
                return new \WP_Error('download_failed', $download->get_error_message());
            }

            $working_dir = $upgrader->unpack_package($download, true);

            if (is_wp_error($working_dir)) {
                ob_end_clean();
                activate_plugin($pluginFile);
                return new \WP_Error('unpack_failed', $working_dir->get_error_message());
            }

            // Now that download is successful, delete the old plugin directory
            $pluginDir = WP_PLUGIN_DIR . '/' . $pluginSlug;
            if (file_exists($pluginDir)) {
                global $wp_filesystem;
                if (!$wp_filesystem->delete($pluginDir, true)) {
                    ob_end_clean();
                    activate_plugin($pluginFile);
                    return new \WP_Error('delete_failed', __('Could not delete old plugin version.', 'fluent-cart'));
                }
            }

            // Install the new version
            $result = $upgrader->install_package(
                array(
                    'source'                      => $working_dir,
                    'destination'                 => WP_PLUGIN_DIR . '/' . $pluginSlug,
                    'clear_destination'           => false,
                    'abort_if_destination_exists' => false,
                    'clear_working'               => true,
                    'hook_extra'                  => array(
                        'type'   => 'plugin',
                        'action' => 'update',
                    ),
                )
            );

            if (is_wp_error($result)) {
                ob_end_clean();
                return new \WP_Error('install_failed', $result->get_error_message());
            }

            ob_end_clean();

            wp_clean_plugins_cache();

            // Activate the updated plugin
            $activate_result = activate_plugin($pluginFile);

            if (is_wp_error($activate_result)) {
                return new \WP_Error('activation_failed', $activate_result->get_error_message());
            }

            return true;

        } catch (\Exception $e) {
            ob_end_clean();
            activate_plugin($pluginFile);
            return new \WP_Error('update_failed', $e->getMessage());
        }
    }


    private function updateFromWordPress($pluginFile)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        // Check if plugin is currently active
        $was_active = is_plugin_active($pluginFile);

        // Deactivate plugin before updating
        if ($was_active) {
            deactivate_plugins($pluginFile, true); // true = silent
        }

        // Use WordPress native Plugin_Upgrader
        $skin = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);

        // Perform the upgrade
        $result = $upgrader->upgrade($pluginFile);

        if (is_wp_error($result)) {
            // Reactivate the old plugin if it was active and update failed
            if ($was_active) {
                activate_plugin($pluginFile, '', false, true); // true = silent
            }
            return $result;
        }

        if ($result === false) {
            // Reactivate the old plugin if it was active and update failed
            if ($was_active) {
                activate_plugin($pluginFile, '', false, true);
            }
            return new \WP_Error('update_failed', __('Plugin update failed.', 'fluent-cart'));
        }

        // Reactivate the plugin if it was active before
        if ($was_active) {
            $activate_result = activate_plugin($pluginFile, '', false, true);
            
            if (is_wp_error($activate_result)) {
                return new \WP_Error('activation_failed', $activate_result->get_error_message());
            }
        }

        return true;
    }


    private function updateFromOther($sourceType)
    {
        return new \WP_Error(
            'not_supported', 
            sprintf(
                __('Update for source type "%s" is not currently available.', 'fluent-cart'),
                $sourceType
            )
        );
    }
}

