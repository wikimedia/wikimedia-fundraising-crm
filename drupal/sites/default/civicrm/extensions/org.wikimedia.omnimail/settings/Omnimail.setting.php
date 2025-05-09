<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2017                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */

/**
 * Settings metadata file
 */
return [
  'omnimail_omnirecipient_load' => [
    'group_name' => 'Omnimail Preferences',
    'group' => 'omnimail',
    'name' => 'omnimail_omnirecipient_load',
    'type' => 'Array',
    'default' => [],
    'title' => 'Omnimail Recipient Load settings',
    'is_domain' => '1',
    'is_contact' => 0,
    'description' => 'settings to inform the Omnimail job',
    'help_text' => 'this will be managed programmatically',
  ],
  'omnimail_omnigroupmembers_load' => [
    'group_name' => 'Omnimail Preferences',
    'group' => 'omnimail',
    'name' => 'omnimail_omnigroupmembers_load',
    'type' => 'Array',
    'default' => [],
    'title' => 'Omnimail Group Members Load settings',
    'is_domain' => '1',
    'is_contact' => 0,
    'description' => 'settings to inform the Omnimail job',
    'help_text' => 'this will be managed programmatically',
  ],
  'omnimail_job_retry_number' => [
    'group_name' => 'Omnimail Preferences',
    'group' => 'omnimail',
    'name' => 'omnimail_job_retry_number',
    'type' => 'Integer',
    'default' => 15,
    'title' => 'Omnimail Retry attempts',
    'is_domain' => '1',
    'is_contact' => 0,
    'description' => 'default number of retry attempts within a process',
    'help_text' => 'As there may be a delay the job will try a few times to see if the file is available before exiting',
  ],
  'omnimail_job_retry_interval' => [
    'group_name' => 'Omnimail Preferences',
    'group' => 'omnimail',
    'name' => 'omnimail_job_retry_interval',
    'type' => 'Integer',
    'default' => 15,
    'title' => 'Omnimail Retry interval',
    'is_domain' => '1',
    'is_contact' => 0,
    'description' => 'How long to wait between retries',
    'help_text' => 'As there may be a delay the job will try a few times to see if the file is available before exiting',
  ],
  'omnimail_job_default_time_interval' => [
    'group_name' => 'Omnimail Preferences',
    'group' => 'omnimail',
    'name' => 'omnimail_job_default_time_interval',
    'default' => '7 days',
    'type' => 'Integer',
    'title' => 'Omnimail Default Time Interval',
    'is_domain' => '1',
    'is_contact' => 0,
    'description' => 'Length of date range to use, if not passed in',
    'help_text' => 'If start date & end date are not passed in then choose dates this far apart. Format is for strtotime - eg. 1 Day, 2 weeks',
  ],
  'omnimail_credentials' => [
    'group_name' => 'Omnimail Preferences',
    'group' => 'omnimail',
    'name' => 'omnimail_credentials',
    'type' => 'Array',
    'default' => [],
    'title' => 'Omnimail Credentials',
    'is_domain' => '1',
    'is_contact' => 0,
    'description' => 'Credentials for omnimail',
    'help_text' => 'You can set these using the $civicrm_settings global',
  ],
  'omnimail_field_mapping' => [
    'group_name' => 'Omnimail Preferences',
    'group' => 'omnimail',
    'name' => 'omnimail_field_mapping',
    'type' => 'Array',
    'default' => [],
    'title' => 'Omnimail Field Mapping',
    'is_domain' => '1',
    'is_contact' => 0,
    'description' => 'Mapping of fields to sync with Acoustic',
    'help_text' => 'You can set these using the $civicrm_settings global',
  ],
  'omnimail_allowed_upload_folders' => [
    'group_name' => 'Omnimail Preferences',
    'group' => 'omnimail',
    'name' => 'omnimail_allowed_upload_folders',
    'type' => 'Array',
    'default' => [],
    'title' => 'Omnimail Allowed Upload Folders',
    'is_domain' => '1',
    'is_contact' => 0,
    'description' => 'Files uploaded with Omnicontact.Upload must be in one of these folders',
    'help_text' => 'You can set these using the $civicrm_settings global',
  ],
  'omnimail_sms_short_code' => [
    'name' => 'omnimail_sms_short_code',
    'type' => 'Integer',
    'default' => NULL,
    'title' => 'SMS short code',
    'is_domain' => '1',
    'is_contact' => 0,
    'description' => 'SMS number used for texting donors. This is the number they receive texts from.',
    'help_text' => 'We are now using the campaign_code as the qualifier in rest calls and this is currently unused',
  ],
  'omnimail_sms_campaign_id' => [
    'name' => 'omnimail_sms_campaign_id',
    'type' => 'String',
    'default' => 'af111b1f-bc8b-4edc-a743-408594312f99',
    'title' => 'SMS campaign id',
    'is_domain' => '1',
    'is_contact' => 0,
    'description' => 'SMS campaign ID, which donors are consented into.',
  ],
];
