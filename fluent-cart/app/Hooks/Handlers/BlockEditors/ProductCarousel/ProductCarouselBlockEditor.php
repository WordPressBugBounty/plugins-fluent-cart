<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\ProductCarousel;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Handlers\BlockEditors\BlockEditor;
use FluentCart\App\Hooks\Handlers\BlockEditors\ProductCarousel\InnerBlocks\InnerBlocks;
use FluentCart\App\Hooks\Handlers\ShortCodes\ShopAppHandler;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class ProductCarouselBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-carousel';

    protected ?string $localizationKey = 'fluent_cart_product_carousel_block_editor_data';

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductCarousel/ProductCarouselBlockEditor.jsx',
                'dependencies' => [
                    'wp-blocks', 
                    'wp-components', 
                    'wp-block-editor',
                    'wp-data',
                    'wp-element',
                ]
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'admin/BlockEditor/ShopApp/style/shop-app-block-editor.css',
            'admin/BlockEditor/ProductInfo/style/product-info-block-editor.scss',
            'admin/BlockEditor/ProductCarousel/style/product-carousel-block-editor.scss'
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerInnerBlocks();
    }

    public function registerInnerBlocks()
    {
        InnerBlocks::register();
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()      => [
                'rest'               => Helper::getRestInfo(),
                'slug'               => $this->slugPrefix,
                'name'               => static::getEditorName(),
                'trans'              => TransStrings::getShopAppBlockEditorString(),
                'title'             => __('Product Carousel', 'fluent-cart'),
                'description'       => __('This block will display the product carousel.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg')
            ],
            'fluent_cart_block_editor_asset' => [
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
            ],
            'fluent_cart_block_translation'  => TransStrings::blockStrings(),
            'fluentCartCarouselVars' => [
                'defaultSlides' => 3,
                'spaceBetween' => 16,
                'breakpoints' => [
                    0    => ['slidesPerView' => 1],
                    768  => ['slidesPerView' => 2],
                    1024 => ['slidesPerView' => 3],
                    1280 => ['slidesPerView' => 4],
                ],
                'rtl' => is_rtl(),
            ],
        ];
    }

    public function provideContext(): array
    {
        // in which name the data will be received => which attr
        return [
            'fluent-cart/carousel_settings'       => 'carousel_settings',
            'fluent-cart/product_ids'            => 'product_ids',
        ];
    }

    public function render(array $shortCodeAttribute, $block = null, $content = null): string
    {
        AssetLoader::loadProductArchiveAssets();

        $app = fluentCart();
        $slug = $app->config->get('app.slug');

        Vite::enqueueStaticScript(
            $slug . '-fluentcart-swiper-js',
            'public/lib/swiper/swiper-bundle.min.js',
            [$slug . '-app',]
        );

        Vite::enqueueStaticStyle(
            $slug . '-fluentcart-swiper-css',
            'public/lib/swiper/swiper-bundle.min.css',
        );

        Vite::enqueueStyle(
                'fluentcart-product-carousel',
                'public/carousel/products/style/product-carousel.scss',
        );

        Vite::enqueueScript(
                'fluentcart-product-carousel',
                'public/carousel/products/product-carousel.js',
                []
        );

        $colors = Arr::get($shortCodeAttribute, 'colors', []);
        $filters = Arr::get($shortCodeAttribute, 'filters', []);
        $default_filters = Arr::get($shortCodeAttribute, 'default_filters', [
            'enabled' => false,
        ]);

        $allowOutOfStock = Arr::get($default_filters, 'enabled', false) === true &&
            Arr::get($default_filters, 'allow_out_of_stock', false) === true;

        $enableFilter = Arr::get($shortCodeAttribute, 'enable_filter', 0);

        $view = ("[" . ShopAppHandler::SHORT_CODE . "
            block_class='" . Arr::get($shortCodeAttribute, 'className', '') . "'
            per_page='" . Arr::get($shortCodeAttribute, 'per_page', 10) . "'
            order_type='" . Arr::get($shortCodeAttribute, 'order_type', 'DESC') . "'
            live_filter='" . Arr::get($shortCodeAttribute, 'live_filter', true) . "'
            view_mode='" . Arr::get($shortCodeAttribute, 'view_mode', '') . "'
            price_format='" . Arr::get($shortCodeAttribute, 'price_format', 'starts_from') . "'
            search_grid_size='" . Arr::get($shortCodeAttribute, 'search_grid_size', '') . "'
            product_grid_size='" . Arr::get($shortCodeAttribute, 'product_grid_size', '') . "'
            product_box_grid_size='" . Arr::get($shortCodeAttribute, 'product_box_grid_size', '') . "' 
            paginator='" . Arr::get($shortCodeAttribute, 'paginator', '') . "' 
            use_default_style='" . Arr::get($shortCodeAttribute, 'use_default_style', 1) . "' 
            enable_filter='" . $enableFilter . "'
            allow_out_of_stock='" . $allowOutOfStock . "' 
            enable_wildcard_filter='" . Arr::get($shortCodeAttribute, 'enable_wildcard_filter', 1) . "'
            " .
            (count($colors) ? "colors='" . (json_encode(Arr::get($shortCodeAttribute, 'colors', []))) . "'
            " : "") .
            "enable_wildcard_for_post_content='" . Arr::get($shortCodeAttribute, 'enable_wildcard_for_post_content', 0) . "'
            " .
            (count($filters) ? "filters='" . esc_attr((json_encode(Arr::get($shortCodeAttribute, 'filters', [])))) . "'
            " : "") .
            "default_filters='" . (json_encode($default_filters)) . "'
        ]");

        return $content;
    }
}
