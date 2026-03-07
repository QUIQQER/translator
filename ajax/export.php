<?php

/**
 * Export a group, send the download header
 * Please call it in an iframe or new window
 * no quiqqer xml would be sent
 *
 * @param String $group - translation group
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_translator_ajax_export',
    function ($group, $langs, $type, $external) {
        $group = str_replace('/', '!GROUPSEPARATOR!', $group);
        $group = QUI\Utils\Security\Orthos::clear($group);
        $group = str_replace('!GROUPSEPARATOR!', '/', $group);

        $langs = QUI\Utils\Security\Orthos::clearArray(
            json_decode($langs, true)
        );
        $type = QUI\Utils\Security\Orthos::clear($type);

        QUI\Utils\System\File::downloadHeader(
            QUI\Translator::export($group, $langs, $type, boolval($external))
        );
    },
    ['group', 'langs', 'type', 'external'],
    'Permission::checkAdminUser'
);
