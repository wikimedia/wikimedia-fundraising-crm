<?php
namespace Civi\Api4\Action\WMFDataManagement;

use Civi\Api4\Activity;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Class ArchiveThankYou.
 *
 * Delete details from old thank you emails.
 *
 * @method setLimit(int $limit) Set the number of activities to hit in the run.
 * @method getLimit(): int Get the number of activities
 * @method setEndTimeStamp(string $endTimeStamp) Set the time to purge up to.
 * @method getEndTimeStamp(): string Get the time to purge up to.
 * @method getBatchSize(): int get the number to do each query.
 * @method setBatchSize(int $batchSize) set the number to run each query.
 *
 * @package Civi\Api4
 */
class ArchiveThankYou extends AbstractAction {

  /**
   * Limit for run.
   *
   * @var int
   */
  protected $limit = 10000;

  /**
   * Number to run per iteration.
   *
   * @var int
   */
  protected $batchSize = 1000;

  /**
   * Date to finish at - a strtotime-able value.
   *
   * @var string
   */
  protected $endTimeStamp = '1 year ago';

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    // We might need a temp table - but testing without first.
    // $tempTable = \CRM_Utils_SQL_TempTable::build()->createWithColumns('id int unsigned NOT NULL  KEY ( id )');
    $rows = 0;
    $orClause = [
      ['subject', 'IN', $this->getThankYouSubjects()],
    ];
    $includeSubjects = [];
    $excludeSubjects = [];
    foreach ($this->getThankYouLikeSubjects() as $string) {
      $orClause[] = ['subject', 'LIKE', addslashes($string)];
      $includeSubjects[] = 'subject LIKE "' . addslashes($string) . '"';
      $excludeSubjects[] = 'subject NOT LIKE "' . addslashes($string) . '"';
    }
    $this->logIncludeSQL($includeSubjects);

    $this->logExcludeSQL($excludeSubjects);


    while ($rows < $this->getLimit()) {
      $ids = array_keys((array) Activity::get($this->getCheckPermissions())
        ->addSelect('id')
        ->addWhere('activity_type_id:name', '=', 'Email')
        ->addWhere('details', '<>', '')
        ->addWhere('activity_date_time', '<', $this->getEndTimeStamp())
        ->addClause('OR', $orClause)
        ->setLimit($this->getBatchSize())
        ->setDebug(TRUE)
        ->execute()->indexBy('id'));
       if (empty($ids)) {
         break;
       }
      \CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_activity SET details = NULL
        WHERE id IN(" . implode(',', $ids) . ")"
      );
      \CRM_Core_DAO::executeQuery(
        "UPDATE log_civicrm_activity SET details = NULL
        WHERE id IN(" . implode(',', $ids) . ")"
      );
      $rows += count($ids);
    }
  }

  public function getThankYouLikeSubjects() {
    return [
      '%free knowledge for billions%',
      '%Lời cảm ơn cá nhân từ Maryana%',
      '%你的捐款是另一個值得慶賀的原因%',
      '%您的捐款为我们的 % 周年庆锦上添花%',
      '%tavs ziedojums ir vēl viens iemesls svinībām.',
      '%Ваша пожертва — це ще один привід  для святкування.',
      '%gift allows us to look far ahead.',
      '%donation is one more reason to celebrate.',
      '%A personal thank you note from Maryana, the Wikimedia Foundation’s CEO.',
      '%Een persoonlijk bedankbriefje van Maryana, de CEO van de Wikimedia Foundation.',
      '%Thank you for your gift',
      'ウィキペディアへの毎月のご寄付に心から感謝しています',
      '%votre don nous donne autre chose à célébrer',
      '%jouw donatie is een reden te meer voor een feestje.',
      '%あなたからの贈り物に感謝いたします。',
      '%Gracias por tu donativo',
      '%Grazie per la tua donazione',
      '%Takk for gaven',
      '%Wir danken Ihnen für Ihr Geschenk',
      '%あなたの定期的なご寄付のおかげでより成果を挙げられます',
      '%personal thank you note from Lisa, President of the Wikimedia Endowment%',
      '%Tack för din gåva',
      '%Bedankt voor je donatie',
      '%Köszönjük az ajándékod',
      '%Merci pour votre don',
      '%Mulțumesc pentru cadou',
      '%Tak for din gave',
      '%謝謝您的禮物',
      '%Obrigado pelo seu presente',
      '%спасибо за подарок',
      '%Osobné poďakovanie od Maryany%',
      '%Recevez les remerciements personnels de Maryana, directrice générale de la Wikimedia Foundation.',
      '%Ceci est un remerciement personnel de la part de Maryana, directrice générale de la Wikimedia Foundation.',
      '%Uma nota pessoal de agradecimento da Maryana%',
      '%Личная благодарность от Марианы Искандер%',
      '%Una nota de agradecimiento de Maryana%',
      '%Osobiste podziękowania od Maryany%',
      '%ウィキメディア財団 CEOのマリアナからのお礼です%',
      '%來自 Wikimedia Foundation 行政總裁 Maryana 的個人感謝信%',
      '%Ein persönliches Dankesschreiben von Maryana%',
      '%Ein persönliches Dankesschreiben von Maryana%',
      '%En personlig takkemeddelelse fra Maryana%',
      '%Un messaggio personale di ringraziamento da parte di Maryana%',
      '%Ett personligt tack från Maryana%',
      '%O notă personală de mulțumire din partea Maryanei%',
      '%Uma nota pessoal de agradecimento de Maryana%',
      '%Personīga pateicība no Wikimedia Foundation izpilddirektores Marjanas.%',
      '%Votre don mensuel à Wikipédia mérite encore plus de remerciements',
      '%Una nota personal d\'agraïment de la Maryana%',
      '%Особиста подяка від Мар’яни Іскандер%',
      '%Személyes köszönetnyilvánító levél Maryanától%',
      '%Una nota de agradecimiento personal de Maryana%',
      '%מכתב תודה אישי ממריאנה, מנכ"לית קרן ויקימדיה%',
      '%En personlig takk fra Maryana%',
      '%Osobné poďakovanie od Maryany',
      '%tu donación nos da un motivo más para celebrar.',
      '%Persoonlike dankie-nota van Maryana%',
      '%Лична благодарница од Марјана%',
      '%din gåva ger oss ännu ett skäl att fira.',
      '%jouw donatie is reden voor nog meer vreugde.',
      '%o seu donativo é mais uma razão para celebrar.',
      '%התרומה שלך היא סיבה נוספת לחגוג.%',
      '%az adományod egy újabb ok az ünneplésre',
      '%Twoja darowizna daje nam kolejny powód do radości.',
      '%la tua donazione è una ragione in più per festeggiare',
      '%ваше пожертвование — еще одна причина для радости.',
      '%Ihre Spende ist ein Grund mehr zum Feiern.',
      '%din donasjon er enda en grunn til å feire.',
      '%din donation giver os endnu en ting at fejre.',
      '%com a sua doação, temos ainda mais motivos para comemorar.',
      '%la teva donació és un motiu més de celebració.',
      '%誕生20周年とあなたからのご寄付を祝して',
      '%váš dar je ďalším dôvodom na oslavu.',
      '%donația ta reprezintă încă un motiv pentru a sărbători.',
      '%jouw donatie is reden voor een feestje.',
      '%Ваша пожертва — це ще одна причина для свята%',
    ];
  }
  public function getThankYouSubjects() {
    return [
      'Your donation ensures the future of Wikipedia. Thank you.',
      'Благодарность от Фонда Викимедиа',
      'Takk fra Wikimedia Foundation',
      'Tak fra Wikimedia Foundation',
      'A te adományod. A te kíváncsiságod. A te Wikipédiád.',
      'Da Fundação Wikimedia, obrigado',
      'Da Fundação Wikimedia, obrigado!',
      'Mulțumiri din partea Fundației Wikimedia',
      'A Wikimédia Alapítvány köszöni a segítséd',
      'Paldies no Wikimedia Foundation',
      'Obrigado. Wikimedia Foundation.',
      'Ваш дар = бесплатные знания для миллиардов',
      'Ďakuje vám Wikimedia Foundation',
      'תודה לך מקרן ויקימדיה ',
      'Donația dvs. Curiozitatea dvs. Wikipedia dvs.',
      'Váš dar. Vaša zvedavosť. Vaša Wikipédia.',
      'Thank you for your recent stock donation',
      'Your donation keeps us all exploring',
      'Sua doação mensal à Wikipédia merece um agradecimento extra',
      'Your monthly gift to Wikipedia deserves an extra helping of thanks',
      'La tua donazione ci permette di continuare a esplorare',
      'Med hjälp av din gåva kan vi fortsätta utforska tillsammans',
      'La tua donazione ci permette di continuare a esplorare',
      'Sua doação. Sua curiosidade. Sua Wikipédia.',
      'O seu donativo. A sua curiosidade. A sua Wikipédia.',
      'Votre don. Vos raisons. Votre Wikipédia.',
      'Ваше пожертвование. Ваша любознательность. Ваша Википедия.',
      'Twoja darowizna. Twoja ciekawość. Twoja Wikipedia.',
      'Deine Spende. Dein Wissensdurst. Deine Wikipedia.',
      'Ваши регулярные пожертвования помогают нам достигать многих целей',
      'Din månedlige gave til Wikipedia fortjener en ekstra tak',
      'Twój miesięczny datek dla Wikipedii zasługuje na dodatkowe podziękowanie',
      'Külön meg szeretném köszönni a havi ajándékodat a Wikipédiának',
      'Ihr monatlicher Beitrag zu Wikipedia verdient eine Extraportion Anerkennung',
      'Ваші регулярні пожертви допомагають нам досягати багатьох цілей',
      '%az adományod egy újabb ok az ünneplésre',
      'Tvoj mesačný príspevok Wikipédii si zaslúži ďalšie poďakovanie',
      'Il tuo sostegno mensile a Wikipedia merita un ringraziamento extra',
      'Cadoul tău lunar pentru Wikipedia merită un nou rând de mulțumiri',
      'Din återkommande gåva hjälper oss att uppnå så mycket',
      'Tu donación recurrente nos ayuda a lograr muchas cosas',
      'Rendszeres adományod segítségével annyi mindent megvalósíthatunk',
      'Votre don récurrent nous permet d’accomplir tant de choses',
      'O seu donativo mensal à Wikipédia merece um agradecimento especial',
      'Je maandelijkse gift aan Wikipedia verdient een extra bedankje',
      'Tu regalo mensual a Wikipedia merece un agradecimiento extra',
      'La tua donazione regolare ci aiuta a realizzare così tanto',
      'La teva donació mensual a la Viquipèdia es mereix un agraïment especial.',
      'Thank you from the Wikimedia Foundation',
      'Din tilbagevendende gave hjælper os med at opnå meget mere',
      '你持續的禮物幫助我們達成更多目標',
      '来自维基媒体基金会的感谢信',
      'We’re here for you. Thanks for being here for us',
      'Tu regalo mensual para Wikipedia merece un agradecimiento adicional',
      'Your donation. Your curiosity. Your Wikipedia.',
      'Tack än en gång för din månatliga gåva till Wikipedia',
      'Your gift = free knowledge for billions',
      'Merci à vous de la part de la Wikimedia Foundation',
      'Merci à vous de la part de la Fondation Wikimédia',
      'Din donation. Din nyfikenhet. Ditt Wikipedia.',
      'Uw donatie. Uw nieuwsgierigheid. Uw Wikipedia.',
      'Grazie dalla Wikimedia Foundation',
      'Gracias desde la Fundación Wikimedia',
      'Dank u wel van de Wikimedia Foundation',
      'Die Wikimedia Foundation sagt Danke',
      'Podziękowanie od Wikimedia Foundation',
      'Votre cadeau = le savoir gratuit pour des milliards de personnes',
      'La tua donazione. La tua curiosità. La tua Wikipedia.',
      'ウィキメディア財団からの感謝',
      'あなたから世界への贈り物：無料でオープンな百科事典',
      'Tack från Wikimedia Foundation',
      'Recevez les remerciements personnels de Maryana, directrice générale de la Wikimedia Foundation.',
      'Een persoonlijk bedankbriefje van Maryana, de CEO van de Wikimedia Foundation.',
      'Tack för din gåva',
      'Poděkování od Wikimedia Foundation',
      'Grazas de parte da Fundación Wikimedia',
      'Una nota de agradecimiento de Maryana, directora ejecutiva de la Wikimedia Foundation.',
      'Ceci est un remerciement personnel de la part de Maryana, directrice générale de la Wikimedia Foundation.',
      'Your recurring gift helps us achieve so much',
      'Den faste gaven fra deg gjør at vi får til så mye.',
      'Rendszeres adományod segítségével annyi mindent megvalósíthatunk',
      'התרומה החוזרת שלכם עוזרת לנו לעשות המון',
    ];
  }

  /**
   * @param array $includeSubjects
   */
  protected function logIncludeSQL(array $includeSubjects): void {
    $includeSQL = 'SELECT count(*) FROM civicrm_activity
WHERE activity_type_id = 3
AND details <> ""
AND activity_date_time < "' . date('Y-m-d', strtotime($this->getEndTimeStamp())) . '"
AND (subject IN ("' . implode('",
 "', $this->getThankYouSubjects()) . '")
      OR ' . implode('
OR ', $includeSubjects) . ')';

    \Civi::log('wmf')->info('include where
{sql}
', ['sql' => $includeSQL]);
  }

  /**
   * @param array $excludeSubjects
   */
  protected function logExcludeSQL(array $excludeSubjects): void {
    $excludeSQL = 'SELECT DISTINCT subject FROM civicrm_activity
WHERE activity_type_id = 3
AND details <> ""
AND activity_date_time < "' . date('Y-m-d', strtotime($this->getEndTimeStamp())) . '"
AND subject NOT IN ("' . implode('",
 "', $this->getThankYouSubjects()) . '")
      -- some things we know can be left alone...
      AND subject NOT LIKE "%copy sent to %"
      AND subject NOT LIKE "Recur fail message%"
      AND subject NOT LIKE "Benefactor"
      AND subject NOT LIKE "Update address for Wikimedia Foundation"
      AND subject NOT LIKE "%We’d love to acknowledge your support!"
      AND subject NOT LIKE "Your Matching Gift Has Been Verified"
      AND subject NOT LIKE "You’re Invited: %"
      AND subject NOT LIKE "%Celebrate 20 years%"
      AND subject NOT LIKE "%Are you free to connect?%"
      AND subject NOT LIKE "We’d like to acknowledge your cumulative support!"
      AND subject NOT LIKE "Thank you for your interest in planned giving to Wikipedia"
      AND subject NOT LIKE "%You helped the Wikimedia Endowment reach a new milestone!%"
      AND subject NOT LIKE "Would you like to be listed on our Endowment Benefactor page?"
      AND subject NOT LIKE "A special gift for your generous commitment to Wikipedia"
      AND subject NOT LIKE "Thanks for making Wikipedia part of your legacy"
      AND subject NOT LIKE "See you %"
      AND subject NOT LIKE "Thank you for joining us!"
      AND subject NOT LIKE "Thank you for your retirement account gift"
      AND subject NOT LIKE "Thank you for including Wikipedia in your estate plans"
      AND subject NOT LIKE "test"
      AND subject NOT LIKE "%meet%"
      AND subject NOT LIKE "Please confirm your legacy gift to Wikipedia"
      AND subject NOT LIKE "%Houston%"
      AND subject NOT LIKE "%Seattle%"
      AND subject NOT LIKE "%Manhattan%"
      AND subject NOT LIKE "%NYC%"
      AND subject NOT LIKE "%coffee%"
      AND subject NOT LIKE "%freewill%"
      AND subject NOT LIKE "%legacy society%"
      AND subject NOT LIKE "%Menlo Park%"
      AND subject NOT LIKE "%Event%"
      AND subject NOT LIKE "%stock%"
      AND subject NOT LIKE "%Diwali%"
      AND subject NOT LIKE "%interview%"
      AND subject NOT LIKE "%public policy%"
      AND subject NOT LIKE "We’d like to acknowledge your support!"
      AND subject NOT LIKE "Will you protect Wikipedia%s future?"
      AND subject NOT LIKE "Will we see you on November 9?"
      AND  ' . implode('
AND ', $excludeSubjects) . '
      ORDER BY activity_date_time DESC
      LIMIT 50';

    \Civi::log('wmf')->info('exclude sql (for finding missing ones)
{sql}
', ['sql' => $excludeSQL]);
  }

}
