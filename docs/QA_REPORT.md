# CLEVO integration and quality review

Review date: **2026-11-21**, Asia/Amman. This report records this QA phase, not results copied from earlier phase reports. Read with [TEST_MATRIX.md](TEST_MATRIX.md) and [REMAINING_ISSUES.md](REMAINING_ISSUES.md).

## Release assessment

**Commercial release is not approved by this review.** Local business/security tests and external/device qualification are separate evidence. The installed server runtime needs maintenance, and real payment, Firebase/APNs, mail, Maps and device journeys still require an approved staging environment. Passing local tests does not establish production readiness.

The repository contains **one Flutter application** with customer, owner/partner and driver roles. No separate partner application was created. The PHP Admin dashboard remains web based. PHP/MySQL business services, interfaces, languages and themes are retained.

## Isolation and environment

- PHP 8.2.12; MariaDB 10.4.32; Apache 2.4.58 Win64; PHP OpenSSL 3.0.11. These are observed local versions, not an inventory of a remote production host.
- Flutter 3.50.0 / Dart 3.14.0, Java 21, Android SDK 36, ARM64 debug build; Windows host. Chrome 157 is available for web dashboard testing.
- The runner creates random `clevo_security_test_*` databases, verifies the workspace test datadir, applies the actual migrations and starts PHP on loopback 18089 with MariaDB on 13379. Runtime tests use restricted database grants. The live application database/port 3306 is not targeted.
- Random accounts and credentials remain in ignored private test storage. No real customer messages, charges, refunds, settlements or location samples are sent. Payment-provider HTTP is replaced by local closures; matching locally signed sandbox webhooks exercise the real HTTP verification and ledger code.
- Registration verifies the grant from the encrypted local mail queue without delivering email. Test wallet balances represent frozen historical fixture data, not authorized stored value. The public-hosted package audit sends only package names/versions to OSV.

## Executed verification

Counts below refer to completed suites in the full baseline and affected focused rerun. A missing or blocked external test is never counted as passed.

| Check | Evidence/result |
| --- | --- |
| Security units | 47/47 |
| Source credential scan | 153 application files, zero findings |
| OpenAPI/runtime contracts | 958/958 |
| Authentication/web security | 172/172 |
| REST API regression | 1,821/1,821; 647 HTTP requests, 157 registry operations |
| Booking policy/concurrency | 121/121 |
| Financial/webhook regression | 161/161, local sandbox provider double |
| Driver/logistics | 22/22 |
| Notification worker/API | 28/28, local delivery doubles |
| Admin security/shared workflows | 158/158 |
| Admin financial reports/exports | 66/66 |
| Database migrations/concurrency/recovery | 178/178; 477 SQL compatibility statements |
| Ten combined user journeys | **99/99 passed**, all ten scenarios executed in the focused rerun |
| Bounded loopback load | Initial/diagnostic runs 8/8; one affected run 7/8 with three timeouts. See the retained open performance finding. |
| Admin browser | 52/52 |
| Live Dart → PHP → database | 1 live test passed; actual customer/owner/driver HTTP integration |
| Flutter unit/widget/security | 21 passed |
| New Flutter accessibility checks | 8 passed |
| Flutter analysis | Final-source rerun passed: no issues found |
| Android build | ARM64 debug rebuild passed after applying dependency constraints |
| Android artifact checks | Patched artifact: 16/16; 10 native XML/plist/entitlement files parse |
| Admin automated accessibility | **24 page/language/theme combinations**, zero confirmed automated violations after fixes; keyboard upload activation checked |
| Isolated Apache | 16/16 rules and 2/2 bearer/API checks |
| Syntax/format checks | 162 PHP files: zero syntax errors; new JavaScript parses; new Dart format check and Git whitespace check passed |
| Composer advisory audit | Zero advisories/abandoned packages; validation/platform requirements passed |
| Pub OSV audit | 135 locked hosted packages, zero matches |
| Android Maven OSV audit | Initial 146 coordinates/four matches; patched graph: 144 coordinates, zero matches |
| npm advisory audits | No findings in the OpenAPI and browser test tooling checked |
| Native Android journeys | **Not run:** ADB reports no connected Android device; SDK alone is insufficient |
| iOS build/device journeys | **Not run:** no macOS/Xcode/iOS device environment |
| Real payment-provider sandbox | **Blocked:** no onboarded account/credentials/certification |
| Real Firebase/APNs/SMTP/Maps | **Blocked:** no approved external configuration/device qualification |

## Failures, root causes and repairs

### Android transitive dependencies — HIGH / MEDIUM / LOW

The resolved **debugRuntimeClasspath**, not merely the Dart lockfile, pulled JUnit 4.12 and Guava 28.1-android through Flutter's `integration_test` Android plugin. OSV reported:

| Advisory | Package | Upstream severity / interpretation |
| --- | --- | --- |
| [GHSA-xxph-c9ww-hj94](https://osv.dev/vulnerability/GHSA-xxph-c9ww-hj94) | Guava | HIGH database rating; deserialization resource consumption requires attacker-controlled Java deserialization. Presence is confirmed; reachability/exploitation in CLEVO was not demonstrated. |
| [GHSA-7g45-4rm6-3mm3](https://osv.dev/vulnerability/GHSA-7g45-4rm6-3mm3) | Guava | MEDIUM; temporary-file permissions, environment/API-use dependent. |
| [GHSA-5mg8-w23w-74h3](https://osv.dev/vulnerability/GHSA-5mg8-w23w-74h3) | Guava | LOW; temporary-directory information disclosure. |
| [GHSA-269g-pwp5-87pp](https://osv.dev/vulnerability/GHSA-269g-pwp5-87pp) | JUnit | MEDIUM; temporary-folder permissions, dependent on test use and local user isolation. |

Added application dependency constraints in `mobile/clevo/android/app/build.gradle.kts` selecting JUnit **4.13.2** and Guava **33.7.2-android**. The integration-test plugin is retained. The current-source ARM64 rebuild, actual resolved dependency graph, 16 artifact checks and OSV rescan all passed: **144 Maven coordinates, zero matches**. No SDK/vendor source was edited. This verifies resolution/build integrity, not an exploit or native device journey.

Patched debug artifact: `mobile/clevo/build/app/outputs/flutter-apk/app-debug.apk`, **107,101,357 bytes**, SHA-256 **EF3DE19A5FF3AF0B0776D5C069DC4BB9A6AEF6077C2A7AD9694117AB63203726**. V2 signature verifies; ABI is ARM64; global cleartext and backups are disabled; unapproved background-location permissions/private fixture files are absent. It uses debug signing and an unconfigured default API host, so it is not a deployable store release.

### Cancellation expectation — LOW, test harness

The first combined journey suite reported **75/78**, with three related assertions failing/stopping at scenario 8. It expected a second cancellation request to succeed after a verified pre-pickup refund. `includes/finance/refunds.php` already atomically cancels that booking, and `includes/booking-workflow.php` rejects repeated terminal transitions. The correct test now reads `Cancelled`, expects the redundant transition to return 409, and asserts exactly one cancellation history event and one compensating financial journal. No application cancellation or payment check was relaxed. The focused rerun executed **all ten scenarios: 99/99 passed**, including the initially blocked subscription and cross-owner/wallet/job authorization scenarios.

The initial aggregate runner correctly returned exit 1 because of those journey assertions; the independent business, Dart, database, Admin and Apache suites in that same run passed. Apache prints `Syntax OK` on stderr, which the outer PowerShell transcript labels as a native-command record; this is separate from the actual failed journey assertions.

### Dependency report encoding — LOW, test harness

The initial Maven scan failed closed with an incomplete-report error because Windows PowerShell redirected Gradle output as UTF-16. The scanner now handles UTF-16/UTF-8 and resolves BOM-selected dependency versions as well as explicit versions. Its rerun detected the four genuine version matches above; an incomplete scan was never reported as clean.

### Dart formatting — LOW, test tooling

The newly added accessibility test initially failed the formatting check. Applied `dart format`; its repeated format check reports zero changes. The eight accessibility tests executed successfully. This was not an application failure.

### Admin accessibility — MEDIUM, corrected and verified

Axe Core **4.14.1** initially reported **31 violation-rule instances across 12 page/language combinations** in light mode. Confirmed causes were low text contrast, the shared avatar without an appropriate alternative/accessible control name, icon-only return links without names, and legacy viewport directives disabling zoom. The rules were `color-contrast`, `image-alt`, `link-name` and `meta-viewport`. Axe's own impact labels are accessibility labels, not CRITICAL security exploit ratings.

Repairs retain the layout and palette: darker blue/green/orange shades where needed, readable plain links and empty-state text, bilingual accessible control names, a keyboard-focusable profile upload with Enter/Space activation, a decorative avatar within that named control, and removal of zoom restrictions from nine Admin pages. The existing Arabic/English and theme code remains authoritative.

Expanding the rerun to both themes found residual dark-link contrast and an overly broad new pagination selector coloring active buttons. Narrowed the selector to exclude buttons and removed opacity from the financial admin-name caption; these were corrected before the final check. The last isolated browser recheck passed **52/52 functional checks** and **24 accessibility combinations with zero confirmed violations**, plus actual keyboard activation of the intended file chooser. Five synthetic screenshots were reviewed, including the final Arabic dark view and English light financial report.

Axe still flags some contrast evaluations as **incomplete/manual review**. Zero confirmed automated violations is not a claim that all WCAG requirements, native screen readers, canvas charts or every Admin page have been certified.

### Intermittent local load timeout — MEDIUM, investigation remains open

One affected run completed **7/8 load assertions**: three of 50 catalog requests at concurrency 10 returned no HTTP response before the unchanged 20-second client timeout. Earlier runs and the instrumented diagnostic rerun completed all 170 requests successfully. The independent ApacheBench comparison completed 50/50 requests with zero failed/non-2xx responses. No application validation, timeout, financial rule or rate limit was relaxed to obtain that result.

The confirmed failure is a loopback transport timeout under this serial development server. Its underlying cause is **not fully isolated**; it cannot honestly be assigned to a SQL/index defect or dismissed as a corrected application bug. The inspected PHP log contained no fatal, uncaught, lock-wait or deadlock markers. The load tool now records cURL result, connect and first-byte timing on future failures without storing request bodies or credentials. Keep QA-R05 open for controlled, sustained production-like worker/DB testing.

The first independent-benchmark wrapper also falsely failed despite a successful 50/50 ApacheBench report: PowerShell 5 had not retained the process handle and lost its exit status. The wrapper now retains the handle and fails closed if no exit status is available. The affected rerun returned **exit 0**, **8/8 load assertions** and ApacheBench **exit 0 / 50 completed / zero failed requests**. This was a LOW test-harness defect, distinct from the earlier real transport timeouts.

## Performance interpretation

`tests/qa-performance.php` sends 50 public catalog requests at each concurrency level 1, 5 and 10, then 20 authenticated ledger-report requests at concurrency 5. It records p50/p95/max latency, throughput, response status and JSON validity. Every request targets the guarded temporary backend; no production stress or destructive data generation occurs.

The PHP built-in server serializes HTTP requests and the fixture dataset is small. Its throughput/p95 cannot qualify Apache/PHP-FPM, a load balancer, a large commercial dataset, mobile radio latency or a production SLO. No latency/throughput SLO has been approved. Measurements below retain this limitation and do not supersede the intermittent failure above.

Final instrumented rerun: 10 stations, 16 bookings and 16 financial journals in the temporary schema. Latencies are milliseconds; no transport/HTTP/JSON errors occurred in this rerun.

| Endpoint / client | Requests | Concurrency | p50 ms | p95 ms | Maximum ms | Requests/sec |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Catalog / PHP cURL multi | 50 | 1 | 161.55 | 627.97 | 804.87 | 4.35 |
| Catalog / PHP cURL multi | 50 | 5 | 781.19 | 1,551.68 | 1,694.48 | 4.54 |
| Catalog / PHP cURL multi | 50 | 10 | 1,126.58 | 1,215.49 | 1,296.40 | 7.72 |
| Admin ledger report / PHP cURL multi | 20 | 5 | 615.25 | 1,921.36 | 2,105.74 | 4.60 |

The independent ApacheBench comparison uses a public catalog read on the **same PHP development server**, not an Apache-hosted production benchmark. Its final console summary and exit status both pass. Warmup and host activity visibly affect these small samples; do not use them as a commercial capacity forecast.

## Accessibility, offline and notification boundaries

Flutter tests execute Android/iOS tap-target **guidelines** (on the desktop test renderer), tap labels and contrast rules for Arabic/English guest/customer/owner/driver screens at a 390×844 viewport and 200% text scale. These do not constitute native Android/iOS testing or manual TalkBack/VoiceOver certification. RenderFlex errors are checked; complete reading order and all content visibility require device review.

Existing Dio tests cover refresh races/replay, logout offline, uncertain mutation outcomes, stable idempotency keys and fixed-origin/redirect policy. GPS tests cover foreground/screen visibility, revocation and original-timestamp bounded offline recovery; PHP tests reject stale samples, unauthorized jobs and invalid transitions. Physical GPS accuracy, radio interruption and OS permission prompts remain untested.

Notification tests execute database outbox/worker delivery, deduplication, rollback, preference/revocation checks, safe deep links, OAuth signing, HTTP-v1 serialization and retry backoff through local transports. They **do not** verify delivery to Firebase, APNs, SMTP or a customer device.

## Reproduction and evidence handling

```powershell
$env:PUB_CACHE = "$PWD/.mobile-tools/pub-cache"
npm.cmd install --prefix .security-test/browser-tools --ignore-scripts --no-fund --no-audit playwright-core@1.65.1 axe-core@4.14.1
powershell -NoProfile -ExecutionPolicy Bypass -File tests/run-isolated.ps1 -QA -AdminBrowser -Flutter "$PWD/.mobile-tools/fast/flutter/bin/flutter.bat"
```

Use `-AdminOnly -QA -AdminBrowser` for affected journey/admin/accessibility reruns after a complete baseline. It still creates a fresh schema/auth setup and explicitly skips other business suites. Do not run two disposable runners concurrently on the same ports.

After such a setup, `tests/recheck-qa.ps1 -Target admin` reuses only its verified disposable schema for the browser/accessibility checks. `-Target load` runs the instrumented bounded load and an independent 50-request ApacheBench comparison. The helper validates both test environment files, schema prefix, loopback ports, ambient configuration and database datadir, uses the restricted HTTP runtime and stops only processes it starts. It does not initialize, migrate or reset a database. Do not use it with production configuration.

```powershell
cd mobile/clevo
../../.mobile-tools/fast/flutter/bin/flutter.bat analyze --no-pub
../../.mobile-tools/fast/flutter/bin/flutter.bat test --no-pub test/api_test.dart test/policy_test.dart test/app_test.dart test/tracking_test.dart test/accessibility_test.dart
cd ../..
composer audit --locked --format=json
npm.cmd audit --prefix tests/openapi --json
npm.cmd audit --prefix .security-test/browser-tools --json
node tests/qa-dependency-audit.cjs --maven
powershell -NoProfile -ExecutionPolicy Bypass -File tests/qa-android-artifact.ps1
```

The Maven audit requires an actual successful `:app:dependencies --configuration debugRuntimeClasspath --console=plain` report in ignored `.security-test/qa-android-dependencies.txt`. The Android artifact script requires a successful current ARM64 debug build. Configure the existing ignored local SDK/Java/Gradle cache paths before native builds; no release signing or production credentials are needed.

Sanitized summaries live in ignored `.security-test/qa-journeys.json`, `qa-performance.json`, `qa-dependency-*.json`, `qa-android-artifact.json` and `qa-admin-accessibility.json`. Full local logs/screenshots are not a distributable credentials bundle. Never publish private fixture JSON, environment files, cookies or tokens. This phase does not deploy or start the next implementation phase.

## Changed files

- `mobile/clevo/android/app/build.gradle.kts`: patched transitive dependency constraints; `mobile/clevo/test/accessibility_test.dart`: eight additional widget checks.
- `includes/shared_admin_sidebar.php`, `assets/js/admin-common.js`, `assets/css/admin-management.css`: accessible shared controls, focus, translated accessible names and contrast.
- `admin/financial-reports.php`, `admin/station-approvals.php`: readable report colors/captions and named return links. Those files and `admin/all-support-chats.php`, `security-center.php`, `notifications.php`, `manage-stations.php`, `manage-plans.php`, `manage-users.php`, `support-chat.php` allow browser zoom.
- `tests/qa-journeys.php`, `qa-performance.php`, `qa-dependency-audit.cjs`, `qa-admin-accessibility.cjs`, `qa-android-artifact.ps1`, `recheck-qa.ps1`, and `tests/run-isolated.ps1`: isolated journeys, measurements, scans, native artifact and affected reruns.
- `docs/QA_REPORT.md`, `docs/TEST_MATRIX.md`, `docs/REMAINING_ISSUES.md`: current evidence, scope and severity.

No new application schema migration, production migration/reset, platform/framework replacement, role removal or production integration activation was performed. Existing migrations were executed only in the disposable test schemas.

The full baseline and targeted reruns are retained separately: the initial aggregate failed on the corrected cancellation assertion; a focused attempt was interrupted and excluded; the next complete journey run passed but surfaced accessibility failures; a subsequent run still found residual contrast and intermittent transport failures; final browser/accessibility and load rechecks passed with the earlier timeout retained as an open finding. No original failure was deleted from the conclusion or reclassified as a production success.
