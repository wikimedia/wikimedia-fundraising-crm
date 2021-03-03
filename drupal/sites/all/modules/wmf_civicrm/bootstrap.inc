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
    civicrm_initialize();
    $optionGroup = civicrm_api3('OptionGroup', 'getsingle', array(
      'name' => $group_name,
    ));

    foreach ($values as $key => $value)
    {
        $params = array_merge(array(
          'option_group_id' => $optionGroup['id'],
          'name' => $key,
          'label' => $key,
          'is_active' => 1,
        ), $value);

        $result = civicrm_api3('OptionValue', 'get', array(
          'option_group_id' => $optionGroup['id'],
          'name' => $params['name'],
        ));
        if ($result['count'] === 0) {
          civicrm_api3('OptionValue', 'create', $params);
          wmf_civicrm_flush_cached_options();
        }
    }

}
