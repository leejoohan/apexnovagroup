# Product Inventory REST API design

Status: approved by the user on 2026-09-28; implemented through parallel subagents.

## Purpose and scope

Build a runnable Laravel 11.x product inventory API satisfying the supplied brief: relational models, authenticated product CRUD, filters and pagination, validation requests, API resources, Eloquent scopes and attributes, soft deletes, feature tests, migrations, seeders, Docker, OpenAPI, caching, and rate limiting.

Create the Laravel application at the repository root. Preserve the existing unrelated `question1.php` file. No frontend application, deployment, or external publishing is required.

## Approach

Use Laravel's conventional controller, Form Request, model, and API Resource structure. Use PHP 8.3, MySQL 8.4, and Redis 7 in Docker Compose, with Nginx serving PHP-FPM. Use SQLite and the array cache for fast, isolated feature tests; include instructions for MySQL integration verification.

Alternatives considered: SQLite alone reduces setup but does not exercise the requested caching infrastructure; a repository/service architecture adds indirection without a requirement that justifies it. A small dedicated product cache component is justified to centralize keys and invalidation.

## Access and authentication

Assumption: this is a shared inventory for trusted staff. All authenticated users may read and modify the same products; multi-tenancy and roles are outside this brief.

Use Sanctum personal access tokens. Provide `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, and `GET /api/v1/auth/me`. Logout revokes the current token. Tokens expire after a configurable lifetime, defaulting to 24 hours. No public registration endpoint; document initial user provisioning and optional local demonstration credentials through environment configuration.

Return JSON authentication errors for API requests, including requests without an explicit Accept header. Never return passwords or stored token hashes.

## Data model

- Category: id, name, unique slug, timestamps; has many products.
- Supplier: id, name, unique email, optional phone, timestamps; belongs to many products.
- Product: id, category_id, unique SKU, name, optional description, decimal(12,2) price, nonnegative integer stock_quantity, timestamps, deleted_at.
- Product-supplier pivot: product_id and supplier_id with a composite unique key and foreign keys.

Product belongs to Category and belongs to many Suppliers. Category deletion is restricted while referenced. Product uses SoftDeletes; its SKU remains reserved after deletion. Price is serialized as a two-decimal string to avoid a floating-point API contract. An SKU mutator trims and uppercases values before validation and persistence; a stock_status accessor returns out_of_stock for zero, low_stock for 1–10, and in_stock for more than 10.

## Endpoints and validation

Authenticated product routes:

| Method | Path | Result |
| --- | --- | --- |
| GET | /api/v1/products | Filtered, paginated products |
| POST | /api/v1/products | Create product; 201 |
| GET | /api/v1/products/{product} | Product detail |
| PUT | /api/v1/products/{product} | Replace required product fields |
| PATCH | /api/v1/products/{product} | Update supplied fields |
| DELETE | /api/v1/products/{product} | Soft delete; 204 |
| GET | /api/v1/categories | Paginated category lookup |
| GET | /api/v1/suppliers | Paginated supplier lookup |

Use Form Requests for login, product writes, and list parameters. Validate existing category and supplier IDs, unique normalized SKU, at most two decimal places for price, nonnegative bounded price and stock, distinct supplier IDs, and string lengths. Product creation and supplier synchronization are transactional. PATCH preserves omitted relationships; supplier_ids: [] clears suppliers. PUT requires all required fields and an explicit supplier_ids array.

Filters combine with AND: category_id, min_price, max_price, min_stock, max_stock, and stock_status. Validate lower bounds against upper bounds when both are supplied. Implement filtering as an Eloquent scope. Pagination defaults to 15, allows 1–100 per page, and uses stable ascending id order. API Resources produce data, links, and meta for collections and data for individual resources. Eager load category and suppliers. Deleted products are excluded and return 404 for detail, update, and repeated delete requests.

Use conventional JSON error responses: 401 unauthenticated, 404 missing resource, 422 validation error, 429 rate limited, and generic 500 responses when debug is disabled.

## Caching and throttling

Cache product list and detail data in Redis for 60 seconds. Keys include normalized validated filters, page, page size, and a shared catalog revision. Cache data rather than complete HTTP responses so request-specific pagination URLs are generated per request. Advance the revision after successful product writes; stale generations expire naturally. Invalidation covers model changes, supplier pivot updates through the API, and category/supplier model edits that affect serialized products. Do not cache authentication responses or errors.

Rate-limit login to 5 attempts per minute per IP and normalized email, with an additional per-IP limit to prevent bypass by varying email. Limit authenticated API requests to 60 per minute per user. Use Redis as the shared limiter store in Docker; make test limits configurable and verify 429 responses and Retry-After.

## Documentation and local operations

Provide a complete OpenAPI 3 document with bearer authentication, all endpoints and query parameters, request/response schemas, examples, and error responses. Serve Swagger UI at /docs and the specification at /openapi.yaml. Pin the Swagger UI asset version and explain whether loading the UI requires internet access.

Docker Compose supplies application, Nginx, MySQL, and Redis services, persistent database storage, health checks, and environment examples. Bind the public HTTP port to localhost by default. Do not publish database/cache ports. Provide explicit setup commands for dependencies, application key, migrations, seeding, tests, logs, and shutdown. Database migrations and seeding run explicitly rather than destructively at every container startup.

Seed useful categories, suppliers, and related products across stock and price ranges. Optional demonstration user creation uses explicitly configured credentials. Supply factories for repeatable tests, and a Composer lockfile when dependency installation is available.

## Verification and acceptance

Feature coverage will exceed the five-test minimum and verify:

1. Login succeeds, invalid credentials fail, and current-token logout revokes access.
2. Product endpoints reject unauthenticated requests.
3. Product listing uses resource pagination and relationship formatting.
4. Category, inclusive price, stock quantity, and stock status filters work independently and together, including zero bounds.
5. Product creation persists category/supplier relationships and normalizes SKU.
6. Invalid writes, duplicate SKU, unknown relationships, and invalid query ranges return 422.
7. PATCH preserves omitted fields and relationships; explicit supplier arrays synchronize correctly.
8. PUT enforces required replacement fields.
9. Product deletion is soft and subsequent reads/updates exclude deleted records.
10. Cached reads reflect create, update, delete, and relationship changes.
11. Rate limiting returns 429 with retry metadata.
12. Documentation and lookup endpoints are available with their intended access rules.

Run the feature suite, formatting checks, migration/seed smoke checks, route inspection, OpenAPI validation, and Docker Compose configuration validation. Attempt a Docker/MySQL/Redis smoke test when the local Docker engine is available. Record any environment limitations explicitly; do not report unexecuted checks as passing.
