<?php namespace thank_you\generators;

class ThankYouSubject extends RenderTranslatedPage {

  function __construct() {
    // FIXME: drupal var and settings UI
    $this->title = 'Fundraising/Translation/Thank_you_subject_2018-10-01';
    $this->proto_file = __DIR__ . '/../templates/subject/thank_you.$1.subject';
    $this->substitutions = [
      '/<p>/i' => '',
      '/<\/p>/i' => '',
    ];
  }

  function add_template_info_comment( $page_content, $template_info ) {
    return $page_content;
  }
}
