<?php

/**
 * This file contains QUI\Translator\Setup
 */

namespace QUI\Translator;

use QUI;
use QUI\Database\Exception;
use QUI\Package\Package;
use QUI\Database\Tables;
use PDO;

/**
 * Class Setup
 * @package QUI\Translator
 */
class Setup
{
    /**
     * @param Package $Package
     * @throws QUI\Exception
     */
    public static function onPackageSetup(Package $Package): void
    {
        if ($Package->getName() !== 'quiqqer/translator') {
            return;
        }

        $table = QUI\Translator::table();
        $Table = self::requireTableManager();

        // id field
        $exists = $Table->getColumn($table, 'id');

        if (!empty($exists)) {
            $Table->setPrimaryKey($table, 'id');
            self::patchForEmptyLocales();

            return;
        }

        // create id column for old translation table
        $Table->addColumn($table, [
            'id' => 'INT(11) DEFAULT NULL'
        ]);

        $PDO = self::requirePDO();

        $PDO->query(
            "SET @count = 0;
            UPDATE `$table` SET `$table`.`id` = @count:= @count + 1;"
        );

        $Table->setPrimaryKey($table, 'id');
        $Table->setAutoIncrement($table, 'id');

        self::patchForEmptyLocales();
    }

    /**
     * packages empty package fields
     * @throws Exception
     */
    protected static function patchForEmptyLocales(): void
    {
        $table = QUI\Translator::table();

        // update empty package fields
        $emptyLocales = QUI::getDataBase()->fetch([
            'from' => $table,
            'where' => [
                'package' => null
            ]
        ]);

        foreach ($emptyLocales as $entry) {
            if (!isset($entry['id'])) {
                continue;
            }

            QUI::getDataBase()->update(
                $table,
                ['package' => $entry['groups']],
                ['id' => $entry['id']]
            );
        }
    }

    /**
     * @throws QUI\Exception
     */
    protected static function requireTableManager(): Tables
    {
        $Table = QUI::getDataBase()->table();

        if ($Table === null) {
            throw new QUI\Exception('Database table manager is not available');
        }

        return $Table;
    }

    /**
     * @throws QUI\Exception
     */
    protected static function requirePDO(): PDO
    {
        $PDO = QUI::getDataBase()->getPDO();

        if ($PDO === null) {
            throw new QUI\Exception('Database PDO connection is not available');
        }

        return $PDO;
    }
}
