# CLEVO account deletion and legal publication

Prepared 2026-11-22. Legal operator/contact/retention periods have not been supplied or approved. `general/privacy.php` and existing-layout `general/terms.php` show a draft banner and HTTP503 until legal approval. Incorrect old terms that claimed automatic wallet deduction on acceptance were corrected. Support ticket functionality remains at `general/contact.php` and in-app Support; a real support mailbox still needs approval. These technical drafts are not a Jordan legal compliance certification.

## Workflow and API

| Method/path under `/api/v1` | Authorization / result |
| --- | --- |
| GET legal | Public metadata and same-origin policy/support URLs; no secrets |
| GET me/account-deletion-request | Full authenticated account; self only |
| POST me/account-deletion-request | Password + JSON `confirm:true`, stable Idempotency-Key; 202 pending request |
| POST me/account-deletion-request/cancel | Same reauthentication/confirmation; only before processing |
| GET admin/account-deletion-requests | Admin-only bounded/paginated queue |
| POST admin/account-deletion-requests/{id}/fulfill | Admin password, review_reference, retention_reviewed:true, external_cleanup_reviewed:true; gated policy and worker drain |

Example request body: `{"password":"<current password supplied privately>","confirm":true}`. Never log the body/password; no real password belongs in this document. GET returns `data.request` (null or RequestID/State/timestamps/policy); POST requests return the same request object. The standard response envelope/errors apply: 401 failed reauthentication, 403 unauthorized role/task session, 409 pending obligations/state/worker conflict, 422 invalid/false confirmation, 429 rate limit, 503 disabled/unapproved policy. OpenAPI and endpoint inventory are regenerated from the registry.

Web `general/delete-account.php` supports an independent request using login+password without installing the app, with CSRF, IP/identity throttling and self identity derived on the server. Authenticated users never supply a target user ID. Flutter Profile → Privacy and account deletion provides policy links, confirmation, password request and status/cancel UI for all supported roles. Task-scoped driver codes cannot delete an account; use full credentials/recovery. Admin self-erasure needs a separate operator succession review.

State machine: requested → cancelled or processing → completed; cancelled → requested. Completion is not reported when only a request or disabled account exists. Fulfillment rechecks Admin role/password under lock, requires approved retention text/version and `ERASURE_WORKER_FENCE=1`, rejects active bookings, unresolved payment/refund/subscription states, unreviewed owner stations, active outbound leases and unsafe/shared legacy media. Operator must stop/drain **all** worker nodes before setting the fence consistently; a flag alone cannot stop an already running external delivery.

The transaction revokes sessions/tokens/devices, pseudonymizes the account, clears profile/contact/addresses, covered personal notifications/support/review content, queued mail secrets and customer booking location. A dedicated worker deletes associated GPS and queued private profile files. New migration 010 keeps a cleanup marker; requests remain processing until location and file work finishes. Runtime DB grants remain append-only for tracking. Cleanup retries are safe and do not delete booking/journal/audit history.

Financial/commercial/dispute/security evidence, archived owner identity/KYC and relational IDs are retained only after a documented policy/legal review. This is **selective erasure, not a blanket deletion of every historical PII field**. Before enabling it commercially, approve per-field retention and expiry, legacy/shared assets, audit/idempotency metadata, document lifecycle and provider erasure. The system cannot erase unidentified external processor copies or old backups by pretending success; external_cleanup_reviewed attests actual documented processor/asset handling. Retention expiry automation for every evidence category remains a release decision, not implemented here. Maintain a restricted erasure register independently of recovery snapshots and replay it on restores.

Publish only after legal operator, privacy/support mailboxes, actual processor list/locations, purpose/legal bases, retention/expiry periods, timeline, complaint/correction/access process and Jordan requirements are approved by the responsible operator/legal adviser. Fill both language retention descriptions and a version; do not set approval flags just to turn a checklist green. Real devices must verify links and completion before store submission.
