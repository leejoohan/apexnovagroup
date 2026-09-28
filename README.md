# Product Inventory API

A Laravel 11 REST API for a shared staff inventory. Authenticated staff can list, create, update, and soft delete products. Categories and suppliers are read-only lookups. There is no public registration endpoint or user interface beyond the API reference.

The interactive reference is at **`/docs`** and the OpenAPI 3 specification is at **`/openapi.yaml`**. The specification is served locally; `/docs` loads its pinned Swagger UI 5.17.14 CSS and JavaScript from jsDelivr, so the interactive page needs internet access.

## Start with Docker

You need Docker with Compose. The image build installs Composer dependencies, so Composer and PHP are not needed on the host.

```sh
cp .env.example .env
```

Edit `.env` and set a private `DB_PASSWORD`. To create an initial login during seeding, set **both** `INVENTORY_SEED_USER_EMAIL` and `INVENTORY_SEED_USER_PASSWORD` to credentials you choose. Leaving either empty skips that user. There are no built-in public or default login credentials.

```sh
docker compose build
docker compose run --rm app php artisan key:generate --show
```

Copy the printed `base64:...` value into `.env` as `APP_KEY=base64:...`. Key generation is explicit because the image has no writable source bind mount. Then initialize the database and start the web service:

```sh
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan db:seed --force
docker compose up -d
```

Open [http://localhost:8080/docs](http://localhost:8080/docs). Only the web service is published, on `127.0.0.1:8080`; MySQL and Redis are private Compose services. Data lives in named volumes. Migrations and seeding run only when you call them, so restarting containers does not reset data.

```sh
docker compose ps
docker compose logs -f app web
docker compose exec app php artisan test
docker compose down
```

After changing application source, rebuild the image and restart the services. After changing `.env`, recreate the affected containers. To seed a user after the containers are running, set the two seed variables in `.env`, run `docker compose up -d --force-recreate app`, then run `docker compose exec app php artisan db:seed --force`.

## Native setup

Use PHP 8.3 or newer with Composer 2 and the required PHP extensions (`pdo_sqlite` or `pdo_mysql`, `mbstring`, `xml`, and `redis` if using Redis). For a local SQLite setup, create `.env` from the example, **delete its `DB_DATABASE=inventory` line**, and set these values. With `DB_DATABASE` absent, Laravel uses `database/database.sqlite`.

```dotenv
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=sqlite
CACHE_STORE=file
CACHE_LIMITER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
```

Set `INVENTORY_SEED_USER_EMAIL` and `INVENTORY_SEED_USER_PASSWORD` in `.env` if you want a login. Then run:

```sh
composer install
touch database/database.sqlite
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

The native API is at [http://127.0.0.1:8000/docs](http://127.0.0.1:8000/docs). If your PHP has no `phpredis` extension, the SQLite/file configuration above does not connect to Redis. For a native MySQL/Redis setup, update `.env` with reachable `DB_*` and `REDIS_*` values and use `CACHE_STORE=redis`, `CACHE_LIMITER=redis`, and `SESSION_DRIVER=redis`; `REDIS_CLIENT=predis` is an option when `phpredis` is unavailable.

## Authenticate and call the API

Log in with a user created by the optional seed credentials or provisioned privately in Laravel. All authenticated users share the same inventory and may modify it. Login needs `email` and `password`; `device_name` is optional (up to 255 characters). The response contains `data.token`, `data.token_type` (`Bearer`), and `data.user` (`id`, `name`, `email`). Store the plaintext token when issued; the API does not expose it again.

```sh
curl -sS -X POST http://localhost:8080/api/v1/auth/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"staff@example.com","password":"your-password","device_name":"inventory-cli"}'
```

Use the returned token in later calls (replace the placeholder):

```sh
TOKEN='paste-returned-data.token-here'
curl -sS 'http://localhost:8080/api/v1/products?category_id=1&min_price=0&max_price=100&stock_status=in_stock&per_page=20' \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -sS http://localhost:8080/api/v1/categories \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -sS -X POST http://localhost:8080/api/v1/auth/logout \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
```

The token lasts `INVENTORY_TOKEN_EXPIRATION` minutes (default 1440); logout revokes the token used for that request. `GET /api/v1/auth/me` returns the current user. There is no registration route.

## Product contract

`GET /api/v1/products` accepts `category_id`, inclusive `min_price`/`max_price`, inclusive `min_stock`/`max_stock`, and `stock_status` (`out_of_stock`, `low_stock`, `in_stock`). Filters combine with AND. Price and stock bounds can be zero; a lower bound above its upper bound returns 422. Results are ordered by ascending ID and paginated with `page` and `per_page` (default 15, maximum 100). Collections return `data`, `links`, and `meta`; individual products return `data`.

Create a product with `POST /api/v1/products` and update it with `PUT` or `PATCH /api/v1/products/{product}`. Required creation fields are `category_id`, `sku`, `name`, `price`, and `stock_quantity`; `supplier_ids` may be omitted or provided as a distinct array of existing IDs. PUT requires the required fields **and** an explicit `supplier_ids` array. PATCH preserves omitted fields and relationships; `"supplier_ids": []` clears all suppliers. SKU values are trimmed and uppercased. A deleted product is hidden from reads and updates, and its SKU cannot be reused.

Prices allow up to two decimal places and return as strings with two decimal places. Stock is a nonnegative integer. `stock_status` is `out_of_stock` at 0, `low_stock` at 1–10, and `in_stock` above 10. Writes with invalid data or unknown category/supplier IDs return 422. `DELETE /api/v1/products/{product}` returns 204; subsequent requests for that product return 404.

Product lists and details are cached for 60 seconds in Redis in Docker. Product writes and related category/supplier changes invalidate the catalog generation after successful commits. Cached pagination links are generated for each current request.

The login limit defaults to `INVENTORY_LOGIN_RATE_LIMIT=5` per minute per IP and normalized email, plus `INVENTORY_LOGIN_IP_RATE_LIMIT=20` per minute per IP. Authenticated API traffic defaults to `INVENTORY_API_RATE_LIMIT=60` per minute per user. Exceeded limits return 429 with `Retry-After`. Missing or expired authentication returns JSON 401, missing products return 404, and validation errors return JSON 422 with field errors. Set `APP_DEBUG=false` outside local development so unexpected 500 errors do not expose details.

## Testing with Docker

Run these commands from the repository root, where `compose.yaml` is located. Install Docker with Compose and configure `.env` as described in [Start with Docker](#start-with-docker). PHP, Composer, and PHPUnit are included in the application image; no host PHP installation is required.

### 1. Build and start the containers

```sh
docker compose up -d --build
docker compose ps
```

The services should report `running` and become `healthy`. The source code is copied into the image, so repeat the build command after changing application code or tests.

### 2. Run the complete feature suite

```sh
docker compose exec app php artisan test --testsuite=Feature
```

This prints each test and its pass/fail status. For a shorter summary:

```sh
docker compose exec app php artisan test --compact
```

The verified implementation result is:

```text
Tests:    38 passed (207 assertions)
```

The number may increase as tests are added. A successful command exits with code `0`; a failing test reports its name, expected/actual result, and file location, and returns a nonzero exit code.

The tests create their own temporary users, products, categories, and suppliers. You do not need a login token, seed credentials, or a manual migration/seed step to run this suite. They exercise Laravel's HTTP request handling inside the test process using **in-memory SQLite and array caches**. They do not send requests to the running Nginx service or use its MySQL inventory data.

### 3. Run a specific group of tests

```sh
# Product CRUD, filters, pagination, validation, and soft deletes
docker compose exec app php artisan test --filter=ProductTest

# Login, tokens, logout, and rate limits
docker compose exec app php artisan test --filter=AuthTest

# Cache hits, invalidation, transaction timing, and pagination URLs
docker compose exec app php artisan test --filter=ProductCacheTest

# Category/supplier lookups, pagination, and seeders
docker compose exec app php artisan test --filter=LookupTest

# Swagger page and OpenAPI document
docker compose exec app php artisan test --filter=DocumentationTest

# Test environment isolation
docker compose exec app php artisan test --filter=EnvironmentTest
```

### 4. Run one endpoint test

```sh
docker compose exec app php artisan test \
  --filter=test_store_normalizes_sku_and_persists_suppliers
```

That test creates a category and two suppliers, submits a product through `POST /api/v1/products`, checks HTTP `201` and the normalized SKU, then verifies the supplier links in the database.

To stop at the first failure while investigating:

```sh
docker compose exec app php artisan test --stop-on-failure
```

### Main endpoint coverage

The project exceeds the requirement for at least five feature tests. Examples from [ProductTest.php](tests/Feature/ProductTest.php) and [AuthTest.php](tests/Feature/AuthTest.php) include:

| Scenario | Endpoint | Assertions |
| --- | --- | --- |
| List products | `GET /api/v1/products` | HTTP 200, pagination, price formatting, and related resources |
| Filter products | `GET /api/v1/products?...` | Category, inclusive price/stock ranges, stock statuses, and zero bounds |
| Create product | `POST /api/v1/products` | HTTP 201, normalized SKU, and persisted supplier relationships |
| Partially update product | `PATCH /api/v1/products/{product}` | HTTP 200, omitted fields preserved, explicit empty supplier list clears links |
| Replace product | `PUT /api/v1/products/{product}` | Required replacement fields validated and values replaced |
| Delete product | `DELETE /api/v1/products/{product}` | HTTP 204, soft-deleted database row, subsequent HTTP 404 |
| Reject invalid input | Product write/list endpoints | HTTP 422 and field-level validation errors |
| Reject unauthenticated access | All product endpoints | HTTP 401 |
| Log in | `POST /api/v1/auth/login` | Token returned, safe user fields, incorrect credentials rejected |
| Log out | `POST /api/v1/auth/logout` | Current token revoked while other tokens remain valid |
| Enforce rate limits | Login and authenticated API endpoints | HTTP 429 and `Retry-After` |

For the actual code snippets, see [IMPLEMENTATION_SHOWCASE.md](IMPLEMENTATION_SHOWCASE.md#9-feature-tests).

### Run tests without starting the full stack

Because feature tests use SQLite and array caches, they can run in a temporary application container without MySQL, Redis, or Nginx running. Configure `.env` first; Compose still reads it when resolving the service configuration.

```sh
docker compose build app
docker compose run --rm --no-deps -T app php artisan test --testsuite=Feature --compact
```

For scripts or CI using an already running application container, disable terminal allocation:

```sh
docker compose exec -T app php artisan test --testsuite=Feature --compact
```

### Additional checks

```sh
# Check PHP formatting without changing files
docker compose exec app vendor/bin/pint --test

# Validate Composer configuration against the lockfile
docker compose exec app composer validate --strict

# Inspect registered API routes
docker compose exec app php artisan route:list --path=api/v1
```

These checks complement the feature tests. To exercise the running MySQL/Redis-backed API, use [Swagger UI](http://localhost:8080/docs) or the requests in [Authenticate and call the API](#authenticate-and-call-the-api): log in, create a product, read a filtered list, update the product, read it again to check cache refresh, then delete it and confirm a subsequent read returns 404. This manual integration check requires migrations, seed data, and a staff login from the Docker setup steps.

### Troubleshooting

| Problem | Action |
| --- | --- |
| Docker daemon is unavailable | Start Docker Desktop or your Docker engine, then retry. |
| `service "app" is not running` | Run `docker compose up -d`, or use the temporary-container test command above. |
| A newly added test is not found, or results reflect old code | Run `docker compose up -d --build app` and rerun the tests. |
| The isolation guard reports cached configuration | Run `docker compose exec app php artisan config:clear`, then rerun the tests. |
| Compose reports missing `DB_PASSWORD` | Configure `.env` using the Docker setup instructions. |
| A test fails | Read the reported assertion and rerun it with `--filter=<test_method_name>`. |

[phpunit.xml](phpunit.xml) overrides inherited environment settings, including `DB_URL`. [tests/TestCase.php](tests/TestCase.php) refuses to run database tests unless the testing environment, in-memory SQLite, and array caches are active. No `migrate:fresh` command is needed for this test workflow.

When finished, stop the local stack while preserving its database volumes:

```sh
docker compose down
```

### Native test commands

If you followed the native setup instead of Docker:

```sh
php artisan test --testsuite=Feature
vendor/bin/pint --test
php artisan route:list --path=api/v1
```
