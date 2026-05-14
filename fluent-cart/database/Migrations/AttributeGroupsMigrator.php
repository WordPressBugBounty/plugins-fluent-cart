<?php

namespace FluentCart\Database\Migrations;

class AttributeGroupsMigrator extends Migrator
{
    public static string $tableName = 'fct_atts_groups';

    public static function getSqlSchema(): string
    {
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `title` VARCHAR(192) NOT NULL,
                `slug` VARCHAR(192) NOT NULL UNIQUE,
                `description` longtext NULL,
                `settings` longtext NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL";
    }

    public static function migrated()
    {
        static::dropLegacyTitleUniqueIndexes();
    }

    public static function dropLegacyTitleUniqueIndexes()
    {
        global $wpdb;

        $tableName = static::getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $indexes = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM %i WHERE Column_name = %s",
            $tableName,
            'title'
        ), ARRAY_A);

        if (empty($indexes)) {
            return;
        }

        foreach ($indexes as $index) {
            if (($index['Non_unique'] ?? '1') !== '0') {
                continue;
            }

            static::dropIndexIfExists($index['Key_name']);
        }
    }
}
