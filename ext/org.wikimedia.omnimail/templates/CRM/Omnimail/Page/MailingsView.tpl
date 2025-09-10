{*
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
*}
{* relationship selector *}
<h3>Mailing events (up to 500 most recent)</h3>

  <a href="{$remoteDataURL|smarty:nodefaults}">Acoustic data</a>
  <table
    class="crm-contact-mailings">
    <thead>
    <tr>
      <th class='crm-contact-recipient_action_datetime'>{ts}When{/ts}</th>
      <th class='crm-contact-event_type'>{ts}Action{/ts}</th>
      <th class='crm-contact-mailing_identifier'>{ts}Mailing{/ts}</th>
      <th class='crm-contact-email'>{ts}Email{/ts}</th>
      <th class='crm-contact-contact-reference'>{ts}Acoustic ID{/ts}</th>
    </tr>
    </thead>

  </table>

{literal}
<script type="text/javascript">
  {/literal}var tableData = {$mailings}{literal}
  cj('table.crm-contact-mailings').DataTable({
      data : tableData,
      columns: [
        { data: 'recipient_action_datetime' },
        { data: 'event_type' },
        { data: {
           _:   "mailing_identifier.display",
          sort: "mailing_identifier.name"
        } },
        { data: 'email' },
        { data: {
           _:   "contact_identifier.display",
          sort: "contact_identifier.name"
        } },
      ],
      order: [[0, "desc"]]
    });

</script>
{/literal}
