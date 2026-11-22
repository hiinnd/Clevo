# CLEVO operations and monitoring

Prepared 2026-11-22; reference files remain unactivated. See [DEPLOYMENT.md](DEPLOYMENT.md) for environments/least privilege and [PRODUCTION_READINESS.md](PRODUCTION_READINESS.md) for blockers.

## Checks and scheduling

| Surface | Behavior |
| --- | --- |
| `GET /health/live.php` | Minimal PHP process liveness; no DB/credentials or configuration details |
| `GET /health/ready.php` | Independent OPS_HEALTH_TOKEN bearer; DB SELECT, migrations 001–010, storage, maintenance and configured worker/backup freshness; 200/503 minimal status |
| `GET /health/metrics.php` | Same bearer plus private-network Apache restriction; numeric readiness, queue/oldest age, mail due, worker last-success ages/exit codes and backup age |
| `php scripts/check-health.php` | CLI readiness details with safe check names/counts; exit 0/1 |
| `php scripts/production-preflight.php` | Redacted configuration/review gates; no infrastructure activation and no proof of live delivery |

Health bearer is 64 random lowercase hex characters, independent of user JWT and encryption keys. Supply it in Authorization only, with TLS and restricted private monitoring ingress. Query parameters are not credentials. Do not put token values in URLs, access logs or Prometheus config tracked by Git. A CA-valid hostname and credentials_file belong in the private installed Prometheus configuration. If readiness/metrics returns 503, Prometheus also reports scrape failure; both up=0 and readiness=0 alerts are intentional.

The runner permits only fixed jobs and holds a per-job filesystem lock. Atomic heartbeat files retain last successful completion even on failure. A fresh running job with previous success is healthy for at most 120 seconds; hung/failed/stale jobs are unhealthy. systemd enforces a 120-second control-group timeout; re-evaluate batch sizes after measurement. One machine must own each scheduled job. Filesystem locks do not coordinate independent hosts; multiple app nodes require a single scheduler or a DB/distributed lease design before enabling another scheduler.

| Job | Service | Proposed interval / privilege |
| --- | --- | --- |
| notification-outbox | booking-notification-worker.php | 60 s; inbox/outbox delivery records only |
| notification-delivery | notification-delivery-worker.php | 60 s; outbound delivery/devices, business reads |
| security-mail | security-mail-worker.php | 60 s; encrypted mail + bounded auth/rate cleanup |
| booking-expiry | expire-bookings.php | 60 s; shared booking service and history/outbox |
| tracking-retention | purge-tracking.php --apply | 60 s; dedicated GPS DELETE identity |
| payment-report | reconcile-payments.php | Reference timer 60 s; may use separate 5-minute override; **no --query-provider** and no charge/refund issuance |
| privacy-files | process-account-erasure-files.php --apply | Reviewed requests only; dedicated GPS DELETE and file-cleanup identity; gated policy |

Use separate `/etc/clevo/workers/<job>.env` files. Existing service bootstraps may require full application config even for a narrow DB identity; mail/Firebase private files belong only to outbound workers that need them. The privacy cleanup CLI uses DB-only validation and reviewed legal metadata; it needs no JWT/SMTP/encryption key. Test actual grants rather than widening privileges until errors disappear. SQL examples are a starting point, not certification of a new MySQL host. Runtime cannot rewrite audit/history/journal records or delete GPS; a migration identity must never run FPM.

## Logging and alerts

PHP errors remain disabled in responses, exception arguments hidden, logs on. Apache example logs method and **path only**, status, bytes and elapsed microseconds. Never add raw query strings, request bodies, Cookie/Authorization, passwords, OTPs, payment payloads, coordinates or uploaded documents. Service logs report allowlisted job name, exit and UTC time; child output is suppressed by the runner. Use journal forwarding with restricted retention and no personal content. Configure disk/free-inode, CPU, memory, PHP-FPM saturation, DB connection/lock/disk, TLS expiry/renewal failure and backup object-store availability monitoring at host/provider level; app counters do not replace it.

`deploy/monitoring/` supplies private Prometheus scrape, readiness/backlog/backup alert and logrotate references. Test routing to a real approved operator channel without customer data. No alert recipient or third-party monitoring account has been configured. Full APM/trace export is not implemented; do not send HTTP bodies/identity headers to an error service by default. Review logrotate ownership/service names for the selected OS and FPM pool before installing it.

## SMTP, Firebase and payments

SMTP: provision approved transactional sender domain, STARTTLS/TLS provider access, least-privilege SMTP credentials, SPF and DKIM, DMARC alignment/reporting with an approved mailbox, bounce/complaint suppression and quotas. Confirm records and received-message Authentication-Results using test mailboxes; no SPF/DKIM/DMARC record was installed. Follow [Google sender guidance](https://support.google.com/mail/answer/81126), and use the selected provider's actual DNS instructions, not invented values.

FCM: separate staging/production Firebase projects; enable HTTP v1 API and a backend identity with only required send permission. Keep service-account JSON outside every public root and Git, set FCM_EXPECTED_PROJECT_ID, and validate project mismatch rejection. Configure Android app ID/signing fingerprints, iOS bundle/APNs credential in Firebase, provisioning entitlement and device permission prompts. Flutter client config identifies a project and is not a server credential; Maps keys must be package/bundle/signing restricted. Test exact approved devices, token refresh/revocation, language/deep links and failure retry before enabling external send. [FCM server authorization](https://firebase.google.com/docs/cloud-messaging/auth-server).

Production provider approvals are missing. Online payment processing remains hard-blocked by backend production guards. Keep PAYMENT_PROVIDER/PAYMENT_MODE disabled; do not copy sandbox credentials into production or change flags to imply commercial approval. Cash uses the existing separate attested ledger workflow. Stored-value wallet issuance and automated settlements remain blocked pending legal/provider scope and testing. See [PAYMENT_INTEGRATION.md](PAYMENT_INTEGRATION.md).
