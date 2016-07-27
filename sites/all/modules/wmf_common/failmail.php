<?php

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
    $to = variable_get('wmf_common_failmail', '');
    if (empty($to)) {
        $to = 'fr-tech@wikimedia.org';
    }
    $params['error'] = $error;
    $params['message'] = $message;
    if ($source) {
        $params['source'][] = $source;
    }

    watchdog(
        'failmail',
        "What's that? Something wrong: $message",
        array(),
        WATCHDOG_ERROR
    );

    $params['module'] = $module;
    $params['removed'] = (is_callable(array($error, 'isRejectMessage'))) ? $error->isRejectMessage() : FALSE;
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
            $message['subject'] = t('Fail Mail : REMOVAL');
            $message['body'][] = t("A message was removed from ActiveMQ due to the following error(s):");
        } elseif(empty($params['error'])){
            $message['subject'] = t('Fail Mail : UNKNOWN ERROR');
            $message['body'][] = t("A message failed for reasons unknown, while being processed:");
        } else {
            $message['subject'] = t('Fail Mail');
            if ( property_exists( $params['error'], 'type' ) ) {
                $message['subject'] .= ' : ' . $params['error']->type;
            }
            $message['body'][] = t("A message generated the following error(s) while being processed:");
        }
        if ( !empty($params['module']) ) {
            $message['subject'] .= " ({$params['module']})";
        }
    }

    if (is_callable(array($params['error'], 'getMessage'))) {
        $message['body'][] = t("Error: ") . $params['error']->getMessage();
    } elseif (!empty($params['error'])) {
        $message['body'][] = t("Error: ") . $params['message'];
    }
    if(!empty($params['source'])){
        $message['body'][] = "---" . t("Source") . "---";
        $message['body'][] = print_r($params['source'], true);
        $message['body'][] = "---" . t("End") . "---";
    } else {
        $message['body'][] = t("The exact message was deemed irrelevant.");
    }
}
