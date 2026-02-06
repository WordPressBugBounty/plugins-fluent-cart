<?php

namespace FluentCart\App\Services\PluginInstaller;

class BackgroundInstaller
{

    public function installPlugin($pluginSlug)
    {
        $plugin = [
            'name'      => $pluginSlug,
            'repo-slug' => $pluginSlug,
            'file'      => $pluginSlug . '.php'
        ];

        try {
            $this->backgroundInstaller($plugin);
        } catch (\Exception $exception) {
            return new \WP_Error('plugin_install_error', $exception->getMessage());
        }

        return true;
    }

    private function backgroundInstaller($plugin_to_install)
    {
        if (!empty($plugin_to_install['repo-slug'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            WP_Filesystem();

            $skin = new \Automatic_Upgrader_Skin();
            $upgrader = new \WP_Upgrader($skin);
            $installed_plugins = array_keys(\get_plugins());
            $plugin_slug = $plugin_to_install['repo-slug'];
            $plugin_file = isset($plugin_to_install['file']) ? $plugin_to_install['file'] : $plugin_slug . '.php';
            $installed = false;
            $activate = false;

            // See if the plugin is installed already.
            if (isset($installed_plugins[$plugin_file])) {
                $installed = true;
                $activate = !is_plugin_active($installed_plugins[$plugin_file]);
            }

            // Install this thing!
            if (!$installed) {
                // Suppress feedback.
                ob_start();

                try {
                    $plugin_information = plugins_api(
                        'plugin_information',
                        array(
                            'slug'   => $plugin_slug,
                            'fields' => array(
                                'short_description' => false,
                                'sections'          => false,
                                'requires'          => false,
                                'rating'            => false,
                                'ratings'           => false,
                                'downloaded'        => false,
                                'last_updated'      => false,
                                'added'             => false,
                                'tags'              => false,
                                'homepage'          => false,
                                'donate_link'       => false,
                                'author_profile'    => false,
                                'author'            => false,
                            ),
                        )
                    );

                    if (is_wp_error($plugin_information)) {
                        throw new \Exception(wp_kses_post($plugin_information->get_error_message()));
                    }

                    $package = $plugin_information->download_link;
                    $download = $upgrader->download_package($package);

                    if (is_wp_error($download)) {
                        throw new \Exception(wp_kses_post($download->get_error_message()));
                    }

                    $working_dir = $upgrader->unpack_package($download, true);

                    if (is_wp_error($working_dir)) {
                        throw new \Exception(wp_kses_post($working_dir->get_error_message()));
                    }

                    $result = $upgrader->install_package(
                        array(
                            'source'                      => $working_dir,
                            'destination'                 => WP_PLUGIN_DIR,
                            'clear_destination'           => false,
                            'abort_if_destination_exists' => false,
                            'clear_working'               => true,
                            'hook_extra'                  => array(
                                'type'   => 'plugin',
                                'action' => 'install',
                            ),
                        )
                    );

                    if (is_wp_error($result)) {
                        throw new \Exception(wp_kses_post($result->get_error_message()));
                    }

                    $activate = true;

                } catch (\Exception $e) {
                    throw new \Exception(esc_html($e->getMessage()));
                }

                // Discard feedback.
                ob_end_clean();
            }

            wp_clean_plugins_cache();

            // Activate this thing.
            if ($activate) {
                try {
                    $result = activate_plugin($installed ? $installed_plugins[$plugin_file] : $plugin_slug . '/' . $plugin_file);

                    if (is_wp_error($result)) {
                        throw new \Exception(esc_html($result->get_error_message()));
                    }
                } catch (\Exception $e) {
                    throw new \Exception(esc_html($e->getMessage()));
                }
            }
        }
    }

    public function installFromGithub($githubUrl, $pluginSlug)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        WP_Filesystem();

        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \WP_Upgrader($skin);

        ob_start();

        $download = null;

        try {
            if (strpos($githubUrl, '/releases/latest') !== false) {
                $downloadUrl = $this->getLatestReleaseDownloadUrl($githubUrl);
                if (is_wp_error($downloadUrl)) {
                    throw new \Exception($downloadUrl->get_error_message());
                }
                $githubUrl = $downloadUrl;
            }


            $download = $upgrader->download_package($githubUrl);


            if (is_wp_error($download)) {
                throw new \Exception(wp_kses_post($download->get_error_message()));
            }

            $working_dir = $upgrader->unpack_package($download, true);

            if (is_wp_error($working_dir)) {
                throw new \Exception(wp_kses_post($working_dir->get_error_message()));
            }


            // $source_files = glob($working_dir . '/*');

            // if (count($source_files) === 1 && is_dir($source_files[0])) {
            //     // Single subdirectory found, use it as the actual source
            //     $working_dir = $source_files[0];
            // }

            $result = $upgrader->install_package(
                array(
                    'source'                      => $working_dir,
                    'destination'                 => WP_PLUGIN_DIR . '/' . $pluginSlug,
                    'clear_destination'           => false,
                    'abort_if_destination_exists' => false,
                    'clear_working'               => true,
                    'hook_extra'                  => array(
                        'type'   => 'plugin',
                        'action' => 'install',
                    ),
                )
            );

            if (is_wp_error($result)) {
                throw new \Exception(wp_kses_post($result->get_error_message()));
            }

            ob_end_clean();

            wp_clean_plugins_cache();

            $plugin_file = $pluginSlug . '/' . $pluginSlug . '.php';
            $activate_result = activate_plugin($plugin_file);

            if (is_wp_error($activate_result)) {
                throw new \Exception(esc_html($activate_result->get_error_message()));
            };

            return true;

        } catch (\Exception $e) {
            ob_end_clean();

            return new \WP_Error('installation_failed', $e->getMessage());
        }
    }

    private function getLatestReleaseDownloadUrl($releasesUrl)
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


        if (empty($data['zipball_url'])) {
            return new \WP_Error('no_release', 'No release found. Please ensure the repository has published releases.');
        }

        return $data['zipball_url'];
    }
}
