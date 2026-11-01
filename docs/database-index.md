# Project query index

The project list API filters by organization and active status, optionally narrows
by delivery date, orders by ID and returns an exact filtered count. The index
`idx_project_org_active_delivery` covers `(organization_id, active, delivery_date)`.
It supports active counts and delivery-date ranges without changing API queries,
search semantics or response metadata.

The original organization index remains: its ID ordering can still be preferable
for first pages or broad date ranges. The date-aware index may range-scan matching
dates and sort those results into ID order instead.

## Local evidence

A reproducible synthetic dataset contains 62,030 projects across five tenants:
30, 2,000, 20,000, 20,000 and 20,000 projects, with 23, 1,599, 16,015, 1,982 and
16,011 active projects respectively. Inserts interleave tenants; about one in eleven
dates is null and non-null dates span ten years. These larger cohorts are scaling
stress scenarios, not estimates of typical developer portfolios.

Recorded on local Docker MySQL 8.0.46 / PHP 8.4.27 on 2026-11-01. Medians below
are milliseconds, from 33 warm executions per query/configuration after two warmups,
alternating baseline and index-available execution order:

| Query | Existing indexes | Index available |
|---|---:|---:|
| Small first page (30 projects) | 0.232 | 0.216 |
| Large active count (20,000 projects) | 15.777 | 2.971 |
| Archive-heavy active count (20,000 projects) | 16.118 | 0.811 |
| Narrow date count (241 matches) | 17.453 | 0.646 |
| Narrow date first page | 1.479 | 0.575 |
| Offset-10,000 page | 12.057 | 12.130 |

The baseline explicitly ignores only the new index; normal application queries
have no index hints. The measurement includes query round trips and fetching, not
HTTP authentication/serialization. Results and plans vary with hardware, cache,
optimizer statistics and data distribution. Small sub-millisecond differences are
not evidence of a material improvement; deep offsets still require walking past
thousands of entries. Write overhead and production throughput were not measured.

EXPLAIN ANALYZE showed the existing organization index visiting 20,000 tenant rows
for the active count. The new index enables a covering active-entry count; the
narrow date count range-scans 241 matching entries. Every measured result hash must
match between configurations. The command records both EXPLAIN and EXPLAIN ANALYZE
along with samples; there are no timing thresholds in CI.

## Reproduce in a disposable local database

The tool refuses every database name except `propert_ease_benchmark`. Seeding
requires empty application tables; it does not erase existing rows. It does not
load the application's default database URL. Use the local Compose MySQL service:

```bash
docker compose exec -T database sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -u root' <<'SQL'
CREATE DATABASE propert_ease_benchmark
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON propert_ease_benchmark.* TO 'propert_ease'@'%';
SQL

export BENCHMARK_DATABASE_URL='mysql://propert_ease:local_password@database:3306/propert_ease_benchmark?serverVersion=8.0&charset=utf8mb4'

docker compose exec -T -e DATABASE_URL="$BENCHMARK_DATABASE_URL" \
  app php bin/console doctrine:migrations:migrate --env=dev --no-interaction

docker compose exec -T -e BENCHMARK_DATABASE_URL \
  app php tools/benchmark-project-queries.php seed

docker compose exec -T -e BENCHMARK_DATABASE_URL \
  app php tools/benchmark-project-queries.php measure > var/project-query-benchmark.json
```

If the dedicated database already exists, inspect it instead of repeating CREATE
or assuming it is safe to overwrite. Run seed only once on an empty migrated
benchmark database. Later measurements require its 62,030-project fixture and the
new migration. Keep the database quiet during measurement. The generated
organizations/projects are query fixtures, not application/demo accounts.

The measurement command changes no rows or indexes. Its output includes the
actual distribution, result hashes, SQL/parameters, timing samples and query plans.
The migration is additive; its down method drops only the new index. No claim of
zero-lock deployment behavior is made.

Further context: [MySQL EXPLAIN](https://dev.mysql.com/doc/refman/8.0/en/explain.html)
and [multi-column indexes](https://dev.mysql.com/doc/refman/8.0/en/multiple-column-indexes.html).
