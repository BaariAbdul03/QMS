# Smart QMS

A queue management system for a clinic-style waiting room. Visitors take a
digital token instead of standing in line, see their live position and estimated
wait, a public board announces who is being served, and staff call, serve and
complete visitors from a panel.

No framework, no Composer, no build step. PHP + PDO + MySQL, with a hand-rolled
router, a dependency-free test runner and Bootstrap vendored locally.

---

## The three screens

| Screen | URL | What it does |
|---|---|---|
| Visitor | `/` | Pick a service, take a token, watch position + ETA, cancel. The ticket survives a refresh (`localStorage`) and a refresh keeps polling. |
| Display board | `/display` | Full-screen dark board: one large tile per counter with NOW SERVING, waiting counts and per-service averages. Announces new tokens with a chime and a spoken call. |
| Staff panel | `/staff` | Every counter with Call Next, the current token in large text, and Start / Complete / No-Show / Recall enabled only when the state machine allows them. Polls every 3 s. |

All three are the same front controller plus JSON polling — no WebSockets, no
server-rendered state to keep in sync.

---

## Architecture

```
   visitor page      display board      staff panel
        │                 │                 │  fetch() JSON, poll every 3 s
        └────────────┬────┴─────────────────┘
                     ▼
        public/index.php   (front controller + router + error boundary)
                     ▼
   Controllers ──▶ Models ──▶ PDO ──▶ MySQL / MariaDB
                  │  Token         race-free issuing, guarded transitions
                  │  QueueStats    the self-learning ETA
                  │  Service, Counter
                  └── Core: Env, Config, Database, Router, Request, Response,
                           Auth, Csrf, Throttle, Session, View, Clock
```

* `App\` classes are autoloaded PSR-4 style by a `spl_autoload_register` in
  `app/bootstrap.php`.
* Every response is `{"ok":true,"data":...}` or `{"ok":false,"error":...}` with
  a real HTTP status code (400/401/403/404/409/429/500).
* Views are plain PHP with `htmlspecialchars` escaping; the JavaScript renders
  with `textContent`, never `innerHTML`.

### Layout

```
public/            front controller + assets (this is the document root)
app/Core/          infrastructure
app/Controllers/   Page, Token, Staff, Status, Auth, Health
app/Models/        Token, QueueStats, Service, Counter
app/Views/         visitor, display, staff, login, layout
config/            config.php (reads .env), routes.php
database/          schema.sql, seed.php, reset.php
tests/             6 suites + support/ fixtures and process pool
bin/               php.sh, dev-server.sh
storage/           runtime files (rate-limit windows, logs)
```

---

## Four things worth reading the code for

### 1. Race-free token numbers

`SELECT MAX(number) ... FOR UPDATE` looks correct and is not: on an empty day
InnoDB takes a **gap lock**, two concurrent requests take the same gap, and both
then try to insert — deadlock (error 1213).

Instead, `tokens` allocation goes through a one-row-per-service-per-day counter:

```sql
INSERT INTO queue_counters (service_id, queue_date, last_number)
VALUES (?, ?, LAST_INSERT_ID(1))
ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)
```

One row lock held until commit, no gap, no deadlock. Numbers come out gap-free;
the `UNIQUE (service_id, queue_date, number)` key stays as a safety net, and the
transaction retries up to 3 times on 1213/1205 with jittered backoff.
See `Token::issue()` in [app/Models/Token.php](app/Models/Token.php).

### 2. Every transition is one guarded UPDATE

```
waiting ──call──▶ called ──start──▶ serving ──complete──▶ completed
   │                │  ▲
   │                │  └──── recall (re-announce, stays called)
   │                └──no_show──▶ no_show
   └──cancel──▶ cancelled
```

```sql
UPDATE tokens SET status='serving', serving_at=NOW()
 WHERE id = ? AND status = 'called' AND counter_id = ?;
-- rowCount() = 0  →  illegal transition  →  HTTP 409
```

No read-then-write, no application-level lock, no way for two tabs to both
"complete" the same visitor. The 409 body names the current status, so the panel
can say *"A-024 is already completed by another desk"* instead of showing a
stack trace.

### 3. Two counters cannot take the same visitor

`callNext()` locks the counter row (a desk holds at most one visitor), then takes
the oldest waiting token with `FOR UPDATE SKIP LOCKED`.

The trap: `ORDER BY taken_at` looks right and starves queues. `taken_at` is not
in `idx_queue`, so InnoDB **filesorts**, and a locking read must lock every row
it reads — the first desk locks the whole queue, the second skips all of it and
wrongly reports "queue empty". The query walks the index instead
(`ORDER BY number`), so it locks one row and stops. `EXPLAIN` shows
`Using index`, no `Using filesort`.

### 4. The ETA learns, and admits how well it knows

```
samples = last 10 completed tokens, duration = serving_at → completed_at
          (drop < 10 s = mis-click, > 60 min = forgotten "Complete")
avg     = mean(samples);  < 3 samples → blended with services.avg_service_minutes
open    = open counters that can serve this service (min 1)
ahead   = waiting tokens of this service with a smaller number
eta     = ceil((ahead × avg + 0.5 × avg if a desk is mid-service) ÷ open)
shown   = 75%–125% of eta     e.g. "About 6–12 min"
```

Measured from `serving_at`, not `called_at`, so the walk to the desk is not
counted as service time. A range, because a point estimate would be a lie about
how well this is known. `QueueStats::etaFor()` in
[app/Models/QueueStats.php](app/Models/QueueStats.php), tested by
[tests/eta_test.php](tests/eta_test.php).

---

## Getting it running

**Requirements**

* PHP **8.2+** (developed and verified on 8.2.12; target 8.4/8.5 for new
  installs — 8.2 is security-only until 31 Dec 2026)
* MySQL **8.0.1+** or MariaDB **10.6+** (`SELECT ... FOR UPDATE SKIP LOCKED`)
  — verified on MySQL 8.0.46. MySQL 8.0 reaches EOL April 2026, so prefer
  MySQL 8.4 LTS / MariaDB 11.8 LTS for anything new.
* `pdo_mysql`, `mbstring`

**Setup**

```bash
mysql -u root -p -e "CREATE DATABASE qms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p qms < database/schema.sql

cp .env.example .env                # then edit credentials
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
                                    # paste into STAFF_PASSWORD_HASH

php database/seed.php --fresh       # demo history + a live queue
```

**Run it**

```bash
bash bin/dev-server.sh start        # http://127.0.0.1:8080   (Apache)
bash bin/dev-server.sh status
bash bin/dev-server.sh stop
```

`bin/dev-server.sh` copies the XAMPP `httpd.conf` into `.dev/` and points it at
`public/`; the stock config is never modified. It exists because `php -S` handles
**one request at a time**, and on Windows `PHP_CLI_SERVER_WORKERS` is not
supported — so a concurrency test against the built-in server would serialise and
prove nothing. On Linux/macOS, `PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t public`
is fine for manual browsing.

`bin/php.sh` wraps `php` with a project-local ini that enables `pdo_mysql`, for
machines whose default build does not. On XAMPP/plain `php`, plain `php` works.

If `public/` is not the document root, set `APP_BASE_PATH=/qms` in `.env`.

**Demo data**

```bash
php database/reset.php          # empty the queue, keep services/counters
php database/reset.php --all    # also drop services/counters
php database/seed.php --fresh   # rebuild history and a live queue
```

`database/seed.php` is safe to run twice: a day that already has history is left
alone, and a service that already has people waiting keeps its queue. See
[database/seed.php](database/seed.php).

---

## Tests

```bash
bash bin/php.sh tests/run_all.php
```

6 files · 66 tests · 287 assertions. Current suite is green.

| File | Proves |
|---|---|
| `state_machine_test.php` | every legal transition passes, every illegal one returns 409 |
| `concurrency_test.php` | 50 simultaneous issuers, no duplicates, no deadlocks |
| `double_call_next_test.php` | two desks racing on an empty queue: exactly one winner |
| `eta_test.php` | the arithmetic, the outlier filter, and both ETA behaviours from the plan |
| `security_test.php` | CSRF, rate limit, staff guards, hashed password |
| `http_auth_test.php` | the sign-in → overview → sign-out flow over HTTP against a running server |

**Measured concurrency results** (from the two concurrency modes):

```
direct  50 processes on one wall-clock barrier
        fired in 2.67 s   peak overlap: 50 inside issue() at once   deadlocks 0
http    50 parallel POST /api/tokens through curl_multi
        fired in 0.22 s   median single latency 32.4 ms
        serial equivalent 1.62 s  ->  implied concurrency ~7.3x    deadlocks 0
```

HTTP mode needs a multi-worker server and is run against a live one:

```bash
bash bin/dev-server.sh start
bash bin/php.sh tests/concurrency_test.php http http://127.0.0.1:8080 50
```

`double_call_next_test.php` asserts what is actually true: for simultaneous
requests, *some* counter wins and no visitor is lost — strict "first click wins"
is undefined under true simultaneity. FIFO order for sequential requests is
asserted separately.

---

## API

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/api/health` | public | app/db/php status |
| GET | `/api/services` | public | active services + waiting counts |
| POST | `/api/tokens` | public | take a token (`service_id`) → `public_id`, `display_code` |
| GET | `/api/tokens/{public_id}` | public | status, position, ETA |
| POST | `/api/tokens/{public_id}/cancel` | public | visitor leaves the queue |
| GET | `/api/status` | public | display board: serving per counter, queue lengths |
| GET | `/api/staff/overview` | staff | counters, current tokens, waiting per service |
| POST | `/api/staff/counters/{id}/call-next` | staff | give the next waiting visitor to a counter |
| POST | `/api/staff/tokens/{id}/start` | staff | called → serving |
| POST | `/api/staff/tokens/{id}/complete` | staff | serving → completed |
| POST | `/api/staff/tokens/{id}/no-show` | staff | called → no_show |
| POST | `/api/staff/tokens/{id}/recall` | staff | re-announce a called token |
| GET | `/staff/login` · POST `/staff/login` | – | session sign-in (CSRF on the form) |
| POST | `/staff/logout` | staff | sign out |
| GET | `/` · `/display` · `/staff` | – | the three screens |

`{public_id}` is 16 hex characters from `random_bytes(8)` — an obviously wrong id
is a 404 from the router, not a database round trip.

---

## Security

* PDO with `ERRMODE_EXCEPTION`, `EMULATE_PREPARES = false`, `utf8mb4`, and the
  session time zone pinned to `+05:30` so `NOW()` agrees with the `queue_date`
  the app computes in `Asia/Kolkata`.
* Staff password: `password_hash()` in `.env`, verified with `password_verify()`;
  an empty hash fails closed (login disabled). `session_regenerate_id(true)` on
  success, `HttpOnly` + `SameSite=Lax` cookie.
* CSRF: staff writes require a token from the session, checked **after**
  authentication (401 before 403 — asking an anonymous caller for a CSRF token
  would leak whether a session exists). Expired forms redirect with a plain
  message instead of an error page.
* Abuse limit: `POST /api/tokens` is throttled per IP (fixed window, 10/min,
  `flock`-guarded JSON file under `storage/rate-limit`), plus one active token
  per service per browser. Set `RATE_LIMIT_TOKENS_PER_MINUTE=0` to disable it —
  the concurrency test needs that, because it legitimately fires 50 requests from
  one address.
* Output escaped in views; JavaScript writes `textContent`.
* `.env` is git-ignored and outside `public/`; the vendored Bootstrap CSS/JS is
  served locally (SRI hash verified against the published 5.3.8 build), so the
  board keeps working with no internet and no third-party origin.

---

## Deliberate limits (and what I'd do next)

Out of scope on purpose: SMS/WhatsApp notifications, multi-branch, payments, an
analytics dashboard, full user accounts, WebSockets.

Known limits worth saying out loud:

* **Polling, not push.** 3 s polling is simple, stateless and works on shared
  hosting. Next step: SSE (or WebSockets with Redis pub/sub), plus a 1–2 s cache
  on `/api/status`.
* **One staff password.** No per-user accounts or audit trail. Next: per-staff
  users and a "who completed what" log.
* **The ETA's in-flight term is one half service**, no matter how many desks are
  mid-service. Fine for a waiting room, wrong for a large hall.
* **Rate limiting is per IP file.** Real deployments want a shared store.
* **Strict FIFO to the *first* simultaneous caller is undefined.** No visitor is
  ever lost or duplicated; ordering among truly simultaneous clicks is not
  promised.

---

## Demo script (3 minutes)

1. Display board on the big screen, visitor page on a phone, staff panel on a
   laptop. Click **"Click to enable sound"** on the board (browsers refuse audio
   until a real click — this is the usual reason demos are silent).
2. Take 3 tokens on the phone. Show position + ETA range.
3. Staff: **Call Next** → the board chimes and flashes, the phone flips to
   "Go to Counter 1".
4. Complete a few quickly → watch the remaining ETA shrink as the rolling
   average learns.
5. Run the live race: `bash bin/php.sh tests/concurrency_test.php http http://127.0.0.1:8080 50`
   → 50 simultaneous tokens, zero duplicates, zero deadlocks.
