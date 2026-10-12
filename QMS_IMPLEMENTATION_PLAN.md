# Smart Queue Management System (QMS) — Implementation Plan

Revised plan. Based on the GLM 5.3 proposal, with fixes found during research (versions checked Oct 2026).

---

## 1. What we are building

Visitors take a digital token instead of standing in line. They see live position and estimated wait. A public display board shows who is being served. Staff call, serve and complete tokens from a panel.

**Three screens:** Visitor (`/`), Display board (`/display`), Staff panel (`/staff`).
**Four things worth talking about:** race-free token numbers, state machine, self-learning ETA, polling vs SSE trade-off.

---

## 2. Research findings (what changed vs original plan)

| # | Original plan | Problem | Fix |
|---|---|---|---|
| 1 | `SELECT MAX(number) ... FOR UPDATE` to get next number | On an empty day, InnoDB locks a *gap*. Two concurrent requests both take the gap lock, then both try to insert → **deadlock (error 1213)**. Retry hides it but does not fix it. | Per-service/day **counter row** + `INSERT ... ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)`. One row lock, no gap, no deadlock. Keep `UNIQUE` key as safety net. |
| 2 | `callNext` picks oldest waiting token | Two counters clicking at once can grab the **same token**. | `SELECT ... FOR UPDATE SKIP LOCKED` (MySQL 8.0.1+, MariaDB 10.6+). |
| 3 | States: waiting → called → serving → completed | No method ever moves `called → serving`. ETA uses `called_at → completed_at`, which includes walk-to-counter time, so it overestimates service time. | Add `startServing`, `serving_at` column. ETA uses `serving_at → completed_at`. |
| 4 | ETA = position × avg | Ignores number of open counters. 3 counters = 3× faster. | `ETA = ahead × avg ÷ open_counters`. |
| 5 | "Simple password stored in config" | Plain-text password, no CSRF, no session hardening. | `password_hash` / `password_verify`, hash in `.env`, `session_regenerate_id()`, CSRF token, `HttpOnly` + `SameSite` cookie. |
| 6 | Visitor tracks token by numeric `id` | IDs are guessable; anyone can read anyone's token. | Random `public_id` (`bin2hex(random_bytes(8))`) used in visitor URLs/API. |
| 7 | `CURDATE()` for queue date | DB timezone may differ from India time → queue resets at wrong hour. | App computes `queue_date` in `Asia/Kolkata`, passes as parameter. Set DB session `time_zone = '+05:30'`. |
| 8 | "Sound alert, 5 lines of JS" | Browsers **block autoplay audio** until user interacts with page. Demo would be silent. | Add "Enable sound" button on display board; first click unlocks audio. |
| 9 | `php -S localhost:8000` | Built-in server is **single process**; a blocked request stalls everything (PHP manual). Concurrency test would serialize and prove nothing. `PHP_CLI_SERVER_WORKERS` fixes this but, as far as I know, not on Windows. | Use XAMPP Apache on Windows. On Linux/macOS: `PHP_CLI_SERVER_WORKERS=4 php -S ...`. |
| 10 | No cancel / recall | Visitors leave; staff need to re-call a missed token. | Add `cancelled` status and `recall` action. |

### Version targets (checked Oct 2026)

| Item | Target | Note |
|---|---|---|
| PHP | **8.4 or 8.5** | 8.5 released Nov 2025, latest patch ~8.5.10 (Aug 2026). Active support to Dec 2027, security to Dec 2029. 8.4 active support ends 31 Dec 2026. Avoid 8.3 and older (8.3 security-only; 8.2 EOL 31 Dec 2026). PHP 8.6 is alpha — do not use. |
| Database | **MariaDB 11.8 LTS** or **MySQL 8.4 LTS** | MySQL 8.0 reached EOL April 2026. MySQL 9.7 is the new LTS. XAMPP ships MariaDB; bundled version varies — run `SELECT VERSION();`. Need MariaDB ≥ 10.6 / MySQL ≥ 8.0.1 for `SKIP LOCKED`. |
| Bootstrap | **5.3.x** (5.3.8 at last check) | Pin exact version in CDN URL. Copy `integrity` hash from getbootstrap.com. |
| Extensions | `pdo_mysql`, `mbstring` | Both on by default in XAMPP. |

Code written here runs on PHP 8.2+, but target 8.4+ for new work.

---

## 3. Architecture

```
Browser (visitor / display / staff)
        │  fetch() JSON, poll every 3 s
        ▼
public/index.php  (front controller + tiny router)
        ▼
Controllers  →  Models (Token, Service, Counter, QueueStats)  →  PDO  →  MariaDB/MySQL
```

### Folder layout

```
qms/
├─ public/
│  ├─ index.php            # front controller
│  └─ assets/              # app.css, visitor.js, display.js, staff.js, ding.mp3
├─ app/
│  ├─ Core/                # Env, Database, Router, Request, Response, Auth, Csrf
│  ├─ Controllers/         # PageController, TokenController, StaffController, StatusController
│  ├─ Models/              # Service, Counter, Token, QueueStats
│  └─ Views/               # visitor.php, display.php, staff.php, login.php
├─ config/config.php
├─ database/
│  ├─ schema.sql
│  └─ seed.php
├─ tests/
│  ├─ concurrency_test.php
│  └─ state_machine_test.php
├─ .env.example
└─ README.md
```

No Composer, no framework. Tiny `spl_autoload_register` maps `App\Models\Token` → `app/Models/Token.php`.

---

## 4. Database schema

```sql
CREATE DATABASE qms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE qms;

CREATE TABLE services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  prefix CHAR(1) NOT NULL UNIQUE,                -- A, B ...
  avg_service_minutes DECIMAL(5,2) NOT NULL DEFAULT 7.00,   -- fallback only
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE counters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  service_id INT NULL,                           -- NULL = handles all services
  staff_name VARCHAR(50) NULL,
  is_open TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB;

-- Source of truth for "next number". One row per service per day.
CREATE TABLE queue_counters (
  service_id INT NOT NULL,
  queue_date DATE NOT NULL,
  last_number INT UNSIGNED NOT NULL,
  PRIMARY KEY (service_id, queue_date),
  FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB;

CREATE TABLE tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(16) NOT NULL UNIQUE,            -- random, used by visitors
  service_id INT NOT NULL,
  counter_id INT NULL,                           -- set when called
  queue_date DATE NOT NULL,
  number INT UNSIGNED NOT NULL,
  display_code VARCHAR(10) NOT NULL,             -- 'A-014'
  status ENUM('waiting','called','serving','completed','no_show','cancelled')
         NOT NULL DEFAULT 'waiting',
  taken_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  called_at    DATETIME NULL,
  serving_at   DATETIME NULL,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_token (service_id, queue_date, number),      -- safety net
  KEY idx_queue (service_id, queue_date, status, number),    -- position + callNext
  KEY idx_done  (service_id, status, completed_at),          -- ETA lookup
  FOREIGN KEY (service_id) REFERENCES services(id),
  FOREIGN KEY (counter_id) REFERENCES counters(id)
) ENGINE=InnoDB;
```

### State machine

```
waiting ──call──▶ called ──start──▶ serving ──complete──▶ completed
   │                │  ▲
   │                │  └──── recall (re-announce, stays called)
   │                └──no_show──▶ no_show
   └──cancel──▶ cancelled
```

Every transition is **one guarded UPDATE**, no extra locks:

```sql
UPDATE tokens SET status='serving', serving_at=NOW()
WHERE id = ? AND status = 'called' AND counter_id = ?;
-- rowCount() = 0  →  illegal transition → return HTTP 409
```

---

## 5. Core algorithms

### 5.1 Issue token (race-free)

```php
// Token::issue(int $serviceId): array
// Retry up to 3 times on MySQL error 1213 (deadlock) or 1205 (lock wait timeout)
$date = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

$pdo->beginTransaction();
$pdo->prepare(
  "INSERT INTO queue_counters (service_id, queue_date, last_number)
   VALUES (?, ?, LAST_INSERT_ID(1))
   ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)"
)->execute([$serviceId, $date]);

$number = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
$code   = $prefix . '-' . str_pad((string)$number, 3, '0', STR_PAD_LEFT);
$public = bin2hex(random_bytes(8));

$pdo->prepare(
  "INSERT INTO tokens (public_id, service_id, queue_date, number, display_code)
   VALUES (?,?,?,?,?)"
)->execute([$public, $serviceId, $date, $number, $code]);
$pdo->commit();
```

Why it works: the counter row is locked until commit, so concurrent requests for the same service queue up on one row. Numbers are gap-free and unique.

### 5.2 Call next (no double-pick)

```sql
-- inside a transaction; $serviceFilter = counter's service or all services
SELECT id FROM tokens
WHERE queue_date = ? AND status = 'waiting'
  AND (service_id = ? OR ? IS NULL)
ORDER BY taken_at, id
LIMIT 1
FOR UPDATE SKIP LOCKED;
```
Then `UPDATE ... SET status='called', counter_id=?, called_at=NOW()`.
Before this, reject if the counter already holds a `called`/`serving` token (one at a time).

### 5.3 Dynamic ETA

```
samples  = last 10 completed tokens for the service
           duration = serving_at → completed_at
           drop durations < 10 s or > 60 min (misclicks, forgotten "complete")
avg      = mean(samples)
           if count < 3: blend with services.avg_service_minutes
open     = number of open counters that can serve this service (min 1)
ahead    = COUNT(waiting tokens, same service/date, number < mine)
eta_min  = ceil(ahead × avg ÷ open)
shown as a range: [eta × 0.75 , eta × 1.25]   e.g. "about 9–15 min"
```
Honest range beats fake precision. Put this in `QueueStats::etaFor(Token)`.
Stretch: add half an average service time if a counter is mid-service.

---

## 6. API (11 endpoints)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/api/services` | public | list active services + waiting count |
| POST | `/api/tokens` | public | take token (`service_id`) → `public_id`, `display_code` |
| GET | `/api/tokens/{public_id}` | public | status, position, ETA |
| POST | `/api/tokens/{public_id}/cancel` | public | visitor leaves queue |
| GET | `/api/status` | public | display board: serving per counter + queue lengths |
| POST | `/staff/login` · `/staff/logout` | – | session auth |
| GET | `/api/staff/overview` | staff | counters, current tokens, waiting per service |
| POST | `/api/staff/counters/{id}/call-next` | staff | next token |
| POST | `/api/staff/tokens/{id}/start` | staff | called → serving |
| POST | `/api/staff/tokens/{id}/complete` | staff | serving → completed |
| POST | `/api/staff/tokens/{id}/no-show` · `/recall` | staff | missed token handling |

All responses JSON: `{ "ok": true, "data": {...} }` or `{ "ok": false, "error": "..." }` with proper HTTP codes (400, 401, 404, 409, 429).

### Security checklist
- PDO: `ERRMODE_EXCEPTION`, `EMULATE_PREPARES = false`, charset `utf8mb4`.
- Output escaping with `htmlspecialchars` in views; JS uses `textContent`, not `innerHTML`.
- Staff: `password_hash` in `.env`, `session_regenerate_id(true)` after login, cookie `HttpOnly` + `SameSite=Lax`, CSRF token header on every staff POST.
- Abuse limit: one active token per service per browser (cookie + `localStorage`), plus simple per-IP rate limit on `POST /api/tokens`.
- `.env` outside `public/`.

---

## 7. Build phases

Rule: one phase → test → commit → next. Never ask the AI for the whole app.

### Phase 0 — Environment (20 min)
- Install PHP 8.4+ and MariaDB 11.8 / MySQL 8.4 (or XAMPP). Run `php -v` and `SELECT VERSION();`.
- Create DB `qms`, run `schema.sql`, `git init`.
- **Done when:** `php -v` ≥ 8.4, tables exist, `SKIP LOCKED` query runs without syntax error.

### Phase 1 — Scaffold (45 min)
**Prompt:**
> Create a PHP 8.4 project, no frameworks, no Composer. Folders: public/, app/Core, app/Controllers, app/Models, app/Views, config/, database/, tests/. Include: .env loader, PDO Database singleton (ERRMODE_EXCEPTION, EMULATE_PREPARES false, utf8mb4, sets session time_zone to +05:30), PSR-4-style spl_autoload_register for the App\ namespace, a small Router (method + path with {param} support), JSON Response helper, and public/index.php as front controller. Set default timezone Asia/Kolkata. Add a GET /api/health route returning {"ok":true}.

**Done when:** `GET /api/health` returns JSON; bad DB credentials show a clean error, not a stack trace.

### Phase 2 — Queue engine (1.5 h) — the heart
**Prompt:**
> Using the schema in database/schema.sql, create app/Models/Token.php with: issue(int $serviceId) using the queue_counters upsert + LAST_INSERT_ID pattern inside a transaction, retrying up to 3 times on MySQL errors 1213 and 1205; callNext(int $counterId) using SELECT ... FOR UPDATE SKIP LOCKED, rejecting if the counter already holds a called/serving token; start(int $tokenId, int $counterId), complete(int $tokenId), markNoShow(int $tokenId), recall(int $tokenId), cancel(string $publicId). Each state change must be a single guarded UPDATE ... WHERE status = <expected> and throw a domain exception (→ HTTP 409) when rowCount is 0. Prepared statements only.

**Tests (do not skip):**
1. `tests/state_machine_test.php` — every legal transition passes, every illegal one throws.
2. `tests/concurrency_test.php` — fire 50 parallel `POST /api/tokens` (PHP `curl_multi`, or `seq 50 | xargs -P 20 -I{} curl -s -X POST ...`). Then run:
   ```sql
   SELECT service_id, queue_date, number, COUNT(*) c
   FROM tokens GROUP BY 1,2,3 HAVING c > 1;     -- expect 0 rows
   SELECT MAX(number), COUNT(*) FROM tokens WHERE service_id=1;  -- expect equal
   ```
   Server must run multi-worker (Apache / XAMPP), otherwise requests run one by one and the test proves nothing.
3. Two counters call-next at once with 1 waiting token → exactly one gets it, other gets "queue empty".

**Done when:** all three pass.

### Phase 3 — Staff panel (1.5 h)
**Prompt:**
> Build the staff area: POST /staff/login (password verified with password_verify against a hash in .env, session_regenerate_id on success, CSRF token in session), GET /staff page (Bootstrap 5.3 via pinned CDN), showing each counter with: Call Next button, current token in large text, and Start / Complete / No-Show / Recall buttons enabled according to token state. Show waiting count per service. Staff JS uses fetch with the CSRF header and refreshes /api/staff/overview every 3 seconds. Disable buttons while a request is in flight.

**Done when:** two browser windows as two counters never get the same token; wrong-order clicks show a friendly error.

### Phase 4 — Visitor page + display board (1.5 h)
**Prompt:**
> Create (1) visitor page: pick a service, POST /api/tokens, store public_id in localStorage so refresh keeps the ticket, show big token code, position, and ETA range, refreshing every 3 s from GET /api/tokens/{public_id}; show clear states for waiting / called ("Go to Counter 2") / serving / done / cancelled; Cancel button. (2) display board /display: full-screen dark layout, one large card per counter showing NOW SERVING code, plus waiting count per service, polling /api/status every 3 s. Pause polling when document.hidden. Implement GET /api/status.

**Done when:** walk through full flow on phone (visitor) + laptop (display) + second laptop (staff).

### Phase 5 — Smart ETA (1 h)
**Prompt:**
> Create app/Models/QueueStats.php with avgServiceMinutes(int $serviceId) (mean of last 10 completed tokens, duration = serving_at→completed_at, ignore <10 s and >60 min, blend with services.avg_service_minutes when fewer than 3 samples), openCounters(int $serviceId), and etaFor(array $token) returning {minutes, low, high} using ahead × avg ÷ openCounters. Wire it into GET /api/tokens/{public_id}. Add unit tests with fixed sample data.

**Done when:** closing a counter raises ETA; finishing several fast services lowers ETA.

### Phase 6 — Polish + demo prep (1 h)
- `database/seed.php`: 2 services, 3 counters, ~200 completed tokens over past 3 days with realistic durations (random ~4–10 min), plus a few waiting tokens today. ETA looks smart on first launch.
- Display board: **"Click to enable sound"** overlay → unlocks audio. Play ding when a new token appears (compare `called_at`/token id with last seen). Stretch: `speechSynthesis` reads "Token A zero one four, counter two".
- Friendly empty states, error toasts, mobile layout check.
- `README.md`: problem, architecture sketch, setup, API table, concurrency design, known limits.
- Reset script: truncate tokens + queue_counters for demo reruns.

**Total:** ~7.5 h incl. Phase 0, with buffer. Cut order if short on time: speech → cancel → recall → rate limit.

---

## 8. Demo script (3 min)

1. Display board on big screen, visitor on phone, staff on laptop.
2. Take 3 tokens on the phone. Show position + ETA.
3. Staff: Call Next → board updates, sound plays, phone shows "Go to Counter 1".
4. Complete a few quickly → show ETA for remaining token shrink.
5. Run `concurrency_test.php` live: 50 simultaneous tokens, zero duplicates.

---

## 9. Likely interview questions

| Question | Answer outline |
|---|---|
| Two people click at the same instant. | Counter row lock serializes per service; `UNIQUE` key is backstop; retry on deadlock/timeout. Tested with 50 parallel requests. |
| Why not `MAX()+1`? | Race window; with `FOR UPDATE` on empty set, gap locks → deadlocks. |
| Two counters call next together? | `FOR UPDATE SKIP LOCKED` — second skips the locked row. |
| How does ETA work? | Rolling average of real service durations, divided by open counters, shown as range. Falls back to default with little data. |
| Why polling not WebSockets? | Simple, stateless, works on PHP + shared hosting. 3 s is fine for a queue. |
| How would you scale it? | SSE or WebSockets (Redis pub/sub), cache `/api/status` for 1–2 s, read replica, move ETA calc to cached job. |
| Security? | Prepared statements, `password_hash`, CSRF, session hardening, unguessable `public_id`, rate limit. |
| What breaks? | Staff forgetting "complete" skews ETA (filtered by 60 min cap); no-shows; multi-day token resets handled by `queue_date`. |

---

## 10. Out of scope (say this on purpose)

SMS/WhatsApp notifications, multi-branch, payments, analytics dashboard, full user accounts, WebSockets. Mention as "next steps" — shows you cut scope deliberately.

---

## 11. Definition of done

- [ ] 50-parallel token test: zero duplicates
- [ ] Double call-next test: single winner
- [ ] Illegal transitions return 409
- [ ] Staff password hashed, CSRF on all staff POSTs
- [ ] ETA changes when counters open/close and from live data
- [ ] Display board polls, plays sound after click
- [ ] Seed script gives a good first-run demo
- [ ] README with setup + architecture + concurrency explanation
- [ ] Clean git history, one commit per phase
