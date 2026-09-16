# Hospital Management System (HMS)

A PHP/MySQL web application implementing core hospital workflows: patient registration, appointments, prescriptions, billing, inventory, and staff management.

**Stack:** PHP 8+ · MySQL 8+ · Vanilla JS · Service Worker (offline-first) · PDO · RBAC

---

## Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| PHP  | 8.0+    | PDO + PDO_MySQL extensions required |
| MySQL | 8.0+   | Or MariaDB 10.6+ |
| Web server | Apache 2.4+ or Nginx | Apache: `mod_rewrite` must be enabled |

The easiest local setup on macOS is **XAMPP**, **MAMP**, or **Laravel Herd**.

---

## Quick Start (XAMPP — recommended for local testing)

### 1. Install XAMPP

Download from [apachefriends.org](https://www.apachefriends.org/) and start **Apache** and **MySQL** from the XAMPP Control Panel.

### 2. Place the project

Copy the `HMS/` folder into XAMPP's web root:

```
macOS:   /Applications/XAMPP/xamppfiles/htdocs/HMS
Windows: C:\xampp\htdocs\HMS
```

### 3. Configure credentials

Copy the secrets template and fill in your MySQL credentials:

```bash
cp config/external_config.example.php config/external_config.php
```

Edit `config/external_config.php`. The file auto-detects environment by hostname:

```php
// Stage (localhost / 127.0.0.1) credentials
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'hms_db');
define('DB_USER', 'root');
define('DB_PASS', '');   // XAMPP default is no password

// Production credentials — fill in the else block in external_config.php
```

`APP_ENV` is set to `'stage'` automatically when the server hostname is `localhost` or `127.0.0.1`, otherwise `'prod'`.

### 4. Run the setup script

Open your browser and visit:

```
http://localhost/HMS/setup.php
```

This will:
- Create the `hms_db` database
- Run the full schema (17 tables, 5 triggers)
- Seed all demo data (users, patients, appointments, prescriptions, billing, inventory, suppliers, shifts)

You should see a green success log ending with `=== Setup complete! ===`.

> **After setup, delete or block access to `setup.php`** — it re-seeds the database on every run.

### 5. Log in

```
http://localhost/HMS/
```

| Username     | Password     | Role        | Access |
|-------------|-------------|-------------|--------|
| `superadmin` | `d3f@uL+Ed1` | SuperAdmin  | Everything + Audit Log + Staff Shifts |
| `admin1`     | `d3f@uL+Ed1` | Admin       | Patients, Appointments, Billing, Inventory, Staff Profiles (view) |
| `DR_Kazenga` | `d3f@uL+Ed1` | Doctor      | My Appointments (respond/amend), Patient Profiles, Prescriptions |
| `DR_Lupa`    | `d3f@uL+Ed1` | Doctor      | My Appointments (respond/amend), Patient Profiles, Prescriptions |
| `nurse_tim`  | `d3f@uL+Ed1` | Nurse       | Patients, Appointments, Book Appointment, Prescriptions (view), Inventory (view), My Shifts |
| `nurse_joy`  | `d3f@uL+Ed1` | Nurse       | Patients, Appointments, Book Appointment, Prescriptions (view), Inventory (view), My Shifts |
| `patient_1`  | `d3f@uL+Ed1` | Patient     | Dashboard, own profile, Book/view/cancel own appointments |
| `patient_2`  | `d3f@uL+Ed1` | Patient     | Dashboard, own profile, Book/view/cancel own appointments |

---

## Database Configuration

Credentials are stored in `config/external_config.php` (not committed). The app config in `config/config.php` loads this file at runtime. See `config/external_config.example.php` for the full template.

**`config/config.php` settings you may want to adjust:**

```php
define('BASE_URL',         '/HMS');  // URL prefix — change to '' for built-in server at root
define('SESSION_LIFETIME', 600);     // Idle session timeout in seconds (default: 10 minutes)
```

---

## Project Structure

```
HMS/
├── .gitignore              # Excludes secrets (external_config.php, .env) and OS files
├── config/
│   ├── config.php          # App config: BASE_URL, SESSION_LIFETIME, ROLES — loads secrets file
│   ├── config.example.php  # Template for config.php (safe to commit)
│   ├── external_config.php # DB credentials — NOT committed (see .gitignore)
│   ├── external_config.example.php  # Template for external_config.php
│   └── .htaccess           # Deny direct browser access to config/
├── css/
│   └── style.css           # Design system (CSS custom properties, responsive sidebar layout)
├── img/                    # Static image assets
├── includes/
│   ├── auth.php            # Session, CSRF, RBAC helpers: require_role(), verify_csrf(), h(),
│   │                       #   flash_set/get(), redirect(), get_sync_token(), validate_sa_id()
│   ├── db.php              # PDO singleton (get_db())
│   ├── audit.php           # write_audit_log() — called after every mutation
│   ├── header.php          # Sidebar nav (role-aware), top bar, sync badge, SW registration
│   └── footer.php          # Closes layout, loads main.js + sync_manager.js
├── js/
│   ├── .htaccess           # Sends Service-Worker-Allowed: /HMS/ so SW in /js/ can control /HMS/ scope
│   ├── main.js             # UI helpers: sidebar, flash dismiss, validateForm(), validateSaId(),
│   │                       #   initSaIdLiveValidation(), populateFromSaId(), setRequiredTooltips()
│   ├── sync_manager.js     # Connectivity probe, offline UI, IndexedDB v2 queue + session cache,
│   │                       #   form interception, flush to sync_receive.php
│   └── service_worker.js   # SW (hms-cache-v13): network-first (PHP), cache-first (assets),
│                           #   NETWORK_FIRST list (auth/sync/probe), inline offline fallback
├── pages/                      # All application page controllers
│   ├── login.php           # FR-01: Authentication — accepts username or email address
│   ├── logout.php          # Session destroy + audit
│   ├── dashboard.php       # FR-01: Role-aware dashboard with stat cards & recent items
│   ├── register_patient.php# FR-01: Patient registration (Admin/Nurse/SA)
│   ├── patient_profile.php # FR-01: View/edit patient profile; list view for staff
│   ├── appointment_list.php# FR-03: Full appointment workflow (see below)
│   ├── appointment_book.php# FR-03: Book an appointment (all roles; patients self-book)
│   ├── prescriptions.php   # FR-02: Issue (Doctor), dispense (Pharmacist), cancel
│   ├── billing.php         # FR-04: Billing records, payments, stat cards
│   ├── inventory.php       # FR-05: Items, stock alerts, purchase orders, suppliers (4 tabs)
│   ├── staff_shifts.php    # FR-05/06: Assign and view shifts (SuperAdmin write, others view)
│   ├── staff_profiles.php  # FR-06: Create/deactivate Doctor & Nurse accounts (SuperAdmin write)
│   ├── audit_log.php       # SuperAdmin: paginated audit trail + CSV export
│   ├── my_security.php     # Change username, view/edit email (SA only), reset password (all roles); practice number read-only card (Doctor/Nurse)
│   ├── session_info.php    # NFR-03: Returns session snapshot as JSON for IndexedDB caching
│   ├── ping.php            # NFR-03: Stateless connectivity probe (no session, no DB) — HEAD → 200 ok
│   └── sync_receive.php    # NFR-03: Receives offline-queued ops from sync_manager.js
├── sql/
│   └── schema.sql          # Full DDL: 17 tables, 5 triggers, FK constraints
├── offline.html            # Shown by SW when page unavailable and no cache hit
├── setup.php               # One-time DB setup + seed script — DELETE after use
└── index.php               # Redirects to login or dashboard
```

---

## Pages at a Glance

| URL | Who can access | What it does |
|-----|---------------|-------------|
| `/HMS/` | Everyone | Redirect to login or dashboard |
| `/HMS/pages/login.php` | Public | Login form |
| `/HMS/pages/dashboard.php` | All logged-in | Stat cards + recent items (role-filtered) |
| `/HMS/pages/register_patient.php` | Admin, Nurse, SA | Register new patient |
| `/HMS/pages/patient_profile.php?id=N` | Admin, Doctor, Nurse, SA | View/edit patient |
| `/HMS/pages/appointment_list.php` | All logged-in | Browse & act on appointments |
| `/HMS/pages/appointment_book.php` | All logged-in | Book appointment (patients self-book) |
| `/HMS/pages/prescriptions.php` | All logged-in | Issue / dispense / cancel Rx |
| `/HMS/pages/billing.php` | Admin, Doctor, Nurse, SA | Billing + payment recording |
| `/HMS/pages/inventory.php` | Admin, Nurse, Pharmacist, SA | Stock, alerts, POs, suppliers |
| `/HMS/pages/staff_shifts.php` | Admin, Doctor, Nurse, SA | Shift schedule |
| `/HMS/pages/staff_profiles.php` | Admin, SA | Create/deactivate Doctor & Nurse accounts; SuperAdmin can update practice numbers |
| `/HMS/pages/audit_log.php` | SuperAdmin only | Immutable audit trail + CSV export |
| `/HMS/pages/my_security.php` | All logged-in | Change username · view/edit email (SA) · reset password |
| `/HMS/pages/session_info.php` | Authenticated (GET) | JSON session snapshot for offline cache |
| `/HMS/pages/ping.php` | Public (GET/HEAD) | Connectivity probe — returns `200 ok`; no session or DB required |

---

## Appointment Workflow

Appointments follow a strict state machine enforced in `appointment_list.php`:

```
Patient books → Requested
  Doctor accepts    → Confirmed
  Doctor amends     → Amended  (proposed_date/time set; patient must respond)
    Patient accepts → Confirmed (proposed date/time replaces original)
    Patient declines→ Declined
  Doctor declines   → Declined  (reason stored)
Patient cancels own Requested/Confirmed → Cancelled
```

All state transitions are guarded by an **optimistic lock** — if another user updated the record while the form was open, the action is rejected with a clear error message.

---

## Security Notes

- All forms use CSRF tokens (`csrf_field()` + `verify_csrf()`).
- All output is escaped via `h()` (`htmlspecialchars`).
- All DB queries use PDO prepared statements — no string interpolation.
- Every data mutation writes a row to `audit_log` via `write_audit_log()`.
- Sessions use `httponly=true`, `SameSite=Strict`, and regenerate ID on login.
- `config/` is blocked from direct browser access via `.htaccess`.
- DB credentials live in `external_config.php`, which is excluded from git via `.gitignore`.
- Sync operations are authenticated with a per-session `sync_token` (issued by `get_sync_token()` and verified in `sync_receive.php`).
- Login accepts either `users.username` or `users.email` — both columns are unique-indexed.
- SA ID numbers are validated server-side (`validate_sa_id()`) and client-side (`validateSaId()`) checking: 13-digit format, valid YYMMDD date of birth, citizenship digit (0/1), and Luhn check digit. On blur, the client also auto-populates Date of Birth and Gender from a valid ID.
- Password policy: minimum 10 characters, at least one uppercase letter, one lowercase letter, one digit, and one special character. Enforced on both client and server.
- Phone numbers must be exactly 10 digits and start with 0 (`^0\d{9}$`). Validated client-side on all `type="tel"` inputs and server-side on patient, staff, and emergency contact phone fields.
- Password changes (via My Security) verify the current hash with `password_verify()` before updating; old passwords are never stored.
- Mandatory form fields show a red left-border indicator; failing validation adds a full red border and a hover tooltip stating which field is required.
- Practice numbers for doctors and nurses are set on account creation (mandatory, alphanumeric) and can only be updated by a SuperAdmin. Doctors and nurses can view their practice number on the My Security page (read-only).

> **Production checklist:** set `DB_PASS` in `external_config.php` to a strong password, move `external_config.php` above the web root (update the path in `config.php`), enable HTTPS (set `secure=true` in `auth.php` session cookie), and delete `setup.php`.

---

## Offline Support (PWA)

The app is offline-first across three layers:

### Layer 1 — Service Worker (`js/service_worker.js`)

The SW is registered with scope `/HMS/`. Because the file lives in `/HMS/js/`, the `js/.htaccess` sends the `Service-Worker-Allowed: /HMS/` response header so the browser permits the wider scope.

**Cache strategies:**

| Request type | Strategy | Offline behaviour |
|---|---|---|
| `offline.html`, `style.css`, `main.js`, `sync_manager.js` | Pre-cached at install (`Promise.allSettled` — one miss never blocks install) | Always available |
| Shell pages (`dashboard`, `register_patient`, `appointment_book`, `appointment_list`) | Network-first → cache fallback | Served from cache |
| `ping.php`, `login.php`, `logout.php`, `sync_receive.php` | Network-only (`NETWORK_FIRST`) | Returns `503` JSON — never stale data |
| All other `.php` pages | Network-first → cache fallback | Served from cache if previously visited |
| No cache hit at all | Inline fallback HTML | "No Connection" page — never a browser error |

All cache lookups use `{ ignoreVary: true }` to avoid `Vary`-header mismatches between prefetch fetches (mode `cors`) and navigation requests (mode `navigate`).
All `Response.clone()` calls are made synchronously before `return res` — calling inside a `.then()` after the response is handed to the browser causes a "body already used" error.

Current cache version: `hms-cache-v13`.

**Bump the `CACHE` constant in `js/service_worker.js` whenever `style.css`, `main.js`, or `sync_manager.js` changes.** These are cached cache-first (see table above), so an unchanged version number means browsers keep serving the *old* file — the real fix only lands a request later, once the background revalidation fetch happens to catch up. Bumping the version forces the SW's `activate` handler to purge every old cache immediately, so the fix is guaranteed on the very next load. PHP pages don't need a bump — they're network-first, so edits there are live on the next reload regardless.

### Layer 2 — Sync Manager (`js/sync_manager.js`)

Manages connectivity detection, the offline UI, and the IndexedDB operation queue.

**IndexedDB `hms_offline` (version 2) stores:**
- `pending_ops` — queued changes awaiting flush.
- `session` — session snapshot cached from `session_info.php` so auth context is available offline.
- `data_cache` — reserved for offline data lookups.

**Key behaviours:**
- `_prefetchShell()` — runs automatically after every login; fetches the four shell pages so the SW caches them before the user navigates to them manually.
- `_probeOnline()` — sends a `HEAD` request to `ping.php` with a 2 s timeout. `navigator.onLine` is **not** used (it stays `true` under DevTools Network throttling); only a real network probe is reliable.
- `_connected` — internal state variable; all offline/online decisions read this, never `navigator.onLine`.
- Connectivity is polled every **120 s in production** / **60 s in stage** (interval set from `window.HMS_ENV`). The `online`/`offline` browser events also trigger an immediate re-check.
- `_applyOfflineUI(connected)` — when offline, grays out (`.nav-offline`, `opacity 0.38`, `cursor: not-allowed`) every sidebar link whose `href` is not in the shell-pages list. Each grayed link gets a tooltip: *"Service unavailable offline — available when connection is restored"*. All items are restored immediately on reconnect.
- `_hookForms()` — intercepts form submits. Forms with `data-offline-sync` submit via `fetch()` POST (the SW does not intercept POST, so the call throws when offline); on network error the serialised payload is queued to IndexedDB. Forms without `data-offline-sync` show a warning instead of queuing.
- `flush()` — sends all queued ops to `sync_receive.php` on reconnect, authenticated by the per-session `sync_token`.

The sync badge in the sidebar header turns orange when there are pending offline operations.

### Layer 3 — Sync Receiver (`pages/sync_receive.php`)

Replays queued operations into MySQL using the same validation rules as the live page controllers. Every replayed operation writes a row to both `audit_log` and `sync_log`.

### Connectivity Probe (`pages/ping.php`)

A stateless, session-free, DB-free endpoint that returns `200 OK`. Used exclusively by `_probeOnline()`. Lives in `NETWORK_FIRST` so the SW never serves a cached response for it. This tells the SW if we are online or offline to then serve the cached pages or update recods to the sync i the IndexedDB.

---

## Code Quality & Standards

### i. Implemented Feature Coverage

All core hospital workflows are fully implemented across six authenticated roles:

| Module | Implemented Functionality |
|--------|--------------------------|
| Authentication | Login by username or email; idle session timeout; CSRF protection; password complexity |
| Patient Management | Registration, profile view/edit, SA ID validation, DOB/gender auto-population, emergency contact |
| Appointments | Book, confirm, amend, accept/decline amendment, cancel; optimistic locking; state machine |
| Prescriptions | Issue (Doctor), dispense (Pharmacist), cancel (Doctor/Admin/SA); role-filtered views |
| Billing | Create invoice, record payment, stat cards per billing status |
| Inventory | Stock items, reorder alerts, purchase orders, supplier management (4-tab UI) |
| Staff Management | Create/deactivate Doctor & Nurse accounts with mandatory practice number; shift scheduling |
| My Security | Username change, email view/edit (SA only), password reset with countdown redirect; practice number read-only (Doctor/Nurse) |
| Audit Log | Immutable, paginated, CSV-exportable record of every data mutation |
| Offline / PWA | Service Worker, IndexedDB queue, session cache, automatic flush on reconnect |

---

### ii. Code Comments & Documentation

Each source file follows a consistent commenting approach:

- **File header** — every PHP page opens with a `/** ... */` doc-block stating the file's purpose, which HTTP methods it handles, and which roles are permitted.
- **Section markers** — logical blocks within a page (input collection, validation, persistence, output) are separated by short `//` comments naming the block.
- **Non-obvious logic** — inline comments explain constraints or business rules that are not immediately clear from the code alone (e.g. the Luhn check-digit loop in `validate_sa_id()`, the optimistic-lock timestamp comparison in appointment updates, the dual-field login query).
- **JS functions** — public utility functions in `main.js` carry a brief description above the function signature. Complex functions note their parameters and return value.
- **SQL schema** — every table in `schema.sql` carries a comment identifying the functional requirement it belongs to and what the table represents. Non-obvious columns include an inline `--` explanation.

---

### iii. Coding Standards

The codebase follows consistent conventions across all layers:

**PHP**
- 4-space indentation; opening braces on the same line as the control structure.
- `snake_case` for variables and function names; `UPPER_CASE` for constants (`define()`).
- All user-supplied values pass through `trim()` before use and `h()` (`htmlspecialchars`) before output.
- `require_once` is used for all includes — it fails loudly rather than silently if a file is missing.

**SQL**
- Keywords in `UPPER CASE`; table and column identifiers in `snake_case`.
- All queries use PDO prepared statements with `?` positional placeholders — no string interpolation.
- Foreign keys and `UNIQUE` constraints are declared in the schema, not enforced only in application code.
- `INSERT IGNORE` in seed data allows `setup.php` to be re-run without duplicate-key errors.

**HTML / CSS**
- CSS custom properties (`--primary`, `--danger`, `--border`, etc.) are declared in `style.css` — no hard-coded colour literals appear in page templates.
- Consistent component classes: `.card`, `.card-header`, `.card-body`, `.form-group`, `.field-error`, `.btn`, `.status-badge`.
- All forms include the `novalidate` attribute; validation is handled programmatically in JS and PHP rather than relying on native browser behaviour.

**JavaScript**
- `'use strict'` is declared at the top of `main.js`.
- Vanilla JS only — no external framework or library dependency.
- Event listeners are attached in `DOMContentLoaded` blocks; global utility functions are named and reusable across pages.

---

### iv. Code Efficiency

- **Single DB connection** — `get_db()` in `includes/db.php` returns a PDO singleton; the connection is opened once per request and reused for every query on that page.
- **Role-filtered queries** — list queries include `WHERE` clauses scoped to the current user's role (e.g. a Doctor only fetches their own appointments and prescriptions), avoiding over-fetching.
- **Result limits** — all list queries cap results with `LIMIT 100` to prevent runaway memory use on large data sets.
- **Prepared statement reuse** — a `prepare()` call is made once and `execute()` is called in a loop (e.g. seed inserts in `setup.php`), not re-prepared on every iteration.
- **Asset caching** — the Service Worker uses a cache-first strategy for CSS and JS so repeat page loads skip the network for static assets entirely.
- **PRG pattern** — every successful mutation ends with `redirect()`, preventing duplicate form submissions on browser refresh.
- **Lazy token creation** — `get_csrf_token()` and `get_sync_token()` only generate and store a token on the first call per session.

---

### v. Error Handling

- **Database transactions** — all multi-table writes (e.g. creating a user + patient profile, or user + doctor + staff record) are wrapped in `beginTransaction()` / `commit()` with a `rollBack()` in the `catch (PDOException $e)` block. Partial writes cannot reach the database.
- **PDO error mode** — the connection is opened with `PDO::ERRMODE_EXCEPTION`; any SQL failure throws a catchable exception rather than returning `false` silently.
- **Server logging** — `catch` blocks write the technical error to the server log via `error_log('[HMS] ...')` while showing only a generic user-facing message through `flash_set('error', ...)`.
- **CSRF check first** — every POST handler calls `verify_csrf()` immediately; failure redirects with an error flash before any business logic or DB access runs.
- **Role enforcement** — `require_login()` and `require_role()` are called at the top of every page controller; failure redirects to login or returns a 403 before any output is produced.
- **Optimistic locking** — appointment state changes compare a `record_updated_at` timestamp submitted with the form against the current database value; a mismatch aborts the action with a clear conflict error.
- **Flash messages** — user-facing outcomes (success / error / warning) are stored by `flash_set()` and rendered once by `flash_get()` in `header.php`, then automatically dismissed after 5 seconds.

---

### vi. Parameter Handling

**Server-side (PHP)**
- All `$_POST` and `$_GET` values are read with the null-coalescing operator (`?? ''`) so missing keys never produce notices.
- String inputs pass through `trim()` immediately after collection to remove accidental whitespace.
- Numeric IDs are cast with `(int)` before use: `$patient_id = (int)($_POST['patient_id'] ?? 0)`.
- Optional fields are passed to PDO as `null` using the short-circuit ternary (`$field ?: null`), keeping the database free of empty strings.
- Values are bound positionally with `?` in prepared statements and passed as an array to `execute([...])` — no manual escaping is needed or used.

**Client-side (JavaScript)**
- Field values are read as `field.value.trim()` before any validation or comparison.
- AJAX requests (e.g. the password-change endpoint) send parameters as `application/x-www-form-urlencoded` in the request body using `URLSearchParams`.
- The CSRF token is included in every AJAX call by reading the hidden `<input name="csrf_token">` value from the page before the `fetch()` is dispatched.

---

### vii. Validation Checks

Validation is applied in three independent layers so that bypassing one layer does not bypass the others:

**Client-side — `validateForm()` in `js/main.js`**
- Required fields: any `[required]` element with an empty value is rejected before submission.
- Email format: `/^[^\s@]+@[^\s@]+\.[^\s@]+$/` on all `input[type="email"]` fields.
- Password complexity: minimum 10 characters, at least one uppercase letter, one lowercase letter, one digit, and one special character — checked with five separate regex tests.
- Phone format: `/^0\d{9}$/` on all `input[type="tel"]` fields — exactly 10 digits, starting with 0.
- SA ID on submit: 13 digits, valid YYMMDD date of birth, citizenship digit (0 or 1), Luhn check digit.
- SA ID on blur: `initSaIdLiveValidation()` shows an inline error as soon as the user leaves the field; auto-populates DOB and Gender when valid.
- Alphanumeric: `/^[a-zA-Z0-9]+$/` on all `input[data-alphanumeric]` fields (e.g. practice number).

**Server-side — each PHP page controller**
- All client-side rules are mirrored in PHP so that a missing or manipulated JS environment cannot bypass validation.
- Uniqueness is verified via `SELECT` queries before any `INSERT` or `UPDATE`: username, email, SA ID number, and practice number must each be unique in their respective tables.
- Role-specific business rules: Doctors can only cancel their own prescriptions; patients can only cancel their own appointments; practice numbers can only be updated by a SuperAdmin.
- Enum allowlists: gender, blood group, appointment status, shift type, and staff type values are checked with `in_array()` before use.

**Database layer — `sql/schema.sql`**
- `NOT NULL` constraints on all required columns.
- `UNIQUE KEY` on `users.username`, `users.email`, `doctors.license_number`, `doctors.practice_number`, `nurses.license_number`, `nurses.practice_number`.
- `FOREIGN KEY` constraints with `ON DELETE CASCADE` ensure referential integrity — orphaned child records cannot exist.
- `ENUM` column types for status and role fields reject any value outside the declared set at the storage level.
- Five database triggers auto-generate `stock_alerts` when inventory falls below reorder thresholds.

---

## Abbreviations & Reference

### Acronyms

| Abbreviation | Full Term |
|---|---|
| SW | Service Worker |
| PWA | Progressive Web App |
| RBAC | Role-Based Access Control |
| PDO | PHP Data Objects |
| CSRF | Cross-Site Request Forgery |
| FR | Functional Requirement |
| NFR | Non-Functional Requirement |
| SA | SuperAdmin (shorthand used in role guards) |
| Rx | Prescription |
| PO | Purchase Order |
| FK | Foreign Key |
| PK | Primary Key |
| DDL | Data Definition Language (schema SQL) |
| POPIA | Protection of Personal Information Act |
| NHA | National Health Act |
| HPCSA | Health Professions Council of South Africa |
| SANC | South African Nursing Council |
| SA ID | South African 13-digit Identity Number (YYMMDD G SSS C A Z — validated with Luhn check) |

---

### Core Database Entities

| Table | What it represents |
|---|---|
| `users` | All system accounts — every role logs in through here |
| `patients` | Patient demographic and medical records |
| `doctors` | Doctor profiles (specialization, HPCSA licence, practice number) |
| `nurses` | Nurse profiles (department, SANC licence, practice number) |
| `staff` | Links a `users` record to a `doctors` or `nurses` profile |
| `appointments` | Bookings between a patient and a doctor |
| `prescriptions` | Medications issued by a doctor, dispensed by a pharmacist |
| `billing_records` | Invoice per patient visit |
| `payments` | Payments recorded against a billing record |
| `inventory` | Stock items (medications, consumables, equipment) |
| `stock_alerts` | Auto-generated when stock falls below reorder point |
| `suppliers` | Vendor contact details for purchase orders |
| `purchase_orders` | Orders raised against a supplier for an inventory item |
| `stock_receipts` | Goods-received records that update `inventory.stock_level` |
| `staff_shifts` | Scheduled shifts per staff member (Scheduled / Off Day / Standby) |
| `audit_log` | Immutable record of every data mutation in the system |
| `sync_log` | Tracks offline operations received via `sync_receive.php` |

---

### PHP Helper Functions

| Function | Defined in | Purpose |
|---|---|---|
| `get_db()` | `includes/db.php` | Returns the PDO singleton; opens the connection on first call |
| `require_login()` | `includes/auth.php` | Redirects to login if the session has no `user_id` |
| `require_role(array)` | `includes/auth.php` | Aborts with 403 if the current role is not in the allowed list |
| `is_logged_in()` | `includes/auth.php` | Returns `true` if a valid session exists |
| `current_user_id()` | `includes/auth.php` | Returns `$_SESSION['user_id']` as int |
| `current_role()` | `includes/auth.php` | Returns `$_SESSION['role']` as string |
| `current_username()` | `includes/auth.php` | Returns `$_SESSION['username']` |
| `current_full_name()` | `includes/auth.php` | Returns `$_SESSION['full_name']` |
| `h(string)` | `includes/auth.php` | `htmlspecialchars` wrapper — use on every value echoed to HTML |
| `csrf_field()` | `includes/auth.php` | Outputs a hidden `<input>` with the current CSRF token |
| `verify_csrf(string)` | `includes/auth.php` | Timing-safe comparison of submitted token against session token |
| `get_csrf_token()` | `includes/auth.php` | Lazily creates and returns the session CSRF token |
| `get_sync_token()` | `includes/auth.php` | Lazily creates and returns a per-session sync authentication token |
| `flash_set(type, msg)` | `includes/auth.php` | Stores a one-time message in the session (`success`/`error`/`warning`/`info`) |
| `flash_get(type)` | `includes/auth.php` | Retrieves and clears a flash message; returns `null` if none |
| `redirect(path)` | `includes/auth.php` | Prepends `BASE_URL` and issues a `Location:` header redirect |
| `validate_sa_id(string)` | `includes/auth.php` | Validates a 13-digit SA ID: date of birth, citizenship digit (0/1), Luhn check digit — returns error string or `null` |
| `write_audit_log(...)` | `includes/audit.php` | Inserts a row into `audit_log` after every data mutation |

---

### JavaScript Globals & Modules

| Name | Type | Purpose |
|---|---|---|
| `window.HMS_BASE_URL` | Global string | URL prefix (e.g. `/HMS`) injected by `header.php`; used by JS to build API URLs |
| `window.HMS_SYNC_TOKEN` | Global string | Per-session sync token injected by `header.php`; sent with every offline flush |
| `window.HMS_ENV` | Global string | `'prod'` or `'stage'` injected by `header.php` from `APP_ENV`; controls ping interval (120 s prod / 60 s stage) |
| `HMSSyncManager` | JS module (`sync_manager.js`) | Manages the IndexedDB queue, session cache, and online/offline flush cycle |
| `validateForm(form)` | Function (`main.js`) | Client-side form validation — required fields, email format, password complexity, phone format (`^0\d{9}$`), alphanumeric fields (`data-alphanumeric`), SA ID |
| `validateSaId(id)` | Function (`main.js`) | Client-side SA ID validation mirroring `validate_sa_id()` — returns error string or `null` |
| `initSaIdLiveValidation()` | Function (`main.js`) | Attaches blur-time SA ID validation to all `input[data-sa-id]` fields; calls `populateFromSaId()` on valid input |
| `populateFromSaId(id, form)` | Function (`main.js`) | Extracts DOB and gender from a valid 13-digit SA ID and auto-fills the nearest `input[name=dob]` and `select[name=gender]` |
| `flashField(el)` | Function (`main.js`) | Briefly highlights a field with a green flash (`.field-autofilled`) after auto-population |
| `setRequiredTooltips()` | Function (`main.js`) | Walks all `[required]` fields and sets a native `title` tooltip from the associated label |
| `attachRequiredTooltip(field)` | Function (`main.js`) | Sets the `title` tooltip on a single field; called by `setRequiredTooltips()` and dynamic form toggles |

---

## Requirements Coverage

Traceability against the project's Functional and Non-Functional Requirements (Tables 2-7 / 2-8). "Where implemented" points at the controller(s) primarily responsible.

### Functional Requirements

| Req. ID | Requirement | Status | Where implemented |
|---|---|---|---|
| FR-01 | Patient Data Management | ✅ In scope | `register_patient.php`, `patient_profile.php` |
| FR-02 | Medicine/Prescriptions Issuance | ✅ In scope | `prescriptions.php` (issue/dispense/cancel) |
| FR-03 | Appointment Scheduling | ✅ In scope | `appointment_book.php`, `appointment_list.php` |
| FR-04 | Billing Management | ✅ In scope | `billing.php` |
| FR-05 | Risk Management Tracking (Inventory + HR) | ✅ In scope | `inventory.php` (stock alerts/reorder), `staff_shifts.php` (shift/staffing) |
| FR-06 | Doctor/Nurse Management | ✅ In scope | `staff_profiles.php` |
| TR-01 | Data Migration (existing fragmented records → HMS DB) | ❌ Not started | `setup.php` only seeds demo data — no import/ETL tool exists for real legacy records. Added to Future Dev (#7). |

### Non-Functional Requirements

| ID | Scope | Status | Where implemented |
|---|---|---|---|
| NFR-1 | Security and Compliance | ⚠ Partial | RBAC, CSRF, audit log, password policy, session hardening all in scope (see Security Notes). 2FA and `medical_notes` encryption are not yet applied — see Known Deferred Items. Formal POPIA/NHA/HPCSA compliance sign-off has not been done — added to Future Dev (#9). |
| NFR-2 | Performance | ⚠ Partial | Addressed structurally (prepared-statement reuse, `LIMIT` caps, single PDO connection, cache-first static assets — see Code Efficiency) but not measured. No load/benchmark testing exists — added to Future Dev (#10). |
| NFR-3 | Reliability | ✅ In scope | Offline-first PWA — Service Worker + IndexedDB queue + automatic flush (see Offline Support) |
| NFR-4 | Usability, Interoperability | ⚠ Partial | Usability in scope — consistent design system, `novalidate` + programmatic validation, mandatory-field indicators. External interoperability (secure API gateway for sharing data with outside healthcare systems) is not built — added to Future Dev (#8). |
| NFR-5 | Scalability | ⚠ Partial | Single PDO connection per request, role-filtered/limited queries. 3rd-party integrations (email service, Azure Blob Storage) are not yet built — tracked as Future Dev #1 and #3. |
| NFR-6 | Auditability | ✅ In scope | `audit_log.php` — immutable, paginated, CSV-exportable; every mutation writes via `write_audit_log()` |

---

## Known Deferred Items

| Item | Status |
|------|--------|
| AES-256 encryption on `patients.medical_notes` | Deferred — field exists, encryption not yet applied |
| 2FA authentication | Deferred — to be implemented last |
| Unit / integration tests | Deferred — no test suite exists yet |


## Future Development changes
| Item No. | Description | Requirements Link |
|----------|-------------|-------------------|
| #1 | Email 3rd Party service to send email notifications on accounts and changes | NFR-5 (3rd-party integrations for added functionality) |
| #2 | File uploads — user profile documents (ID/POA/etc.) | FR-01 (Patient profile and records management) |
| #3 | Azure Blob Storage service handler for file uploads, with back-up plan | NFR-3, NFR-5 (Reliability/continuity + infrastructure scalability) |
| #4 | Generic information landing page | NFR-4 (Usability, consistent branding/UI) |
| #5 | NFS data store as a DR site / recovery site | NFR-3 (Reliability — continuity during downtime) |
| #6 | Detailed plan for device security and mobile security for mobile devices (enabling offline-sync) | NFR-1, NFR-3 (Security & Compliance + Reliability/offline continuity) |
| #7 | Data migration tool/ETL to import existing fragmented physical & digital records into HMS (`setup.php` only seeds demo data today) | TR-01 (Data Migration) |
| #8 | Secure external API gateway for data sharing/integration with outside healthcare ecosystems | NFR-4 (Interoperability) |
| #9 | Formal POPIA/NHA/HPCSA compliance audit and legal sign-off | NFR-1 (Security and Compliance) |
| #10 | Performance/load testing and benchmarking suite | NFR-2 (Performance) |