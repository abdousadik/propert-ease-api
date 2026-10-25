<?php

namespace App\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use PHPUnit\Framework\Attributes\DataProvider;

#[Group('mysql')]
final class MembershipConcurrencyTest extends TestCase
{
    #[DataProvider('concurrentOperations')]
    public function testConcurrentMutationsRetainAnOwner(
        array $operations
    ): void {
        $url = getenv('MYSQL_TEST_DATABASE_URL');

        if ($url === false || $url === '') {
            self::markTestSkipped('MYSQL_TEST_DATABASE_URL is not configured.');
        }

        $connection = DriverManager::getConnection(['url' => $url]);

        self::assertSame(
            'propert_ease_concurrency_test',
            $connection->fetchOne('SELECT DATABASE()')
        );

        $organizationId = null;
        $userIds = [];
        $membershipIds = [];
        $workers = [];

        try {
            $connection->insert('organization', ['name' => 'Concurrency test']);
            $organizationId = (int) $connection->lastInsertId();

            foreach ([1, 2] as $number) {
                $connection->insert('user', [
                    'email' => bin2hex(random_bytes(12)).'@example.com',
                    'roles' => '["ROLE_USER"]',
                    'password' => 'unused-test-hash',
                    'first_name' => 'Owner',
                    'last_name' => (string) $number,
                    'phone' => '0600000000',
                ]);

                $userId = (int) $connection->lastInsertId();
                $userIds[] = $userId;

                $connection->insert('membership', [
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'role' => 'owner',
                ]);

                $membershipIds[] = (int) $connection->lastInsertId();
            }

            // Hold the same lock used by MembershipManager.
            $connection->beginTransaction();
            $connection->executeStatement(
                'UPDATE organization SET name = name WHERE id = ?',
                [$organizationId]
            );

            foreach ($userIds as $index => $userId) {
                $worker = new Process(
                    [
                        PHP_BINARY,
                        dirname(__DIR__).'/Support/membership-worker.php',
                        $operations[$index],
                        (string) $organizationId,
                        (string) $membershipIds[$index],
                        (string) $userId,
                    ],
                    env: ['MYSQL_TEST_DATABASE_URL' => $url],
                    timeout: 20
                );

                $worker->start();
                $workers[] = $worker;
            }

            $deadline = microtime(true) + 5;

            do {
                $allReady = true;

                foreach ($workers as $worker) {
                    if (!$worker->isRunning()) {
                        self::fail(
                            $worker->getErrorOutput().$worker->getOutput()
                        );
                    }

                    if (!str_contains($worker->getOutput(), "READY\n")) {
                        $allReady = false;
                    }
                }

                if ($allReady) {
                    break;
                }

                if (microtime(true) >= $deadline) {
                    self::fail('Workers did not become ready within five seconds.');
                }

                usleep(1000);
            } while (true);

            $connection->commit();

            $statuses = [];
            $successfulRemovals = 0;

            foreach ($workers as $worker) {
                $worker->wait();

                self::assertTrue(
                    $worker->isSuccessful(),
                    $worker->getErrorOutput().$worker->getOutput()
                );

                $lines = explode("\n", trim($worker->getOutput()));
                $result = json_decode(
                    end($lines),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                $statuses[] = $result['status'];

                if ($result['status'] === 204) {
                    ++$successfulRemovals;
                }

                if ($result['status'] === 409) {
                    self::assertSame('last_owner', $result['code']);
                }
            }

            sort($statuses);
            self::assertCount(2, $statuses);
            self::assertCount(1, array_keys($statuses, 409, true));

            $successes = array_values(array_filter(
                $statuses,
                static fn (int $status): bool => in_array($status, [200, 204], true)
            ));

            self::assertCount(1, $successes);

            self::assertSame(
                1,
                (int) $connection->fetchOne(
                    'SELECT COUNT(*) FROM membership WHERE organization_id = ? AND role = ?',
                    [$organizationId, 'owner']
                )
            );

            self::assertSame(
                2 - $successfulRemovals,
                (int) $connection->fetchOne(
                    'SELECT COUNT(*) FROM membership WHERE organization_id = ?',
                    [$organizationId]
                )
            );
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            foreach ($workers as $worker) {
                $worker->stop(1);
            }

            if ($organizationId !== null) {
                $connection->delete('membership', [
                    'organization_id' => $organizationId,
                ]);
                $connection->delete('organization', ['id' => $organizationId]);
            }

            foreach ($userIds as $userId) {
                $connection->delete('user', ['id' => $userId]);
            }

            $connection->close();
        }
    }

    public static function concurrentOperations(): iterable
    {
        yield 'two demotions' => [['change', 'change']];
        yield 'two removals' => [['remove', 'remove']];
        yield 'demotion and removal' => [['change', 'remove']];
    }
}