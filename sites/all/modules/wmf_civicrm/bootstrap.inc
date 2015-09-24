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

/**
 * Create option values in CiviCRM.
 *
 * For more control use the option_values_detailed function below.
 *
 * NB - encapsulated the detailed create function has a slight performance
 * cost since group is resolved potentially multiple times - but this
 * function seems like a rarely run function.
 *
 * @param string $group_name
 * @param array $values
 */
function wmf_civicrm_create_option_values( $group_name, $values )
{
    foreach ($values as $value) {
        wmf_civicrm_create_option_values_detailed($group_name, array(
            $value => array(
                'name' => $value,
        )));
    }
}

/**
 * Create option values in CiviCRM with details.
 *
 * The previous other of this only permits a flat array. This allows a more
 * nuanced option value create.
 *
 * This checks for an existing option value first.
 *
 * @param string $group_name
 * @param array $values
 */
function wmf_civicrm_create_option_values_detailed($group_name, $values)
{
    $api = wmf_civicrm_bootstrap_civi();

    $api->OptionGroup->Get(array(
      'name' => $group_name,
    ));
    $result = $api->values();
    $option_group = array_pop($result);
    foreach ($values as $key => $value)
    {
        $params = array_merge(array(
          'option_group_id' => $option_group->id,
          'name' => $key,
          'label' => $key,
          'is_active' => 1,
        ), $value);

        $api->OptionValue->Get(array(
          'option_group_id' => $option_group->id,
          'name' => $params['name'],
        ));

        $result = $api->values();
        if (empty($result))
        {
            $api->OptionValue->Create(array_merge(array(
              'option_group_id' => $option_group->id,
              'name' => $key,
              'label' => $key,
              'is_active' => 1,
            )), $value);
        }
    }
}

/**
 * Ensure that a location type exists for every item in the array.
 *
 * @param array $financial_types
 *
 * @throws \CiviCRM_API3_Exception
 */
function wmf_civicrm_create_location_types($location_types, $update_existing = FALSE)
{
    $existingTypes = civicrm_api3('Address', 'getoptions', array(
      'field' => 'location_type_id',
    ));
    if (!$update_existing) {
        $location_types = array_diff($location_types, $existingTypes['values']);
    }
    foreach ($location_types as $name => $display_name)
    {
        $params = array(
            'name' => $name,
            'display_name' => $display_name,
            'is_active' => 1,
        );
        if ($update_existing) {
            $params['id'] = array_search($display_name,  $existingTypes['values']);
        }
        civicrm_api3('LocationType', 'create', $params);
    }
}

/**
 * @deprecated
 */
function wmf_civicrm_add_location_type($params)
{
    $locationType = new CRM_Core_DAO_LocationType();
    $locationType->copyValues($params);
    $locationType->save();

    return $locationType;
}
