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
namespace Civi\Api4\Action\WMFDonor;

use Civi\Api4\Generic\BasicGetFieldsAction;
use Civi\Api4\Generic\DAOGetAction;
use Civi\Api4\Generic\DAOGetFieldsAction;

/**
 * The WMF Donor class gets calculated WMF Donor fields.
 *
 * This is useful for checking the calculations used and running updates
 * when the calculated data changes.
 *
 * It does not query the actual WMF Donor fields.
 *
 * @inheritDoc
 */
class GetFields extends BasicGetFieldsAction {


}
