# PropertEase API

A Symfony API for managing real estate development projects within
organization workspaces.

Users can belong to multiple organizations, with separate permissions in
each. Projects belong to an organization, and access is controlled through
membership.

The project is under active development. Public property listings, a frontend,
and cloud deployment are planned.

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

- PHP 8.4
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

Membership-management endpoints are not implemented yet. Creating an
organization does not grant public publishing approval.

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
| 204 | Project archived |
| 400 | Malformed JSON or non-object JSON body |
| 401 | Authentication failed or token missing/invalid/expired |
| 403 | Organization member lacks write permission |
| 404 | Organization inaccessible, or project missing/foreign/archived |
| 409 | Signup email already used |
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

Tests use the separate SQLite database at `var/test.db`.
Database-reset helpers verify that path before recreating its schema.

Test signing keys are generated under `var/jwt/` and ignored by Git.

The suite covers:

- Signup validation, persistence, password hashing, and uniqueness conflicts.
- Login and JWT authentication.
- Membership persistence and uniqueness.
- Organization permission decisions.
- Organization creation and request validation.
- Project isolation, write permissions, validation, and search.

SQLite tests do not replace verification of MySQL-specific migrations.

## Continuous Integration

GitHub Actions runs on pushes, pull requests, and manual dispatch.

The workflow:

1. Installs PHP and locked Composer dependencies.
2. Validates Composer configuration.
3. Audits dependencies.
4. Checks PHP platform requirements.
5. Lints YAML and the service container.
6. Generates disposable test JWT keys.
7. Runs PHPUnit.

## Bruno Collection

Open `collection/bruno/` in Bruno.

The collection currently contains signup and unauthenticated project-list
requests. Additional requests will be added as the API develops.

Use local variables for credentials and tokens. Do not commit real secrets.

## Planned Development

- Membership-management endpoints.
- Controller/validation refactoring.
- Validated image uploads.
- Properties and public listing publication.
- Enquiries and construction updates.
- Buyer and developer frontend workspaces.
- Production deployment and automated releases.

## License

This project includes an [MIT License](LICENSE).