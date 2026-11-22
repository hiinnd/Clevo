# CLEVO deployment and rollback

Prepared 2026-11-22. **Reference configuration, not an executed deployment.** The operator confirmed that no production hosting or domains are approved. No DNS, certificates, infrastructure, live credentials or stores were changed.

## Hosting decision

Local XAMPP is a development environment. Its observed PHP 8.2.12, MariaDB 10.4.32, Apache 2.4.58 and OpenSSL 3.0.11 are not the deployment baseline. No existing commercial host has been demonstrated sufficient. Recommend a managed VPS or equivalent managed service that supports all capabilities below; ordinary shared hosting is sufficient only if the provider proves every capability and the staging tests pass.

| Capability | Required evidence |
| --- | --- |
| Patched supported PHP, mysqli/mysqlnd, sodium, openssl, curl, fileinfo, mbstring, CLI/FPM | Same tested build/configuration for API, web and workers; `composer check-platform-reqs --no-dev` |
| MySQL/InnoDB, strict mode, utf8mb4, UTC, constraints/locking | Disposable clone migrations, financial and concurrency suites; restricted identities |
| Scheduled PHP jobs, process limits, protected writable storage outside all web roots | Actual heartbeat/queue tests; no worker invoked by public URL |
| HTTPS, renewals, exact virtual host, firewall, outbound HTTPS/SMTP | TLS and renewal rehearsal, no public MySQL, no untrusted proxy headers |
| Encrypted independent backups and recoverability | Restore to a fresh target with counts/balances and file hashes |
| Logs, monitoring, alerts, deployment SSH/CI controls | Alert receipt by approved operators and documented rollback |

A candidate reference stack is patched PHP 8.4 FPM, Apache 2.4 and MySQL 8.4 LTS on a supported Linux distribution. These versions are **not verified by the Windows/MariaDB test environment**. PHP 8.4 receives upstream security support through 2028-12-31; use its current patched release, not an initial 8.4 binary. [PHP support schedule](https://www.php.net/supported-versions.php).

An initial staging sizing hypothesis is 2 vCPU, 4 GiB RAM, SSD with monitored free space and encrypted backup storage in another failure domain. It is not a capacity guarantee or a procurement approval. Benchmark PHP-FPM pool size (reference max_children=8), DB memory, GPS ingestion, disk and worker throughput with realistic data before committing a production size. A single VPS has a single point of failure; managed DB/replica/PITR and additional app nodes are later availability decisions, not implied by these templates.

## Environments and filesystem

Keep development, staging and production databases, DB users, encryption/JWT keys and issuer/audience, Firebase projects, Maps restrictions, SMTP recipients, object repositories and storage separate. Never restore real customer data into developer machines. Use synthetic staging accounts. Staging enforces HTTPS, secure cookies, non-root credentials and private storage; external mail additionally requires an exact recipient allowlist. External delivery defaults off.

Proposed layout for each environment:

```text
/srv/clevo-production/releases/<reviewed-revision>/  # immutable application + vendor
/srv/clevo-production/current                     # controlled release symlink
/srv/clevo-production/private/{profiles,verification,tmp,sessions,ops,backups}
/etc/clevo/{production.env,backup.env,workers/,secrets/,tls/}
/var/log/clevo/
```

Repository contents are not a production web-root allowlist. Deployment must exclude `.git`, `.env*`, `.security-test`, `.mobile-tools`, `mobile`, `tests`, development seeds, dumps and signing files from public access. Retain `.htaccess` protections, migration/CLI files inaccessible over HTTP, dependencies protected by deny rules, and reviewed static assets. Keep `deploy/bin/backup.sh` available to the private service. Release directories are owned by the deployment identity, read-only to FPM. Private directories are 0700 or reviewed shared-group/ACL equivalents; secret files 0600/0640. The reference backup unit uses the trusted application OS identity to read existing 0600 uploads, a separate SELECT-only DB identity, and service-level write restrictions to backups/ops/cache. It does not claim OS isolation from the app. A separately isolated backup agent must be designed/tested with the hosting provider; directory ACLs alone cannot grant access to existing 0600 files. Do not use world-writable permissions.

`deploy/env/*.env.example`, `deploy/apache-production.conf.example`, `deploy/php-fpm.conf.example`, `deploy/php-security.ini.example` are unactivated references. Copy values to private files through a secret manager; templates intentionally fail validation. Dotenv files are **not shell scripts**. Keep the environment pointer in the FPM pool; PHP CLI/systemd needs the corresponding pointer separately. Keep APP_KEY stable across normal deployments so encrypted queues/devices remain readable; credential rotation is a separate approved procedure.

## Domain and TLS activation plan

After explicit infrastructure approval: reserve approved hostnames; restrict SSH to operators and MySQL to loopback/private app addresses; install a CA-issued certificate and automated renewal; validate `apachectl -t`, `php-fpm8.4 -t` and effective PHP configuration. Enable only TLS 1.2/1.3; redirect HTTP to the fixed approved HTTPS host. HSTS is present without preload/includeSubDomains; assess subdomains before expanding it. Do not disable certificate verification for HTTPS, DB or monitoring. TLS terminators must use exact TRUSTED_PROXIES and strip spoofed forwarding headers. No domain in this repository is an approved production hostname.

Validate denied private/config/vendor/backup URLs on the real virtual host, direct-IP/default-vhost behavior, upload size limits, secure cookies, bearer forwarding, CORS exact web origins and certificate renewal. Native Flutter clients do not need a permissive CORS origin. Existing inline/CDN resources require a tested CSP rollout; no restrictive CSP has been invented that would silently break the PHP dashboard.

## Deployment sequence (requires approval before execution outside disposable tests)

1. Review a committed revision containing all intended changes. Build Composer vendor from the lock with `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader`; run audits and platform checks in the target runtime. Store a manifest of revision, migration checksums, dependency locks and artifact SHA-256. Never run an unrevised `git pull` in a live web root.
2. Provision private configuration, least-privilege accounts and protected storage in approved staging. Keep outbound disabled, payments disabled and legal flags unapproved until their respective reviews. Validate the configuration with `php scripts/production-preflight.php`; false checks are blockers, not values to blindly set to 1.
3. On a disposable clone, run migration read-only preflight, then the apply path with writers stopped. Fresh empty DB only: `initialize-database.php --empty-only`, `migrate-security.php`, `migrate-database.php --apply --maintenance`. Existing DB: preserve its records; never initialize it. Migrations 009/010 add erasure requests, mail ownership and cleanup completion. Existing migration checksums must not be edited.
4. Run regression, concurrency, restricted-user, financial, backup/restore and new operational/privacy tests. Exercise real staging SMTP/FCM/APNs, maps and provider sandbox only with authorized test recipients/devices and credentials. Current local transport doubles are not delivery certification.
5. For an approved production window: stop/drain all writers, workers, callbacks and scheduled jobs; serve maintenance at the edge as well as APP maintenance. Take and verify the encrypted pre-change backup. Apply only rehearsed forward migrations with a separate migration identity. MySQL DDL auto-commits; a PHP transaction cannot roll it back. Never rely on the `--maintenance` acknowledgement itself to stop other writers.
6. Stage the immutable release, atomically switch `current`, reload FPM to clear OPcache, and run internal health/security/business smoke tests. Start allowlisted workers/timers with per-job profiles, verify last-success heartbeats, backups and alerts, then remove maintenance and monitor error/latency/queue/financial reconciliation metrics. Do not expose an instance whose readiness fails.

## Rollback

Keep the preceding artifact and configuration version privately. On failure, put traffic into maintenance, drain all writers and capture the incident reference/metrics without PII. If the preceding code supports the additive schema, switch to the previous immutable artifact and reload FPM; retain the forward schema and all new data. Re-test login, catalog, booking reads and financial state before traffic. Do not roll back schema with DROP/column removal, restore a dump over a live DB or reverse ledger history.

If code is incompatible or data is damaged, keep maintenance, restore to a **new isolated database/storage target**, validate it, replay reviewed erasure tombstones and reconcile provider/webhook changes after the restore point. A responsible operator must approve any eventual cutover, credential change or data-loss window. See [PRODUCTION_BACKUP_RECOVERY.md](PRODUCTION_BACKUP_RECOVERY.md). The backup/restore tooling never selects a production restore target.
