<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;
use Bricks\Helpers;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Modules\Templating\Bricks\BricksLoader;
use FluentCart\App\Modules\Templating\Bricks\BricksHelper;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Services\Renderer\ProductRenderer;

class ProductContent extends Element
{
    public $category = 'fluent-cart';
    public $name = 'fct-product-content';
    public $icon = 'ion-md-list-box';

    public function get_label()
    {
        return esc_html__('Product Content (FluentCart)', 'fluent-cart');
    }

    public function set_controls()
    {
        $edit_link = Helpers::get_preview_post_link(get_the_ID());
        $label = esc_html__('Edit product content in FluentCart.', 'fluent-cart');

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

        $this->controls['info'] = [
            'tab'     => 'content',
            'type'    => 'info',
            'content' => $edit_link ? '<a href="' . esc_url($edit_link) . '" target="_blank">' . $label . '</a>' : $label,
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
                'title' => esc_html__('Select a product', 'fluent-cart-bricks-blocks'),
            ]);
        }

        $content = $product->post_content;
       

        if (!$content) {
            return $this->render_element_placeholder([
                'title' => esc_html__('Product content is empty.', 'fluent-cart'),
            ]);
        }

        $content = $this->render_dynamic_data($content);
        $content = Helpers::parse_editor_content($content);
        $content = str_replace(']]>', ']]&gt;', $content);

        ?>
        <div
            <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping
                echo $this->render_attributes( '_root' );
            ?>
        >
            <?php echo wp_kses($content, BricksHelper::getAllowedHtmlForContent()); ?>
        </div>

        <?php
    }
}
