<?php

namespace QUITests\Translator;

use PHPUnit\Framework\TestCase;
use QUI\Translator\LegacyPublicationCleanup;
use QUI\Translator\PublicationTransaction;

final class LegacyPublicationCleanupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/locale-migration-' . bin2hex(random_bytes(8)) . '/';
        mkdir($this->directory . 'bin/_contexts', 0700, true);
        mkdir($this->directory . '.context-snapshots');
        file_put_contents($this->directory . 'bin/_contexts/manifest.json', '{"format":2,"version":"current"}');
        file_put_contents($this->directory . '.context-snapshot.json', '{"format":1,"version":"current","rows":[]}');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                chmod($file->getPathname(), 0700);
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->directory);
    }

    private function cleanup(): void
    {
        PublicationTransaction::withLock($this->directory, fn () => LegacyPublicationCleanup::run($this->directory));
    }

    public function testMigrationRemovesGenerationsButPreservesCurrentAndUnrelatedFiles(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $version = md5((string)$index);
            mkdir($this->directory . 'bin/_contexts/' . $version . '/common', 0700, true);
            file_put_contents($this->directory . 'bin/_contexts/' . $version . '/common/de.js', 'old');
            file_put_contents($this->directory . '.context-snapshots/' . $version . '.json', 'old');
        }

        mkdir($this->directory . 'bin/_contexts/common');
        file_put_contents($this->directory . 'bin/_contexts/common/de.js', 'current');
        file_put_contents($this->directory . '.context-snapshots/notes.txt', 'keep');
        mkdir($this->directory . 'outside');
        file_put_contents($this->directory . 'outside/preserved', 'keep');
        symlink($this->directory . 'outside', $this->directory . 'bin/_contexts/' . md5('link'));
        $this->cleanup();

        self::assertSame(['common'], array_map('basename', glob($this->directory . 'bin/_contexts/*', GLOB_ONLYDIR)));
        self::assertSame('current', file_get_contents($this->directory . 'bin/_contexts/common/de.js'));
        self::assertSame(['notes.txt'], array_map('basename', glob($this->directory . '.context-snapshots/*')));
        self::assertFileExists($this->directory . 'outside/preserved');
        self::assertFileExists($this->directory . '.context-snapshot.json');
        self::assertFileExists($this->directory . '.context-storage-v2-cleaned');
    }

    public function testCompletedMigrationDoesNotInspectLocaleDirectoriesAgain(): void
    {
        $this->cleanup();
        // Removing the directory makes any subsequent enumeration or manifest read fail.
        unlink($this->directory . 'bin/_contexts/manifest.json');
        rmdir($this->directory . 'bin/_contexts');
        $this->cleanup();
        self::assertDirectoryDoesNotExist($this->directory . 'bin/_contexts');
    }

    public function testFailedCleanupIsRetriedWithoutRemovingCurrentFiles(): void
    {
        $old = $this->directory . 'bin/_contexts/' . md5('blocked');
        mkdir($old);
        file_put_contents($old . '/de.js', 'old');
        chmod($old, 0500);
        clearstatcache();

        try {
            if (is_writable($old)) {
                self::markTestSkipped('This user bypasses directory write permissions.');
            }

            try {
                $this->cleanup();
                self::fail('An incomplete cleanup must be reported.');
            } catch (\QUI\Exception $Exception) {
                self::assertStringContainsString('Unable to remove old locale', $Exception->getMessage());
            }

            self::assertFileDoesNotExist($this->directory . '.context-storage-v2-cleaned');
            self::assertFileExists($this->directory . '.context-snapshot.json');
        } finally {
            chmod($old, 0700);
        }

        $this->cleanup();
        self::assertDirectoryDoesNotExist($old);
        self::assertFileExists($this->directory . '.context-storage-v2-cleaned');
    }

    public function testPendingPublicationCannotBeCleaned(): void
    {
        file_put_contents($this->directory . '.publication-journal.json', '{}');

        try {
            LegacyPublicationCleanup::run($this->directory);
            self::fail('Pending publication must prevent cleanup.');
        } catch (\QUI\Exception $Exception) {
            self::assertStringContainsString('recovery is pending', $Exception->getMessage());
        }

        self::assertFileDoesNotExist($this->directory . '.context-storage-v2-cleaned');
    }
}
