/**
 * Translator add variable method
 */
define('package/quiqqer/translator/bin/AddVariable', [

    "package/quiqqer/translator/bin/Panel",
    "Locale",
    "Ajax",
    "qui/controls/windows/Prompt"

], function (Panel, Locale, Ajax, QUIPrompt) {
    "use strict";

    return function (Translator) {
        var group = Translator.getTranslationGroup();

        new QUIPrompt({
            name       : 'add_new_translation',
            icon       : 'fa fa-plus',
            titleicon  : 'fa fa-plus',
            width      : 560,
            height     : 200,
            group      : group,
            title      : Locale.get('quiqqer/translator', 'add.window.title'),
            information: Locale.get('quiqqer/translator', 'add.window.text'),
            // no autoclose
            check      : function () {
                return false;
            },

            cancel_button: {
                text     : Locale.get('quiqqer/translator', 'add.window.btn.close'),
                textimage: 'fa fa-remove'
            },

            ok_button: {
                text     : Locale.get('quiqqer/translator', 'add.window.btn.add'),
                textimage: 'fa fa-plus'
            },

            events: {
                onEnter: function (result, Win) {
                    if (result === '') {
                        return;
                    }

                    Ajax.post('package_quiqqer_translator_ajax_add', function () {
                        // nothing
                    }, {
                        'package': 'quiqqer/translator',
                        groups   : group,
                        'var'    : result
                    });

                    Win.setValue('');
                },

                onClose: function () {
                    Translator.refresh();
                }
            }
        }).open();
    };
});
