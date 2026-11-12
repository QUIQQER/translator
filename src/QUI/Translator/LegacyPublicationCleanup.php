<?php

namespace QUI\Translator;

use DirectoryIterator;
use QUI\Exception;

/**
 * Remove accumulated locale generations and snapshots once after migrating to stable paths.
 * Cleanup runs only after publication and cache completion have succeeded.
 *
 * @see https://dev.quiqqer.com/quiqqer/core/-/work_items/1583
 */
final class LegacyPublicationCleanup
{
    /**
     * Call under the publication lock, after the transaction journal has been removed.
     * The marker belongs to the locale directory so restored installations migrate independently.
     */
    public static function run(string $directory): void
    {
        $directory = rtrim($directory, '/') . '/';
        $marker = $directory . '.context-storage-v2-cleaned';

        if (is_file($marker)) {
            return;
        }

        if (is_file($directory . '.publication-journal.json')) {
            throw new Exception('Cannot clean locale publications while recovery is pending');
        }

        foreach (['bin', 'bin/_contexts', '.context-snapshots'] as $path) {
            if (is_link($directory . $path)) {
                throw new Exception('Cannot clean a linked locale directory: ' . $path);
            }
        }

        $manifest = json_decode((string)file_get_contents($directory . 'bin/_contexts/manifest.json'), true);

        if (($manifest['format'] ?? null) !== 2 || !is_file($directory . '.context-snapshot.json')) {
            throw new Exception('Cannot clean locale publications before stable paths have been published');
        }

        foreach (new DirectoryIterator($directory . 'bin/_contexts') as $entry) {
            if (preg_match('/^[a-f0-9]{32}$/D', $entry->getFilename()) === 1) {
                self::remove($entry->getPathname());
            }
        }

        $snapshots = $directory . '.context-snapshots';

        if (is_dir($snapshots)) {
            foreach (new DirectoryIterator($snapshots) as $entry) {
                if (preg_match('/^[a-f0-9]{32}\.json$/D', $entry->getFilename()) === 1) {
                    self::remove($entry->getPathname());
                }
            }

            // Preserve any unrelated files rather than deleting the entire directory.
            if (iterator_count(new \FilesystemIterator($snapshots)) === 0 && !@rmdir($snapshots)) {
                throw new Exception('Unable to remove old locale snapshot directory: ' . $snapshots);
            }
        }

        PublicationTransaction::writeFile($marker, "2\n");
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (!@unlink($path)) {
                throw new Exception('Unable to remove old locale publication: ' . $path);
            }

            return;
        }

        foreach (new DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) {
                self::remove($entry->getPathname());
            }
        }

        if (!@rmdir($path)) {
            throw new Exception('Unable to remove old locale publication directory: ' . $path);
        }
    }
}
