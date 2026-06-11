<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Customer;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;

/**
 * Reports & analytics — the headline research surface.
 *
 * Design rules baked in:
 *  - Every report is CURRENCY-SCOPED: it filters to one currency (the store
 *    default unless `currency` is passed) so totals are never silently summed
 *    across currencies. The chosen currency is echoed in meta.currency.
 *  - "Revenue" definitions are explicit and consistent across tools (see
 *    metricDefs): gross = sum(total_amount) of paid orders; net = paid −
 *    refunded; paid orders = payment_status in the paid set.
 *  - Date basis is created_at (echoed as meta.date_basis) for determinism.
 *  - Server-side aggregation only — no raw row dumps. query-orders caps at 200
 *    grouped rows; trend caps its bucket count.
 *  - Each report returns an NL summary the agent can quote verbatim.
 *
 * Parameter design: a shared `range` enum (today … last_year) resolves to a
 * UTC window server-side, with explicit start_date/end_date as an override —
 * the agent never has to compute "last month" itself.
 */
class ReportTools
{
    const PAID = ['paid', 'partially_paid', 'partially_refunded'];

    const RANGES = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'mtd', 'qtd', 'ytd', 'last_quarter', 'last_year'];

    const MAX_BUCKETS = 180;

    const MAX_ROWS = 200;

    public static function definitions()
    {
        $rangeProp = ['type' => 'string', 'enum' => self::RANGES, 'description' => 'Relative window, resolved in UTC to match the store reports. Or pass start_date + end_date.'];

        return [
            'fluent-cart/get-sales-report' => [
                'label'       => __('Get Sales Report', 'fluent-cart'),
                'description' => __('Revenue overview for a period with comparison to the prior equal period: gross, net, paid, refunded, tax, shipping, fees, order count, AOV, unique customers, and percent change. Scoped to one currency, the store default unless a currency is given.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, UTC. Overrides range.'],
                        'end_date'   => ['type' => 'string', 'description' => 'YYYY-MM-DD, UTC. Overrides range.'],
                        'currency'   => ['type' => 'string', 'description' => 'ISO currency. Defaults to the store currency.'],
                        'compare'    => ['type' => 'boolean', 'default' => true, 'description' => 'Include prior-period comparison.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getSalesReport'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-sales-trend' => [
                'label'       => __('Get Sales Trend', 'fluent-cart'),
                'description' => __('Time series of revenue and order count bucketed by day, week, or month over a period. Scoped to one currency. Use to see growth and seasonality.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, UTC.'],
                        'end_date'   => ['type' => 'string', 'description' => 'YYYY-MM-DD, UTC.'],
                        'interval'   => ['type' => 'string', 'enum' => ['day', 'week', 'month'], 'default' => 'day'],
                        'currency'   => ['type' => 'string'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getSalesTrend'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-top-products' => [
                'label'       => __('Get Top Products', 'fluent-cart'),
                'description' => __('Best-selling products over a period, ranked by revenue or units sold. Scoped to one currency.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string'],
                        'end_date'   => ['type' => 'string'],
                        'metric'     => ['type' => 'string', 'enum' => ['revenue', 'units'], 'default' => 'revenue'],
                        'currency'   => ['type' => 'string'],
                        'limit'      => ['type' => 'integer', 'default' => 10, 'description' => 'Max 50.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getTopProducts'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-refund-report' => [
                'label'       => __('Get Refund Report', 'fluent-cart'),
                'description' => __('Refund metrics for a period: refunded order count, refund rate as a share of paid orders, total and average refunded amount. Scoped to one currency.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string'],
                        'end_date'   => ['type' => 'string'],
                        'currency'   => ['type' => 'string'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getRefundReport'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/query-sources' => [
                'label'       => __('Query Sources (UTM attribution)', 'fluent-cart'),
                'description' => __('Flexible UTM attribution: pick metrics and group by any UTM fields — source, medium, campaign, term, content, id — over a period, with optional source/medium/campaign filters to drill down. Scoped to one currency, paid orders. Orders with no UTM fall under a none bucket. Returns up to 200 rows ranked by the first metric.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'metrics'      => ['type' => 'array', 'description' => 'Defaults to orders and gross_revenue.', 'items' => ['type' => 'string', 'enum' => ['orders', 'gross_revenue', 'net_revenue', 'aov', 'unique_customers', 'refunded_amount']]],
                        'dimensions'   => ['type' => 'array', 'description' => 'Group by these UTM fields. Defaults to utm_source, utm_medium, utm_campaign.', 'items' => ['type' => 'string', 'enum' => ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id']]],
                        'utm_source'   => ['type' => 'string', 'description' => 'Filter to one source, exact match.'],
                        'utm_medium'   => ['type' => 'string', 'description' => 'Filter to one medium, exact match.'],
                        'utm_campaign' => ['type' => 'string', 'description' => 'Filter to one campaign, exact match.'],
                        'range'        => $rangeProp,
                        'start_date'   => ['type' => 'string'],
                        'end_date'     => ['type' => 'string'],
                        'currency'     => ['type' => 'string'],
                        'limit'        => ['type' => 'integer', 'default' => 50, 'description' => 'Max 200.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'querySources'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/query-orders' => [
                'label'       => __('Query Orders (flexible aggregate)', 'fluent-cart'),
                'description' => __('Flexible order analytics: pick metrics and group by dimensions with filters. Use when a fixed report does not fit, for example revenue by payment_status this month, or orders by month. Scoped to one currency. Returns up to 200 grouped rows.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'metrics'    => [
                            'type'        => 'array',
                            'description' => 'One or more. Defaults to order_count and gross_revenue.',
                            'items'       => ['type' => 'string', 'enum' => ['order_count', 'gross_revenue', 'paid_revenue', 'refunded_amount', 'aov', 'unique_customers']],
                        ],
                        'dimensions' => [
                            'type'        => 'array',
                            'description' => 'Group by these. Empty means a single total row.',
                            'items'       => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'status', 'payment_status']],
                        ],
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string'],
                        'end_date'   => ['type' => 'string'],
                        'currency'   => ['type' => 'string'],
                        'sort_desc'  => ['type' => 'boolean', 'default' => true, 'description' => 'Sort by the first metric descending. When grouping by a time dimension (day/week/month), rows default to chronological order unless you set this explicitly.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'queryOrders'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/query-products' => [
                'label'       => __('Query Products (flexible aggregate)', 'fluent-cart'),
                'description' => __('Flexible product-sales analytics over sold items: pick metrics and group by product or variation, within a period and one currency. For a time series use get-sales-trend. Returns up to 200 rows.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'metrics'    => ['type' => 'array', 'description' => 'Defaults to units_sold and line_revenue. net_revenue = line_revenue minus refunds.', 'items' => ['type' => 'string', 'enum' => ['units_sold', 'line_revenue', 'net_revenue', 'order_count', 'avg_unit_price', 'refund_amount']]],
                        'dimensions' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['product', 'variation']]],
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string'],
                        'end_date'   => ['type' => 'string'],
                        'currency'   => ['type' => 'string'],
                    ],
                ],
                'execute_callback'    => [self::class, 'queryProducts'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/query-customers' => [
                'label'       => __('Query Customers (flexible aggregate)', 'fluent-cart'),
                'description' => __('Flexible customer analytics: pick metrics and group by country, state, status, or first/last purchase month, with optional filters. LTV is in store currency. Returns up to 200 rows.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'metrics'            => ['type' => 'array', 'description' => 'Defaults to customer_count and total_ltv.', 'items' => ['type' => 'string', 'enum' => ['customer_count', 'total_ltv', 'avg_ltv', 'avg_purchase_count', 'repeat_customers']]],
                        'dimensions'         => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['country', 'state', 'status', 'first_purchase_month', 'last_purchase_month']]],
                        'country'            => ['type' => 'string'],
                        'status'             => ['type' => 'string', 'enum' => ['active', 'archived']],
                        'min_ltv'            => ['type' => 'number', 'description' => 'Minimum LTV in store currency.'],
                        'min_purchase_count' => ['type' => 'integer'],
                    ],
                ],
                'execute_callback'    => [self::class, 'queryCustomers'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // get-sales-report
    // -----------------------------------------------------------------

    public static function getSalesReport($params = [])
    {
        $currency = self::currency($params);
        $range    = self::resolveRange($params);

        $current = self::salesMetrics($range['start'], $range['end'], $currency);

        $data = [
            'range'       => self::rangeBlock($range, $currency),
            'metrics'     => self::salesMetricsOut($current, $currency),
            'definitions' => self::metricDefs(),
        ];

        $compare = !isset($params['compare']) || !empty($params['compare']);
        if ($compare && $range['prev_start']) {
            $prior = self::salesMetrics($range['prev_start'], $range['prev_end'], $currency);
            $data['comparison'] = [
                'prior_metrics'  => self::salesMetricsOut($prior, $currency),
                'change_percent' => [
                    'gross_revenue' => self::pct($current['gross'], $prior['gross']),
                    'net_revenue'   => self::pct($current['net'], $prior['net']),
                    'order_count'   => self::pct($current['orders'], $prior['orders']),
                ],
            ];
        }

        $summary = sprintf(
            /* translators: 1: gross revenue, 2: order count, 3: average order value */
            __('Revenue %1$s across %2$d paid orders, AOV %3$s.', 'fluent-cart'),
            MCPHelper::displayAmount($current['gross'], $currency),
            $current['orders'],
            MCPHelper::displayAmount($current['aov'], $currency)
        );

        return MCPHelper::envelope($summary, $data, ['currency' => $currency, 'date_basis' => 'created_at']);
    }

    private static function salesMetrics($start, $end, $currency)
    {
        // One aggregate scan instead of eight (this is the headline report, and
        // it runs twice when compare=true). Same filtered set, same numbers.
        $row = Order::query()
            ->whereIn('payment_status', self::PAID)
            ->where('currency', $currency)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->selectRaw(
                'COUNT(*) as orders, '
                . 'COALESCE(SUM(total_amount), 0) as gross, '
                . 'COALESCE(SUM(total_paid), 0) as paid, '
                . 'COALESCE(SUM(total_refund), 0) as refund, '
                . 'COALESCE(SUM(tax_total), 0) as tax, '
                . 'COALESCE(SUM(shipping_total), 0) as ship, '
                . 'COALESCE(SUM(fee_total), 0) as fees, '
                . 'COUNT(DISTINCT customer_id) as uniq'
            )
            ->first();

        $orders = $row ? (int) $row->orders : 0;
        $gross  = $row ? (int) $row->gross : 0;
        $paid   = $row ? (int) $row->paid : 0;
        $refund = $row ? (int) $row->refund : 0;
        $tax    = $row ? (int) $row->tax : 0;
        $ship   = $row ? (int) $row->ship : 0;
        $fees   = $row ? (int) $row->fees : 0;
        $uniq   = $row ? (int) $row->uniq : 0;

        return [
            'orders'   => $orders,
            'gross'    => $gross,
            'paid'     => $paid,
            'refund'   => $refund,
            'net'      => $paid - $refund,
            'tax'      => $tax,
            'shipping' => $ship,
            'fees'     => $fees,
            'unique'   => $uniq,
            'aov'      => $orders > 0 ? (int) round($gross / $orders) : 0,
        ];
    }

    private static function salesMetricsOut($m, $currency)
    {
        return [
            'order_count'      => $m['orders'],
            'unique_customers' => $m['unique'],
            'gross_revenue'    => MCPHelper::money($m['gross'], $currency),
            'net_revenue'      => MCPHelper::money($m['net'], $currency),
            'paid'             => MCPHelper::money($m['paid'], $currency),
            'refunded'         => MCPHelper::money($m['refund'], $currency),
            'tax'              => MCPHelper::money($m['tax'], $currency),
            'shipping'         => MCPHelper::money($m['shipping'], $currency),
            'fees'             => MCPHelper::money($m['fees'], $currency),
            'aov'              => MCPHelper::money($m['aov'], $currency),
        ];
    }

    private static function metricDefs()
    {
        return [
            'paid_orders'   => 'Orders with payment_status in: ' . implode(', ', self::PAID),
            'gross_revenue' => 'Sum of order total_amount for paid orders.',
            'net_revenue'   => 'Sum of total_paid minus total_refund.',
            'aov'           => 'gross_revenue divided by paid order count.',
            'date_basis'    => 'created_at, within the given range.',
        ];
    }

    // -----------------------------------------------------------------
    // get-sales-trend
    // -----------------------------------------------------------------

    public static function getSalesTrend($params = [])
    {
        $currency = self::currency($params);
        $range    = self::resolveRange($params);
        $interval = isset($params['interval']) && in_array($params['interval'], ['day', 'week', 'month'], true) ? $params['interval'] : 'day';

        $format = $interval === 'month' ? '%Y-%m' : ($interval === 'week' ? '%x-W%v' : '%Y-%m-%d');

        $rows = Order::query()
            ->whereIn('payment_status', self::PAID)
            ->where('currency', $currency)
            ->where('created_at', '>=', $range['start'])
            ->where('created_at', '<=', $range['end'])
            ->selectRaw('DATE_FORMAT(created_at, ?) as bucket, COUNT(*) as order_count, SUM(total_amount) as gross', [$format])
            ->groupBy('bucket')
            ->orderBy('bucket', 'ASC')
            ->limit(self::MAX_BUCKETS)
            ->get();

        $trend = [];
        $sum   = 0;
        foreach ($rows as $row) {
            $gross = (int) $row->gross;
            $sum  += $gross;
            $trend[] = [
                'bucket'      => $row->bucket,
                'order_count' => (int) $row->order_count,
                'gross'       => MCPHelper::moneyCompact($gross),
            ];
        }

        $summary = sprintf(
            /* translators: 1: number of buckets, 2: interval, 3: total revenue */
            __('%1$d %2$s buckets, total revenue %3$s.', 'fluent-cart'),
            count($trend),
            $interval,
            MCPHelper::displayAmount($sum, $currency)
        );

        return MCPHelper::envelope(
            $summary,
            ['interval' => $interval, 'range' => self::rangeBlock($range, $currency), 'trend' => $trend],
            ['currency' => $currency, 'truncated' => count($rows) >= self::MAX_BUCKETS]
        );
    }

    // -----------------------------------------------------------------
    // get-top-products
    // -----------------------------------------------------------------

    public static function getTopProducts($params = [])
    {
        $currency = self::currency($params);
        $range    = self::resolveRange($params);
        $metric   = isset($params['metric']) && $params['metric'] === 'units' ? 'units' : 'revenue';
        $limit    = isset($params['limit']) ? min(max((int) $params['limit'], 1), 50) : 10;
        $orderCol = $metric === 'units' ? 'units' : 'revenue';

        $rows = OrderItem::query()
            ->whereHas('order', function ($q) use ($range, $currency) {
                $q->whereIn('payment_status', self::PAID)
                    ->where('currency', $currency)
                    ->where('created_at', '>=', $range['start'])
                    ->where('created_at', '<=', $range['end']);
            })
            ->selectRaw('post_id, MAX(post_title) as title, SUM(quantity) as units, SUM(line_total - refund_total) as revenue, COUNT(DISTINCT order_id) as order_count')
            ->groupBy('post_id')
            ->orderBy($orderCol, 'DESC')
            ->limit($limit)
            ->get();

        $products = [];
        foreach ($rows as $row) {
            $products[] = [
                'product_id'  => (int) $row->post_id,
                'title'       => $row->title,
                'units_sold'  => (int) $row->units,
                'revenue'     => MCPHelper::moneyCompact((int) $row->revenue),
                'order_count' => (int) $row->order_count,
            ];
        }

        $summary = sprintf(
            /* translators: 1: number of products, 2: ranking metric */
            __('Top %1$d products by %2$s.', 'fluent-cart'),
            count($products),
            $metric
        );

        return MCPHelper::envelope($summary, ['metric' => $metric, 'range' => self::rangeBlock($range, $currency), 'products' => $products], ['currency' => $currency]);
    }

    // -----------------------------------------------------------------
    // get-refund-report
    // -----------------------------------------------------------------

    public static function getRefundReport($params = [])
    {
        $currency = self::currency($params);
        $range    = self::resolveRange($params);

        $paidBase = Order::query()
            ->whereIn('payment_status', self::PAID)
            ->where('currency', $currency)
            ->where('created_at', '>=', $range['start'])
            ->where('created_at', '<=', $range['end']);

        $paidCount = (clone $paidBase)->count();

        $refundedBase   = (clone $paidBase)->where('total_refund', '>', 0);
        $refundedCount  = (clone $refundedBase)->count();
        $refundedAmount = (int) (clone $refundedBase)->sum('total_refund');

        $rate = $paidCount > 0 ? round(($refundedCount / $paidCount) * 100, 2) : 0;
        $avg  = $refundedCount > 0 ? (int) round($refundedAmount / $refundedCount) : 0;

        $summary = sprintf(
            /* translators: 1: refunded order count, 2: refund rate percent, 3: total refunded */
            __('%1$d orders refunded, %2$s%% of paid, totaling %3$s.', 'fluent-cart'),
            $refundedCount,
            $rate,
            MCPHelper::displayAmount($refundedAmount, $currency)
        );

        return MCPHelper::envelope(
            $summary,
            [
                'range'                => self::rangeBlock($range, $currency),
                'paid_order_count'     => $paidCount,
                'refunded_order_count' => $refundedCount,
                'refund_rate_percent'  => $rate,
                'total_refunded'       => MCPHelper::money($refundedAmount, $currency),
                'average_refund'       => MCPHelper::money($avg, $currency),
            ],
            ['currency' => $currency]
        );
    }

    // -----------------------------------------------------------------
    // query-sources (flexible UTM attribution)
    // -----------------------------------------------------------------

    const UTM_DIMENSIONS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];

    public static function querySources($params = [])
    {
        $currency   = self::currency($params);
        $range      = self::resolveRange($params);
        $limit      = isset($params['limit']) ? min(max((int) $params['limit'], 1), self::MAX_ROWS) : 50;
        $metrics    = self::pickList($params, 'metrics', ['orders', 'gross_revenue', 'net_revenue', 'aov', 'unique_customers', 'refunded_amount'], ['orders', 'gross_revenue']);
        $dimensions = self::pickList($params, 'dimensions', self::UTM_DIMENSIONS, ['utm_source', 'utm_medium', 'utm_campaign']);

        // Build the aggregate directly (rather than via SourceReportService,
        // which hard-codes its grouping) so the agent picks the UTM dimensions.
        // Uses the raw query builder with the same aliases as the admin Source
        // report to avoid Order model global scopes. Paid + one currency to stay
        // consistent with the other reports.
        // Dedupe operations to one row per order before joining: fct_order_operations
        // has only an INDEX on order_id (not UNIQUE), so a raw leftJoin would fan out
        // and make every SUM(o.<money>) double-count any order with >1 ops row.
        // MAX() per UTM column is ONLY_FULL_GROUP_BY-safe and returns the single
        // row's value in the normal one-row-per-order case.
        $opSub = App::db()->table('fct_order_operations')
            ->select('order_id')
            ->selectRaw(
                'MAX(utm_source) as utm_source, MAX(utm_medium) as utm_medium, '
                . 'MAX(utm_campaign) as utm_campaign, MAX(utm_term) as utm_term, '
                . 'MAX(utm_content) as utm_content, MAX(utm_id) as utm_id'
            )
            ->groupBy('order_id');

        $query = App::db()->table('fct_orders as o')
            ->leftJoinSub($opSub, 'oo', 'o.id', '=', 'oo.order_id')
            ->whereIn('o.payment_status', self::PAID)
            ->where('o.currency', $currency)
            ->where('o.created_at', '>=', $range['start'])
            ->where('o.created_at', '<=', $range['end']);

        // Optional drill-down filters on exact UTM values.
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $f) {
            if (!empty($params[$f])) {
                $query->where('oo.' . $f, sanitize_text_field($params[$f]));
            }
        }

        $selects   = [];
        $groupExpr = [];
        foreach ($dimensions as $dim) {
            // Coalesce NULL/'' into a single 'none' bucket; group by the
            // expression so the split values collapse together.
            $expr        = "COALESCE(NULLIF(oo." . $dim . ", ''), 'none')";
            $selects[]   = $expr . ' as ' . $dim;
            $groupExpr[] = $expr;
        }

        // Definitions must match metricDefs() and the sales report so an agent's
        // "revenue by source" ties out with "revenue this month":
        //   gross_revenue = SUM(total_amount), net_revenue = SUM(total_paid - total_refund).
        $metricSql = [
            'orders'           => 'COUNT(DISTINCT o.id) as orders',
            'gross_revenue'    => 'SUM(o.total_amount) as gross_revenue',
            'net_revenue'      => 'SUM(o.total_paid - o.total_refund) as net_revenue',
            'unique_customers' => 'COUNT(DISTINCT o.customer_id) as unique_customers',
            'refunded_amount'  => 'SUM(o.total_refund) as refunded_amount',
        ];
        foreach ($metrics as $m) {
            if (isset($metricSql[$m])) {
                $selects[] = $metricSql[$m];
            }
        }
        if (in_array('aov', $metrics, true)) {
            if (!in_array('gross_revenue', $metrics, true)) {
                $selects[] = $metricSql['gross_revenue'];
            }
            if (!in_array('orders', $metrics, true)) {
                $selects[] = $metricSql['orders'];
            }
        }

        $query->selectRaw(implode(', ', $selects));
        if ($groupExpr) {
            $query->groupByRaw(implode(', ', $groupExpr));
        }

        $firstMetric = isset($metrics[0]) ? $metrics[0] : 'orders';
        if ($firstMetric === 'aov') {
            $firstMetric = 'gross_revenue';
        }
        if ($groupExpr && isset($metricSql[$firstMetric])) {
            $query->orderBy($firstMetric, 'DESC');
        }
        // Fetch one extra row so we can tell "more exist beyond your limit" from
        // "you hit the 200 hard cap". $limit is already clamped to <= MAX_ROWS.
        $query->limit($limit + 1);

        $rows         = $query->get();
        $moneyMetrics = ['gross_revenue', 'net_revenue', 'refunded_amount'];
        $truncated    = count($rows) > $limit;

        $out = [];
        foreach ($rows as $row) {
            if (count($out) >= $limit) {
                break;
            }
            $r = [];
            foreach ($dimensions as $dim) {
                $r[$dim] = $row->{$dim};
            }
            foreach ($metrics as $m) {
                if ($m === 'aov') {
                    $g = (int) $row->gross_revenue;
                    $c = (int) $row->orders;
                    $r['aov'] = MCPHelper::moneyCompact($c > 0 ? (int) round($g / $c) : 0);
                } elseif (in_array($m, $moneyMetrics, true)) {
                    $r[$m] = MCPHelper::moneyCompact((int) $row->{$m});
                } else {
                    $r[$m] = (int) $row->{$m};
                }
            }
            $out[] = $r;
        }

        $summary = sprintf(
            /* translators: 1: row count, 2: metric list, 3: dimension list */
            __('%1$d rows — metrics [%2$s] grouped by [%3$s].', 'fluent-cart'),
            count($out),
            implode(', ', $metrics),
            $dimensions ? implode(', ', $dimensions) : __('total', 'fluent-cart')
        );

        return MCPHelper::envelope(
            $summary,
            ['metrics' => $metrics, 'dimensions' => $dimensions, 'range' => self::rangeBlock($range, $currency), 'rows' => $out],
            [
                'currency'   => $currency,
                'date_basis' => 'created_at',
                'returned'   => count($out),
                'limit'      => $limit,
                'max_rows'   => self::MAX_ROWS,
                // true = more rows exist; raise `limit` (up to max_rows) to see them.
                'truncated'  => $truncated,
            ]
        );
    }

    // -----------------------------------------------------------------
    // query-orders (flexible aggregate)
    // -----------------------------------------------------------------

    public static function queryOrders($params = [])
    {
        $currency   = self::currency($params);
        $range      = self::resolveRange($params);
        $metrics    = self::pickList($params, 'metrics', ['order_count', 'gross_revenue', 'paid_revenue', 'refunded_amount', 'aov', 'unique_customers'], ['order_count', 'gross_revenue']);
        $dimensions = self::pickList($params, 'dimensions', ['day', 'week', 'month', 'status', 'payment_status'], []);

        $query = Order::query()
            ->whereIn('payment_status', self::PAID)
            ->where('currency', $currency)
            ->where('created_at', '>=', $range['start'])
            ->where('created_at', '<=', $range['end']);

        $selects   = [];
        $groupCols = [];
        foreach ($dimensions as $dim) {
            $selects[]   = self::dimensionExpr($dim) . ' as ' . $dim;
            $groupCols[] = $dim;
        }

        $metricSql = [
            'order_count'      => 'COUNT(*) as order_count',
            'gross_revenue'    => 'SUM(total_amount) as gross_revenue',
            'paid_revenue'     => 'SUM(total_paid) as paid_revenue',
            'refunded_amount'  => 'SUM(total_refund) as refunded_amount',
            'unique_customers' => 'COUNT(DISTINCT customer_id) as unique_customers',
        ];
        foreach ($metrics as $m) {
            if (isset($metricSql[$m])) {
                $selects[] = $metricSql[$m];
            }
        }
        if (in_array('aov', $metrics, true)) {
            if (!in_array('gross_revenue', $metrics, true)) {
                $selects[] = $metricSql['gross_revenue'];
            }
            if (!in_array('order_count', $metrics, true)) {
                $selects[] = $metricSql['order_count'];
            }
        }

        $query->selectRaw(implode(', ', $selects));
        foreach ($groupCols as $g) {
            $query->groupBy($g);
        }

        $sortDesc    = !isset($params['sort_desc']) || !empty($params['sort_desc']);
        $firstMetric = isset($metrics[0]) ? $metrics[0] : 'order_count';
        if ($firstMetric === 'aov') {
            $firstMetric = 'gross_revenue';
        }

        // Find the first time dimension, if any.
        $timeDim = null;
        foreach ($dimensions as $dim) {
            if (in_array($dim, ['day', 'week', 'month'], true)) {
                $timeDim = $dim;
                break;
            }
        }

        if ($groupCols) {
            if ($timeDim !== null && !isset($params['sort_desc'])) {
                // A time series reads chronologically by default; ranking a
                // calendar by metric is rarely what's wanted. An explicit
                // sort_desc still overrides this.
                $query->orderBy($timeDim, 'ASC');
            } else {
                $query->orderBy($firstMetric, $sortDesc ? 'DESC' : 'ASC');
            }
        }
        $query->limit(self::MAX_ROWS);

        $rows         = $query->get();
        $moneyMetrics = ['gross_revenue', 'paid_revenue', 'refunded_amount'];

        $out = [];
        foreach ($rows as $row) {
            $r = [];
            foreach ($dimensions as $dim) {
                $r[$dim] = $row->{$dim};
            }
            foreach ($metrics as $m) {
                if ($m === 'aov') {
                    $g = (int) $row->gross_revenue;
                    $c = (int) $row->order_count;
                    $r['aov'] = MCPHelper::moneyCompact($c > 0 ? (int) round($g / $c) : 0);
                } elseif (in_array($m, $moneyMetrics, true)) {
                    $r[$m] = MCPHelper::moneyCompact((int) $row->{$m});
                } else {
                    $r[$m] = (int) $row->{$m};
                }
            }
            $out[] = $r;
        }

        $summary = sprintf(
            /* translators: 1: row count, 2: metric list, 3: dimension list */
            __('%1$d rows — metrics [%2$s] grouped by [%3$s].', 'fluent-cart'),
            count($out),
            implode(', ', $metrics),
            $dimensions ? implode(', ', $dimensions) : __('total', 'fluent-cart')
        );

        return MCPHelper::envelope(
            $summary,
            ['metrics' => $metrics, 'dimensions' => $dimensions, 'range' => self::rangeBlock($range, $currency), 'rows' => $out],
            ['currency' => $currency, 'truncated' => count($rows) >= self::MAX_ROWS]
        );
    }

    // -----------------------------------------------------------------
    // query-products / query-customers (flexible aggregates)
    // -----------------------------------------------------------------

    public static function queryProducts($params = [])
    {
        $currency   = self::currency($params);
        $range      = self::resolveRange($params);
        $metrics    = self::pickList($params, 'metrics', ['units_sold', 'line_revenue', 'net_revenue', 'order_count', 'avg_unit_price', 'refund_amount'], ['units_sold', 'line_revenue']);
        $dimensions = self::pickList($params, 'dimensions', ['product', 'variation'], ['product']);

        $query = OrderItem::query()->whereHas('order', function ($q) use ($range, $currency) {
            $q->whereIn('payment_status', self::PAID)
                ->where('currency', $currency)
                ->where('created_at', '>=', $range['start'])
                ->where('created_at', '<=', $range['end']);
        });

        $selects   = [];
        $groupCols = [];
        if (in_array('product', $dimensions, true)) {
            $selects[]   = 'post_id';
            $selects[]   = 'MAX(post_title) as product_title';
            $groupCols[] = 'post_id';
        }
        if (in_array('variation', $dimensions, true)) {
            $selects[]   = 'object_id';
            $groupCols[] = 'object_id';
        }

        $metricSql = [
            'units_sold'    => 'SUM(quantity) as units_sold',
            'line_revenue'  => 'SUM(line_total) as line_revenue',
            'net_revenue'   => 'SUM(line_total - refund_total) as net_revenue',
            'order_count'   => 'COUNT(DISTINCT order_id) as order_count',
            'refund_amount' => 'SUM(refund_total) as refund_amount',
        ];
        foreach ($metrics as $m) {
            if (isset($metricSql[$m])) {
                $selects[] = $metricSql[$m];
            }
        }
        if (in_array('avg_unit_price', $metrics, true)) {
            if (!in_array('line_revenue', $metrics, true)) {
                $selects[] = $metricSql['line_revenue'];
            }
            if (!in_array('units_sold', $metrics, true)) {
                $selects[] = $metricSql['units_sold'];
            }
        }

        $query->selectRaw(implode(', ', $selects));
        foreach ($groupCols as $g) {
            $query->groupBy($g);
        }

        $firstMetric = isset($metrics[0]) ? $metrics[0] : 'line_revenue';
        if ($firstMetric === 'avg_unit_price') {
            $firstMetric = 'line_revenue';
        }
        if ($groupCols && isset($metricSql[$firstMetric])) {
            $query->orderBy($firstMetric, 'DESC');
        }
        $query->limit(self::MAX_ROWS);

        $rows         = $query->get();
        $moneyMetrics = ['line_revenue', 'net_revenue', 'refund_amount', 'avg_unit_price'];

        $out = [];
        foreach ($rows as $row) {
            $r = [];
            if (in_array('product', $dimensions, true)) {
                $r['product_id']    = (int) $row->post_id;
                $r['product_title'] = $row->product_title;
            }
            if (in_array('variation', $dimensions, true)) {
                $r['variation_id'] = (int) $row->object_id;
            }
            foreach ($metrics as $m) {
                if ($m === 'avg_unit_price') {
                    $u   = (int) $row->units_sold;
                    $rev = (int) $row->line_revenue;
                    $r['avg_unit_price'] = MCPHelper::moneyCompact($u > 0 ? (int) round($rev / $u) : 0);
                } elseif (in_array($m, $moneyMetrics, true)) {
                    $r[$m] = MCPHelper::moneyCompact((int) $row->{$m});
                } else {
                    $r[$m] = (int) $row->{$m};
                }
            }
            $out[] = $r;
        }

        return MCPHelper::envelope(
            sprintf(
                /* translators: 1: row count, 2: metric list */
                __('%1$d rows — product metrics [%2$s].', 'fluent-cart'),
                count($out),
                implode(', ', $metrics)
            ),
            ['metrics' => $metrics, 'dimensions' => $dimensions, 'range' => self::rangeBlock($range, $currency), 'rows' => $out],
            ['currency' => $currency, 'truncated' => count($rows) >= self::MAX_ROWS]
        );
    }

    public static function queryCustomers($params = [])
    {
        $metrics    = self::pickList($params, 'metrics', ['customer_count', 'total_ltv', 'avg_ltv', 'avg_purchase_count', 'repeat_customers'], ['customer_count', 'total_ltv']);
        $dimensions = self::pickList($params, 'dimensions', ['country', 'state', 'status', 'first_purchase_month', 'last_purchase_month'], []);

        $query = Customer::query();
        if (!empty($params['country'])) {
            $query->where('country', sanitize_text_field($params['country']));
        }
        if (!empty($params['status'])) {
            $query->where('status', sanitize_text_field($params['status']));
        }
        if (isset($params['min_ltv'])) {
            $query->where('ltv', '>=', Helper::toCent($params['min_ltv']));
        }
        if (isset($params['min_purchase_count'])) {
            $query->where('purchase_count', '>=', (int) $params['min_purchase_count']);
        }

        $selects   = [];
        $groupCols = [];
        foreach ($dimensions as $dim) {
            if ($dim === 'country' || $dim === 'state') {
                // Coalesce NULL and '' into a single 'unknown' bucket. Group by the
                // expression (not the alias, which would resolve to the raw column
                // and keep null/'' split).
                $expr        = "COALESCE(NULLIF($dim, ''), 'unknown')";
                $selects[]   = "$expr as $dim";
                $groupCols[] = $expr;
            } elseif ($dim === 'status') {
                $selects[]   = $dim;
                $groupCols[] = $dim;
            } elseif ($dim === 'first_purchase_month') {
                $selects[]   = "DATE_FORMAT(first_purchase_date, '%Y-%m') as first_purchase_month";
                $groupCols[] = "DATE_FORMAT(first_purchase_date, '%Y-%m')";
            } elseif ($dim === 'last_purchase_month') {
                $selects[]   = "DATE_FORMAT(last_purchase_date, '%Y-%m') as last_purchase_month";
                $groupCols[] = "DATE_FORMAT(last_purchase_date, '%Y-%m')";
            }
        }

        $metricSql = [
            'customer_count'     => 'COUNT(*) as customer_count',
            'total_ltv'          => 'SUM(ltv) as total_ltv',
            'avg_ltv'            => 'AVG(ltv) as avg_ltv',
            'avg_purchase_count' => 'AVG(purchase_count) as avg_purchase_count',
            'repeat_customers'   => 'SUM(CASE WHEN purchase_count > 1 THEN 1 ELSE 0 END) as repeat_customers',
        ];
        foreach ($metrics as $m) {
            if (isset($metricSql[$m])) {
                $selects[] = $metricSql[$m];
            }
        }

        $query->selectRaw(implode(', ', $selects));
        if ($groupCols) {
            $query->groupByRaw(implode(', ', $groupCols));
        }

        $firstMetric = isset($metrics[0]) ? $metrics[0] : 'customer_count';
        if ($groupCols && isset($metricSql[$firstMetric])) {
            $query->orderBy($firstMetric, 'DESC');
        }
        $query->limit(self::MAX_ROWS);

        $rows         = $query->get();
        $moneyMetrics = ['total_ltv', 'avg_ltv'];

        $out = [];
        foreach ($rows as $row) {
            $r = [];
            foreach ($dimensions as $dim) {
                $r[$dim] = $row->{$dim};
            }
            foreach ($metrics as $m) {
                if (in_array($m, $moneyMetrics, true)) {
                    $r[$m] = MCPHelper::moneyCompact((int) round((float) $row->{$m}));
                } elseif ($m === 'avg_purchase_count') {
                    $r[$m] = round((float) $row->{$m}, 2);
                } else {
                    $r[$m] = (int) $row->{$m};
                }
            }
            $out[] = $r;
        }

        return MCPHelper::envelope(
            sprintf(
                /* translators: 1: row count, 2: metric list */
                __('%1$d rows — customer metrics [%2$s].', 'fluent-cart'),
                count($out),
                implode(', ', $metrics)
            ),
            ['metrics' => $metrics, 'dimensions' => $dimensions, 'rows' => $out],
            ['currency' => MCPHelper::currencyCode(), 'note' => 'LTV is in store currency; customers are not currency-scoped.']
        );
    }

    private static function dimensionExpr($dim)
    {
        if ($dim === 'day') {
            return "DATE_FORMAT(created_at, '%Y-%m-%d')";
        }
        if ($dim === 'week') {
            return "DATE_FORMAT(created_at, '%x-W%v')";
        }
        if ($dim === 'month') {
            return "DATE_FORMAT(created_at, '%Y-%m')";
        }
        return $dim;
    }

    // -----------------------------------------------------------------
    // shared helpers
    // -----------------------------------------------------------------

    private static function currency($params)
    {
        if (!empty($params['currency'])) {
            return strtoupper(sanitize_text_field($params['currency']));
        }
        return MCPHelper::currencyCode();
    }

    /**
     * Resolve range/start/end into a UTC window plus the prior equal-length
     * window. Relative ranges are computed in store timezone, expressed in UTC.
     */
    private static function resolveRange($params)
    {
        // Resolve windows in UTC to match FluentCart's own admin reports, which
        // bucket on the GMT-stored created_at (DATE_FORMAT(created_at, ...)) with
        // no timezone conversion. Using store-local boundaries here would make a
        // local day straddle two UTC dates and emit an extra trailing bucket.
        $tz = new \DateTimeZone('UTC');

        if (!empty($params['start_date']) || !empty($params['end_date'])) {
            $start = self::dayStart(!empty($params['start_date']) ? $params['start_date'] : '-30 days', $tz);
            $end   = self::dayEnd(!empty($params['end_date']) ? $params['end_date'] : 'now', $tz);
            return self::withPrior($start, $end, !empty($params['start_date']) ? 'custom' : 'last_30_days');
        }

        $range = isset($params['range']) && in_array($params['range'], self::RANGES, true) ? $params['range'] : 'last_30_days';

        $now     = new \DateTime('now', $tz);
        $startDt = clone $now;
        $endDt   = clone $now;
        // Set for calendar-bounded ranges to force a calendar-aligned prior period.
        $prevStartDt = null;
        $prevEndDt   = null;

        if ($range === 'yesterday') {
            $startDt->modify('-1 day');
            $endDt->modify('-1 day');
        } elseif ($range === 'last_7_days') {
            $startDt->modify('-6 days');
        } elseif ($range === 'last_30_days') {
            $startDt->modify('-29 days');
        } elseif ($range === 'this_month' || $range === 'mtd') {
            $startDt = new \DateTime($now->format('Y-m-01'), $tz);
        } elseif ($range === 'last_month') {
            $startDt = new \DateTime($now->format('Y-m-01'), $tz);
            $startDt->modify('-1 month');
            $endDt = (clone $startDt)->modify('last day of this month');
            // Prior = the full calendar month before last month.
            $prevStartDt = (clone $startDt)->modify('-1 month');
            $prevEndDt   = (clone $prevStartDt)->modify('last day of this month');
        } elseif ($range === 'qtd') {
            $startDt = self::quarterStart($now, $tz);
        } elseif ($range === 'last_quarter') {
            $qs      = self::quarterStart($now, $tz);
            $startDt = (clone $qs)->modify('-3 months');
            $endDt   = (clone $qs)->modify('-1 day');
            // Prior = the full calendar quarter before last quarter.
            $prevStartDt = (clone $startDt)->modify('-3 months');
            $prevEndDt   = (clone $startDt)->modify('-1 day');
        } elseif ($range === 'ytd') {
            $startDt = new \DateTime($now->format('Y-01-01'), $tz);
        } elseif ($range === 'last_year') {
            $year    = (int) $now->format('Y') - 1;
            $startDt = new \DateTime($year . '-01-01', $tz);
            $endDt   = new \DateTime($year . '-12-31', $tz);
            // Prior = the full calendar year before last year.
            $prevStartDt = new \DateTime(($year - 1) . '-01-01', $tz);
            $prevEndDt   = new \DateTime(($year - 1) . '-12-31', $tz);
        }

        $start = self::dayStart($startDt->format('Y-m-d'), $tz);
        $end   = self::dayEnd($endDt->format('Y-m-d'), $tz);

        if ($prevStartDt !== null && $prevEndDt !== null) {
            return self::withPrior(
                $start,
                $end,
                $range,
                self::dayStart($prevStartDt->format('Y-m-d'), $tz),
                self::dayEnd($prevEndDt->format('Y-m-d'), $tz)
            );
        }

        return self::withPrior($start, $end, $range);
    }

    private static function quarterStart($now, $tz)
    {
        $month       = (int) $now->format('n');
        $qStartMonth = (int) (floor(($month - 1) / 3) * 3 + 1);
        return new \DateTime($now->format('Y') . '-' . str_pad($qStartMonth, 2, '0', STR_PAD_LEFT) . '-01', $tz);
    }

    private static function withPrior($startUtc, $endUtc, $label, $prevStartUtc = null, $prevEndUtc = null)
    {
        // Calendar-bounded ranges (last_month/last_quarter/last_year) pass an
        // explicit prior *calendar* period so a 31-day month isn't compared to a
        // 28-day second-count window. Other ranges fall back to an equal-length
        // block ending 1s before start (exact for fixed-length, rolling, custom).
        if ($prevStartUtc !== null && $prevEndUtc !== null) {
            return [
                'start'      => $startUtc,
                'end'        => $endUtc,
                'prev_start' => $prevStartUtc,
                'prev_end'   => $prevEndUtc,
                'label'      => $label,
            ];
        }

        $s         = new \DateTime($startUtc, new \DateTimeZone('UTC'));
        $e         = new \DateTime($endUtc, new \DateTimeZone('UTC'));
        $lengthSec = $e->getTimestamp() - $s->getTimestamp();

        $prevEnd   = (clone $s)->modify('-1 second');
        $prevStart = (clone $prevEnd)->modify('-' . ($lengthSec + 1) . ' seconds');

        return [
            'start'      => $startUtc,
            'end'        => $endUtc,
            'prev_start' => $prevStart->format('Y-m-d H:i:s'),
            'prev_end'   => $prevEnd->format('Y-m-d H:i:s'),
            'label'      => $label,
        ];
    }

    private static function dayStart($value, $tz)
    {
        try {
            $dt = new \DateTime((string) $value, $tz);
        } catch (\Exception $e) {
            $dt = new \DateTime('now', $tz);
        }
        $dt->setTime(0, 0, 0);
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    private static function dayEnd($value, $tz)
    {
        try {
            $dt = new \DateTime((string) $value, $tz);
        } catch (\Exception $e) {
            $dt = new \DateTime('now', $tz);
        }
        $dt->setTime(23, 59, 59);
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    private static function rangeBlock($range, $currency)
    {
        return [
            'start'    => MCPHelper::toIso8601($range['start']),
            'end'      => MCPHelper::toIso8601($range['end']),
            'label'    => $range['label'],
            'currency' => $currency,
        ];
    }

    private static function pickList($params, $key, array $allowed, array $default)
    {
        if (empty($params[$key]) || !is_array($params[$key])) {
            return $default;
        }
        $out = [];
        foreach ($params[$key] as $v) {
            if (in_array($v, $allowed, true) && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out ? $out : $default;
    }

    private static function pct($current, $prior)
    {
        if ($prior == 0) {
            return $current == 0 ? 0 : null;
        }
        return round((($current - $prior) / abs($prior)) * 100, 2);
    }
}
