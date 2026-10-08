<?php

namespace App\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class MigrationTest extends TestCase
{
    private string $database;
    private string $url;
    private ?\Doctrine\DBAL\Connection $admin = null;
    private ?string $mysqlDatabase = null;

    protected function setUp(): void
    {
        if ($mysqlUrl = getenv('MIGRATION_DATABASE_URL')) {
            $this->admin = DriverManager::getConnection(['url' => $mysqlUrl]);
            $this->mysqlDatabase = 'propert_migration_'.bin2hex(random_bytes(6));
            $this->admin->createSchemaManager()->createDatabase($this->mysqlDatabase);
            $this->url = preg_replace('#/[^/?]+(\?.*)?$#', '/'.$this->mysqlDatabase.'$1', $mysqlUrl);
            // Doctrine's test configuration appends this suffix to MySQL database names.
            $this->admin->createSchemaManager()->createDatabase($this->mysqlDatabase.'_test');
            return;
        }
        $this->database = tempnam(sys_get_temp_dir(), 'propert-migrate-');
        $this->url = 'sqlite:///'.str_replace('\\', '/', $this->database);
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null) {
            $this->admin->createSchemaManager()->dropDatabase($this->mysqlDatabase.'_test');
            $this->admin->createSchemaManager()->dropDatabase($this->mysqlDatabase);
            $this->admin->close();
            return;
        }
        @unlink($this->database);
    }

    private function console(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), 'bin/console', ...$arguments, '--env=test', '--no-debug', '--no-interaction'], dirname(__DIR__, 2), ['DATABASE_URL' => $this->url, 'SYMFONY_DOTENV_VARS' => false]);
        $process->run();
        return $process;
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        $url = $this->mysqlDatabase === null ? $this->url : preg_replace('#/'.$this->mysqlDatabase.'(\?.*)?$#', '/'.$this->mysqlDatabase.'_test$1', $this->url);
        return DriverManager::getConnection(['url' => $url]);
    }

    public function testFreshDatabaseMatchesMapping(): void
    {
        $migrate = $this->console(['doctrine:migrations:migrate']);
        self::assertSame(0, $migrate->getExitCode(), $migrate->getOutput().$migrate->getErrorOutput());
        $validate = $this->console(['doctrine:schema:validate']);
        self::assertSame(0, $validate->getExitCode(), $validate->getOutput().$validate->getErrorOutput());
        $connection = $this->connection();
        self::assertTrue($connection->createSchemaManager()->listTableColumns('project')['owner_id']->getNotnull());
        $connection->close();
    }

    public function testLegacyRecordsRequireExplicitAssignment(): void
    {
        $baseline = $this->console(['doctrine:migrations:migrate', 'DoctrineMigrations\\Version20261008000100']);
        self::assertSame(0, $baseline->getExitCode(), $baseline->getOutput().$baseline->getErrorOutput());
        $connection = $this->connection();
        $connection->insert('user', ['email' => 'Legacy@Example.com', 'roles' => '["ROLE_USER"]', 'password' => 'legacy-hash', 'first_name' => 'Legacy', 'last_name' => 'User', 'phone' => '1']);
        $userId = (int) $connection->lastInsertId();
        $connection->insert('project', ['name' => 'Legacy Home', 'label' => 'Home', 'number_of_floors' => 1, 'address' => 'Street', 'postal_code' => '01234', 'active' => 1]);
        $migrate = $this->console(['doctrine:migrations:migrate']);
        self::assertNotSame(0, $migrate->getExitCode());
        self::assertStringContainsString('unowned', $migrate->getOutput().$migrate->getErrorOutput());
        self::assertSame('legacy@example.com', $connection->fetchOne('SELECT email FROM user'));
        $assign = $this->console(['app:projects:assign-owner', 'legacy@example.com']);
        self::assertSame(0, $assign->getExitCode(), $assign->getOutput().$assign->getErrorOutput());
        self::assertSame($userId, (int) $connection->fetchOne('SELECT owner_id FROM project'));
        $migrate = $this->console(['doctrine:migrations:migrate']);
        self::assertSame(0, $migrate->getExitCode(), $migrate->getOutput().$migrate->getErrorOutput());
        self::assertSame('Legacy Home', $connection->fetchOne('SELECT name FROM project'));
        $connection->insert('user', ['email' => 'second@example.com', 'roles' => '[]', 'password' => 'hash', 'first_name' => 'Second', 'last_name' => 'User', 'phone' => '2']);
        $assign = $this->console(['app:projects:assign-owner', 'second@example.com']);
        self::assertSame(0, $assign->getExitCode());
        self::assertSame($userId, (int) $connection->fetchOne('SELECT owner_id FROM project'));
        $connection->close();
    }

    public function testNormalizedEmailCollisionStopsUpgrade(): void
    {
        self::assertSame(0, $this->console(['doctrine:migrations:migrate', 'DoctrineMigrations\\Version20261008000100'])->getExitCode());
        $connection = $this->connection();
        if ($this->mysqlDatabase !== null) {
            // Baseline MySQL's case-insensitive collation already rejects case-only duplicates.
            $connection->executeStatement('ALTER TABLE user MODIFY email VARCHAR(180) COLLATE utf8mb4_bin NOT NULL');
        }
        foreach (['Ada@example.com', 'ada@example.com'] as $email) {
            $connection->insert('user', ['email' => $email, 'roles' => '[]', 'password' => 'hash', 'first_name' => 'Ada', 'last_name' => 'User', 'phone' => '1']);
        }
        $migrate = $this->console(['doctrine:migrations:migrate']);
        self::assertNotSame(0, $migrate->getExitCode());
        self::assertStringContainsString('collision', $migrate->getOutput().$migrate->getErrorOutput());
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM user'));
        $connection->close();
    }
}
