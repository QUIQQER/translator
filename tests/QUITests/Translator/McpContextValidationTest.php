<?php

namespace QUITests\Translator;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Translator;
use QUI\Translator\MCP\Provider;
use ReflectionMethod;
use ReflectionProperty;

class McpContextValidationTest extends TestCase
{
    private Connection $originalConnection;
    private Connection $connection;
    private ?QUI\Locale $originalLocale;
    private ?QUI\Events\Manager $originalEvents;
    private QUI\Config $Config;
    private mixed $development;
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        if (!interface_exists(QUI\AI\MCP\ProviderInterface::class)) {
            require_once dirname(__DIR__, 2) . '/stubs/QUI/AI/MCP/ProviderInterface.php';
        }

        $this->originalConnection = QUI::getDataBaseConnection();
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $this->connection);

        $Table = new Table(Translator::table());
        $Table->addColumn('id', 'integer', ['autoincrement' => true]);
        $Table->addColumn('groups', 'string');
        $Table->addColumn('var', 'string');
        $Table->addColumn('package', 'string', ['default' => '']);
        $Table->addColumn('datatype', 'string', ['default' => 'php,js']);
        $Table->addColumn('html', 'integer', ['default' => 0]);
        $Table->addColumn('priority', 'integer', ['default' => 0]);
        $Table->addColumn('context', 'text', ['notnull' => false]);
        $Table->addColumn('de', 'text', ['notnull' => false]);
        $Table->addColumn('de_edit', 'text', ['notnull' => false]);
        $Table->setPrimaryKey(['id']);
        $this->connection->createSchemaManager()->createTable($Table);

        $this->directory = sys_get_temp_dir() . '/translator-mcp-context-' . bin2hex(random_bytes(8)) . '/';
        mkdir($this->directory, 0700);
        $this->originalLocale = QUI::$Locale;
        $this->originalEvents = QUI::$Events;
        $Locale = $this->createMock(QUI\Locale::class);
        $Locale->method('dir')->willReturn($this->directory);
        QUI::$Locale = $Locale;
        QUI::$Events = $this->createMock(QUI\Events\Manager::class);
        $this->Config = QUI::getConfig('etc/conf.ini.php');
        $this->development = $this->Config->get('globals', 'development');
        $this->Config->set('globals', 'development', 0);
    }

    protected function tearDown(): void
    {
        $this->Config->set('globals', 'development', $this->development);
        QUI::$Locale = $this->originalLocale;
        QUI::$Events = $this->originalEvents;
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $this->originalConnection);
        $this->connection->close();

        foreach (glob($this->directory . '.*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->directory);
        parent::tearDown();
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidContexts(): iterable
    {
        yield 'reserved context name' => ['Common'];
        yield 'malformed context list' => ['frontend,,administration'];
        yield 'non-string' => [12];
        yield 'explicit null' => [null];
    }

    #[DataProvider('invalidContexts')]
    public function testInvalidNewContextDoesNotCreateAnEntry(mixed $context): void
    {
        try {
            $this->save(['context' => $context]);
            self::fail('Invalid context must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->rows());
        }
    }

    #[DataProvider('invalidContexts')]
    public function testInvalidContextDoesNotChangeExistingEntry(mixed $context): void
    {
        $this->insertExistingEntry();
        $before = $this->rows();

        try {
            $this->save(['context' => $context]);
            self::fail('Invalid context must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame($before, $this->rows());
        }
    }

    public function testCustomContextIsNormalizedBeforeSaving(): void
    {
        $result = $this->save(['context' => ' namescoreDashboard,administration, namescoreDashboard ']);
        $row = $this->rows()[0];

        self::assertSame(1, $result['count']);
        self::assertSame([], $result['publishedGroups']);
        self::assertSame('administration,namescoreDashboard', $row['context']);
        self::assertSame('Changed text', $row['de_edit']);
    }

    /** @return iterable<string, array{array<string, string>, ?string}> */
    public static function optionalContexts(): iterable
    {
        yield 'omitted' => [[], null];
        yield 'explicitly empty' => [['context' => ''], ''];
        yield 'whitespace' => [['context' => '  '], ''];
    }

    #[DataProvider('optionalContexts')]
    public function testNewEntryPreservesOptionalContextSemantics(array $data, ?string $expected): void
    {
        $this->save($data);

        self::assertSame($expected, $this->rows()[0]['context']);
    }

    #[DataProvider('optionalContexts')]
    public function testExistingEntryPreservesOmittedContextAndResetsExplicitEmpty(array $data, ?string $expected): void
    {
        $this->insertExistingEntry();
        $this->save($data);
        $row = $this->rows()[0];

        self::assertSame($expected ?? 'namescoreDashboard', $row['context']);
        self::assertSame('Changed text', $row['de_edit']);
        self::assertSame('Original text', $row['de']);
    }

    private function insertExistingEntry(): void
    {
        $this->connection->insert(Translator::table(), [
            'groups' => 'test/mcp-context',
            'var' => 'title',
            'package' => 'test/mcp-context',
            'context' => 'namescoreDashboard',
            'de' => 'Original text',
            'de_edit' => 'Original individual text'
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->connection->quoteIdentifier(Translator::table()))
            ->orderBy('id')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $data
     * @return array{saved: array<int, array<string, mixed>>, count: int, publishedGroups: list<string>}
     */
    private function save(array $data): array
    {
        $variable = $data + [
            'group' => 'test/mcp-context',
            'var' => 'title',
            'package' => 'test/mcp-context',
            'translations' => ['de' => 'Changed text']
        ];
        $Method = new ReflectionMethod(Provider::class, 'saveLanguageVariables');

        return $Method->invoke(null, [$variable], false);
    }
}
