<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\PromoGateways\Pro\AuthorizeNetPromo;
use FluentCart\App\Modules\PaymentMethods\PromoGateways\Pro\MolliePromo;
use FluentCart\App\Modules\PaymentMethods\PromoGateways\Pro\PaddlePromo;

class PromoGatewaysHandler
{
    /**
     * Default promo gateways to register
     * @var array
     */
    protected $defaultGateways = [
        'paddle' => PaddlePromo::class,
        'mollie' => MolliePromo::class,
        'authorize_dot_net' => AuthorizeNetPromo::class,
    ];
    
    public function register()
    {
        add_action('fluent_cart/register_payment_methods', [$this, 'registerPromoGateways'], 20);
    }
    
    /**
     * Register promo gateways
     * Only registers if Fluent Cart Pro is not active
     */
    public function registerPromoGateways()
    {
        $isProActive = defined('FLUENTCART_PRO_PLUGIN_VERSION');   

        // Allow filtering of promo gateways
        $gateways = apply_filters('fluent_cart/promo_gateways', $this->defaultGateways);
        
        foreach ($gateways as $slug => $promoClass) {
            // Skip if class doesn't exist
            if (!class_exists($promoClass)) {
                continue;
            }
            
            $isGatewayRegistered = GatewayManager::has($slug);
            if (!$isGatewayRegistered && !$isProActive) {
                $gateway = GatewayManager::getInstance();
                try {
                    $gateway->register($slug, new $promoClass());
                } catch (\Exception $e) {
                    // Log error but continue with other gateways
                    error_log(sprintf(
                        'Failed to register promo gateway %s: %s',
                        $slug,
                        $e->getMessage()
                    ));
                }
            }
        }
    }
    
    /**
     * Add a new promo gateway to the default list
     * 
     * @param string $slug
     * @param string $className
     */
    public function addGateway($slug, $className)
    {
        $this->defaultGateways[$slug] = $className;
    }
    
    /**
     * Remove a promo gateway from the default list
     * 
     * @param string $slug
     */
    public function removeGateway($slug)
    {
        unset($this->defaultGateways[$slug]);
    }
}
