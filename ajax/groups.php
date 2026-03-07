<?php

/**
 * Return translation groups
 *
 * @return array
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_translator_ajax_groups',
    function () {
        return QUI\Translator::getGroupList();
    },
    false,
    'Permission::checkAdminUser'
);
