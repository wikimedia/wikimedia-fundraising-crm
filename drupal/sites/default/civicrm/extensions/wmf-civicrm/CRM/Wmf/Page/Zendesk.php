<?php

use CRM_Wmf_ExtensionUtil as E;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

class CRM_Wmf_Page_Zendesk extends CRM_Core_Page {

  public function run() {
    $zendesk_api_user = Civi::settings()->get('zendesk_api_user');
    $zendesk_api_password = Civi::settings()->get('zendesk_api_password');
    $zendeskURL = Civi::settings()->get('zendesk_url');
    $ticketURLPrefix = "{$zendeskURL}/agent/tickets/";
    $this->assign('ticketURLPrefix', $ticketURLPrefix);

    // retrieve contact email
    $contact_id = CRM_Utils_Request::retrieve('cid', 'Integer');
    list($displayName, $contactEmail) = CRM_Contact_BAO_Contact_Location::getEmailDetails($contact_id);

    // set up API client
    $zendeskApiClient = new Client();
    $requestAuthHeaders = $this->getApiAuthHeaders($zendesk_api_user, $zendesk_api_password);

    // fetch Zendesk open ticket data via API
    $openTicketSearchParams = "requester:{$contactEmail} status<solved";
    $openTicketsRequest = new Request('GET', "{$zendeskURL}/api/v2/search.json?query=$openTicketSearchParams", $requestAuthHeaders);
    $openTicketsResponse = $zendeskApiClient->sendAsync($openTicketsRequest)->wait();
    $openTickets = json_decode($openTicketsResponse->getBody(), TRUE);
    if ($openTickets['count'] > 0) {
      $this->assign('openTickets', $openTickets['results']);
    }

    // fetch Zendesk closed ticket data via API
    $closedTicketSearchParams = "requester:{$contactEmail} status>=solved";
    $closedTicketsRequest = new Request('GET', "{$zendeskURL}/api/v2/search.json?query=$closedTicketSearchParams", $requestAuthHeaders);
    $closedTicketsResponse = $zendeskApiClient->sendAsync($closedTicketsRequest)->wait();
    $closedTickets = json_decode($closedTicketsResponse->getBody(), TRUE);
    if ($closedTickets['count'] > 0) {
      $this->assign('closedTickets', $closedTickets['results']);
    }

    parent::run();
  }

  /**
   * @param string $zendesk_api_user
   * @param string $zendesk_api_password
   *
   * @return array
   */
  protected function getApiAuthHeaders(string $zendesk_api_user, string $zendesk_api_password): array {
    return [
      'Authorization' => 'Basic ' . base64_encode("$zendesk_api_user:$zendesk_api_password"),
    ];
  }

}
