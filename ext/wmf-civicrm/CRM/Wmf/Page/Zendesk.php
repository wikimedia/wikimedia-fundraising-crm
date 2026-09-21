<?php

use CRM_Wmf_ExtensionUtil as E;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class CRM_Wmf_Page_Zendesk extends CRM_Core_Page {

  public function run() {
    $zendeskURL = Civi::settings()->get('zendesk_url');
    $ticketURLPrefix = "{$zendeskURL}/agent/tickets/";
    $this->assign('ticketURLPrefix', $ticketURLPrefix);

    $contact_id = CRM_Utils_Request::retrieve('cid', 'Positive', NULL, TRUE);
    if (!$this->isConfigured()) {
      return parent::run();
    }
    $requesterQuery = $this->getRequesterQuery($contact_id);
    if (!$requesterQuery) {
      return parent::run();
    }

    $zendeskApiClient = $this->getApiClient();

    try {
      // fetch Zendesk open ticket data via API, unless recently counted as zero
      if (Civi::cache('long')->get("zendesk_open_count_$contact_id") !== 0) {
        $openTicketsResponse = $zendeskApiClient->get('/api/v2/search.json', ['query' => ['query' => "$requesterQuery status<solved"]]);
        $openTickets = json_decode($openTicketsResponse->getBody(), TRUE);
        if ($openTickets['count'] > 0) {
          $this->assign('openTickets', $openTickets['results']);
        }
      }

      // fetch Zendesk closed ticket data via API
      $closedTicketsResponse = $zendeskApiClient->get('/api/v2/search.json', ['query' => ['query' => "$requesterQuery status>=solved"]]);
      $closedTickets = json_decode($closedTicketsResponse->getBody(), TRUE);
      if ($closedTickets['count'] > 0) {
        $this->assign('closedTickets', $closedTickets['results']);
      }
    }
    catch (GuzzleException $e) {
      $this->assign('zendeskError', E::ts('Cannot connect to Zendesk API: %1', [1 => $e->getMessage()]));
    }

    parent::run();
  }

  /**
   * Don't query the Zendesk api if the settings are at their default value.
   */
  protected function isConfigured(): bool {
    $settings = Civi::settings();
    return $settings->get('zendesk_api_user') !== $settings->getDefault('zendesk_api_user')
      && $settings->get('zendesk_api_password') !== $settings->getDefault('zendesk_api_password');
  }

  protected function getApiClient(): Client {
    $settings = Civi::settings();
    return new Client([
      'base_uri' => $settings->get('zendesk_url'),
      'auth' => [$settings->get('zendesk_api_user'), $settings->get('zendesk_api_password')],
      'timeout' => 10,
    ]);
  }

  /**
   * Zendesk search ORs repeated keywords, so this matches any of the contact's emails.
   */
  protected function getRequesterQuery(int $contactId): string {
    $emails = \Civi\Api4\Email::get(FALSE)
      ->addSelect('email')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('email', 'IS NOT EMPTY')
      ->execute()
      ->column('email');
    $emails = array_unique($emails);
    if (!$emails) {
      return '';
    }
    return 'requester:' . implode(' requester:', $emails);
  }

}
