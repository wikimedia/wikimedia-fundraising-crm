<?php

function wmf_common_failmail($error, $source = null, $removed = FALSE)
{
    $to = variable_get('wmf_common_failmail', '');
    if (empty($to)) {
        $to = 'fr-tech@wikimedia.org';
    }
    $params['error'] = $error;
    if ($source)
        $params['source'][] = $source;
    elseif (property_exists($error, 'source'))
        $params['source'][] = $error->source;
    $params['removed'] = $removed;
    drupal_mail('wmf_common', 'fail', $to, language_default(), $params);
}

/*
 * Hook called by drupal_mail to construct the message.
 */
function wmf_common_mail($key, &$message, $params)
{
    switch($key) {
    case 'fail':
        if ($params['removed'] === true){
            $message['subject'] = t('queue2civicrm Fail Mail : REMOVAL');
            $message['body'][] = t("A message was removed from ActiveMQ due to the following error(s):");
        } elseif(empty($params['error'])){
            $message['subject'] = t('queue2civicrm Fail Mail : UNKNOWN ERROR');
            $message['body'][] = t("A message failed for reasons unknown, while being processed in queue2civicrm:");
        } else {
            $message['subject'] = t('queue2civicrm Fail Mail');
            $message['body'][] = t("A message generated the following error(s) while being processed in queue2civicrm:");
        }
    }

    if(!empty($params['error'])){
        $message['body'][] = t("Error: ") . $params['error']->getMessage();
    }
    if(!empty($params['source'])){
        $message['body'][] = "---" . t("Source") . "---";
        $message['body'][] = print_r($params['source'], true);
        $message['body'][] = "---" . t("End") . "---";
    } else {
        $message['body'][] = t("The exact message was deemed irrelevant.");
    }
}
