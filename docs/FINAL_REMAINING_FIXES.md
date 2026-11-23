# Final source review and remaining fixes

Review date: 2026-11-23 (Asia/Amman). This is an incremental review of the existing application, not launch authorization. Read with FINAL_INTEGRATION_RESULTS.md and LAUNCH_BLOCKERS.md. The five requested baseline reports were read; their historical results are not results of this run.

## Subsequent implementation and verification — 2026-11-23

The four earlier fixes below were already present in commit `51d91aa` and are preserved. The changes in this section supersede the older missing-feature statements below; final execution evidence is in [FINAL_INTEGRATION_RESULTS.md](FINAL_INTEGRATION_RESULTS.md). No production activation is authorized.

| ID / severity | Confirmed cause | Final change and limits |
| --- | --- | --- |
| FIX-01 HIGH | `includes/database.php` stored an ordinary SHA-256 body digest. Reauthentication requests include a password; a database copy could provide a fast offline guessing oracle despite password_hash() on the account. | Request digests use HMAC-SHA256 over the original body digest with a domain prefix and private APP_KEY. Migration011 adds an indexed digest-version marker; the protected PHP migrator upgrades existing digests without plaintext bodies, changing neither cached responses nor business/financial records. Idempotent replay/conflicts remain enforced. Readiness detects unkeyed rows. |
| FIX-02 MEDIUM | Flutter registration supported customers only, although the existing public PHP registration contract already supports owner documents and pending Admin approval. | `features/owner_onboarding.dart` adds native initial-owner registration through the **same** `POST /api/v1/auth/register` multipart contract. It accepts a document image, validates bounded size, keeps passwords in memory, does not log in/activate the owner, and warns about uncertain results without automatic duplicate submission. Both languages/themes use the existing design system. This introduces no new KYC approval policy, extra-station creation API or commercial membership. |
| FIX-03 MEDIUM | Owner subscription functionality had no native/API reporting path; commercially distinct owner membership/pricing rules remain undefined. | Added read-only `GET /api/v1/owner/subscription-usage` and `/owner/subscriptions`. It shows redemption state and covered service value for the authenticated owner's booking snapshots only; no customer-wide subscription, email or phone is exposed. It cannot create plans, activate subscriptions, charge or refund. A read-only scope was chosen after requesting clarification; owner SaaS billing/owner-controlled package pricing still needs explicit policy approval. |
| FIX-04 MEDIUM | Expiry reminders existed as an unscheduled legacy script, with user/title-based suppression that could suppress another subscription. | A bounded shared `clevo_subscription_expiry_batch()` uses per-subscription/end-date durable idempotency, transactional outbox events and UTC dates. It expires existing plans only, preserves wash balances, and progresses past previously notified rows. Added allowlisted `subscription-expiry` job and narrow SQL grant reference; actual production scheduling remains unactivated. |
| FIX-05 MEDIUM | Failed owner registration could save a private document before invalid/duplicate account validation and leave it unreferenced. | API/web error paths remove **only** strictly named, unreferenced private verification uploads. A committed account/station reference prevents cleanup, including when later mail work fails. This is cleanup of the current failed attempt, not deletion of legacy/shared KYC. |
| FIX-06 MEDIUM | Default catalog pagination counted an aggregate derived table, repeating service/review aggregation even without aggregate filters. | The unfiltered/nonaggregate count now uses stations directly; price/rating/mode/radius filters retain their aggregate path. Data, sorting and totals remain server-driven. No timeout, threshold or business constraint changed; historical intermittent timeouts still require target-stack diagnosis. |
| FIX-07 LOW/MEDIUM | Arabic screens displayed English wire states/purposes/roles in several histories and forms; unavailable profile/privacy services displayed payment-specific text. | Shared display-only state labels now cover the registered values in Arabic/English. Owner approval status is visible and unavailable station actions are disabled until approval. The generic 503 message is context-neutral. Unknown/new server values remain visible rather than being invented as success. This does not certify every legacy text or native assistive-technology flow. |

### Migration and secret handling

Drain writers and use the existing protected migration command with `--apply --maintenance` after an approved backup/rehearsal. Migration011 and its PHP backfill must precede this application version; bootstrap refuses service without it. Existing migrations 001–010 are unchanged. Re-running the protected apply path can harden late legacy writes. Keep APP_KEY stable during ordinary deployment; changing it without a reviewed data/key migration would invalidate encrypted data and keyed digests. Do not send it to SQL or print it.

Assess access to earlier DB dumps/backups containing unkeyed hashes; protect their custody/retention and decide on credential resets through the approved incident/rotation process. No compromise is asserted, no password or real credential is printed, and no live rotation is performed.

### Explicitly unchanged approval boundaries

The server still has one authorized scalar role per account. No multi-role membership or role-switch approval exists, so neither a client switch nor a new authorization model was added. Initial owner registration reuses an existing backend business flow; additional-station/resubmission policies and owner membership/pricing are separate product decisions. Production payments, stored value, settlements and externally delivered notifications remain gated.

No PHP dashboard, role, booking/payment service or historical record was removed. PHP/MySQL remain authoritative; there is still exactly one Flutter project. The sections below retain the earlier review record and should not be interpreted as current absence of the new initial-owner registration or read-only usage report.

### Changed-file inventory and regression mapping

| Area | Files changed/added in this pass | Relevant execution |
| --- | --- | --- |
| Digest hardening and safe rollout | `includes/database.php`, `includes/config.php`, `includes/operations.php`, `scripts/migrate-database.php`, new `migrations/011_idempotency_hash_security.sql` | Existing idempotency/API/booking/financial suites passed; 32/32 affected DB assertions include backfill, replay, conflicts and readiness |
| Registration upload cleanup | `includes/security/uploads.php`, `api/v1/auth.php`, `general/register.php` | Actual invalid/valid multipart tests and referenced-file protection in 32/32; auth/web176/176 |
| Subscription expiry | new `includes/subscription-reminders.php`, `scripts/plan_expiry_notifier.php`, `deploy/database-grants.sql.example`, job allowlist in `includes/operations.php` | Real disposable DB duplicate suppression, expiry balances, bounded progression; notification28/28; real scheduling/delivery blocked |
| Owner usage and catalog | `includes/api/owner.php`, `routes.php`, `http.php`, `catalog.php`; `scripts/generate-api-docs.php` | Owner isolation/non-PII report and catalog-equivalence checks; API 1870/1870; contract 1001/1001; unchanged timeout policy |
| Unified mobile features and localization | new `features/owner_onboarding.dart`, `ui/value_labels.dart`; `app.dart`, `core/navigation.dart`, `features/account.dart`, `auth.dart`, `bookings.dart`, `customer.dart`, `dashboards.dart`, `history.dart`, `owner.dart`, `payments.dart`, `privacy.dart`, `ui/common.dart` under `mobile/clevo/lib/` | Analyzer clean; 43 distinct default unit/widget tests verified across full/affected runs; separate live Dart/PHP test passed; current ARM64 debug build/artifact passed |
| Regression harness | new `tests/final-fixes.php`, `tests/run-isolated.ps1`, `tests/recheck-qa.ps1`; new mobile `test/owner_onboarding_test.dart`, `test/value_labels_test.dart`; corrected `test/owner_form_test.dart` fixture | Original failure records and exact reruns retained in [FINAL_INTEGRATION_RESULTS.md](FINAL_INTEGRATION_RESULTS.md) |
| Generated contracts/schema | `docs/openapi.v1.json`, `API_ENDPOINT_INVENTORY.md`, `DATABASE_SCHEMA.md`; `scripts/render-schema-docs.php` |175 operations/151 paths; schema 52 tables/521 columns; 180 DB assertions/526 SQL preparations; ERD regeneration produced no relationship change |
| Documentation | Current final reports/checklists plus reconciled `MOBILE_API_MATRIX.md`, `MOBILE_ARCHITECTURE.md`, `PRODUCTION_READINESS.md`, `REMAINING_ISSUES.md`, `DATABASE_CHANGES.md`, `OPERATIONS_MONITORING.md`, `API_INTEGRATION.md`, `SECURITY_CHANGES.md` | Dated baseline evidence retained; new evidence does not certify providers/devices/production |

Only local test fixtures were created. No production database migration, table reset, real credential/signing key insertion or infrastructure action was performed. See [FINAL_INTEGRATION_RESULTS.md](FINAL_INTEGRATION_RESULTS.md) for exact failures, reruns and blocked checks rather than interpreting individual table counts as a single all-green command.

## Earlier fixes retained from 51d91aa

| Change | Cause and resulting behavior | Regression |
| --- | --- | --- |
| Owner station phone | `includes/api/owner.php` omitted PhoneNumber from the owner station list; `features/owner.dart` read Phone. The API now returns PhoneNumber and the editor initializes from that field. | API field-presence assertion and owner form widget test. |
| Numeric forms | `ui/common.dart` accepted NaN, infinity and overflowing exponents, although JSON cannot represent them. Numeric validation now requires a finite parsed double before submission. | Four widget cases for NaN, Infinity, -Infinity and 1e999. |
| Owner availability translation | Status dropdown displayed English wire values in Arabic. Labels now translate while submitted Active/Busy/Closed values remain unchanged. | Analyzer and Flutter regression; native visual acceptance remains pending. |
| Web station hours | `owner/add-station.php` checked only digit shape, permitting impossible clocks. Hours/minutes/seconds now use clock ranges; absent optional coordinate and required clock inputs no longer produce undefined-key reads. | PHP syntax and integration cases for invalid hours, minutes, seconds and empty clock; existing valid multipart upload coverage retained. |

That earlier pass made no schema change. The current pass adds migration 011 as described above. Neither pass mutates live data, activates providers, elevates roles, creates a second app or deploys/publishes.

## Reconciliation of every baseline open issue

| Baseline ID | Current source/evidence | Disposition |
| --- | --- | --- |
| QA-R01 / PROD-02 | Local PHP/XAMPP/MariaDB test tooling; deploy references do not provision a maintained server. | Open: approved target-stack rehearsal required. |
| QA-R02 / PROD-03 | `includes/finance/` retains sandbox verification and commercial gates. | Open: merchant certification, funded subscriptions, refunds and settlement approval. |
| QA-R03 / PROD-05 | Existing single Android/iOS project, signing guards and release checklists. | Open: signed artifacts and native/device acceptance. |
| QA-R04 / PROD-03 | `core/notifications.dart`, native Maps configuration, SMTP/Firebase backend templates. | Open: approved projects/credentials, recipients/devices and real delivery. |
| QA-R05 / PROD-07 | Catalog aggregates services/reviews and supports bounded pagination/location filtering; existing performance harness retains transport errors. | Open: historical intermittent timeout has no established root-cause fix. No timeout or error threshold was relaxed; no speculative index added without target-volume evidence. |
| QA-R06 / PROD-08 | Initial native owner application and owner-scoped redemption reporting now use existing PHP services. Customer purchases and Admin plan/subscription administration remain unchanged. `navigation.dart` and server auth retain scalar roles. | Initial registration/reporting gaps addressed; commercial owner membership, additional-station/resubmission and multi-role policies remain open. |
| QA-R07 / PROD-08 | Bilingual/theme infrastructure and automated accessibility tests exist. Owner status labels repaired. | Open: every-screen visual review, raw server status translations, screen readers and full navigation/button acceptance are not certified. |
| QA-R08 / PROD-02 | Forward migrations and disposable recovery harness exist. | Open: Oracle MySQL/approved production version, restored data and performance qualification. |
| QA-R09 / PROD-01,06 | Private config, workers, monitoring, backups and signing templates exist under deploy/scripts. | Open: actual approved infrastructure, secret rotation evidence, offsite recovery and alerts. |
| QA-R10 | Existing exports use print HTML. | Open if binary PDF/Arabic print layout is a launch requirement. |
| PROD-04 | Policy/deletion service remains gated by approved legal/retention configuration. | Open: operator/contact, retention, processor cleanup and approved public documents. |
| PROD-08 additional | Erasure workers and job locks exist; multi-node scheduling and retained-data expiry require operational policy. | Open: production topology and retention acceptance. |

## Decisions that cannot be inferred safely

Initial native owner onboarding now submits the existing multipart registration contract, creating the same closed/pending station and pending owner for email/Admin approval. Existing web behavior is retained. No new KYC decision, resubmission/extra-station policy or alternate approval path was invented.

Commercial owner subscription management remains ambiguous: customer-plan administration and an owner platform membership require different financial rules. A clarification was requested. Read-only redemption reporting for the owner's own bookings was added within existing owner reporting authority; customer purchases remain customer-only and plan administration remains Admin-only. There is no approved owner membership/pricing model, and no financial privilege expansion was implemented.

Multi-role membership is not approved by the repository. No membership table or server context switch exists. Switching a client role would violate the current account model; no such switch was added.

## Shared backend and configuration review

`core/api.dart` supplies the shared Dio transport to Customer, Owner and Driver features. `core/config.dart` supports CLEVO_API_URL and requires HTTPS outside explicit loopback debug use; release validation rejects placeholder/local endpoints. Existing native Firebase and Maps hooks, backend SMTP/service-account configuration, private signing examples and Android/iOS checklists are retained. Real credentials must be supplied through approved private configuration. Payment credentials alone intentionally cannot unlock production processing.

Review does not establish exhaustive correctness of every action or screen. Outstanding acceptance work is explicitly retained rather than inferred from a passing subset of tests.
