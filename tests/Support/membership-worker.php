<?php

use App\Enum\MembershipRole;
use App\Exception\MembershipChangeRejected;
use App\Service\MembershipManager;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 5 || !in_array($argv[1], ['change', 'remove'], true)) {
    throw new InvalidArgumentException('Invalid worker arguments.');
}

$url = getenv('MYSQL_TEST_DATABASE_URL');

if ($url === false || $url === '') {
    throw new RuntimeException('MYSQL_TEST_DATABASE_URL is required.');
}

$connection = DriverManager::getConnection(['url' => $url]);

if (
    $connection->fetchOne('SELECT DATABASE()')
    !== 'propert_ease_concurrency_test'
) {
    throw new RuntimeException('Refusing to use a different database.');
}

$connection->executeStatement(
    'SET SESSION innodb_lock_wait_timeout = 10'
);

$operation = $argv[1];
$organizationId = (int) $argv[2];
$membershipId = (int) $argv[3];
$actorId = (int) $argv[4];

$manager = new MembershipManager($connection);

// The parent test waits until both workers reach this point.
echo "READY\n";
flush();

try {
    if ($operation === 'change') {
        $manager->change(
            $organizationId,
            $membershipId,
            $actorId,
            MembershipRole::Viewer
        );

        $status = 200;
    } else {
        $manager->remove($organizationId, $membershipId, $actorId);
        $status = 204;
    }

    $result = ['status' => $status];
} catch (MembershipChangeRejected $exception) {
    $result = [
        'status' => $exception->status,
        'code' => $exception->errorCode,
    ];
}

echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;