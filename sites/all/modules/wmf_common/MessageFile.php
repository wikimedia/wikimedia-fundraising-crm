<?php

/**
 * Class MessageFile replicates some of the internationalization features of
 * MediaWiki allowing usage of i18n files in the standard MediaWiki format.
 *
 * @author Peter Gehres <pgehres@wikimedia.org>
 * @license GPLv2 or later
 */
class MessageFile {

  protected $messages = null;

  /**
   * Creates a new MessageFile and initializes it with the messages
   * contained in the specified file.
   *
   * @param string $msg_file filename of the file containing the messages
   * @throws Exception if the messages could not be loaded
   */
  public function __construct($msg_file) {
    if (!file_exists($msg_file)) {
      throw new Exception("Message file does not exist: $msg_file");
    }

    // The assumption is made that the included file uses an array called
    // $messages to store the messages.  This is the MediaWiki default.
    $messages = array();
    include $msg_file;

    $this->messages = $messages;
  }

  /**
   * Gets a message in the specified language, if it exists.  If a translation does not
   * exist, the function attempts to return the requested message in English.  If the
   * message still does not exist, the key is returned.
   *
   * @param string $key the name of the message to retrieve
   * @param string $language language code to attempt to retrieve
   * @return string the message in the requested language, a fallback, or finally the key
   * @throws Exception if the MessageFile is not properly initialized
   */
  public function getMsg($key, $language) {
    if($this->messages === null){
      throw new Exception("MessageFile not initialized");
    }
    // if the requested translation exists, return that
    if ($this->msgExists($key, $language)) {
      return $this->messages[$language][$key];
    }
    // if not, but an english version exists, return that
    elseif ($this->msgExists($key, 'en')) {
      return $this->messages['en'][$key];
    }
    // finally, return the message key itself
    return $key;
  }

  /**
   * Determines whether or not a translation exists in the language specified for
   * the message specified.  The function does not take into account any fallback
   * languages defined for the specified language.
   *
   * @param string $key the message key for which to search
   * @param string $language the language code for which to search for a translation of the message
   * @return bool true if a translation exists for the message, false otherwise
   * @throws Exception if the MessageFile is not properly initialized
   */
  public function msgExists($key, $language) {
    return array_key_exists($language, $this->messages)
      && array_key_exists($key, $this->messages[$language]);
  }

  /**
   * Gets a list of the languages included in this MessageFile
   *
   * @return array a list of the language codes represented in this MessageFile
   * @throws Exception if the MessageFile is not properly initialized
   */
  public function languageList() {
    return array_keys($this->messages);
  }
}
