<?php

namespace FluentCart\App\Modules\Shipping\Http\Requests;

use FluentCart\App\App;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Validator\ValidationException;

class ShippingZoneRequest extends RequestGuard
{

    public function beforeValidation()
    {
        $data = $this->all();
        $data['region'] = Arr::get($data, 'region', '');
        $data['order'] = Arr::get($data, 'order', '');

        // Handle multi-country selection
        if ($data['region'] === 'selection') {
            $meta = Arr::get($data, 'meta', []);
            $countries = Arr::get($meta, 'countries', []);
            $selectionType = Arr::get($meta, 'selection_type', 'included');

            $data['meta'] = [
                'countries'      => array_values(array_filter(array_map('sanitize_text_field', $countries))),
                'selection_type' => in_array($selectionType, ['included', 'excluded']) ? $selectionType : 'included',
            ];
        } else {
            $data['meta'] = null;
        }

        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {

        return [
            'name'   => 'required|string|maxLength:192',
            'region' => function ($attr, $value) {
                if ($value === 'all') {
                    $zone = \FluentCart\App\Models\ShippingZone::query()->where('region', 'all');
                    if ($this->id) {
                        $zone = $zone->where('id', '!=', $this->id);
                    }
                    $zone = $zone->first();
                    if ($zone) {
                        return __('Only one "Whole World" shipping zone is allowed.', 'fluent-cart');
                    }
                }

                if ($value === 'selection') {
                    $meta = Arr::get($this->all(), 'meta', []);
                    $countries = Arr::get($meta, 'countries', []);
                    if (empty($countries)) {
                        return __('Please select at least one country.', 'fluent-cart');
                    }
                }

                return null;
            },
            'order'  => 'nullable|integer',

        ];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'name.required'   => esc_html__('Shipping name is required.', 'fluent-cart'),
            'name.max'        => esc_html__('Shipping name cannot exceed 192 characters.', 'fluent-cart'),
            'region.required' => esc_html__('Shipping country region is required.', 'fluent-cart')
        ];
    }

    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'name'   => 'sanitize_text_field',
            'region' => 'sanitize_text_field',
            'meta'   => function ($value) {
                return $value; // Already sanitized in beforeValidation
            },
            'order'  => 'intval'
        ];
    }
}
