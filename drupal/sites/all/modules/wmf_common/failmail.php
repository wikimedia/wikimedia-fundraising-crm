<?php

use Civi\Omnimail\MailFactory;

/**
 * Send out a failmail notifying fr-tech of an abnormality in processing.
 *
 * @param string $module
 * @param string $message
 * @param Exception $error
 * @param string $source - In practice this is only even the gateway + trxn_id (log_id)
 */
function wmf_common_failmail($module, $message, $error = NULL, $source = NULL) {
  $isRemoved = (is_callable([$error, 'isRejectMessage'])) ? $error->isRejectMessage() : FALSE;
  $subject = _wmf_common_get_subject($error, $module, $isRemoved);
  \Civi::log('wmf')->alert(
    'failmail: What\'s that? Something wrong: {message}. Message was ' . ($isRemoved ? '' : ' not ') . 'removed. {log_id}',
    ['message' => $message, 'subject' => $subject, 'log_id' => $source]
  );


  $mailer = MailFactory::singleton()->getMailer();
  $mailer->send([
    'from_address' => \Civi::settings()->get('wmf_failmail_from'),
    'from_name' => 'Fail Mail',
    'html' => wmf_common_get_body($message, $error, $source, $isRemoved),
    'reply_to' => '',
    'subject' => $subject,
    'to' => \Civi::settings()->get('wmf_failmail_recipient'),
  ]);
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
  if ($isRemoved === TRUE) {
    $subject = t('Fail Mail : REMOVAL');
  }
  elseif ($error === NULL) {
    $subject = t('Fail Mail : UNKNOWN ERROR');
  }
  else {
    if (property_exists($error, 'type')) {
      $subject .= ' : ' . $error->type;
    }
  }
  $subject .= ': ' . gethostname();
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
function wmf_common_get_body($message, $error, $source, $isRemoved) {
  $body = [];

  if ($isRemoved === TRUE) {
    $body[] = t("A message was removed from the queue due to the following error(s):");
  }
  elseif ($error === NULL && !$message) {
    $body[] = t("A message failed for reasons unknown, while being processed:");
  }
  else {
    $body[] = t("A message generated the following error(s) while being processed:");
  }
  if ($message) {
    $body[] = $message;
  }

  if (is_callable([$error, 'getMessage'])) {
    $body[] = t("Error: ") . $error->getMessage();
  }

  if (!empty($source)) {
    $body[] = "---" . t("Source") . "---";
    $body[] = print_r($source, TRUE);
    $body[] = "---" . t("End") . "---";
  }
  elseif (empty($message)) {
    $body[] = t("The exact message was deemed irrelevant.");
  }
  return '<p>' . implode('</p><p>', $body) . '</p>';
}
