# Verification record — 2026-10-08

## Final local results

| Runtime | Database | Tests | Assertions | Result |
| --- | --- | --- | --- | --- |
| PHP 8.2.26 | SQLite | 45 | 175 | Passed |
| PHP 8.4.0 | SQLite | 45 | 175 | Passed |
| PHP 8.2.26 | MySQL 8.0.46 | 45 discovered | 168 | Passed; 2 explicit skips |
| PHP 8.4.0 | MySQL 8.0.46 | 45 discovered | 168 | Passed; 2 explicit skips |

MySQL skips only the two SQLite-trigger failure-injection tests: concurrent signup and upload persistence failure. Ordinary signup conflicts, authentication, ownership, CRUD, validation, uploads, search, and fresh/legacy migrations run against both databases.

Additional successful checks: `composer validate --strict`, `composer audit` (no reported security advisories), PHP syntax checks across source/migrations/tests, Symfony YAML/container lint, MySQL fresh migration and schema validation, Docker Compose configuration, and Postman collection JSON parsing. The documented `composer test` command was exercised successfully; the final additional regressions were then run through the same PHPUnit entry point.

The GitHub workflow implements the same PHP/database matrix. Hosted GitHub Actions execution has **not** been verified before publishing this branch.

## Regression review

The implementation was reviewed for ownership isolation, safe migrations, upload cleanup, and duplicate-signup race handling.

Two findings were corrected:

- Malformed UTF-8 multipart values could pass validation and poison SQLite resource serialization. `testMalformedMultipartTextDoesNotPersist` now checks rejection before persistence and readable empty lists afterward. Malformed field names also return valid JSON errors in both multipart and query requests.
- Search retained surrounding name whitespace despite documenting trimming. `testPaginationAndQuerySearch` now proves a padded name finds the expected resource and count.

The new regressions failed before the fixes (6 failures and 2 errors), then the complete suite passed. There are no deferred findings from this review.

## Implementation decisions and practical limits

- Local PHP used ignored project-specific INI files because the machine configuration had sodium disabled and a broken Xdebug path. Machine settings were preserved; ordinary local commands still require the documented PHP extensions to be enabled.
- The minimum is PHP 8.2 rather than the old documented 8.1, because the existing Lexik JWT 3 dependency requires 8.2. PHP 8.1 users must upgrade. Dependencies were updated within the existing framework/package constraints.
- Ownership, project validation, pagination, and upload changes share creation/response paths and were committed together. This makes the implementation commit larger; functional regressions independently cover each behavior.
- Padded search names were treated as a contract correctness issue and fixed instead of deferred. Names are now trimmed as documented.
- Static images remain publicly served by filename, and soft deletion retains image files. Ownership protects API records; private image downloads and storage retention are separate future requirements.
- SQL LIKE wildcard characters retain their documented search semantics. Clients wanting literal percent/underscore matching need a later contract change.
- Login identifiers use the documented normalized lowercase email; clients must supply that username form.
- SQLite-specific failure injection is not duplicated on MySQL; the ordinary MySQL behavior and all migration paths are tested.
- No deployment, GitHub merge, or hosted CI success is implied by local verification. Existing databases still require the explicit legacy ownership procedure in [upgrading.md](upgrading.md).
