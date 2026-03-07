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
    'package_quiqqer_translator_ajax_update',
    function ($groups, $data, $showSuccessMessage) {
        $data = json_decode($data, true);

        if (!isset($showSuccessMessage)) {
            $showSuccessMessage = 1;
        }

        $showSuccessMessage = (int)$showSuccessMessage;

        if (!isset($data['var'])) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/translator',
                    'exception.translation.not.found.update'
                ),
                404
            );
        }

        $result = QUI\Translator::get($groups, $data['var']);

        if (!isset($result[0])) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/translator',
                    'exception.translation.var.not.found.update',
                    [
                        'var' => $data['var'],
                        'group' => $groups
                    ]
                ),
                404
            );
        }

        // benutzer edit
        if (isset($data['id'])) {
            QUI\Translator::editById($data['id'], $data);
        } else {
            if (!isset($data['package'])) {
                $data['package'] = '';
            }

            QUI\Translator::edit($groups, $data['var'], $data['package'], $data);
        }

        if ($showSuccessMessage) {
            QUI::getMessagesHandler()->addSuccess(
                QUI::getLocale()->get(
                    'quiqqer/translator',
                    'message.translation.update.successful'
                )
            );
        }
    },
    ['groups', 'data', 'showSuccessMessage'],
    'Permission::checkAdminUser'
);
