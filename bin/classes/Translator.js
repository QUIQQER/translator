/**
 * Translator
 */
define('package/quiqqer/translator/bin/classes/Translator', [

    'qui/QUI',
    'qui/classes/DOM',
    'Ajax',
    'Locale'

], function (QUI, QDOM, QUIAjax, QUILocale) {
    "use strict";

    return new Class({
        Extends: QDOM,
        Type   : 'package/quiqqer/translator/bin/classes/Translator',

        initialize: function (options) {
            this.parent(options);
        },

        /**
         * Return the available languages
         * Language which are in use
         *
         * @return {Promise}
         */
        getAvailableLanguages: function () {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('ajax_system_getAvailableLanguages', resolve, {
                    onError: reject
                });
            });
        },

        /**
         * refresh the translation in the locale
         *
         * @return {Promise}
         */
        refreshLocale: function () {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_translator_ajax_refreshLocale', function (data) {
                    var lang, group;

                    for (lang in data) {
                        if (!data.hasOwnProperty(lang)) {
                            continue;
                        }

                        for (group in data[lang]) {
                            if (!data[lang].hasOwnProperty(group)) {
                                continue;
                            }

                            QUILocale.set(lang, group, data[lang][group]);
                        }
                    }

                    resolve();
                }, {
                    'package': 'quiqqer/translator',
                    onError  : reject
                });
            });
        },

        /**
         * Add a translation variable
         *
         * @param {String} group
         * @param {String} varName
         * @param {String} [pkg]
         * @returns {Promise}
         */
        add: function (group, varName, pkg) {
            if (typeof pkg === 'undefined') {
                pkg = group;
            }

            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_translator_ajax_add', resolve, {
                    'package'  : 'quiqqer/translator',
                    'onError'  : reject,
                    'showError': false,
                    'group'    : group,
                    'var'      : varName,
                    'pkg'      : pkg
                });
            });
        },

        /**
         * Return the data of a variable
         *
         * @param group
         * @param varName
         * @param pkg
         * @return {Promise}
         */
        get: function (group, varName, pkg) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_translator_ajax_getVarData', resolve, {
                    'package': 'quiqqer/translator',
                    'onError': reject,
                    'group'  : group,
                    'var'    : varName,
                    'pkg'    : pkg
                });
            });
        },

        /**
         * Set a translation vor a translation variable
         *
         * @param {String} group
         * @param {String} varName
         * @param {Object} data - {'en' : 'English text', 'de' : 'German text', package : ''}
         * @param [showSuccessMessage]
         * @return {Promise}
         */
        setTranslation: function (group, varName, data, showSuccessMessage) {
            return new Promise(function (resolve, reject) {
                data.var = varName;

                if (typeof showSuccessMessage === 'undefined') {
                    showSuccessMessage = 1;
                }

                showSuccessMessage = showSuccessMessage ? 1 : 0;

                QUIAjax.post('package_quiqqer_translator_ajax_update', resolve, {
                    'package': 'quiqqer/translator',
                    onError  : reject,
                    groups   : group,
                    data     : JSON.encode(data),
                    
                    showSuccessMessage: showSuccessMessage
                });
            });
        },

        /**
         * Publish the translations
         *
         * @param [group]
         * @param [showSuccessMessage]
         * @returns {Promise}
         */
        publish: function (group, showSuccessMessage) {
            group = group || false;

            if (typeof showSuccessMessage === 'undefined') {
                showSuccessMessage = 1;
            }

            showSuccessMessage = showSuccessMessage ? 1 : 0;

            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_translator_ajax_create', resolve, {
                    'package'         : 'quiqqer/translator',
                    group             : group,
                    showSuccessMessage: showSuccessMessage,
                    onError           : reject
                });
            });
        }
    });
});
