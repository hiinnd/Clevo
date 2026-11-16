# CLEVO unified mobile verification

Date: 2026-11-16. Environment: Windows; Flutter 3.50.0 / Dart 3.14.0; Temurin Java 21; Android SDK 36 / NDK 28.2.13676358. Database tests use MariaDB 10.4.32 in the dedicated disposable workspace.

## Results

| Check | Latest completed result |
|---|---|
| Flutter unit/widget/security tests | 21 passed, 0 failed; explicit four-suite run |
| Flutter static analysis | Passed: no issues found |
| Live Dart → PHP → MySQL integration | Passed: 1 live end-to-end test, 0 failed; isolated mobile-only rerun |
| PHP security units | 47/47 |
| Credential source scan | 143 application source files, 0 findings |
| OpenAPI/runtime contracts | 906/906 |
| PHP authentication/web security integration | 172/172 |
| REST API integration | 1,740/1,740 assertions, 620 HTTP requests, 148 registry operations |
| Shared booking workflow | 121/121 |
| Financial/webhook integration | 161/161; no external provider contacted |
| Driver/logistics | 22/22 |
| Notifications | 28/28; no SMTP, Firebase or APNs message sent |
| Database migration/concurrency/recovery | 178/178; 499 SQL statements prepare successfully |
| Apache source protection / bearer handling | 16/16 source/routing checks; 2/2 bearer checks |
| PHP syntax / whitespace | 148 files, 0 syntax failures; `git diff --check` passed |
| Android APK | Passed: current-source ARM64 debug APK, signature and merged manifest verified |
| Missing release-signing configuration | Passed: release task graph refused execution in dry-run mode |
| Native XML/plist syntax | 13 files passed using .NET XML validation |
| iOS build | Not run: macOS/Xcode unavailable |

The full PHP run passed every PHP/Apache suite; its initial Dart assertion mismatch was corrected and the focused live rerun passed. The final Android rebuild and artifact checks passed. No failing automated functional check remains from these completed runs. External/device qualification remains outstanding as described below.

## Android artifact

- File: `mobile/clevo/build/app/outputs/flutter-apk/app-debug.apk`
- Application: CLEVO, `com.clevo.clevo`, version `1.0.0+1`.
- Size: 105,753,621 bytes. ARM64 only; its ABI metadata and bundled Flutter engine agree.
- SHA-256: `87D9A82BFBE57502F632F4EC2CABD87094F7C6DF33C3D56539DD2169EDA34BD2`.
- Minimum Android API 24; target/compile API 36.
- SDK debug certificate; APK Signature Scheme v2 verified. This is a test artifact, not a signed commercial release.
- Merged manifest: backups disabled, global cleartext disabled, debug-only local network configuration present. Internet, coarse/fine foreground location and notification permissions are present; background-location and location foreground-service permissions are absent.
- ZIP inspection found no environment files, private signing configuration, mobile test fixture credentials or service-account files bundled.
- No physical-device installation or UI acceptance test was performed; the desktop widget tests do not replace native-device qualification.

## Coverage

The mobile tests cover single-flight access refresh; retention of mutation body/idempotency key; rejection of refresh replay; secure-vault clearing; logout races during refresh and encrypted writes; offline sign-out; unknown mutation outcomes; fixed-origin requests; redirect restrictions; HTTPS/local-development configuration; role guards and Admin exclusion; allowlisted notification links and provider checkout URLs; authorized foreground GPS; and bounded original-timestamp offline recovery.

Flutter widget tests verify Customer, Owner and Driver dashboard selection from the authenticated server profile, blocked navigation into other roles, Arabic RTL and restored dark mode. GPS widget tests verify backgrounding during a pending position read prevents transmission, covering the tracking screen stops sharing, and revoked job authorization stops collection.

The live Dart suite uses real PHP/MySQL endpoints for role login, catalog/services, availability, server pricing, booking idempotency, owner acceptance synchronization, customer IDOR rejection, driver role/job isolation, tracking policy, notification preferences, wallet gating and safe online-payment unavailability. Its transport is not mocked. Normal standalone Flutter testing explicitly skips that suite without disposable fixture configuration; the isolated runner supplies it separately.

## Failures found and handled

1. Initial role widget tests exposed a GoRouter route-state context error during transitions. Reading the router's current location fixed it; the role tests then passed.
2. Startup of the previous disposable database directory timed out twice before migrations/tests. That directory was preserved as ignored `mysql-preserved-mobile-*` data. A new disposable directory initialized successfully. No old test data or production database was deleted.
3. The first full PHP run passed 171/172 security integration checks. The legacy registration check received 403 using its login-form CSRF fixture. It now reads the challenge from the registration form it actually submits; the rerun passed 172/172, retaining the strict expected response and no-account-created assertion. No application authorization check was relaxed.
4. Initial dependency solving rejected incompatible Firebase major versions. The committed lockfile now resolves compatible packages. Flutter 3.50 Material imports and analyzer findings were corrected.
5. The first Android build was deliberately stopped while provisioning tools; a subsequent build uses verified dependencies and bounded Gradle memory/workers. Java dependency deprecation/source-8 compiler warnings are recorded as warnings, not successful security verification.
6. The first live Dart run incorrectly expected HTTP 403 for an unassigned driver's generic booking lookup. The existing API correctly conceals that object with 404. The assertion now requires 404; the separate forbidden owner-endpoint check still requires 403. A mobile-only isolated rerun avoids repeating already-passing PHP business suites.
7. The current-source Android rebuild caught a Kotlin DSL name conflict in the new private-signing configuration (`java.util.Properties`). An explicit `Properties` import corrects that conflict; the final rebuild verifies the fix. An optional Python XML-check command could not run because Python is absent; native XML validation uses .NET instead.
8. Artifact inspection found dependency JNI libraries advertising extra CPU architectures in an ARM64-engine test build. The installed Flutter 3.50 Gradle plugin resets native filters to all supported ABIs; the application now uses its custom-filter option and the explicit target list, while preserving split/multi-ABI builds. The final artifact is rechecked for ARM64 consistency. An initial release-gate probe did not reach the expected guard message; the corrected Flutter release-task dry run passed the missing-signing-configuration guard without executing a release task.

## Isolation and outstanding release gates

The runner verifies a disposable database prefix, loopback host and workspace datadir. It uses PHP port 18089 and MySQL port 13379, never production port 3306. Fixture accounts/passwords are random and stored only in ignored private test storage; no token or password is printed or supplied in build defines. No application schema migration, production seed, real message or commercial charge is performed in this client phase.

No approved Firebase/APNs native configuration or restricted Maps SDK keys were supplied. Native map rendering, push delivery, OS permission behavior and GPS accuracy/offline field testing require physical Android/iOS devices. Maps and Firebase default to disabled. No production payment-provider approval/credentials, stored-value authorization or settlement approval is supplied; unavailable checkout is an expected safety gate, and a browser return never marks anything paid.

The debug artifact defaults to the deliberately unconfigured `clevo.invalid` API host. Build with the actual HTTPS API configuration before connecting it to your deployment. Release signing, Apple provisioning, approved launcher/store artwork, privacy declarations and real-device acceptance tests remain mandatory. Windows cannot compile or sign iOS; macOS instructions and iOS source/configuration are provided without claiming a successful iOS build.

Multi-role memberships/context switching, mobile owner station onboarding and owner subscription administration remain backend gaps; existing web workflows are preserved. See `MOBILE_ARCHITECTURE.md` and `MOBILE_API_MATRIX.md`. Passing local tests does not establish production readiness.
