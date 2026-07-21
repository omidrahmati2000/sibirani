# Apple Store — Backend Take-Home

A Laravel API for a small digital-goods store: browse products, check out with
safe (no-oversell) stock decrement, pay via an HMAC-verified webhook, and get
the purchased account details delivered asynchronously. Built with
Sanctum-based auth and Policy-based authorization.

## Stack

- Laravel (PHP), MySQL, Redis, Laravel Sail (Docker)
- Queue driver: `database`
- Auth: Laravel Sanctum (personal access tokens)

## Setup

```bash
git clone <this-repo>
cd sibirani
composer install
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate   # only if APP_KEY is still empty
./vendor/bin/sail artisan migrate --seed
```

Sail generates `compose.yaml` in this repo (recent Sail versions no longer
use `docker-compose.yml`) — this doesn't change any of the commands above,
`./vendor/bin/sail up -d` still works exactly as documented.

`.env.example` already contains Sail-ready values (`DB_CONNECTION=mysql`,
`DB_HOST=mysql`, `DB_DATABASE=apple_store`, `REDIS_HOST=redis`,
`QUEUE_CONNECTION=database`) plus a placeholder
`PAYMENT_WEBHOOK_SECRET=change-me-to-a-real-shared-secret`. **That secret is
an obviously-fake placeholder — replace it with a real, random shared secret
before this is ever used against a live payment gateway.**

### Test environment

`.env.testing` is **not** committed (it's gitignored, like `.env`), so create
it yourself before running the suite:

```bash
cat > .env.testing <<'EOF'
APP_ENV=testing
APP_KEY=base64:GENERATE_YOUR_OWN_OR_COPY_FROM_.env
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=apple_store_testing
DB_USERNAME=sail
DB_PASSWORD=password
QUEUE_CONNECTION=database
CACHE_STORE=array
SESSION_DRIVER=array
PAYMENT_WEBHOOK_SECRET=test-secret-do-not-use-in-real-life
EOF
```

Then create the test database itself — the test suite will fail with "no
such database" otherwise:

```bash
./vendor/bin/sail mysql -e "CREATE DATABASE IF NOT EXISTS apple_store_testing"
```

### Running the tests

```bash
./vendor/bin/sail test
```

Current status: **20/20 tests passing** (Checkout, ConcurrentCheckout,
AccountDeliveryJob, Idempotency, PaymentWebhook, OrderAuthorization, plus
model/unit tests).

## Manual/local testing

`./vendor/bin/sail artisan migrate --seed` seeds:

| User | Email | Role |
|---|---|---|
| Admin | `admin@example.com` | admin |
| Customer | `customer@example.com` | customer |

| Product | Slug | Stock |
|---|---|---|
| Apple ID (US Region) | `apple-id-us` | 5 |
| Netflix Premium Subscription (1 Month) | `netflix-premium-1m` | 20 |
| Spotify Family Subscription (1 Month) | `spotify-family-1m` | 0 (demonstrates the insufficient-stock path) |

There is no registration/login endpoint (see "Out of scope" below). To get a
Sanctum bearer token for manual testing, use `php artisan tinker`:

```bash
./vendor/bin/sail artisan tinker
```
```php
$user = App\Models\User::where('email', 'customer@example.com')->first();
$user->createToken('test')->plainTextToken;
```

Use the resulting token as `Authorization: Bearer <token>` against
`POST /api/orders`, `GET /api/orders`, `GET /api/orders/{id}`,
`POST /api/orders/{id}/cancel`, `POST /api/orders/{id}/refund`.

Because `QUEUE_CONNECTION=database`, delivery jobs sit in the `jobs` table
until a worker picks them up — run one to actually see accounts delivered
outside of the test suite (tests run jobs synchronously via `Bus::fake`/queue
assertions, so they don't need this):

```bash
./vendor/bin/sail artisan queue:work
```

## Assumptions & deliberate design decisions

- **`Idempotency-Key` header is required, not optional.** A strict reading of
  "must support an Idempotency-Key header" could mean either "must be
  accepted if present" or "must be required." We chose to require it (422 if
  missing) on `POST /api/orders`, since making it optional would let clients
  silently skip the safety net it's meant to provide.
- **Retry count means 3 total attempts, not 3 retries after the first.**
  "Retry up to three times" was interpreted as the delivery job making at
  most 3 attempts in total (attempt, retry, retry), rather than 4 (initial +
  3 retries).
- **`DeliverAccountJob` manages its own retry/backoff** by re-dispatching
  itself with `->delay()` rather than relying on the queue worker's `$tries`
  mechanism. This was a deliberate choice for testability (attempts and
  backoff are directly assertable without depending on worker process
  timing), at the cost of being a slightly non-standard use of Laravel's
  queue system. A real worker (`queue:work`) must still be running for
  delivery to happen outside of tests, since the driver is `database`.
- **The idempotency-key claim commits independently of the checkout
  transaction.** `CheckoutService` writes the `IdempotencyKey` row (claim,
  then final response body) outside of — not nested inside — the
  product-lock transaction. This is deliberate: if checkout fails (e.g.
  insufficient stock) and the product-lock transaction rolls back, a naive
  nested-transaction design would roll back the stored 422 response along
  with it, defeating the whole point of idempotency (a retried request would
  re-run the failed checkout instead of replaying the cached response).
- **Webhook payload shape is a simplification.** `{ "order_id": ..., "reference": ... }`
  stands in for a real payment gateway's richer payload. The signature
  scheme is `hash_hmac('sha256', <raw request body>, PAYMENT_WEBHOOK_SECRET)`,
  sent by the caller in the `X-Signature` header and verified with
  `hash_equals` (timing-safe comparison) in `VerifyWebhookSignature`
  middleware; a missing/misconfigured secret fails closed (rejected), not
  open.
- **No user registration/login endpoint.** Not requested by the assignment,
  so it's out of scope; see "Manual/local testing" above for the tinker-based
  token workflow instead.

## What's implemented

- Product listing, checkout with pessimistic row locking (`lockForUpdate`)
  to prevent overselling under concurrent requests, proven with a real
  forked-process concurrency test (not just mocked).
- Idempotency-Key support on checkout (required header, 422 if absent,
  cached response replay on retry).
- HMAC-signed payment webhook (`POST /api/webhooks/payment`) with
  timing-safe verification and fail-closed behavior.
- Asynchronous account delivery via a queued job with self-managed
  retry/backoff and a `Failed` terminal state after exhausting attempts.
- Policy-based authorization for order actions (view/cancel/refund),
  admin vs. customer roles.
- Full automated test suite: 20/20 passing, covering checkout, concurrent
  checkout, idempotency, webhook validation, delivery job retries, and
  authorization.

## What's skipped (out of time budget)

Per the assignment's own stated priority order, these optional/lower-priority
items were **not** implemented:

- **Rate limiting** on checkout/webhook endpoints.
- **Redis caching** of the product listing endpoint.
- **Structured logging** (dedicated log channel + structured context for
  order/webhook/delivery lifecycle events).
- **OpenAPI/Postman documentation** of the API surface.

These are explicitly deprioritized by the assignment brief itself, and are
documented here rather than silently omitted.

## Written answers

See [`docs/ANSWERS.md`](docs/ANSWERS.md) for draft answers to the
assignment's two written questions (scaling to 100k orders/day and the
biggest security risk).
