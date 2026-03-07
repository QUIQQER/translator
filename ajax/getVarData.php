<?php

/**
 * Update einer Variablen
 *
 * @param array $groups
 * @param array $data
 *
 * @throws QUI\Exception
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_translator_ajax_getVarData',
    function ($group, $var, $pkg) {
        return QUI\Translator::getVarData($group, $var, $pkg);
    },
    ['group', 'var', 'pkg'],
    'Permission::checkAdminUser'
);
