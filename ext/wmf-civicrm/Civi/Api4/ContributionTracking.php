<?php
namespace Civi\Api4;

/**
 * ContributionTracking entity.
 *
 * Provided by the WMF CiviCRM extension.
 * @searchable primary
 * @searchFields id,tracking_date,amount,currency,country,utm_key,utm_medium,referrer
 * @package Civi\Api4
 */
class ContributionTracking extends Generic\DAOEntity {
    /**
     * Get permissions.
     *
     * It may be that we don't need a permission check on this api at all at there is a check on the entity
     * retrieved.
     *
     * @return array
     */
    public static function permissions(): array {
        $permissions = parent::permissions();
        $permissions['get'] = ['access CiviCRM'];
        return $permissions;
    }
}
