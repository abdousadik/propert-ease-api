# PropertEase API

A PHP 8.4 / Symfony 7.4 API for real estate development projects within organization workspaces.

Users can belong to multiple organizations with separate Owner, Manager, and Viewer permissions. Project access is scoped to the selected organization. Membership changes run in transactions and prevent removal or demotion of the final Owner.

The repository includes unit, functional, and integration tests, separate-process MySQL concurrency tests, Docker Compose, and GitHub Actions checks.

Originally developed as a Kaliti hiring exercise, PropertEase is now being extended as a personal engineering project. Cloud deployment, public property listings, and a frontend are planned.

## Start here

- [Run locally](#local-setup)
- [Organizations and permissions](#organizations-and-permissions)
- [Project endpoints](#project-endpoints)
- [Tests](#tests)
- [MySQL concurrency tests](#mysql-concurrency-tests)
- [Continuous integration](#continuous-integration)

## Features

- JSON signup with input validation, normalized email addresses, and password hashing.
- JWT login and authenticated API access.
- Organization creation with automatic Owner membership.
- Organization-scoped Owner, Manager, and Viewer roles.
- Project creation, details, partial updates, and soft archiving.
- Search by project name and inclusive delivery-date range.
- Isolation between organizations.
- Versioned database migrations.
- Unit, integration, and functional tests.
- GitHub Actions checks on pushes and pull requests.

## Technology

- PHP 8.4, minimum patch 8.4.27
- Symfony 7.4 LTS
- Doctrine ORM and Doctrine Migrations
- MySQL 8.0 for local development
- Lexik JWT Authentication Bundle
- PHPUnit and Symfony BrowserKit
- SQLite for automated tests
- Docker Compose
- Bruno for manual API requests

## Local Setup

### Requirements

- Git
- Docker with Docker Compose
- On Windows: WSL Ubuntu with Docker Desktop integration

PHP and Composer run inside Docker; host installations are not required.

### 1. Clone the repository

```bash
git clone https://github.com/abdousadik/propert-ease-api.git
cd propert-ease-api
```

On WSL, keep the repository in the Linux filesystem, for example
`~/projects/propert-ease-api`.

### 2. Configure local environment variables

Create `.env.local` in the repository root:

```dotenv
APP_ENV=dev
DATABASE_URL="mysql://propert_ease:local_password@database:3306/propert_ease?serverVersion=8.0&charset=utf8mb4"
JWT_PASSPHRASE="replace-with-your-local-passphrase"
```

Choose your own local JWT passphrase. Do not commit `.env.local` or signing keys.

The database credentials above match the development Compose configuration.
They are intended for local development.

### 3. Start Docker services

On Linux or WSL, set your user and group IDs so container-created files belong
to your user:

```bash
export LOCAL_UID=$(id -u)
export LOCAL_GID=$(id -g)

docker compose up -d --build
docker compose ps
```

Wait for the database service to report healthy before running migrations.

### 4. Install dependencies

```bash
docker compose exec app composer install
```

### 5. Apply migrations

For a fresh development database:

```bash
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console doctrine:schema:validate
```

The organization ownership migration requires projects to have an organization.
An older database containing projects needs an explicit ownership-assignment
plan before applying that migration. Do not apply it blindly to existing data.

### 6. Generate development JWT keys

```bash
docker compose exec app php bin/console lexik:jwt:generate-keypair
```

Generate these once during initial setup. Do not overwrite existing signing
keys unintentionally.

The API is available at:

```text
http://localhost:8000
```

Docker runs PHP’s development server. This setup is not the production
deployment configuration.

## Authentication

JSON request bodies require:

```http
Content-Type: application/json
```

### Register

```http
POST /api/signup
```

```json
{
  "firstName": "Demo",
  "lastName": "User",
  "phone": "0600000000",
  "email": "demo@example.com",
  "password": "LocalDemoPassword123!"
}
```

Successful registration returns `201 Created` with safe user data.

Email addresses are trimmed and converted to lowercase. Passwords must contain
12–128 characters and are stored as hashes. Password whitespace is preserved.

An already-used email returns `409 Conflict`.

### Log in

```http
POST /api/login_check
```

```json
{
  "username": "demo@example.com",
  "password": "LocalDemoPassword123!"
}
```

The `username` field contains the email address.

Successful login returns:

```json
{
  "token": "<jwt>"
}
```

For protected requests, send:

```http
Authorization: Bearer <jwt>
```

Invalid credentials and missing, malformed, or expired tokens return `401`.

## Organizations and Permissions

### Create an organization

```http
POST /api/organizations
```

```json
{
  "name": "Company A"
}
```

Returns `201 Created`:

```json
{
  "data": {
    "id": 1,
    "name": "Company A"
  }
}
```

The authenticated creator becomes an Owner in the same database transaction.

| Membership role | Read projects | Create/update/archive projects |
|---|---|---|
| Owner | Yes | Yes |
| Manager | Yes | Yes |
| Viewer | Yes | No |

A user can have different roles in different organizations. Membership roles
are separate from global authentication roles.

### Membership Management

Any organization member can list memberships. Only Owners can add users,
change roles, or remove memberships.

| Method | Path | Operation |
|---|---|---|
| GET | `/api/organizations/{organizationId}/memberships` | List members |
| POST | `/api/organizations/{organizationId}/memberships` | Add a registered user |
| PATCH | `/api/organizations/{organizationId}/memberships/{membershipId}` | Change role |
| DELETE | `/api/organizations/{organizationId}/memberships/{membershipId}` | Remove membership |

Add an existing registered user:

```json
{
  "email": "member@example.com",
  "role": "viewer"
}
```

Change a role:

```json
{
  "role": "manager"
}
```

Roles are `owner`, `manager`, and `viewer`. Email addresses are normalized
before lookup. Unknown fields and invalid roles return 422.

- Successful addition returns 201.
- Duplicate membership returns 409 with `membership_already_exists`.
- An unregistered email returns 404 with `user_not_found`.
- Successful role change returns 200.
- Successful removal returns 204 without deleting the user account.
- Foreign membership IDs return 404.
- Removing or demoting the final Owner returns 409 with `last_owner`.
- An Owner may step down or leave when another Owner remains.

Membership mutations share an organization-level transaction lock and recheck
the actor's current permissions inside the transaction. MySQL concurrency
tests cover competing self-demotions, removals, and mixed operations.

Membership lists expose membership ID, user ID, first/last name, and role.
They do not expose email addresses, phone numbers, or password hashes.

Email invitations and acceptance are not implemented. Organization creation
does not grant public publishing approval.

## Project Endpoints

All project endpoints require authentication and membership in the selected
organization.

| Method | Path | Operation |
|---|---|---|
| POST | `/api/organizations/{organizationId}/projects` | Create |
| GET | `/api/organizations/{organizationId}/projects` | List/search |
| GET | `/api/organizations/{organizationId}/projects/{projectId}` | Details |
| PATCH | `/api/organizations/{organizationId}/projects/{projectId}` | Partial update |
| DELETE | `/api/organizations/{organizationId}/projects/{projectId}` | Archive |

The former `/api/project` endpoints have been removed.

### Create a project

```json
{
  "name": "Palm Residence",
  "label": "Residential development",
  "numberOfFloors": 5,
  "address": "Example address",
  "postalCode": "20000",
  "deliveryDate": "2027-06-01 12:00:00"
}
```

Rules:

- `name`, `label`, and `address`: nonblank strings, at most 255 characters after trimming.
- `postalCode`: nonblank string, at most 6 characters; leading zeros are preserved.
- `numberOfFloors`: JSON integer between 0 and 2147483647.
- `deliveryDate`: optional; null or a valid UTC date in `YYYY-MM-DD HH:MM:SS` format.
- Unknown fields are rejected.
- Organization ownership and active status are assigned by the server.

Returns `201 Created` with the project ID and name.

### Update a project

Send only the fields being changed:

```json
{
  "label": "Updated residence",
  "numberOfFloors": 6
}
```

Editable fields:

- `label`
- `address`
- `postalCode`
- `numberOfFloors`
- `deliveryDate`

The project name and organization cannot be changed.

To clear the delivery date:

```json
{
  "deliveryDate": null
}
```

All supplied fields are validated before changes are applied.
Empty objects and invalid fields return `422`.

### List and search

The response contains a `data` array. Empty results return:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "limit": 20,
    "total": 0,
    "totalPages": 0
  }
}
```

Optional query parameters:

| Parameter | Meaning |
|---|---|
| `name` | Partial name match |
| `deliveryDateMin` | Inclusive minimum delivery date |
| `deliveryDateMax` | Inclusive maximum delivery date |

Example:

```bash
curl --get \
  "http://localhost:8000/api/organizations/1/projects" \
  --header "Authorization: Bearer <jwt>" \
  --data-urlencode "name=Palm" \
  --data-urlencode "deliveryDateMin=2027-01-01 00:00:00" \
  --data-urlencode "deliveryDateMax=2027-12-31 23:59:59"
```

Date filters use UTC and require the full timestamp format. Reversed ranges
are rejected. Projects without a delivery date do not match supplied date
bounds.

Name searches treat `%` and `_` literally. Results contain only active projects
in the selected organization and are ordered by ID.

### Pagination

List/search accepts `page` and `limit` query parameters.

- Default page: 1.
- Default limit: 20; maximum: 100.
- Values must be positive decimal integers without leading zeros.
- Invalid values or offsets exceeding 2147483647 return 422.
- Results are ordered by project ID.
- `meta.total` counts active projects matching the organization and search filters.
- `meta.totalPages` is 0 when no projects match.
- Pages beyond the last return an empty `data` array while retaining the total.

Example: `/api/organizations/1/projects?page=2&limit=20`

### Archive a project

DELETE marks a project inactive and returns `204 No Content` with no body.

Archived projects are excluded from lists, searches, and active detail lookups.

### Access and validation responses

| Status | Meaning |
|---|---|
| 200 | Successful read or update |
| 201 | Resource created |
| 204 | Project archived or membership removed |
| 400 | Malformed JSON or non-object JSON body |
| 401 | Authentication failed or token missing/invalid/expired |
| 403 | Organization member lacks permission for the operation |
| 404 | Organization inaccessible, project missing/foreign/archived, membership missing/foreign, or requested user unregistered |
| 409 | Signup email already used, duplicate membership, or attempted removal/demotion of the last Owner |
| 415 | Unsupported request content type |
| 422 | Input validation failed |

Application validation errors use an `error` object, for example:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Invalid project data.",
    "details": {
      "numberOfFloors": "Must be an integer between 0 and 2147483647."
    }
  }
}
```

JWT authentication errors currently use the bundle’s `code` and `message`
response format.

## Tests

Generate isolated test JWT keys once:

```bash
docker compose exec app php bin/console lexik:jwt:generate-keypair --env=test
```

Run the suite:

```bash
docker compose exec app php vendor/bin/phpunit
```

Run a focused test file:

```bash
docker compose exec app php vendor/bin/phpunit tests/Functional/OrganizationTest.php
```

The regular suite uses the separate SQLite database at `var/test.db`.
Database-reset helpers verify that path before recreating its schema.
MySQL concurrency tests use a dedicated database and are skipped when
`MYSQL_TEST_DATABASE_URL` is not configured.

Test signing keys are generated under `var/jwt/` and ignored by Git.

The suite covers:

- Signup validation, persistence, password hashing, and uniqueness conflicts.
- Login and JWT authentication.
- Membership persistence, uniqueness, administration permissions, and last-Owner protection.
- Organization permission decisions.
- Organization creation and request validation.
- Project isolation, write permissions, validation, and search.

SQLite tests do not replace verification of MySQL-specific migrations.

### MySQL Concurrency Tests

These tests run competing membership mutations through separate processes and
database connections. An independent observer queries MySQL Performance Schema
and verifies that every worker is waiting for the parent-held organization row
before the parent releases it. Worker readiness alone is not treated as contention.
Polling has a five-second deadline; no fixed delay determines the mutation order.

Scenarios cover:

- Competing self-demotions, self-removals, and mixed operations retaining one Owner.
- Owners attempting to demote/remove each other; the losing actor's revoked access.
- Successful competing changes to different members of the same organization.
- Add/change/remove operations waiting while their actor is demoted or removed.
- Exceptions injected after real INSERT/UPDATE/DELETE statements, verifying rollback
  through another connection and subsequent acquisition of the released lock.

Assertions inspect committed membership rows and transaction completion, in addition
to operation outcomes. Failure injection lives only in test support code.

They require the dedicated database `propert_ease_concurrency_test`. The test
and workers refuse other database names and clean up their own fixture records.

Create the local test database using the existing Compose credentials:

```bash
docker compose exec -T database sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -u root' <<'SQL'
CREATE DATABASE IF NOT EXISTS propert_ease_concurrency_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON propert_ease_concurrency_test.*
    TO 'propert_ease'@'%';
SQL
```

Set the connection URL in your WSL terminal:

```bash
export MYSQL_TEST_DATABASE_URL='mysql://propert_ease:local_password@database:3306/propert_ease_concurrency_test?serverVersion=8.0&charset=utf8mb4'
export MYSQL_TEST_OBSERVER_DATABASE_URL='mysql://root:local_root_password@database:3306/propert_ease_concurrency_test?serverVersion=8.0&charset=utf8mb4'
```

The observer URL above uses the existing local Docker root account only to read
lock diagnostics. Workers receive only the normal test-account URL. CI similarly
uses its disposable MySQL service root account for observation. Both connections
must select the dedicated test database on the same server. A restricted observer
can instead use SELECT privileges on `performance_schema.data_lock_waits`,
`performance_schema.data_locks`, `performance_schema.threads`, and a table in the
test database (to permit selecting that database). No diagnostic privileges are
required by the application account. Use only disposable test environments here.

Apply migrations and validate the schema:

```bash
docker compose exec \
  -e DATABASE_URL="$MYSQL_TEST_DATABASE_URL" \
  app php bin/console doctrine:migrations:migrate \
  --env=dev --no-interaction

docker compose exec \
  -e DATABASE_URL="$MYSQL_TEST_DATABASE_URL" \
  app php bin/console doctrine:schema:validate --env=dev
```

The explicit URL targets the dedicated database. Using `--env=dev` here avoids
the test environment's automatic MySQL database-name suffix.

Run the concurrency tests:

```bash
docker compose exec \
  -e MYSQL_TEST_DATABASE_URL \
  -e MYSQL_TEST_OBSERVER_DATABASE_URL \
  app php vendor/bin/phpunit tests/Integration/MembershipConcurrencyTest.php
```

Without `MYSQL_TEST_DATABASE_URL`, the MySQL cases are skipped. If it is set,
missing observer configuration or diagnostic access fails the tests rather than
silently skipping contention verification. The default SQLite suite runs
independently; do not overlap SQLite test runs sharing `var/test.db`.

### Membership transaction contract

`MembershipManager` is the membership mutation boundary:

1. Begin a transaction and acquire a write lock using
   `UPDATE organization SET name = name WHERE id = ?`.
2. Read the actor's current membership and the organization-scoped target.
3. Enforce Owner-only administration and retain at least one Owner.
4. Apply the mutation and commit. Any exception rolls back the transaction and
   releases its locks; uniqueness conflicts retain their existing 409 response.

All add/change/remove writers must follow the same organization-lock protocol.
Locking only the target membership would not serialize changes to different
Owners, so two requests could both pass the final-Owner count. Different
organizations use different rows; these tests do not measure throughput.

HTTP controllers call the service outside an existing database transaction. Under
MySQL's default REPEATABLE READ isolation, a future caller must not wrap it in a
transaction that established an older consistent-read snapshot before acquiring
the organization lock. Nested transaction behavior is not a blanket guarantee of
fresh reads. ORM membership objects can also become stale after DBAL writes;
mutation authorization therefore uses database reads inside the service.

An operation waiting for the lock must use permissions committed before it
acquires that lock. A demoted actor receives 403; a removed actor receives 404.
The MySQL tests demonstrate both outcomes for add/change/remove operations.

These are service/database tests, not simultaneous HTTP requests. They exercise
controlled interleavings, not every possible schedule. Project writes use the request-time authorization policy below.

### Project writes and membership revocation

Project create, PATCH and archive operations check membership permissions before
applying changes. Once a write has been authorized, it may finish if the actor is
removed or demoted before persistence. The next request must use current
membership: a removed member receives 404; a member demoted to Viewer receives
403 on a new project write. The JWT can remain valid for authentication while
organization access has been revoked.

This policy preserves the current behavior. Project writes do not acquire the
membership administration lock, so immediate cancellation of already-authorized
writes is not guaranteed. Membership administration retains its stronger
transactional, current-state checks under the organization-row lock.

`ProjectRevocationConsistencyTest` demonstrates create, PATCH and archive behavior
for both removal and demotion. A controlled Doctrine onFlush hook commits the
membership mutation through a second SQLite connection after authorization and
before project SQL. Assertions inspect stored project and membership state and
retry a write with the same JWT. This is a deterministic application-interleaving
test, not simultaneous MySQL HTTP requests or proof of every possible schedule.

## Continuous Integration

GitHub Actions runs on pushes, pull requests, and manual dispatch.

The workflow contains two independent jobs:

### Regular checks (`tests`)

1. Installs PHP and locked Composer dependencies.
2. Validates Composer configuration.
3. Audits dependencies.
4. Checks PHP platform requirements.
5. Lints YAML and the service container.
6. Generates disposable test JWT keys.
7. Runs the regular PHPUnit suite.

### MySQL verification (`mysql-concurrency`)

1. Starts a disposable MySQL 8.0 service.
2. Installs PHP and locked Composer dependencies.
3. Applies all migrations to a fresh dedicated database.
4. Validates the MySQL schema.
5. Runs concurrent membership-mutation tests.

CI database credentials are disposable test values, not production credentials.

## Bruno Collection

Open `collection/bruno/` in Bruno.

The collection currently contains signup and unauthenticated project-list
requests. Additional requests will be added as the API develops.

Use local variables for credentials and tokens. Do not commit real secrets.

## Architecture at a glance

- Controllers decode HTTP requests, check permissions, persist changes, and return JSON responses.
- ProjectInputValidator shares create/PATCH field rules while preserving their distinct required-field and partial-update behavior.
- OrganizationVoter maps organization membership to read, edit, and membership-management permissions.
- MembershipManager serializes membership changes using an organization-row lock and checks actor permissions inside the transaction.
- Doctrine entities and migrations define persistence; project queries include organization scope.
- Functional tests exercise API behavior; MySQL integration tests exercise competing membership mutations.

## Project origin

This project began as a Kaliti recruitment coding exercise and has since been extended as a personal engineering project. The existing MIT license includes a HypeCodeTeam copyright notice, which is retained. The relationship between that notice and the original exercise materials is still being verified.

## Planned Development

- Email invitations and membership acceptance.
- Controller/validation refactoring.
- Validated image uploads.
- Properties and public listing publication.
- Enquiries and construction updates.
- Buyer and developer frontend workspaces.
- Production deployment and automated releases.

## License

This project includes an [MIT License](LICENSE).