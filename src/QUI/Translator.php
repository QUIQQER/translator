<?php

/**
 * This file contains QUI\Translator
 */

namespace QUI;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Query\QueryBuilder;
use DOMElement;
use QUI;
use QUI\Cache\Manager as CacheManager;
use QUI\Database\Exception;
use QUI\Utils\Doctrine as DoctrineUtils;
use QUI\Utils\StringHelper;
use QUI\Utils\System\File as QUIFile;
use QUI\Utils\Text\XML;

use function array_flip;
use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function implode;
use function ini_get;
use function is_dir;
use function is_null;
use function json_decode;
use function json_encode;
use function ltrim;
use function mb_strpos;
use function min;
use function preg_replace_callback;
use function set_time_limit;
use function str_replace;
use function strlen;
use function trim;
use function unlink;

/**
 * QUIQQER Translator
 *
 * Manage all translations, for the system and the plugins
 *
 * @author  www.pcsg.de (Henning Leutz)
 *
 * mysql fix for old dev version
 *
 * UPDATE translate
 * SET groups = REPLACE(groups, '\'', '') WHERE 1;
 * UPDATE translate
 * SET var = REPLACE(var, '\'', '') WHERE 1
 */
class Translator
{
    const ERROR_CODE_VAR_EXISTS = 601;

    /**
     * @var string
     */
    const EXPORT_DIR = 'translator_exports/';

    /**
     * @var string
     */
    protected static string $cacheName = 'translator';

    /**
     * @var array<string, int|false>|null
     */
    protected static ?array $localeModifyTimes = null;

    /**
     * @var list<string>|null
     */
    protected static ?array $availableLanguages = null;

    /**
     * @var string|null
     */
    protected static ?string $localePublishVersion = null;

    /**
     * Return the real table name
     *
     * @return String
     */
    public static function table(): string
    {
        return QUI::getDBTableName('translate');
    }

    protected static function createDatabaseException(DbalException $Exception): Exception
    {
        return new Exception($Exception->getMessage(), $Exception->getCode());
    }

    protected static function introspectTranslatorTable(): \Doctrine\DBAL\Schema\Table
    {
        $table = self::table();

        if ($table === '') {
            throw new QUI\Exception('Database table name is not available');
        }

        try {
            $SchemaManager = QUI::getSchemaManager();

            // @phpstan-ignore function.alreadyNarrowedType
            if (method_exists($SchemaManager, 'introspectTableByUnquotedName')) {
                return $SchemaManager->introspectTableByUnquotedName($table);
            }

            return $SchemaManager->introspectTable($table);
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected static function quoteDbalArrayKeys(array $data): array
    {
        $quoted = [];

        foreach ($data as $key => $value) {
            $quoted[DoctrineUtils::quoteIdentifier((string)$key)] = $value;
        }

        return $quoted;
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     *
     * @throws DbalException
     */
    protected static function bulkInsertDbal(string $table, array $columns, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $quotedColumns = array_map(
            static fn (string $column): string => DoctrineUtils::quoteIdentifier($column),
            $columns
        );
        $rowPlaceholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = [];
        $params = [];

        foreach ($rows as $row) {
            $placeholders[] = $rowPlaceholders;

            foreach ($columns as $column) {
                $params[] = $row[$column] ?? null;
            }
        }

        QUI::getDataBaseConnection()->executeStatement(
            'INSERT INTO ' . DoctrineUtils::quoteIdentifier($table)
            . ' (' . implode(', ', $quotedColumns) . ') VALUES '
            . implode(', ', $placeholders),
            $params
        );
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    protected static function fetchDbal(array $query): array
    {
        $QueryBuilder = QUI::getQueryBuilder();

        $select = $query['select'] ?? '*';

        if (isset($query['count'])) {
            $countField = (string)$query['count'];
            $select = 'COUNT(*) AS ' . DoctrineUtils::quoteIdentifier($countField);
        }

        if (is_array($select)) {
            $select = array_map(
                static fn ($field): string => DoctrineUtils::quoteIdentifier((string)$field),
                $select
            );
            $QueryBuilder->select(...$select);
        } else {
            $select = (string)$select;

            if ($select !== '*' && !str_contains($select, '(') && !str_contains($select, ' ')) {
                $select = DoctrineUtils::quoteIdentifier($select);
            }

            $QueryBuilder->select($select);
        }

        $QueryBuilder->from(DoctrineUtils::quoteIdentifier((string)$query['from']));

        self::applyDbalWhere($QueryBuilder, $query['where'] ?? null, false);
        self::applyDbalWhere($QueryBuilder, $query['where_or'] ?? null, true);

        if (!empty($query['group'])) {
            $QueryBuilder->groupBy(DoctrineUtils::quoteIdentifier((string)$query['group']));
        }

        if (!empty($query['order'])) {
            $order = explode(' ', (string)$query['order']);
            $QueryBuilder->orderBy(
                DoctrineUtils::quoteIdentifier($order[0]),
                $order[1] ?? null
            );
        }

        if (isset($query['limit'])) {
            $limit = explode(',', (string)$query['limit']);

            if (count($limit) === 1) {
                $QueryBuilder->setMaxResults((int)$limit[0]);
            } elseif ($limit[0] !== '') {
                $QueryBuilder->setFirstResult((int)$limit[0]);
            }

            if (count($limit) > 1 && $limit[1] !== '') {
                $QueryBuilder->setMaxResults((int)$limit[1]);
            }
        }

        try {
            return $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }
    }

    protected static function applyDbalWhere(QueryBuilder $QueryBuilder, mixed $where, bool $or): void
    {
        if (empty($where)) {
            return;
        }

        if (is_string($where)) {
            $or ? $QueryBuilder->orWhere($where) : $QueryBuilder->andWhere($where);
            return;
        }

        if (!is_array($where)) {
            return;
        }

        $expressions = [];
        $index = 0;

        foreach ($where as $field => $value) {
            $parameter = 'where_' . count($QueryBuilder->getParameters()) . '_' . $index;
            $quotedField = DoctrineUtils::quoteIdentifier((string)$field);

            if (is_array($value) && ($value['type'] ?? null) === '%LIKE%') {
                $expressions[] = $QueryBuilder->expr()->like($quotedField, ':' . $parameter);
                $QueryBuilder->setParameter($parameter, '%' . $value['value'] . '%');
                $index++;
                continue;
            }

            if (is_array($value) && ($value['type'] ?? null) === 'NOT') {
                $expressions[] = $QueryBuilder->expr()->neq($quotedField, ':' . $parameter);
                $QueryBuilder->setParameter($parameter, $value['value'] ?? null);
                $index++;
                continue;
            }

            if ($value === null) {
                $expressions[] = $QueryBuilder->expr()->isNull($quotedField);
                $index++;
                continue;
            }

            $expressions[] = $QueryBuilder->expr()->eq($quotedField, ':' . $parameter);
            $QueryBuilder->setParameter($parameter, $value);
            $index++;
        }

        $expression = '(' . implode($or ? ' OR ' : ' AND ', $expressions) . ')';
        $or ? $QueryBuilder->orWhere($expression) : $QueryBuilder->andWhere($expression);
    }

    /**
     * Translator setup
     * it looks, which languages are exist and create it
     */
    public static function setup(): void
    {
    }

    /**
     * Add / create a new language
     *
     * @param string $lang - lang code, length must be 2 signs
     *
     * @throws QUI\Exception
     * @throws \Exception
     */
    public static function addLang(string $lang): void
    {
        if (strlen($lang) !== 2) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/translator',
                    'exception.lang.shortcut.not.allowed'
                )
            );
        }

        $Connection = QUI::getDataBaseConnection();
        $Table = self::introspectTranslatorTable();
        $table = self::table();

        if ($Table->hasColumn($lang)) {
            return;
        }

        $quotedTable = DoctrineUtils::quoteIdentifier($table);
        $quotedLang = DoctrineUtils::quoteIdentifier($lang);
        $quotedEditLang = DoctrineUtils::quoteIdentifier($lang . '_edit');

        try {
            $Connection->executeStatement(
                "ALTER TABLE $quotedTable ADD $quotedLang TEXT NULL, ADD $quotedEditLang TEXT NULL"
            );
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }

        if (file_exists(VAR_DIR . 'locale/localefiles')) {
            unlink(VAR_DIR . 'locale/localefiles');
        }
    }

    /**
     * Export locale groups as XML
     *
     * @param string $group - which group should be exported? ("all" = Alle)
     * @param list<string> $langs - languages
     * @param string $type - "original" oder "edit"
     * @param bool $external (optional) - export translations of external groups
     * that are overwritten by the selected groups ($group) [default: false]
     *
     * @return string
     *
     * @throws QUI\Database\Exception
     */
    public static function export(string $group, array $langs, string $type, bool $external = false): string
    {
        $exportFolder = VAR_DIR . self::EXPORT_DIR;

        // Var-Folder für Export Dateien erstellen, falls nicht vorhanden
        QUI\Utils\System\File::mkdir($exportFolder);

        $fileName = $exportFolder . 'translator_export';

        // Alle Gruppen
        if ($group === 'all') {
            $groups = self::getGroupList();
            $fileName .= '_all';
        } else {
            $groups = [$group];
            $fileName .= '_' . str_replace('/', '_', $group);
        }

        $fileName .= '_' . mb_substr(md5(microtime()), 0, 6) . '.xml';

        $result = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $result .= '<locales>' . PHP_EOL;

        foreach ($groups as $grp) {
            $result .= self::createXMLContent($grp, $langs, $type, $external);
        }

        $result .= '</locales>';

        // Temp-Datei erzeugen
        file_put_contents($fileName, $result);

        return $fileName;
    }

    /**
     * Creates the content for a locale.xml for one or more groups/languages
     *
     * @param string $group
     * @param list<string> $languages
     * @param string $editType - original, edit, edit_overwrite
     * @param bool $external (optional) - include translations of external groups
     * that are overwritten by the selected groups ($group) [default: false]
     *
     * @return string
     *
     * @throws Exception
     */
    protected static function createXMLContent(
        string $group,
        array $languages,
        string $editType,
        bool $external = false
    ): string {
        if ($external) {
            $entries = self::get(false, false, $group);
        } else {
            $entries = self::get($group);
        }

        $pool = [];

        foreach ($entries as $entry) {
            // Undefinierte Gruppen ausschließen
            if (!isset($entry['groups'])) {
                continue;
            }

            if (!$external && mb_strpos($entry['groups'], $group) === false) {
                QUI\System\Log::addError(
                    'Translator Export: xml-Gruppe (' . $entry['groups'] . ')' .
                    ' passt nicht zur Translator-Gruppe (' . $group . ')'
                );

                continue;
            }

            $group = $entry['groups'];
            $type = 'php';

            if (!empty($entry['datatype'])) {
                $type = $entry['datatype'];
            }

            if (!isset($pool[$type])) {
                $pool[$type] = [];
            }

            if (!isset($pool[$type][$group])) {
                $pool[$type][$group] = [];
            }

            $pool[$type][$group][] = $entry;
        }

        $result = '';

        foreach ($pool as $type => $groups) {
            foreach ($groups as $group => $entries) {
                $result .= '<groups name="' . $group . '" datatype="' . $type . '">' . PHP_EOL;

                foreach ($entries as $entry) {
                    $result .= "\t" . '<locale name="' . $entry['var'] . '"';

                    if (isset($entry['html']) && $entry['html'] == 1) {
                        $result .= ' html="true"';
                    }

                    if (!empty($entry['priority'])) {
                        $result .= ' priority="' . (int)$entry['priority'] . '"';
                    }

                    if (!empty($entry['package'])) {
                        $result .= ' package="' . $entry['package'] . '"';
                    }

                    $result .= '>' . PHP_EOL;

                    foreach ($languages as $lang) {
                        $var = '';

                        switch ($editType) {
                            case 'edit':
                                if (isset($entry[$lang . '_edit']) && !empty($entry[$lang . '_edit'])) {
                                    $var = $entry[$lang . '_edit'];
                                }
                                break;

                            case 'edit_overwrite':
                                if (isset($entry[$lang . '_edit']) && !empty($entry[$lang . '_edit'])) {
                                    $var = $entry[$lang . '_edit'];
                                } else {
                                    if (!empty($entry[$lang])) {
                                        $var = $entry[$lang];
                                    }
                                }
                                break;

                            default:
                                if (!empty($entry[$lang])) {
                                    $var = $entry[$lang];
                                }
                        }

                        $result .= "\t\t" . '<' . $lang . '>';
                        $result .= '<![CDATA[' . $var . ']]>';
                        $result .= '</' . $lang . '>' . PHP_EOL;
                    }

                    $result .= "\t" . '</locale>' . PHP_EOL;
                }

                $result .= '</groups>' . PHP_EOL;
            }
        }

        return $result;
    }

    /**
     * Import a locale xml file
     *
     * @param string $file - path to the file
     * @param bool|integer $overwriteOriginal - if true, the _edit fields would be updated
     *                                     if false, the original fields would be updated
     * @param bool $devModeIgnore
     * @param string $packageName - name of the package
     * @param bool $force - The translation should really be executed, the $filemtimes is ignored
     *
     * @return array<int, array{group: string, var: string, locale: array<string, mixed>}> - List of imported vars
     * @throws QUI\Exception
     */
    public static function import(
        string $file,
        bool | int $overwriteOriginal = 0,
        bool $devModeIgnore = false,
        string $packageName = '',
        bool $force = false
    ): array {
        if (!file_exists($file)) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/translator',
                    'exception.lang.file.not.exist'
                )
            );
        }

        $fileMTimes = self::getLocaleModifyTimes();

        // nothing has changed
        if ($force === false && isset($fileMTimes[$file]) && filemtime($file) <= $fileMTimes[$file]) {
            return [];
        }

        $result = [];
        $devMode = QUI::conf('globals', 'development');

        if ($devModeIgnore) {
            $devMode = true;
        }

        // Format-Prüfung
        try {
            $groups = XML::getLocaleGroupsFromDom(
                XML::getDomFromXml($file)
            );
        } catch (\Exception) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/translator',
                    'exception.import.wrong.format',
                    ['file' => $file]
                )
            );
        }

        if (empty($groups)) {
            self::setLocaleFileModifyTime($file);

            return [];
        }

        set_time_limit((int)ini_get('max_execution_time'));

        foreach ($groups as $locales) {
            $group = $locales['group'];
            $datatype = '';

            if (isset($locales['datatype'])) {
                $datatype = $locales['datatype'];
            }

            foreach ($locales['locales'] as $locale) {
                $var = $locale['name'];

                unset($locale['name']);

                if (!isset($locale['html'])) {
                    $locale['html'] = 0;
                }

                if ($locale['html']) {
                    $locale['html'] = 1;
                } else {
                    $locale['html'] = 0;
                }

                if (empty($locale['priority'])) {
                    $locale['priority'] = 0;
                } else {
                    $locale['priority'] = (int)$locale['priority'];
                }

                $localePackageName = $packageName;

                if (empty($localePackageName) && !empty($locale['package'])) {
                    $localePackageName = $locale['package'];
                }

                try {
                    self::add($group, $var, $localePackageName);
                } catch (QUI\Exception $Exception) {
                    if ($Exception->getCode() !== self::ERROR_CODE_VAR_EXISTS) {
                        QUI\System\Log::writeException($Exception);
                    }
                }

                // test if group exists
                $groupContent = self::get($group, $var, $localePackageName);

                if (empty($groupContent)) {
                    continue;
                }

                if ($overwriteOriginal && $devMode) {
                    // set the original fields
                    $locale['datatype'] = $datatype;
                    self::update($group, $var, $localePackageName, $locale);
                } else {
                    // update only _edit fields
                    $_locale = [
                        'datatype' => $datatype,
                        'html' => $locale['html'],
                        'priority' => $locale['priority'],
                        'package' => $localePackageName
                    ];

                    unset($locale['html']);
                    unset($locale['priority']);
                    unset($locale['id']);

                    foreach ($locale as $key => $entry) {
                        $_locale[$key . '_edit'] = $entry;
                    }

                    self::edit($group, $var, $localePackageName, $_locale);
                }

                $result[] = [
                    'group' => $group,
                    'var' => $var,
                    'locale' => $locale,
                ];
            }
        }

        self::setLocaleFileModifyTime($file);

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/translator',
                'import.success'
            )
        );

        return $result;
    }

    /**
     * Imports all locale.xml files within the given package as batch
     *
     * @param Package\Package $Package
     *
     * @throws Exception|\QUI\Exception
     */
    public static function batchImportFromPackage(QUI\Package\Package $Package): void
    {
        $file = $Package->getXMLFilePath('locale.xml');

        if (!is_string($file) || $file === '') {
            return;
        }

        if (!file_exists($file)) {
            return;
        }

        self::batchImport($file, $Package->getName());

        try {
            $Dom = XML::getDomFromXml($file);
            $fileList = $Dom->getElementsByTagName('file');

            /** @var DOMElement $File */
            foreach ($fileList as $File) {
                $filePath = $Package->getDir() . ltrim($File->getAttribute('file'), '/');
                $packageName = $Package->getName();

                if ($File->hasAttribute('package')) {
                    $packageName = $File->getAttribute('package');
                }

                if (!file_exists($filePath)) {
                    continue;
                }

                self::batchImport($filePath, $packageName);
            }
        } catch (QUI\Exception) {
        }
    }

    /**
     * Starts a mass import of the whole locale.xml file.
     * The locale.xml will be inserted in one query of multiple INSERT IGNORE statements.
     *
     * Note:
     * This does not recurse into locale.xml files defined by <file> tags
     *
     * @param string $file - Full system filepath to the locale.xml
     * @param string $packageName - The package name of the locale.xml
     *
     * @return bool|int - Returns true on success
     *
     * @throws Exception|\QUI\Exception
     * @todo prepared statements
     */
    public static function batchImport(string $file, string $packageName = ''): bool | int
    {
        if (!file_exists($file)) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/translator',
                    'exception.lang.file.not.exist'
                )
            );
        }

        // Check XML format
        try {
            $groups = XML::getLocaleGroupsFromDom(
                XML::getDomFromXml($file)
            );
        } catch (\Exception) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/translator',
                    'exception.import.wrong.format',
                    ['file' => $file]
                )
            );
        }

        if (empty($groups)) {
            self::setLocaleFileModifyTime($file);

            return 0;
        }

        // *********************************** //
        //         Database Operations
        // *********************************** //

        $Connection = QUI::getDataBaseConnection();

        set_time_limit((int)ini_get('max_execution_time'));

        $localeVariables = [];
        $languages = self::langs();

        foreach ($groups as $locales) {
            $group = $locales['group'];
            $datatype = '';

            if (isset($locales['datatype'])) {
                $datatype = $locales['datatype'];
            }

            foreach ($locales['locales'] as $locale) {
                $var = $locale['name'];

                unset($locale['name']);

                if (!isset($locale['html'])) {
                    $locale['html'] = 0;
                }

                if ($locale['html']) {
                    $locale['html'] = 1;
                } else {
                    $locale['html'] = 0;
                }

                if (empty($locale['priority'])) {
                    $locale['priority'] = 0;
                } else {
                    $locale['priority'] = (int)$locale['priority'];
                }

                $localePackageName = $packageName;

                if (empty($localePackageName) && !empty($locale['package'])) {
                    $localePackageName = $locale['package'];
                }

                $localeVariable = [
                    'group' => $group,
                    'var' => $var,
                    'datatype' => $datatype,
                    'html' => $locale['html'],
                    'priority' => $locale['priority'],
                    'package' => $localePackageName
                ];

                foreach ($languages as $lang) {
                    if (isset($locale[$lang])) {
                        $localeVariable[$lang] = $locale[$lang];
                    }
                }

                $localeVariables[trim($group) . '/' . trim($var)] = $localeVariable;
            }
        }

        $hasOperations = false;
        $table = DoctrineUtils::quoteIdentifier(self::table());

        try {
            $currentRows = self::fetchDbal([
                'select' => [
                    'id',
                    'groups',
                    'var',
                ],
                'from' => self::table(),
                'where' => [
                    'package' => $packageName
                ]
            ]);

            foreach ($currentRows as $currentRow) {
                $varGroup = trim($currentRow['groups']);
                $varName = trim($currentRow['var']);

                if (!isset($localeVariables[$varGroup . '/' . $varName])) {
                    continue;
                }

                $var = $localeVariables[$varGroup . '/' . $varName];
                $updateData = [];

                foreach ($languages as $langCode) {
                    if (isset($var[$langCode])) {
                        $updateData[$langCode] = $var[$langCode];
                    }
                }

                $updateData['datatype'] = $var['datatype'];
                $updateData['html'] = $var['html'];
                $updateData['priority'] = $var['priority'];

                $hasOperations = true;
                $Connection->update($table, self::quoteDbalArrayKeys($updateData), self::quoteDbalArrayKeys([
                    'id' => $currentRow['id']
                ]));

                unset($localeVariables[$varGroup . '/' . $varName]);
            }

            $insertColumns = array_merge(
                ['groups', 'var', 'datatype', 'html', 'priority', 'package'],
                $languages
            );
            $insertRows = [];

            foreach ($localeVariables as $var) {
                $containsActiveLanguage = false;
                $insertData = [
                    'groups' => $var['group'],
                    'var' => $var['var'],
                    'datatype' => $var['datatype'],
                    'html' => $var['html'],
                    'priority' => $var['priority'],
                    'package' => $var['package']
                ];

                foreach ($languages as $langCode) {
                    if (isset($var[$langCode])) {
                        $containsActiveLanguage = true;
                        $insertData[$langCode] = $var[$langCode];
                    }
                }

                if (!$containsActiveLanguage) {
                    continue;
                }

                $insertRows[] = $insertData;
            }

            if (!empty($insertRows)) {
                $hasOperations = true;
                self::bulkInsertDbal(self::table(), $insertColumns, $insertRows);
            }
        } catch (DbalException $Exception) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/translator', 'exception.batch.query.error', [
                    'file' => $file,
                    'error' => $Exception->getMessage()
                ])
            );
        }

        if (!$hasOperations) {
            return true;
        }

        self::setLocaleFileModifyTime($file);

        return true;
    }

    /**
     * @param Package\Package $Package
     * @param int $overwriteOriginal
     * @param bool $devModeIgnore
     * @param bool $force - The translation should really be executed, the $filemtimes is ignored
     *
     * @throws QUI\Exception
     */
    public static function importFromPackage(
        QUI\Package\Package $Package,
        int $overwriteOriginal = 0,
        bool $devModeIgnore = false,
        bool $force = false
    ): void {
        $file = $Package->getXMLFilePath('locale.xml');

        if (!is_string($file) || $file === '') {
            return;
        }

        if (!file_exists($file)) {
            return;
        }

        self::import(
            $file,
            $overwriteOriginal,
            $devModeIgnore,
            $Package->getName(),
            $force
        );

        try {
            $Dom = XML::getDomFromXml($file);
            $fileList = $Dom->getElementsByTagName('file');

            /** @var DOMElement $File */
            foreach ($fileList as $File) {
                $filePath = $Package->getDir() . ltrim($File->getAttribute('file'), '/');
                $packageName = $Package->getName();

                if ($File->hasAttribute('package')) {
                    $packageName = $File->getAttribute('package');
                }

                if (!file_exists($filePath)) {
                    continue;
                }

                self::import(
                    $filePath,
                    $overwriteOriginal,
                    $devModeIgnore,
                    $packageName,
                    $force
                );
            }
        } catch (QUI\Exception) {
        }
    }

    /**
     * Add the file to the modify time list
     *
     * @param string $file - path to the locale file
     */
    protected static function setLocaleFileModifyTime(string $file): void
    {
        if (!file_exists($file)) {
            return;
        }

        self::$localeModifyTimes[$file] = filemtime($file);

        file_put_contents(
            VAR_DIR . 'locale/localefiles',
            json_encode(self::$localeModifyTimes)
        );
    }

    /**
     * Return modify times of all imported locale XML files
     *
     * @return array<string, int|false>
     */
    protected static function getLocaleModifyTimes(): array
    {
        if (!is_null(self::$localeModifyTimes)) {
            return self::$localeModifyTimes;
        }

        $cacheFile = VAR_DIR . 'locale/localefiles';

        if (!file_exists($cacheFile)) {
            file_put_contents($cacheFile, '');
        }

        $cacheContent = file_get_contents($cacheFile);

        if ($cacheContent === false || $cacheContent === '') {
            self::$localeModifyTimes = [];

            return self::$localeModifyTimes;
        }

        $list = json_decode($cacheContent, true);

        if (!is_array($list)) {
            $list = [];
        }

        self::$localeModifyTimes = $list;

        return self::$localeModifyTimes;
    }

    /**
     * Ordner in dem die Übersetzungen liegen
     *
     * @return String
     */
    public static function dir(): string
    {
        return QUI::getLocale()->dir();
    }

    /**
     * Übersetzungs Datei
     *
     * @param string $lang
     * @param string $group
     *
     * @return String
     */
    public static function getTranslationFile(string $lang, string $group): string
    {
        return QUI::getLocale()->getTranslationFile($lang, $group);
    }

    /**
     * Return the list of the translation files for a language
     * it combines the language files in none development mode
     *
     * @param string $lang - Language -> eq: "de" or "en" ... and so on
     *
     * @return array<string, string>
     */
    public static function getJSTranslationFiles(string $lang): array
    {
        if (strlen($lang) !== 2) {
            return [];
        }

        $result = [];

        $jsDir = self::dir() . 'bin/';
        $cacheFile = $jsDir . '_cache/' . $lang . '.js';
        $development = QUI::conf('globals', 'development');

        QUIFile::mkdir($jsDir . '_cache/');

        if (file_exists($cacheFile) && !$development) {
            return ['locale/_cache' => $cacheFile];
        }

        $dirs = QUIFile::readDir($jsDir);
        $localeSetCalls = [];

        foreach ($dirs as $dir) {
            $package_dir = $jsDir . $dir;
            $package_list = QUIFile::readDir($package_dir);

            foreach ($package_list as $package) {
                if ($package == '_cache') {
                    continue;
                }

                if (!file_exists($package_dir . '/' . $package)) {
                    continue;
                }

                if (!is_dir($package_dir . '/' . $package)) {
                    continue;
                }

                $lang_file = $package_dir . '/' . $package . '/' . $lang . '.js';

                if (file_exists($lang_file)) {
                    $result['locale/' . $dir . '/' . $package] = $lang_file;
                    $langContent = file_get_contents($lang_file);

                    if (
                        $langContent !== false
                        && !self::isEmptyJavaScriptLocaleFile($langContent)
                    ) {
                        $localeSetCall = self::getJavaScriptLocaleSetCall($langContent);

                        if ($localeSetCall !== null) {
                            $localeSetCalls[] = $localeSetCall;
                        }
                    }
                }
            }
        }

        if ($development) {
            return $result;
        }

        $cacheData = "define('locale/_cache/$lang', ['Locale'], function(Locale) {";
        $cacheData .= PHP_EOL . implode(PHP_EOL, $localeSetCalls);
        $cacheData .= PHP_EOL . 'return Locale;';
        $cacheData .= PHP_EOL . '});';

        file_put_contents($cacheFile, $cacheData);

        return ['locale/_cache' => $cacheFile];
    }

    /**
     * Extracts the Locale.set() call from a generated JavaScript locale module.
     */
    protected static function getJavaScriptLocaleSetCall(string $content): ?string
    {
        if (preg_match('/Locale\.set\((.*)\)\s*;?\s*\}\);?\s*$/s', trim($content), $matches) !== 1) {
            return null;
        }

        return 'Locale.set(' . $matches[1] . ');';
    }

    /**
     * Checks if a generated JavaScript locale module contains no translations.
     */
    protected static function isEmptyJavaScriptLocaleFile(string $content): bool
    {
        $localeSetCall = self::getJavaScriptLocaleSetCall($content);

        if ($localeSetCall === null) {
            return false;
        }

        return preg_match('/,\s*(?:\[\]|\{\})\s*\);$/', $localeSetCall) === 1;
    }

    /**
     * Returns the locale publish version used for cache busting.
     */
    public static function getLocalePublishVersion(): string
    {
        if (is_string(self::$localePublishVersion) && self::$localePublishVersion !== '') {
            return self::$localePublishVersion;
        }

        try {
            $Config = QUI::getPackage('quiqqer/translator')->getConfig();
            $configuredVersion = $Config?->get('locale', 'publishVersion');

            if (is_string($configuredVersion) && $configuredVersion !== '') {
                self::$localePublishVersion = $configuredVersion;

                return $configuredVersion;
            }
        } catch (\Exception) {
            // nothing, create a new version
        }

        $version = self::createLocalePublishVersion();
        self::$localePublishVersion = $version;
        self::saveLocalePublishVersion($version);

        return $version;
    }

    /**
     * Return all available languages
     *
     * @return list<string>|null
     * @throws Exception|\QUI\Exception
     */
    public static function getAvailableLanguages(): ?array
    {
        $cacheName = 'quiqqer/translator/availableLanguages';

        if (self::$availableLanguages !== null) {
            return self::$availableLanguages;
        }

        try {
            $cachedLanguages = CacheManager::get($cacheName);

            if (is_array($cachedLanguages)) {
                self::$availableLanguages = array_values(
                    array_filter(
                        $cachedLanguages,
                        static function ($entry): bool {
                            return is_string($entry);
                        }
                    )
                );

                return self::$availableLanguages;
            }
        } catch (\Exception) {
            // nothing, retrieve languages normally
        }

        $projects = QUI::getProjectManager()->getProjects(true);
        $languages = [];

        /* @var $Project QUI\Projects\Project */
        foreach ($projects as $Project) {
            $languages = array_merge($languages, $Project->getAttribute('langs'));
        }

        $languages = array_unique($languages);
        $languages = array_unique(array_merge($languages, self::langs()));
        $languages = array_values(
            array_filter(
                $languages,
                static function ($entry): bool {
                    return is_string($entry);
                }
            )
        );

        CacheManager::set($cacheName, $languages);
        self::$availableLanguages = $languages;

        return $languages;
    }

    /**
     * Remove all duplicate entries in `translate`
     *
     * Duplicate = identical regarding `groups`, `var` and `package`
     *
     * When a duplicate is found, the entry with the LOWEST id is kept and all other
     * entries are deleted!
     *
     * @return void
     *
     * @throws QUI\Database\Exception
     */
    public static function cleanup(): void
    {
        $Connection = QUI::getDataBaseConnection();
        $table = DoctrineUtils::quoteIdentifier(self::table());

        try {
            $result = $Connection->createQueryBuilder()
                ->select(
                    DoctrineUtils::quoteIdentifier('groups'),
                    DoctrineUtils::quoteIdentifier('var'),
                    DoctrineUtils::quoteIdentifier('package')
                )
                ->from($table)
                ->groupBy(
                    DoctrineUtils::quoteIdentifier('groups'),
                    DoctrineUtils::quoteIdentifier('var'),
                    DoctrineUtils::quoteIdentifier('package')
                )
                ->having('COUNT(*) > 1')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($result as $row) {
                $duplicates = $Connection->createQueryBuilder()
                    ->select(DoctrineUtils::quoteIdentifier('id'))
                    ->from($table)
                    ->where(DoctrineUtils::quoteIdentifier('groups') . ' = :groups')
                    ->andWhere(DoctrineUtils::quoteIdentifier('var') . ' = :var')
                    ->andWhere(DoctrineUtils::quoteIdentifier('package') . ' = :package')
                    ->setParameter('groups', $row['groups'])
                    ->setParameter('var', $row['var'])
                    ->setParameter('package', $row['package'])
                    ->executeQuery()
                    ->fetchAllAssociative();

                $duplicateIds = [];

                foreach ($duplicates as $duplicate) {
                    $duplicateIds[] = $duplicate['id'];
                }

                if (empty($duplicateIds)) {
                    continue;
                }

                $Connection->createQueryBuilder()
                    ->delete($table)
                    ->where(DoctrineUtils::quoteIdentifier('groups') . ' = :groups')
                    ->andWhere(DoctrineUtils::quoteIdentifier('var') . ' = :var')
                    ->andWhere(DoctrineUtils::quoteIdentifier('package') . ' = :package')
                    ->andWhere(DoctrineUtils::quoteIdentifier('id') . ' != :keepId')
                    ->setParameter('groups', $row['groups'])
                    ->setParameter('var', $row['var'])
                    ->setParameter('package', $row['package'])
                    ->setParameter('keepId', min($duplicateIds))
                    ->executeStatement();
            }
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }
    }

    /**
     * Create the locale files
     *
     * @throws QUI\Exception
     */
    public static function create(): void
    {
        // first step, a cleanup
        // so, we get no errors in gettext
        self::cleanup();

        $languages = self::langs();
        $dir = self::dir();

        // Sprach Ordner erstellen
        $folders = [];

        foreach ($languages as $lang) {
            $lcMessagePath = $dir . '/' . StringHelper::toLower($lang);
            //$lcMessagePath .= '_' . StringHelper::toUpper($lang);
            $lcMessagePath .= '/LC_MESSAGES/';

            $folders[$lang] = $lcMessagePath;

            QUIFile::unlink($folders[$lang]);
            QUIFile::mkdir($folders[$lang]);
        }

        $js_languages = [];
        $Output = false;

        if (class_exists('QUI\Output')) {
            $Output = new Output();
        }

        // Sprachdateien erstellen
        foreach ($languages as $lang) {
            set_time_limit((int)ini_get('max_execution_time'));

            if (strlen($lang) !== 2) {
                continue;
            }

            $result = self::fetchDbal([
                'select' => [
                    $lang,
                    $lang . '_edit',
                    'groups',
                    'var',
                    'datatype',
                    'datadefine',
                    'html',
                    'priority'
                ],
                'from' => self::table(),
                'order' => 'priority ASC'
            ]);

            // priority ASC Erklärung:
            // Wir müssen den kleinsten zuerst nehmen,
            // damit die höchste Priorität zuletzt kommt und die davor überschreibt,
            // ist verwirrend, aber somit sparen wir ein Query

            foreach ($result as $entry) {
                if (self::isEmpty($entry[$lang]) && self::isEmpty($entry[$lang . '_edit'])) {
                    continue;
                }

                if ($entry['datatype'] == 'js') {
                    $js_languages[$entry['groups']][$lang][] = $entry;
                    continue;
                }

                // if php,js
                if (str_contains($entry['datatype'], 'js') || empty($entry['datatype'])) {
                    $js_languages[$entry['groups']][$lang][] = $entry;
                }

                $value = $entry[$lang];

                if (
                    isset($entry[$lang . '_edit'])
                    && !self::isEmpty($entry[$lang . '_edit'])
                ) {
                    $value = $entry[$lang . '_edit']; // benutzer übersetzung
                }

                if (is_array($value)) {
                    $value = '';
                } else {
                    $value = (string)$value;
                }

                if ($Output) {
                    $value = $Output->parse($value);

                    // replace because of img src="" use url decode
                    $value = str_replace('%5B', '[', $value);
                    $value = str_replace('%5D', ']', $value);
                }

                $value = str_replace('\\', '\\\\', $value);
                $value = str_replace('"', '\"', $value);
                $value = str_replace("\n", '{\n}', $value);

                if (is_string($value) && $value !== '' && $value !== ' ') {
                    $value = trim($value);
                }

                // ini Datei
                $iniVar = $entry['var'];

                // in php some keywords are not allowed, so we rewrite the key in `
                // it's better than destroy the ini file
                switch ($iniVar) {
                    case 'null':
                    case 'yes':
                    case 'no':
                    case 'true':
                    case 'false':
                    case 'on':
                    case 'off':
                    case 'none':
                        $iniVar = '`' . $iniVar . '`';
                        break;
                }

                $ini = $folders[$lang] . str_replace('/', '_', $entry['groups']) . '.ini.php';
                $iniValue = is_string($value) ? $value : '';
                $ini_str = $iniVar . '= "' . $iniValue . '"';

                QUIFile::mkfile($ini);
                QUIFile::putLineToFile($ini, $ini_str);
            }

            // create JavaScript lang files
            $jsDir = $dir . '/bin/';

            QUIFile::mkdir($jsDir);

            foreach ($js_languages as $group => $groupEntry) {
                foreach ($groupEntry as $lang => $entries) {
                    $vars = [];

                    foreach ($entries as $entry) {
                        $value = $entry[$lang];

                        if (isset($entry[$lang . '_edit']) && !empty($entry[$lang . '_edit'])) {
                            $value = $entry[$lang . '_edit']; // benutzer übersetzung
                        }

                        $vars[$entry['var']] = $value;
                    }

                    $js = "define('locale/" . $group . "/" . $lang . "', ['Locale'], function(Locale)";
                    $js .= '{';
                    $js .= 'Locale.set("' . $lang . '", "' . $group . '", ';
                    $js .= json_encode($vars);
                    $js .= ')';
                    $js .= '});';

                    // create package dir
                    QUIFile::mkdir($jsDir . $group);

                    if (file_exists($jsDir . $group . '/' . $lang . '.js')) {
                        unlink($jsDir . $group . '/' . $lang . '.js');
                    }

                    file_put_contents($jsDir . $group . '/' . $lang . '.js', $js);
                }
            }
        }

        // clean cache dir of js files
        QUI::getTemp()->moveToTemp($dir . '/bin/_cache/');

        QUI::getLocale()->refresh();

        QUI\Cache\Manager::clearCompleteQuiqqerCache();
        self::refreshLocalePublishVersion();

        QUI::getEvents()->fireEvent('quiqqerTranslatorPublish');
    }

    /**
     * @param mixed $str
     * @return bool
     */
    protected static function isEmpty(mixed $str): bool
    {
        if ($str === null) {
            return false;
        }

        if (!is_string($str)) {
            return empty($str);
        }

        if (str_contains($str, ' ') && strlen($str) === 1) {
            return false;
        }

        return empty($str);
    }

    /**
     * Publish a language group
     *
     * @param string $group
     *
     * @throws QUI\Exception
     */
    public static function publish(string $group): void
    {
        $languages = self::langs();
        $dir = self::dir();
        $Output = false;

        if (class_exists('QUI\Output')) {
            $Output = new Output();
        }

        foreach ($languages as $lang) {
            if (strlen($lang) !== 2) {
                continue;
            }

            $folder = $dir . '/';
            $folder .= StringHelper::toLower($lang); //. '_' . StringHelper::toUpper($lang);
            $folder .= '/LC_MESSAGES/';

            QUIFile::mkdir($folder);

            $result = self::fetchDbal([
                'select' => [
                    $lang,
                    $lang . '_edit',
                    'groups',
                    'var',
                    'datatype',
                    'datadefine',
                    'html'
                ],
                'from' => self::table(),
                'where' => [
                    'groups' => $group
                ],
                'order' => 'priority ASC'
            ]);

            // priority ASC Erklärung:
            // Wir müssen den kleinsten zuerst nehmen,
            // damit die höchste Priorität zuletzt kommt und die davor überschreibt,
            // ist verwirrend, aber somit sparen wir ein Query

            $javaScriptValues = [];
            $iniContent = '';

            foreach ($result as $data) {
                // value select
                $value = $data[$lang];

                if (isset($data[$lang . '_edit']) && !self::isEmpty($data[$lang . '_edit'])) {
                    $value = $data[$lang . '_edit'];
                }

                if (is_array($value)) {
                    $value = '';
                } else {
                    $value = (string)$value;
                }

                if (empty($value)) {
                    continue;
                }

                if ($Output) {
                    $value = $Output->parse($value);

                    // replace because of img src="" use url decode
                    $value = str_replace('%5B', '[', $value);
                    $value = str_replace('%5D', ']', $value);
                }

                if ($data['datatype'] == 'js') {
                    $javaScriptValues[$data['var']] = $value;
                    continue;
                }

                // php und js beachten
                if (str_contains($data['datatype'], 'js') || empty($data['datatype'])) {
                    $javaScriptValues[$data['var']] = $value;
                }

                $value = str_replace('\\', '\\\\', $value);
                $value = str_replace('"', '\"', $value);
                $value = str_replace("\n", '{\n}', $value);

                if (is_string($value) && $value !== '' && $value !== ' ') {
                    $value = trim($value);
                }

                // ini Content
                $iniVar = $data['var'];

                // in php some keywords are not allowed, so we rewrite the key in `
                // it's better than destroy the ini file
                switch ($iniVar) {
                    case 'null':
                    case 'yes':
                    case 'no':
                    case 'true':
                    case 'false':
                    case 'on':
                    case 'off':
                    case 'none':
                        $iniVar = '`' . $iniVar . '`';
                        break;
                }

                // content
                $iniValue = is_string($value) ? $value : '';
                $iniContent .= $iniVar . '= "' . $iniValue . '"' . PHP_EOL;
            }

            // set data
            $iniFile = $folder . str_replace('/', '_', $group) . '.ini.php';

            QUIFile::unlink($iniFile);
            QUIFile::mkfile($iniFile);

            file_put_contents($iniFile, $iniContent);

            // javascript
            $jsFile = $dir . '/bin/' . $group . '/' . $lang . '.js';

            QUIFile::unlink($jsFile);
            QUIFile::mkfile($jsFile);

            $jsContent = "define('locale/" . $group . "/" . $lang . "', ['Locale'], function(Locale)";
            $jsContent .= '{';
            $jsContent .= 'Locale.set("' . $lang . '", "' . $group . '", ';
            $jsContent .= json_encode($javaScriptValues);
            $jsContent .= ')';
            $jsContent .= '});';

            file_put_contents($jsFile, $jsContent);
        }

        // clean cache dir of js files
        QUI::getTemp()->moveToTemp($dir . '/bin/_cache/');
        QUI\Cache\Manager::clearCompleteQuiqqerCache();

        QUI::getLocale()->refresh();
        self::refreshLocalePublishVersion();

        QUI::getEvents()->fireEvent('quiqqerTranslatorPublish');
    }

    /**
     * Creates and persists a new locale publish version.
     */
    protected static function refreshLocalePublishVersion(): void
    {
        self::$localePublishVersion = self::createLocalePublishVersion();
        self::saveLocalePublishVersion(self::$localePublishVersion);
    }

    /**
     * Generate a version token for locale cache busting.
     */
    protected static function createLocalePublishVersion(): string
    {
        return md5((string)microtime(true));
    }

    /**
     * Persist locale publish version in package config.
     */
    protected static function saveLocalePublishVersion(string $version): void
    {
        try {
            $Config = QUI::getPackage('quiqqer/translator')->getConfig();

            if (!$Config) {
                return;
            }

            $Config->set('locale', 'publishVersion', $version);
            $Config->save();
        } catch (\Exception) {
            // ignore
        }
    }

    /**
     * Returns a translation
     *
     * @param boolean|string $group - group
     * @param boolean|string $var - variable, optional
     * @param boolean|string $package - optional, package name
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws QUI\Database\Exception
     */
    public static function get(
        bool | string $group = false,
        bool | string $var = false,
        bool | string $package = false
    ): array {
        $where = [];

        if ($group) {
            $where['groups'] = $group;
        }

        if ($var) {
            $where['var'] = $var;
        }

        if ($package) {
            $where['package'] = $package;
        }

        return self::fetchDbal([
            'from' => self::table(),
            'where' => $where
        ]);
    }

    /**
     * Get data for the table
     *
     * @param string $groups - Group
     * @param array<string, int|string> $params - optional array(limit => 10, page => 1)
     * @param bool|array<string, mixed> $search - optional array(search => '%str%', fields => '')
     *
     * @return array{data: array<int, array<string, mixed>>, page: int, count: int|string, total: int|string}
     * @throws Exception|\QUI\Exception
     */
    public static function getData(string $groups, array $params = [], bool | array $search = false): array
    {
        $table = self::table();
        $db_fields = self::langs();

        $max = 10;
        $page = 1;

        if (isset($params['limit'])) {
            $max = (int)$params['limit'];
        }

        if (isset($params['page'])) {
            $page = (int)$params['page'];
        }

        $page = ($page - 1) ?: 0;
        $limit = ($page * $max) . ',' . $max;

        // search empty translations
        if ($search && isset($search['emptyTranslations']) && $search['emptyTranslations']) {
            $fields = [];

            if (!empty($search['fields'])) {
                $fields = array_flip($search['fields']);
            }

            $whereParts = [];

            foreach ($db_fields as $field) {
                if (!empty($fields) && !isset($fields[$field])) {
                    continue;
                }

                $quotedField = DoctrineUtils::quoteIdentifier($field);
                $quotedEditField = DoctrineUtils::quoteIdentifier($field . '_edit');
                $whereParts[] = "(($quotedField = '' OR $quotedField IS NULL) AND ($quotedEditField = '' OR $quotedEditField IS NULL))";
            }

            if (empty($whereParts)) {
                return [
                    'data' => [],
                    'page' => $page + 1,
                    'count' => 0,
                    'total' => 0
                ];
            }

            $where = '(' . implode(' OR ', $whereParts) . ')';
            $Connection = QUI::getDataBaseConnection();

            try {
                $result = $Connection->createQueryBuilder()
                    ->select('*')
                    ->from(DoctrineUtils::quoteIdentifier($table))
                    ->where($where)
                    ->setFirstResult($page * $max)
                    ->setMaxResults($max)
                    ->executeQuery()
                    ->fetchAllAssociative();

                $count = $Connection->createQueryBuilder()
                    ->select('COUNT(*) AS ' . DoctrineUtils::quoteIdentifier('count'))
                    ->from(DoctrineUtils::quoteIdentifier($table))
                    ->where($where)
                    ->executeQuery()
                    ->fetchAllAssociative();
            } catch (DbalException $Exception) {
                $Exception = self::createDatabaseException($Exception);
                QUI\System\Log::writeException($Exception);

                return [
                    'data' => [],
                    'page' => 1,
                    'count' => 0,
                    'total' => 0
                ];
            }

            return [
                'data' => $result,
                'page' => $page + 1,
                'count' => $count[0]['count'],
                'total' => $count[0]['count']
            ];
        }

        if ($search && isset($search['search'])) {
            // search translations
            $where = [];
            $whereSearch = [
                'type' => '%LIKE%',
                'value' => trim($search['search'])
            ];

            // default fields
            $default = [
                'groups' => $whereSearch,
                'var' => $whereSearch,
                'datatype' => $whereSearch,
                'datadefine' => $whereSearch
            ];

            foreach ($db_fields as $lang) {
                if (strlen($lang) == 2) {
                    $default[$lang] = $whereSearch;
                    $default[$lang . '_edit'] = $whereSearch;
                }
            }

            // search
            $fields = [];

            if (!empty($search['fields'])) {
                $fields = $search['fields'];
            }

            foreach ($fields as $field) {
                if (isset($default[$field])) {
                    $where[$field] = $whereSearch;

                    if (
                        in_array($field, $db_fields, true)
                        && strlen($field) === 2
                        && isset($default[$field . '_edit'])
                    ) {
                        $where[$field . '_edit'] = $whereSearch;
                    }
                }
            }

            if (empty($where)) {
                $where = $default;
            }

            $data = [
                'from' => $table,
                'where_or' => $where,
                'limit' => $limit
            ];
        } else {
            // search complete group
            $data = [
                'from' => $table,
                'where' => [
                    'groups' => $groups
                ],
                'limit' => $limit
            ];
        }

        // result mit limit
        try {
            $result = self::fetchDbal($data);
        } catch (QUI\Database\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return [
                'data' => [],
                'page' => 1,
                'count' => 0,
                'total' => 0
            ];
        }


        // count
        $data['count'] = 'groups';
        unset($data['limit']);

        try {
            $count = self::fetchDbal($data);
        } catch (QUI\Database\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return [
                'data' => [],
                'page' => 1,
                'count' => 0,
                'total' => 0
            ];
        }

        return [
            'data' => $result,
            'page' => $page + 1,
            'count' => $count[0]['groups'],
            'total' => $count[0]['groups']
        ];
    }

    /**
     * Return the data from a translation variable
     *
     * @param string $group
     * @param string $var
     * @param bool|string $package
     *
     * @return array<string, mixed>
     */
    public static function getVarData(string $group, string $var, bool | string $package = false): array
    {
        $where = [
            'groups' => $group,
            'var' => $var
        ];

        if (!empty($package)) {
            $where['package'] = $package;
        }

        try {
            $result = self::fetchDbal([
                'from' => self::table(),
                'where' => $where,
                'limit' => 1
            ]);
        } catch (QUI\Database\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return [];
        }

        return $result[0] ?? [];
    }

    /**
     * List of all existing groups
     *
     * @return list<string>
     */
    public static function getGroupList(): array
    {
        try {
            $result = self::fetchDbal([
                'select' => 'groups',
                'from' => self::table(),
                'group' => 'groups'
            ]);
        } catch (QUI\Database\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return [];
        }

        $list = [];

        foreach ($result as $entry) {
            $list[] = $entry['groups'];
        }

        return $list;
    }

    /**
     * Add a translation variable
     *
     * @param string $group
     * @param string $var
     * @param bool|string $package = default = false
     * @param string $dataType - default = php,js
     * @param bool|integer $html - default = false
     *
     * @throws QUI\Exception
     */
    public static function add(
        string $group,
        string $var,
        bool | string $package = false,
        string $dataType = 'php,js',
        bool | int $html = false
    ): void {
        if (empty($var) || empty($group)) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/translator',
                    'exception.empty.var.group'
                )
            );
        }

        $result = self::get($group, $var, $package);

        if (isset($result[0])) {
            throw new QUI\Exception(
                [
                    'quiqqer/translator',
                    'exception.var.exists',
                    [
                        'group' => $group,
                        'var' => $var,
                        'package' => $package
                    ]
                ],
                self::ERROR_CODE_VAR_EXISTS
            );
        }

        // cleanup datatype
        $types = [];
        $dataType = explode(',', $dataType);

        foreach ($dataType as $type) {
            switch ($type) {
                case 'php':
                case 'js':
                    $types[] = $type;
                    break;
            }
        }

        if (empty($types)) {
            $types = ['php', 'js'];
        }

        try {
            QUI::getDataBaseConnection()->insert(
                DoctrineUtils::quoteIdentifier(self::table()),
                self::quoteDbalArrayKeys([
                    'groups' => $group,
                    'var' => $var,
                    'package' => !empty($package) ? $package : '',
                    'datatype' => implode(',', $types),
                    'html' => $html ? 1 : 0
                ])
            );
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }
    }

    /**
     * Add a translation like from a user
     *
     * @param string $group
     * @param string $var
     * @param array<string, mixed> $data - [de='', en=>'', datatype=>'', html=>1]
     *
     * @throws QUI\Exception
     */
    public static function addUserVar(string $group, string $var, array $data): void
    {
        $package = false;
        $development = QUI::conf('globals', 'development');

        if (isset($data['package'])) {
            $package = $data['package'];
        }

        if ($development) {
            $languages = self::langs();

            foreach ($languages as $lang) {
                if (!isset($data[$lang . '_edit']) && isset($data[$lang])) {
                    $data[$lang . '_edit'] = $data[$lang];
                }
            }
        }

        try {
            QUI\Translator::add($group, $var, $package);
        } catch (QUI\Exception $Exception) {
            if ($Exception->getCode() !== self::ERROR_CODE_VAR_EXISTS) {
                throw $Exception;
            }
        }

        QUI\Translator::edit($group, $var, $package, $data);
    }

    /**
     * Updates a translation var entry
     *
     * Is used directly when DEV Mode is on. This has the sense that a developer does not have to work in locale.xml
     * but can work directly in the translator. He can then export this again and gets a modified locale.xml
     *
     * IS DIFFERENT TO edit() => edit() = Normal behavior
     *
     * @param string $group
     * @param string $var
     * @param string $packageName
     * @param array<string, mixed> $data
     *
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public static function update(string $group, string $var, string $packageName, array $data): void
    {
        $languages = self::langs();
        $_data = [];

        foreach ($languages as $lang) {
            if (!isset($data[$lang])) {
                continue;
            }

            $content = trim($data[$lang]);

            // Leere Werte ignorieren
            if (empty($content)) {
                continue;
            }

            $_data[$lang] = $content;
        }

        $_data['html'] = 0;
        $_data['priority'] = 0;
        $_data['datatype'] = 'php,js';

        if (isset($data['datatype'])) {
            $_data['datatype'] = $data['datatype'];
        }

        if (!empty($data['html'])) {
            $_data['html'] = 1;
        }

        if (!empty($data['priority'])) {
            $_data['priority'] = (int)$data['priority'];
        }

        try {
            QUI::getDataBaseConnection()->update(
                DoctrineUtils::quoteIdentifier(self::table()),
                self::quoteDbalArrayKeys($_data),
                self::quoteDbalArrayKeys([
                    'groups' => $group,
                    'var' => $var,
                    'package' => $packageName ?: $group
                ])
            );
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }

        QUI::getEvents()->fireEvent('quiqqerTranslatorUpdate', [$group, $var, $packageName, $data]);
    }

    /**
     * User Edit - Updates a translation var entry
     *  edit() = normal behavior
     *
     *  IS DIFFERENT TO update() =>
     *      update() used directly when DEV Mode is on. This has the sense that a developer does not have to work in locale.xml
     *      but can work directly in the translator. He can then export this again and gets a modified locale.xml
     *
     * @param string $group
     * @param string $var
     * @param string $packageName
     * @param array<string, mixed> $data
     *
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public static function edit(string $group, string $var, string $packageName, array $data): void
    {
        try {
            QUI::getDataBaseConnection()->update(
                DoctrineUtils::quoteIdentifier(self::table()),
                self::quoteDbalArrayKeys(self::getEditData($data)),
                self::quoteDbalArrayKeys([
                    'groups' => $group,
                    'var' => $var,
                    'package' => $packageName ?: $group
                ])
            );
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }

        QUI::getEvents()->fireEvent('quiqqerTranslatorEdit', [$group, $var, $packageName, $data]);
    }

    /**
     * User Edit with an entry id
     *
     * @param integer $id
     * @param array<string, mixed> $data
     *
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public static function editById(int $id, array $data): void
    {
        try {
            QUI::getDataBaseConnection()->update(
                DoctrineUtils::quoteIdentifier(self::table()),
                self::quoteDbalArrayKeys(self::getEditData($data)),
                self::quoteDbalArrayKeys([
                    'id' => $id
                ])
            );
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }

        QUI::getEvents()->fireEvent('quiqqerTranslatorEditById', [$id, $data]);
    }

    /**
     * Prepares the data for a translation entry
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     * @throws Exception|\QUI\Exception
     */
    protected static function getEditData(array $data): array
    {
        $languages = self::langs();
        $_data = [];

        $development = QUI::conf('globals', 'development');

        $isSpace = function ($str) {
            return str_contains($str, ' ') && strlen($str) === 1;
        };

        foreach ($languages as $lang) {
            if ($development) {
                if (isset($data[$lang])) {
                    if ($isSpace($data[$lang])) {
                        $_data[$lang] = $data[$lang];
                    } else {
                        $_data[$lang] = trim($data[$lang]);
                    }
                }

                if (isset($data[$lang . '_edit'])) {
                    if ($isSpace($data[$lang . '_edit'])) {
                        $_data[$lang . '_edit'] = $data[$lang . '_edit'];
                    } else {
                        $_data[$lang . '_edit'] = trim($data[$lang . '_edit']);
                    }
                }

                continue;
            }

            if (!isset($data[$lang]) && !isset($data[$lang . '_edit'])) {
                continue;
            }

            if (isset($data[$lang])) {
                if ($isSpace($data[$lang])) {
                    $_data[$lang . '_edit'] = $data[$lang];
                } else {
                    $_data[$lang . '_edit'] = trim($data[$lang]);
                }

                continue;
            }

            if ($isSpace($data[$lang . '_edit'])) {
                $_data[$lang . '_edit'] = $data[$lang . '_edit'];
            } else {
                $_data[$lang . '_edit'] = trim($data[$lang . '_edit']);
            }
        }

        $_data['html'] = 0;
        $_data['priority'] = 0;
        $_data['datatype'] = 'php,js';

        if (isset($data['datatype'])) {
            $_data['datatype'] = $data['datatype'];
        }

        if (!empty($data['html'])) {
            $_data['html'] = 1;
        }

        if (!empty($data['priority'])) {
            $_data['priority'] = (int)$data['priority'];
        }

        return $_data;
    }

    /**
     * Deletes a translation group/var pair
     *
     * @param string $group
     * @param string $var
     *
     * @throws QUI\Database\Exception
     */
    public static function delete(string $group, string $var): void
    {
        if (file_exists(VAR_DIR . 'locale/localefiles')) {
            unlink(VAR_DIR . 'locale/localefiles');
        }

        try {
            QUI::getDataBaseConnection()->delete(
                DoctrineUtils::quoteIdentifier(self::table()),
                self::quoteDbalArrayKeys([
                    'groups' => $group,
                    'var' => $var
                ])
            );
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }
    }

    /**
     * Delete a translation entry
     *
     * @param integer $id
     *
     * @throws QUI\Database\Exception
     */
    public static function deleteById(int $id): void
    {
        if (file_exists(VAR_DIR . 'locale/localefiles')) {
            unlink(VAR_DIR . 'locale/localefiles');
        }

        try {
            QUI::getDataBaseConnection()->delete(
                DoctrineUtils::quoteIdentifier(self::table()),
                self::quoteDbalArrayKeys(['id' => $id])
            );
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }
    }

    /**
     * Which languages are there
     *
     * @return list<string>
     *
     * @throws QUI\Exception
     */
    public static function langs(): array
    {
        $columns = self::introspectTranslatorTable()->getColumns();

        $fields = [];

        foreach ($columns as $column) {
            $fields[] = $column->getName();
        }

        $languages = [];

        foreach ($fields as $entry) {
            if (
                $entry == 'groups'
                || $entry == 'id'
                || $entry == 'var'
                || $entry == 'html'
                || $entry == 'datatype'
                || $entry == 'datadefine'
                || $entry == 'package'
                || $entry == 'priority'
            ) {
                continue;
            }

            if (str_contains($entry, '_edit')) {
                continue;
            }

            $languages[] = $entry;
        }

        return $languages;
    }

    /**
     * Returns the variables to be translated
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws DbalException
     * @throws QUI\Exception
     * @throws \Exception
     */
    public static function getNeedles(): array
    {
        $where = [];

        foreach (self::langs() as $lang) {
            $field = DoctrineUtils::quoteIdentifier($lang);
            $where[] = $field . ' = ' . QUI::getDataBaseConnection()->quote('');
        }

        return self::fetchDbal([
            'from' => self::table(),
            'where' => implode(' OR ', $where)
        ]);
    }

    /**
     * Parser Methoden
     */

    /**
     * @var array<int, array{groups: string, var: string}>
     */
    protected static array $tmp = [];

    /**
     * T Blöcke in einem String finden
     *
     * @param string $string
     *
     * @return array<int, array{groups: string, var: string}>
     */
    public static function getTBlocksFromString(string $string): array
    {
        if (!str_contains($string, '{/t}')) {
            return [];
        }

        self::$tmp = [];

        preg_replace_callback(
            '/{t([^}]*)}([^[{]*){\/t}/im',
            function ($params) {
                if (!empty($params[1])) {
                    $_params = explode(' ', trim($params[1]));
                    $_params = str_replace(['"', "'"], '', $_params);

                    $group = '';
                    $var = '';

                    foreach ($_params as $param) {
                        $_param = explode('=', $param);

                        if ($_param[0] == 'groups') {
                            $group = $_param[1];
                        }

                        if ($_param[0] == 'var') {
                            $var = $_param[1];
                        }
                    }

                    self::$tmp[] = [
                        'groups' => $group,
                        'var' => $var
                    ];

                    return ''; // phpstan
                }

                $_param = explode(' ', $params[2]);

                if (!str_contains($_param[0], '/') || str_contains($_param[1], ' ')) {
                    self::$tmp[] = [
                        'groups' => '',
                        'var' => $params[2]
                    ];
                }

                self::$tmp[] = [
                    'groups' => $_param[0],
                    'var' => $_param[1],
                ];

                return ''; // phpstan
            },
            $string
        );

        return self::$tmp;
    }

    /**
     * PHP Blöcke in einem String finden
     *
     * @param string $string
     *
     * @return array<int, array{groups: string, var: string}>
     */
    public static function getLBlocksFromString(string $string): array
    {
        if (!str_contains($string, '$L->get(') && !str_contains($string, '$Locale->get(')) {
            return [];
        }

        self::$tmp = [];

        preg_replace_callback(
            '/\$L(ocale)?->get\s*\(\s*\'([^)]*)\'\s*,\s*\'([^[)]*)\'\s*\)/im',
            function ($params) {
                if (
                    !empty($params[2])
                    && !empty($params[3])
                    && !str_contains($params[2], '/')
                ) {
                    self::$tmp[] = [
                        'groups' => $params[2],
                        'var' => $params[3],
                    ];
                }

                return ''; // phpstan
            },
            $string
        );

        return self::$tmp;
    }

    /**
     * Deletes double group-var entries
     *
     * @param array<int, array{groups: string, var: string}> $array
     *
     * @return array<int, array{groups: string, var: string}>
     */
    public static function deleteDoubleEntries(array $array): array
    {
        // Doppelte Einträge löschen
        $new_tmp = [];

        foreach ($array as $tmp) {
            if (!isset($new_tmp[$tmp['groups'] . $tmp['var']])) {
                $new_tmp[$tmp['groups'] . $tmp['var']] = $tmp;
            }
        }

        $array = [];

        foreach ($new_tmp as $tmp) {
            $array[] = $tmp;
        }

        return $array;
    }
}
