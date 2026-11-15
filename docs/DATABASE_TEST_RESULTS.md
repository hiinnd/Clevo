# Database phase validation results

The later API phase reran and extended these checks; its current results are in [API_TEST_RESULTS.md](API_TEST_RESULTS.md). Counts below describe the completed database phase.

Executed 2026-11-15 in the repository workspace. PHP CLI 8.2.12, MariaDB 10.4.32 on loopback port 13379, PHP test HTTP server on 18089 and isolated Apache/mod_php on 18090. These are observed local versions, not endorsed production patch levels. No production database/configuration was supplied or contacted.

## Final executed checks

| Check | Result |
|---|---|
| `powershell -NoProfile -ExecutionPolicy Bypass -File tests/run-isolated.ps1` — security units | **47/47 passed** |
| Same harness — security/web/API integration | **171/171 passed** |
| Same harness — database migration/concurrency/backup/restore suite | **159/159 passed** |
| Same harness — real isolated Apache rules | **12/12 passed** |
| Credential source scan | **102 first-party source files; 0 findings**; locations/rules only, no values emitted |
| PHP syntax | **101 files; 0 failures**, excluding vendor and ignored disposable storage |
| Application JavaScript syntax | **3 actual assets; 0 failures**, plus rendered inline-script checks in HTTP tests |
| Existing SQL preparation | **307 complete static DML statements prepared; 0 candidate failures** |
| Composer configuration/platform | `composer validate --strict --no-check-publish` and `composer check-platform-reqs` passed |
| Patch whitespace | `git diff --check` passed |

The harness creates new names and keeps all data within `.security-test/mysql`. The database suite additionally verifies the connected server's datadir resolves under that isolated workspace directory before creating any copy. Database names contain random suffixes; restore accepts a separate strict name pattern and an empty testing-only loopback target. No DROP DATABASE/TABLE or fixture reset is performed against any existing database. Tests leave disposable copies as evidence.

## Database evidence

- Both **populated original source schema/fixtures** and **empty original schema** accept the security prerequisite and 002/003. The application refuses traffic if database migration journal entries are incomplete. Missing user/station FK is adopted; existing equivalent constraints are preserved. Running migrations twice is a no-op.
- Ordered SHA-256 row comparisons cover **every pre-existing column value in every pre-existing table** before/after database expansion and again after rerun. Comparison starts after security prerequisite 001, whose credential cleanup is separately tested; this is not a claim that 001 leaves exposed credentials usable.
- Original fixture cross-station driver anomalies: **six reported, none rewritten**. Duplicate wallet owners and orphan support senders block before target DDL; both duplicate balances remain intact. Unique wallet/order/sender constraints reject invalid new writes.
- Applied checksum tampering and a deliberately ambiguous `started` DDL journal step refuse execution. Initialization refuses a nonempty DB. Partial DDL is not represented as transactional rollback.
- Two independent PHP processes contend on real InnoDB connections: duplicate booking returns one result; duplicate same-key payment returns one committed result; different bookings cannot spend the same final wallet balance; different plan bookings cannot spend the final wash twice.
- Request-key replay returns the same response; a changed body conflicts. Different payment keys cannot debit an already-paid booking. Service snapshots, notifications, audit/effect/receipt rows participate in the transaction.
- Refund replay restores the customer once and reverses the recorded owner payout once. A refunded booking cannot be completed. Plan restoration is single-use. Repeated owner/driver completion cannot duplicate loyalty rewards.
- Insufficient wallet after points calculation rolls back all loyalty buckets/payment state. An injected unique-ledger insertion failure after balance mutation rolls back wallet debit, points and receipt. A nested service cannot commit its outer caller; outer rollback restores the preceding wallet mutation.
- Half-cent points rounding preserves `discount + payable = total`. Wallet ceiling checks reject credit overflow without changing the balance even after deliberately making that isolated connection permissive. Normal application connections explicitly enforce strict transactional SQL mode.
- Subscription retry cannot repurchase after catalog price changes; original purchase price/washes remain snapshotted. Withdrawal retry reserves the balance once. Only one active assignment per booking is accepted. Authorized stationary GPS updates append history; unassigned updates are denied.
- Customer cannot archive catalog. Owner service archiving retains bookings/snapshots; admin station archiving and plan retirement retain bookings/subscriptions. Archived services reject new bookings.
- The actual backup tool writes a protected logical dump and SHA-256 manifest. The actual restore tool imports it into another empty disposable database. Every source table/column value is compared after restoration. Nonempty or nonmatching target names refuse restore.
- Metadata inventory/rendering includes **32 tables, 277 columns, 142 index-column entries and 43 declared FK-column entries**. It is generated from the migrated instance, not a guessed ERD.

## PHP and API compatibility evidence

The HTTP server uses a distinct principal with per-table permissions, **no DDL**, **no booking/catalog/financial DELETE**, immutable wallet ledger INSERT/SELECT access and audit-log INSERT only. Tests explicitly deny schema CREATE, booking DELETE and audit UPDATE. Eleven former schema-ensure entry points remain read-only under those permissions.

All four roles and legacy role aliases retain their authentication/authorization tests. Customer home/search/history/plans/wallet/station detail pages, owner incoming orders/reviews/drivers/wallet/services, administrator/driver dashboards and protected media/notification flows execute against the expanded schema, with inline JavaScript compilation checks. New bearer booking/payment routes test valid creation, same-key replay, changed-input conflict, wrong role, another customer's payment and duplicate payment. Existing PHP URLs, CSRF and session tests remain in the same suite. The real Apache check additionally denies direct download of development seed SQL.

The static SQL check tokenizes first-party PHP, prepares complete constant DML statements without executing writes, skips fragments and reports failures by file/line rather than SQL/row values. Dynamic interpolated SQL, every possible form combination, visual parity and production performance are not proven by preparation alone. Additive schema contracts plus sampled real role journeys provide compatibility evidence without claiming exhaustive functional coverage.

## Failures encountered and resolved

The first new migration-suite run failed because a synthetic fixture assumed UserID starts at 1, while the actual legacy table has AUTO_INCREMENT=3. Fixtures now explicitly choose their isolated IDs; the production schema counter was not reset. Subsequent migration/concurrency/recovery runs passed.

Schema-document generator inspection found an omitted closing quote in Mermaid relationship labels; generation was corrected and the checked-in graph regenerated. A manual JavaScript check initially referenced a nonexistent weather filename; file discovery then checked all three actual assets successfully. These were test/tooling issues, not unresolved database failures.

There are **no remaining failures in the checks above**. Original source data warnings remain intentionally unresolved. MySQL 8.x execution, production data reconciliation, large-table DDL lock duration, replica/PITR recovery, encryption/offsite backup delivery, real SMTP/gateway/bank integrations, capacity scheduling and load testing were not performed. Follow [DATABASE_BACKUP_RESTORE.md](DATABASE_BACKUP_RESTORE.md) before release.
