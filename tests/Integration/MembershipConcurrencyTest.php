<?php

namespace App\Tests\Integration;

use App\Enum\MembershipRole;
use App\Service\MembershipManager;
use App\Tests\Support\FailingMembershipConnection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[Group('mysql')]
final class MembershipConcurrencyTest extends TestCase
{
    private ?Connection $connection = null;
    private ?Connection $observer = null;
    private string $url;
    private ?int $organizationId = null;
    private array $users = [];
    private array $memberships = [];
    private array $workers = [];

    protected function setUp(): void
    {
        $url = getenv('MYSQL_TEST_DATABASE_URL');

        if ($url === false || $url === '') {
            self::markTestSkipped('MYSQL_TEST_DATABASE_URL is not configured.');
        }

        $this->url = $url;
        $this->connection = DriverManager::getConnection(['url' => $url]);
        self::assertSame('propert_ease_concurrency_test', $this->connection->fetchOne('SELECT DATABASE()'));
        $this->connection->setNestTransactionsWithSavepoints(true);

        $observerUrl = getenv('MYSQL_TEST_OBSERVER_DATABASE_URL');
        self::assertNotEmpty($observerUrl, 'Set MYSQL_TEST_OBSERVER_DATABASE_URL for lock-wait observation.');
        $this->observer = DriverManager::getConnection(['url' => $observerUrl]);
        self::assertSame('propert_ease_concurrency_test', $this->observer->fetchOne('SELECT DATABASE()'));
        self::assertSame(
            $this->connection->fetchOne('SELECT @@server_uuid'),
            $this->observer->fetchOne('SELECT @@server_uuid'),
            'The observer must connect to the same MySQL server.'
        );

        $this->connection->insert('organization', ['name' => 'Concurrency test']);
        $this->organizationId = (int) $this->connection->lastInsertId();
        $this->addMember(MembershipRole::Owner);
        $this->addMember(MembershipRole::Owner);
    }

    protected function tearDown(): void
    {
        if ($this->connection?->isTransactionActive()) {
            $this->connection->rollBack();
        }

        foreach ($this->workers as $worker) {
            $worker->stop(1);
        }

        if ($this->organizationId !== null) {
            $this->connection->delete('membership', ['organization_id' => $this->organizationId]);
            $this->connection->delete('organization', ['id' => $this->organizationId]);
        }

        foreach ($this->users as $user) {
            $this->connection->delete('user', ['id' => $user['id']]);
        }

        $this->observer?->close();
        $this->connection?->close();
    }

    #[DataProvider('concurrentOperations')]
    public function testConcurrentMutationsRetainAnOwner(array $operations): void
    {
        $this->holdOrganizationLock();

        foreach ($operations as $index => $operation) {
            $this->startWorker($operation, (string) $this->memberships[$index], $this->users[$index]['id']);
        }

        $this->awaitBlockedWorkers();
        $this->connection->commit();
        $results = $this->finishWorkers();
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertContains($statuses[0], [200, 204]);
        self::assertSame(409, $statuses[1]);

        foreach ($results as $index => $result) {
            if ($result['status'] === 409) {
                self::assertSame('last_owner', $result['code']);
                self::assertSame('owner', $this->role($index));
            } else {
                self::assertSame($operations[$index] === 'remove' ? false : 'viewer', $this->role($index));
            }
        }

        self::assertSame(1, $this->ownerCount());
        self::assertCount(2 - count(array_keys(array_column($results, 'status'), 204, true)), $this->membershipRows());
    }

    #[DataProvider('concurrentOperations')]
    public function testCompetingOwnersCannotBothRevokeEachOther(array $operations): void
    {
        $this->holdOrganizationLock();

        foreach ($operations as $index => $operation) {
            $this->startWorker($operation, (string) $this->memberships[1 - $index], $this->users[$index]['id']);
        }

        $this->awaitBlockedWorkers();
        $this->connection->commit();
        $results = $this->finishWorkers();
        $successes = 0;

        foreach ($results as $index => $result) {
            if (in_array($result['status'], [200, 204], true)) {
                ++$successes;
                self::assertSame('owner', $this->role($index));
                self::assertSame($operations[$index] === 'remove' ? false : 'viewer', $this->role(1 - $index));
                $loser = $results[1 - $index];
                self::assertSame($operations[$index] === 'remove' ? 404 : 403, $loser['status']);
                self::assertSame($operations[$index] === 'remove' ? 'not_found' : 'forbidden', $loser['code']);
                self::assertCount($operations[$index] === 'remove' ? 1 : 2, $this->membershipRows());
            }
        }

        self::assertSame(1, $successes);
        self::assertSame(1, $this->ownerCount());
    }

    public function testCompetingChangesToDifferentMembersBothPersist(): void
    {
        $this->addMember(MembershipRole::Manager);
        $this->addMember(MembershipRole::Manager);
        $this->holdOrganizationLock();
        $this->startWorker('change', (string) $this->memberships[2], $this->users[0]['id']);
        $this->startWorker('change', (string) $this->memberships[3], $this->users[1]['id']);
        $this->awaitBlockedWorkers();
        $this->connection->commit();
        self::assertSame([200, 200], array_column($this->finishWorkers(), 'status'));
        self::assertSame(['owner', 'owner', 'viewer', 'viewer'], array_column($this->membershipRows(), 'role'));
    }

    #[DataProvider('revokedOperations')]
    public function testWaitingOperationUsesCommittedActorPermissions(string $operation, string $revocation): void
    {
        $this->addMember(MembershipRole::Manager);
        $newUser = $this->addUser();
        $before = $this->membershipRows();
        $this->holdOrganizationLock();
        $this->startWorker(
            $operation,
            $operation === 'add' ? $newUser['email'] : (string) $this->memberships[2],
            $this->users[1]['id']
        );
        $this->awaitBlockedWorkers();

        // Revoke permission while the other connection is demonstrably waiting.
        // The service uses a savepoint inside this test's coordinating transaction.
        $manager = new MembershipManager($this->connection);
        if ($revocation === 'remove') {
            $manager->remove($this->organizationId, $this->memberships[1], $this->users[0]['id']);
            unset($before[1]);
            $before = array_values($before);
        } else {
            $manager->change($this->organizationId, $this->memberships[1], $this->users[0]['id'], MembershipRole::Viewer);
            $before[1]['role'] = 'viewer';
        }

        $this->connection->commit();
        $result = $this->finishWorkers()[0];
        self::assertSame($revocation === 'remove' ? 404 : 403, $result['status']);
        self::assertSame($revocation === 'remove' ? 'not_found' : 'forbidden', $result['code']);
        self::assertSame($before, $this->membershipRows(), 'The rejected operation must make no membership changes.');
        self::assertSame(1, $this->ownerCount());
    }

    #[DataProvider('mutationOperations')]
    public function testFailureAfterMembershipWriteRollsBackAndReleasesLock(string $operation): void
    {
        $this->addMember(MembershipRole::Manager);
        $newUser = $this->addUser();
        $before = $this->membershipRows();
        $failing = DriverManager::getConnection([
            'url' => $this->url,
            'wrapperClass' => FailingMembershipConnection::class,
        ]);
        self::assertInstanceOf(FailingMembershipConnection::class, $failing);

        try {
            $failing->failAfterMembershipWrite = true;
            $manager = new MembershipManager($failing);
            try {
                match ($operation) {
                    'add' => $manager->add($this->organizationId, $this->users[0]['id'], $newUser['email'], MembershipRole::Viewer),
                    'change' => $manager->change($this->organizationId, $this->memberships[2], $this->users[0]['id'], MembershipRole::Viewer),
                    'remove' => $manager->remove($this->organizationId, $this->memberships[2], $this->users[0]['id']),
                };
                self::fail('The injected failure must propagate.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Injected failure after membership SQL.', $exception->getMessage());
            }

            self::assertFalse($failing->failAfterMembershipWrite, 'A real membership SQL write must have executed.');
            self::assertFalse($failing->isTransactionActive());
            self::assertSame($before, $this->membershipRows(), 'Inspect committed state using a different connection.');

            // A second connection can write immediately: rollback released the lock.
            $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
            (new MembershipManager($this->connection))->change(
                $this->organizationId, $this->memberships[2], $this->users[0]['id'], MembershipRole::Viewer
            );
            self::assertSame('viewer', $this->role(2));
        } finally {
            if ($failing->isTransactionActive()) {
                $failing->rollBack();
            }
            $failing->close();
        }
    }

    public static function concurrentOperations(): iterable
    {
        yield 'two demotions' => [['change', 'change']];
        yield 'two removals' => [['remove', 'remove']];
        yield 'demotion and removal' => [['change', 'remove']];
    }

    public static function mutationOperations(): iterable
    {
        foreach (['add', 'change', 'remove'] as $operation) {
            yield $operation => [$operation];
        }
    }

    public static function revokedOperations(): iterable
    {
        foreach (['add', 'change', 'remove'] as $operation) {
            foreach (['demote', 'remove'] as $revocation) {
                yield "$operation after $revocation" => [$operation, $revocation];
            }
        }
    }

    private function addUser(): array
    {
        $email = bin2hex(random_bytes(12)).'@example.com';
        $this->connection->insert('user', [
            'email' => $email, 'roles' => '["ROLE_USER"]', 'password' => 'unused-test-hash',
            'first_name' => 'Member', 'last_name' => 'Test', 'phone' => '0600000000',
        ]);
        $user = ['id' => (int) $this->connection->lastInsertId(), 'email' => $email];
        $this->users[] = $user;
        return $user;
    }

    private function addMember(MembershipRole $role): void
    {
        $user = $this->addUser();
        $this->connection->insert('membership', [
            'organization_id' => $this->organizationId, 'user_id' => $user['id'], 'role' => $role->value,
        ]);
        $this->memberships[] = (int) $this->connection->lastInsertId();
    }

    private function holdOrganizationLock(): void
    {
        $this->connection->beginTransaction();
        $this->connection->executeStatement('UPDATE organization SET name = name WHERE id = ?', [$this->organizationId]);
    }

    private function startWorker(string $operation, string $target, int $actorId): void
    {
        $worker = new Process([
            PHP_BINARY, dirname(__DIR__).'/Support/membership-worker.php',
            $operation, (string) $this->organizationId, $target, (string) $actorId,
        ], env: [
            'MYSQL_TEST_DATABASE_URL' => $this->url,
            'MYSQL_TEST_OBSERVER_DATABASE_URL' => false,
        ], timeout: 20);
        $worker->start();
        $this->workers[] = $worker;
    }

    private function awaitBlockedWorkers(): void
    {
        $deadline = microtime(true) + 5;
        $blockerId = (int) $this->connection->fetchOne('SELECT CONNECTION_ID()');

        do {
            $ids = [];
            foreach ($this->workers as $worker) {
                if (!$worker->isRunning()) {
                    self::fail('Worker exited before contention was observed: '.$worker->getErrorOutput().$worker->getOutput());
                }
                $lines = explode("\n", $worker->getOutput());
                if (count($lines) > 1) {
                    $ready = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
                    if (($ready['event'] ?? null) !== 'ready') {
                        self::fail('Worker did not identify its database session.');
                    }
                    $ids[] = $ready['connection_id'];
                }
            }

            if (count($ids) === count($this->workers)) {
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                $waiting = $this->observer->fetchFirstColumn(
                    'SELECT DISTINCT requester.PROCESSLIST_ID
                     FROM performance_schema.data_lock_waits waits
                     JOIN performance_schema.threads requester ON requester.THREAD_ID = waits.REQUESTING_THREAD_ID
                     JOIN performance_schema.threads blocker ON blocker.THREAD_ID = waits.BLOCKING_THREAD_ID
                     JOIN performance_schema.data_locks locks
                       ON locks.ENGINE = waits.ENGINE AND locks.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
                     WHERE blocker.PROCESSLIST_ID = ? AND requester.PROCESSLIST_ID IN ('.$placeholders.')
                       AND locks.OBJECT_SCHEMA = ? AND locks.OBJECT_NAME = ?
                       AND locks.INDEX_NAME = ? AND locks.LOCK_DATA = ? AND locks.LOCK_STATUS = ?',
                    [$blockerId, ...$ids, 'propert_ease_concurrency_test', 'organization', 'PRIMARY',
                     (string) $this->organizationId, 'WAITING']
                );
                if (count($waiting) === count($ids)) {
                    $waiting = array_map('intval', $waiting);
                    sort($ids);
                    sort($waiting);
                    self::assertSame($ids, $waiting, 'Every worker must wait for this parent-held organization row.');
                    self::assertCount(count($ids) + 1, array_unique([$blockerId, ...$ids]));
                    return;
                }
            }

            if (microtime(true) >= $deadline) {
                self::fail('MySQL did not report all workers waiting on the organization row within five seconds.');
            }
            usleep(1000);
        } while (true);
    }

    private function finishWorkers(): array
    {
        $results = [];
        foreach ($this->workers as $worker) {
            $worker->wait();
            self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            $lines = explode("\n", trim($worker->getOutput()));
            $result = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($result['transaction_active'], 'Successful and rejected operations must end their transaction.');
            $results[] = $result;
        }
        return $results;
    }

    private function membershipRows(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, user_id, organization_id, role FROM membership WHERE organization_id = ? ORDER BY id',
            [$this->organizationId]
        );
    }

    private function role(int $index): string|false
    {
        return $this->connection->fetchOne('SELECT role FROM membership WHERE id = ?', [$this->memberships[$index]]);
    }

    private function ownerCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM membership WHERE organization_id = ? AND role = ?', [$this->organizationId, 'owner']
        );
    }
}
