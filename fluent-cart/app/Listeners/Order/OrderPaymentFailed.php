<?php

namespace FluentCart\App\Listeners\Order;

use FluentCart\App\Models\Order;

class OrderPaymentFailed
{
    public static function handle(\FluentCart\App\Events\Order\OrderPaymentFailed $event)
    {
        if ($event->order) {
            $event->order->addLog(
                __('Payment Failed', 'fluent-cart'), 
                __('Payment attempt failed for this order.', 'fluent-cart'), 
                'error'
            );
        }

        if ($event->order->customer) {
            $event->order->customer->recountStat();
        }
    }

}

