# CLEVO production blockers

As of **2026-11-23**, **NO-GO**. The authoritative severity/evidence/closure register is [LAUNCH_BLOCKERS.md](LAUNCH_BLOCKERS.md), LB-01 through LB-10. This document separates different reasons why local success is insufficient.

| Category | Status | Required release evidence |
| --- | --- | --- |
| Known runtime vulnerability/version risk | CRITICAL, open | Currently observed PHP 8.2.12 is not an approved patched web runtime. Confirm vendor patches/current supported packages, deploy only to approved staging, and rerun compatibility/security/recovery there. No live exploit was attempted. |
| Repaired internal defects | Tested in the documented local scope | Keyed idempotency/backfill, initial native owner onboarding, scoped usage, expiry reminders, registration cleanup, count optimization and state labels. The 32-assertion affected DB/API rerun and relevant Flutter regressions passed; external/native acceptance remains separate. |
| Product decisions | Open where required for launch | Owner membership/plan pricing, extra-station/resubmission policy, multi-role membership. Current scalar roles and read-only owner usage do not authorize these features. |
| External integration approvals | HIGH, blocked | Merchant/provider/model/sandbox certification, Firebase/APNs/Maps/mail identities and real test devices/recipients. No credential guessing or production-success simulation. |
| Deployed operations | HIGH, blocked | Approved host/domains/TLS/DB/runtime/least privilege, installed workers/alerts, independent encrypted recovery/key custody, restore/rollback/renewal evidence. Templates are not installed configuration. |
| Legal and privacy | HIGH, blocked | Operator/contact/public policies, retention/timeline, processor/backup erasure, evidence-expiry rules and store disclosure approval. Selective erasure is not a legal compliance certificate. |
| Signed/native release | HIGH, blocked | Final Android/iOS identifiers/signing, Mac/Xcode, real devices, final AAB/IPA, store/internal review and permission/privacy acceptance. Debug desktop tests are insufficient. |
| Remaining acceptance/performance | MEDIUM/LOW, open | Historical timeout root cause, sustained realistic load/SLOs, all-screen buttons/themes/translations/manual accessibility and required print/PDF qualification. |

Migration011 and a reviewed stable APP_KEY are prerequisites for the new code; older copies with weak request hashes and historical source credentials require custody/rotation assessment, not publication of their values. No production mutation, key rotation, deployment or publishing is authorized by a green local suite.
