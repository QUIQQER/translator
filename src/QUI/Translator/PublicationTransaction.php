<?php

namespace QUI\Translator;

use QUI\Exception;
use Throwable;

/**
 * Stage a publication before replacing visible files, with durable rollback data.
 * The manifest is replaced last and also serves as the crash commit marker.
 */
final class PublicationTransaction
{
    /** @var array<string, resource> */
    private static array $locks = [];

    private string $directory;
    private string $staging;

    /** @var array<string, string> Target paths relative to the locale directory, mapped to staged files. */
    private array $files = [];

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/') . '/';
        $this->staging = '.publication-stage-' . bin2hex(random_bytes(12));
        self::mkdir($this->directory . $this->staging);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @param (callable(): void)|null $afterRecoveredCommit
     * @return T
     */
    public static function withLock(string $directory, callable $callback, ?callable $afterRecoveredCommit = null): mixed
    {
        $directory = rtrim($directory, '/') . '/';
        self::mkdir($directory);
        $identity = realpath($directory) ?: $directory;

        if (isset(self::$locks[$identity])) {
            return $callback();
        }

        $lock = fopen($directory . '.publication.lock', 'c');

        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new Exception('Unable to lock locale publication');
        }

        self::$locks[$identity] = $lock;

        try {
            $journal = self::readJournal($directory);

            if ($journal !== null) {
                if (self::isCommitted($directory, $journal)) {
                    if ($afterRecoveredCommit !== null) {
                        $afterRecoveredCommit();
                    }
                } else {
                    self::rollback($directory, $journal);
                }

                self::discardJournal($directory, $journal);
            }

            return $callback();
        } finally {
            unset(self::$locks[$identity]);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param (callable(): void)|null $afterRecoveredCommit */
    public static function recoverIfNeeded(string $directory, ?callable $afterRecoveredCommit = null): void
    {
        if (is_file(rtrim($directory, '/') . '/.publication-journal.json')) {
            self::withLock($directory, static function (): void {
            }, $afterRecoveredCommit);
        }
    }

    public function stage(string $file, string $content): void
    {
        if (!str_starts_with($file, $this->directory)) {
            throw new Exception('Locale publication target is outside its directory');
        }

        $relative = ltrim(substr($file, strlen($this->directory)), '/');
        self::validateRelativePath($relative);
        $staged = $this->files[$relative] ?? $this->staging . '/file-' . count($this->files);
        self::writeFile($this->directory . $staged, $content);
        $this->files[$relative] = $staged;
    }

    /** Replace all prepared files, leaving the journal until post-commit cache work succeeds. */
    public function commit(string $manifest): void
    {
        $manifest = ltrim(substr($manifest, strlen($this->directory)), '/');

        if (!isset($this->files[$manifest])) {
            throw new Exception('Locale publication has no staged manifest');
        }

        $manifestSource = $this->files[$manifest];
        unset($this->files[$manifest]);
        $this->files[$manifest] = $manifestSource;
        $entries = [];

        foreach ($this->files as $target => $source) {
            $file = $this->directory . $target;
            $backup = null;
            $originalHash = null;

            if (file_exists($file)) {
                if (!is_file($file)) {
                    throw new Exception('Invalid locale file target: ' . $file);
                }

                $backup = $this->staging . '/backup-' . count($entries);
                $content = self::readFile($file);
                $permissions = fileperms($file);
                self::writeFile(
                    $this->directory . $backup,
                    $content,
                    $permissions === false ? null : $permissions & 0777
                );
                $originalHash = hash('sha256', $content);
            }

            $entries[] = [
                'target' => $target,
                'source' => $source,
                'backup' => $backup,
                'originalHash' => $originalHash
            ];
        }

        $journal = [
            'staging' => $this->staging,
            'manifest' => $manifest,
            'manifestHash' => hash('sha256', self::readFile($this->directory . $manifestSource)),
            'files' => $entries
        ];
        self::writeFile($this->directory . '.publication-journal.json', json_encode($journal, JSON_THROW_ON_ERROR));

        try {
            foreach ($entries as $entry) {
                $file = $this->directory . $entry['target'];
                self::mkdir(dirname($file));
                self::writeFile($file, self::readFile($this->directory . $entry['source']));
            }
        } catch (Throwable $exception) {
            if (!self::isCommitted($this->directory, $journal)) {
                try {
                    self::rollback($this->directory, $journal);
                    self::discardJournal($this->directory, $journal);
                } catch (Throwable $rollbackError) {
                    throw new Exception(
                        'Locale publication failed; recovery is pending: ' . $rollbackError->getMessage(),
                        previous: $exception
                    );
                }
            }

            throw $exception;
        }
    }

    public function complete(): void
    {
        $journal = self::readJournal($this->directory);

        if ($journal !== null && $journal['staging'] === $this->staging) {
            self::discardJournal($this->directory, $journal);
        }
    }

    public function __destruct()
    {
        if (!is_file($this->directory . '.publication-journal.json')) {
            self::removeTree($this->directory . $this->staging);
        }
    }

    /** Atomically replace one file without changing existing read permissions. */
    public static function writeFile(string $file, string $content, ?int $permissions = null): void
    {
        $temporary = @tempnam(dirname($file), '.locale-');

        if ($temporary === false || dirname($temporary) !== realpath(dirname($file))) {
            if ($temporary !== false) {
                @unlink($temporary);
            }

            throw new Exception('Unable to create temporary locale file: ' . $file);
        }

        $handle = null;

        try {
            $handle = fopen($temporary, 'wb');

            if ($handle === false) {
                throw new Exception('Unable to open temporary locale file: ' . $file);
            }

            $offset = 0;
            $length = strlen($content);

            while ($offset < $length) {
                $written = fwrite($handle, substr($content, $offset));

                if ($written === false || $written === 0) {
                    throw new Exception('Unable to write locale file: ' . $file);
                }

                $offset += $written;
            }

            if (!fflush($handle) || !fsync($handle)) {
                throw new Exception('Unable to flush locale file: ' . $file);
            }

            fclose($handle);
            $handle = null;
            $existingPermissions = is_file($file) ? fileperms($file) : false;
            $permissions ??= $existingPermissions === false ? (0666 & ~umask()) : ($existingPermissions & 0777);

            if (!@chmod($temporary, $permissions) || !@rename($temporary, $file)) {
                throw new Exception('Unable to replace locale file: ' . $file);
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function readFile(string $file): string
    {
        $content = @file_get_contents($file);

        if ($content === false) {
            throw new Exception('Unable to read locale file: ' . $file);
        }

        return $content;
    }

    /** @return array{staging: string, manifest: string, manifestHash: string, files: list<array{target: string, source: string, backup: ?string, originalHash: ?string}>}|null */
    private static function readJournal(string $directory): ?array
    {
        $file = $directory . '.publication-journal.json';

        if (!is_file($file)) {
            return null;
        }

        $journal = json_decode(self::readFile($file), true, 512, JSON_THROW_ON_ERROR);

        if (
            !is_array($journal)
            || !is_string($journal['staging'] ?? null)
            || preg_match('/^\.publication-stage-[a-f0-9]{24}$/D', $journal['staging']) !== 1
            || !is_string($journal['manifest'] ?? null)
            || !is_string($journal['manifestHash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $journal['manifestHash']) !== 1
            || !is_array($journal['files'] ?? null)
            || !array_is_list($journal['files'])
        ) {
            throw new Exception('Invalid locale publication journal');
        }

        self::validateRelativePath($journal['manifest']);

        foreach ($journal['files'] as $entry) {
            if (
                !is_array($entry)
                || !is_string($entry['target'] ?? null)
                || !is_string($entry['source'] ?? null)
                || !str_starts_with($entry['source'], $journal['staging'] . '/file-')
                || !array_key_exists('backup', $entry)
                || !array_key_exists('originalHash', $entry)
                || ($entry['backup'] === null) !== ($entry['originalHash'] === null)
            ) {
                throw new Exception('Invalid locale publication journal entry');
            }

            self::validateRelativePath($entry['target']);
            self::validateRelativePath($entry['source']);

            if ($entry['backup'] !== null) {
                if (
                    !is_string($entry['backup'])
                    || !str_starts_with($entry['backup'], $journal['staging'] . '/backup-')
                    || !is_string($entry['originalHash'])
                    || preg_match('/^[a-f0-9]{64}$/D', $entry['originalHash']) !== 1
                ) {
                    throw new Exception('Invalid locale publication backup');
                }

                self::validateRelativePath($entry['backup']);
            }
        }

        return $journal;
    }

    /** @param array{manifest: string, manifestHash: string} $journal */
    private static function isCommitted(string $directory, array $journal): bool
    {
        $manifest = $directory . $journal['manifest'];
        return is_file($manifest) && hash_file('sha256', $manifest) === $journal['manifestHash'];
    }

    /** @param array{files: list<array{target: string, source: string, backup: ?string, originalHash: ?string}>} $journal */
    private static function rollback(string $directory, array $journal): void
    {
        foreach (array_reverse($journal['files']) as $entry) {
            $target = $directory . $entry['target'];

            if ($entry['backup'] === null) {
                if (is_file($target) && !@unlink($target)) {
                    throw new Exception('Unable to remove uncommitted locale file: ' . $target);
                }

                $parent = dirname($target);

                while ($parent !== rtrim($directory, '/') && @rmdir($parent)) {
                    $parent = dirname($parent);
                }

                continue;
            }

            if (is_file($target) && hash_file('sha256', $target) === $entry['originalHash']) {
                continue;
            }

            $backup = $directory . $entry['backup'];

            if (!is_file($backup) || hash_file('sha256', $backup) !== $entry['originalHash']) {
                throw new Exception('Invalid locale file backup: ' . $target);
            }

            if (!@rename($backup, $target)) {
                throw new Exception('Unable to restore locale file: ' . $target);
            }
        }
    }

    /** @param array{staging: string} $journal */
    private static function discardJournal(string $directory, array $journal): void
    {
        // Remove the marker first: a crash during cleanup must not revive an old rollback.
        if (!@unlink($directory . '.publication-journal.json')) {
            throw new Exception('Unable to finish locale publication journal');
        }

        self::removeTree($directory . $journal['staging']);
    }

    private static function validateRelativePath(string $path): void
    {
        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, "\0")
            || in_array('..', explode('/', $path), true)
        ) {
            throw new Exception('Invalid locale publication path');
        }
    }

    private static function mkdir(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new Exception('Unable to create locale file directory: ' . $directory);
        }
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            if ($entry->isDir() && !$entry->isLink()) {
                self::removeTree($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($directory);
    }
}
