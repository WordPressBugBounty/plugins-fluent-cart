<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\Api\Resource\ProductResource;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;

class MediaCarouselBlockEditor extends BlockEditor
{
    protected static string $editorName = 'media-carousel';

    public function supports(): array
    {
        return [
            'html'       => false,
            'align' => true,
            'typography' => [
                'fontSize'   => true,
                'lineHeight' => true
            ],
            'spacing'    => [
                'margin' => true
            ],
            'color'      => [
                'text' => true,
            ]
        ];
    }

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/MediaCarousel/MediaCarouselBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'admin/BlockEditor/MediaCarousel/style/media-carousel-block-editor.scss'
        ];
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Media Carousel', 'fluent-cart'),
                'description'       => __('This block will display the media carousel.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg')
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        AssetLoader::loadSingleProductAssets();

        $product = null;
        $insideProductInfo = Arr::get($shortCodeAttribute, 'inside_product_info', 'no');
        $queryType = Arr::get($shortCodeAttribute, 'query_type', 'default');
        $carouselSettings = Arr::get($shortCodeAttribute, 'carouselSettings', []);
        
        if ($queryType === 'default') {
            $product = fluent_cart_get_current_product();

        } else {
            $productId = Arr::get($shortCodeAttribute, 'product_id', null);
            $variationIds = Arr::get($shortCodeAttribute, 'variation_ids', []);

            if ($productId) {
                $product = ProductResource::findByProductAndVariants([
                    'product_id'  => $productId,
                    'variant_ids' => $variationIds
                ]);
            }
        }

        if (!$product) {
            return '';
        }

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

        wp_enqueue_style(
            'fluentcart-product-media-carousel-css',
            Vite::getAssetUrl('public/carousel/product-media/style/product-media-carousel.scss'),
            [],
            null
        );

        wp_enqueue_script(
            'fluentcart-product-media-carousel',
            Vite::getAssetUrl('public/carousel/product-media/product-media-carousel.js'),
            [],
            null,
            true
        );

        ob_start();
        (new ProductRenderer($product))->renderCarousel([
            'carouselSettings' => $carouselSettings
        ]);
        return ob_get_clean();
    }
}
