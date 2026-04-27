/**
 * Displays the variable and the control can update the translation variable
 *
 * @event onChange
 * @event onSaveBegin [self]
 * @event onSave [self]
 * @event onSaveEnd [self]
 */
define('package/quiqqer/translator/bin/controls/UpdateContent', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'qui/controls/loader/Loader',
    'Ajax',
    'Locale',
    'Editors',
    'controls/lang/Select',
    'package/quiqqer/translator/bin/classes/Translator',

    'css!package/quiqqer/translator/bin/controls/UpdateContent.css'

], function (QUI, QUIControl, QUIButton, QUILoader, QUIAjax, QUILocale,
             Editors, LangSelect, Translator) {
    "use strict";

    var Translate = new Translator();

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/translator/bin/controls/UpdateContent',

        Binds: [
            '$onInject'
        ],

        options: {
            'group'  : false,
            'var'    : false,
            'package': false,
            datatype : 'php,js',
            html     : false,
            data     : {},

            createIfNotExists: false
        },

        initialize: function (options) {
            this.parent(options);

            this.$Elm    = null;
            this.$Input  = null;
            this.$Button = null;
            this.$Input  = null;
            this.$data   = null;

            this.$Editor     = null;
            this.$LangSelect = null;
            this.Loader      = new QUILoader();

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
            this.$Elm = this.parent();

            this.$Elm.set({
                'class': 'quiqqer-translator-updateContent',
                html   : '' +
                    '<div class="quiqqer-translator-updateContent-langselect"></div>' +
                    '<div class="quiqqer-translator-updateContent-content"></div>'
            });

            if (this.getAttribute('styles')) {
                this.$Elm.setStyles(this.getAttribute('styles'));
            }

            if (!this.$Input) {
                this.$Input = new Element('input', {
                    type : 'hidden',
                    value: this.getAttribute('value'),
                    name : this.getAttribute('name')
                });
            }

            this.Loader.inject(this.$Elm);

            this.$LangSelect = new LangSelect({
                'class': 'quiqqer-translator-updateContent-langselect-select',
                events : {
                    onChangeBegin: function (Control, lang) {
                        this.$unload(lang);
                    }.bind(this),
                    onChange     : function (Control, lang) {
                        this.$loadLangContent(lang);
                    }.bind(this)
                }
            }).inject(
                this.$Elm.getElement(
                    '.quiqqer-translator-updateContent-langselect'
                )
            );

            return this.$Elm;
        },

        /**
         * event : on inject
         *
         * @return {Promise}
         */
        $onInject: function () {
            var self = this;

            if (!this.$Elm.getParent('.field-container')) {
                this.$Elm.removeClass('field-container-field');
            }

            return Editors.getEditor().then(function (Editor) {
                Editor.addEvent('onLoaded', function () {
                    self.Loader.hide();
                    self.fireEvent('load', [self]);
                });

                Editor.inject(self.$Elm.getElement('.quiqqer-translator-updateContent-content'));
                Editor.setContent('');

                self.$Editor = Editor;
                self.$loadLangContent(self.$LangSelect.getValue());
            });
        },

        /**
         * Load the content from the current selected language
         *
         * @return {Promise}
         */
        $loadLangContent: function () {
            var lang = this.$LangSelect.getValue();

            if (!this.$Editor) {
                return Promise.resolve();
            }

            if (lang === '') {
                return Promise.resolve();
            }

            if (this.$data) {
                if (this.$data[lang + '_edit']) {
                    this.$Editor.setContent(this.$data[lang + '_edit']);
                } else if (this.$data[lang]) {
                    this.$Editor.setContent(this.$data[lang]);
                } else {
                    this.$Editor.setContent('');
                }

                return Promise.resolve();
            }

            var self = this;

            return new Promise(function (resolve) {
                QUIAjax.get('package_quiqqer_translator_ajax_getVarData', function (data) {
                    self.$data = data;

                    if (self.$data[lang + '_edit']) {
                        self.$Editor.setContent(self.$data[lang + '_edit']);
                    } else if (self.$data[lang]) {
                        self.$Editor.setContent(self.$data[lang]);
                    } else {
                        self.$Editor.setContent('');
                    }

                    resolve();
                }, {
                    'package': 'quiqqer/translator',
                    'group'  : self.getAttribute('group'),
                    'var'    : self.getAttribute('var'),
                    'pkg'    : self.getAttribute('package')
                });
            });
        },

        /**
         * event: on import
         */
        $onImport: function () {
            var self = this;

            this.$Input = this.getElm();
            this.$Elm   = new Element('div').wraps(this.$Input);

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

            this.create();


            this.$onInject().then(function () {
                self.$Input.inject(self.$Elm, 'after');
            });
        },

        /**
         * Create the translation
         *
         * @returns {Promise}
         */
        save: function () {
            this.fireEvent('saveBegin', [this]);
            this.$unload();

            var self = this,
                data = this.getData();

            data.package  = this.getAttribute('package');
            data.datatype = this.getAttribute('datatype');
            data.html     = this.getAttribute('html') ? 1 : 0;

            return Translate.setTranslation(
                self.getAttribute('group'),
                self.getAttribute('var'),
                data
            ).then(function () {
                return Translate.refreshLocale();

            }).then(function () {
                return Translate.publish(
                    self.getAttribute('group')
                );
            }, function (err) {
                if (err.getCode() === 404 &&
                    self.getAttribute('createIfNotExists')) {

                    return Translate.add(
                        self.getAttribute('group'),
                        self.getAttribute('var'),
                        self.getAttribute('package')
                    ).then(function () {
                        return Translate.refreshLocale();

                    }).then(function () {
                        return Translate.publish(
                            self.getAttribute('group')
                        );
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
            return this.getData();
        },

        /**
         * Return the translation data
         *
         * @return {String} - {en: '', de: ''}
         */
        getData: function () {
            return this.$data;
        },

        /**
         * unload the editor content to the data
         */
        $unload: function () {
            if (!this.$data) {
                return;
            }

            var content = this.$Editor.getContent(),
                lang    = this.$LangSelect.getValue(),
                dev     = parseInt(QUIQQER_CONFIG.globals.development);

            this.$data[lang] = content;

            if (dev) {
                this.$data[lang + '_edit'] = content;
            }
        }
    });
});
