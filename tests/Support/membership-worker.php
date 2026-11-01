<?php

use App\Enum\MembershipRole;
use App\Exception\MembershipChangeRejected;
use App\Service\MembershipManager;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 5 || !in_array($argv[1], ['add', 'change', 'remove'], true)) {
    throw new InvalidArgumentException('Invalid worker arguments.');
}

$url = getenv('MYSQL_TEST_DATABASE_URL');

if ($url === false || $url === '') {
    throw new RuntimeException('MYSQL_TEST_DATABASE_URL is required.');
}

$connection = DriverManager::getConnection(['url' => $url]);

if ($connection->fetchOne('SELECT DATABASE()') !== 'propert_ease_concurrency_test') {
    throw new RuntimeException('Refusing to use a different database.');
}

$connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 10');
$manager = new MembershipManager($connection);
$operation = $argv[1];
$organizationId = (int) $argv[2];
$target = $argv[3];
$actorId = (int) $argv[4];

// Readiness identifies the session; the parent separately observes its real lock wait.
echo json_encode([
    'event' => 'ready',
    'connection_id' => (int) $connection->fetchOne('SELECT CONNECTION_ID()'),
], JSON_THROW_ON_ERROR).PHP_EOL;
flush();

try {
    match ($operation) {
        'add' => $manager->add($organizationId, $actorId, $target, MembershipRole::Viewer),
        'change' => $manager->change($organizationId, (int) $target, $actorId, MembershipRole::Viewer),
        'remove' => $manager->remove($organizationId, (int) $target, $actorId),
    };

    $result = ['status' => match ($operation) {
        'add' => 201,
        'change' => 200,
        'remove' => 204,
    }];
} catch (MembershipChangeRejected $exception) {
    $result = ['status' => $exception->status, 'code' => $exception->errorCode];
}

$result['transaction_active'] = $connection->isTransactionActive();
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
$connection->close();
