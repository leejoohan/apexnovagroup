# Product Inventory Implementation Plan

> **For agentic workers:** Use superpowers:subagent-driven-development. Track progress with the checkboxes below.

**Goal:** Deliver the approved runnable, tested inventory API.

**Architecture:** Conventional Laravel controllers, Form Requests, resources, and relational Eloquent models. A small cache component coordinates product reads and invalidation; Docker supplies MySQL and Redis.

**Tech Stack:** Laravel 11, PHP 8.3, Sanctum, MySQL 8.4, Redis 7, PHPUnit, OpenAPI 3.

**Spec:** `docs/superpowers/specs/2026-09-28-product-inventory-design.md`

## Global constraints

- Preserve question1.php. Application lives at repository root.
- Shared staff inventory; login/logout/me; no public registration.
- Routes use /api/v1; products use soft deletes and reserve SKU after deletion.
- Price decimal(12,2), stock nonnegative integer; stock statuses zero, 1–10, >10.
- Page size 15 by default, maximum 100. Product cache lifetime 60 seconds.
- Login limit 5/minute per IP/email plus per-IP limit; authenticated API 60/minute/user.
- Workers own disjoint files and do not commit or spawn other agents. Coordinator integrates and verifies.

## Review focus

- Zero filter bounds must remain active (product filter tests).
- Cached pagination URLs must use the current request (cache tests).
- Supplier sync must invalidate caches after the transaction commits (mutation/cache tests).
- Malformed scalar/array inputs must return 422, not 500 (validation tests).
- Logout revokes only the presented token (authentication tests).

### Task 1: Scaffold and authentication (coordinator)

Files: composer.json/lock, artisan, bootstrap/*, config/*, routes/api.php, routes/console.php, app/Models/User.php, app/Http/Controllers/AuthController.php, app/Http/Requests/LoginRequest.php, app/Http/Resources/UserResource.php, app/Providers/AppServiceProvider.php, user/token migrations, tests/Feature/AuthTest.php, phpunit.xml.

Interfaces: product routes call ProductController index/store/show/update/destroy; lookups call LookupController categories/suppliers. Cache observers are registered by product models, avoiding provider conflicts. Models User, Product, Category, Supplier expose factories.

- [x] Scaffold Laravel 11 and install Sanctum; configure SQLite test environment.
- [x] Write and run failing authentication tests: login 200 with plaintext token, invalid credentials 422, missing token 401 without Accept, logout 204 invalidates current token only, rate limits 429 with Retry-After.
- [x] Implement requests/resources/controllers, token expiry, routes, and named configurable throttles.
- [x] Run authentication tests and formatting.

### Task 2: Inventory domain and feature tests (API worker)

Files exclusively owned: app/Models/{Product,Category,Supplier}.php, app/Http/Controllers/{ProductController,LookupController}.php, app/Http/Requests/{StoreProductRequest,UpdateProductRequest,ProductIndexRequest,PaginationRequest}.php, app/Http/Resources/{ProductResource,CategoryResource,SupplierResource}.php, app/Support/ProductCache.php, app/Observers/*, domain migrations, domain factories, database/seeders/*, tests/Feature/{ProductTest,ProductCacheTest,LookupTest}.php.

Interfaces: route/controller names in Task 1; filter scope Product::scopeFilter(Builder $query, array $filters): Builder; ProductCache centralizes reads and invalidation. Models register their own observers/events. Seed credentials from config('inventory.seed_user_email') and config('inventory.seed_user_password').

- [x] Write tests against approved endpoint semantics before implementing behavior; run to demonstrate missing endpoints/models.
- [x] Implement migrations, models, factories, seeders, scope and attributes.
- [x] Implement validation, resource responses, transactional product/supplier mutations and paginated lookups.
- [x] Implement cached list/detail data and revision invalidation after committed writes.
- [x] Verify CRUD, combined filters and zero bounds, validation failures, PUT/PATCH differences, soft deletes, relationships, cache hit/invalidation and request-specific links.

### Task 3: Docker environment (infrastructure worker)

Files exclusively owned: Dockerfile, compose.yaml, .dockerignore, docker/*, .env.example.

Interfaces: app at /var/www/html; services app, web, mysql, redis; web http://localhost:8080. APP_KEY generated explicitly. App uses DB_HOST=mysql and REDIS_HOST=redis. Composer install in Docker build; persistent vendor handling must not hide installed dependencies. PHP 8.3 extensions include pdo_mysql, pdo_sqlite, mbstring, xml, redis.

- [x] Create PHP-FPM image, Nginx configuration, Compose health checks/volumes, and local-only published web port.
- [x] Supply environment settings matching Laravel config and configurable inventory limits/seed credentials.
- [x] Validate Compose and build image; report exact verified commands and limitations.

### Task 4: OpenAPI and README (documentation worker)

Files exclusively owned: public/openapi.yaml, resources/views/docs.blade.php, routes/web.php, README.md, tests/Feature/DocumentationTest.php.

Interfaces: /docs Swagger UI, /openapi.yaml spec, API paths/schemas from approved design and implemented resources; Docker commands from Task 3. Swagger UI assets pinned to a concrete version.

- [x] Write full OpenAPI paths, request/response schemas, filter parameters, bearer scheme and errors.
- [x] Add Swagger view/routes and documentation endpoint test.
- [x] Document Docker and native setup, explicit credentials, token examples, filters, cache invalidation, rate limits, tests and caveats.
- [x] Validate YAML/OpenAPI and verify documentation endpoints after integration.

### Task 5: Integration and independent review

- [x] Run all feature tests, Pint, Composer validation, migrate/seed smoke checks, and route inspection.
- [x] Build/start Docker and verify MySQL/Redis-backed authentication, CRUD, cache refresh and soft deletes.
- [x] Independent subagent reviews requirements and correctness across completed code; fix material findings and rerun affected checks.
- [x] Report actual verification results and startup instructions, retaining all user files.
