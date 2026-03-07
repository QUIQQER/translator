<?php

/**
 * Return the translation list
 *
 * @param string $groups
 * @param string $params
 * @param string $search
 *
 * @return array
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_translator_ajax_translations',
    function ($groups, $params, $search) {
        $languages = QUI\Translator::langs();
        $data = QUI\Translator::getData(
            $groups,
            json_decode($params, true),
            json_decode($search, true)
        );

        if (!QUI::conf('globals', 'development')) {
            foreach ($data['data'] as $key => $entry) {
                foreach ($languages as $lang) {
                    if (!empty($entry[$lang . '_edit'])) {
                        $data['data'][$key][$lang] = $entry[$lang . '_edit'];
                    }
                }
            }
        }

        return [
            'data' => $data,
            'langs' => $languages
        ];
    },
    ['groups', 'params', 'search'],
    'Permission::checkAdminUser'
);
