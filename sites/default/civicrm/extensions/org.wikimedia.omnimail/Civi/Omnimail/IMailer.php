<?php

namespace Civi\Omnimail;

/**
 * Must be implemented by every mailing engine
 */
interface IMailer {

  /**
   * Enqueue an email into the external mailing system
   *
   * @param array $email All keys are required:
   *    from_address
   *    from_name
   *    html
   *    plaintext
   *    reply_to
   *    subject
   *    to_address
   *    to_name
   *
   * @return boolean True if the mailing system accepted your message for
   *   delivery
   */
  function send($email);

}
