# CLEVO QA test matrix

Date: **2026-11-21**. `PASS-local` means an executed test against the isolated backend or test renderer. `BLOCKED` means the required environment/approval is absent. It does not mean passed. One Flutter app contains customer, partner/owner and driver roles.

## Required journeys

| ID | Journey and asserted evidence | Automated source | Current result / boundary |
| --- | --- | --- | --- |
| J01 | API registration; login denied before verification; consume encrypted-queue verification grant; reject replay; login; persist profile | `tests/qa-journeys.php` scenario 1; `tests/integration.php` | PASS-local. SMTP/email arrival and native registration UI not qualified. |
| J02 | Owner API creates bilingual service, price and duration; customer API sees exact persisted values | `tests/qa-journeys.php` scenario 2; `tests/admin.php`; Dart live API | PASS-local. Station itself is an approved test fixture; this is not owner onboarding/KYC certification. |
| J03 | Customer books with an injected fake price; backend quotes service plus fee; same-key retry yields same ID; owner lists and accepts; customer sees status; inbox event exists | `tests/qa-journeys.php` scenario 3; `tests/booking.php`; `test/live_api_test.dart` | PASS-local. No real FCM delivery asserted. |
| J04 | Checkout pending without earnings; matching signed local sandbox webhook confirms amount; replay has one capture; API returns verified state | `tests/qa-journeys.php` scenario 4; `tests/payments.php`; `tests/admin-financial.php` | PASS-local with local provider transport. Real provider sandbox/acquirer acceptance BLOCKED. |
| J05 | Owner assigns; only assigned driver sees job; reject skipped stage; driver accepts and heads to pickup | `tests/qa-journeys.php` scenario 5; `tests/logistics.php`; API regression | PASS-local. Native push receipt/navigation/permission dialogs not qualified. |
| J06 | Timestamped accurate foreground location stored during active job; owning customer reads fresh location; stranger denied | `tests/qa-journeys.php` scenario 6; `tests/logistics.php`; Flutter tracking tests | PASS-local. Native map/GPS hardware and real-device background behavior BLOCKED. |
| J07 | Customer-code pickup; arrival; owner service completion; return; customer-code delivery; all four role history APIs agree; exact earnings/commission; terminal GPS hidden | `tests/qa-journeys.php` scenario 7; driver/admin/finance suites | PASS-local. Native history UI/device acceptance not qualified. |
| J08 | Paid cancellation is blocked until verified refund; confirmed pre-pickup refund atomically cancels; repeat terminal request cannot create history/money twice | `tests/qa-journeys.php` scenario 8; `tests/payments.php`; `tests/booking.php` | PASS-local after correcting the initial test expectation. External refund certification BLOCKED. |
| J09 | Plan payment pending gives no entitlement; verified payment/replay gives one funded subscription; eligible booking consumes actual benefit | `tests/qa-journeys.php` scenario 9; `tests/payments.php` funded quota/exhaustion/reversal tests | PASS-local in the combined rerun and financial regression; real provider subscription purchase BLOCKED. |
| J10 | Customer booking/payment IDOR, unassigned job access, foreign owner station/service mutation, wallet owner spoofing, Admin role denial | `tests/qa-journeys.php` scenario 10; `tests/api.php`; authentication/admin suites | PASS-local in the combined rerun and existing API/security regression. |

## Additional coverage

| ID | Test area | Executed evidence | Limits / remaining issue |
| --- | --- | --- | --- |
| A01 | API method/auth/role/schema/contracts | 958 contract assertions; 1,821 API assertions over 647 HTTP requests / 157 registered operations | Not a native UI test. |
| A02 | Auth and sessions | 47 units, 172 integration checks; JWT/refresh replay, role/IDOR, CSRF, reset/verification, throttle, CORS, upload checks | Production identity migration/credential rotation needs operational sign-off. |
| A03 | Database integrity/migrations/recovery | 178 checks, 477 SQL statements prepared; restricted runtime privileges; backup/restore and recovery paths | MariaDB 10.4.32 tested. Deployment MySQL-version restored-copy test still needed. |
| A04 | Booking concurrency | Real competing processes in booking/database suites, row locks/capacity/idempotency and financial races | Local DB concurrency is not production capacity/soak qualification. |
| A05 | Webhooks/financial safety | 161 payment and 66 admin-financial checks; signatures, wrong amounts/currency/merchant, replays, out-of-order capture/refund, immutable postings, recognition, subscriptions | No real provider contacted. |
| A06 | Driver/location privacy | 22 driver checks plus API and combined journey coverage; single-use handoffs, assignment ownership, stale/replayed coordinates, retention, terminal clearing | Maps SDK and permission/GPS field qualification missing. |
| A07 | Notifications | 28 worker/API checks; local OAuth/FCM serialization, preferences, opt-out, revocation, rollback, dedup and backoff | FCM/APNs/email device delivery BLOCKED. |
| A08 | Flutter client/widget regression | 21 tests: shared client, authorization/navigation, AR/EN, theme, safe links and tracking lifecycle | Desktop test renderer with test adapters. |
| A09 | Accessibility | Eight new Flutter cases; Admin axe 24 page/language/theme combinations with zero confirmed violations after fixes, plus keyboard upload activation | Some contrast evaluations remain manual/incomplete; TalkBack/VoiceOver and full WCAG review not run. |
| A10 | Flutter-to-PHP | 1 live isolated Dart test passed using actual HTTP, not a mocked transport | It uses an in-memory test token vault; secure-vault behavior is covered separately, not an OS keystore acceptance test. |
| A11 | Android | Dependency-patched debug rebuild + 16/16 artifact checks and 10 native XML parses passed | No connected device/emulator; native integration NOT RUN. Debug artifact is not a signed store release. |
| A12 | iOS | Source/XML syntax only where included in native metadata checks | Build/device integration NOT RUN on Windows. |
| A13 | Offline/network faults | Dio transport-fault tests, unknown result recovery, single-flight refresh/logout races; GPS queue/backlog and notification retries | Physical airplane-mode/network handover field testing NOT RUN. |
| A14 | Load | Initial/diagnostic 170-request runs passed; one run had 3/50 catalog timeouts at concurrency 10; independent ApacheBench 50/50 with zero failed requests | Intermittent failure retained in QA-R05. No production SLO, sustained soak or production topology tested. |
| A15 | Dependency advisories | Composer, 135 Pub coordinates, npm tool locks and 144 resolved Android Maven coordinates have no current advisory matches | Four initial Maven matches repaired and rescanned. No comprehensive iOS/embedded native SBOM. |
| A16 | Admin regression | 158 backend, 66 financial and 52 browser checks; 5 synthetic screenshots; CSV download and authorized print HTML | Browser PDF print dialog/binary PDF not certified. |
| A17 | Apache | 16/16 source protection/denies/rewrite checks and 2/2 bearer/API assertions passed | Does not patch old Apache itself. |

Final executed results, harness failures and exact release gates are maintained in [QA_REPORT.md](QA_REPORT.md) and [REMAINING_ISSUES.md](REMAINING_ISSUES.md). Earlier phase reports are historical evidence, not substitutes for this run.
