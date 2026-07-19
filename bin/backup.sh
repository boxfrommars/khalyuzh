#!/usr/bin/env sh

set -eu
umask 077

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
database_path=${1:-"$project_root/storage/records.sqlite"}
backup_directory=${2:-"$project_root/storage/backups"}

if [ ! -f "$database_path" ]; then
    echo "Database not found: $database_path" >&2
    exit 1
fi

mkdir -p "$backup_directory"
timestamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_path="$backup_directory/records-$timestamp.sqlite"

sqlite3 "$database_path" ".backup '$backup_path'"
find "$backup_directory" -type f -name 'records-*.sqlite' -print \
    | sort -r \
    | awk 'NR > 30' \
    | while IFS= read -r old_backup; do
        rm -f -- "$old_backup"
    done

echo "$backup_path"
