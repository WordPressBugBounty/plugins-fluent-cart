<?php

namespace FluentCart\App\Modules\Templating\Bricks;

use Bricks\Elements;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;

class BricksLoader
{
    public function register()
    {
        if (!defined('BRICKS_VERSION')) {
            return;
        }

        add_action('init', [$this, 'loadElements'], 20);

        (new DynamicData())->register();

        add_action('wp_footer', [$this, 'addDynamicFieldClassesScript']);

        add_filter('fluent_cart/template/disable_taxonomy_fallback', function ($result) {
            $bricks_data = \Bricks\Database::get_template_data('archive');

            if ($bricks_data) {
                return true;
            }

            return $result;
        });

        add_filter('fluent_cart/products_views/preload_collection_bricks', [$this, 'preloadProductCollectionsAjax'], 10, 2);

    }

    public function loadElements()
    {
        $elements = [
            'ProductTitle'            => 'fct-product-title',
            'ProductShortDescription' => 'fct-product-short-description',
            'ProductContent'          => 'fct-product-content',
            'ProductStock'            => 'fct-product-stock',
            'PriceRange'              => 'fct-price-range',
            'BuySection'              => 'fct-product-buy-section',
            'ProductGallery'          => 'fct-product-gallery',
            'ProductsCollection'      => 'fct-products',
        ];

        foreach ($elements as $elementKey => $elementName) {
            $elementFile = FLUENTCART_PLUGIN_PATH . "app/Modules/Templating/Bricks/Elements/$elementKey.php";
            $className = "\\FluentCart\\App\\Modules\\Templating\\Bricks\\Elements\\$elementKey";
            Elements::register_element($elementFile, $elementName, $className);
        }

    }

    public function preloadProductCollectionsAjax($view, $args)
    {
        $products = $args['products'];
        $clientId = Arr::get($args, 'client_id', '');

        $settings = get_transient('fc_bx_collection_' . $clientId);
        if (!$settings) {
            return $view;
        }

        do_action('fluent_cart/bricks/rendering_ajax_collection');

        ob_start();
        $post_index = 1;

        foreach ($products as $product) {
            $post = get_post($product->ID);
            setup_postdata($post);
            BricksHelper::setFormCurrentPost($post);
            BricksHelper::renderCollectionCard($settings, $product, $post_index, $clientId);

            $post_index++;
        }
        wp_reset_postdata();

        return ob_get_clean();
    }

    public function addDynamicFieldClassesScript()
    {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const classMap = {
                'product-title': 'fct-dynamic-product-title',
                'product-image': 'fct-dynamic-product-image',
                'product-excerpt': 'fct-dynamic-product-excerpt',
                'product-price': 'fct-dynamic-product-price',
                'product-button': 'fct-dynamic-product-button'
            };

            const applyDynamicFieldClass = function(marker) {
                if (marker.hasAttribute('data-fct-field-class-applied')) {
                    return;
                }

                const className = classMap[marker.getAttribute('data-fct-field')];

                if (!className) {
                    marker.setAttribute('data-fct-field-class-applied', '1');
                    return;
                }

                let dynamicDiv = marker.closest('.dynamic');

                if (!dynamicDiv) {
                    dynamicDiv = marker.parentElement;
                    while (dynamicDiv && !dynamicDiv.classList.contains('dynamic')) {
                        dynamicDiv = dynamicDiv.parentElement;
                    }
                }

                if (dynamicDiv) {
                    dynamicDiv.classList.add(className);
                }

                marker.setAttribute('data-fct-field-class-applied', '1');
            };

            let scheduled = false;
            const pendingNodes = new Set();

            const processNode = function(node) {
                if (!node || node.nodeType !== 1) {
                    return;
                }

                const markers = node.matches('[data-fct-field]')
                    ? [node]
                    : node.querySelectorAll('[data-fct-field]');

                markers.forEach(applyDynamicFieldClass);
            };

            const scheduleNode = function(node) {
                pendingNodes.add(node);

                if (scheduled) {
                    return;
                }

                scheduled = true;
                requestAnimationFrame(function() {
                    pendingNodes.forEach(processNode);
                    pendingNodes.clear();
                    scheduled = false;
                });
            };

            document.querySelectorAll('[data-fluent-cart-shop-app-product-list]').forEach(function(productList) {
                processNode(productList);

                new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        mutation.addedNodes.forEach(scheduleNode);
                    });
                }).observe(productList, {
                    childList: true,
                    subtree: true
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Get product options for select controls.
     * Limits to 50 recent products for editor performance.
     * Users can search or use manual product ID for others.
     */
    public static function getProductOptions()
    {
        if (!class_exists('\FluentCart\App\Models\Product')) {
            return [];
        }

        $options = [];

        try {
            $products = Product::query()
                ->where('post_status', 'publish')
                ->orderBy('post_date', 'DESC')
                ->limit(50)
                ->get();

            foreach ($products as $product) {
                $options[$product->ID] = $product->post_title;
            }
        } catch (\Exception $e) {
            // Silently fail if models aren't available
        }

        return $options;
    }
}
