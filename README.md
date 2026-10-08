# PropertEase API

[![API checks](https://github.com/abdousadik/propert-ease-api/actions/workflows/ci.yml/badge.svg)](https://github.com/abdousadik/propert-ease-api/actions/workflows/ci.yml)

A Symfony REST API for managing a private portfolio of real estate projects. Users register, authenticate with JWT, and create, search, update, or soft-delete their own projects.

The project demonstrates owner-scoped database queries, typed request validation, predictable JSON errors, bounded pagination, safe image uploads, versioned schema upgrades, and functional testing with real authentication and persistence.

## Stack and architecture

- PHP **8.2+**, Symfony **6.4**, Doctrine ORM **3**, MySQL **8**, Lexik JWT.
- Controllers coordinate requests; `src/Api/Input.php` validates payloads and queries before mutation.
- `ProjectRepository` applies owner/active predicates to both resource access and paginated search/count queries.
- `ProjectPictureStorage` checks uploads and removes a moved file if persistence fails.
- API exception handling produces safe JSON responses; Lexik retains its token/authentication response format.
- Three committed migrations support fresh databases and deliberate assignment of legacy records.

## Run locally

Prerequisites: PHP 8.2+, Composer, MySQL 8 (or Docker Compose), and PHP extensions `ctype`, `iconv`, `fileinfo`, `mbstring`, `openssl`, `sodium`, `pdo_mysql`. Tests also use `pdo_sqlite` and `gd`. Symfony CLI is optional.

```bash
git clone https://github.com/abdousadik/propert-ease-api.git
cd propert-ease-api
composer install
cp .env .env.local
docker compose up -d mysql
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console lexik:jwt:generate-keypair --skip-if-exists
symfony server:start
```

On Windows, use `Copy-Item .env .env.local` instead of `cp` if needed. Without Symfony CLI, run `php -S 127.0.0.1:8000 -t public` for local development. The `.env` database URL matches the Compose service on port 3310. Set your database credentials in `.env.local` when using an existing MySQL installation. Wait until MySQL is healthy before migrating. Existing databases must follow [the upgrade guide](docs/upgrading.md).

Development secrets in `.env` are examples. Generate unique production `APP_SECRET` and `JWT_PASSPHRASE` values, supply them through your deployment environment, preserve private keys outside source control, and serve the API over HTTPS. Make `var/` and `public/uploaded_pictures/` writable by the PHP process. Configure PHP/OpenSSL before generating RSA keys; some Windows installations require `OPENSSL_CONF` to point to their bundled `openssl.cnf`.

## Try the API

Examples assume `http://127.0.0.1:8000`. All endpoint paths include `/api`.

Register:

```bash
curl -X POST http://127.0.0.1:8000/api/signup \
  -H 'Content-Type: application/json' \
  -d '{"firstName":"Ada","lastName":"Example","phone":"0123456789","email":"ada@example.com","password":"a-long-demo-password"}'
```

Signup returns **201** with a safe user in `data`. Login with the normalized lowercase email:

```bash
curl -X POST http://127.0.0.1:8000/api/login_check \
  -H 'Content-Type: application/json' \
  -d '{"username":"ada@example.com","password":"a-long-demo-password"}'
```

Copy the returned `token` into a shell variable named `TOKEN`. Create a project:

```bash
curl -X POST http://127.0.0.1:8000/api/project \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"Garden Homes","label":"Residential","numberOfFloors":3,"address":"12 Garden Street","postalCode":"01234","deliveryDate":"2027-06-30 12:00:00"}'
```

Response (**201**, with a `Location` header):

```json
{"data":{"id":1,"name":"Garden Homes","label":"Residential","numberOfFloors":3,"address":"12 Garden Street","postalCode":"01234","deliveryDate":"2027-06-30 12:00:00","picture":null}}
```

Search using query parameters:

```bash
curl -G http://127.0.0.1:8000/api/project/search \
  -H "Authorization: Bearer $TOKEN" \
  --data-urlencode 'name=Garden' \
  --data-urlencode 'deliveryDateMin=2027-01-01 00:00:00' \
  --data-urlencode 'page=1' --data-urlencode 'limit=20'
```

Collections always return `data` and `meta` (`page`, `limit`, `total`), including an empty `data` array. The default limit is 20 and the maximum is 100.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/api/signup` | Register with JSON |
| POST | `/api/login_check` | Obtain a JWT |
| POST | `/api/project` | Create; JSON or multipart with optional picture |
| GET | `/api/project` | List your active projects |
| GET | `/api/project/search` | Search your active projects |
| GET | `/api/project/{id}` | Read your project |
| PATCH | `/api/project/{id}` | Update mutable fields with JSON |
| DELETE | `/api/project/{id}` | Soft-delete; returns empty 204 |

Project names are immutable. Unknown fields, invalid types/dates, and empty PATCH requests are rejected. Ownership comes from the authenticated user; foreign-owned, deleted, and missing IDs return **404**. [The complete contract](docs/api-contract.md) covers validation, multipart uploads, response formats, and status codes.

Optional uploads accept one JPEG, PNG, or WebP image up to **5 MiB**, checked server-side. Configure upload limits and static serving with script execution disabled as described in the contract. Static images are publicly served by filename; ownership applies to API records. Soft deletion retains files and database records.

Import [the Postman collection](collection/PropertEaseAPI.postman_collection.json), set `baseUrl`, and run signup/login. Login saves the token; create saves the project ID for later requests. Supply your own credentials rather than reusing demo accounts on a deployed instance.

## Test and verify

The default test environment uses an isolated SQLite file in `var/`, JWT keys in `var/jwt/`, and test-only hashing costs. Tests reset its user/project tables; never point `APP_ENV=test` at real data. Test keys are separate from the development keys in `config/jwt/`.

```bash
php bin/console lexik:jwt:generate-keypair --env=test --skip-if-exists
composer test
composer validate --strict
composer audit
php bin/console lint:yaml config .github
php bin/console lint:container
```

Tests cover real signup/login/JWT protection, two-user isolation, CRUD and soft deletion, strict validation, search/date bounds, pagination, valid/spoofed/oversized uploads, database-failure cleanup, fresh migrations, and safe legacy upgrades. SQLite triggers inject genuine persistence/uniqueness failures for two tests; those cases are explicitly skipped on MySQL, while ordinary API and upload tests run on both databases.

CI runs PHP 8.2 and 8.4 against SQLite and MySQL 8, installs the lockfile, audits dependencies, applies migrations, validates the schema, lints PHP/configuration, and runs the full suite. Migration integration tests create temporary databases when `MIGRATION_DATABASE_URL` is set to a MySQL connection with create/drop privileges.

## Compatibility and scope

This hardening release changes the old API's response shapes and shared access model. See [upgrade instructions](docs/upgrading.md) before using it with existing data or clients. The API intentionally has no team sharing, admin roles, refresh tokens, or frontend. Deployment-specific rate limits, image privacy requirements, backups, and key rotation must be configured for the intended environment.

## License

[MIT](LICENSE).
