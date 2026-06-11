<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\Http\Requests\ProductVariationRequest;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\TaxClass;
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
        return [];
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

    public function updateTaxSettings(Request $request, $variantId)
    {
        $variant = ProductVariation::query()->find($variantId);

        if (!$variant) {
            return $this->sendError([
                'message' => __('Variant not found', 'fluent-cart')
            ]);
        }

        $taxExempt = sanitize_text_field($request->get('tax_exempt', 'no'));
        $taxClassSlug = sanitize_text_field($request->get('tax_class', ''));
        $otherInfo = $variant->other_info ?: [];

        if (!$taxClassSlug) {
            $taxClassSlug = sanitize_text_field(Arr::get($otherInfo, 'tax_class', 'standard'));
        }

        if (!$taxClassSlug) {
            $taxClassSlug = 'standard';
        }

        if (!TaxClass::query()->where('slug', $taxClassSlug)->exists()) {
            return $this->sendError([
                'message' => __('Invalid tax class', 'fluent-cart')
            ], 422);
        }

        $otherInfo['tax_exempt'] = $taxExempt === 'yes' ? 'yes' : 'no';
        $otherInfo['tax_class'] = $taxClassSlug;

        $variant->update([
            'other_info' => $otherInfo
        ]);

        return $this->sendSuccess([
            'message'        => $otherInfo['tax_exempt'] === 'yes'
                ? __('Variation is now tax exempt', 'fluent-cart')
                : __('Tax will be charged on this variation', 'fluent-cart'),
            'tax_exempt'     => $otherInfo['tax_exempt'],
            'tax_class'      => $otherInfo['tax_class'],
            'tax_class_slug' => $otherInfo['tax_class']
        ]);
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
