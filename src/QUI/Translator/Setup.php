<?php

/**
 * This file contains QUI\Translator\Setup
 */

namespace QUI\Translator;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use QUI;
use QUI\Database\Exception;
use QUI\Package\Package;
use QUI\Utils\Doctrine as DoctrineUtils;

/**
 * Class Setup
 * @package QUI\Translator
 */
class Setup
{
    protected static function introspectTranslatorTable(string $table): \Doctrine\DBAL\Schema\Table
    {
        if ($table === '') {
            throw new QUI\Exception('Database table name is not available');
        }

        try {
            $SchemaManager = QUI::getSchemaManager();

            // @phpstan-ignore function.alreadyNarrowedType
            if (method_exists($SchemaManager, 'introspectTableByUnquotedName')) {
                return $SchemaManager->introspectTableByUnquotedName($table);
            }

            return $SchemaManager->introspectTable($table);
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }
    }

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

        if ($table === '') {
            throw new QUI\Exception('Database table name is not available');
        }

        $Table = self::introspectTranslatorTable($table);

        if (!$Table->hasColumn('id')) {
            self::addMissingIdColumnOnMysql($table);
        }

        self::patchForEmptyLocales();
    }

    /**
     * Adds the legacy id column only for old MySQL/MariaDB translator tables.
     * PostgreSQL installations get the id column directly from database.xml.
     *
     * @throws Exception
     */
    protected static function addMissingIdColumnOnMysql(string $table): void
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();

        if (!$Platform instanceof AbstractMySQLPlatform) {
            return;
        }

        $quotedTable = DoctrineUtils::quoteIdentifier($table);
        $quotedId = DoctrineUtils::quoteIdentifier('id');

        try {
            $Connection->executeStatement("ALTER TABLE $quotedTable ADD $quotedId INT(11) DEFAULT NULL");
            $Connection->executeStatement('SET @count = 0');
            $Connection->executeStatement("UPDATE $quotedTable SET $quotedId = @count:= @count + 1");
            $Connection->executeStatement("ALTER TABLE $quotedTable ADD PRIMARY KEY ($quotedId)");
            $Connection->executeStatement("ALTER TABLE $quotedTable MODIFY $quotedId INT(11) NOT NULL AUTO_INCREMENT");
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }
    }

    /**
     * packages empty package fields
     * @throws Exception
     * @throws QUI\Exception
     */
    protected static function patchForEmptyLocales(): void
    {
        $table = QUI\Translator::table();

        if ($table === '') {
            throw new QUI\Exception('Database table name is not available');
        }

        $quotedTable = DoctrineUtils::quoteIdentifier($table);
        $Connection = QUI::getDataBaseConnection();

        try {
            $emptyLocales = $Connection->createQueryBuilder()
                ->select('*')
                ->from($quotedTable)
                ->where(DoctrineUtils::quoteIdentifier('package') . ' IS NULL')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($emptyLocales as $entry) {
                if (!isset($entry['id'])) {
                    continue;
                }

                $Connection->update(
                    $quotedTable,
                    [DoctrineUtils::quoteIdentifier('package') => $entry['groups']],
                    [DoctrineUtils::quoteIdentifier('id') => $entry['id']]
                );
            }
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }
    }

    protected static function createDatabaseException(DbalException $Exception): Exception
    {
        return new Exception($Exception->getMessage(), $Exception->getCode());
    }
}
