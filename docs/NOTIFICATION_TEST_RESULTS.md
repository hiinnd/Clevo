# Notification and logistics validation

Executed 2026-11-16 (Asia/Amman). The final complete isolated runner exited successfully using PHP 8.2.12, MariaDB 10.4.32, local PHP HTTP 18089 and Apache 18090, database port 13379. These are observed test versions, not production patch-level recommendations. The dedicated workspace datadir was verified before testing. No production database, customer device, Firebase/APNs account or real email recipient was used.

| Check | Observed result |
|---|---|
| Security unit | 47/47 passed |
| Credential source scan | 143 files, zero findings |
| OpenAPI/runtime contract | 906/906 passed |
| Authentication/security HTTP integration | 172/172 passed |
| REST API regression | 1,740/1,740 passed; 620 helper requests, 148 registry operations |
| Shared booking workflow | 121/121 passed; 18 helper requests |
| Financial/webhook regression | 161/161 passed; 45 helper requests; no provider contacted |
| Driver/logistics | 22/22 passed |
| Notification service | 28/28 passed; local injected transports only |
| Database migrations/concurrency/backup/restore | 178/178 passed |
| Complete static PHP SQL compatibility | 499 statements prepared; zero candidates requiring fragment review |
| Apache route/access rules | 14/14 passed |
| Apache bearer API | 2/2 passed |
| Generated OpenAPI / Redocly | Valid, 159 operations across 138 paths |
| PHP syntax | 147/147 first-party files |
| JavaScript syntax | 4/4 standalone assets; affected PHP inline scripts parsed in HTTP regression |
| Composer manifest/platform/advisory checks | Passed; no advisories or abandoned packages reported |
| OpenAPI development-tool audit | Zero vulnerabilities reported |
| SMTP Message-ID helper | Sender-domain validation and stable retry identity passed |
| Documentation links / whitespace | Passed |

The notification suite exercises encrypted and deduplicated registration, transactional rollback, unique channel jobs, actual worker claims/acknowledgement, opt-outs, sanitized failure/backoff, session revocation, allowlisted deep links, HTML escaping and Arabic responsive markup, OAuth signing with an ephemeral RSA key and typed HTTP-v1 request construction. It also calls real local HTTP device registration/revocation, preferences, history and role-bound monitoring APIs. No credential values are included in reports.

The logistics suite exercises assigned-only reads/writes, monotone stages, active-job GPS, accuracy/time/sequence checks, replay conflicts, wrong assignment/background/stale rejection, customer handoff proof, durable failed-code attempts, owner completion, immediate terminal cache shutdown and GPS retention. Existing regression fixtures now use the real shared handoff workflow rather than directly skipping stages.

Intermediate failures were resolved before the clean run:

1. Existing disposable MariaDB crash recovery exceeded the earlier 45-second startup deadline. A 180-second bounded readiness window allowed that dedicated instance to recover; no schema/reset action was run before readiness. The old instance/data was retained; no production service was contacted.
2. Legacy-adoption code referenced a marketplace helper unavailable in standalone database/integration bootstrap. It now performs its ownership/account/station checks directly using the already-locked booking and shared identity service.
3. The old mail test expected null transport to mark messages sent. It now verifies encrypted messages remain queued without a delivery claim when sending is disabled.
4. One intermediate run used a disposable schema created before the new location-phase column was added. A fresh complete migration/rehearsal run resolved the schema/code mismatch. No live schema was patched to hide the failure.
5. Windows RSA fixture generation required an explicit local OpenSSL configuration path. The test now passes the installed PHP OpenSSL config when generating its ephemeral key. No test private key is committed or printed.

No unresolved failure in the final listed suites is treated as passed. Syntax, dependency and whitespace verification is reported alongside the delivery documentation; HTML markup assertions and inline JavaScript parsing are not email-client visual certification.

Not tested: actual Firebase/APNs/SMTP acceptance or display, Apple/Android notification or location permissions, Flutter compilation/device navigation, real background location (disabled), production MySQL/proxy/SAPI, provider quotas, email-client rendering, sender SPF/DKIM/DMARC, bounce webhooks, independent penetration/load testing or offsite production recovery. Real credentials, sender-domain/Firebase/APNs setup and deployment rehearsal remain release gates.

Reproduce through `powershell -NoProfile -ExecutionPolicy Bypass -File tests/run-isolated.ps1`. It creates fresh disposable database names, runs migrations and all relevant suites, and shuts down only its own processes. Composer offers `test:notifications`; invoke it only within that prepared isolated environment. Do not point individual tests at a live database.
