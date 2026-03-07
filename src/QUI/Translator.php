<?php

/**
 * This file contains QUI\Translator
 */

namespace QUI;

use DOMElement;
use PDO;
use QUI;
use QUI\Cache\Manager as CacheManager;
use QUI\Database\Exception;
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

        $Table = QUI::getDataBase()->table();

        if ($Table === null) {
            throw new QUI\Exception('Database table manager is not available');
        }

        // if column already exists, don't refresh locale
        $exists = $Table->existColumnInTable(self::table(), $lang);

        if ($exists) {
            return;
        }

        $Table->addColumn(
            self::table(),
            [
                $lang => 'text NULL',
                $lang . '_edit' => 'text NULL'
            ]
        );

        if (file_exists(VAR_DIR . 'locale/localefiles')) {
            unlink(VAR_DIR . 'locale/localefiles');
        }
    }

    /**
     * Export locale groups as xml
     *
     * @param string $group - which group should be exported? ("all" = Alle)
     * @param list<string> $langs - languages
     * @param string $type - "original" oder "edit"
     * @param bool $external (optional) - export translations of external groups
     * that are overwritten by the selected groups ($group) [default: false]
     *
     * @return string
     *
     * @throws QUI\DataBase\Exception
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

        // Check xml format
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

        $PDO = QUI::getDataBase()->getPDO();

        if ($PDO === null) {
            throw new QUI\Exception('Database PDO connection is not available');
        }

        set_time_limit((int)ini_get('max_execution_time'));

        $localeVariables = [];

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

                // Add locale variable to the batch
                $localeVariable = [
                    'group' => $group,
                    'var' => $var,
                    'datatype' => $datatype,
                    'html' => $locale['html'],
                    'priority' => $locale['priority'],
                    'package' => $localePackageName
                ];

                foreach (self::langs() as $lang) {
                    if (isset($locale[$lang])) {
                        $localeVariable[$lang] = $locale[$lang];
                    }
                }

                // Add the data into the array using the key: group/variable
                $localeVariables[trim($group) . "/" . trim($var)] = $localeVariable;
            }
        }

        $sql = "";
        // ************************** //
        //           Update
        // ************************** //
        $currentRows = QUI::getDataBase()->fetch([
            "select" => [
                "id",
                "groups",
                "var",
            ],
            "from" => self::table(),
            "where" => [
                "package" => $packageName
            ]
        ]);

        foreach ($currentRows as $currentRow) {
            $varGroup = trim($currentRow['groups']);
            $varName = trim($currentRow['var']);

            // Check if this xml contains the locale variable.
            // if it does not contain it, skip it
            if (!isset($localeVariables[$varGroup . "/" . $varName])) {
                continue;
            }

            $var = $localeVariables[$varGroup . "/" . $varName];

            // Build a string containing all languages
            $updateFieldString = "";

            foreach (self::langs() as $langCode) {
                if (isset($var[$langCode])) {
                    $updateFieldString .= $langCode . "=" . $PDO->quote($var[$langCode]) . ", ";
                }
            }

            $updateFieldString .= "datatype=" . $PDO->quote($var['datatype']) . ", ";
            $updateFieldString = trim($updateFieldString, ", ");

            if (empty($updateFieldString)) {
                continue;
            }

            $sql .= "UPDATE " . self::table();
            $sql .= " SET ";
            $sql .= $updateFieldString;
            $sql .= " WHERE id=" . $PDO->quote($currentRow['id']) . ";";
            $sql .= PHP_EOL;

            unset($localeVariables[$varGroup . "/" . $varName]);
        }

        // ************************** //
        //           Insert
        // ************************** //

        // Add backticks to all languages
        $langColumns = array_map(function ($lang) {
            return "`$lang`";
        }, self::langs());

        $langColumns = implode(",", $langColumns);

        foreach ($localeVariables as $var) {
            //Check if at least one active language will be inserted
            // @TODO check if this part can be improved
            $containsActiveLanguage = false;

            foreach (self::langs() as $langCode) {
                if (isset($var[$langCode])) {
                    $containsActiveLanguage = true;
                }
            }

            if (!$containsActiveLanguage) {
                continue;
            }

            // Insert the locale variable
            $langValues = "";

            foreach (self::langs() as $langCode) {
                if (!isset($var[$langCode])) {
                    $langValues .= "null" . ",";
                    continue;
                }

                $langValues .= $PDO->quote($var[$langCode]) . ",";
            }

            $langValues = trim($langValues, ", ");

            $sql .= "INSERT INTO `" . self::table() . "` ";
            $sql .= " (`groups`, `var`, `datatype`, `html`, `priority`, `package`, " . $langColumns . ")";

            // Build the value clause VALUES('','',[...])
            $sql .= " VALUES (";
            $sql .= $PDO->quote($var['group']) . ",";
            $sql .= $PDO->quote($var['var']) . ",";
            $sql .= $PDO->quote($var['datatype']) . ",";
            $sql .= $PDO->quote($var['html']) . ",";
            $sql .= $PDO->quote($var['priority']) . ",";
            $sql .= $PDO->quote($var['package']) . ",";
            $sql .= $langValues;
            $sql .= ");";
            $sql .= PHP_EOL;
        }

        if (empty($sql)) {
            return true;
        }

        $result = $PDO->exec($sql);

        if ($result === false) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/translator', 'exception.batch.query.error', [
                    'file' => $file,
                    'error' => $PDO->errorInfo()[2]
                ])
            );
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
     * Return modify times of all imported locale xml files
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

        $require = ['Locale'];
        $result = [];

        $jsDir = self::dir() . 'bin/';
        $cacheFile = $jsDir . '_cache/' . $lang . '.js';
        $development = QUI::conf('globals', 'development');

        QUIFile::mkdir($jsDir . '_cache/');

        if (file_exists($cacheFile) && !$development) {
            return ['locale/_cache' => $cacheFile];
        }

        $dirs = QUIFile::readDir($jsDir);
        $cacheData = '';

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

                    if ($langContent !== false) {
                        $cacheData .= PHP_EOL . $langContent;
                    }

                    $require[] = 'locale/' . $dir . '/' . $package . '/' . $lang;
                }
            }
        }

        if ($development) {
            return $result;
        }

        $requireEncode = json_encode($require);

        if ($requireEncode === false) {
            $requireEncode = '[]';
        }

        $requireEncode = str_replace('\/', '/', $requireEncode);

        $cacheData .= "\n\n
            require($requireEncode);
            \n\n
            define('locale/_cache/$lang', $requireEncode, function(Locale) {return Locale})
        ";

        file_put_contents($cacheFile, $cacheData);

        return ['locale/_cache' => $cacheFile];
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
     * @throws QUI\DataBase\Exception
     */
    public static function cleanup(): void
    {
        $PDO = QUI::getDataBase()->getPDO();
        $table = self::table();

        if ($PDO === null) {
            throw new QUI\Exception('Database PDO connection is not available');
        }

        // check if dublicate entries exist
        $Statement = $PDO->prepare(
            'SELECT `groups`, `var`, `package`
            FROM ' . $table . '
            GROUP BY `groups`, `var`, `package`
            HAVING count( * ) > 1'
        );

        $Statement->execute();
        $result = $Statement->fetchAll(PDO::FETCH_ASSOC);

        if (empty($result)) {
            return;
        }

        $DB = QUI::getDataBase();

        foreach ($result as $row) {
            $duplicates = $DB->fetch([
                'select' => ['id'],
                'from' => $table,
                'where' => [
                    'groups' => $row['groups'],
                    'var' => $row['var'],
                    'package' => $row['package']
                ]
            ]);

            $duplicateIds = [];

            foreach ($duplicates as $duplicate) {
                $duplicateIds[] = $duplicate['id'];
            }

            if (empty($duplicateIds)) {
                continue;
            }

            $DB->delete($table, [
                'groups' => $row['groups'],
                'var' => $row['var'],
                'package' => $row['package'],
                'id' => [
                    'type' => 'NOT',
                    'value' => min($duplicateIds)
                ]
            ]);
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

            $result = QUI::getDataBase()->fetch([
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
            // damit die höchste Priorität zu letzt kommt und die davor überschreibt
            // Ist verwirrend, aber somit sparen wir ein Query

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

            // create javascript lang files
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

            $result = QUI::getDataBase()->fetch([
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
            // damit die höchste Priorität zu letzt kommt und die davor überschreibt
            // Ist verwirrend, aber somit sparen wir ein Query

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
     * @throws QUI\DataBase\Exception
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

        return QUI::getDataBase()->fetch([
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

        // PDO search emptyTranslations
        if ($search && isset($search['emptyTranslations']) && $search['emptyTranslations']) {
            $PDO = QUI::getPDO();
            $fields = [];

            // search empty translations
            if (!empty($search['fields'])) {
                $fields = array_flip($search['fields']);
            }

            $whereParts = [];

            foreach ($db_fields as $field) {
                if (!empty($fields) && !isset($fields[$field])) {
                    continue;
                }

                $whereParts[] = "(
                    ($field = '' OR $field IS NULL) AND
                    ({$field}_edit = '' OR {$field}_edit IS NULL)
                )";
            }

            $where = implode(' OR ', $whereParts);

            $querySelect = "
                SELECT *
                FROM $table
                WHERE $where
                LIMIT $limit
            ";

            $queryCount = "
                SELECT COUNT(*) as count
                FROM $table
                WHERE $where
            ";

            $Statement = $PDO->prepare($querySelect);
            $Statement->execute();
            $result = $Statement->fetchAll(PDO::FETCH_ASSOC);

            $Statement = $PDO->prepare($queryCount);
            $Statement->execute();
            $count = $Statement->fetchAll(PDO::FETCH_ASSOC);

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
            $result = QUI::getDataBase()->fetch($data);
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
            $count = QUI::getDataBase()->fetch($data);
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
            $result = QUI::getDataBase()->fetch([
                'from' => self::table(),
                'where' => $where,
                'limit' => 1
            ]);
        } catch (QUI\Database\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return [];
        }

        if (!isset($result[0])) {
            return [];
        }

        return $result[0];
    }

    /**
     * List of all existing groups
     *
     * @return list<string>
     */
    public static function getGroupList(): array
    {
        try {
            $result = QUI::getDataBase()->fetch([
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

        // insert data
        QUI::getDataBase()->insert(
            self::table(),
            [
                'groups' => $group,
                'var' => $var,
                'package' => !empty($package) ? $package : '',
                'datatype' => implode(',', $types),
                'html' => $html ? 1 : 0
            ]
        );
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
     * IS DIFFERENT TO edit() => edit() = Normal behaviour
     *
     * @param string $group
     * @param string $var
     * @param string $packageName
     * @param array<string, mixed> $data
     *
     * @throws QUI\Exception
     * @throws QUI\DataBase\Exception
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

        QUI::getDataBase()->update(self::table(), $_data, [
            'groups' => $group,
            'var' => $var,
            'package' => $packageName ?: $group
        ]);

        QUI::getEvents()->fireEvent('quiqqerTranslatorUpdate', [$group, $var, $packageName, $data]);
    }

    /**
     * User Edit - Updates a translation var entry
     *  edit() = normal behaviour
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
     * @throws QUI\DataBase\Exception
     */
    public static function edit(string $group, string $var, string $packageName, array $data): void
    {
        QUI::getDataBase()->update(self::table(), self::getEditData($data), [
            'groups' => $group,
            'var' => $var,
            'package' => $packageName ?: $group
        ]);

        QUI::getEvents()->fireEvent('quiqqerTranslatorEdit', [$group, $var, $packageName, $data]);
    }

    /**
     * User Edit with an entry id
     *
     * @param integer $id
     * @param array<string, mixed> $data
     *
     * @throws QUI\Exception
     * @throws QUI\DataBase\Exception
     */
    public static function editById(int $id, array $data): void
    {
        QUI::getDataBase()->update(self::table(), self::getEditData($data), [
            'id' => $id
        ]);

        QUI::getEvents()->fireEvent('quiqqerTranslatorEditById', [$id, $data]);
    }

    /**
     * Prepares the data for a translation entry
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
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
     * @throws QUI\DataBase\Exception
     */
    public static function delete(string $group, string $var): void
    {
        if (file_exists(VAR_DIR . 'locale/localefiles')) {
            unlink(VAR_DIR . 'locale/localefiles');
        }

        QUI::getDataBase()->delete(
            self::table(),
            [
                'groups' => $group,
                'var' => $var
            ]
        );
    }

    /**
     * Delete a translation entry
     *
     * @param integer $id
     *
     * @throws QUI\DataBase\Exception
     */
    public static function deleteById(int $id): void
    {
        if (file_exists(VAR_DIR . 'locale/localefiles')) {
            unlink(VAR_DIR . 'locale/localefiles');
        }

        QUI::getDataBase()->delete(
            self::table(),
            ['id' => $id]
        );
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
        $Table = QUI::getDataBase()->table();

        if ($Table === null) {
            throw new QUI\Exception('Database table manager is not available');
        }

        $fields = $Table->getColumns(self::table());

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
     * @throws QUI\DataBase\Exception
     */
    public static function getNeedles(): array
    {
        return QUI::getDataBase()->fetch([
            'from' => self::table(),
            'where' => implode(' = "" OR ', self::langs()) . ' = ""'
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
