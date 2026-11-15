# CLEVO authentication and authorization API

Implemented security phase, 2026-11-15. PHP/MySQL remain the backend. The older audit/architecture files describe the pre-change baseline and target system; this document describes the **implemented** API surface. Setup, migrations, credential rotation and limitations are in [SECURITY_CHANGES.md](SECURITY_CHANGES.md).

## Transport and routing

Use HTTPS in production. Production bootstrap refuses non-HTTPS requests; only explicitly configured `TRUSTED_PROXIES` can supply `X-Forwarded-Proto`. Configure the web server to forward `Authorization` to PHP and keep credentials out of request/access/error logs.

With Apache rewriting enabled: `/api/v1/auth/login`. Without rewriting: `/api/v1/index.php/auth/login`. Native apps send JSON with `Content-Type: application/json`; body limit is 16 KiB. Owner application registration can instead use multipart form data with `verification_file`. The API is bearer-only: a PHP browser session or allowed CORS origin never authenticates an API request. Invalid explicit bearer credentials cannot fall back to cookies.

Response envelope:

```json
{
  "status": "success",
  "data": { "token_type": "Bearer", "expires_in": 900 },
  "message": ""
}
```

Token responses also contain `access_token` and `refresh_token`. Real values are deliberately omitted here. Empty data is `[]` for compatibility with the existing response helper. Errors have `status: error`, a stable message/code and the matching HTTP status; no SQL, key or exception detail is returned. Responses have `Cache-Control: no-store`.

Common statuses: 201 registered; 200 successful command/read; 204 accepted CORS preflight; 400 malformed JSON; 401 absent/invalid/expired/revoked authentication; 403 role/origin/CSRF denied; 404 inaccessible object; 405 wrong method with Allow; 409 state conflict; 413 too large; 415 wrong content type; 422 validation/challenge failure; 429 throttled with Retry-After; 503 unavailable configuration/service. Do not retry non-idempotent business operations blindly. A refresh replay requires a fresh login, not retrying the old token.

## Public authentication endpoints

All routes below use **POST**. None trusts a caller-supplied identity, permission or role claim.

| Route | Request fields | Behavior |
|---|---|---|
| `/auth/login` | `login` (email or local phone), `password` | Uses `password_verify`, current active account/status/role and reset-required policy; returns an access/refresh pair. Generic `invalid_credentials` on failure. |
| `/auth/driver-exchanges` | `login`, `code` (six digits) | Exchanges a hashed, 15-minute, single-use assignment code. Resulting session is restricted to that booking and active driver, with a maximum 12-hour family lifetime. Code is issued/reissued only by the owning station. |
| `/auth/refresh` | `refresh_token` | Atomically consumes the current refresh token and returns a new pair. Replay commits revocation of the entire session family, including already-issued access tokens. |
| `/auth/register` | `role` (`customer` or `owner` only), `full_name`, `email`, `phone`, `password`; owner also `station_name` and multipart `verification_file` | Creates a pending account and queues email verification. Returns 201 `verification_required`; does not return credentials. Admin/driver/arbitrary roles cannot self-register. Driver provisioning remains owner-controlled. |
| `/auth/password/forgot` | `email` | Returns a random `challenge_id` and the same generic success message regardless of account existence. Known accounts receive a queued OTP. Unknown identifiers produce no account or usable challenge. |
| `/auth/password/verify` | `challenge_id`, `otp` | Correct, unexpired OTP is consumed and exchanged for a `reset_grant`. Five wrong attempts exhaust that challenge. |
| `/auth/password/reset` | `reset_grant`, `password` | Consumes an unexpired grant atomically, hashes the new password, clears pending recovery-address changes, invalidates outstanding challenges and revokes all account sessions/refresh families. It does not activate a pending or banned account. |
| `/auth/email/resend` | `email` | Generic success; queues a replacement verification message only when verification is required. Previous same-purpose challenges are invalidated. |
| `/auth/email/verify` | `token` | Consumes a one-hour verification token. Customer activation is allowed; owner applications remain pending administrative approval. A verified recovery-address change updates the email and revokes prior sessions/challenges. |

Password creation/reset policy: 8-72 bytes, upper/lowercase letters, a digit and a non-alphanumeric character; control bytes are rejected. Password input is not silently truncated or trimmed. Login continues to accept existing modern password hashes without imposing a new minimum on their already-set password. Legacy plaintext/SHA-256 and known exposed fixture passwords are invalidated by migration and require recovery/reprovisioning.

Verification emails contain `Emails/verify.php#token=...`. The fragment is not sent in an HTTP request. The web landing page removes it from browser history and POSTs it with CSRF protection, avoiding token-bearing access-log query strings. Old plaintext verification tokens are deliberately invalidated by migration; resend generates a new link. Mobile clients may extract that fragment and submit the token through `/auth/email/verify`; native universal-link registration is a later Flutter task.

Reset flow is strictly **request -> verify OTP -> receive reset grant -> reset**. OTPs and grants expire after five minutes. Challenge secrets are stored as keyed hashes, never in legacy plaintext OTP columns. A reset request/session/challenge ID is not permission to replace a password. Hold grants only long enough to complete the flow, then discard them. Email delivery is asynchronous; an operational mail worker is required.

## Access tokens, refresh storage and logout

- JWT access tokens use the maintained `firebase/php-jwt` library with HS256 and an independent, environment-provided signing key of at least 32 random bytes. Default access lifetime is 900 seconds; configurable values are clamped to 60-900 seconds and session expiry. Strict validation covers signature/algorithm/type, issuer/audience, subject/user ID consistency, canonical role, issued/not-before/expiry times, token identifier, session identity and account version.
- Refresh tokens are opaque 256-bit random values. MySQL stores only their SHA-256 hashes, session/family linkage, expiry and used/revoked timestamps. A session has a fixed absolute expiry; rotation does not extend it indefinitely. Default family lifetime is 30 days, capped at 12 hours for assignment-code sessions.
- Every protected request checks the current account, persisted session, auth version, role and relevant assignment. Tokens cannot retain authorization after restriction, reset, explicit revocation or assignment completion. Used refresh rows remain until after family expiry so replay detection continues to work.
- Rotate refresh with a **single in-flight refresh operation** per app session. Concurrent refresh using the same token intentionally causes replay detection and revokes the family. If the server rotated but the network lost the response, reusing the old token also requires login. There is no replay grace window.
- Flutter should keep credentials in Keychain/Android secure storage, never URLs, analytics, crash logs or ordinary shared preferences. The API is a CLEVO authentication API, not an OAuth authorization-server implementation. Bearer-token theft remains a risk requiring protected transport/storage.

Authenticated POST `/auth/logout` revokes the current session family and its access tokens. POST `/auth/logout-all` revokes all account sessions/families and outstanding security challenges. Both require the access bearer. Separate device logins are separate families. Refresh/logout effects are persisted in the database, not just deleted from a client cookie.

## Protected resource examples implemented in this phase

| Method / route | Authorization and response scope |
|---|---|
| `GET /me` | Current user's ID/name/canonical role/email/phone only. |
| `GET /me/wallet` | Current user only; ignores `user_id` supplied by a caller. Multiple legacy wallets return a reconciliation conflict instead of choosing an arbitrary one. |
| `GET /bookings?after_id=` | Customer's own bookings, ascending IDs, at most 50. Owner/driver/admin use authorized detail routes rather than a customer list. |
| `GET /bookings/{id}` | Customer owns booking, owner owns station, driver has active assignment and grant scope, or authorized admin. Explicit DTO excludes task credentials and internal identity/financial metadata. |
| `GET /notifications?after_id=` | Personal inbox plus current role broadcasts, at most 50; role read state is recipient-specific. |
| `PATCH /notifications/{id}/read` | Only an accessible personal/role notification; other users' IDs return 404. |
| `GET /owner/stations/{id}/services` | Owner role and station ownership; up to 100 catalog rows. |
| `GET /driver/tasks` | Active jobs assigned to that driver; a task-code session sees only its own task. |
| `GET /driver/tasks/{id}` | Active assigned job within the authenticated session's grant scope. |
| `GET /admin/security-status` | Admin role only; no secrets or operational credentials returned. |

`/api/index.php/stations` and `/api/index.php/bookings` retain legacy read URLs/envelopes. Stations are public and limited to 100 approved records. Bookings now require a valid new bearer token and return only the customer's own allowlisted fields (maximum 50); insecure old cookies/shared-key JWTs are not accepted. Unsupported methods return 405. These endpoints do not run booking-expiry side effects.

This is **not** the complete target marketplace API: booking creation/payment/withdrawals and full owner/admin mutations still use existing hardened web entry points. A real payment processor and the full Flutter API remain separate phases. Do not advertise the architecture's proposed routes as implemented here.

## Existing web compatibility

Existing PHP login forms, role redirects, owner driver-code login, dashboards, recovery modals and profile forms remain in place. They use strict file sessions in protected private storage, a dedicated cookie name (`__Host-CLEVOSESSION` in production; `CLEVOSESSION` locally), HttpOnly/SameSite Lax, Secure in production and session ID regeneration at login. Sessions expire after 30 minutes idle / 12 hours absolute and are revalidated against database state on every request. Deployment invalidates pre-migration sessions deliberately.

All session-authenticated mutations require CSRF. Existing forms/AJAX callers were updated, including location saves, logout, availability, legacy replies and notification reads. GET logout/read/availability URLs no longer mutate state. API bearer requests do not use browser session CSRF tokens. New recovery-email changes keep the old email usable until the new address is verified; verification revokes old sessions.

Owner driver creation uses an unknowable random password hash, with the existing task-code flow providing access until credentials are securely provisioned/recovered. Driver removal deactivates/revokes instead of deleting assignment history. New task codes are displayed once to the authorized owner, hashed in MySQL, never placed in notifications, and can be reissued from incoming orders. Current task views/updates and tracking enforce the assigned job.

## Throttles and CORS

| Operation | IP budget | Identifier budget | Window |
|---|---:|---:|---|
| Login / driver exchange | 30 | 10 | 15 minutes |
| Registration | 10 | 5 | 1 hour |
| Reset request / email resend | 15 | 5 | 1 hour |
| OTP/email verification/reset action | 30 | 8 when a challenge identifier is used | 15 minutes |
| Refresh | 120 | 60 if invoked with an identifier | 1 minute |
| All API traffic | 240 | 120 per authenticated user | 1 minute |
| Authenticated sensitive API mutations | — | 30 per user | 1 minute |
| Web mutations | 180 | 90 per authenticated user | 1 minute |

Limits use atomic, persisted MySQL counters with HMAC-obscured keys; restarting PHP or spoofing `X-Forwarded-For` does not reset identity. IP identity deliberately uses the direct remote peer; deployments behind a shared proxy need an appropriately configured edge limiter and capacity review. The application does not trust arbitrary forwarded client-IP headers. OTP attempt budgets are additional to request throttling.

Set `CORS_ALLOWED_ORIGINS` to comma-separated **exact origins**, including scheme/optional port and no path/trailing slash. Wildcards, `null`, lookalike domains and unlisted origins are denied. Production origins must use HTTPS. Approved preflight returns 204 without authentication; actual protected requests still need valid bearer credentials. No credentialed-cookie CORS is enabled. Native Flutter clients normally have no Origin header and still must authenticate.

## Uploads and private information

All new profile/station images and verification documents use generated filenames in private filesystem storage. MIME is determined from bytes, original extensions must match, size is limited to 5 MiB, image dimensions/pixel count are bounded, SVG/executable content and PHP polyglots are rejected. Verification permits inspected PDF/images; active PDF script/launch/embedded-file markers are rejected and PDFs are downloaded rather than executed inline. This is not a malware-scanner guarantee.

`includes/media.php` serves allowlisted image bytes; profile/station images remain public marketplace media. Verification documents require the linked owner or admin. The `user_id` document-review selector is admin-only. Legacy verification directories must be blocked from direct access using the supplied deployment rules. Private downloads currently use web-session authorization; a bearer download API for a future Flutter document-review flow is not implemented in this phase.

## Tested boundaries and remaining validation

Automated security tests exercise actual PHP HTTP routes, a real isolated MariaDB database, OTP/email/revocation flows, four roles, ownership boundaries, multipart uploads, cookie/CSRF behavior, generated JavaScript syntax and a two-process refresh race. See [SECURITY_TEST_RESULTS.md](SECURITY_TEST_RESULTS.md) for exact results and failures corrected during implementation.

Real SMTP delivery, live key rotation, production Apache/proxy/HTTPS rules, supported production MySQL version, hardware-backed mobile storage, device deep links and full browser visual interaction were not validated. This phase supplies code and deployment requirements; it is not a production security certification.
