# CLEVO production readiness

Current reconciliation **2026-11-23**: [final fixes](FINAL_REMAINING_FIXES.md),[executed integration evidence](FINAL_INTEGRATION_RESULTS.md),[launch blockers](LAUNCH_BLOCKERS.md) supersede the dated implementation gaps/counts below. Native initial-owner application,owner-scoped subscription usage,expiry processing,upload cleanup,catalog counts and display labels were improved; additive migration011 plus a protected HMAC backfill is now required. The old local runtime has a CRITICAL version blocker; production is still **NO-GO**. No approved host/domain exists and no infrastructure was changed.

Prepared **2026-11-22 (Asia/Amman)**. Decision: **NOT APPROVED FOR COMMERCIAL LAUNCH**. The operator confirmed there is no approved production environment. This phase prepares code, private-configuration templates, tests and runbooks; it does not deploy, publish, change DNS/infrastructure or rotate live credentials. PHP/MySQL, existing PHP dashboards and ONE Flutter application with customer/owner/driver navigation are retained.

## Deliverables and evidence

- [Deployment/hosting/HTTPS/rollback](DEPLOYMENT.md), [backup and recovery](PRODUCTION_BACKUP_RECOVERY.md), [monitoring/workers/integrations](OPERATIONS_MONITORING.md).
- [Android release checklist](ANDROID_RELEASE_CHECKLIST.md), [iOS release checklist](IOS_RELEASE_CHECKLIST.md), [privacy/deletion operations](PRIVACY_OPERATIONS.md).
- [Executed test results and limitations](PRODUCTION_TEST_RESULTS.md); original [QA gates](REMAINING_ISSUES.md) remain applicable. Historical QA results are not substituted for this phase's execution.
- Unactivated templates under `deploy/`: environment-specific examples, Apache HTTPS/FPM/PHP configuration, DB grants, systemd jobs/backups, restic wrapper, Prometheus/alerts/logrotate. No real domains, passwords, signing keys or provider credentials are included.
- Migration 010 and the preceding migration 009 are applied to disposable databases only; schema/ERD regenerated from actual migrated metadata. No production data is reset or dropped. Runtime retains no DDL/GPS DELETE privileges.
- Backend legal/deletion endpoints, public draft policy and web deletion entry, Admin review, Flutter privacy/request/status flow; tested shared PHP service and separate restricted cleanup worker.

## Task coverage

| # | Requirement | Preparation / remaining activation evidence |
| --- | --- | --- |
| 1–2 | Hosting assessment | Local XAMPP is development only. Recommend managed VPS/equivalent with required capabilities; no existing commercial host has been qualified. Target Linux/PHP8.4/MySQL8.4 still needs its own rehearsal. |
| 3 | HTTPS/domains | Fixed-host redirect, TLS1.2/1.3, HSTS, private monitoring ingress and renewal/test plan. Actual hostnames/certificates/DNS await approval. |
| 4 | Environment separation | Development/staging/production/backup profiles, separate identities/projects/storage and secure staging validation. |
| 5–6 | Secrets/errors | Private dotenv/secret store, decoded independent keys, remote DB certificate verification, safe response errors, hidden exception arguments; fail-closed preflights. No live rotation. |
| 7 | DB least privilege | Runtime append-only history/ledger/GPS; separate backup/migration/retention/erasure roles. Restricted runtime and erasure operations tested locally. Final MySQL grants need target-server tests. |
| 8–9 | Backups/restore | Native consistent SQL + checksummed manifest, encrypted restic/systemd automation references, actual disposable DB restore and encrypted local recovery test. Offsite repository, retention and production RPO/RTO unapproved. |
| 10–11 | Logging/health | Redacted path logs, minimal live/ready, authenticated metrics, queue/worker/backup health, alerts/logrotate references; real alert routing/host metrics unconfigured. |
| 12 | Background jobs | Fixed allowlisted runner with lock, heartbeat, no arbitrary command, systemd timeout/timer references. No production worker installed. |
| 13 | Email authentication | SMTP/TLS configuration and SPF/DKIM/DMARC/bounce verification plan. Sender/domain/provider approval and real test delivery missing. |
| 14 | Firebase | Backend expected-project validation, private service account path, separate projects, Android/iOS/APNs setup checklist. Real configuration/devices missing. |
| 15 | Payments | Production online processing, stored value and settlement gates preserved; no successful payments/subscriptions simulated. Provider qualification remains mandatory. |
| 16 | Deploy/rollback | Immutable artifacts, checksums, maintenance/drain, rehearsed forward migrations, code rollback without destructive schema rollback, isolated recovery plan. No deployment performed. |
| 17 | Legal/support/deletion | Bilingual policy/terms drafts, support links, reauthenticated in-app/API/web request, Admin review and selective erasure. Operator/contact/legal retention/processor approval blocks publication. |
| 18–19 | Signing/identifiers | Existing candidate `com.clevo.clevo`; private signing examples, release guards, provisional internal Android ID/label and iOS provisioning checklist. Identity ownership, real keys and Apple team missing. |
| 20 | Store requirements | Current official Google/Apple requirements reviewed with dated source links in both checklists. Submission is not automatic approval. |
| 21 | Internal builds | Guarded local Android internal-build command and staging config template; engineering debug build validation recorded separately. Approved distributable internal builds/Play/TestFlight remain blocked. |
| 22 | Release/privacy validation | API/security/DB/Flutter/artifact tests and permission review, data disclosure checklist; native device/iOS/final signed release qualification still required. |
| 23 | Launch checklist | Dependency-ordered gates below. A green local test run cannot authorize activation. |

## Change map for review

| Files / area | Purpose and reuse |
| --- | --- |
| `includes/security/environment.php`, `includes/operations.php`, `health/`, `scripts/production-preflight.php`, `scripts/run-job.php` | Existing dotenv/auth/mysqli reused for secure staging, remote DB CA verification, private readiness/metrics and bounded allowlisted operations. |
| `includes/privacy.php`, `general/privacy.php`, `general/delete-account.php`, `general/terms.php`, `admin/privacy-requests.php`, shared Admin sidebar | Existing identity/password/CSRF/transaction/audit services reused for reviewed selective erasure and legal/support navigation. |
| `includes/api/http.php`, API registry/contracts/controllers, `scripts/generate-api-docs.php`, `docs/openapi.v1.json` | Strict constant validation, documented privacy commands and response DTOs; existing authentication/object policies preserved. |
| `migrations/009_privacy_requests.sql`, `migrations/010_erasure_cleanup.sql`, migration/inventory render scripts, schema/ERD | Forward-only persistence, immutable earlier migration checksum and actual disposable metadata. |
| `scripts/backup-database.php`, `scripts/restore-disposable.php`, `scripts/process-account-erasure-files.php`, `deploy/` | Temporary credential cleanup, native client TLS/GTID compatibility, narrow cleanup worker, encrypted backup and unactivated deployment/monitoring/job templates. |
| `mobile/clevo/lib/features/privacy.dart`, profile/router/config, Android Gradle/manifest, config examples, release scripts | ONE existing Flutter project extended with privacy/self-deletion, safe release endpoint validation, candidate internal identity and private signing guards. |
| `admin/financial-reports.php` | Existing palette/layout retained; initial theme/transition contrast regression repaired. No financial calculation changes. |
| `tests/production.php`, runner/recheck, contract/axe/artifact/backup/alignment tests, Flutter privacy/distribution tests | Executed regression and new security/operations evidence; no real-message/payment test. |
| `.gitignore`, `.gitattributes`, mobile ignores | Keep signing/build/test artifacts private and Linux deployment shell/config references in LF format. |

## Open release blockers

| ID | Severity | Status and closure evidence |
| --- | --- | --- |
| PROD-01 | HIGH | **Confirmed missing approval:** hosting, domains, private-network topology and operator access. Approve a provider/environment and rehearse the reference configuration before any infrastructure action. |
| PROD-02 | HIGH | **Confirmed runtime gap:** old local XAMPP; candidate production stack is **untested**. Patch/provision approved staging, run all migration/concurrency/recovery/security suites there, confirm certificates and effective config. |
| PROD-03 | HIGH | **Confirmed external integration blockers:** no approved SMTP/Firebase/APNs/Maps/payment configuration or merchant certification. Actual messages, GPS devices, provider callbacks/refunds and settlements remain unverified. Any cash-only launch scope must be explicitly approved. |
| PROD-04 | HIGH | **Confirmed legal/retention gap:** operator/contact/timeline/field retention and processor erasure policy missing. Technical erasure retains reviewed commercial/security/KYC/dispute evidence; complete legal review and evidence-expiry/backup-erasure procedures before enabling it. Draft URLs cannot be submitted to stores. |
| PROD-05 | HIGH | **Confirmed signing/device/build limitations:** no production keys/store ownership, native device, Mac/Xcode or final signed AAB/IPA. Complete both store checklists and real-device journeys. Engineering debug artifacts do not close this gate. |
| PROD-06 | HIGH | **Untested production recovery/monitoring:** no encrypted offsite repository, recovery key custody, agreed retention/RPO/RTO, actual alert recipient, failover or TLS renewal rehearsal. Local recovery demonstrates tooling only. |
| PROD-07 | MEDIUM | **Prior unresolved load qualification:** previous intermittent timeout remains QA-R05; current bounded synthetic rerun does not establish a root-cause fix or a production SLO. Run sustained production-like load/tracing and capacity tests. |
| PROD-08 | MEDIUM | **Scope/manual qualification:** scalar backend role, remaining onboarding/subscription gaps, complete assistive-technology review, all retained-data expiry and multi-node scheduler coordination. See existing QA and privacy registers. |

No confirmed CRITICAL production exploit was demonstrated in this phase. That statement does not certify security or close historical/external risks.

## Dependency-ordered launch checklist

1. [ ] Approve operator/legal contacts, launch feature scope, provider strategy, hosting and final application/domain identities.
2. [ ] Provision approved staging with supported patched runtime and separate secrets/data/providers; validate effective HTTPS, CORS, secure cookies, least DB grants, deny rules and uploads.
3. [ ] Rehearse immutable deployment, forward migrations 001–011 including the PHP digest backfill, code rollback and encrypted offsite recovery; measure counts, balances, file integrity, erasure replay and RPO/RTO. Approve retention before any automated prune/delete. An old writer cannot safely share the upgraded idempotency schema/semantics; drain writers and review rollback compatibility.
4. [ ] Install reviewed workers/monitoring/backups in staging, test failure/timeout alerts, queue retries, mail DNS authentication and TLS renewal using synthetic accounts and approved recipients only.
5. [ ] Complete actual provider sandbox and Firebase/APNs/Maps/device journeys; retain production payment/real-wallet/settlement gates until separate commercial and technical approval.
6. [ ] Approve bilingual privacy/terms/processing times and retained fields, provider cleanup and recovery erasure register; exercise completed account erasure via web and app.
7. [ ] Pass PHP/API/security/financial/concurrency/recovery/Flutter/widget/native/accessibility/dependency tests on the target stack and final signed artifacts. Record remaining limitations and risk acceptance; do not suppress failures.
8. [ ] Build approved signed internal Android/iOS artifacts and test all three roles with approved testers. Submit to internal store tracks only with explicit publishing approval and meet applicable closed-test/review gates.
9. [ ] Run production preflight with reviewed private configuration, verify independent alerts/backups/certificate/security smoke tests and record deployment/rollback owners and window.
10. [ ] Obtain explicit approval for production infrastructure activation/deployment/publishing. Keep all online-money safeguards until their approvals; monitor the staged rollout and stop on integrity/authorization/reconciliation failures.

This phase stops before item 10 or any other irreversible production action.
