<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;

require dirname(__DIR__).'/vendor/autoload.php';

$action = $argv[1] ?? '';
$url = getenv('BENCHMARK_DATABASE_URL');
if (!in_array($action, ['seed', 'measure'], true) || $url === false || $url === '') {
    throw new RuntimeException('Use seed|measure with an explicit BENCHMARK_DATABASE_URL. See docs/database-index.md.');
}
$connection = DriverManager::getConnection(['url' => $url]);
if ($connection->fetchOne('SELECT DATABASE()') !== 'propert_ease_benchmark') {
    throw new RuntimeException('Refusing any database except propert_ease_benchmark.');
}

if ($action === 'seed') {
    foreach (['project', 'organization', 'membership', 'user'] as $table) {
        if ((int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table) !== 0) {
            throw new RuntimeException('Seeding requires an empty benchmark database.');
        }
    }
    // Synthetic query fixtures only; this is not application/demo provisioning.
    $tenants = [1 => [30, 80], 2 => [2000, 80], 3 => [20000, 80],
        4 => [20000, 10], 5 => [20000, 80]];
    $connection->transactional(function ($connection) use ($tenants): void {
        foreach ($tenants as $id => $distribution) {
            $connection->insert('organization', ['id' => $id, 'name' => 'Synthetic tenant '.$id]);
        }
        $rows = [];
        $flush = function () use ($connection, &$rows): void {
            if ($rows !== []) {
                $connection->executeStatement(
                    'INSERT INTO project (name,label,number_of_floors,address,postal_code,delivery_date,picture,active,organization_id) VALUES '
                    .implode(',', array_fill(0, count($rows), '(?,?,?,?,?,?,?,?,?)')),
                    array_merge(...$rows)
                );
                $rows = [];
            }
        };
        for ($i = 0; $i < 20000; ++$i) {
            foreach ($tenants as $id => [$count, $activePercent]) {
                if ($i >= $count) {
                    continue;
                }
                $mix = ($i * 1103515245 + $id * 12345) & 0x7fffffff;
                $month = ($i * 37 + $id) % 120;
                $date = $i % 11 === 0 ? null : sprintf('%04d-%02d-%02d 12:00:00',
                    2025 + intdiv($month, 12), 1 + $month % 12, 1 + $i % 28);
                $rows[] = [($i % 100 === 0 ? 'Palm' : 'Development').' '.$id.'-'.$i,
                    'Synthetic residential development', 1 + $i % 20,
                    str_repeat('Example address ', 10), '20000', $date, null,
                    (int) ($mix % 100 < $activePercent), $id];
                if (count($rows) === 1000) {
                    $flush();
                }
            }
        }
        $flush();
    });
    $connection->executeQuery('ANALYZE TABLE project')->fetchAllAssociative();
    echo json_encode($connection->fetchAllAssociative(
        'SELECT organization_id,COUNT(*) AS total,SUM(active=1) AS active FROM project GROUP BY organization_id'
    ), JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}

$index = 'idx_project_org_active_delivery';
$indexes = $connection->fetchFirstColumn('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['project']);
if (!in_array($index, $indexes, true)) {
    throw new RuntimeException('Apply the current migrations before measuring.');
}
$distribution = $connection->fetchAllAssociative('SELECT organization_id,COUNT(*) AS total,SUM(active=1) AS active FROM project GROUP BY organization_id');
if (array_sum(array_column($distribution, 'total')) !== 62030) {
    throw new RuntimeException('Measure requires the seeded 62,030-project fixture.');
}
$fields = 'id,name,label,number_of_floors,address,postal_code,delivery_date,picture,active,organization_id';
$queries = [
    'small_first_page' => ["SELECT $fields FROM project %s WHERE organization_id=? AND active=? ORDER BY id LIMIT 20", [1,1]],
    'large_active_count' => ['SELECT COUNT(id) FROM project %s WHERE organization_id=? AND active=?', [3,1]],
    'archive_heavy_active_count' => ['SELECT COUNT(id) FROM project %s WHERE organization_id=? AND active=?', [4,1]],
    'narrow_date_count' => ['SELECT COUNT(id) FROM project %s WHERE organization_id=? AND active=? AND delivery_date>=? AND delivery_date<=?', [3,1,'2028-04-01 00:00:00','2028-05-31 23:59:59']],
    'narrow_date_first_page' => ["SELECT $fields FROM project %s WHERE organization_id=? AND active=? AND delivery_date>=? AND delivery_date<=? ORDER BY id LIMIT 20", [3,1,'2028-04-01 00:00:00','2028-05-31 23:59:59']],
    'large_deep_page' => ["SELECT $fields FROM project %s WHERE organization_id=? AND active=? ORDER BY id LIMIT 20 OFFSET 10000", [3,1]],
];
$output = ['mysql' => $connection->fetchOne('SELECT VERSION()'), 'php' => PHP_VERSION, 'project_distribution' => $distribution,
    'samples_per_configuration' => 33, 'warmups_per_configuration' => 2, 'queries' => []];
foreach ($queries as $name => [$template, $parameters]) {
    $sql = ['baseline' => sprintf($template, 'IGNORE INDEX ('.$index.')'),
        'index_available' => sprintf($template, '')];
    $samples = ['baseline' => [], 'index_available' => []];
    foreach ($sql as $statement) {
        for ($i = 0; $i < 2; ++$i) {
            $connection->executeQuery($statement, $parameters)->fetchAllAssociative();
        }
    }
    $expectedHash = null;
    for ($i = 0; $i < 33; ++$i) {
        foreach ($i % 2 === 0 ? array_keys($sql) : array_reverse(array_keys($sql)) as $configuration) {
            $start = hrtime(true);
            $rows = $connection->executeQuery($sql[$configuration], $parameters)->fetchAllAssociative();
            $samples[$configuration][] = (hrtime(true) - $start) / 1e6;
            $hash = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
            $expectedHash ??= $hash;
            if ($hash !== $expectedHash) {
                throw new RuntimeException('Query results changed during measurement: '.$name);
            }
        }
    }
    foreach ($sql as $configuration => $statement) {
        sort($samples[$configuration]);
        $output['queries'][$name][$configuration] = [
            'sql' => $statement, 'parameters' => $parameters,
            'median_ms' => round($samples[$configuration][16], 4),
            'p90_ms' => round($samples[$configuration][29], 4),
            'samples_ms' => $samples[$configuration], 'result_hash' => $expectedHash,
            'explain' => $connection->executeQuery('EXPLAIN '.$statement, $parameters)->fetchAllAssociative(),
            'analyze' => $connection->executeQuery('EXPLAIN ANALYZE '.$statement, $parameters)->fetchFirstColumn(),
        ];
    }
}
echo json_encode($output, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
