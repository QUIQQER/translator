/**
 * Publish the translations
 */
define(['Ajax'], function (Ajax) {
    "use strict";

    return {
        publish: function (Translator, oncomplete) {
            oncomplete = oncomplete || function () {
                };

            Ajax.post('package_quiqqer_translator_ajax_create', oncomplete, {
                'package' : 'quiqqer/translator',
                Translator: Translator
            });
        }
    };
});
