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

function wmf_civicrm_create_contribution_types($contribution_types)
{
    foreach ($contribution_types as $type)
    {
        $contribution_type_id = CRM_Utils_Array::key(
            $type,
            CRM_Contribute_PseudoConstant::contributionType()
        );

        if (!$contribution_type_id)
        {
            $params = array(
                'is_active' => 1,
                'is_deductible' => 1,
                'accounting_code' => strtoupper($type),
                'name' => $type,
            );
            $ids = array();
            CRM_Contribute_BAO_ContributionType::add($params, $ids);
        }
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
