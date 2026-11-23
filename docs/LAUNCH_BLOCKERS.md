# CLEVO launch blockers

As of 2026-11-23 (Asia/Amman): launch remains blocked. No deployment or publishing is authorized or performed.

Current severity register is below. Implementation fixes are distinguished from external approval/qualification; local tests do not close the latter.

| ID | Severity | Evidence / required closure |
| --- | --- | --- |
| LB-01 | CRITICAL | Observed PHP 8.2.12 is below security releases addressing critical runtime vulnerabilities. This is a confirmed version match, not an attack demonstration or an inventory of an approved production host. Do not expose this XAMPP stack. Use a supported currently patched target runtime and rehearse the whole stack; application authorization cannot repair a vulnerable engine. [Official PHP advisory](https://www.php.net/archive/2026.php#2026-10-20-1), [current release record](https://www.php.net/archive/2026.php). |
| LB-02 | HIGH | No approved production host/domain/TLS deployment, target MySQL qualification, installed workers/monitoring or independent encrypted recovery exists. Reference files are unactivated; approve the environment, alerts, key custody, retention, restore/rollback drills and least privilege. |
| LB-03 | HIGH | Real merchant/payment model and provider certification are missing. Production online payment, stored value, subscription funding and payouts remain disabled. Certify real sandbox callbacks/query/refunds and reconcile actual provider records before requesting commercial activation. |
| LB-04 | HIGH | Firebase/APNs, restricted Maps keys, authenticated mail domain and authorized device delivery/GPS/checkout-return journeys remain unverified. Private configuration hooks are ready; no credentials/projects are invented. |
| LB-05 | HIGH | Legal operator/contact, published approved policies, deletion timeline/retention and processor/backups erasure remain unapproved. The selective erasure implementation does not replace legal retention/expiry decisions; draft pages are not store policy URLs. |
| LB-06 | HIGH | No production signing/store ownership, final signed AAB/IPA or Mac/Xcode/native-device acceptance. Debug builds/desktop widget tests are insufficient. |
| LB-07 | HIGH | Deploy migration 011/backfill with writers drained and preserve APP_KEY. Earlier unkeyed request hashes and historic credential exposure require custody/rotation assessment; no automatic live reset/rotation is authorized. Source remediation is not proof that old copies were never exposed. |
| LB-08 | MEDIUM | Owner SaaS membership, owner-controlled package creation/pricing, additional station submission/resubmission and multi-role memberships need approved product rules if required at launch. Initial native owner registration and read-only owner usage reporting now exist; they do not grant these financial/role powers. |
| LB-09 | MEDIUM | Historical intermittent load timeout, sustained large-data/production topology tests, all-screen native navigation/visual/manual accessibility and retained-data expiry qualification remain open. Current bounded measurements must not be presented as an SLO or a root-cause fix. |
| LB-10 | LOW | Binary PDF generation is not implemented; existing financial export includes CSV and print HTML. Qualify Arabic browser printing/PDF if it is a launch requirement. |

1. Approve remaining launch scope: additional station/resubmission policy, commercially distinct owner subscriptions/permissions, and whether multi-role accounts are required. Initial native owner applications now use the existing PHP approval flow; read-only station usage reporting does not grant financial management powers.
2. Supply an approved maintained HTTPS staging/production environment, domain identities, database version and least-privilege identities. Rehearse migrations and recovery on disposable/restored copies, preserving historical financial data.
3. Approve the merchant/provider and commercial model; certify real sandbox capture, callback validation, reconciliation, refunds, subscription funding and settlement. Production online money, stored value and payouts remain gated. A cash-only launch also needs explicit scope approval.
4. Configure approved Firebase/APNs projects, restricted Maps keys and SMTP sender/domain authentication. Test actual device delivery, opt-out/revocation, permission handling, checkout return and GPS. Do not invent credentials or use customer recipients for tests.
5. Approve operator/legal/support details, public privacy/terms, deletion timelines and retention/processor cleanup rules; qualify retained-data expiry and backup erasure procedures.
6. Provide private Android signing and Apple team/profiles plus Mac/Xcode and authorized devices. Produce and inspect final signed AAB/IPA; execute Arabic/English, Dark/Light, all-role workflows, every navigation action/button and assistive-technology checks. Desktop tests are not native acceptance.
7. Resolve or isolate the historical intermittent API timeout through production-like sustained load and tracing. Agree SLOs, realistic data sizes and worker topology; retain failures and original timeout limits.
8. Configure and rehearse offsite encrypted backup/restore, key custody, RPO/RTO, alert recipients, TLS renewal, worker failures and multi-node coordination. Record secret rotation separately without exposing secret values.
9. Qualify browser print/PDF output if required at launch. Complete manual/incomplete accessibility and dependency/native SDK reviews.
10. Resolve any failed/unexecuted current regressions in FINAL_INTEGRATION_RESULTS.md and obtain explicit deployment/store approval after the above evidence is reviewable.

Historical blocker mappings and exact code changes: FINAL_REMAINING_FIXES.md. Existing activation runbooks: PRODUCTION_READINESS.md, ANDROID_RELEASE_CHECKLIST.md, IOS_RELEASE_CHECKLIST.md, PAYMENT_INTEGRATION.md and OPERATIONS_MONITORING.md.
