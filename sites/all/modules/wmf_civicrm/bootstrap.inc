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
        civicrm_api3('FinancialType', 'create', array(
          'is_active' => 1,
          'is_deductible' => 1,
          'accounting_code' => strtoupper($type),
          'name' => $type,
        ));
    }
}

function wmf_civicrm_create_option_values( $group_name, $values )
{
    $api = wmf_civicrm_bootstrap_civi();

    $api->OptionGroup->Get(array(
        'name' => $group_name,
    ));
    $result = $api->values();
    $option_group = array_pop($result);
    foreach ($values as $value)
    {
        $api->OptionValue->Get(array(
            'option_group_id' => $option_group->id,
            'name' => $value,
        ));

        $result = $api->values();
        if (empty($result))
        {
            $api->OptionValue->Create(array(
                'option_group_id' => $option_group->id,
                'name' => $value,
                'label' => $value,
                'is_active' => 1,
            ));
        }
    }
}

function wmf_civicrm_create_location_types($location_types, $update_existing = FALSE)
{
    foreach ($location_types as $name => $display_name)
    {
        $location_type_id = CRM_Utils_Array::key(
            $name,
            CRM_Contribute_PseudoConstant::locationType()
        );

        if (!$location_type_id || $update_existing == TRUE)
        {
            $params = array(
                'name' => $name,
                'display_name' => $display_name,
                'is_active' => 1,
            );
            if ($location_type_id && $update_existing == TRUE)
            {
                $params['id'] = $location_type_id;
            }
            wmf_civicrm_add_location_type($params);
        }
    }
}

function wmf_civicrm_add_location_type($params)
{
    $locationType = new CRM_Core_DAO_LocationType();
    $locationType->copyValues($params);
    $locationType->save();

    return $locationType;
}
