#!/bin/bash
# Install / refresh the BlastIQ queue worker as a supervisord program.
#
# Idempotent — safe to re-run. On second+ runs it just refreshes the config
# and restarts the worker.
#
# Usage (from project root, as root or with sudo):
#   sudo bash deploy/install-supervisor.sh
#
# What it does:
#   1. Detects current project path (the directory containing this script's parent)
#   2. Detects the user that owns storage/ (matches PHP-FPM pool user on most setups)
#   3. Substitutes __PROJECT_PATH__ and __RUN_AS_USER__ in supervisor-worker.conf
#   4. Writes the result to /etc/supervisord.d/blastiq-worker.ini
#   5. Kills any stale `php artisan queue:work` processes (left over from
#      pre-supervisor manual launches — they hold OLD code in memory and
#      are the #1 reason "I deployed but nothing changed" happens)
#   6. supervisorctl reread + update + start
#
# Prerequisites:
#   - supervisord installed and running:
#       sudo systemctl start supervisord && sudo systemctl enable supervisord
#   - /etc/supervisord.d/ exists and is included from /etc/supervisord.conf
#     (default on RHEL/CentOS/Alma; on Debian/Ubuntu the path is /etc/supervisor/conf.d/)

set -euo pipefail

# Detect platform's supervisor include directory
if [ -d /etc/supervisord.d ]; then
    SUPERVISOR_DIR=/etc/supervisord.d
    SUPERVISOR_EXT=ini
elif [ -d /etc/supervisor/conf.d ]; then
    SUPERVISOR_DIR=/etc/supervisor/conf.d
    SUPERVISOR_EXT=conf
else
    echo "ERROR: neither /etc/supervisord.d nor /etc/supervisor/conf.d exists." >&2
    echo "Install supervisor first: yum install supervisor   OR   apt-get install supervisor" >&2
    exit 1
fi

# Resolve project root from the script's own location
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
PROJECT_PATH=$(cd "$SCRIPT_DIR/.." && pwd)
SOURCE_CONF="$SCRIPT_DIR/supervisor-worker.conf"
TARGET_CONF="$SUPERVISOR_DIR/blastiq-worker.$SUPERVISOR_EXT"

if [ ! -f "$SOURCE_CONF" ]; then
    echo "ERROR: $SOURCE_CONF not found." >&2
    exit 1
fi

# Detect the user that owns storage/. Falls back to current user. Same logic
# as deploy.sh — runs the worker as whoever already writes to storage/, which
# matches PHP-FPM in cPanel/Plesk-style per-user pools and matches www-data
# in shared-pool setups.
RUN_AS_USER=$(stat -c '%U' "$PROJECT_PATH/storage" 2>/dev/null || stat -f '%Su' "$PROJECT_PATH/storage" 2>/dev/null || whoami)

echo "=== BlastIQ Supervisor Worker Install ==="
echo "  Project path:    $PROJECT_PATH"
echo "  Run as user:     $RUN_AS_USER"
echo "  Source config:   $SOURCE_CONF"
echo "  Target config:   $TARGET_CONF"
echo ""

# Step 1: render the config with substitutions
echo "[1/5] Rendering config to $TARGET_CONF..."
sed -e "s|__PROJECT_PATH__|$PROJECT_PATH|g" \
    -e "s|__RUN_AS_USER__|$RUN_AS_USER|g" \
    "$SOURCE_CONF" > "$TARGET_CONF"

# Step 2: ensure log directory exists and is writable by the run-as user
echo "[2/5] Ensuring storage/logs/ exists and is writable..."
mkdir -p "$PROJECT_PATH/storage/logs"
chown "$RUN_AS_USER":"$RUN_AS_USER" "$PROJECT_PATH/storage/logs" 2>/dev/null || true

# Step 3: kill any stale queue:work processes from pre-supervisor launches.
# These are the #1 cause of "I deployed but nothing changed" — they hold the
# old service classes in memory forever. We DO NOT touch supervisor-managed
# workers here (they get cleanly restarted in step 5 instead).
echo "[3/5] Killing any stale (non-supervised) queue:work processes..."
# pgrep returns non-zero if no match, so guard with || true
STALE_PIDS=$(pgrep -f "artisan queue:work" 2>/dev/null || true)
if [ -n "$STALE_PIDS" ]; then
    # Filter out anything that's a child of supervisord (PPID == supervisord's PID)
    # so we never kill a properly-supervised worker.
    SUPERVISORD_PID=$(pgrep -x supervisord | head -1 || echo "")
    for pid in $STALE_PIDS; do
        if [ -n "$SUPERVISORD_PID" ]; then
            PPID_OF=$(ps -o ppid= -p "$pid" 2>/dev/null | tr -d ' ' || echo "")
            if [ "$PPID_OF" = "$SUPERVISORD_PID" ]; then
                echo "  Skipping supervised PID $pid (parent is supervisord)"
                continue
            fi
        fi
        echo "  Killing stale PID $pid"
        kill "$pid" 2>/dev/null || true
    done
    sleep 2
    # Force-kill any holdouts
    for pid in $STALE_PIDS; do
        if kill -0 "$pid" 2>/dev/null; then
            echo "  Force-killing stubborn PID $pid"
            kill -9 "$pid" 2>/dev/null || true
        fi
    done
else
    echo "  No stale workers found."
fi

# Step 4: tell supervisor to reread + register/update the program
echo "[4/5] supervisorctl reread + update..."
supervisorctl reread
supervisorctl update

# Step 5: ensure it's running
echo "[5/5] Starting (or restarting) blastiq-worker..."
# `start` is a no-op if already running, `restart` errors if not running yet.
# This sequence handles both first-install and subsequent runs.
supervisorctl start "blastiq-worker:*" 2>/dev/null || supervisorctl restart "blastiq-worker:*"

echo ""
echo "=== Done ==="
echo "Status:"
supervisorctl status "blastiq-worker:*"
echo ""
echo "Tail the log:  tail -f $PROJECT_PATH/storage/logs/worker.log"
