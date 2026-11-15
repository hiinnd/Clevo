# CLEVO feature and API inventory

Snapshot: 2026-11-15, baseline `e85903e`. This inventory maps the available repository, not a claim that the listed screens or workflows have passed runtime tests. See [AUDIT_REPORT.md](AUDIT_REPORT.md) for severity, evidence and limitations.

**Current status:** `SOURCE` = implementation was inspected but its complete workflow was not executed; `PARTIAL` = a confirmed incomplete/broken/unsafe path exists; `DISPLAY` = presentation with no corresponding business implementation. All future endpoints below are **proposals under `/api/v1`**, not endpoints that currently exist. Path parameters such as `{id}` require object-level authorization. Legacy pages and URLs remain compatibility entry points while shared PHP services are extracted.

## Public, identity and recovery

| Feature | Current files / behavior | Status and audit links | Future API endpoint(s) / reuse |
|---|---|---|---|
| Public marketplace landing, categories, approved active stations, account navigation | `general/landing.php` queries stations/services and infers categories from service names; includes chat and theme/language UI | SOURCE; P11/P12/P17; driver profile navigation falls through to the customer link at line 703 onward and needs role-aware parity checking | `GET /catalog/categories`, `GET /stations`, `GET /stations/{id}`; reuse bilingual content, branding and catalog queries after validation/pagination |
| About | `general/about.php` | SOURCE; depends on DB bootstrap even for static content | `GET /content/about` or bundled localized Flutter content; keep existing web page |
| Terms | `general/terms.php` | SOURCE; no versioned acceptance storage found | `GET /content/terms`; add versioned acceptance only when product policy requires it |
| Guest/authenticated contact | `general/contact.php`, `includes/support-helpers.php` | SOURCE; guest email persistence and role normalization present; provider mail untested | `POST /contact`; validation/throttle -> shared support service -> admin event |
| Customer and owner registration/document submission | `general/register.php`, `Emails/verify_template.php` | PARTIAL; A02/A06/A10/B01/B08/P15 | `POST /auth/register`, `POST /owner/applications`, `POST /uploads/verification`; reuse hashing, wallet/loyalty initialization and owner application concepts after hardening |
| Email verification and success UI | `Emails/verify.php`, `Emails/verify_success.php`, `Emails/verify_template.php` | SOURCE; verification token is cleared, owners remain pending approval; no token expiry/resend backend found | `POST /auth/email/verify`, `POST /auth/email/resend`; trusted web/app links and legacy success page |
| Password/session login for four roles, driver task code exchange | `general/login.php`, `includes/config.php` | PARTIAL; A02/A03/A05/A07-A11/P02 | `POST /auth/login`, `POST /auth/driver-exchanges`, `GET /me`; retain web login adapters and role alias mapping |
| Password-reset request and email | `general/send-reset-otp.php`, `Emails/reset_otp.php` | PARTIAL; A01/A06/A08 | `POST /auth/password/forgot`; shared queued mail template and hashed challenge |
| OTP validation | `general/verify-reset-otp.php` | PARTIAL; A01/A08; verification currently has no separate reset authorization grant | `POST /auth/password/verify`; produce a scoped, expiring reset grant only after verification |
| Password replacement | `general/login.php` POST `reset_password_action` | PARTIAL; A01/A07/A08 | `POST /auth/password/reset`; shared password policy and session revocation |
| Logout | `general/logout.php`, `includes/shared_logout_modal.php`; driver completion also destroys session | PARTIAL; A07; local logout exists, token revocation does not | `POST /auth/logout`, `DELETE /me/sessions/{id}`; preserve web confirmation/modal |
| Refresh/session/device authentication management | Existing JWT issue/validate helpers in `includes/config.php`; no refresh/revocation/session-list backend | PARTIAL; A03/A07/A09/P01 | `POST /auth/refresh`, `GET /me/sessions`, `DELETE /me/sessions/{id}`; new persistence/service required |

## Customer features

| Feature | Current files / behavior | Status and audit links | Future API endpoint(s) / reuse |
|---|---|---|---|
| Customer dashboard, active order, loyalty/plan/inbox overview | `user/home.php` | SOURCE; duplicate timeout updates and weather/location integrations; B10/P11/P13 | `GET /me/overview`, `GET /bookings?scope=active`, `GET /me/loyalty`, `GET /me/subscriptions/active`, `GET /notifications/unread-count` |
| Station search/filter, service modes, price/rating aggregation | `user/search-stations.php` | SOURCE; client-side full-list filtering; P12/P17 | `GET /stations?query=&mode=&lat=&lng=&cursor=`, `GET /stations/{id}/services`, `GET /stations/{id}/reviews` |
| Station details, select multiple services, map/address, booking request, plan-at-creation | `user/station-details.php`, `includes/booking-helpers.php`, `includes/plan-helpers.php` | PARTIAL; F02/B07/B10/S01; service ownership and DB price lookup already present | `POST /booking-quotes`, `GET /stations/{id}/availability`, `POST /bookings`; reuse item snapshots and price lookup after payment/slot policy fixes |
| Booking acknowledgement and last/by-reference order lookup | `user/success.php` | SOURCE; not evidence of payment success | `GET /bookings/{id}` or `GET /bookings?reference=`; Flutter local confirmation view reads authoritative result |
| Booking history / quick review | `user/booking-history.php` | SOURCE; unbounded history and duplicate rating controller; P12/P17 | `GET /bookings?cursor=`, `POST /bookings/{id}/review` |
| Order details, cancellation/reschedule, completed-order review | `user/order-details.php`, `includes/order-details.php` (older overlapping detail/review UI) | SOURCE; ownership/Completed checks, Pending-only change policy; F02/B10/P17/S02 | `GET /bookings/{id}`, `POST /bookings/{id}/cancel`, `POST /bookings/{id}/reschedule`, `POST /bookings/{id}/review`; one shared review and booking service |
| Live customer/owner/driver map and route | `user/live-tracking.php`, `user/get_order_status.php` | SOURCE; object scoping exists, fallback GPS can mislead; P10/P11 | `GET /bookings/{id}/tracking`, `GET /bookings/{id}/status`; role-minimized tracking DTO and explicit stale state |
| Saved last location and location picker | `user/save-location.php`, `includes/location-picker.php`; callers in home/station details | PARTIAL; CSRF/runtime DDL; P02/P07/P10/S06 | `GET /me/location`, `PUT /me/location`, `GET /maps/reverse-geocode`; reuse range validation, add auth/CSRF-compatible adapters |
| Profile, car name, email/phone and picture | `user/profile.php`, media helpers in `includes/app-helpers.php` | PARTIAL; B01/B08; one car-name field rather than a vehicle-management domain | `GET /me`, `PATCH /me`, `POST /me/profile-picture`, `POST /me/email-change`; preserve one-car behavior before adding any fleet feature |
| Wallet balance and last ten transactions | `user/user-wallet.php`, wallet helpers | SOURCE; F03/F07/F10 | `GET /me/wallet`, `GET /me/wallet/transactions?cursor=` |
| Wallet top-up | `user/user-wallet.php` JS `charge`, `includes/payment-gateway.php` without booking/plan | PARTIAL; F01/F05; successful UI does not fund wallet | `POST /wallet/top-up-intents`, `GET /payments/{id}`; verified provider/ledger implementation required |
| Loyalty balance, points earned display and points-to-wallet redemption | `user/loyalty.php`, `includes/app-helpers.php` | PARTIAL; F06/F10; conversion currently 40 points per JOD and completion earns gross-price points | `GET /me/loyalty`, `GET /me/loyalty/events`, `POST /loyalty/redemptions`; preserve displayed policy until explicit finance decision |
| Referral code | `user/loyalty.php:12` | DISPLAY; P17; no attribution, invite tracking or reward ledger | Future `GET /me/referral`, `POST /referrals/claim` only after business rules; do not promise functional referrals in mobile |
| Plan catalog, active subscription, wallet/card subscription purchase | `user/customer-plans.php`, `includes/plan-helpers.php`, gateway | PARTIAL; F01/F08/S01; wallet purchase source includes debit + subscription transaction | `GET /plans`, `GET /me/subscriptions`, `GET /me/subscriptions/active`, `POST /subscription-purchases`, `GET /payments/{id}` |
| Choose cash/wallet/card/plan; optional points discount | `includes/payment-method.php`, `includes/payment-gateway.php`, booking helpers | PARTIAL; F01-F04/S02; cash limited to station by this payment selector | `GET /bookings/{id}/payment-options`, `POST /bookings/{id}/payment-intents`, `GET /payments/{id}`; server quote/financial service replaces mock confirmation |
| Pending payment dues | `includes/pending-dues.php` returns latest accepted unpaid order, JSON when `?json`, otherwise redirects | SOURCE; P16; HTML after exit is dead | `GET /me/payment-dues`; retain legacy redirect and latest-due behavior until expanded with pagination |
| Notification inbox and single-read | `user/notifications.php`, app helpers | SOURCE; B10/P07/P12/P17; database notifications only | `GET /notifications`, `PATCH /notifications/{id}/read`, `POST /notifications/read-all`, `GET /notifications/unread-count` |
| Support tickets, follow-up replies and FAQ | `user/support.php`, `includes/support-helpers.php` | PARTIAL; B05/B06/P12; explicit guest/customer schema conflicts | `GET /support/tickets`, `POST /support/tickets`, `GET /support/tickets/{id}/messages`, `POST /support/tickets/{id}/messages`, `GET /content/faq` |

## Owner features

| Feature | Current files / behavior | Status and audit links | Future API endpoint(s) / reuse |
|---|---|---|---|
| Owner dashboard, counters, recent orders/reviews, today's earnings | `owner/owner-dashboard.php`, `owner/ajax_stats.php` | SOURCE; earnings are summed credits; F09/B09/P08/P12 | `GET /owner/overview`, `GET /owner/stats`, `GET /owner/bookings?limit=5`, `GET /owner/reviews?limit=2` |
| Station availability Active/Busy/Closed | `owner/owner-dashboard.php` GET `change_status` | SOURCE; approval-scoped update and CSRF in URL; P07 | `PATCH /owner/stations/{id}/availability` |
| Station registration/details, image, GPS/address and opening hours | `owner/add-station.php`; initial application in registration | PARTIAL; B01/B08/B09/P11 | `GET /owner/stations`, `GET /owner/stations/{id}`, `POST /owner/stations`, `PATCH /owner/stations/{id}`, `POST /owner/stations/{id}/image`; new station retains approval workflow |
| Bilingual service create/edit/delete and station/home/pickup modes | `owner/manage-services.php`, service translation asset | PARTIAL; B04/B08/B09/P08 | `GET/POST /owner/stations/{id}/services`, `PATCH /owner/services/{id}`, `POST /owner/services/{id}/archive`; preserve existing delete URL as archive adapter after policy change |
| Driver list/add/delete | `owner/manage-drivers.php` | PARTIAL; A05/B09/P08/P11 | `GET /owner/stations/{id}/drivers`, `POST /owner/stations/{id}/driver-invitations`, `PATCH /owner/drivers/{id}`, `POST /owner/drivers/{id}/deactivate` |
| Incoming orders, accept/reject and eligible driver list | `owner/incoming-orders.php` actions `accept_order`, `reject_order`, `fetch_drivers_list` | SOURCE; pending/owner checks present; F02/B03/B10 | `GET /owner/bookings`, `POST /owner/bookings/{id}/accept`, `POST /owner/bookings/{id}/reject`, `GET /owner/bookings/{id}/eligible-drivers` |
| Assign pickup/delivery driver and access code | `owner/incoming-orders.php` action `assign_driver`; driver login consumes code | SOURCE; same-owner validation, no driver availability/same-station guarantee; B02/B09 and seed mismatch | `POST /owner/bookings/{id}/assignment`; driver invitation/assignment exchange service handles scoped grants |
| Complete station/home order, loyalty and plan settlement | `owner/incoming-orders.php` action `complete_order` | PARTIAL; B03/F04/S01 | `POST /owner/bookings/{id}/transitions`; transactional booking/completion service |
| Reviews list/statistics and owner replies | `owner/owner-reviews.php`; unsafe duplicate `includes/process-reviews.php` | PARTIAL; A04/P17; protected controller enforces owned station | `GET /owner/reviews`, `GET /owner/reviews/stats`, `POST /owner/reviews/{id}/reply`; legacy unsafe entry point must delegate to this policy |
| Owner wallet/history/monthly earnings | `owner/owner-wallet.php`, app helpers | SOURCE; F04/F07/F09 | `GET /owner/earnings`, `GET /me/wallet`, `GET /me/wallet/transactions` |
| Bank details and full-balance withdrawal requests | `owner/owner-wallet.php` actions `save_bank_account`, `withdraw_balance`; `includes/app-helpers.php` payout-table helpers | SOURCE; no actual bank transfer integration; S02 | `GET/PUT /owner/bank-account`, `GET/POST /owner/withdrawals`; reserve and audit disbursement |
| Owner notification inbox/read-all | `owner/notifications.php` | SOURCE; multi-station join duplication/GET mutations; P07/P09 | Shared `GET /notifications`, `PATCH /notifications/{id}/read`, `POST /notifications/read-all` |

## Driver features

| Feature | Current files / behavior | Status and audit links | Future API endpoint(s) / reuse |
|---|---|---|---|
| Task/password/code login and active task selection | `general/login.php`, `driver/driver-dashboard.php` and session `driver_booking_id` | PARTIAL; A05/A07/B02; password login selects latest active delivery booking, not a task list | `POST /auth/login`, `POST /auth/driver-exchanges`, `GET /driver/tasks`, `GET /driver/tasks/{id}` |
| Driver dashboard customer/car/contact/address/payment summary | `driver/driver-dashboard.php` | SOURCE; current role/task scoping exists; F10/P10 | `GET /driver/tasks/{id}` with minimal DTO; preserve contact/navigation links and payment-ready flag |
| Status progression and completion | `driver/driver-dashboard.php` POST `update_status`; `driver/update-status.php` POST `update_booking_status` | PARTIAL; B02/B03; two overlapping implementations and session logout at completion | `POST /driver/tasks/{id}/transitions`; shared matrix/event transaction; define post-completion token/session behavior |
| Periodic GPS upload | `driver/driver-dashboard.php` POST `update_location`, ten-second browser polling | SOURCE; finite-number validation but no range/freshness/accuracy, page must run; P10 | `POST /driver/tasks/{id}/locations`; assignment, range, sequence/timestamp and active-task checks |
| Driver support/notifications | Generic support accepts driver role; notifications generated on assignment; no driver-specific inbox page | PARTIAL; generic support SOURCE, native push absent S04 | Shared `/support/tickets`, `/notifications`, `/devices`; new inbox UI without removing task alerts |

## Admin features

Admin web dashboards remain first-class supported clients. Future admin API routes allow shared-service testing and later clients; shipping an admin mobile app is not assumed.

| Feature | Current files / behavior | Status and audit links | Future API endpoint(s) / reuse |
|---|---|---|---|
| Overview KPIs, pending stations, recent support, popular service, ratings | `admin/admin-dashboard.php` | SOURCE; completed commission/date metrics and first-service popularity do not fully represent multi-service/collected finance; F09/P12 | `GET /admin/overview`, `GET /admin/analytics/services` with explicit metric definitions |
| Customer list and block/activate/blacklist | `admin/manage-users.php` | SOURCE; status change has transaction/row lock, but no active-session revocation; A07/A11 | `GET /admin/users`, `PATCH /admin/users/{id}/status`; revoke permissions on restriction |
| Blacklist/security list and unban | `admin/security-center.php`, `admin/unban_process.php` | SOURCE; alternate unban updates two tables without the primary controller's transaction | `GET /admin/blacklist`, `DELETE /admin/blacklist/{id}`, `POST /admin/users/{id}/unban`; shared restriction service |
| Station list/block/delete | `admin/manage-stations.php` | PARTIAL; B04 history cascade | `GET /admin/stations`, `PATCH /admin/stations/{id}/status`, `POST /admin/stations/{id}/archive` |
| Pending owner/station approval, document review and approval email | `admin/station-approvals.php`, registration uploads | SOURCE; A06/B01/P15; account/station updates transactional, delivery untested | `GET /admin/station-applications`, `GET /admin/documents/{id}`, `POST /admin/station-applications/{id}/approve`, `POST /admin/station-applications/{id}/reject` |
| Plan create/edit/delete/activation, bilingual content/translation | `admin/manage-plans.php` | SOURCE; F08/B08/P11 | `GET/POST /admin/plans`, `PATCH /admin/plans/{id}`, `POST /admin/plans/{id}/archive`; version purchased terms |
| Financial KPIs/history, station revenue ranking, weekday chart | `admin/financial-reports.php` | PARTIAL; F09; gross booking totals are not reconciled captures/profit | `GET /admin/finance/summary`, `GET /admin/finance/bookings`, `GET /admin/finance/stations`, `GET /admin/finance/series`, `GET /admin/reconciliation` |
| Withdrawal queue, manual paid/rejected processing and wallet refund | `admin/financial-reports.php` POST `update_withdrawal_status` | SOURCE; pending row locked and conditional update exists; bank transfer untested/manual | `GET /admin/withdrawals`, `POST /admin/withdrawals/{id}/approve`, `POST /admin/withdrawals/{id}/record-payment`, `POST /admin/withdrawals/{id}/reject` |
| Support conversations, guest reply email and follow-up | `admin/all-support-chats.php`, `admin/support-chat.php`, support helpers | PARTIAL; B05/B06/P12/P16 | `GET /admin/support/tickets`, `GET /admin/support/tickets/{id}/messages`, `POST /admin/support/tickets/{id}/messages`, `POST /admin/support/tickets/{id}/close` |
| Admin personal/role notification inbox and read-all | `admin/notifications.php` | SOURCE; P07/P09; one shared read flag per role notification | Shared `/notifications` with recipient-specific reads and authorized admin events |
| Admin profile picture across dashboards/sidebar | `admin/upload-profile-picture.php`, `includes/shared_admin_sidebar.php`, repeated page upload handlers | SOURCE; shared image helper validates MIME/size and generates a name; P07/P16 gaps remain; Referer-based redirect in upload controller needs allowed internal targets | `POST /me/profile-picture`; central upload policy/service and compatibility forms |

## Shared components and integrations

| Component / feature | Files | Existing capability and future boundary |
|---|---|---|
| Bootstrap, connection, session aliases, CSRF, auth, escaping, JSON, JWT | `includes/config.php` | Reusable helper concepts after A01-A11/P02/P03 hardening. Split bootstrap into web/session and API/stateless middleware; never expose secret constants |
| General helpers | `includes/app-helpers.php` | Runtime schema repair, images, wallets/loyalty, commission/payout, withdrawal tables, notification templates and inserts. Extract services/repositories, remove request DDL, add typed event identity |
| Booking helpers | `includes/booking-helpers.php` | Item snapshots, points quote/application, payable amount, refunds/reversal, Pending cancellation/reschedule, timeouts. Preserve domain behavior through policy/transaction fixes |
| Plan helpers | `includes/plan-helpers.php` | Wash counts/end dates, feature parsing, active plans, purchase, consume/restore and labels. Reuse concepts with versioned purchased terms/eligibility |
| Support helpers | `includes/support-helpers.php` | Messages, legacy message fallback, guest email and five-minute destructive cleanup. Retain history through archive/close policy |
| Owner/admin navigation and logout modal | `includes/shared_owner_sidebar.php`, `includes/shared_admin_sidebar.php`, `includes/shared_logout_modal.php` | Preserve web UI; shared Flutter navigation follows the same role/feature permissions. Relative links resolve at the including page URL, not the include file's directory |
| General translations | `assets/translations.js` plus page dictionaries | Static common AR/EN keys; migrate approved copy into shared backend message keys/Flutter ARB catalogs |
| Service translations | `assets/service-translations.js` | Stored bilingual fields plus phrase/word fallback; keep legacy output while centralizing catalog localization |
| Lamea chat, voice, transcript utility functions | `assets/lamea.js`, included in many pages | Browser posts to external n8n, WebKit speech input, local transcript helper/export functions. Backend implementation/provider config is absent. `saveLameaTranscript` is defined without a caller, so automatic transcript capture is not established. Proposed `POST /assistant/messages`, optionally `GET /assistant/conversations/{id}` after provider/session review |
| Weather/reverse geocoding/routing/map links | `user/home.php`, `user/station-details.php`, `user/live-tracking.php`, `owner/add-station.php`, `includes/location-picker.php` | Browser weather, Leaflet/OpenStreetMap tiles, Nominatim, OSRM and Google Maps URLs; all integrations untested. Proposed `GET /weather`, `GET /maps/reverse-geocode`, `POST /maps/routes` with provider policies/quotas |
| Email | `Emails/reset_otp.php`, `Emails/verify_template.php`, registration/approval/support code, Composer PHPMailer | SMTP implementation exists; central configuration/outbox absent. Internal job interface, not public mail-sending endpoint; verification/reset APIs initiate authorized jobs |
| Plan expiry/renewal reminders | `scripts/plan_expiry_notifier.php` | Seven-day user warning, expired-plan admin notice and expiry update; no scheduler/deployment guard found. Move into CLI-only scheduled job and per-subscription deduplication; no public worker endpoint |
| API front controller | `api/index.php` | Only two read resources, CORS headers/OPTIONS and legacy envelope; see exact current routes below. Reuse JSON conventions through compatibility adapters, not current authorization assumptions |
| Database, dependencies and configuration | `clevo.sql`, `composer.json`, `composer.lock`, `.gitignore`, `.vscode/settings.json`; installed `vendor/composer/installed.json` | 17 application tables, destructive fixtures, one locked PHPMailer dependency, ignored vendor/.env, editor-only setting. No Flutter tree, pubspec, production config, tests or CI found |
| Images/media | `assets/Images`, `assets/uploads`, `uploads/profiles`, `uploads/stations` | Preserve branding/current public media; distinguish public image delivery from private verification documents and validate retained uploads |

## Exact current API / AJAX surface

This list distinguishes actual PHP interfaces from the future REST proposals. Most page handlers return a mix of HTML/redirects and ad hoc JSON; form-encoded POST and a session CSRF token are normally expected. Treat them as web interfaces until converted and contract-tested.

| Current entry point | Methods / selector | Current authentication / notes |
|---|---|---|
| `api/index.php/stations` | GET through PATH_INFO `stations` | Public approved-station ID/name/location/coordinates list |
| `api/index.php/bookings` | GET through PATH_INFO `bookings` | JWT `userID` or session fallback; lists own rows and runs timeout mutation |
| `api/index.php` | OPTIONS | CORS preflight after DB/session bootstrap; other unknown resources return 404; unsupported verbs on known resources lack explicit 405 |
| `general/login.php` | POST `login_action` / `reset_password_action` | Session/CSRF; login redirects, reset returns JSON; recovery bypass A01 |
| `general/register.php` | POST `register_action`, multipart | Public session/CSRF; redirects/renders registration state |
| `general/send-reset-otp.php`, `general/verify-reset-otp.php` | POST form fields | Session/CSRF, JSON |
| `Emails/verify.php` | GET `token` | Verification-token link, redirects |
| `user/station-details.php?id=` | POST `create_booking` | Customer/user session + CSRF, JSON |
| `user/success.php`, `user/order-details.php?id=` | POST `cancel_booking` / `reschedule_booking`; detail also `submit_rating` | Customer/user session + CSRF, JSON |
| `user/booking-history.php` | POST `quick_rating` | Customer/user session + CSRF, JSON |
| `includes/order-details.php?id=` | POST `submit_rating` | Logged-in session + own completed booking + CSRF, JSON |
| `includes/payment-method.php?id=` | POST `set_payment` | Session + own accepted unpaid booking + CSRF, JSON |
| `includes/payment-gateway.php?id=` or `?plan_id=` | POST `mark_visa_paid` | Session/CSRF; simulated confirmation, unsafe for production |
| `includes/pending-dues.php?json` | GET | Session, standard helper envelope; otherwise redirect |
| `user/get_order_status.php?id=` | GET | Session + customer/owner/assigned-driver object relationship, JSON |
| `user/save-location.php` | POST coordinates/address | Customer/user session, **no CSRF**, ad hoc JSON |
| `owner/ajax_stats.php` | GET | Owner session; expired auth redirects before unreachable local 401 branch |
| `owner/incoming-orders.php` | POST `fetch_drivers_list`, `accept_order`, `assign_driver`, `reject_order`, `complete_order` | Owner session + CSRF; ownership checked in each command |
| `owner/manage-drivers.php` | POST `fetch_drivers`, `add_driver`, `delete_driver` | Owner session + CSRF, selected owner's first station |
| `owner/add-station.php` | POST `ajax_save`, multipart | Owner session + CSRF, station upsert UI |
| `owner/owner-reviews.php` | POST `submit_reply` | Owner session + owned review + CSRF |
| `includes/process-reviews.php` | POST `submit_reply` | **No authentication, ownership or CSRF check**; insecure alternate route |
| `owner/owner-wallet.php` | POST `save_bank_account`, `withdraw_balance` | Owner session + CSRF, JSON |
| `driver/driver-dashboard.php` | POST `update_location`, `update_status` | Driver session bound to `driver_booking_id` + CSRF |
| `driver/update-status.php` | POST `update_booking_status` | Driver session/assignment + CSRF |
| `user/notifications.php`, `owner/notifications.php`, `admin/notifications.php` | GET `read`; owner/admin also `read_all` | Role session; mutating GET; user route can return JSON for request headers |
| `owner/owner-dashboard.php` | GET `change_status` plus CSRF in URL | Owner role, approved station; mutating GET |
| `user/profile.php`, `user/loyalty.php`, `user/customer-plans.php` | POST save profile/image, redeem, wallet subscription | Role session + CSRF, HTML/redirect outcomes |
| `general/contact.php`, `user/support.php`, `admin/support-chat.php` | POST ticket/contact/reply | CSRF and appropriate guest/session/admin checks; mixed role mapping defects |
| Admin CRUD/security/finance/profile pages | POST selector-specific forms | Admin session; most validate CSRF, three upload paths do not (P07) |
| `scripts/plan_expiry_notifier.php` | Script execution; no CLI guard | No application authorization if web-routable; actual access depends on server rules |

## Confirmed absent backend areas for mobile/production

The following require implementation, rather than a Flutter wrapper around an existing page: versioned JSON authentication and refresh/revocation; mobile CRUD/action DTOs and error contracts; verified payment capture/refunds/top-ups and event reconciliation; typed financial operation identity; safe upload/download API; FCM/APNs device and delivery services; background/native tracking with freshness; worker/outbox operation; machine-readable plan coverage; archive/audit history and risk-based automated tests. Current pages cover substantial business concepts but do not establish these capabilities.

All 67 first-party PHP files are explicitly mapped above, including presentation-only email/include pages and the standalone notifier. A file's presence is evidence of code to inspect, not evidence that its feature works. Static checks, confirmed defects, fixture integrity observations and unresolved runtime gates remain separately documented in the audit.
