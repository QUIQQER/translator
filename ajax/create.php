<?php

/**
 * Publish translations
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_translator_ajax_create',
    function ($group, $showSuccessMessage) {
        if (!isset($showSuccessMessage)) {
            $showSuccessMessage = 1;
        }

        $showSuccessMessage = (int)$showSuccessMessage;

        if (!empty($group)) {
            QUI\Translator::publish($group);
        } else {
            QUI\Translator::create();
        }

        if ($showSuccessMessage) {
            QUI::getMessagesHandler()->addSuccess(
                QUI::getLocale()->get(
                    'quiqqer/translator',
                    'message.translation.create.successful'
                )
            );
        }
    },
    ['group', 'showSuccessMessage'],
    'Permission::checkAdminUser'
);
