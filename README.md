![QUIQQER Translator](bin/images/Readme.jpg)

QUIQQER Translator
========

The quiqqer/translation package extends QUIQQER with a translation panel and translation functions.
With the quiqqer/translator it is possible to edit / add or delete translation variables.
In addition, translations (locale.xml files) can be imported and exported.

Translations are stored in different formats, so that translations can be used for high performance.
JavaScript (client) and PHP (server) variables are processed separately and made available.

An attempt is made to access gettext (http://php.net/manual/de/book.gettext.php)
if this is not supported the server will use normal ini files.

Package name:

    quiqqer/translator


Features
--------

- Translation Management
- Translator API (XML, PHP, JavaScript)

Installation
------------

The package name is: quiqqer/translator

Locale publication and upgrades
------------------------------

Context manifest format 2 uses stable JavaScript paths. Each language has a frontend
and an administration bundle containing the shared translations. Custom contexts have
their own files; when custom contexts exist, a common module is also available for
standalone loading. Repeated publication replaces these files and a single private
`.context-snapshot.json`. Legacy PHP and JavaScript entry points remain available.

Publish the compatible Core patch (2.42.1 or later) before this Translator patch.
The Composer requirement prevents installing this Translator with the old loader.
The new Core loader supports both the old and new manifest formats, so Core can be
updated first. Do not downgrade Translator independently after migration.

The first successful publication, including publication during regular system or
package setup, removes the old hexadecimal version directories in
`var/locale/bin/_contexts/` and versioned JSON files in `.context-snapshots/`.
Cleanup runs under the publication lock only after the new files and cache completion
have succeeded and the recovery journal has been removed. Cleanup errors are reported;
the next publication retries unfinished work. Unrelated top-level files are preserved
and symlinks are not followed during deletion.

The `.context-storage-v2-cleaned` marker is written only after successful cleanup.
Subsequent publications check this marker and skip directory enumeration. Keep this
marker with the locale data when backing up or restoring an installation.

Open pages may need reloading once when upgrading from versioned paths. Afterwards,
custom contexts loaded by an open page can contain newer translations than its initial
bundle. The URL version remains a browser cache key, not a historical file selector.
For container builds, run setup before copying locale data into the final image layer.


Contribute
----------

- Issue Tracker: https://dev.quiqqer.com/quiqqer/package-translator/issues
- Source Code: https://dev.quiqqer.com/quiqqer/package-translator/tree/master


Support
-------

If you have found errors, wishes or suggestions for improvement,
you can contact us by email at support@pcsg.de.

We will try to meet your needs or send them to the responsible developers
of the project.


License
-------

- GPL-3.0+
