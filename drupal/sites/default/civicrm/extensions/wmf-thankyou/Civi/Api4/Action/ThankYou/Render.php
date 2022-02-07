<?php


namespace Civi\Api4\Action\ThankYou;

use Civi;
use Civi\Api4\Exception\EOYEmail\NoContributionException;
use Civi\Api4\Exception\EOYEmail\NoEmailException;
use Civi\Api4\Exception\EOYEmail\ParseException;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use wmf_communication\Templating;
/**
 * Class Render.
 *
 * Get the content of the thank you.
 *
 * @method array getTemplateParameters() Get the parameters for the template.
 * @method $this setTemplateParameters(array $templateParameters) Get the parameters for the template.
 * @method string getTemplateName() Get the name of the template.
 * @method $this setTemplateName(string $templateName) Get the name of the template.
 * @method string getLanguage() Get the language to render in.
 * @method $this setLanguage(string $language) Set the language to render in.
 * @method $this setReceiveDate(string $receiveDate) Set the receiveData to UTC contribution received date.
 */
class Render extends AbstractAction {

  /**
   * Parameters for the template.
   *
   * @var array
   */
  protected $templateParameters = [];

  /**
   * The contact's language.
   *
   * This might already be in a 2 character variant but should
   * cope (once refactored) with the value stored on the contact.
   *
   * @var string
   */
  protected $language;

  /**
   * The name of the selected template.
   *
   * Options are thank_you, endowment_thank_you, monthly_convert.
   *
   * @var string
   */
  protected $templateName;

  /**
   * The contribution receive date.
   *
   * This is mapped to Hawaii time for US tax purposes.
   *
   * @var string
   */
  protected $receiveDate;

  public function getReceiveDate() {
    // Format the datestamp
    $date = strtotime($this->receiveDate);

    // For tax reasons, any donation made in the US on Jan 1 UTC should have a timestring in HST.
    // So do 'em all that way.
    return strftime('%Y-%m-%d', $date - (60 * 60 * 10));

  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \Throwable
   */
  public function _run(Result $result): void {
    $locale = $this->getLanguage();
    $templateParams = $this->getTemplateParameters();
    if (!$locale) {
      Civi::log('wmf')->info('Donor language unknown.  Defaulting to English...', ['language' => $this->getLanguage()]);
      $locale = 'en';
    }
    // TemplateParams['locale'] holds the mediawiki-style locale.
    // It is used for
    // 1 - a template parameter for adding locale to a url
    // 2 - loading the template - this usage will be removed once
    // we move the templates to the database (at which point users can edit too).
    $templateParams['locale'] = strtolower(str_replace('_', '-', $locale));
    // If is important to do this before we do any currency formatting.
    // There is a destruct method on the AutoClean class that
    // means once this variable is torn down (e.g. at the end of the
    // function) the locale will revert back.
    $swapLocale = \CRM_Utils_AutoClean::swapLocale($this->getLanguage());

    $templateParams['receive_date'] = $this->getReceiveDate();
    $templateParams['gift_source'] = $templateParams['gift_source'] ?? NULL;
    $templateParams['stock_value'] = $templateParams['stock_value'] ?? NULL;
    if ($templateParams['stock_value']) {
      $templateParams['stock_value'] = Civi::format()
        ->money($templateParams['stock_value'], $templateParams['currency']);
    }
    if ($templateParams['amount']) {
      $templateParams['amount'] = Civi::format()
        ->money($templateParams['amount'], $templateParams['currency']);
    }

    $templatesDirectory =       // This hard-coded path is transitional.
      __DIR__ . DIRECTORY_SEPARATOR . '../../../../../wmf-civicrm/msg_templates/' . $this->getTemplateName();
    // @todo - stop loading the 'old' way & load from the database.
    $template = new Templating(
      $templatesDirectory,
      $this->getTemplateName(),
      $templateParams['locale'],
      $templateParams
    );

    $smarty = \CRM_Core_Smarty::singleton();
    // At this stage we are still using the old templating system to select our translation.
    $htmlTemplate = $templatesDirectory . DIRECTORY_SEPARATOR . $template->loadTemplate('html')->getTemplateName();
    $html = $smarty->fetchWith($htmlTemplate, $templateParams);
    $subjectTemplate = $templatesDirectory . DIRECTORY_SEPARATOR . $template->loadTemplate('subject')->getTemplateName();
    $subject = $smarty->fetchWith($subjectTemplate, $templateParams);
    $page_content = str_replace('<p></p>', '', $html);
    $result[] = [
      'html' => $page_content,
      'subject' => trim($subject),
    ];
  }

}
