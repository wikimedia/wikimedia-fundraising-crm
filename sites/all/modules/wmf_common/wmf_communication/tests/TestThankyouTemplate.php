<?php

use wmf_communication\AbstractMailingTemplate;

class TestThankyouTemplate extends AbstractMailingTemplate {
    function getSubjectKey() {
        return 'donate_interface-email-subject';
    }

    function getTemplateName() {
        return 'thank_you';
    }

    function getTemplateDir() {
        return __DIR__ . "/templates";
    }
}
