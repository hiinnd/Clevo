# CLEVO API requests and responses

These are fictional values/placeholders, not real accounts, credentials or provider sessions. Set a trusted deployment base URL including any app subdirectory. Never commit an access/refresh token, password, OTP or driver code. The [OpenAPI specification](openapi.v1.json) has per-operation request schemas/examples and response contracts; the [inventory](API_ENDPOINT_INVENTORY.md) lists all routes and roles.

## Login and refresh

```http
POST /api/v1/auth/login
Content-Type: application/json

{"login":"customer@example.test","password":"<password from secure input>"}
```

```json
{
  "status": "success",
  "data": { "token_type": "Bearer", "access_token": "<redacted>", "refresh_token": "<redacted>", "expires_in": 900 },
  "message": "",
  "meta": { "request_id": "00000000000000000000000000000000" }
}
```

POST the current `refresh_token` to `/auth/refresh`, then atomically replace **both** tokens in protected client storage. Do not retry a consumed refresh token: replay revokes the session family. `/auth/logout` and `/auth/logout-all` use POST with bearer authentication.

## Catalog and filtering

```http
GET /api/v1/stations?query=wash&mode=home&min_price=5&max_price=30&min_rating=4&lat=31.95&lng=35.91&radius_km=10&sort=distance&direction=asc&page=1&per_page=10
```

```json
{
  "status": "success",
  "data": { "stations": [{ "StationID": 1, "Name": "Example station", "Status": "Active", "Rating": "4.80", "PriceMin": "10.00", "PriceMax": "25.00", "DistanceKm": 1.2, "Image": "https://example.test/assets/station.png" }] },
  "message": "",
  "meta": { "request_id": "00000000000000000000000000000000", "pagination": { "page": 1, "per_page": 10, "total": 1, "total_pages": 1 } }
}
```

Location is a paired numeric latitude/longitude. With `mode`, prices are calculated from eligible services for that mode. A station without an eligible priced service is excluded. Station details, services, reviews and status/hours are separate public reads.

## Booking and price

```http
POST /api/v1/bookings
Authorization: Bearer <access token>
Content-Type: application/json
Idempotency-Key: example_booking_intent_000001

{"station_id":1,"service_ids":[10,11],"service_location":"station","booking_date":"2027-01-20","booking_time":"10:00","use_plan":false}
```

```json
{
  "status": "success",
  "data": { "status": "success", "booking_id": 123, "order_number": "C0123456789ABCDEF01", "payment_method": "" },
  "message": "",
  "meta": { "request_id": "00000000000000000000000000000000" }
}
```

Booking creation keeps HTTP 200 for compatibility. Read `/bookings/123/price-breakdown`; do not trust prices in a client cart. The breakdown separates `total`, `discount`, `payable`, `outstanding`, `settlement_state` and `plan_covered`. A covered plan has zero cash payable/outstanding while retaining its nominal service total. Unknown legacy settlement remains explicitly unverified. Home/delivery also requires coordinates and may include address text. Retry the identical normalized request with the **same key** if the network outcome is unknown; changed input with that key returns 409. Cancel/reschedule Pending bookings through their POST action routes and a stable request key.

## Owner and driver actions

```http
POST /api/v1/owner/bookings/123/driver
Authorization: Bearer <owner access token>
Content-Type: application/json
Idempotency-Key: example_assignment_intent_0001

{"driver_id":20}
```

Assignment returns the booking ID; it never writes a plaintext sign-in code into the idempotency receipt. The owner separately calls `POST /owner/bookings/123/driver-code` to receive a one-use code in a protected response. Exchange it at `/auth/driver-exchanges` with the driver's login identifier. That session is bound to booking 123 and cannot change recovery identity or read unrelated account resources. For permanent account/history access, the owner invites the driver by email; verification plus a single-use OTP password setup precedes password login.

After payment, `POST /driver/jobs/123/accept` accepts the task. Then:

```http
PATCH /api/v1/driver/jobs/123/status
Authorization: Bearer <driver access token>
Content-Type: application/json
Idempotency-Key: example_job_status_pickup_0001

{"status":"Pickup"}
```

```http
POST /api/v1/driver/jobs/123/location
Authorization: Bearer <driver access token>
Content-Type: application/json
Idempotency-Key: example_gps_sample_000000001

{"latitude":31.95,"longitude":35.91}
```

Use a new key per intended GPS sample, the same key for retransmission of that sample. The driver cannot skip directly to Completed, append to another assignment or revive a terminal job. Completed task-code sessions are revoked.

## Two-step internal payment

```http
POST /api/v1/payment-sessions
Authorization: Bearer <customer access token>
Content-Type: application/json
Idempotency-Key: example_payment_session_00001

{"booking_id":123,"method":"wallet","use_points":false}
```

HTTP 201:

```json
{
  "status": "success",
  "data": { "payment_session": { "SessionID": "000000000000000000000000000000000000000000000000", "BookingID": 123, "Method": "wallet", "Status": "created", "QuotedAmount": "10.00", "QuotedPoints": "0.00", "ExpiresAt": "2027-01-20 07:10:00.000000" } },
  "message": "",
  "meta": { "request_id": "00000000000000000000000000000000" }
}
```

Creation does not charge money. POST `{}` with a new stable confirmation key to `/payment-sessions/{SessionID}/confirm`. Successful response has session `Status=confirmed` and a `PaymentID`; GET `/payments/{id}` returns its internal receipt state. An expired or changed quote returns 409 without spending points/funds. `visa` returns 503 until a real provider is integrated; no fake session URL is returned.

## Refund/support

```http
POST /api/v1/refund-requests
Authorization: Bearer <customer access token>
Content-Type: application/json
Idempotency-Key: example_refund_request_000001

{"booking_id":123,"reason":"Service was cancelled before execution."}
```

HTTP 201 returns `data.refund_id`. It does not return funds immediately. An admin may approve eligible pre-service internal refunds through `PATCH /admin/refund-requests/{id}` with `{"status":"approved","note":"Reviewed before service execution."}`. Completed/cash/provider cases require manual reconciliation (409), not simulated money.

```http
POST /api/v1/support-requests
Authorization: Bearer <customer access token>
Content-Type: application/json
Idempotency-Key: example_dispute_request_00001

{"subject":"Booking dispute","message":"Please review this booking.","category":"dispute","booking_id":123}
```

HTTP 201 returns `data.ticket_id`. Add messages through scoped message routes. An unrelated user receives 404; a wrong role receives 403. Closure preserves the ticket and messages.

## Error and retry

```json
{
  "status": "error",
  "data": [],
  "message": "idempotency_conflict",
  "error": { "code": "idempotency_conflict", "details": [] },
  "meta": { "request_id": "00000000000000000000000000000000" }
}
```

A 409 does not authorize another charge. Read the resource or resolve the changed intent. For 429, wait for `Retry-After`. For a network timeout, retry the same committed-operation key. For 401 after refresh replay/revocation, sign in again. Send only the request ID and non-sensitive error code to support; do not attach tokens, passwords, bank details or verification documents to logs.
