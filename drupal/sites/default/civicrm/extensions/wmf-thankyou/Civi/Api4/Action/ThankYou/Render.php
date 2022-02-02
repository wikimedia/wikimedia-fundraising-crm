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
   * Options are thank_you, endowment_thank_you, recurring_notification.
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
    $templateParams = $this->getTemplateParameters();
    $templateParams['receive_date'] = $this->getReceiveDate();
    $template = new Templating(
      // This hard-coded path is transitional.
      __DIR__ . DIRECTORY_SEPARATOR . '../../../../../wmf-civicrm/msg_templates/' . $this->getTemplateName(),
      $this->getTemplateName(),
      $this->getLanguage(),
      $templateParams
    );

    $html = $template->loadTemplate('html')->render($templateParams);
    $subject = $template->loadTemplate('subject')->render($templateParams);
    $page_content = str_replace('<p></p>', '', $html);
    $result[] = [
      'html' => $page_content,
      'subject' => trim($subject),
    ];
  }

}
