<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\Framework\Http\Request\Request;

class WidgetsController extends Controller
{
    public function __invoke(Request $request): \WP_REST_Response
    {
        if (!PermissionManager::hasAnyPermission(['customers/view', 'orders/view'])) {
            return $this->sendError([
                'message' => __('You do not have permission to access this resource', 'fluent-cart')
            ]);
        }

        $filter = $request->get('filter') ?? '';
        $filter = str_replace('fluent_cart_', '', $filter);
        $data = $request->get('data', []);

        if ($filter === 'single_order_page') {
            $order = Order::find((int)$data['order_id']);
            if (!$order) {
                return $this->sendError([
                    'message' => __('Order not found', 'fluent-cart')
                ]);
            }

            $data['order'] = $order;
        }

        return $this->sendSuccess([
            'widgets' => apply_filters('fluent_cart/widgets/' . $filter, [], $data)
        ]);
    }
}
