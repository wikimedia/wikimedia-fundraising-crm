<?php
// Functions to help with module schema install and update

/**
 * Load civi api, only for use from within .install code
 */
function wmf_civicrm_bootstrap_civi()
{
    static $api = null;

    if ( $api ) {
        return $api;
    }

    //XXX XXX REALLY?
    $civi_base = implode(DIRECTORY_SEPARATOR, array(drupal_get_path('module', 'civicrm'), '..'));
    set_include_path(
        implode(
            PATH_SEPARATOR,
            array(
                get_include_path(),
                $civi_base,
                $civi_base.DIRECTORY_SEPARATOR.'packages',
            )
        )
    );
    require_once "api/class.api.php";
    $api = new civicrm_api3(array('conf_path' => conf_path()));
    return $api;
}

/**
 * Ensure that a financial type exists for every item in the array.
 *
 * @param array $financial_types
 *
 * @return array
 *   Financial types in th DB.
 *
 * @throws \CiviCRM_API3_Exception
 */
function wmf_civicrm_create_financial_types($financial_types)
{
    $existingFinancialTypes = civicrm_api3('Contribution', 'getoptions', array(
        'field' => 'financial_type_id',
    ));
    $missingTypes = array_diff($financial_types, $existingFinancialTypes['values']);
    foreach ($missingTypes  as $type)
    {
        $result = civicrm_api3('FinancialType', 'create', array(
          'is_active' => 1,
          'is_deductible' => 1,
          'accounting_code' => strtoupper($type),
          'name' => $type,
        ));
        $existingFinancialTypes[$result['id']] = $type;
    }
    return $existingFinancialTypes;
}
