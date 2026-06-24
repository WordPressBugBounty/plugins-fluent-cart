<?php

namespace FluentCart\App\Helpers;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

/**
 * Helper for the store attribute library and per-item attribute snapshots.
 *
 * @package FluentCart\App\Helpers
 *
 * @version 1.0.0
 */
class AttributeHelper
{
    /**
     * Return the store attribute library, or a single group set by slug.
     *
     * Loads every attribute group (with its terms) ONCE per request into a
     * static cache, then serves all later calls from memory — so resolving
     * variant attributes for many cart/order items never re-queries the DB.
     *
     * Shape — groups keyed by slug; each group carries its meta plus every term
     * keyed by slug DIRECTLY on the group, so `color.red` resolves the term:
     *   [
     *     'color' => [
     *       'title' => 'Color',
     *       'slug'  => 'color',
     *       'type'  => 'color',
     *       'red'   => ['title' => 'Red',  'slug' => 'red',  'settings' => [...]],
     *       'blue'  => ['title' => 'Blue', 'slug' => 'blue', 'settings' => [...]],
     *     ],
     *   ]
     *
     * Note: term slugs share the group array with the reserved meta keys
     * `title`/`slug`/`type`; product attribute terms never use those slugs.
     *
     * @param string $attrSlug Group slug (e.g. 'color', 'size'). Empty = all.
     * @return array Single group set, the whole library keyed by group slug when
     *               $attrSlug is empty, or [] when the slug is not found.
     */
    public static function getStoreProductAttributeSet($attrSlug = '')
    {
        static $allAttributes = null;

        if ($allAttributes === null) {
            // Groups keyed by slug; each group merges its meta with every term
            // keyed by slug (flat), so `color.red` resolves the term directly.
            $allAttributes = AttributeGroup::query()
                ->with(['terms' => function ($query) {
                    $query->orderBy('serial', 'ASC');
                }])
                ->orderBy('serial', 'ASC')
                ->get()
                ->keyBy('slug')
                ->map(function ($group) {
                    $groupSettings = is_array($group->settings) ? $group->settings : [];

                    $terms = $group->terms->keyBy('slug')->map(function ($term) {
                        return [
                            'title'    => $term->title,
                            'slug'     => $term->slug,
                            'settings' => is_array($term->settings) ? $term->settings : [],
                        ];
                    })->toArray();

                    return array_merge([
                        'title' => $group->title,
                        'slug'  => $group->slug,
                        'type'  => Arr::get($groupSettings, 'type', 'options'),
                    ], $terms);
                })
                ->toArray();
        }

        if ($attrSlug) {
            return Arr::get($allAttributes, $attrSlug, []);
        }

        return $allAttributes;
    }

    /**
     * Build the `item_attributes` snapshot for a single cart/order line item.
     *
     * Stored at `other_info['item_attributes']` on cart_data items and order_items.
     * FluentCart's own attributes are keyed `pa_{group_slug}`; the value is the
     * term TITLE and slug is the term SLUG — frozen at the moment it is written so
     * later renames in the attribute library never rewrite past orders. Third-party
     * providers append their own entries (WITHOUT the `pa_` prefix) via the
     * `fluent_cart/item_attributes` filter — they supply their own group slug as
     * the key plus value/slug.
     *
     * Output shape:
     *   [
     *     'pa_color'             => ['value' => 'Red',  'slug' => 'red'],
     *     'pa_size'              => ['value' => 'XS',   'slug' => 'xs'],
     *     'fluent_booking_start' => ['value' => '2026-06-29 12:12:00', 'slug' => 'fluent_booking'],
     *   ]
     *
     * @param int $variationId Product variation id (order/cart item object_id).
     * @param int $productId   Owning product id (passed to the filter for context).
     * @return array
     */
    public static function getProductItemAttributes($variationId, $productId = 0)
    {
        $atts = [];

        $variationId = (int) $variationId;

        if ($variationId) {
            $relations = AttributeRelation::query()
                ->where('object_id', $variationId)
                ->with(['group', 'term'])
                ->get();

            foreach ($relations as $relation) {
                $group = $relation->group;
                $term  = $relation->term;

                if (!$group || !$term) {
                    continue;
                }

                // Our own attributes carry the `pa_` prefix on the group slug.
                $atts['pa_' . $group->slug] = [
                    'value' => $term->title,
                    'slug'  => $term->slug,
                ];
            }
        }

        // Third-party attributes are appended without the `pa_` prefix — providers
        // key by their own group slug and supply value/slug themselves.
        return apply_filters('fluent_cart/item_attributes', $atts, [
            'variation_id' => $variationId,
            'product_id'   => (int) $productId,
        ]);
    }

    /**
     * The store's attribute groups as a lightweight slug => label/type map.
     *
     * Sourced from the request-cached `getStoreProductAttributeSet()` registry,
     * so this is only the current (live) group labels — used to resolve the
     * display title of a frozen `pa_{slug}` snapshot entry.
     *
     * @return array e.g. ['color' => ['title' => 'Color', 'slug' => 'color', 'type' => 'color']]
     */
    public static function getMainAttributes()
    {
        $mainAtts = [];

        foreach (self::getStoreProductAttributeSet() as $slug => $group) {
            $mainAtts[$slug] = [
                'title' => Arr::get($group, 'title', $slug),
                'slug'  => Arr::get($group, 'slug', $slug),
                'type'  => Arr::get($group, 'type', 'options'),
            ];
        }

        return $mainAtts;
    }

    /**
     * Resolve a line item's stored `item_attributes` snapshot into display rows.
     *
     * FluentCart attributes (keyed `pa_{group_slug}`) are resolved against the
     * live group library for their display title; the term value/slug come from
     * the frozen snapshot. A `pa_*` entry whose group no longer exists is
     * skipped. Third-party (un-prefixed) entries are resolved through the
     * `fluent_cart/item_display_attr_{key}` filter so the owning plugin can
     * shape its own label/value.
     *
     * @param array  $itemAttributes other_info['item_attributes'] snapshot.
     * @param mixed  $item           Owning cart/order item (passed to filters).
     * @param string $scope          'cart' | 'order_item' (passed to filters).
     * @return array Keyed by attr key: ['display_title','attr_key','slug','display_value','is_system']
     */
    public static function getDisplayAttributes(array $itemAttributes, $item = null, $scope = 'cart')
    {
        if(!$itemAttributes) {
            return [];
        }

        $mainAtts    = self::getMainAttributes();
        $displayAtts = [];

        foreach ($itemAttributes as $key => $value) {
            if (strpos($key, 'pa_') === 0) {
                $groupSlug = substr($key, 3);

                // Group was removed from the library — drop the stale entry.
                if (!isset($mainAtts[$groupSlug])) {
                    $displayAtts[$key] = [
                        'display_title' => Str::of($groupSlug)->title(),
                        'attr_key'      => $key,
                        'slug'          => $key,
                        'display_value' => Arr::get($value, 'value', ''),
                        'is_system'     => true,
                    ];
                    continue;
                }

                $mainAtt = $mainAtts[$groupSlug];

                $displayAtts[$key] = [
                    'display_title' => $mainAtt['title'],
                    'attr_key'      => $key,
                    'slug'          => $mainAtt['slug'],
                    'display_value' => Arr::get($value, 'value', ''),
                    'is_system'     => true,
                ];

                continue;
            }

            // Third-party attribute — let the owning plugin shape the display row.
            $displayAtts[$key] = apply_filters('fluent_cart/item_display_attr_' . $key, [
                'display_title' => $key,
                'attr_key'      => $key,
                'slug'          => Arr::get($value, 'slug', $key),
                'display_value' => Arr::get($value, 'value', ''),
                'is_system'     => false,
            ], [
                'attr'  => $value,
                'item'  => $item,
                'scope' => $scope,
            ]);
        }

        $displayAtts = apply_filters('fluent_cart/item_display_attr', $displayAtts, [
            'item'  => $item,
            'scope' => $scope,
        ]);

        // Drop rows that resolved to an empty value (e.g. a provider opted out).
        return array_filter($displayAtts, function ($attr) {
            return !empty($attr['display_value']);
        });
    }

    /**
     * Render a line item's attributes as a single inline string.
     *
     * @param array  $itemAttributes other_info['item_attributes'] snapshot.
     * @param mixed  $item           Owning cart/order item (passed to filters).
     * @param string $scope          'cart' | 'order_item'.
     * @param string $separator      Glue between pairs (default ' | ').
     * @return string e.g. "Color: Red | Size: XS"
     */
    public static function getDisplayAttributesString(array $itemAttributes, $item = null, $scope = 'cart', $separator = ' | ')
    {
        $displayAtts = self::getDisplayAttributes($itemAttributes, $item, $scope);
        if(!$displayAtts || !is_array($displayAtts)) {
            return '';
        }

        $parts = [];
        foreach ($displayAtts as $attr) {
            $title = Arr::get($attr, 'display_title', '');
            $value = Arr::get($attr, 'display_value', '');
            // Skip the "Label: " prefix when the title is missing, otherwise we
            // would render a stray leading colon (": Red").
            $parts[] = $title !== '' ? $title . ': ' . $value : $value;
        }

        $string = implode($separator, $parts);

        // Let integrators render the combination in their own format — they get
        // the default string plus the resolved rows to rebuild from scratch.
        return apply_filters('fluent_cart/item_display_attr_string', $string, [
            'display_atts' => $displayAtts,
            'item'         => $item,
            'scope'        => $scope,
            'separator'    => $separator,
        ]);
    }
}
