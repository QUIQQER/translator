<?php

namespace QUI\Translator;

use QUI;

class DoctrineHelper
{
    public static function quoteIdentifier(string $identifier): string
    {
        if (method_exists('QUI\Utils\Doctrine', 'quoteIdentifier')) {
            return \QUI\Utils\Doctrine::quoteIdentifier($identifier);
        }

        return QUI::getDataBaseConnection()
            ->getDatabasePlatform()
            ->quoteSingleIdentifier($identifier);
    }
}
