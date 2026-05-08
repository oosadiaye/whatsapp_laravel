#!/bin/bash
# Install / refresh the BlastIQ Reverb daemon as a supervisord program.
#
# Idempotent — safe to re-run after deploys or config changes.
#
# Usage (from project root, as root or with sudo):
#   sudo bash deploy/install-reverb.sh

set -euo pipefail

if [ -d /etc/supervisord.d ]; then
    SUPERVISOR_DIR=/etc/supervisord.d
    SUPERVISOR_EXT=ini
elif [ -d /etc/supervisor/conf.d ]; then
    SUPERVISOR_DIR=/etc/supervisor/conf.d
    SUPERVISOR_EXT=conf
else
    echo "ERROR: neither /etc/supervisord.d nor /etc/supervisor/conf.d exists." >&2
    exit 1
fi

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
PROJECT_PATH=$(cd "$SCRIPT_DIR/.." && pwd)
SOURCE_CONF="$SCRIPT_DIR/supervisor-reverb.conf"
TARGET_CONF="$SUPERVISOR_DIR/blastiq-reverb.$SUPERVISOR_EXT"

if [ ! -f "$SOURCE_CONF" ]; then
    echo "ERROR: $SOURCE_CONF not found." >&2
    exit 1
fi

RUN_AS_USER=$(stat -c '%U' "$PROJECT_PATH/storage" 2>/dev/null || stat -f '%Su' "$PROJECT_PATH/storage" 2>/dev/null || whoami)

echo "=== BlastIQ Reverb Install ==="
echo "  Project path:    $PROJECT_PATH"
echo "  Run as user:     $RUN_AS_USER"
echo "  Source config:   $SOURCE_CONF"
echo "  Target config:   $TARGET_CONF"
echo ""

echo "[1/4] Rendering config to $TARGET_CONF..."
sed -e "s|__PROJECT_PATH__|$PROJECT_PATH|g" \
    -e "s|__RUN_AS_USER__|$RUN_AS_USER|g" \
    "$SOURCE_CONF" > "$TARGET_CONF"

echo "[2/4] Ensuring storage/logs/ exists..."
mkdir -p "$PROJECT_PATH/storage/logs"
chown "$RUN_AS_USER":"$RUN_AS_USER" "$PROJECT_PATH/storage/logs" 2>/dev/null || true

echo "[3/4] supervisorctl reread + update..."
supervisorctl reread
supervisorctl update

echo "[4/4] Starting (or restarting) blastiq-reverb..."
supervisorctl start blastiq-reverb 2>/dev/null || supervisorctl restart blastiq-reverb

echo ""
echo "=== Done ==="
echo "Status:"
supervisorctl status blastiq-reverb
echo ""
echo "Tail the log:  tail -f $PROJECT_PATH/storage/logs/reverb.log"
