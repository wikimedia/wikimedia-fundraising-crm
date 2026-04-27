<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Contacts_by_email_disambiguation',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Contacts_by_email_disambiguation',
        'label' => E::ts('Contacts by email disambiguation'),
        'api_entity' => 'Contact',
        'api_params' => [
          'version' => 4,
          'select' => [
            'display_name',
            'email_primary.email',
            'GROUP_CONCAT(DISTINCT Contact_Email_contact_id_01.email) AS GROUP_CONCAT_Contact_Email_contact_id_01_email',
            'address_primary.street_address',
            'address_primary.city',
            'address_primary.state_province_id:abbr',
            'address_primary.postal_code',
            'address_primary.country_id:abbr',
            'wmf_donor.all_funds_last_donation_date',
            'wmf_donor.last_donation_amount',
            'wmf_donor.last_donation_currency',
            'GROUP_FIRST(Contact_ContributionRecur_contact_id_01.contribution_status_id:label ORDER BY Contact_ContributionRecur_contact_id_01.next_sched_contribution_date DESC) AS GROUP_FIRST_Contact_ContributionRecur_contact_id_01_contribution_status_id_label_Contact_ContributionRecur_contact_id_01_next_sched_contribution_date',
            'GROUP_FIRST(Contact_ContributionRecur_contact_id_01.cancel_date ORDER BY Contact_ContributionRecur_contact_id_01.next_sched_contribution_date DESC) AS GROUP_FIRST_Contact_ContributionRecur_contact_id_01_cancel_date_Contact_ContributionRecur_contact_id_01_next_sched_contribution_date',
            'GROUP_FIRST(Contact_ContributionRecur_contact_id_01.end_date ORDER BY Contact_ContributionRecur_contact_id_01.next_sched_contribution_date DESC) AS GROUP_FIRST_Contact_ContributionRecur_contact_id_01_end_date_Contact_ContributionRecur_contact_id_01_next_sched_contribution_date',
          ],
          'orderBy' => [],
          'where' => [],
          'groupBy' => ['id'],
          'join' => [
            [
              'Email AS Contact_Email_contact_id_01',
              'LEFT',
              [
                'id',
                '=',
                'Contact_Email_contact_id_01.contact_id',
              ],
              [
                'Contact_Email_contact_id_01.is_primary',
                '=',
                FALSE,
              ],
            ],
            [
              'Email AS Contact_Email_contact_id_02',
              'LEFT',
              [
                'id',
                '=',
                'Contact_Email_contact_id_02.contact_id',
              ],
            ],
            [
              'ContributionRecur AS Contact_ContributionRecur_contact_id_01',
              'LEFT',
              [
                'id',
                '=',
                'Contact_ContributionRecur_contact_id_01.contact_id',
              ],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Contacts_by_email_disambiguation_SearchDisplay_Contacts_by_email_disambiguation',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Contacts_by_email_disambiguation',
        'label' => E::ts('Contacts by email disambiguation'),
        'saved_search_id.name' => 'Contacts_by_email_disambiguation',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [
            [
              'wmf_donor.all_funds_last_donation_date',
              'DESC',
            ],
          ],
          'limit' => 0,
          'pager' => FALSE,
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'display_name',
              'label' => E::ts('Name'),
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'Contact',
                'action' => 'view',
                'join' => '',
                'target' => '',
                'task' => '',
              ],
              'title' => E::ts('View Contact'),
            ],
            [
              'type' => 'field',
              'key' => 'email_primary.email',
              'label' => E::ts('Primary Email'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'GROUP_CONCAT_Contact_Email_contact_id_01_email',
              'label' => E::ts('Other Emails'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'address_primary.city',
              'label' => E::ts('City'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'address_primary.state_province_id:abbr',
              'label' => E::ts('State'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'address_primary.country_id:abbr',
              'label' => E::ts('Country'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'wmf_donor.last_donation_amount',
              'label' => E::ts('Last Donation'),
              'sortable' => TRUE,
              'rewrite' => '[wmf_donor.last_donation_currency] [wmf_donor.last_donation_amount]',
            ],
            [
              'type' => 'field',
              'key' => 'wmf_donor.all_funds_last_donation_date',
              'label' => E::ts('Date'),
              'sortable' => TRUE,
              'rewrite' => '{"[wmf_donor.all_funds_last_donation_date]"|date_format:"%b %-e %Y"}',
            ],
            [
              'type' => 'field',
              'key' => 'GROUP_FIRST_Contact_ContributionRecur_contact_id_01_contribution_status_id_label_Contact_ContributionRecur_contact_id_01_next_sched_contribution_date',
              'label' => E::ts('Recurring'),
              'sortable' => TRUE,
              'rewrite' => '{assign var="date" value="[GROUP_FIRST_Contact_ContributionRecur_contact_id_01_end_date_Contact_ContributionRecur_contact_id_01_next_sched_contribution_date]"}
{assign var="date" value="[GROUP_FIRST_Contact_ContributionRecur_contact_id_01_cancel_date_Contact_ContributionRecur_contact_id_01_next_sched_contribution_date]"}

[GROUP_FIRST_Contact_ContributionRecur_contact_id_01_contribution_status_id_label_Contact_ContributionRecur_contact_id_01_next_sched_contribution_date]
{if $date} {$date|date_format:"%b %-e %Y"}{/if}',
            ],
          ],
          'actions' => ['contact.merge'],
          'classes' => ['table', 'table-striped'],
          'columnMode' => 'custom',
          'actions_display_mode' => 'buttons',
          'noResultsText' => 'No contacts have this email.',
        ],
      ],
      'match' => [
        'saved_search_id',
        'name',
      ],
    ],
  ],
];
