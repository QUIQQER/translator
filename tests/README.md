# Translator tests

Run the package suite from the package directory:

```sh
./tools/phpunit
```

The default bootstrap creates a disposable SQLite database, configuration,
filesystem cache and locale directory. It requires `pdo_sqlite` and the installed
QUIQQER dependencies. It does not load the surrounding installation's bootstrap
or copy its database credentials. Installed packages supply code and catalog
fixtures; generated files remain in the private runtime, which is removed at
process shutdown. Do not run application setup through this bootstrap: package
source directories are shared with the surrounding installation.

`RuntimeIsolationTest` exercises real configuration, database and cache writes
in a second process and verifies cleanup and separation from the parent runtime.
The publication tests exercise generated PHP and JavaScript files and rollback;
their mocked event dispatcher does not verify the complete cache integration.
The combined publication, cache handling and browser execution flow requires
separate integration validation.

Focused regression checks:

```sh
./tools/phpunit --filter 'ConflictingReferenced|FailedCommit|ContextEditorLabel|RuntimeIsolation'
```

Conflicting referenced language files are tested through both public package
import APIs, including unchanged database contents after rejection. The editor
label test imports the package catalog and checks the published German and
English administration modules.
