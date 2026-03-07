<?php

/**
 * Import the data from a file or a xml string
 *
 * @param QDOM $File
 * @param integer $overwrite
 * @return mixed
 */

use QUI\QDOM;

QUI::getAjax()->registerFunction(
    'package_quiqqer_translator_ajax_file_import',
    function ($File, $overwrite) {
        $overwrite = (int)$overwrite;

        /* @var $File QUI\QDOM */
        if (!$File->getAttribute('filepath')) {
            QUI::getMessagesHandler()->addError(
                QUI::getLocale()->get(
                    'quiqqer/translator',
                    'exception.file.could.not.read'
                )
            );

            return [];
        }

        if ($overwrite == 1) {
            return QUI\Translator::import(
                $File->getAttribute('filepath'),
                false
            );
        }

        return QUI\Translator::import(
            $File->getAttribute('filepath'),
            true
        );
    },
    ['File', 'overwrite'],
    'Permission::checkAdminUser'
);
