<?php

namespace QUITests\Translator;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Translator;
use Symfony\Component\Process\Process;

class RuntimeIsolationTest extends TestCase
{
    public function testChildWritesOnlyToItsDisposableRuntime(): void
    {
        $version = Translator::getLocalePublishVersion();
        $Config = QUI::getPackage('quiqqer/translator')->getConfig();
        self::assertNotNull($Config);
        $configFile = $Config->getFilename();
        $configuration = file_get_contents($configFile);
        $database = QUI::getDataBaseConnection()->getParams()['path'];
        $rows = Translator::get();
        $localeDirectory = Translator::dir();
        QUI\Cache\Manager::set('translator/runtime-isolation', 'parent-cache');
        $fixture = dirname(__DIR__, 2) . '/Fixtures/runtime-isolation.php';
        $Process = new Process(
            [PHP_BINARY, $fixture],
            cwd: dirname(__DIR__, 3),
            timeout: 30
        );
        $Process->mustRun();
        $result = json_decode($Process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        self::assertNotSame(CMS_DIR, $result['runtime']);
        self::assertStringStartsWith(sys_get_temp_dir() . '/quiqqer-translator-test-', $result['runtime']);

        foreach (['config_path', 'database_path', 'locale_path'] as $key) {
            self::assertStringStartsWith($result['runtime'], $result[$key]);
        }

        self::assertNotSame($configFile, $result['config_path']);
        self::assertNotSame($database, $result['database_path']);
        self::assertNotSame($localeDirectory, $result['locale_path']);
        self::assertSame('child-config', $result['config_value']);
        self::assertSame('child-version', $result['locale_version']);
        self::assertSame('Child database value', $result['database_value']);
        self::assertSame('child-cache', $result['cache_value']);
        self::assertNotEmpty($result['cache_files']);

        foreach ($result['cache_files'] as $file) {
            self::assertStringStartsWith($result['runtime'], $file);
        }

        self::assertDirectoryDoesNotExist($result['runtime']);
        self::assertSame($configuration, file_get_contents($configFile));
        self::assertSame($rows, Translator::get());
        self::assertSame($version, Translator::getLocalePublishVersion());
        self::assertSame('parent-cache', QUI\Cache\Manager::get('translator/runtime-isolation'));
    }
}
