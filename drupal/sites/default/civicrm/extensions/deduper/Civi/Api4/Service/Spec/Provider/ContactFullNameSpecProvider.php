<?php

namespace Civi\Api4\Service\Spec\Provider;

use Civi\API\Event\PrepareEvent;
use Civi\Api4\Name;
use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\Provider\Generic\SpecProviderInterface;
use Civi\Api4\Service\Spec\RequestSpec;
use Civi\Core\Service\AutoSubscriber;

/**
 * Provider to handle full name as a pseudo field.
 */
class ContactFullNameSpecProvider extends AutoSubscriber implements SpecProviderInterface {

  public static function getSubscribedEvents(): array {
    return [
      'civi.api.prepare' => 'prepareFullName',
    ];
  }

  /**
   * @param string $entity
   * @param string $action
   *
   * @return bool
   */
  public function applies(string $entity, string $action): bool {
    return $entity === 'Contact' && in_array($action, ['create', 'update', 'save'], TRUE);
  }

  /**
   * @param \Civi\Api4\Service\Spec\RequestSpec $spec
   *
   * @return void
   */
  public function modifySpec(RequestSpec $spec): void {
    $fullName = new FieldSpec('full_name', $spec->getEntity(), 'String');
    $fullName->setTitle('Full Name')
      ->setDescription('Full name to be parsed');
    $fullName->setUsage(['import']);
    $spec->addFieldSpec($fullName);
  }

  /**
   * @param \Civi\API\Event\PrepareEvent $event
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @noinspection PhpUnused
   * @noinspection UnknownInspectionInspection
   */
  public function prepareFullName(PrepareEvent $event): void {
    $apiRequest = $event->getApiRequest();
    if ($apiRequest['version'] === 3 || !$this->applies($event->getEntityName(), $event->getActionName())) {
      return;
    }
    $parameters = $apiRequest->getParams();
    if ($event->getActionName() === 'save') {
      $defaultName = $parameters['defaults']['full_name'] ?? '';
      foreach ($parameters['records'] as $index => $values) {
        $fullName = $values['full_name'] ?? $defaultName;
        if ($fullName) {
          $parameters['records'][$index] = $this->addParsedName($this->cleanString((string) $fullName, 128), $values);
        }
        unset($parameters['records'][$index]['full_name'], $values['full_name']);
      }
      $apiRequest->setRecords($parameters['records']);
      if ($defaultName) {
        unset($parameters['defaults']['full_name']);
        $apiRequest->setDefaults($parameters['defaults']);
      }
    }
    else {
      $values = $parameters['values'];
    }

    if (!empty($values['full_name'])) {
      $fullName = $this->cleanString((string) $values['full_name'], 128);
      $values = $this->addParsedName($fullName, $values);
      $apiRequest->setValues($values);
    }
  }

  /**
   * Clean up a string by
   *  - trimming preceding & ending whitespace
   *  - removing any in-string double whitespace
   *
   * @param string $string
   * @param int $length
   *
   * @return string
   */
  protected function cleanString(string $string, int $length): string {
    $replacements = [
      // Hex for &nbsp;
      '/\xC2\xA0/' => ' ',
      '/&nbsp;/' => ' ',
      // Replace multiple ideographic space with just one.
      '/(\xE3\x80\x80){2}/' => html_entity_decode("&#x3000;"),
      // Trim ideographic space (this could be done in trim further down but seems a bit fiddly)
      '/^(\xE3\x80\x80)/' => ' ',
      '/(\xE3\x80\x80)$/' => ' ',
      // Replace multiple space with just one.
      '/\s\s+/' => ' ',
      // And html ampersands with normal ones.
      '/&amp;/' => '&',
      '/&Amp;/' => '&',
    ];
    return mb_substr(trim(preg_replace(array_keys($replacements), $replacements, $string)), 0, $length);
  }

  /**
   * @param string $fullName
   * @param $values
   *
   * @return array|mixed
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function addParsedName(string $fullName, $values) {
    // Parse name parts into fields if we have the full name and the name parts are
    // not otherwise specified.
    $parsedName = Name::parse(FALSE)
      ->setNames([$fullName])
      ->execute()->first();
    if (!empty($parsedName)) {
      $values = array_merge(array_filter($parsedName), $values);
      $values['addressee_custom'] = $values['addressee_display'] = $fullName;
      unset($values['full_name']);
    }
    return $values;
  }

}
