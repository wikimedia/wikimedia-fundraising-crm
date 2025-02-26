<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * This class is used to retrieve and display activities for a contact.
 */
class CRM_Activity_Selector_Activity extends CRM_Core_Selector_Base implements CRM_Core_Selector_API {

  /**
   * We use desc to remind us what that column is, name is used in the tpl
   *
   * @var array
   */
  public static $_columnHeaders;

  /**
   * ContactId - contact id of contact whose activies are displayed
   *
   * @var int
   */
  protected $_contactId;

  protected $_admin;

  protected $_context;

  protected $_activityTypeIDs;

  protected $_viewOptions;

  /**
   * Class constructor.
   *
   * @param int $contactId
   *   Contact whose activities we want to display.
   * @param int $permission
   *   The permission we have for this contact.
   *
   * @param bool $admin
   * @param string $context
   * @param null $activityTypeIDs
   *
   * @return \CRM_Activity_Selector_Activity
   */
  public function __construct(
    $contactId,
    $permission,
    $admin = FALSE,
    $context = 'activity',
    $activityTypeIDs = NULL) {
    $this->_contactId = $contactId;
    $this->_permission = $permission;
    $this->_admin = $admin;
    $this->_context = $context;
    $this->_activityTypeIDs = $activityTypeIDs;

    // get all enabled view componentc (check if case is enabled)
    $this->_viewOptions = CRM_Core_BAO_Setting::valueOptions(CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
      'contact_view_options', TRUE, NULL, TRUE
    );
  }

  /**
   * This method returns the action links that are given for each search row.
   * currently the action links added for each row are
   *
   * - View
   *
   * @param int $activityTypeId
   * @param int $sourceRecordId
   * @param bool $accessMailingReport
   * @param int $activityId
   * @param null $key
   * @param null $compContext
   *
   * @return array
   */
  public static function actionLinks(
    $activityTypeId,
    $sourceRecordId = NULL,
    $accessMailingReport = FALSE,
    $activityId = NULL,
    $key = NULL,
    $compContext = NULL) {

    //CRM-14277 added additional param to handle activity search
    $extraParams = "&searchContext=activity";

    $extraParams .= ($key) ? "&key={$key}" : NULL;
    if ($compContext) {
      $extraParams .= "&compContext={$compContext}";
    }

    $showView = TRUE;
    $showUpdate = $showDelete = FALSE;
    $qsUpdate = NULL;
    $url = NULL;
    $qsView = NULL;

    $activityTypeName = CRM_Core_PseudoConstant::getName('CRM_Activity_BAO_Activity', 'activity_type_id', $activityTypeId);

    // CRM-7607
    // Lets allow to have normal operation for only activity types.
    // When activity type is disabled or no more exists give only delete.
    switch ($activityTypeName) {
      case 'Event Registration':
      case 'Change Registration':
        $url = 'civicrm/contact/view/participant';
        $qsView = "action=view&reset=1&id={$sourceRecordId}&cid=%%cid%%&context=%%cxt%%{$extraParams}";
        break;

      case 'Contribution':
        $url = 'civicrm/contact/view/contribution';
        $qsView = "action=view&reset=1&id={$sourceRecordId}&cid=%%cid%%&context=%%cxt%%{$extraParams}";
        break;

      case 'Payment':
      case 'Refund':
        if ($sourceRecordId) {
          $participantId = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_ParticipantPayment', $sourceRecordId, 'participant_id', 'contribution_id');
        }
        if (!empty($participantId)) {
          $url = 'civicrm/contact/view/participant';
          $qsView = "action=view&reset=1&id={$participantId}&cid=%%cid%%&context=%%cxt%%{$extraParams}";
        }
        else {
          // Allow deletion of orphan activities if the contribution/participant was probably deleted
          $url = 'civicrm/activity';
          $showView = $showDelete = TRUE;
          $qsView = "atype={$activityTypeId}&action=view&reset=1&id=%%id%%&cid=%%cid%%&context=%%cxt%%{$extraParams}";
        }
        break;

      case 'Membership Signup':
      case 'Membership Renewal':
      case 'Change Membership Status':
      case 'Change Membership Type':
        $url = 'civicrm/contact/view/membership';
        $qsView = "action=view&reset=1&id={$sourceRecordId}&cid=%%cid%%&context=%%cxt%%{$extraParams}";
        break;

      case 'Pledge Reminder':
      case 'Pledge Acknowledgment':
        $url = 'civicrm/contact/view/activity';
        $qsView = "atype={$activityTypeId}&action=view&reset=1&id=%%id%%&cid=%%cid%%&context=%%cxt%%{$extraParams}";
        break;

      case 'Email':
      case 'Bulk Email':
        $url = 'civicrm/activity/view';
        $delUrl = 'civicrm/activity';
        $qsView = "atype={$activityTypeId}&action=view&reset=1&id=%%id%%&cid=%%cid%%&context=%%cxt%%{$extraParams}";
        if ($activityTypeName == 'Email') {
          $showDelete = TRUE;
        }
        break;

      case 'Inbound Email':
        $url = 'civicrm/contact/view/activity';
        $qsView = "atype={$activityTypeId}&action=view&reset=1&id=%%id%%&cid=%%cid%%&context=%%cxt%%{$extraParams}";

        if (CRM_Activity_BAO_Activity::checkEditInboundEmailsPermissions()) {
          $showDelete = $showUpdate = TRUE;
          $qsUpdate = "atype={$activityTypeId}&action=update&reset=1&id=%%id%%&cid=%%cid%%&context=%%cxt%%{$extraParams}";
        }
        break;

      case 'Open Case':
      case 'Change Case Type':
      case 'Change Case Status':
      case 'Change Case Start Date':
        $showUpdate = $showDelete = FALSE;
        $url = 'civicrm/activity';
        $qsView = "atype={$activityTypeId}&action=view&reset=1&id=%%id%%&cid=%%cid%%&context=%%cxt%%{$extraParams}";
        $qsUpdate = "atype={$activityTypeId}&action=update&reset=1&id=%%id%%&cid=%%cid%%&context=%%cxt%%{$extraParams}";
        break;

      default:
        $url = 'civicrm/activity';
        $showView = $showDelete = $showUpdate = TRUE;
        $qsView = "atype={$activityTypeId}&action=view&reset=1&id=%%id%%&cid=%%cid%%&context=%%cxt%%{$extraParams}";
        $qsUpdate = "atype={$activityTypeId}&action=update&reset=1&id=%%id%%&cid=%%cid%%&context=%%cxt%%{$extraParams}";

        // When type is not available lets hide view and update.
        if (empty($activityTypeName)) {
          $showView = $showUpdate = FALSE;
        }
        break;
    }

    $qsDelete = "atype={$activityTypeId}&action=delete&reset=1&id=%%id%%&cid=%%cid%%&context=%%cxt%%{$extraParams}";

    $actionLinks = [];

    if ($showView) {
      $actionLinks += [
        CRM_Core_Action::
        VIEW => [
          'name' => ts('View'),
          'url' => $url,
          'qs' => $qsView,
          'title' => ts('View Activity'),
          'weight' => -20,
        ],
      ];
    }

    if ($showUpdate) {
      $updateUrl = 'civicrm/activity/add';
      if ($activityTypeName == 'Email') {
        $updateUrl = 'civicrm/activity/email/add';
      }
      elseif ($activityTypeName == 'Print PDF Letter') {
        $updateUrl = 'civicrm/activity/pdf/add';
      }
      if (CRM_Activity_BAO_Activity::checkPermission($activityId, CRM_Core_Action::UPDATE)) {
        $actionLinks += [
          CRM_Core_Action::
          UPDATE => [
            'name' => ts('Edit'),
            'url' => $updateUrl,
            'qs' => $qsUpdate,
            'title' => ts('Update Activity'),
            'weight' => -10,
          ],
        ];
      }
    }

    if (
      $activityTypeName &&
      CRM_Case_BAO_Case::checkPermission($activityId, 'File On Case', $activityTypeId)
    ) {
      $actionLinks += [
        CRM_Core_Action::
        ADD => [
          'name' => ts('File on Case'),
          'url' => '#',
          'extra' => 'onclick="javascript:fileOnCase( \'file\', \'%%id%%\', null, this ); return false;"',
          'title' => ts('File on Case'),
          'weight' => 50,
        ],
      ];
    }

    if ($showDelete) {
      if (!isset($delUrl) || !$delUrl) {
        $delUrl = $url;
      }
      $actionLinks += [
        CRM_Core_Action::
        DELETE => [
          'name' => ts('Delete'),
          'url' => $delUrl,
          'qs' => $qsDelete,
          'title' => ts('Delete Activity'),
          'weight' => 100,
        ],
      ];
    }

    if ($accessMailingReport) {
      $actionLinks += [
        CRM_Core_Action::
        BROWSE => [
          'name' => ts('Mailing Report'),
          'url' => 'civicrm/mailing/report',
          'qs' => "mid={$sourceRecordId}&reset=1&cid=%%cid%%&context=activitySelector",
          'title' => ts('View Mailing Report'),
        ],
      ];
    }

    return $actionLinks;
  }

  /**
   * Getter for array of the parameters required for creating pager.
   *
   * @param $action
   * @param array $params
   */
  public function getPagerParams($action, &$params) {
    $params['status'] = ts('Activities %%StatusMessage%%');
    $params['csvString'] = NULL;
    $params['rowCount'] = Civi::settings()->get('default_pager_size');

    $params['buttonTop'] = 'PagerTopButton';
    $params['buttonBottom'] = 'PagerBottomButton';
  }

  /**
   * Returns the column headers as an array of tuples:
   * (name, sortName (key to the sort array))
   *
   * @param string $action
   *   The action being performed.
   * @param string $output
   *   What should the result set include (web/email/csv).
   *
   * @return array
   *   the column headers that need to be displayed
   */
  public function &getColumnHeaders($action = NULL, $output = NULL) {
    if ($output == CRM_Core_Selector_Controller::EXPORT || $output == CRM_Core_Selector_Controller::SCREEN) {
      $csvHeaders = [ts('Activity Type'), ts('Description'), ts('Activity Date')];
      foreach (self::_getColumnHeaders() as $column) {
        if (array_key_exists('name', $column)) {
          $csvHeaders[] = $column['name'];
        }
      }
      return $csvHeaders;
    }
    else {
      return self::_getColumnHeaders();
    }
  }

  /**
   * Returns total number of rows for the query.
   *
   * @param string $action
   *   Action being performed.
   *
   * @param null $case
   *
   * @return int
   *   Total number of rows
   */
  public function getTotalCount($action, $case = NULL) {
    $params = [
      'contact_id' => $this->_contactId,
      'admin' => $this->_admin,
      'caseId' => $case,
      'context' => $this->_context,
      'activity_type_id' => $this->_activityTypeIDs,
      'offset' => 0,
      'rowCount' => 0,
      'sort' => NULL,
    ];
    return CRM_Activity_BAO_Activity::getActivitiesCount($params);
  }

  /**
   * Returns all the rows in the given offset and rowCount.
   *
   * @param string $action
   *   The action being performed.
   * @param int $offset
   *   The row number to start from.
   * @param int $rowCount
   *   The number of rows to return.
   * @param string $sort
   *   The sql string that describes the sort order.
   * @param string $output
   *   What should the result set include (web/email/csv).
   *
   * @param null $case
   *
   * @return int
   *   the total number of rows for this action
   */
  public function &getRows($action, $offset, $rowCount, $sort, $output = NULL, $case = NULL) {
    $params = [
      'contact_id' => $this->_contactId,
      'admin' => $this->_admin,
      'caseId' => $case,
      'context' => $this->_context,
      'activity_type_id' => $this->_activityTypeIDs,
      'offset' => $offset,
      'rowCount' => $rowCount,
      'sort' => $sort,
    ];
    $config = CRM_Core_Config::singleton();
    $rows = CRM_Activity_BAO_Activity::getActivities($params);

    if (empty($rows)) {
      return $rows;
    }

    $activityStatus = CRM_Core_PseudoConstant::activityStatus();

    $engagementLevels = CRM_Campaign_PseudoConstant::engagementLevel();

    // CRM-4418
    $permissions = [$this->_permission];
    if (CRM_Core_Permission::check('delete activities')) {
      $permissions[] = CRM_Core_Permission::DELETE;
    }
    $mask = CRM_Core_Action::mask($permissions);

    foreach ($rows as $k => $row) {
      $row = &$rows[$k];

      // add class to this row if overdue
      if (CRM_Utils_Date::overdue($row['activity_date_time'] ?? '')
        && ($row['status_id'] ?? NULL) == 1
      ) {
        $row['overdue'] = 1;
        $row['class'] = 'status-overdue';
      }
      else {
        $row['overdue'] = 0;
        $row['class'] = 'status-ontime';
      }

      $row['status'] = $row['status_id'] ? $activityStatus[$row['status_id']] : NULL;

      $engagementLevel = $row['engagement_level'] ?? NULL;
      if ($engagementLevel) {
        $row['engagement_level'] = CRM_Utils_Array::value($engagementLevel, $engagementLevels, $engagementLevel);
      }

      $actionLinks = $this->actionLinks($row['activity_type_id'],
        $row['source_record_id'] ?? NULL,
        // CRM-3553
        !empty($row['mailingId']),
        $row['activity_id'] ?? NULL,
        $this->_key
      );

      $actionMask = array_sum(array_keys($actionLinks)) & $mask;

      if ($output != CRM_Core_Selector_Controller::EXPORT && $output != CRM_Core_Selector_Controller::SCREEN) {
        $row['action'] = CRM_Core_Action::formLink($actionLinks,
          $actionMask,
          [
            'id' => $row['activity_id'],
            'cid' => $this->_contactId,
            'cxt' => $this->_context,
            'caseid' => isset($row['case_id']) ? current($row['case_id']) : NULL,
          ],
          ts('more'),
          FALSE,
          'activity.selector.action',
          'Activity',
          $row['activity_id']
        );
      }

      unset($row);
    }

    return $rows;
  }

  /**
   * Name of export file.
   *
   * @param string $output
   *   Type of output.
   *
   * @return string
   *   name of the file
   */
  public function getExportFileName($output = 'csv') {
    return ts('CiviCRM Activity');
  }

  /**
   * Get colunmn headers for search selector.
   *
   *
   * @return array
   */
  private static function &_getColumnHeaders() {
    if (!isset(self::$_columnHeaders)) {
      self::$_columnHeaders = [
        [
          'name' => ts('Type'),
          'sort' => 'activity_type',
          'direction' => CRM_Utils_Sort::DONTCARE,
        ],
        [
          'name' => ts('Subject'),
          'sort' => 'subject',
          'direction' => CRM_Utils_Sort::DONTCARE,
        ],
        [
          'name' => ts('Added by'),
          'sort' => 'source_contact_name',
          'direction' => CRM_Utils_Sort::DONTCARE,
        ],
        ['name' => ts('With')],
        ['name' => ts('Assigned')],
        [
          'name' => ts('Date'),
          'sort' => 'activity_date_time',
          'direction' => CRM_Utils_Sort::DONTCARE,
        ],
        [
          'name' => ts('Status'),
          'sort' => 'status_id',
          'direction' => CRM_Utils_Sort::DONTCARE,
        ],
        ['desc' => ts('Actions')],
      ];
    }

    return self::$_columnHeaders;
  }

}
