<?php

namespace QUITests\Translator;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Translator;
use QUI\Translator\DoctrineHelper as DoctrineUtils;
use ReflectionProperty;

class TranslatorSqliteTest extends TestCase
{
    private Connection $originalConnection;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = QUI::getDataBaseConnection();
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);

        $this->setConnection($this->connection);
        $this->createTranslatorTable();
    }

    protected function tearDown(): void
    {
        $this->setConnection($this->originalConnection);
        $this->connection->close();

        parent::tearDown();
    }

    public function testLangsUsesSqliteSchemaMetadata(): void
    {
        self::assertSame(['de', 'en'], Translator::langs());
    }

    public function testAddEditDeleteCrudOnSqlite(): void
    {
        $group = 'phpunit/translator-sqlite';
        $var = 'crud';
        $package = 'phpunit/translator';

        Translator::add($group, $var, $package, 'js', true);

        $row = $this->fetchRow($group, $var);
        self::assertIsArray($row);
        self::assertSame($package, $row['package']);
        self::assertSame('js', $row['datatype']);
        self::assertSame(1, (int)$row['html']);

        Translator::edit($group, $var, $package, [
            'de' => ' Deutscher Text ',
            'en_edit' => ' English text ',
            'datatype' => 'php',
            'priority' => 7
        ]);

        $row = $this->fetchRow($group, $var);
        self::assertIsArray($row);
        self::assertSame('Deutscher Text', $row[QUI::conf('globals', 'development') ? 'de' : 'de_edit']);
        self::assertSame('English text', $row['en_edit']);
        self::assertSame('php', $row['datatype']);
        self::assertSame(7, (int)$row['priority']);

        Translator::delete($group, $var);

        self::assertFalse($this->fetchRow($group, $var));
    }

    public function testAddUserVarInsertsAndUpdatesOnSqlite(): void
    {
        $group = 'phpunit/translator-sqlite';
        $var = 'user-var';
        $package = 'phpunit/translator';

        Translator::addUserVar($group, $var, [
            'package' => $package,
            'de' => ' Erster Text ',
            'en' => ' First text ',
            'datatype' => 'php,js',
            'html' => 1,
            'priority' => 3
        ]);

        $row = $this->fetchRow($group, $var);
        self::assertIsArray($row);
        self::assertSame('Erster Text', $row['de_edit']);
        self::assertSame('First text', $row['en_edit']);
        self::assertSame(1, (int)$row['html']);
        self::assertSame(3, (int)$row['priority']);

        Translator::addUserVar($group, $var, [
            'package' => $package,
            'de' => ' Aktualisierter Text '
        ]);

        $row = $this->fetchRow($group, $var);
        self::assertIsArray($row);
        self::assertSame('Aktualisierter Text', $row['de_edit']);

        Translator::delete($group, $var);
    }

    private function createTranslatorTable(): void
    {
        $Table = new Table(Translator::table());
        $Table->addColumn('id', 'integer', ['autoincrement' => true]);
        $Table->addColumn('groups', 'string', ['length' => 255]);
        $Table->addColumn('var', 'string', ['length' => 255]);
        $Table->addColumn('html', 'integer', ['default' => 0]);
        $Table->addColumn('datatype', 'string', ['length' => 32, 'default' => 'php,js']);
        $Table->addColumn('datadefine', 'string', ['length' => 32, 'default' => '']);
        $Table->addColumn('package', 'string', ['length' => 255, 'default' => '']);
        $Table->addColumn('priority', 'integer', ['default' => 0]);
        $Table->addColumn('de', 'text', ['notnull' => false]);
        $Table->addColumn('de_edit', 'text', ['notnull' => false]);
        $Table->addColumn('en', 'text', ['notnull' => false]);
        $Table->addColumn('en_edit', 'text', ['notnull' => false]);
        $Table->setPrimaryKey(['id']);

        $this->connection->createSchemaManager()->createTable($Table);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchRow(string $group, string $var): array | false
    {
        $Platform = $this->connection->getDatabasePlatform();

        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from(DoctrineUtils::quoteIdentifier(Translator::table()))
            ->where($Platform->quoteSingleIdentifier('groups') . ' = :group')
            ->andWhere($Platform->quoteSingleIdentifier('var') . ' = :var')
            ->setParameter('group', $group)
            ->setParameter('var', $var)
            ->executeQuery()
            ->fetchAssociative();
    }

    private function setConnection(Connection $Connection): void
    {
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $Connection);
    }
}
