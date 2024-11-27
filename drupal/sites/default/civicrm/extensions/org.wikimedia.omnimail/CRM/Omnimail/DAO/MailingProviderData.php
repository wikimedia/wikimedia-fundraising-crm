<?php

/**
 * DAOs provide an OOP-style facade for reading and writing database records.
 *
 * DAOs are a primary source for metadata in older versions of CiviCRM (<5.74)
 * and are required for some subsystems (such as APIv3).
 *
 * This stub provides compatibility. It is not intended to be modified in a
 * substantive way. Property annotations may be added, but are not required.
 * @property string $contact_identifier
 * @property string $mailing_identifier
 * @property string $email
 * @property string $event_type
 * @property string $recipient_action_datetime
 * @property int|string $contact_id
 * @property bool|string $is_civicrm_updated
 * @property int|string $contact_timestamp_type
 */
class CRM_Omnimail_DAO_MailingProviderData extends CRM_Omnimail_DAO_Base {

}
