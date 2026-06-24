<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class GroupBulkUpdateVariantRequest extends RequestGuard
{
    const MAX_VARIANTS_PER_REQUEST = 500;

    public function rules()
    {
        $variantIds = $this->get('variant_ids', []);
        if (is_array($variantIds)) {
            $variantIds = array_values(array_filter(array_map('absint', $variantIds)));
        }
        $skuRule = 'nullable|sanitizeText|maxLength:30';

        if (is_array($variantIds) && count($variantIds) === 1) {
            $excludeId = absint($variantIds[0]);
            $skuRule .= '|unique:fct_product_variations,sku,' . $excludeId;
        }

        return [
            'variant_ids' => 'required|array',
            'sku'         => $skuRule,
        ];
    }

    public function messages()
    {
        return [
            'variant_ids.required' => esc_html__('At least one variant ID is required.', 'fluent-cart'),
            'variant_ids.array'    => esc_html__('variant_ids must be an array.', 'fluent-cart'),
            'sku.maxLength'        => esc_html__('SKU may not be greater than 30 characters.', 'fluent-cart'),
            'sku.unique'           => esc_html__('The SKU must be unique.', 'fluent-cart'),
        ];
    }

    public function sanitize()
    {
        return [
            'variant_ids' => function ($value) {
                if (!is_array($value)) {
                    return [];
                }
                return array_slice(
                    array_values(array_filter(array_map('absint', $value))),
                    0,
                    self::MAX_VARIANTS_PER_REQUEST
                );
            },
            'sku' => function ($value) {
                return ($value !== null && $value !== '') ? sanitize_text_field($value) : null;
            },
        ];
    }
}
