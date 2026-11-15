# CLEVO database changes

Subsequent booking phase (2026-11-16): [BOOKING_WORKFLOW.md](BOOKING_WORKFLOW.md) is authoritative for migration 005, configured interval capacity, status events, post-commit notification delivery and later test results. Earlier counts/availability descriptions below are historical where superseded.

Subsequent API migration `004_mobile_api.sql` and its deployment/grant requirements are documented in [API_INTEGRATION.md](API_INTEGRATION.md); see [API_TEST_RESULTS.md](API_TEST_RESULTS.md) for the later full regression run. Counts below retain the database phase baseline; the generated schema/ERD now include migration 004.

Phase: 2026-11-15. This is an incremental PHP/mysqli/MySQL refactor, following the authentication phase. Existing identifiers, table/column names, roles, bookings, balances, subscriptions and historical values are preserved. No production database was connected to, reset or migrated. Validation used fresh disposable databases reconstructed from the repository's actual legacy schema and populated development snapshot, plus synthetic concurrency fixtures.

Read [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md), [DATABASE_ERD.md](DATABASE_ERD.md), [DATABASE_BACKUP_RESTORE.md](DATABASE_BACKUP_RESTORE.md) and [DATABASE_TEST_RESULTS.md](DATABASE_TEST_RESULTS.md). The original [audit](AUDIT_REPORT.md) and architecture documents describe their historical baseline; this document records the subsequent implementation.

## Sources and inventory

The starting schema is the original `clevo.sql`: 17 InnoDB tables, existing PKs, unique email/phone/review constraints and declared foreign keys. The previous security migration supplies compatibility fields and authentication tables. Schema documentation is generated from `information_schema` on the migrated disposable instance, including **every column, primary/secondary/unique index and declared FK/delete rule**, rather than inferred from PHP filenames. `scripts/database-inventory.php` repeats this read-only inspection against an operator-configured deployment and redacts sensitive column defaults. It prints no customer rows or credentials.

The local database engine is MariaDB 10.4.32 bundled with XAMPP. A deployed MySQL 8.x instance and its data were unavailable. Run inventory, preflight and the same restored-copy tests on the exact production engine/version, SQL mode and collation before release. Local success is not evidence of MySQL 8.4 deployment readiness, production query latency or a reconciled production ledger.

## Versioned schema

| Version | Purpose | Deployment |
|---|---|---|
| `000_legacy_schema.sql` | Reviewed existing schema, without DROP/reset/seed statements; preserves legacy definitions | **Empty databases only**, guarded by `scripts/initialize-database.php --empty-only`; never an upgrade |
| Existing compatibility PHP + `001_auth_security.sql` | Previous phase's schema/auth expansion and credential containment | Existing `scripts/migrate-security.php`; unchanged migration/checksum sources |
| `002_database_integrity.sql` | Unique wallets/order references, query indexes, additive snapshots/archive fields, six domain tables, support sender FK | `scripts/migrate-database.php --apply --maintenance` after preflight |
| `003_required_relationships.sql` | Missing legacy FK relationships, including user/station | Same runner; equivalent existing relationships are preserved, missing relationships use RESTRICT |

`clevo.sql` is now a compatibility **schema-only** SOURCE entry point. Development INSERTs moved to `database/seeds/development.sql` and are never referenced by production migration runners. Disabled fixture credentials remain unusable. All original fixture values/relationship anomalies are retained for reproducible inspection; these examples are not reconciled financial truth.

The runner defaults to **read-only preflight**. It serializes migrations using the same database advisory lock as security migration 001, verifies SHA-256 checksums, and journals each DDL statement as `started` before execution and `applied` afterward. An ambiguous `started` step refuses automatic retry, even if the DDL may have succeeded. Inspect schema/journal against the exact SQL before an operator records a justified reconciliation. Never edit applied migration files, delete journal rows to force a retry or claim a transaction can undo DDL. MySQL DDL can implicitly commit; see the [official transaction documentation](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html).

Stop all application, worker, cron and administrative writers externally. `--maintenance` acknowledges that this has been done; it cannot prove that another deployment has stopped writing. Re-run preflight after the freeze. DDL may rebuild/lock tables and needs a measured maintenance window on a restored production-sized copy. Rollout order: backup -> restore rehearsal -> preflight -> 001 if needed -> 002/003 -> runtime grants -> PHP artifact -> role/financial checks -> reopen traffic.

## Relationships and data safeguards

| Finding | Handling |
|---|---|
| Wallet lacked uniqueness by user | `uq_wallet_user`; preflight blocks duplicate non-null owners. No arbitrary wallet selection/merging or balance deletion. Nullable historical owners remain, with a warning. |
| Order references lacked uniqueness | Nullable `uq_booking_order_number`; duplicates stop preflight. New references have 72 random bits and fit the existing 20-character field. A collision fails/rolls back instead of referencing another order. |
| Support message sender had no FK | Add RESTRICT FK after orphan preflight; null guest/system senders remain valid. |
| Legacy relationships may be absent in a deployed variant | 003 compares child/parent columns in actual metadata; preserves equivalent constraints and their existing delete rules. All required relationships are checked for orphans before DDL. |
| Catalog/account cascades could erase history | PHP service/station removal now archives; plans become inactive. Historical joins, identifiers and subscriptions survive. Per-table runtime grants deny DELETE on catalog/users/bookings/financial history. Existing cascade FKs are deliberately not dropped/replaced in this additive phase. Privileged SQL deletions remain dangerous. |
| Driver/station, service/station or subscription/customer mismatch | Preflight reports counts, including mismatched service items, wrong station-owner roles, drivers without stations and unattributed wallet entries; source seed has six cross-station driver assignments. No rewriting of historical ownership or fabricated assignment timestamps. Shared services enforce station/assignment/owner policies for new operations. Single-column FKs alone do not express these cross-row role policies. |
| Repeated loyalty rows | Preserved as existing additive buckets; lock and spend their combined balance. No false unique-user constraint or deletion of buckets. |
| Negative balances/washes, unknown roles | Reported for review; not silently normalized. Amount-sensitive operations fail safely on invalid balances. Role aliases `User`, `user`, `customer`, `Admin`, `admin`, owner and driver remain supported. |
| Mutable catalog could distort history | Existing service snapshots retained; new purchases capture price/name/wash count. New bookings capture station owner and an explicit UTC appointment instant. Existing snapshot columns stay null when historic facts cannot be established. |

There is no artificial roles/driver-account replacement table: existing `user.Role`, `user.StationID` and `booking.DriverID` remain the web/API contract. Reassignment history is separate from the current driver cache. The schema inventory includes auth, support, blacklist, bank/withdrawal and per-recipient notification-read relationships as well as marketplace entities.

## New tables justified by existing workflows

| Table | Reason and invariants |
|---|---|
| `request_idempotency` | Booking, payment, subscription purchase and withdrawal retries; `(UserID, Scope, KeyHash)` primary key, request hash and minimal committed response. Keys are stored hashed. Same key/different body -> 409. Receipt and result commit with the business operation; failures roll back the key. No automatic expiry that would allow financial replay. |
| `payments` | Structured receipt for existing booking wallet/points/plan/cash choices; one row per booking and unique non-null provider/reference pair. `recorded` denotes an internal authorized operation, not external gateway settlement. Cash starts pending; completion records cash collection. Plan coverage has cash amount zero. No historical payment is fabricated from `Payment_Method`. |
| `booking_effects` | Unique `(BookingID, Effect)` receipts for refund, payout/reversal and completion reward. Prevent replay of points/refund effects independently of description text. |
| `driver_assignments` | Existing owner assignment action now retains booking/driver/station/actor/time. Unique generated active booking key prevents two open assignments for one booking; ended history remains. Existing `booking.DriverID` is the current cache. No invented history backfill. |
| `tracking_records` | Existing authorized GPS writes append server-timed samples and atomically update `booking.Driver_Lat/Driver_Lon`. Stationary coordinates are valid; terminal/unassigned jobs cannot append samples. No retention deletion was introduced. |
| `audit_logs` | Actor/action/entity/time for touched booking, catalog, assignment, payment/refund, subscription and withdrawal mutations. No payloads, tokens, bank details or coordinates. Runtime INSERT only; privileged administration/backups still require access controls. This is not a tamper-proof external audit service or complete authentication-event log. |

New history FKs use RESTRICT. `wallet_transactions` retains every legacy description/amount and gains nullable `OperationKey`, `BookingID`, a unique operation key, booking FK and history index. New records use explicit references; ambiguous legacy descriptions are not retroactively treated as settlement proof.

## Transactions, calculations and concurrency

`includes/database.php` supplies explicit domain transaction depth and savepoints. All cooperating legacy domain boundaries use these helpers: an inner service cannot commit its caller. Authentication retains its independently tested transaction boundaries; never call a domain transaction helper inside an untracked raw mysqli transaction. New outer atomic operations retry deadlock/lock-timeout failures up to three attempts with short jitter and roll back before retrying. Callers with explicit outer transactions return failure on contention; retry using the same request key.

The shared services in `includes/commerce.php` serve existing PHP adapters and new booking/payment API writes. They lock relevant booking/catalog/subscription/loyalty rows, lock shared wallet owners in ascending user-ID order where both payer/payee are involved, and use guarded balance/wash updates. Locks stay until the outer transaction commits. Independent driver/auth/owner boundaries can still encounter deadlocks; failed operations must be retried, not assumed successful. [MySQL locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html) require an actual transaction.

Booking creation commits catalog validation, server-priced service snapshots, optional plan consumption, owner notification, audit and idempotent response together. Distinct requests may book the same civil time, preserving existing behavior: the project has no bay/resource-capacity model. No exclusive slot or station throughput limit is invented. A future approved capacity rule must acquire the station/resource lock, validate the occupied interval and reserve it in the same creation/reschedule transaction.

Wallet payment commits points redemption, guarded wallet debit, typed ledger/receipt, payment marker, owner earning, audit and idempotent response together. A ledger insertion failure rolls all of them back. A second key cannot charge an already paid booking. New wallet owner creation uses a unique-key upsert. Refunds lock the booking and use a unique effect, reverse the **recorded** owner earning, and restore wallet/points/plan entitlement once. Insufficient owner funds abort the entire refund for operator reconciliation rather than silently creating an unfunded customer credit. An old unstructured paid record requires reconciliation (409) before monetary refund; historical flags are not proof of captured money.

Existing money columns retain `DECIMAL(10,2)` and existing JOD pricing. Connections retain deployment SQL modes and enforce STRICT_TRANS_TABLES to prevent silent truncation; wallet credits also guard the DECIMAL ceiling independently of SQL mode. New calculations use integer hundredths for quotes, wallet ledger amounts and commission; the existing 10% commission and 40-points-per-JOD rule remain. Points discount rounding now derives payable from the rounded discount, so `discount + payable = total`, including half-cent boundaries. The legacy nominal booking total/commission and plan subsidy policy are retained, not presented as a reconciled double-entry general ledger.

Plan purchase locks the product and commits wallet debit, entitlement and purchase snapshot together. Guarded decrements and booking locks prevent spending the final wash twice; restoration is single-use. Owner/driver completion awards loyalty once. Owner withdrawal atomically snapshots bank details, reserves the locked wallet balance, records one withdrawal/ledger/audit/notification set and returns a replayable result. Admin processing locks the pending request, updates it once, and records a uniquely referenced refund when rejected. There is no bank transfer or gateway settlement integration in this phase.

The browser card acknowledgement mock is now unable to create funds/subscriptions/payment markers **in any environment**. Its existing page stays available but confirmation returns `payment_gateway_not_configured` (503). A future real provider must validate signed webhooks, amount/currency/account ownership and unique provider events before calling an authorized transactional capture service. Never infer settlement from a redirect or client payload.

## UTC and compatibility

PHP bootstrap uses UTC and every mysqli connection sets session `time_zone='+00:00'`. New event timestamps use server UTC `DATETIME(6)` defaults; existing TIMESTAMP fields retain their storage behavior. `Booking_Date`/`Booking_Time` and station hours remain civil business values for existing pages. New bookings and reschedules additionally store `ScheduledAtUtc` plus `BookingTimeZone` (environment default `Asia/Amman`). Subscription DATE boundaries continue using UTC days. Historical ambiguous DATETIME/civil appointments are never blindly shifted or backfilled.

Current PHP URLs, HTML layout, themes, role aliases and bilingual data are preserved. Delete actions now preserve history through archive/deactivation. Existing schema `clevoEnsure*` helpers remain read-only assertions; no request-time CREATE/ALTER/DROP was reintroduced. Runtime startup requires completed 002/003 journal entries and the deployed idempotency table, and fails safely when the database expansion is incomplete. The application needs 002/003 deployed before the new PHP artifact accepts traffic.

Existing PHP forms now submit generated request keys; old cached clients missing keys receive a controlled 422 and need reload. A key is scoped to one intended operation; preserve it for network retries and create a fresh key after the user deliberately changes a committed operation. Native API clients supply `Idempotency-Key` without browser session cookies. See [DATABASE_API.md](DATABASE_API.md).

## Release limits and operational follow-up

Rehearsal passed on empty and populated disposable databases; detailed counts and previously fixed test failures are in [DATABASE_TEST_RESULTS.md](DATABASE_TEST_RESULTS.md). Production data quality, historical timezone provenance, ledger reconciliation, production backups/offsite encryption, exact MySQL compatibility, large-table lock duration and load tests remain operator release gates. Audit indexes do not establish measured production performance. Existing aggregate reports remain legacy reports rather than audited accounting statements. No migration was run against a real customer database and no real records were deleted.
