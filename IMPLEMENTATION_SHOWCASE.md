# Product Inventory API — Implementation Showcase

This document maps the original requirements to code implemented in this repository. Snippets are extracted from the source files; imports and surrounding classes are omitted where indicated by the excerpt. Use the linked files for complete implementations and [README.md](README.md) for setup.

The application uses Laravel 11, PHP 8.3, Sanctum, MySQL, and Redis. It serves a shared inventory for authenticated staff; category and supplier endpoints provide read-only lookups.

## Requirement coverage

| Requirement | Implementation evidence |
| --- | --- |
| Product, Category, Supplier relationships | [1. Models and relationships](#1-models-and-relationships) |
| Full product CRUD | [2. Product CRUD](#2-product-crud) |
| Category, price, stock filters and pagination | [3. Filtering and pagination](#3-filtering-and-pagination) |
| Sanctum authentication | [4. Sanctum authentication](#4-sanctum-authentication) |
| Form Request validation | [5. Form Requests](#5-form-requests) |
| API Resources | [6. Response formatting](#6-response-formatting) |
| Eloquent scope and accessor/mutator | [3. Filtering and pagination](#3-filtering-and-pagination) and [7. Accessor and mutator](#7-accessor-and-mutator) |
| Product soft deletes | [8. Soft deletes and migrations](#8-soft-deletes-and-migrations) |
| At least five feature tests | [9. Feature tests](#9-feature-tests) |
| Migrations, seeders, setup README | [8. Soft deletes and migrations](#8-soft-deletes-and-migrations) and [10. Seeders and setup](#10-seeders-and-setup) |
| Docker | [11. Docker environment](#11-docker-environment) |
| Swagger/OpenAPI | [12. API documentation](#12-api-documentation) |
| Caching | [13. Product caching](#13-product-caching) |
| Rate limiting | [14. Rate limiting](#14-rate-limiting) |

## 1. Models and relationships

Each product belongs to one category and may have multiple suppliers. Category and Supplier expose the inverse relationships.

Source: [app/Models/Product.php](app/Models/Product.php), line 46.

```php
public function category(): BelongsTo
{
    return $this->belongsTo(Category::class);
}
```

Source: [app/Models/Product.php](app/Models/Product.php), line 51.

```php
public function suppliers(): BelongsToMany
{
    return $this->belongsToMany(Supplier::class);
}
```

Source: [app/Models/Category.php](app/Models/Category.php), line 22.

```php
public function products(): HasMany
{
    return $this->hasMany(Product::class);
}
```

Source: [app/Models/Supplier.php](app/Models/Supplier.php), line 22.

```php
public function products(): BelongsToMany
{
    return $this->belongsToMany(Product::class);
}
```

## 2. Product CRUD

Laravel registers all product resource actions under `/api/v1`. The route group requires a valid Sanctum token and applies the authenticated API rate limit.

Source: [routes/api.php](routes/api.php), line 8.

```php
Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::apiResource('products', ProductController::class);
        Route::get('categories', [LookupController::class, 'categories']);
        Route::get('suppliers', [LookupController::class, 'suppliers']);
    });
});
```

| Method | Endpoint | Controller action | Success |
| --- | --- | --- | --- |
| GET | `/api/v1/products` | `index` | 200, paginated collection |
| POST | `/api/v1/products` | `store` | 201, created product |
| GET | `/api/v1/products/{product}` | `show` | 200, product detail |
| PUT | `/api/v1/products/{product}` | `update` | 200, replacement |
| PATCH | `/api/v1/products/{product}` | `update` | 200, partial update |
| DELETE | `/api/v1/products/{product}` | `destroy` | 204, no body |

Creation saves the product and synchronizes suppliers in a database transaction. The resource includes the loaded category and suppliers.

Source: [app/Http/Controllers/ProductController.php](app/Http/Controllers/ProductController.php), line 29.

```php
public function store(StoreProductRequest $request): JsonResponse
{
    $data = $request->validated();
    $product = DB::transaction(function () use ($data) {
        $product = Product::create(Arr::except($data, 'supplier_ids'));
        $product->suppliers()->sync($data['supplier_ids'] ?? []);
        ProductCache::invalidateAfterCommit();

        return $product->load(['category', 'suppliers']);
    });

    return (new ProductResource($product))->response()->setStatusCode(201);
}
```

Detail reads use the cache component shown in section 13. Updating also runs transactionally: PATCH preserves omitted fields, while PUT clears an omitted optional description. An explicit empty supplier array removes all supplier links.

Source: [app/Http/Controllers/ProductController.php](app/Http/Controllers/ProductController.php), line 43.

```php
public function show(Product $product, ProductCache $cache): ProductResource
{
    return new ProductResource($cache->product($product));
}
```

Source: [app/Http/Controllers/ProductController.php](app/Http/Controllers/ProductController.php), line 48.

```php
public function update(UpdateProductRequest $request, Product $product): ProductResource
{
    $data = $request->validated();
    $product = DB::transaction(function () use ($product, $data) {
        $attributes = Arr::except($data, 'supplier_ids');
        if (request()->isMethod('put') && ! array_key_exists('description', $attributes)) {
            $attributes['description'] = null;
        }
        $product->update($attributes);
        if (array_key_exists('supplier_ids', $data)) {
            $product->suppliers()->sync($data['supplier_ids']);
            ProductCache::invalidateAfterCommit();
        }

        return $product->refresh()->load(['category', 'suppliers']);
    });

    return new ProductResource($product);
}
```

## 3. Filtering and pagination

`scopeFilter` is the Eloquent local scope. Category, inclusive price bounds, stock quantity bounds, and stock status combine with AND. Checking `isset` preserves zero-valued bounds.

Source: [app/Models/Product.php](app/Models/Product.php), line 56.

```php
public function scopeFilter(Builder $query, array $filters): Builder
{
    if (isset($filters['category_id'])) {
        $query->where('category_id', $filters['category_id']);
    }
    foreach (['min_price' => ['price', '>='], 'max_price' => ['price', '<='],
        'min_stock' => ['stock_quantity', '>='], 'max_stock' => ['stock_quantity', '<=']] as $key => [$column, $operator]) {
        if (isset($filters[$key])) {
            $query->where($column, $operator, $filters[$key]);
        }
    }
    if (isset($filters['stock_status'])) {
        match ($filters['stock_status']) {
            'out_of_stock' => $query->where('stock_quantity', 0),
            'low_stock' => $query->whereBetween('stock_quantity', [1, 10]),
            'in_stock' => $query->where('stock_quantity', '>', 10),
        };
    }

    return $query;
}
```

The list action passes only validated filters and pagination parameters to the cache/query component. The query uses eager loading and stable ascending product ID ordering.

Source: [app/Http/Controllers/ProductController.php](app/Http/Controllers/ProductController.php), line 19.

```php
public function index(ProductIndexRequest $request, ProductCache $cache): AnonymousResourceCollection
{
    $validated = $request->validated();
    $page = (int) ($validated['page'] ?? 1);
    $perPage = $request->pageSize();
    $filters = Arr::except($validated, ['page', 'per_page']);

    return ProductResource::collection($cache->products($filters, $page, $perPage));
}
```

Page size defaults to 15 and is limited to 100. The list query uses `->filter($filters)->orderBy('id')->paginate(...)`; its full implementation appears in section 13.

Source: [app/Http/Requests/PaginationRequest.php](app/Http/Requests/PaginationRequest.php), line 14.

```php
public function rules(): array
{
    return [
        'page' => ['sometimes', 'integer', 'min:1'],
        'per_page' => ['sometimes', 'integer', 'between:1,100'],
    ];
}
```

Source: [app/Http/Requests/PaginationRequest.php](app/Http/Requests/PaginationRequest.php), line 22.

```php
public function pageSize(): int
{
    return (int) ($this->validated('per_page') ?? 15);
}
```

Example request:

```http
GET /api/v1/products?category_id=1&min_price=10&max_price=50&min_stock=1&max_stock=10&per_page=20&page=1
Authorization: Bearer <token>
```

[ProductIndexRequest](app/Http/Requests/ProductIndexRequest.php) rejects malformed filters and lower bounds above upper bounds with 422 responses.

## 4. Sanctum authentication

The User model includes Sanctum's `HasApiTokens` trait. Login checks the hashed password, creates a token with a configurable expiry, and returns its plaintext value once.

Source: [app/Models/User.php](app/Models/User.php), line 15.

```php
use HasApiTokens, HasFactory, Notifiable;
```

Source: [app/Http/Controllers/AuthController.php](app/Http/Controllers/AuthController.php), line 16.

```php
public function login(LoginRequest $request): JsonResponse
{
    $data = $request->validated();
    $user = User::where('email', $data['email'])->first();
    if (! $user || ! Hash::check($data['password'], $user->password)) {
        throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
    }

    $token = $user->createToken($data['device_name'] ?? 'inventory-api', ['*'], now()->addMinutes(config('sanctum.expiration')));

    return response()->json(['data' => [
        'token' => $token->plainTextToken,
        'token_type' => 'Bearer',
        'user' => new UserResource($user),
    ]]);
}
```

Logout deletes only the token presented with that request. Other tokens belonging to the same user remain valid. The default token lifetime is 1,440 minutes, configured in [config/sanctum.php](config/sanctum.php). There is no public registration route.

Source: [app/Http/Controllers/AuthController.php](app/Http/Controllers/AuthController.php), line 38.

```php
public function logout(Request $request): Response
{
    $request->user()->currentAccessToken()->delete();

    return response()->noContent();
}
```

## 5. Form Requests

Validation lives outside controllers. SKU input is normalized before checking uniqueness; category and supplier IDs must exist; price and stock must be nonnegative.

Source: [app/Http/Requests/StoreProductRequest.php](app/Http/Requests/StoreProductRequest.php), line 16.

```php
protected function prepareForValidation(): void
{
    if (is_string($this->input('sku'))) {
        $this->merge(['sku' => Str::upper(trim($this->input('sku')))]);
    }
}
```

Source: [app/Http/Requests/StoreProductRequest.php](app/Http/Requests/StoreProductRequest.php), line 23.

```php
public function rules(): array
{
    return [
        'category_id' => ['required', 'integer', 'exists:categories,id'],
        'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')],
        'name' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string', 'max:10000'],
        'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        'stock_quantity' => ['required', 'integer', 'between:0,4294967295'],
        'supplier_ids' => ['sometimes', 'array'],
        'supplier_ids.*' => ['required', 'integer', 'distinct', 'exists:suppliers,id'],
    ];
}
```

PUT requires all required product fields plus an explicit supplier array. PATCH validates supplied fields while preserving omitted values. `Rule::unique(...)->ignore($product?->id)` permits retaining the current SKU during an update.

Source: [app/Http/Requests/UpdateProductRequest.php](app/Http/Requests/UpdateProductRequest.php), line 23.

```php
public function rules(): array
{
    $required = $this->isMethod('put') ? 'required' : 'sometimes';
    $product = $this->route('product');

    return [
        'category_id' => [$required, 'integer', 'exists:categories,id'],
        'sku' => [$required, 'string', 'max:255', Rule::unique('products', 'sku')->ignore($product?->id)],
        'name' => [$required, 'string', 'max:255'],
        'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
        'price' => [$required, 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        'stock_quantity' => [$required, 'integer', 'between:0,4294967295'],
        'supplier_ids' => [$this->isMethod('put') ? 'present' : 'sometimes', 'array'],
        'supplier_ids.*' => ['required', 'integer', 'distinct', 'exists:suppliers,id'],
    ];
}
```

Additional Form Requests are [LoginRequest](app/Http/Requests/LoginRequest.php), [ProductIndexRequest](app/Http/Requests/ProductIndexRequest.php), and [PaginationRequest](app/Http/Requests/PaginationRequest.php). Invalid input returns JSON 422 with field-level errors.

## 6. Response formatting

`ProductResource` defines the public JSON fields and includes related resources only when loaded. The model casts price to `decimal:2`, so the API returns amounts such as `"12.50"`. Collections include Laravel pagination `data`, `links`, and `meta`.

Source: [app/Http/Resources/ProductResource.php](app/Http/Resources/ProductResource.php), line 10.

```php
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'category_id' => $this->category_id,
        'sku' => $this->sku,
        'name' => $this->name,
        'description' => $this->description,
        'price' => $this->price,
        'stock_quantity' => $this->stock_quantity,
        'stock_status' => $this->stock_status,
        'category' => new CategoryResource($this->whenLoaded('category')),
        'suppliers' => SupplierResource::collection($this->whenLoaded('suppliers')),
        'created_at' => $this->created_at?->toISOString(),
        'updated_at' => $this->updated_at?->toISOString(),
    ];
}
```

## 7. Accessor and mutator

The SKU mutator also protects normalization when products are written through Eloquent outside HTTP requests. The stock-status accessor derives a value instead of storing redundant status data.

Source: [app/Models/Product.php](app/Models/Product.php), line 32.

```php
protected function sku(): Attribute
{
    return Attribute::make(set: fn ($value) => mb_strtoupper(trim($value)));
}
```

Source: [app/Models/Product.php](app/Models/Product.php), line 37.

```php
protected function stockStatus(): Attribute
{
    return Attribute::make(get: fn () => match (true) {
        $this->stock_quantity === 0 => 'out_of_stock',
        $this->stock_quantity <= 10 => 'low_stock',
        default => 'in_stock',
    });
}
```

## 8. Soft deletes and migrations

The product model uses `SoftDeletes`. Its migration adds `deleted_at`, a unique SKU, decimal price, unsigned stock quantity, and a category foreign key. Deleting a category that products still reference is restricted.

Source: [app/Models/Product.php](app/Models/Product.php), line 16.

```php
use HasFactory, SoftDeletes;
```

Source: [database/migrations/2026_09_28_000003_create_products_table.php](database/migrations/2026_09_28_000003_create_products_table.php), line 9.

```php
public function up(): void
{
    Schema::create('products', function (Blueprint $table) {
        $table->id();
        $table->foreignId('category_id')->constrained()->restrictOnDelete();
        $table->string('sku')->unique();
        $table->string('name');
        $table->text('description')->nullable();
        $table->decimal('price', 12, 2);
        $table->unsignedInteger('stock_quantity');
        $table->timestamps();
        $table->softDeletes();
    });
}
```

The pivot table enforces valid references and prevents duplicate product/supplier pairs.

Source: [database/migrations/2026_09_28_000004_create_product_supplier_table.php](database/migrations/2026_09_28_000004_create_product_supplier_table.php), line 9.

```php
public function up(): void
{
    Schema::create('product_supplier', function (Blueprint $table) {
        $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
        $table->unique(['product_id', 'supplier_id']);
    });
}
```

Deleting a product sets its deletion timestamp and returns 204. Eloquent queries and route binding exclude deleted products; their SKUs remain reserved by the unique constraint.

Source: [app/Http/Controllers/ProductController.php](app/Http/Controllers/ProductController.php), line 68.

```php
public function destroy(Product $product): Response
{
    $product->delete();

    return response()->noContent();
}
```

## 9. Feature tests

The suite contains 38 feature tests, exceeding the minimum of five. The following six excerpts demonstrate actual endpoint assertions. Product tests use `RefreshDatabase` and authenticate a factory user with `Sanctum::actingAs(...)` in `setUp`.

### Authentication returns a token and safe user fields

Source: [tests/Feature/AuthTest.php](tests/Feature/AuthTest.php), line 13.

```php
public function test_login_returns_token_and_safe_user_resource(): void
{
    $user = User::factory()->create(['password' => 'Correct-password-123']);
    $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Correct-password-123']);
    $response->assertOk()->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.user.id', $user->id)->assertJsonMissingPath('data.user.password');
    $this->assertNotEmpty($response->json('data.token'));
    $this->assertDatabaseCount('personal_access_tokens', 1);
}
```

### Creation persists suppliers and normalizes SKU

Source: [tests/Feature/ProductTest.php](tests/Feature/ProductTest.php), line 82.

```php
public function test_store_normalizes_sku_and_persists_suppliers(): void
{
    $category = Category::factory()->create();
    $suppliers = Supplier::factory()->count(2)->create();

    $this->postJson('/api/v1/products', [
        'category_id' => $category->id,
        'sku' => '  item-123  ',
        'name' => 'Item',
        'description' => 'Useful',
        'price' => '12.50',
        'stock_quantity' => 10,
        'supplier_ids' => $suppliers->modelKeys(),
    ])->assertCreated()->assertJsonPath('data.sku', 'ITEM-123')
        ->assertJsonPath('data.stock_status', 'low_stock');

    $product = Product::where('sku', 'ITEM-123')->firstOrFail();
    $this->assertEqualsCanonicalizing($suppliers->modelKeys(), $product->suppliers()->pluck('suppliers.id')->all());
}
```

### Combined filters respect zero-valued bounds

Source: [tests/Feature/ProductTest.php](tests/Feature/ProductTest.php), line 43.

```php
public function test_filters_are_combined_and_zero_bounds_are_applied(): void
{
    $category = Category::factory()->create();
    $other = Category::factory()->create();
    $wanted = Product::factory()->for($category)->create(['price' => '0.00', 'stock_quantity' => 0]);
    Product::factory()->for($category)->create(['price' => '1.00', 'stock_quantity' => 0]);
    Product::factory()->for($other)->create(['price' => '0.00', 'stock_quantity' => 0]);
    Product::factory()->for($category)->create(['price' => '0.00', 'stock_quantity' => 1]);

    $this->getJson("/api/v1/products?category_id={$category->id}&min_price=0&max_price=0&min_stock=0&max_stock=0&stock_status=out_of_stock")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $wanted->id);
}
```

### PATCH preserves omitted values and clears explicit empty suppliers

Source: [tests/Feature/ProductTest.php](tests/Feature/ProductTest.php), line 126.

```php
public function test_patch_preserves_omitted_fields_and_explicit_empty_suppliers_clear_them(): void
{
    $product = Product::factory()->create(['name' => 'Original', 'price' => '9.99']);
    $supplier = Supplier::factory()->create();
    $product->suppliers()->attach($supplier);

    $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed'])
        ->assertOk()->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.suppliers.0.id', $supplier->id);
    $this->patchJson("/api/v1/products/{$product->id}", ['supplier_ids' => []])
        ->assertOk()->assertJsonCount(0, 'data.suppliers');
    $this->assertSame('9.99', $product->fresh()->price);
}
```

### Deletion is soft and deleted products become unavailable

Source: [tests/Feature/ProductTest.php](tests/Feature/ProductTest.php), line 159.

```php
public function test_delete_soft_deletes_and_hides_product_from_all_endpoints(): void
{
    $product = Product::factory()->create();

    $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();
    $this->assertSoftDeleted($product);
    $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();
    $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Again'])->assertNotFound();
    $this->deleteJson("/api/v1/products/{$product->id}")->assertNotFound();
}
```

### Excessive login requests return 429 and Retry-After

Source: [tests/Feature/AuthTest.php](tests/Feature/AuthTest.php), line 61.

```php
public function test_login_is_rate_limited_with_retry_after(): void
{
    config(['inventory.login_rate_limit' => 2]);
    $payload = ['email' => 'missing@example.test', 'password' => 'bad'];
    $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
    $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
    $this->postJson('/api/v1/auth/login', $payload)->assertStatus(429)->assertHeader('Retry-After');
}
```

Further coverage includes invalid input, full PUT replacement, token expiry and revocation, pagination links, cache invalidation, stale route-bound models, documentation routes, and safe test-environment isolation.

The completed implementation was verified with **38 passing tests and 207 assertions**, both natively and inside Docker. MySQL/Redis HTTP smoke checks and OpenAPI schema validation also passed. These are the implementation verification results; rerun the commands below after future changes.

```sh
php artisan test
# Or, with the Docker stack running:
docker compose exec app php artisan test
```

[tests/TestCase.php](tests/TestCase.php) refuses database tests unless the environment uses in-memory SQLite and array caches, with no overriding database URL. [phpunit.xml](phpunit.xml) overrides inherited container settings.

## 10. Seeders and setup

The seeder creates three categories, three suppliers, and four demonstration products spanning different prices and stock levels. It reuses existing SKUs, respects soft-deleted rows, and synchronizes supplier links.

Source: [database/seeders/DatabaseSeeder.php](database/seeders/DatabaseSeeder.php), line 39.

```php
foreach ($products as $data) {
    $product = Product::withTrashed()->firstOrCreate(['sku' => $data['sku']], [
        'category_id' => $categories[$data['category']]->id,
        'name' => $data['name'],
        'description' => $data['description'],
        'price' => $data['price'],
        'stock_quantity' => $data['stock_quantity'],
    ]);
    if (! $product->trashed()) {
        $product->suppliers()->sync(collect($data['suppliers'])->map(fn (string $email) => $suppliers[$email]->id)->all());
    }
}
ProductCache::invalidateAfterCommit();
```

A staff login is created only when both configured seed credentials are supplied. No default password is embedded in the application.

Source: [database/seeders/DatabaseSeeder.php](database/seeders/DatabaseSeeder.php), line 53.

```php
$email = config('inventory.seed_user_email');
$password = config('inventory.seed_user_password');
$email = is_string($email) ? strtolower(trim($email)) : null;
if (is_string($email) && $email !== '' && is_string($password) && $password !== '') {
    User::firstOrCreate(['email' => $email], ['name' => 'Inventory Demo', 'password' => $password]);
}
```

[README.md](README.md) includes Docker/native setup, explicit application-key generation, migration/seeding commands, authentication examples, filters, caching, rate limits, and test commands. After configuring the environment and building the images:

```sh
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan db:seed --force
docker compose up -d
```

## 11. Docker environment

[Dockerfile](Dockerfile) builds a PHP 8.3 FPM image with MySQL/SQLite PDO and Redis support. Composer installs the locked dependencies inside the image; a separate Nginx target serves the public directory.

Source: [Dockerfile](Dockerfile), line 25.

```dockerfile
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
COPY . /var/www/html

RUN composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

FROM nginx:1.27-alpine AS web

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

EXPOSE 80
```

Compose supplies `app`, `web`, `mysql`, and `redis` services with health checks. MySQL 8.4 and Redis 7 use named volumes; neither publishes a host port. The following application environment excerpt selects those internal services:

Source: [compose.yaml](compose.yaml), line 13.

```yaml
DB_CONNECTION: mysql
DB_HOST: mysql
DB_PORT: 3306
DB_DATABASE: ${DB_DATABASE:-inventory}
DB_USERNAME: ${DB_USERNAME:-inventory}
DB_PASSWORD: ${DB_PASSWORD:?Set DB_PASSWORD in .env}
REDIS_CLIENT: phpredis
REDIS_HOST: redis
REDIS_PORT: 6379
CACHE_STORE: redis
CACHE_LIMITER: redis
SESSION_DRIVER: redis
QUEUE_CONNECTION: sync
```

Only Nginx publishes a port, bound to localhost:

Source: [compose.yaml](compose.yaml), line 45.

```yaml
web:
  build:
    context: .
    target: web
  ports:
    - "127.0.0.1:8080:80"
  depends_on:
    app:
      condition: service_healthy
```

The application source is copied into the images, so rebuild after code changes. Migrations and seeding run explicitly, rather than resetting data on startup.

## 12. API documentation

[public/openapi.yaml](public/openapi.yaml) declares OpenAPI 3.0.3 paths, request and response schemas, query filters, bearer authentication, and error responses. Its bearer security scheme is:

Source: [public/openapi.yaml](public/openapi.yaml), line 345.

```yaml
securitySchemes:
  bearerAuth:
    type: http
    scheme: bearer
    bearerFormat: Sanctum personal access token
```

The Swagger page loads the local specification. Swagger UI assets are pinned to version 5.17.14 and require internet access to jsDelivr.

Source: [resources/views/docs.blade.php](resources/views/docs.blade.php), line 19.

```javascript
window.ui = SwaggerUIBundle({
    url: @json(route('openapi')),
    dom_id: '#swagger-ui',
    deepLinking: true,
    presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
    layout: 'StandaloneLayout',
});
```

Open [Swagger UI](http://localhost:8080/docs) or the [OpenAPI specification](http://localhost:8080/openapi.yaml) after starting Docker. Routes are defined in [routes/web.php](routes/web.php).

## 13. Product caching

`ProductCache` caches list data for 60 seconds. Keys include filters, page, page size, and a catalog revision. It builds a fresh paginator for each request so cached data does not reuse another request’s URLs.

Source: [app/Support/ProductCache.php](app/Support/ProductCache.php), line 33.

```php
public function products(array $filters, int $page, int $perPage): LengthAwarePaginator
{
    $normalized = collect($filters)->sortKeys()->all();
    $key = $this->key('list', [$normalized, $page, $perPage]);
    $data = Cache::remember($key, self::TTL_SECONDS, function () use ($filters, $page, $perPage) {
        $result = Product::query()->with(['category', 'suppliers'])
            ->filter($filters)->orderBy('id')->paginate($perPage, ['*'], 'page', $page);

        return ['items' => $result->items(), 'total' => $result->total()];
    });

    return new LengthAwarePaginator($data['items'], $data['total'], $perPage, $page, [
        'path' => request()->url(),
        'query' => request()->query(),
    ]);
}
```

Detail cache misses re-query the product after selecting the cache key. This prevents an older route-bound model from being cached under a newer catalog revision.

Source: [app/Support/ProductCache.php](app/Support/ProductCache.php), line 50.

```php
public function product(Product $product): Product
{
    return Cache::remember($this->key('detail', [$product->id]), self::TTL_SECONDS,
        fn () => Product::query()->with(['category', 'suppliers'])->findOrFail($product->id));
}
```

Writes invalidate the catalog by changing its revision after a successful transaction commit. Existing entries expire naturally. Product, Category, and Supplier model events call this helper; the controller also invokes it when supplier links change.

Source: [app/Support/ProductCache.php](app/Support/ProductCache.php), line 17.

```php
public static function invalidateAfterCommit(): void
{
    if (DB::connection()->transactionLevel() > 0) {
        DB::afterCommit(fn () => self::invalidate());

        return;
    }

    self::invalidate();
}
```

Source: [app/Support/ProductCache.php](app/Support/ProductCache.php), line 28.

```php
public static function invalidate(): void
{
    Cache::forever(self::REVISION_KEY, (string) Str::uuid());
}
```

## 14. Rate limiting

The login endpoint has two limits: five requests per minute for an IP/email combination and twenty per minute per IP. Authenticated API traffic permits sixty requests per minute per user by default. Values are configurable in [config/inventory.php](config/inventory.php); Docker uses Redis for the shared limiter store.

Source: [app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php), line 14.

```php
public function boot(): void
{
    RateLimiter::for('login', function (Request $request) {
        $email = $request->input('email');
        $email = is_string($email) ? strtolower(trim($email)) : '';

        return [
            Limit::perMinute(config('inventory.login_ip_rate_limit'))->by('login-ip:'.$request->ip()),
            Limit::perMinute(config('inventory.login_rate_limit'))->by('login-email:'.$request->ip().':'.hash('sha256', $email)),
        ];
    });
    RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('inventory.api_rate_limit'))->by((string) ($request->user()?->id ?? $request->ip())));
}
```

The route middleware shown in section 2 applies these named limiters. Exceeded limits return HTTP 429 with `Retry-After`; the feature-test example in section 9 verifies that behavior.
