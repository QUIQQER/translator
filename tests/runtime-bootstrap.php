<?php

/** Run package tests against disposable data, never the surrounding installation. */

foreach (['CMS_DIR', 'ETC_DIR', 'VAR_DIR', 'USR_DIR', 'OPT_DIR'] as $constant) {
    if (defined($constant)) {
        throw new RuntimeException('Translator tests must initialize their isolated runtime before QUIQQER.');
    }
}

$runtime = sys_get_temp_dir() . '/quiqqer-translator-test-' . bin2hex(random_bytes(16)) . '/';
$packages = dirname(__DIR__, 3) . '/';
mkdir($runtime, 0700);

$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (new FilesystemIterator($path) as $entry) {
        $remove($entry->getPathname());
    }

    rmdir($path);
};

// Register cleanup last at shutdown, after framework shutdown handlers finish.
register_shutdown_function(static function () use ($remove, $runtime): void {
    register_shutdown_function(static fn() => $remove($runtime));
});

foreach (['etc/plugins/quiqqer', 'var/cache', 'usr'] as $directory) {
    mkdir($runtime . $directory, 0700, true);
}

symlink($packages, $runtime . 'packages');
$config = [
    'globals' => [
        'cms_dir' => $runtime,
        'var_dir' => $runtime . 'var/',
        'usr_dir' => $runtime . 'usr/',
        'opt_dir' => $runtime . 'packages/',
        'url_dir' => '/',
        'url_bin_dir' => '/bin/',
        'url_lib_dir' => '/lib/',
        'url_sys_dir' => '/admin/',
        'development' => '0',
        'debug_mode' => '0',
        'timezone' => 'Europe/Berlin',
        'standardLanguage' => 'en',
        'lang' => 'en'
    ],
    'db' => [
        'driver' => 'pdo_sqlite',
        'path' => $runtime . 'database.sqlite',
        'prfx' => 'test_'
    ]
];
$ini = '';

foreach ($config as $section => $values) {
    $ini .= '[' . $section . ']' . PHP_EOL;

    foreach ($values as $key => $value) {
        $ini .= $key . '="' . $value . '"' . PHP_EOL;
    }
}

file_put_contents($runtime . 'etc/conf.ini.php', $ini);
file_put_contents($runtime . 'etc/cache.ini.php', "[handlers]\nfilesystem=1\n");
file_put_contents($runtime . 'etc/projects.ini.php', '');
file_put_contents($runtime . 'etc/plugins/quiqqer/translator.ini.php', '[locale]');
file_put_contents(
    $runtime . 'etc/plugins/quiqqer/log.ini.php',
    "[log_levels]\ndebug=false\nerror=false\nwarning=false\nalert=false\n"
);
file_put_contents($runtime . 'var/cache/events.php', '<?php return ["events" => [], "siteEvents" => []];');
define('ETC_DIR', $runtime . 'etc/');
define('CACHE', false);
define('HOST', 'localhost');
define('URL_OPT_DIR', '/packages/');
ini_set('error_log', $runtime . 'php-errors.log');
putenv('QUIQQER_OTHER_AUTOLOADERS=KEEP');
require $packages . 'quiqqer/core/src/autoload.php';
QUI::load();
QUI::$Locale = new QUI\Locale();
QUI\Update::importDatabase(dirname(__DIR__) . '/database.xml');
QUI\Translator::addLang('de');
QUI\Translator::addLang('en');

foreach (
    [
        'Mcp/Server/Builder.php',
        'Mcp/Schema/Result/CallToolResult.php',
        'QUI/AI/MCP/ProviderInterface.php',
        'QUI/AI/MCP/Server.php',
        'QUI/AI/MCP/ToolHelper.php'
    ] as $stub
) {
    require_once __DIR__ . '/stubs/' . $stub;
}
