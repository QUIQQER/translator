<?php

namespace QUITests\Translator;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Translator\DoctrineHelper as DoctrineUtils;
use QUI\Translator;
use ReflectionClass;
use Throwable;

class TranslatorTest extends TestCase
{
    public function testGetTBlocksFromStringWithAttributes(): void
    {
        $string = '{t groups="quiqqer/translator" var="message.test"}ignored{/t}';

        $result = Translator::getTBlocksFromString($string);

        $this->assertSame([
            [
                'groups' => 'quiqqer/translator',
                'var' => 'message.test'
            ]
        ], $result);
    }

    public function testGetTBlocksFromStringWithGroupVarText(): void
    {
        $string = '{t}quiqqer/translator message.test{/t}';

        $result = Translator::getTBlocksFromString($string);

        $this->assertSame([
            [
                'groups' => 'quiqqer/translator',
                'var' => 'message.test'
            ]
        ], $result);
    }

    public function testGetLBlocksFromStringFindsLocaleCalls(): void
    {
        $string = <<<'PHP'
<?php
echo $L->get('quiqqer/translator', 'message.one');
echo $Locale->get('quiqqer/translator', 'message.two');
echo $L->get('translator', 'message.three');
echo $Locale->get('translator', 'message.four');
PHP;

        $result = Translator::getLBlocksFromString($string);

        $this->assertSame([
            [
                'groups' => 'translator',
                'var' => 'message.three'
            ],
            [
                'groups' => 'translator',
                'var' => 'message.four'
            ]
        ], $result);
    }

    public function testDeleteDoubleEntriesRemovesDuplicatesByGroupAndVar(): void
    {
        $entries = [
            ['groups' => 'a/b', 'var' => 'x'],
            ['groups' => 'a/b', 'var' => 'x'],
            ['groups' => 'a/b', 'var' => 'y']
        ];

        $result = Translator::deleteDoubleEntries($entries);

        $this->assertCount(2, $result);
        $this->assertSame([
            ['groups' => 'a/b', 'var' => 'x'],
            ['groups' => 'a/b', 'var' => 'y']
        ], $result);
    }

    public function testEmptyJavaScriptLocaleFilesAreDetected(): void
    {
        $RefClass = new ReflectionClass(Translator::class);
        $Method = $RefClass->getMethod('isEmptyJavaScriptLocaleFile');

        $this->assertTrue($Method->invoke(
            null,
            <<<'JS'
define('locale/vendor/package/en', ['Locale'], function(Locale){Locale.set("en", "vendor/package", [])});
JS
        ));

        $this->assertTrue($Method->invoke(
            null,
            <<<'JS'
define('locale/vendor/package/en', ['Locale'], function(Locale){Locale.set("en", "vendor/package", {})});
JS
        ));

        $this->assertFalse($Method->invoke(
            null,
            <<<'JS'
define('locale/vendor/package/en', ['Locale'], function(Locale){Locale.set("en", "vendor/package", {"key":"value"})});
JS
        ));
    }

    public function testJavaScriptLocaleSetCallIsExtracted(): void
    {
        $RefClass = new ReflectionClass(Translator::class);
        $Method = $RefClass->getMethod('getJavaScriptLocaleSetCall');

        $this->assertSame(
            'Locale.set("en", "vendor/package", {"key":"value"});',
            $Method->invoke(
                null,
                <<<'JS'
define('locale/vendor/package/en', ['Locale'], function(Locale){Locale.set("en", "vendor/package", {"key":"value"})});
JS
            )
        );
    }

    public function testLocalePublishVersionIsPersistedInPackageConfig(): void
    {
        $Package = QUI::getPackage('quiqqer/translator');
        $Config = $Package->getConfig();

        if (!$Config) {
            $this->markTestSkipped('Package config is not available.');
        }

        $oldVersion = (string)$Config->get('locale', 'publishVersion');
        $RefClass = new ReflectionClass(Translator::class);
        $versionProperty = $RefClass->getProperty('localePublishVersion');
        $versionProperty->setValue(null, null);

        try {
            $version1 = Translator::getLocalePublishVersion();
            $this->assertNotSame('', $version1);

            $versionProperty->setValue(null, null);
            $version2 = Translator::getLocalePublishVersion();
            $this->assertSame($version1, $version2);
        } finally {
            $Config->set('locale', 'publishVersion', $oldVersion);
            $Config->save();
            $versionProperty->setValue(null, null);
        }
    }

    public function testRefreshLocalePublishVersionWritesNewConfigValue(): void
    {
        $Package = QUI::getPackage('quiqqer/translator');
        $Config = $Package->getConfig();

        if (!$Config) {
            $this->markTestSkipped('Package config is not available.');
        }

        $oldVersion = (string)$Config->get('locale', 'publishVersion');
        $RefClass = new ReflectionClass(Translator::class);
        $versionProperty = $RefClass->getProperty('localePublishVersion');
        $refreshMethod = $RefClass->getMethod('refreshLocalePublishVersion');
        $versionProperty->setValue(null, null);

        try {
            $versionBefore = Translator::getLocalePublishVersion();
            usleep(1000);
            $refreshMethod->invoke(null);

            $versionProperty->setValue(null, null);
            $versionAfter = Translator::getLocalePublishVersion();

            $this->assertNotSame($versionBefore, $versionAfter);
        } finally {
            $Config->set('locale', 'publishVersion', $oldVersion);
            $Config->save();
            $versionProperty->setValue(null, null);
        }
    }

    public function testBatchImportInsertsMultipleVariablesAndUpdatesSetupAttributes(): void
    {
        self::skipIfTranslatorDatabaseIsUnavailable();

        $languages = Translator::langs();

        if (empty($languages)) {
            $this->markTestSkipped("Translator table has no language columns.");
        }

        $language = $languages[0];
        $package = "codex-test/translator-batch-" . uniqid("", true);
        $group = "codex/translator-batch";
        $file = sys_get_temp_dir() . "/translator-batch-" . uniqid("", true) . ".xml";

        try {
            file_put_contents($file, self::createBatchImportLocaleXml($group, $language, false, 0));

            Translator::batchImport($file, $package);

            $rows = self::fetchTranslatorRowsByPackage($package, $language);

            $this->assertCount(2, $rows);
            $this->assertSame("first.variable", $rows[0]["var"]);
            $this->assertSame("First value", $rows[0][$language]);
            $this->assertSame("0", (string)$rows[0]["html"]);
            $this->assertSame("0", (string)$rows[0]["priority"]);
            $this->assertSame("second.variable", $rows[1]["var"]);

            file_put_contents($file, self::createBatchImportLocaleXml($group, $language, true, 7));

            Translator::batchImport($file, $package);

            $rows = self::fetchTranslatorRowsByPackage($package, $language);

            $this->assertCount(2, $rows);
            $this->assertSame("Updated first value", $rows[0][$language]);
            $this->assertSame("1", (string)$rows[0]["html"]);
            $this->assertSame("7", (string)$rows[0]["priority"]);
            $this->assertSame("Updated second value", $rows[1][$language]);
            $this->assertSame("1", (string)$rows[1]["html"]);
            $this->assertSame("7", (string)$rows[1]["priority"]);
        } finally {
            self::cleanupTranslatorRowsByPackage($package);

            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private static function skipIfTranslatorDatabaseIsUnavailable(): void
    {
        try {
            QUI::getDataBaseConnection()
                ->executeQuery(
                    "SELECT 1 FROM " . DoctrineUtils::quoteIdentifier(Translator::table()) . " LIMIT 1"
                )
                ->free();
        } catch (Throwable $Exception) {
            self::markTestSkipped("QUIQQER translator database is not available: " . $Exception->getMessage());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchTranslatorRowsByPackage(string $package, string $language): array
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();

        return $Connection->createQueryBuilder()
            ->select(
                $Platform->quoteSingleIdentifier("groups"),
                $Platform->quoteSingleIdentifier("var"),
                $Platform->quoteSingleIdentifier("datatype"),
                $Platform->quoteSingleIdentifier("html"),
                $Platform->quoteSingleIdentifier("priority"),
                $Platform->quoteSingleIdentifier("package"),
                $Platform->quoteSingleIdentifier($language)
            )
            ->from(DoctrineUtils::quoteIdentifier(Translator::table()))
            ->where($Platform->quoteSingleIdentifier("package") . " = :package")
            ->setParameter("package", $package)
            ->orderBy($Platform->quoteSingleIdentifier("var"), "ASC")
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private static function cleanupTranslatorRowsByPackage(string $package): void
    {
        try {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();

            $Connection->createQueryBuilder()
                ->delete(DoctrineUtils::quoteIdentifier(Translator::table()))
                ->where($Platform->quoteSingleIdentifier("package") . " = :package")
                ->setParameter("package", $package)
                ->executeStatement();
        } catch (Throwable) {
        }
    }

    private static function createBatchImportLocaleXml(
        string $group,
        string $language,
        bool $html,
        int $priority
    ): string {
        $htmlAttribute = $html ? " html=\"true\"" : "";
        $priorityAttribute = $priority > 0 ? " priority=\"" . $priority . "\"" : "";
        $firstValue = $html ? "Updated first value" : "First value";
        $secondValue = $html ? "Updated second value" : "Second value";

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<locales>
    <groups name="$group" datatype="php">
        <locale name="first.variable"$htmlAttribute$priorityAttribute>
            <$language><![CDATA[$firstValue]]></$language>
        </locale>
        <locale name="second.variable"$htmlAttribute$priorityAttribute>
            <$language><![CDATA[$secondValue]]></$language>
        </locale>
    </groups>
</locales>
XML;
    }
}
