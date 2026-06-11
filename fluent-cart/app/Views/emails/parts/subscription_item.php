<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php if (isset($heading)): ?>
    <p style="font-size:16px;font-weight:500;color:rgb(44,62,80);margin:0px;margin-bottom:16px;line-height:24px;margin-top:0px;margin-left:0px;margin-right:0px">
        <?php echo esc_html($heading); ?>
    </p>
<?php endif; ?>

<table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
       style="border-width:1px;border-color:rgb(229,231,235);border-radius:8px;overflow:hidden;margin-bottom:16px;">
    <tbody>
    <tr>
        <td>
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
                   style="background-color:rgb(249,250,251);padding-left:16px;padding-right:16px;padding-top:0px;padding-bottom:0px;border-bottom-width:1px;border-color:rgb(229,231,235)">
                <tbody style="width:100%">
                <tr style="width:100%">
                    <td style="width:80%">
                        <p style="font-size:12px;font-weight:600;color:rgb(55,65,81);text-transform:uppercase;margin:0px;line-height:24px;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px">
                            <?php esc_html_e('Subscription', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td data-id="__react-email-column" style="width:20%;text-align:right">
                        <p style="font-size:12px;font-weight:600;color:rgb(55,65,81);text-transform:uppercase;margin:0px;line-height:24px;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px">
                            <?php esc_html_e('Price', 'fluent-cart'); ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
                   style="padding-left:16px;padding-right:16px;padding-top:0px;padding-bottom:0px;border-bottom-width:1px;border-color:rgb(243,244,246)">
                <tbody style="width:100%">
                <tr style="width:100%">
                    <td style="width:80%">
                        <p style="font-size:14px;font-weight:600;color:rgb(17,24,39);margin-bottom:2px;line-height:24px;margin-top:16px">
                            <?php echo esc_html($subscription->item_name); ?>
                        </p>
                    </td>
                    <td style="width:20%;text-align:right">
                        <p style="font-size:14px;color:rgb(17,24,39);margin:0px;line-height:24px;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($transaction->total)); ?>
                            (<?php echo esc_html($subscription->billing_interval); ?>)
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>
        </td>
    </tr>
    </tbody>
</table>
<table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
       style="background-color:rgb(249,250,251);padding:16px;border-radius:8px;margin-bottom:24px;border-width:1px;border-color:rgb(229,231,235)">
    <tbody>
    <tr>
        <td>
            <?php
            $isReverseCharge = $order && $order->isReverseChargeTaxOrder();
            if ($order && ($order->tax_total > 0 || $isReverseCharge)):
            ?>
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tbody style="width:100%">
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;color:rgb(55,65,81);margin:0px;line-height:24px;">
                            <?php esc_html_e('Subtotal', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;color:rgb(55,65,81);margin:0px;line-height:24px;">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->subtotal)); ?>
                        </p>
                    </td>
                </tr>
                <?php if ($isReverseCharge): ?>
                <?php
                    $rcReversedTotal = $order ? $order->getReversedTaxTotal() : 0;
                    if ($rcReversedTotal > 0) {
                        $rcTaxLabel = sprintf(
                            /* translators: %1$s: formatted reversed tax amount */
                            __('Tax reversed: %1$s', 'fluent-cart'),
                            \FluentCart\App\Helpers\Helper::toDecimal($rcReversedTotal)
                        );
                    } else {
                        $rcTaxLabel = __('Charge reversed', 'fluent-cart');
                    }
                ?>
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;color:rgb(55,65,81);margin:0px;line-height:24px;">
                            <?php esc_html_e('Tax', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;color:rgb(55,65,81);margin:0px;line-height:24px;">
                            <?php echo esc_html($rcTaxLabel); ?>
                        </p>
                    </td>
                </tr>
                <?php else: ?>
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;color:rgb(55,65,81);margin:0px;line-height:24px;">
                            <?php esc_html_e('Tax', 'fluent-cart');
                            echo esc_html(\FluentCart\App\Helpers\Helper::getOrderTaxLabel($order));
                            ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;color:rgb(55,65,81);margin:0px;line-height:24px;">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->tax_total)); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ($isReverseCharge): ?>
                <div style="font-size:12px;color:rgb(55,65,81);margin-top:4px;margin-bottom:4px;">
                    <?php echo '* ' . esc_html__('Tax to be paid on reverse charge basis', 'fluent-cart'); ?>
                </div>
            <?php endif; ?>
            <hr style="border-color:rgb(209,213,219);margin-top:8px;margin-bottom:8px;width:100%;border:none;border-top:1px solid #eaeaea">
            <?php endif; ?>
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tbody style="width:100%">
                <tr style="width:100%">
                    <td style="width:70%"><p
                                style="font-size:16px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px">
                            <?php esc_html_e('Total', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:16px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($transaction->total)); ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>
            <hr style="border-color:rgb(209,213,219);margin-top:8px;margin-bottom:8px;width:100%;border:none;border-top:1px solid #eaeaea">
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tbody style="width:100%">
                <tr style="width:100%">
                    <td style="width:70%">
                        <p>
                            <?php esc_html_e('Payment Method', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p>
                            <?php echo esc_html($transaction->getPaymentMethodText()); ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>
        </td>
    </tr>
    </tbody>
</table>
