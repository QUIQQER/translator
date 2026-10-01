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
