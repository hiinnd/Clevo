# CLEVO feature completeness

Reviewed 2026-11-23 against current code and executed tests. Paths are repository-relative; API paths are under `/api/v1`. Backend test evidence does not automatically certify its Flutter/native/web presentation. The original feature inventory remains a historical baseline, not today's status.

**Status vocabulary:** **Tested** = implemented and executed within the stated local scope; **Implemented** = source exists but this particular flow was not executed; **Partially Implemented** = a known capability/parity/acceptance gap remains; **Blocked** = external configuration, approval or compatible environment prevents completion; **Missing** = no implementation was found. These statuses are not a production-readiness percentage. Suite details and failures/reruns: [FINAL_INTEGRATION_RESULTS.md](FINAL_INTEGRATION_RESULTS.md).

## Identity and account

| Feature | Status | Evidence / endpoint / remaining boundary |
| --- | --- | --- |
| Customer registration | Tested | `features/auth.dart`, `api/v1/auth.php`, `POST auth/register`; auth/journey/live API tests |
| Initial owner document application | Tested within local client/API scope | `features/owner_onboarding.dart`, multipart `POST auth/register`; four AR/EN/theme widget cases; real HTTP/DB pending-owner/closed-station assertions in final-fixes rerun |
| Owner email/Admin approval | Tested backend | Existing verification/station Admin services; no client bypass or automatic owner activation |
| Additional station creation/resubmission policy | Partially Implemented | Existing web primary-station setup retained; no new multi-station/KYC resubmission policy invented |
| Password login for all roles | Tested | `security/auth.php`, web login and `POST auth/login`; account state/version checked |
| Driver task-code login | Tested | `auth/driver-exchanges`, scoped job/session tests; task code does not grant full account authority |
| Email verification/resend | Tested backend; delivery Blocked | `auth/email/*`, expiring hashed challenges/local encrypted mail inspection; actual SMTP not executed |
| Forgot/OTP/reset | Tested backend; delivery Blocked | `auth/password/*`; single-use grants/password hashing/session revocation |
| Access/refresh/logout/replay prevention | Tested | `core/api.dart`, auth session/refresh records; race/revocation/offline logout tests |
| Four-role/backend object isolation | Tested | API registry/router and shared policies; cross-user/owner/driver/Admin negatives |
| Multi-role memberships/switching | Missing; approval Blocked | One scalar Role remains; no client role switch, no fabricated membership |
| Profile/avatar and phone preservation | Tested | `GET/PATCH me`, `POST me/avatar`; upload policy, API and inherited owner form regression |
| Saved addresses | Tested backend | `me/addresses`; self-only reads/writes, archive semantics |
| Initial owner document cleanup on invalid registration | Tested in affected suite | Shared upload cleanup refuses referenced documents and removes only new unreferenced private files |

## Marketplace and bookings

| Feature | Status | Evidence / endpoint / remaining boundary |
| --- | --- | --- |
| Existing PHP landing/about/role dashboards | Implemented / partially tested | Legacy files retained; PHP lint/auth compatibility; exhaustive public-page/browser acceptance not executed |
| Unified mobile role dashboards | Tested within widgets | `features/dashboards.dart`, server-derived `GET me`; static navigation is not fake metrics |
| Station search/filter/sort/location distance | Tested backend | `GET stations`, catalog SQL/pagination tests; default count avoids duplicate aggregation |
| Station details/services/prices/durations | Tested | `stations/{id}` and `/services`, owner CRUD/customer reads; authoritative DB values |
| Owner station/service/price management | Tested backend | `owner/stations`, `/services`, `owner/services/{id}`; owned/approved station policy |
| Owner availability/hours/capacity/fees | Tested backend | `PATCH owner/stations/{id}/availability`; DB settings, range validation and inherited web-clock negatives |
| At-station/home/pickup booking | Tested backend/live API | `POST bookings`; shared `commerce.php`/`booking-workflow.php` |
| Multiple services/date/time/server total | Tested | Snapshots/fees/duration/civil timezone, ignores untrusted client money |
| Capacity/concurrent/repeated booking protection | Tested locally | Row locking, capacity pools, idempotency/concurrency tests; production scale/DB version remains blocked |
| Owner accept/reject/cancel/Admin cancellation | Tested backend | Shared state machine and API/web adapters; impossible/unauthorized transitions rejected |
| Booking history/status/events/price breakdown | Tested backend | `bookings/*`, role-scoped views and immutable events |
| Cancellation/refund eligibility/reschedule/expiry | Tested backend | Booking/financial suites; provider refund execution externally blocked |
| Ratings/reviews/replies | Tested backend | `bookings/{id}/reviews`, `me/reviews`, owner reply policy; full native visual acceptance pending |
| Loyalty balances/points discounts | Partially Implemented | Existing PHP/booking logic retained; no complete native loyalty event/redemption feature and real wallet issuance remains gated |
| Referral attribution/reward tracking | Missing | Legacy display code is not a referral business service; not a proven removed working feature |
| Weather/AI assistant/voice/transcripts | Partially Implemented / Blocked | Existing web `includes/weather.php`, `includes/assistant.php`, `assets/lamea.js`; approved providers/full mobile parity not present |

## Money, subscriptions and operations

| Feature | Status | Evidence / endpoint / remaining boundary |
| --- | --- | --- |
| Separate cash workflow | Tested locally | Server eligibility/owner attestation; no card-provider success implied |
| Hosted online payment/session/status | Blocked externally | `payment-orders`, PHP provider adapter; local contract/evidence tests pass, production disabled |
| Signed webhook/query/cancel/unknown state | Tested with local provider double | `webhooks/paytabs`, order reconcile/cancel; real provider account/certification absent |
| Immutable ledger/receipts/reconciliation | Tested locally | `finance/*`, `financial-receipts`, Admin reports; real bank/provider reconciliation pending |
| Approved 5% commission/owner liabilities | Tested locally | Integer JOD fils, post-discount service/fee basis; historic commissions preserved |
| Wallet balance/movement history | Tested read-only | `me/wallet`, `/transactions`; real stored value/top-up/spend not authorized |
| Real wallet issuance/settlements/payouts | Blocked | Commercial/legal/provider approval absent; legacy shortcuts stay disabled |
| Customer Silver/Gold/Diamond catalog/history | Tested backend | `subscription-plans`, `me/subscriptions`; snapshots/eligibility retained |
| New funded subscription activation/refunds | Tested locally; live funding Blocked | Verified evidence required; existing legacy entitlements are explicitly distinguished |
| Owner subscription usage | Tested in affected suite | `GET owner/subscription-usage`, `/owner/subscriptions`; own booking redemptions only, no customer-wide subscription or financial mutation |
| Owner SaaS membership/owner-controlled package pricing | Missing; approval Blocked | No approved product/price/permission model; read-only usage is not represented as this feature |
| Expiry/renewal reminders | Tested locally; deployment Blocked | Shared bounded UTC/idempotent batch and allowlisted `subscription-expiry`; 32/32 affected-suite assertions including duplicate suppression/progression and narrow worker grants; real scheduler/delivery not activated |
| Subscription benefit consumption/restoration | Tested backend | Booking/payment/concurrency suites; pending/complete/cancel/refund accounting |
| Notification inbox/read/preferences/devices | Tested locally | Shared PHP notification service and `notifications`, `me/notification-*` |
| Transactional outbox/retry/deep links | Tested locally | Post-commit durable records, delivery leases/retry/preference/session checks |
| Real FCM/APNs/SMTP delivery | Blocked | Config hooks/native registrations/templates exist, no real delivery certification |
| HTML bilingual transactional templates | Tested rendering/serialization locally | Existing email infrastructure retained; actual mailbox/mobile render acceptance pending |
| Guest/user/owner/driver support/Admin replies | Tested backend | Existing web support plus `support-requests/*`; provider mail blocked |

## Logistics, Admin and release

| Feature | Status | Evidence / endpoint / remaining boundary |
| --- | --- | --- |
| Driver assignment/conflict protection | Tested backend | Owner assignment/shared logistics service; drivers restricted to assigned jobs |
| Driver accept/stages/handoffs/history | Tested backend/live API | `driver/jobs/*`, confirmation codes and shared booking status/history |
| GPS sample authorization/accuracy/time/sequence | Tested with synthetic samples | `driver/jobs/{id}/locations`; no physical GPS measurement |
| Customer live map/stale/offline recovery | Partially Implemented / Blocked | Server policy and lifecycle tests pass; native Maps keys, permissions and actual devices unqualified |
| Stop sharing after terminal/revoked/background state | Tested logic | Server gates and Flutter tracking-policy/widget tests; background tracking not approved |
| Admin users/owners/drivers/station approval | Tested local web/API | Admin-only services, CSRF, document verification, archive/revocation/audit |
| Admin bookings/services/plans/support/disputes/refunds | Tested local web/API | Existing management/dashboard preserved; no synthetic financial approval |
| Admin financial charts/reports/CSV | Tested against DB | Recognized journals/book/date filtering, secure exports and browser checks |
| Binary PDF | Missing | Print HTML exists; generated PDF/browser print dialog/Arabic output unqualified |
| Arabic/English/state labels | Partially Implemented / Tested subset | Bilingual new forms/known states and compact-text tests; every legacy/server-content string not certified |
| Dark/light/RTL/LTR/accessibility | Partially verified | Flutter theme tests and 24 Admin combinations; native/manual assistive acceptance outstanding |
| Forward migrations/data/FK/recovery | Tested disposable only | 001–011, metadata/count/balance/restore tests; target MySQL/staging rehearsal absent |
| Source secret/config/authorization guards | Tested within rules | Pattern scan, independent env keys, no tracked signing files; historic rotation/copy exposure not closed |
| Production hosting/HTTPS/domain/least privilege | Blocked | Unapproved environment; local old runtime must not be exposed |
| Monitoring/health/jobs/logging | Implemented and locally tested; activation Blocked | Authenticated readiness/metrics, allowlisted workers and deployment references; no installed production service/alerts |
| Encrypted backup/recovery/rollback | Tested tooling locally; offsite Blocked | Native dump/restore and local encrypted repo test; no production RPO/RTO/retention/failover certification |
| Privacy/web/in-app selective deletion | Tested application scope; legal Blocked | Self reauthentication/Admin review/narrow cleanup worker; operator/retention/processor approval missing |
| Android engineering debug build | Tested build/artifact only | Current-source ARM64 debug APK built; 16/16 artifact checks; one app/project, not a signed production/internal-store release or device test |
| iOS build/signing/TestFlight | Blocked | No Mac/Xcode/team/profiles/device; not claimed passed |
| Production payment/notification/tracking simulation | Not presented as production-ready | Tests are labeled doubles/synthetic data; real commercial/device gates remain explicit |

No absence of a file or unexecuted screen is silently counted as a passing feature. Remaining product/approval/runtime gates are authoritative in [LAUNCH_BLOCKERS.md](LAUNCH_BLOCKERS.md).
