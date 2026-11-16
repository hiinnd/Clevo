# Driver logistics and tracking

2026-11-16. The existing PHP/MySQL booking engine, role model, pages and financial guards remain. The user explicitly selected API plus a Flutter integration example because this repository has no Flutter app. No production location collection, background tracking, native permission or physical handoff was tested.

## Audit findings and fixes

| Severity | Confirmed source finding | Resolution |
|---|---|---|
| HIGH | `user/get_order_status.php` substituted customer/station/default coordinates for missing driver GPS. | Shared tracking projection returns null/waiting/stale; PHP map creates a driver marker only from an authorized fresh sample. |
| HIGH | GPS cache had no capture age/accuracy and remained readable on terminal jobs. | Assignment-scoped timestamps, sequence, accuracy, phase epoch, active-job authorization and immediate cache clearing. |
| HIGH | `commerce.php` location writer relied on `DriverID` and coarse booking status, with no current assignment/consent-mode metadata. | Current assignment, active driver/station, verified payment and driving-stage checks in `logistics.php`. |
| HIGH | Driver controlled washing/return without station confirmation or customer handoff evidence. | Ordered delivery stages, owner-only service completion and customer-issued single-use pickup/delivery codes. |
| MEDIUM | No retention worker; public routing demo received private coordinates and the marker was snapped to route geometry. | Bounded GPS cleanup and actual GPS display; unapproved public routing calls removed. No simulated route/ETA. |

Migration `007_driver_tracking.sql` is additive: booking delivery stage/version; assignment execution lease and sequence metadata; GPS sample metadata; `delivery_events` and hashed `delivery_confirmations`. Migration 008 adds the phase epoch as a forward-only fix without changing 007. These migrations do not rewrite historical stages or delete business records. Existing legacy jobs require the owning station's explicit adoption, preserving current status and recording that the initial stage was inferred, not historical proof. The PHP owner page and API expose that operation. No historical handoff confirmation is fabricated.

## State and authority

| Delivery stage | Legacy booking projection | Authority |
|---|---|---|
| Assigned | Accepted | Station owner assigns eligible station driver |
| Accepted | Accepted | Assigned driver accepts paid job and obtains execution lease |
| Heading to Pickup | In Progress | Assigned driver |
| Arrived at Pickup | Arrived | Assigned driver |
| Vehicle Collected | Pickup | Assigned driver plus customer pickup code |
| Arrived at Station | Washing | Assigned driver; this projection is not owner completion |
| Service Completed | Washing | Owning station confirms service done |
| Returning Vehicle | Returning | Assigned driver after station confirmation |
| Delivered | Completed | Assigned driver plus customer delivery code; normal booking completion effects apply |

Canonical API values are `assigned`, `accepted`, `heading_pickup`, `arrived_pickup`, `vehicle_collected`, `arrived_station`, `service_completed`, `returning_vehicle`, `delivered`. No skips or backward transitions are accepted. Cancellation uses the existing booking policy and records a terminal `cancelled` logistics stage. The existing centralized booking transition remains the only booking-status writer; compatibility driver status URLs translate through the same stricter service. Owner completion at the station does not prematurely pay out a delivery booking: financial completion occurs after confirmed vehicle return.

Assignment locks booking/station/driver, validates station ownership and current driver status, and checks reserved interval overlap. A generated unique active execution key permits one accepted/running job per driver. Future non-overlapping assignments remain possible; starting early checks expanded station/driver capacity. Started jobs cannot be silently reassigned. Reassignment clears cached GPS and revokes task-code sessions. Task-scoped sessions cannot access a different assignment's booking.

Pickup/delivery code issuance belongs only to the booking customer, at the corresponding handoff stage. Codes expire in ten minutes, allow eight wrong attempts per issuance, and have bounded issuance rate. Hashes are stored; replay receipts contain encrypted codes, never plaintext. A new code supersedes the previous one. Wrong attempts commit even though the API returns rejection. Successful consumption and stage advancement commit together. No code appears in push messages, logs or URLs. Code possession confirms customer participation, not a cryptographic proof of physical custody or GPS truth.

## APIs

All routes are relative to `/api/v1`; new mutation contracts are in OpenAPI.

- `GET /bookings/{id}/tracking`: own customer or station owner, no public tracking URL.
- `GET /bookings/{id}/delivery-events`: scoped stage history with actor role/time/reason.
- `POST /bookings/{id}/confirmation-code`: customer, `{kind: pickup|delivery}`, Idempotency-Key.
- `GET /driver/jobs/{id}/tracking`: assigned driver, including assignment ID and sequence head.
- `POST /driver/jobs/{id}/locations`: assignment ID, sequence, latitude, longitude, accuracy_m, UTC captured_at; foreground only.
- `PATCH /driver/jobs/{id}/delivery-stage`: assigned driver, ordered stage, Idempotency-Key.
- `POST /driver/jobs/{id}/confirm-handoff`: assigned driver, kind/code, Idempotency-Key.
- `POST /owner/bookings/{id}/service-completed`: owning station, Idempotency-Key.
- `POST /owner/bookings/{id}/logistics-adoption`: explicit legacy adoption by owner, Idempotency-Key.

Existing driver/jobs and driver/tasks aliases, assignment, history, location and PHP controllers remain adapters to these services. Old coordinate-only samples may retain unknown accuracy but are never presented as a fresh accurate marker. New clients should use the strict locations endpoint and all metadata. Old status commands cannot bypass new handoff/station requirements.

## Location privacy, stale state and recovery

Sharing is allowed only during heading/arrival for pickup, vehicle transport and return. It pauses during station work, on background requests (not approved), inactive account/station, unproven/refunded payment or ended assignment. Terminal transitions clear the legacy cache immediately and revoke task access. No API exposes raw location history. A new driving phase starts without reusing an old pickup marker.

GPS samples store client capture time, server receipt time, claimed accuracy, foreground mode, assignment ID and increasing sequence. Coordinates/accuracy must be finite and bounded; timestamps are UTC, at most 15 seconds in the future or 120 seconds old. Client GPS/accuracy remains an untrusted report, not proof of payment or custody. Duplicate sequence/payload is harmless; changed payload on that sequence conflicts; older captures cannot overwrite the latest projection. Assignment changes invalidate a queued sample. Uploads are rate limited to 12/minute per assignment in addition to API/session limits.

Projection states: waiting, fresh (up to 30 seconds), stale, inaccurate (unknown or above 100m accuracy), inactive. Coordinates older than 120 seconds are withheld. Both PHP and the Flutter example draw only a fresh driver marker. Network failure clears the marker instead of pretending the last point is live. PHP collection is foreground-only, uses one in-memory retry sample with its original timestamp/sequence, pauses when permission/GPS is unavailable or server authorization is stale, and clears samples on hide, assignment change and completion. There is no persistent browser location queue.

Schedule `php scripts/purge-tracking.php --apply` each minute with the dedicated retention identity. Default raw retention is one hour; validated `TRACKING_RETENTION_HOURS` permits 1–24 hours. Batches cap at 1,000 GPS records; completed/cancelled/reassigned jobs' samples are eligible immediately. Expired code hashes are cleared. This deletion is intentional location minimization, not deletion of booking/financial/status history. Limit private backup retention too; database cleanup does not erase old backups. The script without --apply performs no cleanup. Deployment must monitor retention lag.

## Flutter and native permission preparation

[clevo_tracking_map.dart](flutter/clevo_tracking_map.dart) is a mergeable customer widget using an injected authenticated fetch function and approved tile URL. Supply `response.data.tracking`, localization and a customer/station map center. Customer viewing does not need device location permission. The widget has no embedded API token, provider credential or background location service. It was not compiled because the repo has no Flutter package/SDK setup; integrators must add compatible `flutter_map`/`latlong2`, run analyze/widget tests and test devices. Consult [flutter_map marker docs](https://docs.fleaflet.dev/layers/marker-layer) for the API used by the example.

For the future driver collector, request foreground permission in context after a job is accepted; support denied/permanently denied, disabled location and approximate accuracy. Start/stop collection from authoritative job state, not just app login. Keep a small encrypted bounded offline queue only if the final app needs it; discard expired samples and clear it at logout/reassignment/termination. Never persist handoff codes or access tokens in ordinary preferences.

Background tracking remains disabled at the PHP endpoint and is not enabled by an environment switch. A future approved need must justify Android location foreground-service/background permissions, a visible disclosure/notification, consent, target-SDK/store review and device tests. Android's requirements differ by OS and app state: [background permission](https://developer.android.com/develop/sensors-and-location/location/permissions/background), [foreground-service types](https://developer.android.com/about/versions/14/changes/fgs-types-required). iOS should start with When In Use; Always/background capability requires a justified separate flow and real-device suspension/revocation testing. See [Apple authorization](https://developer.apple.com/documentation/CoreLocation/requesting-authorization-to-use-location-services) and [background updates](https://developer.apple.com/documentation/corelocation/cllocationmanager/allowsbackgroundlocationupdates).

Notifications use committed outbox delivery, including customer and station stage updates. External FCM/APNs is configured separately in [NOTIFICATIONS.md](NOTIFICATIONS.md); no exact coordinates or confirmation codes are pushed. GPS/permission/push failures never advance a booking stage automatically.

Migration/restore, concurrency and unauthorized transition tests run against disposable MariaDB. `tests/logistics.php` covers scoped reads/writes, stale/duplicate/invalid samples, stage ordering, handoff proof/attempt persistence, owner confirmation, terminal shutdown and retention. Existing booking/API/security/financial regression was adapted to perform real handoffs instead of bypassing the stronger policy. Exact executed results and remaining provider/native limitations are in [NOTIFICATION_TEST_RESULTS.md](NOTIFICATION_TEST_RESULTS.md).
