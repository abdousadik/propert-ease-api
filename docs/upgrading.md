# Upgrading an existing database

This release changes response envelopes, signup payload handling, status codes, validation, pagination, and project access. Update API clients using [the contract](api-contract.md). Existing JWT keys must be preserved; production secrets must come from the deployment environment.

Back up the database and upload directory, verify restoration, and schedule a maintenance window. MySQL DDL implicitly commits, so do not assume a failed migration rolls back every prior statement. The migrations intentionally reject destructive down operations.

## Fresh installation

Run `php bin/console doctrine:migrations:migrate --no-interaction`. All three migrations run on an empty database. No manual owner assignment is needed.

## Existing user/project tables

1. Compare your tables with `migrations/Version20261008000100.php`: columns, types, lengths, nullability, primary keys, and the unique email index. Reconcile schema differences explicitly. Do not mark the baseline as executed just to bypass an error.
2. Initialize migration metadata, then record the verified baseline:

   ```bash
   php bin/console doctrine:migrations:sync-metadata-storage
   php bin/console doctrine:migrations:version 'DoctrineMigrations\Version20261008000100' --add --no-interaction
   ```

3. Apply the nullable-owner migration:

   ```bash
   php bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20261008000200' --no-interaction
   ```

   It normalizes existing emails. Case/whitespace collisions stop the migration before updates are scheduled. Resolve accounts deliberately and retry; this release never merges users automatically. Owners can sign in with their normalized lowercase emails afterward.

4. Existing projects have no trustworthy creator history. Decide who owns them using your records. For a single selected existing user, assign **all currently unowned records**:

   ```bash
   php bin/console app:projects:assign-owner owner@example.com
   ```

   This command does not change projects that already have an owner. If projects belong to several users, assign individual records explicitly through a reviewed database update instead of running this bulk command. Unowned records stay hidden from the API. Do not invent creators.

5. Finish and validate:

   ```bash
   php bin/console doctrine:migrations:migrate --no-interaction
   php bin/console doctrine:schema:validate
   ```

   The final migration stops while any unowned row remains, including soft-deleted rows. This prevents silent orphaning. Restore your verified backup if an upgrade must be reversed.

Only test environments use generated temporary JWT keys and reduced password hashing costs. Never use the CI keys or test secrets in production.
