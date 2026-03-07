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

        $decodedLangs = json_decode($langs, true);

        if (!is_array($decodedLangs)) {
            $decodedLangs = [];
        }

        $langs = array_values(
            array_filter(
                QUI\Utils\Security\Orthos::clearArray($decodedLangs),
                static function ($entry): bool {
                    return is_string($entry);
                }
            )
        );
        $type = QUI\Utils\Security\Orthos::clear($type);

        QUI\Utils\System\File::downloadHeader(
            QUI\Translator::export($group, $langs, $type, boolval($external))
        );
    },
    ['group', 'langs', 'type', 'external'],
    'Permission::checkAdminUser'
);
