<?php

namespace FluentCart\App\Helpers;

use FluentCart\App\App;
use FluentCart\App\Models\OrderOperation;
use FluentCart\Framework\Support\Arr;

class UtmHelper
{

    public static function allowedUtmParameterKey(): array
    {
        $keys = [
            'utm_campaign',
            'utm_content',
            'utm_term',
            'utm_source',
            'utm_medium',
            'utm_id',
            'refer_url',
            'fbclid',
            'gclid'
        ];

        return apply_filters('fluent_cart/utm/allowed_keys', $keys, []);
    }

    /**
     * Hostnames that belong to the same store network (e.g. child/product sites
     * that redirect visitors here for checkout). Referrers from these hosts are
     * treated as internal navigation and never recorded as refer_url — the real
     * source arrives as query params (refer_url, utm_*) appended by the child site.
     */
    public static function getInternalDomains(): array
    {
        $domains = apply_filters('fluent_cart/utm/internal_domains', []);

        if (!is_array($domains)) {
            return [];
        }

        $hosts = [];
        foreach ($domains as $domain) {
            if (!is_string($domain) || !$domain) {
                continue;
            }
            $domain = strtolower(trim($domain));
            if (strpos($domain, '//') !== false) {
                $domain = (string)wp_parse_url($domain, PHP_URL_HOST);
            }
            $domain = trim($domain, '/');
            if ($domain) {
                $hosts[] = $domain;
            }
        }

        return array_values(array_unique($hosts));
    }

    public static function addUtmToOrder($orderId, $data = [])
    {
        $directValueKeys = [
            'utm_campaign',
            'utm_content',
            'utm_term',
            'utm_source',
            'utm_medium',
            'utm_id',
            'refer_url',
        ];

        $directValues = Arr::only($data, $directValueKeys);
        $metaValues = Arr::except($data, $directValueKeys);

        foreach ($directValues as $key => $value) {
            if ($key == 'refer_url') {
                $directValues[$key] = self::normalizeReferUrl($value);
            } else {
                $directValues[$key] = sanitize_text_field($value);
            }
        }

        $allowedKeys = self::allowedUtmParameterKey();
        $allowedMetaValues = [];
        foreach ($metaValues as $key => $value) {
            if (!in_array($key, $allowedKeys)) {
                continue;
            }
            $allowedMetaValues[$key] = sanitize_text_field($value);
        }

        $directValues['meta'] = $allowedMetaValues;

        $hasValues = array_filter($directValues) || array_filter($allowedMetaValues);


        $oldOperation = OrderOperation::query()->where('order_id', $orderId)->first();

        if (empty($oldOperation)) {
            OrderOperation::query()->create(
                array_merge($directValues, ['order_id' => $orderId])
            );
        } else {
            $directValues['meta'] = Arr::mergeMissingValues($directValues['meta'], $oldOperation->meta);
            Arr::mergeMissingValues($directValues, Arr::except($oldOperation->toArray(), 'meta'));
            $directValues = array_merge($directValues);
            $oldOperation->update($directValues);
        }
    }

    /**
     * Reduce a referrer (full URL or bare host) to its bare domain:
     * no scheme, no www. prefix, no path — e.g. "google.com"
     */
    public static function normalizeReferUrl($value): string
    {
        $value = trim((string)$value);
        if (!$value) {
            return '';
        }

        if (strpos($value, '//') !== false) {
            $host = wp_parse_url($value, PHP_URL_HOST);
            if ($host) {
                $value = $host;
            }
        } else {
            $value = explode('/', $value)[0];
        }

        $value = strtolower($value);
        if (strpos($value, 'www.') === 0) {
            $value = substr($value, 4);
        }

        return sanitize_text_field($value);
    }

    public static function getUtmDataOfRequest(): array
    {
        $requestData = App::request()->all();
        $requestUtmData = Arr::get($requestData, 'utm_data', []);
        $sanitizedUtmData = [];
        // Sanitize UTM data
        foreach ($requestUtmData as $utmKey => $utmValue) {
            $sanitizedKey = sanitize_text_field($utmKey);
            $sanitizedUtmData[$sanitizedKey] = sanitize_text_field($utmValue);
        }

        return $sanitizedUtmData;
    }

}