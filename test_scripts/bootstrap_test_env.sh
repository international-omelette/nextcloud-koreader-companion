#!/usr/bin/env bash
#
# bootstrap_test_env.sh - provision a self-contained KOReader Companion test stack
#                         without Docker and without root.
#
# Downloads a static PHP build, a Nextcloud release, and a database server
# (MariaDB by default) into $KOREADER_TESTENV, installs Nextcloud, deploys this
# app, enables it, seeds a test library, and serves it via PHP's built-in server.
#
# Usage:
#   ./test_scripts/bootstrap_test_env.sh [--db mariadb|mysql|sqlite] [--port 8090]
#                                        [--root DIR] [--reset]
#
# Then:
#   ./test_scripts/test_opds.sh     --base-url "http://127.0.0.1:8090/index.php"
#   ./test_scripts/test_koreader.sh --base-url "http://127.0.0.1:8090/index.php"
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
PHP_VER="${PHP_VER:-8.4.23}"
NC_VER="${NC_VER:-34.0.5}"
MARIADB_VER="${MARIADB_VER:-10.11.10}"
MYSQL_VER="${MYSQL_VER:-8.0.40}"

DB_ENGINE="${DB_ENGINE:-mariadb}"     # mariadb | mysql | sqlite
HTTP_PORT="${HTTP_PORT:-8090}"
DB_PORT="${DB_PORT:-3307}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"
KOREADER_PASS="${KOREADER_PASS:-test123}"
ROOT="${KOREADER_TESTENV:-$HOME/.koreader-testenv}"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
RESET=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --db)     DB_ENGINE="$2"; shift 2 ;;
    --port)   HTTP_PORT="$2"; shift 2 ;;
    --root)   ROOT="$2"; shift 2 ;;
    --reset)  RESET=true; shift ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

DL="$ROOT/downloads"
PHP_DIR="$ROOT/php"
NC_DIR="$ROOT/nextcloud"
DB_DIR="$ROOT/db"
DB_DATA="$ROOT/db-data"
APP_DIR="$ROOT/app"
RUN_DIR="$ROOT/run"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"; }

need curl
need tar

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

# Validate ROOT *before* creating or writing anything into it. teardown trusts
# $ROOT/.koreader-testenv, so the marker must only ever be written to a
# directory this script safely owns.
[[ -n "$ROOT" ]] || die "KOREADER_TESTENV must not be empty"
ROOT_CANON="$(canon "$ROOT")"
[[ -n "$ROOT_CANON" ]] || die "Could not resolve KOREADER_TESTENV: $ROOT"
HOME_CANON="$(canon "$HOME")"
case "$ROOT_CANON" in
  "/"|"$HOME"|"$HOME_CANON") die "KOREADER_TESTENV must not be '$ROOT_CANON'" ;;
esac
if [[ "$REPO_DIR" == "$ROOT_CANON" || "$REPO_DIR" == "$ROOT_CANON"/* ]]; then
  die "KOREADER_TESTENV must not contain the repository ($REPO_DIR): $ROOT_CANON"
fi
if [[ "$ROOT_CANON" == "$REPO_DIR" || "$ROOT_CANON" == "$REPO_DIR"/* ]]; then
  die "KOREADER_TESTENV must not be inside the repository ($REPO_DIR): $ROOT_CANON"
fi

MARKER=".koreader-testenv"
if [[ -e "$ROOT" && ! -d "$ROOT" ]]; then
  die "KOREADER_TESTENV exists and is not a directory: $ROOT"
fi
if [[ -d "$ROOT" ]]; then
  # Only claim a directory that is empty or already ours.
  if [[ ! -f "$ROOT/$MARKER" && -n "$(ls -A "$ROOT" 2>/dev/null)" ]]; then
    die "Refusing to use non-empty directory '$ROOT' without a '$MARKER' marker"
  fi
else
  mkdir -p "$ROOT"
fi
mkdir -p "$DL" "$RUN_DIR"
: > "$ROOT/$MARKER"

stop_pid() { # stop_pid <pidfile>
  [[ -f "$1" ]] || return 0
  local pid; pid="$(cat "$1" 2>/dev/null || true)"
  [[ -n "$pid" ]] || return 0
  kill "$pid" 2>/dev/null || true
  for _ in $(seq 1 10); do kill -0 "$pid" 2>/dev/null || break; sleep 1; done
}

if $RESET; then
  log "Resetting $ROOT"
  stop_pid "$RUN_DIR/nc-server.pid"
  stop_pid "$RUN_DIR/db.pid"
  stop_pid "$RUN_DIR/mariadb.pid"
  stop_pid "$RUN_DIR/mysql.pid"
  rm -rf "$PHP_DIR" "$NC_DIR" "$DB_DIR" "$DB_DATA" "$APP_DIR"
fi

# ---------------------------------------------------------------------------
# Download helper (cached)
# ---------------------------------------------------------------------------
fetch() { # fetch <url> <dest>
  local url="$1" dest="$2"
  if [[ -s "$dest" ]]; then
    log "cached $(basename "$dest")"
    return 0
  fi
  log "downloading $(basename "$dest")"
  curl -fL --retry 3 --max-time 1800 -o "$dest.part" "$url"
  mv "$dest.part" "$dest"
}

# Extract a .tar.bz2 without requiring the bzip2 binary (uses PHP's bz2 ext).
extract_tar_bz2() { # extract_tar_bz2 <archive> <destdir>
  local archive="$1" dest="$2"
  if command -v bzip2 >/dev/null 2>&1; then
    tar -xjf "$archive" -C "$dest"
    return 0
  fi
  log "bzip2 not found; decompressing with PHP"
  "$PHP_BIN" -r '
    $in = bzopen($argv[1], "r"); $out = fopen($argv[2], "wb");
    while (!feof($in)) { fwrite($out, bzread($in, 1048576)); }
    bzclose($in); fclose($out);
  ' "$archive" "$archive.tar"
  tar -xf "$archive.tar" -C "$dest"
  rm -f "$archive.tar"
}

# Extract a .tar.xz using xz if present, otherwise a downloaded static busybox.
extract_tar_xz() { # extract_tar_xz <archive> <destdir>
  local archive="$1" dest="$2"
  if command -v xz >/dev/null 2>&1; then
    tar -xJf "$archive" -C "$dest" --strip-components=1
    return 0
  fi
  local bb="$DL/busybox"
  if [[ ! -x "$bb" ]]; then
    fetch "https://busybox.net/downloads/binaries/1.35.0-x86_64-linux-musl/busybox" "$bb"
    chmod +x "$bb"
  fi
  tar --use-compress-program="$bb xz" -xf "$archive" -C "$dest" --strip-components=1
}

# ---------------------------------------------------------------------------
# 1. Static PHP
# ---------------------------------------------------------------------------
install_php() {
  if [[ -x "$PHP_DIR/php" ]]; then
    log "PHP already present: $("$PHP_DIR/php" -r 'echo PHP_VERSION;')"
    return 0
  fi
  fetch "https://dl.static-php.dev/static-php-cli/common/php-$PHP_VER-cli-linux-x86_64.tar.gz" "$DL/php-$PHP_VER.tar.gz"
  rm -rf "$PHP_DIR"; mkdir -p "$PHP_DIR"
  tar -xzf "$DL/php-$PHP_VER.tar.gz" -C "$PHP_DIR"
  [[ -x "$PHP_DIR/php" ]] || die "Static PHP extraction failed"
  log "PHP $("$PHP_DIR/php" -r 'echo PHP_VERSION;') ready"
}

# Small PHP helper: run SQL as root over the DB socket.
db_sql() { # db_sql <socket> <sql>...
  local sock="$1"; shift
  "$PHP_BIN" -r '
    $pdo = new PDO("mysql:unix_socket=".$argv[1].";charset=utf8mb4", "root", "",
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    for ($i = 2; $i < $argc; $i++) { $pdo->exec($argv[$i]); }
  ' "$sock" "$@"
}

# ---------------------------------------------------------------------------
# 2. Database server
# ---------------------------------------------------------------------------
install_mariadb() {
  local sock="$RUN_DIR/mariadb.sock"
  if [[ ! -x "$DB_DIR/bin/mariadbd" ]]; then
    fetch "https://archive.mariadb.org/mariadb-$MARIADB_VER/bintar-linux-systemd-x86_64/mariadb-$MARIADB_VER-linux-systemd-x86_64.tar.gz" "$DL/mariadb-$MARIADB_VER.tar.gz"
    rm -rf "$DB_DIR"; mkdir -p "$DB_DIR"
    tar -xzf "$DL/mariadb-$MARIADB_VER.tar.gz" -C "$DB_DIR" --strip-components=1
  fi
  if [[ ! -d "$DB_DATA/mysql" ]]; then
    log "initializing MariaDB data dir"
    "$DB_DIR/scripts/mariadb-install-db" --no-defaults --basedir="$DB_DIR" --datadir="$DB_DATA" \
      --auth-root-authentication-method=normal >/dev/null
  fi
  if ! "$DB_DIR/bin/mariadb-admin" --no-defaults --socket="$sock" -u root ping >/dev/null 2>&1; then
    log "starting MariaDB on port $DB_PORT"
    nohup "$DB_DIR/bin/mariadbd" --no-defaults --basedir="$DB_DIR" --datadir="$DB_DATA" \
      --socket="$sock" --pid-file="$RUN_DIR/mariadb.pid" --port="$DB_PORT" \
      --bind-address=127.0.0.1 --skip-name-resolve >"$RUN_DIR/mariadb.log" 2>&1 &
    echo $! > "$RUN_DIR/db.pid"
    for _ in $(seq 1 30); do
      "$DB_DIR/bin/mariadb-admin" --no-defaults --socket="$sock" -u root ping >/dev/null 2>&1 && break
      sleep 1
    done
    "$DB_DIR/bin/mariadb-admin" --no-defaults --socket="$sock" -u root ping >/dev/null 2>&1 \
      || die "MariaDB failed to start (see $RUN_DIR/mariadb.log)"
  fi
  db_sql "$sock" \
    "CREATE DATABASE IF NOT EXISTS nextcloud CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci" \
    "CREATE USER IF NOT EXISTS 'nextcloud'@'127.0.0.1' IDENTIFIED BY 'nextcloud'" \
    "GRANT ALL PRIVILEGES ON nextcloud.* TO 'nextcloud'@'127.0.0.1'" \
    "FLUSH PRIVILEGES"
  DB_HOST="127.0.0.1:$DB_PORT"
}

install_mysql() {
  local sock="$RUN_DIR/mysql.sock"
  local mysql_base="$ROOT/mysql"
  if [[ ! -x "$mysql_base/bin/mysqld" ]]; then
    fetch "https://dev.mysql.com/get/Downloads/MySQL-8.0/mysql-$MYSQL_VER-linux-glibc2.28-x86_64.tar.xz" "$DL/mysql-$MYSQL_VER.tar.xz"
    rm -rf "$mysql_base"; mkdir -p "$mysql_base"
    extract_tar_xz "$DL/mysql-$MYSQL_VER.tar.xz" "$mysql_base"
  fi
  DB_DIR="$mysql_base"
  ldd "$mysql_base/bin/mysqld" 2>/dev/null | grep -q 'not found' && \
    warn "mysqld has missing shared libs (often libaio1/libnuma1). Install them or use --db mariadb."
  export LD_LIBRARY_PATH="$mysql_base/lib/private:${LD_LIBRARY_PATH:-}"
  if [[ ! -d "$DB_DATA/mysql" ]]; then
    log "initializing MySQL data dir"
    "$mysql_base/bin/mysqld" --no-defaults --initialize-insecure --basedir="$mysql_base" --datadir="$DB_DATA" >/dev/null
  fi
  if ! "$mysql_base/bin/mysqladmin" --no-defaults --socket="$sock" -u root ping >/dev/null 2>&1; then
    log "starting MySQL on port $DB_PORT"
    nohup "$mysql_base/bin/mysqld" --no-defaults --basedir="$mysql_base" --datadir="$DB_DATA" \
      --socket="$sock" --pid-file="$RUN_DIR/mysql.pid" --port="$DB_PORT" \
      --bind-address=127.0.0.1 --mysqlx=0 --skip-name-resolve \
      --log-error="$RUN_DIR/mysql.err" >"$RUN_DIR/mysql.log" 2>&1 &
    echo $! > "$RUN_DIR/db.pid"
    for _ in $(seq 1 30); do
      "$mysql_base/bin/mysqladmin" --no-defaults --socket="$sock" -u root ping >/dev/null 2>&1 && break
      sleep 1
    done
    "$mysql_base/bin/mysqladmin" --no-defaults --socket="$sock" -u root ping >/dev/null 2>&1 \
      || die "MySQL failed to start (see $RUN_DIR/mysql.err)"
  fi
  db_sql "$sock" \
    "CREATE DATABASE IF NOT EXISTS nextcloud CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci" \
    "CREATE USER IF NOT EXISTS 'nextcloud'@'127.0.0.1' IDENTIFIED WITH mysql_native_password BY 'nextcloud'" \
    "GRANT ALL PRIVILEGES ON nextcloud.* TO 'nextcloud'@'127.0.0.1'" \
    "FLUSH PRIVILEGES"
  DB_HOST="127.0.0.1:$DB_PORT"
}

# ---------------------------------------------------------------------------
# 3. Nextcloud
# ---------------------------------------------------------------------------
install_nextcloud() {
  if [[ ! -f "$NC_DIR/occ" ]]; then
    fetch "https://download.nextcloud.com/server/releases/nextcloud-$NC_VER.tar.bz2" "$DL/nextcloud-$NC_VER.tar.bz2"
    rm -rf "$NC_DIR"; mkdir -p "$NC_DIR"
    extract_tar_bz2 "$DL/nextcloud-$NC_VER.tar.bz2" "$NC_DIR"
    # tarball extracts to nextcloud/ -> flatten
    if [[ -d "$NC_DIR/nextcloud" ]]; then
      shopt -s dotglob; mv "$NC_DIR/nextcloud"/* "$NC_DIR/"; shopt -u dotglob
      rmdir "$NC_DIR/nextcloud"
    fi
  fi

  if [[ ! -f "$NC_DIR/config/config.php" ]]; then
    log "installing Nextcloud $NC_VER ($DB_ENGINE)"
    local db_args
    case "$DB_ENGINE" in
      sqlite) db_args=(--database sqlite) ;;
      mariadb|mysql)
        db_args=(--database mysql --database-host "$DB_HOST" --database-name nextcloud \
                 --database-user nextcloud --database-pass nextcloud) ;;
      *) die "Unsupported --db: $DB_ENGINE" ;;
    esac
    "$PHP_BIN" "$NC_DIR/occ" maintenance:install \
      --admin-user "$ADMIN_USER" --admin-pass "$ADMIN_PASS" \
      "${db_args[@]}" --data-dir "$NC_DIR/data" --no-interaction
  fi
  "$PHP_BIN" "$NC_DIR/occ" config:system:set trusted_domains 1 --value=127.0.0.1 >/dev/null 2>&1 || true
  "$PHP_BIN" "$NC_DIR/occ" config:system:set trusted_domains 2 --value=localhost  >/dev/null 2>&1 || true
}

# ---------------------------------------------------------------------------
# 4. App deployment
# ---------------------------------------------------------------------------
deploy_app() {
  log "building app copy (composer)"
  rm -rf "$APP_DIR"; mkdir -p "$APP_DIR"
  ( cd "$REPO_DIR" && tar --exclude=.git --exclude=build --exclude=vendor -cf - . ) | tar -xf - -C "$APP_DIR"
  fetch "https://getcomposer.org/composer.phar" "$DL/composer.phar"
  "$PHP_BIN" "$DL/composer.phar" --working-dir="$APP_DIR" install --no-dev --no-interaction --quiet

  rm -rf "$NC_DIR/apps/koreader_companion"
  cp -r "$APP_DIR" "$NC_DIR/apps/koreader_companion"
  chmod -R 755 "$NC_DIR/apps/koreader_companion"
  log "enabling app"
  "$PHP_BIN" "$NC_DIR/occ" app:enable koreader_companion
}

# ---------------------------------------------------------------------------
# 5. Serve + seed
# ---------------------------------------------------------------------------
serve() {
  if [[ -f "$RUN_DIR/nc-server.pid" ]] && kill -0 "$(cat "$RUN_DIR/nc-server.pid")" 2>/dev/null; then
    log "NC server already running (pid $(cat "$RUN_DIR/nc-server.pid"))"
    return 0
  fi
  log "starting Nextcloud on http://127.0.0.1:$HTTP_PORT"
  nohup "$PHP_BIN" -d memory_limit=1G -d display_errors=1 -S "127.0.0.1:$HTTP_PORT" -t "$NC_DIR" \
    >"$RUN_DIR/nc-server.log" 2>&1 &
  echo $! > "$RUN_DIR/nc-server.pid"
  for _ in $(seq 1 20); do
    curl -sf "http://127.0.0.1:$HTTP_PORT/status.php" >/dev/null 2>&1 && break
    sleep 1
  done
  curl -sf "http://127.0.0.1:$HTTP_PORT/status.php" >/dev/null 2>&1 \
    || die "Nextcloud failed to start on port $HTTP_PORT (see $RUN_DIR/nc-server.log)"
}

seed() {
  # KOReader sync stores md5(password), not plaintext
  local md5
  md5="$(printf '%s' "$KOREADER_PASS" | md5sum | cut -d' ' -f1)"
  "$PHP_BIN" "$NC_DIR/occ" user:setting "$ADMIN_USER" koreader_companion koreader_sync_password "$md5" >/dev/null 2>&1 || true

  mkdir -p "$NC_DIR/data/$ADMIN_USER/files/eBooks"
  local book="$NC_DIR/data/$ADMIN_USER/files/eBooks/Test Book - Jane Tester.epub"
  if [[ ! -f "$book" ]]; then
    "$PHP_BIN" -r '
      $z = new ZipArchive(); $p = $argv[1];
      $z->open($p, ZipArchive::CREATE);
      $z->addFromString("mimetype", "application/epub+zip");
      $z->addFromString("META-INF/container.xml", "<?xml version=\"1.0\"?><container version=\"1.0\" xmlns=\"urn:oasis:names:tc:opendocument:xmlns:container\"><rootfiles><rootfile full-path=\"content.opf\" media-type=\"application/oebps-package+xml\"/></rootfiles></container>");
      $z->addFromString("content.opf", "<?xml version=\"1.0\"?><package xmlns=\"http://www.idpf.org/2007/opf\" version=\"2.0\" unique-identifier=\"id\"><metadata xmlns:dc=\"http://purl.org/dc/elements/1.1/\"><dc:title>Test Book</dc:title><dc:creator>Jane Tester</dc:creator><dc:language>en</dc:language></metadata><manifest></manifest><spine></spine></package>");
      $z->close();
    ' "$book"
    "$PHP_BIN" "$NC_DIR/occ" files:scan "$ADMIN_USER" >/dev/null 2>&1 || true
    # First page load triggers metadata indexing
    curl -s -o /dev/null -u "$ADMIN_USER:$ADMIN_PASS" \
      "http://127.0.0.1:$HTTP_PORT/index.php/apps/koreader_companion/" || true
  fi
}

summary() {
  cat <<EOF

============================================================
 KOReader Companion test environment is ready
============================================================
 Nextcloud : http://127.0.0.1:$HTTP_PORT/index.php
 Admin     : $ADMIN_USER / $ADMIN_PASS
 KOReader  : user '$ADMIN_USER', password '$KOREADER_PASS'
 Database  : $DB_ENGINE ($DB_HOST)
 Root dir  : $ROOT

 Run the suites:
   ./test_scripts/test_opds.sh     --base-url "http://127.0.0.1:$HTTP_PORT/index.php"
   ./test_scripts/test_koreader.sh --base-url "http://127.0.0.1:$HTTP_PORT/index.php"

 Stop: ./test_scripts/teardown_test_env.sh
 Wipe: ./test_scripts/teardown_test_env.sh --wipe
============================================================
EOF
}

# ---------------------------------------------------------------------------
main() {
  install_php
  PHP_BIN="$PHP_DIR/php"
  case "$DB_ENGINE" in
    mariadb) install_mariadb ;;
    mysql)   install_mysql ;;
    sqlite)  DB_HOST="sqlite" ;;
    *) die "Unsupported --db: $DB_ENGINE (use mariadb|mysql|sqlite)" ;;
  esac
  install_nextcloud
  deploy_app
  serve
  seed
  summary
}

main "$@"