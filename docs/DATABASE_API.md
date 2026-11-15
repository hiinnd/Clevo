# Transactional booking/payment writes

These writes reuse the same PHP services as existing browser pages. Authentication, JSON limits, exact-origin CORS and bearer validation follow [AUTHENTICATION_API.md](AUTHENTICATION_API.md). Only an active customer may use these routes; the server ignores client price, commission and payment-status claims.

Every write requires `Idempotency-Key`: 16–128 ASCII letters, digits, `_` or `-`. Generate a random value for one intended operation; retain it until the result is known. The server scopes keys by authenticated user and operation, hashes them and retains the committed receipt. Equal normalized input replays the original response. Changed input with a committed key returns 409 `idempotency_conflict`. Failures roll back the uncommitted key. A new key cannot charge an already-paid booking. Do not reuse a key for a different booking or payment choice.

## POST /api/v1/bookings

```json
{
  "station_id": 1,
  "service_ids": [10, 11],
  "service_location": "station",
  "booking_date": "2027-01-20",
  "booking_time": "10:00",
  "use_plan": false,
  "address_details": ""
}
```

Service IDs must belong to the approved, active, unarchived station and support the requested mode (`station`, `home`, `delivery`). Date/time is civil time in configured `BOOKING_TIMEZONE`; the server validates opening hours/future time and also stores UTC. Home/delivery requires numeric `latitude`/`longitude` within geographic bounds. Prices and commission come from locked server catalog rows. Plan use must have a valid remaining wash. The existing policy permits multiple bookings at the same time; no capacity promise is made.

Success uses the existing envelope:

```json
{"status":"success","data":{"status":"success","booking_id":123,"order_number":"C0123456789ABCDEF01","payment_method":""},"message":""}
```

An unpaid booking is Pending, even if input contains a forged payment field. A plan booking records its entitlement consumption and plan receipt atomically. A request with the same key may safely replay after catalog availability or appointment time changes; it returns the committed booking rather than creating another.

## POST /api/v1/bookings/{id}/payments

```json
{"method":"wallet","use_points":true}
```

Booking must belong to the customer, be Accepted and have no payment choice already recorded. Methods: `wallet`, `cash`, `plan`, `visa`. Cash is allowed only at the station and is pending collection until authorized completion. Wallet/points/plan use guarded internal transactions. `visa` with an amount due returns `data.status=redirect_visa` and consumes no points/funds; this is **not settlement**. The existing card acknowledgement endpoint returns 503 because no real gateway is implemented. A fully points-covered choice records method `points` server-side.

Errors include 401 unauthenticated, 403 wrong role, 404 not found/other customer's booking, 409 unavailable/insufficient/paid/conflicting key, 422 invalid input/missing request key, and 429 rate limit. Existing API envelopes remain in use. The current success HTTP status is 200 for both creation and replay.

Browser forms retain their existing PHP URLs/actions/CSRF protection and now submit hidden/generated keys. Subscription purchases and owner withdrawals also use shared idempotent services through existing web forms; no additional mobile subscription/withdrawal API is claimed here.
