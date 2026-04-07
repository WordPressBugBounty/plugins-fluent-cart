<?php

namespace FluentCart\App\Models;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\Framework\Database\Orm\Relations\HasMany;

/**
 * Shipping Zone Model - DB Model for Shipping Zones
 *
 * @package FluentCart\App\Models
 * @version 1.0.0
 */
class ShippingZone extends Model
{
    use CanSearch;

    protected $table = 'fct_shipping_zones';

    protected $appends = ['formatted_region'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'region',
        'meta',
        'order'
    ];

    /**
     * Get the shipping methods for this zone.
     */
    public function methods(): HasMany
    {
        return $this->hasMany(ShippingMethod::class, 'zone_id', 'id')
            ->orderBy('id', 'DESC');
    }

    public function setMetaAttribute($value)
    {
        if ($value && is_array($value)) {
            $this->attributes['meta'] = json_encode($value);
        } else {
            $this->attributes['meta'] = '{}';
        }
    }

    public function getMetaAttribute($value)
    {
        if (!$value || $value === '{}') {
            return null;
        }

        return json_decode($value, true);
    }

    public function getFormattedRegionAttribute()
    {
        if ($this->region === 'all') {
            return __('Whole World', 'fluent-cart');
        }

        if ($this->region === 'selection') {
            $meta = $this->meta;
            $countries = isset($meta['countries']) ? $meta['countries'] : [];
            $selectionType = isset($meta['selection_type']) ? $meta['selection_type'] : 'included';

            if (empty($countries)) {
                return '';
            }

            $names = array_map(function ($code) {
                return AddressHelper::getCountryNameByCode($code);
            }, array_slice($countries, 0, 5));

            $label = implode(', ', $names);
            if (count($countries) > 5) {
                $label .= sprintf(' +%d more', count($countries) - 5);
            }

            if ($selectionType === 'excluded') {
                $label = sprintf(__('All except: %s', 'fluent-cart'), $label);
            }

            return $label;
        }

        if (!empty($this->region)) {
            return AddressHelper::getCountryNameByCode($this->region);
        }

        return '';
    }

    /**
     * Check if this zone applies to a given country code.
     */
    public function appliesToCountry(string $country): bool
    {
        if ($this->region === 'all') {
            return true;
        }

        if ($this->region === 'selection') {
            $meta = $this->meta;
            $countries = isset($meta['countries']) ? $meta['countries'] : [];
            $selectionType = isset($meta['selection_type']) ? $meta['selection_type'] : 'included';

            if ($selectionType === 'excluded') {
                return !in_array($country, $countries);
            }

            return in_array($country, $countries);
        }

        // Legacy: direct country code match
        return $this->region === $country;
    }
}
