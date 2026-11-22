# CLEVO production-preparation test results

Execution date: **2026-11-22**, Windows/XAMPP and guarded disposable MariaDB on loopback 13379 / PHP18089 / isolated Apache18090. Synthetic accounts and local payment/notification doubles only. No production DB, live messages, external charges or infrastructure changes. Artifacts/fixtures/secrets remain ignored; only redacted results are documented.

## Executed checks

| Suite | Result | Practical scope |
| --- | --- | --- |
| Security unit / source credential scan | 47/47; latest scan170 source files, zero findings; PHP lint178 files passed | Does not scan or clean historical Git secrets; required rotations remain separate approved work |
| Security/auth integration | 172/172 | Fresh disposable migrations, restricted runtime and authentication/session/CSRF compatibility |
| OpenAPI/runtime contract | 995/995 latest standalone run; 174 operations / 150 paths | Actual registry163 + preserved auth11; regenerated privacy/deletion request/response schemas |
| REST API | 1860/1860, 660 HTTP requests | Authentication, role/object boundaries, validation, server data and idempotency |
| Booking workflow | 121/121 | Shared lifecycle/capacity/concurrency and history |
| Financial/webhook | 161/161 | Local signed evidence/idempotency/reconciliation; no real provider certification |
| Driver/logistics / notifications | 22/22 and 28/28 | Authorized jobs/tracking and local transports; no real GPS/FCM/APNs/SMTP |
| Admin security/workflows / financial reports | 158/158 and 66/66 | Real disposable API/DB and financial exports |
| Ten complete QA journeys | 99/99 | Registration through completion/cancellation/subscription/IDOR |
| Bounded load | 8/8, 170 requests, zero errors in this run | Catalog concurrency1/5/10 + report concurrency5; small data, serial development server, no production SLO |
| Production preparation | 65/65 latest affected rerun | Readiness authorization, stale/failed workers/backups, maintenance, genuine allowlisted job, reauthenticated self deletion, web/Admin CSRF and page authorization, denied role/IDOR/policy/worker states, restricted runtime redaction, separate narrow worker GPS/file cleanup without financial/audit rights, session revocation, financial preservation, safe retries, failed-dump credential-file cleanup, secure staging and fail-closed launch config |
| DB migration/concurrency/recovery | 180/180; 519 SQL preparations, zero fragment-review candidates | Native consistent dump, guarded empty-target restore, counts/balances and schema/FK integrity; MariaDB10.4.32 only |
| Encrypted local backup/recovery | 6/6 | Official SHA-256-verified restic0.20.0; local encrypted synthetic SQL snapshot, full-data integrity check, restore/hash match and wrong-password rejection. Repeated after current migrated dump. No offsite repository/S3/systemd test |
| Admin Chromium browser / rendered accessibility | 52/52; 24 page/language/theme combinations, zero confirmed axe violation instances after affected rerun | Financial-page theme initialization corrected; incomplete/manual axe checks still require review |
| Isolated Apache | 16/16 rules, 2/2 bearer/API | Existing deny rules, public-root protections and authorization forwarding; no production TLS/FPM activation |
| Flutter analyze / automated tests | Analyze clean; complete suite32/32; final affected privacy/distribution recheck3/3 | Shared client, role routes, offline/retry/GPS fencing, new distribution URL guard and in-app deletion confirmation/password/idempotency/status, eight accessibility scenarios. Desktop widget tests; final ADB check found0 authorized/0 unauthorized devices, no Android/iOS device execution |
| Composer / dependency advisories | Validate, platform checks and locked audit passed; Pub135 / resolved Maven144 coordinates, zero OSV findings | Point-in-time check; native SDK/embedded code/iOS dependencies not fully covered |
| OpenAPI linter | Redocly passed | Development-only Node tooling, no backend technology replacement |
| Monitoring YAML / backup shell | YAML parse and `bash -n` passed | Syntax only; Prometheus/systemd/logrotate/Linux FPM/production HTTPS unexecuted |
| Production/mobile example preflights | Correctly exit1, ready=false | Missing approved domains/secrets/signing/providers/policy; expected blocker, not a successful release |
| Android engineering debug build | Build succeeded; 16/16 artifact checks; ten XML files parsed | ARM64, target36/min24, v2 debug signature, cleartext/backups disabled, no unapproved background location or bundled secret files; not an approved internal distribution/release |
| Android native alignment | ZIP 16 KiB check passed; four packaged 64-bit ELF libraries meet at least16 KiB LOAD alignment | No 16 KiB device execution or final AAB-generated APK certification |
| Release signing guard | Missing private configuration correctly rejected by `:app:assembleRelease --offline` before build | No release signing key or signed release artifact produced |

Current engineering debug artifact: `mobile/clevo/build/app/outputs/flutter-apk/app-debug.apk`, **143,869,018 bytes**, SHA-256 `70E1E230374DA61601BFB2B33D1DE286C6C88D67F4E5CB9843D2E875417CED76`. Default API remains `.invalid`; this artifact is for source/build validation and needs an approved test configuration before a real connected user journey. It is not a production or store-upload build.

## Failures and fixes

- **MEDIUM, fixed and rerun:** first new security test accepted `confirm:false` (HTTP202). Runtime validator did not enforce JSON Schema `const`; strict constant enforcement added with negative unit/API tests. Affected production suite later passes and full API regression passes.
- **MEDIUM, fixed and rerun:** previous web erasure attempted GPS DELETE with an append-only runtime identity. Migration010/completion marker and dedicated worker now handle GPS deletion; narrow worker permissions are tested, financial/audit rewrites denied and financial records preserved.
- **MEDIUM, fixed and rerun:** backup/restore error handling could skip temporary credential-file cleanup through early exit. Exit now follows finally; injected missing-client test verifies cleanup. Native restore and encrypted roundtrip pass.
- **LOW, corrected:** initial Dart analyze reported an unused import and three missing braces; corrected, analyzer and all32 tests pass. Initial analyzer invocation from repository root was stopped and rerun in the Flutter project; it is not counted as successful analysis.
- **LOW, corrected validation tooling:** monitoring YAML parser was not installed; initial parse attempts failed. Added an ignored development-only YAML parser and reran successfully. PowerShell npm.ps1 execution policy was preserved; npm.cmd used instead.
- **LOW Android build configuration, fixed and verified:** first build failed because AGP disabled custom resValues. Explicit feature enablement restored the build; the final debug artifact passed metadata/signature/permission and alignment checks. An initial attempt to test a nonregistered `validateSigningRelease` task was a harness error; using the actual assembleRelease graph verified the missing-private-signing guard.
- **MEDIUM financial-page theme/contrast, fixed and verified:** the first full QA command and first affected rerun exited1 on one axe color-contrast rule affecting three Arabic/light texts. The diagnostic run recorded an intermediate background `#dedfe2` during the initial dark-to-light card transition, with ratios4.38/3.76 below the required4.5 for those small texts. Applying the stored theme before first paint and restricting card animation to transform/shadow resolved the transient contrast failure without changing the palette/layout. Final affected run: browser52/52, axe24 combinations/zero confirmed violations. The original full-command failure is retained here; only affected tests were repeated after the safe CSS/init change.

## Reproduction

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/run-isolated.ps1 -QA -AdminBrowser
powershell -NoProfile -ExecutionPolicy Bypass -File tests/recheck-qa.ps1 -Target production
powershell -NoProfile -ExecutionPolicy Bypass -File tests/recheck-qa.ps1 -Target admin
powershell -NoProfile -ExecutionPolicy Bypass -File tests/production-backup.ps1 -Restic <verified-private-restic.exe>
```

Flutter tests run from `mobile/clevo` using the local SDK/cache. `tests/qa-android-artifact.ps1` inspects the engineering debug APK; `tests/android-page-alignment.ps1` inspects ZIP/ELF alignment, not device behavior. Recheck commands refuse non-loopback/unexpected disposable schemas and never initialize or restore a production database.

## Unexecuted release checks

Approved Linux/PHP8.4/MySQL8.4 deployment, actual least-privilege backup grants there, offsite S3 recovery/retention/failover, public DNS/CA/renewal, installed systemd/Prometheus/logrotate/alerts, real SMTP authentication/delivery, Firebase/APNs/Maps/native permissions, real merchant callbacks/refunds/settlements, production signing, final AAB/IPA, Play/TestFlight upload, iOS compilation and native devices remain **UNTESTED/BLOCKED**. Local passes do not establish commercial production readiness. Open gates and severity: [PRODUCTION_READINESS.md](PRODUCTION_READINESS.md).
