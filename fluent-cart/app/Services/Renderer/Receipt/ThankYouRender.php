<?php

namespace FluentCart\App\Services\Renderer\Receipt;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;

class ThankYouRender
{

    protected $config = null;

    protected $settings = null;

    protected $is_first_time = false;

    protected $order_operation = null;


    public function __construct($config)
    {
        $this->config = $config;

        $this->is_first_time = Arr::get($this->config, 'is_first_time', false);
        $this->order_operation = Arr::get($this->config, 'order_operation', false);

        $this->settings = new StoreSettings();
        AssetLoader::enqueueThankYouPageAssets();

    }

    public function renderWrapperStart()
    {
        ?>
        <div class="fct-thank-you-page">
        <div class="fct-thank-you-page-inner">
        <div class="fct-thank-you-page-content email-template-content">
        <?php
    }

    public function renderWrapperEnd()
    {
        ?>
        </div>
        </div>
        </div>
        <?php
    }

    public function render($hide_wrapper = false)
    {
        $order = Arr::get($this->config, 'order', null);

        if (!$order) {
            return;
        }
        ?>
        <?php if (!$hide_wrapper) {
        $this->renderWrapperStart();
    } ?>
        <?php do_action('fluent_cart/receipt/thank_you/before_header', $this->config); ?>
        <?php $this->renderHeader(); ?>
        <?php do_action('fluent_cart/receipt/thank_you/after_header', $this->config); ?>

        <?php do_action('fluent_cart/receipt/thank_you/before_body', $this->config); ?>
        <?php $this->renderBody(); ?>
        <?php do_action('fluent_cart/receipt/thank_you/after_body', $this->config); ?>
        <?php if (!$hide_wrapper) {
        $this->renderWrapperEnd();
    } ?>

        <?php

        $this->renderFooter();

        do_action('fluent_cart/after_receipt', [
                'order'           => $order,
                'is_first_time'   => $this->is_first_time ?? false,
                'order_operation' => $this->order_operation ?? null
        ]);

        if (!empty($this->is_first_time)) {
            do_action('fluent_cart/after_receipt_first_time', [
                    'order'           => $order,
                    'order_operation' => $this->order_operation ?? null
            ]);
        }
    }

    public function renderHeader()
    {
        $bgColor = '#d4edda';
        $titleColor = '#155724';
        $iconBg = '#155724bd';

        $order = Arr::get($this->config, 'order', null);

        if ($order->payment_status !== 'paid') {
            $bgColor = '#fff3cd';
            $titleColor = '#856404';
            $iconBg = '#856404bd';
        }
        ?>
        <div class="fct-thank-you-page-header" style="background: <?php echo esc_attr($bgColor); ?>;">
            <?php if ($order->payment_status !== 'paid') : ?>
                <div class="fct-thank-you-page-header-icon" style="background: <?php echo esc_attr($iconBg); ?>;">
                    <svg class="w-64 h-64" xmlns="http://www.w3.org/2000/svg"
                         viewBox="0 0 1024 1024" fill="currentColor">
                        <path
                                d="M480 674V192c0-18 14-32 32-32s32 14 32 32v482h-64zm0 63h64v60h-64v-60zM0 512C0 229 229 0 512 0s512 229 512 512-229 512-512 512S0 795 0 512zm961 0c0-247-202-448-449-448S64 265 64 512s201 448 448 448 449-201 449-448z"></path>
                    </svg>
                </div>
                <h1 class="fct-thank-you-page-header-title" style="color:<?php echo esc_attr($titleColor); ?>">
                    <?php echo esc_html__('Payment Pending!', 'fluent-cart'); ?>
                </h1>

            <?php else: ?>
                <div class="fct-thank-you-page-header-icon" style="background: <?php echo esc_attr($iconBg); ?>;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20,6 9,17 4,12"></polyline>
                    </svg>
                </div>
                <h1 class="fct-thank-you-page-header-title" style="color:<?php echo esc_attr($titleColor); ?>;">
                    <?php echo esc_html__('Purchase Successful!', 'fluent-cart'); ?>
                </h1>
            <?php endif; ?>

            <?php do_action('fluent_cart/receipt/thank_you/after_header_title', $this->config); ?>

        </div>

        <?php
    }

    public function renderBody()
    {
        $order = Arr::get($this->config, 'order', null);

        if (!$order) {
            return;
        }
        ?>
        <div class="fct-thank-you-page-body">
            <div class="fct-thank-you-page-body-inner">
                <div class="fct-thank-you-page-body-content">
                    <div class="fct-thank-you-page-body-content-inner">
                        <?php do_action('fluent_cart/receipt/thank_you/before_order_header', $this->config); ?>
                        <?php $this->renderOrderHeader(); ?>
                        <?php do_action('fluent_cart/receipt/thank_you/after_order_header', $this->config); ?>

                        <?php $this->renderStoreTaxInformation(); ?>

                        <?php do_action('fluent_cart/receipt/thank_you/before_order_items', $this->config); ?>
                        <?php $this->renderOrderItems(); ?>
                        <?php do_action('fluent_cart/receipt/thank_you/after_order_items', $this->config); ?>

                        <?php $this->renderSubscriptionItems(); ?>

                        <?php $this->renderDownloads(); ?>

                        <?php $this->renderLicenses(); ?>

                        <?php $this->renderAddress(); ?>

                        <?php $this->renderThankYouPageInstructions(); ?>

                    </div>
                </div>
            </div>
        </div>

        <?php
    }

    public function renderOrderHeader()
    {
        $order = Arr::get($this->config, 'order', null);
        $profilePage = $this->settings->getCustomerProfilePage();
        ?>
        <div class="no-print">
            <div class="no-print-title">
                <?php
                echo sprintf(
                /* translators: %s is the customer's full name */
                        esc_html__('Hello %s!', 'fluent-cart'),
                        esc_html($order->customer->full_name)
                );
                ?>
            </div>
            <?php if ($order->payment_status === 'paid') : ?>
                <p>
                    <?php
                    printf(
                            '%s<strong style="color: #007bff;"><a href="%s">#%s</a></strong>%s',
                            esc_html__('Your order ', 'fluent-cart'),
                            esc_url($profilePage . 'order/' . $order->uuid),
                            esc_html($order->invoice_no),
                            esc_html__(' has been placed successfully.', 'fluent-cart')
                    );
                    ?>
                </p>
            <?php else: ?>
                <p>

                    <?php
                    printf(
                            '<strong style="color: #007bff;"><a href="%s">%s</a></strong> %s <a style="color: #007bff;" target="_blank" href="%s">%s</a>.',
                            esc_url($profilePage . 'order/' . $order->uuid),
                            esc_html__('Your order', 'fluent-cart'),
                            esc_html__('has payment due. You can pay from', 'fluent-cart'),
                            esc_url(\FluentCart\App\Services\Payments\PaymentHelper::getCustomPaymentLink($order->uuid)),
                            esc_html__('here', 'fluent-cart')
                    );
                    ?>

                </p>
            <?php endif; ?>

        </div>

        <?php
    }

    public function renderStoreTaxInformation()
    {
        $order = Arr::get($this->config, 'order', null);

        $orderTaxRates = $order->getPrimaryOrderTaxRate();
        if ($order->tax_total > 0 || $orderTaxRates): ?>
            <div class="fct_invoice_tax_content">
                <?php
                if ($orderTaxRates) {
                    $storeVatNumber = Arr::get($orderTaxRates->meta, 'store_vat_number', '');
                    $taxcountry = Arr::get($orderTaxRates->meta ?? [], 'tax_country', '');
                    if ($storeVatNumber !== '') {
                        echo '<p>' . esc_html(TaxModule::getCountryTaxTitle($taxcountry)) . ': ' . esc_html($storeVatNumber) . '</p>';
                    }
                }
                ?>
            </div>
        <?php endif;
    }

    public function renderOrderItems()
    {
        $order = Arr::get($this->config, 'order', null);

        if ($order->order_items) : ?>
            <div class="fct-thank-you-page-order-items">
                <?php $this->renderOrderItemsHeader(); ?>

                <?php $this->renderOrderItemsBody(); ?>
            </div>
        <?php endif;
    }

    public function renderOrderItemsHeader()
    {
        ?>
        <div class="fct-thank-you-page-order-items-header">
            <div class="fct-thank-you-page-order-items-header-row">
                <?php echo esc_html__('Item', 'fluent-cart'); ?>
            </div>
            <div class="fct-thank-you-page-order-items-header-row">
                <?php echo esc_html__('Total', 'fluent-cart'); ?>
            </div>
        </div>
        <?php
    }

    public function renderOrderItemsBody()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <div class="fct-thank-you-page-order-items-body">
            <?php
            $order->loadMissing(['order_items']);
            $orderItems = $order->getProductItems()->toArray();
            $orderItems = $this->buildBundleItemsTree($orderItems);

            foreach ($orderItems as $item) :

                ?>
                <div class="fct-thank-you-page-order-items-list">
                    <div class="fct-thank-you-page-order-items-list-title">
                        <p class="fct-thank-you-page-order-items-list-quantity">
                            <?php echo esc_html($item['post_title']); ?>
                            <?php if ($item['quantity'] > 1): ?>
                                <span>x <?php echo esc_html(Helper::translateNumber($item['quantity'])); ?></span>
                            <?php endif; ?>
                        </p>
                        <p class="fct-thank-you-page-order-items-list-variant-title">
                            - <?php echo esc_html($item['title']); ?>
                        </p>
                        <?php if ($item['payment_type'] === 'subscription' && !empty($item['payment_info'])): ?>
                            <p class="fct-thank-you-page-order-items-list-payment-info">
                                <?php echo wp_kses_post($item['payment_info']) ?>
                            </p>
                        <?php endif; ?>
                        <?php $this->renderBundleProducts($item); ?>
                    </div>
                    <div class="fct-thank-you-page-order-items-list-price">
                        <div class="fct-thank-you-page-order-items-list-price-inner">
                            <?php echo esc_html($item['formatted_total']); ?>
                        </div>
                    </div>
                </div>

                <div class="fct-thank-you-page-order-items-tax-info">
                    <?php $this->renderItemTaxPill($item, $order); ?>
                    <?php if (!empty($item['setup_info'])): ?>
                        <div class="fct-thank-you-page-order-items-setup-fee">
                            <div class="setup-fee">
                                <?php echo esc_html(Arr::get($item, 'other_info.signup_fee_name', '')); ?>
                            </div>

                            <div class="setup-fee-amount">
                                <?php echo esc_html(Helper::toDecimal(Arr::get($item, 'other_info.signup_fee', ''))); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php $this->renderItemSetupFeeTaxPills($item, $order); ?>
                </div>
            <?php endforeach; ?>

            <?php $this->renderOrderTotal(); ?>

            <!-- tax note -->
            <?php $this->renderTaxNote(); ?>

        </div>
        <?php
    }

    public function renderThankYouPageInstructions()
    {
        $order = Arr::get($this->config, 'order', null);
        if (!$order) {
            return;
        }
        $transaction              = $order->getLatestTransaction();
        $methodSlug               = '';
        $thankYouPageInstructions = '';
        if ($transaction && !empty($transaction->payment_method)) {
            $methodSlug = $transaction->payment_method;
        }
        if (GatewayManager::has($methodSlug)) {
            $gatewayInstance = GatewayManager::getInstance($methodSlug);
            if ($gatewayInstance && isset($gatewayInstance->settings)) {
                $gatewaySettings          = (array) $gatewayInstance->settings->get();
                $thankYouPageInstructions = Arr::get($gatewaySettings, 'thank_you_page_instructions', '');
            }
        }

        if (!empty($thankYouPageInstructions)) : ?>

            <div style="max-width: 620px; margin: 15px auto 0; font-family: Arial, Helvetica, sans-serif;">
                <div style="font-size: 14px; color: #2F3448; padding: 12px 0; border-top: 1px solid #e7eaee;">
                    <?php echo wp_kses_post($thankYouPageInstructions); ?>
                </div>
            </div>
        <?php endif;
    }

    public function renderOrderTotal()
    {
        ?>
        <div class="fct-thank-you-page-order-items-total">
            <?php $this->renderSubtotal(); ?>
            <?php $this->renderDiscount(); ?>
            <?php $this->renderShipping(); ?>
            <?php $this->renderFees(); ?>
            <?php $this->renderTaxSummaryBox(); ?>

            <?php $this->renderProrateCredit(); ?>
            <?php $this->renderRefund(); ?>
            <?php $this->renderTotal(); ?>
            <?php $this->renderPaymentMethod(); ?>
        </div>
        <?php
    }

    public function renderTaxNote()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->isReverseChargeTaxOrder()): ?>
            <div style="text-align: right; font-size: 14px; margin-top: 10px;">
                <?php echo '*' . esc_html__('Tax to be paid on reverse charge basis', 'fluent-cart') ?>
            </div>
        <?php
        endif;
    }

    public function renderSubtotal()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->subtotal != $order->total_amount || $order->tax_total > 0): ?>
            <div class="fct-meta-line fct-thank-you-page-order-items-total-subtotal">
                <div class="fct-meta-line-label fct-thank-you-page-order-items-total-label">
                    <?php echo esc_html__('Subtotal', 'fluent-cart'); ?>
                </div>
                <div class="fct-meta-line-value fct-thank-you-page-order-items-total-value"><?php echo esc_html(Helper::toDecimal($order->subtotal)); ?></div>
            </div>
        <?php endif;
    }

    public function renderDiscount()
    {
        $order = Arr::get($this->config, 'order', null);
        $prorateCredit = (int) Arr::get($order->config, 'prorate_credit', 0);
        $upgradeDiscount = $order->manual_discount_total - $prorateCredit;
        // When the prorate credit IS the whole discount, label the row directly
        // instead of showing "Discount" with a single redundant breakdown row.
        $onlyProrateCredit = $prorateCredit > 0 && $upgradeDiscount <= 0 && $order->coupon_discount_total <= 0;

        if ($order->manual_discount_total + $order->coupon_discount_total > 0): ?>
        <div class="fct-meta-line fct-thank-you-page-order-items-total-discount">
            <div class="fct-meta-line-label fct-thank-you-page-order-items-total-label">
                <?php echo $onlyProrateCredit ? esc_html__('Prorate Credit', 'fluent-cart') : esc_html__('Discount', 'fluent-cart'); ?>
            </div>
            <div class="fct-meta-line-value fct-thank-you-page-order-items-total-value">
                - <?php echo esc_html(Helper::toDecimal($order->manual_discount_total + $order->coupon_discount_total)); ?>
            </div>
        </div>
        <?php if ($prorateCredit > 0 && $upgradeDiscount > 0): ?>
        <div class="fct-meta-line" style="padding-left:12px;">
            <div class="fct-meta-line-label" style="font-size:12px;color:rgb(107,114,128);">
                <?php echo esc_html__('Upgrade Discount', 'fluent-cart'); ?>
            </div>
            <div class="fct-meta-line-value" style="font-size:12px;color:rgb(107,114,128);">
                <?php echo esc_html(Helper::toDecimal($upgradeDiscount)); ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($prorateCredit > 0 && !$onlyProrateCredit): ?>
        <div class="fct-meta-line" style="padding-left:12px;">
            <div class="fct-meta-line-label" style="font-size:12px;color:rgb(107,114,128);">
                <?php echo esc_html__('Prorate Credit', 'fluent-cart'); ?>
            </div>
            <div class="fct-meta-line-value" style="font-size:12px;color:rgb(107,114,128);">
                <?php echo esc_html(Helper::toDecimal($prorateCredit)); ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif;
    }

    public function renderProrateCredit()
    {
        // Rendered as a sub-row inside renderDiscount.
    }

    public function renderShipping()
    {
        $order = Arr::get($this->config, 'order', null);

        if ($order->shipping_total <= 0) {
            return;
        }
        $displayShipping = (int) $order->shipping_total;
        if ($order->isReverseChargeTaxOrder()) {
            $rcAdj = (int) Arr::get(TaxSummaryHelper::computeTaxSummary($order), 'rcShippingAdjustment', 0);
            if ($rcAdj > 0) {
                $displayShipping = max(0, $displayShipping - $rcAdj);
            }
        }
        ?>
        <div class="fct-meta-line fct-thank-you-page-order-items-total-shipping">
            <div class="fct-meta-line-label fct-thank-you-page-order-items-total-label">
                <?php echo esc_html__('Shipping', 'fluent-cart'); ?>
            </div>
            <div class="fct-meta-line-value fct-thank-you-page-order-items-total-value"><?php echo esc_html(Helper::toDecimal($displayShipping)); ?></div>
        </div>
        <?php
    }

    public function renderFees()
    {
        $order = Arr::get($this->config, 'order', null);
        if (!$order || $order->fee_total <= 0) {
            return;
        }
        $feeItems = $order->feeItems()->get();
        foreach ($feeItems as $feeItem): ?>
            <div class="fct-meta-line fct-thank-you-page-order-items-total-fee">
                <div class="fct-meta-line-label fct-thank-you-page-order-items-total-label">
                    <?php echo esc_html($feeItem->title); ?>
                </div>
                <div class="fct-meta-line-value fct-thank-you-page-order-items-total-value">
                    <?php echo esc_html(Helper::toDecimal($feeItem->subtotal)); ?>
                </div>
            </div>
        <?php endforeach;
    }

    public function renderTaxTotal()
    {
        $order = Arr::get($this->config, 'order', null);

        if ($order->isReverseChargeTaxOrder()): ?>
            <?php
                $rcReversedTotal = $order->getReversedTaxTotal();
                $rcChargeLabel = $rcReversedTotal > 0
                    ? sprintf(
                        /* translators: %1$s: formatted reversed tax amount */
                        __('Tax reversed: %1$s', 'fluent-cart'),
                        Helper::toDecimal($rcReversedTotal)
                    )
                    : __('Charge reversed', 'fluent-cart');
            ?>
            <div class="fct-meta-line fct-thank-you-page-order-items-total-tax">
                <div class="fct-meta-line-label fct-thank-you-page-order-items-total-label">
                    <?php echo esc_html__('Tax', 'fluent-cart'); ?>
                </div>
                <div class="fct-meta-line-value fct-thank-you-page-order-items-total-value">
                    <?php echo esc_html($rcChargeLabel); ?>
                </div>
            </div>
        <?php elseif ($order->tax_total > 0): ?>
            <div class="fct-meta-line fct-thank-you-page-order-items-total-tax">
                <div class="fct-meta-line-label fct-thank-you-page-order-items-total-label">
                    <?php echo esc_html__('Total Tax', 'fluent-cart'); ?>
                    <?php echo \FluentCart\App\Helpers\Helper::getOrderTaxLabel($order); ?>
                </div>
                <div class="fct-meta-line-value fct-thank-you-page-order-items-total-value">
                    <?php echo esc_html(Helper::toDecimal($order->tax_total)); ?>
                </div>
            </div>
        <?php endif;
    }

    public function renderShippingTax()
    {
        $order = Arr::get($this->config, 'order', null);

        if ($order->shipping_tax <= 0) {
            return;
        }
        ?>
        <div class="fct-meta-line fct-thank-you-page-order-items-total-shipping-tax">
            <div class="fct-meta-line-label fct-thank-you-page-order-items-total-label">
                <?php echo esc_html__('Shipping Tax', 'fluent-cart'); ?>
                <?php echo \FluentCart\App\Helpers\Helper::getOrderTaxLabel($order); ?>
            </div>
            <div class="fct-meta-line-value fct-thank-you-page-order-items-total-value">
                <?php echo esc_html(Helper::toDecimal($order->shipping_tax)); ?>
            </div>
        </div>
        <?php
    }

    public function renderRefund()
    {
        $order = Arr::get($this->config, 'order', null);

        if ($order->total_refund > 0): ?>
            <div class="fct-meta-line fct-thank-you-page-order-items-total-refund">
                <div class="fct-meta-line-label fct-thank-you-page-order-items-total-label">
                    <?php echo esc_html__('Refund', 'fluent-cart'); ?>
                </div>
                <div class="fct-meta-line-value fct-thank-you-page-order-items-total-value">
                    - <?php echo esc_html(Helper::toDecimal($order->total_refund)); ?>
                </div>
            </div>
        <?php endif;
    }

    public function renderTotal()
    {
        $order = Arr::get($this->config, 'order', null);
        $displayTotal = (int) $order->total_amount - (int) $order->total_refund;
        if ($order->isReverseChargeTaxOrder()) {
            $rcAdj = (int) Arr::get(TaxSummaryHelper::computeTaxSummary($order), 'rcTotalAdjustment', 0);
            if ($rcAdj > 0) {
                $displayTotal = max(0, $displayTotal - $rcAdj);
            }
        }
        ?>
        <div class="fct-meta-line fct-thank-you-page-order-items-total-total">
            <div class="fct-meta-line-label fct-thank-you-page-order-items-total-label">
                <?php echo esc_html__('Total', 'fluent-cart'); ?>
            </div>
            <div class="fct-meta-line-value fct-thank-you-page-order-items-total-value">
                <?php echo esc_html(Helper::toDecimal($displayTotal)); ?>
            </div>
        </div>
        <?php
    }

    public function renderPaymentMethod()
    {
        $order = Arr::get($this->config, 'order', null);
        $transaction = $order->getLatestTransaction();
        ?>
        <div class="fct-meta-line fct-thank-you-page-order-items-total-payment-method">
            <div class="fct-meta-line-label fct-thank-you-page-order-items-total-label">
                <?php echo esc_html__('Payment Method', 'fluent-cart'); ?>
            </div>
            <div class="fct-meta-line-value fct-thank-you-page-order-items-total-value">
                <?php if ($transaction->card_last_4) :
                    echo esc_html($transaction->card_brand) . ' ' . esc_html($transaction->card_last_4);
                else:
                    $title = $transaction->payment_method;
                    $gateway = GatewayManager::getInstance($transaction->payment_method);
                    $title = $gateway ? $gateway->getMeta('title') : $title;
                    echo esc_html($title);
                endif; ?>
            </div>
        </div>
        <?php
    }

    public function renderSubscriptionItems()
    {
        $order = Arr::get($this->config, 'order', null);
        $subscriptions = $order->subscriptions;

        if ($subscriptions->count() == 0) {
            return;
        }
        ?>
        <div class="fct-thank-you-page-order-items-subscriptions">
            <p class="fct-thank-you-page-order-items-subscriptions-heading">
                <?php echo esc_html__('Subscription Details', 'fluent-cart'); ?>
            </p>

            <table class="fct-thank-you-page-order-items-subscriptions-table" role="presentation">
                <tbody>
                <?php foreach ($subscriptions as $subs): ?>
                    <tr>
                        <td class="fct-thank-you-page-order-items-subscriptions-table-file">
                            <p>
                                <?php echo esc_html($subs->item_name); ?>
                            </p>
                        </td>

                        <td class="fct-thank-you-page-order-items-subscriptions-billing-infos">
                            <div>
                                <?php if (!empty($subs->payment_info)) : ?>
                                    <span>
                                        <?php echo $subs->payment_info; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($subs->next_billing_date)) : ?>
                                    <p class="fct-thank-you-page-order-items-subscriptions-billing-infos-next-billing"><?php
                                        echo sprintf(
                                        /* translators: 1: Next billing date */
                                                esc_html__('- Auto renews on %1$s', 'fluent-cart'),
                                                esc_html(
                                                        \FluentCart\App\Services\DateTime\DateTime::gmtToTimezone($subs->next_billing_date)->format('M d, Y h:i A')
                                                )
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>


        <?php
//        App::make('view')->render('invoice.parts.subscription_items', [
//            'subscriptions' => $order->subscriptions,
//            'order'         => $order
//        ]);
    }

    public function renderDownloads()
    {
        $order = Arr::get($this->config, 'order', null);
        $downloads = $order->getDownloads();
        if (!$downloads) {
            return;
        }
        ?>
        <div class="fct-thank-you-page-order-items-downloads">
            <p class="fct-thank-you-page-order-items-downloads-heading">
                <?php echo esc_html__('Downloads', 'fluent-cart'); ?>
            </p>

            <table class="fct-thank-you-page-order-items-downloads-table"
                   role="presentation">
                <tbody>
                <tr>
                    <td>
                        <table role="presentation" width="100%">
                            <tbody>
                            <?php foreach ($downloads as $downloadItem): ?>
                                <tr>
                                    <td>
                                        <?php if ($downloadItem['downloads']): ?>

                                            <table role="presentation" width="100%">
                                                <tbody>
                                                <?php foreach ($downloadItem['downloads'] as $download): ?>
                                                    <tr>
                                                        <td class="fct-thank-you-page-order-items-downloads-table-file">
                                                            <p>
                                                                <?php echo esc_html($download['title']); ?>
                                                                <?php if ($download['file_size']): ?>
                                                                    <span>(<?php echo esc_html(Helper::readableFileSize($download['file_size'])); ?>)</span>
                                                                <?php endif; ?>
                                                            </p>
                                                        </td>
                                                        <td class="fct-thank-you-page-order-items-downloads-button">
                                                            <a href="<?php echo esc_url($download['download_url'] ?? ''); ?>">
                                                                <?php echo esc_html__('Download', 'fluent-cart'); ?>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>

                                        <?php endif; ?>

                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <?php $this->renderDownloadsNotice(); ?>

        <?php
    }

    public function renderDownloadsNotice($show_notice = false)
    {
        $showNotice = $show_notice ?? true;
        ?>
        <?php if (!$showNotice) {
        return;
    } ?>
        <table
                class="fct-thank-you-page-order-items-downloads-notice"
                role="presentation">
            <tbody>
            <tr>
                <td>
                    <p class="fct-thank-you-page-order-items-downloads-notice-title">
                        <?php echo esc_html__('Important', 'fluent-cart'); ?>
                    </p>
                    <p class="fct-thank-you-page-order-items-downloads-notice-content">
                        <?php echo esc_html__('This download link is valid for 7 days. After that, you can download the files again from your account
                        on our website.', 'fluent-cart'); ?>
                    </p>
                </td>
            </tr>
            </tbody>
        </table>
        <?php
    }

    public function renderLicenseNotice($show_notice = false)
    {
        $showNotice = $show_notice ?? true;
        ?>
        <?php if (!$showNotice) {
        return;
    } ?>
        <table
                class="fct-thank-you-page-order-items-downloads-notice"
                role="presentation">
            <tbody>
            <tr>
                <td>
                    <p class="fct-thank-you-page-order-items-downloads-notice-title">
                        <?php echo esc_html__('Important', 'fluent-cart'); ?>
                    </p>
                    <p class="fct-thank-you-page-order-items-downloads-notice-content">
                        <?php echo esc_html__('This download link is valid for 7 days. After that, you can download the files again from your account on our website.', 'fluent-cart'); ?>
                    </p>
                </td>
            </tr>
            </tbody>
        </table>
        <?php
    }

    public function renderLicenses()
    {
        $order = Arr::get($this->config, 'order', null);
        $licenses = $order->getLicenses();
        if (!$licenses || $licenses->count() == 0) {
            return;
        }
        ?>
        <div class="fct-thank-you-page-order-items-licenses">
            <p class="fct-thank-you-page-order-items-licenses-heading">
                <?php echo esc_html__('Licenses', 'fluent-cart'); ?>
            </p>
            <table class="fct-thank-you-page-order-items-licenses-table" role="presentation">
                <tbody>
                <tr>
                    <td>
                        <table role="presentation">
                            <tbody>

                            <?php foreach ($licenses as $license): ?>
                                <tr>
                                    <td class="fct-thank-you-page-order-items-licenses-table-file">
                                        <p>
                                            <?php  echo esc_html($license->productVariant->variation_title); ?>:
                                            <?php echo esc_html($license->license_key); ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <?php $this->renderLicenseNotice(); ?>
        <?php
    }

    public function renderAddress()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <div class="fct-thank-you-page-order-items-addresses">
            <?php $this->renderBillToAddress(); ?>
            <?php if ($order->fulfillment_type === 'physical') : ?>
                <?php $this->renderShipToAddress(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function renderBillToAddress()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <div class="fct-thank-you-page-order-items-addresses-bill-to">
            <h5>
                <?php echo esc_html__('Bill To', 'fluent-cart'); ?>
            </h5>
            <?php
            if (!empty($order->billing_address)) :
                ?>
                <div class="fct-thank-you-page-order-items-addresses-bill-to-address">
                    <?php echo esc_html($order->billing_address->getAddressAsText()); ?>
                </div>

                <div class="fct-thank-you-page-order-items-addresses-bill-to-email">
                    <?php echo esc_html($order->customer->email); ?>
                </div>
            <?php else: ?>
                <div class="fct-thank-you-page-order-items-addresses-bill-to-name">
                    <?php echo esc_html($order->customer->full_name); ?>
                </div>
                <div class="fct-thank-you-page-order-items-addresses-bill-to-email">
                    <?php echo esc_html($order->customer->email); ?>
                </div>
            <?php endif; ?>
            <?php
            $phoneNumber = Arr::get($order->billing_address->meta ?? [], 'other_data.phone', '');
            if ($phoneNumber !== ''):
                ?>
                <div class="fct-thank-you-page-order-items-addresses-bill-to-phone">
                    <?php echo esc_html($phoneNumber); ?>
                </div>
            <?php endif; ?>
            <?php
            $companyName = $order->billing_address
                    ? Arr::get($order->billing_address->meta ?? [], 'other_data.company_name', '')
                    : '';
            if ($companyName === '') {
                $companyName = $order->getCustomerTaxName();
            }
            if ($companyName !== ''):
                ?>
                <div class="fct-thank-you-page-order-items-addresses-bill-to-company-name">
                    <?php echo esc_html($companyName); ?>
                </div>
            <?php endif; ?>
            <?php
            $legalRegId = $order->billing_address
                    ? Arr::get($order->billing_address->meta ?? [], 'other_data.legal_registration_id', '')
                    : '';
            if ($legalRegId === '') {
                $legalRegId = Arr::get($order->getBusinessInfo(), 'legal_registration_id', '');
            }
            if ($legalRegId !== ''):
                ?>
                <div class="fct-thank-you-page-order-items-addresses-bill-to-legal-reg">
                    <?php echo esc_html__('Reg. ID', 'fluent-cart') . ': ' . esc_html($legalRegId); ?>
                </div>
            <?php endif; ?>
            <div class="fct-thank-you-page-order-items-addresses-bill-to-vat-number">
                <?php
                $vatNumber = $order->getCustomerTaxNumber();
                if ($vatNumber !== '') {
                    $vatLabel = __('VAT/Tax ID', 'fluent-cart');
                    echo esc_html($vatLabel) . ': ' . esc_html($vatNumber);
                }
                ?>
            </div>
            <?php
            $reverseChargeDeclaration = Arr::get($order->getBusinessInfo(), 'reverse_charge_declaration', '');
            if ($reverseChargeDeclaration !== ''):
                ?>
                <div class="fct-thank-you-page-order-items-addresses-bill-to-reverse-charge">
                    <strong><?php echo esc_html__('Reverse Charge Declaration', 'fluent-cart'); ?>:</strong>
                    <?php echo esc_html($reverseChargeDeclaration); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function renderShipToAddress()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <div class="fct-thank-you-page-order-items-addresses-ship-to">
            <h5 class="fct-thank-you-page-order-items-addresses-ship-to-title">
                <?php echo esc_html__('Ship To', 'fluent-cart'); ?>
            </h5>
            <?php if (!empty($order->shipping_address)) : ?>
                <div class="fct-thank-you-page-order-items-addresses-ship-to-address">
                    <?php echo esc_html($order->shipping_address->getAddressAsText()); ?>
                </div>
                <!-- show phone number -->
                <?php
                $phone = Arr::get($order->shipping_address->meta ?? [], 'other_data.phone', '');
                if ($phone !== ''):?>
                    <div class="fct-thank-you-page-order-items-addresses-ship-to-phone">
                        <?php echo esc_html($phone); ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="fct-thank-you-page-order-items-addresses-ship-to-name">
                    <?php echo esc_html($order->customer->full_name); ?>
                </div>
                <div class="fct-thank-you-page-order-items-addresses-ship-to-email">
                    <?php echo esc_html($order->customer->email); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function renderFooter()
    {
        ?>
        <div class="fct-thank-you-page-footer">
            <?php do_action('fluent_cart/receipt/thank_you/before_footer_buttons', $this->config); ?>
            <?php $this->renderViewOrderButton(); ?>
            <?php $this->renderDownloadReceiptButton(); ?>
            <?php do_action('fluent_cart/receipt/thank_you/after_footer_buttons', $this->config); ?>
        </div>
        <?php
    }

    public function renderViewOrderButton()
    {
        $order = Arr::get($this->config, 'order', null);
        $profilePage = $this->settings->getCustomerProfilePage();

        ?>
        <a
                class="fct-thank-you-page-view-order-button"
                href="<?php echo esc_url($profilePage . 'order/' . $order->uuid); ?>">
            <?php echo esc_html__('View Order', 'fluent-cart'); ?>
        </a>
        <?php
    }

    public function renderDownloadReceiptButton()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <a
                class="fct-thank-you-page-download-receipt-button"
                href="<?php echo esc_url(\FluentCart\App\Services\URL::appendQueryParams(
                        home_url(),
                        [
                                'fluent-cart' => 'receipt',
                                'order_hash'  => $order->uuid,
                                'download'    => 1
                        ]
                )) ?>">
            <?php echo esc_html__('Download Receipt', 'fluent-cart'); ?>
        </a>
        <?php
    }

    /**
     * Build a parent → bundle items relationship from flat order items
     *
     * Converts a flat order items list into:
     * - Parent items
     * - Each parent contains a ` bundle_items ` array
     *
     * @param array $orderItems
     * @return array
     */
    protected function buildBundleItemsTree($orderItems)
    {
        if (!$orderItems) {
            return [];
        }

        $parents = [];
        $childrenByParent = [];

        // Separate parents and children
        foreach ($orderItems as $item) {
            $parentId = Arr::get($item, 'line_meta.bundle_parent_item_id', null);

            // If bundle child → group by parent ID
            if ($parentId) {
                $childrenByParent[$parentId][] = $item;

                // Otherwise it's a parent item
            } else {
                $item['bundle_items'] = [];
                $parents[$item['id']] = $item;
            }
        }

        // Attach children to their parent
        foreach ($childrenByParent as $parentId => $children) {
            if (isset($parents[$parentId])) {
                $parents[$parentId]['bundle_items'] = $children;
            }
        }

        // Re-index a final array
        return array_values($parents);
    }

    public function renderBundleProducts($item)
    {
        //dd($item);
        if (!$item['bundle_items']) {
            return;
        }

        $total = count($item['bundle_items']);
        ?>
        <div class="fct-bundle-products" data-fluent-cart-collapsibles>
            <h4 class="fct-bundle-products-title">
                <?php echo esc_html__('Bundle of', 'fluent-cart') . ':'; ?>
            </h4>

            <div class="fct-bundle-products-list">
                <?php foreach (array_slice($item['bundle_items'], 0, 2) as $bundleItem): ?>
                    <p>
                        <?php echo esc_html(Arr::get($bundleItem, 'title', '')); ?>
                    </p>
                <?php endforeach; ?>

                <?php if ($total > 2): ?>
                    <div class="fct-bundle-products-more">
                        <div class="fct-bundle-products-more-list">
                            <?php foreach (array_slice($item['bundle_items'], 2) as $bundleItem): ?>
                                <p>
                                    <?php echo esc_html(Arr::get($bundleItem, 'title', '')); ?>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($total > 2) : ?>
                <a href="#" class="fct-see-more-btn" data-fluent-cart-collapsible-toggle>
                        <span class="see-more-text">
                            <?php echo esc_html__('See More', 'fluent-cart'); ?>
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="7" viewBox="0 0 12 7" fill="none">
                                <path d="M0.75 0.75L5.04289 5.04289C5.37623 5.37623 5.54289 5.54289 5.75 5.54289C5.95711 5.54289 6.12377 5.37623 6.45711 5.04289L10.75 0.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>

                    <span class="see-less-text">
                            <?php echo esc_html__('See Less', 'fluent-cart'); ?>
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="7" viewBox="0 0 14 8" fill="none">
                                <path d="M0.75 6.54297L6.04289 1.25008C6.37623 0.916742 6.54289 0.750076 6.75 0.750076C6.95711 0.750076 7.12377 0.916742 7.45711 1.25008L12.75 6.54297" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    public function renderTaxSummaryBox()
    {
        $order   = Arr::get($this->config, 'order', null);
        $summary = TaxSummaryHelper::computeTaxSummary($order);
        if (!$summary['shouldRender']) {
            return;
        }
        ?>
        <div class="fct-thank-you-tax-summary">
            <div class="fct-thank-you-tax-summary-header">
                <span class="fct-thank-you-tax-summary-title">
                    <?php echo esc_html__('TAX SUMMARY', 'fluent-cart'); ?>
                </span>
                <span class="fct-item-tax-hint" tabindex="0"
                      aria-label="<?php echo esc_attr__('Tax breakdown explanation', 'fluent-cart'); ?>">
                    <span class="fct-item-tax-hint-icon">i</span>
                    <span class="fct-item-tax-hint-tooltip">
                        <?php echo esc_html__('Inclusive tax is embedded in item prices. Exclusive tax is added on top.', 'fluent-cart'); ?>
                    </span>
                </span>
            </div>

            <?php if ($summary['isReverseCharge']): ?>
                <?php
                    $rcReversedTotal    = (int) Arr::get($summary, 'reversedTaxTotal', 0);
                    $rcReversedShipping = (int) Arr::get($summary, 'reversedShippingTax', 0);
                    $rcReversedValue    = $rcReversedTotal > 0
                        ? Helper::toDecimal($rcReversedTotal)
                        : __('Charge reversed', 'fluent-cart');
                ?>
                <?php if ($summary['showRcShippingRow'] && $rcReversedShipping > 0): ?>
                <div class="fct-thank-you-tax-summary-row fct-thank-you-tax-summary-row--muted">
                    <span><?php echo esc_html__('Added on shipping', 'fluent-cart'); ?></span>
                    <span style="text-decoration:line-through;opacity:0.6;"><?php echo esc_html(Helper::toDecimal($rcReversedShipping)); ?></span>
                </div>
                <?php endif; ?>
                <div class="fct-thank-you-tax-summary-row fct-thank-you-tax-summary-row--total">
                    <span><?php echo esc_html__('Tax reversed', 'fluent-cart'); ?></span>
                    <span><?php echo esc_html($rcReversedValue); ?></span>
                </div>
            <?php else: ?>
                <?php
                    $tyFeeRows  = Arr::get($summary, 'feeTaxLineRows', []);
                    $rowCount   = (int) ($summary['inclusiveTax'] > 0) + (int) ($summary['exclusiveTax'] > 0) + count($tyFeeRows) + (int) ($summary['shippingTax'] > 0);
                    $shouldShowBreakdown = $rowCount >= 2 || ($rowCount === 1 && !($summary['payableTax'] > 0 || $summary['inclusiveTax'] > 0 || (int) Arr::get($summary, 'inclusiveFeeTax', 0) > 0));
                ?>
                <?php if ($summary['inclusiveTax'] > 0 && $shouldShowBreakdown): ?>
                    <div class="fct-thank-you-tax-summary-row fct-thank-you-tax-summary-row--muted">
                        <span><?php echo esc_html__('Included in item prices', 'fluent-cart'); ?></span>
                        <span><?php echo esc_html(Helper::toDecimal($summary['inclusiveTax'])); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($summary['exclusiveTax'] > 0 && $shouldShowBreakdown): ?>
                    <div class="fct-thank-you-tax-summary-row">
                        <span><?php echo esc_html__('Added on products', 'fluent-cart'); ?></span>
                        <span><?php echo esc_html(Helper::toDecimal($summary['exclusiveTax'])); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($shouldShowBreakdown) : ?>
                <?php foreach ($tyFeeRows as $feeRow) : ?>
                <div class="fct-thank-you-tax-summary-row<?php echo $feeRow['inclusive'] ? ' fct-thank-you-tax-summary-row--muted' : ''; ?>">
                    <span><?php echo esc_html($feeRow['display_label']); ?></span>
                    <span><?php echo esc_html(Helper::toDecimal($feeRow['tax_amount'])); ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($summary['shippingTax'] > 0 && $shouldShowBreakdown):
                    $isShippingInclusive    = (bool) Arr::get($summary, 'isShippingInclusive', false);
                    $shippingTaxLines       = Arr::get($summary, 'shippingTaxLines', []);
                    $shippingRowClass       = 'fct-thank-you-tax-summary-row' . ($isShippingInclusive ? ' fct-thank-you-tax-summary-row--muted' : '');
                    if (!empty($shippingTaxLines)):
                        foreach ($shippingTaxLines as $shLine): ?>
                            <div class="<?php echo esc_attr($shippingRowClass); ?>">
                                <span><?php echo esc_html($shLine['label']); ?></span>
                                <span><?php echo esc_html(Helper::toDecimal($shLine['shipping_tax'])); ?></span>
                            </div>
                        <?php endforeach;
                    else: ?>
                        <div class="<?php echo esc_attr($shippingRowClass); ?>">
                            <span>
                                <?php if ($isShippingInclusive): ?>
                                    <?php echo esc_html__('Included in shipping prices', 'fluent-cart'); ?>
                                <?php else: ?>
                                    <?php echo esc_html__('Added on shipping', 'fluent-cart'); ?>
                                <?php endif; ?>
                            </span>
                            <span><?php echo esc_html(Helper::toDecimal($summary['shippingTax'])); ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($summary['payableTax'] > 0): ?>
                    <div class="fct-thank-you-tax-summary-row fct-thank-you-tax-summary-row--total">
                        <span><?php echo esc_html__('Total payable tax', 'fluent-cart'); ?></span>
                        <span><?php echo esc_html(Helper::toDecimal($summary['payableTax'])); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($summary['inclusiveTax'] > 0 || $summary['inclusiveFeeTax'] > 0): ?>
                    <div class="fct-thank-you-tax-summary-row fct-thank-you-tax-summary-row--muted">
                        <span><?php echo esc_html__('Total tax in this order', 'fluent-cart'); ?></span>
                        <span><?php echo esc_html(Helper::toDecimal($summary['totalOrderTax'])); ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function renderItemTaxPill($item, $order)
    {
        $rates      = TaxSummaryHelper::getItemTaxRates($item);
        $isReversed = $order->isReverseChargeTaxOrder();
        $rcMode     = $order->getOrderRcMode();

        if (!empty($rates)) {
            $this->renderTaxRatePills($rates, $isReversed, $rcMode);
            return;
        }

        // Fallback: existing aggregate pill for old orders without tax_config
        $taxAmount = (int) ($item['tax_amount'] ?? 0);
        if ($taxAmount <= 0) {
            return;
        }
        $isInclusive  = TaxSummaryHelper::isPrimaryTaxInclusive($order);
        $pillClass    = $isInclusive ? 'fct-tax-pill--inclusive' : 'fct-tax-pill--exclusive';
        $reversedClass = ($isReversed && (!$isInclusive || $rcMode === 'dynamic')) ? ' is-reversed' : '';
        ?>
        <div class="fct-item-tax-pills">
            <div class="fct-item-tax-pill-row">
                <span class="fct-tax-pill <?php echo esc_attr($pillClass); ?>">
                    <?php echo $isInclusive ? esc_html__('Incl.', 'fluent-cart') : esc_html__('Add.', 'fluent-cart'); ?>
                </span>
                <span class="fct-item-tax-pill-amount <?php echo esc_attr(($isInclusive ? 'is-inclusive' : 'is-exclusive') . $reversedClass); ?>">
                    <?php if ($isInclusive): ?>
                        <?php echo esc_html__('incl.', 'fluent-cart'); ?> <?php echo esc_html(Helper::toDecimal($taxAmount)); ?>
                    <?php else: ?>
                        + <?php echo esc_html(Helper::toDecimal($taxAmount)); ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php
    }

    public function renderItemSetupFeeTaxPills($item, $order)
    {
        $isSubscription = $item['payment_type'] === 'subscription'
                || !empty(Arr::get($item, 'other_info.signup_fee'));

        if (!$isSubscription) {
            return;
        }

        $isReversed    = $order->isReverseChargeTaxOrder();
        $rcMode        = $order->getOrderRcMode();
        $signupFeeItem = null;
        if ($order->order_items) {
            $signupFeeItem = $order->order_items
                    ->where('payment_type', 'signup_fee')
                    ->where('object_id', $item['object_id'])
                    ->first();
        }

        if ($signupFeeItem) {
            $rates = TaxSummaryHelper::getItemTaxRates($signupFeeItem->toArray());
            if (!empty($rates)) {
                $this->renderTaxRatePills($rates, $isReversed, $rcMode);
                return;
            }
        }

        $signupFeeTax = (int) Arr::get($item, 'other_info.signup_fee_tax', 0);
        if ($signupFeeTax <= 0) {
            return;
        }
        $sfIsInclusive = TaxSummaryHelper::isPrimaryTaxInclusive($order);
        $reversedClass = ($isReversed && (!$sfIsInclusive || $rcMode === 'dynamic')) ? ' is-reversed' : '';
        ?>
        <div class="fct-item-tax-pills">
            <div class="fct-item-tax-pill-row">
                <span class="fct-item-tax-pill-amount<?php echo esc_attr($reversedClass); ?>"><?php
                    /* translators: %1$s: formatted setup fee tax amount */
                    echo sprintf(esc_html__('Setup fee tax: %1$s', 'fluent-cart'), esc_html(Helper::toDecimal($signupFeeTax)));
                    ?></span>
            </div>
        </div>
        <?php
    }

    private function renderTaxRatePills(array $rates, bool $isReversed = false, string $rcMode = 'fixed')
    {
        ?>
        <div class="fct-item-tax-pills">
            <?php foreach ($rates as $rate) : ?>
                <?php
                $pillClass = $rate['inclusive'] ? 'fct-tax-pill--inclusive' : 'fct-tax-pill--exclusive';
                $percent   = rtrim(rtrim(number_format($rate['rate_percent'], 3, '.', ''), '0'), '.');
                $rateIsInclusive = !empty($rate['inclusive']);
                $rateReversedClass = ($isReversed && (!$rateIsInclusive || $rcMode === 'dynamic')) ? ' is-reversed' : '';
                ?>
                <div class="fct-item-tax-pill-row">
                    <span class="fct-tax-pill <?php echo esc_attr($pillClass); ?>">
                        <?php echo esc_html($rate['label']) . ' (' . esc_html($percent) . '%)'; ?>
                    </span>
                    <span class="fct-item-tax-pill-amount <?php echo esc_attr(($rate['inclusive'] ? 'is-inclusive' : 'is-exclusive') . $rateReversedClass); ?>">
                        <?php if ($rate['inclusive']): ?>
                            <?php echo esc_html__('incl.', 'fluent-cart'); ?> <?php echo esc_html(Helper::toDecimal($rate['tax_amount'])); ?>
                        <?php else: ?>
                            + <?php echo esc_html(Helper::toDecimal($rate['tax_amount'])); ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

}
