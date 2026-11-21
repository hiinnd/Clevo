# CLEVO Admin integration

Phase date: 2026-11-21. The existing PHP, MySQL/MariaDB, Bootstrap and JavaScript stack, dashboard layout, Arabic/English switching and `global_theme` preference are preserved. Customer, partner/owner and driver refer to roles in the existing **single unified Flutter application**; administration remains web based.

## Entry points and scope

Open `admin/admin-dashboard.php` with an active, authenticated administrator account. The new **Management / الإدارة** sidebar entry opens `admin/management.php`; it adds operational tables and forms without replacing the existing dashboard, station, plan, security, notification or support pages.

| Area | Implementation and integration |
| --- | --- |
| Dashboard statistics | `clevo_admin_dashboard_stats()` reads actual station approvals, customers, reviews and bookings. Creation dates use the configured business timezone. The pending card is explicitly station approvals. Cash commission cards use recognized ledger entries. The same data is available from `GET /api/v1/admin/dashboard-statistics`. |
| Bookings | Paginated canonical booking records, customer/station details, appointment, lifecycle status and evidence-based payment state. Admin cancellation calls `clevo_booking_transition()` so mobile status, history, compensation and notifications remain synchronized. |
| Users and owners | All account roles are visible, with verified-email state and explicit account status changes. Restrictions revoke refresh, access and web sessions. Restoring an account does not revive revoked sessions. Self-restriction, removing the last active admin and activation without required email verification are rejected. Role/password fields cannot be mass assigned. |
| Stations | Existing approval forms and admin API use one command. Approval requires a verified owner, no account restriction and an existing, readable commercial verification document. Private documents are authorized and their reads audited. Station-specific documents take precedence over the owner's legacy document. Rejection blocks the station; it does not reject the entire owner account or their other stations. Approval preserves owner-controlled opening availability. |
| Drivers | Create/edit drivers through the existing owner-domain service. New credentials are randomly generated and hashed; drivers must enroll/reset. Active jobs prevent deactivation. An owner cannot override an administrative restriction. Admin actions retain the actual admin actor in the audit log. |
| Services | Add/edit bilingual names, prices, duration and delivery modes; archive without deleting booking history. The same station eligibility, validation and catalog service used by the partner API runs on admin changes. |
| Plans/subscriptions | Existing plan forms use the shared validated command. Purchases keep their snapshots. Admin can cancel an entitlement without claiming a financial refund. This phase does not create unpaid subscriptions. Provider-funded package terms still use the existing sandbox policy contract. |
| Refunds | Legacy refund review includes a decision note and guards against repeat decisions. Unsupported cash/post-fulfillment cases require manual review. Provider refund submission uses the existing payment service, which requires a subsequent verified provider result before reversal. It never marks a submission as a successful refund. |
| Support/disputes | Mobile-created tickets and disputes use `support_tickets` and `support_messages`. Existing web replies call the same message service as mobile. Close/reopen is explicit; reading a page no longer closes a conversation after five minutes. Closed threads reject replies from either interface. |
| Earnings/settlements | Owner summaries read ledger account lines, separating sandbox payables from cash commission dues. Requests retain the existing blocked settlement state and do not imply a bank transfer. Settlement history is paginated. |
| Financial reports | Date/book filters, recognized gross, net recognized commission, refunds, daily chart, station ranking and recent ledger activity. Web, API and exports call `clevo_admin_financial_report()`. |
| Audit | Paginated admin-only audit viewer and API. Successful mutations, document reads, refund/settlement requests and exports record the actor and entity. Database privileges prohibit audit UPDATE/DELETE. |

## Shared business boundary

`includes/admin-services.php` and `includes/admin-commands.php` provide `clevo_admin_command($db, $actor, $action, $id, $input)`. Both web forms and `includes/api/admin.php` enter this command boundary. It rechecks the administrator against the database, validates the existing API input contracts, wraps changes in the existing nested transaction/savepoint service and calls the canonical marketplace, booking or finance service. There is no second database or mobile synchronization queue to reconcile: all applications read the same committed records.

New management forms use POST, session-bound CSRF tokens, stable idempotency keys and a 303 redirect. Provider submission follows the existing claim/submit flow without holding a database transaction across external I/O. Legacy admin forms remain supported and now call these shared commands. Profile image writes are prepared and audited. Query filters, page sizes and SQL sort identifiers are bounded/allowlisted; sensitive account credential columns are excluded from the management repository.

`includes/admin-queries.php` provides the management repository. `includes/admin-reports.php` provides the financial report, dashboard statistics, payment-evidence classification and CSV cell escaping. `includes/verification.php` resolves database-owned verification references. Existing booking/payment services remain authoritative for commissions, lifecycle eligibility, compensation and ledger postings.

Restricting an owner closes their stations to new bookings and revokes their authentication. Existing bookings, payment evidence and history remain intact. Restoring the account does not reopen stations automatically; availability must be explicitly restored. Station approval distinguishes a registration-pending owner from an administrative restriction recorded in `blacklist`, and cannot override the latter.

## Confirmed audit findings corrected in this phase

References point to the corrected implementation. Severity describes the prior defect, not an unresolved vulnerability.

| Severity | Confirmed defect and correction | Current evidence |
| --- | --- | --- |
| HIGH | Station approval could activate an administratively restricted pending owner. Check the locked restriction record before approval. | `includes/admin-commands.php:27`; admin integration rejection/preserved-status assertions. |
| HIGH | Restricting an owner revoked authentication but left their open stations eligible for new customer bookings. Close availability atomically with the restriction while retaining existing bookings. | `includes/admin-services.php:49`; actual customer booking API rejects new work and still returns the previous booking. |
| HIGH | Unescaped database strings in plan, notification and unban inline handlers could break attribute boundaries and permit stored script injection. JSON-encode and HTML-escape at the appropriate boundary. | `admin/manage-plans.php:151`, `admin/notifications.php:143`, `admin/security-center.php:173`; synthetic quoted/markup canaries stay inert in Chromium. |
| MEDIUM | Quoted admin names broke JavaScript on user/report pages. Use the shared JavaScript serializer. | `admin/manage-users.php:238`, `admin/financial-reports.php:373`; quoted Arabic name renders unchanged. |
| MEDIUM | Translated support option labels were submitted as status values, breaking close/reopen in Arabic. Keep canonical values and translate label text. | `admin/management.php`; real browser Arabic close/English reopen. |
| MEDIUM | Empty financial periods raised an exception in `max()` and returned 503. Supply a nonempty numeric array. | `admin/financial-reports.php:52`; future empty-period HTTP regression. |
| MEDIUM | Independently read report sections could disagree during concurrent ledger writes. Use one consistent read-only snapshot. | `includes/admin-reports.php:17`; concurrent second-connection financial test under READ COMMITTED. |
| MEDIUM | Array-shaped or malformed management fields could be coerced before validation. Reject unsupported field shapes and noninteger identifiers/durations. | `admin/management.php:15`; 422 and unchanged-database assertions. |
| LOW | CSV formula escaping also converted legitimate negative integer reversals into text. Preserve trusted integers while escaping untrusted formula-like strings. | `includes/admin-reports.php:91`; negative-number and formula-injection tests. |
| LOW | Shared sidebar labels/theme initialization differed between new management and legacy pages. Apply the existing preferences consistently. | `assets/js/admin-common.js`; Arabic/English and persisted-light-mode browser checks. |
| LOW | The legacy page avatar-upload fallback bypassed the audited profile command. Route it through the same service as the upload endpoint and parameterize the sidebar lookup. | `includes/shared_admin_sidebar.php`; real multipart upload, private storage and single-audit-event assertions. |
| LOW | The plan editor had no visible cancel action. Add a Bootstrap dismiss button using the existing bilingual cancel label. | `admin/manage-plans.php`; browser opens and dismisses the quoted-name plan without submitting it. |

Provider onboarding, external bank settlement, production-scale performance, native mobile UI and binary PDF generation remain untested or gated as described below. They are not presented as verified functionality.

## Changed files by responsibility

| Responsibility | Files |
| --- | --- |
| Shared administration commands, authorization, reads and evidence | `includes/admin-services.php`, `includes/admin-commands.php`, `includes/admin-queries.php`, `includes/admin-reports.php`, `includes/verification.php`, `includes/admin-print-report.php` |
| Web management and exports | `admin/management.php`, `admin/export.php`, `assets/css/admin-management.css`, `assets/js/admin-management.js`, `assets/js/admin-common.js` |
| Existing dashboard and management adapters | `admin/admin-dashboard.php`, `admin/financial-reports.php`, `admin/manage-users.php`, `admin/manage-stations.php`, `admin/station-approvals.php`, `admin/manage-plans.php`, `admin/security-center.php`, `admin/unban_process.php`, `admin/notifications.php`, `admin/all-support-chats.php`, `admin/support-chat.php`, `admin/upload-profile-picture.php`, `includes/shared_admin_sidebar.php` |
| Existing business/API integration | `includes/config.php`, `includes/marketplace.php`, `includes/commerce.php`, `includes/support-helpers.php`, `includes/media.php`, `includes/api/admin.php`, `includes/api/customer.php`, `includes/api/bookings.php`, `includes/api/routes.php` |
| Contract and deployment documentation | `scripts/generate-api-docs.php`, `docs/openapi.v1.json`, `docs/API_ENDPOINT_INVENTORY.md`, `deploy/database-grants.sql.example`, `docs/ADMIN_INTEGRATION.md` |
| Tests and runners | `composer.json`, `tests/admin.php`, `tests/admin-financial.php`, `tests/admin-helpers.php`, `tests/admin-browser-fixtures.php`, `tests/admin-browser.cjs`, `tests/api.php`, `tests/integration.php`, `tests/run-isolated.ps1` |

No production migration was executed, no real records were reset, and no existing role/dashboard was removed.

## Financial meanings

- All new report amounts are integer **JOD fils**, with exponent 3. Display divides by 1000 and retains three decimal places. Legacy catalog/wallet prices keep their existing storage precision.
- The approved existing policy remains 500 basis points on the post-discount services/fees basis, rounded half up to fils through `clevo_finance_commission()`. The dashboard does not recalculate commission from nominal booking totals.
- `cash` and `sandbox` are separate report books. Fixture books and unproven legacy payments are excluded. Cash is an owner's collection attestation and commission receivable; it is not independently reconciled bank cash. Sandbox payments are test money.
- Selecting cash or opening checkout creates no recognized revenue. Capture creates deferred funds; fulfillment recognizes income. Verified refunds reverse commission and owner payable through compensating ledger entries. Replays do not double count.
- Gross is recognized gross **before refunds**; refunds are shown separately. Net commission includes refund reversals in the selected period. Owner payable movement is a period movement, while the owner earnings page shows current balances. Top stations rank gross before refunds. These measures are not net profit or bank settlement.
- Date filters are inclusive civil dates in `BOOKING_TIMEZONE` (default `Asia/Amman`), converted into a half-open UTC interval. Charts use actual calendar dates, rather than combining every historical Monday into one point. Ledger headers are aggregated once before summing line accounts.
- Admin booking payment labels are `unverified`, `cash_attested`, `verified_sandbox`, `refunded_sandbox`, `refund_due_sandbox` or `verified_subscription_sandbox`. They depend on linked receipts/journals and verified provider or subscription evidence, rather than a `Payment_Method` or legacy `recorded` flag alone.

Cards, totals, charts and history in a report use one read-only repeatable-read snapshot, including when the connection normally uses READ COMMITTED. A second real database connection tests concurrent recognition during a report. Dashboard commission cards request totals only; daily chart aggregation streams journal rows rather than loading the entire period into a PHP array. Report queries still scan the selected period; production-scale load testing remains outstanding.

`GET /api/v1/admin/financial-reports?from=YYYY-MM-DD&to=YYYY-MM-DD&book=cash|sandbox` now returns the ledger-based `FinancialReportDTO` with `totals`, `daily`, `top_stations`, `history`, currency/precision and timezone. It replaces the previous nominal-GMV response. Recent history is capped at 50; period totals and daily aggregates cover the whole selected period. Neither customer nor partner mobile code consumes the former admin-only nominal report DTO.

## Exports

The existing export control now submits to `admin/export.php`. It requires current admin authorization and CSRF, independently validates dates/book/format, writes an audit event with the actor, period and book, and sends `no-store` responses.

- **CSV:** UTF-8 BOM for Arabic spreadsheet compatibility, standards-based quoting, formula-prefix protection, and complete period totals/daily aggregates. Columns ending in `_minor` are integer fils. Trusted negative integer reversals remain numeric; formula-like untrusted strings are escaped. The export contains no customer contact details or bank account information and is independent of the recent-history table limit.
- **PDF where supported:** the same control offers an authenticated Arabic/English print view with **Print / Save as PDF**. This uses the browser's PDF capability; no server PDF engine is installed or added. Authorization and report calculation happen before rendering the print view. Tests verify the print HTML and totals; they do not drive a browser print dialog or validate a generated binary PDF.

## API additions

The generated `docs/openapi.v1.json` and endpoint inventory describe these admin-only routes:

```text
GET    /admin/dashboard-statistics
GET    /admin/services
POST   /admin/stations/{id}/services
PATCH  /admin/services/{id}
DELETE /admin/services/{id}
GET    /admin/drivers
POST   /admin/drivers
PATCH  /admin/drivers/{id}
GET    /admin/audit-logs
```

Paths above are relative to `/api/v1`. Existing admin booking, user, station, plan, subscription, refund, dispute, support and finance routes continue to use the common command services. All writes require bearer authorization and an idempotency key according to the generated contract. The service/audit repository endpoints return fixed 25-record pages with `rows`, `page` and `has_more`; driver listing retains normal API pagination.

## Setup and operational limits

1. Use the existing environment and all migrations through `008_notifications`; this phase requires no schema or data rewrite.
2. Apply the runtime grant change in `deploy/database-grants.sql.example`: `audit_logs` needs **SELECT, INSERT**, with no UPDATE/DELETE. The isolated test runtime uses those exact restrictions. Do not grant broad write/delete privileges to make the viewer work.
3. Keep private verification storage outside the public document root in production and retain the existing Apache denies. Missing legacy documents must be supplied through the established verification flow before approval.
4. Production payment processing, stored-value operations and bank settlements remain gated by the existing provider/regulatory configuration. No production payment account, real refund, external message or bank transfer was activated by this phase. The UI exposes blocked/unverified states rather than bypassing those controls.

## Verification

Run from the repository root using the installed XAMPP PHP/MySQL binaries:

```powershell
npm.cmd install --prefix .security-test/browser-tools --no-audit --no-fund --ignore-scripts playwright-core@1.65.1
powershell -NoProfile -ExecutionPolicy Bypass -File tests/run-isolated.ps1 -AdminBrowser
```

The runner creates a uniquely named database in `.security-test/mysql`, validates the dedicated datadir, applies the real migrations, grants a restricted runtime principal and starts the real PHP HTTP API. It does not use the application's live database. Test payment evidence uses local transport doubles and is explicitly sandbox/test data; it is not certification against an onboarded PayTabs account.

Executed environment: Windows/XAMPP, PHP 8.2.12 and MariaDB 10.4.32. This verifies the existing local stack; deployment on a different MySQL/PHP version still requires its own restored-copy smoke and compatibility checks.

The optional browser suite uses installed Google Chrome through Playwright Core (test tooling only, no Node backend). Override `CLEVO_ADMIN_BROWSER_EXECUTABLE` for another compatible Chromium executable. Omit `-AdminBrowser` to run the PHP suites without browser tooling. `-AdminOnly -AdminBrowser` runs the focused admin suites with disposable schema/auth setup. Browser fixtures, cookie jars and synthetic screenshots remain under ignored `.security-test`; never publish private fixture files.

The runner includes:

- `tests/admin.php`: real PHP sessions, every admin landing page, customer/owner/driver denials, CSRF, export authorization, query injection, token revocation/restoration, approval/document gates, mobile booking creation and admin cancellation, shared catalog/driver/plan updates, support/dispute replies and close/reopen, audit access and administrative driver restriction protection.
- `tests/admin-financial.php`: real cash booking/payment/completion, duplicate completion, integer precision, chart/card agreement, date boundaries, unproven legacy exclusion, sandbox deferred recognition, verified compensating refunds, owner earnings, blocked settlements, API/web/CSV agreement, formula injection, Arabic and bilingual PDF print views.
- Existing authentication, API contract/HTTP, booking, payment/webhook, driver, notification, database/concurrency/recovery and isolated Apache suites.
- `tests/admin-browser-fixtures.php` and `tests/admin-browser.cjs`: authenticated browser interactions against the same disposable backend, Arabic close/English reopen of a dispute, role-preserving navigation, stored light mode, quoted names, inert stored-XSS canaries, plan editing, actual ledger chart values, CSV download, CSRF-protected bilingual print navigation and compact layout. Five synthetic screenshots support visual review.

Backend integration here means live HTTP requests against the customer and partner mobile API routes and shared MySQL records, plus real headless Chromium interaction with the PHP dashboard. Native Android/iOS UI tests were not run in this phase. Browser tests exercise the installed Chrome environment and existing CDN scripts; they do not certify every client network, production infrastructure, a payment-provider account, or a generated binary PDF.

### Final executed results — 2026-11-21

The complete `tests/run-isolated.ps1 -AdminBrowser` run completed successfully. No suite failures remain.

| Suite/check | Result |
| --- | --- |
| Admin security and shared application workflows | **158/158 passed** |
| Admin financial reports, recognition, refunds, concurrency and exports | **66/66 passed** |
| Real Chromium dashboard interactions | **52/52 passed**; five screenshots reviewed |
| Security units | 47/47 passed |
| Credential source scan | 153 application files; zero findings |
| OpenAPI/runtime contracts | 958/958 passed |
| Authentication integration | 172/172 passed |
| Versioned API HTTP integration | 1,821/1,821 passed; 647 requests, 157 runtime operations |
| Booking workflows | 121/121 passed |
| Financial/webhook integration | 161/161 passed; local provider double only |
| Driver/logistics | 22/22 passed |
| Notifications | 28/28 passed; no external messages |
| Database migration/concurrency/recovery | 178/178 passed; 477 SQL statements prepared, zero fragment-review candidates |
| Isolated Apache rules and bearer/API | 16/16 and 2/2 passed |
| PHP syntax | 160 repository PHP files checked; zero failures |
| JavaScript syntax | Both new admin assets and the browser test parse successfully |
| Git whitespace check | Passed with the repository's normal line-ending configuration |

Early focused validation failed on empty-period financial rendering, quoted-name JavaScript and the missing plan-modal dismiss action. These defects were corrected and the complete run above passed. Browser fixture creation and plan ID matching also required test-harness corrections; they are not application defects. Apache's configuration test writes `Syntax OK` to stderr, which the outer Windows PowerShell transcript labels as a native-command error record; Apache and the aggregate runner both completed successfully.

The screenshots confirm the retained dashboard style, Arabic RTL/dark management, English LTR/light management and financial reports, compact navigation, and the English print report. Stored markup canaries appear as literal text. Tests do not prove readiness for production deployment, real provider settlement, other database versions or native mobile UI. No binary PDF was generated or certified.

This work stops at the admin integration phase. No production deployment, schema reset, real payment/refund, customer message or bank transfer was performed.
