# Written Answers

*Note: these are a starting draft written to be grounded in what was actually
built in this repo. The candidate should personalize the wording and be ready
to defend the reasoning in their own words in interview, rather than reciting
this text verbatim.*

## 1. How would you scale this system to support 100,000 orders/day? What would become the first bottleneck?

100,000 orders/day averages out to roughly 1.15 orders/second, which sounds
trivial for a single MySQL instance and a couple of PHP-FPM workers. But
average throughput is the wrong number to design for — order traffic for a
store like this is not uniform across the day, it's concentrated around
drops, promotions, and restocks of specific popular SKUs. A realistic planning
assumption is that a meaningful fraction of that daily volume — say 10-20% —
can land in a five-to-ten-minute window when a popular product goes on sale,
which pushes the *effective* peak rate from ~1 req/s to something like
50-200 req/s, and if many of those requests target the *same* product row,
that concentration is exactly where this implementation will hurt first.

**First bottleneck: the single-row `lockForUpdate()` on the `products` table
during checkout.** `CheckoutService::checkout()` acquires a pessimistic lock
on the specific product row being purchased before decrementing stock. That's
the correct choice for correctness — it's what makes the oversell test pass
even under real concurrent forked processes — but it means every buyer of the
*same* SKU is serialized through that one row's lock, one transaction at a
time, for the duration of the checkout transaction (order creation,
idempotency bookkeeping, stock decrement). Under a flash-sale spike on one
popular product, throughput for that specific SKU is bounded by how fast
MySQL can commit-and-release that single row lock, not by how many PHP-FPM
workers or database connections are available. Adding more application
servers does nothing for this bottleneck — it only means more requests are
now *waiting* on the same lock instead of fewer.

**How I'd scale it:**

- **Move hot-inventory decrement off MySQL row locks entirely.** For the
  popular-SKU/flash-sale case specifically, use a Redis atomic counter
  (`DECR`/`DECRBY` with a floor check via Lua script for atomicity) as the
  authoritative "is there stock right now" gate, and treat MySQL as the
  system of record that gets reconciled asynchronously (write-behind) rather
  than being on the hot path of every single checkout request. This turns a
  disk-backed row lock into an in-memory atomic operation, which is orders of
  magnitude faster and doesn't serialize on transaction commit latency.
- **Read replicas** for all `GET` endpoints (product listing, order history)
  so read traffic never contends with the write path at all.
- **Horizontal scaling of PHP-FPM and queue workers** behind a load balancer
  — straightforward once the database isn't the serialization point.
- **Move the queue off `database` and onto Redis or SQS.** The `database`
  queue driver works fine for a take-home's test/demo volume, but it competes
  for the same MySQL connections and rows as the checkout path itself; a
  dedicated queue backend removes that coupling and scales delivery-job
  throughput independently of order throughput.
- **Partition/shard orders by date** (or by a hash of customer/order id) once
  the `orders` table's write volume alone becomes the limiting factor — not
  needed at 100k/day on a single well-indexed table, but worth planning for
  if this system is meant to grow well past that.
- **Rate limit checkout per user/IP** so a single client (or a bot during a
  drop) can't multiply the contention on the hot row disproportionately to
  their fair share of stock.

In short: the architecture is correct for preventing oversell today, but the
mechanism that guarantees correctness (a per-row database lock) is also the
first thing that will cap throughput under a concentrated spike, and the fix
is to move the hot decrement path to something faster than a row lock while
keeping MySQL as the reconciled source of truth.

## 2. What do you consider the biggest security risk in this system, and how would you mitigate it?

**Biggest risk: the payment webhook (`POST /api/webhooks/payment`) is the
highest-value forgeable endpoint in this system.** Nothing else in the API
can directly mark an order as paid and trigger delivery of a purchased
account. If an attacker could call this endpoint successfully without being
the real payment gateway, they could get free deliveries for any order —
without paying — which is a direct financial loss, not just a data-exposure
risk. Any endpoint that changes money/fulfillment state based on an
unauthenticated (from the user's perspective) callback deserves the most
scrutiny in a store like this.

**Mitigations already implemented (Task 6):**

- **HMAC signature verification.** The webhook payload is signed with
  `hash_hmac('sha256', <raw request body>, PAYMENT_WEBHOOK_SECRET)` and sent
  in the `X-Signature` header. `VerifyWebhookSignature` middleware
  recomputes the HMAC server-side over the raw body and compares it to the
  supplied header.
- **Timing-safe comparison (`hash_equals`).** A naive `===` string
  comparison on a computed vs. supplied signature leaks information via
  timing side-channels (it short-circuits on the first mismatched byte),
  letting an attacker who can measure response latency brute-force the
  signature byte-by-byte. `hash_equals` runs in constant time regardless of
  where the strings differ.
- **Fail-closed on a missing/misconfigured secret.** If
  `PAYMENT_WEBHOOK_SECRET` isn't configured, the middleware rejects the
  request (500, a distinct config-error status from the 401 used for a
  genuine signature mismatch) rather than falling back to "no verification"
  — a common real-world bug where an unset env var silently disables auth.
  This is deliberately fail-closed rather than fail-open.
- **Idempotent, order-state-aware processing.** The webhook handler
  re-validates the order's current state before applying the payment, so a
  legitimately-signed but duplicated/replayed webhook call (e.g. the gateway
  retrying due to a timeout) doesn't double-process a payment or re-trigger
  delivery for an order that's already paid.

**What's still missing, and how I'd close the gap further:**

- **Replay protection (nonce/timestamp).** HMAC verification proves the
  payload wasn't tampered with and came from someone holding the shared
  secret, but it does *not* prove the request is fresh — a captured valid
  request could be replayed verbatim. The order-state check above blunts
  the *impact* of a replay (an already-paid order won't be re-processed),
  but a more complete fix is to include a timestamp and/or nonce in the
  signed payload, reject requests outside a short tolerance window (e.g. 5
  minutes), and track consumed nonces to reject exact repeats within that
  window.
- **IP allowlisting.** Restricting the webhook route to the payment
  gateway's published IP ranges at the infrastructure or middleware level
  adds a second, independent barrier — even a leaked secret wouldn't help an
  attacker who isn't calling from an allowlisted address.
- **Secret rotation.** `PAYMENT_WEBHOOK_SECRET` is currently a single
  long-lived value. Supporting rotation (accepting either a current or a
  short-lived previous secret during a rollover window) limits the blast
  radius and lifetime of a leaked secret.
- **Rate limiting the webhook endpoint** to blunt brute-force signature
  guessing attempts and general abuse, independent of the timing-safe
  comparison already in place.

The combination already implemented (HMAC + timing-safe comparison +
fail-closed + idempotent state checks) covers the most damaging failure mode
(a forged or tampered payload marking an order paid), but replay protection,
IP allowlisting, and secret rotation would be the next layer I'd add before
trusting this with real payment volume.
