#!/bin/sh
# Lightweight cron-safe wrapper for the Lightspeed queue worker.
# - Ensures single instance via flock
# - Short runtime per invocation (respects vend_queue_runtime_business)
# - Logs to assets/services/queue/logs/worker.log

APP_ROOT="/home/master/applications/jcepnzzkmj/public_html/assets/services/queue"
PHP_BIN="php"
LOCK_FILE="$APP_ROOT/logs/worker.lock"
LOG_FILE="$APP_ROOT/logs/worker.log"
RUNNER="$APP_ROOT/bin/run-jobs.php"

# Ensure log dir exists
mkdir -p "$APP_ROOT/logs"

# Acquire non-blocking lock (exit quietly if already running)
if command -v flock >/dev/null 2>&1; then
  exec flock -n "$LOCK_FILE" $PHP_BIN "$RUNNER" --continuous --limit=500 >> "$LOG_FILE" 2>&1
else
  # Fallback without flock: check a PID file
  PIDFILE="$APP_ROOT/logs/worker.pid"
  if [ -f "$PIDFILE" ] && kill -0 $(cat "$PIDFILE") 2>/dev/null; then
    exit 0
  fi
  echo $$ > "$PIDFILE"
  trap 'rm -f "$PIDFILE"' EXIT INT TERM
  $PHP_BIN "$RUNNER" --continuous --limit=500 >> "$LOG_FILE" 2>&1
fi
