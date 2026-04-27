/**
 * Translator delete variables method
 */
define('package/quiqqer/translator/bin/DeleteVariables', [

    "package/quiqqer/translator/bin/Panel",
    "Locale",
    "Ajax",
    "qui/controls/windows/Confirm"

], function (Panel, Locale, Ajax, QUIConfirm) {
    "use strict";

    return function (Translator, data) {
        var i, len;

        var message = '<ul style="margin-top: 10px">';

        for (i = 0, len = data.length; i < len; i++) {
            message = message + '<li>' + data[i].groups + ' ' + data[i]['var'] + '</li>';
        }

        message = message + '</ul>';

        new QUIConfirm({
            name       : 'del_sel_items',
            title      : Locale.get('quiqqer/translator', 'del.window.title'),
            maxWidth   : 600,
            maxHeight  : 400,
            text       : Locale.get('quiqqer/translator', 'del.window.text'),
            data       : data,
            texticon   : 'fa fa-trash',
            icon       : 'fa fa-trash',
            information: message + Locale.get('quiqqer/translator', 'del.window.text.information'),
            ok_button  : {
                text     : Locale.get('quiqqer/system', 'delete'),
                textimage: 'fa fa-trash'
            },
            events     : {
                onSubmit: function (Win) {
                    Win.Loader.show();

                    var list = [],
                        data = Win.getAttribute('data');

                    for (var i = 0, len = data.length; i < len; i++) {
                        list.push({
                            'var'   : data[i]['var'],
                            'groups': data[i].groups,
                            'id'    : data[i].id
                        });
                    }

                    Ajax.post('package_quiqqer_translator_ajax_delete', function () {
                        Translator.refresh();
                    }, {
                        'package': 'quiqqer/translator',
                        data     : JSON.encode(list)
                    });
                }
            }
        }).open();
    };
});
