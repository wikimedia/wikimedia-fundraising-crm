<?php

require_once 'CRM/Export/Form/Map.php';

/**
 * Override the contact export pages to use our Contacts and
 * Contributions format.  The mapping has been created by
 * our drupal update hook.  The initial mapping selection page
 * is bypassed to avoid an accidental "all" choice, which sadly
 * forces us to do a little dance to set up the form variables.
 */
class CRM_Contact_Form_Task_ContactsAndContributionsExport extends CRM_Export_Form_Map
{
    function preProcess()
    {
        require_once 'CRM/Contribute/Task.php';
        require_once 'CRM/Contribute/Form/Task.php';
        CRM_Contact_Form_Task::preProcessCommon( $this, true );
        CRM_Contribute_Form_Task::preProcessCommon( $this, true );

        $params = array('name' => 'Contacts and Contributions');
        $defaults = array();
        require_once 'CRM/Core/BAO/Mapping.php';
        $mapping = CRM_Core_BAO_Mapping::retrieve($params, $defaults);
        if ($mapping) {
            $this->set('mappingId', $mapping->id);
        }

        require_once 'CRM/Export/Form/Select.php';
        $this->set( 'exportMode' , CRM_Export_Form_Select::CONTRIBUTE_EXPORT );
        $this->assign( 'matchingContacts', TRUE );
        $this->set( 'componentIds', $this->_componentIds );
        $this->set( 'selectAll' , FALSE  );

        parent::preProcess();

        $this->set( 'componentClause', $this->_componentClause );
        $this->set( 'componentTable', $this->_componentTable );
        #$this->_exportParams = $this->controller->exportValues( $this->_name );
    }

    function postProcess()
    {
        //TODO fix civi
        variable_set('wmf_reports_export_inprogress', 'Contacts and Contributions');
        parent::postProcess();
    }
}
