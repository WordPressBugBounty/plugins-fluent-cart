<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class ShippingMethodsRender
{
    protected $shippingMethods = [];

    protected $selectedId = null;

    public function __construct($shippingMethods = [], $selectedId = null)
    {
        $this->shippingMethods = $shippingMethods;
        $this->selectedId = $selectedId;
    }

    public function render()
    {
        $errorId = 'shipping-methods-error';

        ?>
        <div class="fct_shipping_methods" id="shipping_methods" data-fluent-cart-checkout-page-shipping-methods-wrapper>
            <div class="fct_checkout_form_section" aria-describedby="<?php echo esc_attr($errorId); ?>">
                <div class="fct_form_section_header">
                    <h4 id="shipping-methods-title" class="fct_form_section_header_label">
                        <?php echo esc_html__('Shipping Options', 'fluent-cart') ?>
                    </h4>
                </div>
                <div class="fct_form_section_body">
                    <?php $this->renderBody(); ?>
                </div>
                <span
                        id="<?php echo esc_attr($errorId); ?>"
                        data-fluent-cart-checkout-page-form-error=""
                        class="fct_form_error"
                        role="alert"
                        aria-live="polite"
                ></span>
            </div>
        </div>
        <?php
    }

    public function renderBody()
    {
        if (is_wp_error($this->shippingMethods)) {
            $this->renderEmpty($this->shippingMethods->get_error_message());
        } else if ($this->shippingMethods) {
            $this->renderMethods();
        } else {
            $this->renderEmptyState();
        }
    }

    public function renderEmptyState()
    {
        ?>
        <div class="fct-empty-state" role="alert">
            <?php echo esc_html__('No shipping methods available for this address.', 'fluent-cart') ?>
        </div>
        <?php
    }

    public function renderMethods()
    {
        if (is_wp_error($this->shippingMethods)) {
            return;
        }
        $errorId = 'shipping-methods-error';
        ?>
        <div
                class="fct_shipping_methods_list"
                data-fluent-cart-checkout-page-shipping-method-wrapper
                role="radiogroup"
                aria-labelledby="shipping-methods-title"
                aria-describedby="<?php echo esc_attr($errorId); ?>"
        >
            <?php $this->renderLoader(); ?>

            <input type="hidden" name="fc_selected_shipping_method" value="<?php echo esc_attr($this->selectedId); ?>">
            <?php foreach ($this->shippingMethods as $shippingMethod) : ?>
                <div class="fct_shipping_methods_item">
                    <input
                            type="radio"
                            <?php echo checked($this->selectedId, $shippingMethod->id); ?>
                            name="fc_shipping_method"
                            id="shipping_method_<?php echo esc_attr($shippingMethod->id); ?>"
                            value="<?php echo esc_attr($shippingMethod->id); ?>"
                    />
                    <label for="shipping_method_<?php echo esc_attr($shippingMethod->id); ?>">
                        <?php
                        $description = Arr::get($shippingMethod->meta, 'description', '');
                        ?>
                        <?php echo esc_html($shippingMethod->title); ?>
                        <span class="shipping-method-amount" aria-label="<?php
                        /* translators: %s charge amount */
                        printf(esc_attr__('Shipping cost: %s', 'fluent-cart'),
                                esc_html(Helper::toDecimal($shippingMethod->charge_amount))); ?>"
                        >
                            <?php echo esc_html(Helper::toDecimal($shippingMethod->charge_amount)); ?>
                        </span>
                        <span class="fct-checkmark" aria-hidden="true"></span>
                        <?php if (!empty($description)) : ?>
                            <small class="fct_shipping_method_description"><?php echo esc_html($description); ?></small>
                        <?php endif; ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
//        do_action('fluent_cart/views/checkout_page_shipping_method_list', [
//            'shipping_methods' => $this->shippingMethods
//        ]);
    }

    public function renderEmpty($message)
    {
        ?>
        <div class="fct-empty-state" role="alert">
            <?php echo wp_kses_post($message); ?>
        </div>
        <?php
    }

    public function renderLoader()
    {
        ?>
            <div class="fct_shipping_methods_loader">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10" opacity="0.2" fill="none" stroke="currentColor" stroke-miterlimit="10" stroke-width="2.5"></circle>

                    <path d="m12,2c5.52,0,10,4.48,10,10" fill="none" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2.5">
                        <animateTransform attributeName="transform" attributeType="XML" type="rotate" dur="0.5s" from="0 12 12" to="360 12 12" repeatCount="indefinite"></animateTransform>
                    </path>
                </svg>
            </div>
        <?php
    }
}
