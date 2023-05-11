<?php


namespace Civi\Api4\Action\ThankYou;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

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
 * @method $this setContributionID(int $contributionID)
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
   * @var int
   */
  protected $contributionID;

  /**
   * The name of the selected template.
   *
   * Options are thank_you, endowment_thank_you, monthly_convert.
   *
   * @var string
   */
  protected $templateName;

  public function getContributionID() {
    return $this->contributionID ?: $this->getTemplateParameters()['contribution_id'];
  }

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

    // @todo - this handling for tags can be removed once we switch to allowing
    // the WorkFlow message to render using it's own method.
    // It is here as a temporary refactor so we can add the test & remove from the
    // other places.
    $tags = \Civi\Api4\EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civicrm_contribution')
      ->addWhere('entity_id', '=', $this->getContributionID())
      ->addWhere('tag_id:name', 'IN', ['RecurringRestarted', 'UnrecordedCharge'])
      ->addSelect('tag_id:name')
      ->execute()->indexBy('tag_id:name');
    $templateParams['isRecurringRestarted'] = !empty($tags['RecurringRestarted']);
    $templateParams['isDelayed'] = !empty($tags['UnrecordedCharge']);

    if ($templateParams['stock_value']) {
      $templateParams['stock_value'] = Civi::format()
        ->money($templateParams['stock_value'], $templateParams['currency']);
    }
    else {
      $templateParams['stock_value'] = 0;
    }
    if ($templateParams['amount']) {
      $templateParams['amount'] = Civi::format()
        ->money($templateParams['amount'], $templateParams['currency']);
    }
    else {
      $templateParams['amount'] = 0;
    }

    $templateStrings = Civi\Api4\Message::load(FALSE)
      ->setLanguage($this->getLanguage())
      ->setFallbackLanguage('en_US')
      ->setWorkflow($this->getTemplateName())->execute()->first();

    foreach ($templateStrings as $key => $string) {
      $template[$key] = $string['string'];
    }
    $smarty = \CRM_Core_Smarty::singleton();
    // @todo - Once we have created a template within CiviCRM we can use
    // the render method as in eoy_email.
    $html = $smarty->fetchWith('string:' . $template['msg_html'], $templateParams);
    $subject = $smarty->fetchWith('string:' . $template['msg_subject'], $templateParams);
    $page_content = str_replace('<p></p>', '', $html);
    $result[] = [
      'html' => $page_content,
      'subject' => trim($subject),
    ];
  }

}
