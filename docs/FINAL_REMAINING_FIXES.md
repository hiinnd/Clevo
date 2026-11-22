# Final source review and remaining fixes

Review date: 2026-11-23 (Asia/Amman). This is an incremental review of the existing application, not launch authorization. Read with FINAL_INTEGRATION_RESULTS.md and LAUNCH_BLOCKERS.md. The five requested baseline reports were read; their historical results are not results of this run.

## Confirmed fixes

| Change | Cause and resulting behavior | Regression |
| --- | --- | --- |
| Owner station phone | `includes/api/owner.php` omitted PhoneNumber from the owner station list; `features/owner.dart` read Phone. The API now returns PhoneNumber and the editor initializes from that field. | API field-presence assertion and owner form widget test. |
| Numeric forms | `ui/common.dart` accepted NaN, infinity and overflowing exponents, although JSON cannot represent them. Numeric validation now requires a finite parsed double before submission. | Four widget cases for NaN, Infinity, -Infinity and 1e999. |
| Owner availability translation | Status dropdown displayed English wire values in Arabic. Labels now translate while submitted Active/Busy/Closed values remain unchanged. | Analyzer and Flutter regression; native visual acceptance remains pending. |
| Web station hours | `owner/add-station.php` checked only digit shape, permitting impossible clocks. Hours/minutes/seconds now use clock ranges; absent optional coordinate and required clock inputs no longer produce undefined-key reads. | PHP syntax and integration cases for invalid hours, minutes, seconds and empty clock; existing valid multipart upload coverage retained. |

No schema change, live data mutation, provider activation, role elevation, second app or deployment was performed.

## Reconciliation of every baseline open issue

| Baseline ID | Current source/evidence | Disposition |
| --- | --- | --- |
| QA-R01 / PROD-02 | Local PHP/XAMPP/MariaDB test tooling; deploy references do not provision a maintained server. | Open: approved target-stack rehearsal required. |
| QA-R02 / PROD-03 | `includes/finance/` retains sandbox verification and commercial gates. | Open: merchant certification, funded subscriptions, refunds and settlement approval. |
| QA-R03 / PROD-05 | Existing single Android/iOS project, signing guards and release checklists. | Open: signed artifacts and native/device acceptance. |
| QA-R04 / PROD-03 | `core/notifications.dart`, native Maps configuration, SMTP/Firebase backend templates. | Open: approved projects/credentials, recipients/devices and real delivery. |
| QA-R05 / PROD-07 | Catalog aggregates services/reviews and supports bounded pagination/location filtering; existing performance harness retains transport errors. | Open: historical intermittent timeout has no established root-cause fix. No timeout or error threshold was relaxed; no speculative index added without target-volume evidence. |
| QA-R06 / PROD-08 | `routes.php` exposes owner operations but no station creation or owner subscription management; customer subscriptions and Admin plan/subscription administration exist. `navigation.dart` and server auth enforce scalar roles. | Open product decisions: see below. |
| QA-R07 / PROD-08 | Bilingual/theme infrastructure and automated accessibility tests exist. Owner status labels repaired. | Open: every-screen visual review, raw server status translations, screen readers and full navigation/button acceptance are not certified. |
| QA-R08 / PROD-02 | Forward migrations and disposable recovery harness exist. | Open: Oracle MySQL/approved production version, restored data and performance qualification. |
| QA-R09 / PROD-01,06 | Private config, workers, monitoring, backups and signing templates exist under deploy/scripts. | Open: actual approved infrastructure, secret rotation evidence, offsite recovery and alerts. |
| QA-R10 | Existing exports use print HTML. | Open if binary PDF/Arabic print layout is a launch requirement. |
| PROD-04 | Policy/deletion service remains gated by approved legal/retention configuration. | Open: operator/contact, retention, processor cleanup and approved public documents. |
| PROD-08 additional | Erasure workers and job locks exist; multi-node scheduling and retained-data expiry require operational policy. | Open: production topology and retention acceptance. |

## Decisions that cannot be inferred safely

Native station onboarding is conditional in the request. Existing web onboarding creates a closed/pending station and changes the owner account to pending for review. That flow is not evidence of approved native KYC/document/resubmission requirements. It remains available; no alternate mobile approval path was invented.

Owner subscription management is ambiguous: customer-plan administration, an owner platform membership and read-only station redemption reporting have different authorization, financial and privacy rules. Current source gives customer purchases to customers and plan administration to Admin. There is no approved owner membership model. A clarification was requested; implementation requires a defined model and approved permissions. Existing customer funding and Admin restrictions remain enforced.

Multi-role membership is not approved by the repository. No membership table or server context switch exists. Switching a client role would violate the current account model; no such switch was added.

## Shared backend and configuration review

`core/api.dart` supplies the shared Dio transport to Customer, Owner and Driver features. `core/config.dart` supports CLEVO_API_URL and requires HTTPS outside explicit loopback debug use; release validation rejects placeholder/local endpoints. Existing native Firebase and Maps hooks, backend SMTP/service-account configuration, private signing examples and Android/iOS checklists are retained. Real credentials must be supplied through approved private configuration. Payment credentials alone intentionally cannot unlock production processing.

Review does not establish exhaustive correctness of every action or screen. Outstanding acceptance work is explicitly retained rather than inferred from a passing subset of tests.
