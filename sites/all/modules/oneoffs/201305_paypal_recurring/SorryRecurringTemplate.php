<?php

use wmf_communication\AbstractMailingTemplate;

class SorryRecurringTemplate extends AbstractMailingTemplate {
    function getTemplateName() {
        return 'sorry_may2013';
    }

    function getTemplateDir() {
        return __DIR__ . "/templates";
    }
}
