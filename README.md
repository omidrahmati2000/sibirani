# Apple Store API

Laravel backend for a digital-goods store. The API exposes a product catalog,
creates orders without overselling inventory, confirms payments through an
HMAC-signed webhook, and delivers purchased account details asynchronously.

The implementation is intentionally small, but the important production
concerns are represented explicitly: database-level concurrency control,
idempotency, authorization, payment verification, queue retries, rate
limiting, cache invalidation, and sensitive-data boundaries.

## At a glance

| Area | Implementation |
| --- | --- |
| Framework | Laravel 13 / PHP 8.3+ |
| Database | MySQL |
| Cache | Redis locally; array cache during tests |
| Queue | Laravel database queue |
| Authentication | Laravel Sanctum personal access tokens |
| Authorization | `OrderPolicy` plus owner/admin query scoping |
| Money | Whole Iranian rials stored as integer `BIGINT` values |
| Inventory concurrency | Pessimistic row locking with `lockForUpdate()` |
| API documentation | OpenAPI 3.0.3 and local Swagger UI |
| Test status | 42 tests, 239 assertions passing |

## Quick start

### Requirements

- Docker and Docker Compose
- PHP 8.3+ and Composer
- Node.js and npm

### Installation

```bash
git clone <repository-url>
cd sibirani

composer install
cp .env.example .env

./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed

npm install
npm run build
```

The provided environment is configured for Sail's MySQL and Redis services.
Before any real integration, replace the placeholder
`PAYMENT_WEBHOOK_SECRET` with a strong secret shared only with the payment
provider. Never commit `.env` or real credentials.

The application is available at `http://localhost:8080` with the default Sail
configuration.

### Start the delivery worker

The queue driver is `database`, so payment confirmation only creates a queued
delivery job. Run a worker to process it locally:

```bash
./vendor/bin/sail artisan queue:work
```

### Run the test suite

```bash
./vendor/bin/sail test
```

Tests use the isolated `testing` database configured in `phpunit.xml`; they do
not use the main `apple_store` database. The suite currently covers checkout,
real concurrent checkout, idempotency races, payments, delivery retries,
authorization, rate limiting, catalog caching, cache invalidation, schema
constraints, and local API documentation.

## Local API documentation

After building the frontend assets, open:

```text
http://localhost:8080/docs
```

This is a locally served Swagger UI backed by
[`docs/openapi.yaml`](docs/openapi.yaml). It supports **Try it out** requests.

For protected endpoints, use **Authorize** and enter:

```text
Bearer YOUR_SANCTUM_TOKEN
```

Checkout also requires an `Idempotency-Key` header. The payment webhook
requires an `X-Signature` header containing the lowercase SHA-256 HMAC of the
exact raw request body. Swagger documents the header, but calculating a real
signature still requires the configured webhook secret.

## Seeded data and manual testing

The database seeder creates these demo users:

| Email | Role |
| --- | --- |
| `admin@example.com` | admin |
| `customer@example.com` | customer |

It also creates:

| Product | Slug | Initial stock |
| --- | --- | ---: |
| Apple ID (US Region) | `apple-id-us` | 5 |
| Netflix Premium Subscription (1 Month) | `netflix-premium-1m` | 20 |
| Spotify Family Subscription (1 Month) | `spotify-family-1m` | 0 |

There is no registration or login endpoint because it was outside the task
scope. Generate a local Sanctum token with Tinker:

```bash
./vendor/bin/sail artisan tinker
```

```php
$user = App\Models\User::where('email', 'customer@example.com')->first();
$user->createToken('local-testing')->plainTextToken;
```

Use that token as `Authorization: Bearer <token>`.

## API surface

All API routes use the `/api` prefix.

| Method | Endpoint | Auth | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api/user` | Sanctum | Return the authenticated user |
| `GET` | `/api/products` | Public | List products and current stock |
| `GET` | `/api/orders` | Sanctum | List orders visible to the caller |
| `POST` | `/api/orders` | Sanctum | Create an order and reserve stock |
| `GET` | `/api/orders/{order}` | Sanctum | View an authorized order |
| `POST` | `/api/orders/{order}/cancel` | Admin | Cancel a pending order and restore stock |
| `POST` | `/api/orders/{order}/refund` | Admin | Refund a paid or delivered order |
| `POST` | `/api/webhooks/payment` | HMAC | Mark a pending order paid and enqueue delivery |

Example checkout request:

```bash
curl -X POST http://localhost:8080/api/orders \
  -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: checkout-apple-id-001" \
  -d '{"product_id":1,"quantity":1}'
```

Example response amounts are integers in Iranian rials:

```json
{
  "data": {
    "unit_price_rials": 1999,
    "total_rials": 1999,
    "status": "pending"
  }
}
```

## Core design decisions

### Integer rial amounts

Prices are stored as whole Iranian rials, never as floating-point values or
decimal currency values:

- `products.price_rials`
- `orders.unit_price_rials`
- `orders.total_rials`

The database columns are unsigned `BIGINT` values. This supports large integer
amounts such as one billion rials and prevents rounding errors. The order
stores a price snapshot, so later product-price changes do not rewrite
financial history.

### Pessimistic inventory locking

Checkout runs inside one database transaction:

1. Claim and lock the idempotency record.
2. Lock the selected product row with `lockForUpdate()`.
3. Check stock while holding that lock.
4. Decrement stock and create the order.
5. Store the final response for idempotent replay.
6. Commit all changes together.

Two concurrent buyers of the same product therefore serialize on the product
row. Only the transaction that observes available stock can decrement it. A
real forked-process concurrency test proves that stock never becomes negative
and orders never exceed available inventory.

Pessimistic locking is appropriate here because inventory is a scarce mutable
resource and a failed checkout must not oversell. Optimistic locking could be
considered for a much hotter or more distributed inventory design, but it
would require version columns, retry policy, and careful conflict handling.

### Idempotency

`POST /api/orders` requires `Idempotency-Key`. The database enforces a unique
`(user_id, key)` pair. The request payload is hashed and stored with the key.

- The same key and same request replay the original status and response body.
- The same key with a different product or quantity returns `409 Conflict`.
- Concurrent requests with the same key wait on the locked idempotency row.
- An insufficient-stock `422` response is also stored and replayed.

This prevents duplicate orders when a client retries after a timeout.

### Payment and asynchronous delivery

The webhook middleware computes HMAC-SHA256 over the exact raw request body and
compares it with `hash_equals()`. Missing or blank secrets fail closed.

Inside the payment transaction, the order is locked and only a pending order
can transition to paid. The delivery job is dispatched with `afterCommit`, so
the worker cannot process a payment that later rolls back.

`DeliverAccountJob` uses three total attempts with 2-second and 4-second
backoff. Successful delivery stores the payload and marks the order delivered;
exhausted attempts mark it failed.

### Authorization and sensitive data

- Customers can see only their own orders.
- Administrators can list and inspect orders but cannot receive delivery
  credentials.
- Delivery credentials are returned only to the owning customer, only for a
  delivered order, and only on the detail endpoint.
- Order deletion is not part of the API. Financial history is preserved.
- Cancelling a pending order restores stock; refunding a paid or delivered
  order changes financial status but does not recreate inventory.

### Catalog caching and invalidation

`GET /api/products` uses a five-minute cache in the normal Redis-backed
environment. The cache is invalidated after a successful transaction that
changes stock:

- checkout decrements stock;
- cancellation restores stock.

Invalidation is registered with `DB::afterCommit`, so a rolled-back
transaction does not publish an unnecessary cache change. Refund does not
invalidate the catalog because it does not change inventory.

### Rate limits and structured logs

| Limiter | Policy |
| --- | --- |
| Checkout | 10 requests/minute per authenticated user, or IP fallback |
| Payment webhook | 60 requests/minute per IP |
| Product catalog | 30 requests/minute per IP |

The structured log channel records lifecycle metadata such as IDs, status,
and retry attempt. It deliberately excludes delivery credentials, webhook
secrets, and full payment payloads.

## Database model

| Table | Important responsibilities |
| --- | --- |
| `users` | Sanctum owners and `customer`/`admin` roles |
| `products` | Product identity, integer rial price, and stock |
| `orders` | Immutable financial snapshot, lifecycle state, and delivery metadata |
| `idempotency_keys` | Request hash, replay response, and unique user/key claim |
| `personal_access_tokens` | Sanctum bearer tokens |
| `jobs` | Database-backed asynchronous delivery queue |

Orders use a restricted user foreign key so a user cannot be deleted while
their financial history still exists. The order list has a composite
`(user_id, created_at)` index for the primary customer-history query.

## Order lifecycle

| Current state | Action | Result |
| --- | --- | --- |
| `pending` | Valid payment webhook | `paid`, delivery job queued |
| `paid` | Successful delivery | `delivered` |
| `paid` / `delivered` | Delivery retries exhausted / terminal failure | `failed` where applicable |
| `pending` | Admin cancellation | `cancelled`, stock restored |
| `paid` / `delivered` | Admin refund | `refunded`, stock unchanged |

Invalid cancel/refund transitions return `409 Conflict` rather than silently
overwriting state.

## Testing strategy

The test suite is split into unit and feature tests:

- schema and model behavior;
- integer money and large-value totals;
- successful and insufficient-stock checkout;
- real concurrent checkout and concurrent idempotency requests;
- payment signature validation and duplicate webhook behavior;
- delivery success, retries, and terminal failure;
- owner/admin authorization and credential exposure boundaries;
- cancellation/refund lifecycle transitions;
- rate limiting and product cache invalidation;
- local Swagger UI and OpenAPI serving.

Run everything with:

```bash
./vendor/bin/sail test
```

Run one area while developing:

```bash
./vendor/bin/sail artisan test --filter=ConcurrentCheckoutTest
./vendor/bin/sail artisan test --filter=PaymentWebhookTest
```

Format changed PHP files with Laravel Pint:

```bash
./vendor/bin/pint
```

## Project structure

```text
app/
  Http/Controllers/       Thin API controllers
  Http/Requests/           Input validation
  Http/Resources/          Stable JSON response shapes
  Jobs/                    Asynchronous delivery
  Models/                  Eloquent models and query scopes
  Policies/                Authorization rules
  Services/                Checkout, lifecycle, delivery, and cache behavior
database/
  migrations/              Schema and constraints
  seeders/                 Demo users and products
docs/
  openapi.yaml             API contract
  ANSWERS.md               Assignment discussion answers
resources/
  js/swagger.js            Local Swagger UI entrypoint
routes/
  api.php                  API endpoints and middleware
  web.php                  Swagger UI and OpenAPI routes
tests/
  Feature/                 HTTP, database, queue, and concurrency behavior
  Unit/                    Focused model behavior
```

## Deliberate scope boundaries

This is a take-home implementation rather than a complete commerce platform.
The following are intentionally outside the current scope:

- registration, password login, and token revocation UI;
- a real payment-provider SDK and gateway-specific event schema;
- webhook timestamp/nonce replay protection and secret rotation;
- product administration and stock-management endpoints;
- encrypted/retention-managed delivery credentials;
- a Redis/SQS production queue migration;
- distributed inventory admission for extreme flash-sale traffic.

The design notes and next-step answers are available in
[`docs/ANSWERS.md`](docs/ANSWERS.md). The API contract is available in
[`docs/openapi.yaml`](docs/openapi.yaml).
