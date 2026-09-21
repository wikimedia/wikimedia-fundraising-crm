<?php

use GuzzleHttp\Exception\GuzzleException;

/**
 * Returns the number of open Zendesk tickets for a contact as JSON.
 */
class CRM_Wmf_Page_ZendeskCount extends CRM_Wmf_Page_Zendesk {

  public function run() {
    // Release the session lock so a slow Zendesk call doesn't block the user's other requests.
    session_write_close();
    $contact_id = CRM_Utils_Request::retrieve('cid', 'Positive', NULL, TRUE);
    if (!$this->isConfigured()) {
      CRM_Utils_System::sendJSONResponse(['count' => NULL]);
    }
    $cacheKey = "zendesk_open_count_$contact_id";
    $count = Civi::cache('long')->get($cacheKey);
    if ($count === NULL) {
      $requesterQuery = $this->getRequesterQuery($contact_id);
      if (!$requesterQuery) {
        CRM_Utils_System::sendJSONResponse(['count' => NULL]);
      }
      try {
        $response = $this->getApiClient()->get('/api/v2/search/count.json', [
          'query' => ['query' => "$requesterQuery status<solved"],
        ]);
      }
      catch (GuzzleException $e) {
        Civi::log('wmf')->warning('Zendesk open ticket count failed for contact {contact_id}: {message}', [
          'contact_id' => $contact_id,
          'message' => $e->getMessage(),
        ]);
        CRM_Utils_System::sendJSONResponse(['count' => NULL]);
      }
      $data = json_decode($response->getBody(), TRUE);
      if (!isset($data['count'])) {
        Civi::log('wmf')->warning('Zendesk open ticket count response missing count for contact {contact_id}', [
          'contact_id' => $contact_id,
        ]);
        CRM_Utils_System::sendJSONResponse(['count' => NULL]);
      }
      $count = (int) $data['count'];
      Civi::cache('long')->set($cacheKey, $count, 120);
    }
    CRM_Utils_System::sendJSONResponse(['count' => $count]);
  }

}
