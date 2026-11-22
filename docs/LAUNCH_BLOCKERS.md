# CLEVO launch blockers

As of 2026-11-23 (Asia/Amman): launch remains blocked. No deployment or publishing is authorized or performed.

1. Approve launch scope: native station onboarding/KYC, owner subscription meaning/permissions, and whether multi-role accounts are required. No role switching or financial privilege expansion may be inferred from UI requirements.
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
