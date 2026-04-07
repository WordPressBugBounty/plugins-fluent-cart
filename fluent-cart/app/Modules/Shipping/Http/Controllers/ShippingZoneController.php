<?php

namespace FluentCart\App\Modules\Shipping\Http\Controllers;

use FluentCart\App\App;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\ShippingZone;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Modules\Shipping\Services\Filter\ShippingZoneFilter;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\Shipping\Http\Requests\ShippingMethodRequest;
use FluentCart\App\Modules\Shipping\Http\Requests\ShippingZoneRequest;

class ShippingZoneController extends Controller
{
    public function index(Request $request)
    {
        return $this->sendSuccess([
            'shipping_zones' => ShippingZoneFilter::fromRequest($request)->paginate()
        ]);
    }

    public function store(ShippingZoneRequest $request)
    {
        $data = $request->getSafe($request->sanitize());
        $data['meta'] = $this->buildMeta([], Arr::get($data, 'meta'), Arr::get($data, 'region'));

        $shippingZone = ShippingZone::query()->create($data);

        return $this->sendSuccess([
            'shipping_zone' => $shippingZone,
            'message'       => __('Shipping zone has been created successfully', 'fluent-cart')
        ]);
    }

    public function show($id)
    {
        $shippingZone = ShippingZone::with('methods')->findOrFail($id);

        return $this->sendSuccess([
            'shipping_zone' => $shippingZone
        ]);
    }

    public function update(ShippingZoneRequest $request, $id)
    {
        $data = $request->getSafe($request->sanitize());
        $shippingZone = ShippingZone::query()->findOrFail($id);

        $regionChanged = Arr::get($data, 'region') !== $shippingZone->region;
        if (!$regionChanged && Arr::get($data, 'region') === 'selection') {
            $oldCountries = Arr::get($shippingZone->meta, 'countries', []);
            $newCountries = Arr::get($data, 'meta.countries', []);
            $regionChanged = $oldCountries !== $newCountries;
        }

        if ($regionChanged) {
            ShippingMethod::where('zone_id', $id)->update(['states' => []]);
        }

        $existingMeta = is_array($shippingZone->meta) ? $shippingZone->meta : [];
        $data['meta'] = $this->buildMeta($existingMeta, Arr::get($data, 'meta'), Arr::get($data, 'region'));

        $shippingZone->update($data);

        return $this->sendSuccess([
            'shipping_zone' => $shippingZone,
            'message'       => __('Shipping zone has been updated successfully', 'fluent-cart'),
        ]);
    }

    public function destroy($id)
    {
        $shippingZone = ShippingZone::findOrFail($id);

        // Delete associated shipping methods
        ShippingMethod::where('zone_id', $id)->delete();

        // Delete the shipping zone
        $shippingZone->delete();

        return $this->sendSuccess([
            'message' => __('Shipping zone has been deleted successfully', 'fluent-cart')
        ]);
    }

    public function updateOrder(Request $request)
    {
        $zones = $request->get('zones', []);

        if (!$zones || !is_array($zones)) {
            return $this->sendError([
                'message' => __('Invalid data provided', 'fluent-cart')
            ]);
        }

        foreach ($zones as $index => $zoneId) {
            ShippingZone::where('id', $zoneId)->update(['order' => $index]);
        }

        return $this->sendSuccess([
            'message' => __('Shipping zones order has been updated', 'fluent-cart')
        ]);
    }

    public function getCountriesByContinent()
    {
        $continents = LocalizationManager::getContinents();
        $allCountries = LocalizationManager::getCountries();

        $grouped = [];
        foreach ($continents as $code => $continent) {
            $countryCodes = Arr::get($continent, 'countries', []);
            $countries = [];
            foreach ($countryCodes as $countryCode) {
                if (isset($allCountries[$countryCode])) {
                    $countries[] = [
                        'code' => $countryCode,
                        'name' => $allCountries[$countryCode],
                    ];
                }
            }
            if (!empty($countries)) {
                $grouped[] = [
                    'code'      => $code,
                    'name'      => Arr::get($continent, 'name', $code),
                    'countries' => $countries,
                ];
            }
        }

        return $this->sendSuccess([
            'continents' => $grouped,
        ]);
    }

    public function getZoneStates(Request $request)
    {
        $country_code = sanitize_text_field(Arr::get($request->all(), 'country_code', ''));

        $countryInfo = LocalizationManager::getCountryInfoFromRequest(null, $country_code);

        return $this->sendSuccess([
            'data' => $countryInfo
        ]);
    }

    /**
     * Build meta array by merging new country selection data into existing meta.
     * For 'selection' regions, sets countries and selection_type.
     * For other regions, removes country selection keys but preserves any other meta.
     */
    private function buildMeta(array $existing, $incoming, $region): array
    {
        if ($region === 'selection' && is_array($incoming)) {
            $existing['countries'] = Arr::get($incoming, 'countries', []);
            $existing['selection_type'] = Arr::get($incoming, 'selection_type', 'included');
        } else {
            unset($existing['countries'], $existing['selection_type']);
        }

        return $existing;
    }
}
