# CLEVO final source audit

Date: **2026-11-23, Asia/Amman**. **Decision: NO-GO for commercial production.** This review inspected actual source, reran local tests and then applied documented internal fixes. Prior reports were treated as leads, not proof. PHP/MySQL and the existing PHP Admin remain; one Flutter project serves Customer, Owner and Driver. No deployment, publication, live credential rotation or production data modification was performed.

Current fixes: [FINAL_REMAINING_FIXES.md](FINAL_REMAINING_FIXES.md). Fresh execution/failure/rerun evidence: [FINAL_INTEGRATION_RESULTS.md](FINAL_INTEGRATION_RESULTS.md). Feature inventory: [FEATURE_COMPLETENESS.md](FEATURE_COMPLETENESS.md). Release gates: [PRODUCTION_BLOCKERS.md](PRODUCTION_BLOCKERS.md), [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md).

## Method and evidence boundary

Reviewed PHP entry points/shared services, registry/contracts/generated OpenAPI, actual migrations, Flutter transport/state/routes/screens, upload/privacy/notification/tracking code, release/config/backup/job references and automated tests. Compared the original feature inventory against the current paths and checked for deleted legacy feature files. There is no evidence that retaining a file alone proves its behavior.

Executed tests use guarded disposable schemas and synthetic accounts on loopback. Local signed webhook/SMTP/FCM doubles exercise application contracts; they are not external certification. Windows lacks a compatible iOS build environment; ADB enumerated no authorized device. The selected maintained production PHP/MySQL/Linux stack, real store artifacts and external services remain untested. Test counts in the integration report are this run's evidence; earlier dated reports remain historical.

## Thirty requested checks

| # | Verdict | Actual evidence / limit |
| --- | --- | --- |
| 1 | Partially verified | Legacy role pages retained; shared-service/auth/booking/Admin regressions exercised. Unsafe payment shortcuts, history deletion and real stored value were intentionally restricted and documented. Referral attribution/native AI/weather/loyalty parity and exhaustive legacy UI acceptance remain incomplete. |
| 2 | Verified | PHP front controllers `api/v1/index.php`, `includes/config.php` and shared `includes/` services remain primary. Composer contains PHP libraries, not a replacement backend framework. |
| 3 | Verified locally | `core/api.dart`, `core/config.dart`: fixed-origin HTTPS Dio transport, no certificate bypass/redirect following, revocable bearer/refresh and secure vault. Real deployed HTTPS remains blocked. |
| 4 | Verified source | No mobile MySQL driver, DB connection or DB credential config; data calls go through the shared PHP API. |
| 5 | Verified locally | `includes/api/router.php`, role/account/session policies, per-object services and GoRouter policies; Admin remains web. Single scalar role; multi-role switching is absent and unapproved. |
| 6 | Verified locally | Auth/security/API negative tests execute backend gates; client navigation is not authorization. |
| 7 | Partially verified | Data screens use API resource loaders; dashboards are navigation with authenticated profile, not invented financial counters. Static text/branding is local. External maps/provider data and every screen with realistic large data remain unqualified. |
| 8 | Partially verified | Critical backend commands, ten journeys, Admin browser controls, new owner registration and inherited phone/numeric forms executed. Every native/legacy button is not certified. |
| 9 | Verified locally | `booking-workflow.php`, shared adapters/history/outbox and HTTP/journey tests. |
| 10 | Verified locally | Owner service CRUD, database pricing/duration, public catalog and live customer/owner API tests; client submits service IDs, not trusted totals. |
| 11 | Verified locally | Station-first locking, capacity/interval checks, idempotency and concurrency tests; target MySQL/load qualification remains open. |
| 12 | Verified application guard; external blocked | `includes/finance/provider.php`, `orders.php`, `recognition.php`: authenticated provider evidence, amount/currency/profile checks, immutable journals. Production online handling stays disabled. Cash is separately attested, not provider-confirmed card payment. |
| 13 | Verified local arithmetic; commercial wallet blocked | Integer-fils ledger/5% approved basis and historical values tested. Real stored value/payouts not enabled; wallet reads do not prove authorization to spend/fund. |
| 14 | Verified locally; external blocked | New funded subscriptions require verified evidence. Historical plans retain explicit legacy treatment; no synthetic activation is used in production. Owner usage report cannot activate a plan. |
| 15 | Verified local queue; delivery blocked | Transactional outbox/retry/preferences/device checks and subscription expiry job; real FCM/APNs/SMTP delivery not executed. |
| 16 | Verified logic; native blocked | Assigned-job authorization, valid stages, sequence/time/accuracy, terminal/lifecycle fencing and stale/offline tests; no real location/device certification. |
| 17 | Partially implemented/verified | Existing bilingual copy and new state labels/onboarding tested in AR/EN. Unknown wire reasons, all legacy copy/content and every screen still need review; no claim of exhaustive translation. |
| 18 | Partially verified | Shared Flutter theme tests and Admin AR/EN/light/dark checks. All native screens/platform dialogs/visual regressions remain unqualified. |
| 19 | Verified locally | Admin figures/CSV derive from recorded ledger transactions; financial tests compare expected DB entries. No external bank settlement certification. |
| 20 | Verified disposable only | Actual forward migrations, count/balance/FK/recovery tests. Migration011 changes security digests/version metadata, not business history. No live migration executed. |
| 21 | Current scan clean within rules; historic exposure remains | Pattern source/signing-file checks; targeted original Git sources still contain historical SMTP literals. Values withheld. New keyed request digest repairs a discovered offline-guessing risk; old copies/rotations still need review. |
| 22 | Verified within listed suites | See fresh counts, initial failures and affected reruns in integration evidence; no unexecuted test is marked passed. |
| 23 | Engineering debug only | Android rebuild and APK/signature/permission/alignment results are recorded after execution. No approved signed release AAB/internal store delivery. |
| 24 | Blocked | Windows; no macOS/Xcode/team/profiles/device. No iOS build is claimed. |
| 25 | Prepared, not production configured | `deploy/`, `health/`, allowlisted jobs and recovery tools have local/syntax tests. No installed approved production monitoring/offsite backups/alerts/renewal evidence. |
| 26 | Partially implemented; approval blocked | In-app/web/API selective deletion and Admin review exist. Legal identity, public approved policy/contact/retention, processor cleanup and final store declarations are missing. |
| 27 | Verified boundary | Production financial guards, external-send flags and unconfigured native integrations remain explicit. Test doubles/debug artifacts are labeled; no fake live success is presented. |
| 28 | NOT satisfied for launch | A HIGH application digest defect was repaired; known old runtime version matches critical upstream vulnerabilities. No exploit was run, and absence of other vulnerabilities is not established. |
| 29 | Core journeys verified; exhaustive acceptance open | Existing/local core workflows pass within executed scope; external/native journeys and approved owner membership/multi-role features remain blocked/missing. |
| 30 | Documented, not approved | Hosting/runtime/DB, merchant/provider, Firebase/APNs/Maps/mail, signing/stores, legal, recovery/monitoring and product decisions are explicitly listed with closure evidence. |

## Independent findings and disposition

The source review found an ordinary SHA-256 idempotency body digest in password-bearing reauthentication requests (`includes/database.php:61`). Migration011 and HMAC backfill now protect new/existing records while preserving replayed results. Other source-confirmed repairs are initial native owner registration, owner-scoped subscription usage, orphan registration upload cleanup (`includes/security/uploads.php:44`), scheduled/bounded expiry reminders (`includes/subscription-reminders.php:5`), redundant catalog count aggregation and raw status display. These are implementation repairs, not proof of external readiness.

No critical **application exploit** was demonstrated. This is distinct from the **CRITICAL runtime version blocker** and does not authorize release. Updated official runtime/store references and precise remaining acceptance evidence are in the blocker/checklist documents. Launch can be reconsidered only after all applicable blockers close on the approved deployment and final signed artifacts.
