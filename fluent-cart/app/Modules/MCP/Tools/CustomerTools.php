<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Customer;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\Api\Resource\CustomerResource;

/**
 * Customer tools — find customers, then load one's 360° view.
 *
 * Parameter design:
 *  - list-customers filters on the metrics owners actually segment by (LTV,
 *    purchase count, location, first/last purchase window). LTV/min_ltv are in
 *    store currency, not cents.
 *  - AOV is computed (ltv ÷ purchase_count) rather than read from the stored
 *    column, so it's always internally consistent with the LTV we show.
 *  - get-customer is lean by default (profile + metrics); orders, subscriptions,
 *    addresses, labels, notes are opt-in via include[]. with_orders_limit
 *    bounds the order history so a whale's account can't flood context.
 */
class CustomerTools
{
    public static function definitions()
    {
        return [
            'fluent-cart/list-customers' => [
                'label'       => __('List Customers', 'fluent-cart'),
                'description' => __('Find and filter customers. Compact rows with LTV, order count, AOV, and location. For one customer\'s full history use get-customer. min_ltv is in store currency (e.g. 500), not cents.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'               => ['type' => 'string', 'description' => 'Matches name or email.'],
                        'status'               => ['type' => 'string', 'enum' => ['active', 'archived']],
                        'country'              => ['type' => 'string', 'description' => 'ISO-2 country code.'],
                        'state'                => ['type' => 'string'],
                        'city'                 => ['type' => 'string'],
                        'min_ltv'              => ['type' => 'number', 'description' => 'Minimum lifetime value in store currency.'],
                        'min_purchase_count'   => ['type' => 'integer'],
                        'first_purchase_after' => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC.'],
                        'last_purchase_after'  => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC.'],
                        'last_purchase_before' => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC.'],
                        'sort_by'              => ['type' => 'string', 'enum' => ['id', 'ltv', 'purchase_count', 'last_purchase_date', 'created_at'], 'default' => 'ltv'],
                        'sort_type'            => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
                        'page'                 => ['type' => 'integer', 'default' => 1],
                        'per_page'             => ['type' => 'integer', 'default' => 15, 'description' => 'Max 100.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'listCustomers'],
                'permission_callback' => function () {
                    return PermissionGate::can('customers/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-customer' => [
                'label'       => __('Get Customer', 'fluent-cart'),
                'description' => __('Full profile + metrics for one customer. Identify by customer_id OR email. Add include[] for orders, subscriptions, addresses, labels, notes. Use with_orders_limit to bound order history.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'customer_id'       => ['type' => 'integer'],
                        'email'             => ['type' => 'string'],
                        'include'           => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string', 'enum' => ['orders', 'subscriptions', 'addresses', 'labels', 'notes']],
                            'description' => 'Optional sections. Profile + metrics are always returned.',
                        ],
                        'with_orders_limit' => ['type' => 'integer', 'default' => 10, 'description' => 'Cap on orders when include has orders. Max 50.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getCustomer'],
                'permission_callback' => function () {
                    return PermissionGate::can('customers/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/upsert-customer' => [
                'label'       => __('Create or Update Customer', 'fluent-cart'),
                'description' => __('Create a customer or update an existing one. Identify by customer_id to update, or email to create or match. On create, email is required. Only the fields you pass change. Set status to archived to deactivate; there is no hard delete. Use new_email to rename. if_exists handles a matched email: merge updates, skip leaves it, error returns a conflict.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer'],
                        'email'       => ['type' => 'string', 'description' => 'Required to create; used to match on update.'],
                        'new_email'   => ['type' => 'string', 'description' => 'Rename an existing customer in place.'],
                        'first_name'  => ['type' => 'string'],
                        'last_name'   => ['type' => 'string'],
                        'status'      => ['type' => 'string', 'enum' => ['active', 'archived']],
                        'city'        => ['type' => 'string'],
                        'state'       => ['type' => 'string'],
                        'country'     => ['type' => 'string', 'description' => 'ISO-2 country code.'],
                        'postcode'    => ['type' => 'string'],
                        'if_exists'   => ['type' => 'string', 'enum' => ['merge', 'skip', 'error'], 'default' => 'merge'],
                    ],
                ],
                'execute_callback'    => [self::class, 'upsertCustomer'],
                'permission_callback' => function () {
                    return PermissionGate::can('customers/manage');
                },
            ],
        ];
    }

    public static function upsertCustomer($params = [])
    {
        $ifExists = (isset($params['if_exists']) && in_array($params['if_exists'], ['merge', 'skip', 'error'], true)) ? $params['if_exists'] : 'merge';

        // Reject invalid emails up front — sanitize_email() silently returns ''
        // for garbage input, which would otherwise create an empty-email customer.
        foreach (['email', 'new_email'] as $emailField) {
            if (isset($params[$emailField]) && $params[$emailField] !== '') {
                $clean = sanitize_email($params[$emailField]);
                if (!$clean || !is_email($clean)) {
                    return MCPHelper::error(
                        'invalid_email',
                        sprintf(
                            /* translators: 1: field name */
                            __('The provided %1$s is not a valid email address.', 'fluent-cart'),
                            $emailField
                        ),
                        ['fields' => [$emailField]]
                    );
                }
            }
        }

        $existing = null;
        if (!empty($params['customer_id'])) {
            $existing = Customer::query()->where('id', (int) $params['customer_id'])->first();
            if (!$existing) {
                return MCPHelper::error('customer_not_found', __('No customer found for the given customer_id.', 'fluent-cart'));
            }
        } elseif (!empty($params['email'])) {
            $existing = Customer::query()->where('email', sanitize_email($params['email']))->first();
        } else {
            return MCPHelper::error('missing_identifier', __('Provide customer_id to update, or email to create or match.', 'fluent-cart'), ['fields' => ['customer_id', 'email']]);
        }

        $fields = self::writableFields($params);

        if ($existing) {
            if ($ifExists === 'skip') {
                return MCPHelper::envelope(__('Customer already exists; left unchanged.', 'fluent-cart'), ['customer_id' => (int) $existing->id, 'action' => 'skipped']);
            }
            if ($ifExists === 'error') {
                return MCPHelper::error('customer_exists', __('A customer with this identifier already exists.', 'fluent-cart'), ['customer_id' => (int) $existing->id]);
            }
            if (!empty($params['new_email'])) {
                $newEmail = sanitize_email($params['new_email']);
                $taken    = Customer::query()->where('email', $newEmail)->where('id', '!=', $existing->id)->first();
                if ($taken) {
                    return MCPHelper::error('email_taken', __('Another customer already uses that email.', 'fluent-cart'));
                }
                $fields['email'] = $newEmail;
            }
            if ($fields) {
                $existing->fill($fields);
                $existing->save();
            }
            $existing = Customer::query()->where('id', $existing->id)->first();
            return MCPHelper::envelope(
                self::label($existing, (int) $existing->ltv),
                ['customer_id' => (int) $existing->id, 'action' => 'updated', 'name' => MCPHelper::personName($existing), 'email' => $existing->email, 'status' => $existing->status]
            );
        }

        if (empty($params['email'])) {
            return MCPHelper::error('missing_param', __('email is required to create a customer.', 'fluent-cart'));
        }
        $fields['email'] = sanitize_email($params['email']);
        if (empty($fields['status'])) {
            $fields['status'] = 'active';
        }

        // Delegate to the resource layer: it normalizes the name, links an
        // existing WP user via user_id, and uses firstOrCreate so a concurrent
        // create matches rather than duplicating — none of which a raw
        // Customer::create() does.
        $result = CustomerResource::create($fields);
        if (is_wp_error($result)) {
            return MCPHelper::error('customer_create_failed', $result->get_error_message(), ['retryable' => true]);
        }
        $customer = is_array($result) && isset($result['data']) ? $result['data'] : null;
        if (!is_object($customer) || empty($customer->id)) {
            return MCPHelper::error('customer_create_failed', __('Customer creation failed.', 'fluent-cart'));
        }

        return MCPHelper::envelope(
            self::label($customer, (int) $customer->ltv),
            ['customer_id' => (int) $customer->id, 'action' => 'created', 'name' => MCPHelper::personName($customer), 'email' => $customer->email, 'status' => $customer->status]
        );
    }

    private static function writableFields($params)
    {
        $out = [];
        foreach (['first_name', 'last_name', 'status', 'city', 'state', 'country', 'postcode'] as $f) {
            if (isset($params[$f])) {
                $out[$f] = sanitize_text_field($params[$f]);
            }
        }
        if (isset($out['status']) && !in_array($out['status'], ['active', 'archived'], true)) {
            unset($out['status']);
        }
        return $out;
    }

    public static function listCustomers($params = [])
    {
        $paging = MCPHelper::pagination($params);
        $query  = Customer::query();

        if (!empty($params['search'])) {
            $like = '%' . sanitize_text_field($params['search']) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('email', 'LIKE', $like)
                    ->orWhere('first_name', 'LIKE', $like)
                    ->orWhere('last_name', 'LIKE', $like);
            });
        }

        foreach (['status', 'country', 'state', 'city'] as $col) {
            if (!empty($params[$col])) {
                $query->where($col, sanitize_text_field($params[$col]));
            }
        }

        if (isset($params['min_ltv'])) {
            $query->where('ltv', '>=', Helper::toCent($params['min_ltv']));
        }
        if (isset($params['min_purchase_count'])) {
            $query->where('purchase_count', '>=', (int) $params['min_purchase_count']);
        }
        $dateFilters = [
            'first_purchase_after' => ['first_purchase_date', '>='],
            'last_purchase_after'  => ['last_purchase_date', '>='],
            'last_purchase_before' => ['last_purchase_date', '<='],
        ];
        foreach ($dateFilters as $field => $spec) {
            if (empty($params[$field])) {
                continue;
            }
            $date = self::toDbDate($params[$field]);
            if ($date === null) {
                return self::invalidDateError($field);
            }
            $query->where($spec[0], $spec[1], $date);
        }

        $sortBy   = self::allowed($params, 'sort_by', ['id', 'ltv', 'purchase_count', 'last_purchase_date', 'created_at'], 'ltv');
        $sortType = strtoupper(isset($params['sort_type']) ? $params['sort_type'] : 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $query->orderBy($sortBy, $sortType);
        if ($sortBy !== 'id') {
            $query->orderBy('id', 'DESC');
        }

        $paginator = $query->paginate($paging['per_page'], ['*'], 'page', $paging['page']);
        $total     = self::total($paginator);

        $rows = [];
        foreach (MCPHelper::paginatorItems($paginator) as $customer) {
            $rows[] = self::formatRow($customer);
        }

        return MCPHelper::envelope(
            sprintf(
                /* translators: %d: number of matching customers */
                _n('%d customer found.', '%d customers found.', $total, 'fluent-cart'),
                $total
            ),
            ['customers' => $rows],
            MCPHelper::pagingMeta($paginator)
        );
    }

    private static function formatRow($customer)
    {
        $ltv   = (int) $customer->ltv;
        $count = (int) $customer->purchase_count;

        return [
            'customer_id'        => (int) $customer->id,
            'label'              => self::label($customer, $ltv),
            'name'               => MCPHelper::personName($customer),
            'email'              => $customer->email,
            'status'             => $customer->status,
            'ltv'                => MCPHelper::moneyCompact($ltv),
            'purchase_count'     => $count,
            'aov'                => MCPHelper::moneyCompact(self::aovCents($ltv, $count)),
            'location'           => self::location($customer),
            'last_purchase_date' => MCPHelper::toIso8601($customer->last_purchase_date),
        ];
    }

    private static function label($customer, $ltvCents)
    {
        $name = MCPHelper::personName($customer);
        if (!$name) {
            $name = $customer->email;
        }

        return sprintf(
            /* translators: 1: customer name, 2: email, 3: lifetime value */
            __('%1$s [%2$s] — LTV %3$s', 'fluent-cart'),
            $name,
            $customer->email,
            MCPHelper::displayAmount($ltvCents)
        );
    }

    public static function getCustomer($params = [])
    {
        $customer = self::resolve($params);
        if (is_wp_error($customer)) {
            return $customer;
        }

        $include = isset($params['include']) ? (array) $params['include'] : [];
        $ltv     = (int) $customer->ltv;
        $count   = (int) $customer->purchase_count;

        $data = [
            'customer_id' => (int) $customer->id,
            'name'        => MCPHelper::personName($customer),
            'email'       => $customer->email,
            'status'      => $customer->status,
            'wp_user_id'  => $customer->user_id ? (int) $customer->user_id : null,
            'location'    => self::location($customer),
            'metrics'     => [
                'ltv'                 => MCPHelper::money($ltv),
                'purchase_count'      => $count,
                'aov'                 => MCPHelper::money(self::aovCents($ltv, $count)),
                'first_purchase_date' => MCPHelper::toIso8601($customer->first_purchase_date),
                'last_purchase_date'  => MCPHelper::toIso8601($customer->last_purchase_date),
            ],
            'created_at'  => MCPHelper::toIso8601($customer->created_at),
        ];

        if (in_array('addresses', $include, true)) {
            $data['addresses'] = self::addresses($customer);
        }
        if (in_array('orders', $include, true)) {
            $limit = isset($params['with_orders_limit']) ? min(max((int) $params['with_orders_limit'], 1), 50) : 10;
            $data['orders'] = self::orders($customer, $limit);
        }
        if (in_array('subscriptions', $include, true)) {
            $data['subscriptions'] = self::subscriptions($customer);
        }
        if (in_array('labels', $include, true)) {
            $data['labels'] = self::labels($customer);
        }
        if (in_array('notes', $include, true)) {
            $data['notes'] = MCPHelper::htmlToText($customer->notes);
        }

        return MCPHelper::envelope(self::label($customer, $ltv), $data);
    }

    private static function resolve($params)
    {
        if (!empty($params['customer_id'])) {
            $customer = Customer::query()->where('id', (int) $params['customer_id'])->first();
        } elseif (!empty($params['email'])) {
            $customer = Customer::query()->where('email', sanitize_email($params['email']))->first();
        } else {
            return MCPHelper::error('missing_identifier', __('Provide customer_id or email.', 'fluent-cart'), ['fields' => ['customer_id', 'email']]);
        }

        if (!$customer) {
            return MCPHelper::error('customer_not_found', __('No customer found for the given identifier.', 'fluent-cart'));
        }

        return $customer;
    }

    private static function addresses($customer)
    {
        $customer->load('billing_address', 'shipping_address');
        return [
            'billing'  => self::addressList($customer->billing_address),
            'shipping' => self::addressList($customer->shipping_address),
        ];
    }

    private static function addressList($addresses)
    {
        if (!$addresses) {
            return [];
        }
        $out = [];
        foreach ($addresses as $addr) {
            $out[] = [
                'name'       => $addr->name,
                'address_1'  => $addr->address_1,
                'address_2'  => $addr->address_2,
                'city'       => $addr->city,
                'state'      => $addr->state,
                'postcode'   => $addr->postcode,
                'country'    => $addr->country,
                'phone'      => $addr->phone,
                'is_primary' => (bool) $addr->is_primary,
            ];
        }
        return $out;
    }

    private static function orders($customer, $limit)
    {
        $orders = $customer->orders()->orderBy('id', 'DESC')->limit($limit)->get();
        $out = [];
        foreach ($orders as $order) {
            $out[] = [
                'order_id'       => (int) $order->id,
                'number'         => $order->invoice_no ? $order->invoice_no : (string) $order->id,
                'status'         => $order->status,
                'payment_status' => $order->payment_status,
                'total'          => MCPHelper::moneyCompact($order->total_amount),
                'created_at'     => MCPHelper::toIso8601($order->created_at),
            ];
        }
        return $out;
    }

    private static function subscriptions($customer)
    {
        $subs = $customer->subscriptions()->orderBy('id', 'DESC')->get();
        $out = [];
        foreach ($subs as $sub) {
            $out[] = [
                'id'                => (int) $sub->id,
                'status'            => $sub->status,
                'item_name'         => $sub->item_name,
                'recurring_total'   => MCPHelper::moneyCompact($sub->recurring_total),
                'billing_interval'  => $sub->billing_interval,
                'next_billing_date' => MCPHelper::toIso8601($sub->next_billing_date),
            ];
        }
        return $out;
    }

    private static function labels($customer)
    {
        $customer->load('labels');
        $out = [];
        if (!$customer->relationLoaded('labels')) {
            return $out;
        }
        foreach ($customer->labels as $label) {
            $out[] = ['id' => (int) $label->id, 'title' => $label->title];
        }
        return $out;
    }

    private static function location($customer)
    {
        $parts = array_filter([$customer->city, $customer->state, $customer->country]);
        return $parts ? implode(', ', $parts) : null;
    }

    private static function aovCents($ltvCents, $count)
    {
        return $count > 0 ? (int) round($ltvCents / $count) : 0;
    }

    private static function allowed($params, $key, array $allowed, $default)
    {
        $val = isset($params[$key]) ? $params[$key] : $default;
        return in_array($val, $allowed, true) ? $val : $default;
    }

    private static function total($paginator)
    {
        return MCPHelper::paginatorTotal($paginator);
    }

    private static function toDbDate($value)
    {
        try {
            return (new \DateTime((string) $value, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            // Return null so callers reject the input. An epoch fallback would
            // silently turn a typo'd date bound into an unbounded "match all".
            return null;
        }
    }

    private static function invalidDateError($field)
    {
        return MCPHelper::error(
            'invalid_date',
            sprintf(
                /* translators: 1: field name */
                __('%1$s is not a valid date. Use YYYY-MM-DD or ISO 8601.', 'fluent-cart'),
                $field
            ),
            ['fields' => [$field]]
        );
    }
}
