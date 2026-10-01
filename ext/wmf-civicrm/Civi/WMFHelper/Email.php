<?php

namespace Civi\WMFHelper;

use Civi\Api4\Email as EmailApi;

class Email {

  /**
   * Get a wikimedia.org email to notify for this contact - for privacy reasons
   * as the email we are sending may contain donor details, this function ensures we
   * are sending to a staff address, never a personal address that might also be on
   * the contact.
   *
   * Checks primary first, then the rest of the contact emails,
   * then finally the configured fallback address.
   */
  public static function getStaffNotificationEmail(int $contactID): string {
    $emails = EmailApi::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addSelect('email')
      ->addOrderBy('is_primary', 'DESC')
      ->execute();
    foreach ($emails as $email) {
      if (self::isWikimediaEmail($email['email'])) {
        return $email['email'];
      }
    }
    return \Civi::settings()->get('wmf_mg_fallback_notification_email');
  }

  /**
   * Check whether an email address is a wikimedia.org address.
   */
  public static function isWikimediaEmail(?string $email): bool {
    return $email && preg_match('/@wikimedia\.org$/i', $email);
  }

}
