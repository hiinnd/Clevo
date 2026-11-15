# REST API phase validation results

Subsequent booking phase (2026-11-16): [BOOKING_WORKFLOW.md](BOOKING_WORKFLOW.md) is authoritative for migration 005, configured interval capacity, status events, post-commit notification delivery and later test results. Earlier counts/availability descriptions below are historical where superseded.

Executed 2026-11-15. PHP CLI/mod_php 8.2.12, MariaDB 10.4.32, Windows PowerShell, Node 24.22.3 and development-only Redocly CLI 2.78.0. These are observed test versions, not production patch-level recommendations. PHP/MySQL remain the runtime stack.

Tests used generated identities, keys and credentials in disposable databases reconstructed from the repository schema/development snapshot. MariaDB used workspace-private `.security-test/mysql` on loopback port 13379, the PHP HTTP server used 18089 and isolated Apache/mod_php used 18090. The runner verifies the actual datadir before testing, waits for a successful database connection and attempts graceful shutdown of its own database process. The readiness probe separately rejected port 3306 without connecting. No live production database, real payment provider, bank account, mail recipient or Flutter application was tested or modified. Reports contain no credential values.

## Final executed checks

| Check | Result |
|---|---|
| Security unit suite | 47/47 assertions passed |
| First-party application credential-pattern scan | 118 files; 0 findings |
| OpenAPI/runtime contract suite | 706/706 assertions passed |
| Existing security/web/API integration suite | 172/172 assertions passed |
| Extended REST API HTTP suite | 1,456/1,456 assertions passed; 526 helper requests plus 2 actual multipart upload requests |
| Migration, concurrency, compatibility and recovery suite | 163/163 assertions passed |
| Existing SQL preparation against migrated schema | 379 statements; 0 candidates requiring fragment review |
| Actual isolated Apache rules | 14/14 passed |
| Actual Apache bearer/API requests | 2/2 passed |
| PHP syntax | 120 first-party PHP files; 0 failures |
| JavaScript syntax | 3 existing asset files; 0 failures |
| Redocly OpenAPI 3.1.1 validation | Valid; 0 errors |
| Composer manifest/platform requirements | Strict validation passed; installed platform requirements satisfied |
| Composer locked dependency audit | No vulnerability advisories reported |
| Development OpenAPI tool dependency audit | 0 reported vulnerabilities |
| Git whitespace check | Passed |
| Changed documentation references/JSON examples | 10 documents; 35 relative links resolved; JSON examples parsed |

The final isolated runner exited 0 with **no failed assertions**. The failed startup attempt and resolved validation/test defects are recorded below.

The generated contract contains **125 method/path operations across 107 paths**: 114 registry operations and 11 preserved authentication operations. The inventory classifies **102 added, 22 modified and 1 retaining its business behavior**. Aliases count as operations; these numbers are not a branch-coverage percentage or proof of every possible production state. Composer emitted a nonfatal unwritable user-cache warning and completed its audit without that cache.

## What the tests exercise

- Real HTTP routing, method rejection/Allow, missing bearer, explicit invalid bearer on public resources, current-account restrictions and role boundaries for every registry operation. Independent literal assertions also reject customer access to administrator financial/account modules and merchant catalog mutation; registry self-consistency alone is not treated as authorization proof.
- Existing registration/login, password hashing, recovery/verification expiry and single-use behavior, rotating refresh/replay revocation, logout, web login sessions/CSRF, secure upload handling and CORS regression checks. The broader API reuses those tested authentication helpers.
- Station search/price/rating/location filtering, bounded pagination, allowlisted sorting, actual review averages, owner station/service/price/hours changes and public archival visibility. Availability is status/hours, not a capacity reservation system.
- Own-object and cross-user/cross-station booking, address, payment, notification, support, review, driver and document cases. Multipart avatar/station uploads and protected binary verification downloads use actual HTTP requests. DTO checks reject credential/task-code/private-field leakage.
- Booking create/retry, acceptance, cancellation/reschedule, server pricing, plan coverage and price-breakdown settlement state. Inconsistent historical receipt amount/currency is reported for reconciliation and cannot authorize completion. Forged legacy plan markers cannot mint completion rewards or earnings.
- Internal payment-session quote/expiry/confirmation, changed points, one debit on replay, typed payment history, eligible internal refund approval once, wallet subscription purchase and plan retirement with retained history. Unconfigured card operations return 503 rather than fake settlement.
- Owner-scoped driver provisioning/invitation, verified email/password enrollment, assignment/reassignment restrictions, separately issued single-use job grants, task-bound versus full driver sessions, GPS samples, legal lifecycle and reduced private data in history. A task grant cannot change recovery identity, inspect account finances/inbox or revoke other device sessions.
- Owner bank/withdrawal reserve, audited administrator bank-detail view and one-time reserve restoration. These tests cover manual status attestations, not a real bank transfer.
- Support/dispute creation/replies/closure, retained conversations, completed-booking reviews/owner replies, administrator user/station/plan/subscription moderation and session revocation. Shared owner rejection preserves the existing administrator notification and retries do not duplicate it.
- Deliberately revoked SELECT on the disposable runtime identity: the API returns a controlled 500 `internal_error`, includes a correlation ID and excludes SQL, exception text and credentials. The test restores the grant and verifies recovery. An expected class-only diagnostic is not a successful business request.
- Migration 004 on empty and populated copies, repeated migration/checksum behavior, original-column/data preservation, new FK/index metadata, restricted runtime grants, transactions/concurrent writers, backup/guarded restore and SQL compatibility. A regression fixture with 201 non-expiring rows verifies overdue bookings are not starved by the worker's candidate limit.
- Actual Apache rewrite forwarding of bearer authorization, anonymous/public access and denials for secrets, dumps, private storage, dependencies, migrations, seeds, tests and developer documents.

The HTTP suite clears only disposable rate-limit buckets between groups so comprehensive contract coverage does not hit normal user quotas. Existing integration checks exercise actual throttling separately. This is not a load test. The private coverage artifact stores method/path/status evidence only, never request/response bodies or credentials.

## Failures encountered and resolved

1. Stricter empty-body validation initially rejected legacy read-marker JSON `[]`. Empty commands now accept an omitted body, `{}` or legacy `[]` under bounded JSON/media-type rules; arbitrary fields remain rejected. The affected authorization regression passes.
2. One negative role test initially selected an owner for a route that intentionally permits owners. It now selects a genuinely disallowed role; separate hard-coded privilege-boundary assertions supplement the generated matrix.
3. Standards validation found empty schema `properties` serialized as a JSON array. The generator now emits an object, and full Redocly validation passes.
4. Review found non-expiring Accepted bookings could occupy the expiry candidate batch before overdue Pending bookings. Eligibility is filtered before the limit, with a 201-row regression case.
5. Review found task-code sessions could otherwise reach account recovery/profile mutations through generic authenticated routes. Their API scope is restricted; full driver enrollment uses a separate verified-email/password flow. Financial review also added receipt/entitlement proof before execution/payout and consistent price-breakdown reads.
6. One full rerun failed during startup with connection refused: the old fixed three-second database delay was shorter than local InnoDB crash recovery. The API suite was skipped and dependent database checks failed because its synthetic schema had not been created. The runner now waits for database readiness, verifies its datadir, fails before migrations on a wrong instance and shuts down its own instance gracefully. The next complete run passed; the final rerun is recorded in the table above.

Negative security cases intentionally expect 4xx/503/controlled 500 responses. These expected responses are distinguished from failed assertions or infrastructure failures.

## Reproduce

```powershell
composer install --no-interaction
powershell -NoProfile -ExecutionPolicy Bypass -File tests/run-isolated.ps1
php scripts/generate-api-docs.php
npm.cmd --prefix tests/openapi ci --ignore-scripts --no-audit --no-fund
npm.cmd --prefix tests/openapi run validate
composer validate --strict --no-check-publish
composer check-platform-reqs
composer audit --locked --no-interaction
git diff --check
```

The PowerShell runner owns test configuration and random test identities. Do not point standalone integration/database/API scripts at production; their environment/datadir guards are part of their contract. `composer test:api` is for an already initialized isolated test environment, not a replacement for the wrapper. Redocly/Node dependencies are development tooling only and must not become a deployed backend dependency. Regenerate schema/ERD from the protected metadata inventory using `scripts/render-schema-docs.php` when inspecting a different restored copy.

## Remaining release verification

Run the same migrations and tests against a restored production copy on the exact supported MySQL release, SQL mode/collation, deployed PHP/SAPI, least-privilege grants, trusted proxy and HTTPS configuration. A local MariaDB pass does not establish MySQL 8.x production compatibility or reconcile historical payment flags, missing coordinates or original cross-station driver anomalies. No production data was silently repaired.

No configured card gateway, provider-signed webhook/capture/refund flow, bank transfer, live SMTP delivery, FCM integration or production maps SDK was available. External payment/session requests fail explicitly; cash collection and withdrawal paid status remain authorized manual attestations. Completed/in-progress/cash/provider refund requests require manual/provider dispute reconciliation. Financial summaries remain nominal, not reconciled accounting statements.

No Flutter app, device secure-storage behavior, provider sandbox certification, external penetration test, production load/capacity benchmark, high-availability/failover or offsite disaster-recovery drill is claimed. Existing web login/CSRF and shared-service regressions were tested, but every PHP dashboard screen and visual interaction was not exercised in a browser. API role/object assertions and local dependency scans do not establish complete security. Operational scheduling, provider integration, historical reconciliation and release verification are documented in [API_INTEGRATION.md](API_INTEGRATION.md).
