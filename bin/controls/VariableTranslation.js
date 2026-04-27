/**
 * Displays one translation variable
 */
define('package/quiqqer/translator/bin/controls/VariableTranslation', [

    'qui/QUI',
    'qui/controls/Control',
    'Ajax',
    'Locale',
    'utils/Panels',
    'package/quiqqer/translator/bin/Panel',
    'package/quiqqer/translator/bin/classes/Translator',

    'css!package/quiqqer/translator/bin/controls/VariableTranslation.css'

], function (QUI, QUIControl, QUIAjax, QUILocale, PanelUtils,
             TranslatorPanel, Translator) {
    "use strict";

    var lg        = 'quiqqer/translator',
        Translate = new Translator();

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/translator/bin/controls/VariableTranslation',

        Binds: [
            'edit',
            '$onCreate',
            '$onInject'
        ],

        options: {
            'group' : '',
            'var'   : '',
            size    : 1,
            editable: false
        },

        initialize: function (options) {
            this.parent(options);

            this.addEvents({
                onCreate: this.$onCreate,
                onInject: this.$onInject
            });
        },

        /**
         * event : on Create
         */
        create: function () {
            var Elm = this.parent();

            Elm.addClass('quiqqer-translator-variabletranslation');
            Elm.addClass('smooth');

            Elm.addEvent('click', function () {

                if (Elm.get('data-create')) {
                    this.createVar();
                    return;
                }

                this.edit();

            }.bind(this));

            new Element('span', {
                'class': 'fa fa-spinner fa-spin'
            }).inject(Elm);

            return Elm;
        },

        /**
         * Refresh the display
         */
        refresh: function () {
            var self = this,
                Elm  = this.getElm();

            Elm.set('data-create', null);

            return new Promise(function (resolve) {

                Elm.set('html', '');

                new Element('span', {
                    'class': 'fa fa-spinner fa-spin'
                }).inject(Elm);


                QUIAjax.get([
                    'ajax_system_getAvailableLanguages',
                    'package_quiqqer_translator_ajax_getVarData'
                ], function (languages, data) {

                    var i, len, lang, text;

                    var Container = new Element('div'),
                        path      = URL_BIN_DIR + '16x16/flags/';


                    if (typeOf(data) === 'array' && !data.length) {

                        new Element('span', {
                            'class': 'quiqqer-translator-variabletranslation-entry',
                            html   : QUILocale.get(lg, 'control.variabletranslation.button.create'),
                            title  : QUILocale.get(lg, 'control.variabletranslation.button.create.title')
                        }).inject(Container);

                        Elm.set({
                            'data-create': 1
                        });

                    } else if (self.getAttribute('size') == 1) {

                        lang = QUILocale.getCurrent();
                        text = data[lang] || '--';

                        new Element('span', {
                            'class': 'quiqqer-translator-variabletranslation-entry',
                            html   : '<img src="' + path + lang + '.png" />' +
                            text
                        }).inject(Container);

                    } else {

                        for (i = 0, len = languages.length; i < len; i++) {
                            lang = languages[i];
                            text = data[lang] || '--';

                            new Element('span', {
                                'class': 'quiqqer-translator-variabletranslation-entry',
                                html   : '<img src="' + path + lang + '.png" />' +
                                text
                            }).inject(Container);
                        }
                    }

                    Container.inject(
                        Elm.set('html', '')
                    );

                    resolve();
                }, {
                    'package': 'quiqqer/translator',
                    'group'  : this.getAttribute('group'),
                    'var'    : this.getAttribute('var')
                });

            }.bind(this));
        },

        /**
         * event on inject
         */
        $onInject: function () {
            this.refresh();
        },

        /**
         * Create the variable and open the edit
         *
         * @return {Promise}
         */
        createVar: function () {
            var self    = this,
                group   = this.getAttribute('group'),
                varName = this.getAttribute('var');

            return new Promise(function (reslove, reject) {
                Translate.add(group, varName).then(function () {
                    return self.refresh();

                }).then(function () {
                    self.edit();

                }).then(reslove, reject);
            });
        },

        /**
         * Opens the translator with the variable
         */
        edit: function () {
            var panels = QUI.Controls.getByType(
                'package/quiqqer/translator/bin/Panel'
            );

            if (!panels.length) {

                PanelUtils.openPanelInTasks(
                    new TranslatorPanel({
                        group : this.getAttribute('group'),
                        search: {
                            search: this.getAttribute('var')
                        }
                    })
                );

                return;
            }

            panels[0].setAttribute('group', this.getAttribute('group'));
            panels[0].setAttribute('search', {
                search: this.getAttribute('var')
            });

            PanelUtils.execPanelOpen(panels[0]);

            panels[0].refresh();
        }
    });
});
