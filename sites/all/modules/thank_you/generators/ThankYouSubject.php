<?php namespace thank_you\generators;

use wmf_communication\MediaWikiMessages;

class ThankYouSubject extends RenderTranslatedPage {

  protected $proto_file = __DIR__ . '/../templates/subject/thank_you.$1.subject';
  protected $key = 'donate_interface-email-subject';

  public function execute($wantedLangs = []) {
    watchdog(
      'make-thank-you',
      "Obtaining thank you subjects for placement into '{$this->proto_file}'",
      NULL,
      WATCHDOG_INFO
    );
    $messages = MediaWikiMessages::getInstance();
    if (empty($wantedLangs)) {
      $wantedLangs = $messages->languageList();
      watchdog('make-thank-you', 'Trying all possible languages', NULL, WATCHDOG_INFO);
    }
    foreach ($wantedLangs as $lang) {
      if (!$messages->msgExists($this->key, $lang)) {
        watchdog('make-thank-you', "$lang -- {$this->key} is not available!", NULL, WATCHDOG_ERROR);
        continue;
      }

      $subject = $messages->getMsg($this->key, $lang);
      $file = str_replace('$1', $lang, $this->proto_file);

      if (file_put_contents($file, $subject)) {
        watchdog('make-thank-you', "$lang -- Wrote translation into $file", NULL, WATCHDOG_INFO);
      }
      else {
        watchdog('make-thank-you', "$lang -- Could not open $file for writing!", NULL, WATCHDOG_ERROR);
      }
    }
  }
}
