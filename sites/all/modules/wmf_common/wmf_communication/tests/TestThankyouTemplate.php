<?php

use wmf_communication\AbstractMailingTemplate;

class TestThankyouTemplate extends AbstractMailingTemplate {
    function getTemplateName() {
        return 'thank_you';
    }

    function getTemplateDir() {
        return __DIR__ . "/templates";
    }
}
