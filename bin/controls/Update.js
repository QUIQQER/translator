/**
 * Displays the variable and the control can update the translation variable
 *
 * @event onChange
 * @event onSaveBegin [self]
 * @event onSave [self]
 * @event onSaveEnd [self]
 */
define('package/quiqqer/translator/bin/controls/Update', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'Ajax',
    'Locale',
    'package/quiqqer/translator/bin/classes/Translator',
    'package/quiqqer/translator/bin/controls/Create',

    'css!package/quiqqer/translator/bin/controls/Update.css'

], function (QUI, QUIControl, QUIButton, QUIAjax, QUILocale, Translator, Create) {
    "use strict";

    const Translate = new Translator();

    return new Class({

        Extends: Create,
        Type: 'package/quiqqer/translator/bin/controls/Update',

        Binds: [
            '$onInject'
        ],

        options: {
            'group': false,
            'var': false,
            'package': false,
            datatype: 'php,js',
            html: false,
            data: {},

            createIfNotExists: false
        },

        initialize: function (options) {
            this.parent(options);

            this.$Toggler = null;

            this.removeEvents('onInject');

            this.addEvents({
                onInject: this.$onInject,
                onImport: this.$onImport
            });
        },

        /**
         * Return the domnode element
         *
         * @returns {HTMLElement}
         */
        create: function () {
            const Elm = this.parent();

            Elm.set({
                'class': 'quiqqer-translator-update',
                html: ''
            });

            return Elm;
        },

        /**
         * event : on inject
         *
         * @return {Promise}
         */
        $onInject: function () {
            const self = this,
                Elm = this.getElm(),
                path = URL_BIN_DIR + '16x16/flags/';

            Elm.set('html', '');

            const flexField = this.$Elm.getParent().hasClass('field-container-field');

            if (flexField) {
                this.$Elm.addClass('field-container-field');
                this.$Elm.addClass('quiqqer-t-entry__minimize');
                this.$Elm.replaces(this.$Elm.getParent());
            }

            return new Promise(function (resolve) {
                QUIAjax.get([
                    'ajax_system_getAvailableLanguages',
                    'package_quiqqer_translator_ajax_getVarData'
                ], function (languages, data) {
                    let i, len, lang, Container;
                    const current = QUILocale.getCurrent();

                    // current language to the top
                    languages.sort(function (a, b) {
                        if (a === current) {
                            return -1;
                        }

                        if (b === current) {
                            return 1;
                        }

                        return 0;
                    });

                    for (i = 0, len = languages.length; i < len; i++) {
                        lang = languages[i];

                        Container = new Element('div', {
                            'class': 'quiqqer-translator-entry',
                            html: '<img src="' + path + lang + '.png" /><input type="text" name="' + lang + '" />'
                        }).inject(Elm);

                        if (i > 0) {
                            Container.setStyles({
                                display: 'none',
                                opacity: 0
                            });
                        }

                        if (lang in data && data[lang] !== '') {
                            Container.getElement('input').value = data[lang];
                        }

                        if (lang + '_edit' in data && data[lang + '_edit'] !== '' && data[lang + '_edit'] !== null) {
                            Container.getElement('input').value = data[lang + '_edit'];
                        }
                    }

                    if (!flexField) {
                        self.$Toggler = new QUIButton({
                            icon: 'fa fa-arrow-circle-o-right',
                            styles: {
                                position: 'absolute',
                                right: 0
                            },
                            events: {
                                onClick: self.toggle
                            }
                        }).inject(Elm);
                    } else {
                        self.$Toggler = new Element('span.field-container-item', {
                            html: '<span class="fa fa-arrow-circle-o-right"></span>',
                            styles: {
                                cursor: 'pointer',
                                lineHeight: 28,
                                textAlign: 'center',
                                width: 50
                            },
                            events: {
                                click: function (event) {
                                    event.stop();
                                    self.toggle();
                                }
                            }
                        }).inject(self.getElm(), 'after');
                    }

                    if (languages.length <= 1) {
                        self.$Toggler.destroy(); // needed because of css bug -> not last child
                    }

                    const Input = Elm.getElements('input');

                    Input.addEvent('change', function () {
                        self.setAttribute('data', self.getData());
                        self.fireEvent('change', [self]);
                    });

                    self.fireEvent('load', [self]);
                    resolve();
                }, {
                    'package': 'quiqqer/translator',
                    'group': self.getAttribute('group'),
                    'var': self.getAttribute('var'),
                    'pkg': self.getAttribute('package')
                });
            });
        },

        /**
         * event: on import
         */
        $onImport: function () {
            const self = this;

            this.$Input = this.getElm();
            this.$Elm = new Element('div').wraps(this.$Input);

            if (this.$Input.hasClass('field-container-field')) {
                this.$Elm.addClass('field-container-field');
                this.$Elm.addClass('quiqqer-t-entry__minimize');
            }

            // options
            if (this.$Input.get('data-qui-options-group')) {
                this.setAttribute('group', this.$Input.get('data-qui-options-group'));
            }

            if (this.$Input.get('data-qui-options-package')) {
                this.setAttribute('package', this.$Input.get('data-qui-options-package'));
            }

            if (this.$Input.get('data-qui-options-var')) {
                this.setAttribute('var', this.$Input.get('data-qui-options-var'));
            }

            if (this.$Input.get('data-qui-options-datatype')) {
                this.setAttribute('datatype', this.$Input.get('data-qui-options-datatype'));
            }

            if (!this.getAttribute('package')) {
                this.setAttribute('package', this.getAttribute('group'));
            }


            this.$onInject().then(function () {
                self.$Input.inject(self.$Elm, 'after');

                // flexbox tables
                if (!self.$Input.hasClass('field-container-field')) {
                    return;
                }

                self.$Toggler.getElm().setStyle('display', 'none');
                self.$Toggler.destroy();

                self.$Toggler = new Element('span.field-container-item', {
                    html: '<span class="fa fa-arrow-circle-o-right"></span>',
                    styles: {
                        cursor: 'pointer',
                        textAlign: 'center',
                        width: 50
                    },
                    events: {
                        click: function (event) {
                            event.stop();
                            self.toggle();
                        }
                    }
                }).inject(self.getElm(), 'after');
            });
        },

        /**
         * Create the translation
         *
         * @returns {Promise}
         */
        save: function () {
            this.fireEvent('saveBegin', [this]);

            const self = this,
                data = this.getData();

            data.package = this.getAttribute('package');
            data.datatype = this.getAttribute('datatype');
            data.html = this.getAttribute('html') ? 1 : 0;

            return Translate.setTranslation(
                self.getAttribute('group'),
                self.getAttribute('var'),
                data,
                false
            ).then(function () {
                return Translate.refreshLocale();
            }).then(function () {
                return Translate.publish(self.getAttribute('group'), false);
            }, function (err) {
                if (err.getCode() === 404 && self.getAttribute('createIfNotExists')) {
                    return Translate.add(
                        self.getAttribute('group'),
                        self.getAttribute('var'),
                        self.getAttribute('package')
                    ).then(function () {
                        return Translate.setTranslation(
                            self.getAttribute('group'),
                            self.getAttribute('var'),
                            data
                        );
                    }).then(function () {
                        return Translate.refreshLocale();
                    }).then(function () {
                        return Translate.publish(self.getAttribute('group'), false);
                    });
                }

                throw err;
            }).then(function () {
                self.fireEvent('save', [this]);
                self.fireEvent('saveEnd', [this]);
            });
        },

        /**
         * Return the current value for the current locale
         *
         * @return {String}
         */
        getValue: function () {
            const current = QUILocale.getCurrent();
            const Input = this.getElm().getElement('[name="' + current + '"]');

            return Input ? Input.value : '';
        }
    });
});
