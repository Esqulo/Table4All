# Performance and resilience under rush

> **Mandatory requirement.** Table4All is used during a venue's rush hour, with
> a counter full of customers waiting. The system must never be the
> bottleneck. Every change has to keep working, and stay fast, under the
> worst conditions below. A feature that is correct but slow during a rush
> is a bug.

## Conditions we design for

| Condition | What it means in practice |
|---|---|
| **Rush load** | Dozens of customers ordering by QR at once, plus every waiter and kitchen screen refreshing. Bursts arrive within seconds of each other. |
| **Slow or flaky networks** | Customers use their own mobile data (3G / weak 4G). Venue Wi-Fi drops. Requests time out and get retried. |
| **Old hardware** | Kitchen and counter screens are often old Android tablets or low-end PCs; customers have entry-level phones. CPU, memory and battery are scarce. |
| **Concurrency between people** | Two waiters on the same table, the kitchen marking an item ready while a waiter edits the order, a customer double-tapping "order". |
| **Plain HTTP on the LAN** | Some venues reach the app without HTTPS, so secure-context-only browser APIs (`crypto.randomUUID`, service workers) may be missing. |

## Hot paths

These screens are used continuously during service. Treat changes to them as
performance-sensitive:

| Screen | Route | Who | Refresh |
|---|---|---|---|
| Kitchen/bar queue | `restaurant.queues.show` | Kitchen | every 8 s |
| Delivery board | `restaurant.delivery.index` | Waiters | every 10 s |
| Table (order + payments) | `restaurant.tables.show` | Waiters | every 15 s while there are no unsaved edits |
| Tables board | `restaurant.tables.index` | Waiters, owner | every 20 s |
| Customer table | `customer.table` / `customer.order` | Customers (mobile data) | every 20 s while the menu is closed |
| Table chat | `customer.table` (`chat` prop) | Customers (mobile data) | every 3 s while the table is chatting, 12 s after a quiet minute |
| Public menu / join | `table.show`, `menu.show` | Customers | - |

## Budgets

Targets for the hot paths, measured on production hardware with a realistic
venue (around 40 open tables and 150 products):

- **Server time:** p95 under 150 ms per request. No request on a hot path should run more than ~10 queries, and the count must not grow with the number of rows (no N+1).
- **Partial reloads:** a polling refresh returns only the props it needs, gzipped. Aim for under 20 KB.
- **Images:** product pictures are at most 800 px on the longest side, WebP, typically 30-80 KB.
- **Interaction:** taps respond immediately (button disabled, progress bar) even when the network takes seconds.

## Rules for contributors

### Database
1. **Index every column you filter or join on.** PostgreSQL does *not* index foreign keys automatically. A new `where('x_id', …)` on a hot path needs a matching index in a migration.
2. **Select only the columns you send.** Use `get([...])` / `with('rel:id,name')`.
3. **Aggregate in SQL**, not in PHP or the browser: `withSum`, `withCount`, `selectSub`. The tables board gets `order_total` and `paid_total` from the database instead of shipping every line and payment.
4. **No N+1.** Eager-load, and cap eager loads that feed previews (`->limit(5)` inside `with`).
5. **Totals always use the price stored at order time** (`restaurant_table_product.price`, `queue_items.price`), never `products.price`.

### Writes must be safe to retry and to race
6. **Status transitions are conditional updates:** `UPDATE … WHERE id = ? AND status = 'pending'`, then act only if a row changed. Never "read the status, check it in PHP, then update". See `QueueItemController`.
7. **Anything that changes a table's order runs in a transaction that locks the table row first** (`RestaurantTable::whereKey($id)->lockForUpdate()`). That serializes deliveries, order saves and table closing on the same table.
8. **Creating writes (orders, payments) carry a `request_key`.** The client generates one per logical action (`newRequestKey()` in `resources/js/lib/request-key.ts`) and the server runs the action through `App\Support\Idempotency::once()`, so a re-sent request is a no-op. Claim the key *after* validation.
9. **Saves of a whole screen need optimistic locking and must apply only the difference.** `TableController::update` takes the `orderVersion` the screen was rendered with. If the order changed meanwhile, the server rejects the save instead of overwriting deliveries it never saw, and only touches lines whose quantity changed.
10. **Insert in bulk** (`Model::insert($rows)` with explicit timestamps) instead of `create()` in a loop.

### Frontend
11. **Live screens use `useLiveReload`** (`resources/js/hooks/use-live-reload.ts`), never a raw `setInterval`. It requests only the listed props, waits for one refresh to finish before scheduling the next (so slow links don't pile up requests), pauses when the tab is hidden or the device is offline, and refreshes right away when it comes back. Disable it while the user has unsaved input.
12. **Images in lists:** `loading="lazy" decoding="async"`. Upload through `App\Support\ImageOptimizer::storeUpload()`, never `->store()` directly.
13. **Don't build expensive objects per row or per render.** Currency goes through `formatBRL()` (`resources/js/lib/money.ts`), which caches the `Intl.NumberFormat`.
14. **Disable submit buttons while a request is in flight** (`processing` from `<Form>`, or a local `submitting` flag).
15. **Watch the bundle.** Pages are code-split by Inertia. Keep heavy dependencies out of the layouts and out of customer pages.

### Server and infrastructure
16. nginx gzips text responses and serves `/build/*` (hashed) with a one-year immutable cache and `/storage/*` (random names) with a 30-day cache.
17. PHP-FPM keeps warm workers (`pm.min_spare_servers`), recycles them (`pm.max_requests`) and kills stuck requests (`request_terminate_timeout = 60s`). `docker/php/www.conf` is sized for a small VPS (2 vCPU, 2-4 GB shared with Postgres: 8 workers, 2 warm). On a bigger host raise `pm.max_children` to about `(RAM for PHP) / 50 MB`.
    - Background work never competes with the live screens: the queue worker runs under `nice -n 19` (ffmpeg and every other job inherit it) and ffmpeg decodes and encodes on one thread.
    - Logs can't fill the disk: Docker keeps at most 3 x 10 MB per container (`x-logging` in `docker-compose.yml`), and nginx doesn't log polling requests (those carrying `X-Inertia-Partial-Component`).
    - **Polling is the traffic.** Every open screen polls, so its interval is the main lever on server load. The table chat is on every customer's phone, so it is quick (3 s) only while someone is chatting and slows down to 12 s after a quiet minute. A new poll on a screen that many people keep open should do the same.
18. OPcache with `validate_timestamps = 0`, a large realpath cache, and `php artisan optimize` on boot (in `docker/entrypoint.sh`).
19. **"Hoje tem!" stories (pictures and videos, gone after 24 hours).** Uploads are untrusted and never served as they came:
    - The type is read from the content (fileinfo), then pictures are re-encoded by GD (`ImageOptimizer::storeReencoded()`, which refuses what it can't decode instead of keeping the original), dropping EXIF/GPS. The browser crops them to 9:16 first, so little is sent.
    - Videos (at most 30 s and 50 MB, checked in the browser before uploading and again on the server) wait on the **private** disk until `App\Jobs\ProcessStoryVideo` converts them on the queue with ffmpeg: 720p H.264/AAC, metadata stripped, `+faststart` so playback starts before the download ends. ffmpeg only reads local files, only MP4/MOV/WebM with camera codecs, with timeouts (`App\Support\Video\FfmpegVideoTranscoder`).
    - `stories:prune` (scheduled every 5 minutes) deletes expired stories with their files, gives up on conversions stuck for an hour, and sweeps any story file older than 25 h, so nothing accumulates. Expired stories are hidden by the query right away, before the cleanup runs.
    - The feed's strip is 3 queries whatever the number of stories, and only the owner's strip polls (`useLiveReload`) while one of its videos converts.

## How to test under adverse conditions

- **Network:** Chrome DevTools → Network → *Slow 4G* (or a custom 400 kbps / 400 ms profile). Place an order, deliver an item and close a table. Every action must give immediate feedback and must never duplicate on a double tap.
- **CPU:** DevTools → Performance → *CPU: 4x slowdown* (or 6x) on the kitchen and tables screens with 40+ items.
- **Concurrency:** open the same table in two browsers, mark an item delivered in one and save the order in the other. The stale save must be rejected with a toast and the delivery kept.
- **Queries:** `EXPLAIN` new hot-path queries against PostgreSQL. Look for `Index Scan`, not `Seq Scan` on large tables.
- **Automated:** `php artisan test --filter=RushResilienceTest` covers idempotent orders and payments, double deliveries, stale-save rejection and snapshot totals.

## Audit log

### 2026-11-15: initial audit

| # | Problem | Impact during a rush | Fix |
|---|---|---|---|
| 1 | No indexes on foreign keys or `access_code` (PostgreSQL doesn't create them) | Sequential scans on every screen; gets slower as history grows | Migration `2026_11_15_100000_add_performance_indexes` |
| 2 | `markDelivered` checked status in PHP, then updated | Double tap or two waiters billed the item **twice** | Conditional update + transaction with table lock |
| 3 | Order save deleted and re-inserted lines without a transaction or version check | A delivery marked while a waiter edited was **wiped from the bill**; a failed insert left the table empty | Transaction, table lock, `orderVersion` optimistic locking |
| 4 | Customer order: no idempotency, one INSERT per item | Retries on bad connections **duplicated orders** in the kitchen | `request_key` + single bulk insert |
| 5 | Payment: no idempotency | Retried submit recorded the payment twice | `request_key` |
| 6 | Closing a table compared totals without a lock | A delivery landing at the same moment could be left unpaid | Transaction with table lock |
| 7 | Tables board shipped every line and payment of every open table and summed them in the browser using the **current** product price | Heavy payload; wrong totals after a price change or promotion | `order_total` / `paid_total` computed in SQL from snapshot prices; thumbnails capped at 5 |
| 8 | Kitchen screen had no auto-refresh; delivery board used a fixed 30 s `setInterval` | Kitchen missed new orders; slow links stacked overlapping reloads | `useLiveReload` on kitchen, delivery, tables, table and customer screens |
| 9 | Product photos stored as uploaded (up to 2 MB each) | Customer menu downloaded megabytes over mobile data | `ImageOptimizer` (800 px WebP), `products:optimize-images` for existing files, lazy images |
| 10 | nginx without gzip | Inertia JSON and JS sent uncompressed | gzip; long-term cache for hashed assets and uploads |
| 11 | FPM started with 2 workers (max 10), 300 s timeouts | Cold workers at the start of a rush; a stuck request held a worker for 5 minutes | Warm pool, `max_requests`, 60 s limits, realpath cache |
| 12 | `new Intl.NumberFormat` on every price render | Noticeable jank on old devices with long lists | Cached `formatBRL()` |
| 13 | Active-promotion query duplicated in two controllers | Drift risk in the pricing logic | `Sale::scopeActiveFor` / `Sale::activePriceMap` |

### 2026-11-15: billing fixes found during the audit

| # | Problem | Impact | Fix |
|---|---|---|---|
| 14 | Unique key `(table, product, ordered_by_user_id)` made a later delivery of the same product add to the existing line at **that line's price** | Promotion items billed at full price, or the reverse | Price added to the key (`2026_11_15_110000_…`); `markDelivered` matches on price |
| 15 | The table screen overwrote rows sharing a product and price (one per customer) instead of summing them | The waiter saw fewer units, and saving **deleted the rest from the bill** | `orderLines` grouped by (product, price) in SQL; client sums defensively |
| 16 | Waiter save was a full delete + re-insert | Dropped who ordered each line | `syncOrder` applies only the difference: unchanged lines keep their customer, added units go unattributed, removals take unattributed units first |

### Deploy notes for this audit
- Rebuild the images (`docker compose build`): the GD and EXIF extensions were added. Without GD, uploads still work but are stored unoptimized.
- Migrations run on boot. The index migration is additive and quick on current data volumes.
- After deploying, run `php artisan products:optimize-images` once to shrink existing pictures.

### Deploy notes for "Hoje tem!" (2026-11-19)
- Rebuild the images (`docker compose build`): ffmpeg was added, and the upload limit went from 20 MB to 60 MB (nginx and PHP). Without ffmpeg the app keeps working, with pictures only: the strip doesn't offer videos.
- The queue worker converts the videos and the scheduler runs `stories:prune`; both already run in the production container. The dev container now runs the scheduler too.

### 2026-11-20: online payments (Stripe Connect)
- Customers pay on Stripe's **hosted** checkout: no Stripe JS on the customer page, and the app makes no Stripe call on any polling request. The customer page adds two small queries (`payments`, `myCheckout`) to its 20 s refresh; the coupon preview (`couponQuote`) is an optional prop, computed only when the form asks for it.
- Starting a checkout locks the `restaurant_tables` row while it checks the balance and the coupons in use, then **holds** the amount (an `online_payments` row) before calling Stripe outside the lock. Two customers can't pay the same balance or stack coupons that don't combine.
- A payment is confirmed by whichever arrives first (the return URL, the webhook, or `payments:reconcile` every minute) and is recorded once: the `online_payments` row is locked and only a non-paid row is turned into `table_payments`. `request_key` makes a double-tapped "Pay" reopen the same checkout.
- Transfers the platform owes a restaurant (a platform coupon, Stripe's fee for sponsored restaurants) use a Stripe idempotency key per payment, so retrying them is safe.

### 2026-11-20: moving items and merging tables
- A write that touches **two** tables (`App\Support\TableTransfer`: moving items when a customer changes tables, merging two tables) locks **both** `restaurant_tables` rows in one transaction, **lowest id first**. Two transfers between the same tables in opposite directions then wait for each other instead of deadlocking, and deliveries, order saves, payments and closing (which lock the same rows) never see half a move. Any future two-table write must lock in the same order.
- Everything is checked under the lock before anything is written, so a move is all or nothing: if the units or items asked for are gone (delivered, edited or moved meanwhile), nothing moves and the screen is told.
- `restaurant_table_product` has no id column and its unique key doesn't match `NULL` customers, so the moved rows are addressed by `ctid` (stable while the table row is locked) and a destination line grows on one row only.
- Moving items carries a `request_key` (a resent request would move more units). A resent merge finds the old table already gone (soft-deleted) and just follows to the merged one.
- A merge doesn't carry the chat over: both tables' messages and reactions are deleted, so the merged group starts a fresh conversation.
- A merge waits while the table being merged has an online payment in flight, so Stripe's confirmation is never credited to a table that no longer exists.

### 2026-11-20: cost audit for a small VPS
Measured with 40 open tables and 150 products: every polling request runs 2-14 queries (3 ms or less each; the tables board's totals 15 ms) and returns 0.2-3 KB gzipped, so the database is not where the cost is. A busy small venue (8 tables, 16 phones, 6 staff screens) makes about 400 requests/min, ~80% of them the table chat polling every 3 s.

| # | Problem | Impact | Fix |
|---|---|---|---|
| 17 | Chat polled every 3 s on every open phone, even with nobody chatting | Most of the server's requests | Quick only for a minute after activity, then 12 s (about a third of the requests) |
| 18 | nginx logged every poll; Docker logs had no size limit | Disk filling up on a small VPS | Polls not logged; 3 x 10 MB per container |
| 19 | FPM sized for a large host (16 workers, 4 always idle) | 300-500 MB of RAM held for nothing | 8 workers, 2 warm |
| 20 | ffmpeg on 2 threads at normal priority | A video upload slowed every screen on 1-2 vCPU | Queue worker at `nice 19`, ffmpeg on 1 thread |

### 2026-11-21: scale test (10 venues, 400 open tables, large history)
History: 50k closed tables, 400k bill lines, 250k chat messages, 200k queue items, 20k customers (286 MB of database). Every hot path stayed at 5-25 ms of database time whatever the history, so history size is not the risk. Load: k6 against the production configuration (OPcache, cached config), app and Postgres sharing the same CPUs, one phone per table (30% of tables chatting), occasional orders and messages, and each venue's staff screens.

| CPUs | Throughput | Poll latency (median / p95) | Errors |
|---|---|---|---|
| 2 vCPU | ~47 req/s, saturated | 5.4 s / 8.1 s | 0 |
| 4 vCPU | ~124 req/s, at the limit | 0.65 s / 1.7 s | 0 |

The scenario asks for ~125 req/s, about three quarters of it the table chat. The CPU goes to PHP booting Laravel for each short poll (~30 ms of CPU each); Postgres used a quarter of what PHP did. Rule of thumb: **about 25-30 polling requests per second per vCPU**.

| # | Problem | Impact | Fix |
|---|---|---|---|
| 21 | A Postgres connection per request: 25-30 ms (backend fork + SCRAM) | More than the queries of a poll | `DB_PERSISTENT=true` (persistent PDO per FPM worker): chat poll 117 ms to 51 ms unloaded |
| 22 | The built app preloads ~35 chunks; the `Link` header (4.5 KB) overflowed nginx's 4 KB FastCGI buffer | **Every full page load was a 502 in production** (dev, served by Vite, has no preload header) | `AddLinkHeadersForPreloadedAssets::using(10)` and bigger `fastcgi_buffer_size` |
| 23 | Customer spend (rewards) scanned every restaurant's bill lines (no index on `ordered_by_user_id`) | 50 ms, growing with the platform | `rtp_buyer_idx`: 0.3 ms |
| 24 | The 90-day dashboard scanned every restaurant's bill lines | 0.6-1.3 s | Covering index `rtp_table_cover_idx` + `random_page_cost=1.1`: 220 ms (the rest is the real aggregation) |

### Known gaps / next steps
- **Per-request boot cost is the ceiling.** See the scale test above. Beyond what one VPS size gives, the levers are, in order: push instead of polling for the chat (Laravel Reverb), since it is most of the traffic; Laravel Octane (FrankenPHP), which keeps the app booted between requests; then more vCPUs.
- **Polling vs push.** Polling is simple and robust on bad networks, but with many screens open it costs one request per screen every few seconds. If load grows, move the kitchen and delivery boards to WebSockets (Laravel Reverb) and keep polling as the fallback.
- **Sessions and cache on the database.** Every request writes the session row. Fine for one venue; for multi-venue hosting, move sessions and cache to Redis.
- **Offline ordering.** A service worker with a retry queue would let waiters keep working through short Wi-Fi drops (it needs HTTPS).
- **Measure the bundle** (`npm run build`) and set a size budget in CI for the customer pages.
