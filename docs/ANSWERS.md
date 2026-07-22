# Written Answers

## 1. How would you scale this system to support 100,000 orders/day? What would become the first bottleneck?

100,000 orders per day averages about 1.2 orders per second, which a single
well-configured Laravel application and MySQL instance can handle. The design
should still be based on peak traffic rather than the average: promotions or
restocks can concentrate hundreds of requests on one product in a short time.

The first bottleneck in this implementation would be the pessimistic
`lockForUpdate()` on a popular product row. It is the correct correctness
mechanism because it prevents overselling, but it serializes buyers of the
same SKU through one database row. Adding more PHP workers does not remove
that contention; it only creates more sessions waiting for the same lock.

**I would use a threshold-based hybrid strategy for inventory locking. While
stock is comfortably above the risk zone, a transactional row lock is enough:
the inventory decision and decrement happen in one transaction, and the
predicate is protected by the row lock, so this path does not introduce a
phantom-read problem. Once stock reaches a configured threshold, I would
switch to optimistic locking (for example, a version column with a conditional
update and retry/fail-fast behavior). The threshold should be configurable
per product type and adjusted for product competition: scarce, highly
competitive products need to enter the optimistic-lock path earlier than
slow-moving products with ample stock.**

I would scale the system in stages:

- Keep the database transaction as the source of truth initially, but replace
  the read-then-decrement path with an atomic conditional update or an
  inventory-reservation table. For extremely hot SKUs, a Redis atomic counter
  can be used as a fast admission layer, with durable reservations and
  reconciliation in MySQL. Redis should not silently become the only source
  of inventory truth.
- Put the application behind a load balancer and scale PHP workers
  horizontally. Move delivery jobs from the database queue to Redis or SQS so
  queue traffic does not compete with checkout writes.
- Add read replicas and appropriate indexes for order history, product
  lookup, idempotency keys, and webhook processing. Writes and inventory
  decisions must continue to use the primary database.
- Add per-user/IP rate limits and backpressure for checkout and webhooks.
  Monitor database lock wait time, queue age, checkout latency, and failed
  deliveries so capacity decisions are based on actual traffic.
- Partition or archive old orders only when the orders table itself becomes a
  measurable storage or index bottleneck. At 100,000 orders/day, a properly
  indexed single table may still be sufficient.

The important trade-off is that the mechanism guaranteeing inventory
correctness is also the first hot-path bottleneck. I would optimize that path
only after measuring contention, and would preserve a durable, auditable
inventory record while doing so.

## 2. What do you consider the biggest security risk in this system, and how would you mitigate it?

The highest-value attack surface is the payment webhook. It is intentionally
unauthenticated with a user token, but a successful request changes payment
state and starts delivery. If an attacker can forge it, they can obtain goods
without paying. Delivered credentials are also sensitive data, so a database
leak or accidental logging of `delivery_payload` would be a second serious
risk.

The implemented webhook protection uses an HMAC over the exact raw request
body, a server-side secret, and `hash_equals()` for timing-safe comparison.
Invalid signatures return `401`, while a missing or blank server secret fails
closed as a deployment error. The order is locked during state transition,
duplicate payment notifications are no-ops, and delivery is dispatched only
after the payment transaction commits.

Before using a real gateway, I would add the following controls:

- Require a signed timestamp and event ID, reject stale timestamps, and store
  consumed event IDs to prevent replay of a valid webhook.
- Support secret rotation with a short overlap window, store secrets in a
  secret manager, and never place them in logs or source control.
- Rate-limit the webhook and optionally restrict it at the network layer to
  the gateway's published IP ranges. Keep HMAC verification as the primary
  control because IP ranges can change or be misconfigured.
- Validate the event type, order state, amount, currency, and gateway event
  identity in a production payload. The take-home payload intentionally only
  contains `order_id` and `reference`.
- Keep account credentials out of logs and return them only to the owning
  customer after delivery. In production I would encrypt the delivery payload,
  minimize its retention period, and audit access to it.

The goal is defense in depth: a leaked endpoint URL, replayed network packet,
misconfigured environment variable, or compromised admin token should not by
itself enable free fulfillment or broad credential disclosure.
