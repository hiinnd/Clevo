#!/usr/bin/env bash
# Unactivated Linux reference. No repository init/prune, remote provisioning or restore.
set -euo pipefail
umask 077
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
: "${CLEVO_ENV_FILE:?Private backup environment file required}"
: "${RESTIC_REPOSITORY:?Approved encrypted repository required}"
: "${RESTIC_PASSWORD_FILE:?Private repository password file required}"
command -v restic >/dev/null
# PHP reads dotenv; never source an application environment file into a shell.
metadata="$(php -d display_errors=0 scripts/backup-database.php --json)"
dump="$(printf '%s' "$metadata" | php deploy/bin/backup-state.php path)"
private="$(php deploy/bin/backup-state.php root)"
paths=("$dump" "$dump.manifest.json")
for part in profiles verification; do
  if [[ -d "$private/$part" ]]; then paths+=("$private/$part"); fi
done
# Explicit paths exclude client passwords, env files, sessions, OTPs and temporary uploads.
restic backup --quiet --tag clevo-production -- "${paths[@]}"
restic check --quiet
printf '%s' "$metadata" | php deploy/bin/backup-state.php success
# Dumps remain private. Retention deletion/prune requires a separately approved policy.
