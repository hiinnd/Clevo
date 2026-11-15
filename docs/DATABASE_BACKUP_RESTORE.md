# Database backup, migration and recovery runbook

Scope: CLEVO PHP/MySQL, 2026-11-15. No production backup/restore was performed by this phase. The delivered tool's logical backup and restore were exercised on an isolated MariaDB instance. Use a dedicated backup identity and protected environment file; never put passwords on a command line, in Git, in a ticket or in a report.

## Before changing an existing deployment

1. Record application artifact revision, DB engine/version, SQL mode, schema metadata, free disk, table sizes, timezone and replication topology. Use `php scripts/database-inventory.php` through protected `CLEVO_ENV_FILE`. Save metadata outside the web root. Do not infer the live schema from the development dump.
2. Establish a maintenance window from a production-sized rehearsal. Stop HTTP writers, queue/mail workers, expiry jobs and administrative SQL. Retain a reversible traffic-switch procedure. `--maintenance` does not stop writers for you.
3. Produce a fresh verified backup, including database, uploaded/private files and the configuration/key references needed to recover encrypted queued mail. Keep key material in the secret manager, separately from the dump. Do not package `.env` with source or dump it into logs.
4. Restore that backup into an isolated protected instance with a different database name and no outbound mail/payment/notification side effects. Test preflight, migrations, role journeys, financial reconciliation and recovery there first. A production copy contains personal/financial data; restrict access and anonymize it for broader development use.
5. Resolve **counts** reported by preflight through reviewed, traceable reconciliation. Do not delete duplicate wallets, guess balances, rename historical order references blindly, discard orphan records or reassign historical drivers just to satisfy constraints. Take another backup after approved reconciliation.

Commands (configure `CLEVO_ENV_FILE` using the hosting secret process):

```text
php scripts/database-inventory.php
php scripts/migrate-database.php
php scripts/backup-database.php
php scripts/migrate-security.php
php scripts/migrate-database.php --apply --maintenance
```

001 is required first; if it is already applied with the correct checksum it is a no-op. Do not use `initialize-database.php`, `clevo.sql` or development seeds on an existing deployment. For a provisioned **empty** database only: `php scripts/initialize-database.php --empty-only`, followed by 001/002/003.

## Backup tool

`scripts/backup-database.php` uses the configured `MYSQLDUMP_BINARY` (default `mysqldump`). It creates a random-named protected client option file under `PRIVATE_STORAGE_PATH/backups`, never passes a password in process arguments, removes the option file in `finally`, and writes a uniquely named dump plus SHA-256/size/UTC-time/engine manifest. Default output is private and outside the document root in production. It refuses non-InnoDB tables because its consistent snapshot would not cover them.

Dump settings: single transaction, quick streaming, no table locks/add-locks, binary hex data, utf8mb4, UTC, routines/events/triggers, no create-database directive, no add-drop-table directive. DDL must remain frozen throughout the snapshot. The backup identity needs the privileges required by its exact MySQL/MariaDB client version for tables, views/routines/events/triggers; validate those privileges on staging. Use a matching supported client/server combination. The script does not certify privileged routines/definers across hosts.

Private directories must have restrictive inherited Windows ACLs or POSIX ownership/modes. PHP `chmod` alone does not establish Windows ACL isolation. Never serve the private root through an Alias, expose backups via support downloads or commit them. Failed dumps are retained as **partial artifacts without a successful manifest**; never restore them.

Encrypt backups at rest and in transit using the deployment backup system, transfer a verified copy to separate/offsite protected storage, and monitor failures/age. The PHP script does not configure encryption, retention, offsite transport or binary-log/PITR infrastructure. Define RPO/RTO with operations; an example target of 15-minute RPO/60-minute RTO needs binlog archiving and timed recovery drills rather than daily logical dumps alone. Do not delete historical records to implement backup retention.

## Disposable restore tool and validation

```text
php scripts/restore-disposable.php <absolute-protected-backup.sql>
```

The target must already exist and be empty; environment must be `testing`, DB host exactly `127.0.0.1`, and database name match `clevo_restore_test_` plus 12 lowercase hex characters. The input must resolve within the configured private `backups` directory and match its manifest SHA-256. The tool refuses database-switching directives and nonempty/nonmatching targets. It uses `MYSQL_BINARY` (default `mysql`) and a short-lived protected client option file. No production restore switch is provided.

After restore, verify schema/FKs/indexes and migration checksums, count **every** table, compare stable ordered row hashes and monetary totals, verify wallet ownership/ledger references, plan entitlements, service snapshots, assignments and protected-file references. Test all roles, idempotent retries, one payment/refund and session revocation without provider side effects. Test the recovered PHP artifact with the recovered secret references and real production-like SQL modes. Successful command exit alone is not a restore drill.

The automated rehearsal creates a new empty target, restores the real tool-produced dump, and compares every pre-existing column value in every source table. A second restore into the populated target is refused. Tests leave their isolated databases for inspection and never drop/reset a real database.

## Rollback and partial DDL

There is **no destructive down migration**. Expanded tables/columns/indexes are retained. MySQL/MariaDB DDL is not an application transaction; do not issue ROLLBACK and assume an ALTER was undone.

- Failure before DDL: keep traffic stopped, inspect preflight counts and fix the documented cause on another restored copy.
- `started` step without `applied`: the DDL may or may not exist. Compare exact SQL, actual columns/indexes/FKs and checksum. An operator may mark the step applied only after proving the expected definition exists. If absent, execute/reconcile that one reviewed statement under maintenance, then record it. Do not remove the journal or rerun all statements blindly.
- Application regression after a completed expansion: keep the expanded schema and switch to a reviewed compatible artifact. Retain history-preserving archive actions, safe payment behavior and restricted grants. Returning to an old artifact with hard-delete catalog actions is not an acceptable rollback. Never delete payment/idempotency/effect receipts to make retries work.
- Corruption or an unrecoverable migration: preserve the failed instance read-only for investigation. Restore the **pre-migration backup to a new instance**, replay validated binlogs to the chosen recovery point if available, reconcile any accepted post-backup bookings/payments/provider events, then validate and switch traffic. Do not overwrite the only production copy or erase post-backup financial activity.

A real production restoration needs an incident owner, an explicitly chosen recovery point, provider/bank reconciliation and a reviewed traffic cutover. The supplied test restore command cannot perform that cutover.

## Development seeds

`database/seeds/development.sql` contains the original synthetic marketplace snapshot with disabled login credentials and six intentionally retained assignment anomalies. Keep it separate from migrations, backups and production provisioning. It has cyclic user/station references: the tests create the original tables, load fixtures, and add the missing user/station relationship through 003 after validating references. Use that isolated harness (`tests/run-isolated.ps1`) to reproduce it; do not disable production foreign keys or import the fixture over real data.
