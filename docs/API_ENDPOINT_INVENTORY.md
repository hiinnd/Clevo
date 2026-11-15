# API endpoint inventory

Generated from the runtime route registry and preserved authentication controllers. `modified` means an endpoint existed before this API phase and now receives pagination/validation/envelope improvements; `unchanged` retains its business behavior; `added` had no `/api/v1` route. Legacy PHP pages remain available. Files: modules under `includes/api/`, shared services `includes/marketplace.php`, auth/commerce/security helpers. Legacy card shortcuts return 503; configured sandbox hosted checkout uses payment-orders. Production online/wallet/settlement activation is blocked; see PAYMENT_INTEGRATION.md.

| Method | /api/v1 path | Change | Roles | Request | Idempotency-Key | Handler |
|---|---|---|---|---|---|---|
| GET | `/stations` | added | public | none | not required | `catalog.stations` |
| GET | `/stations/search` | added | public | none | not required | `catalog.stations` |
| GET | `/stations/{id}` | added | public | none | not required | `catalog.station` |
| GET | `/stations/{id}/services` | added | public | none | not required | `catalog.services` |
| GET | `/stations/{id}/availability` | added | public | none | not required | `catalog.availability` |
| GET | `/stations/{id}/reviews` | added | public | none | not required | `catalog.reviews` |
| GET | `/subscription-plans` | added | public | none | not required | `catalog.plans` |
| GET | `/me` | modified | any | none | not required | `customer.profile` |
| PATCH | `/me` | added | any | Profile | required | `customer.profile_update` |
| POST | `/me/avatar` | added | any | Avatar | required | `customer.avatar` |
| GET | `/me/verification-document` | added | any | none | not required | `customer.verification` |
| GET | `/me/addresses` | added | any | none | not required | `customer.addresses` |
| POST | `/me/addresses` | added | any | Address | required | `customer.address_create` |
| PUT | `/me/addresses/{id}` | added | any | Address | required | `customer.address_update` |
| DELETE | `/me/addresses/{id}` | added | any | Empty | required | `customer.address_delete` |
| GET | `/me/wallet` | modified | any | none | not required | `payments.wallet` |
| GET | `/me/wallet/transactions` | added | any | none | not required | `payments.wallet_transactions` |
| GET | `/me/subscriptions` | added | customer | none | not required | `payments.subscriptions` |
| POST | `/me/subscriptions` | added | customer | Purchase | required | `payments.purchase` |
| GET | `/notifications` | modified | any | none | not required | `customer.notifications` |
| PATCH | `/notifications/{id}/read` | modified | any | Empty | not required | `customer.notification_read` |
| POST | `/notifications/read-all` | added | any | Empty | not required | `customer.notification_all` |
| GET | `/bookings/history` | added | customer | none | not required | `bookings.history` |
| GET | `/bookings` | modified | customer | none | not required | `bookings.list` |
| POST | `/bookings` | modified | customer | Booking | required | `bookings.create` |
| GET | `/bookings/{id}` | modified | any | none | not required | `bookings.detail` |
| GET | `/bookings/{id}/status` | added | any | none | not required | `bookings.status` |
| GET | `/bookings/{id}/events` | added | customer,owner,admin | none | not required | `bookings.events` |
| GET | `/bookings/{id}/price-breakdown` | added | customer,owner,admin | none | not required | `bookings.price` |
| POST | `/bookings/{id}/cancel` | added | customer | Empty | required | `bookings.cancel` |
| POST | `/bookings/{id}/reschedule` | added | customer | Reschedule | required | `bookings.reschedule` |
| POST | `/bookings/{id}/payments` | modified | customer | Payment | required | `payments.booking_pay` |
| POST | `/bookings/{id}/reviews` | added | customer | Review | required | `customer.review` |
| GET | `/me/reviews` | added | customer | none | not required | `customer.reviews` |
| GET | `/owner/stations` | added | owner | none | not required | `owner.stations` |
| PATCH | `/owner/stations/{id}` | added | owner | StationPatch | required | `owner.station_update` |
| POST | `/owner/stations/{id}/image` | added | owner | StationImage | required | `owner.station_image` |
| GET | `/owner/stations/{id}/services` | modified | owner | none | not required | `owner.services` |
| POST | `/owner/stations/{id}/services` | added | owner | ServiceCreate | required | `owner.service_create` |
| PATCH | `/owner/services/{id}` | added | owner | ServicePatch | required | `owner.service_update` |
| DELETE | `/owner/services/{id}` | added | owner | Empty | required | `owner.service_delete` |
| PATCH | `/owner/stations/{id}/availability` | added | owner | Availability | required | `owner.availability` |
| GET | `/owner/bookings` | added | owner | none | not required | `owner.bookings` |
| POST | `/owner/bookings/{id}/accept` | added | owner | Empty | required | `owner.accept` |
| POST | `/owner/bookings/{id}/reject` | added | owner | Empty | required | `owner.reject` |
| POST | `/owner/bookings/{id}/complete` | added | owner | Empty | required | `owner.complete` |
| POST | `/owner/bookings/{id}/driver` | added | owner | Assignment | required | `owner.assign` |
| POST | `/owner/bookings/{id}/driver-code` | added | owner | Empty | not required | `owner.driver_code` |
| GET | `/owner/drivers` | added | owner | none | not required | `owner.drivers` |
| POST | `/owner/drivers` | added | owner | DriverCreate | required | `owner.driver_create` |
| PATCH | `/owner/drivers/{id}` | added | owner | DriverPatch | required | `owner.driver_update` |
| POST | `/owner/drivers/{id}/invitation` | added | owner | DriverInvitation | required | `owner.driver_invite` |
| DELETE | `/owner/drivers/{id}` | added | owner | Empty | required | `owner.driver_delete` |
| GET | `/owner/earnings` | added | owner | none | not required | `owner.earnings` |
| GET | `/owner/bank-account` | added | owner | none | not required | `owner.bank` |
| PUT | `/owner/bank-account` | added | owner | Bank | required | `owner.bank_update` |
| GET | `/owner/withdrawals` | added | owner | none | not required | `owner.withdrawals` |
| POST | `/owner/withdrawals` | added | owner | Empty | required | `owner.withdrawal_create` |
| GET | `/owner/reviews` | added | owner | none | not required | `owner.reviews` |
| PATCH | `/owner/reviews/{id}/reply` | added | owner | Reply | required | `owner.review_reply` |
| GET | `/driver/jobs` | added | driver | none | not required | `driver.jobs` |
| GET | `/driver/jobs/history` | added | driver | none | not required | `driver.history` |
| GET | `/driver/jobs/{id}` | added | driver | none | not required | `driver.detail` |
| POST | `/driver/jobs/{id}/accept` | added | driver | Empty | required | `driver.accept` |
| PATCH | `/driver/jobs/{id}/status` | added | driver | DriverStatus | required | `driver.status` |
| POST | `/driver/jobs/{id}/location` | added | driver | Location | required | `driver.location` |
| GET | `/driver/tasks` | modified | driver | none | not required | `driver.jobs` |
| GET | `/driver/tasks/history` | added | driver | none | not required | `driver.history` |
| GET | `/driver/tasks/{id}` | modified | driver | none | not required | `driver.detail` |
| POST | `/driver/tasks/{id}/accept` | added | driver | Empty | required | `driver.accept` |
| PATCH | `/driver/tasks/{id}/status` | added | driver | DriverStatus | required | `driver.status` |
| POST | `/driver/tasks/{id}/location` | added | driver | Location | required | `driver.location` |
| POST | `/payment-sessions` | added | customer | PaymentSession | required | `payments.session_create` |
| POST | `/webhooks/paytabs` | added | public | none | not required | `finance.webhook` |
| POST | `/payment-orders` | added | customer | OnlineCheckout | required | `finance.checkout` |
| GET | `/payment-orders` | added | customer | none | not required | `finance.orders` |
| GET | `/payment-orders/{session}` | added | customer | none | not required | `finance.order` |
| POST | `/payment-orders/{session}/cancel` | added | customer | Empty | required | `finance.cancel` |
| POST | `/payment-orders/{session}/reconcile` | added | customer | Empty | not required | `finance.reconcile` |
| POST | `/payment-orders/{session}/refund-requests` | added | customer | OnlineRefund | required | `finance.refund_request` |
| GET | `/payment-refunds` | added | customer | none | not required | `finance.refunds` |
| GET | `/financial-receipts` | added | customer | none | not required | `finance.receipts` |
| GET | `/financial-receipts/{id}` | added | customer | none | not required | `finance.receipt` |
| GET | `/owner/financial-statement` | added | owner | none | not required | `finance.owner_statement` |
| GET | `/owner/settlements` | added | owner | none | not required | `finance.settlements` |
| GET | `/admin/payment-reconciliation` | added | admin | none | not required | `finance.report` |
| GET | `/admin/payment-refunds` | added | admin | none | not required | `finance.refunds` |
| POST | `/admin/payment-refunds/{id}/submit` | added | admin | Empty | required | `finance.refund_submit` |
| GET | `/admin/financial-receipts/{id}` | added | admin | none | not required | `finance.receipt` |
| GET | `/admin/settlements` | added | admin | none | not required | `finance.settlements` |
| POST | `/admin/settlements` | added | admin | SettlementRequest | required | `finance.settlement_request` |
| GET | `/payment-sessions/{session}` | added | customer | none | not required | `payments.session` |
| POST | `/payment-sessions/{session}/confirm` | added | customer | Empty | required | `payments.confirm` |
| GET | `/payments` | added | customer | none | not required | `payments.list` |
| GET | `/payments/{id}` | added | customer | none | not required | `payments.detail` |
| GET | `/refund-requests` | added | customer | none | not required | `payments.refunds` |
| POST | `/refund-requests` | added | customer | Refund | required | `payments.refund_create` |
| GET | `/support-requests` | added | any | none | not required | `customer.support` |
| POST | `/support-requests` | added | any | Support | required | `customer.support_create` |
| GET | `/support-requests/{id}` | added | any | none | not required | `customer.support_detail` |
| GET | `/support-requests/{id}/messages` | added | any | none | not required | `customer.support_messages` |
| POST | `/support-requests/{id}/messages` | added | any | Reply | required | `customer.support_reply` |
| GET | `/admin/security-status` | unchanged | admin | none | not required | `admin.security` |
| GET | `/admin/users` | added | admin | none | not required | `admin.users` |
| GET | `/admin/users/{id}` | added | admin | none | not required | `admin.user` |
| GET | `/admin/users/{id}/verification-document` | added | admin | none | not required | `admin.verification` |
| PATCH | `/admin/users/{id}/status` | added | admin | AccountStatus | required | `admin.user_status` |
| GET | `/admin/stations` | added | admin | none | not required | `admin.stations` |
| GET | `/admin/stations/{id}` | added | admin | none | not required | `admin.station` |
| PATCH | `/admin/stations/{id}/status` | added | admin | StationStatus | required | `admin.station_status` |
| DELETE | `/admin/stations/{id}` | added | admin | Empty | required | `admin.station_archive` |
| GET | `/admin/bookings` | added | admin | none | not required | `admin.bookings` |
| GET | `/admin/bookings/{id}` | added | admin | none | not required | `admin.booking` |
| POST | `/admin/bookings/{id}/cancel` | added | admin | BookingCancellation | required | `admin.booking_cancel` |
| GET | `/admin/financial-reports` | added | admin | none | not required | `admin.financial` |
| GET | `/admin/subscription-plans` | added | admin | none | not required | `admin.plans` |
| POST | `/admin/subscription-plans` | added | admin | PlanCreate | required | `admin.plan_create` |
| PATCH | `/admin/subscription-plans/{id}` | added | admin | PlanPatch | required | `admin.plan_update` |
| DELETE | `/admin/subscription-plans/{id}` | added | admin | Empty | required | `admin.plan_delete` |
| GET | `/admin/subscriptions` | added | admin | none | not required | `admin.subscriptions` |
| PATCH | `/admin/subscriptions/{id}` | added | admin | SubscriptionStatus | required | `admin.subscription_status` |
| GET | `/admin/support-requests` | added | admin | none | not required | `admin.support` |
| GET | `/admin/support-requests/{id}` | added | admin | none | not required | `admin.support_detail` |
| POST | `/admin/support-requests/{id}/messages` | added | admin | Reply | required | `admin.support_reply` |
| PATCH | `/admin/support-requests/{id}/status` | added | admin | TicketStatus | required | `admin.support_status` |
| GET | `/admin/disputes` | added | admin | none | not required | `admin.disputes` |
| GET | `/admin/disputes/{id}` | added | admin | none | not required | `admin.support_detail` |
| POST | `/admin/disputes/{id}/messages` | added | admin | Reply | required | `admin.support_reply` |
| PATCH | `/admin/disputes/{id}/status` | added | admin | TicketStatus | required | `admin.support_status` |
| GET | `/admin/refund-requests` | added | admin | none | not required | `admin.refunds` |
| PATCH | `/admin/refund-requests/{id}` | added | admin | Decision | required | `admin.refund_decision` |
| GET | `/admin/withdrawals` | added | admin | none | not required | `admin.withdrawals` |
| GET | `/admin/withdrawals/{id}` | added | admin | none | not required | `admin.withdrawal` |
| PATCH | `/admin/withdrawals/{id}` | added | admin | WithdrawalStatus | required | `admin.withdrawal_status` |
| POST | `/auth/register` | modified | public | Register | not required | `auth.auth_register` |
| POST | `/auth/login` | modified | public | Login | not required | `auth.auth_login` |
| POST | `/auth/refresh` | modified | public | Refresh | not required | `auth.auth_refresh` |
| POST | `/auth/driver-exchanges` | modified | public | DriverExchange | not required | `auth.auth_driver-exchanges` |
| POST | `/auth/password/forgot` | modified | public | Email | not required | `auth.auth_password_forgot` |
| POST | `/auth/password/verify` | modified | public | Otp | not required | `auth.auth_password_verify` |
| POST | `/auth/password/reset` | modified | public | Reset | not required | `auth.auth_password_reset` |
| POST | `/auth/email/verify` | modified | public | Verification | not required | `auth.auth_email_verify` |
| POST | `/auth/email/resend` | modified | public | Email | not required | `auth.auth_email_resend` |
| POST | `/auth/logout` | modified | any | none | not required | `auth.auth_logout` |
| POST | `/auth/logout-all` | modified | any | none | not required | `auth.auth_logout-all` |
