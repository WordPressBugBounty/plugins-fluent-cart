<?php

namespace FluentCart\App\Modules\PaymentMethods\Cod;


use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Models\Subscription;

class CodHandler {

    protected $cod;

    public function __construct(Cod $cod)
    {
        $this->cod = $cod;
    }
    public function handlePayment($paymentInstance)
    {
        $settings = $this->cod->settings->get();
        $order = $paymentInstance->order;
        if ($order->total_amount == 0) {
            return $this->handleZeroTotalPayment($paymentInstance);
        }

        if (!$settings['is_active'] === 'yes') {
            throw new \Exception(esc_html__('Offline payment is not activated', 'fluent-cart'));
        }


        $order->payment_method_title = $this->cod->getMeta('title');
        $order->save();


        if (!$order->id) {
            throw new \Exception(esc_html__('Order not found!', 'fluent-cart'));
        }

        if ($paymentInstance->order) {
            if (!$paymentInstance->order->customer) {
                $paymentInstance->order->customer = $paymentInstance->order->customer()->first();
            }
            
            $paymentInstance->order->load(['customer', 'shipping_address', 'billing_address']);
            
            $data = [
                'order'       => $paymentInstance->order,
                'customer'    => $paymentInstance->order->customer ?? [],
                'transaction' => $paymentInstance->transaction ?? []
            ];
            
            do_action('fluent_cart/order_placed_offline', $data);
        }

        $paymentHelper = new PaymentHelper('offline_payment');

        $relatedCart = Cart::query()->where('order_id', $order->id)
            ->where('stage', '!=', 'completed')
            ->first();

        if ($relatedCart) {
            $relatedCart->stage = 'completed';
            $relatedCart->completed_at = DateTime::now()->format('Y-m-d H:i:s');
            $relatedCart->save();
        }

        return $paymentHelper->successUrl($paymentInstance->transaction->uuid);
    }

    public function handleZeroTotalPayment($paymentInstance)
    {
        $order = $paymentInstance->order;

        $transaction = $paymentInstance->transaction;
        $transaction->status = Status::TRANSACTION_SUCCEEDED;
        $transaction->save();

        (new StatusHelper($order))->syncOrderStatuses($transaction);

        // Check if this order has subscriptions with zero or negative recurring_total
        if ($order->payment_method === 'offline_payment') {
            $subscription = Subscription::query()
                ->where('parent_order_id', $order->id)
                ->where('recurring_total', '<=', 0)
                ->first();

                if ($subscription) {
                    $subscription->status = 'active';
                    $subscription->next_billing_date = null; // No future billing needed
                    $subscription->save();
                }
        }

        $paymentHelper = new PaymentHelper('offline_payment');
        return $paymentHelper->successUrl($transaction->uuid);
    }
}
