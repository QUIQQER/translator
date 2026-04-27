/**
 * Translator panel
 */
define('package/quiqqer/translator/bin/Panel', [

    'qui/QUI',
    'qui/controls/desktop/Panel',
    'qui/controls/buttons/Button',
    'qui/controls/buttons/Separator',
    'qui/controls/buttons/Select',
    'qui/controls/windows/Confirm',
    'qui/utils/Elements',
    'Ajax',
    'Locale',
    'Editors',
    'Mustache',
    'controls/grid/Grid',
    'controls/upload/Form',

    'text!package/quiqqer/translator/bin/Panel.addVariable.html',
    'css!package/quiqqer/translator/bin/Panel.css'

], function() {
    'use strict';

    var QUI = arguments[0],
        QUIPanel = arguments[1],
        QUIButton = arguments[2],
        QUIButtonSeparator = arguments[3],
        QUISelect = arguments[4],
        QUIConfirm = arguments[5],
        QUIElementUtils = arguments[6],

        Ajax = arguments[7],
        Locale = arguments[8],
        Editors = arguments[9],
        Mustache = arguments[10],
        Grid = arguments[11],
        UploadForm = arguments[12],
        templateAdd = arguments[13];


    return new Class({

        Extends: QUIPanel,
        Type: 'package/quiqqer/translator/bin/Panel',

        Binds: [
            'exportGroup',
            'search',
            'addVariable',
            'deleteVariables',
            'addGroup',
            'publish',
            'importTranslation',
            '$onCreate',
            '$onResize',
            '$onRefresh',
            '$loadSubGroups',
            '$loadGrid',
            '$gridClick',
            '$gridDblClick',
            '$gridBlur',
            '$onEditComplete',
            '$searchTemplate',
            '$attentionBox',
            'addTranslationGroup'
        ],

        options: {
            'field': '',
            'order': '',
            'limit': 500,
            'page': 1,
            'search': false,
            'group': false,
            'var': false
        },

        initialize: function(options) {
            // defaults
            this.setAttribute(
                'title',
                Locale.get('quiqqer/translator', 'panel.title')
            );

            this.setAttribute(
                'icon',
                URL_BIN_DIR + '16x16/flags/default.png'
            );

            this.parent(options);

            this.addEvents({
                onCreate: this.$onCreate,
                onResize: this.$onResize,
                onRefresh: this.$onRefresh
            });

            this.$Container = null;
            this.$Grid = null;
            this.$Editor = null;
            this.$groups = {};
            this.$groupcount = 0;
            this.$langs = [];

            this.$EditorHeader = null;
            this.$devMessageShowed = false;
        },

        /**
         * Return the actually grid
         *
         * @return {null|Object} null | controls/grid/Grid
         */
        getGrid: function() {
            return this.$Grid;
        },

        /**
         * Internal creation
         */
        $onCreate: function() {
            this.Loader.show();

            this.$Container = new Element('div', {
                'class': 'qui-translater'
            }).inject(this.getBody());

            this.$loadButtons();
            this.$loadGroups.delay(500, this);
        },

        /**
         * event: resize
         */
        $onResize: function() {
            if (!this.getGrid()) {
                return;
            }

            var Body = this.getBody(),
                Grid = this.getGrid();

            var bodyHeight = Body.getSize().y,
                height = bodyHeight;


            if (this.getAttribute('search')) {
                height = height - 110;
            } else {
                height = height - 40;
            }

            if (this.$Editor) {
                height = 300;

                this.$Editor.getElm().setStyles({
                    height: bodyHeight - 410
                });

                this.$Editor.setHeight(bodyHeight - 410);
            }

            Grid.setHeight(height);
            Grid.setWidth(Body.getSize().x - 40);
        },

        /**
         * Refresh the grid and the Panel
         */
        $onRefresh: function() {
            if (!this.getGrid()) {
                return;
            }

            var self = this;

            this.Loader.show();

            Ajax.get('package_quiqqer_translator_ajax_translations', function(result) {
                self.getGrid().setData(result.data);
                self.$gridBlur();
                self.$attentionBox();

                self.Loader.hide();
            }, {
                'package': 'quiqqer/translator',
                groups: this.getTranslationGroup(),
                params: JSON.encode({
                    field: this.getAttribute('field'),
                    order: this.getAttribute('order'),
                    limit: this.getAttribute('limit'),
                    page: this.getAttribute('page')
                }),
                search: JSON.encode(this.getAttribute('search'))
            });
        },

        /**
         * Return the selected translation group
         *
         * @return {String}
         */
        getTranslationGroup: function() {
            var ButtonBar = this.getButtonBar(),

                Sel1 = ButtonBar.getChildren('translater/group/begin'),
                Sel2 = ButtonBar.getChildren('translater/group/end');

            return Sel1.getValue() + '/' + Sel2.getValue();
        },

        /**
         * Export the selected group and opens a download dialog
         */
        exportGroup: function() {
            var group = this.getTranslationGroup(),
                devMode = (QUIQQER_CONFIG.globals.development).toInt(),

                content = '<div class="qui-translator-export-group">' +
                    '<h3>' + Locale.get('quiqqer/translator', 'export.window.group') + '</h3>' +
                    '<input id="qui-translator-export-group-current" type="radio" name="export_group" value="' + group + '" checked="checked"/>' +
                    '<label for="qui-translator-export-group-current">' +
                    Locale.get('quiqqer/translator', 'export.window.group.current.label', {group: group}) +
                    '</label>' +
                    '<input id="qui-translator-export-group-all" type="radio" name="export_group" value="all"/>' +
                    '<label for="qui-translator-export-group-all">' +
                    Locale.get('quiqqer/translator', 'export.window.group.all.label', {count: this.$groupcount}) +
                    '</label>' +
                    '<input id="qui-translator-export-group-external" type="checkbox" name="export_external"/>' +
                    '<label for="qui-translator-export-group-external">' +
                    Locale.get('quiqqer/translator', 'export.window.group.external.label') +
                    '</label>' +
                    '</div>' +
                    '<div class="qui-translator-export-language">' +
                    '<h3>' + Locale.get('quiqqer/translator', 'export.window.language') + '</h3>' +
                    '</div>' +
                    '<div class="qui-translator-export-type">' +
                    '<h3>' + Locale.get('quiqqer/translator', 'export.window.type') + '</h3>' +
                    '<input id="qui-translator-export-type-original" type="radio" name="export_type" value="original" checked="checked"/>' +
                    '<label for="qui-translator-export-type-original">' +
                    Locale.get('quiqqer/translator', 'export.window.type.original.label') +
                    '</label>' +
                    '<input id="qui-translator-export-type-edit" type="radio" name="export_type" value="edit"/>' +
                    '<label for="qui-translator-export-type-edit">' +
                    Locale.get('quiqqer/translator', 'export.window.type.edit.label') +
                    '</label>' +
                    '<input id="qui-translator-export-type-edit-overwrite" type="checkbox" name="export_type_overwrite" checked="checked"/>' +
                    '<label for="qui-translator-export-type-edit-overwrite">' +
                    Locale.get('quiqqer/translator', 'export.window.type.edit.overwrite.label') +
                    '</label>' +
                    '</div>';

            var ConfirmWindow = new QUIConfirm({
                title: Locale.get('quiqqer/translator', 'export.window.title'),
                icon: 'fa fa-download',
                texticon: false,

                events: {
                    onSubmit: function(Win) {
                        var i, len;
                        var Body = Win.getContent(),
                            grp = Body.getElement('#qui-translator-export-group-current'),
                            langs = Body.getElements('.qui-translator-export-language input'),
                            type = Body.getElement('#qui-translator-export-type-original'),
                            overwrite = Body.getElement('#qui-translator-export-type-edit-overwrite'),
                            external = Body.getElement('#qui-translator-export-group-external'),
                            groupTmp = 'all',
                            langsTmp = [],
                            typeTmp = 'original';

                        // Gruppe
                        if (grp.checked) {
                            groupTmp = group;
                        }

                        // Sprachen
                        for (i = 0, len = langs.length; i < len; i++) {
                            if (!langs[i].checked) {
                                continue;
                            }

                            langsTmp.push(langs[i].value);
                        }

                        // Typ
                        if (!type.checked) {
                            if (!overwrite.checked) {
                                typeTmp = 'edit';
                            } else {
                                typeTmp = 'edit_overwrite';
                            }
                        }

                        require(['DownloadManager'], function(DownloadManager) {
                            DownloadManager.download('package_quiqqer_translator_ajax_export', {
                                'package': 'quiqqer/translator',
                                group: groupTmp,
                                langs: JSON.encode(langsTmp),
                                type: typeTmp,
                                external: external.checked ? 1 : 0
                            });
                        });
                    }
                }
            });

            ConfirmWindow.create();
            ConfirmWindow.setContent(content);

            // Sprachen reinladen
            var l,
                Langs = ConfirmWindow.getContent().getElement('.qui-translator-export-language'),
                langs = this.$langs;

            for (var i = 0, len = langs.length; i < len; i++) {
                l = langs[i];

                new Element('input', {
                    type: 'checkbox',
                    name: 'export_language',
                    value: l,
                    id: 'qui-translator-export-lang-' + l,
                    checked: 'checked'
                }).inject(Langs);

                new Element('label', {
                    'for': 'qui-translator-export-lang-' + l,
                    html: l
                }).inject(Langs);
            }

            // Edit-Option
            var Content = ConfirmWindow.getContent(),
                OrigOption = Content.getElement('#qui-translator-export-type-original'),
                OrigLabel = Content.getElement('label[for="qui-translator-export-type-original"]'),
                EditOption = Content.getElement('#qui-translator-export-type-edit'),
                EditLabel = Content.getElement('label[for="qui-translator-export-type-edit"]'),
                Overwrite = Content.getElement('#qui-translator-export-type-edit-overwrite'),
                OverwriteLabel = Content.getElement('label[for="qui-translator-export-type-edit-overwrite"]');

            if (!devMode) {
                OrigOption.setStyle('display', 'none');
                OrigLabel.setStyle('display', 'none');

                EditOption.checked = true;
                EditOption.setStyle('display', 'none');
                EditLabel.setStyle('display', 'none');

                ConfirmWindow.open();
                return;
            }

            Overwrite.setStyle('display', 'none');
            OverwriteLabel.setStyle('display', 'none');

            EditOption.addEvent('click', function() {
                Overwrite.setStyle('display', '');
                OverwriteLabel.setStyle('display', '');
            });

            OrigOption.addEvent('click', function() {
                Overwrite.setStyle('display', 'none');
                OverwriteLabel.setStyle('display', 'none');
            });

            ConfirmWindow.open();
        },

        /**
         * Opens the delete dialog
         */
        deleteVariables: function() {
            var self = this,
                Grid = this.getGrid(),
                data = Grid.getSelectedData();

            require(['package/quiqqer/translator/bin/DeleteVariables'], function(del) {
                del(self, data);
            });
        },

        /**
         * Starts the publishing process
         */
        publish: function() {
            var self = this;

            this.getButtonBar().getChildren('publish').setAttribute('textimage', 'fa fa-spinner fa-spin');

            require(['package/quiqqer/translator/bin/Publish'], function(Publisher) {
                Publisher.publish(self, function() {
                    self.getButtonBar().getChildren('publish').setAttribute('textimage', 'fa fa-reply');
                });
            });
        },

        /**
         * create the grid
         */
        $loadGrid: function() {
            var self = this;

            Ajax.get('package_quiqqer_translator_ajax_translations', function(translations) {
                var Body = self.getBody(),
                    cols = [],
                    langs = translations.langs,
                    dev = (QUIQQER_CONFIG.globals.development).toInt(),
                    height = Body.getSize().y - 40,
                    width = Body.getSize().x - 40;

                cols.push({
                    header: Locale.get('quiqqer/system', 'id'),
                    dataIndex: 'id',
                    dataType: 'number',
                    width: 50
                });

                cols.push({
                    header: Locale.get('quiqqer/translator', 'grid.title.variable'),
                    dataIndex: 'var',
                    dataType: 'string',
                    width: 150,
                    editable: dev === 1
                });

                if (self.getAttribute('search')) {
                    cols.push({
                        header: Locale.get('quiqqer/translator', 'grid.title.group'),
                        dataIndex: 'groups',
                        dataType: 'string',
                        width: 150
                    });

                    cols.push({
                        header: Locale.get('quiqqer/translator', 'grid.title.origin_package'),
                        dataIndex: 'package',
                        dataType: 'string',
                        width: 150
                    });

                    self.$attentionBox();
                }

                // Sprachen
                for (var i = 0, len = langs.length; i < len; i++) {
                    cols.push({
                        header: langs[i],
                        dataIndex: langs[i],
                        dataType: 'code',
                        width: 300,
                        editable: true
                    });

                    if (dev === 1) {
                        cols.push({
                            header: langs[i] + '_edit',
                            dataIndex: langs[i] + '_edit',
                            dataType: 'code',
                            width: 300,
                            editable: true
                        });
                    }
                }

                cols.push({
                    header: Locale.get('quiqqer/translator', 'grid.title.type'),
                    dataIndex: 'datatype',
                    dataType: 'string',
                    width: 50,
                    editable: true
                });

                cols.push({
                    header: Locale.get('quiqqer/translator', 'grid.title.html'),
                    dataIndex: 'html',
                    dataType: 'bool',
                    width: 50,
                    editable: true
                });

                if (self.$Grid) {
                    self.$Grid.destroy();
                }

                self.$Grid = new Grid(self.$Container, {
                    columnModel: cols,
                    pagination: true,
                    filterInput: true,
                    buttons: [
                        {
                            name: 'add',
                            text: Locale.get('quiqqer/translator', 'btn.add.var.text'),
                            textimage: 'fa fa-plus',
                            events: {
                                onClick: self.addVariable
                            }
                        }, {
                            name: 'del',
                            text: Locale.get('quiqqer/translator', 'btn.del.var.text'),
                            textimage: 'fa fa-trash',
                            events: {
                                onMousedown: self.deleteVariables
                            }
                        }
                    ],

                    editable: false,
                    editondblclick: false,

                    perPage: self.getAttribute('limit'),
                    page: self.getAttribute('page'),
                    sortOn: self.getAttribute('field'),
                    width: width,
                    height: height,
                    onrefresh: function(me) {
                        var options = me.options;

                        self.setAttribute('field', options.sortOn);
                        self.setAttribute('order', options.sortBy);
                        self.setAttribute('limit', options.perPage);
                        self.setAttribute('page', options.page);

                        self.refresh();
                    },

                    alternaterows: true,
                    resizeColumns: true,
                    selectable: true,
                    multipleSelection: true,
                    resizeHeaderOnly: true
                });

                // Events
                self.$Grid.addEvents({
                    onClick: self.$gridClick,
                    onDblClick: self.$gridDblClick,
                    onBlur: self.$gridBlur
                });

                self.$Grid.setData(translations.data);
                self.$gridBlur();
                self.resize();
                self.Loader.hide();


                // dev info
                if (dev && self.$devMessageShowed === false) {
                    self.$devMessageShowed = true;

                    QUI.getMessageHandler(function(MessageHandler) {
                        MessageHandler.addInformation(
                            'QUIQQER ist im Entwicklungsmodus, ' +
                            'daher werden im Übersetzer die lang_edit Spalten angezeigt<br />' +
                            'Mehr Informationen im quiqqer/translator Wiki unter: ' +
                            '<a href="https://dev.quiqqer.com/quiqqer/package-translator/wikis/home" target="_blank">' +
                            'https://dev.quiqqer.com/quiqqer/package-translator/wikis/home' +
                            '</a>'
                        );
                    });
                }

                self.$langs = langs;

            }, {
                'package': 'quiqqer/translator',
                groups: this.getTranslationGroup(),
                params: JSON.encode({
                    field: this.getAttribute('field'),
                    order: this.getAttribute('order'),
                    limit: this.getAttribute('limit'),
                    page: this.getAttribute('page')
                }),
                search: JSON.encode(this.getAttribute('search'))
            });
        },

        /**
         * Create the buttons
         */
        $loadButtons: function() {
            var self = this;

            this.addButton({
                name: 'search',
                title: Locale.get('quiqqer/translator', 'btn.search.title'),
                alt: Locale.get('quiqqer/translator', 'btn.search.alt'),
                icon: 'fa fa-search',
                events: {
                    onClick: this.search
                }
            });

            this.addButton(new QUIButtonSeparator());

            this.addButton(
                new QUISelect({
                    name: 'translater/group/begin',
                    searchable: true,
                    styles: {
                        width: '17%'
                    },
                    events: {
                        onChange: function(value) {
                            var ButtonBar = self.getButtonBar(),
                                ImportBtn = ButtonBar.getChildren('import'),
                                ExportBtn = ButtonBar.getChildren('export'),
                                Grid = self.getGrid();

                            ImportBtn.disable();
                            ExportBtn.disable();

                            if (Grid) {
                                var i, len;
                                var gridBtns = Grid.getButtons();

                                for (i = 0, len = gridBtns.length; i < len; i++) {
                                    gridBtns[i].disable();
                                }
                            }

                            self.$loadSubGroups(value);
                        }
                    }
                })
            );

            this.addButton(
                new QUISelect({
                    name: 'translater/group/end',
                    searchable: true,
                    styles: {
                        width: '17%'
                    },
                    events: {
                        onChange: function() {
                            var ButtonBar = self.getButtonBar(),
                                ImportBtn = ButtonBar.getChildren('import'),
                                ExportBtn = ButtonBar.getChildren('export'),
                                Grid = self.getGrid();

                            ImportBtn.enable();
                            ExportBtn.enable();

                            if (Grid) {
                                var i, len;
                                var gridBtns = Grid.getButtons();

                                for (i = 0, len = gridBtns.length; i < len; i++) {
                                    gridBtns[i].enable();
                                }
                            }

                            // grid sheet to 1
                            self.setAttribute('page', 1);
                            self.$loadGrid();
                        }
                    }
                })
            );

            this.addButton(new QUIButtonSeparator());

            this.addButton({
                name: 'import',
                text: Locale.get('quiqqer/translator', 'btn.import.text'),
                textimage: 'fa fa-upload',
                disabled: false,
                events: {
                    onClick: this.importTranslation
                }
            });

            this.addButton({
                name: 'export',
                text: Locale.get('quiqqer/translator', 'btn.export.text'),
                textimage: 'fa fa-download',
                disabled: false,
                events: {
                    onClick: this.exportGroup
                }
            });

            this.addButton(new QUIButtonSeparator());

            this.addButton({
                name: 'publish',
                text: Locale.get('quiqqer/translator', 'btn.publish.text'),
                textimage: 'fa fa-reply',
                events: {
                    onClick: this.publish
                }
            });

        },

        /**
         * Load the groups in the DropDown
         */
        $loadGroups: function() {
            var self = this;

            Ajax.get('package_quiqqer_translator_ajax_groups', function(result) {
                var i, g, len, group;

                var ButtonBar = self.getButtonBar(),
                    Sel1 = ButtonBar.getChildren('translater/group/begin'),
                    Sel2 = ButtonBar.getChildren('translater/group/end'),
                    groups = {};

                var g1 = Sel1.getValue();
                var g2 = Sel2.getValue();

                // clear first
                Sel1.clear();
                Sel2.clear();

                for (i = 0, len = result.length; i < len; i++) {
                    group = result[i].split('/');

                    if (typeof groups[group[0]] === 'undefined') {
                        groups[group[0]] = [];
                    }

                    if (typeof group[1] !== 'undefined') {
                        groups[group[0]].push(group[1]);
                    }
                }

                self.$groups = groups;
                self.$groupcount = result.length;

                for (g in groups) {
                    if (groups.hasOwnProperty(g)) {
                        Sel1.appendChild(g, g, URL_BIN_DIR + '16x16/flags/default.png');
                    }
                }

                if (g1 && g2) {
                    Sel1.setValue(g1);

                    (function() {
                        this.setValue(g2);
                        this.close();
                    }.delay(100, Sel2));

                    return;
                }

                if (self.getAttribute('group')) {
                    var groupValue = self.getAttribute('group').split('/');

                    Sel1.setValue(groupValue[0]);

                    (function() {
                        this.setValue(groupValue[1]);
                        this.close();
                    }.delay(100, Sel2));

                    return;
                }

                // load projects if exists
                var hasProjects = Sel1.getChildren().filter(function(Child) {
                    return Child.getAttribute('value') === 'project';
                }).length;

                if (hasProjects) {
                    Sel1.setValue('project');
                } else {
                    if (Sel1.firstChild()) {
                        Sel1.setValue(
                            Sel1.firstChild().getAttribute('value')
                        );
                    }
                }

                if (Sel2.firstChild()) {
                    (function() {
                        this.setValue(
                            this.firstChild().getAttribute('value')
                        );

                        this.close();

                    }.delay(100, Sel2));
                }

            }, {
                'package': 'quiqqer/translator'
            });
        },

        /**
         * Load the sub groups at the second select dropdown
         */
        $loadSubGroups: function(value) {
            var ButtonBar = this.getButtonBar(),
                Sel2 = ButtonBar.getChildren('translater/group/end'),
                groups = {};

            Sel2.clear();

            if (typeof this.$groups[value] !== 'undefined') {
                groups = this.$groups[value];
            }

            for (var i = 0, len = groups.length; i < len; i++) {
                Sel2.appendChild(
                    groups[i],
                    groups[i],
                    URL_BIN_DIR + '16x16/flags/default.png'
                );
            }

            if (!groups.length) {
                Sel2.disable();
            } else {
                Sel2.enable();

                (function() {
                    if (Sel2.getValue() === '') {
                        Sel2.open();
                    }
                }).delay(200);
            }
        },

        /**
         * Create a attention box that the search is on
         */
        $attentionBox: function() {
            if (this.getAttribute('search') === false) {
                return;
            }

            if (this.getBody().getElement('.message-attention')) {
                return;
            }

            var Bar = this.getButtonBar(),
                self = this;

            Bar.getChildren('translater/group/begin').disable();
            Bar.getChildren('translater/group/end').disable();
            Bar.getChildren('export').disable();

            var onClick = function() {
                self.Loader.show();

                self.setAttribute('search', false);
                self.setAttribute('page', 1);

                var Bar = self.getButtonBar();

                Bar.getChildren('translater/group/begin').enable();
                Bar.getChildren('translater/group/end').enable();
                Bar.getChildren('export').enable();

                self.$loadGrid();
                self.resize();
            };

            require(['qui/controls/messages/Attention'], function(Attention) {
                new Attention({
                    message: Locale.get('quiqqer/translator', 'search.params.active'),
                    events: {
                        onClick: function(Message) {
                            Message.destroy();
                            onClick();
                        },

                        onDestroy: onClick
                    },
                    styles: {
                        margin: '0 0 20px',
                        'border-width': 1,
                        cursor: 'pointer'
                    }
                }).inject(self.getBody(), 'top');
            });
        },

        /**
         * Grid methods
         */

        /**
         * event: on grid click
         *
         * @param {Object} event - Grid Event
         */
        $gridClick: function(event) {
            var len = event.target.selected.length,
                Grid = this.getGrid(),
                Delete = Grid.getAttribute('buttons').del;

            if (len === 0) {
                Delete.disable();
                return;
            }

            Delete.enable();
            event.evt.stop();
        },

        /**
         * dblclick on the grid -> edit entry
         *
         * @param {Object} data - grid selected data
         */
        $gridDblClick: function(data) {
            var self = this,
                Cell = data.cell,
                row = data.row,
                Grid = this.getGrid(),
                Content = this.getContent(),

                gridData = data.target.$data,
                columnModel = data.target.$columnModel,
                localeData = gridData[row];

            var index = QUIElementUtils.getChildIndex(Cell);

            if (index === 0) {
                return;
            }

            // In normal mode only language content columns may be edited.
            var Column = columnModel[index],
                dev = (QUIQQER_CONFIG.globals.development).toInt();

            if (!dev && !this.$langs.contains(Column.dataIndex)) {
                return;
            }


            Grid.setHeight(300);

            var cellContent = Cell.get('text').trim();

            if (cellContent === '&nbsp;') {
                cellContent = '';
            }

            if (this.$Editor) {
                this.$gridDblClickHeaderCreate(localeData, Column, row);
                this.$Editor.setContent(cellContent);

                if ((localeData.html).toInt() === 1) {
                    if (typeof this.$Editor.hideSourceCode === 'function') {
                        this.$Editor.hideSourceCode();
                    } else {
                        this.$Editor.switchToWYSIWYG();
                    }

                    this.$Editor.showToolbar();
                } else {
                    if (typeof this.$Editor.showSourceCode === 'function') {
                        this.$Editor.showSourceCode();
                    } else {
                        this.$Editor.switchToSource();
                    }

                    this.$Editor.hideToolbar();
                }

                this.$Editor.focus();
                return;
            }

            this.Loader.show();

            Editors.getEditor(null, function(Editor) {
                self.$Editor = Editor;

                var height = Content.getSize().y - 410;

                if (height < 250) {
                    height = 250;
                }

                self.$EditorHeader = new Element('div', {
                    'class': 'qui-translater-editable-header'
                }).inject(Content);

                self.$gridDblClickHeaderCreate(localeData, Column, row);

                var EditorContainer = new Element('div', {
                    'class': 'qui-translater-editable',
                    styles: {
                        height: height
                    }
                }).inject(Content);

                self.$EditorContainer = EditorContainer;
                self.$Editor.setAttribute('showLoader', false);

                self.$Editor.addEvent('onLoaded', function() {
                    if ((localeData.html).toInt() === 1) {
                        if (typeof self.$Editor.hideSourceCode === 'function') {
                            self.$Editor.hideSourceCode();
                        } else {
                            self.$Editor.switchToWYSIWYG();
                        }

                        self.$Editor.showToolbar();
                    } else {
                        if (typeof self.$Editor.showSourceCode === 'function') {
                            self.$Editor.showSourceCode();
                        } else {
                            self.$Editor.switchToSource();
                        }

                        self.$Editor.hideToolbar();
                    }

                    self.$Editor.setHeight(height);
                    self.Loader.hide();
                });

                self.$Editor.inject(EditorContainer);
                self.$Editor.setContent(cellContent);
            });
        },

        /**
         * Jelper fpr edit header creation
         *
         * @param {Object} Data - row data
         * @param {Object} Column - Grid column
         * @param {Number} row . Grid row
         */
        $gridDblClickHeaderCreate: function(Data, Column, row) {
            var self = this;

            this.$EditorHeader.set(
                'html',

                '<div class="qui-translater-editable-header-text">' +
                'Bearbeiten von: <b>' + Data.groups + ' - ' + Data.var + ' (' + Column.header + ')</b>' +
                '</div>'
            );

            new QUIButton({
                textimage: 'fa fa-save',
                text: Locale.get('quiqqer/translator', 'edit.btn.save'),
                styles: {
                    'float': 'right'
                },
                events: {
                    onClick: function() {
                        self.Loader.show();

                        Data[Column.dataIndex] = self.$Editor.getContent();

                        // refresh grid
                        if (typeof row !== 'undefined') {
                            self.getGrid().setDataByRow(row, Data);
                        }

                        self.$saveData(Data, function() {
                            self.Loader.hide();
                        });
                    }
                }
            }).inject(this.$EditorHeader);

            new QUIButton({
                textimage: 'fa fa-remove',
                text: Locale.get('quiqqer/translator', 'edit.btn.close'),
                styles: {
                    'float': 'right',
                    marginRight: 5
                },
                events: {
                    onClick: function() {
                        self.$EditorHeader.destroy();
                        self.$EditorContainer.destroy();
                        self.$Editor = null;
                        self.fireEvent('onResize');
                    }
                }
            }).inject(this.$EditorHeader);
        },

        /**
         * onblur on the grid
         */
        $gridBlur: function() {
            var Grid = this.getGrid();

            Grid.unselectAll();
            Grid.removeSections();

            Grid.getAttribute('buttons').del.disable();

            if (this.getAttribute('search')) {
                Grid.getAttribute('buttons').add.disable();
            }
        },

        /**
         * event: on grid edit complete
         *
         * @param {Object} data - row data
         * @param {Function} [callback] - optional, callback function
         */
        $saveData: function(data, callback) {
            Ajax.post('package_quiqqer_translator_ajax_update', function() {
                if (typeof callback !== 'undefined') {
                    callback();
                }
            }, {
                'package': 'quiqqer/translator',
                groups: data.groups,
                data: JSON.encode(data)
            });
        },

        /**
         * opens the importation sheet
         */
        importTranslation: function() {
            var self = this,
                devMode = (QUIQQER_CONFIG.globals.development).toInt(),
                Popup;

            var LogoUploadForm = new UploadForm({
                multiple: false,
                sendbutton: false,
                uploads: 1,
                styles: {
                    clear: 'both',
                    float: 'left',
                    margin: 0,
                    width: '100%',
                    marginTop: 25
                },
                events: {
                    onComplete: function() {
                        self.Loader.hide();
                        self.$Grid.refresh();
                        //Popup.close();
                    },

                    onAdd: function(Control, File) {
                        if (typeof FileReader === 'undefined') {
                            return;
                        }

                        new FileReader().readAsDataURL(File);
                    },

                    onError: function(Control, Error) {
                        QUI.getMessageHandler(function(MH) {
                            MH.addError(
                                Error.getAttribute('message'),
                                self.$ImgDrop
                            );
                        });
                    },

                    onSubmit: function(data, Control) {
                        self.Loader.show();

                        var Overwrite = Popup.getContent().getElement(
                            '#qui-translator-import-overwrite'
                        );

                        var checked = Overwrite ? Overwrite.checked : false;

                        Control.setParam('overwriteOriginal', checked ? 1 : 0);
                    }
                }
            });

            LogoUploadForm.setParam(
                'onfinish',
                'package_quiqqer_translator_ajax_import'
            );

            LogoUploadForm.setParam('package', 'quiqqer/translator');

            Popup = new QUIConfirm({
                icon: 'fa fa-upload',
                title: Locale.get('quiqqer/translator', 'import.window.title'),
                maxWidth: 600,
                maxHeight: 400,
                events: {
                    onOpen: function(Win) {
                        var Content = Win.getContent();
                        var content = '<div class="qui-translator-import-descripton">' +
                            Locale.get('quiqqer/translator', 'import.window.description') +
                            '</div>';

                        if (devMode) {
                            content += '<label for="qui-translator-import-overwrite">' +
                                '<input type="checkbox" id="qui-translator-import-overwrite"/>' +
                                Locale.get('quiqqer/translator', 'import.window.overwrite.label') +
                                '</label>';
                        }

                        Content.set('html', content);
                        LogoUploadForm.inject(Content);
                    },

                    onSubmit: function() {
                        LogoUploadForm.submit();
                    }
                }
            });

            Popup.open();
        },

        addVariable: function() {
            var self = this;

            var content = Mustache.render(templateAdd, {
                text: Locale.get('quiqqer/translator', 'add.window.text', {
                    group: this.getTranslationGroup()
                }),

                group: this.getTranslationGroup(),
                label_group: Locale.get('quiqqer/translator', 'add.window.group', {
                    group: this.getTranslationGroup()
                }),
                label_maingroup: Locale.get('quiqqer/translator', 'add.window.maingroup.label'),
                label_subgroup: Locale.get('quiqqer/translator', 'add.window.subgroup.label'),
                label_variable: Locale.get('quiqqer/translator', 'add.window.variable.label'),
                label_js: Locale.get('quiqqer/translator', 'add.window.js.label'),
                label_php: Locale.get('quiqqer/translator', 'add.window.php.label'),
                label_html: Locale.get('quiqqer/translator', 'add.window.html.label'),
                placeholder_maingroup: Locale.get('quiqqer/translator', 'add.window.maingroup.placeholder'),
                placeholder_subgroup: Locale.get('quiqqer/translator', 'add.window.subgroup.placeholder'),
                placeholder_variable: Locale.get('quiqqer/translator', 'add.window.variable.placeholder'),
                label_js_desc: Locale.get('quiqqer/translator', 'add.window.js.desc'),
                label_php_desc: Locale.get('quiqqer/translator', 'add.window.php.desc'),
                label_html_desc: Locale.get('quiqqer/translator', 'add.window.html.desc')
            });

            var ConfirmWindow = new QUIConfirm({
                title: Locale.get('quiqqer/translator', 'add.window.title', {
                    group: this.getTranslationGroup()
                }),
                icon: 'fa fa-plus',
                autoclose: false,
                maxHeight: 600,
                maxWidth: 800,
                texticon: false,

                cancel_button: {
                    text: Locale.get('quiqqer/system', 'cancel'),
                    textimage: 'fa fa-remove'
                },
                ok_button: {
                    text: Locale.get('quiqqer/system', 'ok'),
                    textimage: 'fa fa-check'
                },

                events: {
                    onSubmit: function(Win) {
                        if (GroupInput.value.trim() === '') {
                            GroupInput.focus();
                            return false;
                        }

                        if (SubGroupInput.value.trim() === '') {
                            SubGroupInput.focus();
                            return false;
                        }

                        if (VariableInput.value.trim() === '') {
                            VariableInput.focus();
                            return false;
                        }

                        var Content = Win.getContent(),
                            datatype = [];

                        if (Content.getElement('[name="qui-translator-add-variable-js"]').checked) {
                            datatype.push('js');
                        }

                        if (Content.getElement('[name="qui-translator-add-variable-php"]').checked) {
                            datatype.push('php');
                        }

                        if (!datatype.length) {
                            datatype = ['php', 'js'];
                        }

                        Win.Loader.show();
                        self.Loader.show();

                        Ajax.post('package_quiqqer_translator_ajax_add', function() {
                            Win.close();

                            self.$loadGroups();
                            self.$Grid.refresh();
                            self.Loader.hide();
                        }, {
                            'package': 'quiqqer/translator',
                            group: GroupInput.value.trim() + '/' + SubGroupInput.value.trim(),
                            'var': VariableInput.value.trim(),
                            datatype: datatype.join(','),
                            html: Content.getElement('[name="qui-translator-add-variable-html"]').checked ? 1 : 0
                        });
                    },

                    onOpen: function() {
                        VariableInput.focus();
                    }
                }
            });

            ConfirmWindow.create();
            ConfirmWindow.setContent(content);

            var Content = ConfirmWindow.getContent(),
                GroupInput = Content.getElement('input[name="qui-translator-add-variable-maingroup"]'),
                SubGroupInput = Content.getElement('input[name="qui-translator-add-variable-subgroup"]'),
                VariableInput = Content.getElement('input[name="qui-translator-add-variable-variable"]'),
                group = this.getTranslationGroup().split('/');

            if (typeof group[0] !== 'undefined') {
                GroupInput.value = group[0];
            }

            if (typeof group[1] !== 'undefined') {
                SubGroupInput.value = group[1];
            }

            var onEnter = function(event) {
                if (typeof event !== 'undefined' &&
                    event.code === 13) {
                    ConfirmWindow.submit();
                }
            };

            GroupInput.addEvent('keydown', onEnter);
            SubGroupInput.addEvent('keydown', onEnter);
            VariableInput.addEvent('keydown', onEnter);

            new QUIButton({
                icon: 'fa fa-edit',
                title: Locale.get('quiqqer/translator', 'add.window.edit.btn.text'),
                events: {
                    onClick: function() {
                        GroupInput.getParent('tr').setStyle('display', '');
                        SubGroupInput.getParent('tr').setStyle('display', '');

                        GroupInput.select();
                        GroupInput.focus();
                    }
                }
            }).inject(
                ConfirmWindow.getContent().getElement('.group-display .field-container-field')
            );

            ConfirmWindow.open();
        },

        /**
         * Opens the translater search
         */
        search: function() {
            this.Loader.show();

            var self = this,
                Sheet = this.createSheet();

            Sheet.addEvent('onOpen', function(Sheet) {
                Sheet.addButton(
                    new QUIButton({
                        text: Locale.get('quiqqer/translator', 'btn.search.sheet.text'),
                        textimage: 'fa fa-search',
                        events: {
                            onClick: function() {
                                self.Loader.show();
                                Sheet.getBody().getElement('form').fireEvent('submit');
                            }
                        }
                    })
                );

                Ajax.get('package_quiqqer_translator_ajax_template_search', self.$searchTemplate, {
                    'package': 'quiqqer/translator',
                    Sheet: Sheet
                });

                self.Loader.hide();
            });

            Sheet.show();
        },

        /**
         * set the search template into the sheet body
         *
         * @param {String} result - html template
         * @param {Object} Request - qui/classes/request/Ajax
         */
        $searchTemplate: function(result, Request) {
            var Form, elements;

            var Sheet = Request.getAttribute('Sheet'),
                Body = Sheet.getBody();

            Body.set('html', result);
            Body.setStyle('overflow', 'auto');

            Form = Body.getElement('form');
            elements = Form.elements;

            var enableDisableFields = function() {
                var emptyTranslations = elements.emptyTranslations;

                if (emptyTranslations.checked) {
                    Array.each(elements, function(Elm) {
                        if (Elm.name === 'emptyTranslations') {
                            return;
                        }

                        if (Elm.name === 'lang') {
                            return;
                        }

                        Elm.disabled = true;

                        if (Elm.type === 'checkbox') {
                            Elm.checked = false;
                        } else {
                            Elm.value = '';
                        }
                    });

                    elements.search.blur();
                    return;
                }

                Array.each(elements, function(Elm) {
                    if (Elm.name === 'emptyTranslations') {
                        return;
                    }

                    Elm.disabled = false;

                    if (Elm.type === 'checkbox') {
                        Elm.checked = true;
                    }

                    elements.search.focus();
                });
            };

            // set values
            var search = false,
                fields = false;

            if (this.getAttribute('search') && this.getAttribute('search').search) {
                search = this.getAttribute('search').search;
            }

            if (this.getAttribute('search') && this.getAttribute('search').fields) {
                fields = this.getAttribute('search').fields;
            }

            if (search) {
                elements.search.value = search;
            }

            elements.emptyTranslations.addEvent('change', enableDisableFields);

            if (fields) {
                for (var i = 0, len = fields.length; i < len; i++) {
                    if (elements[fields[i]]) {
                        elements[fields[i]].checked = true;
                    }

                    // language
                    if (fields[i].length === 2 &&
                        Form.getElement('[value="' + fields[i] + '"]')) {
                        Form.getElement('[value="' + fields[i] + '"]').checked = true;
                    }
                }
            } else {
                Form.getElements('[type="checkbox"]').set('checked', true);
            }

            if (this.getAttribute('search')) {
                elements.emptyTranslations.checked = this.getAttribute('search').emptyTranslations;
            } else {
                if (!this.getAttribute('search')) {
                    elements.emptyTranslations.checked = false;
                }
            }


            elements.search.focus();
            enableDisableFields();


            var self = this;

            Form.addEvents({
                /**
                 * @param {DOMEvent} [event]
                 */
                submit: function(event) {
                    if (typeof event !== 'undefined') {
                        event.stop();
                    }


                    var fields = [];

                    if (elements.groups.checked) {
                        fields.push('groups');
                    }

                    if (elements.var.checked) {
                        fields.push('var');
                    }

                    if (elements.datatype.checked) {
                        fields.push('datatype');
                    }

                    if (elements.datadefine.checked) {
                        fields.push('datadefine');
                    }

                    // languages
                    if (elements.lang) {
                        const languages = !elements.lang.length ? [elements.lang] : elements.lang;

                        for (let i = 0, len = languages.length; i < len; i++) {
                            if (languages[i].checked) {
                                fields.push(languages[i].value);
                            }
                        }
                    }

                    self.setAttribute('search', {
                        search: elements.search.value,
                        emptyTranslations: elements.emptyTranslations.checked,
                        fields: fields
                    });

                    Sheet.hide();

                    self.$loadGrid();
                    self.resize();
                }
            });
        }
    });
});
