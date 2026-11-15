# Security test results

Executed: 2026-11-15. Scope: this authentication/security phase. Tests used only synthetic accounts and generated credentials in an isolated database; no production CLEVO database, real mail recipient or payment provider was used. Secret values, tokens and OTPs are deliberately absent from this report.

## Final results

| Check | Result | Evidence / boundary |
|---|---|---|
| Security unit suite | **47/47 passed** | `php tests/security.php`; JWT signatures/claims/expiry/algorithm, password policy/hashing, role aliases, environment fail-closed behavior, CORS allowlist, trusted HTTPS forwarding, CSRF, output contexts, mail encryption/tamper detection and upload validation. |
| Database + HTTP integration suite | **146/146 passed** | `tests/integration.php` through `tests/run-isolated.ps1`; real MariaDB, actual PHP HTTP requests, restricted runtime DB identity and two concurrent refresh processes. |
| Apache server/rules suite | **11/11 passed** | `tests/apache-rules.ps1`; actual isolated Apache/mod_php, configuration syntax, lower/upper-case VCS denial, environment/dump/migration/worker/private-file denial, uploaded-PHP denial and real `/api/v1` rewrite/method/preflight behavior. Local HTTP, not production TLS. |
| Credential source scan | **89 files checked; 0 findings** | `php tests/scan-secrets.php`; recursive first-party PHP/JS/SQL scan, excluding vendor/tests/docs/generated test artifacts; reports rule/location only. Not a forensic guarantee about Git history or arbitrary secret encodings. |
| PHP syntax | **90 first-party PHP files; 0 failures** | `php -l` over application, migration and test PHP, excluding vendor and generated test data. |
| JavaScript syntax | **3 application assets passed** | `node --check` on `assets/lamea.js`, `service-translations.js`, `translations.js`. Test helper `check-inline-js.js` also passed. Generated login/admin/driver/owner page JavaScript is compiled during integration checks. |
| Composer manifest / platform | **Passed** | `composer validate --no-check-publish --no-interaction`; `composer check-platform-reqs`; declared PHP/extensions satisfied in test environment. |
| Composer advisory audit | **No advisories or abandoned packages reported** | `composer audit --locked --no-interaction --format=json`; point-in-time result. A nonfatal cache-directory write warning occurred; audit completed without cache. |
| Patch whitespace | **Passed** | `git diff --check`. |

The unit and integration suites contain **193 assertions**; Apache adds 11 server checks. These counts are not a security certification or proof that untested features work.

## Integration coverage

- Additive schema migration on the supplied base-table definitions, ordinary rerun idempotence, current schema assertions, and a database runtime account explicitly denied CREATE while all application HTTP checks succeed. Eleven legacy schema helper entry points run with DML-only permissions.
- All four roles plus legacy `User` normalization. Public API and existing PHP registration cannot choose admin/driver/unknown roles. Existing customer/owner/admin/driver web login redirects/dashboard access remain operational.
- JWT-authenticated own-account access; malformed/no bearer rejection; role gate failures; own versus other booking, wallet, inbox, station services and driver job; another owner's service cannot be edited; alternate legacy review endpoint denies customer writes.
- Refresh rotation, used-token replay, revocation of predecessor/successor access/refresh credentials, logout revocation, expiry, password-reset revocation, role-change rejection and a real two-process contention test. Exactly one refresh succeeds; the replay invalidates the entire family durably.
- Request-only reset bypass denied; correct OTP grants reset; wrong OTP budget and expiry enforced; reset/email grants are single-use; weak/stale flows are denied; password reset invalidates a staged recovery-address change; verified email replacement revokes prior sessions.
- Hash-only refresh/OTP/task credential storage; encrypted queued mail; driver code exchange/use/expiry/ownership; task-code session cannot inspect a different job assigned to the same driver; current web tracking respects the selected assignment.
- Browser CSRF on login/location/logout and generated forms; session identifier rotation; HttpOnly/SameSite/no-store headers; admitted CORS preflight still cannot authenticate; spoofed forwarding does not bypass login throttles; registration/recovery/verification/refresh persisted limits.
- Actual multipart station-image upload, private storage/public-image delivery, authorization on private verification downloads, executable-extension/polyglot and size checks, protected server routes and generated JavaScript syntax. Driver terminal-state revival/skipping/duplicate completion are denied and loyalty is awarded once.
- Recipient-specific admin notification reads. Owner/admin/customer/driver role boundaries. Null-transport mail-worker processing clears encrypted payload after processing; no real SMTP delivery is inferred.

## Failures encountered and corrected

1. An early unit fixture attempted HS384 signing with an undersized test key. The library correctly rejected it; the fixture now uses sufficient random key material and asserts that the application rejects the wrong algorithm.
2. Initial web tests could not persist sessions in the host's configured temporary directory. Session storage now uses the protected configured private directory and startup fails closed instead of continuing without a session. Temporary upload storage is explicitly configured in the test server and documented for production.
3. A multipart test received a PHP startup notice before JSON because the host upload temporary path was unavailable. A private writable upload temp directory in the SAPI configuration corrected the response; the real multipart test passes.
4. The compatibility migration initially leaked a local variable into its CLI caller, causing SQL error 1064. It is now enclosed in a local function scope; fresh migration plus rerun and restricted-runtime tests pass.
5. A cookie-header assertion reused an existing anonymous cookie jar, so no new Set-Cookie header was emitted. The fixture now uses a new per-run jar and verifies the actual new-session cookie attributes.
6. The first isolated Apache cleanup command targeted a nonexistent Windows service. The test harness now uses single-process mode and stops only its own process; the previous isolated process was removed with scoped authorization. The corrected server test passed and its port was confirmed closed.

There are **no outstanding failed assertions** in the final recorded suites. Earlier failed runs are not represented as passing results.

## Reproduction

On this Windows/XAMPP workspace:

```powershell
php tests/security.php
php tests/scan-secrets.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/run-isolated.ps1
composer validate --no-check-publish --no-interaction
composer check-platform-reqs
composer audit --locked --no-interaction --format=json
```

`run-isolated.ps1` initializes/reuses a **dedicated loopback test database instance**, generates protected ignored configuration, creates a fresh `clevo_security_test_...` schema and restricted random runtime identity, starts a local PHP HTTP server, runs the suites and checks Apache when its PHP module is available. Tests fail rather than execute against a non-testing/non-matching database. Synthetic test data/credentials are kept under ignored `.security-test` for local diagnosis, never committed or copied into production. The wrapper stops processes it starts; a pre-existing dedicated test database instance is not stopped by the wrapper.

Defaults: MariaDB binaries `C:\xampp\mysql\bin`, loopback DB port 13379, PHP HTTP port 18089, isolated Apache port 18090. `-MysqlBin`, `-DatabasePort`, `-HttpPort` can override database/PHP values; `-SkipApache` explicitly skips the optional Apache suite. A standalone Apache check needs the test database/runtime configuration to remain available; normally run it through the wrapper before cleanup. No real SMTP setup is needed; null transport is allowed only outside production.

## Test environment and limits

Test runtime: PHP 8.2.12, Node.js 24.22.3 and isolated MariaDB 10.4.32 with InnoDB. These local versions are test observations, not a recommendation to deploy old patches. The effective production MySQL version, current patched runtime, private OS ACLs, actual hosting TLS/proxy configuration and external provider credentials still require deployment validation.

Not executed: real SMTP delivery/bounces/DNS authentication; external credential rotation/provider activity review; production database migration/restore; production TLS/proxy/edge limiter; complete browser visual interaction/AR-EN/theme comparisons; iOS/Android secure storage, app links or physical-device background behavior; broad load/penetration/malware scanning; unresolved financial settlement/refund/plan economics from the audit. No skipped provider/device capability is counted as a passing test.
