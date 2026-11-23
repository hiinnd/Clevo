# CLEVO final release checklist

Prepared 2026-11-23. Every item must have a named accountable operator and linked evidence before sign-off. An unchecked item is not implicitly accepted. No launch authorization is recorded here.

## Local implementation and review

- [ ] Review [FINAL_REMAINING_FIXES.md](FINAL_REMAINING_FIXES.md), current diff/source fingerprint, feature matrix and actual passing/failing/rerun results. Preserve one Flutter project, PHP/MySQL and web Admin.
- [ ] Verify migration 011 on an authorized disposable copy with representative old request records; preserve cached responses/business history, drain writers for actual cutover and keep APP_KEY stable/private.
- [ ] Re-run all affected PHP/API/security/booking/financial/notification/logistics/Admin/database/Flutter tests after the final code change; record skips and real failures. Do not raise timeouts, suppress assertions or mark missing tests passed.
- [ ] Verify initial owner document/email/Admin flow, approved station services/prices/hours/capacity, scoped subscription usage and expiry reminder dedup/progression. Do not enable owner financial management or role switching without approved rules.
- [ ] Retain restrictions on unverified online success, real wallet issuance, subscription activation and settlements; verify raw provider return/deep links cannot mark payment successful.

## Hosting and operations — approval required before activation

- [ ] Approve final host/domains, supported **patched** runtime/DB and private topology. Do not expose the old local XAMPP engine; verify effective config and relevant vendor advisories, not just a branch number.
- [ ] Rehearse on the actual intended PHP/MySQL version: migrations, rollback, constraints/query compatibility, concurrency, historic integer-fils balances, backups and failure recovery.
- [ ] Provision separate dev/staging/production secrets/accounts/projects/storage; verify DB least privilege, secure HTTPS/cookies, proxy allowlist, CORS, file deny rules, upload limits and disabled response debugging.
- [ ] Install reviewed job profiles including `subscription-expiry`, notification/retention/security workers; test heartbeat/failure/backlog alerts, maintenance/drain and single-scheduler/multi-node coordination.
- [ ] Configure protected logs/metrics and actual alert recipients; prove TLS renewal and host/DB/disk health monitoring.
- [ ] Provision an independent encrypted backup repository with private key recovery, approved retention/RPO/RTO and tested offsite restoration, erasure replay and rollback. Do not treat a local restic roundtrip as disaster-recovery certification.
- [ ] Assess historical secret/hash exposure and perform only separately approved rotation/reset/history/copy handling. Never attach secret values or signing keys to review evidence.

## Providers, legal and native clients

- [ ] Obtain approved merchant/provider sandbox access and commercial scope; certify actual payment/callback/query/failure/cancel/refund/subscription/settlement/reconciliation behavior before requesting production activation.
- [ ] Configure separate Firebase projects/HTTPv1/APNs/native registrations, restricted Maps keys and SMTP sender domain authentication; use approved test recipients/devices and record delivery, opt-out/revocation, error/retry and deep-link behavior.
- [ ] Approve and publish legal identity/support/privacy contacts, bilingual policies, account deletion timeline, per-field retention/expiry and processor/backup erasure procedures. Validate in-app and external web deletion to completion.
- [ ] Complete actual SDK/data inventories, Google Play Data safety and Apple privacy/permission declarations. No invented no-data/privacy manifest declaration.
- [ ] Approve final bundle/package identities, private signing custody, version/build numbers and final release configuration. Ensure no local/placeholder API, secret fixture or debug signing is in the release.
- [ ] Build/inspect final Android AAB and generated APKs, native16KiB alignment/device compatibility, release manifests/signatures and real Android journeys.
- [ ] Build/inspect iOS archive/IPA on a compatible Mac/Xcode with proper provisioning/APNs entitlements, privacy manifests and real iPhone acceptance.
- [ ] Test every supported role/screen/action in Arabic/English, RTL/LTR, both themes, large text, TalkBack/VoiceOver, network/process interruption, authorized GPS, support and provider returns on actual devices.
- [ ] Resolve the historical timeout with controlled sustained production-like load/tracing and agreed SLOs. Qualify large-data pagination/queue throughput and required Arabic print/PDF output.
- [ ] After explicit approval, distribute internal Play/TestFlight builds and satisfy applicable account/review/closed-testing gates; do not upload as an incidental test.

Current policy checks were revisited on 2026-11-23: Play submissions require target API 36 under the current policy; Apple uploads require Xcode 26+/iOS 26+ SDK. Recheck at the actual submission date and use the actual final artifact. [Play target requirements](https://support.google.com/googleplay/android-developer/answer/11926878), [Apple requirements](https://developer.apple.com/news/upcoming-requirements/).

Privacy/deletion routes must be functional and publicly approved before submission, not draft503 pages. [Play account deletion policy](https://support.google.com/googleplay/android-developer/answer/13327111). The detailed [Android](ANDROID_RELEASE_CHECKLIST.md) and [iOS](IOS_RELEASE_CHECKLIST.md) checklists remain required.

## Final approval

- [ ] Close applicable LB-01–LB-10 or document an explicitly approved reduced launch scope that does not bypass technical/legal/security gates.
- [ ] Obtain explicit deployment/infrastructure/store approval, release and rollback owners, monitored window and incident procedure.
- [ ] Execute only the reviewed deployment/publishing plan after that approval. This implementation/audit phase stops before these actions.
