# Final integration and regression evidence

Review/execution date: **2026-11-23, Asia/Amman**. Read with [FINAL_REMAINING_FIXES.md](FINAL_REMAINING_FIXES.md) and [LAUNCH_BLOCKERS.md](LAUNCH_BLOCKERS.md). This is local implementation/test evidence, not commercial readiness or deployment approval.

## Environment and isolation

Observed PHP 8.2.12 / disposable MariaDB 10.4.32, Windows, Flutter 3.50/Dart 3.14, Android SDK 36/Java 21 and Chrome. Exactly one `mobile/clevo/pubspec.yaml`; PHP remains the server and Admin remains web-based. Flutter's shared Dio client uses HTTPS API requests, secure token storage and server-derived roles; no MySQL client/DB credentials are part of the mobile app.

The test harness creates random `clevo_security_test_*` databases, checks the workspace-only datadir, uses DB13379/PHP 18089/isolated Apache18090, and grants a restricted runtime principal. No live database, real customer notification, charge/refund/settlement, real GPS sample, DNS/cloud change, key rotation or store upload is used. External transport doubles are not provider certification. Current ADB enumeration: zero authorized devices; Windows has no compatible macOS/Xcode environment.

## Execution record

The tables in the audit/feature reports reference the executed suites in this section. Logs/fixtures are kept under ignored `.security-test`; reports contain no credentials. The full PHP harness returned exit 1 for the new reminder query failure described below; after correction, the affected real-database suite returned exit 0. Unaffected passing suites were not relabeled as a second full green run.

| Check | Executed result / scope |
| --- | --- |
| Security unit | 47/47 |
| Auth/session/CSRF/web compatibility | 176/176, including inherited station-clock corrections |
| OpenAPI/runtime contract / linter | 1001/1001; 175 operations /151 paths; Redocly passed |
| REST API | 1870/1870; 663 HTTP requests; 164 runtime registry operations |
| Booking concurrency/workflow | 121/121 |
| Financial and webhook | 161/161, local signed provider double only |
| Driver/logistics / notifications | 22/22 and 28/28, authorized synthetic samples and local transports |
| New internal security/performance/subscription/upload/owner API checks | **32/32** on the final affected disposable DB rerun; includes actual multipart registration, pending-account/station restrictions and narrow subscription-worker grants/denied unrelated mutations |
| Admin security / financial reports | 158/158 and 66/66 |
| Ten complete backend journeys | 99/99 assertions; local doubles/synthetic accounts |
| Bounded load checks | 8/8; 170 requests, zero errors in this execution; small fixtures/serial development server only |
| Production configuration/privacy/jobs | 65/65 local assertions; no production activation |
| Database migrations/concurrency/native recovery | 180/180; 526 legacy SQL compatibility preparations, zero unresolved candidates; 52 tables/521 columns/267 index columns/89 FK columns after 011 |
| Admin Chromium / axe | 52/52; 24 language/theme/page combinations, zero confirmed rule violations; incomplete/manual accessibility remains open |
| Live Dart-to-PHP/MySQL | 1/1 customer/owner/driver integration test passed separately with guarded fixtures |
| Isolated Apache / bearer API | 16/16 and 2/2 |
| Flutter analyzer / unit/widget regressions | Analyzer clean; 38/38 selected tests. Full default run 42 passed/1 failed/1 skipped, then corrected owner-form fixture passed 1/1: **43 distinct default unit/widget tests verified across runs**; live test skipped without fixture by design and passed separately above |
| PHP syntax / source scan | 180 PHP files linted; 172 application files scanned; zero pattern findings |
| Composer / Pub / native Android package advisories | Locked Composer audit clean; 135 Pub and 144 resolved Maven coordinates, zero OSV matches at execution; iOS/native embedded code not fully covered |
| Android current-source build / artifact | Debug ARM64 build succeeded; 16/16 APK checks,10 native XML files parsed; ZIP 16KiB and all 4 ELF libraries aligned; no runtime16KiB/device test |
| Local encrypted recovery | 6/6 restic recovery checks against the latest synthetic native dump; local encrypted repo only, not remote disaster recovery |
| Production/mobile example preflights | Both returned `ready:false`, exit 1 as expected: unsigned/unconfigured examples cannot be released; this is blocker evidence |
| Android release-signing refusal | Executed Gradle `:app:assembleRelease --offline -q`; rejected at the missing private `android/key.properties` guard as required. Wrapper check passed; no release artifact or key generated |

Bounded catalog timings: concurrency 1 p50/p95/max106.30/141.09/156.99ms; concurrency 5 395.30/445.13/515.20ms; concurrency 10 900.93/1039.39/1051.80ms. Ledger concurrency 5:492.76/539.84/605.49ms. Fixture size 14 stations/68 bookings/55 journals. These measurements neither close the earlier intermittent timeout nor establish commercial capacity; no timeouts or failure thresholds were raised.

The debug APK is 143,880,880 bytes, SHA-256 `C3232931C767A20D99DA0315833279B633850314B0E4DD206C7C6E11663C48C4`. It uses debug signing and an intentionally unconfigured default API; it is not a production or store-testing distribution. No real-device or iOS result is implied.

Source provenance: base HEAD `51d91aa930f43912e8ff93c7dcb2fc1875065e58`, with the documented uncommitted fixes. The protected first-party source manifest covers 226 source/config/migration/lock files (excludes docs, tests, vendor and environment files); SHA-256 of the sorted path/hash manifest is `8e393efe90d3ec6142faab5c607fc9c6c7d1920e9dce761d6c9cc136fd157b1b`. It identifies the reviewed source set, not an attestation of all repository/history contents. A targeted known-baseline Git review found four historical SMTP literal locations; values were withheld and historical custody/rotation remains open.

## Test failures retained, not counted as passes

1. An initial baseline run overlapped the schema-security edit and an old disposable DB lacked the new column. Its remaining results are discarded as a mixed-source run. A fresh migrated run and affected reruns are required; no production schema was touched.
2. The first new owner widget fixture awaited real temporary-file I/O inside fake async and then tapped before scrolling layout settled. Stopped the hung test, used an in-memory XFile and settled actual scroll frames; the revised four language/theme cases passed in the38-test run. No missed-tap warning was silenced and no production file picker is replaced by test data.
3. Initial onboarding analysis found missing braces and an unrelated mounted guard; corrected and analyzer passed before widget execution.
4. The new reminder anti-starvation query initially hit an ASCII/UTF-8 collation mismatch in MariaDB. Added an explicit ASCII/binary comparison for hexadecimal keys. The affected final-fixes suite then passed 28/28 on the real disposable DB; a subsequent expanded run passed **32/32**, including a separate narrow worker identity and three denied unrelated mutations. Target MySQL still needs its own qualification.
5. The inherited phone-editor widget fixture omitted the approval status actually returned by the API. It now declares its intended approved station, preserving the phone assertion while pending/blocked stations remain uneditable. The affected owner-form widget rerun passed 1/1; the earlier full-command failure is retained above.

## Required deployment order for the security fix

Use approved backup/rehearsal and drain writers before migration 011 plus its PHP backfill, then cut over code and check readiness. Earlier cached results and business records are preserved. Missing migration fails closed; legacy raw-body digests must not be kept as a password-guessing oracle. APP_KEY remains private/stable; assess prior dumps and historic credentials through the separately approved rotation/custody procedure.

## Unexecuted acceptance work

Native Android/iOS journeys, iOS build, production signing/final AAB/IPA, real FCM/APNs/Maps/SMTP/provider sandbox, public HTTPS/TLS renewal/proxy validation, approved MySQL/Linux stack, installed jobs/alerts, offsite disaster recovery/retention, sustained realistic load, every legacy/native button and full manual accessibility remain **untested or externally blocked**. Unknown provider state is not payment success. No production notification/GPS demo or fake balance is represented as live functionality.

## Reproduction

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/run-isolated.ps1 -QA -AdminBrowser -Flutter <local-flutter.bat>
powershell -NoProfile -ExecutionPolicy Bypass -File tests/recheck-qa.ps1 -Target final
```

Run `flutter analyze --no-pub` and `flutter test --no-pub` from `mobile/clevo`; the live Dart test runs separately with guarded fixtures from the PHP harness. Build debug with `flutter build apk --debug --target-platform android-arm64 --no-pub`, inspect it with `tests/qa-android-artifact.ps1`, and rehearse encrypted recovery with `tests/production-backup.ps1` against disposable fixtures only. Missing credentials/readiness false is an expected blocker, never a passing deployment.
