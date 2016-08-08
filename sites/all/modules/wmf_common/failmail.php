<?php

use wmf_communication\Mailer;

/**
 * Send out a failmail notifying fr-tech of an abnormality in processing.
 *
 * @param string $module
 * @param string $message
 * @param Exception $error
 * @param string $source
 */
function wmf_common_failmail( $module, $message, $error = null, $source = null )
{
    watchdog(
        'failmail',
        "What's that? Something wrong: $message",
        array(),
        WATCHDOG_ERROR
    );

    $isRemoved = (is_callable(array($error, 'isRejectMessage'))) ? $error->isRejectMessage() : FALSE;
    $mailer = Mailer::getDefault();
    $mailer->send(array(
      'from_address' => variable_get('site_mail', ini_get('sendmail_from')),
      'from_name' => 'Fail Mail',
      'html' => wmf_common_get_body($message, $error, $source, $isRemoved),
      'reply_to' => '',
      'subject' => _wmf_common_get_subject($error, $module, $isRemoved),
      'to' => variable_get('wmf_common_failmail', 'fr-tech@wikimedia.org'),
    ));
}

/**
 * Get the subject for the failmail.
 *
 * @param Exception|NULL $error
 * @param string $module
 * @param bool $isRemoved
 *
 * @return null|string
 */
function _wmf_common_get_subject($error, $module, $isRemoved) {
  $subject = t('Fail Mail');
  if ($isRemoved === true){
      $subject = t('Fail Mail : REMOVAL');
  } elseif ($error === null) {
      $subject = t('Fail Mail : UNKNOWN ERROR');
  } else {
      if (property_exists($error, 'type')) {
          $subject .= ' : ' . $error->type;
      }
  }
  $subject .= $module ? " ({$module})" : '';
  return $subject;

}

/**
 * Get the body for the failmail.
 *
 * @param string $message
 * @param Exception|NULL $error
 * @param array $source
 * @param bool $isRemoved
 *
 * @return string
 */
function wmf_common_get_body($message, $error, $source, $isRemoved)
{
    $body = array();

    if ($isRemoved === true){
        $body[] = t("A message was removed from ActiveMQ due to the following error(s):");
    } elseif($error === null && !$message){
        $body[] = t("A message failed for reasons unknown, while being processed:");
    } else {
        $body[] = t("A message generated the following error(s) while being processed:");
    }
    if ($message) {
      $body[] = $message;
    }

    if (is_callable(array($error, 'getMessage'))) {
        $body[] = t("Error: ") . $error->getMessage();
    }

    if(!empty($source)){
        $body[] = "---" . t("Source") . "---";
        $body[] = print_r($source, true);
        $body[] = "---" . t("End") . "---";
    } elseif (empty($message)) {
        $body[] = t("The exact message was deemed irrelevant.");
    }
    return '<p>' . implode($body, '</p><p>') . '</p>';
}
