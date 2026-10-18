#!/usr/bin/env bash
#
# teardown_test_env.sh - stop (and optionally wipe) the test stack created by
#                        bootstrap_test_env.sh.
#
# Usage:
#   ./test_scripts/teardown_test_env.sh [--root DIR] [--wipe]
#
#   (no flags)  Stop the Nextcloud server and database, keep files for a fast restart
#   --wipe      Also delete $KOREADER_TESTENV (downloads, PHP, Nextcloud, DB data)
#
set -euo pipefail

ROOT="${KOREADER_TESTENV:-$HOME/.koreader-testenv}"
WIPE=false
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --root) ROOT="$2"; shift 2 ;;
    --wipe) WIPE=true; shift ;;
    -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

RUN_DIR="$ROOT/run"
MARKER=".koreader-testenv"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2; exit 1; }

# Absolute, symlink-resolved path. Walks up past missing parents so a
# not-yet-created ROOT still resolves against its real ancestor.
canon() {
  local p="$1" rest=""
  [[ "$p" != /* ]] && p="$PWD/$p"
  while [[ ! -d "$p" && "$p" != "/" ]]; do
    rest="/$(basename "$p")$rest"
    p="$(dirname "$p")"
  done
  if [[ -d "$p" ]]; then
    echo "$(cd "$p" && pwd -P)$rest"
  fi
}

stop_pidfile() { # stop_pidfile <label> <pidfile>
  local label="$1" file="$2" pid
  if [[ ! -f "$file" ]]; then
    return 0
  fi
  pid="$(cat "$file" 2>/dev/null || true)"
  if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
    log "stopping $label (pid $pid)"
    kill "$pid" 2>/dev/null || true
    for _ in $(seq 1 15); do
      kill -0 "$pid" 2>/dev/null || break
      sleep 1
    done
    if kill -0 "$pid" 2>/dev/null; then
      warn "$label did not stop; sending SIGKILL"
      kill -9 "$pid" 2>/dev/null || true
    fi
  else
    log "$label not running"
  fi
  rm -f "$file"
}

log "tearing down test environment at $ROOT"
stop_pidfile "Nextcloud server" "$RUN_DIR/nc-server.pid"
stop_pidfile "database"         "$RUN_DIR/db.pid"
stop_pidfile "mariadb"          "$RUN_DIR/mariadb.pid"
stop_pidfile "mysql"            "$RUN_DIR/mysql.pid"

if $WIPE; then
  [[ -n "$ROOT" ]] || die "Refusing to wipe an empty path"

  ROOT_CANON="$(canon "$ROOT")"
  REPO_CANON="$(canon "$REPO_DIR")"
  HOME_CANON="$(canon "$HOME")"

  [[ -n "$ROOT_CANON" ]] || die "Could not resolve '$ROOT'"
  case "$ROOT_CANON" in
    "/"|"$HOME"|"$HOME_CANON") die "Refusing to wipe unsafe path: '$ROOT_CANON'" ;;
  esac
  [[ -f "$ROOT/$MARKER" ]] || die "Refusing to wipe '$ROOT_CANON': marker '$MARKER' is missing (not a bootstrap_test_env.sh root). Remove it manually if you are sure."
  if [[ "$REPO_CANON" == "$ROOT_CANON" || "$REPO_CANON" == "$ROOT_CANON"/* ]]; then
    die "Refusing to wipe '$ROOT_CANON': it contains the repository ($REPO_CANON)"
  fi
  if [[ "$ROOT_CANON" == "$REPO_CANON" || "$ROOT_CANON" == "$REPO_CANON"/* ]]; then
    die "Refusing to wipe '$ROOT_CANON': it is inside the repository ($REPO_CANON)"
  fi

  # Kill any leftover processes clearly rooted in this environment.
  if command -v pkill >/dev/null 2>&1; then
    pkill -f "php .*-S 127\.0\.0\.1:.* -t $ROOT" 2>/dev/null || true
    pkill -f "$ROOT/db/bin/mariadbd" 2>/dev/null || true
    pkill -f "$ROOT/mysql/bin/mysqld" 2>/dev/null || true
  fi

  log "removing $ROOT"
  rm -rf "$ROOT"
  log "wiped"
else
  log "stopped (files kept; restart with bootstrap_test_env.sh)"
fi