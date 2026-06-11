<?php

namespace FluentCart\App\Events\Subscription;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;

class SubscriptionReactivated extends EventDispatcher
{
    public string $hook = 'fluent_cart/subscription_reactivated';
    protected array $listeners = [];

    public Subscription $subscription;
    public ?Order $order;
    public ?Customer $customer;

    public function __construct(Subscription $subscription, ?Order $order = null, ?Customer $customer = null)
    {
        $this->subscription = $subscription;
        $this->order = $order;
        $this->customer = $customer;
    }

    public function toArray(): array
    {
        return [
            'subscription' => $this->subscription,
            'order'        => $this->order,
            'customer'     => $this->customer,
        ];
    }

    public function getActivityEventModel()
    {
        return $this->subscription;
    }
}
