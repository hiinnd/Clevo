# CLEVO REST API integration

Subsequent integration phases (2026-11-16): [NOTIFICATIONS.md](NOTIFICATIONS.md), [TRACKING_ARCHITECTURE.md](TRACKING_ARCHITECTURE.md), and [NOTIFICATION_TEST_RESULTS.md](NOTIFICATION_TEST_RESULTS.md) describe migrations 007/008, current delivery/tracking policy and test evidence. The API now has 159 operations across 138 paths. Earlier phase counts and worker/null-transport behavior below are historical where superseded.

Subsequent payment phase (2026-11-16): [PAYMENT_INTEGRATION.md](PAYMENT_INTEGRATION.md) and [PAYMENT_TEST_RESULTS.md](PAYMENT_TEST_RESULTS.md) are authoritative for migration 006, the approved new-booking 5% commission in integer JOD fils, sandbox hosted checkout, verified funding, refunds, ledger and commercial blockers. The API now has 145 operations across 125 paths. Earlier phase counts and retained financial rules below are historical where superseded.

Subsequent booking phase (2026-11-16): [BOOKING_WORKFLOW.md](BOOKING_WORKFLOW.md) is authoritative for migration 005, configured interval capacity, status events, post-commit notification delivery and later test results. Earlier counts/availability descriptions below are historical where superseded.

Implemented API phase, 2026-11-15. PHP/mysqli, MySQL, existing role aliases and the PHP Admin Dashboard remain in place. No Flutter project, new backend framework, production migration or real provider settlement was introduced.

The runtime exposes **127 method/path operations**, including preserved authentication and the `/driver/tasks` compatibility aliases. See the generated [endpoint inventory](API_ENDPOINT_INVENTORY.md), [OpenAPI 3.1.1 specification](openapi.v1.json), [examples](API_EXAMPLES.md) and [executed test results](API_TEST_RESULTS.md). The inventory distinguishes added, modified and unchanged business behavior. The specification uses the [OpenAPI 3.1.1 standard](https://spec.openapis.org/oas/v3.1.1.html); it describes implemented routes, including explicit unavailable-provider errors, rather than promised integrations.

## Install and deploy incrementally

1. Follow [SECURITY_CHANGES.md](SECURITY_CHANGES.md) for protected environment configuration, independent APP/JWT secrets, HTTPS, SMTP, storage and credential rotation. Do not put real credentials in requests committed to source, logs or reports. The API uses the same identity/database/services as the PHP pages.
2. Follow [DATABASE_BACKUP_RESTORE.md](DATABASE_BACKUP_RESTORE.md): back up, restore into a disposable instance, inspect the actual deployed schema, freeze writers and rehearse migrations. Existing migrations 001–003 are unchanged. `php scripts/migrate-database.php --apply --maintenance` now also applies **004_mobile_api.sql** using the existing checksum/step journal. No production data was migrated by this phase.
3. Migration 004 adds `saved_addresses`, `payment_sessions`, `refund_requests`; optional dispute category/booking linkage on `support_tickets`; and `booking.DriverAcceptedAt`. All legacy columns/identifiers/data remain. New FKs use RESTRICT; a generated unique key permits one default active address per user. No historical payments/assignments/addresses are fabricated.
4. Apply the additional per-table runtime grants in `deploy/database-grants.sql.example`. Runtime needs SELECT/INSERT/UPDATE on the three new tables, retains no DDL or financial/catalog/booking DELETE, wallet ledger INSERT/SELECT only and audit-log INSERT only. Use a different identity for migration, backups and privileged maintenance. Adding a table does not automatically update a pre-existing production identity's grants.
5. Deploy the PHP artifact after migrations. `/api/v1/index.php` checks for completed migration 004 before accepting API traffic; base web bootstrap additionally requires migration 005 in the subsequent booking release. Missing configuration/schema returns a generic unavailable response rather than attempting schema changes during requests.
6. Schedule `php scripts/expire-bookings.php` **each minute** with a protected environment. It calls the shared, transactional expiry service for up to 200 candidates per invocation; run additional batches if backlog metrics require them. Monitor nonzero exit status and reconcile ambiguous legacy payment records. Schedule the existing encrypted mail worker separately. HTTP GETs are not a substitute for workers.
7. Enable rewrite rules and forward `Authorization` into PHP. Root `.htaccess` now explicitly forwards the header through rewriting; the Apache integration test verifies actual bearer authentication. Without rewriting, `/api/v1/index.php/me` remains available. Do not expose `.env`, private storage, SQL dumps, migrations, source-control metadata, tests, dependencies or developer documentation as public downloads.
8. Set the client's base URL to **`${APP_URL}/api/v1`**, including any deployment subdirectory. The OpenAPI server entry `/api/v1` is a root-host example; override the server/base URL for subdirectory deployments. Configure trusted reverse-proxy TLS and real-client-IP handling at the server. PHP ignores untrusted forwarded IP headers. `TRUSTED_PROXIES` authorizes forwarded protocol only; it does not automatically establish trusted client-IP forwarding.

Local validation used PHP 8.2.12 and MariaDB 10.4.32. Repeat restored-copy tests on the exact supported production MySQL release, SQL mode, proxy/SAPI and deployment artifact. Local pass counts do not certify production load, patched hosting, offsite recovery or provider accounts.

## HTTP contract

Use HTTPS in production. Send `Authorization: Bearer <access token>` and `Content-Type: application/json`. JSON bodies are limited to **16 KiB**. Registration with an owner verification file and image uploads use multipart form data; the shared upload service validates real MIME, extension, dimensions, uploaded-file provenance and a 5 MiB file limit, uses generated names and private non-executable storage. File-validation failures return controlled 422 errors. A JSON oversized body returns 413.

Success retains the existing envelope and adds correlation/pagination metadata:

```json
{
  "status": "success",
  "data": { "bookings": [] },
  "message": "",
  "meta": {
    "request_id": "00000000000000000000000000000000",
    "pagination": { "page": 1, "per_page": 25, "total": 0, "total_pages": 0 }
  }
}
```

Errors retain `status/data/message` and add a structured code/details object:

```json
{
  "status": "error",
  "data": [],
  "message": "validation_failed",
  "error": { "code": "validation_failed", "details": { "body.latitude": "invalid_or_missing" } },
  "meta": { "request_id": "00000000000000000000000000000000" }
}
```

`X-Request-ID` matches the response metadata and is generated by the server, never trusted from an incoming header. Unexpected route failures log event/request ID/exception **class**, and return 500 `internal_error`; bootstrap/configuration failures return 503. No SQL, credential, request body, bank snapshot or exception message is included in error output. Empty data remains `[]` for compatibility. Error details may be an empty array/object; populated validation details map field paths to stable codes. Render text as text, not HTML.

Status conventions: 200 read/update/archive; 201 new address/service/driver/support/review/subscription/session/refund/withdrawal/plan and registration; 204 CORS preflight; 400 invalid JSON; 401 invalid/missing/revoked bearer; 403 role denied; 404 missing or inaccessible object; 405 wrong method with `Allow`; 409 state/idempotency/funds conflict; 413 JSON too large; 415 wrong media type; 422 invalid fields; 429 throttled with `Retry-After`; 500 unexpected internal error; 503 unavailable provider/configuration. **Booking creation retains its previously published 200** for compatibility. Idempotent replay returns the original operation's status convention.

Two documented download routes return binary content on success: `GET /me/verification-document` and `GET /admin/users/{id}/verification-document`. Their errors use the JSON envelope. They enforce self/admin policy, MIME allowlists, `nosniff`, attachment/sandbox headers and never accept a request-controlled filesystem path. Public profile/station images use absolute application URLs; verification documents are not public images.

## Authentication and permissions

The authentication flows and password/OTP/refresh semantics in [AUTHENTICATION_API.md](AUTHENTICATION_API.md) remain. Public auth endpoints and approved station/plan catalog are available without a token. A malformed explicit bearer is rejected even on catalog routes. Browser cookies and allowed CORS origins never authenticate API requests.

- Customer: own bookings, prices, payments, wallet, addresses, reviews, inbox and support.
- Owner: only owned station resources, services, prices, availability, assigned drivers, bookings, reviews, earnings/bank/withdrawals. Approval/archival restrictions remain authoritative.
- Driver: only assigned jobs at the driver's own approved station. A task-code session is additionally bound to one booking; it cannot inspect another job even for that same driver. It permits job routes and read-only own-profile access, and cannot change recovery identity, browse account inbox/finances/addresses/support, or revoke other device sessions. Active detail returns minimum customer contact/vehicle/location data needed for the task. History omits private historical address/contact data.
- Admin: authorized account/station/booking oversight, nominal financial reports, plan/subscription/support/dispute/refund/withdrawal management. Users cannot choose privileged roles during registration/profile edits. Admin status changes prevent self-restriction and removal of the last active administrator, enforce verification for activation and revoke restricted accounts' sessions. There is no arbitrary unlogged balance-edit API.

`User`/`user` normalize to customer; `Admin` normalizes to admin. DTOs exclude passwords, reset/verification hashes, refresh credentials, driver task-code hashes, internal session metadata and unrelated bank/private information. Bank snapshot detail is an explicit admin route with an audit event, not part of public/owner earnings DTOs. New actor/status decisions and financial actions are audited. This is not a complete external tamper-proof security logging system.

For a persistent driver account/history, provision the driver with an optional email or call `POST /owner/drivers/{id}/invitation` for a task-only driver that has no enrolled recovery email. The recipient verifies the queued link, requests/validates a password-reset OTP and sets a private strong password. Password login then creates normal driver account scope, still limited to assigned station jobs. Owners cannot replace an already enrolled recovery identity through invitation. A task code alone cannot bootstrap this enrollment.

Native clients should protect tokens with OS-backed secure storage. Serialize refresh attempts per session: refresh is one-time rotation, and reuse revokes the family. On logout, verified recovery-email change, password reset or restriction, discard revoked credentials and reauthenticate. A completed task invalidates its task-code sessions; a full password-authenticated driver session may still read scoped history. Email changes remain pending until a single-use verification link is consumed; verification revokes existing sessions.

## Pagination, filtering and value types

List routes return `meta.pagination`. `page` defaults to 1 (maximum 10,000); `per_page` defaults to 25 (1–100); `direction` is `asc` or `desc`, default descending. Sorting uses an allowlist and an ID tiebreaker, never caller-provided SQL. Metadata totals count only the authorized scope. The older `after_id` parameter remains on bookings/notifications as an additional lower-bound filter; new clients should use page/per_page consistently.

| Resource | Sort keys | Filters/meaning |
|---|---|---|
| Stations/search | `id`, `price`, `rating`, `name`, `distance` when coordinates supplied | `query` searches station name; `location` searches location text; `status=Active/Busy/Closed`; `mode=station/home/delivery`; `min_price`, `max_price` apply to the cheapest eligible service; `min_rating` 0–5; paired `lat/lng`; `radius_km` 0.1–250 requires coordinates. Set `direction=asc` for nearest/cheapest first. |
| Station/owner services | `id`, `price` (public service listing also `name`) | Station/ownership/archival scope is mandatory. |
| Bookings, owner bookings, driver jobs/history, admin bookings | `id`, `date`, `price` | `status` must be an existing lifecycle value; customer/owner/driver scope cannot be overridden by query parameters. History includes known terminal states; driver active lists include only active assignments. |
| Wallet/payment history | `id`, `amount`, `created_at` | Own wallet/receipt scope; payments additionally allow `status=pending/recorded/refunded`. |
| Addresses/drivers/reviews | `id`, respectively `label` / `name` / `rating` | Owner/user/station scope is mandatory. |
| Inbox/support/refunds/subscriptions/plans/withdrawals | `id`, relevant `created_at`, `end_date`, `price`, `amount` or `requested_at` as documented by the specific route | Notifications support `after_id`; admin support supports `status=New/Replied/Closed`. Admin users support `role`, `status`, `query`; admin stations support `status`; financial reports support inclusive civil `from/to` dates. |

Coordinates and money in database DTOs can be decimal strings; legacy numeric IDs/flags may be numeric strings. New action responses use integer IDs. Parse safely rather than casting JSON numbers blindly. Request IDs must be positive bounded integers; request booleans must be real JSON booleans, not `"1"`. Prices accept two-decimal strings/numbers and reject sub-cent inputs. Fields not supported by a new mutation are rejected; booking creation retains compatibility by ignoring non-authoritative extra price/commission/payment markers.

Server event timestamps are UTC. Legacy SQL datetime strings are returned as UTC without a `Z`; interpret them as UTC explicitly. Booking date/time and station hours are **civil values** in `BOOKING_TIMEZONE` (default `Asia/Amman`); do not reinterpret them as UTC. New bookings/reschedules also persist an explicit UTC instant. Arabic/English catalog descriptions and notification text remain available. Themes and client language preferences remain UI concerns; no PHP UI redesign was performed.

## Sensitive writes and retries

Require `Idempotency-Key` on the operations marked in the inventory. Use 16–128 ASCII letters, digits, `_` or `-`; generate an unpredictable value for **one intended operation** and preserve it for network retries. The server scopes keys to authenticated user/operation and stores hashes plus a minimal committed response in the same transaction as the mutation. Changed normalized input with a committed key returns 409. New route wrappers canonicalize object-key order. Failed transactions do not consume a key.

Cached responses describe the original operation; use a GET for the latest resource state. A new key is not a mechanism to charge an already-paid booking, repeat a refund, restore washes twice or award completion points again. For changed user intent, use a new key after resolving the previous outcome. Multipart image keys bind to the file fingerprint; the same image retry reuses its committed result. Generated private files are retained by the existing upload storage lifecycle; an orphan-file retention/cleanup policy is an operational follow-up, not an automatic deletion of historical files.

Notifications' read markers are inherently idempotent and do not require a key. Login/refresh/challenge operations have their own one-use/replay rules. Driver-code issuance deliberately rotates a credential, is owner-scoped/rate-limited, returns plaintext only in the authorized response and **does not store it in idempotency receipts or notifications**. Retrieve the grant separately after assignment; it is valid for 15 minutes and one exchange.

Global API limits currently use 240 requests/IP/minute, 120/user/minute and 30 sensitive mutations/user/minute, plus existing action-specific auth limits and 10 driver-code issues/owner/minute. Honor 429/Retry-After. GPS updates should use a bounded interval (for example at least five seconds) and client backoff. Tune limits using real traffic, proxy/NAT topology and load tests before launch; test counters are deliberately reset between integration groups so contract coverage does not masquerade as a load test.

## Booking, driver and money workflows

1. Read catalog/services and station availability; create a booking with service IDs, location mode and a future civil date/time. Server catalog rows set prices, commission and owner snapshot. Read the booking/price breakdown before payment. `total` is nominal service value; `payable` reflects points/plan coverage, `outstanding` is remaining internal cash due, and `settlement_state` distinguishes recorded/pending/unpaid/refunded/legacy reconciliation. Unknown legacy amounts remain null rather than being presented as settled. An empty payment marker means unpaid; client-supplied status is never proof of settlement.
2. Owner accepts/rejects Pending bookings. Delivery assignment validates an active same-station driver, retains assignment history and revokes old task grants. Reassignment is limited to unstarted Pending/Accepted jobs; it cannot transfer an in-progress task silently. Owner deactivation of a driver with active jobs is refused until jobs are completed/reassigned. Privileged emergency account restriction remains a separate admin action.
3. Create an **internal** payment session with `booking_id`, `method=wallet/cash/plan`, optional `use_points`. It snapshots amount/points and expires after ten minutes, **without spending or reserving funds**. Confirm it using its ID and a stable request key. Confirmation locks/rechecks the quote, booking and balance/entitlement, and performs the existing shared payment transaction. If points changed, 409 `payment_quote_changed` requires a new session/consent. Expired sessions cannot confirm.
4. Wallet, points and plan receipts are recorded atomically. Cash is allowed only at station and stays pending until authorized completion, representing the owner's manual collection attestation. Existing `POST /bookings/{id}/payments` is retained for older clients; its `redirect_visa` result is not settlement. New clients should use explicit sessions. Station acceptance and remote-payment ten-minute deadlines are enforced in shared mutation services as well as the expiry worker, so Flutter does not depend on page visits.
5. Work execution/completion requires a matching structured payment receipt (user/method/amount), or a consumed same-customer plan entitlement. Legacy payment markers without proof return `legacy_payment_requires_reconciliation`; they cannot mint completion rewards or owner payouts. A current owner cash-collection attestation for an active station booking may create its internal cash receipt at recording time, not fabricate a historical capture timestamp.
6. Drivers accept paid jobs into `In Progress`, then use the existing legal graph (`Arrived`/`Pickup` -> `Washing` -> `Returning` -> `Completed`). Invalid skips/terminal revival are rejected. GPS writes update the current cache and append authorized samples transactionally. Owner completion for station/home service uses the same single-award/payout logic as PHP pages.
7. Subscription purchase uses the shared wallet debit/entitlement/snapshot service. Product retirement preserves subscriptions/history. Owner withdrawal reserves the available wallet balance and snapshots bank data once. Admin withdrawal `paid` is an **authorized manual status attestation**, not a bank transfer; rejection restores the reserve once. Bank format validation/manual review is not bank-account ownership verification.

There is no configured payment gateway or bank-transfer provider. `visa` payment-session/subscription purchase returns **503 `payment_gateway_not_configured`**; no fake hosted URL, capture, webhook settlement or wallet top-up is manufactured. The legacy card page no longer displays success before the backend accepts an operation. Future gateway work must validate signed provider events, ownership, amount/currency and unique provider references before authorizing ledger mutations. Do not collect PAN/CVV in this API.

Refund requests are **requests**, one per booking; they do not immediately credit money. The admin may approve a structured wallet/points/plan receipt before service execution, reverse recorded owner earnings and restore the entitlement/funds once. Already-processed requests conflict. Completed/in-progress service, cash or external-provider refunds return `manual_refund_required` (409) and require dispute/provider reconciliation. Insufficient owner funds abort the whole refund. A rejected request can be discussed through a booking-linked support dispute; the API does not generate repeated refund records to bypass review.

Payment history includes structured receipts introduced in the database phase. Legacy booking payment flags are not fabricated into verified receipts. Legacy bookings and wallet transactions remain readable; ambiguous monetary refunds require reconciliation. Financial reports use booking civil dates for nominal completed GMV/commission and UTC receipt creation dates for internal receipt summaries; these are different event bases. They are explicitly **not reconciled accounting statements**. Existing two-decimal storage and business commission/points/plan subsidy rules remain; no new currency policy or double-entry accounting system is claimed.

Availability now enforces configured interval capacity; station and pickup/return share the wash pool, and home teams have a separate pool. Query an exact date/time and service IDs for a live quote; creation/reschedule recheck it transactionally. See BOOKING_WORKFLOW.md for policy setup and legacy reconciliation. Maps/GPS return actual coordinates; there is no new maps-provider backend, push tracking channel or Flutter SDK in this phase.

## Support, disputes and retained history

Authenticated users create/read/reply to their own support requests. `category=dispute` requires a booking visible to that role. Administrators use support/dispute aliases to read, reply and close requests; closed API threads reject new replies. Messages use the existing support-message service and legacy fallback. Legacy preview TEXT is bounded while full new messages remain in the message table. The legacy PHP five-minute cleanup no longer deletes conversations: it closes expired answered chats and retains messages; do not assume the API schedules that PHP compatibility behavior. Normal API closure is explicit through the admin status endpoint.

Reviews require the customer's own Completed booking, one review per booking; only the station owner can reply. Notification reads remain per-recipient for broadcasts. Address deletion, service deletion, station deletion, driver deletion and plan deletion are archive/deactivation operations preserving history. The dashboard remains usable with the expanded schema and restricted runtime grants.

## Reuse and file layout

| Component | Implementation/reuse |
|---|---|
| Front controller/auth | `api/v1/index.php`, extracted preserved `api/v1/auth.php`; existing security/JWT/session/recovery helpers |
| Route policy and schemas | `includes/api/routes.php`, `contracts.php`, `router.php`, `http.php`; one source for routing/RBAC/input requirements and documentation inventory |
| HTTP modules/read DTOs | `includes/api/catalog.php`, `bookings.php`, `customer.php`, `owner.php`, `driver.php`, `payments.php`, `admin.php` |
| Existing financial/booking services | `includes/commerce.php`, `database.php`, `booking-helpers.php`, `plan-helpers.php`, `security/driver.php`; reused for quotes, atomic payment/refund/plan/withdrawal, cancellation/reschedule, completion and GPS |
| Extracted shared web/API operations | `includes/marketplace.php`; owner service/price and driver provisioning, assignment/rejection, profile changes, review policy, withdrawal processing. Corresponding owner/profile/financial PHP adapters now call shared services where extracted. |
| Existing support/upload/mail | `support-helpers.php`, `security/uploads.php`, `security/mail.php`; API-specific orchestration adds scope/transaction/idempotency without replacing storage/transport |
| Documentation/validation | `scripts/generate-api-docs.php`, `docs/openapi.v1.json`, inventory; locked **development-only** Redocly CLI under `tests/openapi`, not a Node.js backend dependency |

Regenerate artifacts with `php scripts/generate-api-docs.php`. Run `powershell -NoProfile -ExecutionPolicy Bypass -File tests/run-isolated.ps1` for guarded SQL/HTTP/Apache tests. Validate the full standard using `npm.cmd --prefix tests/openapi ci --ignore-scripts --no-audit --no-fund` then `npm.cmd --prefix tests/openapi run validate` (a supported Node release is needed only for development tooling). PHP-only contract tests also compare every registry operation, roles, headers, success statuses, references and published request examples.

Tests/known limits are in [API_TEST_RESULTS.md](API_TEST_RESULTS.md). Live MySQL/SMTP/provider/bank/FCM integrations, production proxy topology, production-data reconciliation, resource capacity and load/penetration testing remain release gates. No real records were reset or deleted and no production deployment was performed. This phase stops at tested PHP API/documentation; Flutter is not built.
