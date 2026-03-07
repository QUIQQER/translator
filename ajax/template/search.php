<?php

/**
 * Search template
 *
 * @return String
 */

use QUI\Translator;

QUI::getAjax()->registerFunction(
    'package_quiqqer_translator_ajax_template_search',
    function () {
        $Engine = QUI::getTemplateManager()->getEngine(true);

        $languages = [];
        $result = Translator::langs();

        foreach ($result as $lang) {
            if (strlen($lang) == 2) {
                $languages[] = $lang;
            }
        }

        $Engine->assign([
            'languages' => $languages
        ]);

        return $Engine->fetch(
            str_replace('/ajax/template', '', dirname(__FILE__)) . '/template/search.html'
        );
    },
    false,
    'Permission::checkAdminUser'
);
