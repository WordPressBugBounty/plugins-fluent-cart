<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class GroupBulkUpdateVariantRequest extends RequestGuard
{
    const MAX_VARIANTS_PER_REQUEST = 500;

    public function rules()
    {
        return [
            'variant_ids' => 'required|array',
        ];
    }

    public function messages()
    {
        return [
            'variant_ids.required' => esc_html__('At least one variant ID is required.', 'fluent-cart'),
            'variant_ids.array'    => esc_html__('variant_ids must be an array.', 'fluent-cart'),
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
        ];
    }
}
