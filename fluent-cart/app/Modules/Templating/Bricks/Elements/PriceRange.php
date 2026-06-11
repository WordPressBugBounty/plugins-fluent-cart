<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\Templating\Bricks\BricksLoader;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class PriceRange extends Element
{

    public $category = 'fluent-cart';
    public $name = 'fct-price-range';
    public $icon = 'ti-money';

    public function get_label()
    {
        return esc_html__('Price Range (FluentCart)', 'fluent-cart');
    }

    public function set_controls()
    {
        $this->controls['queryType'] = [
            'tab'      => 'content',
            'type'     => 'select',
            'label'    => esc_html__('Query Type', 'fluent-cart-bricks-blocks'),
            'options'  => [
                'default' => esc_html__('Default', 'fluent-cart-bricks-blocks'),
                'custom'  => esc_html__('Custom', 'fluent-cart-bricks-blocks'),
            ],
            'default'  => 'default',
            'inline'   => true,
        ];

        $this->controls['productId'] = [
            'tab'         => 'content',
            'type'        => 'select',
            'label'       => esc_html__('Product', 'fluent-cart-bricks-blocks'),
            'options'     => BricksLoader::getProductOptions(),
            'placeholder' => esc_html__('Select a product', 'fluent-cart-bricks-blocks'),
            'searchable'  => true,
            'rerender'    => true,
            'required'    => ['queryType', '=', 'custom'],
        ];

        $this->controls['manualProductId'] = [
            'tab'         => 'content',
            'type'        => 'text',
            'label'       => esc_html__('Manual Product ID', 'fluent-cart-bricks-blocks'),
            'description' => esc_html__('Use this if the product is not available in dropdown.', 'fluent-cart-bricks-blocks'),
            'required'    => [['queryType', '=', 'custom'], ['productId', '=', '']],
        ];

        $this->controls['hideNoRange'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Hide when max and mix is same', 'fluent-cart'),
            'type'    => 'checkbox'
        ];
        $this->controls['priceRangeTypography'] = [
            'tab'   => 'content',
            'label' => esc_html__('Price Range typography', 'fluent-cart'),
            'type'  => 'typography',
            'css'   => [
                [
                    'selector' => '.fct-price-range.fct-product-prices',
                    'property' => 'font',
                ],
            ],
        ];
    }

    public function render()
    {
        $settings = $this->settings;
        $queryType = $settings['queryType'] ?? 'default';

        if ($queryType === 'default') {
            $productId = $this->post_id;
        } else {
            $productId = !empty($settings['productId']) ? \intval($settings['productId']) : 0;
            $manualProductId = !empty($settings['manualProductId']) ? \intval($settings['manualProductId']) : 0;

            if (!$productId && $manualProductId) {
                $productId = $manualProductId;
            }
        }

        $product = ProductDataSetup::getProductModel($productId);

        if (!$product) {
            return $this->render_element_placeholder([
                'title' => esc_html__(
                    'Select a product',
                    'fluent-cart-bricks-blocks'
                ),
            ]);
        }

        if(Arr::get($settings, 'hideNoRange')) {
            $hasPriceRange = $product->detail->variation_type !== 'simple';
            if(!$hasPriceRange) {
                $min_price = $product->detail->min_price;
                $max_price = $product->detail->max_price;
                $hasPriceRange = $max_price && $max_price > $min_price;
            }
            if(!$hasPriceRange) {
                return $this->render_element_placeholder(
                    [
                        'description' => esc_html__('Product does not have min-max range', 'fluent-cart'),
                    ]
                );
            }
        }

        ?>
        <div
            <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping internally
                echo $this->render_attributes( '_root' );
            ?>
        >
            <?php (new ProductRenderer($product))->renderPrices(); ?>
        </div>

        <?php
    }
}
