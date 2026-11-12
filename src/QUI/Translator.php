<?php

/**
 * This file contains QUI\Translator
 */

namespace QUI;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Query\QueryBuilder;
use DOMElement;
use QUI;
use QUI\Cache\Manager as CacheManager;
use QUI\Database\Exception;
use QUI\Translator\DoctrineHelper as DoctrineUtils;
use QUI\Translator\LegacyPublicationCleanup;
use QUI\Translator\PublicationTransaction;
use QUI\Utils\StringHelper;
use QUI\Utils\LocaleContext;
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
     * @var list<string>|null
     */
    protected static ?array $languages = null;

    protected static ?Connection $languagesConnection = null;

    protected static ?string $languagesTable = null;

    /**
     * @var string|null
     */
    protected static ?string $localePublishVersion = null;

    private static ?Connection $contextSchemaConnection = null;

    private static ?string $contextSchemaTable = null;

    /**
     * Ensure locale imports also work when another package is set up before Translator.
     */
    public static function ensureContextSchema(): void
    {
        $Connection = QUI::getDataBaseConnection();
        $table = self::table();

        if (self::$contextSchemaConnection === $Connection && self::$contextSchemaTable === $table) {
            return;
        }

        $directory = self::dir();
        QUIFile::mkdir($directory);
        $lock = fopen($directory . '.context-schema.lock', 'c');

        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new QUI\Exception('Unable to lock translator schema migration');
        }

        try {
            $Table = self::introspectTranslatorTable();

            if ($Table->hasColumn('context')) {
                self::$contextSchemaConnection = $Connection;
                self::$contextSchemaTable = $table;
                return;
            }

            QUI::getSchemaManager()->alterTable(new TableDiff($Table, addedColumns: [
                new Column('context', Type::getType('text'), ['notnull' => false])
            ]));
            self::$languages = null;
            self::$localeModifyTimes = [];
            self::$contextSchemaConnection = $Connection;
            self::$contextSchemaTable = $table;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $locale */
    private static function importContext(array $locale, string $group, string $package): ?string
    {
        if (array_key_exists('context', $locale)) {
            return LocaleContext::normalize($locale['context']);
        }

        return $package === '' || $package === $group ? '' : null;
    }

    /** @return array<string, string> File paths mapped to their owning package. */
    private static function getPackageLocaleFiles(QUI\Package\Package $Package): array
    {
        $file = $Package->getXMLFilePath('locale.xml');

        if (!is_string($file) || !is_file($file)) {
            return [];
        }

        $files = [$file => $Package->getName()];
        $Dom = XML::getDomFromXml($file);

        foreach ($Dom->getElementsByTagName('file') as $File) {
            $path = $Package->getDir() . ltrim($File->getAttribute('file'), '/');

            if (is_file($path)) {
                $files[$path] = $File->hasAttribute('package')
                    ? $File->getAttribute('package') : $Package->getName();
            }
        }

        return $files;
    }

    /**
     * Validate the complete import before any row is changed. Each language file
     * must declare the same metadata, including missing versus explicit context.
     *
     * @param array<string, string> $files
     */
    private static function validateLocaleContexts(array $files): void
    {
        $contexts = [];

        foreach ($files as $file => $package) {
            $groups = XML::getLocaleGroupsFromDom(XML::getDomFromXml($file));

            foreach ($groups as $group) {
                foreach ($group['locales'] as $locale) {
                    $owner = $package !== '' ? $package : ($locale['package'] ?? '');
                    $key = json_encode([$group['group'], $locale['name'], $owner], JSON_THROW_ON_ERROR);
                    $context = self::importContext($locale, $group['group'], $owner);

                    if (array_key_exists($key, $contexts) && $contexts[$key] !== $context) {
                        throw new QUI\Exception('Conflicting locale contexts for ' . $key . ' in ' . $file);
                    }

                    $contexts[$key] = $context;
                }
            }
        }
    }

    /**
     * Return the active publication manifest. An absent manifest means
     * the regular setup has not published contexts yet.
     *
     * @return array{version: string, contexts: list<string>, languages: list<string>, bundledContexts?: list<string>, format?: int}
     */
    public static function getJSContextManifest(): array
    {
        PublicationTransaction::recoverIfNeeded(self::dir(), self::finishPublication(...));
        $file = self::dir() . 'bin/_contexts/manifest.json';

        if (!is_file($file)) {
            return ['version' => '', 'contexts' => [], 'languages' => []];
        }

        $manifest = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($manifest) || !isset($manifest['version'], $manifest['contexts'], $manifest['languages'])) {
            throw new QUI\Exception('Invalid locale context manifest');
        }

        return $manifest;
    }

    /**
     * Return one bundle for standard contexts, or common and custom context modules.
     * Existing getJSTranslationFiles() remains the complete legacy collection.
     * Context selection requires a publication created by the regular setup.
     *
     * @param array{version: string, contexts: list<string>, languages: list<string>, bundledContexts?: list<string>, format?: int}|null $manifest
     * @return array<string, string>
     */
    public static function getJSContextTranslationFiles(
        string $lang,
        string $context,
        ?array $manifest = null
    ): array {
        $context = LocaleContext::normalize($context);

        if ($context === '' || str_contains($context, ',')) {
            throw new \InvalidArgumentException('Exactly one locale context is required');
        }

        $manifest ??= self::getJSContextManifest();

        if ($manifest['version'] === '') {
            throw new QUI\Exception('Locale contexts have not been published; run the regular setup');
        }

        if (!in_array($context, $manifest['contexts'], true)) {
            throw new \InvalidArgumentException('Unknown locale context: ' . $context);
        }

        if (!in_array($lang, $manifest['languages'], true)) {
            return [];
        }

        $result = [];
        $tokens = ['common', 'c' . bin2hex($context)];

        if (in_array($context, $manifest['bundledContexts'] ?? [], true)) {
            $tokens = ['b' . bin2hex($context)];
        }

        foreach ($tokens as $token) {
            $prefix = 'locale/_contexts/'
                . (($manifest['format'] ?? 1) === 2 ? '' : $manifest['version'] . '/') . $token;
            $result[$prefix] = self::dir() . 'bin/' . substr($prefix, 7) . '/' . $lang . '.js';
        }

        return $result;
    }

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

        if (isset($query['order']) && is_array($query['order'])) {
            foreach ($query['order'] as $field => $direction) {
                $QueryBuilder->addOrderBy(DoctrineUtils::quoteIdentifier($field), $direction);
            }
        } elseif (!empty($query['order'])) {
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

        $Table = self::introspectTranslatorTable();
        $addedColumns = [];

        if (!$Table->hasColumn($lang)) {
            $addedColumns[] = new Column($lang, Type::getType('text'), [
                'notnull' => false
            ]);
        }

        $editLang = $lang . '_edit';

        if (!$Table->hasColumn($editLang)) {
            $addedColumns[] = new Column($editLang, Type::getType('text'), [
                'notnull' => false
            ]);
        }

        if (empty($addedColumns)) {
            return;
        }

        try {
            self::$languages = null;
            QUI::getSchemaManager()->alterTable(new TableDiff($Table, addedColumns: $addedColumns));
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

                    if (isset($entry['context'])) {
                        $result .= ' context="' . htmlspecialchars($entry['context'], ENT_QUOTES | ENT_XML1) . '"';
                    }

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

        self::ensureContextSchema();
        self::validateLocaleContexts([$file => $packageName]);
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

                $locale['context'] = self::importContext($locale, $group, $localePackageName);

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
                        'context' => $locale['context'],
                        'html' => $locale['html'],
                        'priority' => $locale['priority'],
                        'package' => $localePackageName
                    ];

                    unset($locale['html']);
                    unset($locale['priority']);
                    unset($locale['id'], $locale['context'], $locale['package']);

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

        self::validateLocaleContexts(self::getPackageLocaleFiles($Package));
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
     * The locale.xml will be imported with DBAL update and bulk insert operations.
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
        self::ensureContextSchema();
        self::validateLocaleContexts([$file => $packageName]);
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
                    'context' => self::importContext($locale, $group, $localePackageName),
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

                $identity = json_encode([trim($group), trim($var), $localePackageName], JSON_THROW_ON_ERROR);
                $localeVariables[$identity] = $localeVariable;
            }
        }

        $hasOperations = false;
        $table = DoctrineUtils::quoteIdentifier(self::table());

        try {
            $packages = array_values(array_unique(array_column($localeVariables, 'package')));
            $currentRows = $Connection->createQueryBuilder()
                ->select(...array_map(DoctrineUtils::quoteIdentifier(...), ['id', 'groups', 'var', 'package']))
                ->from($table)
                ->where(DoctrineUtils::quoteIdentifier('package') . ' IN (:packages)')
                ->setParameter('packages', $packages, \Doctrine\DBAL\ArrayParameterType::STRING)
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($currentRows as $currentRow) {
                $varGroup = trim($currentRow['groups']);
                $varName = trim($currentRow['var']);
                $identity = json_encode([$varGroup, $varName, $currentRow['package']], JSON_THROW_ON_ERROR);

                if (!isset($localeVariables[$identity])) {
                    continue;
                }

                $var = $localeVariables[$identity];
                $updateData = [];

                foreach ($languages as $langCode) {
                    if (isset($var[$langCode])) {
                        $updateData[$langCode] = $var[$langCode];
                    }
                }

                $updateData['context'] = $var['context'];
                $updateData['datatype'] = $var['datatype'];
                $updateData['html'] = $var['html'];
                $updateData['priority'] = $var['priority'];

                $hasOperations = true;
                $Connection->update($table, self::quoteDbalArrayKeys($updateData), self::quoteDbalArrayKeys([
                    'id' => $currentRow['id']
                ]));

                unset($localeVariables[$identity]);
            }

            $insertColumns = array_merge(
                ['groups', 'var', 'datatype', 'html', 'priority', 'package', 'context'],
                $languages
            );
            $insertRows = [];

            foreach ($localeVariables as $var) {
                $containsActiveLanguage = false;
                $insertData = [
                    'groups' => $var['group'],
                    'var' => $var['var'],
                    'context' => $var['context'],
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

        self::validateLocaleContexts(self::getPackageLocaleFiles($Package));

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
            json_encode(['format' => 2, 'files' => self::$localeModifyTimes])
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

        self::$localeModifyTimes = is_array($list) && ($list['format'] ?? null) === 2
            ? ($list['files'] ?? []) : [];

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
     * @param bool $refreshCache Rebuild the bundle while keeping the previous file available.
     *
     * @return array<string, string>
     */
    public static function getJSTranslationFiles(string $lang, bool $refreshCache = false): array
    {
        return PublicationTransaction::withLock(
            self::dir(),
            static fn (): array => self::getJSTranslationFilesUnlocked($lang, $refreshCache),
            self::finishPublication(...)
        );
    }

    /** @return array<string, string> */
    private static function getJSTranslationFilesUnlocked(string $lang, bool $refreshCache): array
    {
        if (strlen($lang) !== 2) {
            return [];
        }

        $result = [];

        $jsDir = self::dir() . 'bin/';
        $cacheFile = $jsDir . '_cache/' . $lang . '.js';
        $development = QUI::conf('globals', 'development');

        QUIFile::mkdir($jsDir . '_cache/');

        if (file_exists($cacheFile) && !$development && !$refreshCache) {
            return ['locale/_cache' => $cacheFile];
        }

        $dirs = QUIFile::readDir($jsDir);
        $localeSetCalls = [];

        foreach ($dirs as $dir) {
            if ($dir === '_cache' || $dir === '_contexts') {
                continue;
            }

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

        if ($development && !$refreshCache) {
            return $result;
        }

        $cacheData = "define('locale/_cache/$lang', ['Locale'], function(Locale) {";
        $cacheData .= PHP_EOL . implode(PHP_EOL, $localeSetCalls);
        $cacheData .= PHP_EOL . 'return Locale;';
        $cacheData .= PHP_EOL . '});';

        self::writeJavaScriptFile($cacheFile, $cacheData);

        return ['locale/_cache' => $cacheFile];
    }

    /**
     * Replace a complete JavaScript file without exposing a missing or partial file to readers.
     *
     * @throws QUI\Exception
     */
    private static function writeJavaScriptFile(string $file, string $content): void
    {
        PublicationTransaction::writeFile($file, $content);
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
        $manifest = self::getJSContextManifest();

        if ($manifest['version'] !== '') {
            return $manifest['version'];
        }

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
            $languages = array_merge($languages, (array)$Project->getAttribute('langs'));
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

    /** Publish the complete collection using stable context paths. */
    public static function create(): void
    {
        self::publishCollection(null);
    }

    /** Publish a group; all JavaScript views share one effective collection. */
    public static function publish(string $group): void
    {
        self::publishCollection($group);
    }

    /**
     * Backfill metadata from unchanged package definitions during a partial update.
     * Text and individual edit columns are deliberately not part of this migration.
     * The first committed context manifest marks successful completion.
     */
    private static function importExistingContextMetadata(): void
    {
        $rows = self::get();
        $packages = [];

        foreach ($rows as $row) {
            $package = $row['package'] ?: $row['groups'];
            $packages[$package] = true;
        }

        $files = [];

        foreach (array_keys($packages) as $package) {
            try {
                $Package = QUI::getPackageManager()->getInstalledPackage($package);
            } catch (QUI\Exception) {
                continue;
            }

            $files += self::getPackageLocaleFiles($Package);
        }

        self::validateLocaleContexts($files);
        $definitions = [];

        foreach ($files as $file => $package) {
            foreach (XML::getLocaleGroupsFromDom(XML::getDomFromXml($file)) as $group) {
                foreach ($group['locales'] as $locale) {
                    $definitions[$package][$group['group']][$locale['name']] = self::importContext(
                        $locale,
                        $group['group'],
                        $package
                    );
                }
            }
        }

        $Connection = QUI::getDataBaseConnection();
        $Connection->transactional(static function (Connection $Connection) use ($rows, $definitions): void {
            foreach ($rows as $row) {
                $package = $row['package'] ?: $row['groups'];
                $group = $row['groups'];
                $key = $row['var'];
                $entries = $definitions[$package][$group] ?? [];

                if (!array_key_exists($key, $entries) || $row['context'] !== null) {
                    continue;
                }

                $Connection->createQueryBuilder()
                    ->update(DoctrineUtils::quoteIdentifier(self::table()))
                    ->set(DoctrineUtils::quoteIdentifier('context'), ':context')
                    ->where(DoctrineUtils::quoteIdentifier('id') . ' = :id')
                    ->andWhere(DoctrineUtils::quoteIdentifier('context') . ' IS NULL')
                    ->setParameter('context', $entries[$key])
                    ->setParameter('id', $row['id'])
                    ->executeStatement();
            }
        });
    }

    private static function publishCollection(?string $group): void
    {
        self::ensureContextSchema();
        PublicationTransaction::withLock(self::dir(), static function () use ($group): void {
            $languages = self::langs();
            $previous = self::getJSContextManifest();

            if ($previous['version'] === '') {
                self::importExistingContextMetadata();
            }

            $snapshot = self::readPublicationSnapshot($previous['version'], ($previous['format'] ?? 1) === 2);
            $complete = $group === null || $snapshot === null;

            if ($complete) {
                self::cleanup();
            }

            $query = [
                'from' => self::table(),
                'order' => ['priority' => 'ASC', 'package' => 'ASC', 'id' => 'ASC']
            ];

            if (!$complete) {
                $query['where'] = ['groups' => $group];
            }

            $freshRows = self::preparePublicationRows(array_values(self::fetchDbal($query)), $languages);
            $rows = $freshRows;

            if (!$complete) {
                $rows = array_values(array_filter(
                    $snapshot,
                    static fn (array $row): bool => $row['groups'] !== $group
                ));
                $rows = array_merge($rows, $freshRows);
            }

            $collection = QUI\Translator\ContextCollection::build($rows, $languages, $previous['contexts']);
            $version = self::createLocalePublishVersion();
            $Publication = new PublicationTransaction(self::dir());
            $hasCustomContexts = array_diff($collection['contexts'], ['frontend', 'administration']) !== [];

            foreach ($collection['modules'] as $language => $contexts) {
                $sources = [];

                foreach ($contexts as $context => $groups) {
                    $token = $context === 'common' ? 'common' : 'c' . bin2hex($context);
                    $module = 'locale/_contexts/' . $token . '/' . $language;
                    $file = self::dir() . 'bin/_contexts/' . $token . '/' . $language . '.js';
                    $payload = json_encode([
                        'language' => $language,
                        'version' => $version,
                        'context' => $context,
                        'groups' => (object)$groups
                    ], JSON_THROW_ON_ERROR);
                    $content = "define('" . $module . "', ['Locale'], function(Locale){var data=" . $payload . ';';
                    $content .= 'Object.keys(data.groups).forEach(function(group){';
                    $content .= 'Locale.set(data.language,group,data.groups[group]);});return data;});';
                    // Standard modules are embedded in their bundles. Custom loading also needs a common fallback.
                    if (
                        !in_array($context, ['frontend', 'administration'], true)
                        && ($context !== 'common' || $hasCustomContexts)
                    ) {
                        $Publication->stage($file, $content);
                    }
                    $sources[$context] = $content;
                }

                foreach (['frontend', 'administration'] as $context) {
                    $prefix = 'locale/_contexts/';
                    $token = 'b' . bin2hex($context);
                    $module = $prefix . $token . '/' . $language;
                    $dependencies = [
                        $prefix . 'common/' . $language,
                        $prefix . 'c' . bin2hex($context) . '/' . $language
                    ];
                    $content = $sources['common'] . "\n" . $sources[$context] . "\n";
                    $content .= 'define(' . json_encode($module, JSON_THROW_ON_ERROR) . ',';
                    $content .= json_encode($dependencies, JSON_THROW_ON_ERROR);
                    $content .= ',function(common,context){return context;});';
                    $file = self::dir() . 'bin/_contexts/' . $token . '/' . $language . '.js';
                    $Publication->stage($file, $content);
                }
            }

            if ($complete) {
                self::createLegacyFiles($freshRows, $languages, $Publication);
            } else {
                self::publishLegacyFiles($group, $freshRows, $languages, $Publication);
            }

            self::writeLegacyCollection($collection['legacy'], $languages, $Publication);
            $Publication->stage(
                self::dir() . '.context-snapshot.json',
                json_encode(['format' => 1, 'version' => $version, 'rows' => $rows], JSON_THROW_ON_ERROR)
            );
            $manifest = [
                'format' => 2,
                'version' => $version,
                'contexts' => $collection['contexts'],
                'languages' => $languages,
                'bundledContexts' => ['frontend', 'administration']
            ];
            $manifestFile = self::dir() . 'bin/_contexts/manifest.json';
            $Publication->stage($manifestFile, json_encode($manifest, JSON_THROW_ON_ERROR));
            $Publication->commit($manifestFile);
            self::finishPublication();
            $Publication->complete();
            LegacyPublicationCleanup::run(self::dir());
        }, self::finishPublication(...));
    }

    /**
     * Store the already prepared values, never reconstruct context metadata from JS.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function readPublicationSnapshot(string $version, bool $fixedPaths): ?array
    {
        if ($version === '') {
            return null;
        }

        $file = self::dir() . ($fixedPaths ? '.context-snapshot.json' : '.context-snapshots/' . $version . '.json');

        if (!is_file($file)) {
            if ($fixedPaths || is_dir(self::dir() . '.context-snapshots')) {
                throw new QUI\Exception('Published locale snapshot is missing');
            }

            return null;
        }

        $snapshot = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        if (
            !is_array($snapshot)
            || ($snapshot['format'] ?? null) !== 1
            || !is_array($snapshot['rows'] ?? null)
            || ($fixedPaths && ($snapshot['version'] ?? null) !== $version)
        ) {
            throw new QUI\Exception('Invalid published locale snapshot');
        }

        return array_values($snapshot['rows']);
    }

    /**
     * Resolve individual text and Output hooks once for both PHP and JavaScript.
     * Previously published rows are passed through the snapshot unchanged.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $languages
     * @return list<array<string, mixed>>
     */
    private static function preparePublicationRows(array $rows, array $languages): array
    {
        $Output = class_exists('QUI\Output') ? new Output() : null;

        foreach ($rows as &$row) {
            foreach ($languages as $language) {
                $edit = $row[$language . '_edit'] ?? '';
                $value = self::isEmpty($edit) ? ($row[$language] ?? '') : $edit;
                $value = is_array($value) ? '' : (string)$value;

                if (!self::isEmpty($value) && $Output !== null) {
                    $value = $Output->parse($value);
                    $value = str_replace(['%5B', '%5D'], ['[', ']'], $value);
                }

                $row[$language] = $value;
                $row[$language . '_edit'] = '';
            }
        }

        return $rows;
    }

    /** Cache work runs only after the manifest commit and is retried after interruption. */
    private static function finishPublication(): void
    {
        $manifest = self::getJSContextManifest();
        self::$localePublishVersion = $manifest['version'];
        self::saveLocalePublishVersion($manifest['version']);
        QUI::getLocale()->refresh();
        QUI\Cache\Manager::clearCompleteQuiqqerCache();
        QUI::getEvents()->fireEvent('quiqqerTranslatorPublish');
    }

    /**
     * Keep legacy paths alive, including empty modules for removed groups.
     *
     * @param array<string, array<string, array<string, string>>> $collection
     * @param list<string> $languages
     */
    private static function writeLegacyCollection(
        array $collection,
        array $languages,
        PublicationTransaction $Publication
    ): void {
        $directory = self::dir() . 'bin/';
        $groups = [];

        foreach ($collection as $entries) {
            foreach (array_keys($entries) as $group) {
                $groups[$group] = true;
            }
        }

        foreach (glob($directory . '*/*', GLOB_ONLYDIR) ?: [] as $path) {
            $relative = substr($path, strlen($directory));

            if (!str_starts_with($relative, '_')) {
                $groups[$relative] = true;
            }
        }

        foreach ($languages as $language) {
            $calls = [];

            foreach (array_keys($groups) as $group) {
                $values = $collection[$language][$group] ?? [];
                $call = 'Locale.set(' . json_encode($language) . ', ' . json_encode($group) . ', '
                    . json_encode((object)$values, JSON_THROW_ON_ERROR) . ');';
                $module = 'locale/' . $group . '/' . $language;
                $file = $directory . $group . '/' . $language . '.js';
                $Publication->stage(
                    $file,
                    'define(' . json_encode($module) . ", ['Locale'], function(Locale){" . $call . '});'
                );

                if ($values !== []) {
                    $calls[] = $call;
                }
            }

            $Publication->stage(
                $directory . '_cache/' . $language . '.js',
                "define('locale/_cache/" . $language . "', ['Locale'], function(Locale){"
                    . implode("\n", $calls) . 'return Locale;});'
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $languages
     */
    private static function createLegacyFiles(
        array $rows,
        array $languages,
        PublicationTransaction $Publication
    ): void {
        $dir = self::dir();

        // Sprach Ordner erstellen
        $folders = [];

        foreach ($languages as $lang) {
            $lcMessagePath = $dir . '/' . StringHelper::toLower($lang);
            //$lcMessagePath .= '_' . StringHelper::toUpper($lang);
            $lcMessagePath .= '/LC_MESSAGES/';

            $folders[$lang] = $lcMessagePath;
        }


        // Sprachdateien erstellen
        foreach ($languages as $lang) {
            set_time_limit((int)ini_get('max_execution_time'));

            if (strlen($lang) !== 2) {
                continue;
            }

            $result = $rows;

            // priority ASC Erklärung:
            // Wir müssen den kleinsten zuerst nehmen,
            // damit die höchste Priorität zuletzt kommt und die davor überschreibt,
            // ist verwirrend, aber somit sparen wir ein Query

            $iniContents = [];

            foreach ($result as $entry) {
                if (self::isEmpty($entry[$lang]) && self::isEmpty($entry[$lang . '_edit'])) {
                    continue;
                }

                if ($entry['datatype'] == 'js') {
                    continue;
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


                $value = str_replace('\\', '\\\\', $value);
                $value = str_replace('"', '\"', $value);
                $value = str_replace("\n", '{\n}', $value);

                if ($value !== '' && $value !== ' ') {
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
                $iniContents[$ini] ??= '';
                $iniContents[$ini] .= $iniVar . '= "' . $value . '"' . "\n";
            }

            foreach (glob($folders[$lang] . '*.ini.php') ?: [] as $ini) {
                $iniContents[$ini] ??= '';
            }

            foreach ($iniContents as $ini => $content) {
                $Publication->stage($ini, $content);
            }

            unset($iniContents);
        }
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
     * @param list<array<string, mixed>> $rows
     * @param list<string> $languages
     */
    private static function publishLegacyFiles(
        string $group,
        array $rows,
        array $languages,
        PublicationTransaction $Publication
    ): void {
        $dir = self::dir();

        foreach ($languages as $lang) {
            if (strlen($lang) !== 2) {
                continue;
            }

            $folder = $dir . '/';
            $folder .= StringHelper::toLower($lang); //. '_' . StringHelper::toUpper($lang);
            $folder .= '/LC_MESSAGES/';


            $result = array_filter($rows, static fn (array $row): bool => $row['groups'] === $group);

            // priority ASC Erklärung:
            // Wir müssen den kleinsten zuerst nehmen,
            // damit die höchste Priorität zuletzt kommt und die davor überschreibt,
            // ist verwirrend, aber somit sparen wir ein Query

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


                if ($data['datatype'] == 'js') {
                    continue;
                }

                $value = str_replace('\\', '\\\\', $value);
                $value = str_replace('"', '\"', $value);
                $value = str_replace("\n", '{\n}', $value);

                if ($value !== ' ') {
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
                $iniValue = $value;
                $iniContent .= $iniVar . '= "' . $iniValue . '"' . PHP_EOL;
            }

            // set data
            $iniFile = $folder . str_replace('/', '_', $group) . '.ini.php';

            $Publication->stage($iniFile, $iniContent);
        }
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
                'datadefine' => $whereSearch,
                'context' => $whereSearch
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
        bool | int $html = false,
        ?string $context = null
    ): void {
        self::ensureContextSchema();
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
                    'html' => $html ? 1 : 0,
                    'context' => $context === null ? null : LocaleContext::normalize($context)
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
        self::ensureContextSchema();
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

        if (array_key_exists('context', $data)) {
            $_data['context'] = $data['context'] === null ? null : LocaleContext::normalize($data['context']);
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
        self::ensureContextSchema();
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
        self::ensureContextSchema();
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

        if (array_key_exists('context', $data)) {
            $_data['context'] = $data['context'] === null ? null : LocaleContext::normalize($data['context']);
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
        $Connection = QUI::getDataBaseConnection();
        $table = self::table();

        if (
            self::$languages !== null
            && self::$languagesConnection === $Connection
            && self::$languagesTable === $table
        ) {
            return self::$languages;
        }

        if ($table === '') {
            throw new QUI\Exception('Database table name is not available');
        }

        try {
            $SchemaManager = QUI::getSchemaManager();

            // @phpstan-ignore function.alreadyNarrowedType
            if (method_exists($SchemaManager, 'introspectTableColumnsByUnquotedName')) {
                $columns = $SchemaManager->introspectTableColumnsByUnquotedName($table);
            } else {
                $columns = $SchemaManager->listTableColumns($table);
            }

            if ($columns === []) {
                throw TableDoesNotExist::new($table);
            }
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }

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
                || $entry == 'context'
            ) {
                continue;
            }

            if (str_contains($entry, '_edit')) {
                continue;
            }

            $languages[] = $entry;
        }

        self::$languages = $languages;
        self::$languagesConnection = $Connection;
        self::$languagesTable = $table;

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
