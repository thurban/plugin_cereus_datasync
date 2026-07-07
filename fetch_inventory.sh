#!/usr/bin/env bash
# Copies the newest file from SOURCE_DIR to DEST_DIR, stripping spaces from the filename.
set -euo pipefail

SOURCE_DIR="/share/solarwinds/Network Device Inventory"
DEST_DIR="/var/www/html/cacti/plugins/cereus_datasync/inventory"

# Find the newest regular file in SOURCE_DIR (not recursive)
newest=$(find "$SOURCE_DIR" -maxdepth 1 -type f -printf '%T@ %p\n' | sort -rn | head -1 | cut -d' ' -f2-)

if [[ -z "$newest" ]]; then
    echo "ERROR: no files found in: $SOURCE_DIR" >&2
    exit 1
fi

filename=$(basename "$newest")
dest_name="${filename// /_}"   # replace every space with underscore

mkdir -p "$DEST_DIR"

# Skip if already present
if [[ -f "$DEST_DIR/$dest_name" ]]; then
    echo "EXISTS: $DEST_DIR/$dest_name"
    exit 0
fi

cp -- "$newest" "$DEST_DIR/$dest_name"
echo "COPIED: $DEST_DIR/$dest_name"

# Keep only the 5 newest files in DEST_DIR; remove the rest
find "$DEST_DIR" -maxdepth 1 -type f -printf '%T@ %p\n' \
    | sort -rn \
    | tail -n +6 \
    | cut -d' ' -f2- \
    | while IFS= read -r old; do
        rm -- "$old"
        echo "REMOVED: $old"
    done
