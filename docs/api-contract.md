# API contract

All routes begin with `/api`. Signup and login are public; project routes require `Authorization: Bearer <token>`. Each user has a private project portfolio. Requests for another user's, deleted, or missing project return the same 404.

| Method | Route | Request | Success |
| --- | --- | --- | --- |
| POST | `/api/signup` | JSON signup fields | 201, safe user in `data` |
| POST | `/api/login_check` | JSON `username` (normalized email), `password` | 200, Lexik `token` |
| POST | `/api/project` | JSON fields or multipart fields with optional `picture` | 201, resource in `data`, Location header |
| GET | `/api/project` | `page`, `limit` query parameters | 200, collection |
| GET | `/api/project/search` | pagination plus `name`, `deliveryDateMin`, `deliveryDateMax` | 200, collection |
| GET | `/api/project/{id}` | positive integer ID | 200, resource in `data` |
| PATCH | `/api/project/{id}` | nonempty JSON of mutable fields | 200, updated resource in `data` |
| DELETE | `/api/project/{id}` | positive integer ID | 204, empty body |

## Fields

Project creation requires `name`, `label`, `numberOfFloors`, `address`, `postalCode`, and `deliveryDate`. PATCH permits any subset except `name`; ownership is server-assigned and cannot be changed. Unknown fields, nulls, arrays in scalar fields, and invalid values are rejected. JSON bodies must be objects. Multipart is supported only for creation, and takes decimal integer strings for floors.

| Field | Rule |
| --- | --- |
| name / label / address | Nonblank string, at most 255 characters |
| postalCode | String of 1–6 characters; leading zeroes preserved |
| numberOfFloors | Integer 0–2147483647; fractional/scientific values rejected |
| deliveryDate | Exact valid `Y-m-d H:i:s`, year 1000–9999, interpreted as UTC |
| picture | One successful JPEG, PNG, or WebP upload; detected MIME and image structure; at most 5 MiB |
| firstName / lastName | Nonblank string, at most 100 characters |
| phone | Nonblank string, at most 20 characters |
| email | Valid email, at most 180 characters; trimmed and lowercased |
| password | 12–128 characters; never returned |

Login uses the normalized lowercase email as `username`. Text must be valid UTF-8; strings other than passwords are trimmed. Search name uses a SQL LIKE contains match (`%` and `_` have wildcard meaning). Date bounds are inclusive and cannot be reversed. Search reads query parameters; GET bodies do not supply filters.

Pagination defaults to `page=1&limit=20`, caps limit at 100, and orders by ascending ID. Unknown/array query values and offsets above 2147483647 are rejected. Separate page requests can reflect intervening writes.

```json
{"data":[],"meta":{"page":1,"limit":20,"total":0}}
```

Resources contain `id`, `name`, `label`, `numberOfFloors`, `address`, `postalCode`, `deliveryDate`, and `picture`. Nullable legacy dates and absent pictures serialize as null. `picture` is a stored filename; static pictures are served under `/uploaded_pictures/` when configured by the web server. Soft deletion hides records without removing database rows or image files.

## Errors

```json
{"error":{"code":"validation_failed","message":"Check the supplied fields.","details":{"numberOfFloors":"Must be an integer from 0 to 2147483647."}}}
```

| Status | Meaning |
| --- | --- |
| 400 | Malformed/non-object JSON, empty PATCH, or invalid query parameters |
| 401 | Missing/invalid JWT or invalid login credentials |
| 404 | Missing, deleted, foreign-owned, or invalid-ID resource |
| 405 | Unsupported route method |
| 409 | Duplicate normalized email, including database uniqueness races |
| 415 | Unsupported write media type |
| 422 | Invalid/unknown payload field or invalid upload |
| 500 | Internal failure with no stack trace/database details in response |

Lexik retains its authentication format, for example `{"code":401,"message":"JWT Token not found"}`, and login returns `{"token":"..."}`. Other API errors use the envelope above; empty details serialize as an object.

## Upload deployment settings

Enable `fileinfo`. Set PHP `upload_max_filesize` to at least `5M` and `post_max_size` above that (for example `8M`); request size limits also apply at the web server. The upload directory must be writable by PHP. Serve generated images as static files with script execution disabled and `X-Content-Type-Options: nosniff`. Do not route files in this directory to PHP. Images have unguessable filenames but are publicly served; project ownership protects API records, not a separate authenticated image download endpoint.
