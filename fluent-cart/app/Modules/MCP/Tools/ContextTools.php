<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Permission\PermissionManager;

/**
 * Discovery tools — the agent's entry point into a FluentCart store.
 *
 * `get-store-context` is the documented "call this first" tool. One call tells
 * the agent who it is, what it's allowed to do, the store's money/time
 * conventions, headline numbers, and every valid enum value — so it never has
 * to guess a status string or invent a currency format. It's cached (60s) and
 * invalidated when reference data changes, because it's called every session.
 *
 * `list-reference-data` is the on-demand lookup for the heavier reference lists
 * (coupons, labels, tax/shipping config) the agent only sometimes needs — kept
 * OUT of the context payload so the first call stays lean.
 *
 * Parameter philosophy: get-store-context takes nothing (zero friction, it's
 * discovery). list-reference-data takes only `kinds[]` — the agent asks for
 * exactly the lists it needs, and we return only the kinds its role can see.
 */
class ContextTools
{
    const CACHE_TTL = 60;

    const CACHE_PREFIX = 'fluent_cart_mcp_context_';

    // The verified FluentCart domain enums. Hardcoded (with a filter override)
    // rather than scraped, so the agent always gets the complete valid set even
    // if a status currently has zero rows.
    const ENUMS = [
        'order_statuses'        => ['draft', 'pending', 'on-hold', 'processing', 'completed', 'canceled', 'failed', 'refunded', 'partial-refund'],
        // Kept in sync with Status::getPaymentStatuses(); 'authorized' is a valid
        // persisted status (card authorized, not yet captured) and must be listed
        // so clients can filter authorized orders through list-orders.
        'payment_statuses'      => ['paid', 'pending', 'failed', 'refunded', 'partially_refunded', 'partially_paid', 'authorized'],
        // 'none' = no shipping required (e.g. digital orders); reported when the
        // stored value is empty. It is read-only — change-order-status won't set it.
        'shipping_statuses'     => ['none', 'unshipped', 'shipped', 'delivered', 'unshippable'],
        'order_types'           => ['payment', 'renewal', 'subscription'],
        'subscription_statuses' => ['active', 'trialing', 'paused', 'canceled', 'failing', 'expired', 'expiring', 'past_due', 'intended', 'pending', 'completed'],
        'billing_intervals'     => ['daily', 'weekly', 'monthly', 'quarterly', 'half_yearly', 'yearly'],
        'fulfillment_types'     => ['physical', 'digital'],
        'coupon_types'          => ['fixed', 'percentage'],
        'order_modes'           => ['live', 'test'],
    ];

    // Payment statuses that count as realized revenue. Centralized so every
    // tool (context, reports, aggregates) agrees on what "paid" means.
    const PAID_STATUSES = ['paid', 'partially_paid', 'partially_refunded'];

    /**
     * Ability definitions for this domain. The registrar merges every tool
     * class's definitions(), so a tool's schema lives next to its code.
     */
    public static function definitions()
    {
        return [
            'fluent-cart/get-store-context' => [
                'label'       => __('Get Store Context', 'fluent-cart'),
                'description' => __('START HERE — call once per session. Returns who you are and your permissions, the store currency/timezone conventions, headline stats, every valid enum value (order/payment/shipping/subscription statuses, intervals, types), and usage guidelines. Use this before any other tool so you never guess a status string or money format.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
                'execute_callback'    => [self::class, 'getContext'],
                'permission_callback' => function () {
                    return PermissionGate::can('dashboard_stats/view') || PermissionGate::canAny(PermissionGate::readRoleCaps());
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/list-reference-data' => [
                'label'       => __('List Reference Data', 'fluent-cart'),
                'description' => __('On-demand lookup lists kept out of get-store-context to keep it lean: coupons, labels, gateways, tax_classes, shipping_zones, product_categories. Pass kinds[] with only what you need. Kinds your role cannot see are reported in meta.warnings, not dropped silently.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'kinds' => [
                            'type'        => 'array',
                            'description' => 'Which reference lists to return.',
                            'items'       => ['type' => 'string', 'enum' => ['coupons', 'labels', 'gateways', 'tax_classes', 'shipping_zones', 'product_categories']],
                        ],
                    ],
                    'required' => ['kinds'],
                ],
                'execute_callback'    => [self::class, 'listReferenceData'],
                'permission_callback' => function () {
                    return PermissionGate::canAny(PermissionGate::readRoleCaps());
                },
                'annotations' => ['readonly' => true],
            ],
        ];
    }

    public static function getContext($params = [])
    {
        $userId   = get_current_user_id();
        $cacheKey = self::CACHE_PREFIX . $userId;

        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $context = self::buildContext($userId);
        set_transient($cacheKey, $context, self::CACHE_TTL);

        return $context;
    }

    private static function buildContext($userId)
    {
        $user    = get_user_by('ID', $userId);
        $isAdmin = $user && user_can($user, 'manage_options');

        $you = [
            'wp_user_id'  => (int) $userId,
            'name'        => $user ? $user->display_name : null,
            'email'       => $user ? $user->user_email : null,
            'is_admin'    => (bool) $isAdmin,
            'permissions' => array_values((array) PermissionManager::getUserPermissions()),
        ];

        $store = [
            'name'         => get_bloginfo('name'),
            'url'          => site_url(),
            'version'      => defined('FLUENTCART_VERSION') ? FLUENTCART_VERSION : null,
            'pro_active'   => defined('FLUENT_CART_PRO') || defined('FLUENTCART_PRO_VERSION'),
            'currency'     => MCPHelper::currencyContext(),
            'timezone'     => wp_timezone_string(),
            'current_time' => MCPHelper::toIso8601(DateTime::gmtNow()),
        ];

        // Headline stats are dashboard data: gate them on dashboard_stats/view so
        // a narrow read role can still get context (enums, currency, permissions)
        // without seeing store-wide revenue/order/customer numbers.
        $canStats = PermissionGate::can('dashboard_stats/view');
        $stats    = $canStats ? self::buildStats() : null;

        return MCPHelper::envelope(
            $canStats ? self::summary($stats) : __('Store context loaded.', 'fluent-cart'),
            [
                'you'              => $you,
                'store'            => $store,
                'stats'            => $stats,
                'enums'            => apply_filters('fluent_cart/mcp_enums', self::ENUMS),
                'reference_kinds'  => self::referenceKinds(),
                'guidelines'       => self::guidelines(),
            ]
        );
    }

    /**
     * Headline numbers. Each metric is isolated in safeCount/safeSum so one
     * failing query (e.g. a model that doesn't exist on a given install) yields
     * null for that stat instead of breaking the whole discovery call.
     */
    private static function buildStats()
    {
        $since30 = DateTime::gmtNow()->modify('-30 days')->format('Y-m-d H:i:s');

        return [
            'orders_total'         => self::safeCount(function () {
                return Order::query()->count();
            }),
            'orders_last_30d'      => self::safeCount(function () use ($since30) {
                return Order::query()->where('created_at', '>=', $since30)->count();
            }),
            'revenue_last_30d'     => self::safeMoney(function () use ($since30) {
                return (int) Order::query()
                    ->whereIn('payment_status', self::PAID_STATUSES)
                    ->where('created_at', '>=', $since30)
                    ->sum('total_paid');
            }),
            'customers_total'      => self::safeCount(function () {
                return Customer::query()->count();
            }),
            'active_subscriptions' => self::safeCount(function () {
                return Subscription::query()->where('status', 'active')->count();
            }),
            'products_published'   => self::safeCount(function () {
                if (!class_exists('\FluentCart\App\Models\Product')) {
                    return null;
                }
                // post_type is pinned by the model's global scope; a literal
                // here (and the wrong singular one) would match nothing.
                return \FluentCart\App\Models\Product::query()
                    ->where('post_status', 'publish')
                    ->count();
            }),
        ];
    }

    private static function safeCount(callable $fn)
    {
        try {
            $val = $fn();
            return $val === null ? null : (int) $val;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function safeMoney(callable $fn)
    {
        try {
            return MCPHelper::money((int) $fn());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* translators: %1$s: revenue amount, %2$d: orders in 30 days, %3$d: total customers */
    private static function summary($stats)
    {
        $rev30   = isset($stats['revenue_last_30d']['display']) ? $stats['revenue_last_30d']['display'] : '—';
        $orders30 = isset($stats['orders_last_30d']) ? (int) $stats['orders_last_30d'] : 0;
        $customers = isset($stats['customers_total']) ? (int) $stats['customers_total'] : 0;

        return sprintf(
            /* translators: %1$s: 30-day revenue, %2$d: 30-day order count, %3$d: total customers */
            __('Store snapshot — last 30 days: %1$s across %2$d orders; %3$d customers total.', 'fluent-cart'),
            $rev30,
            $orders30,
            $customers
        );
    }

    /** Tells the agent what `kinds` it can pass to list-reference-data. */
    private static function referenceKinds()
    {
        return ['coupons', 'labels', 'gateways', 'tax_classes', 'shipping_zones', 'product_categories'];
    }

    private static function guidelines()
    {
        $default = 'Call get-store-context once per session, then use search-* tools to find records and get-* tools to load one record fully. '
            . 'Money is returned as both a number (amount) and a formatted string (display) — quote display, compare amount. '
            . 'Dates are ISO-8601 UTC; pass a relative range (e.g. last_30_days) or explicit start_date/end_date to report tools. '
            . 'Use the exact enum values from this payload — never invent a status. '
            . 'Reports never sum across currencies; filter by one currency if the store has several. '
            . 'Writes (refund-order, change-subscription-status:cancel) require a dry_run preview first.';

        return apply_filters('fluent_cart/mcp_guidelines', $default);
    }

    /**
     * `list-reference-data` — heavier lookup lists, fetched on demand.
     *
     * @param array $params { kinds: string[] } — which lists to return. Each
     *                      kind is gated by its own capability; kinds the
     *                      caller can't see are reported in `skipped`, not
     *                      silently dropped, so the agent knows why.
     */
    public static function listReferenceData($params = [])
    {
        $kinds = isset($params['kinds']) ? (array) $params['kinds'] : [];
        if (!$kinds) {
            return MCPHelper::error(
                'missing_kinds',
                __('Provide one or more kinds. Valid: coupons, labels, gateways, tax_classes, shipping_zones, product_categories.', 'fluent-cart'),
                ['valid_kinds' => self::referenceKinds()]
            );
        }

        $gate = [
            'coupons'            => 'coupons/view',
            'labels'             => 'labels/view',
            'gateways'           => 'dashboard_stats/view',
            'tax_classes'        => 'store/settings',
            'shipping_zones'     => 'store/settings',
            'product_categories' => 'products/view',
        ];

        $data    = [];
        $skipped = [];

        foreach ($kinds as $kind) {
            if (!isset($gate[$kind])) {
                $skipped[$kind] = 'unknown_kind';
                continue;
            }
            if (!PermissionGate::can($gate[$kind])) {
                $skipped[$kind] = 'forbidden: requires ' . $gate[$kind];
                continue;
            }
            $data[$kind] = self::fetchReferenceKind($kind);
        }

        $meta = $skipped ? ['warnings' => self::skipWarnings($skipped)] : [];

        return MCPHelper::envelope(
            sprintf(
                /* translators: %d: number of reference lists returned */
                _n('Returned %d reference list.', 'Returned %d reference lists.', count($data), 'fluent-cart'),
                count($data)
            ),
            $data,
            $meta
        );
    }

    private static function skipWarnings($skipped)
    {
        $out = [];
        foreach ($skipped as $kind => $reason) {
            $out[] = $kind . ': ' . $reason;
        }
        return $out;
    }

    /**
     * Each kind is fetched behind a class_exists guard so a model that isn't
     * present on a given install returns [] rather than fataling.
     */
    private static function fetchReferenceKind($kind)
    {
        try {
            if ($kind === 'coupons' && class_exists('\FluentCart\App\Models\Coupon')) {
                $coupons = \FluentCart\App\Models\Coupon::query()
                    ->select(['id', 'code', 'title', 'type', 'amount', 'status'])
                    ->orderBy('id', 'DESC')
                    ->limit(200)
                    ->get();
                $out = [];
                foreach ($coupons as $c) {
                    // Match list-coupons: numeric amount; fixed coupons stored in
                    // cents are reported in store currency, percentage as-is.
                    $amount = ($c->type === 'fixed')
                        ? 0 + \FluentCart\App\Helpers\Helper::toDecimalWithoutComma((int) $c->amount)
                        : (is_numeric($c->amount) ? 0 + $c->amount : $c->amount);
                    $out[] = [
                        'id'     => (int) $c->id,
                        'code'   => $c->code,
                        'title'  => $c->title,
                        'type'   => $c->type,
                        'amount' => $amount,
                        'status' => $c->status,
                    ];
                }
                return $out;
            }

            if ($kind === 'labels' && class_exists('\FluentCart\App\Models\Label')) {
                // fct_label stores a single (maybe-serialized) `value` column —
                // it may hold a plain title string or an array {title,color,…}.
                // Labels are user-created and can grow large; cap like coupons
                // so kinds[]=labels can't trigger an unbounded read/response.
                $labels = \FluentCart\App\Models\Label::query()->orderBy('id', 'ASC')->limit(200)->get();
                $out = [];
                foreach ($labels as $label) {
                    $val   = $label->value;
                    $entry = ['id' => (int) $label->id];
                    if (is_array($val)) {
                        $entry['title'] = isset($val['title']) ? $val['title'] : (isset($val['value']) ? $val['value'] : null);
                        if (isset($val['color'])) {
                            $entry['color'] = $val['color'];
                        }
                    } else {
                        $entry['title'] = $val;
                    }
                    $out[] = $entry;
                }
                return $out;
            }

            if ($kind === 'tax_classes' && class_exists('\FluentCart\App\Models\TaxClass')) {
                // fct_tax_classes labels its name column `title`, not `name`.
                return \FluentCart\App\Models\TaxClass::query()
                    ->select(['id', 'title'])
                    ->get()
                    ->toArray();
            }

            if ($kind === 'shipping_zones' && class_exists('\FluentCart\App\Models\ShippingZone')) {
                // fct_shipping_zones labels its name column `name`, not `title`.
                return \FluentCart\App\Models\ShippingZone::query()
                    ->select(['id', 'name', 'region'])
                    ->get()
                    ->toArray();
            }

            if ($kind === 'gateways') {
                return self::enabledGateways();
            }

            if ($kind === 'product_categories') {
                return self::productCategories();
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }

    /**
     * Active payment gateways. Each gateway stores its own settings (there is no
     * single payment_settings option), so we read the registered gateway
     * instances from the GatewayManager and keep the ones with is_active=yes.
     */
    private static function enabledGateways()
    {
        $managerClass = '\FluentCart\App\Modules\PaymentMethods\Core\GatewayManager';
        if (!class_exists($managerClass) || !method_exists($managerClass, 'getInstance')) {
            return [];
        }

        try {
            $gateways = $managerClass::getInstance()->all();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ((array) $gateways as $gateway) {
            if (!is_object($gateway) || !method_exists($gateway, 'getMeta')) {
                continue;
            }

            $settings = (isset($gateway->settings) && is_object($gateway->settings) && method_exists($gateway->settings, 'get'))
                ? (array) $gateway->settings->get()
                : [];

            $isActive = isset($settings['is_active'])
                ? ($settings['is_active'] === 'yes')
                : !empty($gateway->getMeta('status'));
            if (!$isActive) {
                continue;
            }

            $meta  = (array) $gateway->getMeta();
            $route = isset($meta['route']) ? $meta['route'] : null;
            $out[] = [
                'key'   => $route,
                'title' => isset($meta['title']) ? $meta['title'] : $route,
                'mode'  => isset($settings['payment_mode'])
                    ? $settings['payment_mode']
                    : (isset($settings['checkout_mode']) ? $settings['checkout_mode'] : null),
            ];
        }
        return $out;
    }

    /** Product categories from the WP taxonomy (best-effort across naming). */
    private static function productCategories()
    {
        foreach (['fluent-cart-category', 'product_cat', 'fluent_cart_category'] as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 200]);
            if (is_wp_error($terms)) {
                continue;
            }
            $out = [];
            foreach ($terms as $term) {
                $out[] = ['id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'count' => (int) $term->count];
            }
            return $out;
        }
        return [];
    }

    /**
     * Clear the cached context for all users. Hooked from MCPInit onto the
     * events that change anything the context payload reports.
     */
    public static function invalidateCache()
    {
        global $wpdb;

        $like = $wpdb->esc_like('_transient_' . self::CACHE_PREFIX) . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));

        $like = $wpdb->esc_like('_transient_timeout_' . self::CACHE_PREFIX) . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    }
}
