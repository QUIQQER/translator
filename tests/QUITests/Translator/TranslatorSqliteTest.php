<?php

namespace QUITests\Translator;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use QUI;
use QUI\Translator;
use QUI\Translator\DoctrineHelper as DoctrineUtils;
use QUI\Translator\PublicationTransaction;
use ReflectionProperty;

class TranslatorSqliteTest extends TestCase
{
    private Connection $originalConnection;
    private Connection $connection;
    private int $queryCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = QUI::getDataBaseConnection();
        $Logger = $this->createMock(LoggerInterface::class);
        $Logger->method('debug')->willReturnCallback(function ($message, array $context): void {
            if (isset($context['sql'])) {
                $this->queryCount++;
            }
        });
        $Configuration = new Configuration();
        $Configuration->setMiddlewares([new Middleware($Logger)]);
        $this->connection = DriverManager::getConnection(
            [
                'driver' => 'pdo_sqlite',
                'memory' => true
            ],
            $Configuration
        );

        $this->setConnection($this->connection);
        $this->createTranslatorTable();
    }

    protected function tearDown(): void
    {
        $this->setConnection($this->originalConnection);
        $this->connection->close();

        parent::tearDown();
    }

    public function testLangsUsesSqliteSchemaMetadata(): void
    {
        self::assertSame(['de', 'en'], Translator::langs());
    }

    public function testRepeatedLangsCallsDoNotExecuteQueries(): void
    {
        $this->queryCount = 0;
        self::assertSame(['de', 'en'], Translator::langs());
        self::assertGreaterThan(0, $this->queryCount);
        $this->queryCount = 0;

        self::assertSame(['de', 'en'], Translator::langs());
        self::assertSame(['de', 'en'], Translator::langs());
        self::assertSame(0, $this->queryCount);
    }

    public function testAddingLanguageInvalidatesLanguageCache(): void
    {
        self::assertSame(['de', 'en'], Translator::langs());

        Translator::addLang('fr');

        self::assertSame(['de', 'en', 'fr'], Translator::langs());
        $this->queryCount = 0;
        self::assertSame(['de', 'en', 'fr'], Translator::langs());
        self::assertSame(0, $this->queryCount);
    }

    public function testLanguageCacheDoesNotLeakBetweenConnections(): void
    {
        self::assertSame(['de', 'en'], Translator::langs());
        $OtherConnection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);
        $Table = new Table(Translator::table());
        $Table->addColumn('fr', 'text');
        $Table->addColumn('fr_edit', 'text');
        $OtherConnection->createSchemaManager()->createTable($Table);

        try {
            $this->setConnection($OtherConnection);
            self::assertSame(['fr'], Translator::langs());
            $this->setConnection($this->connection);
            self::assertSame(['de', 'en'], Translator::langs());
        } finally {
            $this->setConnection($this->connection);
            $OtherConnection->close();
        }
    }

    public function testMissingTableIsNotCachedAsAnEmptyLanguageList(): void
    {
        $this->connection->createSchemaManager()->dropTable(Translator::table());

        try {
            Translator::langs();
            self::fail('Reading languages without a translation table must fail.');
        } catch (QUI\Database\Exception $Exception) {
            self::assertStringContainsString(Translator::table(), $Exception->getMessage());
        }

        $this->createTranslatorTable();
        self::assertSame(['de', 'en'], Translator::langs());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function publicationModes(): iterable
    {
        yield 'publish one group' => [false];
        yield 'rebuild all locales' => [true];
    }

    #[DataProvider('publicationModes')]
    public function testPublishingKeepsCompleteJavaScriptFilesAvailable(bool $create): void
    {
        $this->withLocaleDirectory(function (string $directory) use ($create): void {
            $group = 'phpunit/cache';
            $this->connection->insert(Translator::table(), [
                'groups' => $group, 'var' => 'message', 'datatype' => 'js',
                'de' => 'new german', 'en' => 'new english'
            ]);
            mkdir($directory . 'bin/' . $group, 0700, true);
            $readers = [];
            $previous = [];
            $permissions = [];

            try {
                foreach (['de', 'en'] as $lang) {
                    $file = $directory . 'bin/' . $group . '/' . $lang . '.js';
                    file_put_contents($file, self::javaScript($lang, 'old text'));
                    $bundle = Translator::getJSTranslationFiles($lang)['locale/_cache'];

                    foreach ([$file, $bundle] as $path) {
                        $previous[$path] = file_get_contents($path);
                        $permissions[$path] = fileperms($path) & 0777;
                        $readers[$path] = fopen($path, 'rb');
                        self::assertIsResource($readers[$path]);
                    }
                }

                $create ? Translator::create() : Translator::publish($group);

                foreach ($readers as $path => $reader) {
                    self::assertFileExists($path, 'Published URLs must still exist after publication.');
                    $current = file_get_contents($path);
                    self::assertIsString($current);
                    self::assertStringContainsString('new ', $current);
                    self::assertStringNotContainsString('old text', $current);
                    self::assertSame($previous[$path], stream_get_contents($reader));
                    clearstatcache(true, $path);
                    self::assertSame($permissions[$path], fileperms($path) & 0777);
                }

                self::assertSame([], glob($directory . 'bin/_cache/.locale-*'));
                self::assertSame([], glob($directory . 'bin/' . $group . '/.locale-*'));
            } finally {
                foreach ($readers as $reader) {
                    if (is_resource($reader)) {
                        fclose($reader);
                    }
                }
            }
        });
    }

    public function testCreatePreservesIniContentsAcrossGroupsAndLanguages(): void
    {
        $this->withLocaleDirectory(function (string $directory): void {
            $defaults = [
                'groups' => 'phpunit/ini',
                'package' => 'phpunit/base',
                'datatype' => 'php',
                'de' => '',
                'de_edit' => '',
                'en' => '',
                'en_edit' => ''
            ];
            $rows = [
                [
                    'var' => 'shared',
                    'de' => 'niedrig',
                    'en' => 'low',
                    'priority' => 1
                ],
                [
                    'groups' => 'phpunit/other',
                    'var' => 'message',
                    'datatype' => 'php,js',
                    'de' => 'Andere Gruppe',
                    'en' => 'Other group',
                    'priority' => 2
                ],
                [
                    'var' => 'special',
                    'de' => "  \"Zitat\" \\ Pfad\nZeile  ",
                    'en' => ' "Quote" ',
                    'priority' => 3
                ],
                [
                    'var' => 'on',
                    'de' => ' ',
                    'en' => ' yes ',
                    'priority' => 4
                ],
                [
                    'var' => 'shared',
                    'package' => 'phpunit/override',
                    'de' => 'Original',
                    'de_edit' => 'Angepasst',
                    'en' => 'Original',
                    'en_edit' => 'Custom',
                    'priority' => 5
                ],
                [
                    'var' => 'empty',
                    'priority' => 6
                ],
                [
                    'groups' => 'phpunit/js-only',
                    'var' => 'message',
                    'datatype' => 'js',
                    'de' => 'Nur JavaScript',
                    'en' => 'JavaScript only',
                    'priority' => 7
                ]
            ];

            foreach ($rows as $row) {
                $this->connection->insert(Translator::table(), array_replace($defaults, $row));
            }

            $german = <<<'INI'
shared= "niedrig"
special= "\"Zitat\" \\ Pfad{\n}Zeile"
`on`= " "
shared= "Angepasst"
INI;
            $english = <<<'INI'
shared= "low"
special= "\"Quote\""
`on`= "yes"
shared= "Custom"
INI;
            $expected = [
                'de/LC_MESSAGES/phpunit_ini.ini.php' => $german . "\n",
                'en/LC_MESSAGES/phpunit_ini.ini.php' => $english . "\n",
                'de/LC_MESSAGES/phpunit_other.ini.php' => 'message= "Andere Gruppe"' . "\n",
                'en/LC_MESSAGES/phpunit_other.ini.php' => 'message= "Other group"' . "\n"
            ];

            for ($run = 0; $run < 2; $run++) {
                Translator::create();

                foreach ($expected as $path => $content) {
                    self::assertSame($content, file_get_contents($directory . $path));
                }

                foreach (['de', 'en'] as $lang) {
                    self::assertFileDoesNotExist($directory . $lang . '/LC_MESSAGES/phpunit_js-only.ini.php');
                }
            }
        });
    }

    public function testGeneratedIniFilesStillSupportSingleLineAppending(): void
    {
        $this->withLocaleDirectory(function (string $directory): void {
            $this->connection->insert(Translator::table(), [
                'groups' => 'phpunit/ini',
                'var' => 'original',
                'datatype' => 'php',
                'de' => 'Original',
                'en' => 'Original'
            ]);

            Translator::create();
            $file = $directory . 'de/LC_MESSAGES/phpunit_ini.ini.php';

            self::assertTrue(QUI\Utils\System\File::mkfile($file));
            QUI\Utils\System\File::putLineToFile($file, 'additional= "Added individually"');

            self::assertSame(
                'original= "Original"' . "\n" . 'additional= "Added individually"' . "\n",
                file_get_contents($file)
            );
        });
    }

    public function testDevelopmentModeStillReturnsIndividualModules(): void
    {
        $this->withLocaleDirectory(function (string $directory): void {
            mkdir($directory . 'bin/phpunit/cache', 0700, true);
            $file = $directory . 'bin/phpunit/cache/de.js';
            file_put_contents($file, self::javaScript('de', 'development'));
            QUI::getConfig('etc/conf.ini.php')->set('globals', 'development', 1);

            self::assertSame(['locale/phpunit/cache' => $file], Translator::getJSTranslationFiles('de'));
            self::assertFileDoesNotExist($directory . 'bin/_cache/de.js');
            self::assertSame([], Translator::getJSTranslationFiles('invalid'));
        });
    }

    public function testFailedCacheReplacementKeepsPreviousFile(): void
    {
        $this->withLocaleDirectory(function (string $directory): void {
            mkdir($directory . 'bin/phpunit/cache', 0700, true);
            $source = $directory . 'bin/phpunit/cache/de.js';
            file_put_contents($source, self::javaScript('de', 'old text'));
            $bundle = Translator::getJSTranslationFiles('de')['locale/_cache'];
            $previous = file_get_contents($bundle);
            file_put_contents($source, self::javaScript('de', 'new text'));
            chmod(dirname($bundle), 0555);
            clearstatcache();

            try {
                if (is_writable(dirname($bundle))) {
                    self::markTestSkipped('This user bypasses directory write permissions.');
                }

                try {
                    Translator::getJSTranslationFiles('de', true);
                    self::fail('Cache replacement must fail when its directory is not writable.');
                } catch (QUI\Exception $Exception) {
                    self::assertStringContainsString('locale file', $Exception->getMessage());
                }

                self::assertSame($previous, file_get_contents($bundle));
                self::assertSame([], glob(dirname($bundle) . '/.locale-*'));
            } finally {
                chmod(dirname($bundle), 0700);
            }
        });
    }

    /** @return iterable<string, array{string}> */
    public static function unpublishedChanges(): iterable
    {
        yield 'text draft' => ['text'];
        yield 'context draft' => ['context'];
        yield 'deleted draft' => ['delete'];
    }

    #[DataProvider('unpublishedChanges')]
    public function testPublishingOneGroupPreservesOtherPublishedGroups(string $change): void
    {
        $this->withLocaleDirectory(function (string $directory) use ($change): void {
            foreach (['test/target', 'test/untouched'] as $group) {
                $this->connection->insert(Translator::table(), [
                    'groups' => $group, 'package' => $group, 'var' => 'title',
                    'datatype' => 'php,js', 'context' => '', 'de' => 'Published ' . $group
                ]);
            }

            Translator::create();
            $php = $directory . 'de/LC_MESSAGES/test_untouched.ini.php';
            $javascript = $directory . 'bin/test/untouched/de.js';
            $previousPhp = file_get_contents($php);
            $previousJavaScript = file_get_contents($javascript);

            if ($change === 'delete') {
                $this->connection->delete(Translator::table(), ['groups' => 'test/untouched']);
            } else {
                $data = $change === 'context' ? ['context' => 'draftContext'] : ['de_edit' => 'Unpublished draft'];
                $this->connection->update(Translator::table(), $data, ['groups' => 'test/untouched']);
            }

            $this->connection->update(Translator::table(), ['de_edit' => 'Published target change'], [
                'groups' => 'test/target'
            ]);
            Translator::publish('test/target');
            self::assertSame($previousPhp, file_get_contents($php));
            self::assertSame($previousJavaScript, file_get_contents($javascript));
            $files = Translator::getJSContextTranslationFiles('de', 'frontend');
            $common = file_get_contents(array_values($files)[0]);
            self::assertStringContainsString('Published test\\/untouched', $common);
            self::assertStringContainsString('Published target change', $common);
            self::assertStringNotContainsString('Unpublished draft', $common);
            self::assertNotContains('draftContext', Translator::getJSContextManifest()['contexts']);
        });
    }

    public function testFirstSingleGroupPublicationEstablishesCompletePhpAndJsBaseline(): void
    {
        $this->withLocaleDirectory(function (string $directory): void {
            foreach (['test/target', 'test/initial'] as $group) {
                $this->connection->insert(Translator::table(), [
                    'groups' => $group, 'package' => $group, 'var' => 'title',
                    'datatype' => 'php,js', 'context' => '', 'de' => 'Initial value'
                ]);
            }

            Translator::publish('test/target');
            self::assertStringContainsString(
                'Initial value',
                file_get_contents($directory . 'de/LC_MESSAGES/test_initial.ini.php')
            );
            self::assertStringContainsString(
                'Initial value',
                file_get_contents($directory . 'bin/test/initial/de.js')
            );
        });
    }

    public function testBothPublishModesParseEffectiveValuesExactlyOnce(): void
    {
        $this->withLocaleDirectory(function (string $directory): void {
            $parseCalls = 0;
            QUI::$Events->method('fireEvent')->willReturnCallback(
                static function (string $event, false | array $arguments = false) use (&$parseCalls): array {
                    if ($event === 'outputParseBegin' && is_array($arguments)) {
                        $parseCalls++;
                        $arguments[0] = 'Parsed: ' . $arguments[0];
                    }

                    return [];
                }
            );
            $this->connection->insert(Translator::table(), [
                'groups' => 'test/parsed', 'package' => 'test/parsed', 'var' => 'title',
                'datatype' => 'php,js', 'context' => 'namescoreDashboard',
                'de' => 'Original', 'de_edit' => 'Hello %5Bname%5D', 'en' => ''
            ]);
            Translator::create();
            self::assertSame(1, $parseCalls);
            $javascript = $directory . 'bin/test/parsed/de.js';
            $php = $directory . 'de/LC_MESSAGES/test_parsed.ini.php';
            $firstJavaScript = file_get_contents($javascript);
            $firstPhp = file_get_contents($php);
            self::assertStringContainsString('Parsed: Hello [name]', $firstJavaScript);
            self::assertStringContainsString('Parsed: Hello [name]', $firstPhp);
            self::assertStringNotContainsString('Parsed: Parsed:', $firstPhp);
            Translator::publish('test/parsed');
            self::assertSame(2, $parseCalls);
            self::assertSame($firstJavaScript, file_get_contents($javascript));
            self::assertSame($firstPhp, file_get_contents($php));

            Translator::publish('test/unrelated');
            self::assertSame(2, $parseCalls, 'Previously published groups must not run Output hooks again.');
            self::assertSame($firstJavaScript, file_get_contents($javascript));
        });
    }

    public function testFailedCommitRestoresEveryPreviouslyVisibleArtifact(): void
    {
        $this->withLocaleDirectory(function (string $directory): void {
            $this->connection->insert(Translator::table(), [
                'groups' => 'test/rollback', 'package' => 'test/rollback', 'var' => 'title',
                'datatype' => 'php,js', 'context' => 'namescoreDashboard',
                'de' => 'Previous text', 'en' => 'Previous English'
            ]);
            Translator::create();
            $manifest = Translator::getJSContextManifest();
            $oldFiles = Translator::getJSContextTranslationFiles('de', 'namescoreDashboard', $manifest);
            $oldContents = array_map('file_get_contents', $oldFiles);
            $generations = glob($directory . 'bin/_contexts/*', GLOB_ONLYDIR);
            $paths = [
                'de/LC_MESSAGES/test_rollback.ini.php', 'en/LC_MESSAGES/test_rollback.ini.php',
                'bin/test/rollback/de.js', 'bin/test/rollback/en.js',
                'bin/_cache/de.js', 'bin/_cache/en.js', 'bin/_contexts/manifest.json'
            ];
            $previous = [];

            foreach ($paths as $path) {
                $previous[$path] = file_get_contents($directory . $path);
            }

            $this->connection->update(Translator::table(), [
                'de' => 'Uncommitted text', 'en' => 'Uncommitted English'
            ], ['groups' => 'test/rollback']);
            $cacheDirectory = $directory . 'bin/_cache';
            chmod($cacheDirectory, 0555);
            clearstatcache();

            try {
                if (is_writable($cacheDirectory)) {
                    self::markTestSkipped('This user bypasses directory write permissions.');
                }

                try {
                    Translator::publish('test/rollback');
                    self::fail('The commit must fail at its unwritable cache target.');
                } catch (QUI\Exception $Exception) {
                    self::assertStringContainsString('locale file', $Exception->getMessage());
                }

                foreach ($previous as $path => $content) {
                    self::assertSame($content, file_get_contents($directory . $path), $path);
                }
                self::assertSame($manifest, Translator::getJSContextManifest());
                self::assertSame($oldContents, array_map('file_get_contents', $oldFiles));
                self::assertSame($generations, glob($directory . 'bin/_contexts/*', GLOB_ONLYDIR));
                self::assertSame([], glob($directory . '.publication-stage-*'));
                self::assertFileDoesNotExist($directory . '.publication-journal.json');
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
                );

                foreach ($files as $file) {
                    self::assertStringNotContainsString('.locale-', $file->getFilename());
                }
            } finally {
                chmod($cacheDirectory, 0700);
            }

            Translator::publish('test/rollback');
            self::assertStringContainsString('Uncommitted text', file_get_contents($directory . $paths[0]));
            self::assertStringContainsString('Uncommitted text', file_get_contents($directory . $paths[2]));
            self::assertNotSame($previous['bin/_contexts/manifest.json'], file_get_contents(
                $directory . 'bin/_contexts/manifest.json'
            ));
            $currentFiles = Translator::getJSContextTranslationFiles('de', 'namescoreDashboard');
            self::assertStringContainsString('Uncommitted text', file_get_contents(array_values($currentFiles)[1]));
            self::assertSame($oldContents, array_map('file_get_contents', $oldFiles));
        });
    }

    public function testStagingSameTargetTwiceDoesNotOverwriteAnotherTarget(): void
    {
        $this->withLocaleDirectory(function (string $directory): void {
            PublicationTransaction::withLock($directory, static function () use ($directory): void {
                $Publication = new PublicationTransaction($directory);
                $Publication->stage($directory . 'first', 'original');
                $Publication->stage($directory . 'first', 'replacement');
                $Publication->stage($directory . 'second', 'independent');
                $Publication->stage($directory . 'manifest', 'committed');
                $Publication->commit($directory . 'manifest');
                $Publication->complete();
            });
            self::assertSame('replacement', file_get_contents($directory . 'first'));
            self::assertSame('independent', file_get_contents($directory . 'second'));
            self::assertSame([], glob($directory . '.publication-stage-*'));
        });
    }

    public static function crashCommitPoints(): iterable
    {
        yield 'before manifest commit' => [false];
        yield 'after manifest commit' => [true];
    }

    #[DataProvider('crashCommitPoints')]
    public function testPersistedPublicationJournalRecoversAtManifestBoundary(bool $committed): void
    {
        $this->withLocaleDirectory(function (string $directory) use ($committed): void {
            $this->connection->insert(Translator::table(), [
                'groups' => 'test/recovery', 'package' => 'test/recovery', 'var' => 'title',
                'datatype' => 'php,js', 'context' => 'namescoreDashboard',
                'de' => 'Previous recovery text', 'en' => 'Previous recovery text'
            ]);
            Translator::create();
            $manifestFile = $directory . 'bin/_contexts/manifest.json';
            $previousManifest = file_get_contents($manifestFile);
            $previous = [];

            foreach (['de/LC_MESSAGES/test_recovery.ini.php', 'bin/test/recovery/de.js', 'bin/_cache/de.js'] as $path) {
                $previous[$path] = file_get_contents($directory . $path);
            }

            $previousGenerations = glob($directory . 'bin/_contexts/*', GLOB_ONLYDIR);
            $cacheCompletions = 0;
            QUI::$Events->method('fireEvent')->willReturnCallback(
                static function (string $event) use (&$cacheCompletions): array {
                    if ($event === 'quiqqerTranslatorPublish' && ++$cacheCompletions === 1) {
                        throw new \RuntimeException('Interrupted cache completion');
                    }

                    return [];
                }
            );
            $this->connection->update(Translator::table(), ['de' => 'Recovered new text'], ['groups' => 'test/recovery']);

            try {
                Translator::publish('test/recovery');
                self::fail('The cache completion hook must interrupt publication.');
            } catch (\RuntimeException $Exception) {
                self::assertSame('Interrupted cache completion', $Exception->getMessage());
            }

            self::assertFileExists($directory . '.publication-journal.json');
            $newManifest = file_get_contents($manifestFile);
            self::assertNotSame($previousManifest, $newManifest);

            if (!$committed) {
                // Recreate the persisted crash image immediately before the manifest rename.
                file_put_contents($manifestFile, $previousManifest);
            }

            $manifest = Translator::getJSContextManifest();
            self::assertSame(json_decode($committed ? $newManifest : $previousManifest, true), $manifest);
            self::assertSame($committed ? 2 : 1, $cacheCompletions);

            foreach ($previous as $path => $content) {
                if ($committed) {
                    self::assertStringContainsString('Recovered new text', file_get_contents($directory . $path));
                } else {
                    self::assertSame($content, file_get_contents($directory . $path));
                }
            }

            if (!$committed) {
                self::assertSame($previousGenerations, glob($directory . 'bin/_contexts/*', GLOB_ONLYDIR));
            }

            self::assertFileDoesNotExist($directory . '.publication-journal.json');
            self::assertSame([], glob($directory . '.publication-stage-*'));
            Translator::publish('test/recovery');
            self::assertStringContainsString('Recovered new text', file_get_contents($directory . 'bin/_cache/de.js'));
        });
    }

    public function testCorruptPublicationJournalFailsClosed(): void
    {
        $this->withLocaleDirectory(function (string $directory): void {
            file_put_contents($directory . 'existing', 'unchanged');
            file_put_contents($directory . '.publication-journal.json', '{"files":[]}');

            try {
                Translator::getJSContextManifest();
                self::fail('A corrupt journal must prevent publication recovery.');
            } catch (QUI\Exception $Exception) {
                self::assertSame('Invalid locale publication journal', $Exception->getMessage());
            }

            self::assertSame('unchanged', file_get_contents($directory . 'existing'));
            self::assertFileExists($directory . '.publication-journal.json');
        });
    }

    public function testCorruptPublicationSnapshotDoesNotPublishDrafts(): void
    {
        $this->withLocaleDirectory(function (string $directory): void {
            $this->connection->insert(Translator::table(), [
                'groups' => 'test/snapshot', 'package' => 'test/snapshot', 'var' => 'title',
                'datatype' => 'php,js', 'de' => 'Published snapshot text', 'en' => ''
            ]);
            Translator::create();
            $manifest = Translator::getJSContextManifest();
            $javascript = file_get_contents($directory . 'bin/_cache/de.js');
            file_put_contents($directory . '.context-snapshots/' . $manifest['version'] . '.json', '{"format":0}');
            $this->connection->update(Translator::table(), ['de' => 'Draft text'], ['groups' => 'test/snapshot']);

            try {
                Translator::publish('test/snapshot');
                self::fail('A corrupt snapshot must prevent publication.');
            } catch (QUI\Exception $Exception) {
                self::assertStringContainsString('snapshot', $Exception->getMessage());
            }

            self::assertSame($manifest, Translator::getJSContextManifest());
            self::assertSame($javascript, file_get_contents($directory . 'bin/_cache/de.js'));
        });
    }

    private static function javaScript(string $lang, string $text): string
    {
        return 'define("locale/phpunit/cache/' . $lang . '", ["Locale"], function(Locale){'
            . 'Locale.set("' . $lang . '", "phpunit/cache", {"message":"' . $text . '"})});';
    }

    private function withLocaleDirectory(callable $test): void
    {
        $directory = sys_get_temp_dir() . '/translator-js-cache-' . bin2hex(random_bytes(8)) . '/';
        mkdir($directory, 0700);
        $previousLocale = QUI::$Locale;
        $previousEvents = QUI::$Events;
        $Config = QUI::getConfig('etc/conf.ini.php');
        $development = $Config->get('globals', 'development');
        $Locale = $this->createMock(QUI\Locale::class);
        $Locale->method('dir')->willReturn($directory);
        $Locale->method('getCurrent')->willReturn('de');
        QUI::$Locale = $Locale;
        QUI::$Events = $this->createMock(QUI\Events\Manager::class);
        $Config->set('globals', 'development', 0);

        try {
            $test($directory);
        } finally {
            QUI::$Locale = $previousLocale;
            QUI::$Events = $previousEvents;
            $Config->set('globals', 'development', $development);
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($directory);
        }
    }

    public function testAddEditDeleteCrudOnSqlite(): void
    {
        $group = 'phpunit/translator-sqlite';
        $var = 'crud';
        $package = 'phpunit/translator';

        Translator::add($group, $var, $package, 'js', true);

        $row = $this->fetchRow($group, $var);
        self::assertIsArray($row);
        self::assertSame($package, $row['package']);
        self::assertSame('js', $row['datatype']);
        self::assertSame(1, (int)$row['html']);

        Translator::edit($group, $var, $package, [
            'de' => ' Deutscher Text ',
            'en_edit' => ' English text ',
            'datatype' => 'php',
            'priority' => 7
        ]);

        $row = $this->fetchRow($group, $var);
        self::assertIsArray($row);
        self::assertSame('Deutscher Text', $row[QUI::conf('globals', 'development') ? 'de' : 'de_edit']);
        self::assertSame('English text', $row['en_edit']);
        self::assertSame('php', $row['datatype']);
        self::assertSame(7, (int)$row['priority']);

        Translator::delete($group, $var);

        self::assertFalse($this->fetchRow($group, $var));
    }

    public function testAddUserVarInsertsAndUpdatesOnSqlite(): void
    {
        $group = 'phpunit/translator-sqlite';
        $var = 'user-var';
        $package = 'phpunit/translator';

        Translator::addUserVar($group, $var, [
            'package' => $package,
            'de' => ' Erster Text ',
            'en' => ' First text ',
            'datatype' => 'php,js',
            'html' => 1,
            'priority' => 3
        ]);

        $row = $this->fetchRow($group, $var);
        self::assertIsArray($row);
        self::assertSame('Erster Text', $row['de_edit']);
        self::assertSame('First text', $row['en_edit']);
        self::assertSame(1, (int)$row['html']);
        self::assertSame(3, (int)$row['priority']);

        Translator::addUserVar($group, $var, [
            'package' => $package,
            'de' => ' Aktualisierter Text '
        ]);

        $row = $this->fetchRow($group, $var);
        self::assertIsArray($row);
        self::assertSame('Aktualisierter Text', $row['de_edit']);

        Translator::delete($group, $var);
    }

    public function testFirstPartialPublicationMigratesUnchangedPackageMetadataOnly(): void
    {
        $this->withLocaleDirectory(function (string $directory): void {
            mkdir($directory . 'fixture');
            $manifest = $directory . 'fixture/locale.xml';
            file_put_contents($manifest, '<locales><file file="de.xml"/><file file="en.xml"/></locales>');

            foreach (['de', 'en'] as $language) {
                file_put_contents(
                    $directory . 'fixture/' . $language . '.xml',
                    '<locales><groups name="test/migration" datatype="php,js">'
                    . '<locale name="title" context="namescoreDashboard"><' . $language . '>Package text</'
                    . $language . '></locale></groups></locales>'
                );
            }

            $this->connection->insert(Translator::table(), [
                'groups' => 'test/migration', 'var' => 'title', 'package' => 'test/migration',
                'de' => 'Original text', 'de_edit' => 'Individual text', 'context' => null
            ]);
            $this->connection->insert(Translator::table(), [
                'groups' => 'test/migration', 'var' => 'explicit', 'package' => 'test/migration',
                'de' => 'Explicit text', 'context' => 'runtimeContext'
            ]);
            file_put_contents($manifest, '<locales><file file="de.xml"/><file file="en.xml"/>'
                . '<groups name="test/migration"><locale name="explicit"><de>XML text</de></locale></groups></locales>');
            $Package = $this->createMock(QUI\Package\Package::class);
            $Package->method('getXMLFilePath')->willReturn($manifest);
            $Package->method('getDir')->willReturn($directory . 'fixture/');
            $Package->method('getName')->willReturn('test/migration');
            $Manager = $this->createMock(QUI\Package\Manager::class);
            $Manager->method('getInstalledPackage')->willReturn($Package);
            $previousManager = QUI::$PackageManager;
            QUI::$PackageManager = $Manager;

            try {
                Translator::publish('quiqqer/translator');
                $row = $this->fetchRow('test/migration', 'title');
                self::assertSame('namescoreDashboard', $row['context']);
                self::assertSame('Original text', $row['de']);
                self::assertSame('Individual text', $row['de_edit']);
                self::assertSame('runtimeContext', $this->fetchRow('test/migration', 'explicit')['context']);
                self::assertContains('namescoreDashboard', Translator::getJSContextManifest()['contexts']);
                $this->connection->update(Translator::table(), ['context' => 'frontend'], ['id' => $row['id']]);
                Translator::publish('test/migration');
                self::assertSame('frontend', $this->fetchRow('test/migration', 'title')['context']);
            } finally {
                QUI::$PackageManager = $previousManager;
            }
        });
    }

    /** @return iterable<string, array{bool}> */
    public static function importModes(): iterable
    {
        yield 'normal import' => [false];
        yield 'batch import' => [true];
    }

    #[DataProvider('importModes')]
    public function testExportImportPreservesDistinctPackageOwnersAndContextInheritance(bool $batch): void
    {
        $file = tempnam(sys_get_temp_dir(), 'translator-owner-roundtrip.xml-');
        $modifyFile = VAR_DIR . 'locale/localefiles';
        $previous = is_file($modifyFile) ? file_get_contents($modifyFile) : null;
        $group = 'test/roundtrip';
        $owners = [
            $group => ['context' => '', 'priority' => 1, 'de' => 'Base text', 'en' => 'Base English'],
            'test/override' => ['context' => null, 'priority' => 9, 'de' => 'Override', 'en' => 'Override English']
        ];

        try {
            foreach ($owners as $owner => $data) {
                $this->connection->insert(Translator::table(), [
                    'groups' => $group, 'var' => 'title', 'package' => $owner, 'datatype' => 'php,js'
                ] + $data);
            }

            $method = new \ReflectionMethod(Translator::class, 'createXMLContent');
            $export = $method->invoke(null, $group, ['de', 'en'], 'original');
            file_put_contents($file, '<locales>' . $export . '</locales>');
            $this->connection->delete(Translator::table(), ['groups' => $group]);

            for ($round = 0; $round < 2; $round++) {
                if ($batch) {
                    Translator::batchImport($file);
                } else {
                    Translator::import($file, 1, true, '', true);
                }

                self::assertCount(2, Translator::get($group));

                foreach ($owners as $owner => $data) {
                    $rows = Translator::get($group, 'title', $owner);
                    self::assertCount(1, $rows);
                    self::assertSame($data['context'], $rows[0]['context']);
                    self::assertSame($data['priority'], (int)$rows[0]['priority']);
                    self::assertSame($data['de'], $rows[0]['de']);
                    self::assertSame($data['en'], $rows[0]['en']);

                    if ($round === 1) {
                        self::assertSame('Individual text', $rows[0]['de_edit']);
                    }
                }

                $this->connection->update(Translator::table(), ['de_edit' => 'Individual text'], ['groups' => $group]);
            }
        } finally {
            unlink($file);

            if ($previous === null) {
                @unlink($modifyFile);
            } else {
                file_put_contents($modifyFile, $previous);
            }

            (new ReflectionProperty(Translator::class, 'localeModifyTimes'))->setValue(null, null);
        }
    }

    #[DataProvider('importModes')]
    public function testExplicitImportOwnerTakesPrecedenceOverExportedPackage(bool $batch): void
    {
        $file = tempnam(sys_get_temp_dir(), 'translator-explicit-owner.xml-');
        $modifyFile = VAR_DIR . 'locale/localefiles';
        $previous = is_file($modifyFile) ? file_get_contents($modifyFile) : null;

        try {
            file_put_contents($file, '<locales><groups name="test/owner" datatype="php,js">'
                . '<locale name="title" package="test/exported" context="namescoreDashboard">'
                . '<de>Text</de></locale></groups></locales>');

            if ($batch) {
                Translator::batchImport($file, 'test/actual');
            } else {
                Translator::import($file, 1, true, 'test/actual', true);
            }

            self::assertSame([], Translator::get('test/owner', 'title', 'test/exported'));
            $rows = Translator::get('test/owner', 'title', 'test/actual');
            self::assertCount(1, $rows);
            self::assertSame('namescoreDashboard', $rows[0]['context']);
        } finally {
            unlink($file);

            if ($previous === null) {
                @unlink($modifyFile);
            } else {
                file_put_contents($modifyFile, $previous);
            }

            (new ReflectionProperty(Translator::class, 'localeModifyTimes'))->setValue(null, null);
        }
    }

    public function testNormalAndBatchImportAndXmlExportPreserveContext(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'translator-context-import.xml-');
        $modifyFile = VAR_DIR . 'locale/localefiles';
        $previous = is_file($modifyFile) ? file_get_contents($modifyFile) : null;

        try {
            file_put_contents($file, '<locales><groups name="test/context" datatype="php,js">'
                . '<locale name="title" context=" namescoreDashboard,administration ">'
                . '<de>Titel</de><en>Title</en></locale></groups></locales>');
            Translator::batchImport($file, 'test/context');
            self::assertSame('administration,namescoreDashboard', $this->fetchRow('test/context', 'title')['context']);
            Translator::import($file, 0, true, 'test/context', true);
            $row = $this->fetchRow('test/context', 'title');
            self::assertSame('administration,namescoreDashboard', $row['context']);
            self::assertArrayNotHasKey('context_edit', $row);
            $method = new \ReflectionMethod(Translator::class, 'createXMLContent');
            $xml = $method->invoke(null, 'test/context', ['de', 'en'], 'edit_overwrite');
            self::assertStringContainsString('context="administration,namescoreDashboard"', $xml);
            file_put_contents($file, '<locales><groups name="test/context" datatype="php,js">'
                . '<locale name="title"><de>Neu</de></locale></groups></locales>');
            Translator::batchImport($file, 'test/context');
            self::assertSame('', $this->fetchRow('test/context', 'title')['context']);
        } finally {
            unlink($file);

            if ($previous === null) {
                @unlink($modifyFile);
            } else {
                file_put_contents($modifyFile, $previous);
            }

            (new ReflectionProperty(Translator::class, 'localeModifyTimes'))->setValue(null, null);
        }
    }

    #[DataProvider('importModes')]
    public function testConflictingReferencedLanguageFilesLeaveDatabaseUnchanged(bool $batch): void
    {
        $this->withLocaleDirectory(function (string $directory) use ($batch): void {
            $modifyFile = VAR_DIR . 'locale/localefiles';
            $previousModifyTimes = is_file($modifyFile) ? file_get_contents($modifyFile) : null;
            $manifest = $directory . 'locale.xml';
            $this->connection->insert(Translator::table(), [
                'groups' => 'test/context',
                'var' => 'existing',
                'package' => 'test/context',
                'context' => 'frontend',
                'de' => 'Published original',
                'de_edit' => 'Individual text'
            ]);
            $before = Translator::get('test/context');
            file_put_contents($directory . 'de.xml', '<locales><groups name="test/context">'
                . '<locale name="conflicting" context="frontend"><de>Titel</de></locale></groups></locales>');
            file_put_contents($directory . 'en.xml', '<locales><groups name="test/context">'
                . '<locale name="conflicting" context="administration"><en>Title</en></locale></groups></locales>');
            $Package = $this->createMock(QUI\Package\Package::class);
            $Package->method('getXMLFilePath')->willReturn($manifest);
            $Package->method('getDir')->willReturn($directory);
            $Package->method('getName')->willReturn('test/context');

            try {
                foreach ([['de', 'en'], ['en', 'de']] as $languages) {
                    file_put_contents($manifest, '<locales><groups name="test/context">'
                        . '<locale name="existing"><de>Unwanted update</de></locale>'
                        . '<locale name="new"><de>Unwanted insertion</de></locale></groups>'
                        . '<file file="' . $languages[0] . '.xml"/>'
                        . '<file file="' . $languages[1] . '.xml"/></locales>');

                    try {
                        if ($batch) {
                            Translator::batchImportFromPackage($Package);
                        } else {
                            Translator::importFromPackage($Package, 1, true, true);
                        }

                        self::fail('A package with conflicting referenced contexts must be rejected.');
                    } catch (QUI\Exception $Exception) {
                        self::assertStringContainsString('Conflicting locale contexts', $Exception->getMessage());
                    }

                    self::assertSame($before, Translator::get('test/context'));
                }
            } finally {
                if ($previousModifyTimes === null) {
                    @unlink($modifyFile);
                } else {
                    file_put_contents($modifyFile, $previousModifyTimes);
                }

                (new ReflectionProperty(Translator::class, 'localeModifyTimes'))->setValue(null, null);
            }
        });
    }

    public function testContextEditorLabelIsPublishedForAdministrationInBothLoginLanguages(): void
    {
        $this->withLocaleDirectory(function (): void {
            $modifyFile = VAR_DIR . 'locale/localefiles';
            $previousModifyTimes = is_file($modifyFile) ? file_get_contents($modifyFile) : null;

            try {
                Translator::import(dirname(__DIR__, 3) . '/locale.xml', 1, true, 'quiqqer/translator', true);
                Translator::create();
                $expected = [
                    'de' => 'JavaScript-Kontexte (leer: gemeinsam)',
                    'en' => 'JavaScript contexts (empty: shared)'
                ];

                foreach ($expected as $language => $label) {
                    $files = Translator::getJSContextTranslationFiles($language, 'administration');
                    $javascript = implode('', array_map('file_get_contents', $files));
                    $entry = json_encode('grid.title.context', JSON_THROW_ON_ERROR)
                        . ':' . json_encode($label, JSON_THROW_ON_ERROR);
                    self::assertStringContainsString($entry, $javascript);
                }
            } finally {
                if ($previousModifyTimes === null) {
                    @unlink($modifyFile);
                } else {
                    file_put_contents($modifyFile, $previousModifyTimes);
                }

                (new ReflectionProperty(Translator::class, 'localeModifyTimes'))->setValue(null, null);
            }
        });
    }

    public function testMcpContextSaveAndLanguageFilteringPreserveMetadata(): void
    {
        if (!interface_exists(QUI\AI\MCP\ProviderInterface::class)) {
            require_once dirname(__DIR__, 2) . '/stubs/QUI/AI/MCP/ProviderInterface.php';
        }

        $save = new \ReflectionMethod(QUI\Translator\MCP\Provider::class, 'saveLanguageVariables');
        $get = new \ReflectionMethod(QUI\Translator\MCP\Provider::class, 'getLanguageVariables');
        $Config = QUI::getConfig('etc/conf.ini.php');
        $development = $Config->get('globals', 'development');
        $Config->set('globals', 'development', 0);
        $identity = ['group' => 'test/mcp', 'var' => 'title', 'package' => 'test/mcp'];

        try {
            $result = $save->invoke(null, [$identity + [
                'context' => ' namescoreDashboard ',
                'translations' => ['de' => 'First text', 'en' => 'English text']
            ]], false);
            self::assertSame(1, $result['count']);
            self::assertSame([], $result['publishedGroups']);
            self::assertSame('namescoreDashboard', $this->fetchRow('test/mcp', 'title')['context']);

            $filtered = $get->invoke(null, 10, 1, ['de', 'context'], 'test/mcp');
            self::assertSame(['de'], $filtered['languages']);
            self::assertCount(1, $filtered['data']);
            self::assertSame('namescoreDashboard', $filtered['data'][0]['context']);
            self::assertSame('First text', $filtered['data'][0]['de_edit']);
            self::assertArrayNotHasKey('en', $filtered['data'][0]);
            self::assertArrayNotHasKey('en_edit', $filtered['data'][0]);
            self::assertArrayNotHasKey('context_edit', $filtered['data'][0]);

            $save->invoke(null, [$identity + ['translations' => ['de' => 'Updated text']]], false);
            $row = $this->fetchRow('test/mcp', 'title');
            self::assertSame('namescoreDashboard', $row['context']);
            self::assertSame('Updated text', $row['de_edit']);
            self::assertSame('English text', $row['en_edit']);

            $save->invoke(null, [$identity + ['context' => '', 'translations' => []]], false);
            $row = $this->fetchRow('test/mcp', 'title');
            self::assertSame('', $row['context']);
            self::assertSame('Updated text', $row['de_edit']);
            self::assertSame(['de', 'en'], Translator::langs());
        } finally {
            $Config->set('globals', 'development', $development);
        }
    }

    public function testContextIsMetadataAndTextEditingPreservesIt(): void
    {
        Translator::add('test/context', 'title', 'test/context', 'php,js', false, 'namescoreDashboard');
        Translator::edit('test/context', 'title', 'test/context', ['de' => 'Edited']);
        self::assertSame('namescoreDashboard', $this->fetchRow('test/context', 'title')['context']);
        self::assertSame(['de', 'en'], Translator::langs());
        Translator::edit('test/context', 'title', 'test/context', ['context' => '']);
        self::assertSame('', $this->fetchRow('test/context', 'title')['context']);
    }

    public function testSchemaUpgradeAddsNullableContextToExistingRows(): void
    {
        $Table = $this->connection->createSchemaManager()->introspectTable(Translator::table());
        $this->connection->createSchemaManager()->alterTable(new \Doctrine\DBAL\Schema\TableDiff(
            $Table,
            droppedColumns: [$Table->getColumn('context')]
        ));
        $this->connection->insert(Translator::table(), [
            'groups' => 'test/old', 'var' => 'title', 'de' => 'Existing'
        ]);
        Translator::ensureContextSchema();
        self::assertNull($this->fetchRow('test/old', 'title')['context']);
        self::assertSame('Existing', $this->fetchRow('test/old', 'title')['de']);
        self::assertSame(['de', 'en'], Translator::langs());
    }

    public function testContextPublicationKeepsPreviousGenerationAndClearsRemovedContent(): void
    {
        $this->withLocaleDirectory(function (string $directory): void {
            Translator::add('test/context', 'title', 'test/context', 'js', false, 'namescoreDashboard');
            Translator::update('test/context', 'title', 'test/context', [
                'de' => 'Unique translated title', 'en' => 'Unique translated title', 'datatype' => 'js'
            ]);
            Translator::create();
            $previous = Translator::getJSContextManifest();
            $previousFiles = Translator::getJSContextTranslationFiles('de', 'namescoreDashboard', $previous);
            self::assertCount(2, $previousFiles);
            $oldFile = array_values($previousFiles)[1];
            self::assertStringContainsString('Unique translated title', file_get_contents($oldFile));
            $this->connection->delete(Translator::table(), ['groups' => 'test/context']);
            Translator::publish('test/context');
            $current = Translator::getJSContextManifest();
            self::assertNotSame($previous['version'], $current['version']);
            self::assertContains('namescoreDashboard', $current['contexts']);
            self::assertStringContainsString('Unique translated title', file_get_contents($oldFile));
            $newFiles = Translator::getJSContextTranslationFiles('de', 'namescoreDashboard');
            self::assertStringNotContainsString('Unique translated title', file_get_contents(array_values($newFiles)[1]));
            self::assertStringNotContainsString('Unique translated title', file_get_contents($directory . 'bin/test/context/de.js'));
            self::assertSame($previousFiles, Translator::getJSContextTranslationFiles('de', 'namescoreDashboard', $previous));
        });
    }

    private function createTranslatorTable(): void
    {
        $Table = new Table(Translator::table());
        $Table->addColumn('id', 'integer', ['autoincrement' => true]);
        $Table->addColumn('groups', 'string', ['length' => 255]);
        $Table->addColumn('var', 'string', ['length' => 255]);
        $Table->addColumn('html', 'integer', ['default' => 0]);
        $Table->addColumn('datatype', 'string', ['length' => 32, 'default' => 'php,js']);
        $Table->addColumn('datadefine', 'string', ['length' => 32, 'default' => '']);
        $Table->addColumn('package', 'string', ['length' => 255, 'default' => '']);
        $Table->addColumn('priority', 'integer', ['default' => 0]);
        $Table->addColumn('context', 'text', ['notnull' => false]);
        $Table->addColumn('de', 'text', ['notnull' => false]);
        $Table->addColumn('de_edit', 'text', ['notnull' => false]);
        $Table->addColumn('en', 'text', ['notnull' => false]);
        $Table->addColumn('en_edit', 'text', ['notnull' => false]);
        $Table->setPrimaryKey(['id']);

        $this->connection->createSchemaManager()->createTable($Table);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchRow(string $group, string $var): array | false
    {
        $Platform = $this->connection->getDatabasePlatform();

        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from(DoctrineUtils::quoteIdentifier(Translator::table()))
            ->where($Platform->quoteSingleIdentifier('groups') . ' = :group')
            ->andWhere($Platform->quoteSingleIdentifier('var') . ' = :var')
            ->setParameter('group', $group)
            ->setParameter('var', $var)
            ->executeQuery()
            ->fetchAssociative();
    }

    private function setConnection(Connection $Connection): void
    {
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $Connection);
    }
}
