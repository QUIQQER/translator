<?php

require dirname(__DIR__) . '/phpunit-bootstrap.php';

$Config = QUI::getPackage('quiqqer/translator')->getConfig();

if ($Config === null) {
    throw new RuntimeException('The isolated Translator package configuration is missing.');
}

$Config->set('isolation', 'sentinel', 'child-config');
$Config->set('locale', 'publishVersion', 'child-version');
$Config->save();
$SavedConfig = new QUI\Config($Config->getFilename());

QUI\Translator::add('test/runtime-isolation', 'child', 'test/runtime-isolation');
QUI\Translator::update('test/runtime-isolation', 'child', 'test/runtime-isolation', [
    'de' => 'Child database value'
]);
$rows = QUI\Translator::get('test/runtime-isolation', 'child', 'test/runtime-isolation');

$snapshotCache = static function (): array {
    $snapshot = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(VAR_DIR . 'cache/', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->isFile()) {
            $snapshot[$file->getPathname()] = hash_file('sha256', $file->getPathname());
        }
    }

    return $snapshot;
};

$cacheBefore = $snapshotCache();
QUI\Cache\Manager::set('translator/runtime-isolation', 'child-cache');
$cacheValue = QUI\Cache\Manager::get('translator/runtime-isolation');
$cacheFiles = array_keys(array_diff_assoc($snapshotCache(), $cacheBefore));

$result = [
    'runtime' => CMS_DIR,
    'config_path' => $Config->getFilename(),
    'database_path' => QUI::getDataBaseConnection()->getParams()['path'],
    'locale_path' => QUI\Translator::dir(),
    'config_value' => $SavedConfig->get('isolation', 'sentinel'),
    'locale_version' => $SavedConfig->get('locale', 'publishVersion'),
    'database_value' => $rows[0]['de'] ?? null,
    'cache_value' => $cacheValue,
    'cache_files' => $cacheFiles
];

echo json_encode($result, JSON_THROW_ON_ERROR);
