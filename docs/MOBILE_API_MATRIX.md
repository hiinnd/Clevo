# Mobile API integration inventory

All paths below are relative to `/api/v1/`. The authoritative server contracts remain `openapi.yaml` and `docs/API_ENDPOINT_INVENTORY.md`. Admin routes are intentionally consumed by the PHP web dashboard, not exposed as mobile administrator screens. The app uses the current scalar server role and does not elevate a customer/driver to owner locally.

| Experience / feature | Flutter file | Actual PHP API |
|---|---|---|
| Shared login / task-scoped driver login | `features/auth.dart`, `core/state.dart` | `POST auth/login`, `POST auth/driver-exchanges`, `GET me` |
| Refresh / logout | `core/api.dart` | `POST auth/refresh`, `POST auth/logout` |
| Customer registration / email verification | `features/auth.dart` | `POST auth/register`, `POST auth/email/resend`, `POST auth/email/verify` |
| Password recovery | `features/auth.dart` | `POST auth/password/forgot`, `POST auth/password/verify`, `POST auth/password/reset` |
| Customer, owner, driver dashboards | `features/dashboards.dart` | Server-verified `GET me` role; dashboards link to real scoped resources without synthetic metrics |
| Station search / price, rating, mode, location filters | `features/customer.dart` | `GET stations` with validated query parameters, pagination and sorting |
| Station details / services / availability | `features/customer.dart` | `GET stations/{id}`, `GET stations/{id}/services`, `GET stations/{id}/availability` |
| Multiple-service, scheduled station/home/pickup-return booking | `features/customer.dart` | `POST bookings`; no submitted prices or calculated client totals |
| My bookings / history / details / timeline | `features/bookings.dart`, `features/history.dart` | `GET bookings`, `GET bookings/history`, `GET bookings/{id}`, `GET bookings/{id}/events` |
| Cancel / reschedule / review | `features/bookings.dart` | `POST bookings/{id}/cancel`, `POST bookings/{id}/reschedule`, `POST bookings/{id}/reviews` |
| Pickup/return private handoff code | `features/bookings.dart` | `POST bookings/{id}/confirmation-code`; code stays in dialog memory |
| Customer live driver location | `features/tracking.dart` | `GET bookings/{id}/tracking`; map requires configured platform keys |
| Cash / hosted online payment | `features/payments.dart` | `POST bookings/{id}/payments` with `cash`; `POST payment-orders` |
| Payment status / provider verification / retry / cancellation | `features/payments.dart`, `features/history.dart` | `GET payment-orders`, `GET payment-orders/{session}`, `POST payment-orders/{session}/reconcile`, `POST payment-orders/{session}/cancel` |
| Refunds / receipts | `features/payments.dart`, `features/history.dart` | `POST payment-orders/{session}/refund-requests`, `GET payment-refunds`, `GET financial-receipts` |
| Recorded wallet balance / movements | `features/payments.dart`, `features/history.dart` | `GET me/wallet`, `GET me/wallet/transactions`; commercial spending/top-up remains blocked |
| Catalog plans / existing subscriptions | `features/payments.dart`, `features/history.dart` | `GET subscription-plans`, `GET me/subscriptions`, `POST payment-orders` purpose `subscription`; server-only verified activation |
| Owner stations / station profile | `features/owner.dart` | `GET owner/stations`, `PATCH owner/stations/{id}` |
| Owner service CRUD / price / duration / supported service modes | `features/owner.dart` | `GET/POST owner/stations/{id}/services`, `PATCH/DELETE owner/services/{id}` |
| Owner hours / capacity / status / additional fees | `features/owner.dart` | `GET stations/{id}`, `PATCH owner/stations/{id}/availability` |
| Owner orders / acceptance / rejection / completion | `features/bookings.dart` | `GET owner/bookings`, `POST owner/bookings/{id}/accept`, `/reject`, `/complete`, `/service-completed` |
| Driver provisioning / invitations / deactivation | `features/owner.dart` | `GET/POST owner/drivers`, `PATCH/DELETE owner/drivers/{id}`, `POST owner/drivers/{id}/invitation` |
| Assignment | `features/bookings.dart` | `POST owner/bookings/{id}/driver`; backend prevents conflicting assignments |
| Earnings / commission / bank account | `features/owner.dart`, `features/history.dart` | `GET owner/financial-statement`, `PUT owner/bank-account`; settlements are commercially gated |
| Owner reviews / replies | `features/history.dart` | `GET owner/reviews`, `PATCH owner/reviews/{id}/reply` |
| Driver assigned jobs / history / detail | `features/driver.dart` | `GET driver/jobs` and `/history` return the **tasks** array; `GET driver/jobs/{id}` returns **booking** |
| Driver acceptance / stage / customer-code handoff | `features/driver.dart` | `POST driver/jobs/{id}/accept`, `PATCH driver/jobs/{id}/delivery-stage`, `POST driver/jobs/{id}/confirm-handoff` |
| Driver authorized foreground GPS | `features/tracking.dart`, `core/tracking_policy.dart` | `GET driver/jobs/{id}/tracking`, `POST driver/jobs/{id}/locations`; assignment, sequence, original UTC time, accuracy, `capture_mode=foreground` |
| Driver navigation | `features/driver.dart` | Assigned-job coordinates only; external Google Maps HTTPS directions URL |
| Shared profile / avatar | `features/account.dart` | `GET/PATCH me`, `POST me/avatar` multipart; backend validates MIME/authorization/storage |
| Customer saved addresses | `features/account.dart` | `GET/POST me/addresses`, `PUT/DELETE me/addresses/{id}` |
| Notification inbox / read / preferences / device registration | `features/account.dart`, `core/notifications.dart` | `GET notifications`, `PATCH notifications/{id}/read`, `GET/PUT me/notification-preferences`, `POST me/notification-devices`, `/revoke` |
| Support request / conversation | `features/account.dart` | `GET/POST support-requests`, `GET/POST support-requests/{id}/messages` |
| Customer / station reviews | `features/history.dart` | `GET me/reviews`, `GET stations/{id}/reviews` |

New mobile client code does not alter endpoint semantics or add a second booking/payment implementation. A shared Dio layer applies HTTPS configuration, access-token refresh and error handling; each mutation carries its own operation key where supported. Unknown mutation outcomes keep forms locked to the original request for safe retry or direct the user to history. All paginated resource lists support refresh and load-more.

Known backend gaps: multi-role memberships/context switch, mobile owner station creation/onboarding, owner-specific subscription administration. Operational gates: real API hostname/deployment, Firebase/APNs projects and credentials, restricted Maps keys, commercial gateway approval, production mail/queue operations, release signing and actual device qualification. These are not represented by fake successful actions.
