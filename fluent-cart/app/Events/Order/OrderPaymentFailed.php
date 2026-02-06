<?php

namespace FluentCart\App\Events\Order;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;

class OrderPaymentFailed extends EventDispatcher
{

    public string $hook = 'fluent_cart/order_payment_failed';
    protected array $listeners = [
        Listeners\Order\OrderPaymentFailed::class,
    ];

    /**
     * @var $order Order
     */
    public Order $order;

    /**
     * @var $customer Customer|null
     */
    public ?Customer $customer;

    /**
     * @var $transaction OrderTransaction|null
     */

    /**
     * @var $oldStatus string|null
     */
    public ?string $oldStatus;

    /**
     * @var $newStatus string|null
     */
    public ?string $newStatus;

    /**
     * @var $reason string|null
     */
    public ?string $reason;



    public ?OrderTransaction $transaction;

    public function __construct(Order $order, $transaction = null, $oldStatus = null, $newStatus = null, $reason = null)
    {
        $this->order = $order;
        $this->order->load('customer', 'order_items', 'shipping_address', 'billing_address');
        $this->customer = $order->customer;
        $this->transaction = $transaction;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->reason = $reason;
    }

    public function toArray(): array
    {
        return [
            'order'       => $this->order,
            'customer'    => $this->customer ?? null,
            'transaction' => $this->transaction ?? null,
            'old_status'  => $this->oldStatus,
            'new_status'  => $this->newStatus,
            'reason'      => $this->reason,
        ];
    }

    public function getActivityEventModel()
    {
        return $this->order;
    }

    public function beforeDispatch()
    {
        // Add any pre-dispatch logic here if needed
    }

    public function afterDispatch()
    {
        // to do: maybe add some logic here

    }

}

