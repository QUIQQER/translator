<?php

/**
 * This file contains QUI\Translator\Setup
 */

namespace QUI\Translator;

use Doctrine\DBAL\Exception as DbalException;
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

        $quotedTable = DoctrineUtils::quoteIdentifier($table);
        $quotedId = DoctrineUtils::quoteIdentifier('id');
        $Connection = QUI::getDataBaseConnection();

        try {
            $Table = QUI::getSchemaManager()->introspectTableByUnquotedName($table);

            if (!$Table->hasColumn('id')) {
                $Connection->executeStatement("ALTER TABLE $quotedTable ADD $quotedId INT(11) DEFAULT NULL");
                $Connection->executeStatement('SET @count = 0');
                $Connection->executeStatement("UPDATE $quotedTable SET $quotedId = @count:= @count + 1");
            }

            try {
                $Connection->executeStatement("ALTER TABLE $quotedTable ADD PRIMARY KEY ($quotedId)");
            } catch (DbalException $Exception) {
                if (!str_contains($Exception->getMessage(), 'Multiple primary key defined')) {
                    throw $Exception;
                }
            }

            $Connection->executeStatement("ALTER TABLE $quotedTable MODIFY $quotedId INT(11) NOT NULL AUTO_INCREMENT");
        } catch (DbalException $Exception) {
            throw self::createDatabaseException($Exception);
        }

        self::patchForEmptyLocales();
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
