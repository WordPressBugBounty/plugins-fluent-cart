<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\Http\Requests\ProductVariationRequest;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class ProductVariationController extends Controller
{
    public function index(Request $request): array
    {
        // 

        $parameters = $request->get('params');
        $variants = ProductVariationResource::get($parameters);

        return [
            'variants' => $variants['variants'],
        ];
    }

    public function find(Request $request, ProductVariation $product): array
    {
        //
    }

    public function create(ProductVariationRequest $request)
    {

        $data = $request->getSafe($request->sanitize());
        $productId = Arr::get($data, 'variants.post_id');


        $product = Product::query()->with('detail')->findOrFail($productId);

        $variationData = Arr::get($data, 'variants', []);
        $variationData['other_info']['is_bundle_product'] = $product->isBundleProduct()?'yes':'no';
        $variationData['detail_id'] = Arr::get($product, 'detail.id', null);

        $isCreated = ProductVariationResource::create($variationData);

        if (is_wp_error($isCreated)) {
            return $isCreated;
        }
        return $this->response->sendSuccess($isCreated);
    }

    public function update(ProductVariationRequest $request, $variantId)
    {

        $data = $request->getSafe($request->sanitize());

        $productId = Arr::get($data, 'variants.post_id');

        $product = Product::query()->with('detail')->findOrFail($productId);

        $isUpdated = ProductVariationResource::update(
            Arr::get($data, 'variants', []), 
            $variantId, 
            [
                'detail_id' => Arr::get($product, 'detail.id', null)
            ]);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function delete(Request $request, $variantId)
    {

        $isDeleted = ProductVariationResource::delete($variantId);

        if (is_wp_error($isDeleted)) {
            return $isDeleted;
        }
        return $this->response->sendSuccess($isDeleted);
    }

    public function setMedia(Request $request, $variantId)
    {

        $data = $request->getSafe([
            'media.*.id'    => 'intval',
            'media.*.title' => 'sanitize_text_field',
            'media.*.url'   => function ($value) {
                if (empty($value)) {
                    return '';
                }

                return sanitize_url($value);
            },
        ]);
        $isSetMedia = ProductVariationResource::setImage(Arr::get($data, 'media', []), $variantId);

        if (is_wp_error($isSetMedia)) {
            return $isSetMedia;
        }
        return $this->response->sendSuccess($isSetMedia);
    }

    public function updatePricingTable(Request $request, $variantId)
    {

        // Use sanitize_textarea_field to retain newlines
        $data['description'] = sanitize_textarea_field($request->get('description'));

        $isUpdated = ProductVariationResource::updatePricingTable($data, $variantId);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }
}
