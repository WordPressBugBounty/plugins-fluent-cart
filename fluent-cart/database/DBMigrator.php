<?php

namespace FluentCart\Database;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\Product;

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

use FluentCart\Database\Migrations\AttributeGroupsMigrator;
use FluentCart\Database\Migrations\AttributeObjectRelationsMigrator;
use FluentCart\Database\Migrations\AttributeTermsMigrator;
use FluentCart\Database\Migrations\CartMigrator;
use FluentCart\Database\Migrations\CustomersMigrator;
use FluentCart\Database\Migrations\MetaMigrator;
use FluentCart\Database\Migrations\Migrator;
use FluentCart\Database\Migrations\OrderMetaMigrator;
use FluentCart\Database\Migrations\OrdersMigrator;
use FluentCart\Database\Migrations\OrdersItemsMigrator;
use FluentCart\Database\Migrations\OrderTransactionsMigrator;
use FluentCart\Database\Migrations\ProductDetailsMigrator;
use FluentCart\Database\Migrations\ProductDownloadsMigrator;
use FluentCart\Database\Migrations\ProductMetaMigrator;
use FluentCart\Database\Migrations\ProductVariationMigrator;
use FluentCart\Database\Migrations\ScheduledActionsMigrator;
use FluentCart\Database\Migrations\ShippingClassesMigrator;
use FluentCart\Database\Migrations\SubscriptionMetaMigrator;
use FluentCart\Database\Migrations\SubscriptionsMigrator;
use FluentCart\Database\Migrations\TaxClassesMigrator;
use FluentCart\Database\Migrations\TaxRatesMigrator;
use FluentCart\Database\Migrations\OrderTaxRateMigrator;
use FluentCart\Database\Migrations\CouponsMigrator;
use FluentCart\Database\Migrations\CustomerAddressesMigrator;
use FluentCart\Database\Migrations\CustomerMetaMigrator;
use FluentCart\Database\Migrations\OrderAddressesMigrator;
use FluentCart\Database\Migrations\OrderDownloadPermissionsMigrator;
use FluentCart\Database\Migrations\OrderOperationsMigrator;
use FluentCart\Database\Migrations\AppliedCouponsMigrator;
use FluentCart\Database\Migrations\LabelMigrator;
use FluentCart\Database\Migrations\LabelRelationshipsMigrator;
use FluentCart\Database\Migrations\ActivityMigrator;
use FluentCart\Database\Migrations\WebhookLogger;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Models\LicenseActivation;
use FluentCartPro\App\Modules\Licensing\Models\LicenseMeta;
use FluentCartPro\App\Modules\Licensing\Models\LicenseSite;
use FluentCart\Database\Migrations\ShippingZonesMigrator;
use FluentCart\Database\Migrations\ShippingMethodsMigrator;
use FluentCart\Database\Migrations\RetentionSnapshotsMigrator;

class DBMigrator
{
    private static array $migrators = [
        MetaMigrator::class,
        AttributeGroupsMigrator::class,
        AttributeObjectRelationsMigrator::class,
        AttributeTermsMigrator::class,
        CartMigrator::class,
        CouponsMigrator::class,
        CustomerAddressesMigrator::class,
        CustomerMetaMigrator::class,
        CustomersMigrator::class,
        OrderAddressesMigrator::class,
        OrderDownloadPermissionsMigrator::class,
        OrderMetaMigrator::class,
        OrderOperationsMigrator::class,
        OrdersItemsMigrator::class,
        OrdersMigrator::class,
        OrderTaxRateMigrator::class,
        OrderTransactionsMigrator::class,
        ProductDetailsMigrator::class,
        ProductDownloadsMigrator::class,
        ProductMetaMigrator::class,
        SubscriptionMetaMigrator::class,
        SubscriptionsMigrator::class,
        TaxClassesMigrator::class,
        TaxRatesMigrator::class,
        ProductVariationMigrator::class,
        AppliedCouponsMigrator::class,
        LabelMigrator::class,
        LabelRelationshipsMigrator::class,
        ActivityMigrator::class,
        WebhookLogger::class,
        ShippingZonesMigrator::class,
        ShippingMethodsMigrator::class,
        ShippingClassesMigrator::class,
        ScheduledActionsMigrator::class,
        RetentionSnapshotsMigrator::class
    ];

    public static function migrateUp($network_wide = false)
    {
        global $wpdb;
        if ($network_wide) {
            // Retrieve all site IDs from this network (WordPress >= 4.6 provides easy to use functions for that).
            if (function_exists('get_sites') && function_exists('get_current_network_id')) {
                $site_ids = get_sites(array('fields' => 'ids', 'network_id' => get_current_network_id()));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $site_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs WHERE site_id = $wpdb->siteid;");
            }
            // Install the plugin for all these sites.
            foreach ($site_ids as $site_id) {
                switch_to_blog($site_id);
                self::run_migrate();
                restore_current_blog();
            }
        } else {
            self::run_migrate();
        }
    }

    public static function run_migrate()
    {
        self::migrate();
        self::maybeMigrateDBChanges();
        update_option('_fluent_cart_db_version', FLUENTCART_DB_VERSION, 'yes');
    }

    public static function migrate()
    {
        /**
         * @var $migrator Migrator
         */
        foreach (self::$migrators as $migrator) {
            $migrator::migrate();
        }
    }

    public static function maybeMigrateDBChanges()
    {
        $currentDBVersion = get_option('_fluent_cart_db_version');

        // Always add recent changes at the top
        if (!$currentDBVersion || version_compare($currentDBVersion, FLUENTCART_DB_VERSION, '<')) {

            update_option('_fluent_cart_db_version', FLUENTCART_DB_VERSION, 'yes');

            // 2026-05-27
            OrdersMigrator::addFeeTotalColumn();

            // 2026-03-29
            ShippingZonesMigrator::addMetaColumn();
            ShippingMethodsMigrator::changeAmountToDecimal();

            // 2026-02-28
            OrdersMigrator::addPaymentStatusIndex();

            // 2026-02-06
            ProductVariationMigrator::addSkuColumn();

            // 2025-10-29
            ProductMetaMigrator::dropCompositeUniqueIndex();

            // 2025-10-12
            OrderAddressesMigrator::addMetaColumn();
            CustomerAddressesMigrator::addMetaColumn();

            // 2025-10-02
            ShippingMethodsMigrator::modifyStatesToJson();

            // 2025-09-30
            TaxClassesMigrator::addSlugColumn();
            ShippingMethodsMigrator::addMetaColumn();

            // 2025-09-29
            TaxClassesMigrator::addMetaColumn();

            // Old fallbacks — handled by migrated() on reactivation.
            // Safe to remove after next major release.

            // // 2025-09-26
            // TaxClassesMigrator::renameCategoriesToMeta();
            // TaxClassesMigrator::addDescriptionColumn();
            // OrderTaxRateMigrator::addFiledAtColumn();

            // // 2025-09-23
            // OrderTaxRateMigrator::addMetaColumn();

            // // 2025-09-22
            // OrdersMigrator::addTaxBehaviorColumn();

            // // 2025-09-10
            // TaxRatesMigrator::addGroupColumn();

            // // 2025-09-02
            // ShippingMethodsMigrator::addStatesColumn();
            // ShippingZonesMigrator::renameRegionsToRegion();

            // // 2025-07-23
            // SubscriptionsMigrator::addUuidColumn();
            // SubscriptionsMigrator::renameInitialAmountToSignupFee();
            // SubscriptionsMigrator::backfillEmptyUuids();

            // // 2025-07-18
            // CustomersMigrator::addLtvColumn();

            // // 2025-07-16
            // OrdersMigrator::renameDiscountTotalColumn();
            // OrderMetaMigrator::renameKeyToMetaKey();
            // OrderMetaMigrator::renameValueToMetaValue();

            // // 2025-07-11
            // MetaMigrator::renameKeyToMetaKey();
            // MetaMigrator::renameValueToMetaValue();

            // // 2025-06-06
            // OrdersMigrator::addReceiptNumberColumn();
        }
    }

    public static function migrateDown($network_wide = false)
    {
        /**
         * @var $migrator Migrator
         */
        foreach (self::$migrators as $migrator) {
            $migrator::dropTable();
        }

        Product::query()->where('post_type', '=', FluentProducts::CPT_NAME)->delete();

        //Migrate Down The Licenses
        if (class_exists(License::class)) {
            License::query()->truncate();
            LicenseActivation::query()->truncate();
            LicenseSite::query()->truncate();
            LicenseMeta::query()->truncate();
        }
    }

    public static function refresh($network_wide = false)
    {
        static::migrateDown($network_wide);
        static::migrateUp($network_wide);
    }
}
